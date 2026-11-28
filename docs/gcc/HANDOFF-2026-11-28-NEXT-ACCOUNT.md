# Handoff — 2026-11-28, for the next Claude account

## Where everything is

| Repository | Branch | SHA | State |
|---|---|---|---|
| `fullstackfull/lavkoo` | `gcc/development` | `6ede8757` | all round-3 work merged and pushed |
| `fullstackfull/lavkooapp` | `gcc/r3-customer-app` | `09faaa6` | pushed, suite 1,060 pass / 105 skip |
| `fullstackfull/lavkooappseller` | `gcc/r3-seller-app` | `c9a13d3` | pushed, suite 1,986 pass / 2 skip |

Nothing is uncommitted anywhere. Two squash-merged branches' full history is preserved at
`gcc/r2-hac-history` and `gcc/r3-egbf-history` so no commit exists only on the old container.

**Figures (`php docs/gcc/audits/2026-11-24-post-merge-assessment/count.php` — never type a number
it can print): 1,566 in code of 1,754 (89%) · 1,153 proven (66%) · 188 missing.**

Last comprehensive CI: **run 683, green, 50 success / 0 failure / 2 skipped by design**, on
`5ae65ea8`. Round 3's work landed after it, so `6ede8757` has NOT had a comprehensive run.

## Do this first

1. **The integration gate is unfinished.** Two attempts timed out at 78% (`timeout 7000` and
   `timeout 6600`, phpunit exit **124** both times) on 14,644 tests. One `F` was seen at about
   test 11,505 and is **still unidentified**. Split the gate into halves of about 6,000 tests, or
   raise the inner timeout past 7,000 seconds, and name that failure before spending CI.
2. Then dispatch the comprehensive CI once on a frozen SHA.

**A trap that cost three near-misses: a task notification's exit code is the shell wrapper's, not
the command's.** Put `echo "phpunit exit: $?"` immediately after the command and read THAT. Also:
phpunit writes its failure list at the END, so grepping a partial log always reports zero
failures, and phpunit exits 0 on a path that does not exist.

## Decisions the owner has not yet made (each blocks a named criterion; nothing else waits on them)

1. **The wallet race fix shape.** Root-caused to `app/Utils/Helpers.php:606` —
   `sales_commission_before_order()` re-reads the carts table and indexes `[0]` with no guard. The
   loser of a wallet race takes a locking read, so its snapshot for plain reads lands after the
   winner deletes the cart. **Not an over-draw**: the loser raises before the debit and rolls back;
   the cost is that a customer who should be told their balance is short gets a 500. Reproducer
   with no timing in it: `tests/Feature/Wallet/CommissionReReadsTheClearedCartTest`. Not fixed
   because that function is on EVERY checkout path. Options: a guard there, a cart read that cannot
   return empty, or holding the cart rows until the transaction closes.
2. **`payment_requests.payment_amount` is `DECIMAL(24,2)`** — 10.234 KWD loses four fils in the
   INSERT. Confirm `2026_05_07_000001_update_all_decimal_columns_to_40_20` has run in production,
   or approve widening. This is what keeps D2-V1 at CODED.
3. **Seller analytics `revenue` sums riyals and dirhams** with no currency column in any analytics
   table (`EventRecorder` accumulates `$delta['revenue'] += $event->value`). A made-up money figure
   on a live screen. Needs a migration plus a decision on historical rows.
4. **Releasing `gcc.payments.amount_in_the_currencys_minor_unit`** (ships off) — it fixes a real
   10x undercharge: 10.23 KWD was sent to Paymob as 1,023 fils, i.e. 1.023 dinars.
5. G-16 "encrypted at rest"; E-02's account opener (opening one inside the writer's transaction
   deadlocks both ways in InnoDB); G-11's search-index job; H2-07's Amazon listing key and
   per-marketplace currency; Stripe's minor-unit convention for KWD/BHD/OMR; the delivery man's
   money (`delivery_men` carries no country); the name of G-07's privilege-widening capability;
   `docs/address/README.md` decision 11; how a market is named to customers; the live
   `seller_app_version_control` values. All recorded with options and a recommendation in
   `docs/gcc/tracker/owner-decisions.md`.

## Things that will waste your time if you do not know them

- **`flutter test` and `flutter analyze` DO work here** after
  `git config --global --add safe.directory /home/user/flutter`. Earlier notes in
  `EXECUTION_STATE.md` saying otherwise are superseded. Revert `pubspec.lock` and
  `GeneratedPluginRegistrant.swift` afterwards.
- **The app CI format step reads against `origin/main` on a new branch's first push**
  (`github.event.before` is all zeros), so 26 pre-existing unclean files appear that nobody
  touched. Open the PR against `gcc/development` and only your own files are read.
- **Do not run two suites in one working tree** — the language-file restore is per process, so two
  processes make the tree transiently dirty and produce spurious failures that pass serially. That
  is what an earlier "four cross-track failures" turned out to be.
- **Confirm a teeth-check mutation actually landed** (print the occurrence count) before concluding
  a test is toothless. A failed mutation and a toothless test look identical, and the flattering
  reading is the wrong one.
- **`scripts/ci/leak-guard-allow.json` is the owner's.** When a branch trips the guard on a
  fabricated key- or phone-shaped example in its own earlier commit, squash-merge instead: that
  keeps the string out of the remote entirely and needs no exception. Done twice.
- The container restarted three times on 2026-11-27 and took MariaDB with it. Start it with
  `/usr/sbin/mariadbd --user=mysql --datadir=/var/lib/mysql`.
- Full detail, including every lesson and the open-defects register, is in
  `docs/gcc/EXECUTION_STATE.md`.

## Forbidden without the owner's approval (unchanged)

Merging to `main`; production deploy; production data access or real balance migration; activating
any country or market; changing a financial control in production; weakening a security or
financial guard; force push or history rewrite. Keep sensitive financial switches off until their
release is approved — of 84 `GCC_*` switches only two default on, both approved: the C-16 till
gate and MFA-required.
