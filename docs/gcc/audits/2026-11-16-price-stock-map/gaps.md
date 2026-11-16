# Gaps the critic found in the map (29)

Each entry names an area the sweep did not cover or covered thinly, where it is, and why it matters. Paths are relative to the repository root.

1. **Scheduled bulk price writer is missing from the map (seller:run-stuck-bulk-jobs -> RunSellerBulkJob -> BulkPriceOperation)**  
   Where: app/Services/Marketplace/Bulk/BulkPriceOperation.php:60-61 (reads unit_price), :89 (before snapshot), :98-101 (forceFill unit_price, discount, discount_type then save)  
   Why it matters: Bulk price changes write the single base unit_price (USD) and a product-wide discount. No storefront or country row is touched, so a bulk change moves every storefront at once or none. The map has BulkStockOperation:77 but not this price writer.

2. **Scheduled seller automation writes a product-wide discount (seller:run-automation, every 15 min)**  
   Where: app/Services/SellerAutomation/Actions/SetDiscountAction.php:63 (floor check on base unit_price), :98-99 (before/after), :125-127 (save discount and discount_type)  
   Why it matters: The rule's min_price_after_discount floor is computed from base unit_price. A country price could be pushed below the seller's floor on that storefront and the rule would not notice.

3. **Scheduled automation triggers and listing actions use product-level stock and global status**  
   Where: app/Services/SellerAutomation/Triggers/LowStockTrigger.php:45-47; .../Triggers/OutOfStockTrigger.php:45; .../Triggers/RestockedTrigger.php:60; .../Triggers/StaleStockTrigger.php:49-53; .../Triggers/SelectsSimpleProducts.php:34-46; .../Actions/HideListingAction.php:78 and .../Actions/PublishListingAction.php:90 (global status write)  
   Why it matters: Publish and hide decisions use one global current_stock and one global status column. A storefront with its own stock or its own listing state is invisible to these rules.

4. **Hourly seller insights read base unit_price and current_stock (seller:refresh-insights)**  
   Where: app/Services/SellerIntelligence/Producers/InventoryRiskProducer.php:51-52 and :71 (revenue at risk = sold x unit_price); .../Producers/PricingRiskProducer.php:133-143 (unit_price < purchase_price); .../Producers/StaleInventoryProducer.php:57  
   Why it matters: Under-cost and revenue-at-risk warnings use base price only. A per-country price below cost would not be flagged, and a per-country stock figure would not appear.

5. **Price-change history observer fires on every scheduled price write**  
   Where: app/Observers/ProductPriceObserver.php:39-46 and :67-71; app/Services/Marketplace/PriceChangeRecorder.php:137-154 and :173-175  
   Why it matters: Price history records base unit_price, discount and discount_type only. Per-country price changes would be unrecorded or attributed to the base price, so the audit trail cannot show what a storefront charged.

6. **Scheduled myfatoorah:reconcile (every 10 min when enabled) verifies gateway amount and currency**  
   Where: app/Services/MyFatoorah/PaymentVerificationService.php:198-221 (captured currency must equal attempt expected_currency; amount vs expected_amount) and :326-345 (owed payment_amount vs expected_amount); app/Console/Commands/MyFatoorahReconcile.php:64  
   Why it matters: This is the only scheduled check that a gateway payment matches the order's amount and currency. If the storefront currency is not written into the attempt snapshot, the payment is sent to a person as paid in X but the order is in Y. The per-storefront currency has to be in the snapshot before this check can pass.

7. **Scheduled abandoned-cart email renders in default currency from a console run**  
   Where: app/Console/Commands/SendAbandonedCartReminders.php:110 (synchronous Mail::to()->send, run by cart:remind-abandoned every 30 min and stage 2 daily at 10:00); app/Mail/AbandonedCartReminderMail.php:30-36; resources/views/email-templates/abandoned-cart-reminder.blade.php:78 and :85 (webCurrencyConverter); app/Services/Retention/AbandonedCartService.php:146 and :156 (cart_value from base Cart.price minus discount), :51   
   Why it matters: A console run has no storefront session, so webCurrencyConverter falls back to the system default currency. A buyer who shopped in another storefront receives the reminder in the wrong currency. cart_value and the minimum-value setting compare base amounts only.

8. **Queued order-placed email totals are rebuilt in the view from base lines and shown in default currency**  
   Where: app/Listeners/OrderPlacedListener.php:35-51 (ShouldQueue, QueuedMailDelivery, sendingMail); app/Mail/OrderPlaced.php:31-34; resources/views/email-templates/order-placed-v2.blade.php:126-170 (subtotal, total, shipping and discount recomputed inline from base detail lines), :162, :164, :189-232 (webCurrencyConverter on every amount)  
   Why it matters: Queue workers have no session, so every amount renders in the default currency. The view rebuilds totals from line prices instead of the stored order header, so the confirmation can differ from what the buyer paid in their storefront currency. Any Mailable sent through app/Jobs/SendEmailJob.php inherits the same converter behaviour.

9. **Customer invoice PDF and admin, vendor and POS invoice views convert base amounts to default currency**  
   Where: app/Http/Controllers/Web/UserProfileController.php:935-955 (generate_invoice, mPDF of VIEW_FILE_NAMES['order_invoice']); resources/views/admin-views/order/invoice.blade.php:580, :659-669, :773-774, :816-890, :905-1023; resources/views/vendor-views/order/invoice.blade.php (same lines); resources/views/admin-views/pos/order/invoice.blade.php:86-159  
   Why it matters: The invoice a customer downloads applies usdToDefaultCurrency at the session rate and labels with getCurrencyCode (system default). It never shows the currency the buyer paid. Only bring_change_amount_currency (invoice.blade.php:802-807) carries a stored currency.

10. **Order currency is not persisted**  
   Where: database/migrations/2024_12_31_170955_bring_change_amount_column_in_orders_table.php (only currency column touching orders); app/Models/Order.php (only bring_change_amount_currency, lines 32, 95, 153); app/Services/MyFatoorah/PaymentVerificationService.php:204-221 (currency exists only on the MyFatoorah attempt)  
   Why it matters: Orders hold base (USD) amounts only. Every email, invoice, export, COD cash figure and refund re-converts at render time with the current rate. Per-country orders need orders.currency_code plus an exchange-rate snapshot.

11. **API v2 seller product list returns raw base rows; v2 has no customer price endpoint**  
   Where: app/Http/Controllers/RestAPI/v2/seller/ProductController.php:23-36 (list returns Product rows raw: unit_price, discount, discount_type, current_stock, variation JSON); compare :56 and :362 (Helpers::product_data_formatting); routes/rest_api/v2/api.php (v2 prefixes are seller and delivery-man only)  
   Why it matters: The list bypasses the serializer the map covers. Seller apps see one base price and one product-wide stock figure, and variation prices are not keyed by country. The customer storefront reads prices only through v1.

12. **API v2 seller product writes unit_price and discount; low-stock read**  
   Where: app/Http/Controllers/RestAPI/v2/seller/ProductController.php:52 (current_stock below stock limit), :146-187 and :386-425 (unit_price and discount checked against base), :304, :308-309 and :556, :560-561 (unit_price and discount written through Convert::usd)  
   Why it matters: The map lists only the current_stock writes at :311 and :563. The price writes have no country or storefront parameter, so this legacy path can overwrite the base price that any per-country design depends on.

13. **API v2 seller orders return raw amounts, feed COD cash and loyalty, and write deliveryman_charge without currency**  
   Where: app/Http/Controllers/RestAPI/v2/seller/OrderController.php:44-50 (list returns raw Order rows), :65-70 (details returns raw OrderDetail rows), :239 (COD cash = order_amount), :269 (loyalty from Convert::default(order_amount - shipping_cost)), :108-148 (amount_date_update writes deliveryman_charge from the request with no conversion)  
   Why it matters: Amounts are base with no currency field, so sellers and loyalty points see figures in an unknown currency. A seller can write deliveryman_charge with no currency or conversion.

14. **API v2 delivery-man cash to collect**  
   Where: app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:178, :195, :418 (order_amount + edit_due_amount and cash in hand)  
   Why it matters: The courier's cash-to-collect figure is base order_amount. The map covers only the status write at :228. A COD order paid in another storefront currency would be collected at the wrong amount.

15. **API v2 seller refunds computed from base line amounts**  
   Where: app/Http/Controllers/RestAPI/v2/seller/RefundController.php:77-91 (refund_amount from line price, tax, discount and coupon share)  
   Why it matters: Refund amounts carry no currency or rate snapshot. A per-country order refunded later at a different rate would not return what the buyer paid.

16. **JSON-LD offer currency label is the system default code while the price is a USD base value**  
   Where: app/View/Composers/ProductSeoSupplementComposer.php:105-106 (map covers :105 only; :106 currency = getCurrencyCode() or 'USD'); app/Services/Seo/StructuredDataBuilder.php:142-146 (priceCurrency falls back to USD when absent)  
   Why it matters: products.unit_price is stored in USD: ProductService.php:614 converts the entered price with currencyConverter, and webCurrencyConverter treats it as USD. getCurrencyCode() default returns the system default code. In a multi-currency install the schema would state a USD number under the default currency code. Verify on a multi-currency store before relying on it.

17. **Sitemaps and feeds: no prices today, and no product feed exists**  
   Where: sitemap.xml; public/sitemap.xml; app/Http/Controllers/Admin/Settings/SiteMapController.php; app/Services/Seo/SitemapHreflangGenerator.php  
   Why it matters: Verified zero price, stock or currency tokens in both sitemaps. No merchant, Google Shopping, XML or CSV product feed exists in routes or controllers, so nothing leaks prices today. A future feed or per-country sitemap must take prices from a per-storefront source, and hreflang must emit alternates per storefront.

18. **Product Excel exports carry one base price and one product-wide stock**  
   Where: resources/views/file-exports/product-list.blade.php:80-81 (unit_price and discount via usdToDefaultCurrency, ProductListExport); resources/views/file-exports/product-report-export.blade.php:41-51 (unit price, sold amount, current_stock); resources/views/file-exports/product-stock-report.blade.php:35-42 (current_stock; built by app/Http/Controllers/Admin/ProductStockReportController.php:63)  
   Why it matters: The export shows one price and one stock per product, converted to default currency. Per-country values would be missing or replaced by the base value, so a seller cannot audit what each storefront charges or holds.

19. **Restock export prints raw base price with no currency**  
   Where: resources/views/file-exports/restock-product-list.blade.php:48 (raw unit_price, no symbol, no conversion); app/Exports/RestockProductListExport.php:21-34  
   Why it matters: This is the only export that prints a price with no currency at all. Restock demand per country would be judged on raw USD base units.

20. **Order exports recompute totals in the view, in default currency**  
   Where: resources/views/file-exports/order-export.blade.php:73-106 (OrderManager::getOrderTotalPriceSummary; extra discount at :76-82; total at :82; all default currency); resources/views/file-exports/order-report-export.blade.php:44-53 (order_amount + edit_due_amount - edit_return_amount computed in view); resources/views/file-exports/customer-order-list.blade.php:46; resources/views/file-exports/vendor-order-list.blade.php  
   Why it matters: Export totals are rebuilt in the view, so they can differ from the order header and the invoice. All of them use default currency because the order's own currency is not stored.

21. **Coupon export shows base minimum and discount in default currency**  
   Where: resources/views/file-exports/coupon-list.blade.php:41-44 (min_purchase, max_discount and flat discount via usdToDefaultCurrency)  
   Why it matters: Coupon minimums are stored in base. A per-country coupon threshold cannot be exported or audited correctly.

22. **Transaction and earnings PDF reports re-convert base totals to default currency**  
   Where: resources/views/admin-views/transaction/order_wise_pdf.blade.php (14 conversion calls); resources/views/admin-views/transaction/ and resources/views/vendor-views/transaction/ (*_pdf.blade.php); resources/views/admin-views/report/admin-earning-duration-wise-pdf.blade.php (9 PDF templates in total use usdToDefaultCurrency or webCurrencyConverter)  
   Why it matters: Finance PDFs cannot show per-storefront currency. They convert base totals at render time with the current rate, so totals drift from what buyers paid.

23. **Normal product save writes base unit_price and flat discount via currencyConverter**  
   Where: app/Services/ProductService.php:614-620 (add), :693-699 (update), :852-855 (bulk import); the map covers only the current_stock lines at :622, :701 and :857  
   Why it matters: This is the seller path that converts the entered price to USD base at save time with the current rate. A per-country price input has to stay separate from this write, or saving the product will overwrite it.

24. **Flash deal and deal-of-the-day discounts are stored in mixed units**  
   Where: app/Services/FlashDealService.php:52-53 (discount and discount_type stored from request without conversion; confirm the target row is the flash-deal product); app/Services/DealOfTheDayService.php:15 and :28 (amount discounts converted with usdToDefaultCurrency and persisted; the map labels :15 as a read)  
   Why it matters: Deal-of-the-day amounts are saved in default-currency units at the saving admin's session rate, while product discounts are USD base. The map's read label hides that this is a persisted write. Mixed units would give a different discount per storefront.

25. **Clearance setup discount stored raw, unlike product flat discounts**  
   Where: app/Services/ClearanceSaleService.php:30-31 (discount_type and discount_amount stored from request, no conversion); compare app/Services/ProductService.php:619 (flat discount converted to base)  
   Why it matters: Product flat discounts are converted to USD base, but clearance amounts are not. The unit of a clearance amount is ambiguous for per-country prices. Verify against Product::clearanceSale (map Product.php:265) before relying on it.

26. **POS order-detail discount and variation written from request values**  
   Where: app/Services/OrderDetailsService.php:34-39 (discount = discount x quantity, discount_type discount_on_product, variation JSON); callers app/Http/Controllers/Admin/POS/POSOrderController.php and app/Http/Controllers/Vendor/POS/POSOrderController.php  
   Why it matters: POS line discounts are written with no currency field, so POS sales in a per-country storefront cannot carry their currency.

27. **AI pricing suggestions carry base unit_price and current_stock**  
   Where: Modules/AI/app/Response/ProductResponse.php:87-109; Modules/AI/app/PromptTemplates/PricingTemplate.php:29-31  
   Why it matters: The model is asked for and returns base unit_price and current_stock. The write path into products was not traced. Any accepted suggestion becomes the base price for every storefront.

28. **Own-warehouse stock switch treats global products.current_stock as owner stock**  
   Where: app/Console/Commands/SalesChannelsStockUsePlatformForOwnWarehouseCommand.php:19 and :45 (dry run unless --apply)  
   Why it matters: Makes the global current_stock the authority for owner stock. While this command is in use, per-country stock for the own warehouse cannot be expressed.

29. **Not traced line by line: scheduled settlement, payout and report money paths**  
   Where: app/Console/Commands/RunVendorSettlements.php:87 (scheduled marketplace:settle --release at 02:00); app/Services/Marketplace/SettlementEngine.php; app/Services/Marketplace/PayoutService.php; app/Services/Reports/SellerReportService.php; app/Services/Admin/Reports/TransectionReportPdfService.php; Modules/TaxModule/app/Exports/  
   Why it matters: Checked by grep only, not read in full. Settlements and payouts are scheduled and assumed to be in USD base, so per-country settlement and tax exports need their own review. A grep of Modules/Auction found no price or stock writes.

