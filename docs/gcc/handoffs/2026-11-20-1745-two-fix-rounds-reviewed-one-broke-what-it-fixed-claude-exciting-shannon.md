# Handoff — two fix rounds independently verified; one introduced the defect it existed to remove

**Date:** 2026-11-20 17:45 UTC
**Branch (docs):** `claude/exciting-shannon-4mh2ok`
**Stage:** tracker evidence + engineering rework in flight. Nothing merged, nothing deployed, no production data touched, no force push.

## 1. What was verified

Five independent adversarial lanes ran against two fix heads that had only self-reports:

| head | pull request | lanes | verdicts |
|---|---|---|---|
| `7465de7d` on `gcc/p1-wallet-balance-writers-guard` | 95 (draft) | 3 | `fix_incomplete` ×3 — all three named the same root cause |
| `5a0b7484` on `gcc/p1-ledger-chain-per-entity` | 137 (draft) | 2 | `fix_wrong`, `fix_incomplete` |

Neither criterion moved. **E-17.2 stays MISSING. E-03.3 stays CODED.** The tracker is unchanged at 1753 criteria, **813 MET**, **361 CODED** — only the evidence behind those two records changed, and it got worse, not better.

## 2. PR 137: the fix round broke the invariant it existed to restore

`app/Services/Marketplace/VendorLedger.php:69` narrows the idempotency lookup through the chain, and the chain key is resolved at write time from a setting that can move. So **one order line is credited twice** whenever the store's base-currency setting moves between two deliveries of that line — on a **one-currency, one-market, Saudi** ledger.

Measured: two deliveries of `order_details/900`, no currency named either time, the setting deleted in between → **2 rows, 100.0000 credited for a 50.00 earning**, both rows stamped `balance_after=50.0000`, and the settlement drawn from them records **opening 0 + net 100 = closing 50**. On the previous head `a7c8760e` the identical fixture gives 1 row and 50.0000. The lane is 292/775/0 green with the defect, and so is CI on both named engines. `config/gcc.php`'s two-currency gate does not cover it: it fires as soon as the switch is thrown.

The pull request body's central claim about the change — "a reference that repeats across a chain boundary is a different event and not a swallowed write" — is false as written. It is a different event when it is another market's order line; it is the **same** event when only the base currency moved, and the code cannot tell those apart. Reverting the line is not the cure either: that reinstates the swallowed cross-market write the round existed to fix.

**PR 137's title now carries `DO NOT MERGE`.**

Also open on that head, each measured: three of six guard sites unpinned (blinding `SettlementEngine::currencyRecordedFor()` and both gates in `RunVendorSettlements.php` leaves the lane green, and the two command gates wrong-print the operator's dry-run report as "1 settlement, net 140.00" — riyals plus dirhams in one number — while the run draws two settlements of 100 and 40); a fully refunded line leaving a payable settlement once earning and refund resolve different currency codes; and a pre-existing double credit the concurrency file cannot see because it races only distinct references (two processes delivering one order line give 3 rows and 210 credited for a 100 line, switch on or off).

**One correction in the branch's favour.** The CI evidence rule is now satisfied, which it was not before: four named jobs on `5a0b7484`, run 44762343116, all SUCCESS, read out of the logs — `Marketplace and ledger tests, MariaDB 11.4.2` (136377846762) and `MySQL 8` (136377846804) at 292/775, and both `(concurrency)` jobs (136377847198, 136377847378) at 48/714, which are the only two jobs where the new row-lock test executes at all. E-17.2 is not MISSING for want of CI. It is MISSING because the **legal-entity half of the key is wired to nothing**: eight `VendorLedger::record()` call sites, none passing a company, no legal-entity table, model, migration, resolver or mapping anywhere.

## 3. PR 95: the guard went backwards on one path

`tests/Support/PlatformWriteScanner.php:585` cuts the statement at the **first** occurrence of the word `where`, anywhere in it — which deletes the `SET` clause itself when a join subquery, a subselect or a **quoted literal** contains that word first. **Seven money writes that the previous head `9f6bc526` caught are missed at `7465de7d`**, each proved on MariaDB 10.11.14 to write `0.00000000000000000000` into a `decimal(40,20)` wallet seeded at `500.50000000000000000000`. The regression is disclosed nowhere, and the trade is not forced: removing the cut turns exactly two synthetic provider cases red and would have caught all seven.

Two more classes, both green on the branch today:

- `withSqlCommentsStripped` (line 599) requires whitespace after `--`, so `--` at end of line is not stripped although the engine treats it as a comment. The statement planted in the real listed migration `2025_07_13_021823_...` left all 71 tests green and was proved to write.
- **27 money writes under `app/`**, each spelling `wallet_balance` plainly on one line, planted one per file: guard green at 71/112, and a rescan with the branch's own scanner still named exactly the same six writers over 2368 files.

So the pull request's own headline sentence — "This is one class: a name the file does not spell" — is **false**. The survivors spell it plainly, and two of them sit in the very migration the pull request headlines.

The first half of MET *is* satisfied and the record now says so: named jobs passed on the named head (run 44760202351, `Fulfillment + security tests` green on MariaDB 11.4.2 and MySQL 8, `Tests, SQLite` green on PHP 8.2 and 8.3, 18 of 21 checks success, only the by-design overlay red). What is missing is **coverage**. A guard that is green while 30 proven money writes stand in the directories it scans does not establish the criterion.

## 4. A collision no single pull request can see

**PR 95 and PR 142 both extend `tests/Support/PlatformWriteScanner.php`.** Neither can see the other. The rework lane for PR 95 has been told to read PR 142's version before restructuring, and to shape the change so both can land.

## 5. In flight

A two-lane rework is running: one lane per defect, each followed by an independent adversarial verification on the head it pushes. Both lanes must prove every fix by mutation — revert the fix in one line, show the test that goes red — because a fix with no mutation proof is what produced both of the rounds reviewed here.

## 6. Owner decisions still open

Unchanged from the previous handoff, plus one made sharper by this round:

1. **What should a payout do for a seller holding two chains?** Refuse until a currency is chosen; draw per chain; or keep drawing on the sum, which is what the code does with the switch on and leaves one chain negative and another stranded. A financial rule, not a technical choice.
2. **Is `vendor_ledger_entries.legal_entity_id` to be added by a migration on live data, and where does the value come from?** Without a source the column can only hold NULL, and E-17.2 cannot be MET.
3. **`installation/backup/database.sql` is in no ref of this repository.** `SHOW CREATE TABLE users` from a copy of the live schema is needed before PR 142 is merged: if a column the account scrub nulls is `NOT NULL` on the live server, the request 500s — and only for customers who hold money.
4. **A minor-unit field** before any 1/1000-currency market (KWD, BHD, OMR). The 0.01 threshold now in the code is one halala and one fils, but up to nine of the smallest unit in those three.

---

https://claude.ai/code/session_018Q6HuHNDa9TWpNZBsZLmVX
