# E18 — تطبيق العميل Flutter: السلة والدفع والطلبات والمحفظة والولاء والعناوين (Customer App Commerce Flows)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkooapp@eedbbb8fd0303aa9fed5d4ff0ac45b3a409abf7a` (المصدر الأساسي)، `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` (للتحقق المتقاطع من العقود فقط).

## 1. النطاق وما تم فحصه

**ما تمت تغطيته في `lavkooapp`:**

| المجال | المسار | عدد ملفات Dart | الأسطر | مستوى القراءة |
|---|---|---|---|---|
| السلة | `lib/features/cart` | 12 | 3810 | كامل للـ controller/repository/model، وقراءة منطقية لـ `cart_screen.dart` |
| إتمام الطلب والدفع | `lib/features/checkout` | 26 | 7830 | كامل للـ controller/repository/شاشة الدفع الرقمي/خدمة MyFatoorah/`checkout_screen.dart` |
| الطلبات | `lib/features/order`, `order_details`, `tracking`, `refund`, `reorder` | 61 | 12893 | repositories ونماذج كاملة، وفحص موجَّه للواجهات |
| المحفظة والولاء | `lib/features/wallet`, `loyaltyPoint`, `refer_and_earn`, `profile` | 38 | 5981 | repositories/controllers/نماذج، وفحص موجَّه للواجهات |
| العناوين والشحن والقسائم | `lib/features/address`, `shipping`, `coupon`, `offline_payment` | 39 | 4520 | كامل للنماذج والـ repositories ونموذج إدخال العنوان |
| الكتالوج والمتاجر والمحادثة | `product`, `product_details`, `shop`, `chat`, `support`, `search_product`, `category`, `brand`, `deal`, `clearance_sale`, `wishlist`, `compare`, `review`, `restock` | 231 | ≈41500 | جرد نقاط النهاية وحقول النماذج المالية فقط |
| المساعدات | `lib/helper/price_converter.dart`, `cart_healper.dart`, `shop_helper.dart`, `product_helper.dart`, `lib/utill/app_constants.dart` | 5 | 743 | كامل |
| الاختبارات | `test/myfatoorah_payment_status_test.dart`, `test/myfatoorah_return_url_test.dart` | 2 | 269 | أسماء الحالات والتوقعات |

**أنماط البحث المسجَّلة (كلها على `lavkooapp/lib` ما لم يُذكر غير ذلك):** `PriceConverter\.(convertPrice|getUnitCurrency|convertPriceWithoutSymbol|longToShortPrice|percentageCalculation)\(`، `toStringAsFixed`، `decimalPointSettings`، `'SYP'|'SAR'|'USD'`، `businessMode`، `orderGroupId|order_group_id`، `\.balance\b|walletBalance|totalWalletBalance`، `\.loyaltyPoint\b|totalLoyaltyPoint`، `region_id|city_id|district|national_address|short_address|building_number|unified_address` (غير حسّاس لحالة الأحرف)، `storefront|store_id|market_id|country_id|X-Store|X-Country|X-Market|legal_entity|zone_id`، `setCurrency\(`، `_zipCodeController`، `GoogleMap\(`، `totalPhysical`. وعلى `lavkoo`: `Idempotency-Key` و`idempotency` في `app`, `routes`, `config`, `Modules`، و`current_currency_code` في `app`, `routes`.

**ما لم يُفحص:** بنية التطبيق العامة وترويسات `DioClient` والتخزين المحلي والروابط العميقة (مغطّاة في E16)، محرّك الثيم الديناميكي (E17)، تنفيذ نقاط النهاية في الخلفية (تُرك لملاحق الخلفية؛ اكتُفي بالتحقق من وجود المسارات المذكورة)، شاشات المصادقة، ملفات `android/` و`ios/`، وأي تشغيل فعلي للتطبيق أو الاختبارات (لم يُنفَّذ أي أمر `flutter`/`dart`).

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 السلة: سلة خادمية واحدة مُجمَّعة حسب `cart_group_id`

- **السلة خادمية بالكامل.** `CartController.getCartData` يستدعي `getCartList` ويستبدل القائمة المحلية كاملةً بردّ الخادم (`lavkooapp:lib/features/cart/controllers/cart_controller.dart:43-63`). الطلب هو `GET /api/v1/cart?coupon_code=…&guest_id=…` (`lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:30-38`). لا توجد سلة محلية دائمة: بحثُ `sqflite|hive|cart.*sharedPreferences` في `lib` لم يُظهر تخزيناً للسلة (المطابقات الوحيدة لـ Hive هي تهيئته في `main.dart` واستعماله في تذكيرات الدواء)؛ المفتاح المحلي الوحيد المتصل بها هو `guest_cart_id` (`lavkooapp:lib/utill/app_constants.dart:312`). الحالة: VERIFIED.
- **المعرّف الوحيد المرسَل مع كل عمليات السلة هو `guest_id` (والتوكن في الترويسة).** الإضافة `POST /api/v1/cart/add` بالحقول `id, guest_id, variant, quantity, buy_now, shipping_method_exist, shipping_method_id` مع خيارات المتغيّرات (`lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:55-83`)، تعديل الكمية (`lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:131-145`)، الحذف (`lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:148-161`)، الاختيار `POST /api/v1/cart/select-cart-items` بـ `ids` و`action` (`lavkooapp:lib/features/cart/controllers/cart_controller.dart:212-231`، `lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:178-191`). لا يُرسَل أي معرّف متجر/دولة/كيان. الحالة: VERIFIED.
- **سلة الضيف والدمج.** بعد تسجيل الدخول من شاشة السلة يُستدعى `mergeGuestCart` الذي يرسل `cart_guest_id` إلى `/api/v1/cart/get-merge-guest-cart` (`lavkooapp:lib/features/cart/controllers/cart_controller.dart:240-248`، `lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:164-175`، `lavkooapp:lib/features/cart/screens/cart_screen.dart:539-548`). الحالة: VERIFIED.
- **نموذج عنصر السلة** يحمل `seller_id`, `seller_is`, `cart_group_id`, `shop_info`, `shop`, `is_checked`, `tax`, `tax_model`, `applied_tax`, `shipping_cost_tax`, `shipping_cost`, `shipping_type`, `minimum_order_amount_info`, `free_delivery_order_amount`, `is_product_available` و`product.total_current_stock` (`lavkooapp:lib/features/cart/domain/models/cart_model.dart:95-180`، `lavkooapp:lib/features/cart/domain/models/cart_model.dart:237-256`). لا يحمل عملة ولا متجراً. الحالة: VERIFIED.
- **التجميع حسب البائع في الواجهة.** الشاشة تبني قائمة المجموعات من `cartGroupId` الفريدة (`lavkooapp:lib/features/cart/screens/cart_screen.dart:151-163`) ثم قائمة عناصر لكل مجموعة (`lavkooapp:lib/features/cart/screens/cart_screen.dart:165-189`). لكل مجموعة: خانة اختيار جماعية (`lavkooapp:lib/features/cart/screens/cart_screen.dart:941-949`)، اسم المتجر `shopInfo` قابل للنقر (`lavkooapp:lib/features/cart/screens/cart_screen.dart:958`)، واختيار طريقة الشحن لكل مجموعة (`lavkooapp:lib/features/cart/screens/cart_screen.dart:1004`). وضع `business_mode == "single"` يُقرأ إلى `singleVendor` (`lavkooapp:lib/features/cart/screens/cart_screen.dart:111-114`). الحالة: VERIFIED.
- **الإجماليات تُحسب في التطبيق.** المجموع = Σ(`price − discount`)×`quantity` للعناصر المختارة، والضريبة من `CartHelper.calculateVatTax` = Σ(`applied_tax + shipping_cost_tax`) عند `system_tax_include_status != 1` (`lavkooapp:lib/features/cart/screens/cart_screen.dart:209-223`، `lavkooapp:lib/helper/cart_healper.dart:4-17`)، والشحن من الطرق المختارة ومن `shipping_cost` لكل عنصر (`lavkooapp:lib/features/cart/screens/cart_screen.dart:224-239`). الحالة: VERIFIED.
- **فحوص قبل الانتقال للدفع (كلها في التطبيق):** اختيار طريقة شحن لكل مجموعة `order_wise` (`lavkooapp:lib/features/cart/screens/cart_screen.dart:402-436`)، الحد الأدنى لمبلغ الطلب لكل بائع `minimumOrderAmountInfo` (`lavkooapp:lib/features/cart/screens/cart_screen.dart:438-456`)، الكمية مقابل `totalCurrentStock` للمنتجات المادية (`lavkooapp:lib/features/cart/screens/cart_screen.dart:458-473`)، إغلاق/إجازة المتجر (`lavkooapp:lib/features/cart/screens/cart_screen.dart:475-519`)، واشتراط تسجيل الدخول عند `guest_checkout == 0` (`lavkooapp:lib/features/cart/screens/cart_screen.dart:521-548`). أزرار الكمية تحدّ الكمية بالمخزون المتاح وتحذف عند الحد الأدنى (`lavkooapp:lib/features/cart/widgets/cart_quantity_button_widget.dart:29-45`). الحالة: VERIFIED.

### 2.2 إتمام الطلب: checkout واحد لكل المجموعات المختارة

- **checkout واحد يضم عدة بائعين.** زر السلة يفتح `CheckoutScreen` بكل عناصر السلة وبـ `sellerId: null` (`lavkooapp:lib/features/cart/screens/cart_screen.dart:666-680`). طلبات الوضع لا تحمل قائمة عناصر ولا معرّف بائع؛ الخادم يحوّل ما هو مختار في السلة إلى طلبات. الرد يحمل `order_ids` كقائمة، والتطبيق مهيأ لعدة معرّفات (`lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:361-375`، `lavkooapp:lib/features/checkout/widgets/order_place_bottomsheet_widget.dart:45-55`). الحالة: VERIFIED للتطبيق؛ «طلب لكل مجموعة بائع» في الخادم INFERRED من تعدد `order_ids` ومن `order_group_id` في نموذج الطلب (`lavkooapp:lib/features/order/domain/models/order_model.dart:175`).
- **طرق الشحن.** في وضع `sellerwise_shipping` يُطلب `GET /api/v1/shipping-method/by-seller/{sellerId}/{type}` لكل مجموعة (`lavkooapp:lib/features/shipping/controllers/shipping_controller.dart:36-90`، `lavkooapp:lib/features/shipping/domain/repositories/shipping_repository.dart:16-25`)، وفي غيره تُطلب طرق الإدارة بالقيم الثابتة `1` و`admin` (`lavkooapp:lib/features/shipping/controllers/shipping_controller.dart:92-126`) وتُخزَّن تحت المجموعة الثابتة `all_cart_group` (`lavkooapp:lib/features/cart/screens/cart_screen.dart:646-650`). الاختيار يُحفظ خادمياً بـ `id` و`cart_group_id` (`lavkooapp:lib/features/shipping/domain/repositories/shipping_repository.dart:28-43`). الحالة: VERIFIED.
- **العنوان.** الشاشة تجلب قائمة العناوين عند الفتح (`lavkooapp:lib/features/checkout/screens/checkout_screen.dart:84-125`) وتمنع المتابعة دون عنوان شحن للمنتجات المادية (`lavkooapp:lib/features/checkout/screens/checkout_screen.dart:186-199`). **عنوان الفوترة مُجبَر على مساواة عنوان التوصيل داخل الـ controller** في كل الحالات؛ إعداد `billing_input_by_customer` يتحكم في إظهار قسم الفوترة في الشاشة فقط (`lavkooapp:lib/features/checkout/screens/checkout_screen.dart:116-118`). مواضع التثبيت: (`lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:160-162`، `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:425-451`، `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:831-846`). الحالة: VERIFIED.
- **القسيمة.** تظهر للمسجَّلين فقط (`lavkooapp:lib/features/checkout/screens/checkout_screen.dart:438-447`). التطبيق يرسل الرمز وحده `GET /api/v1/coupon/apply?code=` ويقرأ `coupon_discount` من الرد ثم يعيد جلب السلة بـ `coupon_code` (`lavkooapp:lib/features/coupon/domain/repositories/coupon_repository.dart:12-19`، `lavkooapp:lib/features/coupon/controllers/coupon_controller.dart:37-70`). نموذج القسيمة يحمل `seller_id` و`coupon_bearer` و`min_purchase` دون أي بُعد متجر/عملة (`lavkooapp:lib/features/coupon/domain/models/coupon_item_model.dart:25-40`). الحالة: VERIFIED.
- **ملاحظة الطلب والملخّص.** الملاحظة حقل نصي واحد لكل الـ checkout (`lavkooapp:lib/features/checkout/screens/checkout_screen.dart:675-682`). الملخّص (المجموع، الشحن، الخصم، القسيمة، الضريبة، خصم الإحالة، الإجمالي) يُحسب محلياً من قيم مُمرَّرة من السلة (`lavkooapp:lib/features/checkout/screens/checkout_screen.dart:527-604`). الحالة: VERIFIED.
- **«اشترِ الآن».** يضيف للسلة بـ `buy_now: 1` ثم يفتح الـ checkout بعنصر واحد (`lavkooapp:lib/features/product_details/widgets/cart_bottom_sheet_widget.dart:1244-1268`، `lavkooapp:lib/features/product_details/widgets/cart_bottom_sheet_widget.dart:1385-1416`). الحالة: VERIFIED.

### 2.3 بدء الدفع ومسارات الدفع الأربعة

**مصدر قائمة الطرق:** كلها من `GET /api/v1/config` — `cash_on_delivery`, `digital_payment`, `payment_methods[]` (`key_name` + `additional_datas.gateway_title/gateway_image`), `wallet_status`, `offline_payment` (`lavkooapp:lib/features/splash/domain/models/config_model.dart:193-194`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:296-316`). الـ checkout يعيد قراءة هذه الحقول فقط عند الفتح (`lavkooapp:lib/features/splash/controllers/splash_controller.dart:301-321`، `lavkooapp:lib/features/checkout/screens/checkout_screen.dart:97`). الورقة السفلية تعرض COD (`lavkooapp:lib/features/checkout/widgets/payment_method_bottom_sheet_widget.dart:136-137`)، المحفظة للمسجَّلين (`lavkooapp:lib/features/checkout/widgets/payment_method_bottom_sheet_widget.dart:160-163`)، البوابات الرقمية ديناميكياً (`lavkooapp:lib/features/checkout/widgets/payment_method_bottom_sheet_widget.dart:250-276`) والدفع غير المتصل (`lavkooapp:lib/features/checkout/widgets/payment_method_bottom_sheet_widget.dart:294-295`). **لا يوجد في التطبيق اسم بوابة ثابت للعرض**؛ الاستثناء الوحيد هو مفاتيح MyFatoorah للتعرّف على مسار التحقق (`lavkooapp:lib/features/checkout/domain/services/myfatoorah_payment_service.dart:27`). البوابات الظاهرة فعلياً في الإنتاج: UNKNOWN (تتطلب ردّ `config` الحي أو جدول `addon_settings`).

| المسار | الطلب | المعاملات | الموضع |
|---|---|---|---|
| COD | `GET /api/v1/customer/order/place` | `address_id, coupon_code, coupon_discount, billing_address_id, order_note, guest_id, is_guest`، حقول إنشاء حساب الضيف، `bring_change_amount, current_currency_code` + ترويسة `Idempotency-Key` | `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:19-59` |
| المحفظة | `GET /api/v1/customer/order/place-by-wallet` | نفس حقول العنوان/القسيمة/الضيف في سلسلة الاستعلام، دون عملة | `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:124-144` |
| غير متصل | `POST /api/v1/customer/order/place-by-offline-payment` | `method_informations` (base64 لـ JSON)، `method_name, method_id, payment_note` + حقول العنوان/القسيمة/الضيف | `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:72-121` |
| رقمي | `POST /api/v1/digital-payment` | `order_note, customer_id, address_id, billing_address_id, coupon_code, coupon_discount, payment_platform: "app", payment_method, callback: null, payment_request_from: "app", guest_id, is_guest`، وحقول إنشاء حساب الضيف | `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:158-195` |

- مسار الدفع الرقمي **لا يرسل رمز عملة ولا أي سياق متجر**، بينما COD يرسل `current_currency_code` من العملة الحالية للتطبيق (`lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:241-245`). في الخلفية يُستعمل هذا الحقل فقط عند `currency_model == 'multi_currency'` (`lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:244-248`). الحالة: VERIFIED.
- **مفتاح المحاولة.** الـ controller يولّد مفتاحاً واحداً لكل محاولة ويبقيه عند انعدام الرد (`lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:46-63`، `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:315-327`) ويُرسله ترويسةً `Idempotency-Key` في مسارات الطلب المعنية (`lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:66-69`). تقييم جانب الخادم لهذه الترويسة مُحال إلى SEC-E18-05.

SEC-E18-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- **إثبات نجاح الوضع** هو وجود `order_ids` غير فارغة لا رمز HTTP 200 (`lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:263-267`). الحالة: VERIFIED.
- **نتيجة الدفع الرقمي — مساران مختلفان:**
  1. **MyFatoorah: تحقق خادمي.** الشاشة تتعرّف على MyFatoorah من رابط البدء (`lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:53-54`)، توقف التنقل عند رابط العودة الخاص بنا (إن كان للرابط مضيف فيجب أن يطابق مضيف `AppConstants.baseUrl`) وتستخرج `reference` (`lavkooapp:lib/features/checkout/domain/services/myfatoorah_payment_service.dart:39-64`)، ثم تستطلع `GET /api/v1/payment/myfatoorah/status` حتى 75 ثانية بفواصل متزايدة (`lavkooapp:lib/features/checkout/domain/services/myfatoorah_payment_service.dart:70-132`). النتائج ثلاث: `paid`، فشل مؤكَّد (`failed|cancelled|expired` مع `is_final`)، أو «قيد التأكيد» الذي لا يُعرض فشلاً أبداً (`lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:181-234`، `lavkooapp:lib/features/checkout/domain/models/myfatoorah_payment_status.dart:41-76`). المسار موجود في الخلفية (`lavkoo:routes/rest_api/v1/api.php:540`). الاختبارات تثبّت ذلك: `paid` لا يُعتمد إلا بعلم الخادم (`lavkooapp:test/myfatoorah_payment_status_test.dart:103-112`)، `pending` ليس فشلاً (`lavkooapp:test/myfatoorah_payment_status_test.dart:58-68`)، ورفض callback على مضيف آخر (`lavkooapp:test/myfatoorah_return_url_test.dart:49-55`). الحالة: VERIFIED (الاختبارات لم تُشغَّل).
  2. **بقية البوابات (ومنها Paymob إن كانت مفعّلة): النتيجة تُقرأ من عنوان إعادة التوجيه** لا من API (`lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:123-135`، `lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:384-452`). الحالة: VERIFIED. `SEC-E18-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- **شحن المحفظة ودفع المتبقي من طلب مُعدَّل** يمرّان عبر الشاشة نفسها: مسار `addFundToWalletScreen` يفتح `DigitalPaymentScreen(fromWallet: true)` (`lavkooapp:lib/helper/route_healper.dart:1818-1832`)، ودفع المتبقي يمرّر `orderId` (`lavkooapp:lib/features/order_details/controllers/order_details_controller.dart:556-577`). الحالة: VERIFIED.
- `SEC-E18-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.` و`SEC-E18-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`

### 2.4 الطلبات: القائمة، التفاصيل، التتبع، الإلغاء، الاسترجاع، إعادة الطلب

- القائمة `GET /api/v1/customer/order/list?limit=10&offset=…&status=…[&type=…]` (`lavkooapp:lib/features/order/domain/repositories/order_repository.dart:16-20`)؛ التفاصيل `GET /api/v1/customer/order/details?order_id=` (`lavkooapp:lib/features/order_details/domain/repositories/order_details_repository.dart:18-21`)؛ تتبّع الضيف برقم الطلب والهاتف (`lavkooapp:lib/features/order_details/domain/repositories/order_details_repository.dart:76-80`)؛ الفاتورة `generate-invoice` (`lavkooapp:lib/features/order_details/domain/repositories/order_details_repository.dart:137`). الحالة: VERIFIED. `SEC-E18-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.`
- **نموذج الطلب** يحمل `seller_id`, `seller_is`, `seller`, `order_group_id`, `order_amount`, `paid_amount`, `shipping_cost`, `total_tax_amount`, `tax_model`, `coupon_discount_bearer`, `bring_change_amount_currency` (`lavkooapp:lib/features/order/domain/models/order_model.dart:25-80`). **لا يوجد حقل عملة أو متجر أو كيان قانوني على مستوى الطلب**: البحث عن `currency` في نماذج الطلب أعاد `bring_change_amount_currency` فقط (`lavkooapp:lib/features/order/domain/models/order_model.dart:224`). يوجد حقل `country` داخل نموذج عنوان الشحن المضمَّن (`lavkooapp:lib/features/order/domain/models/order_model.dart:350`) وهو بيانات عنوان المستلم وليس بُعداً للطلب. `order_group_id` يُحلَّل ولا يُستعمل في أي واجهة (المطابقة الوحيدة خارج التعريف هي `lavkooapp:lib/features/order/domain/models/order_model.dart:175`). الحالة: VERIFIED.
- **عرض البائع لكل طلب:** بطاقة الطلب تعرض صورة المتجر أو المتجر الداخلي حسب `seller_is == 'admin'` (`lavkooapp:lib/features/order/widgets/order_widget.dart:124-131`)؛ تفاصيل الطلب تعرض اسم المتجر وزر المحادثة مع البائع (`lavkooapp:lib/features/order_details/widgets/seller_section_widget.dart:77-89`، `lavkooapp:lib/features/order_details/widgets/seller_section_widget.dart:140-148`). الحالة: VERIFIED.
- **الإلغاء** يظهر في الواجهة لصاحب الطلب عندما `order_status == 'pending'` وليس `POS` عبر `GET /api/v1/order/cancel-order?order_id=` (`lavkooapp:lib/features/order_details/widgets/cancel_and_support_center_widget.dart:93-98`، `lavkooapp:lib/features/order/domain/repositories/order_repository.dart:41-44`). **إعادة الطلب** للطلبات `delivered` عبر `POST /api/v1/customer/order/again` (`lavkooapp:lib/features/order_details/widgets/cancel_and_support_center_widget.dart:116-131`، `lavkooapp:lib/features/order_details/domain/repositories/order_details_repository.dart:87-90`). الحالة: VERIFIED.
- **الاسترجاع** على مستوى بند الطلب: معلومات مسبقة `GET …/refund?order_details_id=`، طلب multipart إلى `…/refund-store` بـ `order_details_id, amount, refund_reason` وصور، والنتيجة `…/refund-details` (`lavkooapp:lib/features/refund/domain/repositories/refund_repository.dart:21-41`، `lavkooapp:lib/features/refund/domain/repositories/refund_repository.dart:49-63`). مبلغ الاسترجاع يأتي من الخادم (`refund_amount`) (`lavkooapp:lib/features/refund/domain/models/refund_info_model.dart:48-55`). الحالة: VERIFIED.
- **دفع المتبقي لطلب مُعدَّل** بأربع طرق (`lavkooapp:lib/features/order_details/domain/repositories/order_details_repository.dart:156-217`)؛ المسار الرقمي منها يرسل `current_currency_code` (`lavkooapp:lib/features/order_details/domain/repositories/order_details_repository.dart:172-187`). الحالة: VERIFIED.
- **إجمالي الطلب في التفاصيل يُعاد حسابه في التطبيق** من البنود والشحن والخصومات والضريبة (`lavkooapp:lib/features/order_details/widgets/order_amount_calculation.dart:86-143`). الحالة: VERIFIED.

### 2.5 المحفظة والولاء: رصيد واحد ونقاط واحدة لكل عميل

| العنصر | مصدر القيمة | الموضع | الحالة |
|---|---|---|---|
| رصيد المحفظة في «المزيد» وفي شاشة الدفع | `wallet_balance` من `GET /api/v1/customer/info` → `ProfileController.balance` | `lavkooapp:lib/features/profile/domain/models/profile_model.dart:57-61`، `lavkooapp:lib/features/profile/controllers/profile_contrroller.dart:35-36`، `lavkooapp:lib/features/more/widgets/more_horizontal_section_widget.dart:60-87` | VERIFIED |
| رصيد بطاقة المحفظة | `total_wallet_balance` من `GET /api/v1/customer/wallet/list` | `lavkooapp:lib/features/wallet/domain/models/wallet_transaction_model.dart:28-29`، `lavkooapp:lib/features/wallet/widgets/wallet_card_widget.dart:41-50` | VERIFIED |
| النقاط | `loyalty_point` من `customer/info`، و`total_loyalty_point` من `GET /api/v1/customer/loyalty/list` | `lavkooapp:lib/features/loyaltyPoint/domain/models/loyalty_point_model.dart:28-29`، `lavkooapp:lib/features/loyaltyPoint/screens/loyalty_point_screen.dart:173` | VERIFIED |

- **الدفع بالمحفظة عند الـ checkout:** شاشة الدفع تعرض رصيد المحفظة وتستعمله في مسار الدفع بالمحفظة وفي دفع المتبقي؛ تفاصيل الفحص المحلي مُحالة إلى SEC-E18-06. الحالة: VERIFIED للعرض.

SEC-E18-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- **شحن المحفظة:** متاح عند `add_funds_to_wallet == 1` ووجود بوابات رقمية (`lavkooapp:lib/features/wallet/widgets/wallet_card_widget.dart:107-121`)؛ الطلب `POST /api/v1/add-to-fund` بـ `payment_platform: app, payment_method, payment_request_from: app, amount, current_currency_code` (`lavkooapp:lib/features/wallet/domain/repositories/wallet_repository.dart:58-71`)، ثم فتح `redirect_link` (`lavkooapp:lib/features/wallet/controllers/wallet_controller.dart:101-137`). حدود المبلغ `minimum_add_fund_amount`/`maximum_add_fund_amount` من الإعداد العام (`lavkooapp:lib/features/splash/domain/models/config_model.dart:330-345`). الحالة: VERIFIED.
- **تحويل النقاط:** `POST /api/v1/customer/loyalty/loyalty-exchange-currency` بـ `point` فقط (`lavkooapp:lib/features/loyaltyPoint/domain/repositories/loyalty_point_repository.dart:44-48`). المعاينة تُحسب محلياً من `loyalty_point_exchange_rate` العام مقسوماً على سعر صرف العملة الحالية، والحد الأدنى من `loyalty_point_minimum_point` (`lavkooapp:lib/features/loyaltyPoint/widget/loyalty_point_converter_dialogue_widget.dart:32-37`، `lavkooapp:lib/features/loyaltyPoint/widget/loyalty_point_converter_dialogue_widget.dart:94-101`). الحالة: VERIFIED.
- أنواع الحركات ثابتة في التطبيق كقوائم فلترة (`lavkooapp:lib/utill/app_constants.dart:360-375`). لا يوجد في أي نموذج محفظة/ولاء حقل عملة أو متجر. الحالة: VERIFIED.

### 2.6 العناوين

- **الحقول:** `contact_person_name, address_type, address, city, zip, phone, state, country, latitude, longitude, is_billing, guest_id, email` (`lavkooapp:lib/features/address/domain/models/address_model.dart:1-17`، `lavkooapp:lib/features/address/domain/models/address_model.dart:37-53`). **لا يوجد حقل حي أو عنوان وطني ولا معرّفات مدن**: بحثُ `region_id|city_id|district|national_address|short_address|building_number|unified_address` في `lib` أعاد صفر مطابقة، بينما الخلفية تعتمد نظام عناوين مركزياً بمعرّفات (`lavkoo:CLAUDE.md:42`). حقل `state` موجود في النموذج (`lavkooapp:lib/features/address/domain/models/address_model.dart:11`) ولا تعرضه شاشة الإدخال. الحالة: VERIFIED.
- **النموذج:** المدينة حقل نصي حر إلزامي (`lavkooapp:lib/features/address/screens/add_new_address_screen.dart:670-681`)؛ الدولة اسم نصي من منتقٍ أو من قائمة الدول المقيَّدة (`lavkooapp:lib/features/address/screens/add_new_address_screen.dart:97-107`)؛ **الرمز البريدي بلا حقل إدخال**: `_zipCodeController` يظهر في التعريف والتعبئة عند التعديل والإرسال فقط (`lavkooapp:lib/features/address/screens/add_new_address_screen.dart:56`، `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:139`، `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:719`)؛ الإحداثيات من `LocationController` (`lavkooapp:lib/features/address/screens/add_new_address_screen.dart:729-744`) والخريطة في شاشة مستقلة (`lavkooapp:lib/features/location/screens/select_location_screen.dart:64`)؛ الإرسال `POST /api/v1/customer/address/add` بجسم `toJson` (`lavkooapp:lib/features/address/domain/repositories/address_repository.dart:92`). الحالة: VERIFIED.
- **الهاتف:** رمز الدولة الافتراضي من `country_code` في الإعداد العام (`lavkooapp:lib/features/address/screens/add_new_address_screen.dart:90-102`)، والتحقق في نموذج العنوان «غير فارغ» فقط (`lavkooapp:lib/features/address/screens/add_new_address_screen.dart:254-256`)، ويُخزَّن الرقم مدموجاً بالرمز (`lavkooapp:lib/features/address/screens/add_new_address_screen.dart:713-714`). استخراج الرمز عند التعديل يعتمد أول رمز «يحتويه» الرقم (`lavkooapp:lib/helper/country_code_helper.dart:7-16`). الحالة: VERIFIED.
- قيود التوصيل: قوائم الدول والرموز البريدية المقيَّدة من `get-restricted-country-list` و`get-restricted-zip-list` (`lavkooapp:lib/features/address/domain/repositories/address_repository.dart:22`، `lavkooapp:lib/features/address/domain/repositories/address_repository.dart:33`). الحالة: VERIFIED.

### 2.7 واجهة تعدد البائعين

- وضع `business_mode` يُقرأ في 6 ملفات خارج نموذج الإعداد (السلة، «المزيد»، الرئيسية، الرئيسية بقالب Fashion، الرئيسية بقالب Aster، لوحة التنقل)؛ مثال: `lavkooapp:lib/features/dashboard/screens/dashboard_screen.dart:74`. في السلة يُخفي الوضع `single` عناصر تجميع البائع فقط (`lavkooapp:lib/features/cart/screens/cart_screen.dart:1194`، `lavkooapp:lib/features/cart/screens/cart_screen.dart:1463`) ولا يضيف فحص كيان. الحالة: VERIFIED.
- صفحات المتاجر: `GET /api/v1/seller?slug=`، `seller/list/…`، `seller/more`، منتجات وقسائم وفئات وعلامات لكل متجر (`lavkooapp:lib/utill/app_constants.dart:116-118`، `lavkooapp:lib/utill/app_constants.dart:187-189`، `lavkooapp:lib/utill/app_constants.dart:197-198`). المتجر الداخلي يُمثَّل بـ `sellerId: 0` وببيانات `inHouseShop` من الإعداد (`lavkooapp:lib/features/cart/screens/cart_screen.dart:1687-1691`). الحالة: VERIFIED.
- المحادثة: نقاط `customer/chat/{list|get-messages|send-message|seen-message}/{type}` حيث `type` إحدى القيمتين `seller` أو `delivery-man` (`lavkooapp:lib/features/chat/domain/repositories/chat_repository.dart:22-25`، `lavkooapp:lib/features/chat/domain/repositories/chat_repository.dart:45-48`، `lavkooapp:lib/features/chat/controllers/chat_controller.dart:105-109`)، وتُفتح من تفاصيل الطلب بمعرّف البائع (`lavkooapp:lib/features/order_details/widgets/seller_section_widget.dart:77-89`). الحالة: VERIFIED.

### 2.8 عرض المبالغ: التحويل والتنسيق والكسور

- **`PriceConverter.convertPrice` هو المنسِّق الرئيس: 114 استدعاءً في 42 ملفاً.** يضرب المبلغ في `myCurrency.exchangeRate / usdCurrency.exchangeRate` لأن `singleCurrency` مثبَّتة على `false` (`lavkooapp:lib/helper/price_converter.dart:65-73`)، ثم **يطبع بصفر خانات عشرية دائماً** `toStringAsFixed(0)` (`lavkooapp:lib/helper/price_converter.dart:75-78`) مع فاصل آلاف وموضع رمز من `currency_symbol_position` (`lavkooapp:lib/helper/price_converter.dart:80-82`). النسخة السابقة التي كانت تستعمل `decimal_point_settings` معلَّقة (`lavkooapp:lib/helper/price_converter.dart:6-40`). الحالة: VERIFIED.
- `decimal_point_settings` (إعداد عام واحد، `lavkooapp:lib/features/splash/domain/models/config_model.dart:286-288`) يُستعمل فقط في `getUnitCurrency` (استدعاء واحد)، `convertPriceWithoutSymbol` (استدعاء واحد)، نسبة الخصم، وحقل «مبلغ الباقي» (`lavkooapp:lib/helper/price_converter.dart:118-138`، `lavkooapp:lib/helper/price_converter.dart:140-172`، `lavkooapp:lib/features/order_details/widgets/order_payment_bottomsheet_widget.dart:777-785`). ويوجد `toStringAsFixed(2)` ثابت في حساب الطلب (`lavkooapp:lib/features/order_details/widgets/order_amount_calculation.dart:280`). **لا يوجد أي منطق كسور حسب العملة** (لا جدول ISO 4217 ولا قراءة كسور من كائن العملة: `CurrencyList` يحمل `id, name, symbol, code, status, exchangeRate, createdAt, updatedAt` فقط — `lavkooapp:lib/features/splash/domain/models/config_model.dart:541-549`). الحالة: VERIFIED.
- **اختيار العملة:** `getCurrencyData` يعطي الأولوية الأولى لعملة رمزها `SYP` أو لعملة النظام الافتراضية، وعند وجود العملة الافتراضية في القائمة (وضعها في الإنتاج UNKNOWN) فإن العملة المحفوظة للمستخدم (الأولوية الثانية) لا تُبلَغ (`lavkooapp:lib/features/splash/controllers/splash_controller.dart:231-283`). منتقي العملة ما زال موجوداً في الإعدادات (`lavkooapp:lib/features/setting/widgets/select_currency_bottom_sheet_widget.dart:189-195`) ويستدعي `setCurrency` الذي يعيد المرور بالمنطق نفسه (`lavkooapp:lib/features/splash/controllers/splash_controller.dart:346-351`). أثر ذلك: المنتقي بلا أثر فعلي — INFERRED من قراءة المنطق (يحسمه تشغيل فعلي). عند قراءة عملة برمزها `SYP` تُستبدل قيمة رمز العملة (symbol) بقيمة ثابتة في النموذج (`lavkooapp:lib/features/splash/domain/models/config_model.dart:567-570`).
- **الضريبة:** تُعرض سطراً مستقلاً عند `system_tax_include_status != 1`، وإلا يُضاف وسم «شامل الضريبة» إلى الإجمالي (`lavkooapp:lib/features/checkout/screens/checkout_screen.dart:557-604`، `lavkooapp:lib/features/cart/screens/cart_screen.dart:317-330`). المبلغ نفسه من الخادم (`applied_tax`). الحالة: VERIFIED.
- **العملة في أحداث التحليلات:** `'SYP'` ثابتة في 3 مواضع وقيمة احتياطية في 3 أخرى (`lavkooapp:lib/features/cart/controllers/cart_controller.dart:144`، `lavkooapp:lib/features/cart/screens/cart_screen.dart:655`، `lavkooapp:lib/features/product_details/controllers/product_details_controller.dart:75`، `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:303`، `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:766`، `lavkooapp:lib/features/checkout/screens/checkout_screen.dart:712`). الحالة: VERIFIED.

### 2.9 حقول نموذج المنتج المستهلَكة

`unit_price`, `purchase_price`, `tax`, `tax_model`, `tax_type`, `discount`, `discount_type`, `current_stock`, `shipping_cost`, `multiply_qty`, `minimum_order_qty`, `min_qty`, `refundable`, `product_type`, `added_by`, `user_id`, `choice_options`, `variation[]` (`type, price, sku, qty`), `clearance_sale`, `brand` (`lavkooapp:lib/features/product/domain/models/product_model.dart:263-284`، `lavkooapp:lib/features/product/domain/models/product_model.dart:354-400`، `lavkooapp:lib/features/product/domain/models/product_model.dart:570-598`). نموذج التفاصيل يضيف `seller` و`digital_variation` ويتجاهل `tax` (السطر معلَّق) (`lavkooapp:lib/features/product_details/domain/models/product_details_model.dart:490-497`، `lavkooapp:lib/features/product_details/domain/models/product_details_model.dart:531`). نطاق السعر يُحسب من أسعار المتغيّرات (`lavkooapp:lib/helper/product_helper.dart:4-23`). لا يوجد حقل عملة أو متجر أو توفّر حسب الدولة في أي من النموذجين. الحالة: VERIFIED.

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الدليل | الحالة |
|---|---|---|---|
| A1 | خادم واحد وعنوان واحد ثابت `baseUrl`، وعليه تُبنى مطابقة روابط العودة من البوابات | `lavkooapp:lib/utill/app_constants.dart:39`، `lavkooapp:lib/features/checkout/domain/services/myfatoorah_payment_service.dart:55-64` | VERIFIED |
| A2 | سلة واحدة لكل عميل/ضيف؛ لا بُعد متجر في أي طلب سلة أو checkout | `lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:30-38`، `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:171-188` | VERIFIED |
| A3 | رصيد محفظة واحد ورصيد نقاط واحد لكل عميل | `lavkooapp:lib/features/profile/domain/models/profile_model.dart:57-61` | VERIFIED |
| A4 | عملة عرض واحدة لكل التطبيق تُطبَّق على كل مبلغ، بما فيه الطلبات التاريخية وحركات المحفظة | `lavkooapp:lib/helper/price_converter.dart:41-86`، `lavkooapp:lib/features/order/widgets/order_widget.dart:251-255` | VERIFIED |
| A5 | المبالغ القادمة من API بعملة أساس تُحوَّل في العميل بسعر صرف | `lavkooapp:lib/helper/price_converter.dart:65-73` | VERIFIED |
| A6 | صفر خانات عشرية في العرض | `lavkooapp:lib/helper/price_converter.dart:75-78` | VERIFIED |
| A7 | بقايا سوق سابق: أولوية `SYP`، رمز `SYP` ثابت، `'SYP'` في التحليلات، `country = 'IN'`، بديل `'Bangladesh'`، موضوع إشعارات `sixvalley`، اسم الحزمة `syriapharmacy` | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:236-246`، `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:68`، `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:102`، `lavkooapp:lib/utill/app_constants.dart:334-335`، `lavkooapp:pubspec.yaml:1` | VERIFIED |
| A8 | قائمة طرق الدفع وحالة المحفظة/الولاء وحدود الشحن وسعر تحويل النقاط إعدادات عامة واحدة من `config` | `lavkooapp:lib/features/splash/domain/models/config_model.dart:296-316`، `lavkooapp:lib/features/splash/domain/models/config_model.dart:330-345` | VERIFIED |
| A9 | رمز الهاتف الافتراضي ودولة العنوان الافتراضية من `country_code` عام واحد | `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:90-102` | VERIFIED |
| A10 | نموذج عنوان واحد لكل الدول، مدينة نصية حرة | `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:670-681` | VERIFIED |
| A11 | الاختبار الوحيد الذي يذكر عملة يفترض `SAR` | `lavkooapp:test/myfatoorah_payment_status_test.dart:12-31` | VERIFIED |

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم في هذا النطاق | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| GCC-13 (هوية واحدة، سلال/طلبات/محافظ منفصلة لكل متجر) | السلة خادمية، فالفصل يمكن أن يُنفَّذ في الخادم دون ترحيل بيانات محلية | لا يُرسَل سياق متجر في أي طلب سلة/دفع/طلبات/محفظة؛ عدّاد السلة ورصيد المحفظة قيمة واحدة في الذاكرة؛ قائمة الطلبات بلا عملة/متجر | `lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:30-38`، `lavkooapp:lib/features/order/domain/models/order_model.dart:25-80` |
| GCC-15 (ولاء محلي لكل دولة) | الشاشة تعرض ما يرسله الخادم، والتحويل ينفَّذ خادمياً | نقاط واحدة، سعر تحويل وحدّ أدنى عامّان، والمعاينة تُحسب محلياً بسعر صرف العملة | `lavkooapp:lib/features/loyaltyPoint/widget/loyalty_point_converter_dialogue_widget.dart:32-37`، `lavkooapp:lib/features/loyaltyPoint/widget/loyalty_point_converter_dialogue_widget.dart:94-101` |
| قيد: checkout لكيان بائع واحد | الخادم هو من يقسّم الطلبات؛ التطبيق يتحمّل عدة `order_ids` | واجهة السلة تسمح باختيار مجموعات عدة بائعين في checkout واحد دون أي فحص كيان؛ ملاحظة واحدة وعنوان واحد وطريقة دفع واحدة لكل المجموعات | `lavkooapp:lib/features/cart/screens/cart_screen.dart:666-680`، `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:361-375` |
| قيد: تسعير محلي بلا FX آلي | — | التطبيق يحوّل كل مبلغ في العميل بسعر صرف (`singleCurrency = false`)؛ يسلم العرض فقط إذا كانت النسبة 1 | `lavkooapp:lib/helper/price_converter.dart:65-73` |
| ملاحظة الكسور (KWD/BHD/OMR بثلاث خانات) | — | صفر خانات في المنسِّق الرئيس؛ خانتان ثابتتان في موضع؛ `decimal_point_settings` عام وغير مستعمل في المنسِّق | `lavkooapp:lib/helper/price_converter.dart:75-78`، `lavkooapp:lib/features/order_details/widgets/order_amount_calculation.dart:280` |
| Foundation: Payment (حسابات مربوطة بالكيان/المتجر) | قائمة البوابات ديناميكية من الخادم؛ مسار MyFatoorah يتحقق خادمياً ومغطّى باختبارات | القائمة عامة لا لكل متجر؛ بدء الدفع الرقمي لا يحمل متجراً ولا عملة؛ بقية البوابات تعتمد عنوان العودة | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:301-321`، `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:171-188` |
| قيد: محافظ ودفاتر منفصلة حسب المتجر/الكيان/العملة | حركات المحفظة ورصيدها من الخادم | لا حقل عملة في الحركة؛ الرصيد يُنسَّق بعملة التطبيق الحالية؛ رصيد واحد يُعرض في شاشة الدفع (SEC-E18-06) | `lavkooapp:lib/features/wallet/widgets/wallet_card_widget.dart:41-50` |
| GCC-16 (شحن محلي/عابر حسب الأهلية) | قوائم الدول/الرموز المقيَّدة وطرق الشحن لكل بائع من الخادم | لا عرض لأهلية المنتج حسب دولة الوجهة؛ العنوان بلا معرّفات مدن؛ فحص المخزون في السلة محلي على `total_current_stock` | `lavkooapp:lib/features/address/domain/repositories/address_repository.dart:22`، `lavkooapp:lib/features/cart/screens/cart_screen.dart:458-473` |
| GCC-17 (حملات مركزية وقواعد قسائم محلية) | تطبيق القسيمة خادمي بالرمز فقط | نموذج القسيمة بلا متجر/عملة؛ `min_purchase` و`discount` يُنسَّقان بعملة التطبيق | `lavkooapp:lib/features/coupon/domain/models/coupon_item_model.dart:25-40` |
| GCC-12 (إيقاف متجر مع بقاء الطلبات والاسترجاع) | شاشات الطلبات/الاسترجاع/دفع المتبقي مستقلة عن السلة | لا مفهوم «تصفّح فقط» في السلة/الـ checkout؛ فحص الإغلاق الحالي على مستوى البائع (إجازة/إغلاق مؤقت) فقط | `lavkooapp:lib/features/cart/screens/cart_screen.dart:475-519` |
| Foundation: Legal entity (فواتير وضرائب لكل كيان) | الفاتورة تولَّد في الخادم؛ الضريبة من `applied_tax` | وسم شمول الضريبة إعداد عام؛ عنوان الفوترة مُجبَر على عنوان التوصيل | `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:160-162`، `lavkooapp:lib/features/checkout/screens/checkout_screen.dart:557-604` |
| Foundation: Seller ≠ Country | `seller_id`/`cart_group_id` يُستعملان بدلالة البائع فقط | يجب ألا يُعاد استعمال تجميع البائع في الواجهة لتمثيل المتجر/الدولة | `lavkooapp:lib/features/cart/screens/cart_screen.dart:151-163` |

### 4.1 مصفوفة أثر الشاشات (توثيق فقط)

| الشاشة / المكوّن | يفترض سلة واحدة | يفترض محفظة/نقاطاً واحدة | يفترض عملة واحدة | الأثر عند تعدد المتاجر |
|---|---|---|---|---|
| `CartScreen` وعدّاد السلة | نعم | — | نعم | تبديل المتجر يجب أن يعيد جلب السلة؛ لا يجوز ظهور عناصر متجر آخر |
| `CheckoutScreen` + ورقة الدفع | نعم | نعم (فحص الرصيد) | نعم | قائمة الدفع والرصيد والعناوين المؤهَّلة يجب أن تكون للمتجر الحالي |
| `DigitalPaymentScreen` | — | — | — (يعتمد `baseUrl`) | يعمل مع مسارات `/sa`…`/om` ما دام المضيف واحداً؛ مطابقة `path.contains` تتحمّل بادئة المسار |
| قائمة/تفاصيل الطلبات | — | — | نعم | طلب سعودي يُعرض برمز وكسور المتجر الحالي ما لم يحمل الطلب عملته |
| `WalletScreen`, بطاقة المحفظة, «المزيد» | — | نعم | نعم | رصيد واحد؛ يحتاج رصيداً لكل متجر/عملة أو منتقياً |
| `LoyaltyPointScreen` وحوار التحويل | — | نعم | نعم | سعر التحويل والحد الأدنى يجب أن يأتيا مع المتجر |
| `AddNewAddressScreen` | — | — | — | نموذج واحد لكل الدول؛ لا حقول سعودية (حي/عنوان وطني) ولا خليجية |
| صفحات المنتج/البحث/المتجر | — | — | نعم | الأسعار تُحوَّل محلياً؛ ذاكرة GET المؤقتة مفتاحها المسار ومعاملات الاستعلام المرتبة واللغة، بلا بُعد للمتجر أو الدولة (`lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:149-168`) |
| منتقي العملة في الإعدادات | — | — | — | يجب ألا يُخلط بتبديل المتجر؛ عملياً بلا أثر اليوم |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أقل إضافة موصى بها (توثيق فقط) |
|---|---|---|---|
| مسار MyFatoorah (`MyFatoorahPaymentService`, `MyFatoorahPaymentStatus`, اختباراه) | Preserve | تحقق خادمي بثلاث نتائج ومغطّى باختبارات | إبقاؤه كما هو؛ التأكد أن `currency` في الرد تعكس عملة المتجر |
| `DigitalPaymentScreen` كشاشة دفع وحيدة (طلب، محفظة، متبقٍ) | Preserve / Extend | نقطة واحدة لكل الدفعات الرقمية | تعميم نمط «اسأل الخادم» على بقية البوابات عند توفّر نقطة حالة عامة |
| مفتاح المحاولة `Idempotency-Key` وإثبات `order_ids` | Preserve / Extend | إثبات النجاح بـ `order_ids` يمنع نجاحاً زائفاً | توحيد عقد مفتاح المحاولة عبر مسارات الطلب (SEC-E18-05) |
| السلة الخادمية وتجميع `cart_group_id` | Preserve / Extend | الفصل لكل متجر ممكن خادمياً دون ترحيل محلي | حمل سياق المتجر في الطلبات (القرار في E16) وإعادة الجلب عند التبديل |
| `PriceConverter` | Refactor | صفر خانات وتحويل FX في العميل يتعارضان مع ثلاث خانات ومع التسعير المحلي | منسِّق يأخذ رمز العملة وعدد الكسور من الخادم (لكل مبلغ أو لكل متجر) دون ضرب بسعر صرف |
| منطق `getCurrencyData` وأولوية `SYP` | Refactor | بقايا سوق سابق تُبطل اختيار المستخدم | اشتقاق العملة من المتجر المختار لا من قائمة العملات |
| نماذج الطلب والمحفظة والولاء والقسيمة | Extend | بلا عملة/متجر | حقول اختيارية `currency_code`, `currency_decimals`, معرّف/رمز المتجر مع بقاء التوافق الخلفي |
| `ProfileModel.wallet_balance` / `loyalty_point` | Extend | قيمة واحدة | بنية أرصدة لكل متجر مع إبقاء الحقلين الحاليين للنسخ القديمة (قيمة المتجر السعودي) |
| نموذج العنوان وشاشة الإدخال | Refactor / New | لا يستهلك نظام العناوين المركزي في الخلفية | نموذج حسب الدولة يعتمد معرّفات المنطقة/المدينة/الحي والعنوان الوطني للسعودية |
| أحداث Meta (`MetaTrackingService`) | Refactor | `'SYP'` ثابتة | تمرير عملة المتجر الفعلية |
| `AddFundToWalletScreen` القديمة (`lavkooapp:lib/features/wallet/screens/add_fund_to_wallet_screen.dart:13`) | Refactor (تنظيف) | لم تعد مربوطة بالمسار (بحث `AddFundToWalletScreen` في `lib`: التعريف وتعليق المسار فقط) | لا إجراء في هذه المرحلة؛ تُسجَّل ككود غير مستعمل |
| فحوص السلة المحلية (حد أدنى، مخزون، إغلاق متجر) | Preserve | تجربة مستخدم فقط؛ المرجع هو الخادم | إضافة رسالة «متجر متوقف/تصفّح فقط» من الخادم |
| منتقي متجر/دولة صريح | New | غير موجود في هذا النطاق | يُعرَّف في E16؛ يجب أن يكون منفصلاً عن منتقي اللغة والعملة |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E18-F01 | المنسِّق الرئيس للمبالغ يطبع صفر خانات عشرية دائماً (114 استدعاءً في 42 ملفاً) | P0 | VERIFIED | `lavkooapp:lib/helper/price_converter.dart:75-78` | مبلغ 1.250 KWD يُعرض «1»؛ الإجمالي المعروض يخالف المحصَّل؛ يمسّ السعودية اليوم أيضاً (الهللات) | منسِّق يعتمد كسور العملة من الخادم؛ اختبارات عرض لكل العملات الست |
| E18-F02 | تحويل FX في العميل لكل مبلغ (`singleCurrency = false`) | P1 | VERIFIED | `lavkooapp:lib/helper/price_converter.dart:65-73`، `lavkooapp:lib/helper/price_converter.dart:140-172` | يتعارض مع «لا FX آلي»؛ أي سعر صرف ≠ 1 يغيّر المبلغ المعروض عن المحصَّل | الخادم يرسل المبالغ بعملة المتجر؛ التطبيق ينسِّق فقط |
| E18-F03 | عملة العرض تُحسم بأولوية `SYP` ثم العملة الافتراضية، فيُتجاهل اختيار المستخدم المحفوظ ما دامت إحداهما موجودة في القائمة | P1 | VERIFIED (المنطق)؛ الأثر INFERRED | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:231-283` | وجود عملة `SYP` في أي قائمة مستقبلية يفرضها على كل المتاجر؛ منتقي العملة بلا أثر | اشتقاق العملة من المتجر؛ إزالة الأولوية الثابتة |
| E18-F04 | لا سياق متجر/كيان في طلبات السلة والـ checkout والطلبات والمحفظة والولاء | P1 | VERIFIED | `lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:55-83`، `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:171-188`، `lavkooapp:lib/features/wallet/domain/repositories/wallet_repository.dart:58-71` | GCC-13 غير قابل للتنفيذ من جهة التطبيق دون حامل سياق | آلية حمل السياق تُقرَّر في E16 وتُطبَّق على كل نقاط هذا النطاق |
| E18-F05 | الطلبات والحركات المالية بلا حقل عملة؛ تُنسَّق بعملة التطبيق الحالية | P1 | VERIFIED | `lavkooapp:lib/features/order/domain/models/order_model.dart:25-80`، `lavkooapp:lib/features/order/widgets/order_widget.dart:251-255`، `lavkooapp:lib/features/wallet/domain/models/wallet_transaction_model.dart:119-130` | سجل طلبات عابر للدول يُعرض بعملة خاطئة | حقل عملة وكسور لكل طلب/حركة، وتنسيق كل سجل بعملته |
| E18-F06 | رصيد محفظة واحد ونقاط واحدة لكل عميل، على مستوى الحساب لا المتجر | P1 | VERIFIED | `lavkooapp:lib/features/profile/domain/models/profile_model.dart:57-61` | يتعارض مع GCC-13/GCC-15 وقيد الدفاتر المنفصلة | أرصدة لكل متجر/كيان؛ قرار موضع فحص الكفاية (SEC-E18-06) |
| E18-F07 | معاينة تحويل النقاط تعتمد سعراً وحدّاً عامّين وسعر صرف العملة | P1 | VERIFIED | `lavkooapp:lib/features/loyaltyPoint/widget/loyalty_point_converter_dialogue_widget.dart:32-37`، `lavkooapp:lib/features/loyaltyPoint/widget/loyalty_point_converter_dialogue_widget.dart:94-101` | معاينة خاطئة لكل متجر غير السعودي | قيم التحويل مع بيانات ولاء المتجر، والمعاينة من الخادم |
| E18-F08 | واجهة السلة تسمح بـ checkout واحد لعدة بائعين دون فحص كيان | P1 | VERIFIED | `lavkooapp:lib/features/cart/screens/cart_screen.dart:666-680` | يخالف قيد «كيان بائع واحد» إن وُجد في المتجر أكثر من كيان | فرض القيد خادمياً مع رسالة واضحة؛ واجهة تمنع الخلط |
| E18-F09 | نتيجة الدفع لغير MyFatoorah تُشتق من عنوان العودة؛ القائمة والـ `baseUrl` عامّان | P1 | VERIFIED | `lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:123-135`، `lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:384-452` | حالة عرض غير موثوقة لـ Paymob وأي بوابة خليجية جديدة (3-D Secure، محافظ) | نقطة حالة عامة لكل البوابات على غرار MyFatoorah |
| E18-F10 | بدء الدفع الرقمي لا يرسل عملة ولا سياق متجر؛ مجموعات المعاملات غير متّسقة بين المسارات الأربعة | P1 | VERIFIED | `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:19-59`، `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:158-195` | ربط حساب البوابة بالكيان يعتمد كلياً على استنتاج الخادم | عقد موحّد لبدء الدفع يحمل سياق المتجر (مفتاح المحاولة: SEC-E18-05) |
| E18-F11 | بيانات الطلب لا تحمل مفتاح محاولة على مستوى الطلب؛ تفاصيل معالجة الترويسة في الخادم مُحالة إلى SEC-E18-05 | P1 | INFERRED | `lavkoo:app/Services/Monitoring/Panels/OrderIntegrityPanel.php:1375-1378` | أثر على GCC-13 عند تقسيم الطلبات حسب المتجر والكيان | تأكيد من مدقق الطلبات، ثم قرار تصميم مفتاح محاولة على مستوى الطلب |
| E18-F12 | نموذج العنوان لا يستهلك نظام العناوين المركزي: مدينة نصية حرة، بلا حقل حي أو عنوان وطني، وحقل `state` في النموذج دون واجهة إدخال، والرمز البريدي بلا حقل إدخال | P1 | VERIFIED | `lavkooapp:lib/features/address/domain/models/address_model.dart:1-17`، `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:670-681`، `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:719` | مطابقة مدن شركات الشحن في ست دول غير موثوقة؛ لا نماذج حسب الدولة | نموذج حسب الدولة بمعرّفات من الخادم |
| E18-F13 | الهاتف في العنوان يُتحقق من عدم فراغه فقط، واستخراج الرمز بـ `contains` | P2 | VERIFIED | `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:254-256`، `lavkooapp:lib/helper/country_code_helper.dart:7-16` | أرقام غير صالحة لشركات الشحن؛ التباس بين رموز خليجية متقاربة | تحقق حسب دولة المتجر/الوجهة |
| E18-F14 | `'SYP'` ثابتة أو احتياطية في أحداث التحليلات (6 مواضع) | P2 | VERIFIED | `lavkooapp:lib/features/cart/controllers/cart_controller.dart:144`، `lavkooapp:lib/features/checkout/screens/checkout_screen.dart:712` | بيانات إسناد وإيراد خاطئة لكل الدول | تمرير عملة المتجر |
| E18-F15 | الإجماليات تُعاد حسابها في العميل (السلة، الـ checkout، تفاصيل الطلب) بحساب `double` | P2 | VERIFIED | `lavkooapp:lib/features/cart/screens/cart_screen.dart:209-239`، `lavkooapp:lib/features/checkout/screens/checkout_screen.dart:589-603`، `lavkooapp:lib/features/order_details/widgets/order_amount_calculation.dart:86-143` | فروق تقريب بثلاث خانات بين المعروض والمحصَّل | اعتماد إجماليات الخادم للعرض |
| E18-F16 | عنوان الفوترة مُجبَر على عنوان التوصيل في الـ controller في كل الحالات؛ إعداد `billing_input_by_customer` يتحكم في إظهار قسم الفوترة في الشاشة فقط | P2 | VERIFIED | `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:160-162`، `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:831-846` | قد يتعارض مع متطلبات الفوترة لكل كيان قانوني | قرار مالك المنتج لكل دولة |
| E18-F17 | حدود شحن المحفظة وحقل «الباقي» يعتمدان إعدادات عامة للمبلغ والكسور | P2 | VERIFIED | `lavkooapp:lib/features/splash/domain/models/config_model.dart:330-345`، `lavkooapp:lib/features/order_details/widgets/order_payment_bottomsheet_widget.dart:777-785` | حدود وكسور خاطئة لعملات مختلفة | قيم لكل متجر |
| E18-F18 | بقايا قالب/سوق سابق في القيم الافتراضية (`'IN'`, `'Bangladesh'`, `sixvalley`) | P2 | VERIFIED | `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:68`، `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:102`، `lavkooapp:lib/utill/app_constants.dart:334-335` | سلوك افتراضي غير متوقع عند غياب الإعداد | تنظيف ضمن مرحلة معتمدة |
| E18-F19 | قائمة طرق الدفع وحالة المحفظة/الولاء تأتي من `config` عام واحد | INFO | VERIFIED | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:301-321` | يكفي أن يصبح `config` خاصاً بالمتجر ليتبعه التطبيق دون تغيير الواجهة | `config` لكل متجر |
| E18-F20 | التطبيق مهيأ لعدة `order_ids` من checkout واحد | INFO | VERIFIED | `lavkooapp:lib/features/checkout/widgets/order_place_bottomsheet_widget.dart:45-55` | يدعم تقسيم الطلبات حسب البائع داخل الكيان الواحد | إبقاؤه |
| SEC-E18-01 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |
| SEC-E18-02 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |
| SEC-E18-03 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |
| SEC-E18-04 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |
| SEC-E18-05 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |
| SEC-E18-06 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | — | — | — | — | — |

## 7. أسئلة مفتوحة وعناصر UNKNOWN

1. **قيم `config` الحية** (العملة الافتراضية، قائمة العملات وأسعار صرفها، `decimal_point_settings`, `currency_model`, `business_mode`, `shipping_method`, البوابات المفعّلة، `guest_checkout`, `billing_input_by_customer`): UNKNOWN — يحسمها ردّ `/api/v1/config` الإنتاجي أو جداول `business_settings`/`addon_settings`/`currencies`.
2. **هل نسبة `myCurrency.exchangeRate / usdCurrency.exchangeRate` تساوي 1 في الإنتاج؟** UNKNOWN — يعتمد على وجود عملة `USD` في القائمة وعلى الأسعار؛ يحدّد إن كانت المبالغ المعروضة اليوم مطابقة للمحصَّلة.
3. **معالجة مفتاح المحاولة في جانب الخادم:** INFERRED (التفاصيل في SEC-E18-05)؛ يحسمه مدقق مسار وضع الطلبات في الخلفية.
4. **معالجة الخادم لمدخلات العميل عند بدء الدفع ووضع الطلب:** UNKNOWN — يحسمها مدقق الدفع/الطلبات في الخلفية (التفاصيل في الملحق المقيّد، SEC-E18-03).
5. **هل Paymob مفعّلة للتطبيق وبأي `key_name`؟** UNKNOWN — بيانات إنتاج.
6. **هل النسخ المنشورة في المتاجر مطابقة لهذا الـ commit؟** UNKNOWN — يتطلب مقارنة البناء المنشور.
7. **سلوك منتقي العملة فعلياً** (F03) يحتاج تشغيلاً، وهو خارج صلاحيات هذه المرحلة.
8. **علاقة مسارات شاشة الدفع بالروابط العميقة:** يحسمها E16 (التفاصيل في الملحق المقيّد، SEC-E18-02).

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| السلة تُجلب من الخادم وتستبدل القائمة المحلية | `lavkooapp:lib/features/cart/controllers/cart_controller.dart:43-63` | VERIFIED |
| طلب السلة يحمل `coupon_code` و`guest_id` فقط | `lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:30-38` | VERIFIED |
| حقول الإضافة للسلة | `lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:55-83` | VERIFIED |
| دمج سلة الضيف | `lavkooapp:lib/features/cart/domain/repositories/cart_repository.dart:164-175` | VERIFIED |
| حقول `CartModel` (بائع، مجموعة، اختيار، ضريبة) | `lavkooapp:lib/features/cart/domain/models/cart_model.dart:95-180` | VERIFIED |
| التجميع حسب `cartGroupId` | `lavkooapp:lib/features/cart/screens/cart_screen.dart:151-163` | VERIFIED |
| حساب الإجماليات محلياً | `lavkooapp:lib/features/cart/screens/cart_screen.dart:209-239` | VERIFIED |
| فحص الحد الأدنى والمخزون وإغلاق المتجر | `lavkooapp:lib/features/cart/screens/cart_screen.dart:438-519` | VERIFIED |
| فتح checkout واحد بكل العناصر و`sellerId: null` | `lavkooapp:lib/features/cart/screens/cart_screen.dart:666-680` | VERIFIED |
| طرق الشحن لكل بائع | `lavkooapp:lib/features/shipping/controllers/shipping_controller.dart:36-90` | VERIFIED |
| اختيار الشحن بـ `cart_group_id` | `lavkooapp:lib/features/shipping/domain/repositories/shipping_repository.dart:28-43` | VERIFIED |
| إجبار الفوترة = التوصيل | `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:160-162` | VERIFIED |
| مفتاح المحاولة | `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:46-63` | VERIFIED |
| إثبات الوضع بـ `order_ids` | `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:263-267` | VERIFIED |
| معاملات COD | `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:33-47` | VERIFIED |
| حمولة بدء الدفع الرقمي | `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:171-188` | VERIFIED |
| ترويسة `Idempotency-Key` | `lavkooapp:lib/features/checkout/domain/repositories/checkout_repository.dart:66-69` | VERIFIED |
| إعادة قراءة طرق الدفع من `config` | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:301-321` | VERIFIED |
| عرض البوابات ديناميكياً | `lavkooapp:lib/features/checkout/widgets/payment_method_bottom_sheet_widget.dart:250-276` | VERIFIED |
| التعرّف على MyFatoorah من رابط البدء | `lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:53-54` | VERIFIED |
| استطلاع حالة MyFatoorah | `lavkooapp:lib/features/checkout/domain/services/myfatoorah_payment_service.dart:103-132` | VERIFIED |
| مطابقة المضيف لرابط العودة | `lavkooapp:lib/features/checkout/domain/services/myfatoorah_payment_service.dart:55-64` | VERIFIED |
| نتيجة بقية البوابات من عنوان العودة | `lavkooapp:lib/features/checkout/screens/digital_payment_order_place_screen.dart:123-135` | VERIFIED |
| مسار حالة MyFatoorah في الخلفية | `lavkoo:routes/rest_api/v1/api.php:540` | VERIFIED |
| اختبار «`paid` بعلم الخادم فقط» | `lavkooapp:test/myfatoorah_payment_status_test.dart:103-112` | VERIFIED |
| اختبار رفض مضيف آخر | `lavkooapp:test/myfatoorah_return_url_test.dart:49-55` | VERIFIED |
| شحن المحفظة عبر `DigitalPaymentScreen` | `lavkooapp:lib/helper/route_healper.dart:1818-1832` | VERIFIED |
| نموذج الطلب بلا عملة/متجر | `lavkooapp:lib/features/order/domain/models/order_model.dart:25-80` | VERIFIED |
| الإلغاء عند `pending` فقط | `lavkooapp:lib/features/order_details/widgets/cancel_and_support_center_widget.dart:93-98` | VERIFIED |
| طلب الاسترجاع | `lavkooapp:lib/features/refund/domain/repositories/refund_repository.dart:21-41` | VERIFIED |
| `wallet_balance` و`loyalty_point` من ملف العميل | `lavkooapp:lib/features/profile/domain/models/profile_model.dart:57-61` | VERIFIED |
| `total_wallet_balance` | `lavkooapp:lib/features/wallet/domain/models/wallet_transaction_model.dart:28-29` | VERIFIED |
| حمولة شحن المحفظة | `lavkooapp:lib/features/wallet/domain/repositories/wallet_repository.dart:58-71` | VERIFIED |
| تحويل النقاط بـ `point` فقط | `lavkooapp:lib/features/loyaltyPoint/domain/repositories/loyalty_point_repository.dart:44-48` | VERIFIED |
| حقول العنوان | `lavkooapp:lib/features/address/domain/models/address_model.dart:1-17` | VERIFIED |
| المدينة نص حر | `lavkooapp:lib/features/address/screens/add_new_address_screen.dart:670-681` | VERIFIED |
| صفر خانات عشرية | `lavkooapp:lib/helper/price_converter.dart:75-78` | VERIFIED |
| تحويل بسعر الصرف في العميل | `lavkooapp:lib/helper/price_converter.dart:65-73` | VERIFIED |
| أولوية `SYP` في اختيار العملة | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:236-246` | VERIFIED |
| `CurrencyList` بلا عدد كسور | `lavkooapp:lib/features/splash/domain/models/config_model.dart:541-549` | VERIFIED |
| حقول أسعار المنتج | `lavkooapp:lib/features/product/domain/models/product_model.dart:354-400` | VERIFIED |
| المتغيّر `type, price, sku, qty` | `lavkooapp:lib/features/product/domain/models/product_model.dart:570-598` | VERIFIED |
| `current_currency_code` يُستعمل خادمياً عند `multi_currency` فقط | `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:244-248` | VERIFIED |
| مسارات الوضع في الخلفية (COD/غير متصل/محفظة) | `lavkoo:routes/rest_api/v1/api.php:324-326`، `lavkoo:routes/rest_api/v1/api.php:390` | VERIFIED |
| اسم الحزمة والإصدار | `lavkooapp:pubspec.yaml:1`، `lavkooapp:pubspec.yaml:5` | VERIFIED |

## 9. سجل التحقق المستقل

> تحقق مستقل بعد إعداد الملف: قراءة الكود المُستشهَد به، وبحث عكسي لكل ادعاء نفي وجود، وتدقيق لحالات الإثبات. لم يُنفَّذ أي أمر بناء أو اختبار، ولم يُجرَ أي تعديل على المستودعات.

### 9.1 الأعداد

| البند | العدد |
|---|---|
| الادعاءات المفحوصة (12 ادعاءً من P0/P1، و33 ادعاءً جوهرياً آخر) | 45 |
| مؤكَّدة دون تعديل | 33 |
| مُصحَّحة (نطاق أو صياغة أو إضافة قيد) | 7 |
| مُفنَّدة جزئياً أو كلياً وصُحِّحت | 4 |
| غير قابلة للتحقق من الكود الساكن | 1 |
| عمليات بحث عكسي لنفي الوجود | 9 |

### 9.2 التصحيحات

1. §2.8 و§6 (E18-F03): أولوية `SYP` والعملة الافتراضية مشروطة بوجودهما في القائمة؛ حُذفت صيغة «موجودة دائماً».
2. §2.4 (E18-F05): حقل `country` موجود داخل نموذج عنوان الشحن المضمَّن؛ الحكم على مستوى الطلب بقي كما هو.
3. §2.3 (E18-F09): مطابقة المضيف تُطبَّق إن وُجد مضيف في الرابط.
4. §2.3 و§5 و§6 و§7 (E18-F10 وE18-F11): تفاصيل مفتاح المحاولة في المسارات ونتائج البحث في الخادم نُقلت إلى SEC-E18-05، وصُنّف E18-F11 بصفته INFERRED مع مصدره في لوحة المراقبة.
5. §2.6 و§6 (E18-F12): حقل `state` موجود في النموذج دون واجهة إدخال.
6. §2.2 و§6 (E18-F16): إعداد `billing_input_by_customer` يتحكم في إظهار قسم الفوترة، أما التثبيت على عنوان التوصيل فيحدث في الـ controller في كل الحالات.
7. §2.4: زر الإلغاء يظهر في الواجهة؛ شرط الخادم UNKNOWN.

### 9.3 ادعاءات مُفنَّدة وصُحِّحت

1. §2.7: قراءات `business_mode` موزَّعة على 6 ملفات مختلفة، لا «الرئيسية ×3».
2. §2.8: `decimal_point_settings` يُقرأ مرة واحدة في `getUnitCurrency`، لا مرتين.
3. §2.8: `CurrencyList` يحمل أيضاً `createdAt` و`updatedAt`.
4. §4.1: مفتاح ذاكرة GET يتضمن معاملات الاستعلام واللغة، لا المسار واللغة فقط؛ ويبقى غياب بُعد المتجر والدولة عن المفتاح.

### 9.4 المخاطر المتبقية

- قيم `config` الإنتاجية (العملة الافتراضية، قائمة العملات وأسعار صرفها، الكسور، البوابات المفعّلة): UNKNOWN كما في §7.
- قراءة الخادم لمفتاح المحاولة في مسارات الطلب: INFERRED، ويحسمها مدقق الطلبات (SEC-E18-05).
- المرجع الخادمي لفحص كفاية رصيد المحفظة: UNKNOWN (SEC-E18-06).
- افتراض شاشة الدفع أن الخادم يرفض بوابة معطّلة حتى لو ظهرت في قائمة مخزّنة: لم يُتحقق منه من جانب الخادم.
- رأس `lavkoo` الحالي بعد الإصدار المدقَّق `7f42777`: الملفات الخمسة المستشهد بها من الخلفية في §6 و§8 لا فرق بينها وبين `7f42777` بحسب مقارنة الإصدارين؛ بقية الاستشهادات لم تُقارَن.

### 9.5 فجوات التغطية

- لم تُفتح بالقراءة المباشرة في هذا التحقق: `route_healper.dart` (الأسطر 1547-1557 و1818-1832)، و`order_details_repository.dart` (156-217)، وأسطر تحديث السلة وحذفها في `cart_repository.dart` (131-161)، ونماذج المنتج (`product_model.dart` 263-284 و354-400 و570-598)، وحقول الاسترجاع، وسطر `OrderController.php` (244-248) في الخلفية.
- لم يُفحص من جانب الخادم أي سلوك مرتبط بمسارات `api.php` إلا للأسطر المذكورة في §8.

### 9.6 عمليات البحث العكسي (نفي الوجود)

| النمط | النطاق | النتيجة |
|---|---|---|
| `country`، `market`، `store_id`، `storefront`، `tenant`، `entity`، `currency_code`، `X-*` | `lib/data`، `lib/utill`، والسلة والدفع والطلبات والمحفظة والولاء | لا رؤوس متجر أو دولة؛ حقل `country` فقط داخل عنوان الشحن المضمَّن |
| `district`، `neighbou?rhood`، `national_address`، `region`، `state`، `unified` | العنوان والدفع والموقع والمساعدات | حقل `state` في النموذج فقط، دون واجهة إدخال |
| أسماء بوابات (`paymob`، `tabby`، `tamara`، `stripe`...) | `lib` | لا أسماء بوابات ثابتة في الواجهة |
| `4217`، `KWD`، `BHD`، `OMR`، `minor_unit`، `fraction_digits` | `lib` | لا منطق كسور حسب العملة |
| `browse`، `catalog_only`، `read_only`، `maintenance`، `vacation`، `temporary` | السلة والدفع والمتاجر والإعدادات | لا وضع تصفّح فقط؛ إغلاق المتجر المؤقت والإجازة على مستوى البائع فقط |
| تخزين السلة محلياً (`prefs`، `hive`، `cache`، `storage`) | `lib` | لا تخزين محلي للسلة؛ مفتاح `guest_cart_id` فقط |
| `idempot` بلا حساسية لحالة الأحرف | `lavkoo` (بعد استبعاد `vendor` و`node_modules` و`storage`) | 164 ملفاً، معظمها قنوات بيع أخرى؛ الموضع المتعلق بالطلب مذكور في SEC-E18-05 |
| `Idempotency-Key` و`HTTP_IDEMPOTENCY` | `lavkoo/app` و`routes` و`config` و`Modules` و`database` و`resources` | لا قراءة لهذه الترويسة في معالجة الطلب ضمن نطاق البحث؛ التفاصيل في SEC-E18-05 |

### 9.7 البنود المنقولة إلى الملحق المقيّد

- عدد البنود في الملحق: 6 (SEC-E18-01 إلى SEC-E18-06).
- نُقل في هذا التحقق: SEC-E18-05 وSEC-E18-06. الباقي (SEC-E18-01 إلى SEC-E18-04) كان في الملحق قبل هذا التحقق.
