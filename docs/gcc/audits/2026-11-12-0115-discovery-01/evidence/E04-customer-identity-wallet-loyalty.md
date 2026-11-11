# E04 — هوية العميل والمحفظة والولاء والعناوين والموافقات (Customer identity, wallet, loyalty, addresses, consents)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129 (الخلفية)، lavkooapp@eedbbb8fd0303aa9fed5d4ff0ac45b3a409abf7a (تطبيق العميل — عقود القراءة فقط)، lavkooappseller@8ea00922e44db3d69059d46892f8f4f84349b580 (تطبيق البائع — عقود القراءة فقط). المستودع القديم `pharmacyksa98` غير متاح: كل ما يتوقف عليه `UNKNOWN (missing access: pharmacyksa98)`.

## 1. النطاق وما تم فحصه

**النطاق:** التغطية F (جانب العميل) من `DISCOVERY_PROMPT_01`: الهوية والمصادقة، المحفظة، الولاء، الإحالة، العناوين، الموافقات، وما يراه المشرف والبائع من بيانات العميل.

**ما فُحص (نحو 115 ملفاً؛ درجة القراءة — كاملة أو مقاطع — مبيَّنة لكل مجموعة):**

- النماذج (20): `User` بنسختيه، `Customer`، `GuestUser`، `WalletTransaction`، `LoyaltyPointTransaction`، `AddFundBonusCategories`، `ReferralCustomer`، `ReferrlaCustomer`، `CustomerWallet`، `CustomerWalletHistory`، `ShippingAddress`، `BillingAddress`، `AddressRegion`، `AddressCity`، `PhoneOrEmailVerification`، `PasswordReset`، `SupportTicket`، `Chatting`، `Subscription`، `CustomerSegment`.
- الكتّاب الماليون (6 دوال): `CustomerManager`، `CustomerTrait`، `WalletTransactionRepository`، `LoyaltyPointTransactionRepository`، والجزء المعني من `OrderManager`، و`module-helper.php` كاملاً.
- المتحكمات: `RestAPI/v1/auth/*` (4 من 6 كاملة، و2 تصفّحاً)، `RestAPI/v1/{Customer,UserWallet,UserLoyalty,OrderEdit}Controller` كاملة، المقاطع المعنية من `RestAPI/v1/OrderController` و`RestAPI/v2/{seller,delivery_man}` و`RestAPI/v3/seller/{Order,POS,Refund}Controller`، `Customer/Auth/*` (3 من 5 كاملة تقريباً)، `Customer/PaymentController` كاملاً، `Web/{UserWallet,UserLoyalty}Controller` كاملين ومقاطع `Web/{UserProfile,Web}Controller`، `Admin/Customer/*` (3 ملفات)، `Admin/Order/{Order,Refund}Controller` و`Vendor/{Order,Refund,Customer,POS}` في مواضع المحفظة والولاء.
- العناوين: `ShippingAddressService`، و8 من 27 ملفاً في `app/Services/Address` (`AddressSchema`, `AddressCountry`, `NationalAddress`, `AddressSnapshot`, `AddressCatalog`, `OrderAddressService`, `CheckoutAddressGuard`, `DeliveryRestrictionNotice`) ومقاطع `StorefrontAddressService`، و`docs/address/README.md` كاملاً.
- الترحيلات: 24 ترحيلاً تمس `users`, `wallet_transactions`, `loyalty_point_transactions`, `referral_customers`, `add_fund_bonus_categories`, `shipping_addresses`, `address_*`, `delivery_*_codes`, `guest_users`, `order_details_rewards`.
- الإعدادات والمسارات: `config/auth.php`, `config/passport.php`, `config/database.php`, `config/address.php`, `bootstrap/app.php`, `RouteServiceProvider`, `routes/rest_api/v1/api.php`, ومقاطع `routes/web/routes.php` و`routes/admin/routes.php` و`routes/vendor/routes.php` و`routes/rest_api/v3/seller.php`.
- تطبيقا Flutter: نماذج القراءة فقط (`profile_model.dart`, `wallet_transaction_model.dart`, `loyalty_point_model.dart`, `customer_model.dart`, `app_constants.dart`, `price_converter.dart`).

**أنماط البحث المسجَّلة (rg على جذر `lavkoo` باستثناء `vendor`, `node_modules`, `storage`):**

| النمط | النتيجة |
|---|---|
| `wallet_balance` | 176 سطراً (أُعيد العدّ في التحقق المستقل بـ grep: `app` 24، `resources` 110 منها ملفات الترجمة، `public` 28، `database` 6، `tests` 6، `docs` 2، `Modules` 0) |
| `loyalty_point\b` | 129 سطراً: `app` 55، `resources` 53، `public` 12، `database` 7، `docs` 2 |
| استدعاءات كتّاب المحفظة والولاء (`create_wallet_transaction(`, `createWalletTransaction(`, `addWalletTransaction(`, `create_loyalty_point_transaction(`, `addLoyaltyPointTransaction(`) | 20 موضع استدعاء للمحفظة و9 للولاء |
| كتابة مباشرة للعمودين (`increment/decrement('wallet_balance'|'loyalty_point')`, `'wallet_balance' =>`, `UPDATE users`) في `app`, `Modules`, `routes`, `database/seeds` | لا شيء خارج الكتّاب الستة |
| تعديل أو حذف صفوف الدفترين (`walletTransactionRepo->(delete|update)`, `WalletTransaction::where(...)->(delete|update)` ونظيراتها للولاء) | صفر نتائج |
| `consent|opt_in|optin|opt_out|unsubscrib|marketing_|newsletter|agree|terms_accepted|…` في `database/migrations` و`Modules/*/Database` | صفر نتائج |
| `unsubscrib` في `app`, `routes`, `resources/views`, `resources/themes` | صفر نتائج |
| `Schema::create('users'|'shipping_addresses'|'orders'|…)` في `database/migrations` | `carts` فقط |
| `Passport::|tokensExpireIn|personalAccessTokensExpireIn` في `app` و`config` | صفر نتائج |
| `expir` في ملفات الولاء الستة و`loyalty|wallet` في `app/Console` | لا شيء يخص انتهاء النقاط |
| `wallet_transactions|WalletTransaction|loyalty_point|create_wallet_transaction` في `tests` | صفر ملفات |

**ما لم يُفحص:** متحكمات بوابات الدفع (عدا مقطع callback واحد)، منطق السلة والكوبونات، محافظ البائع والمشرف والتسويات، نظام أدوار المشرفين، واجهات Flutter خارج نماذج القراءة، وحدة `Auction` (غير موجودة في المستودع رغم الإشارة إليها)، وأي قيمة من قاعدة بيانات الإنتاج أو `.env`.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 نموذج هوية العميل

- العميل صف واحد في `users`، ولا يوجد في المستودع ترحيل إنشاء لهذا الجدول: المخطط الأساسي يأتي من ملف `installation/backup/database.sql` الذي تشير إليه تعليمات المشروع (lavkoo:CLAUDE.md:22) وهو غير متتبَّع في المستودع (مجلد `installation` يحوي ملفين نصيين فقط). لذلك قيود التفرّد على `users.email` و`users.phone` على مستوى قاعدة البيانات `UNKNOWN`؛ ما في الكود هو قواعد تحقق في بعض مسارات التسجيل فقط (lavkoo:app/Http/Requests/Web/CustomerRegistrationRequest.php:25-34، lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:48-53).
- الأعمدة المضافة بالترحيلات: `cm_firebase_token`، `is_active`، `login_medium`/`social_id` (lavkoo:database/migrations/2021_09_08_211832_add_social_column_to_users_table.php:17-18)، `is_phone_verified`/`temporary_token`، `is_email_verified`، عدّادات الحظر، `referral_code`/`referred_by` (lavkoo:database/migrations/2023_06_05_115153_add_referral_code_and_referred_by_to_users_table.php:17-18)، `app_language` بقيمة افتراضية `en` (lavkoo:database/migrations/2023_10_21_113354_add_app_language_column_to_users_table.php:17)، و`email` قابل لـ NULL (lavkoo:database/migrations/2024_08_25_130313_modify_email_column_as_nullable_in_users_table.php:15). لا يوجد أي عمود دولة/متجر/كيان قانوني على العميل سوى نص حر `country` ضمن حقول العنوان القديمة (lavkoo:app/Models/User.php:78-82).
- **صنفان Eloquent على الجدول نفسه:** الحرّاس كلهم (`web`, `api`, `customer`) يستخدمون المزوّد `users` المربوط بـ `App\User` (lavkoo:config/auth.php:38-63 و lavkoo:config/auth.php:82-86)، بينما بقية الكود والعلاقات (مثل `Order::customer`) تستخدم `App\Models\User` (lavkoo:app/Models/Order.php:214-217). الصنفان يختلفان في `$fillable` و`$hidden` و`$casts`: `App\Models\User` يخفي `temporary_token` وتوكنات Firebase وبيانات البطاقة (lavkoo:app/Models/User.php:109-117) بينما `App\User` يخفي `password` و`remember_token` فقط (lavkoo:app/User.php:38-40). أي حقل GCC جديد أو قاعدة إخفاء يجب أن تُطبَّق على الصنفين. الصنفان `App\Models\Customer` (lavkoo:app/Models/Customer.php:9-12) و`App\Models\ReferrlaCustomer` (lavkoo:app/Models/ReferrlaCustomer.php:8-11) بلا أي استخدام (بحث `Models\\Customer\b|Customer::(where|find|create|query)` و`ReferrlaCustomer` في `app`, `routes`, `config`: لا نتائج غير التعريف).
- `App\User` يعرّف خاصية عامة `public mixed $email` (lavkoo:app/User.php:20) تحجب سمة `email` في Eloquent عند الوصول بصيغة الخاصية. الأثر الفعلي `INFERRED` (من دلالات PHP للخصائص المصرَّحة)؛ البحث عن `user()->email` في `app` و`resources` أعاد صفر نتائج، فالكود الحالي يتجنبها بالوصول المصفوفي.
- **الضيف:** صف في `guest_users` (`ip_address`, `fcm_token`) (lavkoo:database/migrations/2023_08_12_215346_create_guest_users_table.php:16-21). على الويب يُنشأ تلقائياً لكل جلسة غير مسجَّلة تمرّ بمسار يحمل `guestCheck` (lavkoo:app/Http/Middleware/GuestMiddleware.php:20-28)، وفي الـ API يصدره `GET /api/v1/get-guest-id` (lavkoo:app/Http/Controllers/RestAPI/v1/GeneralController.php:21-28). معرّف الضيف ومعرّف العميل يتشاركان العمود `customer_id` في `orders` و`carts` و`shipping_addresses` ويُفرَّق بينهما بـ `is_guest` (علاقة الطلب بالعميل: lavkoo:app/Models/Order.php:214-217). SEC-E04-17: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- «العميل العابر» في POS هو صف `users` بالمعرّف 0 (يُستثنى بقيمة بريد مخصّصة له في lavkoo:app/Repositories/CustomerRepository.php:192-196).

### 2.2 مسارات المصادقة

| المسار | الآلية | الدليل |
|---|---|---|
| جلسة الويب | الحارس `customer` (session) عبر `auth('customer')->attempt()`؛ ثم تحديث بيانات الجلسة ودمج سلة الضيف | lavkoo:app/Http/Controllers/Customer/Auth/CustomerAuthController.php:227-243 |
| حماية صفحات العميل | middleware `customer` يتحقق من الجلسة ومن `is_active` | lavkoo:app/Http/Middleware/CustomerMiddleware.php:21-29 |
| API التطبيق | Laravel Passport: personal access token باسم `LaravelAuthApp` عند الدخول؛ لا ضبط لمدد الصلاحية في الكود (البحث المسجَّل أعلاه) | lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:105-173، lavkoo:config/passport.php:16 |
| مسارات API المحمية | `auth:api` لمجموعة `customer` (الملف الشخصي، المحفظة، الولاء، الدفع بالمحفظة) | lavkoo:routes/rest_api/v1/api.php:342-432 |
| مسارات الضيف في الـ API | middleware `apiGuestCheck`: توكن صالح أو وجود `guest_id` | lavkoo:app/Http/Middleware/APIGuestMiddleware.php:21-33 |
| تحديد هوية الطالب | دالة واحدة `Helpers::getCustomerInformation` تُستخدم في الويب والـ API وتعيد المستخدم أو النص `offline` | lavkoo:app/Utils/Helpers.php:34-60 |
| OTP الهاتف/البريد | رمز من 6 أرقام يُخزَّن في `phone_or_email_verifications`؛ الإرسال عبر Zender (إعداد عام واحد) أو مزوّد SMS المركزي أو Firebase | lavkoo:app/Services/Web/CustomerAuthService.php:13-16، lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:243-296 |
| حدّ المحاولات | محدِّد `auth` لكل IP بقيمة سياسة `auth_attempts_per_minute` (الافتراضي 3)، وعدّادات `login_hit_count`/`otp_hit_count` | lavkoo:app/Providers/RouteServiceProvider.php:250-252، lavkoo:app/Services/Platform/PolicyRegistry.php:266-269 |
| الدخول الاجتماعي | الويب عبر Socialite؛ الـ API بالتحقق من توكن Google/Facebook/Apple ثم مطابقة البريد | lavkoo:app/Http/Controllers/Customer/Auth/SocialAuthController.php:43-105، lavkoo:app/Http/Controllers/RestAPI/v1/auth/SocialAuthController.php:215-310 |
| الإحالة عند التسجيل | `referral_code` يُولَّد لكل عميل (20 حرفاً)، و`referred_by` يُحفظ عند التسجيل | lavkoo:app/Utils/Helpers.php:688-695، lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:63-77 |

**إعدادات الهوية المحرِّكة للسلوك (كلها عامة، وقيمها في الإنتاج `UNKNOWN`):** في `login_setups`: `login_options` (تفعيل `otp_login` و`manual_login`) (lavkoo:app/Http/Controllers/Customer/Auth/CustomerAuthController.php:70-78)، و`phone_verification` و`email_verification` حيث يُلغي تحقق الهاتف تحقق البريد (lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:140-142). في `business_settings`: `maximum_login_hit` و`temporary_login_block_time` (lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:119-120)، و`maximum_otp_hit` و`otp_resend_time` و`temporary_block_time` (lavkoo:app/Traits/CustomerTrait.php:96-98). لا يوجد لأي منها نطاق دولة أو متجر.

**دمج الضيف في العميل:** يقتصر على (أ) السلة عند الدخول — نقل صفوف `carts` من معرّف الضيف إلى العميل (lavkoo:app/Utils/CartManager.php:23-50 للويب، و lavkoo:app/Http/Controllers/RestAPI/v1/CartController.php:342-370 للـ API)، و(ب) عنواني الطلب الجاري عندما يختار الضيف إنشاء حساب أثناء الشراء (lavkoo:app/Utils/OrderManager.php:443-447، lavkoo:app/Http/Controllers/Web/WebController.php:599-626، lavkoo:app/Utils/module-helper.php:27-53). لا يوجد كود يعيد إسناد طلبات الضيف السابقة إلى الحساب (بحث `Order::where(.*guest|orders.*is_guest.*update` في `app`: لا تحديث لملكية الطلبات).

**حذف الحساب:** حذف نهائي لصف `users` (`$user->delete()`) من الـ API (lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:118-141)، ومن الويب (lavkoo:app/Http/Controllers/Web/UserProfileController.php:117-149)، ومن لوحة الإدارة (lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:289-302 عبر lavkoo:app/Repositories/CustomerRepository.php:179-183). الشرط الوحيد عدم وجود طلبات جارية (ونشاط مزاد). لا يُفحص رصيد المحفظة أو النقاط، ولا حذف منطقي (لا `SoftDeletes` في lavkoo:app/Models/User.php:56-58)، فتبقى صفوف الدفترين والطلبات بمعرّف عميل يتيم. حظر العميل من الإدارة يلغي توكناته (lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:151-165، lavkoo:app/Repositories/CustomerRepository.php:211-215).

**مسائل أمنية في هذه المسارات** موثّقة في الملحق المقيّد فقط:

- SEC-E04-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E04-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E04-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E04-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E04-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E04-07: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E04-09: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E04-12: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E04-13: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E04-14: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور (جزء منها موثّق علناً في lavkoo:docs/address/README.md:66).
- SEC-E04-15: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 2.3 `users.wallet_balance` و`users.loyalty_point` — جرد كامل للقراءة والكتابة

**تعريف العمودين:** أُضيفا `float` قابلين لـ NULL (lavkoo:database/migrations/2022_05_12_104511_add_two_column_to_users_table.php:16-19)، ثم `loyalty_point` إلى `decimal(18,4)` بافتراضي 0 (lavkoo:database/migrations/2025_04_16_154104_modify_loyalty_point_column_in_users_table.php:14-16) و`wallet_balance` إلى `decimal(18,12)` (lavkoo:database/migrations/2025_07_13_021823_modify_wallet_balance_column_in_users_table.php:14-16)، ثم كلاهما إلى `DECIMAL(40,20)` (lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:95-96). في النموذجين يُحوَّلان إلى `float` (lavkoo:app/Models/User.php:154-155، lavkoo:app/User.php:52-53) وهما ضمن `$fillable` في `App\Models\User` (lavkoo:app/Models/User.php:95-96). تطبيق أي من هذه الترحيلات على الإنتاج `UNKNOWN`.

**أ) مواضع الكتابة — `wallet_balance` (4 مواضع، هي 4 نسخ شبه متطابقة من الدالة نفسها):**

| # | الكاتب | الموضع | معاملة DB | قفل صف | Idempotent | صف دفتر مرافق |
|---|---|---|---|---|---|---|
| A | `CustomerManager::create_wallet_transaction` | lavkoo:app/Utils/CustomerManager.php:36-91 (الكتابة في السطر 77) | نعم، للحفظين فقط (79-90) | لا | لا | نعم دائماً |
| B | `CustomerTrait::createWalletTransaction` | lavkoo:app/Traits/CustomerTrait.php:42-92 (السطر 78) | نعم (80-91) | لا | لا | نعم دائماً |
| C | `WalletTransactionRepository::addWalletTransaction` | lavkoo:app/Repositories/WalletTransactionRepository.php:107-158 (السطر 148) | نعم (145-156) | لا | لا | نعم دائماً |
| D | `OrderManager::createWalletTransaction` | lavkoo:app/Utils/OrderManager.php:2299-2349 (السطر 2335) | نعم (2337-2348) | لا | لا | نعم دائماً |

النمط المشترك للكتّاب الأربعة: تُحسب قيمة الرصيد الجديد داخل الدالة وتُكتب على العمود. لا يستخدم أي منها قفلاً صفّياً ولا `increment/decrement` ولا قيداً على الرصيد السالب في الكود، ولا مفتاح تكرار (بحث `lockForUpdate|sharedLock|FOR UPDATE` في `app` و`Modules`: المواضع في المنتجات وترقيم الطلبات وقنوات البيع وتسوية MyFatoorah، وصفر في كتّاب المحفظة والولاء)؛ `transaction_id` معرّف UUID عشوائي يُولَّد عند كل استدعاء (lavkoo:app/Utils/CustomerManager.php:44). عند الفشل تعيد الدوال `false` بعد `info()` أو بصمت (lavkoo:app/Repositories/WalletTransactionRepository.php:154-157). SEC-E04-10: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

**ب) مواضع استدعاء الكتّاب — المحفظة (20 موضعاً):**

| الغرض | `transaction_type` | `reference` المكتوب | الكاتب | الموضع |
|---|---|---|---|---|
| دفع طلب بالمحفظة (API) | `order_place` | `order payment` | A | lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:495 |
| دفع طلب بالمحفظة (ويب) | `order_place` | `order payment` | A | lavkoo:app/Http/Controllers/Web/WebController.php:881 |
| POS لوحة الإدارة | `order_place` | `order_place_in_pos` | B | lavkoo:app/Http/Controllers/Admin/POS/POSOrderController.php:117 |
| POS لوحة البائع | `order_place` | `order_place_in_pos` | B | lavkoo:app/Http/Controllers/Vendor/POS/POSOrderController.php:123 |
| POS تطبيق البائع | `order_place` | `order_place_in_pos` | B | lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:323 |
| شحن المحفظة عبر بوابة | `add_fund` | `add_funds_to_wallet` | A | lavkoo:app/Utils/module-helper.php:307 |
| إضافة يدوية من المشرف | `add_fund_by_admin` | نص من الطلب أو `add_fund_by_admin` | C | lavkoo:app/Http/Controllers/Admin/Customer/CustomerWalletController.php:98 |
| استرداد إلى المحفظة | `order_refund` | `order_refund` | B | lavkoo:app/Services/RefundStatusService.php:83 |
| إلغاء طلب POS مدفوع بالمحفظة (إدارة) | `order_refund` | `pos_order_cancel_{id}` | B | lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:702 |
| إلغاء طلب POS مدفوع بالمحفظة (بائع) | `order_refund` | `pos_order_cancel_{id}` | B | lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:661 |
| سداد مستحق تعديل الطلب | `due_payment_for_order` | `reduce_wallet_amount` | A | lavkoo:app/Traits/OrderEditManager.php:719 |
| إرجاع فرق تعديل الطلب (إدارة) | `return_order_amount_by_admin` | `add_wallet_amount` | A | lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:267 |
| إرجاع فرق تعديل الطلب (بائع) | `return_order_amount_by_admin` | `add_wallet_amount` | A | lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:240 |
| تحويل نقاط إلى محفظة (API) | `loyalty_point` | `point_to_wallet` | A | lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:99 |
| تحويل نقاط إلى محفظة (ويب) | `loyalty_point` | `point_to_wallet` | B | lavkoo:app/Http/Controllers/Web/UserLoyaltyController.php:128 |
| مكافأة إحالة (دالة مشتركة) | `add_fund_by_admin` | `earned_by_referral` | D | lavkoo:app/Utils/OrderManager.php:877 |
| مكافأة إحالة (لوحة البائع) | `add_fund_by_admin` | `earned_by_referral` | C | lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:699 |
| مكافأة إحالة (تطبيق البائع، موضعان) | `add_fund_by_admin` | `earned_by_referral` | A | lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:475 و lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:772 |
| مكافأة إحالة (تطبيق المندوب) | `add_fund_by_admin` | `earned_by_referral` | A | lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:249 |

**ج) مواضع القراءة — `wallet_balance`:** من أسطر `app` الـ 24: 4 كتابة، 4 قراءة تمهيدية داخل الكتّاب، 8 قراءة عمل، 1 مفتاح استجابة، 4 تعريفات نموذج، 1 docblock، 2 مفتاحا ترجمة لا علاقة لهما بالعمود (lavkoo:app/Http/Controllers/Admin/Order/DeliverySyriaDispatchController.php:118، lavkoo:app/Http/Controllers/Web/UserLoyaltyController.php:124).

| # | القارئ | الغرض | الموضع |
|---|---|---|---|
| R1 | API `GET /api/v1/customer/wallet/list` | يعيد `total_wallet_balance` | lavkoo:app/Http/Controllers/RestAPI/v1/UserWalletController.php:31 و lavkoo:app/Http/Controllers/RestAPI/v1/UserWalletController.php:72 |
| R2 | API `GET /api/v1/customer/order/place-by-wallet` | فحص كفاية الرصيد | lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:444 |
| R3 | صفحة المحفظة (ويب) | عرض الرصيد | lavkoo:app/Http/Controllers/Web/UserWalletController.php:25 |
| R4 | إتمام الشراء بالمحفظة (ويب) | فحص كفاية الرصيد | lavkoo:app/Http/Controllers/Web/WebController.php:828 |
| R5 | POS الإدارة | فحص كفاية الرصيد | lavkoo:app/Http/Controllers/Admin/POS/POSOrderController.php:116 |
| R6 | POS البائع (لوحة) | فحص كفاية الرصيد | lavkoo:app/Http/Controllers/Vendor/POS/POSOrderController.php:122 |
| R7 | POS البائع (API v3) | فحص كفاية الرصيد | lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:322 |
| R8 | سداد مستحق تعديل الطلب | فحص كفاية الرصيد | lavkoo:app/Traits/OrderEditManager.php:713 |
| R9 | قوالب Blade (15 سطراً) | عرض/مقارنة | lavkoo:resources/themes/default/web-views/checkout/payment.blade.php:92، lavkoo:resources/themes/default/web-views/checkout/payment.blade.php:318، lavkoo:resources/themes/default/web-views/order/partials/_choose-payment-method-modal.blade.php:1، lavkoo:resources/themes/default/web-views/partials/_choose-payment-method-order-details.blade.php:1، lavkoo:resources/themes/default/auction/web-views/partials/_cod-commission-payment-modal.blade.php:3، lavkoo:resources/themes/default/auction/web-views/product/_participate-entry-info.blade.php:63، lavkoo:resources/themes/default/auction/web-views/product/details/_claim-checkout.blade.php:443-459، lavkoo:resources/views/admin-views/customer/customer-view.blade.php:74، lavkoo:resources/views/admin-views/pos/partials/_admin-pos-customer-info.blade.php:32، lavkoo:resources/views/vendor-views/pos/partials/_vendor-pos-customer-info.blade.php:32 |
| R10 | **قرّاء ضمنيون عبر تسلسل النموذج كاملاً** (لا يظهرون في بحث نصي) | `GET /api/v1/customer/info` يعيد نموذج المستخدم كله | lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:59-86 |
| R11 | ضمني: بحث عملاء POS لتطبيق البائع | `->get()->toArray()` على `User` | lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:179-202 |
| R12 | ضمني: علاقة `customer` داخل قوائم الطلبات لتطبيق البائع وتطبيق المندوب | النموذج كاملاً ضمن الطلب | lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:92-98، lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:96 |

**د) قرّاء Flutter (عقد يجب ألا ينكسر):**

| التطبيق | المفتاح المقروء | الموضع | نقطة الـ API |
|---|---|---|---|
| العميل | `wallet_balance`, `loyalty_point` | lavkooapp:lib/features/profile/domain/models/profile_model.dart:57-64، lavkooapp:lib/features/profile/controllers/profile_contrroller.dart:35-36 | `/api/v1/customer/info` (lavkooapp:lib/utill/app_constants.dart:103) |
| العميل | `total_wallet_balance`, `wallet_transaction_list` | lavkooapp:lib/features/wallet/domain/models/wallet_transaction_model.dart:28-39 | `/api/v1/customer/wallet/list` (lavkooapp:lib/utill/app_constants.dart:173) |
| العميل | `total_loyalty_point` | lavkooapp:lib/features/loyaltyPoint/domain/models/loyalty_point_model.dart:28-29 | `/api/v1/customer/loyalty/list` (lavkooapp:lib/utill/app_constants.dart:174) |
| البائع | `wallet_balance` لكل عميل في POS | lavkooappseller:lib/features/pos/domain/models/customer_model.dart:50-54، lavkooappseller:lib/features/pos/controllers/customer_controller.dart:63 | `/api/v3/seller/pos/customers` (lavkooappseller:lib/utill/app_constants.dart:90) |

**هـ) `loyalty_point` — الكتابة والقراءة:** موضعا كتابة فقط، بالنمط نفسه للكتّاب الأربعة (وصف دفتر مرافق دائماً): `CustomerManager::create_loyalty_point_transaction` (lavkoo:app/Utils/CustomerManager.php:93-137؛ الكتابة 124) و`LoyaltyPointTransactionRepository::addLoyaltyPointTransaction` (lavkoo:app/Repositories/LoyaltyPointTransactionRepository.php:114-160؛ الكتابة 150). من أسطر `app` الـ 55: 2 كتابة، 2 قراءة تمهيدية، 13 قراءة عمل، 5 تعريفات، و33 سطراً تستخدم النص `loyalty_point` كقيمة `transaction_type` أو `reward_type` لا كعمود.

| القراءة (13) | الموضع |
|---|---|
| عرض الرصيد وفحص التحويل (API) | lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:60 و lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:93 |
| عرض الرصيد وفحص التحويل (ويب) | lavkoo:app/Http/Controllers/Web/UserLoyaltyController.php:33 و lavkoo:app/Http/Controllers/Web/UserLoyaltyController.php:118 |
| أهلية طلب الاسترداد (API) | lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:515 و lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:576 |
| أهلية طلب الاسترداد (ويب) | lavkoo:app/Http/Controllers/Web/UserProfileController.php:861 و lavkoo:app/Http/Controllers/Web/UserProfileController.php:900 |
| قرار الاسترداد (إدارة/بائع/API v2/API v3) | lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:128، lavkoo:app/Http/Controllers/Vendor/RefundController.php:147، lavkoo:app/Http/Controllers/RestAPI/v2/seller/RefundController.php:138، lavkoo:app/Http/Controllers/RestAPI/v3/seller/RefundController.php:162 |
| قابلية تعديل الطلب | lavkoo:app/Traits/OrderEditManager.php:506 |
| عرض في لوحة الإدارة (Blade) | lavkoo:resources/views/admin-views/customer/customer-view.blade.php:81 |

### 2.4 بنية دفتر المحفظة ووحدة التخزين النقدية

**الجدول `wallet_transactions`:** `id`, `user_id` (قابل لـ NULL بلا مفتاح أجنبي فعلي), `transaction_id` (UUID), `credit`, `debit`, `admin_bonus`, `balance`, `transaction_type`, `reference`, الطوابع الزمنية (lavkoo:database/migrations/2022_05_12_104247_create_wallet_transactions_table.php:16-27)، ثم `payment_method` (lavkoo:database/migrations/2023_08_26_173523_add_payment_method_column_to_wallet_transactions_table.php:16-18)، ثم توسيع الدقة إلى `decimal(21,12)` ثم `DECIMAL(40,20) NOT NULL` بلا قيمة افتراضية (lavkoo:database/migrations/2026_01_22_165803_update_wallet_transactions_decimal_precision.php:14-19، lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:97-100).

ما **لا** يحتويه الدفتر: عمود عملة، سعر صرف، متجر/دولة/كيان قانوني، معرّف طلب أو مجموعة طلبات، معرّف طلب دفع أو استرداد، هوية المنفّذ (مشرف/بائع/نظام)، مفتاح idempotency أو فهرس فريد. قيم `transaction_type` السبع المستخدمة: `add_fund`, `add_fund_by_admin`, `order_place`, `order_refund`, `loyalty_point`, `due_payment_for_order`, `return_order_amount_by_admin` (lavkoo:app/Http/Controllers/RestAPI/v1/UserWalletController.php:96-104). قيم `reference` نصوص ثابتة في 17 من 20 موضع استدعاء (الجدول في 2.3-ب)، ونص حر يدخله المشرف في موضع واحد؛ الموضعان الوحيدان المرتبطان برقم طلب هما إرجاع POS بالمرجع `pos_order_cancel_{id}`، وهما أيضاً الوحيدان اللذان يفحصان التكرار قبل الإيداع (lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:696-708، lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:655-667).

**قيم `transaction_type` و`reference` جزء من العقد مع الواجهات:** ترشيح سجل المحفظة في الـ API والويب يعتمد على قيمتي `reference` الثابتتين `add_funds_to_wallet` و`earned_by_referral` كأنواع زائفة (`added_via_payment_method`, `earned_by_referral`) (lavkoo:app/Http/Controllers/RestAPI/v1/UserWalletController.php:53-64، lavkoo:app/Http/Controllers/Web/UserWalletController.php:94-113)، وتطبيق العميل يرسل هذه الأنواع ويقرأ كل أعمدة صف الدفتر (lavkooapp:lib/utill/app_constants.dart:366-375، lavkooapp:lib/features/wallet/domain/models/wallet_transaction_model.dart:44-55). أي ربط مستقبلي للحركة بطلب أو دفعة يجب أن يكون في أعمدة جديدة لا بتغيير هذه القيم.

**عمود `balance` لا يساوي الرصيد الفعلي في صفوف `add_fund`:** يُكتب `balance = الرصيد + credit − debit` بينما يُكتب في `users.wallet_balance` المجموع نفسه **مضافاً إليه** `admin_bonus` (lavkoo:app/Utils/CustomerManager.php:74 مقابل lavkoo:app/Utils/CustomerManager.php:77). لذلك صيغة إعادة البناء الصحيحة هي Σ(`credit` + `admin_bonus` − `debit`) لا Σ(`credit` − `debit`). تقرير الإدارة وتصديره يحسبان «الرصيد» بـ `total_credit − total_debit` فقط (lavkoo:app/Http/Controllers/Admin/Customer/CustomerWalletController.php:138-140).

**وحدة التخزين ليست «ريالاً» بحكم الكود، بل تتبع إعدادين في قاعدة البيانات:**

- كل المبالغ تمرّ عبر محوّلات تتفرع على `currency_model`: إذا كان `multi_currency` تُخزَّن المبالغ مقسومة على (سعر العملة الافتراضية ÷ سعر USD)، أي بوحدة أساس USD؛ وإلا تُخزَّن كما هي بالعملة الافتراضية (lavkoo:app/Utils/Convert.php:9-22، lavkoo:app/Utils/currency.php:50-68، والثابتان في lavkoo:app/Utils/constant.php:14 و lavkoo:app/Utils/constant.php:28).
- قيمتا `currency_model` و`system_default_currency` وأسعار جدول `currencies` في الإنتاج `UNKNOWN`؛ البذرة الوحيدة في المستودع تضبط `system_default_currency = 1` فقط (lavkoo:database/seeds/BusinessSettingSeeder.php:15-19).
- القرّاء يعاملون القيمة المخزَّنة كوحدة أساس ويحوّلونها عند العرض: لوحة الإدارة وPOS عبر `usdToDefaultCurrency` (lavkoo:resources/views/admin-views/customer/customer-view.blade.php:74، lavkoo:resources/views/admin-views/pos/partials/_admin-pos-customer-info.blade.php:32)، واجهة المتجر عبر `webCurrencyConverter` (lavkoo:resources/themes/default/web-views/checkout/payment.blade.php:92)، والـ API يعيد القيمة الخام (lavkoo:app/Http/Controllers/RestAPI/v1/UserWalletController.php:72) ليحوّلها التطبيق (lavkooapp:lib/helper/price_converter.dart:69-73).
- قرينة غير حاسمة (`INFERRED`): تطبيق العميل يفرض مسار العملات المتعددة دائماً (`singleCurrency = false`) ويعرض بلا كسور عشرية (lavkooapp:lib/helper/price_converter.dart:65 و lavkooapp:lib/helper/price_converter.dart:75-76).

**النسخ الأربع من الكاتب لا تتطابق في التحويل** (كلها تتطابق عندما لا يكون النموذج `multi_currency` وسعر العملة الافتراضية 1؛ وتتباعد خلاف ذلك):

| البند | A | B | C | D |
|---|---|---|---|---|
| دالة التحويل إلى وحدة التخزين | `Convert::usd` (lavkoo:app/Utils/CustomerManager.php:70-71) | `currencyConverter` (lavkoo:app/Traits/CustomerTrait.php:71-72) | `currencyConverter` (lavkoo:app/Repositories/WalletTransactionRepository.php:137-138) | `currencyConverter` (lavkoo:app/Utils/OrderManager.php:2328-2329) |
| صيغة نوع `loyalty_point` | (نقاط ÷ المعدل) × `Convert::default(1)` (lavkoo:app/Utils/CustomerManager.php:58-59) | `localToDefaultCurrency(loyaltyPointToLocalCurrency())` المعتمدة على سعر عملة الجلسة (lavkoo:app/Traits/CustomerTrait.php:64-65، lavkoo:app/Utils/currency.php:140-170) | (نقاط ÷ المعدل) × `currencyConverter(1)` (lavkoo:app/Repositories/WalletTransactionRepository.php:130-131) | كصيغة A (lavkoo:app/Utils/OrderManager.php:2321-2322) |
| الأنواع المعالَجة | 7 أنواع (lavkoo:app/Utils/CustomerManager.php:53-69) | 5 (lavkoo:app/Traits/CustomerTrait.php:59-69) | 6 (lavkoo:app/Repositories/WalletTransactionRepository.php:125-135) | 5 (lavkoo:app/Utils/OrderManager.php:2316-2326) |
| حساب الـ bonus | `Helpers::add_fund_to_wallet_bonus` | نفسها | نسخة خاصة (lavkoo:app/Repositories/WalletTransactionRepository.php:160-179) | نفسها |

أمثلة تباعد مثبتة بالقراءة: (1) تحويل النقاط إلى محفظة يستخدم A في الـ API وB في الويب، فالصيغتان مختلفتان للعملية نفسها؛ (2) نوعا `due_payment_for_order` و`return_order_amount_by_admin` يُمرَّر لهما مبلغ الطلب المخزَّن مباشرة ثم يُحوَّل مرة أخرى بـ `Convert::usd` (lavkoo:app/Traits/OrderEditManager.php:719، lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:267)، بخلاف دفع الطلب الذي يُحوَّل أولاً بـ `Convert::default` (lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:495)؛ (3) POS تطبيق البائع يحوّل المبلغ ثم يمرّره إلى كاتب يحوّله ثانية (lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:287 ثم lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:323 و lavkoo:app/Traits/CustomerTrait.php:72). أثر هذه الفروق على بيانات الإنتاج `UNKNOWN` لأنه يتوقف على `currency_model`.

**الكسور العشرية:** `Convert::default` يقرّب إلى خانتين ثابتتين (lavkoo:app/Utils/Convert.php:62) وهو في مسار خصم دفع الطلب بالمحفظة؛ `usdToDefaultCurrency` يقطع (floor) إلى `decimal_point_settings` العام (lavkoo:app/Utils/currency.php:101-103) وهو في مسار الشحن والاسترداد؛ والحساب كله بـ `float` ثم تخزين `DECIMAL(40,20)`. لا يوجد مفهوم «عدد خانات العملة» لكل عملة، وهو لازم لـ KWD/BHD/OMR (ثلاث خانات).

### 2.5 تدفقات المحفظة

1. **الشحن عبر بوابة:** الطلب من الويب أو الـ API (lavkoo:routes/web/routes.php:461، lavkoo:routes/rest_api/v1/api.php:448-450) يتحقق من `add_funds_to_wallet` ومن الحدّين `minimum_add_fund_amount`/`maximum_add_fund_amount` وينشئ طلب دفع بخاصية `attribute = add_funds_to_wallet` وhook نجاح `add_fund_to_wallet_success` (lavkoo:app/Http/Controllers/Customer/PaymentController.php:455-555). عند النجاح يحوّل الـ hook المبلغ ويستدعي الكاتب A بنوع `add_fund` ومرجع ثابت (lavkoo:app/Utils/module-helper.php:299-326). يُحفظ في صف الدفتر اسم طريقة الدفع فقط في `payment_method` (lavkoo:app/Utils/CustomerManager.php:47)، لا معرّف طلب الدفع ولا مرجع عملية البوابة، وتُعالَج الاستدعاءات المتكررة للـ hook في كل بوابة على حدة. SEC-E04-11: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
2. **قواعد الـ bonus:** جدول `add_fund_bonus_categories` (نوع `percentage`/ثابت، حد أدنى للمبلغ، سقف، فترة، حالة) (lavkoo:database/migrations/2023_08_12_102355_create_add_fund_bonus_categories_table.php:16-28). تُختار القواعد النشطة ذات أعلى حد أدنى مستوفى ويؤخذ أعلى bonus (lavkoo:app/Utils/Helpers.php:697-715). مبالغ القواعد تُخزَّن بوحدة التخزين عبر `currencyConverter` (lavkoo:app/Http/Controllers/Admin/Customer/CustomerWalletController.php:160-175). القواعد عامة بلا متجر أو عملة. لا يوجد في الكاتب قيد محاسبي مقابل للـ bonus (الكتّاب الأربعة لا يمسّون `admin_wallets`).
3. **الدفع بالمحفظة عند الشراء:** يُحسب المبلغ من السلة، يُقارَن بالرصيد، يُنشأ الطلب بحالة `confirmed` و`payment_status = paid` و`payment_method = pay_by_wallet` و`transaction_ref` فارغ داخل معاملة الطلب، ثم **بعدها** يُستدعى الخصم في خطوة مستقلة (API: lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:416-501؛ ويب: lavkoo:app/Http/Controllers/Web/WebController.php:806-896؛ بيانات الطلب في lavkoo:app/Utils/OrderManager.php:1071-1077). ترتيب الخصم وتبعاته في SEC-E04-10. خصم واحد يغطي كل طلبات المجموعة (الطلب يُقسَّم بحسب البائع) دون أي ربط في الدفتر.
4. **POS:** القنوات الثلاث تخصم قبل إنشاء الطلب وبمرجع ثابت (المواضع في 2.3-ب). SEC-E04-08: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
5. **الاسترداد إلى المحفظة:** من لوحة الإدارة فقط، عند حالة `refunded` وإعداد `wallet_add_refund = 1` وطريقة `customer_wallet` (lavkoo:app/Services/RefundStatusService.php:81-89، lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:109-175، والإعداد في lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:489). تطبيق البائع يقرّ أو يرفض فقط (lavkoo:app/Http/Controllers/RestAPI/v3/seller/RefundController.php:129-200). صف الدفتر يحمل المرجع الثابت `order_refund`؛ الربط بالطلب موجود في `refund_transactions` لا في دفتر المحفظة.
6. **إلغاء طلب مدفوع بالمحفظة:** لا يوجد إرجاع تلقائي إلا لطلبات POS (المواضع أعلاه). العميل يستطيع الإلغاء فقط لطلبات COD المعلّقة (lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:159-168، lavkoo:app/Http/Controllers/Web/UserProfileController.php:831-844)، وتحديث الحالة من الإدارة أو البائع إلى `canceled` لطلب `pay_by_wallet` عادي لا يولّد حركة محفظة (lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:691-708). البحث عن `pay_by_wallet` وعن `'order_refund'` في `app` لا يُظهر أي إيداع استرداد خارج `RefundStatusService` وموضعي إرجاع POS.
7. **تعديل الطلب:** السداد بالمحفظة (lavkoo:app/Traits/OrderEditManager.php:711-737) والإرجاع إليها (lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:247-287). SEC-E04-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
8. **التعديل اليدوي من المشرف:** إيداع فقط (`amount` بين 0.01 و10,000,000) (lavkoo:app/Http/Requests/Admin/AddFundRequest.php:40-46، lavkoo:app/Http/Controllers/Admin/Customer/CustomerWalletController.php:96-123). لا توجد عملية خصم يدوية، ولا يُسجَّل المشرف المنفّذ: لا عمود له في الدفتر، ومراقب التدقيق غير مربوط بـ `User` ولا بـ `WalletTransaction` (lavkoo:app/Providers/ObserverServiceProvider.php:70-76)، والبحث عن `audit` في `app/Http/Controllers/Admin/Customer` والكتّاب أعاد صفر نتائج.
9. **نظام محفظة قديم متروك:** جدولا `customer_wallets` (`balance`, `royality_points`) و`customer_wallet_histories` ما زالا في المخطط (lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:29-31) ولهما مسار ويب حيّ يحوّل النقاط القديمة إلى رصيد قديم (lavkoo:routes/web/routes.php:453-454، lavkoo:app/Http/Controllers/Customer/RewardPointController.php:11-33). لا يوجد كاتب آخر لهما في الكود. وجود أرصدة فعلية فيهما في الإنتاج `UNKNOWN`.

### 2.6 الولاء والإحالة

**الدفتر `loyalty_point_transactions`:** `user_id`, `transaction_id`, `credit`, `debit`, `balance`, `reference`, `transaction_type` (lavkoo:database/migrations/2022_05_14_063309_create_loyalty_point_transactions_table.php:16-26). الأنواع: `order_place` (كسب)، `point_to_wallet` (تحويل)، `refund_order` (عكس) (lavkoo:app/Repositories/LoyaltyPointTransactionRepository.php:123-129). `reference` يحمل رقم الطلب في الكسب والعكس، و`transaction_id` لحركة المحفظة في التحويل — أي أن دفتر الولاء، بخلاف دفتر المحفظة، مربوط بالطلب.

**مفاتيح الإعدادات (كلها `business_settings` عامة):** `loyalty_point_status`, `loyalty_point_for_each_order`, `loyalty_point_exchange_rate`, `loyalty_point_item_purchase_point`, `loyalty_point_minimum_point`, `loyalty_point_each_order_status` (lavkoo:app/Utils/OrderManager.php:2098-2108)؛ وللمحفظة والإحالة: `wallet_status`, `add_funds_to_wallet`, `minimum_add_fund_amount`, `maximum_add_fund_amount`, `ref_earning_status`, `ref_earning_exchange_rate`, `ref_earning_customer` (lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:500-514)، و`wallet_add_refund` (lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:489). القيم في الإنتاج `UNKNOWN`. تُنشر للتطبيق كقيم مفردة في `/api/v1/config` (lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:223-226 و lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:239-241).

**قاعدة الكسب:** النقاط = `(int)(المبلغ × loyalty_point_item_purchase_point ÷ 100)` حيث المبلغ = قيمة الطلب ناقص الشحن محوَّلة إلى العملة الافتراضية (lavkoo:app/Repositories/LoyaltyPointTransactionRepository.php:123-124)، وتُمنح عند حالة `delivered`. لكن شروط المنح تختلف بحسب القناة:

| القناة | الشروط | الموضع |
|---|---|---|
| لوحة الإدارة | وجود صف `order_details_rewards` غير مسلَّم، `loyalty_point_status`، `loyalty_point_for_each_order`، ليس ضيفاً؛ ثم `reward_delivered = 1` | lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:726-734 |
| لوحة البائع | كالإدارة + `wallet_status` | lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:678-687 |
| API البائع v3 (موضعان) | `wallet_status` و`loyalty_point_status` وليس ضيفاً؛ دون `order_details_rewards` ولا `loyalty_point_for_each_order` | lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:459-463، lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:757-761 |
| API البائع v2 | شروط مختلفة عن الموضع المرجعي. SEC-E04-18: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | lavkoo:app/Http/Controllers/RestAPI/v2/seller/OrderController.php:267-271 |
| تطبيق المندوب | لا منح للنقاط عند التسليم (لا استدعاء لكاتب الولاء في الملف) | lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:171-251 |

لقطة المكافأة لكل بند طلب تُحفظ عند إنشاء الطلب في `order_details_rewards` (lavkoo:app/Utils/OrderManager.php:1489-1508، lavkoo:database/migrations/2025_07_24_104352_create_order_details_rewards_table.php:14-23) بصيغة تختلف عن صيغة المنح الفعلية، وهي التي تُخصم عند الاسترداد (lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:126-130 و lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:161-163).

**التحويل إلى المحفظة:** شرطاه `wallet_status` و`loyalty_point_status`، والحد الأدنى `loyalty_point_minimum_point`، والمعدل `loyalty_point_exchange_rate` (نقاط لكل وحدة عملة). تُنفَّذ الحركتان في كاتبين منفصلين: إيداع المحفظة ثم خصم النقاط (تبعات الترتيب في SEC-E04-10) (API: lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:73-110؛ ويب: lavkoo:app/Http/Controllers/Web/UserLoyaltyController.php:109-138). الـ API يشترط عدداً صحيحاً (lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:84-86) والويب يقبل `numeric` (lavkoo:app/Http/Requests/Web/LoyaltyExchangeCurrencyRequest.php:19-24)، والصيغتان مختلفتان (2.4). **لا يوجد انتهاء صلاحية للنقاط** (البحث المسجَّل في القسم 1).

**الإحالة:** عند التسجيل بكود إحالة يُحفظ `referred_by` وتُنشأ لقطة في `referral_customers` بخصم المُحال وقيمة مكافأة المُحيل (lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:79-89، lavkoo:app/Services/ReferByEarnCustomerService.php:25-43، lavkoo:database/migrations/2025_06_03_171327_create_referral_customers_table.php:14-31). خصم المُحال يُطبَّق على أول طلب ضمن مدة الصلاحية (lavkoo:app/Utils/OrderManager.php:825-864). مكافأة المُحيل تُودَع في **المحفظة** عند أول طلب مسلَّم ومدفوع للمُحال، بقيمة الإعداد الحيّ `ref_earning_exchange_rate` لا اللقطة (lavkoo:app/Utils/OrderManager.php:866-885).

منطق صرف المكافأة موزّع على 6 مواضع «تسليم» تستخدم ثلاثة كتّاب مختلفين (A وC وD): لوحة الإدارة (lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:736)، لوحة البائع (lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:689-705)، API البائع v2 (lavkoo:app/Http/Controllers/RestAPI/v2/seller/OrderController.php:273)، API البائع v3 في موضعين (lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:465-479، lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:763-774)، وتطبيق المندوب (lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:229-251). هذه المواضع لا تتطابق في طريقة تحديد المستفيد ولا في وحدة المبلغ الممرَّر (الدالة المشتركة تمرّر `usdToDefaultCurrency(الإعداد)` والمواضع الصريحة تمرّر الإعداد كما هو)، وتفاصيل شروط الصرف في SEC-E04-16. تستدعي الدالة المشتركة أيضاً مسارين غير تسليميين: إتمام الدفع بالمحفظة على الويب (lavkoo:app/Http/Controllers/Web/WebController.php:877-879) والـ hook الرقمي `digital_payment_success` (lavkoo:app/Utils/module-helper.php:200-202)؛ لا يُنتج أي منهما صرفاً على الأرجح لأن شرط `delivered` غير متحقق عند إنشاء الطلب (`INFERRED`). الحقل `is_used_by_refer` موجود في نموذج `ReferralCustomer` (lavkoo:app/Models/ReferralCustomer.php:38).

- SEC-E04-16: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 2.7 العناوين

- **الجدول والملكية:** `shipping_addresses` يخدم الشحن والفوترة معاً (`is_billing`) ويُملَك بـ `customer_id` + `is_guest`؛ الأعمدة القديمة `city`, `zip`, `state`, `country` نصوص حرة (lavkoo:app/Models/ShippingAddress.php:45-64). `country` يحمل **اسم** الدولة بالإنجليزية لا رمزها: فحص تقييد الدول يقارن الاسم المُدخل بأسماء مشتقة من الثابت `COUNTRIES` لرموز الجدول `delivery_country_codes` (lavkoo:app/Traits/CommonTrait.php:95-105). دفتر العناوين عام لكل عميل بلا متجر أو رمز دولة؛ التمييز الوحيد بين الدول هو نص `country`. الجدول `billing_addresses` والنموذج موجودان، وطلبات اليوم تشير بعمودي `shipping_address` و`billing_address` إلى `shipping_addresses` (lavkoo:app/Models/Order.php:224-232).
- **النظام الموحّد سعودي التصميم:** الوضع `off|soft|strict` في الإعداد `unified_address_mode` (موثّق في lavkoo:docs/address/README.md:19-24)، ويعود `off` تلقائياً إن لم تُطبَّق الترحيلات أو لم تُستورد المدن أو كان تقييد الرمز البريدي مفعّلاً (lavkoo:app/Services/Address/AddressSchema.php:26-36 و lavkoo:app/Services/Address/AddressSchema.php:127-134). الحقول المنظَّمة تُطلب فقط إذا كانت الدولة السعودية (lavkoo:app/Services/ShippingAddressService.php:83-88) بحسب قائمة تهجئات ثابتة (lavkoo:app/Services/Address/AddressCountry.php:13-22)؛ التحقق يفرض جوالاً سعودياً والعنوان الوطني المختصر ويكتب الدولة بالاسم الثابت `Saudi Arabia` (lavkoo:app/Services/ShippingAddressService.php:209-234، lavkoo:app/Services/Fulfillment/Support/SaudiPhone.php:28-38، lavkoo:app/Services/Address/NationalAddress.php:54-68). في الوضع `soft` يُسمح لقناة الـ API وحدها بالحقول القديمة (lavkoo:app/Enums/Address/AddressChannel.php:16-19). أي دولة أخرى تبقى على النص الحر القديم.
- **الجداول الرئيسة:** `address_regions` يحمل `country_code` بافتراضي `SA` وقيداً فريداً (`country_code`, `code`) (lavkoo:database/migrations/2026_11_07_100000_create_address_master_tables.php:22-38)، لكن قارئ القوائم لا يرشّح بالدولة إطلاقاً (lavkoo:app/Services/Address/AddressCatalog.php:19-26). الأعمدة الأربعة المضافة للعنوان: `region_id`, `city_id`, `district`, `national_address` بلا مفاتيح أجنبية (lavkoo:database/migrations/2026_11_07_100100_add_unified_address_columns_to_shipping_addresses_table.php:29-48). تطبيق هذه الترحيلات على الإنتاج وقيمة الوضع `UNKNOWN`.
- **لقطة الطلب:** الطلب يحفظ نسخة غير قابلة للتبدّل من العنوان في `shipping_address_data`/`billing_address_data` (lavkoo:app/Utils/OrderManager.php:1090-1094 و lavkoo:app/Utils/OrderManager.php:453-459)، تتضمن عند تفعيل النظام أسماء المنطقة والمدينة يومها و`address_schema` (lavkoo:app/Services/Address/AddressSnapshot.php:26-45). معرّفات العناوين القادمة من العميل تُتحقَّق ملكيتها قبل كتابة الطلب (lavkoo:app/Services/Address/OrderAddressService.php:39-97).
- **`zip`:** لا يُشتق أبداً من العنوان الوطني (lavkoo:app/Services/Address/AddressSnapshot.php:90-94)، ويصبح إلزامياً فقط مع تقييد الرمز البريدي (lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:303).
- **قيود التوصيل:** `delivery_country_codes(country_code)` و`delivery_zip_codes(zipcode)` قائمتان عامتان بلا متجر ولا بائع، والرموز البريدية غير مربوطة بدولة (lavkoo:database/migrations/2022_10_18_130956_create_delivery_country_codes_table.php:16-20، lavkoo:database/migrations/2022_10_18_130938_create_delivery_zip_codes_table.php:16-20). تُطبَّق عند حفظ العنوان وعند الدفع بالمحفظة (lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:321-329، lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:455-468).

### 2.8 الموافقات والتفضيلات التسويقية

لا توجد أي بنية بيانات لموافقات العميل أو تفضيلاته التسويقية (البحثان المسجَّلان في القسم 1: صفر نتائج في الترحيلات، وصفر نتائج لـ `unsubscrib`). الموجود فعلياً:

- موافقة ملفات الارتباط للتحليلات: كعكة متصفح `6valley_cookie_consent` تُكتب من جهة العميل لمدة 30 يوماً (lavkoo:resources/themes/default/layouts/front-end/app.blade.php:400-404) ويحترمها جامع التحليلات إذا فُعّلت السياسة (lavkoo:app/Services/Analytics/Support/PrivacyGate.php:47-62). لا تُحفظ خادمياً ولا تُربط بالعميل.
- قائمة بريدية `subscriptions(email)` بلا ربط بالعميل ولا نطاق ولا نص موافقة (lavkoo:database/migrations/2022_04_06_020313_create_subscriptions_table.php:16-20، lavkoo:app/Http/Controllers/Web/WebController.php:1576-1595).
- نموذج التسجيل لا يلتقط قبول الشروط أو الموافقة التسويقية (lavkoo:app/Http/Requests/Web/CustomerRegistrationRequest.php:25-34).
- تذكير السلة المتروكة يراسل العملاء المسجَّلين بقرار إعداد عام واحد دون مراعاة أي تفضيل للعميل؛ الضيوف مستثنون افتراضياً بإعداد مستقل (lavkoo:app/Services/Retention/AbandonedCartService.php:47-68 و lavkoo:app/Services/Retention/AbandonedCartService.php:169-193).
- اللغة المفضلة `app_language` هي التفضيل الوحيد المحفوظ على العميل.

### 2.9 ما يراه ويصدّره المشرف والبائع؛ تذاكر الدعم والمحادثات

- **المشرف:** مسارات العملاء والمحفظة والولاء كلها تحت مفتاح صلاحية واحد `module:people` بلا أي نطاق بيانات (lavkoo:routes/admin/routes.php:581-620)، وكذلك تذاكر الدعم (lavkoo:routes/admin/routes.php:1117). صفحة العميل تعرض الرصيد والنقاط والعناوين والطلبات (lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:220-269). التصديرات: قائمة العملاء بالهاتف والبريد (lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:375-428، lavkoo:resources/views/file-exports/customer-list.blade.php:40-52)، طلبات عميل (lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:271-281)، المشتركون (lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:430-461)، حركات المحفظة (lavkoo:app/Http/Controllers/Admin/Customer/CustomerWalletController.php:125-147)، حركات الولاء (lavkoo:app/Http/Controllers/Admin/Customer/CustomerLoyaltyController.php:81-103).
- **البائع (اللوحة):** قائمة أسماء وهواتف لكل عملاء المنصة لا عملائه فقط (lavkoo:routes/vendor/routes.php:218-223، lavkoo:app/Http/Controllers/Vendor/CustomerController.php:46-53، lavkoo:app/Repositories/CustomerRepository.php:185-209)، وإمكانية إنشاء عميل عام.
- **تطبيق البائع وتطبيق المندوب:** يستقبلان نموذج العميل كاملاً ضمن الطلبات وبحث POS (القرّاء R11 وR12)، أي الرصيد والنقاط والبريد والهاتف وكود الإحالة، لأن الحقول المخفية في `App\Models\User` لا تشمل العمودين (lavkoo:app/Models/User.php:109-117).
- **تذاكر الدعم:** `support_tickets` مملوكة بـ `customer_id` بلا ربط بطلب أو متجر أو دولة (lavkoo:app/Models/SupportTicket.php:32-43). ملكية العميل مفروضة في الـ API والويب (lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:143-150 و lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:182-188، lavkoo:app/Http/Controllers/Web/UserProfileController.php:558-578). ربط التذكرة بالعميل اختياري في مسار الويب (lavkoo:app/Http/Controllers/Web/UserProfileController.php:544-554).
- **المحادثات:** `chattings` بمفاتيح `user_id`, `seller_id`, `admin_id`, `delivery_man_id`, `shop_id` (lavkoo:app/Models/Chatting.php:63-82)؛ قراءات العميل مقيّدة بمعرّفه (lavkoo:app/Http/Controllers/RestAPI/v1/ChatController.php:41 و lavkoo:app/Http/Controllers/RestAPI/v1/ChatController.php:181).

### 2.10 الاختبارات

لا يوجد اختبار آلي يشير إلى دفتر المحفظة أو الولاء أو كتّابهما (البحث المسجَّل `wallet_transactions|WalletTransaction|loyalty_point|create_wallet_transaction` في `tests`: صفر ملفات). ثلاثة ملفات تشير إلى `wallet_balance`: أحدها مخطط جداول للاختبارات (lavkoo:tests/Support/BuildsApiAddressSchema.php:39)، والاثنان الآخران يتحققان من أن الرصيد **لم يتغير** عند رفض طلب بسبب العنوان (lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:289-296؛ lavkoo:tests/Feature/Address/StorefrontOrderCompletionTest.php:164-171).

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الدليل |
|---|---|---|
| 1 | رصيد محفظة واحد ورصيد نقاط واحد لكل عميل، عمودان على `users` | lavkoo:database/migrations/2022_05_12_104511_add_two_column_to_users_table.php:16-19 |
| 2 | الدفتران بلا عملة ولا متجر ولا كيان قانوني | lavkoo:database/migrations/2022_05_12_104247_create_wallet_transactions_table.php:16-27، lavkoo:database/migrations/2022_05_14_063309_create_loyalty_point_transactions_table.php:16-26 |
| 3 | عملة واحدة للنظام (`system_default_currency`) ونموذج عملة واحد (`currency_model`)؛ وحدة التخزين تتبعهما | lavkoo:app/Utils/currency.php:50-68 |
| 4 | إعدادات المحفظة/الولاء/الإحالة والحدود وقواعد الـ bonus عامة | lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:500-514، lavkoo:app/Utils/Helpers.php:697-715 |
| 5 | `/api/v1/config` يعيد قيمة واحدة لكل من العملة ورمز الدولة وحالات المحفظة والولاء | lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:158، lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:212-226 |
| 6 | النظام الموحّد للعناوين سعودي: الاسم الثابت، الجوال السعودي، العنوان الوطني، افتراضي `SA` | lavkoo:app/Services/Address/AddressCountry.php:13-15، lavkoo:app/Services/ShippingAddressService.php:233، lavkoo:database/migrations/2026_11_07_100000_create_address_master_tables.php:24 |
| 7 | قوائم تقييد الدول والرموز البريدية عامة | lavkoo:app/Traits/CommonTrait.php:95-110 |
| 8 | مرسل OTP واحد (إعداد Zender عام يُقرأ بـ `first()`) | lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:265-271 |
| 9 | التقريب إلى خانتين ثابتتين وإعداد خانات عام واحد | lavkoo:app/Utils/Convert.php:62، lavkoo:app/Utils/currency.php:101-103 |
| 10 | مسارات الـ API والويب تُحمَّل بلا بادئة دولة أو سياق متجر | lavkoo:app/Providers/RouteServiceProvider.php:97-119 |
| 11 | هوية الهاتف نص خام بمطابقة تامة، بلا تطبيع لرمز الدولة | lavkoo:app/Repositories/CustomerRepository.php:31-50 |
| 12 | صلاحية المشرف على العملاء والمحافظ مفتاح واحد بلا نطاق بيانات | lavkoo:routes/admin/routes.php:581 |

## 4. الأثر على قرارات GCC المعتمدة

| القرار | ما يدعمه اليوم في هذا النطاق | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| GCC-13 (هوية واحدة) | جدول عملاء واحد وحرّاس مصادقة مشتركون للويب والتطبيق | لا قيد تفرّد مثبت في المستودع؛ هوية الهاتف غير مطبَّعة؛ صنفا نموذج؛ معرّف الضيف يشارك العمود مع العميل؛ مسائل SEC مقيّدة في المصادقة | lavkoo:config/auth.php:82-86، lavkoo:app/Repositories/CustomerRepository.php:31-50 |
| GCC-13 (محافظ ومسارات مالية منفصلة لكل متجر/كيان) | دفتر حركات (`credit`/`debit`) يرافق كل تغيير رصيد في الكود | رصيد عام واحد؛ دفتر بلا متجر/كيان/عملة/طلب؛ 4 كتّاب دون قفل صفّي؛ POS وتعديل الطلب والاسترداد كلها تكتب على الرصيد العام | lavkoo:app/Utils/CustomerManager.php:36-91 |
| GCC-15 (ولاء محلي لكل دولة) | دفتر ولاء مربوط برقم الطلب | رصيد نقاط عام واحد؛ إعدادات كسب وتحويل عامة؛ التحويل يصبّ في المحفظة العامة؛ قواعد الكسب تختلف بحسب القناة | lavkoo:app/Repositories/LoyaltyPointTransactionRepository.php:114-160 |
| القيد المؤقت: محافظ ودفاتر منفصلة بالمتجر/الكيان/العملة مع تسوية الأرصدة السعودية | يمكن حسابياً مطابقة الدفتر بالرصيد (2.3 و5.1) | وحدة التخزين غير مثبتة؛ لا ربط بطلب أو دفعة؛ لا هوية منفّذ | lavkoo:app/Utils/Convert.php:9-22 |
| القيد المؤقت: لا تحويل عملات آلي | — | الكتّاب مبنيون على تحويل ضمني من/إلى وحدة أساس عند كل حركة | lavkoo:app/Utils/currency.php:50-68 |
| Foundation: كيان قانوني بائع لكل دولة | — | شحن المحفظة التزام مالي لا يُنسب لكيان؛ الـ bonus بلا قيد محاسبي؛ الإضافة اليدوية بلا منفّذ | lavkoo:app/Utils/module-helper.php:299-326 |
| GCC-16 (شحن محلي وعابر) | لقطة عنوان ثابتة على الطلب؛ قائمة مناطق/مدن بمعرّفات | النظام الموحّد سعودي فقط؛ الدولة اسم نصي؛ قيود التوصيل عامة | lavkoo:app/Services/Address/AddressCountry.php:13-22 |
| GCC-11 والقيد «موافقات بنطاق صحيح» | — | لا بنية موافقات أصلاً | lavkoo:database/migrations/2022_04_06_020313_create_subscriptions_table.php:16-20 |
| GCC-14 والقيد «المشرف المحلي لا يرى معاملات دولة أخرى» | صلاحيات وحدات قائمة | بيانات العملاء والمحافظ بلا نطاق؛ البائع يرى قائمة عملاء المنصة؛ تطبيقات البائع والمندوب تستقبل الرصيد العام | lavkoo:routes/admin/routes.php:581-620، lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:179-202 |
| GCC-12 (إيقاف متجر دون كسر الالتزامات) | — | حالة المحفظة والولاء مفتاحان عامّان؛ إيقاف متجر لا يمكن أن يعزل محفظته | lavkoo:app/Utils/CustomerManager.php:38 |
| GCC-19 (السعودية متجر افتراضي) | كل البيانات الحالية سعودية ضمناً، فيمكن إسنادها للمتجر الافتراضي | يلزم تثبيت وحدة التخزين قبل وسمها بـ SAR | lavkoo:database/seeds/BusinessSettingSeeder.php:15-19 |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها |
|---|---|---|---|
| عمودا `users.wallet_balance` و`users.loyalty_point` | Preserve | يقرؤهما 8 + 13 موضع PHP و16 سطر Blade و4 عقود Flutter | يبقيان إسقاطاً لرصيد المتجر السعودي الافتراضي ويُحدَّثان من الكاتب الموحّد في المعاملة نفسها |
| مفاتيح الـ API: `wallet_balance`, `loyalty_point`, `total_wallet_balance`, `wallet_transaction_list`, `total_loyalty_point` | Preserve | مقروءة حرفياً في التطبيقين | الإضافة فقط (مفاتيح جديدة لكل متجر)، بلا تغيير دلالة القائم |
| قيم `transaction_type` السبع وقيم `reference` الثابتة | Preserve | مفاتيح ترشيح في الويب والـ API وتطبيق العميل | لا تغيير؛ المراجع الجديدة في أعمدة مستقلة |
| جدولا `wallet_transactions` و`loyalty_point_transactions` وصفوفهما التاريخية | Preserve + Extend | سجل الأرصدة السعودية الوحيد | أعمدة قابلة لـ NULL: متجر/كيان، عملة، طلب/دفعة/استرداد، منفّذ، مفتاح idempotency |
| الكتّاب الأربعة للمحفظة وكاتبا الولاء | Refactor | متباعدون، دون قفل صفّي، دون مفتاح تكرار | خدمة كتابة واحدة يستدعيها الجميع؛ الدوال القديمة تبقى واجهات رقيقة عليها |
| منطق مكافأة الإحالة (6 مواضع) وقواعد كسب النقاط (5 مواضع) | Refactor | ثلاثة سلوكيات لمكافأة واحدة | دالة واحدة بحارس تكرار مربوط بـ `referral_customers` |
| رصيد لكل (عميل، متجر/كيان، عملة) | New | مطلوب لـ GCC-13 وGCC-15 | جدول أرصدة جديد بقيد فريد وقفل صف |
| إعدادات المحفظة/الولاء/الإحالة وقواعد الـ bonus | Extend | عامة اليوم | نطاق متجر مع بقاء القيم الحالية افتراضي السعودية |
| النظام الموحّد للعناوين (`AddressSchema`, `ShippingAddressService`, `AddressSnapshot`, `OrderAddressService`) | Preserve + Extend | يعمل ومختبَر للسعودية، واللقطة تحفظ التاريخ | ترشيح القوائم بـ `country_code` الموجود، ومحقّق لكل دولة بدل شرط `isSaudi` |
| `delivery_country_codes` و`delivery_zip_codes` | Extend | عامة | نطاق متجر، وربط الرمز البريدي بدولة |
| هوية العميل والحرّاس وPassport | Preserve + Refactor | أساس GCC-13 | إغلاق بنود SEC أولاً، ثم تطبيع الهاتف وقيود التفرّد |
| الموافقات والتفضيلات | New | غير موجودة | سجل موافقات بنطاق (متجر، قناة، غرض) وطابع زمني ومصدر |
| حذف الحساب | Refactor | حذف نهائي دون قراءة الأرصدة | إخفاء الهوية مع إبقاء السجل المالي، وفحص الأرصدة لكل كيان |
| `customer_wallets`, `customer_wallet_histories`, `RewardPointController`, `Customer`, `ReferrlaCustomer` | Refactor (إزالة لاحقة) | متروكة مع مسار حيّ | التحقق من بيانات الإنتاج ثم تعطيل المسار |

### 5.1 قابلية إعادة بناء الأرصدة السعودية من الدفتر

- **على مستوى الكود (`VERIFIED`):** كل كتابة على `wallet_balance` (4 من 4) وعلى `loyalty_point` (2 من 2) ترافقها كتابة صف دفتر في المعاملة نفسها، ولا يوجد كود يعدّل أو يحذف صفوف الدفترين. دفتر المحفظة والعمودان أُنشئت بترحيلين متتاليين بتاريخ واحد (`2022_05_12`) ودفتر الولاء بعدهما بيومين (`2022_05_14`)، فلا يُتوقع ضمن هذا المخطط رصيد سابق للدفتر (`INFERRED` من أسماء ملفات الترحيل؛ تاريخ تطبيقها على الإنتاج `UNKNOWN`).
- **الصيغة:** `wallet_balance` = Σ(`credit` + `admin_bonus` − `debit`) لكل `user_id`؛ `loyalty_point` = Σ(`credit` − `debit`). المطابقة الحسابية مستقلة عن وحدة التخزين لأن الطرفين كُتبا بالوحدة نفسها لحظة الحركة.
- **مصادر انحراف متوقعة يجب قياسها لا افتراض غيابها (`INFERRED`):** تحديثات متعارضة بسبب غياب القفل الصفّي؛ حساب `float`؛ تغيّر نوع العمود ثلاث مرات؛ عملاء محذوفون نهائياً (صفوف دفتر يتيمة)؛ أي تعديل يدوي في قاعدة البيانات؛ الرصيد `NULL` لمن لم تُسجَّل له حركة.
- **ما لا يستطيع الدفتر الإجابة عنه:** بأي عملة كانت الحركة، ولأي طلب أو دفعة بوابة أو استرداد تعود، ومن نفّذ الإضافة اليدوية. إسناد حركات الدفع يحتاج مطابقة خارجية مع `orders` (`payment_method = pay_by_wallet`) وجدول طلبات الدفع و`refund_transactions` بالزمن والمبلغ.
- **الحكم:** إعادة البناء **ممكنة حسابياً** (`INFERRED`)، ونتيجتها على بيانات الإنتاج `UNKNOWN` إلى أن يُشغَّل استعلام مطابقة للقراءة فقط على نسخة من قاعدة البيانات.

### 5.2 أصغر تغيير بنيوي يفصل الأرصدة دون كسر القرّاء الحاليين (توصية توثيقية)

1. **جدول أرصدة جديد** بمفتاح فريد (`user_id`، المتجر أو الكيان القانوني) يحمل `currency_code` ورصيد المحفظة ورصيد النقاط؛ هو مصدر الحقيقة ويُقفل صفّه عند كل حركة.
2. **توسيع الدفترين بأعمدة قابلة لـ NULL** (متجر/كيان، عملة، مرجع طلب/دفعة/استرداد، منفّذ، مفتاح idempotency فريد). الصفوف التاريخية تُوسَم بالمتجر السعودي؛ عمود العملة لا يُعبَّأ إلا بعد تثبيت وحدة التخزين.
3. **كاتب واحد** تتحول إليه الدوال الست الحالية (تبقى أسماؤها وتواقيعها كواجهات)، فلا يتغير أي من مواضع الاستدعاء الـ 20 + 9 في الخطوة الأولى.
4. **العمودان على `users` يبقيان** ويُحدَّثان من الكاتب الموحّد في المعاملة نفسها عندما يكون المتجر هو السعودي الافتراضي (GCC-19). بهذا لا يتغير أي قارئ في 2.3 (ج، د، هـ) ولا أي مفتاح API، ويبقى سلوك السعودية كما هو. المتاجر الجديدة لا تملك قرّاء قائمين، فتقرأ من الجدول الجديد حصراً عبر سياق متجر صريح.
5. **عدم** إعادة تعريف السمة على النموذج لتعيد رصيد «المتجر الحالي»: التسلسل الضمني للنموذج يصل تطبيقات البائع والمندوب (R11، R12)، وهناك صنفا نموذج، وكتابة مطلقة عبر `save()`؛ تغيير الدلالة ضمنياً يكسر هذه المسارات بصمت.
6. **قبل أي ترحيل بيانات:** تقرير مطابقة (5.1) وقرار مالك حول وحدة الأرصدة التاريخية.

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E04-F01 | رصيد محفظة ورصيد نقاط عامّان لكل عميل، ودفتران بلا متجر/كيان/عملة | P0 | VERIFIED | lavkoo:database/migrations/2022_05_12_104511_add_two_column_to_users_table.php:16-19، lavkoo:database/migrations/2022_05_12_104247_create_wallet_transactions_table.php:16-27 | تعارض مباشر مع GCC-13 وGCC-15؛ أي متجر جديد سيشارك الرصيد السعودي | 5.2 |
| E04-F02 | وحدة تخزين المبالغ تتبع `currency_model` وسعر العملة الافتراضية، بلا عمود عملة أو سعر في الدفتر؛ قيم الإنتاج غير معروفة | P0 | VERIFIED (كود) / UNKNOWN (بيانات) | lavkoo:app/Utils/Convert.php:9-22، lavkoo:app/Utils/currency.php:50-68 | لا يمكن وسم الأرصدة التاريخية بـ SAR ولا نقلها إلى كيان قانوني دون تثبيت الوحدة | قراءة `currency_model`, `system_default_currency`, `currencies` من الإنتاج (قراءة فقط) وتوثيق تاريخ تغيّرها |
| E04-F03 | 4 كتّاب للمحفظة و2 للولاء دون قفل صفّي ودون مفتاح تكرار في الكود (SEC-E04-10)، والخصم في مسارات الدفع منفصل عن إنشاء الطلب في معاملة واحدة | P0 | VERIFIED | lavkoo:app/Utils/CustomerManager.php:36-91، lavkoo:app/Traits/CustomerTrait.php:42-92، lavkoo:app/Repositories/WalletTransactionRepository.php:107-158، lavkoo:app/Utils/OrderManager.php:2299-2349، lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:495 | خسارة مالية وانحراف الرصيد عن الدفتر؛ يتضاعف مع تعدد المتاجر | كاتب واحد بقفل ومفتاح idempotency؛ الخصم داخل معاملة الطلب |
| E04-F04 | مسائل أمنية في المصادقة واسترداد الحساب وتفويض عمليات المحفظة (SEC-E04-01 إلى SEC-E04-18) | P0 | VERIFIED | الملحق المقيّد غير المنشور | مع هوية واحدة عبر ست دول يصبح أي استيلاء على حساب عابراً لكل المتاجر | إغلاق بنود P0 قبل بدء تنفيذ GCC-13 |
| E04-F05 | النسخ الأربع من الكاتب تتباعد في التحويل والأنواع المعالَجة (ويب مقابل API لتحويل النقاط؛ أنواع تعديل الطلب؛ POS تطبيق البائع) | P1 | VERIFIED (كود) / UNKNOWN (أثر) | lavkoo:app/Utils/CustomerManager.php:58-59، lavkoo:app/Traits/CustomerTrait.php:64-65، lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:287 | مبالغ مختلفة للعملية نفسها بحسب القناة عند أي إعداد عملة غير محايد | توحيد الكاتب وحذف التحويل الضمني |
| E04-F06 | دفتر المحفظة غير مربوط بطلب/دفعة/استرداد (رقم الطلب يظهر في موضعين فقط من 20) ولا يسجّل المنفّذ | P1 | VERIFIED | lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:495، lavkoo:app/Utils/module-helper.php:307، lavkoo:app/Services/RefundStatusService.php:83-88 | تعذّر إسناد الحركات لكيان قانوني أو بوابة؛ تسوية يدوية | أعمدة مرجعية ومنفّذ في الدفتر |
| E04-F07 | عمود `balance` في صفوف `add_fund` يستثني الـ bonus، وتقارير الإدارة تحسب الرصيد بلا bonus | P1 | VERIFIED | lavkoo:app/Utils/CustomerManager.php:74-77، lavkoo:app/Http/Controllers/Admin/Customer/CustomerWalletController.php:138-140 | مطابقة خاطئة إن استُخدم `balance` أو `credit − debit` | اعتماد الصيغة في 5.1 |
| E04-F08 | مكافأة الإحالة تُصرف من 6 مواضع «تسليم» بثلاثة كتّاب وسلوكيات غير متطابقة (التفاصيل مقيّدة: SEC-E04-16) | P1 | VERIFIED | lavkoo:app/Utils/OrderManager.php:866-885، lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:465-479، lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:229-251، lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:689-705 | صرف غير متسق من المحفظة العامة؛ بيانات تاريخية تحتاج فحصاً قبل التسوية | دالة واحدة بحارس تكرار؛ فحص صفوف `earned_by_referral` في الإنتاج |
| E04-F09 | قواعد كسب النقاط تختلف بحسب القناة (5 مواضع)، ومسار المندوب بلا منح (التفاصيل مقيّدة: SEC-E04-18) | P1 | VERIFIED | lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:726-734، lavkoo:app/Http/Controllers/RestAPI/v2/seller/OrderController.php:267-271 | أرصدة نقاط غير متسقة قبل فصلها حسب الدولة | توحيد قاعدة الكسب قبل GCC-15 |
| E04-F10 | لا إرجاع تلقائي للمحفظة عند إلغاء طلب `pay_by_wallet` غير POS | P1 | VERIFIED | lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:691-708 | التزامات غير مسوّاة تجاه العملاء تدخل التسوية التاريخية | حصر الطلبات الملغاة المدفوعة بالمحفظة في الإنتاج |
| E04-F11 | هوية العميل: لا قيد تفرّد مثبت، هاتف غير مطبَّع، صنفا نموذج بقواعد إخفاء مختلفة، معرّف الضيف يشارك عمود العميل | P1 | VERIFIED (كود) / UNKNOWN (قيود DB) | lavkoo:config/auth.php:82-86، lavkoo:app/User.php:38-40، lavkoo:app/Repositories/CustomerRepository.php:31-50، lavkoo:app/Models/Order.php:214-217 | حسابات مكررة ونسب خاطئ للطلبات والنقاط؛ يهدد «هوية واحدة» | قيود تفرّد وتطبيع E.164 وتوحيد الصنفين |
| E04-F12 | حذف الحساب نهائي دون قراءة الأرصدة ولا إبقاء للسجل | P1 | VERIFIED | lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:118-141، lavkoo:app/Repositories/CustomerRepository.php:179-183 | صفوف دفتر وطلبات يتيمة؛ تعارض مع الاحتفاظ بالسجلات المالية لكل كيان | إخفاء هوية بدل الحذف |
| E04-F13 | النظام الموحّد للعناوين سعودي فقط، والقارئ لا يرشّح بالدولة، و`country` اسم نصي، وقيود التوصيل عامة | P1 | VERIFIED | lavkoo:app/Services/ShippingAddressService.php:83-88، lavkoo:app/Services/Address/AddressCatalog.php:19-26، lavkoo:app/Traits/CommonTrait.php:95-110 | عناوين الدول الخمس الأخرى نص حر لا يخضع لمحقّق مخصّص للدولة؛ تقييد عام يطال كل المتاجر | 5 (صف العناوين) |
| E04-F14 | لا بنية موافقات أو تفضيلات تسويقية، ولا إلغاء اشتراك | P1 | VERIFIED | lavkoo:app/Http/Requests/Web/CustomerRegistrationRequest.php:25-34، lavkoo:app/Services/Retention/AbandonedCartService.php:169-193 | لا يمكن تحقيق «موافقات بنطاق صحيح» ولا عزل الجمهور التسويقي | سجل موافقات جديد |
| E04-F15 | بيانات العملاء والمحافظ بلا نطاق: مفتاح صلاحية واحد للمشرف، قائمة عملاء المنصة للبائع، نموذج العميل كاملاً في حمولات تطبيقي البائع والمندوب | P1 | VERIFIED | lavkoo:routes/admin/routes.php:581-620، lavkoo:app/Repositories/CustomerRepository.php:185-209، lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:92-98 | تسريب بيانات بين الكيانات بعد GCC | نطاق بيانات ومورد API محدَّد الحقول للعميل |
| E04-F16 | إعدادات المحفظة/الولاء/الإحالة وقواعد الـ bonus عامة، وتُنشر للتطبيق قيماً مفردة | P1 | VERIFIED | lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:500-514، lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:223-241 | لا ولاء محلي ولا حدود شحن بعملة كل متجر | نطاق متجر للإعدادات |
| E04-F17 | الكسور: تقريب ثابت لخانتين، قطع بإعداد عام، حساب `float` | P2 | VERIFIED | lavkoo:app/Utils/Convert.php:62، lavkoo:app/Utils/currency.php:101-103 | أخطاء تقريب في KWD/BHD/OMR | خانات لكل عملة وحساب عشري |
| E04-F18 | نظام محفظة قديم متروك بمسار حيّ | P2 | VERIFIED (كود) / UNKNOWN (بيانات) | lavkoo:app/Http/Controllers/Customer/RewardPointController.php:11-33، lavkoo:routes/web/routes.php:453-454 | رصيد ثانٍ محتمل خارج التسوية | فحص الجدولين في الإنتاج |
| E04-F19 | أعمدة الدفتر `NOT NULL` بلا افتراضي بينما الكتّاب لا يملؤون `admin_bonus` إلا في نوع واحد؛ الإدراج يعتمد على وضع MySQL غير الصارم | P2 | INFERRED | lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:97-100، lavkoo:config/database.php:59، lavkoo:app/Utils/CustomerManager.php:55-56 | تشغيل الوضع الصارم يوقف كل حركات المحفظة غير الشحن | افتراضي صريح أو تعبئة دائمة |
| E04-F20 | فحص أهلية الاسترداد في الـ API يقرأ نوع مكافأة مخالفاً للويب | P2 | VERIFIED | lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:572-576، lavkoo:app/Http/Controllers/Web/UserProfileController.php:889 | سلوك مختلف بين القناتين | توحيد الشرط |
| E04-F21 | لا اختبارات آلية لدفتر المحفظة أو الولاء أو كتّابهما؛ الملفات التي تلمس الرصيد تتحقق من عدم تغيّره عند رفض الطلب | INFO | VERIFIED | lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:289-296؛ lavkoo:tests/Feature/Address/StorefrontOrderCompletionTest.php:164-171 | أي إعادة هيكلة بلا شبكة أمان | اختبارات خصائص للكاتب الموحّد قبل التحويل |
| E04-F22 | عقد Flutter يقرأ مفاتيح الرصيد حرفياً | INFO | VERIFIED | lavkooapp:lib/features/profile/domain/models/profile_model.dart:57-64، lavkooappseller:lib/features/pos/domain/models/customer_model.dart:50-54 | تغيير الدلالة يكسر الإصدارات المنشورة | إضافة مفاتيح لا تبديل |
| E04-F23 | لقطة العنوان على الطلب قائمة وثابتة | INFO | VERIFIED | lavkoo:app/Services/Address/AddressSnapshot.php:26-45 | تدعم حفظ اللقطات التاريخية | يُحافَظ عليها |
| E04-F24 | دمج الضيف يقتصر على السلة وعنواني الطلب الجاري؛ طلبات الضيف السابقة لا تُسند | INFO | VERIFIED | lavkoo:app/Utils/CartManager.php:23-50، lavkoo:app/Utils/OrderManager.php:443-447 | يجب تعريف الدمج لكل متجر مع سلال منفصلة | قرار تصميم في GCC-13 |
| E04-F25 | الدفتران كافيان حسابياً لإعادة بناء الأرصدة: كل كتابة رصيد في الكود (4 من 4 للمحفظة، 2 من 2 للولاء) يرافقها صف دفتر في المعاملة نفسها، ولا كود يعدّل أو يحذف الصفوف؛ الصيغة Σ(`credit` + `admin_bonus` − `debit`) | INFO | INFERRED | lavkoo:app/Utils/CustomerManager.php:79-90، lavkoo:app/Traits/CustomerTrait.php:80-91، lavkoo:app/Repositories/WalletTransactionRepository.php:145-156، lavkoo:app/Utils/OrderManager.php:2337-2348، lavkoo:app/Utils/CustomerManager.php:126-135، lavkoo:app/Repositories/LoyaltyPointTransactionRepository.php:148-158 | التسوية التاريخية ممكنة دون مصدر خارجي للأرصدة، لكنها لا تجيب عن العملة ولا عن الطلب/الدفعة | تشغيل استعلام مطابقة للقراءة فقط على نسخة من الإنتاج قبل أي ترحيل (5.1) |

## 7. أسئلة مفتوحة وعناصر UNKNOWN

| # | السؤال | ما يحسمه |
|---|---|---|
| 1 | قيم `currency_model`, `system_default_currency`, `decimal_point_settings` وأسعار `currencies` في الإنتاج، وهل تغيّرت عبر الزمن؟ | قراءة `business_settings` و`currencies` من الإنتاج، وسجل التدقيق للإعدادات |
| 2 | هل يطابق Σ(`credit` + `admin_bonus` − `debit`) الرصيد لكل عميل؟ وكم عدد الفروق وحجمها؟ | استعلام مطابقة للقراءة فقط على نسخة من قاعدة البيانات |
| 3 | هل توجد قيود تفرّد على `users.email`/`users.phone`؟ وكم عدد الحسابات المكررة؟ | `SHOW CREATE TABLE users` واستعلام تكرار |
| 4 | أي الترحيلات مطبّقة فعلاً (دقة الأعمدة، جداول العناوين)؟ وما قيمة `unified_address_mode`؟ | جدول `migrations` و`business_settings` في الإنتاج |
| 5 | هل في `customer_wallets` أرصدة غير صفرية؟ | قراءة الجدول |
| 6 | كم صف `earned_by_referral` مكرر لكل مُحيل/طلب، وكم طلب `pay_by_wallet` ملغى بلا استرداد؟ | استعلامات قراءة فقط |
| 7 | قيمة `app.mode` وإعدادات `login_setups` (`phone_verification`, `email_verification`, خيارات الدخول) | `.env` وقاعدة البيانات |
| 8 | هل وُجدت أرصدة أو دفاتر في الخلفية القديمة قبل النقل؟ | UNKNOWN (missing access: pharmacyksa98) |
| 9 | مدة صلاحية توكنات Passport الفعلية وإعداد العملاء (`oauth_clients`) | قاعدة البيانات و`.env` |
| 10 | قرار المالك: بأي وحدة تُثبَّت الأرصدة السعودية التاريخية إن كانت مخزَّنة بوحدة أساس غير SAR؟ | قرار مالك بعد البند 1 |
| 11 | ما سياق المتجر الذي سيرسله تطبيق العميل ليُعاد له رصيد المتجر الصحيح؟ | تصميم مشترك مع مدققي الـ API وFlutter |

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| الحرّاس الثلاثة للعميل يستخدمون `App\User` | lavkoo:config/auth.php:38-63، lavkoo:config/auth.php:82-86 | VERIFIED |
| `App\Models\User` يخفي حقولاً لا يخفيها `App\User` | lavkoo:app/Models/User.php:109-117، lavkoo:app/User.php:38-40 | VERIFIED |
| العمودان ضمن `$fillable` ويُحوَّلان إلى `float` | lavkoo:app/Models/User.php:95-96، lavkoo:app/Models/User.php:154-155 | VERIFIED |
| تعريف العمودين وتغيّر نوعهما | lavkoo:database/migrations/2022_05_12_104511_add_two_column_to_users_table.php:16-19، lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:95-96 | VERIFIED |
| مخطط دفتر المحفظة | lavkoo:database/migrations/2022_05_12_104247_create_wallet_transactions_table.php:16-27 | VERIFIED |
| مخطط دفتر الولاء | lavkoo:database/migrations/2022_05_14_063309_create_loyalty_point_transactions_table.php:16-26 | VERIFIED |
| الكاتب A يقرأ قبل المعاملة ويكتب قيمة مطلقة | lavkoo:app/Utils/CustomerManager.php:39-40، lavkoo:app/Utils/CustomerManager.php:77-83 | VERIFIED |
| الكاتب B | lavkoo:app/Traits/CustomerTrait.php:45-46، lavkoo:app/Traits/CustomerTrait.php:78-84 | VERIFIED |
| الكاتب C | lavkoo:app/Repositories/WalletTransactionRepository.php:110-112، lavkoo:app/Repositories/WalletTransactionRepository.php:145-151 | VERIFIED |
| الكاتب D | lavkoo:app/Utils/OrderManager.php:2302-2303، lavkoo:app/Utils/OrderManager.php:2335-2341 | VERIFIED |
| `balance` يستثني الـ bonus | lavkoo:app/Utils/CustomerManager.php:74، lavkoo:app/Utils/CustomerManager.php:77 | VERIFIED |
| التحويل يتفرع على `currency_model` | lavkoo:app/Utils/Convert.php:9-22، lavkoo:app/Utils/currency.php:50-68 | VERIFIED |
| تقريب ثابت لخانتين | lavkoo:app/Utils/Convert.php:62 | VERIFIED |
| قطع بإعداد الخانات العام | lavkoo:app/Utils/currency.php:101-103 | VERIFIED |
| hook الشحن ومرجعه الثابت | lavkoo:app/Utils/module-helper.php:299-326 | VERIFIED |
| طلب الشحن وحدوده | lavkoo:app/Http/Controllers/Customer/PaymentController.php:455-555 | VERIFIED |
| قواعد الـ bonus | lavkoo:app/Utils/Helpers.php:697-715 | VERIFIED |
| الدفع بالمحفظة (API): فحص ثم طلب ثم خصم | lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:444، lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:472-495 | VERIFIED |
| الدفع بالمحفظة (ويب) | lavkoo:app/Http/Controllers/Web/WebController.php:828، lavkoo:app/Http/Controllers/Web/WebController.php:857-881 | VERIFIED |
| الاسترداد إلى المحفظة | lavkoo:app/Services/RefundStatusService.php:81-89 | VERIFIED |
| إرجاع POS بمرجع مربوط بالطلب وحارس تكرار | lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:696-708 | VERIFIED |
| الإضافة اليدوية إيداع فقط | lavkoo:app/Http/Requests/Admin/AddFundRequest.php:40-46 | VERIFIED |
| مراقب التدقيق لا يشمل العميل ولا الدفتر | lavkoo:app/Providers/ObserverServiceProvider.php:70-76 | VERIFIED |
| كاتبا الولاء | lavkoo:app/Utils/CustomerManager.php:93-137، lavkoo:app/Repositories/LoyaltyPointTransactionRepository.php:114-160 | VERIFIED |
| قاعدة الكسب | lavkoo:app/Repositories/LoyaltyPointTransactionRepository.php:123-124 | VERIFIED |
| التحويل عمليتان منفصلتان | lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:99-100، lavkoo:app/Http/Controllers/Web/UserLoyaltyController.php:128-129 | VERIFIED |
| مفاتيح إعدادات الولاء | lavkoo:app/Utils/OrderManager.php:2098-2108 | VERIFIED |
| حفظ إعدادات المحفظة/الولاء/الإحالة | lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:500-514 | VERIFIED |
| مكافأة الإحالة: الدالة المشتركة | lavkoo:app/Utils/OrderManager.php:866-885 | VERIFIED |
| مواضع صرف المكافأة الأخرى (5) | lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:736، lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:689-705، lavkoo:app/Http/Controllers/RestAPI/v2/seller/OrderController.php:273، lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:465-479، lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:229-251 | VERIFIED |
| حذف الحساب نهائي | lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:118-141، lavkoo:app/Http/Controllers/Web/UserProfileController.php:117-149 | VERIFIED |
| دمج سلة الضيف | lavkoo:app/Utils/CartManager.php:23-50 | VERIFIED |
| `customer/info` يعيد النموذج كاملاً | lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:59-86 | VERIFIED |
| بحث عملاء POS يعيد النموذج كاملاً | lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:179-202 | VERIFIED |
| قارئ Flutter للرصيد والنقاط | lavkooapp:lib/features/profile/domain/models/profile_model.dart:57-64 | VERIFIED |
| قارئ Flutter لتطبيق البائع | lavkooappseller:lib/features/pos/domain/models/customer_model.dart:50-54 | VERIFIED |
| أوضاع العنوان الموحّد | lavkoo:app/Services/Address/AddressSchema.php:127-134 | VERIFIED |
| اشتراط السعودية للحقول المنظَّمة | lavkoo:app/Services/ShippingAddressService.php:83-88، lavkoo:app/Services/Address/AddressCountry.php:13-22 | VERIFIED |
| `country_code` موجود والقارئ لا يرشّح به | lavkoo:database/migrations/2026_11_07_100000_create_address_master_tables.php:24، lavkoo:app/Services/Address/AddressCatalog.php:19-26 | VERIFIED |
| لقطة العنوان | lavkoo:app/Services/Address/AddressSnapshot.php:26-45 | VERIFIED |
| قيود التوصيل العامة | lavkoo:app/Traits/CommonTrait.php:95-110 | VERIFIED |
| كعكة الموافقة جهة العميل | lavkoo:resources/themes/default/layouts/front-end/app.blade.php:400-404 | VERIFIED |
| جدول المشتركين بريد فقط | lavkoo:database/migrations/2022_04_06_020313_create_subscriptions_table.php:16-20 | VERIFIED |
| مسارات عملاء الإدارة تحت `module:people` | lavkoo:routes/admin/routes.php:581-620 | VERIFIED |
| قائمة عملاء المنصة للبائع | lavkoo:app/Repositories/CustomerRepository.php:185-209 | VERIFIED |
| ملكية تذاكر الدعم | lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:143-150، lavkoo:app/Http/Controllers/Web/UserProfileController.php:558-578 | VERIFIED |
| وضع MySQL غير صارم في الإعداد | lavkoo:config/database.php:59 | VERIFIED |
| مخطط `users` الأساسي خارج المستودع | lavkoo:CLAUDE.md:22 | VERIFIED |
| تطبيق العميل يفرض مسار العملات المتعددة | lavkooapp:lib/helper/price_converter.dart:65 | VERIFIED |

## 9. سجل التحقق المستقل

> تحقّق مستقل بالقراءة فقط: إعادة فتح الاستشهادات، وفحص الادعاءات الدلالية للمسارات المالية والمصادقة، وإعادة البحث عن الغياب بأنماط بديلة، ثم تنقيح المحتوى العام. المستودع المفحوص `lavkoo@7f42777`؛ الـ HEAD الحالي يختلف بإضافة وثائق فقط، ولا تغيير في الملفات المستشهد بها.

| البند | العدد |
|---|---|
| ادعاءات فُحصت (كود وأعداد وغياب) | 121 |
| مؤكَّدة دون تعديل | 102 |
| مُصحَّحة (تعديل جزئي أو توسيع) | 14 |
| مُفنَّدة كلياً | 0 |
| غير قابلة للتحقق من الكود (إطار العمل غير موجود في المستودع، أو قيم إنتاج، أو مستودع مفقود) | 5 |

### التصحيحات

1. §1: إعادة عدّ `wallet_balance` (176 سطراً؛ `resources` 110).
2. §2.1: نُقلت جملة ربط معرّفي الضيف والعميل وأثرها إلى الملحق المقيّد (SEC-E04-17)، واستُبدل اسم بريد حرفي لحساب نظامي بوصف.
3. §2.3: أُعيدت صياغة نمط الكتّاب الأربعة دون تفاصيل التسلسل، وسُجِّلت نتيجة بحث القفل الصفّي في وحدات أخرى؛ التفاصيل في SEC-E04-10.
4. §2.3 (هـ)، §2.5 (1 و3)، §2.6 (التحويل): أُزيلت أوصاف التسلسل ومعالجة التكرار، واستُبدلت بإحالات.
5. §2.6: الدالة المشتركة لصرف مكافأة الإحالة تُستدعى من 6 مواضع تسليمية و2 غير تسليمية لم تكن مذكورة (الحكم على الأخيرين `INFERRED`)؛ شرط الصرف أُحيل إلى SEC-E04-16؛ وصُحِّح وصف `is_used_by_refer` (موجود في النموذج).
6. §2.6 (جدول الولاء): صف تطبيق البائع v2 أُحيل إلى SEC-E04-18.
7. §2.10: عدد ملفات الاختبار التي تشير إلى `wallet_balance` هو 3 لا 1، وصُحِّح نطاق الاستنتاج.
8. §2.9: ربط تذكرة الدعم بالعميل وُصِف بأنه اختياري.
9. §6: F03 وF04 وF08 وF09 وF21 أُعيدت صياغتها بالمراجع الصحيحة، وحُدِّث نطاق الملحق إلى SEC-E04-18.
10. الملحق المقيّد: تصحيحات مُلحقة بالبنود SEC-E04-01 و02 و05 و06 و10 و13 و16، وبندان جديدان نُقلا من الملف الرئيسي (SEC-E04-17 و18).

### بنود باقية

- ترتيب تعريفي المسار المكرر لـ `existing-account-check` مبني على سلوك الإطار (`INFERRED`)، ومجلد `vendor` غير موجود في المستودع.
- فرع Apple في الدخول الاجتماعي لم يُفحص كاملاً (SEC-E04-13).
- أثر الحركات غير المتطابقة وأثر تحويل الوحدات في الإنتاج: كل القيم `UNKNOWN`.
- صحة إعادة بناء الأرصدة من الدفتر (§5.1) تحتاج قراءة فعلية للبيانات ولا تُستنتج من الكود.

### فجوات التغطية

- بوابات الدفع خارج Paymob وMyFatoorah لم تُفحص؛ حكم SEC-E04-11 لذلك `INFERRED`.
- متحكمات السلة والكوبونات، ومحافظ البائع والمشرف والتسويات، ونظام أدوار المشرفين: خارج النطاق كما في §1.
- مقاطع `RestAPI/v2` و`RestAPI/v3` و`Web/UserProfileController` قُرئت جزئياً، ولم تُفحص كاملة.

### البنود المنقولة إلى الملحق في هذا التحقق

عدد البنود: 2 (SEC-E04-17 وSEC-E04-18). مؤشرات SEC في هذا الملف: SEC-E04-01 إلى SEC-E04-18.

### نتائج الفحوص الآلية

- `check_citations.py`: 0 defects (467 استشهاداً ملفياً، 3 استشهادات commit).
- `scan_sensitive.py`: 0 hits.
