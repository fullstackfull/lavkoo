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
| Listings Items: إنشاء/استبدال قائمة (`putListingsItem`) | **مبني (PR-C)** | `amazon.listings_put` بنطاق `product_writes` وقدرة `ProductSync`، ولإنشاء قائمة SKU لا تحمل Amazon له شيئًا فقط؛ وتغيير محتوى قائمة قائمة يمرّ بـ`amazon.listings_patch_content` لأن PUT كاملًا كان سيمحو كل خاصية لا يسمّيها المسودة. (كان هذا الصفّ يقول «غير مبني ولا مسجَّل» بعد أن بُني: صُحِّح) |
| Catalog Items (مطابقة ASIN) | **مبني (PR-C)** | يُعيد ASIN و`productType` الذي بدونه لا يمكن سؤال متطلبات القائمة. أربعة أجوبة مفصولة: واحد، أو عدّة (لشخص)، أو صفر مؤكَّد، أو **UNKNOWN** حين لا يُمكن السؤال — و`UNKNOWN` لا تُقرأ أبدًا كـ«لا يوجد»، وهو ما يمنع إنشاء قائمة ثانية لمنتج مُدرج. `ListingCatalogLookup`، `AmazonCatalogMapper` |
| Product Type Definitions | **مبني (PR-C)** | `getDefinitionsProductType` يُقرأ قبل إنشاء قائمة، ولا تُستعمل إلا قائمة `required` التي يحملها الجواب نفسه. القواعد الكاملة في مخطَّط JSON خلف رابط موقَّع **لا يُجلب** (ليس على قائمة العمليات)، فحيث لا يحمل الجواب قائمة فالإجابة UNKNOWN وتُستعمل قائمة المالك المعلنة، مسجَّلةً أنها قائمته لا قائمة Amazon |
| `JSON_LISTINGS_FEED` | **غير مبني بقرار، والسبب مكتوب** | العمليات الستّ مسجَّلة وحدودها معروفة (`createFeed` 0.0083/ث، و«5 feeds submission per account per 5 minutes» و«25,000 records per feed submission»)، والرفع موثَّق (PUT، و`Content-Type` يطابق ما مُرِّر لـ`createFeedDocument` — لكن **في تعليق داخل مثال كود لا في نصّ**). والغلاف موثَّق: `header` (`sellerId`، `version` «2.0»، `issueLocale`) و`messages` فيها `messageId`، `sku`، `operationType`، `productType`، `requirements`، `attributes`، `patches`. **وما ليس موثَّقًا هو ما يُوقِف البناء: أسماء حقول مستند النتيجة.** الوثيقة تقول إن التقرير «contains a list of issues that prevent the acceptance of the submissions and a list of issues that occur after the acceptance» **ولا تسمّي حقلًا واحدًا منه**، والمخطَّط يعيش في مستودع شيفرة هذه الجلسة محدودة بمستودعك وحده فلم يُقرأ. ومجموعة `operationType` الكاملة غير موثَّقة كذلك (ظهرت `DELETE`، `PARTIAL_UPDATE`، `UPDATE`، `PATCH` في مثال، والصفحة لا تقول إنها كل القيم).<br><br>**ولهذا قرار لا تأجيل:** ميزة الدفعة هي الإنتاجية وحدها، وخطرها أن إرسال ٢٥٠٠٠ تغيير سعر بلا قدرة على قراءة أيّها نفذ **أسوأ** من ٢٥٠٠٠ نداءً يجيب كلٌّ منها عن نفسه. والتحقّق الفردي بعد الدفعة يُلغي الميزة أصلًا. فالسعر والمخزون يمرّان واحدًا واحدًا عبر Listings Items كما هما الآن.<br><br>**الخطوة التالية محدَّدة:** قراءة `schemas/feeds/listings-feed-schema-v2.json` ومخطَّط تقرير معالجة الدفعة من مستودع `amzn/selling-partner-api-models`، أو تشغيل دفعة واحدة على حساب حقيقي وقراءة مستند نتيجتها حرفيًّا. | `AmazonOperations.php` → `amazon.feeds_*` |
| Notifications (SQS/EventBridge) | مسجَّل، غير مبني؛ **والاستقصاء يبقى المصدر لسببٍ موثَّق لا تحفّظًا** | **لا يوجد نوع إشعار `ORDER_CREATED` إطلاقًا**، والوثائق لا تقول إن الطلب الجديد يأتي كـ`ORDER_CHANGE` (الذي يصف «order status changes and buyer requested cancelations»). فالإشعارات **مُسرِّع لا سجل**، ولا تُستبدَل بها دورة الاستقصاء.<br><br>وما صار معروفًا ويُبنى عليه متى بُني: المسارات (`POST /notifications/v1/destinations`، `POST /notifications/v1/subscriptions/{notificationType}`، و**`DELETE /notifications/v2/subscriptions/...`** — نعم v2 وهي ليست غلطة مطبعية)، ورمز نطاق الرمز غير المقيَّد `sellingpartnerapi::notifications` بـ`grant_type=client_credentials`، وسياسة الطابور (المُخوَّل `437568002678`، والإجراءان `sqs:GetQueueAttributes` و`sqs:SendMessage`)، وأن الطوابير **standard لا FIFO**، والغلاف (`NotificationVersion`، `NotificationType`، `PayloadVersion`، `EventTime`، `Payload`، `NotificationMetadata{ApplicationId, SubscriptionId, PublishTime, NotificationId}`).<br><br>**وثلاث حقائق تحكم أي بناء:** «notifications might be delivered more than once» فالتكرار متوقَّع ويُكشف بـ`NotificationId`؛ و«best-effort ordering» فلا يُعتمد على ترتيب؛ **وصفحة Amazon تناقض نفسها** في `REPORT_PROCESSING_FINISHED` (العنوان «Payload schema: Version 1.0» والنصّ والمثالان `2020-09-04`) فلا يُؤكَّد إصدار حمولة بل يُقرأ ما يصل.<br><br>**وما ينقص ليس وثيقة بل بيانات اعتماد:** قراءة الطابور تحتاج مفاتيح AWS للبائع، وهي سرٌّ جديد يُخزَّن ويُحمى. | `AmazonOperations.php` → `amazon.notifications_*` |
| Reports (التسويات، كامل القوائم) | **مبني (PR-C)** | **مسارانِ لأن Amazon لا تسمح بغيرهما.** تقرير يُطلب (`GET_MERCHANT_LISTINGS_ALL_DATA`، `GET_AFN_INVENTORY_DATA`): `createReport` ثم استقصاء `getReport` ثم `getReportDocument` ثم التنزيل. وتقرير **التسوية لا يُطلب إطلاقًا** — صفحته تقول حرفيًّا: "Settlement reports cannot be requested or scheduled. They are automatically scheduled by Amazon. You can search for these reports using the getReports operation." فيُبحث عنه بـ`getReports`. والطلب **مُسيَّج**: جواب `createReport` الضائع يعني تقريرًا موجودًا برقم لم نعرفه، فيُبحث عنه ولا يُطلب ثانيًا. والتسوية تهبط **فاتورة غير معتمدة** من نوع `marketplace_statement` (ساق المصدر)، **لا قيود تكلفة**، لأن Finances يسجّل الرسوم سلفًا وكتابة الاثنين تحسب العمولة مرتين. `AmazonReports`، `AmazonReportRunner`، `AmazonFlatFile`، `AmazonSettlementReader` |
| فجوة معلنة: تنزيل تقرير بالتدفّق | غير مبني | عميل HTTP يحتفظ بالجسم كاملًا في الذاكرة، فـ`report_max_bytes` يحدّ **ما يُحلَّل** ويرفض باسمه فوقه، ولا يحدّ ما يُستقبَل. كتالوج يتجاوزه يحتاج التنزيل بالتدفّق |
| فجوة معلنة: ثلاثة مصائد في مستندات التقارير | مبنيّ التعامل معها، ومُسجَّل أنها من Amazon لا منّا | المبالغ بترقيم العملة المحلي (`95,00`)، وBOM في أول ٣ بايتات يحمله **أول اسم عمود**، وعناوين قد تأتي **مترجمة** عند إعادة تقرير مُخزَّن. عنوانٌ لا يُطابَق **رفضٌ مُسمّى** لا نتيجة فارغة |
| شحنات متعددة/تتبع جزئي | **مبني (PR-C)** | `FulfillmentRouter` يجمع البنود **حسب المزوّد**، فطلبٌ بعض بنوده عند طرود وبعضها عند مخزن يخرج في **صندوقين برقمَي تتبّع**. وكان المُبلِّغ يرسل **كل** بنود الطلب تحت الرقم الواحد المنسوخ على الطلب: إخبارٌ كاذب عن كل وحدة في الصندوق الآخر، ورقم تتبّع لن يتحرّك لنصف الطلب. الآن **تأكيد واحد لكل صندوق** بوحداته فقط، ويُسجَّل بمرجع الصندوق نفسه فلا يُرسل مرتين ولا يُحسب الثاني تكرارًا للأول. `ListsOrderParcels`، `ProviderParcels`، `SplitShipmentConfirmationTest` |

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

## 6. Odoo (PR-F) — غير مبني، وقيد المالك ملزم

انظر [odoo-accounting.md](odoo-accounting.md): وحدة `OperationalCosts` تبقى المحرك الوحيد للتكلفة وFIFO والعمولات والربحية، وOdoo سجلّ محاسبي فقط.

## 7. اللوحة والمراقبة (PR-G)

| المطلوب | الحالة |
|---|---|
| مركز القنوات، المنتجات والقنوات، المخزون حسب الموقع، الطلبات الموحدة، التوجيه، السجلات، التكاليف والربحية | **مبني** لـAmazon وTrendyol (`app/Http/Controllers/Admin/SalesChannels`, `.../OperationalCosts`) |
| شاشة منتج موحَّدة تُظهر النشر لكل قناة والسعر والمخزون والمزوّد والموافقة والربحية في مكان واحد | **مبني**: بطاقة «قنوات البيع» في نموذج المنتج (قراءة فقط، ولا تتصل بأي قناة) وزرّها إلى شاشة الإدراج `admin/sales-channels/listings/{product}`: حفظ حقول القناة، التحقق، تسجيل تصريح المنتج المنظّم، الإرسال (POST مُقيّد + مربّع تأكيد)، وسؤال القناة عمّا تحمله. **الناقص**: معاينة الصفحة كما يراها المشتري، ورفع الصور |
| لوحة مراقبة (تأخر العمال، عمر المخزون، بيع زائد محتمل، طلبات بلا موقع، Odoo بحاجة مراجعة) | مبني جزئيًا: الهب يعرض تقادم المزامنة والحجوزات القديمة وما لا موقع له؛ لا عمال مُصطفّون لقياس تأخرهم، ولا Odoo | `AdminSalesChannelsHubTest.php`، `ChannelOrderReservations::unattributedUnits()` |
