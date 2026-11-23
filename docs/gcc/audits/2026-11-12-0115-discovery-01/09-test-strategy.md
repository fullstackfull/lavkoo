# Lavkoo GCC — Discovery-01 — التقرير 09: استراتيجية الاختبار

> **المرحلة:** AUDIT (قراءة فقط) — **الجلسة:** `claude/exciting-shannon-4mh2ok` — **التاريخ:** 2026-11-12 (+03:00)
> **SHA الأساس:** `lavkoo@7f42777` · `lavkooapp@eedbbb8` · `lavkooappseller@8ea0092` · مرجع قديم `ksaapp98@79f50be`
> **يعتمد على:** [E15](./evidence/E15-tests-ci-quality-gates.md) (الأساسي)؛ وأقسام GCC والاختبارات في [E02](./evidence/E02-store-context-settings-currency-cache.md)، [E03](./evidence/E03-data-model-ownership-inventory.md)، [E04](./evidence/E04-customer-identity-wallet-loyalty.md)، [E05](./evidence/E05-staff-roles-permissions-audit.md)، [E08](./evidence/E08-catalog-discovery-pricing.md)، [E09](./evidence/E09-cart-checkout-order-pipeline.md)، [E10](./evidence/E10-payments-gateways.md)، [E11](./evidence/E11-inventory-fulfillment-shipping.md)، [E12](./evidence/E12-sales-channels-marketplaces.md)، [E17](./evidence/E17-customer-app-dynamic-theme.md)، [E18](./evidence/E18-customer-app-commerce-flows.md)، [E20](./evidence/E20-seller-app-operations-finance.md)، [E22](./evidence/E22-api-contract-customer.md)، [E23](./evidence/E23-api-contract-seller.md)؛ ومدخلات [التقرير 03](./03-data-ownership-isolation-matrix.md) و[التقرير 05](./05-gap-risk-register.md)
> **الحالة:** نهائي لهذه الجلسة — لا يتضمن أي تنفيذ.

## الخلاصة

- لم يُشغَّل أي اختبار ولا أداة من المستودعات في هذه المرحلة. كل الأعداد في هذا التقرير عدٌّ ساكن للنص المصدري ([E15 §1](./evidence/E15-tests-ci-quality-gates.md)).
- التغطية الآلية قوية في المسارات السعودية المتخصصة (تنفيذ الطلبات، القنوات، التكاليف، Odoo، MyFatoorah، العنوان الموحّد)، وغائبة عن التجارة الأساسية: السلة، checkout الكامل، دفتر المحفظة، الولاء، القسائم، دورة `refund_requests`، الثيم في الخلفية، الإشعارات، مصادقة العميل، الكتالوج والبحث، و seller-center ([E15 §2.3](./evidence/E15-tests-ci-quality-gates.md)).
- المخطط الأساسي غير قابل لإعادة الإنتاج من المستودع. الجداول الأساسية تُستنتج داخل الاختبارات بثلاث صيغ متعارضة للعمود المالي نفسه (`orders.order_amount`)، وهذا مصنّف P0 ([E15-F02](./evidence/E15-tests-ci-quality-gates.md)).
- لذلك يسبق أي تنفيذ GCC ثلاثة شروط: خط أساس لانحدار السعودية (القسم 2)، ومخطط بنيوي يُبنى منه قاعدة الاختبار (B1)، وشبكة عزل تمنع الوصول إلى أي خدمة خارجية أثناء الاختبار (القسم 4).
- المحاور الثلاثة عشر في القسم 3 لها أهداف ومعايير قبول مقترحة (PROPOSED). لا يُقاس أي منها اليوم، لأن معظم سياقات المتجر والدولة غير موجودة بعد في الكود.

## 1. الوضع الحالي للتغطية (بالأرقام)

### 1.1 جرد الاختبارات بحسب المجلد

الأرقام من [E15 §2.1](./evidence/E15-tests-ci-quality-gates.md) (عدّ ساكن، VERIFIED). الأعداد أدناه تخص مجلدات `lavkoo:tests/`.

| المجلد | ملفات | أصناف اختبار | دوال `test*` | ما تثبته على مستوى عالٍ |
|---|---:|---:|---:|---|
| `tests/Feature/SalesChannels` | 113 | 110 | 1,816 | موصلات Amazon وNoon وTrendyol، تقسيم المخزون على الحسابات، outbox، إرسال الطلبات إلى Torod وM5AZN، شاشات الإدارة وصلاحيات الموديول |
| `tests/Feature/Fulfillment` | 52 | 48 | 1,010 | موجّه التنفيذ وتقسيم الطلب على المزوّدين، COD، محوّلا Torod وM5AZN ضد HTTP مزيّف، webhooks، لقطة ملكية سطر الطلب، حرّاس الحالة |
| `tests/Feature/Address` | 57 | 51 | 730 | نظام العنوان الموحّد، وضع الطلب عبر API من زاوية العنوان، صفحات checkout للويب، لوحتا الإدارة والبائع |
| `tests/Feature/OperationalCosts` | 34 | 33 | 403 | دفتر التكاليف، العقود والعمولات بعملاتها، طبقات تكلفة المخزون، الربحية مع غياب سعر الصرف |
| `tests/Feature/Accounting` | 10 | 10 | 192 | ترحيل Odoo: بناء القيود، الـ outbox، التراجع، التفرّد |
| `tests/Feature/MyFatoorah` | 5 | 4 | 135 | بدء الدفع، التحقق، التسوية وwebhook، الاسترداد الكلي والجزئي، ظهور البوابة في checkout |
| `tests/Feature/Concurrency` | 9 | 9 | 45 | اختبارات تزامن على MySQL/MariaDB (انظر 1.4) |
| `tests/Feature/Security` | 2 | 2 | 10 | مسح جدول المسارات الإدارية ووجود الحارس |
| `tests/Feature/DeepLink` | 1 | 1 | 11 | `DeepLinkResolver` والمسارات المنشورة للتطبيق |
| `tests/Feature` (جذر) | 3 | 3 | 4 | `CiDatabaseTest`، `StrictModeTest`، `ExampleTest` |
| `tests/Unit/Deploy` | 3 | 3 | 108 | سكربتات النشر والنسخ الاحتياطي و leak guard |
| `tests/Unit/Fulfillment` | 14 | 14 | 127 | آلة الحالات، توزيع COD، تنقيح PII والأسرار، رمز العملة |
| `tests/Unit/OperationalCosts` | 13 | 13 | 107 | `Money`، قواعد الكسور، حاسبة القواعد |
| `tests/Unit/Accounting` | 4 | 4 | 59 | `OdooMoney`، XML-RPC، preflight |
| `tests/Unit/SalesChannels` | 9 | 9 | 47 | `StockAllocator`، فحوص ساكنة لحدود الخروج الخارجي |
| `tests/Unit/MyFatoorah` | 2 | 2 | 16 | مقارنة المبالغ وتوقيع الـ webhook |
| `tests/Unit/Ci` + `tests/Unit` (جذر) | 2 | 2 | 3 | حارس تقسيم مصفوفة CI؛ مثال Laravel |
| **المجموع** | **333 + 2** | **318** | **4,823** | 4,826 بالمسح الكامل قبل استثناء 3 دوال مساعدة بالاسم نفسه في `tests/Support` |

**المطابقة:** مجموع صفوف الجدول 333 ملف. يُضاف إليه ملفان في جذر `tests/` غير مذكورين في صفوف E15 هما `lavkoo:tests/TestCase.php` و`lavkoo:tests/CreatesApplication.php`، فيصبح 335 ملفاً غير ملفات الدعم. وملفات الدعم في `tests/Support` 62 ملفاً، فيكون المجموع 397 ملفاً كما في E15. عُدّ ذلك بـ `find` و`wc` في قراءة فقط (VERIFIED).

**ملاحظات على الأرقام:**

- عدد الاختبارات وقت التشغيل UNKNOWN، لأن شيئاً لم يُشغَّل. تعليق CI يذكر «نحو 4,700» اختبار ([`lavkoo:.github/workflows/ci.yml:47`](./evidence/E15-tests-ci-quality-gates.md)) وتعليقاً آخر يذكر 1,905 اختباراً في `tests/Feature/SalesChannels` ([`lavkoo:.github/workflows/ci.yml:235`](./evidence/E15-tests-ci-quality-gates.md)) مقابل 1,816 دالة بالعدّ الساكن. الفرق يعود إلى توسّع مزوّدات البيانات (168 موضعاً من `DataProvider`) وقت التشغيل.
- الحزم الأحدث فقط مغطاة بكثافة. كل ما هو مغطى أُضيف بعد 2026-11-05. الالتزام `lavkoo@34dce658` (2026-11-04) احتوى أربعة ملفات فقط تحت `tests/`، مع أنه احتوى 162 ملف خدمة تحت `app/Services/{Theme,Monitoring,SellerCenter,DeveloperPortal}` ([E15 §2.1](./evidence/E15-tests-ci-quality-gates.md)، VERIFIED).

### 1.2 التغطية بحسب مساحات الخدمات

عدد ملفات الاختبار التي تذكر `App\Services\<Dir>` ([E15 §2.2](./evidence/E15-tests-ci-quality-gates.md)، بحث نصي، VERIFIED):

| مساحة الخدمات | ملفات الخدمة | ملفات اختبار تشير إليها |
|---|---:|---:|
| SalesChannels | 201 | 134 |
| Fulfillment | 158 | 130 |
| OperationalCosts | 83 | 68 |
| Address | 27 | 52 |
| Accounting | 23 | 18 |
| MyFatoorah | 15 | 9 |
| Marketplace | 42 | 3 |
| DeepLink | 3 | 1 |
| Monitoring | 94 | **0** |
| Theme | 28 | **0** |
| SellerCenter | 20 | **0** |
| DeveloperPortal | 20 | **0** |
| SellerIntelligence | 23 | **0** |
| Analytics | 19 | **0** |
| SellerAutomation | 15 | **0** |
| Commerce | 11 | **0** |
| Seo | 8 | **0** |

على مستوى أعلى: من 86 ملف خدمة في جذر `app/Services` تُشير الاختبارات إلى خمسة فقط، ومنها `CartService` و`CouponService` و`CustomerWalletService` و`RefundTransactionService` و`PaymentRoutingService` و`NotificationService` و`VendorWalletService` غير مشار إليها (81 ملفاً بلا أي إشارة). من 430 متحكماً يظهر اسم 29 منها في الاختبارات، ومن 235 نموذجاً يظهر 70، ومن ملفات `Modules/` البالغة 229 لا يظهر أي ملف. المطابقة بالأسماء لا تلتقط الاختبارات التي تصل إلى المتحكم عبر URL؛ لذلك أُضيف جرد المسارات في 1.3 (E15 §2.2–2.3).

### 1.3 ما ليس مغطى، مع أنماط البحث المسجَّلة

كل الأنماط بُحث عنها بـ `grep -rliE` في ملفات `*.php` تحت `lavkoo:tests/` ([E15 §2.3](./evidence/E15-tests-ci-quality-gates.md)). الحالة VERIFIED لكل صف، ما لم يُذكر خلاف ذلك.

| المجال | نمط البحث | النتيجة |
|---|---|---|
| السلة | `CartManager\|CartService\|CartController\|api/v1/cart\|'carts'\|cart/add\|add-to-cart` | 8 ملفات / 12 موضعاً، كلها إنشاء جدول أو تمهيد. لا اختبار لإضافة عنصر أو تعديله أو حذفه |
| checkout | `checkout` | 39 ملفاً / 231 موضعاً، مغطّى من ثلاث زوايا: العنوان (15 ملفاً)، حرّاس COD (9)، MyFatoorah (3). لا اختبار لمسار checkout كامل |
| المحفظة | `wallet` ثم `WalletTransaction\|wallet_transactions\|create_wallet_transaction\|CustomerWalletService` | 10 ملفات للنمط الأول؛ صفر للنمط الثاني. لا اختبار لدفتر المحفظة |
| الولاء | `loyalty` ثم `loyalty_point_transactions\|LoyaltyPointTransaction\|loyalty_point` | موضع واحد (نص رسالة)؛ صفر للنمط الثاني |
| القسائم | `coupon` ثم `CouponService\|CouponController\|coupon/apply\|Coupon::` | 5 ملفات؛ قسيمة ثابتة واحدة ضمن اختبار مبالغ الطلب؛ لا اختبار لقواعد القسيمة |
| الاسترداد | `refund` ثم `RefundRequest\|refund_requests\|RefundTransaction\|RefundStatus` | 33 ملفاً للنمط الأول؛ ثلاثة مواضع للنمط الثاني منها إنشاء جدول وتمهيد، والرابع اسم دالة يختبر استرداد MyFatoorah المعلّق. دورة `refund_requests` الأساسية غير مغطاة |
| الثيم | `ThemeManager\|ThemeBuilderService\|ThemeDelivery\|StorefrontThemeRenderer\|ViewerContext\|App.Services.Theme\|api/v1/theme` | صفر. لا اختبار backend واحد لنظام الثيم |
| الأدوار الإدارية | `AdminRole\|admin_roles\|module_access\|ModulePermission\|module:` | 18 ملفاً؛ الصلاحية مثبتة للموديولات الجديدة (`sales_channels`، `operational_costs`، fulfillment، address) ولمجموعة من 12 مساراً فقط. لا مصفوفة تغطي كل مسارات الإدارة |
| Seller API v3 | `api/v3/seller\|RestAPI.v3.seller`، و`seller-center\|SellerCenter\|seller_center` | ملفان يستدعيان دالتين مباشرة؛ صفر لـ `seller-center` مقابل 236 تعريف مساراً في `routes/rest_api/v3/seller.php` |
| Customer API v1 | جرد المسارات الحرفية | نحو ثماني مجموعات مسارات مقابل 180 تعريف مساراً في `routes/rest_api/v1/api.php` |
| مصادقة العميل | `auth/login\|auth/register\|verify-otp\|social-login\|PassportAuthController` | 7 ملفات، كلها عنوان المزوّد المزيّف. صفر لتسجيل العميل أو OTP |
| Paymob / Tabby / Tamara | `paymob`؛ `tabby\|tamara` | صفر / صفر |
| الإشعارات | `topic\|subscribeToTopic`؛ `Notification::fake`؛ `Mail::fake` | صفر في كل الأنماط. لا اختبار لإرسال push أو SMS أو WhatsApp أو email للعملاء |
| البحث والفهرسة | `ProductSearch\|product_search_index\|AdvancedSearch` | صفر |
| الكتالوج | 16 اسماً من خدمات الكتالوج (`ProductSearchService`، `CouponService`، `SeoMetaResolver`… ) | صفر ([E08-F12](./evidence/E08-catalog-discovery-pricing.md)، P1) |
| Modules | `Modules.Blog\|Modules.AI\|AIProvider` | صفر. `Modules/TaxModule/tests` يحتوي ملفي `.gitkeep` فقط |
| وضع الصيانة | `maintenance_mode\|MaintenanceModeMiddleware` | موضع واحد يعطّل الوسيط في اختبارات اللوحة (`lavkoo:tests/Feature/Address/PanelAddressTestCase.php:52-56`) |
| عملات الخليج | `'KWD'`، `'AED'`، `'QAR'`، `'BHD'`، `'OMR'` | 2 / 3 / 0 / 0 / 0 ملفات. `KWD` يظهر كعملة مرفوضة فقط؛ `SAR` يظهر في 132 ملفاً |
| تطابق الكيانات والدول | `storefront_id\|legal_entity\|country_id\|market_id` | صفر مواضع في `tests/` (كلمة `market` تظهر بمعنى marketplace فقط) |

**ملاحظة على اختبارات اللوحات:** اختبارات اللوحات تعطّل عمداً وسطاء التفويض (`AdminMiddleware`، `SellerMiddleware`، `SellerStaffAccessMiddleware`، `ModulePermissionMiddleware`) في `lavkoo:tests/Feature/Address/PanelAddressTestCase.php:52-56`، فهي تثبت منطق الصفحة وليس صلاحية الوصول ([E15 §3](./evidence/E15-tests-ci-quality-gates.md)). هذا لا يُعد اختباراً للتفويض.

### 1.4 البنية التحتية للاختبار

- **قاعدة الاختبار الافتراضية SQLite في الذاكرة.** `lavkoo:phpunit.xml:26-27` يثبّت `DB_CONNECTION=sqlite` و`:memory:` بعناصر `<server>` تتغلّب على متغيّرات البيئة. ملف `lavkoo:phpunit.mysql.xml:5-8` يترك القاعدة للبيئة لتأخذها مهمة CI. (VERIFIED)
- **لا يوجد dump للمخطط في المستودع.** `lavkoo:.gitignore:53` يتجاهل `*.sql`، و`lavkoo:CLAUDE.md:22` يوجّه إلى استيراد `installation/backup/database.sql` قبل الهجرة، لكن الملف غير موجود في المستودع. الهجرات: 336 ملفاً، منها 92 فيها `Schema::create` و244 بلا أي `Schema::create` ([E15 §2.4](./evidence/E15-tests-ci-quality-gates.md)، VERIFIED).
- **المخطط في الاختبارات مستنتج لا منقول.** كل بانٍ يعيد تعريف `orders` و`users` و`products` يدوياً، ويصرّح بأن ذلك لا يثبت السلوك على المخطط الحي (`lavkoo:tests/Support/BuildsFulfillmentSchema.php:10-20`). الجداول الجديدة تُبنى بالهجرات الحقيقية (`lavkoo:tests/Support/BuildsSalesChannelSchema.php:40-67`).
- **التعارض في دقة العمود المالي نفسه.** `orders.order_amount` معرَّف بـ `decimal(16,2)` في `lavkoo:tests/Support/BuildsApiAddressSchema.php:98`، وبـ `decimal(40,20)` في `lavkoo:tests/Support/BuildsFulfillmentSchema.php:169`، وبـ `decimal(24,2)` في `lavkoo:tests/Support/BuildsMyFatoorahSchema.php:94`. لا يوجد مصدر حقيقة واحد لدقة الأعمدة المالية داخل المستودع ([E15-F02](./evidence/E15-tests-ci-quality-gates.md)، P0، VERIFIED).
- **جداول خارج الهجرات.** الكود نفسه يسرد جداول لا تنشئها أي هجرة، منها `vendor_ledger_entries` و`vendor_settlements` و`payment_routing_rules` و`warehouses` و`stock_movements` و`audit_logs` و`api_snapshots` ([`lavkoo:app/Services/Fulfillment/Readiness/Compatibility/RestoreSection.php:22-29`](./evidence/E15-tests-ci-quality-gates.md)). جداول الثيم `themes` و`theme_versions` و`theme_sections` و`experience_pages` لا يذكرها أي ملف تحت `database/migrations` أو `Modules` ([E15 §2.4](./evidence/E15-tests-ci-quality-gates.md)، VERIFIED).
- **حاجز الحماية:** الباني يرفض إسقاط الجداول ما لم تكن البيئة `testing` واسم القاعدة يحتوي `test` (`lavkoo:tests/Support/BuildsFulfillmentSchema.php:224-236`). وهذا نموذج يُعاد استخدامه لأي بيئة GCC.
- **إعدادات الاختبار للمخازن والطوابير والبريد.** `CACHE_DRIVER=array` و`QUEUE_CONNECTION=sync` و`MAIL_DRIVER=array` في `lavkoo:phpunit.xml:25-29`. هذا يعني أن مفاتيح الكاش لا تُختبر بعزلها عن السوق؛ وأن الطوابير لا تُختبر بتشغيل فعلي ([E15 §4](./evidence/E15-tests-ci-quality-gates.md)، VERIFIED).
- **التزامن.** 45 اختباراً تشغّل عدة عمليات PHP على MySQL/MariaDB وتتخطّى نفسها على SQLite (`lavkoo:tests/Support/Concurrency/ConcurrentRunner.php:19-24`). التوزيع: مخزون الـ pool والـ outbox والحجوزات 14، MyFatoorah 6، Noon 6، Odoo 5، تقسيم الطرود 4، قرارات المرتجعات 3، مراجعات التنبيه 4، استقبال الطلب 1، وفئات أخرى بمجموع 2. مخزون checkout يُختبر بعملية محاكاة `stock.checkout` (`lavkoo:tests/Support/Concurrency/StockOperations.php:247-262`) لا بكود checkout الإنتاجي. كتابات المحفظة والولاء وقيود الدفاتر الأخرى غير مغطاة بهذه المجموعة ([E15-F07](./evidence/E15-tests-ci-quality-gates.md)، P1).
- **عزل الاستدعاءات الخارجية الموجود.** `Http::preventStrayRequests()` في 89 ملف اختبار، و`Http::fake` في 103. الأصناف الأساسية تفرضه في `setUp`، مثل `lavkoo:tests/Feature/Fulfillment/FulfillmentTestCase.php:49`. المزوّدان Torod وM5AZN يُستبدلان بمحوّلين مزيّفين (`lavkoo:tests/Feature/Fulfillment/FulfillmentTestCase.php:66-76`). لا `Mail::fake` ولا `Notification::fake` ولا `Bus::fake` في أي ملف، و`Queue::fake` في 6 ملفات و`Event::fake` في ملف واحد ([E15 §2.6](./evidence/E15-tests-ci-quality-gates.md)، VERIFIED).
- **لا قياس تغطية ولا تحليل ساكن.** `coverage: none` في `lavkoo:.github/workflows/ci.yml:37`. لا `phpstan` ولا `psalm` ولا إعداد `pint` في CI ([E15 §2.7](./evidence/E15-tests-ci-quality-gates.md)).
- **لا E2E متتبَّع.** سكربتات Playwright معرّفة في `lavkoo:package.json:11-17` لكن `/tests-e2e/` و`/playwright.config.ts` متجاهَلان في git (`lavkoo:.gitignore:38-39`) وغير موجودين في الشجرة ([E15-F14](./evidence/E15-tests-ci-quality-gates.md)، P2).

### 1.5 تطبيقا Flutter وعقود الـ API

| المكوّن | الاختبارات الموجودة | CI | الملاحظة |
|---|---|---|---|
| تطبيق العميل `lavkooapp` | 11 ملفاً؛ 104 `test(` و12 `testWidgets(`؛ معظمها الثيم الديناميكي (75 حالة للمحلِّل والتحكم والعرض) وMyFatoorah ومحلّل الروابط ([E15 §2.12](./evidence/E15-tests-ci-quality-gates.md)) | **لا يوجد** (لا مجلد `.github` ولا workflow؛ VERIFIED) | لا `integration_test`. لا اختبار بوابة الإصدار رغم وجود الثابت `lavkooapp:lib/utill/app_constants.dart:29` |
| تطبيق البائع `lavkooappseller` | 19 ملفاً؛ 142 `test(`؛ 14 ملف عقد تضم 122 اختباراً ([E23 §2.9](./evidence/E23-api-contract-seller.md)) واختبار بوابة الإصدار `lavkooappseller:test/app_version_gate_test.dart:6-17` | موجود: `lavkooappseller:.github/workflows/ci.yml:3-7`، `lavkooappseller:.github/workflows/ci.yml:35-42`؛ هل يعمل فعلاً على GitHub: UNKNOWN | عقود مضمّنة يدوياً من backend «يعمل»، وبعضها بعملة `SYP` (`lavkooappseller:test/control_tower_contract_test.dart:50`) |
| ksaapp98 (مرجع قديم) | 17 ملفاً؛ منها ستة غير موجودة في `lavkooapp` (وضع الطلب، رحلات API، مصدر نتيجة الدفع، نظافة سجلات الإصدار، سلك تعثّر الإصدار، فحص الأسرار) | `ksaapp98:.github/workflows/flutter.yml:32-36` بخمس مهام | نسب هذه الاختبارات مرهون بالتقرير 06 ([E15-F05](./evidence/E15-tests-ci-quality-gates.md)، P1) |
| عقد الـ backend | لا لقطة متتبَّعة في git؛ جداول اللقطات لا تنشئها أي هجرة؛ `api:snapshot` مجدول أسبوعياً ويقارن جانب الطلب فقط ([E15 §2.10](./evidence/E15-tests-ci-quality-gates.md)) | لا تستدعي CI `api:snapshot` | وجود اللقطات في الإنتاج UNKNOWN ([E22-F14](./evidence/E22-api-contract-customer.md)، P1) |

## 2. خط الأساس لانحدار السعودية (شرط مسبق قبل أي تنفيذ)

### 2.1 المبدأ

السعودية هي السوق الافتراضي (GCC-19 في `lavkoo:docs/gcc/DECISIONS.md`، VERIFIED)، ومعايير **Preserve** تفرض أن تبقى الأنظمة القائمة كما هي. الاختبارات الحالية، وقد عُدّت في القسم 1، تثبت أجزاء من ذلك السلوك. الأجزاء غير المثبتة تحتاج **اختبارات توصيفية (characterization tests)** تُكتب على السلوك الحالي قبل أي تعديل، ثم تُعامَل توقعاتها كعقد لا يُعدَّل إلا بقرار مالك. هذا هو الخط الأساسي المطلوب.

### 2.2 الحزم المطلوبة B1–B13

الجدول مأخوذ من [E15 §6.1](./evidence/E15-tests-ci-quality-gates.md) مع تحديث حالة كل حزمة بما يثبته القسم 1.

| الحزمة | ما تثبّته (السلوك السعودي الحالي) | التغطية الحالية | الحالة والأولوية المقترحة |
|---|---|---|---|
| B1 مخطط الإنتاج البنيوي | بناء قاعدة الاختبار من لقطة structure-only حقيقية (بلا بيانات)، وتشغيل كل الهجرات فوقها على MySQL 8 وMariaDB 11.4.2 | لا توجد؛ المخطط مستنتج ([E15-F02](./evidence/E15-tests-ci-quality-gates.md)، P0) | مفقودة |
| B2 checkout ووضع الطلب | COD، المحفظة، الدفع اليدوي، الدفع الرقمي عبر API v1 والويب؛ المبالغ (ضريبة مشمولة وغير مشمولة، شحن، قسيمة، خصم)؛ تقسيم البائعين و`order_group_id`؛ إنقاص المخزون؛ لقطات `order_details` | جزئية: مبالغ وضع الطلب عبر API في `lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:101-146` (22 ملفاً تُسند `order_amount`)؛ حارس COD لكل مجموعة في `lavkoo:tests/Feature/Fulfillment/StatusAndCheckoutGuardsTest.php:144`؛ تقسيم البائعين غير مختبر | جزئية (P0) |
| B3 المحفظة والولاء | كل أنواع حركات `wallet_transactions` و`loyalty_point_transactions`؛ تطابق الرصيد مع الدفتر؛ شحن المحفظة؛ الاسترداد إلى المحفظة؛ تحويل النقاط؛ اختبارات التزامن | صفر ([E04 §2.10](./evidence/E04-customer-identity-wallet-loyalty.md)، VERIFIED) | مفقودة (P0) |
| B4 القسائم | النوع، المالك، الحد الأدنى، السقف، حد الاستخدام مع التزامن، الصلاحية الزمنية، حامل الخصم | صفر لقواعد القسيمة ([E15 §2.3](./evidence/E15-tests-ci-quality-gates.md)) | مفقودة (P1) |
| B5 الاسترداد الأساسي | `refund_requests` ← القرار ← `refund_transactions`؛ الاسترداد الجزئي؛ أثره على المحفظة والمخزون والعمولة | صفر للدورة الأساسية؛ استرداد MyFatoorah مغطى في `lavkoo:tests/Feature/MyFatoorah/RefundAndAdminTest.php:104` | جزئية (P1) |
| B6 المال والتقريب | golden لدوال التحويل والتنسيق الحالية بـ SAR وخانتين، على المتجر أحادي العملة ومتعدد العملات | جزئية: `MoneyTest` (MyFatoorah)، `PilotServerCompatibilityTest`، `OdooMoneyTest` بثلاث خانات في موضع واحد (`lavkoo:tests/Unit/Accounting/OdooMoneyTest.php:79-83`) | جزئية (P1) |
| B7 عقود API | golden JSON لاستجابات النقاط التي يستهلكها التطبيقان (config، الثيم، السلة، الطلب، الدفع، دخول البائع، الطلبات، seller-center)؛ لقطة سطح متتبَّعة | عقد البائع موجود في التطبيق فقط بحمولات ملتقطة يدوياً؛ صفر golden في الخلفية ([E23 §2.9](./evidence/E23-api-contract-seller.md)) | مفقودة (P1) |
| B8 مصفوفة التفويض | لكل مسار إداري: حارس `admin` ووحدة صلاحية؛ لكل مسار بائع: حارس البائع وعزل `seller_id` | جزئية: المسح يثبت حارس `admin` لكل المسارات؛ وحدة الصلاحية لموديولين و12 مساراً ([E15-F08](./evidence/E15-tests-ci-quality-gates.md)، P1) | جزئية (P1) |
| B9 الثيم (backend) | النشر، المراجعة، checksum، مفاتيح الكاش، السقوط الاحتياطي، توافق إصدار التطبيق | صفر ([E15 §2.3](./evidence/E15-tests-ci-quality-gates.md)) | مفقودة (P0) |
| B10 الإشعارات | ما يُرسَل ولمن عند أحداث الطلب، مع بدائل كاملة للمزوّدين | صفر | مفقودة (P1) |
| B11 تطبيق العميل | CI واختبارات وضع الطلب ورحلات API وبوابة الإصدار وفحص الأسرار | لا CI؛ الاختبارات الستة موجودة في `ksaapp98` فقط | مفقودة، معتمدة على نسب PR (P1) |
| B12 تأكيد CI | تشغيل أخضر موثّق على HEAD في المستودعات الثلاثة، وفحوص مطلوبة للدمج | UNKNOWN: آخر تشغيل موثّق كان على المستودع القديم عند `lavkoo@3f7920c` ([E15-F04](./evidence/E15-tests-ci-quality-gates.md)، P1) | غير محسومة |
| B13 وسم «Saudi baseline» | مجموعة B2–B10 مع الحزم الحالية كمهمة CI مسماة، لا يُسمح بتعديل توقعاتها ضمن PR فيه عمل GCC | غير موجودة | مفقودة (P0) |

> **ملاحظة:** الحزم B14 إلى B17 والمحور A14 إضافات مقترحة في [التقرير 10] (القسم 5 وحدود التقرير)، ولم تُعرَّف في هذا التقرير. تُضاف إليه بعد اعتماد المالك.

### 2.3 معايير الدخول والخروج

**دخول المرحلة:** لا يبدأ أي تغيير على المخطط أو دوال المال أو الدفاتر أو الثيم قبل اكتمال B1 وB2 وB3 وB6 وB9 (مقترح يُراجَع في [التقرير 10](./10-implementation-roadmap.md)). توقعات الاختبارات الحالية الـ 4,823 دالة لا تُعدَّل ضمن أي PR يحمل عمل GCC إلا بموافقة المالك ([E15 §5](./evidence/E15-tests-ci-quality-gates.md)، Preserve).

**خروج المرحلة (PROPOSED):**

1. الخط الأساسي أخضر على MySQL 8 وMariaDB 11.4.2 بالوضعين الصارم وغير الصارم، ومُشغَّل على HEAD المعتمد، مع نتيجة موثّقة. الحالة الحالية UNKNOWN.
2. لقطات golden للمبالغ والطلبات والمحافظ المرجعية السعودية موجودة، ومرتبطة بسجلات إنتاجية مجهولة الهوية بعد موافقة المالك.
3. كل تغيير لتوقع سعودي موجود يحمل قراراً موثّقاً من المالك.

**ملاحظة مهمة:** بعض السلوك السعودي الحالي معيب، وتثبيته كما هو يُنشئ عقداً خاطئاً. مثال: الواجهة تعرض المبالغ بصفر خانات عشرية دائماً ([E18-F01](./evidence/E18-customer-app-commerce-flows.md)، P0، VERIFIED) ومنها الريال السعودي. لذلك يجب أن تحمل لقطة العرض عنوان «عيب معروف» وأن يكون تغييرها قراراً للمالك ([التقرير 11](./11-open-decisions-for-owner.md)).

## 3. المحاور الاختبارية

لكل محور: الفجوة الحالية بالدليل، ثم الهدف والسيناريوهات والبيانات ونوع الاختبار ومعيار القبول. معايير القبول **PROPOSED** ولا تعني أن شيئاً منها قائم اليوم.

### A1. عزل الدول الست (six-country isolation)

**الفجوة الحالية:**

- لا اختبار يحمل مفهوم الدولة أو واجهة المتجر أو الكيان القانوني (البحث في القسم 1.3، صفر مواضع، VERIFIED).
- لا حامل لسياق المتجر في المسارات أو الترويسات أو الجلسة أو مفاتيح الكاش: [E02-F02](./evidence/E02-store-context-settings-currency-cache.md) (P0)، [E02-F03](./evidence/E02-store-context-settings-currency-cache.md) (P0)، [E22-F01](./evidence/E22-api-contract-customer.md) (P0).
- لا نطاق مركزي للعزل في الاستعلامات؛ العزل كله بشروط `where` موضعية ([E03-F13](./evidence/E03-data-model-ownership-inventory.md)، P1). قاعدة ظهور الكتالوج ليست نقطة مرور إلزامية ([E08-F02](./evidence/E08-catalog-discovery-pricing.md)، P0).
- التجميعات المخزنية تجمع إدراجات كل الحسابات بلا تمييز دولة ([E12-F03](./evidence/E12-sales-channels-marketplaces.md)، P1).

| البند | التفاصيل |
|---|---|
| الهدف | أي قراءة أو كتابة أو إشعار أو وظيفة أو مفتاح كاش تحت سوق A لا تكشف ولا تغيّر بيانات سوق B |
| السيناريوهات | (1) مصفوفة الأسواق: كل زوج من الأسواق الستة لكل مجال (كتالوج، أسعار، مخزون، سلة، طلبات، قسائم، إعدادات، ثيم، إشعارات). (2) تشابه SKU وslug بين سوقين دون تصادم. (3) عميل واحد له طلبات في سوقين، ويظهر كل طلب في سوقه فقط. (4) مفتاح الكاش نفسه في سوقين يعيد محتوى كل سوق. (5) تفريغ كاش سوق لا يفرّغ سوقاً آخر. (6) الوظائف والأحداث تحمل معرّف السوق وتنفَّذ في سياقه. (7) موظف بنطاق سوق واحد لا يرى بيانات السوق الآخر. (8) طلب بلا سوق صريح يُحلّ إلى السعودية (GCC-19 في `lavkoo:docs/gcc/DECISIONS.md`). (9) فاحص ساكن يمنع استعلاماً على جدول مملوك للسوق بلا نطاق (على غرار `lavkoo:tests/Support/PlatformWriteScanner.php:5-12`) |
| البيانات اللازمة | سوقان مفعّلان على الأقل (SA وAE) بعملتين وكيانين قانونيين وكتالوجين مستقلين؛ SKU مشترك؛ عميل بطلبات في سوقين؛ موظف بنطاق واحد؛ جداول مبنية من B1. بيانات تركيبية بلا معلومات شخصية |
| نوع الاختبار | Feature (المسارات وطبقات الكتابة)؛ Contract (الاستجابات)؛ Unit (الفاحص الساكن)؛ E2E دخانياً بعد التنفيذ |
| معيار القبول (PROPOSED) | صفر صفوف أو استجابات أو مفاتيح كاش أو رسائل أو وظائف من سوق A في سياق سوق B عبر المصفوفة كاملة. الفاحص الساكن يُنهي بنجاح ولا يسمح باستثناء بلا تعليل. مخرجات السعودية تطابق لقطة B2–B10 |

### A2. checkout بكيان قانوني واحد

**الفجوة الحالية:**

- الدفع يجمع كل المجموعات المحددة ويحصّلها بدفعة واحدة، ولا يوجد حارس كيان واحد ([E09-F05](./evidence/E09-cart-checkout-order-pipeline.md)، P1). طلب الدفع الواحد يجمع كل البائعين ومجموعات السلة ([E10-F07](./evidence/E10-payments-gateways.md)، P1).
- واجهة السلة تسمح بـ checkout واحد لعدة بائعين دون فحص كيان ([E18-F08](./evidence/E18-customer-app-commerce-flows.md)، P1).
- الطلبات والسلال بلا بُعد متجر أو كيان ([E09-F02](./evidence/E09-cart-checkout-order-pipeline.md)، P0).
- الاختبار الوحيد لحارس الحالة يحكم على كل `cart_group` منفرداً لأن كل مجموعة تصير طلباً مستقلاً (`lavkoo:tests/Feature/Fulfillment/StatusAndCheckoutGuardsTest.php:144`). تقسيم الطلب متعدد البائعين (`order_group_id`) لا يملك اختباراً ([E15 §3](./evidence/E15-tests-ci-quality-gates.md)).

| البند | التفاصيل |
|---|---|
| الهدف | طلب واحد = كيان قانوني واحد = عملة واحدة. سلة تحتوي متجرين من كيانين لا تصل إلى الدفع |
| السيناريوهات | (1) سلة بعنصر سعودي وآخر إماراتي تُرفض قبل الدفع مع سبب مفهوم. (2) طلب متعدد البائعين السعودي القديم يبقى مقروءاً بالمبالغ والتقسيم نفسهما (golden). (3) طلب الدفع الواحد يرتبط بكيان واحد. (4) استجابة الدفع لا تُقبل إن لم تطابق كيان الطلب. (5) حارس COD يبقى لكل مجموعة. (6) إنقاص المخزون لكل مجموعة يحدث مرة واحدة |
| البيانات اللازمة | بائعان سعوديان (أحدهما قديم متعدد البائعين)، بائع إماراتي بكيان مختلف، سلال بقسائم وضريبة مشمولة وغير مشمولة، طرق دفع COD ومحفظة ومدفوع رقمياً |
| نوع الاختبار | Feature مع MySQL (B2)؛ Contract (`POST /api/v1/customer/order/place*`)؛ Concurrency لإنقاص المخزون عند checkout متوازٍ |
| معيار القبول (PROPOSED) | المبالغ والطلبات والمجموعات السعودية متطابقة بنسبة 100% مع لقطة B2. أي سلة مختلطة الكيان ترفض قبل إنشاء `payment_requests`. كل `payment_request` يرتبط بكيان واحد |

### A3. المحفظة والولاء (فصل الأرصدة والدفاتر)

**الفجوة الحالية:**

- رصيد المحفظة ونقاط الولاء عددان على `users`، والدفتران بمفتاح `user_id` فقط، بلا متجر أو كيان أو عملة ([E04-F01](./evidence/E04-customer-identity-wallet-loyalty.md)، P0؛ [E03-F03](./evidence/E03-data-model-ownership-inventory.md)، P0).
- وحدة المبالغ في الدفتر تتبع `currency_model` وسعر العملة الافتراضية، بلا عمود عملة أو سعر ([E04-F02](./evidence/E04-customer-identity-wallet-loyalty.md)، P0، VERIFIED في الكود، UNKNOWN في البيانات).
- كتّاب المحفظة والولاء متعددون؛ التفاصيل في الملحق المقيّد ([E04-F03](./evidence/E04-customer-identity-wallet-loyalty.md)، P0)، ولا توجد تغطية تزامن لهذه الكتابات ([E15-F07](./evidence/E15-tests-ci-quality-gates.md)، P1).
- دفتر المحفظة غير مربوط بطلب أو دفعة أو استرداد؛ رقم الطلب يظهر في موضعين فقط من 20 موضعاً ([E04-F06](./evidence/E04-customer-identity-wallet-loyalty.md)، P1).
- قواعد كسب النقاط تختلف بحسب القناة في خمسة مواضع ([E04-F09](./evidence/E04-customer-identity-wallet-loyalty.md)، P1).
- اختبارات الطلب تتحقق من أن الرصيد لم يتغير عند رفض الطلب، ولا توجد اختبارات لحركات الرصيد نفسها ([E04 §2.10](./evidence/E04-customer-identity-wallet-loyalty.md)، VERIFIED).

| البند | التفاصيل |
|---|---|
| الهدف | رصيد لكل (عميل، كيان، عملة). لا حركة بين الكيانات. الرصيد التاريخي السعودي يطابق الدفتر قبل الهجرة وبعدها |
| السيناريوهات | (1) كل نوع حركة (شحن، خصم، استرداد إلى المحفظة، مكافأة، تعديل مشرف) يحدّث الرصيد والدفتر معاً. (2) مجموع الدفتر = الرصيد قبل الهجرة وبعدها للعملاء السعوديين. (3) خصم متوازٍ لا يجعل الرصيد سالباً. (4) إشعار دفع مكرر لا يشحن المحفظة مرتين. (5) تحويل النقاط يحترم الحد والسعر لكل قناة. (6) شحن المحفظة بعملة غير عملة السوق لا يُحوَّل تلقائياً؛ السلوك الحالي يحوّل بعد الحساب العام ([E10-F12](./evidence/E10-payments-gateways.md)، P1). (7) حذف الحساب يُعالج الرصيد غير الصفري قبل الحذف؛ الحالي يحذف دون قراءة الأرصدة ([E04-F12](./evidence/E04-customer-identity-wallet-loyalty.md)، P1) |
| البيانات اللازمة | عملاء سعوديون بأرصدة تاريخية ودفاتر مصدّرة؛ صفوف مكافأة الإحالة؛ حركات ولاء بأنواعها؛ عميل بمحافظ في سوقين (لاحقاً) |
| نوع الاختبار | Unit (حساب الرصيد من الدفتر)؛ Feature (التدفقات)؛ Concurrency على MySQL؛ مطابقة ترحيل (scripted، بموجب [التقرير 07](./07-migration-rollback-plan.md))؛ مراجعة يدوية من المالية |
| معيار القبول (PROPOSED) | صفر حركات بين كيانين. 100% من الأرصدة السعودية تطابق الدفتر أو تُدرج في قائمة فروق يوقّعها المالك. لا رصيد سالب تحت التزامن. لا شحن مكرر لإشعار واحد |

### A4. الأدوار والنطاقات (roles and scopes)

**الفجوة الحالية:**

- لا حامل لنطاق بيانات الموظف (متجر أو كيان أو دولة) في أي مكان ([E05-F01](./evidence/E05-staff-roles-permissions-audit.md)، P0).
- التقارير والتصدير ولوحة المعلومات تجمع النظام كله باستعلامات مباشرة في المتحكمات ([E05-F02](./evidence/E05-staff-roles-permissions-audit.md)، P0). بيانات العملاء ومحافظهم وولاؤهم وتصديرها خلف مفتاح واحد بلا نطاق ([E05-F03](./evidence/E05-staff-roles-permissions-audit.md)، P0).
- تعليمات مسارات الإدارة التي لا تحمل وحدة صلاحية: الإحصاء والتصنيف في [E05 §2.4](./evidence/E05-staff-roles-permissions-audit.md)، والتفاصيل الأمنية في `SEC-E05-03` (الملحق المقيّد).
- الاختبارات تغطي وحدة الصلاحية لموديولين و12 مساراً فقط ([E15-F08](./evidence/E15-tests-ci-quality-gates.md)، P1). المسح الآلي لجدول المسارات الإدارية يثبت الحارس `admin` فقط ([E15 §2.3](./evidence/E15-tests-ci-quality-gates.md)).
- تباين تفويض موظف البائع بين لوحة الويب وAPI v3 مذكور في `SEC-E05-05`، ومسارات seller-center غير مختبرة ([E15 §2.3](./evidence/E15-tests-ci-quality-gates.md)).

| البند | التفاصيل |
|---|---|
| الهدف | مصفوفة (دور × سوق × إجراء) تُفرض على الويب وAPI والتصدير والتقارير والمشرفين والبائعين، وتُكمل `module_access` القائمة (GCC-14) |
| السيناريوهات | (1) لكل مسار إداري وحدة صلاحية أو استثناء موثّق. (2) لكل مسار بائع (ويب وv3) حارس وعزل `seller_id` وصلاحية `seller_can`. (3) موظف بنطاق سوق لا يرى تصدير عملاء سوق آخر. (4) الحقول الحساسة مخفاة للأدوار المقيّدة. (5) الرفض يُعاد بشكل عقد أخطاء موحّد؛ الحالي غير موحّد ([E23-F07](./evidence/E23-api-contract-seller.md)، P1). (6) سجل التدقيق يحمل السوق ([E05-F08](./evidence/E05-staff-roles-permissions-audit.md)، P1). (7) تطابق الصلاحية بين الويب وv3 لكل إجراء |
| البيانات اللازمة | أدوار: مشرف عام، مشرف سوق واحد، موظف بلا تصدير، بائع مالك، موظف بائع بصلاحية محدودة؛ جدول مسارات الإدارة والبائع المولَّد من `ApiManifest` |
| نوع الاختبار | Feature (مسح جدول المسارات، وهو امتداد لـ `AdminRouteAuthenticationTest`)؛ Feature لكل دور؛ Contract لحمولات الرفض؛ مراجعة يدوية للمصفوفة |
| معيار القبول (PROPOSED) | صفر مسار إداري بلا وحدة صلاحية أو استثناء موثّق؛ صفر مسار بائع بلا حارس؛ مصفوفة الصلاحية متطابقة بين الويب وv3؛ صفر تصدير لعميل خارج نطاق الدور |

### A5. لا تحويل آلي للعملات، والعملات ثلاثية الخانات

**الفجوة الحالية:**

- الاختبارات تثبّت SAR وخانتين؛ صفر اختبار لـ BHD أو OMR أو QAR؛ وموضع واحد يختبر ثلاث خانات ([E15-F03](./evidence/E15-tests-ci-quality-gates.md)، P1). الموضع هو `lavkoo:tests/Unit/Accounting/OdooMoneyTest.php:79-83`.
- بديل سعر الصرف في الاختبارات يثبّت نموذج `multi_currency` وأسعاراً ثابتة لـ USD وSAR وTRY (`lavkoo:tests/Support/FakeCurrencyRates.php:11`)، والعملة الافتراضية SAR بخانتين عشريتين بحسب [E15 §3](./evidence/E15-tests-ci-quality-gates.md) (المعرّفة في `lavkoo:tests/Support/FakeCurrencyRates.php:25-33`).
- نمط جيد يجب الحفاظ عليه: مبلغ بلا سعر صرف يُعامل مجهولاً لا صفراً ولا تخميناً ([`lavkoo:tests/Feature/OperationalCosts/ProfitWithoutExchangeRateTest.php:77`](./evidence/E15-tests-ci-quality-gates.md)).
- الكتالوج: وحدة تخزين السعر تفترض USD أساساً في `multi_currency` مع تحويل عند العرض ([E08-F01](./evidence/E08-catalog-discovery-pricing.md)، P0).
- الدفع: عملة الدفع تُشتق من إعداد عام مع تحويل صرف آلي ورجوع إلى أول عملة مفعّلة أو USD ([E10-F05](./evidence/E10-payments-gateways.md)، P0، VERIFIED في الكود).
- الدقة: التقريب الثابت إلى خانتين في 193 موضعاً من `round(…,2)` ([E02-F05](./evidence/E02-store-context-settings-currency-cache.md)، P1)، والمبالغ في الكتالوج والتكاليف والتنفيذ والـ API بخانتين ([E11-F03](./evidence/E11-inventory-fulfillment-shipping.md)، P1؛ [E23-F04](./evidence/E23-api-contract-seller.md)، P1).
- العرض: المنسِّق الرئيس للمبالغ في التطبيق يطبع صفر خانات عشرية دائماً، فيظهر 1.250 KWD كـ«1» ([E18-F01](./evidence/E18-customer-app-commerce-flows.md)، P0، VERIFIED)، وتتجاهل الاستجابات رمز العملة ([E22-F02](./evidence/E22-api-contract-customer.md)، P0).

| البند | التفاصيل |
|---|---|
| الهدف | لكل عملة (SAR/2، AED/2، QAR/2، KWD/3، BHD/3، OMR/3) تُحفظ القيم وتُحسب وتُعرض وتُرسل للبوابة بخانات العملة. لا تُشتق أسعار سوق من سوق آخر |
| السيناريوهات | (1) خاصية الذهاب والإياب: التخزين والحساب والعرض يحافظون على الخانات. (2) إرسال البوابة بالوحدة الأصغر حسب أس العملة، لا بضرب ثابت (Paymob يستخدم ×100 حالياً، [E10-F04](./evidence/E10-payments-gateways.md)، P0). (3) منتج بلا سعر في سوق ما لا يُباع فيه ولا يُشتق سعره. (4) التقارير المجمّعة تعرض «غير معروف» عند غياب سعر الصرف. (5) توزيع COD يساوي المستحق بالضبط ([`CodAllocatorTest`](./evidence/E15-tests-ci-quality-gates.md)). (6) الفاتورة وOdoo بثلاث خانات للعملات الثلاث. (7) قاعدة التقريب (نصف لأعلى أو غيره) مكتوبة ومختبرة، وهي قرار مفتوح (انظر القسم 2.3) |
| البيانات اللازمة | أسعار ثابتة لكل عملة؛ لقطات بوابة بالوحدة الأصغر؛ لا جدول سعر صرف في الاختبار؛ مبالغ بثلاث خانات |
| نوع الاختبار | Unit (خاصية، مع مزوّدات بيانات لكل عملة)؛ Dart unit للمنسِّق؛ Feature لـ checkout بكل عملة؛ Contract لحقل `currency_code` مع المبالغ |
| معيار القبول (PROPOSED) | صفر تحويل آلي في مسارات الكتالوج والـ checkout؛ 3 خانات في الكيانات الثلاثة؛ صفر تقريب ضمني بخانتين في مسار KWD/BHD/OMR؛ اختبار يثبت أن سعراً لسوق لا يُشتق من سوق آخر. لقطة SAR تبقى كما هي، مع قرار المالك في حالة العرض ([E18-F01](./evidence/E18-customer-app-commerce-flows.md)) |

### A6. الثيم (عزل النشر والمعاينة والتخزين)

**الفجوة الحالية:**

- مخازن التطبيق المحلية كلها بلا بُعد متجر: حمولة الثيم على القرص، وحالة الـ controller في الذاكرة ([E17-F01](./evidence/E17-customer-app-dynamic-theme.md)، P0، VERIFIED في الكود).
- عقد الثيم بلا بُعد متجر: اختيار عام للثيم النشط، وجواب إصدار واحد ([E17-F02](./evidence/E17-customer-app-dynamic-theme.md)، P1).
- فحص الإصدار يُجاب من ذاكرة عامة ومن القرص لمدة 400 ثانية ([E17-F03](./evidence/E17-customer-app-dynamic-theme.md)، P1).
- الحملات التراكبية لا تعرف ثيماً ولا متجراً ([E17-F24](./evidence/E17-customer-app-dynamic-theme.md)، P1).
- نسخ الثيم بين الدول تنقل مراجع كيانات ونطاقات سعرية خاصة بالمصدر ([E17-F09](./evidence/E17-customer-app-dynamic-theme.md)، P1).
- تطبيق العميل: 75 اختباراً للمحلِّل والتحكم والعرض. الـ backend: صفر اختبار للنشر والمراجعة والـ cache ([E15 §2.3](./evidence/E15-tests-ci-quality-gates.md)).

| البند | التفاصيل |
|---|---|
| الهدف | مسودة ونشر ومعاينة كل سوق مستقلة (GCC-07، GCC-09). لا يغيّر نشر سوق ثيم سوق آخر. مكتبة المكوّنات مشتركة |
| السيناريوهات | (1) مسودة سوق A لا تغيّر الثيم المنشور لسوق B. (2) مفاتيح الكاش وتخزين التطبيق المحلي تحمل السوق؛ بعد تبديل المتجر لا يُعرض محتوى السوق السابق حتى في وضع عدم الاتصال. (3) نسخ ثيم بين سوقين ينشئ مسودة ويعيد ربط المراجع (لا صفوف مشتركة للبانرات). (4) `revision` و`checksum` لا يتطابقان بين ثيمين منسوخين. (5) نسخة تطبيق مثبتة قديمة تستلم ثيم السعودية بحمولة متوافقة (golden). (6) معاينة مرتبطة بسوق ورمز معاينة محدد المدة. (7) مقارنة الأنماط بين المستودعين من مصدر واحد |
| البيانات اللازمة | سوقان بثيمين مختلفين وصفحات وبانرات؛ ثيم منسوخ؛ fixtures لكل نسخة تطبيق؛ قائمة الأنماط المعتمدة |
| نوع الاختبار | Feature لـ backend (B9)؛ Dart unit وrender matrix مع إضافة بُعد السوق؛ Contract golden لحمولة الثيم؛ مراجعة يدوية بصرية على جهاز لـ RTL |
| معيار القبول (PROPOSED) | صفر تسرّب ثيم أو محتوى بين الأسواق في المصفوفة؛ صفر تحديث لصف بانر مشترك عبر النسخ؛ الحمولة السعودية لنسخة قديمة مطابقة للقطة B7 |

### A7. ثبات مصدر التنفيذ (fulfillment source fixity)

**الفجوة الحالية:**

- ثلاث آليات لاختيار المصدر ولا بديل تلقائي في أيٍّ منها ([E11-F06](./evidence/E11-inventory-fulfillment-shipping.md)، INFO، VERIFIED). هذا يطابق القرار المعتمد ويجب حفظه باختبار.
- مصدر المنتج عمود عام واحد للطلبات الداخلية ([E11-F05](./evidence/E11-inventory-fulfillment-shipping.md)، P1).
- اللقطة التاريخية بلا سياق متجر أو كيان أو حساب ([E11-F10](./evidence/E11-inventory-fulfillment-shipping.md)، P1).
- لا حجز لمخزون المتجر؛ المجمّعات والحجوزات للقنوات فقط ([E11-F07](./evidence/E11-inventory-fulfillment-shipping.md)، P1).
- الاختبارات الحالية تثبّت الاستقرار داخل سياق متجر واحد: `lavkoo:tests/Feature/Fulfillment/OwnershipSnapshotTest.php:121-133` (طلب سابق لا يتحرك عند إعادة تعيين المنتج لاحقاً)، و`lavkoo:tests/Feature/Fulfillment/RouterTest.php:49` (كل مزوّد يستلم عناصره فقط).

| البند | التفاصيل |
|---|---|
| الهدف | منتج واحد في سوق واحد له مزوّد واحد ومصدر واحد. لا تقاطع بين أسواق. لا انتقال تلقائي عند نفاد المخزون أو تعطّل المزوّد |
| السيناريوهات | (1) المنتج نفسه في سوقين بمزوّدين مختلفين لا يتقاطعان في التوجيه أو المخزون أو الشحنات. (2) اللقطة تحفظ المزوّد والحساب والموقع والسوق والعملة لكل سطر. (3) إعادة تعيين المزوّد بعد الطلب لا تغيّر السطر. (4) نفاد مخزون المزوّد في سوق لا يحوّل الطلب لمزوّد سوق آخر ولا لمزوّد بديل. (5) تعطّل المزوّد يترك الطلب في حالة معلّقة لا في مزوّد آخر. (6) الـ pools والحجوزات والـ outbox تبقى كما هي (B2 وE11) |
| البيانات اللازمة | منتج مشترك في سوقين، مزوّدان، مواقع مخزون بلا دولة في الوقت الحالي؛ fixtures للـ pools والحجوزات |
| نوع الاختبار | Feature (امتداد `OwnershipSnapshotTest` و`RouterTest`)؛ Concurrency للـ pools؛ Contract لمحوّلات Torod وM5AZN مع HTTP مزيّف |
| معيار القبول (PROPOSED) | صفر حالات fallback في المصفوفة؛ 100% من لقطات الأسطر ثابتة بعد أي إعادة تعيين؛ الاختبارات القائمة لم تُعدَّل توقعاتها |

### A8. الاسترداد الجزئي

**الفجوة الحالية:**

- الاسترداد عبر البوابة غير مربوط بـ `refund_requests`؛ مسار المتجر يوثّق المرتجع نصياً أو يقيّد في المحفظة فقط ([E10-F10](./evidence/E10-payments-gateways.md)، P1، و[E10 §2.11](./evidence/E10-payments-gateways.md)).
- إلغاء طلب مدفوع مسبقاً لا يعكس `pending_amount` ولا `order_transactions`، ولا استرداد تلقائي إلا لـ POS بالمحفظة ([E09-F09](./evidence/E09-cart-checkout-order-pipeline.md)، P1).
- لا رد تلقائي إلى المحفظة عند إلغاء طلب دُفع بالمحفظة في غير POS ([E04-F10](./evidence/E04-customer-identity-wallet-loyalty.md)، P1).
- لا اختبار آلي للقيود المالية للاسترجاع الداخلي ([E09-F19](./evidence/E09-cart-checkout-order-pipeline.md)، P1). استرداد MyFatoorah الكلي والجزئي مغطى ([E15 §2.1](./evidence/E15-tests-ci-quality-gates.md)).

| البند | التفاصيل |
|---|---|
| الهدف | الاسترداد الجزئي لأي طلب يحسب الضريبة والعمولة والمخزون والمحفظة بدقة، وإلى كيان الطلب نفسه فقط |
| السيناريوهات | (1) استرداد جزئي لعناصر من طلب سعودي: المبلغ والضريبة والعمولة والمحفظة والمخزون (B5 golden). (2) استرداد جزئي بالكويتية بثلاث خانات. (3) طلب مدفوع بالمحفظة والبوابة معاً: يعود كل جزء إلى مصدره. (4) لا يزيد الاسترداد التراكمي على المبلغ المحصّل على مستوى الطلب ومستوى معاملة البوابة؛ سقف الاسترداد الجزئي مثبت باختبار في MyFatoorah (`lavkoo:tests/Feature/MyFatoorah/RefundAndAdminTest.php:104`)، ولا يوجد اختبار تزامن لهذا السقف في التدقيق. (5) الاسترداد لا يذهب إلى بوابة أو محفظة كيان آخر. (6) تكرار طلب الاسترداد لا يُنشئ استرداداً ثانياً (idempotency). (7) إلغاء طلب مدفوع يعكس `pending_amount` ويُنشئ حركة الاسترداد |
| البيانات اللازمة | طلب بثلاثة عناصر مدفوع بمزيج من MyFatoorah والمحفظة؛ طلب بالكويتية؛ عمولة وضريبة لكل عنصر؛ مخزون مرجعي |
| نوع الاختبار | Feature (B5)؛ Concurrency لسقف الاسترداد؛ Unit لحساب الحصص؛ مراجعة مالية يدوية لعينة من المطابقة |
| معيار القبول (PROPOSED) | صفر استرداد تراكمي يزيد على المحصّل؛ الاسترداد يصل إلى مصدر الدفع في الكيان نفسه؛ لقطة استرداد سعودي مطابقة للقطة B5؛ صفر استرداد مكرر من الطلب نفسه |

### A9. الشحن العابر للحدود والأهلية (GCC-16)

**الفجوة الحالية:**

- التحقق من الهاتف سعودي فقط في المحوّلين والحمولة، ودولة M5AZN `SA` ([E11-F04](./evidence/E11-inventory-fulfillment-shipping.md)، P1).
- مطابقة المدن سعودية عملياً؛ `country_code` غير مكتوب والمرشحون بلا تصفية دولة ([E11-F11](./evidence/E11-inventory-fulfillment-shipping.md)، P1).
- الشحن بإعدادات عامة، ولا فحص أهلية منتج في checkout المتجر ([E11-F12](./evidence/E11-inventory-fulfillment-shipping.md)، P1). لا فحص أهلية منتج × وجهة في الدفع ([E09-F11](./evidence/E09-cart-checkout-order-pipeline.md)، P1).
- قواعد المنتجات المنظَّمة لكل قناة لا لكل سوق، ومتغيّراتها بيئية ([E12-F09](./evidence/E12-sales-channels-marketplaces.md)، P1).
- تغطية الاختبارات: قيود البلد وZIP سعودية فقط (`lavkoo:tests/Feature/Address/StorefrontCheckoutTest.php:203`)، وصفر مفهوم لأهلية المنتج ([E15 §3](./evidence/E15-tests-ci-quality-gates.md)).

| البند | التفاصيل |
|---|---|
| الهدف | قرار الأهلية يُحسب قبل الدفع لكل (منتج، بلد المصدر، بلد الوجهة، المزوّد) مستقلاً عن تغطية شركة الشحن. المنتجات المنظَّمة (الصيدلانية والصحية) لا تُعبر الحدود تلقائياً |
| السيناريوهات | (1) منتج منظّم: التوصيل داخل بلده مسموح، والعابر للحدود مرفوض قبل الدفع بسبب مفهوم. (2) وجهة غير موجودة في قائمة المنتج تُرفض. (3) لا تغطية لشركة شحن في الوجهة تُرفض قبل الدفع. (4) لا طلب إلى شركة الشحن لطلب غير مؤهل. (5) المصدر ثابت (مخزون السوق المصدر فقط). (6) تطابق المدن والهواتف بصيغة كل بلد (تركيبية). (7) مجموعة قرارات السعودية الحالية مطابقة لقطة B2 |
| البيانات اللازمة | مصفوفة منتجات (منظّم/عادي) وقوائم وجهات؛ تغطية شركات شحن لكل بلد؛ عناوين تركيبية بصيغ الدول الست |
| نوع الاختبار | Unit (محرك الأهلية)؛ Feature (checkout)؛ Contract (مصفوفة)؛ مراجعة يدوية قانونية وضريبية (المحتوى التنظيمي غير مثبت في التدقيق، UNKNOWN) |
| معيار القبول (PROPOSED) | قرار الأهلية متاح لكل خلية من المصفوفة قبل إنشاء أي `payment_request`؛ صفر طلب إلى شركة شحن لطلب مرفوض؛ قرارات السعودية مطابقة للقطة |

### A10. الإشعارات (عزل المرسل والجمهور والقالب)

**الفجوة الحالية:**

- الإشعار البريدي المؤجَّل يُحلّ قالبه بلغة المتجر الافتراضية وقت التنفيذ، ولا توجد جلسة مستخدم داخل عامل الطابور ([E02-F11](./evidence/E02-store-context-settings-currency-cache.md)، P1، VERIFIED للحمولات وINFERRED للأثر؛ `lavkoo:app/Models/EmailTemplate.php:84-87`).
- مواضيع FCM العامة ثابتة، مثل `sixvalley` و`maintenance_mode_start_user_app` (`lavkoo:app/Utils/settings.php:264-269`)، والمبالغ في الإشعار الفوري بالعملة الافتراضية العامة (`lavkoo:app/Traits/PushNotificationTrait.php:233`).
- `notifications` و`customer/info` و`config` تعيد بيانات عالمية بلا تصفية سوق ([E22-F13](./evidence/E22-api-contract-customer.md)، P1).
- لا تفضيلات تسويقية ولا موافقات بنيوية ([E04-F14](./evidence/E04-customer-identity-wallet-loyalty.md)، P1). التقرير لم يشمل E06 (الاتصالات والامتثال) في مدخلاته، لذلك هذا المحور **غير مكتمل** (انظر حدود التقرير).
- التغطية: صفر في الإشعارات والبريد (`Notification::fake` و`Mail::fake` و`Bus::fake` غير مستخدمة، [E15 §2.6](./evidence/E15-tests-ci-quality-gates.md)).

| البند | التفاصيل |
|---|---|
| الهدف | كل حدث يُرسَل بهوية المرسل والقالب واللغة والـ topic الخاصة بسوقه، ولا يصل إلى جمهور سوق آخر |
| السيناريوهات | (1) حدث طلب سعودي يُرسَل بهوية السوق السعودي فقط. (2) طلب إماراتي لا يظهر في جمهور تسويق سعودي. (3) حملة تسويقية تختار أسواقاً محددة. (4) قالب بلغة السوق يُختار حتى داخل الطابور. (5) المبلغ في الإشعار بعملة الطلب وخاناتها. (6) SMS وWhatsApp بمزوّد السوق. (7) الإشعارات السعودية الحالية مطابقة لقطة B10 |
| البيانات اللازمة | أحداث طلب لكل سوق؛ عميل بموافقات في سوقين؛ قوالب بلغتين؛ fakes لـ FCM وSMS وWhatsApp وSMTP |
| نوع الاختبار | Feature مع fakes؛ Unit لاختيار اللغة والقالب؛ Contract لأسماء الـ topics؛ مراجعة يدوية لنصوص القوالب |
| معيار القبول (PROPOSED) | صفر رسالة لجمهور خارج السوق في المصفوفة؛ صفر قالب بلغة غير لغة السوق؛ لقطة B10 السعودية مطابقة |

### A11. أوضاع الإيقاف (GCC-12)

**الفجوة الحالية:**

- وضع الصيانة حالة عامة واحدة مخزّنة في الكاش فقط، تزول بأي تفريغ شامل، ولا يوجد إيقاف لكل متجر ([E02-F08](./evidence/E02-store-context-settings-currency-cache.md)، P1؛ `lavkoo:app/Traits/MaintenanceModeTrait.php:14`).
- 17 موضعاً تفرغ مخزن الكاش كله، فإيقاف سوق قد يمسح كل الأسواق ([E02-F09](./evidence/E02-store-context-settings-currency-cache.md)، P1).
- قنوات البيع مطفأة افتراضياً بالمفتاح `enabled` (`lavkoo:config/sales_channels.php:23`)، وهذا الإطفاء الموجود نمط مفيد لكنه عام.
- لا اختبار لوضع الصيانة الحالي إلا تعطيله في اختبارات اللوحة ([E15 §2.3](./evidence/E15-tests-ci-quality-gates.md)).

| البند | التفاصيل |
|---|---|
| الهدف | لكل سوق ثلاثة أوضاع (browse-only، maintenance، redirect إلى السوق الافتراضي) دون كسر الطلبات السابقة والدفع والاسترداد والدعم وwebhooks |
| السيناريوهات | لكل وضع: (1) الطلبات السابقة مرئية. (2) webhook الدفع لطلب سابق يُعالَج. (3) استرداد طلب سابق يُعالَج. (4) webhook حالة التنفيذ يُعالَج. (5) تذكرة الدعم تُفتح وتُقرأ. (6) الشراء الجديد يُمنع برسالة مفهومة. (7) الـ API يعيد حالة الإيقاف بشكل عقد صريح. (8) الوظائف المجدولة تستمر. (9) إجراء الإيقاف لا يفرّغ كاش الأسواق الأخرى. (10) سوق مُوقف لا يغيّر استجابات السوق الآخر |
| البيانات اللازمة | متجر بطلبات مفتوحة في كل مرحلة؛ webhooks مزيّفة موقّعة باختبار؛ إعداد إيقاف لكل سوق |
| نوع الاختبار | Feature (مصفوفة الأوضاع)؛ Contract لرمز الحالة؛ E2E للتوجيه (redirect) بعد التنفيذ |
| معيار القبول (PROPOSED) | 100% من الحالات الأربع لكل وضع (طلب، دفع، استرداد، تنفيذ) تنجح؛ وصفر تأثير لإيقاف سوق على سوق آخر |

### A12. انحدار السعودية الحالي وبوابته

**الفجوة الحالية:**

- الحزم الحالية (4,823 دالة) تُشغَّل على SQLite وMySQL/MariaDB بحسب المهام في `lavkoo:.github/workflows/ci.yml:44-80`، `lavkoo:.github/workflows/ci.yml:85-148`، `lavkoo:.github/workflows/ci.yml:202-310`، `lavkoo:.github/workflows/ci.yml:314-357`.
- الفجوة الكبرى في التغطية: [E15-F01](./evidence/E15-tests-ci-quality-gates.md)، P0، VERIFIED. لا خط أساس للتجارة الأساسية (القسم 2).
- حالة CI على HEAD غير معروفة ([E15-F04](./evidence/E15-tests-ci-quality-gates.md)، P1، UNKNOWN).

| البند | التفاصيل |
|---|---|
| الهدف | كل تغيير GCC يُقاس مقابل خط أساس سعودي ثابت، ولا تُعدَّل توقعات السعودية دون قرار |
| السيناريوهات | (1) تشغيل الحزم الحالية كاملةً على كل PR يلمس `app` أو `database` أو `config` أو `routes` أو `tests`. (2) تشغيل B13 على MySQL 8 وMariaDB 11.4.2 بالوضعين. (3) مقارنة لقطات golden بين الفرع والـ HEAD المعتمد. (4) بروفة هجرة على نسخة بنيوية قبل كل مرحلة |
| البيانات اللازمة | لقطة بنيوية (B1)؛ بيانات تركيبية سعودية؛ نسخة مجهولة من بيانات الإنتاج عند توفّرها (UNKNOWN) |
| نوع الاختبار | الحزم القائمة (Unit وFeature وConcurrency)؛ B2–B10 كـ Feature وConcurrency وContract |
| معيار القبول (PROPOSED) | صفر فشل؛ صفر توقع معدَّل دون قرار موثّق؛ صفر فرق في لقطات golden السعودية؛ نتيجة CI موثّقة على HEAD |

### A13. عقود الـ API للتطبيقين وتوافق النسخ القديمة

**الفجوة الحالية:**

- تطبيق العميل: 164 ثابتاً للنقاط كلها `/api/v1/…`، وصفر نقطة بلا مسار في الخادم، وصفر عدم تطابق في طريقة HTTP بعد احتساب `_method` ([E22 §2.4](./evidence/E22-api-contract-customer.md)، VERIFIED). هذا مطابقة بنيوية وليست اختباراً للحمولات.
- تطبيق البائع: 191 ثابتاً، و241 زوجاً (فعل، مسار) مميزاً يطلبها التطبيق، وصفر نقطة مفقودة وصفر عدم تطابق في الفعل ([E23 §2.1](./evidence/E23-api-contract-seller.md)، VERIFIED).
- عقود الحمولات مثبتة من جهة واحدة: 122 اختباراً في التطبيق بحمولات ملتقطة يدوياً، وصفر اختبار في الخلفية لـ21 من 22 اسماً ([E23 §2.9](./evidence/E23-api-contract-seller.md)).
- الخادم: لا Resource layer، والاستجابات تُسلسل نماذج Eloquent خاماً ([E23-F05](./evidence/E23-api-contract-seller.md)، P1). أي عمود جديد على الجدول يظهر في الاستجابة تلقائياً.
- المبالغ في الاستجابات بلا رمز عملة ([E22-F02](./evidence/E22-api-contract-customer.md)، P0)، ورمز `currency` في الحمولات الحديثة مصدره الإعداد العام، والاختبارات تثبّت `SYP` ([E23-F03](./evidence/E23-api-contract-seller.md)، P1).
- `X-App-Version` يحمل ثابتاً لا يطابق إصدار الحزمة، ومرتبط ببوابة التحديث ([E22-F12](./evidence/E22-api-contract-customer.md)، P1). بوابة إصدار تطبيق البائع موجودة (`lavkooappseller:test/app_version_gate_test.dart:6-17`، `lavkooappseller:test/app_version_gate_test.dart:28-38`).
- شكل الخطأ غير موحد، و401 يمسح الجلسة في التطبيق ([E22-F08](./evidence/E22-api-contract-customer.md)، P1؛ [E23-F07](./evidence/E23-api-contract-seller.md)، P1).
- محلّلات التطبيق تشترط حقولاً مالية دون فحص `null` في الطلب والمحفظة وتفاصيل المنتج ([E22-F15](./evidence/E22-api-contract-customer.md)، P1).
- تطبيق العميل لا يملك CI ولا اختبار بوابة إصدار ([E15 §2.12](./evidence/E15-tests-ci-quality-gates.md)).

| البند | التفاصيل |
|---|---|
| الهدف | عقد مكتوب وآلي لكل نقطة تستهلكها الإصدارات المنشورة. الإضافة آمنة، والتغيير الكاسر يُكشف في CI قبل النشر. الإصدارات القديمة المثبّتة تستمر في القراءة |
| السيناريوهات | (1) golden لكل نقطة من النقاط الحرجة في التطبيقين (config، الثيم، السلة، الطلب، الدفع، الدخول، الطلبات، seller-center، الكشوف). (2) تغيير إضافي (مفتاح جديد) لا يكسر الـ golden ويُسجَّل كآمن. (3) حذف أو إعادة تسمية أو تغيير نوع حقل مالي يكسر CI. (4) تشغيل الحزمة المثبتة القديمة (أو محلّلها) على استجابات الخادم الجديد. (5) محلّلات تقبل null في الحقول المالية. (6) شكل الخطأ الموحد 401/403/422 (7) بوابة الإصدار: أدنى إصدار أقل من المنشور يُوجَّه لشاشة التحديث، والأعلى يعمل. (8) الترويسة `X-App-Version` تطابق الإصدار المثبت |
| البيانات اللازمة | استجابات مرجعية مجهولة الهوية لكل نقطة (B7)؛ حزمتان قديمتان مثبتتان من المتاجر (**UNKNOWN**: الإصدار المنشور في المتاجر غير مؤكد، والمرجع الحالي `3.0.6+48` و`3.1.6+49` في المستودعات)؛ قائمة الحقول المالية مع عملتها |
| نوع الاختبار | Contract (golden وsnapshot، backend)؛ Dart unit للمحلّلات والبوابة؛ Feature لشكل الخطأ؛ مراجعة يدوية لقرارات الإصدار |
| معيار القبول (PROPOSED) | صفر تغيير كاسر مقابل golden للنقاط الحرجة دون قرار؛ الحزمتان القديمتان تحللان استجابات الخادم الجديد في CI؛ لقطة السطح المتتبَّعة تُقارن بـ `api:snapshot --fail-on-breaking` ([E15 §2.10](./evidence/E15-tests-ci-quality-gates.md)) بعد توفر جدوله؛ الحقول المالية تحمل رمز العملة |

## 4. البيئات وعزل الاتصالات الخارجية

### 4.1 البيئات

| البيئة | المحرك والبيانات | الاستخدام | الحالة |
|---|---|---|---|
| تطوير محلي | SQLite في الذاكرة (`lavkoo:phpunit.xml:26-27`)؛ بيانات تركيبية | اختبارات وحدة سريعة فقط. لا دليل على المال أو الهجرات | موجودة |
| CI MySQL 8.0 وMariaDB 11.4.2 | قاعدة اختبار اسمها يحتوي `test`؛ مهام المصفوفة (`lavkoo:.github/workflows/ci.yml:85-148`، `lavkoo:.github/workflows/ci.yml:202-310`، `lavkoo:.github/workflows/ci.yml:314-357`) | الدليل الرئيسي للمال والمخطط والتزامن | موجودة؛ حالة التشغيل على HEAD UNKNOWN |
| بنية الإنتاج (structure-only) | لقطة بنيوية بلا بيانات، مخزنة خارج المستودع العام | بناء القاعدة وتشغيل الهجرات (B1) | مفقودة؛ تحتاج موافقة المالك |
| إعادة الإنتاج (rehearsal) | نسخة مجهولة الهوية من بيانات الإنتاج | بروفة الهجرة قبل كل مرحلة GCC ([E15-F11](./evidence/E15-tests-ci-quality-gates.md)، P2) | UNKNOWN (وجود البيئة وإذن الوصول) |
| staging | غير موجودة | — | مفقودة ([E15-F11](./evidence/E15-tests-ci-quality-gates.md)) |
| تطبيقات Flutter | `flutter test` في CI، ومحاكي أو جهاز للمراجعة البصرية و RTL | اختبارات وحدة وواجهات وعقود مسجّلة | موجودة للبائع؛ مفقودة للعميل (B11) |

**قواعد البيانات:** لا تُستعمل بيانات إنتاج حقيقية في أي اختبار ولا في أي artifact مرفوع. الأسماء والهواتف والعناوين تركيبية. لا يُخزَّن أي مخطط تشغيلي داخل المستودع العام ([التقرير 05](./05-gap-risk-register.md)).

### 4.2 عزل الاتصالات الخارجية (PROPOSED)

الاختبارات لا تصل أبداً إلى بوابات الدفع أو شركات الشحن أو المنصات أو Odoo أو Firebase أو SMS وWhatsApp. الموجود اليوم ([E15 §2.6](./evidence/E15-tests-ci-quality-gates.md)) يكفي كأساس، والنواقص مذكورة.

| القاعدة | الموجود اليوم | المطلوب |
|---|---|---|
| منع الطلبات الخارجية | `Http::preventStrayRequests()` في 89 ملفاً وفي الأصناف الأساسية الثلاثة | فرضه في صنف الاختبار الأساسي لكل الملفات، وفشل الاختبار عند أي طلب خارجي غير مزيّف |
| المزوّدات المزيّفة | Torod وM5AZN عبر `FakeTorodAdapter` و`FakeM5aznAdapter` (`lavkoo:tests/Feature/Fulfillment/FulfillmentTestCase.php:66-76`)؛ MyFatoorah بخطافات بديلة | بديل لكل مزوّد جديد قبل أول تكامل، ومنع أي إعداد حقيقي |
| إعدادات البيئة | `*_ENABLED` تُطفأ افتراضياً؛ `sales_channels.enabled` مطفأ (`lavkoo:config/sales_channels.php:23`) | تثبيت الإطفاء في `phpunit` نفسه، لا في الإعدادات الافتراضية فقط |
| الكاش والطوابير والبريد | `array` و`sync` و`array` في `lavkoo:phpunit.xml:25-29` | الحفاظ عليها، مع اختبارات مخصصة لمفاتيح الكاش (A1) وطابور مُفعَّل لمحاكاة المؤجَّل (A10) |
| أسرار البوابات | لا أسرار في الاختبارات؛ الاختبارات تستخدم قيماً تركيبية | منع أي سر في CI؛ توقيع webhooks بمفتاح اختباري يعيش في الاختبار فقط |
| شبكة الـ runner | غير مفحوصة في هذا التدقيق (UNKNOWN) | قائمة حجب للمضيفين الخارجيين في إعداد الـ runner، والتحقق منها بإعداد مثبت |
| webhooks | مزيّفة في الاختبارات | حمولات مرجعية مجهولة الهوية، وتوقيع اختباري مع رفض التوقيع الخاطئ |

## 5. بوابات CI المقترحة (PROPOSED)

| البوابة | المحفز والنطاق | ما تفرضه | الحالة | الدليل |
|---|---|---|---|---|
| G1 lint | كل PR و push إلى `main` | `php -l` لكل ملف PHP | موجودة | `lavkoo:.github/workflows/ci.yml:24-42` |
| G2 tests-sqlite | كل PR | الحزمة الكاملة على SQLite، وحزمة `tests/Unit/Deploy` | موجودة | `lavkoo:.github/workflows/ci.yml:44-80` |
| G3 tests-mysql وقنوات وstrict | كل PR؛ المحركات MySQL 8 وMariaDB 11.4.2 | الحزم الحقيقية والتزامن والوضع الصارم | موجودة؛ تقسيم القنوات يعتمد على ترتيب التشغيل (E15-F15) | `lavkoo:.github/workflows/ci.yml:85-310`، `lavkoo:.github/workflows/ci.yml:314-357`؛ `lavkoo:tests/Unit/Ci/CiSalesChannelSplitCoversEveryTestTest.php:30-34` |
| G4 Saudi baseline (B13) | كل PR يلمس `app` أو `database` أو `config` أو `routes` أو `tests` | الحزم B2–B10 ولقطات golden؛ مطلوب للدمج | جديدة | القسم 2 |
| G5 structure build (B1) | كل PR يلمس `database` أو المخطط المرجعي | بناء القاعدة من اللقطة البنيوية وتشغيل الهجرات على المحركين | جديدة | القسم 4.1 |
| G6 مسح التفويض والعزل | كل PR يلمس المسارات أو الاستعلامات | مصفوفة B8 ومسح الاستعلامات غير المُنطَّقة بالسوق (A1) | جديدة (توسيع `AdminRouteAuthenticationTest`) | `lavkoo:tests/Feature/Security/AdminRouteAuthenticationTest.php:104-131` |
| G7 اختبارات المال | كل PR يلمس المال أو العملات | خاصية الذهاب والإياب لكل عملة (A5) | جديدة | القسم 3، A5 |
| G8 عقود API | كل PR يلمس `routes` أو الـ resources أو الاستجابات أو العقود | golden للنقاط الحرجة وحزم التطبيقين القديمة (A13) | جديدة | القسم 3، A13 |
| G9 تطبيق العميل | كل PR في `lavkooapp` | `flutter analyze` و`flutter test` وفحص الترجمات وبوابة الإصدار | جديدة (B11، معتمدة على نسب PR) | `lavkooappseller:.github/workflows/ci.yml:35-42` كنموذج |
| G10 تطبيق البائع | كل PR في `lavkooappseller` | الحالي كما هو؛ يضاف إليه golden المشترك | موجودة؛ هل تعمل على GitHub UNKNOWN | `lavkooappseller:.github/workflows/ci.yml:35-42` |
| G11 leak guard | كل PR والتوثيق | قواعد الأسماء والمحتوى، ومعها قواعد أرقام الدول الخمس الأخرى | موجودة؛ التوسيع مطلوب | `lavkoo:scripts/ci/leak-guard.php:149-151` ([E15-F10](./evidence/E15-tests-ci-quality-gates.md)) |
| G12 paths-ignore للتوثيق | مهام الاختبار فقط | تخطي المهام الثقيلة لتغييرات `docs/**` مع بقاء G11 | جديدة | `lavkoo:.github/workflows/ci.yml:5-9` ([E15-F09](./evidence/E15-tests-ci-quality-gates.md)) |
| G13 الفحوص المطلوبة | إعدادات الفروع على GitHub | G2 وG3 وG4 وG8 مطلوبة للدمج | غير معروفة (UNKNOWN) | [E15-F04](./evidence/E15-tests-ci-quality-gates.md) |
| G14 بروفة الهجرة | قبل كل مرحلة GCC | بروفة على نسخة وتحقق من المطابقة (التقرير 07) | يدوية، بموافقة المالك | [E15-F11](./evidence/E15-tests-ci-quality-gates.md) |

**شرط مسبق لـ G3:** يُصلح الاعتماد على ترتيب التشغيل في أربعة أصناف dispatch قبل إضافة اختبارات إلى `tests/Feature/SalesChannels` ([E15-F15](./evidence/E15-tests-ci-quality-gates.md)، P2، VERIFIED كتوثيق).

## 6. الأسئلة التي تحجب الاختبارات

هذه الأسئلة تُدرج في [التقرير 11](./11-open-decisions-for-owner.md) ولا تُحسم هنا:

1. قاعدة التقريب لـ KWD وBHD وOMR (نصف لأعلى أو غيره) وحدود العرض، لأن كل معيار قبول في A5 يعتمد عليها.
2. هل يُثبَّت العرض الحالي للسعودية بصفر خانات عشرية كما هو، أم يُصحَّح ويُعامل تغييراً في السلوك السعودي ([E18-F01](./evidence/E18-customer-app-commerce-flows.md))؟
3. هل يُسمح بإنتاج لقطة بنيوية من الإنتاج وتخزينها خارج المستودع العام، وما صاحب هذا الأصل؟ (E15 §7 Q5)
4. هل تتوفر بيئة إعادة إنتاج مجهولة الهوية، وما شروط الوصول إليها؟
5. ما الإصدارات الفعلية المنشورة في المتجرين (لحزم B7 وA13)؟
6. ما سلوك مكافأة الإحالة والمحفظة لكل سوق (A3)؟

## حدود هذا التقرير وعناصر UNKNOWN

- **لم يُشغَّل أي اختبار.** كل الأعداد عدٌّ ساكن. التغطية الفعلية وقت التشغيل UNKNOWN، ونسبة التغطية السطرية غير مقيسة ([E15 §7](./evidence/E15-tests-ci-quality-gates.md)، بند 8).
- **لم تُقرأ أجسام كل دوال الاختبار (4,823).** قُرئت الأجسام في الملفات الحاملة والمستشهَد بها، والباقي بالاسم ومطابقات البحث ([E15 §9 هـ](./evidence/E15-tests-ci-quality-gates.md)).
- **إعدادات GitHub غير مفحوصة:** حماية الفروع، نتائج التشغيل الفعلية، متغيّر `PILOT_BASELINE` (UNKNOWN، [E15 §7](./evidence/E15-tests-ci-quality-gates.md)، بنود 1–3).
- **المستودع القديم `pharmacyksa98` غير متاح** (UNKNOWN: missing access)، فلا يُعرف إن كانت بعض أصناف الاختبار الغائبة موجودة هناك ([E15-F13](./evidence/E15-tests-ci-quality-gates.md)).
- **المخطط الإنتاجي غير متاح.** أعمدة المال والجداول الخارجية للهجرات UNKNOWN ([E03-F01](./evidence/E03-data-model-ownership-inventory.md)).
- **محور الإشعارات غير مكتمل.** مدخلات هذا التقرير لا تشمل E06 (الاتصالات والامتثال)، وهو المرجع الأساسي لهذا المحور. الاعتماد هنا على E02 وE15 فقط.
- **قرارات بلا محور في هذا التقرير:** GCC-08 وGCC-10 وGCC-11 (السياسات القانونية لكل دولة قبل البيع) وGCC-18 ليست ضمن محاور REPORT_SPECS. يلزم محور مستقل لـ GCC-11 في مرحلة لاحقة.
- **المحتوى التنظيمي لشحن المنتجات المنظَّمة** (A9) غير مثبت في التدقيق؛ قواعد الاستيراد والتصدير والضرائب UNKNOWN.
- **قيم الإنتاج:** `currency_model` وقيم `unified_address_mode` والعملات المفعّلة وتفعيل الحملات والاستخدام الفعلي لسكربتات النشر UNKNOWN ([E15 §7](./evidence/E15-tests-ci-quality-gates.md)، بنود 4–10).
- **عدم وضوح تحميل الإصدارات:** أيّ ملف APK أو IPA منشور في المتجر غير معروف؛ المراجع هنا هي الإصدارات في المستودعات الحالية فقط.
- **النتائج الأمنية المقيّدة** لا تُفصَّل هنا؛ الإشارة بالمعرّفات ووصف فئة محايد فقط: `SEC-E15-01` إلى `SEC-E15-07` (أدوات تشغيلية وتفويض وتسجيلات وكتابات مال وتزامن)، و`SEC-E04-10` (كتابات الدفتر)، و`SEC-E05-03` و`SEC-E05-05` (تفويض). التفاصيل في الملحق المقيّد غير المنشور.

## سجل الأدلة الرئيسية

| الادعاء | الموضع | الحالة |
|---|---|---|
| 397 ملفاً تحت `tests/`، منها 62 دعماً، و318 صنف اختبار، و4,823 دالة | `lavkoo:tests` (عدّ `find`) و[E15 §2.1](./evidence/E15-tests-ci-quality-gates.md) | VERIFIED |
| SQLite في الذاكرة افتراضياً، وCACHE وQUEUE وMAIL مزيّفة | `lavkoo:phpunit.xml:25-29` | VERIFIED |
| المخطط المستنتج وثلاث صيغ لـ `orders.order_amount` | `lavkoo:tests/Support/BuildsApiAddressSchema.php:98`، `lavkoo:tests/Support/BuildsFulfillmentSchema.php:169`، `lavkoo:tests/Support/BuildsMyFatoorahSchema.php:94` | VERIFIED |
| لا dump في المستودع و`*.sql` متجاهَل | `lavkoo:.gitignore:53`، `lavkoo:CLAUDE.md:22` | VERIFIED |
| 336 هجرة، منها 92 بـ `Schema::create` | [E15 §2.4](./evidence/E15-tests-ci-quality-gates.md) | VERIFIED |
| صفر اختبار لدفتر المحفظة والولاء | [E04 §2.10](./evidence/E04-customer-identity-wallet-loyalty.md)؛ [E15 §2.3](./evidence/E15-tests-ci-quality-gates.md) | VERIFIED |
| صفر اختبار لنظام الثيم في الـ backend | [E15 §2.3](./evidence/E15-tests-ci-quality-gates.md) | VERIFIED |
| صفر `storefront_id` أو `legal_entity` أو `country_id` أو `market_id` في الاختبارات | [E15 §3](./evidence/E15-tests-ci-quality-gates.md) | VERIFIED |
| عملات الخليج: KWD في موضع رفض فقط، وBHD وOMR وQAR صفر | [E15 §2.3](./evidence/E15-tests-ci-quality-gates.md) | VERIFIED |
| اختبار ثلاث خانات واحد | `lavkoo:tests/Unit/Accounting/OdooMoneyTest.php:79-83` | VERIFIED |
| تعطيل وسطاء التفويض في اختبارات اللوحات | `lavkoo:tests/Feature/Address/PanelAddressTestCase.php:52-56` | VERIFIED |
| اختبار عدم انتقال طلب سابق عند إعادة تعيين المنتج | `lavkoo:tests/Feature/Fulfillment/OwnershipSnapshotTest.php:121-133` | VERIFIED |
| اختبار تقسيم الطلب بالمجموعة الواحدة | `lavkoo:tests/Feature/Fulfillment/StatusAndCheckoutGuardsTest.php:144` | VERIFIED |
| اختبار مبالغ وضع الطلب عبر API | `lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:101-146` | VERIFIED |
| التزامن على MySQL/MariaDB فقط، ومحاكاة مخزون checkout | `lavkoo:tests/Support/Concurrency/ConcurrentRunner.php:19-24`، `lavkoo:tests/Support/Concurrency/StockOperations.php:247-262` | VERIFIED |
| CI يشغّل التزامن في مهمة مستقلة | `lavkoo:.github/workflows/ci.yml:252-254` | VERIFIED |
| `coverage: none` ولا تحليل ساكن في CI | `lavkoo:.github/workflows/ci.yml:37` | VERIFIED |
| تعليق عدد الاختبارات «نحو 4,700» | `lavkoo:.github/workflows/ci.yml:47` | VERIFIED (كتعليق) |
| لا CI في تطبيق العميل | [E15 §2.12](./evidence/E15-tests-ci-quality-gates.md) | VERIFIED |
| CI تطبيق البائع موجود، وتشغيله على GitHub UNKNOWN | `lavkooappseller:.github/workflows/ci.yml:3-7`، `lavkooappseller:.github/workflows/ci.yml:35-42` | VERIFIED / UNKNOWN |
| ثابت إصدار تطبيق العميل بلا اختبار حارس | `lavkooapp:lib/utill/app_constants.dart:29` | VERIFIED |
| المبالغ تُعرض بصفر خانات عشرية في تطبيق العميل | `lavkooapp:lib/helper/price_converter.dart:75-78` | VERIFIED |
| 164 ثابتاً للنقاط في تطبيق العميل، بلا عدم تطابق مسار أو فعل | [E22 §2.4](./evidence/E22-api-contract-customer.md) | VERIFIED |
| 241 زوجاً (فعل، مسار) في تطبيق البائع، بلا نقاط مفقودة | [E23 §2.1](./evidence/E23-api-contract-seller.md) | VERIFIED |
| 14 ملف عقد و122 اختباراً في تطبيق البائع، بحمولات ملتقطة يدوياً | [E23 §2.9](./evidence/E23-api-contract-seller.md) | VERIFIED |
| لا لقطة عقد متتبَّعة في git ولا جدول لها في الهجرات | [E15 §2.10](./evidence/E15-tests-ci-quality-gates.md)؛ [E22-F14](./evidence/E22-api-contract-customer.md) | VERIFIED |
| 45 اختباراً تزامنياً مصنفة حسب المجال | [E15 §2.5](./evidence/E15-tests-ci-quality-gates.md) | VERIFIED |
| نطاق المسارات الإدارية والموديولات: `admin` لكل المسارات، وصلاحية الوحدة لـ12 مساراً | `lavkoo:tests/Feature/Security/AdminRouteAuthenticationTest.php:104-131`؛ [E15-F08](./evidence/E15-tests-ci-quality-gates.md) | VERIFIED |
| CI على كل push إلى `main` بلا `paths-ignore` | `lavkoo:.github/workflows/ci.yml:5-9` | VERIFIED |
| آخر تشغيل CI موثّق كان على المستودع القديم | `lavkoo:docs/sales-channels/final-report.md:128-131` | VERIFIED (كتوثيق) |
| فحوص المتصفح خارج المستودع | `lavkoo:docs/address/test-report.md:14`، `lavkoo:.gitignore:38-39` | VERIFIED |
| أمر `api:snapshot` مجدول أسبوعياً ويقارن جانب الطلب فقط | [E15 §2.10](./evidence/E15-tests-ci-quality-gates.md) | VERIFIED |
| تتبّع تخفيض العرض ورمز العملة في تطبيق البائع والعقود | `lavkooappseller:test/control_tower_contract_test.dart:50` | VERIFIED |
| اختبار بوابة الإصدار لتطبيق البائع | `lavkooappseller:test/app_version_gate_test.dart:6-17` | VERIFIED |
| مواضيع FCM العامة ثابتة | `lavkoo:app/Utils/settings.php:264-269` | VERIFIED |
| الوضع الصيانة حالة عامة في الكاش | `lavkoo:app/Traits/MaintenanceModeTrait.php:14` | VERIFIED |
| قنوات البيع مطفأة افتراضياً | `lavkoo:config/sales_channels.php:23` | VERIFIED |
| المزوّدات المزيّفة والـ HTTP المحجوب | `lavkoo:tests/Feature/Fulfillment/FulfillmentTestCase.php:49`، `lavkoo:tests/Feature/Fulfillment/FulfillmentTestCase.php:66-76` | VERIFIED |
| العملة الافتراضية للبديل SAR وخانتان | `lavkoo:tests/Support/FakeCurrencyRates.php:11`، `lavkoo:tests/Support/FakeCurrencyRates.php:25-33` | VERIFIED |
| اختبار الربح بلا سعر صرف يُعامل مجهولاً | `lavkoo:tests/Feature/OperationalCosts/ProfitWithoutExchangeRateTest.php:77` | VERIFIED |
| اختبار مرحلي لمطابقة سلوك الاسترداد البوابي (MyFatoorah) | `lavkoo:tests/Feature/MyFatoorah/RefundAndAdminTest.php:104` | VERIFIED |
| مقارنة المبالغ وتوقيع webhook لـ MyFatoorah مغطّاة بوحدة | [E15 §2.1](./evidence/E15-tests-ci-quality-gates.md) | VERIFIED |
| الإرسال المؤجل يُحلّ بلغة المتجر الافتراضية | `lavkoo:app/Models/EmailTemplate.php:84-87` | VERIFIED للكود، INFERRED للأثر ([E02-F11](./evidence/E02-store-context-settings-currency-cache.md)) |
| تطبيق العميل: الاختبارات الستة الغائبة موجودة في `ksaapp98` | `ksaapp98:.github/workflows/flutter.yml:32-36`، `ksaapp98:test/release_version_test.dart:6-22` | VERIFIED |
