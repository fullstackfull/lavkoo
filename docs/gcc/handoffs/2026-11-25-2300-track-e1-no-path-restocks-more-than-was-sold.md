# Track E1 — no path restocks more than was sold

**Written** 2026-11-25, 23:00 UTC. **Worktree** `/home/user/wt/klj`, branch `wt/klj`, cut from `30a2283e`.
**Commits** `e7d40792`, `100a362f`. **Not pushed.**
**Criterion** axis-m line 654 — *No path restocks more than was sold (reopened line, partial then whole
return, return above the line)*; recorded evidence *"Four defects pinned as 'currently' in PR 24, not
fixed"*.

**Database** `pharmacy_testing_klj`. **Tests run:** `tests/Feature/Inventory` (54, green),
`tests/Feature/Marketplace/ReturnReceiptTest.php`, `tests/Feature/Concurrency/RestockBudgetConcurrencyTest.php`,
`tests/Feature/Concurrency/ReturnReceiptConcurrencyTest.php` (16, green), and the four suites that drive the
order edit writer — `tests/Feature/Security/VendorOrderEditProductsTest.php`,
`SellerApiOrderEditProductsTest.php`, `VendorOrderHandlerOwnershipTest.php`,
`tests/Feature/Payment/CustomerOrderEditPayDueAmountTest.php` (206, green). **276 green, 0 red.**

---

## 1. The headline: the tracker line is stale, and the hole it hid is a fifth writer

The four defects PR 24 pinned as `..._currently_...` are **already fixed on this branch**, by work that
reached it before `30a2283e`. Every one of their tests has been rewritten to assert the correct behaviour and
every one has teeth (section 2). The criterion was nevertheless still open, because the *bug family* was not
closed: the single place that answers "how many units of this line may still be put back"
(`App\Services\Marketplace\RestockBudget`) was asked by **three** of the **four** writers of
`products.current_stock` on an order line. The fourth — the order edit screen's writer, reached by eight
controllers — still answered the question itself, and got it wrong after a partial return.

That is the defect this track found and fixed. It is the same family, the same shelf and the same arithmetic
as the four pinned ones; it was simply the writer nobody had taught.

---

## 2. The four pinned defects: state, fix, pinning test, teeth

The four old names and assertions are quoted from `origin/gcc/p0-stock-holds-characterization` (PR 24,
`eebb5884`), so the change of meaning is visible even though the rewrites happened before this branch point;
none of the four was deleted.

Every teeth check below is a deliberate break of the production code, `tests/Feature/Inventory` (54 tests)
run against MySQL, and a revert. The mutation harness lived outside the repository.

### (a) Re-opening a cancelled line drove the variant's quantity negative

| | |
|---|---|
| Where | `app/Utils/OrderManager.php:190`; twin `app/Repositories/OrderRepository.php:724`; third copy `app/Traits/OrderEditManager.php:366` |
| Pinned as (PR 24, `origin/gcc/p0-stock-holds-characterization`) | `test_re_opening_a_cancelled_line_floors_current_stock_at_zero_but_currently_drives_the_variant_negative`, asserting `$this->assertSame(-2, $this->variantQuantity('Red'), 'the variant JSON is decremented without a floor');` |
| Now asserts | `StockHoldsCharacterizationTest::test_re_opening_a_cancelled_line_floors_both_current_stock_and_the_variant_at_zero`; `RestockThroughTheRealRoutesTest::test_re_opening_a_cancelled_line_through_the_panel_never_drives_the_variant_below_zero`; `::test_the_order_edit_writer_floors_the_variant_at_zero` |
| Fix | `max(0, $var['qty'] - $units)` on the re-deduct, beside the floor `current_stock` already had |
| Teeth | **Yes.** Floor removed in the status writer → 1 red (`..._floors_both_current_stock_and_the_variant_at_zero`). Floor removed in the edit writer's copy → 1 red (`..._the_order_edit_writer_floors_the_variant_at_zero`). Each names the figure: `0` expected, negative found |

### (b) A partial return then marking the order returned restocked twice

| | |
|---|---|
| Where | `app/Utils/OrderManager.php:225-229` (`unitsThisStatusChangeMoves`); `app/Repositories/OrderRepository.php:689-691` |
| Pinned as (PR 24) | `test_a_partial_return_then_marking_the_order_returned_currently_restocks_more_units_than_the_line_held`, asserting `$this->assertSame(54, $this->stock(), 'three units left the shelf and four came back: 1 by the receipt and 3 by the status writer');` |
| Now asserts | `StockHoldsCharacterizationTest::test_a_partial_return_then_marking_the_order_returned_restocks_exactly_what_left_the_shelf`; `RestockThroughTheRealRoutesTest::test_a_partial_return_then_the_order_marked_returned_puts_back_exactly_what_left_the_shelf` |
| Fix | The restore spends `RestockBudget::unspentUnitsOf()` instead of the line's whole quantity |
| Teeth | **Yes.** `RestockBudget::unspentUnitsOf` made to ignore units already back → **14 red**. The panel copy alone made to ignore the budget → **5 red**, which is also the proof the two copies are separately covered |

### (c) A return typed for more units than the line held restocked the typed figure

| | |
|---|---|
| Where | `app/Services/Marketplace/ReturnLogisticsService.php:159-189` |
| Pinned as (PR 24) | `test_a_whole_line_return_for_more_units_than_the_line_currently_restocks_the_returns_quantity`, asserting `$this->assertSame([55, 0], [$this->stock(), $this->stockFlag()], 'the claim checks line qty <= return qty, then adds the return qty, not the line qty');` |
| Now asserts | `StockHoldsCharacterizationTest::test_a_whole_line_return_for_more_units_than_the_line_restocks_the_lines_quantity_and_reports_the_clamp`; `RestockThroughTheRealRoutesTest::test_a_return_for_more_units_than_the_line_held_restocks_the_lines_quantity_and_says_so`; `::test_a_return_above_the_lines_quantity_is_a_shape_the_panel_can_create_and_it_is_clamped`; `::test_a_clamp_caused_by_units_already_back_says_that_and_not_something_untrue` |
| Fix | `min($rma->qty, unspentUnitsOf(...))`, and `clamped` reports `asked`, `restocked`, `already_back`, `line_held` so the operator is told rather than quietly corrected |
| Teeth | **Yes.** Covered by the same budget mutation as (b) (14 red includes the clamp-report tests) |

### (d) A restocked return of a variant line left the variant's quantity unchanged

| | |
|---|---|
| Where | `app/Services/Marketplace/ReturnLogisticsService.php:197-201`, `variationWithReturnedUnits` at `:446` |
| Pinned as (PR 24) | `test_a_restocked_return_of_a_variant_line_currently_leaves_the_variant_quantity_unchanged`, asserting `$this->assertSame([52, 20], [$this->stock(), $this->variantQuantity('Red')], 'current_stock rises, the variant JSON is never written');` (the tracker records PR 17 as its fix) |
| Now asserts | `StockHoldsCharacterizationTest::test_a_restocked_return_of_a_variant_line_puts_the_units_back_on_the_variant_too`; `RestockThroughTheRealRoutesTest::test_a_return_authorized_in_the_panel_puts_back_what_left_the_shelf_and_no_more` |
| Fix | The receipt writes `products.variation` with the returned units, preserving the column's own keys |
| Teeth | **Yes.** The variant write skipped → **6 red** |

Two further guards of the same family were teeth-checked while the harness was up, both green-with-teeth:
a restore that puts nothing back keeping the line's claim (`OrderManager.php:157`, mutated → 1 red,
`test_the_rest_api_copy_of_the_status_writer_never_drains_the_shelf_either`), and the claim guard in the edit
writer (section 3).

---

## 3. The defect this track fixed: the fourth writer, after a partial return

**File and line (before):** `app/Traits/OrderEditManager.php:199-209` as `30a2283e` had it, inside
`addAndUpdateProductsInOrderDetails` — the writer the order edit screens run, reached by eight controllers
(`Admin\Order\OrderEditController`, `Admin\Order\OrderController`, `Admin\Vendor\VendorController`,
`Vendor\Order\OrderEditController`, `Vendor\Order\OrderController`, `Customer\PaymentController`,
`RestAPI\v1\OrderEditController`, `RestAPI\v3\seller\OrderEditController`).

**What it did.** It gave a line's units back whenever `is_stock_decreased` was 1 — its own question, asked
instead of the shared one. A **partial** return leaves that claim with the line, because only the receipt
that *completes* a line takes it (`ReturnLogisticsService.php:170-172`, the `completesTheLine` branch). So
the guard saw 1, read it as "the whole line is still with the customer", and put the whole line back.

**Measured on the real routes** (`POST admin/orders/status`, `POST admin/marketplace/returns/{id}/receive`,
then the trait's own method): shelf 50 with a line of 3 off it, a return of 1 received → 51, an edit that
drops the line → **54, where 53 is all that ever left**. The variant's quantity inside `products.variation`
gained the same phantom unit, 20 → 24. An edit that shrank the line to one gave 53 where 52 is right.

**No pinning test existed.** The two tests that covered this writer
(`test_the_order_edit_writer_does_not_give_back_units_a_return_already_put_on_the_shelf`,
`test_an_edit_that_drops_a_returned_line_puts_no_units_on_the_shelf`) both used a **whole**-line return,
which flips the claim to 0 and so never reached the case.

**Fix — `e7d40792`.** The quantity decision moved out of this writer into the place that already held it. Not
a fourth clamp: `unitsThisEditMayGiveBack()` (`app/Traits/OrderEditManager.php:337-347`) asks
`RestockBudget::unspentUnitsOf()`, exactly as `OrderManager::unitsThisStatusChangeMoves` and the
`OrderRepository` twin do. Two questions, now asked the same way by all four writers: `is_stock_decreased` is
the **claim**, `RestockBudget` is **how many of the claimed units are still off the shelf**. Re-taking is
left unbounded, which is `OrderManager`'s own documented rule — an edited line is live for all of its units
again, and bounding that direction would let the shelf offer units an open order has promised.

**Three tests added** to `tests/Feature/Inventory/RestockThroughTheRealRoutesTest.php`:

- `test_the_order_edit_writer_does_not_give_back_units_a_partial_return_already_put_on_the_shelf`
- `test_an_edit_that_shrinks_a_partly_returned_line_gives_back_only_what_is_still_off_the_shelf`
- `test_an_edit_that_drops_a_line_the_status_writer_already_restored_puts_no_units_on_the_shelf`

**Teeth.** The writer made to ignore the budget (the defect restored) → the first two red, `54` for `53` and
`53` for `52`, each naming the figure. The budget made to ignore units already back → both red among the 14.

### An inert tooth, found and closed

The third test exists **because the claim guard had no teeth.** With the budget in place, removing
`if ((int)($detail['is_stock_decreased'] ?? 1) !== 1) { return 0; }` altogether left **all 53 tests green** —
every covered line's units were back through a `return_shipments` row the budget can read, so the budget
alone answered 0. A line the **status writer** restored has no such row: the claim is the only record of it,
and an edit trusting the budget alone puts those units back a second time (53 → 56). The new third test
fails if the guard goes (mutation → 1 red). The guard was never weakened or removed; it was given a reason to
exist that a test can see.

---

## 4. The second commit: the lock the other three take — `100a362f`

The shared budget is only as good as the serialisation around it. The two status writers and the receipt lock
the order line **before** they read the budget and keep it locked while they spend
(`OrderManager.php:146`, `OrderRepository.php:681`, `ReturnLogisticsService.php:155`); the edit
writer read it unlocked. An admin saving an edit while a receipt for the same line is processed could put one
unit back twice: the edit reads three unspent, the receipt locks the line, receives one, restocks one, and
the edit then adds three — four on the shelf for three that left.

`lockTheLinesThisEditGivesBack()` (`app/Traits/OrderEditManager.php:311-320`) locks **all** of the order's
lines, in id order, **before the first product row is locked**. `adjustProductStock` holds each product lock
until the edit's own transaction commits (its `DB::transaction` is a savepoint inside
`writeEditedOrder`'s), so locking a line *after* a product would let an edit hold a product another writer
wants while waiting for a line that writer holds. Taking every line first means this writer never waits for a
line while holding a product, which rules the cycle out by construction. Lock order is then
`order_details` → `products`, the same as the other three.

**This change is NOT teeth-checked, and that is stated rather than implied.** A lock changes nothing a single
process can see, so no test in `tests/Feature/Inventory` can be made to fail by removing it. What is proven
is that nothing regressed: 54 + 206 tests green with it in place. The three writers that already have this
discipline are raced on a real engine by `tests/Feature/Concurrency/RestockBudgetConcurrencyTest` through the
operations in `tests/Support/Concurrency/registrations/return_receipts.php`. **Next step:** add a
`returns.edit_order_lines` operation and race a partial receipt against an order edit. It was not done here
because the edit writer's table set (`order_details_rewards`, `order_taxes`, `order_edit_histories`,
`order_status_histories`, `referral_customers`, `users`, …) is far larger than the four tables
`BuildsReturnReceiptSchema` builds, and a six-second process-spawning test added under load on a four-core
box shared by five tracks is a flake risk that should be taken deliberately, not incidentally.

---

## 5. Saudi continuity

Byte-for-byte unchanged for a correct single return, and the reasoning is arithmetic rather than a
reassurance: `RestockBudget::unitsAlreadyBack()` returns **0** when the line has no `return_shipments` rows
and **0** when that table does not exist, so `unspentUnitsOf()` returns the line's own quantity and the edit
writer passes the same figure it always did. A **whole**-line received return already answered 0 through the
claim guard, which is untouched. The only behaviour that differs is the case that was wrong: a line with a
**received, restocked, partial** return behind it. `tests/Feature/Marketplace/ReturnReceiptTest` and both
return concurrency classes are green, and `assertLanguageFilesUnchanged` holds — nothing wrote into
`resources/lang`.

---

## 6. Further restock writers found and NOT touched

### 6.1 `ChannelReturnIngestor` → `AutoRestockAcceptedReturns` → `ReturnDecisionService` — a fifth writer, of a different shelf

`app/Services/SalesChannels/Sync/ChannelReturnIngestor.php:56` calls
`App\Services\OperationalCosts\Returns\AutoRestockAcceptedReturns::forReturn()`, which calls
`ReturnDecisionService::decide()`. **It is not a fifth writer of `products.current_stock`**: it puts units
back at an `inventory_locations` row and onto the cost layers of an `ExternalOrderLine`, a separate shelf.
`ChannelReturnIngestor.php` belongs to another track and was read only.

It is **independently bounded, and bounded correctly** — `ReturnDecisionService.php:210`, `:227` and `:248`: a claim is
decided for exactly the units it has not had decided; a recorded return never exceeds what was sold less what
was already decided; units put back never exceed what was taken from the cost layers for the line over all of
its returns. So it does not restock more than was sold, and it does not need `RestockBudget` — the two shelves
have different "what left" figures. **Recorded, not changed.** Worth one line in the inventory-flow document:
there are two restockable shelves, each with its own single answer to the same question.

### 6.2 A return that names no order line restocks its typed quantity, uncapped — an open question, not a defect to fix blind

`ReturnLogisticsController::store` makes `order_details_id` optional, and
`RestockThroughTheRealRoutesTest::test_a_return_that_points_at_no_order_line_still_restocks_its_whole_quantity`
pins today's behaviour: 2 units sold, a return typed for 4 with no line → **shelf 54**. There is no line, so
there is no budget and nothing to clamp. Two shapes reach it, both deliberate:

- the order and the product do not name exactly one line (two lines of one product in one order) — the
  authorizer refuses to guess, pinned by `test_two_lines_of_one_product_in_one_order_are_left_alone_rather_than_guessed_at`;
- the return names only a product and no order at all —
  `test_a_return_naming_only_a_product_reaches_into_no_order`.

**The question for the owner:** what does a return with no order line mean? If it is goods arriving without
an order (a supplier, a walk-in, a stranger's parcel), today's behaviour is right and capping it would break
a working path. If it is an operator mis-typing an RMA, it is an unbounded way to inflate the shelf. **Not
changed**, per the rule that a currently-correct path is not altered on a guess. Capping the second shape at
the order's unspent total for that product is implementable; it would flip a pinned test and needs the
owner's answer first.

### 6.3 The order edit writer re-takes stock for a channel order that never took any — an over-deduct, out of this criterion

Both status writers skip a channel order entirely (`ChannelOrderMarker::holdsNoStock`,
`OrderManager.php:128` and `:131`, `OrderRepository.php:666` and `:669`) because its goods are at the provider and it took
nothing from `products.current_stock`. The edit writer's **re-take** loop
(`app/Traits/OrderEditManager.php:276`) has no such check and runs for every surviving line. A channel order
is materialised with `order_type` defaulting to `'default_type'`
(`ChannelOrderMaterializer.php:172`), which `OrderEditService::checkIsOrderEditable` (`:482`) admits while
the status is pending or confirmed — so an edit of one **drains the platform shelf by the line's quantity**.

The give-back direction is already safe: a channel line carries `is_stock_decreased = 0`
(`ChannelOrderMaterializer.php:232`), so the claim guard returns 0 and nothing is put back. The exposure is
one-directional — the shelf **loses** units, which cannot oversell. **Not fixed here:** it is an over-deduct,
not a restock above what was sold, so it is outside axis-m 654; the marker it needs lives in
`app/Services/SalesChannels/**`, which this track must not edit; and channel-order semantics belong to the
track that owns them. It deserves its own criterion.

### 6.4 The edit screen's *displayed* available stock adds the whole line back

`OrderEditService.php:96`, `:156`, `:712` do `$currentStock += $details['qty'] ?? 1` with **no claim check at
all**; the seller-app copy (`RestAPI/v3/seller/OrderController.php:347-349`) at least checks
`is_stock_decreased == 1`. After a partial return — or after a line was restored — the figure the operator is
offered as the maximum they may type is larger than the shelf holds. The shelf itself cannot go negative (the
re-take is conditional and floors at zero), so this cannot restock above what was sold; it is an
**availability** overstatement, which belongs with the oversell criteria (C-16), not here. **Recorded, not
changed** — no test pins the correct figure, and what the maximum typeable quantity *should* be on that screen
is a product decision.

### 6.5 The two status writers are still two near-identical copies

`OrderManager::getStockUpdateOnOrderStatusChange` and `OrderRepository::updateStockOnOrderStatusChange` are
roughly sixty duplicated lines each, and the mutation round proves they diverge in coverage (breaking only the
panel copy turned **5** tests red, not the 14 the shared budget turns). The quantity question is now
centralised, which was the root cause; the surrounding sixty lines are not. Consolidating them is a safe,
separate refactor with no behavioural need, and was not attempted inside a money-adjacent criterion fix.

### 6.6 The known limitation that remains, already documented and in the safe direction

`RestockBudget` reads `return_shipments`, a record over all time rather than of the current cycle. Once a line
has been re-opened, the units its returns put back have left the shelf again and nothing records that, so a
second trip round sell → return → re-open → return restores nothing where it should restore three. The shelf
**understates** what it holds, which cannot oversell. Pinned, with the reasoning, by
`test_going_round_the_cycle_again_puts_nothing_back_but_never_drains_the_shelf`. Closing it needs the current
cycle recorded — a movement the status writers do not yet write.

---

## 7. Criterion 654: what is closed and what is not

**Closed.** All four PR-24 pins are fixed and each has teeth (section 2). The fifth path of the same family —
the order edit writer after a partial return — is fixed at the root, with three tests and teeth (section 3),
and the fourth writer now reads the budget under the same lock as the other three (section 4). One answer,
four askers, no new clamp.

**Not closed, and each with its reason.** The no-line return (6.2) waits on an owner decision. The edit
writer's channel-order re-take (6.3) and the edit screen's displayed availability (6.4) are a different
criterion (over-deduct and over-availability, not over-restock). The lock of section 4 has no race test yet
(the named next step). The all-time-versus-this-cycle limitation (6.6) needs a movement the status writers do
not write.

`docs/gcc/tracker/axis-m-security-tests-and-deployment.md:654` is not edited — tracker files are not this
track's to write. Its recorded evidence, *"Four defects pinned as 'currently' in PR 24, not fixed"*, is
**stale**: the four were fixed before `30a2283e`, and the reason the criterion stayed open was the fifth
writer, which this handoff closes.
