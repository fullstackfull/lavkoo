# Admin routes without a module permission (2026-11-16)

Read-only static listing, no route was executed for this table. It was made from the route table of the checkout at `main` (`c5c04b7b`) with `Route::getRoutes()`: every route of the `admin` prefix whose resolved middleware holds `admin` but no `module:*` entry. 80 routes. The `module:` middleware is what the role editor of the admin panel switches on and off per employee; a route without it answers every signed-in employee, including one whose role holds no module at all (an employee with an empty role was redirected to the dashboard on `/admin/system-setup/addon/*` and let through on `/admin/addon/*`: that is how the audit found the group).

**What to do with it.** Most of the first three groups are fine as they are. The AI, VAT and tax groups carry business settings and tax data, and the product pickers list the whole catalogue; gating them changes what employees without those modules see, so it is a product decision (the matching module for each: AI settings `3rd_party_setup`, VAT and tax `business_settings`, tax reports `reports`). The legacy add-on screens are gated by draft PR #63. The profile route is closed by draft PR #57.

## Open to every admin on purpose (no data of another role) (18)

| Method | Path |
|---|---|
| GET | `admin/meta-app` |
| POST | `admin/meta-app/store` |
| GET | `admin/v2/sidebar-pins` |
| POST | `admin/v2/sidebar-pins/toggle` |
| POST | `admin/v2/sidebar-pins/replace` |
| GET | `admin/component` |
| GET | `admin/component-snippets` |
| GET | `admin/advanced-search` |
| POST | `admin/advanced-search-recent` |
| GET | `admin/v2/advanced-search` |
| GET | `admin/dashboard` |
| POST | `admin/dashboard/order-status` |
| GET | `admin/dashboard/earning-statistics` |
| GET | `admin/dashboard/order-statistics` |
| GET | `admin/dashboard/real-time-activities` |
| GET | `admin/settings` |
| GET | `admin/design-system` |
| GET | `admin/logout` |

## Own profile (by id: draft PR #57 makes it the signed-in admin only) (3)

| Method | Path |
|---|---|
| GET | `admin/profile/update/{id}` |
| POST | `admin/profile/update/{id}` |
| PATCH | `admin/profile/update/{id}` |

## Product pickers and product search (read-only lists, any employee) (5)

| Method | Path |
|---|---|
| GET | `admin/products/multiple-product-details` |
| GET | `admin/products/search` |
| GET | `admin/products/search-all-product` |
| GET | `admin/products/picker-options` |
| GET | `admin/products/picker-labels` |

## Chat with customers, sellers and delivery men (3)

| Method | Path |
|---|---|
| GET | `admin/messages/index/{type}` |
| GET | `admin/messages/message` |
| POST | `admin/messages/message` |

## Legacy add-on screens (now behind `themes_and_addons`, draft PR #63) (5)

| Method | Path |
|---|---|
| GET | `admin/addon` |
| POST | `admin/addon/publish` |
| POST | `admin/addon/activation` |
| POST | `admin/addon/upload` |
| POST | `admin/addon/delete` |

## Priority setup (3)

| Method | Path |
|---|---|
| GET | `admin/business-settings/priority-setup` |
| POST | `admin/business-settings/priority-setup` |
| POST | `admin/business-settings/priority-setup/update-by-type` |

## AI auto-fill and AI settings (module `3rd_party_setup` is not asked) (27)

| Method | Path |
|---|---|
| GET | `admin/product/title-auto-fill` |
| GET | `admin/product/description-auto-fill` |
| GET | `admin/product/general-setup-auto-fill` |
| GET | `admin/product/price-others-auto-fill` |
| GET | `admin/product/seo-section-auto-fill` |
| GET | `admin/product/variation-setup-auto-fill` |
| POST | `admin/product/analyze-image-auto-fill` |
| POST | `admin/product/generate-title-suggestions` |
| GET | `admin/auction/product/title-auto-fill` |
| GET | `admin/auction/product/description-auto-fill` |
| GET | `admin/auction/product/general-setup-auto-fill` |
| GET | `admin/auction/product/shipping-policy-auto-fill` |
| GET | `admin/auction/product/auction-info-auto-fill` |
| GET | `admin/auction/product/seo-section-auto-fill` |
| POST | `admin/auction/product/analyze-image-auto-fill` |
| POST | `admin/auction/product/generate-title-suggestions` |
| GET | `admin/blog/title-auto-fill` |
| GET | `admin/blog/description-auto-fill` |
| POST | `admin/blog/seo-section-auto-fill` |
| POST | `admin/blog/generate-title-suggestions` |
| POST | `admin/blog/analyze-image-auto-fill` |
| GET | `admin/third-party/ai-setting` |
| POST | `admin/third-party/ai-setting/store` |
| GET | `admin/third-party/ai-setting/vendors-usage-limits` |
| POST | `admin/third-party/ai-setting/vendors-usage-limits/update` |
| GET | `admin/third-party/ai-setting/customers-usage-limits` |
| POST | `admin/third-party/ai-setting/customers-usage-limits/update` |

## VAT and tax settings (8)

| Method | Path |
|---|---|
| GET | `admin/vat-tax/list` |
| POST | `admin/vat-tax/add-vat-tax-data` |
| GET|POST|PUT|PATCH|DELETE|OPTIONS | `admin/vat-tax/update-vat-tax-data` |
| POST | `admin/vat-tax/update-vat-tax-status` |
| GET | `admin/vat-tax/export-vat-tax` |
| GET | `admin/vat-tax/system-vat-tax` |
| POST | `admin/vat-tax/system-vat-tax` |
| POST | `admin/vat-tax/system-vat-tax-vendor-status` |

## Tax reports (8)

| Method | Path |
|---|---|
| GET | `admin/report/get-tax-report` |
| GET | `admin/report/get-tax-details` |
| GET | `admin/report/tax-details-report-export` |
| GET | `admin/report/admin-tax-report-export` |
| GET | `admin/report/vendor-wise-taxes` |
| GET | `admin/report/vendor-wise-taxes-export` |
| GET | `admin/report/vendor-tax-report` |
| GET | `admin/report/vendor-tax-export` |

Counts: Open to every admin on purpose 18, Own profile 3, Product pickers and product search 5, Chat with customers, sellers and deliver 3, Legacy add-on screens 5, Priority setup 3, AI auto-fill and AI settings 27, VAT and tax settings 8, Tax reports 8; total 80.
