# Understanding: `_commission-withdraw-panel.blade.php`

**Path:** `resources/themes/default/auction/web-views/user-profile/partials/_product-details/_commission-withdraw-panel.blade.php`

## Purpose
A partial rendered inside the auction product details view on the **customer's user profile**. After an auction ends and delivery is complete, it shows one of two financial flows depending on how the buyer paid:

1. **Cash on Delivery (COD)** — the auction owner collected cash, so the **owner owes commission to the admin**.
2. **Wallet / Digital / Offline** — the platform collected the buyer's payment, so the **admin owes earnings to the auction owner** (vendor/seller side of customer).

The single root branch is `$claimPaymentMethod === 'cash_on_delivery'`.

---

## Branch 1 — COD (Owner pays commission to Admin)

Uses `$codCommissionTransaction` (aliased to `$codTx`) and reads `$codTx?->payment_status`.

Three sub-states driven by `\Modules\Auction\app\Enums\PaymentStatus`:

| State | UI |
|---|---|
| `PAID` | Green success card — "Commission_Paid", shows `$deliveredBreakdown->commissionAmount`, success message. |
| `PENDING` | Yellow/warning card — "Payment_Under_Verification" (offline payment submitted, awaiting admin). Shows `$auctionProduct?->admin_commission`. |
| _none / other_ | Red call-to-action card — "Amount to pay Admin", shows `$auctionProduct?->admin_commission`, **Pay Now** button opens modal `#auction-cod-commission-modal`. |

---

## Branch 2 — Non-COD (Admin pays earnings to Owner)

Uses `$customerWithdrawRequest` (aliased to `$wr`). Drives off `\Modules\Auction\app\Enums\WithdrawStatus`.

Four sub-states:

| State | UI |
|---|---|
| `APPROVED` | Single card: optional **Approve_Note** (green badge with `$wr->transaction_note`) + "Payment_Received_from_Admin" amount (`$wr->amount`) + divider + `Withdraw_info` block (via `_withdraw-info-fields` partial, `statusClass = text-success`). |
| `PENDING` | "Amount Pay By Admin" with `$deliveredBreakdown->vendorReceivable` + divider + `Withdraw_info` header with an **edit pencil** button that opens offcanvas `#auction-customer-withdraw-offcanvas`. Fields partial included with empty `statusClass`. |
| `REJECTED` | Like PENDING but shows a red **Denied_Note** badge at top (with `$wr->transaction_note`), and fields partial uses `statusClass = text-danger`. Edit pencil still present so the customer can resubmit. |
| _no request yet_ | Green "Amount_pay_by_Admin" + `$deliveredBreakdown->vendorReceivable` + primary **Request_Withdraw** button opening the same offcanvas. |

---

## Data Contract (variables expected from the parent view)

- `$claimPaymentMethod` — string, e.g. `'cash_on_delivery'`.
- `$codCommissionTransaction` — nullable model with `payment_status` (PaymentStatus enum).
- `$customerWithdrawRequest` — nullable withdraw request model with `status` (WithdrawStatus enum), `amount`, `transaction_note`, and the fields consumed by the included `_withdraw-info-fields` partial.
- `$auctionProduct` — auction product model, used for `admin_commission`.
- `$deliveredBreakdown` — value object with `commissionAmount` and `vendorReceivable`.

## External Pieces It Couples To

- **Modal:** `#auction-cod-commission-modal` — opens the offline/COD commission payment flow.
- **Offcanvas:** `#auction-customer-withdraw-offcanvas` — the customer's withdraw-request form (create / edit on rejected).
- **Partial:** `auction.web-views.user-profile.partials._product-details._withdraw-info-fields` — renders bank/account details, parametrized by `wr` and `statusClass`.
- **Enums:** `Modules\Auction\app\Enums\PaymentStatus`, `Modules\Auction\app\Enums\WithdrawStatus`.
- **Helpers:** `webCurrencyConverter()`, `translate()`.

## Key Observations / Notes

- Uses Bootstrap **5** attributes (`data-bs-toggle`, `data-bs-target`) — consistent with the default theme (vendor theme would use BS4).
- The amount displayed in PENDING/REJECTED non-COD states is `$deliveredBreakdown->vendorReceivable` (the computed payable), but in APPROVED it switches to `$wr->amount` (the actual paid amount, which may differ if admin adjusted).
- In COD PENDING, the amount uses `$auctionProduct?->admin_commission` rather than `$deliveredBreakdown->commissionAmount`. Subtle inconsistency worth flagging if the two ever diverge.
- Translation keys mix snake_case (`Commission_Paid`, `Amount_pay_by_Admin`) with sentence form (`Amount to pay Admin`, `Amount Pay By Admin`) — keys are inconsistent across branches.
- `text-danger` is used for the "Amount Pay By Admin" header in the PENDING (non-COD) branch — visually it reads as an alert even though pending is a neutral waiting state; may be intentional to signal "still owed".
- The edit pencil button is shown in both PENDING and REJECTED non-COD states, allowing the customer to revise their withdraw details before / after admin decision.
