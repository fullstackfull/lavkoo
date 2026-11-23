# E13 — المحاسبة: Odoo والتكاليف التشغيلية والعمولات والتسويات والضريبة والتقارير (Accounting: Odoo, Operational Costs, Commissions, Settlements, Tax, Reports)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo` عند `lavkoo@7f42777c` (الكود المدقَّق؛ الـ HEAD المحلي عند إعادة التحقق `lavkoo@7609dc2b` يضيف ثلاثة ملفات تدقيق Markdown فقط تحت `docs/gcc/audits/` ولا يغيّر أي كود). لم يُستعمل `lavkooapp` ولا `lavkooappseller` ولا `ksaapp98` في هذا الملحق.

## 1. النطاق وما تم فحصه

**ما قُرئ كاملاً (سطراً بسطر):** `config/odoo.php`، `routes/admin/odoo-accounting.php`، `docs/sales-channels/odoo-accounting.md`، وأحد عشر ملفاً من `app/Services/Accounting/Odoo/` (‏`OdooInstance`، `OdooSchema`، `OdooOperations`، `OdooPostingKey`، `OdooAccountMap`، `OdooMoney`، `OdooDraftBuilder`، `OdooPreflightRun`، `OdooPostingRun`، `OdooOutbox`، `OdooThreeWayReconciliation`)، والنموذجان `OdooAccountMapping` و`OdooPosting`، وهجرة `2026_11_15_100000_create_odoo_accounting_tables`، و`OdooReconcileCommand`، والمتحكّمان `OdooConnectionController` و`OdooOutboxController`؛ `config/operational_costs.php`، هجرة `2026_11_07_100000_create_operational_cost_tables`، `CostLedger`، `InventoryCostLayerService`، `ProfitabilityService`، `FxConverter`، `OperationalCostsSwitch`، `ConversionGaps`، `StockLocationResolver`؛ `VendorLedger`، `SettlementEngine`، `CommissionEngine`، `RunVendorSettlements`، والنماذج `VendorLedgerEntry`، `VendorSettlement`، `AdminWallet`، `SellerWallet`، `OrderItemCommission`، `CommissionRule`؛ هجرات `Modules/TaxModule` الست و`VatTaxManagement` و`VatTaxConfiguration` و`SystemTaxSetupService`.

**ما قُرئ جزئياً (مقاطع محدّدة مع بحث `rg`):** `OdooClient` (505 أسطر: مواضع بيانات الاعتماد فقط)، `OdooPreflight`، `OdooEntryDraft`، `Money`، `StorageCostService`، `ReturnDecisionService`، `SaleDocumentReader`، `PaymentFeeContributor`، `RuleCalculator`، `ContractService`، `PayoutService`، `RefundReversalService`، `Marketplace/ReconciliationService`، `ExchangeRateService`، `app/Utils/OrderManager.php` (المقاطع 156–414، 805–823، 1062–1205، 1396–1535، 2140–2170)، `app/Utils/Helpers.php` (632–652)، `app/Utils/module-helper.php`، `CalculateTaxService`، `AdminTaxReportController`، `DashboardService`، `DashboardController`، `ReportController`، `SellerReportService`، `AnalyticsReporting`، وعيّنة من قوالب `resources/views/file-exports/`.

**الأحجام:** `app/Services/Accounting` = 23 ملفاً (3,560 سطراً)؛ `app/Services/OperationalCosts` = 83 ملفاً (9,209 أسطر)؛ `app/Services/Marketplace` = 42 ملفاً (38 في الجذر بمجموع 7,782 سطراً)؛ `Modules/TaxModule` ≈ 6,310 سطر PHP (أُعيد قياسه في المراجعة)؛ `app/Exports` = 30 ملفاً؛ الاختبارات: `tests/Feature/Accounting` = 10، `tests/Unit/Accounting` = 4، `tests/Feature/OperationalCosts` = 34، `tests/Unit/OperationalCosts` = 13.

**أنماط البحث المستعملة (`rg` على `app` و`routes` و`config` و`database` و`Modules` و`tests`):** `OdooOutbox|OdooPostingRun|->enqueue\(`، `AdminWallet::|SellerWallet::|admin_wallets|seller_wallets|OrderTransaction::`، `CommissionEngine|VendorLedger`، `Schema::create\('(vendor_ledger_entries|commission_rules|order_item_commissions|vendor_settlements|vendor_payout_requests|vendor_bank_change_logs|purchase_orders|admin_wallets|seller_wallets|transactions|withdraw_requests)'`، `country_code`، `getTaxSystemType`، `CalculateTaxService|order_taxes`، `seller_sales_commission`، `isInCoolingPeriod`، `invoice_settings`، `zatca|fatoora|e-invoice|einvoice|tlv`، `AdminWalletHistory|SellerWalletHistory`، `costs:refresh|costs:accrue-storage|marketplace:settle|odoo:reconcile`.

**ما لم يُفحص:** المتحكّمان `OdooMappingController` و`OdooReconciliationController` وبقية ملفات `app/Services/Accounting/Odoo` الاثني عشر غير المذكورة أعلاه (‏`OdooXmlRpc`، `OdooAnswer`، `OdooUndoLink(s)`…)؛ نحو 60 ملفاً من `app/Services/OperationalCosts` (الفواتير، أوامر الشراء، الشحنات) قُرئت عناوينها فقط؛ عملاء القنوات (`ChannelHttp`، `ChannelOperations` عدا ثابتَين) — من اختصاص E12؛ محرّك العملة العام (`currency.php` عدا دالتين، `CurrencyRateSource`) — E02؛ مسار الدفع والاستلام من البوابات — E10؛ واجهات Blade للتقارير عدا عيّنة؛ بقية خدمات `Marketplace` غير المالية (Moderation، SLA، Warehouse…)؛ محتوى 61 ملف اختبار (أُحصيت ولم تُقرأ)؛ قاعدة بيانات الإنتاج وملف `.env` (غير متاحين).

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

يوجد في هذا المجال **أربعة أنظمة مالية متراكبة** بُنيت في أزمنة مختلفة ولا يجمعها دفتر واحد:

| # | النظام | الجداول | المصدر | الحالة |
|---|---|---|---|---|
| 1 | المحفظة الموروثة (6valley) | `admin_wallets`، `seller_wallets`، `order_transactions`، `transactions`، `withdraw_requests` | ملف التثبيت + هجرات تعديل | يعمل دائماً مع كل طلب |
| 2 | النواة المالية للـ Marketplace (Phase 3) | `commission_rules`، `order_item_commissions`، `vendor_ledger_entries`، `vendor_settlements`، `vendor_payout_requests`، `vendor_bank_change_logs` | **لا هجرة في المستودع** | يعمل إن وُجدت الجداول (`Schema::hasTable`) |
| 3 | التكاليف التشغيلية والربحية | `operational_cost_*` (8 جداول)، `inventory_cost_layers`، `inventory_cost_consumptions` | هجرات 2026-11 | مطفأ افتراضياً |
| 4 | Odoo | `odoo_account_mappings`، `odoo_posting_queue`، `odoo_reconciliations` | هجرة 2026_11_15 | مطفأ افتراضياً، ولا مُدرِج له |

### 2.1 Odoo

**ما الذي يُرحَّل.** نوع المصدر الوحيد هو قيد التكلفة التشغيلية: الثابت `SOURCE_COST_ENTRY = 'operational_cost_entry'` في `lavkoo:app/Services/Accounting/Odoo/OdooPostingKey.php:32`، والعامل يرفض أي صفّ نوعه غير ذلك (`lavkoo:app/Services/Accounting/Odoo/OdooPostingRun.php:104-110`). المخرَج قيد يومية متنوّع `move_type = 'entry'` بسطرين (مَدين على حساب المصروف ودائن على الحساب المقابل) يُنشأ **مسوّدة** (`lavkoo:app/Services/Accounting/Odoo/OdooEntryDraft.php:58-66`، `lavkoo:app/Services/Accounting/Odoo/OdooDraftBuilder.php:108-137`). وحالة الطابور `posted` تعني أن المسوّدة أُنشئت في Odoo (`lavkoo:app/Services/Accounting/Odoo/OdooPostingRun.php:187-191`)، لا أن القيد رُحّل نهائياً. لا تُرحَّل فواتير مبيعات ولا مدفوعات عملاء ولا مرتجعات مبيعات ولا تسويات بائعين ولا ضريبة مخرجات: البحث عن `source_type` في `app/Services/Accounting` و`app/Models/Odoo*.php` لا يُظهر إلا هذا الثابت. العكس والمرتجع يُعبَّر عنهما بقيد جديد معكوس الأعمدة لا بتعديل (`lavkoo:app/Services/Accounting/Odoo/OdooOperations.php:88-105` يُبقي `write_entry` و`unlink_entry` و`post_entry` خارج القائمة المسموحة). عقد `AccountingDestination` في وحدة القنوات ما زال مربوطاً بوجهة لا ترسل شيئاً (`lavkoo:app/Providers/SalesChannelsServiceProvider.php:47-48`).

**الحالات القابلة للترحيل.** `actual` و`reconciled` فقط (`lavkoo:app/Services/Accounting/Odoo/OdooDraftBuilder.php:60`)؛ التقدير والمفقود والملغى يُرفض بأكواد مسمّاة (`lavkoo:app/Services/Accounting/Odoo/OdooDraftBuilder.php:155-175`).

**نموذج الربط.** `odoo_account_mappings` يربط (`seller_id`، `odoo_company_id`، `fact_kind`، `fact_code`، `sales_channel`، `currency`) بحساب ويومية وضريبة ومركز تكلفة في Odoo، بمفتاح فريد على الستة (`lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:23-59`). `fact_kind` قيمتان في النموذج: `cost_component` و`counterpart` (`lavkoo:app/Models/OdooAccountMapping.php:31-46`). تعليق عمود `fact_kind` في الهجرة يذكر أنواع سطور قيد إضافية (`sale`، `refund`، `channel_fee`، `cost_of_goods`، `shipping_cost`، `payout`) لا ثوابت لها في النموذج، ولا ورود لها في `app/Services/Accounting` (بحث: `channel_fee|cost_of_goods|shipping_cost` بلا نتائج هناك)، فهي وعد تصميمي لا سلوك قائم (`lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:28`). لا حساب افتراضي: غياب الربط رفض (`lavkoo:app/Services/Accounting/Odoo/OdooAccountMap.php:82-98`)، والربط غير المعتمد لا يُستعمل (`lavkoo:app/Models/OdooAccountMapping.php:65-68`). **لا ربط للشركاء (partners):** `partner_id` مُعلَن حقلاً اختيارياً في `lavkoo:app/Services/Accounting/Odoo/OdooPreflight.php:62-63` لكن الباني لا يكتبه في أي سطر (`lavkoo:app/Services/Accounting/Odoo/OdooDraftBuilder.php:108-137`).

**بُعد الشركة (multi-company).** موجود **في البيانات** لا في الاتصال: عمود `odoo_company_id` على الجداول الثلاثة، والشركة تُقرأ من إعداد الاتصال `company_id` أو تُقبل إن كانت الوحيدة، ووجود عدة شركات بلا اختيار رفضٌ مسمّى `odoo_several_companies_and_none_chosen` (`lavkoo:app/Services/Accounting/Odoo/OdooPreflightRun.php:148-176`). لكن **الاتصال واحد**: كل متحكّم والأمر يأخذ أول صفّ `SalesChannelAccount` قناته `odoo` (`lavkoo:app/Http/Controllers/Admin/Accounting/OdooConnectionController.php:148-152`، `lavkoo:app/Http/Controllers/Admin/Accounting/OdooOutboxController.php:179-182`، `lavkoo:app/Console/Commands/OdooReconcileCommand.php:46`)، والتعليق ينصّ على الافتراض: «There is one: an accountant does not keep two sets of books». فالبنية اليوم = نسخة Odoo واحدة + شركة واحدة.

**المحفّزات.** لا شيء يُدرِج تلقائياً: `OdooOutbox::enqueue()` (`lavkoo:app/Services/Accounting/Odoo/OdooOutbox.php:49-72`) **ليس له أي مُنادٍ إنتاجي** — البحث `rg "enqueue\("` في `app` و`routes` و`Modules` يعيد تعريف الدالة فقط، والمنادون كلهم في `tests/`. التصريف (drain) يدوي من زرّ إداري (`lavkoo:routes/admin/odoo-accounting.php:48`، `lavkoo:app/Http/Controllers/Admin/Accounting/OdooOutboxController.php:71-99`)، و`odoo:reconcile` غير مُجدوَل (البحث عن `odoo` في `app/Console/ScheduleDefinition.php` بلا نتائج). النتيجة: الطابور فارغ دائماً ما لم يُكتب مُدرِج لاحقاً.

**مفاتيح منع التكرار.** ثلاث طبقات: مفتاح فريد `(source_type, source_id, source_version)` (`lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:128`)؛ مرجع `PKSA-COST-<id>-<version>` يُكتب في `ref` ويُبحث عنه قبل كل إنشاء (`lavkoo:app/Services/Accounting/Odoo/OdooPostingKey.php:61`، `lavkoo:app/Services/Accounting/Odoo/OdooPostingRun.php:145-160`)؛ نسخة مشتقّة من المبلغ والعملة والحالة وتاريخ الاعتماد (`lavkoo:app/Services/Accounting/Odoo/OdooPostingKey.php:71-81`). السياج `may_have_posted` يُكتب قبل خروج الطلب (`lavkoo:app/Services/Accounting/Odoo/OdooOutbox.php:113-142`) والنتيجة الملتبسة تنتظر إنساناً (`lavkoo:app/Services/Accounting/Odoo/OdooPostingRun.php:236-255`).

**المطابقة الثلاثية.** مستند المصدر (بنود الفواتير المعتمدة) ← الدفتر الداخلي ← Odoo (‏`debit − credit` على الحسابات المربوطة)، ولا يُغلق أي فرق آلياً (`lavkoo:app/Services/Accounting/Odoo/OdooThreeWayReconciliation.php:101-115`، `lavkoo:app/Services/Accounting/Odoo/OdooThreeWayReconciliation.php:137-145`، `lavkoo:app/Services/Accounting/Odoo/OdooThreeWayReconciliation.php:215-255`). العملة الافتراضية للأمر `SAR` (`lavkoo:app/Console/Commands/OdooReconcileCommand.php:27`).

**المفاتيح وقيمها الافتراضية في الكود.** أربعة مفاتيح بيئية كلها `false`: `ODOO_ENABLED`، `ODOO_EXTERNAL_WRITES_ENABLED`، `ODOO_DRAFT_CREATION_ENABLED`، `ODOO_FINAL_POSTING_ENABLED` (`lavkoo:config/odoo.php:28`، `lavkoo:config/odoo.php:32`، `lavkoo:config/odoo.php:36`، `lavkoo:config/odoo.php:42`). النموذج هنا «off / reads / draft / final» وليس «off / shadow / on»؛ وضع المحاكاة هو `dry_run = true` على صفّ الاتصال عند إنشائه مع `enabled = false` و`write_enabled = false` (`lavkoo:app/Http/Controllers/Admin/Accounting/OdooConnectionController.php:73-85`). الترحيل النهائي لا ينفّذه أي كود. الشاشات خلف `module:operational_costs` (`lavkoo:routes/admin/odoo-accounting.php:24-28`). القيم الفعلية في `.env` الإنتاج: UNKNOWN.

**تخزين بيانات الاعتماد.** سرّ واحد (`api_key`) في كتلة مشفّرة بـ `Crypt::encryptString` على صفّ الحساب (`lavkoo:app/Services/SalesChannels/Support/ChannelCredentialStore.php:33`، `lavkoo:app/Services/SalesChannels/Support/ChannelCredentialStore.php:130`)؛ الحقل للكتابة فقط (`lavkoo:app/Http/Controllers/Admin/Accounting/OdooConnectionController.php:96-106`)؛ العنوان وقاعدة البيانات واسم الدخول و`company_id` إعدادات غير سرّية في عمود `settings`. لا قيمة سرّية في `config/odoo.php`.

**ملاحظة توثيقية.** السطر الأول من `docs/sales-channels/odoo-accounting.md` ما زال يقول «الحالة: غير مبني» (`lavkoo:docs/sales-channels/odoo-accounting.md:3`) بينما الكود موجود منذ `lavkoo@c7a0cc8a` والأقسام اللاحقة من الملف نفسه تصف المبنيّ (`lavkoo:docs/sales-channels/odoo-accounting.md:75`).

### 2.2 التكاليف التشغيلية والربحية

**المفاتيح.** `OPERATIONAL_COSTS_ENABLED` و`OPERATIONAL_COSTS_SCHEDULE_ENABLED` كلاهما `false` افتراضياً، ومصدر أوامر الشراء `off` (`lavkoo:config/operational_costs.php:22-24`، `lavkoo:config/operational_costs.php:36`). الجدولة مشروطة بالاثنين معاً (`lavkoo:app/Services/OperationalCosts/Support/OperationalCostsSwitch.php:27-30`، `lavkoo:app/Console/ScheduleDefinition.php:118-121`).

**الدفتر.** `CostLedger` هو الكاتب الوحيد لإنشاء صفوف `operational_cost_entries` وتغيير حالتها المالية (`lavkoo:app/Services/OperationalCosts/CostLedger.php:235`، `lavkoo:app/Services/OperationalCosts/CostLedger.php:57-135`)، ويكتب `AllocationService` حقل `allocation_status` وحده (`lavkoo:app/Services/OperationalCosts/AllocationService.php:40`، `lavkoo:app/Services/OperationalCosts/AllocationService.php:65`): فرادة بـ `dedupe_key`، صفّ فعّال واحد لكل `slot_key`، الأقوى يُحيل الأضعف، المعتمد لا يُكتب فوقه بل يُحفظ الجديد «متنازعاً»، ولا حذف — العكس قيد جديد سالب يحمل `calc_inputs['reverses']` (`lavkoo:app/Services/OperationalCosts/CostLedger.php:36-49`، `lavkoo:app/Services/OperationalCosts/CostLedger.php:146-190`، `lavkoo:app/Services/OperationalCosts/CostLedger.php:192-282`). الأعمدة المجمَّدة بعد الاعتماد في `lavkoo:app/Models/OperationalCostEntry.php:36-40`.

**طبقات التكلفة (FIFO).** الطبقة مفتاحها (`seller_id`، `inventory_location_id`، `product_id`، `variant_key`) وتُستهلك الأقدم فالأقدم تحت قفل، والاستهلاك يُجمَّد في `inventory_cost_consumptions`، والنقص يُبلَّغ ولا يُسعَّر صفراً (`lavkoo:app/Services/OperationalCosts/InventoryCostLayerService.php:135-169`، `lavkoo:app/Services/OperationalCosts/InventoryCostLayerService.php:172-184`). التحويل بين موقعين ينقل الطبقات بتكلفتها (`lavkoo:app/Services/OperationalCosts/InventoryCostLayerService.php:248-309`). توقيت الاعتراف بتكلفة البضاعة: المتجر عند `delivered` والقنوات عند `shipped` (`lavkoo:config/operational_costs.php:48-52`).

**العملة.** عملة أساس **واحدة للنظام كله** من الإعداد: `OPERATIONAL_COSTS_BASE_CURRENCY` ثم `FULFILLMENT_CURRENCY` ثم `'SAR'` (`lavkoo:config/operational_costs.php:28`، `lavkoo:app/Services/OperationalCosts/Support/FxConverter.php:48-51`). الطبقة تُخزَّن دائماً بعملة الأساس مع حفظ العملة الأصلية والسعر، وغياب السعر استثناء لا تخمين (`lavkoo:app/Services/OperationalCosts/InventoryCostLayerService.php:61-74`، `lavkoo:app/Services/OperationalCosts/InventoryCostLayerService.php:92`). `FxConverter` يعيد `null` إذا لم يكن المتجر `multi_currency` أو غاب أحد السعرين (`lavkoo:app/Services/OperationalCosts/Support/FxConverter.php:32-40`).

**الربح بلا سعر صرف.** الإيراد غير القابل للتحويل يُجعل صفراً مع ملاحظة `revenue_not_convertible` وعلامة «غير موثوق» وفجوة مسجّلة (`lavkoo:app/Services/OperationalCosts/Profit/ProfitabilityService.php:119-130`)؛ والتكلفة غير القابلة للتحويل تُحسب «مفقودة» لا صفراً (`lavkoo:app/Services/OperationalCosts/Profit/ProfitabilityService.php:219-226`)؛ و`ConversionGaps` يحمل عدّادات العملات المتروكة (`lavkoo:app/Services/OperationalCosts/Profit/ConversionGaps.php:11-19`). كل النتائج بعملة الأساس الواحدة (`lavkoo:app/Services/OperationalCosts/Profit/ProfitabilityService.php:114`). التقدير لا يُستبدَل إلا بتسوية مُثبَتة `Reconciled` (`lavkoo:app/Services/OperationalCosts/Profit/ProfitabilityService.php:303-306`). أبعاد التجميع المتاحة: قناة، حساب، مزوّد، نمط تنفيذ، شهر، يوم، حالة — **لا متجر ولا دولة ولا كيان** (`lavkoo:app/Services/OperationalCosts/Profit/ProfitAggregator.php:8-16`).

**العقود والقواعد.** العقد يُبحث عنه بـ (`seller_id`، `provider_code`، تاريخ السريان)، ويُقيَّد اختيارياً بعملة، ويُختار الأحدث سريانًا ثم إصداراً (`lavkoo:app/Services/OperationalCosts/ContractService.php:220-234`)، وعقد بوابة الدفع مفتاحه رمز طريقة الدفع (`lavkoo:app/Services/OperationalCosts/Contributors/PaymentFeeContributor.php:48`). شروط القاعدة تدعم `cities` و`countries` و`payment_methods` (`lavkoo:app/Services/OperationalCosts/Calculation/RuleCalculator.php:85-87`) — أي أن الدولة شرط تسعير لا بُعد ملكية.

**التخزين والمرتجعات.** استحقاق التخزين يومي لكل موقع مزوّد، بعملة الأساس، ويوم بلا رقم مخزون موثوق = «مفقود» (`lavkoo:app/Services/OperationalCosts/StorageCostService.php:42-46`، `lavkoo:app/Services/OperationalCosts/StorageCostService.php:57`). قرار المرتجع (إعادة للرفّ بطبقة جديدة أو شطب) مرّة واحدة لكل حدث إرجاع تحت قفل (`lavkoo:app/Services/OperationalCosts/Returns/ReturnDecisionService.php:49-60`).

**موقع المخزون.** `StockLocationResolver` يأخذ **أول** موقع فعّال لكل رمز مزوّد (`lavkoo:app/Services/OperationalCosts/Support/StockLocationResolver.php:63-66`) — افتراض موقع واحد لكل مزوّد.

**قراءة المبيعة.** إيراد طلب المتجر يُقرأ بعملة المتجر الواحدة (`lavkoo:app/Services/OperationalCosts/Sales/SaleDocumentReader.php:207`) مع فصل الضريبة حسب `tax_model` على السطر أو الطلب (`lavkoo:app/Services/OperationalCosts/Sales/SaleDocumentReader.php:160-166`).

### 2.3 العمولة وتسوية البائعين

**مساران للعمولة يعملان معاً على كل طلب:**

1. **الموروث:** `orders.admin_commission` يُحسب من نسبة البائع أو الإعداد العام `sales_commission` عبر `Helpers::seller_sales_commission()` التي تعيد **نصاً** من `number_format(..., 2)` (`lavkoo:app/Utils/Helpers.php:639-652`)، ويُنظَّف من الفواصل عند الاستعمال (`lavkoo:app/Utils/OrderManager.php:1062`). هذا الرقم هو ما يغذّي `order_transactions` والمحافظ.
2. **الجديد:** `CommissionEngine` يحسب لكل سطر بقواعد نطاقها `product (400) > vendor (300) > category (200) > global (100)` (`lavkoo:app/Services/Marketplace/CommissionEngine.php:36-51`، `lavkoo:app/Services/Marketplace/CommissionEngine.php:103-141`)، ويرجع إلى السلوك الموروث إن لم توجد قواعد (`lavkoo:app/Services/Marketplace/CommissionEngine.php:158-178`)، ولا عمولة على بضاعة المنصّة نفسها (`lavkoo:app/Services/Marketplace/CommissionEngine.php:68-70`). اللقطة تُكتب في `order_item_commissions` بمفتاح `order_details_id` داخل معاملة الطلب (`lavkoo:app/Utils/OrderManager.php:1120-1205`، `lavkoo:app/Utils/OrderManager.php:1409-1415`)، وعملتها رمز `system_default_currency` (`lavkoo:app/Utils/OrderManager.php:1152`). حقول القاعدة: `scope_type`، `scope_id`، `rate_type`، `percentage`، `fixed_amount`، `min_amount`، `max_amount` — **بلا عملة ولا متجر** (`lavkoo:app/Models/CommissionRule.php:16-20`).

الكتابة كلها داخل `try/catch` يسجّل تحذيراً ولا يُفشل الطلب (`lavkoo:app/Utils/OrderManager.php:1122-1125`، `lavkoo:app/Utils/OrderManager.php:1202-1204`).

**دفتر البائع.** قيدان لكل سطر (كسب دائن + عمولة مدين) بحالة `pending` و`available_at` = الآن + نافذة الإرجاع (`lavkoo:app/Utils/OrderManager.php:1168-1200`). `VendorLedger::record()` مانع للتكرار على (البائع، النوع، المرجع)، ويقفل آخر قيد للبائع ويحسب `balance_after` (`lavkoo:app/Services/Marketplace/VendorLedger.php:62-80`)، والتقريب إلى 4 منازل (`lavkoo:app/Services/Marketplace/VendorLedger.php:53-54`)، والعملة = رمز `system_default_currency` (`lavkoo:app/Services/Marketplace/VendorLedger.php:91`، `lavkoo:app/Services/Marketplace/VendorLedger.php:227-241`). **سلسلة الرصيد مفتاحها (`seller_id`، `seller_is`) فقط** (`lavkoo:app/Models/VendorLedgerEntry.php:50-53`)، و`balances()` و`withdrawable()` يجمعان كل القيود بلا تمييز عملة (`lavkoo:app/Services/Marketplace/VendorLedger.php:104-128`، `lavkoo:app/Services/Marketplace/VendorLedger.php:147-158`). النضوج مشروط بأن يكون الطلب `delivered` (`lavkoo:app/Services/Marketplace/VendorLedger.php:186-218`).

**التسوية.** `marketplace:settle --release` يومياً 02:00 (`lavkoo:app/Console/ScheduleDefinition.php:35`). `SettlementEngine::calculate()` يأخذ كل القيود `available` غير المُطالَب بها للبائع ويختمها بـ `settlement_id`، وعملة التسوية = **عملة أول قيد** (`lavkoo:app/Services/Marketplace/SettlementEngine.php:66-72`، `lavkoo:app/Services/Marketplace/SettlementEngine.php:102`). الحساب لا يدفع؛ الاعتماد يجمّد؛ الدفع من «معتمد» فقط؛ الإلغاء قبل الاعتماد فقط (`lavkoo:app/Services/Marketplace/SettlementEngine.php:120-139`، `lavkoo:app/Services/Marketplace/SettlementEngine.php:147-171`، `lavkoo:app/Services/Marketplace/SettlementEngine.php:178-195`).

**طلبات الدفع (payout) على الدفتر.** فحص فترة التهدئة ثم بوابة KYC ثم الرصيد القابل للسحب ثم حجز بقيد مدين `reserved` (`lavkoo:app/Services/Marketplace/PayoutService.php:57-90`). عملة الدفع الاختيارية تُحوَّل بلقطة سعر عند الطلب (`lavkoo:app/Services/Marketplace/PayoutService.php:95-114`). الرقابة المزدوجة فوق عتبة والتجميد بالساعات من إعدادات `Policy` (`lavkoo:app/Services/Marketplace/PayoutService.php:478-492`).

**طلبات السحب الموروثة (withdraw).** تعمل على `seller_wallets` بوحدة التخزين (USD عند `multi_currency`): الطلب ينقص `total_earning` ويزيد `pending_withdraw` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:455-469`). عند اعتماد الإدارة تُزاد `withdrawn` ويُكتب قيد جسر `legacy_withdraw` في الدفتر بعد تحويل `usdToDefaultCurrency` (`lavkoo:app/Http/Controllers/Admin/Vendor/VendorController.php:769-796`). مراقب المطابقة يعدّ السحوبات المعتمدة غير المجسَّرة (`lavkoo:app/Services/Marketplace/ReconciliationService.php:51-66`).

**ضوابط تغيير الحساب البنكي.** تغيير بيانات البنك يُسجَّل في `vendor_bank_change_logs` ويفتح نافذة تهدئة (`lavkoo:app/Services/Marketplace/PayoutService.php:404-429`، `lavkoo:app/Http/Controllers/Vendor/ProfileController.php:125-142`)، وتُفرض النافذة على مسار الدفتر وعلى مسارَي السحب الموروثين في API v2 و v3 (`lavkoo:app/Services/Marketplace/PayoutService.php:57-59`، `lavkoo:app/Http/Controllers/RestAPI/v2/seller/SellerController.php:203`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:448-453`). الحساب البنكي للبائع **واحد** وهو أعمدة على `sellers` (`lavkoo:app/Models/Seller.php:54-57`).

**الاسترداد.** المسار الموروث ينقص `inhouse_earning` أو `total_earning` بمبلغ الاسترداد (`lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:135-139`)، ثم `RefundReversalService` يكتب قيدَي عكس على الدفتر (`lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:150`، `lavkoo:app/Services/Marketplace/RefundReversalService.php:53`).

**غياب الهجرات.** لا ملف في المستودع ينشئ `vendor_ledger_entries` ولا `vendor_settlements` ولا `vendor_payout_requests` ولا `commission_rules` ولا `purchase_orders` — والكود نفسه يوثّق ذلك في قائمة «Tables … that no migration here creates: they come from the dump» (`lavkoo:app/Services/Fulfillment/Readiness/Compatibility/RestoreSection.php:23-30`). البحث `rg "Schema::create\('(vendor_ledger_entries|commission_rules|order_item_commissions|vendor_settlements|vendor_payout_requests|vendor_bank_change_logs)'"` على المستودع كله (عدا `vendor/` و`node_modules/`) بلا نتائج؛ و`order_item_commissions` و`vendor_bank_change_logs` غير مذكورين حتى في تلك القائمة.

### 2.4 دلالات محفظة الإدارة (`admin_wallets`)

**صفّ عام واحد.** كل مواضع الإنشاء والتعديل التي رصدتها في `app/` تستعمل `admin_id = 1` ثابتاً: الإنشاء (`lavkoo:app/Utils/OrderManager.php:809-823`)، ولوحة التحكم (`lavkoo:app/Http/Controllers/Admin/DashboardController.php:81`)، ومسار تغيير حالة الطلب في `OrderRepository` (`lavkoo:app/Repositories/OrderRepository.php:700-975`) الذي يستدعيه متحكّما الإدارة والبائع (`lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:769`، `lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:741`)، و`AdminWalletRepository` يقبل فلتر `admin_id` في القراءة فقط (`lavkoo:app/Repositories/AdminWalletRepository.php:43`). الحقول: `inhouse_earning`، `withdrawn`، `commission_earned`، `delivery_charge_earned`، `pending_amount`، `total_tax_collected` (`lavkoo:app/Models/AdminWallet.php:26-47`) — **بلا عملة ولا متجر ولا كيان**.

| الحقل | أين يُحدَّث | الدليل |
|---|---|---|
| `pending_amount` (+) | عند نجاح الدفع الرقمي، مع إدراج `order_transactions` بحالة `hold` | `lavkoo:app/Utils/OrderManager.php:2147-2168` |
| `pending_amount` (+) | دفع مستحقّات تعديل الطلب | `lavkoo:app/Traits/OrderEditManager.php:720`، `lavkoo:app/Utils/module-helper.php:274` |
| `pending_amount` (−) | عند التسليم لطلب مدفوع رقمياً | `lavkoo:app/Utils/OrderManager.php:366-382` |
| `commission_earned` (+) | عند التسليم (نقدي/أوفلاين أو رقمي) | `lavkoo:app/Utils/OrderManager.php:309-314`، `lavkoo:app/Utils/OrderManager.php:366-367` |
| `commission_earned` (+) | عمولة مزاد (وحدة Auction غير موجودة في المستودع) | `lavkoo:app/Utils/module-helper.php:558-560` |
| `delivery_charge_earned` (±) | حسب نموذج الشحن وحامل الشحن المجاني | `lavkoo:app/Utils/OrderManager.php:218-253`، `lavkoo:app/Utils/OrderManager.php:311-313` |
| `inhouse_earning` (+ / −) | تسليم طلب المنصّة / استرداد | `lavkoo:app/Utils/OrderManager.php:316-322`، `lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:135-136` |
| `total_tax_collected` (+) | عند التسليم | `lavkoo:app/Utils/OrderManager.php:322`، `lavkoo:app/Utils/OrderManager.php:411` |
| `commission_earned`، `delivery_charge_earned`، `inhouse_earning`، `total_tax_collected` (تعديل) | تغيير حالة الطلب من لوحة الإدارة أو البائع (النسخة الموازية في `OrderRepository`) | `lavkoo:app/Repositories/OrderRepository.php:700-975`، `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:769`، `lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:741` |

التحديث «اقرأ ثم عدّل ثم احفظ» على الصفّ نفسه بلا قفل صريح في هذه الدالة (`lavkoo:app/Utils/OrderManager.php:309-314`). **لا سجلّ حركات:** النموذجان `AdminWalletHistory` و`SellerWalletHistory` بلا أي كاتب (البحث `rg "AdminWalletHistory|SellerWalletHistory|admin_wallet_histories|seller_wallet_histories"` في `app` و`Modules` عدا `app/Models` بلا نتائج). فأثر كل حركة لا يُستعاد إلا من `order_transactions` (`lavkoo:app/Utils/OrderManager.php:289-307`) و`transactions`.

`seller_wallets` صفّ واحد لكل بائع بالعدّادات `total_earning`، `withdrawn`، `commission_given`، `pending_withdraw`، `delivery_charge_earned`، `collected_cash`، `total_tax_collected` (`lavkoo:app/Models/SellerWallet.php:28-52`).

### 2.5 الضريبة (`Modules/TaxModule`)

**نموذج البيانات.** `taxes` (اسم، `tax_rate`، `country_code` اختياري)، `system_tax_setups` (‏`tax_type` = `order_wise` / `product_wise` / `category_wise`، `country_code`، `tax_payer`، `tax_ids`، `is_default`، `is_included`)، `tax_additional_setups` (ضريبة رسوم إضافية مثل التوصيل)، `taxables` (ربط متعدّد الأشكال بمنتج أو فئة)، `order_taxes` (سطور ضريبة الطلب) (`lavkoo:Modules/TaxModule/database/migrations/2025_05_26_115643_create_taxes_table.php:19-20`، `lavkoo:Modules/TaxModule/database/migrations/2025_05_26_115043_create_system_tax_setups_table.php:19-24`، `lavkoo:Modules/TaxModule/database/migrations/2025_05_26_121656_create_order_taxes_table.php:21-32`). أي أن النِّسب يمكن أن تكون لكل منتج أو فئة أو للطلب كله — **لا لكل منطقة/دولة فعلياً**.

**بُعد الدولة خامد.** عمود `country_code` موجود، لكن الإعداد `country_type = 'single'` (`lavkoo:Modules/TaxModule/config/config.php:16`) يجعل الحفظ يكتب `null` دائماً (`lavkoo:Modules/TaxModule/app/Services/SystemTaxSetupService.php:18`)، والمحلِّل الفعلي المستعمل في الطلبات `getTaxSystemType()` يأخذ الإعداد الوحيد `is_active = 1` و`is_default = 1` بلا شرط دولة، ويخبّئه 30 دقيقة بمفتاح `tax_system_type_{tax_payer}_{with_vat|no_vat}` غير مقيَّد بدولة أو متجر (`lavkoo:Modules/TaxModule/app/Traits/VatTaxManagement.php:15-33`). قاعدة التحقق تفرض فرادة `country_code` في `taxes` (`lavkoo:Modules/TaxModule/app/Http/Requests/TaxAddRequest.php:26`).

**شامل/غير شامل.** علم واحد `is_included` على الإعداد النظامي يُنسخ إلى `orders.tax_model` وإلى كل سطر (`lavkoo:app/Utils/OrderManager.php:1086`، `lavkoo:app/Utils/OrderManager.php:1286`). المعادلة في `lavkoo:Modules/TaxModule/app/Services/CalculateTaxService.php:287-296`.

**سطور ضريبة الطلب.** تُكتب من `OrderManager::getAddOrderTaxDetails()` مباشرة (`lavkoo:app/Utils/OrderManager.php:1511-1535`) لا من `CalculateTaxService::getCalculatedTax()` التي مناديها الوحيد هو متحكّم API داخل الوحدة نفسها يمرّر `storeData: false` (البحث `rg "CalculateTaxService|getCalculatedTax"` في `app` و`Modules`). مسار التخزين داخل تلك الخدمة يكتب عمودَي `country_code` و`store_id` غير الموجودَين في هجرة `order_taxes` (`lavkoo:Modules/TaxModule/app/Services/CalculateTaxService.php:269`، `lavkoo:Modules/TaxModule/app/Services/CalculateTaxService.php:276`) — مسار غير مستعمل في هذا المشروع (INFERRED من غياب المنادين بـ `storeData: true`).

**تقارير الضريبة.** تقرير الإدارة يجمع `order_transactions` بحالة `disburse` في المدى الزمني ويحسب ضريبة على عمولة الإدارة ورسوم التوصيل بنِسب تُختار في الطلب نفسه (`lavkoo:Modules/TaxModule/app/Http/Controllers/Admin/Reports/AdminTaxReportController.php:124-130`، `lavkoo:Modules/TaxModule/app/Http/Controllers/Admin/Reports/AdminTaxReportController.php:46-73`). لا فلتر متجر/دولة/كيان/عملة.

**حقول الرقم الضريبي.** على `shops`: `tax_identification_number`، `tin_expire_date`، `tin_certificate` (`lavkoo:database/migrations/2025_06_12_202801_add_tin_fields_to_shops_table.php:15-17`). هوية المنصّة الضريبية على الفاتورة تأتي من الإعداد العام `invoice_settings` (‏`business_identity` / `business_identity_value`) ويُقرأ **وقت الطباعة** (`lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:613`، `lavkoo:resources/views/admin-views/order/invoice.blade.php:528-531`). لا أثر لفوترة إلكترونية (ZATCA أو ما يماثلها): البحث `rg -i -P "zatca|e-invoice|einvoice|\btlv\b|fatoora(?!h)"` في `app` و`config` و`resources/views` و`Modules` لا يعيد أي ترميز لفوترة إلكترونية؛ المطابقات المتبقية هي مسارات `generate-invoice`، وعناصر واجهة مثل `collapseInvoice` (لأن الفحص غير حسّاس لحالة الأحرف)، وأرقام فواتير بوابة الدفع. النمط `fatoora(?!h)` يستبعد أسماء MyFatoorah.

### 2.6 التقارير واللوحات والتصديرات

| المكوّن | ما يجمعه | افتراض العملة | الدليل |
|---|---|---|---|
| لوحة الإدارة | صفّ `admin_wallets` الوحيد | وحدة التخزين ثم `usdToDefaultCurrency` | `lavkoo:app/Http/Controllers/Admin/DashboardController.php:81`، `lavkoo:app/Http/Controllers/Admin/DashboardController.php:105-109`، `lavkoo:app/Services/DashboardService.php:59-65` |
| `Admin\ReportController` | 44 سطراً فيه `selectRaw(` أو `->sum(` (أُحصيت؛ فُحص منها موضع واحد يجمع `seller_wallets` كلها) | لا عمود عملة في الاستعلام المفحوص | `lavkoo:app/Http/Controllers/Admin/ReportController.php:1107` |
| `Admin\TransactionReportController` | 21 سطراً مماثلاً (أُحصيت؛ فُحصت أربعة منها تجمع `init_order_amount` حسب طريقة الدفع) | لا عمود عملة في الاستعلامات المفحوصة | `lavkoo:app/Http/Controllers/Admin/TransactionReportController.php:114-123` |
| `SellerReportService` | مجاميع طلبات البائع | بلا عملة | `lavkoo:app/Services/Reports/SellerReportService.php:275-282` |
| `AnalyticsReporting` | `SUM(revenue)` و`SUM(value)` للأحداث | بلا عملة | `lavkoo:app/Services/Analytics/Reporting/AnalyticsReporting.php:259`، `lavkoo:app/Services/Analytics/Reporting/AnalyticsReporting.php:699` |
| قوالب التصدير | كل مبلغ عبر `usdToDefaultCurrency` + `getCurrencyCode()` | العملة الافتراضية العامة، والمنازل من `decimal_point_settings` | `lavkoo:resources/views/file-exports/order-transaction-report-export.blade.php:82-88`، `lavkoo:app/Utils/currency.php:257-267` |
| تقرير الربحية | `ProfitAggregator` بسبعة أبعاد | عملة أساس واحدة مع إعلان الفجوات | `lavkoo:app/Services/OperationalCosts/Profit/ProfitAggregator.php:8-16` |

### 2.7 أنواع المبالغ والتقريب

| الجدول / المكوّن | النوع المخزَّن | cast في النموذج | التقريب | الدليل |
|---|---|---|---|---|
| `admin_wallets`، `seller_wallets`، `order_transactions`، `transactions.amount` | `DECIMAL(40,20)` (قبلها `DECIMAL(21,12)`، وأصلها `double`) | `float` | لا تقريب عند الحفظ | `lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:14-19`، `lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:51-55`، `lavkoo:database/migrations/2026_01_22_153232_update_admin_wallets_amount_columns.php:15-19`، `lavkoo:app/Models/AdminWallet.php:26-37` |
| `orders.admin_commission` | `DECIMAL(40,20)` (أصلها `DECIMAL(8,2)`) | — | يُحسب بـ `number_format(…, 2)` | `lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:63`، `lavkoo:app/Utils/Helpers.php:649` |
| `vendor_ledger_entries`، `vendor_settlements`، `order_item_commissions` | UNKNOWN (لا هجرة) | `float` | `round(…, 4)` | `lavkoo:app/Models/VendorLedgerEntry.php:36-42`، `lavkoo:app/Services/Marketplace/VendorLedger.php:53-54`، `lavkoo:app/Models/OrderItemCommission.php:23-30` |
| `operational_cost_*`، طبقات التكلفة | `DECIMAL(20,6)`، أسعار الصرف `DECIMAL(20,10)` | `decimal:6` | `Money` (BigDecimal) تخزين 6، عرض 2 | `lavkoo:database/migrations/2026_11_07_100000_create_operational_cost_tables.php:116-120`، `lavkoo:app/Services/OperationalCosts/Support/Money.php:22-23`، `lavkoo:config/operational_costs.php:76` |
| `odoo_reconciliations` | `DECIMAL(20,6)` | — | — | `lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:153-158` |
| حقول Odoo النقدية | float عبر XML-RPC | — | **منزلتان ثابتتان** | `lavkoo:app/Services/Accounting/Odoo/OdooMoney.php:33-34`، `lavkoo:app/Services/Accounting/Odoo/OdooEntryDraft.php:87` |
| `taxes.tax_rate`، `order_taxes.*` | `double(23,8)` | `float` | لا تقريب | `lavkoo:Modules/TaxModule/database/migrations/2025_05_26_121656_create_order_taxes_table.php:21-24` |
| فحص سلامة الدفتر | — | — | سماحية `0.01` | `lavkoo:app/Services/Marketplace/ReconciliationService.php:26` |

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الموضع |
|---|---|---|
| A1 | محفظة إدارة واحدة للنظام كله (`admin_id = 1` ثابت في الكود) | `lavkoo:app/Utils/OrderManager.php:809-823` |
| A2 | عملة دفتر البائع = `system_default_currency` العامة، ورصيده سلسلة واحدة | `lavkoo:app/Services/Marketplace/VendorLedger.php:227-241`، `lavkoo:app/Models/VendorLedgerEntry.php:50-53` |
| A3 | عملة التسوية = عملة أول قيد فيها | `lavkoo:app/Services/Marketplace/SettlementEngine.php:102` |
| A4 | عملة أساس التكاليف واحدة، افتراضها النصّي `'SAR'` | `lavkoo:config/operational_costs.php:28`، `lavkoo:app/Services/OperationalCosts/Support/FxConverter.php:50` |
| A5 | «الدفاتر» الخاصة بالمنصّة = `seller_id = 0` (كيان واحد) | `lavkoo:database/migrations/2026_11_07_100000_create_operational_cost_tables.php:24-25`، `lavkoo:database/migrations/2026_11_07_100000_create_operational_cost_tables.php:91` |
| A6 | Odoo: اتصال واحد، شركة واحدة، ومنزلتان عشريتان «the only currency this store settles in is SAR» | `lavkoo:app/Http/Controllers/Admin/Accounting/OdooConnectionController.php:148-152`، `lavkoo:app/Services/Accounting/Odoo/OdooMoney.php:33-34` |
| A7 | أمر المطابقة افتراضه `--currency=SAR` | `lavkoo:app/Console/Commands/OdooReconcileCommand.php:27` |
| A8 | إعداد ضريبي نظامي افتراضي واحد، و`country_type = 'single'` | `lavkoo:Modules/TaxModule/app/Traits/VatTaxManagement.php:27-33`، `lavkoo:Modules/TaxModule/config/config.php:16` |
| A9 | هوية الفاتورة الضريبية إعداد عام واحد | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:613` |
| A10 | عمولة موروثة بمنزلتين ثابتتين | `lavkoo:app/Utils/Helpers.php:649` |
| A11 | عقد سارٍ واحد يُختار لكل (`seller_id`، `provider_code`، تاريخ السريان) دون قيد فرادة على هذا الثلاثي في الهجرة؛ أول موقع فعّال واحد لكل مزوّد | `lavkoo:app/Services/OperationalCosts/ContractService.php:220-234`، `lavkoo:database/migrations/2026_11_07_100000_create_operational_cost_tables.php:47`، `lavkoo:app/Services/OperationalCosts/Support/StockLocationResolver.php:63-66` |
| A12 | حساب بنكي واحد للبائع | `lavkoo:app/Models/Seller.php:54-57` |
| A13 | السحب الموروث بوحدة التخزين (USD عند `multi_currency`) محوَّلة بسعر عام واحد | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:456-468`، `lavkoo:app/Utils/currency.php:76-98` |
| A14 | مرجع Odoo ببادئة ثابتة `PKSA-COST-` بلا مميِّز كيان | `lavkoo:app/Services/Accounting/Odoo/OdooPostingKey.php:61` |

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Foundation: separate legal selling company per country (فواتير، ضرائب، تسويات، محاسبة) | دفتر التكاليف لا يحذف ولا يعدّل؛ دفتر البائع إلحاقي؛ ربط Odoo يحمل `odoo_company_id` | لا بُعد كيان قانوني في `admin_wallets` ولا `order_transactions` ولا `vendor_ledger_entries` ولا `operational_cost_entries` ولا اتصال Odoo | `lavkoo:app/Models/AdminWallet.php:39-47`، `lavkoo:app/Models/OrderTransaction.php:41-59`، `lavkoo:app/Models/VendorLedgerEntry.php:30-34`، `lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:27` |
| Foundation: historical snapshots preserved | لقطة العمولة لكل سطر مجمَّدة؛ `order_taxes` يحفظ الاسم والنسبة والمبلغ وقت الطلب | هوية البائع القانونية على الفاتورة تُقرأ من الإعداد الحالي وقت الطباعة؛ `admin_wallets` بلا سجلّ حركات | `lavkoo:app/Models/OrderItemCommission.php:15-21`، `lavkoo:app/Utils/OrderManager.php:1513-1534`، `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:613` |
| Foundation: keep Odoo (review before modifying) | حماية ضد التكرار، مفاتيح مطفأة، لا ترحيل نهائي | اتصال وشركة واحدة؛ لا مُدرِج؛ منزلتان | `lavkoo:config/odoo.php:28-42`، `lavkoo:app/Services/Accounting/Odoo/OdooOutbox.php:49-72` |
| Provisional: wallets and ledgers separate by store/legal entity/currency, reconcile historical Saudi balances | `currency` عمود على قيد الدفتر والتسوية واللقطة | الرصيد والتسوية يجمعان عبر العملات؛ الأرصدة التاريخية عدّادات بلا حركات | `lavkoo:app/Services/Marketplace/VendorLedger.php:104-128`، `lavkoo:app/Services/Marketplace/SettlementEngine.php:66-72` |
| Provisional: local pricing, no auto-FX | `FxConverter` لا يخمّن سعراً والربح يعلن الفجوة | التقارير الموروثة تحوّل كل شيء بسعر عام واحد إلى عملة افتراضية واحدة | `lavkoo:app/Services/OperationalCosts/Support/FxConverter.php:32-40`، `lavkoo:app/Services/DashboardService.php:59-65` |
| GCC-13 (محافظ ومسارات مالية منفصلة لكل متجر/كيان) | — | `order_transactions.customer_id` بلا متجر؛ كل الطلبات تصبّ في `admin_id = 1` | `lavkoo:app/Utils/OrderManager.php:2147-2168` |
| GCC-14 (نطاقات بيانات للموظفين) | صلاحيات وحدة `module:operational_costs` و`module:marketplace` على الشاشات المرصودة (تغطية كل الشاشات المالية: SEC-E13-01) | لا نطاق كيان/دولة على أي شاشة مالية أو تصدير | `lavkoo:routes/admin/operational-costs.php:24-28`، `lavkoo:routes/admin/routes.php:687` |
| GCC-16 (الشحن المحلي والعابر حسب الأهلية، مع التحقق من الضريبة والوجهة) | شروط `countries` في قواعد التكلفة | الضريبة لا تعرف دولة الوجهة ولا المنشأ | `lavkoo:app/Services/OperationalCosts/Calculation/RuleCalculator.php:85-87`، `lavkoo:Modules/TaxModule/app/Traits/VatTaxManagement.php:27-33` |
| Note: KWD/BHD/OMR ثلاث منازل | التخزين `DECIMAL(20,6)` و`DECIMAL(40,20)` و`round(4)` يتّسع | Odoo منزلتان؛ العمولة الموروثة منزلتان؛ العرض من إعداد عام واحد | `lavkoo:app/Services/Accounting/Odoo/OdooMoney.php:33-34`، `lavkoo:app/Utils/Helpers.php:649`، `lavkoo:app/Utils/currency.php:259` |
| GCC-12 (إيقاف متجر دون كسر الالتزامات) | التسوية والنضوج أوامر مجدولة عامة لا تعتمد على حالة واجهة المتجر | لا يوجد مفهوم متجر في هذه الأوامر أصلاً، فلا تعارض ولا دعم | `lavkoo:app/Console/ScheduleDefinition.php:35` |

### 4.1 أين يلزم بُعد الكيان القانوني (مصفوفة موجزة)

القاعدة المستخلصة من الكود: لا يُضاف عمود دولة لكل جدول؛ يُضاف **كيان قانوني** حيث يُسجَّل مال أو التزام ضريبي، ويُشتقّ في غيره من الطلب أو الحساب أو الموقع.

| الجدول / التجميع | المفتاح الحالي | هل يحمل عملة؟ | الحاجة | الدليل |
|---|---|---|---|---|
| `admin_wallets` | `admin_id` (ثابت = 1) | لا | بنية جديدة لكل كيان؛ الصفّ الحالي يُثبَّت على الكيان السعودي | `lavkoo:app/Models/AdminWallet.php:39-47` |
| `seller_wallets` | `seller_id` | لا | لا يُستعمل للكيانات الجديدة | `lavkoo:app/Models/SellerWallet.php:28-52` |
| `order_transactions` | `order_id` + `seller_id` + `shop_id` (متجر البائع لا متجر الدولة) | لا | كيان مباشر (سجلّ ضريبي ومالي) | `lavkoo:app/Models/OrderTransaction.php:41-59`، `lavkoo:database/migrations/2025_09_04_174517_add_shop_id_to_order_transactions_table.php:15` |
| `order_taxes` | `order_id` + `tax_id` + `seller_id` | لا | يُشتقّ من الطلب؛ التقارير تحتاج فلتر كيان | `lavkoo:Modules/TaxModule/database/migrations/2025_05_26_121656_create_order_taxes_table.php:21-32` |
| `system_tax_setups` / `taxes` | `is_default` + `tax_payer` (و`country_code` خامد) | — | إعداد لكل كيان/متجر | `lavkoo:Modules/TaxModule/database/migrations/2025_05_26_115043_create_system_tax_setups_table.php:19-24` |
| `order_item_commissions` | `order_details_id` | نعم (رمز عام) | يُشتقّ من الطلب | `lavkoo:app/Models/OrderItemCommission.php:15-21` |
| `commission_rules` | `scope_type` + `scope_id` | لا | نطاق متجر + عملة للمبالغ الثابتة | `lavkoo:app/Models/CommissionRule.php:16-20` |
| `vendor_ledger_entries` | (`seller_id`، `seller_is`) | نعم (رمز عام) | كيان + عملة في مفتاح سلسلة الرصيد | `lavkoo:app/Models/VendorLedgerEntry.php:30-34` |
| `vendor_settlements` / `vendor_payout_requests` | (`seller_id`، `seller_is`) | نعم (من أول قيد / من قيد الحجز) | كيان + عملة | `lavkoo:app/Services/Marketplace/SettlementEngine.php:102`، `lavkoo:app/Services/Marketplace/PayoutService.php:95-114` |
| `operational_cost_entries` / الفواتير / العقود | `seller_id` (0 = المنصّة) + `sales_channel` + `account_id` + `inventory_location_id` | نعم + `base_currency` | كيان مباشر أو مشتقّ من الحساب/الموقع؛ عملة أساس لكل كيان | `lavkoo:database/migrations/2026_11_07_100000_create_operational_cost_tables.php:116-120` |
| `inventory_cost_layers` | (`seller_id`، `inventory_location_id`، `product_id`، `variant_key`) | نعم (عملة الأساس العامة) | معزولة فعلياً بالموقع إذا كان الموقع لكيان واحد؛ العملة تتبع الكيان | `lavkoo:app/Services/OperationalCosts/InventoryCostLayerService.php:172-184` |
| `odoo_account_mappings` / `odoo_posting_queue` / `odoo_reconciliations` | `seller_id` + `odoo_company_id` | نعم في الربط والمطابقة | ربط الاتصال والشركة بالكيان | `lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:27` |

### 4.2 بقاء الأرصدة والترحيلات السعودية التاريخية سليمة وقابلة للمطابقة

1. **الأرصدة الموروثة عدّادات لا حركات.** `admin_wallets` و`seller_wallets` لا يحملان سجلّاً (القسم 2.4)، فالمصدر الوحيد لإعادة بناء أي رصيد سعودي هو `order_transactions` و`transactions` و`withdraw_requests`. المقترح التوثيقي: قبل أي توسعة، لقطة قراءة فقط لهذه الجداول الخمسة مع مجاميع تحقق، وتثبيتها مرجعاً لـ «رصيد افتتاحي للكيان السعودي».
2. **وحدة القياس.** المبالغ الموروثة بوحدة التخزين، وتُحوَّل عند العرض بسعر `system_default_currency` الحالي (`lavkoo:app/Utils/currency.php:76-98`)؛ فتغيير العملة الافتراضية أو نموذج العملة لخدمة GCC يغيّر قراءة كل رقم تاريخي سعودي دون تغيير أي صفّ. هذا قيد صريح على أي تعديل لإعدادات العملة العامة (يُراجَع مع E02).
3. **دفتر البائع.** القيود الحالية تحمل رمز العملة العام وقت الكتابة (`lavkoo:app/Services/Marketplace/VendorLedger.php:91`)، و`balance_after` محسوب على سلسلة البائع الواحدة (`lavkoo:app/Services/Marketplace/VendorLedger.php:74-80`). أي تقسيم لاحق للسلسلة حسب الكيان يجب أن يُبقي القيود القديمة وأرصدتها الجارية كما هي تحت الكيان السعودي، وأن يبدأ سلاسل الكيانات الجديدة من صفر؛ فحص `ledgerRunningBalanceIntegrity` الموجود (`lavkoo:app/Services/Marketplace/ReconciliationService.php:33-42`) يصلح بوابة قبل وبعد.
4. **Odoo.** لا مُدرِج إنتاجي، فالمتوقَّع أن يكون `odoo_posting_queue` فارغاً (UNKNOWN حتى يُتحقَّق من الإنتاج). إن وُجدت صفوف، فمرجعها `PKSA-COST-<id>-<version>` هو ما يُبحث به في Odoo (`lavkoo:app/Services/Accounting/Odoo/OdooPostingRun.php:145-160`)؛ **تغيير صيغة المرجع للصفوف القائمة يكسر منع التكرار**، والكود نفسه يقارن المرجعين صراحةً ويرفض عند الاختلاف (`lavkoo:app/Services/Accounting/Odoo/OdooPostingRun.php:137-142`). أي مميِّز كيان يُضاف للقيود الجديدة فقط.
5. **دفتر التكاليف.** الوحدة مطفأة افتراضياً (`lavkoo:config/operational_costs.php:22`)؛ إن لم تُفعَّل في الإنتاج فلا بيانات تاريخية تُرحَّل، وإضافة بُعد الكيان وعملة الأساس لكل كيان تكون قبل أول قيد. إن كانت مفعَّلة، فمفتاح `slot_key` مشتقّ بدالة تجزئة من (البائع، نوع الموضوع ومعرّفه، المكوّن، مرجع الخدمة) (`lavkoo:app/Services/OperationalCosts/Data/CostDraft.php:73-75`)، وتغيير تركيبها يستدعي الفحص نفسه الذي بُني له أمر `costs:check-legacy-commission-slots` (`lavkoo:app/Console/Commands/CostsCheckLegacyCommissionSlotsCommand.php:48`).
6. **الضريبة.** `order_taxes` يحفظ اسم الضريبة ونسبتها ومبلغها وقت الطلب (`lavkoo:app/Utils/OrderManager.php:1513-1534`)، فالطلبات السعودية القديمة لا تتأثر بتغيير الإعداد؛ ما يتأثر هو التقارير التي تعيد الحساب بنِسب مختارة وقت التقرير (`lavkoo:Modules/TaxModule/app/Http/Controllers/Admin/Reports/AdminTaxReportController.php:46-73`).

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | الإضافة الدنيا المقترحة |
|---|---|---|---|
| `CostLedger` + `operational_cost_entries` | Preserve + Extend | كاتب الإنشاء والحالة المالية الوحيد، مانع للتكرار، لا حذف | عمود كيان قانوني اختياري (قيمته للصفوف الحالية = الكيان السعودي) يُشتقّ عند الكتابة من الحساب/الموقع/الطلب |
| `InventoryCostLayerService` (FIFO) | Preserve + Extend | سليم ومقفول ومعزول بالموقع | عملة أساس لكل كيان بدل الإعداد العام؛ لا تغيير في الخوارزمية |
| `ProfitabilityService` / `ProfitAggregator` | Extend | يعلن الفجوات ولا يخمّن | بُعد تجميع «كيان/متجر» ومنع جمع عملات أساس مختلفة |
| `ContractService` / القواعد | Extend | الإصدارات والتدقيق جيّدة | إضافة الكيان إلى مفتاح البحث عن العقد |
| Odoo (الطابور، السياج، الباني) | Preserve + Extend | أقوى ضمانات عدم التكرار في المشروع | اتصال لكل كيان قانوني، منازل العملة من النسخة، مميِّز كيان في المرجع للقيود الجديدة فقط |
| `OdooMoney` | Refactor (صغير) | منزلتان ثابتتان | تمرير منازل العملة فعلياً من المنادي |
| `VendorLedger` / `SettlementEngine` / `PayoutService` | Preserve + Extend | إلحاقي، مقفول، مانع للتكرار، ضوابط احتيال | سلسلة رصيد وتسوية لكل (بائع، كيان، عملة)؛ استيثاق مخطّط الجداول أولاً |
| `CommissionEngine` + `order_item_commissions` | Preserve + Extend | حتمي ومجمَّد | نطاق متجر/عملة للقاعدة؛ عملة للمبالغ الثابتة |
| `admin_wallets` / `seller_wallets` / `order_transactions` | Preserve (تاريخ سعودي) + New بجوارها | عدّادات قابلة للتغيير بلا حركات | عدم إعادة استعمالها للدول الجديدة؛ بنية جديدة مقيَّدة بالكيان، وتجميد لقطة افتتاحية للأرصدة السعودية |
| مسار السحب الموروث `withdraw_requests` | Preserve ثم Isolate | يعمل اليوم ومجسَّر إلى الدفتر | حصره في الكيان السعودي وعدم تفعيله للكيانات الجديدة |
| `Modules/TaxModule` | Extend | عمود `country_code` موجود لكنه خامد | محلِّل ضريبي بحسب الكيان/المتجر، ومفتاح كاش مقيَّد، وعلم الشمول لكل كيان |
| الفاتورة (`invoice_settings`) | New | لا لقطة كيان | لقطة كيان قانوني على الطلب/الفاتورة عند الإصدار |
| تقارير ولوحات وتصديرات موروثة | Refactor | مجاميع عامة بعملة واحدة | فلتر كيان/متجر إلزامي ومنع الجمع عبر العملات |
| `Helpers::seller_sales_commission` | Refactor (حذِر) | نصّ بمنزلتين | توحيد المصدر مع `CommissionEngine` بعد اختبار رجعي سعودي |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E13-F01 | `admin_wallets` صفّ عام واحد (`admin_id = 1`) بلا عملة ولا كيان، عدّاداته تُعدَّل في مكانها ولا سجلّ حركات له | P0 | VERIFIED | `lavkoo:app/Utils/OrderManager.php:809-823`، `lavkoo:app/Utils/OrderManager.php:309-314`، `lavkoo:app/Models/AdminWallet.php:39-47`، `lavkoo:app/Repositories/OrderRepository.php:700-975` | أي طلب غير سعودي سيُجمع بعملته على الأرصدة السعودية ويفسدها بلا إمكان فصل لاحق | عدم توجيه طلبات الكيانات الجديدة إليه؛ لقطة افتتاحية موثّقة للرصيد السعودي؛ بنية مقيَّدة بالكيان بجواره |
| E13-F02 | `seller_wallets` صفّ واحد لكل بائع بلا عملة/متجر، والسحب الموروث بوحدة تخزين محوَّلة بسعر عام | P0 | VERIFIED | `lavkoo:app/Models/SellerWallet.php:28-52`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:455-469` | بائع يبيع في متجرين ستختلط مستحقّاته بعملتين في رقم واحد | حصر المسار في الكيان السعودي؛ اعتماد دفتر مقيَّد بالكيان للجديد |
| E13-F03 | سلسلة رصيد دفتر البائع والتسوية مفتاحهما (`seller_id`، `seller_is`) فقط؛ المجاميع تعبر العملات، وعملة التسوية عملة أول قيد | P0 | VERIFIED | `lavkoo:app/Services/Marketplace/VendorLedger.php:74-80`، `lavkoo:app/Services/Marketplace/VendorLedger.php:104-128`، `lavkoo:app/Services/Marketplace/SettlementEngine.php:66-72`، `lavkoo:app/Services/Marketplace/SettlementEngine.php:102` | تسوية واحدة قد تجمع ريالاً وديناراً وتُدفع بعملة واحدة؛ تحويل ضمني بين شركات قانونية | مفتاح (بائع، كيان، عملة) للرصيد والتسوية والحجز |
| E13-F04 | جداول النواة المالية (`vendor_ledger_entries`، `vendor_settlements`، `vendor_payout_requests`، `commission_rules`، `order_item_commissions`، `vendor_bank_change_logs`) بلا هجرة في المستودع | P0 | VERIFIED (الغياب مع البحث المسجّل)؛ المخطّط الفعلي UNKNOWN | `lavkoo:app/Services/Fulfillment/Readiness/Compatibility/RestoreSection.php:23-30`، `lavkoo:app/Services/Marketplace/VendorLedger.php:49-51`؛ ملف التثبيت `installation/backup/database.sql` الذي تذكره تعليمات المستودع (`lavkoo:CLAUDE.md:22`) غير موجود في هذه النسخة (بحث: `ls installation` لا يعيد إلا ملفين نصيين، ومطابقة `git ls-files` لملفات `.sql` بلا نتائج) | لا يمكن تصميم هجرة إضافية آمنة ولا بناء بيئة اختبار مطابقة دون مخطّط الإنتاج؛ وجودها نفسه في الإنتاج غير مؤكَّد | استخراج `SHOW CREATE TABLE` من الإنتاج (قراءة فقط) وتوثيقه قبل أي تصميم |
| E13-F05 | الضريبة: إعداد نظامي افتراضي واحد، علم شمول واحد، كاش غير مقيَّد بدولة، و`country_type = 'single'`؛ عمود `country_code` خامد | P0 | VERIFIED | `lavkoo:Modules/TaxModule/app/Traits/VatTaxManagement.php:15-33`، `lavkoo:Modules/TaxModule/config/config.php:16`، `lavkoo:Modules/TaxModule/app/Services/SystemTaxSetupService.php:18` | لا يمكن تطبيق أنظمة ضريبية مختلفة لكل دولة ولا إخراج ضريبي مستقلّ لكل كيان (النِّسب نفسها يحدّدها المستشار الضريبي للمالك) | محلِّل ضريبي بحسب الكيان/المتجر مع الحفاظ على الإعداد الحالي افتراضاً للسعودية |
| E13-F06 | `order_transactions` بلا عملة/متجر/كيان، وتقرير ضريبة الإدارة يجمعه كله | P1 | VERIFIED | `lavkoo:app/Models/OrderTransaction.php:41-59`، `lavkoo:Modules/TaxModule/app/Http/Controllers/Admin/Reports/AdminTaxReportController.php:124-130` | تقرير ضريبي يخلط كيانات وعملات | عمود كيان (القيمة التاريخية = السعودي) وفلتر إلزامي في التقارير |
| E13-F07 | هوية البائع القانونية على الفاتورة تُقرأ من الإعداد العام الحالي وقت الطباعة، ولا لقطة كيان على الطلب | P1 | VERIFIED لمسار الطباعة؛ غياب لقطة في موضع آخر INFERRED | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:606-613`، `lavkoo:resources/views/admin-views/order/invoice.blade.php:528-531` | إعادة طباعة فاتورة سعودية قديمة بعد تغيير الإعداد تُظهر هوية مختلفة | لقطة كيان قانوني عند إصدار الطلب، والفواتير القديمة تُثبَّت على الكيان السعودي |
| E13-F08 | Odoo: اتصال واحد (أول صفّ قناته `odoo`) وشركة واحدة من إعداده | P1 | VERIFIED | `lavkoo:app/Http/Controllers/Admin/Accounting/OdooConnectionController.php:148-152`، `lavkoo:app/Console/Commands/OdooReconcileCommand.php:46`، `lavkoo:app/Services/Accounting/Odoo/OdooPreflightRun.php:148-176` | دفاتر كيانات متعدّدة لا يمكن توجيهها إلى شركات Odoo مختلفة | ربط الاتصال (أو الشركة) بالكيان القانوني، واختيار الاتصال من كيان القيد |
| E13-F09 | Odoo لا يُرحِّل إلا قيود التكاليف مسوّدةً، ولا مُدرِج إنتاجي للطابور، ولا جدولة، ولا ترحيل نهائي | INFO | VERIFIED | `lavkoo:app/Services/Accounting/Odoo/OdooPostingKey.php:32`، `lavkoo:app/Services/Accounting/Odoo/OdooOutbox.php:49-72`، `lavkoo:app/Services/Accounting/Odoo/OdooOperations.php:88-105` | لا توجد اليوم قيود مبيعات/ضريبة/تسويات في Odoo من هذا النظام؛ لا ترحيلات سعودية تاريخية يُخشى عليها من الكود (الواقع في الإنتاج UNKNOWN) | قرار مالك: هل تدخل المبيعات والضريبة والتسويات نطاق Odoo في GCC |
| E13-F10 | تقريب Odoo ثابت على منزلتين (والتوازن يُفحص بمنزلتين) | P1 | VERIFIED | `lavkoo:app/Services/Accounting/Odoo/OdooMoney.php:33-34`، `lavkoo:app/Services/Accounting/Odoo/OdooDraftBuilder.php:100-101`، `lavkoo:app/Services/Accounting/Odoo/OdooEntryDraft.php:87` | فقد الفلس الثالث في KWD/BHD/OMR | قراءة منازل العملة من النسخة وتمريرها |
| E13-F11 | التكاليف: عملة أساس عامة واحدة ودفتر منصّة واحد (`seller_id = 0`) بلا كيان على القيود والطبقات والعقود والفواتير | P1 | VERIFIED | `lavkoo:config/operational_costs.php:28`، `lavkoo:database/migrations/2026_11_07_100000_create_operational_cost_tables.php:24-25`، `lavkoo:database/migrations/2026_11_07_100000_create_operational_cost_tables.php:91`، `lavkoo:app/Services/OperationalCosts/InventoryCostLayerService.php:92` | ربحية وتكلفة بضاعة لا تنفصل لكل شركة؛ تحويل تكاليف الكويت إلى SAR | عملة أساس لكل كيان وبُعد كيان؛ الوحدة مطفأة فالتوسعة ممكنة قبل أي بيانات |
| E13-F12 | البحث عن العقد بـ (`seller_id`، `provider_code`، التاريخ) دون الكيان القانوني، وموقع مخزون فعّال واحد لكل مزوّد | P1 | VERIFIED | `lavkoo:app/Services/OperationalCosts/ContractService.php:220-234`، `lavkoo:database/migrations/2026_11_07_100000_create_operational_cost_tables.php:47`، `lavkoo:app/Services/OperationalCosts/Support/StockLocationResolver.php:63-66` | عقد مزوّد واحد سيُسعِّر طلبات كيان آخر إن لم يُضَف الكيان إلى مفتاح البحث؛ طبقات تُنشأ في موقع خاطئ إن كان الموقع مشتركاً بين كيانات | إضافة الكيان/الحساب إلى مفتاح العقد ومحلِّل الموقع |
| E13-F13 | مساران للعمولة: موروث بنصّ `number_format(…, 2)` يغذّي المحافظ، وجديد بقواعد يغذّي الدفتر؛ القواعد بلا متجر/عملة و`fixed_amount` بلا عملة | P1 | VERIFIED | `lavkoo:app/Utils/Helpers.php:639-652`، `lavkoo:app/Utils/OrderManager.php:1062`، `lavkoo:app/Services/Marketplace/CommissionEngine.php:143-152`، `lavkoo:app/Models/CommissionRule.php:16-20`، `lavkoo:app/Services/Marketplace/CommissionEngine.php:175` | رقمان مختلفان للعمولة على الطلب نفسه عند وجود قواعد؛ تقريب منزلتين في الموروث وأربع منازل في المحرّك، حتى في مسار الرجوع الذي يعلّق بأنه مطابق للموروث | مصدر واحد للعمولة ونطاق متجر/عملة للقاعدة |
| E13-F14 | قناتا دفع للبائع (سحب موروث بوحدة التخزين / دفع على الدفتر بالعملة الافتراضية) والجسر يحوّل بـ `usdToDefaultCurrency` بينما قيد الكسب يُكتب بمبلغ السطر كما هو | P1 | INFERRED (يتوقّف على `currency_model` في الإنتاج) | `lavkoo:app/Http/Controllers/Admin/Vendor/VendorController.php:785-796`، `lavkoo:app/Utils/OrderManager.php:1180-1188`، `lavkoo:app/Utils/currency.php:76-98` | عند `multi_currency` قد لا تتطابق وحدة الكسب مع وحدة الجسر | حسم `currency_model` الإنتاجي ثم توحيد وحدة الدفتر |
| E13-F15 | لقطة العمولة وقيود الدفتر «أفضل جهد» داخل `try/catch`؛ وتحديث `admin_wallets` اقرأ-عدّل-احفظ | P1 | VERIFIED | `lavkoo:app/Utils/OrderManager.php:1202-1204`، `lavkoo:app/Utils/OrderManager.php:309-314` | طلب بلا قيد كسب؛ ضياع تحديث تحت التزامن | مراقب `commissionSnapshotVsLedger` موجود (`lavkoo:app/Services/Marketplace/ReconciliationService.php:33-42`)؛ يُجعل بوابة قبول |
| E13-F16 | `float` cast فوق `DECIMAL(40,20)`؛ `double` في جداول الضريبة؛ منازل العرض من إعداد عام واحد | P2 | VERIFIED | `lavkoo:app/Models/AdminWallet.php:26-37`، `lavkoo:Modules/TaxModule/database/migrations/2025_05_26_121656_create_order_taxes_table.php:21-24`، `lavkoo:app/Utils/currency.php:259` | انحرافات تقريب صغيرة؛ عرض 3 منازل غير ممكن لكل عملة | منازل لكل عملة عند العرض؛ حساب عشري في المسارات الجديدة |
| E13-F17 | التقارير واللوحات والتصديرات تجمع على مستوى النظام بعملة افتراضية واحدة | P1 | VERIFIED (عيّنة: 5 مكوّنات من الجدول 2.6) | `lavkoo:app/Http/Controllers/Admin/ReportController.php:1107`، `lavkoo:app/Services/DashboardService.php:59-65`، `lavkoo:resources/views/file-exports/order-transaction-report-export.blade.php:82-88` | أرقام مضلِّلة وتسرّب بيانات مالية بين الكيانات لموظف محلّي | فلتر كيان إلزامي ومنع الجمع عبر العملات |
| E13-F18 | لا اختبار توصيفي (characterization) لدفتر البائع/التسوية/الدفع/العمولة/محافظ الإدارة والبائع | P1 | VERIFIED بالبحث عن أسماء النماذج `VendorLedger` و`SettlementEngine` و`PayoutService` و`CommissionEngine` و`AdminWallet` و`SellerWallet` و`OrderTransaction` في `tests/`: ملف واحد، يسرد أسماء النماذج ليؤكّد أن وحدة التكاليف لا تكتب فيها (لا اختبار سلوكي)؛ وبأسماء الجداول (`order_transactions`، `vendor_ledger_entries`، `commission_rules`، إلخ): ملفان، والثاني مخطّط مساعد لاختبارات العناوين. (تصحيح: صيغة البحث السابقة استعملت المحرف الرأسي المهرّب فعادت بصفر نتائج) | `lavkoo:tests/Unit/OperationalCosts/PlatformTablesStayThePlatformsTest.php:32-35`، `lavkoo:tests/Support/BuildsApiAddressSchema.php:339` | أي توسعة مالية بلا شبكة أمان رجعية للسعودية | اختبارات توصيفية (characterization) قبل أي تعديل |
| E13-F19 | ضوابط الاحتيال الموجودة (تهدئة تغيير البنك، KYC، رقابة مزدوجة، maker-checker، الحجز) | INFO | VERIFIED | `lavkoo:app/Services/Marketplace/PayoutService.php:57-90`، `lavkoo:app/Services/Marketplace/PayoutService.php:404-429`، `lavkoo:app/Services/Marketplace/SettlementEngine.php:147-151` | يجب ألا تُفقد عند التوسعة | Preserve كما هي، مع تقييد الحساب البنكي بالكيان |
| E13-F20 | وثيقة Odoo تبدأ بـ «الحالة: غير مبني» خلافاً للكود | P2 | VERIFIED | `lavkoo:docs/sales-channels/odoo-accounting.md:3`، `lavkoo:docs/sales-channels/odoo-accounting.md:75` | تضليل القارئ | تصحيح توثيقي لاحق |
| SEC-E13-01 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E13-02 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E13-03 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E13-04 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | — | — |

## 7. أسئلة مفتوحة وعناصر UNKNOWN

1. **مخطّط جداول النواة المالية ووجودها في الإنتاج** (E13-F04): يحسمه `SHOW CREATE TABLE` للجداول الستة من قاعدة الإنتاج (قراءة فقط) أو ملف التثبيت غير المتتبَّع.
2. **قيم مفاتيح `.env` الإنتاجية:** `ODOO_*` الأربعة، `OPERATIONAL_COSTS_ENABLED`، `OPERATIONAL_COSTS_SCHEDULE_ENABLED`، `OPERATIONAL_COSTS_BASE_CURRENCY`، `OPERATIONAL_COSTS_SOURCE_PURCHASE_ORDERS`. يحسمها ملف `.env` والكاش المبني.
3. **هل يوجد صفّ اتصال Odoo وربط معتمد وصفوف في `odoo_posting_queue` في الإنتاج؟** يحسمه استعلام عدّ على الجداول الثلاثة وعلى `sales_channel_accounts` بقناة `odoo`.
4. **`currency_model` و`system_default_currency` و`sales_commission` و`decimal_point_settings` الإنتاجية** (تحسم E13-F14 وطريقة قراءة الأرصدة التاريخية): `business_settings`.
5. **هل توجد قواعد في `commission_rules` وهل فُعِّل الإعداد الضريبي (`is_active`، `is_included`) وما النِّسب المسجّلة؟** بيانات الإنتاج؛ النِّسب الواجبة قانوناً لكل دولة قرار المستشار الضريبي للمالك لا يُستنتج من الكود.
6. **حجم الأرصدة السعودية التاريخية** في `admin_wallets` و`seller_wallets` وعدد السحوبات المعتمدة غير المجسَّرة: شاشة `admin/marketplace/reconciliation` أو استعلام قراءة.
7. **هل طُبِّقت هجرة `2026_05_07_000001` (‏`DECIMAL(40,20)`) وهجرات التكاليف و Odoo على الإنتاج؟** جدول `migrations`.
8. **فرع Odoo في المستودع القديم (PR #15):** UNKNOWN (missing access: pharmacyksa98)؛ لا يمكن مقارنة ما في `lavkoo` بما كان مفتوحاً هناك.
9. **إشارة `amount_currency` وحقل `reversed_entry_id` في Odoo** معلَنان في الوثيقة نفسها «UNVERIFIED» (`lavkoo:docs/sales-channels/odoo-accounting.md:188`، `lavkoo:docs/sales-channels/odoo-accounting.md:212`): تحسمهما نسخة Odoo حقيقية.
10. **وحدة `Modules/Auction`** يُشار إليها في تحديث `commission_earned` (`lavkoo:app/Utils/module-helper.php:551-560`) وهي غير موجودة في المستودع: أثرها على المحفظة غير قابل للتحقق. وتذكرها تعليمات المستودع (`lavkoo:CLAUDE.md:36`) ضمن وحدات `Modules/`، وهو تناقض توثيقي.

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| مفاتيح Odoo الأربعة افتراضها `false` | `lavkoo:config/odoo.php:28-42` | VERIFIED |
| شاشات Odoo خلف `module:operational_costs` | `lavkoo:routes/admin/odoo-accounting.php:24-28` | VERIFIED |
| نوع المصدر الوحيد لـ Odoo قيد التكلفة | `lavkoo:app/Services/Accounting/Odoo/OdooPostingKey.php:32` | VERIFIED |
| المرجع `PKSA-COST-<id>-<version>` | `lavkoo:app/Services/Accounting/Odoo/OdooPostingKey.php:61` | VERIFIED |
| مفتاح فريد `(source_type, source_id, source_version)` | `lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:128` | VERIFIED |
| `enqueue()` بلا منادٍ إنتاجي | `lavkoo:app/Services/Accounting/Odoo/OdooOutbox.php:49` + بحث `rg "enqueue\("` | VERIFIED |
| السياج يُكتب قبل الإنشاء | `lavkoo:app/Services/Accounting/Odoo/OdooOutbox.php:113-142` | VERIFIED |
| الحالات القابلة للترحيل `actual`/`reconciled` | `lavkoo:app/Services/Accounting/Odoo/OdooDraftBuilder.php:60` | VERIFIED |
| القيد `move_type = entry` مع `company_id` | `lavkoo:app/Services/Accounting/Odoo/OdooEntryDraft.php:58-66` | VERIFIED |
| اختيار الشركة ورفض التعدّد بلا اختيار | `lavkoo:app/Services/Accounting/Odoo/OdooPreflightRun.php:148-176` | VERIFIED |
| اتصال Odoo واحد | `lavkoo:app/Http/Controllers/Admin/Accounting/OdooConnectionController.php:148-152` | VERIFIED |
| سرّ Odoo مشفّر | `lavkoo:app/Services/SalesChannels/Support/ChannelCredentialStore.php:130` | VERIFIED |
| منزلتان ثابتتان في Odoo | `lavkoo:app/Services/Accounting/Odoo/OdooMoney.php:33-34` | VERIFIED |
| `AccountingDestination` مربوط بوجهة فارغة | `lavkoo:app/Providers/SalesChannelsServiceProvider.php:47-48` | VERIFIED |
| مفاتيح التكاليف افتراضها `false` | `lavkoo:config/operational_costs.php:22-24` | VERIFIED |
| عملة أساس التكاليف من الإعداد وافتراضها `SAR` | `lavkoo:config/operational_costs.php:28` | VERIFIED |
| الطبقة تُخزَّن بعملة الأساس | `lavkoo:app/Services/OperationalCosts/InventoryCostLayerService.php:92` | VERIFIED |
| FIFO تحت قفل | `lavkoo:app/Services/OperationalCosts/InventoryCostLayerService.php:172-184` | VERIFIED |
| الإيراد غير القابل للتحويل لا يُحسب | `lavkoo:app/Services/OperationalCosts/Profit/ProfitabilityService.php:119-130` | VERIFIED |
| جدولة أوامر التكاليف مشروطة | `lavkoo:app/Console/ScheduleDefinition.php:118-121` | VERIFIED |
| `admin_id = 1` ثابت | `lavkoo:app/Utils/OrderManager.php:809-823` | VERIFIED |
| إدراج `order_transactions` عند التسليم النقدي | `lavkoo:app/Utils/OrderManager.php:289-307` | VERIFIED |
| `pending_amount` عند الدفع الرقمي | `lavkoo:app/Utils/OrderManager.php:2147-2168` | VERIFIED |
| لقطة العمولة وقيود الدفتر | `lavkoo:app/Utils/OrderManager.php:1120-1205` | VERIFIED |
| العمولة الموروثة `number_format(…, 2)` | `lavkoo:app/Utils/Helpers.php:649` | VERIFIED |
| سلسلة الرصيد لكل (بائع، نوع) | `lavkoo:app/Services/Marketplace/VendorLedger.php:74-80` | VERIFIED |
| عملة الدفتر `system_default_currency` | `lavkoo:app/Services/Marketplace/VendorLedger.php:227-241` | VERIFIED |
| عملة التسوية عملة أول قيد | `lavkoo:app/Services/Marketplace/SettlementEngine.php:102` | VERIFIED |
| تسوية مجدولة يومياً | `lavkoo:app/Console/ScheduleDefinition.php:35` | VERIFIED |
| تهدئة تغيير البنك | `lavkoo:app/Services/Marketplace/PayoutService.php:404-429` | VERIFIED |
| جسر السحب الموروث إلى الدفتر | `lavkoo:app/Http/Controllers/Admin/Vendor/VendorController.php:778-796` | VERIFIED |
| جداول بلا هجرة | `lavkoo:app/Services/Fulfillment/Readiness/Compatibility/RestoreSection.php:23-30` | VERIFIED |
| `country_type = 'single'` | `lavkoo:Modules/TaxModule/config/config.php:16` | VERIFIED |
| محلِّل الضريبة بلا دولة وكاشه عام | `lavkoo:Modules/TaxModule/app/Traits/VatTaxManagement.php:15-33` | VERIFIED |
| `tax_model` من علم واحد | `lavkoo:app/Utils/OrderManager.php:1086` | VERIFIED |
| سطور ضريبة الطلب | `lavkoo:app/Utils/OrderManager.php:1511-1535` | VERIFIED |
| تقرير ضريبة الإدارة على `order_transactions` | `lavkoo:Modules/TaxModule/app/Http/Controllers/Admin/Reports/AdminTaxReportController.php:124-130` | VERIFIED |
| حقول TIN على `shops` | `lavkoo:database/migrations/2025_06_12_202801_add_tin_fields_to_shops_table.php:15-17` | VERIFIED |
| هوية الفاتورة من الإعداد العام | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:613` | VERIFIED |
| المحافظ `DECIMAL(40,20)` | `lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:14-19` | VERIFIED |
| التكاليف `DECIMAL(20,6)` | `lavkoo:database/migrations/2026_11_07_100000_create_operational_cost_tables.php:116-120` | VERIFIED |
| مجموع محافظ البائعين في تقرير الإدارة | `lavkoo:app/Http/Controllers/Admin/ReportController.php:1107` | VERIFIED |
| التحويل العام في اللوحة | `lavkoo:app/Services/DashboardService.php:59-65` | VERIFIED |
| أول إيداع لوحدتَي Odoo والتكاليف | `lavkoo@c7a0cc8a`، `lavkoo@c8383df0` | VERIFIED |
| دفتر البائع ووحدة الضريبة من الإيداع الأول | `lavkoo@34dce658` | VERIFIED |

## 9. سجل التحقق المستقل

> مرور تحقق مستقل على هذه الوثيقة: تحقق من الاستشهادات بإعادة فتح مواضعها، وقراءة الكود عند `lavkoo@7f42777c`، وإعادة البحث في كل ادعاء غياب بأنماط مختلفة، وتنقيح الصياغة. لم يُجرَ أي تعديل على المستودعات، ولم يُنفَّذ أي كود من المستودع.

### 9.1 الأعداد

| البند | العدد |
|---|---|
| ادعاءات تم التحقق منها (قراءة كود أو بحث مكرَّر) | 83 |
| مؤكَّدة دون تعديل | 60 |
| مصحَّحة (استشهاد، أو نطاق، أو صياغة، أو سياق مضاد أُضيف) | 19 |
| مُبطلة أو مُعادة صياغتها لأن المطلق غير صحيح | 2 |
| غير قابلة للتحقق بالقراءة الساكنة | 2 |

### 9.2 التصحيحات

1. رأس الوثيقة: الـ HEAD المحلي عند إعادة التحقق وعدد ملفات الفرق عن الكود المدقَّق (ثلاثة ملفات توثيق فقط).
2. §1: حجم `Modules/TaxModule` أُعيد قياسه (6,310 سطر PHP).
3. §2.1: عمود `fact_kind` يعرّف قيمتين في النموذج، وتعليق الهجرة يذكر ستة أنواع إضافية بلا تعريف ولا استعمال.
4. §2.1: حالة `posted` في طابور Odoo تعني إنشاء المسوّدة في Odoo، لا الترحيل النهائي.
5. §2.2: بحث العقد يتضمّن عملة اختيارية (استشهاد مُصحَّح).
6. §2.3: استشهاد نافذة التهدئة يشمل مسار API v2.
7. §2.4: أُضيف مسار `OrderRepository` الموازي لكتابة `admin_wallets` (يستدعيه متحكّما الإدارة والبائع). الحكم بأن كل المواضع تستعمل `admin_id = 1` بقي صحيحاً.
8. §2.5: نمط البحث عن الفوترة الإلكترونية صُحّح في الوصف؛ النتيجة (لا ترميز فوترة إلكترونية) بقيت.
9. §3 (A5): أُضيف استشهاد جدول العمليات (`seller_id` في `operational_cost_entries`).
10. §4 (GCC-14): صيغة الصلاحيات تحصر التغطية بالشاشات المرصودة وتحيل إلى SEC-E13-01.
11. §4 (GCC-16): عُدّل وصف القرار بنص القرار في `DECISIONS.md`.
12. §4.2 بند 5: استشهاد `slot_key` صُحّح إلى تعريف `CostDraft::slotKey`.
13. §5: صف `CostLedger` عُدّل ليطابق الكتابة الفعلية.
14. §6 F01: أُضيف مسار `OrderRepository` إلى دليل الصف.
15. §6 F04: أُضيف غياب ملف التثبيت المذكور في تعليمات المستودع.
16. §6 F11: أُضيف استشهاد `seller_id` لجدول العمليات.
17. §6 F13: أُضيف تعارض التقريب (منزلتان في الموروث، أربع منازل في المحرّك حتى في مسار الرجوع).
18. §6 F18: نمط البحث الأصلي كُتب بمحرف رأسي مهرَّب فيعيد صفراً؛ الصيغة الصحيحة تعيد ملفاً واحداً بأسماء النماذج وملفين بأسماء الجداول.
19. §7 بند 10: أُضيف تناقض تعليمات المستودع بشأن `Modules/Auction`.

مُبطلان (صيغة أو استنتاج صحيح):
- ادعاء أن العقد «واحد لكل (`seller_id`، `provider_code`)» (§3 A11 وصف F12): الهجرة لا تحمل فرادة على هذا الثلاثي؛ الاختيار يتم بالسريان والإصدار. أُعيدت صياغته.
- ادعاء أن `CostLedger` هو الكاتب الوحيد لـ `operational_cost_entries` (§2.2): `AllocationService` يكتب `allocation_status`. أُعيدت صياغته.

### 9.3 بقايا غير محسومة

- E13-F14 (تحويل الوحدة في الجسر): يتوقّف على `currency_model` الإنتاجي، وهو UNKNOWN.
- عرض أرصدة الإدارة في اللوحة (§2.6): لم تُقرأ قوالب العرض التي تحوّل الأرقام؛ الحكم موسوم بالتحويل من الكود فقط.
- جداول النواة المالية في الإنتاج (E13-F04): UNKNOWN، وملف التثبيت غير موجود في هذه النسخة.
- `OrderRepository::manageWalletOnOrderStatusChange`: قُرئت مواضع الكتابة فيها ولم تُقرأ كل تفرّعات الحالات.

### 9.4 فجوات التغطية

- `StorageCostService` و`ReturnDecisionService` و`StockLocationResolver`: قراءة جزئية لمقاطع محددة.
- `SettlementEngine`: الشرط المتعلّق بحالة التسوية مؤكَّد؛ التدفق الكامل للتسوية لم يُقرأ سطراً سطراً.
- شاشات Blade للتقارير: عيّنة فقط، كما في §1.
- `OdooMappingController` و`OdooReconciliationController` وبقية ملفات Odoo الاثني عشر غير المذكورة: لم تُفحص، كما في §1.

### 9.5 الملحق المقيّد

- عدد البنود المنقولة إلى الملحق المقيّد: 4 (SEC-E13-01 إلى SEC-E13-04)، ولكل منها مؤشّر واحد في §6. نُقلت في مرور سابق، ولم تُضَف بنود جديدة في هذا المرور.
- لا يتضمّن هذا السجل تفاصيل أي من البنود المنقولة.
