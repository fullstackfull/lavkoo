# Lavkoo GCC — Discovery-01 — التقرير 01: الملخص التنفيذي وتغطية التدقيق والعوائق الحرجة

> **المرحلة:** AUDIT (قراءة فقط) — **الجلسة:** `claude/exciting-shannon-4mh2ok` — **التاريخ:** 2026-11-12 (+03:00)
> **SHA الأساس:** `lavkoo@7f42777` · `lavkooapp@eedbbb8` · `lavkooappseller@8ea0092` · مرجع قديم `ksaapp98@79f50be`
> **يعتمد على:** [التقرير 02](./02-cross-repo-architecture-api-contracts.md) · [التقرير 03](./03-data-ownership-isolation-matrix.md) · [التقرير 04](./04-preserve-extend-refactor-new-matrix.md) · [التقرير 05](./05-gap-risk-register.md) · [التقرير 06](./06-git-lineage-legacy-prs.md) · [التقرير 07](./07-migration-rollback-plan.md) · [التقرير 08](./08-web-customer-seller-impact-matrix.md) · [التقرير 09](./09-test-strategy.md) · [التقرير 10](./10-implementation-roadmap.md) · [التقرير 11](./11-open-decisions-for-owner.md) · [التقرير 12](./12-go-no-go-recommendation.md) · الملاحق [E01 إلى E23](#8-فهرس-التقارير-والملاحق)
> **الحالة:** نهائي لهذه الجلسة — لا يتضمن أي تنفيذ.

> **وضع هذا التقرير:** توثيقي. التوصيات `PROPOSED` ولا تُعدّ تعليمات تنفيذ، ولا تعيد فتح أي قرار معتمد في `docs/gcc/DECISIONS.md`. لم يُشغَّل أي كود أو اختبار أو استعلام، ولم يُفحص أي وضع إنتاجي.

## 1. الخلاصة

- دُقّقت المستودعات الثلاثة الحالية (`lavkoo` للخلفية، و`lavkooapp` لتطبيق العميل، و`lavkooappseller` لتطبيق البائع) قراءةً ساكنة على SHA الأساس، مع مراجعة مرجعية لـ `ksaapp98`. تعذّر الوصول إلى المستودع القديم `pharmacyksa98`، فكل ما يتعلق به `UNKNOWN`.
- الحكم الأساسي: الكود الحالي يفترض متجراً واحداً وعملة واحدة وكياناً قانونياً واحداً. لا يحمل أي مسار أو ترويسة أو عمود أو مفتاح كاش سياق المتجر أو الدولة أو الكيان، والأرصدة والدفاتر والمخطط والكاش بلا بُعد دولة. من أصل 130 مدخلاً في سجل المخاطر، 29 مدخلاً من الدرجة P0 ([التقرير 05](./05-gap-risk-register.md) القسم 5).
- التوصية: **No-Go** لأي تنفيذ تطبيقي الآن. **Go مشروط** لاعتماد المرحلة 0 كنطاق مكتوب ومحدود، بالشروط C1 إلى C10 في [التقرير 12](./12-go-no-go-recommendation.md).
- لم يُنفَّذ شيء في هذه الجلسة. لم يُعدَّل أي ملف في المستودعات، ولم يُنشأ أي commit أو فرع أو Pull Request.
- ثلاثة بنود تحتاج قراراً من المالك قبل أي عمل آخر: قيمة شبيهة ببيانات اعتماد في ملف إعداد متتبَّع، وبند أمني من الدرجة P0 في مسار إداري، وثلاثة commits عامة تحتوي نصوصاً غير منقّحة في السجل (القسم 6).

## 2. ما الذي دُقِّق وكيف

- **المنهج:** قراءة ساكنة فقط عبر Read وGrep وGlob وأوامر git للقراءة. لم تُشغَّل اختبارات أو builds أو أوامر `artisan` و`composer` و`flutter`، ولم يُجرَ أي اتصال بالإنتاج أو بمزوّد خارجي.
- **الدليل:** كل استشهاد بالصيغة `<repo>:<path>:<line>` أو `<repo>@<sha>`، ويُتحقق منه آلياً بعد إعادة فتح الموضع. وسم كل نتيجة `VERIFIED` أو `INFERRED` أو `UNKNOWN` كما وردت في الملاحق دون ترقية.
- **حالة GitHub عند بدء الجلسة:** لكل مستودع من المستودعات الثلاثة الحالية فرع واحد (`main`)، ولا يوجد فيه Pull Request مفتوح أو مغلق ([README الفهرس](./README.md) القسم 2).

### 2.1 تغطية المجالات A إلى I

| الحرف | البند | الملاحق والتقارير | الحالة |
|---|---|---|---|
| A | خريطة الكود والمعمارية: المسارات والطبقات والـ Models والترحيلات والـ middleware والـ guards والطوابير والـ Modules والاختبارات وCI والفروق بين المستودعات | E01، E15، E21، E22، E23؛ [التقرير 02](./02-cross-repo-architecture-api-contracts.md) | مكتمل ساكناً. أجزاء من POS وقوالب Blade وجرد v2 للبائع فُحصت بالعينات فقط |
| B | سياق المتجر والملكية والعزل: مرتكزات الملكية والكاش والجلسة والإعدادات والعملة | E02، E03، E04؛ [التقرير 03](./03-data-ownership-isolation-matrix.md) | مكتمل ساكناً. قيم الإعدادات والمخطط في الإنتاج `UNKNOWN` |
| C | الكتالوج والاكتشاف: الشجرة والعلامات والمنتجات والـ SKU والباركود والأسعار والعملة والـ SEO والعروض والقسائم والبحث | E08، E06 (SEO والروابط)، E12 (ربط التصنيفات بالأسواق) | مكتمل ساكناً |
| D | المخزون والدفع والشحن والمحاسبة: مزامنة المزوّد والحجوزات والسلة والـ checkout والطلب والـ webhooks والتنفيذ والاسترداد والتسوية وقنوات المخزون والشحن العابر | E09، E10، E11، E12، E13 | مكتمل ساكناً. قدرات المزوّدين وحالة التشغيل الحية `UNKNOWN` (Q-33، Q-34) |
| E | الثيم والتكامل مع الويب وتطبيق العميل: ThemeManager وThemeBuilderService وThemeDelivery والنشر والـ revision والـ checksum والكاش والثيم الديناميكي والإصدار والتوطين | E07، E16، E17، E22 | مكتمل ساكناً |
| F | العميل والموظفون والولاء: هوية العميل والمحفظة والولاء والعناوين والموافقات، وأدوار الموظفين والصلاحيات والتدقيق والتصدير، ومسار العميل في التطبيق | E04، E05، E18 | مكتمل ساكناً. المسائل الأمنية مقيّدة بالمعرّفات |
| G | الاتصالات والامتثال والتفعيل: الإشعارات والـ topics والبريد وSMS والواتساب، الصفحات القانونية، الصيانة والإيقاف، التوجيه، SEO، الروابط العميقة | E06، E02 (الصيانة والإعدادات) | مكتمل ساكناً. التقييم القانوني خارج نطاق التدقيق |
| H | نسب Git والـ PRs والفروع: المستودعات القديمة والحالية، الدموج، إعادة التسمية، حالة الوظائف | E21؛ [التقرير 06](./06-git-lineage-legacy-prs.md) | **مقيّد.** `pharmacyksa98` غير متاح، فحالة PR #12 إلى #16 القديمة `UNKNOWN` |
| I | تطبيق البائع (إلزامي): الدخول والصلاحيات والمنتجات والمخزون والطلبات والمالية والإشعارات والروابط العميقة وبوابة الإصدار وعقد v3 | E14، E19، E20، E23 | مكتمل ساكناً. تشغيل CI على GitHub وإصدارات المتاجر المنشورة `UNKNOWN` |

### 2.2 ما لم يُفحص

- الإنتاج كله: قاعدة البيانات، و`.env`، وإعدادات المتجر الحية، والنسخ المنشورة في المتاجر، ولوحات المزوّدين. كل قيمة إنتاجية `UNKNOWN`.
- المستودع `pharmacyksa98` (الخلفية القديمة): الوصول مرفوض في هذه الجلسة.
- أجزاء من POS وقوالب Blade وتطبيقات Flutter وجرد v2 للبائع: فُحصت بالعينات، والتفصيل في القسم 9 من الملاحق المعنية.
- الحكم القانوني والضريبي والمحتوى التسويقي: خارج نطاق التدقيق.

## 3. أهم النتائج

الترقيم يشير إلى مدخل السجل في [التقرير 05](./05-gap-risk-register.md). الحالة تُنقل من الملحق كما هي.

1. **المخطط الفعلي للإنتاج لا يمكن إعادة بنائه من المستودع.** 37 جدولاً تُعدَّل في migrations ولا تُنشأ فيها، وأكثر من 100 model بلا migration إنشاء، ولا dump متتبَّع. ويختلف العددان بين الملحقين (37 و110 في E01، و36 و71 في E03). الاختبارات تعمل على مخطط مستنتَج. **R-001، P0، VERIFIED للكود و UNKNOWN للمخطط.** الدليل: [E01](./evidence/E01-backend-architecture-map.md) §6 F01، [E03](./evidence/E03-data-model-ownership-inventory.md) §6 F01، `lavkoo:.gitignore:53`.

2. **أساس الإنتاج نفسه غير معروف.** الوثائق تفترض أن الخادم التجريبي يعمل على `lavkoo@698aa1e7`، بينما يزيد `main` عليه 11 دمجاً رئيسياً و32 migration. لا دليل ساكن على ما نُفّذ على الخادم. **R-022 وGIT-R01، P0، UNKNOWN.** الدليل: `lavkoo:docs/integrations/hotfix-2.md:28`، `lavkoo:.github/workflows/ci.yml:189`، [التقرير 06](./06-git-lineage-legacy-prs.md) القسم 9.

3. **لا يوجد أي مرجع لسياق المتجر أو الدولة أو الكيان في أي نقطة دخول.** لا مسار ولا ترويسة ولا middleware ولا عمود يحمل ذلك. كل الإعدادات (العملة والبوابات والثيم والمنطقة الزمنية) عامة وتُحمَّل عند الإقلاع. **R-004، P0، VERIFIED.** الدليل: `lavkoo:app/Models/BusinessSetting.php:13`، [E02](./evidence/E02-store-context-settings-currency-cache.md) §6 F02.

4. **المبالغ المالية بلا عملة، وتُفسَّر وقت القراءة من إعداد عام.** الطلبات وسطورها والقيود والمعاملات والاسترداد لا تحمل عملة، فتبديل إعداد واحد يعيد تفسير كل السجلات التاريخية دون ترحيل. **R-003، P0، VERIFIED للكود.** الدليل: `lavkoo:app/Utils/currency.php:50-67`، [E02](./evidence/E02-store-context-settings-currency-cache.md) §6 F01.

5. **التحويل الآلي للعملة موجود في الدفع والعرض، ويتعارض مع القيد المؤقت "لا تحويل آلي".** مبلغ الدفع يُحوَّل بسعر الصرف في `multi_currency`، وتطبيق العميل يضرب كل سعر بمعامل صرف. التعارض مثبت في الدفع ومحتمل في العرض. **R-011 وR-012، P0، VERIFIED للكود.** الدليل: `lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-423`، `lavkooapp:lib/helper/price_converter.dart:65-78`، القيد في `lavkoo:docs/gcc/DECISIONS.md:39` ([Q-38](./11-open-decisions-for-owner.md)).

6. **الكود يقرّب كل المبالغ بخانتين عشريتين، فيُفقد الفلس الثالث.** نحو 193 موضعاً تستعمل `round(…,2)`، وتطبيق العميل يعرض بصفر خانات. الدينار الكويتي والبحريني والريال العُماني تحتاج ثلاث خانات. **R-014 وR-036، P0 وP1، VERIFIED للكود و UNKNOWN لقيم الإنتاج.** الدليل: [E10](./evidence/E10-payments-gateways.md) §6 F04، [E02](./evidence/E02-store-context-settings-currency-cache.md) §6 F05.

7. **المحافظ والنقاط والدفاتر مفتاحها المستخدم أو المتجر الداخلي وحده.** رصيد المحفظة والنقاط عمودان عامان على جدول `users`، وأربعة كتّاب للمحفظة يكتبون في مسارات منفصلة. محفظة الإدارة مفتاحها ثابت (`admin_id = 1`)، ودفتر البائع مفتاحه (البائع، نوع البائع) بلا كيان أو عملة. السلة والطلب تُحصَّل بدفعة واحدة لكل البائعين المحددين. **R-007 إلى R-010، P0، VERIFIED.** الدليل: `lavkoo:app/Models/User.php:95-96`، `lavkoo:app/Services/Marketplace/VendorLedger.php:74-80`، `lavkoo:app/Utils/OrderManager.php:809-823`، `lavkoo:app/Http/Controllers/Customer/PaymentController.php:379-383`.

8. **الكتالوج والكاش والبحث بلا بُعد متجر.** 85 موضعاً تقرأ المنتجات مباشرة، ويشمل فهرس البحث كل المنتجات. مفاتيح الكاش لا تحمل بُعد سوق، و17 موضعاً يفرّغ مخزن الكاش كله. وتطبيق العميل يخزّن ملف الثيم وبيانات المنتجات بلا بُعد متجر ويعرضها قبل أي اتصال. **R-006 وR-025 وR-026، P0، VERIFIED.** الدليل: `lavkoo:app/Models/Product.php:208-249`، `lavkoo:app/Traits/CacheManagerTrait.php:153-192`، [E08](./evidence/E08-catalog-discovery-pricing.md) §6 F02، [E17](./evidence/E17-customer-app-dynamic-theme.md) §6 F01.

9. **الفواتير والضرائب ليست مستندات قانونية ثابتة.** إعداد ضريبي واحد عام بعلم شمول واحد، ورقم الفاتورة هو رقم الطلب ويُولَّد من إعدادات حية وقت الطباعة، ولا توجد لقطة كيان أو رقم ضريبي أو إشعار دائن. **R-018 وR-019، P0، VERIFIED للكود.** الدليل: `lavkoo:Modules/TaxModule/config/config.php:16`، `lavkoo:resources/views/admin-views/order/invoice.blade.php:527-532`.

10. **الصفحات القانونية والموافقات التسويقية عالمية.** نموذج الصفحات القانونية جدول واحد بلا دولة أو إصدارات أو اعتماد، ويخدم العملاء والبائعين معاً. لا يوجد سجل موافقة لأي قناة، وتذكير السلة يعمل دون موافقة مسجّلة. **R-017 وR-059، P0 وP1، VERIFIED.** الدليل: `lavkoo:database/migrations/2025_03_08_201607_create_business_pages_table.php:14-23`، `lavkoo:app/Console/Commands/SendAbandonedCartReminders.php:90-113`.

11. **صلاحيات الموظفين بلا نطاق.** مفتاح صلاحية واحد يغطي بيانات العملاء والمحافظ والولاء والتصدير، ولا نطاق للمتجر أو الدولة أو الكيان. التقارير والتصديرات تجمع النظام كله بعملة واحدة. **R-005 وR-020، P0، VERIFIED.** الدليل: `lavkoo:routes/admin/routes.php:581-584`، `lavkoo:app/Http/Controllers/Admin/ReportController.php:138-149`.

12. **الاختبارات لا تحمي التجارة الأساسية، ولا توجد بيئة staging.** لا اختبار للسلة ودفتر المحفظة والـ `refund_requests` وقواعد القسائم والثيم في الخلفية، ولا CI لتطبيق العميل، ولا staging في المستودعات. حالة CI على `main` غير معروفة. **R-021 وR-084 وR-086، P0 وP1، VERIFIED للغياب و UNKNOWN لنتيجة CI.** الدليل: [التقرير 09](./09-test-strategy.md) القسم 1، [E15](./evidence/E15-tests-ci-quality-gates.md) §F11.

13. **نسب Git: محتوى PR #12 إلى #16 موجود في `main`، والوثائق تقول إنها مفتوحة.** الدموج الـ42 كلها أسلاف لـ `main`، ومحتوى PR #12 إلى #16 دُمج عبر PR #17 (`lavkoo@cbf2cddb`). ووثائق المستودع تصف الحالة بأنها مفتوحة و"لم يُدمج شيء". حالة تلك الطلبات في المستودع القديم `UNKNOWN`. **GIT-R02 وGIT-R03 وGIT-R05.** الدليل: `lavkoo@cbf2cddb`، `lavkoo:docs/sales-channels/final-report.md:7-8`، [التقرير 06](./06-git-lineage-legacy-prs.md) القسم 1 والقسم 9.

14. **تطبيق العميل ليس لقطة لأي commit في `ksaapp98`، والنسخ المنشورة لا ترسل سياق متجر.** PR #2 موجود جزئياً، والاختبارات وCI وأداة فحص الأسرار غائبة. إعداد iOS يعلن معرّفاً لا يطابق معرّف الحزمة، والنسخ المثبتة تحلّل `config` بصرامة، فأي تغيير في الافتراض أو شكل الاستجابة يكسرها. **R-023 وR-024، P0، VERIFIED.** الدليل: `lavkooapp:ios/Runner/GoogleService-Info.plist:9-10`، `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414`، `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73` (الترويسات بلا سياق متجر)، `lavkooapp:lib/features/splash/controllers/splash_controller.dart:90-128` (تحليل config)، [التقرير 06](./06-git-lineage-legacy-prs.md) القسم 7.

15. **تطبيق البائع لا يعرف نطاقه.** هوية الجلسة مشتقة من الرمز وحده، والرمز مرتبط ببائع واحد ومتجر واحد، ولا يستلم التطبيق صلاحيات المستخدم. تطبيق البائع يقرأ حقل العملة في العقد ولا يستعمله. **R-062 وR-080 وR-013، P0 وP1، VERIFIED.** الدليل: `lavkoo:app/Models/Seller.php:96-99`، `lavkooappseller:lib/features/menu/screens/more_screen.dart:163-165`، [التقرير 08](./08-web-customer-seller-impact-matrix.md).

16. **الدفع: حساب بوابة واحد لكل مزوّد، وMyFatoorah أحادي السوق، وTabby وTamara غير موجودين.** إعداد البوابة صف واحد لكل مفتاح بلا كيان أو متجر، وتكامل MyFatoorah مقيّد بعملة ومضيف سعوديين. كود Paymob متحكّم قالب بلا اختبارات وبلا استرداد، ويعتمد على عودة المتصفح. **R-015 وR-016 وR-071 وR-073، P0 وP1، VERIFIED.** الدليل: `lavkoo:config/myfatoorah.php:60-72`، `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:146`، `lavkoo:docs/gcc/DECISIONS.md:15`.

## 4. العوائق الحرجة (P0)

المجموع 29 من الدرجة P0، موزعة كما في [التقرير 05](./05-gap-risk-register.md) القسم 5. التفاصيل والشروط في [التقرير 12](./12-go-no-go-recommendation.md) القسم 2.

| الفئة | العدد | المعرّفات | الشرط الذي يفتحها |
|---|---|---|---|
| data | 1 | R-001 | لقطة DDL بلا بيانات خارج المستودع العام (C5) |
| process | 2 | R-021، R-022 | خط أساس الاختبار (C7)، وإثبات commit الإنتاج والـ migrations المطبّقة (C3) |
| country-context | 4 | R-004، R-005، R-006، R-020 | StoreContext ومرجع متجر للكتالوج والتقارير بعد G0 (المرحلة 1) |
| cache | 2 | R-025، R-026 | مفاتيح كاش بُعد سوق ومسح عند تبديل المتجر (المرحلة 2 والمرحلة 15) |
| financial | 9 | R-003، R-007 إلى R-014 | قياس الأرصدة على نسخة مستعادة (C6)، وقرارات Q-06 وQ-07 وQ-38، ثم المراحل 3 و6 و7 و9 |
| integration | 3 | R-002، R-015، R-016 | قياسات Q-27 وQ-33، وحساب بوابة لكل كيان (المرحلة 8) |
| legal-compliance | 3 | R-017، R-018، R-019 | إصدارات الصفحات القانونية والفواتير لكل كيان (المرحلتان 12 و14) |
| mobile-compat | 2 | R-023، R-024 | قرار المعرّفات (Q-21)، وبوابة الإصدار (Q-25)، والإصدارات المنشورة (Q-31) |
| security | 3 | R-027، R-028، R-029 | معالجة المعرّفات `SEC-…` بمسار مستقل قبل المراحل 5 و6 و9 و16 (C10) |

## 5. ما الذي يجب الحفاظ عليه

ما يلي مأخوذ من [التقرير 04](./04-preserve-extend-refactor-new-matrix.md) القسم 5 ومن الأساس المعتمد. كل تغيير عليه إضافي ومتوافق، ولا يُعاد كتابته.

- **السعودية كما هي:** غياب سياق المتجر يعني السعودية (GCC-19، `lavkoo:docs/gcc/DECISIONS.md:34`)، والأرصدة `users.wallet_balance` و`users.loyalty_point` ودفترا المحفظة والولاء كما هما ([E04](./evidence/E04-customer-identity-wallet-loyalty.md) §5).
- **المراجع التاريخية:** `orders.id` وفضاء الترقيم القائم، ولقطات الطلب، وفواتير السعودية القائمة، ومعرّفات الدفع والتنفيذ ([E03](./evidence/E03-data-model-ownership-inventory.md) §5، [E09](./evidence/E09-cart-checkout-order-pipeline.md) §5).
- **دلالات الملكية:** `seller_id` و`seller_is` و`added_by` و`shop_id` لا تُعاد استعمالها كدولة أو متجر ([E03](./evidence/E03-data-model-ownership-inventory.md) §5، [E14](./evidence/E14-seller-vendor-backend-api-v3.md) §5).
- **العقود المنشورة:** المسارات `api/v1` و`api/v3/seller` وأشكال ردودها، وشكل `/api/v1/config`، وصيغة الخطأ الحالية. العقد إضافات فقط ([E22](./evidence/E22-api-contract-customer.md) §5، [E23](./evidence/E23-api-contract-seller.md) §5).
- **الترويسة `lang` وطيّ `sa` إلى `ar`** ([E02](./evidence/E02-store-context-settings-currency-cache.md) §5).
- **التنفيذ والمخزون:** `FulfillmentRouter` و`ProviderOrderDispatcher` و`InventorySyncService` و`StockAllocator` و`ReservationService` وoutbox المخزون ومحوّلا Torod وM5AZN ([E11](./evidence/E11-inventory-fulfillment-shipping.md) §5).
- **القنوات والمالية:** أسوار الإرسال وحسابات القنوات، و`CostLedger` وOdoo outbox و`VendorLedger` و`SettlementEngine` ([E12](./evidence/E12-sales-channels-marketplaces.md) §5، [E13](./evidence/E13-finance-odoo-costs-settlements.md) §5).
- **الدفع:** خدمات MyFatoorah وعقد `payment_requests` وأسماء مفاتيح الإعدادات الحالية، وPaymob وMyFatoorah كما هما ([E10](./evidence/E10-payments-gateways.md) §5).
- **الهوية:** `applicationId` وbundle id وFirebase لكل تطبيق. لا إعادة تسمية آلية ([E16](./evidence/E16-customer-app-architecture-context.md) §5، `lavkooapp:CLAUDE.md:3`).
- **الترحيلات القائمة:** بادئاتها الحالية دون إعادة توقيت، وسجل Git بلا force push ولا rebase على `main` ([E21](./evidence/E21-git-lineage-legacy-prs.md) §5).
- **مفاتيح التكاملات المطفأة افتراضياً:** `SALES_CHANNELS_ENABLED` و`ODOO_ENABLED` و`FULFILLMENT_INTEGRATIONS_ENABLED`، ولا تُفعَّل إلا بقرار مكتوب (`lavkoo:config/sales_channels.php:23`، `lavkoo:config/odoo.php:28-42`).
- **اختبارات الانحدار السعودية:** لا تُعدَّل توقعاتها ضمن عمل GCC ([E15](./evidence/E15-tests-ci-quality-gates.md) §5).
- **عناوين الرجوع من البوابات والـ webhooks والروابط العميقة:** تبقى ثابتة وتشير إلى السعودية أو إلى متجر الكيان المسجَّل ([E02](./evidence/E02-store-context-settings-currency-cache.md) §5).

## 6. قيود الوصول والمسائل المقيّدة

### 6.1 قيود الوصول

- **`pharmacyksa98` غير متاح.** الوصول مرفوض في هذه الجلسة. لذلك تبقى حالة PR #12 إلى #16 القديمة، وأي commit بعد `lavkoo@89f1be90`، وحالة CI في المستودع القديم `UNKNOWN` ([التقرير 06](./06-git-lineage-legacy-prs.md) القسم 10).
- **لا وصول إلى الإنتاج:** قاعدة البيانات و`.env` وإعدادات المتجر الحية والنسخ المنشورة في المتاجر ولوحات المزوّدين. قيم `currency_model` و`system_default_currency` و`decimal_point_settings` و`unified_address_mode` و`app.mode` و`user_app_version_control` و`seller_app_version_control` كلها `UNKNOWN` ([التقرير 11](./11-open-decisions-for-owner.md) Q-27).

### 6.2 الملاحق المقيّدة

- الملاحق المقيّدة **غير منشورة** في هذا المستودع، وتُسلَّم للمالك خارجه. يظهر كل بند أمني هنا بمعرّفه `SEC-…` وفئة محايدة فقط.
- عدد المعرّفات 222 عبر 23 ملحقاً ([التقرير 05](./05-gap-risk-register.md) القسم 4). من بينها بنود مُعلنة من الدرجة P0 في E04 (الملخّص) وE05 (01 إلى 03)، وE14 (01 إلى 14 على مستوى المجموعة). والتناقض في نطاق درجة P0 لبنود تطبيق البائع موثّق في [التقرير 12](./12-go-no-go-recommendation.md) القسم 5 (N2).

### 6.3 بنود تحتاج قراراً من المالك

- **SEC-E21-04:** قيمة شبيهة ببيانات اعتماد داخل ملف إعداد متتبَّع. النوع فقط، ولا تُنسخ القيمة ولا المسار في أي وثيقة. **تحتاج تدويراً من المالك.**
- **SEC-E05-03:** مسألة أمنية مقيّدة من الدرجة P0 في مسار إداري. **تحتاج مراجعة المالك** قبل أي عمل على نطاقات الموظفين (GCC-14).
- **ثلاثة commits عامة سابقة تحتوي نصوصاً غير منقّحة في السجل:** `lavkoo@41201499` (لـ E08)، و`lavkoo@98e795b` (لـ E12)، و`lavkoo@f263e3d` (لـ E22). إزالتها من التاريخ **قرار للمالك**. لا تُنفَّذ من هذه الجلسة، ولا يُجرى force push على `main` وفق [COLLABORATION.md](../../COLLABORATION.md).
- **وثائق معتمدة تصف PR #12 إلى #16 بأنها مفتوحة:** `lavkoo:docs/gcc/DECISIONS.md:60-61`، و`lavkoo:docs/gcc/STATUS.md:35`، و`lavkoo:docs/sales-channels/final-report.md:7-8`. هذا تعارض توثيقي مع الأدلة في Git، وليس تعارضاً مع قرار معتمد (GIT-R02 وGIT-R03، و[التقرير 12](./12-go-no-go-recommendation.md) القسم 5، N6). تصويبه يحتاج موافقة المالك لأن `DECISIONS.md` وثيقة معتمدة.
- **تصحيحات مطلوبة قبل اعتماد الشروط:** N1 إلى N8 في [التقرير 12](./12-go-no-go-recommendation.md) القسم 5، ومنها غياب أسئلة لـ P0-D وM-05 وM-14 وM-15 (N3).

### 6.4 تعارض واحد مع قيد مؤقت

- التحويل الآلي للعملة (القسم 3، البند 5) تعارض بين السلوك القائم والقيد المؤقت "لا تحويل آلي" (`lavkoo:docs/gcc/DECISIONS.md:39`). ليس تعارضاً مع قرار رقمي معتمد، ويُعرض على المالك كـ[Q-38](./11-open-decisions-for-owner.md). وأي تعارض آخر مع قرار معتمد لم يُثبت بدليل.

## 7. خلاصة التوصية

| المسار | التوصية | الشروط الرئيسية |
|---|---|---|
| المرحلة 0 من [التقرير 10](./10-implementation-roadmap.md) (P0-A إلى P0-G) | **Go مشروط** بنطاق مكتوب ومحدود، دون تغيير في السلوك | C1 إلى C9 |
| أي مرحلة تطبيقية (1 إلى 17) | **No-Go** حتى يُغلق G0 ويُحسم سؤال المالك الذي يحجب المرحلة | C1 إلى C10 |
| `lavkoo` (الخلفية) | **Go مشروط** للمرحلة 0 فقط. **No-Go** لأي تعديل تطبيقي قبل G0 | C1 إلى C9 |
| `lavkooapp` (العميل) | **No-Go** حالياً. **Go مشروط** لاحقاً لحاجز الاختبارات فقط | C1 وC2 وC3 وC10، وQ-21 وQ-25 وQ-31 |
| `lavkooappseller` (البائع) | **No-Go** حالياً. **Go مشروط** لاحقاً لاختبارات golden فقط | C1 وC3 وC7 وC10، وQ-18 وQ-25 وQ-31 وQ-43 وQ-44 |

أول ما يلزم من المالك بالترتيب: قرار كتابي يخرج الحزمة من DISCOVERY ONLY إلى المرحلة 0 (C1)، ثم إثبات نسب الإنتاج والـ migrations (C3)، ثم لقطة بنية بلا بيانات (C5)، ثم قياسات على نسخة مستعادة (C6). التفاصيل والشروط في [التقرير 12](./12-go-no-go-recommendation.md).

## 8. فهرس التقارير والملاحق

### 8.1 التقارير

| الرقم | العنوان | الرابط |
|---|---|---|
| 01 | الملخص التنفيذي وتغطية التدقيق والعوائق الحرجة | هذا التقرير |
| 02 | المعمارية الحالية عبر المستودعات وعقود الـ API ومخطط الاعتماديات | [02-cross-repo-architecture-api-contracts.md](./02-cross-repo-architecture-api-contracts.md) |
| 03 | مصفوفة ملكية البيانات والعزل والعلاقات المستهدفة | [03-data-ownership-isolation-matrix.md](./03-data-ownership-isolation-matrix.md) |
| 04 | مصفوفة Preserve / Extend / Refactor / New / Dependent-On-PR | [04-preserve-extend-refactor-new-matrix.md](./04-preserve-extend-refactor-new-matrix.md) |
| 05 | سجل الفجوات والمخاطر (P0 / P1 / P2) | [05-gap-risk-register.md](./05-gap-risk-register.md) |
| 06 | نسب Git والـ PRs والفروع والـ commits | [06-git-lineage-legacy-prs.md](./06-git-lineage-legacy-prs.md) |
| 07 | خطة الترحيل والتراجع ومطابقة أرصدة السعودية | [07-migration-rollback-plan.md](./07-migration-rollback-plan.md) |
| 08 | مصفوفة الأثر على الويب وتطبيق العميل وتطبيق البائع | [08-web-customer-seller-impact-matrix.md](./08-web-customer-seller-impact-matrix.md) |
| 09 | استراتيجية الاختبار | [09-test-strategy.md](./09-test-strategy.md) |
| 10 | خارطة طريق التنفيذ بمراحل صغيرة | [10-implementation-roadmap.md](./10-implementation-roadmap.md) |
| 11 | القرارات غير المحسومة التي تحتاج مدخلات المالك | [11-open-decisions-for-owner.md](./11-open-decisions-for-owner.md) |
| 12 | توصية Go / No-Go النهائية | [12-go-no-go-recommendation.md](./12-go-no-go-recommendation.md) |

### 8.2 ملاحق الأدلة

| الرمز | المجال | الرابط |
|---|---|---|
| E01 | خريطة الكود والمعمارية في الـ backend | [E01-backend-architecture-map.md](./evidence/E01-backend-architecture-map.md) |
| E02 | سياق المتجر: الإعدادات والعملة والجلسة والكاش | [E02-store-context-settings-currency-cache.md](./evidence/E02-store-context-settings-currency-cache.md) |
| E03 | جرد نموذج البيانات ومصفوفة الملكية | [E03-data-model-ownership-inventory.md](./evidence/E03-data-model-ownership-inventory.md) |
| E04 | هوية العميل والمحفظة والولاء والعناوين والموافقات | [E04-customer-identity-wallet-loyalty.md](./evidence/E04-customer-identity-wallet-loyalty.md) |
| E05 | أدوار الموظفين والصلاحيات والتدقيق والتصدير | [E05-staff-roles-permissions-audit.md](./evidence/E05-staff-roles-permissions-audit.md) |
| E06 | الاتصالات والصفحات القانونية والإيقاف والتوجيه وSEO | [E06-communications-compliance-activation.md](./evidence/E06-communications-compliance-activation.md) |
| E07 | نظام الثيم في الـ backend | [E07-theme-system-backend.md](./evidence/E07-theme-system-backend.md) |
| E08 | الكتالوج والتصنيفات والأسعار والعروض والقسائم والبحث | [E08-catalog-discovery-pricing.md](./evidence/E08-catalog-discovery-pricing.md) |
| E09 | السلة والدفع والطلبات والمرتجعات والضريبة والفواتير | [E09-cart-checkout-order-pipeline.md](./evidence/E09-cart-checkout-order-pipeline.md) |
| E10 | بوابات الدفع والتحقق والـ webhooks | [E10-payments-gateways.md](./evidence/E10-payments-gateways.md) |
| E11 | المخزون والحجوزات ومزوّدو التنفيذ والشحن | [E11-inventory-fulfillment-shipping.md](./evidence/E11-inventory-fulfillment-shipping.md) |
| E12 | قنوات البيع والأسواق | [E12-sales-channels-marketplaces.md](./evidence/E12-sales-channels-marketplaces.md) |
| E13 | المحاسبة: Odoo والتكاليف والتسويات والضريبة | [E13-finance-odoo-costs-settlements.md](./evidence/E13-finance-odoo-costs-settlements.md) |
| E14 | مجال البائع في الـ backend: Seller API v3 | [E14-seller-vendor-backend-api-v3.md](./evidence/E14-seller-vendor-backend-api-v3.md) |
| E15 | الاختبارات الآلية والـ CI وبوابات الجودة | [E15-tests-ci-quality-gates.md](./evidence/E15-tests-ci-quality-gates.md) |
| E16 | تطبيق العميل: المعمارية والإعداد وانتقال السياق | [E16-customer-app-architecture-context.md](./evidence/E16-customer-app-architecture-context.md) |
| E17 | تطبيق العميل: الثيم الديناميكي وعقده مع الـ backend | [E17-customer-app-dynamic-theme.md](./evidence/E17-customer-app-dynamic-theme.md) |
| E18 | تطبيق العميل: السلة والدفع والطلبات والمحفظة والولاء | [E18-customer-app-commerce-flows.md](./evidence/E18-customer-app-commerce-flows.md) |
| E19 | تطبيق البائع: المعمارية والدخول والصلاحيات وبوابة الإصدار | [E19-seller-app-architecture-auth-gate.md](./evidence/E19-seller-app-architecture-auth-gate.md) |
| E20 | تطبيق البائع: المنتجات والمخزون والطلبات والمالية | [E20-seller-app-operations-finance.md](./evidence/E20-seller-app-operations-finance.md) |
| E21 | نسب Git والـ PRs القديمة وحالة التكاملات | [E21-git-lineage-legacy-prs.md](./evidence/E21-git-lineage-legacy-prs.md) |
| E22 | عقد الـ API: تطبيق العميل مقابل API v1/v2 | [E22-api-contract-customer.md](./evidence/E22-api-contract-customer.md) |
| E23 | عقد الـ API: تطبيق البائع مقابل Seller API v3 | [E23-api-contract-seller.md](./evidence/E23-api-contract-seller.md) |

## حدود هذا التقرير وعناصر UNKNOWN

**تأكيد:** لم يُنفَّذ شيء. لم يُعدَّل أي ملف في `lavkoo` أو `lavkooapp` أو `lavkooappseller` أو `ksaapp98`، ولم يُنشأ أي commit أو فرع أو Pull Request، ولم يُشغَّل أي اختبار أو أمر أو تبعية أو سكربت من المستودعات، ولم يُكتب أي استعلام على قاعدة بيانات.

**UNKNOWN (لا تُحسم بالقراءة الساكنة):**

- المخطط الفعلي للإنتاج، وعدد الجداول وأنواع أعمدتها (R-001). يحسمه C5 بلقطة DDL.
- الـ commit المنشور وقائمة الـ migrations المطبّقة (R-022، GIT-R01، Q-28).
- حالة PR #12 إلى #16 في `pharmacyksa98`، وأي commit بعد `lavkoo@89f1be90` (GIT-R05، Q-30).
- قيم الإعدادات الحية في الإنتاج (R-130، Q-27)، وحالة بوابات الدفع المضافة على الخادم (R-002، Q-27).
- حالة CI على HEAD في المستودعات، وتشغيل CI لتطبيق البائع على GitHub (R-084، Q-36).
- الإصدارات المنشورة للتطبيقين، وأصل تطبيق البائع، والمعرّفات النهائية (Q-31، Q-21).
- قدرات Paymob وMyFatoorah وTorod وM5AZN وLogesTechs في الدول الست، وقدرات Tabby وTamara (Q-33، Q-09).
- Odoo والقنوات الحيّة في الإنتاج، والمدرِج الإنتاجي للطابور (Q-34).
- أحجام الجداول وإصدار المحرك وRPO وRTO (M-05)، وتاريخ تغيير الإعدادات المالية (M-14)، وبقايا جداول المحافظ القديمة (M-15). لا سؤال لها في التقرير 11 (N3).
- تفاصيل الملاحق المقيّدة: لم تُفتح في هذا التقرير، وما يُذكر منها معرّفات فقط.

**خارج نطاق التدقيق:** الحكم القانوني والضريبي على المحتوى والفوترة والشحن المنظّم وقواعد الموافقات التسويقية، وأي تقدير لمدد أو جهد أو سعة فريق.

## سجل الأدلة الرئيسية

| الادعاء | الموضع | الحالة |
|---|---|---|
| المخطط غير قابل لإعادة البناء من المستودع (R-001) | [E01](./evidence/E01-backend-architecture-map.md) §6 F01؛ `lavkoo:.gitignore:53` | VERIFIED (الكود) / UNKNOWN (المخطط) |
| أساس الإنتاج غير معروف، وmain يزيد عليه 11 دمجاً | `lavkoo:docs/integrations/hotfix-2.md:28`؛ `lavkoo:.github/workflows/ci.yml:189`؛ `lavkoo@698aa1e7` | VERIFIED (الفرق) / UNKNOWN (النشر) |
| لا مرجع لسياق المتجر في أي نقطة دخول | `lavkoo:app/Models/BusinessSetting.php:13`؛ [E02](./evidence/E02-store-context-settings-currency-cache.md) §6 F02 | VERIFIED |
| المبالغ بلا عملة وتُفسَّر من إعداد عام | `lavkoo:app/Utils/currency.php:50-67` | VERIFIED (الأثر INFERRED) |
| التحويل الآلي في الدفع | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:415-423` | VERIFIED |
| تسعير تطبيق العميل بمعامل الصرف وبصفر خانات | `lavkooapp:lib/helper/price_converter.dart:65-78` | VERIFIED |
| الكود يقرّب بخانتين، و193 موضعاً | [التقرير 05](./05-gap-risk-register.md) R-036؛ [E02](./evidence/E02-store-context-settings-currency-cache.md) §6 F05 | VERIFIED (الكود) |
| رصيد المحفظة والنقاط عمودان عامان على users | `lavkoo:app/Models/User.php:95-96` | VERIFIED |
| دفتر البائع بمفتاح (البائع، نوع البائع) | `lavkoo:app/Services/Marketplace/VendorLedger.php:74-80` | VERIFIED |
| محفظة الإدارة بمفتاح ثابت وتقسيم السلة | `lavkoo:app/Utils/OrderManager.php:809-823`؛ `lavkoo:app/Utils/OrderManager.php:887-902` | VERIFIED |
| السلة تُحصَّل بدفعة واحدة لكل البائعين | `lavkoo:app/Http/Controllers/Customer/PaymentController.php:379-383` | VERIFIED |
| 85 موضعاً تقرأ المنتجات مباشرة، والكاش بلا بُعد سوق | `lavkoo:app/Models/Product.php:208-249`؛ `lavkoo:app/Traits/CacheManagerTrait.php:153-192` | VERIFIED |
| الفاتورة: رقم الطلب وإعدادات حية، بلا إشعار دائن | `lavkoo:resources/views/admin-views/order/invoice.blade.php:527-532`؛ `lavkoo:Modules/TaxModule/config/config.php:16` | VERIFIED |
| الصفحات القانونية جدول عالمي بلا دولة أو اعتماد | `lavkoo:database/migrations/2025_03_08_201607_create_business_pages_table.php:14-23` | VERIFIED |
| مفتاح صلاحية واحد للعملاء والمحافظ والتصدير | `lavkoo:routes/admin/routes.php:581-584` | VERIFIED |
| لا اختبار للسلة ودفتر المحفظة وrefund_requests، ولا staging | [التقرير 09](./09-test-strategy.md) القسم 1؛ [E15](./evidence/E15-tests-ci-quality-gates.md) §F11 | VERIFIED (غياب بالعدّ الساكن) |
| محتوى PR #12 إلى #16 في main عبر PR #17 | `lavkoo@cbf2cddb`؛ [التقرير 06](./06-git-lineage-legacy-prs.md) القسم 1 | VERIFIED |
| الوثائق تصف PR #12 إلى #16 بأنها مفتوحة | `lavkoo:docs/gcc/DECISIONS.md:60-61`؛ `lavkoo:docs/gcc/STATUS.md:35`؛ `lavkoo:docs/sales-channels/final-report.md:7-8` | VERIFIED (نص وثيقة) |
| تطبيق العميل ليس لقطة لأي commit في ksaapp98 | [التقرير 06](./06-git-lineage-legacy-prs.md) القسم 7؛ `lavkooapp@eedbbb8` | VERIFIED |
| معرّف iOS لا يطابق إعداد Firebase | `lavkooapp:ios/Runner/GoogleService-Info.plist:9-10`؛ `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414` | VERIFIED |
| النسخ المثبتة لا ترسل سياق متجر وتحلّل config بصرامة | `lavkooapp:lib/data/datasource/remote/dio/dio_client.dart:65-73` | VERIFIED (الكود)، INFERRED (أثر الفشل) |
| الهوية من الرمز وحده، ولا صلاحيات في تطبيق البائع | `lavkoo:app/Models/Seller.php:96-99`؛ `lavkooappseller:lib/features/menu/screens/more_screen.dart:163-165` | VERIFIED |
| Tabby وTamara غير موجودين في الكود | `lavkoo:docs/gcc/DECISIONS.md:15`؛ [التقرير 06](./06-git-lineage-legacy-prs.md) القسم 5 | VERIFIED (بالغياب) |
| commits عامة سابقة تحتوي نصوصاً غير منقّحة | `lavkoo@41201499`؛ `lavkoo@98e795b`؛ `lavkoo@f263e3d` | VERIFIED (وجود الـ commit)؛ محتواها في الملاحق المقيّدة |
| الشروط C1 إلى C10 ومسار الـ Go المشروط | [التقرير 12](./12-go-no-go-recommendation.md) القسم 2 | VERIFIED (نص التقرير) |
