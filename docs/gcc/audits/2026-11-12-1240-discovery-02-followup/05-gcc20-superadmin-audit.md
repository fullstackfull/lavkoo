# GCC-20 — تدقيق Platform Super Admin داخل تطبيق البائع (Discovery-02 follow-up)

**التاريخ:** 2026-11-12
**الوضع:** DOCUMENTATION ONLY / READ-ONLY — لا تنفيذ، لا كود API أو UI، لا تغيير في أي مستودع
**الحالة:** كل ما يلي مُقترح PROPOSED ما لم يُذكر خلاف ذلك. لا شيء معتمد.
**المرجع:** `origin/main:docs/gcc/GCC-20-SUPER-ADMIN-MOBILE.md` و `origin/main:docs/gcc/DECISIONS.md` (قسم GCC-20)

---

## 1. النطاق والإصدارات والمنهجية

### 1.1 الإصدارات (SHA)

| المستودع / المرجع | SHA | ملاحظة |
|---|---|---|
| `lavkoo` — HEAD المحلي على `claude/exciting-shannon-4mh2ok` عند بدء التدقيق | `f2e0121357c4fefd3ad51916e0352c417b97ccef` | الشجرة نظيفة للمسارات المدققة |
| `lavkoo` — HEAD المحلي عند إنهاء التدقيق | `18dd23626747…` | تقدّم بتوثيق فقط من مسارات تدقيق أخرى (`docs/gcc/audits/…`)؛ `git diff --quiet f2e0121 18dd2362` على كل مسارات الكود المدققة = صفر فروق |
| `lavkoo` — `origin/claude/exciting-shannon-4mh2ok` | `da1f763e6786…` | يتقدم على HEAD المحلي بملف توثيق واحد فقط من مسار تدقيق آخر (`docs/gcc/audits/2026-11-12-1240-discovery-02-followup/02-git-lineage-and-n6-errata.md`)، ولا يغير أي ملف مدقق |
| `lavkoo` — `origin/main` | `55e3dd0ea41069f64b0657fcbb4f8953710d2cd9` | مصدر نص GCC-20 |
| `lavkooappseller` — seller main (clone) | `9ea64232683a6b415fb504e3d0a740a2e5a53306` | HEAD المطلوب |
| `lavkooappseller` — الأساس المدقق (محلي) | `8ea00922e44db3d69059d46892f8f4f84349b580` | مرجع الاستشهادات `lavkooappseller:` |

**تحقق تطابق تطبيق البائع:** `git diff --name-only 8ea0092 9ea6423` لا يعرض أي ملف تحت `lib/`. التغييرات بين الإصدارين في `pubspec.yaml` و`assets/language/ar.json` و`CLAUDE.md` وإعدادات iOS/macOS وأصول المتجر فقط. لذلك كل استشهاد `lavkooappseller:lib/...` صحيح في الإصدارين، ولا توجد حالة تحتاج الوسم `(main@9ea6423)`.

### 1.2 المنهجية

- قراءة ثابتة (static) فقط لملفات Backend في HEAD المذكور، وملفات Flutter في seller main. لا تشغيل لأي كود أو اختبار أو `artisan` أو `flutter` أو استعلام قاعدة بيانات أو اتصال بالإنتاج.
- كل استشهاد تم فتحه وإعادة التحقق منه قبل الكتابة.
- الحالات: VERIFIED = قرأت الكود في السطر المستشهد به؛ INFERRED = استنتاج من بنية الملفات أو من غياب نتائج البحث؛ UNKNOWN = لا يُحسم ساكناً.
- الخطورة: P0 / P1 / P2 / INFO وفق قواعد المهمة.
- **Redaction:** وفق قواعد النشر، أي مسار يجمع موقع الشيفرة مع خلل يتعلق بفحص تحوّل إلى مؤشر مقيّد `SEC-G20-NN` دون تفاصيل (انظر القسم 9.2). لم يُفتح أي ملف تحت `restricted/` ولم يُكتب فيه.

### 1.3 أنماط البحث المستخدمة (Search patterns)

Backend (`/home/user/lavkoo`):

- `grep -rl "SellerPrincipal" app Modules routes config`: تحديد كلاس الهوية الوحيد للبائع والموظف.
- `grep -rn "'admin'" routes/rest_api`: هل توجد مسارات REST بحارس الإدارة. النتيجة: لا شيء (تعليق واحد في `routes/rest_api/delivery_syria.php`).
- `grep -rln "guard('admin')|auth('admin')|Admin::|App\\Models\\Admin\b|AdminRole" app/Http/Controllers/RestAPI app/Http/Middleware`: استخدام حارس الإدارة في REST. النتيجة: لا استخدام لـ `auth('admin')` في REST؛ وظهر `Admin::` في متحكم تسجيل البائع فقط (يُعالج في القسم 9.2).
- `grep -rn "auth:api|passport|HasApiTokens|createToken" app Modules routes`: آلية الرموز. النتيجة: `HasApiTokens` على `App\User` و`App\Models\Customer` فقط.
- `grep -rn "seller_api_auth|seller_can|seller_owner|'admin'" bootstrap/app.php`: أسماء الوسطاء (middleware aliases).
- `grep -rln "auth_token" app`: كل مواضع الرمز المخزن.
- `grep -rni "two_factor|2fa|totp|mfa\b" app config routes`: وجود تحقق ثانٍ. النتيجة: لا يوجد.
- `grep -rn "impersonat|login-as|loginAs" app routes`: لا يوجد.
- `grep -rn "ApiDoc::ADMIN"` و`grep -rn "audience: ApiDoc::" app/Http/Controllers/RestAPI`: هل توجد واجهات REST بجمهور إدارة. النتيجة: `ApiDoc::ADMIN` مستخدم في مصنف البوابة فقط؛ الواجهات REST مصنفة `CUSTOMER_APP` (11) أو `VENDOR_APP` (88).
- `grep -rn "registerFactory|ChangeNotifierProvider"` (لاحقاً في Flutter).

Seller app (`seller-main`):

- `grep -rniE "superAdmin|isSuper|isAdmin|admin_role|adminRole|allowlist|whitelist|platform_super|@[a-z]+\.[a-z]+"` على `lib/`: النتيجة: لا علم إداري ولا قائمة بريد ولا معرّف ثابت.
- `grep -rn "if (.*(id|Id|ID) *== *[0-9]" lib`: لا معرّفات ثابتة للمستخدم.
- `grep -rn "subscribeToTopic|unsubscribeFromTopic|onMessage|onMessageOpenedApp|getInitialMessage" lib`.
- `grep -rn "local_auth|LocalAuthentication|biometric" lib`: لا قفل حيوي. النتيجة: لا يوجد.
- `grep -rn "logout|sign.?out|timeout|idle" lib`: لا Endpoint خروج على الخادم ولا مهلة جلسة في التطبيق.
- `grep -rn "onGenerateRoute|named route|routes: {|GetPage" lib`: لا جدول مسارات مسمّى. التنقل بـ `Navigator.push` مباشرة.
- `grep -rn "isLoggedIn()" lib`: بوابة التنقل الوحيدة على مستوى العميل هي وجود مفتاح الرمز.

### 1.4 ما لم يُقرأ (NOT read)

- الملفات التالية غير مقروءة بالكامل: `app/Services/Marketplace/SellerPermissionService.php` (قرأت الأسطر 1–220 فقط)، و`app/Http/Controllers/RestAPI/v3/seller/SellerController.php` (قرأت الدوال المستشهد بها فقط)، و`routes/rest_api/v2/api.php` (قرأت مجموعة seller فقط).
- `routes/vendor/routes.php` و`routes/seller/routes.php` (قرأت عناصر الوسطاء فقط).
- كل مجلدات الوحدات الأخرى في Flutter (`order`, `pos`, `product`, `wallet`, `statement`, `refund`...) إلا ما ذُكر.
- `lib/firebase_options.dart`: لم يُفتح عمداً لأنه يحوي إعدادات مشروع لا حاجة لها في هذا التدقيق.
- `lib/features/security/*` قرأت منه `security_controller.dart` بمقاطع فقط.
- `lib/features/splash/controllers/splash_controller.dart` و`lib/features/notification/controllers/notification_controller.dart` و`lib/helper/version_helper.dart` و`lib/helper/app_health_reporter.dart`: لم تُقرأ. بوابة إصدار التطبيق (version gating) لم تُحسم.
- `bootstrap/backup/app.php`، ومجلد `installation/`، وأي migration. لا يوجد وصول لقاعدة البيانات، ولم تُفحص أعمدة الجداول الفعلية.
- `app/Services/SellerIntelligence/*` و`app/Services/AdminService.php` (قرأت الدوال المستشهد بها فقط).
- مجلدات `restricted/` و`*-security.md`: **لم تُفتح ولم تُكتب** (قاعدة المهمة).
- Production: ملف `.env`، وإعدادات `SESSION_DRIVER`، وقائمة مسارات الإنتاج الفعلية (`route:list`)، وسجل الإنتاج، ومزوّد FCM، وحدود الاشتراك بالمواضيع.

---

## 2. الوضع الحالي

### 2.1 مصادقة البائع والموظف وما يصل إليه الرمز

**الدخول الموحّد (نقطة واحدة):** `POST /api/v3/seller/auth/login` (`lavkoo:routes/rest_api/v3/seller.php:53-54`) تحت `throttle:auth` (`:51`). لا يوجد Endpoint دخول منفصل للإدارة.

**البائع (owner) — VERIFIED:**

1. `LoginController::login` يبحث عن البريد في جدول `sellers` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:72`).
2. التحقق من كلمة المرور عبر الحارس الجلسي `auth('seller')->attempt` ومع شرط `status == 'approved'` (`LoginController.php:98`).
3. الرمز: `Str::random(50)` يُكتب نصاً في `sellers.auth_token` (`LoginController.php:99-100`). لا عمود لتاريخ الانتهاء، ولا تجزئة (hash)، ولا قدرات (abilities).
4. في كل طلب: `SellerApiAuthMiddleware::handle` يقرأ `Authorization` ويستدعي `SellerPermissionService::principalFor` (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:43-57`). الدالة تبحث أولاً في `sellers` بالرمز مع `approved` (`lavkoo:app/Services/Marketplace/SellerPermissionService.php:100`).
5. النتيجة: `SellerPrincipal::owner($seller)` (`lavkoo:app/Services/Marketplace/SellerPrincipal.php:35-38`)، ويُضبط `$request['seller']` وسمة `seller_principal` (`SellerApiAuthMiddleware.php:60`, `:64`). `isOwner()` = لا موظف ولا مفتاح (`SellerPrincipal.php:71-74`)، و`can()` يعيد `true` للمالك (`SellerPrincipal.php:93-96`).

**الموظف (staff) — VERIFIED:**

1. نفس نقطة الدخول. إن لم يوجد بائع بهذا البريد (`LoginController.php:78`) تُستدعى `staffToken()` (`LoginController.php:28-54`)، وتطابق كل المرشحين النشطين بالبريد وكلمة المرور، وتتحقق أن الشركة `approved` (`LoginController.php:38-44`).
2. الرمز: `Str::random(50)` يُكتب في `seller_staff.auth_token` (`LoginController.php:50-51`)، ويُعاد `{token}` فقط (`LoginController.php:82`).
3. في كل طلب: `principalFor` يبحث في `seller_staff` بالرمز، ويشترط حالة `active` والشركة `approved` (`SellerPermissionService.php:109-115`). الصلاحيات تُقرأ من الدور **عند كل طلب** (`permissionsOf`, `SellerPermissionService.php:200-209`).
4. النتيجة: `SellerPrincipal::staff($employer, $staff, permissions)` (`SellerPrincipal.php:60-63`). `sellerId()` هو **المتجر**، فكل استعلام يُنطَق على `sellerId` يخدم الموظف على بيانات المتجر نفسه.
5. التحقق من الصلاحية على المسار: `EnsureSellerPermission::handle` يطابق `seller_can:<permission>` (`lavkoo:app/Http/Middleware/EnsureSellerPermission.php:42-46`). المسارات التي لا تعلن `seller_can` مفتوحة لكل هوية مصادقة بحسب التصميم الموثق (`lavkoo:routes/rest_api/v3/seller.php:74-76`).
6. المسارات المالكة فقط: `seller_owner` يطابق `isOwner()` (`lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:34-38`)، ويُطبَّق على `account-delete` و`seller-update` (`routes/rest_api/v3/seller.php:87-92`).

**ما يصل إليه كل رمز (VERIFIED بالقراءة الساكنة):**

| الرمز | المدى |
|---|---|
| رمز البائع | كل مسارات v3 المحمية بـ `seller_api_auth` (`routes/rest_api/v3/seller.php:72`) ضمن متجره، بما فيها المالية (`seller_can:finance.view` السطر 110)، والسحب (`seller_can:payouts.request` السطر 120)، وإدارة الفريق والأمن (`routes/rest_api/v3/seller.php:536-555`)، وإغلاق الحساب (`seller_owner`, `:87-92`). |
| رمز الموظف | المسارات التي يجيزها دوره عبر `seller_can`، ومسارات المالكين مرفوضة (`EnsureSellerIsOwner`). |
| مفتاح التكامل (`SellerApiKey`) | المسارات التي تعلن `seller_can:` فقط؛ أي مسار بلا إعلان يُرفض بـ 403 (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:66-79`). |
| الرمز بعد إلغاء الشركة | يُرفض بـ 401 (`SellerApiAuthMiddleware.php:90-92`) لأن `principalFor` لا يجد صفاً `approved`. |

**نقاط الضعف الهيكلية في الرموز (VERIFIED):**

- الرمز نص عادي في قاعدة البيانات، ولا يكتب الكود له تاريخ انتهاء ولا تجزئة. في المقابل، مفاتيح التكامل مُجزّأة بـ `Hash::make` (`lavkoo:app/Services/Marketplace/SellerApiKeyService.php:56`) ولها `expires_at` وفحص `isUsable()` (`lavkoo:app/Models/SellerApiKey.php:36-43`). إذن النموذج الأكثر أماناً موجود في الكود، لكنه غير مُطبَّق على رموز الدخول.
- لا يوجد Endpoint لتسجيل الخروج على الخادم، ولا قائمة جلسات للمالك، ولا إبطال انتقائي. المسار الوحيد للإبطال ينطبق على مفاتيح التكامل (`lavkoo:routes/rest_api/v3/seller.php:523`).
- الرمز يتجدد عند: تغيير كلمة مرور المالك (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:409-411`)، وعند تعليق البائع أو حذفه من لوحة الإدارة (`lavkoo:app/Http/Controllers/Admin/Vendor/VendorController.php:219` و`:858`)، وعند مسارات أخرى مُحالة إلى المؤشرات المقيّدة في القسم 9.2 دون تفاصيل.
- إيقاف موظف من المالك يفرّغ رمزه (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerSecurityController.php:275`، و`lavkoo:app/Services/Marketplace/SellerTeamService.php:155`, `:163`, `:199`).
- الخروج في التطبيق محلي فقط: `clearSharedData` يحذف الرمز من `SharedPreferences` وكلمة المرور من التخزين الآمن، ولا يستدعي الخادم (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:135-148`).

### 2.2 حارس الإدارة اليوم (Admin guard) — VERIFIED

- **التعريف:** حارس `admin` جلسي (`session`) مع مزوّد `admins` (`lavkoo:config/auth.php:49-52`)، ومزوّد `admins` يشير إلى `App\Models\Admin` (`lavkoo:config/auth.php:88-91`، نموذج في السطر 90).
- **النموذج:** `Admin` يمتد `Authenticatable` ويستخدم `Notifiable, StorageTrait` فقط، بلا `HasApiTokens` (`lavkoo:app/Models/Admin.php:34`, `:36`). `HasApiTokens` موجود فقط على `App\User` (`lavkoo:app/User.php:18`) و`App\Models\Customer` (`lavkoo:app/Models/Customer.php:11`).
- **الوسيط:** `AdminMiddleware` يتحقق من `Auth::guard('admin')->check()` وإلا `abort(404)` (`lavkoo:app/Http/Middleware/AdminMiddleware.php:20`, `:27`). ويوجد تفريع خاص بالمعرّف الرقمي 1 داخل الفحص نفسه (`lavkoo:app/Http/Middleware/AdminMiddleware.php:21`).
- **الوحدات:** `ModulePermissionMiddleware` يستدعي `Helpers::module_permission_check` (`lavkoo:app/Http/Middleware/ModulePermissionMiddleware.php:20-31`)، وفيها `admin_role_id == 1` يمنح كل الوحدات (`lavkoo:app/Utils/Helpers.php:386`).
- **تسجيل الدخول:** متحكم `Admin\Auth\LoginController::login` (`lavkoo:app/Http/Controllers/Admin/Auth/LoginController.php:39-66`) يحدد نوع المستخدم (مدير/موظف) بالمعرّف الرقمي للصف (`:51`, `:55`)، ثم `AdminService::isLoginSuccessful` عبر `auth('admin')->attempt` (`lavkoo:app/Services/AdminService.php:12-18`). المسار مُحاط بـ`throttle:auth` (`lavkoo:routes/admin/routes.php:174`).
- **لا تحقق ثانٍ:** لا يوجد أي تحقق ثانٍ في مسار الدخول (VERIFIED بالقراءة، و INFERRED بنتيجة البحث الشامل).
- **الجلسة:** الافتراضي `file` (`lavkoo:config/session.php:21`)، والخروج يبطل الجلسة الحالية فقط (`lavkoo:app/Services/AdminService.php:20-24`). لا توجد قائمة أجهزة أو جلسات.
- **التدقيق:** `AuditLogger::resolveActor` يفحص حارس `admin` أولاً (`lavkoo:app/Services/AuditLogger.php:119-126`)، ثم الرمز الجديد لطبقة البائع، ثم `seller` و`customer` (`AuditLogger.php:136-141`).

### 2.3 هل يوجد API إدارة للموبايل؟ — نتيجة البحث: لا

- لا توجد مجموعة مسارات REST بوسيط `admin` (نتيجة البحث في `routes/rest_api`).
- لا يستخدم أي متحكم REST الحارس `admin` (`auth('admin')` أو `guard('admin')`).
- `ApiDoc::ADMIN` معرّف في `lavkoo:app/Services/DeveloperPortal/ApiDoc.php:41` ومُستخدم في مصنف البوابة فقط، ولا يُسند إلى أي مسار REST.
- لا يوجد مسار يمثّل بائعاً من حساب الإدارة.
- الخلاصة: **لا يوجد أي مسار API يقبل هوية إدارية**. أي تطبيق إدارة موبايل يحتاج بناء هوية ومسارات جديدة. وأي إشارة إلى "حارس الإدارة" في الموبايل تعني الحارس الجلسي للوحة الويب فقط.

---

## 3. مصفوفة فجوات الفصل بين `platform_super_admin` والبائع/الموظف

الحالة: VERIFIED ما لم يُذكر. الخطورة وفق القواعد.

| البند | الوضع الحالي | المطلوب في GCC-20 | الفجوة | الحالة | الخطورة |
|---|---|---|---|---|---|
| 1. مصدر المصادقة | دخول واحد للبائع والموظف عبر `sellers`/`seller_staff` (`LoginController.php:56-130`). الإدارة لا تملك أي دخول موبايل. | هوية إدارية من مزوّد `admins` عبر Endpoint منفصل. لا بيانات إدارة عبر شاشة البائع. | لا توجد مصادقة إدارة موبايل؛ مسار الدخول الوحيد هو مسار البائع. | VERIFIED | P1 |
| 2. نوع الرمز والنطاق | رمز نص عادي 50 حرفاً، بلا تاريخ انتهاء في الكود، وبلا قدرات. `SellerPrincipal` يمثل المالك/الموظف/المفتاح فقط. | رموز منفصلة بقدرات (abilities)، قصيرة العمر، مع تدوير للرمز المُحدَّث، مُجزّأة في التخزين، وجمهور (audience) منفصل. | كل البنود مفقودة للإدارة؛ الرموز الحالية بلا انتهاء. | VERIFIED | P1 |
| 3. اكتشاف الدور | الخادم يحدد الهوية من قاعدة البيانات (`SellerApiAuthMiddleware.php:43-57`)، لكن العميل لا يعرف نوع الهوية: الدخول يعيد الرمز فقط (`LoginController.php:82`, `:114`)، و`seller-info` لا يحوي حقل دور أو صلاحيات (قرأت `SellerController.php:207-240`). | خادم يصدر `principal_type` وقدرات الدور في Endpoint مخصص؛ العميل لا يقرر. | الخادم سليم اليوم لأنه لا توجد هوية إدارية؛ لا يوجد مطالب دور (role claims) لأي هوية. | VERIFIED | P1 |
| 4. التنقل | شاشة واحدة؛ كل بلاطات "المزيد" ظاهرة دون شرط (`lavkooappseller:lib/features/menu/screens/more_screen.dart:72-176`)، والتنقل بـ `Navigator.push` مباشرة (`more_screen.dart:231-232`). لا جدول مسارات. | جذر تنقل منفصل للإدارة بحارس يعتمد على الهوية المستلمة من الخادم؛ شاشات البائع لا تُبنى للإدارة والعكس. | لا توجد بوابة تنقل مبنية على الهوية. الإخفاء يتم بالنسخ فقط عند تطبيق الصلاحيات (والشاشات تظهر للموظف ثم يُرفض بالخادم). | VERIFIED | P1 |
| 5. تفويض الـ API | طبقة البائع: `seller_api_auth` + `seller_can` + `seller_owner` (`bootstrap/app.php:124-127`). طبقة الإدارة: وسيط الويب `admin` (`bootstrap/app.php:111`). لا قدرات إدارية على REST. الرمز المجهول يُرفض بـ 401 (`SellerApiAuthMiddleware.php:90-92`). | كل مسار إدارة يعلن قدرة صريحة، ويرفض أي هوية بائع بالبنية. | طبقة قدرات الإدارة غير موجودة. بالإضافة إلى ذلك توجد واجهة بائع قديمة (مؤشر مقيّد `SEC-G20-03`، القسم 9.2). | VERIFIED | P1 |
| 6. نطاق البيانات (كل الدول مقابل دولة) | كل بيانات البائع تُنطَق على `$request->seller->id` (`SellerControlTowerController.php:50`). لا نموذج سوق (market) ولا كيان قانوني. الدولة حقل نصي واحد على البائع (`lavkoo:app/Models/Seller.php:18`, `:47`, `:74`). العملة حقل لكل صف (`Order.php:32`, `:95`, `:153`). | نطاق كلي لكل الأسواق مع تقسيم إلزامي حسب السوق والكيان القانوني والعملة؛ لا جمع بين العملات. | لا توجد بنية سوق/كيان قانوني. اسم الدولة لا يعني المتجر أو الكيان. | VERIFIED (نماذج)، INFERRED (الجداول الفعلية) | P1 |
| 7. عزل الكاش و FCM | `DioClient` مفرد يحمل رمزاً واحداً (`lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:35`, `:49`, `:77-90`). المتحكمات جذرية في `MultiProvider` (`lavkooappseller:lib/main.dart:175-191`) ومسجلة كـ factory (`lib/di_container.dart:438`). الخروج لا يعيد ضبطها (`lib/features/menu/widgets/sign_out_confirmation_dialog_widget.dart:87-88`). المواضيع: `lavkoo_seller` و`maintenance_mode_start_vendor` لكل أجهزة البائعين (`lib/utill/app_constants.dart:400-401`؛ `lib/features/auth/domain/repositories/auth_repository.dart:89-90`). رمز الدفع الفوري واحد لكل **متجر** يكتبه أي مبدأ (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:628-631`). | مفاتيح كاش مقيدة بالهوية والسوق؛ إفراغ عند تبديل السوق أو الهوية؛ مواضيع FCM حسب السوق والدور؛ لا مواضيع بائع للإدارة ولا العكس. | كل البنود مفقودة. | VERIFIED | P1 |
| 8. سجل التدقيق | `AuditLogger` يسجل المنفّذ (إدارة/بائع/موظف/مفتاح) مع IP ووكيل المستخدم (`lavkoo:app/Services/AuditLogger.php:119-144`). `record()` يبتلع الأخطاء (`AuditLogger.php:62-64`)، ويوجد `recordOrFail()` (`AuditLogger.php:80`). لا يوجد نوع منفّذ للإدارة عبر الموبايل. | كل قراءة لبيانات حساسة وكل تصدير وكل إجراء يُسجَّل بمنفذ إداري ومحطة وسوق وقيم قبل/بعد، وبلا فقد صامت. | نوع منفّذ جديد مطلوب؛ الافتراضي يتجاهل تعذر كتابة السجل ولا يفشل الإجراء. | VERIFIED | P1 |
| 9. الإجراءات الحساسة والموافقة | لا إجراءات إدارة موبايل. على جهة البائع توجد بوابة مالك فقط (`EnsureSellerIsOwner`) وصلاحية سحب. لا step-up ولا MFA (انظر القسم 2.2). | معاينة مسبقة (preflight) + تأكيد صريح + إعادة مصادقة/MFA حديثة + موافقة مزدوجة حيث تلزم + أثر تدقيق. | كل البنود مفقودة؛ المرحلة 3 فقط ومشروطة بموافقة منفصلة. | VERIFIED (الغياب) | P1 |
| 10. إدارة الجلسات والإبطال عن بعد | لا قائمة جلسات للبائع أو الإدارة؛ لا إبطال عن بعد للرمز؛ الإبطال الوحيد للموظف من المالك (`SellerSecurityController.php:275`)؛ الإدارة تبطل الجلسة الحالية فقط (`AdminService.php:20-24`). | قائمة أجهزة وجلسات، وإبطال عن بعد يسري على الطلب التالي، وإعادة مصادقة قبل الإبطال الجماعي. | مفقود للإدارة، وناقص للبائع. | VERIFIED | P1 |
| 11. التخزين على الجهاز | الرمز في `SharedPreferences` نص عادي (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:118`)، بينما كلمة المرور في التخزين الآمن (`lavkooappseller:lib/data/datasource/local/secure_credential_store.dart:8-12`, `:51-58`) ويُملأ حقل الدخول منها ("تذكرني"، `lib/features/auth/controllers/auth_controller.dart:311-312`). | رمز الإدارة في التخزين الآمن فقط (Keychain/Keystore)؛ لا حفظ لبيانات اعتماد الإدارة عبر مسار "تذكرني". | رمز البائع نص عادي؛ مسار "تذكرني" موجود ويجب ألا يُستخدم للإدارة. | VERIFIED | P1 (الرمز) / P2 (مسار التذكّر) |

---

## 4. ما هو ممنوع (FORBIDDEN)

هذه القائمة ملزمة لأي تنفيذ لاحق لـ GCC-20، وتُعد مخالفتها رفضاً للتسليم:

1. **علم من جهة العميل** (client-side flag) مثل `isSuperAdmin` أو `role=admin` أو `is_admin` في الاستجابة أو في التخزين المحلي، يُستخدم لفتح شاشة إدارة أو استدعاء Endpoint إدارة.
2. **قائمة بريد إلكتروني** (email allowlist) في التطبيق أو في الخادم كمعيار لدور الإدارة الخارقة.
3. **معرّف مستخدم ثابت** (hardcoded ID) في التطبيق. وينطبق الحكم على الخادم أيضاً في المسار الجديد: الاعتماد على المعرّف الرقمي 1 أو على `admin_role_id == 1` (القسم 9، F-11) ممنوع في الواجهة الجديدة ويُرحَّل إلى أدوار صريحة.
4. **رمز البائع أو الموظف أو مفتاح التكامل** يفتح شاشة أو Endpoint إدارة، أو يُستخدم لإصدار رمز بهوية إدارية (minting).
5. **بيانات اعتماد الإدارة** تُدخل عبر شاشة دخول البائع، أو تُحفظ عبر مسار "تذكرني" الحالي، أو تُرسل إلى `api/v3/seller/auth/login`.
6. **إجماليات مبالغ مجموعة عبر عملات** دون بيانات تحويل (معدل الصرف، المصدر، التاريخ/الوقت، وسعر التقرير). الإجمالي الموحّد يُسمح به فقط كتقرير مُحوّل صراحة بالبيانات أعلاه.
7. **اسم الدولة كبديل** للمتجر أو البائع أو المستودع أو الكيان القانوني.
8. **إخفاء زر فقط** كبديل للتحقق على الخادم.
9. **استخدام رموز Passport الخاصة بالعملاء** (حارس `api` على `App\User`، `lavkoo:config/auth.php:44-47`) لهوية الإدارة.
10. **مواضيع FCM مشتركة** بين البائع والإدارة أو بين الإدارة والعملاء، أو مواضيع بلا بُعد سوق.
11. **تسجيل قيمة `Authorization`** في أي build إنتاجي. التشخيص الحالي محصور في `kDebugMode` (`lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:60`)، ويطبع الترويسات في وضع debug (`logging_interceptor.dart:13`)، ويجب ألا تُنقل القيمة إلى أي سجل.
12. **تنفيذ أي إجراء حساس** بلا معاينة مسبقة وأثر تدقيق.
13. **التصدير** بلا علامة مائية (المنفّذ، الوقت، السوق)، أو بلا تسجيل تدقيق، أو من الكاش المشترك للجهاز.
14. **إعادة استخدام مدخل الدخول الحالي** للبائع لفتح الإدارة عبر "تبديل الحساب" أو "الوضع الإداري" داخل الجلسة نفسها.

---

## 5. عقد Backend مقترح لهوية Admin Mobile (تصميم فقط — PROPOSED)

كل بند في هذا القسم **PROPOSED**. لا كود ولا migration ولا مسار مُنشأ.

1. **PROPOSED — حارس مستقل:** حارس `admin_mobile` بمزوّد على `App\Models\Admin` وجدول رموز منفصل. لا يستخدم حارس `api` (Passport على `App\User`) ولا `SellerPrincipal`.
2. **PROPOSED — دور خارق صريح:** صلاحية مسماة (مثلاً `platform.super_admin`) على `AdminRole` وليس على `id == 1`. المسار الجديد لا يقرأ المعرّف الرقمي. الاعتماد على `id == 1` و`admin_role_id == 1` الحالي يبقى كما هو في الويب إلى أن يُقرر المالك خلاف ذلك.
3. **PROPOSED — دخول منفصل:** `POST /admin-mobile/v1/auth/login` منفصل عن نقطة البائع، تحت throttle، ولا يقبل إلا هوية إدارية. الاستجابة الأولى تطلب تحدي MFA، والرمز يُصدر بعد التحقق.
4. **PROPOSED — MFA إلزامي:** TOTP أو مزوّد معتمد. لا يوجد MFA في الكود الحالي (القسم 2.2). تحديد المزوّد قرار مالك (القسم 10).
5. **PROPOSED — رموز قصيرة العمر:** رمز وصول (مثلاً 15 دقيقة) ورمز تحديث يُدوَّر عند كل استخدام. كلاهما مُجزّأ في التخزين، ولكل منهما `device_id` وقدرات (abilities).
6. **PROPOSED — قدرات (abilities) مسماة:** مثل `admin.read.overview`، و`admin.read.market.{code}`، و`admin.read.finance`، و`admin.write.ops` (المرحلة 2)، و`admin.write.critical` (المرحلة 3، مع موافقة إضافية). كل Endpoint يعلن قدرته.
7. **PROPOSED — قائمة الأجهزة والجلسات:** `GET /admin-mobile/v1/sessions`، و`DELETE /admin-mobile/v1/sessions/{id}`. الإبطال يُفحَص في كل طلب (لا توكن مستقل بلا فحص)، ويسري على الطلب التالي.
8. **PROPOSED — هوية من الخادم فقط:** `GET /admin-mobile/v1/me` يعيد `principal_type = platform_super_admin` وقدرات الهوية وأسواقها المسموحة وأعلام الميزة. العميل لا يستنتج الدور من أي حقل آخر.
9. **PROPOSED — بوابة الميزة وإصدار التطبيق:** علم إعداد `admin_mobile_enabled` وحد أدنى لإصدار التطبيق. عند الإيقاف تُرجع المسارات 404 (وليس 403) حتى لا يُكشف وجودها.
10. **PROPOSED — نطاق السوق في كل Endpoint:** معامل `market` بقيم `SA|AE|KW|QA|BH|OM|all`، يُتحقق منه على الخادم مقابل أسواق الهوية. المعامل إلزامي ما عدا `overview`.
11. **PROPOSED — التدقيق:** كل قراءة لبيانات مالية أو عملاء وكل تصدير ومعاينة إجراء تُسجَّل عبر `recordOrFail()` بنوع منفّذ جديد `admin_mobile` ومعرّف الجهاز والسوق.
12. **PROPOSED — الإجراءات الحساسة:** ثلاث خطوات: `preflight` (يعيد الشروط والآثار) ثم `confirm` بعد إعادة مصادقة MFA حديثة مع مفتاح تكرار (idempotency) ثم التنفيذ مع أثر تدقيق. تُفعَّل فقط في المرحلة 3.
13. **PROPOSED — معدلات الطلب:** حد لكل هوية وكل جهاز، وحد أشد لطلبات التصدير.
14. **PROPOSED — الإشعارات:** جدول أجهزة إدارة مستقل عن `sellers.cm_firebase_token` (`SellerController.php:628-631`)، ومواضيع مُسمّاة بالدور والسوق (القسم 6).

### 5.1 قائمة Endpoints للقراءة فقط (المرحلة 1) — PROPOSED

| Endpoint | الغرض | ملاحظات التصميم |
|---|---|---|
| `GET /admin-mobile/v1/me` | الهوية والقدرات والأسواق والأعلام | لا بيانات أعمال |
| `GET /admin-mobile/v1/overview` | لوحة كل الأسواق | مجاميع مجمعة حسب السوق والكيان والعملة؛ حدود زمنية إلزامية |
| `GET /admin-mobile/v1/markets` | قائمة الأسواق الستة وحالتها وحداثة البيانات | عرض حالة المزامنة (freshness) |
| `GET /admin-mobile/v1/markets/{market}/summary` | ملخص سوق واحد | يتطلب `admin.read.market.{code}` |
| `GET /admin-mobile/v1/markets/{market}/orders/summary` | عدد الطلبات والحالات والمعلّق والمرتجعات | استعلامات محدودة بنافذة زمنية |
| `GET /admin-mobile/v1/markets/{market}/sales` | المبيعات حسب الكيان القانوني والعملة | بلا إجمالي موحّد بلا تحويل |
| `GET /admin-mobile/v1/alerts` | التنبيهات حسب الخطورة والسوق | إزالة التكرار؛ مقتطفات خالية من البيانات الشخصية |
| `GET /admin-mobile/v1/health/integrations` | الدفع (webhooks) وOdoo والمنصات والشحن | حالة فقط دون أسرار |
| `GET /admin-mobile/v1/stock/health` | صفري/منخفض المخزون والبيع الزائد | قراءة فقط |
| `GET /admin-mobile/v1/audit` | سجل الإجراءات والقراءات الحساسة للإدارة | قراءة فقط |
| `GET /admin-mobile/v1/sessions` و`DELETE /admin-mobile/v1/sessions/{id}` | إدارة الجلسات | الإبطال يسري فوراً |

**لا تصدير في المرحلة 1.** التصدير يُضاف في المرحلة 2 مع العلامة المائية والتدقيق.

### 5.2 مصادر تجميع موجودة يمكن إعادة استخدامها بالخدمات (INFERRED من أسماء الملفات)

`lavkoo:app/Services/Monitoring/Panels/` يحوي `OrderIntegrityPanel` و`InventoryIntegrityPanel` و`PaymentsPanel` و`IntegrationsPanel` و`AlertsPanel` و`HealthScoreService` (`lavkoo:app/Services/Monitoring/HealthScoreService.php`). هذه لوحات الويب ومرتبطة بصلاحيات `MonitoringPermissionService` (`lavkoo:app/Services/Monitoring/MonitoringPermissionService.php:70-82`). المقترح استخلاص منطق التجميع إلى خدمات مشتركة بدل نسخه، مع إبقاء المتحكم الجديد نحيفاً. لم تُقرأ هذه الملفات بالتفصيل (انظر القسم 1.4).

---

## 6. تصميم تطبيق مقترح (PROPOSED)

1. **تطبيق واحد بهويتين:** شاشة دخول البائع لا تتغير. دخول الإدارة شاشة منفصلة ومدخل منفصل (ليس حقلاً في شاشة البائع). بعد الدخول يقرر الخادم الجذر (`principal_type`) من `GET /admin-mobile/v1/me` أو من مسار البائع الحالي، وليس العميل.
2. **فصل التنقل:** جذران (`SellerShell` و`AdminShell`) مع حارس جذر لكل منهما يعتمد الهوية المستلمة من الخادم. شاشات البائع لا تستورد مستودعات الإدارة، ومجلد `admin_mobile` منفصل (تسمية مقترحة فقط).
3. **مخزن حالة السوق (Market scope):** كائن قيمة يحمل السوق المختار، ويُمرَّر صراحة إلى كل مستودع. لا يُقرأ من متغير عام.
4. **مفاتيح الكاش:** `principal|market|resource|params`. عند تبديل السوق: إلغاء الطلبات الجارية (CancelToken) وإفراغ كل كاش مرتبط بالسوق السابق وإعادة ضبط المتحكمات ذات النطاق السوقي. هذا يعالج F-10.
5. **"كل الأسواق":** يُعرض فقط من مسارات `overview`. التفاصيل تُفتح بعد اختيار سوق.
6. **العملات:** مجموعات حسب العملة. الإجمالي الموحّد غير ممكن إلا عند توفر بيانات التحويل من الخادم (المعدل، المصدر، التاريخ). لا عملة افتراضية.
7. **مواضيع FCM:** اشتراك حسب الدور والسوق تحدده استجابة الخادم، مع إلغاء الاشتراك عند الخروج وعند تبديل الهوية. الروابط العميقة تُعاد من الخادم بعد التحقق من الهوية عند الفتح، لا من بيانات الإشعار وحدها.
8. **التصدير:** طلب من الخادم فقط. الملف يحمل علامة مائية (المنفّذ، السوق، الوقت). يُخزَّن خارج التخزين المشترك، ويتطلب جلسة MFA حديثة. لا تصدير في المرحلة 1.
9. **الجلسة:** رمز قصير العمر في التخزين الآمن (Keychain/Keystore) وليس في `SharedPreferences`. قفل بعد عدم النشاط، وشاشة أجهزة مع إبطال عن بعد، وخروج يستدعي الخادم.
10. **بلا تخزين دون اتصال** لبيانات الإدارة في المرحلة 1 (أو تشفير مع مدة صلاحية قصيرة وقرار مالك).
11. **الإيقاف والتراجع:** بوابة ميزة من الخادم تُخفي الجذر بالكامل؛ الإيقاف لا يؤثر على تطبيق البائع الحالي.
12. **الواجهة:** العربية أولاً مع RTL، والبيانات الرقمية بأرقام لاتينية حسب أسلوب التطبيق. لا تغيير على شاشات البائع الحالية.

---

## 7. المراحل المقترحة (PROPOSED)

| المرحلة | النطاق | معايير الخروج | الموافقة المطلوبة |
|---|---|---|---|
| **0 — الأساس (تصميم وأمن)** | قرار الهوية والـMFA، ونقل المؤشرات `SEC-G20-*` إلى الملحق المقيّد وإغلاقها، وتحديد قائمة الأسواق والكيانات، وقرار نوع الهوية (`platform.super_admin`) | المؤشرات الأمنية المتعلقة بالمصادقة مُغلقة بأدلة ومراجعة مخوّلة؛ وثيقة عقد الهوية موافق عليها | مالك المشروع + مراجع أمني مخوّل. **Phase 0 لا تبدأ** إلا بموافقة كتابية منفصلة (`docs/gcc/DECISIONS.md`) |
| **1 — المراقبة للقراءة فقط** | مسارات القسم 5.1 فقط، ومفاتيح الجلسات، ومصادقة MFA، وفصل التنقل، وعزل الكاش والمواضيع | كل اختبارات القسم 8 للمرحلة 1 خضراء؛ لا وصول لرمز بائع أو موظف إلى أي مسار إدارة؛ لا بيانات حساسة في الكاش بعد تبديل السوق؛ اختبار اختراق مستقل مكتوب وموقّع؛ تراجع بالعلم مُجرَّب؛ بيانات الإنتاج تُقرأ من نسخة مخصصة أو عبر قرار مالك | مالك المشروع (إطلاق) + مسؤول الأمن (اختبار) + قرار إنتاجي منفصل |
| **2 — عمليات محدودة** | تعيين مشكلات الدعم، وطلب مراجعة مخزون، وإعدادات تشغيلية محلية محددة، واعتماد مهام موظفي البائع حسب السياسة، والتصدير مع العلامة المائية | تدقيق كامل لكل إجراء مع أثر قبل/بعد؛ لا فقد صامت في السجل (`recordOrFail`)؛ قائمة الصلاحيات المسموحة للدور موثقة؛ عدم تأثر سير البائع الحالي (اختبارات الانحدار القسم 8/T-21)؛ مراجعة أداء للاستعلامات | مالك المشروع + مالك العمليات + مراجع أمني |
| **3 — إجراءات حساسة بموافقة إضافية** | تفعيل/إيقاف المتجر، وتعديل حساب الدفع أو وجهة التسوية، ونشر السياسات القانونية، وإطلاق المخزون، وتغيير الصلاحيات، والاسترداد لمبالغ كبيرة، وتفعيل/تعطيل التكاملات | معاينة مسبقة وتأكيد وMFA حديث وموافقة مزدوجة حيث تلزم؛ عدم كسر الـwebhooks والاستردادات للطلبات السابقة عند الإيقاف؛ كل إجراء له دليل تشغيل (runbook)؛ اختبارات تكامل مع المزودين في بيئة اختبار | مالك المشروع + موافقة مالية/قانونية حيث تلزم + موافقة إنتاج منفصلة لكل فئة إجراء |

**ملاحظة:** كل مرحلة تحتاج قراراً كتابياً منفصلاً وفق `DECISIONS.md`. التخطيط هنا لا يفتح أي مرحلة.

---

## 8. مصفوفة الاختبارات (إثبات منع التسرب ورفع الصلاحيات)

الأوصاف عامة؛ لا تتضمن خطوات تنفيذ ولا حمولات. المستوى: BE = اختبار feature في الخلفية؛ APP = اختبار widget/unit في Flutter؛ CT = اختبار عقد (contract).

| ID | الهوية (Principal) | المحاولة (وصف عام) | النتيجة المتوقعة | المستوى | يُثبت متطلب GCC-20 |
|---|---|---|---|---|---|
| T-01 | بائع (owner) | استدعاء Endpoint من `admin-mobile/v1/overview` برمز البائع | رفض بالرمز الموثق للهوية (401 أو 403)، بلا بيانات | BE | الفصل بين المنصة والبائع |
| T-02 | موظف بدور يمنح كل الصلاحيات | استدعاء أي Endpoint إدارة برمز الموظف | رفض بنفس الشكل، بلا بيانات | BE | عدم تصعيد الصلاحية بالدور |
| T-03 | مفتاح تكامل بكل النطاقات | استدعاء Endpoint إدارة بمفتاح التكامل | رفض؛ المفتاح لا يُعامل كهوية إدارية | BE | عدم استخدام رموز البائع لفتح الإدارة |
| T-04 | هوية إدارية | استدعاء أي مسار `seller_api_auth` في v3 برمز الإدارة | رفض 401؛ لا تُنشأ هوية بائع | BE | فصل الرموز بالجمهور (audience) |
| T-05 | هوية إدارية بعد الدخول بكلمة المرور دون MFA | استدعاء Endpoint قراءة | رفض؛ لا رمز وصول يُصدر قبل MFA | BE | MFA إلزامي |
| T-06 | هوية إدارية برمز منتهٍ | استدعاء Endpoint قراءة | رفض 401 مع رسالة موثقة | BE | رموز قصيرة العمر |
| T-07 | هوية إدارية على جهاز تم إبطاله من جهاز آخر | استدعاء Endpoint بعد الإبطال مباشرة | رفض في الطلب التالي | BE | الإبطال عن بعد |
| T-08 | بيانات اعتماد إدارية | محاولة الدخول عبر نقطة دخول البائع | لا رمز؛ رد موحد لا يكشف وجود الحساب | BE/CT | عدم تمرير بيانات الإدارة عبر شاشة البائع |
| T-09 | بائع | فتح مسار جذر الإدارة في التطبيق | الجذر لا يُبنى؛ لا تُعرض الشاشة | APP | فصل التنقل |
| T-10 | بائع | محاكاة استجابة `me` تحوي حقل إدارة من جهة العميل | الشيفرة لا تفتح الإدارة؛ استدعاءات الإدارة ما زالت مرفوضة من الخادم | APP + BE | لا علم من جهة العميل |
| T-11 | مدير سوق واحد | طلب سوق آخر غير مسموح به | رفض بنفس الشكل؛ لا بيانات | BE | النطاق حسب السوق |
| T-12 | إدارة كاملة | كل استدعاءات القراءة تحمل معامل السوق ما عدا `overview` | تحقق على الخادم؛ الطلب بلا سوق يُرفض | CT | انتشار اختيار السوق |
| T-13 | إدارة كاملة | تبديل السوق من A إلى B | لا تظهر بيانات A بعد التبديل (الكاش والطلبات الجارية) | APP | عزل الكاش |
| T-14 | إدارة كاملة | عرض "كل الأسواق" بعملات متعددة | مجاميع مفصولة حسب العملة والكيان مع بيانات التحويل؛ لا إجمالي موحّد بلا تحويل | BE | منع جمع العملات |
| T-15 | جهاز إدارة | تسجيل رمز الإشعارات | الاشتراك في مواضيع الإدارة فقط؛ لا `lavkoo_seller` ولا مواضيع العملاء | CT | عزل FCM |
| T-16 | موظف بائع | تسجيل رمز إشعارات من جهاز الموظف | رمز جهاز الموظف لا يُكتب في حقل رموز إشعارات الإدارة؛ استبدال رمز المتجر يبقى محكوماً بقرار المالك (F-08) | BE | عزل FCM بين الهويات |
| T-17 | إدارة كاملة | إجراء حساس دون معاينة أو دون MFA حديث | رفض؛ لا تغيير في الحالة | BE | الإجراءات الحساسة |
| T-18 | إدارة كاملة | إجراء حساس بعد المعاينة وMFA | تنفيذ مرة واحدة (idempotency) مع سجل قبل/بعد ومنفّذ ومحطة وسوق | BE | التدقيق |
| T-19 | موظف بائع | طلب تصدير من مسار الإدارة | رفض؛ لا ملف | BE | التصدير محصور |
| T-20 | إدارة كاملة | تصدير مسموح | ملف بعلامة مائية وتدقيق؛ لا ملف في التخزين المشترك | BE + APP | التصدير والتدقيق |
| T-21 | بائع ومالك | انحدار: `seller-info` و`orders/list` و`transactions` قبل وبعد التنفيذ | استجابات متطابقة بنيوياً (لقطة عقد) | CT | استمرار سلوك البائع الحالي (شرط GCC-20 في `origin/main:docs/gcc/DECISIONS.md`) |
| T-22 | هوية إدارية | استدعاء أي مسار v2 بالرمز | رفض؛ لا تفويض عبر المسار القديم | BE | إغلاق المسار الموازي |
| T-23 | إدارة كاملة | خروج من التطبيق | الرمز المحلي يُحذف ويُبطَل على الخادم؛ الطلب التالي مرفوض | APP + BE | الإبطال |
| T-24 | إدارة كاملة | حفظ الرمز | الرمز في التخزين الآمن فقط؛ لا في `SharedPreferences`؛ لا يُحفظ في "تذكرني" | APP | التخزين |
| T-25 | بناء الإنتاج | تشغيل التطبيق | لا تُطبع الترويسات (لا `Authorization`) | APP | الكشف في السجلات |
| T-26 | ميزة مُعطّلة من الخادم | استدعاء أي مسار إدارة | 404 موحد؛ الجذر مخفي في التطبيق | BE + APP | بوابة الميزة والتراجع |
| T-27 | بائع | تعليق البائع من لوحة الإدارة | يُبطل الرمز ويرفض الطلب التالي (مسار موجود؛ التحقق انحداري) | BE | الإبطال عند التعليق |
| T-28 | إدارة | قراءة بيانات حساسة (عملاء/مالية) | سجل تدقيق يُكتب، ويفشل الطلب إن فشل السجل | BE | عدم الفقد الصامت |

---

## 9. جدول النتائج

### 9.1 النتائج (Findings)

الخطورة وفق: P0 / P1 / P2 / INFO. الحالة: VERIFIED / INFERRED / UNKNOWN. كل الاستشهادات `lavkoo:` في HEAD `f2e0121`، و`lavkooappseller:` في `8ea0092` (وlib مطابق في `9ea6423`).

| ID | العنوان | الخطورة | الحالة | الأدلة | الأثر | التوصية (توثيقية) |
|---|---|---|---|---|---|---|
| F-01 | لا توجد هوية إدارية على أي API | P1 | VERIFIED | `lavkoo:config/auth.php:49-52, 88-91`؛ `lavkoo:app/Models/Admin.php:34-36`؛ بحث `routes/rest_api` (القسم 2.3) | لا يوجد أساس لتطبيق إدارة موبايل؛ أي بناء يبدأ من الصفر | PROPOSED: حارس `admin_mobile` ومزوّد مستقل (القسم 5، بند 1) |
| F-02 | رموز الدخول نص عادي بلا انتهاء | P1 | VERIFIED | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:100, 109`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:50-51, 99-100`؛ مقابل `lavkoo:app/Services/Marketplace/SellerApiKeyService.php:56` و`lavkoo:app/Models/SellerApiKey.php:36-43` | تسريب قاعدة البيانات يعني جلسات حية؛ لا سقف زمني | PROPOSED: تجزئة وانتهاء وتدوير للرموز الجديدة، ترحيل تدريجي للبائعين (قرار مالك لأثر الترحيل على المستخدمين) |
| F-03 | لا قائمة جلسات ولا إبطال عن بعد للمالك؛ لا تسجيل خروج خادمي | P1 | VERIFIED | `lavkoo:routes/rest_api/v3/seller.php:523` (الإبطال للمفاتيح فقط)؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerSecurityController.php:275`؛ `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:135-148` | لا يستطيع المالك إنهاء جلسة مسروقة على جهاز فُقد | PROPOSED: قائمة جلسات وإبطال للبائع والإدارة معاً |
| F-04 | رمز البائع في `SharedPreferences` (نص عادي) | P1 | VERIFIED | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:118`؛ `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:35`؛ مقابل `lavkooappseller:lib/data/datasource/local/secure_credential_store.dart:8-12` | الجهاز المكسور يكشف الرمز | PROPOSED: نقل الرمز إلى التخزين الآمن (لكل البائعين) وفي الإدارة إلزامياً |
| F-05 | مسار "تذكرني" يحفظ كلمة المرور في التخزين الآمن ويملأ حقل الدخول | P2 | VERIFIED | `lavkooappseller:lib/features/auth/controllers/auth_controller.dart:311-312`؛ `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:166-169`؛ `lavkooappseller:lib/data/datasource/local/secure_credential_store.dart:51-58` | خطر إن استُخدم المسار لبيانات الإدارة | PROPOSED: لا "تذكرني" في دخول الإدارة (ممنوع في القسم 4، بند 5) |
| F-06 | العميل لا يعرف الدور؛ كل بلاطات "المزيد" ظاهرة | P1 | VERIFIED | `lavkooappseller:lib/features/menu/screens/more_screen.dart:72-176`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:82, 114`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:207-240` (لا حقل دور) | تجربة موظف مرتبكة؛ التنقل لا يُبنى على الهوية | PROPOSED: `principal_type` وقدرات من الخادم (القسم 5، بند 8)؛ إخفاء بالقدرة مع بقاء التحقق على الخادم |
| F-07 | التنقل بـ `Navigator.push` دون جدول مسارات أو حارس | P2 | VERIFIED | `lavkooappseller:lib/features/menu/screens/more_screen.dart:231-232`؛ `lavkooappseller:lib/features/dashboard/screens/dashboard_screen.dart:74-77` | لا نقطة مركزية لفصل الجذور | PROPOSED: جذران منفصلان مع حارس جذر (القسم 6، بند 2) |
| F-08 | رمز الدفع الفوري واحد لكل متجر، يكتبه أي مبدأ | P1 | VERIFIED | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:628-631` | جهاز الموظف يستبدل جهاز المالك؛ إشعارات الإدارة لا تُفصل عن إشعارات المتجر | PROPOSED: جدول أجهزة مستقل لكل هوية (القسم 5، بند 14)؛ قرار مالك على سلوك الاستبدال |
| F-09 | مواضيع عامة للبائعين بلا بُعد سوق أو دور | P1 | VERIFIED | `lavkooappseller:lib/utill/app_constants.dart:400-401`؛ `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:89-90, 154-155`؛ `lavkoo:app/Services/SellerIntelligence/SellerNotifier.php:112` | لا يمكن إرسال إشعار لسوق واحد أو لدور واحد | PROPOSED: مواضيع `admin.{role}.{market}` و`seller.{market}` مع إدارة اشتراك من الخادم |
| F-10 | متحكمات جذرية لا تُعاد ضبطها عند الخروج | P1 | VERIFIED | `lavkooappseller:lib/main.dart:175-191`؛ `lavkooappseller:lib/di_container.dart:438`؛ `lavkooappseller:lib/features/menu/widgets/sign_out_confirmation_dialog_widget.dart:87-88` | بيانات متجر أو سوق سابق تبقى في الذاكرة بعد تبديل الهوية | PROPOSED: مفاتيح كاش بالهوية والسوق، وإعادة ضبط المتحكمات عند الخروج وتبديل السوق (القسم 6، بند 4) |
| F-11 | تحديد الدور الخارق بمعرّفات رقمية ثابتة (صف 1 ودور 1) في أماكن متعددة | P1 | VERIFIED | `lavkoo:app/Http/Controllers/Admin/Auth/LoginController.php:51, 55`؛ `lavkoo:app/Http/Middleware/AdminMiddleware.php:21`؛ `lavkoo:app/Http/Controllers/LoginController.php:84`؛ `lavkoo:app/Utils/Helpers.php:386`؛ `lavkoo:app/Services/Monitoring/MonitoringPermissionService.php:78` | تعريفان مختلفان للدور الخارق في الكود نفسه؛ ممنوع في الواجهة الجديدة | PROPOSED: دور صريح بصلاحية مسماة للمسار الجديد؛ ترحيل الويب لاحقاً بقرار مالك |
| F-12 | لا تحقق ثانٍ لدخول الإدارة | P1 | VERIFIED (الغياب) | `lavkoo:app/Http/Controllers/Admin/Auth/LoginController.php:39-66`؛ `lavkoo:app/Services/AdminService.php:12-18`؛ بحث `two_factor|2fa|totp|mfa` بلا نتيجة | الدخول بكلمة المرور وحدها لوحة قيادة مالية | PROPOSED: MFA إلزامي للمسار الجديد (القسم 5، بند 4)؛ مزوّد MFA قرار مالك |
| F-13 | جلسة الإدارة في الويب بلا قائمة أجهزة؛ الافتراضي `file` | P2 | VERIFIED | `lavkoo:config/session.php:21`؛ `lavkoo:app/Services/AdminService.php:20-24` | لا إبطال جماعي للإدارة | PROPOSED: قائمة أجهزة ضمن مسار الموبايل (القسم 5، بند 7)؛ القرار للويب معلّق |
| F-14 | مسجّل التدقيق لا يفشل الإجراء عند تعذر كتابة السجل (الافتراضي) | P1 | VERIFIED | `lavkoo:app/Services/AuditLogger.php:62-64`؛ `lavkoo:app/Services/AuditLogger.php:80` (`recordOrFail`) | إجراء حساس قد يكتمل ولا يبقى له أثر عند تعذر الكتابة | PROPOSED: `recordOrFail` لكل إجراء وقراءة حساسة للإدارة الموبايل |
| F-15 | لا بُعد سوق ولا كيان قانوني في نماذج البيانات | P1 | VERIFIED (النماذج)، INFERRED (الجداول) | `lavkoo:app/Models/Seller.php:18, 47, 74`؛ `lavkoo:app/Models/Order.php:32, 95, 153`؛ قائمة `app/Models` (لا نموذج سوق أو كيان) | لا تجميع صحيح حسب الكيان أو السوق؛ خطر جمع عملات | UNKNOWN لحين مراجعة الجداول الفعلية؛ PROPOSED: تحديد مصدر السوق والكيان قبل المرحلة 1 |
| F-16 | لوحات المراقبة موجودة للويب فقط | P2 | VERIFIED (الوجود)، INFERRED (القابلية لإعادة الاستخدام) | `lavkoo:app/Services/Monitoring/Panels/*`؛ `lavkoo:app/Services/Monitoring/MonitoringPermissionService.php:70-82` | تكرار منطق إن نُسخت اللوحات | PROPOSED: استخلاص خدمات مشتركة (القسم 5.2) |
| F-17 | تسجيل `Authorization` في وضع debug فقط | P2 | VERIFIED | `lavkooappseller:lib/data/datasource/remote/dio/logging_interceptor.dart:13`؛ `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:60` | لا خطر في الإصدار الإنتاجي؛ خطر في أجهزة مطوّر | PROPOSED: إبقاء التقييد وإضافة اختبار T-25 |
| F-18 | لا قفل حيوي ولا مهلة خمول في التطبيق | P2 (للبائع) / P1 (لوضع الإدارة) | VERIFIED (بحث) | بحث `local_auth|biometric|idle` في `lib/` بلا نتيجة ذات صلة | جهاز غير مقفل يفتح الإدارة | PROPOSED: قفل الإدارة إلزامي (القسم 6، بند 9) |
| F-19 | `SellerPrincipal` لا يحمل بُعد المنصة؛ المالك = كل الصلاحيات داخل متجره | INFO | VERIFIED | `lavkoo:app/Services/Marketplace/SellerPrincipal.php:21-38, 71-74, 93-96` | تصميم سليم للبائع؛ توسيعه ليشمل المنصة يكسر الفصل | PROPOSED: `PlatformPrincipal` منفصل بدل توسيع `SellerPrincipal` |
| F-20 | حفظ الإيجابيات: الإيقاف والتجديد وإعادة الضبط | INFO | VERIFIED | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:46-51` (فحص الحالة عند كل طلب)؛ `lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:34-38`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:409-411` | قواعد قابلة لإعادة الاستخدام | إعادة استخدام النمط في الإدارة |

### 9.2 مؤشرات أمنية مقيّدة (Security triage pointers)

المسارات التالية تشير إلى مسائل أمنية مقيّدة، وفق قواعد النشر **لا تُفصَّل هنا**. تُقدَّم كمؤشرات فقط، ومعرّفاتها مؤقتة (provisional) حتى يُسنِد الملحق المقيّد أرقامه:

SEC-G20-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-G20-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-G20-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-G20-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

| المؤشر | الفئة المحايدة | تقدير الخطورة الأولي | الحالة |
|---|---|---|---|
| SEC-G20-01 | مسار مصادقة (مقيّد) | P0 (بانتظار الفرز) | VERIFIED (الوجود)، UNKNOWN (الإمكانية الفعلية) |
| SEC-G20-02 | مسار إشعارات (مقيّد) | P0 (بانتظار الفرز) | VERIFIED (الوجود)، UNKNOWN (الإمكانية الفعلية) |
| SEC-G20-03 | مسار تكامل قديم (مقيّد) | P1 (بانتظار الفرز) | VERIFIED (الوجود)، UNKNOWN (الإمكانية الفعلية) |
| SEC-G20-04 | مسار تسجيل (مقيّد) | P2 | VERIFIED (الوجود) |

لم يُكتب شيء في الملحق المقيّد من هذا التكليف، ولم يُفتح. المطلوب من المالك أو وكيل الأمن (agent E) إدخال التفاصيل في الملحق عبر القناة الخاصة، ثم إسناد الأرقام الرسمية وإغلاق المؤشرات بعد المراجعة. انظر القسم 10.

### 9.3 ERRATA المقترحة (لا تعديل على الوثائق المعتمدة)

- لا تعديل مقترح على `DECISIONS.md` أو `STATUS.md`. الصياغة الحالية في القرار GCC-20 (`origin/main:docs/gcc/DECISIONS.md`) متوافقة مع النتائج: الإدارة "هوية منفصلة" و"ليست رمز بائع موسّعاً"، والكود الحالي لا يحقق ذلك بعد (F-01 وF-06). **لا تعارض مباشر مع قرار معتمد يستدعي إعادة فتح.**

---

## 10. الحدود (ما لا يُحسم بالقراءة الساكنة)

1. **إعدادات الإنتاج:** `.env`، و`SESSION_DRIVER`، و`APP_ENV`، وإعدادات الـ`throttle`، وحدود FCM. لم تُفتح ولا يمكن إثبات سلوكها من الكود وحده (UNKNOWN).
2. **التحقق التشغيلي للمؤشر المقيّد (SEC-G20-02):** لا يُحسم ساكناً؛ التفاصيل في الملحق المقيّد (UNKNOWN).
3. **توفر MFA:** اختيار المزوّد (TOTP محلي أو مزود SMS) وتكلفته وتوافقه مع المنصتين، وهو قرار مالك وليس قراراً تقنياً ساكناً (UNKNOWN).
4. **حدود مزوّد الإشعارات:** حد الاشتراك بالمواضيع، وعدد المواضيع لكل جهاز، وسياسة الإرسال لكل سوق. تحتاج وثائق المزود وحسابه (UNKNOWN).
5. **الجداول الفعلية:** وجود جدول `seller_staff` و`seller_roles` و`seller_api_keys` و`audit_logs` في قاعدة الإنتاج، ونوع أعمدة العملة والكيان. الكود يتحقق بـ `Schema::hasTable` في عدة مواضع، لذا الوضع الفعلي UNKNOWN.
6. **سلوك الاستبدال للرمز المدفوع:** هل يُعد استبدال جهاز المالك بجهاز الموظف مقصوداً أم خطأ. قرار مالك (F-08).
7. **بوابة الإصدار (version gating):** `version_helper.dart` و`app-health` لم تُقرأ. أثرها على حد أدنى لإصدار وضع الإدارة غير مُحسوم.
8. **البيانات الشخصية والمالية:** لا وصول للبيانات. لا تقدير لحجم الجداول أو أداء الاستعلامات (تحتاج قياساً في بيئة اختبار بموافقة).
9. **الاختبارات:** لم تُشغَّل أي اختبارات، والمصفوفة في القسم 8 تصميم فقط. التحقق من أن الاختبارات موجودة أو ناجحة UNKNOWN.
10. **المؤشرات المقيّدة:** لم يُفتح الملحق المقيّد. تقدير الخطورة في القسم 9.2 أولي ويحتاج فرزاً مخوّلاً.
11. **الوضع المعتمد:** لا تنفيذ GCC-20 مصرّح به (`DECISIONS.md`). لم يُكتب أي API أو UI أو auth، ولم يُغيَّر أي مستودع، ولم يُنشر هذا الملف على GitHub.
