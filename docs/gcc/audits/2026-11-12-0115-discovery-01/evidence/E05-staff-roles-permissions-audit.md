# E05 — أدوار الموظفين وصلاحيات الوحدات وموظفو البائع والتدقيق والتصدير (Staff roles, module permissions, seller staff, audit, exports)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo` عند HEAD وقت القراءة lavkoo@046d623d. الكود مطابق للـ SHA المرجعي lavkoo@7f42777c. عند إعادة التحقق المستقل كان HEAD هو lavkoo@41201499، والفرق بين lavkoo@7f42777c وHEAD ملفات توثيق تحت `docs/gcc/` فقط دون أي تغيير في الكود (تحقّقت بـ `git diff --stat`). لم يُستخدم `lavkooapp` ولا `lavkooappseller` ولا `ksaapp98` في هذا الملحق.

## 1. النطاق وما تم فحصه

**المجال:** التغطية F (جزء الموظفين) في الـ backend: أدوار المشرفين و`module_access`، فرض الصلاحيات على المسارات، موظفو البائع وأدوارهم، عزل بيانات البائع، سجل التدقيق ومحرك الموافقات، التصدير والتقارير ولوحات المعلومات، ووصول المشرف إلى بيانات العملاء.

**ما قُرئ بالكامل (نحو 95 ملفاً):**

- النماذج: `Admin`، `AdminRole`، `SellerStaff`، `SellerRole`، `SellerApiKey`، `AuditLog`، `ApprovalRequest`، `ApprovalDecision`، `V2SidebarPin`.
- الـ middleware: `AdminMiddleware`، `ModulePermissionMiddleware`، `SellerMiddleware`، `SellerStaffAccessMiddleware`، `EnsureSellerPermission`، `EnsureSellerIsOwner`، `SellerCenterContext`، `SellerApiAuthMiddleware`، `SellerApiMiddleware`، `DeliveryManAuth`، `AuthorizationToolMiddleware`، وتسجيل الأسماء المستعارة في `bootstrap/app.php`.
- الخدمات: `AuditLogger`، `AuditTrail`، `ApprovalEngine`، `AdminService`، `DashboardService` (جزئياً)، `SellerPermissionService`، `SellerPrincipal`، `SellerTeamService`، `SellerAuditTrailService`، وخدمات الصلاحيات الدقيقة الأربع (`ThemePermissionService`، `MonitoringPermissionService`، `AnalyticsPermissionService`، `DeveloperPortalPermissionService`)، ورؤوس `ConsoleGuard` و`OutboundUrlGuard` و`SellerReportService`.
- المتحكمات: `CustomRoleController`، `EmployeeController`، `Admin\ProfileController`، `Admin\Auth\LoginController`، `DashboardController`، `AuditLogController`، `ApprovalController`، `CustomerWalletController`، مقاطع من `CustomerController`/`OrderController`/`ReportController`/`TransactionController` الإدارية، و`StaffLoginController` و`ProfileController` و`ShopController` و`CustomerController` و`OrderController` في لوحة البائع، و`LoginController` في API v3.
- المسارات: `routes/admin/*.php` (6 ملفات، 2,305 سطراً)، `routes/vendor/routes.php`، `routes/seller/routes.php`، `routes/rest_api/v3/seller.php`، ومسارات الوحدات `Modules/AI`، `Modules/Blog`، `Modules/TaxModule`.
- غيرها: `config/auth.php`، `GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION`، `UserRole`، الـ seeders (`AdminRoleTable`، `AdminTable`)، ترحيل إعادة تسمية المفاتيح، الأمران `AuditAdminRoleModuleKeys` و`GenerateAdminRoutesJson`، `AuditTrailObserver`، `AuditedBuilder`، `DemoMaskingTrait`، واختبارَا `AdminRouteAuthenticationTest` و`ModulePermissionsTest`.

**أدوات العدّ:** سكربت Python للقراءة فقط (في scratchpad) يحلّل تعليمات `Route::` ويجمع الـ middleware الموروث من المجموعات المتداخلة، ثم يعدّ التعليمات التي تحمل بادئة معيّنة (`module:`، `seller_can:`، `seller_api_auth`، `seller_owner`، `seller_staff_access`). العدّ هو لـ «تعليمات المسار» في الملفات لا لأزواج (طريقة، URI) المسجَّلة وقت التشغيل؛ تحقّقت من مجموع الملف الرئيسي يدوياً (عدّ نصي: 810 مطابقة لـ `Route::<verb>(` بما فيها `Route::view`، وهو مطابق لعدد تعليمات السكربت).

**أنماط البحث المسجَّلة:** `module_permission_check`، `module_access`، `module:[a-z_0-9]+`، `admin_role_id`، `'admin_id' => 1`، `addGlobalScope|implements Scope|booted()`، `AuditLogger`، `AuditTrail::`، `ApprovalEngine|ApprovalRequest::`، `Excel::download|streamDownload|FastExcel`، `Str::mask|maskPhone|maskEmail`، `Prunable|model:prune`، `storefront_id|legal_entity|market_id|country_scope|store_scope`، `seller_staff_id`، و`audit_logs|approval_requests|seller_staff|seller_roles|seller_api_keys|admin_roles` داخل `database/` و`Modules/` و`installation/`.

**ما لم يُفحص:** محتوى جداول الإنتاج (الأدوار الفعلية، عدد الموظفين، وجود جداول التدقيق)، ملفات القوالب الإدارية واحداً واحداً (فُحصت مواضع `module_permission_check` فقط)، منطق كل تقرير مالي سطراً بسطر (فُحصت بنية الاستعلام ومعاملات النطاق)، تطبيق البائع Flutter (خارج هذا الملحق)، والمستودع القديم `pharmacyksa98` (UNKNOWN — missing access).

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 هوية المشرف والحُرّاس (guards)

- يوجد حارس جلسة واحد للوحة الإدارة اسمه `admin` بمزوّد `admins` ونموذج `App\Models\Admin` (`lavkoo:config/auth.php:49-52`، `lavkoo:config/auth.php:88-91`). البائع له حارس جلسة `seller` (`lavkoo:config/auth.php:54-57`) والعميل `customer`، والـ API يستخدم `passport` (`lavkoo:config/auth.php:44-47`). VERIFIED.
- سجل المشرف يحمل `admin_role_id` و`status` (`lavkoo:app/Models/Admin.php:38-53`) وعلاقة `role()` إلى `AdminRole` (`lavkoo:app/Models/Admin.php:77-80`). VERIFIED.
- `AdminMiddleware` يتحقق من تسجيل الدخول، ويُخرج المشرف غير النشط إلا إذا كان معرّفه 1 (`lavkoo:app/Http/Middleware/AdminMiddleware.php:18-29`)، وإن لم يكن مسجَّلاً يُرجع 404. VERIFIED.
- «الموظف» ليس نموذجاً مستقلاً: هو سجل في `admins` بمعرّف غير 1. صفحة الدخول تميّز النوعين بالمعرّف: السجل 1 يدخل من صفحة `admin` وما عداه من صفحة `employee` (`lavkoo:app/Http/Controllers/Admin/Auth/LoginController.php:50-58`)، والثابتان في `lavkoo:app/Enums/UserRole.php:5-9`. قائمة الموظفين تستثني المعرّف 1 (`lavkoo:app/Repositories/AdminRepository.php:67-93`) وقائمة الأدوار تستثني الدور 1 (`lavkoo:app/Repositories/AdminRoleRepository.php:64-82`). VERIFIED.

### 2.2 بنية الدور و`module_access`

- جدول `admin_roles` يحمل `name` و`module_access` و`status`؛ العمود `module_access` نصّ (cast = `string`) يخزَّن فيه مصفوفة JSON مسطّحة من سلاسل (`lavkoo:app/Models/AdminRole.php:23-36`). VERIFIED.
- الكتابة: `CustomRoleController::add` يخزّن `json_encode($request['modules'])` كما وصل من النموذج (`lavkoo:app/Http/Controllers/Admin/Employee/CustomRoleController.php:50-62`)، وطلب التحقق يفحص اسم الدور فقط (`lavkoo:app/Http/Requests/Admin/CustomRoleRequest.php:31-39`). أي أن العمود قائمة حرّة من المفاتيح وليست مقيّدة بكتالوج عند التخزين. VERIFIED.
- لا يوجد في المستودع ترحيل يُنشئ `admin_roles`؛ الجدول يأتي من قاعدة الأساس (CLAUDE.md يشير إلى استيراد `installation/backup/database.sql`، وهو غير متتبَّع). الدليل الوحيد على شكله: مخطط الاختبار `lavkoo:app/Console/Commands/BootstrapTestSchema.php:80` والـ seeder الذي يُدرج الدورين 1 (`Master Admin`) و2 (`Employee`) دون `module_access` (`lavkoo:database/seeds/AdminRoleTable.php:15-23`). INFERRED لشكل الجدول في الإنتاج.

**قائمة مفاتيح الوحدات (الكتالوج الخشن، 14 مفتاحاً)** — `lavkoo:app/Enums/GlobalConstant.php:1260-1275`:

| المفتاح | يُفرض بـ `module:` في المسارات؟ (عدد تعليمات المسار) | ملاحظات |
|---|---|---|
| `dashboard` | لا (0) | يُستهلك في شرط قالب واحد فقط: `lavkoo:resources/views/admin-views/system/dashboard.blade.php:8`. بحث `module:dashboard` في `routes/` و`Modules/`: لا نتائج. |
| `pos` | نعم (19) | |
| `catalog` | نعم (67) | |
| `orders` | نعم (29) | |
| `marketing` | نعم (78 + 24 في وحدة Blog) | |
| `people` | نعم (101) | يجمع العملاء والبائعين والموظفين والأدوار والمراجعات والدعم والمندوبين. |
| `reports` | نعم (37) | |
| `marketplace` | نعم (96) | |
| `business_settings` | نعم (117 + 17 للعناوين) | |
| `system_settings` | نعم (76) | يشمل سجل التدقيق والمراقبة وبوابة المطوّر. |
| `3rd_party_setup` | نعم (68 + 33 للـ fulfillment) | |
| `sales_channels` | نعم (50) | ملف مستقل. |
| `operational_costs` | نعم (41 + 16 لـ Odoo) | ملفان مستقلان. |
| `themes_and_addons` | نعم (76) | |

- أُعيدت تسمية ثمانية مفاتيح قديمة ودُمج بعضها (`pos_management→pos`، `promotion_management|blog_management→marketing`، `user_section|support_section→people`، `order_management→orders`، `product_management→catalog`، `report→reports`) بترحيل بيانات (`lavkoo:database/migrations/2026_04_23_120000_rename_admin_role_module_access_keys.php:8-17`) مع أمر فحص/إصلاح (`lavkoo:app/Console/Commands/AuditAdminRoleModuleKeys.php:10-24`). وجود مفاتيح قديمة في أدوار الإنتاج UNKNOWN.

**مفاتيح القدرات الدقيقة (تُخزَّن في العمود نفسه، 19 مفتاحاً):**

| الخدمة | المفاتيح | ما يمنحه المفتاح الخشن ضمناً | الدليل |
|---|---|---|---|
| `ThemePermissionService` | `theme_view`، `theme_edit`، `theme_publish`، `theme_restore`، `theme_styles` | `themes_and_addons` يمنح الكل عدا `theme_publish` | `lavkoo:app/Services/Theme/ThemePermissionService.php:20-34`، `lavkoo:app/Services/Theme/ThemePermissionService.php:39-68` |
| `MonitoringPermissionService` | `monitoring_view`، `monitoring_logs`، `monitoring_security`، `monitoring_infrastructure`، `monitoring_errors`، `monitoring_settings` | `system_settings` يمنح `view` و`errors` فقط | `lavkoo:app/Services/Monitoring/MonitoringPermissionService.php:26-58`، `lavkoo:app/Services/Monitoring/MonitoringPermissionService.php:70-93` |
| `AnalyticsPermissionService` | `analytics_view`، `analytics_journeys`، `analytics_campaigns`، `analytics_export`، `analytics_settings` | `reports` يمنح `analytics_view` فقط | `lavkoo:app/Services/Analytics/AnalyticsPermissionService.php:21-50`، `lavkoo:app/Services/Analytics/AnalyticsPermissionService.php:82-109` |
| `DeveloperPortalPermissionService` | `developer_view`، `developer_console`، `developer_snapshot` | `system_settings` يمنح `developer_view` فقط | `lavkoo:app/Services/DeveloperPortal/DeveloperPortalPermissionService.php:27-41`، `lavkoo:app/Services/DeveloperPortal/DeveloperPortalPermissionService.php:50-73` |

نموذج إنشاء الدور يعرض المجموعات الخمس كلها كحقول `modules[]` (`lavkoo:resources/views/admin-views/custom-role/create.blade.php:45-48`، `lavkoo:resources/views/admin-views/custom-role/create.blade.php:66`، `lavkoo:resources/views/admin-views/custom-role/create.blade.php:93`، `lavkoo:resources/views/admin-views/custom-role/create.blade.php:111`، `lavkoo:resources/views/admin-views/custom-role/create.blade.php:133`). VERIFIED. هذا سابقة مهمة: النظام وسّع الصلاحيات فعلاً بإضافة مفاتيح جديدة إلى العمود نفسه دون تغيير مخطط.

### 2.3 أين تُفرض الصلاحية

1. **Middleware المسار (الآلية الأساسية).** الاسم المستعار `module` يشير إلى `ModulePermissionMiddleware` (`lavkoo:bootstrap/app.php:118`) الذي يستدعي `Helpers::module_permission_check($module)` ويعيد التوجيه إلى لوحة المعلومات مع رسالة رفض عند الفشل (`lavkoo:app/Http/Middleware/ModulePermissionMiddleware.php:20-31`). الدالة تقرأ دور المشرف وتتحقق من `status == 1` ووجود المفتاح في JSON، ثم تمنح كل شيء إن كان `admin_role_id == 1` (`lavkoo:app/Utils/Helpers.php:374-390`). VERIFIED.
2. **القوالب (إخفاء القوائم).** 61 موضعاً لـ `module_permission_check` في 10 ملفات قوالب، أغلبها في الشريطين الجانبيين (مثل `lavkoo:resources/views/layouts/admin/partials/v2/_side-bar.blade.php:34` و`lavkoo:resources/views/layouts/admin/partials/v2/_side-bar.blade.php:448`). هذا إخفاء واجهة لا فرض. VERIFIED.
3. **داخل الكود (قليل).** خمسة مواضع خارج الـ middleware: `lavkoo:app/Services/Fulfillment/ProductFormProviderSelection.php:30`، `lavkoo:app/Services/Admin/SettingsIndexService.php:150-157`، `lavkoo:app/Http/Controllers/Admin/SalesChannels/Support/AccountForm.php:59`، `lavkoo:app/Http/Controllers/Admin/OperationalCosts/ReturnPanel.php:45`، `lavkoo:app/Http/Controllers/Admin/V2AdvancedSearchController.php:146-157`. والخدمات الدقيقة الأربع تُستدعى من متحكماتها (مثال: `lavkoo:app/Http/Controllers/Admin/Telemetry/AnalyticsController.php:84-89`). VERIFIED.
4. **لا Policies ولا Gates.** المجلد `app/Policies` غير موجود، وبحث `Gate::(define|allows|denies|authorize)` و`$this->authorize` و`extends .*Policy` في `app/` و`Modules/` لم يُرجع أي استخدام لتفويض Laravel القياسي (المطابقات الأربع دوال أعمال تحمل الاسم نفسه: اثنتان في `DeliverySyriaClient` واثنتان في `ReturnLogisticsService`). وبحث `middleware|module_permission|admin_role_id|authorize` داخل متحكمات الوحدات الثلاث لم يُرجع شيئاً؛ و`authorize()` في طلبات النماذج المفحوصة تُرجع `true` (مثال: `lavkoo:app/Http/Requests/Admin/CustomRoleRequest.php:26-29`).

**حالات المشرف الأعلى (Super Admin) — شرطان مختلفان:**

| الشرط | المواضع | الأثر |
|---|---|---|
| `admin_role_id == 1` | `lavkoo:app/Utils/Helpers.php:386-388`؛ الخدمات الأربع (`lavkoo:app/Services/Theme/ThemePermissionService.php:46-49` وما يماثله)؛ `lavkoo:app/Services/SalesChannels/Listings/RegulatedProductGate.php:77-82` | استثناء كامل من فحص الوحدات والقدرات. |
| `admin_role_id == 1` كشرط حصري لفعل | `lavkoo:app/Http/Controllers/Admin/SalesChannels/ChannelAccountController.php:187-189` (الموافقة على نقل بيانات المشترين)؛ `lavkoo:app/Http/Controllers/Admin/Fulfillment/IntegrationCenterController.php:368-380` (استثناء من جاهزية go-live) | أفعال لا يملكها إلا المشرف الرئيسي مهما كانت وحدات الدور. |
| `admins.id == 1` | `lavkoo:app/Http/Middleware/AdminMiddleware.php:21`؛ `lavkoo:app/Http/Controllers/Admin/Auth/LoginController.php:51-58`؛ `lavkoo:app/Repositories/AdminRepository.php:78` | استثناء من فحص الحالة، صفحة دخول مختلفة، إخفاء من قائمة الموظفين. |

- `EmployeeController` يمنع إسناد الدور 1 لموظف عند الإضافة (`lavkoo:app/Http/Controllers/Admin/Employee/EmployeeController.php:58-83`). VERIFIED.
- لا يوجد فصل بين «من يملك الوحدة» و«من يحق له منح الصلاحيات»: إدارة الموظفين والأدوار تقع تحت المفتاح الخشن `people` نفسه (`lavkoo:routes/admin/routes.php:658`، `lavkoo:routes/admin/routes.php:671`). تبعات ذلك الأمنية في `SEC-E05-02`.

### 2.4 تغطية المسارات الإدارية (عدّ آلي)

المسارات الإدارية تُحمَّل من ستة ملفات أساسية (`lavkoo:app/Providers/RouteServiceProvider.php:187-207`، `lavkoo:app/Providers/SalesChannelsServiceProvider.php:66-70`) ومن ثلاث وحدات.

| الملف | تعليمات المسار | تحمل `module:` | لا تحمل `module:` | إعلان المجموعة |
|---|---|---|---|---|
| `routes/admin/routes.php` | 810 | 764 | 46 | `lavkoo:routes/admin/routes.php:128`، `lavkoo:routes/admin/routes.php:179` |
| `routes/admin/fulfillment.php` | 33 | 33 | 0 | `lavkoo:routes/admin/fulfillment.php:23` |
| `routes/admin/addresses.php` | 17 | 17 | 0 | `lavkoo:routes/admin/addresses.php:25` |
| `routes/admin/sales-channels.php` | 50 | 50 | 0 | `lavkoo:routes/admin/sales-channels.php:28` |
| `routes/admin/operational-costs.php` | 41 | 41 | 0 | `lavkoo:routes/admin/operational-costs.php:27` |
| `routes/admin/odoo-accounting.php` | 16 | 16 | 0 | `lavkoo:routes/admin/odoo-accounting.php:27` |
| ملفات المسارات الإدارية للوحدات الثلاث (`Modules/AI`، `Modules/Blog`، `Modules/TaxModule`) | 67 | 24 | 43 | مثال: `lavkoo:Modules/Blog/routes/admin/routes.php:15-16` |
| **المجموع** | **1,034** | **945 (91.4%)** | **89 (8.6%)** | |

- مجموعات تعلن `module:` صراحةً: 53 من 275 مجموعة في الملف الرئيسي، ومجموعة واحدة في كل ملف من الملفات الخمسة التابعة، ومجموعة واحدة في ملفات الوحدات.
- من الـ 89 غير المشمولة بمفتاح وحدة: 7 تعليمات عامة قبل الدخول بطبيعتها (تغيير اللغة، recaptcha، التفعيل، الاشتراك في topic، صفحة الدخول وإرسالها)، و82 تعليمة تتطلب مشرفاً مسجَّلاً دون مفتاح وحدة (39 في الملف الرئيسي و43 في ملفات الوحدات). جزء منها شخصي أو واجهي بطبيعته (تثبيتات الشريط الجانبي، الخروج، البحث الذي يُصفّى داخلياً بالصلاحيات)، والباقي مصنَّف في الملحق المقيّد فقط.
- SEC-E05-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E05-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E05-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- اختباران يثبّتان جزءاً من التغطية: `lavkoo:tests/Feature/Security/AdminRouteAuthenticationTest.php:23-31` (لا مسار إداري بلا حارس `admin`، ويثبّت حضور الحارس والوحدة على 12 مساراً كانت تفتقر إليهما سابقاً: `lavkoo:tests/Feature/Security/AdminRouteAuthenticationTest.php:121-129`) و`lavkoo:tests/Feature/SalesChannels/ModulePermissionsTest.php:25-37` (كل مسارات `sales_channels` و`operational_costs` خلف وحدتها). بحث `module:|ModulePermissionMiddleware|module_permission_check` في `tests/` أرجع 12 ملفاً (SalesChannels 5، Address 3، Fulfillment 2، OperationalCosts 1، Security 1)؛ لم أجد بينها اختباراً يثبّت `module:` على مجمل الملف الرئيسي أو على مسارات وحدتَي AI وTaxModule (INFERRED من أسماء الملفات ومساراتها؛ قُرئ الاختباران المذكوران فقط).

### 2.5 موظفو البائع: النموذج والمفاتيح والفرض

**النموذج.** `seller_staff` (حساب دخول مستقل ببريد وكلمة مرور و`auth_token` خاص، مرتبط بـ `seller_id` و`seller_role_id`: `lavkoo:app/Models/SellerStaff.php:16-38`) و`seller_roles` (دور يعرّفه البائع، `permissions` مصفوفة JSON: `lavkoo:app/Models/SellerRole.php:10-26`). المالك (سجل `sellers`) لا يُمثَّل كموظف ويملك كل شيء ضمنياً (`lavkoo:app/Services/Marketplace/SellerPrincipal.php:93-96`). VERIFIED.

**كتالوج الصلاحيات (11 مفتاحاً في 7 مجموعات)** — `lavkoo:app/Services/Marketplace/SellerPermissionService.php:30-41`: `products.view`، `products.manage`، `orders.view`، `orders.manage`، `inventory.manage`، `promotions.manage`، `finance.view`، `payouts.request`، `reviews.view`، `shop_settings.manage`، `staff.manage`. القيم تُنقّى مقابل الكتالوج عند الكتابة والقراءة (`lavkoo:app/Services/Marketplace/SellerPermissionService.php:50-55`، `lavkoo:app/Services/Marketplace/SellerPermissionService.php:200-209`)، ولا يستطيع غير المالك منح ما لا يملكه (`lavkoo:app/Services/Marketplace/SellerTeamService.php:245-254`)، ولا يُسند دور من متجر آخر (`lavkoo:app/Services/Marketplace/SellerTeamService.php:259-266`). VERIFIED.

**الكيان الموحَّد `SellerPrincipal`.** يفصل «المتجر» (`sellerId()`) عن «الشخص» (`staffId()`، `apiKeyId()`) وصلاحياته (`lavkoo:app/Services/Marketplace/SellerPrincipal.php:66-84`)، ويُوضع على خصائص الطلب تحت المفتاح `seller_principal` (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:19`). ثلاثة أنواع: مالك، موظف، مفتاح تكامل مقيَّد بنطاقاته (`lavkoo:app/Services/Marketplace/SellerPrincipal.php:35-63`).

**API v3 للبائع (236 تعليمة مسار في `routes/rest_api/v3/seller.php`):**

| الفئة | العدد | الدليل |
|---|---|---|
| عامة قبل الدخول (دخول، استعادة كلمة المرور، تسجيل) | 8 | `lavkoo:routes/rest_api/v3/seller.php:49-70` |
| خلف `seller_api_auth` | 228 | `lavkoo:routes/rest_api/v3/seller.php:72` |
| منها خلف `seller_can:<key>` | 212 | مثال: `lavkoo:routes/rest_api/v3/seller.php:97-100` |
| منها خلف `seller_owner` (المالك شخصياً) | 11 | `lavkoo:routes/rest_api/v3/seller.php:87-94` |
| منها «جلسة المتصل نفسه» بلا صلاحية (اللغة، معلومات البائع، رمز Firebase، معلومات المتجر، مندوبو المتجر) | 5 | `lavkoo:routes/rest_api/v3/seller.php:73-81` |

- `SellerApiAuthMiddleware` يحلّ الرمز إلى مالك معتمد أو موظف نشط أو مفتاح تكامل، ويعيد فحص الحالة في كل طلب (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:41-93`؛ `lavkoo:app/Services/Marketplace/SellerPermissionService.php:98-120`). مفتاح التكامل يُرفض على أي مسار لا يعلن `seller_can:` (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:66-79`، `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:102-117`) — إغلاق افتراضي. VERIFIED.
- `EnsureSellerPermission` يقبل عدة مفاتيح بمعنى «أي واحد منها» ويرد 403 بصيغة JSON أو صفحة (`lavkoo:app/Http/Middleware/EnsureSellerPermission.php:26-59`). `EnsureSellerIsOwner` يرفض الموظف والمفتاح معاً (`lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:24-41`). VERIFIED.
- الموظف يحصل على رمز API خاص به عند الدخول (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:28-54`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:78-84`). VERIFIED.

**لوحة البائع على الويب (`/vendor`) — طبقتان:**

- اللوحة الكلاسيكية (`routes/vendor/routes.php`، 221 تعليمة): 207 منها داخل مجموعة `['seller', 'seller_staff_access']` (`lavkoo:routes/vendor/routes.php:87`)، و14 قبل الدخول. لا تحمل أي منها `seller_can:`. دخول الموظف يسجّله بهوية البائع المالك ويضع `seller_staff_id` في الجلسة (`lavkoo:app/Http/Controllers/Vendor/Auth/StaffLoginController.php:37-76`)، ثم يشتق `SellerStaffAccessMiddleware` الصلاحية المطلوبة من مقطع الـ URL الثاني بخريطة «رفض افتراضي» (`lavkoo:app/Http/Middleware/SellerStaffAccessMiddleware.php:33-59`، `lavkoo:app/Http/Middleware/SellerStaffAccessMiddleware.php:65-135`)، مع استثناء مسارات حركة الأموال التي تتطلب `payouts.request` أينما وقعت (`lavkoo:app/Http/Middleware/SellerStaffAccessMiddleware.php:141-150`). المالك يمرّ دون أي فحص. VERIFIED.
- Seller Center الجديد (`routes/seller/routes.php`، 27 تعليمة): المجموعة تحمل `seller` و`seller_staff_access` و`seller_center` (`lavkoo:routes/seller/routes.php:40-48`)؛ الأخير يبني `SellerPrincipal` من الجلسة ليخدم بوابة `seller_can:` نفسها المستخدمة في الـ API (`lavkoo:app/Http/Middleware/SellerCenterContext.php:30-52`). 18 تعليمة تعلن `seller_can:` و9 للملاحة/التفضيلات.
- مسارات وحدتَي AI وTaxModule الخاصة بالبائع (على `/vendor` وعلى `v3/seller`) معرَّفة في ملفات الوحدتين لا في الملفات الثلاثة أعلاه (18 تعليمة ويب و20 تعليمة API بحسب العدّ الآلي)؛ تقييم تغطيتها ضمن `SEC-E05-05`.
- SEC-E05-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E05-08: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- `DeliveryManAuth` حارس رمز بسيط للمندوب بلا أدوار (`lavkoo:app/Http/Middleware/DeliveryManAuth.php:18-32`). `AuthorizationToolMiddleware` (`lavkoo:app/Http/Middleware/AuthorizationToolMiddleware.php:19-23`) غير مسجَّل ولا مستخدَم (بحث الاسم في `app/`، `routes/`، `bootstrap/`، `Modules/`، `config/`: لا نتائج خارج ملفه)، ويُوصى بحذفه أو توثيقه قبل أي إعادة ربط. `SellerApiMiddleware` تمرير فارغ (`lavkoo:app/Http/Middleware/SellerApiMiddleware.php:17-20`).

### 2.6 عزل بيانات البائع اليوم

- **لا يوجد Global Scope للبائع.** بحث `addGlobalScope|implements Scope|booted()` في `app/` و`Modules/` أرجع نطاقات ترجمة ونشاط فقط (مثل `lavkoo:app/Models/Attribute.php:33`، `lavkoo:app/Models/Review.php:115`) ونطاقاً معلَّقاً في `lavkoo:app/Models/Order.php:366`. لا نطاق مستأجر أو بائع أو متجر. VERIFIED.
- **النمط الفعلي: شرط يدوي في كل استعلام.**

| السطح | النمط | مثال | الاتساع |
|---|---|---|---|
| لوحة البائع (ويب) | `'seller_id' => auth('seller')->id()` مع `'seller_is' => 'seller'` في معاملات المستودع | `lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:430`، `lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:443` | 201 ظهوراً لـ `auth('seller')->id()` في 33 من 41 ملف متحكم. |
| API v3 | `$request->seller` يضعه الـ middleware (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:59-64`) ثم يُستخدم معرّفه في الشروط | 207 ظهوراً (بالصيغتين `request->seller` و`request['seller']`) في 34 من 38 ملف متحكم (الأربعة الباقية: الدخول والتسجيل والاستعادة والماركات). | |
| خدمات حديثة | معرّف المتجر وسيط إلزامي للدالة، ونطاق خاص داخل الخدمة | `lavkoo:app/Services/Reports/SellerReportService.php:311-325` (`scopedOrders`/`scopedProducts`)؛ `lavkoo:app/Services/Marketplace/SellerAuditTrailService.php:33-76` | نمط أنظف لكنه غير معمَّم. |
| منتجات البائع | `added_by = 'seller'` و`user_id = <seller id>` (لا عمود `seller_id` في المنتجات) | `lavkoo:app/Services/Reports/SellerReportService.php:319-325` | دلالة قديمة مزدوجة يجب الانتباه لها. |

- **الاتساق:** متوسط لا كامل. فحص إرشادي على لوحة البائع: من 124 استدعاء `getFirstWhere(... 'id' => ...)` في متحكمات `Vendor`، 46 تحمل شرط الملكية في السطر نفسه و78 لا تحمله؛ كثير من الـ 78 مشروع (معرّف البائع نفسه، عميل POS)، لكن عيّنة من أربعة مواضع أظهرت أن الاتساق غير مضمون بالتصميم (التفاصيل في الملحق المقيّد). المقابل السليم موجود في المتحكم المجاور (`lavkoo:app/Http/Controllers/Vendor/ProfileController.php:75-96` يعمل على البائع الموثَّق لا على المعرّف الوارد). VERIFIED للعيّنة، INFERRED لبقية المواضع.
- SEC-E05-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E05-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- جانب الإدارة: لا عزل إطلاقاً بحكم التصميم؛ المشرف يرى كل البائعين وكل الطلبات، والمرشّح الوحيد هو `seller_id` اختياري في الطلب (`lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:139-164`). المتجر الداخلي يُمثَّل بـ `seller_is = 'admin'` مع تحويل `seller_id` من `0` إلى `1` (`lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:139`). VERIFIED.

### 2.7 سجل التدقيق ومحرك الموافقات

**`AuditLogger` — نقطة الكتابة الوحيدة.** `record()` يكتب صفاً في `audit_logs` بالحقول: نوع الفاعل ومعرّفه واسمه، `action` (اسم منقّط بادئته الوحدة)، نوع الموضوع ومعرّفه، `before`/`after`/`context` (JSON)، IP و user agent (`lavkoo:app/Services/AuditLogger.php:34-66`؛ الحقول في `lavkoo:app/Models/AuditLog.php:15-26`). لا يرمي استثناءً أبداً ويعود `null` بصمت إن لم يوجد الجدول (`lavkoo:app/Services/AuditLogger.php:41-44`، `lavkoo:app/Services/AuditLogger.php:62-65`)؛ `recordOrFail()` للحالات التي يكون فيها السطر هو الأثر الوحيد (`lavkoo:app/Services/AuditLogger.php:80-97`). VERIFIED.

**تحديد الفاعل** (`lavkoo:app/Services/AuditLogger.php:119-144`): حارس `admin` أولاً، ثم `SellerPrincipal` من الطلب (مالك/موظف/مفتاح بهويته الخاصة: `lavkoo:app/Services/Marketplace/SellerPrincipal.php:104-116`)، ثم حارسا `seller` و`customer`، وإلا `system`. في اللوحة الكلاسيكية للبائع لا يوجد `SellerPrincipal` (الوسيط `seller_center` مركَّب في `lavkoo:routes/seller/routes.php:47` فقط)، فيُسجَّل فعل الموظف باسم المالك. INFERRED.

**ما الذي يُسجَّل:**

| المصدر | ما يغطيه | الدليل |
|---|---|---|
| استدعاءات صريحة | 95 ملفاً تستخدم `AuditLogger`؛ أكثر البادئات: `sales_channel` (35 موضعاً)، `seller` (34)، `operational_cost` (13)، `commerce` (12)، `monitoring` (11)، `theme` (10)، `address` (9)، ثم `procurement`، `payout`، `auth`، `warehouse`، `returns`، `settlement` … | عدّ `action: '<prefix>.` في `app/` |
| `AuditTrailObserver` (أحداث النماذج) | القسائم والعروض والبانرات وطرق الدفع غير المباشر وطرق السحب، و`AdminRole` و`Admin` | `lavkoo:app/Services/AuditTrail.php:63-80`؛ `lavkoo:app/Providers/ObserverServiceProvider.php:70-77`؛ `lavkoo:app/Observers/AuditTrailObserver.php:27-52` |
| `AuditedBuilder` (التحديثات الجماعية) | `BusinessSetting` و`Setting` فقط | `lavkoo:app/Models/Builders/AuditedBuilder.php:27-56`؛ `lavkoo:app/Models/BusinessSetting.php:26-28`؛ `lavkoo:app/Models/Setting.php:29-31` |
| أحداث المصادقة | دخول، فشل دخول، قفل، خروج | `lavkoo:app/Listeners/Security/RecordAuthenticationEvents.php:40-43` |

- القيم تمرّ بمنقّح أسرار وتُقصّ إلى 500 حرف، وتُستثنى `password` و`auth_token` و`remember_token` (`lavkoo:app/Services/AuditTrail.php:50-53`، `lavkoo:app/Services/AuditTrail.php:113-127`). VERIFIED.
- **ما لا يُسجَّل:** المتحكمات الإدارية الكلاسيكية للعملاء والمحافظ والطلبات والاسترجاع والبائعين لا تحوي أي إشارة للتدقيق (عدّ كلمة `audit` في `CustomerWalletController`، `CustomerController`، `Order\OrderController`، `Order\RefundController`، `Vendor\VendorController`: صفر في كلٍّ منها)، ولا يوجد أي مستخدم لـ `AuditLogger` تحت `app/Repositories`. يشمل ذلك مساراً مالياً يدوياً في لوحة الإدارة. VERIFIED.
- SEC-E05-09: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- تحديث الدور وحذفه يمرّان بتحديث/حذف جماعي (`lavkoo:app/Repositories/AdminRoleRepository.php:53-62`)، و`AdminRole` لا يحمل `AuditedBuilder`؛ والمراقب نفسه يوثّق أن التحديث الجماعي لا يطلق حدثاً (`lavkoo:app/Observers/AuditTrailObserver.php:18-19`). إذن تعديل صلاحيات دور لا يُسجَّل، بخلاف إنشائه. INFERRED.

**العرض والاحتفاظ:**

- شاشة مركز التدقيق للقراءة فقط، خلف `module:system_settings` (`lavkoo:routes/admin/routes.php:241-243`؛ `lavkoo:app/Http/Controllers/Admin/Marketplace/AuditLogController.php:23-62`)، مرشّحاتها: الوحدة، نوع الفاعل، الفعل، بحث نصي. لا مرشّح نطاق.
- البائع يرى سجل متجره عبر خدمة تحصر الصفوف بالفاعل (المالك/موظفوه/مفاتيحه) أو بوجود `"seller_id":N` داخل نص `context` (مطابقة `LIKE` على JSON: `lavkoo:app/Services/Marketplace/SellerAuditTrailService.php:49-75`)، بسقف صفوف من سياسة `limit_audit_rows` (`lavkoo:app/Services/Platform/PolicyRegistry.php:426-429`). VERIFIED.
- **لا سياسة احتفاظ.** لا حذف ولا تقليم لـ `audit_logs` (بحث `audit_logs|AuditLog` في `app/Console` و`app/Jobs` أرجع استدعاءات لخدمة الكتابة `AuditLogger` فقط، مثل `lavkoo:app/Console/Commands/ThemePublishDue.php:6`، دون أي حذف أو تقليم؛ بحث `Prunable|MassPrunable|model:prune` في `app/` و`Modules/`: لا نتائج). السجل «إلحاق فقط» بنص التعليق (`lavkoo:app/Models/AuditLog.php:8-12`). VERIFIED لغياب الكود؛ الحجم الفعلي UNKNOWN.
- **الجدول غير مُعرَّف بترحيل في المستودع.** ملف دعم الاختبارات يصرّح بذلك (`lavkoo:tests/Support/BuildsAuditLogTable.php:8-27`)، وبحث أسماء الجداول `audit_logs`، `approval_requests`، `approval_decisions`، `seller_staff`، `seller_roles`، `seller_api_keys` في `database/` و`Modules/` و`installation/` لم يُرجع أي ترحيل إنشاء. الكود يتحمّل الغياب بفحوص `Schema::hasTable` (مثل `lavkoo:app/Services/Marketplace/SellerPermissionService.php:59-61`)، وفحص الجاهزية يحذّر عند غياب `audit_logs` (`lavkoo:app/Services/Fulfillment/Readiness/FulfillmentPreflight.php:362-364`). وجود هذه الجداول في الإنتاج UNKNOWN.

**محرك الموافقات `ApprovalEngine`:**

- طلب موافقة عام على أي موضوع: `workflow`، الموضوع، `amount` و`currency`، `required_approvals`، مقدّم الطلب ونوعه، `payload` (`lavkoo:app/Services/ApprovalEngine.php:40-79`؛ الحقول `lavkoo:app/Models/ApprovalRequest.php:18-23`). قاعدتان: مقدّم الطلب لا يوافق عليه، والموافق يُحسب مرة واحدة اعتماداً على قيد تفرّد يفترضه المحرك في جدول `approval_decisions` (`lavkoo:app/Services/ApprovalEngine.php:97-112`)؛ لا يحمل `lavkoo:app/Models/ApprovalDecision.php:13-20` هذا القيد، ولا ينشئ المستودع الجدول (انظر أعلاه)، فوجود القيد في الإنتاج UNKNOWN. رفض واحد يُنهي الطلب (`lavkoo:app/Services/ApprovalEngine.php:138-164`). كل خطوة تُسجَّل عبر `AuditLogger`. VERIFIED لبنية المحرك؛ قيد التفرّد UNKNOWN.
- المستخدم الوحيد خارج المحرك وشاشته: دفعات البائعين الكبيرة (`lavkoo:app/Services/Marketplace/PayoutService.php:150-171`، `lavkoo:app/Services/Marketplace/PayoutService.php:438-455`)؛ التفعيل اختياري بعتبة إعداد. التسويات لها maker-checker خاص بإعداد `settlement_maker_checker` الذي يذكر تعليق الكود أنه معطَّل افتراضياً (القيمة الفعلية UNKNOWN) (`lavkoo:app/Http/Controllers/Admin/Marketplace/SettlementController.php:150-153`). بحث `ApprovalEngine|ApprovalRequest::` في `app/`: `PayoutService` و`ApprovalController` فقط.
- صندوق الموافقات يعرض كل الطلبات المعلّقة لكل مشرف يحمل `marketplace` (`lavkoo:routes/admin/routes.php:721-726` داخل المجموعة عند `lavkoo:routes/admin/routes.php:687`؛ `lavkoo:app/Http/Controllers/Admin/Marketplace/ApprovalController.php:28-49`)، ويُدخل هوية المشرف فقط إلى المحرك (`lavkoo:app/Http/Controllers/Admin/Marketplace/ApprovalController.php:61-73`). لا نطاق ولا حدّ مبلغ لكل موافق.

### 2.8 التصدير والتقارير ولوحات المعلومات

يوجد 30 صنف تصدير في `app/Exports` و5 في `Modules/TaxModule`، و82 موضع استدعاء (عدّ نصي في `app` و`Modules` و`routes`) (`Excel::download`، `streamDownload`، `FastExcel`). كل أصناف التصدير تستقبل مصفوفة بيانات جاهزة من المتحكم؛ لا يحمل أيٌّ منها معامل نطاق.

**جدول التصدير والتقارير الإدارية التي تجمّع على مستوى النظام كله:**

| التصدير / التقرير | البيانات | معاملات التحديد المتاحة | التفويض الحالي | الدليل |
|---|---|---|---|---|
| قائمة العملاء `Customers.xlsx` | كل العملاء: الاسم، الهاتف، البريد، إحصاءات الطلبات | بحث، حالة، تواريخ الانضمام/الطلب | `module:people` | `lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:403-427`؛ `lavkoo:resources/views/file-exports/customer-list.blade.php:51-52` |
| قائمة المشتركين | بريد المشتركين | بحث، تاريخ | `module:people` | `lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:430-461` |
| طلبات عميل | طلبات عميل محدد | معرّف العميل | `module:people` | `lavkoo:routes/admin/routes.php:585` |
| معاملات المحفظة | كل حركات محافظ العملاء + مجاميع دائن/مدين | نوع الحركة، تاريخ، عميل اختياري | `module:people` | `lavkoo:app/Http/Controllers/Admin/Customer/CustomerWalletController.php:125-147` |
| معاملات نقاط الولاء | كل حركات الولاء | مماثل | `module:people` | `lavkoo:routes/admin/routes.php:614-618` |
| الطلبات `Orders.xlsx` | كل الطلبات | حالة، تاريخ، `seller_id` اختياري، عميل، مندوب | `module:orders` | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:478-492` |
| طلبات الاسترجاع | كل طلبات الاسترجاع | حالة | `module:orders` | `lavkoo:routes/admin/routes.php:1908-1914` |
| قائمة البائعين وطلبات بائع وطلبات السحب | كل البائعين وبياناتهم | بحث | `module:people` | `lavkoo:app/Http/Controllers/Admin/Vendor/VendorController.php:278-284` |
| الموظفون والأدوار | كل الموظفين (بلا السجل 1) والأدوار ووحداتها | دور | `module:people` | `lavkoo:app/Http/Controllers/Admin/Employee/EmployeeController.php:85-109`؛ `lavkoo:app/Http/Controllers/Admin/Employee/CustomRoleController.php:92-106` |
| أرباح الإدارة وأرباح البائعين (Excel/PDF) | مجاميع الطلبات المسلَّمة والعمولات والشحن والضرائب لكل النظام | نوع التاريخ فقط | `module:reports` | `lavkoo:routes/admin/routes.php:948-956`؛ `lavkoo:app/Http/Controllers/Admin/ReportController.php:138-149` |
| تقرير الطلبات، المنتجات، مبيعات البائعين | كل الطلبات/المنتجات | تاريخ، `seller_id` اختياري | `module:reports` | `lavkoo:routes/admin/routes.php:958-972` |
| معاملات الطلبات والمصروفات والاسترجاع (Excel/PDF) | `order_transactions` لكل النظام | تاريخ، حالة، عميل/بائع اختياري | `module:reports` | `lavkoo:routes/admin/routes.php:938-946`، `lavkoo:routes/admin/routes.php:975-990` |
| مخزون المنتجات والمفضّلة | كل المنتجات | `seller_id` اختياري، فئة | `module:reports` | `lavkoo:routes/admin/routes.php:992-1004` |
| المنتجات، الفئات، الماركات، القسائم، المراجعات، المندوبون | القوائم الكاملة | بحث/حالة | `catalog` / `marketing` / `people` | مواضع `Excel::download` في متحكمات `Admin\Product`، `Admin\Promotion`، `Admin\Deliveryman` |
| التحليلات (CSV) | تفصيل بُعد تحليلي حتى 5000 صف | نافذة زمنية، بُعد | `module:reports` + `analytics_export` | `lavkoo:app/Http/Controllers/Admin/Telemetry/AnalyticsController.php:84-96` |
| التكاليف التشغيلية والربحية (CSV) | دفاتر التكاليف والأرباح | نوع التقرير | `module:operational_costs` | `lavkoo:routes/admin/operational-costs.php:71`، `lavkoo:routes/admin/operational-costs.php:80` |
| تقارير الضرائب (المنصة والبائعون) | الضرائب المحصَّلة لكل النظام ولكل بائع | تاريخ، بائع | مقيّد — انظر `SEC-E05-03` | `lavkoo:Modules/TaxModule/routes/web.php:33-46` |
| لوحة المعلومات الإدارية | أعلى المنتجات والعملاء والبائعين، عدّادات الطلبات بالحالة، أرباح شهرية، أرصدة `admin_wallets`، أعداد العملاء/البائعين/المندوبين | نوع الإحصاء الزمني فقط | المفتاح `dashboard` كشرط عرض في القالب — انظر `SEC-E05-03` | `lavkoo:app/Http/Controllers/Admin/DashboardController.php:56-118`، `lavkoo:app/Http/Controllers/Admin/DashboardController.php:204-234` |
| الأنشطة اللحظية (polling) | عدد الطلبات الجديدة غير المطّلع عليها، طلبات إعادة التخزين، الرسائل الجديدة | لا شيء | انظر `SEC-E05-03` | `lavkoo:app/Http/Controllers/Admin/DashboardController.php:357-399` |

- **بنية الاستعلامات في التقارير:** معظمها استعلامات Eloquent مباشرة داخل المتحكم لا عبر المستودعات: `Order::` يظهر 40 مرة في `ReportController`، و19 في `TransactionReportController`، و13 في `OrderReportController`، و9 في `VendorProductSaleReportController` (عدّ نصي). مثال: `lavkoo:app/Http/Controllers/Admin/ReportController.php:140-149`. لا توجد نقطة مركزية واحدة يُضاف عندها شرط نطاق. VERIFIED.
- **لا بُعد عملة في التجميع.** المجاميع تُحسب بـ `sum` على كل الطلبات ثم تُحوَّل بدالة العملة الافتراضية (مثل `lavkoo:app/Http/Controllers/Admin/DashboardController.php:297-318`). عدّ كلمة `currency` في متحكمات التقارير المفحوصة (عشرة ملفات): صفر في معظمها، والمواضع الوحيدة استدعاءات لدوال التحويل إلى العملة الافتراضية (`lavkoo:app/Http/Controllers/Admin/DashboardController.php:312`) و`TransactionController` غير المربوط بمسار (انظر أدناه)، ولا يوجد بُعد عملة في التجميع. VERIFIED.
- `TransactionController::export` (تصدير كل المعاملات بأسماء العملاء والبائعين: `lavkoo:app/Http/Controllers/Admin/TransactionController.php:62-110`) غير مربوط بأي مسار (بحث `TransactionController` في `routes/` و`Modules/*/routes`: المطابقات تخص `RefundTransactionController` و`TransactionReportController` فقط). كود خامل. VERIFIED.
- جانب البائع: تقاريره وتصديراته محصورة بمعرّف المتجر (`lavkoo:app/Services/Reports/SellerReportService.php:311-325`)، وفي الـ API خلف `seller_can:finance.view` أو ما يماثلها.

### 2.9 وصول المشرف إلى بيانات العملاء والإخفاء (masking)

- كل ما يخص العملاء (قائمة، عرض، تعطيل، حذف، إضافة، تحديث ملف، محفظة، ولاء، تصدير) خلف مفتاح واحد `people` (`lavkoo:routes/admin/routes.php:581-620`). لا تفريق بين القراءة والكتابة ولا بين البيانات الشخصية والمالية. VERIFIED.
- تقرير المحفظة يحمّل كل الحركات وقائمة أسماء كل العملاء للمرشّح (`lavkoo:app/Http/Controllers/Admin/Customer/CustomerWalletController.php:47-93`). يتيح مسار إضافة الرصيد اليدوي تعديل رصيد محفظة عميل ضمن مفتاح `people` (SEC-E05-09). VERIFIED.
- **الإخفاء موجود لوضع العرض التجريبي فقط.** `DemoMaskingTrait` يقنّع البريد والهاتف عند `config('app.mode') == 'demo'` وعلى مسارات `admin|vendor|seller` (`lavkoo:app/Traits/DemoMaskingTrait.php:13-20`)، ويُستخدم في `User` (`lavkoo:app/Models/User.php:58`) و`Order` (`lavkoo:app/Models/Order.php:73`) و`Seller` (`lavkoo:app/Models/Seller.php:42`) و`DeliveryMan` (`lavkoo:app/Models/DeliveryMan.php:33`) و`Subscription` (`lavkoo:app/Models/Subscription.php:22`). في الوضع الحي تُعرض وتُصدَّر القيم كاملة. بحث `Str::mask|maskPhone|maskEmail` أرجع أيضاً منقّحات خاصة بسجلات الـ fulfillment والتحليلات، لا بعرض اللوحة. VERIFIED.
- `User::$hidden` يحجب أسرار الجلسة وبيانات البطاقة من الاستجابات المسلسلة (`lavkoo:app/Models/User.php:109-117`) — حماية للـ API لا للّوحة.

### 2.10 عناصر مساعدة

- `V2SidebarPin`: تثبيتات الشريط الجانبي لكل مستخدم بمفتاح (`user_type`, `user_id`) وكاش لكل مستخدم (`lavkoo:app/Models/V2SidebarPin.php:36-46`). لا يتأثر بتعدد المتاجر.
- `GenerateAdminRoutesJson`: يولّد فهرس بحث من مسارات GET الإدارية (`lavkoo:app/Console/Commands/GenerateAdminRoutesJson.php:44-56`)؛ البحث يُصفّى بالصلاحيات وقت العرض.
- `ConsoleGuard` يصنّف طلبات بوابة المطوّر إلى آمن/كتابة/محظور (`lavkoo:app/Services/DeveloperPortal/ConsoleGuard.php:33-49`)، و`OutboundUrlGuard` يحمي من SSRF (`lavkoo:app/Services/Security/OutboundUrlGuard.php:15-36`). كلاهما مستقل عن النطاق.

## 3. افتراضات المتجر الواحد / الدولة / العملة

| الافتراض | الدليل | الحالة |
|---|---|---|
| لا مفهوم لنطاق بيانات إطلاقاً: بحث `storefront_id|legal_entity|market_id|country_scope|store_scope` في `app/`، `Modules/`، `routes/`، `config/`، `database/` بلا نتائج. | بحث مسجَّل | VERIFIED |
| الصلاحية «ماذا» فقط (وحدة/قدرة) على مستوى الدور؛ لا «أين». الدور مشترك بين كل من يحمله. | `lavkoo:app/Utils/Helpers.php:374-390` | VERIFIED |
| مشرف أعلى واحد بدلالتين ثابتتين: `admins.id = 1` و`admin_roles.id = 1`. | `lavkoo:app/Http/Middleware/AdminMiddleware.php:21`؛ `lavkoo:app/Utils/Helpers.php:386-388` | VERIFIED |
| محفظة إدارة واحدة: `admin_wallets` بصف `admin_id = 1` (23 سطراً في 7 ملفات، عدّ نصي)، تُعرض أرصدتها في لوحة المعلومات. | `lavkoo:app/Http/Controllers/Admin/DashboardController.php:81`؛ `lavkoo:app/Utils/OrderManager.php:811-813` | VERIFIED |
| متجر داخلي واحد: `seller_is = 'admin'` مع `seller_id` 0/1. | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:139-148` | VERIFIED |
| علم «طلب جديد» واحد عالمي: فتح قائمة الطلبات يضع `checked = 1` على كل الطلبات، وعدّاد الأنشطة اللحظية يعدّ كل `checked = 0`. | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:137`؛ `lavkoo:app/Http/Controllers/Admin/DashboardController.php:359` | VERIFIED |
| التجميع المالي بلا بُعد عملة أو كيان: مجموع واحد ثم تحويل للعملة الافتراضية. | `lavkoo:app/Http/Controllers/Admin/DashboardController.php:297-318` | VERIFIED |
| سجل التدقيق والموافقات بلا عمود نطاق؛ `approval_requests` يحمل `currency` لكنه لا يحمل كياناً. | `lavkoo:app/Models/AuditLog.php:15-19`؛ `lavkoo:app/Models/ApprovalRequest.php:18-23` | VERIFIED |
| عزل البائع بـ `seller_id` فقط؛ البائع = متجر واحد = نطاق واحد. | §2.6 | VERIFIED |
| صفحتا دخول فقط (مشرف/موظف) بإعدادين عامّين. | `lavkoo:app/Http/Controllers/Admin/Auth/LoginController.php:30، 50-58، 71-75` | VERIFIED |

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| GCC-14 — دخول موظف واحد بنطاقات دول/كيانات فوق صلاحيات الأدوار الحالية | حارس واحد وجدول واحد للمشرفين؛ نقطة فرض مركزية (`module` alias + دالة واحدة)؛ سابقة توسيع المفاتيح دون تغيير مخطط؛ نمط `SellerPrincipal` جاهز للاقتداء. | لا يوجد أي حامل لنطاق البيانات (لا جدول، لا سياق جلسة، لا Global Scope)؛ الاستعلامات الإدارية مبعثرة داخل المتحكمات؛ 82 تعليمة مسار موثَّقة خارج نموذج الوحدات؛ إدارة الصلاحيات غير مفصولة عن `people`. | `lavkoo:app/Http/Middleware/ModulePermissionMiddleware.php:20-31`؛ `lavkoo:app/Services/Marketplace/SellerPrincipal.php:66-96`؛ §2.4 |
| GCC-14 — «enforce in backend and APIs» | جانب البائع يفرض على المسار لا على القائمة، وبالمفاتيح نفسها للويب والـ API. | جانب الإدارة يعتمد في 61 موضعاً على إخفاء القوائم، والمفتاح `dashboard` بلا فرض على المسار. | `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:26-59`؛ `lavkoo:resources/views/admin-views/system/dashboard.blade.php:8` |
| GCC-13 — هوية عميل واحدة مع فصل المحافظ والطلبات لكل متجر/كيان؛ «local admins must not access unrelated-country customer transactions» | — | كل بيانات العملاء ومحافظهم وولائهم خلف `people` بلا نطاق؛ التصدير يخرج كل العملاء بهواتفهم وبريدهم؛ الإخفاء لوضع demo فقط. | `lavkoo:routes/admin/routes.php:581-620`؛ `lavkoo:app/Traits/DemoMaskingTrait.php:13-20` |
| Foundation: Legal Entity (فواتير، ضرائب، تسويات، محاسبة لكل شركة) | محرك الموافقات يحمل `amount` و`currency`؛ تقارير البائع محصورة بالمتجر. | تقارير الأرباح والمعاملات والضرائب تجمّع النظام كله بلا بُعد كيان أو عملة؛ محفظة إدارة واحدة؛ تفويض تقارير الضرائب مشمول بـ `SEC-E05-03`. | `lavkoo:app/Http/Controllers/Admin/ReportController.php:138-149`؛ `lavkoo:app/Http/Controllers/Admin/DashboardController.php:297-318` |
| Foundation: تمييز Seller/Shop عن Country/Storefront (عدم إعادة استخدام `seller_id`) | `seller_id` مستخدم باتساق كـ «المتجر التجاري للبائع» في 201 + 368 موضعاً؛ `SellerPrincipal::sellerId()` موثَّق بأنه «المتجر لا الشخص». | أي محاولة لتمثيل الدولة عبر `seller_id` تكسر هذه المواضع كلها؛ يلزم بُعد مستقل. | `lavkoo:app/Services/Marketplace/SellerPrincipal.php:65-69` |
| GCC-12 — إيقاف متجر دون كسر الطلبات والمدفوعات والدعم | — | لا يوجد ما يربط صلاحية موظف بحالة متجر؛ يلزم أن تبقى أفعال الدعم والاسترجاع متاحة لموظفي المتجر الموقوف. | §3 |
| GCC-09 / التغطية I — تطبيق البائع | الـ API يعيد رمز خطأ مميّزاً `permission` و`owner_only` و`api_key`. | يلزم التحقق من أن تطبيق البائع يعالج هذه الرموز ومن أثر أي بُعد متجر جديد على رموز الموظفين. | `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:50-54`؛ `lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:34-38` |
| القيد المؤقت: caches/notifications/queues بنطاق السوق | تثبيتات الشريط الجانبي لكل مستخدم. | علم `orders.checked` وعدّادات الأنشطة اللحظية عالمية؛ صندوق الموافقات وسجل التدقيق بلا نطاق. | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:137`؛ `lavkoo:app/Http/Controllers/Admin/Marketplace/ApprovalController.php:34-39` |
| KWD/BHD/OMR (3 منازل عشرية) | — | `ApprovalRequest.amount` يُحوَّل إلى `float`؛ مجاميع التقارير بلا عملة. | `lavkoo:app/Models/ApprovalRequest.php:25-32` |

### أين يُركَّب نطاق البيانات بأقل تغيير (توصية توثيقية فقط)

1. **حامل النطاق:** جدول ربط جديد لكل مشرف (لا لكل دور) يحدّد المتاجر/الكيانات المسموحة ونوع الفعل (قراءة/كتابة/تصدير/موافقة). الأدوار ومفاتيح `module_access` تبقى كما هي حرفياً (توافق كامل مع الأدوار الحالية). المشرف الرئيسي (`admin_role_id == 1`) يُعامل كنطاق شامل للحفاظ على السلوك الحالي.
2. **كائن Principal للإدارة** على غرار `SellerPrincipal`: يُبنى مرة في الطلب من الجلسة، ويحمل المتجر الحالي المختار والنطاقات المسموحة، ويُوضع على خصائص الطلب ليقرأه الـ middleware وكاتب التدقيق والاستعلامات.
3. **الفرض على المسار:** وسيط جديد بجانب `module:` يُركَّب في إعلانات المجموعات العليا فقط (سبعة مواضع في الملفات الأساسية والتابعة بحسب عمود «إعلان المجموعة» في جدول §2.4، إضافةً إلى ملفات الوحدات الثلاث)؛ لا حاجة لتعديل 1,034 تعليمة مسار.
4. **الفرض على الاستعلام (الجزء الأثقل):** نطاق Eloquent لكل نموذج مملوك لمتجر/كيان يُفعَّل من الـ Principal، لأن التقارير تستعلم مباشرة من المتحكمات ولن تمرّ من مستودع واحد. إضافة مرشّح في الواجهة وحده لا يكفي لأي بند في جدول §2.8.
5. **التدقيق:** ختم النطاق في `AuditLogger::record` (نقطة واحدة يمرّ بها 95 ملفاً) بدل تعديل المستدعين.
6. **قبل أي نطاق:** إدخال المسارات الـ 82 تحت نموذج الوحدات، وفصل قدرة «إدارة الأدوار والموظفين والنطاقات».

### فئات ستتسرّب بين المتاجر إن أُضيفت مرشّحات واجهة فقط

- **الاستعلامات بالمعرّف المباشر:** عرض عميل/طلب/استرجاع/بائع بمعرّف في الـ URL (مثل `lavkoo:routes/admin/routes.php:584-585`) لا تمرّ بمرشّحات القائمة، فيلزم فرض النطاق على الاستعلام نفسه.
- **التصدير:** كل صفوف جدول §2.8؛ نقاط التصدير تعيد بناء الاستعلام من معاملات الطلب (مثل `lavkoo:app/Http/Controllers/Admin/Customer/CustomerWalletController.php:125-134`).
- **لوحات المعلومات ونقاط JSON الإحصائية:** `lavkoo:app/Http/Controllers/Admin/DashboardController.php:259-295`.
- **قوائم الاختيار والبحث المساعد (autocomplete):** قوائم أسماء العملاء والمنتجات والبائعين.
- **الإشعارات والعدّادات:** الطلبات الجديدة، الرسائل، طلبات إعادة التخزين (`lavkoo:app/Http/Controllers/Admin/DashboardController.php:357-399`).
- **سجل التدقيق وصندوق الموافقات:** صف واحد لكل النظام.
- **جانب البائع:** أي بائع يعمل في أكثر من متجر دولة سيرى بياناته مجتمعة لأن الحدّ الوحيد هو `seller_id`.

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أقل إضافة موصى بها |
|---|---|---|---|
| `ModulePermissionMiddleware` + `Helpers::module_permission_check` + مفاتيح الوحدات الـ 14 | Preserve | آلية عاملة تغطي 91.4% من المسارات؛ GCC-14 ينص على إبقائها. | لا تغيير؛ يُضاف وسيط نطاق مجاور. |
| الخدمات الدقيقة الأربع (Theme/Monitoring/Analytics/Developer) | Preserve / Extend | نمط ناجح لتوسيع القدرات داخل العمود نفسه مع توافق خلفي. | قدرات جديدة بالنمط نفسه (مثل تصدير، إدارة أدوار). |
| جدول `admin_roles` وعمود `module_access` | Preserve | بيانات إنتاج قائمة؛ أي تغيير في الشكل يكسر الأدوار الحالية. | لا يُخزَّن النطاق فيه؛ جدول ربط جديد. |
| أفعال «المشرف الرئيسي فقط» | Preserve | ضوابط أمان مقصودة (نقل بيانات المشترين، استثناء go-live). | تعريف مقابل لها على مستوى الكيان إن لزم. |
| `SellerPrincipal` / `SellerPermissionService` / `EnsureSellerPermission` / `EnsureSellerIsOwner` / `SellerApiAuthMiddleware` | Preserve | نموذج تفويض حديث موحَّد للويب والـ API، إغلاق افتراضي، وعقد قائم مع تطبيق البائع. | بُعد متجر اختياري في الـ Principal لاحقاً دون تغيير دلالة `sellerId()`. |
| `SellerStaffAccessMiddleware` (خريطة الـ URL) | Refactor | يعتمد على مقاطع المسار ويتباين مع بوابات الـ API. | توحيد تدريجي على `seller_can:` لكل مسار. |
| العزل اليدوي بـ `seller_id` في متحكمات `Vendor` | Refactor | اتساق غير كامل (§2.6). | نطاق نموذج أو خدمة مقيِّدة، بعد مسح المواضع الـ 78. |
| `AuditLogger` / `AuditTrail` / `AuditTrailObserver` / `AuditedBuilder` | Preserve / Extend | نقطة كتابة واحدة مع تنقيح أسرار. | ختم النطاق مركزياً؛ ضمّ `AdminRole` للتحديثات الجماعية؛ ترحيل رسمي للجدول؛ سياسة احتفاظ. |
| `ApprovalEngine` | Preserve / Extend | محرك عام بقاعدتَي maker-checker ومفتاح فريد. | حقل كيان/متجر على الطلب وتصفية الصندوق به؛ تخزين المبلغ بدقة عشرية. |
| متحكمات التقارير الإدارية (استعلامات مباشرة) | Refactor | لا نقطة مركزية للنطاق ولا بُعد عملة. | خدمة تقارير مقيَّدة بالنطاق على غرار `SellerReportService`. |
| أصناف `app/Exports` والقوالب | Extend | تستقبل بيانات جاهزة؛ العزل مسؤولية المتحكم. | فرض النطاق قبل البناء، وقدرة تصدير مستقلة، وإخفاء اختياري. |
| `DemoMaskingTrait` | Extend | وصلة جاهزة على مستوى accessors النماذج. | شرط إخفاء مبني على نطاق الموظف بدل وضع demo فقط. |
| جدول نطاقات الموظفين، Principal إداري، وسيط نطاق، سياق «المتجر الحالي» في اللوحة | New | غير موجودة. | كما في §4. |
| اختبارات تغطية المسارات | Extend | موجودة لوحدتين فقط. | اختبار يثبت أن كل مسار إداري موثَّق يحمل وحدة أو مُدرَج في قائمة استثناء صريحة. |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E05-F01 | لا يوجد أي حامل لنطاق بيانات الموظف (متجر/كيان/دولة): لا جدول ولا سياق ولا Global Scope. | P0 | VERIFIED | بحث §3؛ `lavkoo:app/Utils/Helpers.php:374-390` | GCC-14 وGCC-13 غير قابلين للتحقيق بلا بناء جديد؛ كل موظف يرى كل المتاجر. | جدول نطاقات لكل مشرف + Principal + وسيط + نطاقات نماذج (§4). |
| E05-F02 | التقارير والتصديرات ولوحة المعلومات تجمّع النظام كله باستعلامات مباشرة داخل المتحكمات، بلا معامل نطاق وبلا بُعد عملة. | P0 | VERIFIED | `lavkoo:app/Http/Controllers/Admin/ReportController.php:138-149`؛ `lavkoo:app/Http/Controllers/Admin/DashboardController.php:297-318` | تسرّب مالي بين الكيانات، وجمع عملات مختلفة في رقم واحد عند تشغيل دولة ثانية. | خدمة تقارير مقيَّدة بالنطاق والعملة؛ منع التجميع العابر للعملات. |
| E05-F03 | بيانات العملاء ومحافظهم وولاؤهم وتصديرها خلف مفتاح واحد `people` بلا نطاق وبلا إخفاء في الوضع الحي. | P0 | VERIFIED | `lavkoo:routes/admin/routes.php:581-620`؛ `lavkoo:resources/views/file-exports/customer-list.blade.php:51-52` | يخالف نص القرار بشأن عدم اطلاع الإداريين المحليين على معاملات دول أخرى. | نطاق على كل نقاط العملاء، قدرة تصدير مستقلة، إخفاء حسب النطاق. |
| E05-F04 | SEC-E05-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P0 | VERIFIED | الملحق المقيّد | يُسقط نموذج الأدوار الحالي وأي نطاق يُبنى فوقه. | تُعالج قبل أي عمل على GCC-14. |
| E05-F05 | SEC-E05-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P0 | VERIFIED | الملحق المقيّد | إدارة الصلاحيات غير مفصولة عن وحدة تشغيلية عادية؛ النطاقات سترث الخلل. | قدرة مستقلة لإدارة الأدوار/الموظفين/النطاقات. |
| E05-F06 | 82 تعليمة مسار إدارية موثَّقة بلا `module:` (39 في الملف الرئيسي و43 في ملفات الوحدات)؛ التصنيف في `SEC-E05-03` (رُفعت الدرجة إلى P0 بسبب مسارات رفع حزم الإضافات). | P0 | VERIFIED | §2.4 (عدّ آلي)؛ الملحق المقيّد | أي نطاق يُركَّب بجانب `module:` لن يشملها؛ بعضها يمس بيانات يجب عزلها لكل كيان قانوني. | إدخالها تحت وحدات، واختبار تغطية شامل قبل بناء النطاقات. |
| E05-F07 | جداول `audit_logs`، `approval_requests`، `approval_decisions`، `seller_staff`، `seller_roles`، `seller_api_keys` لا يُنشئها أي ترحيل في المستودع؛ الكود يصمت عند غيابها. | P1 | VERIFIED (غياب الترحيل) / UNKNOWN (الإنتاج) | `lavkoo:tests/Support/BuildsAuditLogTable.php:8`؛ `lavkoo:app/Services/AuditLogger.php:41-44` | قد لا يكون هناك سجل تدقيق أو موظفو بائع في الإنتاج؛ لا مخطط موثَّق يُبنى عليه. | حصر مخطط الإنتاج، ثم ترحيلات رسمية idempotent. |
| E05-F08 | سجل التدقيق والموافقات بلا عمود نطاق، وعرضهما عالمي؛ عزل سجل البائع يعتمد مطابقة نصية على JSON. | P1 | VERIFIED | `lavkoo:app/Models/AuditLog.php:15-19`؛ `lavkoo:app/Services/Marketplace/SellerAuditTrailService.php:66-74`؛ `lavkoo:app/Http/Controllers/Admin/Marketplace/ApprovalController.php:34-39` | موظف دولة يرى أفعال وموافقات دول أخرى؛ الموافقة المزدوجة قد تُستكمل من خارج الكيان. | أعمدة نطاق تُختم مركزياً؛ تصفية الشاشات؛ قاعدة «الموافق من الكيان نفسه». |
| E05-F09 | فجوات تغطية التدقيق: العمليات الإدارية الكلاسيكية (محفظة العميل، الطلبات، الاسترجاع، البائعون) وتعديل/حذف الأدوار لا تُسجَّل. | P1 | VERIFIED / INFERRED | `lavkoo:app/Repositories/AdminRoleRepository.php:53-62`؛ عدّ `audit` في المتحكمات الكلاسيكية (§2.7) | لا أثر تدقيقياً لبعض الأفعال الإدارية الحساسة؛ خطر مالي ورقابي يتضاعف مع تعدد الكيانات. | مراقب/Builder مدقَّق لهذه النماذج، وموافقة لإضافة الرصيد فوق عتبة. |
| E05-F10 | عزل بيانات البائع يدوي وغير متسق في لوحة الويب (SEC-E05-04، SEC-E05-06). | P1 | VERIFIED (عيّنة) | §2.6؛ الملحق المقيّد | النمط نفسه لو استُخدم لعزل المتاجر سينتج تسرّباً مماثلاً. | مسح كامل + نطاق نموذج/خدمة مقيِّدة. |
| E05-F11 | تباين تفويض موظف البائع بين لوحة الويب و API v3 (SEC-E05-05). | P1 | VERIFIED | الملحق المقيّد؛ `lavkoo:app/Http/Middleware/SellerStaffAccessMiddleware.php:65-135` | صلاحيات الموظف تختلف حسب القناة؛ يخالف مبدأ «الويب والـ API متطابقان». | توحيد الفرض على `seller_can:` لكل مسار في القناتين. |
| E05-F12 | المفتاح `dashboard` يُستهلك في قالب فقط؛ و61 موضع إخفاء قوائم لا تُعدّ فرضاً. | P1 | VERIFIED | `lavkoo:resources/views/admin-views/system/dashboard.blade.php:8` | مرشّحات الواجهة ليست حدّ أمان؛ لوحة المعلومات أول ما يتأثر بتعدد الكيانات. | وسيط على مسارات اللوحة ونقاطها الإحصائية. |
| E05-F13 | عدّادات وإشعارات إدارية عالمية (`orders.checked`، الأنشطة اللحظية). | P1 | VERIFIED | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:137`؛ `lavkoo:app/Http/Controllers/Admin/DashboardController.php:359` | تنبيهات الطلبات والأنشطة مشتركة بين كل المشرفين والكيانات. | حالة «مطّلع عليه» لكل متجر/موظف. |
| E05-F14 | مشرف أعلى واحد بشرطين ثابتين (`id = 1`، `admin_role_id = 1`) ومحفظة إدارة واحدة `admin_id = 1`. | INFO | VERIFIED | `lavkoo:app/Http/Middleware/AdminMiddleware.php:21`؛ `lavkoo:app/Utils/OrderManager.php:811-813` | يجب تعريف مقابله في نموذج الكيانات (مشرف عام مقابل مشرف كيان؛ محفظة لكل كيان). | قرار تصميم في مرحلة لاحقة؛ لا تغيير على السجل 1. |
| E05-F15 | `module_access` قائمة حرّة تُخزَّن دون مطابقة مقابل كتالوج، وتحمل 14 مفتاح وحدة + 19 قدرة دقيقة. | INFO | VERIFIED | `lavkoo:app/Http/Controllers/Admin/Employee/CustomRoleController.php:50-62`؛ `lavkoo:app/Http/Requests/Admin/CustomRoleRequest.php:31-39` | مرونة للتوسعة، لكن لا ضمان لسلامة القيم؛ مفاتيح قديمة قد تبقى. | كتالوج مركزي واحد يتحقق منه الحفظ. |
| E05-F16 | لا سياسة احتفاظ لسجل التدقيق. | P2 | VERIFIED | بحث §2.7 | نمو غير محدود؛ متطلبات احتفاظ قد تختلف بين الدول. | سياسة احتفاظ لكل كيان مع أرشفة. |
| E05-F17 | محرك الموافقات مستخدَم لمسار واحد (الدفعات) واختيارياً؛ الرصيد اليدوي والاسترجاع خارج المحرك (انظر SEC-E05-09 لبند الرصيد). | P2 | VERIFIED | `lavkoo:app/Services/Marketplace/PayoutService.php:150-171` | ضوابط مالية ضعيفة لعمليات يدوية عابرة للكيانات. | توسيع المحرك تدريجياً بمسارات جديدة. |
| E05-F18 | لا اختبارات لـ `SellerPermissionService`/`SellerStaff`/`ApprovalEngine`، والاختبار `SellerCenterRouteCollisionTest` المذكور في التعليق غير موجود. | P2 | VERIFIED | بحث `SellerStaff|seller_can|SellerPermissionService|SellerPrincipal` في `tests/`: ملفان يذكرانها عرضاً لا اختباراً مخصَّصاً (`PanelAddressTestCase` و`ApiSellerAddressTest`)؛ `find tests -iname "*SellerCenter*"`: لا نتائج؛ التعليق في `lavkoo:app/Providers/RouteServiceProvider.php:171-175` | لا شبكة أمان عند توسيع نموذج التفويض. | اختبارات عقد قبل أي تعديل. |
| E05-F19 | كود خامل: `TransactionController` غير مربوط، `AuthorizationToolMiddleware` غير مسجَّل، `SellerApiMiddleware` فارغ. | P2 | VERIFIED | `lavkoo:app/Http/Controllers/Admin/TransactionController.php:62-110`؛ `lavkoo:app/Http/Middleware/AuthorizationToolMiddleware.php:19-23` | خطر إعادة ربطه لاحقاً دون نطاق. | توثيقه كغير مستخدم؛ عدم الاعتماد عليه. |
| E05-F20 | SEC-E05-07: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |

## 7. أسئلة مفتوحة وعناصر UNKNOWN

1. **محتوى `admin_roles` في الإنتاج:** الأدوار الفعلية ومفاتيحها، وهل بقيت مفاتيح قديمة قبل إعادة التسمية. يحسمه: قراءة الجدول من قاعدة الإنتاج (أو تشغيل أمر الفحص من قِبل المالك).
2. **وجود جداول `audit_logs`، `approval_requests`، `approval_decisions`، `seller_staff`، `seller_roles`، `seller_api_keys` في الإنتاج ومخططها الفعلي ومصدر إنشائها.** يحسمه: مخطط قاعدة الإنتاج، أو المستودع القديم `pharmacyksa98` (UNKNOWN — missing access).
3. **عدد الموظفين الإداريين وموظفي البائعين الفعليين وحجم سجل التدقيق.** يحسمه: قاعدة الإنتاج.
4. **قيم الإعدادات:** `settlement_maker_checker`، عتبة الموافقة المزدوجة للدفعات، `employee_login_url`. يحسمه: `business_settings` في الإنتاج.
5. **هل شُغّل الـ seeder الافتراضي للمشرف وهل غُيّرت قيمه.** يحسمه: جدول `admins` في الإنتاج.
6. **وحدة Auction:** يستوردها `DashboardController` ومذكورة في `modules_statuses.json` لكن مجلدها غير موجود تحت `Modules/`. هل هي منشورة في الإنتاج؟ يحسمه: نظام ملفات الخادم (خارج هذا الملحق).
7. **تطبيق البائع:** هل يعالج رموز الرفض `permission` و`owner_only`، وهل يدعم دخول الموظفين؟ يحسمه: ملحق تطبيق البائع.
8. **قرار تصميم مطلوب من المالك (لا يعيد فتح قرار معتمد):** هل المشرف الرئيسي الحالي يبقى ذا نطاق شامل على كل الدول والكيانات، أم يُستحدث «مشرف كيان» أعلى لكل شركة؟ GCC-14 لا يحسم ذلك نصاً.

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| حارس `admin` بجلسة ومزوّد `admins` | `lavkoo:config/auth.php:49-52` | VERIFIED |
| حقول المشرف وعلاقة الدور | `lavkoo:app/Models/Admin.php:38-53`؛ `lavkoo:app/Models/Admin.php:77-80` | VERIFIED |
| `module_access` نصّ JSON | `lavkoo:app/Models/AdminRole.php:23-36` | VERIFIED |
| منطق فحص الوحدة واستثناء الدور 1 | `lavkoo:app/Utils/Helpers.php:374-390` | VERIFIED |
| وسيط الوحدة | `lavkoo:app/Http/Middleware/ModulePermissionMiddleware.php:20-31` | VERIFIED |
| الأسماء المستعارة للـ middleware | `lavkoo:bootstrap/app.php:111-127` | VERIFIED |
| استثناء المعرّف 1 من فحص الحالة | `lavkoo:app/Http/Middleware/AdminMiddleware.php:18-29` | VERIFIED |
| كتالوج الوحدات الـ 14 | `lavkoo:app/Enums/GlobalConstant.php:1260-1275` | VERIFIED |
| تخزين `modules[]` دون مطابقة الكتالوج | `lavkoo:app/Http/Controllers/Admin/Employee/CustomRoleController.php:50-62`؛ `lavkoo:app/Http/Requests/Admin/CustomRoleRequest.php:31-39` | VERIFIED |
| إعادة تسمية المفاتيح | `lavkoo:database/migrations/2026_04_23_120000_rename_admin_role_module_access_keys.php:8-17` | VERIFIED |
| القدرات الدقيقة | `lavkoo:app/Services/Theme/ThemePermissionService.php:39-68`؛ `lavkoo:app/Services/Monitoring/MonitoringPermissionService.php:70-93`؛ `lavkoo:app/Services/Analytics/AnalyticsPermissionService.php:82-109`؛ `lavkoo:app/Services/DeveloperPortal/DeveloperPortalPermissionService.php:50-73` | VERIFIED |
| أفعال المشرف الرئيسي فقط | `lavkoo:app/Http/Controllers/Admin/SalesChannels/ChannelAccountController.php:187-189`؛ `lavkoo:app/Http/Controllers/Admin/Fulfillment/IntegrationCenterController.php:368-380` | VERIFIED |
| مجموعتا الإدارة الأساسيتان | `lavkoo:routes/admin/routes.php:128`؛ `lavkoo:routes/admin/routes.php:179` | VERIFIED |
| الموظفون والأدوار تحت `people` | `lavkoo:routes/admin/routes.php:658`؛ `lavkoo:routes/admin/routes.php:671` | VERIFIED |
| تحميل ملفات المسارات الإدارية | `lavkoo:app/Providers/RouteServiceProvider.php:187-207`؛ `lavkoo:app/Providers/SalesChannelsServiceProvider.php:66-70` | VERIFIED |
| نموذجا موظف البائع ودوره | `lavkoo:app/Models/SellerStaff.php:16-38`؛ `lavkoo:app/Models/SellerRole.php:10-26` | VERIFIED |
| كتالوج صلاحيات البائع | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:30-41` | VERIFIED |
| حلّ الرمز إلى Principal | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:98-120`؛ `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:41-93` | VERIFIED |
| بوابة الصلاحية وبوابة المالك | `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:26-59`؛ `lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:24-41` | VERIFIED |
| خريطة الويب للموظف | `lavkoo:app/Http/Middleware/SellerStaffAccessMiddleware.php:65-135` | VERIFIED |
| دخول الموظف على الويب بهوية المالك | `lavkoo:app/Http/Controllers/Vendor/Auth/StaffLoginController.php:37-76` | VERIFIED |
| مجموعات مسارات البائع | `lavkoo:routes/vendor/routes.php:87`؛ `lavkoo:routes/seller/routes.php:40-48`؛ `lavkoo:routes/rest_api/v3/seller.php:72` | VERIFIED |
| لا Global Scope للبائع | `lavkoo:app/Models/Order.php:366`؛ `lavkoo:app/Models/Review.php:115` | VERIFIED |
| نمط العزل اليدوي | `lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:430`؛ `lavkoo:app/Services/Reports/SellerReportService.php:311-325` | VERIFIED |
| كاتب التدقيق وتحديد الفاعل | `lavkoo:app/Services/AuditLogger.php:34-66`؛ `lavkoo:app/Services/AuditLogger.php:119-144` | VERIFIED |
| النماذج المدقَّقة تلقائياً | `lavkoo:app/Services/AuditTrail.php:63-80`؛ `lavkoo:app/Providers/ObserverServiceProvider.php:70-77` | VERIFIED |
| جدول التدقيق بلا ترحيل | `lavkoo:tests/Support/BuildsAuditLogTable.php:8-27` | VERIFIED |
| شاشة التدقيق | `lavkoo:routes/admin/routes.php:241-243`؛ `lavkoo:app/Http/Controllers/Admin/Marketplace/AuditLogController.php:23-62` | VERIFIED |
| محرك الموافقات | `lavkoo:app/Services/ApprovalEngine.php:40-79`؛ `lavkoo:app/Services/ApprovalEngine.php:86-132` | VERIFIED |
| استخدام المحرك للدفعات | `lavkoo:app/Services/Marketplace/PayoutService.php:150-171` | VERIFIED |
| تصدير العملاء دون إخفاء | `lavkoo:app/Http/Controllers/Admin/Customer/CustomerController.php:403-427`؛ `lavkoo:resources/views/file-exports/customer-list.blade.php:51-52` | VERIFIED |
| إضافة رصيد المحفظة من الإدارة | `lavkoo:app/Http/Controllers/Admin/Customer/CustomerWalletController.php:96-123` | VERIFIED |
| لوحة المعلومات تجمّع النظام | `lavkoo:app/Http/Controllers/Admin/DashboardController.php:56-118` | VERIFIED |
| استعلامات التقارير المباشرة | `lavkoo:app/Http/Controllers/Admin/ReportController.php:138-149` | VERIFIED |
| الإخفاء لوضع demo فقط | `lavkoo:app/Traits/DemoMaskingTrait.php:13-20` | VERIFIED |
| علم `checked` العالمي | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:137` | VERIFIED |
| محفظة الإدارة `admin_id = 1` | `lavkoo:app/Http/Controllers/Admin/DashboardController.php:81`؛ `lavkoo:app/Utils/OrderManager.php:811-813` | VERIFIED |
| اختبارات تغطية المسارات الموجودة | `lavkoo:tests/Feature/Security/AdminRouteAuthenticationTest.php:23-31`؛ `lavkoo:tests/Feature/SalesChannels/ModulePermissionsTest.php:25-37` | VERIFIED |

## 9. سجل التحقق المستقل

> أُجري التحقق المستقل بعد اكتمال المسودة: فحص الاستشهادات آلياً، ثم إعادة قراءة الكود للبنود P0 وP1 وعدد من المطالبات الداعمة لتصميم GCC، ثم فحص غياب كل مطالبة بأنماط بحث بديلة، ثم مراجعة الصياغة والدرجات وتنقيح المحتوى الأمني.

| البند | العدد |
|---|---|
| المطالبات التي جرى فحصها | 88 |
| مؤكَّدة دون تغيير | 69 |
| مصحَّحة (رقم أو استشهاد أو درجة أو صياغة) | 13 |
| مدحوضة | 2 |
| غير قابلة للتحقق بالقراءة الساكنة | 4 |

**التغطية:** أُعيد فحص بنود §6 من F01 إلى F20، والمطالبات المرتبطة بها في §2 و§4، وبنود الملحق المقيّد SEC-E05-01 إلى SEC-E05-09. فُحصت بنود SEC-E05-04 بعيّنة من أربعة مواضع فقط.

**التصحيحات:**
1. رأس الوثيقة: HEAD وقت القراءة `lavkoo@046d623d`، وعند التحقق `lavkoo@41201499`، والفرق توثيقي فقط.
2. §1: عدد مطابقات `Route::<verb>(` هو 810 (لا 809).
3. §2.3: عدد دوال `authorize` التجارية المطابقة لنمط التفويض هو أربعة (لا خمسة).
4. §2.7: قيد التفرّد المفترض في `approval_decisions` غير معرَّف في المستودع، فصار UNKNOWN بدل VERIFIED.
5. §2.7: قيمة `settlement_maker_checker` تستند إلى تعليق الكود، والقيمة الفعلية UNKNOWN.
6. §2.8: عدد استدعاءات التصدير 82 (لا 91).
7. §2.8: عبارة العملة صُحِّحت؛ المواضع الوحيدة استدعاءات لدوال التحويل، وعدد المتحكمات المفحوصة عشرة.
8. §2.6: استدعاءات `request->seller` في API v3 هي 207 في 34 من 38 ملفاً (لا 368).
9. §3: عدد سطور `admin_wallets` هو 23 (لا 21).
10. §3: مرجع صفحات الدخول يشمل سطر الإعداد `employee_login_url`، و69-75 مرجع لتسجيل الخروج.
11. §6 F06: رُفعت الدرجة من P1 إلى P0 بسبب مسارات رفع حزم الإضافات (الملحق المقيّد).
12. الملحق المقيّد: رُفعت درجة SEC-E05-03 إلى P0 للسبب نفسه.
13. §6 F09: حُذف مرجع محفظة العميل من الأدلة ونُقل إلى SEC-E05-09.

**المطالبات المدحوضة:**
- «ملفان للعناوين فقط» في اختبارات قطاع البائع: الملفان `PanelAddressTestCase` و`ApiSellerAddressTest` يذكران المفاتيح عرضاً، والنتيجة (لا اختبار مخصَّص) صحيحة.
- «لا نتائج» لبحث `audit_logs|AuditLog` في `app/Console` و`app/Jobs`: وُجدت استدعاءات لخدمة الكتابة في أمرين، والاستنتاج (لا حذف ولا تقليم) بقي صحيحاً.

**غير قابلة للتحقق بالقراءة الساكنة (UNKNOWN):**
- قيد التفرّد في جدول `approval_decisions` في قاعدة الإنتاج.
- وجود جداول `audit_logs` و`seller_staff` و`seller_roles` و`seller_api_keys` ومحتوى `admin_roles` في الإنتاج.
- عدد المشرفين الفعليين وموظفي البائعين وحجم سجل التدقيق.
- اكتمال مسارات كتابة المخزون على قناة الويب (الملحق المقيّد، SEC-E05-08).

**الملاحظات المتبقية (أسماء الأقسام):** §2.5 (تعيين مفتاح المخزون)، §2.7 (قيد التفرّد وحجم السجل)، §2.9 (مسار الرصيد اليدوي)، §7 (البنود 1 إلى 5 وقرار المشرف الرئيسي).

**فجوات التغطية:** لم يُعَد فحص كل موضع من مواضع الإخفاء الـ 61 في القوالب ولا كل موضع من مواضع `getFirstWhere` الـ 78 في لوحة البائع، فاقتُصر على العيّنات. لم تُقرأ `SellerApiKeyService` ولا `CustomerWalletService` ولا `ApprovalEngine::open` ولا `AddFundRequest`. لم يُنفَّذ أي كود ولم تُجرَ أي اختبارات.

**البنود المنقولة إلى الملحق المقيّد في هذه الجولة:** SEC-E05-08 وSEC-E05-09 (بندان). مجموع بنود الملحق 9 (SEC-E05-01 إلى SEC-E05-09). ونُقّحت صياغة عدد من الفقرات التفصيلية في §2 و§4 و§5 و§8 بصياغة تقريرية، وأُزيل وصف آلية كود خامل غير مسجَّل.
