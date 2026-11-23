# E16 — تطبيق العميل Flutter: البنية والإعدادات وانتقال السياق (Customer Flutter app: architecture, configuration, context propagation)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkooapp@eedbbb8fd0303aa9fed5d4ff0ac45b3a409abf7a` (النطاق الأساسي) — `ksaapp98@79f50bec1c419cae8bc1a6f4c314962d3b682ac0` (تطبيق العميل القديم، مرجع للمقارنة فقط) — `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` (مقاطع تقاطعية محدودة؛ الإيداعات المحلية اللاحقة لهذا الـ SHA تضيف ملفات توثيق تحت `docs/gcc` فقط ولا تغيّر الكود — تحقّق منه المراجع المستقل بـ `git diff --stat`) — `lavkooappseller@8ea00922e44db3d69059d46892f8f4f84349b580` (معرّفات الحزمة فقط).

## 1. النطاق وما تم فحصه

**ما تمت تغطيته (قراءة ثابتة فقط، دون تشغيل `flutter` أو `dart` أو أي اختبار):**

- ملفات الجذر: `lavkooapp:pubspec.yaml`، `lavkooapp:README.md`، `lavkooapp:CLAUDE.md`، `lavkooapp:ANIMATION_SYSTEM_GUIDE.md`، `lavkooapp:firebase.json`، `lavkooapp:update_lang.dart`، `lavkooapp:backup_chat.ps1`، `lavkooapp:.gitignore`.
- نقطة الدخول والحقن: `lavkooapp:lib/main.dart` (364 سطراً) و`lavkooapp:lib/di_container.dart` (640 سطراً) كاملين.
- طبقة البيانات كاملة (16 ملف Dart): `lib/data/datasource/remote/dio/` و`lib/data/datasource/remote/exception/` و`lib/data/local/` و`lib/data/reposotories/` و`lib/data/services/` و`lib/data/model/`.
- الثوابت والمساعدات: `lavkooapp:lib/utill/app_constants.dart` (413 سطراً، كاملاً)، و`price_converter.dart`، `country_code_helper.dart`، `data_sync_helper.dart`، `db_helper.dart`، `api_checker.dart`، `phone_verification_helper.dart`، `number_checker_helper.dart`، `network_info.dart`، ومقاطع التوجيه في `lavkooapp:lib/helper/route_healper.dart` (1908 أسطر: ثوابت المسارات، `GoRouter`، `_routeHandler`).
- الميزات: `splash` (المتحكم، المستودع، الشاشة، `config_model.dart` بكامل أسطره الـ 1307)، `update`، `maintenance`، `setting`، `onboarding`، `auth` (المتحكم 1308 أسطر والمستودع 501 سطراً كاملين، ومقاطع من الشاشات)، `deep_link`، `location`، `notification`، `address` (النموذج والمستودع والمتحكم ومقاطع الشاشة)، ومقاطع من `dynamic_theme` (المستودع، `capability_registry.dart`، `theme_action_resolver.dart`) بقدر ما يخصّ الترويسات ومفاتيح التخزين.
- التوطين والإشعارات: `lib/localization/**`، `lib/push_notification/**`، والمجلدان العلويان `localization/` و`push_notification/` (مقارنة `diff` مع نسخ `lib/`).
- المنصّتان: `android/app/build.gradle.kts`، `android/app/src/main/AndroidManifest.xml`، `res/values/strings.xml`، `res/xml/network_security_config.xml`، ملفّا `MainActivity.kt`، `google-services.json` (المعرّفات فقط)، `ios/Runner/Info.plist`، `Runner.entitlements`، `AppDelegate.swift`، معرّفات `project.pbxproj`، `GoogleService-Info.plist` (المعرّفات فقط)، `lib/firebase_options.dart` (المعرّفات فقط).
- الاختبارات: قائمة `test/` (11 ملفاً) وقراءة `test/deep_link_resolver_test.dart` و`test/localization_keys_test.dart`.
- مقارنة مع التطبيق القديم `ksaapp98` عبر `diff -rq` (179 فرقاً، معظمها صور) وقراءة `ksaapp98:docs/release-readiness.md`.
- مقاطع تقاطعية من الخلفية للتحقق من الطرف المقابل فقط: `APILocalizationMiddleware`، مقدّمة `routes/rest_api/v1/api.php`، `ConfigController::configuration`، `config/deeplinks.php`، `DeepLinkResolver`، `CustomerController::update_cm_firebase_token`.

**أنماط البحث المستخدمة (على `lib/**/*.dart` ما لم يُذكر غير ذلك):** `sharedPreferences|prefs\.|SharedPreferences`، `Hive\.|openBox`، `sqflite|openDatabase`، `database\.[a-zA-Z]+\(`، `subscribeToTopic|unsubscribeFromTopic|getToken\(\)|deleteToken|onTokenRefresh`، `headers\b`، `AppConstants.baseUrl`، `/api/v[0-9]`، `guest_id`، `updateHeader\(`، `PriceConverter\.`، `exchangeRate`، `current_currency_code`، `countryDialCode|\.countryCode`، `region_id|city_id|district|national_address|short_address|building_number|address_regions|address_cities|unified_address`، `X-Storefront|X-Store|X-Market|X-Country|X-Currency|Accept-Language|store_id|country_id|zone_id`، `secure_storage|package_info|device_info|remote_config` (في `pubspec.yaml`)، `dart-define|fromEnvironment|flavor|productFlavors|buildConfigField` (في `lib/` وملفات Gradle)، `geoip|ipinfo|ip-api|countryFromIp|Platform\.localeName`، `clearGuestId\(\)`، وسلاسل العلامات القديمة عبر `git grep -F` على كامل الملفات المتتبَّعة. عدد الملفات المقروءة كلياً أو جزئياً نحو 95 ملفاً، والبحث النصّي شمل ملفات Dart الـ 709 كلها.

**ما لم يُفحص:** لم يُشغَّل أي بناء أو اختبار (الاختبارات NOT RUN). لم تُفحص منطقياً شاشات التجارة (السلة، الدفع، المنتج، الطلبات، المحفظة) إلا بقدر ما يمسّ انتقال السياق والتخزين المحلي والعملة؛ ولم يُفحص محرّك الثيم الديناميكي ولا الـ renderers. لم تُقرأ الأصول الثنائية ولا ملفات اللغات `bn/es/hi`. لم تُقرأ قيم الإنتاج (`business_settings`، `.env`، لوحات Play Console و App Store Connect و Firebase). المستودع الخلفي القديم `pharmacyksa98` غير متاح: `UNKNOWN (missing access: pharmacyksa98)`.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 البنية: إدارة الحالة، الطبقات، الحقن، التنقّل

- **إدارة الحالة:** `provider` مع متحكّمات `ChangeNotifier`. تُسجَّل 40 `ChangeNotifierProvider` وواحد `Provider<SectionRendererRegistry>` في `MultiProvider` عند الإقلاع — `lavkooapp:lib/main.dart:155-217`. الاعتماديتان معلنتان في `lavkooapp:pubspec.yaml:16-17`.
- **حقن الاعتماديات:** `get_it` عبر `final sl = GetIt.instance` — `lavkooapp:lib/di_container.dart:196`. يحتوي الملف 153 استدعاء `registerLazySingleton` و38 `registerFactory`؛ المتحكّمات تُسجَّل كـ factory (`lavkooapp:lib/di_container.dart:267-318`) باستثناء `DynamicThemeController` المسجَّل singleton (`lavkooapp:lib/di_container.dart:291-292`).
- **الطبقات:** Controller ← ServiceInterface ← RepositoryInterface ← `DioClient`. الواجهة العامة للمستودعات `RepositoryInterface<T>` بخمس دوال (`lavkooapp:lib/interface/repo_interface.dart:1-12`)، وكثير من تطبيقاتها يرمي `UnimplementedError` (مثال: `lavkooapp:lib/features/splash/domain/repositories/splash_repository.dart:64-92`). توجد 46 ميزة تحت `lib/features` ونحو 35 واجهة مستودع (العدّ يتراوح بين 35 و37 بحسب نمط البحث عن `abstract class …RepositoryInterface`).
- **التنقّل:** `go_router` (`lavkooapp:pubspec.yaml:89`) عبر `RouterHelper.goRoutes` — `lavkooapp:lib/helper/route_healper.dart:1081-1103`، بـ 76 مساراً فعّالاً من نوع `FadeGoRoute`، ومفتاح `navigatorKey` عام وكائن `Get.context` المشتق منه (`lavkooapp:lib/main.dart:361-364`) الذي تعتمد عليه المتحكّمات والمستودعات للوصول إلى الـ providers خارج شجرة الـ widgets (مثال: `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:101-102`).
- **تسلسل الإقلاع:** `AppHealthReporter.install()` ثم `Hive.initFlutter()` ثم `Firebase.initializeApp` ثم `FlutterDownloader.initialize` ثم `di.init()` ثم `MetaTrackingService().initialize` ثم `AppHealthReporter.reportLaunch` ثم تهيئة الإشعارات والرابط الابتدائي — `lavkooapp:lib/main.dart:104-153`. (خطوة سابقة في تسلسل الإقلاع موضوع بند مقيّد. SEC-E16-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.) قاعدة Drift تُنشأ كمتغيّر عام `final database = AppDatabase()` — `lavkooapp:lib/main.dart:82`.

### 2.2 عنوان الـ API وبناء `DioClient` والترويسات

- **العنوان الأساسي** ثابت وقت الترجمة: `static const String baseUrl = 'https://lavkoo.com'` — `lavkooapp:lib/utill/app_constants.dart:39`. لا يوجد flavor ولا `--dart-define` ولا ملف بيئة (بحث: `dart-define|fromEnvironment|flavor` في `lib/` و`android/app/build.gradle.kts`: لا نتائج). كل مسارات الـ API ثوابت نصّية تبدأ بـ `/api/v1` (164 موضعاً في `app_constants.dart`، ولا يوجد أي `/api/v2` أو `/api/v3`). يوجد موضع واحد يكتب الـ host حرفياً خارج الثابت: رابط تسجيل البائع على الويب — `lavkooapp:lib/features/more/screens/become_merchant_screen.dart:97` (بحث `lavkoo\.com|pharmacyksa\.com` في `lib/`: موضعان فقط، الثابت وهذا السطر).
- **إنشاء العميل:** singleton واحد يُبنى مرة واحدة بالعنوان الثابت: `sl.registerLazySingleton(() => DioClient(AppConstants.baseUrl, sl(), ...))` — `lavkooapp:lib/di_container.dart:206-207`. المُنشئ يضبط `options.baseUrl` ومهلتَي اتصال واستقبال 60 ثانية والترويسات ويضيف `LoggingInterceptor` — `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:21-47`.
- **المعترض الوحيد** `LoggingInterceptor` لا يعدّل الطلب (`onRequest` يمرّره كما هو) — `lavkooapp:lib/data/datasource/remote/dio/logging_interceptor.dart:7-10`. لا يوجد معترض لتجديد التوكن؛ معالجة 401 تتم في `ApiErrorHandler.getMessage` باستدعاء `clearSharedData()` — `lavkooapp:lib/data/datasource/remote/exception/api_error_handler.dart:70-80`.
- **الترويسات تُبنى في دالة واحدة** `_headers()` — `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73`، ويُعاد بناؤها فقط عبر `updateHeader` — `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:49-53`، وله موضعا استدعاء فقط: حفظ التوكن بعد الدخول (`lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:126-133`) وتغيير اللغة (`lavkooapp:lib/localization/controllers/localization_controller.dart:36`).

| الترويسة | القيمة ومصدرها | الدليل |
|---|---|---|
| `Content-Type` | `application/json; charset=UTF-8` | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:66` |
| `Authorization` | `Bearer $token`؛ التوكن من مفتاح `user_login_token`؛ عند الضيف تصبح القيمة الحرفية `Bearer null` | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:67` و`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:27` |
| `Accept` | `application/json` | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:68` |
| `X-Platform` | `ios` أو `android` | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:69` |
| `X-App-Version` | الثابت `AppConstants.appVersion` = `16.2.1` (ليس إصدار `pubspec`) | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:70`، `lavkooapp:lib/utill/app_constants.dart:29` |
| `X-Client-Id` | معرّف تثبيت عشوائي من 32 خانة ست عشرية يُحفظ في `analytics_client_id` | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:71`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:77-82` |
| `lang` | رمز اللغة (`ar` أو `en`) من مفتاح `language_code`، والافتراضي `ar` | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:72`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:89-92` |

ترويسات تُضاف لطلبات محدّدة فقط: `X-UI-Components` و`X-UI-Engine` و`X-UI-Schema` و`If-None-Match` لطلبات الثيم (`lavkooapp:lib/features/dynamic_theme/engine/capability_registry.dart:89-93`، `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:90-98`)، و`Idempotency-Key` لطلب إنشاء الطلب (`lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:66-69`).

**ما لا يُرسَل إطلاقاً:** لا ترويسة ولا معامل للدولة أو المتجر أو العملة. العضو `DioClient.countryCode` يُقرأ ويُخزَّن (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:28-29`) لكنه لا يدخل في `_headers()`؛ بل إن موضع الاستدعاء الوحيد الذي يمرّر له قيمة يمرّر رمز اللغة لا رمز دولة (`lavkooapp:lib/localization/controllers/localization_controller.dart:36`). معرّف الضيف لا يسافر كترويسة بل كمعامل `guest_id` في الاستعلام أو الجسم (81 موضعاً في 22 ملفاً)، ومنها 14 ثابتاً في `app_constants.dart` تحمل قيمة معرّف ضيف ثابتة مكتوبة حرفياً (مثال: `lavkooapp:lib/utill/app_constants.dart:48`).

**طلبات تمرّ خارج `DioClient` ولا تحمل ترويساته (مهم لأي سياق متجر يُضاف كترويسة):**

| الموضع | الآلية | الترويسات المرسلة | الدليل |
|---|---|---|---|
| تحديث الملف الشخصي | `http.MultipartRequest` | `Authorization` فقط | `lavkooapp:lib/features/profile/domain/repositories/profile_repository.dart:39-43` |
| طلب الاسترجاع | `http.MultipartRequest` | `Authorization` فقط | `lavkooapp:lib/features/refund/domain/repositories/refund_repository.dart:21-28` |
| إرسال/تعديل التقييم | `http.MultipartRequest` | `Authorization` فقط | `lavkooapp:lib/features/review/domain/repositories/review_repository.dart:32-42` |
| إرسال رسالة محادثة | `http.MultipartRequest` | `Authorization` فقط | `lavkooapp:lib/features/chat/domain/repositories/chat_repository.dart:67-77` |
| تذكرة دعم جديدة / رد | `http.MultipartRequest` (موضعان) | `Authorization` فقط | `lavkooapp:lib/features/support/domain/repositories/support_ticket_repository.dart:22-29`، `lavkooapp:lib/features/support/domain/repositories/support_ticket_repository.dart:73-81` |
| تنزيل منتج رقمي / معاينة | `HttpClient` مباشر | SEC-E16-18: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | `lavkooapp:lib/features/order_details/domain/repositories/order_details_repository.dart:145-153`، `lavkooapp:lib/features/product_details/domain/repositories/product_details_repository.dart:71-79` |
| صورة الإشعار | `http.get` | لا شيء | `lavkooapp:lib/push_notification/notification_helper.dart:400-408` |
| صفحات الدفع | WebView `loadRequest(Uri.parse(widget.url))` | ترويسات المتصفح فقط | `lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:120` |
| رابط تنزيل رقمي في المتصفح الخارجي | `launchUrl` برابط مبني من `baseUrl` | مذكور في البند المقيّد أعلاه (SEC-E16-18) | `lavkooapp:lib/features/order_details/controllers/order_details_controller.dart:270-271` |
| تنزيل ملفات عبر مدير التنزيل (مرفقات المحادثة، ملفات الطلب، معاينة المنتج الرقمي) | `FlutterDownloader.enqueue` (5 مواضع) | مذكور في البند المقيّد أعلاه (SEC-E16-18) | `lavkooapp:lib/features/chat/controllers/chat_controller.dart:625-632`، `lavkooapp:lib/features/chat/controllers/chat_controller.dart:662-668`، `lavkooapp:lib/features/order_details/controllers/order_details_controller.dart:179-185`، `lavkooapp:lib/features/order_details/controllers/order_details_controller.dart:376-383`، `lavkooapp:lib/features/product_details/controllers/product_details_controller.dart:321-328` |
| صفحة المدونة | WebView `loadRequest` | ترويسات المتصفح فقط | `lavkooapp:lib/features/blog/screens/blog_screen.dart:68` |

(أُضيف الصفّان الأخيران في التحقق المستقل: بحث `MultipartRequest|HttpClient\(\)|http\.(get|post)\(|loadRequest|InAppWebView|FlutterDownloader\.enqueue|launchUrl` على `lib/**/*.dart`. مواضع `MultipartRequest` ستة بالضبط، و`HttpClient()` موضعان، و`http.get` موضعان أحدهما لمصدر الأخبار الخارجي في ميزة الصحة.)

يُستخدم `AppConstants.baseUrl` في 20 موضعاً غير تعليقي (نتيجة `grep`): سطر الحقن نفسه و19 موضعاً يمرّ خارج `DioClient` أو يبني رابطاً مباشرة، منها بناء روابط التخزين (`lavkooapp:lib/push_notification/notification_helper.dart:259`) ومواضع معالجة عودة الدفع (SEC-E16-14: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور). ثلاثة من المواضع الـ 19 تقع في ملف يتيم لا يُنشأ صنفه من أي مكان (`lavkooapp:lib/features/wallet/screens/add_fund_to_wallet_screen.dart:166-170`؛ الراوتر يذكره في تعليق فقط — `lavkooapp:lib/helper/route_healper.dart:1822`)، وهذا الملف غير موجود في `ksaapp98`.

**طبقة تخزين مؤقت داخل `DioClient.get`:** ذاكرة ثم قرص (SharedPreferences) ثم دمج الطلبات المتزامنة — `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:174-260`. تنطبق على 15 بادئة مسار (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-140`) بمطابقة `uri.contains(endpoint)` (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:142-147`). مدة الصلاحية 300000 مللي ثانية افتراضياً وتُستبدل مرة لكل جلسة بنتيجة `GET /api/v1/cash` (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:103-122`) التي تجيب عنها الخلفية بالرقم 400000 (`lavkoo:routes/rest_api/v1/api.php:58-60`). مفتاح التخزين = المسار + المعاملات مرتبةً + `#` + اللغة فقط (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-172`).

تصحيح من التحقق المستقل: مدة الصلاحية تُطبَّق على طبقة القرص فقط (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:194-215`). طبقة الذاكرة خريطة `static` تُعاد منها النتيجة دون أي فحص زمني (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:188-192`)، فالرد المخزَّن في الذاكرة يبقى طوال عمر العملية ولا يزول إلا بتغيير اللغة أو بسحب التحديث في الصفحة الرئيسية (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:361-364`، `lavkooapp:lib/features/home/screens/home_screens.dart:239-242`؛ بحث `clearMemoryCache`: موضعا استدعاء فقط). ولأن `/api/v1/theme/version` ضمن القائمة و`fetchRevision` يمرّ عبر `dioClient.get` (`lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:64-70`) ثم يقصر `_revalidate` عند عدم تغيّر الرقم (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:205-231`)، فالاستنتاج (INFERRED، لم يُشغَّل) أن فحص نسخة الثيم يُجاب من الذاكرة داخل الجلسة الواحدة. الوثيقة القديمة تسجّل الملاحظة نفسها (`ksaapp98:docs/app-backend-compatibility.md:2357-2362`). يُحال التأكيد لنطاق الثيم.

### 2.3 التوكن ووضع الضيف

- **تخزين التوكن:** في SharedPreferences بالمفتاح `user_login_token` — `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:126-133` (جوانب الحماية عند التخزين موضوع البند المقيّد SEC-E16-03 المشار إليه في 2.6). `isLoggedIn()` = وجود المفتاح فقط (`lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:181-184`).
- **الخروج:** `clearSharedData()` يحذف `user_login_token` و`guestId` فقط — `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:186-191`. تسلسل الخروج في الواجهة يعيد ضبط متحكّمات الذاكرة ثم يطلب معرّف ضيف جديداً — `lavkooapp:lib/features/more/widgets/logout_confirm_bottom_sheet_widget.dart:90-127`. يجب أن يراعي أي تبديل سياق مستقبلي سلوك تحديث ترويسات `DioClient`. SEC-E16-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- **وضع الضيف:** عند غياب التوكن يطلب التطبيق `GET /api/v1/get-guest-id` ويحفظ القيمة في المفتاحين `guestId` و`guest_cart_id` — `lavkooapp:lib/features/auth/controllers/auth_controller.dart:1224-1236`، `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:156-168`، `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:458-470`. القيمة الافتراضية عند غياب المفتاح قيمة ثابتة، وسلوك إعادة ضبط معرّف الضيف يختلف بين مسارات الدخول. SEC-E16-17: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- **تسجيل اللغة لدى الخلفية:** طلب `POST` إلى `/api/v1/customer/language-change` يحمل `_method: put` داخل جسم JSON مع الحقل `current_language` وقيمته `sy` للعربية و`en` لغيرها — `lavkooapp:lib/localization/controllers/localization_controller.dart:38-39`، `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:135-149`. المسار في الخلفية معرَّف بـ `Route::put` فقط (`lavkoo:routes/rest_api/v1/api.php:277`). هل يصل طلب POST بـ `_method` داخل جسم JSON إلى مسار PUT فعلاً: `UNKNOWN` بالقراءة الثابتة؛ الوثيقة القديمة تناقض نفسها في هذه النقطة (`ksaapp98:docs/app-backend-compatibility.md:217` تقول إن Laravel يحترمه، و`ksaapp98:docs/app-backend-compatibility.md:2353-2354` تقول إن كل نداء يعود 405). يحسمه اختبار تشغيلي على الخلفية (ممنوع في هذه المرحلة). النمط نفسه مستعمل في تسجيل توكن الجهاز (2.10) وفي حذف العنوان وعناصر السلة والمقارنة (بحث `'_method'|"_method"` في `lib/`: 11 موضع إرسال في 6 ملفات مستودع، منها ثلاثة مواضع في طلبات multipart لا تنطبق عليها المسألة). الخلفية توثّق أن رمز العربية في هذا المتجر هو `sy` وتطابق الرموز عائلياً — `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:33-43`.

### 2.4 نقطة `config`: الحقول التي يستهلكها التطبيق

`GET /api/v1/config` (`lavkooapp:lib/utill/app_constants.dart:97`، `lavkooapp:lib/features/splash/domain/repositories/splash_repository.dart:14-21`) تُحلَّل في `ConfigModel.fromJson` — `lavkooapp:lib/features/splash/domain/models/config_model.dart:183-429`. الخلفية تبني القيم من إعدادات عامة واحدة (`getWebConfig`) دون أي بُعد متجر — `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:203-219`.

| الموضوع | حقل JSON ← حقل Dart | ملاحظات التحليل | الدليل |
|---|---|---|---|
| العملة الافتراضية | `system_default_currency` ← `systemDefaultCurrency` (int) | `int.parse` مع بديل | `lavkooapp:lib/features/splash/domain/models/config_model.dart:186-192` |
| قائمة العملات | `currency_list` ← `List<CurrencyList>` (`id`,`name`,`symbol`,`code`,`status`,`exchangeRate`) | رمز `SYP` يُستبدل رمزه محلياً | `lavkooapp:lib/features/splash/domain/models/config_model.dart:246-252`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:541-591` |
| موضع رمز العملة | `currency_symbol_position` ← `currencySymbolPosition` | `right` أو غيره | `lavkooapp:lib/features/splash/domain/models/config_model.dart:253` |
| نموذج العملة | `currency_model` ← `currencyModel` | يُقرأ ولا يُستعمل في التحويل (انظر 2.7) | `lavkooapp:lib/features/splash/domain/models/config_model.dart:280` |
| الخانات العشرية | `decimal_point_settings` ← `decimalPointSettings` (int واحد عام) | الافتراضي 1 عند الغياب | `lavkooapp:lib/features/splash/domain/models/config_model.dart:286-291` |
| رمز الدولة | `country_code` ← `countryCode` (String) | يُفكّ بـ `!` في شاشات الدخول | `lavkooapp:lib/features/splash/domain/models/config_model.dart:273`، `lavkooapp:lib/features/auth/screens/login_screen.dart:70-71` |
| اللغات | `language` ← `List<Language>` | تُحلَّل ولا تُستعمل لاختيار اللغة (القائمة مترجمة في التطبيق) | `lavkooapp:lib/features/splash/domain/models/config_model.dart:256-261` |
| طرق الدفع | `payment_methods`، `digital_payment`، `cash_on_delivery`، `offline_payment`، `payment_method_image_path` | تُحدَّث وحدها عند فتح الدفع | `lavkooapp:lib/features/splash/domain/models/config_model.dart:306-315`، `lavkooapp:lib/features/splash/controllers/splash_controller.dart:301-321` |
| الصيانة | `maintenance_mode` ← `MaintenanceMode` (`maintenance_status`، `selected_maintenance_system.user_app`، الرسائل، المدة) | | `lavkooapp:lib/features/splash/domain/models/config_model.dart:386-388`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:809-835`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:863-865` |
| بوابة الإصدار | `user_app_version_control` ← `UserAppVersionControl{forAndroid,forIos}`؛ `seller_app_version_control` ← `vendorAppVersionControl` | `status` عبر `int.parse` بلا حماية | `lavkooapp:lib/features/splash/domain/models/config_model.dart:373-378`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:787-798` |
| أعلام الميزات | `wallet_status`، `loyalty_point_status`، `guest_checkout`، `add_funds_to_wallet`، `ref_earning_status`، `order_verification`، `billing_input_by_customer`، `minimum_order_limit`، `refund_day_limit`، `business_mode`، `seller_registration`، `brand_setting`، `digital_product_setting` | إسنادات مباشرة صارمة النوع في أغلبها | `lavkooapp:lib/features/splash/domain/models/config_model.dart:293-357`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:184-196`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:254` |
| الدخول والتحقق | `customer_login`، `customer_verification`، `social_login`، `email_verification`، `phone_verification`، `firebase_verification`، `otp_resend_time` | | `lavkooapp:lib/features/splash/domain/models/config_model.dart:270-279`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:390-399` |
| التوصيل والخرائط | `delivery_country_restriction`، `delivery_zip_code_area_restriction`، `map_api_status`، `default_location{lat,lng}`، `shipping_method`، `inhouse_selected_shipping_type` | | `lavkooapp:lib/features/splash/domain/models/config_model.dart:207-218`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:269`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:365-371` |
| الهوية البصرية | `company_name`، `company_logo`، `primary_color`، `secondary_color`، `active_theme`، `announcement` | اللونان يُطبَّقان على الثيم فور الوصول؛ الحقلان `companyLogo` و`companyCoverImage` يُسندان من المفتاحين بالعكس | `lavkooapp:lib/features/splash/domain/models/config_model.dart:199-206`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:384-385`، `lavkooapp:lib/features/splash/controllers/splash_controller.dart:152-157` |
| السياسات | `about_us`، `privacy_policy`، `terms_&_conditions`، `refund_policy`، `return_policy`، `cancellation_policy`، `shipping_policy`، `faq` | مجموعة واحدة عامة | `lavkooapp:lib/features/splash/domain/models/config_model.dart:225-245` |
| التتبّع | `meta_app_tracking` | الخدمة معطّلة بثابت في الكود (2.7) | `lavkooapp:lib/features/splash/domain/models/config_model.dart:426-428` |

**هشاشة العقد:** أي استثناء داخل `ConfigModel.fromJson` لا يُلتقط في `initConfig` (`lavkooapp:lib/features/splash/controllers/splash_controller.dart:120-128`) ولا في سلسلة `.then` بشاشة البداية (`lavkooapp:lib/features/splash/screens/splash_screen.dart:310-312`). الإسنادات الصارمة تشمل `unit = json['unit'].cast<String>()` (`lavkooapp:lib/features/splash/domain/models/config_model.dart:268`) و`status = int.parse(json['status'].toString())` (`lavkooapp:lib/features/splash/domain/models/config_model.dart:795`) وحقولاً منطقية ونصّية تُسند دون تحويل نوع (`lavkooapp:lib/features/splash/domain/models/config_model.dart:193-196`)، ومثلها حقول عددية (`lavkooapp:lib/features/splash/domain/models/config_model.dart:294-297`). كما أن الخلفية تعيد مصفوفة فارغة `[]` لحقول الصيانة غير المضبوطة (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:106-110`) بينما يتوقع التطبيق كائناً (`lavkooapp:lib/features/splash/domain/models/config_model.dart:821-826`). الاستنتاج (INFERRED من غياب أي `try/catch` حول التحليل، ومصحَّح في التحقق المستقل): أثر فشل تحليل رد الشبكة يختلف بحسب وجود نسخة محلية. (أ) تثبيت جديد أو بيانات ممسوحة: لا نسخة محلية، فيبقى `configModel` فارغاً ولا يُستدعى أي توجيه، أي أن التطبيق يعلق عند شاشة البداية. (ب) نسخة مثبَّتة لديها `config` محفوظ: أثرها موضّح في SEC-E16-09: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

**ترتيب القراءة:** النسخة المحلية من `config` تُقرأ من Drift أولاً وتُطبَّق (العملة، الصيانة، التوجيه) قبل وصول رد الشبكة — `lavkooapp:lib/features/splash/controllers/splash_controller.dart:83-117`، ثم تُكتب النسخة الجديدة تحت المفتاح نفسه `/api/v1/config` بلا أي لاحقة — `lavkooapp:lib/features/splash/controllers/splash_controller.dart:193-209`.

### 2.5 بوابة الإصدار ووضع الصيانة

**بوابة الإصدار (Force Update):**

- المنطق: `_updateIsRequired` يختار `forIos` أو `forAndroid`؛ يقارن `compareVersions(minimum, AppConstants.appVersion)` ضمن شروط تفعيل مفصّلة في SEC-E16-10: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. — `lavkooapp:lib/features/splash/screens/splash_screen.dart:325-338`.
- الحقول: `status` (int)، `version` (String)، `link` (String) لكل منصّة — `lavkooapp:lib/features/splash/domain/models/config_model.dart:787-798`. الخلفية تعيدها من إعداد عام واحد `user_app_version_control` — `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:236`.
- رقم الإصدار المقارَن ثابت يدوي `16.2.1` لا علاقة له بإصدار البناء `3.0.6+48` (`lavkooapp:lib/utill/app_constants.dart:29`، `lavkooapp:pubspec.yaml:5`)؛ ولا توجد حزمة `package_info`. تعليق الكود نفسه يوثّق أن المتجر الحي ينشر حدّاً أدنى `14.1` بحالة 1 ورابط فارغ، وأن تصحيح الثابت وحده يوقف كل النسخ عند شاشة التحديث — `lavkooapp:lib/utill/app_constants.dart:9-28`. الوثيقة القديمة تذكر القراءة نفسها — `ksaapp98:docs/release-readiness.md:16-30`. قيمة الإنتاج الحالية: `UNKNOWN` (تحتاج قراءة `business_settings` أو رد `config` الحي).
- شاشة التحديث: الرابط من `config`؛ إن كان `null` يفشل `appUrl!`، وإن كان فارغاً تظهر رسالة «لا يمكن الفتح» ولا مخرج من الشاشة — `lavkooapp:lib/features/update/screen/update_screen.dart:50-76`.
- مواضع التفعيل: انظر البند المقيّد أعلاه.

**وضع الصيانة:**

- الشرط: `maintenance_status == 1` و`selected_maintenance_system.user_app == 1` — `lavkooapp:lib/features/splash/screens/splash_screen.dart:124-130` و`lavkooapp:lib/helper/route_healper.dart:1062-1074`.
- المسارات المؤدية لشاشة الصيانة: شاشة البداية (المحلي ثم الشبكة)؛ رسالة FCM من النوع `maintenance_mode` تعيد تحميل `config` ثم توجّه — `lavkooapp:lib/push_notification/notification_helper.dart:116-138`؛ مؤقّت كل 30 ثانية لصيانة مجدولة خلال 60 دقيقة — `lavkooapp:lib/features/splash/controllers/splash_controller.dart:172-191` و`lavkooapp:lib/features/splash/controllers/splash_controller.dart:374-383`.
- الشاشة تحجب التطبيق كاملاً (`PopScope(canPop: false)`) وتعيد الفحص عند العودة للواجهة — `lavkooapp:lib/features/maintenance/maintenance_screen.dart:35-48` و`lavkooapp:lib/features/maintenance/maintenance_screen.dart:58-64`. لا يوجد وضع «تصفّح فقط» ولا إعادة توجيه لمتجر آخر.

**تغطية الفحوص عند الفتح برابط عميق:** مفصّلة في SEC-E16-11: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 2.6 التخزين المحلي: كل مفتاح وبُعد عزله

**SharedPreferences:**

| المفتاح | المحتوى | الكاتب / القارئ | البُعد الفعلي | الدليل |
|---|---|---|---|---|
| `user_login_token` | توكن الدخول | `AuthRepository`، `DioClient` | عام (مستخدم واحد في كل مرة)؛ لا بُعد متجر | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:129` |
| `guestId` | معرّف الضيف | `AuthRepository` | عام | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:159` |
| `guest_cart_id` | معرّف سلة الضيف (للدمج) | `AuthRepository` | عام | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:461` |
| `user_log_data` | بيانات «تذكّرني» بصيغة JSON | `AuthRepository` | عام — SEC-E16-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:364-370` |
| `user_email`، `user_password` | تُحذف وتُقرأ فقط ولا تُكتب (إرث) | `AuthRepository` | — | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:377-386` |
| `apple_login_email` | بريد Apple عند أول دخول | `AuthRepository` | عام | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:444-456` |
| `currency` | رمز العملة «المختارة» (يُعاد كتابته آلياً، انظر 2.7) | `SplashRepository` | عام | `lavkooapp:lib/features/splash/domain/repositories/splash_repository.dart:39-52` |
| `language_code`، `country_code` | لغة الواجهة ودولة الـ Locale (`SA` للعربية، `US` للإنجليزية) | `LocalizationController` | عام؛ `country_code` ليس دولة متجر | `lavkooapp:lib/localization/controllers/localization_controller.dart:78-82`، `lavkooapp:lib/utill/app_constants.dart:338-349` |
| `intro` | إظهار التعريف الأول | `SplashRepository` | عام | `lavkooapp:lib/features/splash/domain/repositories/splash_repository.dart:35-38` |
| `theme` | الوضع الداكن | `ThemeController` | عام | `lavkooapp:lib/theme/controllers/theme_controller.dart:14-29` |
| `search_product` | سجل البحث (قائمة نصوص) | `SearchProductRepository` | عام؛ لا مستخدم ولا لغة ولا متجر | `lavkooapp:lib/features/search_product/domain/repositories/search_product_repository.dart:61-86` |
| `analytics_client_id` | معرّف التثبيت | `DioClient` | لكل تثبيت | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:35` |
| `pending_crash_count` | عدّاد الأعطال المعلّقة | `AppHealthReporter` | لكل تثبيت | `lavkooapp:lib/data/services/app_health_reporter.dart:65-72` |
| `isNewPricingMode` | علم تجريبي للتسعير (معطّل في المحوّل) | `SplashController` | عام | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:32-43` |
| `api_cache_<المسار؟المعاملات#اللغة>` ومعه `..._time` | ردود GET المخزّنة | `DioClient` | اللغة فقط | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:195-197`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:235-236` |
| `health_records` | سجلات صحية يدخلها المستخدم | شاشة السجلات الصحية | لكل جهاز | `lavkooapp:lib/features/health/misc/health_records_screen.dart:30-51` |

ثوابت مفاتيح معلنة بلا أي استعمال (0 نتيجة لكل منها خارج ملف الثوابت): `user`، `home_address`، `office_address`، `config`، `guest_mode`، `user_address`، `userId` — `lavkooapp:lib/utill/app_constants.dart:292-312` و`lavkooapp:lib/utill/app_constants.dart:336` و`lavkooapp:lib/utill/app_constants.dart:43` (أعاد التحقق المستقل البحث عن `AppConstants.<الاسم>` لكل ثابت: صفر استعمال).

**قاعدة Drift** `cache_response_new_db`، جدول واحد `cache_response` بالأعمدة `id`، `end_point` (فريد)، `header`، `response`، `is_success` — `lavkooapp:lib/data/local/cache_response.dart:5-23`. لا عمود للمستخدم أو اللغة أو المتجر؛ البُعد كله مضمَّن في نص المفتاح:

| نمط المفتاح (`end_point`) | المحتوى | البُعد | الدليل |
|---|---|---|---|
| `/api/v1/config` | إعدادات المتجر كاملة | لا شيء | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:193-209` |
| `<uri>#<lang>` عبر `DataSyncRepo` | التصنيفات، العلامات، البانرات، قوائم المنتجات، المتاجر، العروض المميزة | اللغة فقط | `lavkooapp:lib/data/reposotories/data_sync_repo.dart:36-63` |
| `/api/v1/cart?guest_id=<قيمة>#<lang>` | السلة؛ القيمة تصبح قيمة ثابتة بعد الدخول بكلمة المرور (2.3) | اللغة + معرّف الضيف؛ لا مستخدم | `lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:21-27` |
| `/api/v1/products/shop-again-product#<lang>` | مشتريات سابقة للمستخدم | اللغة فقط | `lavkooapp:lib/features/product/domain/repositories/seller_product_repository.dart:72-76` |
| `/api/v1/customer/order/list?limit=10&offset=` | قائمة طلبات «إعادة الطلب» | لا شيء | `lavkooapp:lib/features/order/controllers/order_controller.dart:23-33`، `lavkooapp:lib/features/order/controllers/order_controller.dart:51-67` |
| `/api/v1/theme/home::<page>` | صفحة الثيم المنشورة | الصفحة فقط | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:31` |
| `/api/v1/flash-deals` و`/api/v1/flash-deals/products/` | العروض الخاطفة | لا شيء | `lavkooapp:lib/features/deal/controllers/flash_deal_controller.dart:30-33` |
| `/api/v1/seller/<slug>/products?...` | منتجات تصفية متجر بائع | المعاملات فقط | `lavkooapp:lib/features/shop/controllers/shop_controller.dart:253-254` |

عمود `header` يُكتب مع كل صف من صفوف `DataSyncRepo` — `lavkooapp:lib/data/reposotories/data_sync_repo.dart:46-51`؛ ومحتواه موضوع بند مقيّد. SEC-E16-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

**متى تُمسح المخازن:** المسح الشامل الوحيد يقع عند تغيير اللغة (Drift كاملاً + ذاكرة Dio + قرص Dio) — `lavkooapp:lib/localization/controllers/localization_controller.dart:41-44`. (بحث `clearCacheResponses|deleteCacheResponse`: المواضع الثلاثة خارج تعريف الجدول هي تغيير اللغة وحذف صفحات الثيم فقط). سحب التحديث في الصفحة الرئيسية يمسح ذاكرة Dio وحدها دون القرص ودون Drift — `lavkooapp:lib/features/home/screens/home_screens.dart:239-242`.

**مخازن ميزة «الصحة» (محلية بحتة):** صناديق Hive: `medications`، `water`، `bmi`، `health_challenges`، `health_readings`، `sleep_tracker`، `period_tracker` (مثال: `lavkooapp:lib/features/health/reminders/medication_reminder_screen.dart:93`، `lavkooapp:lib/features/health/trackers/period_tracker_screen.dart:31`)؛ وقاعدة sqflite باسم `health.db` بجدولين — `lavkooapp:lib/features/health/data/health_database_helper.dart:10-57`. لا بُعد مستخدم لها. SEC-E16-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

**ملفات:** `order.pdf` في مجلد المستندات (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:270-271`) وصورتا الإشعار `largeIcon` و`bigPicture` (`lavkooapp:lib/push_notification/notification_helper.dart:365-367`).

### 2.7 اللغة والاتجاه RTL والعملة وتنسيق الأسعار

**اللغة:** القائمة مترجمة في الكود بعنصرين فقط: العربية (`ar`/`SA`) ثم الإنجليزية (`en`/`US`) — `lavkooapp:lib/utill/app_constants.dart:338-349`؛ والافتراضي أول عنصر أي العربية دون نظر إلى لغة الجهاز — `lavkooapp:lib/localization/controllers/localization_controller.dart:19-20` و`lavkooapp:lib/localization/controllers/localization_controller.dart:62-76`. ملفات الترجمة المرفقة خمسة (`ar`،`bn`،`en`،`es`،`hi`) لكن اثنان فقط قابلان للوصول. الاتجاه يُشتق من اللغة: `_isLtr = languageCode != 'ar'` — `lavkooapp:lib/localization/controllers/localization_controller.dart:30`. الاختيار من شاشة الإعدادات فقط (`lavkooapp:lib/features/setting/screens/settings_screen.dart:42-51`)؛ شاشة التعريف الأولى ثلاث صفحات ثابتة بلا اختيار لغة أو دولة أو عملة — `lavkooapp:lib/features/onboarding/domain/repositories/onboarding_repository.dart:17-32`. تغيير اللغة: حفظ المفتاحين، إعادة بناء الترويسات، إبلاغ الخلفية، مسح المخازن الثلاثة، إعادة جلب الثيم — `lavkooapp:lib/localization/controllers/localization_controller.dart:28-60`. المفتاح المفقود في الترجمة يُعرض باسمه الخام — `lavkooapp:lib/localization/language_constrants.dart:5-13`.

**العملة — الاختيار:** `getCurrencyData` يعطي الأولوية الأولى لأي عملة رمزها `SYP` أو للعملة الافتراضية للنظام ثم يتوقف (`break`) قبل النظر في اختيار المستخدم المحفوظ — `lavkooapp:lib/features/splash/controllers/splash_controller.dart:236-246`؛ ثم يعيد كتابة المفتاح `currency` بالعملة المحسومة «دائماً» — `lavkooapp:lib/features/splash/controllers/splash_controller.dart:278-281`. النتيجة (VERIFIED بقراءة التدفق): ورقة اختيار العملة في الإعدادات (`lavkooapp:lib/features/setting/widgets/select_currency_bottom_sheet_widget.dart:189-195`) تستدعي `setCurrency` الذي يستدعي `getCurrencyData` (`lavkooapp:lib/features/splash/controllers/splash_controller.dart:346-351`) فيعود الاختيار فوراً إلى العملة الافتراضية (أو `SYP` إن وُجدت في القائمة حتى لو لم تكن الافتراضية). وجود `SYP` في قائمة الإنتاج: `UNKNOWN`.

**العملة — التحويل والتنسيق:** الدالة المركزية `PriceConverter.convertPrice` — `lavkooapp:lib/helper/price_converter.dart:41-86`:

- تضرب السعر الوارد من الـ API في `myCurrency.exchangeRate × (1 / usdCurrency.exchangeRate)` لأن `singleCurrency` مثبّت على `false` بتعليق — `lavkooapp:lib/helper/price_converter.dart:65-73`. `usdCurrency` هي العملة ذات الرمز `USD` إن وُجدت، وإلا العملة الحالية — `lavkooapp:lib/features/splash/controllers/splash_controller.dart:61` و`lavkooapp:lib/features/splash/controllers/splash_controller.dart:108-110`. توضيح من التحقق المستقل: الضرب نفسه VERIFIED في الكود، لكن قيمة المعامل الفعلية تتبع البيانات: إن لم توجد عملة `USD` في القائمة صار المعامل 1، وإن وُجدت صار نسبة سعرَي الصرف؛ فالأثر الرقمي في الإنتاج `UNKNOWN` (يحتاج جدول `currencies`). الثابت في الكود هو التباين مع الويب: محوّل الويب في الخلفية يستعمل معاملاً قدره 1 ما لم يكن `currency_model` متعدد العملات (`lavkoo:app/Utils/currency.php:113-131`)، بينما التطبيق يتجاهل `currency_model` ويطبّق النسبة دائماً؛ والوثيقة القديمة تسجّل الشبهة نفسها على أنها غير مثبتة (`ksaapp98:docs/app-backend-compatibility.md:2371-2376`).
- تنسّق الناتج دائماً بـ `toStringAsFixed(0)` أي **صفر خانات عشرية** متجاهلةً `decimal_point_settings` — `lavkooapp:lib/helper/price_converter.dart:75-78`؛ وكذلك المسار الاحتياطي عند غياب الإعدادات — `lavkooapp:lib/helper/price_converter.dart:60-64`.
- لها 114 موضع استدعاء في 42 ملفاً (منها 51 في السلة والدفع وتفاصيل الطلب والمحفظة)، مثل إجماليات الدفع — `lavkooapp:lib/features/checkout/screens/checkout_screen.dart:531-554`.

الدوال الأخرى: `getUnitCurrency` و`convertPriceWithoutSymbol` و`percentageCalculation` تستعمل `decimalPointSettings ?? 1` (`lavkooapp:lib/helper/price_converter.dart:118-138`، `lavkooapp:lib/helper/price_converter.dart:140-172`، `lavkooapp:lib/helper/price_converter.dart:108-116`)، و`longToShortPrice` تثبّت خانتين (`lavkooapp:lib/helper/price_converter.dart:174-189`). الإعداد العشري قيمة واحدة عامة لا لكل عملة (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:219`).

**العملة — ما يُرسل للخلفية:** فقط الحقل `current_currency_code` عند بدء الدفع الرقمي وشحن المحفظة وسداد المستحق — `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:46`، `lavkooapp:lib/features/wallet/domain/repositories/wallet_repository.dart:62-70`، `lavkooapp:lib/features/order_details/domain/repositories/order_details_repository.dart:187`. طلبات الكتالوج والسلة لا تحمل عملة.

**عملة التحليلات:** أحداث Meta تكتب `currency: 'SYP'` حرفياً في ثلاثة مواضع وتتخذها بديلاً في ثلاثة أخرى — `lavkooapp:lib/features/cart/controllers/cart_controller.dart:144`، `lavkooapp:lib/features/cart/screens/cart_screen.dart:655`، `lavkooapp:lib/features/product_details/controllers/product_details_controller.dart:75`، `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:303`، `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:766`، `lavkooapp:lib/features/checkout/screens/checkout_screen.dart:712`. الخدمة كلها خامدة حالياً بالثابت `static const bool enabled = false` — `lavkooapp:lib/services/meta_tracking_service.dart:15`.

### 2.8 الدولة والهاتف والموقع والعناوين

- **رمز الاتصال الافتراضي:** من `config.country_code` العام عبر `CountryCode.fromCountryCode(...)` في شاشات الدخول والتسجيل وتتبّع الضيف والعنوان — `lavkooapp:lib/features/auth/screens/login_screen.dart:70-71`، `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:90-101`. قيم القالب الأصلي ما زالت بدائل: `'+880'` في المتحكّم (`lavkooapp:lib/features/auth/controllers/auth_controller.dart:74`)، و`'BD'` (`lavkooapp:lib/features/auth/screens/mobile_verify_screen.dart:32-37`)، و`'Bangladesh'` و`country = 'IN'` (`lavkooapp:lib/features/address/screens/add_new_address_screen.dart:68` و`lavkooapp:lib/features/address/screens/add_new_address_screen.dart:97-101`). لا يوجد `+966` ولا `SAR` ولا قائمة مدن سعودية مكتوبة في الكود (بحث `+966`، `SAR`، `ريال`، `saudi|riyadh` في `lib/`: لا نتائج)؛ السعودية تأتي من البيانات لا من الكود.
- **اشتقاق رمز الدولة من رقم محفوظ:** مطابقة رمز الدولة من الرقم المحفوظ والتحقق من الجوال مفصّلان في SEC-E16-15: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- **نموذج العنوان:** حقول نصّية حرّة `city`، `zip`، `state`، `country` مع `latitude`/`longitude` — `lavkooapp:lib/features/address/domain/models/address_model.dart:1-17`. لا يوجد أي من حقول نظام العناوين الموحّد في الخلفية (بحث `region_id|city_id|district|national_address|short_address|building_number|address_regions|address_cities|unified_address` في `lib/`: صفر نتائج)، مع أن الخلفية تعرض `GET /api/v1/address/regions` و`/cities` — `lavkoo:routes/rest_api/v1/api.php:70-73`. الدولة تُختار من منتقي الدول أو من قائمة الدول المقيّدة عند تفعيل `delivery_country_restriction` — `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:195-202`، `lavkooapp:lib/features/address/domain/repositories/address_repository.dart:19-27`.
- **الخرائط والموقع:** الموضع الابتدائي (0,0) ثم موقع الجهاز — `lavkooapp:lib/features/location/controllers/location_controller.dart:16-27` و`lavkooapp:lib/features/location/controllers/location_controller.dart:72-93`؛ البديل عند غياب إحداثيات العنوان هو `default_location` العام من `config` — `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:77-82`. الترميز الجغرافي والبحث عن الأماكن يمرّان عبر الخلفية (`/api/v1/mapapi/*`) — `lavkooapp:lib/features/location/domain/repositories/location_repository.dart:16-46`. ظهور الخريطة مشروط بـ `map_api_status == 1` — `lavkooapp:lib/features/location/screens/select_location_screen.dart:56-60`. مفتاح خرائط Google في المنصّتين قيمة نائبة `YOUR_MAP_KEY_HERE` — `lavkooapp:android/app/src/main/AndroidManifest.xml:74-77`، `lavkooapp:ios/Runner/AppDelegate.swift:16`. لا يوجد أي استنتاج للدولة من IP أو من الموقع (بحث `geoip|ipinfo|ip-api|countryFromIp`: لا نتائج).

### 2.9 الروابط العميقة

| الطبقة | الوضع الحالي | الدليل |
|---|---|---|
| Android intent-filter | `autoVerify`، المخططات المعلنة (SEC-E16-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.)، host واحد `lavkoo.com`، المسارات: `/` بالضبط، وبادئات `/track-order`، `/referral-login`، `/product/`، `/vendor-shop/`، `/brand/`، `/products`، `/go/` | `lavkooapp:android/app/src/main/AndroidManifest.xml:122-142` |
| Android deep linking | `flutter_deeplinking_enabled = true` | `lavkooapp:android/app/src/main/AndroidManifest.xml:117-119` |
| iOS associated domains | `applinks:pharmacyksa.com` (يختلف عن host أندرويد) | `lavkooapp:ios/Runner/Runner.entitlements:17-20` |
| iOS URL schemes | معرّف Google المعكوس، مخطط Facebook بقيمة نائبة، و`ksacosmetics` | `lavkooapp:ios/Runner/Info.plist:27-39` |
| iOS deep linking | `FlutterDeepLinkingEnabled = true` | `lavkooapp:ios/Runner/Info.plist:48-49` |
| مسارات GoRouter للروابط | `/`، `/track-order`، `/referral-login`، `/product/:slug`، `/vendor-shop/:slug`، `/open-link`، `/brand/:slug`، `/products`، `/track-order/:orderId`، `/go/:code` | `lavkooapp:lib/helper/route_healper.dart:105-121`، `lavkooapp:lib/helper/route_healper.dart:1105-1169` |
| المسار غير المعروف | `errorBuilder` يعرض الشاشة الرئيسية | `lavkooapp:lib/helper/route_healper.dart:1101-1103` |
| البوابة | `DeepLinkGatewayScreen` تسأل الخلفية ثم تستبدل نفسها بالشاشة المطلوبة؛ الأهداف: `home`، `product`، `brand`، `product_list`، `order_tracking`، وغير ذلك يُفتح في المتصفح | `lavkooapp:lib/features/deep_link/screens/deep_link_gateway_screen.dart:32-75` |
| المحلِّل | `GET /api/v1/deep-link/resolve?url=` بمهلة 6 ثوانٍ؛ وعند الفشل يُستعمل مسار احتياطي محلي (SEC-E16-13: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.) | `lavkooapp:lib/data/services/deep_link_resolver.dart:59-83` |
| روابط داخلية | رابط البانر غير المصنَّف يمرّ عبر `/open-link` | `lavkooapp:lib/features/banner/controllers/banner_controller.dart:160-166`، `lavkooapp:lib/helper/route_healper.dart:766-772` |
| الإقلاع برابط | يُقرأ `getInitialLink().path` فقط لتأخير بناء الراوتر حتى تحميل `config` | `lavkooapp:lib/main.dart:227-231`، `lavkooapp:lib/main.dart:307-322` |
| الطرف الخلفي | الأنماط المنشورة: `/`، `/product/*`، `/brand/*`، `/products`، `/go/*`؛ ومطابقة host واحد هو `config('app.url')` | `lavkoo:config/deeplinks.php:39-45`، `lavkoo:app/Services/DeepLink/DeepLinkResolver.php:158-186`، `lavkoo:app/Services/DeepLink/DeepLinkResolver.php:195-220` |
| الاختبارات | 7 اختبارات وحدة لـ `ResolvedDeepLink.fromJson` و`readLocally` فقط؛ لا اختبار لمطابقة الراوتر ولا لاتساق manifest/entitlements | `lavkooapp:test/deep_link_resolver_test.dart:10-90` |

ملاحظتان: (أ) `/product/:slug` موضّح في SEC-E16-12: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. المسارات `/brand`، `/products`، `/track-order/:id`، `/go` تمرّ بالبوابة. (ب) manifest يطالب بـ `/track-order` و`/referral-login` و`/vendor-shop/` وهي غير موجودة في قائمة `android_paths` لدى الخلفية (`lavkoo:config/deeplinks.php:60-65`). ملفات الربط المنشورة لدى الخلفية: SEC-E16-07: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 2.10 الإشعارات Push

| الموضوع (Topic) | متى يُشترك | الدليل |
|---|---|---|
| `maintenance_mode_start_user_app` | عند كل تحميل ناجح لـ `config` (ضيف أو مسجَّل) | `lavkooapp:lib/utill/app_constants.dart:324`، `lavkooapp:lib/features/splash/controllers/splash_controller.dart:142-150` |
| `theme_updates_user_app` | كذلك | `lavkooapp:lib/utill/app_constants.dart:329`، `lavkooapp:lib/features/splash/controllers/splash_controller.dart:146-147` |
| `sixvalley` (الثابتان `topic` و`demoTopic` بالقيمة نفسها) | عند كل تسجيل لتوكن الجهاز | `lavkooapp:lib/utill/app_constants.dart:334-335`، `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:95-96` |
| موضوعات إعادة التوفّر الديناميكية (اسمها يأتي من الخلفية) | عند طلب إشعار التوفّر وعند تحميل القائمة | `lavkooapp:lib/features/cart/controllers/cart_controller.dart:182-183`، `lavkooapp:lib/features/restock/controllers/restock_controller.dart:68-72` |

- لا يوجد أي `unsubscribeFromTopic` ولا `deleteToken` ولا مستمع `onTokenRefresh` في `lib/` (بحث بالأنماط الثلاثة: صفر نتائج)؛ الاشتراكات تبقى بعد الخروج.
- **تسجيل التوكن:** `POST /api/v1/customer/cm-firebase-token` مع `_method=put` و`guest_id` و`cm_firebase_token` — `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:91-110`. يُستدعى بعد الدخول وعند الإقلاع للمستخدم المسجَّل فقط — `lavkooapp:lib/features/splash/screens/splash_screen.dart:131-134`، `lavkooapp:lib/features/auth/controllers/auth_controller.dart:354-356`. الخلفية تحفظه في عمود واحد `users.cm_firebase_token` أو `guest_users.fcm_token` — `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:638-660`. المسار معرَّف بـ `Route::put` فقط (`lavkoo:routes/rest_api/v1/api.php:308`)، فوصول هذا النداء فعلياً يتوقف على مسألة `_method` داخل جسم JSON المسجَّلة `UNKNOWN` في 2.3. نطاق استدعاء تسجيل التوكن مفصّل في SEC-E16-16: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- **التوجيه حسب `type`:** `order`، `wallet`، `chatting`، `product_restock_update`، `notification`، `referral_code_used`، `block`، `maintenance_mode`، `demo_reset`، و`theme_version_changed` (صامت يشغّل مزامنة الثيم) — `lavkooapp:lib/push_notification/notification_helper.dart:41-83`، `lavkooapp:lib/push_notification/notification_helper.dart:85-166`، `lavkooapp:lib/push_notification/notification_helper.dart:410-441`. عند الإقلاع من إشعار يوجَّه المستخدم المسجَّل من شاشة البداية — `lavkooapp:lib/features/splash/screens/splash_screen.dart:135-166`.
- ملاحظات سلوكية: معالجة النوع `block` (`lavkooapp:lib/push_notification/notification_helper.dart:96-109`) موضوع بند مقيّد. SEC-E16-08: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.؛ شرط عرض الإشعار المحلي صحيح دائماً بسبب `||` — `lavkooapp:lib/push_notification/notification_helper.dart:140-144`؛ العرض المحلي لأندرويد فقط — `lavkooapp:lib/push_notification/notification_helper.dart:243-245`؛ معالج الخلفية لا يفعل شيئاً — `lavkooapp:lib/push_notification/notification_helper.dart:444-456`؛ قناة الإشعار باسم القالب `6valley` — `lavkooapp:lib/push_notification/notification_helper.dart:303-311`؛ `aps-environment` في iOS قيمته `development` — `lavkooapp:ios/Runner/Runner.entitlements:5-6`.
- المجلدان العلويان `push_notification/` و`localization/` نسخ أقدم خارج `lib/` لا تدخل في البناء (ناتج `diff` يُظهر أن نسخة `lib/` هي الأحدث؛ مثال الفرق: معالجة `theme_version_changed` موجودة في `lavkooapp:lib/push_notification/notification_helper.dart:89-94` فقط).

### 2.11 هوية التطبيق والسلاسل القديمة (تسجيل فقط — لا توصية بأي إعادة تسمية آلية)

| العنصر | `lavkooapp` (الحالي) | `ksaapp98` (القديم) | الدليل |
|---|---|---|---|
| اسم الحزمة في `pubspec` | `syriapharmacy` | `syriapharmacy` | `lavkooapp:pubspec.yaml:1` |
| الإصدار | `3.0.6+48` | `3.0.6+48` | `lavkooapp:pubspec.yaml:5` |
| `appName` | `LAVKOO` | `Ksa Cosmetics` | `lavkooapp:lib/utill/app_constants.dart:7`، `ksaapp98:lib/utill/app_constants.dart:7` |
| `baseUrl` | `https://lavkoo.com` | `https://pharmacyksa.com` | `lavkooapp:lib/utill/app_constants.dart:39`، `ksaapp98:lib/utill/app_constants.dart:39` |
| Android `applicationId` | `com.lavkoo.app` | `com.ksacosmetics.app` | `lavkooapp:android/app/build.gradle.kts:31`، `ksaapp98:android/app/build.gradle.kts:31` |
| Android host للروابط | `lavkoo.com` | `pharmacyksa.com` | `lavkooapp:android/app/src/main/AndroidManifest.xml:131`، `ksaapp98:android/app/src/main/AndroidManifest.xml:113` |
| اسم العرض في Android | `Lavkoo` في manifest، و`KSA Cosmetics` في `strings.xml` | `KSA Cosmetics` | `lavkooapp:android/app/src/main/AndroidManifest.xml:59`، `lavkooapp:android/app/src/main/res/values/strings.xml:2`، `ksaapp98:android/app/src/main/AndroidManifest.xml:49` |
| iOS bundle id | `com.ksacosmetics.app` (لم يتغيّر) | `com.ksacosmetics.app` | `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414`، `ksaapp98:ios/Runner.xcodeproj/project.pbxproj:414` |
| اسم العرض في iOS | `CFBundleName = Lavkoo`، `CFBundleDisplayName = KSA Cosmetics` | `KSA Cosmetics` | `lavkooapp:ios/Runner/Info.plist:17-20` |
| مشروع Firebase (Android) | `lavkoo-380f1` للحزمة `com.lavkoo.app` | `ksa-cosmetics` | `lavkooapp:android/app/google-services.json:4`، `lavkooapp:android/app/google-services.json:12`، `ksaapp98:android/app/google-services.json:4` |
| Firebase (iOS / Dart) | `lavkoo-380f1` و`iosBundleId = com.lavkoo.app` (لا يطابق bundle id في `pbxproj`) | `ksa-cosmetics` / `com.ksacosmetics.app` | `lavkooapp:lib/firebase_options.dart:64-66`، `lavkooapp:ios/Runner/GoogleService-Info.plist:9-12`، `ksaapp98:lib/firebase_options.dart:63-67` |
| `firebase.json` (FlutterFire) | يشير إلى مشروع ثالث `syria-pharmacy-ce885` | — | `lavkooapp:firebase.json:1` |
| iOS associated domain | `pharmacyksa.com` | `pharmacyksa.com` | `lavkooapp:ios/Runner/Runner.entitlements:19` |

إضافات من التحقق المستقل على جدول الهوية:

- **تاريخ السلالتين في git:** سجل `ksaapp98` (20 إيداعاً) يتضمن إيداعات تجهيز للنشر في المتاجر: توقيع الإصدار و`targetSdk 36` (`ksaapp98@4542dff`)، وجاهزية App Store (`ksaapp98@607ec72`)، ورفع رقم البناء لـ App Store Connect (`ksaapp98@84f181e`، `ksaapp98@750468d`). أما `lavkooapp` فإيداعه الأول (`lavkooapp@0df8b09`) مؤرَّخ 2026-11-11، وكود `lib/` فيه يطابق رأس `ksaapp98` عدا الهوية والتنسيق (2.12). الاستنتاج (INFERRED من رسائل الإيداع وحدها): السلالة التي جُهِّزت للمتاجر حتى ذلك التاريخ هي سلالة `com.ksacosmetics.app`؛ ما نُشر فعلاً يبقى `UNKNOWN`.
- **إعدادات تسجيل الدخول بـ Google تحتاج مطابقة مع مشروع Firebase الحالي:** التفاصيل في الملحق المقيّد SEC-E16-19: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

سلاسل إرث أخرى مسجَّلة: عنوان README `flutter_syriacosmetics_ecommerce` (`lavkooapp:README.md:1`)؛ ملف `MainActivity.kt` يتيم بحزمة `com.missjz.miss` بجانب الفعلي (`lavkooapp:android/app/src/main/kotlin/com/missjz/miss/MainActivity.kt:1`، `lavkooapp:android/app/src/main/kotlin/com/lavkoo/app/MainActivity.kt:1`)؛ مخطط `ksaseller` في iOS مقابل `lavkooseller` في Android (`lavkooapp:ios/Runner/Info.plist:56`، `lavkooapp:android/app/src/main/AndroidManifest.xml:49`)؛ ثلاثة معرّفات مختلفة لتطبيق البائع داخل تطبيق العميل: `com.lavkoo.seller` في manifest (`lavkooapp:android/app/src/main/AndroidManifest.xml:44`)، و`com.lavkoocosmeticsmanager.app` ورابط Play لـ `com.syriapharmacy.vendor` في الشاشة (`lavkooapp:lib/features/more/screens/become_merchant_screen.dart:97-102`)، والمعرّف الفعلي لتطبيق البائع هو `com.lavkoocosmeticsmanager.app` (`lavkooappseller:android/app/build.gradle.kts:39`)؛ نص تسويقي «in Syria» (`lavkooapp:lib/features/more/screens/become_merchant_screen.dart:184`)؛ مصدر أخبار خارجي على host إرث (`lavkooapp:lib/features/health/domain/models/news_model.dart:21-23`)؛ ثابت الموضوع `sixvalley` وقناة `6valley`. تكرار `syriapharmacy` في المستودع: 4182 مرة في 595 ملفاً (استيرادات الحزمة).

ملفات الترجمة المرفقة تحمل نصوصاً قانونية وتعريفية بعلامة قديمة وولاية قضائية غير خليجية: 461 مدخلاً يحتوي `syria` في `ar.json` و469 في `en.json`، ومنها سياسة خصوصية تنصّ على قانون ومحاكم دولة أخرى (`lavkooapp:assets/language/ar.json:2401`، `lavkooapp:assets/language/en.json:2207`) وبيانات اتصال `<REDACTED>` (هاتف وبريد أعمال) في `lavkooapp:assets/language/en.json:2209`. لا مرجع لها في كود Dart (بحث `syria_|privacyIntroduction|privacyLaw|termsIntro|aboutUsIntro` في `lib/`: صفر نتائج) — فهي محتوى خامد لكنه يُشحن داخل الحزمة. السياسات المعروضة فعلياً تأتي من الخلفية عبر `business-pages` (`lavkooapp:lib/features/splash/domain/repositories/splash_repository.dart:23-32`).

### 2.12 الاختبارات و CI والفروق عن التطبيق القديم

- `lavkooapp` يحتوي commit-ين فقط: `lavkooapp@0df8b09` (استيراد أولي) و`lavkooapp@eedbbb8` (توثيق). لا يوجد مجلد `.github` (0 ملف متتبَّع) ولا `pubspec.lock` (النمط `*.lock` في `lavkooapp:.gitignore:3`).
- 11 ملف اختبار؛ لا اختبار لـ `DioClient` أو الترويسات أو `PriceConverter` أو `ConfigModel` أو بوابة الإصدار.
- `ksaapp98` يحتوي ما ليس في `lavkooapp`: workflow للـ CI (`ksaapp98:.github/workflows/flutter.yml`)، مجلد `docs/`، وستة اختبارات منها `ksaapp98:test/release_version_test.dart` التي تثبّت حدّ الإصدار `14.1` (`ksaapp98:test/release_version_test.dart:26-27`). تعليق `lavkooapp:lib/utill/app_constants.dart:27-28` يشير إلى اختبار `test/release_version_test.dart` غير الموجود في `lavkooapp`، وتعليق `lavkooapp:ios/Runner/Runner.entitlements:16` يشير إلى `docs/release-readiness.md` غير الموجود فيه كذلك.
- في ملفات نطاقي: الفروق بين المستودعين في `app_constants.dart` هي `appName` و`baseUrl` فقط؛ `dio_client.dart` و`price_converter.dart` و`splash_controller.dart` و`config_model.dart` و`main.dart` متطابقة حرفياً (ناتج `diff -q`). أعاد التحقق المستقل المقارنة بـ `diff -b -B` على ملفات Dart الـ 34 المختلفة تحت `lib/`: 20 ملفاً منها فروقها مسافات وتنسيق فقط (طبقة البيانات، محلِّل الروابط، الدفع كله، المحفظة، الطلبات، الشحن، الولاء)، و14 ملفاً فيها فروق محتوى صغيرة (من سطرين إلى 24 سطراً) تنحصر في الهوية والمظهر: `firebase_options.dart`، ألوان الثيم، حركة الإقلاع، ثلاثة renderers للثيم، شاشتا الأخبار، التعريف الأول، شاشة التاجر، `app_constants.dart`، ولونا خلفية شاشة البداية (`lavkooapp:lib/features/splash/screens/splash_screen.dart:365`). لم يُقرأ مضمون فروق الـ renderers الثلاثة تفصيلاً (يُحال لنطاق الثيم). الملف `lib/features/wallet/screens/add_fund_to_wallet_screen.dart` موجود في `lavkooapp` فقط وهو يتيم (2.2).
- لم يستعمل المؤلف الوثيقة القديمة `ksaapp98:docs/app-backend-compatibility.md` (3857 سطراً: مصفوفة توافق التطبيق مع الخلفية القديمة). رجع إليها التحقق المستقل للمقارنة فقط؛ ما فيها مكتوب عن الخلفية القديمة غير المتاحة، فلا يُعدّ دليلاً على `lavkoo` إلا حيث أُعيد فتح الكود هنا.

## 3. افتراضات المتجر الواحد / الدولة / العملة

1. **عنوان واحد ثابت وقت الترجمة** لكل الطلبات، وعميل HTTP واحد singleton — `lavkooapp:lib/utill/app_constants.dart:39`، `lavkooapp:lib/di_container.dart:206-207`.
2. **لا مفهوم للمتجر/الدولة في أي طلب**: لا ترويسة ولا مسار ولا معامل (2.2). كلمة `country` في التطبيق تعني إمّا دولة الـ Locale (`country_code` = `SA`/`US`) أو رمز اتصال الهاتف أو نص حر في العنوان.
3. **`config` واحد عام** يحدّد العملة والخانات العشرية ورمز الدولة والموقع الافتراضي وطرق الدفع والألوان والصيانة وبوابة الإصدار، ويُخزَّن تحت مفتاح واحد بلا بُعد (2.4).
4. **عملة واحدة فعلياً**: المنطق يفرض العملة الافتراضية للنظام (أو `SYP`) ويلغي اختيار المستخدم (2.7).
5. **تحويل عملة في العميل** بمعامل `exchangeRate` نسبةً إلى `USD` لكل سعر معروض، مع **صفر خانات عشرية** (2.7).
6. **إعداد عشري واحد عام** لا لكل عملة — `lavkooapp:lib/features/splash/domain/models/config_model.dart:286-291`.
7. **افتراض سوري قديم مكتوب في الكود**: أولوية `SYP`، رمز الليرة، البديل `'SYP'`، ورمز اللغة `sy` المرسل للخلفية — `lavkooapp:lib/features/splash/controllers/splash_controller.dart:236-246`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:567-570`، `lavkooapp:lib/localization/controllers/localization_controller.dart:38-39`.
8. **لغتان فقط** والعربية افتراضية بدولة Locale `SA` — `lavkooapp:lib/utill/app_constants.dart:338-349`.
9. **صيانة واحدة عامة** تحجب التطبيق كله، وموضوع FCM واحد عام لها (2.5، 2.10).
10. **بوابة إصدار واحدة عامة** لكل منصّة (2.5).
11. **موضوعات FCM عامة** بلا بُعد دولة، بلا إلغاء اشتراك (2.10).
12. **host واحد للروابط العميقة** ومسارات بلا بادئة دولة (2.9).
13. **هوية عميل واحدة بتوكن واحد** (يتوافق مع GCC-13)، لكن **سلة واحدة** و**محفظة/نقاط واحدة** في نموذج الملف الشخصي (`wallet_balance`، `loyalty_point`) — `lavkooapp:lib/features/profile/domain/models/profile_model.dart:57-63`.
14. **عنوان نصّي حر** بدولة واحدة افتراضية من `config` (2.8).
15. **مجموعة سياسات واحدة** من `config`/`business-pages`، ونصوص قانونية خامدة مرفقة بولاية قضائية واحدة غير خليجية (2.11).

## 4. الأثر على قرارات GCC المعتمدة

### 4.1 جدول القرارات

| القرار / الأساس | ما يدعمه اليوم في هذا النطاق | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Foundation: six markets enabled independently | `config` يُجلب عند كل إقلاع ويتحكّم في الأعلام، فيمكن للخادم تغيير السلوك دون نشر | لا منتقي دولة، لا قائمة متاجر، لا مفتاح محفوظ للمتجر | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:75-225` |
| Foundation: one domain with `/sa /ae …` paths, manual choice beats IP | لا يوجد أي استنتاج بالـ IP أو الموقع، فالاختيار اليدوي هو الآلية الوحيدة الممكنة | مسارات الروابط والراوتر والـ manifest بلا بادئة دولة؛ host iOS يخالف host Android | `lavkooapp:android/app/src/main/AndroidManifest.xml:131-141`، `lavkooapp:ios/Runner/Runner.entitlements:19` |
| Foundation: distinct concepts (Country ≠ Seller/Shop) | التطبيق لا يعيد استعمال `seller_id`/`shop_id` كدولة | مفتاح `country_code` المحفوظ والحقل `DioClient.countryCode` يحملان دلالة Locale؛ إعادة استعمالهما للمتجر تخلط المفهومين | `lavkooapp:lib/localization/controllers/localization_controller.dart:78-82`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:28-29` |
| Foundation: independent prices, no auto-FX | — | كل سعر معروض يُضرب في معامل صرف داخل العميل | `lavkooapp:lib/helper/price_converter.dart:65-73` |
| ملاحظة النقد (KWD/BHD/OMR بثلاث خانات) | `decimal_point_settings` موجود كحقل | الدالة المركزية تثبّت صفر خانات؛ الإعداد عام لا لكل عملة | `lavkooapp:lib/helper/price_converter.dart:75-78` |
| Foundation: payments bound to legal entity/store | طرق الدفع تُقرأ من `config` وتُحدَّث عند فتح الدفع؛ العملة تُرسل في `current_currency_code` | كشف عودة الدفع مربوط بـ host واحد `baseUrl` | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:301-321`، `lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:123-135` |
| GCC-07 / GCC-09 (ثيم لكل دولة، تخصيص التطبيق) | تفاوض قدرات عبر `X-UI-*` وكاش ثيم بمفتاح مستقل | مفتاح كاش الثيم بلا بُعد متجر؛ موضوع `theme_updates_user_app` عام | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:31`، `lavkooapp:lib/utill/app_constants.dart:329` |
| GCC-10 (علامة مشتركة مع استثناءات) | `company_name` والألوان والشعار من `config` | `appName` ثابت؛ أسماء العرض في المنصّتين متباينة أصلاً | `lavkooapp:lib/utill/app_constants.dart:7`، `lavkooapp:ios/Runner/Info.plist:17-20` |
| GCC-11 (سياسات لكل دولة) | السياسات تُجلب من الخادم | مجموعة واحدة عامة؛ `business-pages` مخزّنة بمفتاح لغة فقط؛ نصوص قانونية خامدة مرفقة | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-140`، `lavkooapp:assets/language/en.json:2207` |
| GCC-12 (إيقاف لكل متجر: تصفّح/صيانة/تحويل) | شاشة صيانة وإعادة فحص عند العودة وإشعار FCM | وضع واحد يحجب كل شيء (بما فيه الطلبات السابقة والدعم)؛ لا «تصفّح فقط» ولا «تحويل»؛ انظر SEC-E16-11 أعلاه. | `lavkooapp:lib/features/maintenance/maintenance_screen.dart:58-64` |
| GCC-13 (هوية واحدة، سلال/محافظ منفصلة) | توكن واحد عام يناسب الهوية الواحدة | كاش السلة والطلبات بلا بُعد متجر ولا مستخدم؛ حقل محفظة ونقاط واحد | `lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:21-27`، `lavkooapp:lib/features/profile/domain/models/profile_model.dart:57-63` |
| GCC-15 (ولاء محلي) | — | `loyalty_point_exchange_rate` وحقل نقاط واحد عام | `lavkooapp:lib/features/splash/domain/models/config_model.dart:297-305` |
| GCC-16 (شحن محلي وعابر) | قيود الدول والرموز البريدية تُجلب من الخادم | عنوان نصّي حر لا يستهلك كتالوج المناطق/المدن | `lavkooapp:lib/features/address/domain/models/address_model.dart:1-17` |
| GCC-19 (السعودية المتجر الافتراضي) | التطبيق لا يرسل سياقاً، فالخادم يحسم الافتراضي | النسخ المثبَّتة ستتبع «الافتراضي الحالي» إن تغيّر لاحقاً (انظر 4.4) | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73` |
| قيد: caches / notifications / app-local caches market-scoped | سابقة جاهزة: تغيير اللغة يمسح المخازن الثلاثة ويعيد بناء الترويسات | لا بُعد متجر في أي مفتاح | `lavkooapp:lib/localization/controllers/localization_controller.dart:28-60` |

### 4.2 مقارنة خيارات حمل سياق المتجر مع بنية `DioClient` (توثيق فقط)

| الخيار | ما يلمسه في التطبيق | ما لا يغطّيه تلقائياً | الدليل |
|---|---|---|---|
| **ترويسة** (مثل ترويسة متجر جديدة) | دالة واحدة `_headers()` + إعادة بناء عبر `updateHeader`؛ النمط نفسه الذي تسلكه اللغة اليوم | 6 مواضع `MultipartRequest`، موضعا `HttpClient`، `http.get`، 5 مواضع `FlutterDownloader.enqueue`، صفحات WebView (الدفع والمدونة)، الروابط الخارجية (جدول 2.2)؛ ومفاتيح الكاش الثلاثة التي تتضمّن اللغة فقط | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-172`، `lavkooapp:lib/data/reposotories/data_sync_repo.dart:36-39` |
| **بادئة مسار داخل الـ API** | 164 ثابت مسار + 15 بادئة في قائمة الكاش التي تطابق بـ `contains` | كل ما سبق، مع خطر كسر مطابقة قائمة الكاش إن وقعت البادئة بعد `/api/v1` | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-147` |
| **عنوان أساسي لكل متجر** (مثل `…/sa`) | سطر الحقن الواحد، لكن `baseUrl` ثابت `const` والعميل singleton يُضبط مرة واحدة | 19 موضعاً آخر يستعمل `AppConstants.baseUrl` مباشرة: روابط التخزين يجب ألّا تأخذ البادئة، ومواضع معالجة عودة الدفع تتحمّل البادئة (انظر SEC-E16-14 أعلاه) | `lavkooapp:lib/di_container.dart:206-207`، `lavkooapp:lib/push_notification/notification_helper.dart:259` |

قراءة الأدلة: خيار الترويسة هو الأقل مساساً بكود التطبيق، ويتّسق مع سابقتين قائمتين لدى الخلفية (قراءة `lang` في `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29`، وقراءة `X-UI-*` في `lavkoo:app/Services/Theme/ViewerContext.php:76-86`). أيّاً كان الخيار، تبقى المواضع الخارجة عن `DioClient` ومفاتيح الكاش عملاً لازماً، وتبقى المسارات الحالية `/api/v1/*` الخالية من أي بادئة أو سياق عقداً دائماً للنسخ المثبَّتة. تنبيه تسمية: الخلفية تفسّر اليوم `lang: sa` على أنها العربية لتوافق نسخ قديمة (`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:24-27`)، فرمز المتجر `sa` يجب ألّا يمرّ في ترويسة `lang` ولا في المفتاح `country_code`.

### 4.3 ما يحتاجه التطبيق لمنتقي متجر صريح ولعزل التخزين (توثيق فقط)

- **مفتاح محفوظ جديد للمتجر** منفصل عن `country_code` (دلالة Locale) وعن `currency` (يُشتق من المتجر لا من المستخدم).
- **نقطة اختيار**: لا توجد اليوم إلا شاشة الإعدادات (لغة وعملة)؛ التعريف الأول ثابت بلا اختيار (2.7).
- **إقلاع مرحلتين**: قائمة المتاجر المفعّلة يجب أن تتوافر قبل `config` الخاص بالمتجر؛ واليوم `config` المحلي يُطبَّق قبل الشبكة تحت مفتاح واحد (2.4)، فتبديل المتجر دون مفتاح مخصّص يعرض عملة وصيانة وطرق دفع المتجر السابق للحظات.
- **عزل الكاش**: إضافة بُعد المتجر إلى مفتاح Dio القرصي ومفتاح `DataSyncRepo` والمفاتيح المباشرة (config، الطلبات، الثيم، العروض)، أو مسح شامل عند التبديل على غرار تغيير اللغة.
- **التوكن**: يبقى عاماً (GCC-13). **معرّف الضيف وسلّته**: القرار بين معرّف لكل متجر في التطبيق أو عزل (guest_id, storefront) في الخادم يخصّ نطاق السلة.
- **الموضوعات**: تسمية لكل متجر + إلغاء اشتراك عند التبديل (غير موجود اليوم)؛ و`sixvalley` يُعامل كجمهور الإرث السعودي.
- **الروابط العميقة ببادئة `/sa`** تحتاج عملاً في أربع طبقات: مسارات manifest، مسارات GoRouter، القراءة المحلية `readLocally` (أول مقطع سيكون `sa` فيُفتح المتصفح — `lavkooapp:lib/data/services/deep_link_resolver.dart:96-117`)، وأنماط الخلفية (`lavkoo:config/deeplinks.php:39-45`)؛ مع قاعدة لما يحدث حين يخالف متجرُ الرابط المتجرَ المختار.
- **العملة والأسعار**: إلغاء أولوية `SYP` والتحويل داخل العميل، وخانات عشرية لكل عملة.

### 4.4 سلوك النسخ المثبَّتة القديمة بعد تحوّل الخلفية إلى متعدّدة المتاجر

توجد سلالتان محتملتان في الميدان (أيهما منشور فعلاً: `UNKNOWN`، يحتاج Play Console و App Store Connect): سلالة `ksaapp98` (حزمة `com.ksacosmetics.app`، host `pharmacyksa.com`، مشروع Firebase `ksa-cosmetics`) وسلالة `lavkooapp` (Android `com.lavkoo.app`، host `lavkoo.com`، مشروع `lavkoo-380f1`). كلتاهما تشتركان في الكود التالي (`dio_client.dart` متطابق):

1. **لا ترسل أي سياق متجر**، وتنادي `/api/v1/*` بلا بادئة؛ فالخادم وحده يحسم المتجر. للإبقاء على GCC-19 يجب أن يُحسم لها المتجر السعودي تحديداً. تعارض دقيق مع نص القرار: GCC-19 يسمح بتغيير الافتراضي لاحقاً، بينما نسخة قديمة تحمل توكناً وعناوين وسلة سعودية ستنتقل صامتةً إلى دولة أخرى إن رُبطت بـ «الافتراضي الحالي». هذا ليس طلب إعادة فتح للقرار بل قيد تنفيذ: افتراضي العملاء عديمي السياق يُثبَّت على السعودية بمعزل عن افتراضي الويب.
2. **لا يمكن تمييزها بالإصدار**: رأسا المستودعين يرسلان `X-App-Version: 16.2.1` (`lavkooapp:lib/utill/app_constants.dart:29`، `ksaapp98:lib/utill/app_constants.dart:29`)، والترويسة والثابت موجودان منذ أول إيداع في `ksaapp98` (`ksaapp98@afb8974`؛ بحث `git log -S`). تقييد من التحقق المستقل: هذا VERIFIED للتاريخ المتتبَّع في المستودعين فقط. ما بُني قبل هذا التاريخ `UNKNOWN`، وتعليقا الكود يدلّان على وجود نسخ ميدانية أقدم كانت ترسل رمز الدولة في ترويسة `lang` (`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:24-27`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:84-88`)، فلا يصح افتراض أن كل نسخة مثبَّتة ترسل ترويسة إصدار أصلاً. التمييز الموثوق الوحيد هو غياب ترويسة المتجر الجديدة نفسها.
3. **تحلّل `config` بصرامة** (2.4): يجب أن تبقى أنواع الحقول وأسماؤها كما هي، وأن تعيد لها الخلفية عملة السعودية وإعدادها العشري ورمز دولتها. أثر فشل التحليل على النسخ المثبَّتة: انظر SEC-E16-09 أعلاه.
4. **تعرض الأسعار** بضرب معامل الصرف وصفر خانات؛ السلوك لا يتغيّر ما دامت قائمة العملات السعودية كما هي.
5. **تبقى مشتركة** في `sixvalley` و`maintenance_mode_start_user_app` و`theme_updates_user_app` إلى الأبد (لا إلغاء اشتراك)؛ أي بثّ على هذه الموضوعات لمتجر آخر يصل إليها. رسالة صيانة لمتجر آخر تجعلها تعيد تحميل `config` السعودي فقط؛ لكن إن بقي علم الصيانة عاماً فستُحجب بالكامل.
6. **الروابط الجديدة ببادئة `/sa/…`** لا تطابق intent-filter فتُفتح في المتصفح (سلوك مقبول)؛ الروابط القديمة بلا بادئة تفتح التطبيق وتُحلّ على المتجر الافتراضي.
7. **بوابة التحديث هي الأداة الوحيدة لإجبارها على الترقية**، وهي اليوم بلا رابط (بحسب تعليق الكود)، عامة لا لكل متجر، وتفاصيل تغطيتها عند الفتح البارد برابط عميق في SEC-E16-11 أعلاه.
8. **السلالة القديمة تنادي host آخر** (`pharmacyksa.com`) وتوكنات إشعاراتها في مشروع Firebase آخر؛ هل يخدم الـ host-ان الخلفية نفسها، وأي مشروع Firebase مهيّأ في الخلفية: `UNKNOWN`.

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أقل إضافة موصى بها (توثيق فقط) |
|---|---|---|---|
| `DioClient._headers()` و`updateHeader` | Extend | نقطة واحدة لكل الترويسات، وسابقة اللغة تعمل | إضافة سياق المتجر في الدالة نفسها مع إمكان التفريغ، دون تغيير الترويسات الحالية |
| مسارات `/api/v1/*` بلا سياق | Preserve | عقد كل النسخ المثبَّتة | إبقاؤها حيّة ومحسومة على السعودية للعملاء عديمي السياق |
| شكل رد `/api/v1/config` | Preserve | تحليل صارم بلا التقاط استثناء | إضافات فقط (حقول جديدة اختيارية)؛ لا تغيير نوع ولا حذف |
| `ConfigModel` | Extend | يستهلك كل إعدادات المتجر | حقول اختيارية جديدة للمتجر والعملة والخانات، مع تحليل متسامح |
| مواضع `MultipartRequest`/`HttpClient`/`http.get` | Refactor | تخرج عن الترويسات المشتركة | توحيد بناء الترويسات في مصدر واحد قبل إضافة أي سياق |
| مفاتيح الكاش (Dio القرصي، `DataSyncRepo`، المفاتيح المباشرة في Drift) | Refactor | بُعد اللغة فقط أو بلا بُعد | مولّد مفاتيح واحد يتضمّن المتجر (واللغة)، ومسح عند الخروج وعند تبديل المتجر |
| `PriceConverter` | Refactor | تحويل صرف في العميل وصفر خانات في 114 موضعاً | تنسيق بحسب خانات العملة القادمة من الخادم دون أي ضرب؛ اختبارات وحدة قبل التغيير |
| `SplashController.getCurrencyData` وورقة اختيار العملة | Refactor | أولوية `SYP` وإلغاء اختيار المستخدم | اشتقاق العملة من المتجر؛ إزالة فرع `SYP` بعد التأكد من بيانات الإنتاج |
| بوابة الإصدار (`_updateIsRequired`) | Extend | تعمل، لكن برقم ثابت يدوي ورابط فارغ، وتفاصيل تغطيتها في SEC-E16-11 | قرار المالك في ترقيم الإصدار والرابط؛ توحيد نقطة فحص البوابة لكل مسارات الإقلاع |
| شاشة الصيانة وتدفّقها | Extend | وضع واحد يحجب كل شيء | أوضاع GCC-12 الثلاثة في النسخة الجديدة مع إبقاء السلوك الحالي للنسخ القديمة |
| `DeepLinkResolver` + `DeepLinkGatewayScreen` | Preserve + Extend | الخادم مصدر الحقيقة؛ مسار السقوط المحلي في SEC-E16-13 أعلاه | دعم مقطع الدولة في القراءة المحلية وفي الراوتر والـ manifest |
| موضوعات FCM الثلاثة الثابتة | Preserve (للإرث) + New | النسخ القديمة لن تلغي اشتراكها أبداً | موضوعات جديدة لكل متجر للنسخة الجديدة مع إلغاء اشتراك عند التبديل |
| `LocalizationController` (مفتاحا `language_code`/`country_code`) | Preserve | يعمل، ودلالته Locale | عدم إعادة استعمال `country_code` للمتجر |
| توكن العميل `user_login_token` | Preserve | هوية واحدة عبر الدول (GCC-13) | لا تغيير في البُعد؛ معالجة البنود الأمنية المقيّدة على حدة |
| نموذج العنوان وشاشة الإضافة | Extend | نص حر لا يستهلك كتالوج المناطق والمدن | ربط اختياري بكتالوج العناوين الموحّد لكل دولة (تنسيق مع نطاق العناوين) |
| معرّفات التطبيق (applicationId، bundle id، Firebase، hosts) | Preserve (تسجيل فقط) | تعليمات المشروع تمنع إعادة التسمية الآلية، والحالة الراهنة متباينة بين السلالتين | قرار مالك موثّق بعد قراءة لوحات المتاجر؛ لا تغيير آلي |
| منتقي المتجر + مفتاح المتجر المحفوظ + قائمة المتاجر | New | غير موجود | شاشة اختيار عند أول تشغيل وفي الإعدادات، ونقطة خادم لقائمة المتاجر |
| اختبارات `DioClient`/`PriceConverter`/`ConfigModel`/بوابة الإصدار و CI | New | غير موجودة في `lavkooapp` | نقل ما في `ksaapp98` وإضافة اختبارات عزل المتجر |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E16-F01 | الدالة المركزية لعرض السعر تضرب في معامل صرف داخل العميل وتنسّق بصفر خانات عشرية (114 موضعاً في 42 ملفاً)؛ أي أن كل مبلغ يمرّ بها يُقرَّب إلى عدد صحيح حتى في المتجر السعودي | P0 | VERIFIED (الكود: الضرب وصفر الخانات)؛ UNKNOWN (قيمة المعامل الفعلية في الإنتاج)؛ INFERRED (اختلاف المعروض عن المحصَّل) | `lavkooapp:lib/helper/price_converter.dart:65-78`، `lavkooapp:lib/features/splash/controllers/splash_controller.dart:61`، `lavkoo:app/Utils/currency.php:113-131` | عرض خاطئ لـ KWD/BHD/OMR (ثلاث خانات)؛ مسار كود يخالف «لا تحويل آلي» ويتجاهل `currency_model` خلافاً لمحوّل الويب | تنسيق بخانات العملة من الخادم دون ضرب؛ اختبارات قبل أي تغيير |
| E16-F02 | هوية التطبيق متباينة بين السلالتين وداخل `lavkooapp` نفسه (Android `com.lavkoo.app` و`lavkoo.com` و`lavkoo-380f1`؛ iOS bundle `com.ksacosmetics.app` مع إعداد Firebase لـ `com.lavkoo.app` ونطاق مرتبط `pharmacyksa.com`؛ القديم `com.ksacosmetics.app` و`pharmacyksa.com` و`ksa-cosmetics`) | P0 | VERIFIED للكود؛ UNKNOWN لما هو منشور | `lavkooapp:android/app/build.gradle.kts:31`، `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414`، `lavkooapp:lib/firebase_options.dart:64-66`، `ksaapp98:android/app/build.gradle.kts:31`، `ksaapp98:lib/utill/app_constants.dart:39`، `lavkooapp:ios/Runner/Info.plist:5-6`، `ksaapp98@4542dff` | تعريف «النسخة القديمة» وخط التوافق غير محسوم؛ خطر انقطاع الإشعارات والروابط والدخول الاجتماعي واستمرارية التحديث (INFERRED) | تسجيل فقط: قرار مالك بعد قراءة لوحات المتاجر و Firebase؛ لا إعادة تسمية آلية |
| E16-F03 | النسخ المثبَّتة لا ترسل سياق متجر وتحلّل `config` بصرامة دون التقاط استثناء | P0 | VERIFIED (الإرسال والتحليل الصارم)؛ INFERRED (أثر فشل التحليل)؛ UNKNOWN (ترويسات النسخ الأقدم من التاريخ المتتبَّع) | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73`، `lavkooapp:lib/features/splash/controllers/splash_controller.dart:90-128`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:795` | تغيير افتراضي المتجر ينقل النسخ القديمة صامتةً؛ تغيير شكل `config` يُعلِّق التثبيت الجديد عند البداية (أثره على النسخ ذات الكاش المحلي في SEC-E16-09 أعلاه) | تثبيت افتراضي العملاء عديمي السياق على السعودية؛ تجميد شكل `config`؛ اختبار عقد |
| E16-F04 | لا منتقي متجر ولا مفتاح محفوظ ولا انتقال سياق؛ ومواضع تمرّ خارج `DioClient` (6 multipart، 2 `HttpClient`، `http.get`، 5 `FlutterDownloader.enqueue`، WebView للدفع والمدونة) و host مكتوب حرفياً في موضع واحد | P1 | VERIFIED | `lavkooapp:lib/features/profile/domain/repositories/profile_repository.dart:39-43`، `lavkooapp:lib/features/chat/controllers/chat_controller.dart:625-632`، `lavkooapp:lib/features/more/screens/become_merchant_screen.dart:97`، `lavkooapp:lib/features/onboarding/domain/repositories/onboarding_repository.dart:17-32` | سياق المتجر لن يصل لكل الطلبات إن أُضيف في مكان واحد | منتقي + مفتاح جديد + توحيد بناء الترويسات |
| E16-F05 | مفاتيح التخزين المحلي بلا بُعد متجر (وبعضها بلا مستخدم أو لغة) | P1 | VERIFIED | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-172`، `lavkooapp:lib/data/reposotories/data_sync_repo.dart:36-39`، `lavkooapp:lib/features/order/controllers/order_controller.dart:23-33`، `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:186-191` | عرض بيانات متجر سابق من الكاش بعد التبديل (الجانب المتعلق بالمستخدم في SEC-E16-06) | مولّد مفاتيح موحّد ببُعد المتجر، ومسح عند الخروج والتبديل |
| E16-F06 | منطق العملة يفرض `SYP` أو الافتراضية ويلغي اختيار المستخدم؛ `'SYP'` مكتوبة في أحداث التحليلات (الخدمة خامدة) | P1 | VERIFIED | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:236-246`، `lavkooapp:lib/features/splash/controllers/splash_controller.dart:278-281`، `lavkooapp:lib/features/cart/controllers/cart_controller.dart:144`، `lavkooapp:lib/services/meta_tracking_service.dart:15` | عملة العرض لا تتبع المتجر؛ عملة خاطئة في التحليلات عند التفعيل | اشتقاق العملة من المتجر؛ إزالة الفروع القديمة بعد فحص بيانات الإنتاج |
| E16-F07 | بوابة الإصدار: ثابت `16.2.1` لا يطابق البناء `3.0.6+48`، `X-App-Version` لا يميّز بين بناءات التاريخ المتتبَّع، الرابط فارغ بحسب تعليق الكود والوثيقة القديمة، البوابة عامة، وتفاصيل تغطيتها في SEC-E16-11 | P1 | VERIFIED (المنطق)؛ INFERRED (تغطية الإقلاع البارد)؛ UNKNOWN (قيمة الإنتاج الحالية والنسخ الأقدم من التاريخ المتتبَّع) | `lavkooapp:lib/utill/app_constants.dart:9-29`، `lavkooapp:lib/features/splash/screens/splash_screen.dart:325-338`، `lavkooapp:lib/features/update/screen/update_screen.dart:50-76` | لا وسيلة موثوقة لإجبار النسخ القديمة على الترقية قبل تفعيل تعدّد المتاجر | قرار مالك في الترقيم والرابط؛ فرض الفحص على مسار الرابط |
| E16-F08 | الصيانة وضع واحد عام يحجب التطبيق كله، تفاصيل تغطيته عند الفتح برابط في SEC-E16-11، وموضوعها عام | P1 | VERIFIED | `lavkooapp:lib/features/maintenance/maintenance_screen.dart:58-64`، `lavkooapp:lib/helper/route_healper.dart:1051-1074`، `lavkooapp:lib/utill/app_constants.dart:324` | GCC-12 غير قابل للتحقيق في التطبيق الحالي؛ إيقاف متجر يحجب الطلبات السابقة والدعم | أوضاع الإيقاف الثلاثة في النسخة الجديدة؛ إبقاء علم الإرث للسعودية فقط |
| E16-F09 | الروابط العميقة بلا بادئة دولة في أربع طبقات؛ host Android (`lavkoo.com`) يخالف نطاق iOS (`pharmacyksa.com`)؛ manifest يطالب بمسارات غير منشورة لدى الخلفية | P1 | VERIFIED | `lavkooapp:android/app/src/main/AndroidManifest.xml:131-141`، `lavkooapp:ios/Runner/Runner.entitlements:19`، `lavkooapp:lib/data/services/deep_link_resolver.dart:96-117`، `lavkoo:config/deeplinks.php:39-45` | روابط `/sa/…` لن تفتح التطبيق؛ روابط iOS قد لا تعمل أصلاً | تصميم مسارات الروابط مع نطاق الويب، ثم تحديث الطبقات الأربع معاً |
| E16-F10 | موضوعات FCM عامة بلا إلغاء اشتراك؛ توكن واحد لكل عميل؛ مشروعا Firebase مختلفان بين السلالتين | P1 | VERIFIED للكود؛ UNKNOWN لإعداد الخلفية ولوصول نداء تسجيل التوكن فعلياً (مسألة `_method`، 2.3) | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:91-110`، `lavkooapp:lib/features/splash/controllers/splash_controller.dart:142-150`، `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:638-660` | بثّ متجر يصل لجمهور متجر آخر؛ تعذّر عزل جمهور التسويق | موضوعات لكل متجر + إلغاء اشتراك؛ معاملة الموضوعات الحالية كجمهور إرث سعودي |
| E16-F11 | نموذج العنوان نص حر ولا يستهلك كتالوج المناطق والمدن؛ بدائل القالب `+880`/`BD`/`Bangladesh`/`IN` | P1 | VERIFIED | `lavkooapp:lib/features/address/domain/models/address_model.dart:1-17`، `lavkooapp:lib/features/auth/controllers/auth_controller.dart:74`، `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:68` | عناوين غير قابلة للتحقق لكل دولة؛ افتراضات هاتف خاطئة عند غياب الإعداد | ربط العنوان بكتالوج الدولة؛ افتراضات من المتجر |
| E16-F12 | تداخل دلالات: `lang: sa` تُفسَّر عربيةً في الخلفية، المفتاح `country_code` يعني Locale، ورمز اللغة المرسل `sy` | P1 | VERIFIED | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29`، `lavkooapp:lib/localization/controllers/localization_controller.dart:38-39`، `lavkooapp:lib/localization/controllers/localization_controller.dart:78-82` | خلط اللغة بالدولة عند إدخال رمز المتجر `sa` | اسم ترويسة ومفتاح جديدان للمتجر؛ فصل صريح بين Locale والمتجر |
| E16-F13 | سلاسل وهويات إرث متباينة (اسم الحزمة، README، أسماء العرض، مخططات `ksacosmetics`/`ksaseller`، ثلاثة معرّفات لتطبيق البائع، `firebase.json`، `MainActivity` يتيم، `sixvalley`/`6valley`) | P2 | VERIFIED | `lavkooapp:pubspec.yaml:1`، `lavkooapp:ios/Runner/Info.plist:36`، `lavkooapp:lib/features/more/screens/become_merchant_screen.dart:97-102`، `lavkooapp:firebase.json:1` | التباس عند النشر وفتح تطبيق البائع؛ لا أثر مباشر على GCC | تسجيل فقط؛ لا إعادة تسمية آلية |
| E16-F14 | نصوص قانونية وتعريفية خامدة مرفقة بعلامة قديمة وولاية قضائية غير خليجية | P2 | VERIFIED | `lavkooapp:assets/language/ar.json:2401`، `lavkooapp:assets/language/en.json:2207` | محتوى قانوني غير معتمد يُشحن داخل الحزمة (GCC-11) | مراجعة قانونية وتنظيف في مرحلة التنفيذ |
| E16-F15 | `lavkooapp` يفتقد CI واختبارات وحماية الإصدار الموجودة في `ksaapp98`؛ `pubspec.lock` غير متتبَّع؛ مجلدان علويان مكرّران قديمان | P2 | VERIFIED | `ksaapp98:test/release_version_test.dart:26-27`، `lavkooapp:.gitignore:3`، `lavkooapp:lib/utill/app_constants.dart:27-28` | انحدار في شبكة الأمان قبل أي تعديل GCC | نقل الاختبارات و CI أولاً (يتبع نطاق السلالة H) |
| E16-F16 | عيوب سلوكية في معالجة الإشعارات: شرط عرض الإشعار المحلي دائم الصحة؛ معالج الخلفية فارغ؛ العرض المحلي لأندرويد فقط؛ `aps-environment = development`؛ ومعالجة نوع `block` (انظر SEC-E16-08) | P2 | VERIFIED (الكود)؛ INFERRED (أثر APNs) | `lavkooapp:lib/push_notification/notification_helper.dart:140-144`، `lavkooapp:lib/push_notification/notification_helper.dart:444-456`، `lavkooapp:ios/Runner/Runner.entitlements:5-6` | سلوك إشعارات غير متوقّع في الإصدار | إصلاح ضمن عمل الإشعارات لكل متجر |
| E16-F17 | مفتاح خرائط Google قيمة نائبة في المنصّتين | P2 | VERIFIED (المستودع)؛ UNKNOWN (البناء المنشور) | `lavkooapp:android/app/src/main/AndroidManifest.xml:74-77`، `lavkooapp:ios/Runner/AppDelegate.swift:16` | الخريطة قد لا تعمل في بناء من المستودع | توثيق مصدر المفتاح وقت البناء |
| E16-F18 | البنية: Provider + GetIt + GoRouter + Dio singleton + ثلاث آليات تخزين (SharedPreferences، Drift، Hive/sqflite) | INFO | VERIFIED | `lavkooapp:lib/main.dart:155-217`، `lavkooapp:lib/di_container.dart:196-207` | أساس تصميم العزل | — |
| E16-F19 | كاش GET داخل `DioClient` يشمل `theme/version` و`business-pages`: طبقة القرص بمدة يحدّدها الخادم (400000 مللي ثانية)، وطبقة الذاكرة بلا مدة صلاحية طوال عمر العملية | P2 | VERIFIED (الكود)؛ INFERRED (تعطّل مزامنة الثيم داخل الجلسة) | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:103-140`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:188-192`، `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:205-231`، `lavkoo:routes/rest_api/v1/api.php:58-60` | نشر ثيم أو سياسة لمتجر قد لا يظهر داخل الجلسة المفتوحة؛ وبعد تبديل متجر يُعاد رد المتجر السابق من الذاكرة | يُراعى في تصميم إبطال الكاش لكل متجر؛ يؤكّده نطاق الثيم |
| E16-F20 | ميزة «الصحة» تخزّن بيانات حساسة محلياً بلا بُعد مستخدم وتجلب أخباراً من host خارجي إرث | INFO | VERIFIED | `lavkooapp:lib/features/health/data/health_database_helper.dart:10-57`، `lavkooapp:lib/features/health/domain/models/news_model.dart:21-23` | امتثال خصوصية لكل دولة | يُحال لنطاق الامتثال |
| E16-F21 | (أضافه التحقق المستقل) نداءات تعتمد على `_method` داخل جسم JSON فوق `POST` نحو مسارات `Route::put`/`Route::delete` فقط: تسجيل اللغة، تسجيل توكن الجهاز، وحذف/تحديث عناصر أخرى | P1 | VERIFIED (شكل الطلب والمسار)؛ UNKNOWN (هل يصل الطلب أم يعود 405) | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:91-110`، `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:135-149`، `lavkoo:routes/rest_api/v1/api.php:277`، `lavkoo:routes/rest_api/v1/api.php:308`، `ksaapp98:docs/app-backend-compatibility.md:2353-2354` | إن لم يصل: لغة العميل وتوكن جهازه لا يُسجَّلان، فتتعطل الإشعارات الفردية ولغتها، وهما أساس أي إشعار مقيّد بالمتجر | اختبار تشغيلي واحد على الخلفية يحسم المسألة قبل تصميم إشعارات كل متجر (U12) |
| SEC-E16-01 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P0 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-02 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-03 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-04 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-05 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-06 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-07 | مسألة أمنية مقيّدة (تخصّ ملفات لدى الخلفية وتُحال لنطاقها) — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-08 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-09 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | INFERRED | الملحق المقيّد | — | — |
| SEC-E16-10 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-11 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | INFERRED | الملحق المقيّد | — | — |
| SEC-E16-12 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-13 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-14 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-15 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-16 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-17 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E16-18 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | — | — |

## 7. أسئلة مفتوحة وعناصر UNKNOWN

| # | السؤال | ما يحسمه |
|---|---|---|
| U1 | أي بناء منشور فعلاً في Google Play و App Store (المعرّف، الإصدار، الـ host)؟ وهل نُشر بناء من `lavkooapp` أصلاً؟ | Play Console و App Store Connect |
| U2 | هل يخدم `pharmacyksa.com` و`lavkoo.com` الخلفية نفسها؟ وما قيمة `APP_URL` في الإنتاج؟ | `.env` الإنتاج وإعداد DNS/الخادم |
| U3 | أي مشروع Firebase مهيّأ للإرسال في الخلفية (`ksa-cosmetics` أم `lavkoo-380f1`)؟ | إعدادات الإشعارات في قاعدة الإنتاج ولوحة Firebase |
| U4 | القيم الحية لـ `user_app_version_control` (الحالة، الإصدار، الرابط) | رد `config` الحي أو `business_settings` |
| U5 | محتوى `currency_list` الحي: هل فيه `SYP` أو `USD`؟ وما قيم `exchange_rate`؟ وما عملة الأسعار المخزّنة فعلاً؟ | جدول `currencies` و`system_default_currency` في الإنتاج |
| U6 | القيم الحية لـ `decimal_point_settings` و`country_code` و`default_location` و`currency_model` | `business_settings` |
| U7 | هل يبدأ `go_router` فعلاً من مسار الرابط عند الإقلاع البارد، وما ترتيب البوابتين حينها (SEC-E16-11)؟ | تشغيل على جهاز (ممنوع في هذه المرحلة) |
| U8 | حالة ملفات الربط المنشورة فعلياً على الـ host الحي | جلب الملفين من الإنتاج (ممنوع في هذه المرحلة) |
| U9 | أي فروق في المستودع الخلفي القديم | `UNKNOWN (missing access: pharmacyksa98)` |
| U10 | هل يُحقن مفتاح الخرائط وقيم Facebook وقت البناء خارج المستودع؟ | خط البناء/النشر الفعلي |
| U11 | هل الاختبارات الـ 11 تنجح على HEAD؟ | تشغيل `flutter test` (NOT RUN) |
| U12 | هل يصل طلب `POST` الحامل لـ `_method` بقيمة `put` أو `delete` داخل جسم JSON إلى مسارات `Route::put`/`Route::delete` في الخلفية (تسجيل اللغة، توكن الجهاز، حذف العنوان، تحديث/حذف السلة، المقارنة)؟ الوثيقة القديمة متناقضة فيه | اختبار تشغيلي على الخلفية أو سجلات 405 في الإنتاج |
| U13 | ما الترويسات التي ترسلها النسخ المبنية قبل أول إيداع متتبَّع في `ksaapp98` (2026-10-22)؟ هل تحمل `X-App-Version` أصلاً؟ وهل ما زالت ترسل رمز الدولة في `lang`؟ | سجلات الطلبات في الإنتاج أو أرشيف البناءات المنشورة |
| U14 | هل يعمل الدخول بـ Google في بناء من `lavkooapp` مع تباين أرقام المشاريع بين `GIDClientID` وملفات Firebase؟ | تشغيل فعلي ولوحة Google Cloud / Firebase |
| U15 | هل يُجاب فحص `/api/v1/theme/version` من ذاكرة Dio طوال الجلسة فيعطّل أثر إشعار النشر؟ | تشغيل على جهاز؛ يتابعه نطاق الثيم |

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| العنوان الأساسي ثابت `https://lavkoo.com` | `lavkooapp:lib/utill/app_constants.dart:39` | VERIFIED |
| عميل Dio singleton يُبنى بالعنوان الثابت | `lavkooapp:lib/di_container.dart:206-207` | VERIFIED |
| سبع ترويسات ثابتة تُبنى في دالة واحدة، بلا دولة ولا عملة | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73` | VERIFIED |
| `updateHeader` لا يستطيع تفريغ قيمة | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:49-53` | VERIFIED |
| المعترض لا يعدّل الطلبات | `lavkooapp:lib/data/datasource/remote/dio/logging_interceptor.dart:7-10` | VERIFIED |
| مفتاح كاش Dio = المسار + المعاملات + اللغة | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-172` | VERIFIED |
| مدة الكاش من `GET /api/v1/cash` = 400000 | `lavkoo:routes/rest_api/v1/api.php:58-60` | VERIFIED |
| طلبات multipart ترسل `Authorization` فقط | `lavkooapp:lib/features/refund/domain/repositories/refund_repository.dart:21-28` | VERIFIED |
| التوكن في SharedPreferences بالمفتاح `user_login_token` | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:126-133` | VERIFIED |
| معرّف الضيف الافتراضي (قيمة ثابتة) | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:166-168` | VERIFIED |
| الخروج يحذف مفتاحين فقط | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:186-191` | VERIFIED |
| `config` يُحلَّل في `ConfigModel.fromJson` | `lavkooapp:lib/features/splash/domain/models/config_model.dart:183-429` | VERIFIED |
| `config` المحلي يُطبَّق قبل الشبكة | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:83-117` | VERIFIED |
| `config` يُبنى من إعدادات عامة في الخلفية | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:203-219` | VERIFIED |
| منطق بوابة الإصدار ومقارنة الإصدارات | `lavkooapp:lib/features/splash/screens/splash_screen.dart:325-359` | VERIFIED |
| ثابت الإصدار `16.2.1` وتعليق حدّ `14.1` | `lavkooapp:lib/utill/app_constants.dart:9-29` | VERIFIED (الكود)؛ UNKNOWN (قيمة الإنتاج) |
| سلوك البوابتين عند الإقلاع البارد برابط | انظر SEC-E16-11 | INFERRED |
| أولوية `SYP` وإعادة كتابة مفتاح العملة | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:236-281` | VERIFIED |
| ضرب معامل الصرف وصفر خانات | `lavkooapp:lib/helper/price_converter.dart:65-78` | VERIFIED (الكود)؛ UNKNOWN (قيمة المعامل في الإنتاج) |
| محوّل الويب يستعمل معامل 1 خارج وضع تعدد العملات | `lavkoo:app/Utils/currency.php:113-131` | VERIFIED |
| طبقة الذاكرة في كاش Dio بلا مدة صلاحية | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:188-192` | VERIFIED |
| التوجيه من `config` المحلي يسبق تحليل رد الشبكة | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:90-128` | VERIFIED |
| مواضع `FlutterDownloader.enqueue` بلا ترويسات (5 مواضع) | `lavkooapp:lib/features/order_details/controllers/order_details_controller.dart:376-383` | VERIFIED |
| مسارا اللغة وتوكن الجهاز `Route::put` فقط والتطبيق يرسل POST مع `_method` | `lavkoo:routes/rest_api/v1/api.php:277` و`lavkoo:routes/rest_api/v1/api.php:308` | VERIFIED (الكود)؛ UNKNOWN (الوصول الفعلي) |
| ترويسة الإصدار موجودة منذ أول إيداع في السلالة القديمة | `ksaapp98@afb8974` | VERIFIED (التاريخ المتتبَّع فقط) |
| إيداعات تجهيز النشر في السلالة القديمة | `ksaapp98@4542dff` و`ksaapp98@84f181e` | VERIFIED (وجود الإيداعات)؛ UNKNOWN (ما نُشر) |
| `GIDClientID` في iOS يتبع رقم مشروع السلالة القديمة | `lavkooapp:ios/Runner/Info.plist:5-6` | VERIFIED (المقارنة الرقمية)؛ INFERRED (الأثر) |
| جدول Drift الوحيد وأعمدته | `lavkooapp:lib/data/local/cache_response.dart:5-23` | VERIFIED |
| مفتاح `DataSyncRepo` = المسار + اللغة | `lavkooapp:lib/data/reposotories/data_sync_repo.dart:36-39` | VERIFIED |
| المسح الشامل عند تغيير اللغة فقط | `lavkooapp:lib/localization/controllers/localization_controller.dart:41-44` | VERIFIED |
| لغتان فقط، العربية أولاً | `lavkooapp:lib/utill/app_constants.dart:338-349` | VERIFIED |
| رمز اللغة `sy` يُرسل للخلفية | `lavkooapp:lib/localization/controllers/localization_controller.dart:38-39` | VERIFIED |
| الخلفية تفسّر `lang: sa` عربيةً | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29` | VERIFIED |
| نموذج العنوان نص حر | `lavkooapp:lib/features/address/domain/models/address_model.dart:1-17` | VERIFIED |
| كتالوج المناطق والمدن موجود في الخلفية وغير مستهلَك | `lavkoo:routes/rest_api/v1/api.php:70-73` | VERIFIED |
| intent-filter Android: host ومسارات | `lavkooapp:android/app/src/main/AndroidManifest.xml:122-142` | VERIFIED |
| نطاق iOS المرتبط `pharmacyksa.com` | `lavkooapp:ios/Runner/Runner.entitlements:17-20` | VERIFIED |
| القراءة المحلية للرابط بأول مقطع | `lavkooapp:lib/data/services/deep_link_resolver.dart:86-118` | VERIFIED |
| أنماط الروابط المنشورة في الخلفية | `lavkoo:config/deeplinks.php:39-45` | VERIFIED |
| الموضوعات الثلاثة الثابتة | `lavkooapp:lib/utill/app_constants.dart:324-335` | VERIFIED |
| تسجيل توكن الجهاز | `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:91-110` | VERIFIED |
| عمود توكن واحد لكل عميل في الخلفية | `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:638-660` | VERIFIED |
| Android `applicationId` الحالي والقديم | `lavkooapp:android/app/build.gradle.kts:31` و`ksaapp98:android/app/build.gradle.kts:31` | VERIFIED |
| iOS bundle id لم يتغيّر | `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414` | VERIFIED |
| مشروع Firebase الحالي والقديم | `lavkooapp:lib/firebase_options.dart:56` و`ksaapp98:lib/firebase_options.dart:56` | VERIFIED |
| حدّ الإصدار الموثّق في المستودع القديم | `ksaapp98:test/release_version_test.dart:26-27` | VERIFIED (كتوثيق)؛ UNKNOWN (القيمة الحية) |
| المعرّف الفعلي لتطبيق البائع | `lavkooappseller:android/app/build.gradle.kts:39` | VERIFIED |

## 9. سجل التحقق المستقل

> أُجري التحقق بقراءة ثابتة فقط على الـ SHA نفسها، دون تشغيل أي كود أو اختبار ودون أي اتصال بالشبكة. أُعيد فتح كل موضع مذكور أدناه في المستودعات، وأُعيد تنفيذ بحوث الغياب بأنماط مختلفة عن أنماط المؤلف.

### 9.1 الأعداد

| البند | العدد |
|---|---|
| الادعاءات المفحوصة دلالياً | 83 (كل بنود P0 و P1 السبعة عشر: E16-F01 إلى E16-F12 و SEC-E16-01/02/03/04/07، و66 ادعاءً آخر من الأقسام 2 إلى 4 والمصفوفات) |
| المؤكَّدة كما هي | 67 |
| المصحَّحة (تعديل نص أو حالة أو عدّ أو دليل) | 11 |
| المدحوضة (استُبدل الادعاء) | 2 |
| غير القابلة للتحقق بالقراءة الثابتة | 3 |
| عيوب المدقق الآلي قبل التحقق وبعده | 0 و 0 |
| مواضع أُعيدت صياغتها لأسباب نشر آمن | 3 |

### 9.2 الادعاءان المدحوضان

1. **«تغيير شكل `config` يُبقي كل نسخة مثبَّتة عالقة عند شاشة البداية».** غير صحيح للنسخ التي لديها `config` محفوظ: أثر ذلك على هذه النسخ موضّح في SEC-E16-09 أعلاه. التعليق عند البداية يخص التثبيت الجديد فقط. صُحِّح في 2.4 و4.4 و E16-F03.
2. **«كاش GET يخدم الردود حتى 400 ثانية».** المدة تخص طبقة القرص فقط؛ طبقة الذاكرة بلا مدة صلاحية طوال عمر العملية (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:188-192`). صُحِّح في 2.2 و E16-F19، ورُفعت درجته من INFO إلى P2.

### 9.3 التصحيحات

| # | الادعاء الأصلي | المشكلة | التصحيح |
|---|---|---|---|
| 1 | كل النسخ المثبَّتة ترسل `X-App-Version: 16.2.1` | تعميم على ما هو خارج التاريخ المتتبَّع؛ تعليقات الكود تدل على نسخ ميدانية أقدم بسلوك ترويسات مختلف | قُيِّد بالتاريخ المتتبَّع (`ksaapp98@afb8974`) وأضيف U13 |
| 2 | قائمة المواضع خارج `DioClient` (6 multipart، 2 `HttpClient`، `http.get`، WebView) | ناقصة: 5 مواضع `FlutterDownloader.enqueue`، WebView المدونة، و host مكتوب حرفياً في شاشة التاجر | أضيفت الصفوف في 2.2 و4.2 و E16-F04 |
| 3 | تسجيل اللغة يتم بـ `PUT` | التطبيق يرسل `POST` مع `_method: put` داخل JSON إلى مسار `Route::put` فقط؛ الوصول الفعلي غير محسوم | صُحِّح في 2.3 و2.10 وأضيف U12 |
| 4 | الفروق بين السلالتين في بقية الملفات «تنسيق فقط» | 14 ملفاً من 34 فيها فروق محتوى صغيرة (هوية ومظهر) | صُحِّح في 2.12 بإحصاء دقيق |
| 5 | E16-F01 حالته VERIFIED كلياً وأثره «اختلاف المعروض عن المحصَّل» | المعامل الفعلي يتبع بيانات الإنتاج (يصير 1 عند غياب `USD`)؛ اختلاف المبلغ استنتاج | فُصلت الحالات، وأضيف دليل تباين التطبيق مع محوّل الويب (`lavkoo:app/Utils/currency.php:113-131`)؛ بقيت الدرجة P0 بسبب صفر الخانات |
| 6 | يُستخدم `baseUrl` في 19 موضعاً يمرّ خارج العميل | ثلاثة منها في ملف يتيم غير مستعمل | وُضِّح في 2.2 |
| 7 | مسح المخازن يقع عند تغيير اللغة فقط | صحيح للمسح الشامل؛ مسح ذاكرة Dio عند سحب التحديث موصوف في 2.6، وبقية التفاصيل في SEC-E16-06 | أضيف في 2.6 |
| 8 | توكن الجهاز «للمستخدم المسجَّل فقط» | تفاصيل نطاق الاستدعاء في SEC-E16-16 | وُضِّح في 2.10 |
| 9 | 35 واجهة مستودع | العدّ يتفاوت بحسب النمط (35 إلى 37) | صيغة تقريبية |
| 10 | الـ HEAD المحلي لـ `lavkoo` يضيف ملف توثيق واحداً | لم يعد دقيقاً (إيداعات توثيق لاحقة) | صيغة عامة تحقّق منها `git diff --stat` |
| 11 | هوية التطبيق (E16-F02) | ينقصها دليلان: تاريخ إيداعات تجهيز النشر في السلالة القديمة، وتباين معرّفات الدخول بـ Google مع مشروع Firebase | أضيفا في 2.11 و E16-F02 و U14 |

ملاحظة على الربط بالقرارات: البند 4 من ضوابط الترحيل في `DECISIONS.md` يخص بوابة إصدار تطبيق **البائع**؛ بوابة إصدار تطبيق العميل (E16-F07) تُربط بالضابط 2 (الحفاظ على العملاء السعوديين الحاليين) وبتعليمات مستودع التطبيق (`lavkooapp:CLAUDE.md:23`). لا يعيد هذا الملحق فتح أي قرار معتمد؛ فقرة GCC-19 في 4.4 قيد تنفيذ مدعوم بالكود لا طلب تغيير.

### 9.4 ما تعذّر التحقق منه

1. سلوك البوابتين عند الإقلاع البارد برابط عميق موضّح في SEC-E16-11؛ يبقى INFERRED (U7).
2. وصول نداءات `POST` + `_method` داخل JSON إلى مسارات PUT/DELETE (U12).
3. القيم الحية (بوابة الإصدار، قائمة العملات، الخانات العشرية): تعليق الكود والوثيقة القديمة يذكران قراءة بتاريخ 2026-11-11، وهي رواية موثَّقة لا تحقق مباشر.

### 9.5 بحوث الغياب المعادة (كلها على `lavkooapp:lib/**/*.dart` ما لم يُذكر غير ذلك)

| الادعاء | النمط المستعمل في إعادة البحث | النتيجة |
|---|---|---|
| لا سياق متجر في أي طلب | `x-storefront\|x-store\|x-market\|x-country\|x-currency\|accept-language\|store_id\|storefront\|country_id\|zone_id\|market_code` (غير حساس للحالة)، وكل مواضع `headers` | لا ترويسة ولا معامل؛ كلمة storefront ترد في تعليقات وأسماء تخطيطات الثيم فقط |
| لا حقول كتالوج العناوين | `region_id\|city_id\|district\|national_address\|short_address\|building_number\|address/regions\|address/cities\|regionId\|cityId\|unified` | صفر |
| لا ثوابت سعودية | `\+966\|'966'\|\bSAR\b\|ريال\|ر\.س` و`saudi\|riyadh\|jeddah\|السعودية\|الرياض` | صفر (مطابقة عرضية واحدة لكلمة عربية أخرى في ميزة الصحة) |
| لا استنتاج دولة من IP أو لغة الجهاز | `geoip\|ipinfo\|ip-api\|ipapi\|localeName\|PlatformDispatcher\.instance\.locale\|window\.locale\|deviceLocale` | صفر؛ `isoCountryCode` يُستعمل لنص العنوان المعروض فقط |
| لا flavors ولا dart-define | `dart-define\|fromEnvironment\|flavor\|productFlavors\|buildConfigField\|dotenv` في `lib/` وملفات Gradle و`pubspec.yaml` | صفر |
| لا إلغاء اشتراك ولا تحديث توكن | `unsubscribeFromTopic\|deleteToken\|onTokenRefresh` | صفر |
| لا `/api/v2` ولا `/api/v3` | `/api/v[2-9]` | صفر |
| النصوص القانونية المرفقة غير مستعملة | `LawContent\|ContactContent\|privacyRights\|privacyChildren` ومفاتيح `getTranslated('privacy…'\|'terms…'\|'about…')` | صفر للمفاتيح القانونية المرفقة |
| جرد مفاتيح SharedPreferences | استخراج كل مفاتيح `get*/set*/remove/containsKey` الحرفية وثوابت `AppConstants` | يطابق جدول 2.6؛ لا مفتاح إضافي |

### 9.6 مخاوف باقية

- درجة E16-F02 (P0) مبنية على أثر مشروط بما هو منشور فعلاً؛ إن تبيّن أن `lavkooapp` لم يُنشر قط وأن السلالة القديمة هي القاعدة المثبَّتة، يصبح البند قرار مسار نشر لا خللاً قائماً.
- E16-F01: خطورته على السعودية اليوم تتوقف على `decimal_point_settings` وأسعار الصرف الحية؛ الثابت كودياً هو صفر الخانات وتجاهل `currency_model`.
- الملحق المقيّد سليم الإحالات (أُعيد فتح مواضع SEC-E16-01 إلى SEC-E16-08)، وهو يحتوي تفاصيل البنود المقيّدة دون قيم سرّية؛ يبقى غير منشور.
- الوثيقة القديمة `ksaapp98:docs/app-backend-compatibility.md` تحوي ادعاءات «تحقّق بالتشغيل» لا يمكن إعادة إنتاجها هنا، وبعضها متناقض (U12)؛ يُستحسن أن تراجعها النطاقات المعنية (المصادقة، الدفع، العناوين، الثيم) كمصدر أسئلة لا كدليل.

### 9.7 فجوات التغطية (أجزاء من النطاق لم يفحصها الملحق)

- نقاط `app-health` و`analytics/events` وما تحمله من هوية (`X-Client-Id`) وغياب بُعد المتجر عنها.
- تبديل الصفحة الرئيسية بحسب `active_theme` (مساران: الافتراضي و`theme_aster`؛ 15 موضع استعمال لـ `activeTheme`) وأثره على GCC-07 و GCC-09.
- إعدادات البناء والنشر: التوقيع، `proguard`، `MARKETING_VERSION` و`CURRENT_PROJECT_VERSION` في iOS، و`flutter_launcher_icons.yaml`، ومجلد `tool/`.
- تدفّقات WebView (الدفع، المدونة) وما يحدث لسياق المتجر واللغة داخلها.
- سلوك عدم الاتصال ومعالجة الأخطاء العامة (`ApiChecker`، `NetworkInfo`) عند ردود خاصة بمتجر موقوف (GCC-12).
- محرّك الثيم الديناميكي والـ renderers (مستثنى صراحةً في القسم 1) وفروق الـ renderers الثلاثة بين السلالتين.
- تطبيق البائع ليس ضمن هذا الملحق إلا معرّف الحزمة.
