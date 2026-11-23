# E20 — تطبيق البائع Flutter: المنتجات والمخزون والطلبات و POS والمرتجعات والمالية وخصائص Seller Center (Seller Flutter app: operations and finance features — coverage I, part 2)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkooappseller@8ea00922e44db3d69059d46892f8f4f84349b580` (المصدر الأساسي)، `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` (للمطابقة مع `routes/rest_api/v3/seller.php` وعيّنة من المتحكمات والخدمات).

## 1. النطاق وما تم فحصه

**المغطّى.** 33 مجلد خصائص (من أصل 47 مجلداً في `lavkooappseller:lib/features/`) هي: `addProduct`, `product`, `product_details`, `inventory`, `restock`, `barcode`, `bulk_jobs`, `brand_claims`, `order`, `order_details`, `order_edit`, `delivery_man`, `shipping`, `pos`, `refund`, `returns`, `review`, `chat`, `coupon`, `clearance_sale`, `wallet`, `transaction`, `statement`, `finance_control`, `bank_info`, `vat_management`, `reports`, `seller_center`, `control_tower`, `action_center`, `automation`, `ai`, `emergency_contract` (مجموعها 531 ملف Dart و≈84 ألف سطر بحسب `find … -name '*.dart' | wc -l` و`wc -l`). قُرئت كاملةً طبقات `domain/repositories` و`domain/models` المحمِّلة للعقد (≈45 ملفاً)، والملفات `lavkooappseller:lib/utill/app_constants.dart`، `lavkooappseller:lib/helper/price_converter.dart`، `lavkooappseller:lib/helper/product_helper.dart`، `lavkooappseller:lib/helper/report_export_helper.dart`، وأجزاء محددة من الشاشات والمتحكمات (المواضع مذكورة عند كل ادعاء). فُحصت أسماء حالات الاختبار في 14 ملف `*_contract_test.dart` وملف `pending_pos_order_test.dart`.

**المطابقة مع الـ backend.** قُرئ `lavkoo:routes/rest_api/v3/seller.php` كاملاً (703 أسطر)، وطُوبقت آلياً كل ثوابت `/api/v3/seller/…` في التطبيق مع المسارات المعرَّفة (سكربت قراءة فقط في scratchpad). قُرئت مقاطع من: `ProductController`, `POSController`, `OrderController`, `SellerController`, `SellerInventoryController`, `SellerPayoutController`، وخدمات `InventoryService`, `VendorLedger`، ومسارات `Modules/AI` و`Modules/TaxModule`.

**أنماط البحث المستخدمة (rg/grep على `lib/`).** `currency`، `toStringAsFixed(`، `d{0,2}`، `PriceConverter\.`، `countryCode|country_code|CountryCode`، `torod|m5azn|fulfil|odoo|amazon|noon|trendyol|sales_channel`، `storefront|legal_entity|market_code|store_code|country_id`، `http.MultipartRequest(`، `offline_pos_orders|AppConstants.cartList`، `requestStatus|deniedNote`، `systemTaxType`، وأسماء المسارات غير المستهلكة (`order-detail-status`, `assign-delivery-man`, …).

**ما لم يُفحص.** تسجيل الدخول والصلاحيات وبوابة الإصدار والإشعارات و`shop/profile/settings/security` (نطاق E19)؛ منطق الـ backend المالي والمخزني بالتفصيل (نطاقات backend)؛ كل ملفات `widgets/` و`screens/` سطراً بسطر (فُحصت بالبحث الموجَّه لا بالقراءة الكاملة)؛ ملفات الترجمة والأصول؛ `android/` و`ios/`. لم يُشغَّل أي اختبار أو بناء.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 بنية الاتصال والهوية

- العنوان الأساسي ثابت في الكود: `baseUrl = 'https://lavkoo.com'` واسم التطبيق `Seller Center LV` (`lavkooappseller:lib/utill/app_constants.dart:6-11`). كل نقاط النهاية ثوابت نصية في الملف نفسه (`lavkooappseller:lib/utill/app_constants.dart:13-370`).
- عميل HTTP الموحّد يرسل فقط `Authorization: Bearer` وثلاث ترويسات لغة (`lang`, `X-localization`, `Accept-Language`) (`lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56`). لا توجد أي ترويسة أو معامل لدولة أو متجر أو كيان قانوني أو عملة (بحث `country|market|storefront|store_id|currency|zone` في الملف: صفر نتائج).
- تسعة مواضع ترسل `http.MultipartRequest` مباشرة خارج `DioClient` (ستة منها في هذا النطاق: `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:86`، `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:380`، `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:70`، `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:175`، `lavkooappseller:lib/features/chat/domain/repositories/chat_repository.dart:53`، `lavkooappseller:lib/features/bank_info/domain/repositories/bank_info_repository.dart:19`)؛ هذه الطلبات تحمل ترويسة `Authorization` فقط، فأي ترويسة سياق تُضاف مستقبلاً إلى `DioClient` لن تصلها تلقائياً.
- هوية البائع في الـ backend تأتي من الرمز: `$request->seller` (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:56-64`). المسار الوحيد الذي يحمل معرّف البائع في الـ URL هو `products/{seller_id}/all-products`، ويتحقق المتحكم من تطابقه مع صاحب الرمز (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:143-151`).

**نتيجة المطابقة الآلية للمسارات (VERIFIED):** 183 ثابتاً تبدأ بـ `/api/v3/seller`؛ 173 منها تطابق مساراً في `lavkoo:routes/rest_api/v3/seller.php` (مطابقة مسار لا طريقة HTTP)، والعشرة الباقية تخدمها الـ Modules: تسعة مسارات AI (`lavkoo:Modules/AI/routes/api.php:19-31`) ومسار تقرير الضريبة (`lavkoo:Modules/TaxModule/routes/api/v3/api.php:17-21`). في الاتجاه المعاكس، عشرة مسارات backend لا يشير إليها التطبيق إطلاقاً (صفر نتائج بحث في `lib/`): `orders/order-detail-status/{id}`, `orders/assign-delivery-man`, `orders/delivery-charge-date-update`, `orders/assign-third-party-delivery`, `orders/update-payment-status` (`lavkoo:routes/rest_api/v3/seller.php:205-210`)، `monthly-earning`, `monthly-commission-given` (`lavkoo:routes/rest_api/v3/seller.php:113-114`)، `products/list` (`lavkoo:routes/rest_api/v3/seller.php:155`)، `pos/get-categories` (`lavkoo:routes/rest_api/v3/seller.php:319`)، `shipping-method/edit/{id}` (`lavkoo:routes/rest_api/v3/seller.php:292`).

### 2.2 المنتجات (إنشاء/تعديل/اعتماد/Bulk/Brand claims)

| البند | نقطة النهاية | الحقول الأساسية | الدليل |
|---|---|---|---|
| قائمة المنتجات | `GET products/{sellerId}/all-products` | `limit=20`, `offset`, `search`, `product_types`, `min_price/max_price`, `brand_ids`, `category_ids`, `product_status`, `request_status` (0 جديد/1 معتمد/2 مرفوض), `filter_sort_by` | `lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:20-54` |
| إضافة/تعديل | `POST products/add` و`products/update/{id}` (مع `_method=put`) | `name`/`description` (JSON لكل لغة), `unit_price`, `discount`, `discount_type`, `tax_ids` (JSON), `tax_model`, `category_id`/`sub_category_id`/`sub_sub_category_id`, `unit`, `brand_id`, `colors`, `images`, `thumbnail`, `current_stock`, `shipping_cost`, `multiply_qty`, `code`, `minimum_order_qty`, `product_type`, `digital_product_type`, `tags`, حقول SEO | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:218-251`، `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:270-277`، `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:325-338` |
| المتغيرات | ضمن الطلب نفسه | `choice_attributes`, `choice_no`, `choice`, `choice_options_{attrId}`, `price_{variant}`, `qty_{variant}`, `sku_{variant}` | `lavkooappseller:lib/features/addProduct/controllers/variation_controller.dart:296-347` |
| المنتج الرقمي | multipart | `extensions_type`, `digital_product_variant_key`, `digital_product_sku`, `digital_product_price`, `digital_files_{key}`, `preview_file`, `authors`, `publishing_house` | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:279-293`، `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:351-375` |
| الضريبة | `GET /api/v1/vat-tax/get-taxVat-list` | قائمة `id`, `name`, `tax_rate`؛ يُضاف عنصر «All» محلياً | `lavkooappseller:lib/features/addProduct/controllers/add_product_tax_controller.dart:22-37`، `lavkooappseller:lib/features/addProduct/domain/models/tax_vat_model.dart:1-12` |
| Bulk | `POST seller-center/bulk-jobs/price` و`/stock` | `product_ids`, `mode`, `value`, `discount`, `discount_type`, `note`؛ النتيجة «إيصال» بعدّادات وصفوف مرفوضة | `lavkooappseller:lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:33-60`، `lavkooappseller:lib/features/bulk_jobs/domain/models/bulk_job_models.dart:7-20` |
| Brand claims | `seller-center/brand-claims` (+`/exposure`, `/{id}/documents`, `/submit`, `/withdraw`) | `brand_id`, `claim_type`, `status`, `may_list`, `entitles`, `expires_at`, مستندات | `lavkooappseller:lib/features/brand_claims/domain/repositories/brand_claim_repository.dart:13-57`، `lavkooappseller:lib/features/brand_claims/domain/models/brand_claim_models.dart:28-32` |

ملاحظات موثّقة:

1. **SKU/الكود.** حقل `code` واحد للمنتج، يُولَّد محلياً من 6 محارف عشوائية ويُتحقق من أن طوله ≥ 6 (`lavkooappseller:lib/features/addProduct/controllers/add_product_controller.dart:578-585`، `lavkooappseller:lib/features/addProduct/screens/add_product_screen.dart:149`، `lavkooappseller:lib/features/addProduct/screens/add_product_screen.dart:1296-1302`). الـ backend يفرض فرادته على كامل جدول `products` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:798`). أمّا SKU المتغيّر فالتطبيق يرسله كنصّ حرفي `sku_<variantType>` وليس قيمة يدخلها البائع (`lavkooappseller:lib/features/addProduct/controllers/variation_controller.dart:344-347`).
2. **الملكية.** الـ backend يكتب `user_id = seller.id` و`shop_id` لأول متجر للبائع و`added_by = "seller"` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:900-902`)، وحالة الاعتماد تُشتق من الإعداد `new_product_approval` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1049`). التطبيق يعرض `request_status` و`denied_note` (`lavkooappseller:lib/features/product_details/widgets/product_details_widget.dart:656-662`، `lavkooappseller:lib/features/product_details/widgets/product_details_widget.dart:716`).
3. **نموذج الضريبة.** `tax_model` قيمتاه `include`/`exclude` (`lavkooappseller:lib/features/addProduct/screens/add_product_next_screen.dart:88`)، واختيار `tax_ids` يصبح إلزامياً فقط عندما يكون الإعداد العام `system_tax_type == 'product_wise'` و`system_tax_include_status == 0` (`lavkooappseller:lib/features/addProduct/controllers/add_product_controller.dart:755`، `lavkooappseller:lib/features/addProduct/screens/add_product_next_screen.dart:332`).
4. **AI.** تسع نقاط نهاية للتوليد (عنوان، وصف، إعداد عام، تسعير، متغيرات، SEO، اقتراح عناوين، تحليل صورة، فحص الحد) ترسل `name`/`description`/`langCode`/`keywords`/`image` (`lavkooappseller:lib/features/ai/domain/repositories/ai_repository.dart:16-161`) وتخدمها `Modules/AI` (`lavkoo:Modules/AI/routes/api.php:22-30`). لا يُرسل أي سياق سوق أو عملة مع طلب «التسعير».

### 2.3 المخزون وإعادة التخزين

يوجد في التطبيق **مساران مختلفان لكتابة المخزون**:

- **المسار الدفتري (الأحدث).** `POST seller-center/inventory/products/{id}/adjust` بالحقول `delta`, `reason`, `note` (`lavkooappseller:lib/features/inventory/domain/repositories/inventory_repository.dart:31-45`)، مع قراءة `overview`, `movements`, `warehouses`, `batches` (`lavkooappseller:lib/features/inventory/domain/repositories/inventory_repository.dart:12-28`). التطبيق يعرض رسالة الرفض القادمة من الخادم (`lavkooappseller:lib/features/inventory/controllers/inventory_controller.dart:119-158`)، ويخفي المستودعات والدفعات ما لم يُعِد الخادم `warehouses_enabled`/`batches_enabled` (`lavkooappseller:lib/features/inventory/domain/models/inventory_models.dart:40-41`، `lavkooappseller:lib/features/inventory/widgets/inventory_view.dart:58-62`). في الـ backend يمر هذا المسار عبر `InventoryService::adjust` الذي يقفل الصف، يرفض الرصيد السالب، **ويرفض التعديل إذا كان مزوّد fulfillment يتحكم بمخزون المنتج** (`stock_is_set_by_the_warehouse_sync`) ثم يسجّل حركة في `stock_movements` (`lavkoo:app/Services/Marketplace/InventoryService.php:36-74`).
- **المسار الثاني لكتابة المخزون.** `POST products/quantity-update` مع `_method=put` (ويقابله `PUT` في ملف المسارات) بالحقول `product_id`, `current_stock`, `variation` (`lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:413-427`، `lavkooappseller:lib/features/restock/domain/repositories/restock_repository.dart:70-79`) و`POST products/restock-request-stock-update` (`lavkooappseller:lib/features/restock/domain/repositories/restock_repository.dart:52-61`). يستدعيهما التطبيق من بطاقة «نفاد المخزون» ومن قائمة إعادة التخزين (`lavkooappseller:lib/features/product/widgets/stockout_product_card_widget.dart:436`، `lavkooappseller:lib/features/restock/controllers/restock_controller.dart:247-293`). يكتب متحكم الـ backend `current_stock` و`variation` عبر `updateByParams` بعد التحقق من ملكية البائع للمنتج (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1767-1788`)، و`updateByParams` تحديث Query Builder جماعي (`lavkoo:app/Repositories/ProductRepository.php:478-482`). يختلف هذا المسار عن المسار الدفتري في طبقات الضبط التي يمر بها؛ تفاصيل الفرق في الملحق المقيّد.
  - SEC-E20-06: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

**مصدر الحقيقة كما يراه التطبيق:** رقم واحد `current_stock` لكل منتج مع `qty` لكل متغيّر داخل JSON `variation` (`lavkooappseller:lib/features/product/domain/models/product_model.dart:731-742`). لا يعرف التطبيق شيئاً عن Torod أو M5AZN أو pools أو الحجوزات أو قنوات البيع أو Odoo (بحث `torod|m5azn|fulfil|odoo|amazon|noon|trendyol|sales_channel` في `lib/`: النتائج الوحيدة هي `fulfillment_rate` في بطاقة الأداء وكلمة marketplace في التعليقات). المستودع يظهر كقائمة للقراءة فقط (`id`, `name`, `code`, `address`, `is_default`, `status`) بلا دولة (`lavkooappseller:lib/features/inventory/domain/models/inventory_models.dart:111-128`).

### 2.4 الطلبات

| الوظيفة | نقطة النهاية | الحقول | الدليل |
|---|---|---|---|
| القائمة | `POST orders/list` | `limit=10`, `offset`, `status`, `order_current_status` + فلاتر (POS فقط/الموقع فقط/مستحق/مرتجع/حالة الدفع/العميل) | `lavkooappseller:lib/features/order/domain/repositories/order_repository.dart:15-40`، `lavkooappseller:lib/features/order_details/domain/models/order_list_filter_model.dart:102-127` |
| التفاصيل | `GET orders/{id}` | — | `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:49-51` |
| Breakdown | `GET orders/{id}/breakdown` | `fees` (source, is_settled, currency, items, commission_*, seller_receives, shipping, ledger), `timeline`, `sla` | `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:59-61`، `lavkooappseller:lib/features/order_details/domain/models/order_breakdown_model.dart:90-127` |
| تغيير الحالة/الدفع/التوصيل | `POST orders/order-detail-info-update` (طلب واحد) | `order_id`, `order_status`, `payment_status`, `delivery_man_id`, `deliveryman_charge`, `expected_delivery_date`, `delivery_service_name`, `third_party_delivery_tracking_id`, `delivery_type` | `lavkooappseller:lib/features/order_details/domain/models/order_setup_model.dart:38-49`، `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:149-153` |
| تعديل الطلب | `POST orders/edit-order-validation`, `orders/edit-order-submit`, `orders/assign-order-in-cod`؛ منتجات التعديل `GET products/{sellerId}/edit-order-all-products` | خريطة بيانات حرّة + `order_id` | `lavkooappseller:lib/features/order_edit/domain/repositories/order_edit_repository.dart:15-18`، `lavkooappseller:lib/features/order_edit/domain/repositories/order_edit_repository.dart:28-41`، `lavkooappseller:lib/features/order_edit/domain/repositories/order_edit_repository.dart:81-83` |
| تعديل العنوان | `POST orders/address-update` | `order_id`, `contact_person_name`, `phone`, `city`, `zip`, `email`, `address`, `latitude`, `longitude`, `address_type` | `lavkooappseller:lib/features/order/domain/repositories/order_repository.dart:49-64` |
| الفاتورة | `GET orders/{id}/invoice` | — | `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:162-166` |

- قائمة الحالات المتاحة تُبنى **داخل التطبيق** لا من الخادم: لطلبات `inhouse_shipping` ثلاث حالات فقط (pending/confirmed/processing)، ولغيرها ثماني حالات (`lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:19-41`). إعداد التوصيل يظهر فقط عند `sellerwise_shipping` ولغير طلبات POS والمنتجات الرقمية (`lavkooappseller:lib/features/order_details/widgets/order_setup_bottom_sheet.dart:47`، `lavkooappseller:lib/features/order_details/widgets/order_setup_bottom_sheet.dart:334`).
- «التوصيل عبر طرف ثالث» مجرد اسم خدمة نصّي ورقم تتبع نصّي (`lavkooappseller:lib/features/order_details/widgets/order_setup_bottom_sheet.dart:307`)؛ نموذج الطلب لا يحمل أي حقل لمزوّد fulfillment أو رابط تتبع أو شحنة (حقول التوصيل في النموذج تقتصر على `delivery_type`, `delivery_service_name`, `third_party_delivery_tracking_id` في `lavkooappseller:lib/features/order/domain/models/order_model.dart:292-299` و`shipping_responsibility` في `lavkooappseller:lib/features/order/domain/models/order_model.dart:335`؛ بحث `fulfil|provider|tracking_url|shipment` في الملف: صفر نتائج).
- عنوان الطلب: الـ backend يقبل `region_id`, `city_id`, `district`, `national_address` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:570-577`) لكن التطبيق لا يرسل أياً منها ولا يرسل دولة (`lavkooappseller:lib/features/order/domain/repositories/order_repository.dart:53-64`).
- نطاق الطلبات في متحكم الطلبات هو `seller_id` + `seller_is = 'seller'`؛ وهو مُثبَّت في 9 من 9 استعلامات طلبات مفحوصة في `OrderController` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:300`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:565`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:597-602`).
- **عمال التوصيل:** CRUD كامل، سجل الطلبات والأرباح، تحصيل النقد (`deliveryman_id`, `amount`)، وطلبات سحب عامل التوصيل (قبول/رفض) (`lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:19-146`)؛ إنشاء العامل يرسل `country_code` ورقم هوية ونوعها (`lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:204-216`).
- **الشحن:** طرق شحن بعنوان ومدة وتكلفة فقط (`lavkooappseller:lib/features/shipping/domain/models/shipping_model.dart:1-6`)، وتكلفة حسب التصنيف (`ids`, `cost`, `multiply_qty`) ونوع الشحن المختار (`lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:36-39`، `lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:68-89`). لا وجهة ولا دولة ولا منطقة.

### 2.5 نقطة البيع POS

- المسارات: `pos/product-list`, `pos/products?code=`, `pos/customers`, `pos/customer-store`, `coupon/check-coupon`, `pos/get-tax-amount`, `pos/place-order`, `pos/get-invoice` (`lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:21-148`).
- جسم الطلب: `cart[]`, `coupon_discount`, `order_amount`, `coupon_code`, `coupon_discount_amount`, `customer_id`, `extra_discount`, `extra_discount_type`, `payment_method`, `paid_amount`, `total_tax_amount`, `modified_cart[]` (`lavkooappseller:lib/features/pos/domain/models/place_order_body.dart:86-106`). المبلغ `order_amount` يُحسب في التطبيق ويُرسل بعد تحويله إلى عملة العرض وتقريبه (`lavkooappseller:lib/features/pos/screens/pos_screen.dart:821-836`).
- طرق الدفع: `cash` و`card` ثابتتان، وتُضاف `wallet` إذا كان الإعداد العام `wallet_status` مفعّلاً (`lavkooappseller:lib/features/pos/screens/pos_screen.dart:57`، `lavkooappseller:lib/features/pos/screens/pos_screen.dart:81-83`). الدفع بالمحفظة يعتمد على `wallet_balance` الذي يعود ضمن بيانات عميل POS (`lavkooappseller:lib/features/pos/domain/models/customer_model.dart:51`، `lavkooappseller:lib/features/pos/controllers/customer_controller.dart:59-63`)، وفي الـ backend يُخصم من محفظة العميل العامة (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:320-323`).
- إنشاء عميل POS: `f_name`, `l_name`, `email`, `phone`, `country` (نص حر), `city`, `zip_code`, `address` (`lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:89-101`).
- **البيع دون اتصال.** عند تعذّر وصول الطلب إلى الخادم (`response == null`) يُحفظ الجسم على الجهاز في `SharedPreferences` تحت المفتاح العام `offline_pos_orders`، وتُعاد محاولة إرساله تلقائياً عند عودة الاتصال حتى 10 محاولات (`lavkooappseller:lib/features/pos/controllers/cart_controller.dart:398-403`، `lavkooappseller:lib/features/pos/controllers/offline_sales_controller.dart:21-22`، `lavkooappseller:lib/features/pos/controllers/offline_sales_controller.dart:105-153`). المفتاح غير مرتبط بالمتجر أو البائع، وهو ما يتطلب تحديد نطاق للصندوق في GCC. ضوابط إعادة الإرسال وحدودها مفصّلة في الملحق المقيّد. كذلك سلال POS المعلّقة تُحفظ تحت المفتاح العام `cart_list` (`lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:112-128`).
- SEC-E20-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E20-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
- SEC-E20-05: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 2.6 الاستردادات والمرتجعات

- **Refund (قرار مالي):** `GET refund/list` (مع `date_type`, `start_date`, `end_date`)، `GET refund/refund-details?order_details_id=`، `GET refund/single-item?id=`، و`POST refund/refund-status-update` بالحقول `refund_status`, `refund_request_id`, `note` (`lavkooappseller:lib/features/refund/domain/repositories/refund_repository.dart:14-29`، `lavkooappseller:lib/features/refund/domain/repositories/refund_repository.dart:97-99`). القرارات المتاحة للبائع من التطبيق: `approved` أو `rejected` فقط (`lavkooappseller:lib/features/refund/domain/repositories/refund_repository.dart:37-44`). المبالغ تُعرض بـ `PriceConverter.convertPrice` (`lavkooappseller:lib/features/refund/widgets/refund_widget.dart:118`).
- **Returns (البضاعة العائدة):** `GET seller-center/returns` و`/{id}`، و`POST /{id}/in-transit` (`carrier`, `tracking_number`)، `/{id}/receive` (`restock`)، `/{id}/reject` (`reason`) (`lavkooappseller:lib/features/returns/domain/repositories/return_repository.dart:12-45`). النموذج يربط المرتجع بـ `order_id` و`refund_request_id` وبقيود دفترية (`lavkooappseller:lib/features/returns/domain/models/return_models.dart:45-60`، `lavkooappseller:lib/features/returns/domain/models/return_models.dart:120-125`). قرار `restock` يُتخذ عند الاستلام؛ لا يوجد في العقد موقع مخزون أو مستودع وجهة للمرتجع.

### 2.7 المالية

| الخاصية | ما الذي تديره فعلياً | نقاط النهاية | الدليل |
|---|---|---|---|
| `wallet` (قديم) | عرض أرصدة `seller-info.wallet` (`total_earning`, `withdrawn`, `pending_withdraw`, `commission_given`, `delivery_charge_earned`, `collected_cash`, `total_tax_collected`) وطلب سحب | `withdraw-method-list`, `balance-withdraw`, `balance-withdraw-update`, `close-withdraw-request`, `payment-information/list` | `lavkooappseller:lib/features/wallet/domain/repositories/wallet_repository.dart:14-96`، `lavkooappseller:lib/features/wallet/screens/wallet_screen.dart:109-150`، `lavkooappseller:lib/features/profile/domain/models/profile_info.dart:157-165` |
| `transaction` | قائمة طلبات السحب (`amount`, `approved`, `withdrawal_method_fields`) + تصدير CSV محلي | `transactions?status=&from=&to=` | `lavkooappseller:lib/features/transaction/domain/repositories/transaction_repository.dart:15-17`، `lavkooappseller:lib/features/transaction/domain/models/transaction_model.dart:43-54`، `lavkooappseller:lib/features/transaction/screens/transaction_screen.dart:55` |
| `seller_center` payouts (جديد) | أرصدة دفترية `available/pending/reserved/paid/balance`, `withdrawable`, `in_cooling_period`, `payout_eligible`, `payout_currencies`؛ طلب/إلغاء payout | `seller-center/payouts` (GET/POST), `/{id}/cancel` | `lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:77-101`، `lavkooappseller:lib/features/seller_center/domain/models/payout_models.dart:32-56` |
| `statement` | كشف حساب سطراً بسطر مع `balance_after`, `order_id`, `payout_reference`, `settlement_reference`؛ تصدير ملف من الخادم | `seller-center/statement`, `/export` | `lavkooappseller:lib/features/statement/domain/repositories/statement_repository.dart:13-45`، `lavkooappseller:lib/features/statement/domain/models/statement_models.dart:41-54` |
| `finance_control` | مطابقة (delivered → recorded → credited + gaps)، محاكي العمولة، سجل تغيّر الأسعار، سياسة التسعير (`min_margin_percent`, `min_price`, `enforce`) | `seller-center/finance/{reconciliation,fee-simulator,price-changes,pricing-policy}` | `lavkooappseller:lib/features/finance_control/domain/repositories/finance_control_repository.dart:12-60`، `lavkooappseller:lib/features/finance_control/domain/models/finance_control_models.dart:248-255` |
| `bank_info` | حقول بنكية نصية على سجل البائع: `bank_name`, `branch`, `holder_name`, `account_no` (مع الاسم والهاتف) | `POST seller-update` (multipart مع `_method=put`) | `lavkooappseller:lib/features/bank_info/domain/repositories/bank_info_repository.dart:18-27` |
| `vat_management` | **تقرير ضريبة للقراءة فقط**؛ لا إدارة لرقم ضريبي ولا لنِسب ولا لفواتير | `get-vat-tax-report-list?limit&offset&start_date&end_date` | `lavkooappseller:lib/features/vat_management/screens/vat_management_screen.dart:24-38`، `lavkooappseller:lib/features/vat_management/domain/repositories/vat_repository.dart:12-20`، `lavkooappseller:lib/features/vat_management/domain/models/vat_report_model.dart:23-35` |
| `reports` | تقارير طلبات/منتجات/مخزون من الخادم + تصدير (Excel/PDF) | `seller-center/reports/{orders,products,stock}` و`/export`, `/export-pdf` | `lavkooappseller:lib/features/reports/domain/repositories/report_repository.dart:13-51`، `lavkooappseller:lib/features/reports/screens/order_report_screen.dart:73-80` |

حقائق حاكمة:

1. **نظاما سحب متوازيان.** القديم يخصم من `SellerWallet.total_earning` وينشئ `withdraw_requests` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:455-469`)، والجديد يعمل على `VendorLedger`/`VendorPayoutRequest` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:50-63`). كلاهما معروض في القائمة نفسها (`lavkooappseller:lib/features/menu/screens/more_screen.dart:137-146`).
2. **العملة في العقود المالية الجديدة.** الخادم يُرجع حقل `currency` في كشف الحساب وتفصيل الطلب وموجز اليوم وطلبات الـ payout، والتطبيق يحلّله (`lavkooappseller:lib/features/statement/domain/models/statement_models.dart:107`، `lavkooappseller:lib/features/order_details/domain/models/order_breakdown_model.dart:99`، `lavkooappseller:lib/features/control_tower/domain/models/control_tower_models.dart:266`، `lavkooappseller:lib/features/seller_center/domain/models/payout_models.dart:83`) **لكنه لا يستخدمه في أي شاشة** (بحث `\.currency\b` في `lib/features` خارج `splash`: صفر استخدامات قراءة). كل المبالغ تُنسَّق بـ `PriceConverter` أي برمز العملة العام وإعداد المنازل العام. مصدر الحقل في كشف الحساب هو `VendorLedger::baseCurrency()` أي الإعداد العام `system_default_currency` (`lavkoo:app/Services/Marketplace/SellerLedgerStatementService.php:91`، `lavkoo:app/Services/Marketplace/VendorLedger.php:227-237`)، أما عملة كل قيد فتُمرَّر من المستدعي أو تُختم بالعملة الافتراضية (`lavkoo:app/Services/Marketplace/VendorLedger.php:45`، `lavkoo:app/Services/Marketplace/VendorLedger.php:91`). والأرصدة تُجمع لكل بائع بحسب `status` فقط دون تجميع بالعملة (`lavkoo:app/Services/Marketplace/VendorLedger.php:104-116`).
3. **`payout_currencies`.** تُعرض قائمة عملات للـ payout فقط إذا كان `currency_model == multi_currency` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:54-58`)، والتطبيق يرسل `payout_currency` اختيارياً (`lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:90-96`).
4. **تقرير الضريبة.** يُحسب في الخادم لمتجر واحد يُستخرج من البائع (`shop_id`) ويجمع `order_taxes` بحسب `tax_on` و`tax_name` و`tax_rate` (`lavkoo:Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:65-100`)؛ لا يحمل كياناً قانونياً ولا دولة ولا عملة. الرقم الضريبي للبائع يُرسل فقط عند التسجيل (`tax_identification_number`, `tin_expire_date`, `tin_certificate`) وهو خارج هذا النطاق (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:230-231`).
5. مسألتان أمنيتان مرتبطتان بالصلاحيات ونطاق تقرير الضريبة:
   - SEC-E20-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.
   - SEC-E20-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 2.8 Seller Center / Control Tower / Action Center / Automation

| الخاصية في التطبيق | نقاط النهاية | متحكم الـ backend (من ملف المسارات) | ملاحظات العقد |
|---|---|---|---|
| Overview/Scorecard | `seller-center/overview`, `/scorecard` | `SellerCenterController` (`lavkoo:routes/rest_api/v3/seller.php:415-419`) | verification, performance, finance, SLA؛ العتبات تأتي من الخادم (`lavkooappseller:lib/features/seller_center/domain/models/seller_center_models.dart:20-24`) |
| Verification (KYC) | `seller-center/verification`, `/submit` | `SellerVerificationController` (`lavkoo:routes/rest_api/v3/seller.php:426-431`) | `document_type`, `document_number`, `expires_at`, `document_file` (`lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:51-55`) |
| Analytics | `seller-center/analytics`, `/activities` | `SellerAnalyticsController` (`lavkoo:routes/rest_api/v3/seller.php:437-440`) | `range` |
| Control Tower | `seller-center/control-tower`, `/briefing`, `/issues/{id}/status` | `SellerControlTowerController` (`lavkoo:routes/rest_api/v3/seller.php:447-457`) | issues بأقسام وصحة لكل domain؛ الموجز اليومي يحمل `balance.currency` (`lavkooappseller:lib/features/control_tower/domain/models/control_tower_models.dart:251-268`) |
| Action Center | `seller-center/action-center`, `/{id}/dismiss` | `SellerActionCenterController` (`lavkoo:routes/rest_api/v3/seller.php:589-592`) | insights بـ `action_key`/`action_params` (`lavkooappseller:lib/features/action_center/domain/models/seller_insight_model.dart:50-64`) |
| Automation | `seller-center/automation/{catalogue,rules,activity}` + run/revert/preview | `SellerAutomationController` (`lavkoo:routes/rest_api/v3/seller.php:565-581`) | نموذج القواعد يُبنى من catalogue الخادم لا من قائمة في التطبيق (`lavkooappseller:lib/features/automation/domain/models/automation_models.dart:229-239`) |
| Bulk jobs | `seller-center/bulk-jobs` | `SellerBulkJobController` (`lavkoo:routes/rest_api/v3/seller.php:671-678`) | — |
| Inventory ledger | `seller-center/inventory/*` | `SellerInventoryController` (`lavkoo:routes/rest_api/v3/seller.php:655-663`) | — |
| Returns | `seller-center/returns` | `SellerReturnController` (`lavkoo:routes/rest_api/v3/seller.php:640-647`) | — |
| Finance control | `seller-center/finance/*` | `SellerFinanceControlController` (`lavkoo:routes/rest_api/v3/seller.php:489-504`) | — |
| Statement / Payouts / Reports | `seller-center/{statement,payouts,reports}` | `SellerStatementController`, `SellerPayoutController`, `SellerReportController` (`lavkoo:routes/rest_api/v3/seller.php:598-634`) | — |

المجموعات الفرعية لمسارات `seller-center` في ملف المسارات تحمل `seller_can:<permission>` أو `seller_owner` (`lavkoo:routes/rest_api/v3/seller.php:411-680`)، ولم يُفحص تقييد البيانات بالبائع صاحب الرمز لكل متحكم في هذا النطاق. اختبارات العقد الأربعة عشر تثبّت أشكال هذه الحمولات (مثل `lavkooappseller:test/statement_contract_test.dart:20` و`lavkooappseller:test/order_breakdown_contract_test.dart:16`) وتعتمد سلوك «القسم الناقص لا يكسر التحليل»، وهو ما يجعل **إضافة حقول جديدة آمنة للإصدارات القديمة** بينما تغيير نوع أو معنى حقل قائم ليس كذلك.

### 2.9 الكوبونات، التصفية، المحادثات، التقييمات

- **الكوبونات:** `coupon/list|store|update/{id}|status-update/{id}|delete/{id}|customers` (`lavkooappseller:lib/features/coupon/domain/repositories/coupon_repository.dart:17-98`). النوعان المتاحان من التطبيق `discount_on_purchase` و`free_delivery` (`lavkooappseller:lib/features/coupon/controllers/coupon_controller.dart:39-41`)، والحقول `coupon_type`, `customer_id`, `limit`, `discount_type`, `discount`, `min_purchase`, `max_discount`, `code`, `title`, `start_date`, `expire_date`؛ النموذج يحمل `seller_id` و`coupon_bearer` بلا أي بُعد متجر/دولة/عملة (`lavkooappseller:lib/features/coupon/domain/models/coupon_model.dart:76-94`).
- **Clearance sale:** إعداد واحد لكل متجر (`setup_by`, `user_id`, `shop_id`, `discount_type`, `discount_amount`, نوافذ زمنية، `show_in_homepage`) وقائمة منتجات بخصم لكل منتج (`lavkooappseller:lib/features/clearance_sale/domain/models/clearance_config_model.dart:42-50`، `lavkooappseller:lib/features/clearance_sale/domain/repositories/clearance_sale_repository.dart:25-141`).
- **المحادثات:** `messages/{list,get-message,send,seen,search}/{type}` حيث `type` هو الطرف (عميل/عامل توصيل)، مع مرفقات `media[]`/`file[]` (`lavkooappseller:lib/features/chat/domain/repositories/chat_repository.dart:22-85`).
- **التقييمات:** قائمة وفلترة وتفعيل/إخفاء وردّ (`review_id`, `reply_text`) (`lavkooappseller:lib/features/review/domain/repositories/product_review_repository.dart:12-103`).

### 2.10 عرض المال والعملة والمنازل العشرية

- مصدر واحد لتنسيق المال: `PriceConverter` — 250 استدعاء في 74 ملفاً (176 منها `convertPrice`). يقرأ من إعداد `/api/v1/config`: `currency_model`, `currency_symbol_position`, `decimal_point_settings`, `currency_list`, `system_default_currency` (`lavkooappseller:lib/helper/price_converter.dart:8-34`، `lavkooappseller:lib/features/splash/domain/models/config_model.dart:242-249`).
- في وضع `multi_currency` يُضرب المبلغ بـ `myCurrency.exchangeRate / usdCurrency.exchangeRate` (`lavkooappseller:lib/helper/price_converter.dart:24`)؛ `myCurrency` هي العملة التي اختارها المستخدم ومحفوظة محلياً تحت مفتاح `currency`، وافتراضها `system_default_currency`، و`usdCurrency` تُعيَّن بالبحث عن الرمز الحرفي `'USD'` (`lavkooappseller:lib/features/splash/controllers/splash_controller.dart:70-83`).
- عدد المنازل: `decimalPointSettings ?? 1` — قيمة عامة واحدة لكل التطبيق (`lavkooappseller:lib/helper/price_converter.dart:22`). لكن توجد مواضع مثبّتة على منزلتين: حقل مبلغ الـ payout (`lavkooappseller:lib/features/seller_center/widgets/request_payout_sheet_widget.dart:79`)، حقل قيمة السعر في Bulk (`lavkooappseller:lib/features/bulk_jobs/screens/bulk_edit_screen.dart:274`)، حساب الخصم الإضافي في POS (`lavkooappseller:lib/helper/price_converter.dart:187`، `lavkooappseller:lib/helper/price_converter.dart:197`؛ مستخدم في `lavkooappseller:lib/features/pos/screens/pos_screen.dart:798-799`)، و`longToShortPrice` (`lavkooappseller:lib/helper/price_converter.dart:212-223`).
- **عدم تماثل التحويل (VERIFIED في الكود).** عند القراءة يُحوَّل سعر المنتج لعملة العرض (`lavkooappseller:lib/features/addProduct/screens/add_product_next_screen.dart:115-122`)، وعند الكتابة تُستدعى `systemCurrencyToDefaultCurrency` التي تُرجع القيمة كما هي في الفرعين (`lavkooappseller:lib/helper/price_converter.dart:154-162`؛ الاستدعاء في `lavkooappseller:lib/features/addProduct/screens/add_product_seo_screen.dart:757-763`) ويتولى الخادم التحويل من العملة الافتراضية (`lavkoo:app/Utils/currency.php:50-67`). محاكي العمولة وقيمة Bulk وسياسة التسعير وطلب السحب القديم تُرسل القيمة المكتوبة كما هي (`lavkooappseller:lib/features/finance_control/screens/finance_control_screen.dart:498-504`، `lavkooappseller:lib/features/bulk_jobs/screens/bulk_edit_screen.dart:227-233`، `lavkooappseller:lib/common/basewidgets/custom_edit_dialog_widget.dart:450-476`) بينما طلب الـ payout الجديد وحده يعكس التحويل قبل الإرسال (`lavkooappseller:lib/features/seller_center/widgets/request_payout_sheet_widget.dart:143-147`). النتيجة: السلوك متّسق فقط في `single_currency` أو حين تساوي عملة العرض العملة الافتراضية.
- **الدولة/الهاتف:** رمز الاتصال الافتراضي في نماذج عامل التوصيل وعميل POS وجهة الطوارئ وتعديل عنوان الطلب يُشتق من القيمة العامة الواحدة `config.country_code` (`lavkooappseller:lib/features/delivery_man/widgets/delivery_man_info_widget.dart:35`، `lavkooappseller:lib/features/pos/screens/add_new_customer_screen.dart:46`، `lavkooappseller:lib/features/emergency_contract/widgets/add_emergency_contact_widget.dart:27`)، واستخراج رمز الدولة من رقم محفوظ يتم بمطابقة نصية لأول رمز «يحتويه» الرقم (`lavkooappseller:lib/helper/country_code_helper.dart:7-16`).

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | أين | الحالة |
|---|---|---|---|
| A1 | بائع واحد = متجر واحد = كتالوج واحد؛ لا اختيار سياق ولا ترويسة سياق | `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:900-902` | VERIFIED |
| A2 | عملة مرجعية واحدة للتنسيق (رمز وموضع ومنازل من إعداد عام)؛ في وضع `multi_currency` يختار المستخدم عملة العرض، ويُحوَّل المبلغ بسعر صرف واحد مقابل USD | `lavkooappseller:lib/helper/price_converter.dart:18-33`؛ `lavkooappseller:lib/features/splash/controllers/splash_controller.dart:70-83` | VERIFIED |
| A3 | رصيد مالي واحد لكل بائع (`available/pending/reserved/paid/balance`) بلا تفصيل بالعملة أو الكيان | `lavkooappseller:lib/features/seller_center/domain/models/payout_models.dart:37-43`؛ `lavkoo:app/Services/Marketplace/VendorLedger.php:104-116` | VERIFIED |
| A4 | منزلتان عشريتان في مدخلات مالية محددة | `lavkooappseller:lib/features/seller_center/widgets/request_payout_sheet_widget.dart:79`؛ `lavkooappseller:lib/features/bulk_jobs/screens/bulk_edit_screen.dart:274` | VERIFIED |
| A5 | نظام ضريبي واحد: قائمة ضرائب عامة + `tax_model` + إعداد `system_tax_type` عام | `lavkooappseller:lib/features/addProduct/controllers/add_product_tax_controller.dart:22-37`؛ `lavkoo:Modules/TaxModule/routes/api/v1/api.php:17-22` | VERIFIED |
| A6 | سعر واحد ومخزون واحد وكود واحد للمنتج؛ الكود فريد على الجدول كله | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:218-251`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:798` | VERIFIED |
| A7 | دولة واحدة لرموز الهاتف الافتراضية (`config.country_code`) | `lavkooappseller:lib/features/pos/screens/add_new_customer_screen.dart:46` | VERIFIED |
| A8 | USD عملة أساس حسابية في وضع multi-currency (بحث حرفي عن `'USD'`) | `lavkooappseller:lib/features/splash/controllers/splash_controller.dart:78-79` | VERIFIED |
| A9 | طرق شحن بلا وجهة؛ «طرف ثالث» نص حر | `lavkooappseller:lib/features/shipping/domain/models/shipping_model.dart:1-6`؛ `lavkooappseller:lib/features/order_details/domain/models/order_setup_model.dart:38-49` | VERIFIED |
| A10 | مخازن محلية على الجهاز غير مقيّدة ببائع/متجر (`offline_pos_orders`, `cart_list`, `currency`) | `lavkooappseller:lib/features/pos/controllers/offline_sales_controller.dart:21`؛ `lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:112-128` | VERIFIED |
| A11 | إرث قالب/متجر سابق: عيّنات الاختبار بعملة `SYP` وتعليق «this build serves a Syrian store» واسم الحزمة `syriacosmeticsmanger` وقائمة لغات برمز دولة `SY` | `lavkooappseller:test/control_tower_contract_test.dart:50`؛ `lavkooappseller:lib/utill/app_constants.dart:407-421` | VERIFIED (INFO) |
| A12 | سنوات فلتر المعاملات مثبّتة 2020–2032 | `lavkooappseller:lib/features/transaction/domain/repositories/transaction_repository.dart:51-66` | VERIFIED (P2) |

لم يُعثر على ترميز صلب لـ `SAR` أو `+966` أو `SA` في خصائص هذا النطاق (بحث `'+966'|"+966"|'SA'|"SA"|\bSAR\b|+966` في كامل `lib/` خارج `lib/localization`: صفر نتائج)؛ الافتراض السعودي يأتي من **بيانات الإعداد** لا من الكود، وقيمه الإنتاجية `UNKNOWN`.

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Foundation: distinct concepts (Seller/Shop ≠ Country/Storefront/Legal Entity) | الهوية من الرمز؛ `seller_id` لا يُستعمل كدولة | لا مفهوم Storefront أو Legal Entity في عقود البائع المفحوصة (ورد لفظ `storefront` في تعليقين فقط بمعنى صفحة متجر البائع)؛ `shop_id` يُشتق كأول متجر للبائع | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:900-902` |
| Foundation: independent products/prices/SKUs/inventory per country | عقد المنتج مرن (JSON لكل لغة، متغيرات) | سعر/مخزون/كود واحد؛ فرادة `code` عالمية تمنع تكرار SKU نفسه في دولتين كصفّين مستقلين | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:798` |
| Foundation: one fulfillment source per product per storefront; keep Torod/M5AZN | مسار `inventory/adjust` يتحقق من ملكية المزوّد للمخزون قبل التعديل | يوجد كاتبان لـ `current_stock` خارج مسار واحد موحّد (`quantity-update` وPOS)؛ التطبيق لا يُظهر مصدر التنفيذ؛ التفاصيل في SEC-E20-06 وSEC-E20-04 | `lavkoo:app/Services/Marketplace/InventoryService.php:50-53`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1777-1781` |
| Foundation: separate legal entity (invoices, tax, bank, settlements) | كشف حساب دفتري بمرجع payout/settlement | بيانات بنكية واحدة على سجل البائع؛ تقرير ضريبة لمتجر واحد؛ فاتورة الطلب بلا كيان | `lavkooappseller:lib/features/bank_info/domain/repositories/bank_info_repository.dart:22-27` |
| Provisional: wallets/ledgers separate by store/legal entity/currency | حقل `currency` موجود في الحمولات | التطبيق يتجاهله؛ الأرصدة مجمّعة بلا عملة؛ نظاما سحب متوازيان | `lavkoo:app/Services/Marketplace/VendorLedger.php:104-116` |
| Provisional: local pricing, no auto-FX | — | مسار العرض يضرب بسعر الصرف في `multi_currency`؛ يجب إبقاء الوضع `single_currency` لكل متجر أو استبدال الآلية | `lavkooappseller:lib/helper/price_converter.dart:24` |
| ملاحظة 3 منازل (KWD/BHD/OMR) | `decimal_point_settings` قابل للضبط | قيمة عامة واحدة + مواضع مثبّتة على منزلتين | `lavkooappseller:lib/features/seller_center/widgets/request_payout_sheet_widget.dart:79` |
| GCC-13 (محافظ منفصلة لكل متجر) | — | POS يدفع من `wallet_balance` العام للعميل | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:320-323` |
| GCC-14 (نطاقات بيانات للموظفين) | `seller_can` على مسارات البائع | لا نطاق متجر/كيان في صلاحيات البائع (وانظر `SEC-E20-01`) | `lavkoo:routes/rest_api/v3/seller.php:411-420` |
| GCC-16 (شحن محلي وعابر للحدود) | — | طرق الشحن بلا وجهة؛ لا فحص أهلية منتج؛ تتبّع نص حر | `lavkooappseller:lib/features/shipping/domain/models/shipping_model.dart:1-6` |
| GCC-17 (قواعد كوبونات محلية) | كوبون بائع بحدود ومبالغ | لا بُعد متجر/عملة في الكوبون | `lavkooappseller:lib/features/coupon/domain/models/coupon_model.dart:76-94` |
| GCC-18 (علامات مركزية وسمات مشتركة) | `brands`, `categories`, `attributes` تُجلب مركزياً؛ brand claims | لا امتدادات محلية للسمات في العقد | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:28-61` |
| GCC-08 (شجرة تصنيفات مركزية) | التطبيق يقرأ شجرة واحدة من `categories` | لا إظهار/إخفاء حسب الدولة (غير مطلوب من التطبيق اليوم) | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:52-57` |
| GCC-12 (إيقاف متجر مع بقاء الالتزامات) | — | التطبيق لا يعرف حالة متجر دولة؛ يجب أن تبقى الطلبات/المرتجعات/الـ payouts تعمل عند الإيقاف | INFERRED من غياب أي حقل حالة متجر في النماذج المفحوصة (لم يُكرَّر البحث على كل النماذج) |
| GCC-19 (السعودية الافتراضية) | غياب السياق يعني سلوكاً واحداً | يصلح كقاعدة توافق: الإصدار القديم بلا ترويسة = المتجر السعودي الافتراضي | `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56` |

**مصفوفة الأثر على القوائم/المخزون/المالية متعددة الدول:**

| الخاصية | تفترض عملة واحدة | تفترض ضريبة واحدة | تفترض متجراً واحداً | تغيير العقد المطلوب (توثيق فقط) | توافق الإصدارات القديمة |
|---|---|---|---|---|---|
| إضافة/تعديل منتج | نعم | نعم | نعم | بُعد storefront للمنتج (حقل/ترويسة)، قائمة ضرائب مقيّدة بالمتجر، نطاق فرادة `code` لكل متجر | غياب البُعد ⇒ المتجر الافتراضي SA |
| قائمة المنتجات/التقارير | نعم | — | نعم | فلتر storefront اختياري + إرجاع رمز المتجر والعملة لكل صف | الحقول الإضافية يتجاهلها المحلّل الحالي |
| المخزون (adjust/quantity-update/bulk) | — | — | نعم | توحيد الكتابة عبر الدفتر؛ إرجاع `stock_controlled_by` لكل منتج؛ موقع مخزون لكل متجر | إبقاء `quantity-update` مع رفض واضح للمنتجات المملوكة لمزوّد |
| الطلبات | نعم (عرض) | نعم (عرض) | نعم | إرجاع `storefront`, `currency`, `legal_entity` snapshot لكل طلب؛ فلتر متجر | طلبات SA تبقى كما هي |
| POS | نعم | نعم | نعم | سياق متجر إلزامي + مفتاح idempotency + محفظة المتجر | الإصدار القديم يُقيَّد بالمتجر الافتراضي |
| Refund/Returns | نعم | — | نعم | وراثة سياق الطلب؛ موقع مخزون للمرتجع | متوافق |
| Wallet/Payouts/Statement | نعم | — | نعم | أرصدة مفصّلة حسب (legal entity, currency)؛ `currency` وعدد منازله لكل مبلغ | إبقاء الحقول المجمّعة لرصيد SA فقط |
| Bank info | — | — | نعم | حساب تسوية لكل كيان قانوني | الحقول الحالية = حساب SA |
| VAT report | نعم | نعم | نعم | تقرير لكل كيان/دولة بعملته ورقمه الضريبي | التقرير الحالي = SA |
| Coupons/Clearance | نعم | — | نعم | بُعد متجر وعملة للقواعد | الكوبونات الحالية = SA |
| Shipping methods | نعم | — | نعم | وجهة/منطقة ومتجر لكل طريقة | الطرق الحالية = SA |
| Seller-center (tower/actions/automation) | جزئياً | — | نعم | إضافة سياق المتجر إلى issues/insights/قواعد الأتمتة | إضافي فقط |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | الإضافة الدنيا المقترحة |
|---|---|---|---|
| ثوابت المسارات `/api/v3/seller/*` (183) والمسارات العشرة القديمة غير المستهلكة | Preserve | إصدارات منشورة تعتمدها | لا حذف ولا إعادة تسمية؛ أي جديد بمسارات/حقول إضافية |
| اختبارات العقد (14 ملفاً) | Preserve | خط الأساس لتوافق الحمولات | إضافة حالات لحقول المتجر/العملة عند إقرارها |
| `PriceConverter` | Extend | نقطة تنسيق واحدة (249 استدعاء) | قبول عملة ومنازل لكل مبلغ من الحمولة بدل الإعداد العام؛ إزالة التثبيت على منزلتين |
| عقود Seller Center المالية (statement/payouts/breakdown) | Extend | تحمل `currency` أصلاً | استخدام `currency` في العرض؛ أرصدة لكل كيان/عملة |
| عقد المنتج (add/update) | Extend | مرن وقابل للإضافة | بُعد storefront + ضرائب مقيّدة بالمتجر |
| مسار المخزون الدفتري `inventory/adjust` | Preserve | يحترم ملكية المزوّد ويكتب الحركة | — |
| مسار `quantity-update`/`restock-request-stock-update` | Refactor (backend) | كاتب موازٍ لـ `current_stock` خارج الدفتر الموحّد (SEC-E20-06) | توجيهه عبر `InventoryService` مع بقاء شكل الطلب |
| POS offline outbox | Refactor (app) | مفتاح تخزين عام غير مرتبط بالمتجر (SEC-E20-03) | ربط كل عنصر بـ (seller, storefront) مع ضوابط إعادة الإرسال على الخادم |
| `wallet` القديم مقابل payouts الدفتري | Refactor (قرار) | مصدران للسحب | تحديد مصدر واحد لكل كيان قانوني قبل GCC |
| تقرير VAT | Extend | يعمل لمتجر واحد | بُعد كيان قانوني/دولة |
| طرق الشحن والتوصيل بطرف ثالث | Extend | بلا وجهة | حقول وجهة/أهلية |
| محدّد سياق المتجر في تطبيق البائع | New | غير موجود | اختيار متجر/دولة + ترويسة سياق في `DioClient` وفي المواضع التسعة لـ `MultipartRequest` |
| تعديل عنوان الطلب | Extend | الخادم يقبل حقول العنوان الموحّد | إرسال `region_id`/`city_id`/`district`/`national_address` والدولة |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E20-F01 | الشاشات المالية تفترض عملة واحدة ورصيداً واحداً لكل بائع: حقل `currency` القادم من الخادم يُحلَّل ولا يُستخدم، والأرصدة تُجمع في الخادم بلا تجميع بالعملة | P0 | VERIFIED (الكود) / UNKNOWN (قيود بعملتين في الإنتاج) | `lavkooappseller:lib/features/statement/domain/models/statement_models.dart:107`؛ `lavkooappseller:lib/features/seller_center/domain/models/payout_models.dart:37-43`؛ `lavkoo:app/Services/Marketplace/VendorLedger.php:104-116`؛ `lavkoo:app/Services/Marketplace/VendorLedger.php:227-237`؛ `lavkoo:app/Services/Marketplace/RefundReversalService.php:108`؛ `lavkoo:app/Utils/OrderManager.php:1152` | تُختم القيود بالعملة الافتراضية في المسار الأساسي، لكن قيد الاسترداد ولقطة العمولة قد يحملان عملة أخرى عند غياب الإعداد الافتراضي؛ وعند تعدد العملات لبائع واحد يُعرض مجموع مختلط برمز واحد ويُطلب payout عليه | أرصدة وكشوف لكل (legal entity, currency) في العقد؛ التطبيق يعرض كل مبلغ بعملته |
| E20-F02 | منزلتان عشريتان مثبّتتان في مدخلات مالية وحسابات POS، وإعداد المنازل عام واحد | P1 | VERIFIED | `lavkooappseller:lib/features/seller_center/widgets/request_payout_sheet_widget.dart:79`؛ `lavkooappseller:lib/features/bulk_jobs/screens/bulk_edit_screen.dart:274`؛ `lavkooappseller:lib/helper/price_converter.dart:187`؛ `lavkooappseller:lib/helper/price_converter.dart:22` | فقدان الفلس الثالث في KWD/BHD/OMR | منازل لكل عملة من الخادم؛ إزالة التثبيت |
| E20-F03 | لا سياق متجر/دولة/كيان في أي طلب؛ تسعة طلبات multipart خارج `DioClient` | P1 | VERIFIED | `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56`؛ `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:86` | لا يمكن للبائع إدارة أكثر من متجر دولة | محدّد سياق + ترويسة موحّدة تُطبَّق على كل المسارات |
| E20-F04 | عقد المنتج بسعر/ضريبة/مخزون/كود واحد؛ `code` فريد عالمياً؛ SKU المتغير نص حرفي | P1 | VERIFIED | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:218-251`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:798`؛ `lavkooappseller:lib/features/addProduct/controllers/variation_controller.dart:344-347` | يتعارض مع منتجات/SKU مستقلة لكل دولة | بُعد متجر في العقد؛ فرادة الكود لكل متجر؛ SKU متغير حقيقي |
| E20-F05 | يوجد كاتبان لـ `current_stock` خارج مسار واحد موحّد (المسار الدفتري والمسار القديم) | P1 | VERIFIED (على مستوى المتحكم) | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1767-1788`؛ `lavkoo:app/Repositories/ProductRepository.php:478-482`؛ `lavkoo:app/Services/Marketplace/InventoryService.php:50-53`؛ `lavkooappseller:lib/features/product/widgets/stockout_product_card_widget.dart:436` | تعدّد الكتّاب يفرّق مصدر الحقيقة للمخزون لكل منتج؛ تفاصيل الضوابط في SEC-E20-06 | توحيد الكتابة عبر `InventoryService`؛ التحقق مع E11 |
| E20-F06 | صندوق POS غير المتصل: مفتاح تخزين عام على الجهاز غير مرتبط بالمتجر أو البائع | P1 | VERIFIED | `lavkooappseller:lib/features/pos/controllers/offline_sales_controller.dart:21-22`؛ `lavkooappseller:lib/features/pos/controllers/cart_controller.dart:398-403` | تعذّر فصل طلبات المتاجر أو البائعين داخل الصندوق نفسه | ربط الصندوق بـ (seller, storefront) في GCC؛ تفاصيل ضوابط الإعادة في SEC-E20-03 |
| E20-F07 | عدم تماثل تحويل العملة بين القراءة والكتابة | P1 | VERIFIED (الكود) / UNKNOWN (قيمة `currency_model` الإنتاجية) | `lavkooappseller:lib/helper/price_converter.dart:154-162`؛ `lavkooappseller:lib/features/finance_control/screens/finance_control_screen.dart:498-504`؛ `lavkooappseller:lib/features/seller_center/widgets/request_payout_sheet_widget.dart:143-147` | أرقام خاطئة إذا فُعّل `multi_currency` مع عملة عرض مختلفة | إلغاء التحويل في التطبيق؛ كل مبلغ بعملة متجره |
| E20-F08 | نظاما سحب متوازيان (wallet القديم و payouts الدفتري) | P1 | VERIFIED | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:455-469`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:50-63`؛ `lavkooappseller:lib/features/menu/screens/more_screen.dart:137-146` | مصدران للرصيد قبل الفصل بالكيان القانوني | قرار مصدر واحد + مطابقة أرصدة SA التاريخية |
| E20-F09 | الضريبة: قائمة عامة + تقرير لمتجر واحد؛ التطبيق لا يدير رقماً ضريبياً ولا نِسباً | P1 | VERIFIED | `lavkooappseller:lib/features/vat_management/screens/vat_management_screen.dart:24-38`؛ `lavkoo:Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:65-100` | لا مخرجات ضريبية مستقلة لكل دولة | ضرائب وتقارير مقيّدة بالكيان/الدولة |
| E20-F10 | تعديل عنوان الطلب لا يرسل حقول العنوان الموحّد ولا الدولة | P1 | VERIFIED | `lavkooappseller:lib/features/order/domain/repositories/order_repository.dart:53-64`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:570-577` | عناوين نصية حرة لطلبات يُفترض ربطها بمدن مزوّدي الشحن | إرسال المعرّفات؛ سلوك وضع `strict` يحتاج تحققاً |
| E20-F11 | POS: المبالغ تُحسب في التطبيق وتُرسل بعملة العرض؛ الدفع من محفظة العميل العامة | P1 | VERIFIED | `lavkooappseller:lib/features/pos/screens/pos_screen.dart:821-836`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:320-323` | يتعارض مع GCC-13 ومع تسعير محلي لكل متجر | سياق متجر لـ POS ومحفظة المتجر (انظر `SEC-E20-04`) |
| E20-F12 | الشحن بلا وجهة والتوصيل بطرف ثالث نص حر | P1 | VERIFIED | `lavkooappseller:lib/features/shipping/domain/models/shipping_model.dart:1-6`؛ `lavkooappseller:lib/features/order_details/domain/models/order_setup_model.dart:38-49` | لا دعم لـ GCC-16 من جهة البائع | حقول وجهة/أهلية/مزوّد |
| E20-F13 | كوبونات البائع والتصفية بلا بُعد متجر/عملة | P1 | VERIFIED | `lavkooappseller:lib/features/coupon/domain/models/coupon_model.dart:76-94`؛ `lavkooappseller:lib/features/clearance_sale/domain/models/clearance_config_model.dart:42-50` | يتعارض مع GCC-17 | بُعد متجر وعملة |
| E20-F14 | شرط `customerId.toString().length == 4` يعامل أي معرّف من 4 أرقام كعميل عابر | P2 | VERIFIED | `lavkooappseller:lib/features/pos/screens/pos_screen.dart:827`؛ `lavkooappseller:lib/features/pos/controllers/customer_controller.dart:18` | طلبات POS لعملاء معرّفاتهم 1000–9999 تُنسب إلى walk-in | فحص `null` صريح (إصلاح مستقل عن GCC) |
| E20-F15 | قائمة حالات الطلب وسنوات الفلتر مثبّتة في التطبيق | P2 | VERIFIED | `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:19-41`؛ `lavkooappseller:lib/features/transaction/domain/repositories/transaction_repository.dart:51-66` | أي حالة جديدة تتطلب إصداراً | جلبها من الخادم لاحقاً |
| E20-F16 | التطبيق لا يعرف مزوّدي fulfillment أو القنوات؛ الرفض يظهر كنص خطأ فقط | INFO | VERIFIED | `lavkooappseller:lib/features/inventory/controllers/inventory_controller.dart:151-158` | البائع لا يرى لماذا مخزونه «مقفل» | حقل قراءة `stock_controlled_by` |
| E20-F17 | 183 ثابت مسار كلها محلولة (173 في ملف المسارات + 10 في الـ Modules)؛ 10 مسارات backend غير مستهلكة | INFO | VERIFIED | `lavkoo:routes/rest_api/v3/seller.php:205-210`؛ `lavkoo:Modules/AI/routes/api.php:19-31` | خط أساس التوافق | الإبقاء عليها |
| E20-F18 | إرث قالب سابق (SYP، Syrian store، اسم الحزمة) | INFO | VERIFIED | `lavkooappseller:lib/utill/app_constants.dart:407-421`؛ `lavkooappseller:test/statement_contract_test.dart:20` | لا تُعدَّل هذه المعرّفات تلقائياً (`lavkooappseller:CLAUDE.md:3`)؛ عيّنات الاختبار ليست سعودية | — |
| E20-F19 | تطبيق البائع يخدم فقط `added_by='seller'` و`seller_is='seller'`؛ إن كان كتالوج Lavkoo in-house فلا يغطيه التطبيق | INFO | VERIFIED (الكود) / UNKNOWN (البيانات) | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:900-902`؛ `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:597-602` | يحدد هل تطبيق البائع أداة تشغيل لمتاجر الدول أم للبائعين الخارجيين فقط | قرار مالك بعد معرفة بيانات الإنتاج |
| SEC-E20-01 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P1 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E20-02 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P1 | INFERRED | الملحق المقيّد | — | — |
| SEC-E20-03 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P1 | VERIFIED | الملحق المقيّد | — | — |
| SEC-E20-04 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P0 | VERIFIED (الكود) | الملحق المقيّد | — | — |
| SEC-E20-05 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P1 | VERIFIED (الكود) | الملحق المقيّد | — | — |
| SEC-E20-06 | مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور | P1 | VERIFIED (الكود) | الملحق المقيّد | — | — |

## 7. أسئلة مفتوحة وعناصر UNKNOWN

1. **قيم الإعداد الإنتاجية** لـ `currency_model`, `system_default_currency`, `decimal_point_settings`, `country_code`, `system_tax_type`, `system_tax_include_status`, `wallet_status`, `new_product_approval`, `shipping_method`: `UNKNOWN` — تحتاج قراءة `business_settings` في الإنتاج أو استجابة `/api/v1/config` الحية.
2. **هل كتالوج Lavkoo الحالي in-house (`added_by='admin'`) أم لبائعين؟** `UNKNOWN` — يحتاج عدّ صفوف `products` حسب `added_by` في الإنتاج.
3. **هل يوجد في الإنتاج بائعون برصيد في النظامين معاً** (`seller_wallets.total_earning` و`vendor_ledger_entries`)؟ `UNKNOWN` — يحتاج قاعدة البيانات.
4. **هل توجد قيود `vendor_ledger_entries` بعملات مختلفة لبائع واحد؟** `UNKNOWN` — يحتاج قاعدة البيانات.
5. **تفاعل المسار الثاني لكتابة المخزون مع مزامنة المزوّد.** `UNKNOWN` — يحسمه مدقق E11؛ التفاصيل في SEC-E20-06.
6. **الإصدار المنشور فعلياً من تطبيق البائع** وأي المسارات العشرة غير المستهلكة ما زالت تستقبل حركة من إصدارات أقدم: `UNKNOWN` — يحتاج سجلات الخادم أو المتجر.
7. **وضع `unified_address_mode` الإنتاجي** وأثره على `orders/address-update` القادم من التطبيق بلا معرّفات: `UNKNOWN`.
8. المستودع القديم `pharmacyksa98`: `UNKNOWN (missing access: pharmacyksa98)` — لا يمكن مقارنة عقود seller v3 بما كان قبله.

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| عنوان أساسي ثابت واسم التطبيق | `lavkooappseller:lib/utill/app_constants.dart:6-11` | VERIFIED |
| ترويسات العميل: توثيق + لغة فقط | `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56` | VERIFIED |
| حقول إنشاء المنتج | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:218-251` | VERIFIED |
| حقول المتغيرات وSKU الحرفي | `lavkooappseller:lib/features/addProduct/controllers/variation_controller.dart:296-347` | VERIFIED |
| فرادة `code` على `products` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:798` | VERIFIED |
| ملكية المنتج `user_id`/`shop_id`/`added_by` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:900-902` | VERIFIED |
| حالة الاعتماد من `new_product_approval` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1049` | VERIFIED |
| قائمة الضرائب العامة | `lavkooappseller:lib/features/addProduct/controllers/add_product_tax_controller.dart:22-37` | VERIFIED |
| تعديل مخزون دفتري | `lavkooappseller:lib/features/inventory/domain/repositories/inventory_repository.dart:31-45` | VERIFIED |
| حارس ملكية المزوّد في `InventoryService::adjust` | `lavkoo:app/Services/Marketplace/InventoryService.php:50-53` | VERIFIED |
| كتابة مباشرة في `quantity-update` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1767-1788` | VERIFIED |
| `updateByParams` تحديث جماعي | `lavkoo:app/Repositories/ProductRepository.php:478-482` | VERIFIED |
| طلب موحّد لتغيير حالة الطلب/التوصيل | `lavkooappseller:lib/features/order_details/domain/models/order_setup_model.dart:38-49` | VERIFIED |
| قائمة الحالات مثبّتة في التطبيق | `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:19-41` | VERIFIED |
| حقول تعديل العنوان من التطبيق | `lavkooappseller:lib/features/order/domain/repositories/order_repository.dart:49-64` | VERIFIED |
| الخادم يقبل حقول العنوان الموحّد | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:570-577` | VERIFIED |
| جسم طلب POS | `lavkooappseller:lib/features/pos/domain/models/place_order_body.dart:86-106` | VERIFIED |
| طرق دفع POS ومحفظة العميل | `lavkooappseller:lib/features/pos/screens/pos_screen.dart:81-83` | VERIFIED |
| خصم محفظة العميل في POS (backend) | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:320-323` | VERIFIED |
| صندوق POS غير المتصل ومفتاحه | `lavkooappseller:lib/features/pos/controllers/offline_sales_controller.dart:21-22` | VERIFIED |
| ضوابط الصندوق المؤجل وسياق الجلسة (SEC-E20-03) | الملحق المقيّد | VERIFIED |
| قرارات الاسترداد المتاحة | `lavkooappseller:lib/features/refund/domain/repositories/refund_repository.dart:37-44` | VERIFIED |
| مسارات المرتجعات | `lavkooappseller:lib/features/returns/domain/repositories/return_repository.dart:36-45` | VERIFIED |
| السحب القديم | `lavkooappseller:lib/features/wallet/domain/repositories/wallet_repository.dart:24-42` | VERIFIED |
| السحب القديم في الخادم (`SellerWallet`) | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:455-469` | VERIFIED |
| payouts الدفتري و`payout_currencies` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:50-63` | VERIFIED |
| `baseCurrency` = `system_default_currency` | `lavkoo:app/Services/Marketplace/VendorLedger.php:227-237` | VERIFIED |
| تجميع الأرصدة بلا عملة | `lavkoo:app/Services/Marketplace/VendorLedger.php:104-116` | VERIFIED |
| `currency` يُحلَّل ولا يُستخدم | `lavkooappseller:lib/features/statement/domain/models/statement_models.dart:107` | VERIFIED (بحث: صفر استخدامات) |
| VAT management = تقرير فقط | `lavkooappseller:lib/features/vat_management/screens/vat_management_screen.dart:24-38` | VERIFIED |
| تقرير الضريبة يُحسب لمتجر واحد (ضوابط النطاق في SEC-E20-02) | `lavkoo:Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:65-73` | VERIFIED |
| حقول البنك | `lavkooappseller:lib/features/bank_info/domain/repositories/bank_info_repository.dart:22-27` | VERIFIED |
| محاكي العمولة يرسل القيمة كما هي | `lavkooappseller:lib/features/finance_control/screens/finance_control_screen.dart:498-504` | VERIFIED |
| `systemCurrencyToDefaultCurrency` بلا أثر | `lavkooappseller:lib/helper/price_converter.dart:154-162` | VERIFIED |
| تحويل العرض بسعر الصرف | `lavkooappseller:lib/helper/price_converter.dart:24` | VERIFIED |
| تحويل الخادم من العملة الافتراضية | `lavkoo:app/Utils/currency.php:50-67` | VERIFIED |
| منزلتان في حقل payout | `lavkooappseller:lib/features/seller_center/widgets/request_payout_sheet_widget.dart:79` | VERIFIED |
| منزلتان في Bulk price | `lavkooappseller:lib/features/bulk_jobs/screens/bulk_edit_screen.dart:274` | VERIFIED |
| `toStringAsFixed(2)` في خصم POS | `lavkooappseller:lib/helper/price_converter.dart:187` | VERIFIED |
| البحث الحرفي عن `USD` | `lavkooappseller:lib/features/splash/controllers/splash_controller.dart:78-79` | VERIFIED |
| رمز الهاتف من `config.country_code` | `lavkooappseller:lib/features/pos/screens/add_new_customer_screen.dart:46` | VERIFIED |
| مسارات AI في الـ Module | `lavkoo:Modules/AI/routes/api.php:19-31` | VERIFIED |
| مسار تقرير الضريبة في الـ Module | `lavkoo:Modules/TaxModule/routes/api/v3/api.php:17-21` | VERIFIED |
| مسارات backend غير مستهلكة | `lavkoo:routes/rest_api/v3/seller.php:205-210` | VERIFIED (بحث: صفر مراجع) |
| عيّنات الاختبار بعملة SYP | `lavkooappseller:test/control_tower_contract_test.dart:50` | VERIFIED |
| خطأ طول معرّف العميل في POS | `lavkooappseller:lib/features/pos/screens/pos_screen.dart:827` | VERIFIED |
| القائمة تعرض Wallet و Payouts معاً | `lavkooappseller:lib/features/menu/screens/more_screen.dart:137-146` | VERIFIED |


## 9. سجل التحقق المستقل

> تحقق مستقل بالقراءة فقط بعد إعداد الملف (Verify-and-Redact)، دون تشغيل أي كود أو اختبار ودون أي كتابة في المستودعات. العدّادات أدناه تخص هذا الملف وحده.

### جدول العدّادات

| البند | العدد |
|---|---|
| الادعاءات المُدقَّقة | 70 (18 بنداً من P0/P1 بما فيها SEC-E20-01 إلى SEC-E20-05، و52 ادعاءً داعماً) |
| مؤكَّدة كما كُتبت | 53 |
| مُصحَّحة (نص أو رقم أو استشهاد أو حالة أو درجة) | 14، منها 5 بنود P0/P1 |
| مُفنَّدة جزئياً واستُبدلت | 1 |
| غير قابلة للتحقق في هذه المرحلة | 2 |
| عناصر نُقلت إلى الملحق المقيّد | 6 معرّفات (SEC-E20-01 إلى SEC-E20-06)، منها SEC-E20-06 جديد في هذه المرحلة |

### التصحيحات

1. E20-F01: صُحّحت آلية ختم العملة. القيد يُختم بالعملة الافتراضية في المسار الأساسي، لكن قيد الاسترداد ولقطة العمولة قد يحملان عملة أخرى عند غياب الإعداد الافتراضي (`lavkoo:app/Utils/OrderManager.php:1152`). صارت الحالة «VERIFIED (الكود) / UNKNOWN (الإنتاج)»، ودرجة P0 مُبقاة لأنها فجوة GCC قائمة.
2. §2.7 البند 2: مصدر `currency` في كشف الحساب هو `VendorLedger::baseCurrency()`، أما عملة كل قيد فتُمرَّر من المستدعي.
3. §3 A2: صُحّح الافتراض من «عملة واحدة» إلى عملة مرجعية واحدة مع اختيار عملة العرض في وضع `multi_currency`.
4. §2.3: طريقة `quantity-update` هي POST مع `_method=put`، ويقابلها PUT في ملف المسارات.
5. جدول §2.7 (bank_info): الطلب هو POST multipart مع `_method=put`.
6. §2.4: نطاق `seller_id` و`seller_is` مُثبت في 9 من 9 استعلامات طلبات مفحوصة في `OrderController`، لا في كل متحكمات الطلبات.
7. §2.8: تقييد البيانات بالبائع لم يُفحص لكل متحكم في `seller-center`؛ القيد مُثبت على مستوى المجموعات.
8. §2.7 وجدول §6 وجدول §8: استشهاد الخصم من `SellerWallet` صار `SellerController.php:455-469`.
9. §2.2: أُضيف استشهاد موضع استدعاء توليد الكود (`add_product_screen.dart:149`).
10. §4 (Foundation): لفظ `storefront` ورد في تعليقين فقط بمعنى صفحة متجر البائع؛ صارت العبارة مقيّدة بالعقود المفحوصة.
11. §1 و§2.10 و§5: عدد ملفات اختبار العقد 14 لا 13؛ استدعاءات `PriceConverter` 250 في 74 ملفاً (176 منها `convertPrice`)؛ مجلدات الخصائص المذكورة 33 من 47.
12. §6 E20-F18: مصدر قاعدة عدم تعديل المعرّفات هو `lavkooappseller:CLAUDE.md:3`، لا `DECISIONS.md` (لا يرد فيه نص بهذا المعنى).
13. SEC-E20-05 (الملحق): أُضيف أن حقول الرموز مخفية في `User::$hidden`، وأن المسار مقيّد بـ `orders.manage`؛ الدرجة P1 مُبقاة لبقية الحقول الشخصية والرصيد.

### نقل إلى الملحق المقيّد

- SEC-E20-06 (جديد): كاتب موازٍ لـ `current_stock`؛ نُقل إليه النص الأصلي لبند §2.3 وصف E20-F05 وصف §4 وصف §5 وبند §7.
- SEC-E20-03: نُقل إليه النص الأصلي لبند الصندوق غير المتصل في §2.5 وصف E20-F06 وصف §5 وصف الخروج في §8.
- SEC-E20-02: نُقل إليه النص الأصلي لصف تقرير الضريبة في §8.
- الإجمالي في الملحق: 6 معرّفات (SEC-E20-01 إلى SEC-E20-06).

### أمور متبقية غير محسومة (بأسماء الأقسام)

- §2.1 و§2.2 و§2.4: مطابقة الثوابت وأرقام الاستشهادات الكبيرة لم تُعَد بالكامل آلياً.
- §2.7 و§6 (E20-F01 و E20-F07): قيم `currency_model` و`decimal_point_settings` في الإنتاج `UNKNOWN`.
- §6 SEC-E20-02: الاستنتاج `INFERRED` ويحتاج عدّ بائعين بلا متجر في قاعدة البيانات.
- §7: البنود 1 و2 و3 و4 و6 و7 `UNKNOWN` بحسب ما هو مذكور.

### فجوات التغطية

- مطابقة الـ 183 ثابتاً آلياً لم تُعَد بالكامل؛ جرى التحقق العيّني من المسارات العشرة غير المستهلكة.
- أسماء نقاط النهاية في جداول §2.6 إلى §2.9 لم تُفرد للتحقق واحدة واحدة.
- ملفات `widgets/` و`screens/` لم تُقرأ سطراً بسطر.
- ملفات `.sql` في `database/migrations` لم تُفحص بحثاً عن triggers على `products`.
