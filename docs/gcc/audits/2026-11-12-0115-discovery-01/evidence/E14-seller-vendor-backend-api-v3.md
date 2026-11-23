# E14 — واجهة البائع الخلفية: Seller API v3 ولوحة البائع و Seller Center (Seller/Vendor backend: API v3, vendor panel, Seller Center)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` (الكود المدقَّق؛ النسخة المحلية تحمل فوقه commit توثيقياً واحداً `lavkoo@046d623d` لا يمسّ أي كود)، و`lavkooappseller@8ea00922e44db3d69059d46892f8f4f84349b580` (قراءة نصّية محدودة لثوابت التطبيق وبوابة الإصدار فقط؛ التدقيق الكامل لتطبيق البائع خارج هذا الملحق).

## 1. النطاق وما تم فحصه

**ما فُحص (قراءة ساكنة فقط):**

| المجموعة | الملفات | ملاحظة |
|---|---|---|
| ملفات المسارات | `routes/rest_api/v3/seller.php` (703 أسطر، 236 مساراً)، `routes/vendor/routes.php` (478 سطراً، 221 مساراً)، `routes/seller/routes.php` (122 سطراً، 27 مساراً)، `Modules/AI/routes/api.php` (مجموعة `v3/seller`، 19 مساراً)، ومقطع `seller` من `routes/rest_api/v2/api.php` | قُرئ ملف v3 كاملاً سطراً سطراً |
| Middleware | السبعة المطلوبة + `APILocalizationMiddleware` + تعريف المجموعات والأسماء المستعارة في `bootstrap/app.php` | قُرئت كاملة |
| متحكّمات API v3 | 38 ملفاً تحت `app/Http/Controllers/RestAPI/v3/seller/**` (11,136 سطراً) | قُرئت كاملةً أو شبه كاملة: `LoginController`, `RegisterController`, `SellerController`, `ShopController`, `OrderEditController`, `VendorPaymentInfoController`, `SellerCenterController`, `SellerPayoutController`؛ وقُرئت المقاطع الحاملة من `ProductController` (2,238 سطراً)، `OrderController`, `POSController`, `SellerInventoryController`؛ والباقي (26 ملفاً) مُسح آلياً بنمط البحث عن الاستعلام بالمعرّف ثم رُوجعت المواضع المشتبه بها يدوياً |
| متحكّمات اللوحة | 56 ملفاً تحت `app/Http/Controllers/Vendor/**` و`app/Http/Controllers/Seller/**` | مسح آلي + قراءة `ShopController`, `ProfileController`, `StaffLoginController`, `Auth/RegisterController` ومقاطع من `DashboardController`, `Order/OrderController`, `RefundController` |
| النماذج | `Seller`, `Shop`, `SellerStaff`, `SellerRole`, `SellerApiKey`, `SellerWallet`, `VendorLedgerEntry` كاملة؛ وحقول `SellerWebhook`, `SellerBulkJob`, `SellerAutomationRule`, `SellerInsight`, `SellerPricingPolicy`, `SellerVerificationDocument`, `BrandClaim` | |
| الخدمات | `app/Services/Marketplace/` (38 ملفاً + مجلد `Bulk`): قُرئت كاملةً `SellerPermissionService`, `SellerPrincipal`, `SellerApiKeyService`, `VendorLedger`, `InventoryService`؛ ومقاطع من `PayoutService`, `SellerWebhookDispatcher`, `SellerVerificationService`؛ `SellerIntelligence/SellerNotifier` كاملة؛ `SellerInvoiceService` كاملة؛ `VendorService` و`ShopService` (المقاطع الحاملة)؛ جرد أسماء فقط لـ`SellerCenter/**`, `SellerAutomation/**`, `SellerIntelligence/Producers/**`, `DeveloperPortal/**` | |
| الجدولة والوظائف | `app/Console/ScheduleDefinition.php` (المقطع الخاص بالبائع)، تواقيع الأوامر الستة + `RunVendorSettlements`، ترويسة `RunSellerBulkJob` و`DeliverSellerWebhook` | |
| إعداد التطبيق | `app/Http/Controllers/RestAPI/v1/ConfigController.php` (كامل)، تخزين `seller_app_version_control`، `MaintenanceModeTrait` | |
| الترحيلات | الترحيلات التي تمسّ `sellers`/`shops`/`seller_wallets`/`withdraw*` (42 ملفاً بمطابقة المحتوى)، وقُرئت خمسة منها | |

**أنماط البحث المسجَّلة:** `seller_can:|seller_owner|seller_api_auth`؛ `::find\(|->find\(|findOrFail|where\(\['id'|where\('id'|whereKey\(` في متحكّمات البائع؛ `storefront_id|store_id|legal_entity|market_id|country_id|market_code|storefront` في كل مسارات ومتحكّمات وخدمات ونماذج البائع؛ `app_minimum_version|seller_app|software_version|version_control|for_android|for_ios|force_update` في `app routes config database Modules resources/views`؛ `maintenance_mode_start|lavkoo_seller|sixvalley|'topic'` في `app routes config Modules`؛ `Schema::create('seller…|vendor…|shop…|brand_claim…|withdraw…'` وأسماء 20 جدولاً في `database` و`Modules`؛ `Convert::usd|currency_to_usd|currencyConverter|Convert::default` في متحكّمات v3.

**ما لم يُفحص:** منطق كل منتِج insight وكل trigger/action في الأتمتة سطراً سطراً؛ قوالب Blade للوحة البائع؛ `CommissionEngine` و`SettlementEngine` و`ReconciliationService` (تخصّ ملحق المدفوعات والمحاسبة)؛ تطبيق البائع Flutter عدا أربعة ملفات؛ متحكّمات v2 دالةً دالة (أُحصيت مسارات مجموعة `seller`: 55)، ومجموعة `delivery-man` في v2 (40 مساراً)، وتقارير ضريبة البائع في `Modules/TaxModule` (`Api/v3/VendorTaxReportController`)، وكلها خارج هذا الملف؛ القيم الفعلية في قاعدة بيانات الإنتاج (كلها `UNKNOWN`)؛ المستودع القديم `pharmacyksa98` (`UNKNOWN (missing access: pharmacyksa98)`).

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 ثلاثة أسطح للبائع فوق كيان واحد

| السطح | ملف المسارات | التحميل | المصادقة | عدد المسارات |
|---|---|---|---|---|
| Seller API v3 (تطبيق البائع) | `routes/rest_api/v3/seller.php` | `lavkoo:app/Providers/RouteServiceProvider.php:113-119` بمجموعة `api` | Bearer token مخصَّص عبر `seller_api_auth` | 236 |
| Seller API v2 «القديم» | `lavkoo:routes/rest_api/v2/api.php:27-130`، `lavkoo:app/Providers/RouteServiceProvider.php:110` | مجموعة `api` | المصادقة داخل المتحكّمات لا على المسار؛ خارج سلسلة `SellerPrincipal` | 55 (مجموعة `seller`؛ مجموعة `delivery-man` من 40 مساراً خارج هذا الملحق) |
| لوحة البائع الكلاسيكية | `routes/vendor/routes.php` | `lavkoo:app/Providers/RouteServiceProvider.php:209-214` بمجموعة `web` | جلسة guard `seller` | 221 |
| Seller Center (الشاشات الجديدة) | `routes/seller/routes.php` | `lavkoo:app/Providers/RouteServiceProvider.php:180-185`، بعد اللوحة الكلاسيكية وعلى نفس البادئة `/vendor` | جلسة + `SellerPrincipal` | 27 |
| مسارات AI للبائع | `lavkoo:Modules/AI/routes/api.php:19-46` | مزوّد مسارات الـ module | `seller_api_auth` | 19 |

مجموعة `api` تطبّق `throttle:3000,1` وأربعة middleware للقياس والتسجيل ولا تطبّق جلسة (`lavkoo:bootstrap/app.php:84-94`). تطبيق البائع يعتمد كذلك على خمس نقاط من API v1 المشتركة مع تطبيق العميل: `config`, `app-health`, `attributes`, `business-pages`, `vat-tax` وخرائط `mapapi` (`lavkooappseller:lib/utill/app_constants.dart:13-16`، `lavkooappseller:lib/utill/app_constants.dart:41`، `lavkooappseller:lib/utill/app_constants.dart:200`، `lavkooappseller:lib/utill/app_constants.dart:217`). — `VERIFIED`.

### 2.2 مصادقة API v3 (السؤال 1)

**نوع الـ token وتخزينه.** تسجيل الدخول `POST /api/v3/seller/auth/login` يولّد سلسلة عشوائية من 50 محرفاً بـ`Str::random(50)` ويكتبها في العمود `sellers.auth_token` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:98-100`). لا Passport ولا Sanctum ولا JWT في هذا المسار؛ الـ guard `seller` في `config/auth.php` هو guard جلسة يُستخدم هنا فقط للتحقق من كلمة المرور (`lavkoo:config/auth.php:54-57`). العمود واحد لكل بائع، فكل دخول جديد يستبدل الـ token السابق (جلسة API واحدة لصاحب المتجر). — `VERIFIED`.

**دورة حياة token الدخول.** يُخزَّن token الدخول في عمود واحد لكل حساب (`sellers.auth_token`، ولموظف البائع `seller_staff.auth_token`) فيستبدل كل دخول جديد القيمة السابقة؛ ويولّد تغيير كلمة المرور قيمة جديدة ويعيدها في الردّ (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:408-412`). المفاتيح المُصدَرة وحدها تُفحص بانتهاء وإلغاء (`lavkoo:app/Models/SellerApiKey.php:36-43`). تفاصيل هذا البند وتقييمه في SEC-E14-11. — `VERIFIED` للكود.

**ثلاثة أنواع من حامل الاعتماد**، يحلّها `SellerApiAuthMiddleware` إلى كائن واحد `SellerPrincipal` (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:41-93`):

| الحامل | مصدر الاعتماد | الحلّ | الصلاحيات |
|---|---|---|---|
| صاحب المتجر (owner) | `sellers.auth_token` | `Seller::approved()->where('auth_token', …)` — `lavkoo:app/Services/Marketplace/SellerPermissionService.php:98-103` | كل شيء؛ `can()` تعيد `true` دائماً — `lavkoo:app/Services/Marketplace/SellerPrincipal.php:93-96` |
| موظف البائع (staff) | `seller_staff.auth_token`، يصدر عند دخول الموظف ببريده وكلمة مروره — `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:28-54` | يشترط `status = active` وأن يكون البائع المشغِّل `approved` — `lavkoo:app/Services/Marketplace/SellerPermissionService.php:105-119` | صلاحيات دوره كما هي لحظة الطلب (تُقرأ في كل طلب لا عند الدخول) — `lavkoo:app/Services/Marketplace/SellerPermissionService.php:200-209` |
| مفتاح API مُصدَر (integration) | نصّ يبدأ ببادئة ثابتة؛ يُخزَّن `prefix` + `token_hash` فقط ويُعرض النص مرة واحدة — `lavkoo:app/Services/Marketplace/SellerApiKeyService.php:46-69` | بحث بالـ prefix ثم `Hash::check` ثم فحص أن البائع `approved` — `lavkoo:app/Services/Marketplace/SellerApiKeyService.php:77-99` | نطاقاته (scopes) من نفس كتالوج الصلاحيات؛ ليس owner أبداً — `lavkoo:app/Services/Marketplace/SellerPrincipal.php:71-74` |

حالة البائع تُفحص في **كل طلب** لا عند الدخول فقط؛ بائع يُعاد إلى `pending` أو يُعلَّق يفقد الوصول في الطلب التالي (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:45-57`). يوضع البائع (المتجر) في `$request['seller']` والـ principal في attributes الطلب (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:60-64`)، وهذا مصدر هوية البائع في كل متحكّمات v3.

**سلسلة الـ middleware لمسار v3 نموذجي:** `api` (throttle عام) ← `api_lang` (`lavkoo:routes/rest_api/v3/seller.php:49`) ← `seller_api_auth` (`lavkoo:routes/rest_api/v3/seller.php:72`) ← `seller_can:<keys>` أو `seller_owner`. مسارات الدخول واستعادة كلمة المرور تحمل `throttle:auth` (`lavkoo:routes/rest_api/v3/seller.php:51`) ويُضبط حدّه من إعداد سياسة عام (`lavkoo:app/Services/Platform/PolicyRegistry.php:265-270`، `lavkoo:app/Providers/RouteServiceProvider.php:250-252`)؛ مسار التسجيل العام يُعالَج في SEC-E14-09. الصنف `SellerApiMiddleware` تمريرٌ فارغ وغير مسجَّل بأي اسم مستعار ولا مستخدَم (بحث `SellerApiMiddleware` في `app bootstrap routes config`: الملف نفسه فقط) — `lavkoo:app/Http/Middleware/SellerApiMiddleware.php:17-20`.

**نموذج الصلاحيات.** كتالوج ثابت من 11 مفتاحاً في 7 مجموعات (`lavkoo:app/Services/Marketplace/SellerPermissionService.php:30-41`): `products.view`, `products.manage`, `orders.view`, `orders.manage`, `inventory.manage`, `promotions.manage`, `finance.view`, `payouts.request`, `reviews.view`, `shop_settings.manage`, `staff.manage`. الدور `seller_roles` يحمل `permissions` كمصفوفة JSON مملوكة لبائع واحد (`lavkoo:app/Models/SellerRole.php:14-26`). `seller_can:a,b` يعني «أيّ واحدة تكفي» (`lavkoo:app/Http/Middleware/EnsureSellerPermission.php:42-46`). `seller_owner` يرفض الموظف والمفتاح معاً مهما كانت صلاحياتهما (`lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:34-38`). المفتاح المُصدَر يُرفض على أي مسار لا يعلن `seller_can:` (فشل مغلق) — `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:66-79` و`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:102-117`. استخدام المفاتيح في ملف v3: 53 إعلان `seller_can:` و3 مجموعات `seller_owner`؛ تكرار المفاتيح: `orders.manage` 16، `products.manage` 16، `products.view` 13، `orders.view` 10، `finance.view` 9، `inventory.manage` 6، `shop_settings.manage` 5، `payouts.request` 3، `promotions.manage` 2، `reviews.view` 1، `staff.manage` 1. — `VERIFIED`.

**على لوحة الويب:** دخول الموظف يسجّله كبائعه الأم عبر `loginUsingId` ويضع `seller_staff_id` في الجلسة (`lavkoo:app/Http/Controllers/Vendor/Auth/StaffLoginController.php:61-70`)؛ `SellerMiddleware` يشترط `approved` (`lavkoo:app/Http/Middleware/SellerMiddleware.php:21-26`)؛ `SellerStaffAccessMiddleware` خريطة رفض-افتراضي مبنية على المقطع الثاني من الـ URL، مع أولوية مسارات تحريك المال (`lavkoo:app/Http/Middleware/SellerStaffAccessMiddleware.php:65-134`، `lavkoo:app/Http/Middleware/SellerStaffAccessMiddleware.php:141-150`)؛ و`SellerCenterContext` يبني نفس `SellerPrincipal` من الجلسة ليخدم `seller_can` على الشاشات الجديدة (`lavkoo:app/Http/Middleware/SellerCenterContext.php:30-52`، `lavkoo:app/Http/Middleware/SellerCenterContext.php:61-73`). النتيجة: نظام صلاحيات واحد (المفاتيح نفسها) لكن بآليتين للتطبيق على الويب: خريطة URL للّوحة الكلاسيكية وإعلان على المسار للشاشات الجديدة.

### 2.3 جدول مجموعات مسارات v3 (السؤال 2)

كل المسارات تحت `/api/v3/seller`؛ العمود «الأسطر» يشير إلى `lavkoo:routes/rest_api/v3/seller.php`. المجموع 236 مساراً (مطابق لعدّ `Route::<verb>(` في الملف).

| # | المجموعة (prefix) | الغرض | المتحكّم | الصلاحية | مسارات | الأسطر |
|---|---|---|---|---|---|---|
| 1 | `auth/*` | دخول، استعادة كلمة المرور، OTP، Firebase | `auth\LoginController`, `auth\ForgotPasswordController` | عام + `throttle:auth` | 7 | 51-64 |
| 2 | `registration` | طلب فتح متجر | `auth\RegisterController` | عام | 1 | 66-70 |
| 3 | جذر (جلسة الحامل) | `language-change`, `seller-info`, `cm-firebase-token`, `shop-info`, `seller-delivery-man` | `SellerController` | `seller_api_auth` فقط | 5 | 77-81 |
| 4 | جذر (الحساب) | `account-delete`، `seller-update` | `SellerController` | `seller_owner` | 3 | 87-94 |
| 5 | جذر (إعداد المتجر) | `shop-update`, `update-setup-guide-app` | `SellerController` | `shop_settings.manage` | 2 | 97-100 |
| 6 | جذر (التقييمات) | قائمة، حالة، ردّ | `SellerController` | `reviews.view` | 3 | 103-107 |
| 7 | جذر (المالية — قراءة) | إحصاءات الأرباح والطلبات، معاملات السحب، طرق السحب | `SellerController` | `finance.view` | 6 | 110-117 |
| 8 | جذر (السحب القديم) | `balance-withdraw`, `balance-withdraw-update`, `close-withdraw-request` | `SellerController` | `payouts.request` | 3 | 120-124 |
| 9 | جذر (الإغلاق) | `vacation-add`, `temporary-close` | `ShopController` | `shop_settings.manage` | 2 | 128-133 |
| 10 | `brands` | قائمة العلامات لنموذج المنتج | `BrandController` | `products.view` أو `products.manage` | 1 | 136-142 |
| 11 | جذر (مراجع) | `top-delivery-man`, `categories` | `ProductController` | `products.*` أو `orders.*` | 2 | 145-148 |
| 12 | `products` (قراءة) | قائمة، تفاصيل، نفاد المخزون، صور، الأكثر مبيعاً، طلبات إعادة التوفير… | `ProductController` | `products.view` أو `products.manage` | 14 | 154-169 |
| 13 | `products` (كتابة) | إضافة، تحديث، حذف، حالة، رفع صور وملفات رقمية | `ProductController` | `products.manage` | 10 | 171-182 |
| 14 | `products` (مخزون) | `quantity-update`, `restock-request-stock-update` | `ProductController` | `inventory.manage` أو `products.manage` | 2 | 186-189 |
| 15 | `orders` (قراءة) | قائمة، فاتورة، `breakdown`، تفاصيل | `OrderController` | `orders.view` أو `orders.manage` | 4 | 195-202 |
| 16 | `orders` (كتابة) | حالة الطلب، إسناد مندوب، توصيل طرف ثالث، حالة الدفع، تعديل العنوان | `OrderController` | `orders.manage` | 8 | 204-213 |
| 17 | `orders` (تعديل الطلب) | تحقق وتنفيذ تعديل الطلب، تحويل الفرق إلى COD | `OrderEditController` | `orders.manage` | 3 | 217-223 |
| 18 | `clearance-sale` | منتجات وإعداد تصفية المخزون | `ClearanceSaleController` | `promotions.manage` | 9 | 226-242 |
| 19 | `refund` | قائمة وتفاصيل وقرار الاسترجاع | `RefundController` | `orders.manage` | 4 | 244-255 |
| 20 | `coupon` | CRUD القسائم، فحص قسيمة، قائمة عملاء | `CouponController` | `promotions.manage` | 7 | 257-271 |
| 21 | `shipping` | نوع الشحن وتكلفة الفئات | `shippingController` | `shop_settings.manage` | 4 | 273-284 |
| 22 | `shipping-method` | CRUD طرق الشحن | `ShippingMethodController` | `shop_settings.manage` | 6 | 286-299 |
| 23 | `messages` | محادثات العميل والمندوب | `ChatController` | `orders.view` أو `orders.manage` | 5 | 301-313 |
| 24 | `pos` | نقطة البيع: فئات، عملاء، منتجات، طلب، فاتورة، ضريبة | `POSController`, `POSCartController` | `orders.manage` | 8 | 315-333 |
| 25 | `delivery-man` | المندوبون، تحصيل النقد، سحوباتهم، جهات الطوارئ | `DeliveryManController`, `DeliveryManCashCollectController`, `DeliverymanWithdrawController`, `EmergencyContactController` | `orders.manage` | 20 | 335-375 |
| 26 | `notification` | إشعارات المتجر | `ShopController` | `orders.view` أو `products.view` أو `finance.view` | 2 | 377-386 |
| 27 | `payment-information` | حسابات استلام المدفوعات | `VendorPaymentInfoController` | قراءة `finance.view`؛ كتابة `seller_owner` | 7 | 390-405 |
| 28 | `seller-center` (overview/scorecard) | وضع المتجر وبطاقة الأداء | `SellerCenterController` | أيّ من 5 مفاتيح قراءة | 2 | 415-420 |
| 29 | `seller-center/verification` | وثائق KYC | `SellerVerificationController` | `seller_owner` | 3 | 422-433 |
| 30 | `seller-center/analytics` | تحليلات وأنشطة | `SellerAnalyticsController` | `finance.view` أو `orders.view` | 2 | 435-442 |
| 31 | `seller-center/control-tower` | برج المراقبة والإحاطة اليومية وحالة المشكلة | `SellerControlTowerController` | `orders.view`؛ الكتابة `orders.manage` | 3 | 446-459 |
| 32 | `seller-center/brand-claims` | مطالبات العلامات ووثائقها | `SellerBrandClaimController` | قراءة `products.*`؛ كتابة `products.manage` | 8 | 464-482 |
| 33 | `seller-center/finance` | مطابقة، محاكي الرسوم، سجل تغيّر الأسعار، سياسة التسعير | `SellerFinanceControlController` | `finance.view` / `products.*` / `products.manage` | 5 | 488-506 |
| 34 | `seller-center/integrations` | مفاتيح API و webhooks وسجل التسليم | `SellerIntegrationController` | `shop_settings.manage` | 11 | 511-531 |
| 35 | `seller-center/security` | الأدوار والموظفون وسجل التدقيق | `SellerSecurityController` | `staff.manage` | 12 | 536-558 |
| 36 | `seller-center/automation` | قواعد الأتمتة وسجلها والتراجع | `SellerAutomationController` | قراءة `products.*`؛ كتابة `products.manage` | 11 | 564-583 |
| 37 | `seller-center/action-center` | قائمة ما ينتظر البائع | `SellerActionCenterController` | `orders.view` أو `products.view` | 2 | 587-594 |
| 38 | `seller-center/reports` | تقارير الطلبات والمنتجات والمخزون وتصديرها | `SellerReportController` | `orders.*`/`finance.view` أو `products.*`/`inventory.manage` | 7 | 598-615 |
| 39 | `seller-center/payouts` | المدفوعات على دفتر الأستاذ | `SellerPayoutController` | `finance.view` / `payouts.request` | 3 | 620-626 |
| 40 | `seller-center/statement` | كشف الحساب وتصديره | `SellerStatementController` | `finance.view` | 2 | 630-635 |
| 41 | `seller-center/returns` | شحنات الإرجاع | `SellerReturnController` | `orders.view`؛ الكتابة `orders.manage` | 5 | 639-649 |
| 42 | `seller-center/inventory` | نظرة المخزون، الحركات، المستودعات، الدفعات، التسوية | `SellerInventoryController` | `inventory.manage` أو `products.view`؛ التسوية `inventory.manage` | 5 | 654-665 |
| 43 | `seller-center/bulk-jobs` | مهام السعر والمخزون بالجملة | `SellerBulkJobController` | قراءة ثلاثة مفاتيح؛ السعر `products.manage`؛ المخزون `inventory.manage` | 5 | 670-680 |
| 44 | `products/{seller_id}/…` | كتالوج البائع الكامل لشاشتي المنتجات وتعديل الطلب | `ProductController` | `products.*` أو `orders.manage` + فحص ملكية داخل المتحكّم | 2 | 692-701 |

مسارات AI الـ19 (`product/*` و`auction/product/*`) معرّفة في `lavkoo:Modules/AI/routes/api.php:19-46`؛ توزيعها على نطاقات الصلاحية وتقييمه في SEC-E14-12.

### 2.4 عزل بيانات البائع (السؤال 3)

**كيف يُشتق معرّف البائع.** في v3: من الـ token حصراً — `$request->seller` يضعه الـ middleware، ولا يقبل أي مسار معرّف بائع من العميل عدا مسارَي `products/{seller_id}/…` اللذين يطابقان المعرّف مع صاحب الـ token ويرفضان الاختلاف (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:143-151`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:396-404`). في اللوحة: من `auth('seller')->id()` (214 موضعاً تحت `app/Http/Controllers/Vendor`).

**مفاتيح الملكية ليست موحَّدة.** العزل يُكتب بأربع صيغ مختلفة بحسب الجدول، وكلها يدوية داخل المتحكّم (لا Global Scope ولا Policy):

| الكيان | شرط الملكية | مثال |
|---|---|---|
| المنتج | `products.added_by = 'seller'` و`products.user_id = <seller>` (لا عمود `seller_id`) | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:88` |
| الطلب | `orders.seller_id = <seller>` و`orders.seller_is = 'seller'` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:65-75` |
| طرق الشحن | `shipping_methods.creator_type = 'seller'` و`creator_id` | مسح: `ShippingMethodController` 6 مواضع |
| التصفية | `added_by/setup_by = 'vendor'` و`user_id` | مسح: `ClearanceSaleController` |
| القسائم | `coupons.seller_id IN (<seller>, 0)` | مسح: `CouponController` 4 مواضع |
| جداول Seller Center | `seller_id = <seller>` دائماً | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php:269-275` |

**الاتساق.** المتحكّمات الأحدث (مجموعات 28–43 في الجدول) متّسقة: المسح الآلي لـ13 متحكّماً أظهر 25 موضع استعلام بالمعرّف، كلها مقيَّدة بـ`seller_id` من الـ token أو تمرّ عبر دالة `find()` خاصة مقيَّدة به؛ والخدمات تستقبل `sellerId` صراحة (مثال: `lavkoo:app/Services/Marketplace/InventoryService.php:148-162`، `lavkoo:app/Services/SellerInvoiceService.php:33-38`). أما المتحكّمات الكلاسيكية الموروثة من المنتج الأساس (مجموعات 3–27) ونظيراتها في لوحة الويب فعزلها **غير متّسق**: بعض الدوال أُحكمت بتعليقات تشرح السبب (مثال: `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1767-1776`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:372-375`) ودوال مجاورة لها لم تُحكَم. التفاصيل في الملحق المقيّد:

- SEC-E14-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-07: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-08: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-09: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-10: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-11: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-12: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-13: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E14-14: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

**دلالة معمارية لـGCC (غير أمنية):** لا توجد طبقة عزل مركزية يمكن أن يُضاف إليها بُعد المتجر/الدولة مرة واحدة؛ أي نطاق جديد (storefront) سيُكتب يدوياً في كل استعلام، وهو النمط نفسه الذي أنتج عدم الاتساق الحالي. كما أن قوائم العملاء التي يستخدمها البائع في القسائم ونقطة البيع والمحادثات تُقرأ من الجدول العام `users` بلا أي نطاق متجر.

### 2.5 نموذج البائع والمتجر (السؤال 4)

**`sellers`** — نموذج `Authenticatable` مع `SoftDeletes` (`lavkoo:app/Models/Seller.php:40-42`). الحقول القابلة للتعبئة (`lavkoo:app/Models/Seller.php:44-68`): الهوية (`f_name`, `l_name`, `country_code`, `phone`, `email`, `image`, `password`)، الحالة `status`، **البنك** (`bank_name`, `branch`, `account_no`, `holder_name`)، `auth_token`، **العمولة** `sales_commission_percentage`، **الضريبة** `gst`، الإشعارات (`cm_firebase_token`, `cm_firebase_token_web`)، `pos_status`، `minimum_order_amount`، `stock_limit`، `free_delivery_status`، `free_delivery_over_amount`، `app_language`. لا عمود دولة ولا عملة ولا كيان قانوني؛ `country_code` مرافق لحقل الهاتف (يُطلب مع `company_phone` في `lavkoo:app/Http/Requests/Vendor/ShopRequest.php:28-29`) — `INFERRED` من سياق الاستخدام. الأعمدة المالية `decimal(21,12)` (`lavkoo:database/migrations/2026_01_22_164738_update_sellers_money_columns.php:14-18`).

**`shops`** — الحقول (`lavkoo:app/Models/Shop.php:52-79`): `seller_id`, `author_type` (`vendor`/`admin`)، `name`, `slug`, `address` (نص حر)، `contact`، الصور، الإجازة (`vacation_status`, `vacation_duration_type`, `vacation_start_date`, `vacation_end_date`, `vacation_note`)، `temporary_close`، `setup_guide`, `setup_guide_app`، و**الهوية الضريبية**: `tax_identification_number`, `tin_expire_date`, `tin_certificate` (أُضيفت في `lavkoo:database/migrations/2025_06_12_202801_add_tin_fields_to_shops_table.php:14-19`). لا حقل سجل تجاري مستقل ولا رقم VAT بمسمّاه ولا دولة ولا عملة.

**متجر واحد لكل بائع — عُرفاً لا قيداً.** النموذج يعرّف `shop()` كـ`hasOne` و`shops()` كـ`hasMany` معاً (`lavkoo:app/Models/Seller.php:96-104`)؛ وكل v3 يقرأ `Shop::where('seller_id', …)->first()` (مثال: `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:63`)؛ والفهرس الوحيد المُضاف على `seller_id` مركّب غير فريد `(seller_id, author_type)` (`lavkoo:database/migrations/2026_02_25_163731_add_indexes_to_shops_table.php:22-30`). وجود قيد فريد في مخطط الإنتاج `UNKNOWN` (جدولا `sellers` و`shops` بلا ترحيل إنشاء في المستودع؛ بحث `Schema::create('seller…|shop…'` يعيد `shop_followers` فقط).

**التسجيل والاعتماد.** `POST registration` ينشئ في معاملة واحدة صف `sellers` ثم صف `shops` (بـ`slug` مولَّد وحقول TIN) ثم صف `seller_wallets` بأصفار (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/RegisterController.php:34-40`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/RegisterController.php:44-63`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/RegisterController.php:65-75`)؛ مسار الويب يستخدم `VendorService`/`ShopService` للغرض نفسه (`lavkoo:app/Http/Controllers/Vendor/Auth/RegisterController.php:86-88`). الدخول يرفض غير `approved` ويعيد `loginStatus: pending` للحساب قيد المراجعة (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:98-129`). فوق ذلك طبقة KYC اختيارية: وثائق `seller_verification_documents` والمطلوب افتراضياً `identity` و`business_license`، قابلة للتغيير بإعداد عام واحد `kyc_required_documents`، ولا تحجب المدفوعات إلا بتفعيل `require_kyc_for_payout` (`lavkoo:app/Services/Marketplace/SellerVerificationService.php:28`، `lavkoo:app/Services/Marketplace/SellerVerificationService.php:47-57`، `lavkoo:app/Services/Marketplace/SellerVerificationService.php:154`).

**الإجازة والإغلاق المؤقت.** حقلان على `shops` يكتبهما البائع من v3 (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShopController.php:16-28`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShopController.php:30-40`)؛ الإغلاق المؤقت يستدعي `Cache::clear()` أي يمسح **كامل** ذاكرة التخزين المؤقت للمنصة (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShopController.php:38`).

**البائع الداخلي (in-house / admin).** ليس صفاً في `sellers`: هو صف واحد في `shops` بـ`seller_id = 0` و`author_type = 'admin'` يُنشأ تلقائياً من إعدادات الشركة العامة (`lavkoo:app/Utils/Helpers.php:730-733`، `lavkoo:app/Utils/Helpers.php:765-767`) ويُخزَّن مؤقتاً بمفتاح واحد (`lavkoo:app/Utils/settings.php:37-50`)؛ منتجاته `added_by = 'admin'` وطلباته `seller_is = 'admin'`. **v3 لا يخدمه:** المصادقة تتطلّب صفاً في `sellers` بحالة `approved` (`lavkoo:app/Services/Marketplace/SellerPermissionService.php:100`)، وكل استعلامات v3 تثبّت `seller_is = 'seller'` و`added_by = 'seller'` (موضع واحد فقط في v3 يتفرّع على `id == 0`: `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:211`)؛ ودفتر الأستاذ يرفض `sellerId` الصفري (`lavkoo:app/Services/Marketplace/VendorLedger.php:49-51`). أي أنّ تطبيق البائع لا يستطيع إدارة بضاعة المنصة نفسها. — `VERIFIED`.

### 2.6 القدرات المعروضة للبائع (السؤال 5)

| القدرة | كيف تعمل اليوم | الدليل |
|---|---|---|
| منتجات CRUD | الإنشاء يثبّت `added_by = 'seller'` و`user_id`؛ الأسعار والخصم الثابت وتكلفة الشحن تُحوَّل بـ`Convert::usd()` قبل الحفظ؛ `code` فريد على مستوى جدول `products` كله؛ الحالة الابتدائية `status = 0` و`request_status` تابعة للإعداد العام `new_product_approval`؛ حارس مطالبة العلامة عند تفعيله | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:782-810`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1037-1059`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:2220-2237` |
| المخزون | مساران: (أ) كلاسيكي يكتب `products.current_stock` و`variation` مباشرة؛ (ب) `inventory/products/{id}/adjust` عبر `InventoryService::adjust()` بقفل صف وسجل حركة `stock_movements` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1767-1788`، `lavkoo:app/Services/Marketplace/InventoryService.php:36-85` |
| التداخل مع مزوّدي التنفيذ | **لا تداخل بالتصميم:** إسناد مزوّد (Torod/M5AZN) مسموح فقط لمنتج `added_by = 'admin'` مادي؛ منتج البائع يُرفض بـ`fulfillment_vendor_product_not_allowed`. و`adjust()` يرفض تعديل منتج يملك مزوّدٌ مخزونه. الأثر الوحيد للتنفيذ على البائع هو حارس تغيير حالة الطلب | `lavkoo:app/Services/Fulfillment/ProductProviderAssignmentService.php:196`، `lavkoo:app/Services/Fulfillment/ProviderMappingService.php:49`، `lavkoo:app/Services/Fulfillment/Readiness/Compatibility/CompatibilitySpec.php:107`، `lavkoo:app/Services/Marketplace/InventoryService.php:50-53`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:406-408` |
| الطلبات | قائمة وتفاصيل وفاتورة و`breakdown` (رسوم + خط زمني + SLA)؛ تغيير الحالة يشغّل المخزون والمحفظة ونقاط الولاء وعمولة الإحالة ومحفظة المندوب من داخل المتحكّم | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:169-194`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:369-490` |
| الشحن والمندوبون | طرق شحن ونوع شحن وتكلفة فئات لكل بائع؛ مندوبون مملوكون للبائع (`delivery_men.seller_id`)؛ توصيل طرف ثالث نصّي | مجموعات 21، 22، 25 |
| POS | ينشئ طلباً `order_type = 'POS'` بـ`seller_id` البائع؛ يدعم الدفع من محفظة العميل العامة `users.wallet_balance` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:320-323`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:390-399` |
| الاسترجاع والإرجاع | قرار الاسترجاع (كلاسيكي) + شحنات الإرجاع `ReturnShipment` (Seller Center) | مجموعات 19، 41 |
| المالية — نظامان متوازيان | (أ) القديم: `seller_wallets` (صف واحد لكل بائع، بلا عملة) + `withdraw_requests`؛ (ب) الجديد: `vendor_ledger_entries` غير قابل للتعديل برصيد جارٍ + `vendor_payout_requests` مع فترة تبريد عند تغيير البنك وبوابة KYC وموافقة مزدوجة للمبالغ الكبيرة. كلاهما معروض في v3 | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:426-473`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:44-71`، `lavkoo:app/Services/Marketplace/VendorLedger.php:36-97` |
| كشف الحساب والتقارير | `statement` + تقارير طلبات/منتجات/مخزون مع تصدير | مجموعات 38، 40 |
| القسائم والتصفية | قسائم بـ`seller_id` (والقيمة `0` = كل البائعين)، تصفية مخزون لكل بائع | مجموعات 18، 20 |
| المحادثات | مع العميل والمندوب، بمفتاح `seller_id` و`shop_id` | مجموعة 23 |
| مطالبات العلامات | `brand_claims` بأنواع `owner`/`authorized_reseller`/`distributor` ودورة حالات كاملة | `lavkoo:app/Models/BrandClaim.php:18-36` |
| المهام بالجملة | `seller_bulk_jobs` (سعر/مخزون) عبر الطابور، مع أمر كل دقيقة يشغّل ما لم يلتقطه worker | `lavkoo:app/Console/ScheduleDefinition.php:76` |
| الأتمتة | قواعد: 4 triggers (`LowStock`, `OutOfStock`, `Restocked`, `StaleStock`) و3 actions (`HideListing`, `PublishListing`, `SetDiscount`)، كل 15 دقيقة | `lavkoo:app/Console/ScheduleDefinition.php:64` |
| الذكاء التشغيلي | 12 منتِج insight → Action Center / Control Tower / إحاطة يومية؛ تحديث كل ساعة وتصعيد كل 4 ساعات | `lavkoo:app/Console/ScheduleDefinition.php:48`، `lavkoo:app/Console/ScheduleDefinition.php:58` |
| AI | 19 مسار توليد محتوى منتج/مزاد من `Modules/AI` | `lavkoo:Modules/AI/routes/api.php:19-46` |
| مفاتيح API و Webhooks | مفاتيح بنطاقات؛ 6 أحداث ثابتة (`order.placed`, `order.status_changed`, `order.refund_requested`, `product.stock_low`, `product.hidden_by_rule`, `payout.status_changed`) موقَّعة مع حارس عناوين صادرة وإعادة محاولة كل 5 دقائق | `lavkoo:app/Services/Marketplace/SellerWebhookDispatcher.php:39-46`، `lavkoo:app/Console/ScheduleDefinition.php:70` |

**ملاحظة عن «Developer Portal»:** الخدمات تحت `app/Services/DeveloperPortal/` هي بوابة توثيق API داخلية للمنصة (تصنيف الجمهور `vendor_app`/`customer_app`/…، مولّدات OpenAPI و Postman) وليست بوابة مطوّرين للبائع؛ مفاتيح البائع و webhooks تعيش في `app/Services/Marketplace/` — `lavkoo:app/Services/DeveloperPortal/ApiDoc.php:37-43`.

**جداول Seller Center بلا ترحيل في المستودع.** لا يوجد في `database/` ولا `Modules/` أي ذكر لأسماء الجداول التالية (بحث بالاسم، صفر نتائج لكلٍّ منها؛ الأسماء من `$table` الصريح أو من عرف Laravel في تسمية الجدول): `seller_staff`, `seller_roles`, `seller_api_keys`, `seller_webhooks`, `seller_webhook_deliveries`, `seller_bulk_jobs`, `seller_automation_rules`, `seller_automation_runs`, `seller_automation_actions`, `seller_insights`, `seller_pricing_policies`, `seller_sla_breaches`, `seller_verification_documents`, `seller_notification_deliveries`, `brand_claims`, `vendor_ledger_entries`. والكود يتحصّن بـ`Schema::hasTable()` ويعمل «خامداً» عند غيابها (أمثلة: `lavkoo:app/Services/Marketplace/SellerPermissionService.php:105-107`، `lavkoo:app/Services/Marketplace/VendorLedger.php:49`، `lavkoo:app/Services/Marketplace/SellerWebhookDispatcher.php:95-97`). هل هذه الجداول موجودة في الإنتاج وبأي مخطط: `UNKNOWN` (يحسمه مخطط قاعدة الإنتاج أو ملف الـ SQL الأساس غير المتتبَّع).

### 2.7 بوابة الإصدار والصيانة لتطبيق البائع (السؤال 6)

لا توجد نقطة إعداد خاصة بالبائع: التطبيق يقرأ `GET /api/v1/config` المشتركة (`lavkoo:routes/rest_api/v1/api.php:66`، `lavkooappseller:lib/utill/app_constants.dart:14`) وهي عامة للقراءة ولا تحمل أي سياق متجر.

| الحقل في الردّ | المصدر | الدليل |
|---|---|---|
| `seller_app_version_control` = `{for_android:{status,version,link}, for_ios:{status,version,link}}` | صف `business_settings` بالنوع `seller_app_version_control` (JSON)؛ يكتبه الإداري من شاشة إعدادات التطبيقات؛ القيمة الابتدائية في سكربت الترقية `version: "14.1"` لكلا النظامين | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:237`، `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:305-315`، `lavkoo:app/Traits/UpdateClass.php:304-315` |
| `software_version` | ثابت `SOFTWARE_VERSION` | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:218` |
| `maintenance_mode` = `{maintenance_status, selected_maintenance_system, maintenance_messages, maintenance_type_and_duration}` | مفتاح cache واحد `system_maintenance_mode` + ثلاثة إعدادات عامة | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:106-111`، `lavkoo:app/Traits/MaintenanceModeTrait.php:10-32` |
| `system_default_currency`, `currency_list`, `currency_model`, `currency_symbol_position`, `decimal_point_settings` | إعدادات عامة | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:158`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:203-204`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:214`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:219` |
| `business_mode`, `seller_registration`, `pos_active`, `vendor_can_edit_order_status`, `vendor_review_reply_status`, `vendor_forgot_password_method` | إعدادات عامة | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:161-162`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:205`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:257`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:262`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:277` |
| `in_house_shop`, `company_*`, `country_code`, `language`, `system_timezone` | إعدادات عامة | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:163-168`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:206`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:212`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:253`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:266` |

**التنفيذ في جهة العميل فقط.** الخادم لا يرفض إصداراً قديماً: لا middleware إصدار على v3 (السلسلة في 2.2)، والتطبيق هو من يقارن إصداره بـ`for_android.version`/`for_ios.version` عند الإقلاع (`lavkooappseller:lib/features/splash/screens/splash_screen.dart:75-82`، `lavkooappseller:lib/features/splash/domain/models/config_model.dart:331-332`). وكذلك الصيانة: `maintenance_mode` middleware مطبَّق على مسارات اللوحة (`lavkoo:routes/vendor/routes.php:45`، `lavkoo:routes/seller/routes.php:40`) وغير مطبَّق على v3 (`lavkoo:routes/rest_api/v3/seller.php:49`)؛ التطبيق يعتمد على حقل الإعداد وعلى topic الإشعار. القيم الفعلية في الإنتاج `UNKNOWN`. — الكود `VERIFIED`.

### 2.8 الإشعارات واللغة (السؤال 7)

- **رموز الأجهزة:** عمودان على البائع: `sellers.cm_firebase_token` (التطبيق؛ يكتبه `PUT cm-firebase-token` — `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:619-635`) و`sellers.cm_firebase_token_web` (اللوحة). جهاز واحد لكل بائع؛ `seller_staff` بلا عمود رمز (`lavkoo:app/Models/SellerStaff.php:23`)؛ ويُكتب الرمز على صف البائع (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:628-632`) وتفاصيل نطاق هذه الكتابة في SEC-E14-13. — `VERIFIED`.
- **إشعارات الطلب:** إلى رمز البائع مباشرة وبلغته `app_language` (`lavkoo:app/Traits/PushNotificationTrait.php:72-99`).
- **إشعارات التشغيل (Seller Intelligence):** `SellerNotifier` يجمّع بحسب النوع، يمنع التكرار بمفتاح `(seller, topic, نافذة 12 ساعة)`، ويدفع للرمزين فقط عند الشدّة `critical`/`high` (`lavkoo:app/Services/SellerIntelligence/SellerNotifier.php:46-49`، `lavkoo:app/Services/SellerIntelligence/SellerNotifier.php:153-188`). كلمة `topic` هنا نوع الإشعار لا FCM topic.
- **FCM topics:** تطبيق البائع يشترك في `lavkoo_seller` و`maintenance_mode_start_vendor` (`lavkooappseller:lib/utill/app_constants.dart:400-401`، `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:89-90`). الخادم ينشر `maintenance_mode_start_vendor` عند تبديل الصيانة وبشرط `business_mode == 'multi'` (`lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:274-276`). أما `lavkoo_seller` فلا ناشر له في الخادم (بحث `lavkoo_seller` في `app routes config Modules`: صفر نتائج)؛ الإرسال العام للإداري يذهب دائماً إلى topic الافتراضي `sixvalley` (`lavkoo:app/Http/Controllers/Admin/Notification/NotificationController.php:66-69`، `lavkoo:app/Traits/PushNotificationTrait.php:449-453`). — `VERIFIED` للكود؛ كون تطبيق العميل المنشور مشتركاً في `sixvalley` يخصّ ملحق تطبيق العميل.
- **إشعارات داخل التطبيق:** قائمة عامة لكل البائعين: `notifications.sent_to = 'seller'` منذ تاريخ إنشاء البائع، بلا استهداف بائع أو متجر (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShopController.php:43-64`).
- **اللغة:** ترويسة `lang` تحدّد locale الطلب، مع طيّ `sa → ar` و`us → en` لأن إصدارات قديمة أرسلت **رمز الدولة** مكان رمز اللغة (`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29`)؛ ولغة الإشعارات تُحفظ في `sellers.app_language` عبر `PUT language-change` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:784-791`).

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الدليل | الحالة |
|---|---|---|---|
| A1 | كل مبلغ يدخله البائع (سعر، خصم، شحن، حدّ أدنى، سحب) يُحوَّل عبر `Convert::usd()`/`BackEndHelper::currency_to_usd()` المعتمدَين على إعداد عام واحد `system_default_currency` وسعر صرف الدولار، والتحويل فعّال فقط حين يكون `currency_model` = `multi_currency` (`lavkoo:app/Utils/Convert.php:11-18`)؛ 44 موضع استدعاء في 9 متحكّمات v3 | `lavkoo:app/Utils/Convert.php:9-22`، `lavkoo:app/Utils/BackEndHelper.php:10-23`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:283`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:459` | VERIFIED |
| A2 | `Convert::default()` يقرّب إلى خانتين عشريتين؛ وكذلك مهمة السعر بالجملة، إجراء الخصم في الأتمتة، سياسة التسعير ومحاكي الرسوم — لا يناسب KWD/BHD/OMR | `lavkoo:app/Utils/Convert.php:62`، `lavkoo:app/Services/Marketplace/Bulk/BulkPriceOperation.php:67`، `lavkoo:app/Services/SellerAutomation/Actions/SetDiscountAction.php:77`، `lavkoo:app/Services/Marketplace/PricingPolicyService.php:61`، `lavkoo:app/Services/Marketplace/FeeSimulatorService.php:65-70` | VERIFIED |
| A3 | دفتر أستاذ البائع: رصيد جارٍ واحد لكل `(seller_id, seller_is)`؛ عمود `currency` وسم على القيد لا بُعد للرصيد؛ `balances()` و`withdrawable()` يجمعان كل القيود بلا تمييز عملة؛ العملة الافتراضية هي العملة الأساس العامة | `lavkoo:app/Services/Marketplace/VendorLedger.php:74-80`، `lavkoo:app/Services/Marketplace/VendorLedger.php:104-128`، `lavkoo:app/Services/Marketplace/VendorLedger.php:227-241`، `lavkoo:app/Models/VendorLedgerEntry.php:50-53` | VERIFIED |
| A4 | `seller_wallets`: صف واحد لكل بائع بلا عملة ولا متجر | `lavkoo:app/Models/SellerWallet.php:28-39` | VERIFIED |
| A5 | طلب الدفع يقبل `payout_currency` ويحوّل بسعر الصرف العام لحظة الطلب | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:54-58`، `lavkoo:app/Services/Marketplace/PayoutService.php:97-109` | VERIFIED |
| A6 | هوية الفاتورة التي يطبعها البائع من الإعدادات العامة: `company_name`, `company_phone`, `company_email`, `company_web_logo`, `invoice_settings` + `sellers.gst` | `lavkoo:app/Services/SellerInvoiceService.php:41-52` | VERIFIED |
| A7 | متجر داخلي واحد للمنصة (`author_type = 'admin'`، `seller_id = 0`) ومفتاح cache واحد له | `lavkoo:app/Utils/settings.php:37-50`، `lavkoo:app/Utils/Helpers.php:765-767` | VERIFIED |
| A8 | `products.code` فريد عالمياً عند الإنشاء والتحديث من v3 | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:798`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1233` | VERIFIED |
| A9 | إعدادات سلوك البائع عامة لا لكل متجر: `new_product_approval`, `stock_limit`, `seller_pos`, `free_delivery_*`, `minimum_order_amount_by_seller`, `kyc_required_documents`, `require_kyc_for_payout` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1049`، `lavkoo:app/Services/Marketplace/InventoryService.php:141-146`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:214-216` | VERIFIED |
| A10 | إبطال cache عام: `cacheRemoveByType('sellers'/'shops'/'in_house_shop')` و`Cache::clear()` عند الإغلاق المؤقت | `lavkoo:app/Models/Seller.php:160`، `lavkoo:app/Models/Shop.php:167-170`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShopController.php:38` | VERIFIED |
| A11 | بوابة الإصدار والصيانة و topics الإشعار عامة بلا بُعد متجر | القسمان 2.7 و2.8 | VERIFIED |
| A12 | الأوامر المجدولة للبائع تعمل على كل البائعين دفعة واحدة بلا وسيط سوق؛ التسوية اليومية 02:00 و SLA 03:00 بتوقيت الخادم الواحد | `lavkoo:app/Console/ScheduleDefinition.php:35`، `lavkoo:app/Console/ScheduleDefinition.php:43` | VERIFIED |
| A13 | رمز الدولة `sa` مستعمل تاريخياً كقيمة لترويسة اللغة | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29` | VERIFIED |
| A14 | لا يوجد في مسارات ومتحكّمات وخدمات ونماذج البائع أي `storefront_id`/`store_id`/`country_id`/`market_id`/`legal_entity` (البحث المسجَّل في القسم 1: صفر لأسماء الأعمدة والمفاتيح؛ كلمة `storefront` ترد 10 مرات في `routes/rest_api/v3` و`app/Http/Controllers/RestAPI/v3/seller` و`app/Services/Marketplace`، وكلها في تعليقات أو توصيف توثيقي) | بحث مسجَّل | VERIFIED |

## 4. الأثر على قرارات GCC المعتمدة

### 4.1 عدسة GCC: البائع/المتجر مفهوم سوق-بائعين يجب أن يبقى مستقلاً

**الخلاصة:** `Seller`/`Shop` الحاليان يمثّلان **تاجراً طرفاً ثالثاً داخل منصة واحدة** (هوية دخول + مستأجر صلاحيات + طرف مقابل مالي)، لا واجهة بيع لدولة ولا كياناً قانونياً بائعاً. الأدلة تؤيّد قاعدة `Foundation: distinct concepts` ولا تتعارض معها. التعارضات الدلالية لو أُعيد استخدام `seller_id`/`shop_id` كمتجر دولة:

| # | التعارض | الدليل |
|---|---|---|
| C1 | `seller_id` هوية مصادقة: صف `sellers` يحمل كلمة مرور و token ورمز جهاز ولغة تطبيق؛ «متجر دولة» ليس مستخدماً يسجّل الدخول | `lavkoo:app/Models/Seller.php:40-68` |
| C2 | `seller_id` هو مستأجر نظام الصلاحيات: الأدوار والموظفون والمفاتيح و webhooks وقواعد الأتمتة كلها مملوكة لبائع؛ إعادة استعماله للدولة تخلط «من يعمل في المتجر» بـ«أي سوق» | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:57-73`، `lavkoo:app/Services/Marketplace/SellerApiKeyService.php:51-59` |
| C3 | القيمة `0` و`seller_is = 'admin'` و`added_by = 'admin'` حارس يعني «المنصة نفسها»؛ ستّ دول تحتاج ستة «داخليين» ولا مكان لذلك في حارس واحد، ومزوّدو التنفيذ مربوطون تحديداً بـ`added_by = 'admin'` | `lavkoo:app/Utils/Helpers.php:765-767`، `lavkoo:app/Services/Fulfillment/ProductProviderAssignmentService.php:196` |
| C4 | الطلب يُقسَّم عند الدفع لكل بائع (`orders.seller_id` + `seller_is`)؛ الدولة تضمّ بائعين كثيرين، فاستعمال `seller_id` للدولة يهدم تقسيم الطلبات والعمولة والتسوية | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:65-75` |
| C5 | المال: محفظة ورصيد دفتر أستاذ وعمولة وحساب بنكي **لكل بائع**؛ الكيان القانوني هو الطرف الآخر (من يفوتر العميل ويدفع للبائع) لا البائع نفسه | `lavkoo:app/Services/Marketplace/VendorLedger.php:36-48`، `lavkoo:app/Services/SellerInvoiceService.php:41-52` |
| C6 | `shops.vacation_*`/`temporary_close` إيقاف يملكه التاجر لمتجره؛ GCC-12 إيقاف تملكه المنصة لمتجر دولة بثلاثة أنماط — مفهومان مختلفان يحملان اسماً متقارباً | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShopController.php:16-40` |
| C7 | ملكية المنتج ليست بعمود `seller_id` أصلاً بل `(added_by, user_id)`؛ و`shop_id` يظهر في ستة نماذج فقط (`Chatting`, `OrderTransaction`, `Product`, `ShopFollower`, `StockClearanceProduct`, `StockClearanceSetup`) بمعنى «متجر التاجر» | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:88`، `lavkoo:app/Models/Shop.php:104-107` |
| C8 | عقد v3 يشتق الهوية من الـ token وحده وليس فيه وسيط متجر؛ التطبيق المنشور لا يرسل أي سياق دولة | القسم 2.4 |
| C9 | حجم الانتشار: `seller_id` يرد 1,415 مرة في `app/`، و`seller_is` 428، و`shop_id` 101، و58 نموذجاً يحمل `seller_id` — تغيير الدلالة غير قابل للحصر | عدّ `grep` مسجَّل |

### 4.2 جدول الأثر

| القرار / الأساس | ما يدعمه اليوم في هذا المجال | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Foundation: distinct concepts (Seller/Shop ≠ Country/Storefront/Legal Entity) | البائع كيان مكتمل ومستقل دلالياً؛ `SellerPrincipal` يفصل أصلاً «المتجر» عن «الشخص» | لا مفهوم Storefront ولا Legal Entity في أي جدول أو مسار للبائع (A14) | `lavkoo:app/Services/Marketplace/SellerPrincipal.php:65-74` |
| Foundation: separate legal selling company per country | حقول TIN على المتجر؛ فترة تبريد وتدقيق عند تغيير البنك | فاتورة البائع بهوية شركة عامة واحدة؛ لا ربط للبائع بكيان قانوني؛ حساب بنكي واحد على `sellers` | `lavkoo:app/Services/SellerInvoiceService.php:41-52`، `lavkoo:app/Models/Seller.php:54-57` |
| Foundation: independent products/prices/SKUs/inventory per country | منتج البائع صف مستقل أصلاً | `products.code` فريد عالمياً؛ لا عمود سوق على المنتج؛ السعر محوَّل بعملة أساس عامة (A1، A8) | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:798` |
| Foundation: one fulfillment provider per product per storefront | منتجات البائع خارج المزوّدين تماماً، فلا خطر fallback من جهة البائع | مسار المخزون الكلاسيكي يكتب `current_stock` دون سجل حركة؛ `inventory_locations.seller_id` يخصّ ملحق التنفيذ | `lavkoo:app/Services/Fulfillment/ProductProviderAssignmentService.php:196`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1778-1781` |
| GCC-09 (تدقيق أثر تطبيق البائع) | تطبيق البائع لا يستهلك ثيم العميل؛ يقرأ من `config` ألواناً وشعاراً عامة فقط | `config` مشتركة وعامة: أي تحويلها إلى سياق متجر يمسّ تطبيق البائع المنشور | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:153-168` |
| GCC-10 (علامة مشتركة مع تخصيصات لكل دولة) | — | `company_*` و`in_house_shop` قيمة واحدة تصل للبائع | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:163-168`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:266` |
| GCC-12 (إيقاف لكل متجر) | آلية صيانة عامة بأنظمة مختارة (`vendor_app`, `vendor_panel`) و topic للبائع | مفتاح cache واحد؛ لا فرض خادمي على v3؛ لا نمط browse-only/redirect؛ إيقاف متجر دولة لا يجب أن يوقف معالجة البائع لطلباته القائمة — غير ممثَّل | `lavkoo:app/Traits/MaintenanceModeTrait.php:10-32`، `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:265-280` |
| GCC-13 (هوية عميل واحدة، محافظ منفصلة) | — | POS البائع يدفع من `users.wallet_balance` العام؛ قوائم العملاء للبائع من `users` بلا نطاق | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:320-323` |
| GCC-14 (دخول واحد بنطاقات متعددة) | نموذج صلاحيات بائع جاهز ومفروض على المسار، يُقرأ لحظة الطلب | مفاتيح الصلاحية وظيفية فقط (لا نطاق بيانات)؛ لا مفهوم «موظف مخوَّل على متاجر دول محددة» | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:30-41` |
| GCC-15 (ولاء محلي) | — | تسليم البائع للطلب يمنح نقاط ولاء وعمولة إحالة بإعدادات عامة وعلى رصيد عام | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:459-477` |
| GCC-16 (شحن محلي وعابر) | طرق شحن ومندوبون لكل بائع | لا بُعد دولة منشأ/وجهة في طرق شحن البائع؛ التفاصيل لدى ملحق الشحن | مجموعات 21، 22، 25 |
| GCC-17 (حملات مركزية، قسائم محلية) | قسائم لكل بائع | القسيمة بلا متجر/عملة؛ القيمة `seller_id = 0` = كل البائعين | مسح `CouponController` |
| GCC-18 (علامات مركزية) | سجل مطالبات العلامات لكل بائع | المطالبة بلا نطاق دولة (التفويض التجاري غالباً إقليمي) | `lavkoo:app/Models/BrandClaim.php:32-36` |
| GCC-19 (السعودية الافتراضية) | كل بيانات البائع الحالية سعودية ضمناً؛ عقد v3 بلا وسيط متجر، فالافتراضي الضمني قائم | يلزم تثبيت القاعدة صراحة: غياب سياق المتجر في طلب v3 = السعودية | القسم 2.4 |
| قيد: دفاتر ومحافظ منفصلة لكل متجر/كيان/عملة | دفتر أستاذ غير قابل للتعديل، idempotent، بدقة 4 خانات | الرصيد غير مفصول بعملة/كيان (A3)؛ نظامان ماليان متوازيان؛ `seller_wallets` بلا عملة (A4) | `lavkoo:app/Services/Marketplace/VendorLedger.php:53-54`، `lavkoo:app/Services/Marketplace/VendorLedger.php:104-128` |
| قيد: caches/queues/notifications بنطاق السوق | — | A10، A11، A12 | القسم 3 |
| ملاحظة KWD/BHD/OMR | أعمدة `decimal(21,12)`؛ الدفتر 4 خانات | تقريب إلى خانتين في خمسة مواضع (A2) | `lavkoo:database/migrations/2026_01_22_164826_update_seller_wallets_money_columns.php:14-22` |

### 4.3 ما يحتاجه بائع يعمل في عدة دول (توثيق فقط)

1. **عضوية بائع↔متجر دولة** (كيان جديد، لا عمود على `sellers`): حالة اعتماد لكل متجر، وثائق KYC وهوية ضريبية لكل دولة، حساب استلام لكل دولة/عملة، عمولة قابلة للتخصيص لكل متجر. اعتماد البائع في السعودية لا يورَّث تلقائياً (متّسق مع GCC-11 ومع قيد «لا وراثة تلقائية للحسابات الحسّاسة»).
2. **نطاق البيانات:** المنتج والطلب يرثان المتجر من مصدرهما (المنتج مستقل لكل دولة بقرار الأساس؛ الطلب يحمل متجره)؛ وتُضاف الإشارة إلى المتجر على ما يملكه البائع مباشرة: قيود الدفتر وطلبات الدفع (مع الكيان القانوني والعملة)، القسائم، طرق الشحن، قواعد الأتمتة، الـ insights، المهام بالجملة، وحمولة الـ webhook. المندوبون والموظفون والأدوار تبقى على البائع مع نطاق متاجر اختياري.
3. **اختيار المتجر في v3:** وسيط اختياري (ترويسة أو query) يحدّد المتجر النشط + نقطة جديدة تعدّد متاجر البائع المعتمدة. غيابه = المتجر الافتراضي (السعودية، GCC-19)، فالتطبيق المنشور يستمر دون تغيير.
4. **مالية لكل كيان:** رصيد الدفتر و`withdrawable` وكشف الحساب والدفع مفصولة بـ(بائع، كيان قانوني، عملة)؛ لا تحويل بين العملات عند الدفع إلا بقرار صريح؛ `seller_wallets` القديمة تبقى سعودية للقراءة والتسوية التاريخية.
5. **صلاحيات:** نطاق متاجر على دور الموظف وعلى نطاقات المفتاح المُصدَر، يُفحص في `SellerPrincipal` نفسه كي يخدم API واللوحة والأعمال المؤجَّلة معاً.
6. **إشعارات:** الرمز يبقى على البائع؛ يُضاف المتجر إلى حمولة الإشعار وإلى مفتاح منع التكرار في `SellerNotifier`.

**تغييرات عقد v3 المترتبة وقواعد التوافق الخلفي:**

| التغيير | نوعه | قاعدة التوافق |
|---|---|---|
| وسيط المتجر النشط | إضافي اختياري | غيابه = السعودية؛ قيمة غير معتمدة للبائع = رفض صريح لا تجاهل صامت |
| نقطة «متاجري» | مسار جديد | لا أثر على القديم |
| `seller-info` / `shop-info` | حقول إضافية فقط (المتجر النشط، العملة، عدد الخانات العشرية) | لا حذف ولا تغيير نوع لحقل قائم؛ التطبيق المنشور يتجاهل المجهول |
| القوائم (منتجات/طلبات/كشف/تقارير) | تُرشَّح بالمتجر النشط | بدون وسيط تُعيد بيانات السعودية فقط = سلوك اليوم نفسه |
| مبالغ الإدخال والإخراج | بعملة المتجر النشط بدل التحويل العام | للسعودية النتيجة مطابقة لليوم ما دام `currency_model` الحالي ثابتاً — يستوجب اختبار انحدار |
| `payouts` | رصيد لكل (كيان، عملة) | الشكل الحالي يبقى ويمثّل السعودية |
| `GET /api/v1/config` | حقول متجر إضافية أو نسخة واعية بالمتجر | الحقول الحالية تبقى بقيم السعودية؛ `seller_app_version_control` يبقى بشكله، ويُرفع الحدّ الأدنى فقط عند إلزام ميزة تتطلّب إصداراً جديداً |
| webhooks | حقل متجر في الحمولة | إضافي؛ أسماء الأحداث الستة ثابتة |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أقل إضافة موصى بها |
|---|---|---|---|
| عقد v3 الحالي (236 مساراً وأشكال ردودها) | Preserve | تطبيق منشور يعتمد عليه؛ guardrail رقم 4 | إضافات اختيارية فقط؛ اختبارات عقد قبل أي تغيير |
| `SellerApiAuthMiddleware` + `SellerPrincipal` + `seller_can` + `seller_owner` | Preserve + Extend | نقطة واحدة تحلّ «من يعمل وعلى أي متجر تاجر»، مستخدمة في API واللوحة والأعمال المؤجَّلة | إضافة «المتجر النشط/المتاجر المسموحة» إلى الـ principal |
| كتالوج الصلاحيات الـ11 | Preserve | مخزَّن في أدوار البائعين ونطاقات المفاتيح | نطاق متاجر بجانب المفاتيح لا داخلها |
| `Seller` / `Shop` وحقولهما | Preserve | هوية وبيانات تاريخية سعودية؛ دلالة «تاجر» صحيحة | لا أعمدة دولة عليهما؛ العضوية في كيان جديد |
| حارس البائع الداخلي (`seller_id = 0`, `added_by = 'admin'`, `seller_is = 'admin'`) | Preserve (مراجعة دلالية لازمة) | مزوّدو التنفيذ والتقارير والتسوية مبنية عليه | تحديد كيف يُمثَّل «داخلي لكل دولة» دون تغيير الحارس |
| `VendorLedger` / `PayoutService` | Extend | سليم بنيوياً (غير قابل للتعديل، idempotent، قفل) | بُعد (كيان قانوني، عملة) للرصيد والاستعلامات |
| `seller_wallets` + `withdraw_requests` (المسار القديم) | Preserve ثم تجميد | أرصدة سعودية تاريخية؛ مساراه الكلاسيكيان فيهما مسائل مقيّدة | لا توسعة لدول جديدة؛ تسوية مع الدفتر قبل أي ترحيل |
| Seller Center (insights، أتمتة، مهام بالجملة، webhooks، مفاتيح) | Extend | متّسق العزل وحديث | إشارة المتجر على الصفوف والحمولات؛ إزالة التقريب الثابت لخانتين |
| المتحكّمات الكلاسيكية في v3 واللوحة | Refactor (أمني أولاً) | عزل غير متّسق (SEC-E14-01…14) | إحكام الملكية قبل إضافة أي بُعد جديد |
| تحويلات `Convert::usd` في مسارات البائع (44 موضعاً) | Refactor | عملة أساس عامة واحدة | خدمة مال واعية بعملة المتجر؛ السعودية تبقى مطابقة |
| `GET /api/v1/config` و`seller_app_version_control` | Preserve + Extend | بوابة الإصدار الحالية للتطبيق المنشور | حقول إضافية؛ لا تغيير للموجود |
| صيانة التطبيق (cache key + topic) | Extend | عامة اليوم | نمط إيقاف لكل متجر لا يمنع البائع من معالجة الطلبات القائمة |
| Seller API v2 القديم | Refactor (قرار إيقاف) | سطح قديم موازٍ لعقد v3 (انظر الملحق المقيّد) | التأكد من عدم وجود عملاء ثم الإيقاف — قبل GCC |
| عضوية بائع↔متجر، حساب استلام لكل دولة، KYC لكل دولة، نقطة «متاجري» | New | غير موجودة | كما في 4.3 |
| اختبارات عقد وعزل لمجال البائع | New | شبه غائبة اليوم (E14-F09) | مجموعة انحدار سعودية قبل أي تغيير |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E14-F01 | مسائل أمنية مقيّدة في مجال البائع (14 بنداً SEC-E14-01…14): عزل/تفويض غير متّسق في المتحكّمات الكلاسيكية ونظائرها في اللوحة، ومسائل تسجيل ومصادقة وسطح قديم | P0 | VERIFIED | الملحق المقيّد غير المنشور | مخاطر مالية وعزل بيانات بين البائعين مفتوحة (تفاصيلها في الملحق المقيّد)؛ إضافة دول وبائعين جدد تضاعفها | معالجة أمنية مستقلة ومعتمدة تسبق أي عمل GCC على هذا المجال |
| E14-F02 | لا مفهوم متجر/دولة/كيان قانوني في أي مسار أو جدول أو خدمة للبائع؛ الهوية من الـ token فقط | P1 | VERIFIED | A14؛ `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:56-64` | لا يمكن تشغيل بائع في دولة ثانية دون تصميم عضوية ونطاق | 4.3 بنود 1–3 |
| E14-F03 | رصيد دفتر أستاذ البائع غير مفصول بعملة/كيان؛ `seller_wallets` بلا عملة؛ نظامان ماليان متوازيان معروضان معاً. الكتابة الحالية بعملة الأساس فقط في المسارات المفحوصة (`lavkoo:app/Services/Marketplace/VendorLedger.php:91` يستخدم `baseCurrency()` افتراضياً، و`lavkoo:app/Services/Marketplace/PayoutService.php:95-107` يبقي احتياطي الطلب بعملة الأساس)، فلا خلط قائم في الكود المفحوص؛ الخطر كامن ويُفعَّل بأول قيد بعملة ثانية | P0 | VERIFIED للبنية؛ INFERRED للخطر الكامن | `lavkoo:app/Services/Marketplace/VendorLedger.php:104-128`، `lavkoo:app/Models/SellerWallet.php:28-39`، `lavkoo:routes/rest_api/v3/seller.php:120-124`، `lavkoo:routes/rest_api/v3/seller.php:620-626` | أول قيد بعملة ثانية يخلط الأرصدة ويفسد `withdrawable`؛ خطر مالي | بُعد (كيان، عملة) قبل قبول أي طلب غير سعودي لبائع؛ قرار مالك حول أي النظامين هو المرجع |
| E14-F04 | كل مبالغ البائع تمرّ بتحويل عام على `system_default_currency`؛ تقريب لخانتين في خمسة مواضع | P1 | VERIFIED | A1، A2 | أسعار خاطئة أو مقرَّبة في KWD/BHD/OMR | خدمة مال بعملة المتجر ودقّتها؛ اختبار انحدار SAR |
| E14-F05 | فاتورة البائع بهوية شركة عامة واحدة وإعداد فاتورة واحد | P1 | VERIFIED | `lavkoo:app/Services/SellerInvoiceService.php:41-52` | فاتورة بكيان قانوني خاطئ في دولة أخرى (تعرّض ضريبي) | هوية الفاتورة من لقطة الكيان القانوني للطلب |
| E14-F06 | بوابة الإصدار والصيانة عامة ومنفَّذة في العميل فقط؛ v3 بلا فرض خادمي | P1 | VERIFIED | 2.7 | تطبيق قديم لا يعرف المتاجر سيبقى يعمل؛ لا إيقاف لكل متجر | قاعدة «بدون سياق = السعودية» + فرض خادمي اختياري للإصدار الأدنى عند الحاجة |
| E14-F07 | البائع الداخلي (المنصة) لا يُخدَم من v3 ولا من الدفتر؛ ممثَّل بحارس `0`/`admin` وحيد | P1 | VERIFIED | 2.5 | تمثيل «داخلي لكل دولة» غير محدَّد؛ تطبيق البائع لا يدير بضاعة المنصة | قرار تصميم: كيف يُنسب المخزون والطلب الداخلي لكل متجر دولة |
| E14-F08 | 16 جدولاً لميزات Seller Center بلا ترحيل في المستودع؛ الكود يتخامد بـ`Schema::hasTable` | P1 | VERIFIED (الغياب) / UNKNOWN (الإنتاج) | 2.6 | لا يمكن إعادة بناء المخطط ولا التراجع ولا معرفة ما هو مفعَّل فعلاً في الإنتاج | استخراج مخطط الإنتاج للقراءة وتوثيقه قبل أي ترحيل GCC |
| E14-F09 | شبه غياب للاختبارات: ملفان فقط في `tests/` يلمسان `v3/seller` أو أصناف البائع، ولا اختبار لخدمات `Marketplace` المالية للبائع؛ والاختبار `SellerCenterRouteCollisionTest` المذكور في تعليق المزوّد غير موجود | P1 | VERIFIED | `lavkoo:app/Providers/RouteServiceProvider.php:175` + بحث `v3/seller|seller_api_auth|SellerPrincipal|SellerPermissionService|SellerApiKey|seller-center|SellerCenter` في `tests` (ملفان) وبحث اسم الاختبار (غير موجود) وبحث بأسماء `VendorLedger|PayoutService|SellerWallet|SellerPrincipal|SellerApiKey|WithdrawRequest|VendorPayoutRequest` في `tests` (صفر) | لا شبكة أمان لـ«حفظ سلوك البائع» | مجموعة انحدار للعقد والعزل والمال قبل التنفيذ |
| E14-F10 | `Cache::clear()` عند الإغلاق المؤقت لمتجر بائع، وإبطال cache عام عند حفظ أي بائع/متجر | P1 | VERIFIED | A10؛ `lavkoo:app/Http/Controllers/Vendor/ShopController.php:131` | بائع واحد يمسح cache كل الأسواق؛ يتعارض مع قيد «cache بنطاق السوق» | إبطال موجَّه بالمفتاح والمتجر |
| E14-F11 | مساران لتعديل المخزون: الكلاسيكي يكتب `current_stock` مباشرة بلا سجل حركة | P2 | VERIFIED | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1778-1781`، `lavkoo:app/Services/Marketplace/InventoryService.php:36-85` | سجل حركة ناقص؛ تباين بين التطبيق واللوحة | توحيد عبر `InventoryService` |
| E14-F12 | رمز جهاز واحد لكل بائع؛ توجيه الرمز في SEC-E14-13؛ topic `lavkoo_seller` بلا ناشر؛ قائمة إشعارات البائع عامة لكل البائعين | P2 | VERIFIED / INFERRED | 2.8 | إشعارات مفقودة؛ لا استهداف بدولة | رموز لكل حامل اعتماد؛ استهداف بالمتجر |
| E14-F13 | مسارات AI للبائع تُقيَّم في SEC-E14-12؛ `SellerApiMiddleware` كود ميت؛ تعليق `SellerApiAuthMiddleware` عن عدد المسارات بلا نطاق لم يعد يطابق ملف المسارات (5 مسارات في مجموعة مصادقة الحامل وحدها) | P2 | VERIFIED | `lavkoo:app/Http/Middleware/SellerApiMiddleware.php:17-20`، `lavkoo:routes/rest_api/v3/seller.php:77-81`، `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:69-70` | نظافة وتوثيق | تنظيف التعليق وحذف الصنف الميت |
| E14-F14 | `SellerController` في v3 يستورد أصنافاً من `Modules\Auction` غير الموجود على القرص (الموجود: `AI`, `Blog`, `TaxModule`)، وتستخدمها مقاطع مشروطة بـ`getCheckAddonPublishedStatus`؛ والوثيقة `CLAUDE.md` تذكر الوحدة كموجودة | P2 | VERIFIED للغياب والاستيراد؛ INFERRED لوقت التشغيل | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:38-41`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:224-236`، `lavkoo:CLAUDE.md:36` | الاستيراد وحده لا يفشل؛ الخطأ القاتل المحتمل عند استخدام صنف من الوحدة لو كان إعداد الإضافة مفعّلاً دون الوحدة | توثيق؛ يتبع ملحق خريطة المعمارية وتحديث `CLAUDE.md` |
| E14-F15 | متجر واحد لكل بائع عُرف لا قيد (لا فهرس فريد في الترحيلات) | INFO | VERIFIED / UNKNOWN | 2.5 | صفوف متجر مكرَّرة ممكنة نظرياً؛ `->first()` يخفيها | فحص بيانات الإنتاج للقراءة |
| E14-F16 | `payout_currency` يحوّل بسعر الصرف العام لحظة الطلب | INFO | VERIFIED | A5 | ليس تسعير كتالوج، لكنه FX تلقائي في مسار مالي | قرار مالك: إبقاؤه للسعودية فقط أو تعطيله في GCC |
| E14-F17 | مزوّدو التنفيذ لا يخدمون منتجات البائع إطلاقاً | INFO | VERIFIED | 2.6 | بائع طرف ثالث في أي دولة ينفّذ بنفسه؛ قاعدة «مزوّد واحد لكل منتج» لا تمسّه | يُذكر في تصميم التنفيذ لكل دولة |
| E14-F18 | هوية v3 = token ← `SellerPrincipal` (مالك/موظف/مفتاح) مع 11 مفتاح صلاحية وظيفية مفروضة على المسار (53 إعلان `seller_can` و3 مجموعات `seller_owner` على 236 مساراً)؛ النموذج نفسه يخدم اللوحة والأعمال المؤجَّلة | INFO | VERIFIED | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:41-93`، `lavkoo:app/Services/Marketplace/SellerPermissionService.php:30-41` | نقطة التوسعة الطبيعية لنطاق المتجر في GCC-14 من جهة البائع | إضافة نطاق المتاجر إلى الـ principal لا إلى كل متحكّم |
| E14-F19 | تطبيق البائع يعتمد على `GET /api/v1/config` المشتركة مع تطبيق العميل (وأربع نقاط v1 أخرى)؛ لا إعداد خاص بالبائع | INFO | VERIFIED | `lavkoo:routes/rest_api/v1/api.php:66`، `lavkooappseller:lib/utill/app_constants.dart:14` | أي تحويل لـ`config` إلى سياق متجر لأجل تطبيق العميل يغيّر ما يستقبله تطبيق البائع المنشور | إضافات فقط؛ اختبار عقد لتطبيق البائع عند أي تغيير في `config` |

## 7. أسئلة مفتوحة وعناصر UNKNOWN

1. **قيمة `business_mode` في الإنتاج وعدد البائعين الفعليين** (هل Lavkoo سوق بائعين فعلاً أم متجر داخلي بتطبيق بائع قليل الاستخدام؟) — يحسمه: قراءة `business_settings` وعدّ `sellers` بحسب الحالة. هذا يحدّد وزن كل ما في هذا الملحق.
2. **وجود جداول Seller Center الـ16 ومخططها في الإنتاج** — يحسمه: مخطط قاعدة الإنتاج (قراءة فقط) أو ملف SQL الأساس.
3. **قيمة `seller_app_version_control` الحالية وإصدار تطبيق البائع المنشور في المتاجر** — يحسمه: `business_settings` + صفحتا المتجرين. إصدار المستودع `3.1.6+49` والقيمة الابتدائية `14.1` لا يدلّان على الإنتاج.
4. **هل ما زال أي عميل يستدعي `/api/v2/seller/*`؟** — يحسمه: سجلات الوصول في الإنتاج. بحث `v2/seller|api/v2` في ثوابت تطبيق البائع الحالي: صفر نتائج.
5. **قيم `currency_model` و`system_default_currency`** (هل التحويل `Convert::usd` فعلياً محايد اليوم؟) — يحسمه: `business_settings` و`currencies`؛ التفاصيل لدى ملحق العملة.
6. **هل يوجد قيد فريد على `shops.seller_id` أو صفوف مكرَّرة؟** — يحسمه: مخطط وبيانات الإنتاج.
7. **أيّ النظامين الماليين مرجع الدفع الفعلي للبائعين اليوم** (`seller_wallets` أم الدفتر) وهل أرصدتهما متطابقة؟ — يحسمه: بيانات الإنتاج وقرار المالك.
8. **هل `sellers.country_code` رمز هاتف فقط؟** — مستنتج من نماذج الطلب؛ يحسمه عيّنة بيانات.
9. **حالة أي عمل بائع في المستودع القديم** — `UNKNOWN (missing access: pharmacyksa98)`.
10. **هل يعمل `schedule:run` و queue worker في الإنتاج؟** (التسوية، الأتمتة، webhooks، المهام بالجملة تعتمد عليهما) — يحسمه: إعداد الخادم.
11. **ملفات مرجعية في `CLAUDE.md` غير موجودة في المستودع.** `lavkoo:CLAUDE.md:22` يذكر `installation/backup/database.sql`، و`lavkoo:CLAUDE.md:36` يذكر `Modules/Auction`؛ لا ملف `.sql` في المستودع (بحث `find` عن `*.sql`: صفر)، ولا `Modules/Auction` على القرص (محتوى `Modules/`: AI وBlog وTaxModule). يحسمه: مراجعة الوثيقة مع المالك ومطابقتها بالمستودع المنشور.

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| تحميل v3 بمجموعة `api` | `lavkoo:app/Providers/RouteServiceProvider.php:113-119` | VERIFIED |
| مجموعة `api`: `throttle:3000,1` بلا جلسة | `lavkoo:bootstrap/app.php:84-94` | VERIFIED |
| الأسماء المستعارة لـ middleware البائع | `lavkoo:bootstrap/app.php:112-127` | VERIFIED |
| مجموعة v3 الجذرية تحمل `api_lang` فقط | `lavkoo:routes/rest_api/v3/seller.php:49` | VERIFIED |
| token الدخول 50 محرفاً في `sellers.auth_token` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:98-100` | VERIFIED |
| token الموظف في `seller_staff.auth_token` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:50-51` | VERIFIED |
| guard `seller` جلسة | `lavkoo:config/auth.php:54-57` | VERIFIED |
| حلّ الـ principal: مالك ثم موظف ثم مفتاح | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:56-57` | VERIFIED |
| فحص `approved` في كل طلب | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:98-120` | VERIFIED |
| المفتاح: hash + prefix، يُعرض مرة | `lavkoo:app/Services/Marketplace/SellerApiKeyService.php:46-69` | VERIFIED |
| صلاحية/إلغاء المفتاح | `lavkoo:app/Models/SellerApiKey.php:36-43` | VERIFIED |
| المفتاح يُرفض على مسار بلا `seller_can` | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:66-79` | VERIFIED |
| كتالوج الصلاحيات | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:30-41` | VERIFIED |
| `seller_can` = أيّ صلاحية تكفي | `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:42-46` | VERIFIED |
| `seller_owner` يرفض الموظف والمفتاح | `lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:34-38` | VERIFIED |
| المالك يملك كل شيء | `lavkoo:app/Services/Marketplace/SellerPrincipal.php:93-96` | VERIFIED |
| دخول الموظف على الويب كبائعه الأم | `lavkoo:app/Http/Controllers/Vendor/Auth/StaffLoginController.php:61-70` | VERIFIED |
| خريطة صلاحيات الويب رفض-افتراضي | `lavkoo:app/Http/Middleware/SellerStaffAccessMiddleware.php:65-134` | VERIFIED |
| `SellerCenterContext` يبني الـ principal من الجلسة | `lavkoo:app/Http/Middleware/SellerCenterContext.php:61-73` | VERIFIED |
| مسارات اللوحة: `maintenance_mode`, `actch`, `seller`, `seller_staff_access` | `lavkoo:routes/vendor/routes.php:45-87` | VERIFIED |
| Seller Center على `/vendor` بثلاثة middleware | `lavkoo:routes/seller/routes.php:40-48` | VERIFIED |
| مجموعة `seller` في v2 مسجَّلة (55 مساراً) | `lavkoo:routes/rest_api/v2/api.php:27-130` | VERIFIED |
| مطابقة `{seller_id}` مع صاحب الـ token | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:143-151` | VERIFIED |
| ملكية المنتج `(added_by, user_id)` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:88` | VERIFIED |
| ملكية الطلب `(seller_id, seller_is)` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:65-75` | VERIFIED |
| حقول `sellers` | `lavkoo:app/Models/Seller.php:44-68` | VERIFIED |
| حقول `shops` | `lavkoo:app/Models/Shop.php:52-79` | VERIFIED |
| `shop()` و`shops()` معاً | `lavkoo:app/Models/Seller.php:96-104` | VERIFIED |
| فهرس `shops` غير فريد | `lavkoo:database/migrations/2026_02_25_163731_add_indexes_to_shops_table.php:22-30` | VERIFIED |
| `author_type` افتراضيه `vendor` | `lavkoo:database/migrations/2025_08_04_103017_add_author_type_to_shops_table.php:14-16` | VERIFIED |
| المتجر الداخلي `seller_id = 0` | `lavkoo:app/Utils/Helpers.php:765-767` | VERIFIED |
| cache المتجر الداخلي | `lavkoo:app/Utils/settings.php:37-50` | VERIFIED |
| الدفتر يرفض `sellerId` الصفري | `lavkoo:app/Services/Marketplace/VendorLedger.php:49-51` | VERIFIED |
| KYC: المطلوب الافتراضي والإعداد العام | `lavkoo:app/Services/Marketplace/SellerVerificationService.php:47-57` | VERIFIED |
| المزوّد لمنتج `admin` فقط | `lavkoo:app/Services/Fulfillment/ProductProviderAssignmentService.php:196` | VERIFIED |
| `adjust()` يرفض مخزون المزوّد | `lavkoo:app/Services/Marketplace/InventoryService.php:50-53` | VERIFIED |
| حارس التنفيذ عند تغيير حالة الطلب | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:406-408` | VERIFIED |
| السحب القديم على `seller_wallets` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:455-469` | VERIFIED |
| تبريد البنك و KYC قبل السحب | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:448-453` | VERIFIED |
| تسجيل تغيير البنك من التطبيق | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:394-402` | VERIFIED |
| الدفع على الدفتر | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:97-103` | VERIFIED |
| أحداث الـ webhook الستة | `lavkoo:app/Services/Marketplace/SellerWebhookDispatcher.php:39-46` | VERIFIED |
| الجدولة الخاصة بالبائع | `lavkoo:app/Console/ScheduleDefinition.php:35-76` | VERIFIED |
| `seller_app_version_control` في الردّ | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:236-238` | VERIFIED |
| كتابة إعداد الإصدار | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:305-315` | VERIFIED |
| القيمة الابتدائية للإصدار | `lavkoo:app/Traits/UpdateClass.php:304-315` | VERIFIED |
| التطبيق يقارن الإصدار محلياً | `lavkooappseller:lib/features/splash/screens/splash_screen.dart:75-82` | VERIFIED |
| الصيانة: مفتاح cache واحد | `lavkoo:app/Traits/MaintenanceModeTrait.php:10-32` | VERIFIED |
| نشر topic صيانة البائع | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:274-276` | VERIFIED |
| topics تطبيق البائع | `lavkooappseller:lib/utill/app_constants.dart:400-401` | VERIFIED |
| topic الافتراضي للإرسال العام | `lavkoo:app/Traits/PushNotificationTrait.php:449-453` | VERIFIED |
| إشعار الطلب إلى رمز البائع ولغته | `lavkoo:app/Traits/PushNotificationTrait.php:72-99` | VERIFIED |
| `SellerNotifier`: الرمزان والشدّة | `lavkoo:app/Services/SellerIntelligence/SellerNotifier.php:153-188` | VERIFIED |
| طيّ `sa → ar` في ترويسة اللغة | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29` | VERIFIED |
| `Convert::usd` على الإعداد العام | `lavkoo:app/Utils/Convert.php:9-22` | VERIFIED |
| `Convert::default` يقرّب لخانتين | `lavkoo:app/Utils/Convert.php:62` | VERIFIED |
| رصيد الدفتر بلا بُعد عملة | `lavkoo:app/Services/Marketplace/VendorLedger.php:104-128` | VERIFIED |
| العملة الأساس للدفتر | `lavkoo:app/Services/Marketplace/VendorLedger.php:227-241` | VERIFIED |
| هوية الفاتورة عامة | `lavkoo:app/Services/SellerInvoiceService.php:41-52` | VERIFIED |
| `products.code` فريد عالمياً | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:798` | VERIFIED |
| `Cache::clear()` عند الإغلاق المؤقت | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShopController.php:38` | VERIFIED |
| قائمة إشعارات البائع عامة | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShopController.php:43-64` | VERIFIED |
| حدّ محاولات الدخول يُضبط من إعداد سياسة | `lavkoo:app/Services/Platform/PolicyRegistry.php:265-270` | VERIFIED |
| تعليق يذكر اختباراً غير موجود | `lavkoo:app/Providers/RouteServiceProvider.php:175` | VERIFIED |
| جداول Seller Center بلا ترحيل | بحث بالاسم في `database` و`Modules` (صفر نتائج لـ16 اسماً) | VERIFIED |
| وجود هذه الجداول في الإنتاج | — | UNKNOWN |
| قيم الإعدادات الفعلية (`business_mode`, الإصدار، العملة) | — | UNKNOWN |

## 9. سجل التحقق المستقل

> جلسة التحقق: مستقلة عن جلسة الكتابة (Verify pass) — التاريخ: 2026-11-12 (+03:00) — قراءة ساكنة فقط؛ لم يُنفَّذ أي كود ولم تُعدَّل أي مستودعات.
> المصدر المفحوص: `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129`، و`lavkooappseller@8ea00922e44db3d69059d46892f8f4f84349b580` لثوابت التطبيق فقط.

### 9.1 جداول العدّ

| البند | العدد |
|---|---|
| عدد الادعاءات المفحوصة | 66 |
| مؤكَّدة دون تغيير | 51 |
| مصحَّحة (صياغة أو رقم أو نطاق أو حالة تحقّق) | 9 |
| مرفوضة (ثبت خلافها) | 2 |
| غير قابلة للتحقق بالقراءة الساكنة | 4 |

الادعاءات المفحوصة تشمل: كل بنود P0 وP1 في القسم 6 والبنود SEC-E14-01 حتى 14 في الملحق المقيّد، وأكثر من عشرين ادعاءً آخر من الأقسام 2 و3 و4 والمصفوفة، وإعادة بحث لكل ادعاء غياب (جداول Seller Center، ومسار `SellerCenterRouteCollisionTest`، والاختبارات المالية، و`storefront`).

الادعاءات المرفوضة: (1) «حجز بريد شخص آخر بحساب معروف كلمة مروره» في SEC-E14-08 (ب)، لأن التسجيل يفرض `unique:users`. (2) «تحديث آخر سجل OrderEditHistory» في SEC-E14-04، لأن التحديث يطال كل سجلات الطلب. وفي الملحق المقيّد تفاصيل التصحيح.

### 9.2 التصحيحات المُطبَّقة على المتن

1. §2.1 (صف v2): «لم تُعدّ» صارت 55 مساراً في مجموعة `seller`، مع استبعاد مجموعة `delivery-man` (40).
2. §2.2 (دورة حياة token): نُقل الادعاء عن غياب الانتهاء الزمني إلى الملحق المقيّد (SEC-E14-11)، وبقيت وقائع التخزين والتدوير.
3. §2.2 (حدّ الدخول): أُزيلت القيمة العددية للحدّ الافتراضي، وأُحيل مسار التسجيل العام إلى SEC-E14-09.
4. §2.2 و§2.3 و§6 (F13): نُقلت توزيعات نطاقات مسارات AI إلى SEC-E14-12. أُضيف استشهاد `SellerApiAuthMiddleware.php:69-70` إلى F13.
5. §2.3 (صف 4): أُزيل ذكر طريقة GET من مسار حذف الحساب (SEC-E14-14).
6. §2.8 (رموز الأجهزة): نُقلت تفاصيل كتابة الرمز من حامل الاعتماد إلى SEC-E14-13.
7. §3 (A1): التحويل بـ`Convert::usd` فعّال فقط عند `currency_model = multi_currency` (`Convert.php:11-18`)، وعدد مواضع الاستدعاء 44 في 9 متحكّمات مؤكَّد.
8. §3 (A14): عدد مرات `storefront` صُحِّح من 14 إلى 10 في النطاق المحدّد، وكلها في تعليقات أو توصيف توثيقي؛ الصفر لأسماء الأعمدة والمفاتيح مؤكَّد.
9. §6 (F03): أُضيفت الحالة الفعلية: القيود المفحوصة تُكتب بعملة الأساس فقط، والخطر كامن ومربوط ببداية GCC؛ بقيت الدرجة P0 لأنها حاجز إطلاق GCC.
10. §6 (F01): صيغة الأثر حُيِّدت، والتفاصيل في الملحق المقيّد.
11. §6 (F09): أُضيف دليل البحث عن أصناف الخدمات المالية في `tests` (صفر).
12. §6 (F12): أُحيل توجيه الرمز إلى SEC-E14-13.
13. §6 (F14): الاستيراد وحده لا يُفشل التحميل؛ الخطأ المحتمل عند الاستخدام داخل مقاطع مشروطة بالإضافة، والاستشهاد بـ`CLAUDE.md:36` أُضيف.
14. §7 (البند 11): أُضيف تناقض `CLAUDE.md` مع المستودع (`installation/backup/database.sql` و`Modules/Auction` غير موجودين).
15. §8: حُيِّد صف حدّ الدخول، وصُحِّح صف v2 إلى النطاق الفعلي.

### 9.3 بقايا الشكوك

- §2.2 (دورة حياة token): القيم الفعلية لصلاحية الجلسات في الإنتاج UNKNOWN.
- §2.4 (جدول العزل): المتحكّمات الكلاسيكية في لوحة الويب لم تُفحص دالةً دالة؛ وأُحيل الباقي إلى الملحق المقيّد كما هو.
- §4.2 و§6 (F03): لم يُقرأ `PayoutService` سطراً سطراً؛ الحكم على عدم الخلط مبني على الأسطر المفحوصة فقط.
- §6 (F09): تعداد الاختبارات الفعلية في `tests` (396 ملفاً) لم يُقرأ داخلياً؛ الحكم على الغياب مبني على البحث بالأسماء.
- §2.5 و§6 (F15): تحقق القيد الفريد في قاعدة الإنتاج UNKNOWN.

### 9.4 فجوات التغطية

- متحكّمات v2 لم تُفحص دالةً دالة؛ مجموعة `delivery-man` في v2 (40 مساراً) خارج النطاق.
- تقارير ضريبة البائع في `Modules/TaxModule` (`Api/v3/VendorTaxReportController`) خارج هذا الملف؛ تُحال إلى مراجعة الضرائب.
- عدد المواضع المنسوبة إلى Seller Center (25 موضعاً في 13 متحكّماً) و58 نموذجاً يحمل `seller_id`، وعدد مواضع الويب المرشّحة (45): لم تُعدّ مرة ثانية في هذا التحقق، وتُعدّ UNKNOWN في عدّ هذا الجدول.
- منطق كل منتِج insight وكل trigger/action في الأتمتة، وقوالب Blade، وتطبيق البائع خارج الملفات الأربعة: كما وُصفت في القسم 1.

### 9.5 عدد البنود المنقولة إلى الملحق المقيّد

- عدد البنود: 14 (SEC-E14-01 حتى SEC-E14-14)، وكلها مُحالة بنقطة واحدة في القسم 2.4.
- لم تُضَف أرقام جديدة في هذا التحقق؛ نُقلت صياغات إضافية (دورة حياة token، توزيع نطاقات AI، كتابة رمز الجهاز، طرق GET، حدّ الدخول الافتراضي) إلى البنود الموجودة في الملحق.

### 9.6 نتائج الفحوص الآلية

- `check_citations.py`: صفر أخطاء بعد التصحيح (298 استشهاداً بالملفات و5 استشهادات بالالتزام).
- `scan_sensitive.py`: صفر نتائج.
- مسح ألفاظ الاستغلال في المتن (القائمة المحددة في دليل التحقق): صفر بعد الإزالة.

ملاحظة: عدد عناصر 9.2 أكبر من عدد التصحيحات في 9.1 لأن بعض العناصر تعديل صياغة أو نطاق لا يغيّر ادعاءً بعينه.
