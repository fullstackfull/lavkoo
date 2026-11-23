# Lavkoo GCC — Discovery-01 — التقرير 08: مصفوفة الأثر على الويب وتطبيق العميل وتطبيق البائع

> **المرحلة:** AUDIT (قراءة فقط) — **الجلسة:** `claude/exciting-shannon-4mh2ok` — **التاريخ:** 2026-11-12 (+03:00)
> **SHA الأساس:** `lavkoo@7f42777` · `lavkooapp@eedbbb8` · `lavkooappseller@8ea0092` · مرجع قديم `ksaapp98@79f50be`
> **يعتمد على:** [E02](./evidence/E02-store-context-settings-currency-cache.md) (ثانوي) · [E06](./evidence/E06-communications-compliance-activation.md) · [E07](./evidence/E07-theme-system-backend.md) · [E09](./evidence/E09-cart-checkout-order-pipeline.md) (ثانوي) · [E10](./evidence/E10-payments-gateways.md) (ثانوي) · [E14](./evidence/E14-seller-vendor-backend-api-v3.md) · [E16](./evidence/E16-customer-app-architecture-context.md) · [E17](./evidence/E17-customer-app-dynamic-theme.md) · [E18](./evidence/E18-customer-app-commerce-flows.md) · [E19](./evidence/E19-seller-app-architecture-auth-gate.md) · [E20](./evidence/E20-seller-app-operations-finance.md) · [E22](./evidence/E22-api-contract-customer.md) · [E23](./evidence/E23-api-contract-seller.md)
> **الحالة:** نهائي لهذه الجلسة — لا يتضمن أي تنفيذ.

## 1. النطاق والمنهج

يقيس هذا التقرير حجم الأثر الذي تحدثه كل قدرة من قدرات GCC المعتمدة على كل سطح من الأسطح الستة: الويب، ولوحة الإدارة، ولوحة البائع، وتطبيق العميل، وتطبيق البائع، وعقد الـ API. وهو قياس لحجم التغيير المطلوب على كل سطح، لا قياس لشدة العيوب القائمة، والعيوب مسجّلة في [التقرير 05](./05-gap-risk-register.md).

**مقياس الأثر:**

| المستوى | المعنى |
|---|---|
| لا أثر (none) | القدرة لا تمس هذا السطح، أو لا يستهلكها أصلاً. |
| منخفض (low) | تغيير إضافي أو عرض فقط، دون تغيير سلوك قائم. |
| متوسط (medium) | تغيير وظيفي محلي يحتاج اختباراً انحدارياً، ولا يكسر النسخ المنشورة إذا بقي الافتراض السعودي. |
| عالٍ (high) | تغيير في العقد أو في المال أو في الإنفاذ، أو تغيير يمس نسخاً منشورة أو بيانات مالية أو سلوكاً قانونياً. |

**قواعد الاستشهاد:** كل خلية تحمل استشهاداً بصيغة `repo:path:line` من الملاحق المتحقَّق منها، مع إحالة قصيرة إلى الملحق (مثل `E06 §2.8`). الحالة `VERIFIED` تُنقل كما هي من الملاحق، ولا تُرقّى. لم يُنفَّذ أي كود، ولم يُجرَ أي اتصال شبكي، ولم يُعدَّل أي مستودع.

**حدود الاستشهاد:** فُحص كل استشهاد بالمدقق الآلي للملفات وأرقام الأسطر، وقُورنت عيّنة من عشرة استشهادات بمحتوى الكود الفعلي. الاستشهادات المحالة إلى أقسام الملاحق (مثل `E22 §6.2`) لا تحمل رقم سطر، وهي مشتقة مما قاله الملحق المتحقَّق منه.

**المسائل المقيّدة:** لا يذكر هذا التقرير إلا معرّفات `SEC-…` مع وصف فئة محايد، والتفاصيل في الملحق المقيّد غير المنشور.

## 2. مصفوفة الأثر

| القدرة GCC | الويب (الواجهة) | لوحة الإدارة | لوحة البائع | تطبيق العميل | تطبيق البائع | عقد الـ API |
|---|---|---|---|---|---|---|
| **اختيار الدولة/المتجر** (GCC-19، الاختيار اليدوي) | **عالٍ** — لا مُدخل متجر؛ الجذر هو المتجر الوحيد، واللغة والعملة في الجلسة. يلزم مُدخل متجر وتوجيه. `lavkoo:app/Providers/RouteServiceProvider.php:215-220` (E06 §2.8) | **متوسط** — الإعدادات مفردة في مخزن واحد، ويلزم منتقي متجر في الشاشات. `lavkoo:app/Utils/settings.php:14-34` (E02 §4) | **متوسط** — الرمز يحلّ إلى بائع واحد؛ يلزم عرض المتاجر المتاحة لمن يعمل في عدة دول. `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:56-64` (E14 §2.4) | **عالٍ** — لا منتقي ولا مفتاح محفوظ للمتجر؛ عنوان الـ API ثابت وقت الترجمة. `lavkooapp:lib/utill/app_constants.dart:39` (E16 §4.3) | **عالٍ** — الرمز يُحلّ إلى بائع ومتجر واحد دون شاشة اختيار؛ يلزم مصدر قائمة المتاجر وتبديلها. `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56` (E19 F01) | **عالٍ (إضافي)** — لا وسيط متجر في أي مسار؛ الإضافة ترويسة اختيارية غيابها = السعودية. `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:25` (E22 §6.2) |
| **مسارات `/sa … /om`** | **عالٍ** — المسارات العامة جذرية (`/product/{slug}`)، و3735 استدعاء `route(` يتبع الأسماء، لكن الروابط الحرفية والموقّعة والـ canonical/hreflang تتأثر. `lavkoo:routes/web/routes.php:227-229` (E06 §2.8، §2.14) | **منخفض** — لوحات `admin/*` و`vendor/*` تُكشف بشكل الـ URL؛ البادئة على الواجهة لا تمسها إلا إذا غُيّر الكشف. `lavkoo:app/Traits/MaintenanceModeTrait.php:18-24` (E06 §2.14) | **منخفض** — مسار اللوحة على مجموعة الصيانة؛ البادئة لا تمس المسار إلا عند تعديل الكشف بالشكل. `lavkoo:routes/vendor/routes.php:45` (E06 §2.7) | **متوسط** — الـ API لا يتأثر، لكن ملف الـ manifest ومحلّل الروابط المحلي (أول مقطع) و WebView الدفع تحتاج تعديلاً؛ الروابط `/sa/…` تُفتح في المتصفح في الإصدارات المنشورة. `lavkooapp:lib/data/services/deep_link_resolver.dart:86-118` (E16 §4.3) | **منخفض** — روابط البائع مخطط `lavkooseller://` فقط بلا معالج. `lavkooappseller:android/app/src/main/AndroidManifest.xml:88-93` (E19 F11) | **منخفض** — مسارات `/api/v1` و`/api/v2` و`/api/v3` ثابتة في النسخ المنشورة، ولا تُقترح لها بادئة؛ البادئة على الويب فقط. `lavkoo:app/Providers/RouteServiceProvider.php:97-119` (E06 §2.8، E22 §6.2 ب) |
| **العملة والخانات العشرية** (KWD/BHD/OMR بثلاث خانات) | **عالٍ** — العملة في الجلسة (`currency_code`، `currency_exchange_rate`)، والمبالغ تُحوَّل بمحوّل عام بسعر الصرف الجاري. `lavkoo:app/Utils/currency.php:76-104` (E02 §2.2، E06 §2.8) | **عالٍ** — المبالغ مخزّنة بلا عملة وتُفسَّر وقت القراءة؛ تبديل `currency_model` يعيد تفسير كل المبالغ التاريخية. `lavkoo:app/Utils/currency.php:50-67` (E02 F01، P0) | **عالٍ** — الكشوفات تقرّب إلى خانتين، والكتابة تحوّل بالسعر العام. `lavkoo:app/Utils/Convert.php:62` (E23 F04) | **عالٍ** — `convertPrice` يضرب بسعر صرف ويطبع صفر خانات؛ مبلغ KWD بثلاث خانات يُعرض مقرّباً. `lavkooapp:lib/helper/price_converter.dart:65-78` (E16 F01، E18 F01؛ P0) | **عالٍ** — ضرب في وضع `multi_currency` وثوابت `toStringAsFixed(2)`. `lavkooappseller:lib/helper/price_converter.dart:22-24` (E19 F04، E20 F02) | **عالٍ** — المبالغ أرقام مجرّدة في كل الاستجابات؛ الإضافة الآمنة `currency_code` و`decimal_places` بجانب الحقول القائمة. `lavkoo:app/Models/Order.php:95` (E22 F02، §5) |
| **الكتالوج والأسعار المحلية** (GCC-08، GCC-18، لا FX آلي) | **عالٍ** — الكاش العام للكتالوج بلا بُعد متجر، فيرى كل زائر الأسعار نفسها. `lavkoo:app/Utils/product.php:224-226` (E02 F03، P0) | **عالٍ** — الكود فريد على جدول `products` كله؛ نسخ المنتج بين الدول إجراء إداري يحتاج قراراً صريحاً. `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:798` (E14 §4.2، E20 F04) | **عالٍ** — منتج واحد بسعر ومخزون وكود واحد؛ الصفوف لكل متجر تحتاج بُعداً على المنتج والكاش. `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:782-810` (E14 §2.6) | **عالٍ** — كاش بيانات الكتالوج (القرص حتى 400 ثانية) بمفتاح بلا متجر؛ يلزم مفتاح متجر ومسح عند التبديل. `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-140` (E22 F10، E16 §4.3) | **متوسط** — إنشاء المنتج وتعديله بعقد واحد وكود واحد؛ لا كاش محلي للمنتجات. `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:218-251` (E20 A6) | **عالٍ** — `products/*` و`categories/products` و`brands/products` تعيد أسعاراً بلا عملة وبلا متجر؛ إضافة بُعد متجر اختياري بعد اختبار العقد. `lavkoo:app/Utils/Helpers.php:156-165` (E22 §6.4، F02) |
| **السلة والدفع بكيان واحد** (قيد Provisional: كيان قانوني واحد لكل checkout) | **عالٍ** — الدفع يجمع كل المجموعات المحددة ويحصّلها بدفعة واحدة، ولا حارس كيان. `lavkoo:app/Utils/OrderManager.php:887-902` (E09 F05) | **متوسط** — قوائم الطلبات والاسترجاع بلا تصفية كيان؛ الحارس الجديد لا يمس الطلبات السابقة. `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:631` (E09 §4) | **منخفض** — الطلبات مقسّمة لكل بائع (`seller_id` و`seller_is`)، والتقسيم يبقى داخل الكيان. `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:65-75` (E14 §4.1 C4) | **عالٍ** — واجهة السلة تسمح باختيار مجموعات بائعين مختلفين في checkout واحد دون فحص كيان. `lavkooapp:lib/features/cart/screens/cart_screen.dart:666-680` (E18 F08) | **منخفض** — لا ينشئ البائع سلالاً ولا checkout؛ يتأثر بتغيّر شكل الطلب فقط. `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:369-490` (E14 §2.6) | **عالٍ** — طلب الإنشاء لا يثبت المتجر الذي حُسبت عليه السلة؛ `order_ids` مجموعة لكل بائع. `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:251-282` (E22 §4) |
| **بوابات الدفع** (Paymob وMyFatoorah محفوظتان؛ Tabby/Tamara غير موجودتين) | **عالٍ** — مسارات العودة وcallbacks جذرية خارج الصيانة، وبلا سياق متجر؛ Paymob يعتمد على عودة المتصفح. `lavkoo:routes/web/routes.php:574-612` (E10 F19، F06) | **عالٍ** — حساب واحد لكل بوابة لكل النظام؛ يلزم حساب لكل كيان ومتجر. `lavkoo:app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:181` (E10 F01، P0) | **منخفض** — لا تكامل بوابات في لوحة البائع؛ الحساب البنكي للتسوية واحد على سجل البائع. `lavkoo:app/Models/Seller.php:54-57` (E14 §2.5) | **عالٍ** — قائمة البوابات من config عام، ونتيجة الدفع غير MyFatoorah تُشتق من عنوان العودة على host واحد. `lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:123-135` (E18 F09) | **منخفض** — لا يبدأ دفعات العميل؛ POS يدفع من محفظة العميل العامة. `lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:320-323` (E20 F11) | **عالٍ** — `payment_requests` بلا كيان أو متجر ومستلم ثابت؛ `digital-payment` بلا سياق عملة. `lavkoo:app/Traits/Payment.php:22-37` (E10 A2، E22 §4) |
| **المحفظة والولاء** (GCC-13، GCC-15) | **متوسط** — صفحات المحفظة والنقاط تعرض رصيداً واحداً من حساب المستخدم. `lavkoo:app/Utils/CustomerManager.php:70-77` (E10 F12) | **عالٍ** — محفظة المنصة `admin_id = 1` ثابتة؛ الرصيد الإداري لكل متجر أو كيان يحتاج تصميماً. `lavkoo:app/Utils/OrderManager.php:809-823` (E09 A3) | **منخفض** — لا تعرض لوحة البائع محفظة العميل؛ POS يدفع منها ويستحق المراجعة عند تعدد المتاجر. `lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:320-323` (E14 §2.6) | **عالٍ** — رصيد محفظة ونقاط واحدان في الملف الشخصي للحساب. `lavkooapp:lib/features/profile/domain/models/profile_model.dart:57-61` (E18 A3، F06) | **منخفض** — لا يعرض التطبيق محفظة عميل؛ الطابور المحلي لمبيعات POS مفتاحه عام على الجهاز. `lavkooappseller:lib/features/pos/controllers/offline_sales_controller.dart:21` (E20 A10) | **عالٍ** — `customer/info` يعيد `wallet_balance` و`loyalty_point` من صف `users` في هوية واحدة. `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:59-86` (E22 §3 بند 7) |
| **الثيم** (GCC-07، GCC-09، GCC-10) | **عالٍ** — `themes.is_active` حصري عالمياً؛ تفعيل ثيم دولة يطفئ الباقي، ونحو 14 موضع قراءة بلا سياق. `lavkoo:app/Services/Theme/ThemeManager.php:83-86` (E07 F02) | **عالٍ** — المحرر والنشر والنسخ والأرشفة مقيّدة بـ`theme_id` فقط، والصلاحيات بلا نطاق متجر، والنسخ ينقل معرّفات كيانات المصدر. `lavkoo:app/Services/Theme/ThemePermissionService.php:39-68` (E07 F08، F11) | **منخفض** — لوحة البائع لا تعرض الثيم؛ القناة `vendor_app` مسجّلة وغير قابلة للعرض. `lavkoo:app/Services/Theme/Channel.php:29-34` (E07 §4، E17 F20) | **عالٍ** — مفاتيح التخزين المحلي للحمولة بلا متجر، والتطبيق يعرض المخزّن قبل أي اتصال؛ بعد تبديل المتجر قد يظهر ثيم دولة أخرى. `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-172` (E17 F01؛ P0) | **منخفض** — لا يستهلك أي نقطة من نظام الثيم؛ يقرأ الألوان العامة من config واسم `active_theme`. `lavkooappseller:lib/features/shop/screens/shop_update_screen.dart:549` (E19 A11، §4) | **متوسط** — `theme/home` و`theme/version` بجواب إصدار واحد؛ الترويسة الاختيارية تحتاج `Vary` ومفتاح يضم المتجر، والافتراض السعودي يحفظ العقد. `lavkoo:app/Http/Controllers/RestAPI/v1/ThemeSectionController.php:236-239` (E17 C01، F02) |
| **الإشعارات والـ topics** | **متوسط** — الاشتراك في النشرة يحفظ البريد فقط في `subscriptions` بلا متجر أو أثر موافقة موثَّق. `lavkoo:app/Http/Controllers/Web/WebController.php:1576-1595` (E06 §2.5) | **عالٍ** — البث العام يذهب إلى topic `sixvalley` واحد وإلى جدول `notifications` عام؛ يلزم اختيار السوق قبل الإرسال. `lavkoo:app/Traits/PushNotificationTrait.php:449` (E06 F08) | **متوسط** — الإشعارات الداخلية لكل البائعين دون استهداف متجر؛ `SellerNotifier` يمنع التكرار بمفتاح بائع ونوع فقط. `lavkoo:app/Services/SellerIntelligence/SellerNotifier.php:46-49` (E14 §2.8) | **عالٍ** — topics ثابتة لا تُلغى عند التبديل، وقائمة الإشعارات داخل التطبيق عامة. `lavkooapp:lib/utill/app_constants.dart:324` (E16 §4.4، E22 F13) | **متوسط** — الرمز عمود واحد لكل بائع، وtopic `lavkoo_seller` بلا مرسل، والحمولة بلا معرّف متجر. `lavkooappseller:lib/utill/app_constants.dart:400-401` (E19 F05) | **متوسط** — `cm-firebase-token` عمود واحد، وحمولات الإشعار بلا معرّف متجر؛ الإضافة اختيارية. `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:630-632` (E23 §4.3 بند 11) |
| **الصفحات القانونية** (GCC-11) | **عالٍ** — جدول `business_pages` بمفتاح slug وحالة واحدة؛ لا دولة ولا لغة ولا إصدار. `lavkoo:database/migrations/2025_03_08_201607_create_business_pages_table.php:14-23` (E06 F01، P0) | **عالٍ** — التعديل كتابة مباشرة فوق الصف الحي؛ لا مسودة ولا اعتماد ولا سجل، وGCC-11 يتطلب مسودة وموافقة قبل البيع. `lavkoo:app/Http/Controllers/Admin/Settings/PagesController.php:102-127` (E06 §2.6) | **متوسط** — الصفحات عامة للبائعين والعملاء؛ لا وثيقة مستقلة لشروط البائع في كل دولة. `lavkoo:app/Http/Controllers/Web/PageController.php:26-42` (E06 §2.6) | **متوسط** — السياسات تُجلب من API وتُخزَّن مؤقتاً؛ نصوص قانونية مرفقة بالحزمة لولاية غير خليجية. `lavkooapp:assets/language/ar.json:2401` (E16 F14) | **متوسط** — شروط البائع عند الدخول هي الصفحة العامة نفسها. `lavkooappseller:lib/features/auth/screens/login_screen.dart:284-290` (E19 §4 GCC-11) | **متوسط** — `GET business-pages` بلا سياق؛ إضافة سياق اختياري ومفتاح cache بالمتجر. `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:310-320` (E06 F01، E22 §4) |
| **أوضاع الإيقاف** (GCC-12: browse-only / maintenance / redirect) | **عالٍ** — الصيانة تحجب الصفحات وتتبّع الطلب والاسترجاع، وتُبقي السلة والدفع؛ لا وضع browse-only. `lavkoo:routes/web/routes.php:162-357` (E06 F04، F02) | **متوسط** — إعداد الصيانة يحدد خمسة أنظمة لا متاجر، والإدارة معفاة؛ يلزم إعداد لكل متجر وحالة مفروضة على الخادم. `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:217-221` (E06 §2.7) | **عالٍ** — لوحة البائع الكلاسيكية داخل مجموعة `maintenance_mode`؛ إيقاف متجر دولة يجب ألا يوقف معالجة الطلبات القائمة. `lavkoo:routes/vendor/routes.php:45` (E06 §2.7، E14 F06) | **عالٍ** — شاشة الصيانة تحجب التطبيق كله ويُعاد الفحص عند العودة. `lavkooapp:lib/features/maintenance/maintenance_screen.dart:58-64` (E16 F08) | **عالٍ** — الصيانة عند الإقلاع تعرض شاشة كاملة لنظام `vendor_app`؛ لا تمثيل لمتجر. `lavkooappseller:lib/features/splash/screens/splash_screen.dart:87-91` (E19 F07) | **عالٍ** — الـ API لا يُحجب بالصيانة، و`config` يحمل حالة الصيانة للعميل؛ الإنفاذ لكل متجر يحتاج فحصاً في خدمة السلة والطلب. `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:106-111` (E06 F05) |
| **نطاق البائع متعدد الدول** (Seller ≠ Store ≠ Legal entity) | **متوسط** — صفحة المتجر `vendor-shop/{slug}` تفترض متجراً واحداً للبائع؛ يلزم صفحة لكل متجر دولة. `lavkoo:routes/web/routes.php:344` (E06 §2.8، E14 §2.5) | **عالٍ** — الاعتماد والتحقق والحالة `approved` على البائع لا على متجر؛ الاعتماد في دولة لا يُورَّث. `lavkoo:app/Services/Marketplace/SellerPermissionService.php:100` (E14 §4.3) | **عالٍ** — `Seller::shop()` بصيغة `hasOne` عملياً؛ `Shop::where('seller_id')->first()` في المتحكمات؛ يلزم اختيار متجر في كل المتحكمات. `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:63` (E14 §2.5، E23 F09) | **منخفض** — لا يعرف التطبيق البائع كوحدة دولة؛ بيانات البائع تأتي مع المنتج. `lavkooapp:lib/features/cart/screens/cart_screen.dart:151-163` (E18 §4) | **عالٍ** — رمز واحد = بائع واحد = متجر واحد؛ لا قائمة متاجر ولا تبديل. `lavkoo:app/Models/Seller.php:96-99` (E19 A3) | **عالٍ** — لا بُعد متجر في v3؛ الهوية من الرمز وحده؛ الإضافة وسيط اختياري وقائمة "متاجري". `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:56-64` (E23 F09) |
| **مالية البائع** (محافظ ودفاتر منفصلة بالكيان والعملة) | **منخفض** — الويب لا يعرض مالية البائع؛ فاتورة البائع تحمل هوية شركة عامة واحدة. `lavkoo:app/Services/SellerInvoiceService.php:41-52` (E14 F05) | **عالٍ** — عمولات وتسويات بكيان واحد؛ الدفتر لا يحمل كياناً، واسترداد الطلب الداخلي يقرأ محفظة المنصة `admin_id = 1`. `lavkoo:app/Services/Marketplace/VendorLedger.php:36-48` (E14 §4.1 C5، E09 F20) | **عالٍ** — `seller_wallets` بلا عملة، ودفتر الأستاذ يختم القيد بالعملة الافتراضية في المسار الأساسي؛ نظامان للسحب متوازيان. `lavkoo:app/Services/Marketplace/VendorLedger.php:104-116` (E14 F03، P0؛ E20 F01) | **منخفض** — لم يظهر استهلاك لمالية البائع في النطاق المفحوص (E18 §2)، والغياب الكامل UNKNOWN. | **عالٍ** — الأرصدة بلا عملة أو كيان، والتطبيق يحلّل `currency` ولا يستعمله. `lavkooappseller:lib/features/seller_center/domain/models/payout_models.dart:37-43` (E20 F01؛ P0) | **عالٍ** — `balance-withdraw*` على `seller_wallets`، و`seller-center/payouts` على الدفتر؛ تغيير أي منهما كاسر. `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:426-473` (E23 F12، E20 F08) |
| **بوابة الإصدار والتحديث الإجباري** | **لا أثر** — الويب لا يخضع لبوابة إصدار التطبيق. `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:236` (E17 §2.8) | **متوسط** — شاشة الإعدادات تكتب `seller_app_version_control` للمنصة كلها؛ الحد لكل متجر يحتاج قراراً إدارياً. `lavkoo:app/Http/Controllers/Admin/Settings/BusinessSettingsController.php:305-315` (E14 §2.7) | **لا أثر** — اللوحة ويب ولا تخضع لبوابة التطبيقات. `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:237` (E14 §2.7) | **عالٍ** — الحد الأدنى يُقارَن بثابت `16.2.1` لا بإصدار البناء `3.0.6+48`؛ تغيير الحد أو الفرض يمس كل النسخ المثبّتة. `lavkooapp:lib/features/splash/screens/splash_screen.dart:325-338` (E17 §2.8، E16 F07) | **عالٍ** — قيمة خاطئة تحجب كل البائعين قبل شاشة الدخول؛ الحد الإنتاجي UNKNOWN. `lavkooappseller:lib/features/splash/screens/splash_screen.dart:73-86` (E19 F06) | **متوسط** — الشكل ثابت ومشترك؛ الخادم لا يرفض الإصدارات القديمة لأن v3 بلا middleware إصدار. `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:237` (E14 §2.7) |
| **الروابط العميقة** (App Links / Universal Links) | **متوسط** — ملفا `.well-known` يُكتبان من الإعداد، والملفان المتتبَّعان قيمهما placeholder؛ أي نشر يعيدهما يعطّل الروابط. `lavkoo:public/.well-known/assetlinks.json:8` (E06 F14) | **متوسط** — إعداد واحد `app_deep_link` لتطبيق واحد ونطاق واحد. `lavkoo:app/Services/DeepLink/AppLinkService.php:53-70` (E06 §2.10) | **منخفض** — لا روابط عميقة للوحة البائع؛ الأنماط المنشورة للعميل فقط. `lavkoo:config/deeplinks.php:39-45` (E06 §2.10) | **عالٍ** — manifest أندرويد على `lavkoo.com` وiOS على `pharmacyksa.com`؛ المحلّل المحلي يعتمد أول مقطع، فيُفتح `/sa/…` في المتصفح. `lavkooapp:lib/data/services/deep_link_resolver.dart:86-118` (E06 §2.10، E16 F09) | **منخفض** — مخطط مخصص بلا معالج، ولا روابط https. `lavkooappseller:android/app/src/main/AndroidManifest.xml:88-93` (E19 F11) | **متوسط** — `GET /api/v1/deep-link/resolve` يطابق الأنماط حرفياً ويرفض host غير `app.url`؛ يلزم أنماط بادئة وإرجاع رمز المتجر. `lavkoo:app/Services/DeepLink/DeepLinkResolver.php:158-186` (E06 §2.10) |
| **التخزين المحلي والكاش المقيّد بالسوق** | **متوسط** — الجلسة تحمل اللغة والعملة والكوبون والعنوان بمفاتيح بلا متجر. `lavkoo:app/Http/Controllers/Web/CurrencyController.php:18-28` (E02 F04، E06 §2.8) | **متوسط** — 17 موضعاً تفرّغ الكاش كله من إجراءات إدارية؛ تغيير إعداد متجر يمسح متاجر أخرى. `lavkoo:app/Traits/CacheManagerTrait.php:134-151` (E02 F03، F09) | **عالٍ** — إغلاق متجر مؤقت لبائع واحد يستدعي `Cache::clear()` كاملاً، فيمسح كاش كل الأسواق. `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShopController.php:38` (E14 F10) | **عالٍ** — مفاتيح Dio والـ controller وDataSync بلا بُعد متجر؛ عرض المخزّن قبل الشبكة؛ يلزم مفتاح متجر ومسح شامل عند التبديل. `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-172` (E17 F01؛ P0) | **متوسط** — طابور POS وسلة الجهاز بمفتاح عام؛ تصبح عالية الأثر عند إدخال تبديل المتجر. `lavkooappseller:lib/features/pos/controllers/offline_sales_controller.dart:21` (E20 F06، E19 F15) | **متوسط** — بصمة الحمولة ومفتاح التخزين بلا متجر؛ يلزم إدخال المتجر في البصمة و`Vary`. `lavkoo:app/Services/Theme/ThemeDelivery.php:128-133` (E17 A2) |

**ملاحظات على المصفوفة:**

1. الخلايا المعلَّمة «لا أثر» تعني أن السطح لا يستهلك القدرة بحكم بنيته، ويُستند فيها إلى استشهاد بالبنية نفسها. أما الخلايا المعلَّمة «منخفض» بسبب الغياب فتصرّح بحدود الفحص.
2. الخلايا المعلَّمة «عالٍ» في عمود الـ API تعني أن العقد نفسه يحتاج تغييراً أو توثيقاً قبل أي تنفيذ، وأن النسخ المنشورة تستهلكه كما هو.
3. أكثر الخلايا عالية الأثر تتركّز في ثلاثة مواضع: الأرقام المالية بلا عملة، ومفاتيح الكاش والتخزين المحلي بلا متجر، والإنفاذ الشامل للصيانة والإصدار. وهي المواضع التي تحدد ترتيب التغييرات في القسم 4.

## 3. خطة التوافق مع النسخ المثبَّتة (الافتراض: السعودية)

### 3.1 ما تفترضه النسخ المثبَّتة

| النسخة | ما ترسله | ما تتوقعه من الخادم | ما يلزم ألا يتغير |
|---|---|---|---|
| تطبيق العميل (سلالة `lavkooapp`، وسلالة `ksaapp98` مرجعاً) | نداءات `/api/v1/*` بلا بادئة ولا ترويسة متجر؛ ترويسة `lang` (وقد أرسلت إصدارات قديمة رمز الدولة فيها)؛ `X-App-Version` بثابت `16.2.1` (`lavkooapp:lib/utill/app_constants.dart:29`، E16 F07)؛ `POST` مع `_method` للمسارات `PUT`/`DELETE` (E16 §9.3) | `config` بتحليل صارم في `fromJson` (E16 F03، P0)؛ topics `sixvalley` و`maintenance_mode_start_user_app` و`theme_updates_user_app` (E16 §4.4)؛ محلّل روابط بأول مقطع (E16 F09) | أسماء الحقول وأنواعها في `config`؛ مسارات `/api/v1` كما هي؛ معنى `lang`؛ شكل الخطأ والـ 401 |
| تطبيق البائع (`lavkooappseller`) | `GET config`؛ `POST auth/login`؛ `cm-firebase-token`؛ `_method` في بعض الطلبات (E23 §2.2) | `config` بـ `colors` و`unit` و`currency_list` غير null (`lavkooappseller:lib/features/splash/domain/models/config_model.dart:229-230`، E19 F12)؛ `token` سلسلة في جذر استجابة الدخول؛ 401 بالشكل الحالي (E23 F07) | الأنواع في الجذر؛ شكل `seller-info` و`wallet` ككائن واحد؛ topics `lavkoo_seller` و`maintenance_mode_start_vendor` |
| المتصفح (الويب) | مسارات جذرية؛ روابط بريد موقّعة (`cart.recover`، صلاحية 30 يوماً)؛ روابط `/go/{code}` مطبوعة | مسارات الجذر وتحويلاتها؛ توقيع الروابط (E06 §2.14) | المسار القديم يبقى تحويلاً طوال نافذة الصلاحية؛ `/go/{code}` دائماً |

### 3.2 قواعد التوافق المقترحة (PROPOSED)

1. **غياب سياق المتجر = متجر السعودية المثبَّت صراحةً.** يُربط الافتراض بمعرّف متجر سعودي ثابت، لا بـ«الافتراضي الحالي» الذي يقبل التغيير لاحقاً بحسب GCC-19. هذه قيد تنفيذ لا إعادة فتح للقرار، وقد بيّنه E16 §4.4 بند 1 وE22 §6.2 (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:25`).
2. **عقود إضافية فقط.** لا حذف، ولا إعادة تسمية، ولا تغيير نوع لحقل قائم، ولا تغيير في معنى 401 (E22 §6.3 البنود 1–3 و8–10).
3. **المال الحالي للسعودية يبقى كما هو.** الحقول الجديدة (`currency_code`، `decimal_places`) تُضاف بجانب الأرقام الحالية، ولا يتغير معنى الرقم القائم للسعودية (E22 §5).
4. **`lang` لا يحمل الدولة.** يبقى تحويل `sa` إلى `ar` كما هو للنسخ القديمة، ويُدخل سياق المتجر في مفتاح مستقل (E22 §3 بند 6؛ E19 F18).
5. **بوابة الإصدار لا ترتفع إلا لميزة إلزامية.** الحد الأدنى العام يبقى لكل النسخ، ولا يُرفع إلا عند ميزة تتطلب إصداراً جديداً (E19 §4، صف Guardrail 4؛ E23 §4.3 بند 9).
6. **الصيانة الشاملة تبقى منفصلة عن صيانة المتجر.** علم صيانة المنصة يبقى كما هو، وصيانة متجر لا تُكتب فوقه، لأن بقاءه عاماً يحجب النسخ كلها (E16 §4.4 بند 5).
7. **الروابط القديمة تبقى تحويلاً لا حذفاً.** المسارات الجذرية تعيد التوجيه إلى مسار المتجر السعودي، و`/go/{code}` يبقى دائماً، والروابط الموقّعة تبقى صالحة طوال نافذتها (E06 §2.14).
8. **اختبار عقد على لقطة النسخ المنشورة قبل كل نشر.** يُستعمل `api:snapshot` وكشف التغييرات الكاسرة الموجودان (E22 §5)، ويُوسَّع اختبار العقد ليغطي الخادم، لأن الاختبارات المقابلة في الخلفية محدودة (E23 F11).

### 3.3 ما لا يمكن إثباته بالأدلة الحالية

- توزيع النسخ المثبَّتة في المتجرين، وأي السلالتين منشورة فعلاً: **UNKNOWN** (E16 §4.4؛ يحتاج Play Console وApp Store Connect).
- قيم `business_settings` الإنتاجية، وخصوصاً `currency_model` والعملة الافتراضية و`seller_app_version_control`: **UNKNOWN** (E20 F07، E19 F06).
- نجاح نداءات `POST` مع `_method` إلى مسارات `PUT`/`DELETE` في الإنتاج: **UNKNOWN** (E16 §9.4، E19 F12).
- ما إذا كان مشروع Firebase في الخلفية يخدم السلالتين معاً: **UNKNOWN** (E16 §4.4 بند 8).

## 4. ترتيب التغييرات حسب خطر الكسر (PROPOSED)

الترتيب من الأقل خطراً إلى الأعلى. لا يبدأ أي بند قبل اكتمال ما قبله، وأي بند من الفئة «عالٍ» أو «حرج» يحتاج قراراً من المالك قبل التنفيذ.

| الترتيب | التغيير المقترح | خطر الكسر | السبب | الدليل |
|---|---|---|---|---|
| 1 | حقول إضافية في `config` وفي الاستجابات المالية (`currency_code`، `decimal_places`) | منخفض | المحللات تتجاهل المفاتيح غير المعروفة، والقيم القائمة لا تتغير | E22 §6.3 بند 8 |
| 2 | ترويسة سياق اختيارية مع `Vary`، وغيابها = السعودية | منخفض | سابقة `X-UI-*` قائمة، والنسخ القديمة لا ترسل الترويسة | E22 §6.3 بند 9؛ E17 C03 |
| 3 | نقاط جديدة: قائمة المتاجر المتاحة، وإعداد متجر للبائع بعد الدخول | منخفض | لا يناديها أي إصدار منشور | E22 §6.3 بند 10؛ E23 §4.3 بند 6 |
| 4 | topics بلاحقة السوق بجانب القديمة | منخفض | القديمة تبقى تعمل، والجديدة لا يشترك فيها أحد من النسخ المنشورة | E06 F08؛ E16 §4.4 |
| 5 | قوالب بريد وإشعارات بطبقة override تسقط إلى العام | متوسط | الافتراض العام يبقى، لكن الحمولة تتغير | E06 F07 |
| 6 | مفاتيح cache بالمتجر، مع إفراغ انتقائي بدل الإفراغ الشامل | متوسط | تغيير المفاتيح يبطل الكاش مرة واحدة، والإفراغ الشامل مصدر الخطر الحالي | E02 F03، F09؛ E14 F10 |
| 7 | صفحات قانونية بإصدارات ومسودة وموافقة، مع بقاء الصف الحالي إصداراً سعودياً منشوراً | متوسط | الصفحات الحالية تصبح الإصدار المنشور للسعودية، فلا يتغير ما يراه المستخدم | E06 F01؛ E06 §5 |
| 8 | حالة متجر على الخادم، ومعها قائمة استثناء لما بعد البيع (الطلبات والاسترجاع والدعم والمدفوعات) | عالٍ | يغيّر ما تحجبه الصيانة في الويب ولوحة البائع والـ API، فيحتاج اختباراً انحدارياً شاملاً | E06 F02، F04، F05؛ E14 F06 |
| 9 | مسارات واعية بالمتجر `/sa…/om` مع تحويلات 301 من الجذر | عالٍ | يمس الروابط المنشورة والفهرسة وروابط البريد والروابط الموقعة، وإصدارات التطبيق المنشورة لا تفهم البادئة بعد | E06 F10؛ E16 §4.4 بند 6؛ E06 §7 بند 9 (قرار مالك) |
| 10 | اختيار المتجر في تطبيق البائع، ورمز بنطاق متجر | عالٍ | يمس المصادقة وتسعة طلبات multipart خارج `DioClient` وطابور POS المحلي، ويحتاج إصداراً جديداً للتطبيق | E19 §6 (F08، F15)؛ E23 §4.3 بند 7 |
| 11 | بوابة إصدار لكل متجر، أو رفض خادمي للبناء القديم في المتاجر غير السعودية | عالٍ | رفع الحد العام يُجبر بائعي السعودية على التحديث، ورفض البناء القديم يقطع البائعين الحاليين | E19 F06؛ E23 §4.3 بند 9 |
| 12 | تفعيل الثيم لكل متجر، وتعديل `activate` وحملات ومفاتيح التجارب بنطاق المتجر | عالٍ | `is_active` حصري عالمياً، والحملات والتجارب تُطبَّق على كل الثيمات | E07 F02، F07؛ E17 A17 |
| 13 | حارس الدفع بكيان واحد (خادمياً وفي الواجهة) | عالٍ | الحارس يرفض سلالاً كانت تمر اليوم، ويمس واجهة السلة وطلب الدفع | E09 F05؛ E18 F08؛ E10 F07 |
| 14 | تحويل المال إلى عملة المتجر: لقطة عملة وكسور على الطلب، وإزالة التحويل العام في العميل والخادم | حرج | يغيّر معنى كل مبلغ معروض أو مكتوب، ويحتاج لقطة لكل طلب تاريخي لتفادي إعادة التفسير | E02 F01؛ E18 F02؛ E22 F02؛ E23 §4.3 بنود 1–2 |
| 15 | فصل المحافظ والدفاتر بالكيان والعملة، وترحيل الأرصدة التاريخية | حرج | نظامان للسحب متوازيان، وأرصدة بلا عملة، ورصيد واحد لكل مستخدم عبر المتاجر | E14 F03؛ E20 F01، F08؛ E18 F06؛ E10 F12 |
| 16 | تغيير دلالة `lang` أو `country_code` | حرج | النسخ المنشورة ترسل `sa` بمعنى اللغة، وتغيير الترجمة يكسر اللغة العربية فيها | E16 F12؛ E19 F18؛ E22 §3 بند 6 |
| 17 | حساب بوابة لكل كيان ومتجر، وتغيير مسار العودة والـ webhook | حرج | يمس الدفعات الجارية، وGCC-12 يشترط أن تبقى المدفوعات والاستردادات والـ webhooks تعمل | E10 F01، F02، F19؛ E18 F09 |
| 18 | تغيير أسماء الحزم أو معرّفات التطبيقات أو Firebase | حرج (خارج GCC) | يكسر التحديث في المتجر ويفصل المستخدمين؛ محظور تلقائياً بتعليمات المشروع | E16 F02؛ `lavkooapp:CLAUDE.md:3` |

## 5. تأكيد: تطبيق البائع لا يشارك معمارية ثيم العميل إلا بما يثبته الدليل

**المثبت:**

- تطبيق البائع يشارك مع العميل نقطة `GET /api/v1/config` وحدها، وفيها الألوان والاسم والشعار وقيمة `active_theme` وحالة الصيانة وحد الإصدار (E19 F03؛ `lavkooappseller:lib/utill/app_constants.dart:14`).
- ألوان التطبيق ثابتة في الكود، فاتحة وداكنة، ولا تأتي من الخادم (`lavkooappseller:lib/theme/light_theme.dart:11-12`، E19 §4).
- لا يستهلك تطبيق البائع أي نقطة من نظام الثيم الديناميكي؛ بحث E19 في `theme-delivery|ThemeDelivery|theme_sync` وفي مسارات الثيم أعاد صفراً (E19، عدسة GCC البند 7).
- الخادم يعيد قائمة مكوّنات فارغة لقناة البائع (`lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:156-158`)، وقناة `vendor_app` غير قابلة للعرض (`lavkoo:app/Services/Theme/Channel.php:29-34`) (E07 §4، E17 F20).
- الارتباط الوحيد بالثيم العام هو قيمة `active_theme` التي تحدد اشتراطات صور المتجر في شاشة التحديث (`lavkooappseller:lib/features/shop/screens/shop_update_screen.dart:549`، E19 A11، E19 F13). هذا ارتباط بقيمة إعداد، لا بمعمارية.

**غير المثبت (UNKNOWN):**

- لم يُفحص سطراً بسطر أن كل شاشات تطبيق البائع تقرأ ألوانها من `config`، إذ لم تُفحص شاشات الميزات التشغيلية كلها (E19 §9، فجوات التغطية). لذلك لا يُقال إن الألوان تُطبَّق على الواجهة كلها.
- لم يُثبت أن قيم `colors` المرسلة للبائع تُستعمل في شاشات غير شاشة الصيانة. هذا يحتاج فحص مكوّنات الواجهة في `lavkooappseller/lib`.

**الخلاصة لـ GCC-09:** تطبيق البائع لا يشارك معمارية السمة الديناميكية. يشارك إعداد `config` العام وقيمة `active_theme`، ومن ثم فإن تحويل `config` إلى سياق متجر، أو تغيير معنى `active_theme`، هو الاقتران الوحيد الذي يمس تطبيق البائع من عالم الثيم.

## 6. المسائل المقيّدة المشار إليها

المعرّفات التالية وردت في الملاحق، وتفاصيلها في الملحق المقيّد غير المنشور. لا يُنقل منها أي تفصيل إلى هذا التقرير:

- SEC-E16-09: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E16-11: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E18-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E18-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E19-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

## حدود هذا التقرير وعناصر UNKNOWN

1. **الأثر ليس شدة عيب.** المصفوفة تقيس حجم التغيير المطلوب على كل سطح. شدة العيوب القائمة مسجلة في التقرير 05.
2. **لا قيم إنتاجية.** كل قيمة من `business_settings` أو `.env` أو قاعدة الإنتاج موسومة UNKNOWN، وتحتاج قراءة للقاعدة الإنتاجية مع حجب الأسرار (E06 §7 بند 1، E02 §7).
3. **توزيع النسخ المثبَّتة UNKNOWN.** لا يعرف هذا التقرير عدد المستخدمين على كل نسخة، ولا أي سلالة منشورة فعلاً. يحتاج Play Console وApp Store Connect (E16 §4.4).
4. **المستودع القديم غير متاح.** `pharmacyksa98`: UNKNOWN (missing access: pharmacyksa98)، ولم يُعتمد عليه في أي خلية.
5. **مخطط الجداول غير المتتبَّع UNKNOWN.** جداول الثيم والصفحات والتجارب والحملات والـ Seller Center بلا migration في المستودع، والمخطط الفعلي يحتاج `SHOW CREATE TABLE` من الإنتاج (E06 §2.11، E07 F01، E14 F08).
6. **التحقق من الكود بالعينة.** جرى التحقق الآلي من كل الاستشهادات وعيّنة من عشرة استشهادات بالمحتوى. الاستشهادات المحالة إلى أقسام الملاحق مشتقة من ملاحق متحقَّق منها، ولم يُعَد فتح كل منها.
7. **لم تُفحص شاشات تشغيلية كاملة.** شاشات الميزات التشغيلية في تطبيق البائع، وجزء من تطبيق العميل، خارج عينة الملاحق (E19 §9، E16 §9.7).
8. **السلوك لا القراءة الساكنة.** لم يُشغَّل أي اختبار. حالات مثل `_method` وأثر فشل تحليل `config` وأثر بوابة الإصدار عند الإقلاع البارد تبقى INFERRED أو UNKNOWN كما في الملاحق.
9. **لا إعادة فتح لقرار معتمد.** لم يجد هذا التقرير تعارضاً يستدعي إعادة فتح أي قرار من `docs/gcc/DECISIONS.md`. قيد GCC-19 في القسم 3.2 هو قيد تنفيذ للنسخ المثبتة، لا تعديل للقرار.

## سجل الأدلة الرئيسية

| الادعاء | الموضع | الحالة |
|---|---|---|
| المسارات العامة جذرية بلا بادئة دولة أو لغة | `lavkoo:app/Providers/RouteServiceProvider.php:215-220` | VERIFIED |
| الإعدادات العامة مفردة بلا نطاق متجر | `lavkoo:app/Utils/settings.php:14-34` | VERIFIED |
| الكاش العام للكتالوج والثيم بلا بُعد متجر | `lavkoo:app/Utils/product.php:224-226`؛ `lavkoo:app/Services/Theme/ThemeDelivery.php:128-133` | VERIFIED |
| المبالغ مخزّنة بلا عملة وتُفسَّر بإعدادات عامة | `lavkoo:app/Utils/currency.php:50-67` | VERIFIED (الأثر INFERRED) |
| تطبيق العميل يضرب بسعر الصرف ويطبع صفر خانات | `lavkooapp:lib/helper/price_converter.dart:65-78` | VERIFIED |
| تطبيق البائع يجمع الأرصدة بلا عملة | `lavkooappseller:lib/features/seller_center/domain/models/payout_models.dart:37-43` | VERIFIED (الكود)؛ UNKNOWN (إنتاجياً) |
| محفظة البائع بلا عملة ولا كيان | `lavkoo:app/Models/SellerWallet.php:28-39` | VERIFIED |
| دفع واحد يجمع كل مجموعات السلة دون حارس كيان | `lavkoo:app/Utils/OrderManager.php:887-902` | VERIFIED |
| محفظة المنصة ثابتة على `admin_id = 1` | `lavkoo:app/Utils/OrderManager.php:809-823` | VERIFIED |
| حساب بوابة واحد لكل نظام | `lavkoo:app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:181` | VERIFIED |
| مسارات الدفع والـ webhook خارج مجموعة الصيانة | `lavkoo:routes/web/routes.php:574-612` | VERIFIED |
| الصيانة تحجب الطلبات السابقة على الويب وتُبقي السلة والدفع | `lavkoo:routes/web/routes.php:162-357`؛ `lavkoo:routes/web/routes.php:359-388` | VERIFIED |
| الصيانة تُنفَّذ في التطبيقات من طرف العميل | `lavkooapp:lib/features/maintenance/maintenance_screen.dart:58-64`؛ `lavkooappseller:lib/features/splash/screens/splash_screen.dart:87-91` | VERIFIED |
| إغلاق متجر البائع المؤقت يمسح الكاش كله | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShopController.php:38` | VERIFIED |
| الصفحات القانونية نموذج واحد بلا إصدارات ولا اعتماد | `lavkoo:database/migrations/2025_03_08_201607_create_business_pages_table.php:14-23`؛ `lavkoo:app/Http/Controllers/Admin/Settings/PagesController.php:102-127` | VERIFIED |
| حد إصدار تطبيق العميل ثابت `16.2.1` لا يطابق البناء | `lavkooapp:lib/utill/app_constants.dart:29`؛ `lavkooapp:lib/features/splash/screens/splash_screen.dart:325-338` | VERIFIED (المنطق)؛ UNKNOWN (الإنتاج) |
| حد إصدار تطبيق البائع الإنتاجي غير معلوم ومتضارب | `lavkooappseller:lib/features/splash/screens/splash_screen.dart:73-86` | UNKNOWN (القيمة)؛ VERIFIED (المنطق) |
| تطبيق البائع لا يستهلك نقاط نظام الثيم؛ القناة `vendor_app` غير قابلة للعرض | `lavkoo:app/Services/Theme/Channel.php:29-34`؛ `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:156-158` | VERIFIED |
| ألوان تطبيق البائع ثابتة في الكود | `lavkooappseller:lib/theme/light_theme.dart:11-12` | VERIFIED |
| تطبيق البائع يقرأ `active_theme` لاشتراطات الصور | `lavkooappseller:lib/features/shop/screens/shop_update_screen.dart:549` | VERIFIED |
| البث العام إلى topic واحد `sixvalley` | `lavkoo:app/Traits/PushNotificationTrait.php:449` | VERIFIED |
| تطبيق البائع لا يملك مرسلاً لـ topic `lavkoo_seller` | `lavkooappseller:lib/utill/app_constants.dart:400-401` | VERIFIED (الغياب بالبحث في E14 §2.8) |
| رمز اللغة `sa` يُطوى إلى `ar` في الخلفية | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29` | VERIFIED |
| لا وسيط متجر في عقد البائع v3 | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:56-64` | VERIFIED |
| المحفظة والنقاط واحدة في هوية العميل | `lavkoo:app/Http/Controllers/RestAPI/v1/CustomerController.php:59-86` | VERIFIED |
| واجهة السلة تسمح بخلط مجموعات بائعين في checkout واحد دون فحص كيان | `lavkooapp:lib/features/cart/screens/cart_screen.dart:666-680` | VERIFIED |
| الرابط العميق يعتمد أول مقطع والـ manifest على host مخالف لـ iOS | `lavkooapp:lib/data/services/deep_link_resolver.dart:86-118`؛ `lavkooapp:ios/Runner/Runner.entitlements:17-20` | VERIFIED |
| الملفان المتتبَّعان `.well-known` قيمهما placeholder | `lavkoo:public/.well-known/assetlinks.json:8` | VERIFIED (الكود)؛ UNKNOWN (الإنتاج) |
| مسارات `/api/v1` ثابتة في التطبيق بلا بادئة دولة | `lavkooapp:lib/utill/app_constants.dart:39` | VERIFIED |
