# D1-07: the rows of `order_taxes` add up to the tax of the order

Master tracker requirement D1-07 (tax by market), the order-record criterion. Execution and test on an isolated branch; **what a finished order recorded is not touched, and a change of what a report adds up awaits the finance review before it is merged.**

## The defect

At checkout an order writes one row in `order_taxes` for each tax rate of each line (`tax_on = basic`) and one for each tax rate of the shipping of each line (`tax_on` = the name of the shipping tax group). The amount of a row is the tax of its line, or the shipping tax of its line, shared out among that line's rates.

Both places shared it out by the sum of the rates of **every** line of the seller's cart instead of the rates of the line itself:

| Rows | Divisor used | What a cart of two lines kept |
|---|---|---|
| the tax of a line | the rates of all the lines of the cart added together | each row held half of its line's tax |
| the shipping tax of a line | the rates of all the lines, applied to the shipping tax of the last line only | the rows held one third of the shipping tax in the case of the tests, and less as the lines grow in number |

The order itself was right: `orders.total_tax_amount`, `order_details.tax`, the amount to pay and the invoice never used the rows. A report that adds `order_taxes` up (the admin, vendor and API tax reports read the rows) under-reported the tax of every order of two lines or more.

## The change

`OrderManager::addOrderDetailsData` divides the tax of a line by the sum of the rates of that line, and `OrderManager::generateOrder` writes the shipping rows of each line from that line's own shipping tax and its own rates. Nothing else moves: the order, its lines, the amount, the stock and the invoice are the same numbers as before (the tests pin them), the rows keep their names, types, `before_tax_amount`, `after_tax_amount`, `quantity` and `system_tax_setup_id`.

For a cart of two lines of 100 and 50 under a VAT of 15 and a fee of 5 the rows were 7.5, 2.5, 3.75 and 1.25 (15 in all, the order's tax is 30); they are now 15, 5, 7.5 and 2.5 (30). With a shipping tax of 15 on a shipping cost of 30 split over the same two lines the shipping rows were 0.75 and 0.75 (1.5; the order carries 4.5); they are now 3 and 1.5 (4.5).

The edit of an order in a panel (`updateEditOrderTaxAmount`) and the two POS screens were read and are unchanged: the rows of an edit add up to the tax of the order, and the POS apportions by the rates of the product it is writing.

## Orders already placed

**Nothing rewrites them.** An order placed before this change keeps the rows it was placed with, and a report over a period that spans it adds those rows up as they are. `php artisan orders:tax-rows-reconcile` lists them:

```
php artisan orders:tax-rows-reconcile --from=2026-01-01 --to=2026-12-31 --list=50
php artisan orders:tax-rows-reconcile --json
```

For every order in the period it compares what its rows add up to with `orders.total_tax_amount`, and classes the difference: **short** (the rows add up to less than the tax the order carries: the defect above), **over**, or **without rows** (a tax and no row). It names at most `--list` orders of each class and counts them all, treats a difference of up to `--tolerance` (0.005) as rounding, and exits 1 while any order differs. It reads only: every statement it runs is a select, a test proves it, and it prints no customer data, only order ids and amounts.

Whether to restate the rows of the orders already placed, to leave them and note the period in the reports, or to correct the reports to read the order's own tax is **the finance review's decision** and is not made here. The command is how that review sees the size of the difference.

## What this does not do

- It does not restate, repair or delete a row of a finished order, and it does not change `orders.total_tax_amount`, `order_details.tax` or any amount a customer paid.
- It does not change which rates an order is taxed with (that is D1-07 step 2, the storefront's own setup) or how a tax-included price is recorded.
- Rows written by the old code for orders still open in a panel are rewritten by the edit of that order, as they always were.

## Tests

`OrderTaxRecordedAtPlacementTest` (12): the earlier characterization, with the one case that pinned the defect now stating the corrected rows on purpose, and two new cases (lines that do not share their rates; the shipping tax of each line), each of which fails on the code before the change. `OrderTaxRowsReconcileTest` (17): the classes, the output, the exit code, the rounding tolerance, the period, the list limit, JSON, refused options, a database without the table, that every statement is a select and no row changes, and an order placed through the checkout that reconciles next to one that kept the old rows, which is listed and left alone.

## Open for the finance review

1. Whether the orders already placed keep their rows, are restated, or the reports are read from the order's own tax.
2. Whether a report of the tax of a period should say from which date its rows are complete.
3. Whether the shipping tax of a line is the tax of its line of the order (as the rows now say) or of the order as a whole.
