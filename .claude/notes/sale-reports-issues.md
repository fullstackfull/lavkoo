## Logic issues / questions found in the flow

### Q1. Total Auctions Created

Total auction created by this user

### Q2. Total Auctions Sold

total auction sold with delivered, also that auction haven't the withdawal pending or due, also if has commision the then the commision paid.

### Q3. Total Product Sales Value

how much get paid from auction peyment

### Q4. Total VAT/Tax Collected

total vat for that auctions which are (  auction sold with delivered, also that auction haven't the withdawal pending or due, also if has commision the then the commision paid. )

### Q5. Total Shipping Fee Collected

total Shipping Fee for that auctions which are (  auction sold with delivered, also that auction haven't the withdawal pending or due, also if has commision the then the commision paid. )

### Q6. Gross sales mixes two different filter scopes

Follow vendor panel concept

---

## Implemented

Shared service: `Modules/Auction/app/Services/AuctionSalesReportService.php`. Applies to admin, vendor, customer pages + v1/v3 APIs (all call `getViewData()`).

**Query scopes**

- `baseQuery` — approved + owner filter + date filter on `end_time`. (`total_participants > 0` removed so Q1 counts every approved listing.)
- `soldBase` — `baseQuery` + `end_time < now` + `winner_user_id NOT NULL` + EXISTS paid AUCTION_PAYMENT.
- `settledSoldBase` — `soldBase` + `delivery_status = 'delivered'` + (non-admin: commission paid or none due, AND no `auctionWithdraw` row with status `pending`).

**Per card**

| # | Calculation |
|---|---|
| 1 Total Auctions Created | `baseQuery->count()` |
| 2 Total Auctions Sold | `settledSoldBase->count()` |
| 3 Total Product Sales Value | `settledSoldBase->sum('current_highest_bid_amount')` (product portion only — see fix below) |
| 4 Total VAT/Tax | `settledSoldBase->sum('total_tax_amount')` |
| 5 Total Shipping | `settledSoldBase->sum('shipping_fee')` |
| 6 Gross Sales | `#3 + #4 + #5` |

**Side effects**

- Trend chart now sums paid amounts (matching Q3), aggregated per-auction first to avoid multi-row inflation.
- Paginated detail table + Excel export keep `total_participants > 0`.
- `$total_auctions_unsold` keeps `total_participants > 0` ("had bidders but no winner").
- Admin owner skips the commission/withdraw gate (no commission/withdraw concept for admin-owned auctions).

**Cross-panel impact**

Admin in-house, admin vendor, vendor panel reports, and v1/v3 APIs all consume the same service — numbers will reflect the new stricter definition. Tell me if admin/vendor should keep the looser legacy numbers and I'll split into a customer-only path.

## Gross-doubling fix

First pass set Q3 = `SUM(auction_transactions.amount)`. That column stores `$totalPayable = bid + shipping + VAT` (see `AuctionClaimService.php:215`), so Q6 became `(bid + VAT + shipping) + VAT + shipping` — VAT and shipping counted twice.

Reverted Q3 (and the trend chart) to `current_highest_bid_amount` — the product-only portion of the payment. Gross now resolves to bid + VAT + shipping = total received, with no duplication. `AuctionTransaction` import removed since it's no longer referenced.
