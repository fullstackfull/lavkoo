# E09 — السلة والدفع والطلبات والاسترجاع والعمولات وتكلفة الشحن والضريبة والفواتير (Cart → Checkout → Order → Refund pipeline)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo` عند `7f42777c8247144df322fdc0e0eeca03ae947129` (الـ HEAD المحلي `046d623d` يزيد عليه بملف توثيق واحد تحت `docs/gcc/audits/` ولا يمسّ الكود). لم يُستخدم `lavkooapp` ولا `lavkooappseller` ولا `ksaapp98` في هذا الملحق.

## 1. النطاق وما تم فحصه

**ما تمت قراءته كاملاً أو شبه كامل (قرابة 70 ملفاً):**

| المجموعة | الملفات |
|---|---|
| منطق السلة والطلب | `app/Utils/CartManager.php` (1121 سطراً، كاملاً)، `app/Utils/OrderManager.php` (3241 سطراً؛ الأسطر 60–3034 كاملة)، `app/Utils/order.php`، `app/Utils/Helpers.php` (الدوال ذات العلاقة)، `app/Utils/currency.php`، `app/Utils/Convert.php`، `app/Utils/module-helper.php` (الأسطر 1–260)، `app/Utils/ProductManager.php` (الدالة `updateProductPriceInCartList`) |
| المتحكمات | `app/Http/Controllers/Web/WebController.php` (323–902)، `app/Http/Controllers/Web/CartController.php` (أجزاء)، `app/Http/Controllers/Web/UserProfileController.php` (الإلغاء والفاتورة)، `app/Http/Controllers/Customer/PaymentController.php` (كاملاً)، `app/Http/Controllers/Customer/SystemController.php` (30–130)، `app/Http/Controllers/RestAPI/v1/OrderController.php` (40–640)، `CartController.php`، `ShippingMethodController.php`، `OrderEditController.php` (كاملاً)، `app/Http/Controllers/Admin/Order/OrderController.php` (247–320 و600–860)، `Admin/Order/RefundController.php`، `Vendor/Order/OrderController.php` و`Vendor/RefundController.php` (مسار الحالة)، `RestAPI/v3/seller/OrderController.php` و`POSController.php`، `RestAPI/v2/delivery_man/DeliveryManController.php` (130–260 و760–776)، `Admin/POS/POSOrderController.php` |
| المستودعات والخدمات | `app/Repositories/OrderRepository.php` (29–80 و510–975)، `app/Services/RefundStatusService.php`، `RefundTransactionService.php`، `SellerInvoiceService.php`، `OrderService.php` (جزء POS)، `app/Traits/OrderEditManager.php` (705–740)، `app/Traits/PdfGenerator.php`، `app/Traits/CommonTrait.php`، `app/Services/Marketplace/CommissionEngine.php`، `ShippingRateService.php`، `RefundReversalService.php` (جزء)، `app/Services/Fulfillment/CheckoutCodGuard.php`، `OrderItemOwnershipSnapshotter.php`، `app/Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php` (95–150) |
| النماذج | `Order`، `OrderDetail`، `Cart`، `CartShipping`، `OrderTransaction`، `Transaction`، `AdminWallet`، `SellerWallet`، `RefundRequest`، `ReturnShipment`، `ShippingZone`، `OrderItemCommission`، `CommissionRule`، `VendorLedgerEntry`، `VendorSettlement` |
| الترحيلات | 106 ملف ترحيل يذكر جداول النطاق (حُصرت بـ `grep`)؛ قُرئت منها ترحيلات إنشاء `carts` و`cart_shippings` و`order_transactions` و`order_edit_histories` وترحيلات الدقة العشرية وترحيلات `Modules/TaxModule` الستة |
| TaxModule | `Modules/TaxModule/app/Traits/VatTaxManagement.php` (كاملاً)، `VatTaxConfiguration.php` (فهرس)، `app/Services/CalculateTaxService.php` (15–60)، `config/config.php`، الترحيلات |
| الفواتير | `resources/views/admin-views/order/invoice.blade.php` (الترويسة والمجاميع)، `resources/themes/default/web-views/order/invoice.blade.php` (بحث نمطي)، `app/Http/Controllers/Admin/Settings/InvoiceSettingsController.php` |
| المسارات | `routes/rest_api/v1/api.php` و`routes/web/routes.php` (أسطر السلة والدفع والطلب) |

**أنماط البحث المسجّلة:** `generateOrder|generateOrderAgain`، `getWalletManageOnOrderStatusChange|getStockUpdateOnOrderStatusChange`، `pending_amount`، `sales_commission`، `currency|exchange_rate` على ترحيلات ونماذج جداول النطاق، `Schema::create('<table>'` لجداول النطاق، `zatca` (بلا حساسية للحالة) في `app` و`resources/views` و`resources/themes` و`Modules` و`config` و`routes` و`database`، `round(|number_format(|floor(` في ملفات النطاق، `get_cart_group_ids`، `Cart::`، `delivery_country_restriction`، `verification_code`، `100000|100001`.

**ما لم يُفحص:** منطق بوابات الدفع والـ webhooks (Paymob/MyFatoorah — ملحق آخر)، توجيه التنفيذ والمخزون المشترك (pools/reservations — ملحق E11)، تفاصيل محفظة العميل والولاء (E04)، القسائم والتسعير (E08)، شاشات POS تفصيلياً، واجهات Blade للسلة، تطبيقا Flutter. لم يُشغَّل أي كود أو اختبار. قيم الإعدادات في قاعدة بيانات الإنتاج (`business_settings`) غير متاحة، وكل ما يتوقف عليها موسوم `UNKNOWN`.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 بنية السلة (`carts` و`cart_shippings`)

- جدول `carts` أُنشئ بأعمدة `customer_id`، `cart_group_id` (نصّي)، `product_id`، `quantity`، `price`، `tax`، `discount`، `seller_id`، `seller_is` — `lavkoo:database/migrations/2021_10_02_185124_create_carts_table.php:16-35`؛ وأضيفت لاحقاً `shop_info`، `shipping_cost`، `shipping_type`، `product_type`، `is_guest`، `is_checked` (قائمة `$fillable` في `lavkoo:app/Models/Cart.php:65-90`). لا يوجد عمود متجر/دولة/عملة/كيان قانوني. `VERIFIED`.
- **تكوين المجموعة:** عند الإضافة يُبحث عن أي صف لنفس `(customer_id, is_guest, seller_id, seller_is)`؛ إن وُجد أُعيد استخدام `cart_group_id` وإلا وُلِّد معرّف جديد بالصيغة `<user id أو guest>-<5 أحرف عشوائية>-<time()>` — `lavkoo:app/Utils/CartManager.php:514-519` (ومثله للمنتج الرقمي `lavkoo:app/Utils/CartManager.php:687-692`). أي أن المجموعة = **بائع واحد لكل عميل**، وليست لكل متجر ولا لكل نوع شحن. `VERIFIED`.
- **هوية البائع الداخلي:** منتجات الإدارة تُكتب دائماً `seller_id = 1` و`seller_is = 'admin'` — `lavkoo:app/Utils/CartManager.php:504-505`؛ واسم المتجر يُنسخ نصاً في `shop_info` — `lavkoo:app/Utils/CartManager.php:508`. `VERIFIED`.
- **سلة الضيف:** `customer_id` يحمل `guest_id` مع `is_guest = 1` — `lavkoo:app/Utils/CartManager.php:430-436`؛ ومعرّف الضيف يصدر للتطبيق من نقطة API مخصّصة — `lavkoo:app/Http/Controllers/RestAPI/v1/GeneralController.php:21-27` — ويُنشأ للويب في الجلسة. `VERIFIED` (ما يتصل بذلك أمنياً مُحال إلى SEC-E09-03).
- **الدمج عند الدخول:** `CartManager::cartListSessionToDatabase` ينقل صفوف الضيف إلى العميل، يحذف التكرار لنفس المنتج/المتغيّر، ويضمّها إلى مجموعة البائع الموجودة — `lavkoo:app/Utils/CartManager.php:23-50`؛ يُستدعى من ستة مواضع دخول/تسجيل (حُصرت بـ `grep`)، وللتطبيق مسار مستقل `getMergeGuestCart` — `lavkoo:app/Http/Controllers/RestAPI/v1/CartController.php:342-374`. `VERIFIED`.
- **`is_checked`:** يحدد ما يدخل الطلب؛ «اشترِ الآن» يُلغي تحديد كل سلة العميل ثم يحدد الصف الواحد — `lavkoo:app/Utils/CartManager.php:555-558`. كل دوال القراءة تقبل `type: 'checked'` — `lavkoo:app/Utils/CartManager.php:81-83`. `VERIFIED`.
- **`cart_shippings`:** صف لكل `cart_group_id` يحمل `shipping_method_id` و`shipping_cost` — `lavkoo:database/migrations/2021_10_02_190207_create_cart_shippings_table.php:16-22`؛ يُكتب من الويب `lavkoo:app/Http/Controllers/Customer/SystemController.php:52-61` ومن الـ API `lavkoo:app/Http/Controllers/RestAPI/v1/ShippingMethodController.php:60-70`. `VERIFIED`.
- **نقطة التجميع الوحيدة:** `CartManager::get_cart_group_ids` تجلب كل مجموعات العميل (أو الضيف) بلا أي بُعد آخر — `lavkoo:app/Utils/CartManager.php:163-180`؛ لها 24 موضع استدعاء في 7 ملفات (11 منها داخل `CartManager`، بعد استثناء التعريف نفسه). توجد إضافةً إلى ذلك استعلامات `Cart::` مباشرة في 18 ملفاً تحت `app` و`Modules` (31 في `CartManager`، 13 في `Web/CartController`، 12 في `RestAPI/v1/CartController`). `VERIFIED` (عدّ آلي بعد التصحيح).
- **تحديث السعر:** `ProductManager::updateProductPriceInCartList` يعيد مطابقة `carts.price` والضريبة مع المنتج قبل قراءة السلة، ويقرأ السلة من cache مدته 3 ساعات — `lavkoo:app/Utils/ProductManager.php:2584-2595`. المفتاح لكل عميل (أو جلسة ضيف) دون بُعد متجر. `VERIFIED` (بناء المفتاح). تفاصيل حالات الضيف في الملحق المقيّد.

SEC-E09-11: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 2.2 الدفع (Checkout): من سلة متعددة البائعين إلى طلبات

- **التحقق قبل الدفع:** `OrderManager::checkValidationForCheckoutPages` يفحص الحد الأدنى، وجود عناصر محددة، توفر المخزون (فحص غير مقفل)، إغلاق/إجازة البائع، ووجود طريقة شحن لكل مجموعة — `lavkoo:app/Utils/OrderManager.php:2778-2931`. `VERIFIED`.
- **التقسيم:** `getVendorWiseCartList` يجمع العناصر المحددة حسب `cart_group_id` ويبني لكل مجموعة: `seller_id`، `seller_is`، المجموع، خصم القسيمة الموزَّع، تكلفة الشحن، الشحن المجاني، خصم الإحالة، عناوين الشحن/الفوترة — `lavkoo:app/Utils/OrderManager.php:887-970`. `processOrderGenerateData` يضيف الضريبة لكل سطر — `lavkoo:app/Utils/OrderManager.php:972-1057`. `VERIFIED`.
- **طلب لكل مجموعة تحت `order_group_id` واحد:** `generateOrder` يولّد `order_group_id` واحداً — `lavkoo:app/Utils/OrderManager.php:1551` — ثم يدور على المجموعات فيولّد لكل منها `orders.id` جديداً ويُدرج صف `orders` — `lavkoo:app/Utils/OrderManager.php:1604-1656`. `VERIFIED`.
- **دفعة واحدة تغطي عدة طلبات:** مبلغ البوابة = مجموع `order_amount_with_tax` لكل المجموعات — `lavkoo:app/Http/Controllers/Customer/PaymentController.php:379-383`؛ ودفع المحفظة يخصم المجموع مرة واحدة بعد إنشاء الطلبات — `lavkoo:app/Http/Controllers/Web/WebController.php:821-825` و`lavkoo:app/Http/Controllers/Web/WebController.php:881`؛ وبعد نجاح البوابة يُستدعى `generateOrder` واحد يختم كل الطلبات بنفس `transaction_ref` — `lavkoo:app/Utils/module-helper.php:133-168`. `VERIFIED`.
- **سبعة مواضع نداء لـ `generateOrder` في ثلاثة ملفات** (حُصرت بـ `grep`): الويب COD `lavkoo:app/Http/Controllers/Web/WebController.php:629-639`، الويب offline `lavkoo:app/Http/Controllers/Web/WebController.php:775-785`، الويب محفظة `lavkoo:app/Http/Controllers/Web/WebController.php:857-866`، الـ API (COD `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:251-268`، offline `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:384-401`، محفظة `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:472-487`)، وخطّاف نجاح الدفع الرقمي `lavkoo:app/Utils/module-helper.php:133`. `VERIFIED`.
- **الحد الأدنى للطلب:** لكل مجموعة؛ للإدارة من `minimum_order_amount` العام وللبائع من `sellers.minimum_order_amount` إن فُعّل `minimum_order_amount_by_seller` — `lavkoo:app/Utils/OrderManager.php:2521-2609`. `VERIFIED` (القيم `UNKNOWN`).
- **طريقة الشحن لكل مجموعة:** الإعداد العام `shipping_method` (`inhouse_shipping` أو `sellerwise_shipping`) يحدد مَن يملك قواعد الشحن؛ نوع الشحن (`order_wise` / `category_wise` / `product_wise`) من `shipping_types` حيث `seller_id = 0` للإدارة — `lavkoo:app/Utils/CartManager.php:774-789` و`lavkoo:app/Utils/CartManager.php:256-273`. في `order_wise` يختار العميل `ShippingMethod` بتكلفة ثابتة تُنسخ إلى `cart_shippings`؛ وفي النوعين الآخرين تُحسب التكلفة على السطر — `lavkoo:app/Utils/CartManager.php:899-950`. `VERIFIED`.
- **الشحن حسب المنطقة (جديد، ويب فقط):** إذا فُعّل `zone_wise_shipping` تُستبدل تكلفة الطريقة بسعر `shipping_zones` المطابق لاسم الدولة/المنطقة في عنوان الجلسة — `lavkoo:app/Http/Controllers/Customer/SystemController.php:87-102` و`lavkoo:app/Services/Marketplace/ShippingRateService.php:54-69`. مسار التطبيق يتجاهله — `lavkoo:app/Http/Controllers/RestAPI/v1/ShippingMethodController.php:68`. `VERIFIED`.
- **الشحن المجاني:** إعدادات عامة `free_delivery_status`، `free_delivery_responsibility`، `free_delivery_over_amount`، `free_delivery_over_amount_seller` مع إعدادات البائع — `lavkoo:app/Utils/OrderManager.php:2643-2694`؛ يُخزَّن على الطلب في `extra_discount` و`is_shipping_free` و`free_delivery_bearer` — `lavkoo:app/Utils/OrderManager.php:1097-1101`. `VERIFIED`.
- **رمز التحقق:** يُولَّد رمز تحقق لكل طلب — `lavkoo:app/Utils/OrderManager.php:1065`، ويُطابَق في مسار تسليم المندوب. التفاصيل الأمنية لهذا المسار في الملحق المقيّد (انظر `SEC-E09-04` في §6). `VERIFIED` (موضع التوليد).
- **قيد دفع عند الاستلام للسلال المختلطة المزوّد:** `CheckoutCodGuard::blockedReason` — `lavkoo:app/Services/Fulfillment/CheckoutCodGuard.php:28-48` (يُستدعى من `lavkoo:app/Http/Controllers/Web/WebController.php:537-545` و`lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:190-192`). `VERIFIED`.
- **قيد دولة التوصيل:** القائمة العامة `delivery_country_codes` هي مرجع القيد — `lavkoo:app/Traits/CommonTrait.php:95-105`؛ ويُطبَّق القيد في مسارات الطلب عبر الـ API على عناوين محددة (التفاصيل في الملحق المقيّد). `VERIFIED` (موضع القيد).

SEC-E09-10: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 2.3 حساب مبلغ الطلب

| المكوّن | المصدر | الدليل |
|---|---|---|
| سعر السطر | `carts.price` (سعر المتغيّر أو `unit_price`) | `lavkoo:app/Utils/CartManager.php:469-488` |
| خصم المنتج | `getProductPriceByType(... 'discounted_amount')` يُعاد حسابه وقت الطلب | `lavkoo:app/Utils/OrderManager.php:1253-1261` |
| القسيمة | تُحسب على إجمالي السلة ثم تُوزَّع نسبياً على المجموعات؛ حاملها `coupon_bearer` يُنسخ إلى `orders.coupon_discount_bearer` | `lavkoo:app/Utils/OrderManager.php:502-655`، `lavkoo:app/Utils/OrderManager.php:657-729`، `lavkoo:app/Utils/OrderManager.php:1078-1081` |
| خصم الإحالة | `refer_and_earn_discount` موزَّع على المجموعات | `lavkoo:app/Utils/OrderManager.php:836-864` |
| الضريبة | صفر إن كان النظام «شامل الضريبة»؛ وإلا نسبة × (سعر السطر بعد الخصومات الموزّعة)، مع ضريبة شحن موزَّعة على الأسطر المادية | `lavkoo:app/Utils/CartManager.php:1043-1098` |
| الشحن | `cart_shippings.shipping_cost` + مجموع `carts.shipping_cost` | `lavkoo:app/Utils/CartManager.php:182-224` |
| المبلغ النهائي | `order_amount = order_amount_with_tax − refer_and_earn_discount`؛ ويُحفظ أيضاً `init_order_amount` | `lavkoo:app/Utils/OrderManager.php:1082-1083` |
| عمولة الإدارة (المجمّعة) | نسبة البائع أو الإعداد العام `sales_commission` × (المجموع − خصم المنتج − القسيمة) | `lavkoo:app/Utils/Helpers.php:632-652`، `lavkoo:app/Utils/OrderManager.php:1062` |
| أجر المندوب | `orders.deliveryman_charge` يُدخله المشرف/البائع لاحقاً ويمر عبر `currencyConverter` | `lavkoo:app/Repositories/OrderRepository.php:586-597` |

**التقريب:** لا يوجد تقريب عند كتابة مبالغ الطلب (القيم تُخزَّن كما حُسبت بالفاصلة العائمة). التقريب يظهر في مواضع متفرقة: العمولة المجمّعة `number_format(..., 2)` — `lavkoo:app/Utils/Helpers.php:649`؛ خصم المحفظة عند الدفع `Convert::default` بـ `round($value, 2)` — `lavkoo:app/Utils/Convert.php:51-63`؛ سعر المنطقة `round(..., 2)` — `lavkoo:app/Services/Marketplace/ShippingRateService.php:66`؛ العرض حسب الإعداد العام `decimal_point_settings` — `lavkoo:app/Utils/currency.php:101-103` و`lavkoo:app/Utils/currency.php:129-130`؛ والعمولة السطرية الجديدة بأربع خانات — `lavkoo:app/Services/Marketplace/CommissionEngine.php:63-65`. `VERIFIED`.

### 2.4 اللقطات (Snapshots) على مستوى الطلب

| اللقطة | موجودة؟ | الدليل |
|---|---|---|
| العنوان (JSON) | نعم: `shipping_address_data` و`billing_address_data` تُكتبان مرة واحدة | `lavkoo:app/Utils/OrderManager.php:453-459`، `lavkoo:app/Utils/OrderManager.php:1090-1094` |
| تفاصيل المنتج | نعم: `order_details.product_details` = المنتج كاملاً JSON | `lavkoo:app/Utils/OrderManager.php:1227-1258` |
| الضريبة | جزئياً: `orders.tax_type`، `tax_model`، `total_tax_amount`، `order_details.tax`، وصفوف `order_taxes` (اسم الضريبة ونسبتها) — فقط في الوضع غير الشامل | `lavkoo:app/Utils/OrderManager.php:1084-1086`، `lavkoo:app/Utils/OrderManager.php:1511-1535` |
| العمولة السطرية | نعم (جديد): `order_item_commissions` مع عمود `currency` | `lavkoo:app/Utils/OrderManager.php:1143-1154`، `lavkoo:app/Models/OrderItemCommission.php:15-21` |
| مزوّد التنفيذ | نعم (جديد): `ProviderFulfillmentItem` للسطر المملوك لمزوّد | `lavkoo:app/Services/Fulfillment/OrderItemOwnershipSnapshotter.php:29-52` |
| البائع/المتجر | **لا**: يُحفظ `seller_id` و`seller_is` فقط؛ لا اسم متجر ولا بيانات قانونية على `orders` (اسم المتجر موجود على `carts.shop_info` ولا يُنقل) | `lavkoo:app/Models/Order.php:75-133`، `lavkoo:app/Utils/OrderManager.php:1063-1107` |
| الكيان القانوني البائع | **لا** | `lavkoo:app/Models/Order.php:75-133` |
| رمز العملة / سعر الصرف | **لا** على `orders` و`order_details` و`order_transactions` و`transactions` و`refund_requests` و`refund_transactions` و`admin_wallets` و`seller_wallets`. العمود الوحيد ذو الصلة `bring_change_amount_currency` (عملة مبلغ «أحضر الفكّة» في COD) | `lavkoo:database/migrations/2024_12_31_170955_bring_change_amount_column_in_orders_table.php:16`، `lavkoo:app/Models/OrderDetail.php:44-66`، `lavkoo:app/Models/OrderTransaction.php:41-57`، `lavkoo:app/Models/Transaction.php:11-27` |

**سجل البحث عن لقطة العملة:** `grep -nE "currency|exchange_rate"` على كل ترحيل يذكر أحد الجداول `orders|order_details|order_transactions|transactions|refund_requests|refund_transactions|carts|cart_shippings|admin_wallets|seller_wallets|order_edit_histories|order_taxes` أعاد سطراً واحداً فقط (`bring_change_amount_currency`)؛ والبحث نفسه في نماذج هذه الجداول أعاد `Order.php` (العمود ذاته) و`OrderItemCommission.php` فقط. وخارج هذه الجداول توجد أعمدة عملة في مجالات أخرى (`lavkoo:database/migrations/2026_11_08_100003_create_myfatoorah_refunds_table.php:45`، و`lavkoo:database/migrations/2026_11_07_100100_create_sales_channel_tables.php`، و`lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php`، و`lavkoo:database/migrations/2026_11_07_100000_create_operational_cost_tables.php`، و`lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php`) لم يُتتبَّع استعمالها في مسار الطلب (خارج هذا النطاق). `VERIFIED`.

**دلالة المبالغ المخزّنة:** الواجهات تمرّر مبالغ الطلب عبر `usdToDefaultCurrency` عند العرض — مثلاً `lavkoo:resources/views/admin-views/order/invoice.blade.php:580` — وهذه الدالة تضرب في نسبة «سعر صرف العملة الافتراضية ÷ سعر صرف USD» **الحالية** عندما يكون `currency_model = multi_currency` وتعيد المبلغ كما هو في غير ذلك — `lavkoo:app/Utils/currency.php:76-104`. أي أن وحدة المبلغ المخزّن تعتمد على إعداد عام (`currency_model`) قيمته في الإنتاج `UNKNOWN`، وأن طلباً قديماً يُعاد تسعيره بسعر الصرف الجاري إذا كان الوضع متعدد العملات. `VERIFIED` للكود.

### 2.5 خصم المخزون والأقفال ونقاط التسليم إلى التنفيذ

- **موضع الخصم الرئيسي:** `OrderManager::addOrderDetailsData` داخل معاملة الطلب: قفل صف المنتج `lockForUpdate` — `lavkoo:app/Utils/OrderManager.php:1224-1226`؛ تعديل JSON المتغيّر مع رفض السالب — `lavkoo:app/Utils/OrderManager.php:1291-1321`؛ خصم ذري مشروط `where current_stock >= qty` ورمي `InsufficientStockException` — `lavkoo:app/Utils/OrderManager.php:1345-1359`. `VERIFIED`.
- **ترتيب الأقفال ومنع الجمود:** قفل كل منتجات الطلب مسبقاً مرتبة بالمعرّف — `lavkoo:app/Utils/OrderManager.php:1596-1602`؛ المعاملة تُعاد 3 مرات — `lavkoo:app/Utils/OrderManager.php:1733`. `VERIFIED`.
- **سياسة ما بعد الدفع:** `stock_policy = allow_oversell` لمسار البوابة فقط — `lavkoo:app/Utils/OrderManager.php:1572` و`lavkoo:app/Utils/module-helper.php:163-167`. `VERIFIED`.
- **الإرجاع/إعادة الخصم عند تغيّر الحالة:** قلب شرطي لعلم `order_details.is_stock_decreased` كحارس idempotency ثم زيادة/إنقاص ذري — `lavkoo:app/Utils/OrderManager.php:95-154`؛ ونسخة مطابقة في `lavkoo:app/Repositories/OrderRepository.php:637-698`. `VERIFIED`.
- **مواضع خصم أخرى:** POS الإدارة `lavkoo:app/Http/Controllers/Admin/POS/POSOrderController.php:186-191`؛ تعديل الطلب `lavkoo:app/Traits/OrderEditManager.php:271-278`. طلبات القنوات لا تمسّ مخزون المتجر — `lavkoo:app/Services/SalesChannels/Dispatch/ChannelOrderMarker.php:18-29`. `VERIFIED`.
- **نقاط التسليم إلى التنفيذ (يغطيها ملحق آخر):** (1) `OrderItemOwnershipSnapshotter::capture` داخل المعاملة — `lavkoo:app/Utils/OrderManager.php:1367-1377`؛ (2) `FulfillmentHooks::ordersPlaced` بعد الالتزام — `lavkoo:app/Utils/OrderManager.php:1764`؛ (3) `FulfillmentHooks::orderChanged` من `OrderRepository::update` — `lavkoo:app/Repositories/OrderRepository.php:526-528`؛ (4) `FulfillmentOrderGuard::statusChangeBlockedReason` قبل تغيير الحالة — `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:684-690`؛ (5) `CheckoutCodGuard`؛ (6) خطّاف `DeliverySyria` المضمَّن داخل `generateOrder` ويُفعَّل فقط إذا كان اسم طريقة الشحن حرفياً `DeliverySyria` — `lavkoo:app/Utils/OrderManager.php:1781-1829`. `VERIFIED`.

### 2.6 دورة الحالة ومن يغيّر ماذا

قائمة الحالات: `pending, confirmed, processing, out_for_delivery, delivered, returned, failed, canceled` — `lavkoo:app/Utils/order.php:34-46`. لا توجد آلة حالات مركزية؛ ستة مسارات مستقلة:

| الفاعل | المسار | الحالات المسموحة | نطاق الملكية | الدليل |
|---|---|---|---|---|
| العميل (ويب) | `order_cancel` | `canceled` فقط إذا COD و`pending` | `customer_id` | `lavkoo:app/Http/Controllers/Web/UserProfileController.php:822-843` |
| العميل (API) | `order_cancel` | الشرط نفسه؛ لا يكتب سجل حالة | `customer_id` | `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:143-169` |
| المشرف | `updateStatus` / `bulkUpdateStatus` | المسار الفردي والجماعي؛ الجماعي مقيّد بقائمة من خمس حالات، وتفاصيل المسار الفردي في الملحق المقيّد | بلا نطاق | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:624-692`، `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:79` |
| البائع (لوحة) | `updateStatus` | مع حراسات الدفع | `seller_id` | `lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:568-650` |
| البائع (API v3) | `order_detail_status` | يرفض إن كان `delivered` | `seller_id` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:369-425` |
| مندوب التوصيل | `update_order_status` | `delivered,canceled,returned,out_for_delivery` | `delivery_man_id` | `lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:144-232` |

SEC-E09-08: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E09-09: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

- **سجل الحالة:** `order_status_histories` عبر `CommonTrait::add_order_status_history` — `lavkoo:app/Traits/CommonTrait.php:29-40`؛ أول سجل عند الإنشاء — `lavkoo:app/Utils/OrderManager.php:1662`.
- **تعديل الطلب والمبالغ الجزئية:** `order_edit_histories` يحمل `order_due_amount` و`order_return_amount` وحالتيهما — `lavkoo:database/migrations/2026_01_11_211839_create_order_edit_histories_table.php:23-36`؛ وعلى الطلب `edit_due_amount` و`edit_return_amount` و`init_order_amount`. إرجاع الفرق للمحفظة أو يدوياً — `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:247-288`؛ سداد المستحق بالمحفظة — `lavkoo:app/Traits/OrderEditManager.php:711-737`. `VERIFIED`.

### 2.7 القيود المالية عند التسليم والإلغاء والاسترجاع

| الحدث | القيد | الدليل |
|---|---|---|
| إنشاء طلب مدفوع مسبقاً (غير COD/offline) | صف `order_transactions` بحالة `hold`، وزيادة `admin_wallets.pending_amount` لـ `admin_id = 1` | `lavkoo:app/Utils/OrderManager.php:2135-2170` |
| إنشاء أي طلب لبائع | لقطة `order_item_commissions` + قيدان `vendor_ledger_entries` (أرباح وعمولة) بحالة pending ونافذة استحقاق | `lavkoo:app/Utils/OrderManager.php:1168-1200` |
| `delivered` — COD/offline | إدراج `order_transactions` بحالة `disburse`؛ `commission_earned`، `delivery_charge_earned`، `inhouse_earning` أو (`commission_given`، `collected_cash`/`total_earning`)، `total_tax_collected` | `lavkoo:app/Utils/OrderManager.php:282-353` |
| `delivered` — مدفوع مسبقاً | `order_transactions.status = disburse`؛ إنقاص `pending_amount`؛ بقية الأرصدة كما سبق | `lavkoo:app/Utils/OrderManager.php:354-413` |
| قسيمة على طلب بائع | صف `transactions` بـ `payment_for = coupon_discount` وتعديل `total_earning` إن كان الحامل `inhouse` | `lavkoo:app/Utils/OrderManager.php:180-210` |
| شحن مجاني على طلب بائع | تسوية `delivery_charge_earned` بين المحفظتين + صف `transactions` | `lavkoo:app/Utils/OrderManager.php:213-266` |
| أجر المندوب | `deliveryman_wallets.current_balance` و`cash_in_hand` | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:738-755` |
| `canceled` | إرجاع المخزون فقط؛ استرداد المحفظة لطلب POS المدفوع بالمحفظة حصراً | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:691-708` |
| استرجاع `refunded` | إنقاص `inhouse_earning` أو `total_earning` بمبلغ الاسترجاع؛ صف `refund_transactions`؛ عكس العمولة على دفتر البائع | `lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:133-155` |
| وجهة مال الاسترجاع | إلى محفظة العميل إن `wallet_add_refund = 1` و`payment_method = customer_wallet`؛ وإلا يُسجَّل `payment_info` نصياً (تحويل يدوي). لا نداء بوابة في هذا المسار | `lavkoo:app/Services/RefundStatusService.php:81-89`، `lavkoo:app/Services/RefundTransactionService.php:7-23` |

- **الاسترجاع الجزئي:** الطلب يُسترجع **سطراً سطراً** (`refund_requests.order_details_id`)، والمبلغ = مجموع السطر + ضريبته − خصمه − حصته من القسيمة والإحالة، ولا يشمل الشحن — `lavkoo:app/Utils/OrderManager.php:2714-2737`. نافذة الاسترجاع لكل فئة عبر `CategoryGovernanceService` — `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:547-555`. `VERIFIED`.
- **منطق المحفظة مكرّر في صنفين:** `OrderManager::getWalletManageOnOrderStatusChange` (يستدعيه API البائع والمندوب) و`OrderRepository::manageWalletOnOrderStatusChange` (تستدعيه لوحتا المشرف والبائع) — `lavkoo:app/Utils/OrderManager.php:156-414` و`lavkoo:app/Repositories/OrderRepository.php:700-1006`. كلاهما قراءة-تعديل-حفظ، ولا تُفتح معاملة ولا يُؤخذ قفل داخل الدالتين ولا في الدوال الست المستدعية لهما (`lavkoo:app/Http/Controllers/Vendor/Order/OrderController.php:741`، `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:769`، `lavkoo:app/Http/Controllers/RestAPI/v2/seller/OrderController.php:229`، `lavkoo:app/Http/Controllers/RestAPI/v2/delivery_man/DeliveryManController.php:232`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:424`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:722`). `VERIFIED`.
- **العمولة:** نظامان متوازيان — المجمّع `orders.admin_commission` (نسبة البائع `sellers.sales_commission_percentage` وإلا الإعداد العام) — `lavkoo:app/Utils/Helpers.php:639-652`؛ والسطري `CommissionEngine` بأولوية product > vendor > category > global — `lavkoo:app/Services/Marketplace/CommissionEngine.php:46-51` و`lavkoo:app/Services/Marketplace/CommissionEngine.php:103-114`، مع رجوع للنسبة القديمة إن لم توجد قواعد — `lavkoo:app/Services/Marketplace/CommissionEngine.php:158-170`. المحافظ تُرحَّل من المجمّع، ودفتر البائع من السطري. `VERIFIED`.

### 2.8 الفواتير

- **التوليد:** عند الطلب (on demand) بـ mPDF، لا يُخزَّن مستند: المشرف — `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:605-622`؛ العميل — `lavkoo:app/Http/Controllers/Web/UserProfileController.php:935-954`؛ البائع — `lavkoo:app/Services/SellerInvoiceService.php:41-52`؛ ونسخة تُكتب لملف `invoices/order_invoice_<id>.pdf` للمراسلات — `lavkoo:app/Utils/OrderManager.php:2769-2775` و`lavkoo:app/Traits/PdfGenerator.php:24-45`. `VERIFIED`.
- **الترقيم:** رقم الفاتورة = `orders.id` (يُطبع «Order_Id #») — `lavkoo:resources/views/admin-views/order/invoice.blade.php:555`؛ لا تسلسل فواتير مستقل، ولا إشعار دائن لفواتير البيع (مفهوم «الإشعار الدائن» موجود في وحدة تكاليف التشغيل لفواتير الموردين، وهو خارج هذا النطاق — `lavkoo:app/Services/OperationalCosts/Invoices/InvoiceService.php:43`). `VERIFIED` (بحث `credit_note|creditnote|invoice_number|invoice_no` في `app` و`resources` و`Modules` و`routes` و`database`؛ ورد `invoice_no` في أربعة مواضع من خطّاف DeliverySyria تُرسل رقم الطلب نفسه: `lavkoo:app/Utils/OrderManager.php:1905`، `lavkoo:app/Utils/OrderManager.php:1930`، `lavkoo:app/Utils/OrderManager.php:1966`، `lavkoo:app/Utils/OrderManager.php:1998`).
- **الحقول القانونية ومصدرها:** العنوان من الإعداد العام `shop_address`؛ «هوية النشاط» حقل حرّ (تسمية + قيمة) من `invoice_settings.business_identity` و`business_identity_value` — `lavkoo:resources/views/admin-views/order/invoice.blade.php:527-532`؛ الهاتف والبريد من `company_phone` و`company_email` في التذييل — `lavkoo:app/Traits/PdfGenerator.php:47-68`؛ رقم ضريبي للبائع من `sellers.gst` — `lavkoo:resources/views/admin-views/order/invoice.blade.php:516-521`. كلها تُقرأ **وقت الطباعة** من الإعدادات الحية، لا من لقطة على الطلب. `VERIFIED`.
- **العملة على الفاتورة:** نسخة المشرف بالعملة الافتراضية الحالية — `lavkoo:resources/views/admin-views/order/invoice.blade.php:3` و`lavkoo:resources/views/admin-views/order/invoice.blade.php:574-580`؛ نسخة العميل بعملة جلسة المتصفح عبر `webCurrencyConverter` — `lavkoo:resources/themes/default/web-views/order/invoice.blade.php:585` و`lavkoo:app/Utils/currency.php:113-130`. `VERIFIED`.
- **الفوترة الإلكترونية:** البحث عن `zatca` (بلا حساسية للحالة) في `app`، `resources/views`، `resources/themes`، `Modules`، `config`، `routes`، `database` أعاد صفر ملفات؛ وبحث `fatoora|einvoice|e_invoice|e-invoice` (بلا حساسية للحالة) لم يُظهر إلا اسم بوابة الدفع `fatoorah` في `lavkoo:app/Utils/Helpers.php:283` و`lavkoo:app/Enums/GlobalConstant.php:508`؛ ولا رمز QR في قالبي الفاتورة (`lavkoo:resources/views/admin-views/order/invoice.blade.php` و`lavkoo:resources/themes/default/web-views/order/invoice.blade.php`، بحث `qr` بلا حساسية للحالة). `VERIFIED` (غياب في الكود؛ وجود حل خارجي `UNKNOWN`).

### 2.9 الضريبة (Modules/TaxModule)

- **التصميم:** `system_tax_setups` (نوع `order_wise|product_wise|category_wise`، `tax_payer`، `is_included`، `is_default`، `country_code`) + `taxes` (اسم، نسبة، `country_code`) + `tax_additional_setups` (ضرائب إضافية مثل الشحن) + `taxables` (ربط منتج/فئة) + `order_taxes` (لقطة) — `lavkoo:Modules/TaxModule/database/migrations/2025_05_26_115043_create_system_tax_setups_table.php:18-24` و`lavkoo:Modules/TaxModule/database/migrations/2025_05_26_115643_create_taxes_table.php:20`. `VERIFIED`.
- **ما يستخدمه الدفع فعلياً:** إعداد واحد فقط: `SystemTaxSetup` النشط حيث `tax_payer = 'vendor'` و`is_default = 1`، مخزَّن في cache 30 دقيقة بمفتاح عام `tax_system_type_<payer>_<with_vat|no_vat>` — `lavkoo:Modules/TaxModule/app/Traits/VatTaxManagement.php:15-33`. لا يُمرَّر أي رمز دولة. `VERIFIED`.
- **`country_type` ثابت `single`** في إعداد الوحدة — `lavkoo:Modules/TaxModule/config/config.php:16`؛ وعند `single` يُكتب `country_code = null` — `lavkoo:Modules/TaxModule/app/Services/SystemTaxSetupService.php:18`. الخدمة `CalculateTaxService::getCalculatedTax` تقبل `$countryCode` و`$storeId` — `lavkoo:Modules/TaxModule/app/Services/CalculateTaxService.php:16-33` — لكنها لا تُستدعى إلا من متحكم API الوحدة نفسها (بحث `CalculateTaxService` خارج ملفها أعاد `Modules/TaxModule/app/Http/Controllers/Api/V1/TaxController.php` فقط). `VERIFIED`.
- **الوضع الشامل للضريبة:** إذا `is_included` صحيحاً تعيد دوال السلة صفراً وقائمة فارغة — `lavkoo:app/Utils/CartManager.php:1012-1016` و`lavkoo:app/Utils/CartManager.php:1043-1047`؛ فتُكتب `total_tax_amount = 0` ولا تُنشأ صفوف `order_taxes`، ويُحفظ فقط `tax_model = 'include'` — `lavkoo:app/Utils/OrderManager.php:1084-1086`. أي أن مبلغ الضريبة المتضمَّن **لا يُحتسب ولا يُسجَّل**. `VERIFIED` (أي الوضعين مفعّل في الإنتاج `UNKNOWN`).
- **ضريبة الشحن:** من `additionalData` للإعداد، تُوزَّع على الأسطر المادية بنسبة قيمتها — `lavkoo:app/Utils/CartManager.php:1078-1098`. `VERIFIED`.

### 2.10 ترقيم الطلبات، POS، المنتجات الرقمية

- **`orders.id` يختاره التطبيق لا قاعدة البيانات:** أساس `100001` ثم (أكبر معرّف + 1) مع `lockForUpdate` — `lavkoo:app/Utils/OrderManager.php:799-807`؛ وإعادة المحاولة عند تصادم المفتاح — `lavkoo:app/Utils/OrderManager.php:1736-1744`. `VERIFIED`.
- **خمسة مواضع إنشاء تتشارك التسلسل نفسه بخوارزميتين:** المتجر/API وطلبات القنوات عبر `generateNewOrderID` (مقفلة بـ `lockForUpdate`)، وثلاثة مواضع POS تعتمد على عدّ الصفوف بلا قفل: POS الإدارة `100000 + count + 1` — `lavkoo:app/Http/Controllers/Admin/POS/POSOrderController.php:135-138`؛ POS البائع — `lavkoo:app/Http/Controllers/Vendor/POS/POSOrderController.php:145-148`؛ POS تطبيق البائع — `lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:118-122`. طلبات القنوات تستعمل `generateNewOrderID` — `lavkoo:app/Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php:131`. `VERIFIED`.
- **`order_group_id`:** نص `rand(1000,9999)-<5 أحرف>-time()` — `lavkoo:app/Utils/OrderManager.php:65-68`؛ القيمة الافتراضية للعمود `def-order-group` — `lavkoo:database/migrations/2021_10_04_153201_add_col_to_order_table.php:17`؛ مفهرس غير فريد — `lavkoo:database/migrations/2026_02_25_163505_add_indexes_to_orders_table.php:76-77`. `VERIFIED`.
- **POS:** `order_type = 'POS'` — `lavkoo:app/Services/OrderService.php:147`؛ يُنشأ عبر `OrderRepository::add` لا عبر `generateOrder` — `lavkoo:app/Repositories/OrderRepository.php:44-59`.
- **الرقمي:** يُعفى من الشحن وضريبته — `lavkoo:app/Utils/OrderManager.php:1031` و`lavkoo:app/Utils/OrderManager.php:1045-1047`؛ خصم مخزونه غير مشروط — `lavkoo:app/Utils/OrderManager.php:1357-1359`؛ لا يُسمح بـ COD لسلة بلا منتج مادي — `lavkoo:app/Http/Controllers/Web/WebController.php:549-559`.

### 2.11 الـ Idempotency والحماية من السباقات

| الخطر | الحماية الموجودة | الدليل |
|---|---|---|
| تزامن خصم المخزون | أقفال مرتبة + خصم مشروط + إعادة محاولة | `lavkoo:app/Utils/OrderManager.php:1596-1602`، `lavkoo:app/Utils/OrderManager.php:1345-1356` |
| تصادم `orders.id` | قفل القراءة + إعادة المعاملة حتى 3 مرات | `lavkoo:app/Utils/OrderManager.php:1580-1744` |
| استرجاع المخزون مرتين | علم `is_stock_decreased` شرطي | `lavkoo:app/Utils/OrderManager.php:118-123` |
| تكرار لقطة العمولة | `updateOrCreate` على `order_details_id` | `lavkoo:app/Utils/OrderManager.php:1143-1145` |
| تكرار طلب البوابة | `transaction_ref` يُفحص في تسوية MyFatoorah (خارج النطاق) | `lavkoo:app/Services/MyFatoorah/PaymentSettlementService.php:198-209` |
| إرسال وضع الطلب (COD/offline/محفظة) | **لا مفتاح idempotency** على وضع الطلب؛ لوحة المراقبة تسجّل ذلك صراحةً | `lavkoo:app/Services/Monitoring/Panels/OrderIntegrityPanel.php:1375-1379` |

SEC-E09-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E09-07: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

ملاحظة: تفاصيل تعريف مسار وضع الطلب عبر الـ API محالة إلى الملحق المقيّد (انظر `SEC-E09-05` في §6). `VERIFIED` (للإحالة).

**الاختبارات الآلية:** البحث عن `generateOrder(` في `tests/` أعاد ملفاً واحداً (`tests/Feature/Address/ApiOrderPlacementTest.php`)، وهو يتحقق من مجاميع الطلب عبر الـ API (`order_amount` و`shipping_cost` و`discount_amount` و`total_tax_amount`) في حالات محدودة. البحث عن `getWalletManageOnOrderStatusChange|manageWalletOnOrderStatusChange|RefundController|updateRefundStatus` و`CartManager::` أعاد صفراً في ما يخص الترحيل والاسترجاع الداخلي؛ واختبار `tests/Feature/MyFatoorah/RefundAndAdminTest.php` يغطي استرجاعات البوابة فقط. مجلد `tests/Feature/Concurrency` (9 ملفات) يغطي المخزون المشترك والقنوات و MyFatoorah لا مجاميع الطلب ولا القيود المالية. `VERIFIED` (لم تُشغَّل).

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الدليل | الحالة |
|---|---|---|---|
| A1 | فضاء سلة واحد لكل عميل؛ لا بُعد متجر/دولة في `carts` ولا في مفتاح cache السلة | `lavkoo:app/Utils/CartManager.php:163-180`، `lavkoo:app/Utils/ProductManager.php:2584` | VERIFIED |
| A2 | البائع الداخلي كيان واحد ثابت: `seller_id = 1`، `seller_is = 'admin'` | `lavkoo:app/Utils/CartManager.php:504-505`، `lavkoo:app/Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php:142-143` | VERIFIED |
| A3 | محفظة منصة واحدة: `admin_wallets` بـ `admin_id = 1` مكتوبة حرفياً | `lavkoo:app/Utils/OrderManager.php:809-823`، `lavkoo:app/Utils/OrderManager.php:2168`، `lavkoo:app/Traits/OrderEditManager.php:720` | VERIFIED |
| A4 | محفظة واحدة لكل بائع بلا عملة ولا كيان | `lavkoo:app/Models/SellerWallet.php:28-39` | VERIFIED |
| A5 | عملة واحدة ضمنية لكل المبالغ؛ التحويل للعرض بإعدادات عامة وسعر الصرف الجاري | `lavkoo:app/Utils/currency.php:76-104` | VERIFIED |
| A6 | خانتان عشريتان في العمولة المجمّعة وخصم المحفظة وأسعار المناطق | `lavkoo:app/Utils/Helpers.php:649`، `lavkoo:app/Utils/Convert.php:62`، `lavkoo:app/Models/ShippingZone.php:19-27` | VERIFIED |
| A7 | إعداد ضريبي افتراضي واحد للنظام كله، و`country_type = single` | `lavkoo:Modules/TaxModule/app/Traits/VatTaxManagement.php:27-33`، `lavkoo:Modules/TaxModule/config/config.php:16` | VERIFIED |
| A8 | إعدادات الشحن عامة: `shipping_method`، `free_delivery_*`، `minimum_order_amount*`، قائمة دول التوصيل | `lavkoo:app/Utils/OrderManager.php:2529-2531`، `lavkoo:app/Utils/OrderManager.php:2653-2656` | VERIFIED |
| A9 | هوية الفاتورة القانونية من إعدادات عامة واحدة (`company_*`، `shop_address`، `invoice_settings`) | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:607-613` | VERIFIED |
| A10 | تسلسل أرقام طلبات واحد عام يبدأ من 100001 ويُستخدم رقماً للفاتورة | `lavkoo:app/Utils/OrderManager.php:799-807` | VERIFIED |
| A11 | حدود القسيمة و«أول طلب» والإحالة تُعدّ على مستوى العميل كله | `lavkoo:app/Utils/OrderManager.php:511-512`، `lavkoo:app/Utils/OrderManager.php:539`، `lavkoo:app/Utils/OrderManager.php:830` | VERIFIED |
| A12 | قواعد العمولة بنطاقات global/category/vendor/product فقط | `lavkoo:app/Services/Marketplace/CommissionEngine.php:109-114` | VERIFIED |
| A13 | أثر سوق سابق مضمَّن في مسار الطلب: خطّاف `DeliverySyria` وتعليقات «Pharmacy Syria» | `lavkoo:app/Utils/OrderManager.php:1766-1779`، `lavkoo:app/Utils/OrderManager.php:1874-1875` | VERIFIED |

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Provisional: single-store / single-selling-entity checkout | الطلب مقسَّم أصلاً إلى «طلب لكل مجموعة» تحت `order_group_id`، وكل دوال الحساب تعمل على مجموعة واحدة؛ يمكن جعل المجموعة حدّاً للكيان | الدفع يأخذ **كل** المجموعات المحددة للعميل معاً ويحصّلها بدفعة واحدة؛ لا شيء يمنع سلة تجمع كيانين؛ `order_group_id` لا يحمل معنى كيان | `lavkoo:app/Utils/OrderManager.php:887-902`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:379-383` |
| GCC-13 (سلال/طلبات/تدفقات مالية منفصلة لكل متجر) | `cart_group_id` و`is_checked` يسمحان بتجزئة السلة | لا بُعد متجر في `carts` ولا `orders`؛ 26 موضعاً تعتمد `get_cart_group_ids` و23 ملفاً تستعلم `Cart::` مباشرة؛ الدمج عند الدخول ومفتاح cache بلا متجر | `lavkoo:app/Utils/CartManager.php:163-180`، `lavkoo:app/Utils/CartManager.php:23-50` |
| Foundation: separate legal selling company per country (فواتير/ضرائب/تسويات) | لقطات العنوان والمنتج والعمولة السطرية؛ `order_item_commissions.currency` و`vendor_ledger_entries.currency` | لا لقطة كيان قانوني ولا عملة على الطلب؛ الفاتورة تُبنى من إعدادات حية؛ محفظة منصة واحدة `admin_id = 1` | `lavkoo:app/Models/Order.php:75-133`، `lavkoo:resources/views/admin-views/order/invoice.blade.php:527-532`، `lavkoo:app/Utils/OrderManager.php:2168` |
| Provisional: local pricing, no auto-FX | الأسعار تُنسخ إلى `carts.price` ثم `order_details.price` كما هي | العرض والفواتير ومبلغ البوابة تمر عبر محوّلات عملة عامة بسعر الصرف الجاري | `lavkoo:app/Utils/currency.php:76-104`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-423` |
| Provisional: wallets/ledgers separate by store/entity/currency | دفتر البائع الجديد يحمل `currency` لكل قيد | `admin_wallets`/`seller_wallets`/`order_transactions`/`transactions` بلا كيان ولا عملة، وتُحدَّث بقراءة‑تعديل‑حفظ | `lavkoo:app/Models/AdminWallet.php:39-47`، `lavkoo:app/Models/VendorLedgerEntry.php:30-34` |
| GCC-16 (شحن محلي وعابر للحدود حسب الأهلية) | نقطة تحقق موحّدة قبل الدفع؛ `shipping_zones` حسب الدولة/المنطقة؛ قائمة دول التوصيل | لا فحص أهلية منتج×وجهة في أي مسار (`OrderEligibility` يفحص حالة الطلب والإيقاف والدفع فقط)؛ قيد الدولة يعتمد على قائمة عامة ويُطبَّق على عناوين محددة في مسارات الـ API (انظر `SEC-E09-10`)؛ المناطق ويب فقط | `lavkoo:app/Utils/OrderManager.php:2778-2931`، `lavkoo:app/Services/Fulfillment/OrderEligibility.php:30-57`، `lavkoo:app/Http/Controllers/Customer/SystemController.php:87-102` |
| GCC-17 (قواعد قسائم محلية) | القسيمة تُوزَّع على المجموعات ولها حامل | حدود الاستخدام و«أول طلب» تُحسب على كل طلبات العميل | `lavkoo:app/Utils/OrderManager.php:511-545` |
| GCC-12 (إيقاف متجر دون كسر الطلبات والاسترجاع) | مسارات الحالة والاسترجاع لا تعتمد على سياق واجهة المتجر | لا مفهوم متجر في الطلب أصلاً ليُستثنى؛ الفاتورة تقرأ الإعدادات الحية | `lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:109-175` |
| GCC-14 (نطاقات بيانات الموظفين) | نطاق البائع مطبّق في مسارات البائع | مسارات المشرف على الطلبات والاسترجاع بلا أي نطاق بيانات | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:631` |
| GCC-19 / Guardrail 2 (استمرارية معرّفات وفواتير السعودية) | المعرّفات أرقام بسيطة قابلة للحفظ كما هي | تسلسل واحد عام؛ أي متجر جديد سيتشارك نطاق الأرقام نفسه ورقم الفاتورة نفسه | `lavkoo:app/Utils/OrderManager.php:799-807` |
| ملاحظة الخانات الثلاث (KWD/BHD/OMR) | التخزين `DECIMAL(40,20)` بعد ترحيل التوسعة | تقريب ثابت لخانتين في ثلاثة مواضع مالية + إعداد عرض عام واحد | `lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:56-68`، `lavkoo:app/Utils/Helpers.php:649` |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | الإضافة الدنيا الموصى بها |
|---|---|---|---|
| معاملة `generateOrder` (أقفال، خصم مشروط، إعادة محاولة، `stock_policy`) | Preserve | أقوى جزء في المسار ومصدر تصحيحات إنتاجية موثّقة | لا تغيير في الترتيب؛ إضافة حقول اللقطة داخل المعاملة نفسها |
| حارس `is_stock_decreased` لاسترجاع المخزون | Preserve | idempotent ومكرَّر بتطابق في موضعين | — |
| نقاط التسليم للتنفيذ (`OrderItemOwnershipSnapshotter`, `FulfillmentHooks`, `FulfillmentOrderGuard`, `CheckoutCodGuard`) | Preserve | عقد قائم مع ملحق التنفيذ | — |
| لقطات العنوان و`product_details` و`order_taxes` | Preserve | سجلات تاريخية سعودية | — |
| `order_item_commissions` / `vendor_ledger_entries` / `RefundReversalService` | Extend | تحمل `currency` وهي غير قابلة للتعديل بعد الكتابة | إضافة مرجع الكيان القانوني/المتجر بجانب العملة |
| `orders` / `order_details` | Extend | لا عملة ولا كيان ولا متجر | أعمدة لقطة قابلة للـ NULL (متجر، كيان قانوني، رمز عملة، عدد الخانات، هوية البائع القانونية) تُملأ للطلبات الجديدة؛ القديمة تُعامَل ضمنياً SA/SAR بعد مطابقة |
| `carts` / `cart_shippings` و`get_cart_group_ids` | Extend | نقطة تجميع واحدة يمكن تقييدها | بُعد متجر على الصف، وتقييده في `get_cart_group_ids` وفي الاستعلامات المباشرة المحصورة (23 ملفاً) ومفتاح cache والدمج |
| `checkValidationForCheckoutPages` | Extend | النقطة الطبيعية لفحوص GCC | حارس «كيان واحد لكل دفع» + خطّاف أهلية منتج×وجهة (GCC-16) |
| TaxModule | Extend | أعمدة `country_code` موجودة وغير مستعملة في الدفع | اختيار الإعداد حسب سياق المتجر؛ مفتاح cache مقيّد بالسوق؛ حساب وتسجيل الضريبة المتضمَّنة |
| توليد الفاتورة | Refactor | مستند غير ثابت يُبنى من إعدادات حية | لقطة فاتورة ثابتة (كيان، رقم ضريبي، عملة، مجاميع) وتسلسل لكل كيان مع إبقاء الأرقام السعودية الحالية |
| ترحيل المحافظ (`admin_wallets`/`seller_wallets`) في صنفين | Refactor | مكرَّر، بلا معاملة ولا قفل، ومفتاح `admin_id = 1` | توحيد في خدمة واحدة معاملاتية قبل إضافة أي بُعد كيان |
| ترقيم الطلبات | Extend | ثلاث خوارزميات على تسلسل واحد | مولّد واحد مقفل؛ نطاقات/بادئات عرض لكل متجر دون تغيير `orders.id` السعودي |
| مفتاح idempotency للدفع | New | غير موجود | رمز دفع فريد على الطلب يُولَّد قبل الإرسال |
| سياق المتجر/الكيان في الدفع | New | غير موجود | يعتمد على مخرجات ملحق سياق المتجر |
| خطّاف `DeliverySyria` وكتابات التصحيح | Refactor | بقايا سوق آخر داخل المسار الحرج | عزل خلف واجهة مزوّد وإزالة كتابة ملف التصحيح (بعد موافقة) |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E09-F01 | لا لقطة عملة ولا سعر صرف على `orders`/`order_details`/`order_transactions`/`transactions`/`refund_*`؛ المبالغ تُفسَّر وقت العرض بإعدادات العملة العامة وسعر الصرف الجاري | P0 | VERIFIED | `lavkoo:app/Models/Order.php:75-133`، `lavkoo:app/Utils/currency.php:76-104`، `lavkoo:resources/views/admin-views/order/invoice.blade.php:580` | عند إضافة عملة ثانية تصبح المبالغ التاريخية والجديدة غير قابلة للتمييز؛ خطر تشويه بيانات السعودية والتقارير | عمود رمز عملة (وعدد الخانات) على الطلب وسطوره وقيوده، يُملأ داخل `generateOrder`؛ ترحيل تعبئة للسجلات القديمة بعد التحقق من `currency_model` الإنتاجي |
| E09-F02 | لا بُعد متجر/كيان قانوني على السلال والطلبات والقيود؛ البائع الداخلي `seller_id = 1` ومحفظة المنصة `admin_id = 1` ثابتان | P0 | VERIFIED | `lavkoo:app/Utils/CartManager.php:504-505`، `lavkoo:app/Utils/OrderManager.php:809-823`، `lavkoo:app/Utils/OrderManager.php:2168` | إيرادات وضرائب شركات مختلفة تتراكم في رصيد واحد؛ تسرّب بين الكيانات؛ استحالة تسوية لكل كيان | لا يُعاد توظيف `seller_id`؛ يُضاف مرجع كيان/متجر مستقل على الطلب والقيد، ومحفظة منصة لكل كيان |
| E09-F03 | الفاتورة ليست مستنداً قانونياً ثابتاً: رقمها رقم الطلب، تُولَّد عند الطلب من إعدادات حية، بلا لقطة كيان/رقم ضريبي، ونسخة العميل بعملة جلسة المتصفح؛ لا إشعار دائن للاسترجاع؛ لا أثر لفوترة إلكترونية | P0 | VERIFIED | `lavkoo:resources/views/admin-views/order/invoice.blade.php:527-532`، `lavkoo:resources/views/admin-views/order/invoice.blade.php:555`، `lavkoo:resources/themes/default/web-views/order/invoice.blade.php:585`، `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:605-622` | تغيير إعداد أو إضافة كيان يعيد كتابة فواتير قديمة؛ تعرّض ضريبي وقانوني في كل دولة | لقطة فاتورة عند الإنشاء وتسلسل لكل كيان قانوني؛ إبقاء رقم الطلب السعودي مرجعاً |
| E09-F04 | فضاء سلة واحد لكل عميل، ومفتاح cache ودمج الضيف بلا متجر | P1 | VERIFIED | `lavkoo:app/Utils/CartManager.php:163-180`، `lavkoo:app/Utils/CartManager.php:23-50`، `lavkoo:app/Utils/ProductManager.php:2584` | سلال المتاجر تختلط؛ GCC-13 غير محقق | بُعد متجر في `carts` وتقييد نقطة التجميع والاستعلامات المباشرة |
| E09-F05 | الدفع يجمع كل المجموعات المحددة ويحصّلها بدفعة واحدة؛ لا حارس كيان واحد | P1 | VERIFIED | `lavkoo:app/Utils/OrderManager.php:887-902`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:379-383`، `lavkoo:app/Http/Controllers/Web/WebController.php:881` | دفعة واحدة قد تغطي طلبات كيانين؛ يخالف قيد الدفع بكيان واحد | حارس في `checkValidationForCheckoutPages`؛ إبقاء تقسيم البائعين داخل الكيان الواحد كما هو (توافق خلفي) |
| E09-F06 | الضريبة: إعداد افتراضي واحد عام، `country_type = single`، cache عام؛ والوضع الشامل يسجّل ضريبة صفرية بلا `order_taxes` | P1 | VERIFIED | `lavkoo:Modules/TaxModule/app/Traits/VatTaxManagement.php:15-33`، `lavkoo:Modules/TaxModule/config/config.php:16`، `lavkoo:app/Utils/CartManager.php:1043-1047` | نسب VAT مختلفة لكل دولة غير ممكنة؛ فواتير بلا مبلغ ضريبة في الوضع الشامل | اختيار الإعداد بسياق المتجر (العمود `country_code` موجود)، وحساب الضريبة المتضمَّنة وتسجيلها |
| E09-F07 | تسلسل `orders.id` واحد عام بخوارزميتين، وثلاثة مواضع POS منها بلا قفل، ويُستخدم رقم فاتورة | P1 | VERIFIED | `lavkoo:app/Utils/OrderManager.php:799-807`، `lavkoo:app/Http/Controllers/Admin/POS/POSOrderController.php:135-138`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:118-122` | تصادم معرّفات بين POS والمتجر؛ لا ترقيم لكل كيان | مولّد واحد مقفل؛ رقم عرض/فاتورة لكل متجر فوق المعرّف الداخلي |
| E09-F08 | دفترا أستاذ متوازيان وعمولتان (مجمّعة بخانتين، وسطرية بأربع)، وترحيل المحافظ مكرّر في صنفين بلا معاملة ولا قفل | P1 | VERIFIED | `lavkoo:app/Utils/OrderManager.php:156-414`، `lavkoo:app/Repositories/OrderRepository.php:700-760`، `lavkoo:app/Utils/Helpers.php:639-652`، `lavkoo:app/Utils/OrderManager.php:1168-1200` | فروق بين الدفترين؛ ضياع تحديثات متزامنة؛ تعقيد مطابقة الأرصدة السعودية | توحيد الترحيل معاملاتياً وتحديد الدفتر المرجعي قبل تقسيمه بالكيانات |
| E09-F09 | إلغاء طلب مدفوع مسبقاً لا يعكس `pending_amount` ولا صف `order_transactions` (`hold`)، ولا استرداد تلقائي إلا لـ POS بالمحفظة | P1 | VERIFIED (حُصرت كاتبات `pending_amount` بـ grep في `app` و`Modules`: ستة مواضع هي إنشاء الطلب والتسليم وتعديل الطلب ورد فرق الإرجاع والسداد؛ مسار الإلغاء لا يعكسها) | `lavkoo:app/Utils/OrderManager.php:2168`، `lavkoo:app/Utils/OrderManager.php:377`، `lavkoo:app/Repositories/OrderRepository.php:967`، `lavkoo:app/Traits/OrderEditManager.php:720`، `lavkoo:app/Utils/module-helper.php:274`، `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:273`، `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:696-708` | رصيد معلّق متضخم تاريخياً؛ يجب مطابقته قبل أي فصل للكيانات | مطابقة تاريخية وقيد عكسي عند الإلغاء |
| E09-F10 | تقريب ثابت لخانتين في العمولة المجمّعة وخصم المحفظة وأسعار المناطق، وإعداد عرض عشري عام واحد | P1 | VERIFIED | `lavkoo:app/Utils/Helpers.php:649`، `lavkoo:app/Utils/Convert.php:62`، `lavkoo:app/Services/Marketplace/ShippingRateService.php:66`، `lavkoo:app/Utils/currency.php:101-103` | أخطاء فلس في KWD/BHD/OMR | عدد الخانات من عملة الطلب لا من إعداد عام |
| E09-F11 | لا فحص أهلية منتج×وجهة في الدفع؛ قيد الدولة يعتمد على قائمة عامة ويُطبَّق على عناوين محددة (انظر `SEC-E09-10`) | P1 | VERIFIED (بحث `eligib\|restricted\|cross_border` في `OrderManager` و`CartManager` لم يُظهر شيئاً؛ وبحث `eligib` في `app` و`Modules` أظهر `OrderEligibility` وهو يفحص حالة الطلب والإيقاف والدفع فقط) | `lavkoo:app/Utils/OrderManager.php:2778-2931`، `lavkoo:app/Services/Fulfillment/OrderEligibility.php:30-57`، `lavkoo:app/Traits/CommonTrait.php:95-105` | بيع منتج صحي/منظَّم لوجهة غير مسموحة | خطّاف أهلية داخل `checkValidationForCheckoutPages` ومرة ثانية داخل `generateOrder` قبل المعاملة |
| E09-F12 | لا آلة حالات مركزية؛ ستة مسارات مستقلة لتغيير الحالة (§2.6)، وتختلف آثارها الجانبية بينها | P1 | VERIFIED | `lavkoo:app/Repositories/OrderRepository.php:526-528`، `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:684-690` | تباين الآثار الجانبية بين المسارات | مسار انتقال واحد تمر به كل القنوات |
| E09-F13 | لا مفتاح idempotency لوضع الطلب؛ لوحة المراقبة تسجّل المفتاح بحالة غير مُهيّأ | P1 | VERIFIED | `lavkoo:app/Services/Monitoring/Panels/OrderIntegrityPanel.php:1375-1379` | طلبات مكررة عند إعادة الإرسال/إعادة المحاولة | رمز دفع فريد على الطلب |
| E09-F14 | حدود القسيمة و«أول طلب» والإحالة على مستوى العميل كله | P1 | VERIFIED | `lavkoo:app/Utils/OrderManager.php:511-512`، `lavkoo:app/Utils/OrderManager.php:539`، `lavkoo:app/Utils/OrderManager.php:830` | يخالف GCC-17 (حدود واسترداد لكل متجر) | تقييد العدّ بالمتجر |
| E09-F15 | جداول أساسية وجداول المرحلة 3 بلا ترحيل إنشاء متتبَّع: `orders`، `order_details`، `transactions`، `admin_wallets`، `seller_wallets`، `shipping_methods`، `order_item_commissions`، `commission_rules`، `vendor_ledger_entries`، `shipping_zones`، `return_shipments`؛ والكود يحرس بـ `Schema::hasTable` | P1 | VERIFIED (بحث `Schema::create('<table>'` و`CREATE TABLE` في `database` و`Modules` و`app` و`tests` و`installation` و`routes` و`config`: لا ترحيل إنشاء لأي من الجداول؛ يظهر `orders` و`order_details` و`shipping_methods` في ملفات دعم الاختبارات فقط؛ و`lavkoo:CLAUDE.md` يذكر `installation/backup/database.sql` وهو غير موجود في المستودع) | `lavkoo:app/Utils/OrderManager.php:1123-1125`، `lavkoo:app/Services/Marketplace/ShippingRateService.php:25-27`، `lavkoo:CLAUDE.md` | المخطط الفعلي للإنتاج غير قابل للاستنتاج من المستودع؛ خطر على أي ترحيل GCC | استخراج مخطط الإنتاج (بلا بيانات) قبل تصميم الترحيلات |
| E09-F16 | `order_transactions.order_amount` بدلالتين: للمدفوع مسبقاً يُحسب من السطور ويطرح `$order['discount']`، ولا يُعثر على عمود `discount` في ترحيلات `orders` ولا في `Order::$fillable`، ولا يشمل الشحن؛ ولـ COD = `orders.order_amount` | P2 | VERIFIED | `lavkoo:app/Utils/OrderManager.php:2139`، `lavkoo:app/Utils/OrderManager.php:296` | تقارير المعاملات غير متجانسة عند المطابقة | توحيد التعريف قبل المطابقة التاريخية |
| E09-F17 | بقايا وتصحيحات في المسار الحرج: خطّاف `DeliverySyria` بسجلات `DEBUG`، كتابة ملف `order_generate_test.txt` عند كل دفع ناجح، إسناد بدل مقارنة في `PdfGenerator` | P2 | VERIFIED | `lavkoo:app/Utils/OrderManager.php:1784-1786`، `lavkoo:app/Utils/module-helper.php:171-193`، `lavkoo:app/Traits/PdfGenerator.php:14` | ضجيج وسلوك غير مقصود | تنظيف بعد موافقة |
| E09-F18 | الاسترجاع سطري، بلا شحن، ومال الاسترجاع إلى المحفظة أو تحويل يدوي مسجَّل نصياً؛ لا ربط ببوابة في هذا المسار | INFO | VERIFIED | `lavkoo:app/Services/RefundStatusService.php:81-89`، `lavkoo:app/Utils/OrderManager.php:2714-2737` | الاسترجاع عبر الحدود/لكل كيان يحتاج وجهة مال محددة | ربط الاسترجاع بكيان الطلب وعملته |
| E09-F19 | لا اختبار آلي للقيود المالية (المحافظ والدفاتر والعمولات) ولا لاسترجاع الطلب الداخلي؛ يوجد اختبار واحد بمعاملات لمجاميع الطلب عبر الـ API | P1 | VERIFIED (بحث `admin_wallets\|seller_wallets\|AdminWallet\|SellerWallet\|vendor_ledger\|VendorLedger\|order_item_commissions\|CommissionEngine\|pending_amount\|RefundStatusService\|RefundController\|updateRefundStatus` في `tests/` لم يُظهر اختبارات ترحيل؛ `tests/Feature/MyFatoorah/RefundAndAdminTest.php` يغطي استرجاعات البوابة فقط) | `lavkoo:tests/Feature/Address/ApiOrderPlacementTest.php:101-143` | أي توسعة GCC بلا شبكة أمان للقيود | اختبارات خصائص للمجاميع والقيود قبل التنفيذ |
| E09-F20 | رد فرق تعديل الطلب إلى رصيد المنصة يقرأ المحفظة بـ `admin_id = 1` ويكتب بمفتاح `admin_id = $order['seller_id']`؛ لطلبات البائعين لا يُحدَّث صف المنصة | P1 | VERIFIED (الكود)؛ INFERRED (الأثر المالي) | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:270-274` | رصيد المنصة المعلّق لا ينخفض عند رد فرق تعديل طلب بائع؛ تضخم تاريخي غير مرصود | توحيد مفتاح المحفظة على `admin_id = 1` ومطابقة الفروق التاريخية |
| SEC-E09-01 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P0 | VERIFIED | — | — | — |
| SEC-E09-02 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | — | — | — |
| SEC-E09-03 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P1 | VERIFIED | — | — | — |
| SEC-E09-04 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | — | — | — |
| SEC-E09-05 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور. | P2 | VERIFIED | — | — | — |

### مقاربة متوافقة خلفياً للدفع بكيان واحد (اقتراح توثيقي)

1. إبقاء «طلب لكل مجموعة بائع» و`order_group_id` كما هما داخل الكيان الواحد؛ فهذا سلوك البائعين القائم (Guardrail 4).
2. إضافة بُعد المتجر على صف السلة، وجعل `get_cart_group_ids` تعيد مجموعات المتجر الحالي فقط؛ بذلك تصبح كل دوال الحساب والتحقق و`generateOrder` مقيّدة بكيان واحد دون تغيير منطقها.
3. ختم لقطة (المتجر، الكيان القانوني، العملة) على `orders` داخل `getOrderAddData`، وهي الدالة الوحيدة التي تبني صف الطلب للمتجر/API — `lavkoo:app/Utils/OrderManager.php:1059-1108`؛ مع معالجة المنشئين الثلاثة الآخرين (POS ×2، القنوات) صراحةً.
4. الطلبات السعودية القديمة: لا تُعدَّل معرّفاتها؛ تُعبَّأ اللقطة لها بقيم السعودية بعد مطابقة الأرصدة (E09-F09) والتحقق من `currency_model`.

## 7. أسئلة مفتوحة وعناصر UNKNOWN

1. قيمة `currency_model` و`system_default_currency` و`decimal_point_settings` في الإنتاج — تحدد وحدة كل المبالغ المخزّنة. يحسمها: قراءة `business_settings` الإنتاجية.
2. هل النظام الضريبي الإنتاجي شامل (`is_included`) أم لا، وما النسب المعرّفة — يحسمها: جداول `system_tax_setups` و`taxes` الإنتاجية.
3. المخطط الفعلي لجداول `orders`/`order_details`/المحافظ (هل `orders.id` بلا AUTO_INCREMENT؟ هل نُفِّذ ترحيل `DECIMAL(40,20)`؟ هل جداول المرحلة 3 موجودة؟) — يحسمها: `SHOW CREATE TABLE` من الإنتاج أو الملف `installation/backup/database.sql` غير المتتبَّع.
4. قيم `shipping_method`، `zone_wise_shipping`، `free_delivery_*`، `minimum_order_amount*`، `wallet_add_refund`، `invoice_settings` — `business_settings` الإنتاجية.
5. هل يوجد بائعون فعليون غير الإدارة في الإنتاج (أي هل تقسيم البائعين مستعمل)؟ — عدّ `orders` حسب `seller_is`.
6. حجم الفرق التاريخي في `admin_wallets.pending_amount` الناتج عن E09-F09 — استعلام مطابقة على الإنتاج.
7. هل يوجد حل فوترة إلكترونية خارج هذا المستودع — مالك المشروع.
8. المستودع القديم `pharmacyksa98`: `UNKNOWN (missing access: pharmacyksa98)`؛ لم يُقارن مسار الطلب به.

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| مجموعة السلة = (عميل، بائع) | `lavkoo:app/Utils/CartManager.php:514-519` | VERIFIED |
| البائع الداخلي `seller_id = 1` | `lavkoo:app/Utils/CartManager.php:504-505` | VERIFIED |
| دمج سلة الضيف عند الدخول | `lavkoo:app/Utils/CartManager.php:23-50` | VERIFIED |
| `get_cart_group_ids` بلا بُعد متجر | `lavkoo:app/Utils/CartManager.php:163-180` | VERIFIED |
| تنظيف السلة والجلسة بعد الطلب | `lavkoo:app/Utils/CartManager.php:363-384` | VERIFIED |
| حساب الضريبة ووضع الشمول | `lavkoo:app/Utils/CartManager.php:1043-1076` | VERIFIED |
| `order_group_id` واحد لكل دفع | `lavkoo:app/Utils/OrderManager.php:1551` | VERIFIED |
| طلب لكل مجموعة | `lavkoo:app/Utils/OrderManager.php:1604-1656` | VERIFIED |
| بناء صف الطلب (لا عملة/كيان) | `lavkoo:app/Utils/OrderManager.php:1059-1108` | VERIFIED |
| مولّد رقم الطلب | `lavkoo:app/Utils/OrderManager.php:799-807` | VERIFIED |
| قفل المنتجات والخصم المشروط | `lavkoo:app/Utils/OrderManager.php:1596-1602`، `lavkoo:app/Utils/OrderManager.php:1345-1359` | VERIFIED |
| لقطة العمولة السطرية ودفتر البائع | `lavkoo:app/Utils/OrderManager.php:1120-1205` | VERIFIED |
| قيد `hold` و`pending_amount` عند الإنشاء | `lavkoo:app/Utils/OrderManager.php:2135-2170` | VERIFIED |
| ترحيل المحافظ عند التسليم | `lavkoo:app/Utils/OrderManager.php:156-414` | VERIFIED |
| نسخة ثانية لترحيل المحافظ | `lavkoo:app/Repositories/OrderRepository.php:700-760` | VERIFIED |
| العمولة المجمّعة بخانتين | `lavkoo:app/Utils/Helpers.php:639-652` | VERIFIED |
| محوّل العملة للعرض | `lavkoo:app/Utils/currency.php:76-104` | VERIFIED |
| دفعة واحدة لكل المجموعات | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:379-383` | VERIFIED |
| خطّاف نجاح الدفع و`allow_oversell` | `lavkoo:app/Utils/module-helper.php:133-168` | VERIFIED |
| تغيير الحالة (مشرف) | `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:624-782` | VERIFIED |
| الاسترجاع (مشرف) | `lavkoo:app/Http/Controllers/Admin/Order/RefundController.php:109-175` | VERIFIED |
| استرجاع إلى المحفظة | `lavkoo:app/Services/RefundStatusService.php:81-89` | VERIFIED |
| حقول الفاتورة القانونية | `lavkoo:resources/views/admin-views/order/invoice.blade.php:516-532` | VERIFIED |
| إعداد الضريبة الافتراضي الواحد | `lavkoo:Modules/TaxModule/app/Traits/VatTaxManagement.php:15-33` | VERIFIED |
| `country_type = single` | `lavkoo:Modules/TaxModule/config/config.php:16` | VERIFIED |
| أعمدة `country_code` الضريبية | `lavkoo:Modules/TaxModule/database/migrations/2025_05_26_115043_create_system_tax_setups_table.php:19` | VERIFIED |
| دقة التخزين `DECIMAL(40,20)` | `lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:56-68` | VERIFIED (تنفيذه في الإنتاج UNKNOWN) |
| الشحن حسب المنطقة (ويب) | `lavkoo:app/Http/Controllers/Customer/SystemController.php:87-102` | VERIFIED |
| غياب مفتاح idempotency | `lavkoo:app/Services/Monitoring/Panels/OrderIntegrityPanel.php:1375-1379` | VERIFIED |
| تعريف مسار وضع الطلب عبر الـ API (التفاصيل في الملحق المقيّد) | — | VERIFIED |
| قيم الإعدادات في الإنتاج | — | UNKNOWN |

## 9. سجل التحقق المستقل

> التحقق: الجولة الثانية، بواسطة مُدقِّق مستقل. قراءة ثابتة فقط؛ لم يُنفَّذ أي كود ولم تُعدَّل أي مستودعات ولم تُجرَ أي عملية git تغيّر الحالة.

### عدّادات التحقق

| البند | العدد |
|---|---|
| الادعاءات المفحوصة | 83 |
| مؤكَّدة دون تغيير | 60 |
| مُصحَّحة (رقم، أو استشهاد، أو درجة، أو وصف) | 18 |
| مُفنَّدة (كلياً أو جزئياً) | 2 |
| غير قابلة للتحقق في هذه الجولة | 3 |

### التصحيحات

1. §2.1: عدد مواضع استدعاء `get_cart_group_ids` كان 26 في 8 ملفات، والصحيح 24 موضعاً في 7 ملفات (11 منها داخل `CartManager`).
2. §2.1: عدد الملفات التي تستعلم `Cart::` مباشرة كان 23، والصحيح 18 (تحت `app` و`Modules`).
3. §2.2: مواضع `generateOrder` موجودة في ثلاثة ملفات لا أربعة (سبعة مواضع).
4. §2.10: كانت «أربعة منشئين» وأصبحت خمسة مواضع إنشاء بخوارزميتين، وثلاثة مواضع POS بلا قفل. وE09-F07 صُحِّحت من «ثلاث خوارزميات» إلى خوارزميتين.
5. §2.11: عدد ترحيلات الجداول المذكورة كان 106، والعدّ بنمط الأسماء المقتبسة لـ 18 جدولاً من جداول النطاق هو 95 (النمط الأصلي لم يكن مسجَّلاً).
6. §2.11: بحث `generateOrder(` في `tests/` أعاد ملفاً واحداً لا ملفين، وأُضيف أن `ApiOrderPlacementTest` يتحقق من مجاميع الطلب.
7. §2.8: مفهوم الإشعار الدائن موجود في وحدة تكاليف التشغيل لفواتير الموردين، وهو خارج نطاق فواتير البيع.
8. §2.8: بحث الفوترة الإلكترونية أُضيف إليه `fatoora|einvoice|QR`؛ الظهور الوحيد لـ `fatoorah` اسم بوابة دفع.
9. §2.4: أُضيف أن أعمدة العملة موجودة في مجالات أخرى (استرجاعات البوابة، القنوات، التنفيذ، تكاليف التشغيل، Odoo) ولم يُتتبَّع استعمالها في مسار الطلب.
10. §2.6: عدد مسارات تغيير الحالة كان خمسة، والصحيح ستة (كما يظهر في الجدول).
11. E09-F09: قائمة كاتبات `pending_amount` كانت ناقصة، والصحيح ستة مواضع.
12. E09-F11: أُضيف أن `OrderEligibility` يفحص الحالة والإيقاف والدفع، لا أهلية المنتج أو الوجهة.
13. E09-F13: أُزيل استشهاد المسار المتعلق بوضع الطلب (المعلومات منقولة)، وبقيت الدرجة والحالة.
14. E09-F15: أُضيف أن `CLAUDE.md` يُحيل إلى ملف SQL غير موجود في المستودع.
15. E09-F16: الاستدلال على غياب `discount` وُسّع إلى ترحيلات `orders`.
16. E09-F17: بند رد فرق المحفظة فُصل إلى E09-F20 بدرجة P1 (كانت P2 ضمن F17).
17. E09-F12 وGCC-16: صِيغا بلا وصف تفصيلي للمسارات المعنية؛ نُقلت التفاصيل إلى الملحق المقيّد.
18. §2.1 و§2.2 و§2.6 و§2.11: الأوصاف المرتبطة بمفتاح cache سلة الضيف وقيد الدولة وتحديد الحالة وتكرار الإرسال نُقلت إلى الملحق المقيّد، مع إحالات محايدة.

The refuted claims are listed in the next subsection and are not counted among the corrections.

### الادعاءات المفنَّدة

- «لا اختبار آلي لمجاميع الطلب» (E09-F19): مُفنَّد جزئياً؛ يوجد `ApiOrderPlacementTest::test_the_money_of_an_order...` يتحقق من مجاميع الطلب عبر الـ API. يبقى صحيحاً للقيود المالية والاسترجاع الداخلي.
- «الظهور الوحيد لـ `invoice_no`» (§2.8): مُفنَّد؛ يرد في أربعة مواضع من خطّاف DeliverySyria.

### مخاطر متبقية للمراجعة

1. (INFERRED، درجة مقترحة P1) الفرق بين مبلغ البوابة والمبلغ المخزَّن: مبلغ البوابة يُجمع من `order_amount_with_tax` (`lavkoo:app/Http/Controllers/Customer/PaymentController.php:379-383`)، بينما يُخزَّن `order_amount` بعد طرح خصم الإحالة (`lavkoo:app/Utils/OrderManager.php:1082`). إن صحّ ذلك فالمبلغ المحصَّل إلكترونياً يزيد على الإجمالي المخزَّن بقيمة خصم الإحالة. يلزم التحقق من الجهة التي تموّل خصم الإحالة قبل أي مطابقة مالية.
2. بند يتعلق بمفتاح cache سلة الضيف نُقل إلى الملحق المقيّد (SEC-E09-11) ويحتاج مراجعة الأثر على الأسعار.
3. تعريف `order_group_id` (`lavkoo:app/Utils/OrderManager.php:65-68`) لم يُعَد التحقق منه في هذه الجولة.

### فجوات التغطية

- طُبع محتوى كل استشهاد آلياً وقُرئ الكود مباشرة في الفجوات P0 وP1 وفي الادعاءات الأساسية للأقسام 2 و3 و4؛ لم تُعَد قراءة سياق كل استشهاد في الأقسام 1 و5 و7 و8.
- لم يُعَد التحقق من تفاصيل `checkValidationForCheckoutPages` (`lavkoo:app/Utils/OrderManager.php:2778-2931`) سطراً سطراً؛ فُحص بالبحث والإشارات فقط.
- لم يُعَد عدّ «قرابة 70 ملفاً مقروءاً» المذكور في §1 ولا يُسجَّل له دليل.
- قيم الإنتاج (`business_settings`) تبقى `UNKNOWN` كما هو محدد في الجدول.

### عدد البنود المنقولة إلى الملحق المقيّد

- في هذه الجولة: ستة بنود جديدة (SEC-E09-06 إلى SEC-E09-11)، وإلحاقان بالبندين SEC-E09-04 وSEC-E09-05. كل بند في النص الرئيسي له سطر إحالة محايد.
- الجولة السابقة: SEC-E09-01 إلى SEC-E09-05 (موجودة في §6).
