# Gap matrix — what the code does today, and what the owner's plan still needs

مصدر هذا الجدول هو **الشيفرة نفسها** على `main` عند `a687ecb` (مصفوفات القدرات في الموصّلات، وسجل العمليات المسجّلة، والخدمات والجداول)، لا تقارير سابقة. كل سطر يسمّي الملف الذي يُثبته.

القراءة: **مبني** = يعمل ومُختبر. **مسجَّل** = العملية مُعلنة في `ChannelOperations` فلا يرفضها الناقل، لكن لا يستدعيها شيء. **غير مبني** = لا شيفرة.

## 0. الحالة العامة

| الشيء | الحالة | أين |
|---|---|---|
| القنوات التي لها موصّل | Amazon، Trendyol فقط | `config/sales_channels.php` → `connectors`، و`SalesChannel` enum فيه `storefront، amazon، trendyol` |
| **Noon** | **غير مبني بالكامل**: لا موصّل ولا حالة في الـenum ولا إعداد ولا عمليات مسجّلة | — |
| رفع منتج من المتجر إلى قناة | **مبني لـAmazon في هذه الجولة** (مسودة ← ما تطلبه القناة ← بوابات ← `putListingsItem` ← قبول/رفض)، ومطفأ افتراضيًا؛ وTrendyol وNoon يرفضان صريحًا `channel_cannot_create_listings` | `Listings/*`، `Amazon/AmazonConnector::putListing()`، [listings.md](listings.md) |
| محاسبة Odoo | **مبنية على هذا الفرع ومطفأة بالكامل**: نقلٌ يُكتشف (XML-RPC و JSON-2)، فحصٌ يقرأ النسخة ولا يفترضها، ربط دليل حسابات بموافقة، بانٍ للمسوّدات بسبع رفضات مُسمّاة، صندوق صادر بسياجٍ يُكتب قبل الطلب، عكسٌ ومرتجعات، مطابقة ثلاثية، وأربع شاشات. **ولا شيء منها يُرسل**: أربعة مفاتيح مطفأة (`config/odoo.php`) وموافقة مسجَّلة، والترحيل النهائي غير مبني أصلًا (Odoo لا توثّق طريقته) | `app/Services/Accounting/Odoo/*`، [odoo-accounting.md](odoo-accounting.md) |
| مزوّد التنفيذ لكل منتج | مبني (عمود `products.fulfillment_provider` وخدمة واحدة تكتبه) | `Fulfillment/ProductProviderAssignmentService.php` |
| مزوّد التنفيذ **لكل متغيّر/SKU** | غير مبني: الإسناد لكل منتج؛ و`provider_product_mappings` ربط لا إسناد | نفس الملف + `Models/ProviderProductMapping.php` |
| سياسات مصدر المخزون لكل حساب/منتج | الجدول موجود ويُقرأ، **ولا شيء يُنشئ صفوفه** (لا شاشة ولا أمر) | `inventory_source_policies`، `Inventory/Allocation/PoolAllocationService.php` |
| قيود المنتجات المنظَّمة (صيدلية) | **مبنية كبوابة على قائمة المالك** (فئات وأنماط أسماء): لا نشر تلقائي لصنف مطابق، ويلزمه شخص مسمّى ويُسجَّل. القائمتان تُسلَّمان فارغتين فلا توقف شيئًا حتى يملأهما المالك، وليست بديلًا عن قواعد كل منصة | `Listings/RegulatedProductGate.php` |

## 1. إدارة المنتجات من مكان واحد (PR-A)

| المطلوب | الحالة | أين / ما ينقص |
|---|---|---|
| اختيار منتج والقنوات ثم فحص الحقول | **مبني في الخدمة والأمر والشاشة**: شاشة لكل منتج فيها بطاقة لكل حساب، تحفظ حقول القناة وتتحقق منها وتُرسل وتتابع | `channel_listing_drafts` (مسودة واحدة لكل حساب+منتج+متغيّر)، `ListingDraftService`، `ChannelListingController`، `channels:listings --check` |
| حقول المنصة الإلزامية | **مبني**: تُقرأ من القناة، وما لا تقوله القناة (مخطط JSON خلف رابط لا يُجلب) يأتي من قائمة المالك على الحساب ويُسجَّل أنها قائمته | `AmazonConnector::listingRequirements()`، `ListingRequirements::source` |
| سعر مستقل لكل قناة | **مبني** | `sales_channel_product_mappings.price_*`، `Publishing/PublishPolicyResolver.php` |
| معاينة قبل النشر | مبني للسعر/الكمية فقط (`VALIDATION_PREVIEW`) | `amazon.listings_validate` |
| إنشاء قائمة عبر API | **مبني لـAmazon** (`amazon.listings_put`، نطاق `product_writes`، قدرة `product_sync`)؛ Trendyol `product_create_update: built=false` وNoon لا موصّل | `AmazonOperations.php`، `Contracts/CreatesListings.php` |
| متابعة القبول/الرفض وأسبابه | **مبني**: أسباب القناة تُحفظ على المسودة، و`follow()` يسأل `getListingsItem` بمشكلاته، ولا إرسال ثانٍ لمعرفة النتيجة | `ListingPublisher::follow()`، `channels:listings --follow` |
| تحديث قائمة موجودة بدل التكرار | **مبني**: SKU البائع هوية ثابتة (الاستدعاء يَضبط لا يُضيف)، ومسودة لمنتج مُدرج سلفًا تحديث من البداية، وقائمة أخرى للمنتج نفسه ترفض المحاولة (`listing_already_exists`) | `ListingPublisher`، `ListingDraftService` |
| الاستيراد والربط بقوائم موجودة | **مبني** (مطابقة SKU/باركود، ولا مطابقة بالاسم) | `Sync/ProductMatcher.php` |
| فصل المنتج عن مزوّد الشحن | **مبني الآن** (هذه الجولة): التغيير يسري على ما يأتي، والطلبات المسجَّلة تبقى عند مزوّدها، والمخزون يُعاد حسابه من رقم المالك الجديد | `ProductProviderAssignmentService.php`، `InventorySyncService::rewriteStoreStockFromStoredFigures()` |

## 2. Amazon SP-API (PR-C)

| القدرة | الحالة | ملاحظة من الشيفرة |
|---|---|---|
| LWA، Orders v2026-01-01، FBA/FBM، FBA inventory، Finances، confirmShipment | **مبني** | `AmazonConnector::capabilityMatrix()` |
| Listings Items: بحث + `patch` للسعر والكمية + معاينة | **مبني** | `amazon.listings_patch_*` |
| Listings Items: إنشاء/استبدال قائمة (`putListingsItem`) | **غير مبني ولا مسجَّل** | يحتاج عملية جديدة بنطاق `product_writes` وقدرة `ProductSync` |
| Catalog Items (مطابقة ASIN) | مسجَّل، غير مبني | `catalog_items: built=false` |
| Product Type Definitions | مسجَّل، غير مبني | `product_type_definitions: built=false` |
| `JSON_LISTINGS_FEED` | مسجَّل (أربع عمليات)، غير مبني | `feeds_json_listings: built=false` |
| Notifications (SQS/EventBridge) | مسجَّل، غير مبني؛ الاستقصاء هو المصدر | `notifications: built=false` |
| Reports (التسويات، كامل القوائم) | مسجَّل، غير مبني | `reports: built=false` |
| شحنات متعددة/تتبع جزئي | غير مبني | — |

## 3. Trendyol (PR-D)

| القدرة | الحالة | ملاحظة |
|---|---|---|
| المصادقة، Product V2 (قراءة + سعر/مخزون بدفعات)، Order V2، الطرود، Picking/التتبع، Webhooks، المطالبات (قراءة)، كشف الحساب | **مبني** | `TrendyolConnector::capabilityMatrix()` |
| إنشاء/تعديل محتوى المنتج + جلب الفئات والعلامات والخصائص | **غير مبني** | `product_create_update: built=false` |
| إلغاء بنود / Unsupplied | غير مبني **بقرار** (يقفل المنتج عند Trendyol) | `cancel_unsupplied: built=false` |
| تقسيم الطرود | غير مبني | `split_packages: built=false` |
| رفع/ربط الفاتورة | مسجَّل، غير مبني | `invoice_upload: built=false` |
| قبول/رفض المرتجع | غير مبني | `claims_returns` (قراءة فقط) |

## 4. Noon (PR-E) — كل شيء غير مبني

لا موصّل ولا عمليات ولا إعداد. ما يلزم بناؤه: المصادقة وإدارة الحساب، Content/Catalog (`UpsertProduct`، `GetContent`)، Pricing (`BatchUpsertPricing`/`BatchGetPricing`)، Stock (`UpdateStock`/`GetStock`)، الطلبات والأحداث، المرتجعات والمالية، وتمييز FBN عن FBP/FBPI، ثم إدخاله في كل شاشة. **لا يُفترض أي مسار قبل قراءة التوثيق الرسمي الحالي والتحقق من صلاحيات الحساب.**

## 5. المخزون المشترك الفوري (PR-B)

| المطلوب | الحالة | أين |
|---|---|---|
| مصدر حقيقة لكل (منتج، متغيّر، موقع) | **مبني** | `Inventory/PoolNet.php`، `LocationStockReader.php` |
| حجز ذري محمي من التزامن | **مبني** (فهرس فريد + قفل صف المجمّع) | `Inventory/ReservationService.php`، `Allocation/PoolAllocationService.php` |
| خصم الحجز من مخزون المصدر مرة واحدة (المتجر + القنوات) | **مبني** (الجولة السابقة) | `ChannelHoldsOnProviderStock.php` |
| إعادة التوزيع بعد كل حدث | مبني بـ«المجمّعات المُعلَّمة» + تشغيل سريع كل 5 دقائق | `Allocation/DirtyPools.php`، `ScheduleDefinition.php` |
| **Transactional Outbox** | **غير مبني**: لا جدول أحداث صادرة ولا ضمان عدم فقد حدث | — |
| **Queue workers لكل حدث** | غير مبني: النشر أوامر مجدولة (5/15 دقيقة) لا وظائف مُصطفّة لكل تغيير | `ScheduleDefinition.php` |
| التحقق من تطبيق التحديث لدى المنصة | مبني (نتائج الدفعات + ما تقوله القراءة) | `Publishing/StockPricePublisher.php` |
| fail-closed عند تقادم المخزون أو نقص استيراد الطلبات | **مبني** | `Allocation/OrderFreshness.php`، `StockAvailability::isPublishable()` |
| تحديث محلي فوري (المتجر) | مبني بقدر دورة مزامنة المزوّد (30 دقيقة افتراضيًا، أدناها 5) | `operations.md` → *The storefront's side of a provider's shelf* |

## 6. Odoo (PR-F) — مبني ومطفأ، وقيد المالك ملزم

انظر [odoo-accounting.md](odoo-accounting.md): وحدة `OperationalCosts` تبقى المحرك الوحيد للتكلفة وFIFO والعمولات والربحية، وOdoo سجلّ محاسبي فقط. **ولا سطر واحد يُحسب هنا:** كل مبلغ يُرحَّل **يُقرأ** من `operational_cost_entries`.

| المطلوب | الحالة | الملفات |
|---|---|---|
| اتصال ومصادقة | مبني: النقل **يُكتشف** من نسخة الخادم لا يُفترض، و`version` على XML-RPC لا يحتاج مصادقة فيُختار النقل قبل استعمال أي مفتاح | `OdooTransport.php`، `OdooClient::discover()` |
| فحص الصلاحيات والشركة والعملة | مبني بما **يُمكن** فحصه: `ir.model` و`fields_get` والشركة والعملات والحسابات والضرائب والدفاتر عددًا. **ولا يدّعي صلاحية كتابة**: Odoo لا توثّق سؤالًا عنها، فالفحص يقول ما قرأه لا ما سيُسمح به | `OdooPreflightRun.php` |
| ربط دليل الحسابات والضرائب | مبني، **وبموافقة**: رِبطٌ لم يُعتمد لا يُرحَّل منه، وغير المربوط **رفضٌ مُسمّى** لا حساب احتياطي | `OdooAccountMap.php`، `odoo_account_mappings` |
| العملات | **فجوة معلنة**: تكلفة بعملة أجنبية **تُرفض باسمها** (`odoo_currency_map_not_built:<ccy>`) ولا تُرحَّل بقيمتها المحوّلة مع إسقاط الأصل | `OdooDraftBuilder::foreignCurrencyOf()` |
| مولّد القيود | مبني: مسوّدة واحدة لواقعة واحدة، سبع رفضات مُسمّاة، وأسماء الحقول من النسخة نفسها | `OdooDraftBuilder.php` |
| صندوق الصادر | مبني: مفتاح فريد لكل (مصدر، نسخة)، حجزٌ مشروط، سؤالٌ لـOdoo بالمرجع قبل كل إنشاء، **وسياج يُكتب قبل الطلب** | `OdooOutbox.php`، `OdooPostingRun.php` |
| العكس المحاسبي والمرتجعات | مبني: الرابط **يُقرأ** من الدفتر ولا يُستنتج، ولا يسبق العكسُ ما يعكسه، و`write`/`unlink` خارج قائمة العمليات | `OdooUndoLink.php`، `OdooUndoLinks.php` |
| المطابقة الثلاثية | مبنية: ثلاثة أرقام لمدّة واحدة، وفرقٌ يُعرض ولا يُغلق، و**null ليس صفرًا** | `OdooThreeWayReconciliation.php`، `odoo:reconcile` |
| الشاشات | أربع: الاتصال، الربط، الصندوق (بما فيه ما ينتظر شخصًا)، المطابقة | `Admin/Accounting/Odoo*Controller.php` |
| **الترحيل النهائي (مسوّدة ← مُرحَّل)** | **غير مبني، وفجوة معلنة**: Odoo لا توثّق طريقة ولا كتابة `state`، ومفتاحه موجود مطفأ | `config/odoo.php` → `final_posting_enabled` |
| جسم طلب `create` على JSON-2 | **فجوة معلنة**: صفحة 19.0 لم تُقرأ نصًّا، فالنقل يرفض الكتابة باسمها بدل أن يخمّن شكل الجسم | `OdooNotConfigured::JSON2_REQUEST_NOT_DOCUMENTED` |

## 7. اللوحة والمراقبة (PR-G)

| المطلوب | الحالة |
|---|---|
| مركز القنوات، المنتجات والقنوات، المخزون حسب الموقع، الطلبات الموحدة، التوجيه، السجلات، التكاليف والربحية | **مبني** لـAmazon وTrendyol (`app/Http/Controllers/Admin/SalesChannels`, `.../OperationalCosts`) |
| شاشة منتج موحَّدة تُظهر النشر لكل قناة والسعر والمخزون والمزوّد والموافقة والربحية في مكان واحد | **مبني**: بطاقة «قنوات البيع» في نموذج المنتج (قراءة فقط، ولا تتصل بأي قناة) وزرّها إلى شاشة الإدراج `admin/sales-channels/listings/{product}`: حفظ حقول القناة، التحقق، تسجيل تصريح المنتج المنظّم، الإرسال (POST مُقيّد + مربّع تأكيد)، وسؤال القناة عمّا تحمله. **الناقص**: معاينة الصفحة كما يراها المشتري، ورفع الصور |
| لوحة مراقبة (تأخر العمال، عمر المخزون، بيع زائد محتمل، طلبات بلا موقع، Odoo بحاجة مراجعة) | مبني جزئيًا: الهب يعرض تقادم المزامنة والحجوزات القديمة وما لا موقع له؛ لا عمال مُصطفّون لقياس تأخرهم، ولا Odoo | `AdminSalesChannelsHubTest.php`، `ChannelOrderReservations::unattributedUnits()` |
