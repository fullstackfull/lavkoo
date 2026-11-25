# Track CI1 — app CI gates and the six-market seeder — handoff

**Branches, none pushed** (the track's own instruction).

| repository | branch | base | head |
|---|---|---|---|
| `lavkoo` (backend) | `wt/bfi` | `30a2283e` | **`d48365fc`** |
| `lavkooapp` (customer) | `gcc/development` | `acf422b9` | **`511e5a32`** |
| `lavkooappseller` (seller) | `gcc/development` | `0dd9023e` | **`68a5d596`** |

Backend database `pharmacy_testing_bfi`. Three criteria of axis M: **M-456 and M-457 closed, M-328 closed.**

**Two of the three recorded evidences were STALE and are corrected here rather than repeated.** That is the most
important thing in this document, because both would have been re-reported as facts by the next reader.

---

## What could be executed here, and what could not

| | ran it | only read or parsed it |
|---|---|---|
| backend seeder and its test | 10 tests, 1,931 assertions, MySQL 8, plus 8 mutations | — |
| `scripts/ci/leak-guard.php` over this change | exit 0 | — |
| the ported scanner `.github/scripts/leak-guard.php` | every rule, both repositories, real ranges and planted commits | — |
| the two workflows' base-resolution shell | all four event cases, against both real repositories | — |
| `test/ci_step_order_test.dart` (K-15) | its parser ported to Python, all 6 assertions re-checked | the Dart test itself |
| `dart format`, `flutter analyze`, `flutter test` | **nothing** | the workflow YAML, parsed; the seller's working job, read |

**There is no Dart or Flutter toolchain in this container** (`which flutter dart` is empty; no `/opt/flutter`,
no `/usr/lib/dart`). So no claim below says a Flutter job ran. What ran is PHP 8.3, git, bash and PyYAML.

---

## M-457 — "The CUSTOMER app repository refuses one too"

**Tracker line** (`docs/gcc/tracker/axis-m-security-tests-and-deployment.md:457`), verbatim:

> `[MISSING] The CUSTOMER app repository refuses one too (lavkooapp origin/main has NO .github directory at all —`
> `git ls-tree -r --name-only origin/main | grep -c '^.github/'` is 0, so the repository has no CI of any kind on its
> default branch. Some gcc/* branches carry a workflow; the branch that would have to enforce it does not. This is the
> weakest of the three and the one a reader would most easily assume was covered by the other two.)

**Target state.** The "one" is the pull request of the criterion above it: one that **adds a secret or personal data**.
The criterion is a secret-and-personal-data gate, not a formatting gate; the track's own brief also asked for
dependency install, `dart format --set-exit-if-changed` over changed Dart files, `flutter analyze` and `flutter test`.

### The recorded evidence is false as of today, and this is the verification

```
$ cd /home/user/lavkooapp && git ls-tree -r origin/main --name-only | grep '^\.github/'
.github/workflows/ci.yml
```

`origin/main` of `lavkooapp` **does** have a `.github` directory and **does** have CI. Its three jobs are
`storefront-header`, `analyze-whole-project` and `test-whole-suite`; the `on:` block answers **every** pull request
with no base filter, pushes to `gcc/**`, and `workflow_dispatch`. Run `45699661338` on this branch's head is
**success**. So "no CI of any kind" was true when written and is not true now. Of the four checks the brief named,
**three already existed**: `flutter pub get`, `flutter analyze` (changed files, then the whole project against
`tool/analyze_baseline.txt`, which fails on an added finding), and `flutter test` (the whole suite twice, with
storefronts off and on, `fail-fast: false`). The repository has 82 test files.

### What was genuinely missing, and what now refuses what

1. **`dart format` was run nowhere in this repository, in any job, on any ref.** New job `formatting`
   (*Changed Dart files are formatted*): installs dependencies, resolves the change, and runs
   `dart format --output=none --set-exit-if-changed` over the Dart files the change itself adds or edits. **Refuses:**
   a pull request that adds or edits a Dart file which is not formatted. Scoped to the change deliberately — the app's
   existing source has never been formatted in CI, so reading all of it would make every pull request red for drift
   nobody here introduced, and a red nobody can act on is a check that gets switched off.
2. **Nothing refused a secret or personal data.** New job `leak-guard` (*No secret or personal data added*) — see the
   shared section below.

---

## M-456 — "The SELLER app repository refuses one too"

**Tracker line** (`axis-m-security-tests-and-deployment.md:456`), the gap it names, verbatim:

> `[MISSING] The SELLER app repository refuses one too (Lavkooappseller origin/main (d556fb4f) .github/workflows/ci.yml`
> holds two jobs, `analyze-and-test` (install, verify formatting of changed Dart files, analyze, check translation keys,
> test) and `build-android`; **searching it for leak, secret, gitleaks or trufflehog returns zero matches.** No other ref
> has a scan either: none of the 21 remote refs … holds a file that names a secret scanner … So no check of the
> repository's own challenges a committed key, and main is unprotected …

**Target state.** A check of the repository's own that challenges a committed key or personal datum. Confirmed still
absent: `grep -niE 'leak|secret|gitleaks|trufflehog' .github/workflows/ci.yml` → zero matches at `0dd9023e`.

### Closed by the `leak-guard` job, and one thing the tracker believes that is not so

The tracker's own summary of `analyze-and-test` says it does "verify formatting of changed Dart files". **It did not.**
The step read four fixed paths —

```yaml
run: dart format --output=none --set-exit-if-changed lib/helper lib/theme lib/common/basewidgets/glass tool
continue-on-error: true
```

— so unformatted Dart in `lib/helper` turned the step orange and the **job green**, and anything outside those four
paths was never read. A check that refuses nothing is the thing this axis exists to remove, so it was replaced by the
changed-files gate described above, which **fails the job**. This strengthens a check; it weakens none.

**Not a step of `analyze-and-test`.** That job's last step must stay the whole test run — K-15, pinned by
`test/ci_step_order_test.dart`, which also requires every named feature step to carry `always()` or `!cancelled()`.
Its parser was ported to Python and re-run against the new file: **all six assertions pass**, 42 steps parsed, the
whole run still last.

---

## The gate both apps now have

`.github/scripts/leak-guard.php`, **byte-identical in both repositories** (`diff` is empty, by design — a reader can
diff the two and get nothing), run by a job named *No secret or personal data added*.

**It is the backend's scanner, ported rather than rewritten.** Source blob
`210ecba2f500b67be3c9de9f3283284e1cb7495807c6e958fe4beb953b0aae56` (`scripts/ci/leak-guard.php` at `e96c8485`), whose
rules and git plumbing are the reviewed, tested ones of this programme — `tests/Unit/Deploy/LeakGuardTest`, 114 tests,
still passing, unchanged. **It is PHP, not Dart, for one reason:** there is no Dart toolchain here, so a Dart rewrite
would be several hundred lines of **unexecutable** gate code, whose failure modes are a false green (worse than no
gate) or a permanent red. The runner has PHP already, so the job adds no toolchain. Only these things differ:

| | backend | the apps' port |
|---|---|---|
| fixtures (made-up people allowed) | `tests/*.php`, `resources/lang/` | `test/`, `integration_test/`, `assets/language/` |
| vendored / generated | composer, npm, minified, `public/` | `pubspec.lock`, `Podfile.lock`, `*.g.dart`, plugin registrants, `Generated.xcconfig`, Pods, ephemeral, gradle wrapper |
| runtime output | `storage/`, `build/` | `build/`, `.dart_tool/`, `android/.gradle/`, `ios/.symlinks/` |
| credential files, by name | htpasswd, npmrc, netrc, service-account… | **plus `key.properties`, `keystore.properties`, `*.mobileprovision`** |
| personal data | `saudi-mobile-number` only | **plus `gcc-mobile-number`: AE, KW, QA, BH, OM** |

**`gcc-mobile-number` closes a gap the Discovery records as open** (Q-47, owner decision D-04): the backend's scanner
knows the Saudi mobile shape alone, so a number written `+971`, `+965`, `+974`, `+973` or `+968` broke **no rule
anywhere in this programme**, in a platform being built for six markets. Each market is read by its dialling code and
its own mobile opening digits (Emirati 5; Kuwaiti 5/6/9; Qatari 3/5/6/7; Bahraini 3; Omani 7/9). Unlike the Saudi rule
there is **no bare-zero alternative**, because a national number without a dialling code is eight digits of anything
and reading every such run as a telephone would refuse version strings and identifiers all day.

### What it refuses — executed, not asserted on paper

| case | result |
|---|---|
| `origin/main..HEAD`, customer repository | **exit 0**, nothing found |
| `origin/main..HEAD`, seller repository | **exit 0**, nothing found |
| a planted commit with an assembled key and an assembled Emirati number | **exit 1**, `gcc-mobile-number` + `credential-literal` |
| a secret added and **removed again** in the same range | **exit 1**, named with the earlier commit |
| `android/key.properties` added | **exit 1**, `credential-file`, by name |
| a commit adding only a route path | exit 0 |
| all six markets' mobile shapes | all refused (`+966` as `saudi-mobile-number`, the five others as `gcc-mobile-number`) |
| `flutter-version: '3.47.0'`, `ndkVersion "28.2.13676358"`, a bare 8-digit int, a timestamp, a dialling code alone | all quiet |
| the scanner's own 555 lines | quiet — it does not refuse itself |

Every offending value in those cases was **assembled at run time by the shell or by PHP**, and the planted commits were
made in **throwaway repositories under the scratchpad**, so no secret-shaped object was ever written into either app
repository's object store.

### One false-alarm class fixed at the cause, which is why the gate is usable

Over the whole history the port first reported **14** findings; four were wrong, and all four were ordinary app code:

```
lib/utill/app_constants.dart:57   tokenUri            = '/api/v3/seller/cm-firebase-token'
lib/utill/app_constants.dart:71   forgotPasswordUri   = '/api/v3/seller/auth/forgot-password'
lib/utill/app_constants.dart:73   resetPasswordUri    = '/api/v3/seller/auth/reset-password'
lib/utill/images.dart:81          forgotPasswordIcon  = 'assets/images/forget_password_icon.png'
```

The constant is named after what it is **for**, so the rule's secret-word test matched, and a route path is long and
has both digits and letters, which is what the credential rule reads. A gate that goes red when somebody adds a route
constant is a gate that gets switched off, so `PATH_OR_ASSET` excuses a value that is **entirely** slash-separated
lowercase segments, or an asset file name. Deliberately narrow: a base64 or base64url secret carries capitals, `+` or
`=` and is still read; a prefixed token is matched by `api-token` first; `private-key`, `jwt` and `url-credentials`
never consult it. Re-verified after the change: those four are quiet, and a mixed-case key, a base64 blob, a password
literal, a `Bearer` header, URL credentials and a private-key header are **all still refused**. `ExportOptions.plist`
was also dropped from the credential-file rule after reading it — it holds a team identifier and the signing style,
neither of which is a secret.

**The remaining 9 whole-history findings are real and pre-existing**, and this change must not touch them: Google API
keys in `android/app/google-services.json`, `ios/GoogleService-Info.plist`, `ios/Runner/GoogleService-Info.plist` and
`lib/firebase_options.dart`, plus one e-mail address in `store_assets/APP_STORE_METADATA.md`. Both apps' CLAUDE.md
forbid changing Firebase settings. **This is why the gate is range-scoped** and not a whole-tree scan — the same
reason the backend's is, and the same failure the backend already demonstrated (run `43031304991`, a push on
`origin/main`, 409 findings).

### How the range is resolved, and why that mattered

Both jobs resolve the change the same way: the pull request's base sha, else `github.event.before`, and if that is
missing, all zeros, or a commit the clone does not have, **`origin/main`** rather than the whole history. Without that
fallback the **first push of any new branch** would be red for everything already committed to it. All four cases were
executed against the real repositories:

```
a pull request naming its base        -> 446ea005, 11 changed Dart files
a first push of a new branch (0000…)  -> falls back to origin/main
a manual run (neither set)            -> falls back to origin/main
a base this clone does not have       -> falls back to origin/main
```

---

## The seller app's Java-heap failure: already found, already fixed at the cause

**It was not open.** `docs/gcc/tracker/axis-k-flutter-seller-app.md:332` records it, and the repository confirms it:

- **Cause.** Jetifier was handed the Flutter engine's **per-ABI native artifacts** — 1.29 GiB of `libflutter.so`
  inflating into single byte arrays of 237–377 MiB, which G1 must place as humongous objects and copy again on the way
  out. Gradle runs artifact transforms concurrently, so together with Gradle's and AGP's working set it went past
  `-Xmx4G` and failed `:app:compileDebugKotlin` with **"Java heap space"** while resolving `debugCompileClasspath`
  (CI runs **70** and **71**).
- **Fix, and it is the right shape.** Commit **`d3eee39`**, `android/gradle.properties`:
  `android.jetifier.ignorelist=armeabi_v7a_(debug|profile|release)-,arm64_v8a_…,x86_64_…`. It **removes the
  allocation** instead of making room for it. `org.gradle.jvmargs` was deliberately **left at `-Xmx4G`**: raising it
  would only buy space for copying native code to no purpose, on a runner that already sets aside 1 GiB of metaspace.
  Every archive that really holds class files is still jetified, `flutter_embedding_debug` included.
- **Verified on this branch.** `d3eee39` is an ancestor of `0dd9023e`; no commit since touches `android/`. Run
  **`45699656748`** (head `0dd9023e`): `analyze-and-test` **success**, `build-android` **success**. Run `45652949351`
  on `main`: the same. The build step and the job are **unmodified** — nothing was disabled or skipped, so the check
  that caught the exhaustion still exists.
- **The one later red run was not heap.** Run `45678118197` (head `94f6b4d`) failed on Dart POS tests;
  `build-android` was **skipped** because it `needs: analyze-and-test`. Fixed by the next commit.

**So there was no heap fix to make, and none was invented.** No Gradle or Android memory setting was changed by this
track, which also keeps the seller app's version, package id, bundle id and Firebase settings untouched, as required.

---

## M-328 — "A seeder creates six storefronts … and no real personal data"

**Tracker line** (`axis-m-security-tests-and-deployment.md:328`), verbatim:

> `[MISSING] A seeder creates six storefronts with invented sellers, customers, products, orders and wallets and no real`
> `personal data (no database/seeders directory; only UserFactory)`

**Verified true**, unlike the other two: `ls database/seeders/` → *No such file or directory*; `database/factories/`
held `UserFactory.php` alone. (`database/seeds/` exists with five legacy Laravel-5-era seeders; it is not
`Database\Seeders` and holds nothing of this kind.)

### What was built

`database/seeders/GccStagingSeeder.php` and `database/seeders/InventedMarketIdentity.php`
(`Database\Seeders\` → `database/seeders/` was already PSR-4 mapped in `composer.json`, so nothing there changed).
Per market: **one invented seller with its shop, one invented customer, two products priced in that market's own
currency, one delivered order stamped with that market and that currency, and two wallets** — the customer's balance
with the movement that put it there, and the seller's earnings.

The six markets, their currencies and which one is the default are read from `gcc.storefronts` through
**`StorefrontResolver`**, never typed in the seeder, so it cannot drift from what the application serves. The
storefront columns are written only where the table has them (`hasColumn`), the dormancy pattern every storefront
service follows, so a database that has not run the GCC migrations still gets its six markets' rows.

### What it refuses to do

- **Refuses to run** outside `staging`, `testing` or `local` — a list of names, not "not production", because an
  `APP_ENV` somebody forgot to set reads as the framework's default and the default must not be writable. The refusal
  is the **first statement of `run()`**, and the test asserts nothing was written before it.
- **Not registered in any `DatabaseSeeder`**: a bare `php artisan db:seed` never reaches it. It must be named:
  `php artisan db:seed --class="Database\Seeders\GccStagingSeeder"`.
- **Changes no default.** No `business_settings` row at all. A currency is added only when that code is **absent**, so
  the riyal row an installation already has keeps its name, symbol, rate and status. It never edits a row it did not
  insert, and **it does not open a market** — `active` and the storefront lifecycle stay a separate, gated decision.
  Saudi behaviour is whatever it was: the Saudi market gets its own invented rows like the other five, and nothing
  else about it moves.
- **Idempotent per market**, so a second run adds nothing and a half-finished run can be finished by running it again.

### No real personal data, and no credential

- Names are `Invented Seller SA` and the like; the address names no real street, district or building.
- E-mail addresses sit under **`gcc-staging.example.test`** — `.test` is reserved by RFC 2606 and can never be
  registered, so they reach no mailbox.
- **A telephone number is never written whole.** It is assembled at run time from the market's dialling code and an
  **all-zero local part**. Two reasons, both load-bearing: a number spelled out in the source — however invented —
  would be a finding in this repository's history **for good**, and no market allocates a subscriber line opening with
  a zero after the dialling code, so it can never become somebody's number later.
- **There is deliberately no shared staging password.** The hasher is given `Str::random(64)`, generated and dropped
  in the same expression, so the twelve accounts **exist and none can be signed into with anything the repository
  knows**. An operator uses the ordinary password-reset flow — which is worth exercising on a staging server anyway.

### Tests, and their teeth

`tests/Feature/Seeders/GccStagingSeederTest.php` — **10 tests, 1,931 assertions, green on MySQL 8** against
`pharmacy_testing_bfi`. The schema is built in the test because most of the 300-odd migrations are ALTER-only and
assume the proprietary dump (`BootstrapTestSchema` says so in as many words); the two GCC storefront migrations are
real, so they are **run** rather than written out again — the approach of `tests/Support/BuildsLegalEntityWorld`.

**"No real personal data" is not asserted by eye.** Every string the seeder writes, and every line of its own two
source files, is read by **the repository's own scanner** (`scripts/ci/leak-guard.php`, the one CI runs on every pull
request) under a path that is **not** a fixtures path, so the e-mail and Saudi-mobile rules apply at full strength.

Eight mutations, each turning the suite red; the clean tree is green after each:

| mutation | red |
|---|---|
| a plausible local line (`5123456`) instead of all zeros | 1 |
| a reachable e-mail domain instead of a reserved one | 1 |
| **one shared staging password** | 2 |
| every order priced in the default storefront's currency | 1 |
| one seller and one customer shared by all six markets | 2 |
| the environment guard removed | 1 |
| a `business_settings` default written | 1 |
| the installation's riyal row overwritten | 1 |

**The shared-password mutation is worth reading, because the first version of that test passed it.** The test asserted
that the twelve stored hashes differ — which proves nothing, because **bcrypt salts every hash**, so twelve accounts
sharing one password still store twelve different strings. It now puts **every string literal of the seeder's two
source files, and the joined literals of each line** (a password written in pieces is still written down), to **every**
stored hash with `Hash::check`, and separately refuses `Hash::make()` being handed a literal at all. Both catch it.

`scripts/ci/leak-guard.php` was run over this change before committing: **exit 0, nothing found.**

---

## What is NOT closed, and what the next reader should not assume

1. **No Flutter job was executed.** `dart format`, `flutter analyze` and `flutter test` were not run here, by anyone,
   because the toolchain is absent. The two new jobs are proven only as far as PHP, git, bash and a YAML parse can
   prove them: the scanner's every rule and the range shell were executed; `dart format` itself was not. **The first
   real run of `formatting` may be red** if a pull request touches a Dart file that was already unformatted — that is
   the gate working, but somebody should watch the first few.
2. **A red check is not a gate.** Exactly as the backend records (M1-16, DR-4), `main` of both app repositories is
   **unprotected with no ruleset**, so these jobs can be merged past. The rule that would require them is a document
   in draft PR 91, applied nowhere. Closing that is not this track's and needs the owner.
3. **GitHub's own secret scanning and push protection could not be read** for either app repository, as the tracker
   already records: the API answers `security_and_analysis` null and the proxy refuses the secret-scanning endpoint.
4. **The backend's own scanner still knows only the Saudi mobile shape.** The six-market rule was added to the apps'
   port, where this track owns the file. `scripts/ci/leak-guard.php` is not this track's to edit, so **Q-47 / D-04
   remains open for the backend** — a `+971` number committed to `lavkoo` still breaks no rule. That is a one-constant
   change for whoever owns `scripts/ci/`, and the apps' port is the reference implementation.
5. **`build-android` still hangs off `needs: analyze-and-test`**, so one red Dart test skips the build check entirely
   (run `45678118197` is the example). Not changed: it is not this criterion, and removing `needs:` changes the job
   graph and the runner cost. Worth a decision.
6. **M-328's sibling criteria are untouched.** The same tracker card still has `[MISSING]` for *a staging environment
   behind authentication with its own APP_KEY and outbound channels at sinks*, and for *the release runbook and
   rollback rehearsed there with a recorded log*. Both need a host, a domain and who may reach it — the owner's, per
   that card's own open decisions. The seeder is the data half and nothing more. `docs/gcc/STAGING_PLAN.md`, which the
   card's next-coding-task also names, was **not** written.
7. **The seeder has never run on a real staging server**, only against `pharmacy_testing_bfi` with a hand-built
   schema. Its column set is conservative and the storefront columns are guarded, but the first real run should be
   watched for a `NOT NULL` column the proprietary dump has and this schema does not.
8. **Nothing was pushed, and no pull request was opened**, per the track's instruction. No tracker file was edited, no
   `app/` path in the backend, and nothing in either app's `lib/`.

---

## Co-ordination note

`lavkooapp` was a shared index this round. This track committed **only** `.github/scripts/leak-guard.php` and
`.github/workflows/ci.yml` there, staged by exact path; `git show --name-only 511e5a32 | grep -E '^(lib|test)/'`
returns nothing. The track working in `lib/main.dart` and `test/` of that repository was told, before pushing, that
the new `formatting` job will now read its changed Dart files and the new `leak-guard` job its added lines — and that
`test/` is a fixtures path, so an invented address or number in a test is excused there, while a **private-key
header**, a prefixed token, a JWT or credentials in a URL are not excused anywhere.
