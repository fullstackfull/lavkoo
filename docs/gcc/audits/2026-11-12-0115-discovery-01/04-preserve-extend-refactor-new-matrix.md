# Lavkoo GCC — Discovery-01 — التقرير 04: مصفوفة Preserve / Extend / Refactor / New / Dependent-On-PR والإضافات الدنيا الموصى بها

> **المرحلة:** AUDIT (قراءة فقط) — **الجلسة:** `claude/exciting-shannon-4mh2ok` — **التاريخ:** 2026-11-12 (+03:00)
> **SHA الأساس:** `lavkoo@7f42777` · `lavkooapp@eedbbb8` · `lavkooappseller@8ea0092` · مرجع قديم `ksaapp98@79f50be`
> **يعتمد على:** [E01](../evidence/E01-backend-architecture-map.md) إلى [E23](../evidence/E23-api-contract-seller.md) (القسم 5 من كل ملحق، والأقسام 4 و8 عند الحاجة)، و[E21](../evidence/E21-git-lineage-legacy-prs.md) لحالة النسب وحالة الـ PRs، و`lavkoo:docs/gcc/DECISIONS.md:10-61` للقرارات المعتمدة.
> **الحالة:** نهائي لهذه الجلسة — لا يتضمن أي تنفيذ.

## 1. النطاق والمنهج

- **المدخل.** القسم 5 (مصفوفة Preserve / Extend / Refactor / New) من الملاحق E01 إلى E23، وعدّه الآلي نحو 400 صفاً. يوحّد هذا التقرير التصنيف ويجمع الصفوف حسب النظام الفرعي، ويختصر كل صف إلى ما يؤثر في القرارات. التفصيل الكامل في الملحق المُحال إليه.
- **مصادر إضافية محدودة.** أقسام 4 و8 من الملاحق عند الحاجة لاستخراج الاستشهادات البرمجية، و[E21](../evidence/E21-git-lineage-legacy-prs.md) لحالة النسب والـ PRs، و`lavkoo:docs/gcc/DECISIONS.md` للقرارات المعتمدة.
- **التحقق.** أُعيد فتح أكثر من 80 موضعاً من المواضع المستشهَد بها، للقراءة فقط، للتحقق من محتوى السطر لا من رقمه. لم يُشغَّل أي كود من المستودعات، ولم يُجرَ أي أمر كتابة.
- **المقيّد.** الملاحق المقيّدة لم تُفتح. البنود الأمنية تُذكر بمعرّفات `SEC-…` فقط.
- **تبسيط التصنيفات.** تُعرض التصنيفات المركّبة في الملاحق ("Preserve + Extend") بتصنيفها الأساسي، ويُذكر الامتداد في عمود الإضافة. الفئة Isolate في E10 وE13 تُعامل كـ Refactor (عزل).
- **Dependent-On-PR** يُستعمل فقط حيث تعتمد الوظيفة على عمل قديم حالته غير محسومة. التفصيل في القسم 3.17.

### مفتاح التصنيف

| التصنيف | المعنى |
|---|---|
| Preserve | يبقى كما هو؛ لا تُغيَّر دلالته ولا عقده. أي إضافة لاحقة متوافقة خلفياً. |
| Extend | يبقى ويُوسَّع بإضافات متوافقة: أعمدة قابلة لـ NULL، مفاتيح إضافية، أو بُعد جديد بقيمة افتراضية سعودية. |
| Refactor | يُعاد هيكلته داخلياً، ولا يُقبل إلا بعد اختبارات توصيف للسلوك السعودي الحالي. |
| New | غير موجود في الشجرة اليوم، ويُبنى. |
| Dependent-On-PR | يعتمد على عمل في مستودع أو PR قديم حالته غير محسومة (E21-F03). لا يُحسم قبل حسم النسب. |

## 2. الخلاصة

1. معظم الشجرة الحالية Preserve أو Extend. لا يحتاج GCC إلى إعادة كتابة، بل إلى بنى إضافية محدودة (القسم 4).
2. الإعادة الهيكلية الحقيقية تتركز في: مفاتيح الكاش بلا بُعد سوق، وحلّ الإعدادات عند الإقلاع، والمحافظ المكررة بلا كاتب موحّد، ومحوّلات العملة الثابتة (`Convert::usd`، `PriceConverter`)، والتوجيه المقترن بشكل الـ URL.
3. Dependent-On-PR محصور في قنوات البيع (PR القديمة #12–#16) ونقل محتوى تطبيق العميل من `ksaapp98` (E21 §2.9).
4. أول عائق قبل أي Extend على المال: البنية الفعلية للإنتاج ومدى تطبيق الـ migrations غير معروفة (E21-F06، P0، UNKNOWN). مطابقة أرصدة السعودية شرط قبل أي تغيير على المحافظ (E04 §5.1).
5. لا يُعاد فتح أي قرار معتمد. النقطة الوحيدة التي تستلزم تصويباً توثيقياً هي أن `DECISIONS.md` يصف PR #12–#16 بأنها مفتوحة، بينما يثبت الدليل وجود commits تنفيذها في `lavkoo` (E21 §2.4). المطلوب من `DECISIONS.md` نفسه التحقق من هذا الأمر (`lavkoo:docs/gcc/DECISIONS.md:60-61`)، وهو استيفاء لشرط معتمد لا تعارض معه.

## 3. المصفوفات حسب النظام الفرعي

### 3.1 سياق المتجر والإعدادات والعملة

المصدر: [E02](../evidence/E02-store-context-settings-currency-cache.md) §5؛ [E01](../evidence/E01-backend-architecture-map.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| توقيع `getWebConfig()` وجدول `business_settings` | Preserve | استدعاءات واسعة في `app` (E02 §5)؛ تغيير التوقيع يكسر الباقي | طبقة تخصيص لكل متجر تُستشار أولاً للمفاتيح المصنّفة «لكل متجر» فقط؛ بلا تخصيص يبقى السلوك كما هو | `lavkoo:app/Utils/settings.php:26-30` · VERIFIED |
| كتلة الإعدادات في الكاش (`cache_business_settings_table`) | Extend | كل المفاتيح في كتلة واحدة بلا بُعد متجر (E02 L1) | الإبقاء للقيم العامة، ومدخل منفصل للتخصيصات بمفتاح يتضمن معرّف المتجر | `lavkoo:app/Utils/settings.php:26-30` · VERIFIED |
| `View::share('web_config')` عند الإقلاع | Refactor | يُبنى قبل معرفة المسار والجلسة (E02 L2) | نقل البناء إلى view composer أو middleware بعد حلّ السياق، مع بقاء القيمة الافتراضية | `lavkoo:app/Providers/AppServiceProvider.php:196-225`، `lavkoo:app/Providers/AppServiceProvider.php:312-313` · VERIFIED |
| دوال العملة (`currencyConverter`، `webCurrencyConverter`، `exchangeRate`) وإعداد `currency_model` | Preserve | مرتبطة بدلالة التخزين السعودية؛ تبديل `currency_model` يعيد تفسير كل المبالغ بلا ترحيل (E02 §5) | منسِّق مبالغ يأخذ العملة وعدد الخانات صراحةً؛ لا تُستدعى الدوال القديمة لمبالغ متجر آخر | `lavkoo:app/Utils/currency.php:50-67`، `lavkoo:app/Utils/currency.php:113-131`، `lavkoo:app/Utils/currency.php:225-228` · VERIFIED |
| `currencies` و`decimal_point_settings` و`currency_symbol_position` | Extend | رمز «KSA» ليس ISO، وخانات واحدة للجميع (E02 §5) | عمود ISO وعدد خانات لكل عملة؛ قراءة الخانات من سياق المتجر مع الرجوع للعام | E02 §5 |
| الجلسة المشتركة (عملة، كوبون، عنوان، `guest_id`) | Extend | جلسة واحدة للمتاجر كلها (E02 L3) | فضاء أسماء لكل متجر؛ المفاتيح الحالية تصبح فضاء المتجر السعودي | `lavkoo:config/session.php:143`، `lavkoo:app/Utils/CartManager.php:372-381` · VERIFIED |
| `APILocalizationMiddleware` (طيّ `sa` إلى `ar`) | Preserve | تطبيقات منشورة ترسل `sa` في ترويسة `lang` (E02 §5) | حلّ المتجر من ترويسة اختيارية جديدة بجانبه؛ غيابها = السعودية | `lavkoo:app/Http/Middleware/APILocalizationMiddleware.php:27` · VERIFIED |
| middleware `Localization` (يقرأ مفتاحاً لا يكتبه أحد) | Refactor | مفتاح بلا كاتب (E06 §5) | توحيد مفتاح الجلسة وفصل «اللغة» عن «الدولة» | E06 §5 |
| كاش القوائم والتصنيفات بلا بُعد متجر | Refactor | أسماء المفاتيح ثابتة (E02 L5) | باني مفاتيح مركزي يضيف المتجر؛ البادئة الفارغة للمتجر الافتراضي تُبقي المفاتيح الحالية | `lavkoo:app/Traits/CacheManagerTrait.php:134-151`، `lavkoo:app/Utils/CategoryManager.php:146-151` · VERIFIED |
| التفريغ الشامل للكاش من حدث محلي | Refactor | `cache()->flush()` يفرّغ كاش كل الأسواق (E02 L8) | إبطال موجَّه بالمتجر | `lavkoo:app/Http/Controllers/Admin/Marketplace/ExchangeRateController.php:50` · VERIFIED |
| `app.timezone` | Extend | منطقة واحدة للعملية (E02 §5) | منطقة عرض لكل متجر؛ حسم التباين بين `app.timezone` والإعداد قرار مستقل | E02 §5 |
| قاعدة الرجوع إلى السعودية (GCC-19) | New | لا يوجد سياق متجر في الشجرة (E02 §5) | كائن `StoreContext` يُحلّ مرة لكل طلب أو وظيفة، والافتراضي السعودي عند الغياب | E02 §5؛ `lavkoo:app/Providers/AppServiceProvider.php:196-225` · VERIFIED |

### 3.2 الأساس المعماري والبيانات

المصدر: [E01](../evidence/E01-backend-architecture-map.md) §5؛ [E03](../evidence/E03-data-model-ownership-inventory.md) §5 و§5.1.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| أسماء مسارات `api/v1` و`api/v3/seller` وأسماء مسارات الويب | Preserve | عقد مع تطبيقين منشورين واستدعاءات `route()` (E01 §5) | معامل أو ترويسة متجر اختيارية؛ الغياب = السعودية | `lavkoo:bootstrap/app.php:42-144` · VERIFIED؛ `lavkoo:app/Providers/RouteServiceProvider.php:48-66` · VERIFIED |
| منطق مقترن بشكل الـ URL (`request()->segment(1)` وقوائم البادئات) | Refactor | ينكسر أو يتغير معناه مع بادئة الدولة (E01 §5) | دالة واحدة «قناة الطلب» (web / api / admin / vendor) تُشتق من المسار المطابَق | `lavkoo:app/Traits/DemoMaskingTrait.php:13-20` · VERIFIED |
| `orders.id` وفضاء الترقيم `100001+` و`provider_fulfillments.reference` | Preserve | مراجع ظاهرة للعملاء والمزوّدين والقنوات (E03 §5) | لا تغيير؛ أي ترقيم قانوني للفواتير يُضاف كعمود أو جدول جديد | `lavkoo:app/Utils/OrderManager.php:1604-1656` · VERIFIED |
| لقطات الطلب (`product_details`، `shipping_address_data`، `order_taxes`) | Preserve | تاريخ قانوني (E03 §5) | لقطة الكيان والعملة للطلبات الجديدة فقط؛ تعبئة السعودية بقيمة ثابتة موثّقة | E03 §5؛ `lavkoo:tests/Feature/Fulfillment/OwnershipSnapshotTest.php:121-133` · VERIFIED |
| أعمدة المال الكبيرة (`decimal(18,12)`، `decimal(21,12)`، `decimal(20,6)`) | Preserve | تتسع لثلاث خانات (E03 §5) | لا تغيير في النوع | `lavkoo:database/migrations/2025_07_13_021823_modify_wallet_balance_column_in_users_table.php:14-16` · VERIFIED |
| أعمدة الخانتين وdouble (`myfatoorah_*`، `provider_fulfillments`، `order_taxes`، `orders.admin_commission`) | Refactor | لا تحمل KWD وBHD وOMR بدقة (E03 §5) | توسيع دقة (expand-only) قبل تفعيل أي عملة ثلاثية الخانات، مع اختبارات تقريب | E03 §5 |
| أزواج الملكية `(seller_id, seller_is)` و`(user_id, added_by)` و`shops.author_type` | Preserve | نحو 487 موضعاً لـ `seller_is` و530 لـ `added_by` (E03 §5)؛ تغيير معناها يكسر البائعين وتطبيق البائع | لا إعادة استعمال لها كدولة أو متجر (يطابق DECISIONS) | `lavkoo:app/Services/ProductService.php:587-645` · VERIFIED؛ `lavkoo:app/Utils/Helpers.php:765-767` · VERIFIED |
| `products` (مرتكز الكتالوج) | Extend | المرتكز الأهم للكتالوج (E03 §5) | مرجع متجر واحد على المنتج؛ تفرّد `code` و`barcode` يصير داخل المتجر | `lavkoo:app/Http/Requests/ProductAddRequest.php:49-52` · VERIFIED |
| `admin_wallets` (صف `admin_id=1`) و`seller_wallets` | Refactor | تجميعات مالية بمفتاح لا يحمل الكيان (E03 §5) | بُعد كيان قانوني وعملة، مع إبقاء الصف الحالي صفَّ السعودية | `lavkoo:app/Utils/OrderManager.php:2168` · VERIFIED؛ `lavkoo:app/Models/AdminWallet.php:39-47` · VERIFIED |
| المخطط الأساسي غير المتتبَّع (dump والترحيلات المفقودة) | New (توثيق / أداة) | لا يمكن التحقق من أي ترحيل دونه (E01 §5، E03 §5) | استخراج schema-only من نسخة مطابقة للإنتاج كمرجع مقيّد، وتشغيل أمر التوافق القائم قراءةً فقط | E21 §6 F06 · UNKNOWN |
| المرتكزات المقترحة `Country` و`Storefront` و`Legal Entity` و`Provider Account` و`Gateway Account` | New (PROPOSED) | لا مقابل لها في الجداول الحالية (E03 §5.1) | مرتكزات صغيرة؛ الأعمدة المباشرة الجديدة تنحصر في نحو 22 مرتكزاً وجداول إعداد صغيرة (E03 §5.1) | E03 §5.1 |
| Models ميتة وجداول الإرث السوري | Preserve (لا لمس) | خارج نطاق GCC (E03 §5) | تُستثنى من أي backfill | E03 §5 |

### 3.3 الكتالوج والاكتشاف والتسعير

المصدر: [E08](../evidence/E08-catalog-discovery-pricing.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| معرّفات المنتجات والتصنيفات والعلامات وروابط `/product/{slug}` | Preserve | مفاتيح للطلبات والمراجعات والقنوات والـ SEO (E08 §5) | لا تغيير؛ الصفوف القائمة تُنسب لمتجر السعودية | `lavkoo:config/deeplinks.php:39-45` · VERIFIED |
| صيغة JSON للمتغيرات وعقد استجابة الـ API (`set_data_format`) | Preserve | تطبيقات منشورة تحلّل هذا الشكل (E08 §5) | حقول إضافية اختيارية فقط | `lavkoo:app/Utils/Helpers.php:108-168` · VERIFIED |
| `Product::scopeActive` | Extend | نقطة الترشيح الأوسع: 85 موضعاً مباشراً و20 ملحقاً (E08 §5) | إضافة شرط المتجر مع جرد المسارات الخارجة عنه (142 بداية استعلام ثابتاً و67 استعلاماً خاماً على الأقل) | `lavkoo:app/Models/Product.php:208-249` · VERIFIED |
| فهرس البحث والتطبيع العربي | Extend | يعمل ويغطي العربية (E08 §5) | ترشيح المتجر قبل تطبيق السقف | E08 §5 |
| `brands` و`attributes` و`colors` و`tags` وشجرة `categories` | Preserve | مركزية أصلاً وتوافق GCC-08 وGCC-18 | جدول ظهور وامتداد محلي منفصل بدل تكرار الشجرة | `lavkoo:app/Utils/BrandManager.php:25-38` · VERIFIED؛ `lavkoo:app/Utils/CategoryManager.php:146-151` · VERIFIED |
| `category_governance` | Extend | يحمل سياسات تختلف نظامياً بين الدول (E08 §5) | بُعد متجر أو دولة للصف، مع وراثة الافتراضي | `lavkoo:app/Models/CategoryGovernance.php:14` · VERIFIED |
| مساعدات العملة (`webCurrencyConverter` و`usdToDefaultCurrency` و`Convert`) | Refactor | تفترض عملة واحدة وأساس USD وخانة واحدة (E08 §5) | سياق عملة المتجر يُمرَّر صراحة؛ قرار موثّق لوحدة التخزين | `lavkoo:app/Utils/currency.php:113-131` · VERIFIED |
| تفرّد `code` و`barcode` و`coupons.code` | Refactor | قواعد عالمية في 8 + 2 + 6 مواضع (E08 §5) | تفرّد مركّب (متجر، قيمة) بعد معرفة القيد الفعلي في القاعدة | `lavkoo:app/Http/Requests/ProductAddRequest.php:49-52` · VERIFIED |
| القسائم (`coupons` و`getTotalCouponAmount`) | Extend | منطق التحقق مركزي في دالة واحدة؛ POS وتعديل الطلب تطبيقات منفصلة (E08 §5) | متجر وعملة للقسيمة، وعدّ الحدود داخل المتجر، وتوحيد القاعدة عبر المسارات | E08 §5 |
| Flash / Featured / Deal of the day | Refactor | افتراض «واحد للنظام» في الكتابة والقراءة والكاش (E08 §5) | الفعّال الواحد لكل متجر | E08 §5 |
| `experience_campaigns` | Extend | مرشّح طبيعي لحملة مركزية تختار أسواقاً (GCC-17) | قائمة أسواق للحملة ومفتاح كاش يتضمن السوق | `lavkoo:app/Services/Commerce/CampaignResolver.php:72-78` · VERIFIED |
| SEO (`product_seos`، `seo_meta_info`، القوالب، redirects، sitemap) | Extend | سلسلة حل قائمة بمفاتيح لغوية، ومحرّك redirects قائم (E06 §5) | بُعد متجر للكيانات المركزية؛ روابط ببادئة المتجر؛ hreflang لغة-إقليم؛ استعمال محرّك الـ redirects لخطة 301 | E06 §5 |
| سجل الأسعار وسياسات التسعير | Preserve | سجل تاريخي لا يُعاد كتابته (E08 §5) | عمود عملة للصفوف الجديدة | E08 §5 |
| Product Gallery (نسخ الملفات بين الدول) | Extend | أقرب مسار قائم للنسخ بين الدول (E08 §5) | نسخ فعلي للملفات، وتصفير `bought_together_ids` والضريبة | E08 §5 |
| اختبارات انحدار للكتالوج | New | لا اختبار للكتالوج في المستودع وفق E08 §5 | اختبارات توصيفية للسلوك السعودي الحالي قبل أي تغيير | E08 §5 |

### 3.4 السلة وCheckout والطلبات

المصدر: [E09](../evidence/E09-cart-checkout-order-pipeline.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| معاملة `generateOrder` (أقفال، خصم مشروط، إعادة محاولة، `stock_policy`) | Preserve | أقوى جزء في المسار ومصدر تصحيحات إنتاجية موثّقة (E09 §5) | لا تغيير في الترتيب؛ حقول اللقطة داخل المعاملة نفسها | `lavkoo:app/Utils/OrderManager.php:1604-1656` · VERIFIED |
| التقسيم بحسب مجموعة البائع في السلة (`getVendorWiseCartList`) | Extend | الفصل الحالي بحسب البائع؛ الحارس الجديد يقيّد الدفع بكيان واحد (E09 §5) | حارس «كيان قانوني واحد لكل دفع» وخطّاف أهلية منتج × وجهة (GCC-16) في `checkValidationForCheckoutPages` | `lavkoo:app/Utils/OrderManager.php:887-902` · VERIFIED |
| `carts` و`cart_shippings` و`get_cart_group_ids` | Extend | نقطة تجميع واحدة يمكن تقييدها (E09 §5) | بُعد متجر على الصف، وتقييده في `get_cart_group_ids` والاستعلامات المباشرة (23 ملفاً) والمفاتيح المخزَّنة | `lavkoo:app/Utils/CartManager.php:163-173` · VERIFIED؛ `lavkoo:app/Utils/CartManager.php:23-50` · VERIFIED |
| حارس `is_stock_decreased` لاسترجاع المخزون | Preserve | idempotent ومكرَّر بتطابق في موضعين (E09 §5) | لا شيء | E09 §5 |
| نقاط تسليم التنفيذ (`OrderItemOwnershipSnapshotter`، `FulfillmentHooks`، `FulfillmentOrderGuard`، `CheckoutCodGuard`) | Preserve | عقد قائم مع ملحق التنفيذ (E09 §5) | لا شيء | `lavkoo:app/Services/Fulfillment/OrderEligibility.php:30-57` · VERIFIED |
| `orders` و`order_details` | Extend | لا عملة ولا كيان ولا متجر في الصف (E09 §5) | أعمدة لقطة قابلة لـ NULL (متجر، كيان قانوني، رمز عملة، عدد الخانات، هوية البائع القانونية) تُملأ للطلبات الجديدة | E09 §5؛ `lavkoo:app/Models/Order.php:75-133` · VERIFIED |
| ترقيم الطلبات (ثلاث خوارزميات على تسلسل واحد) | Extend | E09 §5 | مولّد واحد مقفل، ونطاقات أو بادئات عرض لكل متجر دون تغيير `orders.id` السعودي | E09 §5 |
| توليد الفاتورة من إعدادات حية | Refactor | مستند غير ثابت يُبنى من إعدادات حية (E09 §5) | لقطة فاتورة ثابتة (كيان، رقم ضريبي، عملة، مجاميع)، وتسلسل لكل كيان مع إبقاء الأرقام السعودية | E09 §5 |
| ترحيل المحافظ في صنفين (`admin_wallets` / `seller_wallets`) | Refactor | مكرَّر، ومفتاحه `admin_id = 1` (E09 §5) | خدمة واحدة معاملاتية قبل إضافة أي بُعد كيان | `lavkoo:app/Utils/OrderManager.php:2168` · VERIFIED |
| مفتاح idempotency للدفع | New | غير موجود (E09 §5) | رمز دفع فريد على الطلب يُولَّد قبل الإرسال إلى البوابة | E09 §5؛ `lavkoo:app/Traits/Payment.php:22-37` · VERIFIED |
| سياق المتجر والكيان في الدفع | New | يعتمد على مخرجات ملحق سياق المتجر (E09 §5) | — | E09 §5 |
| خطّاف `DeliverySyria` وكتابات التصحيح في المسار الحرج | Refactor | بقايا سوق آخر داخل المسار الحرج (E09 §5) | عزل خلف واجهة مزوّد، وإزالة كتابة ملف التصحيح بعد موافقة المالك | E09 §5 |

### 3.5 بوابات الدفع

المصدر: [E10](../evidence/E10-payments-gateways.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| خدمات MyFatoorah (15 خدمة و3 جداول واختبارات) | Preserve | أصلب مسار مالي؛ فهارس فريدة واختبارات تزامن (E10 §5) | بُعد «حساب» على المحاولة والاسترداد وحدث الـ webhook؛ الحساب السعودي الحالي هو الافتراضي | `lavkoo:app/Services/MyFatoorah/PaymentVerificationService.php:70` · VERIFIED؛ `lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:96-102` · VERIFIED |
| `config/myfatoorah.php` (host / currency / allow-list) | Extend | قيم نشر عامة سعودية (E10 §5) | نقلها إلى مستوى الحساب، مع بقاء القيم الحالية افتراضياً | `lavkoo:config/myfatoorah.php:60-62` · VERIFIED |
| `Money` (MyFatoorah) | Extend | صحيح لخانتين فقط (E10 §5) | دالة وحدات صغرى تأخذ أُسّ العملة | E10 §5 |
| `PaymobController` | Preserve (سلوكاً) ثم Extend | مطلوب حفظه، وبلا اختبارات (E10 §5؛ E21 §2.6) | اختبارات توصيف أولاً؛ معالجة SEC-E10-01 قبل أي توسعة؛ ثم بُعد الحساب وأُسّ العملة | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:14-56` · VERIFIED |
| `addon_settings` (صف لكل بوابة) | Preserve | مفتاح عام تستعمله كل المتحكمات ولوحة الإدارة (E10 §5) | كيان «حساب بوابة» مرتبط بالكيان القانوني، مع رجوع إلى الصف الحالي كحساب سعودي؛ عدم ترميز الدولة في `key_name` | `lavkoo:app/Services/MyFatoorah/GatewayConfigRepository.php:96-102` · VERIFIED |
| عقد `payment_requests` وhooks (`generate_link`، `payment_response`) | Preserve | تعتمد عليه كل البوابات والتطبيق (E10 §5) | أعمدة نسبة اختيارية (storefront، legal entity، gateway account) تُملأ عند الإنشاء؛ لا تغيير في أسماء الحقول | `lavkoo:tests/Support/BuildsMyFatoorahSchema.php:42-61` · VERIFIED |
| `payment_gateways()` و`/api/v1/config.payment_methods` | Extend | قائمة واحدة عامة (E10 §5) | تصفية حسب سياق المتجر مع بقاء الشكل | `lavkoo:app/Traits/PaymentGatewayTrait.php:476-499` · VERIFIED |
| `PaymentRoutingService` و`PaymentRoutingRule` | Extend | عقد غير كاسر سليم، والجدول غير موجود في المستودع (E10 §5) | أبعاد storefront وcurrency ورمز ISO؛ إنفاذها عند بدء الدفع | E10 §5 |
| `RefundService` (MyFatoorah) | Preserve | idempotent ومقيَّد بسقف المدفوع (E10 §5) | ربطه بـ `refund_requests` و`order_id` وقيود البائع | E10 §5 |
| خرائط العملات في `PaymentGatewayTrait` | Refactor | خرائط ثابتة مع رجوع إلى USD أو أول عملة (E10 §5) | استبدال الاختيار الآلي بعملة المتجر الصريحة؛ الخرائط للتحقق فقط | `lavkoo:app/Traits/PaymentGatewayTrait.php:476-499` · VERIFIED |
| COD guard و`FULFILLMENT_ALLOW_MIXED_COD` و`CodAllocator` | Preserve | قاعدة أمان مالية (E10 §5) | أُسّ العملة في التقسيم (انظر 3.6) | E10 §5 |
| البوابات الموروثة غير الخليجية و23 stub | Refactor (عزل) | ضوضاء وسطح صيانة إضافي؛ ضوابطها غير متماثلة (SEC-E10-03) | عدم إدراجها في أي حساب GCC؛ قرار المالك بتعطيلها | E10 §5 |
| Tabby / Tamara | New | لا كود ولا تاريخ في أي مستودع (E21 §2.6) | وفق قالب MyFatoorah بعد تأكيد القدرات لكل بلد وطريقة دفع | `lavkoo:docs/gcc/DECISIONS.md:15` · VERIFIED؛ E21 §2.6 |

### 3.6 المخزون والتنفيذ والشحن

المصدر: [E11](../evidence/E11-inventory-fulfillment-shipping.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| `FulfillmentRouter` و`ProviderOrderDispatcher` (claim / send / settle) وآلة الحالات والمصالحة | Preserve | قلب ضمان «مرة واحدة بالضبط»، ومختبَر (E11 §5) | لا تغيير في المنطق؛ تمرير سياق الحساب أو المتجر كقيمة مقروءة فقط | `lavkoo:app/Services/Fulfillment/ProviderOrderDispatcher.php:197-234` · VERIFIED |
| `InventorySyncService` وكاتب مخزون المنتج المملوك لمزوّد (`ProviderStockOwnership`) | Preserve | حساب واحد يمنع البيع المزدوج (E11 §5) | توثيق كتّاب `current_stock` خارج المسار الرئيسي قبل أي توسعة (SEC-E11-08) | `lavkoo:app/Services/Fulfillment/InventorySyncService.php:101-128` · VERIFIED؛ `lavkoo:app/Services/Marketplace/InventoryService.php:50-53` · VERIFIED |
| المجمّعات والحجوزات والتقسيم والـ outbox | Preserve | مطلوبة بالنص في القرارات ومختبرة تحت التزامن (E11 §5) | نطاق السوق يأتي من المنتج أو الحساب، لا من أعمدة جديدة على المجمّع | `lavkoo:app/Services/SalesChannels/Inventory/Allocation/StockAllocator.php:24-58` · VERIFIED |
| `ProviderHttp` و`ExternalOperations` ومفتاحا البيئة و`dry_run` و`auto_dispatch` | Preserve | شبكة الأمان الرئيسية للنداءات الخارجية (E11 §5) | لا تُستعمل لإيقاف متجر (GCC-12) | `lavkoo:config/fulfillment.php:26-34` · VERIFIED |
| `CodPolicy` و`CodAllocator` و`CheckoutCodGuard` و`FulfillmentMoney` | Refactor (محدود) | قواعد المال سليمة لكنها مثبتة على عملة وخانتين (E11 §5) | دقّة ووحدة صغرى مشتقّتان من عملة الطلب؛ السلوك السعودي يبقى | E11 §5 |
| `CurrencyCode` و`ProviderCurrency` و`FULFILLMENT_CURRENCY` | Refactor | عملة عامة واحدة (E11 §5) | عملة حساب التنفيذ بدل الإعداد العام، مع بقاء السلوك السعودي افتراضياً | E11 §5 |
| `integration_settings` (صف لكل مزوّد) و`ProviderConnectionManager` | Extend | يحتاج بُعد الحساب (E11 §5) | كيان Fulfillment Account يشير إليه؛ الصف الحالي يصير الحساب السعودي | `lavkoo:app/Services/Fulfillment/ProviderConnectionManager.php:30-35` · VERIFIED |
| `products.fulfillment_provider` و`ProductProviderAssignmentService` | Preserve | مصدر واحد لكل منتج قائم، وهو ما تطلبه القرارات (E11 §5) | يكفي إن كان المنتج لكل متجر؛ وإلا جدول إسناد لكل (منتج، متجر) | `lavkoo:app/Services/SalesChannels/Dispatch/DispatchGate.php:124-126` · VERIFIED |
| `SaudiPhone` وبادئة `00966` وافتراضات `SA` | Refactor | سعودية بالبناء (E11 §5) | تطبيع الهاتف بحسب دولة العنوان؛ السلوك السعودي للعناوين السعودية | E11 §5 |
| `provider_city_mappings` وخدمة المطابقة | Extend | محايدة تجاه المزوّد؛ `country_code` موجود غير مفعَّل (E11 §5) | تفعيل `country_code` وتصفية المرشحين بدولة الحساب؛ يخضع لوضع العناوين الموحّد | E11 §5؛ `lavkoo:docs/address/README.md:19-24` · VERIFIED |
| الشحن: `ShippingMethod` وقيد الدول و`ShippingZone` | Extend | إعدادات عامة (E11 §5) | نطاق متجر لطرق الشحن والقيود، وفحص أهلية جديد | E11 §5 |
| فحص أهلية المنتج للشحن العابر للحدود | New | الموجود `RegulatedProductGate` لنشر القنوات، ووضعه الافتراضي `off`، ولا يستدعيه مسار الطلب (E11 §5) | خدمة أهلية تُستدعى من `CheckoutCodGuard` و`OrderEligibility`؛ قواعد المالك و«الرفض عند الجهل» (GCC-16) | `lavkoo:app/Services/SalesChannels/Listings/RegulatedProductGate.php:26-55` · VERIFIED؛ E11 §5 |
| أدوات الجاهزية (`FulfillmentPreflight`، `fulfillment:compatibility`، قائمة go-live) | Preserve | بوابات نشر آمن (E11 §5) | تُعاد استعمالها بوابات Go/No-Go لكل دولة | E11 §5 |
| Fulfillment Account (لكل دولة أو كيان) | New | غير موجود (E11 §5) | مرتكز حساب تنفيذ مرتبط بالكيان القانوني (PROPOSED) | E11 §5 |
| Delivery Syria | قرار مالك | إرث سوري داخل checkout (E11 §5) | لا يُمدَّد؛ يُوثَّق ويُحسم مصيره | E11 §5 |

### 3.7 قنوات البيع

المصدر: [E12](../evidence/E12-sales-channels-marketplaces.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| `ChannelHttp` و`ChannelOperations` وترتيب البوابات والسجل المنقّى | Preserve | نقطة الخروج الوحيدة، تفشل مغلقة، ومحمية باختبارات (E12 §5) | لا شيء؛ يُمنع أي نداء خارجها | `lavkoo:tests/Feature/SalesChannels/EverythingStartsOffTest.php:35-47` · VERIFIED |
| `ChannelAccountService` (`identityOf`) وسلّم القدرات والموافقات | Extend | نمط «هوية الحساب» جاهز لبُعدين جديدين (E12 §5) | إدخال الكيان القانوني والواجهة في `identityOf` | `lavkoo:app/Services/SalesChannels/ChannelAccountService.php:157-163` · VERIFIED |
| جدول `sales_channel_accounts` | Extend | لا ربط بكيان أو واجهة (E12 §5) | أعمدة اختيارية للكيان القانوني والواجهة؛ ترحيل يربط القائم بالسعودية؛ مراجعة القيد الفريد ليشمل السوق | `lavkoo:database/migrations/2026_11_07_100100_create_sales_channel_tables.php:19-57` · VERIFIED |
| الموصّلات الثلاثة ومحوِّلاتها (الأساس في PR #5) | Preserve | كل خصوصية القناة معزولة فيها (E12 §5) | Amazon: بُعد السوق في مفتاح الإدراج؛ Trendyol: قائمة الواجهات كبيانات؛ خانات السعر بحسب العملة | `lavkoo:app/Services/SalesChannels/Amazon/AmazonConnector.php:50` · VERIFIED |
| Amazon: استكمال PR-C وفرع `amazon-feeds` | Dependent-On-PR | الكود في main عبر `lavkoo@cbf2cdd`؛ حالة PR #12 الحيّة غير معلومة (E21-F03) | انظر 3.17 | `lavkoo@7efe022` · VERIFIED؛ `lavkoo@36cd50a` · VERIFIED |
| Trendyol (PR-D #13) وNoon (PR-E #14) | Dependent-On-PR | الكود في main عبر #17؛ Noon لا يوجد إلا عبر #17 (E21 §2.6) | انظر 3.17 | `lavkoo@32249dd` · VERIFIED؛ `lavkoo@0366ba7` · VERIFIED |
| `ListingSyncService` و`ProductMatcher` | Extend | بحث عالمي في كتالوج واحد (E12 §5) | حصر المرشحين بكتالوج واجهة الحساب | E12 §5 |
| `RegulatedProductGate` (نشر القنوات) | Extend | قواعد لكل قناة فقط ومن البيئة (E12 §5) | قواعد لكل (سوق، قناة) مع بقاء الفشل المغلق | `lavkoo:app/Services/SalesChannels/Listings/RegulatedProductGate.php:26-55` · VERIFIED |
| `PoolNet` و`PoolAllocationService` و`StockAllocator` و`ReservationService` و`OrderFreshness` | Preserve | صيغة واحدة وكاتب واحد، ومختبرة بالتزامن (E12 §5) | حارس عند العضوية والربط: سوق الحساب = سوق الموقع = واجهة المنتج | `lavkoo:app/Services/SalesChannels/Inventory/Allocation/PoolMembers.php:24-39` · VERIFIED |
| outbox المخزون (`StockEventOutbox` / `Drain` / `Nudge`) | Preserve | الضمانة المعاملاتية وإعادة الحساب يجب أن تبقى (E12 §5) | حسم على مستوى المجمّع أو الحساب بدل الدفعة؛ تقسيم المعالجة بحسب السوق | `lavkoo:app/Services/SalesChannels/Inventory/Outbox/StockEventOutbox.php:36-67` · VERIFIED |
| `DispatchGate` و`ChannelOrderMaterializer` و`ChannelRecipient` | Extend | قيم الطلب المستورد ثابتة لعميل وبائع داخليين (E12 §5) | وجهة وعملة وهاتف بحسب سوق الحساب؛ يعتمد على تصميم الطلبات وFulfillment | E12 §5؛ `lavkoo:app/Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php:139-143` · VERIFIED |
| `BuyerDataPurger` و`BuyerEchoScrubber` | Extend | احتفاظ أدنى ومُدقَّق (E12 §5) | صيغ الهاتف لكل دولة؛ مدد احتفاظ لكل سوق إن اختلف | E12 §5 |
| شاشات الإدارة ومساراتها | Extend | صلاحية وحدة فقط (E12 §5) | نطاقات بيانات وأفعال لكل سوق وكيان (GCC-14) | E12 §5 |
| `App\Services\Marketplace` | Preserve (خارج النطاق) | مجال مختلف عن قنوات البيع (E12 §5) | عدم الخلط بينه وبين قنوات البيع في مخطط GCC | E12 §5 |
| `docs/sales-channels/*` | Refactor (توثيق) | الخطة ما زالت تصف PR #12–#16 بأنها «مفتوح» (E21 §5) | ملاحظة «الحالة بعد 2026-11-11» بدل تعديل النص التاريخي | `lavkoo:docs/sales-channels/merge-plan.md:127-134` · VERIFIED |

### 3.8 المحاسبة وOdoo والتكاليف والتسويات

المصدر: [E13](../evidence/E13-finance-odoo-costs-settlements.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| `CostLedger` و`operational_cost_entries` | Extend | كاتب الإنشاء والحالة المالية الوحيد، مانع للتكرار، ولا حذف (E13 §5) | عمود كيان قانوني اختياري يُشتق من الحساب أو الموقع أو الطلب عند الكتابة | E13 §5 |
| فحص الصفوف القديمة لعمولة القناة (`costs:check-legacy-commission-slots`) | Preserve (قراءة فقط) | صافي عمولة بالطريقة القديمة قد يُحتسب مرتين؛ وجود الصفوف الفعلية UNKNOWN (E21-F14) | بوابة إلزامية في خطة الترحيل، تُنفَّذ على نسخة معزولة فقط | `lavkoo:app/Console/Commands/CostsCheckLegacyCommissionSlotsCommand.php:46-50` · VERIFIED |
| `InventoryCostLayerService` (FIFO) | Extend | سليم ومقفول ومعزول بالموقع (E13 §5) | عملة أساس لكل كيان بدل الإعداد العام؛ الخوارزمية لا تتغير | E13 §5 |
| `ProfitabilityService` و`ProfitAggregator` | Extend | يعلن الفجوات ولا يخمّن (E13 §5) | بُعد تجميع كيان/متجر، ومنع جمع عملات أساس مختلفة | `lavkoo:tests/Feature/OperationalCosts/ProfitWithoutExchangeRateTest.php:77` · VERIFIED |
| Odoo: الطابور والسياج والباني (الكود) | Preserve | أقوى ضمانات عدم التكرار في المشروع (E13 §5) | اتصال لكل كيان قانوني؛ منازل العملة من النسخة؛ مميّز كيان في المرجع للقيود الجديدة فقط | `lavkoo:app/Services/Accounting/Odoo/OdooOutbox.php:49-72` · VERIFIED؛ `lavkoo:config/odoo.php:28-42` · VERIFIED |
| Odoo: الكود المدموج وحالة PR #15 | Dependent-On-PR | الكود في main عبر `lavkoo@cbf2cdd` فقط؛ حالة #15 الحيّة UNKNOWN؛ ترحيلات الوحدة لم تُشغَّل على الإنتاج وفق رسالة الـ commit (INFERRED) | انظر 3.17 | `lavkoo@a907bf0` · VERIFIED؛ `lavkoo@95e0ae3` · VERIFIED |
| `OdooMoney` | Refactor (صغير) | منزلتان ثابتتان (E13 §5) | تمرير منازل العملة فعلياً من المنادي | E13 §5 |
| `VendorLedger` و`SettlementEngine` و`PayoutService` | Preserve | إلحاقي ومقفول ومانع للتكرار (E13 §5) | سلسلة رصيد وتسوية لكل (بائع، كيان، عملة) | `lavkoo:app/Services/Marketplace/VendorLedger.php:104-128` · VERIFIED؛ `lavkoo:app/Services/Marketplace/SettlementEngine.php:66-72` · VERIFIED |
| `CommissionEngine` و`order_item_commissions` | Extend | حتمي ومجمَّد (E13 §5) | نطاق متجر وعملة للقاعدة؛ عملة للمبالغ الثابتة | `lavkoo:app/Models/OrderItemCommission.php:15-21` · VERIFIED |
| `admin_wallets` و`seller_wallets` و`order_transactions` | Preserve | تاريخ سعودي؛ عدّادات بلا حركات (E13 §5) | لا إعادة استعمال للدول الجديدة؛ بنية جديدة مقيّدة بالكيان مع تجميد لقطة افتتاحية | `lavkoo:app/Models/AdminWallet.php:39-47` · VERIFIED؛ `lavkoo:app/Models/OrderTransaction.php:41-59` · VERIFIED |
| مسار السحب الموروث `withdraw_requests` | Refactor (عزل) | يعمل اليوم ومجسَّر إلى الدفتر (E13 §5) | حصره في الكيان السعودي وعدم تفعيله للكيانات الجديدة | E13 §5 |
| `Modules/TaxModule` | Extend | عمود `country_code` موجود لكنه خامد (E13 §5) | محلِّل ضريبي بحسب الكيان أو المتجر، ومفتاح كاش مقيّد، وعلم الشمول لكل كيان | `lavkoo:Modules/TaxModule/app/Services/CalculateTaxService.php:276` · VERIFIED |
| لقطة الكيان القانوني على الفاتورة | New | لا لقطة كيان (E13 §5) | لقطة كيان قانوني عند إصدار الفاتورة | E13 §5 |
| تقارير ولوحات وتصديرات موروثة | Refactor | مجاميع عامة بعملة واحدة (E13 §5) | فلتر كيان أو متجر إلزامي، ومنع الجمع عبر العملات | `lavkoo:app/Http/Controllers/Admin/ReportController.php:138-149` · VERIFIED؛ `lavkoo:app/Http/Controllers/Admin/DashboardController.php:297-318` · VERIFIED |
| `Helpers::seller_sales_commission` | Refactor (حذِر) | نص بمنزلتين (E13 §5) | توحيد المصدر مع `CommissionEngine` بعد اختبار رجعي سعودي | E13 §5 |

### 3.9 الثيم (backend)

المصدر: [E07](../evidence/E07-theme-system-backend.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| دورة النسخ (draft / publish / archive / restore) في `ThemeManager` | Preserve | ذرّية وغير هدّامة ومقيّدة بـ`theme_id` (E07 §5) | تمرير سياق المتجر إلى سجل التدقيق فقط | `lavkoo:app/Services/Theme/ThemeManager.php:23-56` · VERIFIED |
| `ThemeManager::activate` (إطفاء الحصرية العالمية) | Extend | الموضع الوحيد الذي يفرض الحصرية العالمية (E07 §5) | شرط المتجر في تحديث الإطفاء | `lavkoo:app/Services/Theme/ThemeManager.php:83-86` · VERIFIED |
| `ThemeDelivery` (العقد والتفاوض وETag) | Extend | العقد إضافي ومتوافق، وينقصه بُعد المتجر (E07 §5) | متجر في `publishedVersion()` وفي `fingerprint()` وفي مفتاح `revision` | `lavkoo:app/Services/Theme/ThemeDelivery.php:715-726` · VERIFIED |
| `StorefrontThemeRenderer` | Extend | مفاتيحه بالصفحة فقط (E07 §5) | متجر في المفتاحين وفي `publishedVersion()` | `lavkoo:app/Services/Theme/StorefrontThemeRenderer.php:88-92` · VERIFIED |
| `ThemeBannerLink` | Refactor (محدود) | الدفع الفوري من draft إلى صف حيّ يناقض عزل النشر (E07 §5) | حصر الدفع بصفوف المتجر نفسه؛ نسخ الصف عند نسخ الثيم | `lavkoo:app/Services/Theme/ThemeBannerLink.php:140-176` · VERIFIED |
| `ThemePortabilityService` (تصدير واستيراد) | Extend | أساس «نسخ الثيم»، وينتج مسودة غير نشطة كما يطلب GCC-07 (E07 §5) | إجراء «نسخ إلى متجر» يعيد سكّ الـ uuid، وينسخ الأصول، ويصدر تقرير مراجع مفقودة | `lavkoo:app/Services/Theme/ThemePortabilityService.php:148-176` · VERIFIED |
| `ThemeSyncBeacon` (topic واحد) | Extend | topic واحد للتطبيقات (E07 §5) | topic لكل متجر مع إبقاء القديم | `lavkoo:app/Services/Theme/ThemeSyncBeacon.php:27` · VERIFIED |
| `ViewerContext` و`SectionVisibility` و`Channel` | Extend | بُعد المنصة والقناة ناضج (E07 §5) | سمة `storefront`؛ لا قاعدة إظهار جديدة إن كان الثيم لكل متجر | `lavkoo:app/Services/Theme/SectionVisibility.php:123-131` · VERIFIED؛ `lavkoo:app/Services/Theme/Channel.php:29-34` · VERIFIED |
| `ComponentCapabilityRegistry` وبوابة القدرات | Preserve | مستقلة عن الدولة (E07 §5) | لا شيء | `lavkoo:app/Services/Theme/ComponentCapabilityRegistry.php:156-158` · VERIFIED |
| `experience_campaigns` و`experience_experiments` والمحلِّلان | Extend | عالمية اليوم (E07 §5) | نطاق متجر أو أسواق، ومفاتيح كاش | `lavkoo:app/Services/Commerce/CampaignResolver.php:72-78` · VERIFIED |
| `banners.theme` و`/api/v1/theme/sections` | Preserve | مفتاح كاش واستعلامات قائمة؛ العقد القديم منشور ومجمّد (E07 §5) | لا يُعاد توظيف العمود كمعرّف دولة؛ لا تطوير على المسار المجمّد | E07 §5 |
| `SectionDataResolver` | Refactor | تابع لقرار نطاق الكتالوج (E07 §5، E08 §5) | لا تغيير محلي قبل حسم نطاق الكتالوج | E07 §5 |
| `AppCarouselSetting` و`newcontroller` | Refactor (بعد التحقق) | صف عالمي ومتحكم غير مربوط (E07 §5) | قرار المالك بعد معرفة الاستخدام الفعلي | E07 §5 |
| مخطط جداول الثيم واختبارات الثيم | New | غائبان عن المستودع (E07 §5) | استخراج المخطط من الإنتاج، وكتابة migrations مطابقة واختبارات عقد قبل أي تعديل | E07 §5 |

### 3.10 العميل والمحفظة والولاء والعناوين

المصدر: [E04](../evidence/E04-customer-identity-wallet-loyalty.md) §5 و§5.1–5.2.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| `users.wallet_balance` و`users.loyalty_point` | Preserve | يقرؤهما 8 و13 موضعاً في PHP و16 سطراً في Blade و4 عقود في Flutter (E04 §5) | يبقيان إسقاطاً لرصيد المتجر السعودي الافتراضي، ويُحدَّثان من الكاتب الموحّد في المعاملة نفسها | `lavkoo:database/migrations/2025_07_13_021823_modify_wallet_balance_column_in_users_table.php:14-16` · VERIFIED |
| مفاتيح الـ API (`wallet_balance`، `loyalty_point`، `total_wallet_balance`…) | Preserve | مقروءة حرفياً في التطبيقين (E04 §5) | إضافة مفاتيح جديدة لكل متجر فقط | `lavkoo:app/Http/Controllers/RestAPI/v1/UserWalletController.php:31` · VERIFIED |
| `wallet_transactions` و`loyalty_point_transactions` | Extend | السجل السعودي الوحيد للأرصدة (E04 §5) | أعمدة قابلة لـ NULL: متجر أو كيان، عملة، مرجع طلب أو دفعة أو استرداد، منفّذ، مفتاح idempotency | `lavkoo:app/Repositories/LoyaltyPointTransactionRepository.php:114-160` · VERIFIED |
| الكتّاب الأربعة للمحفظة وكاتبا الولاء | Refactor | متباعدون، بلا قفل صفّي، وبلا مفتاح تكرار (E04 §5) | خدمة كتابة واحدة؛ الدوال القديمة واجهات رقيقة عليها | `lavkoo:app/Utils/CustomerManager.php:36-91` · VERIFIED |
| منطق مكافأة الإحالة وقواعد كسب النقاط | Refactor | ثلاثة سلوكيات لمكافأة واحدة (E04 §5) | دالة واحدة بحارس تكرار مربوط بـ`referral_customers` | E04 §5 |
| رصيد لكل (عميل، متجر أو كيان، عملة) | New | مطلوب لـ GCC-13 وGCC-15 (E04 §5؛ `lavkoo:docs/gcc/DECISIONS.md:41`) | جدول أرصدة بقيد فريد وقفل صف؛ مصدر الحقيقة للمتاجر الجديدة | E04 §5.2؛ `lavkoo:docs/gcc/DECISIONS.md:41` · VERIFIED |
| النظام الموحّد للعناوين (`AddressSchema`، `ShippingAddressService`، `AddressSnapshot`) | Preserve | يعمل ومختبر للسعودية، واللقطة تحفظ التاريخ (E04 §5) | ترشيح القوائم بـ`country_code` ومحقّق لكل دولة بدل شرط السعودية | `lavkoo:app/Services/Address/AddressSchema.php:127-134` · VERIFIED؛ `lavkoo:app/Services/ShippingAddressService.php:83-88` · VERIFIED |
| `delivery_country_codes` و`delivery_zip_codes` | Extend | إعدادات عامة (E04 §5) | نطاق متجر وربط الرمز البريدي بدولة | E04 §5 |
| هوية العميل والحرّاس | Preserve | أساس GCC-13 (E04 §5) | إغلاق بنود SEC أولاً، ثم تطبيع الهاتف وقيود التفرّد | `lavkoo:config/auth.php:82-86` · VERIFIED |
| الموافقات والتفضيلات | New | غير موجودة (E04 §5؛ E06 §5) | سجل موافقات بنطاق (متجر، قناة، غرض) وطابع زمني ومصدر | E04 §5 |
| حذف الحساب | Refactor | حذف نهائي دون قراءة الأرصدة (E04 §5) | إخفاء الهوية مع إبقاء السجل المالي، وفحص الأرصدة لكل كيان | E04 §5 |
| `customer_wallets` و`customer_wallet_histories` و`RewardPointController` | Refactor (إزالة لاحقة) | متروكة بجانب مسار حيّ (E04 §5) | التحقق من بيانات الإنتاج ثم تعطيل المسار | E04 §5 |

### 3.11 الموظفون والأدوار والصلاحيات

المصدر: [E05](../evidence/E05-staff-roles-permissions-audit.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| `ModulePermissionMiddleware` و`Helpers::module_permission_check` ومفاتيح الوحدات الـ14 | Preserve | آلية عاملة تغطي 91.4% من المسارات حسب E05 §5، وGCC-14 ينص على إبقائها | لا تغيير؛ يُضاف وسيط نطاق مجاور | `lavkoo:app/Http/Middleware/ModulePermissionMiddleware.php:20-31` · VERIFIED؛ `lavkoo:routes/admin/routes.php:581-620` · VERIFIED |
| `admin_roles` وعمود `module_access` | Preserve | بيانات إنتاج قائمة؛ أي تغيير في الشكل يكسر الأدوار الحالية (E05 §5) | لا يُخزَّن النطاق فيه؛ جدول ربط جديد | E05 §5 |
| أفعال «المشرف الرئيسي فقط» | Preserve | ضوابط مقصودة (E05 §5) | تعريف مقابل على مستوى الكيان إن لزم | E05 §5 |
| `SellerPrincipal` و`SellerPermissionService` و`EnsureSellerPermission` و`EnsureSellerIsOwner` | Preserve | نموذج تفويض موحَّد للويب والـAPI بإغلاق افتراضي (E05 §5) | بُعد متجر اختياري في الـPrincipal دون تغيير دلالة `sellerId()` | `lavkoo:app/Services/Marketplace/SellerPrincipal.php:66-96` · VERIFIED؛ `lavkoo:app/Http/Middleware/EnsureSellerPermission.php:26-59` · VERIFIED؛ `lavkoo:app/Http/Middleware/EnsureSellerIsOwner.php:34-38` · VERIFIED |
| `SellerStaffAccessMiddleware` (خريطة مقاطع URL) | Refactor | يعتمد على مقاطع المسار ويتباين مع بوابات الـAPI (E05 §5) | توحيد تدريجي على `seller_can:` لكل مسار | E05 §5 |
| العزل اليدوي بـ`seller_id` في متحكمات Vendor | Refactor | اتساق غير كامل (E05 §5) | نطاق نموذج أو خدمة مقيِّدة بعد مسح المواضع | E05 §5 |
| `AuditLogger` و`AuditTrail` و`AuditTrailObserver` | Extend | نقطة كتابة واحدة مع تنقيح أسرار (E05 §5) | ختم النطاق مركزياً؛ ضمّ `AdminRole` للتحديثات الجماعية | E05 §5 |
| `ApprovalEngine` | Extend | محرك عام بقاعدتَي maker-checker (E05 §5) | حقل كيان أو متجر على الطلب وتصفية الصندوق به | `lavkoo:app/Services/ApprovalEngine.php:40-79` · VERIFIED |
| متحكمات التقارير الإدارية (استعلامات مباشرة) | Refactor | لا نقطة مركزية للنطاق ولا بُعد عملة (E05 §5) | خدمة تقارير مقيَّدة بالنطاق | `lavkoo:app/Http/Controllers/Admin/DashboardController.php:297-318` · VERIFIED |
| `DemoMaskingTrait` | Extend | وصلة جاهزة على مستوى accessors (E05 §5) | شرط إخفاء مبني على نطاق الموظف بدل وضع demo فقط | `lavkoo:app/Traits/DemoMaskingTrait.php:13-20` · VERIFIED |
| جدول نطاقات الموظفين و Principal إداري | New | غير موجودة (E05 §5؛ E03 §5.1) | `Staff Scope` مرتبط بالمتجر أو الكيان (PROPOSED) | E05 §5 |
| اختبارات تغطية المسارات | Extend | موجودة لوحدتين فقط (E05 §5) | اختبار يثبت أن كل مسار إداري يحمل وحدة أو مُدرَج في قائمة استثناء صريحة | `lavkoo:tests/Feature/SalesChannels/ModulePermissionsTest.php:25` · VERIFIED |

### 3.12 الاتصالات والامتثال والإيقاف وSEO

المصدر: [E06](../evidence/E06-communications-compliance-activation.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| أسماء الـ topics الحالية (FCM) | Preserve | مثبّتة في إصدارات التطبيق المنشورة (E06 §5؛ E16 §5) | إضافة topics بلاحقة السوق بجانبها؛ عدم إعادة تسمية القائم | `lavkoo:app/Utils/settings.php:264-269` · VERIFIED |
| `PushNotificationTrait` (بناء حمولة FCM) | Extend | يعمل لكل الأطراف (E06 §5) | «سياق اتصال» اختياري يحدد بيانات الاعتماد واسم الـ topic؛ الافتراضي = الحالي | E06 §5 |
| `notification_messages` و`email_templates` | Extend | بنية وترجمات سليمة (E06 §5) | طبقة override اختيارية لكل متجر تسقط إلى العام | E06 §5 |
| `MailConfigServiceProvider` | Refactor (محدود) | يستبدل الإعداد كله بمرسل واحد عند الإقلاع (E06 §5؛ E02 L9) | mailers مسمّاة لكل متجر أو كيان تُختار عند الإرسال | `lavkoo:app/Providers/MailConfigServiceProvider.php:29-45` · VERIFIED |
| `ZenderOtpService` و`ZenderSetting` | Extend | مسار OTP الفعلي للعملاء (E06 §5) | صف لكل متجر؛ معالجة SEC-E06-03 | E06 §5 |
| `NotificationAutomationService` | Preserve (خامل) | غير موصول (E06 §5) | لا يُوصَل قبل وجود سياق متجر وموافقة | E06 §5 |
| `BusinessPage` و`PagesController` | Extend | يخدم الويب والتطبيقين (E06 §5) | إصدارات مرتبطة بمتجر ولغة وحالة (draft / approved / published)؛ الصف الحالي إصدار سعودي منشور (GCC-11) | `lavkoo:database/migrations/2025_03_08_201607_create_business_pages_table.php:14` · VERIFIED |
| وضع الصيانة الحالي (`maintenance_mode`) | Preserve | صيانة المنصة كلها حاجة قائمة (E06 §5) | إبقاؤه «صيانة المنصة»؛ قراءة الحالة من مصدر دائم لا من الكاش وحده | `lavkoo:app/Traits/MaintenanceModeTrait.php:14` · VERIFIED |
| إيقاف المتجر (GCC-12) | New | غير موجود (E06 §5) | حالة لكل storefront (active / browse_only / maintenance / redirect) تُفحص في middleware الواجهة ونقاط إنشاء السلة والطلب، مع قائمة استثناء للدفع والـ webhooks والطلبات السابقة | E06 §5؛ `lavkoo:docs/gcc/DECISIONS.md:27` · VERIFIED |
| إغلاق متجر البائع (`temporary_close`) | Preserve | نظير وظيفي لـ browse-only على مستوى البائع (E06 §5) | مرجع لمواضع الفحص، لا يُعاد توظيفه كدولة | E06 §5 |
| تعريف مسارات الواجهة (3735 استدعاء `route(`) | Refactor (حسّاس) | الاستدعاءات تعتمد على الأسماء (E06 §5) | مجموعة بادئة `{store}` مع قيمة افتراضية، وإبقاء المسارات الجذرية كتحويلات | `lavkoo:routes/web/routes.php:165` · VERIFIED |
| `ApplySeoRedirects` و`redirects` | Preserve | آلية آمنة fail-open (E06 §5) | استعمالها لتحويل المسارات القديمة في خطة 301 | E06 §5 |
| `config/deeplinks.php` و`DeepLinkResolver` | Extend | مصدر واحد للأنماط مع اختبار (E06 §5) | أنماط مسبوقة ببادئة المتجر؛ إبقاء الأنماط الجذرية | `lavkoo:config/deeplinks.php:39-45` · VERIFIED |
| روابط `/go/{code}` و QR و`cart.recover` | Preserve | مطبوعة ومتداولة؛ التوقيع صالح 30 يوماً (E06 §5) | يبقى المسار الجذري دائماً، وطوال نافذة الصلاحية | E06 §5 |
| DeliverySyria (معزول) | Preserve | تكامل اختياري معطّل افتراضياً (E06 §5) | لا تغيير؛ إزالة الكود الخامل لاحقاً بموافقة | E06 §5 |
| Analytics / Telemetry / Monitoring | Extend | مسجِّلات قائمة (E06 §5) | بُعد متجر في الجلسة والحدث | E06 §5 |
| `subscriptions` وشريط الكوكيز | Extend | بدائية (E06 §5) | موافقات بنطاق (قناة × متجر) مع طابع زمني ومصدر | E06 §5 |

### 3.13 backend البائع وAPI v3

المصدر: [E14](../evidence/E14-seller-vendor-backend-api-v3.md) §5؛ [E23](../evidence/E23-api-contract-seller.md) §5؛ [E01](../evidence/E01-backend-architecture-map.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| عقد v3 الحالي (236 مساراً وأشكال ردودها) | Preserve | تطبيق منشور يعتمد عليه؛ guardrail 4 (E14 §5) | إضافات اختيارية فقط؛ اختبارات عقد قبل أي تغيير | `lavkoo:routes/rest_api/v3/seller.php:139` · VERIFIED |
| `SellerApiAuthMiddleware` و`SellerPrincipal` و`seller_can` و`seller_owner` | Preserve | نقطة واحدة تحلّ من يعمل وعلى أي متجر تاجر (E14 §5) | إضافة «المتاجر المسموحة» إلى الـPrincipal | `lavkoo:app/Http/Middleware/SellerApiAuthMiddleware.php:56-64` · VERIFIED |
| كتالوج الصلاحيات الـ11 | Preserve | مخزَّن في أدوار البائعين ونطاقات المفاتيح (E14 §5) | نطاق متاجر بجانب المفاتيح لا داخلها | `lavkoo:app/Services/Marketplace/SellerPermissionService.php:57-73` · VERIFIED |
| `Seller` و`Shop` وحقولهما | Preserve | هوية وبيانات تاريخية سعودية، ودلالة «تاجر» صحيحة (E14 §5) | لا أعمدة دولة عليهما؛ العضوية في كيان جديد | `lavkoo:app/Models/Seller.php:40-68` · VERIFIED |
| حارس البائع الداخلي (`seller_id = 0`، `added_by = 'admin'`) | Preserve (مراجعة دلالية لازمة) | مزوّدو التنفيذ والتقارير والتسوية مبنية عليه (E14 §5) | تحديد تمثيل «داخلي لكل دولة» دون تغيير الحارس | `lavkoo:app/Utils/Helpers.php:765-767` · VERIFIED |
| `VendorLedger` و`PayoutService` (البائع) | Extend | سليم بنيوياً: غير قابل للتعديل، idempotent، ومقفول (E14 §5) | بُعد (كيان قانوني، عملة) للرصيد والاستعلامات | `lavkoo:app/Services/Marketplace/VendorLedger.php:36-48` · VERIFIED |
| `seller_wallets` و`withdraw_requests` (المسار القديم) | Preserve ثم تجميد | أرصدة سعودية تاريخية (E14 §5) | لا توسعة لدول جديدة؛ تسوية مع الدفتر قبل أي ترحيل | E14 §5 |
| Seller Center (insights، أتمتة، مهام، webhooks، مفاتيح) | Extend | متّسق العزل وحديث (E14 §5) | إشارة المتجر على الصفوف والحمولات؛ إزالة التقريب الثابت لخانتين | E14 §5 |
| المتحكّمات الكلاسيكية في v3 واللوحة | Refactor (أمني أولاً) | عزل غير متّسق (SEC-E14-01 … 14) (E14 §5) | إحكام الملكية قبل إضافة أي بُعد جديد | E14 §5 |
| تحويلات `Convert::usd` في مسارات البائع (44 موضعاً) | Refactor | عملة أساس عامة واحدة (E14 §5) | خدمة مال واعية بعملة المتجر؛ السعودية تبقى مطابقة | `lavkoo:app/Utils/Convert.php:9-22` · VERIFIED |
| `GET /api/v1/config` و`seller_app_version_control` | Preserve | بوابة الإصدار الحالية للتطبيق المنشور (E14 §5) | حقول إضافية فقط؛ لا تغيير للموجود | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:133-291` · VERIFIED |
| Seller API v2 القديم | Refactor (قرار إيقاف) | سطح قديم موازٍ لعقد v3 (E01 §5، SEC-E01-02) | التأكد من عدم وجود عملاء، ثم الإيقاف، قبل GCC | E01 §5 |
| عضوية بائع↔متجر، حساب استلام لكل دولة، KYC لكل دولة | New | غير موجودة (E14 §5) | مرتكزات جديدة (PROPOSED) | E14 §5 |
| اختبارات عقد وعزل لمجال البائع | New | شبه غائبة (E14-F09) | مجموعة انحدار سعودية قبل أي تغيير | E14 §5 |

### 3.14 تطبيق العميل

المصدر: [E16](../evidence/E16-customer-app-architecture-context.md) §5؛ [E17](../evidence/E17-customer-app-dynamic-theme.md) §5؛ [E18](../evidence/E18-customer-app-commerce-flows.md) §5؛ [E22](../evidence/E22-api-contract-customer.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| `DioClient._headers()` وترويسات الطلب | Extend | نقطة واحدة لكل الترويسات (E16 §5؛ E22 §5) | ترويسة سياق المتجر في الدالة نفسها، مع إمكان التفريغ دون تغيير الترويسات الحالية | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73` · VERIFIED |
| مسارات `/api/v1/*` بلا سياق (156 مساراً يستدعيها التطبيق، E22 §5) | Preserve | عقد كل النسخ المثبَّتة (E16 §5؛ E22 §5) | إبقاؤها حيّة ومحسومة على السعودية للعملاء عديمي السياق | `lavkoo:routes/rest_api/v1/api.php` · VERIFIED |
| شكل رد `/api/v1/config` | Preserve | تحليل صارم في التطبيق (E16 §5) | إضافات فقط (حقول جديدة اختيارية)؛ لا تغيير نوع ولا حذف | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:133-291` · VERIFIED |
| `ConfigModel` | Extend | يستهلك كل إعدادات المتجر (E16 §5) | حقول اختيارية للمتجر والعملة والخانات مع تحليل متسامح | E16 §5 |
| `PriceConverter` | Refactor | تحويل صرف في العميل وصفر خانات في 114 موضعاً (E16 §5؛ E18 §5) | منسِّق يأخذ رمز العملة وعدد الكسور من الخادم، دون ضرب بسعر صرف | `lavkooapp:lib/helper/price_converter.dart:65-73` · VERIFIED |
| `SplashController.getCurrencyData` وأولوية `SYP` | Refactor | أولوية `SYP` تُبطل اختيار المستخدم (E16 §5) | اشتقاق العملة من المتجر؛ إزالة فرع `SYP` بعد التأكد من بيانات الإنتاج | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:231-240` · VERIFIED |
| مفاتيح الكاش المحلي (Dio و`DataSyncRepo` و Drift) | Refactor | بلا بُعد متجر أو بُعد لغة فقط (E16 §5؛ E22 §5) | مولّد مفاتيح يتضمن المتجر، ومسح عند الخروج وعند تبديل المتجر | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-143` · VERIFIED |
| بوابة الإصدار (`_updateIsRequired`) | Extend | تعمل برقم ثابت يدوي ورابط فارغ (E16 §5؛ SEC-E16-11) | قرار المالك في ترقيم الإصدار والرابط | E16 §5 |
| شاشة الصيانة وتدفّقها | Extend | وضع واحد يحجب كل شيء (E16 §5) | أوضاع GCC-12 الثلاثة مع إبقاء السلوك الحالي للنسخ القديمة | E16 §5 |
| `DeepLinkResolver` و`DeepLinkGatewayScreen` | Extend | الخادم مصدر الحقيقة (E16 §5) | دعم مقطع الدولة في القراءة المحلية والراوتر والـ manifest | `lavkooapp:android/app/src/main/AndroidManifest.xml:131-141` · VERIFIED |
| موضوعات FCM الثلاثة الثابتة | Preserve (للإرث) | النسخ القديمة لن تلغي اشتراكها (E16 §5) | موضوعات جديدة لكل متجر للنسخة الجديدة، مع إلغاء الاشتراك عند التبديل | E16 §5 |
| `LocalizationController` (`language_code` و`country_code`) | Preserve | يعمل ودلالته Locale (E16 §5) | عدم إعادة استعمال `country_code` للمتجر | `lavkooapp:lib/localization/controllers/localization_controller.dart:78-82` · VERIFIED |
| توكن العميل `user_login_token` | Preserve | هوية واحدة عبر الدول (GCC-13) (E16 §5) | لا تغيير في البُعد | E16 §5 |
| نموذج العنوان وشاشة الإضافة | Extend | نص حر لا يستهلك كتالوج المناطق (E16 §5؛ E18 §5) | ربط اختياري بكتالوج العناوين الموحّد لكل دولة | E16 §5 |
| معرّفات التطبيق (applicationId و bundle id و Firebase) | Preserve (تسجيل فقط) | تعليمات المشروع تمنع إعادة التسمية الآلية؛ bundle id الـ iOS لا يطابق إعداد Firebase | قرار مالك موثّق؛ لا تغيير آلي | `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414` · VERIFIED؛ `lavkooapp:ios/Runner/GoogleService-Info.plist:9-10` · VERIFIED |
| منتقي المتجر ومفتاح المتجر المحفوظ | New | غير موجود (E16 §5) | شاشة اختيار عند أول تشغيل وفي الإعدادات، ونقطة خادم لقائمة المتاجر (E22 §5) | E16 §5؛ E22 §5 |
| تخزين الثيم (مفتاح التخزين و`writeCachedPayload`) | Extend | نموذج جاهز للتخزين المؤقت (E17 §5) | إضافة بُعد المتجر إلى مفتاح التخزين وإلى `syncStamp` | `lavkooapp:lib/features/dynamic_theme/domain/repositories/dynamic_theme_repository.dart:177-196` · VERIFIED |
| ذاكرة `DioClient` العامة (`_cacheableEndpoints`) | Refactor | تُبطل فحص الإصدار داخل الجلسة، ومفاتيحها بلا متجر (E17 §5) | إخراج `/theme/version` من القائمة أو إعطاؤها مدة؛ إدخال المتجر في المفتاح | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:124-143` · VERIFIED |
| `ProfileModel.wallet_balance` و`loyalty_point` | Extend | قيمة واحدة (E18 §5) | بنية أرصدة لكل متجر، مع إبقاء الحقلين للنسخ القديمة | E18 §5 |
| أحداث Meta (`MetaTrackingService`) | Refactor | عملة ثابتة `'SYP'` (E18 §5) | تمرير عملة المتجر الفعلية | E18 §5 |
| `AddFundToWalletScreen` القديمة | Refactor (تنظيف) | غير مربوطة بالمسار (E18 §5) | لا إجراء في هذه المرحلة؛ تُسجَّل كشيفرة غير مستعملة | `lavkooapp:lib/features/wallet/screens/add_fund_to_wallet_screen.dart:13` · VERIFIED |
| مسار MyFatoorah في التطبيق | Preserve | تحقق خادمي بثلاث نتائج ومغطى باختبارات (E18 §5؛ E21 §2.9) | إبقاؤه؛ التأكد من أن `currency` في الرد تعكس عملة المتجر | `lavkooapp:lib/features/checkout/domain/services/myfatoorah_payment_service.dart:1` · VERIFIED |
| إثبات نجاح الدفع بـ`order_ids` | Preserve | يمنع نجاحاً زائفاً (E18 §5) | توحيد عقد مفتاح المحاولة عبر مسارات الطلب (SEC-E18-05) | `lavkooapp:lib/features/checkout/controllers/checkout_controller.dart:361-375` · VERIFIED |
| CI والاختبارات وأداة فحص الأسرار ومحتوى `ksaapp98` المفقود | Dependent-On-PR | غائبة في `lavkooapp`، وحالة نقلها تتبع حسم النسب (E21 §2.9، F09) | انظر 3.17 | `ksaapp98@79f50be` · VERIFIED |

### 3.15 تطبيق البائع

المصدر: [E19](../evidence/E19-seller-app-architecture-auth-gate.md) §5؛ [E20](../evidence/E20-seller-app-operations-finance.md) §5؛ [E23](../evidence/E23-api-contract-seller.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| `DioClient` للبائع وترويسات الجلسة (`updateAuthToken`) | Extend | نقطة مركزية ومختبَرة (E19 §5) | ترويسة سياق بالأسلوب نفسه؛ مدخل واحد في الخريطة | E19 §5 |
| المواضع التسعة لـ `http.MultipartRequest` | Refactor (بعد الموافقة) | تقع خارج العميل المركزي فلا ترث أي ترويسة (E19 §5؛ E23 §5) | توحيدها على `DioClient` أو مساعد واحد قبل إضافة أي ترويسة سياق | E19 §5 |
| نقطة «من أنا» للتطبيق | New | التطبيق لا يعرف صلاحياته ولا متاجره (E19 §5) | مسار قراءة يعيد نوع الحساب والصلاحيات والمتاجر أو الكيانات المتاحة | E19 §5 |
| `GET /api/v1/config` المشترك مع تطبيق العميل | Preserve | يكسر التطبيقين معاً إن تغيّر شكله (E19 §5) | الإبقاء على الاستجابة الافتراضية؛ السياق كمعامل أو ترويسة اختيارية | `lavkoo:app/Http/Controllers/RestAPI/v1/ConfigController.php:133-291` · VERIFIED |
| بوابة الإصدار (`AppVersion` و`seller_app_version_control`) | Preserve | بوابة عاملة مع اختبار (E19 §5) | توثيق القيمة الإنتاجية وربطها بثابت الاختبار؛ لا بوابة لكل دولة ما دام التطبيق واحداً | E19 §5 |
| شاشة الصيانة وموضوع `maintenance_mode_start_vendor` | Extend | تعمل للإيقاف الشامل (E19 §5) | تمثيل إيقاف متجر الدولة كحالة بيانات داخل التطبيق لا كحجب كامل | `lavkooappseller:lib/features/maintenance/maintenance_screen.dart:127-158` · VERIFIED |
| تسجيل رمز FCM (`cm-firebase-token`) | Extend | عمود واحد لكل بائع (E19 §5) | جدول أجهزة مرتبط بالـ principal مع الإبقاء على المسار الحالي للنسخ القديمة | E19 §5 |
| توجيه الإشعارات بحسب `type` | Extend | يعمل للأنواع الحالية (E19 §5) | إضافة معرّف المتجر إلى الحمولة؛ إزالة الاعتماد على نص العنوان | E19 §5 |
| مواضيع FCM للبائع (`lavkoo_seller`) | Extend | بلا مرسِل (E19 §5) | قرار: إلغاؤه أو تعريف مواضيع مسمّاة بنطاق (تطبيق × دولة) | E19 §5 |
| `PriceConverter` (تطبيق البائع) | Refactor (محدود) | كسور ثابتة وإسناد `usdCurrency!` (E19 §5؛ E23 §5) | تمرير عملة وكسور السجل من الطلب أو المتجر؛ إزالة الثوابت | `lavkooappseller:lib/helper/price_converter.dart:24` · VERIFIED |
| قائمة اللغات الثابتة و`lang` | Preserve | تعمل؛ `sa` مطويّة (E19 §5) | عدم حمل الدولة في `lang`؛ مفتاح مستقل للدولة | E19 §5 |
| التخزين المحلي للتطبيق | Extend | مفاتيح عامة بلا معرّف حساب أو متجر (E19 §5) | إسباق المفاتيح الحساسة بمعرّف الحساب أو المتجر عند إدخال اختيار المتجر | E19 §5 |
| هوية التطبيق (package / bundle / Firebase) | Preserve | تعليمات المشروع تمنع إعادة التسمية الآلية (E19 §5) | تحديث `MIGRATION.md` ليطابق الواقع؛ حسم عدم اتساق Firebase iOS | `lavkooappseller:android/app/build.gradle.kts:39` · VERIFIED؛ `lavkooappseller:ios/Runner.xcodeproj/project.pbxproj:443` · VERIFIED |
| ميزة `security` (فريق، أدوار، مفاتيح، Webhooks) | Preserve | مكتملة وظيفياً (E19 §5) | عرض وتحرير نطاق الدولة للدور عند إضافته خلفياً | E19 §5 |
| `AppHealthReporter` | Extend | بسيط وغير معرِّف (E19 §5) | حقل اختياري يميّز التطبيق (seller أو customer) | E19 §5 |
| POS offline outbox | Refactor | مفتاح تخزين عام غير مرتبط بالمتجر (E20 §5؛ SEC-E20-03) | ربط كل عنصر بـ(البائع، المتجر) مع ضوابط إعادة الإرسال على الخادم | E20 §5 |
| مسار `quantity-update` و`restock-request-stock-update` (الخادم) | Refactor | كاتب موازٍ لـ`current_stock` خارج الدفتر الموحّد (E20 §5؛ SEC-E20-06) | توجيهه عبر `InventoryService` مع بقاء شكل الطلب | `lavkoo:app/Services/Marketplace/InventoryService.php:50-53` · VERIFIED |
| مصدرا السحب (`wallet` القديم و payouts الدفتري) | Refactor (قرار) | مصدران للسحب (E20 §5؛ E23 §5) | تحديد مصدر واحد لكل كيان قانوني قبل GCC | `lavkoo:app/Services/Marketplace/VendorLedger.php:104-116` · VERIFIED |
| تقرير VAT | Extend | يعمل لمتجر واحد (E20 §5) | بُعد كيان قانوني ودولة | E20 §5 |
| محدّد سياق المتجر في تطبيق البائع | New | غير موجود (E20 §5؛ E23 §5) | اختيار متجر أو دولة، وترويسة سياق في `DioClient` والمواضع التسعة لـ`MultipartRequest` | E23 §5 |
| عقود Seller Center المالية (statement و payouts) | Extend | تحمل `currency` أصلاً (E23 §5) | استخدام `currency` في العرض؛ أرصدة لكل كيان وعملة | `lavkoo:app/Http/Controllers/RestAPI/v3/seller/SellerStatementController.php:87-91` · VERIFIED |
| `Convert::usd` على حدود الـ API | Extend (لا Refactor) | إعادة كتابتها تغيّر أرقام بائعي السعودية (E23 §5) | مسار تحويل يعتمد عملة متجر البائع، مع بقاء السلوك للمتجر الافتراضي | `lavkoo:app/Utils/Convert.php:9-22` · VERIFIED |
| عقد المنتج (add / update) | Extend | مرن وقابل للإضافة (E23 §5) | بُعد storefront وضرائب مقيّدة بالمتجر | E23 §5 |

### 3.16 الاختبارات وCI

المصدر: [E15](../evidence/E15-tests-ci-quality-gates.md) §5.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| حزم `tests/Feature/{Fulfillment,SalesChannels,OperationalCosts,Accounting,MyFatoorah,Address,Concurrency}` و`tests/Unit/*` | Preserve | خط الانحدار الوحيد للسلوك السعودي في هذه المسارات (E15 §5) | لا تُعدَّل توقعاتها ضمن عمل GCC؛ أي تغيير يُعامل كتغيير سلوك سعودي يحتاج موافقة | `lavkoo:tests/Feature/Fulfillment/OwnershipSnapshotTest.php:121-133` · VERIFIED |
| الأصناف التي تثبّت SAR ومنزلتين (`FulfillmentTestCase` و`MyFatoorahTestCase` و`FakeCurrencyRates`) | Extend | صحيحة للسعودية لكنها لا تعبّر عن سوق آخر (E15 §5) | مزوّد بيانات «سياق السوق» مع بقاء SAR/2 افتراضياً؛ الحالات الجديدة تُضاف ولا تُستبدل | `lavkoo:tests/Support/BuildsMyFatoorahSchema.php:42-61` · VERIFIED |
| بُناة المخطط `tests/Support/Builds*Schema.php` | Refactor (بموافقة) | ثلاث صيغ للعمود المالي نفسه (E15 §5) | مخطط بنيوي واحد (بلا بيانات) مستخرج من الإنتاج | E15 §5 |
| عُدّة التزامن `tests/Support/Concurrency/*` | Preserve | تعمل على محرك حقيقي بعمليات حقيقية (E15 §5) | تسجيلات جديدة لكل واجهة متجر: محفظة، ولاء، قسيمة، مخزون checkout | E15 §5 |
| أنماط «dormant until migrated» و«EverythingStartsOff» | Preserve | النموذج الصحيح لإطلاق GCC خاملاً (E15 §5) | كل هجرة GCC تأتي باختبارات «إضافية/متكررة/قابلة للتراجع» و«المتجر السعودي غير المهاجَر يعمل كما كان» | `lavkoo:tests/Feature/SalesChannels/EverythingStartsOffTest.php:35-47` · VERIFIED |
| `.github/workflows/ci.yml` | Extend | مصفوفة محرّكات ناضجة (E15 §5) | مهمة «Saudi baseline» إلزامية؛ توثيق الفحوص المطلوبة للدمج | `lavkoo:.github/workflows/ci.yml:24-42` · VERIFIED |
| `scripts/ci/leak-guard.php` | Extend | قاعدة الهاتف سعودية فقط (E15 §5) | قواعد أرقام الدول الخمس الأخرى | `lavkoo:scripts/ci/leak-guard.php` · VERIFIED |
| `scripts/deploy/*` | Preserve | بوابات drift والنسخ الاحتياطي والتراجع مختبرة (E15 §5) | تعريف الأساس لكل بيئة عند تعدّد البيئات | `lavkoo:scripts/deploy/build-overlay.php:158-161` · VERIFIED |
| `ApiManifest` / `api:snapshot` / `RecordApiResponseShape` | Extend | تشغيلية وغير متتبَّعة، ولا تقارن الاستجابات (E15 §5؛ E22 §5) | لقطة JSON متتبَّعة في git تُقارن في CI بـ`--fail-on-breaking` | E15 §5؛ E22 §5 |
| اختبارات عقد تطبيق البائع (14 ملفاً) | Preserve | تثبّت ما يتوقعه التطبيق (E23 §5) | توليد الحمولات من اختبارات الخلفية (golden files مشتركة) | E23 §5 |
| اختبارات تطبيق العميل (ثيم و MyFatoorah) | Preserve | تحمي الثيم الديناميكي والدفع (E15 §5) | لا شيء | `lavkooapp:test/section_variant_coverage_test.dart:8-15` · VERIFIED |
| CI لتطبيق العميل والاختبارات الستة الموجودة في `ksaapp98` فقط | Dependent-On-PR | غائبة في `lavkooapp`؛ حالة نقلها تتبع E21 (E15 §5؛ E21 §2.9) | نقلها بعد حسم النسب وقبل أي تعديل GCC على التطبيق (انظر 3.17) | `ksaapp98@79f50be` · VERIFIED |
| حزم خط الأساس للتجارة الأساسية (سلة، checkout، محفظة، ولاء، قسائم، استرداد، ثيم، إشعارات، مصادقة، Seller API) | New | غير موجودة إطلاقاً (E15 §5) | القائمة B1–B13 المذكورة في E15 §5 | E15 §5 |
| الملف المسارات الموروث غير المحمَّل والأوامر `prepare:database` و`database:refresh` | Refactor (حذف بموافقة) | آثار تشغيلية كبيرة وبلا اختبار (E15 §5؛ SEC-E15-01 و SEC-E15-03) | حذفها قبل إنشاء بيئات GCC إضافية | E15 §5 |

### 3.17 النسب والمستودعات القديمة (Dependent-On-PR)

المصدر: [E21](../evidence/E21-git-lineage-legacy-prs.md) §2.4 و§2.6 و§5 و§6 و§7.

| المكوّن | التصنيف | السبب | أصغر إضافة موصى بها (PROPOSED) | الدليل |
|---|---|---|---|---|
| تاريخ `lavkoo` بمعرّفاته الأصلية (443 commit على `main`) | Preserve | السجل الوحيد القابل للتحقق لكل ما بُني؛ لا force push ولا squash (E21 §5) | حماية الفرع `main` | `lavkoo@34dce65` · VERIFIED؛ `lavkoo@352ed68` · VERIFIED |
| دمج `lavkoo@cbf2cdd` (PR #17) وما يحمله من المسارات C–G | Preserve | الكود المختبر كشجرة واحدة؛ تفكيكه يعيد تعارضات حُلّت (E21 §5) | لا شيء | `lavkoo@cbf2cdd` · VERIFIED؛ `lavkoo@89f1be9` · VERIFIED |
| مفاتيح التكاملات المطفأة افتراضياً | Preserve ثم Extend | نمط «يُسلَّم خامداً» يخدم تفعيل الأسواق تباعاً (E21 §5) | ربط التفعيل بالمتجر أو الكيان القانوني بدل `.env` العام (تصميم لاحق) | `lavkoo:config/sales_channels.php:23` · VERIFIED |
| أدوات النشر overlay و`PILOT_BASELINE` | Preserve ثم Extend | الطريقة الموثّقة الوحيدة لمعرفة ما يعمل على الخادم (E21 §5) | تسجيل SHA المنشور فعلياً في وثيقة حالة بعد كل نشر | `lavkoo:.github/workflows/ci.yml:189` · VERIFIED |
| migrations ببادئات مستقبلية حتى `2026_11_22` | Preserve | ترتيبها جزء من عقد المسارات (E21 §5، F13) | أي migration GCC تأخذ بادئة لاحقة لـ `2026_11_22_100000` | E21 §5 |
| معرّفات الحزم ومشاريع Firebase الحالية | Preserve (حتى قرار صريح) | توجيه المالك يمنع إعادة التسمية الآلية (E21 §5) | حسم تعارض iOS في تطبيق العميل بقرار مكتوب | `lavkooapp:ios/Runner/GoogleService-Info.plist:9-10` · VERIFIED |
| Amazon: الاستكمال (PR-C، PR #12، وفرع `amazon-feeds`) | Dependent-On-PR | الكود في main عبر `lavkoo@cbf2cdd`؛ حالة PR #12 الحيّة غير معلومة؛ الفرع يتقدم حتى `lavkoo@36cd50a` (E21 §2.4، F03) | قراءة حالة PR #12 ووجود commits بعد 2026-11-11 في المستودع القديم، ثم تثبيت المرجع في الوثائق | `lavkoo:docs/sales-channels/merge-plan.md:127-134` · VERIFIED؛ `lavkoo@7efe022` · VERIFIED؛ `lavkoo@36cd50a` · VERIFIED |
| Trendyol (PR-D، PR #13) وNoon (PR-E، PR #14) | Dependent-On-PR | الكود في main عبر #17؛ حالة #13 و#14 الحيّة UNKNOWN؛ Noon لا يوجد إلا عبر #17 (E21 §2.6) | كما أعلاه | `lavkoo@32249dd` · VERIFIED؛ `lavkoo@0366ba7` · VERIFIED |
| Odoo (PR-F، PR #15) | Dependent-On-PR | الكود في main عبر #17 فقط؛ حالة #15 الحيّة UNKNOWN؛ ترحيلات الوحدة على الإنتاج غير مؤكدة (E21 §4، F06) | كما أعلاه، مع فحص الصفوف القديمة للعمولة (3.8) | `lavkoo@a907bf0` · VERIFIED |
| Guards و safeguards (PR-G، PR #16، على `integration-cdef`) | Dependent-On-PR | 21 ملفاً أضافها PR-G عبر #17؛ حالة #16 الحيّة UNKNOWN (E21 §2.6) | كما أعلاه | `lavkoo@8f92f7e` · VERIFIED |
| تطبيق العميل: CI والاختبارات والأداة والوثائق و`ksaapp98@1a34a4d` (إصلاح فيض التخطيط) | Dependent-On-PR | غائبة في `lavkooapp@eedbbb8`؛ أصل `lavkooapp@0df8b09` غير معلوم (E21 §2.9، F09، سؤال 6) | إفادة منشئ `lavkooapp` ونقل المفقودات بعد الموافقة في أول مرحلة معتمدة | `ksaapp98@1a34a4d` · VERIFIED؛ `lavkooapp@0df8b09` · VERIFIED |
| وثائق `DECISIONS.md` و`STATUS.md` (فقرة PR #12–#16) | Refactor (توثيق) | تقول إن الأرقام مفتوحة، والكود موجود في main (E21 §4، §5) | تصويب بعد اعتماد هذا الملحق؛ `STATUS.md` نفسه يطلب التحقق | `lavkoo:docs/gcc/STATUS.md:35` · VERIFIED؛ `lavkoo:docs/gcc/DECISIONS.md:60-61` · VERIFIED |
| تطبيق البائع (`lavkooappseller`) | Preserve (أصل غير معلوم) | ثلاثة commits، ولا مستودع سابق متاح؛ الهوية مختلطة (E21 §2.10) | حسم الهوية في `MIGRATION.md`، وإفادة المالك عن الأصل (E21 سؤال 8) | `lavkooappseller@afa12db` · VERIFIED؛ `lavkooappseller@e56a533` · VERIFIED |

## 4. الإضافات الدنيا الموحّدة (PROPOSED)

أصغر مجموعة بنى جديدة تحقق القرارات المعتمدة دون إعادة كتابة. كل بند مقترح للتوثيق فقط، ويحتاج موافقة المالك وتصميماً مفصّلاً خارج هذه المرحلة.

| # | البنية المقترحة | ما تحققه | الملاحق | قيد |
|---|---|---|---|---|
| P1 | `StoreContext`: كائن واحد يُحلّ مرة لكل طلب أو وظيفة؛ مصادره بادئة الويب وترويسة API اختيارية وكيان الوظيفة؛ الافتراضي السعودي | بادئات المسارات (Foundation)، GCC-19 | E01 §5، E02 §5، E22 §5 | لا يغيّر أي مسار سعودي بلا تخصيص |
| P2 | مرتكزات `Country` و`Storefront` و`Legal Entity`، ومرجع المتجر على المرتكزات المباشرة فقط (نحو 22) | تمييز المفاهيم (Foundation)، كيان قانوني منفصل، GCC-12 وGCC-13 | E03 §5.1 | لا `country_id` في كل جدول |
| P3 | طبقة تخصيص الإعدادات بمفتاح كاش يتضمن المتجر، مع بقاء `getWebConfig()` | كاش بمحدِّد سوق (Constraints)، GCC-10 وGCC-11 | E02 §5 | الإعدادات القانونية والحساسة لا تُورَث |
| P4 | منسِّق مبالغ واعٍ بالعملة والخانات، وتوسيع دقة الأعمدة الضيقة (expand-only) | تسعير محلي دون FX تلقائي، KWD وBHD وOMR بثلاث خانات | E03 §5، E08 §5، E16 §5 | لا تحويل آلي |
| P5 | جدول أرصدة (عميل × متجر أو كيان × عملة) مع توسيع الدفترين بأعمدة قابلة لـ NULL وكاتب واحد | GCC-13، GCC-15، فصل المحافظ حسب الكيان والعملة | E04 §5.1–5.2 | الأعمدة الحالية تبقى للسعودية؛ تقرير المطابقة أولاً |
| P6 | حالة المتجر `active / browse_only / maintenance / redirect` مع قائمة استثناء | GCC-12 | E06 §5 | لا تُستعمل `ExternalOperations` ولا `auto_dispatch` لإيقاف المتجر (E11 §5) |
| P7 | حسابات مرتبطة بالكيان القانوني: حساب بوابة، وحساب تنفيذ (Fulfillment Account)، وحساب قناة | الحسابات تُربط بالكيان (Foundation)، مصدر تنفيذ واحد لكل منتج | E10 §5، E11 §5، E12 §5 | الحساب السعودي الحالي هو الافتراضي |
| P8 | خدمة أهلية المنتج للشحن العابر للحدود، تُستدعى من checkout | GCC-16، فحص الأهلية قبل التوصيل | E11 §5 | `RegulatedProductGate` سابقة للنمط وليس مكوّناً جاهزاً |
| P9 | لقطة الكيان والعملة على الطلب، ومفتاح idempotency لكل محاولة دفع | لقطات تاريخية، تدفقات مالية منفصلة | E09 §5 | تُملأ للطلبات الجديدة فقط |
| P10 | `Staff Scope` جدول ربط فوق `module_access` | GCC-14 | E05 §5، E03 §5.1 | لا يُغيَّر `admin_roles.module_access` |
| P11 | أدوات قياس مرجعية: مخطط بنيوي مقيّد بلا بيانات، وحزمة توصيف للسعودية قبل أي تغيير | فجوات الاكتشاف، guardrail 6 | E01 §5، E15 §5 | لا تُستعمل بيانات إنتاج في المستودع |

## 5. ما يجب ألا يُمسّ

ما يلي يحتفظ بدلالته وعقده دون تغيير، ويُضاف إليه فقط عند الحاجة وبالإضافة المتوافقة:

- **الترقيم والمراجع التاريخية:** `orders.id` وفضاء `100001+`، و`provider_fulfillments.reference`، ولقطات الطلب، وفواتير السعودية القائمة (E03 §5، E09 §5).
- **أرصدة السعودية:** `users.wallet_balance` و`users.loyalty_point`، وأسماء مفاتيح الـ API المرتبطة بها، ودفترا المحفظة والولاء (E04 §5).
- **دلالات الملكية:** `seller_id` و`seller_is` و`added_by` و`shop_id`، والحارس الداخلي `seller_id = 0`؛ ولا تُعاد استعمالها كدولة أو متجر (E03 §5، E14 §5).
- **العقود المنشورة:** مسارات `api/v1` و`api/v3/seller` وأشكال ردودها، وشكل `/api/v1/config`، وصيغة الخطأ `{errors:[{code,message}]}` (E14 §5، E22 §5، E23 §5).
- **الترويسة `lang`** وطيّ `sa` إلى `ar` (E02 §5، E22 §5).
- **دوال العملة القديمة:** لا تُعاد كتابتها بما يغيّر أرقام السعودية؛ تُضاف بجانبها (E02 §5، E14 §5).
- **التنفيذ والمخزون:** `FulfillmentRouter` و`ProviderOrderDispatcher` و`InventorySyncService` و`StockAllocator` و`PoolNet` و`ReservationService` وoutbox المخزون ومحوّلا Torod وM5AZN (E11 §5، E12 §5).
- **القنوات:** `ChannelHttp` و`ChannelOperations` و`ChannelCredentialStore` و`ListingDraftService` و`ChannelOrderIngestor` وأسوار الإرسال (E12 §5).
- **الدفع:** خدمات MyFatoorah وعقد `payment_requests` وأسماء مفاتيح `addon_settings` الحالية (E10 §5).
- **المالية:** `CostLedger` وOdoo outbox والسياج و`VendorLedger` و`PayoutService` و`SettlementEngine` (E13 §5).
- **الثيم:** دورة draft / publish / archive / restore، و`/api/v1/theme/sections` المجمّد، و`banners.theme` (E07 §5).
- **الإشعارات والروابط:** أسماء FCM topics الحالية، وأنماط الروابط العميقة، و`/go/{code}`، وتوقيع `cart.recover` (E06 §5، E16 §5).
- **الإيقاف الشامل والـ SEO:** `maintenance_mode` بوصفه صيانة المنصة، ومحرّك الـ redirects (E06 §5).
- **الهوية:** `applicationId` و`bundle id` و Firebase لكل تطبيق؛ لا إعادة تسمية آلية (E16 §5، E19 §5، E21 §5).
- **الترحيلات الموجودة:** بادئاتها الحالية ولا إعادة توقيت (E21 §5).
- **سجل Git:** لا force push ولا squash ولا rebase على `main` في `lavkoo` (E21 §5).
- **أدوات النشر:** overlay و`PILOT_BASELINE` والنسخ الاحتياطي والتراجع (E01 §5، E15 §5).
- **نظام العناوين الموحّد:** `AddressSchema` و`ShippingAddressService` و`AddressSnapshot` (E04 §5).
- **الصلاحيات والتدقيق:** `ModulePermissionMiddleware` و`SellerPrincipal` و`seller_can` و`ApprovalEngine` وسجل التدقيق (E05 §5، E14 §5).
- **اختبارات الانحدار السعودية:** لا تُعدَّل توقعاتها ضمن عمل GCC (E15 §5).
- **مفاتيح التكاملات المطفأة افتراضياً:** `SALES_CHANNELS_ENABLED` و`ODOO_ENABLED` و`FULFILLMENT_INTEGRATIONS_ENABLED`؛ لا تُفعَّل إلا بقرار مكتوب (`lavkoo:config/sales_channels.php:23`، `lavkoo:config/odoo.php:28-42`، `lavkoo:config/fulfillment.php:26-34`).
- **روابط الرجوع القديمة:** روابط الرجوع من بوابات الدفع والـ webhooks والروابط العميقة القائمة تبقى على السعودية أو متجر الكيان المسجَّل (E02 §5).

## حدود هذا التقرير وعناصر UNKNOWN

- **UNKNOWN — البنية الفعلية للإنتاج:** أي commit يعمل عليه الخادم، وأي migration نُفِّذ منذ `698aa1e` (E21-F06، P0). يحسمه قراءة `migrations` و`MANIFEST.json` لآخر نشر، للقراءة فقط وبموافقة المالك.
- **UNKNOWN — حالة PR #12–#16 القديمة:** وجود commits بعد 2026-11-11 في المستودع القديم (missing access: `pharmacyksa98`). يحسمه وصول قراءة (E21-F03).
- **UNKNOWN — نتائج CI** على `lavkoo@89f1be9` و`lavkoo@cbf2cdd` و`lavkoo@f61939f` (E21 سؤال 2).
- **UNKNOWN — قيم الإعدادات في الإنتاج:** `business_settings` و`addon_settings` و`currencies` و`provider_*`؛ يحسمها استعلام قراءة معزول لاحقاً.
- **UNKNOWN — صفوف العمولة القديمة:** هل توجد صفوف بالطريقة القديمة في `operational_cost_entries` (E21-F14، سؤال 10).
- **UNKNOWN — أصل تطبيق البائع ومعرّفاته النهائية وحالة نشره في المتاجر** (E21 سؤالا 7 و8).
- **UNKNOWN — قدرات Tabby وTamara** في الدول الست؛ لا كود ولا تجربة (E10 §5، E21 §2.6).
- **UNKNOWN — السلوك التشغيلي لـ Paymob:** بلا اختبارات (E10 §5).
- **الأرقام المنقولة من الملاحق** (936 و3735 و142 و67 و91.4% و236 و183 و233 و156 و114 و487 و530 و44) تعود إلى الملاحق كما وردت. أُعيد فحص بعضها، ولم يُعَد كلها هنا.
- **الصفوف مختصرة.** الصف الكامل وتفصيله في الملحق المُحال إليه.
- **الملاحق المقيّدة لم تُفتح.** بنود SEC تُذكر بمعرّفاتها فقط؛ تفاصيلها خارج هذا التقرير، وتُعالج قبل أي توسعة مرتبطة بها.
- **Dependent-On-PR** تصنيف مؤقت حتى حسم النسب (3.17).
- **لا يحدد هذا التقرير ترتيب التنفيذ** ولا يقترح تنفيذاً؛ كل بند PROPOSED يحتاج موافقة المالك.

## سجل الأدلة الرئيسية

| الادعاء | الموضع | الحالة |
|---|---|---|
| الإعدادات العامة مخزّنة في كتلة كاش واحدة | `lavkoo:app/Utils/settings.php:26-30` | VERIFIED |
| `web_config` يُبنى عند الإقلاع قبل التوجيه | `lavkoo:app/Providers/AppServiceProvider.php:196-225` | VERIFIED |
| كاش القوائم بلا بُعد متجر | `lavkoo:app/Traits/CacheManagerTrait.php:134-151` | VERIFIED |
| تفريغ شامل للكاش من حدث محلي | `lavkoo:app/Http/Controllers/Admin/Marketplace/ExchangeRateController.php:50` | VERIFIED |
| تفعيل الثيم حصري عالمياً | `lavkoo:app/Services/Theme/ThemeManager.php:83-86`؛ `lavkoo:app/Services/Theme/ThemeDelivery.php:715-726` | VERIFIED |
| عمود رصيد المحفظة `decimal(18,12)` | `lavkoo:database/migrations/2025_07_13_021823_modify_wallet_balance_column_in_users_table.php:14-16` | VERIFIED |
| كاتب المحفظة الحالي | `lavkoo:app/Utils/CustomerManager.php:36-91` | VERIFIED |
| تجميع المحفظة الإدارية بمفتاح `admin_id = 1` | `lavkoo:app/Utils/OrderManager.php:2168` | VERIFIED |
| مفاتيح التكاملات مطفأة افتراضياً | `lavkoo:config/sales_channels.php:23`؛ `lavkoo:config/odoo.php:28-42`؛ `lavkoo:config/fulfillment.php:26-34` | VERIFIED |
| كاتب مخزون المزوّد وحمايته من التعديل اليدوي | `lavkoo:app/Services/Fulfillment/InventorySyncService.php:101-128`؛ `lavkoo:app/Services/Marketplace/InventoryService.php:50-53` | VERIFIED |
| `RegulatedProductGate` قائم بقواعد القناة ويرفض عند الجهل | `lavkoo:app/Services/SalesChannels/Listings/RegulatedProductGate.php:26-55` | VERIFIED |
| اختبار مفاتيح التشغيل المطفأة | `lavkoo:tests/Feature/SalesChannels/EverythingStartsOffTest.php:35-47` | VERIFIED |
| أمر فحص صفوف العمولة القديمة موجود | `lavkoo:app/Console/Commands/CostsCheckLegacyCommissionSlotsCommand.php:46-50` | VERIFIED |
| سياج الدفع والحد من التكرار في Odoo | `lavkoo:app/Services/Accounting/Odoo/OdooOutbox.php:49-72` | VERIFIED |
| السلة تُقسَم بحسب مجموعة البائع | `lavkoo:app/Utils/OrderManager.php:887-902` | VERIFIED |
| تفرّد `code` و`barcode` عالمياً في طلب الإنشاء | `lavkoo:app/Http/Requests/ProductAddRequest.php:49-52` | VERIFIED |
| تسعير تطبيق العميل يحوّل بسعر الصرف | `lavkooapp:lib/helper/price_converter.dart:65-73` | VERIFIED |
| تطبيق العميل يعطي أولوية لعملة `SYP` عند اختيار العملة | `lavkooapp:lib/features/splash/controllers/splash_controller.dart:231-240` | VERIFIED |
| ترويسات تطبيق العميل مركزية في `DioClient` | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73` | VERIFIED |
| تطبيق البائع يحوّل بسعر صرف USD | `lavkooappseller:lib/helper/price_converter.dart:24` | VERIFIED |
| bundle id الـ iOS في تطبيق العميل لا يطابق إعداد Firebase | `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414`؛ `lavkooapp:ios/Runner/GoogleService-Info.plist:9-10` | VERIFIED |
| commits تنفيذ PR #12–#16 موجودة في `main` عبر PR #17 | `lavkoo@7efe022`؛ `lavkoo@32249dd`؛ `lavkoo@0366ba7`؛ `lavkoo@a907bf0`؛ `lavkoo@8f92f7e`؛ `lavkoo@cbf2cdd` | VERIFIED |
| وثائق الخطة تصف PR #12–#16 بأنها مفتوحة | `lavkoo:docs/sales-channels/merge-plan.md:127-134` | VERIFIED |
| `DECISIONS.md` يطلب التحقق من وجود commits قديمة في `lavkoo` | `lavkoo:docs/gcc/DECISIONS.md:60-61` | VERIFIED |
| Tabby وTamara غير موجودين في الكود | `lavkoo:docs/gcc/DECISIONS.md:15` (والبحث المسجَّل في E21 §2.6) | VERIFIED |
| تطبيق العميل لا يحمل CI ولا الاختبارات الستة الموجودة في `ksaapp98` | `ksaapp98@79f50be`؛ `lavkooapp@eedbbb8` (E21 §2.9) | VERIFIED |
| الإنتاج الفعلي ومدى تطبيق الـ migrations | E21 §6 F06 | UNKNOWN |
| الترحيلات الخاصة بوحدة التكاليف لم تُشغَّل على الإنتاج | `lavkoo@95e0ae3` وفق رسالة الـ commit (E21 §4) | INFERRED |
