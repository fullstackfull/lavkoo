# E06 — الاتصالات، الصفحات القانونية، وضع الصيانة/الإيقاف، التوجيه، SEO والروابط العميقة (Communications, Compliance, Activation, Routing, SEO, Deep Links)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` (الـ HEAD المحلي `lavkoo@046d623d` يضيف ملف توثيق واحداً فقط تحت `docs/gcc/audits/` ولا يغيّر أي كود)، `lavkooapp@eedbbb8fd0303aa9fed5d4ff0ac45b3a409abf7a`، `lavkooappseller@8ea00922e44db3d69059d46892f8f4f84349b580`.

## 1. النطاق وما تم فحصه

يغطي هذا الملحق بند التغطية G من `DISCOVERY_PROMPT_01.md` في الـ backend (Laravel) مع التحقق من نقاط التماس في تطبيقَي Flutter.

**ما قُرئ كاملاً (نحو 95 ملفاً):**

| المجال | الملفات |
|---|---|
| Push / FCM | `app/Traits/PushNotificationTrait.php` (531 سطراً)، `app/Services/PushNotificationService.php`، `FirebaseService.php`، `NotificationService.php`، `app/Http/Controllers/FirebaseController.php`، `app/Providers/FirebaseServiceProvider.php`، `app/Services/Theme/ThemeSyncBeacon.php`، `app/Services/SellerIntelligence/SellerNotifier.php`، `app/Http/Controllers/Admin/Notification/NotificationController.php`، `app/Http/Controllers/RestAPI/v1/NotificationController.php`، الـ Listeners المرتبطة |
| البريد | `app/Traits/EmailTemplateTrait.php`، `app/Services/EmailTemplateService.php`، `MailService.php`، `app/Providers/MailConfigServiceProvider.php`، `app/Enums/EmailTemplateKey.php`، `app/Models/EmailTemplate.php`، `app/Mail/SendMail.php`، `app/Jobs/SendEmailJob.php`، `app/Listeners/Concerns/QueuedMailDelivery.php`، قوالب `resources/views/email-templates/` (17 ملفاً، فُحصت روابطها) |
| SMS / OTP / WhatsApp / Chat | `app/Utils/SMSModule.php`، `app/Services/ZenderOtpService.php`، `app/Models/ZenderSetting.php`، `app/Services/ChatwootService.php`، `NotificationAutomationService.php`، `ManualWhatsAppMessageService.php` (التواقيع)، `app/Http/Controllers/Admin/CartMarketingController.php`، `app/Models/NotificationTemplateMapping.php`، `IntegrationSetting.php` |
| التسويق والموافقات | `app/Console/Commands/SendAbandonedCartReminders.php`، `app/Services/Retention/AbandonedCartService.php` (جزئياً)، `app/Models/AbandonedCartReminder.php`، `CustomerSegment.php`، `Subscription.php`، `app/Services/Commerce/SegmentRules.php`، `CampaignResolver.php` (جزئياً)، `app/Models/ExperienceCampaign.php`، `ExperienceExperiment.php`، `app/Services/Analytics/Support/PrivacyGate.php`، `AnalyticScript.php`، `MetaAppIntegration.php` |
| الصفحات القانونية | `app/Models/BusinessPage.php`، `app/Services/BusinessPageService.php`، `app/Http/Controllers/Admin/Settings/PagesController.php`، `app/Http/Controllers/Web/PageController.php`، الـ migration `2025_03_08_201607_create_business_pages_table.php`، `app/Traits/UpdateClass.php` (الجزء 965–1036) |
| الصيانة | `app/Http/Middleware/MaintenanceModeMiddleware.php`، `CheckForMaintenanceMode.php`، `app/Traits/MaintenanceModeTrait.php`، `BusinessSettingsController::updateSystemMode`، `BusinessSettingService.php`، `bootstrap/app.php` |
| التوجيه واللغة | `app/Providers/RouteServiceProvider.php`، `routes/web/routes.php` (656 سطراً)، `app/Http/Middleware/Localization.php`، `APILocalizationMiddleware.php`، `app/Utils/language.php` (جزئياً)، `SharedController.php`، `Web/CurrencyController.php` |
| SEO | `app/Services/Seo/` (8 ملفات؛ قُرئ منها 5 كاملة)، `app/View/Composers/SeoHeadComposer.php`، `ProductSeoSupplementComposer.php`، `app/Http/Controllers/Admin/Settings/SiteMapController.php`، `app/Http/Middleware/ApplySeoRedirects.php`، `app/Models/Redirect.php`، `RobotsMetaContent.php`، `SeoTemplate.php`، `SeoMetaInfo.php`، `app/Traits/GeneratesUniqueSlug.php`، `robots.txt`، `public/robots.txt`، `public/sitemap.xml` |
| الروابط العميقة | `config/deeplinks.php`، `app/Services/DeepLink/` (3 ملفات)، `app/Console/Commands/DeepLinkPublish.php`، `app/Http/Controllers/RestAPI/v1/DeepLinkController.php`، `tests/Feature/DeepLink/PublishedAppLinkTest.php` (جزئياً)، `public/.well-known/`، `firebase-messaging-sw.js`، وفي التطبيقين: `AndroidManifest.xml`، `Runner.entitlements`، `lib/data/services/deep_link_resolver.dart`، `lib/utill/app_constants.dart` |
| DeliverySyria | `routes/rest_api/delivery_syria.php`، `app/Http/Middleware/DeliverySyriaWebhookAuthMiddleware.php`، `app/Services/DeliverySyria/DeliverySyriaConfigService.php`، `app/Services/DeliverySyriaService.php` (جزئياً)، `app/Models/DeliverySyriaParcel.php`، `DeliverySyriaGovernorateRate.php`، نقطة الربط في `app/Utils/OrderManager.php` (1770–1875) |
| التحليلات (مستوى عالٍ) | `app/Services/Analytics/VisitorContext.php`، `EventRecorder.php`، `app/Services/Telemetry/TelemetryRecorder.php` (مقاطع الحقول المسجَّلة فقط) |

**أنماط البحث المستخدمة (rg) ومجالها:** `sendPushNotificationToTopic|'topic'|subscribeToTopic` في `app routes resources`؛ `cm_firebase_token|fcm_token` في `app routes database`؛ `maintenance_mode|system_maintenance_mode` في `app routes config Modules database`؛ `consent|opt_in|unsubscribe|newsletter` في `app routes config database Modules`؛ `canonical|hreflang` في `resources/themes resources/views app`؛ `request('language')|->language|query('language')` في `app Modules`؛ `URL::defaults|forceRootUrl|Route::fallback` في `app bootstrap routes`؛ `geoip|ip-api|CF-IPCountry|country_header` في `app config`؛ `pharmacyksa.com|lavkoo.com` في `app config routes resources Modules bootstrap`؛ `Schema::create('<table>'` لكل جدول من جداول هذا المجال في `database Modules app`؛ `subscribeToTopic` في `lib/` للتطبيقين.

**ما لم يُفحص:** تفاصيل لوحات `app/Services/Monitoring/` (94 ملفاً — اكتُفي بنقاط الإدخال والحقول)، منطق `CollectionResolver`/`MerchandisingRules`، قوالب Blade للواجهة عدا مواضع الروابط والـ meta، مجلد `Modules/Blog` عدا نقاط اللغة، وقيم الإعدادات الفعلية في قاعدة بيانات الإنتاج (كلها UNKNOWN). لم يُشغَّل أي كود ولم يُجرَ أي اتصال شبكي.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 قنوات الاتصال — جرد عام

| القناة | هوية المرسل/الحساب | مكان تخزين الإعداد | القوالب واللغات | المحفّزات | الطابور |
|---|---|---|---|---|---|
| Push (FCM HTTP v1) | حساب خدمة Firebase واحد | `business_settings.push_notification_key` | `notification_messages` بمفتاح (`user_type`,`key`) + ترجمات | حالة الطلب، المحادثة، المحفظة، السحب، إشعار إداري عام، الصيانة، إعادة التوفّر، تحديث الثيم | مختلط: بعض الـ listeners في الطابور وبعضها متزامن |
| Web Push | نفس مشروع Firebase | `business_settings.fcm_credentials` + ملف `firebase-messaging-sw.js` | — | topics | — |
| E-mail | SMTP واحد (أو SendGrid بديل) | `business_settings.mail_config` / `mail_config_sendgrid` | `email_templates` بمفتاح (`user_type`,`template_name`) + ترجمات | تسجيل، تحقق، طلب جديد، محفظة، حالة الحساب، سلة متروكة | listeners في الطابور |
| SMS OTP | مزوّد واحد نشط من ستة | `addon_settings` (`settings_type='sms_config'`) | قالب OTP واحد لكل مزوّد | OTP فقط | متزامن |
| Zender (SMS أو WhatsApp OTP) | حساب واحد | صف واحد في `zender_settings` | حقل `message` واحد | OTP فقط | متزامن |
| WhatsApp (Chatwoot) | حساب/inbox واحد | `integration_settings` (`provider='chatwoot'`) | قوالب WhatsApp عند Chatwoot + `notification_template_mappings` | تسويق السلة يدوياً، رسائل يدوية؛ أتمتة حالة الطلب غير موصولة | متزامن |
| In-app | — | `notifications` + `notification_seens`، `seller_notification_deliveries` | نص حر | إشعار إداري عام، رؤى البائع | — |
| Chat/دعم | — | جداول المحادثة والتذاكر | — | رسائل الأطراف | listener |

التفاصيل والأدلة في الفقرات التالية.

### 2.2 Push notifications وFCM

**الإرسال.** كل الإرسال يمر عبر `PushNotificationTrait::sendNotificationToHttp` الذي يقرأ إعداداً واحداً `getWebConfig('push_notification_key')` ويبني عنوان `fcm.googleapis.com/v1/projects/<project_id>/messages:send` — `lavkoo:app/Traits/PushNotificationTrait.php:484-499`. رمز الوصول OAuth يُخزَّن في cache بمفتاح مشتق من `client_email` — `lavkoo:app/Traits/PushNotificationTrait.php:501-530`. الـ SDK (kreait) يُهيَّأ كـ singleton من الإعداد نفسه — `lavkoo:app/Providers/FirebaseServiceProvider.php:19-22`. لا يوجد أي بارامتر متجر/دولة في مسار الإرسال. **VERIFIED**.

**القوالب.** نص الإشعار يُجلب من `NotificationMessage` بشرط (`key`,`user_type`) مع ترجمة بحسب `locale` — `lavkoo:app/Traits/PushNotificationTrait.php:307-314`؛ الجدول يحوي `user_type`,`key`,`message`,`status` فقط — `lavkoo:database/migrations/2023_10_07_182714_create_notification_messages_table.php:16-23`. قائمة المفاتيح ثابتة في الكود لثلاثة أنواع مستخدمين (customer/seller/delivery_man) — `lavkoo:app/Services/PushNotificationService.php:9-110`. لغة الإشعار تؤخذ من `app_language` للمستلم ثم لغة النظام — `lavkoo:app/Traits/PushNotificationTrait.php:29-34`، والعمود قيمته الافتراضية `en` — `lavkoo:database/migrations/2023_10_21_113354_add_app_language_column_to_users_table.php:17`. **VERIFIED**.

**تخزين التوكنات.**

| الطرف | العمود | الدليل | ملاحظة |
|---|---|---|---|
| Customer (تطبيق) | `users.cm_firebase_token` | `lavkoo:database/migrations/2021_03_03_204349_add_cm_firebase_token_to_users.php:17`؛ التحديث `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:638-660` | سلسلة واحدة لكل مستخدم: آخر جهاز يسجّل يفوز |
| Customer (ويب) | `users.cm_firebase_token_web` | `lavkoo:database/migrations/2026_05_07_152641_add_cm_firebase_token_web_to_users_table.php:15`؛ `lavkoo:app/Http/Controllers/FirebaseController.php:33-36` | |
| Guest | `guest_users.fcm_token` | `lavkoo:database/migrations/2023_08_12_215346_create_guest_users_table.php:19`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:650-653` | |
| Seller (تطبيق/ويب) | `sellers.cm_firebase_token`، `cm_firebase_token_web` | `lavkoo:database/migrations/2022_01_11_234310_add_firebase_toke_to_sellers_table.php:17`؛ `lavkoo:database/migrations/2026_05_10_000001_add_cm_firebase_token_web_to_sellers_table.php:11`؛ `lavkoo:app/Http/Controllers/FirebaseController.php:49-59` | |
| Delivery man | `delivery_men.fcm_token` | `lavkoo:database/migrations/2021_11_27_051512_add_fcm_col_for_delivery_man.php:17` | |

لا يوجد جدول أجهزة، ولا يُخزَّن مع التوكن أي سياق (منصة، إصدار، متجر، لغة). **VERIFIED**.

**جرد الـ topics في الـ backend** (البحث: `sendPushNotificationToTopic|'topic'|topic:` في `app routes resources`؛ فُحصت كل النتائج ذات الصلة بـ FCM، 9 مواضع إرسال/تعريف):

| اسم الـ topic | من يرسل | الدليل | من يشترك |
|---|---|---|---|
| `sixvalley` (القيمة الافتراضية) | الإشعار الإداري العام (إضافة وإعادة إرسال) | `lavkoo:app/Traits/PushNotificationTrait.php:449`؛ `lavkoo:app/Http/Controllers/Admin/Notification/NotificationController.php:69`؛ `lavkoo:app/Http/Controllers/Admin/Notification/NotificationController.php:163` | تطبيق العميل عند تحديث التوكن `lavkooapp:lib/features/auth/domain/repositories/auth_repository.dart:95-96` (الثابت `lavkooapp:lib/utill/app_constants.dart:334-335`)؛ متصفح الويب عبر `getFCMTopicListToSubscribe` `lavkoo:app/Utils/settings.php:264-270` |
| `maintenance_mode_start_user_app` | تبديل وضع الصيانة | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:271` | تطبيق العميل عند الإقلاع `lavkooapp:lib/features/splash/controllers/splash_controller.dart:145`؛ الويب `lavkoo:app/Utils/settings.php:267` |
| `maintenance_mode_start_vendor` | تبديل وضع الصيانة (عند `business_mode=multi`) | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:275` | تطبيق البائع `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:89-90` (الثابت `lavkooappseller:lib/utill/app_constants.dart:401`) |
| `maintenance_mode_start_deliveryman` | تبديل وضع الصيانة | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:279` | تطبيق المندوب (خارج المستودعات الثلاثة — UNKNOWN) |
| `theme_updates_user_app` | نشر الثيم (data-only) | `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27`؛ `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:48-70` | تطبيق العميل عند الإقلاع وقبل تسجيل الدخول `lavkooapp:lib/features/splash/controllers/splash_controller.dart:147` (الثابت `lavkooapp:lib/utill/app_constants.dart:329`) |
| `restock_<id>_product_restock_<product_id>_topic` | إشعار إعادة التوفّر | `lavkoo:app/Utils/product.php:193-196`؛ `lavkoo:app/Services/ProductService.php:1157`؛ `lavkoo:app/Listeners/RestockProductNotificationListener.php:38-57` | تطبيق العميل بعد طلب إعادة التوفّر `lavkooapp:lib/features/cart/controllers/cart_controller.dart:183` و`lavkooapp:lib/features/restock/controllers/restock_controller.dart:70`؛ الويب عبر جلسة `customer_fcm_topic` `lavkoo:app/Utils/CustomerManager.php:172-186` |
| `demo_reset` | إعادة ضبط نسخة العرض | `lavkoo:app/Traits/PushNotificationTrait.php:324-338` | غير مستخدم إنتاجياً |

ملاحظات **VERIFIED**: (أ) تطبيق البائع يشترك في topic باسم `lavkoo_seller` — `lavkooappseller:lib/utill/app_constants.dart:400` — ولا يوجد في الـ backend أي إرسال إلى هذا الاسم (البحث `lavkoo_seller` في `app routes resources config` بلا نتائج)؛ الإشعار الإداري العام يصل البائعين عبر حلقة على توكناتهم الفردية — `lavkoo:app/Http/Controllers/Admin/Notification/NotificationController.php:76-87`. (ب) لا يحمل أي اسم topic لاحقة دولة أو متجر أو لغة. (ج) اشتراك topics من الويب يتم عبر نقطة خادم مخصّصة — `lavkoo:routes/admin/routes.php:168-170` — انظر `SEC-E06-01`.

**المحفّزات والطابور.** إشعارات حالة الطلب تصدر من `OrderStatusEvent` في 38 موضع إطلاق ضمن 6 متحكمات (Admin 11، Vendor 7، API v3 seller 9، API v2 seller 5+2، delivery man 4 — عدّ بـ rg). الـ listener `OrderStatusListener` **غير** مُدرج في الطابور (لا ينفّذ `ShouldQueue`) — `lavkoo:app/Listeners/OrderStatusListener.php:8-10` — فيُرسل الـ push داخل الطلب HTTP نفسه، بينما `OrderPlacedListener` في الطابور — `lavkoo:app/Listeners/OrderPlacedListener.php:20-22`. من 24 listener في جذر `app/Listeners/` ينفّذ 16 منها `ShouldQueue` (والثمانية الباقية متزامنة، منها `OrderStatusListener` و`ChattingListener` و`RefundListener`)، ولا يحدد أيٌّ منها اسم طابور (`onQueue`/`$queue`: البحث في `app/Listeners app/Jobs app/Mail` بلا نتائج). اتصال الطابور الافتراضي في الكود `sync` إن لم يُضبط `QUEUE_CONNECTION` — `lavkoo:config/queue.php:16` — وقيمة الإنتاج **UNKNOWN**.

**مسار قديم ما زال مستخدماً.** `Helpers::send_push_notif_to_device` يخاطب نقطة FCM القديمة `fcm/send` بمفتاح خادم — `lavkoo:app/Utils/Helpers.php:476-481` — ويُستدعى لإرسال رمز تحقق التسليم — `lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:811`. **VERIFIED** (صلاحية هذه النقطة لدى Google خارج نطاق القراءة الثابتة).

**الإشعارات داخل التطبيق.** `notifications` جدول عام بلا مستلم محدد (الحقول: `sent_by`,`sent_to`,`title`,`description`,`image`,`status`) — `lavkoo:app/Models/Notification.php:26-34`؛ الـ API يعيد كل ما `sent_to='customer'` لكل العملاء — `lavkoo:app/Http/Controllers/RestAPI/v1/NotificationController.php:13-26`. إشعارات البائع التشغيلية تُسجَّل لكل `seller_id` — `lavkoo:app/Services/SellerIntelligence/SellerNotifier.php:153-180`.

### 2.3 البريد الإلكتروني

- **هوية المرسل واحدة.** `MailConfigServiceProvider::boot` يستبدل إعداد `mail` كاملاً عند الإقلاع من `mail_config` (أو `mail_config_sendgrid` إن كان الأول معطلاً)، بما فيه `from.address` و`from.name` — `lavkoo:app/Providers/MailConfigServiceProvider.php:26-50`. لا يوجد mailer ثانٍ ولا اختيار مرسل بحسب السياق. **VERIFIED**.
- **القوالب.** `EmailTemplateTrait::sendingMail` يجلب القالب بـ (`user_type`,`template_name`) ويطبّق ترجمة اللغة الحالية — `lavkoo:app/Traits/EmailTemplateTrait.php:34-79`؛ علاقة الترجمة مقيَّدة بـ `getDefaultLanguage()` — `lavkoo:app/Models/EmailTemplate.php:84-87`. المفاتيح 17 ثابتاً موزعة على أربع قوائم (admin/vendor/customer/delivery-man) — `lavkoo:app/Enums/EmailTemplateKey.php:25-53`. النصوص الافتراضية تُضمِّن `url('/')` واسم الشركة العام `company_name` لحظة إنشاء القالب — `lavkoo:app/Services/EmailTemplateService.php:126-154`. **VERIFIED**.
- **لغة البريد من الطابور.** `getDefaultLanguage()` يعتمد على الجلسة أو مسار `/api` — `lavkoo:app/Utils/language.php:104-130` — وداخل عامل الطابور لا جلسة مستخدم، فيُستنتج أن البريد المُرسل من listener في الطابور يستخدم لغة النظام الافتراضية لا لغة المستلم. **INFERRED** (يستند إلى غياب تمرير لغة المستلم إلى `sendingMail` وإلى منطق الدالة؛ يحسمه اختبار تشغيلي).
- **الطابور.** الـ listeners البريدية تستخدم `QueuedMailDelivery` (timeout 30، tries 2، backoff 60) — `lavkoo:app/Listeners/Concerns/QueuedMailDelivery.php:23-30`. فشل الإرسال يُبتلع داخل `sendingMail` — `lavkoo:app/Traits/EmailTemplateTrait.php:70-73`.
- **السلة المتروكة.** الأمر `cart:remind-abandoned` مجدول كل 30 دقيقة ومرحلة ثانية يومياً — `lavkoo:app/Console/ScheduleDefinition.php:28` و`lavkoo:app/Console/ScheduleDefinition.php:39` — ولا يرسل ما لم يُفعَّل الإعداد (الافتراضي 0) — `lavkoo:app/Services/Retention/AbandonedCartService.php:60-63` و`lavkoo:app/Console/Commands/SendAbandonedCartReminders.php:44-49`. الرابط موقَّع وصالح 30 يوماً — `lavkoo:app/Console/Commands/SendAbandonedCartReminders.php:132-135`. قالب الرسالة (105 أسطر) لا يحوي رابط إلغاء اشتراك (البحث `unsubscribe|opt.?out` في `resources/views/email-templates/abandoned-cart-reminder.blade.php` بلا نتائج). **VERIFIED**.

### 2.4 SMS وOTP وWhatsApp

- **SMS OTP.** `SMSModule::send` يجرّب بالترتيب twilio → nexmo → 2factor → msg91 → releans → alphanet_sms ويستخدم أول مزوّد `status=1` — `lavkoo:app/Utils/SMSModule.php:18-51` — والإعداد من `addon_settings` بمفتاح الاسم فقط — `lavkoo:app/Utils/SMSModule.php:240-250`. لا اختيار للمزوّد بحسب دولة رقم الهاتف. **VERIFIED**.
- **Zender.** `ZenderOtpService::send` يقرأ `ZenderSetting::first()` (صف واحد) ويرسل SMS أو WhatsApp بحسب `type` — `lavkoo:app/Services/ZenderOtpService.php:11-54`؛ الحقول في `lavkoo:app/Models/ZenderSetting.php:9-30` (السر مشفّر عبر cast). يُستدعى أولاً في تسجيل العميل عبر الـ API ثم يُرجَع إلى `SMSModule` — `lavkoo:app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:270-275` (7 مواضع استدعاء لـ `ZenderOtpService` في `app/`؛ فُحص موضع واحد بالتفصيل). لا توجد migration لجدول `zender_settings` في المستودع (انظر 2.11). تسجيل الخدمة للسجلات: `SEC-E06-03`.
- **WhatsApp عبر Chatwoot.** `ChatwootService` يقرأ أول صف `provider='chatwoot'` مفعّل من `integration_settings` (حساب واحد، inbox واحد) — `lavkoo:app/Services/ChatwootService.php:17-49` — ويشترط رقماً دولياً يبدأ بـ `+` — `lavkoo:app/Services/ChatwootService.php:284-297`. ربط القوالب بالأحداث في `notification_template_mappings` (الحقول: `provider`,`template_name`,`template_language`,`event`,`recipient_field`,`variable_mapping`,`event_condition`) — `lavkoo:app/Models/NotificationTemplateMapping.php:11-21`.
- **أتمتة حالة الطلب عبر WhatsApp غير موصولة.** `NotificationAutomationService` يعرّف `trigger` و`triggerOrderStatusChanged` — `lavkoo:app/Services/NotificationAutomationService.php:18-98` و`lavkoo:app/Services/NotificationAutomationService.php:275-345` — ولا يوجد أي مستدعٍ له خارج ملفه (البحث `NotificationAutomationService|triggerOrderStatusChanged` في `app routes`: لا نتائج خارج الملف). **VERIFIED** (كود خامل).
- **تسويق السلة عبر WhatsApp (يدوي).** `CartMarketingController::index` يسرد كل العملاء المسجّلين ذوي سلال غير فارغة ورقم هاتف، و`send` يرسل قالباً لمن يختارهم المشرف — `lavkoo:app/Http/Controllers/Admin/CartMarketingController.php:14-63` و`lavkoo:app/Http/Controllers/Admin/CartMarketingController.php:68-94` — دون أي فحص موافقة تسويقية. المسارات محمية بـ `admin` + `module:3rd_party_setup` — `lavkoo:routes/admin/routes.php:128-150`. **VERIFIED**.

### 2.5 الجماهير التسويقية والموافقات

| العنصر | ما يوجد | الدليل | الحالة |
|---|---|---|---|
| قائمة بريدية | جدول `subscriptions` بعمود `email` فقط؛ الاشتراك من نموذج الويب بلا تأكيد مزدوج | `lavkoo:app/Models/Subscription.php:29-31`؛ `lavkoo:app/Http/Controllers/Web/WebController.php:1576-1595` | VERIFIED |
| شرائح العملاء | `customer_segments` (`name`,`key`,`status`,`rules`)؛ العضوية تُحسب وقت الطلب من 4 حقول رقمية | `lavkoo:app/Models/CustomerSegment.php:17`؛ `lavkoo:app/Services/Commerce/SegmentRules.php:31-33` | VERIFIED — تُستخدم لاستهداف أقسام الصفحة لا للإرسال |
| حملات الواجهة | `ExperienceCampaign` = overlay مجدول على صفحة مركّبة (`name`,`status`,`page`,`priority`,`starts_at`,`ends_at`,`overrides`) | `lavkoo:app/Models/ExperienceCampaign.php:33`؛ cache `commerce_campaign_overrides_<page>` `lavkoo:app/Services/Commerce/CampaignResolver.php:44` | VERIFIED — لا بُعد متجر في الصف ولا في مفتاح الـ cache |
| روابط الحملات القصيرة | `/go/{code}` + QR | `lavkoo:routes/web/routes.php:132-139`؛ `lavkoo:app/Services/Analytics/CampaignService.php:298-301` | VERIFIED |
| موافقة التحليلات | cookie `6valley_cookie_consent`؛ الاشتراط معطّل افتراضياً | `lavkoo:app/Services/Analytics/Support/PrivacyGate.php:22`؛ `lavkoo:app/Services/Analytics/Support/PrivacyGate.php:47-62`؛ `lavkoo:app/Services/Platform/PolicyRegistry.php:296-299` | VERIFIED |
| شريط الكوكيز | إعداد `cookie_setting` (حالة + نص واحد)، الافتراضي معطّل | `lavkoo:app/Traits/InstallationTrail.php:83-86`؛ `lavkoo:resources/themes/default/layouts/front-end/app.blade.php:382` | VERIFIED |
| موافقة تسويقية (بريد/SMS/WhatsApp/Push) | **لا يوجد سجل موافقة** | البحث `consent|opt_in|opt-in|opt_out|unsubscribe|newsletter|marketing|accepts_marketing|gdpr` في `app routes config database Modules resources/views resources/themes bootstrap`: النتائج الوحيدة ذات الصلة هي موافقة التحليلات أعلاه؛ نموذج النشرة في تذييل الويب يُرسل البريد فقط إلى `subscription` بلا حقل موافقة (`lavkoo:app/Http/Controllers/Web/WebController.php:1576-1595`) | VERIFIED (غياب موثَّق بالبحث) |
| بكسلات/سكربتات تسويق | `analytic_scripts` و`MetaAppIntegration` بلا بُعد متجر | `lavkoo:app/Models/AnalyticScript.php:25-33`؛ `lavkoo:app/Models/MetaAppIntegration.php:12-21` | VERIFIED |

### 2.6 الصفحات القانونية وصفحات السياسات

- **نموذج البيانات.** `business_pages`: `title`,`slug`,`description`,`status`,`default_status` وطوابع زمنية فقط — `lavkoo:database/migrations/2025_03_08_201607_create_business_pages_table.php:14-23` و`lavkoo:app/Models/BusinessPage.php:28-36`. لا يوجد: عمود لغة أو علاقة ترجمة في الـ model، عمود دولة/متجر/كيان قانوني، رقم إصدار، حالة مسودة/معتمَد، من اعتمد ومتى، تاريخ سريان. **VERIFIED**.
- **الصفحات الافتراضية السبع** (`about-us`,`terms-and-conditions`,`privacy-policy`,`refund-policy`,`return-policy`,`cancellation-policy`,`shipping-policy`) تُنشأ/تُرحَّل من إعدادات قديمة — `lavkoo:app/Traits/UpdateClass.php:969-1023` — وثلاث منها تُفرض عليها `status=1` دائماً عند التعديل — `lavkoo:app/Services/BusinessPageService.php:24-26` و`lavkoo:app/Services/BusinessPageService.php:44-46`. الحد الأقصى 17 صفحة — `lavkoo:app/Http/Controllers/Admin/Settings/PagesController.php:75-78`.
- **سير العمل.** التعديل كتابة مباشرة فوق الصف الحي — `lavkoo:app/Http/Controllers/Admin/Settings/PagesController.php:102-127` — والنشر مفتاح `status` واحد — `lavkoo:app/Http/Controllers/Admin/Settings/PagesController.php:129-135`. الصلاحية: `module:business_settings` — `lavkoo:routes/admin/routes.php:1830-1832`. لا مسودة ولا اعتماد ولا سجل إصدارات. **VERIFIED**. (سلوك العرض للصفحات غير المنشورة: `SEC-E06-04`.)
- **التوزيع.** الويب: `GET business-page/{slug}` — `lavkoo:routes/web/routes.php:213` و`lavkoo:app/Http/Controllers/Web/PageController.php:26-42`. التطبيقان: `GET /api/v1/business-pages?type=` — `lavkoo:routes/rest_api/v1/api.php:65-68` و`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:310-320` — من cache عام لثلاث ساعات — `lavkoo:app/Traits/CacheManagerTrait.php:540-545`. تطبيق العميل وتطبيق البائع يستهلكان **المسار نفسه والمحتوى نفسه** — `lavkooapp:lib/utill/app_constants.dart:216` و`lavkooappseller:lib/utill/app_constants.dart:200`. لا توجد سياسة مستقلة للبائعين. **VERIFIED**.
- **الارتباط بالطلب.** لم يُعثر في هذا المجال على تخزين «أي إصدار من الشروط وافق عليه العميل» مع الطلب أو الحساب (البحث عن `terms` في جداول هذا المجال لم يُظهر عمود قبول). **INFERRED** — التحقق النهائي عند مدقّق مسار الطلب (E09).

### 2.7 وضع الصيانة

- **الدقة الحالية = «نظام» لا «متجر».** الإعداد يحدّد خمسة أنظمة: `user_app`,`user_website`,`vendor_app`,`deliveryman_app`,`vendor_panel` — `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:217-221` — مع مدة (`until_change` أو نافذة تاريخين) ورسالة — `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:235-251`. القيم الافتراضية كلها 0 — `lavkoo:app/Traits/UpdateClass.php:498-517`. **VERIFIED**.
- **مصدر الإنفاذ.** `checkMaintenanceMode` يقرأ **فقط** مفتاح الـ cache `system_maintenance_mode` — `lavkoo:app/Traits/MaintenanceModeTrait.php:10-32` — الذي يُكتب لسنة عند التفعيل ويُحذف عند الإيقاف — `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:255-268`. لا يوجد مسار يعيد بناء المفتاح من `business_settings` (البحث `system_maintenance_mode` في المستودع كله: 3 مواضع فقط، قراءة واحدة وكتابة وحذف). إذن أي تفريغ للـ cache (مثل `optimize:clear` عند النشر أو تفعيل add-on — `lavkoo:app/Services/AddonService.php:130`، `lavkoo:app/Http/Controllers/Admin/Settings/EnvironmentSettingsController.php:72-74`) ينهي الإنفاذ بصمت بينما تبقى الإعدادات في قاعدة البيانات «مفعّلة». **VERIFIED** (المسار)؛ الأثر الفعلي يعتمد على driver الـ cache في الإنتاج — الافتراضي `file` `lavkoo:config/cache.php:21` — **UNKNOWN**. انظر أيضاً `SEC-E06-02`.
- **أين يُطبَّق.** الـ alias `maintenance_mode` — `lavkoo:bootstrap/app.php:122` — موضوع على: مجموعتَي الواجهة — `lavkoo:routes/web/routes.php:150` و`lavkoo:routes/web/routes.php:162` — ولوحة البائع الكلاسيكية — `lavkoo:routes/vendor/routes.php:45` — وSeller Center — `lavkoo:routes/seller/routes.php:40`. عند التفعيل يعيد التوجيه إلى `maintenance-mode` — `lavkoo:app/Http/Middleware/MaintenanceModeMiddleware.php:22-31`.
- **استثناء الإدارة.** الجلسات المسجّلة بحارس `admin` لا تخضع للصيانة — `lavkoo:app/Traits/MaintenanceModeTrait.php:13`.
- **ما يبقى يعمل أثناء الصيانة (VERIFIED بقراءة ملفات المسارات):**

| المسار | داخل مجموعة `maintenance_mode`؟ | الدليل |
|---|---|---|
| الصفحة الرئيسية، المنتجات، التصنيفات، صفحات السياسات، تتبع الطلب، حساب العميل، صفحات checkout | نعم (تُحجب) | `lavkoo:routes/web/routes.php:162-357` |
| عمليات السلة والكوبون (`cart/*`, `coupon/*`) | لا | `lavkoo:routes/web/routes.php:359-388` |
| مصادقة العميل (`customer/auth/*`) | لا | `lavkoo:routes/web/routes.php:398-456` |
| بدء الدفع ونتيجته (`customer/web-payment-request`, `payment-success`, `payment-fail`) | لا | `lavkoo:routes/web/routes.php:458-470` |
| callbacks بوابات الدفع (`payment/*` ومنها Paymob وMyFatoorah) | لا | `lavkoo:routes/web/routes.php:574-612` |
| كل الـ REST API (v1/v2/v3) | لا — لا يوجد `maintenance` في `routes/rest_api/` (بحث بلا نتائج) | `lavkoo:routes/rest_api/v1/api.php:54` |
| webhooks (fulfillment، MyFatoorah، DeliverySyria) | لا | `lavkoo:app/Providers/RouteServiceProvider.php:127-155` |
| لوحة الإدارة | لا | `lavkoo:app/Traits/MaintenanceModeTrait.php:21` |
| الطوابير والمهام المجدولة | لا علاقة لها بالإعداد (لا قراءة للمفتاح خارج الـ trait) | البحث أعلاه |

- **التطبيقات.** الـ API لا يُحجب؛ `GET /api/v1/config` يعيد `maintenance_mode` (الحالة، الأنظمة المختارة، الرسائل، المدة) — `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:106-111` — والتطبيق هو من يعرض شاشة الصيانة — `lavkooapp:lib/features/splash/controllers/splash_controller.dart:94-95` — ويُنبَّه لحظياً عبر topic الصيانة (2.2). أي أن صيانة التطبيقات **إنفاذ من طرف العميل** وليست حجباً من الخادم. الفرع `api/*` في الـ trait يُستخدم لتقرير الحالة في `GET /api/v1/config` (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:107`)، ويقرأ الخادم من الأنظمة الخمسة اثنين فقط: `user_website` و`vendor_panel` (`lavkoo:app/Traits/MaintenanceModeTrait.php:17-22`). **VERIFIED**.
- **الوضع الإطاري.** `Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode` (أمر `artisan down`) مسجَّل عالمياً — `lavkoo:bootstrap/app.php:44-47` — وهو شامل لكل شيء بما فيه الـ webhooks، بلا استثناءات معرّفة — `lavkoo:app/Http/Middleware/CheckForMaintenanceMode.php:14-16` (هذا الصنف المحلي غير مسجَّل في `bootstrap/app.php`).
- **أقرب نظير لـ «إيقاف جزئي» موجود اليوم:** إغلاق/إجازة متجر البائع (`temporary_close`/`vacation`) الذي يمنع الإضافة للسلة والطلب لمتجر بعينه مع بقاء التصفح — `lavkoo:app/Utils/CartManager.php:765-768` و`lavkoo:app/Utils/OrderManager.php:2843`. هذا على مستوى `shop` (البائع) لا على مستوى دولة. **VERIFIED** (فُحص موضعان من 12 ملفاً تستخدم المفتاحين).

### 2.8 بنية المسارات واللغة والعملة

- **لا بادئة لغة ولا بادئة دولة.** مسارات الويب تُحمَّل بلا `prefix` — `lavkoo:app/Providers/RouteServiceProvider.php:215-220` — والمسارات العامة جذرية: `/` — `lavkoo:routes/web/routes.php:164-166`، `/product/{slug}` — `lavkoo:routes/web/routes.php:227-229`، `/brand/{slug}` و`/category/{slug}` — `lavkoo:routes/web/routes.php:236-237`، `/vendor-shop/{slug}` — `lavkoo:routes/web/routes.php:344`، `/business-page/{slug}`، `/track-order/*` — `lavkoo:routes/web/routes.php:277-284`، `/p/{slug}`، `/go/{code}`. لا يوجد `URL::defaults` ولا `forceRootUrl` ولا مسار catch-all في الواجهة (البحث في `app bootstrap routes`: النتيجة الوحيدة `URL::forceScheme('https')` — `lavkoo:app/Providers/AppServiceProvider.php:169` — و`Route::fallback` في `routes/update.php` غير محمَّل لأن استدعاء `mapUpdateRoutes` معلَّق (`lavkoo:app/Providers/RouteServiceProvider.php:57-58`). الـ API تحت `/api/v1|v2|v3` بلا بُعد دولة — `lavkoo:app/Providers/RouteServiceProvider.php:97-119`. **VERIFIED**.
- **اللغة في الويب = جلسة فقط.** تبديل اللغة يكتب مفتاح الجلسة `local` والاتجاه — `lavkoo:app/Http/Controllers/SharedController.php:30-33` — و`getDefaultLanguage()` يقرأه — `lavkoo:app/Utils/language.php:104-130`. الـ middleware `Localization` يقرأ مفتاحاً **مختلفاً** هو `locale` — `lavkoo:app/Http/Middleware/Localization.php:18-24` — ولا يوجد في `app routes Modules` أي كتابة لمفتاح `locale` في الجلسة (البحث `put('locale'` بلا نتائج)، فهذا الـ middleware لا أثر له عملياً والـ locale يضبطه `translate()` عند كل نداء — `lavkoo:app/Utils/language.php:8-17`. **VERIFIED**.
- **رموز اللغات «شبه دول».** مجلدات الترجمة الموجودة: `ae`,`bd`,`en`,`es`,`in`,`ks`,`sa` — مثلاً `lavkoo:resources/lang/sa/messages.php` و`lavkoo:resources/lang/ae/messages.php` — أي أن العربية تُعرَّف في هذا النظام برمز `sa` أو `ae`. الـ API يطوي `sa→ar` و`us→en` ثم يطابق مع لغات المتجر بالاسم — `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:21-31` و`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:44-89` — والتعليق في الكود ينص على أن إصدارات منشورة من التطبيق أرسلت رمز الدولة في ترويسة `lang`. **VERIFIED**. هذا تداخل دلالي مباشر مع بادئات المسار المعتمدة `/sa` و`/ae`.
- **العملة في الويب = جلسة فقط.** `currency.change` يكتب `currency_code`,`currency_symbol`,`currency_exchange_rate` في الجلسة — `lavkoo:app/Http/Controllers/Web/CurrencyController.php:18-28`. (تفاصيل التحويل في ملحق E02.)
- **اختيار الدولة / الاستدلال بالـ IP.** غير موجود: لا geo-IP ولا قراءة ترويسة دولة في مسار الواجهة (البحث `geoip|ip-api|ipinfo|CF-IPCountry` في `app config`: النتيجة الوحيدة الوظيفية ترويسة اختيارية للتحليلات، معطّلة افتراضياً — `lavkoo:app/Services/Analytics/VisitorContext.php:187-203`). **VERIFIED**.

### 2.9 SEO

- **canonical.** صفحة المنتج تصدر canonical = `route('product', slug)` عند غياب قيمة صريحة — `lavkoo:app/View/Composers/ProductSeoSupplementComposer.php:61-66`؛ باقي الصفحات تصدره فقط إن أدخل المشرف `canonicals_url` يدوياً — `lavkoo:resources/themes/default/web-views/partials/_robotsMetaContentData.blade.php:62-63`. **VERIFIED**.
- **hreflang.** صفحة المنتج تصدر بدائل بصيغة `<url>?language=<code>` — `lavkoo:app/View/Composers/ProductSeoSupplementComposer.php:136-159` — وكذلك الـ sitemap — `lavkoo:app/Http/Controllers/Admin/Settings/SiteMapController.php:125-138`. لكن لا يوجد في الواجهة أي كود يقرأ بارامتر `language` (البحث `request('language')|query('language')|->language\b|has('language')` في `app Modules`: لا قارئ في متحكمات أو middleware الواجهة؛ مدونة Blog تقرأ `locale` لا `language`). النتيجة: عناوين البدائل تعيد محتوى لغة الجلسة نفسها، فإشارات hreflang الحالية لا تقابلها نسخ لغوية قابلة للفهرسة. **VERIFIED** (غياب موثَّق بالبحث). الصنف `SitemapHreflangGenerator` موجود لكنه غير مستدعى من أي كود تشغيلي — `lavkoo:app/Services/Seo/SitemapHreflangGenerator.php:15` (البحث عن اسمه في `app tests routes`: الملف نفسه فقط).
- **sitemap.** يولَّد يدوياً من لوحة الإدارة بزحف `SitemapGenerator::create(url('/'))` مع إضافة المنتجات والمتاجر والصفحات، ويُكتب ملفاً واحداً `sitemap.xml` في `public/` وجذر المشروع — `lavkoo:app/Http/Controllers/Admin/Settings/SiteMapController.php:111-140` و`lavkoo:app/Http/Controllers/Admin/Settings/SiteMapController.php:214-218`. لا فهرس sitemap ولا تقسيم. الملف المتتبَّع في git فارغ (0 بايت): `lavkoo:public/sitemap.xml`. لا جدولة لتوليده (لا أمر sitemap في `ScheduleDefinition`). **VERIFIED**.
- **robots.txt.** ملفان ثابتان متتبَّعان: جذر المشروع يشير إلى `https://lavkoo.com/sitemap.xml` — `lavkoo:robots.txt:1-3` — و`public/` يشير إلى عنوان تطوير محلي — `lavkoo:public/robots.txt:1-3`. يُكتب عند التثبيت — `lavkoo:app/Traits/InstallationTrail.php:26`. أيهما يُخدَّم في الإنتاج يعتمد على جذر النطاق — **UNKNOWN**.
- **الـ meta.** ثلاث طبقات: ترجمة لكل لغة (`seo_meta_translations`) ← أساس (`seo_meta_info`) ← قالب (`seo_templates` بـ `entity_type`+`language_code`) — `lavkoo:app/Services/Seo/SeoMetaResolver.php:34-66` و`lavkoo:app/Services/Seo/SeoMetaResolver.php:85-118`. كلها بمفتاح (نوع، معرّف، لغة) بلا بُعد دولة. البيانات المنظَّمة تأخذ العملة من `getCurrencyCode()` — `lavkoo:app/View/Composers/ProductSeoSupplementComposer.php:105-106`.
- **إعادة التوجيه.** جدول `redirects` (`from_path`,`to_path`,`status_code`,`match_type`,`is_active`,…) — `lavkoo:app/Models/Redirect.php:24-35` — يطبَّق في مجموعة `web` — `lavkoo:bootstrap/app.php:69` — مع استثناء `/api`,`/admin`,`/vendor`,`/install`,`/update`,`/payment` — `lavkoo:app/Http/Middleware/ApplySeoRedirects.php:32` — و cache عام `seo_active_redirects` لخمس دقائق — `lavkoo:app/Models/Redirect.php:22` و`lavkoo:app/Http/Middleware/ApplySeoRedirects.php:75-86`. المطابقة exact ثم أطول prefix — `lavkoo:app/Services/Seo/RedirectResolverService.php:46-92`. **VERIFIED**. هذه الآلية هي أنسب ما يُبنى عليه لتحويل المسارات القديمة إلى `/sa/...`. لا توجد migration لجدول `redirects` (2.11).
- **تفرّد الـ slug.** للعلامات والتصنيفات: فريد عالمياً على مستوى الجدول مع لاحقة `-02`… — `lavkoo:app/Traits/GeneratesUniqueSlug.php:23-50` و`lavkoo:app/Traits/GeneratesUniqueSlug.php:58-66`. للصفحات: `unique:business_pages,slug` — `lavkoo:app/Http/Requests/Admin/BusinessPageAddRequest.php:19-21`. (slug المنتج عند E08.)

### 2.10 الروابط العميقة / App Links / Universal Links

- **الإعداد.** إعداد واحد `business_settings.app_deep_link` يحمل package أندرويد واحداً وbundle iOS واحداً وروابط المتجرين — `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:346-363` و`lavkoo:app/Services/DeepLink/AppLinkService.php:53-70`. أي أن تطبيقاً واحداً (تطبيق العميل) هو المُعلَن. **VERIFIED**.
- **المسارات المنشورة.** `config/deeplinks.php`: `/`→home، `/product/*`→product، `/brand/*`→brand، `/products`→product_list، `/go/*`→campaign — `lavkoo:config/deeplinks.php:39-45`؛ وقوائم المنصتين — `lavkoo:config/deeplinks.php:60-72`. `/track-order` مستبعد عمداً بحسب التعليق — `lavkoo:config/deeplinks.php:30-36`.
- **ملفات `.well-known`.** تُكتب من الإعداد عبر `AssociationFileWriter::publish` إلى جذر المشروع و`public/` — `lavkoo:app/Services/DeepLink/AssociationFileWriter.php:28-38` — عند حفظ الشاشة أو بالأمر `deeplinks:publish` — `lavkoo:app/Console/Commands/DeepLinkPublish.php:21`. iOS يأخذ قائمة المسارات من الملف؛ أندرويد لا مسارات في الملف — `lavkoo:app/Services/DeepLink/AssociationFileWriter.php:48-67` و`lavkoo:app/Services/DeepLink/AssociationFileWriter.php:73-93`.
- **الملفان المتتبَّعان في git قيمتهما placeholder:** `assetlinks.json` يسمّي الحزمة `com.sixamtech.sixvalley` (حزمة القالب الأصلي) — `lavkoo:public/.well-known/assetlinks.json:8` — و`apple-app-site-association` يحمل `appID` بقيمة `"."` ومسارات `/`,`/product/*`,`/track-order/*` (والمسار الأخير يخالف استبعاد `/track-order` في `lavkoo:config/deeplinks.php:30-36`) — `lavkoo:public/.well-known/apple-app-site-association:6-11`. الكود نفسه يوثّق أن هذه القيمة لا تدّعي أي تطبيق — `lavkoo:app/Services/DeepLink/AssociationFileWriter.php:123-140`. حالة الملفين على خادم الإنتاج **UNKNOWN**.
- **ما تدّعيه التطبيقات.**

| التطبيق | المنصة | الادعاء | الدليل |
|---|---|---|---|
| العميل | Android | host `lavkoo.com`، `autoVerify`، المسارات: `/`، `/track-order`، `/referral-login`، `/product/`، `/vendor-shop/`، `/brand/`، `/products`، `/go/` | `lavkooapp:android/app/src/main/AndroidManifest.xml:122-142` |
| العميل | iOS | `applinks:pharmacyksa.com` | `lavkooapp:ios/Runner/Runner.entitlements:17-20` |
| العميل | الاتصال بالـ API | `baseUrl = https://lavkoo.com` | `lavkooapp:lib/utill/app_constants.dart:39` |
| البائع | Android | مخطط مخصص `lavkooseller` فقط، بلا https app links | `lavkooappseller:android/app/src/main/AndroidManifest.xml:88-93` |

المنصتان تدّعيان **نطاقين مختلفين** (`lavkoo.com` على أندرويد و`pharmacyksa.com` على iOS)، وقائمة الـ manifest على أندرويد أوسع من `android_paths` في `lavkoo:config/deeplinks.php:60-72` (تضيف `/track-order`، `/referral-login`، `/vendor-shop/`، و`/go/` الموجود في `enabled_routes` وغائب عن `android_paths`). **VERIFIED** (الكود)؛ أي النطاقين هو الإنتاجي **UNKNOWN**.
- **الحلّ (resolve).** `GET /api/v1/deep-link/resolve?url=` — `lavkoo:routes/rest_api/v1/api.php:81-85` — يطابق المسار حرفياً مع الأنماط أعلاه — `lavkoo:app/Services/DeepLink/DeepLinkResolver.php:158-186` — ويرفض أي host غير `app.url` — `lavkoo:app/Services/DeepLink/DeepLinkResolver.php:195-220` — ويفك الرابط القصير بـ `Str::after($path, '/go/')` — `lavkoo:app/Services/DeepLink/DeepLinkResolver.php:96-99`. التطبيق يحمل نسخة محلية من المنطق تعتمد على **أول مقطع في المسار** — `lavkooapp:lib/data/services/deep_link_resolver.dart:86-118`. أي مسار يبدأ بـ `/sa/` لن يطابق أياً من الطرفين وسيُعامل كـ `web`. **VERIFIED**.

### 2.11 جداول هذا المجال بلا migration في المستودع

نُفّذ البحث `Schema::create('<table>'` في `database Modules app` لكل جدول. الجداول التالية يستخدمها كود تشغيلي ولا يوجد لها أي تعريف مخطط متتبَّع: `zender_settings`، `notification_template_mappings`، `abandoned_cart_reminders`، `customer_segments`، `experience_campaigns`، `experience_experiments`، `meta_app_integrations`، `redirects`، `seo_templates`، `seller_notification_deliveries`، `analytics_events`، `telemetry_requests`، `visit_sessions`، جداول DeliverySyria، وكذلك `notifications` الأصلي. (الموجود له migration: `notification_messages`، `notification_seens`، `email_templates`، `business_pages`، `robots_meta_contents`، `analytic_scripts`، `seo_meta_info`، `subscriptions`، `deliveryman_notifications`، و`integration_settings` (`lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:26-37` ضمن حارس `Schema::hasTable`).) **VERIFIED** (غياب موثَّق بالبحث). أعمدة وفهارس وقيود هذه الجداول في الإنتاج **UNKNOWN** — يلزم مخطط قاعدة الإنتاج. (يُحال إلى E03.)

### 2.12 DeliverySyria — ما هو وهل هو مفعّل؟

- **ما هو.** تكامل اختياري مع شركة شحن سورية (`deliverysyria.com`): مزامنة أسعار المحافظات، إنشاء طرد، واستقبال تحديث حالة. الثوابت: `DEFAULT_BASE_URL` و`DEFAULT_PLATFORM='pharmacyesyria'` — `lavkoo:app/Services/DeliverySyria/DeliverySyriaConfigService.php:23-31`. النماذج: `DeliverySyriaParcel` (سجل خاص بالتكامل مرتبط بـ `order_id`) و`DeliverySyriaGovernorateRate` — `lavkoo:app/Models/DeliverySyriaParcel.php:17-34` و`lavkoo:app/Models/DeliverySyriaGovernorateRate.php:11-21`. هو أثر من أصل المشروع السوري (اسما حزمتَي التطبيقين `syriapharmacy` و`syriacosmeticsmanger` بحسب الموجز).
- **هل هو مفعّل بالكود افتراضياً؟ لا.** `isEnabled()` يشترط `status=1` في إعداد `business_settings.delivery_syria` وإلا 0 — `lavkoo:app/Services/DeliverySyria/DeliverySyriaConfigService.php:49-52`؛ الإرسال يشترط أيضاً وجود السر — `lavkoo:app/Services/DeliverySyria/DeliverySyriaConfigService.php:88-92`. **VERIFIED**. قيمة الإنتاج **UNKNOWN**.
- **نقطة الربط بمسار الطلب.** بعد إنشاء الطلبات في `OrderManager` تُفحص كل طلبية: إن كانت طريقة الشحن فعّالة واسمها حرفياً `DeliverySyria` والتكامل جاهز يُنشأ طرد — `lavkoo:app/Utils/OrderManager.php:1781-1834` (شرط الاسم `lavkoo:app/Utils/OrderManager.php:1827-1829`، شرط الجاهزية `lavkoo:app/Utils/OrderManager.php:1833`). الحلقة تكتب سطر سجل تحذيري لكل طلب حتى لو لم يكن التكامل مستخدماً — `lavkoo:app/Utils/OrderManager.php:1784-1786`. **VERIFIED**.
- **الـ webhook.** `POST /api/delivery-syria/orders/update-status` بلا مقطع إصدار — `lavkoo:routes/rest_api/delivery_syria.php:16-18` و`lavkoo:app/Providers/RouteServiceProvider.php:127-133` — يتحقق من ترويسة منصة ورمز Bearer بمقارنة ثابتة الزمن ويفشل مغلقاً إن كان التكامل معطلاً — `lavkoo:app/Http/Middleware/DeliverySyriaWebhookAuthMiddleware.php:27-45`. شاشة الإعداد تعرض للمشرف عنواناً نصياً مختلفاً عن المسار الفعلي (`/api/v2/delivery-syria/...`) — `lavkoo:resources/views/admin-views/integrations/delivery-syria-settings.blade.php:69` — بينما المتحكم يحسب العنوان الصحيح — `lavkoo:app/Http/Controllers/Admin/ThirdParty/DeliverySyriaController.php:47`.
- **كود قديم خامل.** `App\Services\DeliverySyriaService` (ثابت العنوان والمنصة) بلا مستدعين (البحث `DeliverySyriaService` في `app routes`: لا استخدام خارج الملف) — `lavkoo:app/Services/DeliverySyriaService.php:11-13` — ومتحكم webhook قديم غير موصول بأي مسار — `lavkoo:app/Http/Controllers/DeliverySyriaWebhookController.php:11`. **VERIFIED**.

### 2.13 التحليلات والقياس (مستوى عالٍ)

- ثلاثة مسجِّلات تعمل كـ middleware على مجموعتَي `web` و`api`: `RecordHttpTelemetry`، `MonitorRequest`، `RecordAnalytics` — `lavkoo:bootstrap/app.php:74-93`.
- **ما يُسجَّل:** `telemetry_requests`: القناة، المسار، اسم المسار، الحالة، المدة، `user_type`,`user_id`,`visitor_id`، نطاق المُحيل — `lavkoo:app/Services/Telemetry/TelemetryRecorder.php:78-91`. `analytics_sessions`: المصدر/الوسيط/الحملة، مسار الهبوط، الجهاز، النظام، المتصفح، لغة المتصفح، الدولة (اختيارية من ترويسة CDN، معطّلة افتراضياً)، إصدار التطبيق، `ip_hash` — `lavkoo:app/Services/Analytics/VisitorContext.php:300-321`. الأحداث تحمل `user_id` و`currency` — `lavkoo:app/Services/Analytics/EventRecorder.php:98-107`.
- **بيانات شخصية:** معرّف المستخدم ومعرّف زائر (cookie) وتجزئة عنوان الشبكة؛ لا يُخزَّن الـ IP الخام بحسب الكود (`networkHash`). الإعدادات الافتراضية: قناع الـ IP مفعّل، اشتراط الموافقة معطّل، تخزين الدولة معطّل — `lavkoo:app/Services/Platform/PolicyRegistry.php:296-307`.
- **بُعد متجر/سوق:** غير موجود في أيٍّ من الصفوف المذكورة. **VERIFIED**.
- ملفات `config/analytics.php` و`config/monitoring.php` و`config/telemetry.php` غير موجودة في المستودع (`git ls-files config` لا يحويها)، فكل `config('analytics.*')` يعود إلى القيم الافتراضية المضمّنة في الكود ما لم تُنشأ الملفات على الخادم — **UNKNOWN** للإنتاج.

### 2.14 الروابط الصادرة التي تُضمِّن مساراً أو عنواناً مطلقاً

إحصاء عام (rg): `route(` — 513 موضعاً في `app/` و3222 في `resources/themes`+`resources/views`؛ `url('<literal>')` — 49 في `app/` و84 في `resources/`. المسارات المسمّاة تتبع أي تغيير في تعريف المسار تلقائياً؛ الخطر يتركز في الفئات التالية:

| الفئة | الموضع | كيف يُبنى الرابط | أثر بادئة `/sa` |
|---|---|---|---|
| بريد تأكيد الطلب → تتبع الطلب | `lavkoo:resources/views/email-templates/order-placed-v2.blade.php:252-255` | `route('track-order.result', order_id, phone_number)` | يتبع المسار، لكن الرسالة تُبنى في عامل الطابور بلا سياق متجر؛ يلزم اشتقاق البادئة من الطلب |
| بريد السلة المتروكة | `lavkoo:app/Console/Commands/SendAbandonedCartReminders.php:132-135` | `URL::signedRoute('cart.recover')` صالح 30 يوماً | التوقيع يشمل المسار: أي رابط أُرسل قبل تغيير المسار يفشل تحققه ما لم يبقَ المسار القديم قائماً |
| روابط تذييل البريد (الرئيسية، اتصل بنا، سياسة الخصوصية) | `lavkoo:resources/views/email-templates/customer-registration.blade.php:172-195` (و5 قوالب أخرى بالنمط نفسه) | `route('home')`, `route('business-page.view', slug)` | يتبع المسار؛ يحتاج سياق متجر المستلم ليشير إلى سياسة دولته |
| نصوص القوالب الافتراضية المخزَّنة | `lavkoo:app/Services/EmailTemplateService.php:136-144` | `url('/')` يُحرق في نص القالب عند الإنشاء ويُخزَّن في `email_templates.body` | نص مخزَّن في قاعدة البيانات؛ لا يتغير بتغيّر المسارات |
| إعادة تعيين كلمة المرور (API) | `lavkoo:app/Http/Controllers/RestAPI/v1/auth/ForgotPasswordController.php:74` | `url('/') . '/customer/auth/reset-password?token='` | مسار حرفي |
| تنزيل المنتج الرقمي | `lavkoo:resources/views/email-templates/digital-product-download.blade.php:263-264` | `route(...)` + `url('digital-product-download-pos')` | مختلط |
| callbacks اجتماعية | `lavkoo:app/Providers/SocialLoginServiceProvider.php:37-44` | `url('customer/auth/login/google/callback')` | مسار حرفي مسجَّل لدى Google/Facebook |
| بوابات دفع بمسارات حرفية | `lavkoo:app/Http/Controllers/Payment_Methods/SslCommerzPaymentController.php:86-88`؛ `lavkoo:app/Http/Controllers/Payment_Methods/StripePaymentController.php:94` | `url('/') . '/payment/...'` | مسار حرفي (بوابات غير مستهدفة لـ GCC؛ Paymob/MyFatoorah عند E10) |
| حمولة push لإعادة التوفّر | `lavkoo:app/Services/ProductService.php:1163` | `route('product', slug)` داخل `data.route` | يُستهلك في التطبيق |
| الروابط القصيرة وQR | `lavkoo:app/Services/Analytics/CampaignService.php:298-301`؛ `lavkoo:resources/views/theme-sections/types/app_download.blade.php:10`؛ `lavkoo:app/Http/Controllers/Admin/Settings/ThemeBuilderController.php:918-928` | `config('app.url') . '/go/' . code`؛ QR لـ `url('/')` | مطبوعات ورقية: يجب بقاء `/go/*` والجذر صالحَين دائماً |
| عناوين يعيدها الـ API للتطبيق | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:305-306` | `url('api/v1/address/regions')` | مسار API حرفي (غير متأثر ببادئة الواجهة) |
| sitemap / canonical / hreflang | 2.9 | `route(...)`, `url('/')` | يجب أن تصبح لكل متجر |
| webhook DeliverySyria | `lavkoo:app/Http/Controllers/Admin/ThirdParty/DeliverySyriaController.php:47` | `url('/api/delivery-syria/...')` | مسار API |
| فاتورة PDF | `lavkoo:app/Traits/PdfGenerator.php:57` | `url('/')` في رأس المستند | نص |
| كشف السياق بمقارنة العنوان | `lavkoo:app/Utils/language.php:106`؛ `lavkoo:app/Models/BusinessPage.php:59`؛ `lavkoo:app/Traits/MaintenanceModeTrait.php:18-24` | `strpos(url()->current(), '/api')`، `request()->is('vendor/*')` | منطق يعتمد على شكل المسار؛ `is('vendor/*')` و`is('admin/*')` يفترضان أن اللوحات غير مسبوقة ببادئة |

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الدليل | الحالة |
|---|---|---|---|
| A1 | مشروع Firebase واحد وحساب خدمة واحد لكل الـ push لكل الأطراف | `lavkoo:app/Traits/PushNotificationTrait.php:487`؛ `lavkoo:app/Providers/FirebaseServiceProvider.php:19-22` | VERIFIED |
| A2 | topic بث واحد لكل العملاء (`sixvalley`) وtopic صيانة واحد لكل تطبيق | `lavkoo:app/Traits/PushNotificationTrait.php:449`؛ `lavkoo:app/Utils/settings.php:267` | VERIFIED |
| A3 | توكن واحد لكل مستخدم بلا سياق | 2.2 | VERIFIED |
| A4 | مرسل بريد واحد واسم مرسل واحد | `lavkoo:app/Providers/MailConfigServiceProvider.php:41` | VERIFIED |
| A5 | قوالب البريد والـ push بمفتاح (نوع المستخدم، المفتاح) فقط؛ اسم الشركة العام يُحرق في النص | `lavkoo:app/Traits/EmailTemplateTrait.php:36`؛ `lavkoo:app/Services/EmailTemplateService.php:63` | VERIFIED |
| A6 | مزوّد SMS واحد نشط لكل الأرقام؛ صف Zender واحد؛ حساب Chatwoot واحد | `lavkoo:app/Utils/SMSModule.php:18-51`؛ `lavkoo:app/Services/ZenderOtpService.php:13`؛ `lavkoo:app/Services/ChatwootService.php:19-21` | VERIFIED |
| A7 | مجموعة صفحات قانونية واحدة بلغة تخزين واحدة لكل العملاء والبائعين | 2.6 | VERIFIED |
| A8 | وضع صيانة واحد للنظام كله، محوره «النظام» لا «المتجر» | 2.7 | VERIFIED |
| A9 | الواجهة على جذر النطاق بلا بادئة؛ اللغة والعملة في الجلسة | 2.8 | VERIFIED |
| A10 | رموز لغات على هيئة رموز دول (`sa`,`ae`) | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:27` | VERIFIED |
| A11 | تطبيق واحد مُعلَن للروابط العميقة ونطاق واحد (`app.url`) | `lavkoo:app/Services/DeepLink/DeepLinkResolver.php:215`؛ `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:356-361` | VERIFIED |
| A12 | sitemap واحد، robots واحد، cache إعادة توجيه واحد، قوالب SEO بلا بُعد دولة | 2.9 | VERIFIED |
| A13 | أثر سوري في الكود: تكامل DeliverySyria وثابت المنصة `pharmacyesyria`، وأرقام هاتف يُشترط فيها الرمز الدولي دون تمييز دولة | `lavkoo:app/Services/DeliverySyria/DeliverySyriaConfigService.php:28`؛ `lavkoo:app/Services/ChatwootService.php:290-294` | VERIFIED |
| A14 | التحليلات والحملات والشرائح بلا بُعد متجر | 2.5، 2.13 | VERIFIED |
| A15 | العملة في البيانات المنظَّمة = عملة الجلسة/النظام | `lavkoo:app/View/Composers/ProductSeoSupplementComposer.php:106` | VERIFIED |

لم يُعثر في هذا المجال على ثابت `SAR` أو `+966` مكتوب حرفياً؛ الافتراض السعودي هنا بنيوي (حساب واحد/إعداد واحد) لا نصي.

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم في هذا المجال | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| GCC-11 (سياسات قانونية معتمدة لكل دولة) | محرّر صفحات، slugs ثابتة للسياسات، توزيع للويب والتطبيقين، ربط SEO | لا بُعد دولة، لا مسودة، لا اعتماد، لا إصدارات، لا لغات متعددة في النموذج، لا فحص جاهزية يمنع البيع، ولا سجل قبول | 2.6 |
| GCC-12 (إيقاف لكل متجر: browse-only / maintenance / redirect) | صيانة لكل «نظام» مع نافذة زمنية ورسالة وتنبيه push؛ مسارات الدفع والـ webhooks والـ API خارج الحجب أصلاً؛ نظير browse-only على مستوى shop؛ جدول redirects | لا بُعد متجر؛ لا وضع browse-only؛ لا وضع redirect؛ الإنفاذ من cache فقط؛ التطبيقات تُحجب من طرف العميل؛ الصيانة تحجب «طلباتي» وتتبع الطلب وطلب الاسترجاع على الويب | 2.7 |
| Foundation: one domain with `/sa … /om` paths | مسارات مسمّاة في أغلب المواضع (3735 `route(`)؛ آلية redirects جاهزة | لا بادئة؛ اللغة جلسة؛ روابط حرفية؛ روابط موقَّعة؛ deep links ومسارات التطبيقات مثبّتة على الجذر؛ رموز اللغة `sa`/`ae` | 2.8، 2.10، 2.14 |
| Foundation: manual country choice beats IP | لا يوجد استدلال بالـ IP أصلاً (لا تعارض) | لا يوجد مفهوم اختيار دولة ولا تخزين له | 2.8 |
| GCC-19 (السعودية المتجر الافتراضي) | كل شيء اليوم عالمي = سعودي فعلياً | لا مفهوم «افتراضي» قابل للتغيير؛ الجذر `/` هو المتجر | 2.8 |
| هويات وقوالب اتصال قابلة للضبط لكل متجر (DISCOVERY_PROMPT) | قوالب قابلة للتعديل وترجمات؛ جدول ربط قوالب WhatsApp فيه لغة وشرط حدث | كل الحسابات مفردة؛ لا مفتاح متجر في أي جدول قوالب؛ لا اختيار مزوّد SMS بحسب الدولة | 2.2–2.4 |
| عزل الجماهير التسويقية (DISCOVERY_PROMPT؛ القيد «Marketing/legal consents have correct scopes») | شرائح محسوبة، سجل تذكيرات السلة | topic بث واحد؛ `notifications` عام؛ `subscriptions` بلا متجر؛ لا سجل موافقة تسويقية لأي قناة | 2.2، 2.5 |
| GCC-13 (هوية عميل واحدة، تدفقات منفصلة لكل متجر) | هوية واحدة وتوكن واحد لكل عميل (متوافق مع «هوية واحدة») | الإشعارات المعاملاتية لا تعرف متجر الطلب (القالب، المرسل، الرابط، اللغة) | 2.2، 2.3 |
| GCC-17 (حملات مركزية، قواعد كوبون محلية) | `ExperienceCampaign` (overlay واجهة) وروابط `/go` القصيرة — مركزية بطبيعتها | لا اختيار أسواق للحملة؛ cache الحملات غير مقيَّد بسوق | 2.5 |
| GCC-07/GCC-09 (ثيم لكل دولة) | topic تحديث الثيم data-only وآمن للتكرار | topic واحد لكل الأسواق: نشر ثيم دولة يوقظ كل التطبيقات (غير ضار وظيفياً لأن العميل يسأل `/theme/version`) | `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27` |
| القيد: caches/queues/routes/notifications/SEO مقيَّدة بالسوق («verify rather than assume») | — | **غير محقَّق** في هذا المجال: مفاتيح cache عامة (`system_maintenance_mode`، `seo_active_redirects`، `commerce_campaign_overrides_<page>`، قائمة الصفحات)، طابور واحد بلا تسمية، topics عامة، sitemap واحد | 2.2، 2.5، 2.7، 2.9 |
| GCC-16 (شحن محلي وعابر للحدود) | DeliverySyria معزول بإعداد ومعطّل افتراضياً | ربطه باسم طريقة الشحن داخل `OrderManager` نمط لا يُعمَّم؛ يجب ألا يُتخذ قالباً لمزوّدي الخليج | 2.12 |

لا يوجد في أدلة هذا المجال ما يثبت تعارضاً يستدعي إعادة فتح قرار معتمد. توجد نقطة واحدة تحتاج قراراً تفصيلياً من المالك (لا إعادة فتح): هل ينتقل المتجر السعودي الحالي إلى `/sa` أم يبقى الجذر معرِّفاً له (انظر القسم 7).

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أقل إضافة مقترحة |
|---|---|---|---|
| `PushNotificationTrait` (بناء حمولة FCM v1، cache رمز الوصول) | Preserve + Extend | يعمل لكل الأطراف والتطبيقات تعتمد على شكل الحمولة | تمرير «سياق اتصال» اختياري يحدد بيانات الاعتماد واسم الـ topic؛ الافتراضي = الحالي |
| أسماء الـ topics الحالية | Preserve | مثبّتة في إصدارات التطبيق المنشورة | إضافة topics بلاحقة السوق بجانبها؛ عدم إعادة تسمية القائم |
| `notification_messages` / `email_templates` | Extend | بنية (نوع، مفتاح) + ترجمات سليمة | طبقة override اختيارية لكل متجر تسقط إلى العام |
| `MailConfigServiceProvider` | Refactor (محدود) | يستبدل الإعداد كله بمرسل واحد عند الإقلاع | تعريف mailers مسمّاة لكل متجر/كيان واختيارها عند الإرسال؛ إبقاء الحالي افتراضياً |
| `SMSModule` | Extend | سلسلة مزوّدين عالمية | اختيار المزوّد بحسب متجر/دولة الرقم قبل السلسلة |
| `ZenderOtpService` / `ZenderSetting` | Preserve + Extend | مسار OTP الفعلي للعملاء | صف لكل متجر أو مفتاح متجر؛ معالجة `SEC-E06-03` |
| `ChatwootService` / `notification_template_mappings` | Extend | الجدول يحمل لغة وشرط حدث | مفتاح متجر/حساب؛ بوابة موافقة قبل التسويق |
| `NotificationAutomationService` | Preserve (خامل) | غير موصول | لا يوصَل قبل وجود سياق متجر وموافقة |
| `BusinessPage` + `PagesController` | Extend | يخدم الويب والتطبيقين | جدول إصدارات مرتبط بمتجر ولغة وحالة (draft/approved/published) مع إبقاء الصف الحالي إصداراً سعودياً منشوراً |
| وضع الصيانة الحالي (`maintenance_mode`) | Preserve | صيانة المنصة كلها حاجة قائمة | إبقاؤه «صيانة المنصة»؛ قراءة الحالة من مصدر دائم لا من cache فقط |
| إيقاف المتجر (GCC-12) | New | لا يوجد | حالة لكل storefront (active / browse_only / maintenance / redirect) تُفحص في middleware الواجهة وفي نقاط إنشاء السلة/الطلب بالـ API، مع قائمة استثناء صريحة للدفع والـ webhooks والطلبات السابقة |
| إغلاق متجر البائع (`temporary_close`) | Preserve | نظير وظيفي لـ browse-only على مستوى البائع | يُستعمل مرجعاً لمواضع الفحص، لا يُعاد توظيفه كدولة |
| تعريف مسارات الواجهة | Refactor (حسّاس) | 3735 استدعاء `route(` يعتمد على الأسماء | مجموعة بادئة `{store}` مع قيمة افتراضية عبر مولّد الروابط، وإبقاء المسارات الجذرية القديمة كتحويلات |
| `Localization` middleware | Refactor | يقرأ مفتاحاً لا يكتبه أحد | توحيد مفتاح الجلسة؛ فصل «لغة» عن «دولة» |
| رموز اللغات `sa`/`ae` | Refactor (بحذر) | تتصادم دلالياً مع بادئات الدول | طبقة تطبيع (language tag مقابل store code) دون تغيير الرموز المخزَّنة في الترجمات |
| `ApplySeoRedirects` + `redirects` | Preserve + Extend | آلية آمنة fail-open | استخدامها لتحويل المسارات القديمة؛ توثيق مخطط الجدول |
| `SeoMetaResolver` وطبقاته | Extend | طبقات واضحة | بُعد متجر في الترجمة/القالب |
| `SiteMapController` | Refactor | ملف واحد يدوي بالزحف | sitemap لكل متجر + فهرس، وتوليد مجدول |
| `ProductSeoSupplementComposer` (canonical/hreflang) | Refactor | hreflang الحالي بلا مقابل | بدائل حقيقية على مسارات المتاجر/اللغات |
| `config/deeplinks.php` + `DeepLinkResolver` + `AssociationFileWriter` | Preserve + Extend | مصدر واحد للأنماط مع اختبار | إضافة أنماط مسبوقة ببادئة المتجر وإرجاع رمز المتجر في الاستجابة؛ إبقاء الأنماط الجذرية |
| ملفا `.well-known` المتتبَّعان | Refactor | قيم placeholder | حذفهما من التتبّع أو توليدهما في النشر |
| روابط `/go/{code}` وQR | Preserve | مطبوعة ومتداولة | يبقى المسار الجذري دائماً |
| التوقيع `cart.recover` | Preserve | صلاحية 30 يوماً | إبقاء المسار القديم طوال نافذة الصلاحية |
| DeliverySyria | Preserve (معزول) | تكامل اختياري معطّل افتراضياً | لا تغيير؛ إزالة الكود القديم الخامل لاحقاً بموافقة |
| Analytics/Telemetry/Monitoring | Extend | مسجِّلات قائمة | عمود/بُعد متجر في الجلسة والحدث |
| `subscriptions`، شريط الكوكيز | Extend | بدائية | موافقات بنطاق (قناة × متجر) مع طابع زمني ومصدر |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E06-F01 | الصفحات القانونية نموذج واحد عالمي بلا دولة ولا إصدارات ولا اعتماد ولا لغات، ويخدم العملاء والبائعين معاً | P0 | VERIFIED | `lavkoo:database/migrations/2025_03_08_201607_create_business_pages_table.php:14-23`؛ `lavkoo:app/Http/Controllers/Admin/Settings/PagesController.php:102-135`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:310-320` | GCC-11 غير قابل للتحقيق؛ البيع في دولة ثانية بسياسات سعودية أو بتعديل مباشر غير معتمد = تعرّض قانوني | إصدارات لكل (متجر، نوع، لغة) بحالات draft/approved/published، ونسخ القالب ينشئ مسودة، وبوابة جاهزية تمنع تفعيل البيع؛ ترحيل الصفوف الحالية كإصدار سعودي منشور |
| E06-F02 | لا يوجد إيقاف لكل متجر؛ الصيانة عالمية بمحور «النظام»، ولا وضعا browse-only وredirect | P1 | VERIFIED | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:217-221`؛ `lavkoo:app/Traits/MaintenanceModeTrait.php:10-32` | GCC-12 غير منفَّذ؛ إيقاف دولة اليوم = إيقاف الجميع | حالة لكل storefront مستقلة عن صيانة المنصة (انظر المصفوفة) |
| E06-F03 | إنفاذ الصيانة يعتمد على مفتاح cache وحيد لا يُعاد بناؤه من قاعدة البيانات | P1 | VERIFIED | `lavkoo:app/Traits/MaintenanceModeTrait.php:14`؛ `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:255-268`؛ `lavkoo:app/Services/AddonService.php:130` | تفريغ الـ cache ينهي الصيانة بصمت؛ نموذج غير صالح لحالة إيقاف متجر ذات أثر قانوني/مالي | مصدر الحقيقة = قاعدة البيانات مع cache قابل لإعادة البناء؛ اختبار انحدار |
| E06-F04 | الصيانة الحالية على الويب تحجب مسارات الطلبات السابقة (طلباتي، تتبع الطلب، الفاتورة، طلب الاسترجاع، تذاكر الدعم) بينما تبقي السلة والدفع | P1 | VERIFIED | `lavkoo:routes/web/routes.php:162-357`؛ `lavkoo:routes/web/routes.php:359-388` | عكس متطلب GCC-12 («الطلبات السابقة والاسترجاع والدعم تستمر»)؛ لا يصلح أساساً لإيقاف المتجر | قائمة استثناء صريحة لالتزامات ما بعد البيع، وحجب إنشاء السلة/الطلب بدلاً من حجب القراءة |
| E06-F05 | لا توجد حالة إيقاف على مستوى المتجر في الخادم؛ فرض الصيانة في التطبيقات يتم من جهة العميل، والحالة تُقرأ من إعداد النظام العام | P1 | VERIFIED | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:106-111`؛ `lavkooapp:lib/features/splash/controllers/splash_controller.dart:94-95` | GCC-12 يتطلب حالة متجر مفروضة على الخادم؛ الإنفاذ الحالي للتطبيقات من طرف العميل وعلى مستوى النظام كله | حالة storefront على الخادم مع فحص مركزي في طبقة الخدمة |
| E06-F06 | كل هويات الاتصال مفردة: مشروع FCM، مرسل البريد، مزوّد SMS، Zender، Chatwoot | P1 | VERIFIED | `lavkoo:app/Traits/PushNotificationTrait.php:487`؛ `lavkoo:app/Providers/MailConfigServiceProvider.php:26-50`؛ `lavkoo:app/Utils/SMSModule.php:18-51`؛ `lavkoo:app/Services/ZenderOtpService.php:13`؛ `lavkoo:app/Services/ChatwootService.php:17-49` | متطلب «هويات/حسابات/قوالب لكل متجر» غير منفَّذ؛ رسائل دولة تصدر باسم وعنوان ورقم دولة أخرى | كيان «سياق اتصال» لكل متجر يحل الحساب والقالب مع سقوط إلى العام |
| E06-F07 | قوالب push/بريد بلا بُعد متجر، واسم الشركة و`url('/')` محروقان في نصوص مخزَّنة | P1 | VERIFIED | `lavkoo:app/Traits/PushNotificationTrait.php:307-309`؛ `lavkoo:app/Traits/EmailTemplateTrait.php:36`؛ `lavkoo:app/Services/EmailTemplateService.php:136-144` | GCC-10 (override للعلامة) والكيان القانوني في الرسائل المعاملاتية غير ممكنين؛ روابط مخزَّنة تتجاهل بادئة المتجر | طبقة override + متغيرات قالب (`{storeName}`,`{storeUrl}`) بدل النص المحروق |
| E06-F08 | البث التسويقي غير معزول: topic واحد `sixvalley`، جدول `notifications` عام، `subscriptions` بلا متجر | P1 | VERIFIED | `lavkoo:app/Traits/PushNotificationTrait.php:449`؛ `lavkoo:app/Http/Controllers/Admin/Notification/NotificationController.php:59-94`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/NotificationController.php:13-26` | حملة دولة تصل عملاء كل الدول في الـ push وفي قائمة الإشعارات داخل التطبيق | topics بلاحقة السوق، وعمود متجر في `notifications` و`subscriptions`، مع إبقاء القديم للسعودية |
| E06-F09 | لا يوجد سجل موافقة تسويقية لأي قناة؛ تسويق السلة عبر WhatsApp وتذكير السلة بالبريد بلا opt-in وبلا رابط إلغاء | P1 | VERIFIED | `lavkoo:app/Http/Controllers/Admin/CartMarketingController.php:14-63`؛ `lavkoo:app/Console/Commands/SendAbandonedCartReminders.php:90-113`؛ `lavkoo:app/Models/Subscription.php:29-31` | القيد المعتمد «consents have correct scopes» بلا أساس؛ تعرّض تنظيمي عند التوسع (التقييم القانوني خارج نطاق التدقيق) | سجل موافقات (عميل × قناة × متجر × طابع زمني × مصدر) وبوابة قبل أي إرسال تسويقي |
| E06-F10 | الواجهة بلا بادئة دولة؛ اللغة والعملة جلسة؛ deep links ومسارات التطبيقات والروابط الموقَّعة والحرفية مثبّتة على الجذر | P1 | VERIFIED | `lavkoo:app/Providers/RouteServiceProvider.php:215-220`؛ `lavkoo:app/Services/DeepLink/DeepLinkResolver.php:158-186`؛ `lavkooapp:lib/data/services/deep_link_resolver.dart:86-118`؛ `lavkoo:app/Console/Commands/SendAbandonedCartReminders.php:132-135` | إدخال `/sa…/om` يمس: فهرسة Google الحالية، روابط البريد الصادرة، الروابط الموقَّعة السارية، app links في الإصدارات المنشورة، callbacks حرفية | خطة ترحيل: مسارات جذرية تبقى كتحويلات، أنماط deep link مزدوجة، وإصدار تطبيق يفهم البادئة قبل تفعيلها |
| E06-F11 | رموز اللغات على هيئة رموز دول (`sa`,`ae`) وإصدارات تطبيق أرسلت رمز الدولة في ترويسة `lang` | P1 | VERIFIED | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:21-31`؛ `lavkoo:resources/lang/sa/messages.php` | التباس لغة/دولة عند إدخال سياق المتجر (ترويسة، بادئة، hreflang)؛ خطر تفسير `sa` كمتجر أو كلغة بحسب الموضع | فصل صريح: ترويسة/حقل مستقل للمتجر، وتطبيع رموز اللغة إلى BCP-47 عند الحدود |
| E06-F12 | hreflang الحالي يشير إلى `?language=` الذي لا يقرؤه أي كود؛ لا نسخ لغوية قابلة للفهرسة؛ canonical لصفحات غير المنتج يدوي | P1 | VERIFIED | `lavkoo:app/View/Composers/ProductSeoSupplementComposer.php:136-159`؛ `lavkoo:app/Http/Controllers/Admin/Settings/SiteMapController.php:125-138`؛ `lavkoo:app/Http/Middleware/Localization.php:18-24` | مع ستة متاجر بمحتوى متقارب: خطر محتوى مكرر وتنافس داخلي دون إشارات صحيحة | canonical ذاتي لكل متجر + hreflang متبادل بين المتاجر/اللغات على مسارات حقيقية |
| E06-F13 | sitemap واحد يدوي بالزحف، robots ثابتان متعارضان، cache redirects عام | P2 | VERIFIED | `lavkoo:app/Http/Controllers/Admin/Settings/SiteMapController.php:111-122`؛ `lavkoo:robots.txt:1-3`؛ `lavkoo:public/robots.txt:1-3` | لا يتسع لستة متاجر؛ ملف `public/robots.txt` يشير إلى عنوان تطوير | sitemap لكل متجر + فهرس مجدول؛ robots ديناميكي |
| E06-F14 | ملفا `.well-known` المتتبَّعان placeholder، والمنصتان تدّعيان نطاقين مختلفين | P1 | VERIFIED (الكود) / UNKNOWN (الإنتاج) | `lavkoo:public/.well-known/assetlinks.json:8`؛ `lavkoo:public/.well-known/apple-app-site-association:6`؛ `lavkooapp:android/app/src/main/AndroidManifest.xml:131`؛ `lavkooapp:ios/Runner/Runner.entitlements:19` | روابط التطبيق قد لا تعمل على إحدى المنصتين اليوم؛ أي نشر يعيد الملفات المتتبَّعة يعطّلها؛ أساس هش لإضافة بادئات | حسم النطاق الإنتاجي، توليد الملفين في النشر، ومطابقة قائمة أندرويد مع `config/deeplinks.php` |
| E06-F15 | جداول تشغيلية في هذا المجال بلا migration متتبَّعة (16 جدولاً على الأقل) | P1 | VERIFIED | 2.11؛ مثلاً `lavkoo:app/Models/Redirect.php:24-35`، `lavkoo:app/Models/ZenderSetting.php:9-22`، `lavkoo:app/Models/CustomerSegment.php:17` | لا يمكن تصميم إضافة أعمدة المتجر ولا إعادة بناء بيئة اختبار مطابقة دون مخطط الإنتاج | استخراج مخطط الإنتاج (قراءة فقط) وتوثيقه كـ baseline قبل أي migration |
| E06-F16 | إشعار حالة الطلب يُرسل متزامناً داخل الطلب HTTP؛ طابور واحد بلا تسمية | P2 | VERIFIED | `lavkoo:app/Listeners/OrderStatusListener.php:8-10`؛ `lavkoo:config/queue.php:16` | بطء/فشل FCM يؤخر عمليات الإدارة؛ لا عزل حِمل بين الأسواق | إدراج الـ listener في الطابور؛ تسمية طوابير بحسب النوع (لا يلزم بحسب السوق في البداية) |
| E06-F17 | بريد الطابور يُرجَّح أنه بلغة النظام لا لغة المستلم | P2 | INFERRED | `lavkoo:app/Models/EmailTemplate.php:84-87`؛ `lavkoo:app/Utils/language.php:104-130` | رسائل بلغة خاطئة؛ يتفاقم مع تعدد المتاجر | تمرير locale المستلم/المتجر صراحةً إلى الإرسال |
| E06-F18 | مسار FCM قديم (`fcm/send`) ما زال مستخدماً لرمز تحقق التسليم | P2 | VERIFIED | `lavkoo:app/Utils/Helpers.php:476-481`؛ `lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:811` | إشعار قد لا يصل؛ مسار ثانٍ ببيانات اعتماد مختلفة الشكل | توحيده على `PushNotificationTrait` |
| E06-F19 | DeliverySyria: حلقة فحص وسطر سجل لكل طلب في `OrderManager`، ربط باسم طريقة الشحن، وكود قديم خامل، وعنوان webhook معروض خاطئ | P2 | VERIFIED | `lavkoo:app/Utils/OrderManager.php:1781-1834`؛ `lavkoo:app/Services/DeliverySyriaService.php:11-13`؛ `lavkoo:resources/views/admin-views/integrations/delivery-syria-settings.blade.php:69` | ضجيج سجلات وكلفة استعلام لكل طلب سعودي؛ نمط ربط لا يصلح لمزوّدي الخليج | إبقاؤه معزولاً ومعطّلاً؛ عدم محاكاته؛ تنظيف لاحق بموافقة |
| E06-F20 | التحليلات والحملات والشرائح ومفاتيح cache المرتبطة بها بلا بُعد متجر | P1 | VERIFIED | `lavkoo:app/Services/Analytics/VisitorContext.php:300-321`؛ `lavkoo:app/Services/Commerce/CampaignResolver.php:44`؛ `lavkoo:app/Models/ExperienceCampaign.php:33` | تقارير مختلطة بين الأسواق؛ overlay حملة يظهر في كل المتاجر | بُعد متجر في الجلسة/الحدث ومفتاح الـ cache وصف الحملة |
| E06-F21 | أتمتة WhatsApp لحالة الطلب مكتوبة وغير موصولة | INFO | VERIFIED | `lavkoo:app/Services/NotificationAutomationService.php:275-345` | لا أثر حالياً؛ وصلها لاحقاً دون سياق متجر وموافقة يخلق F06/F09 | تأجيل الوصل إلى ما بعد سياق الاتصال |
| E06-F22 | topic `lavkoo_seller` في تطبيق البائع بلا مُرسِل في الـ backend | INFO | VERIFIED | `lavkooappseller:lib/utill/app_constants.dart:400`؛ `lavkoo:app/Http/Controllers/Admin/Notification/NotificationController.php:76-87` | البث للبائعين يتم بحلقة توكنات فردية (كل البائعين المعتمدين، بلا تمييز دولة) | عند تقييد البائعين بمتاجر: تصفية الحلقة أو topics بلاحقة |
| E06-F23 | مسارات الدفع والـ callbacks والـ webhooks والـ API خارج مجموعة الصيانة أصلاً | INFO | VERIFIED | `lavkoo:routes/web/routes.php:458-470`؛ `lavkoo:routes/web/routes.php:574-612`؛ `lavkoo:app/Providers/RouteServiceProvider.php:127-155` | أساس جيد لـ GCC-12 (المدفوعات والـ webhooks لا تنكسر) يجب الحفاظ عليه | توثيقه كقاعدة ثابتة واختبار انحدار |
| SEC-E06-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |
| SEC-E06-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |
| SEC-E06-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |
| SEC-E06-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |
| SEC-E06-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |
| SEC-E06-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |

## 7. أسئلة مفتوحة وعناصر UNKNOWN

1. **قيم الإنتاج للإعدادات** (`push_notification_key`، `fcm_credentials`، `mail_config`، مزوّد SMS النشط، صف `zender_settings`، `integration_settings` لـ Chatwoot، `delivery_syria.status`، `app_deep_link`، `maintenance_*`، `cookie_setting`، تفعيل تذكير السلة): UNKNOWN — يلزم قراءة قاعدة بيانات الإنتاج (قراءة فقط) مع حجب الأسرار.
2. **مخطط الجداول غير المتتبَّعة** (2.11): UNKNOWN — يلزم `SHOW CREATE TABLE` من الإنتاج.
3. **النطاق الإنتاجي الفعلي** (`lavkoo.com` أم `pharmacyksa.com`) ومحتوى `/.well-known/*` و`robots.txt` و`sitemap.xml` المخدَّمة فعلاً: UNKNOWN — يلزم `APP_URL` من `.env` وقراءة الملفات على الخادم.
4. **driver الـ cache والطابور في الإنتاج** (`CACHE_DRIVER`، `QUEUE_CONNECTION`) وعدد العمال: UNKNOWN — `.env` وإعداد supervisor.
5. **وجود `config/analytics.php` / `monitoring.php` / `telemetry.php` على الخادم**: UNKNOWN.
6. **تطبيق المندوب**: غير موجود ضمن المستودعات الثلاثة؛ اشتراكه في topic الصيانة وحالته UNKNOWN.
7. **حالة DeliverySyria تاريخياً**: هل توجد طلبات/طرود فعلية في `delivery_syria_parcels`؟ UNKNOWN — بيانات الإنتاج. والمستودع القديم `pharmacyksa98` غير متاح: `UNKNOWN (missing access: pharmacyksa98)`.
8. **لغة بريد الطابور** (E06-F17): يحسمها اختبار تشغيلي غير مسموح في هذه المرحلة.
9. **قرار تفصيلي مطلوب من المالك (ليس إعادة فتح):** هل تنتقل عناوين السعودية الحالية المفهرسة إلى `/sa/...` مع تحويل 301 من الجذر، أم يُخدَّم المتجر الافتراضي (GCC-19) على الجذر أيضاً؟ الخياران ممكنان تقنياً؛ الأول يمس كل الروابط المنشورة والإصدارات الحالية من التطبيق (E06-F10)، والثاني يخلق ازدواج محتوى يحتاج canonical صارماً.
10. **قرار تفصيلي:** هل سياسات البائعين (شروط البائع) وثيقة مستقلة عن سياسات العملاء؟ اليوم المحتوى واحد للتطبيقين.

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| كل الـ push يمر بإعداد واحد `push_notification_key` | `lavkoo:app/Traits/PushNotificationTrait.php:484-499` | VERIFIED |
| الـ topic الافتراضي `sixvalley` | `lavkoo:app/Traits/PushNotificationTrait.php:449` | VERIFIED |
| topics الصيانة الثلاثة | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:270-280` | VERIFIED |
| topic الثيم `theme_updates_user_app` | `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27` | VERIFIED |
| صيغة topic إعادة التوفّر | `lavkoo:app/Utils/product.php:193-196` | VERIFIED |
| topics الويب | `lavkoo:app/Utils/settings.php:264-270` | VERIFIED |
| اشتراكات تطبيق العميل | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:145-147` | VERIFIED |
| اشتراكات تطبيق البائع | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:89-90` | VERIFIED |
| قالب push بمفتاح (key,user_type) | `lavkoo:app/Traits/PushNotificationTrait.php:307-309` | VERIFIED |
| توكن عميل واحد | `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:654-657` | VERIFIED |
| OrderStatusListener غير مُدرج في الطابور | `lavkoo:app/Listeners/OrderStatusListener.php:8-10` | VERIFIED |
| مرسل بريد واحد | `lavkoo:app/Providers/MailConfigServiceProvider.php:26-50` | VERIFIED |
| جلب قالب البريد | `lavkoo:app/Traits/EmailTemplateTrait.php:34-79` | VERIFIED |
| 17 مفتاح قالب بريد | `lavkoo:app/Enums/EmailTemplateKey.php:7-23` | VERIFIED |
| سلسلة مزوّدي SMS | `lavkoo:app/Utils/SMSModule.php:18-51` | VERIFIED |
| Zender صف واحد | `lavkoo:app/Services/ZenderOtpService.php:13` | VERIFIED |
| Chatwoot حساب واحد | `lavkoo:app/Services/ChatwootService.php:17-49` | VERIFIED |
| أتمتة WhatsApp بلا مستدعين | `lavkoo:app/Services/NotificationAutomationService.php:18-98` | VERIFIED |
| تسويق السلة بلا فحص موافقة | `lavkoo:app/Http/Controllers/Admin/CartMarketingController.php:14-63` | VERIFIED |
| تذكير السلة معطّل افتراضياً | `lavkoo:app/Services/Retention/AbandonedCartService.php:60-63` | VERIFIED |
| موافقة التحليلات فقط | `lavkoo:app/Services/Analytics/Support/PrivacyGate.php:47-62` | VERIFIED |
| مخطط `business_pages` | `lavkoo:database/migrations/2025_03_08_201607_create_business_pages_table.php:14-23` | VERIFIED |
| تعديل الصفحة كتابة مباشرة | `lavkoo:app/Http/Controllers/Admin/Settings/PagesController.php:102-127` | VERIFIED |
| API الصفحات للتطبيقين | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:310-320` | VERIFIED |
| التطبيقان يستهلكان المسار نفسه | `lavkooapp:lib/utill/app_constants.dart:216`؛ `lavkooappseller:lib/utill/app_constants.dart:200` | VERIFIED |
| أنظمة الصيانة الخمسة | `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:217-221` | VERIFIED |
| الإنفاذ من cache فقط | `lavkoo:app/Traits/MaintenanceModeTrait.php:10-32` | VERIFIED |
| استثناء جلسة admin من الصيانة | `lavkoo:app/Traits/MaintenanceModeTrait.php:13` | VERIFIED |
| مجموعات الصيانة في الويب | `lavkoo:routes/web/routes.php:150`؛ `lavkoo:routes/web/routes.php:162` | VERIFIED |
| الدفع خارج مجموعة الصيانة | `lavkoo:routes/web/routes.php:458-470` | VERIFIED |
| الـ API بلا middleware صيانة | `lavkoo:routes/rest_api/v1/api.php:54` | VERIFIED |
| الصيانة تُعاد للتطبيق في config | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:106-111` | VERIFIED |
| مسارات الواجهة بلا prefix | `lavkoo:app/Providers/RouteServiceProvider.php:215-220` | VERIFIED |
| اللغة في الجلسة بمفتاح `local` | `lavkoo:app/Http/Controllers/SharedController.php:30-33` | VERIFIED |
| `Localization` يقرأ `locale` | `lavkoo:app/Http/Middleware/Localization.php:18-24` | VERIFIED |
| طيّ `sa→ar` في الـ API | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:27` | VERIFIED |
| العملة في الجلسة | `lavkoo:app/Http/Controllers/Web/CurrencyController.php:18-28` | VERIFIED |
| canonical المنتج | `lavkoo:app/View/Composers/ProductSeoSupplementComposer.php:61-66` | VERIFIED |
| hreflang بـ `?language=` | `lavkoo:app/View/Composers/ProductSeoSupplementComposer.php:149` | VERIFIED |
| توليد sitemap | `lavkoo:app/Http/Controllers/Admin/Settings/SiteMapController.php:111-140` | VERIFIED |
| استثناءات redirects | `lavkoo:app/Http/Middleware/ApplySeoRedirects.php:32` | VERIFIED |
| تفرّد slug عالمي | `lavkoo:app/Traits/GeneratesUniqueSlug.php:40-47` | VERIFIED |
| أنماط deep link المنشورة | `lavkoo:config/deeplinks.php:39-45` | VERIFIED |
| مطابقة المسار حرفياً | `lavkoo:app/Services/DeepLink/DeepLinkResolver.php:158-186` | VERIFIED |
| رفض host غير `app.url` | `lavkoo:app/Services/DeepLink/DeepLinkResolver.php:215-217` | VERIFIED |
| `assetlinks.json` placeholder | `lavkoo:public/.well-known/assetlinks.json:8` | VERIFIED |
| AASA بـ appID "." | `lavkoo:public/.well-known/apple-app-site-association:6` | VERIFIED |
| ادعاء أندرويد | `lavkooapp:android/app/src/main/AndroidManifest.xml:122-142` | VERIFIED |
| ادعاء iOS | `lavkooapp:ios/Runner/Runner.entitlements:17-20` | VERIFIED |
| المحلّل المحلي في التطبيق | `lavkooapp:lib/data/services/deep_link_resolver.dart:86-118` | VERIFIED |
| DeliverySyria معطّل افتراضياً | `lavkoo:app/Services/DeliverySyria/DeliverySyriaConfigService.php:49-52` | VERIFIED |
| ربط DeliverySyria في OrderManager | `lavkoo:app/Utils/OrderManager.php:1827-1833` | VERIFIED |
| webhook DeliverySyria | `lavkoo:routes/rest_api/delivery_syria.php:16-18` | VERIFIED |
| حقول telemetry | `lavkoo:app/Services/Telemetry/TelemetryRecorder.php:78-91` | VERIFIED |
| حقول جلسة التحليلات | `lavkoo:app/Services/Analytics/VisitorContext.php:300-321` | VERIFIED |
| رابط السلة الموقَّع | `lavkoo:app/Console/Commands/SendAbandonedCartReminders.php:132-135` | VERIFIED |
| رابط إعادة التعيين الحرفي | `lavkoo:app/Http/Controllers/RestAPI/v1/auth/ForgotPasswordController.php:74` | VERIFIED |
| الرابط القصير من `app.url` | `lavkoo:app/Services/Analytics/CampaignService.php:298-301` | VERIFIED |
| لغة بريد الطابور | `lavkoo:app/Models/EmailTemplate.php:84-87` | INFERRED |
| قيم إعدادات الإنتاج | — (قاعدة بيانات الإنتاج) | UNKNOWN |

## 9. سجل التحقق المستقل

> تحقق مستقل بعد إعداد الملف: قراءة ثابتة للكود المستشهد به، وبحث بأنماط بديلة لكل ادعاء غياب، وفحص آلي بـ `check_citations` و`scan_sensitive`. لم يُشغَّل أي كود ولم يُجرَ أي اتصال شبكي.

### أ. الأعداد

| البند | العدد |
|---|---|
| ادعاءات تم فحصها | 71 |
| مؤكَّدة | 64 |
| مُصحَّحة | 5 |
| مُفنَّدة | 2 |
| غير قابلة للتحقق | 0 |

### ب. التصحيحات

1. §2.4: عدد مواضع استدعاء `ZenderOtpService` في `app/` هو 7 لا 8 (وصُحِّح الملحق المقيّد بالمثل).
2. §2.5 و§4 و§6 (E06-F09): صيغة «لا موافقة تسويقية إطلاقاً» صُحِّحت إلى «لا يوجد سجل موافقة تسويقية»؛ نموذج النشرة في تذييل الويب يحفظ البريد فقط، وهذا لا يعني غياب الاشتراك الطوعي بل غياب أثر موثَّق للموافقة.
3. §2.10: قائمة الـ manifest على أندرويد أوسع من `android_paths` في `config/deeplinks.php`، والمسار `/go/` موجود في `enabled_routes` وغائب عن `android_paths`.
4. §2.11: `integration_settings` معرَّف في migration (`2026_11_05_100000_create_provider_fulfillment_tables.php`) فأُزيل من قائمة الجداول بلا تعريف؛ يبقى العدد 16 بعد التصحيح.
5. §2.7 و§2.8: توضيح فرع `api/*` في الـ trait (يستخدمه `ConfigController` لتقرير الحالة) وسبب غياب `Route::fallback` المعلَّق في `routes/update.php`.

ملاحظة مضافة (غير تصحيح): مسار `/track-order/*` في ملف AASA المتتبَّع يخالف استبعاد `/track-order` في إعداد الروابط (§2.10).

### ج. الادعاءات المُفنَّدة

1. ادعاء أن `integration_settings` بلا تعريف مخطط متتبَّع (§2.11 وE06-F15): مُفنَّد بوجود migration.
2. ادعاء في الملحق المقيّد بأن ملف إعداد الويب المتتبَّع يُستخدم لإرسال OTP: مُفنَّد كصياغة؛ الخدمة تقرأ المفتاح من إعداد `fcm_credentials`، وبقيت قرينة التطابق INFERRED. صُحِّح في الملحق.

### د. الانتقال إلى الملحق المقيّد

- نُقل في هذا التحقق بند واحد: SEC-E06-06 (فحص الإيقاف بين قنوات الطلب)، وأُبدل بصف محايد مع مؤشر في §6.
- إجمالي بنود الملحق المقيّد: SEC-E06-01 إلى SEC-E06-06.

### هـ. مخاطر متبقية (حسب القسم)

- §2.2 (قنوات الإشعار): ترتيب الطابور ولغة البريد المرسل من الطابور تبقيان INFERRED وتحتاجان اختباراً تشغيلياً.
- §2.7 (الصيانة): driver الـ cache في الإنتاج UNKNOWN، وأثره على الإنفاذ يتوقف عليه.
- §2.10 (الروابط العميقة): النطاق الإنتاجي ومحتوى `.well-known` المخدَّم UNKNOWN.
- §6 (E06-F09): التقييم القانوني للموافقات خارج نطاق التدقيق.

### و. فجوات التغطية

- لم يُعَد عدّ استدعاءات `route(` في §2.14؛ الأرقام كما وردت في الملف.
- قيم الإعدادات الفعلية في قاعدة بيانات الإنتاج غير متاحة (UNKNOWN كما في §7).
- التفاصيل الداخلية لـ `Analytics` و`Monitoring` بقيت على مستوى الحقول كما في §1.
