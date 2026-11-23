# E10 — المدفوعات: البوابات، التحقق، Webhooks، التوجيه، الاستردادات (Payments: gateways, verification, webhooks, routing, refunds)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo` عند `lavkoo@7f42777c` (الـ HEAD المحلي وقت الفحص `lavkoo@046d623d`، والفرق بينهما ملف توثيق واحد تحت `docs/gcc/audits/` ولا يمس أي كود)؛ `lavkooapp` عند `lavkooapp@eedbbb8f` (للاستشهاد بعقد الدفع من جهة التطبيق فقط).

## 1. النطاق وما تم فحصه

**ما تم فحصه (قراءة ثابتة فقط، نحو 78 ملفاً):**

- متحكمات البوابات: `app/Http/Controllers/Payment_Methods/` (16 ملفاً، 5168 سطراً) — قُرئت كاملةً: `PaymobController.php` و`MyFatoorahController.php`؛ وقُرئت مسارات العودة (callback/success) في: Stripe، PayPal، Paytabs، SenangPay، Paystack، FlutterwaveV3، MercadoPago؛ وفُحصت بالبحث النمطي: RazorPay، Bkash، SslCommerz، LiqPay، Paytm، Paymera، MtnCash.
- التدفق العام: `app/Http/Controllers/Customer/PaymentController.php` (662 سطراً، كاملاً)، `app/Traits/Payment.php`، `app/Traits/PaymentGatewayTrait.php`، `app/Traits/Processor.php`، `app/Library/Payment.php`، `app/Utils/module-helper.php` (الأسطر 1–334: دوال الـ hooks)، `app/Traits/OrderEditManager.php` (711–800).
- MyFatoorah: الخدمات الخمس عشرة في `app/Services/MyFatoorah/` (قُرئت كاملةً ما عدا `ConnectionTester.php` و`MyFatoorahResponse.php`)، `app/Http/Controllers/Api/MyFatoorahWebhookController.php`، `app/Http/Controllers/RestAPI/v1/MyFatoorahStatusController.php`، `app/Http/Controllers/Admin/MyFatoorah/SettingsController.php` (جزئياً)، النماذج الثلاثة، الـ enums، الترحيلات السبعة `2026_11_08_10000[1-7]`، `config/myfatoorah.php`، `routes/rest_api/myfatoorah_webhook.php`، `app/Console/Commands/MyFatoorahReconcile.php`، `docs/integrations/myfatoorah.md` (العناوين والمقاطع المتعلقة بالدولة/العملة).
- الإعدادات والتوجيه: `app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php`، `app/Http/Controllers/Admin/PaymentMethodController.php`، `app/Http/Requests/Admin/PaymentMethodUpdateRequest.php` (جزئياً)، `app/Models/Setting.php`، `app/Services/Payments/GatewayReadiness.php`، `app/Console/Commands/PaymentGatewayCheck.php`، `app/Services/Marketplace/PaymentRoutingService.php`، `app/Models/PaymentRoutingRule.php`، `app/Utils/Helpers.php` (273–285 و831–867).
- المحفظة/COD/الدفع غير الإلكتروني/الاسترداد: `app/Http/Controllers/RestAPI/v1/OrderController.php` (171–500)، `app/Http/Controllers/Web/WebController.php` (381–510، 806–896)، `app/Utils/CustomerManager.php` (36–91)، `app/Services/RefundStatusService.php`، `app/Services/RefundTransactionService.php`، `app/Http/Controllers/Admin/Order/RefundController.php` (109–160)، `docs/integrations/cod.md`، `config/fulfillment.php`.
- المسارات: `routes/web/routes.php` (440–657)، `routes/rest_api/v1/api.php` (المسارات المتعلقة بالدفع)، `routes/admin/routes.php` (918–919، 1330–1375)، `app/Providers/RouteServiceProvider.php`.
- ملفات `config/` للبوابات: `paypal.php`، `paytm.php`، `razor.php`، `flutterwave.php`، `sslcommerz.php`، `payuz.php` — كلها تقرأ الأسرار من `env()` بقيم افتراضية فارغة، ولم يُعثر فيها على قيمة سرية مثبتة؛ وتحمل ثلاثة منها قيماً افتراضية غير سرية للوضع أو البيئة (`PAYPAL_MODE` = `sandbox`، `PAYTM_ENVIRONMENT` = `production`، ونطاق API الافتراضي لـ SSLCommerz).

**أنماط البحث المسجَّلة** (غير حساسة لحالة الأحرف، `rg -il` على `app/ config/ routes/ resources/views/ database/ Modules/`):

| النمط | عدد الملفات | الملاحظة |
|---|---|---|
| `paymob` | 12 | متحكم + قوائم الثوابت + نموذج الإعداد |
| `tabby` | 0 | **غير موجود** |
| `tamara` | 0 | **غير موجود** |
| `my_?fatoorah\|fatoorah` | 51 | تكامل كامل |
| `stc_?pay\|stcpay` | 0 | الاسم «STC Pay» بمسافة يرد في الكتالوج والاختبارات والتوثيق فقط |
| `\bmada\b` | 1 (في النطاق المسجَّل) | تعليق في `PaymentMethodCatalog.php` فقط ضمن النطاق؛ يرد الاسم نفسه في 4 ملفات إضافية داخل `tests/` و`docs/` |
| `apple_?pay` | 0 | الاسم «Apple Pay» بمسافة يرد في تعليق الكتالوج وبيانات الاختبار وتوثيق MyFatoorah فقط؛ لا قائمة ثابتة له |
| `hyper_?pay` | 7 | قوائم ثوابت فقط (stub) |
| `moyasar` | 0 | — |
| `'tap'\|tap_pay\|payment/tap` | 7 | قوائم ثوابت فقط (stub) |
| `thawani` | 7 | قوائم ثوابت فقط (stub) |
| `telr`، `knet`، `benefit_?pay` | 0 في النطاق المسجَّل | KNET يرد في بيانات اختبار MyFatoorah فقط (`lavkoo:tests/Feature/MyFatoorah/RefundAndAdminTest.php:324`) |
| `bnpl`، `buy now`، `pay later`، `installment` (بحث إضافي) | مطابقات في الواجهات والترجمات العامة؛ المطابقة ذات الصلة الوحيدة معامل الأقساط في MercadoPago (`lavkoo:app/Http/Controllers/Payment_Methods/MercadoPagoController.php:85`) | لا يوجد مزوّد BNPL |

وبحث إضافي عن `tabby|tamara` في كامل مستودع `lavkoo` (باستثناء `vendor/`، `node_modules/`، `public/`) أعاد ملفين فقط هما وثيقتا `docs/gcc/DECISIONS.md` و`docs/gcc/DISCOVERY_PROMPT_01.md`، وفي `lavkooapp/lib` و`lavkooappseller/lib` أعاد صفر ملفات.

**ما لم يُفحص:** قيم قاعدة البيانات الإنتاجية (`addon_settings`، `business_settings`، `currencies`)، ملف `.env`، لوحات مزوّدي الدفع، المستودع القديم `pharmacyksa98` (UNKNOWN — missing access)، التنفيذ الداخلي لـ `OrderManager::generateOrder` بعد السطر 1560 (نطاق ملحق المخزون/الطلبات)، منطق المحفظة والولاء بالتفصيل (نطاق E04)، تطبيق البائع، واجهات Blade لصفحات الدفع، ومحتوى اختبارات MyFatoorah (عُدَّت فقط). لم يُشغَّل أي اختبار أو أمر artisan.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 جرد البوابات الموجودة فعلياً في الكود

مصدر الحقيقة لقائمة البوابات أربع قوائم ثابتة في الكود يجب أن تتطابق: خريطة المسارات في `lavkoo:app/Traits/Payment.php:39-82` (`Payment::generate_link`)، قائمة `Helpers::getDefaultPaymentGateways` في `lavkoo:app/Utils/Helpers.php:273-285`، الثابت `GlobalConstant::DEFAULT_PAYMENT_GATEWAYS` في `lavkoo:app/Enums/GlobalConstant.php:1129-1149`، وخريطة العملات المدعومة في `lavkoo:app/Traits/PaymentGatewayTrait.php:9-474` (`getPaymentGatewaySupportedCurrencies`). يُضاف إليها قائمتا التحقق في نموذج الإدارة `lavkoo:app/Http/Requests/Admin/PaymentMethodUpdateRequest.php:30`.

خريطة المسارات تحتوي 40 مفتاحاً؛ منها 17 مفتاحاً لها مسار مسجَّل ومتحكم (16 بوابة مدرجة في القائمة الافتراضية + الاسم البديل `my_fatoorah`)، و23 مفتاحاً بلا مسار ولا متحكم (stub: سلسلة نصية في الخريطة فقط). (حُسبت آلياً بمقارنة مفاتيح الخريطة مع بادئات `Route::group` في `routes/web/routes.php`.)

| البوابة (`key_name`) | المتحكم / الخدمة | مصدر الإعداد | الحالة | الدليل |
|---|---|---|---|---|
| **Paymob** (`paymob_accept`) | `PaymobController` | `addon_settings` | **Present — implemented** | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:14-56`، المسار `lavkoo:routes/web/routes.php:573-577` |
| **MyFatoorah** (`fatoorah`؛ بدائل `my_fatoorah`/`myfatoorah`) | `MyFatoorahController` + 15 خدمة + webhook + reconcile + refunds | `addon_settings` (أسرار مشفَّرة) + `config/myfatoorah.php` | **Present — implemented (الأحدث والأكثر صلابة)** | `lavkoo:app/Http/Controllers/Payment_Methods/MyFatoorahController.php:33-42`، `lavkoo:routes/web/routes.php:599-612`، `lavkoo:routes/rest_api/myfatoorah_webhook.php:19-21` |
| **Tabby** | — | — | **Absent** | البحث المسجَّل في القسم 1 (صفر ملفات) |
| **Tamara** | — | — | **Absent** | البحث المسجَّل في القسم 1 (صفر ملفات) |
| Stripe، PayPal، RazorPay، Paystack، SenangPay، Flutterwave، Paytm، Paytabs، LiqPay، MercadoPago، bKash، SSLCommerz | متحكم لكل بوابة | `addon_settings` | implemented (موروثة من القالب الأصلي) | `lavkoo:routes/web/routes.php:484-584` |
| Paymera (`paymera`) | `Payment_Methods\PaymeraController` | `addon_settings` | implemented — عملات SYP/USD | `lavkoo:routes/web/routes.php:648-655`، `lavkoo:app/Traits/PaymentGatewayTrait.php:24-27` |
| MTN Cash (`mtn_cash`) | `MtnCashPaymentController` (1533 سطراً) | `addon_settings` + `mtn_cash_terminals` | implemented — SYP فقط | `lavkoo:routes/web/routes.php:617-644`، `lavkoo:app/Http/Controllers/Payment_Methods/MtnCashPaymentController.php:64-69` |
| `hyper_pay`، `tap`، `thawani`، `foloosi`، `amazon_pay`، `xendit`، `iyzi_pay`، `ccavenue`، `pvit`، `moncash`، `viva_wallet`، `hubtel`، `maxicash`، `esewa`، `swish`، `momo`، `payfast`، `worldpay`، `sixcash`، `phonepe`، `cashfree`، `instamojo`، `mercadopago_pix` | لا يوجد | — | **stub** (مفتاح في الخرائط فقط؛ لا مسار ولا متحكم) | `lavkoo:app/Traits/Payment.php:59-81` |

ملاحظات على الجرد (VERIFIED):

- تكامل MyFatoorah أُنشئ داخل هذا المستودع في 2026-11-08 بتسعة commits متتالية تبدأ بـ `lavkoo@2b249507` وتنتهي بـ `lavkoo@df53a8c4`؛ وتعليق الترحيل يصرّح بأن المفتاح `fatoorah` كان موجوداً في القوائم «but no row was ever created and no controller was ever written» (`lavkoo:database/migrations/2026_11_08_100004_register_myfatoorah_payment_gateway.php:11-16`). علاقة ذلك بالـ PR #10 في المستودع القديم: UNKNOWN (missing access: pharmacyksa98).
- `PaymobController.php` لم يتغير منذ الـ commit الأولي `lavkoo@34dce658` (حسب `git log` على الملف).
- مسارات البوابات كلها مشروطة بعدم وجود إضافة `Modules/Gateways` منشورة (`lavkoo:routes/web/routes.php:472-482`)؛ المجلد غير موجود في المستودع (الموجود: `AI`، `Blog`، `TaxModule`)، فالمسارات مسجَّلة. لو نُشرت تلك الإضافة لاحقاً لاختفت هذه المسارات جميعها بما فيها MyFatoorah (INFERRED من الشرط).
- توجد نسخ مكرَّرة غير موصولة بمسارات: `lavkoo:app/Http/Controllers/Payment/PaymeraController.php:16` (المسارات تستورد نسخة `Payment_Methods`: `lavkoo:routes/web/routes.php:23`)، `lavkoo:app/Packages/PaystackGateway/NewPaystackController.php:18`، `lavkoo:app/Traits/Payment.txt:1`، و`lavkoo:app/Http/Controllers/Admin/PaymentMethodController.php:75` (المسارات تستخدم نسخة `ThirdParty`: `lavkoo:routes/admin/routes.php:1331-1337`).

### 2.2 التدفق العام: دورة حياة `payment_requests` والـ hooks

1. **البدء.** الويب يرسل `POST customer/web-payment-request` (`lavkoo:routes/web/routes.php:458-464`) والتطبيق يرسل `POST /api/v1/digital-payment` (`lavkoo:routes/rest_api/v1/api.php:444-446`)؛ كلاهما يصل إلى `PaymentController::payment` (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:73-201`) الذي يتحقق من صفحة الدفع، العنوان، المخزون (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:131-141`) والحد الأدنى للطلب، ثم يستدعي `getCustomerPaymentRequest`.
2. **حساب المبلغ على الخادم.** المبلغ هو مجموع `order_amount_with_tax` لكل مجموعات السلة (كل البائعين معاً) من `OrderManager::processOrderGenerateData` (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:379-383`). أي أن **طلب دفع واحداً يغطي عدة طلبات (طلب لكل بائع/مجموعة سلة)**.
3. **إنشاء الصف.** `Payment::generate_link` ينشئ صف `payment_requests` بالحقول: `payment_amount`، `success_hook`، `failure_hook`، `payer_id`، `receiver_id`، `currency_code`، `payment_method`، `additional_data` (JSON)، `payer_information`، `receiver_information`، `external_redirect_link`، `attribute`، `attribute_id`، `payment_platform` (`lavkoo:app/Traits/Payment.php:22-37`)، ثم يعيد رابطاً `payment/<gateway>/pay/?payment_id=<uuid>` (`lavkoo:app/Traits/Payment.php:83-87`). النموذج `PaymentRequest` يستخدم UUID و`$guarded = []` (`lavkoo:app/Models/PaymentRequest.php:9-16`).
   - `receiver_id` قيمة ثابتة `'100'` و`attribute_id` طابع زمني `idate("U")` وليس رقم طلب (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:432-437`)؛ و`Receiver('receiver_name', 'example.png')` قيمة شكلية (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:440`).
   - `additional_data` يحمل: `business_name`، `business_logo`، `payment_mode`، `customer_id`، `is_guest`، `address_id`، `billing_address_id`، `coupon_code`، `coupon_discount`، `order_note`، `new_customer_info`… (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:320-370`). **لا يوجد فيه ولا في أعمدة الصف أي معرّف متجر/دولة/كيان قانوني/حساب بوابة.**
4. **الذهاب إلى البوابة.** متحكم البوابة يقرأ الصف بـ `payment_id` ويشترط `is_paid = 0` ثم يحوّل العميل (مثال Paymob: `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:86-89`).
5. **العودة.** متحكم البوابة يضع `is_paid = 1` و`transaction_id` ثم ينادي `call_user_func($payment_data->success_hook, …)` أو `failure_hook`، ثم `Processor::payment_response` (نمط متكرر في متحكمات البوابات العامة؛ مثال: `lavkoo:app/Http/Controllers/Payment_Methods/StripePaymentController.php:117-129`).
6. **من ينشئ الطلب ومتى.** في الدفع الإلكتروني **يُنشأ الطلب بعد الدفع** داخل الـ hook `digital_payment_success` (`lavkoo:app/Utils/module-helper.php:19-205`) الذي يعيد بناء سياق العميل/الضيف من `additional_data` ثم ينادي `OrderManager::generateOrder` بحالة `confirmed`/`paid` و`transaction_ref = transaction_id` و`stock_policy = allow_oversell` (`lavkoo:app/Utils/module-helper.php:133-168`). `generateOrder` (`lavkoo:app/Utils/OrderManager.php:1537`) يختم كل طلب ينشئه بـ `transaction_ref` (`lavkoo:app/Utils/OrderManager.php:1074`). الرابط المعتمد بين صف الدفع والطلبات في الدفع الإلكتروني هو `orders.transaction_ref = payment_requests.transaction_id` (`lavkoo:app/Services/Monitoring/Panels/PaymentsPanel.php:98` يعرض العمودين وأطوالهما). أما COD/المحفظة/الدفع غير الإلكتروني فالطلب يُنشأ مباشرة دون `payment_requests` (القسم 2.10).
7. **الـ hooks المعرَّفة** (كلها دوال عامة في `module-helper.php`): `digital_payment_success` / `digital_payment_fail` (الأخير يسجّل حدث تحليلات فقط: `lavkoo:app/Utils/module-helper.php:220-241`)، `add_fund_to_wallet_success` / `add_fund_to_wallet_fail` (`lavkoo:app/Utils/module-helper.php:299-334`)، `customer_order_edit_pay_due_amount_success` / `_failed` (`lavkoo:app/Utils/module-helper.php:247-295`)، ودوال المزاد `auction_*` (من السطر 338؛ الوحدة `Modules/Auction` غير موجودة في المستودع).
8. **إبلاغ النتيجة.** `Processor::payment_response` يحوّل إلى `external_redirect_link` (إن وُجد) أو إلى المسار `payment-success`/`payment-fail` مع `token` = base64 لسلسلة `payment_method=…&&transaction_reference=…` و`order_ids` (`lavkoo:app/Traits/Processor.php:83-100`). المسارات الثلاثة النهائية: `web-payment`، `payment-success`، `payment-fail` (`lavkoo:routes/web/routes.php:466-470`).

ملاحظة نظافة (P2): الـ hook `digital_payment_success` يكتب في كل تنفيذ ملف تشخيص `storage/logs/order_generate_test.txt` يحوي معرّف العميل والعنوان وأرقام الطلبات (`lavkoo:app/Utils/module-helper.php:171-193`) — بقايا تصحيح في مسار مالي.

### 2.3 عقد تطبيق العميل (كيف يبدأ الدفع وكيف يعرف النتيجة)

- **البدء:** التطبيق يرسل `POST /api/v1/digital-payment` (`lavkooapp:lib/utill/app_constants.dart:190`) بالحقول: `order_note`، `customer_id`، `address_id`، `billing_address_id`، `coupon_code`، `coupon_discount`، `payment_platform: "app"`، `payment_method`، `payment_request_from: "app"`، `guest_id`، `is_guest`، `is_check_create_account`، `password` (`lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:170-188`). الخادم يستبدل `customer_id`/`is_guest` بهوية الطلب الموثَّقة (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:117-121`) ويعيد `{redirect_link, new_user}` (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:193-197`). **لا يرسل التطبيق مبلغاً ولا عملة للطلبات**؛ العملة تأتي من إعدادات الخادم أو من `current_currency_code` الاختياري (القسم 2.5).
- **شحن المحفظة:** `POST /api/v1/add-to-fund` (`lavkooapp:lib/utill/app_constants.dart:242`؛ `lavkoo:routes/rest_api/v1/api.php:448-450`) بحقل `amount` من العميل.
- **معرفة النتيجة — البوابات العامة:** التطبيق يفتح الرابط في WebView ويقرأ النتيجة من عنوان URL: يحتوي `success` و`token`، أو `fail`، أو `cancel` (`lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:131-134`، `lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:388-390`).
- **معرفة النتيجة — MyFatoorah:** التطبيق يميّز الرابط (`lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:54`) ويسأل `GET /api/v1/payment/myfatoorah/status?reference=…` (`lavkooapp:lib/utill/app_constants.dart:194`؛ `lavkoo:routes/rest_api/v1/api.php:540-542`) الذي يعيد `status` ∈ {`paid`,`confirming`,`pending`,`failed`,`cancelled`,`expired`} و`is_final` و`order_ids` و`amount` و`currency` (`lavkoo:app/Http/Controllers/RestAPI/v1/MyFatoorahStatusController.php:123-152`).
- **قائمة طرق الدفع للتطبيق:** `/api/v1/config` يعيد `payment_methods` = ناتج `payment_gateways()` بعد حذف الأسرار (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:61-76`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:227`) — قائمة واحدة عامة للنظام كله.

### 2.4 التحقق على مسارات العودة — نقاط القوة

**MyFatoorah (المرجع الذي يُبنى عليه):**

| الضابط | كيف يُنفَّذ | الدليل |
|---|---|---|
| المبلغ من الخادم فقط | `pay` لا يقبل مبلغاً؛ يقرأ من صف `payment_requests` | `lavkoo:app/Http/Controllers/Payment_Methods/MyFatoorahController.php:50-66`، `lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:72-75` |
| الـ redirect ليس دليلاً | الـ callback يحمل مرجعنا + `paymentId` فقط، ثم قراءة خادم-إلى-خادم | `lavkoo:app/Http/Controllers/Payment_Methods/MyFatoorahController.php:88-146` |
| أربعة شروط أساسية للحالة `paid` | حالة الفاتورة PAID + تطابق `ExternalIdentifier` + تطابق العملة + تطابق المبلغ؛ وتُضاف فحوص معرّف الفاتورة وعدم كون المحاولة مهجورة وانجراف المبلغ؛ وإلا `mismatch` | `lavkoo:app/Services/MyFatoorah/PaymentVerificationService.php:164-255`، `lavkoo:app/Services/MyFatoorah/PaymentVerificationService.php:275-306` |
| انجراف مبلغ الطلب | مقارنة لقطة المحاولة مع `payment_requests.payment_amount` الحالي | `lavkoo:app/Services/MyFatoorah/PaymentVerificationService.php:331-348` |
| توقيع الـ webhook | HMAC-SHA256 بترتيب الحقول الموثَّق + `hash_equals`؛ التحقق من التوقيع يسبق معالجة أي حدث (تفاصيل الاستثناءات الإعدادية في الملحق المقيّد: SEC-E10-08) | `lavkoo:app/Services/MyFatoorah/WebhookSignatureVerifier.php:62-91`، `lavkoo:app/Http/Controllers/Api/MyFatoorahWebhookController.php:91-127` |
| الـ webhook لا يُصدَّق بذاته | التوقيع يثبت المصدر فقط؛ كل تسليم يُتبَع بقراءة S2S | `lavkoo:app/Services/MyFatoorah/WebhookProcessor.php:102-147` |
| منع تكرار الـ webhook | `dedupe_key` فريد يُكتب قبل المعالجة | `lavkoo:app/Services/MyFatoorah/WebhookProcessor.php:55-81`، `lavkoo:database/migrations/2026_11_08_100002_create_myfatoorah_webhook_events_table.php:48` |
| التسوية مرة واحدة | قفل صفّي + تحديث مشروط `is_paid = 0` + lease بـ token للـ hook + فحص وجود طلب بنفس `transaction_ref` | `lavkoo:app/Services/MyFatoorah/PaymentSettlementService.php:114-168`، `lavkoo:app/Services/MyFatoorah/PaymentSettlementService.php:287-329`، `lavkoo:app/Services/MyFatoorah/PaymentSettlementService.php:375-399` |
| فاتورة حيّة واحدة لكل طلب دفع | فهرس فريد `(payment_request_id, live_slot)` + `external_identifier` فريد + `payment_id` فريد | `lavkoo:database/migrations/2026_11_08_100007_add_live_slot_to_myfatoorah_attempts.php:41-44`، `lavkoo:database/migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php:47-51` |
| عدم تشغيل failure hook عند الشك | timeout/pending/mismatch → صفحة «pending» لا «fail» | `lavkoo:app/Http/Controllers/Payment_Methods/MyFatoorahController.php:128-145`، `lavkoo:app/Http/Controllers/Payment_Methods/MyFatoorahController.php:160-186` |
| حصر الوجهة | قائمة سماح للـ hosts، بلا إعادة توجيه، بلا إعادة محاولة للـ POST | `lavkoo:app/Services/MyFatoorah/MyFatoorahClient.php:69-75`، `lavkoo:app/Services/MyFatoorah/MyFatoorahClient.php:92-94`، `lavkoo:app/Services/MyFatoorah/MyFatoorahClient.php:155-164` |
| تحديد المعدّل | `throttle:myfatoorah-webhooks` (600/د لكل IP) و`throttle:myfatoorah-status` (90/د) + حد أدنى بين قراءتين لكل محاولة | `lavkoo:app/Providers/RouteServiceProvider.php:238-244`، `lavkoo:app/Http/Controllers/RestAPI/v1/MyFatoorahStatusController.php:108-117` |

**Paymob:** مسار العودة يحسب HMAC-SHA512 على عشرين حقلاً بالمفتاح `hmac` من الإعداد ويشترط `success === "true"` قبل وضع `is_paid = 1` (`lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:233-269`)؛ والمبلغ المُرسَل إلى Paymob يُقرأ من صف `payment_requests` لا من الطلب (`lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:107-110`). تقييم بقية ضوابط هذا المسار في الملحق المقيّد.

**بقية البوابات:** ثلاث بوابات تحمل ضوابط مضافة لاحقاً (مطابقة المبلغ والعملة مع صف الدفع + حارس `is_paid`، وفي Stripe فحص انتماء الجلسة للصف): Stripe (`lavkoo:app/Http/Controllers/Payment_Methods/StripePaymentController.php:108-171`)، RazorPay (`lavkoo:app/Http/Controllers/Payment_Methods/RazorPayController.php:165-200`)، bKash (`lavkoo:app/Http/Controllers/Payment_Methods/BkashPaymentController.php:208-226`). Paytabs يتحقق من توقيع HMAC-SHA256 بـ `hash_equals` ثم يستعلم من البوابة (`lavkoo:app/Http/Controllers/Payment_Methods/PaytabsController.php:54-60`، `lavkoo:app/Http/Controllers/Payment_Methods/PaytabsController.php:158-163`)؛ Paystack وFlutterwave يستعلمان S2S عن المعاملة (`lavkoo:app/Http/Controllers/Payment_Methods/PaystackController.php:100-115`، `lavkoo:app/Http/Controllers/Payment_Methods/FlutterwaveV3Controller.php:115-155`). MTN Cash يستخدم `lockForUpdate` قبل الـ hook (`lavkoo:app/Http/Controllers/Payment_Methods/MtnCashPaymentController.php:899`).

المسائل الأمنية المحدَّدة في هذا المحور:

- SEC-E10-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E10-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E10-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E10-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E10-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E10-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E10-07: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E10-08: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 2.5 العملة والمبلغ المرسَلان إلى البوابات

- **مصدر رمز العملة** (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-423`): إذا كان الإعداد العام `currency_model = multi_currency` تؤخذ العملة الحالية من `current_currency_code` في الطلب أو من `session('currency_code')` ثم تمرّ على `getPaymentGatewayCurrencyCode`؛ وإلا تؤخذ عملة النظام الافتراضية الوحيدة `system_default_currency` من جدول `currencies`.
- **`getPaymentGatewayCurrencyCode`** (`lavkoo:app/Traits/PaymentGatewayTrait.php:476-499`): إن كانت العملة الحالية ضمن خريطة البوابة الثابتة ومفعَّلة تُستخدم؛ وإن كانت الخريطة بعملة واحدة تُفرض؛ وإن كانت بعدة عملات تُختار **أول عملة مفعَّلة من بين عملات الخريطة وفق ترتيب الاستعلام** (وللبوابة `paystack` حالة خاصة تفضّل GHS/NGN)؛ وإلا يعود `'USD'`.
- **التحويل:** في وضع `multi_currency` يُحوَّل المبلغ بسعر الصرف المخزَّن: `usdToAnotherCurrencyConverter` = `amount × (rate_currency / rate_USD)` مقرَّباً إلى الإعداد العام `decimal_point_settings` (افتراضي 2) (`lavkoo:app/Utils/currency.php:207-217`). أي أن المبالغ تُعامَل كأنها مخزَّنة بأساس USD ويُجرى **تحويل صرف آلي لحظة الدفع**. في وضع العملة الواحدة لا تحويل ولا تقريب عند إنشاء `payment_requests`.
- **خريطة Paymob:** `EGP, USD, EUR, GBP, SAR, AED` (`lavkoo:app/Traits/PaymentGatewayTrait.php:117-124`) — لا تحتوي OMR/KWD/BHD/QAR مع أن المتحكم يحوي عنوان Paymob عُمان (`lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:24-30`).
- **خريطة MyFatoorah:** `KWD, SAR` (`lavkoo:app/Traits/PaymentGatewayTrait.php:40-43`)، لكن الخدمة نفسها ترفض أي عملة غير `config('myfatoorah.currency')` = `SAR` افتراضياً (`lavkoo:config/myfatoorah.php:106`، `lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:63-70`).
- **التقريب والوحدات الصغرى:**
  - Paymob: `round(payment_amount * 100)` (`lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:108`) — يفترض عملة بخانتين.
  - MyFatoorah: `round(…, 2)` عند الإنشاء، `number_format(…, 2)` للقطة المحاولة، ومقارنة بالهللات `× 100` (`lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:72`، `lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:276`، `lavkoo:app/Services/MyFatoorah/Money.php:43-50`).
  - Stripe/RazorPay/bKash: `× 100` (`lavkoo:app/Http/Controllers/Payment_Methods/StripePaymentController.php:158`).
  - عمود `payment_requests.payment_amount` رُفع إلى `DECIMAL(21,12)` ثم `DECIMAL(40,20)` (`lavkoo:database/migrations/2026_01_22_164020_update_payment_requests_amount_column.php:15`، `lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:69`)، أما أعمدة MyFatoorah فـ `decimal(24,2)` (`lavkoo:database/migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php:55-60`، `lavkoo:database/migrations/2026_11_08_100003_create_myfatoorah_refunds_table.php:44`).
- **عملات الثلاث خانات (KWD/BHD/OMR):** لا يوجد في نطاق المدفوعات أي جدول أو دالة تعرّف عدد الخانات لكل عملة؛ كل المواضع أعلاه تفترض خانتين أو تعتمد إعداداً عاماً واحداً (بحث: `rg -n "\* 100|round\(|number_format\(" app/Http/Controllers/Payment_Methods app/Services/MyFatoorah`).

### 2.6 تخزين إعدادات البوابات (`addon_settings`)

- النموذج `Setting` يقابل الجدول `addon_settings` بالحقول: `id` (UUID)، `key_name`، `live_values`، `test_values`، `settings_type`، `mode`، `is_active`، `additional_data` (`lavkoo:app/Models/Setting.php:15-23`). **لا يوجد ترحيل ينشئ الجدول في المستودع** (بحث `rg -l "addon_settings" database/` أعاد ترحيلات تعديل/إدراج فقط)؛ البنية الفعلية للأعمدة والفهارس UNKNOWN دون قاعدة البيانات أو ملف SQL الأساسي (غير متتبَّع في المستودع).
- **حساب واحد لكل بوابة على مستوى النظام كله:** الحفظ يتم بـ `updateOrInsert` على المفتاح المركّب `(key_name, settings_type = 'payment_config')` فقط (`lavkoo:app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:181-189`)، والقراءة في كل المتحكمات بـ `where key_name … where settings_type …->first()` (`lavkoo:app/Traits/Processor.php:56-66`؛ MyFatoorah: `lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:96-102`). لا يوجد أي بُعد لمتجر/دولة/كيان قانوني/بائع.
- النموذج العام يكتب **القيم نفسها** في `live_values` و`test_values` معاً (`lavkoo:app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:183-184`)، والمتحكمات تقرأ العمود الموافق لـ `mode` (مثال: `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:35-40`). كما يكتب خطوة ترقية الإصدار 15.1 صف `paymob_accept` بالطريقة نفسها (`lavkoo:app/Traits/UpdateClass.php:550-562`). حقول Paymob: `supported_country`، `public_key`، `secret_key`، `integration_id`، `hmac` (`lavkoo:app/Http/Requests/Admin/PaymentMethodUpdateRequest.php:133-140`).
- **التشفير:** MyFatoorah يخزّن `api_key` و`webhook_secret` بـ `Crypt::encryptString` داخل الـ blob لكل وضع على حدة ولا يعيد عرضهما (`lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:48-49`، `lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:201-207`، `lavkoo:app/Services/MyFatoorah/GatewayConfig.php:125-145`). حالة بقية البوابات: SEC-E10-06 (الملحق المقيّد).
- **التدقيق:** الجدول يستخدم `AuditedBuilder` لتسجيل التغييرات مع حجب القيم (`lavkoo:app/Models/Setting.php:25-32`).
- **مفاتيح التشغيل العامة** في `business_settings`: `cash_on_delivery`، `digital_payment`، `offline_payment` (`lavkoo:app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:94-96`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:159-160`) — قيمة واحدة للنظام كله.
- **قائمة البوابات المعروضة:** `payment_gateways()` = صفوف `payment_config` الفعّالة، مقيَّدةً بالقائمة الافتراضية ما لم يكن `get_payment_publish_status` = 1 (وهو مرتبط بوجود إضافة `Modules/Gateways/Addon` منشورة، وغير موجودة في المستودع)، مع استبعاد MyFatoorah إن لم يكن جاهزاً (`lavkoo:app/Utils/Helpers.php:831-867`، `lavkoo:app/Traits/AddonHelper.php:64-80`).
- **أدوات الجاهزية:** `GatewayReadiness` والأمر `payment:check` يبلّغان عن الحقول الفارغة بالاسم دون طباعة قيم (`lavkoo:app/Services/Payments/GatewayReadiness.php:77-113`، `lavkoo:app/Console/Commands/PaymentGatewayCheck.php:28-30`).

### 2.7 MyFatoorah بالتفصيل

- **مفاتيح التفعيل (ثلاثة مستقلة):** `MYFATOORAH_ENABLED` (افتراضي false)، `is_active` في الإدارة، و`digital_payment` العام؛ ثم وجود `api_key` و`webhook_secret` ووجود العملة ضمن العملات المفعَّلة (`lavkoo:config/myfatoorah.php:33`، `lavkoo:app/Services/MyFatoorah/GatewayConfig.php:90-119`). `MYFATOORAH_VERIFY_WHEN_DISABLED` (افتراضي true) يبقي التحقق يعمل بعد الإيقاف (`lavkoo:config/myfatoorah.php:40`، `lavkoo:app/Services/MyFatoorah/GatewayAvailability.php:69-72`). `MYFATOORAH_SCHEDULE_ENABLED` (افتراضي false) يتحكم بالجدولة (`lavkoo:config/myfatoorah.php:53`).
- **الدولة/المنطقة:** الـ host الإنتاجي الافتراضي `api-sa.myfatoorah.com` والـ sandbox `apitest.myfatoorah.com` (`lavkoo:config/myfatoorah.php:60-63`)؛ قائمة السماح تحوي هذين فقط (`lavkoo:config/myfatoorah.php:69-72`)؛ وعند فشل المطابقة يعود الكود إلى القيمتين نفسيهما مثبتتين (`lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:112-125`). تعليق الإعداد يذكر أن MyFatoorah يعطي كل دولة host إنتاجياً خاصاً وأن الـ token يعمل مع host دولته فقط (`lavkoo:config/myfatoorah.php:55-59`) — ادعاء توثيقي داخل الكود، وقدرة المزوّد الفعلية لكل سوق UNKNOWN.
- **العملة:** عملة واحدة للتكامل كله من `config('myfatoorah.currency')` (`lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:84`) والتعليق ينص: «The store is KSA-only» (`lavkoo:config/myfatoorah.php:102-106`).
- **المحاولات (`myfatoorah_payment_attempts`):** صف لكل محاولة على صف `payment_requests`، بمرجعنا `external_identifier` = `pk-` + أول 32 خانة من SHA-256 لمعرّف طلب الدفع + رقم المحاولة (`lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:444-447`)، مع لقطة `expected_amount/expected_currency`، `mode` مجمَّد لكل محاولة، `platform`، `return_url`، وأعمدة التسوية (`lavkoo:database/migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php:37-110`). الحالات: `created`، `redirected`، `pending`، `paid`، `failed`، `cancelled`، `expired`، `mismatch` (`lavkoo:app/Enums/MyFatoorah/AttemptStatus.php:13-40`). حد المحاولات 10 وصلاحية الصفحة 60 دقيقة (`lavkoo:config/myfatoorah.php:134-141`).
- **جسم إنشاء الدفع:** `Order.Amount/Currency/ExternalIdentifier`، `IntegrationUrls.Redirection` و`Webhook` (يُرسَل عنوان الـ webhook مع كل فاتورة)، `Language`، `PaymentExpiry`، `MetaData.UDF1..3`، و`Customer` (`lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:449-492`). رقم الجوال يُقسَّم بافتراض مفتاح السعودية `966` حصراً (`lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:536-558`).
- **أحداث الـ webhook (`myfatoorah_webhook_events`):** تُسجَّل كل تسليم بـ `payload_digest` دون حفظ الجسم (`lavkoo:app/Services/MyFatoorah/WebhookProcessor.php:55-70`)؛ يُعالَج الحدث 1 (`PAYMENT_STATUS_CHANGED`) فقط، والبقية (2–7 ومنها `REFUND_STATUS_CHANGED`) لا يترتب عليها تغيير في حالة أي محاولة، لأن مخطط التوقيع المنشور في الكود يغطي الحدث 1 فقط (`lavkoo:app/Models/MyFatoorahWebhookEvent.php:24-30`، `lavkoo:app/Services/MyFatoorah/WebhookSignatureVerifier.php:43-51`، `lavkoo:app/Services/MyFatoorah/WebhookProcessor.php:83-86`). المسار `POST /api/myfatoorah/webhook` خارج مجموعة middleware `api` عمداً (`lavkoo:routes/rest_api/myfatoorah_webhook.php:15-21`، `lavkoo:app/Providers/RouteServiceProvider.php:150-155`).
- **اختيار سرّ الـ webhook:** يُجرَّب سرّ الوضع الفعّال ثم سرّ الوضع الآخر (test↔live) للحساب الوحيد (`lavkoo:app/Http/Controllers/Api/MyFatoorahWebhookController.php:93-109`). لا توجد آلية لتمييز أكثر من حساب.
- **الـ reconcile:** الأمر `myfatoorah:reconcile [--limit]` (`lavkoo:app/Console/Commands/MyFatoorahReconcile.php:26-31`) مجدول كل عشر دقائق فقط عند تفعيل المفتاحين (`lavkoo:app/Console/ScheduleDefinition.php:207-208`)؛ يمسح المحاولات `inFlight` خلال 72 ساعة بدفعات 50 ثم ينادي `recover()` للتسويات المنقطعة التي لم يبدأ الـ hook فيها (`lavkoo:app/Services/MyFatoorah/ReconciliationService.php:45-97`، `lavkoo:app/Services/MyFatoorah/PaymentSettlementService.php:407-459`). `needsAttention()` يعدّ: `mismatched`، `stuck`، `hook_unverifiable`، `in_doubt` (`lavkoo:app/Services/MyFatoorah/ReconciliationService.php:121-158`).
- **الاسترداد (`myfatoorah_refunds`):** انظر 2.11.
- **لوحة الإدارة:** مسارات تحت `admin/third-party/payment-method/myfatoorah` محمية بـ `module:3rd_party_setup`: الإعداد، اختبار الاتصال، تحديث الطرق، reconcile، إعادة التحقق، «never-created»، إعادة تنفيذ الـ hook، الاسترداد، تعليم الاسترداد منفَّذاً (`lavkoo:routes/admin/routes.php:1331-1358`).
- **طرق الدفع داخل MyFatoorah (mada/Visa/Apple Pay/STC Pay…):** لا تُعرَّف في الكود؛ تُسأل من الحساب عبر `GET /v3/payment-methods` وتُخزَّن في `additional_data.account_methods`، ويمكن للمشرف تقييد المعروض عبر `enabled_methods` (`lavkoo:app/Services/MyFatoorah/PaymentMethodCatalog.php:32-80`، `lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:487-489`).
- **مفتاح cache عام:** `myfatoorah.active_currencies` لخمس دقائق (`lavkoo:app/Services/MyFatoorah/GatewayAvailability.php:22`، `lavkoo:app/Services/MyFatoorah/GatewayAvailability.php:105`) — غير مقيَّد بسوق.

### 2.8 Paymob بالتفصيل

- **الإعداد:** صف `paymob_accept` الوحيد؛ `supported_country` يختار عنوان القاعدة من خريطة ثابتة: `egypt`، `PAK`، `KSA`، `oman`، `UAE`، والافتراضي عنوان مصر (`lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:24-31`، `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:49-55`). دولة واحدة فعّالة في أي لحظة.
- **البدء:** `POST {base}/v1/intention/` بـ `amount` (وحدات صغرى ×100)، `currency` من صف الدفع، `payment_methods = [integration_id]` (رقم تكامل واحد)، بيانات فوترة أغلبها `"N/A"` مع قيم بديلة مثبتة عند غياب بيانات الدافع، و`redirection_url = route('paymob.callback')`؛ ثم تحويل إلى `unifiedcheckout` (`lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:101-168`).
- **العودة:** مسار واحد `payment/paymob/callback` (`lavkoo:routes/web/routes.php:574-577`) يُستدعى بعودة متصفح العميل من صفحة Paymob (redirect)؛ تفاصيل آلية الربط والتحقق في الملحق المقيّد (SEC-E10-01).
- **ما لا يوجد لـ Paymob** (بحث `rg -n -i paymob app routes config database tests`): لا مسار webhook خادم-إلى-خادم مستقل (المسار مسجَّل داخل مجموعة `web` دون استثناء CSRF، بخلاف Paymera: `lavkoo:routes/web/routes.php:653-654`)، لا جدول محاولات، لا أمر reconcile، لا واجهة استرداد عبر البوابة، ولا أي اختبار (`rg -li paymob tests` = صفر ملفات). النتيجة تعتمد كلياً على عودة متصفح العميل.
- دوال `createOrder` و`getPaymentToken` (واجهة Paymob القديمة) ما زالت في الملف دون استدعاء من `credit` (`lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:172-231`).

### 2.9 `PaymentRoutingService` / `PaymentRoutingRule`

- **الأبعاد التي تقرّر البوابة:** ثلاثة فقط — `gateway_code`، نطاق المبلغ `min_amount`/`max_amount`، و`country` (نص حر يُقارَن بعد `trim` و`mb_strtolower` مع دولة عنوان الشحن)؛ والإجراء `hide` أو `prefer` مع `priority` (`lavkoo:app/Models/PaymentRoutingRule.php:16-25`، `lavkoo:app/Models/PaymentRoutingRule.php:28-41`). **لا يوجد بُعد للعملة، المتجر، الكيان القانوني، البائع، المنصة (web/app) أو حساب البوابة.**
- **السلوك:** الخدمة لا تضيف بوابة غير متاحة أصلاً؛ تخفي أو تقدّم فقط، وتعيد القائمة كما هي إن لم توجد قاعدة أو لم يوجد الجدول (`lavkoo:app/Services/Marketplace/PaymentRoutingService.php:25-59`).
- **نقطة الاستخدام في مسار الدفع:** صفحة الدفع على الويب فقط (`lavkoo:app/Http/Controllers/Web/WebController.php:445`، `lavkoo:app/Http/Controllers/Web/WebController.php:505-510`)، والدولة تؤخذ من `ShippingAddress.country` للعنوان في الجلسة. ويستدعي الخدمة أيضاً متحكم معاينة القواعد في الإدارة (`lavkoo:app/Http/Controllers/Admin/Marketplace/PaymentRoutingController.php:39`). **واجهة التطبيق `/api/v1/config` لا تطبّق التوجيه** (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:61`)، و`PaymentController::payment` لا يتحقق من أن البوابة المطلوبة مسموحة بالقواعد — التوجيه عرضٌ فقط لا إنفاذ.
- **الجدول:** لا يوجد ترحيل ينشئ `payment_routing_rules` في المستودع (بحث `rg -ln "payment_routing_rules"` خارج `vendor/` أعاد: ملفّي الخدمة، `RestoreSection.php`، وملفّي ترجمة فقط). وجود الجدول في الإنتاج UNKNOWN؛ وبغيابه تعيد الخدمة القائمة دون تغيير (`lavkoo:app/Services/Marketplace/PaymentRoutingService.php:28-30`).
- توجد نسخة مطابقة من الملف في مسار لا يوافق الـ namespace: `lavkoo:app/Services/PaymentRoutingService.php:3` (namespace `App\Services\Marketplace`) — ملف مكرَّر خارج PSR-4.
- إدارة القواعد: `admin/…/payment-routing` (`lavkoo:routes/admin/routes.php:918-919`).

### 2.10 المحفظة، COD، الدفع غير الإلكتروني، شحن المحفظة، مستحقات تعديل الطلب

| الطريقة | نقطة الدخول | ما يحدث | الدليل |
|---|---|---|---|
| **COD** (API) | `GET customer/order/place` | `generateOrder` بحالة `pending`/`unpaid` و`payment_method = cash_on_delivery`؛ يسبقها `CheckoutCodGuard` | `lavkoo:routes/rest_api/v1/api.php:324`، `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:190-192`، `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:251-268` |
| **COD** (web) | `getCashOnDeliveryCheckoutComplete` | زر COD يظهر فقط لمنتجات مادية وبموافقة `CheckoutCodGuard` | `lavkoo:app/Http/Controllers/Web/WebController.php:435-436`، `lavkoo:app/Http/Controllers/Web/WebController.php:512` |
| **Mixed COD** | `FULFILLMENT_ALLOW_MIXED_COD` (افتراضي false) | طلب COD موزَّع على أكثر من محصِّل يُحجز (`hold_mixed_cod_blocked`) ما لم يُفعَّل المفتاح **و**يُعلَّم كل مزوّد «COD settlement verified»؛ التقسيم بالهللات | `lavkoo:config/fulfillment.php:107-112`، `lavkoo:docs/integrations/cod.md:6-18`، `lavkoo:app/Services/Fulfillment/CodAllocator.php:21` |
| **المحفظة** (API) | `GET customer/order/place-by-wallet` | يشترط كفاية `users.wallet_balance`، وينشئ الطلب بحالة `confirmed`/`paid`/`pay_by_wallet`، ويقيّد الخصم بـ `create_wallet_transaction(…, 'order_place')` | `lavkoo:routes/rest_api/v1/api.php:390`، `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:444-495` |
| **المحفظة** (web) | `POST checkout-complete-wallet` | المنطق نفسه | `lavkoo:app/Http/Controllers/Web/WebController.php:828-881` |
| **غير إلكتروني** (API) | `POST customer/order/place-by-offline-payment` | `generateOrder` بحالة `pending`/`unpaid`/`offline_payment` مع `offline_payment_info` من حقول الطريقة المعرَّفة في `offline_payment_methods`؛ التأكيد يدوي من الإدارة | `lavkoo:routes/rest_api/v1/api.php:325-326`، `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:384-396`، `lavkoo:app/Models/OfflinePayments.php:31-43` |
| **شحن المحفظة** | `POST /api/v1/add-to-fund` و`customer/customer-add-fund-request` | يشترط `add_funds_to_wallet = 1` وحدَّي `minimum/maximum_add_fund_amount`؛ ينشئ `payment_requests` بـ `attribute = add_funds_to_wallet`؛ الـ hook يحوّل المبلغ إلى USD ثم إلى العملة الافتراضية ويقيّد رصيداً + مكافأة | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:455-555`، `lavkoo:app/Utils/module-helper.php:299-325`، `lavkoo:app/Utils/CustomerManager.php:53-57` |
| **مستحقات تعديل الطلب** | `customer/customer-order-edit-pay-amount` و`/api/v1/edit-order/due-payment-by-*` | محفظة / غير إلكتروني / COD / بوابة (ينشئ `payment_requests` بـ `attribute = customer_order_edit_pay_due_amount`) | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:557-659`، `lavkoo:app/Traits/OrderEditManager.php:739-793`، `lavkoo:routes/rest_api/v1/api.php:461-468` |

حقائق مرتبطة (VERIFIED):

- رصيد المحفظة عمود واحد `users.wallet_balance` والقيد يُحسب بتحويل `Convert::usd` (`lavkoo:app/Utils/CustomerManager.php:39-40`، `lavkoo:app/Utils/CustomerManager.php:70-77`)؛ وخصم الطلب يمرّ على `Convert::default` الذي يقرّب إلى خانتين (`lavkoo:app/Utils/Convert.php:51-63`). التفاصيل في E04.
- `AdminWallet` يُحدَّث بشرط ثابت `admin_id = 1` في مسارَي مستحقات التعديل (`lavkoo:app/Utils/module-helper.php:274`، `lavkoo:app/Traits/OrderEditManager.php:720`).
- مسارات بدء الدفع ومسارات عودة البوابات تقع **خارج** مجموعة middleware `maintenance_mode` (المجموعة تغطي `lavkoo:routes/web/routes.php:162-357` والمسارات المالية تبدأ من `lavkoo:routes/web/routes.php:458`).

### 2.11 الاستردادات والمطابقة

- **مسار الاسترداد العام للمتجر** (طلبات `refund_requests`): عند تغيير الحالة إلى `refunded` يُخصم المبلغ من `inhouse_earning` أو `total_earning` للبائع، يُضاف صف `refund_transactions` ببيان نصّي لطريقة الدفع (`payment_method`/`payment_info` يدخلهما المشرف)، ويُعكس جانب العمولة عبر `RefundReversalService` (`lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:133-155`، `lavkoo:app/Services/RefundTransactionService.php:7-23`). **إعادة المال آلياً تحدث في حالة واحدة فقط ضمن مسار `RefundStatusService`:** إذا كان `wallet_add_refund = 1` والطريقة `customer_wallet` يُقيَّد المبلغ في محفظة العميل (`lavkoo:app/Services/RefundStatusService.php:81-89`). **لا يستدعي هذا المسار أي بوابة دفع**؛ الاسترداد إلى البطاقة إجراء يدوي خارج النظام يُوثَّق نصياً.
- **استرداد MyFatoorah** (مسار مستقل): إجراء مشرف من شاشة MyFatoorah على محاولة `paid` (`lavkoo:app/Http/Controllers/Admin/MyFatoorah/SettingsController.php:206-232`) ← `RefundService::request` يحجز المبلغ تحت قفل صفّي ويقيّد مجموع الاسترداد بالمدفوع ويستخدم مرجعاً فريداً للـ idempotency ثم `POST /v3/refunds` (`lavkoo:app/Services/MyFatoorah/RefundService.php:50-147`، `lavkoo:app/Services/MyFatoorah/RefundService.php:154-222`). الحالات: `requested`/`pending`/`executed`/`rejected`/`failed` (`lavkoo:app/Models/MyFatoorahRefund.php:26-33`)؛ والتحويل إلى `executed` يدوي بعد تأكيد المشرف من بوابة MyFatoorah (`lavkoo:app/Services/MyFatoorah/RefundService.php:261-268`). الجدول يحمل `refund_request_id` و`order_id` اختياريين (`lavkoo:database/migrations/2026_11_08_100003_create_myfatoorah_refunds_table.php:33-34`) لكن **المستدعي الوحيد لا يمرّرهما** (`lavkoo:app/Http/Controllers/Admin/MyFatoorah/SettingsController.php:223-232`؛ بحث `rg -n "RefundService" app` خارج ملف الخدمة أعاد هذا المتحكم فقط؛ و`AttentionRegistry.php` يشير إلى النموذجين لا إلى الخدمة) — أي أن استرداد MyFatoorah غير مربوط بمسار `refund_requests` ولا يعدّل أرصدة البائع/العمولة/المخزون.
- **Paymob وبقية البوابات:** لا يوجد مسار استرداد عبر البوابة. بحث `rg -n -i "refund" app/Http/Controllers/Payment_Methods` أعاد لـ Paymob اسم الحقل `is_refunded` ضمن حقول التوقيع فقط (`lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:249`)، ولـ Paytm دوالاً مساعدة (`initiateTxnRefund`، `callRefundAPI`: `lavkoo:app/Http/Controllers/Payment_Methods/PaytmController.php:229-233`) لا مستدعي لها خارج الملف نفسه (بحث `rg -n "initiateTxnRefund|callRefundAPI" app routes`).
- **أدوات المطابقة:** (1) `myfatoorah:reconcile` وشاشة المراقبة (2.7)؛ (2) `payment:check` لجاهزية الإعداد (2.6)؛ (3) لوحة `PaymentsPanel` للقراءة فقط: تطابق عيّنة من الطلبات مع `payment_requests` و`offline_payments` و`refund_transactions` وتكشف: دفع بلا طلب، طلب مدفوع بلا سجل بوابة، اختلاف مبلغ، التقاط مكرر، استرداد بلا دفع (`lavkoo:app/Services/Monitoring/Panels/PaymentsPanel.php:1234-1240`، `lavkoo:app/Services/Monitoring/Panels/PaymentsPanel.php:1379-1383`، `lavkoo:app/Services/Monitoring/Panels/PaymentsPanel.php:1437-1440`). تعليق اللوحة يذكر أن `payment_requests` «NO INDEX AND NO PRIMARY KEY» (`lavkoo:app/Services/Monitoring/Panels/PaymentsPanel.php:35-42`) — ادعاء في تعليق كود، والبنية الفعلية UNKNOWN دون قاعدة البيانات. لا توجد مطابقة مع كشوف تسوية البوابات (settlement files) ولا مطابقة لكل كيان.

### 2.12 الاختبارات

اختبارات MyFatoorah: 157 دالة `test_` في 7 ملفات (`tests/Feature/MyFatoorah/` أربعة ملفات: 61 + 44 + 21 + 9؛ `tests/Unit/MyFatoorah/` ملفان: 6 + 10؛ و`tests/Feature/Concurrency/MyFatoorahConcurrencyTest.php`: 6) — عُدَّت بـ `rg -c "public function test_"` ولم تُشغَّل. لا توجد اختبارات تنفيذ لأي بوابة أخرى؛ ما يذكر أسماء البوابات الأخرى في `tests/` فحصان فقط: توجيه إلى `payment/sslcommerz/pay` (`lavkoo:tests/Feature/Address/ApiPaymentAddressTest.php:178-191`) وظهور `paytabs` في قائمة البوابات (`lavkoo:tests/Feature/MyFatoorah/CheckoutVisibilityTest.php:57`). ولا يوجد اختبار مباشر للتدفق العام `PaymentController` عدا اختبارات العناوين (`lavkoo:tests/Feature/Address/OrderPaymentCallbackTest.php`).

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الموضع | الحالة |
|---|---|---|---|
| A1 | حساب واحد لكل بوابة للنظام كله (مفتاح `key_name` + `settings_type`) | `lavkoo:app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:181`، `lavkoo:app/Traits/Processor.php:56-66` | VERIFIED |
| A2 | `payment_requests` بلا نسبة لمتجر/كيان/حساب؛ `receiver_id = '100'` ثابت | `lavkoo:app/Traits/Payment.php:22-37`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:432` | VERIFIED |
| A3 | MyFatoorah: عملة واحدة `SAR` من الإعداد | `lavkoo:config/myfatoorah.php:106`، `lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:84` | VERIFIED |
| A4 | MyFatoorah: host الإنتاج `api-sa` وقائمة السماح سعودية + sandbox فقط | `lavkoo:config/myfatoorah.php:60-72`، `lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:124` | VERIFIED |
| A5 | MyFatoorah: `Money` يقارن بالهللات (×100) والتعليق «SAR has two decimal places» | `lavkoo:app/Services/MyFatoorah/Money.php:13-14`، `lavkoo:app/Services/MyFatoorah/Money.php:43-50` | VERIFIED |
| A6 | MyFatoorah: أعمدة المبالغ `decimal(24,2)` والـ casts `decimal:2` | `lavkoo:database/migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php:55-59`، `lavkoo:app/Models/MyFatoorahPaymentAttempt.php:26-27`، `lavkoo:app/Models/MyFatoorahRefund.php:21` | VERIFIED |
| A7 | MyFatoorah: مفتاح الجوال `966` مثبت (`splitSaudiPhone`) | `lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:536-558` | VERIFIED |
| A8 | MyFatoorah: العنوان الافتراضي «MyFatoorah — ماي فاتورة السعودية» | `lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:226`، `lavkoo:database/migrations/2026_11_08_100004_register_myfatoorah_payment_gateway.php:107` | VERIFIED |
| A9 | MyFatoorah: سرّ webhook واحد لكل وضع وعنوان webhook واحد | `lavkoo:app/Http/Controllers/Api/MyFatoorahWebhookController.php:93-109`، `lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:467` | VERIFIED |
| A10 | Paymob: دولة واحدة فعّالة و`integration_id` واحد ووحدات صغرى ×100 | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:49-55`، `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:106-110` | VERIFIED |
| A11 | خرائط العملات المدعومة ثابتة في الكود (Paymob بلا OMR/KWD/BHD/QAR؛ MyFatoorah `KWD, SAR`) | `lavkoo:app/Traits/PaymentGatewayTrait.php:40-43`، `lavkoo:app/Traits/PaymentGatewayTrait.php:117-124` | VERIFIED |
| A12 | عملة الدفع من إعداد عام (`currency_model`، `system_default_currency`) أو من الجلسة مع تحويل صرف آلي | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-423`، `lavkoo:app/Utils/currency.php:207-217` | VERIFIED |
| A13 | مفاتيح `cash_on_delivery`/`digital_payment`/`offline_payment` وإعدادات شحن المحفظة قيم عامة واحدة | `lavkoo:app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:94-96`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:159-160`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:457`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:495-496` | VERIFIED |
| A14 | قواعد التوجيه: `decimal:2` للمبالغ، ودولة كنص حر بلا رمز ISO | `lavkoo:app/Models/PaymentRoutingRule.php:20-25`، `lavkoo:app/Models/PaymentRoutingRule.php:36` | VERIFIED |
| A15 | تقسيم COD بالهللات (×100) | `lavkoo:app/Services/Fulfillment/CodAllocator.php:21`، `lavkoo:docs/integrations/cod.md:17-18` | VERIFIED |
| A16 | `AdminWallet` بـ `admin_id = 1` ثابت | `lavkoo:app/Utils/module-helper.php:274`، `lavkoo:app/Traits/OrderEditManager.php:720` | VERIFIED |
| A17 | مفاتيح البيئة `MYFATOORAH_*` وجدولة reconcile عامة على مستوى النشر | `lavkoo:config/myfatoorah.php:33-53`، `lavkoo:app/Console/ScheduleDefinition.php:207-208` | VERIFIED |
| A18 | مفتاح cache `myfatoorah.active_currencies` عام | `lavkoo:app/Services/MyFatoorah/GatewayAvailability.php:22` | VERIFIED |
| A19 | فحص «هل يوجد طلب لهذا الدفع» وفهرسة `payment_id`/`external_identifier` فريدة عالمياً (بلا بُعد حساب) | `lavkoo:app/Services/MyFatoorah/PaymentSettlementService.php:391`، `lavkoo:database/migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php:47-51` | VERIFIED |
| A20 | بوابة Paymera (SYP وUSD) وبوابة MTN Cash (SYP) ضمن القائمة الافتراضية؛ وصف «بقايا سوق سابق» استنتاج (INFERRED) | `lavkoo:app/Traits/PaymentGatewayTrait.php:24-30`، `lavkoo:app/Enums/GlobalConstant.php:1142-1144` | VERIFIED |

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Foundation: Payment integrations (حفظ Paymob وMyFatoorah، حسابات لكل كيان/متجر) | التكاملان موجودان في الكود ويعملان عبر عقد موحَّد (حالة الإنتاج UNKNOWN) (`payment_requests` + hooks)؛ MyFatoorah يجمّد `mode` لكل محاولة ويقرأ الإعداد عبر مستودع واحد (`GatewayConfigRepository`) ما يسهّل إضافة بُعد الحساب | لا مفهوم «حساب بوابة»؛ صف إعداد واحد لكل بوابة؛ لا نسبة للدفع إلى كيان | `lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:96-102`، `lavkoo:app/Services/MyFatoorah/PaymentVerificationService.php:70`، `lavkoo:app/Traits/Processor.php:56-66` |
| Foundation: separate legal selling company per country (حسابات البوابات، التسويات، المحاسبة) | — | `payment_requests`، `myfatoorah_payment_attempts`، `myfatoorah_refunds`، `refund_transactions` بلا عمود كيان؛ المطابقة عامة | `lavkoo:app/Traits/Payment.php:22-37`، `lavkoo:database/migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php:37-65` |
| Foundation: never assume a provider supports every country/method | MyFatoorah يسأل الحساب عن طرقه بدل افتراضها | خرائط العملات/الدول المدعومة مثبتة في الكود وتُستخدم لاختيار العملة آلياً | `lavkoo:app/Services/MyFatoorah/PaymentMethodCatalog.php:32-46`، `lavkoo:app/Traits/PaymentGatewayTrait.php:476-499`، `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:24-30` |
| Provisional: local pricing, no auto-FX | في وضع العملة الواحدة لا تحويل | في وضع `multi_currency` يُحوَّل المبلغ بسعر صرف لحظة الدفع، ويُختار «أول عملة مفعَّلة» إن لم تدعم البوابة العملة الحالية | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:416-419`، `lavkoo:app/Traits/PaymentGatewayTrait.php:491-498` |
| Provisional: single-store / single-selling-entity checkout | — | طلب الدفع الواحد يجمع كل مجموعات السلة (كل البائعين) بمبلغ واحد وعملة واحدة؛ لا تحقق من وحدة الكيان | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:379-383` |
| Provisional: wallets separate by store/entity/currency | — | شحن المحفظة يمرّ بحساب البوابة العام ويقيَّد في رصيد واحد `users.wallet_balance` بعد تحويل USD↔افتراضي | `lavkoo:app/Utils/module-helper.php:305-307`، `lavkoo:app/Utils/CustomerManager.php:39-40` |
| GCC-12 (إيقاف المتجر دون كسر المدفوعات/الاستردادات/الـ webhooks) | مسارات العودة والـ webhook خارج `maintenance_mode`؛ MyFatoorah يفصل «إيقاف بدء الدفع» عن «استمرار التحقق» (`verify_when_disabled`) | المفاتيح عامة لا لكل متجر؛ إيقاف `digital_payment` أو `is_active` يوقف البوابة لكل الأسواق | `lavkoo:routes/web/routes.php:466-470`، `lavkoo:app/Services/MyFatoorah/GatewayAvailability.php:69-72`، `lavkoo:config/myfatoorah.php:35-40` |
| GCC-13 (هوية واحدة، تدفقات مالية منفصلة لكل متجر/كيان) | `payer_id` موجود على الصف | لا بُعد متجر في الدفع ولا في نقطة حالة MyFatoorah؛ `transaction_ref` رابط نصّي عام | `lavkoo:app/Traits/Payment.php:26`، `lavkoo:app/Http/Controllers/RestAPI/v1/MyFatoorahStatusController.php:155-174` |
| GCC-14 (نطاقات بيانات للموظفين) | شاشة MyFatoorah محمية بـ `module:3rd_party_setup` | لا نطاق كيان على شاشات الاسترداد/المراقبة/إعداد البوابات | `lavkoo:routes/admin/routes.php:1331-1358` |
| GCC-16 (الشحن العابر للحدود) | قواعد التوجيه تقبل `country` الوجهة | الدولة نص حر؛ التوجيه لا يُطبَّق على التطبيق ولا يُنفَّذ عند بدء الدفع | `lavkoo:app/Models/PaymentRoutingRule.php:36`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:61` |
| GCC-19 (السعودية المتجر الافتراضي الأول) | افتراضات MyFatoorah كلها سعودية، وPaymob يدعم دولاً متعددة بإعداد `supported_country` واحد (الافتراضي عنوان مصر)؛ الصفوف والإعدادات الحالية تصلح كحساب SA الافتراضي دون ترحيل بيانات | — | `lavkoo:config/myfatoorah.php:102-106` |
| ملاحظة الثلاث خانات (KWD/BHD/OMR) | `payment_requests.payment_amount` واسع الدقة | كل ما بعده يفترض خانتين (القسم 2.5) | `lavkoo:app/Services/MyFatoorah/Money.php:43-50`، `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:108` |
| Guardrail 1 (لا إعادة كتابة للمدفوعات) | بنية MyFatoorah (محاولات + تحقق S2S + تسوية مرة واحدة) قابلة للتوسعة ببُعد حساب | Paymob بلا اختبارات تثبّت سلوكه قبل أي توسعة | `rg -li paymob tests` = 0 (القسم 2.8) |

### مصفوفة قدرات المزوّدين لكل سوق (Placeholder — تُملأ من المالك مع كل مزوّد)

لا يجوز استنتاج أي خانة من الكود؛ ما في الكود ادعاءات مطوِّر لا تأكيد مزوّد. كل الخانات **UNKNOWN** حتى يؤكدها المالك مع المزوّد لكل كيان قانوني (أهلية التاجر، العملة، طرق الدفع، التسوية، الاسترداد).

| المزوّد | SA / SAR | AE / AED | KW / KWD | QA / QAR | BH / BHD | OM / OMR | ما يقوله الكود (ليس تأكيداً) |
|---|---|---|---|---|---|---|---|
| Paymob | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | عناوين قاعدة لـ `KSA`/`UAE`/`oman`؛ خريطة العملات تحوي SAR/AED فقط من عملات الخليج |
| MyFatoorah | UNKNOWN (الحساب الحالي: حالة الإنتاج UNKNOWN) | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | host سعودي فقط في قائمة السماح؛ خريطة العملات `KWD, SAR`؛ التكامل مقيَّد بـ SAR |
| Tabby | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | لا كود |
| Tamara | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | لا كود |
| COD (عبر مزوّدي الشحن) | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | نطاق E11 |

### ماذا يتطلب فحص Tabby/Tamara بالنظر إلى الموجود

1. **لا شيء منهما موجود**؛ الإضافة تمرّ حتماً بالقوائم الثابتة الخمس (خريطة المسارات، القائمتان الافتراضيتان، خريطة العملات، قائمتا التحقق في الإدارة) — القسم 2.1.
2. **العقد الحالي لا يحمل ما تحتاجه خدمات BNPL عادةً:** صف `payment_requests` يحمل مبلغاً إجمالياً وبيانات دافع (اسم/بريد/جوال) فقط بلا بنود سلة ولا عنوان شحن ولا ضريبة/شحن مفصَّلين (`lavkoo:app/Traits/Payment.php:22-37`)؛ والطلب غير موجود وقت بدء الدفع (يُنشأ بعد الدفع). ما يطلبه كل مزوّد فعلياً UNKNOWN ويؤكَّد من وثائقه.
3. **نموذج الحالة ثنائي** (`is_paid` 0/1) ولا يعبّر عن «مصرَّح/ملتقَط/ملتقَط جزئياً»؛ يلزم جدول محاولات على غرار `myfatoorah_payment_attempts`.
4. **الاسترداد الجزئي والإلغاء** يجب ربطهما بـ `refund_requests` وبتعديل الطلب — وهذا الربط غير موجود حتى لـ MyFatoorah (2.11).
5. **حدود المبلغ/الأهلية:** `PaymentRoutingRule` يوفّر `min_amount`/`max_amount`/`country` لكنه عرضٌ فقط وعلى الويب فقط (2.9).
6. **التطبيق:** يلزم نمط نقطة الحالة (كما في MyFatoorah) لا قراءة النتيجة من عنوان URL (2.3).
7. **القالب المرجعي الجاهز** داخل المستودع هو تكامل MyFatoorah (محاولات، تحقق S2S، webhook موقَّع، تسوية مرة واحدة، reconcile، اختبارات تزامن).

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أقل إضافة موصى بها |
|---|---|---|---|
| خدمات MyFatoorah الخمس عشرة + الجداول الثلاثة + الاختبارات | **Preserve + Extend** | أصلب مسار مالي في النظام؛ ضوابطه في فهارس فريدة واختبارات تزامن | بُعد «حساب» (account) على المحاولة/الاسترداد/حدث الـ webhook، وتحميل `GatewayConfig` حسب الحساب بدل الصف الوحيد؛ الحساب السعودي الحالي يبقى الافتراضي دون تغيير سلوك |
| `config/myfatoorah.php` (host/currency/allowed_hosts) | **Extend** | قيم نشر عامة سعودية | نقل host/currency/allow-list إلى مستوى الحساب مع بقاء القيم الحالية افتراضاً لحساب SA |
| `Money` (MyFatoorah) | **Extend** | صحيح لخانتين فقط | دالة وحدات صغرى تأخذ أُسّ العملة؛ أعمدة مبالغ بدقة كافية لثلاث خانات |
| `PaymobController` | **Preserve (سلوكاً) ثم Extend** | مطلوب حفظه؛ بلا اختبارات | اختبارات توصيف للسلوك الحالي أولاً؛ معالجة SEC-E10-01 قبل أي توسعة؛ ثم بُعد الحساب/الدولة وأُسّ العملة |
| عقد `payment_requests` + hooks (`generate_link`، `payment_response`) | **Preserve + Extend** | تعتمد عليه كل البوابات والتطبيق | أعمدة نسبة اختيارية (storefront / legal entity / gateway account) تُملأ عند الإنشاء؛ لا تغيير في أسماء الحقول الحالية أو شكل الروابط |
| `addon_settings` (صف لكل بوابة) | **Preserve + New بجانبه** | مفتاح عام مستخدَم في كل المتحكمات ولوحة الإدارة | كيان جديد «حساب بوابة» مرتبط بالكيان القانوني/المتجر مع رجوع إلى الصف الحالي كحساب SA؛ عدم إعادة استخدام `key_name` لترميز الدولة |
| `payment_gateways()` + `/api/v1/config.payment_methods` | **Extend** | قائمة واحدة عامة | تصفية حسب سياق المتجر مع بقاء الشكل الحالي للاستجابة |
| `PaymentGatewayTrait` (خرائط العملات) | **Refactor (لاحقاً)** | خرائط ثابتة + رجوع إلى USD/أول عملة | استبدال الاختيار الآلي بعملة المتجر الصريحة؛ الخرائط تبقى للتحقق فقط |
| `PaymentRoutingService` / `PaymentRoutingRule` | **Extend** | عقد «غير كاسر» سليم | أبعاد storefront/currency ورمز دولة ISO؛ تطبيقه على API وإنفاذه عند بدء الدفع؛ ترحيل الجدول (غير موجود في المستودع) |
| `RefundService` (MyFatoorah) | **Preserve + Extend** | idempotent ومقيَّد بسقف المدفوع | ربطه بـ `refund_requests`/`order_id` وبقيود البائع/العمولة |
| مسار `refund_requests` → المحفظة | **Preserve** | سلوك قائم | (نطاق E04) تحديد المحفظة حسب الكيان |
| `GatewayReadiness` / `payment:check` / `PaymentsPanel` | **Preserve + Extend** | أدوات قراءة آمنة | تجميع حسب الحساب/الكيان |
| COD guard / `FULFILLMENT_ALLOW_MIXED_COD` / `CodAllocator` | **Preserve** | قاعدة أمان مالية | أُسّ العملة في التقسيم (E11) |
| البوابات الموروثة غير الخليجية (bKash، SSLCommerz، Paytm، Paymera، MTN Cash…) والـ 23 stub | **Isolate** | ضوضاء في القائمة وسطح صيانة إضافي؛ ضوابطها غير متماثلة (SEC-E10-03) | عدم إدراجها في أي حساب GCC؛ قرار المالك بتعطيلها |
| الملفات المكرَّرة (`Payment.txt`، `Payment/PaymeraController.php`، `NewPaystackController.php`، `Services/PaymentRoutingService.php`، `Admin/PaymentMethodController.php`) | **Refactor (نظافة، مؤجَّل)** | غير موصولة | توثيقها فقط الآن |
| Tabby / Tamara | **New** | غير موجودين | وفق قالب MyFatoorah بعد تأكيد القدرات |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E10-F01 | إعداد البوابة صف واحد عام لكل `key_name`؛ لا مفهوم «حساب بوابة» مرتبط بكيان قانوني/متجر | P0 | VERIFIED | `lavkoo:app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:181-189`، `lavkoo:app/Traits/Processor.php:56-66`، `lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:96-102` | يستحيل تحصيل أموال كل دولة في حساب كيانها؛ خلط تسويات | كيان «حساب بوابة» جديد بجانب الصف الحالي (الحالي = حساب SA) |
| E10-F02 | `payment_requests` وجداول MyFatoorah بلا نسبة لمتجر/كيان/حساب؛ `receiver_id` ثابت و`attribute_id` طابع زمني؛ الربط بالطلبات نصّي عبر `transaction_ref` | P0 | VERIFIED | `lavkoo:app/Traits/Payment.php:22-37`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:432-437`، `lavkoo:database/migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php:37-65` | لا يمكن توجيه webhook/تحقق/استرداد/مطابقة إلى الحساب الصحيح ولا إعداد تقارير لكل كيان | أعمدة نسبة اختيارية تُملأ عند الإنشاء؛ الصفوف التاريخية تُنسب إلى SA |
| E10-F03 | MyFatoorah أحادي الدولة بنيوياً: عملة واحدة، host/allow-list سعودي، سرّ وعنوان webhook واحد، مفتاح جوال 966 | P0 | VERIFIED | `lavkoo:config/myfatoorah.php:60-72`، `lavkoo:config/myfatoorah.php:106`، `lavkoo:app/Http/Controllers/Api/MyFatoorahWebhookController.php:93-109`، `lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:536-558` | حساب ثانٍ (دولة أخرى) غير ممكن دون توسعة؛ webhook حساب آخر يُرفض توقيعه | إعداد لكل حساب + تثبيت الحساب على المحاولة + اختيار السرّ من حساب المحاولة |
| E10-F04 | افتراض الخانتين العشريتين في كل مسار المبالغ بعد `payment_requests` (Paymob ×100، `Money` ×100، `decimal(24,2)`، `round(…,2)`، `Convert::default`، قواعد التوجيه، تقسيم COD) | P0 | VERIFIED | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:108`، `lavkoo:app/Services/MyFatoorah/Money.php:43-50`، `lavkoo:database/migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php:55`، `lavkoo:app/Utils/Convert.php:62`، `lavkoo:app/Services/Fulfillment/CodAllocator.php:21` | مع KWD/BHD/OMR: مبالغ خاطئة للبوابة، بتر للفلس الثالث، حالات `mismatch` لمدفوعات صحيحة أو فروق لا تُرصد | جدول أُسّ لكل عملة ودوال وحدات صغرى مُعلَّمة بالعملة؛ توسيع دقة الأعمدة |
| E10-F05 | عملة الدفع تُشتق من إعداد عام أو من الجلسة مع تحويل صرف آلي ورجوع إلى «أول عملة مفعَّلة»/USD | P0 | VERIFIED (الكود) / UNKNOWN (قيمة `currency_model` الإنتاجية) | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-423`، `lavkoo:app/Traits/PaymentGatewayTrait.php:476-499`، `lavkoo:app/Utils/currency.php:207-217` | يتعارض مع «تسعير محلي بلا FX آلي»؛ قد يُحصَّل بعملة غير عملة المتجر | عملة المتجر مصدراً وحيداً؛ رفض الدفع إن لم يدعمها الحساب بدل التحويل |
| E10-F06 | Paymob: النتيجة تعتمد على عودة متصفح العميل فقط؛ لا webhook خادم-إلى-خادم، لا محاولات، لا reconcile، لا استرداد، لا اختبارات | P1 | VERIFIED | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:146`، `lavkoo:routes/web/routes.php:573-577` | دفع ملتقَط بلا طلب عند انقطاع العودة؛ لا شبكة أمان؛ «حفظ السلوك» بلا اختبارات تثبّته | اختبارات توصيف، ثم مسار إشعار خادم + محاولات + reconcile على قالب MyFatoorah |
| E10-F07 | طلب الدفع الواحد يجمع كل البائعين/مجموعات السلة؛ لا تحقق من وحدة المتجر/الكيان | P1 | VERIFIED | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:379-383` | يتعارض مع checkout لكيان واحد إن لم تُفصل السلال | فرض وحدة المتجر عند إنشاء `payment_requests` (بالتنسيق مع ملحق السلة/الطلبات) |
| E10-F08 | الطلب يُنشأ بعد الدفع من داخل الـ hook؛ فشل الـ hook يترك مالاً بلا طلب (MyFatoorah يكشفه ويعزله؛ لا توجد آلية مماثلة في المتحكمات الأخرى المفحوصة) | P1 | VERIFIED | `lavkoo:app/Utils/module-helper.php:87-94`، `lavkoo:app/Utils/module-helper.php:133-168`، `lavkoo:app/Services/MyFatoorah/PaymentSettlementService.php:242-272` | مخاطر مالية/تشغيلية تتضاعف مع تعدد الأسواق | تعميم نمط «تسوية مرة واحدة + استعادة» على البوابات المحفوظة |
| E10-F09 | التوجيه (`PaymentRoutingRule`) عرضٌ فقط على الويب؛ لا يُطبَّق على التطبيق ولا يُنفَّذ عند بدء الدفع؛ الأبعاد مبلغ/دولة نصية فقط؛ ترحيل الجدول غير موجود في المستودع | P1 | VERIFIED | `lavkoo:app/Http/Controllers/Web/WebController.php:505-510`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:61`، `lavkoo:app/Models/PaymentRoutingRule.php:28-41`، `lavkoo:app/Services/Marketplace/PaymentRoutingService.php:28-30` | لا يصلح أساساً لتقييد البوابات لكل سوق | أبعاد storefront/currency/ISO + إنفاذ خادمي + ترحيل |
| E10-F10 | الاسترداد عبر البوابة غير مربوط بمسار `refund_requests`: مسار المتجر يوثّق نصياً أو يقيّد في المحفظة فقط؛ استرداد MyFatoorah مستقل ولا يمرّر `refund_request_id`/`order_id`؛ لا استرداد لـ Paymob | P1 | VERIFIED | `lavkoo:app/Services/RefundStatusService.php:81-89`، `lavkoo:app/Http/Controllers/Admin/MyFatoorah/SettingsController.php:223-232`، `lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:133-155` | ازدواج/نقص قيود عند الاسترداد؛ مطابقة يدوية لكل كيان | ربط واحد لقرار الاسترداد بالقناة المالية وبالكيان |
| E10-F11 | مفاتيح التشغيل عامة (`digital_payment`، `cash_on_delivery`، `offline_payment`، `is_active`، `MYFATOORAH_ENABLED`، حدود شحن المحفظة) | P1 | VERIFIED | `lavkoo:app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:94-96`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:159-160`، `lavkoo:config/myfatoorah.php:33`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:457` | لا يمكن إيقاف/تفعيل طريقة دفع لسوق واحد (GCC-12) | مفاتيح على مستوى المتجر/الحساب مع بقاء العام مفتاحَ طوارئ |
| E10-F12 | شحن المحفظة يمرّ بالحساب العام ويقيَّد في رصيد واحد بعد تحويل عملة | P1 | VERIFIED | `lavkoo:app/Utils/module-helper.php:305-307`، `lavkoo:app/Utils/CustomerManager.php:70-77` | يتعارض مع محافظ منفصلة لكل كيان/عملة | (مع E04) ربط الشحن بحساب ومحفظة المتجر |
| E10-F13 | أربع/خمس قوائم بوابات ثابتة يجب تعديلها معاً لإضافة أي بوابة؛ 23 stub وبوابات موروثة غير خليجية في القائمة الافتراضية | P2 | VERIFIED | `lavkoo:app/Traits/Payment.php:39-82`، `lavkoo:app/Utils/Helpers.php:273-285`، `lavkoo:app/Enums/GlobalConstant.php:1129-1149`، `lavkoo:app/Http/Requests/Admin/PaymentMethodUpdateRequest.php:30` | خطر عدم اتساق عند إضافة Tabby/Tamara | سجل بوابات واحد (لاحقاً) |
| E10-F14 | ملف تشخيص يُكتب في كل نجاح دفع؛ ملفات مكرَّرة غير موصولة؛ نسخة خدمة خارج PSR-4 | P2 | VERIFIED | `lavkoo:app/Utils/module-helper.php:171-193`، `lavkoo:app/Services/PaymentRoutingService.php:3`، `lavkoo:app/Traits/Payment.txt:1` | نظافة | تنظيف في مرحلة تنفيذ معتمدة |
| E10-F15 | مسارات البوابات كلها (ومنها MyFatoorah) مشروطة بعدم نشر إضافة `Modules/Gateways` | P2 | INFERRED (من الشرط في ملف المسارات؛ الإضافة غير موجودة) | `lavkoo:routes/web/routes.php:472-482` | نشر الإضافة لاحقاً يزيل مسارات العودة | توثيق/حماية الشرط |
| E10-F16 | احتمال أن يتعدى طول المرجع الاحتياطي `pk-…` (≥ 37 خانة) عرض `orders.transaction_ref` (30 حسب تعليق اللوحة) عند استخدامه كمعرّف معاملة | P2 | INFERRED (العرض مأخوذ من تعليق كود لا من بنية مؤكدة) | `lavkoo:app/Services/Monitoring/Panels/PaymentsPanel.php:98`، `lavkoo:app/Services/MyFatoorah/PaymentSettlementService.php:532-541`، `lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:444-447` | قد يُضعف فحص «الطلب موجود مسبقاً» في الحالة الاحتياطية النادرة | التحقق من عرض العمود في قاعدة البيانات |
| E10-F17 | Tabby وTamara غير موجودين إطلاقاً | INFO | VERIFIED (بحث مسجَّل) | القسم 1 | يُبنيان من الصفر | قالب MyFatoorah بعد تأكيد القدرات |
| E10-F18 | تكامل MyFatoorah وُلد في هذا المستودع في 2026-11-08؛ حالة تفعيله إنتاجياً مجهولة (الصف يُشحن مطفأً، ومفتاحا `MYFATOORAH_ENABLED` و`MYFATOORAH_SCHEDULE_ENABLED` مطفآن افتراضياً) | INFO | VERIFIED (الكود) / UNKNOWN (الإنتاج) | `lavkoo:config/myfatoorah.php:33`، `lavkoo:database/migrations/2026_11_08_100004_register_myfatoorah_payment_gateway.php:56-66` | «حفظ MyFatoorah» قد يعني حفظ كود لم يعمل حياً بعد | تأكيد المالك لحالة الإنتاج |
| E10-F19 | مسارات بدء/عودة الدفع والـ webhook خارج `maintenance_mode`، ومسارات العودة لا تحمل سياق متجر | INFO | VERIFIED | `lavkoo:routes/web/routes.php:458-470`، `lavkoo:routes/rest_api/myfatoorah_webhook.php:19-21` | مع مسارات `/sa…/om` يجب إبقاء عناوين العودة الحالية ثابتة (مسجَّلة لدى المزوّدين ومضمَّنة في فواتير قائمة) | عدم نقلها تحت بادئة المتجر؛ اشتقاق السياق من صف الدفع |
| SEC-E10-01 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P0 | VERIFIED | الملحق المقيّد | — | الملحق المقيّد |
| SEC-E10-02 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P0 | VERIFIED | الملحق المقيّد | — | الملحق المقيّد |
| SEC-E10-03 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P1 | VERIFIED | الملحق المقيّد | — | الملحق المقيّد |
| SEC-E10-04 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P1 | VERIFIED | الملحق المقيّد | — | الملحق المقيّد |
| SEC-E10-05 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P1 | VERIFIED | الملحق المقيّد | — | الملحق المقيّد |
| SEC-E10-06 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P2 | VERIFIED | الملحق المقيّد | — | الملحق المقيّد |
| SEC-E10-07 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P2 | VERIFIED / INFERRED | الملحق المقيّد | — | الملحق المقيّد |
| SEC-E10-08 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P2 | VERIFIED (كود) | الملحق المقيّد | — | الملحق المقيّد |

## 7. أسئلة مفتوحة وعناصر UNKNOWN

| # | السؤال | ما يحسمه |
|---|---|---|
| U1 | أي البوابات مفعَّلة فعلياً في الإنتاج (`is_active`، `mode`)، وهل Paymob و/أو MyFatoorah يعملان حياً، وبأي `supported_country` لـ Paymob؟ | قراءة `addon_settings` الإنتاجية (أسماء الحقول والحالة فقط) أو ناتج `payment:check` من المالك |
| U2 | قيم `currency_model`، `system_default_currency`، `decimal_point_settings`، والعملات المفعَّلة | `business_settings` و`currencies` الإنتاجيتان |
| U3 | قيم `MYFATOORAH_ENABLED`، `MYFATOORAH_SCHEDULE_ENABLED`، `MYFATOORAH_CURRENCY`، `FULFILLMENT_ALLOW_MIXED_COD` في الإنتاج | `.env` الإنتاجي (أسماء وقيم غير سرية) |
| U4 | بنية `payment_requests` و`addon_settings` الفعلية (مفاتيح أساسية، فهارس، عرض `transaction_id`) ووجود جدولَي `payment_routing_rules` و`mtn_cash_*` | مخطط قاعدة البيانات الإنتاجية أو ملف SQL الأساسي (غير متتبَّع) |
| U5 | قدرة كل مزوّد (Paymob، MyFatoorah، Tabby، Tamara) لكل سوق/عملة/كيان، وهل يلزم حساب/token/host/سرّ webhook مستقل لكل دولة | تأكيد المالك مع كل مزوّد (مصفوفة القسم 4) |
| U6 | هل يدعم حساب MyFatoorah مسارَي البحث عن الفاتورة (`invoice_details`، `invoice_by_reference`) وطريقة توقيع أحداث الاسترداد | تجربة sandbox موثَّقة من المالك (`lavkoo:config/myfatoorah.php:83-97`) |
| U7 | علاقة تكامل MyFatoorah الحالي بـ PR #10 في المستودع القديم وبـ PR #1 في تطبيق العميل القديم | الوصول إلى `pharmacyksa98` (missing access) — يُحال إلى ملحق السلالة |
| U8 | هل نُفِّذت الترحيلات السبعة لـ MyFatoorah في الإنتاج | جدول `migrations` الإنتاجي |
| U9 | هل توجد عمليات استرداد فعلية عبر البوابات تُنفَّذ يدوياً من لوحات المزوّدين، وكيف تُطابَق محاسبياً | إفادة المالك/المالية |
| U10 | هل إضافة `Modules/Gateways` منشورة في الإنتاج (تغيّر تسجيل المسارات والقائمة) | نظام ملفات الإنتاج |

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| خريطة مسارات البوابات (40 مفتاحاً) | `lavkoo:app/Traits/Payment.php:39-82` | VERIFIED |
| إنشاء صف `payment_requests` وحقوله | `lavkoo:app/Traits/Payment.php:22-37` | VERIFIED |
| القائمة الافتراضية للبوابات (16) | `lavkoo:app/Utils/Helpers.php:273-285`، `lavkoo:app/Enums/GlobalConstant.php:1129-1149` | VERIFIED |
| `payment_gateways()` واستبعاد MyFatoorah غير الجاهز | `lavkoo:app/Utils/Helpers.php:831-867` | VERIFIED |
| Tabby/Tamara غير موجودين | بحث `rg -il "tabby\|tamara"` (القسم 1) | VERIFIED |
| مسارات Paymob | `lavkoo:routes/web/routes.php:573-577` | VERIFIED |
| مسارات MyFatoorah والاسم البديل | `lavkoo:routes/web/routes.php:599-612` | VERIFIED |
| مسار webhook MyFatoorah | `lavkoo:routes/rest_api/myfatoorah_webhook.php:19-21` | VERIFIED |
| مصدر العملة والتحويل عند الدفع | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-423` | VERIFIED |
| اختيار عملة البوابة والرجوع إلى USD | `lavkoo:app/Traits/PaymentGatewayTrait.php:476-499` | VERIFIED |
| تحويل الصرف والتقريب | `lavkoo:app/Utils/currency.php:207-217` | VERIFIED |
| المبلغ = مجموع كل مجموعات السلة | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:379-383` | VERIFIED |
| `receiver_id='100'`، `attribute_id=idate("U")` | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:432-437` | VERIFIED |
| الطلب يُنشأ بعد الدفع داخل الـ hook | `lavkoo:app/Utils/module-helper.php:133-168` | VERIFIED |
| `payment_response` وشكل الـ token | `lavkoo:app/Traits/Processor.php:83-100` | VERIFIED |
| قراءة إعداد البوابة بالمفتاح العام | `lavkoo:app/Traits/Processor.php:56-66` | VERIFIED |
| حفظ الإعداد بـ `updateOrInsert` والقيم نفسها في العمودين | `lavkoo:app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php:181-189` | VERIFIED |
| تشفير أسرار MyFatoorah | `lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:201-207` | VERIFIED |
| Paymob: دول/عناوين ثابتة | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:24-31` | VERIFIED |
| Paymob: المبلغ ×100 والعملة من الصف | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:107-110` | VERIFIED |
| Paymob: HMAC-SHA512 في العودة | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:233-269` | VERIFIED |
| MyFatoorah: عملة/host/allow-list | `lavkoo:config/myfatoorah.php:60-72`، `lavkoo:config/myfatoorah.php:106` | VERIFIED |
| MyFatoorah: رفض عملة مخالفة | `lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:63-70` | VERIFIED |
| MyFatoorah: شروط `paid` الأساسية وفحوصها الإضافية | `lavkoo:app/Services/MyFatoorah/PaymentVerificationService.php:164-255` | VERIFIED |
| MyFatoorah: تسوية مرة واحدة | `lavkoo:app/Services/MyFatoorah/PaymentSettlementService.php:114-168` | VERIFIED |
| MyFatoorah: توقيع webhook | `lavkoo:app/Services/MyFatoorah/WebhookSignatureVerifier.php:62-91` | VERIFIED |
| MyFatoorah: تجربة سرّ الوضع الآخر | `lavkoo:app/Http/Controllers/Api/MyFatoorahWebhookController.php:93-109` | VERIFIED |
| MyFatoorah: dedupe للأحداث | `lavkoo:app/Services/MyFatoorah/WebhookProcessor.php:55-81` | VERIFIED |
| MyFatoorah: reconcile وجدولته | `lavkoo:app/Services/MyFatoorah/ReconciliationService.php:45-97`، `lavkoo:app/Console/ScheduleDefinition.php:207-208` | VERIFIED |
| MyFatoorah: استرداد idempotent ومقيَّد بالمدفوع | `lavkoo:app/Services/MyFatoorah/RefundService.php:154-222` | VERIFIED |
| MyFatoorah: `Money` بالهللات | `lavkoo:app/Services/MyFatoorah/Money.php:43-50` | VERIFIED |
| MyFatoorah: جوال 966 | `lavkoo:app/Services/MyFatoorah/PaymentInitiationService.php:536-558` | VERIFIED |
| نقطة حالة MyFatoorah للتطبيق | `lavkoo:app/Http/Controllers/RestAPI/v1/MyFatoorahStatusController.php:47-100` | VERIFIED |
| أبعاد قاعدة التوجيه | `lavkoo:app/Models/PaymentRoutingRule.php:28-41` | VERIFIED |
| التوجيه يُستخدم في مسار الدفع على الويب فقط (ومعاينة الإدارة) | `lavkoo:app/Http/Controllers/Web/WebController.php:445`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:61` | VERIFIED |
| COD: guard وmixed COD | `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:190-192`، `lavkoo:config/fulfillment.php:107-112` | VERIFIED |
| الدفع بالمحفظة (API) | `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:444-495` | VERIFIED |
| شحن المحفظة: hook والتحويل | `lavkoo:app/Utils/module-helper.php:299-325` | VERIFIED |
| استرداد المتجر → المحفظة فقط | `lavkoo:app/Services/RefundStatusService.php:81-89` | VERIFIED |
| استرداد MyFatoorah من شاشة الإدارة دون ربط بـ refund request | `lavkoo:app/Http/Controllers/Admin/MyFatoorah/SettingsController.php:223-232` | VERIFIED |
| عقد التطبيق: بدء الدفع | `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:170-188` | VERIFIED |
| عقد التطبيق: قراءة النتيجة من URL | `lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:131-134` | VERIFIED |
| عقد التطبيق: نقطة حالة MyFatoorah | `lavkooapp:lib/utill/app_constants.dart:194` | VERIFIED |
| حالة الإنتاج لكل ما سبق | — | UNKNOWN (القسم 7) |

## 9. سجل التحقق المستقل

> التحقق المستقل (قراءة ثابتة فقط، دون تشغيل أي كود أو اختبار): أُعيد فحص الادعاءات الجوهرية مقابل الكود المقتبس، وأُعيد البحث عن كل ادعاء غياب بأنماط مختلفة، ومُنعت الحالات المنقوضة من الاستمرار في النص. الأعداد عدٌّ تقريبي لمستوى الادعاء الواحد.

| البند | العدد |
|---|---|
| الادعاءات المفحوصة | 120 |
| المؤكَّدة دون تعديل | 94 |
| المصحَّحة (نطاق أو صياغة أو وسم حالة) | 19 |
| المنقوضة وأُزيلت أو أُعيد بناؤها | 5 |
| غير القابلة للتحقق من الكود (قيم إنتاجية أو عدّ عام) | 2 |

**الادعاءات المنقوضة (أُزيلت أو استُبدلت):**

1. «لا يوجد اختبار لأي بوابة أخرى» — يوجد فحصان في `tests/` يذكران بوابتين (توجيه `sslcommerz` وظهور `paytabs` في القائمة)، ولا يوجد فحص تنفيذ لأيٍّ منهما. (§2.12)
2. «كل الافتراضات الحالية سعودية» — افتراضات MyFatoorah سعودية، أما Paymob فيدعم دولاً متعددة عبر `supported_country`. (§4، GCC-19)
3. «`payment_gateways()` = الصفوف الفعّالة ضمن القائمة الافتراضية» — القيد يُتجاوَز عند `get_payment_publish_status` = 1. (§2.6)
4. «كل المفاتيح تُشحن مطفأة» — `verify_when_disabled` مفعّل افتراضياً، والمطفأ هو مفتاحا التشغيل الرئيسيان والصف. (§6، E10-F18)
5. عدد أسطر `Payment_Methods/` «6570 سطراً» — القيمة المحسوبة 5168 سطراً. (§1)

**الادعاءات المصحَّحة (نطاق أو صياغة أو وسم حالة):** إعدادات `config/` غير الفارغة للوضع والبيئة (§1)؛ نطاق بحث `mada` وأسماء `Apple Pay` و`STC Pay` و`KNET` بالمسافة (§1)؛ إضافة بحث `bnpl`/`installment` (§1)؛ «الرابط الوحيد» بين الدفع والطلبات صار «الرابط المعتمد» (§2.2)؛ ترتيب اختيار العملة في الاستعلام وحالة `paystack` (§2.5)؛ أربعة شروط `paid` أساسية مع فحوص إضافية (§2.4)؛ ضوابط Stripe وفحص انتماء الجلسة (§2.4)؛ تعليق `publish status` وتحديث الإصدار 15.1 (§2.6)؛ أحداث الـ webhook 2–7 بلا أثر لا «مرفوضة» (§2.7)؛ صياغة الاسترداد والتقييد بالمدفوع (§2.7)؛ «نقطة الاستخدام» للتوجيه ومعاينة الإدارة (§2.9)؛ نطاق الاسترداد الآلي ضمن `RefundStatusService` (§2.11)؛ Paymera بعملتي SYP وUSD ووسم «بقايا سوق سابق» استنتاجاً (§3، A20)؛ حالة التكاملين «يعملان» صارت «موجودان في الكود» مع إنتاج UNKNOWN (§4)؛ وسم حالة E10-F05 للإنتاج (§6)؛ نطاق E10-F08 على المتحكمات المفحوصة (§6)؛ صياغة E10-F04 وE10-F16 وE10-F18 وصف «Isolate» (§5، §6).

**الإزالات والنقل إلى الملحق المقيّد:** نُقلت إلى الملحق صيغة أن التوقيع في وضع الاختبار قد لا يُشترط عند إطفاء إعداد بعينه، وأُبدل نصها بإشارة إلى البند SEC-E10-08 (§2.4 وجدول §6). أُزيل من النص الرئيسي وصف قيمة مرجع Paymob المولَّدة زمنياً؛ وهو مدرج أصلاً ضمن SEC-E10-01 في الملحق. أُعيدت صياغة العبارات الوصفية المتعلقة بحدود المبالغ وإعادة تنفيذ الخطافات وطبيعة المخاطر في الجداول لتصبح محايدة.

**عدد البنود المنقولة إلى الملحق المقيّد:** 8 (SEC-E10-01 إلى SEC-E10-08)؛ منها بند جديد في هذا التحقق هو SEC-E10-08، والبنود الأخرى محفوظة من المسودة السابقة. ولكل بند مؤشر محايد واحد في النص الرئيسي.

**نتائج الأدوات:** `check_citations.py` على هذا الملف: 0 عيوب. `scan_sensitive.py`: 0 إصابات.

**مخاوف متبقية (أسماء الأقسام):**
- §2.4 (ضوابط Stripe وRazorPay وbKash وPaytabs): قُرئت مقاطعها ولم تُختبر.
- §2.7 (الاتصال والتحقق من الاستجابة): لم تُقرأ `ConnectionTester.php` ولا `MyFatoorahResponse.php`.
- §2.9 (قواعد التوجيه): لم يُقرأ متحكم إدارة القواعد كاملاً.
- §2.11 (لوحة المراقبة): لم تُفحص فروع «طلب مدفوع بلا سجل بوابة» و«استرداد بلا دفع» مفرداً.
- الملحق المقيّد، SEC-E10-03: مصدر `payment_id` في MercadoPago يحتاج قراءة سطرية إضافية.
- §7 (U1–U10): لم تتغير؛ لا يمكن حسمها من الكود.

**فجوات التغطية:**
- `OrderEditController` في الـ API (`routes/rest_api/v1/api.php:461-468`) لم يُقرأ.
- `OrderManager::generateOrder` بعد السطر 1560 خارج هذا الملف (نطاق E09/E04).
- مسارات SSLCommerz وLiqPay وPaytm وPaymera وMTN Cash لم تُفحص تفصيلياً؛ وُثّق منها غياب استدعاء دالتي استرداد Paytm فقط.
- شاشات إدارة MyFatoorah (`SettingsController`) قُرئ جزء منها.
- `Modules/doc.txt` لم يُقرأ.
- عدد الملفات المفحوصة «نحو 78» تقدير ولم يُعدّ.

**أضيف من التحقق:** مسار ترقية الإصدار 15.1 يكتب صف `paymob_accept` (§2.6)؛ بحث `legal|account|store_id` في ملفات البوابات أعاد مفاتيح `store_id` الخاصة بمزوّد SSLCommerz (`lavkoo:config/sslcommerz.php:9`)، وهي معرّفات مزوّد وليست مفهوماً للنظام يدعم E10-F01؛ وبحث `\bmada\b` و`installment` ووسوم الغياب الأخرى مسجَّل في §1.
