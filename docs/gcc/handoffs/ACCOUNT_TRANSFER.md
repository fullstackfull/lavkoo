# Account transfer — LAVKOO GCC

**Written** 2026-11-23, 12:20 UTC, at the owner's emergency checkpoint.
**Purpose:** hand the project to another Claude session or account **without losing code**, and without
making the next session re-do the inventory.

This file contains no secrets and no exploit detail. Where something is held privately, it is named by its
private reference only.

---

## 1. The three repositories

| role | repository |
|---|---|
| backend and canonical planning | https://github.com/fullstackfull/lavkoo |
| customer Flutter app | https://github.com/fullstackfull/lavkooapp |
| seller Flutter app | https://github.com/fullstackfull/lavkooappseller |

All three are **public**. That single fact governs several rules below.

---

## 2. Last SHAs

| repository | `main` | `gcc/integration` |
|---|---|---|
| lavkoo | **fb7753afadb701c57666e0fd62460628b87102d5** | 7b908c073d53ac8bb5e329a5ef9f6ccc9e44871c |
| lavkooapp | **7c28f193b5c07aefc30705d224631a98cd63efc4** | 7c28f193… (identical to main) |
| lavkooappseller | **2fba4835d2a08d90db3db37994b36a511fd425e3** | ca722974e0703264aca900881c32d5f5447ac6a0 |

Planning and tracker branch: `claude/lucid-babbage-jiiim2` on lavkoo, last commit **4755ff7b**.
Handover WIP branch (new, see §7): `gcc/wip-handover-land-the-twenty-five` on lavkoo, **c7785dd0**.

`main` on lavkoo is **1150 commits ahead** of where it stood before this round (`c5c04b7b`), 4 of them
first-parent.

---

## 3. What is actually merged

**lavkooapp — complete.** `main` = `gcc/integration` = `7c28f193`, a clean fast-forward. Every remote branch
of that repository has **zero** commits outside main, re-proved after the merge. It carries the storefront
family, the built-screen batch and the J-11 push-per-storefront branch.

**lavkooappseller — merged, with one known red.** `main` = `2fba4835`, a merge of `gcc/integration` carrying
**95 commits, 7 branch tips and the 16 branches contained in them**. Every one of the 25 remote refs is an
ancestor of main. Analyzer 39 issues / 0 errors (main's own count). Suite 1759 passed, 2 skipped, **1 failed**
— the version gate, which was red on main before this and is unchanged by it.

**lavkoo (backend) — merged, gate not finished.** `main` = `fb7753af`, carrying **40 tips merged** (22 clean,
18 with conflicts resolved) and **9 of 9 cherry-picked security and financial fixes**, each with git's own
`-x` provenance line. Integrity checks at the time: 40 of 40 merged tips are genuine ancestors, none moved on
the remote after being merged, none lost a file it added.

Merged backend tips, by name:

<details><summary>22 clean merges</summary>

`gcc/p0-admin-signin-guards` · `gcc/p0-discovery-test-calendar-independent` ·
`gcc/p1-commission-and-ledger-per-line` · `gcc/p1-saudi-literals-guard` · `gcc/p0-branch-protection-draft` ·
`gcc/p0-ci-every-test-directory-on-a-real-engine` · `gcc/p0-test-empty-real-database` ·
`gcc/p1-remove-order-debug-file` · `gcc/p1-gateway-registry-consistency` · `gcc/p0-saudi-baseline-ci-job` ·
`gcc/p1-paymob-saudi-baseline-pinned` · `gcc/p1-order-notifications-per-storefront` ·
`gcc/p1-coupon-behaviour-pins` · `gcc/p1-order-answer-market` · `gcc/p1-notification-stop-pins` ·
`gcc/p1-order-writers-gate-list` · `gcc/p1-stop-mode-pins` · `gcc/p1-marketing-stop-registry` ·
`gcc/p1-theme-banner-folder-and-identity` · `gcc/p1-legal-versus-operating-rules` ·
`gcc/p1-route-parity-guard` · `gcc/release/all-integration-ci`
</details>

<details><summary>18 merges with conflicts resolved</summary>

`gcc/p1-order-currency-on-every-surface` · `gcc/p1-product-identity-per-country` ·
`gcc/p1-ledger-append-only-and-account-deletion` · `gcc/p1-storefront-money-and-seo` ·
`gcc/p1-payment-refuses-a-currency-it-cannot-take` · `gcc/p1-marketing-consent-and-unsubscribe` ·
`gcc/p1-account-deletion-keeps-money` · `gcc/p1-storefront-payment-methods` ·
`gcc/p1-suborders-and-commission-per-line` · `gcc/p1-restock-never-exceeds-what-left-the-shelf` ·
`gcc/p1-market-account-sharing-key` · `gcc/p1-ledger-chain-per-entity` ·
`gcc/p0-wallet-withdraw-one-conversion` · `gcc/p1-security-coupon-ownership` ·
`gcc/p1-wallet-balance-writers-guard` · `gcc/p1-legacy-behaviour-pins` · `gcc/p1-coupon-form-money` ·
`gcc/p0-report-pagination-stable-order`
</details>

### Three merge defects found after the merge, and all three are closed on main

Confirmed independently, then fixed on the integration branch and carried into main:

1. **A merge resolution lost the concurrency test harness's overlap reader.** 21 call sites across 14
   money-under-a-race classes called a method that no longer existed, so 32–33 cases died in a fatal before
   asserting anything. Invisible on SQLite, because that runner refuses an in-memory database and all 99 tests
   skip there. Fixed by moving every call onto the one surviving name — verified on main: **0 call sites of
   the old name, 40 of the new, one definition.**
2. **Four race fixtures in the restock family** stopped meeting what a worker process really boots. Fixed as
   fixtures, not dismissed as pre-existing — and that mattered, because the contributing tip's own CI at the
   exact merged SHA was 20 of 21 jobs green with **both** concurrency jobs success.
3. **Two money behaviours had been silently dropped from the seller POS controller** — the till currency block
   and the money-row stamping. The fingerprints were a surviving-but-unused import and a public method with
   zero callers. Verified restored on main: the controller uses the currency service, calls `countryOfMoney`
   and calls `stampMoneyRows`.

### Then three fix-forward rounds on main

The backend was merged to main **before** its CI was green, and then fixed forward three times on what CI
found. Each was a real defect with before/after figures on both engines:

- the staff country-scope rule for a route the product-identity lane added (2 red jobs);
- resetting a rate limit must empty the store the counters were moved into (4 red jobs);
- **`orders.id` in the address fixture had no auto-increment while production declares one**, so a strict
  server refused every order written without naming an id — 7 errors, one cause. A lax server quietly writes a
  0, which is why nothing else saw it.

---

## 4. What is NOT merged, with a SHA for each

### Backend (lavkoo) — distinct work tips outside `main`

| branch | SHA | commits outside main | why |
|---|---|---|---|
| `gcc/p1-seller-market-scopes` | `431d0aeb` | 20 | **Unaccounted for until this checkpoint.** See §6. |
| `gcc/p1-wallet-ledger-attribution` | `bb55ab83` | 4 | **Unaccounted for until this checkpoint.** See §6. |
| `gcc/security-digital-files-private-disk` | `4096f1be` | 1 | **Unaccounted for until this checkpoint.** See §6. |
| `gcc/p1-platform-address-stops-confirming-company-payments` | `6d5a9bd3` | 64 | payment-account stack; blocked by one fixture fix (below) |
| `gcc/p1-refund-through-original-account` | `06fcfaf5` | 49 | same stack |
| `gcc/p1-payment-confirmation-address` | `4504a41a` | 44 | same stack (carried inside the first) |
| `gcc/core-payment-accounts` | `457b14b7` | 43 | same stack |
| `gcc/p1-till-charges-the-server-total` | `d94390f3` | 25 | E-11; its own base is red, below |
| `gcc/p1-pos-refuses-over-quantity` | `60f5a605` | 16 | **red on four Address tests** — see §8 |
| `gcc/p1-pos-one-selling-company` | `9cb0fd84` | 11 | fixture contradiction, below |
| `gcc/p0-wallet-one-locked-writer` | `095d537f` | 5 | would remove the overdraft guard, below |
| hotfix deployment line | `0d3ab24d` (`gcc/release/security-hotfix-confirmations`) | 35 | excluded by design: a separate deployment line, far behind |

`pasccp` and `till` are duplicate refs of two of the above (same SHAs). The six `excl-security-hotfix*` refs
duplicate the six `gcc/release/security-hotfix*` refs. `claude/lucid-babbage-jiiim2` (307 commits outside
main) and `claude/exciting-shannon-4mh2ok` (295) are documentation branches and are deliberately never merged.

**Why the five named ones are blocked** — these reasons are measured, not guessed:

- **The payment-account stack (4 tips)** shares 13 identical conflicts, *all of which were resolved once
  already*, and is blocked by ONE root cause: two test fixtures establish the customer with
  `request()->merge(['user' => ...])`, and a security fix stopped the customer reader trusting a
  request-supplied user, so the reader answers `offline`, the cart lookup goes to guest rows and the order
  writer writes nothing. **The fix is one line in each of two fixtures:**
  `request()->setUserResolver(fn () => $customer)` beside the existing merge, which is the pattern
  `tests/Feature/Wallet/WalletCheckoutTest` already uses and which commit `b59f2ef2` applied to the commission
  fixture. I verified the mechanism myself by reading `Helpers::getCustomerInformation` on the integration
  head: it reads `$request->user()`, which goes through the user resolver, not through the input bag that
  `merge()` fills. Land on the newest tip of the stack and pick the others up behind it.
- **`gcc/p1-pos-one-selling-company`** — all 4 conflicts resolved, including one real money defect the naive
  merge created (a relocated wallet debit plus an in-transaction debit = two debits for one POS wallet sale).
  What blocks it is in no conflicted file: its fixture sells in-house rows through the seller and vendor
  tills, which the seller-scoping security fix correctly refuses, and one of its 5 remaining failures pins a
  money defect the branch itself already fixed. **Re-baseline the fixture; do not weaken the scoping.**
- **`gcc/p0-wallet-one-locked-writer`** — its writer takes the row lock and honours the wallet status but does
  **not** consult the overdraft guard, which all four writers on main do. Taking it as written would remove
  that guard from every wallet write. It needs consolidation by hand, not a merge.

### Customer app (lavkooapp)
Nothing. Zero branches outside main.

### Seller app (lavkooappseller)
Nothing outside main. `gcc/integration` at `ca722974` is an ancestor of main.

---

## 5. Security and financial fixes that must not be lost

**Already on backend main** — the 9 cherry-picks, each carrying git's `-x` provenance: a payment into the
wallet credited once; a gateway address confirming nothing for a gateway the shop has not switched on; a
confirmation of the gateway in use settling once; and six more. **Carried warning:** the same nine fixes also
exist on the public branch `gcc/security-gateway-confirmations` (`9` commits outside main), which is an
ancestor of the whole payment-account stack. When that stack lands it brings its own copies under **different
SHAs**. Reconcile with `git log --cherry-mark --left-right`, never merge blindly, or the tree gets two of each.

**On main through the merged tips:** the admin sign-in guards, the coupon-ownership fix, the wallet balance
writers' guard, one currency conversion in the withdraw guard and debit, the append-only ledger, the stable
report pagination, the order-writers gate list, and the seller-scoping fix that refuses an admin-added product
at a seller till.

**Still outside main and listed in §4:** the digital-file serialization fix (`4096f1be`), the ledger
attribution family (`bb55ab83`), and the seller market-scope authorisation family (`431d0aeb`).

**Held privately, NOT on GitHub, by the owner's standing rule that vulnerability detail and its fixes are not
published on a public branch before the system is secured.** These exist **only in this container's git
objects** and will be lost when it is reclaimed. They are listed here by reference only:

| local branch | SHA | commits not on any remote |
|---|---|---|
| `private/v2-main` | `8b593449` | 12 |
| `private/v2-hotfix` | `974903d5` | 11 |
| `private/sec-2026-11-19-stack` | `f5ca9dc7` | 7 |
| `private/sec-2026-11-19-medium` | `fe1bb2db` | 4 |
| `private/sec-2026-11-19` | `907e5be9` | 3 |
| `private/topup-once-r1-wip` | `c48c497f` | 2 |
| `private/topup-once` | `29dd7bee` | 1 |

A content comparison against main shows a substantial part of these is **already represented on main under
other SHAs** (`--cherry-mark` reports equivalents for each of the `sec-*` and `v2-*` families), which is
consistent with the earlier mapping that found the public security pull requests already carry most of them.
What is unique to them is the remainder. **Decision required from the owner:** either a private repository to
push them to, or an explicit decision that the system is now secured enough to publish them, or accept their
loss. I did not push them, and I cannot create a private repository.

The two open private items are tracked as session tasks **#70** and **#82**; neither is described in any public
file, and neither should be.

---

## 6. The twenty-five commits found outside main

Found at this checkpoint by running the check the integrator itself prescribed —
`git log --cherry-mark --left-right origin/main...<branch>` — which reports **`equivalent-in-main: 0`** for all
three, so their content is not on main under another SHA either.

| branch | SHA | commits | what it holds |
|---|---|---|---|
| `gcc/p1-seller-market-scopes` | `431d0aeb` | 20 | the K-09 seller market-scope family: a seller or staff token carrying the markets it may use with a market outside the grant refused; fourteen seller surfaces refused through the real middleware with a real token; every seller list bound to the granted markets; the shop's grant as a ceiling on its staff; a refund decision, seven unbound order writes and the till bound; a delivery man's orders bound; a bulk job and an automation rule bound; the two catalogue lists bound; the report family no longer adding three currencies; a Saudi seller with the switch off answered the base byte for byte. Ships off by default. |
| `gcc/p1-wallet-ledger-attribution` | `bb55ab83` | 4 | E-05: a wallet or loyalty ledger row says what stands behind it, and every caller that knows its order, refund, payment or staff member says so. Dormant until its migration runs. |
| `gcc/security-digital-files-private-disk` | `4096f1be` | 1 | a digital file row read without its id no longer raises when it is serialized. **This is one of two security fixes recovered earlier when the tip count was corrected from 52 to 54, and it was named then as a fix that must not be lost.** |

**How this happened.** The branch map had **54** tips. The integrator merged 40 and cherry-picked 9, and named
**five** it left out with reasons. The remaining nine were never accounted for; after resolving duplicates and
containment they reduce to these three distinct work tips.

**Progress made before the checkpoint, and where it is:** branch `gcc/wip-handover-land-the-twenty-five`
(**c7785dd0**) on lavkoo holds, on top of main:

1. the digital-file security fix merged (`e7a8f8ad`);
2. the ledger attribution branch merged (`911d7d85`) — it auto-merged with **zero conflicted paths** and 40
   staged files, so it was committed rather than aborted;
3. one unfinished test file as the checkpoint found it (`c7785dd0`).

**Nothing on that branch is proved: no test was run on it, no real-engine run, no mutation, no CI.** The
20-commit `gcc/p1-seller-market-scopes` branch is **not merged there at all** — it is the large one and it is
authorisation code, so it needs a merge with a review pass, not a quick landing.

---

## 7. Remaining conflicts, by what is known about them

| where | conflicts | state |
|---|---|---|
| the payment-account stack (4 tips) into main | 13, identical across the tips | all resolved once; blocked only by the two-fixture resolver fix in §4 |
| `gcc/p1-refund-through-original-account` | 15, one extra worth naming | in `app/Utils/OrderManager.php` this tip's side is **empty** where main records the order's storefront snapshot and stamps the money rows — taking the tip's side there would delete both. The resolution is main's side. |
| `gcc/p1-pos-one-selling-company` | 4 | all resolved; blocked by its fixture, not by a conflict |
| `gcc/p1-seller-market-scopes` into main | not yet measured | the merge was never attempted; do it smallest-branch-first as in §9 |
| `gcc/p1-wallet-ledger-attribution` | **0** | measured: auto-merged clean on the WIP branch |
| `gcc/security-digital-files-private-disk` | **0** | measured: merged clean on the WIP branch |

Two conflict shapes recur in this project and are worth knowing before touching any merge:

- **both sides append to the same list** (CI step lists, analyzed-file lists, language files) → resolve as the
  **union**, and then *verify by parsing*, not by eye: compare every step or key against both parents.
- **one side renamed a thing and updated its own call sites while another side kept the old name** → this is
  what lost the concurrency harness. Make the definition and **every** call site agree on one name.

---

## 8. Real CI results, with run numbers

| repository | run | head | conclusion |
|---|---|---|---|
| lavkooapp | **45102179335** (run 45) | `7c28f193` = main | **SUCCESS, all four jobs.** `storefront-header` 29 named steps all green, `analyze-whole-project` 255 findings / 0 errors against a recorded 255, both whole-suite jobs. Suite 924/96/0 with storefronts off and 987/33/0 with them on. main's tree is byte-identical to that head, so this run **is** a run on main's tree — which matters, because that repository's CI triggers on `gcc/**` and on pull requests to main, so a push to main itself runs nothing. |
| lavkooappseller | **45113986622** (run 47) | `2fba4835` = main | **failure.** One failing test: `test/app_version_gate_test.dart` — "the shipped version clears the backend minimum". Every named feature step is green. `build-android` is **skipped**, because it needs the test job, so **no Android build is proved on main.** |
| lavkooappseller | 45095016124 (run 46) | `ca722974` = `gcc/integration` | failure, the same single test. Suite 1759 passed / 1 failed / 2 skipped. |
| lavkoo | **45167641764** (run 571, `workflow_dispatch`) | `fb7753af` = main | **PENDING / never read.** This is the gate on main's current head and it is NOT satisfied. **Read it first in the new session.** |
| lavkoo | the run at `ebe35e87` | the first main merge | 7 red jobs over three causes; all three were fixed and merged (§3). |
| lavkoo | 45089049325 | `d94390f3` (E-11) | failure: 18 success, 5 failure, and the base branch's own run `45033640756` has **the same five job names**, so that head adds no red. Four of the five are real: `ApiSellerAddressTest`, three errors and one failure, on the base branch line — see §4. |

**Known reds that are red by design or pre-existing, so they are not new breakage:**
`Deployment overlay (artifact, deploys nothing)` on lavkoo is red on every run and every base. The seller app's
version gate is red on main and clearing it is reserved to the owner (a published release at or above the
backend's minimum, **or** a deliberate lowering of that minimum in Business Settings → App Settings). **No
version number, package id, bundle id or Firebase setting has been changed anywhere.**

---

## 9. Tracker state

**867 MET / 377 CODED / 509 MISSING of 1753 criteria**, across 359 requirements — 71.0% implemented, 49.5%
proven. Generated from `docs/gcc/tools/tracker-generator/gen_main.py` on branch `claude/lucid-babbage-jiiim2`
at `4755ff7b`.

**One thing changed under the tracker's feet and the new session must not misread it: the generator now
reports `evidence failures: 45`, where it reported 0.** All 45 are of one form —
`lavkoo|lavkooapp origin/main:<path> (pattern not found: …)` — across 40 requirements
(A2-05, A2-07, A2-13, A2-15, B-04, D1-03, D1-07, D2-01×2, D2-04×2, D2-05, D2-10, D2-11, D2-17, E-03, E-06,
E-08×2, E-11, E-12, E-16, E-17×2, F-03, F-07, F-14, G-01, G-17×2, H1-07, H1-09, I1-01, I1-06, I1-17, I2-03,
I2-04, J-11, K-10, K-11, K-15, K-18, M1-04, M2-13, M2-15).

**These are not defects.** Those triples were written to pin *what main did before the GCC work*, anchored at
the moving ref `origin/main`, and the integration has now changed or removed exactly those lines — for example
A2-15 pinned a debug log path the merge deleted, and A2-07 pinned the customer reader that the security fix
replaced. **The clean fix is to re-anchor those "exists now" triples from `origin/main` to the frozen
pre-merge SHA `c5c04b7b`**, which is what they actually describe. That is one mechanical pass and it will
return the count to 0; treating them as regressions would waste hours.

Regenerate with: `cd docs/gcc/tools/tracker-generator && GCC_REPOS_ROOT=/home/user python3 gen_main.py ../.. <date>`
and check two numbers: criteria **1753** and evidence failures **0**.

---

## 10. The first five tasks for the new session, in order

1. **Read lavkoo CI run `45167641764` on `fb7753af` job by job.** It is the mandatory gate on main's current
   head and it has never been read. Expect one red by design (`Deployment overlay`). The jobs that matter most
   are the two **concurrency** jobs on MySQL 8 and MariaDB 11.4.2: they run `tests/Feature/Concurrency` on real
   engines and they are what exposed two of the three merge defects. Anything else red is unfinished work, and
   the three fix-forward rounds in §3 are the template for how to close it: find the root cause, fix it,
   measure before and after on both engines, never skip a test.
2. **Re-anchor the 45 evidence triples** from `origin/main` to `c5c04b7b` (§9), and confirm the generator is
   back to 1753 criteria / 0 evidence failures. Cheap, mechanical, and it unblocks every later tracker edit.
3. **Land the remaining 25 commits** (§6). Continue from `gcc/wip-handover-land-the-twenty-five` (`c7785dd0`),
   which already carries two of the three merges: run the affected directory groups on **both** SQLite and a
   real engine, then merge `gcc/p1-seller-market-scopes` (`431d0aeb`, 20 commits of authorisation code) with a
   review pass that hunts for lost behaviour — an unused import, a method with no callers, a scope defined and
   never applied — and mutate every authorisation boundary it resolves. Full CI before main.
4. **Unblock the payment-account stack** with the two-fixture resolver fix (§4), which frees four tips and 64
   commits including the GCC-21 confirmation-address work. Then reconcile the duplicated security fixes with
   `git log --cherry-mark --left-right` as §5 warns.
5. **Fix the four red `ApiSellerAddressTest` tests on `gcc/p1-pos-refuses-over-quantity`** (`60f5a605`), which
   is E-11's base and cannot land while they are red. Read `docs/address/README.md` first — that is a project
   rule for any address, city or provider-mapping change.

Then, and only then, resume feature work: the MISSING survey in
`docs/gcc/handoffs/2026-11-22-1945-thirty-requirements-are-one-criterion-from-done-claude-lucid-babbage.md`
names eighteen requirements that are one criterion from done and buildable now, grouped so a lane can take
three at a time.

---

## 11. How to resume without redoing the inventory

**Read these, in this order, and nothing else first:**

1. this file;
2. `docs/gcc/LANE_PROTOCOL.md` — the binding working protocol, with six lanes' measured cost. It is why work
   runs as **two agents** (build, then one review-and-fix that fixes what it finds in the same pass) rather
   than four, and it carries two rules earned the hard way: mutations go on money, on security boundaries
   **and** on the dormancy guard of anything that ships switched off, measured against the object the
   application itself builds; and **a change to how CI decides what runs is reviewed as a gate, not as a
   refactor**, because a pure-looking step reorder once left the whole-suite step able to be skipped;
3. `docs/gcc/README.md`, `DECISIONS.md`, `STATUS.md`, `COLLABORATION.md`;
4. the newest files in `docs/gcc/handoffs/`.

**Do not re-derive these; they are measured and recorded:**

- the branch map (54 backend tips from 177 refs) and which are merged — §3 and §4 above;
- the containment position of every branch — re-run in one command if you want it fresh:
  `for r in $(git for-each-ref --format='%(refname:short)' refs/remotes/origin/); do echo "$r $(git rev-list --count $r --not origin/main)"; done`
- the environment facts that cost the most time to learn: `phpunit.xml` pins SQLite in memory through
  `<server>` entries and **overrides the shell environment**, so a real engine needs
  `-c phpunit.mysql.xml` with `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3308 DB_DATABASE=<name>_test
  DB_USERNAME=lavtest DB_PASSWORD=lavtest` (MariaDB 10.11.14; the database name must contain `test`; drop it
  afterwards). Flutter is at `/opt/flutter/bin` and is **not** on PATH; `flutter pub get` rewrites
  `pubspec.lock` against the pinned SDK, so restore it before committing. `translate()` writes unknown keys
  into `resources/lang/<locale>/new-messages.php` and **en, sa and ae are tracked** — check
  `git status --porcelain -- resources/lang/` before every commit. A worktree needs
  `cp -al … vendor && rm -rf vendor/composer && cp -r … vendor/composer` or two worktrees share one autoload
  map. The backend CI carries `workflow_dispatch`, so a full run needs no pull request.

**Standing constraints still in force:** no production deploy, no production database access, migration,
export or backup, no production data, no key rotation, no force push, no history rewrite, no bypassing CI or
the version gate, and no recording of a financial, security or legal approval on a reviewer's behalf. No seller
app version change without verified release evidence. Vulnerability detail is not published on a public branch
before the system is secured — and all three repositories are public.

---

## 12. What this checkpoint did, and what it deliberately did not do

Done: a running merge lane was stopped **after** its in-progress merge was brought to a safe state (zero
conflicted paths, so it was committed rather than aborted); every working-tree change in every worktree of all
three repositories was committed or, where it was a generated `translate()` artefact, restored; the new work
was pushed to `gcc/wip-handover-land-the-twenty-five`; the private work was committed locally and **not**
pushed; and `git status` is clean everywhere.

Not done, on purpose: no new agents, reviews, mutation rounds or long local test runs were started; no
unproven code was merged to main to close the session; and no GitHub Actions run was waited on — their results
stay on GitHub and task 1 above is to read the one that matters.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_018Q6HuHNDa9TWpNZBsZLmVX
