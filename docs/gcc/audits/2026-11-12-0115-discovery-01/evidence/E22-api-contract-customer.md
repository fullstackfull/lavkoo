# E22 — عقد الـ API بين تطبيق العميل والخادم (Cross-repo API contract: customer app vs backend API v1/v2)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo@7f42777c` (الكود المدقَّق؛ الـ HEAD المحلي وقت التحقق `lavkoo@5c8d03b7` يضيف 20 commit توثيقياً تحت `docs/gcc/audits/` فقط، تحقّقت من ذلك بـ `git diff --name-only 7f42777c HEAD -- ':!docs/gcc'` وهو فارغ) — `lavkooapp@eedbbb8f`.

## 1. النطاق وما تم فحصه

**ما تم فحصه**

- جانب التطبيق: كل ثوابت النقاط في `lavkooapp:lib/utill/app_constants.dart:46-288` (164 ثابتاً قيمته تبدأ بـ `/api/`)، وعميل HTTP كاملاً `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:12-375`، وطبقة المزامنة `lavkooapp:lib/data/reposotories/data_sync_repo.dart:16-103`، ومعالج الأخطاء `lavkooapp:lib/data/datasource/remote/exception/api_error_handler.dart:8-137`، و44 ملف Dart (repositories وcontrollers وservices) تحتوي 203 مواقع استخدام للثوابت، ونماذج Dart للتدفقات الحرجة (config، المنتج، السلة، الطلب، المحفظة، الملف الشخصي، العنوان).
- جانب الخادم: `lavkoo:routes/rest_api/v1/api.php` (542 سطراً، 180 تعريف مسار)، `lavkoo:routes/rest_api/v2/api.php` (196 سطراً، 95 مساراً)، `lavkoo:app/Providers/RouteServiceProvider.php:48-66`، تعريف الـ middleware في `lavkoo:bootstrap/app.php:43-134`، `APIGuestMiddleware`، `APILocalizationMiddleware`، و17 متحكماً من أصل 37 تحت `app/Http/Controllers/RestAPI/v1` (قُرئ كاملاً: `ConfigController`، `CustomerController`، `CartController`؛ وقُرئت الدوال المعنية في البقية) و`lavkoo:app/Http/Controllers/Customer/PaymentController.php:73-201` و`lavkoo:app/Http/Controllers/Customer/PaymentController.php:318-555`.
- أدوات قراءة فقط كُتبت في مساحة العمل المؤقتة (خارج المستودعات): مستخرج ثوابت التطبيق ومواقع النداء، محلّل ساكن لملفات المسارات يحلّ `prefix`/`group`/`middleware`، ومطابِق بينهما. عدد المسارات المحلَّلة (180) يطابق عدد أسطر `Route::<verb>(` في الملف (180).
- أنماط البحث المسجّلة: `'/api/` و`"/api/` في `lavkooapp/lib`؛ `AppConstants\.\w+`؛ `['"]_method['"]`؛ `MultipartRequest`؛ `/api/v[23]`؛ `unified_address|region_id|city_id|national_address|district`؛ `x-store|x-country|x-market|x-storefront|storefront|market_code|store_code` (في المستودعين)؛ `idempotency` (في `app/Http/Controllers/RestAPI`، `app/Utils/OrderManager.php`، `app/Utils/CartManager.php`، `app/Http/Middleware`، `bootstrap`)؛ `header\('` في طبقات الخادم ذات الصلة.

**ما لم يُفحص**

- لم يُشغَّل أي كود ولم يُرسل أي طلب؛ جدول المسارات مبني ساكناً وليس من `route:list`. ترتيب تسجيل المسارات المكرّرة مُستنتج من سلوك Laravel المعروف (المجلد `vendor` غير موجود محلياً).
- لم تُقرأ كل نماذج Dart (709 ملفاً)؛ المطابقة على مستوى الحقول اقتصرت على التدفقات الحرجة المذكورة في القسم 2.6.
- تطبيق البائع وAPI v3 خارج هذا الملحق (E14/E19). مسارات v2 فُحصت فقط لإثبات أن تطبيق العميل لا يستعملها.
- قيم الإعدادات في قاعدة بيانات الإنتاج (`business_settings`) غير متاحة: كل ما يعتمد عليها موسوم `UNKNOWN`.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 تسجيل المسارات وطبقات الـ middleware

- ثلاثة ملفات API تُحمَّل تحت البادئة `api` ومجموعة `api`: v1 وv2 وv3 (`lavkoo:app/Providers/RouteServiceProvider.php:97-119`). مجموعة `api` هي: `throttle:3000,1`، `SubstituteBindings`، `RecordHttpTelemetry`، `MonitorRequest`، `RecordAnalytics`، `RecordApiResponseShape` (`lavkoo:bootstrap/app.php:84-94`). لا يوجد في المجموعة أي middleware لسياق متجر أو دولة.
- ملف v1 كله داخل مجموعة واحدة `prefix => v1` مع `api_lang` (`lavkoo:routes/rest_api/v1/api.php:54`)، و`api_lang` هو `APILocalizationMiddleware` (`lavkoo:bootstrap/app.php:121`).
- ثلاث درجات وصول في v1 (من الجدول المحلَّل، 180 مساراً): 45 مساراً خلف `auth:api`، 72 خلف `apiGuestCheck` (`lavkoo:bootstrap/app.php:130`)، 63 عامة. نداءات التطبيق المطابَقة (161 زوج نقطة/طريقة): 44 على `auth:api`، 67 على `apiGuestCheck`، 50 عامة.
- `APIGuestMiddleware`: إن وُجدت ترويسة `Authorization` ونجح `auth('api')->check()` يُدمج المستخدم في الطلب؛ وإلا يُقبل الطلب بمعرّف ضيف (المسار الثاني، ومآله في SEC-E22-01)؛ وإلا 401 بالشكل رسالة الخطأ القياسية للوسيط (`lavkoo:app/Http/Middleware/APIGuestMiddleware.php:21-33`).
- `APILocalizationMiddleware`: يقرأ الترويسة `lang` فقط، ويحوّل القيمتين `sa`→`ar` و`us`→`en` لأن إصدارات منشورة من التطبيق كانت ترسل رمز الدولة في هذه الترويسة (`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29`)، ثم يطابق الرمز مع لغات المتجر (`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:44-89`).
- حدود المعدل: `throttle:auth` على مجموعة `auth` (`lavkoo:routes/rest_api/v1/api.php:119`) بقيمة تُقرأ من الإعدادات وقت الطلب (`lavkoo:app/Providers/RouteServiceProvider.php:246-252`)؛ حدود ثابتة على `theme` و`deep-link` و`app-health` و`address` (`lavkoo:routes/rest_api/v1/api.php:70`، `lavkoo:routes/rest_api/v1/api.php:81`، `lavkoo:routes/rest_api/v1/api.php:97-98`، `lavkoo:routes/rest_api/v1/api.php:475`).
- مسار مغلق غير موثّق: `GET /api/v1/cash` يعيد العدد 400000 (`lavkoo:routes/rest_api/v1/api.php:58-60`)، ويستعمله التطبيق كمدة تخزين مؤقت بالميلي ثانية (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:107-122`).
- v2 معنون في الملف نفسه "Old Seller Mobile APP API Routes" ويحتوي مجموعتي `seller` (55 مساراً) و`delivery-man` (40 مساراً) فقط (`lavkoo:routes/rest_api/v2/api.php:20-27`، `lavkoo:routes/rest_api/v2/api.php:132`).
- وحدة AI تضيف 10 مسارات تحت `api/v1/customer/auction/product` خلف `auth:api` (`lavkoo:Modules/AI/routes/api.php:48-62`)؛ ملف `lavkoo:Modules/Blog/routes/api.php` لا يعرّف أي مسار.

### 2.2 عميل HTTP في التطبيق: مساران مختلفان للترويسات

- العنوان الأساسي ثابت واحد: `https://lavkoo.com` (`lavkooapp:lib/utill/app_constants.dart:39`)، يُحقن في `DioClient` (`lavkooapp:lib/di_container.dart:206`).
- **المسار الأول (Dio)**: كل الطلبات عبر `DioClient` تحمل الترويسات السبع: `Content-Type`، `Authorization: Bearer <token>`، `Accept`، `X-Platform`، `X-App-Version`، `X-Client-Id`، و`lang` (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73`). قيمة `lang` هي رمز اللغة من التفضيلات (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:89-92`). الحقل `countryCode` ما زال يُحفظ في العميل (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:28-29`) لكنه لا يُرسل في أي ترويسة.
- **المسار الثاني (حزمة `http` — multipart)**: ست نقاط تُرسل بـ `http.MultipartRequest` وتحمل ترويسة `Authorization` فقط، دون `lang` ولا `X-Platform` ولا `X-App-Version` ولا `X-Client-Id`: تحديث الملف الشخصي (`lavkooapp:lib/features/profile/domain/repositories/profile_repository.dart:41-43`)، إنشاء تذكرة الدعم والرد عليها (`lavkooapp:lib/features/support/domain/repositories/support_ticket_repository.dart:24-27`، `lavkooapp:lib/features/support/domain/repositories/support_ticket_repository.dart:74-79`)، إرسال/تحديث التقييم (`lavkooapp:lib/features/review/domain/repositories/review_repository.dart:34-42`)، طلب الاسترجاع (`lavkooapp:lib/features/refund/domain/repositories/refund_repository.dart:23-26`)، وإرسال رسالة المحادثة (`lavkooapp:lib/features/chat/domain/repositories/chat_repository.dart:69-75`).
- **مسار ثالث (متصفح/WebView)**: رابط الدفع `redirect_link` يُفتح في WebView، وتُحدَّد نتيجته من عنوان الصفحة المعادة عبر قواعد مطابقة نصية مرتبطة بنطاق التطبيق (`lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:131-134`، `lavkooapp:lib/features/wallet/screens/add_fund_to_wallet_screen.dart:166-170`؛ التفاصيل في SEC-E22-10)؛ وتنزيل المنتج الرقمي يُفتح برابط مباشر (`lavkooapp:lib/features/order_details/controllers/order_details_controller.dart:271`). هذه الطلبات لا تحمل أي ترويسة من التطبيق.
- ترويسات التفاوض على القالب تُضاف للطلبين `theme/version` و`theme/home` فقط: `X-UI-Components`، `X-UI-Engine`، `X-UI-Schema` (`lavkooapp:lib/features/dynamic_theme/engine/capability_registry.dart:89-93`، `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:66-69`).
- يقرأ الخادم أيضاً ترويسة `X-UI-Channel` ومعاملات الاستعلام البديلة `components` و`ui_engine` و`ui_schema` و`channel`، ولا يرسل التطبيق الحالي `X-UI-Channel` (`lavkoo:app/Services/Theme/ViewerContext.php:71-91`).
- ترويسة `Idempotency-Key` تُضاف لطلبات إنشاء الطلب (COD، المحفظة، الدفع اليدوي) (`lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:66-69`).
- ما يقرؤه الخادم من الترويسات فعلاً: `Authorization`، `lang`، ترويسات `X-UI-*` (`lavkoo:app/Services/Theme/ViewerContext.php:76-91`)، `X-App-Version` للتحليلات (`lavkoo:app/Services/Analytics/VisitorContext.php:459`)، `X-Platform` للمراقبة (`lavkoo:app/Http/Middleware/MonitorRequest.php:102`)، و`X-Client-Id` (`lavkoo:app/Services/Telemetry/ClientIdentity.php:41`). تُقرأ ترويسة `X-Locale` في متحكم الدفع MyFatoorah وهي ترويسة لغة لا متجر (`lavkoo:app/Http/Controllers/Payment_Methods/MyFatoorahController.php:66`)، ولا توجد أي قراءة لترويسة متجر/دولة (بحث `x-store|x-country|x-market|x-storefront|X-Region` في `app`، `routes`، `bootstrap`، `config`، `Modules`: صفر نتائج).

### 2.3 محاكاة طرق HTTP (`_method`)

التطبيق لا يرسل `PUT` ولا `DELETE` فعلياً إلا في موضع واحد (`removeWishListUri` بـ `dio.delete`، `lavkooapp:lib/features/wishlist/domain/repositories/wishlist_repository.dart:53`). البقية `POST` مع الحقل `_method` في الجسم (11 موضع نداء في 6 ملفات repository): `cm-firebase-token` و`language-change` و`reset-password` (`lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:97-105`، `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:138-144`، `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:284-290`)، `cart/update` و`cart/remove` (`lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:134-140`، `lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:151-156`)، حذف العنوان (`lavkooapp:lib/features/address/domain/repositories/address_repository.dart:78-80`)، `compare/clear-all` (`lavkooapp:lib/features/compare/domain/repositories/compare_repository.dart:35-37`)، حذف صورة تقييم وتحديث تقييم (`lavkooapp:lib/features/review/domain/repositories/review_repository.dart:95-97`، `lavkooapp:lib/features/review/domain/repositories/review_repository.dart:53-61`)، وتحديث الملف الشخصي (`lavkooapp:lib/features/profile/domain/repositories/profile_repository.dart:50-67`). هذا يعتمد على تفعيل Laravel لآلية تغيير الطريقة عبر حقل `_method` (`INFERRED`: سلوك الإطار؛ لم يُقرأ `vendor`). أي بوابة أو WAF أمام الخادم يجب أن تمرّر `POST` على هذه المسارات.

### 2.4 نتيجة المطابقة: التطبيق ↔ مسارات v1

| البند | النتيجة | الحالة |
|---|---|---|
| ثوابت النقاط في التطبيق | 164 ثابتاً، كلها `/api/v1/…` | VERIFIED |
| ثوابت غير مستعملة | 5: `themeSectionsUri`، `shippingUri`، `getSelectedShippingTypeUri`، `distanceMatrixUri`، `chatWithDeliveryMan` (`lavkooapp:lib/utill/app_constants.dart:60`، `lavkooapp:lib/utill/app_constants.dart:139`، `lavkooapp:lib/utill/app_constants.dart:170`، `lavkooapp:lib/utill/app_constants.dart:231-232`) | VERIFIED |
| نقاط حرفية خارج الثوابت | واحدة: `/api/v1/cash` (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:111`) + قائمة مسارات التخزين المؤقت (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-140`) | VERIFIED |
| (أ) نقاط في التطبيق بلا مسار في الخادم | **صفر** (161 زوج نقطة/طريقة مستعملاً، كلها وُجد لها مسار) | VERIFIED |
| (ب) عدم تطابق طريقة HTTP | **صفر** بعد احتساب `_method` (القسم 2.3) | VERIFIED |
| (ج) مسارات v1 لا يناديها التطبيق بثابت | 24 من 180 (الجدول أدناه) | VERIFIED |
| (د) إصدار الـ API لكل ميزة | كل الميزات على v1؛ لا توجد أي إشارة إلى `/api/v2` أو `/api/v3` في `lavkooapp/lib` (صفر نتائج) | VERIFIED |

**مسارات v1 غير المستهلكة بثابت في التطبيق (24) حسب المنطقة**

| المنطقة | العدد | المسارات | ملاحظة |
|---|---|---|---|
| `address` | 2 | `address/regions`، `address/cities` (`lavkoo:routes/rest_api/v1/api.php:70-73`) | العنوان الموحّد غير مستهلك في التطبيق إطلاقاً (E22-F04) |
| `auth` | 4 | `social-login`، `update-phone` (`lavkoo:routes/rest_api/v1/api.php:157-158`) + تسجيلان مكرّران (`lavkoo:routes/rest_api/v1/api.php:135-136` مقابل `lavkoo:routes/rest_api/v1/api.php:160-161`) | المسار نفسه بالطريقة نفسها معرَّف مرتين بمتحكمين مختلفين؛ المنفَّذ فعلياً على الأرجح هو الأول تسجيلاً (`CustomerAPIAuthController`) — `INFERRED` من سلوك Laravel |
| `cart` | 2 | `remove-all`، `fulfillment-notice` (`lavkoo:routes/rest_api/v1/api.php:181`، `lavkoo:routes/rest_api/v1/api.php:188`) | إشعار الشحنات المتعددة غير معروض في التطبيق |
| `customer` | 2 | `order/fulfillments`، `order/deliveryman-reviews/submit` (`lavkoo:routes/rest_api/v1/api.php:196`، `lavkoo:routes/rest_api/v1/api.php:402`) | |
| `products` | 5 | `rating/{id}`، `shipping-methods`، `search`، `by-ids`، `theme-collection` (`lavkoo:routes/rest_api/v1/api.php:234`، `lavkoo:routes/rest_api/v1/api.php:236`، `lavkoo:routes/rest_api/v1/api.php:252`، `lavkoo:routes/rest_api/v1/api.php:269`، `lavkoo:routes/rest_api/v1/api.php:272`) | `by-ids` و`theme-collection` تُنادى عبر عنوان يختاره الخادم داخل حمولة القالب (`source.endpoint`) لا عبر ثابت (`lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:139-141`) |
| `shipping-method` | 2 | `detail/{id}`، `check-shipping-type` (`lavkoo:routes/rest_api/v1/api.php:167`، `lavkoo:routes/rest_api/v1/api.php:171`) | |
| `banners` | 2 | `category-sections`، `home-promos` (`lavkoo:routes/rest_api/v1/api.php:492-493`) | |
| أخرى | 5 | `deep-link/config`، `brands/page-header/{id}`، `theme/sections`، `mapapi/distance-api`، `faq` (`lavkoo:routes/rest_api/v1/api.php:83`، `lavkoo:routes/rest_api/v1/api.php:301`، `lavkoo:routes/rest_api/v1/api.php:477`، `lavkoo:routes/rest_api/v1/api.php:522`، `lavkoo:routes/rest_api/v1/api.php:529`) | |

تنبيه منهجي: "غير مستهلك بثابت" لا يعني غير مستهلك مطلقاً؛ قسم القالب الديناميكي ينادي عناوين يرسلها الخادم، فأي مسار كتالوج قد يُستدعى بهذه الطريقة.

### 2.5 بيان وجود manifest مولَّد

- `lavkoo:routes.json` (58 عنصراً) و`lavkoo:public/routes.json` (266 عنصراً) فهارس بحث لمسارات لوحة الإدارة فقط (مفاتيح `uri`/`method`/`view`)؛ لا يحتويان أي مسار `api/` (تحقّق آلي: صفر عناصر تحتوي `api/`). كلاهما من الـ commit الأول ولم يتغيّرا.
- يوجد أمران: `api:manifest` (`lavkoo:app/Console/Commands/ApiManifestCommand.php:17-19`) و`api:snapshot` (`lavkoo:app/Console/Commands/ApiSnapshotCommand.php:16-21`). الـ manifest يُبنى وقت التشغيل من جدول المسارات ويُخزَّن في الكاش بمفتاح واحد `developer_portal:manifest` لمدة 86400 ثانية (`lavkoo:app/Services/DeveloperPortal/ApiManifest.php:32-33`)، واللقطات تُحفظ في جدولي `api_snapshots` و`api_changes` (`lavkoo:app/Services/DeveloperPortal/ApiSnapshotService.php:43`، `lavkoo:app/Services/DeveloperPortal/ApiSnapshotService.php:97`). لا يوجد ملف manifest أو OpenAPI أو Postman متتبَّع في git (بحث `git ls-files` عن `manifest|api-snapshot|openapi|swagger|postman`: الناتج ملفات المولِّدات وقوالب العرض و`public/mix-manifest.json` فقط). وجود لقطات فعلية في قاعدة الإنتاج `UNKNOWN`.
- الخادم لا يستعمل Resource classes للـ API: التعليق في `lavkoo:bootstrap/app.php:90-93` يصرّح بذلك، ولذلك يُستنتج شكل الاستجابة من الاستجابات الفعلية عبر `RecordApiResponseShape`. يوجد 4 ملفات Resource في `app/Http/Resources` (ثلاثة على المستوى الأعلى وملف `RestAPI/Product.php`) ولا يستعملها أي من المتحكمات المفحوصة هنا.

### 2.6 التدفقات الحرجة: ما يرسله التطبيق وما يقبله/يعيده الخادم

الرموز: G = خلف `apiGuestCheck`، A = خلف `auth:api`، P = عام.

| التدفق | نداء التطبيق | مسار الخادم | ما يُرسل | ما يُعاد ويُحلَّل | ملاحظات العقد |
|---|---|---|---|---|---|
| config | `GET config` (`lavkooapp:lib/features/splash/domain/repositories/splash_repository.dart:16`) | P — `lavkoo:routes/rest_api/v1/api.php:66` | لا معاملات | كائن واحد مسطّح من 117 مفتاحاً علوياً (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:133-291`) | لا يقبل أي سياق؛ انظر القسم 3 |
| guest id | `GET get-guest-id` (`lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:414`) | P — `lavkoo:routes/rest_api/v1/api.php:530` | — | `{guest_id}` | SEC-E22-01 |
| login | `POST auth/login` بـ `email_or_phone`، `password`، `type` (`lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:46-49`) | P+throttle — `lavkoo:routes/rest_api/v1/api.php:126` | يطابق قواعد التحقق (`lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:107-111`) | `{token,status:true}` أو `{temporary_token,status:false,…}` (`lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:148-155`، `lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:173`) | الرمز Passport personal access token بلا نطاق متجر (`lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:164`) — يوافق GCC-13 |
| OTP هاتف | `POST auth/check-phone` بـ `phone` (+`temporary_token`) (`lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:236-237`) | P+throttle — `lavkoo:routes/rest_api/v1/api.php:128` | `phone` بطول 6–20 (`lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:220-222`) | `{message,token}` بقيمة نصية للحالة، أو 401 عند فشل الإرسال (`lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:286-296`) | 401 هنا يفعّل مسح الجلسة في التطبيق (E22-F08)؛ SEC-E22-05 |
| reset password | `POST`+`_method=put` بـ `identity`، `otp`، `password`، `confirm_password` (`lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:284-290`) | P+throttle — `lavkoo:routes/rest_api/v1/api.php:154` | يطابق (`lavkoo:app/Http/Controllers/RestAPI/v1/auth/ForgotPasswordController.php:199-203`) | — | SEC-E22-06 |
| social login | `POST auth/social-customer-login` بـ `token`، `unique_id`، `medium`، `email`، `name` (`lavkooapp:lib/features/auth/domain/models/social_login_model.dart:19-27`) | P+throttle — `lavkoo:routes/rest_api/v1/api.php:159` | — | `{token,status}` أو `{temp_token,status:false}` | — |
| logout | `GET auth/logout` (`lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:84`) | A — `lavkoo:routes/rest_api/v1/api.php:121` | — | يلغي الرمز ويمسح `cm_firebase_token` (`lavkoo:app/Http/Controllers/RestAPI/v1/auth/PassportAuthController.php:204-218`) | عملية تغيّر الحالة بطريقة GET (SEC-E22-09) |
| FCM token | `POST`+`_method=put` بـ `cm_firebase_token`، `guest_id` (`lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:97-105`) | G — `lavkoo:routes/rest_api/v1/api.php:308` | — | يُحفظ رمز واحد لكل مستخدم/ضيف (`lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:638-661`) | لا يُسجَّل معه متجر ولا منصة |
| cart list | `GET cart?guest_id=…[&coupon_code=…]` (`lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:25-33`) | G — `lavkoo:routes/rest_api/v1/api.php:177` | — | مصفوفة صفوف `Cart` مع `applied_tax`، `shipping_cost_tax`، `free_delivery_order_amount`، `discount` (`lavkoo:app/Http/Controllers/RestAPI/v1/CartController.php:91-146`) | السلة تُحدَّد بـ `customer_id`+`is_guest` فقط (`lavkoo:app/Http/Controllers/RestAPI/v1/CartController.php:70-75`)؛ مبالغ بلا عملة |
| cart add | `POST cart/add` بـ `id`، `guest_id`، `variant`، `quantity`، `buy_now`، `shipping_method_exist`، `shipping_method_id` + خيارات (`lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:55-74`) | G — `lavkoo:routes/rest_api/v1/api.php:178` | يُشترط `id` و`quantity` (`lavkoo:app/Http/Controllers/RestAPI/v1/CartController.php:151-156`) | ناتج `CartManager::add_to_cart` | خطأ التحقق يعود بحالة 200 (`lavkoo:app/Http/Controllers/RestAPI/v1/CartController.php:158-160`) — E22-F07 |
| cart update/remove | `POST`+`_method` بـ `key`، `quantity`، `guest_id` (`lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:134-156`) | G — `lavkoo:routes/rest_api/v1/api.php:179-180` | — | — | — |
| shipping methods | `GET shipping-method/by-seller/{id}/{type}?guest_id` (`lavkooapp:lib/features/shipping/domain/repositories/shipping_repository.dart:19-20`) | G — `lavkoo:routes/rest_api/v1/api.php:168` | — | ناتج `Helpers::getShippingMethods` (`lavkoo:app/Http/Controllers/RestAPI/v1/ShippingMethodController.php:29-33`) | `cost` رقم بلا عملة (`lavkooapp:lib/features/shipping/domain/models/shipping_method_model.dart:25-29`) |
| choose shipping | `POST choose-for-order` بـ `id`، `guest_id`، `cart_group_id` (`lavkooapp:lib/features/shipping/domain/repositories/shipping_repository.dart:33-38`) | G — `lavkoo:routes/rest_api/v1/api.php:169` | — | نص مترجم | SEC-E22-08 |
| coupon apply | `GET coupon/apply?code=` (`lavkooapp:lib/features/coupon/domain/repositories/coupon_repository.dart:14`) | A — `lavkoo:routes/rest_api/v1/api.php:505-508` | — | `{coupon_discount,coupon_type}` أو رسالة بحالة 202 (`lavkoo:app/Http/Controllers/RestAPI/v1/CouponController.php:115-121`) | الضيف لا يستطيع تطبيق كوبون عبر الـ API؛ مبلغ بلا عملة |
| order place (COD) | `GET customer/order/place` بمعاملات استعلام: `address_id`، `coupon_code`، `coupon_discount`، `billing_address_id`، `order_note`، `guest_id`، `is_guest`، `is_check_create_account`، `bring_change_amount`، `current_currency_code` وحقول إنشاء الحساب (`lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:33-53`) | G — `lavkoo:routes/rest_api/v1/api.php:324` | الخادم يعيد حساب المبالغ من السلة ولا يستعمل `coupon_discount` المرسل في COD (`lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:251-268`) | `{order_ids:[…],new_user}` (`lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:279-282`) | `order_ids` جمع: طلب لكل بائع؛ عملية تغيّر الحالة بطريقة GET (SEC-E22-09)؛ SEC-E22-04؛ `current_currency_code` يُستعمل فقط لعملة "مبلغ الفكّة" (`lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:244-249`، `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:264-265`) |
| order by wallet | `GET customer/order/place-by-wallet?…` (`lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:135-138`) | A — `lavkoo:routes/rest_api/v1/api.php:390` | — | — | المقارنة مع `users.wallet_balance` مباشرة (`lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:437-445`) — محفظة واحدة عالمية |
| order offline | `POST place-by-offline-payment` (`lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:94-115`) | G — `lavkoo:routes/rest_api/v1/api.php:326` | `method_informations` بترميز base64 لـ JSON | `{order_ids…}` | — |
| payment start | `POST digital-payment` بـ `payment_method`، `payment_platform:app`، `payment_request_from:app`، `customer_id`، `address_id`، … (`lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:171-188`) | G — `lavkoo:routes/rest_api/v1/api.php:444-446` | الخادم يتجاهل `customer_id` المرسل ويشتقه من المصادقة (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:117-121`) | `{redirect_link,new_user}` (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:193-197`) | العملة: إن كان `currency_model` غير `multi_currency` فالعملة الافتراضية للنظام (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-423`)؛ التطبيق لا يرسل `current_currency_code` في هذا الطلب |
| MyFatoorah status | `GET payment/myfatoorah/status?reference=` (`lavkooapp:lib/features/checkout/domain/services/myfatoorah_payment_service.dart:72-81`) | P+throttle — `lavkoo:routes/rest_api/v1/api.php:540-542` | — | يحتوي `amount` **و**`currency` معاً (`lavkoo:app/Http/Controllers/RestAPI/v1/MyFatoorahStatusController.php:142-149`) | النقطة الوحيدة المفحوصة التي تقرن المبلغ برمز العملة |
| order list | `GET customer/order/list?limit&offset&status[&type]` (`lavkooapp:lib/features/order/domain/repositories/order_repository.dart:19-20`) | A — `lavkoo:routes/rest_api/v1/api.php:398` | — | `{total_size,limit,offset,orders}` (`lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:486-491`) | الفلترة بـ `customer_id` فقط (`lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:467`)؛ المحلّل يستدعي `.toDouble()` على `order_amount`، `discount_amount`، `shipping_cost`، `extra_discount` دون فحص null (`lavkooapp:lib/features/order/domain/models/order_model.dart:158`، `lavkooapp:lib/features/order/domain/models/order_model.dart:168`، `lavkooapp:lib/features/order/domain/models/order_model.dart:173`، `lavkooapp:lib/features/order/domain/models/order_model.dart:200`) |
| order track / cancel | `GET order/track?order_id&guest_id`، `GET order/cancel-order?order_id` (`lavkooapp:lib/features/order/domain/repositories/order_repository.dart:32-33`، `lavkooapp:lib/features/order/domain/repositories/order_repository.dart:43-44`) | P / A — `lavkoo:routes/rest_api/v1/api.php:454-456` | — | الطلب كاملاً + `fulfillments` اختيارياً (`lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:108-113`) | الإلغاء مسموح فقط لـ COD في حالة `pending` (`lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:159-168`) |
| wallet list | `GET customer/wallet/list` بـ `limit`، `offset`، … (`lavkooapp:lib/features/wallet/domain/repositories/wallet_repository.dart:27-40`) | A — `lavkoo:routes/rest_api/v1/api.php:420` | — | `total_wallet_balance` من `users.wallet_balance` + `wallet_transaction_list` (`lavkoo:app/Http/Controllers/RestAPI/v1/UserWalletController.php:31`، `lavkoo:app/Http/Controllers/RestAPI/v1/UserWalletController.php:69-79`) | رصيد واحد بلا عملة ولا متجر؛ المحلّل يشترط `credit`/`debit`/`admin_bonus`/`balance` (`lavkooapp:lib/features/wallet/domain/models/wallet_transaction_model.dart:122-125`) |
| add fund | `POST add-to-fund` بـ `amount`، `payment_method`، `payment_platform`، `payment_request_from`، `current_currency_code` (`lavkooapp:lib/features/wallet/domain/repositories/wallet_repository.dart:62-71`) | A — `lavkoo:routes/rest_api/v1/api.php:448-450` | — | `{redirect_link}`؛ عند خروج المبلغ عن الحدود `{minimum_amount,maximum_amount}` بحالة 202 (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:498-504`) | الحدّان من إعداد عالمي واحد (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:495-496`) |
| loyalty | `GET customer/loyalty/list`، `POST loyalty-exchange-currency` بـ `point` (`lavkooapp:lib/features/loyaltyPoint/domain/repositories/loyalty_point_repository.dart:35-49`) | A — `lavkoo:routes/rest_api/v1/api.php:428-429` | — | `total_loyalty_point` من `users.loyalty_point` (`lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:60`) | التحويل يكتب مباشرة في المحفظة العالمية (`lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:99-100`) |
| addresses | `GET address/list`، `POST address/add`، `POST address/update`، `POST`+`_method=delete` (`lavkooapp:lib/features/address/domain/repositories/address_repository.dart:67-104`) | G — `lavkoo:routes/rest_api/v1/api.php:315-318` | التطبيق يرسل 16 حقلاً قديماً فقط، منها `country` نصاً حراً و`state` (`lavkooapp:lib/features/address/domain/models/address_model.dart:55-74`) | القائمة تُثرى بحقول العنوان الموحّد عند تفعيله (`lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:286-291`، `lavkoo:app/Services/Address/AddressSnapshot.php:26-45`) | E22-F04 |
| product detail | `GET products/details/{slug}?guest_id=<value>` (`lavkooapp:lib/features/product_details/domain/repositories/product_details_repository.dart:17`) | G — `lavkoo:routes/rest_api/v1/api.php:255` | — | نموذج `Product` كاملاً + علاقات (`lavkoo:app/Http/Controllers/RestAPI/v1/ProductController.php:346-387`) | الأسعار (`unit_price`، `discount`، `variation[].price`) أرقام بلا عملة (`lavkoo:app/Utils/Helpers.php:156-165`)؛ SEC-E22-07 |
| search | `POST products/filter` (`lavkooapp:lib/features/search_product/domain/repositories/search_product_repository.dart:30`) | G — `lavkoo:routes/rest_api/v1/api.php:253` | `search` بترميز base64 (`lavkoo:app/Http/Controllers/RestAPI/v1/ProductController.php:135`) | `{total_size,limit,offset,min_price,max_price,products}` (`lavkooapp:lib/features/product/domain/models/product_model.dart:32-44`) | `min_price`/`max_price` بلا عملة |
| categories / brands | `GET categories`، `GET brands?guest_id=<value>` (`lavkooapp:lib/features/category/domain/repositories/category_repo.dart:22`، `lavkooapp:lib/features/brand/domain/repositories/brand_repository.dart:18`) | G — `lavkoo:routes/rest_api/v1/api.php:291`، `lavkoo:routes/rest_api/v1/api.php:300` | — | — | النداء الفعّال يمرّ عبر `getCategoryList` مع معرّف ضيف ثابت (SEC-E22-01) (`lavkooapp:lib/features/category/domain/repositories/category_repo.dart:61-65`)؛ يوجد موضع ثانٍ بلا `guest_id` غير مستدعى عبر الخدمة — E22-F09 |
| theme | `GET theme/version`، `GET theme/home?page&device` + `If-None-Match` (`lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:66-69`، `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:95-102`) | P+throttle — `lavkoo:routes/rest_api/v1/api.php:475-486` | ترويسات `X-UI-*` | 304 / 404 / حمولة مع `checksum` | `Vary` لا يتضمن أي بُعد دولة (`lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:210`)؛ `version()` بلا أي معامل (`lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:236-239`) |
| notifications | `GET notifications?limit&guest_id=<value>&offset` (`lavkooapp:lib/features/notification/domain/repositories/notification_repository.dart:16`) | P — `lavkoo:routes/rest_api/v1/api.php:201` | — | كل الإشعارات النشطة حيث `sent_to = customer` (`lavkoo:app/Http/Controllers/RestAPI/v1/NotificationController.php:15-25`) | قائمة واحدة لكل العملاء، بلا تصفية بالمستخدم ولا بالسوق |
| profile | `GET customer/info`؛ multipart `POST`+`_method=put` (`lavkooapp:lib/features/profile/domain/repositories/profile_repository.dart:20`، `lavkooapp:lib/features/profile/domain/repositories/profile_repository.dart:41-67`) | A — `lavkoo:routes/rest_api/v1/api.php:344-346` | — | نموذج `User` كاملاً بما فيه `wallet_balance` و`loyalty_point` (`lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:59-86`، `lavkooapp:lib/features/profile/domain/models/profile_model.dart:57-61`) | الرصيدان جزء من عقد الملف الشخصي |
| delete account | `GET customer/account-delete/{id}` (`lavkooapp:lib/features/profile/domain/repositories/profile_repository.dart:30-31`) | A — `lavkoo:routes/rest_api/v1/api.php:346` | — | يُرفض إن وُجدت طلبات جارية (`lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:118-141`) | الفحص على كل طلبات العميل بلا تمييز متجر؛ ويُضاف فحص مزاد نشط عند تفعيل وحدة المزاد (`lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:128-133`) |
| chat / support | `customer/chat/*`، `customer/support-ticket/*` (`lavkooapp:lib/features/chat/domain/repositories/chat_repository.dart:25-72`، `lavkooapp:lib/features/support/domain/repositories/support_ticket_repository.dart:24-103`) | A — `lavkoo:routes/rest_api/v1/api.php:354-362`، `lavkoo:routes/rest_api/v1/api.php:407-415` | الإرسال multipart بلا `lang` | — | خطأ إنشاء التذكرة يعيد `errors` ككائن لا كقائمة (`lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:108-113`) — E22-F07 |

### 2.7 شكل الخطأ الذي يفهمه التطبيق

- المعالج المركزي يتفرّع على رمز الحالة (`lavkooapp:lib/data/datasource/remote/exception/api_error_handler.dart:54-116`): في 403 و400 و401 يقرأ `errors[0].message` وإلا `message`؛ **في 401 يستدعي `clearSharedData()` دائماً** (`lavkooapp:lib/data/datasource/remote/exception/api_error_handler.dart:70-80`) وهي تحذف رمز الدخول و`guestId` (`lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:186-191`)؛ 404 بلا رسالة؛ 503 يقرأ `message`؛ وبقية الرموز (مثل 422 و409) تمرّ على `ErrorResponse.fromJson`.
- `ErrorResponse.fromJson` يفترض أن `errors` **قائمة** من `{code,message}` (`lavkooapp:lib/data/model/error_response.dart:6-13`). الصيغة القياسية في الخادم تنتجها `Helpers::validationErrorProcessor` (`lavkoo:app/Utils/Helpers.php:313-320`) وتُعاد غالباً بحالة 403.
- الخادم غير متّسق: (1) `errors` ككائن مفرد في 4 مواضع ضمن ما فُحص (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:137`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:145`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:175`، `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:108-113`)؛ (2) أخطاء تحقق بحالة 200 لغياب رمز الحالة في السلة والشحن والمحفظة والولاء (`lavkoo:app/Http/Controllers/RestAPI/v1/CartController.php:158-160`، `lavkoo:app/Http/Controllers/RestAPI/v1/ShippingMethodController.php:44-46`، `lavkoo:app/Http/Controllers/RestAPI/v1/UserWalletController.php:23-25`، `lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:24-26`)؛ (3) استعمال 202 لنتيجة سلبية (كوبون مرفوض، حدود شحن المحفظة). استدعاء `forEach` بدالة ذات معامل واحد على كائن `Map` يرمي استثناءً في Dart (`INFERRED`: دلالات اللغة، لم يُشغَّل)، فالمواضع من النوع (1) تنتج استثناءً غير معالَج بدل رسالة.

### 2.8 التخزين المؤقت في التطبيق كجزء من العقد

- كاش Dio: 15 بادئة مسار تُخزَّن في الذاكرة و`SharedPreferences` (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-140`)، بمفتاح `المسار?المعاملات#اللغة` (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-172`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:195-197`). التفريغ الكامل موجود ويُستعمل عند تبديل اللغة (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:366-374`).
- كاش Drift عبر `fetchData`: المفتاح `uri#lang` (`lavkooapp:lib/data/reposotories/data_sync_repo.dart:36-39`)، ومفتاح القالب `'/api/v1/theme/home::<page>'` (`lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:31`)، وconfig بمفتاح المسار نفسه (`lavkooapp:lib/features/splash/controllers/splash_controller.dart:84`).
- لا يدخل في أي مفتاح أي بُعد متجر/دولة/عملة.

## 3. افتراضات المتجر الواحد / الدولة / العملة

1. **عنوان أساسي واحد ومسارات ثابتة مجمَّعة في التطبيق**: `baseUrl` و164 مساراً `/api/v1/…` ثوابت وقت الترجمة (`lavkooapp:lib/utill/app_constants.dart:39`، `lavkooapp:lib/utill/app_constants.dart:46-288`). الإصدارات المنشورة ستستمر في نداء `/api/v1/…` بلا بادئة دولة مهما تغيّر الخادم.
2. **`/api/v1/config` وثيقة متجر واحد**: عملة افتراضية واحدة بمعرّف رقمي (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:158`)، `currency_list` و`currency_symbol_position` و`decimal_point_settings` عالمية (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:203-204`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:219`)، `country_code` واحد (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:212`)، هوية شركة واحدة (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:163-168`)، قائمة طرق دفع واحدة (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:227`)، إعدادات محفظة وولاء وحدود شحن رصيد واحدة (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:223-226`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:239-241`)، حدود التوصيل المجاني والحد الأدنى للطلب (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:244-250`)، منطقة زمنية وموقع افتراضي واحد (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:253`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:256`)، وضع صيانة واحد (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:106-111`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:258`)، إعداد ضريبة واحد (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:270-271`)، بوابة إصدار واحدة `user_app_version_control` (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:236`)، ومتجر داخلي واحد `in_house_shop` (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:266`). مفاتيح السياسات القانونية تُعاد `null` ثابتة (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:194-201`) والتطبيق يجلبها من `business-pages` (`lavkooapp:lib/features/splash/domain/repositories/splash_repository.dart:27`).
3. **كل المبالغ أرقام مجرّدة**: لا يوجد حقل عملة في استجابات المنتج والسلة والشحن والكوبون والطلب والمحفظة والاسترجاع. الحقل الوحيد على `Order` هو `bring_change_amount_currency` (`lavkoo:app/Models/Order.php:95`؛ بحث `currency` في `Order.php`، `WalletTransaction.php`، `Cart.php`، `OrderDetail.php`، `Coupon.php`، `ShippingMethod.php`، `RefundRequest.php`: لا نتائج أخرى). التطبيق يحوّل كل مبلغ بنفسه بضربه في سعر صرف عملته المختارة مقسوماً على سعر صرف USD من `currency_list`، مع تثبيت `singleCurrency = false` (`lavkooapp:lib/helper/price_converter.dart:65`، `lavkooapp:lib/helper/price_converter.dart:69-73`).
4. **صفر منازل عشرية في عرض السعر**: `convertPrice` يستعمل `toStringAsFixed(0)` ثابتاً ويتجاهل `decimal_point_settings` القادم من الخادم (`lavkooapp:lib/helper/price_converter.dart:75-78`) رغم أن النموذج يقرؤه (`lavkooapp:lib/features/splash/domain/models/config_model.dart:286-288`). هذا يتعارض مباشرة مع عملات KWD/BHD/OMR ذات الثلاث منازل.
5. **قيمة احتياطية لعملة غير خليجية**: عند غياب رمز العملة يُحفظ `'SYP'` (`lavkooapp:lib/features/splash/controllers/splash_controller.dart:280`).
6. **`lang` تحمل تاريخياً رمز دولة**: الخادم يحوّل `sa`→`ar` (`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:27`)، ومفتاح التفضيلات `country_code` في التطبيق يعني دولة اللغة (SA/US) لا دولة المتجر (`lavkooapp:lib/utill/app_constants.dart:331`، `lavkooapp:lib/utill/app_constants.dart:338-349`، `lavkooapp:lib/localization/controllers/localization_controller.dart:81`). أي سياق دولة جديد يجب ألا يعيد استعمال `lang` ولا المفتاح `country_code`.
7. **هوية العميل ومحفظته وولاؤه في كائن واحد**: `customer/info` يعيد `wallet_balance` و`loyalty_point` من صف `users` (`lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:59-86`).
8. **سلة واحدة لكل عميل** محدَّدة بـ `customer_id`+`is_guest` (`lavkoo:app/Http/Controllers/RestAPI/v1/CartController.php:70-75`)، وتُقسَّم إلى طلبات حسب البائع (`order_ids`).
9. **موضوعات FCM عالمية**: `sixvalley`، `maintenance_mode_start_user_app`، `theme_updates_user_app` (`lavkooapp:lib/utill/app_constants.dart:324`، `lavkooapp:lib/utill/app_constants.dart:329`، `lavkooapp:lib/utill/app_constants.dart:334`، `lavkooapp:lib/features/splash/controllers/splash_controller.dart:145-147`).
10. **العنوان**: الدولة نص حر يُطابَق مع قائمة `COUNTRIES` و`delivery_country_codes` (`lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:663-681`)؛ العنوان الموحّد سعودي فقط ويعامل قناة الـ API كعميل قديم في وضع `soft` (`lavkoo:app/Enums/Address/AddressChannel.php:16-19`، `lavkoo:app/Services/ShippingAddressService.php:265-268`).

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه العقد اليوم | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Foundation: one domain with `/sa /ae …` paths | العقد على نطاق واحد؛ اكتشاف نتيجة الدفع في التطبيق يعتمد `contains` فيتحمّل بادئة مسار على الويب | مسارات الـ API ثابتة في التطبيقات المنشورة؛ بادئة مسار للـ API لا تصل للإصدارات القديمة؛ روابط config (`static_urls`، `referral_customer_signup_url`، `blog_page`) تُبنى من مسارات الويب | `lavkooapp:lib/utill/app_constants.dart:39`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:188-193`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:252`؛ `lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:131-134` |
| GCC-19 Saudi default storefront | غياب السياق في كل طلب يجعل "السعودية افتراضياً" هو التفسير الوحيد المتوافق للإصدارات المنشورة | لا يوجد أي حامل سياق حالياً | بحث الترويسات (القسم 2.2) |
| GCC-13 one customer identity, separate carts/orders/wallets | رمز Passport بلا نطاق متجر صالح لهوية واحدة | السلة والطلبات والمحفظة والولاء تُعاد بلا بُعد متجر؛ `customer/info` يدمج الرصيدين في الهوية | `lavkoo:app/Http/Controllers/RestAPI/v1/CartController.php:70-75`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:467`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/UserWalletController.php:31` |
| GCC-15 country-local loyalty | — | `total_loyalty_point` رقم واحد؛ التحويل إلى المحفظة بلا سياق | `lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:60`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:99-100` |
| Provisional: local pricing, no auto-FX | — | التطبيق يطبّق تحويلاً بسعر الصرف على كل مبلغ ويعرض بلا كسور | `lavkooapp:lib/helper/price_converter.dart:65-78` |
| Foundation: payments per legal entity | `payment_methods` يُعاد من config، فيمكن تخصيصه بالسياق دون تغيير التطبيق | قائمة واحدة؛ عملة الدفع من إعداد عالمي | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:61-76`؛ `lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-423` |
| GCC-07 / GCC-09 theme per country | تفاوض بالترويسات موجود وموثّق، وعميل لا يرسلها يحصل على افتراضي آمن — سابقة مباشرة لحامل سياق اختياري | `Vary` و`version` و مفتاح كاش التطبيق وموضوع FCM بلا بُعد دولة | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:210`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:236-239`؛ `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:31`؛ `lavkooapp:lib/utill/app_constants.dart:329` |
| GCC-11 legal policies per country | الصفحات تُجلب من `business-pages` لا من ثوابت | بلا سياق متجر؛ النقطة ضمن قائمة الكاش في التطبيق | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:310-320`؛ `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:128` |
| GCC-12 per-store pause | الصيانة تُبلَّغ للتطبيق عبر config وموضوع FCM، وسيط الصيانة الخاص بالمتجر (`maintenance_mode`) غير مطبَّق على مسارات الـ API فتبقى الطلبات القديمة متاحة (`lavkoo:bootstrap/app.php:122`)، ويبقى وسيط الإطار `CheckForMaintenanceMode` العام لوضع `down` فقط (`lavkoo:bootstrap/app.php:46`) | حالة واحدة عالمية؛ لا يوجد وضع "تصفح فقط" في العقد | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:106-111`؛ `lavkoo:routes/rest_api/v1/api.php:54`؛ `lavkooapp:lib/utill/app_constants.dart:324` |
| GCC-16 local/cross-border shipping | — | نقاط الشحن بلا وجهة/دولة في الطلب؛ قيود الدولة والرمز البريدي عالمية | `lavkoo:app/Http/Controllers/RestAPI/v1/ShippingMethodController.php:29-33`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:321-329` |
| GCC-17 central campaigns, local coupons | — | `coupon/apply` و`coupon/list` بلا سياق؛ الخصم مبلغ بلا عملة | `lavkoo:app/Http/Controllers/RestAPI/v1/CouponController.php:21-43`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/CouponController.php:102-122` |
| GCC-08 / GCC-18 central categories and brands | `categories` و`brands` نقاط مركزية بطبيعتها | تُخزَّن في التطبيق بمفتاح بلا متجر، فاختلاف الظهور بين الدول يتطلب بُعداً في المفتاح | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:125-126` |
| Provisional: single-store checkout | الاستجابة `order_ids` جمع أصلاً (طلب لكل بائع) | لا يوجد في طلب الإنشاء ما يثبت المتجر الذي حُسبت السلة عليه | `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:251-282` |
| Provisional: market-scoped app-local caches | آلية تفريغ كاملة موجودة | المفاتيح بلا متجر | القسم 2.8 |
| KWD/BHD/OMR 3 decimals | الخادم يرسل `decimal_point_settings` | التطبيق يتجاهله في `convertPrice` | `lavkooapp:lib/helper/price_converter.dart:75-78` |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أقل إضافة موصى بها |
|---|---|---|---|
| مسارات `/api/v1/*` الـ 156 التي يناديها التطبيق (الأسماء، الطرق، قبول `_method`) | Preserve | مجمَّعة في إصدارات منشورة؛ صفر عدم تطابق اليوم | لا تغيير؛ أي سلوك جديد يكون اختيارياً بالسياق |
| درجات الوصول الثلاث و`guest_id` كمعامل | Preserve ثم Extend | 67 نداءً تعتمد `apiGuestCheck` | قبول رمز ضيف غير قابل للتخمين بجانب الرقم (SEC-E22-01) |
| ترويسة `lang` وتحويل `sa`→`ar` | Preserve | إصدارات منشورة ترسل رمز الدولة فيها | عدم تحميلها أي معنى دولة |
| صيغة الخطأ `{errors:[{code,message}]}` بحالة 403 و`{message}` | Preserve | المعالج المركزي في التطبيق مبني عليها | أخطاء GCC الجديدة بهذه الصيغة حصراً، ولا 401 جديدة |
| تفاوض القالب `X-UI-*` + `ETag`/304 | Preserve ثم Extend | نمط توافق ناجح | إضافة ترويسة المتجر إلى `Vary` وإلى بصمة الحمولة |
| `api:manifest` / `api:snapshot` وكشف التغييرات الكاسرة | Preserve واستعمال | أداة جاهزة لضبط العقد قبل كل نشر | أخذ لقطة أساس قبل أول تغيير GCC |
| `/api/v1/config` | Extend | وثيقة المتجر الواحد | مفاتيح إضافية فقط (`storefront`، `available_storefronts`)، وقيم المفاتيح الحالية تُحسب بالسياق مع بقاء الشكل |
| استجابات المال (منتج، سلة، شحن، كوبون، طلب، محفظة) | Extend | أرقام بلا عملة | حقول إضافية `currency_code` و`decimal_places` بجانب الأرقام الحالية؛ عدم تغيير نوع أي حقل قائم |
| `DioClient._headers()` | Extend | مكان واحد لكل ترويسات Dio | ترويسة سياق واحدة؛ ومعها توحيد الطلبات الست متعددة الأجزاء |
| مفاتيح الكاش المحلي (Dio وDrift والقالب) | Refactor (في التطبيق) | بلا بُعد متجر | إدخال رمز المتجر في المفتاح + تفريغ عند التبديل |
| `PriceConverter.convertPrice` | Refactor (في التطبيق) | تحويل صرف قسري وصفر كسور | عرض المبلغ بعملة ودقة يرسلهما الخادم |
| تناقضات شكل الخطأ ورموز الحالة (E22-F07) | Refactor تدريجي | تكسر المعالج أو تُقرأ نجاحاً | توحيد عبر دالة واحدة؛ تغيير الرمز 200→403 يُعامل كتغيير عقد ويُختبر على الإصدار المنشور |
| ترويسة/معامل سياق المتجر + middleware حلّه | New | غير موجود | انظر القسم 6.2 |
| نقطة قائمة المتاجر المتاحة | New | غير موجودة | نقطة عامة جديدة أو مفتاح داخل config |

## 6. الفجوات والمخاطر

### 6.1 السجل

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E22-F01 | لا يوجد أي حامل لسياق المتجر/الدولة في العقد: لا ترويسة ولا معامل ولا حقل، في الاتجاهين (حاملات السياق القائمة، أي قناة الثيم `X-UI-Channel` / `channel` ومعامل `device`، لا تحمل بُعداً للمتجر أو الدولة؛ وشرائح العميل لا تحمل بعداً جغرافياً) | P0 | VERIFIED | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73`؛ `lavkoo:bootstrap/app.php:84-94`؛ بحث الترويسات في القسم 2.2 | كل قرارات GCC المعتمدة على العزل لا يمكن تنفيذها عبر التطبيق قبل تعريف الحامل | ترويسة اختيارية بافتراضي السعودية (القسم 6.2) |
| E22-F02 | المبالغ في كل الاستجابات بلا رمز عملة، والتطبيق يطبّق تحويل صرف ذاتياً على كل مبلغ | P0 | VERIFIED | `lavkooapp:lib/helper/price_converter.dart:65-73`؛ `lavkoo:app/Models/Order.php:95`؛ `lavkoo:app/Utils/Helpers.php:156-165` | عرض سعر خاطئ أو بعملة خاطئة عند أول متجر غير سعودي؛ يناقض "لا تحويل آلي" | حقول عملة إضافية + تعديل التطبيق؛ الإصدارات القديمة تبقى على المتجر السعودي فقط |
| E22-F03 | التطبيق يعرض الأسعار بصفر منازل عشرية ويتجاهل `decimal_point_settings` | P0 | VERIFIED | `lavkooapp:lib/helper/price_converter.dart:75-78`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:219` | KWD/BHD/OMR تُعرض مقرَّبة إلى عدد صحيح | دقة العملة تُرسل مع السياق ويلتزم بها التطبيق؛ بوابة إصدار قبل فتح تلك الأسواق |
| E22-F04 | التطبيق لا يستهلك العنوان الموحّد إطلاقاً (صفر إشارات إلى `region_id`/`city_id`/`district`/`national_address`/`unified_address`)، والخادم يرفض عناوين الـ API السعودية غير المنظّمة في وضع `strict` | P1 | VERIFIED (الكود) / UNKNOWN (قيمة `unified_address_mode` في الإنتاج) | `lavkooapp:lib/features/address/domain/models/address_model.dart:55-74`؛ `lavkoo:app/Services/ShippingAddressService.php:106-122`؛ `lavkoo:app/Services/ShippingAddressService.php:265-268`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:300-308` | تفعيل `strict` يمنع إضافة عنوان سعودي من كل الإصدارات المنشورة؛ ونموذج العنوان لدول الخليج الأخرى غير معرَّف في العقد | إبقاء `soft` إلى أن يُنشر إصدار يدعم الحقول؛ تصميم حقول العنوان لكل دولة قبل تعديل التطبيق مرة واحدة |
| E22-F05 | ست نقاط multipart ومسارات WebView لا تمرّ عبر `DioClient`، فلا ترث أي ترويسة جديدة (ولا `lang` الحالية) | P1 | VERIFIED | `lavkooapp:lib/features/profile/domain/repositories/profile_repository.dart:41-43`؛ `lavkooapp:lib/features/refund/domain/repositories/refund_repository.dart:23-26`؛ `lavkooapp:lib/features/chat/domain/repositories/chat_repository.dart:69-75`؛ `lavkooapp:lib/features/order_details/controllers/order_details_controller.dart:271` | طلب استرجاع أو تذكرة دعم أو رسالة تُسجَّل بسياق السعودية الافتراضي حتى من متجر آخر | الخادم يشتق السياق من الكيان (الطلب/التذكرة) لا من الترويسة في هذه النقاط؛ وتوحيد الترويسات في التطبيق |
| E22-F06 | التطبيق يرسل `Idempotency-Key` لإنشاء الطلب (COD والمحفظة والدفع اليدوي)، والخادم لا يقرأ هذه الترويسة: بحث `Idempotency-Key` و`idempotency_key` و`HTTP_IDEMPOTENCY` في `app` و`routes` و`bootstrap` و`config` و`Modules` لم يُظهر أي قراءة لها؛ بحث `idempotency` في متحكمات الـ API لا نتائج له، وظهوره في `OrderManager` (`lavkoo:app/Utils/OrderManager.php:99`) يخص حارس تعديل المخزون ولا يتعلق بإنشاء الطلب | P1 | VERIFIED | `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:66-69`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:171-283`؛ `lavkoo:app/Services/Monitoring/Panels/OrderIntegrityPanel.php:1375` (المراقبة تعرض مفتاح التكرار بحالة غير مُهيّأة) | عقد إنشاء الطلب يفتقر إلى آلية تكرار معلنة من جهة الخادم، وللمسار أثر مالي محتمل (التفاصيل في SEC-E22-11) | تنفيذ جانب الخادم قبل GCC، مع إدخال المتجر في مفتاح التكرار |
| E22-F07 | شكل الخطأ ورموز الحالة غير متّسقة: `errors` ككائن، أخطاء تحقق بحالة 200، و202 لنتيجة سلبية | P2 | VERIFIED (الخادم) / INFERRED (أثر Dart) | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:137`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/CartController.php:158-160`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/CouponController.php:121`؛ `lavkooapp:lib/data/model/error_response.dart:6-13` | رسائل GCC الجديدة (منتج غير متاح في هذا المتجر…) قد لا تظهر أو تُقرأ نجاحاً | صيغة واحدة ملزمة للأخطاء الجديدة؛ إصلاح القديمة كتغيير عقد مُختبَر |
| E22-F08 | أي 401 يمسح رمز الدخول ومعرّف الضيف في التطبيق، والخادم يستعمل 401 لحالات ليست انتهاء جلسة (فشل إرسال OTP، بيانات دخول خاطئة) | P1 | VERIFIED | `lavkooapp:lib/data/datasource/remote/exception/api_error_handler.dart:70-80`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:293-296`؛ `lavkoo:app/Http/Middleware/APIGuestMiddleware.php:33` | لو أعاد middleware المتجر 401 (متجر متوقف/غير مسموح) لسُجّل خروج كل المستخدمين وفُقدت سلال الضيوف | منع 401 في مسارات السياق؛ استعمال 403 بالصيغة القياسية أو 503 مع `message` للإيقاف |
| E22-F09 | موضع نداء معرَّف لـ `categoriesUri` بلا `guest_id` على مسار خلف `apiGuestCheck`؛ الخدمة تستدعي البديل ذا معرّف ضيف ثابت (`lavkooapp:lib/features/category/domain/services/category_service.dart:13`) فلم أجد مستدعياً للموضع الأول (بحث `getList(` في `lib/features/category`) | P2 | VERIFIED (الموضع) / INFERRED (أنه غير مستدعى) | `lavkooapp:lib/features/category/domain/repositories/category_repo.dart:20-27`؛ `lavkoo:routes/rest_api/v1/api.php:245`؛ `lavkoo:routes/rest_api/v1/api.php:291` | لو استُدعي من ضيف لأعاد الخادم 401، وبحسب E22-F08 تُمسح بياناته | يُحذف أو يُصحَّح في التطبيق؛ مثال على هشاشة اقتران 401 بمسح الجلسة |
| E22-F10 | مفاتيح الكاش المحلي بلا بُعد متجر؛ 15 نقطة كتالوج تُخدَّم من الكاش حتى 400 ثانية | P1 | VERIFIED | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-140`؛ `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-172`؛ `lavkoo:routes/rest_api/v1/api.php:58-60` | بعد تبديل المتجر يُعرض كتالوج وأسعار المتجر السابق | إدخال رمز المتجر في المفتاح وتفريغ الكاش عند التبديل (الدالة موجودة) |
| E22-F11 | مسارات مكرّرة: `auth/existing-account-check` و`auth/registration-with-social-media` مسجّلتان مرتين بمتحكمين | P2 | VERIFIED (التكرار) / INFERRED (أيهما ينفَّذ) | `lavkoo:routes/rest_api/v1/api.php:135-136`؛ `lavkoo:routes/rest_api/v1/api.php:160-161` | كود ميت يُضلّل أي تعديل لاحق على التسجيل الاجتماعي | حسم المتحكم الفعّال بـ `route:list` على بيئة غير إنتاجية ثم حذف الآخر لاحقاً |
| E22-F12 | `X-App-Version` يحمل ثابتاً لا يطابق إصدار الحزمة (`16.2.1` مقابل `3.0.6+48`)، ومربوط ببوابة التحديث | P1 | VERIFIED | `lavkooapp:lib/utill/app_constants.dart:9-29` | لا يمكن للخادم تمييز "إصدار يفهم المتاجر" بمقارنة رقم الإصدار دون حسم هذا أولاً | اعتماد وجود ترويسة السياق نفسها كإشارة قدرة، لا رقم الإصدار |
| E22-F13 | `notifications` و`customer/info` و`config` تعيد بيانات عالمية بلا تصفية سوق | P1 | VERIFIED | `lavkoo:app/Http/Controllers/RestAPI/v1/NotificationController.php:15-25`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:59-86` | إشعارات وعروض سوق تظهر لعملاء سوق آخر | تصفية بالسياق مع افتراضي السعودية |
| E22-F14 | لا يوجد manifest أو لقطة عقد متتبَّعة في git؛ العقد معرَّف ضمنياً بتسلسل نماذج Eloquent | P1 | VERIFIED | `lavkoo:bootstrap/app.php:90-93`؛ `lavkoo:app/Services/DeveloperPortal/ApiManifest.php:32-33` | إضافة عمود إلى جدول تغيّر العقد تلقائياً؛ محلّلات Dart تشترط حقولاً بعينها دون فحص null | لقطة أساس + اختبار عقد على الحقول التي يشترطها التطبيق |
| E22-F15 | محلّلات التطبيق تشترط حقولاً مالية دون فحص null (الطلب، المحفظة، تفاصيل المنتج) | P1 | VERIFIED | `lavkooapp:lib/features/order/domain/models/order_model.dart:158`؛ `lavkooapp:lib/features/wallet/domain/models/wallet_transaction_model.dart:122-125`؛ `lavkooapp:lib/features/product_details/domain/models/product_details_model.dart:490` | إعادة تسمية أو إخفاء أي منها يُسقط الشاشة في الإصدارات المنشورة | قائمة "حقول لا تُمَس" ضمن اختبار العقد |
| SEC-E22-01 … SEC-E22-11 | إحدى عشرة مسألة أمنية مقيّدة — الدرجات والتفاصيل في الملحق المقيّد غير المنشور | P0–P2 | VERIFIED (الكود) | الملحق المقيّد | — | تُعالَج قبل توسيع السطح إلى أسواق جديدة |

SEC-E22-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E22-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E22-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E22-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E22-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E22-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E22-07: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E22-08: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E22-09: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E22-10: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E22-11: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 6.2 أين يُحمَل سياق المتجر مع التوافق الخلفي

| الخيار | يصل للإصدارات المنشورة؟ | التوافق | الملاحظات |
|---|---|---|---|
| **(أ) ترويسة اختيارية** (مثل `X-Storefront: sa`) يحلّها middleware جديد في مجموعة v1، وغيابها = المتجر الافتراضي (GCC-19) | نعم: الإصدارات القديمة لا ترسلها فتبقى على السعودية | الأعلى | يطابق سابقة `X-UI-*` (`lavkoo:app/Services/Theme/ViewerContext.php:76-91`)؛ إضافة سطر واحد في `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73`؛ يجب إضافتها إلى `Vary` وإلى كل مفتاح كاش؛ لا تغطي multipart وWebView (E22-F05) فيلزم معها معامل استعلام بديل بالاسم نفسه كما يفعل القالب |
| **(ب) بادئة مسار** (`/sa/api/v1/…` أو `/api/v1/sa/…`) | لا: 164 مساراً ثابتاً بلا بادئة | متوسط: يتطلب إبقاء المسارات غير المسبوقة تعمل للأبد كمرادف للسعودية | يضاعف جدول المسارات ويغيّر معرّفات نقاط الـ manifest ولقطاته؛ يفيد فقط لكاش HTTP مشترك |
| **(ج) حقل في config** | config نفسه يحتاج السياق أولاً | مكمّل لا بديل | مناسب لإعلان `storefront` الحالي و`available_storefronts` والعملة والدقة؛ `ConfigModel.fromJson` يقرأ مفاتيح بعينها فيتجاهل الإضافات (`lavkooapp:lib/features/splash/domain/models/config_model.dart:184-190`) |

التوصية الموثّقة (لا تنفيذ): (أ) مع معامل استعلام احتياطي + (ج)، وعدم استعمال `lang` ولا المفتاح `country_code` لهذا الغرض (القسم 3 بند 6).

### 6.3 تغييرات العقد مرتّبة حسب خطر الكسر (الأعلى أولاً)

| # | التغيير | خطر الكسر على الإصدارات المنشورة | السبب |
|---|---|---|---|
| 1 | إعادة تسمية/حذف/تغيير نوع حقل مالي قائم، أو إعادة المبالغ بعملة السوق دون سياق صريح | كاسر | محلّلات بلا فحص null + تحويل صرف في العميل (E22-F02، E22-F15) |
| 2 | إعادة 401 من middleware السياق | كاسر | مسح الجلسة ومعرّف الضيف (E22-F08) |
| 3 | نقل المسارات تحت بادئة دولة دون إبقاء القديمة | كاسر | مسارات ثابتة في العميل |
| 4 | تفعيل `strict` للعنوان الموحّد أو اشتراط حقول عنوان جديدة | كاسر لتدفق العنوان | E22-F04 |
| 5 | جعل `current_currency_code` أو أي سياق إلزامياً في `digital-payment` أو إنشاء الطلب | كاسر | التطبيق لا يرسله في `digital-payment`؛ ونقطة `edit-order/due-payment-by-digital-payment` تشترطه أصلاً (`lavkoo:app/Http/Controllers/RestAPI/v1/OrderEditController.php:162`) ويرسله التطبيق فيها (`lavkooapp:lib/features/order_details/domain/repositories/order_details_repository.dart:187`) |
| 6 | تصحيح أخطاء التحقق من 200 إلى 403، أو `errors` من كائن إلى قائمة | متوسط | يغيّر مساراً في منطق العميل؛ يُختبر على الإصدار المنشور |
| 7 | تغيير محتوى `payment_methods` أو `currency_list` حسب السياق | منخفض ما دام الافتراضي سعودياً | الشكل ثابت والقيم تتغيّر |
| 8 | إضافة مفاتيح جديدة إلى config أو حقول `currency_code` بجانب المبالغ | آمن | المحلّلات تتجاهل المفاتيح غير المعروفة |
| 9 | إضافة ترويسة سياق اختيارية + `Vary` | آمن | سابقة `X-UI-*` |
| 10 | نقاط جديدة (قائمة المتاجر، تبديل المتجر) | آمن | لا يناديها الإصدار القديم |

### 6.4 النقاط التي تعيد مالاً أو إعدادات ضمنية لمتجر واحد

- **إعدادات**: `config` (كامل)، `business-pages`، `banners`، `flash-deals`، `deals/featured`، `dealsoftheday`، `theme/home`، `theme/version`، `notifications`، `coupon/list`، `coupon/applicable-list`، `customer/wallet/bonus-list`، `customer/order/offline-payment-method-list`، `customer/get-restricted-country-list`، `customer/get-restricted-zip-list`، `shipping-method/by-seller`.
- **مال بلا عملة**: كل نقاط `products/*` و`seller/{slug}/*` و`categories/products` و`brands/products` (أسعار)، `cart` (سعر، ضريبة، شحن، خصم)، `shipping-method/chosen`، `coupon/apply`، `cart/get-referral-discount-redeem`، `customer/order/list|details|get-order-by-id`، `order/track`، `customer/order/refund|refund-details`، `customer/wallet/list`، `customer/info` (`wallet_balance`)، `add-to-fund` (حدود 202)، `edit-order/due-payment-*`.
- **مال مع عملة**: `payment/myfatoorah/status` فقط.

## 7. أسئلة مفتوحة وعناصر UNKNOWN

1. قيمة `unified_address_mode` و`currency_model` و`system_default_currency` و`decimal_point_settings` و`user_app_version_control` في الإنتاج — يتطلب قراءة `business_settings` أو استجابة `/api/v1/config` الحية (غير مسموح في هذه المرحلة).
2. أيّ المتحكمين ينفَّذ فعلاً للمسارين المكرّرين (E22-F11) — يتطلب `route:list` على بيئة غير إنتاجية.
3. هل توجد لقطات في `api_snapshots` على الإنتاج، ومتى أُخذت آخر لقطة — يتطلب وصولاً لقاعدة البيانات.
4. توزيع إصدارات التطبيق المنشورة فعلياً (وهل ما زالت إصدارات ترسل رمز الدولة في `lang`) — يتطلب بيانات لوحة المراقبة أو متاجر التطبيقات.
5. هل يقف أمام الخادم CDN/بروكسي يخزّن استجابات `GET /api/v1/*` (يؤثر على `Vary` وعلى GET المغيِّرة للحالة) — يتطلب إعدادات البنية التحتية.
6. تطابق الحقول لكل النماذج الـ 709 لم يُستكمل؛ فُحصت التدفقات الحرجة فقط. استكماله يتطلب لقطة شكل الاستجابات المسجَّلة (`RecordApiResponseShape`) من بيئة تشغيل.
7. سلوك `_method` داخل جسم JSON مُستنتج من الإطار (لا `vendor` محلياً)؛ يؤكَّده اختبار على بيئة غير إنتاجية.

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| 164 ثابت نقطة، كلها v1 | `lavkooapp:lib/utill/app_constants.dart:46-288` | VERIFIED |
| عنوان أساسي واحد ثابت | `lavkooapp:lib/utill/app_constants.dart:39` | VERIFIED |
| ترويسات Dio السبع | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73` | VERIFIED |
| multipart يحمل `Authorization` فقط | `lavkooapp:lib/features/profile/domain/repositories/profile_repository.dart:41-43` | VERIFIED |
| مجموعة `api` بلا middleware سياق | `lavkoo:bootstrap/app.php:84-94` | VERIFIED |
| v1 كله تحت `api_lang` | `lavkoo:routes/rest_api/v1/api.php:54` | VERIFIED |
| منطق `apiGuestCheck` | `lavkoo:app/Http/Middleware/APIGuestMiddleware.php:21-33` | VERIFIED |
| تحويل `sa`→`ar` في `lang` | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:27` | VERIFIED |
| v2 للبائع وعامل التوصيل فقط | `lavkoo:routes/rest_api/v2/api.php:26-27` | VERIFIED |
| config كائن متجر واحد | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:133-291` | VERIFIED |
| `/api/v1/cash` ومدة الكاش | `lavkoo:routes/rest_api/v1/api.php:58-60` | VERIFIED |
| مفاتيح الكاش بلا متجر | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-172` | VERIFIED |
| 401 يمسح الجلسة | `lavkooapp:lib/data/datasource/remote/exception/api_error_handler.dart:70-80` | VERIFIED |
| `errors` يُفترض قائمة | `lavkooapp:lib/data/model/error_response.dart:6-13` | VERIFIED |
| صيغة الخطأ القياسية في الخادم | `lavkoo:app/Utils/Helpers.php:313-320` | VERIFIED |
| `Idempotency-Key` يُرسل | `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:66-69` | VERIFIED |
| `Idempotency-Key` لا يُقرأ | بحث `idempotency` في متحكمات الـ API و`OrderManager`/`CartManager`/middleware: لا نتائج ذات صلة | VERIFIED (بحث مسجّل) |
| عملة الدفع من إعداد عالمي | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-423` | VERIFIED |
| الدافع يُشتق من المصادقة | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:117-121` | VERIFIED |
| رصيد المحفظة من `users` | `lavkoo:app/Http/Controllers/RestAPI/v1/UserWalletController.php:31` | VERIFIED |
| رصيد الولاء من `users` | `lavkoo:app/Http/Controllers/RestAPI/v1/UserLoyaltyController.php:60` | VERIFIED |
| `convertPrice` بصفر كسور وتحويل صرف | `lavkooapp:lib/helper/price_converter.dart:65-78` | VERIFIED |
| التطبيق لا يعرف العنوان الموحّد | بحث الأنماط الخمسة في `lavkooapp/lib`: صفر نتائج | VERIFIED (بحث مسجّل) |
| قناة الـ API قديمة في `soft` فقط | `lavkoo:app/Services/ShippingAddressService.php:265-268` | VERIFIED |
| `Vary` القالب بلا دولة | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:210` | VERIFIED |
| `amount` + `currency` في حالة MyFatoorah | `lavkoo:app/Http/Controllers/RestAPI/v1/MyFatoorahStatusController.php:142-149` | VERIFIED |
| manifest وقت التشغيل لا ملف | `lavkoo:app/Services/DeveloperPortal/ApiManifest.php:32-33` | VERIFIED |
| `routes.json` بلا مسارات API | `lavkoo:routes.json`، `lavkoo:public/routes.json` | VERIFIED |
| مسارات مكرّرة | `lavkoo:routes/rest_api/v1/api.php:135-136`؛ `lavkoo:routes/rest_api/v1/api.php:160-161` | VERIFIED |
| `X-App-Version` لا يطابق إصدار الحزمة | `lavkooapp:lib/utill/app_constants.dart:9-29` | VERIFIED |
| موضوعات FCM عالمية | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:145-147` | VERIFIED |
| سلوك `_method` في JSON | سلوك Laravel (لا `vendor` محلياً) | INFERRED |
| قيم الإعدادات في الإنتاج | — | UNKNOWN |


## 9. سجل التحقق المستقل

> أُجري التحقق بعد إنشاء الملف الرئيسي: قراءة فقط على المستودعات، وتعديل محصور في هذا الملف وملحقه المقيّد.

### أ. عدّ البنود

| البند | القيمة |
|---|---|
| الادعاءات المُدقَّقة دلالياً (تشمل كل بنود P0 وP1، وعشرين ادعاءً حاملاً للتصميم على الأقل) | 100 |
| مؤكَّدة | 85 |
| مُصحَّحة | 10 |
| مُفنَّدة | 1 |
| غير قابلة للتحقق في هذه المرحلة | 4 |
| فحص الاستشهادات `check_citations.py` | 0 خلل |
| فحص الحساسية `scan_sensitive.py` | 0 نتيجة |
| بنود نُقلت إلى الملحق المقيّد في هذا التحقق | 3 (SEC-E22-09 إلى SEC-E22-11)؛ الإجمالي في الملحق 11 بنداً |

### ب. التصحيحات

1. رأس الوثيقة: الـ HEAD المحلي وقت التحقق هو `lavkoo@5c8d03b7` (20 commit توثيقياً تحت `docs/gcc/audits/`، دون تغيير في الكود)؛ الصيغة السابقة كانت قديمة.
2. §2.2: ترويسة `X-UI-Channel` يقرؤها الخادم ولا يرسلها التطبيق الحالي، ومعاملات الاستعلام البديلة لم تكن مذكورة، وترويسة `X-Locale` في متحكم الدفع (لغة لا متجر) أُضيفت إلى قائمة القراءات.
3. §6.1 F01: حاملات السياق القائمة لا تحمل متجراً ولا دولة؛ شرائح العميل لا تحمل بعداً جغرافياً (`SegmentResolver`).
4. §2.2 مسار WebView: صار الوصف عامّاً، ونُقلت التفاصيل إلى SEC-E22-10؛ صيغة قاعدة النتيجة لمسار الدفع الرقمي أدقّ مما كانت مكتوبة.
5. §4 GCC-12: وسيط الصيانة `maintenance_mode` لا يُطبَّق على مسارات الـ API؛ وسيط الإطار العام لا يعمل إلا في وضع `down`.
6. §6.1 F06: بحث `idempotency` في متحكمات الـ API لا نتائج له، وفي `OrderManager` ظهور واحد يخص حارس تعديل المخزون؛ وتقدير الخطر صار مقيّداً بالتوقيت (التفاصيل في SEC-E22-11).
7. §2.6 حساب الحذف: أُضيف فحص المزاد النشط عند تفعيل الوحدة.
8. §2.5: عدد ملفات Resource أربعة لا ثلاثة (ثلاثة على المستوى الأعلى وملف في `RestAPI/`)، ولا تستعملها متحكمات v1 المفحوصة.
9. §2.7: معالج الأخطاء يعالج 429 بنص حالة الاستجابة و500 بنص ثابت، ولم يكن ذلك مذكوراً.
10. §6.3 البند 5: نقطة `edit-order/due-payment-by-digital-payment` تشترط `current_currency_code` أصلاً ويرسله التطبيق فيها؛ الشرط الجديد يخص `digital-payment` وإنشاء الطلب فقط.

### ج. المفنَّد

- §2.4 وE22-F11: المتحكم المنفَّذ للمسارين المكرّرين هو الأول تسجيلاً (`CustomerAPIAuthController`) لا الأخير. الحكم `INFERRED` من سلوك Laravel، ويحتاج `route:list` على بيئة غير إنتاجية.

### د. بنود غير قابلة للتحقق في هذه المرحلة

- أعداد المسارات حسب درجة الوصول (45 / 72 / 63) والزوج 161: لم تُعَد بمحلّل آلي؛ جرى فحص نحو 35 مساراً من حيث الدرجة وتطابقت.
- المسارات الـ 24 غير المستهلكة بثابت: جُمعت الأعداد ولم تُعَد أسماؤها واحداً واحداً.
- «44 ملف Dart و203 موقع استخدام»: العدّ بالنمط نفسه يعطي 39 ملفاً لثوابت `Uri`؛ لم يُعَد الرقم الموثّق.
- مسار إدراج الشحن (البند SEC-E22-08 في الملحق): لم يُقرأ كاملاً.

### هـ. مخاطر متبقية

- ترتيب تسجيل المسارات المكرّرة (E22-F11) وسلوك `_method` داخل الجسم JSON: `INFERRED` حتى تأكيدهما باختبار.
- القيم الإنتاجية (`unified_address_mode`، `currency_model`، `decimal_point_settings`، `user_app_version_control`، وقيمة `app.mode` في الملحق): `UNKNOWN`.
- تداخل المصطلح: `Storefront` مستعمل في الكود لمتجر المالك الواحد (`lavkoo:app/Enums/SalesChannels/SalesChannel.php:8`)، فعلى تصميم GCC أن يميّز بينه وبين مفهوم Storefront المعتمد (`INFO`).
- لوحة المراقبة تعرض مفتاح التكرار بحالة غير مُهيّأة (`lavkoo:app/Services/Monitoring/Panels/OrderIntegrityPanel.php:1375`)، وهذا يعضّد E22-F06 ولا يغيّر درجته.
- الاستشهادات تخصّ HEAD `lavkoo@5c8d03b7` وقت التحقق، ويجب إعادة التحقق من HEAD قبل النشر.

### و. فجوات التغطية

- جدول §2.6 لا يغطي نقاط `edit-order/due-payment-*` الأربع (`lavkoo:routes/rest_api/v1/api.php:461-468`) التي يناديها التطبيق (`lavkooapp:lib/features/order_details/domain/repositories/order_details_repository.dart:160-217`)؛ تحتاج جولة تدقيق لاحقة.
- لم تُقرأ كل متحكمات v1 (37 متحكماً) سطراً سطراً، وجرى تدقيق المسارات المعنية فقط.
- مطابقة الحقول لكل نماذج Dart (709 ملفاً) لم تُستكمل، كما هو مذكور في §1.

### ز. البنود المنقولة

- نُقلت إلى الملحق المقيّد في هذا التحقق: SEC-E22-09، SEC-E22-10، SEC-E22-11. مؤشراتها الأحد عشر مذكورة في §6.1.
