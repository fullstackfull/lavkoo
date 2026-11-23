# E17 — الثيم الديناميكي المُدار من الخادم في تطبيق العملاء وعقده مع الخلفية (Customer Flutter app: server-driven dynamic theme and its backend contract)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkooapp@eedbbb8fd0303aa9fed5d4ff0ac45b3a409abf7a` (تطبيق العملاء) · `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` (الخلفية) · `ksaapp98@79f50bec1c419cae8bc1a6f4c314962d3b682ac0` (تطبيق العملاء القديم — مرجع للمقارنة فقط)
> الاختبارات: لم يُشغَّل أي اختبار أو أمر بناء (tests NOT RUN)؛ كل ما يرد أدناه قراءة ثابتة للكود.

## 1. النطاق وما تم فحصه

**ما قُرئ بالكامل (تطبيق العملاء `lavkooapp`):**

| المجموعة | الملفات | الحجم التقريبي |
|---|---|---|
| ميزة الثيم الديناميكي `lib/features/dynamic_theme/**` | 24 ملفاً (controller، schema، repository، service، engine، 6 renderers، شاشتان، 4 widgets) | 6,881 سطراً |
| اختبارات الثيم في `test/` | `dynamic_theme_controller_test`، `dynamic_theme_registry_test`، `dynamic_theme_render_matrix_test`، `dynamic_theme_schema_test`، `section_variant_coverage_test`، `theme_action_resolver_test` | 1,722 سطراً |
| طبقة الشبكة والتخزين المحلي | `lib/data/datasource/remote/dio/dio_client.dart`، `lib/data/local/cache_response.dart`، `lib/helper/db_helper.dart`، `lib/data/reposotories/data_sync_repo.dart` | ≈ 600 سطر |
| نقاط الربط | `lib/localization/controllers/localization_controller.dart`، `lib/push_notification/notification_helper.dart` (الأسطر 1–215 و445–456)، `lib/features/splash/controllers/splash_controller.dart` (1–200)، `lib/features/home/screens/home_screens.dart` (1–330 و486–790)، `lib/features/home/screens/aster_theme_home_screen.dart` (40–290)، `lib/features/dashboard/screens/dashboard_screen.dart` (70–120)، `lib/utill/app_constants.dart` (1–110 و280–360)، `lib/features/banner/**` (controller/model/repository)، `lib/theme/**` (4 ملفات)، `lib/data/services/deep_link_resolver.dart`، `lib/data/services/analytics_reporter.dart`، `android/app/src/main/AndroidManifest.xml` (فلاتر الروابط)، `ios/Runner/Runner.entitlements`، `ANIMATION_SYSTEM_GUIDE.md` (مسح بالكلمات المفتاحية) | — |

**ما قُرئ في الخلفية `lavkoo` (الطرف المقابل للعقد):** `routes/rest_api/v1/api.php` (مجموعة `theme` وما يتصل بها)، `app/Http/Controllers/RestAPI/v1/ThemeSectionController.php` (410 أسطر، كاملاً)، ومن `app/Services/Theme/`: `ThemeDelivery.php` (733)، `ViewerContext.php` (194)، `ComponentCapabilityRegistry.php` (240)، `Channel.php` (115)، `ThemePreviewToken.php` (80)، `ThemeSyncBeacon.php` (71)، `ThemeSourceMap.php` (264)، `ActionResolver.php` (259)، `SectionDestination.php` (98)، `SectionVisibility.php` (202)، `LocalisedSettings.php` (177)، `ThemeManager.php` (269)، `ExperiencePageService.php` (295)، `ThemeCompatibilityReport.php` (96)، `SectionReach.php` (82) كاملةً؛ وأجزاء من `SectionRegistry.php` (1,340 سطراً — استُخرجت التعريفات آلياً بسكربت قراءة فقط)، `SectionDataResolver.php` (الأسطر 362–560 و859–868)، `StorefrontThemeRenderer.php` (1–200 و425–479)، `ThemePortabilityService.php` (36–304: التصدير والتحقق والاستيراد)، `ThemeBuilderController.php` (880–940)؛ والنماذج `Theme`، `ThemeVersion`، `ThemeSection`، `ExperiencePage`؛ و`APILocalizationMiddleware.php`؛ و`ConfigController.php` (مفاتيح الثيم والإصدار). المجموع التقريبي للملفات المفحوصة في المستودعات الثلاثة: 85 ملفاً.

**أنماط البحث المسجَّلة (تستند إليها ادعاءات «غير موجود»):**

- ب1 — وضع المعاينة في التطبيق: البحث عن `preview` (غير حسّاس للحالة) في `lavkooapp/lib` و`lavkooapp/test` أعاد 104 مطابقات كلها خارج الثيم (معاينة صور/ملفات/منتجات رقمية)؛ والبحث الموجَّه عن `theme.{0,40}preview` و`preview.{0,40}theme` و`theme_preview` و`preview=` و`previewToken` أعاد صفر مطابقات.
- ب2 — بُعد المتجر في الخلفية: البحث عن `storefront_id` و`store_id` و`country_id` و`market_id` و`legal_entity` و`country_code` و`X-Storefront` و`X-Country` و`X-Store` داخل `app/Services/Theme` و`ThemeSectionController.php` ونماذج الثيم الأربعة أعاد صفر مطابقات.
- ب3 — بُعد المتجر في التطبيق: البحث عن `X-Store` و`X-Country` و`X-Market` و`zoneId` و`zone_id` و`market_id` و`store_id` في `lavkooapp/lib` أعاد صفر مطابقات (كلمة storefront تظهر في التعليقات فقط).
- ب4 — اختبارات الخلفية: البحث عن `ThemeDelivery` و`theme/home` و`theme/version` و`ThemeSectionController` و`ComponentCapabilityRegistry` و`ThemeSyncBeacon` و`ThemePreviewToken` و`theme_sections` و`theme_versions` داخل `lavkoo/tests` (397 ملفاً) أعاد صفر ملفات.
- ب5 — ترحيلات جداول الثيم: البحث عن `Schema::create` للجداول `themes` و`theme_versions` و`theme_sections` و`theme_blocks` و`experience_pages` في كامل المستودع عدا `vendor` أعاد صفر مطابقات؛ والملف الوحيد في `database/migrations` الذي يحمل كلمة theme هو `lavkoo:database/migrations/2023_08_21_042331_add_theme_to_banners_table.php` وهو غير متعلق بهذه الجداول.
- ب6 — رؤوس الإصدار في محرك الثيم: البحث عن `X-App-Version` و`app_version` و`X-Platform` داخل `app/Services/Theme` و`ThemeSectionController.php` أعاد مطابقة واحدة هي نص ترويسة `Vary`.
- ب7 — تطبيق البائعين: البحث عن `theme/home` و`theme/version` و`theme/sections` و`X-UI-` و`dynamic_theme` و`theme_updates` في `lavkooappseller/lib` أعاد صفر مطابقات.
- ب8 — مواضع مسح ذاكرة Dio: البحث عن `clearMemoryCache` و`clearDiskCache` في `lavkooapp/lib` أعاد موضعَي استدعاء فقط (عدا التعريف).
- ب9 — البحث عن `themeSectionsUri` أعاد التعريف فقط؛ والبحث عن `componentVersion` خارج ملف النموذج وعن `ThemeEngineDiagnostics` خارج ملف تعريفها أعاد صفراً.
- ب10 — البحث عن `home_index` في `lavkoo/app` أعاد موضعاً واحداً (عملية `forget` فقط).
- ب11 — لا يوجد مجلد `.github/workflows` في `lavkooapp` (يوجد في `ksaapp98`).
- ب12 — مسارات الثيم في الـAPI: البحث عن `theme` (غير حسّاس للحالة) في `routes/rest_api/v2` و`routes/rest_api/v3` أعاد صفر مطابقات؛ كل مسارات الثيم في `routes/rest_api/v1/api.php`.
- ب13 — البحث عن `animateSectionEntrance` و`app_animations` داخل `lavkooapp/lib/features/dynamic_theme` أعاد صفر مطابقات (26 مطابقة لـ`animateSectionEntrance` في بقية `lib`).
- ب14 (أضافه التحقق المستقل) — كل مواضع استدعاء مزامنة الثيم: البحث عن `DynamicThemeController` خارج مجلد الميزة وعن `.sync(` و`.refresh()` و`loadPage(` و`HomePage.loadData` في `lavkooapp/lib` أعاد: `main.dart` (تسجيل المزوِّد)، `notification_helper.dart` (إشعار النشر)، `home_screens.dart` و`aster_theme_home_screen.dart` (`sync(force: reload)`)، `localization_controller.dart` (تغيير اللغة)، `dynamic_home_view.dart` و`experience_page_screen.dart`، ومواضع `loadData(false)` في `dashboard_screen.dart` و`login_screen.dart` و`logout_confirm_bottom_sheet_widget.dart`. لا يوجد موضع آخر، ولا يوجد أي استدعاء قسري مرتبط بتسجيل الدخول أو الخروج.
- ب15 (أضافه التحقق المستقل) — بُعد الثيم أو المتجر في الحملات والتجارب: البحث عن `theme_id` و`theme_version` و`storefront_id` و`store_id` و`country` و`market` داخل `app/Services/Commerce/CampaignResolver.php` و`ExperimentResolver.php` و`SegmentResolver.php` و`app/Models/ExperienceCampaign.php` أعاد صفر مطابقات.
- ب16 (أضافه التحقق المستقل) — مواضع إصدار رمز المعاينة: البحث عن `->mint(` و`ThemePreviewToken` في `app` و`routes` و`resources` أعاد موضع إصدار واحداً (`ThemeBuilderController`) وموضعَي تحقق (مسار الويب ومسار الـAPI).
- ب17 (أضافه التحقق المستقل) — استهلاك tokens في التطبيق: البحث عن `_token(` في `lavkooapp/lib/features/dynamic_theme` أعاد سبعة مواضع قراءة فقط (كلها في `theme_style.dart`)؛ والبحث عن `branding` و`typography` خارج ملف النموذج أعاد فرعَي الـswitch في `theme_style.dart` دون أي قارئ.
- ب18 (أضافه التحقق المستقل) — الرؤوس المخصصة في التطبيق: البحث عن النمط `'X-…'` في `lavkooapp/lib` أعاد ستة أسماء فقط: `X-Platform`، `X-App-Version`، `X-Client-Id`، `X-UI-Components`، `X-UI-Engine`، `X-UI-Schema`. والبحث عن `storefront` و`market_id` و`zone_id` و`store_code` و`country_iso` أعاد تعليقات وأسماء أصناف عرض فقط؛ والبحث عن `currency` و`country` داخل مجلد الميزة أعاد صفراً.
- ب19 (أضافه التحقق المستقل) — البحث عن النص `footer` بين علامتَي اقتباس داخل `lavkooapp/lib/features/dynamic_theme` أعاد صفر مطابقات (يدعم C17).
- ب20 (أضافه التحقق المستقل) — إعادة ب4 و ب5 بأنماط أوسع: البحث عن `ViewerContext` و`ActionResolver` و`ThemeManager` و`ThemeSourceMap` و`SectionRegistry` و`ThemePortability` داخل `lavkoo/tests` أعاد صفر ملفات؛ والبحث عن `theme_versions` و`theme_sections` و`experience_pages` في ملفات `php` و`sql` و`json` خارج `vendor` أعاد ملفات خدمات ومتحكمات وأمر مجدول وملفات ترجمة فقط، بلا أي ترحيل أو ملف SQL.

**ما لم يُفحص:** واجهة منشئ الثيم في لوحة الإدارة وقوالب Blade لعرض الويب، `ThemeBuilderService`، القوالب الجاهزة في `ThemePortabilityService` (الأسطر 305 فما بعد)، `PublishValidator`، تفاصيل `SegmentResolver`، بطاقة المنتج `ProductWidget` وتسعيرها، إعدادات iOS الأصلية عدا ملف entitlements، السلوك وقت التشغيل، قيم قاعدة البيانات الإنتاجية، والمستودع القديم للخلفية (`UNKNOWN (missing access: pharmacyksa98)`). فحص التحقق المستقل لاحقاً أجزاءً لم يفحصها المؤلف: `ThemeBannerLink` (الدوال `syncBlock` و`pushBlockEdits` و`syncVersion`)، `CampaignResolver` (الدوال `overridesFor` و`resolveOverrides` و`stamp` و`serving`)، `ExperimentResolver` (الدالتان `assignmentsFor` و`running`)، والأمر المجدول `ThemePublishDue`؛ ونتائجها في البنود E17-F23 إلى E17-F25 وفي تعديل E17-F04 و E17-F09.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 ثلاثة مفاهيم مختلفة تحمل اسم «theme»

| المفهوم | المصدر | أثره في التطبيق | الدليل |
|---|---|---|---|
| ثيم مجلد الويب (folder theme) | `active_theme` في `/api/v1/config` يساوي ناتج `theme_root_path()` الذي يعيد الثابت `'default'` | يختار التطبيق بين `HomePage` و`AsterThemeHomeScreen`؛ ومع القيمة الثابتة يُستخدم `HomePage` دائماً | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:231`، `lavkoo:app/Utils/theme-helpers.php:16-19`، `lavkooapp:lib/features/dashboard/screens/dashboard_screen.dart:108-112` |
| ثيم المنشئ (Theme Builder) — جداول `themes`/`theme_versions`/`theme_sections` | `/api/v1/theme/version` و`/api/v1/theme/home` | يستبدل **محتوى** الصفحة الرئيسية فقط؛ الشريط العلوي والبحث والتنقل تبقى ملكاً للتطبيق | `lavkooapp:lib/features/dynamic_theme/screens/dynamic_home_view.dart:14-23`، `lavkooapp:lib/features/home/screens/home_screens.dart:546-551` |
| مظهر التطبيق نفسه (فاتح/داكن + لوحة ألوان مُصرَّفة) | `SharedPreferences` بالمفتاح `theme` وثوابت `BrandColors` | هوية الواجهة الثابتة (chrome) | `lavkooapp:lib/theme/controllers/theme_controller.dart:14-18`، `lavkooapp:lib/theme/custom_theme_colors.dart:8-21`، `lavkooapp:lib/theme/light_theme.dart:7-9` |

هذا الملف يتناول المفهوم الثاني. المفهوم الأول يبقى مؤثراً لأن اختيار شاشة الرئيسية يعتمد عليه، والثالث يحدّ من أثر «tokens» الخادم (انظر 2.10).

### 2.2 مخطط الحمولة (schema) كما يحلّله التطبيق

التحليل «كلّي» (total): كل الحقول اختيارية وكل قراءة تتراجع إلى قيمة افتراضية ولا ترمي استثناءً (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:494-531`)، واختبار `wrong types everywhere still parse` يثبّت ذلك (`lavkooapp:test/dynamic_theme_schema_test.dart:62-83`).

**أ) مستوى الصفحة — `ThemePayload`** (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:421-440`) مقابل ما يبنيه الخادم (`lavkoo:app/Services/Theme/ThemeDelivery.php:309-331`):

| مفتاح JSON | حقل التطبيق والافتراضي | ملاحظات |
|---|---|---|
| `page` | `page` — الافتراضي `home` | يُستخدم جزءاً من مفتاح التخزين المحلي |
| `revision` | `revision` — الافتراضي 0 | 0 تعني «لا شيء منشور»؛ الصفحة قابلة للعرض فقط إذا `revision > 0` ووُجدت أقسام (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:419`) |
| `schema_version` / `engine_version` | يُقرآن ويُعرضان في شاشة تشخيص غير مربوطة بأي مسار | لا مقارنة ولا رفض من جهة العميل (2.8) |
| `checksum` | `checksum` | ETag لكل عميل: SHA-256 مقتطع على الحمولة المُسلَّمة فعلاً (`lavkoo:app/Services/Theme/ThemeDelivery.php:331`) |
| `content_stamp` | `contentStamp` | صدى لـ`checksum` الذي تعيده `/theme/version`؛ هو مُصادِق المزامنة (`lavkoo:app/Services/Theme/ThemeDelivery.php:316`) |
| `published_at` | نص | للعرض التشخيصي فقط |
| `tokens` | `ThemeTokens` بأربع مجموعات: `colors`، `typography`، `layout`، `branding` | يُستهلك منها 7 مفاتيح فقط (2.10) |
| `sections[]` | قائمة `ThemeSectionSchema`؛ يُسقَط كل عنصر بلا `type` | — |
| `compatibility.withheld` | خريطة «نوع → سبب» | للتشخيص؛ `compatibility.delivered` لا يُقرأ |
| `preview` | لا يُقرأ | يرسله الخادم في وضع المعاينة فقط (`lavkoo:app/Services/Theme/ThemeDelivery.php:150-157`) |

**ب) مستوى القسم — `ThemeSectionSchema`** (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:299-328`) مقابل `ThemeDelivery::present` (`lavkoo:app/Services/Theme/ThemeDelivery.php:434-472`):

| مفتاح JSON | المعالجة في التطبيق |
|---|---|
| `uuid` | هوية ثابتة عبر النشر؛ عند غيابه يُشتق `section-<id>` (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:305`) |
| `id` | رقم الصف؛ يُستخدم هوية لتقارير المشاهدة (`lavkooapp:lib/features/dynamic_theme/screens/dynamic_home_view.dart:52-57`) |
| `type` | مفتاح البحث في سجل الـrenderers |
| `variant` | نمط العرض؛ عند غيابه يُقرأ `settings.style` ثم `settings.layout` (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:310-312`) |
| `component_version` | يُحلَّل (الافتراضي 1) ولا يستخدمه أي renderer (بحث ب9) |
| `settings` | حقيبة `ThemeSettings` بقراءات مطبوعة لا ترمي (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:190-228`) |
| `blocks[]` | `{id, type, settings}`؛ يُسقَط ما لا `type` له (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:124-140`) |
| `cards[]` أو `null` | بطاقات البانر بعد دمجها حيّاً مع صفوف Banner Setup في الخادم (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:143-183`) |
| `source` | `{kind, endpoint, params, note, deal_ends_at, tabs[]}`؛ `kind` أحد `inline` / `api` / `none` (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:65-121`، `lavkoo:app/Services/Theme/ThemeSourceMap.php:14-18`) |
| `experiment` | `{key, variant}` يتحول إلى وسم `key:variant` لتقارير المشاهدة (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:231-238`) |
| `view_all` | إجراء مطبوع يقرره الخادم لرابط «عرض الكل» |

**ج) الإجراء — `ThemeAction`**: الحقول `type`، `url`، `slug`، `id`، `query`، `collection`، `campaign`، `label` (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:17-62`). يرسل الخادم النوع ومعه الرابط الأصلي دائماً (`lavkoo:app/Services/Theme/ActionResolver.php:76-126`).

**د) حقول الإصدار:** ثوابت العميل `schemaVersion = 1` و`engineVersion = 1` (`lavkooapp:lib/features/dynamic_theme/engine/capability_registry.dart:24`، `lavkooapp:lib/features/dynamic_theme/engine/capability_registry.dart:27`) تطابق ثوابت الخادم `SCHEMA_VERSION = 1` و`CURRENT_ENGINE_VERSION = 1` (`lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:29`، `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:32`). كل مكوّن في الخادم مُسجَّل بـ`version = 1` و`engine = 1` (`lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:44-89`).

### 2.3 سجل المكوّنات والمتغيّرات وسلوك «المجهول»

**السجل:** 7 أصناف renderer تغطي 34 نوع قسم؛ تُسجَّل في `ThemeEngine.renderers` (`lavkooapp:lib/features/dynamic_theme/engine/theme_engine.dart:25-33`) وتُعلَن للخادم في `ThemeCapabilities.components` (`lavkooapp:lib/features/dynamic_theme/engine/capability_registry.dart:40-86`). اختبار `capability contract` يفرض تطابق القائمتين كمجموعة (`lavkooapp:test/dynamic_theme_schema_test.dart:266-277`). تحقّقتُ آلياً (سكربت قراءة فقط) من أن القائمة المعلنة = أنواع الـrenderers = 34.

| الـrenderer | الأنواع | شرط العرض `canRender` | المتغيّرات (variant) التي لها فرع، والافتراضي |
|---|---|---|---|
| `BannerSectionRenderer` | `hero_banner`، `promotional_banner`، `split_banner`، `banner_mosaic`، `banner_strip`، `store_banner` | وجود بطاقة واحدة على الأقل (`lavkooapp:lib/features/dynamic_theme/renderers/banner_section_renderer.dart:60-61`) | `store_banner`: grid، mosaic، strip، split، swipe، والافتراضي carousel؛ `promotional_banner`: rail، overlap، swipe، والافتراضي grid؛ `banner_mosaic` يقرأ `settings.display` (`lavkooapp:lib/features/dynamic_theme/renderers/banner_section_renderer.dart:104-129`) |
| `ProductSectionRenderer` | `product_slider`، `featured_deal`، `clearance_sale`، `deal_of_the_day`، `flash_deal`، `category_showcase`، `brand_showcase`، `bundle`، `vendor_showcase` | `source.kind == api` مع endpoint (`lavkooapp:lib/features/dynamic_theme/renderers/product_section_renderer.dart:51`) | عام: grid، list، carousel، spotlight، والافتراضي rail؛ `flash_deal`: grid، banner؛ `deal_of_the_day`: card، banner؛ `bundle` شبكة دائماً (`lavkooapp:lib/features/dynamic_theme/renderers/product_section_renderer.dart:228-251`) |
| `TaxonomySectionRenderer` | `category_grid`، `brand_slider`، `vendor_slider` | `source` من نوع api (`lavkooapp:lib/features/dynamic_theme/renderers/taxonomy_section_renderer.dart:35`) | `category_grid`: cards، tiles، chips؛ `brand_slider`: grid، story؛ `vendor_slider`: rail، compact، logos، storefront (`lavkooapp:lib/features/dynamic_theme/renderers/taxonomy_section_renderer.dart:61-84`) |
| `ContentSectionRenderer` | `usp_strip`، `stats_bar`، `testimonials`، `faq`، `interest_tiles`، `price_tiles`، `app_download` | وجود blocks، عدا `app_download` (`lavkooapp:lib/features/dynamic_theme/renderers/content_section_renderer.dart:39-40`) | `faq`: two_column، cards؛ `testimonials`: wall، compact؛ `interest_tiles`: circles، rail؛ الباقي: plain، dark (`lavkooapp:lib/features/dynamic_theme/renderers/content_section_renderer.dart:104-129`) |
| `UtilitySectionRenderer` | `product_tabs`، `stories`، `branches`، `before_after`، `shipping_cutoff`، `coupon_strip`، `custom_html` | شرط لكل نوع (`lavkooapp:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:47-54`) | `branches`: list؛ `shipping_cutoff`: card؛ `coupon_strip`: cards، strip (`lavkooapp:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:98-113`). ملاحظة: الحقل `card` معرَّف في الـwidget الفعّال ولا يُقرأ في البناء، فنمطا `shipping_cutoff` يُرسمان بالشكل نفسه (`lavkooapp:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:616-624`) |
| `AnnouncementSectionRenderer` | `announcement_bar` | وجود `settings.text` (`lavkooapp:lib/features/dynamic_theme/renderers/chrome_section_renderer.dart:21-22`) | — |
| `SpacerSectionRenderer` | `spacer` | ارتفاع أكبر من صفر (`lavkooapp:lib/features/dynamic_theme/renderers/chrome_section_renderer.dart:69-70`) | — |

**سلوك المجهول — لا placeholder ولا انهيار، بل «تخطٍّ صامت»:**

| الحالة | السلوك | الكود | الاختبار |
|---|---|---|---|
| نوع قسم لا يعرفه هذا البناء | الخادم يحجبه أولاً ويذكر السبب في `compatibility.withheld`؛ وإن وصل، يتخطاه السجل ويسجّله في `unhandledTypes` | `lavkoo:app/Services/Theme/ThemeDelivery.php:247-252`، `lavkooapp:lib/features/dynamic_theme/engine/section_renderer_registry.dart:52-58` | `lavkooapp:test/dynamic_theme_registry_test.dart:61-88`، `lavkooapp:test/dynamic_theme_registry_test.dart:96-108` |
| renderer يرفض أو يرمي أثناء البناء | يُلتقط الاستثناء ويُعاد `null`؛ يخسر القسمَ وحده | `lavkooapp:lib/features/dynamic_theme/engine/section_renderer_registry.dart:60-72` | الاختبار نفسه |
| خطأ أعمق داخل شجرة widgets القسم | في بناء الإصدار فقط: `ErrorWidget.builder` يعيد `SizedBox.shrink`؛ في debug تبقى الشاشة الحمراء | `lavkooapp:lib/main.dart:99-101` | لا اختبار |
| `variant` غير معروف | يسقط إلى الفرع الافتراضي لنوعه | الدوال `*LayoutFor` في الجدول أعلاه | `lavkooapp:test/section_variant_coverage_test.dart:52-58` |
| نوع إجراء غير معروف | يُعامَل كرابط: يفتح `url` خارجياً، وبلا `url` لا يكون العنصر قابلاً للنقر | `lavkooapp:lib/features/dynamic_theme/engine/theme_action_resolver.dart:39`، `lavkooapp:lib/features/dynamic_theme/engine/theme_action_resolver.dart:94-98` | `lavkooapp:test/theme_action_resolver_test.dart:29-45` |
| حمولة فارغة أو أنواع بيانات خاطئة | تُحلَّل إلى حمولة غير قابلة للعرض ⇒ الصفحة الرئيسية المضمَّنة | `lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:419` | `lavkooapp:test/dynamic_theme_schema_test.dart:54-60` |
| قسم بلا بيانات (سكة منتجات فارغة مثلاً) | يختفي كاملاً (`SizedBox.shrink`) | `lavkooapp:lib/features/dynamic_theme/renderers/product_section_renderer.dart:124-126` | مصفوفة العرض |

**ثلاث ملاحظات على أمانة السجل:** (1) القائمة `offered` لـ`vendor_slider` في اختبار التغطية ثلاثة أنماط (`lavkooapp:test/section_variant_coverage_test.dart:70-74`) بينما يعرض الخادم خمسة (`lavkoo:app/Services/Theme/SectionRegistry.php:628-632`)؛ الكود نفسه يعالج الخمسة لكن الاختبار لا يغطي `storefront` و`logos`. (2) مصفوفة العرض تختبر أنماطاً ليست في مفردات الخادم الحالية (`lavkooapp:test/dynamic_theme_render_matrix_test.dart:308-319` للعلامات، و`lavkooapp:test/dynamic_theme_render_matrix_test.dart:399-402` لـ`interest_tiles`) فتمرّ عبر الفرع الافتراضي دون أن تثبت شيئاً عن الأنماط الفعلية. (3) `app_download` مُعفى من شرط الـblocks، وتعريفه في الخادم بلا أي نوع block (`lavkoo:app/Services/Theme/SectionRegistry.php:747-759`)، والـwidget الذي يرسمه يبني قائمة أفقية بعدد الـblocks (`lavkooapp:lib/features/dynamic_theme/renderers/content_section_renderer.dart:166-171`)؛ فالناتج في التطبيق عنوان فوق شريط فارغ بارتفاع ثابت، بلا أزرار المتاجر التي يرسمها الويب.

### 2.4 الجلب: نقاط النهاية والمعاملات والرؤوس والاستجابة والإيقاع

**نقاط النهاية** (`lavkooapp:lib/utill/app_constants.dart:58-60`) على العنوان الأساسي المُصرَّف `https://lavkoo.com` (`lavkooapp:lib/utill/app_constants.dart:39`، `lavkooapp:lib/di_container.dart:206`):

| الطلب | المعاملات | الدليل |
|---|---|---|
| `GET /api/v1/theme/version` | لا معاملات | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:64-82` |
| `GET /api/v1/theme/home` | `page` (القيم: `home`، `header`، أو slug لصفحة مركّبة) و`device` (`mobile` أو `tablet` بحسب أقصر ضلع ≥ 600dp) | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:96`، `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:51-61` |
| بيانات كل قسم | المسار والمعاملات من `source`، مع إلحاق `guest_id=1` إن غاب | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:139-155` |
| `/api/v1/theme/sections` (القديم غير المُرقَّم) | ثابت معرَّف غير مستخدم (بحث ب9) | `lavkooapp:lib/utill/app_constants.dart:60` |

**الرؤوس المُرسَلة فعلياً:**

| الرأس | القيمة | المصدر |
|---|---|---|
| `Authorization` | `Bearer <رمز العميل>`؛ وللضيف يُرسَل الرأس نفسه بقيمة رمز فارغة (`null` نصاً) | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73` |
| `X-Platform` | `ios` أو `android` | الموضع نفسه |
| `X-App-Version` | الثابت `AppConstants.appVersion` وقيمته `16.2.1` — وليس إصدار `pubspec.yaml` وهو `3.0.6+48` | `lavkooapp:lib/utill/app_constants.dart:29`، `lavkooapp:pubspec.yaml:5` |
| `X-Client-Id` | معرّف تثبيت عشوائي ثابت | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:75-82` |
| `lang` | **رمز اللغة** (`ar`/`en`) من التفضيلات عند كل طلب | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:89-92` |
| `X-UI-Components` | قائمة الأنواع الـ34 مفصولة بفواصل | `lavkooapp:lib/features/dynamic_theme/engine/capability_registry.dart:89-93` |
| `X-UI-Engine` / `X-UI-Schema` | `1` / `1` | الموضع نفسه |
| `If-None-Match` | `"<checksum المحمول>"` على طلب `/theme/home` فقط | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:90-93` |

رؤوس الثيم تُمرَّر عبر `Options(headers: …)` لكل طلب؛ ودمجها مع الرؤوس الافتراضية سلوك مكتبة Dio (INFERRED من دلالة المكتبة، لم يُختبر تشغيلياً). لا يُرسَل: `X-UI-Channel`، معامل `preview`، ولا أي رأس أو معامل يدل على دولة أو متجر أو عملة (بحث ب3). الحقل `countryCode` في `DioClient` يُخزَّن ولا يدخل في الرؤوس (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:28-29`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:49-53`).

**معالجة الاستجابة** (`lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:85-136`): 304 ⇒ نجاح بحمولة `null` أي «ما تحمله حديث»؛ 404 ⇒ خطأ مطبوع `page_gone` (الصفحة أُزيلت أو عُطّلت)؛ جسم ليس كائناً ⇒ خطأ؛ حمولة قابلة للعرض ⇒ تُكتب في التخزين المحلي؛ حمولة غير قابلة للعرض ⇒ يُحذف مدخل الصفحة من التخزين.

**خوارزمية المزامنة** (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:163-287`):

1. في أول مزامنة للجلسة تُعرض النسخة المخزنة محلياً قبل أي اتصال (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:173-182`).
2. إن لم تكن المزامنة قسرية يُسأل `/theme/version`. `revision == 0` له وجهان: إن وصل `schema_version > 0` فالخادم يقول «لا شيء منشور» فتُمسح الحالة والتخزين المحلي؛ وإن كان كل شيء صفراً فالطلب فشل (offline) ويبقى المخزَّن (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:209-229`).
3. قرار التنزيل `ThemeRevision.isNewerThan`: أي اختلاف في `revision` ⇒ تنزيل؛ وإلا مقارنة `checksum` الخادم بـ`content_stamp` المحمول (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:469-481`).
4. يُطلب `/theme/home` مع `If-None-Match`؛ عند الفشل تبقى الصفحة المعروضة ويُسجَّل `lastError` (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:233-248`).
5. إن عادت حمولة غير قابلة للعرض وكانت لدينا صفحة، تُسقَط الصفحة ويعود التطبيق للرئيسية المضمَّنة (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:250-259`).
6. عند تغيّر `checksum` تُمسح بيانات الأقسام والصفحات المركّبة وعدّادات المشاهدة، ثم تُجلب صفحة `header` بالطريقة نفسها (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:261-286`).

**مُحفِّزات المزامنة — لا يوجد مؤقّت دوري:**

| المُحفِّز | النداء | قسري؟ | الدليل |
|---|---|---|---|
| أول إطار للوحة الرئيسية | `HomePage.loadData(false)` ثم `sync` | لا | `lavkooapp:lib/features/dashboard/screens/dashboard_screen.dart:91-101`، `lavkooapp:lib/features/home/screens/home_screens.dart:66-71` |
| بناء `DynamicHomeSections` | `sync()` | لا | `lavkooapp:lib/features/dynamic_theme/screens/dynamic_home_view.dart:66-71` |
| عودة التطبيق إلى الواجهة (resume) | `sync()` | لا | `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:36-39` |
| رسالة FCM صامتة من النوع `theme_version_changed` والتطبيق في الواجهة | `sync()`؛ قيمة `revision` في الرسالة لا تُقرأ | لا | `lavkooapp:lib/push_notification/notification_helper.dart:85-94`، `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:48-70` |
| سحب للتحديث في `HomePage` | مسح ذاكرة Dio ثم `loadData(true)` | نعم | `lavkooapp:lib/features/home/screens/home_screens.dart:239-242` |
| تغيير اللغة | `onLanguageChanged()` ثم `refresh()` | نعم | `lavkooapp:lib/localization/controllers/localization_controller.dart:49-52`، `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:359-373` |
| تسجيل الخروج | `loadData(false)` | لا | `lavkooapp:lib/features/more/widgets/logout_confirm_bottom_sheet_widget.dart:117-125` |
| نجاح تسجيل الدخول (أضافه التحقق المستقل) | `loadData(false)` | لا | `lavkooapp:lib/features/auth/screens/login_screen.dart:662-676` |
| فتح صفحة مركّبة | `loadPage(slug)` مرة واحدة في الجلسة | — | `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:105-134` |

الاشتراك في موضوع الإشعار `theme_updates_user_app` يتم بعد نجاح جلب الإعدادات في `SplashController.initConfig`، وهو كل ما يخص الثيم في مرحلة الـsplash؛ لا جلب مسبق للثيم هناك (`lavkooapp:lib/features/splash/controllers/splash_controller.dart:142-150`).

**تحفّظ جوهري على «الفحص الرخيص» (VERIFIED بالقراءة الثابتة):** المسار `/api/v1/theme/version` مُدرج في قائمة `_cacheableEndpoints` لطبقة `DioClient` العامة (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-140`). هذه الطبقة تعيد الاستجابة من ذاكرة ثابتة (static) **بلا مدة صلاحية** ما دامت العملية حيّة (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:189-192`)، ومن `SharedPreferences` لمدة `_cacheDurationMs` (الافتراضي 300000 مللي ثانية، ويستبدله جواب `/api/v1/cash` وهو الثابت 400000 في الخلفية: `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:103-122`، `lavkoo:routes/rest_api/v1/api.php:58-60`). الذاكرة لا تُمسح إلا في موضعين: السحب للتحديث في `HomePage` وتغيير اللغة (بحث ب8). النتيجة: بعد أول جواب ناجح في الجلسة، مزامنة الـresume ومزامنة إشعار النشر تتلقيان الجواب القديم نفسه فلا يُنزَّل النشر الجديد حتى سحب يدوي، تغيير لغة، أو إقلاع بارد بعد انقضاء مدة القرص. اختبارات الـcontroller تستخدم خدمة بديلة ولا تمرّ عبر `DioClient` (`lavkooapp:test/dynamic_theme_controller_test.dart:9-57`) فلا تكشف ذلك. المسار في الخادم مخزَّن أيضاً 600 ثانية (`lavkoo:app/Services/Theme/ThemeDelivery.php:34-35`، `lavkoo:app/Services/Theme/ThemeDelivery.php:79`) لكن النشر يمسحه (`lavkoo:app/Services/Theme/ThemeDelivery.php:160-168`).

**بيانات الأقسام:** تُجلب عند أول بناء للقسم وتُحفظ في الذاكرة بمفتاح `uuid` (أو `uuid#index` لتبويبات `product_tabs`)؛ الجواب الناجح يُحتفظ به طوال الجلسة، والفشل يُعاد بعد 30 ثانية عند أول إعادة بناء (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:67`، `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:306-338`). معظم مسارات المصادر التي يسمّيها الخادم (`lavkoo:app/Services/Theme/ThemeSourceMap.php:117-140`) تقع أيضاً ضمن `_cacheableEndpoints`، فتُخزَّن بالمفتاح «المسار + المعاملات مرتّبة + `#lang`» (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-172`).

### 2.5 التخزين المحلي والعمل دون اتصال والرئيسية البديلة

| الطبقة | المخزن | المفتاح | الأبعاد الداخلة في المفتاح | الإبطال | الدليل |
|---|---|---|---|---|---|
| حمولة الثيم | جدول Drift `cache_response` في قاعدة `cache_response_new_db` (عمود `endPoint` فريد) | `/api/v1/theme/home::<page>`؛ عمود `header` يحمل `{"revision": N}` | **الصفحة فقط** | استبدال عند كل جلب ناجح قابل للعرض؛ حذف المدخل عند حمولة غير قابلة للعرض؛ حذف بالبادئة حين يقول الخادم «لا شيء منشور»؛ مسح الجدول كله عند تغيير اللغة | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:31`، `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:36-42`، `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:126-130`، `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:177-196`، `lavkooapp:lib/data/local/cache_response.dart:5-11`، `lavkooapp:lib/data/local/cache_response.dart:22`، `lavkooapp:lib/localization/controllers/localization_controller.dart:42-44` |
| حالة الذاكرة | حقول `DynamicThemeController` | `_payload`، `_header`، `_pages[slug]`، `_sectionData[uuid]` | slug أو uuid | عند تغيّر checksum، `refresh()`، تغيير اللغة | `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:49-77`، `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:345-350` |
| جواب `/theme/version` وبيانات معظم الأقسام | ذاكرة `DioClient` + `SharedPreferences` بالبادئة `api_cache_` | المسار + المعاملات + `#<lang>` | **اللغة فقط** | الذاكرة: يدوياً؛ القرص: 400000 مللي ثانية | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:195-215`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:233-237`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:361-374` |
| بيانات الرئيسية البديلة (بانرات، تصنيفات…) | Drift عبر `DataSyncRepo` | `<uri>#<lang>` | **اللغة فقط** | مسح عند تغيير اللغة | `lavkooapp:lib/data/reposotories/data_sync_repo.dart:36-39` |
| إعدادات المتجر | Drift | `/api/v1/config` | لا شيء | استبدال عند كل جلب | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:83-84` |

**ما لا يدخل في مفتاح حمولة الثيم:** اللغة (تُعالَج بمسح شامل وإعادة جلب قسرية)، فئة الجهاز، الجمهور (ضيف/عميل/شرائح)، قدرات البناء وإصداره، تعيين التجارب، والمتجر/الدولة (غير موجود أصلاً). مُصادِق المزامنة (`revision` + `content_stamp`) لا يتغير بتغير الجمهور أو القدرات، ولا يوجد إبطال عند تسجيل الدخول/الخروج أو بعد ترقية التطبيق؛ فالحمولة المتفاوَض عليها لجمهور أو بناء سابق تبقى حتى النشر التالي أو سحب يدوي — مع أن الخادم يُدخل الجمهور والشرائح والقدرات في مفتاحه هو (`lavkoo:app/Services/Theme/ThemeDelivery.php:650-674`). هذه سابقة مهمة لعدسة GCC: البُعد الوحيد الذي عولج في العميل (اللغة) احتاج «epoch» ومسحاً صريحاً واختبارَين (`lavkooapp:test/dynamic_theme_controller_test.dart:341-355`، `lavkooapp:test/dynamic_theme_controller_test.dart:394-413`).

**دون اتصال:** تُعرض النسخة المخزنة أولاً؛ فشل فحص الإصدار يعيد `ThemeRevision.none` فيبقى المخزَّن (`lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:75-81`؛ اختبار `lavkooapp:test/dynamic_theme_controller_test.dart:103-116`). قراءة مخزَّن تالف تُعامَل كغياب (`lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:158-174`).

**الرئيسية البديلة الثابتة:** المفتاح الوحيد هو `hasPublishedTheme` (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:151`). عند `false` تعرض `HomePage` تركيبها الأصلي: البانر الرئيسي والتصنيفات (`lavkooapp:lib/features/home/screens/home_screens.dart:298-299`، `lavkooapp:lib/features/home/screens/home_screens.dart:519-537`) ثم العروض الخاطفة والمميزة والتصفية والمنتجات المميزة وأفضل البائعين والأحدث والعلامات وقائمة المنتجات (`lavkooapp:lib/features/home/screens/home_screens.dart:551-774`). حركة الدخول المتتابعة للأقسام التي يصفها دليل الحركة (`lavkooapp:ANIMATION_SYSTEM_GUIDE.md:87-89`) مطبَّقة على أقسام الرئيسية البديلة فقط، ولا تُستخدم داخل الأقسام الديناميكية (بحث ب13). عند الإقلاع البارد تكون القيمة `false` إلى أن تنتهي قراءة المخزَّن، فتُجلب بيانات الرئيسية القديمة مرة واحدة ثم تُتخطى في التحديثات اللاحقة (`lavkooapp:lib/features/home/screens/home_screens.dart:73-78`، `lavkooapp:lib/features/home/screens/home_screens.dart:124-144`). الصفحات المركّبة تُكتب على القرص عند جلبها (أي حمولة قابلة للعرض تُكتب: `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:126-130`) لكن `loadPage` يستدعي الجلب الشبكي فقط (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:105-134`)، وقراءة المخزَّن مقتصرة على صفحة المزامنة و`header` (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:174-176`).

### 2.6 وضع المعاينة (preview) من زاوية التطبيق

- **الخادم:** `/theme/home` يقبل معامل الاستعلام `preview` يحمل رمزاً موقَّعاً؛ إن صحّ تُسلَّم النسخة غير المنشورة التي يسمّيها مع `preview: true` وترويسة `Cache-Control: no-store, private` وبلا ETag؛ وإن غاب أو انتهى أو عُبث به يُتجاهل وتُسلَّم الصفحة المنشورة (`lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:195-201`). الرمز موقَّع ومحدود المدة ويسمّي نسخة واحدة؛ مدته الافتراضية 60 دقيقة وأقصاها 1440 (`lavkoo:app/Services/Theme/ThemePreviewToken.php:27-39`، `lavkoo:app/Services/Theme/ThemePreviewToken.php:47-68`). لا يحمل الرمز هوية مستخدم ولا قناة ولا متجراً (`lavkoo:app/Services/Theme/ThemePreviewToken.php:33-39`).
- **إصدار الرابط:** لوحة الإدارة تصدر رابطاً ورمز QR لجذر **الويب** بالمعامل `theme_preview` بعد فحص صلاحية `canView` (`lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:901-930`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:64`). لا يوجد مُصدِر لرابط يحمل معامل الـAPI `preview`.
- **حدود الصلاحية (GCC-14):** إصدار الرمز محروس بصلاحية `canView` في `ThemeBuilderController::previewLink` (`lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:901-907`)، ولا يوجد في `ThemePermissionService` نطاق صلاحية لكل متجر أو دولة؛ البحث عن `country` و`market` و`store` و`region` في الملف لم يُسفر إلا عن تعليقات تذكر `storefront`. فلا يوجد اليوم ما يحدّ من يعاين مسودة متجر بعينه.
- **التطبيق:** لا يرسل `preview` ولا يقرأ `theme_preview` ولا يملك أي واجهة معاينة (بحث ب1)، ولا يقرأ المفتاح `preview` من الحمولة. فمن زاوية التطبيق **وضع المعاينة غير موجود**؛ والتفويض كله خادمي (صلاحية الإدارة عند الإصدار + توقيع الرمز عند الاستخدام).
- **أثر جانبي (INFERRED):** فلتر روابط التطبيق في Android يلتقط جذر النطاق (`lavkooapp:android/app/src/main/AndroidManifest.xml:122-142`) والمسار `/` يفتح لوحة الرئيسية دون قراءة معاملات الاستعلام عدا `referral_code` (`lavkooapp:lib/helper/route_healper.dart:1081-1107`). فمسح رمز QR للمعاينة على هاتف مثبَّت عليه التطبيق والروابط موثَّقة قد يفتح التطبيق على الصفحة **المنشورة** بدل مسودة الويب. يتوقف ذلك على حالة توثيق الروابط في النظام (UNKNOWN وقت التشغيل).
- **تحذير تصميمي:** لو وُصل `preview` مستقبلاً دون تعديل، فإن `fetchPayload` سيكتب حمولة المسودة في المخزَّن المحلي كصفحة رئيسية لأنه لا يميّز المعاينة (`lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:126-130`).

SEC-E17-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E17-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 2.7 حلّ الإجراءات والروابط العميقة من محتوى الثيم

الخادم يحلّل رابط التاجر مرة واحدة بحسب **أول مقطع في المسار** إلى نوع من قائمة مغلقة من 12 نوعاً (`lavkoo:app/Services/Theme/ActionResolver.php:40-44`، `lavkoo:app/Services/Theme/ActionResolver.php:89-125`)، ويَعُدّ الرابط خارجياً إذا خالف نطاقُه نطاقَ `app.url` (`lavkoo:app/Services/Theme/ActionResolver.php:248-258`). التطبيق يحوّل النوع إلى تنقّل محلي في `ThemeActionResolver` (`lavkooapp:lib/features/dynamic_theme/engine/theme_action_resolver.dart:24-99`):

| النوع | شرط القابلية للنقر | الوجهة في التطبيق | ملاحظة |
|---|---|---|---|
| `product` | `slug` أو `id` | شاشة المنتج | — |
| `category` / `brand` | `slug` أو `id`؛ والفتح الفعلي يتطلب `id` وإلا فُتح الرابط خارجياً | قائمة منتجات التصنيف/العلامة؛ العنوان من `label` ثم من قائمة التصنيفات المحمّلة ثم `slug` | `lavkooapp:lib/features/dynamic_theme/engine/theme_action_resolver.dart:133-156` |
| `vendor` | `slug` أو `id` | متجر البائع | — |
| `search` | دائماً | شاشة البحث؛ **`query` لا يُمرَّر** | `lavkooapp:lib/helper/route_healper.dart:863-865` |
| `cart` / `wishlist` | دائماً | السلة / المفضلة | — |
| `campaign` | `id` غير فارغ | شاشة العرض الخاطف؛ **`id` لا يُمرَّر** | `lavkooapp:lib/helper/route_healper.dart:695-697` |
| `collection` | `collection` غير فارغ | 6 مجموعات إلى `ViewAllProduct`، و5 إلى شاشات خاصة؛ غير ذلك (مثل `most_favorite`) يفتح الرابط خارجياً | `lavkooapp:lib/features/dynamic_theme/engine/theme_action_resolver.dart:168-210`، `lavkoo:app/Services/Theme/ActionResolver.php:55-69` |
| `page` | `slug` غير فارغ | `ExperiencePageScreen` داخل التطبيق | `lavkooapp:lib/features/dynamic_theme/engine/theme_action_resolver.dart:159-166` |
| `url` وأي نوع مجهول | وجود `url` | `launchUrl` بتطبيق خارجي؛ يُشترط أن يحمل الرابط scheme وإلا فلا شيء | `lavkooapp:lib/features/dynamic_theme/engine/theme_action_resolver.dart:212-224` |

مصادر الإجراء في الحمولة: `settings.action` للقسم، `action` لكل block ولكل بطاقة، و`view_all`؛ والخادم لا يضيف `action` إلا حيث يوجد مفتاح `link` (`lavkoo:app/Services/Theme/ActionResolver.php:138-145`). بانرات الرئيسية البديلة تستخدم المحلِّل نفسه، ولها مسار احتياطي مختلف عبر بوابة `/api/v1/deep-link/resolve` (`lavkooapp:lib/features/banner/controllers/banner_controller.dart:107-122`، `lavkooapp:lib/features/banner/controllers/banner_controller.dart:160-166`)، بينما إجراءات الثيم من النوع `url` تفتح المتصفح مباشرة دون المرور بالبوابة.

**الروابط تُخزَّن عناوين مطلقة وقت الحفظ:** منتقي الوجهة في المنشئ يكتب الرابط بـ`url($path)`، أي بنطاق الطلب الإداري لحظة الحفظ وبمسار بلا بادئة دولة (`lavkoo:app/Services/Theme/LinkComposer.php:38-51`، `lavkoo:app/Services/Theme/LinkComposer.php:104-108`)، ورابط «عرض الكل» يُبنى بـ`route()` عند كل طلب (`lavkoo:app/Services/Theme/SectionDestination.php:36-58`). هذا يخص الروابط التي ينتجها المنتقي؛ أما الرابط الذي يكتبه التاجر يدوياً (النوع `url`) فيُحفظ كما كُتب، نسبياً كان أو مطلقاً (`lavkoo:app/Services/Theme/LinkComposer.php:97-99`)، ونقطة الاستدعاء الوحيدة للمنتقي هي متحكم المنشئ (`lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:678`). فالمحتوى المخزَّن مرتبط بالنطاق وبشكل المسار الحاليين: رابط مخزَّن بنطاق يخالف `app.url` يُصنَّف خارجياً فيخرج من التطبيق إلى المتصفح. هل تحمل الروابط المخزَّنة في الإنتاج النطاق السابق؟ UNKNOWN (بيانات).

### 2.8 الاقتران بإصدار التطبيق

- **التوافق يُتفاوَض عليه بالقدرات لا بأرقام الإصدار.** الخادم يرسل تقاطع ما يعلنه العميل مع القائمة الآمنة للتطبيق، ويشترط أن يكون `X-UI-Engine` غير أدنى من متطلَّب المكوّن (`lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:193-213`). `X-UI-Schema` يُقرأ ويدخل مفتاح التخزين المؤقت فقط دون أي منع (`lavkoo:app/Services/Theme/ViewerContext.php:84-87`).
- **المسار القديم:** عميل لا يعلن شيئاً يُعامَل كقادر على رسم كل ما هو آمن للتطبيق (`lavkoo:app/Services/Theme/ViewerContext.php:179-182`).
- **`X-App-Version` غير مستخدم في محرك الثيم** (بحث ب6)، وقيمته في التطبيق `16.2.1` لا تمثل الإصدار الفعلي (`lavkooapp:lib/utill/app_constants.dart:9-29`).
- **بوابة التحديث الإجباري آلية منفصلة:** تقارن `user_app_version_control` من الإعدادات بالثابت نفسه (`lavkooapp:lib/features/splash/screens/splash_screen.dart:325-338`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:236`). التعليق في الكود يذكر أن الحد الأدنى الحي 14.1؛ القيمة الإنتاجية الحالية UNKNOWN.
- **جهة العميل متسامحة:** `schema_version` أعلى ليس خطأً والمحلِّل يتجاهل ما لا يعرفه (`lavkooapp:lib/features/dynamic_theme/engine/capability_registry.dart:22-24`)، ولا يوجد رفض للحمولة بسبب الإصدار.
- **قصور في الاقتران:** ترقية التطبيق لا تُبطل الحمولة المخزنة المتفاوَض عليها للبناء السابق (2.5).

### 2.9 جدول العقد: ما يرسله التطبيق ويتوقعه مقابل ما تقبله الخلفية وتعيده

| # | البند | التطبيق | الخلفية | الحالة |
|---|---|---|---|---|
| C01 | طلب الإصدار | `GET /api/v1/theme/version` مع رؤوس `X-UI-*` | `version()` لا يقرأ الطلب؛ جواب واحد عام مخزَّن بالمفتاح `theme_delivery_revision` (`lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:236-239`، `lavkoo:app/Services/Theme/ThemeDelivery.php:79`) | متوافق؛ الرؤوس مهمَلة |
| C02 | جواب الإصدار | يقرأ `revision`، `checksum`، `schema_version`، `engine_version` | يعيدها مع `published_at` (`lavkoo:app/Services/Theme/ThemeDelivery.php:64-105`) | متوافق |
| C03 | معاملات `/theme/home` | `page`، `device` | `page` يُتحقق منه مقابل الصفحات المتاحة للقناة وإلا 404؛ `device`؛ بدائل استعلام `components`/`ui_engine`/`ui_schema`/`channel`؛ `preview` (`lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:75-94`، `lavkoo:app/Services/Theme/ViewerContext.php:69-105`) | متوافق؛ `preview` لا يُرسل |
| C04 | قائمة القدرات | 34 نوعاً منها `vendor_showcase` (`lavkooapp:lib/features/dynamic_theme/engine/capability_registry.dart:59`) | 33 نوعاً آمناً؛ `vendor_showcase` في قائمة الاستثناءات (`lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:103`) | **عدم تطابق** — القسم يُحجب دائماً عن التطبيق رغم وجود renderer له |
| C05 | ETag | `If-None-Match` بالـchecksum المحمول؛ 304 ⇒ إبقاء | ETag = checksum الحمولة المُسلَّمة؛ مطابقة بالاحتواء؛ 304 (`lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:203-221`، `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:248-256`) | متوافق |
| C06 | صفحة غير متاحة | 404 ⇒ `page_gone` ⇒ «لا محتوى» بلا إعادة محاولة (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:121-124`) | 404 بجسم `errors` | متوافق |
| C07 | مُصادِق المزامنة | `content_stamp` مقابل `checksum` الإصدار | القيمة نفسها من `syncStamp` في الطرفين (`lavkoo:app/Services/Theme/ThemeDelivery.php:630-639`) | متوافق |
| C08 | اللغة | `lang: ar` أو `en` | وسيط `api_lang` يضبط اللغة، ويحوّل القيمتين القديمتين `sa` و`us` إلى `ar` و`en` (`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29`) | متوافق — مع تداخل دلالي بين رمز دولة ورمز لغة |
| C09 | الجمهور | `Authorization` | `auth('api')` ⇒ ضيف/عميل + شرائح؛ يدخل مفتاح التخزين وترويسة `Vary` (`lavkoo:app/Services/Theme/ViewerContext.php:132-159`، `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:210`) | متوافق خادمياً؛ لا إبطال محلي |
| C10 | `X-Platform`، `X-App-Version` | يُرسَلان | لا يقرؤهما محرك الثيم (`X-Platform` مذكور في `Vary` فقط) | مهمَل |
| C11 | `X-Client-Id` | يُرسَل | هوية الزائر لتوزيع التجارب على الضيوف (`lavkoo:app/Services/Theme/ViewerContext.php:110-124`، `lavkoo:app/Services/Telemetry/ClientIdentity.php:41`) | متوافق |
| C12 | إطارات البطاقة المتعددة | يقرأ `image_2` و`image_3` من البطاقة (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:157-161`) | البطاقة تحمل المصفوفة `images` ولا تحمل `image_2`/`image_3` (`lavkoo:app/Services/Theme/SectionDataResolver.php:499-532`) | **عدم تطابق** — التبديل بين الإطارات لا يعمل في التطبيق؛ واختبار التطبيق يثبّت افتراضه هو (`lavkooapp:test/dynamic_theme_schema_test.dart:289-315`) |
| C13 | رأس `vendor_showcase` | يتوقع `settings.shop` (`lavkooapp:lib/features/dynamic_theme/widgets/theme_shop_header.dart:19-22`) | يُضاف في `/theme/sections` القديم فقط (`lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:314-325`) ولا يُضاف في `ThemeDelivery::present` | **عدم تطابق** (مُقنَّع بـC04) |
| C14 | أنواع الإجراءات | 11 نوعاً + مسار احتياطي | 12 نوعاً شاملة `none` | متوافق؛ فجوات: `search.query` و`campaign.id` لا يُستخدمان، و`most_favorite` يخرج للمتصفح |
| C15 | بلاطات السعر | تتوقع `action` لتكون قابلة للنقر | block النوع `price_band` بلا مفتاح `link` (`lavkoo:app/Services/Theme/SectionRegistry.php:968-975`) فلا `action` | **عدم تطابق** — غير قابلة للنقر في التطبيق |
| C16 | مصدر البيانات | يتبع `endpoint` + `params`، ويضيف `guest_id=1` | يسمّي مسارات v1 ويضيف `guest_id=1` (`lavkoo:app/Services/Theme/ThemeSourceMap.php:247-263`) | متوافق |
| C17 | الصفحات | `home`، `header`، وصفحات مركّبة؛ **لا يطلب `footer` أبداً** | `home`/`header`/`footer` مضمونة + صفحات لكل قناة (`lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:44`) | فجوة: أقسام `footer` لا تصل التطبيق |
| C18 | إشعار النشر | موضوع `theme_updates_user_app`، نوع `theme_version_changed` (`lavkooapp:lib/utill/app_constants.dart:329-330`) | الثوابت نفسها (`lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27-29`) | متوافق؛ لكنه غير فعّال داخل الجلسة (2.4) |
| C19 | الحدّ من المعدّل | نحو 3 طلبات عند الإقلاع | `throttle:60,1` على مجموعة `theme` (`lavkoo:routes/rest_api/v1/api.php:475-487`) | متوافق |
| C20 | المتجر/الدولة | لا يُرسَل شيء | لا يُقرأ شيء | **غائب في الطرفين** |

### 2.10 تخصيص الويب مقابل التطبيق كما هو اليوم

الويب والتطبيق يقرآن **النسخة المنشورة نفسها للثيم النشط الوحيد**: `ThemeDelivery::publishedVersion` (`lavkoo:app/Services/Theme/ThemeDelivery.php:715-726`) و`StorefrontThemeRenderer::publishedVersion` (`lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:461-471`). أي: قائمة أقسام واحدة مرتّبة لكل صفحة، و`revision` واحد، ونشر واحد يحرّك السطحين معاً. الفروق الممكنة حالياً:

| الآلية | ماذا تتيح | الدليل |
|---|---|---|
| `theme_sections.channels` (القيم: `web`، `customer_app`، `vendor_app`) | إظهار قسم لقناة دون أخرى | `lavkoo:app/Services/Theme/Channel.php:23-34`، `lavkoo:app/Services/Theme/SectionVisibility.php:123-131`، `lavkoo:app/Services/Theme/ThemeDelivery.php:228-242` |
| `theme_sections.platforms` (رموز المنصة web/app وفئة الجهاز) و`audience` | استهداف بالاتحاد | `lavkoo:app/Services/Theme/SectionVisibility.php:133-149` |
| إعدادات لكل نقطة توقف (`<key>_mobile`، `<key>_tablet`، `visible_mobile`…) | قيمة مختلفة للهاتف؛ تُطوى في الخادم بحسب `device` | `lavkoo:app/Services/Theme/ThemeDelivery.php:515-530`، `lavkoo:app/Services/Theme/SectionVisibility.php:75-85` |
| صفحات مركّبة خاصة بقناة (`experience_pages.channel`) | شاشة «عروض» للتطبيق فقط مثلاً؛ الصفحات النظامية الثلاث مشتركة دائماً | `lavkoo:app/Services/Theme/ExperiencePageService.php:76-101`، `lavkoo:app/Services/Theme/ExperiencePageService.php:132-153`، `lavkoo:app/Services/Theme/ExperiencePageService.php:161-189` |
| حجب القدرات لكل بناء | 6 أنواع ويب فقط + ما لا يعلنه البناء | `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:97-104` |
| ترجمة العميل لقيم الويب | مثلاً تباعد الويب مضروباً في 0.35 | `lavkooapp:lib/features/dynamic_theme/widgets/theme_section_shell.dart:29-30` |

**تدقيق أضافه التحقق المستقل:** استهداف القناة مطبَّق على مسار الويب أيضاً — عارض الويب يبني `ViewerContext` بمنصة `web` ويمرّر كل قسم مع مفتاح `channels` على `SectionVisibility::passes` (`lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:117-132`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:410`). ينتج عن ذلك أن تركيباً مختلفاً لكل قناة ممكن اليوم في الصفحة نفسها بتكرار القسم: نسخة موجَّهة للويب وأخرى للتطبيق، كلٌّ في موضعه وبإعداداته. فالقول بغياب «الترتيب المستقل» صحيح بنيوياً (عمود `sort_order` واحد وقائمة واحدة) لكنه لا يعني استحالة اختلاف التركيب بين القناتين.

**غير موجود:** قائمة أقسام مرتّبة مستقلة لكل قناة (المتاح هو التكرار مع الاستهداف)، دورة مسودة/نشر مستقلة للتطبيق، أو tokens خاصة بقناة. تطبيق البائعين مُسجَّل قناةً (`vendor_app`) لكن الخادم يعيد له قائمة أنواع فارغة (`lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:156-158`) ولا يستدعي نقاط الثيم إطلاقاً (بحث ب7).

**القناة يسمّيها العميل بنفسه:** `X-UI-Channel` أو المعامل `channel` يحدّدان قناة الطلب، وإن غابا تُحدَّد من المنصة (`lavkoo:app/Services/Theme/ViewerContext.php:89-91`). فالقناة تخصيص لعرض الأقسام ولا تصلح حاجزاً أمنياً أو مالياً، ويجب أن يُبنى تصميم GCC على هذا الافتراض صراحةً.

**حدود أثر tokens في التطبيق:** يستهلك `ThemeStyle` سبعة مفاتيح فقط: `colors.primary`، `colors.accent`، `colors.surface`، `colors.text`، `colors.muted_text`، `colors.border`، و`layout.border_radius` (`lavkooapp:lib/features/dynamic_theme/engine/theme_style.dart:40-62`). مجموعتا `typography` و`branding` (الشعار، شعار الجوال…) تُحلَّلان ولا يقرؤهما أي renderer. الهوية الثابتة للتطبيق مُصرَّفة: `light()` و`dark()` يتجاهلان اللون القادم من الإعدادات ويثبّتان `BrandColors.primary` (`lavkooapp:lib/theme/light_theme.dart:7-9`، `lavkooapp:lib/theme/dark_theme.dart:6-12`، `lavkooapp:lib/main.dart:262-270`)؛ والاستثناء موضعان يقرآن لون الإعدادات `selectedPrimaryColor` مباشرة (`lavkooapp:lib/features/more/widgets/profile_info_section_widget.dart:85`، `lavkooapp:lib/features/dashboard/screens/menu_screen.dart:215`) — فحصتُ 4 ملفات تحمل هذا الاسم من أصل 4، وعدة عناصر داخل الأقسام الديناميكية تستخدم الثوابت مباشرة (مثل رابط «عرض الكل»: `lavkooapp:lib/features/dynamic_theme/widgets/theme_view_all.dart:81-90`).

### 2.11 الاختبارات والسلالة

- اختبارات التطبيق الستة تغطي: المحلِّل، قرار المزامنة، عقد القدرات، العزل، مصفوفة العرض (هاتف، RTL)، وحلّ الإجراءات. لا يوجد CI في `lavkooapp` (بحث ب11)؛ فهذه الاختبارات لا تعمل تلقائياً في المستودع الحالي.
- لا اختبار واحداً في الخلفية لمحرك التسليم (بحث ب4)، مع أن التعليقات تشير إلى اختبارات (`lavkoo:app/Services/Theme/ThemeDelivery.php:179-181`، `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:43-44`).
- **مقارنة مع `ksaapp98`:** 21 من 24 ملفاً في مجلد الثيم متطابقة، وكذلك ملفات الاختبار الستة. الفروق في ثلاثة renderers: تغيير لون، وإصلاحان لفيض المحتوى خارج الحدود (overflow) موجودان في القديم فقط — تسمية بطاقة الإحصاء (`ksaapp98:lib/features/dynamic_theme/renderers/content_section_renderer.dart:798-819` مقابل `lavkooapp:lib/features/dynamic_theme/renderers/content_section_renderer.dart:799-811`) وتسمية عدّاد الشحن (`ksaapp98:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:703-719` مقابل `lavkooapp:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:701-709`). الإصلاحان دخلا القديم في الالتزام `ksaapp98@1a34a4d` (بتاريخ 2026-11-11)، ولا يتضمنهما الالتزام الأول للمستودع الحالي `lavkooapp@0df8b09` (التاريخ نفسه)؛ أي أن لقطة `lavkooapp` أُخذت من شجرة تسبق ذلك الالتزام. تعليق القديم يقول إن مصفوفة العرض هي التي كشفت الخللين؛ فيُرجَّح (INFERRED) أن الاختبار نفسه يفشل في `lavkooapp` لقسمَي `stats_bar` و`shipping_cutoff`. كذلك أضاف الحالي سطر `debugPrint` يطبع إعدادات كل بطاقة إحصاء عند كل بناء (`lavkooapp:lib/features/dynamic_theme/renderers/content_section_renderer.dart:743`).
- الملف `test/release_version_test.dart` الذي يستشهد به تعليق `appVersion` (`lavkooapp:lib/utill/app_constants.dart:27-28`) غير موجود في `lavkooapp` وموجود في القديم (`ksaapp98:test/release_version_test.dart`)، ومعه `ksaapp98:.github/workflows/flutter.yml`.

### 2.12 عدسة GCC: تتبّع سيناريو «تبديل المتجر» على الكود الحالي (تحليل، لا تنفيذ)

لا يوجد اليوم تبديل متجر في التطبيق (بحث ب3). التتبّع التالي يجيب عن سؤال واحد: لو أُضيف مصدر لسياق المتجر (رأس أو تفضيل) **دون** تعديل طبقات الثيم، ماذا تفعل المسارات القائمة؟ كل صف مبني على كود مُتحقَّق منه، والنتيجة استنتاج (INFERRED).

| الحالة | ما يفعله الكود القائم | النتيجة المتوقعة |
|---|---|---|
| إقلاع بارد بعد التبديل | `sync` يقرأ المدخلين `home` و`header` من المخزَّن ويعرضهما قبل أي اتصال (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:173-182`)؛ المفتاح لا يعرف المتجر (`lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:31`) | تظهر رئيسية المتجر السابق فوراً |
| فحص الإصدار بعد التبديل | يُجاب من قرص `DioClient` إن كان عمر المدخل أقل من 400 ثانية، ومن الذاكرة طوال الجلسة، بمفتاح «المسار + `#lang`» (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:189-215`) | جواب المتجر السابق ⇒ لا تنزيل |
| وصول جواب المتجر الجديد | `isNewerThan` يقارن `revision` ثم `content_stamp` فقط (`lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:469-481`) | تنزيل صحيح، إلا إذا تطابق الزوج لثيمين منسوخين (E17-F04) |
| تبديل داخل الجلسة | `_payload` و`_pages[slug]` و`_sectionData[uuid]` تبقى في الذاكرة (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:49-77`)؛ لا مُحفِّز يعرف التبديل | يبقى محتوى المتجر السابق حتى سحب يدوي؛ والصفحة المركّبة ذات الـslug نفسه تُعرض من الذاكرة |
| سكك المنتجات والتصنيفات | تُجلب من مسارات مدرجة في `_cacheableEndpoints` (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-140`) | منتجات وأسعار المتجر السابق من المخزَّن |
| انقطاع الاتصال بعد التبديل | فشل الفحص يعيد `ThemeRevision.none` فيبقى المخزَّن (`lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:75-81`) | رئيسية المتجر السابق بلا أي إشارة للمستخدم |
| الرئيسية البديلة | بياناتها من مخازن `<uri>#<lang>` (`lavkooapp:lib/data/reposotories/data_sync_repo.dart:36-39`) | بانرات وتصنيفات المتجر السابق |
| بناء مثبَّت قديم | لا يرسل متجراً، ولا يمكن تعديل مفاتيحه | يحصل على ما يقرره الخادم لطلب «بلا متجر» (E17-F05) |

الخلاصة: قاعدة «لا تُفرغ الصفحة أبداً واعرض المخزَّن أولاً» التي بُني عليها الـcontroller (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:8-21`) صحيحة داخل متجر واحد، وتصبح مصدر التسرّب عند تعدّد المتاجر. معالجة اللغة القائمة تصلح نقطة انطلاق لكنها لا تكفي كما هي: عند تغيير اللغة تُمسح المخازن والصفحات المركّبة ويُعاد الجلب قسراً، لكن `_payload` و`_header` يبقيان على الشاشة إلى أن يصل الجواب، ويبقيان إن فشل الطلب (`lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:359-373`، `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:240-244`). هذا مقبول للّغة وغير مقبول بين المتاجر؛ فتبديل المتجر يجب أن يُفرغ الحالة أولاً ثم يعيد الجلب.

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الموضع | الحالة |
|---|---|---|---|
| A1 | ثيم نشط واحد للنظام كله (`themes.is_active`)، ونسخة منشورة واحدة له؛ `activate()` يطفئ ما عداه | `lavkoo:app/Models/Theme.php:10-13`، `lavkoo:app/Services/Theme/ThemeManager.php:80-105`، `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:83`، `lavkoo:app/Services/Theme/ThemeDelivery.php:715-726` | VERIFIED |
| A2 | مفاتيح التخزين المؤقت في الخادم عامة: `theme_delivery_revision` و`theme_delivery_<page>_<fingerprint>`؛ البصمة تضم الإصدار والمنصة والجهاز والجمهور والشرائح واللغة والقدرات — ولا متجر | `lavkoo:app/Services/Theme/ThemeDelivery.php:128-133`، `lavkoo:app/Services/Theme/ThemeDelivery.php:650-674` | VERIFIED |
| A3 | `revision` يتزايد **لكل `theme_id`**، و`checksum` النسخة يُحسب من البنية والإعدادات فقط | `lavkoo:app/Services/Theme/ThemeDelivery.php:186-193`، `lavkoo:app/Services/Theme/ThemeDelivery.php:680-697` | VERIFIED |
| A4 | عنوان أساسي واحد مُصرَّف في التطبيق | `lavkooapp:lib/utill/app_constants.dart:39` | VERIFIED |
| A5 | لا سياق متجر في طلبات التطبيق؛ والمفتاح `country_code` في التفضيلات يعني دولة **اللغة** (SA للعربية، US للإنجليزية) | `lavkooapp:lib/utill/app_constants.dart:338-349`، `lavkooapp:lib/localization/controllers/localization_controller.dart:78-82` | VERIFIED |
| A6 | مفاتيح التخزين المحلي بلا بُعد متجر (الجدول 2.5) | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:31`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:168`، `lavkooapp:lib/data/reposotories/data_sync_repo.dart:36-39` | VERIFIED |
| A7 | موضوع إشعار واحد لكل تطبيقات العملاء، ومفتاح FCM واحد | `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27`، `lavkoo:app/Traits/PushNotificationTrait.php:484-499` | VERIFIED |
| A8 | مصادر بيانات الأقسام تسمّي مسارات كتالوج عامة مع `guest_id=1` بلا معامل متجر | `lavkoo:app/Services/Theme/ThemeSourceMap.php:117-140`، `lavkoo:app/Services/Theme/ThemeSourceMap.php:247-263` | VERIFIED |
| A9 | محتوى الثيم يحمل مراجع كيانات: `category_id`، `brand_id`، `product_ids`، `shop_id`، `shop_ids`، `deal_id`، `banner_id`، ومعرّف مجموعة؛ ونطاقات سعرية رقمية `min`/`max` بلا عملة | `lavkoo:app/Services/Theme/ThemeSourceMap.php:49-60`، `lavkoo:app/Services/Theme/ThemeSourceMap.php:136`، `lavkoo:app/Services/Theme/ThemeSourceMap.php:163-171`، `lavkoo:app/Services/Theme/ThemeSourceMap.php:226-245`، `lavkoo:app/Services/Theme/SectionDataResolver.php:478-486`، `lavkoo:app/Services/Theme/SectionRegistry.php:968-975` | VERIFIED |
| A10 | بانرات `store_banner` تُحلّ حيّاً من جدول `banners` العام، مفلتَرةً بـ`theme_root_path()` مع رجوع إلى كل البانرات المنشورة من النوع نفسه إن لم يوجد مطابق للثيم | `lavkoo:app/Services/Theme/SectionDataResolver.php:372-396` | VERIFIED |
| A11 | أرقام `stats_bar` الحيّة عدّادات عامة (منتجات، علامات، عملاء، طلبات) | `lavkoo:app/Services/Theme/ThemeDelivery.php:384-393`، `lavkoo:app/Services/Theme/SectionDataResolver.php:859-868` | VERIFIED |
| A12 | الزمن: نهاية «صفقة اليوم» تُحسب بنهاية يوم الخادم؛ وعدّاد `shipping_cutoff` يُحسب بساعة **الجهاز** المحلية | `lavkoo:app/Services/Theme/ThemeSourceMap.php:70-71`، `lavkooapp:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:643-656` | VERIFIED |
| A13 | الروابط تُصنَّف بأول مقطع من المسار ومقابل نطاق واحد؛ وفلتر روابط Android يعدّد بادئات بلا بادئة دولة | `lavkoo:app/Services/Theme/ActionResolver.php:89-125`، `lavkooapp:android/app/src/main/AndroidManifest.xml:131-141` | VERIFIED |
| A14 | رمز المعاينة يسمّي معرّف نسخة فقط | `lavkoo:app/Services/Theme/ThemePreviewToken.php:33-39` | VERIFIED |
| A15 | الهوية: اسم التطبيق ثابت `LAVKOO` ولوحة الألوان مُصرَّفة؛ ترويسة الرئيسية تعرض `companyName` من الإعدادات العامة | `lavkooapp:lib/utill/app_constants.dart:7`، `lavkooapp:lib/theme/custom_theme_colors.dart:8-21`، `lavkooapp:lib/features/home/screens/home_screens.dart:351-357` | VERIFIED |
| A16 | رموز اللغات في الخلفية تشبه رموز دول: مجلدات `sa` و`ae` ضمن `resources/lang`، ومفاتيح التخصيص اللغوي في إعدادات الثيم تُبنى بالصيغة `<key>_<code>` لكل لغة مفعّلة | `lavkoo:resources/lang/sa/new-messages.php`، `lavkoo:resources/lang/ae/new-messages.php`، `lavkoo:app/Services/Theme/SectionRegistry.php:1238-1253`، `lavkoo:app/Services/Theme/LocalisedSettings.php:41-62` | VERIFIED للكود؛ رموز اللغات الإنتاجية UNKNOWN، مع قرينة في الكود على أن رمز العربية في المتجر هو `sy` (تعليق الوسيط `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:36`، وتحويل `ar` إلى `sy` في التطبيق `lavkooapp:lib/localization/controllers/localization_controller.dart:39`) |
| A17 | (أضافه التحقق المستقل) الحملات التراكبية والتجارب عامة لا تعرف ثيماً ولا متجراً: الحملة تُختار باسم الصفحة فقط ومفتاح مخزَّنها `commerce_campaign_overrides_<page>`، وحقول النموذج القابلة للتعبئة لا تضم `theme_id` (بحث `experience_campaigns` و`experience_experiments` في `database` و`app`: لا ترحيل لهما في المستودع، فالمخطط UNKNOWN)؛ والتجربة تستهدف `section_uuid`؛ وبصمة الحملات الحيّة تُطوى في مُصادِق المزامنة لكل العملاء | `lavkoo:app/Services/Commerce/CampaignResolver.php:43-47`، `lavkoo:app/Services/Commerce/CampaignResolver.php:72-78`، `lavkoo:app/Services/Commerce/CampaignResolver.php:121-125`، `lavkoo:app/Models/ExperienceCampaign.php:33`، `lavkoo:app/Services/Commerce/ExperimentResolver.php:36-44`، `lavkoo:app/Services/Commerce/ExperimentResolver.php:83-87`، `lavkoo:app/Services/Theme/ThemeDelivery.php:630-639` | VERIFIED للكود؛ تفعيلها في الإنتاج UNKNOWN (يتوقف على وجود جدول `experience_campaigns` وعلى `commerce.enabled`: `lavkoo:app/Services/Commerce/CampaignResolver.php:204-215`) |
| A18 | (أضافه التحقق المستقل) النشر المجدول عام: الأمر `theme:publish-due` يعمل كل خمس دقائق وينشر كل مسودة حان وقتها لأي ثيم (نشطاً كان أو غير نشط) بمقارنة `publish_at` بساعة الخادم؛ و`publish()` يمسح مخازن التسليم العامة ويرسل إشعار النشر العام أياً كان الثيم | `lavkoo:app/Console/ScheduleDefinition.php:53`، `lavkoo:app/Console/Commands/ThemePublishDue.php:43`، `lavkoo:app/Console/Commands/ThemePublishDue.php:78`، `lavkoo:app/Models/ThemeVersion.php:57-62`، `lavkoo:app/Services/Theme/ThemeManager.php:148-152` | VERIFIED |

حمولة الثيم نفسها لا تحمل أسعاراً؛ المبالغ تصل عبر مسارات الكتالوج التي يسمّيها `source` (خارج هذا النطاق)، باستثناء نطاقات `price_band` وقيم الكوبونات التي يعرضها `coupon_strip` من مسار الكوبونات.

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم في هذا النطاق | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| GCC-07 — ثيم مستقل لكل دولة ومكتبة مكوّنات مشتركة | مكتبة المكوّنات مشتركة فعلاً (سجل واحد في الخادم، وسجل renderers واحد في التطبيق)؛ دورة مسودة/نشر/أرشفة لكل ثيم؛ `revision` لكل ثيم؛ ويمكن وجود عدة ثيمات في القاعدة (واحد نشط) | اختيار الثيم عام (`is_active`)؛ لا بُعد متجر في الطلب أو الجواب أو مفاتيح التخزين في الطرفين | `lavkoo:app/Services/Theme/ThemeDelivery.php:715-726`، `lavkoo:app/Services/Theme/ThemeManager.php:80-105` |
| GCC-07 — «لا نشر عرضي عابر للدول» | النشر ذرّي ومُدقَّق ويمسح مخازن الخادم | إشعار النشر عام؛ ومخزَّن التطبيق لا يعرف لأي متجر تعود الحمولة | `lavkoo:app/Services/Theme/ThemeManager.php:108-168`، `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:177-196` |
| GCC-07 — نسخ اختياري للثيم | آلية قائمة تصلح أساساً: التصدير/الاستيراد ينشئ **ثيماً جديداً غير نشط** بمسودة واحدة ولا يكتب فوق ثيم قائم؛ و`createDraftFrom` ينسخ نسخة داخل الثيم نفسه | الاستيراد يحافظ عمداً على `uuid` الأقسام ولا يعيد ربط المراجع ولا يتحقق منها: المحتوى المنسوخ يحمل معرّفات كيانات ونطاقات سعرية خاصة بمتجر المصدر (A9)، وثيمان مستوردان من الملف نفسه يتطابقان بنيةً و`uuid` (E17-F04). ويحمل الاستيراد كذلك `banner_id` لأنه حقل في مخطط البلاطات، فتشير النسختان إلى صف `banners` نفسه، وتعديل بلاطة في إحداهما يُدفع إلى ذلك الصف المشترك (`lavkoo:app/Services/Theme/SectionRegistry.php:810`، `lavkoo:app/Services/Theme/ThemeBannerLink.php:79-82`، `lavkoo:app/Services/Theme/ThemeBannerLink.php:140-176`) | `lavkoo:app/Services/Theme/ThemePortabilityService.php:55-69`، `lavkoo:app/Services/Theme/ThemePortabilityService.php:148-176`، `lavkoo:app/Services/Theme/ThemePortabilityService.php:199-218`، `lavkoo:app/Services/Theme/ThemeManager.php:171-210` |
| GCC-09 — تخصيص منفصل للويب والتطبيق | استهداف القناة/المنصة (مطبَّق في مسارَي الويب والتطبيق، فيمكن تكرار القسم بنسخة لكل قناة)، تخصيصات نقاط التوقف، صفحات خاصة بقناة، حجب القدرات — أي أن القرار محقَّق في معظمه بالآليات القائمة | لا قائمة ترتيب مستقلة ولا نشر مستقل لكل قناة؛ الصفحات النظامية مشتركة دائماً؛ تطبيق البائعين لا يرسم شيئاً | القسم 2.10 |
| GCC-17 — حملات مركزية وقواعد محلية (أضافه التحقق المستقل) | محرك حملات تراكبية وتجارب قائم يُدمج في حمولة الثيم عبر المسار نفسه | الحملة والتجربة بلا بُعد ثيم أو سوق: حملة على صفحة `home` تُدمج في رئيسية كل ثيم، وتجربة على `section_uuid` تصيب كل نسخة مستوردة تحمل الـ`uuid` نفسه؛ لا «اختيار أسواق» للحملة | الافتراض A17 في القسم 3 |
| GCC-10 — هوية مشتركة مع تخصيصات لكل دولة | tokens الخادم تصل التطبيق | 7 مفاتيح فقط تُستهلك، و`branding` لا يُستخدم؛ الهوية الثابتة مُصرَّفة فلا يغيّرها الخادم | `lavkooapp:lib/features/dynamic_theme/engine/theme_style.dart:40-62`، `lavkooapp:lib/theme/light_theme.dart:7-9` |
| GCC-19 — السعودية المتجر الافتراضي الأولي | النقاط العامة بلا معاملات إلزامية؛ افتراضات `ViewerContext` تجعل البناءات المثبَّتة تعمل دون أي إضافة | «الافتراضي» قابل للتغيير لاحقاً بقرار إداري، والبناءات القديمة لا تستطيع إرسال متجر؛ يلزم تثبيت متجر للعملاء القدامى منفصل عن «الافتراضي الحالي» | `lavkoo:app/Services/Theme/ViewerContext.php:69-105` |
| الأساس: نطاق واحد بمسارات `/sa` `/ae` … | الخادم يحلّل الروابط مركزياً فيمكن إصلاحها في موضع واحد | التصنيف بأول مقطع يجعل كل رابط داخلي ببادئة دولة من النوع `url` فيخرج للمتصفح؛ وبادئات روابط Android لا تشمل بادئة دولة | `lavkoo:app/Services/Theme/ActionResolver.php:89-125`، `lavkooapp:android/app/src/main/AndroidManifest.xml:131-141` |
| القيد المؤقت: مخازن التطبيق المحلية والإشعارات مقيّدة بالسوق («تحقّق ولا تفترض») | سابقة اللغة: مسح شامل + epoch + اختبارات | **غير منفَّذ**: لا مخزن محلياً واحداً يحمل بُعد المتجر؛ الإشعار عام | القسم 2.5 |
| GCC-13 — هوية عميل واحدة وسلال منفصلة | الثيم يرسم بطاقات منتج بزر «أضف للسلة» بحسب إعداد `add_to_cart` | بيانات السكك تأتي من مسارات كتالوج عامة ومخزَّنة بلا بُعد متجر | `lavkooapp:lib/features/dynamic_theme/renderers/product_section_renderer.dart:173`، `lavkoo:app/Services/Theme/ThemeSourceMap.php:117-140` |
| GCC-12 — إيقاف متجر | الرئيسية البديلة وآلية 404 للصفحات يعطيان سلوك تراجع جاهزاً | لا تمييز في العقد بين «لا ثيم منشور» و«متجر موقوف»؛ ووضع الصيانة في التطبيق عام (موضوع إشعار واحد وإعداد واحد) | `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:209-229`، `lavkooapp:lib/utill/app_constants.dart:324` |
| الضابط 1 — «لا إعادة كتابة للثيم الديناميكي في Flutter» | البنية قابلة للتمديد دون إعادة كتابة: سياق المشاهد كائن واحد، والبصمة دالة واحدة، ومفتاح المخزَّن دالة واحدة | — | `lavkoo:app/Services/Theme/ViewerContext.php:38-60`، `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:31` |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أدنى إضافة موصى بها (توثيقاً فقط) |
|---|---|---|---|
| محلِّل المخطط الكلّي `theme_schema.dart` | Preserve + Extend | متسامح مع الحقول المجهولة، فإضافة حقل لا تكسر البناءات المثبَّتة | حقل اختياري يعرّف المتجر في الحمولة وفي جواب الإصدار، يتحقق منه العميل عند القراءة من المخزَّن |
| سجل الـrenderers والتفاوض بالقدرات (`X-UI-*`) | Preserve | هو مكتبة المكوّنات المشتركة التي يطلبها GCC-07، وعقده مثبَّت باختبار | لا تغيير؛ تسوية `vendor_showcase` بين الطرفين |
| عزل الأقسام (try/catch + `ErrorWidget.builder`) والرئيسية البديلة | Preserve | يضمن ألا تُفرغ الصفحةَ مشكلةٌ في قسم أو في الشبكة | — |
| خوارزمية المزامنة (`revision` + `content_stamp` + ETag/304) | Extend | سليمة لمتجر واحد؛ مُصادِقها لا يميّز المتاجر ولا الجمهور ولا القدرات | إدخال هوية المتجر في `syncStamp` وفي قرار `isNewerThan`؛ معاملة «تغيّر المتجر» كتغيّر اللغة |
| `DynamicThemeController.onLanguageChanged` ونمط الـepoch | Extend | نموذج جاهز ومُختبَر لتغيّر بُعد لا يحرّك `revision` | نظير لتبديل المتجر يُفرغ `_payload` و`_header` و`_pages` و`_sectionData` فوراً ولا يعرض القديم أثناء إعادة الجلب |
| مفتاح مخزَّن الثيم `_cacheKey` | Extend | دالة واحدة | إضافة بُعد المتجر، وترحيل المفتاح القديم كمدخل للمتجر السعودي |
| ذاكرة `DioClient` العامة (`_cacheableEndpoints`) | Refactor | تُبطل فحص الإصدار داخل الجلسة، ومفاتيحها بلا متجر، وذاكرتها بلا مدة صلاحية | إخراج `/theme/version` من القائمة أو إعطاء الذاكرة مدة؛ إدخال المتجر في المفتاح؛ مسح عند التبديل |
| `ViewerContext` و`ThemeDelivery::fingerprint` و`revision()` | Extend | نقطة واحدة لكل منها | حقل متجر يُحلّ مرة واحدة من سياق الطلب؛ يدخل البصمة ومفتاح `revision` وترويسة `Vary` |
| اختيار الثيم `Theme::is_active` | Refactor (بحدود ضيقة) | يُقرأ في أربعة مواضع على الأقل | ربط «متجر ← ثيم نشط» مع إبقاء السلوك الحالي للمتجر السعودي أثناء الترحيل |
| `ActionResolver` والعقد المطبوع للإجراءات | Preserve + Extend | مركزي ومغلق القائمة | نزع بادئة المتجر قبل التصنيف، وإرفاق المتجر المقصود بالإجراء عند الروابط العابرة |
| `ThemeSyncBeacon` | Extend | مُسرِّع غير حرج بنص الكود | إضافة المتجر إلى بيانات الرسالة (أو موضوع لكل متجر) |
| `ThemePreviewToken` ومسار `preview` | Extend + New | الرمز سليم التصميم لمتجر واحد | ربط الرمز بالمتجر والقناة؛ ووضع معاينة فعلي في التطبيق (غير موجود) لا يكتب في المخزَّن |
| استهداف القنوات وصفحات القناة | Preserve + Extend | يغطي جزءاً من GCC-09 | قرار مالك: هل يكفي الاستهداف أم يلزم ترتيب/نشر مستقل لكل قناة |
| tokens والهوية في التطبيق | Extend | 7 مفاتيح فقط | استهلاك `branding` والألوان في الهوية الثابتة إن أُريد تخصيص لكل دولة دون إصدار جديد |
| نسخ الثيم بين الدول (`ThemePortabilityService` تصدير/استيراد) | Preserve + Extend | ينشئ ثيماً جديداً غير نشط بمسودة، وهو السلوك الذي يطلبه GCC-07 («النسخ ينتج مسودة») | ربط الثيم المستورد بمتجر الوجهة؛ خطوة إعادة ربط/تحقق للمراجع (A9)؛ وإدخال هوية المتجر في مُصادِق المزامنة لأن `uuid` والبنية يُحفظان |
| متجر مثبَّت للعملاء القدامى | New | البناءات المثبَّتة لا ترسل متجراً | إعداد خادمي مستقل عن «المتجر الافتراضي» |
| اختبارات عقد في الخلفية + CI للتطبيق | New | لا شيء منهما في المستودعين الحاليين | اختبارات عزل المتاجر: مفاتيح التخزين، `revision`، الحمولة، ومخزَّن العميل بعد التبديل |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E17-F01 | مخازن التطبيق المحلية كلها بلا بُعد متجر: حمولة الثيم على القرص، حالة الـcontroller في الذاكرة (الصفحات بمفتاح slug وبيانات الأقسام بمفتاح uuid)، وذاكرة/قرص `DioClient` لجواب الإصدار وبيانات سكك المنتجات. والتطبيق يعرض المخزَّن **قبل** أي اتصال | P0 | VERIFIED (الكود)؛ الأثر العابر للمتاجر INFERRED لعدم وجود تبديل متجر بعد | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:31`، `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:173-182`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-172`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:189-192` | بعد تبديل المتجر يُعرض ثيم دولة أخرى وسكك منتجاتها (بأسعارها وعملتها) من المخزَّن؛ تسرّب عرضي بين المتاجر على الجهاز | بُعد المتجر في كل مفتاح + مسح شامل عند التبديل + إفراغ فوري للحالة + تحقق من متجر الحمولة عند القراءة؛ اختبار يمنع عرض مخزَّن متجر آخر |
| E17-F02 | عقد الثيم بلا بُعد متجر في الطرفين: اختيار عام للثيم النشط، مفاتيح تخزين عامة، جواب إصدار واحد، وطلب تطبيق بلا متجر | P1 | VERIFIED | `lavkoo:app/Services/Theme/ThemeDelivery.php:64-105`، `lavkoo:app/Services/Theme/ThemeDelivery.php:650-674`، `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:83`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73` | GCC-07 غير قابل للتحقيق دون تمديد العقد | تمديد `ViewerContext` والبصمة ومفتاح `revision` و`Vary`؛ ربط متجر ← ثيم؛ رأس متجر صريح في التطبيق |
| E17-F03 | فحص الإصدار يُجاب من ذاكرة `DioClient` العامة: بلا مدة في الذاكرة، و400 ثانية على القرص، وبمفتاح لا يضم إلا اللغة | P1 | VERIFIED (قراءة ثابتة؛ لم يُختبر تشغيلياً) | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-140`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:189-192`، `lavkoo:routes/rest_api/v1/api.php:58-60`، `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:64-82` | مزامنة الـresume وإشعار النشر لا تلتقطان النشر داخل الجلسة؛ ومع `revision` لكل متجر سيُعاد جواب متجر آخر | إخراج المسار من القائمة أو تقييد الذاكرة بمدة؛ شرط مسبق لأي عمل GCC على الثيم في التطبيق |
| E17-F04 | مُصادِق المزامنة محتوائي فقط ومقيَّد بـ`theme_id`: `revision` يبدأ من 1 لكل ثيم، و`checksum` يُحسب من الإعدادات والبنية ومنها `uuid`، والاستيراد يحافظ على `uuid`. فثيمات عدة دول مستوردة من ملف واحد ومنشورة أول مرة تحمل الزوج (`revision = 1`، `checksum`) نفسه — وهو بالضبط سيناريو تهيئة المتاجر الجديدة من قالب واحد | P1 | INFERRED — يستند إلى أربعة مواضع مُتحقَّق منها؛ يتوقف أيضاً على مخطط الجدول (قيد فريد على `uuid`؟ UNKNOWN) | `lavkoo:app/Services/Theme/ThemeDelivery.php:186-193`، `lavkoo:app/Services/Theme/ThemeDelivery.php:680-697`، `lavkoo:app/Services/Theme/ThemePortabilityService.php:55-69`، `lavkoo:app/Services/Theme/ThemePortabilityService.php:199-218`، `lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:469-481` | `isNewerThan` يعيد `false` فيُبقي التطبيق حمولة المتجر السابق بمحتواها المحلول (بانرات، مصادر)؛ وبيانات الأقسام في الذاكرة مفتاحها `uuid` المشترك؛ ويشترك الاستيراد في صفوف البانرات أيضاً (E17-F23) | إدخال هوية المتجر في `syncStamp`، ومقارنة متجر الحمولة صراحةً في العميل |
| E17-F05 | البناءات المثبَّتة لا ترسل متجراً ولا يمكن تغيير مفاتيح مخزَّنها بأثر رجعي | P1 | VERIFIED (غياب المعامل)؛ السياسة المطلوبة قرار | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:96`، `lavkoo:app/Services/Theme/ViewerContext.php:69-105` | إن رُبط «بلا متجر» بـ«المتجر الافتراضي الحالي» فتغيير الافتراضي (GCC-19) ينقل البناءات القديمة صامتاً إلى ثيم وكتالوج دولة أخرى | متجر مثبَّت للعملاء القدامى (السعودية) منفصل عن الافتراضي؛ واستخدام بوابة التحديث الإجباري عند الحاجة |
| E17-F06 | دلالة رأس `lang` متداخلة مع رموز الدول: الخلفية تحوّل `sa` و`us` إلى لغتين؛ مجلدات لغات باسم `sa` و`ae`؛ وتفضيل `country_code` في التطبيق يعني دولة اللغة؛ ومفاتيح التخصيص اللغوي في الثيم لاحقة `_<code>` | P1 | VERIFIED | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29`، `lavkoo:app/Services/Theme/SectionRegistry.php:1238-1253`، `lavkooapp:lib/localization/controllers/localization_controller.dart:78-82` | خطر استنتاج المتجر من اللغة أو إعادة استخدام مفتاح/رأس قائم؛ وتصادم لاحقة لغة مع رمز دولة في إعدادات الثيم | رأس ومفتاح تفضيل جديدان صريحان للمتجر؛ عدم استنتاج المتجر من `lang` أبداً؛ إبقاء التحويل القديم |
| E17-F07 | عقد الإجراءات مبني على شكل المسار بلا بادئة دولة؛ والروابط تُخزَّن عناوين مطلقة بنطاق ومسار لحظة الحفظ؛ وفلتر روابط Android بلا بادئات دول؛ والروابط النسبية من النوع `url` لا تفتح شيئاً | P1 | VERIFIED (الكود)؛ الأثر مع `/sa` INFERRED | `lavkoo:app/Services/Theme/ActionResolver.php:89-125`، `lavkoo:app/Services/Theme/LinkComposer.php:104-108`، `lavkooapp:android/app/src/main/AndroidManifest.xml:131-141`، `lavkooapp:lib/features/dynamic_theme/engine/theme_action_resolver.dart:212-224` | مع بادئات الدول تُصنَّف الروابط الداخلية `url` فتخرج كل نقرة على بانر أو «عرض الكل» للمتصفح بدل الشاشة المحلية؛ والروابط المخزَّنة سابقاً بلا بادئة تحتاج قاعدة تفسير؛ وروابط عابرة للمتاجر بلا دلالة | نزع البادئة في `ActionResolver`؛ قاعدة تفسير للروابط المخزَّنة (بلا بادئة = متجر الثيم نفسه)؛ تعريف دلالة الرابط العابر للمتجر؛ تحديث بادئات روابط التطبيق |
| E17-F08 | المعاينة: التطبيق بلا وضع معاينة؛ الرمز غير مربوط بمتجر أو قناة؛ رابط الإدارة يستهدف جذر الويب الذي يلتقطه التطبيق؛ والمخزَّن لا يميّز المعاينة | P1 | VERIFIED (الغياب)؛ الالتقاط INFERRED | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:195-201`، `lavkoo:app/Services/Theme/ThemePreviewToken.php:33-39`، `lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:901-930`، `lavkooapp:android/app/src/main/AndroidManifest.xml:122-142` | «معاينة مستقلة لكل دولة على الهاتف» غير متاحة للتطبيق | تصميم معاينة التطبيق: رمز مربوط بالمتجر والقناة، مسار لا يكتب في المخزَّن، وإشارة مرئية |
| E17-F09 | نسخ الثيم بين الدول سينقل مراجع كيانات ونطاقات سعرية خاصة بمتجر المصدر؛ الاستيراد يطبّع الإعدادات على المخطط فقط ولا يتحقق من وجود الكيانات أو من صفوف البانرات المرتبطة (E17-F23) | P1 | VERIFIED (وجود المراجع وغياب إعادة الربط)؛ الأثر INFERRED | الافتراض A9 في القسم 3؛ `lavkoo:app/Services/Theme/ThemePortabilityService.php:85-140`، `lavkoo:app/Services/Theme/ThemePortabilityService.php:199-218` | بعد النسخ يطلب التطبيق منتجات/متاجر/بانرات بمعرّفات دولة أخرى أو يعرض نطاقات سعرية بعملة خاطئة (وخانات عشرية ثلاث للدينار والريال العُماني) | خطوة إعادة ربط/تحقق إلزامية عند النسخ؛ يستفيد من فحص الجاهزية القائم قبل النشر |
| E17-F10 | تخصيص الويب/التطبيق اليوم = قائمة واحدة مرتّبة + استهداف؛ لا ترتيب ولا نشر مستقل لكل قناة | P1 | VERIFIED | القسم 2.10 | تحقيق GCC-09 جزئي؛ نشر واحد يحرّك السطحين | قرار مالك على مستوى الاستقلال المطلوب، ثم تمديد الصفحات لكل قناة إن لزم |
| E17-F11 | إشعار النشر عام (موضوع واحد، مشروع FCM واحد)، والتطبيق يتجاهل `revision` المرسَل، ومعالجته في الواجهة فقط | P2 | VERIFIED | `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27-70`، `lavkooapp:lib/push_notification/notification_helper.dart:85-94` | نشر ثيم دولة يوقظ تطبيقات كل الدول (فحص إصدار فقط)؛ غير مقيَّد بالسوق | إضافة المتجر لبيانات الرسالة وتجاهل ما لا يخص متجر الجهاز |
| E17-F12 | هوية التطبيق مُصرَّفة واستهلاك tokens محدود بسبعة مفاتيح | P2 | VERIFIED | `lavkooapp:lib/theme/light_theme.dart:7-9`، `lavkooapp:lib/features/dynamic_theme/engine/theme_style.dart:40-62` | تخصيصات الهوية لكل دولة (GCC-10) لا تظهر في هيكل التطبيق دون إصدار جديد | استهلاك `branding` والألوان في الهيكل إن طُلب |
| E17-F13 | الحمولة المخزنة لا تُبطَل عند تغيّر الجمهور (دخول/خروج) أو قدرات البناء (ترقية) | P2 | VERIFIED | `lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:469-481`، `lavkooapp:lib/features/more/widgets/logout_confirm_bottom_sheet_widget.dart:117-125` | أقسام موجَّهة لجمهور أو بناء سابق تبقى حتى النشر التالي | مزامنة قسرية عند تغيّر الجمهور وعند أول تشغيل بعد الترقية |
| E17-F14 | عدم تطابق في العقد: `vendor_showcase` (C04/C13)، إطارات البطاقة (C12)، بلاطات السعر (C15)، `footer` (C17)، وحقول إجراء مهمَلة (C14) | P2 | VERIFIED | القسم 2.9 | وظائف يراها التاجر في الويب ولا تعمل في التطبيق | تسوية الطرفين واختبار عقد مشترك |
| E17-F15 | اعتماد زمني: نهاية اليوم بتوقيت الخادم، وعدّاد الشحن بتوقيت الجهاز | P2 | VERIFIED | `lavkoo:app/Services/Theme/ThemeSourceMap.php:70-71`، `lavkooapp:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:643-656` | عدّادات خاطئة بفارق المنطقة الزمنية بين الدول | إرسال لحظة مطلقة لكل متجر كما في `deal_ends_at` |
| E17-F16 | ثغرات الاختبار والسلالة: لا اختبارات خلفية للتسليم؛ لا CI في `lavkooapp`؛ قوائم اختبار متقادمة؛ إصلاحان موجودان في القديم فقط؛ ملف اختبار مُشار إليه مفقود | P2 | VERIFIED (الغياب والفروق)؛ فشل المصفوفة INFERRED | القسم 2.11 | لا شبكة أمان آلية لتمديد العقد | اختبارات عقد في الخلفية وCI للتطبيق قبل أي تنفيذ |
| E17-F17 | نظافة الكود: حساب `$blocks` مكرَّر مرتين متتاليتين؛ مفتاح `home_index` يُنسى ولا يُكتب؛ `debugPrint` متروك؛ شاشة التشخيص غير مربوطة | P2 | VERIFIED | `lavkoo:app/Services/Theme/ThemeDelivery.php:352-381`، `lavkoo:app/Services/Theme/ThemeDelivery.php:167`، `lavkooapp:lib/features/dynamic_theme/renderers/content_section_renderer.dart:743`، `lavkooapp:lib/features/dynamic_theme/screens/dynamic_home_view.dart:218-267` | لا أثر وظيفي مباشر | تنظيف عند أول تعديل معتمد |
| E17-F18 | `X-App-Version` لا يمثل الإصدار الفعلي، ومحرك الثيم لا يستخدمه | INFO | VERIFIED | `lavkooapp:lib/utill/app_constants.dart:9-29`، `lavkooapp:pubspec.yaml:5` | لا يصلح أساساً لقواعد توافق الثيم لكل متجر | الاستمرار على التفاوض بالقدرات |
| E17-F19 | لا ترحيلات لجداول الثيم في المستودع | INFO | VERIFIED (البحث ب5)؛ المخطط الفعلي UNKNOWN | `lavkoo:app/Models/Theme.php:16-19`، `lavkoo:app/Models/ThemeSection.php:17-20` | لا يمكن إثبات الأعمدة والفهارس من الكود | يتحقق منه مدقق ثيم الويب/قاعدة البيانات |
| E17-F20 | تطبيق البائعين لا يستهلك عقد الثيم، والخادم يعيد لقناته قائمة فارغة | INFO | VERIFIED | `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:156-158` | لا أثر لثيم لكل دولة على تطبيق البائعين عبر هذا العقد | — |
| E17-F21 | ثلاثة مفاهيم «ثيم» متمايزة؛ و`active_theme` في الإعدادات ثابت `default` فيُستخدم `HomePage` دائماً، وثيم المنشئ يستبدل محتوى الرئيسية فقط | INFO | VERIFIED | `lavkoo:app/Utils/theme-helpers.php:16-19`، `lavkooapp:lib/features/dashboard/screens/dashboard_screen.dart:108-112`، `lavkooapp:lib/features/home/screens/home_screens.dart:546-551` | «ثيم لكل دولة» يخص ثيم المنشئ؛ هيكل التطبيق واختيار الشاشة خارج تحكمه | توثيق الفصل في التصميم؛ عدم ربط المتجر بـ`active_theme` |
| E17-F22 | التمديد الإضافي للعقد متوافق مع البناءات المثبَّتة: محلِّل كلّي يتجاهل الحقول المجهولة، نقاط عامة بلا معاملات إلزامية، وتفاوض بالقدرات مع مسار قديم | INFO | VERIFIED | `lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:494-531`، `lavkoo:app/Services/Theme/ViewerContext.php:69-105`، `lavkoo:app/Services/Theme/ViewerContext.php:179-182`، `lavkooapp:test/dynamic_theme_schema_test.dart:62-83` | إضافة حقل/رأس للمتجر لا تكسر أي بناء مثبَّت؛ الخطر في السياسة (E17-F05) لا في التوافق | الإبقاء على قاعدة «إضافات فقط» في العقد |
| E17-F23 | النسخ المستوردة تحمل `banner_id` لصفوف `banners` نفسها، لأن الاستيراد يعيد قيم البلاطة كما وردت دون إعادة ربط. وتعديل بلاطة في أي نسخة يُدفع إلى الصف المشترك متى كان من نوع Theme Banner، ويظهر أثره في النسخ الأخرى لأن البانر يتقدّم على الإعداد المحلي عند العرض | P1 | VERIFIED (مسار الكود)؛ الأثر عبر النسخ INFERRED | `lavkoo:app/Services/Theme/ThemeBannerLink.php:79-82`، `lavkoo:app/Services/Theme/ThemeBannerLink.php:121`، `lavkoo:app/Services/Theme/ThemeBannerLink.php:140-176`، `lavkoo:app/Services/Theme/ThemeBuilderService.php:381-399`، `lavkoo:app/Services/Theme/ThemePortabilityService.php:245-252`، `lavkoo:app/Services/Theme/SectionRegistry.php:810` | تحرير محتوى نسخة دولة يغيّر محتوى نسخة دولة أخرى دون علم مالكها، وهذا تعديل عابر للكيانات القانونية | عند الاستيراد: إنشاء صف بانر جديد لكل نسخة، أو حذف `banner_id` من الحمولة المستوردة مع إعادة ربط صريحة؛ وقصر الدفع إلى الصف المشترك على الثيم الذي أنشأه |
| E17-F24 | الحملات التراكبية والتجارب لا تعرف ثيماً ولا متجراً. الحملة تُختار باسم الصفحة وتُدمج في كل ثيم منشور لتلك الصفحة، ومفتاح تخزينها `commerce_campaign_overrides_<page>` لا يضم الثيم، والتجربة تُطابق `section_uuid` فتصيب كل نسخة تحمل الـuuid نفسه، وبصمة الحملات الحيّة تُطوى في مُصادِق المزامنة لكل العملاء. النموذج لا يضم `theme_id` في حقوله القابلة للتعبئة، ولا يوجد ترحيل للجدول في المستودع | P1 | VERIFIED (الكود)؛ التفعيل في الإنتاج UNKNOWN | `lavkoo:app/Services/Commerce/CampaignResolver.php:43-47`، `lavkoo:app/Services/Commerce/CampaignResolver.php:72-78`، `lavkoo:app/Services/Commerce/CampaignResolver.php:110-125`، `lavkoo:app/Services/Commerce/ExperimentResolver.php:36-44`، `lavkoo:app/Models/ExperienceCampaign.php:33`، `lavkoo:app/Services/Theme/ThemeDelivery.php:630-639` | حملة مصمَّمة لواجهة دولة تظهر في واجهات دول أخرى تملك الصفحة نفسها، وتتغيّر بصمة المزامنة لكل العملاء عند بدء الحملة أو انتهائها | ربط الحملة والتجربة بالمتجر والثيم صراحةً قبل أي إطلاق متعدد المتاجر، وإدخال هوية المتجر في مفتاح التخزين وفي `stamp`؛ وإلى حين ذلك تقتصر الحملات على متجر واحد |
| E17-F25 | النشر المجدول عام: الأمر `theme:publish-due` يعمل كل خمس دقائق وينشر كل مسودة حان موعدها لأي ثيم نشطاً كان أو غير نشط، و`publish()` يمسح المخازن العامة ويرسل إشعار النشر بالمراجعة الناتجة أياً كان الثيم. حمولة التطبيقات لا تتغيّر بذلك إن لم يكن الثيم نشطاً، لكن الإشعار والمسح يعمّان كل المتاجر | P2 (يصبح P1 مع تعدد المتاجر النشطة) | VERIFIED (الكود)؛ الأثر INFERRED | `lavkoo:app/Console/ScheduleDefinition.php:53`، `lavkoo:app/Console/Commands/ThemePublishDue.php:43`، `lavkoo:app/Console/Commands/ThemePublishDue.php:78`، `lavkoo:app/Models/ThemeVersion.php:57-62`، `lavkoo:app/Services/Theme/ThemeManager.php:147-152` | نشر مجدول لثيم غير نشط يوقظ كل التطبيقات ويفرغ مخازن الخادم دون تغيّر مرئي، وبتعدد المتاجر يصبح نشر متجر حدثاً عاماً لكل المتاجر | تقييد الجدولة والمسح والإشعار بالثيم أو المتجر النشط، مع إشعار موضوعي لكل متجر |

SEC-E17-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E17-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E17-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

## 7. أسئلة مفتوحة وعناصر UNKNOWN

| # | السؤال | ما يحسمه |
|---|---|---|
| U1 | هل يوجد ثيم منشور فعلاً في الإنتاج (أي هل المسار الديناميكي أم الرئيسية البديلة هو ما يراه العملاء)؟ وما قيمة `revision` الحالية؟ | قراءة جدولَي `themes` و`theme_versions` الإنتاجيين أو جواب `/api/v1/theme/version` الحي |
| U2 | المخطط الفعلي لجداول الثيم (أعمدة، فهارس، قيود فريدة) — وبالأخص: هل على `theme_sections.uuid` قيد فريد يمنع استيراد الملف نفسه لأكثر من ثيم؟ | مخطط قاعدة البيانات الإنتاجية أو ملفات الترحيل المفقودة |
| U3 | رموز اللغات المفعّلة في الإعداد `language` (التعليقات تذكر `sy` للعربية) وهل منها ما يطابق رمز دولة خليجية | قيمة `business_settings` الإنتاجية |
| U4 | قيمة `user_app_version_control` الحالية، وتوزّع إصدارات التطبيق المثبَّتة | الإعدادات الإنتاجية ولوحة المراقبة |
| U5 | هل روابط التطبيق موثَّقة فعلاً على Android و iOS (ملف entitlements يعلن النطاق القديم `pharmacyksa.com`: `lavkooapp:ios/Runner/Runner.entitlements:17-20`) | ملفات الارتباط المنشورة على النطاق وفحص على جهاز |
| U6 | هل تفشل مصفوفة العرض في `lavkooapp` لقسمَي `stats_bar` و`shipping_cutoff` | تشغيل الاختبارات (غير مسموح في هذه المرحلة) |
| U7 | هل يطابق `lavkooapp` بناءً منشوراً في المتاجر، وأي إصدار من `ksaapp98` هو أصله | سجل الإصدارات في المتاجر وسلالة Git في المستودع القديم |
| U8 | سلوك وسيط الكاش/CDN أمام `/theme/home` إن وُجد، والتزامه بترويسة `Vary` | إعدادات البنية التحتية |
| U9 | هل يُراد للتطبيق تبديل المتجر داخل الجلسة أم بإعادة فتح التطبيق، وهل يلزم ترتيب/نشر مستقل للتطبيق عن الويب | قرار المالك (لا يعيد فتح قرار معتمد؛ هو تفصيل تنفيذي لـGCC-07 و GCC-09) |
| U10 | هل تحمل الروابط المخزَّنة في إعدادات الثيم والبانرات نطاقاً قديماً (`pharmacyksa.com`) يجعلها «خارجية» في حكم `ActionResolver` | فحص قيم `theme_sections.settings` و`theme_blocks.settings` و`banners.url` الإنتاجية |

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| نقاط نهاية الثيم في التطبيق | `lavkooapp:lib/utill/app_constants.dart:58-60` | VERIFIED |
| مجموعة مسارات `theme` عامة ومحدودة المعدّل | `lavkoo:routes/rest_api/v1/api.php:475-487` | VERIFIED |
| اختيار الثيم النشط الوحيد | `lavkoo:app/Services/Theme/ThemeDelivery.php:715-726` | VERIFIED |
| مفتاح وبصمة التخزين في الخادم | `lavkoo:app/Services/Theme/ThemeDelivery.php:128-133`، `lavkoo:app/Services/Theme/ThemeDelivery.php:650-674` | VERIFIED |
| بناء الحمولة وحقولها | `lavkoo:app/Services/Theme/ThemeDelivery.php:309-331` | VERIFIED |
| تحليل الحمولة في التطبيق | `lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:421-440` | VERIFIED |
| قرار إعادة التنزيل | `lavkooapp:lib/features/dynamic_theme/domain/models/theme_schema.dart:469-481` | VERIFIED |
| قائمة القدرات (34) والرؤوس | `lavkooapp:lib/features/dynamic_theme/engine/capability_registry.dart:40-93` | VERIFIED |
| القائمة الآمنة (33) والاستثناءات (6) | `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:44-104` | VERIFIED |
| تخطي النوع المجهول وعزل الأخطاء | `lavkooapp:lib/features/dynamic_theme/engine/section_renderer_registry.dart:49-73` | VERIFIED |
| اختبار عزل المجهول/الرافض/الرامي | `lavkooapp:test/dynamic_theme_registry_test.dart:61-88` | VERIFIED |
| طلب الحمولة ومعالجة 304/404 | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:85-136` | VERIFIED |
| مفتاح التخزين المحلي | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:31` | VERIFIED |
| الرؤوس الافتراضية لكل طلب | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73` | VERIFIED |
| `/theme/version` ضمن المسارات المخزَّنة | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:129` | VERIFIED |
| ذاكرة بلا مدة صلاحية | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:189-192` | VERIFIED |
| مدة القرص من `/api/v1/cash` | `lavkoo:routes/rest_api/v1/api.php:58-60` | VERIFIED |
| عرض المخزَّن قبل الشبكة | `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:173-182` | VERIFIED |
| معالجة `revision == 0` بوجهيها | `lavkooapp:lib/features/dynamic_theme/controllers/dynamic_theme_controller.dart:209-229` | VERIFIED |
| تغيير اللغة: مسح + إعادة جلب قسرية | `lavkooapp:lib/localization/controllers/localization_controller.dart:28-60` | VERIFIED |
| إشعار النشر في التطبيق | `lavkooapp:lib/push_notification/notification_helper.dart:85-94` | VERIFIED |
| إشعار النشر في الخادم | `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27-70` | VERIFIED |
| النشر: ختم ثم مسح ثم إعلان | `lavkoo:app/Services/Theme/ThemeManager.php:108-168` | VERIFIED |
| رمز المعاينة | `lavkoo:app/Services/Theme/ThemePreviewToken.php:33-68` | VERIFIED |
| مسار المعاينة في الـAPI | `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:195-201` | VERIFIED |
| التطبيق لا يرسل `preview` | البحث ب1 (القسم 1) | VERIFIED بالبحث |
| تصنيف الروابط بأول مقطع | `lavkoo:app/Services/Theme/ActionResolver.php:89-125` | VERIFIED |
| حلّ الإجراءات في التطبيق | `lavkooapp:lib/features/dynamic_theme/engine/theme_action_resolver.dart:24-99` | VERIFIED |
| تحويل `sa`/`us` إلى لغتين | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29` | VERIFIED |
| `active_theme` ثابت `default` | `lavkoo:app/Utils/theme-helpers.php:16-19` | VERIFIED |
| التبديل بين الرئيسية الديناميكية والبديلة | `lavkooapp:lib/features/home/screens/home_screens.dart:546-551` | VERIFIED |
| استهداف القنوات | `lavkoo:app/Services/Theme/SectionVisibility.php:123-131` | VERIFIED |
| صفحات القناة | `lavkoo:app/Services/Theme/ExperiencePageService.php:76-101` | VERIFIED |
| tokens المستهلَكة | `lavkooapp:lib/features/dynamic_theme/engine/theme_style.dart:40-62` | VERIFIED |
| البطاقة تحمل `images` لا `image_2`/`image_3` | `lavkoo:app/Services/Theme/SectionDataResolver.php:499-532` | VERIFIED |
| نسخ المسودة يحافظ على `uuid` | `lavkoo:app/Services/Theme/ThemeManager.php:190-194` | VERIFIED |
| `revision` لكل ثيم | `lavkoo:app/Services/Theme/ThemeDelivery.php:186-193` | VERIFIED |
| فلتر روابط Android | `lavkooapp:android/app/src/main/AndroidManifest.xml:122-142` | VERIFIED |
| إصلاحا overflow في القديم فقط | `ksaapp98:lib/features/dynamic_theme/renderers/content_section_renderer.dart:798-819`، `ksaapp98:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:703-719` | VERIFIED |
| الالتزام الوحيد الذي يمسّ ملفات الثيم في المستودعين الحاليين، والالتزام الذي أدخل إصلاحَي overflow في القديم | `lavkooapp@0df8b09`، `lavkoo@34dce658`، `ksaapp98@1a34a4d` | VERIFIED (سجل Git المحلي) |
| الاستيراد ينشئ ثيماً جديداً غير نشط ويحافظ على `uuid` | `lavkoo:app/Services/Theme/ThemePortabilityService.php:148-176`، `lavkoo:app/Services/Theme/ThemePortabilityService.php:199-218` | VERIFIED |
| لا اختبارات خلفية للتسليم | البحث ب4 (القسم 1) | VERIFIED بالبحث |
| لا ترحيلات لجداول الثيم | البحث ب5 (القسم 1) | VERIFIED بالبحث |
| قيم الإنتاج (ثيم منشور، لغات، حد الإصدار) | — | UNKNOWN |

## 9. سجل التحقق المستقل

**نطاق هذه الجولة:** تحقق مستقل بالقراءة فقط على الملف الرئيسي. لم يُشغَّل أي اختبار أو أمر، ولم يُكتب شيء في المستودعات. أُجريت أوامر Git للقراءة فقط (`rev-parse`، `show`، `merge-base`، `diff --name-only`).

**المستودعات:** `lavkooapp` (HEAD `eedbbb8`)، و`ksaapp98` (HEAD `79f50be`)، و`lavkooappseller` (HEAD `8ea0092`) مطابقة لما في الرأس. أما `lavkoo` فـHEAD الحالي `381db71f` وهو تابع للـSHA المدقَّق `7f42777c`، ولا توجد بينهما أي تغييرات في `app` أو `routes` أو `database` أو `config` أو `resources` أو `tests` أو `Modules`؛ الفروق توثيقية فقط (`docs/gcc/audits`)، فالاستشهادات بأرقام الأسطر صالحة للـHEAD الحالي.

| البند | العدد |
|---|---|
| الادعاءات المفحوصة دلالياً | 96 |
| المؤكَّدة دون تغيير | 85 |
| المصحَّحة | 6 |
| المُفنَّدة | 0 |
| غير القابلة للتحقق | 5 |

**التصحيحات:**

1. كان §1 يشير إلى البنود `E17-F23` إلى `E17-F25` غير الموجودة في الجدول. أُضيفت البنود الثلاثة إلى §6، وأُلحقت التعديلات بـ`E17-F04` و`E17-F09`.
2. `A10`: أُضيف رجوع `store_banner` إلى كل البانرات المنشورة من النوع نفسه عند غياب المطابق للثيم (`SectionDataResolver.php:393-395`)، وكان الوصف يذكر الترشيح فقط.
3. `A17`: صار التوصيف على مستوى حقول النموذج، والمخطط الفعلي للجدول UNKNOWN لعدم وجود ترحيل في المستودع.
4. `C01`: أُضيف موضع مفتاح التخزين (`ThemeDelivery.php:79`) لأن الاستشهاد الوارد في الصف لا يحتويه.
5. §2.6: أُضيف حدّ صلاحية الإصدار وGCC-14 كفجوة تصميمية، وأُزيلت الإشارة النصية إلى الملحق من الفقرة.
6. §2.8: وُصف الموضوع بعبارة أشد من الواقع، فصار «قصور في الاقتران»، لأن تأخّر إبطال الحمولة المخزَّنة مسألة توافق وحداثة لا مسألة أمنية.

**تنقيح الصياغة:** استُبدلت عبارات في ثمانية مواضع لأن لفظها لا يطابق المعنى المقصود (تخصيص، أو فيض عرض، أو إعادة فتح)، ولا تحمل أي منها معنى أمنياً.

**البنود المنقولة إلى الملحق المقيّد:** لم يُنقل من الملف الرئيسي شيء في هذه الجولة، إذ لم يُعثر فيه على تفاصيل تستوجب النقل. أُعيد تصنيف ملاحظتين كانتا في الملحق بلا معرّف إلى `SEC-E17-04` (نطاق رمز المعاينة) و`SEC-E17-05` (دورة حياة الرمز). مجموع معرّفات الملحق: `SEC-E17-01` إلى `SEC-E17-05`، ومؤشراتها في الملف الرئيسي موجودة.

**ادعاءات الغياب التي أُعيد البحث عنها بأنماط أخرى:**
- `store|country|market|currency|storefront|region|zone|tenant|X-Store|X-Country` في `app/Services/Theme` وفي `ThemeSectionController.php` ونماذج الثيم، وفي `lavkooapp/lib/features/dynamic_theme` و`lavkooapp/lib/data`: لا مطابقات برمجية؛ الظاهر تعليقات وأسماء أصناف عرض.
- `preview` (بلا حساسية لحالة الأحرف) في `lavkooapp/lib` و`lavkooapp/test`: 104 سطراً في 19 ملفاً، ولا ملف منها داخل `dynamic_theme`.
- `experience_campaigns` و`experience_experiments` في `database` و`app`: لا ملفات ترحيل.
- `Schema::create` مع كلمة `theme` في `lavkoo/database`: صفر.
- `home_index` في `lavkoo/app`: موضع واحد (`ThemeDelivery.php:167`).
- `clearMemoryCache` و`clearDiskCache` في `lavkooapp/lib`: موضعا استدعاء (`localization_controller.dart:43-44`، `home_screens.dart:240`) مع التعريفين.
- `.github` و`test/release_version_test.dart` في `lavkooapp`: غير موجودين؛ `ksaapp98` يحوي `.github/workflows/flutter.yml`.
- تطابق ملفات مجلد الثيم: 21 من 24 متطابقة مع `ksaapp98` (ثلاثة renderers تختلف).

**مخاطر متبقية (بأسماء الأقسام):**
- §6: تأثير `E17-F04` و`E17-F23` عبر النسخ INFERRED، ويتوقف على احتواء ملف الاستيراد على `banner_id` ووجود ترحيل يمنع تكرار `uuid`.
- §6: تصنيف `E17-F25` P2 مشروط بعدد المتاجر النشطة.
- §7: بقيت البنود U1 إلى U10 UNKNOWN كما هي؛ لا يوجد وصول إنتاجي أو تشغيل.
- §2.4 و§6 `E17-F03`: الأثر على جلسة طويلة قراءة ثابتة لم تُختبر تشغيلياً.

**فجوات التغطية:**
- جدول §2.7 يذكر أن الإجراءين `search` و`campaign` لا يمرّران `query` و`id` (`route_healper.dart:695-697`، `:863-865`)؛ لم يُتتبَّع استدعاؤهما من الشاشات، فالادعاء غير مُتحقَّق منه.
- ترتيب الاشتراك في موضوع الثيم بعد نجاح `initConfig` (`splash_controller.dart:142-150`) لم يُتحقق من شرطه.
- عبارة «قوائم اختبار متقادمة» في `E17-F16` غير مُتحقَّق منها.
- استدعاء مسح الرمز عند تسجيل الخروج (من الملحق، `SEC-E17-02`) لم يُتحقق من موضعه.
- خارج النطاق كما في §1 دون تغيير: `ThemeBuilderService`، و`PublishValidator`، و`SegmentResolver`، و`ProductWidget`.

**نتائج الأدوات بعد التنقيح:** `check_citations.py` على الملف الرئيسي: 0 defects (445 استشهاداً ملفياً)؛ `scan_sensitive.py`: 0 hits؛ `check_citations.py` على الملحق: 0 defects.
