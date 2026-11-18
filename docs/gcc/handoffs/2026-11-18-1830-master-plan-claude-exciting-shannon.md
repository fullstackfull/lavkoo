# Handoff: the admin screens, the seller POS and push fixes, five CI runs read per job, 68 criteria moved (2026-11-18, evening)

Sanitized. Nothing here is a secret or personal data. Every pull request is a **Draft**. None is merged, none is deployed, no production data or key was touched, G0 stays open, and the deferred risks DR-1 to DR-5 are still open (no key was rotated). This continues [the handoff of 15:30](2026-11-18-1530-master-plan-claude-exciting-shannon.md).

## What moved since 15:30 (measured on the criteria, not on the pull request count)

The tracker published with the 15:30 handoff had **1,675 criteria: 666 MET, 394 CODED, 615 MISSING**. The regenerated tracker has **734 MET, 350 CODED, 591 MISSING** (implemented 64.7%, proven by a passing test 43.8%, release readiness 7.7%). Against the first tracker of the day (`f2fa60d3`: 665 / 362 / 648) that is 69 more criteria met.

| Movement since 15:30 | Count | Which |
|---|---:|---|
| MISSING to CODED, a whole criterion | 6 | K-05.5 (the camera reads retail codes), K-06.1 and E-11.4 (the seller app sends an Idempotency-Key and replays it), K-06.3 (a 200 answer that refuses a sale), K-06.4 (who a POS sale is for), K-V3.0 (a queued sale is never dropped silently) |
| CODED to MET | 48 | the integration run 342 (`a5f7de59`, read job by job): F-01 criteria 0 to 3, I2-04 criteria 0 to 5, I2-11 criteria 2 and 3, I2-12 criteria 1, 3 and 4, I2-13 criteria 3 and 4, I2-14 criteria 1 to 4, I2-15.4, I2-16.4, I2-17.5, I2-02.5, I2-07.2 and .3, A2-09.2, A2-15.2, D1-05.4, D1-07.4 and .5, E-03.3, I1-12.3 and .4, I1-13.1 and .2, K-04.0, K-08.3, K-17.1, M1-04.3, M2-01.2 and .3, M2-02.3, M2-04.0 and .3, M2-13.4, M2-18.5 |
| MISSING to MET | 20 | **new proof from per-pull-request runs read this evening:** F-02.0 and .3 (#112, run 344), F-03.2 and .4 (#113, run 345), I2-01.3 and I2-03.3 (#114, run 348), I2-03.4 and I2-11.4 (#115, run 349), K-07.4 and K-11.3 (seller #9, run 23), K-18.2 (seller #7, run 15), J-08.5 (lavkooapp #15 and #17, runs 22 and 28), J-18.2 and J-18.3 (lavkooapp #16, runs 24 and 26); **and six mapped to proof that already existed** because the plan names the same behaviour twice: A1-09.2 (= D1-03.0), D2-17.3 (= A2-15.1), A1-10.3 and A2-09.3 (= the order snapshot), F-14.1 and .2 (= D1-03, the invoice prints the frozen company) |
| Still partly moved | 3 | I2-03.5 and I2-11 (no step-up or dual approval yet), K-06.2 (a queued sale is not tied to a seller and market; the market half needs lavkooappseller #1 and #2) |

**No whole requirement became READY FOR OWNER REVIEW**: every one still has criteria that are CODED, MISSING or not proven on a green head, and no reviewer is named. F-01 and I2-04 are INTEGRATED AND TESTED. The ready-for-review gate needs a named person (see "Asking the owner" below); **no approval was recorded by Claude or by CI**.

## What was built since 15:30

- **lavkoo #113, theme channels (`71c01d0d`, stacked on #112).** The seller app asked for a theme page and was sent the customer app's, from the customer app's cache entry. A channel with no renderer is now sent no section, the channel joins the cache key, and the channels of a section move its checksum. Off by default.
- **lavkoo #114, the legal text publisher (`9f099f97`, stacked on #105).** One audited service writes the legal texts a storefront's readiness reads (draft from a template, edit, approve by someone other than the author, publish from a day, revoke, discard) and a command is its entry point. **A defect found on the way and fixed on the same pull request:** a moment typed in UTC was stored as it came and read back in the application's time zone (`Asia/Dhaka` by default), so a text scheduled three hours ahead was already in force; a three-case test fails without the fix.
- **lavkoo #115, the admin screens (`f5913ab7`, base #111, contains #114 by merge).** `/admin/storefronts` (mark ready, activate, pause, resume) and `/admin/storefronts/{country}/legal-texts` (draft, edit, approve, publish, revoke, new version) behind **three permissions** in the role form (`storefront_lifecycle`, `storefront_legal_write`, `storefront_legal_approve`); the person is the signed-in admin and a typed name is ignored; a text typed in is cleaned by `HtmlSanitizer`; 79 new strings. No migration, no switch. **Not done:** a step-up or dual approval for publishing, revoking and activating (GCC-20), a scope by country, a source of templates.
- **lavkooappseller #8, five wrong rules of the POS (`b2e4d04`).** The app sent **no Idempotency-Key**, so a sale whose answer was lost was recorded a second time on replay; a registered customer whose id has **four digits was sold to as a walk-in** and a walk-in with a short number as a registered customer; the server refuses a sale with **HTTP 200** and the app showed it as placed; the camera read **Code 128 only**; and the outbox **deleted a sale after ten failed replays without a word**. Each is fixed in the app; a failed sale now waits on the device with its reason and is retried or removed only by the seller.
- **lavkooappseller #9, push taps and ratings (`8a25336d`).** The handler that opens the app from a push looked for the English words Order, Theme and chatting in the title, so a seller who reads Arabic tapped a new order and nothing opened; it now decides from the data of the message. The POS product dialog averaged unapproved reviews; it now takes the approved-review helper that #3 already adds, with identical text so the two merge without a conflict.
- **lavkooappseller #7 (`3eba869`)**: a test that the seller app makes no theme call. **lavkooapp #15, #16, #17**: redirect and maintenance honoured by the app, the whole suite green with the flag off and on.
- **The tracker generator is now in the repository** (`docs/gcc/tools/tracker-generator/`, with its data). Until now it lived only in the scratch space of one session; running it reproduces the published tracker and ledger byte for byte. The earlier handoff said it was in `docs/gcc/tools/`; it was not.

## CI read since 15:30 (per job; details in the [audit](../audits/2026-11-18-all-in-one-integration/README.md))

- **Run 342, the integration (`a5f7de59`), finished: 30 success and 2 skipped by design.** Run 344 (#112), 345 (#113), 348 (#114) and 349 (#115) were read job by job: `Tests, SQLite` on PHP 8.2 and 8.3 and the Storefront tests on MySQL 8 and MariaDB 11.4.2 passed on each (975 tests, 3,505 assertions on #115, six skipped by design: the side-bar test is SQLite only). The deployment overlay is red on a pull request run **by design**. **When this was written the strict-mode MariaDB job of runs 348 and 349 and a few sales-channel jobs (which hold none of these tests) were still running; they are not claimed.**
- **lavkooappseller run 21 (#8, `b2e4d04`), run 23 (#9, `8a25336d`):** analyze and the translation check pass; the named steps pass (40 tests of the POS rules; 21 of the push route and the rating rule); the full `flutter test` step is red only for `test/app_version_gate_test.dart`, the version-gate test that is red on `main` and that nothing here touches (181 and 162 passed, 1 failed).
- **The union tree** `gcc/release/all-integration-ci` was saved at `772f1c36` (it now holds #112, #113, #114 and #115 and the stop-journey test). **No run was dispatched on it** (about 30 jobs); do it only when the queue is empty.

## Findings for the owner

1. **The POS of the seller app had four rules that put money in the wrong place or lost it** (see #8). They are fixed in code, but **a seller-app release is needed** for any of it to reach a device (store submission is not part of this work).
2. **A legal text scheduled in UTC took effect hours early** in an application set east of UTC (the default time zone is east of UTC). Fixed on #114. Any other place that stores a UTC moment through a model should be read for the same thing.
3. **Three new permissions** open a country: who holds them (a country manager, legal counsel, a second approver), and whether activating a storefront needs a second person.
4. **83 new Arabic strings** (79 on the server, 4 in the seller app) need a native reader (`reviews/R5-native-arabic-reader.md`, `ARABIC_TEXT_FOR_REVIEW.md`).
5. Still open from before: the refund policy across exchange rates, the included-tax amount, the three wallet decisions (E-01, E-02, E-07), the selling company of each country, where the legal templates come from.

## The three biggest gaps to a complete six-country system (tracker section 3, recomputed)

1. **The money of a storefront is not end to end**: 20 requirements, 100 criteria, **50 MISSING** (27 MET, 23 CODED): the refund flow, the included tax, the invoice currency, the gateway accounts per company.
2. **Wallet and loyalty are still one global balance**: 13 requirements, 65 criteria, **39 MISSING** (17 MET, 9 CODED). Per-market accounts are not started; E-05.3 and E-05.4 and the ledger columns wait for the finance review.
3. **There is no selling company beyond the registry**: 15 requirements, 68 criteria, **35 MISSING** (28 MET, 5 CODED). A country can now be opened from the admin panel (#115) but without a step-up, a dual approval or a scope by country, and there is no invoice number series, payment account or settlement by company.

Behind them: the two Flutter apps (K: 42 MISSING of 91; J: 24 of 93), the per-market notification, consent and marketing profiles (I1: 48), per-storefront publish and brand (F: 26 MISSING of 74), and the legal publishing workflow (I2: step-up, retention, Saudi baseline).

## Things the next session must know

1. **The generator** is `docs/gcc/tools/tracker-generator/` (README there). `gen_main.py` holds the evidence of every criterion; the generated files are not edited by hand. Add a block before `def apply_override`; the index of a criterion is its position in the card, and an index was wrong more than once.
2. **Unpushed work:** a local commit on #115's branch (`gcc/p1-storefront-admin-screens`, `6a061b84`) makes the storefront card list a prerequisite it does not know by its own name (a later pull request adds the selling company) and adds an eighteenth lifecycle test. It was held back so as not to cancel the run of #115 (a push cancels the run in progress). Push it after the strict-mode job of run 349 ends; update `docs/gcc/I2-STOREFRONT-ADMIN-SCREENS.md` (17 to 18 lifecycle tests) and the pull request body with it.
3. The seller CI runs on pushes to `main` and `claude/**` and on pull requests whose base is `main`; **a pull request stacked on another has no run** (K-15.1, J-18.4 are still MISSING). Named steps use `if: always()` because the full step is red on `main`; **a YAML step name must not contain a colon followed by a space** (it broke runs 18 and 19 before a job started).
4. lavkooappseller #3 and #9 add the same helper (`ProductHelper.getApprovedReviewAverage`) with identical text; keep them identical or merge #3 first.
5. A log download from the CI storage can answer `ServerBusy: Egress is over the account limit`; wait and ask again.
6. `AdminStorefrontMenuTest` reads tables of the whole panel and is SQLite only; the six skipped tests on MySQL and MariaDB are by design.
7. `translate()` writes unknown keys into `resources/lang`; the tests of the screens digest the language files and fail when a key is written. Arabic keys for the server go in `sa/messages.php` and `ae/new-messages.php`.

## Next, in order

1. Read the strict-mode jobs of runs 348 and 349 when they end; push `6a061b84` on #115 (one new run) and read it.
2. The next missing criterion of each axis that code can close: customer app reload of wallet and points when the storefront changes (E-04.3), the update screen's target when the config link is empty (J-17.4), CI for stacked pull requests in both apps (J-18.4, K-15.1), K-06.2 with the market choice of #1 and #2, J-15 (the storefront's legal text in the app), I2-03.5 (GCC-20 step-up).
3. Re-run the cancelled per-pull-request runs of #82, #107 to #111 one or two at a time, and read each per job.
4. Dispatch a union run on `772f1c36` when the queue is empty.
5. **Asking the owner, once:** name the person who answers each review R1 to R6 ([REVIEW_GATES.md](../REVIEW_GATES.md)); until then nothing can be READY FOR OWNER REVIEW and nothing that changes a real financial obligation or a historical report (#98, #99, #101, #109) can merge.
