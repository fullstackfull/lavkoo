# Final handoff, 2026-11-19 08:00 UTC: wave 5, the audits and the switch to the next account

Written by the Claude session `exciting-shannon` when the agent capacity ran out ("weekly limit", resets 2026-11-24 21:00 UTC) and the owner asked to save everything and hand over.
**Nothing is merged, deployed or rotated. No force push. Every pull request is a draft.** Standing rules of the charter still hold (no merge to main, no production, no key rotation, no change of live financial settings, no bypass of the version gate or the CI protection; DR-1 to DR-5 stay open risks). No new development was started for this handoff.

Read first, in this order: this file, `docs/gcc/STATUS.md`, `docs/gcc/MASTER_IMPLEMENTATION_TRACKER.md` (generated), `docs/gcc/PR_LEDGER.md`, `docs/gcc/COLLABORATION.md`, then the resume kit in `2026-11-19-resume-kit/` (notes of the session and the scripts of the workflows).

## 1. What the tracker says now (generated, evidence only)

| | MET | CODED | MISSING | of |
|---|---|---|---|---|
| published at `98f23c1f` | 761 | 328 | 586 | 1,675 criteria |
| this commit | **783** | **317** | **575** | 1,675 criteria |

Moves since `98f23c1f`, each backed by a CI run read per job **and** an independent audit (a skeptical verifier and a judge, both re-running the tests):
- CODED to MET (19): F-02.3, F-04.2, F-04.3, F-04.4, F-07.0, F-07.1, F-07.3, F-08.0, F-08.1, F-08.2, F-08.3, F-09.0, F-09.1, F-09.2, F-10.1, F-11.0, F-11.1, F-12.0, K-01.1.
- MISSING to MET (7): F-07.4, F-09.5, K-03.1, K-03.2, M2-10.2, M2-10.3, M2-13.2.
- MISSING to CODED (4): K-03.0, K-10.1, K-10.2, K-10.3.
- MET to CODED (4, the audit refused them): I1-12.3, I2-11.4, I2-16.2, I2-16.3. (J-17.1 had already been put back to CODED at `98f23c1f`.)
- Text corrected by the audit, state unchanged (29 criteria, among them F-07.2 which stays CODED): the evidence sentences now say what ran and what did not.
- **No criterion of the customer app pins (lavkooapp #19) became MET**: the audit kept J-01.3, J-05.3, J-09.1, J-11.3, J-13.2, I1-16.5, I2-10.5 and I2-13.1 CODED with corrected text.
- **The backend pull requests 116 to 123 and lavkooappseller #15 change no state in the tracker**: the audit units that would judge them (U11 to U15) could not run (see 3). Their requirements carry the PR link, the CI read and the gaps in the "Update after the assessment" line.
- **Ready for owner review: none.** No approval of any kind is recorded; nobody named as reviewer (R1 to R6 in `REVIEW_GATES.md`).

Biggest remaining gaps (unchanged): (1) the storefront and its currency are not yet cross-cutting (money outside the formatter, queued sales, order history in the order's currency); (2) wallet and loyalty are still one global balance per customer (no per-market account); (3) no legal-entity dimension anywhere (settlements, wallets, gateways).

## 2. Branches and pull requests (head SHA, CI read)

Backend `fullstackfull/lavkoo` (all drafts; CI is the `pull_request` run; every run is red only for the deployment overlay, by design, and, on branches based on main, for `TorodShipmentDiscoveryTest::test_the_scheduled_run_links_what_appeared_later_and_keeps_the_status_of_a_linked_shipment_current`, the red of main itself):

| PR | branch | head | stacked on | CI run read per job |
|---|---|---|---|---|
| [96](https://github.com/fullstackfull/lavkoo/pull/96) | `gcc/p1-storefront-six-country-isolation` | `e624e43e` | PR 87 line | 356: 22 of 23 |
| [111](https://github.com/fullstackfull/lavkoo/pull/111) | `gcc/p1-storefront-lifecycle` | `7e19d284` | PR 105 line | 355: 22 of 23 |
| [112](https://github.com/fullstackfull/lavkoo/pull/112) | `gcc/p1-theme-publish-per-storefront` | `eaa04e2f` | PR 107 | 359: 22 of 23 |
| [114](https://github.com/fullstackfull/lavkoo/pull/114) | `gcc/p1-legal-text-publisher` | `c440a9f1` | PR 105 | 357: 22 of 23 |
| [115](https://github.com/fullstackfull/lavkoo/pull/115) | `gcc/p1-storefront-admin-screens` | `d5755860` | PR 111 and 114 | 358: 22 of 23 |
| [116](https://github.com/fullstackfull/lavkoo/pull/116) | `gcc/p1-security-coupon-ownership` | `8ab3a4dd` | main | 360: 15 of 21 (overlay + Torod) |
| [117](https://github.com/fullstackfull/lavkoo/pull/117) | `gcc/p1-order-writers-gate-list` | `7a638ed1` | PR 111 | 361: 22 of 23 |
| [118](https://github.com/fullstackfull/lavkoo/pull/118) | `gcc/p1-wallet-contract-tests` | `533e4cbf` | main | 362: 15 of 21 (overlay + Torod); no real-engine job for tests/Feature/Storefront on main |
| [119](https://github.com/fullstackfull/lavkoo/pull/119) | `gcc/p1-stop-mode-pins` | `71a05e62` | PR 111 | 363: 22 of 23 |
| [120](https://github.com/fullstackfull/lavkoo/pull/120) | `gcc/p1-coupon-behaviour-pins` | `2ab62bb5` | PR 96 | 364: 22 of 23 |
| [121](https://github.com/fullstackfull/lavkoo/pull/121) | `gcc/p1-legal-store-pins` | `af88f045` | PR 115 | 365: 22 of 23 |
| [122](https://github.com/fullstackfull/lavkoo/pull/122) | `gcc/p1-legacy-behaviour-pins` | `ce8786b7` | main | 367: 17 of 23 (overlay + Torod); the new CI group *Notification and localization tests* green on MySQL 8 and MariaDB 11.4.2 (37 tests) |
| [123](https://github.com/fullstackfull/lavkoo/pull/123) | `gcc/p1-notification-stop-pins` | `53b1d22f` | PR 111 | 368: 22 of 23 |

Customer app `fullstackfull/lavkooapp`: [#19](https://github.com/fullstackfull/lavkooapp/pull/19) `gcc/p1-customer-pins` `0ed2c844` (push run 33 green, six named steps); the older stack #14 to #18 is in `PR_LEDGER.md`.

Seller app `fullstackfull/lavkooappseller` (the pull requests stacked on a branch that is not main have no run of their own: the evidence is the CI-only branches): [#12](https://github.com/fullstackfull/lavkooappseller/pull/12) `gcc/p1-seller-market-money` `03821fef`; [#13](https://github.com/fullstackfull/lavkooappseller/pull/13) `gcc/p1-seller-market-selector` `8b6f6793`; [#14](https://github.com/fullstackfull/lavkooappseller/pull/14) `gcc/p1-seller-outbox-owner` `2c3082cb`; [#15](https://github.com/fullstackfull/lavkooappseller/pull/15) `gcc/p1-seller-outbox-market` `c8162742` (holds #13). CI-only: `claude/seller-all-integration-ci` `50844615` (run 32: every named step success, the whole suite red only for the known `app_version_gate_test`) and `claude/seller-outbox-market-ci` `c8162742` (run 33: every named step success, same known red).

### Unreviewed work in progress, pushed only so that nothing is lost (NO pull request, NOT reviewed, do not merge)

| repo | branch | head | state |
|---|---|---|---|
| lavkoo | `claude/wip-unreviewed-theme-cache-identity` | `619d04d0` | W5-F: F-04.5, F-10.2, F-12.2, F-12.3, on PR 112. Review verdict FIXES_NEEDED, fix round not done (see 4) |
| lavkoo | `claude/wip-unreviewed-marketing-stop-registry` | `72a568e1` | W5-I: I1-07.2, I1-07.3, on PR 111. Implementation only, review never ran |
| lavkooapp | `claude/wip-unreviewed-customer-order-currency` | `b344cbfe` | W5-J: J-13.1, on #19. Implementation only, review never ran |
| lavkooappseller | `claude/wip-unreviewed-seller-flow-tests` | `83bd60f1` | W5-K: K-16.4 flow tests. Unfinished, written but never run to completion |

## 3. Audits: done and pending

Done (data in `docs/gcc/tools/tracker-generator/data/flips-audit-2026-11-19.json`, 53 decisions, applied by `apply_audit_2026_11_19` in `gen_main.py`): U1 to U7 (theme, caches, preview, schedule, contract, admin screens, runs 355/356), U8 (seller wave 2), U9 (customer app gate), U10 (customer app pins, #19), U2 re-run.

**Pending, could not run (weekly limit): U11 to U15.** Scripts in the resume kit: `flipsaudit5.js` (U11 coupons: PR 116 + 120; U12 writers and stop modes: PR 117 + 119; U13 wallet and legal: PR 118 + 121) and `flipsaudit6.js` (U14 PR 122 + #19 for I1-16.5; U15 PR 123). Their CI logs were saved in the old scratchpad (`job_36x_*.txt`); in a new environment fetch the logs again (see 6). Criteria they would decide, and what the implementers admit (the pull request bodies are the full evidence): 
- U11: I1-11.3 (PR 116 fixes four ownership defects, PR 120 pins the rest; delete/export/staff permissions not covered), I1-11.4, I1-12.0, I1-14.0, I1-14.1 (covered by PR 120), I1-12.1 and I1-12.2 (NOT proven: the admin form floors stored amounts).
- U12: I2-09.2 (the scan of order writers, PR 117), I2-09.0 / I2-09.1 / I2-09.3 (not met: POS routes carry no gate, no owner rule for the marketplace materializer, POS and the materializer record no storefront), I2-13.2 (partial: website cart structural), I2-14.0 (covered, cache-only maintenance).
- U13: E-04.2 (PR 118), E-08.0 (the sentence is FALSE on main: the two ledger repositories update and delete rows), E-17.0 (class-only), I2-01.2, I2-06.2, I2-07.4 (PR 121), I2-06.1 (structural), I2-07.0 (four staff-panel sentences claim compliance).
- U14/U15: I1-03.1, I1-05.3, I1-09.1, I1-10.4, I1-16.5, M1-05.5, M2-06.2 (PR 122, all partial) and I1-07.1 (PR 123: no mail path exists, the due-payment push is never sent, so "sent" cannot be MET as written).
The decision `SET_CODED` (MISSING to CODED) is already mapped in `_AUDIT_STATES` of `gen_main.py`; build the new decision file with `scratchpad/build_audit_json.py <out> <journal dirs>` (kit), regenerate, run the ledger check, leak guard, push.

## 4. Wave 5 in detail

- **W5-F (themes)** `619d04d0`, the review found no high issue and **three medium**: (1) F-10.2: a banner of another theme folder pointing at an Emirati product still passes (the `whereNotIn` sits on the base query only; mutation survives 58 tests); (2) nothing pins that an UNPUBLISHED edit of the default storefront's theme does not reach other storefronts; (3) activating ANOTHER theme of the default storefront (the second way the shared identity changes) is not pinned for other storefronts. Low: the narrowing reads every product banner and hydrates products per request; fails open on an exception; a declared global cache key (`commerce_segment_ctx_*`) sums orders across currencies (total_spent adds riyals, dirhams and dinars); the guide `docs/gcc/F-01-STOREFRONT-THEME.md` still says F-12 is not done. Design decision to confirm with the owner: the "shared identity" is the settings of the default storefront's published theme; with `GCC_STOREFRONT_THEMES` on, any other storefront's theme inherits it key by key (the Emirati theme then shows Saudi tokens for every key it does not set). Findings: the theme settings screen posts every field, so saving there turns every inherited value into an override; no admin action deactivates a theme, so a storefront cannot go back to the default's whole theme.
- **W5-I (marketing stop and registry)** `72a568e1`: the abandoned-cart reminder and the cart-marketing list skip a storefront that may not sell (a skipped reminder writes no ledger row); `NotificationTypeRegistry` lists 68 push, 22 e-mail and 4 WhatsApp types as necessary or marketing; scan tests both ways. Findings: `StorefrontSellingGate::maySell` ignores `gcc.storefronts.countries.<X>.active` (a storefront switched off only in the config is still reminded); the admin push broadcast has no storefront (one FCM topic, every approved seller); `NotificationAutomationService::trigger*` has no caller; `customer_block_message` and `customer_unblock_message` are never sent; the due-payment push is never sent. Review never ran.
- **W5-J (order currency in the customer app)** `b344cbfe`: `OrderModel` parses `storefront_country`, the currency code and the rate when the backend sends them; totals are written in the order's currency (3 decimals for KWD, BHD, OMR), the storefront is shown, only with the flag on; the answers were hand-written from the backend code, not recorded from a real server (no backend HTTP test asserts `currency_code` or `currency_rate` in an answer). Review never ran.
- **W5-K (seller flow tests)** `83bd60f1`: six files in `test/flows` (orders, products, product save, stock, withdrawals, reviews) and named CI steps; never run to completion.
- **W5-K2 (seller outbox market)** is lavkooappseller #15 (reviewed, fixed once, CI read). Open: the market is read when the sale is queued (after the first request failed), a restart binds a no-seller sale to the first session of the new run, a no-seller sale of an ended session is sent by no session.

## 5. Unfinished tasks, in priority order

1. Re-run U11 to U15 (3), apply, regenerate, push the docs. 2. W5-F: fix the three medium review issues, re-review, open the PR on PR 112's branch. 3. Review W5-I, W5-J and finish W5-K, then open draft PRs and read their CI. 4. Read the push run of `claude/wip-unreviewed-seller-flow-tests` (the seller CI runs on `claude/**`). 5. The owner items below. 6. Candidate next criteria not started: J-10.1/.4 (prefixed deep links, owner rule J-10.2 open), F-09.4, F-08.4, M2-19.2 (Saudi-literal guard), E-13.2/E-05.3 (refund reference, replayed credit: finance review first), K-12.3/K-08.4.

## 6. Owner items and risks to carry (none decided, none approved)

Security and money: the coupon pull request 116 needs one judgement call (the seller app now refuses an admin coupon made for one vendor) and keeps one hazard (a coupon the admin repointed to every vendor can be deleted by any vendor); the ownership sweep `docs/gcc/audits/2026-11-18-ownership-sweep/` (PR 21 merge decision, F1 wallet payer, B1 to B5); `POST /customer/auth/login/resend-otp-code` answers 500 for a customer with no verification row; the vendor panel password reset logs the one-time code and phone (branch `gcc/p0-stop-logging-one-time-codes`); E-08.0 (remove or make throw the four repository methods that update and delete ledger rows); the due-payment push that is never sent; POS accepts an empty `modified_cart`; the website login does not count wrong passwords.
Rules and releases: the marketplace and POS sale-gate rule (I2-09); the J-09.1 due-payment guard in the apps; the version-gate test of the seller app is red on purpose (decision A, B or C: never touched); the seller idempotency key needs a seller release and lavkoo #35; the strict-SQL server fails the checkout on a bare `GROUP BY` (PR 89); four staff-panel sentences claim legal compliance (I2-07.0); 83 Arabic strings and two server messages need a native reader; reviewers R1 to R6 are not named; admin schedule audit lines are unconditional (owner decision); the `main` red `TorodShipmentDiscoveryTest` (a time bomb, the one-line repair is in PR 1).
Housekeeping: a stray file `/six-one-mysql.txt` at the root of an earlier container, and an undeletable remote branch `gcc/tmp-analyzer-gate-proof`; the earlier disk-allowance incident and the deletion of a vendor `.git` directory (see the handoffs of 2026-11-17).

## 7. How to resume mechanically

- Branches for the next account: develop on `claude/*` or `gcc/*` branches under the standing directive; commits end with the two trailer lines (`Co-Authored-By: ...`, `Claude-Session: ...`) required by the session; pull request bodies end with the generated-with line and the session link; never force push; draft pull requests only.
- Leak guard before every push, from a worktree that has `scripts/ci/leak-guard.php`: `php scripts/ci/leak-guard.php --base=<sha> --head=<sha> --root=<worktree>` must exit 0.
- Real-engine runs: a scratch MySQL 8.0 on 127.0.0.1:3308 (`mysqld --no-defaults --user=root --datadir=/var/lib/mysql-scratch-3308 ... --port=3308 --skip-log-bin --innodb-flush-log-at-trx-commit=0`) and `mysql_wt.sh` of the kit (copies the worktree, runs `phpunit -c phpunit.mysql.xml`, drops both). SQLite is the default of `phpunit.xml`. Flutter: SDK 3.47.0, `flutter pub get --offline`, then `git checkout -- pubspec.lock`; never commit `pubspec.lock`.
- Reading CI: the lavkoo workflow runs only for `pull_request` and `workflow_dispatch` (a branch push alone starts nothing); the app repositories run on push (`gcc/**` for the customer app, `claude/**` and `main` for the seller app). Read a run job by job: `jobsum.sh` / `jobsteps.py` / `prci.py` of the kit list jobs and steps; a job log comes from the GitHub MCP tool `get_job_logs` with `return_content=false` (a signed URL), then `curl -o <file>` and `grep "Tests:"`.
- Regenerating the tracker: `cd docs/gcc/tools/tracker-generator; export GCC_REPOS_ROOT=<dir holding the three clones>; python3 gen_main.py ../.. <date>; python3 gen_pr_ledger.py ../.. <date>; (cd ../../../..; python3 docs/gcc/tools/check_pr_ledger.py)`; `python3 batch_measure.py ../../../.. origin/claude/exciting-shannon-4mh2ok` prints what moved. Rules for editing `gen_main.py` are in the README of that folder: a criterion is MET only with a named test run on the right head and read job by job, and after the audit.
- The workflow scripts of the kit use the Workflow tool; they were written for the scratchpad of the old container (paths inside are the old ones: replace them). `wave5.js` (four implement, review, fix pipelines), `flipsaudit5.js` and `flipsaudit6.js` (the pending audits), `flipsjudges.js` (judge-only), `build_audit_json.py`. A new container has no vendor directory: run `composer install` once in a worktree and hard-link it into the others (`cp -al`).
