# P0-E — قياسات البيئة المعزولة (قراءة فقط)

| الحقل | القيمة |
|---|---|
| مسار العمل (Workstream) | P0-E — Isolated environment measurements, read-only |
| النطاق | M-01 إلى M-16 من التقرير 07 (القسم 5)، والأسئلة Q-27 وQ-29 وQ-35 وQ-48 وQ-49 وQ-50 من التقرير 04 (D-08)، بقراءة ساكنة وتوثيق فقط |
| مرجع التفويض | lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:4 (موافقة الخيار D-01(B))، والقسم 4 في lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:67 |
| رؤوس الفروع المسجَّلة | القسم 0 |
| التاريخ | 2026-11-12 14:19 +03:00 |
| الحالة | **BLOCKED** — لا توجد نسخة معزولة مصرَّح بها في هذه الجلسة، لذلك جميع قياسات M-01 إلى M-16 محجوبة أو غير معروفة. P0-E غير مغلق، وإغلاقه قرار مالكي. |

## 0. رؤوس الفروع وحدود الوصول

- lavkoo، الفرع claude/exciting-shannon-4mh2ok = cd9ef550be2ed8830d78d4446030fe2947fc04cf، والفرع المحلي وorigin متطابقان عند الفحص (lavkoo@cd9ef550). **VERIFIED**
- lavkoo، الفرع main = 55e3dd0ea41069f64b0657fcbb4f8953710d2cd9 (lavkoo@55e3dd0e). **VERIFIED**
- سلسلة الفرع: التفويض عند lavkoo@c8b6ed3e، والتسليم السابق عند lavkoo@218b8aea. تقريرا P0-A وP0-C موجودان على الفرع ولم يُفحصا في هذا القسم. **VERIFIED**
- ksaapp98 (نسخة محلية للقراءة فقط)، الفرع main = 79f50bec1c419cae8bc1a6f4c314962d3b682ac0 (ksaapp98@79f50bec). **VERIFIED**
- lavkooapp وlavkooappseller: لم تُسجَّل رؤوسهما، لأن بنود M-01 إلى M-16 لا تعتمد عليهما. **VERIFIED**
- مستودع pharmacyksa98: لم تُستدعَ عليه أي أداة، والوصول إليه مرفوض لهذه الجلسة، وإعادة المحاولة محظورة بتعليمات المهمة. **BLOCKED**
- لم يُقدَّم أي نسخة معزولة مصرَّحة في هذه الجلسة. **BLOCKED**
- تحقق الاستشهادات: مطابقة blob الملفات المستشهد بها في نسخة العمل مع الرأس cd9ef550 لا تُظهر فروقاً. **VERIFIED**

## 1. اقتباس البنود M-01 إلى M-16 (سطر لكل بند)

- **M-01** — "مخطط الإنتاج بلا بيانات (DDL) للجداول المالية والتجارية: المفاتيح الأساسية والفرادة والفهارس و`AUTO_INCREMENT` وأنواع الأعمدة، ويشمل `orders` و`order_details` و`products` و`users` و`sellers` و`shops` وجداول المحافظ والدفاتر والتسويات والعمولات ومدفوعات MyFatoorah والمزوّدين والمخزون والقنوات وOdoo" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:272 **VERIFIED**
- **M-02** — "جدول `migrations` (الترحيلات المطبَّقة فعلاً، بما فيها `2026_05_07_000001` وهجرات التكاليف وOdoo)" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:273 **VERIFIED**
- **M-03** — "إعدادات `business_settings` المالية: `currency_model`، `system_default_currency`، `decimal_point_settings`، `business_mode`، `wallet_status`، `loyalty_point_status`، `loyalty_point_exchange_rate`، `loyalty_point_item_purchase_point`، `sales_commission`، `wallet_add_refund`، `unified_address_mode`؛ وحقول هوية النشاط تُقاس بالوجود والطول فقط" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:274 **VERIFIED**
- **M-04** — "جدول `currencies`: عدد الصفوف والرموز (هل يوجد رمز غير ISO مثل `KSA`)، وأسعار الصرف، والحالة، وتاريخ آخر تغيير" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:275 **VERIFIED**
- **M-05** — "أحجام الجداول وعدد الصفوف لكل جدول مالي، ونوع محرك قاعدة البيانات وإصداره، وإمكانية تعديل الجدول دون قفل طويل، وزمن النسخ الاحتياطي والاستعادة (RPO/RTO)، وتأخر النسخ المتماثل إن وُجد" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:276 **VERIFIED**
- **M-06** — "المحافظ: عدد المستخدمين ذوي الرصيد غير الصفري، وعدد الحسابات التي فيها فرق مع الصيغة، وعدد صفوف الدفتر بلا مالك، وعدد الحسابات بقيمة `NULL`" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:277 **VERIFIED**
- **M-07** — "الطلبات: عدد الطلبات حسب `seller_is`؛ عدد `order_group_id` التي تضم أكثر من بائع؛ عدد طلبات `pay_by_wallet` الملغاة بلا استرداد؛ أصغر وأكبر معرّف؛ عدد المعرّفات في فضاء POS؛ وجود مفتاح أساسي على `orders.id`" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:278 **VERIFIED**
- **M-08** — "المحافظ الإدارية والبائعية: عدد صفوف `admin_wallets`، وصفوف `seller_wallets` بـ `seller_id = 1` ومصدرها، ووجود بائع حقيقي بالمعرّف 1، وعدد `shops` بـ `author_type = 'admin'`" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:279 **VERIFIED**
- **M-09** — "الدفاتر والتسويات: عدد القيود والتسويات، وعدد العملات لكل سلسلة بائع، وعدد التسويات المختلطة العملات، وعدد السحوبات المعتمدة غير المجسَّرة، وعدد صفوف الإرجاع بلا قيد عكس" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:280 **VERIFIED**
- **M-10** — "المنتجات: عدد `products.shop_id` الفارغة، ومطابقتها مع `(added_by, user_id)`، وعدد منتجات الإدارة التي يقابل `user_id` بائعاً" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:281 **VERIFIED**
- **M-11** — "المخزون والمزوّدون: حالة `integration_settings` لكل مزوّد (مفعّل أو مطفأ، دون أسرار)، وعدد `provider_fulfillments` حسب الحالة وعمرها، وعدد `channel_stock_events` حسب الحالة وأقدم معلّق، وعدد `inventory_reservations` حسب الحالة، ووجود `product_batches` و`stock_movements` و`warehouses`" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:282 **VERIFIED**
- **M-12** — "Odoo: صفوف الاتصال والربط المعتمد، وعدد `odoo_posting_queue` حسب الحالة، وعدد الصفوف `may_have_posted`، وهل يوجد مُدرِج إنتاجي فعلاً (يُقاس بسجل تنفيذ المهام لا بالكود فقط)" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:283 **VERIFIED**
- **M-13** — "مفاتيح البيئة غير السرية: `ODOO_*`، و`OPERATIONAL_COSTS_*`، و`MYFATOORAH_*`، و`CACHE_DRIVER`، و`SESSION_DRIVER`، و`QUEUE_CONNECTION`، و`FULFILLMENT_CURRENCY`، و`MYFATOORAH_CURRENCY`" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:284 **VERIFIED**
- **M-14** — "تاريخ تغيير الإعدادات المالية (سجل التدقيق لـ `currency_model` والعملة الافتراضية وسعر الصرف ومعدل النقاط)، لأن الكود لا يحفظ هذا التاريخ" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:285 **VERIFIED**
- **M-15** — "أرصدة الأنظمة المتروكة: `customer_wallets` و`customer_wallet_histories` و`seller_wallet_histories` و`admin_wallet_histories` ومدى وجود بيانات حية فيها" — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:286 **VERIFIED**
- **M-16** — "المستودع القديم `pharmacyksa98` لتحديد أصل جداول الدفاتر والثيم والمحافظ وجداول الـ dump" (حالة التقرير 07: UNKNOWN، سبب: missing access pharmacyksa98) — lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:287 **VERIFIED**

أسئلة Discovery-02 المطلوبة (من التقرير 04):

- **Q-27** — "القيم الحية لإعدادات العملة والتقريب والنموذج التجاري والبلد والعنوان ولغات الواجهة ونسخ التطبيقين الدنيا" — lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:53 **VERIFIED**
- **Q-29** — "إعدادات التشغيل: الطابور والكاش والجلسة و`APP_URL`، وتشغيل المجدول وعامل الطابور، وحالة الوحدات المنشورة" — lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:55 **VERIFIED**
- **Q-35** — "عيّنة مطابقة من بيانات الإنتاج المجهّلة (عدّ فقط)" — lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:61 **VERIFIED**
- **Q-48** — "أحجام الجداول وإصدار محرك قاعدة البيانات وRPO/RTO (M-05)" — lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:81 **VERIFIED**
- **Q-49** — "تاريخ تغيير الإعدادات المالية والإثبات المطلوب (M-14)" — lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:82 **VERIFIED**
- **Q-50** — "بقايا جداول المحافظ القديمة وأثرها على المطابقة (M-15)" — lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:83 **VERIFIED**

## 2. ملخص الحالات والحاجة

| الرقم | ما يقيسه (مختصر) | الحالة | الحاجة (انظر القسم 7، ج) |
|---|---|---|---|
| M-01 | بنية DDL للجداول الإنتاجية | BLOCKED | B-01 أو B-02 |
| M-02 | جدول migrations (الترحيلات المطبَّقة) | BLOCKED | B-01 أو B-02 |
| M-03 | قيم business_settings المالية | BLOCKED | B-01 ثم B-02 (القيم غير السرية) |
| M-04 | جدول currencies: الرموز والأسعار والحالة | BLOCKED | B-01 ثم B-02 |
| M-05 | الأحجام والمحرك والإصدار وRPO/RTO | BLOCKED | B-01 وB-02 وB-03 |
| M-06 | أرصدة المحافظ والفروق مع الصيغة | BLOCKED | B-01 أو B-02، وB-08 للقرار المالي |
| M-07 | الطلبات: seller_is وorder_group_id وpay_by_wallet والمعرّفات | BLOCKED | B-01 أو B-02 |
| M-08 | المحافظ الإدارية والبائعية وseller_id = 1 | BLOCKED | B-01 ثم B-02 |
| M-09 | الدفاتر والتسويات | BLOCKED | B-01 وB-04، وB-08 للقرار المالي |
| M-10 | products.shop_id | BLOCKED | B-01 |
| M-11 | المخزون والمزوّدون | BLOCKED | B-01 وB-02 |
| M-12 | Odoo: صفوف الاتصال والترحيل والمُدرِج | BLOCKED | B-01 وB-02 وB-04 |
| M-13 | مفاتيح البيئة غير السرية | BLOCKED | B-02 أو B-03 |
| M-14 | تاريخ تغيير الإعدادات المالية | BLOCKED | B-02 أو B-04 أو B-07، ثم B-03 عند التعذّر |
| M-15 | أرصدة الأنظمة المتروكة | BLOCKED | B-01 أو B-02، وB-03 للمصير |
| M-16 | أصل الجداول من pharmacyksa98 | BLOCKED | B-06 (المالك) |

## 3. النسخ المعزولة: الأصل والتاريخ والتصنيف والعمر

| البند | القيمة |
|---|---|
| الأصل (origin) | NOT AVAILABLE — BLOCKED |
| تاريخ النسخة | NOT AVAILABLE — BLOCKED |
| التصنيف البيئي (environment designation) | NOT AVAILABLE — BLOCKED |
| العمر | NOT AVAILABLE — BLOCKED |
| الفروق المعروفة عن الإنتاج | NOT AVAILABLE — BLOCKED |

- لم تُقدَّم نسخة معزولة في هذه الجلسة، فلم يُنشأ ولم يُستعَد ولم يُصدَّر ولم يُبحث عن أي نسخة قاعدة بيانات. **VERIFIED**
- يذكر CLAUDE.md ملف قاعدة بيانات ابتدائياً يُستورد قبل الهجرات، ولم يُفتح؛ وهو مستبعد من التتبع بقاعدة `*.sql` في lavkoo:.gitignore:53، وتسجّل E09-F15 أنه غير موجود في المستودع (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E09-cart-checkout-order-pipeline.md:349). هذا الملف لا يُعدّ نسخة معزولة مصرَّحة بحسب القسم 1 من التفويض (lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:29). **BLOCKED**
- لا يوجد أي تاريخ أو عمر أو تصنيف مُستنتَج؛ كل قيمة فوق غير متاحة. **UNKNOWN**

## 4. المال والأرصدة والمعاملات: منهجية وفئات تجميعية فقط

- **الإطار:** القياس على نسخة معزولة مُعدّة مسبقاً فقط (B-01)، عدّ مجمّع لكل فئة، دون عرض معرّف حساب أو رصيد حقيقي أو فرق فردي أو تصدير. لا كتابة ولا تعديل ولا تشغيل لأي مهمة. **VERIFIED** (lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:73)
- **صيغة الرصيد المعلنة:** مجموع (credit + admin_bonus − debit) لكل عميل، كما يرد في سؤال E04 §7 البند 2 (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:364). **VERIFIED**
- **تحقق الصيغة من الكود:** لم يُعَد في هذه المهمة. التقرير 07 يصفها بأنها "مستنتجة من الكود" (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:331). **INFERRED**
- **الفئات التجميعية (تُعدّ كأعداد فقط):**
  - C1: حسابات يختلف رصيدها المخزَّن عن الصيغة (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:364). العدد: **BLOCKED**
  - C2: حسابات بقيمة NULL، وC3: صفوف دفتر بلا مالك (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:277). العدد: **BLOCKED**
  - C4: مجاميع تعبر العملات وتسويات مختلطة العملات (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:240). العدد: **BLOCKED**
  - C5: سحوبات معتمدة غير مجسَّرة (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:270). العدد: **BLOCKED**
  - C6: صفوف إرجاع بلا قيد عكس (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:280). العدد: **BLOCKED**
  - C7: طلبات دُفعت بالمحفظة وغير POS وأُلغيت بلا استرداد (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:342). العدد: **BLOCKED**
  - C8: صفوف مكافأة إحالة مكررة (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:368). العدد: **BLOCKED**
  - C9: أرصدة حيّة في جداول الأنظمة المتروكة (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:350). العدد: **BLOCKED**
- **حجم كل فئة اليوم:** غير معروف، لأن لا نسخة معزولة ولا وصول إنتاج. **UNKNOWN**
- **ممنوع في هذا التقرير:** أرصدة حقيقية، ومعرّفات عملاء، وفروق فردية، وصادرات، وأي استعلام تنفيذي. **VERIFIED** (lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:39)

## 5. تفصيل البنود M-01 إلى M-16

### M-01 — بنية DDL للجداول الإنتاجية

- **ما يقيسه:** بنية الجداول المالية والتجارية في الإنتاج بلا بيانات.
- **المصادر الموجودة الآن:**
  - يحوي database/migrations 336 ملف هجرة و128 استدعاء Schema::create، ولا يوجد أي إنشاء لأي جدول من جداول النواة المذكورة في M-01 (orders وorder_details وproducts وusers وsellers وshops وcurrencies وbusiness_settings وجداول المحافظ). **VERIFIED**
  - يشترط CLAUDE.md استيراد ملف قاعدة البيانات الابتدائي قبل الهجرات (lavkoo:CLAUDE.md:22). **VERIFIED**
  - يصف E03-F01 جداول بلا migration وأخرى ALTER-only، ولم تُعَد صياغة الأعداد هنا؛ يحسمها P0-C (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:590). **VERIFIED**
  - لا يمكن استنتاج DDL الإنتاج من الهجرات وحدها، لأن جداول النواة لا تُنشأ فيها. **INFERRED**
- **الحالة:** BLOCKED.
- **ما يحسمه:** قراءة بنية الإنتاج بلا بيانات، أو مقارنتها بنسخة معزولة مُعدّة مسبقاً.
- **الحاجة:** نسخة معزولة مُعدّة مسبقاً (B-01)، أو قراءة إنتاج معزولة (B-02).

### M-02 — جدول migrations

- **ما يقيسه:** الترحيلات المطبَّقة فعلاً، بما فيها `2026_05_07_000001`.
- **المصادر الموجودة الآن:**
  - الملف `2026_05_07_000001_update_all_decimal_columns_to_40_20.php` موجود في lavkoo:database/migrations. **VERIFIED**
  - تسعة ملفات هجرة تحمل بادئة تاريخ بعد 2026-11-12 (من 2026_11_13 إلى 2026_11_22)، منها lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php. حالة تطبيقها غير معروفة. **VERIFIED**
  - يسأل E13 §7 البند 7 إن كانت هجرة الدقة وهجرات التكاليف وOdoo مطبَّقة على الإنتاج (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:271). **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** محتوى جدول migrations في نسخة معزولة أو الإنتاج، ومقارنته بقائمة الملفات الـ 336.
- **الحاجة:** نسخة معزولة (B-01)، أو قراءة إنتاج معزولة (B-02).

### M-03 — إعدادات business_settings المالية

- **ما يقيسه:** قيم المفاتيح المالية، وحقول هوية النشاط تُقاس بالوجود والطول فقط.
- **المصادر الموجودة الآن:**
  - أسماء المفاتيح مستعملة في الكود: currency_model في 31 ملفاً، وsystem_default_currency في 31، وdecimal_point_settings في 30، وbusiness_mode في 25، وwallet_status في 49، وloyalty_point_status في 23، وloyalty_point_exchange_rate في 10، وloyalty_point_item_purchase_point في 6، وsales_commission في 23، وwallet_add_refund في 4، وunified_address_mode في 2 (عدّ ساكن في app وdatabase وModules وconfig وroutes وresources). **VERIFIED**
  - المبالغ مخزَّنة دون عملة، وتُستنتج وحدتها وقت القراءة من إعدادين عامين (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E02-store-context-settings-currency-cache.md:398). **VERIFIED**
  - القيم الإنتاجية لـ currency_model وsystem_default_currency وdecimal_point_settings وsales_commission غير معروفة (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:268). **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** نسخة مجهّلة أولاً (D-08 الخيار A)، ثم قراءة معزولة لأسماء المفاتيح وقيمها غير السرية لما لا يُستعاد من نسخة (D-08 الخيار B).
- **الحاجة:** B-01 ثم B-02.

### M-04 — جدول currencies

- **ما يقيسه:** عدد الصفوف والرموز (وجود رمز غير ISO)، وأسعار الصرف، والحالة، وتاريخ آخر تغيير.
- **المصادر الموجودة الآن:**
  - لا يوجد إنشاء لجدول currencies في database/migrations، ويرد اسمه في ملف هجرة واحد هو lavkoo:database/migrations/2021_06_03_104531_ex_rate_update.php. **VERIFIED**
  - لا يرد الرمز KSA في أي ملف تحت database/ (عدّ 0)، ويذكر E02-F13 رمزاً غير ISO في جدول العملات (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E02-store-context-settings-currency-cache.md:410). **VERIFIED**
  - يذكر E02-F19 أن الإنتاج بحسب توثيق المستودع يستعمل العملة الافتراضية KSA بسعر 1 (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E02-store-context-settings-currency-cache.md:416)، وهذا توثيق لا قياس. **INFERRED**
- **الحالة:** BLOCKED.
- **ما يحسمه:** عدّ الصفوف والرموز والأسعار على نسخة معزولة، ثم الإنتاج لإثبات القيم الحية.
- **الحاجة:** B-01 ثم B-02.

### M-05 — الأحجام والمحرك والإصدار وRPO/RTO

- **ما يقيسه:** أحجام الجداول، ونوع محرك القاعدة وإصداره، وزمن النسخ والاستعادة، وتأخر النسخ المتماثل.
- **المصادر الموجودة الآن:**
  - الافتراضي لاتصال قاعدة البيانات في الإعدادات هو mysql (lavkoo:config/database.php:18)، وهذا افتراض إعداد لا إصدار إنتاجي. **VERIFIED**
  - تُذكر إصدارات MySQL 8 وMariaDB 11.4.2 كأهداف مؤجّلة لا تُطبَّق في هذه المهمة (lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:62). **VERIFIED**
  - يحذّر MIG-09 من أن تعبئة الجداول الكبيرة قد تقفل الكتابة أو تؤخر النسخ المتماثل (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:301). **VERIFIED**
  - الإصدار الإنتاجي والأحجام والزمن الفعلي للنسخ والاستعادة وتأخر النسخ غير معروفة، وRPO/RTO لا مصدر له في المستودع. **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** الإصدار والأعداد على نسخة معزولة (B-01)، وRPO/RTO مستند معتمد من المالك (B-03)، وتأخر النسخ وزمن الاستعادة الفعلي من الإنتاج (B-02).
- **الحاجة:** B-01 وB-02 وB-03.

### M-06 — أرصدة المحافظ والفروق مع الصيغة

- **ما يقيسه:** عدد أصحاب الأرصدة غير الصفرية، والحسابات ذات الفرق، وصفوف الدفتر بلا مالك، والحسابات بقيمة NULL.
- **المصادر الموجودة الآن:**
  - سؤال E04 §7 البند 2 يطلب مطابقة Σ(credit + admin_bonus − debit) مع الرصيد لكل عميل (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:364). **VERIFIED**
  - الصيغة مستنتجة من الكود بحسب التقرير 07 (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:331)، ولم تُعَد مطابقتها بكود المحافظ هنا. **INFERRED**
  - عدد أصحاب الأرصدة غير الصفرية في customer_wallets يسأله E04 §7 البند 5 (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:367). القيمة غير معروفة. **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** عدّ الحسابات والفروق والصفوف بلا مالك على نسخة معزولة، وإثبات الحالة الإنتاجية بقراءة معزولة، ثم تصنيف الفروق وفق القسم 4 (C1 إلى C3).
- **الحاجة:** B-01 أو B-02، وB-08 للقرار المالي.

### M-07 — الطلبات والمعرّفات

- **ما يقيسه:** توزيع الطلبات حسب seller_is، وطلبات order_group_id متعددة البائعين، وطلبات pay_by_wallet الملغاة بلا استرداد، ونطاق المعرّفات، ووجود مفتاح أساسي على orders.id.
- **المصادر الموجودة الآن:**
  - المرجع seller_is يظهر في 103 ملفات، وorder_group_id في 11 ملفاً، وpay_by_wallet في 8 ملفات (عدّ ساكن في app وdatabase وModules وconfig). **VERIFIED**
  - يُخصّص التطبيق orders.id من تسلسل عام واحد، وتحسب مسارات POS المعرّف بمنطق منفصل، ورقم الفاتورة يساوي رقم الطلب (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:595). **VERIFIED**
  - لا استرداد تلقائي للمحفظة عند إلغاء طلب دُفع بالمحفظة وغير POS (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:342). **VERIFIED**
  - لا يُستنتج وجود مفتاح أساسي على orders.id من الهجرات، لأن جدول orders لا يُنشأ فيها. الهجرة lavkoo:database/migrations/2026_02_25_163505_add_indexes_to_orders_table.php تُضيف فهارس بحسب اسمها. **INFERRED**
  - أصغر وأكبر معرّف، وعدد المعرّفات في فضاء POS، وتوزيع seller_is بالأعداد: غير معروفة. **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** عدّ الطلبات والمعرّفات على نسخة معزولة، ومفتاح orders.id من بنية الجدول.
- **الحاجة:** B-01 أو B-02.

### M-08 — المحافظ الإدارية والبائعية وseller_id = 1

- **ما يقيسه:** صفوف admin_wallets وseller_wallets بـ seller_id = 1 ومصدرها، ووجود بائع حقيقي بالمعرّف 1، وعدد shops بـ author_type = 'admin'.
- **المصادر الموجودة الآن:**
  - تعدّل الهجرات أعمدة المال في admin_wallets (lavkoo:database/migrations/2026_01_22_153232_update_admin_wallets_amount_columns.php:30) وseller_wallets (lavkoo:database/migrations/2026_01_22_164826_update_seller_wallets_money_columns.php:30)، ولا تنشئهما. **VERIFIED**
  - يفترض الكود seller_id = 1 مع seller_is = 'admin' في تقارير الطلبات (lavkoo:app/Http/Controllers/Admin/OrderReportController.php:64) والمعاملات (lavkoo:app/Http/Controllers/Admin/TransactionReportController.php:351)، ويحدّد ChannelOrderMaterializer قيمة seller_id = 1 في موضع إنشاء البيانات (lavkoo:app/Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php:142). **VERIFIED**
  - الأرصدة التاريخية في admin_wallets وseller_wallets سؤال مفتوح (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:270). **VERIFIED**
  - عدد الصفوف، ومصدر seller_wallets بـ seller_id = 1، ووجود بائع حقيقي بالمعرّف 1، وعدد shops بـ author_type = 'admin': غير معروفة. **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** عدّ الصفوف وقيم seller_id ومصادرها على نسخة معزولة، وتأكيد البائع المعرّف 1 من الإنتاج.
- **الحاجة:** B-01 ثم B-02.

### M-09 — الدفاتر والتسويات

- **ما يقيسه:** القيود والتسويات، والعملات لكل سلسلة بائع، والتسويات المختلطة، والسحوبات غير المجسَّرة، وصفوف الإرجاع بلا قيد عكس.
- **المصادر الموجودة الآن:**
  - مفتاح سلسلة رصيد دفتر البائع هو (seller_id, seller_is) فقط، والمجاميع تعبر العملات، وعملة التسوية هي عملة أول قيد (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:240). **VERIFIED**
  - لا يوجد إنشاء لأي جدول دفتر أو تسوية في database/migrations (عدّ 0)، وE03-F01 يذكر جداول دفاتر بلا migration (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:590). **VERIFIED**
  - مهمة التسوية المجدولة marketplace:settle بخيار release تعمل يومياً عند 02:00 (lavkoo:app/Console/ScheduleDefinition.php:35). **VERIFIED**
  - الأعداد الإنتاجية للقيود والتسويات والعملات المختلطة والسحوبات والإرجاعات: غير معروفة. **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** عدّ مجمّع على نسخة معزولة (B-01)، وإثبات تشغيل مهمة التسوية من سجل التنفيذ (B-04)، والقرار المالي للفئات C4 إلى C6 (B-08).
- **الحاجة:** B-01 وB-04 وB-08.

### M-10 — products.shop_id

- **ما يقيسه:** الصفوف الفارغة في products.shop_id، ومطابقتها مع (added_by, user_id)، ومنتجات الإدارة التي يطابق user_id بائعاً.
- **المصادر الموجودة الآن:**
  - أُضيف العمود بهجرة lavkoo:database/migrations/2025_09_08_174517_add_shop_id_to_products_table.php:24. **VERIFIED**
  - قيمة shop_id غير موثوقة للصفوف القديمة، لأن حلقة الـ backfill تُحدّث كل الصفوف إلى متجر واحد، ودلالة المتجر الداخلي متباينة (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:599). **VERIFIED**
  - الأعداد الإنتاجية غير معروفة، ويسأل E03 §7 البند 5 عن أثر الحلقة (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:615). **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** مطابقة الأعداد على نسخة معزولة.
- **الحاجة:** B-01.

### M-11 — المخزون والمزوّدون

- **ما يقيسه:** حالة integration_settings لكل مزوّد، وحالات provider_fulfillments وعمرها، وchannel_stock_events وأقدم معلّق، وinventory_reservations، ووجود product_batches وstock_movements وwarehouses.
- **المصادر الموجودة الآن:**
  - integration_settings وprovider_fulfillments تُنشآن في lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:27 و lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:40. **VERIFIED**
  - channel_stock_events تُنشأ في lavkoo:database/migrations/2026_11_13_100000_create_channel_stock_events_table.php:36، وinventory_reservations في lavkoo:database/migrations/2026_11_07_100200_create_inventory_and_routing_tables.php:85. **VERIFIED**
  - لا يوجد إنشاء لـ product_batches أو stock_movements أو warehouses في database/migrations، ويرد اسم warehouses مرة في lavkoo:database/migrations/2026_11_07_100000_create_address_master_tables.php:12 دون إنشاء جدول. **VERIFIED**
  - يسجّل E11-F09 هذه الجداول بلا ترحيل في المستودع (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E11-inventory-fulfillment-shipping.md:412). **VERIFIED**
  - يشير الكود إلى stock_movements في 9 ملفات، وproduct_batches في 5، وwarehouses في 14 (عدّ ساكن في app وModules وroutes). **VERIFIED**
  - مفاتيح تفعيل المزوّدات FULFILLMENT_INTEGRATIONS_ENABLED وFULFILLMENT_EXTERNAL_WRITES_ENABLED افتراضياً false (lavkoo:config/fulfillment.php:26 وlavkoo:config/fulfillment.php:34). **VERIFIED**
  - حالة كل مزوّد في الإنتاج، والأعداد والأعمار وأقدم معلّق: غير معروفة. **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** أعداد الحالات والأعمار على نسخة معزولة (B-01)، وحالة التفعيل من الإنتاج بأسماء المفاتيح وقيمها غير السرية فقط (B-02).
- **الحاجة:** B-01 وB-02.

### M-12 — Odoo: الاتصال والترحيل والمُدرِج

- **ما يقيسه:** صفوف الاتصال والربط، وعدد odoo_posting_queue حسب الحالة، وعدد may_have_posted، ووجود مُدرِج إنتاجي فعلي يُقاس بسجل تنفيذ المهام.
- **المصادر الموجودة الآن:**
  - جداول odoo_account_mappings وodoo_posting_queue وodoo_reconciliations تُنشأ في lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:23 و lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:67 و lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:140. **VERIFIED**
  - نموذج الترحيل يستعمل الجدول odoo_posting_queue (lavkoo:app/Models/OdooPosting.php:63)، والحالة may_have_posted معرّفة في lavkoo:app/Models/OdooPosting.php:41. **VERIFIED**
  - تكامل Odoo ومفتاح الترحيل النهائي مطفآن افتراضياً (lavkoo:config/odoo.php:28 وlavkoo:config/odoo.php:42). **VERIFIED**
  - يُحقن المُدرِج OdooPostingRun في متحكّم الإدارة OdooOutboxController (lavkoo:app/Http/Controllers/Admin/Accounting/OdooOutboxController.php:37)، ولا يظهر أي إدخال لـ Odoo في جدول المهام المجدولة في lavkoo:app/Console/ScheduleDefinition.php (بحث ساكن). **VERIFIED**
  - يخرج أمر odoo:reconcile دون عمل إذا كان التكامل مطفأً (lavkoo:app/Console/Commands/OdooReconcileCommand.php:35). **VERIFIED**
  - هل توجد صفوف اتصال وربط معتمد وصفوف في odoo_posting_queue في الإنتاج: غير معروف (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:267). **UNKNOWN**
  - وجود مُدرِج إنتاجي فعلي يُقاس بسجل تنفيذ المهام، وهذا السجل غير متاح. **BLOCKED**
- **الحالة:** BLOCKED.
- **ما يحسمه:** عدّ الصفوف حسب الحالة وعدد may_have_posted على نسخة معزولة إن وُجدت (B-01)، وحالة التفعيل من الإنتاج (B-02)، وسجل تنفيذ المهام (B-04).
- **الحاجة:** B-01 وB-02 وB-04.

### M-13 — مفاتيح البيئة غير السرية

- **ما يقيسه:** القيم الإنتاجية لمفاتيح التكامل والتكلفة والذاكرة والجلسة والطابور والعملة، بأسمائها وقيمها غير السرية فقط.
- **المصادر الموجودة الآن (أسماء ومفاتيح افتراضية فقط):**
  - لا يرد في lavkoo:.env.example أي اسم يبدأ بـ ODOO_ أو OPERATIONAL_COSTS_ أو MYFATOORAH_ (عدّ 0)، بينما تقرأها ملفات الإعداد عبر env() (lavkoo:config/odoo.php:28، وlavkoo:config/operational_costs.php:22، وlavkoo:config/myfatoorah.php:33). **VERIFIED**
  - الافتراضيات غير السرية: CACHE_DRIVER = file (lavkoo:config/cache.php:21)، وSESSION_DRIVER = file (lavkoo:config/session.php:21)، وQUEUE_CONNECTION = sync (lavkoo:config/queue.php:16)، وFULFILLMENT_CURRENCY = SAR (lavkoo:config/fulfillment.php:59). **VERIFIED**
  - يشمل lavkoo:.env.example أسماء FULFILLMENT_CURRENCY وFULFILLMENT_QUEUE_CONNECTION وQUEUE_RETRY_AFTER. **VERIFIED**
  - إن لم يُضبط QUEUE_CONNECTION في الإنتاج فالافتراضي sync، أي تنفيذ متزامن؛ القيمة الإنتاجية غير معروفة. **INFERRED**
  - القيم الإنتاجية لكل المفاتيح غير معروفة (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:266). **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** أسماء المفاتيح وقيمها غير السرية من بيئة الإنتاج (B-02)، أو إفادة كتابية من المالك بالقيم (B-03). إعدادات النسخة المعزولة لا تمثّل الإنتاج.
- **الحاجة:** B-02 أو B-03.

### M-14 — تاريخ تغيير الإعدادات المالية

- **ما يقيسه:** تاريخ تغيير currency_model والعملة الافتراضية وسعر الصرف ومعدل النقاط.
- **المصادر الموجودة الآن:**
  - لا يرد بين أسماء الجداول التي تحمل history أو log أو audit في الهجرات أي جدول لسجل تغييرات الإعدادات (بحث ساكن في database/migrations). **VERIFIED**
  - يرى التقرير 07 أن الكود لا يحفظ هذا التاريخ (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:285)، ويسأل E04 §7 البند 1 عن تغيّر القيم عبر الزمن (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:363). **VERIFIED**
  - الكود لا يحفظ تاريخ تغيير هذه الإعدادات، استناداً إلى غياب جدول سجل لها (بحث غير شامل). **INFERRED**
  - سجلات التغيير في الإنتاج أو النسخ السابقة غير متاحة، وتاريخ التغيير غير معروف. **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** سجل تغييرات من الإنتاج أو نسخة سابقة موافَق عليها، مع تاريخ القياس ونوع الإثبات (Q-49 الخيار أ). وإن تعذّر ذلك فقرار مالي مكتوب من المالك (Q-49 الخيار ب).
- **الحاجة:** B-02 أو B-04 أو B-07 (استعادة نسخة سابقة)، ثم B-03 عند التعذّر.

### M-15 — أرصدة الأنظمة المتروكة

- **ما يقيسه:** وجود بيانات حية في جداول المحافظ القديمة وعددها.
- **المصادر الموجودة الآن:**
  - تعدّل الهجرات أعمدة customer_wallets (lavkoo:database/migrations/2026_01_22_153551_update_customer_wallets_amount_columns.php:25)، وcustomer_wallet_histories (lavkoo:database/migrations/2026_01_22_153805_update_customer_wallet_histories_amount_column.php:24)، وseller_wallet_histories (lavkoo:database/migrations/2026_01_22_164958_update_seller_wallet_histories_amount_column.php:24)، ولا تنشئها. **VERIFIED**
  - نظام محفظة قديم متروك بمسار كود حيّ؛ الكود مُثبت والبيانات غير معروفة (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:350). **VERIFIED**
  - أرصدة تاريخية في customer_wallets قد تكون رصيداً ثانياً خارج التسوية (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:306). **VERIFIED**
  - وجود صفوف حية وعددها: غير معروف. **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** وجود الجداول وعدد الصفوف الحية فقط دون قيم، من نسخة معزولة (B-01) أو الإنتاج (B-02). ثم قرار المالك بمصير الصفوف بعد المطابقة (B-03 وB-08).
- **الحاجة:** B-01 أو B-02، وB-03.

### M-16 — أصل الجداول من pharmacyksa98

- **ما يقيسه:** أصل جداول الدفاتر والثيم والمحافظ وجداول الـ dump.
- **المصادر الموجودة الآن:**
  - pharmacyksa98: الوصول مرفوض لهذه الجلسة، ولم تُستدعَ أي أداة عليه. **BLOCKED**
  - ksaapp98@79f50bec (نسخة محلية للقراءة فقط): لا يوجد ملف هجرة تحت database/migrations في الرأس، ولا يرد اسما wallet_histories أو ledger في ملفات app (عدّ 0). **VERIFIED**
  - يسأل E03 §7 البند 3 عن أصل جداول الثيم والدفاتر (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:615). **VERIFIED**
  - أصل الجداول غير معروف من أي مصدر متاح. **UNKNOWN**
- **الحالة:** BLOCKED.
- **ما يحسمه:** قراءة المستودع القديم بعد إعادة منح الوصول من المالك (B-06)، أو تصدير معتمد من المالك لقائمة الجداول وأصلها.
- **الحاجة:** المالك (B-06).

## 6. الأسئلة Q-27 وQ-29 وQ-35 وQ-48 وQ-49 وQ-50

- **Q-27:** أسماء المفاتيح موجودة في الكود (انظر M-03). **VERIFIED**. القيم الحية غير معروفة. **UNKNOWN**. تحتاج B-02 بعد B-01، وفق توصية D-08 (أ أولاً ثم ب): lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:172. **BLOCKED**. نسخ التطبيقين الدنيا لم تُقَس هنا. **UNKNOWN**
- **Q-29:** الافتراضيات في الإعدادات موجودة (انظر M-13). **VERIFIED**. الجدولة المرتبطة بالتكاليف مشروطة بمفتاح (lavkoo:app/Console/ScheduleDefinition.php:118). **VERIFIED**. الوحدات المسجّلة في الملف المتتبَّع مفعّلة: Blog وGateways وTaxModule وAI وAuction (lavkoo:modules_statuses.json). **VERIFIED**، وهذه حالة المستودع لا الإنتاج. الحالة الإنتاجية للطابور والمجدول والعامل غير معروفة. **UNKNOWN**. قراءة crontab والمشرف والعامل تحتاج B-05 وB-02. **BLOCKED**
- **Q-35:** المطابقة تُجرى على نسخة مجهّلة وتقتصر على العدّ، والنتائج أعداد لا قيم (lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:61). **VERIFIED**. لا نسخة متاحة. **BLOCKED** (B-01)
- **Q-48:** تعريف M-05 موجود في القسم 5. **VERIFIED**. الإصدار والأحجام وRPO/RTO غير معروفة. **UNKNOWN**. تحتاج B-01 وB-02، وRPO/RTO من B-03. **BLOCKED**
- **Q-49:** الكود لا يحفظ تاريخ تغيير الإعدادات (انظر M-14). **VERIFIED**. الإثبات المطلوب غير متاح. **BLOCKED** (B-02 أو B-04 أو B-07، وB-03 عند التعذّر)
- **Q-50:** بقايا المحافظ القديمة في MIG-13 وE04-F18 (انظر M-15). **VERIFIED**. وجود الصفوف الحية غير معروف. **UNKNOWN**. تحتاج B-01 أو B-02، ثم قرار المالك (B-03). **BLOCKED**

## حدود هذا التقرير وعناصر BLOCKED / UNKNOWN

### أ. حدود القياس

- التحليل ساكن وقراءة فقط على الرأس cd9ef550؛ لم يُشغَّل أي اختبار أو بناء أو أمر على قاعدة بيانات أو إنتاج أو خدمة خارجية، ولم تُقرأ ملفات البيئة السرية ولا المحتوى المقيّد ولا محتوى قاعدة البيانات الابتدائية. **VERIFIED** (سجل الأوامر في هذه الجلسة)
- لم يُعدَّل أي ملف في نسخة العمل lavkoo، ولم يُنفَّذ أي أمر يغيّر حالة المستودع المحلي. **VERIFIED** (سجل الأوامر في هذه الجلسة)
- لم تُفحص مستودعات lavkooapp وlavkooappseller في هذا القسم، ولا تعتمد بنود M-01 إلى M-16 عليهما. **VERIFIED**
- النتائج تُنسب إلى الرأس cd9ef550 فقط؛ أي commit لاحق على الفرع غير مشمول. **VERIFIED**
- P0-A وP0-C لا يُغلقان بهذا التقرير، ولا يُغلق P0-E؛ الإغلاق قرار مالكي منفصل. **VERIFIED** (lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:112)
- لا يغيّر هذا التقرير G0 ولا أي Go/No-Go. **VERIFIED** (lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:87)

### ب. عناصر UNKNOWN

1. بنية DDL الإنتاجية وجداول النواة غير المُنشأة في الهجرات (M-01). **UNKNOWN**
2. الترحيلات المطبَّقة فعلاً، وما إذا كانت الملفات التسعة المؤرَّخة بعد 2026-11-12 قد طُبِّقت ومتى. **UNKNOWN**
3. القيم الإنتاجية للإعدادات المالية والعملة والأسعار، والحد الأدنى لنسخ التطبيقين (M-03 وM-04 وQ-27). **UNKNOWN**
4. إصدار المحرك والأحجام وتأخر النسخ المتماثل وRPO/RTO (M-05 وQ-48). **UNKNOWN**
5. أرصدة المحافظ والفروق وعدد الحسابات بقيمة NULL (M-06 وفئات C1 إلى C3). **UNKNOWN**
6. توزيع الطلبات والمعرّفات وعدد الطلبات الملغاة بلا استرداد (M-07 وفئة C7). **UNKNOWN**
7. صفوف الإدارة والبائع المعرّف 1 ومصدر seller_wallets (M-08). **UNKNOWN**
8. القيود والتسويات والسحوبات غير المجسَّرة والإرجاعات (M-09 وفئات C4 إلى C6). **UNKNOWN**
9. صفوف المنتجات الفارغة في shop_id (M-10). **UNKNOWN**
10. حالات المزوّدين وصفوف المخزون وأعمارها (M-11). **UNKNOWN**
11. صفوف Odoo ومُدرِج الإنتاج وسجل تشغيله (M-12). **UNKNOWN**
12. القيم الإنتاجية للمفاتيح غير السرية، وحالة الطابور والمجدول والعامل (M-13 وQ-29). **UNKNOWN**
13. تاريخ تغيير الإعدادات المالية وسجلاتها (M-14 وQ-49). **UNKNOWN**
14. الصفوف الحية في جداول المحافظ القديمة (M-15 وQ-50). **UNKNOWN**
15. أصل جداول الدفاتر والثيم والمحافظ وجداول الـ dump (M-16). **UNKNOWN**
16. سبب ترقيم تسعة ملفات هجرة بتاريخ لاحق لتاريخ هذا التقرير. **UNKNOWN**
17. بيانات النسخة المعزولة كلها (الأصل والتاريخ والتصنيف والعمر والفروق). **UNKNOWN**

### ج. طلبات الموافقة المحددة (BLOCKED)

| الرقم | ما يُفتح | الموافقة المطلوبة بالضبط | البنود |
|---|---|---|---|
| B-01 | نسخة معزولة مجهّلة مُعدّة مسبقاً (غير متوفرة) | إقرار كتابي من المالك أو المشغّل بوجود نسخة مُعدّة مسبقاً، مع الأصل وتاريخ الإعداد والتصنيف البيئي والعمر والفروق المعروفة عن الإنتاج، وتصريح قراءة فقط لأعداد وبنية، بلا قيم شخصية أو سرية. **BLOCKED** | M-01 إلى M-11، M-15، Q-35، Q-48 (الإصدار والأحجام) |
| B-02 | قراءة إنتاج معزولة (D-08 الخيار B) | موافقة كتابية محددة لمشغّل محدد ونافذة زمنية، ومخرجاتها مقصورة على أسماء المفاتيح والقيم غير السرية وأعداد الصفوف والإصدار، بلا أي قيمة سرية أو أطوال رموز أو بيانات أفراد. **BLOCKED** | M-02 وM-03 وM-04 وM-05 وM-07 وM-11 وM-12 وM-13 وM-14، وQ-27 وQ-29 وQ-48 |
| B-03 | قرار أو مستند من المالك | مستند RPO/RTO المعتمد، وقرار مصير الصفوف بعد المطابقة، وإفادة كتابية بالقيم الإنتاجية إن تعذّر الإثبات الآلي، وقرار المعدّل التاريخي عند تعذّر الإثبات (Q-49 الخيار ب). **BLOCKED** | M-05 وM-13 وM-14 وM-15، وQ-49 وQ-50 |
| B-04 | سجلات تنفيذ المهام | موافقة منفصلة لقراءة سجلات تنفيذ مُدرِج Odoo ومهمة التسوية وأوامر التكلفة، مع بروتوكول تنقيح يمنع ظهور بيانات أفراد أو قيم سرية. **BLOCKED** | M-09 وM-12 وM-14 |
| B-05 | وصول آلة جديد للقراءة فقط | موافقة منفصلة لقراءة إعداد المجدول والعامل والمشرف على الخادم الإنتاجي، بلا تشغيل ولا تعديل. **BLOCKED** | Q-29، والجزء التشغيلي من M-13 |
| B-06 | وصول قراءة إلى pharmacyksa98 | إعادة منح الوصول من المالك لهذه الجلسة، أو تصدير معتمد من المالك لقائمة أسماء الجداول وأصلها؛ إعادة المحاولة بلا إذن محظورة. **BLOCKED** | M-16 |
| B-07 | استعادة نسخة احتياطية موجودة | موافقة منفصلة لاستعادة نسخة سابقة موجودة لقراءة تاريخ الإعدادات. إنشاء نسخة احتياطية جديدة غير مسموح في هذه المهمة. **BLOCKED** | M-14، وQ-49 |
| B-08 | مطابقة مالية على نسخة معتمدة | عدّ مجمّع للفئات C1 إلى C9 على نسخة معتمدة من B-01، ورأي مالي مكتوب من المالك لتفسير الفروق. **BLOCKED** | M-06 وM-09 وM-15، وQ-49 وQ-50 |

- أي مرحلة أخرى (P0-B وP0-D وP0-F وP0-G وG0 وGCC-20) تحتاج موافقة منفصلة، وD-01(B) لا يمنحها. **VERIFIED** (lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:41)
- الثغرات الأمنية المذكورة في التقارير السابقة خارج نطاق P0-E، ولا يُعالَج أي منها هنا. **VERIFIED** (lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:40)

### د. ملاحظات تصحيح مقترحة (نص فقط، لم تُطبَّق على الوثائق المعتمدة)

- يستشهد التقرير 07 لـ M-08 بـ "E13 Q4" (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:279). البند الخاص بالمحافظ الإدارية والبائعية في E13 §7 هو Q6 (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:270)، أما Q4 فيتعلق بقيم العملة الإنتاجية (lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:268). **VERIFIED** — الاقتراح: استبدال "E13 Q4" بـ "E13 Q6" في الصف M-08.
- يصنّف التقرير 07 حالة M-16 بـ UNKNOWN، بينما يصنّفه هذا التقرير BLOCKED لأن المحاولة نفسها ممنوعة بحسب تعريف الحالات. **VERIFIED** — الاقتراح: توضيح سبب الحالة في الصف دون تغيير معناه.
- الملفات التسعة المؤرَّخة بعد 2026-11-12 تحتاج تفسيراً في تقرير P0-C. **VERIFIED**

## سجل الأدلة

| # | الدليل | ما يُثبته | الوسم |
|---|---|---|---|
| E1 | lavkoo@cd9ef550 (رأس الفرع عند الفحص) | الرأس المحلي وorigin متطابقان | VERIFIED |
| E2 | lavkoo@55e3dd0e (رأس main) | رأس الفرع الرئيسي | VERIFIED |
| E3 | ksaapp98@79f50bec (نسخة محلية للقراءة فقط) | الرأس وغياب ملفات الهجرة فيه | VERIFIED |
| E4 | lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:4 | موافقة D-01(B) | VERIFIED |
| E5 | lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:29 | الاستعمال المسموح للنسخ المعزولة | VERIFIED |
| E6 | lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:39-41 | الممنوعات | VERIFIED |
| E7 | lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:62 | المحركات المؤجّلة | VERIFIED |
| E8 | lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:67-74 | بنود القسم 4 (P0-E) | VERIFIED |
| E9 | lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:87 و lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:112 | لا تغيير في G0 ولا إغلاق | VERIFIED |
| E10 | lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:212 | stock_movements بلا ترحيل | VERIFIED |
| E11 | lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:272-287 | بنود M-01 إلى M-16 | VERIFIED |
| E12 | lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:301 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:306 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:331 | MIG-09 وMIG-13 والصيغة | VERIFIED |
| E13 | lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:53 و lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:55 و lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:61 و lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:81-83 | أسئلة Q-27 وQ-29 وQ-35 وQ-48 وQ-49 وQ-50 | VERIFIED |
| E14 | lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:169-172 | خيارات D-08 والتوصية | VERIFIED |
| E15 | lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E02-store-context-settings-currency-cache.md:398 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E02-store-context-settings-currency-cache.md:410 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E02-store-context-settings-currency-cache.md:416 | المال والعملة وE02-F19 | VERIFIED |
| E16 | lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:590 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:595 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:599 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:615 | جداول بلا migration والمعرّفات وshop_id والأسئلة | VERIFIED |
| E17 | lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:342 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:350 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:363 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:364 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:367 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E04-customer-identity-wallet-loyalty.md:368 | استرداد المحفظة والمحافظ القديمة والأسئلة | VERIFIED |
| E18 | lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E09-cart-checkout-order-pipeline.md:349 | ملف SQL الابتدائي غير موجود في المستودع | VERIFIED |
| E19 | lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E11-inventory-fulfillment-shipping.md:412 | جداول المخزون بلا ترحيل | VERIFIED |
| E20 | lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:240 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:266 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:267 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:268 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:270 و lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E13-finance-odoo-costs-settlements.md:271 | الدفاتر وأسئلة §7 | VERIFIED |
| E21 | lavkoo:CLAUDE.md:22 | ترتيب الاستيراد قبل الهجرات | VERIFIED |
| E22 | lavkoo:.gitignore:53 | استبعاد *.sql من التتبع | VERIFIED |
| E23 | lavkoo:.env.example (أسماء المفاتيح فقط، بلا قيم) | غياب بادئات ODOO_ وOPERATIONAL_COSTS_ وMYFATOORAH_ | VERIFIED |
| E24 | lavkoo:config/database.php:18 و lavkoo:config/cache.php:21 و lavkoo:config/session.php:21 و lavkoo:config/queue.php:16 | افتراضيات غير سرية | VERIFIED |
| E25 | lavkoo:config/odoo.php:28 و lavkoo:config/odoo.php:42 و lavkoo:config/myfatoorah.php:33 و lavkoo:config/operational_costs.php:22 و lavkoo:config/fulfillment.php:26 و lavkoo:config/fulfillment.php:34 و lavkoo:config/fulfillment.php:59 | أعلام التفعيل والعملة الافتراضية | VERIFIED |
| E26 | lavkoo:app/Console/ScheduleDefinition.php:35 و lavkoo:app/Console/ScheduleDefinition.php:118 | مهمة التسوية والجدولة المشروطة | VERIFIED |
| E27 | lavkoo:app/Console/Commands/OdooReconcileCommand.php:35 | خروج الأمر عند إطفاء التكامل | VERIFIED |
| E28 | lavkoo:app/Models/OdooPosting.php:41 و lavkoo:app/Models/OdooPosting.php:63 | حالة may_have_posted والجدول | VERIFIED |
| E29 | lavkoo:app/Http/Controllers/Admin/Accounting/OdooOutboxController.php:37 | حقن المُدرِج في متحكم الإدارة | VERIFIED |
| E30 | lavkoo:app/Http/Controllers/Admin/OrderReportController.php:64 و lavkoo:app/Http/Controllers/Admin/TransactionReportController.php:351 و lavkoo:app/Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php:142 | seller_id = 1 في الكود | VERIFIED |
| E31 | lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php | وجود الهجرة | VERIFIED |
| E32 | lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:23 و lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:67 و lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:140 | جداول Odoo | VERIFIED |
| E33 | lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:27 و lavkoo:database/migrations/2026_11_05_100000_create_provider_fulfillment_tables.php:40 | integration_settings وprovider_fulfillments | VERIFIED |
| E34 | lavkoo:database/migrations/2026_11_13_100000_create_channel_stock_events_table.php:36 و lavkoo:database/migrations/2026_11_07_100200_create_inventory_and_routing_tables.php:85 | channel_stock_events وinventory_reservations | VERIFIED |
| E35 | lavkoo:database/migrations/2026_11_07_100000_create_address_master_tables.php:12 | ورود اسم warehouses دون إنشاء جدول | VERIFIED |
| E36 | lavkoo:database/migrations/2025_09_08_174517_add_shop_id_to_products_table.php:24 | إضافة shop_id | VERIFIED |
| E37 | lavkoo:database/migrations/2026_01_22_153232_update_admin_wallets_amount_columns.php:30 و lavkoo:database/migrations/2026_01_22_164826_update_seller_wallets_money_columns.php:30 | تعديل أعمدة المحافظ الإدارية والبائعية | VERIFIED |
| E38 | lavkoo:database/migrations/2026_01_22_153551_update_customer_wallets_amount_columns.php:25 و lavkoo:database/migrations/2026_01_22_153805_update_customer_wallet_histories_amount_column.php:24 و lavkoo:database/migrations/2026_01_22_164958_update_seller_wallet_histories_amount_column.php:24 | تعديل أعمدة المحافظ القديمة | VERIFIED |
| E39 | lavkoo:database/migrations/2026_02_25_163505_add_indexes_to_orders_table.php:25 | هجرة فهارس الطلبات | VERIFIED |
| E40 | lavkoo:database/migrations/2021_06_03_104531_ex_rate_update.php | ورود اسم currencies | VERIFIED |
| E41 | lavkoo:modules_statuses.json | حالة الوحدات في الملف المتتبَّع | VERIFIED |
| E42 | عدّ ساكن على الرأس cd9ef550 (git grep وls-tree، بلا تشغيل كود) | أعداد الملفات والمطابقات الواردة في الأقسام 4 و5 | VERIFIED |
| E43 | مطابقة blob للملفات المستشهد بها بين نسخة العمل والرأس cd9ef550 | لا فروق | VERIFIED |
