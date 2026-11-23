# E19 — تطبيق البائع (Flutter): البنية، تسجيل الدخول، الصلاحيات، الإعدادات، بوابة الإصدار، الإشعارات (Seller Flutter app: architecture, login, permissions, config, version gate, notifications)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkooappseller@8ea00922e44db3d69059d46892f8f4f84349b580` (النطاق الأساسي) ، `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` (النظير الخلفي: `routes/rest_api/v3/seller.php` ومسارات `v1` التي يستهلكها التطبيق).
> لم يُشغَّل أي كود ولم يُجرَ أي اتصال شبكي. كل ما يخص قيم قاعدة البيانات أو `.env` أو النسخ المنشورة في المتاجر موسوم `UNKNOWN`.

## 1. النطاق وما تم فحصه

**ما فُحص (تغطية I — الجزء الأول):**

| المجال | الملفات المقروءة كاملة أو شبه كاملة |
|---|---|
| الهوية والبناء | `pubspec.yaml` (142 سطراً)، `README.md`، `MIGRATION.md` (222)، `docs/seller-center-update-study.md` (289)، `android/app/build.gradle.kts`، `AndroidManifest.xml`، `MainActivity.kt`، `ios/Runner/Info.plist`، `AppDelegate.swift`، `Runner.entitlements`، `firebase.json`، `lib/firebase_options.dart` (المعرّفات فقط)، `android/app/google-services.json` و`ios/Runner/GoogleService-Info.plist` (المعرّفات فقط عبر سكربت يطبع أسماء المفاتيح غير السرّية)، `.github/workflows/ci.yml` (جزئياً) |
| الإقلاع والحقن | `lib/main.dart` (488)، `lib/di_container.dart` (486، قسم `init`)، `lib/core/app_context.dart` |
| الشبكة | `lib/utill/app_constants.dart` (533)، `lib/data/datasource/remote/dio/dio_client.dart`، `logging_interceptor.dart`، `exception/api_error_handler.dart`، `lib/data/datasource/local/secure_credential_store.dart`، `lib/helper/api_checker.dart` |
| المصادقة | `lib/features/auth/**` (المتحكم، الخدمة، المستودع، `login_screen.dart`، `auth_screen.dart`، مقاطع من `otp_verification_screen.dart` و`forget_password_screen.dart` و`registration_screen.dart`)، `sign_out_confirmation_dialog_widget.dart` |
| البداية/الإعدادات/البوابة | `lib/features/splash/**` (المتحكم، الشاشة، المستودع، `config_model.dart` دالة `fromJson` ونماذج الصيانة والإصدار)، `lib/helper/version_helper.dart`، `lib/features/update/screen/update_screen.dart`، `lib/features/maintenance/maintenance_screen.dart` |
| الإشعارات | `lib/notification/my_notification.dart`، `models/notification_body.dart`، `lib/features/notification/**` (المستودع والنموذج)، `lib/helper/notification_helper.dart` (ملف معلَّق بالكامل) |
| اللغة/السمة/الأسعار | `lib/localization/**`، `lib/features/language/**`، `lib/theme/controllers/theme_controller.dart`، مقاطع من `light_theme.dart`، `lib/helper/price_converter.dart`، `country_code_helper.dart` |
| الصحة والأمان | `lib/helper/app_health_reporter.dart`، `lib/features/security/**` (المتحكم، المستودع، النماذج) |
| الاختبارات | `test/app_version_gate_test.dart`، `test/dio_auth_header_test.dart`، `test/version_helper_test.dart`، `test/security_contract_test.dart` (أول 60 سطراً) |
| الخلفية (lavkoo) | `routes/rest_api/v3/seller.php` (الأسطر 1–140 و520–575 + عدّ آلي للمسارات)، `LoginController`، `ForgotPasswordController`، `RegisterController`، `SellerRegistrationRequest`، `SellerApiAuthMiddleware`، `EnsureSellerPermission`، `EnsureSellerIsOwner`، `APILocalizationMiddleware`، `SellerPermissionService`، `SellerPrincipal`، مقاطع `SellerController`، `Seller`/`SellerStaff`، `RestAPI/v1/ConfigController` (كاملاً)، `AppHealthController`، `MaintenanceModeTrait`، مقاطع `BusinessSettingsController`، `UpdateClass`، `PushNotificationTrait`، `SellerNotifier` |

**عمليات البحث المسجَّلة (كلها `grep -rn` داخل `lib/` لتطبيق البائع ما لم يُذكر غير ذلك):** `permission|isOwner|is_owner|owner_only|seller_can|your_role` ؛ `X-Platform|X-App-Version|X-Storefront|X-Country|X-Store|storefront|country_id|market` ؛ `lavkooseller|app_links|uni_links|getInitialLink|onGenerateRoute|associated-domains` (في المستودع كله) ؛ `subscribeToTopic|unsubscribeFromTopic` ؛ `Bearer|'Authorization'` ؛ `http.MultipartRequest` ؛ `setCurrency\b` ؛ `toStringAsFixed(2)` ؛ `forAndroid|forIos` ؛ `activeTheme` ؛ `debugPrint *=` ؛ `logout|sign-out|signout` ؛ وفي `lavkoo`: `lavkoo_seller|six_valley_seller|sixvalley_seller` (PHP/JSON/JS/Blade عدا `vendor/` و`node_modules/` و`storage/`) ، `seller_app_version_control` ، `is_owner|'permissions' =>|isOwner()` داخل `app/Http/Controllers/RestAPI/v3/seller` ، `logout|sign-out` داخل `routes/rest_api/v3/seller.php`.

**ما لم يُفحص:** شاشات الميزات التشغيلية (المنتجات، الطلبات، POS، المحفظة، Seller Center…) وهي من نصيب الجزء الثاني من التغطية I؛ ملفات الصور والخطوط؛ مشروع Xcode عدا أسطر `PRODUCT_BUNDLE_IDENTIFIER`؛ لم يُتحقق من أي نسخة منشورة في Google Play / App Store؛ لم تُقرأ قيم قاعدة بيانات الإنتاج.

**حجم المستودع:** 747 ملف Dart تحت `lib/`، 47 مجلد ميزة تحت `lib/features/`، 56 `ChangeNotifierProvider` مسجَّلاً في `lavkooappseller:lib/main.dart:176-233`، 19 ملف اختبار، 3 commits فقط في السجل (الأقدم `lavkooappseller@afa12db`، ثم `lavkooappseller@e56a533`، ثم `lavkooappseller@8ea0092`)، أي أن تاريخ التطوير السابق غير محفوظ في هذا المستودع.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 البنية والطبقات وعنوان الخادم

- التطبيق fork مخصَّص من قالب Sixvalley seller (`lavkooappseller:README.md:1-4`). البنية feature-first: لكل ميزة `controllers/` (ChangeNotifier) ← `domain/services/` ← `domain/repositories/` ← `DioClient`، موثَّقة في `lavkooappseller:docs/seller-center-update-study.md:33-45`. `VERIFIED`
- إدارة الحالة بـ `provider` والحقن بـ `get_it` (`lavkooappseller:pubspec.yaml:20-21`)؛ كل المستودعات والخدمات تُسجَّل Singletons في `lavkooappseller:lib/di_container.dart:236-246` وما بعدها، وعميل الشبكة واحد: `DioClient(AppConstants.baseUrl, …)` (`lavkooappseller:lib/di_container.dart:240`). `VERIFIED`
- **عنوان الخادم ثابت في الكود:** `baseUrl = 'https://lavkoo.com'` (`lavkooappseller:lib/utill/app_constants.dart:11`). لا يوجد flavors ولا `--dart-define` ولا ملف بيئة؛ كل المسارات ثوابت نصية في الملف نفسه (الأسطر 13–370). `VERIFIED`
- التطبيق يستهلك ثلاثة أسطح من الخلفية: `/api/v3/seller/**` (الغالبية)، وثمانية مسارات `v1` عامة مشتركة مع تطبيق العميل: `config` و`app-health` و`attributes` و`business-pages` و`vat-tax/get-taxVat-list` وثلاثة مسارات `mapapi` (`lavkooappseller:lib/utill/app_constants.dart:14` ، `:16` ، `:41` ، `:200` ، `:217` ، `:359-362`). `VERIFIED`
- تسلسل الإقلاع في `main()`: تهيئة Firebase ← ربط Crashlytics ومعالجات الأعطال ← معالج رسائل الخلفية ← `FlutterDownloader` ← `di.init()` ← `AppVersion.load()` ← `AppHealthReporter.begin()` ← قراءة الإشعار الابتدائي ← `MyNotification.initialize()` ← `runApp` (`lavkooappseller:lib/main.dart:95-243`)، والشاشة الأولى دائماً `SplashScreen` (`lavkooappseller:lib/main.dart:365`). `VERIFIED`
- **تسعة مواضع خارج `DioClient`** تبني طلب `http.MultipartRequest` يدوياً ولا ترث الترويسات المركزية (بلا ترويسات اللغة في أي منها): ثمانية منها تضيف ترويسة `Authorization` يدوياً (حفظ بيانات البنك، الملف الشخصي، المندوب، إرسال رسالة، رفع منتج رقمي بعد البيع، تحديث المتجر، وموضعان في إضافة المنتج)، أما التسجيل فلا يضيف أي ترويسة. تصحيح تحقّق مستقل: الادعاء السابق بأن الترويسة `Authorization` موجودة في المواضع التسعة كلها غير دقيق — مثال: `lavkooappseller:lib/features/bank_info/domain/repositories/bank_info_repository.dart:19-20` و`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:196`. `MIGRATION.md` يذكر «18 call sites» (`lavkooappseller:MIGRATION.md:158`) لكن العدّ الحالي 9 (بحث `http.MultipartRequest`). `VERIFIED`

### 2.2 تدفّق تسجيل الدخول والجلسة

**من جهة التطبيق**

1. نموذج الدخول يقبل بريداً إلكترونياً صالح الصيغة وكلمة مرور من 6 محارف فأكثر فقط (`lavkooappseller:lib/features/auth/screens/login_screen.dart:187-214`)؛ لا يوجد دخول برقم الهاتف ولا دخول اجتماعي ولا OTP عند الدخول. `VERIFIED`
2. الطلب: `POST /api/v3/seller/auth/login` بالحقلين `email` و`password` (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:26-35`).
3. عند 200 تُقرأ `token` كسلسلة وتُحفظ (`lavkooappseller:lib/features/auth/domain/services/auth_service.dart:78-95`): تُحدَّث ترويسة العميل ثم تُكتب في `SharedPreferences` تحت المفتاح `token` (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:114-122`).
4. بعد النجاح: تسجيل رمز FCM والاشتراك في الموضوعين، ثم إبلاغ الخادم باللغة الحالية (`lavkooappseller:lib/features/auth/controllers/auth_controller.dart:103-116`)، ثم الانتقال إلى `DashboardScreen` (`lavkooappseller:lib/features/auth/screens/login_screen.dart:219-231`).
5. «تذكّرني» يحفظ البريد في `SharedPreferences` وكلمة المرور في Keystore/Keychain لإعادة تعبئة النموذج (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:166-173` ، `lavkooappseller:lib/data/datasource/local/secure_credential_store.dart:13-22`) مع ترحيل أي قيمة قديمة من التخزين غير المشفّر (`lavkooappseller:lib/data/datasource/local/secure_credential_store.dart:34-49`).
6. حالة «مسجَّل الدخول» = وجود المفتاح `token` محلياً فقط (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:130-132`)؛ لا يوجد refresh token ولا تحقق من الصلاحية عند الإقلاع.

**صيغة ترويسة Authorization (المعترِض والاختبار)**

- لا يوجد Interceptor للمصادقة؛ الترويسات الافتراضية تُضبط مرة واحدة في مُنشئ `DioClient`: `'Authorization': 'Bearer ${token ?? ''}'` مع `Content-Type` و`Accept` وثلاث ترويسات لغة (`lang` و`X-localization` و`Accept-Language`) (`lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56`). المعترِض الوحيد هو `LoggingInterceptor` ويُضاف في وضع debug فقط (`lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:60-62`). `VERIFIED`
- `updateAuthToken` يستبدل مدخل Authorization فقط ويتجاهل الرمز الفارغ، و`clearAuthToken` يعيده إلى `'Bearer '` (`lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:77-91`).
- `test/dio_auth_header_test.dart` يثبّت هذا العقد: لا يُرسَل `Bearer null` أبداً (`lavkooappseller:test/dio_auth_header_test.dart:32-37`)، الرمز المخزَّن يُحمَل عند الإنشاء (`:39-43`)، التبديل يحافظ على ترويسات اللغة (`lavkooappseller:test/dio_auth_header_test.dart:45-59`)، الرمز الفارغ لا يمحو جلسة عاملة (`lavkooappseller:test/dio_auth_header_test.dart:61-79`)، والخروج يُسقط الاعتماد (`lavkooappseller:test/dio_auth_header_test.dart:81-89`). تعليق الاختبار يوثّق شرط الخادم: يُرفض أي رمز طوله 30 محرفاً أو أقل (`lavkooappseller:test/dio_auth_header_test.dart:8-12`) وهو يطابق `MINIMUM_TOKEN_LENGTH = 30` في `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:16` و`:45`. `VERIFIED`
- **لا توجد أي ترويسة سياق** (دولة/متجر/كيان/منصة/إصدار) في أي طلب: بحث `X-Platform|X-App-Version|X-Storefront|X-Country|X-Store|storefront|country_id` في `lib/` لم يُرجع أي ترويسة. `VERIFIED`

**من جهة الخلفية**

- مجموعة المسارات: `prefix v3/seller` مع `api_lang` فقط، ومجموعة `auth` تحت `throttle:auth` (`lavkoo:routes/rest_api/v3/seller.php:49-64`)؛ معاملات حدود المحاولات لهذه المسارات مسألة مقيّدة — `SEC-E19-11`.
- `LoginController::login`: يبحث عن `Seller` بالبريد؛ إن لم يوجد يجرّب **دخول موظف** من `seller_staff` ويصدر له رمزاً خاصاً (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:72-84` و`:28-54`)؛ وإلا فالمالك بحالة `approved` يحصل على رمز عشوائي من 50 محرفاً يُكتب في `sellers.auth_token` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:98-114`). كل دخول يستبدل الرمز السابق، أي **جلسة واحدة فعّالة لكل حساب** (مالكاً أو موظفاً). `VERIFIED`
- الاستجابة عند النجاح `{"token": …}` فقط — لا تحمل نوع الحساب ولا الصلاحيات ولا المتجر (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:82` و`:114`). عند الفشل 401 مع `loginStatus` = `pending` أو قيمة الرفض الثابتة (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:115-128`) ويترجمها التطبيق إلى رسالتين (`lavkooappseller:lib/features/auth/domain/services/auth_service.dart:87-93`).
- `SellerApiAuthMiddleware` يحلّ الرمز إلى `SellerPrincipal` (مالك ← موظف ← مفتاح API صادر عن المتجر) ويضع `$request->seller` = **المتجر/البائع** دائماً (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:41-93`)؛ الحالة `approved` تُفحص عند كل طلب (`lavkoo:app/Services/Marketplace/SellerPermissionService.php:98-120`).
- كل بائع له متجر واحد: علاقة `hasOne(Shop)` (`lavkoo:app/Models/Seller.php:96-99`). أي أن الرمز ⇐ بائع واحد ⇐ متجر واحد، ولا بُعد دولة/كيان قانوني في سلسلة الحلّ. `VERIFIED`

**انتهاء الجلسة والخروج**

- يُستبدل الرمز الحالي عند كل دخول، وعند تغيير كلمة المرور (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:404-417`)، وعند تعليق البائع من لوحة الإدارة (`lavkoo:app/Http/Controllers/Admin/Vendor/VendorController.php:857-858`). تفاصيل دورة حياة الرمز من جهة الخادم مسألة مقيّدة (راجع `SEC-E19-04`).
- أي 401 يُحوَّل في `ApiErrorHandler` إلى `loginStatus` أو رسالة الخطأ أو قيمة الرفض الثابتة (`lavkooappseller:lib/data/datasource/remote/exception/api_error_handler.dart:62-84`)، و`ApiChecker` عند قيمة الرفض الثابتة يمسح الجلسة مرة واحدة (قفل `isUnAuthorize`) وينقل إلى `AuthScreen` (`lavkooappseller:lib/helper/api_checker.dart:21-43`). **ملاحظة:** رد الوسيط عند رمز غير صالح هو `{'auth-001': …}` بلا `message` ولا `errors` (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:90-92`)، فيسقط إلى القيمة الافتراضية للرفض عند غياب الحقلين (`errorDescription ??=`) (`lavkooappseller:lib/data/datasource/remote/exception/api_error_handler.dart:78`) — العقد قائم على هذا الشكل بالذات ويجب حفظه. `VERIFIED`
- الخروج اليدوي محلي: مسح الرمز وكلمة المرور المحفوظة ثم إلغاء الاشتراك في الموضوعين (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:135-163` ، `lavkooappseller:lib/features/menu/widgets/sign_out_confirmation_dialog_widget.dart:86-88`). إبطال الجلسة من جهة الخادم عند الخروج مسألة مقيّدة (راجع `SEC-E19-04`).
- تغيير كلمة المرور يدوّر الرمز في الخادم ويعيده في الاستجابة (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:404-417`) والتطبيق يتبنّاه (`lavkooappseller:lib/features/profile/controllers/profile_controller.dart:98-112` ، `lavkooappseller:lib/features/auth/controllers/auth_controller.dart:340-346`).

**استعادة كلمة المرور والتسجيل**

- الطريقة تحدَّد من الإعداد: `vendor_forgot_password_method` (بريد/هاتف) و`vendor_forgot_password_sms_method` (`firebase`/`sms`) (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:262-263`)؛ التطبيق يتفرّع عليها (`lavkooappseller:lib/features/auth/controllers/auth_controller.dart:130-164`) ويستخدم `firebase_auth` لإرسال الرمز في مسار Firebase (`lavkooappseller:lib/features/auth/controllers/auth_controller.dart:559-620`). مهلة إعادة الإرسال من `otp_resend_time` (`lavkooappseller:lib/features/auth/screens/otp_verification_screen.dart:40`).
- رابط التسجيل يظهر فقط إذا `seller_registration == "1"` (`lavkooappseller:lib/features/auth/screens/login_screen.dart:246`)، والتسجيل طلب multipart عام (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:195-253`).
- مسائل أمنية في هذه المسارات مقيّدة: `SEC-E19-01` ، `SEC-E19-02` ، `SEC-E19-03` (القسم 6).

### 2.3 الصلاحيات: مالك مقابل موظف

- **الخلفية تملك نموذج صلاحيات كاملاً:** كتالوج من 11 مفتاحاً في 7 مجموعات (`products.view`, `products.manage`, `orders.view`, `orders.manage`, `inventory.manage`, `promotions.manage`, `finance.view`, `payouts.request`, `reviews.view`, `shop_settings.manage`, `staff.manage`) (`lavkoo:app/Services/Marketplace/SellerPermissionService.php:30-41`)؛ المالك يملك كل شيء والموظف ما يمنحه دوره، وتُقرأ الصلاحيات عند كل طلب لا من الرمز (`lavkoo:app/Services/Marketplace/SellerPrincipal.php:93-96` ، `lavkoo:app/Services/Marketplace/SellerPermissionService.php:200-209`). الإنفاذ بوسيطَي `seller_can:<perm>` و`seller_owner` (`lavkoo:bootstrap/app.php:124-127` ، `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:26-59` ، `lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:24-41`). من 236 تعريف مسار في `seller.php` يوجد 53 تصريح `seller_can:` (أغلبها على مجموعات) و3 `seller_owner` (عدّ آلي بـ `grep -c`). `VERIFIED`
- **التطبيق لا يعرف صلاحيات المستخدم الحالي:** لا يوجد مسار يعيد للعميل «من أنا وما صلاحياتي» (بحث `is_owner|'permissions' =>|isOwner()` في متحكمات v3 أعاد فقط قوائم الأدوار في `SellerSecurityController` وفحصاً داخلياً)، واستجابة الدخول رمز فقط، ومعلومات المتجر المعادة من `seller-info` لحامل الاعتماد مسألة مقيّدة (راجع `SEC-E19-05`). وفي التطبيق: بحث `permission|isOwner|seller_can` خارج ميزة `security` لم يجد أي تبويب أو زر مشروط بصلاحية. `VERIFIED`
- البوابات الوحيدة في واجهة التنقل مبنية على الإعدادات لا على الدور: تبويب POS يظهر إذا `config.pos_active == 1` و`seller.pos_status == 1` (`lavkooappseller:lib/features/dashboard/screens/dashboard_screen.dart:85-98` ، `lavkooappseller:lib/features/menu/screens/more_screen.dart:117`)، وميزات AI إذا `is_ai_features_enabled` (`lavkooappseller:lib/features/dashboard/screens/dashboard_screen.dart:63-65`). بند «Security centre» يظهر للجميع (`lavkooappseller:lib/features/menu/screens/more_screen.dart:163-165`).
- النتيجة العملية: الموظف يرى الواجهة كاملة ويتلقى 403 عند كل استدعاء لا يملكه؛ `ApiErrorHandler` يقرأ الرسالة من `errors[0].message` (`lavkooappseller:lib/data/datasource/remote/exception/api_error_handler.dart:49-61`) فتظهر كرسالة خطأ. خطة الدراسة نفسها تُدرج «إخفاء أزرار الواجهة حسب الصلاحية» ضمن عمل المرحلة 4 دون علامة إنجاز (`lavkooappseller:docs/seller-center-update-study.md:153` و`:219`؛ الحالة `INFERRED`). `VERIFIED`
- **ميزة `security` في التطبيق هي إدارة الفريق والمفاتيح لا أمان الجهاز:** تقرأ كتالوج الصلاحيات والأدوار والموظفين و«من يملك وصولاً» وسجل التدقيق، وتكتب أدواراً وموظفين وتُخرج موظفاً قسرياً، وتدير مفاتيح API و Webhooks للمتجر، كلها تحت `/api/v3/seller/seller-center/security` و`/integrations` (`lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:11-28` ، `:48` ، `:54-60` ، `lavkooappseller:lib/utill/app_constants.dart:313-318`). الخلفية تحصرها بـ `staff.manage` (`lavkoo:routes/rest_api/v3/seller.php:536-558`). المتحكم يحمّل تسع قوائم دفعة واحدة (`lavkooappseller:lib/features/security/controllers/security_controller.dart:67-85`). لا يُرسَل أي سرّ من التطبيق في هذه الميزة عدا كلمة مرور الموظف الجديد عند إنشائه. `VERIFIED`

### 2.4 الإعدادات المجلوبة عند البداية

- `SplashController.initConfig()` يستدعي `GET /api/v1/config` (`lavkooappseller:lib/features/splash/domain/repositories/splash_repository.dart:16-23`) — **نفس نقطة تطبيق العميل**، عامة وغير مصادَقة ولا تأخذ أي معامل متجر (`lavkoo:routes/rest_api/v1/api.php:65-68` ، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:25`). ثم يجلب `business-pages?type=default` (`lavkooappseller:lib/features/splash/screens/splash_screen.dart:71`). `VERIFIED`
- الحقول التي يحلّلها `ConfigModel.fromJson` (`lavkooappseller:lib/features/splash/domain/models/config_model.dart:214-344`):

| المجموعة | مفاتيح JSON المستهلكة | الاستعمال |
|---|---|---|
| العملة والأرقام | `system_default_currency`, `currency_list[]` (id, name, symbol, code, exchange_rate), `currency_model`, `currency_symbol_position`, `decimal_point_settings` | `PriceConverter` واختيار العملة الافتراضية |
| روابط الوسائط | `base_urls.*` (12 مفتاحاً), `static_urls.*` | عرض الصور |
| اللغات | `language[]` (code, name) | تُحلَّل لكن قائمة اللغات الفعلية ثابتة في الكود |
| الكتالوج | `colors[]`, `unit[]`, `brand_setting`, `digital_product_setting`, `product_max_unit_price_range` | نماذج المنتج — **`colors` و`unit` تُقرآن بلا فحص null** (`lavkooappseller:lib/features/splash/domain/models/config_model.dart:229-230`) |
| التشغيل | `pos_active`, `seller_registration`, `shipping_method`, `digital_payment`, `cash_on_delivery`, `wallet_status`, `order_verification`, `minimum_order_amount_status`, `minimum_order_amount_by_seller`, `free_delivery_status`, `free_delivery_responsibility`, `vendor_review_reply_status`, `vendor_can_edit_order_status`, `map_api_status`, `is_ai_features_enabled` | إظهار/إخفاء ميزات |
| الضريبة | `system_tax_type`, `system_tax_include_status` | POS والمنتج |
| الاستعادة | `vendor_forgot_password_method`, `vendor_forgot_password_sms_method`, `otp_resend_time`, `country_code` | شاشات الاستعادة والتسجيل |
| الهوية | `company_phone`, `company_email`, `company_logo`, `active_theme`, `software_version` | شاشة الصيانة، اشتراطات الصور |
| البوابات | `maintenance_mode{…}`, `seller_app_version_control{for_android,for_ios}` | 2.5 |
| الرفع | `server_upload_max_filesize`, `system_image_file_upload_max_size`, `system_general_file_upload_max_size` | التحقق من الملفات |

- العملة النشطة: المفضّلة المخزَّنة محلياً تحت `currency`، وإلا عملة `system_default_currency` (`lavkooappseller:lib/features/splash/controllers/splash_controller.dart:70-83`). الدالة `setCurrency` موجودة بلا أي مستدعٍ في الواجهة (بحث `setCurrency\b` ← تعريفات فقط)، فعملياً العملة = الافتراضية العامة للنظام. `VERIFIED`
- فشل الجلب يعرض زر إعادة المحاولة (`lavkooappseller:lib/features/splash/screens/splash_screen.dart:57-68`). ملاحظة: فحص الاتصال يقارن بنص `'Connection to API server failed due to internet connection'` (`lavkooappseller:lib/features/splash/controllers/splash_controller.dart:107-109`) بينما المعالج يعيد `'no_internet_connection'` (`lavkooappseller:lib/data/datasource/remote/exception/api_error_handler.dart:122-126`)، فلا تتحقق المقارنة أبداً (أثر تجميلي). `VERIFIED`

### 2.5 بوابة الإصدار ووضع الصيانة

**الحقول الخلفية:** الإعداد `business_settings.type = seller_app_version_control` بقيمة JSON `{for_android:{status,version,link}, for_ios:{status,version,link}}` يُكتب من لوحة الإدارة (`lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:305-315`) ويُعاد كما هو في `config` (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:237`). قيمة البذر الافتراضية للترقية `version = "14.1"` و`status = 1` للمنصتين (`lavkoo:app/Traits/UpdateClass.php:304-315`). قيمة الإنتاج الحالية `UNKNOWN (business_settings)`.

**منطق التطبيق:**

1. الإصدار المثبَّت يُقرأ من الحزمة عبر `package_info_plus` عند الإقلاع، وافتراضه `0.0.0` إن فشلت القراءة (`lavkooappseller:lib/helper/version_helper.dart:13-29`). مصدر الحقيقة `version: 3.1.6+49` (`lavkooappseller:pubspec.yaml:8`) ويمرّ إلى Android عبر `flutter.versionName` (`lavkooappseller:android/app/build.gradle.kts:43-44`).
2. بعد ثانية من نجاح `config`: يؤخذ `for_android.version` أو `for_ios.version` بحسب المنصة، **وافتراضه `'0'` إذا غاب الحقل أو الكائن كله** (`lavkooappseller:lib/features/splash/screens/splash_screen.dart:73-82`).
3. `AppVersion.isUpdateRequired(min)`: يعيد `false` إذا كان `min` فارغاً أو null، وإلا `compare(min, installed) == 1` (`lavkooappseller:lib/helper/version_helper.dart:50-53`). المقارنة رقمية جزءاً جزءاً بعد التقسيم على النقطة؛ الجزء الناقص أو غير الرقمي = 0 (`lavkooappseller:lib/helper/version_helper.dart:35-47`)، ورقم البناء (`+49`) لا يدخل المقارنة.
4. إذا لزم التحديث: `UpdateScreen` تستبدل البداية بلا مخرج (`lavkooappseller:lib/features/splash/screens/splash_screen.dart:85-86`)، والزر يفتح `link` للمنصة؛ إن كان فارغاً تظهر رسالة فشل فقط (`lavkooappseller:lib/features/update/screen/update_screen.dart:48-60`).
5. **الحقل `status` لا يُستهلك في التطبيق:** يُحلَّل في النموذج (`lavkooappseller:lib/features/splash/domain/models/config_model.dart:1182-1187`) لكن بحث `forAndroid|forIos` يُظهر قراءة `version` و`link` فقط. وفي لوحة الإدارة لا يوجد مفتاح إطفاء لتطبيق البائع أصلاً: الحقل مخفي ومثبَّت على `1` وحقل الإصدار إلزامي (`lavkoo:resources/views/admin-views/system-setup/apps-settings.blade.php:194-211`)، بخلاف تطبيق العميل الذي له مفتاح «force update» (`lavkoo:resources/views/admin-views/system-setup/apps-settings.blade.php:63-70`). النتيجة: الرافعة الوحيدة لبوابة البائع هي رقم الإصدار نفسه، ولا يمكن تعطيلها من النموذج إلا بكتابة إصدار منخفض. `VERIFIED`
6. البوابة تُفحص عند الإقلاع البارد فقط؛ لا إعادة فحص عند العودة من الخلفية (لا يوجد مستدعٍ آخر لـ `isUpdateRequired`). الدالة `compareVersions` في الشاشة كود ميت (`lavkooappseller:lib/features/splash/screens/splash_screen.dart:215-232`).

**السلوك عند غياب الحقول:** غياب `seller_app_version_control` أو المنصة أو `version` ⇐ لا تحديث إجباري (مفتوح). إصدار غير رقمي كلياً ⇐ يُعامل 0 ⇐ لا تحديث. `VERIFIED` (`lavkooappseller:test/version_helper_test.dart:27-42`).

**تضارب موثَّق في «الحد الأدنى المنشور»:** الاختبار الحارس يثبّت `kBackendPublishedMinimumVersion = '2.0.1'` (`lavkooappseller:test/app_version_gate_test.dart:17`) ويفشل البناء إذا نزل إصدار `pubspec` عنه (`lavkooappseller:test/app_version_gate_test.dart:28-38`)؛ بينما `MIGRATION.md` يقول إن الإنتاج يشترط 14.1 (`lavkooappseller:MIGRATION.md:97-109`) والدراسة تروي حادثة إقفال كل البائعين خارج التطبيق لهذا السبب ورفع الإصدار إلى 16.2.2 (`lavkooappseller:docs/seller-center-update-study.md:239-251`)، وبذر الخلفية 14.1. الإصدار الحالي 3.1.6 أدنى من 14.1؛ فإن كانت قيمة الإنتاج ما زالت 14.1 (أو أُعيد بذرها على قاعدة جديدة) فكل البائعين محجوبون عن شاشة الدخول. `UNKNOWN` — تُحسم بقراءة `business_settings.seller_app_version_control` في الإنتاج.

**الصيانة:**

- الخلفية تحسب `maintenance_status` من مفتاح cache واحد عام `system_maintenance_mode` (`lavkoo:app/Traits/MaintenanceModeTrait.php:10-32`) وتعيد معه `selected_maintenance_system` (ومنه `vendor_app`) والرسائل والمدة (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:106-111`).
- التطبيق: إن لم يلزم تحديث وكان `maintenance_status == 1` و`vendor_app == 1` تُعرض `MaintenanceScreen` (`lavkooappseller:lib/features/splash/screens/splash_screen.dart:87-91`)؛ الشاشة تعيد جلب `config` عند كل عودة للتطبيق وتخرج تلقائياً عند انتهاء الصيانة (`lavkooappseller:lib/features/maintenance/maintenance_screen.dart:38-56`). صيانة مجدولة تبدأ خلال 60 دقيقة تشغّل مؤقتاً يفحص كل 30 ثانية (`lavkooappseller:lib/features/splash/controllers/splash_controller.dart:85-101` و`:201-216`).
- إشعار FCM من النوع `maintenance_mode` على الموضوع `maintenance_mode_start_vendor` يجعل التطبيق يعيد جلب `config` ويدخل/يخرج من الصيانة فوراً (`lavkooappseller:lib/notification/my_notification.dart:95-143`)؛ الخلفية ترسله فقط إذا `business_mode == 'multi'` واختير `vendor_app` (`lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:274-276`).
- مجموعة مسارات البائع لا تحمل وسيط صيانة (`lavkoo:routes/rest_api/v3/seller.php:49`)، فالإيقاف من جهة العميل فقط؛ الـ API يبقى عاملاً أثناء الصيانة. `VERIFIED` لمسارات `rest_api/v3/seller`: لا يستعمل أي مسار في `routes/rest_api` الوسيط المخصّص `maintenance_mode`، والوسيط العام المرتبط بالإيقاف `CheckForMaintenanceMode` (`lavkoo:bootstrap/app.php:46`) هو وسيط إطار العمل لوضع الإيقاف الفني، لا لمفتاح الصيانة `system_maintenance_mode`.

### 2.6 الإشعارات، المواضيع، التوجيه، والروابط العميقة

- **تسجيل الرمز:** `updateToken()` يجلب رمز FCM، يشترك في الموضوعين، ثم `POST` مع `_method=put` إلى `/api/v3/seller/cm-firebase-token` بالحقل `cm_firebase_token` (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:86-99`). يُستدعى بعد الدخول وعند كل إقلاع بجلسة قائمة (`lavkooappseller:lib/features/auth/controllers/auth_controller.dart:110` ، `lavkooappseller:lib/features/splash/screens/splash_screen.dart:141-143`). فشل الاستدعاء يُتجاهل بصمت (`lavkooappseller:lib/features/auth/domain/services/auth_service.dart:136-143`).
- **الخلفية تخزّن رمزاً واحداً لكل بائع:** `sellers.cm_firebase_token` على صف المتجر (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:619-635`) ورمز ثانٍ للويب `cm_firebase_token_web`؛ الإرسال للبائع يستهدف هذين العمودين (`lavkoo:app/Traits/PushNotificationTrait.php:73-77` ، `lavkoo:app/Services/SellerIntelligence/SellerNotifier.php:161`) بلغة `sellers.app_language` التي يكتبها `language-change` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:784-791`). لا جدول أجهزة، ولا ربط بالموظف، ولا بُعد دولة. `VERIFIED` (جانب الصلاحيات: `SEC-E19-06`).
- **المواضيع:** ثابتان في الكود: `topic = 'lavkoo_seller'` و`maintenanceModeTopic = 'maintenance_mode_start_vendor'` (`lavkooappseller:lib/utill/app_constants.dart:400-401`)؛ الاشتراك عند `updateToken` والإلغاء عند الخروج (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:89-90` و`:154-155`). **لا يوجد مرسِل للموضوع `lavkoo_seller` في الخلفية** (بحث `lavkoo_seller|six_valley_seller|sixvalley_seller` في `lavkoo` ← صفر نتائج)؛ الموضوع الافتراضي للإرسال العام هو `sixvalley` (`lavkoo:app/Traits/PushNotificationTrait.php:449-453`) وهو وارد في قائمة مواضيع تطبيق العميل (`lavkoo:app/Utils/settings.php:264-270`). أي أن اشتراك `lavkoo_seller` خامل حالياً. `VERIFIED` (غياب المرسِل ضمن حدود البحث المذكور).
- **التوجيه بحسب الحمولة** (`NotificationBody`: `order_id`, `order_details_id`, `refund_id`, `type`, `message_key` — `lavkooappseller:lib/notification/models/notification_body.dart:19-31`):

| الحالة | الآلية | الموضع |
|---|---|---|
| إقلاع بارد من إشعار | `getInitialMessage` ← `SplashScreen(body)` ← `switch(type)`: `chatting`, `theme`, `order`, `wallet`, `wallet_withdraw`, `product_request_approved_message`, `refund`, وافتراضياً شاشة الإشعارات — فقط إذا وُجدت جلسة | `lavkooappseller:lib/main.dart:148-161` ، `lavkooappseller:lib/features/splash/screens/splash_screen.dart:93-135` |
| التطبيق في المقدّمة | تحديث المحادثة لنوع `chatting` (بشرط وجود جلسة)، معالجة `maintenance_mode`، ثم إشعار محلي بمعرّف ثابت `0` | `lavkooappseller:lib/notification/my_notification.dart:66-151` ، `:290` |
| النقر على الإشعار المحلي | تفرّع على `type`/`orderId` | `lavkooappseller:lib/notification/my_notification.dart:37-64` |
| فتح من الخلفية | تفرّع أولاً على **نص العنوان** (`contains('chatting'/'Theme'/'Order')`) ثم على `type` ثم `titleLocKey` | `lavkooappseller:lib/notification/my_notification.dart:154-186` |

  التفرّع على نص العنوان الإنجليزي هشّ مع العناوين العربية (`INFERRED`: العنوان يُترجَم في الخلفية بلغة البائع). الحمولة لا تحمل أي معرّف متجر/دولة.
- **قائمة الإشعارات داخل التطبيق:** `GET /api/v3/seller/notification?limit=20&offset=` و`notification/view?id=` (`lavkooappseller:lib/utill/app_constants.dart:162-164` ، `lavkooappseller:lib/features/notification/domain/repositories/notification_repository.dart:13-15` و`:43`).
- **الروابط العميقة:** المخطَّط `lavkooseller://` مصرَّح به في Android و iOS (`lavkooappseller:android/app/src/main/AndroidManifest.xml:88-93` ، `lavkooappseller:ios/Runner/Info.plist:27-37`) لكن لا يوجد معالج له في Dart ولا حزمة روابط في `pubspec.yaml` ولا `onGenerateRoute` (بحث `lavkooseller|app_links|uni_links|getInitialLink|onGenerateRoute|associated-domains` ← التصريحان الأصليان فقط). أي أن الرابط يفتح التطبيق على البداية دون توجيه. لا App Links/Universal Links لتطبيق البائع. `VERIFIED`

### 2.7 التخزين المحلي، اللغة، وتنسيق الأسعار

**مفاتيح التخزين المحلي** (الثوابت في `lavkooappseller:lib/utill/app_constants.dart:389-405`؛ جرد آلي لكل استدعاءات `SharedPreferences`):

| المفتاح | المخزن | المحتوى | الكاتب/القارئ |
|---|---|---|---|
| `token` | SharedPreferences | رمز الجلسة | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:118` ، `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:35` |
| `user_email` | SharedPreferences | بريد «تذكّرني» | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:169` |
| `user_password` | Keystore/Keychain | كلمة مرور «تذكّرني» | `lavkooappseller:lib/data/datasource/local/secure_credential_store.dart:54` |
| `language_code` ، `country_code` | SharedPreferences | اللغة و«دولة» الـ Locale | `lavkooappseller:lib/localization/controllers/localization_controller.dart:65-68` |
| `currency` | SharedPreferences | رمز العملة المفضّلة | `lavkooappseller:lib/features/splash/domain/repositories/splash_repository.dart:33-45` |
| `theme` | SharedPreferences | داكن/فاتح | `lavkooappseller:lib/theme/controllers/theme_controller.dart:14-23` |
| `cart_list` ، `bluetooth_mac_address` | SharedPreferences | سلة POS وطابعة البلوتوث | `lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:117` ، `:188` |
| `search_address` ، `shipping_type` ، `cookies` | SharedPreferences | مساعدة واجهة | `lavkooappseller:lib/features/splash/domain/repositories/splash_repository.dart:30-31` ، `:50` |
| `app_health_pending_sessions` ، `app_health_pending_crashes` | SharedPreferences | عدّادات الصحة | `lavkooappseller:lib/helper/app_health_reporter.dart:30-31` |
| مفتاح طابور مبيعات POS غير المتصلة | SharedPreferences | مبيعات معلّقة | `lavkooappseller:lib/features/pos/controllers/offline_sales_controller.dart:55-61` (تفصيله في الجزء الثاني) |

لا يوجد أي مفتاح مقيَّد بمتجر أو دولة أو حتى بحساب: كل المفاتيح عامة على مستوى التثبيت؛ تبديل الحساب على الجهاز نفسه يرث `currency` و`cart_list` وطابور POS. `VERIFIED`

**اللغة:** قائمة اللغات ثابتة في الكود: العربية أولاً بـ `Locale('ar','SY')` ثم الإنجليزية `('en','US')` (`lavkooappseller:lib/utill/app_constants.dart:407-421`)، وقائمة `language` القادمة من الخادم لا تُستعمل للاختيار. ملفا الترجمة `assets/language/ar.json` و`en.json` بـ 1838 مفتاحاً لكل منهما، مع رجوع للإنجليزية ثم للمفتاح نفسه (`lavkooappseller:lib/localization/app_localization.dart:24-51`). عند التبديل: حفظ محلي، `PUT language-change` للخادم (فقط بوجود جلسة)، تحديث ترويسات كل العملاء، وضبط `Intl.defaultLocale` برمز اللغة فقط (`lavkooappseller:lib/localization/controllers/localization_controller.dart:29-45` ، `lavkooappseller:lib/features/auth/controllers/auth_controller.dart:118-128` ، `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:21-28`). الخلفية تقرأ ترويسة `lang` وتطوي القيمتين القديمتين `sa`⇐`ar` و`us`⇐`en` لأن نسخاً منشورة كانت ترسل رمز الدولة (`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29`). الاتجاه RTL يتحدد بـ `languageCode == 'ar'`. `VERIFIED`

**الأسعار والعملة والكسور** (`lavkooappseller:lib/helper/price_converter.dart:8-34`):

- عدد الكسور = `decimal_point_settings` العام (الافتراضي 1 إن غاب) (`lavkooappseller:lib/helper/price_converter.dart:22`)؛ رمز العملة وموضعه من `myCurrency.symbol` و`currency_symbol_position`.
- في وضع `single_currency` لا تحويل؛ وإلا `price × myCurrency.exchangeRate ÷ usdCurrency.exchangeRate` مع إسناد غير آمن `!` على عملة الدولار (`lavkooappseller:lib/helper/price_converter.dart:18-24`) — غياب `USD` من `currency_list` في الوضع المتعدد يُسقط كل شاشة تعرض سعراً (`INFERRED` من الإسناد).
- التقريب `round` للعرض العام و`truncate` لمسار السحب (`lavkooappseller:lib/helper/price_converter.dart:26-27` و`:55-56`).
- **كسور ثابتة = 2 في مواضع لا تقرأ الإعداد:** `discountCalculationWithOutSymbol` و`discountCalculation` (`lavkooappseller:lib/helper/price_converter.dart:187` و`:197`) و`longToShortPrice` (`lavkooappseller:lib/helper/price_converter.dart:212-224`)؛ وإجمالاً 6 استعمالات `toStringAsFixed(2)` في `lib/` مقابل 12 قراءة لـ `decimalPointSettings`.
- رمز الاتصال الهاتفي الافتراضي `+880` (إرث القالب) في 17 سطراً من `lib/` (بحث `+880`)، ويُستبدل وقت التشغيل برمز مشتق من `config.country_code` الوحيد (`lavkooappseller:lib/features/auth/controllers/auth_controller.dart:57` ، `lavkooappseller:lib/features/auth/screens/registration_screen.dart:50`). `VERIFIED`

### 2.8 مُبلِّغ صحة التطبيق

- عند كل إقلاع يُزاد عدّاد الجلسات محلياً ثم يُرسَل المتراكم: `POST /api/v1/app-health` بالحقول `platform` (`android`/`ios`)، `app_version`، `sessions`، `crashes` فقط (`lavkooappseller:lib/helper/app_health_reporter.dart:41-45` و`:62-92`)؛ الأعطال تُعدّ من معالجات الأخطاء الثلاثة وتُرسَل في الإقلاع التالي (`lavkooappseller:lib/main.dart:113-125` و`:239-242`). لا هوية جهاز ولا بائع ولا stack trace؛ تفاصيل الأعطال تذهب إلى Firebase Crashlytics (معطّل في debug: `lavkooappseller:lib/main.dart:126`).
- نقطة الاستقبال عامة بطبيعة التصميم (لا مصادقة عند الإقلاع)؛ ضوابط إساءة استخدامها مسألة مقيّدة — `SEC-E19-10` (`lavkoo:routes/rest_api/v1/api.php:97-99` ، `lavkoo:app/Http/Controllers/RestAPI/v1/AppHealthController.php:42-67`). السلسلة الزمنية تُفهرَس بـ `platform:version` فقط (`lavkoo:app/Services/Monitoring/Ingest/AppHealthRecorder.php:55-63`) — **لا تمييز بين تطبيق البائع وتطبيق العميل ولا بين الدول**؛ الفصل الحالي يحدث مصادفةً لاختلاف أرقام الإصدار (3.1.6 مقابل 3.0.6). الطلب يُرسَل عبر عميل `Dio` المركزي (`lavkooappseller:lib/main.dart:146`). `VERIFIED`

### 2.9 هوية التطبيق (تسجيل فقط — لا يُوصى بأي إعادة تسمية آلية)

| العنصر | القيمة الحالية | الموضع |
|---|---|---|
| اسم حزمة Dart | `syriacosmeticsmanger` (يرد في 4,504 سطراً ضمن 629 ملفاً (بحث في ملفات Dart خارج `build/`)، أغلبها أسطر `import`) | `lavkooappseller:pubspec.yaml:1` |
| الإصدار | `3.1.6+49` | `lavkooappseller:pubspec.yaml:8` |
| Android `namespace` و`applicationId` | `com.lavkoocosmeticsmanager.app` | `lavkooappseller:android/app/build.gradle.kts:23` و`:39` |
| حزمة `MainActivity` | `com.lavkoocosmeticsmanager.app` (والمجلد على القرص `…/lavkoocosmeticsmanager/seller/`) | `lavkooappseller:android/app/src/main/kotlin/com/lavkoocosmeticsmanager/seller/MainActivity.kt:1` |
| iOS bundle id | `com.lavkoocosmeticsmanager.app` | `lavkooappseller:ios/Runner.xcodeproj/project.pbxproj:443` |
| الاسم الظاهر | `Seller Center LV` | `lavkooappseller:android/app/src/main/AndroidManifest.xml:36` ، `lavkooappseller:ios/Runner/Info.plist:9-10` ، `lavkooappseller:lib/utill/app_constants.dart:6` |
| اسم الشركة في الواجهة | `Lavkoo` | `lavkooappseller:lib/utill/app_constants.dart:7` |
| مخطَّط الرابط | `lavkooseller` | `lavkooappseller:android/app/src/main/AndroidManifest.xml:92` |
| Firebase (Android) | project id `lavkoo-380f1`، وملف `google-services.json` يضم عميلين: `com.lavkoo.app` و`com.lavkoocosmeticsmanager.app` | `lavkooappseller:android/app/google-services.json:3-5` ، `:12` ، `:31` |
| Firebase (Dart options) | `projectId: lavkoo-380f1` للمنصتين، لكن `messagingSenderId`/`appId` لمنصة iOS برقم مشروع مختلف عن Android | `lavkooappseller:lib/firebase_options.dart:52-68` |
| Firebase (iOS plist) | `PROJECT_ID = lavkoo-380f1` مع `GCM_SENDER_ID` برقم المشروع الآخر | `lavkooappseller:ios/Runner/GoogleService-Info.plist:13-20` |
| `firebase.json` (FlutterFire) | `projectId: ksa-cosmetics` | `lavkooappseller:firebase.json:1` |
| iOS push entitlement | `aps-environment = development` | `lavkooappseller:ios/Runner/Runner.entitlements:5-6` |
| macOS | لا مشروع فعلي (أصول أيقونة وملفات مولَّدة فقط) و Firebase غير مهيَّأ له | `lavkooappseller:lib/firebase_options.dart:30-34` |
| توقيع Android | keystore من `key.properties` غير المتتبَّع، وإلا توقيع debug مع تحذير | `lavkooappseller:android/app/build.gradle.kts:47-66` |

**سلاسل علامات قديمة باقية:** `syriacosmeticsmanger` (اسم الحزمة)؛ `Siller Center` في عناوين `README.md` و`MIGRATION.md` (`lavkooappseller:README.md:1` ، `lavkooappseller:MIGRATION.md:1`)؛ `com.sixvalley.seller` كـ `userAgentPackageName` لخرائط `flutter_map` في 3 مواضع (مثال `lavkooappseller:lib/features/order/screens/select_location_screen.dart:120`)؛ قنوات `6valley_delivery` في ملف معلَّق بالكامل؛ تعليق «this build serves a Syrian store» و`countryCode: 'SY'` (`lavkooappseller:lib/utill/app_constants.dart:407-415`)؛ `ksa-cosmetics` في `firebase.json`. **الوثائق متقادمة مقابل الكود:** `MIGRATION.md` يذكر أن المعرّف المُهيَّأ `com.kuwaitcosmetics.seller` وأن Firebase المودَع يعود لمشروعَي `syria-pharmacy-ce885` و`sixvally-ecommerce` (`lavkooappseller:MIGRATION.md:42-65`)، والدراسة تذكر `com.sixamtech.sixvalley.seller` (`lavkooappseller:docs/seller-center-update-study.md:66-70`)؛ لا شيء من ذلك يطابق الملفات الحالية أعلاه. تغيّرت الهوية في `lavkooappseller@e56a533` دون تحديث الوثيقتين. `VERIFIED`

**عدم اتساق Firebase** بين `firebase.json` وخيارات iOS و Android يعني أن إشعارات iOS قد تُصدَر برمز من مشروع غير الذي ترسل منه الخلفية. `UNKNOWN` — يُحسم من لوحة Firebase ومن مفتاح الخدمة المضبوط في الخلفية وباختبار جهاز iOS فعلي.

### 2.10 `MIGRATION.md` و`docs/seller-center-update-study.md`

- **`MIGRATION.md`** ليس خطة ترحيل بيانات بل «قائمة إصدار وتسليم» لعملية تحديث القالب: ثلاثة شروط قبل النشر (keystore، application id، مشروع Firebase خاص) (`lavkooappseller:MIGRATION.md:8-77`)، إجراءات البناء واقتران الإصدار ببوابة التحديث (`lavkooappseller:MIGRATION.md:93-109`)، بنود تُركت عمداً (`lavkooappseller:MIGRATION.md:150-163`)، وملخّص ما تغيّر (أمان، عربية، أداء، ميزات) (`lavkooappseller:MIGRATION.md:167-222`). **الحالة:** بنود الهوية نُفِّذت فعلياً بقيم مختلفة عمّا يذكره الملف (2.9)؛ بند keystore `UNKNOWN` (الملف غير متتبَّع بحكم التصميم)؛ بند Firebase منفَّذ جزئياً وغير متّسق (2.9).
- **`docs/seller-center-update-study.md`** (مؤرَّخة 2026-08-23) دراسة لتحديث التطبيق ليغطي حزمة Marketplace الخلفية وتوحيد الواجهة في ست مراحل (`lavkooappseller:docs/seller-center-update-study.md:3-15` و`:211-222`). الحالة المسجَّلة فيها: المرحلة 1 منفَّذة، 2 أساسها منفَّذ، 3 معظمها منفَّذ، **4 (الفريق والأدوار + «إخفاء حسب الصلاحية») دون علامة إنجاز**، و5 كذلك (`lavkooappseller:docs/seller-center-update-study.md:216-220`؛ الاستنتاج بعدم التنفيذ `INFERRED` لأن الجدول لا يحمل حالة صريحة لهذين الصفين). الكود الحالي سبق الوثيقة في جزء من المرحلة 4: دخول الموظف عبر الـ API وشاشات الفريق موجودة (2.3) لكن الإخفاء حسب الصلاحية غير موجود. الوثيقة تسمّي الخلفية «مشروع Pharmacy» وتذكر إصدار `1.1.0+6` و33 ميزة (`lavkooappseller:docs/seller-center-update-study.md:4` و`:25-31`) مقابل 47 ميزة و`3.1.6+49` اليوم.

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الدليل | الحالة |
|---|---|---|---|
| A1 | خادم واحد ثابت ومسارات بلا بادئة دولة | `lavkooappseller:lib/utill/app_constants.dart:11-14` | VERIFIED |
| A2 | لا ترويسة سياق في أي طلب؛ الترويسات الثابتة: Authorization + اللغة فقط | `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56` | VERIFIED |
| A3 | رمز واحد محلي (`token`) = حساب واحد = بائع واحد = متجر واحد | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:118` ، `lavkoo:app/Models/Seller.php:96-99` | VERIFIED |
| A4 | إعدادات واحدة عامة للنظام (نقطة `config` بلا معامل) | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:25` | VERIFIED |
| A5 | عملة افتراضية واحدة وعدد كسور واحد وموضع رمز واحد | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:158` ، `:204` ، `:219` | VERIFIED |
| A6 | كسور = 2 ثابتة في مواضع | `lavkooappseller:lib/helper/price_converter.dart:187` ، `:197` | VERIFIED |
| A7 | رمز دولة هاتفي واحد من الإعداد، وافتراض القالب `+880` | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:212` ، `lavkooappseller:lib/features/auth/controllers/auth_controller.dart:57` | VERIFIED |
| A8 | حدّ أدنى واحد للإصدار لكل منصة (لا لكل دولة) | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:237` | VERIFIED |
| A9 | صيانة عامة واحدة بمفتاح cache واحد | `lavkoo:app/Traits/MaintenanceModeTrait.php:14` | VERIFIED |
| A10 | موضوعا FCM ثابتان عامّان ورمز جهاز واحد لكل بائع | `lavkooappseller:lib/utill/app_constants.dart:400-401` ، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:630-632` | VERIFIED |
| A11 | سمة ويب واحدة نشطة (`active_theme`) تحدد اشتراطات صور المتجر في التطبيق | `lavkooappseller:lib/features/shop/screens/shop_update_screen.dart:549` ، `:625` ، `lavkooappseller:lib/features/auth/screens/registration_screen.dart:216` | VERIFIED |
| A12 | لغتان ثابتتان بـ Locale `ar_SY`/`en_US`؛ ترويسة `lang` لغة لا دولة (والخلفية تعامل `sa` كلغة عربية) | `lavkooappseller:lib/utill/app_constants.dart:410-420` ، `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:27` | VERIFIED |
| A13 | مفاتيح التخزين المحلي غير مقيّدة بحساب أو متجر | القسم 2.7 | VERIFIED |
| A14 | مشروع Firebase واحد يضم عميل تطبيق آخر (`com.lavkoo.app`) | `lavkooappseller:android/app/google-services.json:12` | VERIFIED |

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم في هذا النطاق | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Foundation: distinct concepts (Country / Storefront / Legal Entity / Seller / Shop) | `SellerPrincipal` يفصل أصلاً «الشخص» عن «المتجر» — نقطة توسعة طبيعية لإضافة نطاق | الرمز يُحلّ إلى بائع واحد ومتجر واحد؛ لا مفهوم دولة/كيان في المصادقة ولا في التطبيق؛ `seller_id` هو مفتاح العزل المعتمد في مسارات البائع ويجب ألا يُعاد توظيفه كدولة | `lavkoo:app/Services/Marketplace/SellerPrincipal.php:66-69` ، `lavkoo:app/Models/Seller.php:96-99` |
| Foundation: separate legal selling company per country | — | تسجيل البائع لا يحمل دولة ولا كياناً قانونياً (الحقول: اسم، هاتف، بريد، متجر، رقم ضريبي واحد)؛ مسألة مقيّدة في المسار نفسه (`SEC-E19-01`) | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:220-232` |
| GCC-07 / GCC-09 (سمة لكل دولة؛ تدقيق أثر تطبيق البائع) | تطبيق البائع **لا يشارك** معمارية السمة الديناميكية لتطبيق العميل: سمته مجمَّعة ثابتة (فاتح/داكن) بألوان في الكود | يعتمد على `active_theme` العام (`theme_aster`/`theme_fashion`/`default`) لاشتراط صور المتجر؛ مع سمة لكل دولة يصبح هذا الحقل غامضاً | `lavkooappseller:lib/theme/light_theme.dart:11-12` ، `lavkooappseller:lib/main.dart:263` ، `lavkooappseller:lib/features/shop/screens/shop_update_screen.dart:549` |
| GCC-10 (علامة مشتركة مع استثناءات) | الاسم والشعار مجمَّعان في التطبيق (`Seller Center LV`/`Lavkoo`) | `company_logo`/`company_phone`/`company_email` من الإعداد العام تُعرض في شاشة الصيانة؛ لا استثناء لكل دولة | `lavkooappseller:lib/features/maintenance/maintenance_screen.dart:127-158` |
| GCC-11 (سياسات لكل دولة) | صفحات السياسات تُجلب ديناميكياً بالـ slug | `business-pages?type=default` بلا سياق دولة؛ شروط البائع المعروضة عند الدخول واحدة | `lavkooappseller:lib/features/auth/screens/login_screen.dart:284-290` ، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:310-320` |
| GCC-12 (إيقاف لكل متجر دون كسر الطلبات) | مسارات البائع لا يوقفها وضع الصيانة من جهة الخادم، فتبقى معالجة الطلبات ممكنة تقنياً | الصيانة في التطبيق كل-أو-لا-شيء (`vendor_app`)، وتحجب التطبيق كله؛ لا أوضاع browse-only/redirect ولا إيقاف جزئي لمتجر دولة | `lavkooappseller:lib/features/splash/screens/splash_screen.dart:87-91` ، `lavkoo:app/Traits/MaintenanceModeTrait.php:10-32` |
| GCC-13 (هوية عميل واحدة، تدفقات مالية منفصلة) | غير مباشر | عرض المحفظة/الأرصدة في التطبيق بعملة واحدة وكسور واحدة؛ لا تمييز عملة لكل رصيد | `lavkooappseller:lib/helper/price_converter.dart:18-34` |
| GCC-14 (دخول موظف واحد بنطاقات دول/كيانات فوق الأدوار) | دخول الموظف عبر الـ API موجود، والصلاحيات تُحلّ عند كل طلب، والإنفاذ في الخادم | لا بُعد دولة/كيان في `SellerPrincipal` أو الكتالوج؛ التطبيق لا يستلم صلاحياته فلا يستطيع إخفاء ما لا يُسمح به؛ بريد الموظف فريد لكل متجر لا للمنصة، فحساب واحد لعدة متاجر/دول غير مدعوم | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:30-41` ، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:34-44` |
| GCC-19 (السعودية الافتراضية) | النسخ المثبّتة لا ترسل أي سياق، فأي افتراض خلفي = السعودية ينطبق عليها تلقائياً | يجب أن يبقى عقد `config` والدخول و`cm-firebase-token` و401 كما هو لهذه النسخ (القسم 6، F12) | `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56` |
| Provisional: local pricing, no auto-FX | في `single_currency` لا تحويل | في الوضع المتعدد التطبيق يضرب بأسعار الصرف للعرض ويعكسها قبل الإرسال؛ يجب ألا يُستعمل هذا المسار لتسعير دولة أخرى | `lavkooappseller:lib/helper/price_converter.dart:24` ، `:122-132` |
| Provisional: market-scoped app-local caches / notifications | — | كل المفاتيح المحلية والموضوعان ورمز الجهاز عامة (A10, A13) | القسم 2.6 و2.7 |
| 3-decimal currencies (KWD/BHD/OMR) | الكسور تُقرأ من الإعداد في المسار الرئيسي | إعداد واحد عام + مواضع ثابتة على 2 | `lavkooappseller:lib/helper/price_converter.dart:22` ، `:187` |
| Guardrail 4 (حفظ تطبيق البائع وبوابة الإصدار) | اختبار حارس يربط `pubspec` بالحدّ الأدنى | الحدّ الأدنى الفعلي غير معلوم ومتضارب في الوثائق؛ لا مفتاح تعطيل للبائع | `lavkooappseller:test/app_version_gate_test.dart:17` ، `lavkoo:app/Traits/UpdateClass.php:304-315` |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أقل إضافة موصى بها (توثيق فقط) |
|---|---|---|---|
| عقد `POST auth/login` ⇐ `{token}` وشكل 401 (`loginStatus` / `auth-001`) | Preserve | النسخ المثبّتة تعتمد عليه حرفياً | أي حقول جديدة (نوع الحساب، الصلاحيات، المتاجر المتاحة) تُضاف اختيارياً دون تغيير الموجود |
| `DioClient` (ترويسات ثابتة + `updateAuthToken`/`clearAuthToken`) واختبار `dio_auth_header_test` | Preserve + Extend | نقطة مركزية مختبَرة | إضافة ترويسة سياق اختيارية بالأسلوب نفسه (مدخل واحد في الخريطة) مع اختبار مماثل |
| المواضع التسعة لـ `http.MultipartRequest` | Refactor (قبل GCC) | تقع خارج العميل المركزي فلا ترث أي ترويسة جديدة | توحيدها على `DioClient.postMultipart` أو مساعد واحد يبني الترويسات، بند مُدرَج أصلاً في `MIGRATION.md` |
| `SellerApiAuthMiddleware` + `SellerPrincipal` + `seller_can`/`seller_owner` | Preserve + Extend | نموذج «شخص/متجر/صلاحيات» قائم ويُحلّ عند كل طلب | إضافة بُعد النطاق (دول/كيانات مسموحة) إلى الـ principal دون تغيير معنى `sellerId()` |
| نقطة «من أنا» للتطبيق | New | التطبيق لا يعرف صلاحياته ولا متاجره | مسار قراءة يعيد نوع الحساب، الصلاحيات الفعلية، والمتاجر/الكيانات المتاحة؛ يستهلكه التطبيق لإخفاء الواجهة واختيار المتجر |
| `GET /api/v1/config` المشترك | Preserve للنسخ القديمة + Extend | يكسر التطبيقين معاً إن تغيّر شكله | الإبقاء على الاستجابة الافتراضية (السعودية)؛ السياق يُضاف كمعامل/ترويسة اختيارية أو نقطة إعدادات خاصة بالبائع بعد الدخول |
| بوابة الإصدار (`AppVersion` + `seller_app_version_control`) | Preserve | بوابة عاملة مع اختبار | توثيق القيمة الإنتاجية وربطها بثابت الاختبار؛ لا حاجة لبوابة لكل دولة ما دام التطبيق واحداً |
| شاشة الصيانة وموضوع `maintenance_mode_start_vendor` | Preserve + Extend | تعمل للإيقاف الشامل | تمثيل إيقاف متجر دولة كحالة بيانات داخل التطبيق (شريط/قيد على المتجر المختار) لا كحجب كامل |
| تسجيل رمز FCM (`cm-firebase-token`) | Extend | عمود واحد لكل بائع | جدول أجهزة مرتبط بالـ principal (مالك/موظف) مع الإبقاء على المسار الحالي للنسخ القديمة |
| مواضيع FCM | Extend / New | `lavkoo_seller` بلا مرسِل | قرار: إلغاؤه أو تعريف مواضيع مسمّاة بنطاق (تطبيق + دولة)، مع العلم أن المشروع يضم عميل تطبيق آخر |
| توجيه الإشعارات بحسب `type` | Preserve + Extend | يعمل للأنواع الحالية | إضافة معرّف المتجر/الدولة للحمولة؛ إزالة الاعتماد على نص العنوان |
| `PriceConverter` | Refactor محدود | كسور ثابتة وإسناد `usdCurrency!` | تمرير عملة/كسور السجل نفسه (من الطلب أو المتجر) بدل الإعداد العام؛ إزالة الثوابت 2 |
| قائمة اللغات الثابتة و`lang` | Preserve | تعمل؛ `sa` مطويّة كلغة في الخلفية | عدم حمل الدولة في `lang` إطلاقاً؛ مفتاح مستقل للدولة |
| التخزين المحلي | Extend | مفاتيح عامة | إسباق المفاتيح الحساسة للسياق (`cart_list`، طابور POS، `currency`) بمعرّف الحساب/المتجر عند إدخال اختيار المتجر |
| `AppHealthReporter` | Preserve + Extend | بسيط وغير معرِّف | حقل اختياري يميّز التطبيق (seller/customer) |
| هوية التطبيق (package/bundle/Firebase/اسم الحزمة) | Preserve | تعليمات المشروع تمنع إعادة التسمية الآلية | تحديث `MIGRATION.md` ليطابق الواقع؛ حسم عدم اتساق Firebase iOS |
| ميزة `security` (فريق/أدوار/مفاتيح/Webhooks) | Preserve + Extend | مكتملة وظيفياً | عرض/تحرير نطاق الدولة للدور عند إضافته خلفياً |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E19-F01 | لا سياق متجر/دولة/كيان قانوني في تطبيق البائع ولا في مصادقة `v3/seller`: الرمز ⇐ بائع واحد ⇐ متجر واحد، ولا ترويسة سياق، ولا شاشة اختيار | P1 | VERIFIED | `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56` ، `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:56-64` ، `lavkoo:app/Models/Seller.php:96-99` | بائع يعمل في عدة دول يحتاج اليوم حساباً منفصلاً لكل دولة مع خروج/دخول يدوي (رمز واحد على الجهاز)، أو تصميماً جديداً | قرار معماري موثَّق بين (أ) حساب بائع لكل كيان قانوني مع مبدّل حسابات في التطبيق، أو (ب) دخول واحد + اختيار متجر + رمز/ترويسة بنطاق؛ في الحالتين الافتراضي للنسخ القديمة = السعودية |
| E19-F02 | التطبيق لا يستلم صلاحيات المستخدم الحالي ولا نوعه؛ الموظف يرى الواجهة كاملة ويتلقى 403 | P1 | VERIFIED | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:82` ، `lavkooappseller:lib/features/menu/screens/more_screen.dart:163-165` ، `lavkooappseller:docs/seller-center-update-study.md:153` | GCC-14 يضيف نطاقات دول فوق الأدوار؛ دون قناة «من أنا» لا يستطيع التطبيق عرض ما يخص نطاق الموظف فقط | نقطة قراءة للـ principal (صلاحيات + نطاقات) وإخفاء شرطي في الواجهة؛ الإنفاذ يبقى في الخادم |
| E19-F03 | تطبيق البائع يشارك نقطة `GET /api/v1/config` العامة مع تطبيق العميل: عملة، كسور، رمز دولة، سمة نشطة، صيانة، حد إصدار — كلها عامة | P1 | VERIFIED | `lavkooappseller:lib/utill/app_constants.dart:14` ، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:133-291` | أي تحويل للإعدادات إلى «لكل متجر» يغيّر ما يراه التطبيقان معاً؛ والتطبيق يجلبها قبل الدخول فلا يعرف المتجر بعد | إبقاء الاستجابة غير المسيَّقة = افتراضي السعودية؛ إعدادات المتجر المختار تُجلب بعد الدخول من مصدر مصادَق |
| E19-F04 | تنسيق المال: عدد كسور واحد عام، ثوابت `toStringAsFixed(2)`، وتحويل عبر USD بإسناد غير آمن | P1 | VERIFIED (الكود) / INFERRED (أثر السحب) | `lavkooappseller:lib/helper/price_converter.dart:22-24` ، `:187` ، `:197` ، `:212-224` | KWD/BHD/OMR تُعرض وتُقتطع بكسرين أو بكسور السعودية؛ قد يغيّر الاقتطاع في مسار السحب المبلغ المرسَل (`INFERRED`: لم يُتتبَّع استدعاء الدالة إلى طلب السحب) | اشتقاق الكسور والرمز من عملة السجل/المتجر؛ اختبارات عقد بثلاث كسور |
| E19-F05 | الإشعارات غير مسيَّقة: عمود رمز واحد لكل بائع، موضوعان عامّان، حمولة بلا معرّف متجر، و`lavkoo_seller` بلا مرسِل | P1 | VERIFIED | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:630-632` ، `lavkooappseller:lib/utill/app_constants.dart:400-401` ، `lavkooappseller:lib/notification/models/notification_body.dart:19-31` | آخر جهاز يسجّل يستلم وحده؛ لا إشعار لكل دولة؛ إعلان صيانة دولة يصل الجميع | جدول أجهزة لكل principal، مواضيع مسمّاة بنطاق، وإضافة `storefront`/`country` للحمولة مع توافق خلفي |
| E19-F06 | بوابة الإصدار: الحد الأدنى الإنتاجي غير معلوم ومتضارب (بذر 14.1، وثيقة 14.1، اختبار 2.0.1، التطبيق 3.1.6)؛ لا مفتاح تعطيل للبائع (الحقل `status` مثبَّت على 1 في اللوحة ومُهمَل في التطبيق)؛ غياب الحقول = بلا بوابة | P1 | UNKNOWN (القيمة الإنتاجية) / VERIFIED (المنطق) | `lavkoo:app/Traits/UpdateClass.php:304-315` ، `lavkooappseller:test/app_version_gate_test.dart:17` ، `lavkooappseller:MIGRATION.md:97-109` ، `lavkooappseller:lib/features/splash/screens/splash_screen.dart:73-86` | قيمة خاطئة تحجب كل البائعين قبل شاشة الدخول (حدثت سابقاً)؛ قاعدة بيانات جديدة مبذورة تعيد 14.1 | قراءة القيمة الإنتاجية وتوثيقها ومطابقتها مع ثابت الاختبار؛ جعلها بنداً في قائمة Go/No-Go لأي بيئة GCC جديدة (قاعدة مبذورة حديثاً تحمل 14.1) |
| E19-F07 | الصيانة في التطبيق شاملة فقط ومبنية على مفتاح cache عام؛ لا تمثيل لأوضاع GCC-12 لكل متجر | P1 | VERIFIED | `lavkooappseller:lib/features/splash/screens/splash_screen.dart:87-91` ، `lavkoo:app/Traits/MaintenanceModeTrait.php:14` | إيقاف دولة واحدة إما لا ينعكس على التطبيق أو يحجبه كلياً فيمنع معالجة طلبات الدول الأخرى والالتزامات السابقة | إبقاء الصيانة الشاملة كما هي؛ تمثيل إيقاف المتجر كحالة على مستوى المتجر داخل التطبيق |
| E19-F08 | تسعة طلبات `http.MultipartRequest` تقع خارج `DioClient` (ترويسة Authorization يدوية في ثمانية منها، والتسجيل بلا أي ترويسة؛ لا لغة في أي منها) | P1 | VERIFIED | `lavkooappseller:lib/features/bank_info/domain/repositories/bank_info_repository.dart:19-20` ، `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:39-40` ، `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:196` | أي ترويسة سياق تُضاف مركزياً لن تصل إلى هذه المواضع؛ الأثر المقيّد في `SEC-E19-12` | توحيدها قبل إدخال أي ترويسة سياق؛ اختبار يمنع ظهور مواضع جديدة |
| E19-F09 | عدم اتساق إعداد Firebase: `firebase.json` لمشروع آخر، خيارات iOS تمزج رقم مرسِل/معرّف تطبيق من مشروع برقم مختلف مع `projectId` الحالي، و`aps-environment = development` | P1 | UNKNOWN (الأثر) / VERIFIED (القيم) | `lavkooappseller:firebase.json:1` ، `lavkooappseller:lib/firebase_options.dart:52-68` ، `lavkooappseller:ios/Runner/GoogleService-Info.plist:13-20` ، `lavkooappseller:ios/Runner/Runner.entitlements:5-6` | إشعارات iOS للبائع قد لا تصل من الخلفية؛ إعادة توليد الإعداد بـ FlutterFire قد تعيد ربط التطبيق بمشروع قديم | حسمها من لوحة Firebase واختبار جهاز؛ لا تغيير معرّفات دون موافقة المالك |
| E19-F10 | توجيه الإشعار عند الفتح من الخلفية يعتمد على نص العنوان الإنجليزي، والإشعار المحلي بمعرّف ثابت 0 (كل إشعار يستبدل سابقه) | P2 | VERIFIED (الكود) / INFERRED (الأثر مع العربية) | `lavkooappseller:lib/notification/my_notification.dart:162-167` ، `:290` ، `:309` | توجيه خاطئ بالعربية؛ فقدان إشعارات متتالية | التوجيه على `type` فقط؛ معرّف فريد لكل إشعار |
| E19-F11 | مخطَّط `lavkooseller://` مصرَّح به بلا معالج؛ لا App/Universal Links للبائع | INFO | VERIFIED | `lavkooappseller:android/app/src/main/AndroidManifest.xml:88-93` ، `lavkooappseller:ios/Runner/Info.plist:27-37` | لا روابط عميقة قائمة يجب حفظها؛ أي رابط لاحق (مثلاً من بريد إلى طلب في دولة) يحتاج بناءً جديداً | توثيق أن العقد الحالي = فتح التطبيق فقط |
| E19-F12 | قيود التوافق الخلفي للنسخ المثبّتة: (أ) `config` يجب أن يبقي `colors` و`unit` و`currency_list` غير null؛ (ب) `token` سلسلة في جذر استجابة الدخول؛ (ج) 401 بالشكل الحالي؛ (د) `PUT` عبر `_method`؛ (هـ) لا سياق ⇐ السعودية | P1 | VERIFIED | `lavkooappseller:lib/features/splash/domain/models/config_model.dart:229-230` ، `lavkooappseller:lib/features/splash/controllers/splash_controller.dart:71` ، `lavkooappseller:lib/features/auth/domain/services/auth_service.dart:81-82` ، `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:91-94` | تغيير أيٍّ منها يُسقط البداية أو الدخول في كل نسخة منشورة؛ استثناء التحليل في `fromJson` غير ملتقَط في `initConfig` فيبقى التطبيق على شاشة البداية بلا زر إعادة المحاولة (`INFERRED` من غياب أي `try`/`catchError` حول التحليل) | اختبارات عقد خلفية تثبّت هذه الأشكال قبل أي تعديل GCC على `ConfigController` أو الدخول |
| E19-F13 | اشتراطات صور المتجر في التطبيق مرتبطة بقيمة `active_theme` العامة | P2 | VERIFIED | `lavkooappseller:lib/features/shop/screens/shop_update_screen.dart:549` ، `:625` ، `lavkooappseller:lib/features/auth/widgets/info_field_widget.dart:528` | مع سمة لكل دولة (GCC-07) تصبح القيمة العامة بلا معنى للبائع | تعريف خلفي صريح لما يُطلب من صور لكل متجر بدل الاستدلال من اسم السمة |
| E19-F14 | جلسة واحدة لكل حساب: كل دخول يستبدل الرمز؛ حدود المحاولات لمسارات المصادقة مسألة مقيّدة (`SEC-E19-11`) | P2 | VERIFIED (الكود) / UNKNOWN (القيمة الإنتاجية) | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:99-100` | مشاركة حساب المالك بين أجهزة فريق متعدد الدول تطرد الأجهزة بعضها بعضاً | الاعتماد على حسابات الموظفين؛ مراجعة إعداد حدود المحاولات ضمن `SEC-E19-11` |
| E19-F15 | مفاتيح التخزين المحلي عامة على مستوى التثبيت (عملة، سلة POS، طابور المبيعات غير المتصلة) | P1 | VERIFIED | القسم 2.7؛ `lavkooappseller:lib/features/splash/domain/repositories/splash_repository.dart:26-36` | عند إدخال تبديل متجر/حساب قد تُعاد مبيعات POS معلّقة أو سلة على متجر/كيان آخر | إسباق المفاتيح بمعرّف السياق قبل تفعيل أي تبديل؛ يُنسَّق مع مدقق POS (الجزء الثاني) |
| E19-F16 | مُبلِّغ الصحة لا يميّز تطبيق البائع عن تطبيق العميل ولا الدولة | P2 | VERIFIED | `lavkooappseller:lib/helper/app_health_reporter.dart:71-79` ، `lavkoo:app/Services/Monitoring/Ingest/AppHealthRecorder.php:55-63` | مقاييس crash-free تختلط إذا تطابق رقما الإصدار | حقل اختياري للتطبيق |
| E19-F17 | الوثائق (`MIGRATION.md`، الدراسة، `README`) متقادمة مقابل الهوية والإصدار الفعليين، وسلاسل علامات قديمة باقية | P2 | VERIFIED | `lavkooappseller:MIGRATION.md:42-65` ، `lavkooappseller:docs/seller-center-update-study.md:66-70` ، `lavkooappseller:android/app/build.gradle.kts:39` | مخاطرة تشغيلية: من يتبع الوثيقة يعيد توليد إعداد Firebase أو يغيّر المعرّف | تحديث وثائقي فقط؛ **عدم** إعادة تسمية `syriacosmeticsmanger` أو المعرّفات آلياً |
| E19-F18 | اللغة: قائمتان ثابتتان بـ `ar_SY`؛ الخلفية تطوي `sa`⇐`ar` في ترويسة `lang` | INFO | VERIFIED | `lavkooappseller:lib/utill/app_constants.dart:410-420` ، `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29` | لا يجوز استعمال `lang` أو قيمة `sa` لحمل الدولة: نسخ قديمة أرسلتها بمعنى اللغة | مفتاح سياق مستقل تماماً عن اللغة |
| E19-F19 | دخول الموظف قائم على `seller_staff` ببريد فريد لكل متجر؛ يُجرَّب فقط إذا لم يوجد بائع بالبريد نفسه | INFO | VERIFIED | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:34-44` ، `:72-84` | «دخول واحد لعدة دول» (GCC-14) غير ممثَّل للبائعين: الموظف مرتبط ببائع واحد | يُحسم مع قرار E19-F01 |
| SEC-E19-01 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P0 | VERIFIED (مسار الكود) | الملحق المقيّد | مقيّد | مقيّد — تُعالَج قبل أي عمل GCC على تسجيل البائعين |
| SEC-E19-02 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P0 | VERIFIED (مسار الكود) | الملحق المقيّد | مقيّد | مقيّد — أولوية قصوى |
| SEC-E19-03 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED / UNKNOWN (.env) | الملحق المقيّد | مقيّد | مقيّد |
| SEC-E19-04 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | مقيّد | مقيّد |
| SEC-E19-05 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | مقيّد | مقيّد |
| SEC-E19-06 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | مقيّد | مقيّد |
| SEC-E19-07 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | مقيّد | مقيّد |
| SEC-E19-08 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | مقيّد | مقيّد |
| SEC-E19-09 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | مقيّد | مقيّد |

مسائل مقيّدة إضافية أُخرجت من النص الرئيسي بعد التحقق المستقل (تُعرض هنا بمؤشر محايد فقط):

SEC-E19-10: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E19-11: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E19-12: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### ما يحتاجه بائع يعمل في عدة دول (عدسة GCC — توثيق فقط)

1. **اختيار المتجر/الكيان القانوني:** غير موجود. يلزم مصدر خلفي لقائمة المتاجر المتاحة للـ principal، وشاشة/مبدّل في التطبيق، وحالة «المتجر الحالي» تُحفظ محلياً.
2. **رمز أو ترويسة بنطاق:** اليوم رمز عام للبائع. الخياران: رمز يُصدَر لمتجر بعينه (تبديل = إصدار رمز) أو رمز واحد + ترويسة متجر يتحقق منها الخادم مقابل نطاق الـ principal. في الحالتين يجب توحيد مواضع `http.MultipartRequest` أولاً (E19-F08) وأن يرفض الخادم — لا التطبيق — أي متجر خارج النطاق.
3. **مواضيع/أجهزة لكل متجر:** تسجيل الجهاز لكل principal مع قائمة المتاجر المشترَك فيها، وحمولة تحمل المتجر ليعرض التطبيق «طلب جديد — متجر الإمارات» ويوجّه بعد التبديل.
4. **إعدادات المتجر المختار:** عملة، كسور، ضريبة، طرق شحن، سياسات، حالة الإيقاف — تُجلب بعد الاختيار، لا من `config` العام.
5. **التخزين المحلي والـ POS:** إسباق بالمتجر (E19-F15).
6. **التوافق الخلفي:** النسخ المثبّتة (بلا سياق) تُعامَل كمتجر السعودية الافتراضي (GCC-19) وتبقى تعمل دون تحديث إجباري؛ التحديث الإجباري يُستعمل فقط حين يصبح عدم إرسال السياق خطراً مالياً، وعندها تُرفع `seller_app_version_control` بعد التحقق من القيمة (E19-F06).
7. **السمة:** تطبيق البائع لا يستهلك أي نقطة من نظام السمة الديناميكية (بحث `theme-delivery|ThemeDelivery|theme_sync|/api/v[0-9]/[a-z-]*theme` ← صفر)؛ الأثر الوحيد هو `active_theme` (E19-F13) ونوع إشعار `theme` الذي يفتح قائمة الإشعارات فقط.

## 7. أسئلة مفتوحة وعناصر UNKNOWN

| # | السؤال | ما يحسمه |
|---|---|---|
| U1 | القيمة الحالية لـ `seller_app_version_control` في الإنتاج (المنصتان، `status`، `link`) | قراءة `business_settings` في الإنتاج (قراءة فقط بموافقة المالك) |
| U2 | أي إصدارات/معرّفات لتطبيق البائع منشورة فعلاً في المتاجر، وبأي `applicationId` (هل النسخ في الميدان تحمل معرّفاً سابقاً؟) | لوحتا Google Play و App Store |
| U3 | هل إشعارات iOS للبائع تعمل مع الإعداد الحالي غير المتّسق؟ وأي مشروع Firebase تستعمله الخلفية للإرسال؟ | لوحة Firebase + إعداد الخلفية (`push_notification_key`/ملف الخدمة) + اختبار جهاز |
| U4 | قيمة `auth_attempts_per_minute` و`APP_MODE` و`business_mode` و`vendor_forgot_password_method` و`decimal_point_settings` و`currency_model` و`country_code` في الإنتاج | `.env` و`business_settings` |
| U5 | هل `android/key.properties` وkeystore الإصدار موجودان ومؤمَّنان | سؤال المالك (الملف غير متتبَّع عمداً) |
| U6 | هل يوجد بائعون حقيقيون يعملون (أو يُتوقَّع أن يعملوا) في أكثر من دولة بالحساب نفسه — يحدد الخيار (أ) أو (ب) في E19-F01 | قرار المالك |
| U7 | هل سبق أن استُخدم الموضوع `lavkoo_seller` من أداة خارجية (لوحة Firebase) للإرسال اليدوي | سؤال المالك / سجل Firebase |
| U8 | تاريخ التطوير السابق لتطبيق البائع (المستودع الحالي 3 commits فقط) ومصدره | `UNKNOWN (missing access)` — لا يوجد مستودع بائع قديم ضمن المستودعات المتاحة |
| U9 | هل توجد وسطاء عامة (global middleware) توقف مسارات `api/v3/seller` أثناء الصيانة | تحقّق جزئي: الوسيط العام المرتبط بالإيقاف هو `CheckForMaintenanceMode` من إطار العمل، وهو لوضع الإيقاف الفني لا لمفتاح الصيانة؛ يبقى فحص بقية سلسلة الوسطاء العامة (مدقق E01) |

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| عنوان الخادم ثابت `https://lavkoo.com` | `lavkooappseller:lib/utill/app_constants.dart:11` | VERIFIED |
| عميل Dio واحد مسجَّل Singleton | `lavkooappseller:lib/di_container.dart:240` | VERIFIED |
| ترويسات العميل الافتراضية (Authorization + لغة) | `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56` | VERIFIED |
| `updateAuthToken` يتجاهل الفارغ | `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:77-84` | VERIFIED |
| اختبار عدم إرسال `Bearer null` | `lavkooappseller:test/dio_auth_header_test.dart:32-37` | VERIFIED |
| الحد الأدنى لطول الرمز في الخادم 30 | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:16` | VERIFIED |
| طلب الدخول بالبريد وكلمة المرور | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:26-35` | VERIFIED |
| حفظ الرمز في SharedPreferences | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:114-122` | VERIFIED |
| دخول الموظف من `seller_staff` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:28-54` | VERIFIED |
| إصدار رمز المالك واستبدال السابق | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:98-114` | VERIFIED |
| حلّ الرمز إلى principal عند كل طلب | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:98-120` | VERIFIED |
| كتالوج الصلاحيات (11 مفتاحاً) | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:30-41` | VERIFIED |
| وسيطا `seller_can` و`seller_owner` | `lavkoo:bootstrap/app.php:124-127` | VERIFIED |
| `seller-info` يعيد سجل المالك | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:207-212` | VERIFIED |
| بوابة POS الوحيدة في التنقل | `lavkooappseller:lib/features/dashboard/screens/dashboard_screen.dart:85-98` | VERIFIED |
| مسح الجلسة عند قيمة الرفض الثابتة | `lavkooappseller:lib/helper/api_checker.dart:21-43` | VERIFIED |
| الخروج محلي فقط | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:135-163` | VERIFIED |
| `config` يُجلب من `/api/v1/config` | `lavkooappseller:lib/features/splash/domain/repositories/splash_repository.dart:16-23` | VERIFIED |
| `colors`/`unit` بلا فحص null | `lavkooappseller:lib/features/splash/domain/models/config_model.dart:229-230` | VERIFIED |
| `seller_app_version_control` في استجابة `config` | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:237` | VERIFIED |
| بذر الحد الأدنى 14.1 | `lavkoo:app/Traits/UpdateClass.php:304-315` | VERIFIED |
| قراءة الحد الأدنى وافتراضه `'0'` | `lavkooappseller:lib/features/splash/screens/splash_screen.dart:73-82` | VERIFIED |
| منطق `compare` و`isUpdateRequired` | `lavkooappseller:lib/helper/version_helper.dart:35-53` | VERIFIED |
| ثابت الاختبار الحارس `2.0.1` | `lavkooappseller:test/app_version_gate_test.dart:17` | VERIFIED |
| الوثيقة تقول الإنتاج يشترط 14.1 | `lavkooappseller:MIGRATION.md:97-109` | VERIFIED (نص الوثيقة) |
| شاشة الصيانة وشرطها | `lavkooappseller:lib/features/splash/screens/splash_screen.dart:87-91` | VERIFIED |
| مفتاح cache الصيانة العام | `lavkoo:app/Traits/MaintenanceModeTrait.php:14` | VERIFIED |
| إرسال موضوع صيانة البائع | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:274-276` | VERIFIED |
| تسجيل رمز FCM والاشتراك في الموضوعين | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:86-99` | VERIFIED |
| عمود رمز واحد لكل بائع | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:619-635` | VERIFIED |
| ثوابت المواضيع | `lavkooappseller:lib/utill/app_constants.dart:400-401` | VERIFIED |
| الموضوع الافتراضي للإرسال `sixvalley` | `lavkoo:app/Traits/PushNotificationTrait.php:449-453` | VERIFIED |
| توجيه الإقلاع البارد بحسب `type` | `lavkooappseller:lib/features/splash/screens/splash_screen.dart:93-135` | VERIFIED |
| توجيه الخلفية بحسب نص العنوان | `lavkooappseller:lib/notification/my_notification.dart:154-186` | VERIFIED |
| مخطَّط `lavkooseller` مصرَّح به | `lavkooappseller:android/app/src/main/AndroidManifest.xml:88-93` | VERIFIED |
| ثوابت مفاتيح التخزين | `lavkooappseller:lib/utill/app_constants.dart:389-405` | VERIFIED |
| اللغات الثابتة `ar_SY`/`en_US` | `lavkooappseller:lib/utill/app_constants.dart:407-421` | VERIFIED |
| طيّ `sa`⇐`ar` في ترويسة `lang` | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29` | VERIFIED |
| الكسور من الإعداد العام | `lavkooappseller:lib/helper/price_converter.dart:22` | VERIFIED |
| كسور ثابتة 2 | `lavkooappseller:lib/helper/price_converter.dart:187` | VERIFIED |
| حمولة مُبلِّغ الصحة | `lavkooappseller:lib/helper/app_health_reporter.dart:71-79` | VERIFIED |
| فهرسة الصحة بـ `platform:version` | `lavkoo:app/Services/Monitoring/Ingest/AppHealthRecorder.php:55-63` | VERIFIED |
| مسارات ميزة `security` | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:11-28` | VERIFIED |
| حصر مسارات الفريق بـ `staff.manage` | `lavkoo:routes/rest_api/v3/seller.php:536-558` | VERIFIED |
| `applicationId` الحالي | `lavkooappseller:android/app/build.gradle.kts:39` | VERIFIED |
| iOS bundle id | `lavkooappseller:ios/Runner.xcodeproj/project.pbxproj:443` | VERIFIED |
| Firebase project id (Android) وعميلان | `lavkooappseller:android/app/google-services.json:3-5` | VERIFIED |
| `firebase.json` لمشروع آخر | `lavkooappseller:firebase.json:1` | VERIFIED |
| اسم حزمة Dart القديم | `lavkooappseller:pubspec.yaml:1` | VERIFIED |
| اشتراط الصور بحسب `active_theme` | `lavkooappseller:lib/features/shop/screens/shop_update_screen.dart:549` | VERIFIED |
| حالة مراحل الدراسة | `lavkooappseller:docs/seller-center-update-study.md:216-220` | VERIFIED (المراحل 1–3) / INFERRED (المرحلتان 4 و5) |
| غياب مرسِل `lavkoo_seller` في الخلفية | بحث مسجَّل في القسم 1 (صفر نتائج) | VERIFIED (ضمن حدود البحث) |
| غياب معالج الروابط العميقة في Dart | بحث مسجَّل في القسم 1 | VERIFIED (ضمن حدود البحث) |
| القيمة الإنتاجية لبوابة الإصدار | — | UNKNOWN (business_settings) |
| عمل إشعارات iOS | — | UNKNOWN (لوحة Firebase / جهاز) |

## 9. سجل التحقق المستقل

> المدقّق: مراجعة مستقلة للقراءة فقط — التاريخ: 2026-11-12 (+03:00). النطاق: هذا الملف والملحق المقيّد. لم يُعدَّل أي مستودع، ولم يُنفَّذ أي كود أو اختبار أو اتصال شبكي. تبقى قيم الإنتاج و`.env` و`business_settings` موسومة `UNKNOWN`.

### عدّادات التحقق

| البند | العدد (تقريبي، بحساب الادعاءات) |
|---|---|
| الادعاءات المفحوصة | ≈120 |
| مؤكّدة دون تغيير | ≈102 |
| مصحّحة (رقم أو صياغة أو حالة أو استشهاد) | ≈14 |
| مُفنَّدة جزئياً | 1 |
| مُفنَّدة كلياً | 0 |
| غير قابلة للتحقق (بقيت `INFERRED` أو `UNKNOWN`) | 3 |

### التصحيحات

1. القسم 2.1: الترويسة `Authorization` تُضاف يدوياً في ثمانية من المواضع التسعة فقط، والتسجيل لا يضيف أي ترويسة (تفنيد جزئي للصياغة السابقة).
2. القسم 2.5: حكم الصيانة على مسارات البائع انتقل من `INFERRED` إلى `VERIFIED`، مع توضيح أن الوسيط العام `CheckForMaintenanceMode` وسيط إطار عمل لوضع الإيقاف الفني، لا لمفتاح الصيانة.
3. القسم 2.5 و§7 (U9): إجابة جزئية؛ بقي فحص بقية سلسلة الوسطاء العامة.
4. القسم 2.7: عدد مواضع الرمز `+880` من 12 تقريباً إلى 17 سطراً في `lib/`.
5. القسم 2.9: عدّ حزمة Dart من 630 ملفاً و4,505 سطراً إلى 629 ملفاً و4,504 سطراً.
6. القسمان 2.3 و2.10 والقسم 8: حالة المرحلتين 4 و5 في الدراسة تُقرأ `INFERRED` لأن الجدول لا يحمل علامة إنجاز لهما، بدل تقرير "غير منفّذة" كحقيقة.
7. القسم 2.6: موضوع `sixvalley` "وارد في قائمة مواضيع تطبيق العميل" بدل "هو موضوع تطبيق العميل".
8. القسم 4: `seller_id` مفتاح العزل "المعتمد" في مسارات البائع، بدل "الوحيد".
9. القسم 6 (F04): الحالة `VERIFIED` للكود و`INFERRED` لأثر السحب.
10. القسم 6 (F08): تصحيح عدد الترويسات، ونقل الأثر المقيّد إلى الملحق.
11. القسم 6 (F14): نقل تفاصيل حدود المحاولات إلى الملحق.
12. القسم 2.2: تحديث الاستشهادات لتغيير كلمة المرور وتعليق البائع كمصدرين لاستبدال الرمز.
13. القسم 2.8: نقل تفاصيل ضوابط نقطة الاستقبال العامة إلى الملحق.
14. القسمان 2.2 و8: قيمة الرفض الحرفية في الكود استُبدلت بوصف محايد مع الاستشهاد بالسطر.

### تحقق من الملحق المقيّد

- SEC-E19-01 إلى SEC-E19-06 (P0/P1) أُعيد التحقق منها بقراءة الكود المستشهَد به: مؤكّدة في جوهرها.
- SEC-E19-06: اسم الدور المذكور في المسودة لا يقابله اسم في الكود، فصيغ الوصف على قاعدة الصلاحيات.
- SEC-E19-07: سلوك `debugPrint` في بناء release مصنّف `INFERRED`.
- المسائل المنقولة من الملف الرئيسي: ثلاث جديدة (SEC-E19-10 و11 و12)؛ وأُلحقت نصوص أصلية بالمسألتين الموجودتين SEC-E19-04 و05.

### أعمال التحقق

- مدقّق الاستشهادات: 0 عيوب (330 استشهاداً بالملف، 6 استشهادات commit).
- مدقّق الحساسية: 0 نتائج.
- المسح الدلالي: الفقرات P0/P1 من القسم 6 (F01 إلى F09 وF12 وF15) وكل مسائل SEC-E19-01 إلى 06، ونحو عشرين ادعاءً مركزياً من الأقسام 2 إلى 5، ومصفوفة الصلاحيات والإصدار والإشعارات.
- فحوص الغياب: أُعيد البحث بأنماط مختلفة لكل ادعاء غياب، وسُجّلت الأنماط في الملحق المقيّد.

### فجوات التغطية

- لم يُفحص: قيم الإنتاج (`business_settings`، `.env`)، والنسخ المنشورة في المتاجر، وإعدادات Firebase Console، وسطراً بسطر: نطاق `ConfigController` (133–291) وكامل سلسلة الوسطاء العامة (مدقّق E01)، وشاشات الميزات التشغيلية (الجزء الثاني).
- المستودع الخلفي `lavkoo`: الفرع `main` عند الـ SHA المدقَّق `7f42777c`، وHEAD المحلي تقدّم بـ commits توثيقية فقط؛ لا فرق في الكود خارج `docs/` بين الـ SHA المدقَّق وHEAD.
- المستودع القديم غير متاح، وفق المهمة.

### مسائل لم تُحسم (أسماء الأقسام فقط)

- القسم 2.2 والقسم 8: أثر انتهاء الرمز وإبطاله من جهة الخادم (مُحال إلى المسائل المقيّدة).
- القسم 2.5: القيمة الإنتاجية لبوابة الإصدار `UNKNOWN`.
- القسم 2.5 و§7 (U9): بقية سلسلة الوسطاء العامة.
- القسم 2.8: أثر ضوابط نقطة الاستقبال العامة `UNKNOWN`.
- القسم 2.9 والقسم 6 (F09): أثر عدم اتساق Firebase `UNKNOWN`.
