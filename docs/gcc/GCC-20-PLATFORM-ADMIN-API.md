# GCC-20 — Platform admin API, level 1 (monitor)

**Status:** implemented on a feature branch, read-only, switched off by default and dormant until its migrations run. Not release ready: the review gates at the end of this
document apply (GCC-20 privileged authentication needs a security review before merge or release, `AUTONOMOUS_DEVELOPMENT_CHARTER.md` section 5).
**This document is the contract the Flutter seller-app shell is built on.** Business requirement: [GCC-20-SUPER-ADMIN-MOBILE.md](./GCC-20-SUPER-ADMIN-MOBILE.md).

**Phase 2, delivery 1 (this revision): multi-factor authentication and session management.** Signing in takes a second factor by default (a TOTP code, or a one-time recovery
code), and a session has an idle timeout and a limit on how many may be open at once. What changes for the seller app, in one place: sections 5 (the sign-in is now two steps, there is
a new `POST /auth/mfa`, `GET /me` and `GET /sessions` gain members, `DELETE /sessions` ends every other session) and 3 (the runbook). Everything else of level 1 is as it was.

**Phase 2, delivery 2 (this revision on top of it): grants that can end and be narrowed to parts of the API, a tamper-evident audit trail, and the isolation tests.** What changes for the
seller app: `GET /me` gains `capabilities` and `grant_expires_at`; a part of the API the grant does not include answers a new `403 capability_not_allowed`; a grant that has ended is the
same 401 as any other refusal. The rest is for the operator (section 3: the grant options, `list`, and the audit commands `audit:verify` and `audit:prune`) and for the reviewer
(section 7 and the tests of section 11).

**With the staff country scopes (GCC-14c) of the admin panel (section 12).** An admin who is also a restricted employee reaches only the markets of their grant that the staff scope leaves
them, while `GCC_STAFF_COUNTRY_SCOPES` is on. What changes for the seller app is a value, never a shape: `markets` is shorter, and `scope` is `restricted` for an employee whose grant names every
market. While that switch is off, for the master admin and for an employee with no row, nothing changes at all.

## 1. What it is

A small JSON API under `/api/platform-admin/v1` with which the platform owner, signed in to the seller app as an **admin**, watches all six markets: whether each market is
open and legally ready, how many orders each has and in which state, what is waiting to be fulfilled, refund requests, stock at zero, low or past zero, the stock held for orders and
the division of a pooled stock, alerts, and an audit trail of
what the API itself did.

It is a **separate principal**. It is not a seller, a seller staff member, a seller API key, a customer, or an admin who happens to be signed in to the web panel:

| Caller | Can it use this API? | Can it become a platform admin? |
|---|---|---|
| Seller owner, seller staff, seller API key | No: their tokens are never looked at here | No: no route of the seller API, and nothing a seller sends, creates a grant or a platform token |
| Customer (Passport token or session) | No | No |
| Admin signed in to the web panel | No: the panel session is not a credential here | Only through the sign-in of this API, and only with a grant |
| Admin with a grant | Yes, with a token from `POST /auth/login` | Already is one |

How it is kept apart: its own sign-in endpoint, its own token table (`platform_admin_tokens`), its own middleware, no seller middleware, no seller table, no `Auth` guard, no
Passport (`ReverseIsolationTest` scans the code for these). The identity is the admin's own e-mail and password, checked against the `admins` table **without starting a session**.
Which admin may use it is decided by a row in `platform_admin_grants`, written only by a console command an operator runs on the server. There is no hard-coded admin id (not
admin 1), no e-mail allow-list and no flag the client sends. A grant is made for one admin **row**: it remembers the id and the creation time of the row, and a different row that
merely comes to carry the same id (a deleted admin whose id is reused, a restored dump) is not that admin and gets no access. A grant may also **end on a day** and may be limited to
some **capabilities** (parts of the API): a viewer who is to see only the alerts is given only those.

Level 1 only reads. The only things it writes are its own session rows (sign-in, sign-out, ending a session, the time a session was last used), its sign-in challenges, the
counters of its limits and its own audit lines. **No HTTP route can enrol, reset or weaken a second factor**: that is done only by the console commands of section 3.

## 2. The flag and the three states

`config/gcc.php`, block `platform_admin`. Environment: `GCC_PLATFORM_ADMIN_ENABLED` (default `false`), `GCC_PLATFORM_ADMIN_TOKEN_TTL_MINUTES`,
`GCC_PLATFORM_ADMIN_LOGIN_ATTEMPTS_PER_MINUTE`, `GCC_PLATFORM_ADMIN_REQUESTS_PER_MINUTE`; the window and the alert thresholds are configuration only.

| State | What every route of the API does |
|---|---|
| Flag off (default) | **404, exactly the answer of a route that does not exist** (same status, same message, same headers), before the database, the cache, the limiter or any other service is touched. Verified with a query listener: zero statements. Only a real boolean `true` switches it on: `"1"`, `"yes"` and `1` do not. |
| Flag on, migrations not run | **503** `platform_admin_unavailable`, one stable JSON body, no write anywhere, no 500. One missing table or column of the API's migrations is enough. |
| Flag on, migrations run | Serves. |

Whatever the state, nothing an existing API answers changes: `ReverseIsolationTest` compares the seller sign-in, the seller API's refusal, the customer API's refusal and the
storefront list with the API on and off, and the routes of every other API are asked with and without a platform token and answer identically.

A request for another HTTP method on the same path answers the router's own 405 whatever the flag says, because the path is registered for the method that exists. Only the
routes of the API answer 404 while it is off.

**The second switch (not a fourth state).** `GCC_PLATFORM_ADMIN_MFA_REQUIRED` (`gcc.platform_admin.mfa.required`) decides whether a sign-in takes a second factor. It is **true by
default and fails closed**: only a real boolean `false` (the environment values `false`, `0`, `off`, `no`) switches it off; a value that is not a boolean at all, such as a typo, leaves it
on. False is for staging only: the sign-in is then exactly the level 1 sign-in (one step, the answer is byte for byte the one of section 5), and `gcc:platform-admin:grant` prints a warning
every time it is run while the switch is off. It changes nothing while `GCC_PLATFORM_ADMIN_ENABLED` is off.

The other numbers of this revision are configuration in the same block (`gcc.platform_admin.mfa.*` and `gcc.platform_admin.session.*`). A value that makes no sense (zero, negative, text) is the default, and the ones that
weaken a protection when they grow have a ceiling that nothing configured can lift:

| Environment | Default | Ceiling | What it is |
|---|---|---|---|
| `GCC_PLATFORM_ADMIN_MFA_REQUIRED` | `true` | n/a | Whether a sign-in takes the second factor (above) |
| `GCC_PLATFORM_ADMIN_MFA_CHALLENGE_TTL_MINUTES` | 5 | 15 | How long a challenge is good for |
| `GCC_PLATFORM_ADMIN_MFA_MAX_CHALLENGE_ATTEMPTS` | 5 | 10 | Tries a challenge allows before it is consumed |
| `GCC_PLATFORM_ADMIN_MFA_LOCKOUT_FAILURES` | 10 | 20 | Wrong or replayed codes of one admin that lock the second step |
| `GCC_PLATFORM_ADMIN_MFA_LOCKOUT_MINUTES` | 15 | 60 | The window those failures are counted in, and how long the lock lasts |
| `GCC_PLATFORM_ADMIN_SESSION_IDLE_MINUTES` | 60 | 1440 | Unused for this long, a session is over |
| `GCC_PLATFORM_ADMIN_SESSION_MAX_CONCURRENT` | 5 | 20 | Open sessions of one admin; a new one ends the oldest beyond this |

## 3. Operator runbook

All of it is run on the server by an operator. **No HTTP route can create, widen or revoke a grant.** Nothing here touches production data by itself; follow the deployment
process of the platform for migrations and configuration.

### Before the first use

1. Merge and deploy the code. With the flag off nothing changes.
2. **Look at `admins.id` (informational: the migration does not depend on it).** `platform_admin_grants.admin_id` is tied to `admins.id` by a foreign key with `ON DELETE CASCADE`
   when the real column can be referenced, and the migration checks that itself: `BIGINT UNSIGNED` on an InnoDB table (MySQL and MariaDB). The installation dump that creates `admins`
   is not in this repository, so `SHOW CREATE TABLE admins` tells you beforehand which case you are in. If the key cannot be made (another type or engine, no `admins` table, or the
   database refuses it for any other reason) **the migration still succeeds**: it creates the table without the key and writes one warning to the application log that says why.
   Nothing else changes, because a grant is tied to the admin row it was made for by a fingerprint, not by the key (see "Deleting an admin who holds a grant" below).
3. Run the migrations of the API: `2026_11_27_100000_create_platform_admin_grants_table`, `..._100100_create_platform_admin_tokens_table` and `..._100200_create_platform_admin_audit_logs_table`
   (level 1), and the three of the second factor: `2026_11_29_100000_create_platform_admin_mfa_factors_table`, `..._100100_create_platform_admin_mfa_challenges_table` and
   `..._100200_add_mfa_method_to_platform_admin_tokens_table`. They only add tables and one nullable column. Rolling them back drops what they added and nothing else; roll all of them back
   together, newest first (the tokens, the factors and the challenges have a foreign key to the grants table, which MySQL refuses to drop while it is referenced). None of the new tables
   has a foreign key to `admins`: a factor and a challenge hang off the grant, which carries the conditional key of step 2, so deleting an admin takes them away exactly when it takes
   the grant away. Delivery 2 adds three more, all additive: `2026_11_30_100000_add_expiry_and_capabilities_to_platform_admin_grants_table` (two nullable columns),
   `..._100100_add_hash_chain_to_platform_admin_audit_logs_table` (two nullable columns) and `..._100200_create_platform_admin_audit_chain_tables` (the tail of the chain and the checkpoints
   of a prune). Until all nine have run, a switched-on API answers 503 (section 2).
4. **Behind a proxy or a load balancer, set `TRUSTED_PROXIES`** (`config/trustedproxy.php`) so that the address the API sees is the client's. The sign-in and request limits count
   addresses; if every client arrives as the proxy's address they share one limit.
5. Grant access, enrol the second factor, then switch the flag on:

```
php artisan gcc:platform-admin:grant 7 --confirm-owner                                          # admin 7 is the platform owner: every market, every capability, no end
php artisan gcc:platform-admin:grant 12 --countries=KW,AE --capabilities=overview,alerts \
    --expires=2027-03-31                                                                         # admin 12: Kuwait and the Emirates, those two parts, until the end of 31 March 2027
php artisan gcc:platform-admin:mfa:enroll 7                  # prints the secret, the otpauth URI and ten recovery codes, ONCE
php artisan gcc:platform-admin:mfa:confirm 7 123456          # the first code the admin's authenticator app shows: only now is the factor active
GCC_PLATFORM_ADMIN_ENABLED=true                              # then clear the configuration cache the way the deployment does
```

   With `GCC_PLATFORM_ADMIN_MFA_REQUIRED` at its default (true) **an admin without a confirmed factor cannot sign in at all**: the sign-in answers the same 401 as any failure. Enrol every
   admin who holds a grant before they try.

### Day to day

| To do | Command | What it does |
|---|---|---|
| Make a platform owner | `gcc:platform-admin:grant {admin_id} --confirm-owner` | Every market, every capability, no end. **A grant with no `--countries` is a grant for every market, and the command refuses it unless `--confirm-owner` says that is meant.** Refuses an unknown admin, an **inactive admin (status must be exactly 1, the first admin included)**, and any level but 1. |
| Restrict, narrow or widen | `gcc:platform-admin:grant {admin_id} --countries=KW,AE` | The grant is updated in place. Codes are case-insensitive, deduplicated and stored in configuration order. An unknown code, or a list with no code in it, is refused and nothing is written. `--confirm-owner` together with `--countries` is refused: it says the opposite. |
| Limit to parts of the API | `... --capabilities=overview,alerts` | Of `overview`, `markets`, `alerts`, `audit`, `sessions` (case-insensitive, deduplicated, kept in that order). Leaving it out is all five. An unknown name, or a list with none in it, is refused. |
| Give it an end | `... --expires=2027-03-31` | The grant is good through the end of that day (23:59:59 in the time zone the platform stamps its rows in, the one of section 9, point 10), and then it is no grant. A date that has passed, or anything that is not `YYYY-MM-DD`, is refused. Leaving it out is no end. |
| Look at the grants | `gcc:platform-admin:list` | Every grant: the admin, the markets, the capabilities, the end, and its state (`active`, `revoked`, `expired`, `admin inactive`, `admin row replaced`, `admin missing`). While the staff country scopes are on and enforced it adds two columns: the countries the staff scope restricts the admin to, and the markets that leaves them (section 12). Read only. |
| End a stolen or lost token | `gcc:platform-admin:revoke-tokens {admin_id}` | Every session of that admin ends at once; the grant stays, so they can sign in again. |
| Take access away | `gcc:platform-admin:revoke {admin_id}` | The grant is revoked and every session ends. Revoking twice is harmless and keeps the first time. |
| Give it back | `gcc:platform-admin:grant {admin_id} ...` | A revoked grant is reactivated, and any session that survived a hand edit is ended first. |
| Enrol the second factor | `gcc:platform-admin:mfa:enroll {admin_id} [--replace]` | Needs an active grant. Creates a **pending** factor and prints, once, the secret (base32), the otpauth URI and ten recovery codes. Refuses when the admin already has a factor, pending or active, unless `--replace`, which removes it first and ends the admin's sessions and challenges. |
| Activate it | `gcc:platform-admin:mfa:confirm {admin_id} {code}` | The code the authenticator app shows for the secret just entered. Only a confirmed factor counts at sign-in. A wrong code changes nothing. |
| Lost phone, lost codes | `gcc:platform-admin:mfa:reset {admin_id}` | Removes the factor (secret and recovery codes), ends every session and every challenge of the admin. They enrol again. |
| Look | `gcc:platform-admin:mfa:status [{admin_id}]` | For every grant (or one admin): the state of the factor, when it was activated, how many recovery codes are left, whether its secret can still be read with the current key, and how many open sessions passed the second factor and how many did not. Never a secret. |
| Check the trail | `gcc:platform-admin:audit:verify` | Walks the whole audit trail and its chain of hashes. Exit 0 prints how many lines it read and **the hash of the newest, which is worth writing down somewhere else**; exit 1 names the first line that is broken. Read only. |
| Cut the trail | `gcc:platform-admin:audit:prune --days=N --confirm` | The **only** way a line leaves the trail, and never scheduled. `N` is a whole number of at least 1. Removes the run of lines from the start of the trail that were all written more than N days ago (a newer line ends the run, and **the newest line is never removed**), after a verification that must pass, and keeps a checkpoint that carries the hash of the last line removed so that `audit:verify` still passes. Without `--confirm` it only says what it would remove. |

Each of these writes one audit line (`grant.created`, `grant.updated`, `grant.reactivated`, `grant.revoked`, `tokens.revoked`, `mfa.enrolled`, `mfa.confirmed`, `mfa.confirm_failed`, `mfa.reset`, `mfa.status`, `audit.pruned`)
with what the grant was before and after where there is one (the level, the markets, the capabilities and the end). **The lines of every command that changes something carry the identity of the operator**: the operating system user and the host name of
the machine the command ran on (`context.operator.user`, `context.operator.host`), and `source: "console"`; never a secret. The commands work whether the flag is on or off, and each tells the operator to run the migrations
if the tables are missing instead of failing. `gcc:platform-admin:grant` also says, after it succeeds, when the admin has no confirmed factor yet (and that they cannot sign in until
they have one), and warns every time it runs while `GCC_PLATFORM_ADMIN_MFA_REQUIRED` is off. While the staff country scopes are on and enforced it also says, as advice and without changing anything, when the staff scope of the admin
leaves them fewer markets than the grant gives (section 12).

### Capabilities and expiry

* **Capabilities** are the parts of the API a grant includes: `overview`, `markets`, `alerts`, `audit`, `sessions`. A grant with none named has all five (and so does every grant that was made before capabilities existed). They are enforced by the
  route, before the controller: `GET /overview` needs `overview`, `GET /markets` needs `markets`, `GET /alerts` needs `alerts`, `GET /audit-logs` needs `audit`, and `GET /sessions`, `DELETE /sessions` and `DELETE /sessions/{id}` need `sessions`.
  Nothing else is gated: **`GET /me` and `POST /auth/logout` are always allowed**, so the app can always read what the admin may do and sign out. A request for a part the grant does not include is `403 capability_not_allowed` (section 4), is audited like
  any other request, and reads nothing. What `GET /me` says is what the server enforces, in the order of the list above; a value in the grant that is not one of the five is ignored, and a value that cannot be read as a list means no capability at all
  rather than every one.
* **Expiry.** A grant may end on a day. Once it has, it is no grant: a sign-in with it is the uniform 401 (`expired_grant` in the log and the trail), and the first request of a session of that admin after that moment is the uniform 401 **and ends every open
  session of the admin** (`session.grant_expired`). The next day's `grant` command with a new `--expires` gives it back; nothing about it needs a revoke. The platform owner is just as subject to it as anybody.
* Each run of `grant` sets the whole grant: what it leaves out is the default (every market, which needs `--confirm-owner`; every capability; no end). It says what the grant now is, and the audit line says what it was.

### The audit trail

`platform_admin_audit_logs` is append only and **tamper evident**.

* **A chain.** Every line carries `prev_hash`, the hash of the line before it, and `hash`, the SHA-256 of a canonical JSON of its own content (the admin, the action, the market, the session, the address, the user agent, the context with its keys sorted, the time
  it was written, and `prev_hash`). Changing a line, removing one from the middle, or adding one that did not come from the writer, breaks the chain at that line. The first line of the chain follows a fixed starting value. A line written before the chain began has no
  hash and is read as older than it; an unhashed line that turns up after hashed ones is a break.
* **A tail.** The writer holds one row (`platform_admin_audit_tail`: the id and the hash of the line written last) for the duration of a write, which is what makes two writers take turns, and what lets `audit:verify` see that the newest lines are still there.
  **If that row is missing the writer refuses to write**: a line is never chained to nothing, so a request that must be audited fails closed and a console command that audits its change does not make it, until the row is restored (from a backup of the table, or by rolling back and running again the migration that makes it, which starts it from the newest hashed line: do that only after `audit:verify` and the record you keep of its head hash say no line is missing).
  A transaction that writes a line holds the tail until it ends, so the tail is the last thing any transaction takes (factor, grant, challenge, then tail); one that is nevertheless chosen as the victim of a deadlock is done again, up to three times.
* **Nothing edits or deletes through the application.** The model refuses to update or delete a line, and so does a query built on it. A line leaves only by `audit:prune`, which works on the table directly.
* **`audit:verify`** reads every line in order, and the checkpoint if there is one, recomputes each hash and compares each `prev_hash`, then compares the end of the trail with the tail. It exits 0 and prints the number of lines and the hash of the newest, or exits 1 and names the id of
  the first line that is wrong and why. **A person with write access to the database can rewrite the whole chain from some point on and recompute the hashes**: the chain is evidence against changes nobody meant to hide, and against clumsy ones; it is not proof against the
  administrator of the database. Write down the id and the hash `audit:verify` prints (or send them to where the database's administrator cannot reach), at the interval the owner decides: a later `audit:verify` that still finds that line with that hash proves the trail up to it was not rewritten.
* **`audit:prune`** is the only way lines leave, and it is never scheduled (a test says so). It refuses to run while the trail does not verify (the evidence of a break is not to be cut away), takes the lines that are older than `--days` from the start of the trail (a newer line ends the run, and the newest line is never taken), writes a
  checkpoint (the id and the hash of the last line removed, how many, when, and who ran it), removes them in the same transaction, and then writes its own line, `audit.pruned`, into the chain. After it, `audit:verify` starts from the checkpoint: the first line that is left must follow the hash it holds.
  Without `--confirm` it removes nothing and says what it would. **How long to keep the trail is the owner's decision** (section 10); until it is made, nothing is ever removed.
* **A time zone assumption.** The time a line was written is part of what is hashed, as the database gives it back. It is the same string whatever the time zone of the PHP process is, but it changes if the session time zone of the database server changes between the writing
  and the checking (a MySQL `TIMESTAMP` column is read through that zone): `audit:verify` would then report the first line as broken. Keep the server's zone fixed.

### The second factor

* **What it is.** A time-based one-time password (RFC 6238: HMAC-SHA-1, 6 digits, 30 seconds) shown by any authenticator app, and ten one-time recovery codes. A code is accepted when it is
  the code of the current 30-second step or of the step before or after it (so a phone whose clock is up to 30 seconds off still works), **once**: the step of the last accepted code is
  kept for each admin and a code of that step or of an older one is refused. Two sign-ins in the same 30 seconds therefore need two different codes; the second waits for the next one.
* **The secret** is 160 random bits, shown once by `mfa:enroll` as base32 and stored encrypted with the application key. **The recovery codes** are ten of `xxxxx-xxxxx` (ten characters from
  the 31 of the letters without `i`, `l` and `o` and the digits 2 to 9; typing them in any case, with or without the hyphen, works), shown once and stored only as an HMAC-SHA-256 under a key derived from
  the application key. A recovery code works once. Neither the secret, a code, nor anything derived from them is written to a log, to the audit trail, or to any answer of the API.
* **Custody is the operator's.** `mfa:enroll` prints to the operator's terminal and nowhere else. Hand the secret and the codes to the admin over a channel the owner trusts, and have the admin
  keep the codes apart from the phone. Type the secret into the authenticator app by hand: do not paste the otpauth URI into an online QR generator, because the secret would leave the server.
* **Changing `APP_KEY` makes every factor unreadable** (the secret cannot be decrypted, the recovery codes no longer match): no admin can then pass the second step, which fails closed, and
  `mfa:status` says `unreadable`. Reset and enrol again after rotating the key.
* **Lockout.** Ten wrong or replayed codes for one admin within 15 minutes (whatever challenge or address they came from; the window starts at the first of them) lock that admin's second
  step for the rest of the window. A lockout is one audit line (`auth.mfa_locked`). The numbers are `GCC_PLATFORM_ADMIN_MFA_LOCKOUT_FAILURES` (10, at most 20) and
  `GCC_PLATFORM_ADMIN_MFA_LOCKOUT_MINUTES` (15, at most 60). A person who knows the password can lock the admin out for the window by guessing: that is the price of a lockout; changing the
  password ends their access, and the audit lines show it.
* **Staging only:** `GCC_PLATFORM_ADMIN_MFA_REQUIRED=false` (section 2). It is not a way to roll the second factor out: when the switch is turned back on, **a session that did not pass the second factor is
  no longer a session** (the next request is the uniform 401), however it was opened, and an admin without a confirmed factor cannot sign in.

### Rotating and revoking

* A token lives 480 minutes by default; the configured value is never more than 1440 and a value below 1 is the default. A session cannot be extended: the app signs in again.
* **Idle timeout.** A session that has not been used for 60 minutes (`GCC_PLATFORM_ADMIN_SESSION_IDLE_MINUTES`, at most 1440) is over: its next request is the uniform 401 and ends the
  session (`session.idle_ended` in the trail). The time of last use moves at most once a minute, so idleness is measured to the minute; a session that was never used counts from the moment it was opened.
* **At most 5 open sessions per admin** (`GCC_PLATFORM_ADMIN_SESSION_MAX_CONCURRENT`, at most 20). When a sign-in would make one more, the oldest open sessions are ended to make room
  (`session.evicted`: one line for each session that was ended, which names it as the line's `token_id` and, in its context, the session that made room (`by_token_id`) and the limit). Sessions already ended, expired or idle do not count,
  and two sign-ins of one admin at once cannot both find room (the grant is held while they are counted).
* **Any change of the admin's password, made anywhere, ends every one of that admin's sessions on the next request, and a sign-in that was waiting for its second factor cannot be completed.** A session remembers the SHA-256 of the password hash it was
  opened with. (A rehash of the same password, which Laravel does when the hashing cost changes, counts as a change.) Resetting the second factor, revoking the grant, `revoke-tokens` and giving a revoked grant back (or making a grant over to a replaced row) end the sessions and the pending challenges too.
* A session is also refused on the next request when its admin is deactivated, its grant is revoked, it expires or is idle. Nothing is cached, so there is nothing to wait for.
* The admin sees their own sessions with `GET /sessions`, ends one with `DELETE /sessions/{id}` and ends all the others with `DELETE /sessions`.
* If a token may have leaked: `revoke-tokens`, then ask the admin to change the password. A token starts with `lkpa_`, so one found in a log or a file can be recognised and revoked.
* **Deleting an admin who holds a grant.** With the foreign key the grant and its sessions go with the admin. Without it (the migration said so in the log) they stay behind, and
  cannot be used: a grant remembers the id and the creation time of the row it was made for, so a row that comes to carry the same id later is a different admin and gets no access, at
  sign-in or with a session that was already open. Run `gcc:platform-admin:mfa:reset {admin_id}` (removes the secret) and `gcc:platform-admin:revoke {admin_id}` before deleting the admin to leave nothing behind. If the new row is meant to have the
  access, run `gcc:platform-admin:grant {admin_id}` again: the grant is made over to the row that carries the id now, the sessions of the old row end, the command says so, and the audit line (`grant.updated`) carries `replaced_admin_row`.
  A factor remembers the admin row it was enrolled for in the same way a grant does, so it is **not** inherited by the new row: the new row has no factor until `mfa:enroll --replace` is run for it.
  Changing an admin's e-mail or name does not touch the grant or the factor; replacing the row does.
* To switch the whole API off: unset the flag. Every route answers 404 again at once.

### Watching it

`GET /audit-logs` (platform owner: every line) and the table `platform_admin_audit_logs`. A refused sign-in is always in the application log, with its reason (`malformed_input`, `unknown_admin`, `wrong_password`, `inactive_admin`, `no_grant`,
`grant_of_another_admin_row`, `revoked_grant`, `expired_grant`, `mfa_not_enrolled`) and a hash of the address, never the address itself. Only a refusal for an admin who holds a grant is also audited, and at most `failed_login_audit_per_hour` (10) lines per grant per hour, so
traffic from the internet cannot make a table grow. **A refused second step is treated the same way**: always in the application log with its reason (`malformed_input`, `unknown_challenge`, `expired_challenge`, `consumed_challenge`,
`attempts_exhausted`, `wrong_code`, `replayed_code`, `unusable_factor`, `inactive_admin`, `no_grant`, `grant_of_another_admin_row`, `revoked_grant`, `expired_grant`, `password_changed`) and a hash of the challenge, never the challenge or the code; and, when the
challenge belongs to an admin who holds a grant, one `auth.mfa_failed` line, at most `failed_login_audit_per_hour` per grant per hour (its own count, separate from the sign-in's). Seeing `auth.mfa_failed` lines the admin did not cause means
somebody holds the password: change it. The lines the console commands write carry an `operator` in their context; **a restricted admin, who reads only their own lines, is never shown it** (the platform owner is).

## 4. Conventions

* Base path `/api/platform-admin/v1`. Everything is JSON. Send `Accept: application/json`.
* Success: `{"data": ...}`, with `"meta": {...}` for lists. Failure: `{"errors": [{"code": "...", "message": "...", ...}]}`. **The `code` is the contract.** Messages are plain
  English for a person reading a log, are never translated, and may change. The app localises by code.
* Timestamps are UTC ISO 8601 with a trailing `Z`. **Dates** (`from`, `to`, `window`) are calendar dates written `YYYY-MM-DD`, in the zone the platform stamps its rows in: the PHP
  default zone, which the application sets at boot from the merchant's `timezone` setting (reported in the answer as `timezone`). See section 9, point 10.
* Every answer of the API (except the 404 of a switched-off API) carries `Cache-Control: no-store, private` and `Vary: Authorization`.
* Authentication: `Authorization: Bearer lkpa_<64 characters>`. The scheme is case-insensitive; **only** the header counts, not a query string, a body field or a cookie.
* **Every failure to authenticate is the identical 401 `platform_admin_auth`**, with no hint which check failed: unknown or malformed token, expired, idle, revoked, admin deactivated,
  password changed, grant revoked, grant expired, grant of another admin, and at the sign-in an admin who has no confirmed second factor while one is required; at the second step (`POST /auth/mfa`) an unknown, expired, used-up or
  consumed challenge, a wrong code, a code that was already used, a recovery code that was already used, and anything that is not well formed. **The one exception is the lockout** (see below), which is a 429.
* Limits: 120 requests a minute from one address to any route (`429 platform_admin_throttled` with `Retry-After`), counted before a token is looked up. The sign-in has its own limits (section 5).
  **The second step is limited per address** to three times the number of sign-in attempts a minute for one e-mail (15 by default), counted before the challenge is looked up, so that nothing about a challenge can
  be learned from it; **per challenge**: a challenge allows `GCC_PLATFORM_ADMIN_MFA_MAX_CHALLENGE_ATTEMPTS` (5, at most 10) tries, every refusal on a challenge that is still good counts one, and after the last
  the challenge is consumed (every later try with it is the uniform 401, a right code included); and **per admin**: the lockout of section 3. A locked admin who signs in with the right password gets
  `429 platform_admin_throttled` with `Retry-After` (and no challenge), and a code sent with a challenge that is still good gets the same 429 without being checked or counted; every other request of the second step is
  the uniform 401, locked or not, so that a 429 can only be seen by somebody who holds a good challenge.

### The contract level of the shell

The seller app's force-update gate (`seller_app_version_control`, in front of its login screen) is about the SELLER app's release. The admin shell is a different thing inside the same binary: it can stop being
able to read this API without the seller app needing a release, and the seller app can be released without the shell changing. So the shell has a floor of its own, as a CONTRACT level rather than an app version —
what matters is not which build is installed but whether it understands the answers. **Nothing here reads or touches the seller version gate, and a test asserts no statement of a request mentions it.**

* A request says which level it speaks in `X-Platform-Admin-Contract`. A build from before the header says nothing, which is **level 0 and not "whatever is current"**: an older shell must not be handed answers it
  was never written to read. A header that is not a whole number (`1.0`, `v1`, text) is level 0 as well; space around a number is ignored.
* The server's own level is `1`. It goes up when an answer of this API changes shape in a way an older shell cannot read.
* `GCC_PLATFORM_ADMIN_MIN_CONTRACT` is the oldest level the server will answer. It **ships unset, so nothing is forced** and deploying this changes no request's outcome. A value that is not a positive whole number
  means no floor at all, rather than the 0 or 1 a cast would make of it: for a floor, the unsafe direction of a nonsense value is ON.
* **The floor can never be set above the level the server itself speaks.** A minimum of 2 against a server of 1 would refuse every request including the sign-in, leaving no session to read the floor from and no way
  back but another number — which is exactly how the seller version gate once locked every seller out. A configured value above `version` is held down to it.
* The refusal is `426 contract_too_old` and is raised **in front of the whole API, the sign-in included**: a build that cannot read the answers is not given a session to read them with. It carries `claimed`,
  `version` and `min_supported`, because it has to be enough on its own. It is answered `Cache-Control: no-store, private` like every other answer of this API, and says nothing about the account that asked.
* The switch comes first: while the API is off, every route including this check is a plain 404. A dormant API never answers 426, which would admit it exists.
* `GET /me` carries `contract: {version, min_supported}` so a shell that is still new enough can see where the floor stands and warn before it is refused, rather than learning it from a 426 one morning.

### Error codes

| Status | Code | When |
|---|---|---|
| 401 | `platform_admin_auth` | Any failure to authenticate or to sign in |
| 403 | `capability_not_allowed` | The grant does not include the part of the API the route belongs to (section 3, "Capabilities and expiry"); the error carries `capability`, the one that is missing. Not an authentication failure: the token is good, and `GET /me` says what is allowed |
| 403 | `market_not_allowed` | The market is not one the admin may see: not in their grant, or not left to them by their staff country scope (section 12); the answer is the same, so it says nothing about which (`market=all` is never refused: it means all of theirs) |
| 404 | `session_not_found` | The session is not one of the admin's own open sessions (another admin's id, an ended one and an unknown one all answer alike) |
| 404 | (router's own) | The API is switched off, or the path does not exist |
| 422 | `invalid_market` | `market` is not `all` or a configured code; the error carries `valid` |
| 422 | `invalid_window` | `from` or `to` is not a date written `YYYY-MM-DD`, or `from` is after `to` |
| 422 | `invalid_severity` | `severity` is not `info`, `warning` or `critical`; the error carries `valid` |
| 426 | `contract_too_old` | The shell speaks an older contract level than the oldest this server answers (section 4, "The contract level of the shell"). Carries `contract: {claimed, version, min_supported}`, so a build can say what is wrong without a session: this refusal comes back from the SIGN-IN too. No floor is set by default, so nothing meets this |
| 429 | `platform_admin_throttled` | Too many sign-in attempts or requests, too many tries at the second step from one address, or the admin is locked out of the second step; `Retry-After` says when |
| 503 | `platform_admin_unavailable` | The flag is on and the migrations have not run |

## 5. Endpoints

All but `POST /auth/login` and `POST /auth/mfa` need the bearer token. Every request of a signed-in admin is written to the audit trail (action, market, address, user agent, status), refused ones too.

**The sign-in, as the app sees it.** Two steps while the second factor is required (the default), one while it is not (staging):

```
POST /auth/login ──► 200 {"data": {"token", ...}}                          one step: MFA is not required. The answer is the one this document has always described.
                 ──► 200 {"data": {"mfa_required": true, "challenge", ...}} password accepted, no session yet:
                         └─► POST /auth/mfa {"challenge", "code" | "recovery_code"} ──► 200 {"data": {"token", ..., "mfa": {...}}}
                 ──► 401 platform_admin_auth                                 every refusal, whatever the reason (an admin with no confirmed factor included)
                 ──► 429 platform_admin_throttled                            too many attempts, or the admin is locked out of the second step
```

The app tells the two answers of a successful `POST /auth/login` apart by the member `mfa_required`: present and `true` means a code is wanted and there is **no token**; absent means `token` is there.

### `POST /auth/login`

Body (JSON or form; **a body only, credentials in the address are not read**): `email` (up to 254 characters), `password` (up to 1024), `device_name` (1 to 100 characters of UTF-8).

**While the second factor is not required** (`GCC_PLATFORM_ADMIN_MFA_REQUIRED=false`, staging only), and for every request before this revision, the answer is, byte for byte:

```json
{"data": {
  "token": "lkpa_<64 random characters>", "token_type": "Bearer", "expires_at": "2026-11-17T14:00:00Z",
  "me": {"admin_id": 7, "name": "Platform Owner", "level": 1, "scope": "all", "markets": ["SA","AE","KW","QA","BH","OM"], "token_expires_at": "2026-11-17T14:00:00Z"}
}}
```

**While it is required** (the default) and the admin has a confirmed factor, a good password does not open a session. The answer is:

```json
{"data": {"mfa_required": true, "challenge": "lkpc_<48 random characters>", "challenge_expires_at": "2026-11-17T10:05:00Z", "methods": ["totp", "recovery_code"]}}
```

and **there is no `token`** in it. The challenge is shown once, is good for 5 minutes (`GCC_PLATFORM_ADMIN_MFA_CHALLENGE_TTL_MINUTES`, at most 15) and only its SHA-256 is stored. It is not a credential for anything but `POST /auth/mfa`.
The `me` of the answer that opens the session (above, and the one of `POST /auth/mfa`) has the six members it has always had and nothing more: **read `GET /me` for everything else about the admin, as the app already does on every start.**

The token is shown **once**; only its SHA-256 is stored. Success needs: the admin exists, the password matches, **`status` exactly 1 (no exemption for admin 1)**, an active grant made for that admin row and,
while the second factor is required, a **confirmed factor made for that admin row**.
Everything else, including a malformed body and an admin who has no confirmed factor while one is required, is the same 401, with the same work done: one lookup of the admin, **one password check against a real bcrypt hash even when there is
no such admin**, one lookup of the grant and (while the second factor is required) one of the factor. What the caller cannot be told, because it is the answer of a wrong password too, is that the admin is not enrolled: the log and the trail have the reason (`mfa_not_enrolled`). Limits: 5 attempts a minute for one e-mail from one address (configurable), 15 for one address in all; every attempt counts, and a
success starts the count of that e-mail from that address again (**while the second factor is required nothing starts it again**: a good password is not yet a sign-in, and the count ends by itself after a minute). A sign-in also deletes that admin's sessions that ended more than 30 days ago
and the challenges that ended more than a day ago. **A locked-out admin** (section 3) who gives the right password gets `429 platform_admin_throttled` with `Retry-After`, and no challenge. Opening a session ends the oldest open ones when the admin
would have more than 5 (section 3).

### `POST /auth/mfa`

Completes a sign-in that answered `mfa_required`. No bearer token. Body (JSON or form; **a body only**): `challenge` (the `lkpc_` value) and **exactly one of** `code` (a **string** of six digits, since a number would lose its
leading zeros; spaces are ignored) or `recovery_code` (a string, `xxxxx-xxxxx`, in any case, with or without the hyphen). Both, neither, or a value that is not of that shape is a malformed attempt: the same 401 as any failure. A field that is present counts as sent whatever it holds (a list, a number), except
that an empty string is read as absent, so `{"code": "123456", "recovery_code": ""}` is an attempt with a code and `{"code": "123456", "recovery_code": ["x"]}` is both.

```json
{"data": {
  "token": "lkpa_<64 random characters>", "token_type": "Bearer", "expires_at": "2026-11-17T18:00:00Z",
  "me": {"admin_id": 7, "name": "Platform Owner", "level": 1, "scope": "all", "markets": ["SA","AE","KW","QA","BH","OM"], "token_expires_at": "2026-11-17T18:00:00Z"},
  "mfa": {"state": "verified", "method": "totp", "recovery_codes_left": 10}
}}
```

This is the answer of a successful sign-in plus `mfa`: `method` is `totp` or `recovery_code`, and `recovery_codes_left` is how many recovery codes the admin still has (a sign-in with one has used it). **When it is 2 or fewer the app
should say so**; at 0 the admin has to ask the operator for a new factor. The session records how it was opened (`GET /sessions`, `GET /me`).

**Every failure is the same 401 `platform_admin_auth`, with the same work done** (a challenge that does not exist is checked against a dummy secret): an unknown, expired, consumed or used-up challenge, a wrong code, a code of a step that
was already used or an older one, a recovery code that is wrong or was already used, an admin whose password has changed since the challenge was given, whose grant was revoked or who was deactivated, and anything malformed. The app cannot tell which, and must not try: it
counts its own tries (5 per challenge) and reads `challenge_expires_at`, and when either is over it starts again from the password. Each refusal on a challenge that is still good counts one of its tries, and a wrong or replayed
code counts one against the admin's lockout as well; the challenge is consumed by the last try or by the first success. Limits and the lockout: section 4 and section 3. A code is used once, so a second sign-in within the same 30 seconds needs the next code.

### `POST /auth/logout`

Ends the session of the token used: `{"data": {"revoked": true}}`. The admin's other sessions are not touched.

### `GET /me`

`{"data": {"admin_id", "name", "level", "scope", "markets", "token_expires_at", "capabilities", "grant_expires_at", "mfa": {"enrolled", "verified", "method"}, "contract": {"version", "min_supported"}}}`. `scope` is `all` (the platform owner) or `restricted` (the grant names markets, or the staff country scope restricts the admin: section 12); `markets` is what the server allows (the grant, narrowed by the staff scope while it is on), in
configuration order; **`capabilities` is the list of the parts of the API the grant includes** (`overview`, `markets`, `alerts`, `audit`, `sessions`, in that order: what the server enforces, so the app offers only those); `grant_expires_at` is when the grant ends (UTC, with a `Z`) or `null` for no end. **The app must never decide for itself what an admin may see; it reads this on every start, and right after a sign-in.** No e-mail is returned.

`mfa.enrolled` is whether the admin has a confirmed factor made for their own admin row (whether or not it is required just now); `mfa.verified` is whether **this session** passed the second factor, and `mfa.method`
is how: `"totp"`, `"recovery_code"`, or `null` (`verified` is then `false`: a session opened while the second factor was not required, which can only be seen while it is still not required). With the second factor
required `verified` is always `true` and there is always a method, because every other session is refused before it gets here.

`contract.version` is the level this server speaks and `contract.min_supported` the oldest it answers, `null` when no floor is set (see section 4, "The contract level of the shell"). A shell already below the
floor never reaches this route — the 426 carries both numbers itself — so this member is for warning an operator ahead of time, not for discovering a refusal.

```json
{"data": {"admin_id": 7, "name": "Platform Owner", "level": 1, "scope": "all", "markets": ["SA","AE","KW","QA","BH","OM"], "token_expires_at": "2026-11-17T18:00:00Z",
  "capabilities": ["overview","markets","alerts","audit","sessions"], "grant_expires_at": null, "mfa": {"enrolled": true, "verified": true, "method": "totp"},
  "contract": {"version": 1, "min_supported": null}}}
```

### `GET /sessions`, `DELETE /sessions/{id}`, `DELETE /sessions`

`GET` lists this admin's open sessions (not revoked, not expired, not idle), newest first, at most 100: `{"data": [{"id", "device_name", "created_at", "last_used_at", "expires_at", "current", "mfa_method"}]}`.
`mfa_method` is how the session passed the second factor: `"totp"`, `"recovery_code"`, or `null`. Never a token, a hash, an address or a user agent. `last_used_at` moves at most once a minute. `DELETE /sessions/{id}`
ends one of them and answers `{"data": {"id": 12, "revoked": true}}`; it takes effect on that token's next request. Ending the session in use is allowed.

`DELETE /sessions` (no id) **ends every open session of this admin except the one making the request**, and answers `{"data": {"ended": 2}}` with how many it ended (0 when there was no other). It is the
button for a lost phone. It is audited (`sessions.end_others`, with `ended` in its context).

### `GET /markets`

```json
{"data": {"scope": "all", "generated_at": "2026-11-17T06:00:00Z", "markets": [
  {"code": "SA", "path": "sa", "currency": "SAR", "active": true,  "storefront_enabled": false, "legal_ready": true,  "legal_entity": null},
  {"code": "AE", "path": "ae", "currency": "AED", "active": false, "storefront_enabled": false, "legal_ready": false, "legal_entity": null}
]}}
```

The configured countries in configuration order, limited to the admin's. `active` is the country's own switch (`gcc.storefronts.countries.XX.active`); `storefront_enabled` is the
platform switch (`gcc.storefronts.enabled`), repeated on each market so each is self-contained: a market serves customers when both are true. `legal_ready` is the answer of
`StorefrontLegalReadiness`, the code that decides whether a storefront may sell, and is `false` rather than an error when its table is missing; the default storefront is
grandfathered there. `legal_entity` is `gcc.storefronts.legal.legal_entity` (one value for every market today) or null.

### `GET /overview?market=all|SA|AE|KW|QA|BH|OM&from=YYYY-MM-DD&to=YYYY-MM-DD`

`market` is case-insensitive and defaults to `all`; for a restricted admin `all` means all of theirs and the answer says `scope: restricted`. The window defaults to the last 30 days
ending today and is **never longer than 92 days** (longer is cut at the old end with `clamped: true`; `from` after `to` is a 422). Only `to`: the 30 days ending there. Only
`from`: up to today.

```json
{"data": {
  "scope": "all", "market": "all", "generated_at": "2026-11-17T06:00:00Z",
  "window": {"from": "2026-10-19", "to": "2026-11-17", "days": 30, "clamped": false, "timezone": "Asia/Kuwait"},
  "freshness": {"mode": "live", "cached": false},
  "attribution": {
    "orders": {"rule": "all_lines_one_market", "via": "order_details.product_id > products.storefront_country"},
    "refund_requests": {"rule": "product_market", "via": "refund_requests.product_id > products.storefront_country"},
    "stock": {"rule": "live_physical_products_by_market", "via": "products.storefront_country"},
    "inventory": {
      "reservations": {"rule": "product_market", "via": "inventory_reservations.product_id > products.storefront_country", "counted_when": "state = reserved"},
      "allocation": {"rule": "product_market", "via": "inventory_pools.product_id > products.storefront_country", "short_when": "deficit > 0"},
      "reason": null
    },
    "products_country": "column",
    "currency": {"per_order": false, "reason": "order_currency_not_recorded"}
  },
  "markets": [{
    "market": "KW", "storefront_currency": "KWD", "active": true,
    "orders": {"total": 3, "by_status": {"pending": 1, "confirmed": 0, "processing": 0, "out_for_delivery": 0, "delivered": 1, "returned": 0, "failed": 0, "canceled": 1}, "latest_at": "2026-11-16T04:00:00Z"},
    "sales": {"by_currency": null, "reason": "order_currency_not_recorded"},
    "pending_fulfilment": {"count": 1},
    "returns": {"refund_requests": 1, "pending": 1},
    "stock": {"zero": 2, "low": 1, "oversold": 1, "oversold_units": 4, "reserved": {"holds": 3, "units": 9}, "allocation": {"pools": 2, "short": 1, "short_units": 5}},
    "alerts": {"critical": 1, "warning": 1, "info": 1}
  }],
  "unattributed": {
    "orders": {"total": 4, "by_status": {"pending": 2, "...": 0}, "latest_at": "2026-11-13T07:00:00Z"},
    "sales": {"by_currency": null, "reason": "order_currency_not_recorded"},
    "pending_fulfilment": {"count": 3}, "returns": {"refund_requests": 2, "pending": 1},
    "stock": {"zero": 1, "low": 0, "oversold": 0, "oversold_units": 0, "reserved": {"holds": 1, "units": 11}, "allocation": null},
    "reason": "no_single_market"
  }
}}
```

* `orders` and `returns` are **flows of the window**: orders created in it, refund requests created in it (and how many of those are still pending).
* `pending_fulfilment` is a **state**: orders in `pending`, `confirmed` or `processing` (`SlaService::AWAITING_SELLER_STATUSES`) created within the last 92 days, whatever window was
  asked for, so an order that has waited longer than the window is still waiting. An order older than 92 days is not looked for.
* `stock` is a state too: live physical products at zero stock, at low stock and BELOW zero, what is held for orders and not yet shipped, and how a pooled stock is divided
  between the channels that offer it. `oversold` is a SUBSET of `zero`, which has always counted `current_stock <= 0`; `reserved` and `allocation` are `null` rather than zero on
  an installation that records neither, and `attribution.inventory` carries the reason once. See section 6.
* `by_status` has every status the platform knows (`getOrderStatusList()`) in its order, with zero, then any other status found, by name.
* `unattributed` is `null` unless the admin is the platform owner asking for `all`. A single market, and a restricted admin, never see it: those orders might belong to a market
  the admin may not see.
* `alerts` counts the market's alerts by severity.

### `GET /alerts?market=&severity=&page=&per_page=`

```json
{"data": [
  {"id": "6b0e0d0a1c2f3e4d", "severity": "critical", "market": "KW", "code": "legal_not_ready",
   "message": "The KW storefront is active but its legal documents are not approved, so it cannot take orders.",
   "deep_link": "markets/KW", "generated_at": "2026-11-17T06:00:00Z"}
], "meta": {"scope": "all", "market": "all", "severity": null, "generated_at": "2026-11-17T06:00:00Z", "page": 1, "per_page": 25, "total": 5, "has_more": false}}
```

Derived when asked from signals that already exist, stored nowhere. `id` is a stable hash of the code and the market. Worst first, then by market in configuration order, then by code.
At most 50 to a page (default 25); a page or size that is not a positive whole number is the default; a size above 50 is 50.

| Code | Severity | Raised when | Defaults |
|---|---|---|---|
| `legal_not_ready` | critical | storefronts are on, the market is active, and `StorefrontLegalReadiness` says it is not ready | n/a |
| `stock_zero` | warning, critical | live products at zero stock reach the count | 10, 50 |
| `stock_oversold` | info, warning, critical | live products whose stock has gone PAST zero. **One is already information**, and the counts are far lower than the zero-stock pair, because stock below zero is not an ordinary state of a shop but a sale that should have been refused. It is raised BESIDE `stock_zero` and not instead of it: a product below zero is at zero stock as well, and both alerts are true of it | 1, 5, 20 |
| `orders_pending_too_long` | info, warning, critical | orders still `pending` after the age, created within the last 92 days | 1, 5, 20 after 24 hours |

The counts and the age are in `config/gcc.php` (`platform_admin.alerts`). A critical count below its warning count is raised to it, and a value that makes no sense is the default.
`deep_link` is a path the app can route; at level 1 every alert links to the market (`markets/XX`). A message carries only a count and a market, never a name.

### `GET /orders?market=SA&from=YYYY-MM-DD&to=YYYY-MM-DD&page=&per_page=`

One market's orders as a LIST rather than as a count: the first drill-down under a market card (L-13). Capability `orders`, which **a grant has only when it names it**.

```json
{"data": {
  "market": "SA",
  "generated_at": "2026-11-17T09:00:00Z",
  "window": {"from": "2026-10-19", "to": "2026-11-17", "days": 30, "clamped": false, "timezone": "Asia/Riyadh"},
  "orders": [{"id": 16, "status": "processing", "placed_at": "2026-11-17T15:00:00Z", "lines": 1}],
  "attribution": {"rule": "all_lines_one_market", "via": "order_details.product_id > products.storefront_country"}
}, "meta": {"page": 1, "per_page": 25, "has_more": false}}
```

* **ONE market.** `market` is required and `all` is the same `one_market_only` 422 a report and an export answer. An unknown market is `invalid_market` (422) and a market outside the
  viewer's is `market_not_allowed` (403), whatever the case it was written in -- the same `MarketSelector` as every other read, so the refusal and the audited market are in one place.
* **It is the counts' own list.** The market of an order comes from the subquery the overview's figures are grouped from, so a market's list is exactly as long as the overview counts
  it for the same window, and the answer names the rule (`attribution`). An order of no single market is in no market's list and is never shown as Saudi.
* **No customer.** The order's id, its status, the moment it was placed and how many lines it has. No name, address, telephone number or e-mail, and no such column is read: what
  personal data a list of this API may show is an open owner decision (section 10), so until it is answered none is shown.
* Newest first (`placed_at` then id, both descending, so paging cannot drop or repeat a row). At most **50** to a page, default 25, page at most 1000; a page or size that is not a
  positive whole number is the default. `has_more` is read by taking one row more than the page, so nothing counts the table.
* One page is **one statement** over the bounded range, whatever the market holds.

**The three endpoints this section does not yet describe** are `GET /health` (L-09), `GET /reports` and `GET /exports/orders` (L-14) and the three `devices` routes (L-16). They are
built and tested; the shapes live in their cards and their tests, and writing them up here is outstanding.

### `GET /audit-logs?page=&per_page=`

`{"data": [{"id", "admin_id", "action", "market", "token_id", "ip", "user_agent", "context", "created_at"}], "meta": {"scope", "page", "per_page", "has_more"}}`. Newest first, 25 to a
page, at most 100, and the page number is at most 1000. The **platform owner reads every line; a restricted admin reads only their own.** It never counts the table. `action` is one
of `auth.login` (a session was opened; its context says how: `mfa` is `totp` or `recovery_code` when the second factor was passed), `auth.login_failed`, `auth.logout`, `auth.mfa_challenge` (a good password, a challenge given),
`auth.mfa_failed`, `auth.mfa_locked`, `mfa.recovery_code_used` (its context holds `recovery_codes_left`), `me.view`, `sessions.list`, `session.revoke`, `sessions.end_others`, `session.evicted`, `session.idle_ended`, `session.grant_expired`, `markets.view`, `overview.view`, `orders.view`, `alerts.view`,
`audit_logs.view`, `grant.created`, `grant.updated`, `grant.reactivated`, `grant.revoked`, `tokens.revoked`, and the console's `mfa.enrolled`, `mfa.confirmed`, `mfa.confirm_failed`, `mfa.reset`, `mfa.status`, `audit.pruned`. The hashes of the chain are not part of a line as the API shows it.
`context` of a request holds the status and the few parameters the API takes (`id`, `market`, `from`, `to`, `severity`, `page`, `per_page`; `ended` for `sessions.end_others`); never a password, a token, a challenge, a code, an e-mail or
any other value the request carried. `ip` is as sent; `user_agent` is cut at 255 characters. A line of the console carries `operator` (`user`, `host`) in its context, which only the platform owner is shown.

## 6. How a figure is put in a market, and what the schema cannot say

An order carries **no country and no currency** of its own (`docs/gcc/STOREFRONT_FLAG_RUNBOOK.md`: "Orders carry no country. It follows from their lines"). Each product row belongs to
one country, `products.storefront_country` (default `SA`), and never changes it. So:

* **Orders.** An order is in a market when it has at least one line, **every line is a product that still exists, and all of them are of one configured market.** Any other order
  goes to the `unattributed` bucket: no line, a line whose product has been deleted, lines of more than one market (a point-of-sale cart can mix them), or a product whose country is
  not a configured market (a typo). It is **never counted as Saudi** to make the numbers look whole.
* **Refund requests** are in the market of the product they were made for (`refund_requests.product_id`).
* **Stock** counts the live physical products of the market: `product_type = 'physical'`, `status = 1`, `request_status = 1`, and either added by the admin or by a seller whose
  status is `approved` and who is not deleted. How low is low is the rule of the seller's inventory screens (`InventoryService::stockLimitFor`): the seller's own `stock_limit` when
  it is a whole unit or more, otherwise the platform setting `stock_limit`, never below one; stock above zero and up to the limit is low. A test compares the SQL with
  `InventoryService` for six seller limits, fractions included. The brand and business-mode filters of `Product::active()` are not applied.
* **Held stock and a divided pool.** `stock.reserved` is the stock promised to orders and not yet shipped: how many holds and how many units, counted in
  `inventory_reservations` only while a hold is in state `reserved`, because `committed` and `released` are the two ends of a hold's life. `stock.allocation` is how the stock of a
  pooled product is divided between the channels that offer it: how many pools, how many of them could not give every channel its share (`inventory_pools.deficit > 0`) and how
  many units short those are -- the allocator's own record of what it could not satisfy, not a second opinion about it. Both are in the market of the product they are for, so a
  hold or a pool whose product has been deleted, or whose country is not a configured market, is in the `unattributed` bucket and is **never counted as Saudi**.
* **Both inventory tables are dormant and are reported as absent rather than as empty.** They arrive in two migrations of their own, so an installation can have run one and not
  the other. While a table does not exist its figure is `null`, not zero, and `attribution.inventory` names the half that is missing with the reason
  `inventory_stack_is_not_installed`: "nothing is held in this market" and "this platform does not record held stock" are different facts, and a zero would state the first while
  meaning the second. **`oversold` is not dormant**: `products.current_stock` is a signed integer and stock below zero is a real state of the data that the monitoring page already
  counts as a finding, so the figure is read from the same rows, in the same statement, as `zero` and `low`.
* **While `products.storefront_country` does not exist** (the products migration of the storefront stack has not run) every product belongs to the default storefront, which is what
  the storefront code itself assumes before that migration, and the answer says `products_country: "default_storefront"`. An order with no line or a deleted product is still
  unattributed.
* **Money is not reported.** An order stores no currency, and its amounts are stored in the platform's base currency whatever the market it was sold in (the storefront prices are
  not converted; GCC-05 is undecided). Adding them up under a market's currency, or under any, would be a guess; adding currencies would be wrong. So `sales` is `null` with the
  reason `order_currency_not_recorded`, **no figure of money appears anywhere in the answer**, and `storefront_currency` is the configured currency of the storefront, not the
  currency of an order. The contract keeps a place for it: when orders record their market and currency, `sales.by_currency` will become a list grouped per market and currency, and
  `attribution.orders` will name the new rule. Nothing will ever be summed across currencies.
* Every figure is a **grouped aggregate in the database over a bounded range** (the window, or at most the last 92 days); nothing is loaded row by row, and the number of statements
  does not grow with the data (a test adds sixty of everything and counts again). An overview reads the orders twice, an alerts call once. The held stock and the pools cost one
  grouped read each, and nothing at all on an installation whose tables are absent, where the figure is a reason instead.

## 7. Isolation and what is never done

* **No storefront logic applies.** The routes are mapped under the `api` prefix but outside the `api` middleware group, so no telemetry row, no analytics, no response shape for
  the developer portal, no visitor cookie; and none of the storefront middleware. The market is only ever the validated `market` parameter. Nothing reads the product model, whose
  scopes would narrow a query to the storefront a request asks for. `StorefrontIsolationTest` asks every read route with 8 ways a storefront picks a country (`X-Storefront` for
  another country, `?storefront=`, `?country=`, a currency, a session value, a cookie, language and CDN country headers) and 5 market values, and the answers do not change; a control
  shows the header *would* narrow a product query on this very path.
* **No server-side cache.** A cache entry would need a key for the market and for who asks, and level 1 has none; a test scans the code. Two admins with different scopes,
  alternated three times over every route, only ever see their own answer. Everything the browser or a proxy might keep is forbidden by the headers.
* The developer portal lists every route as an internal admin endpoint with bearer authentication and **never to a partner** (`RoutesTest` builds the manifest).
* **Isolation is tested against the route table, not against a list somebody wrote** (`tests/Feature/PlatformAdmin/PlatformTokenIsolationTest`, `IdentityIsolationTest`, `PlatformSignInIsolationTest`): (a) for **every route of the application outside
  `/api/platform-admin/v1`** (2,112 of them today, read from the route table) a request that carries a live platform token answers exactly what the same request with a token of the same shape that was never issued answers (asking with no header at all would not do:
  the customer API's guest cart, for one, answers differently when any bearer header is there), and afterwards the time a session was last used has not moved and the platform trail has no line (three routes whose page carries a token of its own are compared by status and
  redirect, and a control route that does read the token shows the comparison can tell); (b) for **every route of the platform API** the credential of every other identity (a seller's token, a seller staff member's token, a seller's API key, a customer's token, a delivery
  man's token, a live challenge of the second step), put as a bearer token, as the whole `Authorization` header, in `X-Auth-Token` and `X-Api-Key`, in the query string and in a cookie, and the panel session of a seller and of an admin who holds a grant, get the uniform 401 and write
  nothing; (c) the platform sign-in does not accept the right password of a seller, a seller staff member, a customer, a delivery man or an admin who holds no grant, the sign-ins of the sellers (v2 and v3), the customers and the delivery men do not accept an admin's, and an e-mail that
  exists as an admin, a seller and a customer authenticates, at each place, only with the password of that place's own row (the success of the customer's and the delivery man's own sign-in needs the OAuth client tables, which the test database does not have, so only their refusals are
  exercised); (d) no foreign key joins a platform table to a table of another identity in either direction, no migration outside the platform's own mentions a platform table, the platform tables hold none of the credential columns the other identities use (`auth_token`, `password`,
  `remember_token`, `api_key`, ...), and no other credential has the shape of a platform token (`lkpa_`) or challenge (`lkpc_`). (The platform and the seller API keys both have a column called `token_hash`: what is kept apart is the table, the prefix and the code that reads it, not the name.)

## 8. What level 1 deliberately does not do

* No write to any business data: orders, products, stock, settings, payments, wallets, sellers, markets. A test snapshots every table before and after a series of reads: only
  `platform_admin_tokens.last_used_at` and `platform_admin_audit_logs` change, and no statement that changes anything runs on another table.
* **No HTTP route to enrol, reset or change a second factor, and no passkeys, SMS or e-mail codes** (section 10). The factor is a TOTP app and recovery codes, managed from the console only.
* No push notifications or topics, no exports, no step-up confirmation (there is nothing critical to confirm).
* No level 2 (support, local operations) or level 3 (store status, payment accounts, legal publication, stock release, permissions, integrations). A grant of any level but 1 cannot
  be made, and level 1 is all that is read.
* Not yet in the overview, because nothing in the repository gives them a per-market signal: payment and webhook incidents, reconciliation, provider, marketplace, Odoo and logistics
  health, staff and seller issues, approvals. Each can be added as a new read endpoint without changing these.

## 9. Assumptions and choices that could have gone another way

1. **Routes outside the `api` middleware group** (the way the webhooks are mapped): the group records a telemetry row, an analytics event and a response shape for every request and
   sets a visitor cookie. That would write outside the API's own tables, and record answers that are private.
2. **Foreign keys.** The repository has no schema for `admins` (it comes from the installation dump). The grants migration ties `admin_id` to `admins.id` with a foreign key
   (`ON DELETE CASCADE`) only after checking that it can: `BIGINT UNSIGNED` on an InnoDB table (MySQL, MariaDB), or SQLite, which does not tell the types apart. Otherwise, or if the
   database refuses the key for any other reason, it logs one warning and goes on: a feature that ships switched off must never fail the deploy of the whole application. The
   tokens table is always tied to the grants table (its types match by construction). What the key gave, that a reused admin id cannot inherit a grant, does not depend on it: the
   grant carries a fingerprint of the admin row it was made for (SHA-256 of the id and the row's `created_at`), and a grant whose fingerprint is not the row's is no grant, at
   sign-in and on every request. The creation time is used rather than the e-mail because an admin may change their e-mail. A row without a creation time (the first admin of an
   installation may have none) is told apart by its id alone, so for such a row the guard is as good as the id; a restored dump with the same ids and the same creation times is the
   same admin. Changing the formula would end every grant that exists, and a test pins it.
3. **Sign-in limits.** The same number for one address and for one address with one e-mail would make the second unreachable. One e-mail from one address gets the configured number;
   the address as a whole gets three times that. Distributed guessing from many addresses is not throttled by this, which is what MFA is for.
4. **Audit of requests is written before the answer is returned**, in the same request: if the trail cannot be written, the request fails instead of being served unrecorded. The audit
   of a *refused sign-in* is written after the answer, so a refusal costs the same whoever the caller claims to be.
5. **The dummy hash** used when there is no such admin is made once per process, with the configured cost. If the real admins' hashes were made with another cost, the time of a
   refusal for an unknown admin differs from one for a known admin by that ratio; the throttle bounds how often it can be measured.
6. **`token` storage.** A token is 64 random characters, so a fast SHA-256 is enough and a lookup is one indexed read. A bcrypt per request would add nothing against a random secret.
7. **`GET /sessions` and `DELETE /sessions/{id}` work on open sessions only** (not revoked, not expired), so what is listed is exactly what can be ended.
8. **`storefront_enabled`** is the platform switch repeated on each market; there is no separate per-market storefront switch in the configuration.
9. **`legal_entity`** is the one value of `gcc.storefronts.legal.legal_entity`; the configuration has no per-market entity yet.
10. **Day boundaries are in the process time zone, not in `app.timezone`.** Laravel stamps `created_at` with the wall clock of PHP's default zone, and `ConfigServiceProvider` sets that
    zone at boot from the merchant's `timezone` setting (`business_settings`), while `config('app.timezone')` stays at the repository's `Asia/Dhaka`. A window cut at `app.timezone`
    midnight would be hours away from the orders it counts, so the window is cut in the zone the rows are stamped in, and the answer reports it (`window.timezone`). It is the one
    zone of the platform, not that of a market: a Kuwaiti and a Saudi order are both counted in the day of the platform's setting. A test runs the overview with the zone set to `Asia/Kuwait`.
11. **Stuck orders** are looked for only within the last 92 days, and an alert is raised per market, so stuck orders that belong to no market raise none (they are in the overview's
    bucket).
12. **Table sizes.** `products` and `refund_requests` are read by one aggregate each. `refund_requests` has no index on `created_at` in the migrations of this repository, and the
    statuses and `request_status` of `products` may not be indexed; on a very large catalogue read the plans before enabling the flag.
13. **Level 1 grants have one level.** `level` exists so level 2 and 3 can be added without a migration; a level above 1 cannot be granted now.
14. **The monitoring that already counts every request** (route pattern and status, no body, no token) counts these routes like any other.
15. **Where the second factor hangs.** The tables of the factor and of the challenge have a foreign key to the grants table only, not to `admins`: a conditional key (point 2) for each would have been a second and a third place to get the
    types of a database one cannot see wrong. A factor remembers the admin row it was enrolled for exactly as a grant does (the same fingerprint), and a factor whose fingerprint is not the row's is no factor: so an `admins` id that is reused, with
    or without the key to `admins`, inherits neither the grant nor the secret.
16. **The code.** SHA-1, 6 digits, 30 seconds, what every authenticator app does; three steps are accepted (one either side), so a random guess is right three times in a million. The guesses are bounded at 5 a challenge and 10 an
    admin in 15 minutes (about a thousand a day at the most, before anyone notices the `auth.mfa_locked` lines). Each step is accepted once per admin; the check and the marking of the step are one transaction on the factor's row, so two
    requests with the same code cannot both succeed. A recovery code is single-use under the same lock.
17. **A challenge is not bound to an address or a device**: a phone moves between networks in the middle of a sign-in. What binds it is that it is random, short-lived, used once, and that every try on it is counted.
18. **The idle clock is coarse.** It reads the time of last use, which moves at most once a minute (section 5), so a session is ended up to a minute later than the exact idle time. It ends on its first use after: nothing sweeps the table.
19. **The counters of the limits live in the application cache**, like the sign-in's. Behind several servers they need a shared cache store (database or Redis); with a store that is not shared, or that is cleared, a limit starts again.
20. **Sessions without the second factor** are refused while it is required, however they were opened: turning the switch from false to true ends the staging sessions on their next request rather than letting them live out their eight hours.
21. **The operator identity** in the console's audit lines (user and host) is what the process says about itself, not an authenticated identity: it tells a reader which machine and account ran the command, which is what the server's own login and
    audit trail can then be asked about.
22. **Capabilities are named in the grant, and absent means all five.** A column that is SQL NULL is the five parts this revision knows (so a grant made before it exists keeps working); a value that cannot be read grants none. NULL is every capability that exists, today those five: a capability added later to `PlatformAdminGrant::CAPABILITIES` is thereby given to every grant that names no list, which is a decision to make deliberately at that time. `GET /me` and `POST /auth/logout` need none.
23. **A grant ends at the end of a calendar day** of the platform's time zone, because that is how an operator names it; there is no time of day to get wrong. The check is made at sign-in and on every request, so there is nothing to sweep, and the sessions of an expired grant are ended by
    the first request that meets it.
24. **The chain is serialised by one row, not by the table.** Locking "the last line" does not work on an InnoDB table (a second writer that waited for it still sees the line it waited on as the last); the writer takes the row of the tail, which always exists, reads the hash of the last line
    from it, writes the line, and moves the tail. The tail is part of what `audit:verify` checks.
25. **What is hashed** is everything of a line except its id (which the database gives after the line is written, and which the order of the chain already pins). The context is hashed with its keys sorted at every depth, so that a database that returns the members of a JSON column in another
    order (MySQL does) gives the same hash.
26. **A prune never cuts the newest lines** (it takes a run from the start of the trail, all older than the days given), **never runs on a trail that does not verify**, and its checkpoint is not itself chained (a person who can write the table can forge it): the record of the head hash that the operator keeps elsewhere is what answers that.

27. **Transactions that end by writing a line are done again on a deadlock.** The tail is a point every writer passes through, so two transactions that take the same rows in opposite orders can deadlock on it; the sign-in, the second step and the operator's grant, revoke and
    second factor commands are tried up to three times, and what a failed try did is rolled back (a code is not used up, a line is not written twice). A deadlock that goes on reaches the caller.
28. **The readiness check costs about twenty metadata queries per request while the API is switched on** (it asks for each table and for each column that a migration added). It is cheap on the databases this runs on and it is what makes a half-migrated deploy answer 503 instead of 500; if it ever shows up in a
    profile, cache a positive answer for a minute.

## 10. Open owner decisions

1. **MFA policy.** Built: TOTP at sign-in with recovery codes, required by default for everybody who holds a grant. Open: whether a restricted viewer (a country manager) is held to the same, a lighter or a different factor (the switch
   is global today); whether level 2 and 3 will also want a step-up before an action; passkeys (WebAuthn) beside or in place of TOTP. SMS and e-mail codes are not offered, on purpose.
2. **Token lifetime.** 480 minutes (8 hours) at the most, 60 minutes idle, 5 open sessions, no refresh, a new sign-in when it ends. Other numbers, or a refresh token, are a product decision (the three numbers are configuration).
3. **Who holds a grant.** The platform owner (all markets) and, if wanted, country managers as restricted viewers. Should a restricted viewer ever see the unattributed bucket?
4. **GCC-05: record the market and the currency on every order.** That is what would let `sales` be reported per market and currency. Until then no sales figure is given.
5. **Alert thresholds** (zero stock 10 and 50, pending 24 hours, 1, 5 and 20) are placeholders for a Saudi-sized catalogue.
6. **An allow-list of addresses, or a separate host,** for the API (it is a bearer API reachable from the internet like every other).
7. **A panel screen** for an admin to see and end the sessions of an API (today: the console and the API itself).
8. **Retention** of `platform_admin_audit_logs`: how long to keep the trail. Built: nothing removes a line by itself; `audit:prune` exists, needs `--confirm`, refuses a trail that does not verify and is never scheduled. Open: the number of days, whether the lines
   are exported before they go, and who writes down the head hash `audit:verify` prints, how often, and where.
9. **Push topics** for global alerts, separate from marketing and seller topics (GCC-20), once there is something to push.
10. **Recovery-code custody.** The ten codes are printed once, on the operator's terminal, and only a hash is kept. Who keeps them is the owner's to decide: the admin alone (the operator then sees them once, at enrolment), or the owner
    as well (a second holder of every admin's way in). Also whether the codes should be allowed at all for the platform owner's own account, given that a code defeats the factor.
11. **Lockout.** Ten wrong codes lock an admin's second step for 15 minutes, so somebody who knows the password can keep a real admin out. There is no `unlock` command (waiting out the window, or changing the password, is the way
    today). Whether the owner wants one, or wants a lockout that slows rather than stops, is open.
12. **Capability sets and ends of grants as policy.** The five capabilities are the surface of the API, not roles. Which set a country manager gets, whether `sessions` (the right to end one's own sessions) should always be included, and whether every grant of a restricted
    viewer, or of the owner, must carry an end date, are the owner's to decide; the commands make any of them possible and none of them a default.
13. **The staff country scopes (GCC-14c)** narrow what an admin who is also a restricted employee reaches here. The decisions it leaves are listed in section 12.

## 11. Review gates before merge and release

`AUTONOMOUS_DEVELOPMENT_CHARTER.md` section 5 requires a security review of GCC-20 privileged authentication before merge or release. This implementation is a draft for that review:
what to read first is `AuthenticatePlatformAdmin`, `PlatformAdminTokenService::principalFor`, `PlatformAdminAuthenticator`, `PlatformAdminSecondStep` (the check of a code), `PlatformAdminMfaVerifier`, `Totp`, `PlatformAdminGate` and the migrations of
the grants, tokens, factors and challenges tables. Not done here: no run against a real database, no production access, no migration on any real environment, no push, no merge. G0 remains open.

Tests, in `tests/Feature/PlatformAdmin` (run on SQLite, SQLite with `APP_DEBUG=true` and MySQL 8; CI runs them on SQLite with the whole suite, and on MySQL and MariaDB with the storefront
set): the authorization matrix (every route of the route table against every caller that is not the holder of a live token: no header, malformed headers, other schemes, a seller, a seller's
staff, an API key, a customer, a panel session, expired, ended, deactivated, changed password, revoked or foreign grant), the reverse isolation tests, the flag and dormancy tests, login,
sessions, request limit, markets, overview, alerts, audit logs, storefront isolation, read-only proof, routes and migrations (the grants migration against every shape of `admins`:
`BIGINT UNSIGNED`, `INT UNSIGNED`, signed `BIGINT`, MyISAM, partitioned, missing), the grant fingerprint, and the grant commands.

**Delivery 2.** Capabilities (every route against every capability set, in the route table's own order, and the middleware's 403 against the 401s), expiry (a grant that ends at the end of a day, at sign-in, on a request, at the second step, and the sessions it ends), the grant commands
(`--confirm-owner`, `--capabilities`, `--expires`, `list`, the operator on every line), the chain (the hash, the canonical JSON, the tail, writers taken in turn, a changed line, a removed line, an inserted line, an unhashed line after hashed ones, a missing tail, tampering with
the newest lines), the refusal of the model and of its builder to update or delete, `audit:verify` and `audit:prune` (what is cut, the checkpoint, the refusal on a broken trail and without `--confirm`, never scheduled), the retry of a transaction chosen as the victim of a deadlock, and the three tests of isolation described in section 7.

**How the tests of this revision sign in.** The tests of level 1 (login, throttles, sessions, the matrix, read-only proof) run with `gcc.platform_admin.mfa.required` set to `false` by their test base, `PlatformAdminTestCase`,
because what they pin is the one-step sign-in and the behaviour of a session, which MFA does not change; that is the staging mode of section 2 and it is also tested as such. Everything about the second factor is in its own
tests, which switch it on: `Totp` and `Base32` against the vectors of RFC 4226 and RFC 6238 appendix B and RFC 4648 (`tests/Unit/PlatformAdmin`), and in `tests/Feature/PlatformAdmin` the migrations of the factor, the challenge and the session
column, the console commands, the two-step sign-in (every refusal, the attempts, the replay, the lockout, the limits, what is stored and what is never stored), the session rules (idle, the number of sessions, ending the others, the
refusal of a session without the factor), and the shape of every answer that changed. The test base provides `enrolFactor()` and `totpFor()` so that a test can sign in the way the app does.

## 12. The staff country scopes (GCC-14c): what an admin who is also an employee reaches

An admin who signs in to this API may also be an employee of the admin panel whose work is limited to some countries (`gcc:staff-country:assign`,
[GCC-14C-STAFF-COUNTRY-SCOPES.md](./GCC-14C-STAFF-COUNTRY-SCOPES.md)). The owner decided that the two lists are intersected: **through a platform token nobody reaches a market that their staff
scope forbids them in the admin panel.** This section is the rule, what it does to each part of the principal, what the seller app sees, what the console says, what it costs, the decisions it
leaves and what must be true before both switches are on.

**There is no switch of its own.** The staff switch (`GCC_STAFF_COUNTRY_SCOPES`, `gcc.staff.country_scopes`, default off) decides for both. The change adds no migration, no route, no setting, no
error code and no member of any answer. While `GCC_PLATFORM_ADMIN_ENABLED` is off nothing here is reached.

### The rule

The markets an admin reaches are **the markets their grant names that their staff scope also leaves them** (the grant's markets intersected with the staff countries), in the order of the
configuration:

| Grant | Staff scope (admin panel) | Reaches | `scope` |
|---|---|---|---|
| every market (the platform owner) | none: the master admin, an employee with no row, the staff switch off, or its table or the products column missing | every market | `all` |
| every market | KW, SA | SA, KW | `restricted` |
| every market | all six countries | all six, and still **not** the platform owner (below) | `restricted` |
| KW, AE | KW, SA | KW | `restricted` |
| KW | SA | no market: the admin signs in and sees nothing | `restricted` |
| any | only values that are no country (`zz`, `kw` in lower case, an empty value) | no market | `restricted` |
| any | the master admin, whatever rows it has | what the grant names | as the grant says |

Who the staff scope restricts is its own rule and is not repeated here: the master admin (`admin_role_id` 1) never is, an employee with no row is not, and an employee with rows is restricted to the
rows that name a configured country (rows that name none leave them restricted to nothing, never to everything).

**When the staff scope is read.** On every request, and at every step of a sign-in that opens a session, from the rows as they are then. Nothing is kept from one request to the next, so a scope
that is given, changed or taken back, and the staff switch turned on or off, applies to the next request made with the same token. **No session ends** when a scope changes.

### What it does to each part of the principal

* **Markets.** The intersection above, worked out in one place: `PlatformEffectiveMarkets`, called by `PlatformAdminTokenService` when it builds the principal. Everything that reads the markets of a
  principal reads that: `GET /me` and the `me` of both steps of the sign-in, `GET /markets`, and the `market` parameter and the markets answered for by `GET /overview` and `GET /alerts`. The console
  `list` and `grant` ask the same service. No reader holds a copy of the rule. The staff scope itself is asked of `StaffCountryScope::countriesOfAdmin()`, the one place that knows its switch, its
  table and how rows become countries.
* **The platform owner (`scope: all`).** An admin the staff scope restricts is **not** the platform owner of this API, even when their grant names every market and their scope names all six
  countries: the staff scope hides the orders that belong to no country from every restricted employee (GCC-14c, slice 2), and this API does the same. The flag is read in two places, and `scope` of
  every answer follows it: the bucket `unattributed` of `GET /overview` (shown only to the platform owner asking for all markets, so never to a restricted employee), and `GET /audit-logs` (the platform
  owner reads every line and the operator of the console lines; everyone else reads only their own lines, without the operator).
* **Capabilities, level, expiry and sessions** are unchanged: the intersection is about markets only. A narrowed admin who is not granted `alerts` still gets `403 capability_not_allowed`.
* **An empty intersection** is a principal with no market, as a grant that names none is today: it signs in, `GET /me` says `markets: []`, the readers answer with empty lists, and a named market is a
  403. It is not a failure to sign in.
* **The `market` parameter.** A market outside the markets the admin reaches is `403 market_not_allowed` with the same body whichever list forbids it, so it is no oracle for the staff scope. The
  spelling of a code (case, spaces), `market=all` (all of theirs), a list, a keyed list or two codes in one value are what they always were (a 200 for theirs, a 403 for a market outside, a 422 for
  what is not a market); a body, another header or a parameter of another name never names a market.
* **Audit lines** have the format they had, and no line is written about the staff scope, per request or otherwise.

### What the seller app sees

A value, never a shape. `GET /me` and the `me` of the sign-in say `markets` (shorter than the grant, possibly `[]`) and `scope` (`restricted` for an employee the staff scope restricts, even when
their grant names every market). No member, header or error code is added, so an app that handles a restricted viewer already handles this admin. The app is **not told why** the list is shorter
(owner decision 5 below). What it must already do for any restricted viewer applies: an admin whose `scope` was `all` and becomes `restricted` loses the bucket of orders without a market
(`unattributed` is `null`) and the whole audit trail (only their own lines are read).

### The console

Both commands only read; the staff scope is for the staff commands to change.

* `gcc:platform-admin:list`, while the staff scope is on and enforced (its switch is on, and its table and `products.storefront_country` exist), adds two columns: `Staff countries` (what the staff scope
  restricts the admin to: `-` when it does not, `none` when it restricts them to nothing) and `Effective markets` (the markets of the grant that this leaves them; for an admin it does not restrict, the
  markets of the grant). Otherwise the table is the one it always was.
* `gcc:platform-admin:grant`, in the same state, prints one warning after it succeeds when the staff scope of the admin leaves them fewer markets than the grant gives: it says what the staff scope
  restricts them to, what they will see, and that the grant is as given. It prints nothing otherwise.

### What it costs, and when it is dormant

| State | What the API asks about the staff scope |
|---|---|
| The staff switch is off | **Nothing.** The same statements run, whether or not the staff table exists (a test compares them), no statement names the staff table or the role of the admin, and the answers are those of the grant alone (the six-country tests check every grant against six kinds of staff scope, and compare the whole answers of the readers body for body with the grant that has no row) |
| On, and the table or the products column missing | The schema, to find that out, and then it acts as off: nothing is narrowed and nothing fails |
| On, the master admin | **No statement more on a request:** the statement that reads the admin reads the role as one more column, and the staff table and its schema are not touched. A sign-in that opens a session reads the role with one statement of its own |
| On, an employee | The schema and one read of the rows, for each request (and for the step of a sign-in that opens a session) |
| On, a request refused at authentication (the uniform 401) | Nothing: the staff scope is asked only after every check of the token has passed |

Nothing is cached, in a static, in the container or in the application store, so a scope that changes takes effect at once. The API only reads the staff table: a test compares it before and after a series of
requests.

### Decisions the owner is left

Each was decided on the safest reading that can be reversed (turning the staff switch off restores the answers of the grant alone, at the next request, with nothing to clean up). None is financial or legal.

1. **Whether this API should follow the staff scope at all, and with which switch.** Built as the recorded decision says: the stricter of the two lists wins, with no switch of its own, so the staff switch decides for
   both. Open: a switch of its own for this API.
2. **A restricted employee is not the platform owner of this API.** No bucket of orders without a market, only their own audit lines, no operator on the lines: also when their grant names every market and their
   staff scope names all six countries, consistent with the staff scope hiding the orders that belong to no country from every restricted employee. Open: whether such an admin should still read the whole audit
   trail (the lines of other admins say which market somebody asked about, and nothing else) or the bucket; each is one condition (`PlatformAdminPrincipal::isPlatformOwner()`).
3. **Who may hold a grant for every market.** An employee who is not the master admin and holds no staff row is the platform owner of this API when their grant names every market: `gcc:platform-admin:grant
   --confirm-owner` has never asked for the master role, and the staff scope restricts nobody until a country is assigned. Open: whether a grant for every market should need the master role, or a staff scope.
4. **No market is not a refusal.** An admin whose staff scope leaves them no market (their staff countries are not in their grant, or damaged) signs in and sees nothing, which tells nobody what the staff scope
   is. Open: whether such a sign-in should be refused (the uniform 401), which would tell the admin that something is wrong at the cost of saying more.
5. **The seller app is not told why `markets` is shorter than the grant.** Open: a member that appears only when the staff scope narrows the list, so that the app can say why. It would be a contract change for
   the app.
6. **A change of the staff scope ends no session.** It narrows (or widens) the next request. Open: ending the sessions of an admin whose staff scope changes, which needs the staff commands to write to this API's
   tables (they do not, and the two features are kept apart).
7. **A staff scope that is on but not enforced narrows nothing** (the table or the products column is missing: the staff scope's own rule, kept here). An operator who switches it on before the migrations have run
   has not restricted anybody, and `gcc:staff-country:list` and the two extra columns of `gcc:platform-admin:list` are how they find out.

### Before both switches are on

1. Deploy with both switches off. Nothing changes.
2. Run the migrations of both features: the nine of this API (section 3), `2026_11_28_100000_create_admin_country_scopes_table` and the products migration of GCC-14a (`products.storefront_country`).
3. Give the grants (`gcc:platform-admin:grant`) and the staff scopes (`gcc:staff-country:assign`), then read both back: `gcc:staff-country:list` says what is stored and whether it is enforced, and once the staff
   switch is on `gcc:platform-admin:list` says what each admin reaches.
4. **Switch the staff scopes on before, or together with, this API** when employees hold grants: an employee whose grant names every market reaches every market through this API for as long as the staff
   switch is off.
5. Take the decisions above, at least 2 and 3.
6. Tell the seller app team that `scope` can be `restricted` for an admin whose grant names every market, and that `markets` can be shorter than the grant or empty.
7. Check, as a restricted employee with a platform token: `GET /me` (their markets only, `restricted`), `GET /overview?market=<a market of another country>` (403, the same body as for a market outside the
   grant), no bucket of orders without a market and only their own lines in the trail; and, as the master admin, that nothing changed.
8. To go back, turn the staff switch off: the answers are those of the grant alone at the next request.

### The tests

`tests/Feature/PlatformAdmin`: `StaffScopeEffectiveMarketsTest` (the rule for each part of the principal, a scope that changes after the sign-in, the market parameter in 25 spellings, the shapes, the trail),
`StaffScopeSixCountryMatrixTest` (the six countries of the configuration: every grant against every staff scope, the staff switch on and off, over `/me`, `/markets`, `/overview`, `/alerts` and `/audit-logs`,
the unattributed bucket, a market at a time, and the answers with the switch off compared with the grant alone), `StaffScopeDormancyTest` (no statement about the staff scope while it is off or for the master
admin, one read of the rows otherwise), `StaffScopeOtherIdentitiesTest` (sellers, customers and the panel session are unaffected), `StaffScopeMfaSignInTest` (the second step) and `StaffScopeConsoleTest`; and
`tests/Feature/StaffScope/StaffCountryOfAdminTest` for `StaffCountryScope::countriesOfAdmin()`. The expected answers are worked out in the tests as set logic from the two lists and from the description of the world.
