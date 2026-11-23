# E23 — عقد الـ API بين تطبيق البائع وواجهة البائع v3 في الخلفية (Cross-repo API contract: seller app vs backend seller API v3)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` (الـ HEAD المحلي عند الفحص `lavkoo@046d623d` ويزيد عليه ملف توثيق واحد تحت `docs/gcc/` بلا أي تغيير في الكود) · `lavkooappseller@8ea00922e44db3d69059d46892f8f4f84349b580`

## 1. النطاق وما تم فحصه

**الهدف:** مطابقة ما يطلبه تطبيق البائع فعلياً (المسار، الفعل، الحقول، الترويسات، أشكال الأخطاء) مع ما تعلنه وتنفّذه الخلفية، ثم استخلاص التغييرات التعاقدية اللازمة لإدخال نطاق المتجر/الكيان القانوني للبائعين مرتّبةً بخطر الكسر. التغطية: البندان A و I من `DISCOVERY_PROMPT_01`.

**ما فُحص:**

| الجانب | الملفات | الطريقة |
|---|---|---|
| ثوابت النقاط في التطبيق | `lavkooappseller:lib/utill/app_constants.dart` (533 سطراً، قُرئ كاملاً) | استخراج آلي لكل ثابت يبدأ بـ `/api/` ⇐ **191 ثابتاً** |
| مواضع الاستدعاء | كل ملفات `lib/**/*.dart` (747 ملفاً) | بحث `AppConstants.<name>` ⇐ 240 إشارة؛ حُلّت إلى **262 موضع استدعاء** بعد فكّ التعبيرات الشرطية والدوال المساعدة (`_get`/`_post`/`_write`/`_delete`) وقراءة 23 موضعاً يدوياً |
| حرفيات URI خارج الثوابت | `lib/` | بحث `['"]/?api/v[0-9]` خارج `app_constants.dart` ⇐ **صفر نتيجة**؛ بحث `https?://` ⇐ عناوين خرائط OSM/Nominatim وصور تجريبية فقط |
| جدول مسارات الخلفية | `lavkoo:routes/rest_api/v3/seller.php` (703 أسطر، كاملاً)، `lavkoo:Modules/AI/routes/api.php`، `lavkoo:Modules/TaxModule/routes/api/v3/api.php`، `lavkoo:Modules/TaxModule/routes/api/v1/api.php`، `lavkoo:routes/rest_api/v1/api.php` | محلِّل ثابت للمجموعات المتداخلة (prefix/middleware/controller)؛ تحقق العدّ: 236 تعريف `Route::` في `seller.php` = 236 مساراً مستخرجاً |
| طبقة الـ middleware | `SellerApiAuthMiddleware`، `EnsureSellerPermission`، `EnsureSellerIsOwner`، `SellerApiMiddleware`، `APILocalizationMiddleware`، مجموعة `api` في `bootstrap/app.php` | قراءة كاملة |
| المتحكّمات والخدمات | 12 من 38 ملفاً تحت `app/Http/Controllers/RestAPI/v3/seller/` قُرئت مقاطعها ذات الصلة (`LoginController`، `SellerController`، `OrderController`، `ProductController` جزئياً، `SellerPayoutController`، `SellerStatementController`، `SellerInventoryController` جزئياً، `POSController`/`CouponController`/`DeliveryManController`/`RefundController`/`ChatController` عبر استخراج مفاتيح الطلب)، و`VendorTaxReportController` و`TaxController` في TaxModule، و`Convert`، وخدمات `SellerLedgerStatementService`/`VendorLedger`/`SellerOrderBreakdownService`/`PayoutService` | قراءة + استخراج آلي لمفاتيح `$request` وأكواد HTTP |
| جهة العميل | `dio_client.dart`، `api_error_handler.dart`، `api_checker.dart`، 26 من 43 ملف repository (كلياً أو جزئياً)، نماذج `profile_info`/`statement`/`payout`/`order_breakdown`/`vat_report`/`order_setup`/`vacation`، `price_converter.dart` | قراءة |
| اختبارات العقد | 14 ملف `test/*_contract_test.dart` (1,961 سطراً) + `lavkoo:tests` (396 ملفاً) | قراءة الترويسات والحمولات المثبَّتة؛ بحث 22 اسماً من أسماء المتحكّمات/الخدمات في `tests/` |

السكربتات المستخدمة (قراءة فقط، خارج المستودعات): `app_calls.py`، `routes.py`، `match.py`، `final.py`، `gen_tables.py`، `ctrl_reads.py` في مجلد العمل `work-E23`.

**ما لم يُفحص:** (1) مطابقة حقلية كاملة لكل نموذج Dart مقابل كل عمود في الاستجابة — الخلفية تُسلسل نماذج Eloquent خاماً (القسم 2.6)، فالمطابقة الكاملة تتطلب مخطط قاعدة البيانات الحيّ؛ اقتصر الفحص الحقلي على التدفقات الحرجة المذكورة في القسم 2.7. (2) منطق الأعمال داخل المتحكّمات والعزل بين البائعين — من اختصاص E14. (3) بنية التطبيق والجلسة وبوابة الإصدار والإشعارات — من اختصاص E19 ويُحال إليه. (4) Seller API v2 القديم — لا يستدعيه التطبيق (القسم 2.5). (5) لم يُشغَّل أي كود ولم يُجرَ أي طلب شبكي؛ كل ما يخص سلوك الإطار (Laravel/Dio) وقت التشغيل موسوم `INFERRED`.

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 سطح العقد بالأرقام

| البند | القيمة | الحالة |
|---|---|---|
| ثوابت النقاط في التطبيق | 191 | VERIFIED |
| مواضع الاستدعاء بعد الحلّ | 262 (254 على v3 + 8 على v1) | VERIFIED |
| أزواج (فعل، مسار) مميّزة يطلبها التطبيق | **241** (233 على `/api/v3/seller` + 8 على `/api/v1`) — GET 122، POST 80، PUT 27، DELETE 12 | VERIFIED |
| مسارات الخلفية تحت `/api/v3/seller` | **256** = 236 (`seller.php`) + 19 (`Modules/AI`) + 1 (`Modules/TaxModule`) — GET 124، POST 90، PUT 30، DELETE 12 | VERIFIED |
| (أ) نقاط يطلبها التطبيق بلا مسار في الخلفية | **0** | VERIFIED |
| (ب) عدم تطابق الفعل بعد احتساب `_method` | **0** | VERIFIED (ثابتاً) |
| (ج) مسارات v3 لا يستدعيها التطبيق | **23** (القسم 2.4) | VERIFIED |
| (د) استعمال واجهات غير v3 | 8 نقاط v1 مشتركة مع تطبيق العميل؛ **صفر** استعمال لـ v2 | VERIFIED |
| تصنيف البوابات على مسارات v3 الـ 256 | 8 قبل الدخول · 11 `seller_owner` · 212 `seller_can:<permission>` · 25 مصادقة فقط | VERIFIED |

تحميل المسارات: ملف v3 يُحمَّل تحت البادئة `api` ومجموعة `api` (`lavkoo:app/Providers/RouteServiceProvider.php:113-119`)، ومجموعته الجذرية `prefix => 'v3/seller'` مع `api_lang` (`lavkoo:routes/rest_api/v3/seller.php:49`). مسارات AI للبائع تُعرَّف في وحدتها بالبادئة نفسها (`lavkoo:Modules/AI/routes/api.php:19-31`)، وتقرير ضريبة البائع في TaxModule (`lavkoo:Modules/TaxModule/routes/api/v3/api.php:17-21`) المحمَّل عبر `lavkoo:Modules/TaxModule/app/Providers/RouteServiceProvider.php:58-71`. أي أن «عقد v3 للبائع» موزَّع على **ثلاثة ملفات مسارات** في ثلاث وحدات، لا ملف واحد.

### 2.2 اصطلاحات النقل التي يعتمد عليها التطبيق

1. **عنوان واحد ثابت في البناء:** `baseUrl = 'https://lavkoo.com'` (`lavkooappseller:lib/utill/app_constants.dart:11`) يُحقن في `DioClient` (`lavkooappseller:lib/di_container.dart:240`). لا يوجد اختيار خادم أو مسار دولة.
2. **الترويسات:** `Content-Type` و`Accept` بصيغة JSON، `Authorization: Bearer <token>`، وثلاث ترويسات لغة (`lang`، `X-localization`، `Accept-Language`) (`lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56`). الخلفية تقرأ `lang` فقط وتطوي `sa`⇐`ar` و`us`⇐`en` (`lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29`). لا توجد أي ترويسة سياق (متجر/دولة/كيان): بحث `store_id|storefront|legal_entity|country_id|market_id|X-Store|X-Country|X-Market` في `lib/` أعاد تعليقين وصفيين فقط عن «storefront analytics»، والبحث نفسه في متحكّمات v3 وملف المسارات و`SellerApiAuthMiddleware` أعاد تعليقين من النوع نفسه.
3. **تمرير الفعل عبر `_method`:** الواجهة الكلاسيكية تُستدعى بـ POST مع `_method: put|delete` في الجسم. وُجد ذلك في **25 موضعاً**: 24 بقيمة حرفية في 14 ملفاً (بحث آلي بالنمط `_method` مع `put|delete` حرفياً)، وموضع واحد بقيمة متغيّرة (`lavkooappseller:lib/features/shop/domain/models/vacation_model.dart:34`) (مثال: `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:40-43` لمسار `PUT language-change` المعرَّف في `lavkoo:routes/rest_api/v3/seller.php:77`؛ وإغلاق طلب السحب `lavkooappseller:lib/features/wallet/domain/repositories/wallet_repository.dart:76-86` مقابل `Route::delete` في `lavkoo:routes/rest_api/v3/seller.php:123`؛ والإجازة عبر حقل داخل النموذج `lavkooappseller:lib/features/shop/domain/models/vacation_model.dart:34`). واجهة seller-center الحديثة تستعمل الأفعال الحقيقية `put`/`delete` (مثال: `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:99-101`). نجاح المطابقة في الحالة الأولى يتوقف على دعم Laravel لتمرير الفعل من جسم JSON — `INFERRED` (سلوك إطار؛ مجلد `vendor/` غير موجود في النسخة المدقَّقة).
4. **تسعة طلبات `http.MultipartRequest` خارج `DioClient`** (سبق رصدها في E19-F08): ثمانية منها تضيف `Authorization` فقط بلا `Accept` وبلا لغة، والتاسع (التسجيل) بلا أي ترويسة (`lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:39-40`، `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:86-92`، `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:196`). أثر ذلك على شكل الخطأ في القسم 2.8.
5. **الترقيم:** `limit` + `offset` حيث `offset` رقم الصفحة لا إزاحة، والاستجابة تعيد `total_size`/`limit`/`offset` (مثال `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:121-126`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerStatementController.php:51-59`).
6. **معرّف البائع في المسار:** قائمة المنتجات تُطلب من `products/{seller_id}/all-products` بمعرّف يأخذه التطبيق من `seller-info` (`lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:52-56`، `lavkooappseller:lib/features/profile/controllers/profile_controller.dart:43`)، والخلفية ترفض أي قيمة لا تساوي بائع الرمز (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:143-151`). المسار نفسه بلا معرّف (`products/list`) موجود ولا يستعمله التطبيق.
7. **لا مسار خروج:** بحث `logout` في ملفّي مسارات v3 وفي ثوابت التطبيق ⇐ صفر. الخروج عملية محلية في التطبيق.

### 2.3 جدول المطابقة الكامل (241 زوجاً)

كل الثوابت في `lavkooappseller:lib/utill/app_constants.dart`؛ العمود الرابع يذكر اسم الثابت ورقم سطره هناك (L). «…/» اختصار لـ `/api/v3/seller/`. عمود «موضع الاستدعاء» يذكر أول موضع و(+n) عدد المواضع الإضافية. الفعل هو الفعل **الفعّال** بعد `_method`. عمود «البوابة»: «قبل الدخول» = بلا `seller_api_auth`؛ «مالك» = `seller_owner`؛ «مصادقة» = `seller_api_auth` (مع أو بدون `seller_can`)؛ تفصيل الصلاحيات لكل مسار في ملحق E14. كل صف تُحقِّق منه آلياً: سطر الاستدعاء يحوي الثابت أو دالّته المساعدة، وسطر الخلفية يحوي تعريف `Route::`.

| # | الفعل | المسار | الثابت | موضع الاستدعاء في التطبيق | المسار في الخلفية | المتحكّم@الدالة | البوابة |
|---|---|---|---|---|---|---|---|
| 1 | POST | `…/auth/login` | `loginUri` L13 | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:28` | `lavkoo:routes/rest_api/v3/seller.php:54` | `VendorLoginController@login` | قبل الدخول |
| 2 | GET | `/api/v1/config` | `configUri` L14 | `lavkooappseller:lib/features/splash/domain/repositories/splash_repository.dart:18` | `lavkoo:routes/rest_api/v1/api.php:66` | `ConfigController@configuration` | v1 مشترك |
| 3 | POST | `/api/v1/app-health` | `appHealthUri` L16 | `lavkooappseller:lib/helper/app_health_reporter.dart:72` | `lavkoo:routes/rest_api/v1/api.php:97` | `AppHealthController@__invoke` | v1 مشترك |
| 4 | GET | `…/seller-info` | `sellerUri` L17 | `lavkooappseller:lib/features/bank_info/domain/repositories/bank_info_repository.dart:59` (+1) | `lavkoo:routes/rest_api/v3/seller.php:78` | `SellerController@getSellerInfo` | مصادقة |
| 5 | PUT | `…/seller-update` | `sellerAndBankUpdate` L18 | `lavkooappseller:lib/features/bank_info/domain/repositories/bank_info_repository.dart:19` (+1) | `lavkoo:routes/rest_api/v3/seller.php:93` | `SellerController@seller_info_update` | مالك |
| 6 | GET | `…/shop-info` | `shopUri` L19 | `lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:16` (+1) | `lavkoo:routes/rest_api/v3/seller.php:80` | `SellerController@shop_info` | مصادقة |
| 7 | PUT | `…/shop-update` | `shopUpdate` L21 | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:39` | `lavkoo:routes/rest_api/v3/seller.php:98` | `SellerController@shop_info_update` | مصادقة |
| 8 | GET | `…/messages/list/{v}` | `cartUri` L22 | `lavkooappseller:lib/features/chat/domain/repositories/chat_repository.dart:24` (+1) | `lavkoo:routes/rest_api/v3/seller.php:305` | `ChatController@list` | مصادقة |
| 9 | GET | `…/messages/search/{v}` | `chatSearchUri` L23 | `lavkooappseller:lib/features/chat/domain/repositories/chat_repository.dart:34` | `lavkoo:routes/rest_api/v3/seller.php:309` | `ChatController@search` | مصادقة |
| 10 | GET | `…/messages/get-message/{v}/{v}` | `messageUri` L24 | `lavkooappseller:lib/features/chat/domain/repositories/chat_repository.dart:44` | `lavkoo:routes/rest_api/v3/seller.php:306` | `ChatController@get_message` | مصادقة |
| 11 | POST | `…/messages/send/{v}` | `sendMessageUri` L25 | `lavkooappseller:lib/features/chat/domain/repositories/chat_repository.dart:53` | `lavkoo:routes/rest_api/v3/seller.php:307` | `ChatController@send_message` | مصادقة |
| 12 | POST | `…/messages/seen/{v}` | `seenMessageUri` L26 | `lavkooappseller:lib/features/chat/domain/repositories/chat_repository.dart:84` | `lavkoo:routes/rest_api/v3/seller.php:308` | `ChatController@seenMessage` | مصادقة |
| 13 | POST | `…/orders/list` | `orderListUri` L27 | `lavkooappseller:lib/features/order/domain/repositories/order_repository.dart:38` | `lavkoo:routes/rest_api/v3/seller.php:196` | `OrderController@list` | مصادقة |
| 14 | GET | `…/orders/{v}` | `orderDetails` L28 | `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:51` | `lavkoo:routes/rest_api/v3/seller.php:201` | `OrderController@details` | مصادقة |
| 15 | GET | `…/orders/{v}/breakdown` | `orderDetails` L28 | `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:61` | `lavkoo:routes/rest_api/v3/seller.php:200` | `OrderController@breakdown` | مصادقة |
| 16 | POST | `…/balance-withdraw` | `balanceWithdraw` L29 | `lavkooappseller:lib/features/wallet/domain/repositories/wallet_repository.dart:42` | `lavkoo:routes/rest_api/v3/seller.php:121` | `SellerController@withdraw_request` | مصادقة |
| 17 | POST | `…/balance-withdraw-update` | `balanceWithdrawUpdate` L30 | `lavkooappseller:lib/features/wallet/domain/repositories/wallet_repository.dart:66` | `lavkoo:routes/rest_api/v3/seller.php:122` | `SellerController@withdraw_request_update` | مصادقة |
| 18 | DELETE | `…/close-withdraw-request` | `cancelBalanceRequest` L32 | `lavkooappseller:lib/features/wallet/domain/repositories/wallet_repository.dart:86` | `lavkoo:routes/rest_api/v3/seller.php:123` | `SellerController@close_withdraw_request` | مصادقة |
| 19 | GET | `…/transactions` | `transactionUri` L34 | `lavkooappseller:lib/features/transaction/domain/repositories/transaction_repository.dart:17` | `lavkoo:routes/rest_api/v3/seller.php:115` | `SellerController@transaction` | مصادقة |
| 20 | GET | `…/products/{v}/all-products` | `sellerProductUri` L35 | `lavkooappseller:lib/features/clearance_sale/domain/repositories/clearance_sale_repository.dart:78` (+1) | `lavkoo:routes/rest_api/v3/seller.php:696` | `ProductController@getVendorAllProducts` | مصادقة |
| 21 | GET | `…/products/stock-out-list` | `stockOutProductUri` L36 | `lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:87` | `lavkoo:routes/rest_api/v3/seller.php:157` | `ProductController@stock_out_list` | مصادقة |
| 22 | GET | `…/shop-product-reviews` | `productReviewUri` L38 | `lavkooappseller:lib/features/review/domain/repositories/product_review_repository.dart:14` (+2) | `lavkoo:routes/rest_api/v3/seller.php:104` | `SellerController@shop_product_reviews` | مصادقة |
| 23 | GET | `…/shop-product-reviews-status` | `productReviewStatusOnOff` L39 | `lavkooappseller:lib/features/review/domain/repositories/product_review_repository.dart:78` | `lavkoo:routes/rest_api/v3/seller.php:105` | `SellerController@shop_product_reviews_status` | مصادقة |
| 24 | GET | `/api/v1/attributes` | `attributeUri` L41 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:30` | `lavkoo:routes/rest_api/v1/api.php:208` | `AttributeController@get_attributes` | v1 مشترك |
| 25 | GET | `…/brands` | `brandUri` L42 | `lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:185` | `lavkoo:routes/rest_api/v3/seller.php:139` | `BrandController@getBrands` | مصادقة |
| 26 | GET | `…/categories` | `categoryUri` L43 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:54` (+3) | `lavkoo:routes/rest_api/v3/seller.php:147` | `ProductController@get_categories` | مصادقة |
| 27 | POST | `…/products/upload-images` | `uploadProductImageUri` L45 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:87` | `lavkoo:routes/rest_api/v3/seller.php:172` | `ProductController@upload_images` | مصادقة |
| 28 | DELETE | `…/products/delete/{v}` | `deleteProductUri` L48 | `lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:130` | `lavkoo:routes/rest_api/v3/seller.php:178` | `ProductController@delete` | مصادقة |
| 29 | GET | `…/products/edit/{v}` | `editProductUri` L49 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:44` | `lavkoo:routes/rest_api/v3/seller.php:158` | `ProductController@edit` | مصادقة |
| 30 | POST | `…/shipping-method/add` | `addShippingUri` L50 | `lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:114` | `lavkoo:routes/rest_api/v3/seller.php:291` | `ShippingMethodController@store` | مصادقة |
| 31 | PUT | `…/shipping-method/update/{v}` | `updateShippingUri` L51 | `lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:38` | `lavkoo:routes/rest_api/v3/seller.php:294` | `ShippingMethodController@update` | مصادقة |
| 32 | DELETE | `…/shipping-method/delete/{v}` | `deleteShippingUri` L53 | `lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:125` | `lavkoo:routes/rest_api/v3/seller.php:295` | `ShippingMethodController@delete` | مصادقة |
| 33 | GET | `…/shipping-method/list` | `getShippingUri` L55 | `lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:28` | `lavkoo:routes/rest_api/v3/seller.php:290` | `ShippingMethodController@list` | مصادقة |
| 34 | GET | `…/seller-delivery-man` | `getDeliveryManUri` L56 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:21` | `lavkoo:routes/rest_api/v3/seller.php:81` | `SellerController@seller_delivery_man` | مصادقة |
| 35 | PUT | `…/cm-firebase-token` | `tokenUri` L57 | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:92` | `lavkoo:routes/rest_api/v3/seller.php:79` | `SellerController@update_cm_firebase_token` | مصادقة |
| 36 | GET | `…/refund/list` | `refundListUri` L58 | `lavkooappseller:lib/features/refund/domain/repositories/refund_repository.dart:73` (+1) | `lavkoo:routes/rest_api/v3/seller.php:248` | `RefundController@list` | مصادقة |
| 37 | GET | `…/refund/refund-details` | `refundItemDetails` L59 | `lavkooappseller:lib/features/refund/domain/repositories/refund_repository.dart:16` | `lavkoo:routes/rest_api/v3/seller.php:250` | `RefundController@refund_details` | مصادقة |
| 38 | POST | `…/refund/refund-status-update` | `refundReqStatusUpdate` L61 | `lavkooappseller:lib/features/refund/domain/repositories/refund_repository.dart:27` | `lavkoo:routes/rest_api/v3/seller.php:251` | `RefundController@refund_status_update` | مصادقة |
| 39 | GET | `…/shipping/all-category-cost` | `showShippingCostUri` L63 | `lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:50` | `lavkoo:routes/rest_api/v3/seller.php:279` | `shippingController@all_category_cost` | مصادقة |
| 40 | POST | `…/shipping/set-category-cost` | `setCategoryWiseShippingCost` L65 | `lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:88` | `lavkoo:routes/rest_api/v3/seller.php:280` | `shippingController@set_category_cost` | مصادقة |
| 41 | GET | `…/shipping/selected-shipping-method` | `setShippingMethodTypeUri` L67 | `lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:70` | `lavkoo:routes/rest_api/v3/seller.php:278` | `shippingController@selected_shipping_type` | مصادقة |
| 42 | GET | `…/shipping/get-shipping-method` | `getShippingMethodTypeUri` L69 | `lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:60` | `lavkoo:routes/rest_api/v3/seller.php:277` | `shippingController@get_shipping_type` | مصادقة |
| 43 | POST | `…/auth/forgot-password` | `forgotPasswordUri` L71 | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:53` | `lavkoo:routes/rest_api/v3/seller.php:57` | `ForgotPasswordController@reset_password_request` | قبل الدخول |
| 44 | POST | `…/auth/verify-otp` | `verifyOtpUri` L72 | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:78` | `lavkoo:routes/rest_api/v3/seller.php:58` | `ForgotPasswordController@otp_verification_submit` | قبل الدخول |
| 45 | PUT | `…/auth/reset-password` | `resetPasswordUri` L73 | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:65` | `lavkoo:routes/rest_api/v3/seller.php:59` | `ForgotPasswordController@reset_password_submit` | قبل الدخول |
| 46 | GET | `…/products/barcode/generate` | `barCodeGenerateUri` L74 | `lavkooappseller:lib/features/barcode/domain/repositories/barcode_repository.dart:16` | `lavkoo:routes/rest_api/v3/seller.php:160` | `ProductController@barcode_generate` | مصادقة |
| 47 | POST | `…/products/upload-digital-product` | `digitalProductUpload` L76 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:380` | `lavkoo:routes/rest_api/v3/seller.php:173` | `ProductController@upload_digital_product` | مصادقة |
| 48 | PUT | `…/orders/order-wise-product-upload` | `digitalProductUploadAfterSell` L78 | `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:70` | `lavkoo:routes/rest_api/v3/seller.php:207` | `OrderController@digital_file_upload_after_sell` | مصادقة |
| 49 | POST | `…/registration` | `registration` L80 | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:196` | `lavkoo:routes/rest_api/v3/seller.php:68` | `RegisterController@store` | قبل الدخول |
| 50 | DELETE | `…/account-delete` | `deleteAccount` L81 | `lavkooappseller:lib/features/profile/domain/repositories/profile_repository.dart:56` | `lavkoo:routes/rest_api/v3/seller.php:91` | `SellerController@account_delete` | مالك |
| 51 | GET | `…/products/digital-author-list` | `digitalAuthorList` L82 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:501` | `lavkoo:routes/rest_api/v3/seller.php:165` | `ProductController@getDigitalProductsAuthorList` | مصادقة |
| 52 | GET | `…/products/digital-publishing-house-list` | `digitalPublishingHouse` L84 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:511` | `lavkoo:routes/rest_api/v3/seller.php:166` | `ProductController@getDigitalPublishingHouseList` | مصادقة |
| 53 | POST | `…/coupon/check-coupon` | `getCouponDiscount` L87 | `lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:23` | `lavkoo:routes/rest_api/v3/seller.php:266` | `CouponController@check_coupon` | مصادقة |
| 54 | POST | `…/pos/place-order` | `placeOrderUri` L88 | `lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:41` (+1) | `lavkoo:routes/rest_api/v3/seller.php:324` | `POSController@place_order` | مصادقة |
| 55 | GET | `…/pos/products` | `getProductFromProductCode` L89 | `lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:61` | `lavkoo:routes/rest_api/v3/seller.php:322` | `POSController@get_product_by_barcode` | مصادقة |
| 56 | GET | `…/pos/customers` | `customerSearchUri` L90 | `lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:71` (+1) | `lavkoo:routes/rest_api/v3/seller.php:320` | `POSController@customers` | مصادقة |
| 57 | GET | `…/pos/get-invoice` | `invoice` L91 | `lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:135` | `lavkoo:routes/rest_api/v3/seller.php:325` | `POSController@get_invoice` | مصادقة |
| 58 | GET | `…/products/top-selling-product` | `topSellingProduct` L92 | `lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:111` | `lavkoo:routes/rest_api/v3/seller.php:161` | `ProductController@top_selling_products` | مصادقة |
| 59 | GET | `…/products/most-popular-product` | `mostPopularProduct` L94 | `lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:99` | `lavkoo:routes/rest_api/v3/seller.php:162` | `ProductController@most_popular_products` | مصادقة |
| 60 | GET | `…/top-delivery-man` | `topDeliveryMan` L96 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:117` | `lavkoo:routes/rest_api/v3/seller.php:146` | `ProductController@top_delivery_man` | مصادقة |
| 61 | GET | `…/delivery-man/list` | `deliveryManListUri` L97 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:77` | `lavkoo:routes/rest_api/v3/seller.php:339` | `DeliveryManController@list` | مصادقة |
| 62 | GET | `…/delivery-man/details/{v}` | `deliveryManDetails` L98 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:87` | `lavkoo:routes/rest_api/v3/seller.php:342` | `DeliveryManController@details` | مصادقة |
| 63 | GET | `…/pos/product-list` | `posProductList` L100 | `lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:67` (+1) | `lavkoo:routes/rest_api/v3/seller.php:323` | `POSController@product_list` | مصادقة |
| 64 | PUT | `…/shipping-method/status` | `shippingMethodOnOff` L102 | `lavkooappseller:lib/features/shipping/domain/repositories/shipping_repository.dart:99` | `lavkoo:routes/rest_api/v3/seller.php:293` | `ShippingMethodController@status_update` | مصادقة |
| 65 | PUT | `…/products/quantity-update` | `updateProductQuantity` L104 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:415` (+1) | `lavkoo:routes/rest_api/v3/seller.php:187` | `ProductController@updateProductQuantity` | مصادقة |
| 66 | GET | `…/products/review-list/{v}` | `productWiseReviewList` L106 | `lavkooappseller:lib/features/review/domain/repositories/product_review_repository.dart:90` | `lavkoo:routes/rest_api/v3/seller.php:159` | `ProductController@review_list` | مصادقة |
| 67 | POST | `…/delivery-man/store` | `addDeliveryMan` L108 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:178` | `lavkoo:routes/rest_api/v3/seller.php:340` | `DeliveryManController@store` | مصادقة |
| 68 | PUT | `…/delivery-man/update/{v}` | `updateDeliveryMan` L109 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:177` | `lavkoo:routes/rest_api/v3/seller.php:341` | `DeliveryManController@update` | مصادقة |
| 69 | GET | `…/delivery-man/delete/{v}` | `deleteDeliveryman` L110 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:156` | `lavkoo:routes/rest_api/v3/seller.php:344` | `DeliveryManController@delete` | مصادقة |
| 70 | GET | `…/delivery-man/order-list/{v}` | `deliveryManOrderHistory` L111 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:97` | `lavkoo:routes/rest_api/v3/seller.php:346` | `DeliveryManController@order_list` | مصادقة |
| 71 | GET | `…/delivery-man/earning/{v}` | `deliveryManEarning` L113 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:107` | `lavkoo:routes/rest_api/v3/seller.php:348` | `DeliveryManController@earning` | مصادقة |
| 72 | POST | `…/delivery-man/cash-receive` | `collectCashFromDeliveryMan` L115 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:145` | `lavkoo:routes/rest_api/v3/seller.php:352` | `DeliveryManCashCollectController@cash_receive` | مصادقة |
| 73 | GET | `…/delivery-man/emergency-contact/list` | `getEmergencyContactList` L121 | `lavkooappseller:lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:77` (+1) | `lavkoo:routes/rest_api/v3/seller.php:366` | `EmergencyContactController@list` | مصادقة |
| 74 | PUT | `…/delivery-man/emergency-contact/status-update` | `emergencyContactStatusOnOff` L123 | `lavkooappseller:lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:38` | `lavkoo:routes/rest_api/v3/seller.php:369` | `EmergencyContactController@status_update` | مصادقة |
| 75 | DELETE | `…/delivery-man/emergency-contact/delete` | `emergencyContactDelete` L125 | `lavkooappseller:lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:58` | `lavkoo:routes/rest_api/v3/seller.php:370` | `EmergencyContactController@destroy` | مصادقة |
| 76 | GET | `…/delivery-man/withdraw/list` | `deliveryManWithdrawList` L127 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:31` | `lavkoo:routes/rest_api/v3/seller.php:358` | `DeliverymanWithdrawController@list` | مصادقة |
| 77 | GET | `…/delivery-man/reviews/{v}` | `deliveryManReviewList` L129 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:41` | `lavkoo:routes/rest_api/v3/seller.php:345` | `DeliveryManController@reviews` | مصادقة |
| 78 | GET | `…/delivery-man/withdraw/details/{v}` | `deliveryManWithdrawDetails` L131 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:51` | `lavkoo:routes/rest_api/v3/seller.php:359` | `DeliverymanWithdrawController@details` | مصادقة |
| 79 | PUT | `…/delivery-man/withdraw/status-update` | `deliveryManWithdrawApprovedRejected` L133 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:61` | `lavkoo:routes/rest_api/v3/seller.php:360` | `DeliverymanWithdrawController@status_update` | مصادقة |
| 80 | POST | `…/pos/customer-store` | `addNewCustomer` L135 | `lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:91` | `lavkoo:routes/rest_api/v3/seller.php:321` | `POSController@customer_store` | مصادقة |
| 81 | PUT | `…/products/status-update` | `productStatusOnOff` L136 | `lavkooappseller:lib/features/product_details/domain/repositories/product_details_repository.dart:30` | `lavkoo:routes/rest_api/v3/seller.php:176` | `ProductController@status_update` | مصادقة |
| 82 | POST | `…/delivery-man/status-update` | `deliveryManStatusOnOff` L138 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:128` | `lavkoo:routes/rest_api/v3/seller.php:343` | `DeliveryManController@status` | مصادقة |
| 83 | GET | `…/order-statistics` | `businessAnalytics` L140 | `lavkooappseller:lib/features/home/domain/repositories/seller_analytics_repository.dart:24` | `lavkoo:routes/rest_api/v3/seller.php:112` | `SellerController@order_statistics` | مصادقة |
| 84 | GET | `…/products/details/{v}` | `productDetails` L142 | `lavkooappseller:lib/features/product_details/domain/repositories/product_details_repository.dart:19` | `lavkoo:routes/rest_api/v3/seller.php:156` | `ProductController@details` | مصادقة |
| 85 | GET | `…/delivery-man/order-status-history/{v}` | `deliveryManOrderChangeLog` L143 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:166` | `lavkoo:routes/rest_api/v3/seller.php:347` | `DeliveryManController@order_status_history` | مصادقة |
| 86 | GET | `…/get-earning-statitics` | `chartFilterData` L145 | `lavkooappseller:lib/features/home/domain/repositories/seller_analytics_repository.dart:14` | `lavkoo:routes/rest_api/v3/seller.php:111` | `SellerController@getEarningStatics` | مصادقة |
| 87 | GET | `…/coupon/list` | `getCouponList` L148 | `lavkooappseller:lib/features/coupon/domain/repositories/coupon_repository.dart:67` | `lavkoo:routes/rest_api/v3/seller.php:261` | `CouponController@list` | مصادقة |
| 88 | DELETE | `…/coupon/delete/{v}` | `deleteCoupon` L150 | `lavkooappseller:lib/features/coupon/domain/repositories/coupon_repository.dart:47` | `lavkoo:routes/rest_api/v3/seller.php:265` | `CouponController@delete` | مصادقة |
| 89 | PUT | `…/coupon/status-update/{v}` | `couponStatusUpdate` L151 | `lavkooappseller:lib/features/coupon/domain/repositories/coupon_repository.dart:20` | `lavkoo:routes/rest_api/v3/seller.php:264` | `CouponController@status_update` | مصادقة |
| 90 | GET | `…/delivery-man/collect-cash-list/{v}` | `deliveryManCollectedCashList` L153 | `lavkooappseller:lib/features/delivery_man/domain/repositories/delivery_man_repository.dart:242` | `lavkoo:routes/rest_api/v3/seller.php:353` | `DeliveryManCashCollectController@list` | مصادقة |
| 91 | GET | `…/coupon/customers` | `couponCustomerList` L155 | `lavkooappseller:lib/features/coupon/domain/repositories/coupon_repository.dart:35` | `lavkoo:routes/rest_api/v3/seller.php:267` | `CouponController@customers` | مصادقة |
| 92 | PUT | `…/temporary-close` | `temporaryClose` L157 | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:94` | `lavkoo:routes/rest_api/v3/seller.php:131` | `ShopController@temporary_close` | مصادقة |
| 93 | PUT | `…/vacation-add` | `vacation` L158 | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:82` | `lavkoo:routes/rest_api/v3/seller.php:130` | `ShopController@vacation_add` | مصادقة |
| 94 | GET | `…/withdraw-method-list` | `dynamicWithdrawMethod` L159 | `lavkooappseller:lib/features/wallet/domain/repositories/wallet_repository.dart:16` | `lavkoo:routes/rest_api/v3/seller.php:116` | `SellerController@withdraw_method_list` | مصادقة |
| 95 | POST | `…/orders/address-update` | `orderAddressEdit` L161 | `lavkooappseller:lib/features/order/domain/repositories/order_repository.dart:53` | `lavkoo:routes/rest_api/v3/seller.php:211` | `OrderController@address_update` | مصادقة |
| 96 | GET | `…/notification` | `getNotificationList` L162 | `lavkooappseller:lib/features/notification/domain/repositories/notification_repository.dart:43` | `lavkoo:routes/rest_api/v3/seller.php:381` | `ShopController@notification_index` | مصادقة |
| 97 | GET | `…/notification/view` | `seenNotification` L164 | `lavkooappseller:lib/features/notification/domain/repositories/notification_repository.dart:15` | `lavkoo:routes/rest_api/v3/seller.php:382` | `ShopController@seller_notification_view` | مصادقة |
| 98 | GET | `…/products/stock-limit-status` | `stockLimitStatus` L165 | `lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:160` | `lavkoo:routes/rest_api/v3/seller.php:164` | `ProductController@getStockLimitStatus` | مصادقة |
| 99 | POST | `…/shop-product-reviews-reply` | `reviewReply` L167 | `lavkooappseller:lib/features/review/domain/repositories/product_review_repository.dart:101` | `lavkoo:routes/rest_api/v3/seller.php:106` | `SellerController@shopProductReviewReply` | مصادقة |
| 100 | POST | `…/products/delete-digital-product` | `deleteDigitalProductVariationFile` L168 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:485` | `lavkoo:routes/rest_api/v3/seller.php:174` | `ProductController@deleteDigitalProduct` | مصادقة |
| 101 | GET | `…/refund/single-item` | `getSingleRefundModel` L170 | `lavkooappseller:lib/features/refund/domain/repositories/refund_repository.dart:89` | `lavkoo:routes/rest_api/v3/seller.php:249` | `RefundController@getSingleItem` | مصادقة |
| 102 | POST | `…/products/restock-request-list` | `getRestockList` L172 | `lavkooappseller:lib/features/restock/domain/repositories/restock_repository.dart:21` | `lavkoo:routes/rest_api/v3/seller.php:167` | `ProductController@getRestockRequestList` | مصادقة |
| 103 | POST | `…/products/restock-request-stock-update` | `restockUpdateProductQuantity` L174 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:433` (+1) | `lavkoo:routes/rest_api/v3/seller.php:188` | `ProductController@updateRestockQuantity` | مصادقة |
| 104 | GET | `…/products/restock-request-brands-list` | `restockBrandListUri` L176 | `lavkooappseller:lib/features/restock/domain/repositories/restock_repository.dart:32` | `lavkoo:routes/rest_api/v3/seller.php:168` | `ProductController@getRestockRequestBrands` | مصادقة |
| 105 | GET | `…/products/restock-request-delete` | `restockRequestDelete` L178 | `lavkooappseller:lib/features/restock/domain/repositories/restock_repository.dart:43` | `lavkoo:routes/rest_api/v3/seller.php:181` | `ProductController@deleteRestockRequest` | مصادقة |
| 106 | GET | `…/clearance-sale/product-list` | `clearanceSaleProductList` L180 | `lavkooappseller:lib/features/clearance_sale/domain/repositories/clearance_sale_repository.dart:27` | `lavkoo:routes/rest_api/v3/seller.php:230` | `ClearanceSaleController@list` | مصادقة |
| 107 | POST | `…/clearance-sale/product-delete` | `clearanceSaleDeleteProduct` L182 | `lavkooappseller:lib/features/clearance_sale/domain/repositories/clearance_sale_repository.dart:39` | `lavkoo:routes/rest_api/v3/seller.php:232` | `ClearanceSaleController@deleteClearanceProduct` | مصادقة |
| 108 | POST | `…/clearance-sale/all-product-delete` | `clearanceSaleDeleteAllProduct` L184 | `lavkooappseller:lib/features/clearance_sale/domain/repositories/clearance_sale_repository.dart:52` | `lavkoo:routes/rest_api/v3/seller.php:233` | `ClearanceSaleController@deleteAllClearanceProduct` | مصادقة |
| 109 | POST | `…/clearance-sale/product-status-update` | `clearanceSaleProductStatusUpdate` L186 | `lavkooappseller:lib/features/clearance_sale/domain/repositories/clearance_sale_repository.dart:63` | `lavkoo:routes/rest_api/v3/seller.php:234` | `ClearanceSaleController@updateClearanceProductStatus` | مصادقة |
| 110 | POST | `…/clearance-sale/product-discount-update` | `clearanceSaleProductDiscountUpdate` L188 | `lavkooappseller:lib/features/clearance_sale/domain/repositories/clearance_sale_repository.dart:141` | `lavkoo:routes/rest_api/v3/seller.php:235` | `ClearanceSaleController@updateClearanceProductDiscount` | مصادقة |
| 111 | POST | `…/clearance-sale/config-status-update` | `clearanceSaleConfigStatusUpdate` L190 | `lavkooappseller:lib/features/clearance_sale/domain/repositories/clearance_sale_repository.dart:102` | `lavkoo:routes/rest_api/v3/seller.php:236` | `ClearanceSaleController@updateClearanceConfigStatus` | مصادقة |
| 112 | GET | `…/clearance-sale/config-data` | `clearanceSaleConfigData` L192 | `lavkooappseller:lib/features/clearance_sale/domain/repositories/clearance_sale_repository.dart:91` | `lavkoo:routes/rest_api/v3/seller.php:237` | `ClearanceSaleController@getConfigData` | مصادقة |
| 113 | POST | `…/clearance-sale/config-data-update` | `clearanceSaleConfigDataUpdate` L194 | `lavkooappseller:lib/features/clearance_sale/domain/repositories/clearance_sale_repository.dart:116` | `lavkoo:routes/rest_api/v3/seller.php:238` | `ClearanceSaleController@updateConfigData` | مصادقة |
| 114 | POST | `…/clearance-sale/product-add` | `clearanceSaleProductAdd` L196 | `lavkooappseller:lib/features/clearance_sale/domain/repositories/clearance_sale_repository.dart:128` | `lavkoo:routes/rest_api/v3/seller.php:231` | `ClearanceSaleController@addClearanceProduct` | مصادقة |
| 115 | POST | `…/orders/order-detail-info-update` | `setUpOrder` L198 | `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:151` | `lavkoo:routes/rest_api/v3/seller.php:212` | `OrderController@updateOrderDetails` | مصادقة |
| 116 | GET | `/api/v1/business-pages` | `businessPagesUri` L200 | `lavkooappseller:lib/features/splash/domain/repositories/splash_repository.dart:72` | `lavkoo:routes/rest_api/v1/api.php:67` | `ConfigController@getBusinessPagesList` | v1 مشترك |
| 117 | GET | `…/payment-information/withdrawal-method-list` | `paymentWithdrawalMethodList` L201 | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:108` | `lavkoo:routes/rest_api/v3/seller.php:394` | `VendorPaymentInfoController@getWithdrawalMethods` | مصادقة |
| 118 | POST | `…/payment-information/add` | `paymentInformationAdd` L203 | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:122` | `lavkoo:routes/rest_api/v3/seller.php:398` | `VendorPaymentInfoController@add` | مالك |
| 119 | GET | `…/payment-information/list` | `paymentInformationList` L205 | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:134` (+1) | `lavkoo:routes/rest_api/v3/seller.php:393` | `VendorPaymentInfoController@index` | مصادقة |
| 120 | POST | `…/payment-information/status` | `paymentInformationStatusUpdate` L207 | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:144` | `lavkoo:routes/rest_api/v3/seller.php:401` | `VendorPaymentInfoController@updateStatus` | مالك |
| 121 | GET | `…/payment-information/delete` | `paymentInformationDelete` L209 | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:161` | `lavkoo:routes/rest_api/v3/seller.php:402` | `VendorPaymentInfoController@delete` | مالك |
| 122 | POST | `…/payment-information/default` | `paymentInformationDefault` L211 | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:173` | `lavkoo:routes/rest_api/v3/seller.php:400` | `VendorPaymentInfoController@updateDefault` | مالك |
| 123 | POST | `…/payment-information/update` | `paymentInformationUpdate` L213 | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:121` | `lavkoo:routes/rest_api/v3/seller.php:399` | `VendorPaymentInfoController@update` | مالك |
| 124 | POST | `…/update-setup-guide-app` | `updateSetupGuideApp` L215 | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:185` | `lavkoo:routes/rest_api/v3/seller.php:99` | `SellerController@updateSetupGuideApp` | مصادقة |
| 125 | GET | `/api/v1/vat-tax/get-taxVat-list` | `getTaxVatList` L217 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:523` | `lavkoo:Modules/TaxModule/routes/api/v1/api.php:19` | `TaxController@getTaxVatList` | v1 مشترك |
| 126 | POST | `…/pos/get-tax-amount` | `getTaxAmount` L218 | `lavkooappseller:lib/features/pos/domain/repository/cart_repository.dart:146` | `lavkoo:routes/rest_api/v3/seller.php:329` | `POSCartController@getTaxAmountCart` | مصادقة |
| 127 | GET | `…/get-vat-tax-report-list` | `getVatTaxReportList` L219 | `lavkooappseller:lib/features/vat_management/domain/repositories/vat_repository.dart:13` | `lavkoo:Modules/TaxModule/routes/api/v3/api.php:19` | `VendorTaxReportController@vendorWiseTaxes` | مصادقة |
| 128 | POST | `…/auth/firebase-auth-token-store` | `firebaseAuthTokenStore` L221 | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:259` | `lavkoo:routes/rest_api/v3/seller.php:60` | `ForgotPasswordController@firebaseAuthTokenStore` | قبل الدخول |
| 129 | POST | `…/auth/firebase-auth-verify` | `firebaseAuthVerify` L223 | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:270` | `lavkoo:routes/rest_api/v3/seller.php:61` | `ForgotPasswordController@firebaseAuthVerify` | قبل الدخول |
| 130 | POST | `…/auth/check-vendor-exist-info` | `checkVendorExistInfoPhone` L225 | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:288` | `lavkoo:routes/rest_api/v3/seller.php:62` | `ForgotPasswordController@checkVendorExistInfo` | قبل الدخول |
| 131 | GET | `…/orders/{v}/invoice` | `generateInvoice` L232 | `lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:165` | `lavkoo:routes/rest_api/v3/seller.php:198` | `OrderController@invoice` | مصادقة |
| 132 | POST | `…/orders/edit-order-submit` | `editOrderSubmit` L234 | `lavkooappseller:lib/features/order_edit/domain/repositories/order_edit_repository.dart:30` | `lavkoo:routes/rest_api/v3/seller.php:219` | `OrderEditController@submitEditOrder` | مصادقة |
| 133 | POST | `…/orders/edit-order-validation` | `editOrderValidation` L237 | `lavkooappseller:lib/features/order_edit/domain/repositories/order_edit_repository.dart:41` | `lavkoo:routes/rest_api/v3/seller.php:220` | `OrderEditController@checkEditOrderValidation` | مصادقة |
| 134 | POST | `…/orders/assign-order-in-cod` | `switchToCod` L239 | `lavkooappseller:lib/features/order_edit/domain/repositories/order_edit_repository.dart:83` | `lavkoo:routes/rest_api/v3/seller.php:221` | `OrderEditController@assignOrderInCOD` | مصادقة |
| 135 | GET | `…/products/{v}/edit-order-all-products` | `editOrderAllProducts` L240 | `lavkooappseller:lib/features/order_edit/domain/repositories/order_edit_repository.dart:18` | `lavkoo:routes/rest_api/v3/seller.php:697` | `ProductController@editOrderVendorAllProducts` | مصادقة |
| 136 | GET | `…/seller-center/overview` | `sellerCenterOverviewUri` L243 | `lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:16` | `lavkoo:routes/rest_api/v3/seller.php:417` | `SellerCenterController@overview` | مصادقة |
| 137 | GET | `…/seller-center/scorecard` | `sellerScorecardUri` L245 | `lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:26` | `lavkoo:routes/rest_api/v3/seller.php:418` | `SellerCenterController@scorecard` | مصادقة |
| 138 | GET | `…/seller-center/verification` | `sellerVerificationUri` L247 | `lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:36` | `lavkoo:routes/rest_api/v3/seller.php:428` | `SellerVerificationController@index` | مالك |
| 139 | POST | `…/seller-center/verification/submit` | `sellerVerificationSubmitUri` L249 | `lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:58` | `lavkoo:routes/rest_api/v3/seller.php:429` | `SellerVerificationController@submit` | مالك |
| 140 | GET | `…/seller-center/payouts` | `sellerPayoutsUri` L251 | `lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:80` | `lavkoo:routes/rest_api/v3/seller.php:622` | `SellerPayoutController@index` | مصادقة |
| 141 | POST | `…/seller-center/payouts` | `sellerPayoutsUri` L251 | `lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:92` | `lavkoo:routes/rest_api/v3/seller.php:623` | `SellerPayoutController@store` | مصادقة |
| 142 | POST | `…/seller-center/payouts/{v}/cancel` | `sellerPayoutsUri` L251 | `lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:129` | `lavkoo:routes/rest_api/v3/seller.php:624` | `SellerPayoutController@cancel` | مصادقة |
| 143 | GET | `…/seller-center/analytics` | `sellerAnalyticsUri` L252 | `lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:107` | `lavkoo:routes/rest_api/v3/seller.php:438` | `SellerAnalyticsController@index` | مصادقة |
| 144 | GET | `…/seller-center/analytics/activities` | `sellerActivitiesUri` L254 | `lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:119` | `lavkoo:routes/rest_api/v3/seller.php:439` | `SellerAnalyticsController@activities` | مصادقة |
| 145 | GET | `…/seller-center/reports/orders` | `orderReportUri` L259 | `lavkooappseller:lib/features/reports/domain/repositories/report_repository.dart:15` | `lavkoo:routes/rest_api/v3/seller.php:603` | `SellerReportController@orders` | مصادقة |
| 146 | GET | `…/seller-center/reports/products` | `productReportUri` L261 | `lavkooappseller:lib/features/reports/domain/repositories/report_repository.dart:20` | `lavkoo:routes/rest_api/v3/seller.php:609` | `SellerReportController@products` | مصادقة |
| 147 | GET | `…/seller-center/reports/stock` | `stockReportUri` L263 | `lavkooappseller:lib/features/reports/domain/repositories/report_repository.dart:24` | `lavkoo:routes/rest_api/v3/seller.php:611` | `SellerReportController@stock` | مصادقة |
| 148 | GET | `…/seller-center/reports/orders/export` | `orderReportExportUri` L265 | `lavkooappseller:lib/features/reports/screens/order_report_screen.dart:73` | `lavkoo:routes/rest_api/v3/seller.php:604` | `SellerReportController@exportOrders` | مصادقة |
| 149 | GET | `…/seller-center/reports/orders/export-pdf` | `orderReportPdfUri` L267 | `lavkooappseller:lib/features/reports/screens/order_report_screen.dart:80` | `lavkoo:routes/rest_api/v3/seller.php:605` | `SellerReportController@exportOrdersPdf` | مصادقة |
| 150 | GET | `…/seller-center/reports/products/export` | `productReportExportUri` L269 | `lavkooappseller:lib/features/reports/screens/product_report_screen.dart:70` | `lavkoo:routes/rest_api/v3/seller.php:610` | `SellerReportController@exportProducts` | مصادقة |
| 151 | GET | `…/seller-center/reports/stock/export` | `stockReportExportUri` L271 | `lavkooappseller:lib/features/reports/screens/stock_report_screen.dart:52` | `lavkoo:routes/rest_api/v3/seller.php:612` | `SellerReportController@exportStock` | مصادقة |
| 152 | GET | `…/seller-center/statement` | `statementUri` L275 | `lavkooappseller:lib/features/statement/domain/repositories/statement_repository.dart:16` | `lavkoo:routes/rest_api/v3/seller.php:632` | `SellerStatementController@index` | مصادقة |
| 153 | GET | `…/seller-center/statement/export` | `statementExportUri` L276 | `lavkooappseller:lib/features/statement/domain/repositories/statement_repository.dart:29` | `lavkoo:routes/rest_api/v3/seller.php:633` | `SellerStatementController@export` | مصادقة |
| 154 | GET | `…/seller-center/returns` | `returnsUri` L281 | `lavkooappseller:lib/features/returns/domain/repositories/return_repository.dart:14` | `lavkoo:routes/rest_api/v3/seller.php:642` | `SellerReturnController@index` | مصادقة |
| 155 | GET | `…/seller-center/returns/{v}` | `returnsUri` L281 | `lavkooappseller:lib/features/returns/domain/repositories/return_repository.dart:28` | `lavkoo:routes/rest_api/v3/seller.php:643` | `SellerReturnController@show` | مصادقة |
| 156 | GET | `…/seller-center/inventory/overview` | `inventoryOverviewUri` L285 | `lavkooappseller:lib/features/inventory/domain/repositories/inventory_repository.dart:12` | `lavkoo:routes/rest_api/v3/seller.php:657` | `SellerInventoryController@overview` | مصادقة |
| 157 | GET | `…/seller-center/inventory/movements` | `inventoryMovementsUri` L287 | `lavkooappseller:lib/features/inventory/domain/repositories/inventory_repository.dart:16` | `lavkoo:routes/rest_api/v3/seller.php:658` | `SellerInventoryController@movements` | مصادقة |
| 158 | GET | `…/seller-center/inventory/warehouses` | `inventoryWarehousesUri` L289 | `lavkooappseller:lib/features/inventory/domain/repositories/inventory_repository.dart:25` | `lavkoo:routes/rest_api/v3/seller.php:659` | `SellerInventoryController@warehouses` | مصادقة |
| 159 | GET | `…/seller-center/inventory/batches` | `inventoryBatchesUri` L291 | `lavkooappseller:lib/features/inventory/domain/repositories/inventory_repository.dart:28` | `lavkoo:routes/rest_api/v3/seller.php:660` | `SellerInventoryController@batches` | مصادقة |
| 160 | POST | `…/seller-center/inventory/products/{v}/adjust` | `inventoryAdjustUri` L293 | `lavkooappseller:lib/features/inventory/domain/repositories/inventory_repository.dart:34` | `lavkoo:routes/rest_api/v3/seller.php:662` | `SellerInventoryController@adjust` | مصادقة |
| 161 | GET | `…/seller-center/control-tower` | `controlTowerUri` L298 | `lavkooappseller:lib/features/control_tower/domain/repositories/control_tower_repository.dart:12` | `lavkoo:routes/rest_api/v3/seller.php:449` | `SellerControlTowerController@index` | مصادقة |
| 162 | GET | `…/seller-center/control-tower/briefing` | `dailyBriefingUri` L300 | `lavkooappseller:lib/features/control_tower/domain/repositories/control_tower_repository.dart:15` | `lavkoo:routes/rest_api/v3/seller.php:450` | `SellerControlTowerController@briefing` | مصادقة |
| 163 | PUT | `…/seller-center/control-tower/issues/{v}/status` | `controlTowerIssueUri` L302 | `lavkooappseller:lib/features/control_tower/domain/repositories/control_tower_repository.dart:21` | `lavkoo:routes/rest_api/v3/seller.php:455` | `SellerControlTowerController@updateStatus` | مصادقة |
| 164 | GET | `…/seller-center/automation/catalogue` | `automationUri` L306 | `lavkooappseller:lib/features/automation/domain/repositories/automation_repository.dart:12` | `lavkoo:routes/rest_api/v3/seller.php:567` | `SellerAutomationController@catalogue` | مصادقة |
| 165 | GET | `…/seller-center/automation/rules` | `automationUri` L306 | `lavkooappseller:lib/features/automation/domain/repositories/automation_repository.dart:15` | `lavkoo:routes/rest_api/v3/seller.php:569` | `SellerAutomationController@index` | مصادقة |
| 166 | GET | `…/seller-center/automation/rules/{v}` | `automationUri` L306 | `lavkooappseller:lib/features/automation/domain/repositories/automation_repository.dart:18` | `lavkoo:routes/rest_api/v3/seller.php:570` | `SellerAutomationController@show` | مصادقة |
| 167 | GET | `…/seller-center/automation/rules/{v}/preview` | `automationUri` L306 | `lavkooappseller:lib/features/automation/domain/repositories/automation_repository.dart:21` | `lavkoo:routes/rest_api/v3/seller.php:571` | `SellerAutomationController@preview` | مصادقة |
| 168 | GET | `…/seller-center/automation/activity` | `automationUri` L306 | `lavkooappseller:lib/features/automation/domain/repositories/automation_repository.dart:26` (+1) | `lavkoo:routes/rest_api/v3/seller.php:568` | `SellerAutomationController@activity` | مصادقة |
| 169 | POST | `…/seller-center/automation/rules/{v}/run` | `automationUri` L306 | `lavkooappseller:lib/features/automation/domain/repositories/automation_repository.dart:31` | `lavkoo:routes/rest_api/v3/seller.php:579` | `SellerAutomationController@runNow` | مصادقة |
| 170 | POST | `…/seller-center/automation/activity/{v}/revert` | `automationUri` L306 | `lavkooappseller:lib/features/automation/domain/repositories/automation_repository.dart:34` | `lavkoo:routes/rest_api/v3/seller.php:580` | `SellerAutomationController@revert` | مصادقة |
| 171 | POST | `…/seller-center/automation/rules` | `automationUri` L306 | `lavkooappseller:lib/features/automation/domain/repositories/automation_repository.dart:40` | `lavkoo:routes/rest_api/v3/seller.php:575` | `SellerAutomationController@store` | مصادقة |
| 172 | PUT | `…/seller-center/automation/rules/{v}` | `automationUri` L306 | `lavkooappseller:lib/features/automation/domain/repositories/automation_repository.dart:41` | `lavkoo:routes/rest_api/v3/seller.php:576` | `SellerAutomationController@update` | مصادقة |
| 173 | PUT | `…/seller-center/automation/rules/{v}/status` | `automationUri` L306 | `lavkooappseller:lib/features/automation/domain/repositories/automation_repository.dart:52` | `lavkoo:routes/rest_api/v3/seller.php:577` | `SellerAutomationController@setStatus` | مصادقة |
| 174 | DELETE | `…/seller-center/automation/rules/{v}` | `automationUri` L306 | `lavkooappseller:lib/features/automation/domain/repositories/automation_repository.dart:64` | `lavkoo:routes/rest_api/v3/seller.php:578` | `SellerAutomationController@destroy` | مصادقة |
| 175 | GET | `…/seller-center/finance/reconciliation` | `sellerFinanceUri` L310 | `lavkooappseller:lib/features/finance_control/domain/repositories/finance_control_repository.dart:13` | `lavkoo:routes/rest_api/v3/seller.php:491` | `SellerFinanceControlController@reconciliation` | مصادقة |
| 176 | GET | `…/seller-center/finance/fee-simulator` | `sellerFinanceUri` L310 | `lavkooappseller:lib/features/finance_control/domain/repositories/finance_control_repository.dart:19` | `lavkoo:routes/rest_api/v3/seller.php:492` | `SellerFinanceControlController@feeSimulator` | مصادقة |
| 177 | GET | `…/seller-center/finance/price-changes` | `sellerFinanceUri` L310 | `lavkooappseller:lib/features/finance_control/domain/repositories/finance_control_repository.dart:23` | `lavkoo:routes/rest_api/v3/seller.php:496` | `SellerFinanceControlController@priceChanges` | مصادقة |
| 178 | GET | `…/seller-center/finance/pricing-policy` | `sellerFinanceUri` L310 | `lavkooappseller:lib/features/finance_control/domain/repositories/finance_control_repository.dart:45` | `lavkoo:routes/rest_api/v3/seller.php:501` | `SellerFinanceControlController@pricingPolicy` | مصادقة |
| 179 | PUT | `…/seller-center/finance/pricing-policy` | `sellerFinanceUri` L310 | `lavkooappseller:lib/features/finance_control/domain/repositories/finance_control_repository.dart:55` | `lavkoo:routes/rest_api/v3/seller.php:504` | `SellerFinanceControlController@savePricingPolicy` | مصادقة |
| 180 | GET | `…/seller-center/brand-claims/exposure` | `brandClaimsUri` L321 | `lavkooappseller:lib/features/brand_claims/domain/repositories/brand_claim_repository.dart:13` | `lavkoo:routes/rest_api/v3/seller.php:469` | `SellerBrandClaimController@exposure` | مصادقة |
| 181 | GET | `…/seller-center/brand-claims` | `brandClaimsUri` L321 | `lavkooappseller:lib/features/brand_claims/domain/repositories/brand_claim_repository.dart:16` | `lavkoo:routes/rest_api/v3/seller.php:470` | `SellerBrandClaimController@index` | مصادقة |
| 182 | POST | `…/seller-center/brand-claims` | `brandClaimsUri` L321 | `lavkooappseller:lib/features/brand_claims/domain/repositories/brand_claim_repository.dart:21` | `lavkoo:routes/rest_api/v3/seller.php:475` | `SellerBrandClaimController@store` | مصادقة |
| 183 | POST | `…/seller-center/brand-claims/{v}/documents` | `brandClaimsUri` L321 | `lavkooappseller:lib/features/brand_claims/domain/repositories/brand_claim_repository.dart:32` | `lavkoo:routes/rest_api/v3/seller.php:476` | `SellerBrandClaimController@attach` | مصادقة |
| 184 | DELETE | `…/seller-center/brand-claims/{v}/documents/{v}` | `brandClaimsUri` L321 | `lavkooappseller:lib/features/brand_claims/domain/repositories/brand_claim_repository.dart:46` | `lavkoo:routes/rest_api/v3/seller.php:477` | `SellerBrandClaimController@detach` | مصادقة |
| 185 | POST | `…/seller-center/brand-claims/{v}/submit` | `brandClaimsUri` L321 | `lavkooappseller:lib/features/brand_claims/domain/repositories/brand_claim_repository.dart:54` | `lavkoo:routes/rest_api/v3/seller.php:478` | `SellerBrandClaimController@submit` | مصادقة |
| 186 | POST | `…/seller-center/brand-claims/{v}/withdraw` | `brandClaimsUri` L321 | `lavkooappseller:lib/features/brand_claims/domain/repositories/brand_claim_repository.dart:57` | `lavkoo:routes/rest_api/v3/seller.php:479` | `SellerBrandClaimController@withdraw` | مصادقة |
| 187 | GET | `…/seller-center/action-center` | `actionCenterUri` L326 | `lavkooappseller:lib/features/action_center/domain/repositories/action_center_repository.dart:14` | `lavkoo:routes/rest_api/v3/seller.php:590` | `SellerActionCenterController@index` | مصادقة |
| 188 | POST | `…/seller-center/action-center/{v}/dismiss` | `actionCenterUri` L326 | `lavkooappseller:lib/features/action_center/domain/repositories/action_center_repository.dart:27` | `lavkoo:routes/rest_api/v3/seller.php:591` | `SellerActionCenterController@dismiss` | مصادقة |
| 189 | GET | `…/seller-center/bulk-jobs` | `bulkJobsUri` L332 | `lavkooappseller:lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:15` | `lavkoo:routes/rest_api/v3/seller.php:673` | `SellerBulkJobController@index` | مصادقة |
| 190 | GET | `…/seller-center/bulk-jobs/{v}` | `bulkJobsUri` L332 | `lavkooappseller:lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:25` | `lavkoo:routes/rest_api/v3/seller.php:674` | `SellerBulkJobController@show` | مصادقة |
| 191 | GET | `…/seller-center/bulk-jobs/{v}/failures` | `bulkJobsUri` L332 | `lavkooappseller:lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:66` | `lavkoo:routes/rest_api/v3/seller.php:675` | `SellerBulkJobController@downloadFailures` | مصادقة |
| 192 | POST | `…/seller-center/bulk-jobs/price` | `bulkPriceUpdateUri` L333 | `lavkooappseller:lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:40` | `lavkoo:routes/rest_api/v3/seller.php:677` | `SellerBulkJobController@storePriceUpdate` | مصادقة |
| 193 | POST | `…/seller-center/bulk-jobs/stock` | `bulkStockUpdateUri` L335 | `lavkooappseller:lib/features/bulk_jobs/domain/repositories/bulk_job_repository.dart:55` | `lavkoo:routes/rest_api/v3/seller.php:678` | `SellerBulkJobController@storeStockUpdate` | مصادقة |
| 194 | POST | `…/product/title-auto-fill` | `productTitleGenerate` L339 | `lavkooappseller:lib/features/ai/domain/repositories/ai_repository.dart:25` | `lavkoo:Modules/AI/routes/api.php:22` | `AIProductController@titleAutoFill` | مصادقة |
| 195 | POST | `…/product/description-auto-fill` | `productDescriptionGenerate` L341 | `lavkooappseller:lib/features/ai/domain/repositories/ai_repository.dart:38` | `lavkoo:Modules/AI/routes/api.php:23` | `AIProductController@descriptionAutoFill` | مصادقة |
| 196 | POST | `…/product/general-setup-auto-fill` | `productGeneralSetupGenerate` L343 | `lavkooappseller:lib/features/ai/domain/repositories/ai_repository.dart:55` | `lavkoo:Modules/AI/routes/api.php:24` | `AIProductController@generalSetupAutoFill` | مصادقة |
| 197 | POST | `…/product/price-others-auto-fill` | `productPricingGenerate` L345 | `lavkooappseller:lib/features/ai/domain/repositories/ai_repository.dart:110` | `lavkoo:Modules/AI/routes/api.php:25` | `AIProductController@pricingAndOthersAutoFill` | مصادقة |
| 198 | POST | `…/product/variation-setup-auto-fill` | `productVariationSetupGenerate` L347 | `lavkooappseller:lib/features/ai/domain/repositories/ai_repository.dart:127` | `lavkoo:Modules/AI/routes/api.php:27` | `AIProductController@productVariationSetupAutoFill` | مصادقة |
| 199 | POST | `…/product/seo-section-auto-fill` | `productSeoSectionGenerate` L349 | `lavkooappseller:lib/features/ai/domain/repositories/ai_repository.dart:143` | `lavkoo:Modules/AI/routes/api.php:26` | `AIProductController@productSeoSectionAutoFill` | مصادقة |
| 200 | POST | `…/product/generate-title-suggestions` | `productGenerateTitleGenerate` L351 | `lavkooappseller:lib/features/ai/domain/repositories/ai_repository.dart:72` | `lavkoo:Modules/AI/routes/api.php:29` | `AIProductController@generateProductTitleSuggestion` | مصادقة |
| 201 | POST | `…/product/analyze-image-auto-fill` | `productAnalyzeImageAutoGenerate` L353 | `lavkooappseller:lib/features/ai/domain/repositories/ai_repository.dart:93` | `lavkoo:Modules/AI/routes/api.php:28` | `AIProductController@generateTitleFromImages` | مصادقة |
| 202 | GET | `…/product/generate-limit-check` | `generateLimitCheck` L355 | `lavkooappseller:lib/features/ai/domain/repositories/ai_repository.dart:160` | `lavkoo:Modules/AI/routes/api.php:30` | `AIProductController@generateLimitCheck` | مصادقة |
| 203 | GET | `/api/v1/mapapi/geocode-api` | `geocodeUri` L359 | `lavkooappseller:lib/features/order/domain/repositories/location_repository.dart:18` | `lavkoo:routes/rest_api/v1/api.php:524` | `MapApiController@geocode_api` | v1 مشترك |
| 204 | GET | `/api/v1/mapapi/place-api-autocomplete` | `searchLocationUri` L360 | `lavkooappseller:lib/features/order/domain/repositories/location_repository.dart:28` | `lavkoo:routes/rest_api/v1/api.php:521` | `MapApiController@placeApiAutocomplete` | v1 مشترك |
| 205 | GET | `/api/v1/mapapi/place-api-details` | `placeDetailsUri` L362 | `lavkooappseller:lib/features/order/domain/repositories/location_repository.dart:38` | `lavkoo:routes/rest_api/v1/api.php:523` | `MapApiController@placeApiDetails` | v1 مشترك |
| 206 | PUT | `…/language-change` | `setCurrentLanguageUri` L363 | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:40` | `lavkoo:routes/rest_api/v3/seller.php:77` | `SellerController@language_change` | مصادقة |
| 207 | GET | `…/products/delete-image` | `deleteProductImage` L365 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:453` | `lavkoo:routes/rest_api/v3/seller.php:179` | `ProductController@deleteImage` | مصادقة |
| 208 | GET | `…/products/get-product-images/{v}` | `getProductImage` L367 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:474` | `lavkoo:routes/rest_api/v3/seller.php:163` | `ProductController@getProductImages` | مصادقة |
| 209 | GET | `…/products/delete-preview-file` | `deleteProductPreview` L369 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:463` | `lavkoo:routes/rest_api/v3/seller.php:180` | `ProductController@deletePreviewFile` | مصادقة |
| 210 | POST | `…/products/add` | `addProductUri` L44 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:325` (+1) | `lavkoo:routes/rest_api/v3/seller.php:175` | `ProductController@add_new` | مصادقة |
| 211 | PUT | `…/products/update/{v}` | `updateProductUri` L47 | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:325` (+1) | `lavkoo:routes/rest_api/v3/seller.php:177` | `ProductController@updateProduct` | مصادقة |
| 212 | POST | `…/delivery-man/emergency-contact/store` | `emergencyContactAdd` L117 | `lavkooappseller:lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:20` | `lavkoo:routes/rest_api/v3/seller.php:367` | `EmergencyContactController@store` | مصادقة |
| 213 | PUT | `…/delivery-man/emergency-contact/update` | `emergencyContactUpdate` L119 | `lavkooappseller:lib/features/emergency_contract/domain/repositories/emergency_contact_repository.dart:20` | `lavkoo:routes/rest_api/v3/seller.php:368` | `EmergencyContactController@update` | مصادقة |
| 214 | POST | `…/coupon/store` | `addNewCoupon` L147 | `lavkooappseller:lib/features/coupon/domain/repositories/coupon_repository.dart:84` | `lavkoo:routes/rest_api/v3/seller.php:262` | `CouponController@store` | مصادقة |
| 215 | PUT | `…/coupon/update/{v}` | `updateCoupon` L149 | `lavkooappseller:lib/features/coupon/domain/repositories/coupon_repository.dart:84` | `lavkoo:routes/rest_api/v3/seller.php:263` | `CouponController@update` | مصادقة |
| 216 | POST | `…/seller-center/returns/{v}/in-transit` | `returnsUri` L281 | `lavkooappseller:lib/features/returns/domain/repositories/return_repository.dart:36` | `lavkoo:routes/rest_api/v3/seller.php:645` | `SellerReturnController@markInTransit` | مصادقة |
| 217 | POST | `…/seller-center/returns/{v}/receive` | `returnsUri` L281 | `lavkooappseller:lib/features/returns/domain/repositories/return_repository.dart:42` | `lavkoo:routes/rest_api/v3/seller.php:646` | `SellerReturnController@receive` | مصادقة |
| 218 | POST | `…/seller-center/returns/{v}/reject` | `returnsUri` L281 | `lavkooappseller:lib/features/returns/domain/repositories/return_repository.dart:45` | `lavkoo:routes/rest_api/v3/seller.php:647` | `SellerReturnController@reject` | مصادقة |
| 219 | GET | `…/seller-center/security/permissions` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:15` | `lavkoo:routes/rest_api/v3/seller.php:543` | `SellerSecurityController@permissions` | مصادقة |
| 220 | GET | `…/seller-center/security/roles` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:18` | `lavkoo:routes/rest_api/v3/seller.php:544` | `SellerSecurityController@roles` | مصادقة |
| 221 | GET | `…/seller-center/security/staff` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:21` | `lavkoo:routes/rest_api/v3/seller.php:545` | `SellerSecurityController@staff` | مصادقة |
| 222 | GET | `…/seller-center/security/access` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:24` | `lavkoo:routes/rest_api/v3/seller.php:546` | `SellerSecurityController@access` | مصادقة |
| 223 | GET | `…/seller-center/security/audit` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:28` | `lavkoo:routes/rest_api/v3/seller.php:547` | `SellerSecurityController@audit` | مصادقة |
| 224 | POST | `…/seller-center/security/roles` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:32` | `lavkoo:routes/rest_api/v3/seller.php:549` | `SellerSecurityController@storeRole` | مصادقة |
| 225 | PUT | `…/seller-center/security/roles/{v}` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:32` | `lavkoo:routes/rest_api/v3/seller.php:550` | `SellerSecurityController@updateRole` | مصادقة |
| 226 | DELETE | `…/seller-center/security/roles/{v}` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:38` | `lavkoo:routes/rest_api/v3/seller.php:551` | `SellerSecurityController@destroyRole` | مصادقة |
| 227 | POST | `…/seller-center/security/staff` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:42` | `lavkoo:routes/rest_api/v3/seller.php:552` | `SellerSecurityController@storeStaff` | مصادقة |
| 228 | PUT | `…/seller-center/security/staff/{v}` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:42` | `lavkoo:routes/rest_api/v3/seller.php:553` | `SellerSecurityController@updateStaff` | مصادقة |
| 229 | POST | `…/seller-center/security/staff/{v}/sign-out` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:48` | `lavkoo:routes/rest_api/v3/seller.php:554` | `SellerSecurityController@signOutStaff` | مصادقة |
| 230 | DELETE | `…/seller-center/security/staff/{v}` | `sellerSecurityUri` L313 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:51` | `lavkoo:routes/rest_api/v3/seller.php:555` | `SellerSecurityController@destroyStaff` | مصادقة |
| 231 | GET | `…/seller-center/integrations/keys` | `sellerIntegrationsUri` L317 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:54` | `lavkoo:routes/rest_api/v3/seller.php:517` | `SellerIntegrationController@keys` | مصادقة |
| 232 | POST | `…/seller-center/integrations/keys` | `sellerIntegrationsUri` L317 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:57` | `lavkoo:routes/rest_api/v3/seller.php:522` | `SellerIntegrationController@storeKey` | مصادقة |
| 233 | DELETE | `…/seller-center/integrations/keys/{v}` | `sellerIntegrationsUri` L317 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:60` | `lavkoo:routes/rest_api/v3/seller.php:523` | `SellerIntegrationController@revokeKey` | مصادقة |
| 234 | GET | `…/seller-center/integrations/events` | `sellerIntegrationsUri` L317 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:63` | `lavkoo:routes/rest_api/v3/seller.php:518` | `SellerIntegrationController@events` | مصادقة |
| 235 | GET | `…/seller-center/integrations/webhooks` | `sellerIntegrationsUri` L317 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:66` | `lavkoo:routes/rest_api/v3/seller.php:519` | `SellerIntegrationController@webhooks` | مصادقة |
| 236 | POST | `…/seller-center/integrations/webhooks` | `sellerIntegrationsUri` L317 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:70` | `lavkoo:routes/rest_api/v3/seller.php:524` | `SellerIntegrationController@storeWebhook` | مصادقة |
| 237 | PUT | `…/seller-center/integrations/webhooks/{v}` | `sellerIntegrationsUri` L317 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:70` | `lavkoo:routes/rest_api/v3/seller.php:525` | `SellerIntegrationController@updateWebhook` | مصادقة |
| 238 | PUT | `…/seller-center/integrations/webhooks/{v}/status` | `sellerIntegrationsUri` L317 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:77` | `lavkoo:routes/rest_api/v3/seller.php:526` | `SellerIntegrationController@setWebhookStatus` | مصادقة |
| 239 | DELETE | `…/seller-center/integrations/webhooks/{v}` | `sellerIntegrationsUri` L317 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:80` | `lavkoo:routes/rest_api/v3/seller.php:527` | `SellerIntegrationController@destroyWebhook` | مصادقة |
| 240 | POST | `…/seller-center/integrations/webhooks/{v}/test` | `sellerIntegrationsUri` L317 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:84` | `lavkoo:routes/rest_api/v3/seller.php:528` | `SellerIntegrationController@testWebhook` | مصادقة |
| 241 | GET | `…/seller-center/integrations/deliveries` | `sellerIntegrationsUri` L317 | `lavkooappseller:lib/features/security/domain/repositories/security_repository.dart:88` | `lavkoo:routes/rest_api/v3/seller.php:520` | `SellerIntegrationController@deliveries` | مصادقة |

### 2.4 مسارات v3 التي لا يستدعيها التطبيق (23)

| المجال | إجمالي المسارات | يستدعيها التطبيق | غير مستدعاة |
|---|---|---|---|
| `auth/*` + `registration` | 8 | 8 | 0 |
| الحساب والمتجر (`seller-info`، `shop-info`، `seller-update`، `shop-update`، `language-change`، `cm-firebase-token`، `seller-delivery-man`، `account-delete`، `update-setup-guide-app`، `vacation-add`، `temporary-close`) | 12 | 11 | 1 |
| التقييمات | 3 | 3 | 0 |
| المالية الكلاسيكية (إحصاءات، `transactions`، سحب) | 9 | 7 | 2 |
| قوائم مرجعية (`brands`، `categories`، `top-delivery-man`) | 3 | 3 | 0 |
| `products/*` | 28 | 27 | 1 |
| `orders/*` | 15 | 10 | 5 |
| `clearance-sale/*` | 9 | 9 | 0 |
| `refund/*` | 4 | 4 | 0 |
| `coupon/*` | 7 | 7 | 0 |
| `shipping/*` + `shipping-method/*` | 10 | 9 | 1 |
| `messages/*` | 5 | 5 | 0 |
| `pos/*` | 8 | 7 | 1 |
| `delivery-man/*` (مع السحب وجهات الطوارئ وتحصيل النقد) | 20 | 20 | 0 |
| `notification/*` | 2 | 2 | 0 |
| `payment-information/*` | 7 | 7 | 0 |
| `seller-center/*` (17 مجموعة فرعية) | 86 | 84 | 2 |
| AI — `product/*` | 9 | 9 | 0 |
| AI — `auction/product/*` | 10 | 0 | 10 |
| TaxModule — `get-vat-tax-report-list` | 1 | 1 | 0 |
| **المجموع** | **256** | **233** | **23** |

| # | الفعل | المسار | التعريف | المتحكّم@الدالة | البوابة |
|---|---|---|---|---|---|
| 1 | — | `/api/v3/seller/account-delete` (طريقة مقيّدة) | `lavkoo:routes/rest_api/v3/seller.php:87-92` | `SellerController@account_delete` | مالك |
| 2 | GET | `/api/v3/seller/monthly-earning` | `lavkoo:routes/rest_api/v3/seller.php:113` | `SellerController@monthly_earning` | مصادقة |
| 3 | GET | `/api/v3/seller/monthly-commission-given` | `lavkoo:routes/rest_api/v3/seller.php:114` | `SellerController@monthly_commission_given` | مصادقة |
| 4 | GET | `/api/v3/seller/products/list` | `lavkoo:routes/rest_api/v3/seller.php:155` | `ProductController@getProductList` | مصادقة |
| 5 | PUT | `/api/v3/seller/orders/order-detail-status/{id}` | `lavkoo:routes/rest_api/v3/seller.php:205` | `OrderController@order_detail_status` | مصادقة |
| 6 | PUT | `/api/v3/seller/orders/assign-delivery-man` | `lavkoo:routes/rest_api/v3/seller.php:206` | `OrderController@assign_delivery_man` | مصادقة |
| 7 | PUT | `/api/v3/seller/orders/delivery-charge-date-update` | `lavkoo:routes/rest_api/v3/seller.php:208` | `OrderController@amount_date_update` | مصادقة |
| 8 | POST | `/api/v3/seller/orders/assign-third-party-delivery` | `lavkoo:routes/rest_api/v3/seller.php:209` | `OrderController@assign_third_party_delivery` | مصادقة |
| 9 | POST | `/api/v3/seller/orders/update-payment-status` | `lavkoo:routes/rest_api/v3/seller.php:210` | `OrderController@update_payment_status` | مصادقة |
| 10 | GET | `/api/v3/seller/shipping-method/edit/{id}` | `lavkoo:routes/rest_api/v3/seller.php:292` | `ShippingMethodController@edit` | مصادقة |
| 11 | GET | `/api/v3/seller/pos/get-categories` | `lavkoo:routes/rest_api/v3/seller.php:319` | `POSController@get_categories` | مصادقة |
| 12 | GET | `/api/v3/seller/seller-center/verification/document/{id}` | `lavkoo:routes/rest_api/v3/seller.php:430` | `SellerVerificationController@document` | مالك |
| 13 | GET | `/api/v3/seller/seller-center/brand-claims/{id}/documents/{documentId}` | `lavkoo:routes/rest_api/v3/seller.php:471` | `SellerBrandClaimController@document` | مصادقة |
| 14 | POST | `/api/v3/seller/auction/product/title-auto-fill` | `lavkoo:Modules/AI/routes/api.php:34` | `AIAuctionProductController@titleAutoFill` | مصادقة |
| 15 | POST | `/api/v3/seller/auction/product/description-auto-fill` | `lavkoo:Modules/AI/routes/api.php:35` | `AIAuctionProductController@descriptionAutoFill` | مصادقة |
| 16 | POST | `/api/v3/seller/auction/product/general-setup-auto-fill` | `lavkoo:Modules/AI/routes/api.php:36` | `AIAuctionProductController@generalSetupAutoFill` | مصادقة |
| 17 | POST | `/api/v3/seller/auction/product/shipping-policy-auto-fill` | `lavkoo:Modules/AI/routes/api.php:37` | `AIAuctionProductController@shippingPolicyAutoFill` | مصادقة |
| 18 | POST | `/api/v3/seller/auction/product/auction-info-auto-fill` | `lavkoo:Modules/AI/routes/api.php:38` | `AIAuctionProductController@auctionInfoAutoFill` | مصادقة |
| 19 | POST | `/api/v3/seller/auction/product/seo-section-auto-fill` | `lavkoo:Modules/AI/routes/api.php:39` | `AIAuctionProductController@seoSectionAutoFill` | مصادقة |
| 20 | POST | `/api/v3/seller/auction/product/analyze-image-auto-fill` | `lavkoo:Modules/AI/routes/api.php:40` | `AIAuctionProductController@generateTitleFromImages` | مصادقة |
| 21 | POST | `/api/v3/seller/auction/product/generate-title-suggestions` | `lavkoo:Modules/AI/routes/api.php:41` | `AIAuctionProductController@generateProductTitleSuggestion` | مصادقة |
| 22 | POST | `/api/v3/seller/auction/product/setup-auto-fill` | `lavkoo:Modules/AI/routes/api.php:42` | `AIAuctionProductController@setupAutoFill` | مصادقة |
| 23 | GET | `/api/v3/seller/auction/product/generate-limit-check` | `lavkoo:Modules/AI/routes/api.php:43` | `AIAuctionProductController@generateLimitCheck` | مصادقة |

ملاحظات على القائمة:
- **خمسة مسارات طلبات كلاسيكية** (تغيير الحالة، إسناد المندوب، رسوم/تاريخ التوصيل، التوصيل عبر طرف ثالث، حالة الدفع) لم يعد التطبيق يستدعيها؛ استبدلها كلها بنداء واحد `orders/order-detail-info-update` يحمل `order_status` و`payment_status` و`delivery_man_id` و`deliveryman_charge` و`expected_delivery_date` و`delivery_type` (`lavkooappseller:lib/features/order_details/domain/models/order_setup_model.dart:38-50`، `lavkoo:routes/rest_api/v3/seller.php:212`). بقاؤها في الخلفية يخدم نسخاً أقدم من التطبيق والمفاتيح المُصدَرة — وجود مستهلكين فعليين لها `UNKNOWN`.
- **حذف الحساب:** يُعرَّف المسار بطريقتين، أُبقيت إحداهما للنسخ المثبّتة، والتطبيق الحالي يستعمل الطريقة الأولى (`lavkoo:routes/rest_api/v3/seller.php:87-92`، `lavkooappseller:lib/features/profile/domain/repositories/profile_repository.dart:54-56`).
- **عشرة مسارات AI للمزادات** تشير إلى وحدة غير موجودة في المستودع: `Modules/` يحوي `AI` و`Blog` و`TaxModule` فقط بينما `lavkoo:modules_statuses.json:6` يعلن `"Auction": true`. نقطة `seller-info` محمية من ذلك لأن `getCheckAddonPublishedStatus` يشترط وجود `Modules/Auction/Addon/info.php` (`lavkoo:app/Utils/module-helper.php:694-712`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:219-239`).
- **لا يوجد مجال كامل غير مستعمل.** مسارات «المطوّرين» للبائع (مفاتيح API و webhooks تحت `seller-center/integrations`، 11 مساراً) يستدعيها التطبيق كلها؛ غير المستعمل في seller-center مساران لتنزيل مستندات (التحقق وادعاء العلامة).

SEC-E23-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

### 2.5 استعمال واجهات خارج v3

| النقطة | موضع الاستدعاء | التعريف في الخلفية | ما تحمله للبائع | السياق |
|---|---|---|---|---|
| `GET /api/v1/config` | `lavkooappseller:lib/features/splash/domain/repositories/splash_repository.dart:18` | `lavkoo:routes/rest_api/v1/api.php:66` | نموذج العملة وقائمة العملات وعدد الكسور ورمز الدولة والسمة النشطة وبوابة إصدار تطبيق البائع والصيانة (`lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:158`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:203`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:212-219`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:237`) | عام، قبل الدخول، مشترك مع تطبيق العميل |
| `POST /api/v1/app-health` | `lavkooappseller:lib/helper/app_health_reporter.dart:72` | `lavkoo:routes/rest_api/v1/api.php:97` | عدّادات جلسات/أعطال | عام |
| `GET /api/v1/business-pages` | `lavkooappseller:lib/features/splash/domain/repositories/splash_repository.dart:72` | `lavkoo:routes/rest_api/v1/api.php:67` | صفحات السياسات | عام |
| `GET /api/v1/attributes` | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:30` | `lavkoo:routes/rest_api/v1/api.php:208` | سمات المنتج | مرجعي مركزي |
| `GET /api/v1/vat-tax/get-taxVat-list` | `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:523` | `lavkoo:Modules/TaxModule/routes/api/v1/api.php:19` | الضرائب الفعّالة فقط (`is_active = 1`) بالحقول `id`,`name`,`tax_rate` مع تقسيم صفحات (افتراضياً 50)، ولا تصفية بالبلد أو الكيان القانوني (`lavkoo:Modules/TaxModule/app/Http/Controllers/Api/V1/TaxController.php:13-25`) | مرجعي عام |
| `GET /api/v1/mapapi/geocode-api`، `place-api-autocomplete`، `place-api-details` | `lavkooappseller:lib/features/order/domain/repositories/location_repository.dart:18`، `lavkooappseller:lib/features/order/domain/repositories/location_repository.dart:28`، `lavkooappseller:lib/features/order/domain/repositories/location_repository.dart:38` | `lavkoo:routes/rest_api/v1/api.php:519-526` | وسيط خرائط (ترميز جغرافي وإكمال عناوين) | مشترك مع تطبيق العميل |

لا يستدعي التطبيق أي مسار تحت `/api/v2`. مسارات Seller API v2 ما زالت مسجَّلة في الخلفية (`lavkoo:routes/rest_api/v2/api.php:26-27`) — تقييمها في E14.

**دلالة ذلك:** ثلاث من ثماني النقاط (الإعدادات، والسياسات، والضرائب) تحمل بيانات تتأثر مباشرة بقرارات GCC (العملة والكسور، بوابة الإصدار، السياسات، الضرائب) لكنها **مرجعية وتُطلب في الغالب قبل الدخول**، فلا يستطيع الخادم ربطها ببائع أو متجر. التطبيق يأخذ منها `system_default_currency` و`currency_model` و`decimal_point_settings` (`lavkooappseller:lib/features/splash/domain/models/config_model.dart:215`، `lavkooappseller:lib/features/splash/domain/models/config_model.dart:242`، `lavkooappseller:lib/features/splash/domain/models/config_model.dart:249`).

### 2.6 شكل الاستجابات: نماذج Eloquent خام، بلا طبقة Resource

مجموعة `api` نفسها توثّق ذلك: «The API returns JSON directly rather than through Resource classes» وتُلحق middleware يتعلّم شكل الاستجابة من حركة المرور الفعلية (`lavkoo:bootstrap/app.php:84-94`، `lavkoo:app/Http/Middleware/RecordApiResponseShape.php:28-31`). بحث `Resource` في `app/Http/Controllers/RestAPI/v3/seller` أعاد صفر نتيجة، وبحث `JsonResource|ResourceCollection` في متحكّمات v3 ومتحكّمات الوحدتين أعاد صفراً. أمثلة:
- `seller-info` يعيد صف `sellers` كاملاً مع علاقة `wallet` وعدّادين وبضعة مفاتيح إعدادات (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:207-241`)؛ المحجوب فقط `password` و`auth_token` (`lavkoo:app/Models/Seller.php:82-89`).
- `shop-info` يعيد صف `shops` مع مفاتيح مضافة (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:58-78`).
- `orders/list` يعيد عناصر الـ paginator مع علاقات `customer`/`shipping`/`deliveryMan`/`orderDetails`/`offlinePayments` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:92-126`).

**النتيجة التعاقدية:** العقد الفعلي للواجهة الكلاسيكية هو **مخطط قاعدة البيانات**. أي عمود يُضاف إلى `sellers`/`shops`/`orders`/`products` يظهر تلقائياً في الاستجابة (إضافة آمنة لأن نماذج Dart تتجاهل المفاتيح المجهولة)، وأي إعادة تسمية أو تغيير نوع أو تحويل علاقة من كائن إلى قائمة يكسر التطبيق المثبَّت دون أي تغيير في المتحكّم. مواضع تحليل صارمة في التطبيق لا تتحمّل الغياب: محفظة البائع تُقرأ بسبعة نداءات `.toDouble()` دون معالجة لقيمة `null` (`lavkooappseller:lib/features/profile/domain/models/profile_info.dart:157-165`)، ورمز الدخول يُقرأ كسلسلة من جذر الاستجابة (`lavkooappseller:lib/features/auth/domain/services/auth_service.dart:81-82`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:114`).

واجهة seller-center الحديثة تختلف: استجاباتها مبنيّة يدوياً في خدمات، وموثَّقة بسمة `#[ApiDoc]` (88 دالة من دوال متحكّمات v3 تحملها، بالجمهور `VENDOR_APP` — `lavkoo:app/Services/DeveloperPortal/ApiDoc.php:38`)، وهي التي تغطيها اختبارات العقد في التطبيق (القسم 2.9).

### 2.7 المطابقة الحقلية للتدفقات الحرجة

| التدفق | ما يرسله التطبيق | ما تقرؤه/تعيده الخلفية | النتيجة |
|---|---|---|---|
| الدخول | `email`,`password` (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:28-30`) | تحقق `email`,`password` ⇐ `{token}`؛ فشل ⇐ 401 مع `errors` و`loginStatus` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:58-65`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:115-129`) | متطابق. الاستجابة لا تحمل نوع المستخدم ولا صلاحياته ولا متجره (E19-F02) |
| استعادة كلمة المرور | `identity` ثم `identity`,`otp` ثم `identity`,`otp`,`password`,`confirm_password` مع `_method: put` (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:53`، `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:64-67`) | `POST forgot-password`، `POST verify-otp`، `PUT reset-password` (`lavkoo:routes/rest_api/v3/seller.php:57-59`) | متطابق (المسارات). منطق التحقق في E19 (مقيّد) |
| رمز الإشعارات | `cm_firebase_token` مع `_method: put` + اشتراك في موضوعين عامّين (`lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:89-94`) | تحقق `cm_firebase_token` ثم كتابة عمود واحد على صف البائع (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:619-635`) | متطابق. رمز واحد لكل بائع، بلا جهاز/متجر (E19-F05) |
| قائمة الطلبات | `limit`,`offset`,`status`,`order_current_status` (JSON)، ومن الفلتر `date_type`,`start_date`,`end_date`,`order_types`,`order_amount_settlement`,`payment_status`,`customer_id` (`lavkooappseller:lib/features/order/domain/repositories/order_repository.dart:17-40`) | يقرأ المفاتيح نفسها (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:59-99`) | متطابق. لا عملة في الصفوف؛ ثلاثة مجاميع مالية مضافة (`total_tax_amount`,`total_product_price`,`total_product_discount`) بلا وسم عملة |
| إعداد الطلب (الحالة/الدفع/المندوب) | تسعة مفاتيح في `OrderSetupModel` (`lavkooappseller:lib/features/order_details/domain/models/order_setup_model.dart:38-50`) | يتحقق من `order_id` و`payment_status in paid,unpaid` ثم يقرأ الباقي (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:586-595`) | متطابق |
| تعديل عنوان الطلب | `order_id`,`contact_person_name`,`phone`,`city`,`zip`,`email`,`address`,`latitude`,`longitude`,`address_type` (`lavkooappseller:lib/features/order/domain/repositories/order_repository.dart:53-64`) | يقبل المفاتيح نفسها **إضافةً إلى** `region_id`,`city_id`,`district`,`national_address` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:570-577`) | **فجوة حقلية:** التطبيق لا يرسل أياً من الحقول المنظَّمة ويرسل `city` نصاً دائماً، فيُعدّ الموقع «معدَّلاً» ويُمرَّر إلى التحقق المنظَّم (`lavkoo:app/Services/Address/OrderAddressService.php:131-144`). السلوك عند `unified_address_mode = soft|strict` `INFERRED` — يُحسم مع مدقق العناوين |
| إضافة/تحديث منتج | 53 مفتاحاً منها `unit_price`,`discount`,`shipping_cost`,`tax_ids`,`tax_model`,`category_id`,`brand_id`,`code`؛ التحديث يضيف `_method: put` و`id` (`lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:180`، `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:325`) | السعر والخصم الثابت وتكلفة الشحن تُحوَّل بـ `Convert::usd` قبل الحفظ (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1039-1041`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1546-1548`) | متطابق في المفاتيح. **لا حقل عملة ولا متجر:** سعر واحد لمنتج واحد، بعملة ضمنية عامة |
| فلتر السعر في قائمة المنتجات | `min_price`,`max_price` (`lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:34-35`) | يحوّلهما بـ `Convert::usdPaymentModule(…, request('currency_code'))` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:301-308`) | **عدم تطابق حقلي:** الخلفية تنتظر `currency_code` والتطبيق لا يرسله أبداً (بحث `currency_code` في `lib/` ⇐ صفر). الأثر عند `currency_model = multi_currency` `INFERRED` (تحويل بسعر 1) |
| تحديث المخزون | `product_id`,`current_stock`,`variation` مع `_method: put` (`lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:415-421`) | `PUT products/quantity-update` (`lavkoo:routes/rest_api/v3/seller.php:187`) | متطابق. كمية واحدة لكل منتج؛ الجرد متعدد المستودعات عبر `seller-center/inventory/*` فقط |
| المحفظة — السحب الكلاسيكي | `amount`,`withdraw_method_id` وحقول الطريقة (`lavkooappseller:lib/features/wallet/domain/repositories/wallet_repository.dart:60-66`) | يقارن `seller_wallets.total_earning` مع `Convert::usd(amount)` ويخزّن المبلغ محوَّلاً (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:455-469`) | متطابق. المبلغ يُرسَل بعملة العرض ويُحوَّل بسعر الصرف **العام** |
| المحفظة — الدفعات الحديثة | `amount`,`method`,`payout_currency` (`lavkooappseller:lib/features/seller_center/domain/repositories/seller_center_repository.dart:90-96`) | تحقق `amount`,`method`,`payout_currency` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:86-90`)؛ عملة الطلب = `baseCurrency()` (`lavkoo:app/Services/Marketplace/PayoutService.php:101-110`) | متطابق. **مساران متوازيان للسحب** على مخزنَي رصيد مختلفين (`seller_wallets` والـ ledger) والتطبيق يستدعي كليهما (الثابتان L29 و L251) |
| كشف الحساب | فلاتر `entry_type`,`status`,`from`,`to` | `summary.currency` + مبالغ مقرَّبة إلى خانتين (`lavkoo:app/Services/Marketplace/SellerLedgerStatementService.php:90-108`)؛ ملف CSV بلا عمود عملة (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerStatementController.php:87-91`) | متطابق. انظر 2.10 |
| تقرير ضريبة البائع | `limit`,`offset`,`start_date`,`end_date` (`lavkooappseller:lib/features/vat_management/domain/repositories/vat_repository.dart:13-20`) | يعيد `total_tax`,`total_orders`,`total_order_amount` وقائمة حركات (`lavkoo:Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:141-150`) | متطابق. `limit`/`offset` تعودان كما أُرسلتا (سلاسل) والتطبيق يحلّلهما بـ `int.tryParse` (`lavkooappseller:lib/features/vat_management/domain/models/vat_report_model.dart:27-28`). لا عملة ولا كيان قانوني |
| POS | `cart`,`customer_id`,`payment_method`,`paid_amount`,`coupon_code`,`extra_discount`… | `place_order` يقرأ 11 مفتاحاً (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:276-435`)؛ `customer-store` يقبل `city`,`country`,`zip_code` نصاً (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:131-177`) | متطابق في المسارات والأفعال. لا عملة في الطلب؛ الدفع بمحفظة العميل ووصول البائع إلى العملاء مقيَّمان في E14 (مقيّد) |
| القسائم | `coupon_type`,`discount_type`,`discount`,`min_purchase`,`max_discount`,`limit`,`start_date`,`expire_date`… | `min_purchase` و`discount` (إن كان مبلغاً) و`max_discount` تُحوَّل بـ `Convert::usd` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/CouponController.php:82-87`) | متطابق. قيم القسيمة بعملة ضمنية عامة (GCC-17) |
| طرق الشحن | `title`,`duration`,`cost` | `cost` يُحوَّل بـ `BackEndHelper::currency_to_usd` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShippingMethodController.php:34`) | متطابق. تكلفة بعملة ضمنية |
| الفاتورة | `GET orders/{id}/invoice` (`lavkooappseller:lib/features/order_details/domain/repositories/order_details_repository.dart:164-166`) | مصفوفة بايتات (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:140-150`) | متطابق. تعليق الثابت يوثّق أن النسخة السابقة كانت تستدعي نقطة v1 للعميل فتنتهي بـ 401 وخروج قسري (`lavkooappseller:lib/utill/app_constants.dart:228-233`) — مثال حيّ على أثر الخلط بين الواجهات |

### 2.8 توقعات الـ middleware كما يراها العميل

**سلسلة التنفيذ على كل مسار محمي:** مجموعة `api` (`throttle:3000,1` + تتبّع/مراقبة/تسجيل شكل الاستجابة — `lavkoo:bootstrap/app.php:84-94`) ⇐ `api_lang` ⇐ `seller_api_auth` ⇐ (`seller_can:…` أو `seller_owner`). الصنف `SellerApiMiddleware` موجود لكنه **لا يفعل شيئاً** (`lavkoo:app/Http/Middleware/SellerApiMiddleware.php:17-20`) وليس له alias في `lavkoo:bootstrap/app.php:121-127`.

**`SellerApiAuthMiddleware`:** يأخذ الجزء الثاني من ترويسة `authorization` ويشترط طولاً أكبر من 30 (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:43-45`)، يحلّه إلى principal (مالك، ثم موظف، ثم مفتاح API مُصدَر) ويضع `$request['seller']` = **المتجر/البائع** و`seller_principal` = الشخص (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:56-64`، `lavkoo:app/Services/Marketplace/SellerPermissionService.php:98-120`). المفتاح المُصدَر يُرفض على أي مسار لا يعلن `seller_can` (`lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:66-79`، `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:102-117`). كل متحكّم يستنتج النطاق من `$request->seller->id` — أي أن **الرمز هو حامل السياق الوحيد**.

**أشكال الأخطاء (خمسة أشكال فعلية):**

| الحالة | الكود | الجسم | الدليل | ما يفعله التطبيق |
|---|---|---|---|---|
| رمز غير صالح/منتهٍ/بائع غير معتمد | 401 | `{"auth-001": "<نص>"}` — بلا `errors` وبلا `message` | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:90-92` | المعالج لا يجد `loginStatus` ولا `errors` ولا `message` فيضع `'unauthorized'` (`lavkooappseller:lib/data/datasource/remote/exception/api_error_handler.dart:62-78`)، و`ApiChecker` عندها **يمسح الجلسة ويعيد إلى شاشة الدخول** (`lavkooappseller:lib/helper/api_checker.dart:21-43`) |
| لا principal على مسار `seller_can`/`seller_owner` (خطأ توصيل) | 401 | `{"errors":[{"code":"auth-001","message":…}]}` | `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:32-40`، `lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:28-32` | يأخذ `errors[0].message` ⇐ رسالة تُعرض، **بلا** خروج |
| فشل الدخول | 401 | `errors` + `loginStatus: pending|unauthorized` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:115-129` | يقرأ `loginStatus` (`lavkooappseller:lib/features/auth/domain/services/auth_service.dart:87-90`) |
| صلاحية ناقصة / ليس المالك / مفتاح على مسار بلا نطاق | 403 | `{"errors":[{"code":"permission"|"owner_only"|"api_key","message":…}]}` | `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:50-54`، `lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:34-38`، `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:75-79` | يعرض `key` أو `errors[0].message` أو `message` (`lavkooappseller:lib/data/datasource/remote/exception/api_error_handler.dart:49-61`) |
| فشل التحقق من المدخلات / رفض منطقي | **403** غالباً | `{"errors":[{code,message}]}` أو `{"message":…}` أو `{"success":0,"message":…}` | `lavkoo:app/Utils/Helpers.php:313-320`؛ مثال `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:91-93` و`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:105-107` | كالسابق |

إحصاء أكواد الحالة الصريحة في متحكّمات v3 (بحث `], <code>)` و`, <code>);`): 200 ⇐ 206 موضعاً، **403 ⇐ 104**، 404 ⇐ 30، 202 ⇐ 13، 401 ⇐ 4، 422 ⇐ 2، 203 ⇐ 1، 400 ⇐ 1. أي أن 403 هو كود الخطأ العام (تحقق + صلاحية + رفض منطقي)، و202/203 يُستعملان لإخفاقات «ناعمة» بجسم `success: 0`؛ وبعض إخفاقات التحقق تعود بـ 200 لأن الاستجابة لا تحدد كوداً (مثال `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:296` على مسار لا يستدعيه التطبيق الحالي).

**قاعدتان ضمنيتان يعتمد عليهما كل بناء مثبَّت:**
1. **401 بلا `message` وبلا `errors` = اطرد المستخدم وامسح جلسته.** أي رفض جديد (مثلاً «هذا المتجر خارج نطاقك») يعود بـ 401 بهذا الشكل سيُخرج البائع من التطبيق؛ والعكس: لو أُضيف `message` إلى استجابة `seller_api_auth` فلن يخرج صاحب الرمز المنتهي أبداً وسيرى رسالة خطأ على كل شاشة.
2. **403 مع `errors[0].message` = اعرض الرسالة وابقَ.** هذا هو الشكل الوحيد الآمن لأي رفض نطاق جديد.

**طلبات multipart بلا `Accept`:** `EnsureSellerPermission` يعيد صفحة HTML (`seller-views.permission-denied`) لا JSON حين لا يكون الطلب `expectsJson()` (`lavkoo:app/Http/Middleware/EnsureSellerPermission.php:50-58`). ستة من مواضع multipart التسعة (تخدم سبعة مسارات) تمرّ بمسارات `seller_can` وترسل `Authorization` فقط، فالموظف الناقص الصلاحية عند رفع صورة منتج أو تحديث المتجر أو إرسال مرفق محادثة يستلم — `INFERRED` من منطق `expectsJson` في الإطار — جسماً بصيغة HTML يعامله التطبيق كنجاح نقل ثم يحاول تحليله. والنتيجة نفسها تنتظر أي رفض نطاق يُبنى على هذا الـ middleware.

### 2.9 اختبارات العقد

**في التطبيق:** 14 ملفاً `test/*_contract_test.dart` تضم **122 اختباراً** (action_center 6، automation 12، brand_claim 6، bulk_job 5، control_tower 10، finance_control 10، inventory 7، order_breakdown 6، pricing_policy 5، report 14، return 6، security 13، seller_center 17، statement 5). كلها تحلّل حمولات JSON ثابتة مكتوبة داخل الملف وموصوفة بأنها «captured verbatim from a running backend» (مثال `lavkooappseller:test/statement_contract_test.dart:6-13`)، وتُشغَّل في CI التطبيق عبر `flutter test` (`lavkooappseller:.github/workflows/ci.yml:41-42`).

| الملف | النقطة المثبَّتة | ما يثبّته بالتحديد |
|---|---|---|
| `statement_contract_test.dart` | `seller-center/statement` | نطاقان في حمولة واحدة (`range` يتبع الفلتر، `buckets`/`withdrawable` للحساب كله)؛ `summary.currency = "SYP"` (`lavkooappseller:test/statement_contract_test.dart:20-23`، `lavkooappseller:test/statement_contract_test.dart:37`) |
| `order_breakdown_contract_test.dart` | `orders/{id}/breakdown` | `seller_receives` يبقى `null` لطلب لم تُسجَّل أرباحه؛ `fees.currency = "SYP"` (`lavkooappseller:test/order_breakdown_contract_test.dart:16`) |
| `control_tower_contract_test.dart` | `seller-center/control-tower` و`/briefing` | ترتيب الأقسام من الخادم؛ النسبة اليومية `null` مقصودة؛ `balance.currency = "SYP"` (`lavkooappseller:test/control_tower_contract_test.dart:50`) |
| `seller_center_contract_test.dart` | `overview`,`scorecard`,`verification`,`payouts`,`analytics` | طلب الدفعة يحمل `currency`,`payout_currency`,`payout_amount`,`exchange_rate` (`lavkooappseller:test/seller_center_contract_test.dart:114`) |
| `finance_control_contract_test.dart` | `finance/reconciliation`,`fee-simulator`,`price-changes` | `reconciles` يُقرأ من الخادم ولا يُعاد حسابه |
| `pricing_policy_contract_test.dart` | `finance/pricing-policy` | حالة «لا حدّ ولا تكلفة مسجّلة» |
| `report_contract_test.dart` | `reports/orders|products|stock` | تطابق أرقام التطبيق مع لوحة البائع |
| `return_contract_test.dart` | `returns` | التمييز بين `received` و`restocked` |
| `inventory_contract_test.dart` | `inventory/*` | `warehouses_enabled`/`batches_enabled` افتراضهما `false`؛ `balance_after` |
| `bulk_job_contract_test.dart` | `bulk-jobs` | النتيجة الجزئية (نجح 2 ورُفض 1 بسببه) |
| `automation_contract_test.dart` | `automation/*` | حقيبة الإعدادات `[]` و`{}` بمعنى واحد؛ التشغيل المحدود |
| `action_center_contract_test.dart` | `action-center` | `title` مفتاح ترجمة؛ `entity_id` سلسلة |
| `brand_claim_contract_test.dart` | `brand-claims/*` | «لا ادعاء» لا يعني «ممنوع» |
| `security_contract_test.dart` | `security/*` و`integrations/*` | الأشكال الفارغة أولاً؛ `last_used_at = null` |

**في الخلفية:** بحث 22 اسماً (`ControlTower`، `OrderBreakdown`، `ActionCenter`، `SellerSecurity`، `SellerPermission`، `SellerReport`، `BrandClaim`، `SellerReturn`، `SellerInventory`، `BulkJob`، `FinanceControl`، `SellerLedgerStatement`، `Automation`، `SellerCenter`، `PricingPolicy`، `PayoutService`، `SellerApiKey`، `SellerPrincipal`، `SellerVerification`، `DailyBriefing`، `SellerInvoice`، `VendorLedger`) في `lavkoo:tests` أعاد **صفراً لـ 21 اسماً** وملفاً واحداً لـ `VendorLedger` لا يخص العقد. بحث `v3/seller` في `tests/` أعاد ملفين: اختبار عناوين يستدعي دالتَي متحكّم مباشرة لا عبر التوجيه (`lavkoo:tests/Feature/Address/ApiSellerAddressTest.php:61`، `lavkoo:tests/Feature/Address/ApiSellerAddressTest.php:248`) واختباراً يعدّ ورود نص في ملف المتحكّم. CI الخلفية يشغّل phpunit (`lavkoo:.github/workflows/ci.yml:72-75`). اختبار واحد في `lavkoo:tests/Feature/Address/ApiSellerAddressTest.php` يستدعي دالتي متحكّم من v3 مباشرةً (`lavkoo:tests/Feature/Address/ApiSellerAddressTest.php:61`) ويتحقق من أكواد الاستجابة وشكل أخطاء الحقول في سيناريوهات تعديل عنوان الطلب، ويستدعي كذلك دالة إنشاء طلب POS (`lavkoo:tests/Feature/Address/ApiSellerAddressTest.php:248`)، ولا اختبار يثبّت شكل بقية نقاط البائع.

**الخلاصة:** العقد مثبَّت **من جهة واحدة** (العميل) وبحمولات ملتقطة يدوياً من قاعدة بيانات عملتها `SYP`، ومحصور بواجهة seller-center؛ الواجهة الكلاسيكية (نحو 150 مساراً: دخول، منتجات، طلبات، محفظة، POS، مناديب…) بلا اختبار عقد في التطبيق، وفي الخلفية لنقطتين فقط. الخلفية تملك بديلاً وقت التشغيل: منفذ المطوّرين (`ApiManifest`، مولّد OpenAPI، و`ApiSnapshotService` الذي يلتقط سطح الـ API ويصنّف الفروق إلى BREAKING/WARNING — `lavkoo:app/Services/DeveloperPortal/ApiSnapshotService.php:10-22`)، لكن لقطاته في قاعدة البيانات لا في المستودع (بحث عن ملفات `openapi*.json|yaml` و`*.postman_collection.json` خارج `vendor/` ⇐ صفر)؛ وجود لقطة إنتاجية `UNKNOWN`.

### 2.10 عقد المال: عملة ضمنية عامة وتحويل في العميل

هذا أهم ما في العقد لقرارات GCC، وهو قائم على ثلاث آليات متراكبة:

1. **القراءة — أرقام بلا عملة يحوّلها العميل.** النقاط الكلاسيكية تعيد مبالغ خاماً من الأعمدة. التطبيق يعرضها عبر `PriceConverter.convertPrice`: إن كان `currency_model = single_currency` عرضها كما هي، وإلا ضربها في `myCurrency.exchangeRate / usdCurrency.exchangeRate`، ثم قرّبها إلى `decimal_point_settings` وألصق رمز العملة — وكلها قيم **عامة** من `/api/v1/config` (`lavkooappseller:lib/helper/price_converter.dart:18-33`).
2. **الكتابة — الخادم يحوّل بسعر عام.** المبالغ الواردة (سعر المنتج، الخصم، تكلفة الشحن، قيم القسيمة، مبلغ السحب، حدّ الطلب الأدنى) تمرّ على `Convert::usd` أو `BackEndHelper::currency_to_usd`، وكلاهما يقرأ `currency_model` و`system_default_currency` العامّين (`lavkoo:app/Utils/Convert.php:9-22`). والاتجاه المعاكس `Convert::default` يقرّب إلى **خانتين** ثابتتين (`lavkoo:app/Utils/Convert.php:51-63`) ويُستعمل في `seller-info` (`lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:217-218`).
3. **النقاط الحديثة — وسم عملة موجود لكنه عام ومُهمَل في العرض.** أربع حمولات تحمل `currency`: ملخص الكشف، تفصيل الطلب، الإحاطة اليومية، وطلب الدفعة (`lavkoo:app/Services/Marketplace/SellerLedgerStatementService.php:91`، `lavkoo:app/Services/Marketplace/SellerOrderBreakdownService.php:63`، `lavkoo:app/Services/SellerIntelligence/DailyBriefingService.php:174`، `lavkoo:app/Services/Marketplace/PayoutService.php:106`). مصدرها واحد: `VendorLedger::baseCurrency()` = رمز عملة `system_default_currency` (`lavkoo:app/Services/Marketplace/VendorLedger.php:227-241`). التطبيق يحلّل الحقل في أربعة نماذج (`lavkooappseller:lib/features/statement/domain/models/statement_models.dart:107`، `lavkooappseller:lib/features/order_details/domain/models/order_breakdown_model.dart:99`، `lavkooappseller:lib/features/control_tower/domain/models/control_tower_models.dart:266`، `lavkooappseller:lib/features/seller_center/domain/models/payout_models.dart:83`) لكنه **لا يستعمله في العرض**: بحث `\.currency\b` خارج الإعدادات في `lib/` أعاد تعريفات الحقول فقط، وشاشة الكشف تعرض كل مبلغ عبر `PriceConverter.convertPrice` العام (`lavkooappseller:lib/features/statement/screens/statement_screen.dart:105-118`)، وورقة طلب الدفعة «تعيد» المبلغ المكتوب إلى عملة الأساس عبر السعر العام قبل الإرسال (`lavkooappseller:lib/features/seller_center/widgets/request_payout_sheet_widget.dart:128-145`).

التقريب: مبالغ الكشف تُقرَّب في الخدمة إلى خانتين (`lavkoo:app/Services/Marketplace/SellerLedgerStatementService.php:94-107`)؛ التخزين نفسه أدقّ (مثال `order_transactions.tax` بنوع `decimal(21,12)` — `lavkoo:database/migrations/2026_01_22_163845_update_order_transactions_amount_columns.php:19`)، فالقيد على الخانات الثلاث في **طبقة الـ API والعميل** لا في العمود.

## 3. افتراضات المتجر الواحد / الدولة / العملة

| # | الافتراض | الدليل | الحالة |
|---|---|---|---|
| 1 | الرمز ⇐ بائع واحد ⇐ نطاق واحد؛ لا بُعد متجر/دولة/كيان في أي طلب أو استجابة | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:56-64`؛ بحثا القسم 2.2 بند 2 | VERIFIED |
| 2 | خادم واحد ثابت في البناء، بلا مسار دولة | `lavkooappseller:lib/utill/app_constants.dart:11` | VERIFIED |
| 3 | عملة العرض والتحويل وعدد الكسور قيم عامة من نقطة v1 عامة قبل الدخول | `lavkooappseller:lib/helper/price_converter.dart:18-29`، `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:212-219` | VERIFIED |
| 4 | تحويل المبالغ المكتوبة بسعر `system_default_currency` العام | `lavkoo:app/Utils/Convert.php:9-22` | VERIFIED |
| 5 | تقريب إلى خانتين في `Convert::default` وفي ملخص الكشف | `lavkoo:app/Utils/Convert.php:62`، `lavkoo:app/Services/Marketplace/SellerLedgerStatementService.php:94-107` | VERIFIED |
| 6 | وسم `currency` في الحمولات الحديثة = عملة المنصّة الافتراضية لا عملة كيان | `lavkoo:app/Services/Marketplace/VendorLedger.php:235-237` | VERIFIED |
| 7 | سعر واحد ومخزون واحد لكل منتج في عقد الإضافة/التحديث | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1039`، `lavkoo:routes/rest_api/v3/seller.php:187` | VERIFIED |
| 8 | قائمة ضرائب واحدة للمنصّة كلها | `lavkoo:Modules/TaxModule/app/Http/Controllers/Api/V1/TaxController.php:24-25` | VERIFIED |
| 9 | رمز إشعارات واحد لكل بائع وموضوعان عامّان (`lavkoo_seller`، `maintenance_mode_start_vendor`) | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:630-632`، `lavkooappseller:lib/utill/app_constants.dart:400-401` | VERIFIED |
| 10 | عنوان نصّي حرّ (`city`,`country`,`zip`) في تعديل عنوان الطلب وإنشاء عميل POS | `lavkooappseller:lib/features/order/domain/repositories/order_repository.dart:53-64`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/POSController.php:131-177` | VERIFIED |
| 11 | بوابة إصدار واحدة لتطبيق البائع لكل المنصّة | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:237`، `lavkooappseller:lib/features/splash/domain/models/config_model.dart:331-332` | VERIFIED |
| 12 | الحمولات المثبَّتة في اختبارات العقد بعملة `SYP`، وقائمة اللغات برمز دولة `SY`، وتعليق «this build serves a Syrian store» | `lavkooappseller:test/statement_contract_test.dart:20`، `lavkooappseller:lib/utill/app_constants.dart:407-415` | VERIFIED |
| 13 | علاقة `Seller::shop()` بصيغة `hasOne` هي ما تستعمله متحكّمات v3؛ توجد `shops()` بصيغة `hasMany` | `lavkoo:app/Models/Seller.php:96-104` | VERIFIED (العلاقة)؛ استعمال `shops()` يُحسم في E14 |

## 4. الأثر على قرارات GCC المعتمدة

### 4.1 جدول الأثر

| القرار / الأساس | ما يدعمه العقد اليوم | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Foundation: distinct concepts (Seller ≠ Storefront ≠ Legal Entity) | كل شيء يُشتق من `$request->seller` بعد حلّ الرمز؛ العميل لا يرسل معرّف نطاق إلا `seller_id` في مسارين ويُرفض إن خالف الرمز | لا حقل ولا ترويسة لمتجر/كيان؛ إدخال النطاق يجب ألا يعيد استعمال `seller_id` في المسار بمعنى الدولة | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:143-151`، `lavkoo:routes/rest_api/v3/seller.php:696-697` |
| Foundation: separate legal entity per country (فواتير، ضرائب، تسويات) | حمولات الـ ledger تحمل `currency`؛ الفاتورة تُولَّد في الخادم | `currency` عام؛ تقرير الضريبة وملف CSV للكشف وتصديرات التقارير بلا عملة ولا كيان | `lavkoo:app/Services/Marketplace/VendorLedger.php:227-241`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerStatementController.php:87-91` |
| Foundation: independent prices/SKUs/inventory per country؛ لا تحويل آلي | — | عقد المنتج يحمل `unit_price` واحداً ويحوّله الخادم بسعر صرف عام؛ والتطبيق يضرب كل مبلغ معروض بسعر صرف عام — أي **تحويل آلي في طرفَي العقد** | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1039`، `lavkooappseller:lib/helper/price_converter.dart:24` |
| Foundation: one fulfillment source per product per storefront | تغيير حالة الطلب يمرّ على حارس التنفيذ في الخادم؛ التطبيق لا يختار مزوّداً | لا شيء في العقد يمنع ذلك؛ يبقى القرار خادمياً | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:406-408` |
| GCC-08 / GCC-18 (شجرة فئات وعلامات وسمات مركزية) | `categories` و`brands` و`/api/v1/attributes` قوائم عامة غير مقيَّدة ببائع | رؤية الفئة حسب الدولة تحتاج تصفية خادمية بسياق البائع؛ نقطة السمات v1 مرجعية ولا تحمل سياق البائع | `lavkoo:routes/rest_api/v3/seller.php:139`، `lavkoo:routes/rest_api/v3/seller.php:147`، `lavkoo:routes/rest_api/v1/api.php:206-210` |
| GCC-09 (تدقيق تطبيق البائع) | لا يستهلك التطبيق أي نقطة سمة ديناميكية (E19) | `active_theme` العام في `config` | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:231` |
| GCC-11 (سياسات لكل دولة) | — | التطبيق يجلب السياسات من `GET /api/v1/business-pages` العامة | `lavkooappseller:lib/utill/app_constants.dart:200`، `lavkoo:routes/rest_api/v1/api.php:67` |
| GCC-12 (إيقاف لكل متجر دون كسر الالتزامات) | — | لا تمثيل تعاقدي لإيقاف متجر؛ الموجود صيانة شاملة في `config` وإغلاق مؤقت يضبطه البائع نفسه (`temporary-close`) | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:258`، `lavkoo:routes/rest_api/v3/seller.php:130-131` |
| GCC-13 (محافظ ودفاتر منفصلة لكل متجر/كيان/عملة) | الـ ledger الحديث يختم كل قيد بعملة | مساران للسحب على مخزنَين؛ `seller-info.wallet` كائن واحد بسبعة أرقام يحلَّل بلا حماية؛ دفع POS من محفظة العميل بلا نطاق | `lavkooappseller:lib/features/profile/domain/models/profile_info.dart:157-165`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:455-469` |
| GCC-14 (دخول واحد بنطاقات متعددة) | نموذج principal (مالك/موظف/مفتاح) مع 212 مساراً معلَّناً بـ `seller_can`؛ رفض 403 بشكل ثابت | الـ principal مرتبط ببائع واحد؛ استجابة الدخول لا تحمل نطاقات؛ 25 مساراً بمصادقة فقط | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:98-120`، `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:42-54` |
| GCC-16 (شحن محلي وعابر) | طرق الشحن وتكاليف الفئات يملكها البائع | التكلفة بعملة ضمنية؛ العنوان نصّي حرّ في نقطتَي البائع | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ShippingMethodController.php:34`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:570-577` |
| GCC-17 (قواعد قسائم محلية) | القسيمة مملوكة للبائع | المبالغ تُحوَّل بسعر عام | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/CouponController.php:82-87` |
| GCC-19 (السعودية افتراضياً) | غياب أي سياق في البناءات المثبّتة يسمح بتعريف «بلا سياق = السعودية» دون تغيير في العميل | يشترط أن يبقى كل بائع قائم مرتبطاً بمتجر السعودية وألا يتغير شكل أي استجابة له | القسم 4.3 |
| Provisional: 3 خانات عشرية (KWD/BHD/OMR) | أعمدة مالية بدقة عالية | تقريب إلى خانتين في `Convert::default` وفي خدمة الكشف؛ `toStringAsFixed(2)` في مواضع بالتطبيق | `lavkoo:app/Utils/Convert.php:62`، `lavkooappseller:lib/helper/price_converter.dart:187` |
| Provisional: market-scoped app-local caches/notifications | — | انظر E19-F05 و E19-F15 | — |

### 4.2 مبدأ التوافق الخلفي المقترح (توثيق فقط)

لأن **الرمز هو حامل السياق الوحيد** ولأن كل متحكّم يشتق نطاقه من `$request->seller`، فأقلّ الطرق كسراً هو أن يُشتق المتجر/الكيان القانوني **في الخادم من البائع المحلول من الرمز**، لا من مدخل يرسله العميل. بهذا:
- البناءات المثبّتة (لا ترسل شيئاً) تبقى تعمل دون تحديث: بائعها مرتبط بمتجر السعودية (GCC-19) فتحصل على سلوك اليوم حرفياً.
- طلبات multipart التسعة التي لا تمرّ بـ `DioClient` لا تحتاج تعديلاً لتحمل السياق.
- المفاتيح المُصدَرة والـ webhooks ترث النطاق من بائعها.
- لا يُعاد استعمال `seller_id`/`shop_id` بمعنى الدولة.

ما لا يحلّه هذا المبدأ وحده: (أ) المال — العميل يحوّل ويعرض بعملة عامة؛ (ب) النقاط العامة على v1؛ (ج) بائع أو موظف يعمل في أكثر من دولة بدخول واحد (يحتاج اختياراً صريحاً للمتجر — E19-F01).

### 4.3 التغييرات التعاقدية اللازمة مرتّبةً بخطر الكسر

| الترتيب | التغيير | خطر الكسر على البناءات المثبّتة | الافتراضي المتوافق (السعودية) | الدليل على موضع الكسر |
|---|---|---|---|---|
| 1 | **دلالة المال في الكتابة:** مبلغ السحب/الدفعة، سعر المنتج، القسيمة، تكلفة الشحن، `paid_amount` في POS — كلها تُرسَل بعملة العرض العامة ويحوّلها الخادم بسعر عام | **مرتفع (مالي):** بائع في دولة أخرى على بناء قديم يكتب مبالغ تُفسَّر بعملة السعودية | إبقاء `Convert::usd` كما هو لبائعي متجر السعودية؛ للمتاجر الأخرى تفسير المبلغ بعملة متجر البائع خادمياً، مع رفض البناءات التي لا تعلن دعمها (بند 9) | `lavkoo:app/Utils/Convert.php:9-22`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:456-468` |
| 2 | **دلالة المال في القراءة:** إضافة `currency` وعدد الكسور إلى سياق البائع أو إلى كل حمولة مالية، وتوقّف العميل عن الضرب بسعر الصرف العام | **مرتفع للمتاجر غير السعودية / معدوم للسعودية:** البناء القديم يعرض مبلغ الدينار برمز الريال وبكسرين | إضافة حقول فقط؛ قيم `config` العامة تبقى قيم السعودية | `lavkooappseller:lib/helper/price_converter.dart:18-33`، `lavkooappseller:lib/features/statement/screens/statement_screen.dart:105-118` |
| 3 | **شكل رفض النطاق:** أي رفض «خارج نطاقك/المتجر موقوف» يجب أن يكون 403 بـ `errors[0].message`؛ يُمنع 401 | **مرتفع إن خولف** (خروج قسري ومسح جلسة)، منخفض إن التُزم | لا تغيير على مسار 401 الحالي | `lavkooappseller:lib/helper/api_checker.dart:21-43`، `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:90-92` |
| 4 | **ثبات شكل `seller-info` و`shop-info` والدخول:** `wallet` كائن واحد بأرقامه السبعة غير null، و`token` سلسلة في الجذر | **مرتفع إن تغيّر النوع** (انهيار تحليل عند كل فتح للتطبيق) | محفظة المتجر الافتراضي تبقى في الموضع نفسه؛ محافظ الكيانات الأخرى في مفتاح جديد | `lavkooappseller:lib/features/profile/domain/models/profile_info.dart:157-165`، `lavkooappseller:lib/features/auth/domain/services/auth_service.dart:81-82` |
| 5 | **التسلسل الخام لـ Eloquent:** أي عمود نطاق جديد على `sellers`/`shops`/`orders`/`products` يتسرب إلى الاستجابة؛ وأي إعادة تسمية تكسر | متوسط: الإضافة آمنة، التعديل كاسر، ولا اختبار يكشفه | إضافة أعمدة فقط؛ حجب ما لا يلزم بـ `$hidden`؛ التقاط لقطة `ApiSnapshotService` قبل وبعد | `lavkoo:bootstrap/app.php:90-93`، `lavkoo:app/Models/Seller.php:82-89` |
| 6 | **بدائل مقيَّدة بالبائع للنقاط العامة:** عملة/كسور/ضريبة/سياسات/حالة إيقاف المتجر تُقرأ بعد الدخول من نقطة v3 جديدة | متوسط: نقاط جديدة فقط؛ القديمة تبقى | نقاط v1 تبقى تعيد قيم المتجر الافتراضي | القسم 2.5 |
| 7 | **اختيار المتجر لمن يعمل في عدة دول** (قائمة متاجر الـ principal + تبديل) | متوسط إلى مرتفع: يمسّ الجلسة، وتسعة طلبات multipart، وطابور POS المحلي، والمخابئ المحلية | من له متجر واحد لا يرى شيئاً جديداً | `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:39-40`؛ E19-F08، E19-F15 |
| 8 | **الضرائب:** قائمة الضرائب وتقرير الضريبة حسب الكيان القانوني للبائع | متوسط: القائمة العامة تعرض ضرائب دول أخرى عند إنشاء المنتج | القائمة الحالية = ضرائب السعودية | `lavkoo:Modules/TaxModule/app/Http/Controllers/Api/V1/TaxController.php:24-25` |
| 9 | **بوابة إصدار لكل متجر أو رفض خادمي للبناء القديم على المتاجر غير السعودية:** البوابة الحالية واحدة للمنصّة | متوسط: رفع الحد العام يجبر بائعي السعودية على التحديث بلا داعٍ | الحد العام يبقى؛ الشرط الجديد يُطبَّق خادمياً على المتاجر الجديدة فقط (403 بالشكل الآمن) | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:237`؛ E19-F06 |
| 10 | **العناوين:** حقول منظَّمة (`region_id`,`city_id`,`district`,`national_address`) في تعديل عنوان الطلب وعميل POS | متوسط: الخادم يقبلها أصلاً؛ التطبيق لا يرسلها | بقاء المسار النصّي للبناءات القديمة ما دام وضع العناوين يسمح | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:570-577` |
| 11 | **الإشعارات:** تسجيل جهاز لكل principal/متجر وحمولة تحمل المتجر | متوسط: المسار الحالي يبقى للجهاز الواحد | العمود الحالي = جهاز المتجر الافتراضي | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:630-632`؛ E19-F05 |
| 12 | **المستهلكون الخارجيون:** مفاتيح API والـ webhooks تشارك العقد نفسه | متوسط: مستهلكون لا يمكن تحديثهم بإصدار تطبيق | المفتاح يرث متجر بائعه؛ لا حقول إلزامية جديدة | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:66-79` |
| 13 | **معرّف البائع في المسار** (`products/{seller_id}/…`) | منخفض: يبقى مساوياً لبائع الرمز | بلا تغيير | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:148-151` |
| 14 | **اصطلاحات النقل** (`_method`، `limit`/`offset`، 403 للتحقق) | منخفض إن لم تُمسّ؛ **أي «تنظيف» لها كاسر** | بلا تغيير | القسم 2.2 |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | الإضافة الدنيا الموصى بها |
|---|---|---|---|
| المسارات الـ 233 التي يستدعيها التطبيق (مسار + فعل + `_method`) | Preserve | بناءات مثبّتة تعتمد عليها حرفياً؛ تطابق 100% اليوم | لا تغيير في المسار أو الفعل؛ أي جديد بمسار جديد |
| اشتقاق النطاق من الرمز (`$request->seller` + `seller_principal`) | Preserve + Extend | حامل السياق الوحيد وأساس 212 بوابة صلاحية | إلحاق المتجر/الكيان المحلول على الـ principal خادمياً |
| أشكال الخطأ (401 الطارد، 403 بـ `errors[]`) | Preserve | سلوك الجلسة في العميل مبني عليها | توثيقها كعقد صريح؛ كل رفض نطاق = 403 بالشكل الآمن |
| `seller_can` / `seller_owner` والفشل المغلق للمفاتيح | Preserve + Extend | نموذج GCC-14 يُبنى فوقه | إعلان صلاحية على المسارات الـ 25 ذات المصادقة فقط حيث يلزم (تفصيل في الملاحق المقيّدة) |
| التسلسل الخام للنماذج في الواجهة الكلاسيكية | Preserve (الشكل) | تغييره كسر شامل | حجب الأعمدة الجديدة غير اللازمة؛ لقطة سطح قبل/بعد |
| `Convert::usd` / `Convert::default` على حدود الـ API | Extend (لا Refactor) | إعادة كتابتها تغيّر أرقام بائعي السعودية | مسار تحويل/تفسير يعتمد عملة متجر البائع، مع بقاء السلوك الحالي للمتجر الافتراضي؛ معالجة التقريب إلى خانتين |
| وسم `currency` في حمولات الـ ledger | Extend | موجود ومثبَّت في اختبارات العقد | مصدره عملة الكيان القانوني للقيد بدل الإعداد العام؛ إضافة عدد الكسور |
| `PriceConverter` في التطبيق | Refactor (بعد الموافقة) | يضرب بسعر صرف عام ويتجاهل `currency` | عرض المبلغ بعملة الحمولة/السياق؛ لا تحويل في العميل |
| مسارا السحب (الكلاسيكي والـ ledger) | Preserve كليهما حتى قرار | كلاهما مستدعى؛ أرصدة سعودية تاريخية | قرار مالك: أيهما المرجع لكل كيان، مع مطابقة الأرصدة |
| النقاط العامة على v1 التي يستهلكها التطبيق | Preserve + New | مشتركة مع تطبيق العميل | نقطة سياق للبائع بعد الدخول (New) تحمل العملة/الكسور/الضريبة/السياسات/حالة المتجر |
| اختبارات العقد الـ 14 في التطبيق | Preserve + Extend | الضمانة الوحيدة لشكل seller-center | حمولات بعملة ثلاثية الكسور وبمتجر غير افتراضي؛ اختبارات مقابلة في الخلفية |
| منفذ المطوّرين (`ApiManifest`/`ApiSnapshotService`/`#[ApiDoc]`) | Preserve + Extend | آلية الخلفية الوحيدة لكشف الانجراف | لقطة مرجعية قبل أي عمل GCC واعتماد تقرير BREAKING كبوابة |
| اختبار عقد للواجهة الكلاسيكية (دخول، `seller-info`، طلبات، منتجات، سحب) | New | لا يوجد في أي جهة | حمولات مرجعية تُلتقط من بيئة اختبار وتُثبَّت في المستودعين |
| المسارات الـ 23 غير المستدعاة | Preserve حتى التحقق | قد تخدم نسخاً أقدم أو مفاتيح مُصدَرة | قياس الاستعمال الفعلي قبل أي إزالة؛ مسارات المزادات العشرة تُراجَع مع حالة الوحدة |
| طلبات multipart التسعة | Refactor (بعد الموافقة) | بلا `Accept` ولا لغة ولا نقطة مركزية | توحيدها عبر `DioClient` قبل إضافة أي ترويسة سياق |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E23-F01 | تطابق كامل اليوم: 241 زوج (فعل، مسار) يطلبها التطبيق، كلها لها مسار في الخلفية بالفعل نفسه بعد `_method`؛ صفر نقطة مفقودة | INFO | VERIFIED | القسم 2.3؛ `lavkoo:routes/rest_api/v3/seller.php:49` | نقطة انطلاق سليمة؛ أي كسر لاحق سيكون من تغييرات GCC لا من انجراف قائم | تثبيت هذا الجدول كخط أساس وإعادة توليده قبل كل مرحلة |
| E23-F02 | عقد المال ضمني وعام في الاتجاهين: العميل يحوّل ويقرّب ويرمز بقيم `/api/v1/config`، والخادم يحوّل المدخلات بسعر `system_default_currency` | **P0** | VERIFIED | `lavkooappseller:lib/helper/price_converter.dart:18-33`، `lavkoo:app/Utils/Convert.php:9-22`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:456-468`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1039` | عند تشغيل متجر بعملة ثانية: مبالغ سحب وأسعار وقسائم وتكاليف شحن تُكتب وتُعرض بعملة خاطئة — خسارة مالية وخطأ في التسويات؛ ويخالف مبدأ «لا تحويل آلي» | بنود 1 و 2 من 4.3؛ منع أي بائع غير سعودي من بناء لا يدعم العملة الصريحة |
| E23-F03 | وسم `currency` في أربع حمولات حديثة مصدره الإعداد العام، والتطبيق يحلّله ولا يستعمله في العرض؛ اختبارات العقد تثبّت `SYP` | P1 | VERIFIED | `lavkoo:app/Services/Marketplace/VendorLedger.php:227-241`، `lavkooappseller:lib/features/statement/screens/statement_screen.dart:105-118`، `lavkooappseller:test/statement_contract_test.dart:20` | الوسم لا يحمي من الخطأ: كيانان بعملتين يحملان الوسم نفسه | مصدر الوسم = عملة الكيان/القيد؛ العميل يعرض بحسبه |
| E23-F04 | تقريب إلى خانتين في طبقة الـ API (`Convert::default`، ملخص وصفوف الكشف) وثوابت `toStringAsFixed(2)` في العميل | P1 | VERIFIED | `lavkoo:app/Utils/Convert.php:62`، `lavkoo:app/Services/Marketplace/SellerLedgerStatementService.php:94-107`، `lavkooappseller:lib/helper/price_converter.dart:187` | فقدان الخانة الثالثة لـ KWD/BHD/OMR في ملخص الكشف وفي الحد الأدنى للطلب ومبلغ الشحن المجاني المعروضين في seller-info | دقة تتبع عملة السجل؛ اختبارات عقد بثلاث خانات |
| E23-F05 | لا طبقة Resource: الواجهة الكلاسيكية تُسلسل نماذج Eloquent خاماً، فالمخطط هو العقد | P1 | VERIFIED | `lavkoo:bootstrap/app.php:90-93`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:207-241`، `lavkoo:app/Models/Seller.php:82-89` | أعمدة النطاق الجديدة تتسرب تلقائياً؛ إعادة تسمية عمود تكسر التطبيق المثبَّت بلا تغيير في المتحكّم ولا اختبار يكشفه | إضافة فقط؛ `$hidden` لما لا يلزم؛ لقطة سطح قبل/بعد |
| E23-F06 | مواضع تحليل صارمة في العميل: محفظة البائع (`.toDouble()` على سبعة حقول) و`token` في جذر استجابة الدخول | P1 | VERIFIED | `lavkooappseller:lib/features/profile/domain/models/profile_info.dart:157-165`، `lavkooappseller:lib/features/auth/domain/services/auth_service.dart:81-82` | تحويل `wallet` إلى قائمة محافظ أو غياب حقل = انهيار عند فتح التطبيق لكل البناءات المثبّتة | بند 4 من 4.3 |
| E23-F07 | عقد الأخطاء غير موحَّد وله دلالة جلسة: 401 بلا `message`/`errors` يمسح الجلسة؛ ثلاثة أشكال مختلفة لـ 401؛ 403 كود عام (104 مواضع) | P1 | VERIFIED | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:90-92`، `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:32-40`، `lavkooappseller:lib/data/datasource/remote/exception/api_error_handler.dart:62-78`، `lavkooappseller:lib/helper/api_checker.dart:21-43` | رفض نطاق بشكل خاطئ يطرد البائعين أو يحبس صاحب رمز منتهٍ في شاشة أخطاء | توثيق القاعدتين كعقد؛ اختبار عقد لأشكال الخطأ |
| E23-F08 | ستة مواضع multipart (سبعة مسارات) تمرّ بـ `seller_can` بلا ترويسة `Accept` فتستلم صفحة HTML عند الرفض، وبلا لغة | P1 | INFERRED (سلوك `expectsJson`؛ الترويسات والفرع VERIFIED) | `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:50-58`، `lavkooappseller:lib/features/shop/domain/repositories/shop_repository.dart:39-40`، `lavkooappseller:lib/features/addProduct/domain/repository/add_product_repository.dart:86-92` | رفض الصلاحية/النطاق في رفع الصور وتحديث المتجر والمحادثة يظهر كخطأ تحليل لا كرسالة | توحيد الطلبات عبر `DioClient` (E19-F08) |
| E23-F09 | لا بُعد متجر/كيان في العقد: لا ترويسة ولا حقل ولا مسار؛ الرمز حامل السياق الوحيد | P1 | VERIFIED | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:56-64`، `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56` | بائع متعدد الدول غير ممثَّل (E19-F01) | اشتقاق خادمي من البائع (4.2) ثم اختيار متجر صريح لمن يلزمه |
| E23-F10 | ثماني نقاط v1 مشتركة يعتمد عليها تطبيق البائع، ثلاث منها (الإعدادات، والسياسات، والضرائب) تحمل ما يجب أن يصير لكل متجر (عملة/كسور، بوابة إصدار، صيانة، سياسات، ضرائب) | P1 | VERIFIED | القسم 2.5 | لا يمكن ربطها ببائع؛ تغييرها يمسّ تطبيق العميل معاً | بند 6 من 4.3 |
| E23-F11 | العقد مثبَّت من جهة واحدة: 14 ملفاً/122 اختباراً في التطبيق بحمولات ملتقطة يدوياً؛ صفر اختبار مقابل في الخلفية لـ 21 من 22 اسماً؛ الواجهة الكلاسيكية بلا اختبار عقد في التطبيق، وفي الخلفية لنقطتين فقط | P1 | VERIFIED | القسم 2.9؛ `lavkooappseller:.github/workflows/ci.yml:41-42`، `lavkoo:tests/Feature/Address/ApiSellerAddressTest.php:61` | تغيير خادمي يكسر العقد يمرّ في CI الخلفية أخضر؛ اختبارات التطبيق تبقى خضراء على حمولات قديمة | اختبارات عقد في الخلفية تولّد الحمولات المرجعية نفسها؛ لقطة `ApiSnapshotService` |
| E23-F12 | مساران متوازيان للسحب يستدعيهما التطبيق: `balance-withdraw*` على `seller_wallets` و`seller-center/payouts` على الـ ledger | P1 | VERIFIED | `lavkooappseller:lib/utill/app_constants.dart:29-33`، `lavkooappseller:lib/utill/app_constants.dart:251`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:455-469`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:60-70` | فصل المحافظ لكل كيان (GCC-13) يجب أن يشمل المخزنَين والمطابقة بينهما | قرار مالك عن المرجع المالي؛ يُنسَّق مع E14 ومدقق المحافظ |
| E23-F13 | قائمة الضرائب عامة وتُصفّى بالحالة النشطة فقط دون تصفية بالبلد أو الكيان القانوني، وتقرير ضريبة البائع بلا عملة ولا كيان قانوني | P1 | VERIFIED | `lavkoo:Modules/TaxModule/app/Http/Controllers/Api/V1/TaxController.php:24-25`، `lavkoo:Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:141-150` | بائع في دولة يرى ويختار ضرائب دولة أخرى؛ تقرير ضريبي غير صالح لكيان قانوني | بند 8 من 4.3 |
| E23-F14 | تعديل عنوان الطلب وإنشاء عميل POS من التطبيق بحقول نصّية حرّة فقط، والخادم يقبل حقولاً منظَّمة لا يرسلها التطبيق | P1 | VERIFIED (الحقول) / INFERRED (السلوك في `soft|strict`) | `lavkooappseller:lib/features/order/domain/repositories/order_repository.dart:53-64`، `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:570-577`، `lavkoo:app/Services/Address/OrderAddressService.php:131-144` | تعديل العنوان من التطبيق قد يُرفض أو يُفقد التنظيم عند تفعيل العناوين الموحّدة؛ ويمسّ أهلية الشحن (GCC-16) | بند 10 من 4.3؛ يُحسم مع مدقق العناوين |
| E23-F15 | بوابة إصدار تطبيق البائع واحدة للمنصّة داخل نقطة مشتركة | P1 | VERIFIED | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:237`، `lavkooappseller:lib/features/splash/domain/models/config_model.dart:331-332` | لا يمكن إلزام بائعي المتاجر الجديدة ببناء يدعم العملة الصريحة دون إلزام بائعي السعودية | بند 9 من 4.3 (E19-F06) |
| E23-F16 | مستهلكون خارجيون للعقد نفسه: مفاتيح API مُصدَرة و webhooks | INFO | VERIFIED | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:66-79`، `lavkoo:routes/rest_api/v3/seller.php:516-529` | تغيير العقد لا يُعالَج ببوابة إصدار التطبيق وحدها | بند 12 من 4.3؛ عدد المفاتيح الفعّالة `UNKNOWN` |
| E23-F17 | فلتر السعر في قائمة المنتجات ينتظر `currency_code` لا يرسله التطبيق | P2 | VERIFIED (الحقول) / INFERRED (الأثر) | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:301-308`، `lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:34-35` | فلتر خاطئ عند `multi_currency`؛ مثال على حقل عملة غير مُلزِم في العقد | إلزام العملة خادمياً من سياق البائع |
| E23-F18 | 23 مساراً في v3 لا يستدعيها التطبيق، منها خمسة مسارات كتابة على الطلبات وعشرة لوحدة مزادات غير موجودة مع `"Auction": true` في ملف الحالات | P2 | VERIFIED | القسم 2.4؛ `lavkoo:modules_statuses.json:6` | مسارات غير مستدعاة ولا تغطيها اختبارات العقد، وتُراجَع بواباتها قبل أي تغيير في العقد | قياس الاستعمال، ثم قرار بالإبقاء أو الإزالة ضمن مراجعة تصنيف البوابات (E14) |
| E23-F19 | عقد v3 للبائع موزَّع على ثلاثة ملفات مسارات في ثلاث وحدات، لكل منها مجموعاته وبواباته الخاصة | P2 | VERIFIED | `lavkoo:Modules/AI/routes/api.php:19-31`، `lavkoo:Modules/TaxModule/routes/api/v3/api.php:17-21` | أي بوابة نطاق تُضاف في `seller.php` لا تغطي مسارات الوحدات تلقائياً | بوابة النطاق في `seller_api_auth` نفسه لا في مجموعات الملف |
| E23-F20 | التطبيق يستدعي خدمة ترميز جغرافي خارجية مباشرة (Nominatim) بنص عنوان العميل، خارج عقد الخلفية | P2 | VERIFIED | `lavkooappseller:lib/features/order/screens/edit_address_screen.dart:131` | بيانات عنوان تخرج إلى طرف ثالث بلا ضبط لكل دولة | مراجعة خصوصية؛ توحيدها عبر الخلفية |

SEC-E23-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E23-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

## 7. أسئلة مفتوحة وعناصر UNKNOWN

| # | السؤال | ما يحسمه |
|---|---|---|
| 1 | قيمة `currency_model` الإنتاجية (`single_currency` أم `multi_currency`) وقيمة `system_default_currency` و`decimal_point_settings` — تحدد هل التحويل في العميل والخادم فعّال اليوم أم هوية | قراءة `business_settings` الإنتاجية |
| 2 | هل توجد بناءات مثبّتة أقدم من `3.1.6+49` ما زالت تستدعي المسارات الخمسة الكلاسيكية للطلبات أو `GET account-delete`؟ | سجلات الوصول الإنتاجية أو لوحة `ApisPanel` |
| 3 | عدد مفاتيح API المُصدَرة الفعّالة والـ webhooks المسجَّلة (مستهلكون خارجيون للعقد) | جداول مفاتيح البائع و webhooks في الإنتاج |
| 4 | هل التُقطت لقطة `api_snapshots` في الإنتاج، ومتى؟ | جدول `api_snapshots` |
| 5 | سلوك Laravel الفعلي مع `_method` داخل جسم JSON ومع طلبات multipart بلا `Accept` | اختبار في بيئة معزولة (غير مصرّح به في هذه المرحلة) |
| 6 | الحمولات المثبَّتة في اختبارات العقد التُقطت من أي بيئة وأي إصدار خلفية؟ (العملة `SYP` تدل على بيئة غير سعودية) | سؤال لصاحب التطبيق؛ غير موثَّق في المستودع |
| 7 | سلوك `orders/address-update` مع عنوان منظَّم حين `unified_address_mode = soft|strict` | مدقق العناوين + قيمة الإعداد الإنتاجية |
| 8 | حالة وحدة Auction في الإنتاج (المجلد غير موجود في المستودع والملف يعلنها مفعَّلة) | فحص خادم الإنتاج |
| 9 | مطابقة حقلية كاملة للنماذج الكلاسيكية (طلب، منتج، استرجاع) مقابل المخطط الحيّ | تصدير مخطط قاعدة البيانات |

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| عنوان الخادم ثابت في البناء | `lavkooappseller:lib/utill/app_constants.dart:11` | VERIFIED |
| ترويسات العميل الافتراضية | `lavkooappseller:lib/data/datasource/remote/dio/dio_client.dart:42-56` | VERIFIED |
| مجموعة v3 الجذرية وبادئتها | `lavkoo:routes/rest_api/v3/seller.php:49` | VERIFIED |
| مجموعة `auth` محدودة المعدّل وخارج `seller_api_auth` | `lavkoo:routes/rest_api/v3/seller.php:51-64` | VERIFIED |
| مجموعة `seller_api_auth` الرئيسة | `lavkoo:routes/rest_api/v3/seller.php:72` | VERIFIED |
| مسارا المنتجات بمعرّف البائع تحت مجموعة مصادقة ثانية | `lavkoo:routes/rest_api/v3/seller.php:692-701` | VERIFIED |
| تحميل ملف v3 تحت `api` | `lavkoo:app/Providers/RouteServiceProvider.php:113-119` | VERIFIED |
| مسارات AI للبائع | `lavkoo:Modules/AI/routes/api.php:19-46` | VERIFIED |
| مسار تقرير ضريبة البائع | `lavkoo:Modules/TaxModule/routes/api/v3/api.php:17-21` | VERIFIED |
| مجموعة `api` وmiddleware تسجيل الشكل | `lavkoo:bootstrap/app.php:84-94` | VERIFIED |
| أسماء الـ middleware المستعارة | `lavkoo:bootstrap/app.php:121-127` | VERIFIED |
| حلّ الرمز إلى principal ووضع `seller` | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:43-64` | VERIFIED |
| 401 بمفتاح `auth-001` في الجذر | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:90-92` | VERIFIED |
| رفض المفاتيح على مسار بلا نطاق | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:66-79` | VERIFIED |
| 403 للصلاحية و HTML لغير JSON | `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:42-58` | VERIFIED |
| 403 `owner_only` | `lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:34-38` | VERIFIED |
| `SellerApiMiddleware` بلا أثر | `lavkoo:app/Http/Middleware/SellerApiMiddleware.php:17-20` | VERIFIED |
| الخلفية تقرأ ترويسة `lang` | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:23-29` | VERIFIED |
| استجابة الدخول `{token}` وأشكال فشله | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php:98-129` | VERIFIED |
| معالجة 401 و 403 في العميل | `lavkooappseller:lib/data/datasource/remote/exception/api_error_handler.dart:49-78` | VERIFIED |
| مسح الجلسة عند `unauthorized` | `lavkooappseller:lib/helper/api_checker.dart:21-43` | VERIFIED |
| `seller-info` نموذج خام + محفظة | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:207-241` | VERIFIED |
| الحقول المحجوبة في `Seller` | `lavkoo:app/Models/Seller.php:82-89` | VERIFIED |
| التحويل بسعر عام في الكتابة والتقريب إلى خانتين | `lavkoo:app/Utils/Convert.php:9-22`، `lavkoo:app/Utils/Convert.php:51-63` | VERIFIED |
| التحويل والعرض في العميل | `lavkooappseller:lib/helper/price_converter.dart:18-33` | VERIFIED |
| `baseCurrency()` من الإعداد العام | `lavkoo:app/Services/Marketplace/VendorLedger.php:227-241` | VERIFIED |
| ملخص الكشف: `currency` وتقريب | `lavkoo:app/Services/Marketplace/SellerLedgerStatementService.php:90-108` | VERIFIED |
| السحب الكلاسيكي على `seller_wallets` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:426-473` | VERIFIED |
| الدفعات الحديثة: تحقق وأشكال رفض | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerPayoutController.php:86-107` | VERIFIED |
| قائمة الطلبات: مفاتيح الطلب وشكل الاستجابة | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:56-127` | VERIFIED |
| تعديل عنوان الطلب: الحقول المقبولة | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/OrderController.php:561-584` | VERIFIED |
| فحص ملكية `{seller_id}` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:143-151` | VERIFIED |
| فلتر السعر ينتظر `currency_code` | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/ProductController.php:301-308` | VERIFIED |
| تقرير الضريبة: شكل الاستجابة | `lavkoo:Modules/TaxModule/app/Http/Controllers/Api/v3/VendorTaxReportController.php:141-150` | VERIFIED |
| قائمة الضرائب العامة | `lavkoo:Modules/TaxModule/app/Http/Controllers/Api/V1/TaxController.php:13-25` | VERIFIED |
| حقول `config` المالية وبوابة الإصدار | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:212-237` | VERIFIED |
| اختبار عقد الكشف يثبّت `SYP` | `lavkooappseller:test/statement_contract_test.dart:15-37` | VERIFIED |
| CI التطبيق يشغّل الاختبارات | `lavkooappseller:.github/workflows/ci.yml:41-42` | VERIFIED |
| الاختبار الخادمي الوحيد على دوال v3 | `lavkoo:tests/Feature/Address/ApiSellerAddressTest.php:61` | VERIFIED |
| `ApiSnapshotService` يصنّف الفروق | `lavkoo:app/Services/DeveloperPortal/ApiSnapshotService.php:10-22` | VERIFIED |
| `"Auction": true` والوحدة غير موجودة | `lavkoo:modules_statuses.json:6` | VERIFIED |
| مسارات v2 للبائع ما زالت مسجَّلة | `lavkoo:routes/rest_api/v2/api.php:26-27` | VERIFIED |
| 25 موضع `_method` في التطبيق | بحث `_method` في `lib/` (أمثلة: `lavkooappseller:lib/features/coupon/domain/repositories/coupon_repository.dart:22`، `lavkooappseller:lib/features/product/domain/repositories/product_repository.dart:131`) | VERIFIED |
| سلوك تمرير الفعل عبر `_method` وطلبات بلا `Accept` وقت التشغيل | — (الإطار غير موجود في المستودع) | INFERRED |

## 9. سجل التحقق المستقل

تحقق مستقل بعد إعداد الملف: فُتح الكود المستشهد به لكل ادعاء من الأقسام 2 إلى 4 وبنود P0 وP1، وأُعيد البحث لكل ادعاء غياب. لم يُشغَّل أي كود ولم يُجرَ أي طلب شبكي.

### أعداد التحقق

| البند | العدد |
|---|---|
| الادعاءات المفحوصة دلالياً (فتح الكود المستشهد به) | 66 |
| مؤكَّدة | 56 |
| مصحَّحة | 4 |
| مرفوضة (نُفي الادعاء) | 1 |
| غير قابلة للتحقق في هذه المرحلة | 5 |
| البنود المنقولة إلى الملحق المقيّد في هذه الجولة | 1 (SEC-E23-03) |

فحص الاستشهادات الآلي: 0 عيب بعد التعديل. فحص الحساسية: 0 نتيجة بعد التعديل.

### التصحيحات

1. **القسم 2.2، البند 3:** عدد مواضع `_method` بقيمة الفعل `put|delete` هو 24 بقيمة حرفية في 14 ملفاً (بحث آلي)، والرقم 25 يشمل موضعاً واحداً بقيمة متغيّرة (`lavkooappseller:lib/features/shop/domain/models/vacation_model.dart:34`).
2. **القسم 2.5 (صف `get-taxVat-list`) وE23-F13:** العبارة «بلا أي تصفية» غير دقيقة. الدالة `getTaxVatList` تصفّي بـ `is_active = 1` وتقسّم صفحات (`lavkoo:Modules/TaxModule/app/Http/Controllers/Api/V1/TaxController.php:13-25`)، ولا تصفّي بالبلد أو الكيان القانوني.
3. **القسم 2.5 (دلالة ذلك) وE23-F10:** عدد النقاط التي تحمل بيانات مرتبطة بقرارات GCC هو ثلاث (الإعدادات، والسياسات، والضرائب) وليس خمساً، لأن الخمس بنود تتوزّع على ثلاث نقاط.
4. **E23-F04:** أثر فقدان الخانة الثالثة مقصور على ملخص الكشف وحدّي الطلب والشحن المجاني في `seller-info`، إذ يستعمل `Convert::default` في `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerController.php:217-218`.

### ادعاء مرفوض

- **القسم 2.9 وE23-F11 (صيغة سابقة):** «لا اختبار يثبّت شكل استجابة نقطة للبائع في الخلفية» غير صحيح. الملف `lavkoo:tests/Feature/Address/ApiSellerAddressTest.php` يستدعي دالتي متحكّم من v3 مباشرةً ويتحقق من أكواد الاستجابة وشكل أخطاء الحقول في تعديل عنوان الطلب. عُدّل النص إلى «نقطتين فقط».

### تعديلات الصياغة والتنقيح (لا تغيّر الوقائع)

- نُقل وصف مسار حذف الحساب بطريقة ثانية إلى الملحق المقيّد (SEC-E23-03)، وأُبقي في الملف بند محايد وسطر مؤشّر.
- أُعيدت صياغة عبارات تصف نقاطاً أو مسارات بعدم وجود رمز أو تحقق لتصف الغرض والبوابة فقط.
- أُعيدت تسمية وصف `_method` بمصطلح تقني محايد هو «تمرير الفعل».
- أُعيدت صياغة صف E23-F18 ليصف الأثر على الاختبار والبوابات دون افتراض فجوة في التحصين.
- أُدرجت مؤشّرات SEC-E23-01 وSEC-E23-02 سطراً مستقلاً بالصيغة المعتمدة بدل صفوف الجدول.
- بقيت المعرّفات الحرفية كما في الكود (قيم الحالة في معالج الأخطاء وقيمة `loginStatus`) لأنها أسماء تقنية، وهي موسومة بالعلامات الخلفية.

### ملاحظات متبقية

- تعارض تاريخي داخل الكود: تعليق `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:68-70` يذكر نحو مئة مسار بمصادقة فقط قبل إضافة قاعدة `seller_can`، بينما الرقم 25 في القسم 2.8 هو تصنيف ثابت حالي لم يُعَد اشتقاقه بالكامل.
- `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:33-39`: عند غياب principal في الطلبات غير JSON يعيد المسار توجيهاً إلى شاشة الدخول. لم يذكر الملف هذا الفرع، وأثره على العميل غير مُعالَج.
- الفرع المحلي للخلفية عند التحقق هو `lavkoo@5c8d03b`، و`lavkoo@7f42777` سلف له، ولا فرق في المسارات المستشهَد بها بينهما. عبارة «ملف توثيق واحد» المنسوبة إلى `046d623d` لم تُعَد.
- الملحق المقيّد: SEC-E23-01 وSEC-E23-02 لم يُعَد التحقق منهما بعمق في هذه الجولة، واكتُفي بمطابقة المسار وبوابته.
- `lavkoo:app/Services/DeveloperPortal/ResponseShapeRecorder.php` يخزن شكل الاستجابة؛ لم تُقرأ تفاصيل `ResponseShape` لمعرفة إن كانت تحتفظ بقيم أمثلة.
- قيم الإنتاج (`currency_model`، `system_default_currency`، `decimal_point_settings`، عدد مفاتيح API، لقطات `api_snapshots`) ما زالت `UNKNOWN` (القسم 7).

### فجوات التغطية

- لم تُعَد اشتقاق المصفوفة الكاملة (241 زوجاً) ولا حلّ 262 موضع استدعاء؛ اكتفي بالتحقق الحسابي من المجاميع المتطابقة (256 = 233 + 23) وبعيّنة من الصفوف.
- لم يُعَد اشتقاق تصنيف البوابات (212 و25 و11 و8) بالكامل.
- لم يُعَد البحث عن لقطات OpenAPI أو Postman خارج `vendor/`.
- لم تُقارن الحقول الكاملة للنماذج الكلاسيكية؛ القسم 2.7 فُحص في تدفقات مختارة فقط.
- سلوك الإطار وقت التشغيل (Laravel مع `_method`، وDio) ما زال `INFERRED`.

### البنود المنقولة

- في هذه الجولة: 1 (SEC-E23-03).
- في الملحق المقيّد: 3 بنود (SEC-E23-01 وSEC-E23-02 موجودان سابقاً، وSEC-E23-03 أُضيف الآن).
