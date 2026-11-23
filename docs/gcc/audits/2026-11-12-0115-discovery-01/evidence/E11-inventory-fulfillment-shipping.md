# E11 — المخزون والمجمّعات والحجوزات ومزوّدو التنفيذ والشحن (Inventory pools, reservations, fulfillment providers, shipping)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` (الكود المدقَّق؛ النسخة المحلية تحمل فوقه commits توثيقية فقط — `lavkoo@046d623d` و`lavkoo@98e795b5` عند لحظة التحقق المستقل — تضيف ملفات تحت `docs/gcc/` ولا تمسّ أي كود)، `lavkooapp@eedbbb8fd0303aa9fed5d4ff0ac45b3a409abf7a` و`lavkooappseller@8ea00922e44db3d69059d46892f8f4f84349b580` (بحث نصّي فقط عن أثر هذا المجال في التطبيقين).
> المستودع القديم `pharmacyksa98`: `UNKNOWN (missing access: pharmacyksa98)`.

## 1. النطاق وما تم فحصه

**التغطية:** المحور D (المخزون، التنفيذ، الشحن) في backend‏ Laravel.

| المجموعة | ما فُحص | الطريقة |
|---|---|---|
| `app/Services/Fulfillment/**` | 158 ملفاً (19,457 سطراً). قُرئت كاملةً الملفات الحاملة للمنطق (نحو 45 ملفاً: العقود السبعة، `ProviderRegistry`، `ProviderConnectionManager`، `FulfillmentRouter`، `ProviderOrderDispatcher`، `ShipmentTracker`، `FulfillmentStateMachine`، `WebhookEventProcessor`، `ReconciliationService`، `FulfillmentRecovery`، `FulfillmentCancellation`، `InventorySyncService`، `ProviderStockOwnership`، `ProductProviderAssignmentService`، `ProviderMappingService`، طبقة المال والعملة، `TorodPointAdapter` و`M5aznAdapter` وعملاؤهما وبُناة الحمولة). بقية الملفات (Readiness، Verification، معظم Support) قُرئت ترويساتها وجرى البحث فيها بالأنماط. | Read + rg |
| `app/Jobs/Fulfillment/**` و`app/Jobs/SalesChannels/DrainStockEvents.php` | 8 ملفات كاملة | Read |
| النماذج | النماذج الـ29 في قائمة المهمة كلها موجودة؛ قُرئ منها 20 كاملاً (لم تُقرأ: `ProviderCatalogItem`، `ProviderSyncState`، `ProviderFulfillmentEvent`، `ProviderApiLog`، `ProviderCity`، `ProviderCityAlias`، `ProviderCitySyncRun`، `ProviderPriceChange`، `ProductProviderChange` — عُرفت أعمدتها من الترحيل)، إضافةً إلى `ShippingZone`، `ShippingMethod`، `ProductStock`، `DeliverySyriaParcel`، `DeliverySyriaGovernorateRate` | Read |
| الترحيلات | 9 ترحيلات: `2026_11_05_100000`، `2026_11_05_100100`، `2026_11_06_100000`، `2026_11_06_100100`، `2026_11_07_100200` (اثنان)، `2026_11_07_100300`، `2026_11_10_100000`، `2026_11_13_100000`، وجزء العناوين من `2026_11_07_100000` | Read |
| `app/Services/SalesChannels/Inventory/**` و`Routing/**` | 43 ملفاً؛ قُرئ 16 منها كاملاً (الحجوزات، المجمّعات، القارئ، الـ outbox، محرّك التوجيه) | Read + rg |
| `app/Services/Marketplace/**` (جزء المخزون) | `InventoryService`، `WarehouseService`، `FulfillmentService`، `ShippingRateService`، `StockPolicy` كاملة؛ `PurchaseOrderService` و`ReturnLogisticsService` بالبحث | Read + rg |
| مسار الطلب الأساسي | مقاطع `app/Utils/OrderManager.php` (الأسطر 95–154 و1207–1415 و1545–2041)، `CartManager`، `OrderRepository::update`، حرّاس الحالة في المتحكمات الخمسة | Read |
| الإعدادات والمسارات والجدولة | `config/fulfillment.php` كاملاً، مقطع المخزون من `config/sales_channels.php`، `routes/admin/fulfillment.php`، `routes/rest_api/fulfillment_webhooks.php`، `routes/rest_api/delivery_syria.php`، `app/Console/ScheduleDefinition.php`، أربعة أوامر Fulfillment | Read |
| الوثائق | `docs/integrations/README.md`، `capability-matrix.md`، `cod.md`، `operations.md`، `risks.md`، `security-findings.md` (ملخّصه في الملحق المقيّد فقط)، ومقاطع من `torod-point.md` | Read |
| الاختبارات | أسماء دوال الاختبار في `tests/Feature/Fulfillment` (52 ملفاً، 1010 دوال)، `tests/Unit/Fulfillment` (14 ملفاً، 127 دالة)، `tests/Feature/Concurrency` (9 ملفات، 45 دالة). **لم يُشغَّل أي اختبار.** | grep |

**أنماط البحث الرئيسة:** `current_stock`، `product_stocks|ProductStock`، `reserve\(|ReservationService|InventoryReservation::`، `country|'SA'|saudi|966|SAR|KSA`، `round\(|number_format\(|\* 100`، `decimals\(\)`، `Cache::|WithoutOverlapping`، `warehouse_id|Warehouse::`، `third_party_delivery|delivery_service_name`، `delivery_country_restriction`، `Schema::create\('(warehouses|…)'`، `expire|ttl`.

**ما لم يُفحص:** طبقة التشغيلات والصلاحية (`ProductBatch`، `BatchService`، `BatchController`) وواجهة مخزون البائع (`SellerInventoryController` وميزة `inventory` في تطبيق البائع) — رُصد وجودها في التحقق المستقل فقط (انظر E11-F20)؛ مسارات كتابة المخزون في POS ومتحكمات المنتج وواجهتي البائع؛ منطق حساب تكلفة الشحن بأنواعه (`order_wise` / `category_wise` / `product_wise`) ودورة المندوب (`DeliveryMan`) وشاشات الشحن في التطبيقين؛ قوالب Blade لصفحات الإدارة؛ محتوى فئات `Readiness/Compatibility` و`Readiness/Staging` و`Verification` بالتفصيل؛ كامل `M5aznProductImporter` و`TorodCityDirectory` و`ProviderCityReview` و`ProviderSettingsForm`؛ بقية `SalesChannels` (النشر، الطلبات، الموصّلات) لأنها مجال آخر؛ استعمال `OperationalCosts` لمواقع المخزون؛ أجسام الاختبارات (عدا الحزام `ConcurrencyHarnessTest`)؛ قاعدة البيانات الحية و`.env` ولوحات المزوّدين (غير متاحة).

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 أربعة أنظمة فرعية لا نظام واحد

أهم حقيقة بنيوية في هذا المجال أن كلمات «مخزون / مستودع / تنفيذ / توجيه» تشير في الكود إلى أربعة أنظمة فرعية متجاورة، لكلٍّ جداوله وغرضه (VERIFIED):

| # | النظام الفرعي | الكود | الجداول | يخدم |
|---|---|---|---|---|
| 1 | تنفيذ المزوّدين (M5AZN / Torod Point) | `app/Services/Fulfillment/**` (158 ملفاً)، `app/Jobs/Fulfillment/**`، خمسة متحكمات في `app/Http/Controllers/Admin/Fulfillment`، عشرة ملفات أوامر (تسعة `fulfillment:*` وأمر `address:discover-provider-cities`) | 12 جدول `provider_*` و`product_provider_changes` وعمود `products.fulfillment_provider` و`integration_settings` | طلبات المتجر (والطلبات الداخلية المنشأة لطلبات القنوات) |
| 2 | مخزون القنوات: مواقع، مجمّعات، حجوزات، outbox، توجيه | `app/Services/SalesChannels/Inventory/**` (37 ملفاً) و`Routing/**` (6 ملفات) | `inventory_locations`، `inventory_location_balances`، `inventory_source_policies`، `inventory_reservations`، `inventory_pools`، `channel_stock_events`، `fulfillment_routing_rules`، `fulfillment_routing_decisions` | كميات قوائم Amazon / Trendyol / Noon وطلبات القنوات |
| 3 | طبقة «Phase 3, Stage C» (مستودعات، مشتريات، مرتجعات، pick/pack، مناطق شحن) | `app/Services/Marketplace/{InventoryService,WarehouseService,PurchaseOrderService,ReturnLogisticsService,FulfillmentService,ShippingRateService}.php` | `warehouses`، `warehouse_stock`، `stock_movements`، `suppliers`، `purchase_orders`، `purchase_order_items`، `order_fulfillments`، `return_shipments`، `shipping_zones` — **بلا ترحيلات في المستودع** (انظر E11-F09) | لوحة الإدارة وواجهة البائع |
| 4 | مخزون المنصّة الأساسي والشحن | `app/Utils/OrderManager.php`، `app/Utils/CartManager.php`، `ShippingMethod`، `DeliveryMan`، تكامل Delivery Syria | `products.current_stock`، `products.variation` (JSON)، `order_details.is_stock_decreased`، `shipping_methods`، `shipping_types`، `category_shipping_costs`، `cart_shippings`، `delivery_country_codes`، `delivery_zip_codes` | كل طلب |

الحجوزات والمجمّعات (النظام 2) **لا تُستخدم في checkout المتجر**؛ هي آلية لتقسيم رقم مخزون واحد بين قوائم القنوات (انظر 2.7). ومحرّك `FulfillmentRoutingRule/Decision` يخصّ **طلبات القنوات** لا طلبات المتجر (انظر 2.9).

أصل هذه الأنظمة في السجل: النظام 1 دخل مع `lavkoo@c8191540` (2026-11-05)، المجمّعات مع `lavkoo@f5b343a1` (2026-11-07)، الـ outbox مع `lavkoo@eecb8a2f`، بينما النظامان 3 و4 (وDelivery Syria) جزء من الـ commit الأول `lavkoo@34dce658`.

### 2.2 خريطة `app/Services/Fulfillment` (158 ملفاً)

| النطاق الفرعي | الملفات | الدور |
|---|---|---|
| الجذر | 41 | المنطق المحايد تجاه المزوّد: التوجيه (`FulfillmentRouter`)، الإرسال (`ProviderOrderDispatcher`)، الكاتب الوحيد للحالة (`ShipmentTracker`) وآلة الحالات، المال وCOD (`FulfillmentMoney`، `CodPolicy`، `CodAllocator`، `CheckoutCodGuard`، `ProviderOrderShare`)، الاسترداد والمطابقة والإلغاء (`FulfillmentRecovery`، `ReconciliationService`، `FulfillmentCancellation`)، المخزون (`InventorySyncService`، `StorefrontStockRefresher`، `ProviderStockOwnership`)، الربط والإسناد (`ProviderMappingService`، `ProductProviderAssignmentService`)، مطابقة المدن، الإعدادات، العرض للعميل |
| `Contracts` | 7 | `ProviderAdapter` والقدرات الاختيارية `HandlesWebhooks` و`ReconcilesSubmissions` و`ProvidesShippingLabel`، وثلاثة وصلات بين الوحدتين: `ReportsChannelHolds`، `NotesStockChanged`، `WritesStorefrontStock` |
| `Data` | 16 | كائنات نتائج غير قابلة للتغيير (`SubmissionResult`، `CancellationResult`، `MoneyAssessment`، `CollectionPlan`، `CityResolution`، `NormalizedEvent`…) |
| `Exceptions` | 3 | `ProviderUnavailableException`، `UnsupportedCapabilityException`، `CityMappingRefusedException` |
| `Providers/M5azn` | 5 | المحوّل، العميل (تسع عمليات موثّقة)، خرائط الحقول، باني الحمولة، مستورد المنتجات |
| `Providers/TorodPoint` | 11 | المحوّل، عميل Fulfillment API v5، عميل Lastmile API v2، باني الحمولة، خريطة الحالات، دليل المدن ومحلّلها، تأكيد حقل المخزون، إثبات «رحلة رقم الفاتورة» |
| `Readiness` | 7 | `FulfillmentPreflight` (فحص قراءة فقط للبيئة) وقائمة الجاهزية للإرسال الآلي `GoLive*` |
| `Readiness/Compatibility` | 22 | تقرير توافق المخطط الحي (قراءة فقط) لأن الجداول الأساسية تأتي من dump التثبيت |
| `Readiness/Staging` | 10 | خطة تعقيم نسخة staging وجرد القنوات الصادرة |
| `Support` | 26 | النقل الوحيد إلى الشبكة `ProviderHttp` وسجل العمليات `ExternalOperations`، `ProviderConfig`، العملة (`CurrencyCode`، `ProviderCurrency`، `ExchangeRate`)، التنقيح (`PiiRedactor`، `SecretRedactor`)، `FieldMap`، `SaudiPhone`، `ArabicText`، `AppTime`، `LegacyColumnWidener` |
| `Verification` | 10 | التحقق الحي للقراءة فقط وحفظ «شكل» الإجابات كدليل |

### 2.3 المحوّلات والعقود

- المزوّدون المعروفون تعداد مغلق من قيمتين: `m5azn` و`torod_point` — `lavkoo:app/Enums/Fulfillment/ProviderCode.php:9-13`. القيمة `NULL` في المنتج تعني «داخلي/افتراضي» (التعليق في الأسطر 5–8 من الملف نفسه). لا يوجد محوّل ثالث (VERIFIED: `ProviderRegistry::codes()` يعيد `ProviderCode::cases()` — `lavkoo:app/Services/Fulfillment/ProviderRegistry.php:22-25`).
- المحوّل يُحلّ من الإعداد حسب الرمز: `config('fulfillment.providers.<code>.adapter')` — `lavkoo:app/Services/Fulfillment/ProviderRegistry.php:27-40`؛ والتعريفان في `lavkoo:config/fulfillment.php:126-153` (M5AZN، الدور `dropshipping_supplier`) و`lavkoo:config/fulfillment.php:155-168` (Torod Point، الدور `fulfillment_warehouse`).
- العقد الأساسي `ProviderAdapter` (`code`، `readiness`، `testConnection`، `preflight`، `submit`، `cancel`، `fetchStatus`، `mapStatus`) — `lavkoo:app/Services/Fulfillment/Contracts/ProviderAdapter.php:23-56`. `TorodPointAdapter` ينفّذ أربعة عقود (`lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:65`)، و`M5aznAdapter` ينفّذ `ProviderAdapter` و`ReconcilesSubmissions` فقط (`lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznAdapter.php:50`) لأن وثائق M5AZN لا تذكر webhooks ولا إلغاء (`lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznAdapter.php:224-227`).
- المسار الرئيسي لنداءات واجهات المزوّدين (API) هو النقل المشترك `ProviderHttp::send` (بحث `Http::|curl_|Guzzle|new Client` في `app/Services/Fulfillment` و`app/Jobs/Fulfillment`). النقل يرفض أي نداء والتكامل مطفأ، ويرفض كل «كتابة» ومفتاح الكتابة الخارجية مطفأ، ولا يعيد محاولة الكتابة أبداً — `lavkoo:app/Services/Fulfillment/Support/ProviderHttp.php:47-58` و`lavkoo:app/Services/Fulfillment/Support/ProviderHttp.php:71-79`. تصنيف العملية (قراءة/دخول/كتابة) يأتي من سجل مغلق، وأي عملية غير مسجّلة تُعدّ كتابة — `lavkoo:app/Services/Fulfillment/Support/ExternalOperations.php:25-52` و`lavkoo:app/Services/Fulfillment/Support/ExternalOperations.php:70-78`.

`SEC-E11-07: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- تكامل **Delivery Syria** (2.16) خارج هذا الإطار كلياً: عميل مستقل يُستدعى من داخل `OrderManager`.

### 2.4 حسابات المزوّدين وبيانات الاعتماد

- التخزين: صفّ واحد في `integration_settings` لكل رمز مزوّد؛ القراءة بـ `where('provider', $code)->first()` — `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:71-89`. العمود `provider` مفهرس وليس فريداً — `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:26-37`. إذن **حساب واحد لكل مزوّد** بحكم طريقة القراءة لا بحكم قيد في القاعدة. والجدول نفسه مشترك مع تكاملات أخرى (التعليق في الترحيل يذكر أن تكامل Chatwoot يقرؤه ويكتبه عبر النموذج نفسه — `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:23-25`)، فأي بُعد حساب يُضاف إليه يمسّ أكثر من مجال.
- الأسرار تُخزَّن JSON مشفّراً بـ `Crypt::encryptString` في العمود `credentials`، والإعدادات غير السرية JSON صريح في `settings` — `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:138-143`. مفاتيح الأسرار المقبولة قائمة مغلقة لكل مزوّد — `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:30-35`. فشل فكّ التشفير يُعامل «غير مُعدّ» ولا يرمي — `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:249-264`.
- الحفظ معاملة واحدة تحت `lockForUpdate` مع قيد تدقيق — `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:108-152`. الأسرار لا تظهر في `__debugInfo` ولا في `toDisplayArray` — `lavkoo:app/Services/Fulfillment/Support/ProviderConfig.php:80-95`.
- رمز دخول M5AZN (access token) يُخزَّن مع الأسرار المشفّرة ويُجدَّد قبل انتهائه — `lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznClient.php:145-158`.
- معرّف شركة Torod لدى LogesTechs قيمة افتراضية واحدة في الإعداد — `lavkoo:config/fulfillment.php:165-167`؛ وإثبات «رحلة رقم الفاتورة» مربوط بالحساب (company id + base URL) فيسقط إذا تغيّر الحساب — `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodInvoiceRoundTrip.php:49-60`.
- قيم الإنتاج الفعلية (أي المزوّدين مفعّل، هل المفاتيح مضبوطة): `UNKNOWN` — تحتاج قراءة `integration_settings` و`.env` في الخادم.

### 2.5 مفاتيح التشغيل والإيقاف وقيمها الافتراضية في الكود

| المفتاح | الموضع | الافتراضي | الأثر |
|---|---|---|---|
| `FULFILLMENT_INTEGRATIONS_ENABLED` → `fulfillment.enabled` | `lavkoo:config/fulfillment.php:26` | `false` | لا نداء خارجي من أي نوع (`lavkoo:app/Services/Fulfillment/Support/ProviderHttp.php:47-49`)؛ الأوامر تنتهي بلا عمل (`lavkoo:app/Console/Commands/FulfillmentRecoverCommand.php:19-23`) |
| `FULFILLMENT_EXTERNAL_WRITES_ENABLED` | `lavkoo:config/fulfillment.php:34` | `false` | وضع قراءة فقط يفرضه النقل نفسه (`lavkoo:app/Services/Fulfillment/Support/ProviderHttp.php:52-58`) |
| `FULFILLMENT_LOCK_PROVIDER_WHILE_ORDERS_IN_FLIGHT` | `lavkoo:config/fulfillment.php:48` | `false` | عند `true` يُرفض تغيير مزوّد المنتج ما دامت له طلبات جارية |
| `FULFILLMENT_STOP_SELLING_WHEN_A_PRODUCT_LEAVES_A_PROVIDER` | `lavkoo:config/fulfillment.php:57` | `true` | تصفير مخزون المتجر عند خروج المنتج من مزوّد بلا رقم بديل |
| `FULFILLMENT_CURRENCY` | `lavkoo:config/fulfillment.php:59` | `SAR` | عملة كل ما يُرسل للمزوّدين (2.13) |
| `FULFILLMENT_QUEUE_CONNECTION` / `FULFILLMENT_QUEUE` | `lavkoo:config/fulfillment.php:71-74` | `null` / `fulfillment` | طابور واحد عام |
| `FULFILLMENT_ALLOW_MIXED_COD` | `lavkoo:config/fulfillment.php:111` | `false` | تقسيم COD بين محصّلين |
| لكل مزوّد (قاعدة البيانات): `enabled` | `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:84` | `false` | شرط `canCall()` الثاني (`lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:66-69`) |
| لكل مزوّد: `dispatch_enabled`، `auto_dispatch`، `dry_run`، `product_sync_enabled`، `inventory_sync_enabled`، `webhooks_enabled`، `push_products_enabled`، `cod_verified` | `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:103-135` و`lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznAdapter.php:72-107` | الكل `false` عدا `dry_run = true` و`cod_supported = true` و`verify_terminal_webhooks = true` | تُقرأ من القاعدة عند كل نداء |
| `SALES_CHANNELS_ENABLED` | `lavkoo:config/sales_channels.php:23` | `false` | وحدة القنوات كلها |
| `sales_channels.stock.allocation.enabled` / `outbox.enabled` | `lavkoo:config/sales_channels.php:288` و`lavkoo:config/sales_channels.php:320` | `true` / `true` | تقسيم المجمّع والـ outbox (للتراجع فقط) |
| `sales_channels.inventory.own_warehouse_source` | `lavkoo:config/sales_channels.php:417` | `manual` | مصدر كمية «المستودع الخاص» |
| `sales_channels.inventory.hold_channel_units_from_storefront` | `lavkoo:config/sales_channels.php:426` | `true` | خصم حجوزات القنوات من رقم المتجر |
| `sales_channels.managed_dispatch_enabled` | `lavkoo:config/sales_channels.php:431` | `false` | إنشاء شحنة مزوّد لطلب قناة |

الإرسال الفعلي يحتاج ستة شروط مجتمعة (مفتاحا البيئة، تفعيل المزوّد، «إرسال الطلبات»، إطفاء dry run، ولـ Torod إثبات رحلة رقم الفاتورة، ولغير الإرسال اليدوي `auto_dispatch`) — `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:197-234`، وموثّق في `lavkoo:docs/integrations/README.md:67-75`. لحظة «go-live» تُسجَّل والطلبات السابقة لها تُحجز ولا تُرسل بأثر رجعي — `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:209-221`.

كل المسارات التي يمسّ بها مسار الطلب هذه الميزة تسأل أولاً هل نُفّذ الترحيل، فتبقى المنصّة كما كانت قبل الترحيل — `lavkoo:app/Services/Fulfillment/FulfillmentSchema.php:20-29` و`lavkoo:app/Http/Middleware/EnsureFulfillmentMigrated.php:17-24`.

### 2.6 المخزون: مصدر الحقيقة وكيف يقرؤه المتجر

**مصدر الحقيقة للبيع هو `products.current_stock`** (ولمنتج ذي متغيّرات: الحقل `qty` داخل JSON‏ `products.variation`). الأدلة (VERIFIED):

- السلة ترفض الإضافة بمقارنة مباشرة مع `current_stock` أو `qty` المتغيّر — `lavkoo:app/Utils/CartManager.php:422-424` و`lavkoo:app/Utils/CartManager.php:474-478`؛ والفحص قبل وضع الطلب `product_stock_check` — `lavkoo:app/Utils/CartManager.php:982-1009`. **لا حجز عند السلة** (بحث `reserv|hold` في `CartManager`/`CartService`/`CartController`: لا نتيجة متعلقة بالمخزون).
- عند إنشاء الطلب: تُقفل صفوف المنتجات مرتّبةً بالمعرّف داخل معاملة واحدة تُعاد حتى 3 مرات — `lavkoo:app/Utils/OrderManager.php:1582-1602` و`lavkoo:app/Utils/OrderManager.php:1733`؛ ثم خصم ذرّي مشروط `where('current_stock', '>=', qty)->decrement()` يرمي `InsufficientStockException` إذا لم يُخصم شيء — `lavkoo:app/Utils/OrderManager.php:1345-1356`؛ والمتغيّر يُحرس على حدة — `lavkoo:app/Utils/OrderManager.php:1291-1321`. الرفض يُلغي المعاملة كلها ويعيد قائمة فارغة — `lavkoo:app/Utils/OrderManager.php:1745-1756`.
- **فجوة في التصميم:** لا يوجد حجز للمخزون خلال نافذة الدفع (انظر SEC-E11-06).

`SEC-E11-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- الإرجاع عند الإلغاء/الفشل/الإرجاع: قلبٌ مشروط لعلم `order_details.is_stock_decreased` هو نفسه حارس عدم التكرار، ثم زيادة ذرّية تحت قفل المنتج — `lavkoo:app/Utils/OrderManager.php:95-154`، وتوأمه المستعمل من لوحة الإدارة بالانضباط نفسه — `lavkoo:app/Repositories/OrderRepository.php:637-697`.
- كل بيع يُسجَّل حركةً في `stock_movements` بلا رمي — `lavkoo:app/Utils/OrderManager.php:1383-1398`؛ والدالة `InventoryService::record` تتخطّى الكتابة إن لم يوجد الجدول — `lavkoo:app/Services/Marketplace/InventoryService.php:91-125`.
- `product_stocks`: لا ترحيل ينشئه في المستودع (ترحيلان يعدّلان عمود `price` فقط — `lavkoo:database/migrations/2026_01_22_164309_update_product_stocks_price_column.php:14`)، والنموذج `ProductStock` فارغ — `lavkoo:app/Models/ProductStock.php:7-10`، والمرجع الوحيد إليه علاقة `Product::stocks` — `lavkoo:app/Models/Product.php:262`، ولوحة المراقبة تنصّ على أن لا شيء في `app/` يكتبه وأن الكمية الحية هي JSON المتغيّرات — `lavkoo:app/Services/Monitoring/Panels/InventoryIntegrityPanel.php:514-517`. (بحث `ProductStock::|productStocks|product_stocks` في `app/`: لا كاتب.)

**من يكتب `current_stock` غير checkout؟**

1. **مزامنة المزوّد** (المنتجات المملوكة لمزوّد): كاتب مسار المزامنة الوحيد `InventorySyncService::writeStoreStock` (وليس الكاتب الوحيد لـ`current_stock` لهذه المنتجات: خصم checkout نفسه، وواجهتا البائع v2/v3، واستلام أوامر الشراء وإعادة تخزين المرتجعات تكتب العمود أيضاً) يقفل صف المنتج ويحسب `available = max(0, رقم المزوّد − الموعود)` حيث «الموعود» = سطور طلبات المتجر التي لم يتسلّمها المزوّد بعد + حجوزات القنوات، ثم يكتب `current_stock` (و`qty` المتغيّر) ويسجّل حركة `provider_sync` — `lavkoo:app/Services/Fulfillment/InventorySyncService.php:284-351`. رقم المزوّد نفسه يُحفظ في `provider_product_mappings.provider_stock` مع `provider_stock_synced_at` و`stock_source` — `lavkoo:app/Services/Fulfillment/InventorySyncService.php:232-239`. تعريف «لم يتسلّمه المزوّد بعد» — `lavkoo:app/Services/Fulfillment/InventorySyncService.php:143-160`.
   - Torod: لا يُكتب إلا لمنتج **مُسند** إلى Torod وله ربط معتمد ورقم صحيح غير سالب، وبعد تأكيد إداري لحقل المخزون — `lavkoo:app/Services/Fulfillment/InventorySyncService.php:101-128` و`lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:710-760`.
   - M5AZN: من تغذية `qty-changed` وبخريطة حقول موثّقة — `lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznAdapter.php:441-476`.
   - الدورية: يستيقظ المجدول كل خمس دقائق وكل مزوّد يحدّد فترته (الافتراضي 30 دقيقة، الأدنى 5) — `lavkoo:app/Services/Fulfillment/ProviderSyncService.php:25-28` و`lavkoo:app/Console/ScheduleDefinition.php:96`.
2. **من يملك الرقم؟** `ProviderStockOwnership` يقرّر أن مستودع Torod (وحده، ثابت في الكود) هو المرجع لمخزون المنتج المسند إليه ما دام رقمه حديثاً، فتُتجاهل الأرقام المكتوبة يدوياً — `lavkoo:app/Services/Fulfillment/ProviderStockOwnership.php:28` و`lavkoo:app/Services/Fulfillment/ProviderStockOwnership.php:49-68`؛ ويرفض التعديل اليدوي في `InventoryService::adjust` — `lavkoo:app/Services/Marketplace/InventoryService.php:50-53`.
3. **تغيير مزوّد المنتج**: يُعاد كتابة المخزون من أرقام المالك الجديد المخزّنة، وإن لم يكن له رقم يُصفَّر المخزون ويُسجَّل الرقم السابق — `lavkoo:app/Services/Fulfillment/ProductProviderAssignmentService.php:110-132`.
4. استلام أمر شراء ومرتجع قابل لإعادة التخزين وتعديل يدوي وعمليات بائع — عبر `InventoryService` و`PurchaseOrderService` و`ReturnLogisticsService` (تحت قفل المنتج؛ `lavkoo:app/Services/Marketplace/InventoryService.php:36-85`).
5. `WarehouseService` **لا** يمسّ `current_stock` إطلاقاً: يقسّمه إلى مواقع فقط — `lavkoo:app/Services/Marketplace/WarehouseService.php:13-17`.

**حدّ الفحص:** `current_stock` يرد في 291 سطراً ضمن 74 ملفاً في `app/`، ومنها 27 ملفاً فيها أنماط كتابة (`decrement`/`increment`/إسناد). فُحص من الكاتبين: `OrderManager`، `OrderRepository`، `InventorySyncService`، `ProductProviderAssignmentService`، `ProviderStockOwnership`، `InventoryService`، وبالبحث `PurchaseOrderService` و`ReturnLogisticsService` و`M5aznProductImporter`. **لم تُفحص** كتابات متحكمات المنتج (الإدارة، البائع، واجهتا البائع v2 وv3)، نقاط البيع POS الثلاث، تعديل الطلب (`OrderEditService`، `OrderEditManager`)، `ProductService`، و`BulkStockOperation`.

`SEC-E11-08: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`

**العلاقة بين `current_stock` والمجمّعات:** المجمّع لا يخزّن كمية؛ يقرأ الرقم «الخام» من مصدر الموقع عند الحساب (2.7). لموقع المزوّد يُقرأ `provider_product_mappings.provider_stock` لا `current_stock`؛ ولـ«المستودع الخاص» في الوضع `platform` يُقرأ `current_stock` قراءةً عابرة بلا نسخ — `lavkoo:app/Services/SalesChannels/Inventory/LocationStockReader.php:50-59` و`lavkoo:app/Services/SalesChannels/Inventory/LocationStockReader.php:158-181`. والمزامنة العكسية (حجز قناة ← رقم المتجر) تتمّ بإعادة حساب لا بخصم عبر الواجهة `WritesStorefrontStock` — `lavkoo:app/Services/Fulfillment/StorefrontStockRefresher.php:50-80`.

### 2.7 المجمّعات والحجوزات وهوامش الأمان (وحدة القنوات)

**المفاتيح:**

| الجدول | المفتاح | ملاحظات | الدليل |
|---|---|---|---|
| `inventory_locations` | `code` فريد | `seller_id`، `type`، `provider`، `sales_channel_account_id`، `marketplace_id`، `source_of_truth`، `storefront_sellable`، `publishable_channels` — **لا عمود دولة** | `lavkoo:database/migrations/2026_11_07_100200_create_inventory_and_routing_tables.php:20-40` |
| `inventory_location_balances` | (`location_id`, `sku`, `variant_key`) | للمواقع التي مرجعها قناة أو إدخال يدوي فقط؛ مخزون المزوّد «لا يُنسخ هنا» | `lavkoo:database/migrations/2026_11_07_100200_create_inventory_and_routing_tables.php:42-62` و`lavkoo:database/migrations/2026_11_07_100200_create_inventory_and_routing_tables.php:12-14` |
| `inventory_source_policies` | (`account_id`, `product_id`, `variant_key`) | سياسة قناة: من أي موقع تأتي كمية القائمة، `safety_buffer`، `max_publish_quantity` | `lavkoo:database/migrations/2026_11_07_100200_create_inventory_and_routing_tables.php:64-82` |
| `inventory_reservations` | `reservation_key` فريد | الحالة `reserved` / `committed` / `released`؛ مع `sales_channel` و`account_id` و`applied_to_platform_at` | `lavkoo:database/migrations/2026_11_07_100200_create_inventory_and_routing_tables.php:84-104` و`lavkoo:database/migrations/2026_11_10_100000_add_stock_allocation.php:62-64` |
| `inventory_pools` | (`location_id`, `product_id`, `variant_key`) | صفّ القفل للتقسيم؛ `storefront_reserve_mode/value`، `safety_buffer`، `version`، `fingerprint`، `deficit` | `lavkoo:database/migrations/2026_11_10_100000_add_stock_allocation.php:28-54` |
| `provider_product_mappings` | (`provider`, `external_id`, `external_variant_id`) و(`provider`, `product_id`, `variant_key`) | عنصر المزوّد يُربط بمنتج متجر واحد فقط | `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:244-248` |

**معادلة المجمّع:** `Net = max(0, P − Hs − Hc − B)` و`deficit = max(0, Hs + Hc − P)` حيث P رقم الموقع، Hs وحدات طلبات المتجر التي لم يحسبها المزوّد بعد، Hc وحدات طلبات القنوات المفتوحة، B هامش الأمان — `lavkoo:app/Services/SalesChannels/Inventory/PoolFigures.php:40-46` و`lavkoo:app/Services/SalesChannels/Inventory/PoolNet.php:46-73`. المجمّع «يُرفض» (لا يعرض شيئاً) إذا كان مخزونه مخزون المتجر نفسه أو طُلب بلا مفتاح متغيّر لمنتج ذي متغيّرات — `lavkoo:app/Services/SalesChannels/Inventory/PoolNet.php:75-83`. والكمية لا تُنشر إلا إذا كانت `confirmed` أو `manual`، والمؤكَّدة تصير `stale` بعد 180 دقيقة — `lavkoo:app/Services/SalesChannels/Inventory/LocationStockReader.php:228-236` و`lavkoo:config/sales_channels.php:280`.

**هوامش الأمان الموجودة (ستة):** هامش المجمّع (`inventory_pools.safety_buffer` — `lavkoo:app/Services/SalesChannels/Inventory/Allocation/PoolAllocationService.php:68`)؛ هامش القائمة/السياسة و`max_publish_quantity` (`lavkoo:app/Services/SalesChannels/Publishing/PublishPolicyResolver.php:25-35`)؛ «احتياطي المتجر» نسبةً أو عدداً (`lavkoo:app/Services/SalesChannels/Inventory/Allocation/PoolAllocationService.php:139-146`، الافتراضي `percent` بقيمة 0 في `lavkoo:config/sales_channels.php:291-292`)؛ هامش التأخّر (`lavkoo:config/sales_channels.php:299-300`)؛ منع الرفع بعد عجز لعدد دورات (`lavkoo:config/sales_channels.php:303`)؛ ونافذة «حداثة» رقم المستودع قبل الحجز لنقص المخزون (ست ساعات — `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:1066-1078`).

**دورة حياة الحجز** (طلبات القنوات فقط — الموضع الوحيد الذي يستدعي `reserve()` هو `lavkoo:app/Services/SalesChannels/Inventory/ChannelOrderReservations.php:144`؛ بحث `reservations->reserve\(|InventoryReservation::` في `app/`):

| الطور | الشرط | الدليل |
|---|---|---|
| إنشاء `reserved` | طلب قناة مفتوح علينا تنفيذه؛ المفتاح `channel-order:{mapping}:{line}`؛ التكرار لا ينشئ صفاً ثانياً والكمية تتبع السطر | `lavkoo:app/Services/SalesChannels/Inventory/ReservationService.php:23-68` و`lavkoo:app/Services/SalesChannels/Inventory/ChannelOrderReservations.php:158-161` |
| تأكيد `committed` | رُبطت الشحنة أو شُحن/سُلّم الطلب؛ يبقى محسوباً إلى أن يصل رقم مخزون مأخوذ بعد لحظة التأكيد | `lavkoo:app/Services/SalesChannels/Inventory/ReservationService.php:71-80` و`lavkoo:app/Services/SalesChannels/Inventory/ReservationService.php:130-151` |
| إطلاق `released` | إلغاء/فشل/إرجاع، أو أن طلباً داخلياً يحسب الوحدات بدلاً منه | `lavkoo:app/Services/SalesChannels/Inventory/ReservationService.php:103-120` و`lavkoo:app/Services/SalesChannels/Inventory/ChannelOrderReservations.php:120-150` |
| إعادة فتح | فكّ ربط الشحنة أو توقّف الطلب الداخلي عن حساب الوحدات | `lavkoo:app/Services/SalesChannels/Inventory/ReservationService.php:87-101` |
| انتهاء زمني | **غير موجود** (بحث `expire\|expires_at\|ttl` في `app/Services/SalesChannels/Inventory`: لا شيء للحجوزات)؛ الحجز القديم يُعرض للإنسان بعد `aged_hold_days` والمُطلق يُحذف بعد مدة | `lavkoo:config/sales_channels.php:309-311` و`lavkoo:app/Console/Commands/SalesChannelsPruneCommand.php:36-38` |

**عضوية المجمّع:** كل قائمة مربوطة بالمنتج والمتغيّر، مفعّل لها نشر المخزون، وموقعها هو موقع المجمّع — بلا شرط دولة أو marketplace (بحث `country|marketplace` في `app/Services/SalesChannels/Inventory/Allocation/*.php`: لا نتيجة) — `lavkoo:app/Services/SalesChannels/Inventory/Allocation/PoolMembers.php:24-39`. وحسابات القنوات تحمل اليوم بُعداً جغرافياً خاصاً بها لا يقرؤه المجمّع: `market` و`marketplace_ids` على الحساب (`lavkoo:app/Models/SalesChannelAccount.php:25-27`) وإعداد `country_code` لحساب noon (`lavkoo:app/Services/SalesChannels/Noon/NoonConnector.php:513`).

`SEC-E11-09: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`

التقسيم بين القوائم له كاتب واحد: `PoolAllocationService::allocate` يأخذ صف المجمّع `FOR UPDATE` ثم يقرأ المدخلات، ولا يقفل `products` أبداً تفادياً للتشابك مع checkout — `lavkoo:app/Services/SalesChannels/Inventory/Allocation/PoolAllocationService.php:43-59` و`lavkoo:app/Services/SalesChannels/Inventory/Allocation/PoolAllocationService.php:62-98`.

### 2.8 الـ outbox والأحداث

- **outbox المخزون** (`channel_stock_events`): صفّ يُكتب في **المعاملة نفسها** التي تغيّر الحجز/الرقم، لا يحمل كمية بل «نية» إعادة الحساب — `lavkoo:database/migrations/2026_11_13_100000_create_channel_stock_events_table.php:36-80` و`lavkoo:app/Services/SalesChannels/Inventory/Outbox/StockEventOutbox.php:36-67`. المطالبة بدفعة عبارة UPDATE واحدة فلا تأخذها عمليتان — `lavkoo:app/Services/SalesChannels/Inventory/Outbox/StockEventOutbox.php:74-90`؛ الفاشل يُعاد بتراجع ثم يصير `dead` ولا يُحذف — `lavkoo:app/Services/SalesChannels/Inventory/Outbox/StockEventOutbox.php:128-162`. العامل `DrainStockEvents` (محاولة واحدة، قفل عدم تداخل) — `lavkoo:app/Jobs/SalesChannels/DrainStockEvents.php:33-45`، وشبكة الأمان `channels:stock-events` كل دقيقة — `lavkoo:app/Console/ScheduleDefinition.php:116`.
- مزامنة المزوّد تكتب ملاحظة الـ outbox داخل معاملة كتابة الرقم نفسها — `lavkoo:app/Services/Fulfillment/InventorySyncService.php:257-282` و`lavkoo:app/Services/SalesChannels/Inventory/Outbox/ProviderFigureNotes.php:28-47`. الربط بين الوحدتين يتمّ بإعادة ربط الواجهات في `lavkoo:app/Providers/SalesChannelsServiceProvider.php:40-46`، وبدونها يُستعمل كائن «لا شيء» (`lavkoo:app/Providers/FulfillmentServiceProvider.php:32-39`).
- **تنفيذ المزوّدين ليس له جدول outbox مستقل**: صفّ `provider_fulfillments` نفسه (الحالة + `claim_token` + `idempotency_key`) هو النية الدائمة، والمسوح المجدولة تجد العمل «بالحالة» فلا يضيع بفقدان hook أو job — `lavkoo:app/Services/Fulfillment/FulfillmentHooks.php:10-16` و`lavkoo:app/Services/Fulfillment/FulfillmentRecovery.php:19-29`. سجل الأحداث `provider_fulfillment_events` وسجل النداءات `provider_api_logs` للتدقيق.

### 2.9 اختيار المصدر: ثلاث آليات، ولا بديل تلقائي في أيٍّ منها

**(أ) طلبات المتجر — عمود واحد على المنتج.** المصدر هو `products.fulfillment_provider` (نصّ 32، nullable، مفهرس، غير قابل للإسناد الجماعي) — `lavkoo:database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php:22-26`. لا يتغيّر إلا عبر `ProductProviderAssignmentService::assign` — `lavkoo:app/Services/Fulfillment/ProductProviderAssignmentService.php:44-97`، بشروط: منتج مملوك للإدارة (`added_by = admin`)، فيزيائي، وله ربط **معتمد** لكل متغيّر — `lavkoo:app/Services/Fulfillment/ProductProviderAssignmentService.php:193-213`. كل تغيير يُسجَّل في `product_provider_changes` (الأسطر 77–85).

عند checkout يُجمَّد المصدر لكل سطر داخل معاملة الطلب: `OrderItemOwnershipSnapshotter::capture` يقرأ `fulfillment_provider` من نسخة المنتج المحفوظة مع السطر ويكتب صفاً في `provider_fulfillment_items` — `lavkoo:app/Utils/OrderManager.php:1363-1377` و`lavkoo:app/Services/Fulfillment/OrderItemOwnershipSnapshotter.php:40-91`. والتوجيه يقرأ **اللقطة فقط** ويجمّع حسب المزوّد حرفياً — `lavkoo:app/Services/Fulfillment/FulfillmentRouter.php:49-79`.

**هل يوجد fallback تلقائي إلى مزوّد آخر؟ لا** (VERIFIED):

1. اللقطة تُكتب حتى لو غاب الربط، «كي لا يسقط السطر صامتاً إلى المعالجة الداخلية» — `lavkoo:app/Services/Fulfillment/OrderItemOwnershipSnapshotter.php:13-17`؛ وغياب الربط أو نقص المخزون أو مدينة غير مطابقة يُنتج **حجزاً** (`held`) بسبب مسمّى لا إعادة توجيه — `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:216-243` و`lavkoo:app/Enums/Fulfillment/HoldReason.php:11-27`.
2. الحجوزات القابلة للفكّ الآلي (`AUTO_RELEASABLE`) يعيد المسح محاولتها **مع المزوّد نفسه** — `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:41-53`.
3. المرسِل يحلّ المحوّل من مزوّد الشحنة نفسها فلا يرى محوّلٌ شحنة غيره — `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:91-93`؛ ومعالج الأحداث يرفض حدثاً من مزوّد على شحنة مزوّد آخر — `lavkoo:app/Services/Fulfillment/WebhookEventProcessor.php:103-106`.
4. تغيير مزوّد المنتج لاحقاً لا يحرّك طلباً قائماً (اللقطة غير قابلة للتعديل — `lavkoo:app/Models/ProviderFulfillmentItem.php:33-37` و`lavkoo:app/Models/ProviderFulfillmentItem.php:59-72`).
5. الاختبارات تثبّت ذلك: `test_each_provider_receives_only_its_own_items` (`lavkoo:tests/Feature/Fulfillment/RouterTest.php:49`)، `test_reassigning_the_product_later_does_not_move_an_order_already_placed` (`lavkoo:tests/Feature/Fulfillment/OwnershipSnapshotTest.php:121`)، `test_a_missing_mapping_still_records_ownership_so_the_line_cannot_fall_through_to_in_house` (`lavkoo:tests/Feature/Fulfillment/OwnershipSnapshotTest.php:165`).

**الاستثناء الوحيد الذي يشبه البديل:** معالجة قيمة مزوّد غير معروفة للتعداد (التفاصيل في SEC-E11-10). انظر E11-F05.

`SEC-E11-10: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`

الطلب الواحد قد يجمع مصادر عدة (مزوّدان + داخلي): يبقى طلباً واحداً بدفعة واحدة ويُقسَّم إلى شحنة لكل مزوّد — `lavkoo:docs/integrations/README.md:3-6`، ويُنبَّه العميل قبل الدفع (`lavkoo:app/Services/Fulfillment/MixedProviderNotice.php:20-34`).

**(ب) كمية قوائم القنوات — `InventorySourcePolicy`.** الموقع الذي تأتي منه كمية القائمة يُحلّ بالأكثر تحديداً: القائمة ثم سياسة المنتج ثم سياسة الحساب — `lavkoo:app/Services/SalesChannels/Publishing/PublishPolicyResolver.php:15-36`. هذا تدرّج تحديد لا بديل توفّر: لا يُنتقل إلى موقع آخر إذا نفد الأول.

**(ج) طلبات القنوات — `FulfillmentRoutingRule/Decision`.** المحرّك `DynamicFulfillmentRoutingEngine` يقرّر لطلب القناة (`ExternalOrderMapping`) لا لطلب المتجر. قواعده الصلبة قبل أي قاعدة إدارية (طلب تشحنه القناة لا يُعطى لمزوّدنا، COD وسطور غير مربوطة تنتظر إنساناً…) — `lavkoo:app/Services/SalesChannels/Routing/DynamicFulfillmentRoutingEngine.php:47-80`. اختيار القاعدة: الأقل `priority` ثم الأكثر تحديداً ثم الأقدم — `lavkoo:app/Services/SalesChannels/Routing/RoutingRuleMatcher.php:15-24`. وعند `route_to_provider`: إن كان مخزون السطر عند مزوّد غير المزوّد المستهدف فالقرار **مراجعة يدوية** (`stock_not_at_target_provider`) لا تحويل — `lavkoo:app/Services/SalesChannels/Routing/DynamicFulfillmentRoutingEngine.php:143-163`؛ وبلا قاعدة مطابقة: مراجعة يدوية أو متابعة فقط — `lavkoo:app/Services/SalesChannels/Routing/DynamicFulfillmentRoutingEngine.php:86-93`. وإنشاء شحنة مزوّد لطلب قناة مطفأ افتراضياً (`managed_dispatch_enabled`).

**تصحيح من التحقق المستقل — مصدر التنفيذ لطلب القناة ليس عمود المنتج:** عند تفعيل الإرسال المُدار يُحدَّد مصدر التنفيذ لطلب القناة بقرار التوجيه ومزوّد موقع مخزون القائمة، لا بعمود المنتج (تفاصيل الحرّاس وحدودها في SEC-E11-11). النتيجة (VERIFIED على الكود، والمسار مطفأ افتراضياً): طلبات القنوات تُوجَّه بقرار إداري صريح، بينما تبقى طلبات المتجر للمنتج نفسه على مصدره المحفوظ. ليس هذا fallback تلقائياً (القاعدة قرار إداري، والتعارض بين مزوّدَين مرفوض)، لكنه يعني أن «مصدر واحد لكل منتج» مفروض بعمود المنتج على طلبات المتجر فقط. مواضع الالتقاط موضعان (`OrderManager` و`ChannelOrderMaterializer`)، وتعديل الطلب مرفوض لطلب فيه منتج مزوّد — `lavkoo:app/Services/Fulfillment/FulfillmentOrderGuard.php:65-82` و`lavkoo:app/Traits/OrderEditManager.php:477`.

`SEC-E11-11: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`

### 2.10 الإرسال: التدفق، الضمانات، إعادة المحاولة، الإلغاء، المطابقة

```
checkout (معاملة واحدة)  →  لقطة لكل سطر مملوك لمزوّد            provider_fulfillment_items
بعد الـ commit           →  FulfillmentHooks::ordersPlaced → RouteOrderFulfillment
FulfillmentRouter::route →  شحنة لكل مزوّد (pending أو held)      provider_fulfillments
DispatchProviderFulfillment → ProviderOrderDispatcher: claim → send → settle
```

| المرحلة | السلوك | الدليل |
|---|---|---|
| الإشعار | بعد commit الطلب فقط، وأفضل جهد لا يُفشل checkout | `lavkoo:app/Utils/OrderManager.php:1762-1764` و`lavkoo:app/Services/Fulfillment/FulfillmentHooks.php:24-32` |
| تغيّر حالة الطلب | `OrderRepository::update` يستدعي `orderChanged`: إعادة توجيه، وطلب إلغاء لشحنات الطلب المغلق | `lavkoo:app/Repositories/OrderRepository.php:513-531` و`lavkoo:app/Services/Fulfillment/FulfillmentHooks.php:39-60` |
| الأهلية | ليس POS، غير مغلق، غير موقوف، الحالة ضمن `confirmed`/`processing` (أو ما يضيّقه المزوّد)، مدفوع أو COD؛ تُفحص عند التوجيه وعند الإرسال | `lavkoo:app/Services/Fulfillment/OrderEligibility.php:30-58` و`lavkoo:config/fulfillment.php:101-105` |
| التوجيه | قفل صف الطلب وسطوره؛ مرجع `"{order_id}-{MZ أو TP}{generation}"`؛ مفتاح idempotency = sha256(الطلب، المزوّد، الجيل، السطور) | `lavkoo:app/Services/Fulfillment/FulfillmentRouter.php:43-56` و`lavkoo:app/Services/Fulfillment/FulfillmentRouter.php:137-168` |
| المطالبة (claim) | تحت قفل الصف: إعادة فحص كل شيء، preflight، إعادة تقييم المال، تثبيت قرار dry run، ثم `dispatching` برمز مطالبة جديد | `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:119-180` |
| الإرسال | خارج أي معاملة؛ استثناء المحوّل يُعامل «نتيجة مجهولة» | `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:84-116` |
| التسوية | مقبول → `submitted` (يُسجَّل حتى لو فُقدت المطالبة)؛ مرفوض → `failed`؛ dry run؛ غامض → `reconcile_pending`؛ «لم يُرسل قطعاً» → `retry_wait` | `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:258-301` |
| إعادة المحاولة | حتى 5 محاولات بتراجع 60، 300، 900، 3600، 21600 ثانية؛ الـ job لا يعيد نفسه (`tries = 1`) | `lavkoo:config/fulfillment.php:91-99`، `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:310-323`، `lavkoo:app/Jobs/Fulfillment/DispatchProviderFulfillment.php:24` |
| واحد في كل مرة | قفل `WithoutOverlapping` **مشترك** بين jobs الإرسال والمطابقة والإلغاء لكل شحنة | `lavkoo:app/Jobs/Fulfillment/OneAtATimePerFulfillment.php:12-18` |
| المطابقة | تسأل المزوّد ولا تعيد الإرسال؛ «غير موجود» ليس دليلاً فتتراجع ثم تطلب إنساناً بعد 6 محاولات | `lavkoo:app/Services/Fulfillment/ReconciliationService.php:33-82` و`lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:364-414` |
| الإلغاء | محلياً إن لم يصل المزوّد؛ «نية» مسجّلة إن كانت النتيجة مجهولة؛ وإلا طلب للمزوّد بإيجار (lease) لمستدعٍ واحد، ولا تصير الشحنة ملغاة إلا بتقرير المزوّد | `lavkoo:app/Services/Fulfillment/FulfillmentCancellation.php:45-93` و`lavkoo:app/Services/Fulfillment/ShipmentTracker.php:355-381` |
| طابور `sync` | لا شيء يُنفَّذ داخل طلب العميل؛ المسح يقوم بالعمل | `lavkoo:app/Services/Fulfillment/FulfillmentQueue.php:25-43` |

**أوامر الاسترداد والمطابقة المجدولة** (`lavkoo:app/Console/ScheduleDefinition.php:92-98`): `fulfillment:recover` كل دقيقة بثمانية مسوح معزولة ونبضة (`lavkoo:app/Services/Fulfillment/FulfillmentRecovery.php:269-291`) منها تحويل العالق في `dispatching` أكثر من 10 دقائق إلى «نتيجة مجهولة» (`lavkoo:app/Services/Fulfillment/FulfillmentRecovery.php:153-163`) ومؤشرات دوّارة تمنع تجويع الصفوف (`lavkoo:app/Services/Fulfillment/FulfillmentRecovery.php:315-330`)؛ `fulfillment:poll` كل 15 دقيقة (`lavkoo:app/Services/Fulfillment/FulfillmentRecovery.php:208-259`)؛ `fulfillment:sync` للمنتجات والمخزون والمدن؛ `fulfillment:prune` يومياً. وللإنسان: release، confirm-not-created، link، mark-delivered، mark-cancelled، cancel، redispatch — `lavkoo:app/Http/Controllers/Admin/Fulfillment/ProviderFulfillmentController.php:31` و`lavkoo:app/Services/Fulfillment/OperatorActions.php:55-170`.

**حالة الطلب الأم:** حدث المزوّد لا يكتب `orders.order_status` أبداً؛ `FulfillmentAggregator` يشتقّ صورة واحدة للقراءة فقط — `lavkoo:app/Services/Fulfillment/FulfillmentAggregator.php:12-27`. ويُمنع تعليم الطلب «مسلَّماً» أو إعادة فتحه أو تعديل سطوره ما دامت له شحنات مزوّد غير منتهية — `lavkoo:app/Services/Fulfillment/FulfillmentOrderGuard.php:32-97`، والحارس مستدعى في لوحة الإدارة والبائع وواجهتي البائع وتطبيق المندوب (`lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:684`، `lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:646`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:406`، `lavkoo:app/Http/Controllers/RestAPI/v2/seller/OrderController.php:216`، `lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:167`).

### 2.11 Webhooks المزوّدين: المسار، عدم التكرار، الترتيب

- المسار: نقطة `POST` واحدة تحت `/api/fulfillment-webhooks/` مُعرَّفة برمز المزوّد ونوع الحدث (`lastmile` أو `fulfillment`)، خارج مجموعة `api` عمداً، مع حدّ معدّل 600/دقيقة لكل عنوان — `lavkoo:routes/rest_api/fulfillment_webhooks.php:15-18`، `lavkoo:app/Providers/RouteServiceProvider.php:139-144`، `lavkoo:app/Providers/FulfillmentServiceProvider.php:50`.
- نقاط القوة في المصادقة: الطلب يُرفض ما لم تكن التكاملات والمزوّد وwebhooks المزوّد كلها مفعّلة؛ السرّ يُقارن بزمن ثابت (`hash_equals`)؛ كل فشل مصادقة جواب 401 واحد بلا تفاصيل؛ سقف 64KB للحمولة — `lavkoo:app/Http/Controllers/Api/FulfillmentWebhookController.php:37-83`.
- المتحكم **يخزّن ويصفّ فقط**: الحدث يُحفظ مرة واحدة بمفتاح `dedupe_key` فريد (الإعادة تُجاب «duplicate» بلا صف ثانٍ) ولا يُحفظ من الحمولة إلا الحقول التي تقرؤها معالجة الحالة — `lavkoo:app/Services/Fulfillment/WebhookEventProcessor.php:44-78` و`lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:427-445`. مفتاح عدم التكرار مشتقّ من حقول الحدث (انظر SEC-E11-13).

`SEC-E11-13: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- المعالجة (job بثلاث محاولات — `lavkoo:app/Jobs/Fulfillment/ProcessProviderEvent.php:20-25`): إيجاد شحنتنا بالمراجع بشرط اتفاقها كلها، وإلا يبقى الحدث «غير مطابق» ويُعاد 24 ساعة — `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:457-477` و`lavkoo:app/Services/Fulfillment/WebhookEventProcessor.php:151-168`؛ الادعاءات النهائية (تسليم/إلغاء/إرجاع…) تُؤكَّد — وفق إعداد المزوّد (انظر SEC-E11-01) — بسؤال المزوّد عن الطرد **الذي نحمله نحن** ومطابقة مبلغ COD، وما لا يُؤكَّد ينتظر ولا يُطبَّق ثقةً — `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:576-619` و`lavkoo:app/Services/Fulfillment/WebhookEventProcessor.php:123-133`.
- الترتيب: `FulfillmentStateMachine::decide` يرفض القديم (بطابع المزوّد الزمني بدقة الميلي ثانية) والنهائي والمكرّر والرجوع إلى الخلف — `lavkoo:app/Services/Fulfillment/FulfillmentStateMachine.php:28-59` و`lavkoo:app/Models/ProviderFulfillment.php:80-83`؛ والطابع المستقبلي يُقصّ — `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:1054-1064`. كل انتقال يمرّ عبر `ShipmentTracker` تحت قفل الصف ويترك صفاً في `provider_fulfillment_events` — `lavkoo:app/Services/Fulfillment/ShipmentTracker.php:45-79`.
- تقدّم المستودع (created/picked/packed) عرضٌ فقط وليس تسليماً — `lavkoo:app/Services/Fulfillment/WebhookEventProcessor.php:170-183`.
- M5AZN بلا webhooks: الحالة بالاستطلاع (`fulfillment:poll`) أو بإغلاق يدوي — `lavkoo:docs/integrations/capability-matrix.md:21-23`.

`SEC-E11-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`

### 2.12 اللقطة التاريخية لمصدر التنفيذ

| أين | ماذا يُسجَّل | الثبات | الدليل |
|---|---|---|---|
| `provider_fulfillment_items` (صف لكل سطر طلب مملوك لمزوّد؛ `order_detail_id` فريد) | `provider`، `mapping_id`، `external_product_id`، `external_sku`، `external_barcode`، `sku`، `name`، `quantity`، `unit_price`، `line_discount`، `line_tax`، `line_total` (بعملة المزوّد)، `unit_cost`، `weight_kg`، و`snapshot` JSON (وقت الالتقاط، المنتج، المتغيّر، الربط وحالته، `currency_converted`) | غير قابلة للتعديل ولا للحذف (حارس في النموذج)؛ يتغيّر فقط `provider_fulfillment_id` و`status` | `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:116-153`، `lavkoo:app/Models/ProviderFulfillmentItem.php:33-37` |
| `provider_fulfillments` (شحنة لكل طلب × مزوّد × جيل) | `reference`، `provider`، `generation`، `idempotency_key`، `payment_mode`، `cod_amount`، `currency`، المجاميع، مراجع المزوّد، التتبّع، الطوابع | حالة متحرّكة عبر `ShipmentTracker` فقط | `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:39-114` |
| `provider_fulfillment_events` | كل حدث وانتقال مع النتيجة | إلحاقي؛ الحمولة تُمحى بعد 60 يوماً ويبقى السجل | `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:155-176` و`lavkoo:app/Console/Commands/FulfillmentPruneCommand.php:30-32` |
| `product_provider_changes` | من/إلى أي مزوّد، الفاعل، السبب، وعدد السطور التي بقيت مع المزوّد القديم | إلحاقي | `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:326-338` |
| `order_details.product_details` | نسخة JSON من المنتج لحظة الطلب (تتضمّن `fulfillment_provider` لأن العمود جزء من النموذج) | — | `lavkoo:app/Utils/OrderManager.php:1254-1258` |
| `fulfillment_routing_decisions` (طلبات القنوات) | `rule_snapshot`، `inputs`، `line_decisions`، `version`، `is_current` | القرار المطبَّق لا يُعدَّل؛ الجديد نسخة جديدة | `lavkoo:database/migrations/2026_11_07_100200_create_inventory_and_routing_tables.php:144-176` |
| الطلب الأم | `delivery_type`، `delivery_service_name`، `third_party_delivery_tracking_id`، `delivery_man_id` (للتنفيذ الداخلي أو الطرف الثالث اليدوي) | حقول قابلة للتعديل | `lavkoo:database/migrations/2022_03_17_070200_add_delivery_info_to_orders_table.php:17-19` |

**حدّ الثبات (تصحيح من التحقق المستقل):** ثبات اللقطة مفروض بأحداث نموذج Eloquent (`updating` / `deleting`) لا بقيد في قاعدة البيانات (انظر SEC-E11-12). وأمر التنظيف `fulfillment:prune` لا يحذف صفوف اللقطة.

`SEC-E11-12: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`

**ملاحظة:** السطر الداخلي (بلا مزوّد) لا يُكتب له صف لقطة: «غياب اللقطة هو الافتراضي الداخلي» — `lavkoo:app/Services/Fulfillment/OrderItemOwnershipSnapshotter.php:13-15`. ولا تحمل أي لقطة معرّف متجر/دولة/كيان قانوني/حساب مزوّد (بحث `country|storefront_id|store_id|market|legal_entity|currency|shop_id|seller_id` في ترحيلات هذا المجال الستة: الموجود فقط `provider_city_mappings.country_code`، `fulfillment_routing_rules.country`، `inventory_locations.marketplace_id`، `seller_id` في `inventory_locations` و`fulfillment_routing_rules`، وعمودا العملة `provider_fulfillments.currency` و`provider_product_mappings.supplier_cost_currency`).

### 2.13 المال والعملة في التنفيذ

- **عملة واحدة عامة للمزوّدين:** `CurrencyCode::provider()` يقرأ `fulfillment.currency` (الافتراضي `SAR`) — `lavkoo:app/Services/Fulfillment/Support/CurrencyCode.php:26-30` و`lavkoo:config/fulfillment.php:59`. التعليق المجاور ينصّ: «The store is KSA-only» — `lavkoo:config/fulfillment.php:36-39`. والرمز `KSA` في جدول العملات يُعامل مرادفاً لـ`SAR` — `lavkoo:config/fulfillment.php:61-69`.
- **عملة الشحنة تُختم من الإعداد لا من الطلب:** `'currency' => CurrencyCode::provider()` — `lavkoo:app/Services/Fulfillment/FulfillmentRouter.php:153`؛ وافتراضي العمود `SAR` — `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:57`.
- **التحويل:** `ProviderCurrency::fromStored` — في نموذج `multi_currency` يضرب المبلغ المخزَّن (وحدة أساسها USD) في (سعر العملة الهدف ÷ سعر USD) من جدول `currencies`؛ وفي `single_currency` يشترط أن تكون العملة الافتراضية هي عملة المزوّد وإلا يعيد `null` — `lavkoo:app/Services/Fulfillment/Support/ProviderCurrency.php:29-46`. `null` يعني حجز الشحنة بسبب `hold_currency_unconvertible` لا إرسالها برقم مخمَّن — `lavkoo:app/Services/Fulfillment/FulfillmentMoney.php:32-48` و`lavkoo:app/Services/Fulfillment/CodPolicy.php:37-52`. سعر الصرف نصّ في القاعدة ويُقبل فقط كعدد عشري موجب بسيط — `lavkoo:app/Services/Fulfillment/Support/ExchangeRate.php:23-46`.
- **قيمة الشحنة:** سطورها + حصة نسبية من غير السطور (شحن، كوبون، تقريب) بحيث تجمع حصص المزوّدين إلى مجموع الطلب بدقّة جزء من مئة — `lavkoo:app/Services/Fulfillment/ProviderOrderShare.php:31-58`.
- **COD:** محصّل واحد يحصّل كل المتبقّي؛ عدة محصّلين → حجز، إلا إذا فُعّل `allow_mixed` وكان كل مزوّد `cod_verified` ولا توصيل داخلي في الطلب، فيُقسَّم نسبياً — `lavkoo:app/Services/Fulfillment/CodPolicy.php:35-97`. طلب بعضه داخلي وبعضه لمزوّد يُحجز دائماً لأن المسار الداخلي يقيّد كامل `order_amount` نقداً بيد المندوب (التعليق في `lavkoo:app/Services/Fulfillment/CodPolicy.php:22-23`، ويؤكّده كود تطبيق المندوب عند التسليم: `lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:193-196`). وCOD لا يُعرض أصلاً عند checkout لسلة تتقسّم بين محصّلين — `lavkoo:app/Services/Fulfillment/CheckoutCodGuard.php:28-58`، ومواضع الاستدعاء `lavkoo:app/Http/Controllers/Web/WebController.php:435-436` و`lavkoo:app/Http/Controllers/Web/WebController.php:537-545` و`lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:190-192`.
- **إعادة التقييم قبل كل إرسال:** طلب دُفع بعد التوجيه يُرسل مدفوعاً — `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:242-256`.
- **القيم المصرَّح بها:** Torod يستقبل `cod` و`price` لكل عنصر و`cost` اختيارياً (محذوف افتراضياً: `cost_source = omit`) — `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodOrderPayloadBuilder.php:40-70` و`lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodOrderPayloadBuilder.php:98-105`؛ M5AZN يستقبل `total` و`cod_amount` — `lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznPayloadBuilder.php:36-62`.
- **العملة لا تُرسل في الحمولة:** جسما إنشاء الطلب لدى Torod وM5AZN لا يحملان حقل عملة (انظر المفاتيح في الموضعين أعلاه)، واختبار `test_an_order_back_to_m5azn_carries_the_riyal_total_and_collection_in_a_body_that_names_no_currency` يثبّت ذلك (`lavkoo:tests/Feature/Fulfillment/PilotServerM5aznImportTest.php:135`). أي أن عملة المبلغ خاصّية ضمنية لحساب المزوّد، وما يُرسل رقم مجرّد.
- **افتراض خانتين عشريتين في كل الطبقة** — انظر الجدول في القسم 3 وE11-F03.

### 2.14 مطابقة المدن والعنوان

- الجداول محايدة تجاه المزوّد ومفتاحها رمز المزوّد: `provider_cities` (قائمة المزوّد)، `provider_city_aliases`، `provider_city_mappings` («لمزوّد P، مدينتنا C هي مدينة المزوّد E»)، `provider_city_sync_runs` — الأوّلان يُنشآن في `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:295-324` والأخيران في `lavkoo:database/migrations/2026_11_07_100200_create_provider_city_mapping_tables.php:25-67`. قاعدة البيانات تسمح بربط معتمد واحد لكل (مزوّد، مدينة) عبر `approved_slot` — `lavkoo:database/migrations/2026_11_07_100200_create_provider_city_mapping_tables.php:42-45`.
- الحلّ عند الإرسال «بالضبط أو لا شيء»: عنوان يحمل `city_id` يمرّ عبر الربط المعتمد فقط؛ عنوان نصّي قديم يمرّ عبر alias ثم مطابقة فريدة؛ وما عدا ذلك `unmapped` / `ambiguous` / `pending_review` والطلب يُحجز ولا يُرسل إلى «أقرب مدينة» — `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodCityResolver.php:52-73` و`lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodCityResolver.php:159-177`.
- الاعتماد الآلي الوحيد: مدينة لها مرشّح واحد، والقائمة قُرئت كاملة، ولا تناقض في المنطقة، ولم يقرّر إنسان قبلاً — `lavkoo:app/Services/Fulfillment/ProviderCityMappingService.php:23-33`.
- M5AZN لا يستعمل معرّفات مدن: يرسل اسم المدينة العربي نصاً مع `country` و`country_code` من الإعداد — `lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznPayloadBuilder.php:43-48`.
- **ما هو سعودي بالبناء:** التحقق من الهاتف (`SaudiPhone`: يقبل فقط `5XXXXXXXX` بعد إزالة `00`/`966`/`0` — `lavkoo:app/Services/Fulfillment/Support/SaudiPhone.php:12-39`) يُستعمل في preflight المحوّلين (`lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:233-235` و`lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznAdapter.php:192-194`) وفي الحمولة مع البادئة `00966` (`lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodOrderPayloadBuilder.php:81-86`) — والفحص شكليّ لا يعرف دولة العنوان (انظر E11-F04)؛ افتراضات M5AZN `country_code = SA` و`country_name = Saudi Arabia` (`lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznAdapter.php:86-87`)؛ `address_regions.country_code` افتراضيه `SA` والمستورد يثبّت `SA` (`lavkoo:database/migrations/2026_11_07_100000_create_address_master_tables.php:24` و`lavkoo:app/Services/Address/AddressImporter.php:134-140`)؛ العمود `provider_city_mappings.country_code` موجود لكن **لا يكتبه أي كود** (بحث `country_code` في `app/Services/Fulfillment` و`app/Services/Address`: المواضع الوحيدة هي مستورد المناطق وإعداد M5AZN)؛ ومطابقة المرشّحين تقرأ كل المناطق والمدن النشطة بلا تصفية دولة (`lavkoo:app/Services/Fulfillment/ProviderCityMappingService.php:119-124`).

`SEC-E11-14: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`

### 2.15 المستودعات ومواقع المخزون

ثلاثة مفاهيم غير مترابطة (VERIFIED — بحث `warehouse_id|Warehouse::` خارج `Marketplace`: المواضع الوحيدة لوحة البائع والمراقبة وواجهة البائع):

| المفهوم | السمات | الدولة؟ | الدليل |
|---|---|---|---|
| `warehouses` + `warehouse_stock` (Stage C) | `seller_id`، `name`، `code`، `address` (نص حر)، `is_default`، `status`؛ مستودع افتراضي واحد للمنصّة؛ تقسيم لـ`current_stock` لا يؤثّر في البيع | لا | `lavkoo:app/Models/Warehouse.php:16-18`، `lavkoo:app/Http/Controllers/Admin/Marketplace/WarehouseController.php:56-69`، `lavkoo:app/Services/Marketplace/WarehouseService.php:13-17` |
| `inventory_locations` (وحدة القنوات) | الأنواع `torod_warehouse`، `m5azn_supplier`، `amazon_fba`، `own_warehouse`، `other`؛ مصدر الحقيقة `provider` / `channel` / `manual` / `platform`؛ ثلاثة مواقع افتراضية بأكواد ثابتة؛ موقع FBA لكل (حساب، marketplace) | لا (يوجد `marketplace_id` لـ FBA فقط) | `lavkoo:app/Models/InventoryLocation.php:23-33`، `lavkoo:app/Services/SalesChannels/Inventory/InventoryLocationService.php:14-26` |
| `products.fulfillment_provider` | رمز مزوّد واحد على المنتج | لا | 2.9 |

**وضع المستودع الخاص (own warehouse):** موقع `own-warehouse` مصدره `manual` افتراضياً («لا شيء يكتب أرصدته اليوم فلا يعرض شيئاً» — `lavkoo:config/sales_channels.php:413-417`). يتحوّل إلى القراءة من مخزون المتجر بشرطين معاً: الإعداد `platform` وتبديل صفّ الموقع بالأمر المدقَّق `channels:stock-use-platform-for-own-warehouse` (جافّ دون `--apply`) — `lavkoo:app/Console/Commands/SalesChannelsStockUsePlatformForOwnWarehouseCommand.php:21-60` و`lavkoo:app/Services/SalesChannels/Inventory/LocationStockReader.php:37-47`. وحتى بعد ذلك مخزون المتجر «مرفوض» للقنوات إلى أن يقرّر المالك (`lavkoo:app/Services/SalesChannels/Inventory/PoolFigures.php:24-25`)، وخصم وحدات طلبات القنوات من مخزون المتجر غير منفَّذ في هذه النسخة (`lavkoo:config/sales_channels.php:418-420`).

طبقة pick/pack الداخلية `order_fulfillments` حالة أمامية فقط لا تكتب `orders.order_status` — `lavkoo:app/Services/Marketplace/FulfillmentService.php:9-16`.

### 2.16 الشحن: الطرق، المندوب، الطرف الثالث، المناطق، Delivery Syria

- **مسؤولية الشحن إعداد عام واحد:** `business_settings.shipping_method` = `inhouse_shipping` أو `sellerwise_shipping` يُنسخ على كل طلب في `shipping_responsibility` — `lavkoo:app/Utils/OrderManager.php:1095`. نوع الحساب لكل بائع (`order_wise` / `category_wise` / `product_wise`) من `shipping_types` — `lavkoo:app/Utils/CartManager.php:257-272`.
- **`ShippingMethod`**: الحقول التي يكتبها التطبيق `creator_id`، `creator_type`، `title`، `cost`، `duration`، `status` — بلا دولة ولا منطقة ولا عملة — `lavkoo:app/Models/ShippingMethod.php:32-39`. (جدول `shipping_methods` نفسه بلا ترحيل إنشاء في المستودع؛ مخططه الفعلي `UNKNOWN`.)
- **قيد دول/رموز التوصيل عام:** `delivery_country_restriction` و`delivery_zip_code_area_restriction` في `business_settings` مع جدولي `delivery_country_codes` و`delivery_zip_codes`.
- **مناطق الشحن (Stage C):** `ShippingZone` (`countries`، `regions`، `base_cost`، `per_kg_cost`، `free_over`) و`ShippingRateService::resolveZone` يفضّل المنطقة المحدَّدة بالدولة على العامة — `lavkoo:app/Models/ShippingZone.php:15-48` و`lavkoo:app/Services/Marketplace/ShippingRateService.php:23-47`. مطفأة افتراضياً (`zone_wise_shipping`).

`SEC-E11-15: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- **التوصيل على الطلب الأم:** `self_delivery` (مندوب `delivery_man_id`) أو `third_party_delivery` (اسم خدمة ورقم تتبّع نصّيان يدخلهما الإداري أو البائع) — `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:881-894` و`lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:492-518`. هذه الحقول **مستقلة** عن شحنات المزوّدين (التتبّع هناك على `provider_fulfillments`). تطبيق العميل يقرأ حقلي الطرف الثالث (`lavkooapp:lib/features/order/domain/models/order_model.dart:205-206`) لكنه لا يستهلك نقطتي `fulfillment-notice` و`fulfillments` المضافتين (`lavkoo:routes/rest_api/v1/api.php:187-196`؛ بحث `fulfil` في `lavkooapp/lib`: لا نتيجة).
- **Delivery Syria** (شركة توصيل سورية؛ يبدو إرثاً من نسخة سابقة للمنتج — INFERRED من وجوده في الـ commit الأول ومن اسم حزمة تطبيق العميل): hook داخل `OrderManager::generateOrder` يعمل **لكل طلب** بعد الـ commit، ويسجّل سطراً بمستوى warning لكل طلب (`lavkoo:app/Utils/OrderManager.php:1781-1786`)؛ إن كانت طريقة الشحن المختارة مفعّلة واسمها حرفياً `DeliverySyria` والتكامل جاهزاً يُنشئ الطرد بنداء HTTP **متزامن داخل طلب العميل** ويحصّل `order_amount` نقداً إن لم يكن الطلب مدفوعاً، ثم يكتب حقول الطرف الثالث على الطلب — `lavkoo:app/Utils/OrderManager.php:1804-1840`، `lavkoo:app/Utils/OrderManager.php:1912-1914`، `lavkoo:app/Utils/OrderManager.php:1973`، `lavkoo:app/Utils/OrderManager.php:2012-2018`. إعداده مفتاح واحد `delivery_syria` في `business_settings` بسرّين مشفّرين — `lavkoo:app/Services/DeliverySyria/DeliverySyriaConfigService.php:23-34`. webhook الحالة يكتب `orders.order_status` للحالات الوسيطة فقط ولا ينهي طلباً — `lavkoo:app/Http/Controllers/Api/DeliverySyriaWebhookController.php:70-82`. جدولاه (`delivery_syria_parcels`، `delivery_syria_governorate_rates`) بلا ترحيلات في المستودع. ملاحظة: الـ hook يقارن العمود `shipping_methods.name` (`lavkoo:app/Utils/OrderManager.php:1827-1829`) بينما النموذج يكتب `title` ولا يوجد في المستودع ما ينشئ عموداً باسم `name`؛ إن لم يوجد هذا العمود في قاعدة الإنتاج فالـ hook لا يُرسل شيئاً أبداً (INFERRED). هل هو مفعّل في الإنتاج: `UNKNOWN`.

`SEC-E11-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`

### 2.17 حمايات التزامن وما تغطّيه الاختبارات

| الخطر | الحماية | الدليل |
|---|---|---|
| بيع زائد عند checkout متزامن | قفل صفوف المنتجات بترتيب المعرّف + خصم ذرّي مشروط + إعادة المعاملة 3 مرات | `lavkoo:app/Utils/OrderManager.php:1596-1602`، `lavkoo:app/Utils/OrderManager.php:1345-1356` |
| إرجاع/خصم مزدوج عند تغيّر الحالة | قلب مشروط لـ`is_stock_decreased` | `lavkoo:app/Utils/OrderManager.php:114-123` |
| سطر يُملك مرتين | `order_detail_id` فريد | `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:121-122` |
| شحنة مكرّرة لطلب × مزوّد | قفل صف الطلب والسطور + فريد (`order_id`, `provider`, `generation`) + `idempotency_key` فريد + التقاط `UniqueConstraintViolationException` | `lavkoo:app/Services/Fulfillment/FulfillmentRouter.php:43-56`، `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:110-111`، `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:48-50`، `lavkoo:app/Services/Fulfillment/FulfillmentRouter.php:157-160` |
| إرسال مزدوج | مطالبة تحت قفل الصف برمز، job بمحاولة واحدة، قفل مشترك لكل شحنة، التسوية تتحقّق من الرمز، ومرجع مزوّد موجود يمنع الإرسال | `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:132-136`، `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:274-280` |
| مرجع مزوّد مكرّر | فريد (`provider`, `provider_reference`) ثم تعليم الشحنة «تحتاج انتباهاً» | `lavkoo:app/Services/Fulfillment/ShipmentTracker.php:105-114` |
| حدث webhook مكرّر/قديم | `dedupe_key` فريد + آلة الحالات | 2.11 |
| سباق حفظ الإعدادات مع تجديد الرمز | `lockForUpdate` على صف `integration_settings` | `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:159-177` |
| كتابة مخزون المتجر | قفل صف المنتج داخل معاملة تشمل ختم الربط وملاحظة الـ outbox | `lavkoo:app/Services/Fulfillment/InventorySyncService.php:263-287` |
| تقسيم المجمّع | صف `inventory_pools` `FOR UPDATE` + `insertOrIgnore` + 3 محاولات | 2.7 |
| حجز مزدوج لسطر قناة | `reservation_key` فريد + إعادة قراءة قافلة | `lavkoo:app/Services/SalesChannels/Inventory/ReservationService.php:52-67` |
| تعديل عنوان أثناء الإرسال | قفل شحنات الطلب نفسه الذي يأخذه المرسِل | `lavkoo:app/Services/Address/OrderAddressService.php:153-174` |
| تداخل المسوح | `withoutOverlapping` في الجدولة | `lavkoo:app/Console/ScheduleDefinition.php:92-98` |

**الاختبارات (لم تُشغَّل في هذا التدقيق):** `tests/Feature/Fulfillment` يغطّي التوجيه والإرسال والمطابقة والإلغاء وحرّاس الحالة والمال وCOD وwebhooks ومخزون Torod ومصفوفة سيناريوهات الطلب وحراسة الكتابة الخارجية؛ كل نداءات المزوّدين مزيّفة (`lavkoo:docs/integrations/README.md:115-121`). الإعداد الافتراضي SQLite في الذاكرة (`lavkoo:phpunit.xml:26-27`)، واختبارات التزامن الحقيقي تتخطّى نفسها بلا MySQL/MariaDB (`lavkoo:tests/Feature/Concurrency/StockConcurrencyTest.php:54`) وتُشغَّل في CI على MySQL 8.0 وMariaDB 11.4.2 (`lavkoo:.github/workflows/ci.yml:254`). أمثلة: ست عمليات تقسّم مجمّعاً واحداً (`lavkoo:tests/Feature/Concurrency/StockConcurrencyTest.php:87`)، حسابان ينشران مجمّعاً واحداً (السطر 186)، الطلب نفسه يقرؤه الاستطلاع والـ webhook معاً (السطر 226)، التقسيم لا يتشابك مع checkout (السطر 270). **فجوة تغطية معلنة:** جداول المتجر الأساسية في الاختبارات «مخطط أدنى مستنتَج» لأن dump التثبيت ليس في المستودع (`lavkoo:docs/integrations/risks.md:40-48`). ولا يوجد أي اختبار لعملة غير الريال أو هاتف غير سعودي أو أكثر من حساب لمزوّد (بحث `AED|KWD|BHD|OMR|QAR` ومفاتيح الاتصال `971|965|974|973|968` في `tests/Feature/Fulfillment` و`tests/Unit/Fulfillment` و`tests/Feature/Concurrency` و`tests/Support`: لا نتيجة؛ اختبارات العملة كلها عن الريال، مثل `lavkoo:tests/Feature/Fulfillment/RouterTest.php:293`، ومتجر عملته الافتراضية غير الريال يُعامل حالةً مُعلَّمة للإصلاح — `lavkoo:tests/Feature/Fulfillment/PilotServerCompatibilityTest.php:243`).

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الموضع | الحالة |
|---|---|---|---|
| A1 | «المتجر سعودي فقط»: عملة المزوّدين متغيّر بيئة واحد افتراضيه `SAR` | `lavkoo:config/fulfillment.php:36-39`، `lavkoo:config/fulfillment.php:59` | VERIFIED |
| A2 | عملة الشحنة تُختم من الإعداد العام لا من الطلب؛ افتراضي العمود `SAR` | `lavkoo:app/Services/Fulfillment/FulfillmentRouter.php:153`، `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:57` | VERIFIED |
| A3 | خانتان عشريتان في كل مبلغ يُرسل إلى المزوّد أو يُقارَن بما يبلّغه: أعمدة الشحنة `cod_amount` و`items_total` و`shipping_allocation` و`total_amount` و`provider_cost` وعمود السطر `line_total` كلها `decimal(14,2)` (أما `unit_price` و`line_discount` و`line_tax` فهي `decimal(20,6)` وتُحفظ بوحدة التخزين كما تصل من السلة، و`unit_cost` `decimal(14,4)` — `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:138-144` و`lavkoo:app/Services/Fulfillment/OrderItemOwnershipSnapshotter.php:64-68`)؛ `round(…, 2)`؛ التقسيم بـ«السنت» (`* 100`)؛ `number_format(…, 2)`؛ الثابت `PROVIDER_DECIMALS = 2` («rounds to the halala»)؛ سماحية المقارنة 0.005 و0.01 | `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:55-60`، `lavkoo:app/Services/Fulfillment/Support/ProviderCurrency.php:45`، `lavkoo:app/Services/Fulfillment/CodAllocator.php:21`، `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodOrderPayloadBuilder.php:112-115`، `lavkoo:app/Services/Fulfillment/Readiness/Compatibility/CheckoutSection.php:23-24`، `lavkoo:app/Services/Fulfillment/Data/MoneyAssessment.php:63-66` | VERIFIED |
| A4 | `decimals()` معرَّفة في واجهة مصدر الأسعار لكنها غير مستدعاة (بحث `decimals\(\)` في `app/`: التعريف والتنفيذ فقط) | `lavkoo:app/Services/Fulfillment/Support/StoreCurrencyRateSource.php:42-45` | VERIFIED |
| A5 | حساب واحد لكل مزوّد: صف `integration_settings` يُقرأ برمز المزوّد؛ مسار webhook يحمل رمز المزوّد فقط؛ كل جداول `provider_*` مفتاحها النصّ `provider` | `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:73`، `lavkoo:routes/rest_api/fulfillment_webhooks.php:15`، `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:244-248` | VERIFIED |
| A6 | المزوّدون تعداد مغلق في الكود؛ قيمة مجهولة = داخلي | `lavkoo:app/Enums/Fulfillment/ProviderCode.php:9-13`، `lavkoo:app/Services/Fulfillment/OrderItemOwnershipSnapshotter.php:40-43` | VERIFIED |
| A7 | مصدر المنتج لطلبات المتجر عمود واحد عام؛ ولا يُسند إلا منتج تملكه الإدارة (لطلبات القنوات المصدر هو قرار التوجيه — 2.9 ج) | `lavkoo:app/Services/Fulfillment/ProductProviderAssignmentService.php:195-201` | VERIFIED |
| A8 | هاتف سعودي فقط وبادئة `00966`؛ دولة M5AZN الافتراضية `SA` | 2.14 | VERIFIED |
| A9 | ثلاثة مواقع مخزون افتراضية بأكواد ثابتة، وموقع واحد «نشط» لكل مزوّد (`forProvider` يأخذ الأول)، وحجوزات القنوات تُجمع على **كل** مواقع المزوّد لأن «المزوّد يبلّغ رقماً واحداً للمنتج» | `lavkoo:app/Services/SalesChannels/Inventory/InventoryLocationService.php:14-26`، `lavkoo:app/Services/SalesChannels/Inventory/InventoryLocationService.php:51-54`، `lavkoo:app/Services/SalesChannels/Inventory/ChannelHoldsOnProviderStock.php:39-68` | VERIFIED |
| A10 | مستودع واحد هو مرجع المخزون (`WAREHOUSES = [TorodPoint]`) ومستودع منصّة افتراضي واحد | `lavkoo:app/Services/Fulfillment/ProviderStockOwnership.php:28`، `lavkoo:app/Http/Controllers/Admin/Marketplace/WarehouseController.php:66-69` | VERIFIED |
| A11 | منطقة زمنية واحدة للتطبيق تُحوَّل إليها كل طوابع المزوّدين | `lavkoo:app/Services/Fulfillment/Support/AppTime.php:21-31` | VERIFIED |
| A12 | مفاتيح الإيقاف والطابور والمسوح عامة بلا بُعد سوق؛ مفاتيح cache/lock ثابتة (`sales_channels:stock-events:nudged`، `sales-channels:stock-events`) | `lavkoo:config/fulfillment.php:71-74`، `lavkoo:app/Services/SalesChannels/Inventory/Outbox/StockEventNudge.php:30`، `lavkoo:app/Jobs/SalesChannels/DrainStockEvents.php:44` | VERIFIED |
| A13 | صلاحية الإدارة: وحدة `3rd_party_setup` والـ«master admin» هو `admin_role_id === 1`، بلا نطاق دولة/كيان | `lavkoo:routes/admin/fulfillment.php:20-24`، `lavkoo:app/Http/Controllers/Admin/Fulfillment/IntegrationCenterController.php:377-380` | VERIFIED |
| A14 | الشحن: مسؤولية الشحن وقيد الدول والمناطق إعدادات عامة في `business_settings`؛ طريقة الشحن بلا دولة | 2.16 | VERIFIED |
| A15 | دليل مدن Torod يفترض «قائمة توصيل وطنية» واحدة (حدّ الاكتمال 1000 مدينة) | `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodCityDirectory.php:62-66` | VERIFIED |
| A16 | تكامل توصيل خاص بسوريا داخل مسار checkout | `lavkoo:app/Utils/OrderManager.php:1766-1779` | VERIFIED |
| A17 | أسعار وتكاليف المزوّد تُفسَّر بعملة التنفيذ العامة: تكلفة المورّد تُختم برمزها، سعر M5AZN يُحوَّل منها إلى الوحدة المخزَّنة، وسعر المنتج المدفوع إلى Torod يُحوَّل إليها | `lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznProductImporter.php:349`، `lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznProductImporter.php:419-423`، `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:837` | VERIFIED |
| A18 | المنتج المستورد من M5AZN يُنشأ منتج إدارة في المتجر الداخلي (`added_by = admin`، `shop_id` المتجر الداخلي) بلا أي بُعد دولة | `lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznProductImporter.php:220-223` | VERIFIED |

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Foundation: مزوّد/مصدر تنفيذ واحد لكل منتج لكل متجر، بلا fallback تلقائي | مصدر واحد لكل منتج (`products.fulfillment_provider`)؛ اللقطة غير قابلة للتغيير؛ كل إخفاق = حجز مسمّى لا تحويل؛ محرّك القنوات يحوّل التعارض إلى مراجعة يدوية | «لكل متجر» يتحقّق فقط إذا كان صف المنتج نفسه خاصاً بالمتجر (قرار كتالوج). معالجة القيم غير المعروفة (SEC-E11-10). ولطلبات القنوات المصدر هو قرار التوجيه لا عمود المنتج (مسار مطفأ افتراضياً) | 2.9؛ E11-F05، E11-F06 |
| Foundation: الإبقاء على Torod وM5AZN والمجمّعات والحجوزات والمزامنة وهوامش الأمان والـ outbox والمحوّلات | كلها موجودة ومختبرة ومعزولة بمفاتيح إيقاف | لا شيء يتعارض؛ يلزم عدم إعادة كتابتها | القسم 5 |
| Foundation: دعم مزوّدين مستقبليين خاصين بدولة | `ProviderRegistry` + عقد `ProviderAdapter` يسمحان بإضافة محوّل بإعداد وفئة | إضافة رمز تتطلّب تعديل التعداد و`SECRET_KEYS` و`whereIn` في مسارات الإدارة و`ProviderSyncService::task` وقائمة `WAREHOUSES` (مواضع مثبّتة بالاسم) | `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:30-35`، `lavkoo:routes/admin/fulfillment.php:56-65`، `lavkoo:app/Services/Fulfillment/ProviderSyncService.php:85-91` |
| Foundation: تمييز Fulfillment Account عن المزوّد | — | «المزوّد = الحساب» في التخزين والمفاتيح والـ webhook والمواقع | E11-F01 |
| Foundation: سطر الطلب يحتفظ بمصدر التنفيذ التاريخي | لقطة كاملة لسطور المزوّدين + سجل تغيّر مزوّد المنتج | السطر الداخلي بلا صف صريح؛ لا متجر/كيان/حساب على اللقطة | 2.12؛ E11-F10 |
| Foundation: مخزون مستقل لكل دولة بلا مخزون مشترك | قيد (`provider`, `external_id`, `external_variant_id`) يمنع ربط عنصر مزوّد واحد بمنتجَين؛ المجمّع مفتاحه المنتج | رقم البيع على صف المنتج؛ العزل يعتمد كلياً على كون المنتج صفاً لكل متجر. لا حجز لسلة المتجر. عضوية المجمّع بلا قيد دولة | E11-F07، E11-F19 |
| Foundation: كيان قانوني بائع لكل دولة (حسابات، تسويات) | — | حساب المزوّد وتحصيل COD غير مرتبطين بكيان قانوني؛ عملة واحدة | E11-F01، E11-F02 |
| Foundation: تمييز Warehouse/Inventory Location | ثلاثة مفاهيم قائمة | غير مترابطة وبلا دولة | E11-F08 |
| GCC-12 إيقاف متجر دون كسر الطلبات والـ webhooks والتنفيذ | webhook التنفيذ خارج مجموعة `api`؛ المسوح تعمل بالحالة؛ الحرّاس لا تعتمد على واجهة المتجر | مفاتيح الإيقاف الحالية عامة: استعمالها لإيقاف متجر واحد يوقف التنفيذ للجميع | E11-F15 |
| GCC-13 هوية عميل واحدة وطلبات منفصلة لكل متجر | الشحنات مربوطة بـ`order_id` فقط فترث نطاق الطلب | لا شيء في هذا المجال | 2.12 |
| GCC-14 دخول موظف واحد بنطاقات دولة/كيان | حماية بوحدة `3rd_party_setup` وmaster admin | لا نطاق دولة على مركز التكاملات وقائمة الشحنات والسجلات | E11-F16 |
| GCC-16 شحن محلي وعابر للحدود بحسب الأهلية | نقاط ارتكاز جاهزة: `OrderEligibility::check`، `preflight` المحوّل وأسباب الحجز، نمط `CheckoutCodGuard` قبل الدفع، قيد دول التوصيل، `ShippingRateService`، عمود `country` في قواعد توجيه القنوات | لا يوجد أي فحص أهلية منتج (سلع منظَّمة) عند checkout المتجر؛ القيود عامة؛ الهاتف والمدن سعودية | E11-F04، E11-F11، E11-F12 |
| قيد مؤقت: نطاق السوق للـ caches والطوابير والمخزون | — | طابور ومسوح ومفاتيح قفل عامة | E11-F15 |
| ملاحظة KWD/BHD/OMR (3 خانات) | — | خانتان مثبّتتان في الطبقة كلها | E11-F03 |
| Guardrail 1: لا إعادة كتابة للمخزون/التنفيذ العامل | النظام معزول بمفاتيح ومغطّى باختبارات | — | القسم 5 |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أدنى إضافة موصى بها |
|---|---|---|---|
| `FulfillmentRouter`، `ProviderOrderDispatcher` (claim/send/settle)، `ShipmentTracker`، `FulfillmentStateMachine`، `ReconciliationService`، `FulfillmentCancellation`، `FulfillmentRecovery` | Preserve | قلب ضمان «مرة واحدة بالضبط» ومختبر | لا تغيير في المنطق؛ تمرير سياق الحساب/المتجر كقيمة مقروءة فقط |
| لقطة الملكية `provider_fulfillment_items` (ثبات + فريد `order_detail_id`) | Preserve + Extend | أساس «المصدر التاريخي» | أعمدة جديدة nullable (حساب التنفيذ، المتجر) تُملأ للطلبات الجديدة فقط؛ لا مساس بقائمة `IMMUTABLE` |
| `ProviderHttp` + `ExternalOperations` + مفتاحا البيئة + `dry_run` + `auto_dispatch` + go-live | Preserve | شبكة الأمان الرئيسية للنداءات الخارجية | لا تُستعمل لإيقاف متجر (GCC-12) |
| `PiiRedactor`، `SecretRedactor`، سياسات الحفظ والتنظيف | Preserve | حماية بيانات | — |
| `ProviderAdapter` والعقود الاختيارية و`ProviderRegistry` | Preserve + Extend | نقطة إضافة مزوّدي الدول | محوّل جديد لكل مزوّد دولة |
| `TorodPointAdapter`، `M5aznAdapter` وعملاؤهما وخرائط الحقول الموثّقة | Preserve | يعملان للسعودية | لا تُمدَّد لدول أخرى بتعديلها في مكانها |
| `InventorySyncService` (كاتب مسار المزامنة لمخزون المنتج المملوك لمزوّد) و`StorefrontStockRefresher` و`ProviderStockOwnership` | Preserve | حساب واحد يمنع البيع المزدوج | توثيق كتّاب `current_stock` خارج المسار الرئيسي (SEC-E11-08) قبل أي توسعة — لا تعديل في هذه المرحلة |
| المجمّعات والحجوزات والتقسيم والـ outbox | Preserve | مطلوب بالنص في القرارات ومختبر تحت التزامن | نطاق السوق يأتي من المنتج/الحساب لا من أعمدة جديدة على المجمّع |
| `DynamicFulfillmentRoutingEngine` وقراراته المُصدَرة | Preserve | لا fallback، وسجل قرار مُعلَّل | — |
| `CodPolicy`، `CodAllocator`، `CheckoutCodGuard`، `FulfillmentMoney` | Preserve + Refactor محدود | قواعد المال سليمة لكنها مثبّتة على عملة وخانتين | دقّة ووحدة صغرى مشتقّتان من عملة الطلب |
| `CurrencyCode` / `ProviderCurrency` / `FULFILLMENT_CURRENCY` | Refactor | عملة عامة واحدة | عملة حساب التنفيذ بدل الإعداد العام، مع إبقاء السلوك السعودي افتراضياً |
| `integration_settings` (صف لكل مزوّد) و`ProviderConnectionManager` | Extend | يحتاج بُعد الحساب | مفهوم Fulfillment Account جديد يشير إليه؛ الصف الحالي يصير الحساب السعودي |
| `products.fulfillment_provider` و`ProductProviderAssignmentService` | Preserve + Extend | مصدر واحد لكل منتج قائم | يكفي إن كان المنتج لكل متجر؛ وإلا جدول إسناد لكل (منتج، متجر) |
| `SaudiPhone`، بادئة `00966`، افتراضات `SA` | Refactor | سعودية بالبناء | تطبيع هاتف بحسب دولة العنوان؛ السلوك السعودي يبقى للعناوين السعودية |
| `provider_city_mappings` وخدمة المطابقة | Preserve + Extend | محايدة تجاه المزوّد | تفعيل `country_code` الموجود وتصفية المرشّحين بدولة الحساب |
| `inventory_locations`، `warehouses` | Extend (بعد قرار نموذج) | ثلاثة مفاهيم بلا دولة | ربطها بمفهوم Warehouse/Inventory Location واحد في نموذج GCC |
| الشحن: `ShippingMethod`، قيد الدول، `ShippingZone` | Extend | إعدادات عامة | نطاق متجر لطرق الشحن والقيود؛ فحص أهلية جديد |
| فحص أهلية المنتج للشحن العابر للحدود | New | غير موجود في checkout المتجر (بحث `prescription\|regulated\|restricted_product\|is_restricted\|shipping_restriction\|export_restrict\|import_restrict\|hazmat\|ship_to_countr\|eligible_countr` في `app/` و`Modules/` و`routes/` و`database/migrations/`: الموجود الوحيد بوابة `RegulatedProductGate` الخاصة بنشر قوائم القنوات، وضعها الافتراضي `off`، ولا يستدعيها مسار الطلب — `lavkoo:app/Services/SalesChannels/Listings/RegulatedProductGate.php:26-55`) | خدمة أهلية تُستدعى في مواضع `CheckoutCodGuard` و`OrderEligibility`؛ يُدرس نمط `RegulatedProductGate` (قواعد المالك + «الرفض عند الجهل») كسابقة لا كمكوّن جاهز |
| التشغيلات والصلاحية (`product_batches`، `BatchService`) وواجهة مخزون البائع | Preserve (لم تُفحص) | طبقة تتبّع فوق الكتالوج؛ شطب المنتهي يمرّ عبر `InventoryService` | تُفحص في جولة سدّ الفجوات قبل أي قرار (E11-F20) |
| Fulfillment Account (لكل دولة/كيان) | New | غير موجود | — |
| أدوات الجاهزية والتشغيل: `FulfillmentPreflight`، `fulfillment:compatibility`، قائمة go-live، التحقق الحي، و`docs/integrations/**` | Preserve | بوابات النشر الآمن على خادم مخططه غير موثّق | تُعاد استعمالها كبوابات Go/No-Go لكل دولة |
| حزم الاختبارات `tests/Feature/Fulfillment`، `tests/Unit/Fulfillment`، `tests/Feature/Concurrency` | Preserve + Extend | خط الأساس لانحدار السعودية | إضافة حالات عملة/هاتف/حساب غير سعودي دون تعديل القائمة |
| Delivery Syria | قرار مالك (عزل أو إزالة لاحقاً) | إرث سوري داخل checkout | لا يُمدَّد؛ يوثَّق ويُحسم مصيره |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E11-F01 | **المزوّد = الحساب.** صف `integration_settings` واحد لكل رمز مزوّد، تعداد مغلق، وكل جداول `provider_*` ومسار الـ webhook ومواقع المخزون الافتراضية مفتاحها رمز المزوّد فقط؛ وقيود التفرّد كلها بلا بُعد حساب (مرجع المزوّد، عنصر المزوّد، عنصر الكتالوج، مدينة المزوّد، alias المدينة، حالة المزامنة) | P1 | VERIFIED | `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:71-89`، `lavkoo:app/Enums/Fulfillment/ProviderCode.php:9-13`، `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:110-111`، `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:213`، `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:247-248`، `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:277`، `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:309`، `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:322`، `lavkoo:app/Http/Controllers/Api/FulfillmentWebhookController.php:39-42`، `lavkoo:app/Services/SalesChannels/Inventory/InventoryLocationService.php:14-26` | لا يمكن تمثيل «حساب تنفيذ لكل دولة/كيان قانوني» لمزوّد واحد دون ازدواج رمز أو خلط مراجع ومخزون وتسويات بين الكيانات | إدخال بُعد حساب تنفيذ إضافي بجانب رمز المزوّد (لا تحميل الرمز معنى الدولة)، مع بقاء الصف الحالي حساباً سعودياً وترحيل إضافي فقط |
| E11-F02 | **عملة تنفيذ عامة واحدة.** `FULFILLMENT_CURRENCY` (افتراضي `SAR`) تُختم على كل شحنة، والتحويل من جدول العملات العام لا من عملة الطلب | P1 | VERIFIED | `lavkoo:app/Services/Fulfillment/Support/CurrencyCode.php:26-30`، `lavkoo:app/Services/Fulfillment/FulfillmentRouter.php:153`، `lavkoo:app/Services/Fulfillment/Support/ProviderCurrency.php:29-46` | لمتجر غير ريالي (استنتاج من منطق التحويل، لا حالة قائمة اليوم): في `single_currency` تُحجز كل الشحنات (فشل آمن)؛ في `multi_currency` يُحسب مبلغ COD والقيمة المصرّحة بالريال ويُرسل رقماً مجرّداً لمزوّد دولة أخرى — خطر مالي مباشر إن فُعّل مزوّد لدولة غير السعودية قبل المعالجة (يرتفع إلى P0 في تلك الحالة) | عملة الشحنة تُشتقّ من عملة الطلب/حساب التنفيذ وتُحفظ في اللقطة؛ اختبار قبول لكل عملة قبل تفعيل أي مزوّد خارج السعودية |
| E11-F03 | **خانتان عشريتان مثبّتتان** في الأعمدة والتقريب وتقسيم COD والحمولة | P1 | VERIFIED | القسم 3 (A3، A4) | مبالغ KWD/BHD/OMR (3 خانات) تُقرَّب إلى خانتين: فرق في COD والمطابقة مع ما يبلّغه المزوّد (`cashMatches` بسماحية 0.01 — `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:645-652`) | دقّة مشتقّة من العملة؛ أعمدة جديدة أو توسيع إضافي؛ `CodAllocator` بالوحدة الصغرى للعملة |
| E11-F04 | **هاتف سعودي فقط** في preflight المحوّلين والحمولة؛ دولة M5AZN `SA` | P1 | VERIFIED | `lavkoo:app/Services/Fulfillment/Support/SaudiPhone.php:12-39`، `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodOrderPayloadBuilder.php:81-86` | رقم بصيغة دولية غير سعودية (أو بطول مختلف) يُحجز `hold_phone_invalid` (فشل آمن)، فيمتنع التنفيذ خارج السعودية عبر المحوّلين الحاليين. **تصحيح من التحقق المستقل:** نطاق الحجز الآلي مرهون بصيغ الهاتف المحفوظة (U12؛ SEC-E11-14) | تطبيع بحسب دولة العنوان في المحوّلات الجديدة، والتحقق من دولة العنوان قبل الهاتف؛ إبقاء `SaudiPhone` للعناوين السعودية فقط |
| E11-F05 | **مصدر المنتج (لطلبات المتجر) عمود عام واحد**، مقصور على منتجات الإدارة؛ ولطلبات القنوات يُحدَّد المصدر بقرار التوجيه (SEC-E11-10 وSEC-E11-11) | P1 | VERIFIED (الكود) / INFERRED (الملحق المقيّد) | `lavkoo:database/migrations/2026_11_05_100100_add_fulfillment_provider_to_products_table.php:22-26`، `lavkoo:app/Services/Fulfillment/ProductProviderAssignmentService.php:195-201` | (1) «مصدر لكل منتج لكل متجر» يتوقّف على قرار الكتالوج، وهو مفروض بعمود المنتج على طلبات المتجر فقط (2.9 ج)؛ (2) سيناريو النشر المتدرّج: انظر SEC-E11-10 | حسم نموذج المنتج-لكل-متجر أولاً؛ معالجة صريحة للقيم غير المعروفة (SEC-E11-10) |
| E11-F06 | **لا يوجد fallback تلقائي** بين المزوّدين في أيٍّ من الآليات الثلاث | INFO | VERIFIED | 2.9 | يطابق القرار المعتمد | الحفاظ على هذا السلوك واختباراته كما هي |
| E11-F07 | **لا حجز لمخزون المتجر**: الخصم عند إنشاء الطلب فقط؛ المجمّعات والحجوزات للقنوات وحدها | P1 | VERIFIED | `lavkoo:app/Utils/OrderManager.php:1345-1359`، `lavkoo:app/Services/SalesChannels/Inventory/ChannelOrderReservations.php:144` | عبارة القرار «pools/reservations» لا تصف مخزون المتجر؛ عزل المخزون بين الدول يعتمد على صف المنتج. نافذة الدفع بلا حجز قائمة اليوم (انظر SEC-E11-06) | توثيق ذلك في نموذج GCC؛ لا إدخال حجز سلة ضمن GCC إلا بقرار مستقل |
| E11-F08 | **ثلاثة مفاهيم لموقع المخزون غير مترابطة وبلا دولة** (`warehouses`، `inventory_locations`، رمز المزوّد)، مع مواقع وأكواد ثابتة | P1 | VERIFIED | 2.15 | مفهوم «Warehouse / Inventory Location» في الأسس لا يقابله كيان واحد؛ خطر إضافة مفهوم رابع | خريطة ملكية تحدّد أي الثلاثة هو المرجع لكل غرض قبل أي مخطط |
| E11-F09 | **جداول بلا ترحيلات في المستودع**: `product_batches`، `shipping_methods` (ترحيل تعديل عمود `cost` فقط)، `product_stocks` (ترحيلا تعديل فقط)، `warehouses`، `warehouse_stock`، `stock_movements`، `suppliers`، `purchase_orders`، `purchase_order_items`، `order_fulfillments`، `return_shipments`، `shipping_zones`، `delivery_syria_parcels`، `delivery_syria_governorate_rates`، والجداول الأساسية `products`/`orders`/`order_details` | P1 | VERIFIED (الغياب) / UNKNOWN (المخطط الفعلي) | بحث `Schema::create` وأسماء الجداول في `database/` و`Modules/`: لا نتيجة (أُعيد في التحقق المستقل بالنمط `create\(\s*['"]<table>['"]` لكل جدول على حدة: صفر لكل ما ذُكر، ولا ملف `.sql` متتبَّعاً في المستودع)؛ `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:10-17`؛ `lavkoo:docs/integrations/risks.md:40-48` | أنواع الأعمدة والفهارس والقيود الفعلية مجهولة؛ أي ترحيل GCC على هذه الجداول يُصمَّم على افتراض | الحصول على مخطط الإنتاج (قراءة فقط: `SHOW CREATE TABLE`) أو تقرير `fulfillment:compatibility` قبل تصميم الترحيلات |
| E11-F10 | **اللقطة التاريخية بلا سياق متجر/كيان/حساب**، والسطر الداخلي بلا صف مصدر صريح | P1 | VERIFIED | 2.12 | لا يمكن إثبات «أي حساب تنفيذ ولأي كيان» لطلب تاريخي بعد تعدّد الحسابات؛ الطلبات السعودية الحالية تبقى صحيحة ضمنياً | أعمدة إضافية nullable تُملأ من الآن؛ اعتبار الصفوف القديمة «الحساب السعودي الافتراضي» بقاعدة موثّقة لا بتحديث بيانات |
| E11-F11 | **مطابقة المدن سعودية عملياً**: `country_code` غير مكتوب، المرشّحون بلا تصفية دولة، مستورد المناطق يثبّت `SA`، alias المدينة فريد عالمياً | P1 | VERIFIED | 2.14؛ `lavkoo:database/migrations/2026_11_07_100000_create_address_master_tables.php:73` | وأسماء مدن متطابقة بين الدول تتصادم في الـ alias الفريد (تفاصيل الاعتماد الآلي في SEC-E11-16) | تصفية بدولة حساب التنفيذ وتفعيل العمود الموجود (مجال العناوين) |
| E11-F12 | **الشحن بإعدادات عامة ولا فحص أهلية منتج في checkout المتجر**: مسؤولية الشحن، قيد الدول/الرموز، طرق الشحن بلا دولة؛ مناطق الشحن للويب فقط | P1 | VERIFIED | 2.16؛ القسم 5 (سجلّ البحث عن فحص الأهلية) | GCC-16 يتطلّب أهلية (سلع منظَّمة، استيراد/تصدير، وجهة) لا يوجد لها موضع في checkout اليوم؛ السابقة الوحيدة القريبة `RegulatedProductGate` خاصة بنشر قوائم القنوات (`lavkoo:app/Services/SalesChannels/Listings/RegulatedProductGate.php:26-55`) | خدمة أهلية جديدة في مواضع الحراسة القائمة؛ نطاق متجر لطرق الشحن والقيود |
| E11-F13 | **Delivery Syria داخل مسار checkout**: نداء HTTP متزامن بعد commit، ربط باسم طريقة الشحن، تسجيل warning لكل طلب، جداول بلا ترحيلات، وفئات مكرّرة غير مستعملة (`App\Services\DeliverySyriaService`، المتحكم الجذري) | P2 | VERIFIED | `lavkoo:app/Utils/OrderManager.php:1781-1786`، `lavkoo:app/Utils/OrderManager.php:1827-1829`، `lavkoo:app/Utils/OrderManager.php:1973` | ضجيج سجلات وتأخير checkout إن فُعّل؛ نمط ثانٍ للتكامل خارج إطار المحوّلات قد يُنسخ لدول أخرى | قرار مالك: عزل خلف مفتاح أو إزالة لاحقة بموافقة؛ عدم البناء عليه |
| E11-F14 | **حقول التوصيل على الطلب الأم مستقلة عن شحنات المزوّدين**، وتطبيق العميل لا يستهلك نقطتي الشحنات | INFO | VERIFIED | 2.16 | تتبّع شحنات المزوّد يظهر على الويب فقط؛ تجربة التطبيق لطلب مقسَّم ناقصة | يُدرج في مصفوفة أثر التطبيقات |
| E11-F15 | **مفاتيح الإيقاف والطابور والمسوح عامة** وتُقرأ عند بدء العملية | INFO | VERIFIED | القسم 3 (A12)؛ `lavkoo:docs/integrations/operations.md:34-35` | إيقاف متجر (GCC-12) يجب ألا يُنفَّذ بهذه المفاتيح، وإلا توقّف التنفيذ والـ webhooks لكل الدول | مفتاح إيقاف المتجر في طبقة الواجهة فقط؛ إبقاء التنفيذ والمسوح عاملة |
| E11-F16 | **لا نطاق دولة/كيان لصلاحيات مركز التكاملات** والشحنات وسجل النداءات | P1 | VERIFIED | القسم 3 (A13) | استنتاج لوضع متعدّد الدول (لا حالة قائمة اليوم): التفاصيل في SEC-E11-17. يرتفع إلى P0 إن فُعّلت دولة ثانية قبل تطبيق النطاقات (تسرّب بين الكيانات) | تطبيق نطاقات GCC-14 على مسارات `admin/fulfillment-providers` عبر الطلب/الحساب |
| E11-F17 | **انحرافات توثيق ونظافة**: دليل التشغيل يقول إن إعادة المنتج إلى «داخلي» ممنوعة أثناء الطلبات الجارية بينما الافتراضي في الكود يسمح؛ `decimals()` غير مستعملة؛ الوزن غير موثوق (القيمة الافتراضية 1 كغ) | P2 | VERIFIED | `lavkoo:docs/integrations/operations.md:176-177` مقابل `lavkoo:config/fulfillment.php:48`؛ `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodOrderPayloadBuilder.php:88-96` | سوء فهم تشغيلي؛ تسعير شحن بالوزن غير ممكن اليوم | تصحيح الوثيقة ضمن مرحلة التوثيق |
| E11-F18 | **قيم الإنتاج مجهولة**: أي المزوّدين مفعّل، حالة المفاتيح، وجود الجداول، عدد الشحنات المفتوحة، نموذج العملة | INFO | UNKNOWN | مواضع القراءة في الكود: `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:84`، `lavkoo:app/Services/Fulfillment/FulfillmentSchema.php:20-29`، `lavkoo:app/Services/Fulfillment/Support/StoreCurrencyRateSource.php:10-13` | خطة الترحيل والتراجع تعتمد عليها | قراءة فقط لـ`integration_settings` (بلا أسرار) و`provider_sync_states` وعدّ الصفوف |
| E11-F19 | **لا قيد دولة على عضوية قوائم القنوات في المجمّع** (SEC-E11-09) | P1 | VERIFIED (غياب القيد في الكود) / INFERRED (الأثر) | `lavkoo:app/Services/SalesChannels/Inventory/Allocation/PoolMembers.php:24-39`، `lavkoo:app/Models/InventoryLocation.php:63-66`، `lavkoo:app/Models/SalesChannelAccount.php:25-27`، `lavkoo:app/Services/SalesChannels/Noon/NoonConnector.php:513` | حسابات القنوات تحمل اليوم دولة/سوقاً خاصاً بها (`market`، `marketplace_ids`، `country_code` لحساب noon) لا يقرؤه المجمّع؛ هل هذا «مخزون مشترك» بالمعنى المحظور في الأسس أم مسألة تعريف يحسمها نموذج GCC (لا تعارض مُثبَت مع قرار معتمد)؛ وهل توجد حسابات كهذه في الإنتاج: UNKNOWN | قرار موثَّق لنطاق عضوية القوائم بالتنسيق مع مجال القنوات (SEC-E11-09) |
| E11-F20 | **طبقة التشغيلات والصلاحية وواجهة مخزون البائع لم تُفحص** (رُصدت في التحقق المستقل): `product_batches` و`BatchService` (تتبّع رقم التشغيلة وتاريخ الانتهاء فوق الكتالوج، والشطب يمرّ عبر `InventoryService`)، و`SellerInventoryController` تحت `v3/seller` وميزة `inventory` في تطبيق البائع | INFO | VERIFIED (الوجود) / UNKNOWN (السلوك والمخطط) | `lavkoo:app/Services/Marketplace/BatchService.php:9-17`، `lavkoo:app/Models/ProductBatch.php:15-18`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:23-39`، `lavkoo:routes/rest_api/v3/seller.php:654-663`، `lavkooappseller:lib/utill/app_constants.dart:285-287` | تتبّع الصلاحية ذو صلة مباشرة بسلع الصيدلة وبأهلية الشحن العابر للحدود (GCC-16)؛ وأي تغيير في مفاهيم المستودع/الموقع يمسّ عقد واجهة البائع وتطبيقه (Guardrail 4). الجدول `product_batches` بلا ترحيل إنشاء (E11-F09) | فحص هذه الطبقة في جولة سدّ الفجوات وإدراجها في خريطة ملكية المخزون (E11-F08) ومصفوفة أثر تطبيق البائع |

مسائل أمنية مقيّدة في هذا المجال:

- `SEC-E11-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-07: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-08: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-09: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-10: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-11: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-12: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-13: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-14: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-15: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-16: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- `SEC-E11-17: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`

## 7. أسئلة مفتوحة وعناصر UNKNOWN

| # | السؤال | ما يحسمه |
|---|---|---|
| U1 | هل ترحيلات التنفيذ والمخزون مطبَّقة في الإنتاج، وهل `FULFILLMENT_*` و`SALES_CHANNELS_ENABLED` مفعّلة؟ | `.env` الإنتاج وجدول `migrations` |
| U2 | أي المزوّدين مفعّل، وهل `auto_dispatch` حيّ، وكم شحنة مفتوحة/محجوزة؟ | قراءة `integration_settings.enabled/settings` (بلا `credentials`) و`provider_fulfillments` |
| U3 | المخطط الفعلي لجداول Stage C والجداول الأساسية (أنواع، فهارس، محرّك) | `SHOW CREATE TABLE` أو مخرجات `fulfillment:compatibility` من الخادم |
| U4 | هل Delivery Syria مفعّل، وهل يوجد عمود `shipping_methods.name` وطريقة شحن قيمته فيها `DeliverySyria`؟ | `business_settings.delivery_syria` ومخطط/بيانات `shipping_methods` |
| U5 | نموذج العملة الفعّال (`currency_model`) والعملة الافتراضية وعدد الخانات | `business_settings` |
| U6 | هل يعمل Torod Point / LogesTechs وM5AZN في دول الخليج الأخرى، وبأي حسابات وعملات ومعرّفات شركة؟ (إعداد M5AZN يذكر ثلاثة خوادم إقليمية — `lavkoo:config/fulfillment.php:130-132`) | المزوّدان أنفسهما / عقودهما |
| U7 | هل قيمة `own_warehouse_source` في الإنتاج `platform` وهل بُدّل صف الموقع؟ | `.env` و`inventory_locations` |
| U8 | حالة هذه المكوّنات في المستودع القديم وطلبات الدمج القديمة (#11 «stock») | `UNKNOWN (missing access: pharmacyksa98)` |
| U9 | هل تأكّد LogesTechs من حقل المخزون ومن رجوع رقم الفاتورة (شرطا مزامنة المخزون والإرسال الحقيقي)؟ | إعدادات المزوّد في الإنتاج (`stock_field_confirmed_at`، `invoice_round_trip`) |
| U10 | هل توجد في الإنتاج حسابات قنوات لأسواق أخرى مرتبطة بقوائم في مواقع المخزون (E11-F19)؟ | قراءة `sales_channel_accounts` (بلا أسرار) و`sales_channel_product_mappings.stock_location_id` |
| U11 | هل `managed_dispatch_enabled` مفعّل وما قواعد التوجيه القائمة (E11-F05)؟ | `.env` وجدول `fulfillment_routing_rules` |
| U12 | بأي صيغة تُحفظ أرقام هواتف العناوين (دولية أم محلية) — يحدّد مدى أثر E11-F04 | عيّنة مجهَّلة من بنية `shipping_addresses.phone` (الصيغة فقط لا القيم) |
| U13 | نتائج الاختبارات: لم يُشغَّل أي اختبار في هذا التدقيق ولا في التحقق المستقل | تشغيل CI من جهة مخوَّلة |

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| المزوّدون تعداد مغلق من قيمتين | `lavkoo:app/Enums/Fulfillment/ProviderCode.php:9-13` | VERIFIED |
| حلّ المحوّل من الإعداد برمز المزوّد | `lavkoo:app/Services/Fulfillment/ProviderRegistry.php:27-40` | VERIFIED |
| صف إعدادات واحد لكل مزوّد يُقرأ بالرمز | `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:71-89` | VERIFIED |
| الأسرار مشفّرة بـ`Crypt::encryptString` | `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:142` | VERIFIED |
| مفتاحا البيئة افتراضيهما `false` | `lavkoo:config/fulfillment.php:26` و`lavkoo:config/fulfillment.php:34` | VERIFIED |
| النقل يرفض الكتابة ومفتاحها مطفأ | `lavkoo:app/Services/Fulfillment/Support/ProviderHttp.php:52-58` | VERIFIED |
| عملة المزوّدين الافتراضية `SAR` | `lavkoo:config/fulfillment.php:59` | VERIFIED |
| عملة الشحنة من الإعداد العام | `lavkoo:app/Services/Fulfillment/FulfillmentRouter.php:153` | VERIFIED |
| التقريب إلى خانتين | `lavkoo:app/Services/Fulfillment/Support/ProviderCurrency.php:45` | VERIFIED |
| تقسيم COD بالسنت | `lavkoo:app/Services/Fulfillment/CodAllocator.php:19-44` | VERIFIED |
| خصم مخزون checkout ذرّي مشروط | `lavkoo:app/Utils/OrderManager.php:1345-1356` | VERIFIED |
| مسار ما بعد الدفع يسمح بالبيع الزائد | `lavkoo:app/Utils/module-helper.php:163-167` | VERIFIED |
| السلة تقرأ `current_stock` مباشرة | `lavkoo:app/Utils/CartManager.php:422-424` | VERIFIED |
| اللقطة داخل معاملة الطلب | `lavkoo:app/Utils/OrderManager.php:1363-1377` | VERIFIED |
| مصدر اللقطة `fulfillment_provider` من نسخة المنتج | `lavkoo:app/Services/Fulfillment/OrderItemOwnershipSnapshotter.php:40-43` | VERIFIED |
| اللقطة غير قابلة للتعديل أو الحذف | `lavkoo:app/Models/ProviderFulfillmentItem.php:59-72` | VERIFIED |
| التوجيه يجمّع حسب مزوّد اللقطة | `lavkoo:app/Services/Fulfillment/FulfillmentRouter.php:60-79` | VERIFIED |
| فريد (طلب، مزوّد، جيل) ومرجع المزوّد | `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:110-111` | VERIFIED |
| job الإرسال بمحاولة واحدة | `lavkoo:app/Jobs/Fulfillment/DispatchProviderFulfillment.php:24` | VERIFIED |
| قفل مشترك لكل شحنة | `lavkoo:app/Jobs/Fulfillment/OneAtATimePerFulfillment.php:15-18` | VERIFIED |
| النتيجة الغامضة لا يُعاد إرسالها | `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:283-288` | VERIFIED |
| ثمانية مسوح معزولة | `lavkoo:app/Services/Fulfillment/FulfillmentRecovery.php:269-291` | VERIFIED |
| الجدولة | `lavkoo:app/Console/ScheduleDefinition.php:92-98` | VERIFIED |
| مسار الـ webhook وحدّ المعدّل | `lavkoo:routes/rest_api/fulfillment_webhooks.php:15-18`، `lavkoo:app/Providers/FulfillmentServiceProvider.php:50` | VERIFIED |
| مقارنة السرّ بزمن ثابت | `lavkoo:app/Http/Controllers/Api/FulfillmentWebhookController.php:75-78` | VERIFIED |
| حدث webhook يُخزَّن مرة واحدة | `lavkoo:app/Services/Fulfillment/WebhookEventProcessor.php:58-73` | VERIFIED |
| تأكيد الادعاءات النهائية مع المزوّد | `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodPointAdapter.php:576-619` | VERIFIED |
| آلة الحالات ترفض القديم والنهائي | `lavkoo:app/Services/Fulfillment/FulfillmentStateMachine.php:28-59` | VERIFIED |
| كاتب مسار المزامنة لمخزون المنتج المملوك لمزوّد (توجد كتابات أخرى خارج هذا المسار — SEC-E11-08) | `lavkoo:app/Services/Fulfillment/InventorySyncService.php:284-351` | VERIFIED |
| مصدر تنفيذ طلب القناة يُحدَّد بقرار التوجيه (SEC-E11-11) | `lavkoo:app/Services/SalesChannels/Routing/DynamicFulfillmentRoutingEngine.php:47-80` | VERIFIED |
| ثبات اللقطة بأحداث النموذج لا بقيد قاعدة بيانات | `lavkoo:app/Models/ProviderFulfillmentItem.php:59-72` | VERIFIED |
| نداءات الواجهات عبر النقل المشترك مع استثناء موثّق (SEC-E11-07) | `lavkoo:app/Services/Fulfillment/Support/ProviderHttp.php:47-58` | VERIFIED |
| بوابة السلع المنظَّمة لقوائم القنوات فقط | `lavkoo:app/Services/SalesChannels/Listings/RegulatedProductGate.php:26-55` | VERIFIED |
| عضوية المجمّع بلا شرط دولة (SEC-E11-09) | `lavkoo:app/Services/SalesChannels/Inventory/Allocation/PoolMembers.php:24-39` | VERIFIED |
| نطاق فحص الهاتف السعودي (SEC-E11-14) | `lavkoo:app/Services/Fulfillment/Support/SaudiPhone.php:12-39` | VERIFIED |
| طبقة التشغيلات والصلاحية موجودة ولم تُفحص | `lavkoo:app/Services/Marketplace/BatchService.php:9-17` | VERIFIED (الوجود) |
| مستودع Torod وحده مرجع المخزون | `lavkoo:app/Services/Fulfillment/ProviderStockOwnership.php:28` | VERIFIED |
| إسناد المزوّد لمنتجات الإدارة الفيزيائية بربط معتمد | `lavkoo:app/Services/Fulfillment/ProductProviderAssignmentService.php:193-213` | VERIFIED |
| عنصر مزوّد لمنتج واحد | `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:244-248` | VERIFIED |
| مفتاح المجمّع | `lavkoo:database/migrations/2026_11_10_100000_add_stock_allocation.php:51` | VERIFIED |
| معادلة الصافي والعجز | `lavkoo:app/Services/SalesChannels/Inventory/PoolFigures.php:40-46` | VERIFIED |
| الحجز لطلبات القنوات فقط | `lavkoo:app/Services/SalesChannels/Inventory/ChannelOrderReservations.php:144` | VERIFIED |
| كاتب واحد لتقسيم المجمّع تحت قفل | `lavkoo:app/Services/SalesChannels/Inventory/Allocation/PoolAllocationService.php:43-48` | VERIFIED |
| الـ outbox يُكتب داخل المعاملة | `lavkoo:app/Services/SalesChannels/Inventory/Outbox/StockEventOutbox.php:36-67` | VERIFIED |
| محرّك القنوات: تعارض المخزون = مراجعة يدوية | `lavkoo:app/Services/SalesChannels/Routing/DynamicFulfillmentRoutingEngine.php:151-153` | VERIFIED |
| ثلاثة مواقع افتراضية بأكواد ثابتة | `lavkoo:app/Services/SalesChannels/Inventory/InventoryLocationService.php:14-26` | VERIFIED |
| `WarehouseService` لا يمسّ `current_stock` | `lavkoo:app/Services/Marketplace/WarehouseService.php:13-17` | VERIFIED |
| هاتف سعودي فقط | `lavkoo:app/Services/Fulfillment/Support/SaudiPhone.php:38` | VERIFIED |
| بادئة `00966` | `lavkoo:app/Services/Fulfillment/Providers/TorodPoint/TorodOrderPayloadBuilder.php:81-86` | VERIFIED |
| `provider_city_mappings.country_code` في المخطط | `lavkoo:database/migrations/2026_11_07_100200_create_provider_city_mapping_tables.php:32` | VERIFIED |
| `address_regions.country_code` افتراضيه `SA` | `lavkoo:database/migrations/2026_11_07_100000_create_address_master_tables.php:24` | VERIFIED |
| طريقة الشحن بلا دولة (حقول النموذج؛ مخطط الجدول UNKNOWN) | `lavkoo:app/Models/ShippingMethod.php:32-39` | VERIFIED |
| مناطق الشحن للويب فقط | `lavkoo:app/Http/Controllers/Customer/SystemController.php:79-102` | VERIFIED |
| hook Delivery Syria لكل طلب | `lavkoo:app/Utils/OrderManager.php:1781-1786` | VERIFIED |
| master admin = الدور 1 | `lavkoo:app/Http/Controllers/Admin/Fulfillment/IntegrationCenterController.php:377-380` | VERIFIED |
| اختبارات التزامن تحتاج MySQL/MariaDB | `lavkoo:tests/Feature/Concurrency/StockConcurrencyTest.php:54` | VERIFIED |
| المخطط الأساسي غير موجود في المستودع | `lavkoo:docs/integrations/risks.md:40-48` | VERIFIED (نصّ الوثيقة) |
| حالة الإنتاج (تفعيل، ترحيل، بيانات) | — | UNKNOWN |

## 9. سجل التحقق المستقل

> أُجري التحقق قراءةً فقط على الشجرة المحلية (`lavkoo@98e795b5`؛ الفرق عن الـ SHA المدقَّق `lavkoo@7f42777c` ملفات تحت `docs/gcc/` فقط). لم يُشغَّل أي كود أو اختبار، ولم يُتَّصل بأي شبكة.

### 9.1 الأعداد

| البند | العدد |
|---|---|
| ادعاءات فُحصت دلالياً (فتح الموضع المستشهَد به ومطابقته) | 84 |
| منها: فجوات P0/P1 (كلها) | 13 (E11-F01…F05، F07…F12، F16، F19) |
| منها: فجوات أخرى (INFO/P2/UNKNOWN) | 6 |
| منها: ادعاءات حاملة في الأقسام 1–5 و8 | 65 |
| مؤكَّدة كما كُتبت | 66 |
| مصحَّحة (صياغة، استشهاد، تعميم زائد، حالة أو بحث غير مسجَّل) | 15 |
| مدحوضة | 1 (الادعاء الفرعي «أي عنوان غير سعودي يُحجز» في E11-F04) |
| غير قابلة للتحقق بالقراءة الساكنة | 2 (قيم الإنتاج — E11-F18؛ وعدد «27 ملفاً فيها أنماط كتابة» لم يُعَد عدّه) |
| عيوب الفاحص الآلي للاستشهادات | 0 قبل التعديل، 0 بعده |

أُعيد عدّ الأرقام الواردة في القسم 1 فطابقت: 158 ملفاً و19,457 سطراً في `app/Services/Fulfillment`؛ `current_stock` في 291 سطراً ضمن 74 ملفاً؛ 52 / 14 / 9 ملفات اختبار بـ1010 / 127 / 45 دالة.

### 9.2 التصحيحات المُجراة

| # | الادعاء الأصلي | المشكلة | التصحيح |
|---|---|---|---|
| 1 | «أي عنوان غير سعودي يُحجز `hold_phone_invalid` (آمن)» (E11-F04) | تعميم مدحوض في صياغة الأثر (SEC-E11-14) | أُعيدت كتابة أثر E11-F04 و2.14 مع وسم INFERRED، وأُضيف U12 |
| 2 | «مصدر المنتج عمود عام واحد» و«اللاقط يقرأ `fulfillment_provider` من نسخة المنتج» (2.9، E11-F05، A7) | صحيح لطلبات المتجر فقط؛ لطلبات القنوات مصدر تنفيذ محدَّد بقرار التوجيه (SEC-E11-11) | فقرة تصحيح في 2.9 (ج)، وتعديل E11-F05 وA7 وصف القسم 4، وإضافة U11 |
| 3 | «نداءات الواجهات عبر `ProviderHttp::send`» (2.3) | تعميم زائد في وصف النقل المشترك (SEC-E11-07) | قُيّد الادعاء بنداءات واجهات المزوّدين وسُجّل البحث والاستثناء |
| 4 | «الكاتب الوحيد لمخزون المنتج المملوك لمزوّد» (2.6، القسم 5، القسم 8) | يناقضه ما يقرّه الملف نفسه ووثيقة المخاطر عن كتّاب إضافيين (SEC-E11-08) | صار «كاتب مسار المزامنة» مع الاستشهاد بـ`docs/integrations/risks.md:110-114` |
| 5 | «اللقطة غير قابلة للتعديل ولا للحذف» (2.12) | الثبات بأحداث النموذج فقط؛ التفاصيل في SEC-E11-12 | فقرة «حدّ الثبات» بصياغة محايدة |
| 6 | «الادعاءات النهائية تُؤكَّد بسؤال المزوّد» و«كل فشل جواب 401» (2.11) | صياغة مشروطة بإعدادات المزوّد (SEC-E11-01) | قُيّدت العبارتان؛ التفاصيل بقيت في الملحق المقيّد |
| 7 | جداول المدن الأربعة منسوبة إلى ترحيل واحد (2.14) | `provider_cities` و`provider_city_aliases` يُنشآن في ترحيل `2026_11_05_100000` | أُضيف الاستشهاد الصحيح |
| 8 | «أعمدة `decimal(14,2)` في كل مبالغ التنفيذ» (A3) | `unit_price`/`line_discount`/`line_tax` بدقة (20,6) و`unit_cost` (14,4) | حُدّدت الأعمدة ذات الخانتين (ما يُرسل ويُقارَن) وذُكرت البقية |
| 9 | «فحص أهلية المنتج: غير موجود» (القسم 5، E11-F12) | ادعاء غياب بلا بحث مسجَّل، وتوجد بوابة `RegulatedProductGate` لقوائم القنوات | سُجّل البحث، وقُيّد الغياب بـ checkout المتجر، وذُكرت البوابة كسابقة |
| 10 | خطأ في مسار أحد الحرّاس (2.7) | والأثر في E11-F19 موسوم VERIFIED كله (SEC-E11-09) | صُحّح المسار وأُضيف دليل بُعد الدولة على حسابات القنوات، وفُصلت الحالة إلى VERIFIED (غياب القيد) / INFERRED (الأثر)، وأُضيف U10 |
| 11 | E11-F16 بلا بحث مسجَّل للغياب وبلا وسم للأثر | الأثر استنتاج لوضع متعدّد الدول | سُجّل البحث، ووُسم الأثر، وذُكر شرط الارتفاع إلى P0 |
| 12 | E11-F18 بلا دليل في الجدول | خانة الدليل فارغة | أُضيفت مواضع القراءة في الكود |
| 13 | قائمة E11-F09 | ناقصة: `product_batches` و`shipping_methods` و`product_stocks` | أُضيفت مع سجلّ البحث المُعاد |
| 14 | «Delivery Syria إرث من نسخة سابقة» (2.16) | استنتاج مكتوب كحقيقة | وُسم INFERRED مع أساسه |
| 15 | «مطابقة مناطق الشحن» (E11-F12) | صياغة الأثر في SEC-E11-15 | صيغت بدقة مع الإحالة إلى الملحق المقيّد |
| 16 | ترويسة الملف: «commit توثيقي واحد» فوق الـ SHA المدقَّق | صارا اثنين عند التحقق | حُدّثت الترويسة |

إضافات من التحقق: E11-F20 (طبقة التشغيلات والصلاحية وواجهة مخزون البائع — لم تُفحص)، وملاحظة اشتراك `integration_settings` مع تكاملات أخرى (2.4)، والأسئلة U10–U13، وتهريب المحرف `|` داخل خلايا الجداول.

### 9.3 الفحوص المضادة التي لم تجد ما ينقض

- **لا حجز لمخزون المتجر (E11-F07):** بحث `reserv` في `app/` و`Modules/` و`routes/` و`config/` خارج وحدة القنوات: لا آلية حجز للسلة أو checkout؛ `reserve(` له موضعان فقط (`ReservationService` لطلبات القنوات، و`RefundService` لمبالغ الاسترداد).
- **مواضع الالتقاط:** `OrderManager` و`ChannelOrderMaterializer` فقط؛ تعديل الطلب محروس بـ`FulfillmentOrderGuard::editBlockedReason` (موضعان).
- **كاتب `products.fulfillment_provider`:** `ProductProviderAssignmentService::assign` وحده (بحث أنماط الإسناد في `app/` و`Modules/`).
- **`decimals()` غير مستدعاة:** التعريف والتنفيذ فقط.
- **لا اختبار لعملة خليجية غير الريال:** بحث `AED|KWD|BHD|OMR|QAR` في مجلدات الاختبار المذكورة: لا نتيجة (توجد اختبارات لمتجر أساسه USD).
- **`provider_city_mappings.country_code` غير مكتوب:** بحث `country_code` في `app/` كله: لا موضع يكتبه.
- **لا نطاق دولة في متحكمات `Admin/Fulfillment`.**
- **لا تعارض مع `docs/gcc/DECISIONS.md`:** لا توصية هنا تعيد فتح قرار معتمد؛ E11-F06 يؤكّد مطابقة «لا fallback تلقائي»، وE11-F19 يطرح مسألة تعريف لا تعارضاً مُثبَتاً.

### 9.4 مخاوف متبقّية

1. **معايرة الدرجات:** E11-F02 وE11-F16 موسومتان P1 مع شرط ارتفاع إلى P0 عند تفعيل دولة ثانية قبل المعالجة؛ على التقرير الجامع أن يحسم درجة واحدة لكل منهما وفق خطة الإطلاق.
2. **E11-F04:** مدى أثر الفحص يتوقّف على صيغة حفظ الهواتف (U12) وعلى صيغ الترقيم في دول الخليج، وهي معرفة خارج المستودع لم تُتحقَّق منها هنا إلا منطقياً من النمط.
3. **E11-F19 وE11-F05 (مسار القنوات):** يتقاطعان مع مجال القنوات (E12)؛ يلزم توحيد الصياغة بين الملحقَين.
4. **الملحق المقيّد:** فُحصت استشهاداته آلياً (صفر عيوب) وعيّنة منها دلالياً (ثلاثة مواضع)، لا كلها.
5. **الملف الرئيس يذكر وثيقة `docs/integrations/security-findings.md` بالاسم فقط؛** الوثيقة نفسها منشورة في المستودع وقرار إبقائها قرار مالك (مذكور في الملحق المقيّد).
6. **لم تُشغَّل الاختبارات؛** كل ما قيل عن التغطية مبنيّ على أسماء الدوال والملفات.

### 9.5 فجوات التغطية (أجزاء من نطاق المجال لم يفحصها الملف)

- التشغيلات والصلاحية (`product_batches`، `BatchService`، `BatchController`) — E11-F20.
- واجهة مخزون البائع `v3/seller/seller-center/inventory/*` وتحديث المخزون بالجملة، وميزة `inventory` وطلبات إعادة التخزين في تطبيق البائع.
- كتابات `current_stock` في متحكمات المنتج (الإدارة والبائع)، POS (ثلاثة مسارات)، `OrderEditManager`، `BulkStockOperation`.
- أوامر الشراء والمورّدون والمرتجعات اللوجستية (`PurchaseOrderService`، `ReturnLogisticsService`) بالتفصيل، واستعمال `OperationalCosts` لمواقع المخزون.
- حساب تكلفة الشحن (`shipping_types`، `category_shipping_costs`، `cart_shippings`)، والشحن المجاني، ودورة المندوب ومحفظته، وشاشات الشحن والتتبّع في التطبيقين.
- قوالب الإدارة، وفئات `Readiness/*` و`Verification/*`، و`M5aznProductImporter` و`TorodCityDirectory` كاملةً.
