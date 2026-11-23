# E15 — الاختبارات الآلية، التكامل المستمر، بوابات النشر وأدوات عقود الـ API (Automated tests, CI, deploy gates, contract tooling)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo` عند `7f42777c8247144df322fdc0e0eeca03ae947129` (شجرة العمل المحلية تتقدم عليه بالتزامات توثيق فقط تحت `docs/gcc/` يضيفها قائد الجلسة، أولها `lavkoo@046d623d`، ولا تغيّر أي كود)، `lavkooapp` عند `eedbbb8fd0303aa9fed5d4ff0ac45b3a409abf7a`، `lavkooappseller` عند `8ea00922e44db3d69059d46892f8f4f84349b580`، `ksaapp98` (مرجع قديم فقط) عند `79f50bec1c419cae8bc1a6f4c314962d3b682ac0`.
> لم يُشغَّل أي اختبار ولا أي أداة من المستودعات. كل الأعداد في هذا الملحق **عدٌّ ساكن** للنص المصدري.

## 1. النطاق وما تم فحصه

**ما فُحص:**

- `lavkoo:tests/` كاملاً: 397 ملفاً (388 ملف PHP، 8 قوالب Blade بديلة تحت `tests/Support`، وملف نصي واحد)، منها 318 صنف اختبار فعلي و15 صنفاً أساسياً مجرداً داخل `tests/Feature` و62 ملف دعم تحت `tests/Support`. قُرئت كاملةً الملفات الحاملة: `tests/TestCase.php`، `tests/CreatesApplication.php`، `phpunit.xml`، `phpunit.mysql.xml`، الأصناف الأساسية (`FulfillmentTestCase`، `MyFatoorahTestCase`، `ApiAddressTestCase`، `PanelAddressTestCase`)، بناة المخطط (`Builds*Schema`)، أدوات التزامن (`tests/Support/Concurrency/*`)، اختبارات الأمان، واختبارات CI الذاتية. بقية الملفات فُحصت بأسماء دوال الاختبار (4,823 دالة) وبعمليات بحث مسجَّلة.
- `lavkoo:.github/workflows/ci.yml` (357 سطراً، قُرئ كاملاً)، `scripts/ci/leak-guard.php` (500 سطر) و`scripts/ci/leak-guard-allow.json` (18 استثناءً)، `scripts/deploy/` (خمسة ملفات، 1,782 سطراً: قُرئت الترويسات والبوابات).
- أوامر Console: `BootstrapTestSchema`، `PrepareDatabase`، `ApiManifestCommand`، `ApiSnapshotCommand`، `DatabaseRefresh`، `LocalDatabaseRefresh`؛ والـ middleware: `RecordApiResponseShape`، `DatabaseRefreshMiddleware`؛ وخدمات `app/Services/DeveloperPortal` المرتبطة (`ApiManifest`، `ApiSnapshotService`، `ResponseShapeRecorder`).
- `database/factories` (ملف واحد)، `routes/test.php`، `app/Providers/RouteServiceProvider.php`، `.gitignore`، `composer.json`، `package.json`.
- Flutter: `lavkooapp:test/` (11 ملفاً)، `lavkooappseller:test/` (19 ملفاً)، `ksaapp98:test/` (17 ملفاً، للمقارنة)، `analysis_options.yaml` في التطبيقين، `lavkooappseller:.github/workflows/ci.yml`، `ksaapp98:.github/workflows/flutter.yml`.

**أدوات العدّ (سكربتات قراءة فقط في scratchpad):** عدّ `public function test*` و`@test` و`#[Test]` في PHP (النتيجة: 4,823 دالة اختبار بالبادئة `test` بعد استثناء 3 دوال مساعدة بالاسم نفسه في `tests/Support`؛ المسح الكامل لـ `tests/` يعطي 4,826، وصفر استخدام لـ `@test` أو `#[Test]`، و168 موضع `DataProvider`)؛ عدّ `test(` و`testWidgets(` في Dart؛ مطابقة مساحات الأسماء `App\Services\<Dir>` و FQCN للمتحكمات والنماذج مع نصوص الاختبارات؛ جرد المسارات الحرفية (`->get('/…')`، `getJson`، `route('…')`) داخل الاختبارات.

**ما لم يُفحص:** لم يُقرأ جسم كل دالة اختبار من الـ 4,823 (قُرئت الأجسام في نحو 30 ملفاً حاملاً، والباقي بالاسم)؛ لم تُفحص إعدادات GitHub (حماية الفروع، نتائج التشغيل الفعلية، متغيّر `PILOT_BASELINE`)؛ لم يُفتح المستودع القديم `pharmacyksa98` (UNKNOWN: missing access)؛ لم تُشغَّل أي أداة، فلا يوجد في هذا الملحق أي ادعاء «الاختبارات تنجح» من عندنا.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 خريطة التغطية في الـ backend (عدّ ساكن)

| المجلد | ملفات | أصناف اختبار | دوال `test*` | ما تثبته على مستوى عالٍ |
|---|---:|---:|---:|---|
| `tests/Feature/SalesChannels` | 113 | 110 | 1,816 | موصلات Amazon/Noon/Trendyol (طلبات، مخزون، أسعار، شحنات، مرتجعات، تقارير، feeds)، تقسيم المخزون على الحسابات (`StockAllocation`، `PoolNet`)، الـ outbox، إرسال الطلبات إلى Torod/M5AZN، شاشات الإدارة وصلاحيات الموديول، سلامة السجلات من الأسرار، «كل شيء يبدأ مطفأً». |
| `tests/Feature/Fulfillment` | 52 | 48 | 1,010 | موجّه التنفيذ `FulfillmentRouter` وتقسيم الطلب على المزوّدين، COD، محوّلا Torod و M5AZN ضد HTTP مزيّف، webhooks، لقطة ملكية سطر الطلب، حرّاس حالة الطلب، توافق خادم pilot، الهجرات (إضافية/متكررة/قابلة للتراجع)، المتجر غير المهاجَر. |
| `tests/Feature/Address` | 57 | 51 | 730 | نظام العنوان الموحّد: API العناوين للعميل، وضع الطلب عبر `/api/v1/customer/order/place*` من زاوية العنوان، صفحات checkout للويب، لقطة العنوان في الطلب، لوحتا الإدارة والبائع، مطابقة مدن Torod، حماية PII. |
| `tests/Feature/OperationalCosts` | 34 | 33 | 403 | دفتر التكاليف، العقود والعمولات بعملاتها، طبقات تكلفة المخزون FIFO، مطابقة الفواتير، قرارات المرتجعات، الربحية مع غياب سعر صرف. |
| `tests/Feature/Accounting` | 10 | 10 | 192 | ترحيل Odoo: العميل، بناء القيود، الـ outbox، التراجع، التفرّد، المطابقة، المفاتيح. |
| `tests/Feature/MyFatoorah` | 5 | 4 | 135 | بدء الدفع، التحقق، التسوية والـ webhook، الاسترداد الكلي والجزئي، ظهور البوابة في checkout. |
| `tests/Feature/Concurrency` | 9 | 9 | 45 | سباقات حقيقية بعدة عمليات PHP على MySQL/MariaDB (انظر 2.4). |
| `tests/Feature/Security` | 2 | 2 | 10 | كل مسار `admin/*` يحمل middleware `admin`؛ مسارات صيانة أزيلت ولا تعود. |
| `tests/Feature/DeepLink` | 1 | 1 | 11 | `DeepLinkResolver` والمسارات المنشورة للتطبيق. |
| `tests/Feature` (جذر) | 3 | 3 | 4 | `CiDatabaseTest`، `StrictModeTest`، `ExampleTest` (يُتخطّى بلا قاعدة التثبيت). |
| `tests/Unit/Deploy` | 3 | 3 | 108 | سكربتات النشر والنسخ الاحتياطي و leak guard. |
| `tests/Unit/Fulfillment` | 14 | 14 | 127 | آلة الحالات، توزيع COD، تنقيح PII والأسرار، رمز العملة وسعر الصرف. |
| `tests/Unit/OperationalCosts` | 13 | 13 | 107 | `Money`، قواعد الكسور، حاسبة القواعد، فحص ساكن «جداول المنصة تبقى للمنصة». |
| `tests/Unit/Accounting` | 4 | 4 | 59 | `OdooMoney`، XML-RPC، preflight. |
| `tests/Unit/SalesChannels` | 9 | 9 | 47 | `StockAllocator`، فحوص ساكنة (مخرج HTTP واحد، المخزون لا يكتب جداول المنصة). |
| `tests/Unit/MyFatoorah` | 2 | 2 | 16 | مقارنة المبالغ وتوقيع الـ webhook. |
| `tests/Unit/Ci` + `tests/Unit` (جذر) | 2 | 2 | 3 | حارس تقسيم مصفوفة CI؛ مثال Laravel. |
| **المجموع** | **397** (مع 62 ملف دعم) | **318** | **4,823** | |

الحالة: VERIFIED (عدّ ساكن). تعليق في `lavkoo:.github/workflows/ci.yml:47` يذكر «about 4,700 tests» وتعليق في `lavkoo:.github/workflows/ci.yml:235` يذكر 1,905 اختباراً في `tests/Feature/SalesChannels` وحده؛ الفرق عن العدّ الساكن يعود إلى توسّع مزوّدات البيانات وقت التشغيل. عدد الاختبارات الفعلي وقت التشغيل UNKNOWN (لم يُشغَّل شيء).

**الحقيقة البنيوية الأهم:** كل هذه الاختبارات تغطي مسارات العمل التي أُضيفت منذ 2026-11-05. الالتزام الأول `lavkoo@34dce658` (2026-11-04) احتوى تحت `tests/` أربعة ملفات فقط (`CreatesApplication.php`، `TestCase.php`، ومثالَي Laravel)، مع أنه احتوى أصلاً 162 ملف خدمة تحت `app/Services/{Theme,Monitoring,SellerCenter,DeveloperPortal}`. أي أن المنصة الأساسية وما بُني فوقها قبل ذلك التاريخ دخلت المستودع بلا اختبارات. الحالة: VERIFIED (`git ls-tree -r --name-only 34dce658 -- tests`).

### 2.2 التغطية بحسب مساحة الخدمات (مطابقة مساحات الأسماء)

عدد ملفات الاختبار التي تذكر `App\Services\<Dir>` (بحث نصي في كل `tests/**/*.php`):

| `app/Services/<Dir>` | ملفات الخدمة | ملفات اختبار تشير إليها |
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
| SellerIntelligence | 23 | **0** |
| SellerCenter | 20 | **0** |
| DeveloperPortal | 20 | **0** |
| Analytics | 19 | **0** |
| SellerAutomation | 15 | **0** |
| Commerce | 11 | **0** |
| Seo | 8 | **0** |
| Admin, DeliverySyria, Telemetry, Platform, Search, Reports, Retention, Storefront, Payments, Security, Vendor, Web | 28 | **0** |

ومن 86 ملف خدمة في جذر `app/Services` خمسة فقط يُشار إليها في الاختبارات (`AuditLogger`، `OrderAddressUpdateService`، `OrderEditService`، `OrderService`، `ShippingAddressService`)؛ والـ 81 الباقية بلا أي إشارة، ومنها `CartService`، `CouponService`، `CustomerWalletService`، `RefundRequestService`، `RefundStatusService`، `RefundTransactionService`، `PaymentRoutingService`، `NotificationService`، `PushNotificationService`، `VendorWalletService`، `WithdrawRequestService`، `ProductService`. من 430 متحكماً 29 فقط يظهر اسمها الكامل في الاختبارات، و70 من 235 نموذجاً، وصفر من ملفات `Modules/` الـ 229. الحالة: VERIFIED (بحث نصي؛ الاختبارات التي تصل المتحكم عبر URL لا تظهر في هذه المطابقة، ولذلك أُضيف جرد المسارات في 2.3).

### 2.3 ما هو غير مغطّى — عمليات البحث المسجَّلة

كل الأنماط بُحث عنها بـ `grep -rliE` (غير حسّاس لحالة الأحرف) في `lavkoo:tests/` على ملفات `*.php`.

| المجال | النمط | النتيجة | التفسير |
|---|---|---|---|
| السلة | `CartManager\|CartService\|CartController\|api/v1/cart\|'carts'\|cart/add\|add-to-cart` | 8 ملفات / 12 موضعاً | كلها إنشاء جدول `carts` وإدراج صف جاهز كتمهيد. لا اختبار لإضافة/تحديث/حذف عنصر سلة ولا لـ `CartManager`. |
| checkout | `checkout` | 39 ملفاً / 231 موضعاً | مغطّى من ثلاث زوايا فقط: العنوان (15 ملفاً)، حرّاس COD للتنفيذ (9)، MyFatoorah (3). لا اختبار لمسار checkout كاملاً. |
| وضع الطلب | `order/place\|OrderManager\|generate_order\|place-by-wallet\|checkout-complete` | 9 ملفات | `ApiOrderPlacementTest` (18 دالة) يستدعي `/api/v1/customer/order/place` و`place-by-offline-payment` و`place-by-wallet` (`lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:24-28`)؛ اختبار **متخصّص** ذو مزوّد بيانات يثبت مبالغ وضع الطلب عبر API (`order_amount`، الشحن، الخصم، الضريبة) في `lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:101-146`؛ وهو أحد 22 ملفاً تُسند قيمة `order_amount`. ضمن هذا الاختبار لا تغطية للضريبة غير المشمولة (يستخدم `useTaxIncludedPrices`، `lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:35`) ولا للعمولة. تقسيم الطلب متعدد البائعين (`order_group_id`) يظهر في مخططين اختباريين فقط (`lavkoo:tests/Support/BuildsApiAddressSchema.php:92`، `lavkoo:tests/Feature/Address/StorefrontOrderCompletionTest.php:58`) دون اختبار لمنطق التقسيم. الضريبة غير المشمولة مغطاة في `lavkoo:tests/Feature/Fulfillment/OrderScenarioMatrixTest.php:151`. |
| المحفظة | `wallet` ثم `WalletTransaction\|wallet_transactions\|create_wallet_transaction\|CustomerWalletService` | 10 ملفات / صفر للنمط الثاني | المحفظة تظهر كعمود `wallet_balance` في مخطط مستنتج وكتأكيد «لم يُخصم شيء» (`lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:289-297`) وكحالة شحن محفظة في MyFatoorah. **لا اختبار لدفتر المحفظة** (خصم، إضافة، استرداد إلى المحفظة). |
| الولاء | `loyalty` | ملف واحد / موضع واحد | الموضع الوحيد نص رسالة تأكيد في `lavkoo:tests/Feature/Fulfillment/OrderScenarioMatrixTest.php:127`. **صفر** لـ `loyalty_point_transactions\|LoyaltyPointTransaction\|loyalty_point`. |
| القسائم | `coupon` ثم `CouponService\|CouponController\|coupon/apply\|Coupon::` | 5 ملفات / صفر للنمط الثاني | قسيمة واحدة ثابتة المبلغ ضمن اختبار مبالغ الطلب. لا اختبار لقواعد القسيمة (حدّ الاستخدام، الحد الأدنى، المالك، الصلاحية الزمنية). |
| الاسترداد | `refund` ثم `RefundRequest\|refund_requests\|RefundTransaction\|RefundStatus` | 33 ملفاً / 3 ملفات (4 مواضع) | استرداد **البوابة** (MyFatoorah) ومرتجعات القنوات والتكاليف مغطّاة؛ أما دورة `refund_requests` الأساسية (طلب العميل ← قرار ← `refund_transactions` ← المحفظة) فلا اختبار لها: ثلاثة مواضع منها إنشاء جدول وتمهيد (`lavkoo:tests/Support/BuildsPanelAddressSchema.php:100`، `lavkoo:tests/Feature/OperationalCosts/SaleDocumentReaderTest.php:31`، `lavkoo:tests/Feature/OperationalCosts/SaleDocumentReaderTest.php:47`)، والرابع اسم دالة يختبر استرداد MyFatoorah المعلّق (`lavkoo:tests/Feature/MyFatoorah/RefundAndAdminTest.php:124`) لا دورة `refund_requests`. |
| الثيم | `ThemeManager\|ThemeBuilderService\|ThemeDelivery\|StorefrontThemeRenderer\|ViewerContext\|App.Services.Theme\|api/v1/theme` | **صفر** | لا اختبار backend واحد لنظام الثيم (28 ملف خدمة). |
| أدوار الإدارة | `AdminRole\|admin_roles\|module_access\|ModulePermission\|module:` | 18 ملفاً / 37 موضعاً | مغطّى للموديولات الجديدة فقط (`sales_channels`، `operational_costs`، fulfillment، address) ولمجموعة من 12 مساراً يثبّت الاختبار أن لكل منها حارس الإدارة وحارس الموديول. لا مصفوفة تثبت الصلاحية لكل مسارات الإدارة (808 تعريف مسار في `routes/admin/routes.php`). |
| Seller API v3 | `api/v3/seller\|RestAPI.v3.seller` | ملفان | استدعاء مباشر لدالتين فقط: `address_update` في `lavkoo:tests/Feature/Address/ApiSellerAddressTest.php:59-62` و`place_order` (POS) في `lavkoo:tests/Feature/Address/ApiSellerAddressTest.php:248`، وفحص نصي لملف متحكم في `lavkoo:tests/Feature/Fulfillment/StatusAndCheckoutGuardsTest.php:89-104`. `seller-center\|SellerCenter\|seller_center` → **صفر** (مقابل 236 تعريف مسار في `routes/rest_api/v3/seller.php`). |
| Customer API v1 | جرد المسارات الحرفية | نحو 8 مجموعات مسارات | `customer/address*`، `customer/order/place*`، `customer/order/fulfillments`، `payment/myfatoorah/status`، `address/regions`، `address/cities`، وكتلة العنوان في `ConfigController` (استدعاء مباشر). مقابل 180 تعريف مسار في `routes/rest_api/v1/api.php`. النمط `api/v1/` يعطي 18 ملفاً لكن معظمها عناوين API المزوّد M5AZN المزيّفة لا API المتجر. |
| مصادقة العميل | `auth/login\|auth/register\|verify-otp\|social-login\|PassportAuthController` | 7 ملفات — كلها عنوان تسجيل دخول المزوّد M5AZN المزيّف | **صفر** اختبار لتسجيل دخول/تسجيل العميل أو OTP. |
| Paymob / Tabby / Tamara | `paymob`، `tabby\|tamara` | **صفر** / **صفر** | |
| الإشعارات | `topic\|subscribeToTopic`؛ `Notification::fake`؛ `Mail::fake` | صفر / صفر / صفر | لا اختبار لإرسال push/SMS/WhatsApp/email للعملاء. |
| البحث والفهرسة | `ProductSearch\|product_search_index\|AdvancedSearch` | **صفر** | |
| Modules | `Modules.Blog\|Modules.AI\|AIProvider` | **صفر** | `Modules/TaxModule/tests` يحتوي ملفَي `.gitkeep` فقط. |
| وضع الصيانة | `maintenance_mode\|MaintenanceModeMiddleware` | ملف واحد | الموضع الوحيد **يعطّل** الـ middleware في اختبارات اللوحة (`lavkoo:tests/Feature/Address/PanelAddressTestCase.php:52-56`). |
| عملات الخليج | `'KWD'`، `'AED'`، `'QAR'`، `'BHD'`، `'OMR'` | 2 / 3 / 0 / 0 / 0 ملفات | `KWD` يظهر فقط كعملة **مرفوضة** (انظر 3). `'SAR'` في 132 ملفاً (453 موضعاً). |

اختبارات اللوحات تعطّل عمداً `AdminMiddleware` و`SellerMiddleware` و`SellerStaffAccessMiddleware` و`ModulePermissionMiddleware` (`lavkoo:tests/Feature/Address/PanelAddressTestCase.php:52-56`)، فهي تثبت منطق الصفحة لا التفويض.

ثلاثة أصناف اختبار مذكورة في تعليقات الكود ولا ملف لها تحت `tests/`: `SellerCenterRouteCollisionTest` (`lavkoo:app/Providers/RouteServiceProvider.php:175`)، `AlertHistoryDiscrepancyTest` (`lavkoo:app/Services/Monitoring/EventLog.php:16`)، `ChannelOperationsTest` (`lavkoo:app/Services/SalesChannels/Support/ChannelOperations.php:12`). البحث: مطابقة كل `\b[A-Z]\w+Test\b` في `app routes config database Modules scripts .github` مع أسماء ملفات `tests/`. الحالة: VERIFIED للغياب؛ سبب الغياب INFERRED (اختبارات وُجدت في شجرة أخرى ولم تُنقل) ويحسمه الوصول إلى `pharmacyksa98`.

### 2.4 استراتيجية قاعدة بيانات الاختبار

- **SQLite في الذاكرة افتراضياً:** `lavkoo:phpunit.xml:26-27` يثبّت `DB_CONNECTION=sqlite` و`:memory:` بعناصر `<server>` التي تتغلّب على متغيّرات البيئة؛ لذلك يوجد `lavkoo:phpunit.mysql.xml:28-37` بلا إعداد قاعدة بيانات ليأخذها من مهمة CI (الشرح في `lavkoo:phpunit.mysql.xml:5-8`).
- **لا dump للمخطط في المستودع:** `.gitignore` يتجاهل `*.sql` (`lavkoo:.gitignore:53`)، والمجلد `installation/` لا يحتوي إلا ملفَي نص للمسارات. `lavkoo:CLAUDE.md:22` يوجّه إلى استيراد ملف dump قبل الهجرة، لكن ذلك الملف غير موجود في المستودع (`ls installation/backup` → غير موجود؛ `git ls-files | grep -i '\.sql'` → لا نتائج)، وهو ما يقرّه `lavkoo:tests/Feature/ExampleTest.php:11-21` و`lavkoo:app/Console/Commands/BootstrapTestSchema.php:11-18` (الأخير يذكر أن 229 من 307 هجرات وقت كتابته كانت ALTER فقط). العدّ الحالي: 336 ملف هجرة، 92 منها فيها `Schema::create` و244 بلا أي `Schema::create`.
- **الجداول الأساسية «مستنتجة» في الاختبارات:** كل بانٍ يعيد تعريف `orders`، `users`، `products`… يدوياً من النماذج والكود، ويصرّح بأن ذلك لا يثبت السلوك على المخطط الحي: `lavkoo:tests/Support/BuildsFulfillmentSchema.php:10-20`، `lavkoo:tests/Support/BuildsAddressSchema.php:14-15`، `lavkoo:tests/Support/BuildsApiAddressSchema.php:16`. جداول الميزات الجديدة تُبنى بالهجرات **الحقيقية** (`lavkoo:tests/Support/BuildsFulfillmentSchema.php:239-248`، `lavkoo:tests/Support/BuildsSalesChannelSchema.php:40-67`).
- **نتيجة مباشرة:** العمود نفسه يعرَّف بثلاث صيغ مختلفة: `orders.order_amount` هو `decimal(16,2)` في `lavkoo:tests/Support/BuildsApiAddressSchema.php:98`، و`decimal(40,20)` في `lavkoo:tests/Support/BuildsFulfillmentSchema.php:169`، و`decimal(24,2)` في `lavkoo:tests/Support/BuildsMyFatoorahSchema.php:94`. لا يوجد مصدر حقيقة واحد لدقّة الأعمدة المالية داخل المستودع.
- **جداول مخصّصة خارج الهجرات:** الكود نفسه يسرد جداول «لا تنشئها أي هجرة هنا: تأتي من الـ dump»، منها `vendor_ledger_entries`، `vendor_settlements`، `payment_routing_rules`، `warehouses`، `stock_movements`، `audit_logs`، `api_snapshots`، `exchange_rate_logs` (`lavkoo:app/Services/Fulfillment/Readiness/Compatibility/RestoreSection.php:22-29`). كذلك جداول الثيم `themes`، `theme_versions`، `theme_sections`، `experience_pages` (التي يفحص وجودها `lavkoo:app/Services/Theme/ThemeDelivery.php:730`) لا يذكرها أي ملف تحت `database/migrations` أو `Modules` (بحث: `grep -rlE "'themes'|'theme_versions'|'theme_sections'|'experience_pages'"` → صفر).
- **حواجز الأمان:** الباني يرفض إسقاط الجداول ما لم تكن البيئة `testing` واسم القاعدة يحتوي `test` (`lavkoo:tests/Support/BuildsFulfillmentSchema.php:224-236`)؛ `CiDatabaseTest` يثبت أن المهمة تعمل على المحرّك الذي تدّعيه (`lavkoo:tests/Feature/CiDatabaseTest.php:15-30`)؛ `DB_STRICT_TESTS=1` يفعّل الوضع الصارم لاتصال الاختبار فقط لأن اتصال التطبيق نفسه `'strict' => false` (`lavkoo:tests/CreatesApplication.php:21-25`، `lavkoo:config/database.php:59`). `RefreshDatabase` غير مستخدم عمداً لأن `migrate:fresh` يفترض الـ dump (`lavkoo:tests/Feature/MyFatoorah/MyFatoorahTestCase.php:25-28`).
- **Factories:** ملف واحد بصيغة Laravel القديمة لنموذج `User` (`lavkoo:database/factories/UserFactory.php:21-29`)؛ لا استدعاء لـ `factory(` في `tests/`. بيانات الاختبار تُدرج يدوياً عبر `DB::table()->insert` وسمات `Creates*`.

### 2.5 اختبارات التزامن

`ConcurrentRunner` يشغّل العملية نفسها في عدة عمليات PHP تبدأ في لحظة مشتركة على قاعدة MySQL/MariaDB الحقيقية ويجمع تقرير كل عملية (`lavkoo:tests/Support/Concurrency/ConcurrentRunner.php:33-67`)؛ على SQLite تُتخطّى الاختبارات (`lavkoo:tests/Support/Concurrency/ConcurrentRunner.php:19-24`). العامل `lavkoo:tests/Support/Concurrency/worker.php:8-40` يقلع التطبيق ويحمّل ملفات التسجيل تحت `registrations/` (سبعة: attention، myfatoorah، noon، odoo، returns، splits، stock). الاختبارات الـ 45 تغطي: تقسيم مخزون الـ pool والـ outbox وحجوزات المخزون (14)، MyFatoorah (6: فاتورة واحدة لستة checkouts متزامنة، طلب واحد لستة تسليمات webhook)، Noon (6)، Odoo (5)، تقسيم الطرود (4)، قرارات المرتجعات (3)، مراجعات التنبيه (4)، استقبال الطلبات (1)، والعدّة نفسها (2). وبحسب تعليق CI فإن مهمة `concurrency` هي المكان الوحيد الذي تعمل فيه (`lavkoo:.github/workflows/ci.yml:252-254`). **التزامن على المسار الإنتاجي:** ملفات التزامن لا تغطي مسارات الدفاتر المالية الأخرى (انظر SEC-E15-07). مخزون checkout يُختبر بعملية محاكاة `stock.checkout` (`lavkoo:tests/Support/Concurrency/StockOperations.php:247-262`) تستدعيها `lavkoo:tests/Feature/Concurrency/StockConcurrencyTest.php:270-285`، لا بكود checkout الإنتاجي نفسه.

### 2.6 عزل الاستدعاءات الخارجية

- `Http::preventStrayRequests()` في 89 ملف اختبار و`Http::fake` في 103؛ الأصناف الأساسية تفرضه في `setUp`: `lavkoo:tests/Feature/Fulfillment/FulfillmentTestCase.php:49`، `lavkoo:tests/Feature/MyFatoorah/MyFatoorahTestCase.php:42`، `lavkoo:tests/Feature/Address/ApiAddressTestCase.php:31`.
- المزوّدان Torod و M5AZN يُستبدلان بـ `FakeTorodAdapter` و`FakeM5aznAdapter` عبر الإعداد (`lavkoo:tests/Feature/Fulfillment/FulfillmentTestCase.php:66-76`)، والمحوّلات الحقيقية تُختبر منفصلة ضد HTTP مزيّف (`lavkoo:tests/Support/FakeProviderAdapter.php:21-25`).
- MyFatoorah: الخطافات العامة للنجاح/الفشل تُستبدل ببدائل تعدّ الآثار الجانبية (`lavkoo:tests/Support/myfatoorah_test_hooks.php:3-16`، `lavkoo:tests/Feature/MyFatoorah/MyFatoorahTestCase.php:40`)؛ أي أن خطاف إنشاء الطلب الحقيقي `digital_payment_success` لا يعمل في حزمة MyFatoorah، ويُستدعى الحقيقي في `lavkoo:tests/Feature/Address/OrderPaymentCallbackTest.php:44` عبر مساعد واحد في الملف، وفي دالة واحدة في `lavkoo:tests/Feature/Address/ApiUnmigratedStoreTest.php:95`. الاختبار نفسه يوثّق قيداً معروفاً في مسار شحن المحفظة عبر الخطاف (انظر SEC-E15-05).
- القنوات: `tests/Support/Fixtures/AmazonFixtures.php` و`TrendyolFixtures.php` وموصلات بديلة (`ThrowingListingConnector`، `UnreachableChannelConnector`)؛ كل حزمة مبنية على `BuildsSalesChannelSchema` تُراقَب ضد تسرّب بيانات الاعتماد إلى السجلات (`lavkoo:tests/Support/BuildsSalesChannelSchema.php:19-23`).
- لا `Mail::fake` ولا `Notification::fake` ولا `Bus::fake` في أي ملف؛ `Queue::fake` في 6 ملفات و`Event::fake` في ملف واحد.

### 2.7 CI في `lavkoo`

`lavkoo:.github/workflows/ci.yml:5-9`: يعمل على كل `pull_request`، وعلى `push` إلى `main`، ويدوياً. الصلاحيات `contents: read` (`lavkoo:.github/workflows/ci.yml:11-12`). لا يوجد `paths-ignore`، فالتزام توثيق فقط إلى `main` يشغّل المصفوفة كاملة.

| المهمة | المصفوفة | ما تفعله | الدليل |
|---|---|---|---|
| `lint` | PHP 8.2، 8.3 | `php -l` لكل ملف PHP نملكه | `lavkoo:.github/workflows/ci.yml:24-42` |
| `tests-sqlite` | PHP 8.2، 8.3 | الحزمة كاملة على SQLite، ثم `tests/Unit/Deploy` كـ root على 8.3 | `lavkoo:.github/workflows/ci.yml:44-80` |
| `tests-mysql` | MySQL 8 × MariaDB 11.4.2 × مجموعتان | Fulfillment + Security، و Address على محرّك حقيقي | `lavkoo:.github/workflows/ci.yml:85-148` |
| `leak-guard` | — | فحص الأسطر المضافة | `lavkoo:.github/workflows/ci.yml:152-165` |
| `build-overlay` | PR فقط | يبني حزمة النشر كـ artifact ولا ينشر شيئاً | `lavkoo:.github/workflows/ci.yml:169-198` |
| `tests-mysql-sales-channels` | محرّكان × 5 مجموعات | قنوات A-M، N-Z، concurrency، myfatoorah، costs (+Accounting) | `lavkoo:.github/workflows/ci.yml:202-310` |
| `tests-strict-mode` | MariaDB 11.4.2 | حزم الميزات الجديدة مع وضع SQL صارم | `lavkoo:.github/workflows/ci.yml:314-357` |

المجموع 21 مهمة على PR و20 على push. لا قياس تغطية (`coverage: none` في `lavkoo:.github/workflows/ci.yml:37`)، ولا تحليل ساكن (لا `phpstan`/`psalm`/إعداد `pint` في المستودع؛ `laravel/pint` موجود كاعتماد تطوير في `lavkoo:composer.json:63` دون مهمة CI). حارس `CiSalesChannelSplitCoversEveryTestTest` يثبت أن نصفَي التقسيم يغطيان كل صنف (`lavkoo:tests/Unit/Ci/CiSalesChannelSplitCoversEveryTestTest.php:36-42`) ويوثّق عيباً معروفاً: اعتماد على ترتيب التشغيل في أربعة أصناف dispatch (`lavkoo:tests/Unit/Ci/CiSalesChannelSplitCoversEveryTestTest.php:30-34`).

**ما الذي يحجب الدمج؟** ملف الـ workflow لا يعرّف «فحوصاً مطلوبة»؛ ذلك إعداد حماية فروع في GitHub. الحالة: UNKNOWN (يحسمه الاطلاع على إعدادات المستودع).

**هل يعمل CI فعلاً على المستودعات الحالية؟** UNKNOWN. الدليل الوحيد المسجَّل لتشغيل ناجح يخص المستودع القديم: `lavkoo:docs/sales-channels/final-report.md:128-131` يوثّق تشغيلاً على `pharmacyksa98` عند `lavkoo@3f7920c` (20 مهمة ناجحة وواحدة متخطّاة)، و`lavkoo:docs/address/test-report.md:9` يوثّق 2,829 اختباراً بلا فشل على SQLite عند `lavkoo@b6c7d0e`. `3f7920c` سلف للالتزام المدقَّق `7f42777` وبينهما 31 التزاماً غيّرت 43 ملفاً تحت `app routes config database tests`. لا دليل داخل المستودع على تشغيل CI عند `7f42777` في `fullstackfull/lavkoo`.

### 2.8 Leak guard

`scripts/ci/leak-guard.php` يقرأ الأسطر التي يضيفها التغيير، التزاماً التزاماً وكفرق صافٍ، ولا يطبع النص المطابق (`lavkoo:scripts/ci/leak-guard.php:9-15`). قواعد أسماء الملفات: ملف بيئة، مادة مفاتيح، dump قاعدة بيانات، أرشيف، ملف اعتماد، مخرجات تشغيل (`lavkoo:scripts/ci/leak-guard.php:72-79`). قواعد المحتوى: `private-key`، `api-token`، `jwt`، `url-credentials`، `credential-literal`، `saudi-mobile-number`، `email-address` (`lavkoo:scripts/ci/leak-guard.php:125-156`)، مع `oversized-not-scanned` و`binary-not-scanned`. الاستثناءات بمسارات ولقواعد محددة موصوفة في SEC-E15-04 (انظر الملحق المقيّد). ملف السماح فيه 18 استثناءً معلَّلاً. قاعدة الهاتف تطابق أرقام الجوال السعودية فقط (`lavkoo:scripts/ci/leak-guard.php:149-151`). اختبار يربط قواعد الأسماء بـ `.gitignore` (`lavkoo:tests/Unit/Deploy/LeakGuardTest.php:236-254`).

### 2.9 سكربتات النشر وبواباتها (وصف ساكن)

- **`build-overlay.php`**: يبني من مراجعتَي git حزمة بالملفات المضافة/المعدَّلة تحت `app, bootstrap, config, database, resources, routes` فقط مع checksum نسخة الأساس (`lavkoo:scripts/deploy/build-overlay.php:7-16`، `lavkoo:scripts/deploy/build-overlay.php:29-30`). يرفض بدل أن يخمّن: ملفاً محذوفاً، تغيّر `composer.*`/`package*.json`، أو تغييراً خارج تلك المجلدات مثل `public/` و`Modules/` (`lavkoo:scripts/deploy/build-overlay.php:158-161`). يسجّل الهجرات المضافة في الـ manifest (`lavkoo:scripts/deploy/build-overlay.php:100-101`). الأساس في CI هو متغيّر المستودع `PILOT_BASELINE` أو الالتزام `698aa1e` (`lavkoo:.github/workflows/ci.yml:186-191`).
- **`deploy.php`** (مستقل، PHP فقط): `verify` و`apply` بلا `--yes` لا يغيّران شيئاً؛ الملف المنحرف عن الأساس يوقف `apply` حتى يُسمّى صراحةً بـ `--accept-drift`؛ ملفات اللغة تُدمج ولا تُستبدل؛ كل ملف مستبدَل يُنسخ أولاً إلى مجلد نسخ خارج شجرة التطبيق؛ سجل `applied.json` يُكتب قبل كل ملف ليتيح `rollback` (`lavkoo:scripts/deploy/deploy.php:6-27`). تحقق SHA-256 للحزمة والـ manifest (`lavkoo:scripts/deploy/deploy.php:771-798`)؛ رفض مجلد النسخ داخل التطبيق ورفض بدء نشر مع نشر سابق غير مكتمل (`lavkoo:scripts/deploy/deploy.php:680-687`). **لا يشغّل الهجرات ولا يمسح الـ cache ولا يعيد تشغيل شيء** (`lavkoo:scripts/deploy/deploy.php:26`).
- **`backup.sh`**: نسخة كاملة قبل النشر (dump بـ `--single-transaction`، أرشيف ملفات، عدّ الصفوف، `SHA256SUMS`، `RESTORE.txt`)، ترفض الوجهة داخل شجرة التطبيق وتفحص المساحة الحرة (`lavkoo:scripts/deploy/backup.sh:3-13`، `lavkoo:scripts/deploy/backup.sh:120-132`).
- **`verify-backup.sh`**: افتراضياً يقرأ فقط (checksums وسلامة الأرشيف وسطر اكتمال mysqldump) ويصرّح بأن ذلك ليس اختبار استعادة؛ مع `--restore-test` يحمّل الـ dump في قاعدة مؤقتة على الخادم نفسه ويقارن أعداد الصفوف (`lavkoo:scripts/deploy/verify-backup.sh:3-16`، `lavkoo:scripts/deploy/verify-backup.sh:46-50`).
- **`mysql-defaults.php`**: يمنع ظهور كلمة مرور القاعدة على سطر الأوامر (`lavkoo:scripts/deploy/mysql-defaults.php:6-12`).
- هذه السكربتات مغطّاة بـ 108 دوال اختبار في `tests/Unit/Deploy`. هل استُخدمت فعلاً في نشر إنتاجي؟ UNKNOWN.

### 2.10 أدوات عقد الـ API

- **`ApiManifest`**: سجلّ لكل endpoint مشتق من جدول المسارات والانعكاس على المتحكمات (middleware، مصادقة، حدّ المعدل، قواعد التحقق)، مخزَّن في الـ cache بمفتاح `developer_portal:manifest` مع بصمة لجدول المسارات (`lavkoo:app/Services/DeveloperPortal/ApiManifest.php:14-33`). الأمر `api:manifest` يطبع ملخصاً ويمكنه كتابة JSON إلى مسار يحدّده المشغّل (`lavkoo:app/Console/Commands/ApiManifestCommand.php:17-19`، `lavkoo:app/Console/Commands/ApiManifestCommand.php:51-54`).
- **`api:snapshot`**: يجمّد السطح في جدول `api_snapshots` ويسجّل الفروق في `api_changes` مع تصنيف breaking/warning، وله `--fail-on-breaking` (`lavkoo:app/Console/Commands/ApiSnapshotCommand.php:16-21`، `lavkoo:app/Services/DeveloperPortal/ApiSnapshotService.php:34-55`). مجدول أسبوعياً (`lavkoo:app/Console/ScheduleDefinition.php:220`). ما يُقارَن هو **جانب الطلب فقط**: الطرق، المسار، الجمهور، الإصدار، حقول الجسم وأنواعها (`lavkoo:app/Services/DeveloperPortal/ApiSnapshotService.php:240-266`)؛ أشكال الاستجابة ليست جزءاً من المقارنة.
- **`RecordApiResponseShape`**: middleware terminable في مجموعة `api` (`lavkoo:bootstrap/app.php:84-94`) يسجّل بعد إرسال الرد مفاتيح وأنواع JSON فقط في `api_response_shapes`، بعيّنة كل 60 دقيقة ولأكواد حالة محددة (`lavkoo:app/Http/Middleware/RecordApiResponseShape.php:28-35`، `lavkoo:app/Services/DeveloperPortal/ResponseShapeRecorder.php:27-34`، `lavkoo:app/Services/DeveloperPortal/ResponseShapeRecorder.php:122-147`)؛ مفعّل افتراضياً بسياسة `developer_record_response_shapes` (`lavkoo:app/Services/Platform/PolicyRegistry.php:358-362`).
- **هل اللقطات متتبَّعة في git؟ لا.** اللقطات والأشكال صفوف في قاعدة بيانات التشغيل؛ لا ملف لقطة/OpenAPI/Postman في المستودع (بحث: `git ls-files | grep -iE "snapshot|manifest|openapi|postman|swagger"` → ملفات كود وقوالب فقط). والجداول الثلاثة `api_snapshots`، `api_changes`، `api_response_shapes` **لا تنشئها أي هجرة** (بحث في `database` و`Modules` → صفر)، والخدمة تصمت إن غابت (`lavkoo:app/Services/DeveloperPortal/ApiSnapshotService.php:94-97`). وجودها في الإنتاج UNKNOWN. لا مهمة CI تستدعي `api:snapshot`، ولا اختبار واحد يشير إلى `App\Services\DeveloperPortal`.

### 2.11 أوامر قاعدة بيانات موروثة

`DatabaseRefreshMiddleware` middleware عام يعيد 503 إذا وُجد مفتاح cache باسم `demo_database_refresh` (`lavkoo:app/Http/Middleware/DatabaseRefreshMiddleware.php:19-31`، مسجَّل في `lavkoo:bootstrap/app.php:50`). أوامر Console موروثة من المنتج الأصلي لإعادة تهيئة قاعدة البيانات وضبط الإعدادات، غير مجدولة (بحث في `app/Console` و`routes` و`bootstrap` و`config`: لا إشارة إليها في تعريف الجدولة)، وتُوصف آثارها في E15-F12 (انظر SEC-E15-03). ملف مسارات اختبار موروث غير محمَّل من `RouteServiceProvider::map()` (انظر SEC-E15-01) (`lavkoo:app/Providers/RouteServiceProvider.php:48-66`).

### 2.12 اختبارات Flutter و CI التطبيقين

**تطبيق العميل `lavkooapp`** — 11 ملفاً، 104 `test(` و12 `testWidgets(`:

| الملف | العدد | ما يثبّته |
|---|---:|---|
| `dynamic_theme_schema_test.dart` | 22 | محلِّل `ThemePayload` لا يرمي أبداً؛ قرار مزامنة `ThemeRevision`؛ variant/version (`lavkooapp:test/dynamic_theme_schema_test.dart:7-9`). |
| `dynamic_theme_controller_test.dart` | 19 | `DynamicThemeController.sync` و`loadPage` ببديل خدمة يدوي (`lavkooapp:test/dynamic_theme_controller_test.dart:7-8`). |
| `section_variant_coverage_test.dart` | 16 | كل نمط عرض يقدّمه الـ builder يرسم شيئاً مختلفاً؛ القوائم منسوخة يدوياً من `SectionRegistry` في الـ backend (`lavkooapp:test/section_variant_coverage_test.dart:8-15`). |
| `dynamic_theme_render_matrix_test.dart` | 10 | كل نوع قسم × كل نمط، بعرض هاتف و RTL، بلا استثناء layout (`lavkooapp:test/dynamic_theme_render_matrix_test.dart:24-30`). |
| `dynamic_theme_registry_test.dart` | 3 | نوع مجهول أو renderer يرمي يكلّف قسماً واحداً لا الصفحة. |
| `theme_action_resolver_test.dart` | 5 | متى تكون البطاقة قابلة للنقر. |
| `banner_model_test.dart` | 9 | صورة الهاتف ووجهة النقر كما يجيب الخادم. |
| `myfatoorah_payment_status_test.dart` | 12 | الحالات الثلاث لنتيجة الدفع من الخادم؛ الـ fixture بعملة `SAR` (`lavkooapp:test/myfatoorah_payment_status_test.dart:19`). |
| `myfatoorah_return_url_test.dart` | 12 | التعرّف على رابط العودة على مضيفنا فقط. |
| `deep_link_resolver_test.dart` | 7 | قراءة جواب الخادم والتصرف بلا شبكة. |
| `localization_keys_test.dart` | 1 | كل مفتاح ترجمة مكتوب ساكناً موجود في اللغتين. |

لا يوجد مجلد `.github` في `lavkooapp` ولا أي ملف CI آخر (بحث: `git ls-files | grep -iE "^\.github|workflow|codemagic|fastlane|bitrise|\.gitlab-ci|Jenkinsfile"` → صفر). الحالة: VERIFIED. `analysis_options.yaml` يضمّن `flutter_lints` بلا قواعد إضافية (`lavkooapp:analysis_options.yaml:19-33`). لا `integration_test`.

**مقارنة بالتطبيق القديم `ksaapp98`:** عشرة من ملفات `lavkooapp:test/` مطابقة حرفياً لنظيراتها، و`deep_link_resolver_test.dart` مختلف. لكن `ksaapp98` يحتوي ستة ملفات اختبار **غير موجودة** في `lavkooapp`: `ksaapp98:test/order_placement_test.dart` (15 `testWidgets` لوضع الطلب ضد API مزيّف)، `ksaapp98:test/api_journeys_test.dart` (رحلات السلة وحالة الطلب)، `ksaapp98:test/payment_outcome_source_test.dart`، `ksaapp98:test/release_log_hygiene_test.dart`، `ksaapp98:test/release_version_test.dart`، `ksaapp98:test/secret_scan_test.dart`، مع أداة `ksaapp98:tool/secret_scan.dart` و workflow بخمس مهام (formatting، analysis، tests، secret scan، Android build) يعمل على كل push و PR (`ksaapp98:.github/workflows/flutter.yml:32-36`، `ksaapp98:.github/workflows/flutter.yml:55-56`، `ksaapp98:.github/workflows/flutter.yml:149-150`، `ksaapp98:.github/workflows/flutter.yml:166-167`). اختبار `release_version_test.dart` هو سلك تعثّر على بوابة الإصدار: يوثّق أن `AppConstants.appVersion` يساوي `16.2.1` بينما `pubspec.yaml` يقول `3.0.6+48` وأن «تصحيح» أحدهما منفرداً يحجز كل النسخ خلف شاشة التحديث (`ksaapp98:test/release_version_test.dart:6-22`). القيمة نفسها موجودة في `lavkooapp:lib/utill/app_constants.dart:29` **بلا** ذلك الاختبار.

**تطبيق البائع `lavkooappseller`** — 19 ملفاً، 142 `test(`، صفر `testWidgets(`:

- 14 ملف «عقد» (`*_contract_test.dart`) تحلّل حمولات JSON «التُقطت حرفياً من backend يعمل» لنقاط `/api/v3/seller/seller-center/*` و`/api/v3/seller/orders/{id}/breakdown`: action center، automation، brand claims، bulk jobs، control tower، finance control، inventory، order breakdown، pricing policy، reports، returns، security، seller center (overview/verification/payout/analytics)، statement (مثال: `lavkooappseller:test/seller_center_contract_test.dart:9-12`، `lavkooappseller:test/statement_contract_test.dart:6-13`). الحمولات مضمَّنة يدوياً في ملفات Dart ولا رابط آلي بينها وبين الـ backend؛ وعدّة منها بعملة `SYP` لا `SAR` (`lavkooappseller:test/control_tower_contract_test.dart:50`، `lavkooappseller:test/statement_contract_test.dart:20`، `lavkooappseller:test/order_breakdown_contract_test.dart:16`)، أي أنها التُقطت من نسخة تشغيل غير المتجر السعودي (INFERRED من رمز العملة).
- `app_version_gate_test.dart`: إصدار `pubspec.yaml` يجب ألا يقل عن ثابت `kBackendPublishedMinimumVersion` المكتوب في الاختبار (`lavkooappseller:test/app_version_gate_test.dart:6-17`، `lavkooappseller:test/app_version_gate_test.dart:28-38`)؛ `version_helper_test.dart` (7) لمقارنة الإصدارات؛ `dio_auth_header_test.dart` (5) لترويسة المصادقة؛ `pending_pos_order_test.dart` (3)؛ `report_export_helper_test.dart` (3).
- CI موجود: `lavkooappseller:.github/workflows/ci.yml:3-7` (push إلى `main` و`claude/**`، و PR إلى `main`)؛ مهمة `analyze-and-test` تشغّل `flutter analyze --no-fatal-infos` وفحص مفاتيح الترجمة و`flutter test` (`lavkooappseller:.github/workflows/ci.yml:35-42`)، ومهمة `build-android` تبني APK للتصحيح فقط (`lavkooappseller:.github/workflows/ci.yml:86-88`). خطوة التنسيق `continue-on-error` (`lavkooappseller:.github/workflows/ci.yml:28-30`). `analysis_options.yaml` يرفع `avoid_print`، `unused_import`، `dead_code` وغيرها إلى أخطاء (`lavkooappseller:analysis_options.yaml:18-33`). هل يعمل فعلاً على GitHub؟ UNKNOWN.

**اختبارات E2E للويب:** `package.json` يعرّف سكربتات Playwright (`lavkoo:package.json:11-17`) لكن `/tests-e2e/` و`/playwright.config.ts` متجاهَلان في git (`lavkoo:.gitignore:38-39`) وغير موجودين في الشجرة؛ و`lavkoo:docs/address/test-report.md:14` يقرّ بأن فحوص المتصفح الـ 390 «ليست جزءاً من المستودع».

## 3. افتراضات المتجر الواحد / الدولة / العملة

| الافتراض | الموضع | الحالة |
|---|---|---|
| حزمة التنفيذ كلها تعمل بـ `fulfillment.currency = SAR`، متجر عملة واحدة، و`decimal_point_settings = 2` | `lavkoo:tests/Feature/Fulfillment/FulfillmentTestCase.php:55`، `lavkoo:tests/Feature/Fulfillment/FulfillmentTestCase.php:78-88` | VERIFIED |
| MyFatoorah: `myfatoorah.currency = SAR`؛ أي عملة غير SAR **تُرفض** والاختبار يثبّت ذلك بعملة `KWD` | `lavkoo:tests/Feature/MyFatoorah/MyFatoorahTestCase.php:46`، `lavkoo:tests/Feature/MyFatoorah/PaymentFlowTest.php:184-193`، `lavkoo:tests/Feature/MyFatoorah/PaymentFlowTest.php:802-814`، `lavkoo:tests/Feature/MyFatoorah/CheckoutVisibilityTest.php:88` | VERIFIED |
| مقارنة وتنسيق المبالغ بخانتين عشريتين («هللة») | `lavkoo:tests/Unit/MyFatoorah/MoneyTest.php:25-29`، `lavkoo:tests/Unit/MyFatoorah/MoneyTest.php:55-64` | VERIFIED |
| بديل أسعار الصرف: العملة الافتراضية `SAR` و`decimals() = 2` | `lavkoo:tests/Support/FakeCurrencyRates.php:11`، `lavkoo:tests/Support/FakeCurrencyRates.php:25-33` | VERIFIED |
| الموضع الوحيد الذي يختبر 3 خانات عشرية | `lavkoo:tests/Unit/Accounting/OdooMoneyTest.php:79-83` (`decimals: 3`) | VERIFIED (بحث `decimals: 3\|three decimal\|3 decimal` في `tests/` → موضع واحد) |
| الأعمدة المالية في المخططات المستنتجة بخانتين: `wallet_balance decimal(16,2)`، `order_amount decimal(16,2)` | `lavkoo:tests/Support/BuildsApiAddressSchema.php:39`، `lavkoo:tests/Support/BuildsApiAddressSchema.php:98` | VERIFIED (مستنتجة لا حقيقية) |
| خادم pilot: متجر عملة واحدة رمز عملته الافتراضية `KSA` لا `SAR`، و`currencies.exchange_rate` نصّي | `lavkoo:tests/Support/BuildsPilotServerSchema.php:11-22` | VERIFIED كتوثيق داخل الاختبار؛ القيمة في الإنتاج UNKNOWN |
| اسم قاعدة CI `pharmacy_testing`، ونص النسخ الاحتياطي «Pharmacy KSA» ومجلد `pharmacy-backups`، ومتغيّر الأساس `PILOT_BASELINE` (خادم واحد) | `lavkoo:.github/workflows/ci.yml:112`، `lavkoo:scripts/deploy/backup.sh:3`، `lavkoo:scripts/deploy/backup.sh:62`، `lavkoo:.github/workflows/ci.yml:189` | VERIFIED |
| Leak guard يعرف شكل الجوال السعودي فقط | `lavkoo:scripts/ci/leak-guard.php:149-151` | VERIFIED |
| اختبارات العنوان مبنية على السعودية (مناطق/مدن/العنوان الوطني/جوال سعودي) | `lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:79-82` | VERIFIED |
| نطاق واحد للمتجر في الروابط العميقة: «رابط على نطاق متجر آخر ليس لنا» | `lavkoo:tests/Feature/DeepLink/PublishedAppLinkTest.php:97` | VERIFIED |
| أمر موروث يكتب `country_code` و`timezone` لبلد غير خليجي في `business_settings` | `lavkoo:app/Console/Commands/LocalDatabaseRefresh.php:30-31` | VERIFIED |
| عقود تطبيق البائع ملتقطة بعملة `SYP`، وثابت الحد الأدنى للإصدار مكتوب يدوياً | `lavkooappseller:test/control_tower_contract_test.dart:50`، `lavkooappseller:test/app_version_gate_test.dart:17` | VERIFIED |
| لا اختبار واحد يحمل مفهوم دولة/واجهة متجر/كيان قانوني | بحث `storefront_id\|legal_entity\|country_id\|market_id` في `tests/` → صفر مواضع (كلمة `market` تظهر بمعنى marketplace فقط) | VERIFIED |

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم في هذا المجال | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Guardrail 1 و2: لا إعادة كتابة، والحفاظ على السلوك السعودي القائم | خط أساس انحدار قوي لمسارات التنفيذ والقنوات والتكاليف و Odoo و MyFatoorah والعنوان (4,823 دالة) | لا خط أساس للسلة، checkout، المحفظة، الولاء، القسائم، دورة الاسترداد الأساسية، الثيم (backend)، الإشعارات، مصادقة العميل، Seller API v3 | 2.1–2.3 |
| Guardrail 3: حصر افتراضات SAR و`wallet_balance` و`loyalty_point` | اختبارات العملة في التنفيذ والتكاليف ترفض التخمين عند غياب سعر صرف | الاختبارات نفسها تثبّت SAR وخانتين؛ صفر اختبار لـ BHD/OMR/QAR | القسم 3؛ `lavkoo:tests/Feature/OperationalCosts/ProfitWithoutExchangeRateTest.php:77` |
| Foundation: local pricing, no auto-FX | نمط «مبلغ بلا سعر صرف = مجهول لا صفر ولا تخمين» مثبَّت في التكاليف والتنفيذ | لا اختبار يمنع تحويلاً آلياً في تسعير الكتالوج (لا اختبارات كتالوج أصلاً) | `lavkoo:tests/Feature/Fulfillment/OwnershipSnapshotTest.php:186` |
| Foundation: one fulfillment source per product per storefront, no fallback | `RouterTest`، `OwnershipSnapshotTest`، `ProductAssignmentTest` تثبّت اللقطة غير القابلة للتغيير وعدم الانتقال بين المزوّدين | كلها بسياق متجر واحد؛ لا بُعد «storefront» | `lavkoo:tests/Feature/Fulfillment/OwnershipSnapshotTest.php:121-133` |
| Provisional: single-entity checkout | حارس COD يحكم على كل `cart_group` منفرداً | لا اختبار لتقسيم الطلب متعدد البائعين (`order_group_id`) الذي سيُبنى عليه القيد | `lavkoo:tests/Feature/Fulfillment/StatusAndCheckoutGuardsTest.php:144` |
| GCC-13 و Provisional: محافظ ودفاتر منفصلة | — | صفر اختبار لدفتر المحفظة؛ قيد شحن المحفظة موثّق (انظر SEC-E15-05) | انظر SEC-E15-05 |
| GCC-15: ولاء محلي | — | صفر اختبار للولاء | 2.3 |
| GCC-14: نطاقات الموظفين فوق `module_access` | `AdminRouteAuthenticationTest` و`ModulePermissionsTest` يمسحان جدول المسارات آلياً | المسح يثبت وجود `admin` فقط لكل المسارات، وصلاحية الموديول لموديولين و12 مساراً؛ اختبارات اللوحة تعطّل الـ middleware | `lavkoo:tests/Feature/Security/AdminRouteAuthenticationTest.php:104-131`، `lavkoo:tests/Feature/SalesChannels/ModulePermissionsTest.php:25` |
| GCC-07 و GCC-09: ثيم لكل دولة ومكوّنات مشتركة | تطبيق العميل: 75 اختباراً للمحلِّل والتحكم والعرض | لا اختبار backend للنشر/المراجعات/الـ cache؛ قوائم الأنماط منسوخة يدوياً بين المستودعين؛ جداول الثيم بلا هجرة | `lavkooapp:test/section_variant_coverage_test.dart:8-15`؛ 2.4 |
| GCC-12: إيقاف مؤقت لكل متجر دون كسر الالتزامات | نمطا «المتجر غير المهاجَر» و«كل شيء يبدأ مطفأً» قابلان لإعادة الاستخدام | لا اختبار لوضع الصيانة الحالي؛ الـ middleware يُعطَّل في الاختبارات | `lavkoo:tests/Feature/SalesChannels/EverythingStartsOffTest.php:35`، `lavkoo:tests/Feature/Fulfillment/UnmigratedStoreTest.php:67` |
| GCC-16: شحن محلي وعابر للحدود | اختبارات قيود البلد/ZIP في checkout | سعودية فقط؛ لا مفهوم أهلية منتج | `lavkoo:tests/Feature/Address/StorefrontCheckoutTest.php:203` |
| GCC-17: قسائم محلية | — | صفر اختبار لقواعد القسائم | 2.3 |
| Foundation: بوابات الدفع مربوطة بالكيان | MyFatoorah: 151 اختباراً + 6 سباقات | SAR مثبَّت؛ صفر لـ Paymob/Tabby/Tamara | القسم 3 |
| CLAUDE.md للتطبيقين: الحفاظ على عقود API وبوابة الإصدار | تطبيق البائع: 14 ملف عقد + اختبار بوابة الإصدار + CI | لا بوابة عقد آلية بين المستودعات؛ تطبيق العميل بلا CI وبلا اختبار بوابة الإصدار | 2.10، 2.12 |
| Provisional: caches/queues/routes market-scoped «verify rather than assume» | CI يشغّل بـ `CACHE_DRIVER=array` و`QUEUE_CONNECTION=sync` | لا اختبار يتحقق من مفاتيح cache أو عزلها | `lavkoo:phpunit.xml:25-29` |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها |
|---|---|---|---|
| حزم `tests/Feature/{Fulfillment,SalesChannels,OperationalCosts,Accounting,MyFatoorah,Address,Concurrency}` و`tests/Unit/*` | Preserve | هي خط الانحدار الوحيد للسلوك السعودي في هذه المسارات | لا تُعدَّل توقعاتها ضمن عمل GCC؛ أي تغيير فيها يُعامَل كتغيير سلوك سعودي يحتاج موافقة. |
| الأصناف الأساسية التي تثبّت `SAR` وخانتين (`FulfillmentTestCase`، `MyFatoorahTestCase`، `FakeCurrencyRates`) | Extend | صحيحة للسعودية، لكنها لا تستطيع التعبير عن سوق آخر | إضافة مزوّد بيانات «سياق السوق» (عملة + عدد الخانات) مع بقاء SAR/2 القيمة الافتراضية؛ الحالات الجديدة تُضاف ولا تُستبدل. |
| بناة المخطط `tests/Support/Builds*Schema.php` | Refactor (لاحقاً، بموافقة) | ثلاث صيغ للعمود المالي نفسه ولا مصدر حقيقة | مخطط بنيوي واحد (structure-only، بلا بيانات) مستخرج من الإنتاج ومحفوظ خارج المستودع العام، تُبنى منه قاعدة الاختبار؛ البناة الحاليون يبقون حتى يُستبدلوا تدريجياً. |
| عدّة التزامن `tests/Support/Concurrency/*` | Preserve + Extend | تعمل على محرّك حقيقي بعمليات حقيقية | تسجيلات جديدة: خصم محفظة، نقاط ولاء، حدّ قسيمة، مخزون checkout، لكل واجهة متجر. |
| أنماط «dormant until migrated» و«EverythingStartsOff» و`MigrationsTest` | Preserve + Extend | النموذج الصحيح لإطلاق GCC خاملاً | كل هجرة GCC تأتي باختبار «إضافية/متكررة/قابلة للتراجع/لا تغيّر صفاً سعودياً» واختبار «المتجر السعودي غير المهاجَر يعمل كما كان». |
| `.github/workflows/ci.yml` في `lavkoo` | Preserve + Extend | مصفوفة محرّكات ناضجة | مهمة «Saudi baseline» إلزامية؛ `paths-ignore` للتوثيق؛ توثيق الفحوص المطلوبة للدمج. |
| `scripts/ci/leak-guard.php` | Extend | قاعدة الهاتف سعودية فقط | قواعد أرقام الدول الخمس الأخرى. |
| `scripts/deploy/*` | Preserve | بوابات drift/backup/rollback مختبَرة | بند توثيقي: الهجرات خطوة منفصلة يدوية؛ تعريف الأساس لكل بيئة عند تعدّد البيئات. |
| `ApiManifest` / `api:snapshot` / `RecordApiResponseShape` | Extend | موجودة لكنها تشغيلية وغير متتبَّعة ولا تقارن الاستجابات | هجرة لجداولها (أو توثيق مصدرها)، وتصدير لقطة JSON متتبَّعة في git تُقارَن في CI بـ `--fail-on-breaking`، وإضافة أشكال الاستجابة للنقاط التي يستهلكها التطبيقان. |
| اختبارات عقد تطبيق البائع | Preserve + Extend | تثبّت ما يتوقعه التطبيق | توليد الحمولات نفسها من اختبارات backend (golden files مشتركة) بدل الالتقاط اليدوي. |
| اختبارات تطبيق العميل (ثيم + MyFatoorah) | Preserve | تحمي الثيم الديناميكي والدفع | — |
| CI لتطبيق العميل + الاختبارات الستة الموجودة في `ksaapp98` فقط | New (Dependent-On-PR) | غير موجودة في `lavkooapp`؛ حالة نقلها تتبع E21 | نقلها بعد حسم النسب، قبل أي تعديل GCC على التطبيق. |
| حزم خط الأساس للتجارة الأساسية (سلة، checkout، محفظة، ولاء، قسائم، استرداد، ثيم backend، إشعارات، مصادقة، Seller API) | New | غير موجودة إطلاقاً | القائمة B1–B13 في 6.1. |
| ملف المسارات الموروث غير المحمَّل والأوامر `prepare:database`، `database:refresh`، `local:database-refresh` | Refactor (حذف بموافقة) | موروثة ذات آثار تشغيلية كبيرة وبلا اختبار (انظر SEC-E15-01 وSEC-E15-03) | حذفها قبل إنشاء بيئات GCC إضافية. |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E15-F01 | لا خط أساس انحدار آلي للتجارة الأساسية: السلة، checkout الكامل، تقسيم الطلب، دفتر المحفظة، الولاء، قواعد القسائم، دورة `refund_requests`، مصادقة العميل، الكتالوج والتسعير، الثيم (backend)، الإشعارات، Paymob، Seller API v3 | P0 | VERIFIED | 2.2 و2.3؛ `lavkoo@34dce658`؛ `lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:101-146` (اختبار مبالغ وضع الطلب عبر API؛ من 22 ملفاً تُسند قيمة `order_amount`) | شرط «السلوك السعودي لا يتغيّر» غير قابل للتحقق الآلي في أكثر المناطق التي سيمسّها GCC (طلبات، محافظ، ولاء، أسعار) | بناء حزم B2–B10 (6.1) كـ characterization tests على السلوك الحالي **قبل** أي تنفيذ GCC، وجعلها بوابة دمج. |
| E15-F02 | المخطط الأساسي غير موجود في المستودع: الاختبارات تعمل على جداول «مستنتجة» بثلاث صيغ متعارضة للعمود المالي نفسه، وجداول مخصّصة (منها دفاتر البائعين والثيم ولقطات API) لا تنشئها أي هجرة | P0 | VERIFIED | `lavkoo:tests/Support/BuildsFulfillmentSchema.php:10-20`، `lavkoo:tests/Support/BuildsApiAddressSchema.php:98`، `lavkoo:tests/Support/BuildsFulfillmentSchema.php:169`، `lavkoo:tests/Support/BuildsMyFatoorahSchema.php:94`، `lavkoo:app/Services/Fulfillment/Readiness/Compatibility/RestoreSection.php:22-29`، `lavkoo:app/Console/Commands/BootstrapTestSchema.php:11-27` | أي هجرة GCC تمسّ `orders`/`users`/`products`/المحافظ لا يمكن إثبات سلامتها في CI على المخطط الحقيقي؛ دقّة الكسور لـ KWD/BHD/OMR غير قابلة للاختبار؛ لا يمكن إنشاء بيئة من الصفر من المستودع | B1: لقطة بنيوية للمخطط الإنتاجي (بلا بيانات) تُحفظ كأصل خاص، ومهمة CI تبني منها القاعدة وتشغّل الهجرات والاختبارات. |
| E15-F03 | الاختبارات الحالية تثبّت SAR وخانتين عشريتين ورفض أي عملة أخرى في MyFatoorah؛ صفر اختبار لـ BHD/OMR/QAR، وموضع واحد لثلاث خانات | P1 | VERIFIED | القسم 3 | تنفيذ عملات الخليج سيصطدم باختبارات صحيحة للسعودية؛ خطر «إصلاح» الاختبار بدل تمديده، أو تدوير KWD/BHD/OMR إلى خانتين بلا إنذار | تحويل الثوابت إلى مزوّدات بيانات لكل سوق مع إبقاء حالات SAR حرفياً؛ إضافة B6 قبل أي تغيير في دوال المال. |
| E15-F04 | حالة CI على المستودعات الحالية غير معروفة؛ آخر تشغيل ناجح موثّق كان على المستودع القديم لسلف يبعد 31 التزاماً؛ إعدادات حماية الفروع غير معروفة | P1 | UNKNOWN | `lavkoo:docs/sales-channels/final-report.md:128-131`، `lavkoo:.github/workflows/ci.yml:5-9` | لا يمكن الادعاء بأن HEAD «أخضر»؛ بوابة الدمج قد لا تكون مفروضة | التحقق من تبويب Actions وإعدادات الفروع في المستودعات الثلاثة وتسجيل النتيجة قبل بدء التنفيذ. |
| E15-F05 | تطبيق العميل `lavkooapp` بلا CI وبلا ستة ملفات اختبار وأداة فحص أسرار موجودة في `ksaapp98` (وضع الطلب، رحلات API، مصدر نتيجة الدفع، نظافة سجلات الإصدار، سلك تعثّر الإصدار، فحص الأسرار) | P1 | VERIFIED (الغياب) / UNKNOWN (السبب) | 2.12؛ `ksaapp98:.github/workflows/flutter.yml:32-36`؛ `ksaapp98:test/release_version_test.dart:6-22`؛ `lavkooapp:lib/utill/app_constants.dart:29` | تغييرات GCC في تطبيق العميل (اختيار الدولة، السلة لكل متجر، العملة) بلا شبكة أمان لوضع الطلب ولا لبوابة الإصدار التي تحجز كل المستخدمين عند الخطأ | حسم النسب في E21، ثم نقل الاختبارات والـ workflow قبل أي تعديل على التطبيق. |
| E15-F06 | أدوات عقد الـ API تشغيلية فقط: جداولها بلا هجرة، لقطاتها غير متتبَّعة في git، لا تُقارن أشكال الاستجابة، غير موصولة بـ CI، وبلا اختبارات؛ عقود تطبيق البائع ملتقطة يدوياً (وبعملة `SYP`) ولا يقابلها أي اختبار backend | P1 | VERIFIED | 2.10؛ `lavkoo:app/Services/DeveloperPortal/ApiSnapshotService.php:240-266`؛ `lavkooappseller:test/control_tower_contract_test.dart:50` | تغيير حقل في استجابة v1/v3 (مثل إضافة سياق السوق أو تغيير تنسيق المبلغ) لن يوقفه أي فحص في أي من المستودعات الثلاثة | B7: golden files للاستجابات تولَّد في backend وتُستهلك في اختبارات التطبيقين، ولقطة سطح API متتبَّعة تُقارَن في CI. |
| E15-F07 | ملفات التزامن لا تغطي مسارات الدفاتر المالية الأخرى (انظر SEC-E15-07)، ومخزون checkout يُختبر بعملية محاكاة لا بالكود الإنتاجي، وتعمل على مهمة MySQL/MariaDB فقط | P1 | VERIFIED | 2.5؛ `lavkoo:tests/Support/Concurrency/StockOperations.php:247-262`؛ `lavkoo:.github/workflows/ci.yml:252-254` | فصل المحافظ والدفاتر لكل كيان (GCC-13) سيعيد كتابة مسارات خصم/إضافة بلا إثبات سلوكها الحالي تحت التزامن | تسجيلات سباق جديدة ضمن B3/B4 قبل تعديل الدفاتر. |
| E15-F08 | تغطية التفويض جزئية: المسح الآلي يثبت `admin` لكل مسارات الإدارة لكنه لا يثبت صلاحية الموديول إلا لموديولين و12 مساراً؛ اختبارات اللوحة تعطّل middleware التفويض؛ واجهات البائع غير مغطاة بمسح آلي للتفويض (انظر SEC-E15-06) | P1 | VERIFIED | `lavkoo:tests/Feature/Security/AdminRouteAuthenticationTest.php:104-131`، `lavkoo:tests/Feature/Address/PanelAddressTestCase.php:52-56` | نطاقات GCC-14 ستُبنى فوق طبقة لا يوجد لها جرد آلي كامل؛ تسرّب بيانات بين الدول لن يُلتقط | B8: اختبار جدول مسارات يثبت لكل مسار إداري موديولاً، ثم لاحقاً نطاق سوق. |
| E15-F09 | CI يعمل على كل push إلى `main` بلا `paths-ignore`: التزامات التوثيق (ومنها تقارير هذا التدقيق) تشغّل 20 مهمة بحدود تصل إلى 110 دقائق؛ و leak guard يطبّق على التوثيق قواعد محتوى محددة (انظر SEC-E15-04) | P2 | VERIFIED | `lavkoo:.github/workflows/ci.yml:5-9`، `lavkoo:.github/workflows/ci.yml:205`، `lavkoo:scripts/ci/leak-guard.php:125-156` | استهلاك دقائق CI وضجيج نتائج؛ فشل محتمل إن ورد رقم جوال أو بريد شخصي في تقرير | (توثيق) إضافة `paths-ignore: docs/**` لمهام الاختبار مع إبقاء leak guard على التوثيق. |
| E15-F10 | Leak guard يلتقط الجوال السعودي فقط؛ أرقام الإمارات والكويت وقطر والبحرين وعُمان لا تطابق أي قاعدة؛ نطاق الاستثناءات بالمسارات موصوف في SEC-E15-04 | P2 | VERIFIED | `lavkoo:scripts/ci/leak-guard.php:149-151` | بيانات عملاء خليجيين قد تدخل المستودع بلا إنذار | إضافة قواعد لأرقام الدول الخمس مع اختبارات في `LeakGuardTest`. |
| E15-F11 | أدوات النشر مصمَّمة لخادم واحد («pilot») وأساس واحد: الحزمة تغطي ستة مجلدات فقط وترفض `public/` و`Modules/` وتغيّر الاعتمادات، ولا تشغّل الهجرات؛ اختبار الاستعادة اختياري | P2 | VERIFIED | `lavkoo:scripts/deploy/build-overlay.php:29-30`، `lavkoo:scripts/deploy/build-overlay.php:158-161`، `lavkoo:scripts/deploy/deploy.php:26`، `lavkoo:scripts/deploy/verify-backup.sh:7-16`، `lavkoo:.github/workflows/ci.yml:186-191` | هجرات GCC (وهي جوهر العمل) خارج أي بوابة آلية؛ لا تعريف لبيئة staging إلزامية | (توثيق) بوابة Go/No-Go: نسخة احتياطية + `--restore-test` + بروفة هجرة على نسخة قبل كل مرحلة GCC. |
| E15-F12 | أوامر Console موروثة ذات آثار تشغيلية كبيرة، ولا اختبار لأي منها (انظر SEC-E15-03) | P2 | VERIFIED | بحث في `tests/` عن أسماء الأوامر: صفر تغطية (انظر SEC-E15-03) | خطأ تشغيلي واحد يعطّل المتجر أو يغيّر إعداد الدولة العام؛ الخطر يتضاعف مع تعدّد البيئات. | حذفها أو تقييدها في مرحلة تنفيذ معتمدة. |
| E15-F13 | ثلاثة أصناف اختبار يستشهد بها الكود غير موجودة في `tests/` (`SellerCenterRouteCollisionTest`، `AlertHistoryDiscrepancyTest`، `ChannelOperationsTest`) | P2 | VERIFIED (الغياب) / INFERRED (السبب) | `lavkoo:app/Providers/RouteServiceProvider.php:175`، `lavkoo:app/Services/Monitoring/EventLog.php:16`، `lavkoo:app/Services/SalesChannels/Support/ChannelOperations.php:12` | ضمانات يفترض الكود أنها قائمة (عدم تصادم مسارات Seller Center مع اللوحة القديمة، تصنيف كل عملية قناة) غير محروسة هنا | E21 يحسم وجودها في المستودع القديم؛ إعادة إنشائها ضمن B8/B7. |
| E15-F14 | اختبارات E2E للمتصفح (Playwright) خارج المستودع ومتجاهَلة في git؛ لا `integration_test` في أيٍّ من التطبيقين | P2 | VERIFIED | `lavkoo:.gitignore:38-39`، `lavkoo:package.json:11-17`، `lavkoo:docs/address/test-report.md:14` | مسار checkout للويب بلا أي تحقق قابل للتكرار؛ مسارات `/sa … /om` ستحتاج فحوص توجيه في المتصفح | إدخال حزمة E2E دنيا متتبَّعة لمسار الشراء السعودي قبل إدخال بادئات الدول. |
| E15-F15 | عيب معروف: اعتماد على ترتيب التشغيل في أربعة أصناف dispatch، موثّق داخل حارس CI | P2 | VERIFIED (كتوثيق) | `lavkoo:tests/Unit/Ci/CiSalesChannelSplitCoversEveryTestTest.php:30-34` | إضافة ملفات اختبار GCC إلى المجلد نفسه قد تقلب مهام خضراء إلى حمراء بلا علاقة بالتغيير | إصلاح الاعتماد قبل إضافة اختبارات إلى `tests/Feature/SalesChannels`. |
| E15-F16 | خطاف الدفع الحقيقي `digital_payment_success` يُستبدل ببديل في حزمة MyFatoorah، ويُستدعى الحقيقي في موضعين اختباريين فقط؛ قيد شحن المحفظة موثّق (انظر SEC-E15-05) | INFO | VERIFIED | `lavkoo:tests/Support/myfatoorah_test_hooks.php:3-16`، `lavkoo:tests/Feature/Address/OrderPaymentCallbackTest.php:44`، `lavkoo:tests/Feature/Address/ApiUnmigratedStoreTest.php:95` | نقطة التقاء الدفع بإنشاء الطلب والمحفظة — وهي ما سيُفصل بحسب الكيان — ضعيفة التغطية | تُدرج في B2/B3؛ يتابعها E10 و E04. |
| E15-F17 | أصول ناضجة يجب إعادة استخدامها كما هي: منع الطلبات الشاردة (89 ملفاً)، بدائل المزوّدين، رفض قاعدة لا يحتوي اسمها `test`، مصفوفة MySQL 8 + MariaDB 11.4.2، مهمة الوضع الصارم، اختبارات الهجرات، أنماط «خامل حتى الهجرة» | INFO | VERIFIED | 2.4–2.7 | تحدّد معيار الجودة الذي يجب أن تبلغه اختبارات GCC | اعتمادها نصاً في استراتيجية الاختبار (التقرير 09). |
| E15-F18 | حقائق عن خادم pilot مسجَّلة في الاختبارات: رمز العملة الافتراضية `KSA` لا `SAR`، `exchange_rate` نصّي، اتصال غير صارم | INFO | VERIFIED (كتوثيق) / UNKNOWN (قيمة الإنتاج) | `lavkoo:tests/Support/BuildsPilotServerSchema.php:11-22`، `lavkoo:config/database.php:59` | نموذج العملة لكل متجر لا يستطيع افتراض أن `currencies.code` هو ISO | يتحقق منها E02 على بيانات الإنتاج. |

SEC-E15-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
SEC-E15-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
SEC-E15-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
SEC-E15-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
SEC-E15-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
SEC-E15-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
SEC-E15-07: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 6.1 حزم خط الأساس المطلوبة قبل أي تنفيذ GCC

| # | الحزمة | ما تثبّته (السلوك السعودي الحالي كما هو) | الطبقة |
|---|---|---|---|
| B1 | مخطط الإنتاج البنيوي | بناء قاعدة الاختبار من لقطة structure-only حقيقية؛ تشغيل كل الهجرات فوقها على MySQL 8 و MariaDB 11.4.2 | CI (أصل خاص) |
| B2 | checkout ووضع الطلب | COD/محفظة/دفع يدوي/دفع رقمي عبر API v1 والويب؛ المبالغ (ضريبة مشمولة وغير مشمولة، شحن، قسيمة، خصم)؛ تقسيم الطلب على البائعين و`order_group_id`؛ إنقاص المخزون؛ لقطات `order_details` | Feature + MySQL |
| B3 | المحفظة والولاء | كل أنواع حركات `wallet_transactions` و`loyalty_point_transactions`، تطابق الرصيد مع الدفتر، شحن المحفظة، الاسترداد إلى المحفظة، تحويل النقاط؛ سباقات الخصم/الإضافة | Feature + Concurrency |
| B4 | القسائم | النوع، المالك، الحد الأدنى، السقف، حدّ الاستخدام (مع سباق)، الصلاحية الزمنية، حامل الخصم | Feature + Concurrency |
| B5 | الاسترداد الأساسي | `refund_requests` من الطلب إلى القرار إلى `refund_transactions`؛ الاسترداد الجزئي؛ أثره على المحفظة والمخزون والعمولة | Feature |
| B6 | المال والتقريب | golden tests لدوال التحويل والتنسيق الحالية بـ SAR وخانتين، على المتجر أحادي العملة ومتعدد العملات | Unit |
| B7 | عقود API | golden JSON لاستجابات النقاط التي يستهلكها التطبيقان (config، الثيم، السلة، الطلب، الدفع، تسجيل دخول البائع، الطلبات، seller-center)؛ لقطة سطح متتبَّعة | Feature + Dart |
| B8 | مصفوفة التفويض | لكل مسار إداري: `admin` + موديول؛ لكل مسار بائع (ويب و v3): حارس البائع وعزل `seller_id` | Feature (مسح جدول المسارات) |
| B9 | الثيم (backend) | `ThemeDelivery` و`StorefrontThemeRenderer`: النشر، المراجعة، الـ checksum، مفاتيح الـ cache، السقوط الاحتياطي، توافق إصدار التطبيق | Feature |
| B10 | الإشعارات | ما يُرسَل ولمن (topics، SMS/WhatsApp، email) عند أحداث الطلب، ببدائل كاملة | Feature |
| B11 | تطبيق العميل | CI + اختبارات وضع الطلب ورحلات API وسلك تعثّر الإصدار وفحص الأسرار | Dart + CI |
| B12 | تأكيد CI | تشغيل أخضر موثّق على HEAD في المستودعات الثلاثة، وفحوص مطلوبة للدمج | إعداد GitHub |
| B13 | وسم «Saudi baseline» | مجموعة B2–B10 مع الحزم الحالية كمهمة CI واحدة مسمّاة لا يُسمح بتعديل توقعاتها ضمن PR يحمل عمل GCC | CI |

### 6.2 مدخلات منظَّمة لتقرير استراتيجية الاختبار (التقرير 09)

| المحور | أصول قائمة يُبنى عليها | الفجوة | الحزم المقترحة |
|---|---|---|---|
| عزل الدول الست | نمط مسح جدول المسارات (`AdminRouteAuthenticationTest`)، الفاحص الساكن `PlatformWriteScanner` (`lavkoo:tests/Support/PlatformWriteScanner.php:5-12`) | لا مفهوم سوق في أي اختبار | اختبار مصفوفي 6×6: كل قراءة/كتابة بسياق سوق A لا ترى صفوف سوق B (كتالوج، مخزون، طلبات، سلة، قسائم، إعدادات)؛ فاحص ساكن يمنع استعلاماً على جدول مملوك للسوق بلا نطاق. |
| checkout لكيان واحد | حارس `cart_group` في COD | لا اختبار لتقسيم الطلب الحالي | B2 أولاً، ثم: سلة تحتوي متجرين تُرفض؛ طلب واحد = كيان قانوني واحد = عملة واحدة؛ الطلبات السعودية متعددة البائعين القديمة تبقى مقروءة. |
| فصل المحفظة/الولاء | سباقات MyFatoorah كنموذج «مرة واحدة بالضبط» | صفر تغطية | B3 أولاً، ثم: رصيد لكل (عميل، كيان، عملة)؛ لا تحويل بين الكيانات؛ مطابقة الأرصدة السعودية التاريخية (مجموع الدفتر = الرصيد قبل وبعد الهجرة). |
| نطاقات الأدوار | `ModulePermissionsTest` | مسح جزئي | B8 أولاً، ثم مصفوفة (دور × سوق × إجراء) على الويب و API والتصدير والتقارير. |
| لا FX آلي + عملات 3 خانات | `ProfitWithoutExchangeRateTest`، `OdooMoneyTest` (`decimals: 3`)، `CodAllocatorTest` (المجموع يساوي المستحق بالضبط) | SAR/2 مثبَّتان | B6 أولاً، ثم اختبارات خاصية لكل عملة: التخزين والحساب والعرض وإرسال البوابة بـ 3 خانات لـ KWD/BHD/OMR؛ اختبار يثبت أن سعر كتالوج سوق لا يُشتق من سعر سوق آخر. |
| عزل الثيم | 75 اختبار Dart للمحرّك | صفر backend | B9 أولاً، ثم: نشر مسودة سوق A لا يغيّر الثيم المنشور لسوق B؛ مفاتيح cache وتخزين التطبيق المحلي تحمل السوق؛ قوائم الأنماط تولَّد من مصدر واحد للمستودعين. |
| ثبات مصدر التنفيذ | `OwnershipSnapshotTest`، `RouterTest`، `ProductAssignmentTest` | سياق متجر واحد | الإبقاء حرفياً + حالات: المنتج نفسه في سوقين بمزوّدين مختلفين لا يتقاطعان؛ لا fallback عند نفاد مخزون مزوّد السوق. |
| الاسترداد الجزئي | `RefundAndAdminTest` (`lavkoo:tests/Feature/MyFatoorah/RefundAndAdminTest.php:104`)، مرتجعات القنوات | الدورة الأساسية بلا تغطية | B5 أولاً، ثم: استرداد جزئي بعملة السوق وبدقّتها، إلى بوابة الكيان نفسه أو محفظة الكيان نفسه فقط. |
| الأهلية العابرة للحدود | قيود البلد/ZIP، مطابقة مدن Torod | سعودية فقط | حزمة جديدة: مصفوفة (منتج منظَّم × بلد مصدر × بلد وجهة) مستقلة عن تغطية الناقل؛ لا استثناء آلي. |
| عزل الإشعارات | لا شيء | صفر تغطية | B10 أولاً، ثم: هوية المرسل/القالب/الـ topic بحسب السوق؛ جمهور تسويقي لسوق لا يتلقى حملة سوق آخر. |
| أوضاع الإيقاف | `UnmigratedStoreTest`، `EverythingStartsOffTest` | وضع الصيانة الحالي بلا اختبار | لكل وضع (browse-only، maintenance، redirect): الطلبات السابقة و webhooks الدفع والتنفيذ والاسترداد والدعم تعمل، والشراء الجديد يُمنع. |
| انحدار السعودية | كل الحزم الحالية + مصفوفة المحرّكات | الفجوات في E15-F01 | B13: تشغيل إلزامي على MySQL 8 و MariaDB 11.4.2 بالوضعين الصارم وغير الصارم، مع بروفة هجرة على نسخة من بيانات الإنتاج قبل كل مرحلة. |

## 7. أسئلة مفتوحة وعناصر UNKNOWN

1. هل يعمل CI فعلاً في `fullstackfull/lavkoo` و`fullstackfull/lavkooappseller`، وما نتيجة آخر تشغيل على HEAD؟ يحسمه تبويب Actions.
2. هل توجد حماية فروع وفحوص مطلوبة على `main` في المستودعات الثلاثة؟ يحسمه إعدادات المستودع.
3. قيمة متغيّر المستودع `PILOT_BASELINE`، وهل «pilot» هو خادم الإنتاج نفسه؟ يحسمه إعدادات المستودع وصاحب المشروع.
4. هل توجد الجداول `api_snapshots`، `api_changes`، `api_response_shapes` في قاعدة الإنتاج، وهل التُقطت أي لقطة؟ يحسمه قراءة قاعدة الإنتاج (غير مصرَّح بها في هذه المرحلة).
5. المخطط الحقيقي للجداول الأساسية (دقّة الأعمدة المالية، الفهارس، الجداول خارج الهجرات). يحسمه dump بنيوي من الإنتاج.
6. لماذا تغيب عن `lavkooapp` اختبارات و CI موجودة في `ksaapp98`، وهل كان التطبيق المنشور مبنياً من شجرة تحتويها؟ يحسمه E21 والوصول إلى سجل البناء.
7. هل وُجدت `SellerCenterRouteCollisionTest` و`AlertHistoryDiscrepancyTest` و`ChannelOperationsTest` في `pharmacyksa98`؟ UNKNOWN (missing access: pharmacyksa98).
8. العدد الفعلي للاختبارات وقت التشغيل ونسبة التغطية السطرية: لا تُقاس في CI ولم يُشغَّل شيء هنا.
9. من أي backend التُقطت حمولات عقود تطبيق البائع (العملة `SYP`)، وهل تطابق استجابات الإنتاج السعودي؟ يحسمه مقارنة مع استجابات فعلية.
10. هل استُخدمت `scripts/deploy/*` في نشر إنتاجي، ومتى أُجري آخر `--restore-test`؟ يحسمه سجل التشغيل لدى المالك.

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| الحزمة الافتراضية على SQLite في الذاكرة | `lavkoo:phpunit.xml:26-27` | VERIFIED |
| ملف phpunit ثانٍ يترك القاعدة للبيئة | `lavkoo:phpunit.mysql.xml:5-8` | VERIFIED |
| الصنف الأساسي يصفّر ذاكرة مخططَي Fulfillment و Address لكل اختبار | `lavkoo:tests/TestCase.php:14-20` | VERIFIED |
| الوضع الصارم يُفعَّل لاتصال الاختبار فقط | `lavkoo:tests/CreatesApplication.php:21-25` | VERIFIED |
| الـ dump غير موجود والجداول الأساسية بلا هجرة | `lavkoo:tests/Feature/ExampleTest.php:11-21`، `lavkoo:app/Console/Commands/BootstrapTestSchema.php:11-18` | VERIFIED |
| المخططات الأساسية في الاختبارات مستنتجة | `lavkoo:tests/Support/BuildsFulfillmentSchema.php:10-20` | VERIFIED |
| ثلاث صيغ لـ `orders.order_amount` | `lavkoo:tests/Support/BuildsApiAddressSchema.php:98`، `lavkoo:tests/Support/BuildsFulfillmentSchema.php:169`، `lavkoo:tests/Support/BuildsMyFatoorahSchema.php:94` | VERIFIED |
| جداول مخصّصة خارج الهجرات | `lavkoo:app/Services/Fulfillment/Readiness/Compatibility/RestoreSection.php:22-29` | VERIFIED |
| رفض إسقاط قاعدة لا يحتوي اسمها `test` | `lavkoo:tests/Support/BuildsFulfillmentSchema.php:224-236` | VERIFIED |
| عدّة التزامن: عمليات حقيقية على MySQL/MariaDB | `lavkoo:tests/Support/Concurrency/ConcurrentRunner.php:19-67` | VERIFIED |
| منع الطلبات الخارجية وبدائل المزوّدين | `lavkoo:tests/Feature/Fulfillment/FulfillmentTestCase.php:49-76` | VERIFIED |
| SAR وخانتان في حزمة التنفيذ | `lavkoo:tests/Feature/Fulfillment/FulfillmentTestCase.php:78-88` | VERIFIED |
| رفض عملة غير SAR في MyFatoorah | `lavkoo:tests/Feature/MyFatoorah/PaymentFlowTest.php:184-193` | VERIFIED |
| اختبار مبالغ الطلب الوحيد | `lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:101-146` | VERIFIED |
| اختبارات اللوحة تعطّل middleware التفويض | `lavkoo:tests/Feature/Address/PanelAddressTestCase.php:52-56` | VERIFIED |
| كل مسار إداري يحمل `admin` | `lavkoo:tests/Feature/Security/AdminRouteAuthenticationTest.php:104-119` | VERIFIED |
| محفّزات CI ومهامه | `lavkoo:.github/workflows/ci.yml:5-9`، `lavkoo:.github/workflows/ci.yml:24-357` | VERIFIED |
| حارس تقسيم المصفوفة والعيب المعروف | `lavkoo:tests/Unit/Ci/CiSalesChannelSplitCoversEveryTestTest.php:30-42` | VERIFIED |
| قواعد leak guard | `lavkoo:scripts/ci/leak-guard.php:72-79`، `lavkoo:scripts/ci/leak-guard.php:125-156` | VERIFIED |
| حزمة النشر: ستة مجلدات، لا هجرات | `lavkoo:scripts/deploy/build-overlay.php:29-30`، `lavkoo:scripts/deploy/deploy.php:26` | VERIFIED |
| اللقطة تقارن جانب الطلب فقط | `lavkoo:app/Services/DeveloperPortal/ApiSnapshotService.php:240-266` | VERIFIED |
| `api:snapshot` مجدول أسبوعياً | `lavkoo:app/Console/ScheduleDefinition.php:220` | VERIFIED |
| تسجيل أشكال الاستجابة في مجموعة `api` | `lavkoo:bootstrap/app.php:84-94` | VERIFIED |
| ملف المسارات الموروث غير محمَّل | `lavkoo:app/Providers/RouteServiceProvider.php:48-66` | VERIFIED |
| آخر تشغيل CI موثّق كان على المستودع القديم | `lavkoo:docs/sales-channels/final-report.md:128-131` | VERIFIED (كتوثيق) |
| فحوص المتصفح خارج المستودع | `lavkoo:docs/address/test-report.md:14`، `lavkoo:.gitignore:38-39` | VERIFIED |
| CI تطبيق البائع | `lavkooappseller:.github/workflows/ci.yml:3-7`، `lavkooappseller:.github/workflows/ci.yml:35-42` | VERIFIED |
| اختبار بوابة إصدار تطبيق البائع | `lavkooappseller:test/app_version_gate_test.dart:6-38` | VERIFIED |
| عقود البائع ملتقطة يدوياً وبعملة `SYP` | `lavkooappseller:test/seller_center_contract_test.dart:9-12`، `lavkooappseller:test/control_tower_contract_test.dart:50` | VERIFIED |
| اختبارات الثيم في تطبيق العميل | `lavkooapp:test/dynamic_theme_render_matrix_test.dart:24-30`، `lavkooapp:test/dynamic_theme_schema_test.dart:7-9` | VERIFIED |
| CI واختبارات موجودة في القديم فقط | `ksaapp98:.github/workflows/flutter.yml:32-36`، `ksaapp98:test/release_version_test.dart:6-22` | VERIFIED |
| ثابت إصدار تطبيق العميل بلا اختبار حارس | `lavkooapp:lib/utill/app_constants.dart:29` | VERIFIED |


## 9. سجل التحقق المستقل

> نطاق هذه المرحلة: تحقق مستقل من هذا الملف وتحريره مباشرة، ونقل التفاصيل الأمنية إلى الملحق المقيّد. قراءة فقط؛ لم يُشغَّل أي اختبار أو أداة من المستودعات، ولم تُجرَ أي كتابة أو دفع في المستودعات.

### أ. جدول العدّ

| البند | العدد |
|---|---:|
| الادعاءات المفحوصة (ادعاءات مستقلة، لا استشهادات) | 131 |
| مؤكَّدة دون تغيير | 118 |
| مصحَّحة (نطاق أو رقم أو استشهاد) | 6 |
| مرفوضة كما صيغت (أُعيد تفسيرها) | 3 |
| غير قابلة للتحقق بالقراءة الساكنة | 4 |

فحص الاستشهادات الآلي (`check_citations.py`): 254 استشهاداً ملفياً و5 استشهادات التزام، وصفر عيب قبل التحرير. التحقق الدلالي فحص كل ادعاء على حدة، وأُضيف إليه البحث بأنماط بديلة لكل ادعاء غياب.

### ب. التصحيحات

1. §1: عدد الدوال 4,823 دالة اختبار بعد استثناء 3 دوال مساعدة بالاسم نفسه في `tests/Support`؛ المسح الكامل لـ `tests/` يعطي 4,826.
2. §2.2: عدد ملفات الاختبار التي تشير إلى `App\Services\SalesChannels` هو 134 لا 133.
3. §2.3 (صف وضع الطلب): الاختبار المتخصص بمبالغ وضع الطلب عبر API ليس «الاختبار الوحيد»؛ 22 ملفاً تُسند قيمة `order_amount`.
4. E15-F01 (الدليل): العبارة نفسها عُدّلت للسبب نفسه.
5. §2.6: استدعاء الخطاف الحقيقي `digital_payment_success` في موضعين اختباريين (مساعد واحد في `OrderPaymentCallbackTest` ودالة واحدة في `ApiUnmigratedStoreTest`) لا في خمس دوال فقط.
6. E15-F16: الصياغة نفسها عُدّلت للسبب نفسه.

### ج. الادعاءات المرفوضة

1. §2.5 وE15-F07: ادعاء أن مخزون checkout الأساسي غير مغطّى بأي اختبار تزامن. الاختبار موجود، لكنه يستدعي عملية محاكاة (`stock.checkout`) لا كود checkout الإنتاجي؛ الصياغة عُدّلت لتذكر ذلك.
2. §2.3: ادعاء عام بغياب تغطية الضريبة غير المشمولة والعمولة. التغطية موجودة في حزم أخرى (`OrderScenarioMatrixTest`، `PilotServerMoneyTest`، 29 ملفاً تذكر العمولة)؛ الادعاء صار مقيّداً باختبار وضع الطلب عبر API وحده.
3. §2.3 (صف الاسترداد): ادعاء أن المواضع الأربعة لـ`refund_requests` كلها إنشاء جدول أو تمهيد. الموضع الرابع اسم دالة يختبر استرداد MyFatoorah المعلّق.

### د. الملاحظات المتبقية (أسماء الأقسام فقط)

- §2.7 وE15-F04: حالة CI على HEAD وحماية الفروع غير معروفتين (UNKNOWN)؛ لم تُفحص إعدادات GitHub.
- §2.9: استخدام سكربتات النشر في إنتاج فعلي غير معروف (UNKNOWN).
- §2.10: وجود جداول API والتقاط اللقطات في الإنتاج غير معروف (UNKNOWN).
- §2.12: تشغيل CI لتطبيق البائع على GitHub غير معروف (UNKNOWN).
- §3: قيمة `pilot` وقيم العملات في الإنتاج غير معروفة (UNKNOWN).
- الادعاءات غير القابلة للتحقق (4): سبب غياب الأصناف الثلاثة (INFERRED)، وسبب الفرق بين العدّ الساكن والتعليقات في `ci.yml`، وأصل حمولات عقود تطبيق البائع (تعليق في الملف، لا دليل خارجي)، وسبب غياب اختبارات `lavkooapp` مقارنةً بـ`ksaapp98`.

### هـ. فجوات التغطية

- لم تُقرأ أجسام جميع دوال الاختبار (4,823)؛ قُرئت الأجسام في الملفات الحاملة والمستشهَد بها، والباقي بالاسم ومطابقات البحث.
- لم تُفحص إعدادات GitHub (حماية الفروع، نتائج التشغيل، متغيّرات المستودع).
- المستودع القديم `pharmacyksa98` غير متاح (UNKNOWN: missing access).
- المستودع المحلي `lavkoo` يتقدم الآن على `7f42777` بأربعة التزامات توثيق (`381db71f` رأسه)، ولم تتغيّر فيه أي ملفات خارج `docs/gcc/`؛ التدقيق مرتبط بـ`7f42777`.

### و. الملحق المقيّد

- نُقلت إلى الملحق المقيّد أربع مسائل جديدة (SEC-E15-04 إلى SEC-E15-07)، وأُبقيت في الملف الرئيسي إشارات محايدة لكل منها.
- بقيت المسائل الثلاث السابقة (SEC-E15-01 إلى SEC-E15-03) مسجَّلة في الملحق، وأُزيلت الإشارات المكرّرة فصارت إشارة واحدة لكل مسألة.
- أُعيدت صياغة الأوصاف المتعلقة بالتفويض والاستثناءات والمال بعبارات محايدة، وبقيت الحقائق البنيوية والاستشهادات الصحيحة كما هي.
