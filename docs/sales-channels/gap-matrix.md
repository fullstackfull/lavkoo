# Gap matrix — what the code does today, and what the owner's plan still needs

مصدر هذا الجدول هو **الشيفرة نفسها** على `main` عند `a687ecb` (مصفوفات القدرات في الموصّلات، وسجل العمليات المسجّلة، والخدمات والجداول)، لا تقارير سابقة. كل سطر يسمّي الملف الذي يُثبته.

القراءة: **مبني** = يعمل ومُختبر. **مسجَّل** = العملية مُعلنة في `ChannelOperations` فلا يرفضها الناقل، لكن لا يستدعيها شيء. **غير مبني** = لا شيفرة.

## 0. الحالة العامة

| الشيء | الحالة | أين |
|---|---|---|
| القنوات التي لها موصّل | Amazon، Trendyol فقط | `config/sales_channels.php` → `connectors`، و`SalesChannel` enum فيه `storefront، amazon، trendyol` |
| **Noon** | **غير مبني بالكامل**: لا موصّل ولا حالة في الـenum ولا إعداد ولا عمليات مسجّلة | — |
| رفع منتج من المتجر إلى قناة | **مبني لـAmazon في هذه الجولة** (مسودة ← ما تطلبه القناة ← بوابات ← `putListingsItem` ← قبول/رفض)، ومطفأ افتراضيًا؛ وTrendyol وNoon يرفضان صريحًا `channel_cannot_create_listings` | `Listings/*`، `Amazon/AmazonConnector::putListing()`، [listings.md](listings.md) |
| محاسبة Odoo | **غير مبنية**: عقد `AccountingDestination` + وجهة لا تُرسل شيئًا | `Accounting/NullAccountingDestination.php`، `Providers/SalesChannelsServiceProvider.php` |
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
| **جلب خصائص الفئة كمتطلبات قائمة** | **مبني (PR-D)** | Product **V2**: `/product/categories/{id}/attributes`. أي الخصائص إلزامية، والقيم المقبولة حيث تُضمّنها Trendyol، و`allowCustom` (قيمة من عندنا مقبولة فلا تُسجَّل قائمة قيم). ومعرّفات الخصائص والقيم تُحفظ بجانب الأسماء لأن الكتابة تُرسل معرّفات. وفئة لا تُقرأ أو لا تسمّي خاصية واحدة = **UNKNOWN** لا «لا شيء مطلوب». `TrendyolCategoryMapper` |
| **إنشاء وتعديل محتوى المنتج** | **مبني (PR-D)** | Product V2، والاثنان **دفعتان**: الإنشاء `POST …/v2/products`، وتعديل المحتوى `POST …/products/content-bulk-update` (لا PUT في V2 — كان لـV1 المتقاعد). و`HTTP 200` يُعيد `batchRequestId` ويعني **أن الدفعة أُخذت**، لا أن منتجًا صار موجودًا، فالنتيجة `ACCEPTED_FOR_PROCESSING` و`batchResult()` يقول ما صار بكل بند. والباركود هو الهوية. **والفخ**: باركود في قائمة **غير المعتمدة** = `WORKING` لا «غائب» — المنتج موجود وينتظر مراجعة Trendyol، وإنشاؤه ثانيةً يترك منتجين وباركودًا مقفلًا. وما ترفض Trendyol تغييره لمنتج معتمد (barcode، productMainId، brandId، categoryId) يُرفض هنا بالاسم قبل إرسال أي شيء |
| **جلب العلامات التجارية** | **مبني (PR-D)** | `GET /product/brands/by-name` (مصفوفة `{id, name, luxe}`؛ ومطابقة Trendyol **حسّاسة لحالة الأحرف**). والتحقّق يحوّل اسم العلامة إلى معرّف Trendyol: مطابقة واحدة تُكتب على المسودة، وعدّة تُترك **لشخص** (عدّة علامات قد تحمل اسمًا واحدًا، والخطأ يضع منتجنا تحت علامة شركة أخرى)، ولا شيء يعني أن العلامة تُسجَّل عند Trendyol أولًا، واستدعاء فاشل **يرفع استثناءً** فلا تُقرأ «لا نعرف» كأنها «لا توجد». `ResolvesChannelBrands` |
| القائمة الكاملة للعلامات (مُصفّحة) | **غير مبني** | **غلاف الإجابة غير موثَّق**، والتوثيق يتناقض في حدود `size`، فلا يُبنى عليه مُحلِّل |
| رفع الصور | **غير مبني** | — |
| إلغاء بنود / Unsupplied | غير مبني **بقرار** (يقفل المنتج عند Trendyol) | `cancel_unsupplied: built=false` |
| صور المنتج | **مبني (PR-D)، وكان ثغرة لا تحسينًا** | `images` **مطلوب** في الإنشاء ("Evet" في جدول Trendyol نفسه)، و`GET /product/categories/{id}/attributes` — وهو كل ما كان يُسأل — يجيب بخصائص **الفئة** ولا يذكر حقلًا على مستوى المنتج إطلاقًا، **فلا شيء كان يستطيع اكتشاف الشرط**. والحدّ ٨ صور لكل باركود، والروابط يجب أن تكون "SSL sertifikalı \"https\" formatında".<br><br>وصور المتجر تُحوَّل إلى روابط بـ`ChannelProductImages` التي **ترفض ولا تتراجع**: `getStorageImages()` تُرجع **صورة بديلة** حين يغيب الملف، وعلى حِمل قناة يعني ذلك نشر صورتنا الرمادية البديلة كصورة منتج شخصٍ ما، على سوق حقيقي، وتبدو متعمَّدة.<br><br>**وقاعدتان تُرفض بهما الروابط قبل الإرسال:** متجرٌ على http (Trendyol تقول https صريحًا)، ومضيفٌ لا يصله سوق (localhost، أو عنوان IP، أو اسم محجوز `.local`/`.test`/`.example`). الثانية **قاعدتنا لا قاعدة Trendyol** ومُسمّاة كذلك: بدونها ينشئ تثبيتُ staging قوائم **حقيقية** صورها لا تُفتح ولا شيء على الشاشة يشرح السبب. وكل رفضٍ يُقال باسمه: «لا صور لهذا المنتج» و«متجرك يخدم الصور على http» يُرسلان الشخص إلى مكانين مختلفين تمامًا. | `ChannelProductImages`، `ListingRequirements::$alwaysRequired` |
| تقسيم الطرود | غير مبني | أربع نقاط موثَّقة بثلاث إجابات مختلفة: `split` يُعيد `newPackageId` **مفردًا**، و`quantity-split` يُعيد `newPackageIds` **جمعًا**، و`split-packages` يُعيد **٢٠٠ بلا مخطَّط ولا معرّف**. والعملية **غير متزامنة**: الطرود الجديدة تظهر بحالة `UnPacked` **والطرد الأصلي يتحوّل إلى `UnPacked` أيضًا**، فالوثيقة تقول أن يُعاد جلب الطرود أو يُستمع للـwebhooks لمعرفة `shipmentPackageId` و`cargoTrackingNumber` الجديدين. وشروط السماح أو الرفض **غير موثَّقة** عدا خطوة Picking. فأي بناء هنا يجب أن **يُعيد القراءة** ولا يثق بما كان يحمله، وهذا ما يجعله أكبر من بقية الفجوات لا أصغرها | `splitShipmentPackage`، `splitShipmentPackageByQuantity`، `splitMultiPackageByQuantity` |
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

## 6. Odoo (PR-F) — غير مبني، وقيد المالك ملزم

انظر [odoo-accounting.md](odoo-accounting.md): وحدة `OperationalCosts` تبقى المحرك الوحيد للتكلفة وFIFO والعمولات والربحية، وOdoo سجلّ محاسبي فقط.

## 7. اللوحة والمراقبة (PR-G)

| المطلوب | الحالة |
|---|---|
| مركز القنوات، المنتجات والقنوات، المخزون حسب الموقع، الطلبات الموحدة، التوجيه، السجلات، التكاليف والربحية | **مبني** لـAmazon وTrendyol (`app/Http/Controllers/Admin/SalesChannels`, `.../OperationalCosts`) |
| شاشة منتج موحَّدة تُظهر النشر لكل قناة والسعر والمخزون والمزوّد والموافقة والربحية في مكان واحد | **مبني**: بطاقة «قنوات البيع» في نموذج المنتج (قراءة فقط، ولا تتصل بأي قناة) وزرّها إلى شاشة الإدراج `admin/sales-channels/listings/{product}`: حفظ حقول القناة، التحقق، تسجيل تصريح المنتج المنظّم، الإرسال (POST مُقيّد + مربّع تأكيد)، وسؤال القناة عمّا تحمله. **الناقص**: معاينة الصفحة كما يراها المشتري، ورفع الصور |
| لوحة مراقبة (تأخر العمال، عمر المخزون، بيع زائد محتمل، طلبات بلا موقع، Odoo بحاجة مراجعة) | مبني جزئيًا: الهب يعرض تقادم المزامنة والحجوزات القديمة وما لا موقع له؛ لا عمال مُصطفّون لقياس تأخرهم، ولا Odoo | `AdminSalesChannelsHubTest.php`، `ChannelOrderReservations::unattributedUnits()` |
