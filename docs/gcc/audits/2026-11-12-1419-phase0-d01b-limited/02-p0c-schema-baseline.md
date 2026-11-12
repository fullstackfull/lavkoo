# P0-C: خط أساس المخطط وDDL (قراءة فقط)

> **الحالة:** توثيق ثابت مكتمل كنقطة توثيق. فحص المخطط المعزول **BLOCKED**. P0-C **غير مُغلق**، والقرار للمالك وحده. VERIFIED

## الترويسة

| البند | القيمة | الوسم |
|---|---|---|
| مسار العمل | P0-C، بنية المخطط وخط أساس DDL، قراءة فقط (D-01(B) المحدود) | VERIFIED |
| النطاق | مستودع `lavkoo` فقط: جرد ثابت لملفات الترحيل وجداول النماذج ومراجع SQL. لا اتصال بأي قاعدة بيانات، ولا تشغيل ترحيل، ولا اختبار، ولا بناء، ولا تعديل لأي ملف | VERIFIED |
| مرجع التفويض | `lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:56-65` (القسم 3)، و`lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:36` (المنع)، و`lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:62` (تأجيل المحركين) | VERIFIED |
| رأس فرع التدقيق `claude/exciting-shannon-4mh2ok` | `lavkoo@c8b6ed3e434ba39d28a2fd5f01a9159b925bb268` (على الخادم عبر ls-remote، ومطابق لمرجع الفرع المحلي) | VERIFIED |
| رأس `main` على الخادم | `lavkoo@55e3dd0ea41069f64b0657fcbb4f8953710d2cd9` (عبر ls-remote) | VERIFIED |
| رأس `main` المحلي | `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129`. يختلف عن رأس `main` على الخادم. لم يُجلب ولم يُعدَّل، والأمر من اختصاص P0-A | VERIFIED |
| التفويض السابق | `lavkoo@218b8aea` (سلف لرأس فرع التدقيق) | VERIFIED |
| التاريخ | 2026-11-12 14:19 +03:00 حسب التكليف. ساعة النظام عند التنفيذ 2026-11-12 11:22 +0000 | VERIFIED |
| خارج النطاق | lavkooapp وlavkooappseller لم تُفحص في هذا التقرير | VERIFIED |
| الحالة | توثيق مكتمل؛ فحص المخطط المعزول BLOCKED؛ P0-C غير مُغلق | VERIFIED |

## الخلاصة

- 350 ملف ترحيل PHP: 336 في `database/migrations` و14 في وحدات `Modules`. تُنشئ 139 اسم جدول بـ`Schema::create` بالاسم الحرفي، وتعدّل 69 اسماً بالاسم الحرفي، منها 36 لا يُنشأ في أي ترحيل. VERIFIED
- 243 اسم جدول مرتبط بـ244 صنفاً من نماذج Eloquent: 133 له create، و35 تعديل فقط، و75 بلا أي ترحيل. الأعداد VERIFIED، وتسمية الجداول الضمنية INFERRED.
- لا يوجد ملف SQL متتبَّع في المستودع. ملف الـ dump المذكور في `CLAUDE.md` مستبعد بالقاعدة `*.sql`. VERIFIED
- المخطط الأساسي غير موجود في المستودع، ولا توجد نسخة معزولة مفوّضة. لذلك لا يمكن تحديد المخطط الفعلي للإنتاج أو لأي نسخة. BLOCKED
- الأعداد السابقة (36 و71 في Discovery-01، و34 و78 في Discovery-02) مُطابَقة في القسم 3. الفروق طريقية، ولا تُحسم بالمستودع وحده. INFERRED
- هذه الأعداد توصيف للملفات والأسماء، وليست مخططاً للإنتاج. VERIFIED

## 1. الجرد الثابت للترحيلات

### 1.1 الطريقة والأوامر

الطريقة: أوامر git للقراءة فقط، ثم تحليل بلغة Python 3 بوضع `-I` يُقرأ من stdin ولا يكتب أي ملف. لم يُشغَّل `php` أو `artisan` أو `composer` أو `migrate`، ولا أي اختبار. VERIFIED

```
git -C /home/user/lavkoo rev-parse HEAD
git -C /home/user/lavkoo ls-remote origin refs/heads/main refs/heads/claude/exciting-shannon-4mh2ok
git -C /home/user/lavkoo ls-tree -r --name-only HEAD -- database/migrations
git -C /home/user/lavkoo ls-tree -r --name-only HEAD        # ثم تصفية Modules/*/[Dd]atabase/[Mm]igrations/
git -C /home/user/lavkoo cat-file -p HEAD:<path>            # قراءة كل ملف ترحيل، ثم تقسيمه إلى up() وdown()
git -C /home/user/lavkoo ls-files                            # تصفية امتدادات SQL
git -C /home/user/lavkoo log -1 --format='%h %ad' --date=short -- app/Console/Commands/BootstrapTestSchema.php
```

أنماط التحليل المستعملة (لا تُنشر أي عبارة DDL):

```
create حرفي   : Schema::create\s*\(\s*['"]([A-Za-z0-9_]+)['"]
alter حرفي    : Schema::table\s*\(\s*['"]([A-Za-z0-9_]+)['"]
drop / rename : Schema::(drop|dropIfExists)\s*\(  ;  Schema::rename\s*\(
SQL خام       : DB::(statement|unprepared)\s*\(  ;  CREATE TABLE (غير حساسة لحالة الأحرف)
شرطي          : Schema::(hasTable|hasColumn)\(
```

### 1.2 النتائج

| المقياس | القيمة | الوسم |
|---|---|---|
| ملفات ترحيل PHP في `database/migrations` | 336، والمدى الزمني من 2016-06-01 إلى 2026-11-22 | VERIFIED |
| ملفات ترحيل PHP في `Modules/*/database/migrations` | 14 في وحدات AI وBlog وTaxModule، مع ملفي `.gitkeep` غير PHP | VERIFIED |
| الإجمالي PHP | 350 | VERIFIED |
| ملفات فيها `Schema::create` داخل `up()` | 103 (92 في الجذر و11 في الوحدات) | VERIFIED |
| ملفات تعديل فقط (`Schema::table` بلا create) | 242 (239 في الجذر و3 في الوحدات) | VERIFIED |
| ملفات بلا أي استدعاء بنّاء من `Schema` | 5، كلها في الجذر (انظر 1.3) | VERIFIED |
| استدعاءات `create` بالاسم الحرفي | 139، وأسماء مميزة 139، ولا اسم يُنشأ مرتين | VERIFIED |
| استدعاءات `create` باسم غير حرفي | 0 | VERIFIED |
| أسماء مميزة في `Schema::table` بالاسم الحرفي | 69، منها 36 لا يُنشأ في أي ترحيل | VERIFIED |
| استدعاءات `Schema::table` باسم غير حرفي | 6 (5 في الجذر و1 في الوحدات)؛ الجدول المستهدف غير معروف | UNKNOWN |
| `rename` أو `drop` داخل `up()` | 0. و`drop` داخل `down()` في 117 ملفاً | VERIFIED |
| ملفات فيها `DB::statement` أو `DB::unprepared` | 2 (انظر 1.3) | VERIFIED |
| استدعاءات `hasTable` أو `hasColumn` شرطية داخل الترحيلات | 110 سطراً | VERIFIED |
| أسطر `json(` | 72 | VERIFIED |
| `generatedAs` و`storedAs` و`virtualAs` و`check(` و`fullText(` و`spatialIndex(` و`change(` و`charset(` و`collation(` و`engine(` و`useCurrentOnUpdate(` | 0 لكل منها | VERIFIED |
| ملفات يحوي اسمها `_create_` | 102، والفرق عن 103 ملفاً فيها create هو ملف `add_stock_allocation`، أي أن العدّ بالاسم وحده يخطئ | VERIFIED |

### 1.3 الملفات الخمسة بلا استدعاء بنّاء، وملفات SQL الخام

| الملف | ما يفعله في `up()` | الوسم |
|---|---|---|
| `database/migrations/2026_04_23_120000_rename_admin_role_module_access_keys.php` | ترحيل بيانات على `admin_roles` عبر `DB::table` (السطر 41)، لا DDL | VERIFIED |
| `database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php` | `DB::statement($sql)` بوسيط متغيّر (السطر 104، ويتكرر في `down()` عند السطر 204)؛ نص SQL غير معروف بالتحليل الثابت | VERIFIED للاستدعاء، UNKNOWN للأثر |
| `database/migrations/2026_11_06_100000_widen_order_details_delivery_status_for_fulfillment.php` | يستدعي `LegacyColumnWidener::widenString` (السطر 21)، والـ DDL داخل صنف مساعد لم يُفحص | VERIFIED للاستدعاء، UNKNOWN للأثر |
| `database/migrations/2026_11_06_100100_widen_order_details_variation_for_fulfillment.php` | يستدعي `LegacyColumnWidener::convertToText` (السطر 21)، والـ DDL داخل الصنف المساعد نفسه | VERIFIED للاستدعاء، UNKNOWN للأثر |
| `database/migrations/2026_11_08_100004_register_myfatoorah_payment_gateway.php` | ترحيل بيانات (إدراج صف) على `addon_settings` بعد حارس `hasTable` (السطر 31، والإدراج عند السطر 74) | VERIFIED |
| `database/migrations/2026_03_29_140000_add_unique_index_to_seo_meta_info_table.php` | `DB::statement("…")` بنص متعدد الأسطر (السطر 16)، ولم يُفكَّك نصه في هذا التقرير | VERIFIED للاستدعاء، UNKNOWN للأثر |

### 1.4 مقارنة الفروع

- عدد ملفات الجذر 336 في كل من `lavkoo@55e3dd0ea41069f64b0657fcbb4f8953710d2cd9` و`lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129` و`lavkoo@c8b6ed3e434ba39d28a2fd5f01a9159b925bb268` و`lavkoo@218b8aea`. VERIFIED
- عدّ وحدات `Modules` على الفروع الأخرى لم يُعَد. في محاولة أولى أعاد نمط حساس لحالة الأحرف صفراً للوحدات، وصُحّح العدّ على الفرع `c8b6ed3e` فقط. UNKNOWN

## 2. جداول النماذج ومراجع SQL

### 2.1 الطريقة

- فحص 2628 ملف PHP متتبَّعاً في `app/` و`Modules/` و`database/` و`routes/` و`config/` و`bootstrap/`، مع استبعاد مجلدات الترحيل. VERIFIED
- عُدّ نموذجاً كل صنف غير مجرّد يرث من `Model` أو `Authenticatable` أو `Pivot` أو `MorphPivot`، أو يكون اسم أبيه منتهياً بـ`Model`. العدد 244. VERIFIED
- الكشف بالاسم المباشر للأب لا يلتقط الوراثة متعددة المستويات، فالعدد قد يقل عن الواقع. INFERRED
- اسم الجدول: قيمة `$table` الحرفية إن وُجدت (89 صنفاً، ولم توجد قيمة غير حرفية)، وإلا الاسم الضمني بقاعدة Laravel (155 صنفاً). VERIFIED للعدّ
- الاسم الضمني حُسب بتحويل اسم الصنف إلى snake_case وجمع كلمته الأخيرة بقواعد إنجليزية مبسطة، ويُقبل الاسم كما هو أو جمعه. لم يُشغَّل محلّل Laravel لأن تشغيل PHP محظور. INFERRED
- التصنيف: M = له `Schema::create` حرفي في `up()` لأي ترحيل؛ A = له `Schema::table` حرفي ولا create؛ D = لا يظهر في أي منهما. VERIFIED (تعريف الطريقة)

### 2.2 النتائج

| المقياس | القيمة | الوسم |
|---|---|---|
| أصناف النماذج غير المجرّدة | 244 (في `app/Models` 232، وفي `Modules` 11، و`app/User.php` 1) | VERIFIED |
| أسماء جداول مميزة للنماذج | 243، ويشترك `users` بين `App\User` و`App\Models\User` | VERIFIED |
| M / A / D | 133 / 35 / 75، والمجموع 243 | VERIFIED |
| الجداول النموذجية بلا create (A + D) | 110 | VERIFIED |
| جداول create غير مرتبطة بنموذج | 6: `jobs` و5 جداول `oauth_*` | VERIFIED |
| الجداول المُنشأة بالاسم الحرفي (M + غير المرتبط) | 139 = 133 + 6 | VERIFIED |
| مراجع جداول في الشيفرة خارج النماذج (`DB::table` و`->table` و`hasTable` بالاسم الحرفي) | 158 اسماً مميزاً | VERIFIED |
| من تلك المراجع، أسماء بلا create وبلا alter | 34 (قائمتها في 2.5، وتشمل 17 جدول `monitoring_*`) | VERIFIED |
| استدعاءات بأسماء ديناميكية لا يلتقطها البحث الحرفي | عددها غير معروف (منها استدعاء `Schema::create($table, …)` في الأمر المذكور في 2.4) | UNKNOWN |

### 2.3 مراجع SQL في المستودع

| الفحص | النتيجة | الوسم |
|---|---|---|
| `git ls-files` لملفات `*.sql` و`*.sql.gz` و`*.sqlite` و`*.dump` وما شابهها | 0 ملفات | VERIFIED |
| قاعدة `.gitignore` السطر 53 | `*.sql` تستبعد ملف الـ dump | VERIFIED |
| `CLAUDE.md` السطر 22 | يطلب استيراد `installation/backup/database.sql` قبل الترحيل، والملف مستبعد من Git | VERIFIED |
| `installation/` المتتبَّع | ملفان نصيان فقط: `activate_install_routes.txt` و`activate_update_routes.txt` | VERIFIED |
| `database/` المتتبَّع خارج الترحيلات | ملف `.gitignore`، وثلاثة ملفات في `data`، وملف في `factories`، وخمسة في `seeds`؛ لا SQL فيها | VERIFIED |
| `docs/` | 17 ملف Markdown يحوي النص `create table` (لم يُستخرج محتواها)؛ لا ملف مخطط SQL بين الملفات | VERIFIED |
| وجود نسخة من الـ dump محلياً عند المالك أو في مكان آخر | لم يُبحث عنه، لأن القواعد تمنع البحث عن أي نسخة قاعدة بيانات | UNKNOWN |

### 2.4 الأمر `dev:bootstrap-test-schema`

- الأمر ينشئ مخططاً اختبارياً مصغّراً، ويصف نفسه بأنه غير الـ dump الفعلي. VERIFIED (`lavkoo:app/Console/Commands/BootstrapTestSchema.php:31-33`)
- تعليق الكود يقرّر أن الجداول الأساسية لا تُنشئها أي ترحيلات، وأن الـ dump مستبعد من Git، ويذكر «307 ترحيلاً و229 تعديلاً فقط». VERIFIED (`lavkoo:app/Console/Commands/BootstrapTestSchema.php:10-18`)
- مفاتيح `definitions()` أربعة عشر جدولاً (`lavkoo:app/Console/Commands/BootstrapTestSchema.php:64`): 6 لها create، و4 تعديل فقط (`currencies` و`admins` و`notifications` و`users`)، و4 بلا أي ترحيل (`business_settings` و`social_medias` و`admin_roles` و`settings`). VERIFIED
- إنشاء الجدول ديناميكي عبر `Schema::create($table, …)` بعد فحص `hasTable` (`lavkoo:app/Console/Commands/BootstrapTestSchema.php:45-49`)، لذلك لا يظهر في البحث الحرفي. VERIFIED
- لم يُشغَّل الأمر، ولا يُعدّ مرجعاً لمخطط الإنتاج. VERIFIED

### 2.5 قوائم الأسماء (أسماء فقط)

**أ. جداول النماذج تعديل فقط (A، 35 اسماً):** VERIFIED
admin_wallet_histories, admin_wallets, admins, banners, brands, categories, chattings, contacts, coupons, currencies, customer_wallet_histories, customer_wallets, deal_of_the_days, flash_deal_products, flash_deals, help_topics, notifications, order_details, orders, password_resets, payment_requests, product_stocks, products, reviews, seller_wallet_histories, seller_wallets, sellers, shipping_addresses, shipping_methods, shops, support_ticket_convs, support_tickets, transactions, users, withdraw_requests

**ب. جداول النماذج بلا أي ترحيل (D، 75 اسماً):** VERIFIED
abandoned_cart_reminders, addon_settings, admin_roles, app_carousel_settings, approval_decisions, approval_requests, attributes, audit_logs, brand_claim_documents, brand_claims, business_settings, category_governance, colors, commission_rules, customer_segments, customers, delivery_syria_governorate_rates, delivery_syria_parcels, exchange_rate_logs, experience_campaigns, experience_experiments, experience_pages, feature_deals, meta_app_integrations, mtn_cash_terminals, mtn_cash_transactions, notification_template_mappings, order_fulfillments, order_item_commissions, payment_routing_rules, product_batches, product_collections, product_metrics, product_moderation_events, product_price_changes, product_search_index, purchase_order_items, purchase_orders, redirects, referrla_customers, return_shipments, search_functions, seller_api_keys, seller_automation_actions, seller_automation_rules, seller_automation_runs, seller_bulk_jobs, seller_insights, seller_notification_deliveries, seller_pricing_policies, seller_roles, seller_sla_breaches, seller_staff, seller_verification_documents, seller_webhook_deliveries, seller_webhooks, seo_meta_translations, seo_templates, shipping_zones, social_medias, stock_movements, suppliers, theme_assets, theme_blocks, theme_sections, theme_versions, themes, vendor_bank_change_logs, vendor_ledger_entries, vendor_payout_requests, vendor_settlements, warehouse_stock, warehouses, wishlists, zender_settings

**ج. مراجع في الشيفرة بلا أي ترحيل (غير نموذجية، 34 اسماً):** VERIFIED
analytics_campaign_clicks, analytics_campaigns, analytics_daily, analytics_events, analytics_health, analytics_sessions, analytics_visitors, api_changes, api_response_shapes, api_snapshots, auction_participants, auction_products, failed_jobs, migrations, monitoring_alert_rules, monitoring_alert_states, monitoring_backups, monitoring_check_results, monitoring_dependency_buckets, monitoring_deployments, monitoring_error_groups, monitoring_errors, monitoring_events, monitoring_incidents, monitoring_request_buckets, monitoring_scheduled_runs, monitoring_series, monitoring_settings, monitoring_slow_queries, monitoring_spans, monitoring_traces, telemetry_daily, telemetry_requests, visit_sessions

**د. جداول create غير مرتبطة بنموذج (6 أسماء):** VERIFIED
jobs, oauth_access_tokens, oauth_auth_codes, oauth_clients, oauth_personal_access_clients, oauth_refresh_tokens

## 3. مطابقة التقديرات السابقة وافتراضات الترحيلات

### 3.1 جدول المطابقة

| المصدر | المنهج | تعديل فقط | بلا ترحيل | ملاحظة | الوسم |
|---|---|---|---|---|---|
| E03 السطر 376 (Discovery-01، على `lavkoo@7f42777c8247144df322fdc0e0eeca03ae947129`) | مصفوفة أسماء الجداول، 250 اسماً | 36 | 75 | 139 M + 36 A + 75 D = 250 | VERIFIED |
| تقرير 07 السطر 14 والأسطر 341–343، وE03 السطر 618 | عرض مختصر وسرد | 36 | 71 | الرقم 71 يوافق 75 مطروحاً منها أربعة أسماء وصفها E03 بأنها غير مؤكدة | VERIFIED للنص، INFERRED لسبب الاستبعاد |
| `lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:216` (Q-26) | يلخّص التقريرين 11 و12 | 37 (تقرير 11) و36 (تقرير 12) | 110 (تقرير 11) و71 (تقرير 12) | الأرقام منقولة كما وردت ولم تُعَد | VERIFIED للنص |
| 03a القسم 7-أ، الأسطر 277–281 | سكربت ساكن على أرشيف الشجرة | 34 | 78 بعد استبعاد 3 إيجابيات كاذبة، والخام 115 يشملها | 113 = 78 + 35 اسماً غير نموذجي | VERIFIED للنص |
| 03a السطر 61 (E01-08) | تقدير تقريبي | لم يُذكر | 110 إلى 120 | لم يُعَد | VERIFIED |
| تعليق `BootstrapTestSchema.php` (الأسطر 15–17)، وE08 السطر 29 | «307 ترحيلاً و229 تعديلاً فقط» | 229 ملفاً | لا ينطبق | وحدة القياس ملفات لا جداول | VERIFIED |
| هذا التقرير (القسم 2) | مطابقة الأسماء بالكود والترحيلات | 35 نموذجاً، و36 اسماً إجمالاً | 75 نموذجاً | 110 = 35 + 75، و34 غير نموذجي | VERIFIED للعدّ |

### 3.2 تفسير الفروق

- تطابقت الأعداد الثلاثة 139 و36 و75 مع E03 تطابقاً كاملاً، فالمنهج متسق مع Discovery-01 على مستوى الأسماء. VERIFIED
- الفرق بين 75 و71 هو الأسماء الأربعة التي وصفها E03 بأنها نماذج بلا استعمال فعلي أو بلا جدول مؤكَّد: `customers` و`feature_deals` و`referrla_customers` و`search_functions`. الأسماء الأربعة موجودة في القائمة (ب). VERIFIED للأسماء، INFERRED لسبب الاستبعاد
- الرقم 110 يطابق الرقم 110 المنسوب إلى تقرير 11 في Q-26، ولم يُعرف منهج التقرير 11. VERIFIED للمطابقة، UNKNOWN للمنهج
- مقارنة بـ03a: تعديل فقط 35 هنا مقابل 34 هناك (+1)، وبلا ترحيل 75 مقابل 78 (−3)، وغير نموذجي 34 مقابل 35 (−1). قائمة 03a الكاملة غير موجودة في المستودع، فلا تُحدَّد الأسماء الفارقة. الأرجح أن منشأ الفرق اشتقاق الأسماء المفردة والجمع. UNKNOWN للأسماء، INFERRED للسبب
- التعليق (307 و229) يطابق القطع الزمني عند 2026-11-06 تقريباً: 304 ملفاً، منها 72 فيها create و228 تعديل فقط، و4 بلا استدعاء. آخر تعديل للأمر كان في `lavkoo@13d1fc33` بتاريخ 2026-11-06. الفروق 3 و1 تشير إلى تغيّر في مجموعة الملفات بعد كتابة التعليق، لكنها لم تُحسم. VERIFIED للأرقام، INFERRED للسبب، UNKNOWN لشجرة التعليق الأصلية
- وحدتا القياس مختلفتان: 229 في التعليق ملفات، و36 في E03 جداول. وقد أقرّ E03 بذلك في السطر 708 بقوله إنه «لا يُعدّ تعارضاً في الوقائع». VERIFIED

### 3.3 افتراضات تنهار عند الاعتماد على الترحيلات وحدها

| الافتراض | لماذا لا يصمد | الأثر | الوسم |
|---|---|---|---|
| أن الترحيلات تكفي لبناء المخطط كاملاً | 36 جدول تعديل بلا إنشاء، و75 نموذجاً بلا أي ترحيل، والأساس يأتي من dump غير متتبَّع | نماذج كثيرة بلا مخطط ثابت | VERIFIED |
| أن أسماء الترحيلات تصف أثرها | 102 بالاسم مقابل 103 بالمحتوى | العدّ بالاسم يخطئ | VERIFIED |
| أن التعديلات تكفي لمعرفة الأنواع والمفاتيح | لا CREATE أساسي للجداول الـ36 في المستودع | أنواع الأعمدة والمفاتيح غير مؤكدة | UNKNOWN |
| أن الترحيل يعمل من قاعدة فارغة | التعليق في الكود يقرّر أن التنفيذ على قاعدة فارغة يتوقف، والتشغيل محظور لتأكيد ذلك | الترحيل يحتاج أساساً خارجياً | INFERRED (من التعليق) |
| أن الترحيل غير مشروط بحالة القاعدة | 110 سطراً `hasTable` أو `hasColumn` | النتيجة تتوقف على حالة القاعدة الفعلية | VERIFIED للعدّ، UNKNOWN للأثر |
| أن كل DDL ظاهر في النص | صنفان مساعدان، وخمسة استدعاءات بأسماء غير حرفية، وملفا SQL خام | أثرها الفعلي غير معروف | VERIFIED للاستدعاء، UNKNOWN للأثر |
| أن الجداول غير النموذجية كلها في اتصال واحد | 17 جدولاً من `monitoring_*` تُقرأ عبر إعداد اتصال منفصل (E03 السطر 370) | وجودها يُقاس على الاتصال المعني | VERIFIED للمصدر، UNKNOWN لوجودها |
| أن الترحيلات تمثّل ما هو مطبَّق في الإنتاج | جدول `migrations` في الإنتاج غير معروف (M-02) | لا يُعرف أي ترحيل طُبِّق فعلاً | UNKNOWN |

## 4. فحص المخطط المعزول: BLOCKED

- لم تُقدَّم في هذه الجلسة نسخة معزولة مخطّطة (schema-only) ولا كتالوج مخطط مفوّض. القاعدة في `lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:43`. BLOCKED
- لم يُفحص: المحرك والإصدار، والجداول الموجودة فعلاً، والمفاتيح الأساسية والأجنبية، وأنواع الأعمدة، وقابلية القيم للفراغ. BLOCKED
- لم يُنشأ أي مخطط، ولم يُستعد أي dump، ولم يُبحث عن نسخة قاعدة بيانات. VERIFIED
- الحد الأدنى المطلوب من المالك (وصف فقط، لم يُطلب تنفيذه هنا): كتالوج مخطط بلا صفوف ولا بيانات، لكل محرك على حدة، مع تاريخ النسخة ومصدرها وتسميتها «معزولة» وليست إنتاجاً، وبصمة لملف الـ dump (hash) دون محتواه. BLOCKED

## 5. المتطلبات المسبقة الناقصة والفحوص اللاحقة (وصف فقط، لم تُنفَّذ)

### 5.1 الجدول

| المعرّف | المتطلب المسبق | الدليل | الحالة | الفحص اللاحق (وصف) | التفويض المطلوب |
|---|---|---|---|---|---|
| PR-01 | نسخة معزولة مخطّطة مفوّضة، مع المحرك والإصدار والمصدر والتاريخ | القسم 4 | BLOCKED | قراءة كتالوج المخطط فقط (أسماء الجداول والأعمدة والأنواع وقابلية الفراغ والمفاتيح)، دون صفوف | تفويض كتابي من المالك لنسخة معزولة وقراءة فقط |
| PR-02 | مصدر الجدول الأساسي (`installation/backup/database.sql`) وإصداره | `lavkoo:CLAUDE.md:22` و`lavkoo:.gitignore:53` | UNKNOWN | تأكيد المالك لإصدار الملف، وبصمته (hash) فقط، وعدد أسماء `CREATE TABLE` دون محتوى | تفويض المالك، ولا نشر لأي محتوى |
| PR-03 | أنواع الأعمدة والمفاتيح لجداول التعديل فقط (35 نموذجاً) | القسم 2.2 | UNKNOWN | مقارنة الأعمدة التي أضافتها الترحيلات بالكتالوج، بالأسماء فقط | PR-01 |
| PR-04 | حضور جداول D (75 نموذجاً، منها 71 بعد الاستبعاد) في المخطط المعزول | القسم 2.5 ب و3.2 | UNKNOWN | حضور كل اسم أو غيابه في الكتالوج، ثم عدّ الفئات | PR-01 |
| PR-05 | الجداول غير النموذجية (34) واتصال المراقبة المنفصل | القسم 2.5 ج، وE03 السطر 370 | UNKNOWN | حضور كل اسم على الاتصال المعني، مع تسجيل اسم الاتصال لا قيمته | PR-01 وتفويض صريح لاتصال المراقبة |
| PR-06 | أثر الاستدعاءات غير الحرفية (6)، والصنفان المساعدان، وملفا SQL الخام | القسم 1.3 | UNKNOWN | قراءة ثابتة للصنف المساعد وتحديد الجدول والعمود المستهدفين؛ لا تنفيذ | لا شيء الآن. التنفيذ على نسخة معزولة يحتاج تفويضاً منفصلاً |
| PR-07 | الترحيلات المطبَّقة فعلاً في الإنتاج | M-02 في تقرير 07 (السطر 273) | UNKNOWN | قراءة أسماء الترحيلات من جدول `migrations` في نسخة معزولة، ومقارنتها بالـ350 ملفاً | PR-01 |
| PR-08 | أثر الشروط `hasTable` و`hasColumn` (110 سطراً) | القسم 1.2 | UNKNOWN | تسجيل حالة كل شرط في النسخة المعزولة (وجود أو غياب) | PR-01 |
| PR-09 | المحركان المستهدفان MySQL 8 وMariaDB 11.4.2، مع إصدار الإنتاج | `lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:62`، وM-05 | BLOCKED (DEFERRED) | بناء قاعدة معزولة لكل محرك من ملف أساس مفوّض، ثم مقارنة الكتالوج بالقاعدة المرجعية | تفويض منفصل، ولا تطبيق ترحيل في هذه المهمة |
| PR-10 | سلوك عمود `json` (72 موضعاً) عبر المحركين | القسم 1.2 | BLOCKED (DEFERRED) | مقارنة تمثيل الأنواع والقيود لكل محرك بعد PR-09 | تفويض منفصل |
| PR-11 | هل تُحمَّل ترحيلات الوحدات AI وBlog وTaxModule في بيئة الإنتاج | القسم 1.1 | UNKNOWN | قراءة إعدادات تحميل الوحدات، بأسماء المفاتيح وقيمها الافتراضية غير السرية فقط | قراءة إعدادات مفوّضة |

### 5.2 المحركان المستهدفان: DEFERRED

- لا تُطبَّق الترحيلات على أي قاعدة بيانات في هذه المهمة، بما فيها النسخ المعزولة. VERIFIED (`lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:62`)
- محرك الإعداد الافتراضي في الشيفرة هو `mysql` عبر `env('DB_CONNECTION', 'mysql')` (`lavkoo:config/database.php:18`). إصدار الإنتاج غير معروف. VERIFIED للافتراض، UNKNOWN للإصدار
- عمود `json` يُمثَّل بطرق مختلفة بين MySQL 8 وMariaDB 11.4.2. هذا الفرق من المعرفة العامة بالمحركين، ولم يُقس في المستودع. INFERRED
- عدم ظهور الأعمدة المولّدة وقيود `check` والفهارس النصية الكاملة في الترحيلات لا يعني غيابها في الإنتاج. VERIFIED للعدّ، UNKNOWN للأثر

## 6. حدود النشر (معلومات منقّحة)

- لا يتضمن التقرير أي عبارة DDL، ولا أي قيمة بيانات، ولا أي تفصيل اتصال أو مفتاح أو كلمة مرور. VERIFIED
- الأسماء المعروضة هي أسماء جداول وملفات ترحيل وأصناف عامة في المستودع. VERIFIED
- قيمة الافتراض `mysql` اسم محرك وليست سراً. VERIFIED

## 7. معايير القبول المؤجّلة (للتفويض اللاحق)

- AC-01: تسجيل المحرك والإصدار ومصدر النسخة وتاريخها وتسميتها «معزولة». الحالة: BLOCKED
- AC-02: تصنيف كل اسم من الـ243 جدولاً نموذجياً بحضور أو غياب في الكتالوج، مع عدّ الفئات فقط. الحالة: BLOCKED
- AC-03: لكل اسم من الـ36 المعدَّل فقط، الجدول موجود، وكل عمود أضافته الترحيلات موجود بالاسم. الحالة: BLOCKED
- AC-04: لكل اسم من الـ139 المُنشأ، الجدول موجود، أو يُسجَّل الفرق مع سببه. الحالة: BLOCKED
- AC-05: لكل اسم من الـ34 غير النموذجي، الحضور على الاتصال المعني، مع اسم الاتصال فقط. الحالة: BLOCKED
- AC-06: حسم الاستدعاءات غير الحرفية، والصنفين المساعدين، وملفي SQL الخام إلى أهداف، أو فئة UNKNOWN مع السبب. الحالة: BLOCKED
- AC-07: مطابقة الترحيلات المطبَّقة (بالأسماء فقط) مع الـ350 ملفاً، وكل فرق مُفسَّر. الحالة: BLOCKED
- AC-08: إعادة الجرد الثابت بالأوامر الموثقة في القسم 1.1 على رأس commit محدد، مع تسجيل الـ SHA. الحالة: BLOCKED
- AC-09: تطبيق الترحيلات على قاعدة معزولة لكل محرك (MySQL 8 وMariaDB 11.4.2) بعد تفويض منفصل فقط. معيار القبول: لا خطأ في التطبيق، وتقرير فروق الكتالوج بالفئات. الحالة: BLOCKED (DEFERRED)
- AC-10: لا DDL ولا بيانات ولا اتصال في المستودع العام، وأي مادة حساسة تُحفظ في التخزين المقيَّد. الحالة: BLOCKED
- AC-11: قرار إغلاق P0-C بتوقيع مالك منفصل. الحالة: BLOCKED

## حدود هذا التقرير وعناصر BLOCKED / UNKNOWN

- **BLOCKED:** فحص المخطط المعزول (القسم 4)، وكل حقيقة تعتمد على الإنتاج أو نسخة معزولة: الجداول الموجودة فعلاً، والمحرك والإصدار، والمفاتيح، والأنواع، والترحيلات المطبَّقة. BLOCKED
- **BLOCKED (DEFERRED):** المحركان MySQL 8 وMariaDB 11.4.2، فلا تطبيق ترحيل ولا قياس في هذه المهمة. BLOCKED
- **UNKNOWN:** محتوى ملف الـ dump وإصداره، ووجوده عند المالك، لأن البحث عن نسخ قواعد البيانات محظور. UNKNOWN
- **UNKNOWN:** الأثر الفعلي لاستدعاءات `LegacyColumnWidener` وSQL الخام وأسماء الجداول غير الحرفية. UNKNOWN
- **UNKNOWN:** الأسماء الفارقة بين هذا التقرير و03a، وسبب الفرق 307/229 مقابل 304/228 عند القطع الزمني. UNKNOWN
- **INFERRED:** قواعد تسمية الجداول الضمنية تقريب لمحلّل Laravel لم يُشغَّل، ومنشأ فروق 03a. INFERRED
- **UNKNOWN:** الأسماء الديناميكية التي لا يلتقطها البحث الحرفي، ومنها استدعاء `Schema::create($table, …)` في الأمر المذكور. UNKNOWN
- **UNKNOWN:** وراثة `$table` عبر أصناف أب غير مرئية للبحث، والنماذج غير المكتشفة بالاسم الأساسي. UNKNOWN
- **UNKNOWN:** عدّ وحدات `Modules` على الفروع الأخرى، وتحميل الوحدات في الإنتاج. UNKNOWN
- **حدود المنهج:** هذا التقرير لا يثبت أن المخطط الفعلي يطابق أي عدد هنا. الأعداد توصيف للمستودع لا للإنتاج. VERIFIED
- **لا إغلاق:** لا يغلق هذا التقرير P0-C أو G0 أو أي بوابة. القرار للمالك بتوقيع منفصل. VERIFIED
- **الترابط مع المهام الأخرى:** المقاييس M-01 وM-02 وM-05 وM-07 تُقاس في P0-E لا هنا. VERIFIED (`lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:272-287`)

## سجل الأدلة

| المعرّف | المصدر | المنهج أو الأمر | النتيجة | الوسم |
|---|---|---|---|---|
| EV-01 | `lavkoo@c8b6ed3e434ba39d28a2fd5f01a9159b925bb268` | `rev-parse HEAD` و`ls-remote` على `main` وفرع التدقيق | رؤوس الفروع كما في الترويسة | VERIFIED |
| EV-02 | `lavkoo@c8b6ed3e434ba39d28a2fd5f01a9159b925bb268` | `ls-tree -r --name-only` لمسار الترحيل والوحدات | 336 جذر، و14 وحدات، و2 `.gitkeep` | VERIFIED |
| EV-03 | `lavkoo@c8b6ed3e434ba39d28a2fd5f01a9159b925bb268` | `cat-file -p` وتقسيم `up()` و`down()` وأنماط القسم 1.1 | 139 و69 و102 و103 و242 و5 وغيرها (القسم 1.2) | VERIFIED |
| EV-04 | `lavkoo@c8b6ed3e434ba39d28a2fd5f01a9159b925bb268` | تحليل أصناف النماذج والوراثة و`$table` وقائمة الأسماء | 244 و89 و155 و243 و133 و35 و75 و6 | VERIFIED |
| EV-05 | `lavkoo@c8b6ed3e434ba39d28a2fd5f01a9159b925bb268` | `git ls-files` بامتدادات SQL | 0 | VERIFIED |
| EV-06 | `lavkoo:.gitignore:53` | قراءة القاعدة | `*.sql` | VERIFIED |
| EV-07 | `lavkoo:CLAUDE.md:22` | قراءة السطر | استيراد الـ dump قبل الترحيل | VERIFIED |
| EV-08 | `lavkoo:app/Console/Commands/BootstrapTestSchema.php:10-18` | قراءة التعليق | الجداول الأساسية بلا ترحيل، والـ dump مستبعد | VERIFIED |
| EV-09 | `lavkoo:app/Console/Commands/BootstrapTestSchema.php:31-33` | قراءة التوقيع والوصف | مخطط اختباري مصغّر، ويشترط `local` أو `testing` إلا بخيار الإجبار | VERIFIED |
| EV-10 | `lavkoo:app/Console/Commands/BootstrapTestSchema.php:45-49` | قراءة `hasTable` و`Schema::create` | إنشاء بالاسم الديناميكي | VERIFIED |
| EV-11 | `lavkoo:app/Console/Commands/BootstrapTestSchema.php:64` | قراءة مفاتيح `definitions()` | 14 مفتاحاً | VERIFIED |
| EV-12 | `lavkoo:app/Services/Fulfillment/Readiness/Compatibility/RestoreSection.php:24-30` | قراءة القائمة وفحص عضويتها في الترحيلات | 31 اسماً، كلها بلا create وبلا alter | VERIFIED |
| EV-13 | `lavkoo:config/database.php:18` | قراءة سطر الافتراضي | `mysql` | VERIFIED |
| EV-14 | `lavkoo@13d1fc33` و`lavkoo@4c47205b` | `git log -1 --format='%h %ad' --date=short -- <path>` | آخر تعديل للأمر في 2026-11-06، وللمجلد في 2026-11-09 | VERIFIED |
| EV-15 | `lavkoo:database/migrations/2026_04_23_120000_rename_admin_role_module_access_keys.php:41` | قراءة السطر | ترحيل بيانات، لا DDL | VERIFIED |
| EV-16 | `lavkoo:database/migrations/2026_05_07_000001_update_all_decimal_columns_to_40_20.php:104` | قراءة السطر | `DB::statement` بمتغيّر | VERIFIED |
| EV-17 | `lavkoo:database/migrations/2026_11_06_100000_widen_order_details_delivery_status_for_fulfillment.php:21` | قراءة السطر | استدعاء صنف مساعد | VERIFIED |
| EV-18 | `lavkoo:database/migrations/2026_11_06_100100_widen_order_details_variation_for_fulfillment.php:21` | قراءة السطر | استدعاء صنف مساعد | VERIFIED |
| EV-19 | `lavkoo:database/migrations/2026_11_08_100004_register_myfatoorah_payment_gateway.php:31` | قراءة السطر | حارس `hasTable` على `addon_settings` | VERIFIED |
| EV-20 | `lavkoo:database/migrations/2026_03_29_140000_add_unique_index_to_seo_meta_info_table.php:16` | قراءة السطر | `DB::statement` بنص متعدد الأسطر | VERIFIED |
| EV-21 | `lavkoo:database/migrations/2026_11_10_100000_add_stock_allocation.php` | تحقق من وجود الملف | الملف الوحيد الذي ينشئ جدولاً دون `_create_` في اسمه | VERIFIED |
| EV-22 | `lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:12` | قراءة السطر | 336 ملفاً ترحيلياً في النطاق | VERIFIED |
| EV-23 | `lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:376` | قراءة السطر | 250 اسماً: 139 M و36 A و75 D، والأسماء الأربعة المشكوك فيها | VERIFIED |
| EV-24 | `lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:370` | قراءة السطر | 17 جدول `monitoring_*` على اتصال إعداد منفصل | VERIFIED |
| EV-25 | `lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:618` | قراءة السطر | السؤال المفتوح عن الجداول الـ71 | VERIFIED |
| EV-26 | `lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:689` | قراءة السطر | تصحيح E03-F01: أمثلة الجداول الـ36 والـ71 | VERIFIED |
| EV-27 | `lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E03-data-model-ownership-inventory.md:708` | قراءة السطر | وحدتا القياس مختلفتان ولا تعارض في الوقائع | VERIFIED |
| EV-28 | `lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:14` | قراءة السطر | 36 جدولاً ALTER-only و71 جدولاً بلا migration | VERIFIED |
| EV-29 | `lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:342` | قراءة السطر | 36 جدولاً ALTER-only و71 جدولاً بلا migration (E03-F01) | VERIFIED |
| EV-30 | `lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/07-migration-rollback-plan.md:273` | قراءة السطر | M-02 جدول `migrations` | VERIFIED |
| EV-31 | `lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E08-catalog-discovery-pricing.md:29` | قراءة السطر | اقتباس تعليق 307 و229 | VERIFIED |
| EV-32 | `lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md:216` | قراءة السطر | Q-26 يذكر 37 و110 و36 و71 | VERIFIED |
| EV-33 | `lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/03a-coverage-gaps-e01-e11.md:277` | قراءة السطر | 115 جدولاً مرتبطاً بنماذج بلا إنشاء، منها 34 تعديل فقط | VERIFIED |
| EV-34 | `lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/03a-coverage-gaps-e01-e11.md:278` | قراءة السطر | 34 تعديل فقط | VERIFIED |
| EV-35 | `lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/03a-coverage-gaps-e01-e11.md:279` | قراءة السطر | 78 بعد استبعاد 3 إيجابيات كاذبة | VERIFIED |
| EV-36 | `lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/03a-coverage-gaps-e01-e11.md:280` | قراءة السطر | 35 اسماً غير نموذجي بلا إنشاء وتعديل | VERIFIED |
| EV-37 | `lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/03a-coverage-gaps-e01-e11.md:262` | قراءة السطر | 17 جدول `monitoring_*` لا يُنشئه أي ترحيل | VERIFIED |
| EV-38 | `lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/03a-coverage-gaps-e01-e11.md:61` | قراءة السطر | تقدير تقريبي 110 إلى 120 | VERIFIED |
| EV-39 | `lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/06-updated-go-no-go.md:142` | قراءة السطر | الأعداد «تقريبية، وتختلف بطريقة العدّ» | VERIFIED |
| EV-40 | `lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:36` | قراءة السطر | منع الترحيلات والإنشاء على أي قاعدة | VERIFIED |
| EV-41 | `lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:43` | قراءة السطر | قاعدة الوسم BLOCKED عند غياب النسخة المعزولة | VERIFIED |
| EV-42 | `lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:56-65` | قراءة القسم 3 | خطوات P0-C ومخرجها | VERIFIED |
| EV-43 | `lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:62` | قراءة السطر | منع تطبيق الترحيلات على MySQL 8 وMariaDB 11.4.2 | VERIFIED |
| EV-44 | الأنماط في القسم 1.1 مطبَّقة عبر `git cat-file` و`git grep` في Python بوضع `-I` | تشغيل من stdin دون كتابة ملفات | الأعداد الواردة في الأقسام 1 إلى 3 | VERIFIED |
