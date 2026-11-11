# E07 — نظام الثيمات في الـ backend (Theme system on the backend)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo@7f42777` (الكود المدقَّق). الـ HEAD وقت التحقق `lavkoo@4120149`؛ الفرق عن `7f42777` ثلاثة ملفات توثيق تحت `docs/gcc/audits/` ولا يغيّر أي كود (تم التحقق بـ `git diff --name-only`). للمقابلة مع العميل فقط: `lavkooapp@eedbbb8` و`lavkooappseller@8ea0092`.

## 1. النطاق وما تم فحصه

**ما تمت قراءته كاملاً (سطراً بسطر):** `ThemeManager`، `ThemeDelivery`، `StorefrontThemeRenderer`، `ViewerContext`، `Channel`، `SectionVisibility`، `ExperiencePageService`، `ThemePreviewToken`، `ThemeSyncBeacon`، `ThemePermissionService`، `ComponentCapabilityRegistry`، `ThemeAssetService`، `LocalisedSettings`، النماذج العشرة (`Theme`، `ThemeVersion`، `ThemeSection`، `ThemeBlock`، `ThemeAsset`، `ExperiencePage`، `ExperienceCampaign`، `ExperienceExperiment`، `AppCarouselSetting`، `Banner`)، `ThemeSectionController` (API)، `ThemeManagementController`، `ExperiencePageController`، `ThemePublishDue`، `CommerceCampaignsTick`، `CampaignResolver`، `ExperimentResolver`، `NoIndexThemePreview`، `theme-helpers.php`، `ThemeServiceProvider`، `ThemeRepository`، `BannerPlacementService`، `BannerService`.

**ما تمت قراءته جزئياً (الأجزاء الحاملة للمنطق):** `ThemeBuilderService` (584 سطراً)، `ThemeBuilderController` (940)، `ThemeSettingsController`، `AppBuilderController`، `CampaignController`، `ExperimentController`، `ThemePortabilityService` (509)، `ThemeBannerLink` (446)، `SectionDataResolver` (954)، `SectionRegistry` (1340)، `ActionResolver`، `ThemeSourceMap`، `PublishValidator`، `SectionReach`، `BuilderReadiness`، `EntityPageBannerService`، `CacheManagerTrait` (دوال الـ banners)، مجموعات المسارات في `routes/admin/routes.php` و`routes/rest_api/v1/api.php` و`routes/web/routes.php`، وقوالب `resources/views/theme-sections/*` و`resources/themes/default/**` المتصلة بالثيم.

**الحجم التقريبي:** 28 ملفاً في `app/Services/Theme` (9,005 أسطر مع ملفَّي `app/Services/Storefront`)، 11 ملفاً في `app/Services/Commerce` (2,097 سطراً)، 37 قالب قسم في `resources/views/theme-sections/types`، 270 ملفاً في `resources/themes/default` (منها 264 Blade). إجمالي الملفات المفحوصة نحو 90.

**أنماط البحث المستخدمة (rg/grep):** `theme` في `routes/`؛ `Schema::(create|table)` مع أسماء الجداول؛ `theme_versions|theme_sections|theme_blocks|theme_assets|experience_pages|experience_campaigns|experience_experiments|app_carousel` في كامل المستودع عدا `vendor/` و`node_modules/`؛ `Cache::` في `app/Services/Theme`، `app/Services/Commerce`، `app/Services/Storefront` وخدمات الـ banners؛ `where('is_active'` على `Theme`؛ `theme_root_path|WEB_THEME`؛ `storefront_id|country_id|country_code|market_id|market_code|legal_entity|store_id` في كامل نطاق الثيم؛ `ThemeManager|ThemeDelivery|theme_versions|ThemeBuilder|StorefrontThemeRenderer|ViewerContext|ExperiencePage|SectionVisibility|ThemeSection` في `tests/` و`database/` و`Modules/`؛ في تطبيقَي Flutter: `theme/home|theme/version|theme/sections|X-UI-|theme_updates`.

**ما لم يُفحص:** تفاصيل JavaScript/CSS لواجهة الـ builder (`resources/views/admin-views/theme/builder.blade.php`)؛ محتوى كل قالب قسم على حدة (فُحص `custom_html` و`price_tiles` والحاضنة `home.blade.php` فقط)؛ `SegmentResolver` و`CollectionResolver` و`MerchandisingRules` إلا من جهة مفاتيح الـ cache؛ `AuditLogger`؛ منطق `ModulePermissionMiddleware` (نطاق E05)؛ عارض Flutter (نطاق E17)؛ قاعدة بيانات الإنتاج وملف `.env`. لم يُشغَّل أي كود.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 نظامان متعايشان باسم «theme»

1. **مجلد Blade القديم** — ثابت واحد: `theme_root_path()` تعيد `'default'` دائماً (`lavkoo:app/Utils/theme-helpers.php:10-20`)، و`ThemeServiceProvider` يضيف `resources/themes/default` إلى مواقع الـ views ويعرّف `VIEW_FILE_NAMES` من `file_names.php` خارج الـ console (`lavkoo:app/Providers/ThemeServiceProvider.php:17-23`، مسجَّل في `lavkoo:bootstrap/providers.php:13`). مُثبِّت الثيمات القديم (zip + `WEB_THEME`) متقاعد ومحوَّل إلى `/admin/theme` (`lavkoo:routes/admin/routes.php:1213-1217`). القيمة نفسها تُرسل للتطبيق كـ `active_theme` (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:231`) وتُخزَّن في عمود `banners.theme` (القسم 2.9).
2. **محرّك الثيم المبني على قاعدة البيانات** (Theme / Version / Section / Block) وهو موضوع هذا الملحق. يعمل كطبقة فوق Blade: إن لم يوجد ثيم نشط له نسخة منشورة فيها أقسام مرئية للصفحة، تُعرض القوالب القديمة كما هي (`lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:11-19`).

### 2.2 نموذج البيانات

| الجدول (Model) | الأعمدة المعروفة من الكود | المفاتيح والعلاقات | حقل الحالة | بُعد المنصّة | الدليل |
|---|---|---|---|---|---|
| `themes` (`Theme`) | name, slug, description, preview_image, is_active, is_system, created_by_type, created_by_id | hasMany versions, assets؛ hasOne publishedVersion | `is_active` (bool)، `is_system` | لا يوجد | `lavkoo:app/Models/Theme.php:16-40` |
| `theme_versions` (`ThemeVersion`) | theme_id, label, change_note, status, settings (JSON), based_on_version_id, published_at, publish_at, revision, checksum | belongsTo theme؛ hasMany sections | `status` = draft / published / archived | لا يوجد | `lavkoo:app/Models/ThemeVersion.php:15-29` |
| `theme_sections` (`ThemeSection`) | theme_version_id, experience_page_id, uuid, page, type, sort_order, is_visible, settings (JSON), starts_at, ends_at, platforms (JSON), audience (JSON), channels (JSON) | belongsTo version, experiencePage؛ hasMany blocks | `is_visible` + نافذة زمنية | `platforms` (web/app + desktop/tablet/mobile) و`channels` (web/customer_app/vendor_app) | `lavkoo:app/Models/ThemeSection.php:17-31` |
| `theme_blocks` (`ThemeBlock`) | theme_section_id, type, sort_order, is_visible, settings (JSON) | belongsTo section | `is_visible` | يرث من القسم | `lavkoo:app/Models/ThemeBlock.php:15-23` |
| `theme_assets` (`ThemeAsset`) | theme_id, label, path, disk, mime_type, size_bytes, uploaded_by_type, uploaded_by_id | belongsTo theme (وليس النسخة) | — | — | `lavkoo:app/Models/ThemeAsset.php:16-26` |
| `experience_pages` (`ExperiencePage`) | theme_id, channel, slug, title, kind, is_enabled, sort_order | belongsTo theme؛ hasMany sections | `is_enabled`، `kind` = system / custom | `channel` = shared / web / customer_app | `lavkoo:app/Models/ExperiencePage.php:24-43` |
| `experience_campaigns` (`ExperienceCampaign`) | name, status, page, priority, starts_at, ends_at, overrides (JSON) | لا يوجد theme_id | 6 حالات | لا يوجد | `lavkoo:app/Models/ExperienceCampaign.php:18-40` |
| `experience_experiments` (`ExperienceExperiment`) | name, key, status, page, section_uuid, variants (JSON) | يربط بالقسم عبر `section_uuid` فقط | draft / running / stopped | لا يوجد | `lavkoo:app/Models/ExperienceExperiment.php:17-25` |
| `banners` (`Banner`) | photo, mobile_photo, banner_type, layout, priority, theme, published, url, resource_type, resource_id, title, sub_title, button_text, background_color | belongsTo product عبر resource_id | `published` | `mobile_photo` فقط | `lavkoo:app/Models/Banner.php:44-59` |
| `app_carousel_settings` (`AppCarouselSetting`) | images, texts, show_text, show_carousel | صف واحد (`::first()`) | show_carousel | — | `lavkoo:app/Models/AppCarouselSetting.php:9-23` |

**ملكية الثيم:** `created_by_type` / `created_by_id` تُملأ بـ `'admin'` ومعرّف المشرف عند الإنشاء (`lavkoo:app/Http/Controllers/Admin/Settings/ThemeManagementController.php:109-117`) وعند الاستيراد (`lavkoo:app/Services/Theme/ThemePortabilityService.php:160-169`). لا يوجد أي مالك من نوع متجر/دولة/بائع. البحث عن `storefront_id|country_id|country_code|market_id|market_code|legal_entity|store_id` في `app/Services/Theme`، `app/Services/Commerce`، `app/Services/Storefront`، نماذج الثيم والـ banners، ومتحكمات الثيم أعاد صفر نتائج.

**القيود الفريدة:** في الكود فقط: فرادة `themes.slug` عالمياً عبر `slugExists()` (`lavkoo:app/Repositories/ThemeRepository.php:72-78`، `lavkoo:app/Http/Controllers/Admin/Settings/ThemeManagementController.php:103-107`)؛ فرادة (theme_id, slug) للصفحات عبر `find()` قبل الإنشاء (`lavkoo:app/Services/Theme/ExperiencePageService.php:171-175`) و`firstOrCreate` على (theme_id, channel, slug) لصفحات النظام (`lavkoo:app/Services/Theme/ExperiencePageService.php:138-151`). **قيود قاعدة البيانات نفسها UNKNOWN** — انظر 2.3.

### 2.3 مخطط الجداول غير موجود في المستودع

لا يوجد أي migration يُنشئ أو يعدّل `themes`، `theme_versions`، `theme_sections`، `theme_blocks`، `theme_assets`، `experience_pages`، `experience_campaigns`، `experience_experiments` أو `app_carousel_settings`. البحث: `grep -rn` عن أسماء هذه الجداول في كامل المستودع (عدا `vendor/`، `node_modules/`، `storage/`، `public/`، `resources/lang`) أعاد مطابقات في `app/` فقط في الكود المدقَّق (ملفات `docs/gcc/audits/` المضافة لاحقاً توثيق وليست كوداً)؛ و`git log --all --diff-filter=D` على `database/migrations/` لم يُظهر أي migration محذوف؛ و`git log -- app/Services/Theme` يُظهر commit واحداً فقط هو `lavkoo@34dce658`. الـ migration الوحيد المتصل بالنطاق يضيف عمود `theme` إلى `banners` (`lavkoo:database/migrations/2023_08_21_042331_add_theme_to_banners_table.php:16-18`). كذلك لا يوجد migration لأعمدة `banners.mobile_photo` / `layout` / `priority` المذكورة في `$fillable` (بحث `mobile_photo` في `database/` = صفر).

الكود نفسه يفترض أن الـ migrations موجودة في مكان آخر: كل مسار محروس بـ `Schema::hasTable` / `Schema::hasColumn` (مثلاً `lavkoo:app/Services/Theme/ThemeDelivery.php:728-733`، `lavkoo:app/Models/ThemeSection.php:40-45`)، ولوحة الجاهزية تقترح `php artisan migrate` كعلاج (`lavkoo:app/Services/Theme/BuilderReadiness.php:54-56`، `lavkoo:app/Services/Theme/BuilderReadiness.php:83-88`)، وتعليق في متحكم الإدارة يذكر أن «جدول الصفحات بلا FK cascade لأن migration-ه إضافي ومحروس» (`lavkoo:app/Http/Controllers/Admin/Settings/ThemeManagementController.php:176-177`). ملفات `*.sql` مستثناة في `.gitignore`. النتيجة: أنواع الأعمدة، الفهارس، القيود الفريدة، والمفاتيح الأجنبية لهذه الجداول **UNKNOWN** ولا تُحسم إلا بـ `SHOW CREATE TABLE` من قاعدة الإنتاج أو بالحصول على ملفات الـ migration الأصلية.

كذلك **لا يوجد أي اختبار آلي** لنظام الثيم: البحث في `tests/` و`database/` و`Modules/` عن أسماء الأصناف الأساسية أعاد صفر ملفات، مع أن التعليقات تشير إلى «theme tests» تبني مخططاتها يدوياً (`lavkoo:app/Services/Theme/ThemeDelivery.php:179-181`).

### 2.4 دورة الحياة: draft → preview → publish → rollback

| الخطوة | السلوك | الدليل |
|---|---|---|
| إنشاء ثيم | Transaction: `Theme` + نسخة draft أولى + صفحات النظام home/header/footer | `lavkoo:app/Services/Theme/ThemeManager.php:59-77` |
| التحرير | كل تعديل يرفض النسخة غير الـ draft (`isEditable`) | `lavkoo:app/Services/Theme/ThemeBuilderService.php:28-31` |
| الـ draft الافتراضي للـ builder | آخر draft للثيم النشط، وإن لم يوجد منشور له يُنسخ من المنشور، وإن لم يوجد ثيم نشط يُستعمل آخر draft لأي ثيم | `lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:848-860` |
| معاينة (جلسة) | `theme_preview_version_id` في جلسة المشرف + فحص `canView()` عند القراءة؛ غير مخزَّنة في cache | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:431-451` |
| معاينة (رابط موقَّع) | رمز `versionId.expiry.HMAC` بمفتاح التطبيق، 60 دقيقة افتراضياً و1440 حدّاً أقصى؛ يُقبل كـ `?theme_preview=` على الويب وكـ `?preview=` على الـ API | `lavkoo:app/Services/Theme/ThemePreviewToken.php:27-39`، `lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:901-930` |
| منع الفهرسة | `X-Robots-Tag: noindex` و`Cache-Control: no-store` لأي رد يحمل الرمز | `lavkoo:app/Http/Middleware/NoIndexThemePreview.php:23-33`، `lavkoo:bootstrap/app.php:73` |
| فحص ما قبل النشر | `PublishValidator::inspect` يمنع النشر عند وجود blocking | `lavkoo:app/Http/Controllers/Admin/Settings/ThemeManagementController.php:210-218` |
| النشر | Transaction: أرشفة المنشور السابق **للثيم نفسه**، `status = published`، `published_at`، ملاحظة التغيير، مزامنة الـ banners، ختم revision/checksum؛ بعد الـ commit: تفريغ الـ caches، إرسال beacon، تسجيل audit | `lavkoo:app/Services/Theme/ThemeManager.php:108-168` |
| ترقيم revision | `max(revision)` **لكل theme_id** مع `lockForUpdate` ثم +1 | `lavkoo:app/Services/Theme/ThemeDelivery.php:186-195` |
| checksum المخزَّن | SHA-256 مقتطع (32 حرفاً) على settings النسخة وبنية الأقسام والـ blocks | `lavkoo:app/Services/Theme/ThemeDelivery.php:680-697` |
| النشر المجدول | `publish_at` على draft فقط؛ الأمر `theme:publish-due` كل 5 دقائق مع «claim» ذرّي وإعادة فحص | `lavkoo:app/Models/ThemeVersion.php:57-62`، `lavkoo:app/Console/Commands/ThemePublishDue.php:43-78`، `lavkoo:app/Console/ScheduleDefinition.php:53` |
| Rollback | `restoreVersion` ينسخ النسخة القديمة إلى draft جديد ولا ينشر مباشرة | `lavkoo:app/Services/Theme/ThemeManager.php:219-230` |
| التفعيل | `activate()` يطفئ `is_active` لكل الثيمات الأخرى ثم يفعّل المطلوب | `lavkoo:app/Services/Theme/ThemeManager.php:80-105` |
| الحذف | ممنوع للثيم النشط أو النظامي؛ يحذف مجلد الأصول وصفحات الثيم يدوياً | `lavkoo:app/Http/Controllers/Admin/Settings/ThemeManagementController.php:165-184` |

ملاحظتان على الدورة: (أ) تفاصيل قيد حالة النسخة في مسار النشر المباشر مُقيّدة، وهي مُحالة إلى `SEC-E07-03` في القسم 6. (ب) نشر نسخة ثيم غير نشط مسموح ولا يغيّر الواجهة حتى يُفعَّل الثيم.

**الصلاحيات:** طبقتان. الأولى middleware `module:themes_and_addons` على كل مسارات `admin/theme`، `admin/app-builder` و`admin/commerce` (`lavkoo:routes/admin/routes.php:1647`، `lavkoo:routes/admin/routes.php:1675`، `lavkoo:routes/admin/routes.php:1693`). الثانية `ThemePermissionService` بخمس قدرات: `theme_view`، `theme_edit`، `theme_publish`، `theme_restore`، `theme_styles` (`lavkoo:app/Services/Theme/ThemePermissionService.php:20-34`). الدور رقم 1 يملك كل شيء؛ حامل الـ module يملك كل شيء عدا `theme_publish` الذي يُمنح صراحة (`lavkoo:app/Services/Theme/ThemePermissionService.php:39-68`). التفعيل والحذف والنشر والجدولة تتطلب `canPublish()` (`lavkoo:app/Http/Controllers/Admin/Settings/ThemeManagementController.php:130`، `lavkoo:app/Http/Controllers/Admin/Settings/ThemeManagementController.php:197`، `lavkoo:app/Http/Controllers/Admin/Settings/ThemeManagementController.php:240`). لا يوجد أي نطاق بيانات: القدرة تنطبق على كل الثيمات.

### 2.5 أين يُفرض افتراض «ثيم نشط واحد»

- **كتابةً:** `ThemeManager::activate` يحدّث `is_active = false` لكل ثيم آخر دون أي شرط إضافي (`lavkoo:app/Services/Theme/ThemeManager.php:83-86`). هذا هو الموضع الوحيد الذي يفرض الحصرية؛ لا يوجد قيد DB معروف (UNKNOWN، انظر 2.3).
- **قراءةً:** 13 موضعاً يستعلم `Theme ... where('is_active', true)->first()` مباشرة، زائد `ThemeRepository::getActiveTheme`:

| # | الموضع | الغرض |
|---|---|---|
| 1 | `lavkoo:app/Services/Theme/ThemeDelivery.php:717` | النسخة المنشورة لتسليم التطبيق |
| 2 | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:463` | النسخة المنشورة للويب |
| 3 | `lavkoo:app/Services/Theme/ThemeManager.php:245` | `activeThemePublishedVersion()` |
| 4 | `lavkoo:app/Services/Theme/ThemeBannerLink.php:354` | استخدام الـ banners في الثيم النشط |
| 5 | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:83` | الصفحات المسموحة للقناة |
| 6 | `lavkoo:app/Http/Controllers/Web/ExperiencePageController.php:32` | صفحة مخصّصة `/p/{slug}` |
| 7 | `lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:421` | منتقي الموارد |
| 8 | `lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:744` | الثيم الهدف لرفع الوسائط |
| 9 | `lavkoo:app/Http/Controllers/Admin/Settings/AppBuilderController.php:340` | الثيم المعروض في App Builder |
| 10 | `lavkoo:app/Http/Controllers/Admin/Commerce/CampaignController.php:44` | صفحات الحملات |
| 11 | `lavkoo:app/Http/Controllers/Admin/Commerce/CampaignController.php:274` | التحقق من slug الصفحة |
| 12 | `lavkoo:app/Http/Controllers/Admin/Commerce/ExperimentController.php:228` | التحقق من قسم التجربة |
| 13 | `lavkoo:app/Services/Commerce/ExperienceHealth.php:276` | فحوص الصحة |
| 14 | `lavkoo:app/Repositories/ThemeRepository.php:67-70` | `getActiveTheme()` |

التعليق في النموذج يصرّح بالقاعدة: «exactly one theme is active» (`lavkoo:app/Models/Theme.php:11-12`).

### 2.6 عقد التسليم إلى تطبيق العملاء (HTTP)

ثلاثة مسارات GET عامة تحت `/api/v1/theme` بـ `throttle:60,1`، ضمن مجموعة `v1` ذات middleware `api_lang` (`lavkoo:routes/rest_api/v1/api.php:54`، `lavkoo:routes/rest_api/v1/api.php:475-487`).

**(أ) `GET /api/v1/theme/version`** — بلا مُدخلات. يعيد `revision`، `checksum`، `schema_version`، `engine_version`، `published_at`؛ `revision = 0` إن لم يُنشر شيء أو فشل الاستعلام (`lavkoo:app/Services/Theme/ThemeDelivery.php:64-105`). الـ checksum هنا هو `syncStamp`: checksum النسخة المخزَّن مضافاً إليه بصمة الحملات الحيّة (`lavkoo:app/Services/Theme/ThemeDelivery.php:630-639`).

**(ب) `GET /api/v1/theme/home`** — المُدخلات المقروءة فعلاً:

| المُدخل | المصدر | الأثر | الدليل |
|---|---|---|---|
| `page` | query، الافتراضي `home` | slug غير مسموح للقناة → 404 `page_not_found`؛ قيمة غير نصية → `home` | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:75-94` |
| `device` | query: desktop / tablet / mobile | الافتراضي `mobile` للتطبيق | `lavkoo:app/Services/Theme/ViewerContext.php:71-72` |
| `X-UI-Components` أو `components` | header / query | قائمة أنواع الأقسام التي يرسمها الـ build | `lavkoo:app/Services/Theme/ViewerContext.php:76-79` |
| `X-UI-Engine` أو `ui_engine` | header / query | جيل العارض | `lavkoo:app/Services/Theme/ViewerContext.php:81-82` |
| `X-UI-Schema` أو `ui_schema` | header / query | جيل المخطط (يدخل مفتاح الـ cache فقط حالياً) | `lavkoo:app/Services/Theme/ViewerContext.php:86-87` |
| `X-UI-Channel` أو `channel` | header / query | القناة المعلنة؛ الافتراضي يُشتق من المنصّة | `lavkoo:app/Services/Theme/ViewerContext.php:91` |
| `lang` | header (عبر `api_lang`) | اللغة التي تُطوى عليها النصوص | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29` |
| `Authorization` | Bearer (guard `api`) | guest / customer، الشرائح، هوية التجربة | `lavkoo:app/Services/Theme/ViewerContext.php:110-159` |
| `preview` | query | رمز موقَّع → حمولة draft مع `preview: true` و`Cache-Control: no-store` | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:195-201` |
| `If-None-Match` | header | مطابقة الـ checksum → 304 بلا جسم | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:215-217` |

لا يُقرأ أي مُدخل يخص الدولة أو المتجر أو العملة أو إصدار التطبيق التسويقي. ترويسة `Vary` المُعلنة تذكر `X-Platform` (`lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:210`) لكن `ViewerContext` لا يقرؤها (الملف مقروء كاملاً: 194 سطراً)؛ المنصّة تُثبَّت على `app` لكل طلب API (`lavkoo:app/Services/Theme/ViewerContext.php:69`).

مخطط الاستجابة (`lavkoo:app/Services/Theme/ThemeDelivery.php:309-331`): `page`، `revision`، `content_stamp`، `schema_version`، `engine_version`، `published_at`، `tokens` {colors, typography, layout, branding}، `sections[]`، `compatibility` {delivered, withheld{type: reason}}، `checksum` (ETag محسوب على الحمولة المسلَّمة فعلاً). كل قسم (`lavkoo:app/Services/Theme/ThemeDelivery.php:434-472`): `uuid`، `id`، `type`، `variant`، `component_version`، `settings`، `blocks[]`، `cards`، `source`، `experiment`، `view_all`.

**بوابات التوافق:** التفاوض على القدرات لا على رقم الإصدار. `SCHEMA_VERSION = 1` و`CURRENT_ENGINE_VERSION = 1` (`lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:29-32`)؛ 33 نوعاً يرسمها التطبيق (`lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:44-89`) و6 مستثناة مع السبب (`lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:97-104`) من أصل 39 نوعاً في `SectionRegistry` (عدّ `'pages' =>` في الملف = 39). العميل الذي لا يعلن شيئاً يستلم كل ما هو app-safe (`lavkoo:app/Services/Theme/ViewerContext.php:179-182`)؛ والنوع المحجوب يُسمّى في `compatibility.withheld` (`lavkoo:app/Services/Theme/ThemeDelivery.php:247-252`).

**الـ fallback:** جداول غير جاهزة، لا ثيم نشط، لا نسخة منشورة، أو أي استثناء ⇒ `emptyPayload` بـ `revision = 0` وأقسام فارغة (`lavkoo:app/Services/Theme/ThemeDelivery.php:700-713`)، والتطبيق يعرض صفحته المبنية داخلياً حسب وصف العقد (`lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:172`).

**(ج) `GET /api/v1/theme/sections`** — المسار الأقدم: `page` و`type` فقط، بلا revision/ETag (`lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:132-156`). يمر عبر `StorefrontThemeRenderer::sectionsFor` الذي يبني `ViewerContext` بمنصّة `web` وجهاز `desktop` دائماً (`lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:117-128`)، فتُقيَّم قواعد `platforms`/`channels` كأن الطالب متصفح. تطبيق العملاء الحالي يعرّف ثابت هذا المسار (`lavkooapp:lib/utill/app_constants.dart:58-60`) ويستعمل فعلياً `home` و`version` (`lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:67`، `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:96`).

**إشعار النشر:** رسالة FCM صامتة إلى topic واحد ثابت `theme_updates_user_app` تحمل `type = theme_version_changed` و`revision` فقط (`lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27-29`، `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:48-56`)؛ التطبيق يشترك فيه عند الإقلاع (`lavkooapp:lib/features/splash/controllers/splash_controller.dart:147`).

### 2.7 مسار العرض على الويب

1. `HomeController` يسأل العارض هل يرسم الثيم المنشور أنواع banners معيّنة حتى تتنحّى الخانات المبنية (`lavkoo:app/Http/Controllers/Web/HomeController.php:79-88`) ثم يعرض `VIEW_FILE_NAMES['home']` من مجلد `default`.
2. `resources/themes/default/web-views/home.blade.php` يعرض `theme-sections.home` أولاً داخل try/catch؛ إن خرج محتوى فهو **يستبدل** الصفحة الرئيسية القديمة (`lavkoo:resources/themes/default/web-views/home.blade.php:15-23`). الفوتر كذلك استبدال (`lavkoo:resources/themes/default/layouts/front-end/partials/_footer.blade.php:4-14`)؛ الهيدر **إضافة** فوق الهيدر المبني (`lavkoo:resources/themes/default/layouts/front-end/partials/_header.blade.php:3-9`).
3. `theme-sections/home.blade.php` يطلب `sectionsFor($__pageSlug)` ويرسم 37 نوعاً معرّفاً في قائمة `$__renderable` (`lavkoo:resources/views/theme-sections/home.blade.php:14-28`)؛ البيانات عبر `SectionDataResolver`.
4. `<head>` في الـ layout يحقن design tokens للنسخة المنشورة كمتغيّرات CSS تحلّ محل ألوان `business_settings` (`lavkoo:resources/themes/default/layouts/front-end/app.blade.php:90-93`، `lavkoo:resources/views/partials/theme-global-tokens.blade.php:9-30`).
5. الصفحات المخصّصة على الويب: `GET /p/{slug}` للصفحات shared أو web فقط من الثيم النشط (`lavkoo:routes/web/routes.php:223-225`، `lavkoo:app/Http/Controllers/Web/ExperiencePageController.php:30-60`).

`sectionsFor` يخزّن **بنية** الصفحة في cache ثم يطبّق لكل طلب: الجدولة والاستهداف، رقع التجارب، طيّ اللغة، وتراكب الحملات (`lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:66-103`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:111-215`). نتيجة فارغة = `null` = القوالب القديمة.

### 2.8 `ViewerContext` — كل السمات

| السمة | القيم | مصدرها في API | مصدرها في الويب | الدليل |
|---|---|---|---|---|
| `platform` | web / app | ثابت `app` | ثابت `web` | `lavkoo:app/Services/Theme/ViewerContext.php:39` |
| `device` | desktop / tablet / mobile | query `device` | ثابت `desktop` (الإخفاء عبر CSS) | `lavkoo:app/Services/Theme/ViewerContext.php:40` |
| `authenticated` | bool | guard `api` | guard `customer` أو `api` | `lavkoo:app/Services/Theme/ViewerContext.php:41` |
| `locale` | كود لغة | `app()->getLocale()` بعد `api_lang` | `app()->getLocale()` | `lavkoo:app/Services/Theme/ViewerContext.php:42` |
| `uiEngineVersion` | int | `X-UI-Engine` | 0 | `lavkoo:app/Services/Theme/ViewerContext.php:43` |
| `uiSchemaVersion` | int | `X-UI-Schema` | 0 | `lavkoo:app/Services/Theme/ViewerContext.php:44` |
| `supportedComponents` | قائمة أنواع | `X-UI-Components` | فارغة (= الكل) | `lavkoo:app/Services/Theme/ViewerContext.php:45` |
| `channel` | web / customer_app / vendor_app / null | `X-UI-Channel` | null ⇒ web | `lavkoo:app/Services/Theme/ViewerContext.php:49` |
| `segments` | مفاتيح شرائح العملاء | `SegmentResolver` | `SegmentResolver` | `lavkoo:app/Services/Theme/ViewerContext.php:53` |
| `experimentSubject` | `c{customerId}` أو `v{visitorId}` | guard + VisitorContext | نفسه | `lavkoo:app/Services/Theme/ViewerContext.php:58` |

**لا توجد أي سمة دولة أو متجر أو عملة أو منطقة زمنية.** الصنف `final` وغير قابل للتغيير، وتعليقه يشترط أن كل حقل «يمكن الدفاع عنه منفرداً» (`lavkoo:app/Services/Theme/ViewerContext.php:7-18`). ملاحظة لغوية ذات صلة: `api_lang` يحوّل قيمة `lang: sa` إلى `ar` و`us` إلى `en` لأن builds قديمة كانت ترسل كود الدولة في ترويسة اللغة (`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-27`)؛ أي أن الرمز `sa` مستعمل اليوم بمعنى «العربية» لا بمعنى «السعودية».

القنوات: `web` و`customer_app` قابلتان للعرض؛ `vendor_app` مسجَّلة لكن بلا عارض و`servableToChannel` يعيد لها قائمة فارغة (`lavkoo:app/Services/Theme/Channel.php:23-34`، `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:156-158`). البحث في `lavkooappseller/lib` عن `api/v1/theme|dynamic_theme|X-UI-Channel` أعاد صفر استخدام فعلي (المطابقة الوحيدة حقل `vendor_app` في نموذج الإعدادات: `lavkooappseller:lib/features/splash/domain/models/config_model.dart:1064`).

### 2.9 الـ caches: المفاتيح والـ TTL والإبطال

متجر الـ cache الافتراضي `file` والبادئة مشتقة من `APP_NAME` (`lavkoo:config/cache.php:21`، `lavkoo:config/cache.php:101`)؛ قيمة الإنتاج UNKNOWN.

| المفتاح | TTL | يُبطَل عند | نطاق المفتاح | الدليل |
|---|---|---|---|---|
| `theme_delivery_revision` | 600 ث | publish، activate، تغيّر حملة/تجربة | عالمي | `lavkoo:app/Services/Theme/ThemeDelivery.php:79`، `lavkoo:app/Services/Theme/ThemeDelivery.php:160-168` |
| `theme_delivery_{page}_{fingerprint}{_c…}{_x…}` | 600 ث | لا يُمسح؛ يُهجَر بتغيّر checksum/revision داخل الـ fingerprint | page + checksum/revision للنسخة النشطة + منصّة + جهاز + جمهور + شرائح + لغة + engine/schema + قدرات العميل | `lavkoo:app/Services/Theme/ThemeDelivery.php:128-133`، `lavkoo:app/Services/Theme/ThemeDelivery.php:650-674` |
| `theme_delivery_home_index` | — | يُمسح في `flush()`؛ لم يُعثر على أي كتابة له (بحث `home_index` في `app/` = موضع واحد) | — | `lavkoo:app/Services/Theme/ThemeDelivery.php:167` |
| `storefront_theme_page_{page}` | 600 ث | publish، activate، حفظ إعدادات draft، حملة | page فقط | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:25-27`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:88`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:364-380`، `lavkoo:app/Http/Controllers/Admin/Settings/ThemeSettingsController.php:85` |
| `storefront_theme_global_settings` | 600 ث | نفسه | عالمي | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:336` |
| `theme_publish_check_{versionId}` | 300 ث | حفظ/حذف أي section أو block | النسخة | `lavkoo:app/Services/Theme/PublishValidator.php:37-39`، `lavkoo:app/Services/Theme/PublishValidator.php:59-74` |
| `theme_section_reach` | 300 ث | بالانتهاء | عالمي | `lavkoo:app/Services/Theme/SectionReach.php:47` |
| `commerce_campaign_overrides_{page}` | 30 ث | كتابة حملة، `commerce:campaigns-tick` | page فقط | `lavkoo:app/Services/Commerce/CampaignResolver.php:24`، `lavkoo:app/Services/Commerce/CampaignResolver.php:43-47`، `lavkoo:app/Services/Commerce/CampaignResolver.php:57-66` |
| `commerce_experiments_{page}` | 60 ث | تغيير حالة تجربة | page فقط | `lavkoo:app/Services/Commerce/ExperimentResolver.php:20`، `lavkoo:app/Services/Commerce/ExperimentResolver.php:83`، `lavkoo:app/Http/Controllers/Admin/Commerce/ExperimentController.php:240-244` |
| `commerce_segment_ctx_{customerId}` | 600 ث | بالانتهاء | العميل | `lavkoo:app/Services/Commerce/SegmentResolver.php:23`، `lavkoo:app/Services/Commerce/SegmentResolver.php:107` |
| `cache_banner_table` | 3 ساعات | حفظ/حذف أي banner | عالمي + `theme = default` | `lavkoo:app/Traits/CacheManagerTrait.php:40-44`، `lavkoo:app/Library/Constant.php:1141` |
| `cache_banner_type_{type}_{themeName}` | 3 ساعات | حفظ/حذف أي banner | نوع + اسم مجلد الثيم | `lavkoo:app/Services/BannerPlacementService.php:79-93`، `lavkoo:app/Traits/CacheManagerTrait.php:446-455` |
| `bought_together_{productId}_{limit}` | 6 ساعات | بالانتهاء | المنتج | `lavkoo:app/Services/Storefront/BoughtTogetherService.php:26`، `lavkoo:app/Services/Storefront/BoughtTogetherService.php:88-90` |

لا يحمل أي مفتاح مما سبق مكوّن دولة أو متجر. ترتيب الإبطال مقصود: التفريغ بعد الـ commit لا داخله (`lavkoo:app/Services/Theme/ThemeManager.php:145-148`).

**عناوين الأصول (CDN/asset URLs):** أصول الثيم تُحفظ على القرص `public` تحت `theme-assets/{theme_id}/` باسم مولَّد (`lavkoo:app/Services/Theme/ThemeAssetService.php:76-80`) والرابط من `Storage::disk()->url()` (`lavkoo:app/Models/ThemeAsset.php:29-36`). المسارات النسبية في الحمولة تُحوَّل إلى مطلقة بـ `url()` أي على أساس نطاق الطلب (`lavkoo:app/Services/Theme/ThemeDelivery.php:536-556`). لا يوجد في نطاق الثيم أي إعداد CDN مستقل؛ صور الـ banners تمر عبر `storageLink` المشترك (`lavkoo:app/Models/Banner.php:68-75`).

### 2.10 الحملات، التجارب، الصفحات، والـ banners — حقول النطاق

- **الصفحات (`experience_pages`):** لكل ثيم («Pages are per theme»، `lavkoo:app/Services/Theme/ExperiencePageService.php:21-22`) مع `channel` = shared أو قناة واحدة قابلة للعرض (`lavkoo:app/Services/Theme/ExperiencePageService.php:181-183`).
- **الحملات (`experience_campaigns`):** النطاق الوحيد هو `page` (slug) + نافذة زمنية + priority؛ لا `theme_id` ولا قناة (`lavkoo:app/Models/ExperienceCampaign.php:33`، `lavkoo:app/Http/Controllers/Admin/Commerce/CampaignController.php:95-103`). الحل وقت الخدمة بالاستعلام عن `page` فقط (`lavkoo:app/Services/Commerce/CampaignResolver.php:72-78`)، فالحملة على `home` تكسو صفحة `home` لأي ثيم يكون نشطاً.
- **التجارب (`experience_experiments`):** `page` + `section_uuid` (`lavkoo:app/Models/ExperienceExperiment.php:23`، `lavkoo:app/Services/Commerce/ExperimentResolver.php:81-88`). الـ uuid يبقى نفسه عند نسخ draft (`lavkoo:app/Services/Theme/ThemeManager.php:190-194`) **وعند export/import إلى ثيم جديد** (`lavkoo:app/Services/Theme/ThemePortabilityService.php:64`، `lavkoo:app/Services/Theme/ThemePortabilityService.php:199-209`).
- **الـ banners (`banners`):** جدول عالمي؛ نطاقه `banner_type` + `theme` (اسم مجلد Blade، ثابت `default`) + `published` (`lavkoo:app/Services/BannerService.php:83`، `lavkoo:app/Services/BannerPlacementService.php:86-87`). أقسام الثيم تقرأ الـ banners **حيّةً** بثلاث طرق: `store_banner` يقرأ نوعاً كاملاً (`lavkoo:app/Services/Theme/SectionDataResolver.php:372-396`)؛ الـ blocks المرتبطة بـ `banner_id` يتغلّب صف الـ banner على نسختها (`lavkoo:app/Services/Theme/SectionDataResolver.php:478-497`)؛ والـ builder يسجّل صور الـ blocks كصفوف `Theme Banner` (`lavkoo:app/Services/Theme/ThemeBannerLink.php:98-108`).
- **اقتران draft ↔ live عبر الـ banners:** تعديل block مرتبط داخل draft يدفع العنوان/الرابط/الصورة إلى صف الـ banner فوراً (`lavkoo:app/Services/Theme/ThemeBuilderService.php:397-399`، `lavkoo:app/Services/Theme/ThemeBannerLink.php:140-176`)، والنسخة المنشورة تحمل الـ `banner_id` نفسه لأن `createDraftFrom` ينسخ settings الـ block كما هي (`lavkoo:app/Services/Theme/ThemeManager.php:197-204`). أي أن هذا الجزء من الـ draft يظهر للعملاء قبل النشر — سلوك مقصود في التصميم الحالي («one source of truth: the banners table»، `lavkoo:app/Services/Theme/ThemeBannerLink.php:13-21`).
- **`app_carousel_settings`:** صف واحد عالمي تديره `admin/app-carousel` (`lavkoo:routes/admin/routes.php:153`)؛ المتحكم القارئ `newcontroller::curselre` (`lavkoo:app/Http/Controllers/RestAPI/v3/newcontroller.php:11-35`) غير مربوط بأي route (بحث `curselre|newcontroller` في `routes/`، `app/Providers`، `bootstrap/`، `Modules/` = صفر).

### 2.11 النسخ والنقل (Theme copy)

`ThemePortabilityService::export` يسلسل النسخة (settings + أقسام + blocks + uuid + قواعد التسليم) بصيغة `FORMAT_VERSION = 1` (`lavkoo:app/Services/Theme/ThemePortabilityService.php:26`، `lavkoo:app/Services/Theme/ThemePortabilityService.php:40-78`). `import` ينشئ **ثيماً جديداً غير نشط** بنسخة draft واحدة ولا يكتب فوق ثيم قائم (`lavkoo:app/Services/Theme/ThemePortabilityService.php:148-176`). ما لا ينتقل: صفوف `theme_assets` والملفات، صفوف `experience_pages` (تُنشأ صفحات النظام فقط عند فتح الـ builder: `lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:77-79`)، وصفوف الـ banners (تبقى مشارَكة بالـ `banner_id`). ما ينتقل كما هو: معرّفات المنتجات/الأقسام/الماركات/المتاجر/المجموعات داخل settings.

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الدليل | الحالة |
|---|---|---|---|
| A1 | ثيم نشط واحد للنظام كله؛ `activate` يطفئ كل ما عداه | `lavkoo:app/Services/Theme/ThemeManager.php:83-86` | VERIFIED |
| A2 | نسخة منشورة واحدة لكل ثيم؛ الأرشفة مقيّدة بـ `theme_id` فقط | `lavkoo:app/Services/Theme/ThemeManager.php:111-115` | VERIFIED |
| A3 | نسخة واحدة تخدم الويب والتطبيق معاً؛ الفصل على مستوى القسم/الصفحة لا على مستوى النشر | `lavkoo:app/Services/Theme/ExperiencePageService.php:65-69` | VERIFIED |
| A4 | مفاتيح الـ cache بلا بُعد متجر (جدول 2.9) | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:25-26`، `lavkoo:app/Services/Theme/ThemeDelivery.php:34` | VERIFIED |
| A5 | topic واحد لإشعار النشر لكل مستخدمي التطبيق | `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27` | VERIFIED |
| A6 | مجلد Blade واحد ثابت `default`؛ `banners.theme` افتراضه `default` | `lavkoo:app/Utils/theme-helpers.php:16-19`، `lavkoo:database/migrations/2023_08_21_042331_add_theme_to_banners_table.php:17` | VERIFIED |
| A7 | بيانات الأقسام من كتالوج عالمي: `Product::active()`، ماركات، أقسام، وعدّ كل العملاء والطلبات في `stats_bar` | `lavkoo:app/Services/Theme/SectionDataResolver.php:189-204`، `lavkoo:app/Services/Theme/SectionDataResolver.php:859-868` | VERIFIED |
| A8 | ساعة واحدة للمتجر: نوافذ الأقسام، `publish_at`، نوافذ الحملات، و`shipping_cutoff` كلها على `now()` بمنطقة زمنية واحدة من `business_settings.timezone` | `lavkoo:app/Services/Theme/SectionVisibility.php:90-92`، `lavkoo:app/Providers/ConfigServiceProvider.php:30-33`، `lavkoo:app/Services/Theme/SectionDataResolver.php:921-934` | VERIFIED (القيمة الإنتاجية UNKNOWN؛ الافتراضي في الكود `Asia/Dhaka`، `lavkoo:config/app.php:69`) |
| A9 | عملة واحدة ومبالغ صحيحة في `price_tiles`: `min`/`max` تُحوَّل بـ `(int)` وتُعرض بـ `webCurrencyConverter` | `lavkoo:resources/views/theme-sections/types/price_tiles.blade.php:19-24`، `lavkoo:app/Services/Theme/SectionRegistry.php:968-976` | VERIFIED |
| A10 | الروابط تُحلَّل بافتراض أن أول مقطع في المسار هو نوع الوجهة (`product`، `category`، `p`…) | `lavkoo:app/Services/Theme/ActionResolver.php:89-125` | VERIFIED |
| A11 | مسارات مصادر البيانات في الحمولة ثابتة `/api/v1/...` بلا سياق متجر | `lavkoo:app/Services/Theme/ThemeSourceMap.php:44-45`، `lavkoo:app/Services/Theme/ThemeSourceMap.php:61`، `lavkoo:app/Services/Theme/ThemeSourceMap.php:70-73` | VERIFIED |
| A12 | الحملات والتجارب عالمية مفتاحها slug الصفحة | `lavkoo:app/Services/Commerce/CampaignResolver.php:72-78`، `lavkoo:app/Services/Commerce/ExperimentResolver.php:83-87` | VERIFIED |
| A13 | صلاحيات الثيم بلا نطاق بيانات | `lavkoo:app/Services/Theme/ThemePermissionService.php:39-68` | VERIFIED |
| A14 | ترجمة النصوص بلاحقة كود اللغة (`title_ar`)؛ اللغة هي البُعد المحلي الوحيد، و`sa` تُعامل كلغة | `lavkoo:app/Services/Theme/LocalisedSettings.php:41-62`، `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:27` | VERIFIED |
| A15 | هوية العلامة في الثيم (logo، mobile_logo، favicon) جزء من settings النسخة | `lavkoo:app/Services/Theme/ThemeManager.php:26-29` | VERIFIED |
| A16 | «الثيم النشط» افتراض مُدمَج في الـ builder: عند غياب منشور للثيم النشط يختار المحرّر آخر draft لأي ثيم | `lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:859` | VERIFIED |

## 4. الأثر على قرارات GCC المعتمدة

| القرار | ما يدعمه النطاق اليوم | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| GCC-07 ثيم مستقل لكل دولة، drafts ونشر مستقلان | تعدد الثيمات موجود؛ لكل ثيم نسخه وdrafts-ه وصفحاته وأصوله وتسلسل revision خاص؛ النشر والأرشفة مقيّدان بـ `theme_id` | الحصرية العالمية لـ `is_active`؛ 14 موضع قراءة بلا سياق؛ لا عمود مالك من نوع متجر؛ الـ caches عالمية | `lavkoo:app/Services/Theme/ThemeManager.php:83-86`، `lavkoo:app/Services/Theme/ThemeDelivery.php:715-726` |
| GCC-07 نسخ اختياري للثيم | export/import ينشئ ثيماً جديداً غير نشط بنسخة draft | النسخة تحتفظ بالـ uuid وبمعرّفات كتالوج الدولة المصدر وبروابط أصول الثيم المصدر وبـ `banner_id` المشترك | `lavkoo:app/Services/Theme/ThemePortabilityService.php:148-176`، `lavkoo:app/Services/Theme/ThemePortabilityService.php:199-209` |
| GCC-07 منع النشر العرضي بين الدول | النشر يمس نسخ الثيم نفسه فقط؛ التفريغ بعد الـ commit | (1) صفوف الـ banners المشتركة تتغيّر من draft ثيم آخر؛ (2) الحملات والتجارب عالمية بالـ slug والـ uuid؛ (3) `flush()` والـ beacon عالميان | `lavkoo:app/Services/Theme/ThemeBannerLink.php:140-176`، `lavkoo:app/Services/Commerce/CampaignResolver.php:72-78`، `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:48-56` |
| GCC-09 تخصيص الويب والتطبيق كلٌّ على حدة | `channels` و`platforms` على القسم؛ `channel` على الصفحة؛ تفاوض القدرات؛ تخصيصات الجهاز tablet/mobile | نسخة واحدة ونشر واحد وtokens واحدة للقناتين؛ مسار `/theme/sections` القديم يقيّم كأنه ويب | `lavkoo:app/Services/Theme/SectionVisibility.php:123-131`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:117-128` |
| GCC-09 أثر تطبيق البائع | `Channel::VENDOR_APP` مسجَّلة وغير قابلة للعرض؛ لا استهلاك في `lavkooappseller` | لا شيء مطلوب للإطلاق؛ إن أُريد لاحقاً فالإضافة في مكانين: `RENDERABLE` وقائمة مكوّنات للقناة | `lavkoo:app/Services/Theme/Channel.php:29-34`، `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:156-158` |
| GCC-10 هوية مشتركة مع تخصيصات الدولة | `tokens.branding` في settings النسخة يتيح تخصيص الهوية لكل نسخة (VERIFIED)؛ العمل تلقائياً مع ثيم لكل متجر استنتاج (INFERRED)؛ الافتراضات في `defaultSettings()` | الاسم/الشعار العالميان في `business_settings` (نطاق E02)؛ لا آلية وراثة «افتراضي عالمي ← تخصيص دولة» داخل الثيم سوى الدمج مع `defaultSettings()` الثابتة في الكود | `lavkoo:app/Services/Theme/ThemeManager.php:23-56`، `lavkoo:app/Services/Theme/ThemeDelivery.php:564-577` |
| GCC-12 إيقاف متجر | الـ fallback إلى `revision = 0` والقوالب القديمة آمن عند غياب ثيم منشور | لا يوجد مفهوم «وضع المتجر» في مسار الثيم؛ صفحة الصيانة من مجلد `default` العالمي | `lavkoo:app/Services/Theme/ThemeDelivery.php:700-713` |
| GCC-14 نطاقات الموظفين | خمس قدرات مفصولة فوق الـ module؛ audit لكل نشر | القدرات بلا نطاق متجر؛ حامل `theme_publish` ينشر أي ثيم | `lavkoo:app/Services/Theme/ThemePermissionService.php:39-68` |
| GCC-17 حملات مركزية وقواعد محلية | `experience_campaigns` مستقلة عن الثيم (تصلح أساساً لحملة مركزية) | لا حقل لاختيار الأسواق؛ الـ overrides تحمل نصوصاً وروابط بلا تمييز دولة | `lavkoo:app/Models/ExperienceCampaign.php:33` |
| GCC-19 السعودية متجر افتراضي | كل المسارات تعمل بلا مُدخل متجر، فالافتراضي الضمني هو الثيم الوحيد النشط | يلزم جعل «بلا مُدخل = السعودية» قاعدة صريحة حتى لا تنكسر الـ builds المنشورة | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:183-203` |
| قيد «caches بنطاق السوق» | — | كل مفاتيح الثيم بلا بُعد سوق (جدول 2.9) | `lavkoo:app/Services/Theme/ThemeDelivery.php:650-674` |
| ضابط «لا إعادة كتابة لمحرّر الثيم ولا لثيم Flutter الديناميكي» | العقد إضافي بطبيعته (مفاتيح جديدة يتجاهلها العميل القديم) | لا اختبارات ولا مخطط في المستودع لحماية هذا الضابط | `lavkoo:app/Services/Theme/ThemeDelivery.php:445-448` |

**نقاط إدخال مفتاح المتجر (storefront key) — توثيق فقط:**

| الطبقة | الموضع | التغيير الأدنى المتوافق رجعياً (الافتراضي = السعودية) |
|---|---|---|
| جدول | `themes` | عمود `storefront_id` قابل للـ NULL يُملأ للسعودية؛ قاعدة «نشط واحد لكل متجر» بدل «نشط واحد» |
| جدول | `experience_campaigns`، `experience_experiments` | ربط بالمتجر (أو جدول أسواق للحملة المركزية وفق GCC-17)؛ التجربة تُربط بالثيم لا بالـ uuid وحده |
| جدول | `banners` | `storefront_id` مع بقاء عمود `theme` كما هو؛ نسخ صفوف `Theme Banner` عند نسخ الثيم |
| جدول | `app_carousel_settings` | صف لكل متجر إن بقيت الميزة مستعملة (UNKNOWN) |
| فهرس فريد | `themes.slug`؛ (theme_id, slug) في `experience_pages`؛ «نشط لكل متجر» | يُحدَّد بعد الحصول على المخطط الفعلي (2.3)؛ الفرادة اليوم في الكود فقط |
| كتابة | `ThemeManager::activate` | حصر تحديث `is_active = false` بثيمات المتجر نفسه |
| قراءة | المواضع الـ 14 في 2.5 | توجيهها إلى محلِّل واحد «الثيم النشط لمتجر X» افتراضه السعودية |
| سياق | `ViewerContext` | سمة `storefront` للقراءة فقط بقيمة افتراضية؛ تدخل `fingerprint()` |
| مفاتيح cache | كل صفوف جدول 2.9 عدا `theme_publish_check_{versionId}` و`commerce_segment_ctx_{customerId}` | إضافة مكوّن المتجر إلى المفتاح وإلى `flush()` |
| مُدخل endpoint | `/api/v1/theme/home` و`/version` و`/sections` | مُدخل اختياري (header أو query — التسمية قرار نطاق E02/E16)؛ غيابه = السعودية؛ يُضاف إلى `Vary` |
| إشعار | `ThemeSyncBeacon` | إبقاء الـ topic الحالي للسعودية وإضافة topic لكل متجر + حقل متجر في `data` |
| إجراءات النشر | publish / schedule / activate / restore / import | فحص نطاق المتجر في `ThemePermissionService`؛ تسجيل المتجر في audit context |
| الروابط | `ActionResolver`، `LinkComposer`، `ThemeSourceMap` | تجاهل/إضافة بادئة `/sa` … قبل تحليل نوع الوجهة |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | الإضافة الدنيا الموصى بها |
|---|---|---|---|
| دورة النسخ (draft/publish/archive/restore) في `ThemeManager` | Preserve | ذرّية، غير هدّامة، ومقيّدة أصلاً بـ `theme_id` | لا شيء سوى تمرير سياق المتجر إلى audit |
| `ThemeManager::activate` | Extend | الموضع الوحيد الذي يفرض الحصرية العالمية | شرط المتجر في تحديث الإطفاء |
| `ThemeBuilderService` وواجهة الـ builder | Preserve | تعمل على نسخة بعينها؛ لا تعتمد على «النشط» إلا في اختيار الـ draft الافتراضي | اختيار الـ draft حسب المتجر المحدَّد في لوحة الإدارة |
| `ThemeDelivery` (العقد، التفاوض، ETag) | Extend | العقد إضافي ومتوافق؛ ينقصه بُعد المتجر | متجر في `publishedVersion()` وفي `fingerprint()` وفي مفتاح `revision` |
| `StorefrontThemeRenderer` | Extend | shim آمن؛ مفاتيحه بالصفحة فقط | متجر في المفتاحين وفي `publishedVersion()` |
| `ViewerContext` / `SectionVisibility` / `Channel` | Extend | بُعد المنصّة/القناة ناضج | سمة `storefront`؛ لا قاعدة إظهار جديدة مطلوبة إن كان الثيم لكل متجر |
| `ComponentCapabilityRegistry` وبوابة القدرات | Preserve | مستقلة عن الدولة | — |
| `ThemePortabilityService` | Extend | أساس «نسخ الثيم الاختياري» | إجراء «نسخ إلى متجر» يعيد سكّ الـ uuid، ينسخ الأصول وصفوف `Theme Banner`، ويصدر تقرير مراجع كتالوج غير موجودة في المتجر الهدف |
| `ThemeBannerLink` (ربط draft ↔ banners) | Refactor (محدود) | الدفع الفوري من draft إلى صف حيّ يناقض عزل النشر بين المتاجر | حصر الدفع بصفوف المتجر نفسه؛ نسخ الصف عند نسخ الثيم |
| `experience_campaigns` / `experience_experiments` والمحلِّلان | Extend | عالمية اليوم | نطاق متجر/أسواق + مفاتيح cache |
| `ThemeSyncBeacon` | Extend | topic واحد | topic لكل متجر مع إبقاء القديم |
| `ThemePermissionService` | Extend | قدرات بلا نطاق | معامل نطاق المتجر فوق القدرات الحالية (GCC-14) |
| `ActionResolver` / `LinkComposer` / `ThemeSourceMap` | Extend | تحليل بالمقطع الأول ومسارات ثابتة | تطبيع بادئة الدولة |
| `SectionDataResolver` | Dependent (E08) | يقرأ كتالوجاً عالمياً | يتبع قرار نطاق الكتالوج؛ لا تغيير محلي قبل ذلك |
| مجلد `resources/themes/default` و`theme_root_path()` | Preserve | fallback وحيد لكل الصفحات غير المركّبة | — |
| عمود `banners.theme` | Preserve | مفتاح cache واستعلامات قائمة | لا يُعاد توظيفه كمعرّف دولة |
| `/api/v1/theme/sections` | Preserve (مجمَّد) | عقد قديم منشور | توثيق أنه يقيّم كويب؛ لا تطوير |
| `AppCarouselSetting` + `newcontroller` | Refactor/إزالة بعد التحقق | صف عالمي ومتحكم غير مربوط | قرار المالك بعد معرفة الاستخدام الفعلي |
| مخطط الجداول + اختبارات الثيم | New | غائبان عن المستودع | استخراج المخطط من الإنتاج (قراءة فقط) وكتابة migrations مطابقة واختبارات عقد قبل أي تعديل |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E07-F01 | لا migrations ولا dump لثمانية جداول ثيم/تجربة ولا لـ `app_carousel_settings` وأعمدة `banners` الحديثة؛ الفهارس والقيود الفريدة والـ FK غير معروفة | P0 | VERIFIED (الغياب) / UNKNOWN (المخطط) | `lavkoo:app/Services/Theme/BuilderReadiness.php:54-56`، `lavkoo:app/Http/Controllers/Admin/Settings/ThemeManagementController.php:176-177`، `lavkoo:database/migrations/2023_08_21_042331_add_theme_to_banners_table.php:16-18` | لا يمكن تصميم عمود متجر أو فهرس فريد أو backfill بأمان؛ لا يمكن بناء بيئة اختبار من المستودع؛ خطر إتلاف ثيم السعودية الحي | الحصول على `SHOW CREATE TABLE` (قراءة فقط) وتثبيته كـ migrations مطابقة قبل أي مرحلة تنفيذ |
| E07-F02 | حصرية `is_active` عالمية و14 موضع قراءة بلا سياق | P1 | VERIFIED | `lavkoo:app/Services/Theme/ThemeManager.php:83-86`، `lavkoo:app/Services/Theme/ThemeDelivery.php:717`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:463` | تفعيل ثيم الإمارات يطفئ ثيم السعودية | محلِّل واحد للثيم النشط حسب المتجر + حصر `activate` |
| E07-F03 | مفاتيح cache العامة للثيم والحملات والتجارب والـ banners بلا بُعد متجر؛ مفاتيح التسليم تتمايز بـ checksum النسخة النشطة لا بالمتجر | P1 | VERIFIED (المفاتيح) / INFERRED (الأثر) | `lavkoo:app/Services/Theme/ThemeDelivery.php:128-133`، `lavkoo:app/Services/Theme/ThemeDelivery.php:650-674`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:88`، `lavkoo:app/Services/Commerce/CampaignResolver.php:43-47` | عند تعدّد الثيمات النشطة تتشارك المفاتيح العامة (`storefront_theme_page_{page}`، `theme_delivery_revision`، مفاتيح الحملات) بين المتاجر حتى انتهاء TTL | مكوّن متجر في المفاتيح العامة وفي `flush()`؛ اختبار عزل |
| E07-F04 | عقد التسليم بلا مُدخل متجر/دولة، و`ViewerContext` بلا سمة مقابلة، و`lang: sa` تعني «العربية» | P1 | VERIFIED | `lavkoo:app/Services/Theme/ViewerContext.php:69-105`، `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-27` | لا وسيلة للتطبيق لطلب ثيم دولة؛ خطر الخلط بين كود الدولة وكود اللغة | مُدخل اختياري جديد باسم لا يتصادم مع `lang`؛ الغياب = السعودية |
| E07-F05 | إشعار النشر: topic واحد وحمولة بلا متجر، وrevision تسلسل لكل ثيم | P1 | VERIFIED (الآلية) / INFERRED (الأثر) | `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27-29`، `lavkoo:app/Services/Theme/ThemeDelivery.php:186-195` | نشر دولة يوقظ كل الهواتف؛ أرقام revision قد تتطابق أو تتراجع بين ثيمات المتاجر | topic لكل متجر؛ المقارنة على (متجر، checksum) |
| E07-F06 | صفوف الـ banners عالمية ومرتبطة حيّاً بالـ blocks؛ تعديل draft يدفع إلى الصف فوراً؛ النسخ يشارك `banner_id` | P1 | VERIFIED (الآلية) / INFERRED (أثر بين المتاجر) | `lavkoo:app/Services/Theme/ThemeBuilderService.php:397-399`، `lavkoo:app/Services/Theme/ThemeBannerLink.php:140-176`، `lavkoo:app/Services/Theme/SectionDataResolver.php:478-497` | تعديل draft في متجر يغيّر صورة/رابطاً حيّاً في متجر آخر = نشر عرضي بين الدول | نطاق متجر على `banners`؛ نسخ صفوف `Theme Banner` مع الثيم؛ حصر الدفع |
| E07-F07 | الحملات والتجارب عالمية (slug الصفحة / `section_uuid`) | P1 | VERIFIED (الآلية) / INFERRED (الأثر) | `lavkoo:app/Services/Commerce/CampaignResolver.php:72-78`، `lavkoo:app/Services/Commerce/ExperimentResolver.php:83-87` | حملة `home` لدولة تكسو كل الدول؛ تجربة تصيب القسم المنسوخ في ثيم آخر | نطاق متجر/أسواق + ربط التجربة بالثيم |
| E07-F08 | نسخ الثيم (export/import) يحتفظ بالـ uuid وبمعرّفات كتالوج وأصول وروابط الدولة المصدر | P1 | VERIFIED (آلية النسخ والحذف) / INFERRED (انكسار الصور بعد حذف المصدر) | `lavkoo:app/Services/Theme/ThemePortabilityService.php:64`، `lavkoo:app/Services/Theme/ThemePortabilityService.php:199-209`، `lavkoo:app/Services/Theme/ThemeAssetService.php:76-80` | ثيم منسوخ يشير إلى منتجات/أقسام غير موجودة في كتالوج الدولة الهدف؛ حذف الثيم المصدر يحذف مجلد أصوله فتنكسر صور النسخة | إجراء نسخ مخصّص مع إعادة سكّ الهوية ونسخ الأصول وتقرير المراجع |
| E07-F09 | بيانات الأقسام من استعلامات عالمية (`Product::active()`، `storeStats`) ومصادر `/api/v1/...` ثابتة | P1 | VERIFIED (الاستعلامات) / INFERRED (أثر تعدد الدول) | `lavkoo:app/Services/Theme/SectionDataResolver.php:189-204`، `lavkoo:app/Services/Theme/SectionDataResolver.php:859-868`، `lavkoo:app/Services/Theme/ThemeSourceMap.php:44-45` | رفوف المنتجات والإحصاءات تعرض بيانات دول أخرى ما لم يُحصر الكتالوج | يتبع E08؛ اختبار قبول «رف في ثيم دولة لا يعرض منتج دولة أخرى» |
| E07-F10 | تحليل الروابط بالمقطع الأول؛ رابط داخلي بمقطع أول غير معروف (مثل بادئة `/sa`) يُعاد بنوع `URL` بدل تحليله | P1 | VERIFIED (المحلِّل) / INFERRED (أثر التطبيق) | `lavkoo:app/Services/Theme/ActionResolver.php:89-125`، `lavkoo:app/Services/Theme/ActionResolver.php:248-258` | بعد اعتماد مسارات الدول يُرجَّح أن تُعامَل الروابط الداخلية في التطبيق كروابط خارجية (يُحسم في نطاق E16/E17) | تطبيع بادئة الدولة قبل التحليل |
| E07-F11 | صلاحيات الثيم بلا نطاق بيانات: القدرات الخمس تنطبق على كل الثيمات دون فصل بين المتاجر | P1 | VERIFIED | `lavkoo:app/Services/Theme/ThemePermissionService.php:39-68`، `lavkoo:app/Services/Theme/ThemePermissionService.php:20-34` | عند تعدّد المتاجر تنطبق القدرة على ثيمات كل المتاجر (INFERRED؛ GCC-14) | نطاق متجر في خدمة الصلاحيات، يُفرض في المتحكمات والأمر المجدول |
| SEC-E07-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |
| E07-F12 | لا اختبار آلي واحد لنظام الثيم | P1 | VERIFIED (الغياب) | بحث مسجَّل في القسم 2.3 (`tests/`، `database/`، `Modules/`: صفر)؛ التعليق الداعم `lavkoo:app/Services/Theme/ThemeDelivery.php:179-181` | لا شبكة أمان لضابط «Preserve» ولا لعقد التطبيق المنشور | اختبارات عقد لـ `/theme/home` و`/version` ودورة النشر قبل أي تعديل |
| E07-F13 | نسخة ونشر وtokens واحدة للويب والتطبيق | INFO | VERIFIED | `lavkoo:app/Services/Theme/ExperiencePageService.php:65-69`، `lavkoo:app/Services/Theme/ThemeDelivery.php:564-577` | GCC-09 محقَّق على مستوى القسم/الصفحة؛ النشر المنفصل لكل قناة غير متاح | قرار مالك: هل النشر المنفصل مطلوب؟ إن لا فلا تغيير |
| E07-F14 | `/theme/sections` القديم يقيّم الإظهار كويب/desktop | P2 | VERIFIED | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:146`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:117-128` | قسم «للتطبيق فقط» لا يصل عبره وقسم «للويب فقط» يصل | تجميد المسار وتوثيقه |
| E07-F15 | منطقة زمنية واحدة لكل الجدولة؛ الافتراضي في الكود `Asia/Dhaka` | P2 | VERIFIED (الآلية والافتراضي) / INFERRED (أثر الفارق الزمني) | `lavkoo:app/Services/Theme/SectionVisibility.php:90-92`، `lavkoo:app/Providers/ConfigServiceProvider.php:30-33`، `lavkoo:config/app.php:69` | حملة «منتصف الليل» تفتح بفارق ساعة بين متاجر تختلف مناطقها الزمنية؛ وعند غياب سجل المنطقة الزمنية يعمل المتجر بـ`Asia/Dhaka` | منطقة زمنية للمتجر تُستعمل في نوافذ الثيم؛ تعيينها صراحةً لكل متجر |
| E07-F16 | `price_tiles`: حدود صحيحة `(int)` وعملة عالمية | P2 | VERIFIED | `lavkoo:resources/views/theme-sections/types/price_tiles.blade.php:19-24` | لا يمثّل كسور KWD/BHD/OMR؛ الحدود المنسوخة من SAR بلا معنى | حدود عشرية وعملة المتجر؛ عدم نسخها آلياً |
| SEC-E07-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | الملحق المقيّد | — | — |
| E07-F18 | نظافة كود: تعيين `$blocks` مكرّر حرفياً؛ `checksumFor` لا يشمل `channels`؛ مفتاح `theme_delivery_home_index` يُمسح ولا يُكتب | P2 | VERIFIED | `lavkoo:app/Services/Theme/ThemeDelivery.php:352-381`، `lavkoo:app/Services/Theme/ThemeDelivery.php:686-693`، `lavkoo:app/Services/Theme/ThemeDelivery.php:167` | تغيير `channels` وحده لا يحرّك checksum النسخة المخزَّن | تنظيف ضمن مرحلة التنفيذ المعتمدة |
| E07-F19 | `AppCarouselSetting` صف عالمي ومتحكم API غير مربوط | P2 | VERIFIED | `lavkoo:app/Http/Controllers/RestAPI/v3/newcontroller.php:11-35`، `lavkoo:routes/admin/routes.php:153` | بيانات يتيمة محتملة | قرار مالك |
| E07-F20 | `tokens.branding` لكل نسخة ثيم | INFO | VERIFIED | `lavkoo:app/Services/Theme/ThemeManager.php:26-29` | يدعم GCC-10 تلقائياً مع ثيم لكل متجر | تعريف مصدر «الافتراضي العالمي» (E02) |
| E07-F21 | قناة `vendor_app` مسجَّلة بلا عارض؛ تطبيق البائع لا يستهلك الثيم | INFO | VERIFIED | `lavkoo:app/Services/Theme/Channel.php:29-34`، `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:156-158` | لا أثر على تطبيق البائع من تغييرات الثيم | — |
| SEC-E07-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E07-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | INFERRED | الملحق المقيّد | — | — |

## 7. أسئلة مفتوحة وعناصر UNKNOWN

1. **المخطط الفعلي** للجداول التسعة وأعمدة `banners` الحديثة (أنواع، فهارس، قيود فريدة، FK، cascade). يحسمه: `SHOW CREATE TABLE` من الإنتاج (قراءة فقط) أو ملفات الـ migration الأصلية. `UNKNOWN (missing access: production schema)`.
2. **هل نُشر ثيم فعلاً في الإنتاج؟** وكم ثيماً ونسخة وحملة وتجربة توجد؟ وهل تُستعمل قيود `channels`/`platforms`؟ يحسمه: عدّ صفوف من قاعدة الإنتاج.
3. **أين كُتبت «theme tests» و«the seeder»** المذكوران في التعليقات (`lavkoo:app/Services/Theme/ThemeDelivery.php:179-181`، `lavkoo:app/Models/ThemeSection.php:37`)؟ لا يوجد اختبار ثيم ولا seeder للثيم في الكود الحالي (بحث مسجَّل في القسم 2.3)؛ قد يكونان في المستودع القديم. `UNKNOWN (missing access: pharmacyksa98)`.
4. **متجر الـ cache وبادئته في الإنتاج** (`CACHE_DRIVER`، `CACHE_PREFIX`) وهل توجد طبقة CDN/HTTP cache أمام `/api/v1/theme/*` تحترم `Vary`. يحسمه: `.env` وإعداد الخادم.
5. **هل ما زالت builds منشورة تستعمل `/theme/sections`؟** وهل `app_carousel_settings` مقروء من تطبيق ما عبر مسار خارج المستودع؟ يحسمه: سجلات الوصول.
6. **قرار مالك:** هل يلزم نشر منفصل للويب وللتطبيق داخل المتجر الواحد (GCC-09)، أم يكفي الفصل على مستوى القسم/الصفحة؟
7. **قرار مالك:** عند نسخ ثيم بين دولتين، هل تُنسخ الـ banners والأصول أم تُشارَك عمداً ضمن «الهوية المشتركة» (GCC-10)؟ الكود الحالي يشاركها ضمناً.
8. **قيمة `business_settings.timezone`** الإنتاجية وأثر تغييرها على نوافذ مجدولة قائمة.

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| `theme_root_path()` ثابت `default` | `lavkoo:app/Utils/theme-helpers.php:10-20` | VERIFIED |
| إضافة مجلد الثيم إلى مواقع الـ views | `lavkoo:app/Providers/ThemeServiceProvider.php:17-23` | VERIFIED |
| «exactly one theme is active» | `lavkoo:app/Models/Theme.php:11-12` | VERIFIED |
| حالات النسخة draft/published/archived | `lavkoo:app/Models/ThemeVersion.php:15-17` | VERIFIED |
| uuid القسم يُسكّ عند الإنشاء | `lavkoo:app/Models/ThemeSection.php:40-45` | VERIFIED |
| الأصول تتبع الثيم لا النسخة | `lavkoo:app/Models/ThemeAsset.php:9-13` | VERIFIED |
| `activate` يطفئ كل الثيمات الأخرى | `lavkoo:app/Services/Theme/ThemeManager.php:80-105` | VERIFIED |
| النشر transaction + flush بعد commit + beacon + audit | `lavkoo:app/Services/Theme/ThemeManager.php:108-168` | VERIFIED |
| draft من نسخة يحتفظ بالـ uuid وsettings الـ blocks | `lavkoo:app/Services/Theme/ThemeManager.php:171-210` | VERIFIED |
| revision لكل ثيم مع قفل | `lavkoo:app/Services/Theme/ThemeDelivery.php:177-198` | VERIFIED |
| مفتاح cache الحمولة ومكوّنات الـ fingerprint | `lavkoo:app/Services/Theme/ThemeDelivery.php:650-674` | VERIFIED |
| `flush()` يمسح مفتاحين فقط | `lavkoo:app/Services/Theme/ThemeDelivery.php:160-168` | VERIFIED |
| النسخة المنشورة = ثيم نشط + status published | `lavkoo:app/Services/Theme/ThemeDelivery.php:715-726` | VERIFIED |
| شكل الحمولة و ETag | `lavkoo:app/Services/Theme/ThemeDelivery.php:309-333` | VERIFIED |
| `emptyPayload` عند غياب المنشور | `lavkoo:app/Services/Theme/ThemeDelivery.php:700-713` | VERIFIED |
| مسارات الـ API الثلاثة العامة | `lavkoo:routes/rest_api/v1/api.php:475-487` | VERIFIED |
| 404 للصفحة غير المسموحة | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:75-94` | VERIFIED |
| معاينة عبر `preview` + no-store | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:195-201` | VERIFIED |
| `Vary` و304 | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:210-221` | VERIFIED |
| `fromRequest` وكل المُدخلات المقروءة | `lavkoo:app/Services/Theme/ViewerContext.php:69-105` | VERIFIED |
| القنوات الثلاث و`RENDERABLE` | `lavkoo:app/Services/Theme/Channel.php:23-34` | VERIFIED |
| ترتيب قواعد الاستهداف (قناة ← منصّة/جهاز ← جمهور) | `lavkoo:app/Services/Theme/SectionVisibility.php:123-152` | VERIFIED |
| 33 مكوّناً للتطبيق و6 مستثناة | `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:44-104` | VERIFIED |
| بوابة engine والقدرات | `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:193-213` | VERIFIED |
| cache بنية الصفحة للويب + تطبيق القواعد لكل طلب | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:66-103` | VERIFIED |
| `ViewerContext` الويب: web/desktop | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:117-128` | VERIFIED |
| `flush()` الويب يشمل slugs الصفحات المخصّصة | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:364-380` | VERIFIED |
| المعاينة: جلسة + `canView` ثم رمز URL | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:431-459` | VERIFIED |
| استبدال الصفحة الرئيسية القديمة عند وجود أقسام | `lavkoo:resources/themes/default/web-views/home.blade.php:15-23` | VERIFIED |
| حقن tokens في `<head>` | `lavkoo:resources/themes/default/layouts/front-end/app.blade.php:90-93` | VERIFIED |
| مسار الصفحة المخصّصة `/p/{slug}` | `lavkoo:routes/web/routes.php:223-225` | VERIFIED |
| القدرات الخمس وقاعدة الـ module | `lavkoo:app/Services/Theme/ThemePermissionService.php:20-68` | VERIFIED |
| مجموعة مسارات `admin/theme` | `lavkoo:routes/admin/routes.php:1693-1759` | VERIFIED |
| النشر المجدول كل 5 دقائق | `lavkoo:app/Console/ScheduleDefinition.php:53` | VERIFIED |
| claim ذرّي قبل النشر المجدول | `lavkoo:app/Console/Commands/ThemePublishDue.php:68-78` | VERIFIED |
| رمز المعاينة: HMAC بمفتاح التطبيق | `lavkoo:app/Services/Theme/ThemePreviewToken.php:76-79` | VERIFIED |
| beacon: topic ونوع وحمولة | `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27-56` | VERIFIED |
| الصفحات لكل ثيم مع قناة | `lavkoo:app/Services/Theme/ExperiencePageService.php:76-101` | VERIFIED |
| الحملة: الحقول والنطاق | `lavkoo:app/Models/ExperienceCampaign.php:33-40` | VERIFIED |
| حلّ الحملات بالـ slug فقط | `lavkoo:app/Services/Commerce/CampaignResolver.php:69-81` | VERIFIED |
| tick الحملات يفرّغ caches التسليم | `lavkoo:app/Console/Commands/CommerceCampaignsTick.php:32-49` | VERIFIED |
| التجربة: الحقول والتعيين بالـ hash | `lavkoo:app/Models/ExperienceExperiment.php:23-69` | VERIFIED |
| export يحمل uuid | `lavkoo:app/Services/Theme/ThemePortabilityService.php:55-69` | VERIFIED |
| import: ثيم جديد غير نشط، نسخة draft | `lavkoo:app/Services/Theme/ThemePortabilityService.php:159-176` | VERIFIED |
| دفع تعديل block إلى صف الـ banner | `lavkoo:app/Services/Theme/ThemeBannerLink.php:140-176` | VERIFIED |
| صف الـ banner يتغلّب على نسخة الـ block | `lavkoo:app/Services/Theme/SectionDataResolver.php:478-497` | VERIFIED |
| `store_banner` يقرأ النوع حيّاً مع `theme = default` | `lavkoo:app/Services/Theme/SectionDataResolver.php:372-396` | VERIFIED |
| عمود `banners.theme` افتراضه `default` | `lavkoo:database/migrations/2023_08_21_042331_add_theme_to_banners_table.php:16-18` | VERIFIED |
| إبطال cache الـ banners عند الحفظ/الحذف | `lavkoo:app/Models/Banner.php:93-119` | VERIFIED |
| مفاتيح cache الـ banners باسم مجلد الثيم | `lavkoo:app/Services/BannerPlacementService.php:79-93` | VERIFIED |
| `storeStats` يعدّ كل العملاء والطلبات | `lavkoo:app/Services/Theme/SectionDataResolver.php:859-868` | VERIFIED |
| تحليل الروابط بالمقطع الأول | `lavkoo:app/Services/Theme/ActionResolver.php:89-125` | VERIFIED |
| `lang: sa` ⇒ `ar` | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-27` | VERIFIED |
| المنطقة الزمنية من `business_settings` | `lavkoo:app/Providers/ConfigServiceProvider.php:30-33` | VERIFIED |
| أصول الثيم على القرص public بمسار theme id | `lavkoo:app/Services/Theme/ThemeAssetService.php:76-94` | VERIFIED |
| حذف الثيم يحذف مجلد أصوله وصفحاته | `lavkoo:app/Http/Controllers/Admin/Settings/ThemeManagementController.php:165-184` | VERIFIED |
| اللوحة تفترض وجود migrations | `lavkoo:app/Services/Theme/BuilderReadiness.php:83-88` | VERIFIED |
| تطبيق العملاء: ثوابت المسارات والـ topic | `lavkooapp:lib/utill/app_constants.dart:58-60`، `lavkooapp:lib/utill/app_constants.dart:329-330` | VERIFIED |
| تطبيق العملاء: ترويسات القدرات | `lavkooapp:lib/features/dynamic_theme/engine/capability_registry.dart:31-33` | VERIFIED |
| تطبيق العملاء: مفتاح cache محلي بالصفحة فقط | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:31` | VERIFIED |
| غياب migrations جداول الثيم | بحث مسجَّل في القسم 2.3 (لا موضع يُستشهد به) | VERIFIED (غياب) |
| غياب اختبارات الثيم | بحث مسجَّل في القسمين 1 و2.3 | VERIFIED (غياب) |
| مخطط الجداول في الإنتاج | — | UNKNOWN |
| الافتراضي الزمني في الكود `Asia/Dhaka` | `lavkoo:config/app.php:69` | VERIFIED |
| draft احتياطي من ثيم غير نشط في المحرّر | `lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:859` | VERIFIED |
| مفتاح الحمولة يتمايز بـ checksum/revision النسخة النشطة (بلا متجر) | `lavkoo:app/Services/Theme/ThemeDelivery.php:650-674` | VERIFIED |
| `theme_delivery_revision` و`storefront_theme_page_` بلا checksum | `lavkoo:app/Services/Theme/ThemeDelivery.php:79`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:88` | VERIFIED |
| `$__renderable` يضم 37 نوعاً | `lavkoo:resources/views/theme-sections/home.blade.php:22-28` | VERIFIED |
| ذكر «the seeder» في التعليقات | `lavkoo:app/Models/ThemeSection.php:37` | VERIFIED |
| عدد أنواع المكوّنات 33 والمستثناة 6 | `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:44-104` | VERIFIED |

## 9. سجل التحقق المستقل

> التحقق: مُحقِّق مستقل على الكود المدقَّق `lavkoo@7f42777`، قراءة فقط؛ لم يُشغَّل أي كود ولم تُجرَ أي كتابة في المستودعات.

| البند | العدد |
|---|---|
| المطالبات المفحوصة (تقريباً) | 100 |
| المؤكَّدة دون تغيير | 81 |
| المصحَّحة | 17 |
| المرفوضة | 2 |
| غير القابلة للتحقق الساكن (UNKNOWN، خارج العدّ أعلاه) | 6 |

**المراجع الآلية:** `check_citations.py` على الوثيقة بعد التصحيح: 352 مرجع ملف و7 مراجع commit، 0 عيوب. `scan_sensitive.py`: 0 نتائج. الملحق المقيّد: 36 مرجع ملف، 0 عيوب.

**الفحص الدلالي:** جميع مطالبات P0 و P1 (F01 إلى F12) ونحو 80 مطالبة أخرى من الأقسام 2 إلى 4 ومصفوفة الـ cache وسجل الأدلة. كل مطالبة غياب أُعيد البحث عنها بأنماط مختلفة، وشملت: الجداول في الـ migrations وفي المستودع كله، الـ migrations المحذوفة، اختبارات الثيم، مفردات النطاق (متجر/دولة/عملة/سوق)، مسار `curselre` غير المربوط، مفتاح `home_index`، عمود `mobile_photo`، الـ seeders، مفاتيح الصيانة في الثيم، واستعمال تطبيق البائع.

**التصحيحات:**
1. رأس الوثيقة: الـ HEAD وقت التحقق `4120149` لا `046d623d`، والفرق ثلاثة ملفات توثيق.
2. القسم 2.3: نطاق مطابقات الجداول (الكود المدقَّق فقط)، وصياغة سجل `app/Services/Theme`.
3. القسم 2.4: أُضيف افتراض المحرّر عند غياب منشور للثيم النشط.
4. القسم 2.4 والقسم 6 (F11 وF17): نُقلت تفاصيل قيد حالة النسخة وتفاصيل نطاق الملكية إلى الملحق (SEC-E07-03 وSEC-E07-04).
5. القسم 2.7 والقسم 4 (GCC-09 وGCC-10): أُعيدت صياغة بعض الألفاظ الخاصة بالتخصيص؛ الوقائع دون تغيير.
6. القسم 2.9: مفتاح `theme_delivery_{page}_{fingerprint}` يشمل checksum/revision النسخة النشطة، وكان ناقصاً في الجدول.
7. القسم 3 (A8): القيمة الافتراضية للمنطقة الزمنية في الكود `Asia/Dhaka`.
8. القسم 3 (A16): أُضيف افتراض «الثيم النشط» في المحرّر (VERIFIED).
9. القسم 4 (GCC-10): فُصل ما هو VERIFIED (تخصيص الهوية لكل نسخة) عمّا هو INFERRED (العمل التلقائي مع ثيم لكل متجر).
10. E07-F03: الخطر يقع على المفاتيح العامة فقط؛ مفاتيح التسليم تتمايز بـ checksum النسخة النشطة.
11. E07-F05 وF06 وF07 وF09: فُصلت الآلية (VERIFIED) عن الأثر عبر المتاجر (INFERRED).
12. E07-F10: الوصف صار سلوك المحلِّل (VERIFIED)، وأثره في التطبيق INFERRED.
13. E07-F11: الوسم VERIFIED للكود، والأثر عبر المتاجر INFERRED.
14. E07-F12: دليل الغياب صار بحثاً مسجَّلاً بدل تعليق يذكر الاختبارات.
15. E07-F15: أُضيف المرجع `config/app.php:69` وقيمته، ومثال الفارق الزمني INFERRED.
16. القسم 7 (س3): أُضيف مرجع «the seeder» (`ThemeSection.php:37`)، وأن الكود الحالي لا يحوي seeder ولا اختبار للثيم.
17. القسم 6 والقسم 8: صيغة مؤشرات SEC موحّدة إلى `SEC-E07-NN:`، وأُضيفت مراجع القسم 8 للوقائع الجديدة.

**المطالبات المرفوضة:**
- R1: «الـ HEAD المحلي `lavkoo@046d623d` يضيف ملف توثيق واحداً». غير صحيح: الـ HEAD `4120149`، والفرق ثلاثة ملفات.
- R2: «البحث في تطبيق البائع أعاد مطابقة وحيدة في `config_model.dart:1064`». غير صحيح: صفر مطابقات، والإشارة إلى `vendor_app` في ذلك الملف لا علاقة لها بالثيم.

**بنود منقولة إلى الملحق المقيّد:** 4 (SEC-E07-01 وSEC-E07-02 من المسودة الأصلية؛ SEC-E07-03 وSEC-E07-04 في هذا التحقق).

**مخاطر متبقية (أسماء الأقسام):** القسم 2.9 (قيم المفاتيح الإنتاجية)، القسم 2.3 (المخطط الفعلي)، القسم 3 (القيمة الإنتاجية للمنطقة الزمنية)، القسم 6 (F03 وF05 وF06 وF07 وF09 وF10: الآثار عبر المتاجر INFERRED)، القسم 7 (البنود 1 و2 و4 و8).

**فجوات التغطية:**
- لم يُفحص دلالياً: التفاصيل الداخلية لـ `ThemeBuilderService` و`SectionDataResolver` لكل نوع، وقوالب الأقسام الـ 37 عدا `custom_html` و`price_tiles` و`home.blade.php`، و`CacheManagerTrait::cacheRemoveByType`، و`ExperienceHealth`، وأوامر `CommerceCampaignsTick` من الداخل.
- تطبيقات Flutter: أُكِّدت المراجع الحرفية فقط، ولم يُفحص منطق `dynamic_theme` داخلياً (نطاق E17).
- الفحص الآلي شمل كل المراجع الـ 352؛ الفحص الدلالي شمل ما ورد أعلاه فقط.
