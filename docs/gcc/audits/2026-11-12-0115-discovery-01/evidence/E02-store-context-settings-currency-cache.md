# E02 — سياق المتجر: الإعدادات العامة والعملة واللغة والجلسة والكاش (Store context: global settings, currency, locale, session, cache)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` (المستودع الأساسي لهذا الملف)، ومقارنة محدودة بملف واحد في كل من `lavkooapp@eedbbb8fd0303aa9fed5d4ff0ac45b3a409abf7a` و`lavkooappseller@8ea00922e44db3d69059d46892f8f4f84349b580`. (أثناء الجلسة تقدّم HEAD المحلي للمستودع الأساسي إلى `lavkoo@046d623d8ac0d58afe26b072a7a712ae68a40c59` ثم إلى `lavkoo@41201499d515b6adbbe1faeb3150a0bb724c6bf9`، وكلاهما بإضافات توثيق تحت `docs/gcc/audits` فقط؛ الفرق بين `7f42777` و`41201499` خارج `docs` فارغ، فأرقام الأسطر المستشهد بها صالحة لها جميعاً.)

## 1. النطاق وما تم فحصه

**الهدف:** توثيق كيف ينتشر «سياق المتجر» اليوم داخل الـ backend (تغطية B في `DISCOVERY_PROMPT_01`): الإعدادات العامة، العملة، اللغة، الجلسة، الكاش، وما يعنيه ذلك لقرارات GCC المعتمدة.

**ملفات قُرئت كاملةً (سطراً بسطر):** `lavkoo:app/Providers/AppServiceProvider.php`، `lavkoo:app/Utils/settings.php`، `lavkoo:app/Utils/currency.php`، `lavkoo:app/Utils/Helpers.php`، `lavkoo:app/Utils/language.php`، `lavkoo:app/Utils/constant.php`، `lavkoo:app/Utils/BackEndHelper.php`، `lavkoo:app/Utils/Convert.php`، `lavkoo:app/Utils/panel-helpers.php`، `lavkoo:app/Utils/theme-helpers.php`، `lavkoo:app/Utils/version.php`، `lavkoo:app/Traits/CacheManagerTrait.php`، `lavkoo:app/Traits/MaintenanceModeTrait.php`، `lavkoo:app/Traits/SettingsTrait.php`، النماذج `BusinessSetting`/`Setting`/`Currency`/`ExchangeRateLog`/`IntegrationSetting`/`LoginSetup`/`Translation`، الخدمات `BusinessSettingService`/`SettingService`/`DeliveryCountryCodeService`/`Platform/Policy`/`Platform/PasswordPolicy`/`Marketplace/ExchangeRateService`، الـ middleware `Localization`/`APILocalizationMiddleware`/`MaintenanceModeMiddleware`/`CheckForMaintenanceMode`/`DetectMobile`/`MonitorRequest`/`GuestMiddleware`/`APIGuestMiddleware`، المزوّدات `ConfigServiceProvider`/`MailConfigServiceProvider`/`ThemeServiceProvider`/`SocialLoginServiceProvider`/`FirebaseServiceProvider`/`RouteServiceProvider`، `lavkoo:bootstrap/app.php`، `lavkoo:config/app.php`، `lavkoo:config/session.php`، `lavkoo:config/cache.php`، المتحكمات `Admin/Settings/CurrencyController`، `Admin/Marketplace/ExchangeRateController`، `Web/CurrencyController`، `SharedController`، `RestAPI/v1/ConfigController`، والمستودعات `CurrencyRepository`/`BusinessSettingRepository`.

**ملفات قُرئت جزئياً (المقاطع المستشهد بها فقط):** `module-helper.php` (823 سطراً: 1–240، 290–334، 676–823)، `LanguageService.php` (1–140)، `PolicyRegistry.php` (مخطط المفاتيح)، `PaymentConfigProvider.php` (1–140)، `Admin/Settings/BusinessSettingsController.php` (118–275)، `Customer/PaymentController.php` (408–432 ونتائج البحث عن العملة)، `ProductManager.php`، `CategoryManager.php`، `BrandManager.php`، `product.php`، نماذج `Product`/`Category`/`Brand`/`EmailTemplate`، خدمات `Theme/*` و`Commerce/*` (مواضع الكاش فقط)، ملفات `config/fulfillment.php` و`config/myfatoorah.php` و`config/operational_costs.php` (مقاطع العملة والدولة)، و`tests/Support/BuildsPilotServerSchema.php` (كدليل موثَّق عن خادم التجربة).

**جرود آلية (سكربتات قراءة فقط محفوظة في مساحة العمل، لا تنفّذ أي كود من المستودع):**

| الجرد | الأنماط | المجلدات | النتيجة |
|---|---|---|---|
| مفاتيح `business_settings` الحرفية | `getWebConfig('…')`، `BusinessSetting::where(['type' => '…'])`، `updateOrInsert(type: '…')`، `getFirstWhere(params: ['type' => '…'])`، `getSettings(object:, type: '…')`، `get_settings($x, '…')`، `->setting('…')`، `BusinessSetting::updateOrCreate(['type' => '…'])` | `app`، `Modules`، `routes`، `resources/views`، `resources/themes`، `config` | 213 مفتاحاً حرفياً، منها 4 إيجابيات كاذبة استُبعدت يدوياً (`banner_faq_page`، `complete_floor`، `max_pages`، `max_seconds`) ⇒ 209 مفاتيح؛ إضافةً إلى 73 مفتاح سياسة في `PolicyRegistry` ضمن 12 مجموعة |
| مواضع الكاش | `Cache::*(`، `cache()->*(`، `->remember(`، `rememberForever(`، `RateLimiter::`، `Redis::` | `app`، `Modules` | 306 أسطر مطابقة (منها 107 في `settings.php` و`CacheManagerTrait.php` اللذين قُرئا كاملين) |
| مفاتيح الجلسة | `session('…')`، `session()->put/get/has/forget(…)`، `Session::put/get/has/forget(…)` | `app`، `Modules`، `resources/views`، `resources/themes`، `routes` | 73 مفتاحاً حرفياً |
| دوال العملة | أسماء 33 دالة تحويل/تنسيق | `app`، `Modules`، `resources`، `tests`+`routes`+`database`+`config` | جدول أعداد المواضع في القسم 2.2 |
| الثوابت السعودية | `SAR`، `Saudi`، `+966`، `'SA'`، `Riyadh`، `15%`، `KSA`، `Asia/Dhaka`، `Asia/Riyadh`، `ZATCA` … | `app`، `config`، `routes`، `resources/views`، `resources/themes`، `Modules`، `database`، `tests` (مع استثناء `resources/lang` و`*.min.js`) | جدول القسم 3 |

**ما لم يُفحص:** محتوى ملفات العرض `resources/views` و`resources/themes` واحداً واحداً (أُحصيت بالأنماط فقط مع قراءة عيّنات)؛ ملفات JavaScript العامة؛ داخل محرك الثيم وخدمات القنوات والدفع والـ fulfillment إلا مواضع العملة/الكاش/السياق؛ تطبيقا Flutter عدا `lib/helper/price_converter.dart` في كل منهما؛ مجلد `vendor`؛ قاعدة بيانات الإنتاج وملف `.env` (غير متاحين)؛ المستودع القديم `pharmacyksa98` (غير متاح). لم يُشغَّل أي كود ولا أي اختبار.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 آليات الإعدادات العامة

**(أ) مخازن الإعدادات.** توجد ستة مخازن على الأقل، وكلها بلا أي بُعد للمتجر أو الدولة:

| المخزن | الشكل | القراءة | الدليل |
|---|---|---|---|
| جدول `business_settings` | صف لكل مفتاح: `type` + `value` (نص أو JSON) | `getWebConfig()` عبر كاش واحد، أو استعلام مباشر | `lavkoo:app/Models/BusinessSetting.php:13`، `lavkoo:app/Utils/settings.php:14-34` |
| جدول `addon_settings` (النموذج `Setting`) | `key_name` + `settings_type` + `live_values`/`test_values` + `mode` + `is_active` لكل بوابة دفع أو SMS | استعلام مباشر بلا كاش | `lavkoo:app/Models/Setting.php:15-23`، `lavkoo:app/Traits/Processor.php:56-66`، `lavkoo:app/Utils/Helpers.php:830-867` |
| جدول `login_setups` | `key` + `value` | `getLoginConfig()` عبر كاش | `lavkoo:app/Models/LoginSetup.php:22-29`، `lavkoo:app/Utils/settings.php:223-233` |
| جدول `integration_settings` | صف واحد لكل `provider` مع `credentials` و`settings` | استعلام مباشر `where('provider', …)` | `lavkoo:app/Models/IntegrationSetting.php:9-18`، `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:73` |
| جدول `currencies` | `name`/`symbol`/`code`/`exchange_rate`/`status` | مباشرة أو عبر كاشَين | `lavkoo:app/Models/Currency.php:31-39` |
| ملفات `config/*.php` + متغيرات البيئة | مثل `fulfillment.currency`، `myfatoorah.currency`، `operational_costs.base_currency` | `config()` | `lavkoo:config/fulfillment.php:59`، `lavkoo:config/myfatoorah.php:106`، `lavkoo:config/operational_costs.php:28` |

يضاف إليها حالة الإضافات المخزّنة في ملفات (`modules_statuses.json` و`Modules/<name>/Addon/info.php`) التي يقرؤها `getCheckAddonPublishedStatus()` (`lavkoo:app/Utils/module-helper.php:694-712`). الحالة: `VERIFIED`.

**(ب) الدالة المركزية `getWebConfig()`.** تقرأ الجدول كله مرة واحدة `BusinessSetting::all()` وتخزّنه تحت المفتاح الثابت `cache_business_settings_table` لمدة ثلاث ساعات، ثم تبحث عن الصف بـ `firstWhere('type', $name)` وتفكّ JSON (`lavkoo:app/Utils/settings.php:26-30`، `lavkoo:app/Library/Constant.php:1132`، `lavkoo:app/Library/Constant.php:1136`). لا يدخل في المفتاح أي بُعد (لا لغة، لا بائع، لا متجر). الفرع الذي يوحي بوجود كاش لكل مفتاح (`in_array($name, getWebConfigCacheKeys()) && Cache::has($name)` في `lavkoo:app/Utils/settings.php:23`) لا يُفعَّل عملياً: الدالة `setWebConfigCache()` لا تكتب في الكاش بل تفكّ القيمة فقط (`lavkoo:app/Utils/settings.php:62-99`)، والبحث عن أي `Cache::put|forever|add` بأحد الأسماء الستة عشر (18 مدخلاً منها اثنان مكرران) الواردة في `lavkoo:app/Utils/settings.php:105-110` داخل `app`، `Modules`، `routes`، `resources` لم يُرجع أي نتيجة. الحالة: `VERIFIED` (مع تسجيل البحث).

**(ج) الكتابة والإبطال.** المستودع `BusinessSettingRepository` يستدعي `clearWebConfigCacheKeys()` بعد كل كتابة (`lavkoo:app/Repositories/BusinessSettingRepository.php:89-111`)، والنموذج نفسه يستدعي `cacheRemoveByType('business_settings')` في `saved`/`deleted` (`lavkoo:app/Models/BusinessSetting.php:35-41`). هذا النوع يُسقط كاش الإعدادات **ومعه** كاش نوع الشحن والتحليلات ثم كل مجموعة `products` (التي تُسقط بدورها `categories` و`flash_deals` و`shops` و`order_details` وقوائم الصفحة الرئيسية والعلامات) (`lavkoo:app/Utils/settings.php:436-440`، `lavkoo:app/Utils/settings.php:466-500`). أي أن حفظ أي إعداد واحد يُبطل معظم كاش الواجهة. في المقابل توجد كتابات جماعية مباشرة (`BusinessSetting::where(...)->update(...)`) لا تُطلق أحداث النموذج — يوثّق ذلك تعليق `lavkoo:app/Models/Builders/AuditedBuilder.php:13-18` — ولذلك يعتمد إبطال الكاش فيها على أن يتذكّر الموضع المستدعي ذلك (أُحصي 32 موضعاً تنفيذياً في 18 ملفاً ضمن `app` و`Modules` لأنماط الكتابة المباشرة على `BusinessSetting` خارج المستودع (أُعيد العدّ في التحقق): `BusinessSetting::where(...)->update`، `updateOrInsert`، `updateOrCreate`، `create`، `insert`، `DB::table('business_settings')`). الحالة: `VERIFIED` للمسار، وأثر التقادم `INFERRED`.

**(د) إعدادات تُحمَّل عند الإقلاع (قبل التوجيه وقبل أي middleware).**

| المزوّد | ما يفعله في `boot()`/`register()` | الدليل |
|---|---|---|
| `AppServiceProvider` | يبني مصفوفة `$web_config` (اسم الشركة، الهاتف، الشعارات، الألوان، `decimal_point_settings`، حالة المحفظة والولاء، قائمة العملات النشطة، بوابات الدفع النشطة، سياسات الاسترجاع والشحن، عروض الفلاش، الأصناف الرئيسية …) ويشاركها مع كل العروض بـ `View::share`، ثم يستدعي `Helpers::currency_load()`؛ كل ذلك لغير الـ console فقط | `lavkoo:app/Providers/AppServiceProvider.php:171-176`، `lavkoo:app/Providers/AppServiceProvider.php:196-225`، `lavkoo:app/Providers/AppServiceProvider.php:268-302`، `lavkoo:app/Providers/AppServiceProvider.php:312-313` |
| `ConfigServiceProvider` | استعلام مباشر عن `timezone` ثم `date_default_timezone_set()` | `lavkoo:app/Providers/ConfigServiceProvider.php:30-34` |
| `MailConfigServiceProvider` | يستبدل `config('mail')` كاملاً من `mail_config` أو `mail_config_sendgrid` | `lavkoo:app/Providers/MailConfigServiceProvider.php:29-45` |
| `PaymentConfigProvider` | يحمّل إعدادات PayPal و Razorpay و Paystack و Flutterwave و Paytm في `config()` | `lavkoo:app/Providers/PaymentConfigProvider.php:31-52`، `lavkoo:app/Providers/PaymentConfigProvider.php:89-120` |
| `SocialLoginServiceProvider` | يضبط `services.google`/`services.facebook` وعناوين الرجوع | `lavkoo:app/Providers/SocialLoginServiceProvider.php:29-47` |
| `FirebaseServiceProvider` | حساب خدمة واحد من `push_notification_key` (singleton) | `lavkoo:app/Providers/FirebaseServiceProvider.php:19-22` |
| `ThemeServiceProvider` | مسار عرض واحد `resources/themes/default`؛ والدالة `theme_root_path()` ثابتة تُرجع `'default'` | `lavkoo:app/Providers/ThemeServiceProvider.php:17-23`، `lavkoo:app/Utils/theme-helpers.php:16-19` |

ترتيب التسجيل في `lavkoo:bootstrap/providers.php:4-22`. النتيجة البنيوية: حين يعمل أي middleware (بما فيه `StartSession` في مجموعة `web`، `lavkoo:bootstrap/app.php:60-67`) تكون هوية الشركة وقائمة العملات وبوابات الدفع وإعدادات البريد قد حُسمت لقيمة عامة واحدة. `$web_config` مستهلَكة في 276 موضعاً ضمن 60 ملف عرض، و`getWebConfig(` في 936 موضعاً ضمن 216 ملفاً في `app` و439 موضعاً ضمن 150 ملفاً في `resources`. الحالة: `VERIFIED` للكود والأعداد (من بحث `rg`)؛ وترتيب «إقلاع المزوّدات قبل تنفيذ الـ middleware» من دورة حياة الإطار (`INFERRED`).

**(هـ) سجل السياسات `Policy`.** طبقة أحدث فوق الجدول نفسه: 73 مفتاحاً معرّفاً في `PolicyRegistry::GROUPS` بأنواع وحدود وقيم افتراضية (`lavkoo:app/Services/Platform/PolicyRegistry.php:39-45`)، تُقرأ بـ `getWebConfig()` وتُكتب بـ `BusinessSetting::updateOrCreate(['type' => $key], …)` ثم **تُفرغ الكاش كله** بـ `cache()->flush()` (`lavkoo:app/Services/Platform/Policy.php:113`، `lavkoo:app/Services/Platform/Policy.php:118-120`، `lavkoo:app/Services/Platform/Policy.php:166-170`). بينها حدود مالية بلا عملة (`payout_minimum_amount`، `payout_dual_control_amount`: `lavkoo:app/Services/Platform/PolicyRegistry.php:396-401`). الحالة: `VERIFIED`.

### 2.2 العملة من البداية إلى النهاية

**(أ) النموذج.** جدول `currencies` + ثلاثة إعدادات عامة: `currency_model` (`single_currency` أو `multi_currency`)، `system_default_currency` (معرّف صف العملة)، `decimal_point_settings`، إضافةً إلى `currency_symbol_position`. الثابت `MULTI_CURRENCY = 'multi_currency'` والثابت `USD = 'usd'` (بأحرف صغيرة) في `lavkoo:app/Utils/constant.php:14` و`lavkoo:app/Utils/constant.php:28`؛ و`exchangeRate()` تبحث عن الصف بهذا الرمز (`lavkoo:app/Utils/currency.php:225-228`). إن لم يوجد الصف يكون الوصول إلى `exchange_rate` على `null` تحذيراً في PHP، وليس رجوعاً صامتاً إلى 1: لا يوجد `set_error_handler` في مسار التطبيق (الموجود فقط في `lavkoo:app/Services/DeveloperPortal/Support/ValidationExtractor.php:109` و`lavkoo:app/Services/Monitoring/Collectors/StorageCollector.php:176`)، فيتحوّل التحذير عادةً إلى استثناء بمعالج Laravel الافتراضي (`INFERRED`). فصحة التحويل تعتمد على وجود صف رمزه `USD` وعلى ترتيب مقارنة غير حساس للحالة في قاعدة البيانات (`INFERRED`). عمود `exchange_rate` نصّي (`lavkoo:database/migrations/2021_06_03_104531_ex_rate_update.php:16-18`). حذف العملات ذات المعرّفات 1–7 ممنوع بقائمة ثابتة (`lavkoo:app/Http/Controllers/Admin/Settings/CurrencyController.php:172`)، و`Helpers::currency_load()` يرجع إلى `Currency::find(1)` عند غياب العملة الافتراضية (`lavkoo:app/Utils/Helpers.php:331-334`). الحالة: `VERIFIED`.

**(ب) وحدة التخزين.** المبالغ تُخزَّن **بلا عمود عملة**، ووحدتها تُستنتج وقت القراءة من الإعدادين العامين:

- في وضع `multi_currency`: يُقسم المبلغ المُدخل بالعملة الافتراضية على (سعر الافتراضية ÷ سعر الدولار) قبل الحفظ، أي أن المخزَّن «وحدة مبنية على الدولار» (`currencyConverter()` في `lavkoo:app/Utils/currency.php:50-67`). هذا موثّق صراحةً في الكود الأحدث: «The platform stores prices and order totals in a USD-based unit and converts for display» (`lavkoo:app/Services/Fulfillment/Support/ProviderCurrency.php:8-9`).
- في وضع `single_currency`: يُخزَّن المبلغ كما أُدخل (الفرع `else` في `lavkoo:app/Utils/currency.php:63-65`)، فتكون وحدته «العملة الافتراضية أياً كانت».

مواضع الكتابة التي تمر بـ `currencyConverter()`: سعر المنتج والضريبة والخصم وتكلفة الشحن (`lavkoo:app/Services/ProductService.php:614-619`، `lavkoo:app/Services/ProductService.php:633`)، مبالغ الطلب في نقاط البيع (`lavkoo:app/Services/OrderService.php:200-210`)، الكوبونات (`lavkoo:app/Services/CouponService.php:26-29`)، طرق الشحن (`lavkoo:app/Services/ShippingMethodService.php:19`)، حركات المحفظة (`lavkoo:app/Repositories/WalletTransactionRepository.php:137-138`)، وحتى حدود مالية داخل `business_settings` نفسها (`lavkoo:app/Http/Controllers/Admin/Settings/InhouseShopController.php:178`، `lavkoo:app/Http/Controllers/Admin/Settings/OrderSettingsController.php:41`). واجهات البائع (API v2/v3) تستخدم المكافئ `Convert::usd()`/`BackEndHelper::currency_to_usd()` (`lavkoo:app/Utils/Convert.php:9-22`، `lavkoo:app/Utils/BackEndHelper.php:10-23`).

البحث عن `currency|exchange_rate` (غير حساس للحالة) في 15 نموذجاً مالياً (`Order`، `OrderDetail`، `OrderTransaction`، `WalletTransaction`، `LoyaltyPointTransaction`، `Product`، `Cart`، `Coupon`، `ShippingMethod`، `RefundRequest`، `RefundTransaction`، `SellerWallet`، `AdminWallet`، `WithdrawRequest`، `User`) لم يجد إلا الحقل `bring_change_amount_currency` في `Order` (`lavkoo:app/Models/Order.php:95`). الحالة: `VERIFIED`.

**(ج) التحويل عند القراءة.**

| الدالة | المعادلة | التقريب | الدليل |
|---|---|---|---|
| `usdToDefaultCurrency()` (لوحات الإدارة والبائع، السلة، الإشعارات) | `multi`: المبلغ × (سعر الافتراضية ÷ سعر الدولار)؛ `single`: بلا تحويل | **اقتطاع** `floor(value × 10^d) / 10^d` حيث `d = decimal_point_settings ?? 2` | `lavkoo:app/Utils/currency.php:76-104` |
| `webCurrencyConverter()` (واجهة المتجر) | `multi`: المبلغ × (سعر عملة الجلسة ÷ سعر الدولار)؛ `single`: المعامل 1 | `round(…, d)` ثم إضافة الرمز | `lavkoo:app/Utils/currency.php:113-131` |
| `usdToAnotherCurrencyConverter()` (مبلغ الدفع) | المبلغ × (سعر العملة المختارة ÷ سعر الدولار) | `round(…, d)` | `lavkoo:app/Utils/currency.php:207-217` |
| `BackEndHelper::usd_to_currency()` و`Convert::default()` | المبلغ × (الافتراضية ÷ الدولار) | `round(…, 2)` ثابت | `lavkoo:app/Utils/BackEndHelper.php:25-51`، `lavkoo:app/Utils/Convert.php:51-63` |
| `setCurrencySymbol()` | موضع الرمز من `currency_symbol_position`؛ عدد الخانات من `decimal_point_settings` أو 0 | `number_format` | `lavkoo:app/Utils/currency.php:257-267` |

أعداد المواضع (مطابقات `rg` تشمل سطر التعريف): `usdToDefaultCurrency(` — 44 في `app` (18 ملفاً)، 61 في `Modules` (9 ملفات)، 805 في `resources` (155 ملفاً)؛ `webCurrencyConverter(` — 32 في `app` (7 ملفات)، 368 في `resources` (92 ملفاً)؛ `currencyConverter(` — 96 في `app` (36 ملفاً)، 3 في `resources`؛ `setCurrencySymbol(` — 32 في `app`، 61 في `Modules`، 692 في `resources`؛ `getCurrencyCode(` — 27 في `app`، 61 في `Modules`، 683 في `resources`؛ `Convert::usd(` — 47 في `app` (11 ملفاً)؛ `BackEndHelper::currency_to_usd(` — 22 في `app` (6 ملفات)؛ `Convert::usdPaymentModule(` — 9 في `app` (4 ملفات). الدوال الخاصة بعملات بعينها (`bdtTousd`، `usdTobdt`، `usdTomyr`، `usdTozar`، `usdToinr`، `usdToegp` في `lavkoo:app/Utils/Convert.php:65-153`) بلا أي مستدعٍ في `app`/`Modules`/`resources`. الحالة: `VERIFIED`.

**(د) تبديل العملة.** على الويب: المسار `POST /currency` (`lavkoo:routes/web/routes.php:264`) يضع رموز العملة وقيمتها في الجلسة (`lavkoo:app/Http/Controllers/Web/CurrencyController.php:18-28`)، وتفاصيل التحقق عند هذا المسار مُدرجة في الملحق المقيّد (SEC-E02-08)، وقائمة الاختيار لا تُعرض في الترويسة إلا إذا كان النموذج `multi_currency` (`lavkoo:resources/themes/default/layouts/front-end/partials/_header.blade.php:39-40`). في الـ API: لا جلسة أصلاً (مجموعة `api` بلا `StartSession`: `lavkoo:bootstrap/app.php:84-94`)؛ نقطة `/api/v1/config` تُرجع `system_default_currency` و`currency_list` (كل الصفوف لا النشطة فقط) و`currency_model` و`decimal_point_settings` و`currency_symbol_position` (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:158`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:203-204`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:214`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:219`، `lavkoo:app/Traits/CacheManagerTrait.php:53-58`)، والتطبيق يحوّل الأسعار محلياً. عند الدفع يرسل التطبيق `current_currency_code` أو تُؤخذ عملة الجلسة، وفي وضع `multi_currency` يُحوَّل المبلغ آلياً بسعر الصرف إلى عملة تدعمها البوابة (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-423`، `lavkoo:app/Traits/PaymentGatewayTrait.php:476-499`). الحالة: `VERIFIED`.

ملاحظة عابرة للمستودعات: محوّل الأسعار في تطبيق العميل يثبّت `singleCurrency = false` ويضرب دائماً في (سعر عملتي ÷ سعر الدولار) ويعرض بلا خانات عشرية (`lavkooapp:lib/helper/price_converter.dart:65-76`)، بينما تطبيق البائع يحترم `currency_model` (`lavkooappseller:lib/helper/price_converter.dart:18-24`). الحالة: `VERIFIED` للكود؛ التفاصيل من اختصاص ملحقَي التطبيقين.

**(هـ) تحديث أسعار الصرف.** يدوي فقط؛ لا توجد مهمة مجدولة ولا أمر console للعملات (بحث `exchange|currency` في `lavkoo:app/Console/ScheduleDefinition.php` وأسماء ملفات `app/Console/Commands`: لا شيء). المسارات: صفحة العملات الكلاسيكية (`lavkoo:app/Http/Controllers/Admin/Settings/CurrencyController.php:124-168`)، حفظ الإعدادات العامة (`lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:128-178`، والاستدعاء في السطر 170)، الذي يعيد حساب `exchange_rate` لكل صف في جدول `currencies` بقسمته على سعر العملة الافتراضية في وضع `multi_currency` (`lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:180-191`)، ولا يمسّ أسعار المنتجات أو المبالغ المخزَّنة في الطلبات، وشاشة «حوكمة الأسعار» التي تسجّل قبل/بعد في `exchange_rate_logs` إن وُجد الجدول ثم تُفرغ الكاش كله (`lavkoo:app/Services/Marketplace/ExchangeRateService.php:52-64`، `lavkoo:app/Http/Controllers/Admin/Marketplace/ExchangeRateController.php:47-50`). لا يوجد migration ينشئ `exchange_rate_logs` في المستودع (بحث `exchange_rate_logs` في كل ملفات `*.php`/`*.sql`/`*.md` خارج `vendor`: الخدمة نفسها، `RestoreSection.php`، وملفا ترجمة فقط). الحالة: `VERIFIED`.

**(و) ماذا يحدث للمبالغ المخزّنة لو فُعّلت عملة ثانية؟** الجواب يتوقف على معنى «التفعيل»:

1. **تفعيل صف عملة إضافي مع بقاء `single_currency`:** لا يتغير شيء في التخزين. لكن عند استعمال مسار تبديل العملة على الويب (SEC-E02-08) يتغير رمز العملة في الجلسة بينما يبقى معامل التحويل 1 (`lavkoo:app/Utils/currency.php:126-128`، `lavkoo:app/Utils/currency.php:240-241`) ⇒ السعر نفسه يُعرض برمز عملة أخرى (إعادة وسم بلا تحويل). وعند الدفع تُرسَل للبوابة العملة الافتراضية (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:420-422`). الحالة: `VERIFIED` للكود، الأثر `INFERRED`.
2. **تبديل `currency_model` إلى `multi_currency`:** الدالة `updateCurrencyModel()` تغيّر قيمة الإعداد وتمسح الكاش والجلسة وتكتب سطر تدقيق فقط؛ **لا ترحيل لأي مبلغ** (`lavkoo:app/Http/Controllers/Admin/Settings/CurrencyController.php:250-276`). من تلك اللحظة تُفسَّر كل المبالغ التاريخية (أسعار، طلبات، أرصدة محافظ، كوبونات، حدود) على أنها «مبنية على الدولار» وتُضرب عند العرض في (سعر الافتراضية ÷ سعر الدولار)، وتُقسم الكتابات الجديدة على المعامل نفسه؛ فيجتمع في العمود الواحد قديم بوحدة وجديد بوحدة أخرى بلا علامة تميّز بينهما. الحالة: `VERIFIED` للكود، الأثر `INFERRED`.
3. **تغيير العملة الافتراضية:** في `single_currency` تُضبط العملة الجديدة بسعر 1 ويُغيَّر المعرّف فقط (`lavkoo:app/Http/Controllers/Admin/Settings/CurrencyController.php:295-301`) ⇒ كل الأرقام التاريخية تُعاد تسميتها بالعملة الجديدة 1:1. في `multi_currency` تُعرض كلها محوَّلة بسعر الصرف (تحويل آلي). الحالة: `VERIFIED` للكود.

إذن النموذج الحالي لا يستطيع تمثيل «متجرين بعملتين مستقلتين وأسعار محلية بلا تحويل آلي»: إما عملة واحدة لكل شيء، وإما تحويل آلي لكل شيء.

**(ز) قيم الإنتاج.** غير متاحة للقراءة. المستودع نفسه يوثّق ما وُجد على «خادم التجربة»: متجر `single_currency`، العملة الافتراضية ذات المعرّف 8 والرمز «KSA» (اسم الدولة لا رمز ISO) بسعر «1»، وخانتان عشريتان، مع `FULFILLMENT_CURRENCY=SAR` (`lavkoo:tests/Support/BuildsPilotServerSchema.php:12-18`، `lavkoo:tests/Support/BuildsPilotServerSchema.php:43-53`)، ويؤكده إعداد المرادفات `'KSA' => 'SAR'` (`lavkoo:config/fulfillment.php:62-69`، `lavkoo:app/Services/Fulfillment/Support/CurrencyCode.php:8-10`). الحالة: `INFERRED` (توثيق داخل المستودع عن خادم تجربة في تاريخ سابق)؛ القيم الحالية `UNKNOWN` حتى تُقرأ صفوف `business_settings` و`currencies` من الإنتاج.

**(ح) مصادر أخرى لـ«عملة المتجر» خارج الإعداد العام.** الأنظمة الأحدث لا تعتمد `system_default_currency` وحده: `config('fulfillment.currency')` (`lavkoo:config/fulfillment.php:59`)، `config('operational_costs.base_currency')` (`lavkoo:config/operational_costs.php:28`)، `config('myfatoorah.currency')` مع عبارة «The store is KSA-only» وخادم إنتاج سعودي ثابت في قائمة المسموح (`lavkoo:config/myfatoorah.php:60-72`، `lavkoo:config/myfatoorah.php:103-106`)، عملة حساب القناة مع رجوع ثابت إلى `'SAR'` (`lavkoo:app/Services/SalesChannels/Amazon/AmazonConnector.php:586`)، ومحاسبة Odoo التي تفترض خانتين عشريتين («the only currency this store settles in is SAR»: `lavkoo:app/Services/Accounting/Odoo/OdooMoney.php:33`). الحالة: `VERIFIED`.

### 2.3 اللغة مقابل الدولة

**(أ) اختيار اللغة.**

| السياق | المصدر | الدليل |
|---|---|---|
| الويب (متجر/إدارة/بائع) | مفتاح الجلسة `local`؛ عند غيابه تُؤخذ اللغة المعلَّمة `default` في إعداد `language` وتُكتب في الجلسة مع `direction` | `lavkoo:app/Utils/language.php:104-130` |
| تبديل اللغة على الويب | `POST change-language` يكتب `local` و`direction` في الجلسة | `lavkoo:app/Http/Controllers/SharedController.php:30-33` |
| الـ API (v1 و v2 و v3) | الترويسة `lang` عبر `api_lang`؛ تُطوى القيمتان `sa→ar` و`us→en` ثم تُطابَق مع لغات المتجر بالرمز أو باسم اللغة | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29`، `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:69-86`، `lavkoo:routes/rest_api/v1/api.php:54` |
| الإشعارات | العمود `app_language` لكل مستلم (عميل/بائع/مندوب) وإلا اللغة الافتراضية | `lavkoo:app/Traits/PushNotificationTrait.php:29-33` |
| عنوان URL | لا يوجد: لا بادئة لغة ولا دولة في المسارات، ولا `Route::domain`؛ وروابط hreflang تُولَّد بصيغة `?language=<code>` (`lavkoo:app/View/Composers/ProductSeoSupplementComposer.php:144-150`) دون أن يوجد مستهلك لهذا الوسيط في واجهة المتجر (بحث `request('language')` وصيغه الست في `app` و`Modules`: موضعان إداريان فقط) | نتائج البحث المسجّل |

الـ middleware `Localization` المسجّل في مجموعة `web` يقرأ مفتاح الجلسة `locale` (`lavkoo:app/Http/Middleware/Localization.php:20-22`)، ولا يوجد أي موضع يكتب هذا المفتاح (بحث `put('locale'` و`session('locale')` في `app`، `Modules`، `routes`، `resources`: لا نتائج غير الـ middleware نفسه) ⇒ هو بلا أثر عملي. الحالة: `VERIFIED`.

**(ب) رموز اللغة هي رموز دول.** قائمة اللغات في لوحة الإدارة تُبنى من قائمة دول بحقل `iso2` (`lavkoo:app/Http/Controllers/Admin/Settings/LanguageController.php:65-98`)، ومجلدات الترجمة المتتبَّعة هي `ae`، `bd`، `en`، `es`، `in`، `ks`، `sa` تحت `resources/lang`. الدالة `getLanguageCode(string $country_code)` تحوّل «رمز الدولة» إلى لغة بمطابقة جزء الإقليم في قائمة locales (`lavkoo:app/Utils/language.php:416-434`)، و`translate()` تضبط `App::setLocale()` على ناتجها بعد كل استدعاء (`lavkoo:app/Utils/language.php:8-17`). الكود نفسه يوثّق ذلك: «6Valley stores country-ish codes such as "sa"/"sy"» (`lavkoo:app/Services/Seo/SitemapHreflangGenerator.php:85-87`)، ولوحة البائع تحوّل `sy`/`sa` إلى `ar` بجدول ثابت (`lavkoo:app/Services/SellerCenter/Shell.php:47-55`)، وأعلام اللغات في الترويسة تُعرض من رمز اللغة مباشرة (`lavkoo:resources/themes/default/layouts/front-end/partials/_header.blade.php:69-71`). الحالة: `VERIFIED`.

**(ج) RTL.** الاتجاه خاصية لكل لغة داخل إعداد `language` (`lavkoo:app/Services/LanguageService.php:75-82`) تُنسخ إلى مفتاح الجلسة `direction` ويقرؤها القالب (`lavkoo:resources/themes/default/layouts/front-end/app.blade.php:2`)؛ أُحصي 64 موضع قراءة بنمط `session(…'direction')` في `app` و`Modules` و`resources` (عدّ أُعيد في التحقق). الحالة: `VERIFIED`.

**(د) تخزين الترجمة.** نصوص الواجهة في ملفات PHP تحت `resources/lang/<code>/messages.php` و`new-messages.php`، والدالة `translate()` **تكتب** المفاتيح الناقصة في `new-messages.php` وقت التشغيل (`lavkoo:app/Utils/language.php:28-43`). محتوى الكيانات (منتجات، أصناف، علامات …) في جدول `translations` متعدد الأشكال بعمود `locale` (`lavkoo:app/Models/Translation.php:21-27`) يُحمَّل عبر global scope يختار اللغة بفحص نصّي للـ URL: `strpos(url()->current(), '/api')` ⇒ `App::getLocale()` وإلا `getDefaultLanguage()` (`lavkoo:app/Models/Product.php:506-512`، `lavkoo:app/Models/Category.php:165-172`). أُحصي 19 موضعاً لهذا الفحص في 12 ملفاً، و14 مطابقة لـ `->segment(` في 8 ملفات منها 11 استخداماً فعلياً لـ `segment(1)` (مثل `lavkoo:app/Models/Product.php:419-426`). الحالة: `VERIFIED`.

**(هـ) هل يوجد اليوم أي مفهوم لدولة/سوق/Storefront؟** سُجّل البحث التالي في `app`، `Modules`، `config`، `routes`، `database`: `storefront_id`، `market_id`، `country_id`، `legal_entity`، `store_code`، `market_code`، `current_country`، `currentStore`، `StoreContext`، `MarketContext` ⇒ صفر نتائج لكلٍّ منها؛ `tenant` ⇒ 3 مطابقات كلها في تعليقات؛ `store_id` ⇒ 15 مطابقة كلها لمعرّف تاجر SSLCommerz أو لعمود في `order_taxes` (`lavkoo:Modules/TaxModule/app/Services/CalculateTaxService.php:276`). لا يوجد middleware ولا ترويسة ولا بادئة مسار ولا عمود يحمل «متجر الدولة». الموجود فعلاً هو أبعاد جزئية داخل أنظمة فرعية:

| العنصر | ما يحمله | الدليل |
|---|---|---|
| وحدة الضرائب | `country_code` اختياري في `system_tax_setups` و`taxes`، ويُنسخ إلى `order_taxes` | `lavkoo:Modules/TaxModule/database/migrations/2025_05_26_115043_create_system_tax_setups_table.php:19`، `lavkoo:Modules/TaxModule/app/Services/CalculateTaxService.php:269` |
| العنوان الموحَّد | `address_regions.country_code` بقيمة افتراضية `'SA'` | `lavkoo:database/migrations/2026_11_07_100000_create_address_master_tables.php:24` |
| حسابات القنوات | `market` و`marketplace_ids` و`currency` لكل حساب | `lavkoo:database/migrations/2026_11_07_100100_create_sales_channel_tables.php:27-31` |
| محاولات MyFatoorah | `expected_currency` و`captured_currency` لكل محاولة | `lavkoo:database/migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php:56-60` |
| قيود التوصيل | جدول `delivery_country_codes` + إعداد `delivery_country_restriction` | `lavkoo:database/migrations/2022_10_18_130956_create_delivery_country_codes_table.php:16-20`، `lavkoo:app/Http/Controllers/Web/UserProfileController.php:106-110` |
| التحليلات | دولة الزائر من ترويسة يضبطها CDN، ومعطّلة افتراضياً (`analytics_store_country = false`) | `lavkoo:app/Services/Analytics/VisitorContext.php:187-203`، `lavkoo:app/Services/Platform/PolicyRegistry.php:304-305` |

تنبيه اصطلاحي: كلمة «Storefront» مستعملة اليوم بمعنى «واجهة المتجر الذاتية مقابل قنوات البيع» (`StorefrontStockRefresher`، `StorefrontThemeRenderer`، `storefront_reserve_mode` في `lavkoo:database/migrations/2026_11_10_100000_add_stock_allocation.php:35`)، و`storefront_code` إعداد خاص بحساب Trendyol قيمه `SA`/`AE`/`KW` (`lavkoo:app/Services/SalesChannels/Support/ChannelSettings.php:14`)، و«Marketplace» تعني سوق البائعين المتعددين (72 ملفاً). الحالة: `VERIFIED`.

### 2.4 المنطقة الزمنية وأكواد الهاتف والدولة الافتراضية والخريطة

**المنطقة الزمنية.** توجد ثلاث «ساعات» في العملية الواحدة:

1. `config('app.timezone')` قيمة ثابتة `'Asia/Dhaka'` بلا متغير بيئة (`lavkoo:config/app.php:69`)؛
2. إعداد `timezone` في `business_settings` يُقرأ باستعلام مباشر عند كل إقلاع ويُطبَّق بـ `date_default_timezone_set()`، ويُكتب في `config('timezone')` لا في `config('app.timezone')` (`lavkoo:app/Providers/ConfigServiceProvider.php:30-34`)؛
3. نظام المراقبة يعمل على UTC حصراً (`lavkoo:app/Services/Monitoring/Support/Clock.php:24`).

الكود يوثّق أثر ذلك على هذا التثبيت: «`app.timezone` set to Asia/Dhaka while the PHP process default is Asia/Kuwait» (`lavkoo:app/Services/Monitoring/Support/Clock.php:10-14`، `lavkoo:app/Services/Monitoring/Panels/QueuesPanel.php:750-752`). تواريخ Eloquent تُكتب كتوقيت حائطي (wall-clock) بلا إزاحة وتُقرأ بمنطقة التطبيق، وتعليق السمة يصف ذلك ويحوّل لحظات القنوات قبل الكتابة (`lavkoo:app/Models/Concerns/StoresLocalTime.php:8-9`). قائمة الخيارات تضم `Asia/Kuwait` («Kuwait, Riyadh, Baghdad») و`Asia/Muscat` («Abu Dhabi, Muscat») (`lavkoo:app/Enums/GlobalConstant.php:1206`، `lavkoo:app/Enums/GlobalConstant.php:1211`). المنطقة الزمنية واحدة للتثبيت كله، وتُرسل للتطبيق في `system_timezone` (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:253`). الحالة: `VERIFIED` للكود؛ قيمة الإنتاج `INFERRED` من التعليقات (Asia/Kuwait).

**أكواد الهاتف.** إعداد عام واحد `country_code` يحدّد الدولة الافتراضية لحقل الهاتف في الواجهات (يُكتب في `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:138`، يُقرأ في `lavkoo:resources/themes/default/layouts/front-end/app.blade.php:211` برجوع ثابت إلى `'us'`، ويُرسل للتطبيق في `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:212`). قائمتا الدول وأكواد الاتصال ثابتتان في الكود (`lavkoo:app/Enums/GlobalConstant.php:9`، `lavkoo:app/Enums/GlobalConstant.php:256`). أما التطبيع فسعودي صريح في الأنظمة الأحدث: `SaudiPhone::normalize()` لا يقبل إلا `5XXXXXXXX` بعد إزالة `966` (`lavkoo:app/Services/Fulfillment/Support/SaudiPhone.php:31-38`)، و`splitSaudiPhone()` يُرجع كود الدولة `'966'` لكل رقم مقبول الطول (`lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:536-558`)، ومستلم طلبات القنوات يُرفض إن لم يكن جوالاً سعودياً أو لم تكن الدولة `SA` (`lavkoo:app/Services/SalesChannels/Dispatch/ChannelRecipient.php:41-52`). الحالة: `VERIFIED`.

**الدولة الافتراضية.** لا يوجد إعداد عام باسم «الدولة الافتراضية». الافتراض مضمَّن في مواضع متفرقة: `AddressCountry::LEGACY_NAME = 'Saudi Arabia'` مع قائمة تهجئات (`lavkoo:app/Services/Address/AddressCountry.php:13-15`)، القيمة الافتراضية `'SA'` لعمود `address_regions.country_code` (`lavkoo:database/migrations/2026_11_07_100000_create_address_master_tables.php:24`)، مستورد العناوين (`lavkoo:app/Services/Address/AddressImporter.php:140`)، مزوّد M5AZN (`lavkoo:config/fulfillment.php:130-132`، `lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznAdapter.php:86`). الحالة: `VERIFIED`.

**الخريطة.** إعداد عام `default_location` (خط عرض/طول) يُكتب مع الإعدادات العامة (`lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:152-153`) ويُرسل للتطبيق (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:256`)؛ الرجوع الثابت في العروض عند غيابه هو `-33.8688, 151.2195` (`lavkoo:resources/themes/default/web-views/users-profile/account-address.blade.php:373-374`). مفاتيح الخرائط `map_api_status`/`map_api_key`/`map_api_key_server` عامة كذلك. الحالة: `VERIFIED`.

### 2.5 الجلسة ومعادلاتها في الـ API

**الإعداد.** السائق الافتراضي `file`، ملف تعريف الارتباط واحد باسم مشتق من `APP_NAME` وبمسار `'/'` (`lavkoo:config/session.php:21`، `lavkoo:config/session.php:127-130`، `lavkoo:config/session.php:143`). أي أن مسارات `/sa` و`/ae` المقترحة على النطاق الواحد ستتشارك جلسة واحدة ما لم يُغيَّر ذلك. مجموعة `api` بلا جلسة (`lavkoo:bootstrap/app.php:84-94`)؛ استدعاء `session()` فيها يعمل على مخزن في الذاكرة لا يُحفظ بين الطلبات (`INFERRED` من سلوك الإطار).

**مفاتيح الجلسة المتصلة بالسياق** (من أصل 73 مفتاحاً حرفياً):

| المجال | المفاتيح | مواضع الكتابة/القراءة الأساسية |
|---|---|---|
| العملة | `system_default_currency_info`، `currency_code`، `currency_symbol`، `currency_exchange_rate`، `usd`، `default` | `lavkoo:app/Utils/currency.php:27-39`، `lavkoo:app/Utils/Helpers.php:322-344`، `lavkoo:app/Http/Controllers/Web/CurrencyController.php:20-25` |
| اللغة | `local`، `direction`، `language_settings`، (`locale` مقروء فقط) | `lavkoo:app/Utils/language.php:108-126`، `lavkoo:app/Http/Controllers/SharedController.php:30-33`، `lavkoo:app/Utils/Helpers.php:346-355` |
| هوية الضيف | `guest_id` (معرّف صف في `guest_users` يُنشأ عند أول طلب غير مسجَّل) | `lavkoo:app/Http/Middleware/GuestMiddleware.php:20-28` |
| السلة والدفع | `coupon_code`، `coupon_type`، `coupon_bearer`، `coupon_discount`، `coupon_seller_id`، `payment_method`، `shipping_method_id`، `address_id`، `billing_address_id`، `order_id`، `cart_group_id`، `order_note`، `payment_mode`، `newCustomerRegister` | `lavkoo:app/Utils/CartManager.php:372-381`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:361-366`، `lavkoo:app/Utils/module-helper.php:55` |
| قوائم العميل | `wish_list`، `compare_list`، `customer_fcm_topic` | `lavkoo:app/Utils/product.php:203`، `lavkoo:app/Utils/product.php:213`، `lavkoo:app/Utils/settings.php:267-268` |
| لوحات التحكم | `setup_guide_requirements_for_vendor_<id>`، `sc_density`، `theme_preview_version_id`، `seller_staff_id` | `lavkoo:app/Utils/panel-helpers.php:65`، `lavkoo:app/Services/SellerCenter/Shell.php:20`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:50` |

السلة نفسها في قاعدة البيانات (`carts`) وتُحدَّد بـ `customer_id` مع `is_guest` فقط؛ للضيف تكون القيمة `session('guest_id')` أو `guest_id` القادم في الطلب (`lavkoo:app/Utils/CartManager.php:163-173`). لا يدخل أي بُعد للمتجر في أي مفتاح جلسة ولا في تعريف السلة. الحالة: `VERIFIED`.

**معادلات الـ API.**

| الغرض | الآلية | الدليل |
|---|---|---|
| اللغة | ترويسة `lang` | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23` |
| العميل المسجَّل | `Authorization: Bearer` عبر الحارس `api` | `lavkoo:app/Http/Middleware/APIGuestMiddleware.php:21-24` |
| الضيف | وسيط الطلب `guest_id` الذي يحصل عليه التطبيق من `GET api/v1/get-guest-id` | `lavkoo:app/Http/Middleware/APIGuestMiddleware.php:26-28`، `lavkoo:routes/rest_api/v1/api.php:530` |
| العملة | لا ترويسة؛ التطبيق يحوّل محلياً، ويرسل `current_currency_code` عند الدفع فقط | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:417` |
| لغة صفحة الدفع | ترويسة `X-Locale` (MyFatoorah) | `lavkoo:app/Http/Controllers/Payment_Methods/MyFatoorahController.php:66` |
| المنصة والإصدار | `X-Platform`، `X-App-Version` (للمراقبة والتحليلات) | `lavkoo:app/Http/Middleware/MonitorRequest.php:100-125` |
| قدرات الواجهة | `X-UI-Components`/`X-UI-Engine`/`X-UI-Schema`/`X-UI-Channel`؛ وترويسة `Vary` لا تتضمن أي بُعد للمتجر | `lavkoo:app/Services/Theme/ViewerContext.php:76-91`، `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:210` |

بحث مسجَّل عن أي ترويسة سياق: `x-currency`، `x-country`، `x-store`، `x-market`، `x-storefront`، `x-region`، `header('currency`، `header('country` في `app`، `Modules`، `routes`، `config` ⇒ صفر نتائج. الحالة: `VERIFIED`.

### 2.6 الكاش

**الإعداد.** مخزن واحد افتراضي (`CACHE_DRIVER` وافتراضيه `file`) وبادئة واحدة مشتقة من `APP_NAME` (`lavkoo:config/cache.php:21`، `lavkoo:config/cache.php:101`). لا توجد وسوم (tags) ولا مخازن مسمّاة لكل مجال؛ ومحدِّد المعدّل (rate limiter) بلا مخزن مستقل في هذا الملف. سائق الإنتاج `UNKNOWN` (يتطلب `.env`).

**نمط الإبطال.** دالة مركزية `cacheRemoveByType($type)` تمسح قوائم مفاتيح ثابتة لكل «نوع» (`lavkoo:app/Utils/settings.php:433-547`) وتُستدعى في 46 موضعاً ضمن 22 ملف نموذج (منها أحداث `saved`/`deleted`). المفاتيح المتغيرة (حسب اللغة مثلاً) تُسجَّل في «حاويات» هي نفسها مدخلات كاش بمدة ثلاث ساعات (`lavkoo:app/Utils/settings.php:422-431`، `lavkoo:app/Utils/CategoryManager.php:152-157`). وفوق ذلك يوجد إبطال شامل للمخزن كله في 17 موضعاً ضمن كود المتحكمات والخدمات، منها: سبعة `cache()->flush()` (ستة متحكمات إدارية وخدمة السياسات: `lavkoo:app/Services/Platform/Policy.php:119`، `lavkoo:app/Http/Controllers/Admin/Marketplace/ExchangeRateController.php:50`، `lavkoo:app/Http/Controllers/Admin/Marketplace/SellerVerificationController.php:136`، `lavkoo:app/Http/Controllers/Admin/Marketplace/CategoryGovernanceController.php:75`، `lavkoo:app/Http/Controllers/Admin/Marketplace/SlaController.php:86`، `lavkoo:app/Http/Controllers/Admin/Settings/AbandonedCartSettingsController.php:69`، `lavkoo:app/Http/Controllers/Admin/Settings/AuthenticationSecurityController.php:82`)، واثنان من `Cache::clear()` ومُدرجان في الملحق المقيّد (انظر `SEC-E02-01`)، وثمانية استدعاءات `Artisan` لأمري `cache:clear` أو `optimize:clear` في ست ملفات ضمن خدمة الإضافات ومتحكمات الإعدادات والتحديث (منها `lavkoo:app/Services/AddonService.php:130`، `lavkoo:app/Services/AddonService.php:291`، و`lavkoo:app/Http/Controllers/UpdateController.php:64`؛ ومسارات التحديث معطّلة حالياً في `lavkoo:app/Providers/RouteServiceProvider.php:57-58`). الحالة: `VERIFIED`.

**جرد مفاتيح الكاش في `app` و`Modules`.** عمود «الأبعاد» يذكر ما يدخل فعلاً في نص المفتاح.

| # | المفتاح أو النمط | المدة | المحتوى | الإبطال | الأبعاد | الدليل |
|---|---|---|---|---|---|---|
| 1 | `cache_business_settings_table` | 3 س | كل صفوف `business_settings` | نوع `business_settings` + `clearWebConfigCacheKeys()` | لا شيء | `lavkoo:app/Utils/settings.php:26-28`، `lavkoo:app/Utils/settings.php:54-60` |
| 2 | `cache_login_setup_table` | 3 س | كل صفوف `login_setups` | نوع `login_setups` | لا شيء | `lavkoo:app/Utils/settings.php:227-229` |
| 3 | `cache_in_house_shop_table` | 3 س | صف متجر الإدارة | نوع `in_house_shop` | لا شيء | `lavkoo:app/Utils/settings.php:39-45` |
| 4 | `in_house_shop_temporary_close_status` | 1440 ث | حالة الإغلاق المؤقت | يُمسح ويُعاد بناؤه عند كل إقلاع | لا شيء | `lavkoo:app/Traits/CacheManagerTrait.php:501-509` |
| 5 | `cache_for_currency_table` | 3 س | `Currency::all()` (يغذّي `/api/v1/config`) | نوع `currencies` (أحداث النموذج فقط) | لا شيء | `lavkoo:app/Traits/CacheManagerTrait.php:53-58`، `lavkoo:app/Utils/settings.php:447-448` |
| 6 | `active_currency_list` | 3600 ث | العملات النشطة (ترويسة الويب) | `CurrencyRepository` عند الإضافة/التعديل/الحذف | لا شيء | `lavkoo:app/Utils/currency.php:13-18`، `lavkoo:app/Repositories/CurrencyRepository.php:65-70` |
| 7 | `myfatoorah.active_currencies` | 5 د | رموز العملات النشطة | `flushCurrencyCache()` | لا شيء | `lavkoo:app/Services/MyFatoorah/GatewayAvailability.php:102-122` |
| 8 | `cache_banner_table`، `cache_banner_type_<type>_<theme>` | 3 س | البنرات المنشورة | نوع `banners` | اسم الثيم (ثابت `default`) | `lavkoo:app/Traits/CacheManagerTrait.php:40-44`، `lavkoo:app/Services/BannerPlacementService.php:81-85` |
| 9 | `cache_main_categories_list` | 3 س | شجرة الأصناف مع المنتجات والترجمات | نوع `categories` | لا شيء (والترجمة بلغة لحظة الملء) | `lavkoo:app/Traits/CacheManagerTrait.php:134-151` |
| 10 | `cache_main_categories_list_<lang>_<offer_type>_<dataForm>_<data_from>` | 3 س | أصناف صفحات القوائم | حاوية مفاتيح اللغة | اللغة + وسائط الطلب | `lavkoo:app/Utils/CategoryManager.php:146-159` |
| 11 | `cache_home_categories_list` | 3 س | أصناف الصفحة الرئيسية ومنتجاتها | نوع `categories` | لا شيء | `lavkoo:app/Traits/CacheManagerTrait.php:153-192` |
| 12 | `cache_home_categories_api_list<locale>` | 3 س | أصناف الرئيسية للتطبيق | يُمسح فهرس المفاتيح فقط لا المدخلات (`lavkoo:app/Utils/settings.php:453`) | اللغة | `lavkoo:app/Http/Controllers/RestAPI/v1/ProductController.php:403-410` |
| 13 | `cache_priority_wise_brands_list` و`cache_priority_wise_brands_list_<lang>_<offer_type><data_form><segment1>` | 3 س | العلامات مرتبة | نوع `brands` + حاوية اللغة | الخارجي: لا شيء؛ الداخلي: اللغة + أول مقطع في الـ URL | `lavkoo:app/Traits/CacheManagerTrait.php:202-207`، `lavkoo:app/Utils/BrandManager.php:16-25` |
| 14 | `category_page_sub_categories_<id>_<locale>`، `brand_page_categories_<id>_<locale>` | 3 س | أصناف فرعية/أصناف علامة | حاوية اللغة | معرّف الكيان + اللغة | `lavkoo:app/Services/CategoryPageService.php:89-96`، `lavkoo:app/Services/BrandPageService.php:57-64` |
| 15 | `cache_for_home_page_latest_product_list`، `…_top_rated_…`، `…_best_sell_…`، `…_just_for_you_…`، `cache_for_random_single_product` (10 د)، `cache_for_most_demanded_product_item`، `cache_for_in_house_all_products`، `cache_for_all_products_review_list` | 3 س | مجموعات منتجات كاملة مع علاقاتها | نوع `products` | لا شيء | `lavkoo:app/Traits/CacheManagerTrait.php:360-435`، `lavkoo:app/Traits/CacheManagerTrait.php:74-79`، `lavkoo:app/Traits/CacheManagerTrait.php:209-214` |
| 16 | `cache_shops_table`، `cache_for_home_page_top_vendors_list`، `cache_for_home_page_more_vendors_list` | 3 س | المتاجر والبائعون وتقييماتهم | أنواع `shops`/`sellers` | لا شيء | `lavkoo:app/Traits/CacheManagerTrait.php:81-110`، `lavkoo:app/Traits/CacheManagerTrait.php:265-358` |
| 17 | `cache_for_products_max_unit_price`، `cache_for_products_min_unit_price` | 3 س | أقصى/أدنى `unit_price` كما هو مخزَّن | نوع `products` | لا شيء (ولا العملة) | `lavkoo:app/Utils/product.php:224-226`، `lavkoo:app/Utils/product.php:252-254` |
| 18 | `cache_flash_deal_<id أو default>` | 3 س | عرض الفلاش ومنتجاته | حاوية `cache_flash_deal_keys` | معرّف العرض | `lavkoo:app/Utils/ProductManager.php:1626-1634` |
| 19 | `cache_for_Featured_deals_products_list_<lang>` | 3 س | منتجات العرض المميّز | حاوية | اللغة | `lavkoo:app/Utils/product.php:285-292` |
| 20 | `cache_cart_list_for_customer_<id>` / `cache_cart_list_for_guest_<id>` | 3 س | عناصر سلة مستخدم | أنواع `carts`/`products` | معرّف العميل أو الضيف (انظر `E02-F17`) | `lavkoo:app/Utils/ProductManager.php:2584-2595` |
| 21 | `product-shop-close-<productId>` | 3600 ث | إغلاق متجر البائع لمنتج | لا يوجد إبطال موجَّه | معرّف المنتج | `lavkoo:app/Models/Product.php:316-327` |
| 22 | `cache_for_vendor_wise_products_count_<sellerId>` | 3 س | عدد منتجات بائع | حاوية | معرّف البائع | `lavkoo:app/Utils/panel-helpers.php:54-63` |
| 23 | `cache_for_admin_sidebar_all_product_list`، `cache_for_in_house_all_product_list` | 7 أيام | عدّادات لوحة الإدارة | نوع `products` | لا شيء | `lavkoo:app/Utils/product.php:142-145`، `lavkoo:app/Utils/product.php:178-180` |
| 24 | `cache_tags_table`، `cache_help_topics_table`، `cache_for_all_color_list`، `cache_for_all_products_color_list`، `Cache_robots_meta_content_table`، `cache_for_business_page_list`، `cache_for_analytic_script_active_list`، `cache_clearance_sale_setup_table`، `cache_for_clearance_sale_products_count`، `cache_for_in_house_shipping_type` | 3 س | جداول مرجعية كاملة | النوع المقابل لكل منها (جدول إعداد التصفية بلا إبطال) | لا شيء | `lavkoo:app/Traits/CacheManagerTrait.php:60-72`، `lavkoo:app/Traits/CacheManagerTrait.php:112-132`، `lavkoo:app/Traits/CacheManagerTrait.php:216-263`، `lavkoo:app/Traits/CacheManagerTrait.php:512-545` |
| 25 | `_schema_has_business_settings` وأخواه (6 س)، `_product_authors_list`، `_total_discount_products`، قوائم دور النشر (1 س) | — | فحوص المخطط وعدّادات الإقلاع | بلا إبطال أو مع نوع `products` | لا شيء | `lavkoo:app/Providers/AppServiceProvider.php:179-181`، `lavkoo:app/Providers/AppServiceProvider.php:192-193`، `lavkoo:app/Providers/AppServiceProvider.php:259-266` |
| 26 | `system_maintenance_mode` | سنة | **حالة** وضع الصيانة نفسها | `forget` عند الإطفاء فقط | لا شيء | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:255-268`، `lavkoo:app/Traits/MaintenanceModeTrait.php:14` |
| 27 | `storefront_theme_page_<page>`، `storefront_theme_global_settings` | 600 ث | أقسام الثيم المنشور وإعداداته | `flush()` عند النشر | اسم الصفحة فقط | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:25-27`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:88-92`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:364-380` |
| 28 | `theme_delivery_revision`، `theme_delivery_<page>_<fingerprint>[_c…][_x…]` | 600 ث | حمولة الثيم للتطبيق | تغيّر المراجعة + `flush()` | الصفحة + (checksum، المنصة، الجهاز، الجمهور، الشرائح، اللغة، إصدارات الواجهة، المكوّنات) | `lavkoo:app/Services/Theme/ThemeDelivery.php:128-133`، `lavkoo:app/Services/Theme/ThemeDelivery.php:650-672` |
| 29 | `theme_publish_check_<versionId>`، `theme_section_reach` | 300 ث | نتيجة فحص النشر، إحصاء الوصول | `forget` للنسخة | معرّف النسخة / لا شيء | `lavkoo:app/Services/Theme/PublishValidator.php:59-74`، `lavkoo:app/Services/Theme/SectionReach.php:47` |
| 30 | `commerce_campaign_overrides_<page>` (30 ث)، `commerce_experiments_<page>` (60 ث)، `commerce_experiment_reach` (300 ث)، `commerce_segment_keys` و`commerce_segments_live` (60 ث) | — | الحملات والتجارب والشرائح | `forget()` موجَّه | الصفحة أو لا شيء | `lavkoo:app/Services/Commerce/CampaignResolver.php:43-47`، `lavkoo:app/Services/Commerce/ExperimentResolver.php:83`، `lavkoo:app/Services/Commerce/SegmentResolver.php:90` |
| 31 | `commerce_segment_ctx_<customerId>` | 600 ث | مقاييس العميل (عدد الطلبات، إجمالي الإنفاق من كل الطلبات) | انتهاء المدة | معرّف العميل | `lavkoo:app/Services/Commerce/SegmentResolver.php:105-112` |
| 32 | `bought_together_<productId>_<limit>` | 6 س | منتجات تُشترى معاً | انتهاء المدة | معرّف المنتج | `lavkoo:app/Services/Storefront/BoughtTogetherService.php:26`، `lavkoo:app/Services/Storefront/BoughtTogetherService.php:88-90` |
| 33 | `sc:counts:<sellerId>:<staffId>` (60 ث)، `v2_sidebar_pins:<userType>:<userId>` (3600 ث) | — | شارات لوحة البائع، تثبيتات القائمة | `forget` موجَّه | البائع/الموظف، المستخدم | `lavkoo:app/Services/SellerCenter/Counts.php:27-39`، `lavkoo:app/Models/V2SidebarPin.php:45-56` |
| 34 | `tax_system_type_<tax_payer>_<with_vat أو no_vat>` (30 د) + حاوية (7 أيام) | — | إعداد الضريبة الافتراضي والنِّسب | `SystemTaxSetupService` | دافع الضريبة (بلا دولة) | `lavkoo:app/Utils/module-helper.php:716-726` |
| 35 | `fcm_access_token_<md5(client_email)>` (3300 ث)، `active_ai_provider` (60 ث)، `seo_active_redirects` (300 ث)، `developer_portal:manifest` (يوم)، `app_version_info` و`app_release_version` (300 ث)، `SYSTEM_DOMAIN_POINTED_DIRECTORY_<md5>` (دائم)، `cache_all_files_for_<disk>_storage` (10 د)، `cache_for_recent_file_list_<disk>` (3 س)، `cache_translations_table_for_advance_search` (يومان، كل جدول الترجمات)، `channel-rate:<key>:<window>`، `sales_channels:stock-events:nudged`، `odoo:chart:<accountId>:<companyId>`، قفل `provider-city-sync:torod_point`، ومفاتيح مجمِّعات المراقبة | — | بنية تحتية | متنوّع | حساب الخدمة / القرص / الحساب أو لا شيء | `lavkoo:app/Traits/PushNotificationTrait.php:507-509`، `lavkoo:app/Utils/settings.php:238`، `lavkoo:app/Http/Middleware/ApplySeoRedirects.php:77`، `lavkoo:app/Services/DeveloperPortal/ApiManifest.php:32-33`، `lavkoo:app/Utils/version.php:14`، `lavkoo:app/Utils/module-helper.php:810-811`، `lavkoo:app/Packages/AdvanceSearch/AdvanceSearch.php:228-230`، `lavkoo:app/Services/SalesChannels/Support/ChannelRateLimiter.php:20-21`، `lavkoo:app/Http/Controllers/Admin/Accounting/OdooMappingController.php:226-229` |

**خلاصة الجرد:** لا يحتوي أي مفتاح على بُعد دولة أو متجر أو عملة. بُعد اللغة حاضر في بعض المفاتيح (الصفوف 10، 12، 13 الداخلي، 14، 19، 28) وغائب عن مفاتيح أخرى تخزّن المحتوى المترجَم نفسه (الصفوف 9، 11، 13 الخارجي، 15): نماذج `Product` و`Category` و`Brand` تُحمَّل معها علاقة `translations` مفلترة بلغة الطلب الذي ملأ الكاش (`lavkoo:app/Models/Product.php:506-512`)، ثم تُقدَّم من الصفحة الرئيسية لكل الزوار (`lavkoo:app/Http/Controllers/Web/HomeController.php:34-38`). بعض ثوابت المفاتيح تُمسح ولا تُكتب أبداً (مثل `CACHE_FOR_FEATURED_PRODUCTS_LIST` و`CACHE_ORDER_DETAILS_TABLE`: موضع وحيد لكلٍّ منهما في `lavkoo:app/Utils/settings.php:474` و`lavkoo:app/Utils/settings.php:512`). الحالة: `VERIFIED` للمفاتيح؛ أثر التسريب بين اللغات `INFERRED`.

### 2.7 الطوابير والأحداث والإشعارات والـ webhooks

- **الوظائف (12 ملفاً في `app/Jobs`):** حمولاتها معرّفات فقط — `orderId`، `fulfillmentId`، `eventId`، `bulkJobId`، `deliveryId`، `limit` — بلا لغة ولا عملة ولا متجر (`lavkoo:app/Jobs/Fulfillment/RouteOrderFulfillment.php:21`، `lavkoo:app/Jobs/Fulfillment/DispatchProviderFulfillment.php:28`، `lavkoo:app/Jobs/Fulfillment/ProcessProviderEvent.php:27`، `lavkoo:app/Jobs/RunSellerBulkJob.php:33`، `lavkoo:app/Jobs/DeliverSellerWebhook.php:29`، `lavkoo:app/Jobs/SalesChannels/DrainStockEvents.php:37`)؛ و`SendEmailJob` يحمل العنوان وكائن البريد (`lavkoo:app/Jobs/SendEmailJob.php:24-28`). الحالة: `VERIFIED`.
- **الطوابير:** الاتصال الافتراضي في ملف الإعداد `sync` (قيمة الإنتاج `UNKNOWN`)، وأسماء الطوابير المستعملة اثنان: `default` و`fulfillment` (`lavkoo:config/queue.php:16`، `lavkoo:config/fulfillment.php:71-74`، `lavkoo:app/Services/Fulfillment/FulfillmentQueue.php:78`). لا يوجد طابور لكل سوق. الحالة: `VERIFIED`.
- **الأحداث والمستمعون:** 24 ملف حدث في `app/Events` و26 ملفاً في `app/Listeners`، 17 منها تتضمن `ShouldQueue`. الأحداث تحمل `email` ومصفوفة `data` (`lavkoo:app/Events/AddFundToWalletEvent.php:20`، `lavkoo:app/Events/OrderPlacedEvent.php:18-21`). قالب البريد يُختار بلغة `getDefaultLanguage()` وقت التنفيذ (`lavkoo:app/Models/EmailTemplate.php:84-87`، `lavkoo:app/Traits/EmailTemplateTrait.php:36`)؛ داخل عامل الطابور لا توجد جلسة مستخدم فتُحلّ اللغة إلى لغة المتجر الافتراضية (`INFERRED` من `lavkoo:app/Utils/language.php:106-128`). كذلك يبقى مخزن `session()` في ذاكرة العامل بين الوظائف، فتبقى قيم مثل `usd` و`default` و`local` من وظيفة سابقة (`INFERRED` من سلوك الإطار؛ `loadCurrency()` لا يعيد التحميل إلا إذا تغيّر معرّف العملة الافتراضية: `lavkoo:app/Utils/currency.php:27-29`).
- **الإشعارات الفورية:** اللغة من `app_language` للمستلم، والمبالغ بالعملة الافتراضية العامة (`lavkoo:app/Traits/PushNotificationTrait.php:29-33`، `lavkoo:app/Traits/PushNotificationTrait.php:233`). مواضيع FCM العامة ثابتة: `sixvalley` و`maintenance_mode_start_user_app` (`lavkoo:app/Utils/settings.php:264-269`). الحالة: `VERIFIED`.
- **رجوع بوابات الدفع و webhooks:** السياق يُستعاد من حقل `additional_data` في طلب الدفع (العميل/الضيف، العنوان، الكوبون، `payment_mode`) ويُعاد حقن `guest_id` في الجلسة (`lavkoo:app/Utils/module-helper.php:22`، `lavkoo:app/Utils/module-helper.php:101-115`)؛ العملة تُحمل في `currency_code` لطلب الدفع (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:425-428`، `lavkoo:app/Utils/module-helper.php:305-306`). لا يوجد حقل متجر/دولة. الحالة: `VERIFIED`.

### 2.8 جرد مفاتيح `business_settings` وتصنيفها

209 مفاتيح حرفية (بعد استبعاد 4 إيجابيات كاذبة) + 73 مفتاح سياسة. يوجد كذلك ما لا يقلّ عن ستة مواضع تقرأ بمفتاح متغيّر (`lavkoo:app/Services/Platform/Policy.php:169`، `lavkoo:app/Services/Marketplace/SlaService.php:207`، `lavkoo:app/Services/Marketplace/SellerVerificationService.php:260`، `lavkoo:app/Services/Marketplace/CategoryGovernanceService.php:103`، `lavkoo:app/Services/Retention/AbandonedCartService.php:232`، `lavkoo:app/Services/Address/AddressSchema.php:111`)، فالقائمة حدّ أدنى لا حصر. التصنيف المقترح توثيقي فقط.

| المجموعة (العدد) | المفاتيح | التصنيف المقترح لـ GCC | السبب في سطر |
|---|---|---|---|
| هوية العلامة والتواصل (23) | `company_name`، `company_email`، `company_phone`، `shop_address`، `company_web_logo`، `company_web_logo_png`، `company_mobile_logo`، `company_footer_logo`، `company_fav_icon`، `loader_gif`، `company_copyright_text`، `colors`، `company_reliability`، `announcement`، `about_us`، `download_app_apple_store`، `download_app_google_store`، `download_app_apple_stroe`، `download_app_google_stroe`، `download_vendor_app`، `app_deep_link`، `whatsapp`، `messenger` | Global مع تخصيص Per-Storefront | GCC-10: هوية مشتركة افتراضياً مع تخصيص اسم العرض والهاتف والعنوان لكل متجر |
| الهوية القانونية والفاتورة (4) | `company_registration_no`، `company_vat_no`، `company_platform_no`، `invoice_settings` | Per-Legal-Entity | السجل التجاري والرقم الضريبي وبيانات الفاتورة خاصة بكل شركة بائعة |
| السياسات القانونية (5) | `refund-policy`، `return-policy`، `cancellation-policy`، `shipping-policy`، `cookie_setting` | Per-Storefront (بموافقة لكل دولة) | GCC-11: وثائق معتمدة ومنشورة لكل دولة قبل البيع |
| العملة والتنسيق (4) | `currency_model`، `system_default_currency`، `currency_symbol_position`، `decimal_point_settings` | `currency_model` يبقى Global؛ الباقي Per-Storefront | عملة وخانات عشرية ورمز لكل سوق؛ والنموذج العام لا يمثّل ذلك |
| اللغة والجغرافيا (5) | `language`، `pnc_language`، `timezone`، `country_code`، `default_location` | قائمة اللغات Global؛ الباقي Per-Country | المنطقة الزمنية وكود الهاتف وموقع الخريطة تتبع الدولة |
| الدفع (10) | `digital_payment`، `cash_on_delivery`، `offline_payment`، `paypal`، `paystack`، `paytm`، `razor_pay`، `flutterwave`، `ssl_commerz_payment`، `billing_input_by_customer` | Per-Legal-Entity (الحسابات) و Per-Storefront (التفعيل) | حسابات البوابات تُربط بالكيان القانوني، وتوافر الطرق يختلف بالسوق |
| المحفظة والولاء والإحالة (13) | `wallet_status`، `wallet_add_refund`، `add_funds_to_wallet`، `minimum_add_fund_amount`، `maximum_add_fund_amount`، `loyalty_point_status`، `loyalty_point_exchange_rate`، `loyalty_point_item_purchase_point`، `loyalty_point_minimum_point`، `loyalty_point_for_each_order`، `ref_earning_status`، `ref_earning_exchange_rate`، `ref_earning_customer` | Per-Storefront / Per-Legal-Entity | GCC-13 و GCC-15: محافظ وولاء محليان؛ والحدود مبالغ بعملة السوق |
| الطلب والشحن والتوصيل (22) | `shipping_method`، `minimum_order_amount`، `minimum_order_amount_status`، `minimum_order_amount_by_seller`، `minimum_order_limit`، `free_delivery_status`، `free_delivery_over_amount`، `free_delivery_over_amount_seller`، `free_delivery_responsibility`، `order_verification`، `refund_day_limit`، `guest_checkout`، `upload_picture_on_delivery`، `delivery_country_restriction`، `delivery_zip_code_area_restriction`، `zip_code_field_status`، `zone_wise_shipping`، `product_wise_shipping_cost_approval`، `vendor_can_edit_order`، `delivery_syria`، `delivery_syria_webhook_token`، `stock_limit` | Per-Storefront (والعتبات بعملته) | قواعد الشحن والحدود الدنيا والاسترجاع محلية لكل سوق (GCC-16) |
| البائعون والسوق (24) | `business_mode`، `seller_registration`، `seller_pos`، `sales_commission`، `new_product_approval`، `vendor_review_reply_status`، `vendor_registration_header`، `vendor_registration_sell_with_us`، `vendor_registration_faq_status`، `business_process_main_section`، `business_process_step`، `settlement_maker_checker`، `require_kyc_for_payout`، `kyc_required_documents`، `sla_max_cancellation_rate`، `sla_max_refund_rate`، `sla_max_return_rate`، `sla_min_rating`، `sla_processing_hours`، `temporary_close`، `vacation_add`، `shop_banner`، `offer_banner`، `bottom_banner` | `business_mode` Global؛ العمولة والتسوية و KYC Per-Legal-Entity؛ حالة متجر الإدارة Per-Storefront | التسويات والعمولات تتبع الكيان القانوني؛ إغلاق/إجازة المتجر الداخلي يتبع المتجر |
| الدخول والأمان (16) | `recaptcha`، `maximum_otp_hit`، `otp_resend_time`، `temporary_block_time`، `maximum_login_hit`، `temporary_login_block_time`، `forgot_password_verification`، `vendor_forgot_password_method`، `deliveryman_forgot_password_method`، `firebase_otp_verification`، `social_login`، `apple_login`، `email_verification`، `otp_verification`، `admin_login_url`، `employee_login_url` | Global | GCC-13 و GCC-14: هوية واحدة للعميل وللموظف عبر الدول |
| قنوات الاتصال (6) | `mail_config`، `mail_config_sendgrid`، `sms_nexmo`، `push_notification_key`، `fcm_credentials`، `fcm_project_id` | البنية Global؛ هوية المرسل Per-Storefront | تطبيق واحد ومشروع Firebase واحد، لكن اسم/عنوان المرسل والقوالب تتبع المتجر |
| التحليلات و SEO (7) | `pixel_analytics`، `google_tag_manager_id`، `google_search_console_code`، `bing_webmaster_code`، `baidu_webmaster_code`، `yandex_webmaster_code`، `sharethis_property_id` | Global (نطاق واحد) مع إمكان تخصيص لكل متجر | رموز التحقق لكل نطاق؛ والبكسلات قد تُفصل لكل سوق |
| العرض والترتيب (30) | 15 مفتاحاً بلاحقة `_priority` (عدا مفتاحَي المدونة) + `pagination_limit`، `product_brand`، `digital_product`، `features_section_top`، `features_section_middle`، `features_section_bottom`، `bought_together_auto_fill`، `bought_together_limit`، `bought_together_status`، `product_live_viewers_max`، `product_live_viewers_min`، `product_live_viewers_status`، `product_authenticity_badge_status`، `product_authenticity_badge_text`، `stock_clearance_vendor_offer_in_homepage` | Global مع تخصيص Per-Storefront | GCC-07 و GCC-09: تخصيص العرض لكل متجر فوق مكتبة مشتركة |
| النظام والصيانة (13) | `map_api_status`، `map_api_key`، `map_api_key_server`، `storage_connection_type`، `storage_connection_s3_credential`، `setup_guide_requirements_for_admin`، `scheduler_last_run_at`، `addon_activation_`، `addon_activation_delivery_man_app`، `maintenance_mode`، `maintenance_system_setup`، `maintenance_duration_setup`، `maintenance_message_setup` | البنية Global؛ الصيانة Per-Storefront | GCC-12: إيقاف لكل متجر بثلاثة أوضاع، لا مفتاح عام واحد |
| إصدارات التطبيقات (3) | `user_app_version_control`، `seller_app_version_control`، `delivery_man_app_version_control` | Global | تطبيق واحد للعميل وواحد للبائع لكل الدول |
| المدونة (12) | `blog_feature_active_status`، `blog_feature_title`، `blog_feature_sub_title`، `blog_list_priority`، `blog_category_list_priority`، وسبعة مفاتيح `blog_feature_download_*` | Global مع إمكان Per-Storefront | محتوى تسويقي قد يختلف بالسوق |
| المزاد (12) | `auction_feature_status` وثمانية مفاتيح `auction_*` أخرى، `auction_home_bg_image`، `active_auction_for_customer`، `active_auction_for_vendor` | Global (الوحدة غير موجودة في المستودع) | `getWebConfig('auction_feature_status')` يُرجع `null` ما لم تُنشر الإضافة (`lavkoo:app/Utils/settings.php:16-20`) |
| سجل السياسات (73 في 12 مجموعة) | `operations` (9)، `inventory` (7)، `catalog` (3)، `commerce` (9)، `shipping` (2)، `compliance` (11)، `security` (4)، `analytics` (12)، `developer` (4)، `integrations` (3)، `finance` (5)، `platform` (4) | Global عدا `finance` (Per-Legal-Entity) وربما `operations`/`shipping`/`compliance` (Per-Country) | حدود الدفعات مبالغ بعملة الكيان؛ مهل SLA والامتثال قد تختلف تنظيمياً بالدولة |

## 3. افتراضات المتجر الواحد / الدولة / العملة

### 3.1 افتراضات بنيوية (شيء واحد لكل التثبيت)

| الافتراض | أين يظهر | الدليل | الحالة |
|---|---|---|---|
| مجموعة إعدادات واحدة بمفتاح `type` فقط | `business_settings`، `login_setups`، `addon_settings`، `integration_settings` | `lavkoo:app/Models/BusinessSetting.php:13`، `lavkoo:app/Models/IntegrationSetting.php:11-18` | `VERIFIED` |
| عملة افتراضية واحدة ونموذج عملة واحد وعدد خانات واحد وموضع رمز واحد | أربعة مفاتيح عامة تقرؤها كل دوال العملة | `lavkoo:app/Utils/currency.php:27-39`، `lavkoo:app/Utils/currency.php:257-267` | `VERIFIED` |
| مبالغ بلا عملة | كل النماذج المالية الأساسية | بحث القسم 2.2(ب)؛ `lavkoo:app/Services/Fulfillment/Support/ProviderCurrency.php:8-9` | `VERIFIED` |
| منطقة زمنية واحدة للعملية | `ConfigServiceProvider` | `lavkoo:app/Providers/ConfigServiceProvider.php:30-34` | `VERIFIED` |
| ثيم واحد بمسار ثابت | `theme_root_path()` تُرجع `'default'`، ويُرسل للتطبيق في `active_theme` | `lavkoo:app/Utils/theme-helpers.php:16-19`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:231` | `VERIFIED` |
| متجر داخلي واحد للإدارة | `Shop::where('author_type', 'admin')->first()` | `lavkoo:app/Utils/settings.php:39-45` | `VERIFIED` |
| هوية بريد واحدة، حساب Firebase واحد، مواضيع FCM ثابتة | مزوّدات الإقلاع | `lavkoo:app/Providers/MailConfigServiceProvider.php:34-45`، `lavkoo:app/Providers/FirebaseServiceProvider.php:19-22`، `lavkoo:app/Utils/settings.php:264-269` | `VERIFIED` |
| مفتاح صيانة واحد | `system_maintenance_mode` | `lavkoo:app/Traits/MaintenanceModeTrait.php:14` | `VERIFIED` |
| جلسة واحدة ومخزن كاش واحد وبادئة واحدة | إعدادات الإطار | `lavkoo:config/session.php:143`، `lavkoo:config/cache.php:101` | `VERIFIED` |
| نقطة إعداد واحدة للتطبيق | `/api/v1/config` تُرجع هوية وعملة وطرق دفع وصيانة عامة | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:133-291` | `VERIFIED` |
| رموز اللغة = رموز دول | `getLanguageCode()`، مجلدات `resources/lang` | `lavkoo:app/Utils/language.php:416-434` | `VERIFIED` |

### 3.2 جرد الثوابت النصية

الأعداد «مطابقات/ملفات» من `rg` على ملفات `*.php`، `*.js`، `*.json`، `*.vue` مع استثناء مجلدات `lang` وملفات `*.min.js`. لا مطابقات في `Modules` لأيٍّ من الأنماط.

| النمط | `app` | `config` | `routes` | `resources/views` | `resources/themes` | `database` | `tests` |
|---|---|---|---|---|---|---|---|
| `\bSAR\b` | 32/19 | 5/3 | 0 | 1/1 | 0 | 1/1 | 594/134 |
| `Saudi\|SAUDI\|saudi` | 133/45 | 4/2 | 2/2 | 32/13 | 23/5 | 4/3 | 268/47 |
| `\+966\|\b966\b` | 8/6 | 0 | 0 | 0 | 3/1 (إيجابيات كاذبة: إحداثيات SVG) | 1/1 (إيجابية كاذبة: مرجع مدينة) | 81/22 |
| `'SA'\|"SA"` | 17/12 | 1/1 | 0 | 4/4 | 5/3 | 1/1 | 78/48 |
| `'sa'\|"sa"` | 10/8 | 0 | 0 | 1/1 | 2/1 | 0 | 48/25 |
| `\bKSA\b` | 6/5 | 5/2 | 0 | 3/2 | 0 | 1/1 | 111/49 |
| `Riyadh\|riyadh\|الرياض` | 13/8 | 0 | 0 | 0 | 0 | 7/5 | 649/87 |
| `Asia/Dhaka` | 8/8 | 1/1 | 0 | 0 | 0 | 0 | 4/2 |
| `Asia/Riyadh` | 0 | 0 | 0 | 0 | 0 | 0 | 4/2 |
| `\b15\s*%\|0\.15\b\|\b15\.0\b\|vat.{0,12}15\|15.{0,12}vat` | 8/6 (لا علاقة لها بالضريبة) | 0 | 0 | 9/6 (تنسيقات وملصق واحد) | 0 | 0 | 16/6 |
| `ZATCA\|zatca` | 0 | 0 | 0 | 0 | 0 | 0 | 0 |
| `USD` | 69/27 | 0 | 0 | 4/3 | 0 | 0 | 67/29 |
| `AED\|KWD\|QAR\|BHD\|OMR` | 36/6 | 0 | 0 | 0 | 0 | 0 | 8/5 |

**أمثلة ممثِّلة حسب الفئة:**

- **SAR كقيمة افتراضية في الإعداد والمخطط:** `lavkoo:config/fulfillment.php:59`، `lavkoo:config/myfatoorah.php:106`، `lavkoo:config/operational_costs.php:28`، `lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:57`.
- **SAR كرجوع ثابت في الكود:** `lavkoo:app/Services/Fulfillment/Support/CurrencyCode.php:29`، `lavkoo:app/Services/OperationalCosts/Support/FxConverter.php:50`، `lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:84`، `lavkoo:app/Services/SalesChannels/Amazon/AmazonConnector.php:586`، `lavkoo:app/Http/Controllers/Admin/Accounting/OdooReconciliationController.php:48`، `lavkoo:app/Console/Commands/OdooReconcileCommand.php:27`.
- **«KSA» كرمز للعملة أو للدولة:** مرادف العملة `lavkoo:config/fulfillment.php:67-69`؛ خريطة خوادم Paymob حسب الدولة (`egypt`، `PAK`، `KSA`، `oman`، `UAE`) في `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:24-30`.
- **السعودية كشرط منطقي:** `lavkoo:app/Services/Address/AddressCountry.php:13-22`، `lavkoo:app/Services/SalesChannels/Dispatch/ChannelRecipient.php:50-52`، `lavkoo:app/Services/SalesChannels/Dispatch/DispatchGate.php:96`، `lavkoo:config/address.php:5`، `lavkoo:resources/views/partials/address/_unified-address-fields.blade.php:40`.
- **`+966` والجوال السعودي:** `lavkoo:app/Services/Fulfillment/Support/SaudiPhone.php:31-38`، `lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:541-557`، `lavkoo:app/Services/SalesChannels/Dispatch/BuyerEchoScrubber.php:92`.
- **`'SA'`/`'sa'` كقيمة افتراضية لدولة مزوّد أو قناة:** `lavkoo:config/fulfillment.php:132`، `lavkoo:app/Services/Fulfillment/Providers/M5azn/M5aznAdapter.php:86`، `lavkoo:app/Services/SalesChannels/Trendyol/TrendyolClient.php:89`، `lavkoo:app/Services/SalesChannels/Support/ChannelSettings.php:54`.
- **الرياض:** لا منطق يعتمد عليها؛ خريطة مناطق SPL (`lavkoo:app/Services/Address/SplLookupsConverter.php:28`) واستعلام فحص (`lavkoo:app/Services/Fulfillment/Verification/TorodChecks.php:84-85`)، والباقي تعليقات وبيانات عناوين.
- **ضريبة 15%:** لم يُعثر على أي ثابت ضريبي 15% في `app`، `config`، `routes`؛ نسبة الضريبة تأتي من وحدة الضرائب ومن أعمدة المنتج. المطابقة الوحيدة ذات الصلة ملصق ثابت في تقرير إداري («GST (15%)»: `lavkoo:resources/views/admin-views/vat-tax/admin-tax-report/details.blade.php:130`).
- **المنطقة الزمنية:** `'Asia/Dhaka'` ثابتة في `lavkoo:config/app.php:69` وفي أمر التهيئة المحلي مع `country_code = 'BD'` (`lavkoo:app/Console/Commands/LocalDatabaseRefresh.php:30-31`)؛ لا ذكر لـ `Asia/Riyadh` خارج الاختبارات.
- **الدولار كعملة محورية:** `lavkoo:app/Utils/currency.php:121`، `lavkoo:app/Utils/currency.php:209-212`، `lavkoo:app/Utils/Convert.php:14`، `lavkoo:app/Utils/BackEndHelper.php:15`، والرجوع الثابت `'USD'` في `lavkoo:app/Traits/PaymentGatewayTrait.php:498` و`lavkoo:app/View/Composers/ProductSeoSupplementComposer.php:106`.
- **خانتان عشريتان ثابتتان:** `round(…, 2)` في 193 موضعاً (79 ملفاً) في `app` و14 موضعاً في `resources/views`؛ `number_format(…, 2)` في 13 سطراً في `app` و55 سطراً في `resources/views` (عدّ سطري بنمط `number_format(` مع `, 2)`)؛ مقابل 26 موضعاً فقط تقرأ `decimal_point_settings` في `app`. أمثلة: `lavkoo:app/Utils/BackEndHelper.php:50`، `lavkoo:app/Utils/Convert.php:62`، `lavkoo:app/Utils/Helpers.php:649`، `lavkoo:app/Utils/currency.php:308`، `lavkoo:app/Services/Fulfillment/Support/ProviderCurrency.php:45`.

الحالة العامة للجدول: `VERIFIED` (أعداد آلية؛ لم تُفحص كل مطابقة يدوياً، وفُحصت الأمثلة المستشهد بها).

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم في هذا المجال | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Foundation: six markets enabled independently | لا شيء؛ الإعدادات كلها عامة | لا يوجد كيان «متجر/دولة» ولا حالة تفعيل لكل سوق؛ 209 مفاتيح + 73 سياسة بلا نطاق | `lavkoo:app/Utils/settings.php:14-34`، القسم 2.3(هـ) |
| Foundation: one domain with `/sa /ae …` paths; manual choice beats IP | توليد الروابط في الثيم يعتمد على `route()` (694 موضعاً مقابل 8 `url()` حرفية) فيسهل حقن بادئة؛ لا يوجد استنتاج دولة من IP لواجهة المتجر (الحزمة `devrabiul/laravel-geo-genius` مطلوبة في `lavkoo:composer.json:25` لكن البحث عن `GeoGenius`/`geoip`/`ipinfo`/`cf-ipcountry` في `app`، `Modules`، `config`، `routes`، `bootstrap`، وملفات العرض لم يجد أي استعمال لها) | لا بادئة مسار؛ كشف السياق بشمّ الـ URL (`/api`، `segment(1)`)؛ الرموز `sa`/`ae` مستعملة أصلاً كرموز لغة؛ `$web_config` تُحسم قبل التوجيه | `lavkoo:app/Utils/language.php:106`، `lavkoo:app/Models/Product.php:419-426`، `lavkoo:app/Providers/AppServiceProvider.php:312-313` |
| Foundation: distinct concepts (Country / Storefront / Legal Entity …) | أبعاد جزئية: `country_code` في الضرائب والعناوين، `market`/`currency` في حسابات القنوات | تصادم مصطلحات: «Storefront» و«Marketplace» و`store_id` و`storefront_code` لها معانٍ قائمة مختلفة | `lavkoo:app/Services/SalesChannels/Support/ChannelSettings.php:14`، `lavkoo:Modules/TaxModule/app/Services/CalculateTaxService.php:276` |
| Foundation: separate legal selling company per country | حقول `company_registration_no`/`company_vat_no`/`invoice_settings` موجودة | قيمة واحدة عامة لكلٍّ منها؛ إعدادات البريد والدفع تُحمَّل مرة عند الإقلاع | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:140-143`، `lavkoo:app/Providers/PaymentConfigProvider.php:31-52` |
| Foundation / Provisional: local pricing, no auto-FX | وضع `single_currency` الحالي لا يحوّل | النموذج لا يعرف إلا «عملة واحدة للكل» أو «تحويل آلي للكل»؛ في `multi_currency` يُحوَّل مبلغ الدفع آلياً | `lavkoo:app/Utils/currency.php:50-67`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-419` |
| Provisional: wallets and ledgers separate by store/legal entity/currency | لا شيء | حركات المحفظة والولاء بلا عملة؛ تبديل النموذج يعيد تفسير الأرصدة | `lavkoo:app/Repositories/WalletTransactionRepository.php:137-138`، `lavkoo:app/Http/Controllers/Admin/Settings/CurrencyController.php:250-276` |
| GCC-07 (ثيم لكل دولة) | مفتاح كاش الثيم للتطبيق يتضمن اللغة والمنصة والشرائح | لا بُعد متجر في مفاتيح الثيم ولا في ترويسة `Vary`؛ مسار ثيم واحد ثابت | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:25-27`، `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:210` |
| GCC-10 (هوية مشتركة مع تخصيصات) | مفاتيح الهوية معزولة الأسماء ويمكن وضع تخصيص فوقها | `company_name` مقروء في 95 موضعاً مباشرةً؛ `$web_config` تُبنى عند الإقلاع | `lavkoo:app/Providers/AppServiceProvider.php:196-210` |
| GCC-11 (سياسات قانونية لكل دولة) | — | مفاتيح السياسات الأربعة عامة وتدخل `$web_config` | `lavkoo:app/Providers/AppServiceProvider.php:279-282` |
| GCC-12 (إيقاف لكل متجر) | وضع صيانة عام باختيار «الأنظمة» (ويب/تطبيقات/لوحة بائع) | مفتاح واحد في الكاش فقط؛ يزول بأي تفريغ شامل؛ لا أوضاع browse-only/redirect؛ لا استثناء لـ webhooks | `lavkoo:app/Traits/MaintenanceModeTrait.php:10-32`، `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:255-268` |
| GCC-13 (هوية عميل واحدة، سلال ومحافظ منفصلة) | هوية واحدة قائمة فعلاً؛ `app_language` لكل مستخدم | السلة تُعرَّف بالعميل/الضيف فقط؛ مفاتيح الكوبون والعنوان في جلسة مشتركة؛ مقاييس العميل تُجمَّع من كل الطلبات | `lavkoo:app/Utils/CartManager.php:163-173`، `lavkoo:app/Services/Commerce/SegmentResolver.php:105-112` |
| GCC-14 (نطاقات بيانات للموظفين) | — (خارج هذا الملحق) | شاشات الإعدادات تكتب مفاتيح عامة؛ أي موظف يملك صلاحية الإعدادات يغيّرها لكل الأسواق | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:128-178` |
| GCC-15 (ولاء محلي) | — | `loyalty_point_exchange_rate` وأخواته عامة؛ نقاط المستخدم رقم واحد | `lavkoo:app/Utils/currency.php:156-169` |
| GCC-16 (شحن محلي وعابر للحدود) | `delivery_country_codes` وقيد الدولة | قائمة دول واحدة عامة؛ تطبيع الهاتف والعنوان سعودي حصراً | `lavkoo:app/Services/Fulfillment/Support/SaudiPhone.php:31-38` |
| GCC-17 (حملات مركزية وكوبونات محلية) | — | مبالغ الكوبون تُخزَّن عبر `currencyConverter()` بلا عملة؛ حالة الكوبون في الجلسة المشتركة | `lavkoo:app/Services/CouponService.php:26-29`، `lavkoo:app/Utils/CartManager.php:372-375` |
| GCC-19 (السعودية المتجر الافتراضي) | كل السلوك الحالي هو ضمنياً «المتجر السعودي»؛ الرجوع الثابت إلى `SAR`/`SA`/`966` في الأنظمة الأحدث | لا توجد نقطة واحدة تُعرِّف «الافتراضي»؛ الافتراض موزّع على الإعدادات العامة وثوابت الكود | القسم 3 |
| Provisional: market-scoped caches, queues, routes, notifications | — | لا بُعد سوق في أي مفتاح كاش؛ طابوران فقط؛ الوظائف بلا سياق؛ مواضيع FCM عامة | القسم 2.6، `lavkoo:config/queue.php:16`، `lavkoo:app/Utils/settings.php:264-269` |
| ملاحظة الخانات الثلاث (KWD/BHD/OMR) | أعمدة المبالغ الأساسية `DECIMAL(40,20)` بحسب migration أحدث | إعداد خانات واحد عام؛ مئات مواضع التقريب إلى خانتين؛ تطبيق العميل يعرض بلا خانات | `lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:56-72`، `lavkoo:app/Utils/currency.php:101-103`، `lavkooapp:lib/helper/price_converter.dart:75-76` |

لا يتعارض أي دليل في هذا المجال مع قرار معتمد تعارضاً يستدعي إعادة فتحه؛ التعارضات كلها بين القرارات والتنفيذ الحالي.

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (توثيقياً) |
|---|---|---|---|
| توقيع `getWebConfig($name)` وجدول `business_settings` | Preserve + Extend | 936 مستدعياً في `app` وحدها؛ تغييره غير واقعي | طبقة تخصيص تُستشار قبل الصف العام للمفاتيح المصنَّفة «لكل متجر/دولة/كيان» فقط؛ بلا تخصيصات يبقى السلوك السعودي مطابقاً |
| مفتاح `cache_business_settings_table` | Extend | كتلة واحدة لكل الإعدادات | إبقاؤه للقيم العامة وإضافة مدخل مستقل للتخصيصات بمفتاح يتضمن معرّف المتجر |
| دوال العملة (`currencyConverter`، `usdToDefaultCurrency`، `webCurrencyConverter` …) | Preserve + Isolate | مرتبطة بدلالة التخزين الحالية للبيانات السعودية | عدم استدعائها لمبالغ متاجر أخرى؛ منسِّق مبالغ يأخذ العملة والخانات صراحةً |
| إعداد `currency_model` | Preserve (مُقفَل) | تبديله يعيد تفسير كل المبالغ بلا ترحيل | حاجز يمنع التبديل ما دامت توجد مبالغ مخزّنة، أو ترحيل موثَّق؛ القرار لصاحب المشروع |
| جدول `currencies` | Preserve + Extend | مستعمل في التحويل والـ API؛ الرمز «KSA» غير ISO | عمود ISO وعدد خانات لكل عملة، مع إبقاء الصف الحالي كما هو والاعتماد على المرادف القائم |
| `decimal_point_settings` و`currency_symbol_position` | Extend | قيمة واحدة للكل | قراءتهما من سياق المتجر/العملة مع الرجوع إلى العام |
| مفاتيح الجلسة (عملة، كوبون، عنوان، `guest_id`) | Extend | جلسة واحدة للنطاق | فضاء أسماء لكل متجر، مع اعتبار المفاتيح الحالية فضاء المتجر السعودي |
| `cacheRemoveByType()` وثوابت المفاتيح | Refactor | مفاتيح ثابتة بلا أبعاد؛ حاويات هشّة | باني مفاتيح مركزي يضيف المتجر (واللغة حيث يلزم)؛ البادئة الفارغة للمتجر الافتراضي تُبقي المفاتيح الحالية |
| مواضع التفريغ الشامل للكاش | Refactor | حدث في سوق يفرغ كاش الجميع ويُسقط حالة الصيانة | استبدالها بإبطال موجَّه |
| وضع الصيانة | Refactor | حالة في الكاش فقط وعامة | مصدر الحقيقة في قاعدة البيانات ولكل متجر، والكاش نسخة منه |
| `AppServiceProvider::boot()` (`$web_config`) | Refactor | يُحسم قبل التوجيه وبدء الجلسة | نقل البناء إلى view composer أو middleware بعد حلّ السياق |
| `APILocalizationMiddleware` | Preserve + Extend | يحلّ اللغة ويطوي `sa→ar` لتطبيقات منشورة | حلّ المتجر من ترويسة اختيارية جديدة بجانبه؛ غيابها = السعودية |
| middleware `Localization` | Refactor | يقرأ مفتاحاً لا يُكتب | إزالته أو جعله نقطة حلّ السياق للويب |
| رموز اللغة «الدولية» ومجلدات `resources/lang` | Preserve + New | بيانات وترجمات قائمة مفتاحها هذه الرموز | جدول ربط صريح «رمز لغة النظام ↔ لغة BCP-47» وفصل تام بين رمز اللغة ورمز المتجر |
| المنطقة الزمنية | Extend | واحدة للعملية | منطقة عرض لكل دولة؛ تصحيح التباين بين `app.timezone` والإعداد قرار مستقل |
| `PolicyRegistry` / `Policy` | Preserve + Extend | سجل مصرَّح بأنواعه وحدوده | خاصية «النطاق» لكل سياسة؛ إزالة `cache()->flush()` |
| `ExchangeRateService` | Preserve | حوكمة وتدقيق للأسعار | التحقق من وجود جدول `exchange_rate_logs` |
| `CurrencyCode` / `ProviderCurrency` / `StoreCurrencyRateSource` | Preserve | الطبقة الوحيدة التي تتعامل مع العملة صراحةً وبحذر | تعميم نمطها (عملة صريحة، رفض عند الجهل) |
| الوظائف والمستمعون | Extend | بلا سياق | اشتقاق المتجر من الكيان (طلب/تنفيذ) وضبط السياق في بداية كل وظيفة وإعادة ضبطه في نهايتها |
| `StoreContext` + جداول الدولة/المتجر/الكيان القانوني | New | لا مقابل لها | كائن سياق واحد لكل طلب أو وظيفة، افتراضيه المتجر السعودي |

**عدسة GCC — أصغر إدخال متوافق مع القائم:** (1) حلّ السياق مرة واحدة مبكراً (بادئة المسار للويب، ترويسة اختيارية للـ API، الكيان للوظائف والـ webhooks) وتخزينه في كائن واحد؛ (2) قراءة الإعدادات بطبقتين دون تغيير توقيع `getWebConfig()`؛ (3) إبقاء النموذج السعودي `single_currency` كما هو وحمل العملة صراحةً على ما يخص المتاجر الجديدة؛ (4) فضاء أسماء للجلسة والكاش يكون فارغاً للمتجر الافتراضي. بهذا لا يتغير أي مسار قراءة سعودي ما لم يوجد تخصيص.

**آليات ستسرّب بيانات أو إعدادات متجر إلى آخر إن أُضيف متجر ثانٍ دون معالجة:**

| # | الآلية | الدليل |
|---|---|---|
| L1 | كتلة الإعدادات الواحدة في الكاش وقراءة كل المفاتيح منها | `lavkoo:app/Utils/settings.php:26-30` |
| L2 | `View::share('web_config')` عند الإقلاع (هوية، عملات، بوابات، سياسات) | `lavkoo:app/Providers/AppServiceProvider.php:196-225`، `lavkoo:app/Providers/AppServiceProvider.php:312-313` |
| L3 | الجلسة المشتركة: عملة، لغة، كوبون، عنوان، `guest_id` | `lavkoo:config/session.php:143`، `lavkoo:app/Utils/CartManager.php:372-381` |
| L4 | تعريف السلة بالعميل/الضيف فقط | `lavkoo:app/Utils/CartManager.php:163-173` |
| L5 | كاش القوائم والأسعار بلا بُعد متجر (الصفوف 9–19 من الجرد) | `lavkoo:app/Traits/CacheManagerTrait.php:134-151`، `lavkoo:app/Utils/product.php:224-226` |
| L6 | كاش الثيم بلا بُعد متجر | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:88-92`، `lavkoo:app/Services/Theme/ThemeDelivery.php:650-672` |
| L7 | حالة الصيانة العامة | `lavkoo:app/Traits/MaintenanceModeTrait.php:14` |
| L8 | التفريغ الشامل للكاش من أحداث محلية | `lavkoo:app/Http/Controllers/Admin/Marketplace/ExchangeRateController.php:50` |
| L9 | إعدادات البريد والدفع والدخول الاجتماعي و Firebase المحمَّلة عند الإقلاع | `lavkoo:app/Providers/MailConfigServiceProvider.php:29-45`، `lavkoo:app/Providers/FirebaseServiceProvider.php:19-22` |
| L10 | الوظائف بلا سياق، وذاكرة `session()` داخل عامل الطابور (`INFERRED`) | `lavkoo:app/Jobs/Fulfillment/RouteOrderFulfillment.php:21`، `lavkoo:app/Utils/currency.php:27-29` |
| L11 | مواضيع FCM العامة | `lavkoo:app/Utils/settings.php:264-269` |
| L12 | مقاييس العميل المجمَّعة من كل الطلبات (`total_spent` بلا عملة) | `lavkoo:app/Services/Commerce/SegmentResolver.php:105-112` |
| L13 | نقطة `/api/v1/config` الواحدة | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:133-291` |
| L14 | الحدود المالية المخزّنة كأرقام عامة (حد أدنى للطلب، توصيل مجاني، حدود المحفظة، حدود الدفعات) | `lavkoo:app/Http/Controllers/Admin/Settings/InhouseShopController.php:178`، `lavkoo:app/Services/Platform/PolicyRegistry.php:396-401` |

**سلوك «الرجوع إلى السعودية» الذي يحتاجه المستدعون القدامى (GCC-19):** طلب API بلا ترويسة متجر (كل إصدارات التطبيقات المنشورة) ⇒ السعودية؛ عنوان ويب بلا بادئة، وروابط الرجوع من بوابات الدفع والـ webhooks والروابط العميقة القائمة ⇒ السعودية أو متجر الكيان المسجَّل؛ وظيفة أو أمر مجدول بلا سياق ⇒ السعودية؛ `getWebConfig()` بلا سياق ⇒ القيمة العامة الحالية؛ صف قديم بلا عمود متجر/عملة ⇒ السعودية وعملتها بخانتين؛ دوال العملة القديمة ⇒ السلوك الحالي حرفياً؛ الترويسة `lang: sa` تبقى مطوية إلى `ar` (`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:27`).

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E02-F01 | المبالغ مخزّنة بلا عملة، ووحدتها تُستنتج وقت القراءة من إعدادين عامين؛ تبديل `currency_model` أو العملة الافتراضية يعيد تفسير كل المبالغ التاريخية دون أي ترحيل | P0 | VERIFIED (الأثر INFERRED) | `lavkoo:app/Utils/currency.php:50-67`، `lavkoo:app/Utils/currency.php:76-104`، `lavkoo:app/Http/Controllers/Admin/Settings/CurrencyController.php:250-276`، `lavkoo:app/Http/Controllers/Admin/Settings/CurrencyController.php:295-301` | فساد دلالي لأسعار وطلبات وأرصدة السعودية بضغطة إعداد؛ استحالة تمثيل عملتين مستقلتين | قفل الإعدادين؛ عملة صريحة على السجلات المالية الجديدة مع قيمة افتراضية سعودية للقديم؛ منع استعمال دوال التحويل لمتاجر أخرى |
| E02-F02 | لا يوجد أي حامل لسياق المتجر (لا مسار، لا ترويسة، لا middleware، لا عمود)، وكل الإعدادات عامة | P0 | VERIFIED | `lavkoo:app/Models/BusinessSetting.php:13`، `lavkoo:app/Utils/settings.php:14-34`، بحث القسم 2.3(هـ) | أي متجر ثانٍ يرث إعدادات السعودية كلها (دفع، قانون، هوية) | `StoreContext` + طبقة تخصيص للإعدادات حسب التصنيف في 2.8 |
| E02-F03 | لا بُعد متجر/عملة في أي مفتاح كاش؛ قوائم وأسعار وثيم وإعدادات مشتركة | P0 | VERIFIED | جرد 2.6، `lavkoo:app/Traits/CacheManagerTrait.php:134-151`، `lavkoo:app/Utils/product.php:224-226`، `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:25-27` | عرض بيانات متجر لزوار متجر آخر لمدة تصل إلى 3 ساعات | باني مفاتيح مركزي ببُعد المتجر قبل تفعيل أي سوق |
| E02-F04 | جلسة واحدة للنطاق ومفاتيحها (عملة، كوبون، عنوان، `guest_id`) بلا بُعد متجر؛ والسلة تُعرَّف بالعميل/الضيف فقط | P1 | VERIFIED | `lavkoo:config/session.php:143`، `lavkoo:app/Utils/CartManager.php:163-173`، `lavkoo:app/Utils/CartManager.php:372-381` | سلة وكوبون وعملة عرض واحدة عبر مسارات الدول | فضاء أسماء للجلسة؛ بُعد المتجر في السلة (ملحق السلة) |
| E02-F05 | الخانات العشرية: إعداد واحد عام؛ 193 `round(…,2)` في `app` و68 `number_format(…,2)` (13 في `app` و55 في `resources/views`) ثابتة؛ و`usdToDefaultCurrency()` يقتطع بـ `floor` على float (مثلاً 19.99 تصبح 19.98 بحساب IEEE-754) | P1 | VERIFIED (المثال العددي INFERRED بحساب خارج PHP) | `lavkoo:app/Utils/currency.php:101-103`، `lavkoo:app/Utils/currency.php:129-130`، `lavkoo:app/Utils/BackEndHelper.php:50`، `lavkoo:app/Traits/CustomerTrait.php:37` | عرض وحساب خاطئان لعملات الخانات الثلاث؛ فرق هللة بين اللوحة والواجهة اليوم | خانات لكل عملة؛ تقريب موحّد على نوع عشري |
| E02-F06 | رموز اللغة هي رموز دول (`sa`، `ae` …) وتتصادم مع بادئات المتاجر المقترحة | P1 | VERIFIED | `lavkoo:app/Utils/language.php:416-434`، `lavkoo:app/Http/Controllers/Admin/Settings/LanguageController.php:65-98`، `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:27` | التباس «`sa` = العربية» مع «`sa` = المتجر السعودي» في المسارات والترويسات والمجلدات | فصل صريح بين المعرّفين وجدول ربط للغات |
| E02-F07 | منطقة زمنية واحدة للعملية، مع تباين موثَّق بين `app.timezone` (`Asia/Dhaka`) وتوقيت العملية؛ الطوابع تُخزَّن بتوقيت محلي بلا إزاحة | P1 | VERIFIED | `lavkoo:config/app.php:69`، `lavkoo:app/Providers/ConfigServiceProvider.php:30-34`، `lavkoo:app/Services/Monitoring/Support/Clock.php:10-14` | كل الأسواق تُعرض بمنطقة زمنية واحدة للتثبيت (إعداد `Asia/Dhaka`، ومنطقة العملية Asia/Kuwait وفق تعليق الكود)؛ تغيير الإعداد يزيح تفسير التواريخ المخزَّنة (`INFERRED`) | منطقة عرض لكل دولة؛ عدم تغيير منطقة التخزين دون خطة |
| E02-F08 | وضع الصيانة حالة عامة محفوظة في الكاش فقط وتزول بأي تفريغ شامل؛ لا إيقاف لكل متجر | P1 | VERIFIED | `lavkoo:app/Traits/MaintenanceModeTrait.php:14`، `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:255-268` | لا يحقق GCC-12؛ والصيانة تنطفئ بصمت | حالة في قاعدة البيانات لكل متجر |
| E02-F09 | 17 موضعاً تفرغ مخزن الكاش كله من إجراءات إدارية وخدمات (سعر صرف، سياسة، توثيق بائع، SLA …)، ومنها مواضع مُدرجة في الملحق المقيّد (SEC-E02-01) | P1 | VERIFIED | `lavkoo:app/Services/Platform/Policy.php:119`، `lavkoo:app/Http/Controllers/Admin/Marketplace/ExchangeRateController.php:50`، `lavkoo:app/Http/Controllers/Admin/Marketplace/SlaController.php:86` | حدث في سوق يُسقط كاش الأسواق كلها وحالة الصيانة | إبطال موجَّه |
| E02-F10 | قوائم الصفحة الرئيسية تُخزَّن بمفاتيح بلا لغة مع ترجمات لغة أول طلب | P1 | VERIFIED (الأثر INFERRED) | `lavkoo:app/Traits/CacheManagerTrait.php:153-192`، `lavkoo:app/Traits/CacheManagerTrait.php:427-435`، `lavkoo:app/Models/Product.php:506-512`، `lavkoo:app/Http/Controllers/Web/HomeController.php:34-38` | تسريب بين اللغات قائم اليوم؛ يثبت أن أبعاد المحتوى غائبة عن المفاتيح | إدخال اللغة (ثم المتجر) في المفاتيح عبر الباني المركزي |
| E02-F11 | الوظائف والأحداث بلا سياق؛ البريد المؤجَّل يُحلّ بلغة المتجر الافتراضية وقت التنفيذ (لم يُعثر على استعمال للعملة في `app/Mail` أو `SendEmailJob` أو `EmailTemplateTrait`) | P1 | VERIFIED للحمولات، INFERRED للأثر | `lavkoo:app/Jobs/SendEmailJob.php:24-28`، `lavkoo:app/Models/EmailTemplate.php:84-87`، `lavkoo:app/Traits/EmailTemplateTrait.php:36`، `lavkoo:app/Traits/PushNotificationTrait.php:233` | رسائل بلغة المتجر الافتراضية لا بلغة المتجر المعني، وإشعارات فورية بعملة المتجر الافتراضية (`INFERRED`) | اشتقاق السياق من الكيان وضبطه لكل وظيفة |
| E02-F12 | `$web_config` وإعدادات البريد/الدفع/الدخول الاجتماعي/Firebase تُحسم عند الإقلاع قبل التوجيه وبدء الجلسة | P1 | VERIFIED (ترتيب دورة حياة الإطار INFERRED) | `lavkoo:app/Providers/AppServiceProvider.php:161-176`، `lavkoo:app/Providers/AppServiceProvider.php:312-313`، `lavkoo:bootstrap/providers.php:4-22` | سياق يُحلّ في middleware يصل متأخراً | تأجيل البناء إلى ما بعد حلّ السياق |
| E02-F13 | مصادر متعددة غير موحَّدة لـ«عملة المتجر» ورمز غير ISO («KSA») في جدول العملات | P1 | VERIFIED (قيمة الإنتاج INFERRED) | `lavkoo:config/fulfillment.php:59-69`، `lavkoo:config/operational_costs.php:28`، `lavkoo:config/myfatoorah.php:103-106`، `lavkoo:tests/Support/BuildsPilotServerSchema.php:17-18` | كل نظام فرعي سيحتاج مصدراً لكل متجر؛ الرمز غير القياسي يصل إلى البوابات و SEO | مصدر واحد للعملة من سياق المتجر/الكيان |
| E02-F14 | إبطال كاش العملات غير متسق: التعديل الجماعي يمسح `active_currency_list` فقط ويبقى `cache_for_currency_table` (مصدر `/api/v1/config`) حتى 3 ساعات | P2 | VERIFIED (الأثر INFERRED) | `lavkoo:app/Repositories/CurrencyRepository.php:65-70`، `lavkoo:app/Models/Currency.php:45-51`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:203` | أسعار صرف مختلفة بين الويب والتطبيق مؤقتاً | مسح المفتاحين معاً |
| E02-F15 | كود ميت في طبقة السياق: فرع الكاش لكل مفتاح في `getWebConfig()`؛ middleware `Localization`؛ مراقبون غير مسجَّلين يستدعون `flushCache()` غير موجودة؛ ثوابت كاش تُمسح ولا تُكتب | P2 | VERIFIED | `lavkoo:app/Utils/settings.php:23`، `lavkoo:app/Http/Middleware/Localization.php:20-22`، `lavkoo:app/Observers/CurrencyObserver.php:12-15`، `lavkoo:app/Utils/settings.php:474` | يضلّل من يبني فوقه | تنظيف ضمن أول مرحلة تمسّ هذه الملفات |
| E02-F16 | كشف السياق بشمّ الـ URL (`strpos(url()->current(), '/api')` في 19 موضعاً، `segment(1)` في 11) | P2 | VERIFIED (الأثر INFERRED) | `lavkoo:app/Utils/language.php:106`، `lavkoo:app/Models/Product.php:419-426`، `lavkoo:app/Utils/BrandManager.php:16-17` | يتغير معناه مع بادئة `/sa`؛ وأي مسار ويب يحوي «/api» يُعامَل كطلب API | الاعتماد على كائن السياق |
| E02-F17 | مسألة أمنية مقيّدة — انظر SEC-E02-07 في آخر هذا القسم | P1 | VERIFIED | مُدرج في الملحق المقيّد | مُدرج في الملحق المقيّد | مراجعة ضمن ملحق السلة |
| E02-F18 | مسار تبديل العملة على الويب عام غير مرتبط بسياق متجر (التفاصيل في SEC-E02-08): في `single_currency` يتغير رمز العرض دون تحويل؛ وفي `multi_currency` يُحوَّل مبلغ الدفع آلياً | P1 | VERIFIED (الأثر INFERRED) | `lavkoo:app/Http/Controllers/Web/CurrencyController.php:18-28`، `lavkoo:app/Utils/currency.php:126-128`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-419` | عرض سعر برمز عملة خاطئة؛ تعارض مع «لا تحويل آلي» | تعطيل المسار أو ربطه بسياق المتجر |
| E02-F19 | الإنتاج بحسب توثيق المستودع: `single_currency`، العملة الافتراضية «KSA» بسعر 1، خانتان | INFO | INFERRED | `lavkoo:tests/Support/BuildsPilotServerSchema.php:12-18`، `lavkoo:tests/Support/BuildsPilotServerSchema.php:43-53` | نقطة البداية لأي ترحيل | تأكيدها بقراءة مباشرة قبل أي تصميم |
| E02-F20 | المخطط الأساسي (`business_settings`، `currencies`، `products`، `orders` …) لا ينشئه أي migration؛ ملف `database.sql` غير متتبَّع | INFO | VERIFIED | `lavkoo:app/Console/Commands/BootstrapTestSchema.php:11-18`، `lavkoo:.gitignore:53` | أنواع الأعمدة الفعلية لا تُعرف من المستودع | استخراج DDL من الإنتاج (قراءة فقط) |
| E02-F21 | أبعاد دولة/عملة قائمة جزئياً تصلح نقاط ارتكاز: ضرائب، عناوين، حسابات القنوات، محاولات الدفع، `app_language`، بصمة كاش الثيم | INFO | VERIFIED | `lavkoo:Modules/TaxModule/database/migrations/2025_05_26_115043_create_system_tax_setups_table.php:19`، `lavkoo:database/migrations/2026_11_07_100100_create_sales_channel_tables.php:27-31`، `lavkoo:app/Services/Theme/ThemeDelivery.php:650-672` | يمكن البناء عليها بدل تكرارها | مواءمتها مع كيان الدولة/المتجر الجديد |
| E02-F22 | تصادم مصطلحات: «Storefront»، «Marketplace»، `store_id`، `storefront_code` مستعملة بمعانٍ أخرى | INFO | VERIFIED | `lavkoo:app/Services/SalesChannels/Support/ChannelSettings.php:14`، `lavkoo:database/migrations/2026_11_10_100000_add_stock_allocation.php:35` | أسماء الجداول والأصناف الجديدة قد تلتبس | معجم مصطلحات قبل التصميم |
| E02-F23 | لا migration لجدول `exchange_rate_logs`؛ السجل لا يُكتب إن لم يوجد الجدول | P2 | VERIFIED (وجوده في الإنتاج UNKNOWN) | `lavkoo:app/Services/Marketplace/ExchangeRateService.php:55`، `lavkoo:app/Services/Marketplace/ExchangeRateService.php:130` | تاريخ أسعار الصرف قد لا يكون محفوظاً | التحقق ثم إضافة migration عند الموافقة |
| E02-F24 | الأنظمة الأحدث سعودية صراحةً في الهاتف والدولة وخادم MyFatoorah | P1 | VERIFIED | `lavkoo:app/Services/Fulfillment/Support/SaudiPhone.php:31-38`، `lavkoo:app/Services/SalesChannels/Dispatch/ChannelRecipient.php:41-52`، `lavkoo:app/Services/SalesChannels/Dispatch/DispatchGate.php:96-97`، `lavkoo:config/myfatoorah.php:60-72` | تُعلِّم بوابة إرسال القنوات الطلبات غير السعودية بمانع (`destination_not_supported`)، وتقصر تطبيع الهاتف ومضيفي MyFatoorah على السعودية؛ أي دولة أخرى تحتاج تعميماً قبل التفعيل | تعميمها بحسب دولة المتجر (ملاحق التنفيذ والدفع) |

**مسائل أمنية مقيّدة (التفاصيل في الملحق غير المنشور):**

- SEC-E02-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E02-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E02-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E02-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E02-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E02-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E02-07: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E02-08: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

## 7. أسئلة مفتوحة وعناصر UNKNOWN

| # | المجهول | ما الذي يحسمه |
|---|---|---|
| 1 | القيم الحالية في الإنتاج لـ `currency_model`، `system_default_currency`، `decimal_point_settings`، `currency_symbol_position`، `timezone`، `country_code`، `default_location`، `business_mode`، وصفوف `currencies` (الرموز، الأسعار، الحالة) | استعلام قراءة فقط على `business_settings` و`currencies` |
| 2 | قائمة اللغات الفعلية ورموزها (هل العربية `sa` أم `sy` أم غير ذلك) ووجود مجلدات ترجمة غير متتبَّعة على الخادم | صف `language` في `business_settings` + قائمة مجلدات `resources/lang` على الخادم |
| 3 | إعدادات البيئة: `CACHE_DRIVER`، `CACHE_PREFIX`، `SESSION_DRIVER`، `SESSION_DOMAIN`، `QUEUE_CONNECTION`، `APP_NAME`، `FULFILLMENT_CURRENCY`، `MYFATOORAH_CURRENCY` | ملف `.env` للإنتاج (القيم غير السرية فقط) |
| 4 | أنواع الأعمدة الفعلية للجداول الأساسية، وهل نُفِّذ migration توسيع الأعمدة العشرية | `SHOW CREATE TABLE` وجدول `migrations` في الإنتاج |
| 5 | وجود جدول `exchange_rate_logs` | فحص المخطط في الإنتاج |
| 6 | هل عمّال الطوابير دائمو التشغيل (فيصحّ خطر ذاكرة `session()` بين الوظائف) أم الاتصال `sync` | `.env` وإعداد supervisor على الخادم |
| 7 | هل يوجد CDN أو كاش صفحات أمام التطبيق يعتمد ترويسة `Vary` | إعدادات الاستضافة/CDN |
| 8 | هل توجد أكثر من عملة نشطة في الإنتاج، وهل يعرض تطبيق العميل تبديل العملة | البند 1 + ملحق تطبيق العميل |
| 9 | حالة هذه الآليات في المستودع القديم | `UNKNOWN (missing access: pharmacyksa98)` |

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| كل الإعدادات تُقرأ من كتلة كاش واحدة بلا أبعاد | `lavkoo:app/Utils/settings.php:26-30` | VERIFIED |
| فرع الكاش لكل مفتاح لا يُكتب أبداً | `lavkoo:app/Utils/settings.php:23`، `lavkoo:app/Utils/settings.php:62-99` | VERIFIED |
| حفظ إعداد يُسقط كاش المنتجات والأصناف | `lavkoo:app/Utils/settings.php:436-440` | VERIFIED |
| `$web_config` تُبنى وتُشارك عند الإقلاع | `lavkoo:app/Providers/AppServiceProvider.php:196-225`، `lavkoo:app/Providers/AppServiceProvider.php:312-313` | VERIFIED |
| المنطقة الزمنية من إعداد عام تُطبَّق على العملية | `lavkoo:app/Providers/ConfigServiceProvider.php:30-34` | VERIFIED |
| `app.timezone` ثابتة `Asia/Dhaka` | `lavkoo:config/app.php:69` | VERIFIED |
| توقيت العملية Asia/Kuwait على هذا التثبيت | `lavkoo:app/Services/Monitoring/Support/Clock.php:10-14` | INFERRED (تعليق في الكود) |
| التخزين بوحدة مبنية على الدولار في `multi_currency` | `lavkoo:app/Utils/currency.php:50-67`، `lavkoo:app/Services/Fulfillment/Support/ProviderCurrency.php:8-9` | VERIFIED |
| سعر المنتج يُكتب عبر `currencyConverter()` | `lavkoo:app/Services/ProductService.php:614-619` | VERIFIED |
| لا عمود عملة في النماذج المالية الأساسية | `lavkoo:app/Models/Order.php:95` (الحقل الوحيد المطابق) | VERIFIED (بحث مسجَّل) |
| تبديل نموذج العملة بلا ترحيل | `lavkoo:app/Http/Controllers/Admin/Settings/CurrencyController.php:250-276` | VERIFIED |
| تغيير العملة الافتراضية في الوضع المفرد يعيد التسمية فقط | `lavkoo:app/Http/Controllers/Admin/Settings/CurrencyController.php:295-301` | VERIFIED |
| إعادة تأسيس أسعار الصرف في جدول `currencies` عند حفظ الإعدادات العامة | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:180-191` | VERIFIED |
| `usdToDefaultCurrency()` يقتطع بـ `floor` | `lavkoo:app/Utils/currency.php:101-103` | VERIFIED |
| تبديل عملة الجلسة على الويب مسار عام واحد (SEC-E02-08) | `lavkoo:app/Http/Controllers/Web/CurrencyController.php:18-28` | VERIFIED |
| تحويل مبلغ الدفع آلياً في `multi_currency` | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-419` | VERIFIED |
| خادم التجربة: `single_currency` والعملة «KSA» | `lavkoo:tests/Support/BuildsPilotServerSchema.php:12-18` | INFERRED (توثيق) |
| مرادف `KSA => SAR` | `lavkoo:config/fulfillment.php:67-69` | VERIFIED |
| اللغة على الويب من مفتاح الجلسة `local` | `lavkoo:app/Utils/language.php:104-130` | VERIFIED |
| اللغة في الـ API من الترويسة `lang` مع طيّ `sa→ar` | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29` | VERIFIED |
| `Localization` يقرأ مفتاحاً لا يُكتب | `lavkoo:app/Http/Middleware/Localization.php:20-22` | VERIFIED (بحث مسجَّل) |
| رموز اللغة رموز دول | `lavkoo:app/Utils/language.php:416-434`، `lavkoo:app/Services/Seo/SitemapHreflangGenerator.php:85-87` | VERIFIED |
| `translate()` تكتب ملفات الترجمة وقت التشغيل | `lavkoo:app/Utils/language.php:28-43` | VERIFIED |
| الترجمات تُختار بفحص نصّي للـ URL | `lavkoo:app/Models/Product.php:506-512` | VERIFIED |
| مجموعة `api` بلا جلسة | `lavkoo:bootstrap/app.php:84-94` | VERIFIED |
| ملف ارتباط الجلسة بمسار `/` | `lavkoo:config/session.php:143` | VERIFIED |
| السلة تُعرَّف بالعميل/الضيف فقط | `lavkoo:app/Utils/CartManager.php:163-173` | VERIFIED |
| ضيف الـ API يُعرَّف بوسيط `guest_id` | `lavkoo:app/Http/Middleware/APIGuestMiddleware.php:26-28` | VERIFIED |
| بادئة كاش واحدة ومخزن افتراضي واحد | `lavkoo:config/cache.php:21`، `lavkoo:config/cache.php:101` | VERIFIED |
| تفريغ شامل للكاش عند تحديث أسعار الصرف | `lavkoo:app/Http/Controllers/Admin/Marketplace/ExchangeRateController.php:50` | VERIFIED |
| تفريغ شامل للكاش عند حفظ سياسة | `lavkoo:app/Services/Platform/Policy.php:118-120` | VERIFIED |
| حالة الصيانة في الكاش فقط | `lavkoo:app/Traits/MaintenanceModeTrait.php:14`، `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:255-268` | VERIFIED (بحث: 3 مواضع فقط للمفتاح) |
| قوائم الرئيسية بمفاتيح بلا لغة | `lavkoo:app/Traits/CacheManagerTrait.php:427-435` | VERIFIED |
| مفتاح العلامات يتضمن أول مقطع من الـ URL | `lavkoo:app/Utils/BrandManager.php:16-17` | VERIFIED |
| أدنى/أقصى سعر مخزَّنان بلا عملة | `lavkoo:app/Utils/product.php:224-226` | VERIFIED |
| مفتاح كاش الثيم للتطبيق يتضمن اللغة لا المتجر | `lavkoo:app/Services/Theme/ThemeDelivery.php:650-672` | VERIFIED |
| إبطال العملات: التعديل الجماعي يمسح مفتاحاً واحداً | `lavkoo:app/Repositories/CurrencyRepository.php:65-70` | VERIFIED |
| مفتاح كاش السلة يتضمن معرّف العميل أو الضيف (التفاصيل في SEC-E02-07) | `lavkoo:app/Utils/ProductManager.php:2584-2595` | VERIFIED |
| الوظائف تحمل معرّفات فقط | `lavkoo:app/Jobs/Fulfillment/RouteOrderFulfillment.php:21` | VERIFIED |
| قالب البريد بلغة `getDefaultLanguage()` | `lavkoo:app/Models/EmailTemplate.php:84-87` | VERIFIED |
| لغة الإشعار من `app_language` للمستلم | `lavkoo:app/Traits/PushNotificationTrait.php:29-33` | VERIFIED |
| مواضيع FCM العامة ثابتة | `lavkoo:app/Utils/settings.php:264-269` | VERIFIED |
| تطبيع الهاتف سعودي حصراً | `lavkoo:app/Services/Fulfillment/Support/SaudiPhone.php:31-38` | VERIFIED |
| خادم MyFatoorah السعودي فقط في قائمة المسموح | `lavkoo:config/myfatoorah.php:60-72` | VERIFIED |
| الأعمدة المالية الأساسية `DECIMAL(40,20)` بحسب migration | `lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:56-72` | VERIFIED (تنفيذه في الإنتاج UNKNOWN) |
| المخطط الأساسي غير موجود في migrations | `lavkoo:app/Console/Commands/BootstrapTestSchema.php:11-18` | VERIFIED |
| تطبيق العميل يطبّق معادلة التحويل دائماً ويعرض بلا خانات | `lavkooapp:lib/helper/price_converter.dart:65-76` | VERIFIED |
| تطبيق البائع يحترم `currency_model` | `lavkooappseller:lib/helper/price_converter.dart:18-24` | VERIFIED |

## 9. سجل التحقق المستقل

> تحقق مستقل قراءةً فقط على الالتزام المدقَّق `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` وعلى HEAD الحالي `lavkoo@41201499d515b6adbbe1faeb3150a0bb724c6bf9` (الفرق بينهما خارج `docs` فارغ). لم يُشغَّل أي كود أو اختبار.

**جدول العدّ**

| البند | العدد |
|---|---|
| الادعاءات المفحوصة (عيّنة: بنود P0 وP1 كلها، وأقسام 2–4، وأعداد الجرد، وعمليات البحث بالغياب) | 90 |
| مؤكَّدة كما وردت | 72 |
| مُصحَّحة | 12 |
| مُفنَّدة (جزئياً أو كلياً) | 2 |
| غير قابلة للتحقق بالقراءة الثابتة | 4 |

**قائمة التصحيحات**

1. (مُفنَّد) §2.2 (أ): `exchangeRate()` عند غياب الصف لا تُرجع 1 بصمت؛ الأثر تحذير PHP يتحوّل عادةً إلى استثناء (`INFERRED`).
2. (مُفنَّد) §2.2 (هـ) و§8: `updateSystemCurrency` يعيد حساب `exchange_rate` في جدول `currencies` فقط، ولا يعيد تأسيس أسعار المنتجات أو المبالغ المخزَّنة.
3. §2.6 و§6 (E02-F09): عدد مواضع الإبطال الشامل 13 → 17 (7 `cache()->flush()` و2 `Cache::clear()` و8 استدعاءات Artisan)، وحُذف وصف «أحداث محلية».
4. §2.6: `cacheRemoveByType` يُستدعى في 46 موضعاً ضمن 22 ملف نموذج، لا «22 نموذجاً» بأحداث الحفظ فقط.
5. §2.4 وE02-F07: حُذف مثال «الإمارات وعُمان تُعرض بتوقيت الرياض»، لأن القيم في الكود لا تدعمه.
6. §2.4: صُحِّحت صياغة سلوك Eloquent والتواريخ (توقيت حائطي، قراءة بمنطقة التطبيق).
7. E02-F11: حُذف ادعاء «عملة» البريد المؤجَّل؛ لم يُعثر على استعمال للعملة في مسار البريد.
8. E02-F05 و§3.2: عدّ `number_format(…,2)` أُعيد إلى 13 في `app` و55 في `resources/views`.
9. §2.3 (RTL): العدّ أُعيد إلى 64 موضعاً بنمط `session(…'direction')`.
10. §2.1 (ج): الكتابات المباشرة على `BusinessSetting` أُعيد عدّها إلى 32 موضعاً في 18 ملفاً.
11. E02-F24: حُدِّد الأثر بدقة (بوابة إرسال القنوات، تطبيع الهاتف، MyFatoorah) بدل العبارة العامة «تمنع تنفيذ أو تحصيل».
12. E02-F17: نُقل التفصيل إلى الملحق المقيّد (SEC-E02-07)، وأُعيد تصنيف الدرجة إلى P1.
13. E02-F18 و§2.2 (د) و(و): نُقل شرط التحقق عند مسار تبديل العملة إلى الملحق المقيّد (SEC-E02-08)، والوصف الظاهر عام.
14. ترويسة الوثيقة: HEAD المحلي تقدّم إلى `41201499` (الفرق خارج `docs` فارغ)، وتوحيد صيغة أسطر المؤشرات لـ SEC-E02-04 وSEC-E02-06 مع إضافة SEC-E02-07 وSEC-E02-08.

**البنود المنقولة إلى الملحق المقيّد:** SEC-E02-07 وSEC-E02-08 (بندان جديدان في هذه الجولة)، مع ملاحظة تحقق مضافة تحت SEC-E02-01. عدد البنود المنقولة: 2.

**عمليات البحث بالغياب المُعادة:** مفاتيح كاش تُكتب للإعدادات (`Cache::put|forever|add|set` و`cache([`) للأسماء الستة عشر؛ مفاتيح السوق والمتجر والدولة (`storefront_id|market_id|country_id|legal_entity|store_code|market_code|current_country|currentStore|StoreContext|MarketContext`) في `app` و`Modules` و`config` و`routes` و`database` و`bootstrap` و`tests` (لا نتائج؛ المطابقات في `resources/lang` وملفات عرض الإدارة لأسماء أخرى)؛ مكتبات GeoIP (`GeoGenius|geoip|ipinfo|cf-ipcountry`) (لا استعمال للمتجر؛ الحزمة مطلوبة في `composer.json` دون استعمال، والمطابقات في التحليلات نصوص إرشادية)؛ كتابة `locale` في الجلسة (لا نتائج)؛ `exchange_rate_logs` في كل الملفات (الخدمة و`RestoreSection` وملفا ترجمة فقط)؛ ترويسات السياق (`x-currency|x-country|x-store|x-market|x-storefront|x-region`) (لا نتائج)؛ جدولة أسعار الصرف في `ScheduleDefinition` والأوامر وملفات المسارات (لا نتائج)؛ `flushCache` (غير معرّفة في النماذج والسمات؛ المراقبون الثلاثة غير مسجَّلين في `ObserverServiceProvider`)؛ `set_error_handler` في مسار التطبيق (موجود في وحدتين فقط)؛ `Route::domain` (لا نتائج).

**المخاطر المتبقية:**

- درجة SEC-E02-07 (P1 بعد التحقق) وSEC-E02-08 تحتاج مراجعة من مالك الأمان؛ لم تُختبر تشغيلياً.
- قيم الإنتاج (العملات، الجلسة، الكاش، الطوابير، المنطقة الزمنية، أنواع الأعمدة) ما زالت `UNKNOWN`.
- جرد مفاتيح `business_settings` (209 حرفياً و73 سياسة) وأعداد القسم 3.2 لم تُعَد بالكامل؛ أُعيد عدّ ما ورد أعلاه فقط.
- عبارة «وتزول بأي تفريغ شامل» في E02-F08 تعتمد على قراءة الكود، والسلوك على الإنتاج `UNKNOWN`.

**فجوات التغطية:**

- محتوى `resources/views` و`resources/themes` لم يُقرأ فرداً فرداً (خارج النطاق المعلن أصلاً).
- إعداد الطوابير (`config/queue.php:16`) وأعداد الأحداث والمستمعين لم تُعَد.
- صفوف جرد الكاش 2–4 و6–8 و10 و12–19 و21–35 أُخذت بالعيّنة ولم تُفتح سطراً بسطر.
- `PaymentConfigProvider` (الأسطر 89–120) لم يُفتح.

**نتيجة الفحص الآلي بعد التحرير:** `check_citations.py` و`scan_sensitive.py` (انظر التقرير النهائي).
