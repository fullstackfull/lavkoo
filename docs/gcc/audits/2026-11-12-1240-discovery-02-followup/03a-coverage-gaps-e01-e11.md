# الجولة الثانية لتغطية الفجوات: الأدلة E01 إلى E11 (03a)

**الحالة:** توثيق فقط، قراءة ساكنة. لا تنفيذ، ولا تشغيل كود أو اختبار، ولا تعديل لأي مستودع.
**تاريخ الجولة:** 2026-11-12.
**المستودعات (HEAD عند الفحص):**
- `lavkoo`: main على GitHub هو `55e3dd0ea41069f64b0657fcbb4f8953710d2cd9`. الشجرة المحلية على `f2e01213` (الفرع `claude/exciting-shannon-4mh2ok`)، وهي مطابقة لـ main في `app` و`Modules` و`routes` و`config` و`database` و`bootstrap` و`tests`.
- `lavkooapp`: main هو `eedbbb8fd0303aa9fed5d4ff0ac45b3a409abf7a`.
- `lavkooappseller`: main هو `9ea64232683a6b415fb504e3d0a740a2e5a53306`. الفحص أُجري على نسخة مؤقتة من main، والاستشهادات محلية على `8ea0092`، وكل سطر مختلف يُذكر صراحة بـ `(main@9ea6423)`.

## 1. النطاق

- الفجوات المعلنة في أقسام "فجوات التغطية" و"أسئلة مفتوحة" و"مخاوف/مخاطر/بنود متبقية" في الأدلة E01 إلى E11 داخل `evidence/`، وهي في القسم 9 (سجل التحقق المستقل) والقسم 7 (الأسئلة المفتوحة). ملاحظة: عنوان "مخاوف باقية" لا يظهر في E01 إلى E11، وما يعادله فيها "المخاطر المتبقية" أو "مخاوف متبقية" أو "بنود باقية" أو "ملاحظات متبقية" حسب الملف.
- الفحوص المطلوبة: (أ) الجداول بلا migration، (ب) حالة CI في المستودعات الثلاثة، (ج) عدد فروقات المسارات وثوابت التطبيق غير المتحقق منها في E22.
- لم يُفتح أي ملف تحت `gcc-audit/restricted/`. الإشارة إلى البنود المقيّدة بمعرّفات SEC فقط.
- E12 إلى E23 خارج هذه الجولة، وما ورد منها مُحال إلى الدوسيه المختص.

## 2. المنهج

- **القراءة فقط:** `git show` و`git grep` و`git ls-tree` و`git archive` (إلى نسخة مؤقتة في `followup/snap-03a`، خارج المستودعات)، و`ls-remote`، و`gh api` / `gh run list` / `gh run view` للقراءة فقط على المستودعات الثلاثة المسموحة.
- **سكربتات الفحص:** `followup/work-03a/tablecheck.py` و`tablecheck2.py` (تحليل نصي لملفات PHP، لا تنفيذ). لم يُشغَّل أي ملف من المستودعات.
- **الفئة (فئة الفجوة نفسها):** VERIFIED إن ثبتت الفجوة بالفحص الساكن (غياب، أو قراءة لم تُجرَ أصلاً، أو تناقض بين مصدرين)؛ INFERRED إن استُنتجت من أدلة غير مباشرة؛ UNKNOWN إن لا تُحسم إلا ببيانات الإنتاج أو تشغيل.
- **الدرجة:** P0 (يمنع الإطلاق أو يحمل خطر خسارة مالية أو تسريباً بين المتاجر أو خطراً ضريبياً أو فساداً لبيانات سعودية)، P1 (يُحل داخل تنفيذ GCC)، P2 (نظافة)، INFO.
- **الحالة:**
  - `CI` مُغلق بالفحص الساكن أو بقراءة بيانات GitHub.
  - `OD` مفتوح، يلزم بيانات المالك (إنتاج أو إعدادات أو سجلات).
  - `ST` مفتوح، يلزم اختبار staging يأذن به المالك.
  - `SD` مفتوح، يمكن إغلاقه بفحص ساكن ولم يُنفَّذ في هذه الجولة (أُدرج بوضوح ولم يُدَّعَ إغلاقه).
  - `SX` مُحال إلى مدقّق الأمن (agent E) أو صاحب الأمن، ولا تفاصيل هنا.
  - `OX` قرار مالك أو قرار تصميم أو رأي قانوني.
  - `DL` مُفوَّض إلى دوسيه آخر.
- **الترقيم:** `G1-<دوسيه>-<رقم>`، و`G1-X-<رقم>` للفجوات المتقاطعة.

## 3. ملخص الأعداد

| البند | العدد |
|---|---|
| إجمالي الفجوات المسجَّلة | 149 |
| مُغلقة بالفحص (CI) | 20 |
| مفتوحة: تحتاج بيانات المالك (OD) | 61 |
| مفتوحة: تحتاج اختبار staging (ST) | 9 |
| مفتوحة: فحص ساكن مؤجَّل (SD) | 33 |
| مُحالة للأمن ومقيَّدة (SX) | 12 |
| قرار مالك (OX) | 12 |
| مُفوَّضة لدوسيه آخر (DL) | 2 |

توزيع الدرجات: P0 = 5، P1 = 77، P2 = 64، INFO = 3.

الملاحظة: أُدرج الجزء المغلق جزئياً ضمن SD مع بيان الجزء المغلق في الوصف، ولم يُحسب مغلقاً. الأعداد تُحسب من الجدول مباشرة.

## 4. جدول الفجوات

| ID | الدوسيه | الفجوة | الفئة | الدرجة | السبب | طريقة التحقق / الدليل | الحالة |
|---|---|---|---|---|---|---|---|
| G1-E01-01 | E01 | التحقق من جدول المسارات الفعلي غير منفَّذ، وأرقام المسارات عدّ ثابت؛ عدّ Route verbs للإدارة بنمط أضيق يعطي 954 لا 808–814 | UNKNOWN | P2 | أرقام العدّ تختلف حسب طريقة العدّ | تشغيل route:list على بيئة غير إنتاجية بتفويض المالك | ST |
| G1-E01-02 | E01 | أجسام متحكمات بوابات الدفع لم تُفحص في E01 | VERIFIED | P1 | تغطية مدقّق الدفع (E10) | مُفوَّضة إلى G1-E10-10 وG1-E10-14 | DL |
| G1-E01-03 | E01 | قيم الإنتاج لـ QUEUE_CONNECTION وCACHE_DRIVER وSESSION_DRIVER وSESSION_DOMAIN وAPP_MODE، وإعداد cron وخادم الويب، غير معروفة | UNKNOWN | P0 | تحدد هل الطوابير متزامنة وهل المجدول يعمل، وهو شرط لأي تسوية يومية | قراءة .env وcrontab وإعداد الخادم للقراءة فقط بتفويض المالك | OD |
| G1-E01-04 | E01 | vendor/ غير موجود في المستودع، فلا تُفحص الحزم المثبتة فعلياً | UNKNOWN | P2 | حزم الإنتاج قد تختلف عن composer.lock | تثبيت الحزم في بيئة staging وفحص النسخ | ST |
| G1-E01-05 | E01 | ملف mySpecs.html في جذر المستودع (نحو 1090 سطراً و103 كيلوبايت) لم يُقرأ؛ لم يُعدَّ سوى عدد حقول الجرد | VERIFIED | P2 | محتوى توثيقي غير مُراجَع | قراءة ساكنة لاحقة | SD |
| G1-E01-06 | E01 | المستودع القديم pharmacyksa98 غير متاح للتحقق (U2) | UNKNOWN | P1 | لا يمكن إثبات أصل الجداول والمسارات الموروثة | لم تُجرَ أي محاولة وصول لأن قواعد هذه الجولة تمنع لمسه؛ يحتاج صلاحية المالك | OD |
| G1-E01-07 | E01 | عدّ استخدامات Modules\Auction (158 موضعاً في 33 ملفاً) لم يُعَد | VERIFIED | P2 | الرقم مدخل في وصف وحدة المزادات | git grep على شجرة lavkoo@55e3dd0 خارج docs: 158 موضعاً في 33 ملفاً، مطابق | CI |
| G1-E01-08 | E01 | عدد الجداول التي تستعمل النماذج بلا migration إنشاء (نحو 110 و120) لم يُعَد | INFERRED | P1 | الرقم يدخل في G1-E03-01 وتحديد حجم المخطط غير المتتبَّع | سكربت ساكن على أرشيف الشجرة (القسم 5)؛ النتيجة: 115 جدولاً مرتبطاً بنماذج بلا إنشاء، منها 34 بتعديل فقط | CI |
| G1-E01-09 | E01 | تعليق BootstrapTestSchema يذكر 307 migrations، والعدد الحالي 350 | VERIFIED | P2 | التعليق متأخر عن الواقع | عدّ ملفات database/migrations (336) وModules/*/database/migrations (14) | CI |
| G1-E01-10 | E01 | عدّ seller_can/seller_owner (228 مساراً) لم يُعَد بالمجموعات | UNKNOWN | P2 | العدّ يعتمد على تمدد المجموعات | الظهور الخام في routes = 66 (لم يُعَد التمدد) | SD |
| G1-E01-11 | E01 | رقم 'نحو 4,700 اختبار' مصدره تعليق في ci.yml ولم يُشغَّل | UNKNOWN | P1 | عدد الناجح لا يثبته إلا تشغيل | عدّ دوال test_ = 4821 في 316 ملفاً (عدّ ساكن فقط)؛ نتيجة التشغيل من CI | ST |
| G1-E01-12 | E01 | من تسعة مجلدات خدمات فرعية فُحص ارتباط المستودعات بالواجهات في سبعة فقط | VERIFIED | P2 | تغطية جزئية | قراءة المجلدين الباقيين | SD |
| G1-E02-01 | E02 | محتوى resources/views وresources/themes لم يُقرأ (خارج النطاق المعلن) | VERIFIED | P2 | عرض لا يحدد مبالغ | قراءة مركزة لاحقاً | SD |
| G1-E02-02 | E02 | أعداد الأحداث والمستمعين الفعلية لم تُعَد؛ الافتراضي في config/queue.php مقروء | VERIFIED | P2 | تكرار الحدث يُحسب بالتشغيل | config/queue.php:16 قُرئ (الافتراضي sync)؛ العدّ لم يُنفَّذ | SD |
| G1-E02-03 | E02 | صفوف جرد الكاش المأخوذة بالعينة لم تُفحص سطراً سطراً | VERIFIED | P2 | جرد وصفي | مراجعة الصفوف الباقية | SD |
| G1-E02-04 | E02 | PaymentConfigProvider (الأسطر 89–120) لم يُفتح في E02 | VERIFIED | P2 | قراءة إعدادات البوابات من صفوف قاعدة البيانات؛ الصفوف الغائبة تُعالَج بلا فحص للقيمة الفارغة (P1 إن غابت الصفوف في الإنتاج) | قُرئ الكتلة: ثلاث كتل تستدعي ->first() ثم تفكّ json من $data['value'] دون فحص null (PaymentConfigProvider:89–120) | CI |
| G1-E02-05 | E02 | القيم الإنتاجية لـ currency_model وsystem_default_currency وdecimal_point_settings وصفوف currencies غير معروفة | UNKNOWN | P0 | تحدد وحدة كل المبالغ المخزَّنة وشرط قرار العملة في GCC؛ خطر فساد بيانات سعودية | استعلام قراءة فقط على business_settings وcurrencies بتفويض المالك | OD |
| G1-E02-06 | E02 | وجود جدول exchange_rate_logs في الإنتاج | UNKNOWN | P2 | الكود يكتب إليه والمستودع لا يحوي migration له | المستودع: لا إنشاء ولا تعديل (تحقق a)؛ الإنتاج: فحص المخطط | OD |
| G1-E02-07 | E02 | إعدادات الكاش والجلسة والـ CDN والـ Vary وعدد عمال الطابور في الإنتاج | UNKNOWN | P2 | تحدد خطر session() بين الوظائف وصلاحية الكاش | ملف .env وإعداد supervisor والاستضافة بتفويض المالك | OD |
| G1-E02-08 | E02 | SEC-E02-07 وSEC-E02-08 تحتاجان مراجعة مالك الأمان؛ لم تُختبرا تشغيلياً | VERIFIED | P1 | بند أمني مقيّد | مُحالة إلى agent E وصاحب الأمن؛ لم تُفتح الملحقات | SX |
| G1-E02-09 | E02 | جرد مفاتيح business_settings (209 حرفياً و73 سياسة) لم يُعَد بالكامل | INFERRED | P2 | عدّ وصفي | إعادة عدّ لاحقة | SD |
| G1-E02-10 | E02 | أثر التفريغ الشامل للكاش على بيانات الإنتاج لم يُختبر | UNKNOWN | P2 | سلوك وقتي | اختبار staging بتفويض المالك | ST |
| G1-E03-01 | E03 | عدد الجداول بلا migration (71) لم يُعَد؛ القياس الجديد يعطي 78 جدولاً مرتبطاً بنماذج بلا إنشاء ولا تعديل، و113 اسماً إجمالاً مع 35 اسماً مرتبطاً بالكود فقط | VERIFIED | P1 | الرقم المرجعي لا يطابق القياس؛ تعريف الدوسيه غير مذكور | سكربت ساكن (القسم 5 و7-أ) | CI |
| G1-E03-02 | E03 | المخطط الفعلي للإنتاج (أنواع الأعمدة والفهارس والمفاتيح وAUTO_INCREMENT للطلبات) غير معروف | UNKNOWN | P1 | شرط قبل أي تنفيذ يمس البيانات | SHOW CREATE TABLE من نسخة مطابقة بتفويض المالك | OD |
| G1-E03-03 | E03 | وجود الجداول بلا migration في الإنتاج | UNKNOWN | P1 | يحدد إن كانت الجداول من الـ dump أم غائبة | فحص مخطط الإنتاج بتفويض المالك | OD |
| G1-E03-04 | E03 | أثر حلقة backfill لـ products.shop_id على بيانات الإنتاج | UNKNOWN | P1 | يحدد صحة ربط المنتج بالمتجر | استعلام مطابقة قراءة فقط بتفويض المالك | OD |
| G1-E03-05 | E03 | هوية البائع: عالمية تبيع في أكثر من متجر أم مسجَّلة لكل دولة (قرار المالك) | VERIFIED | P1 | يحدد بنية shop↔storefront | قرار مالك | OX |
| G1-E03-06 | E03 | تكرار SKU/barcode عبر المتاجر: القرار المعتمد يوحي بالسماح، وقواعد التحقق الحالية تمنعه | VERIFIED | P1 | تعارض بين القرار المعتمد والكود (يُعرض فقط، لا يُعاد فتح القرار) | قرار مالك؛ الاستشهادات في القسم 4 من E03 | OX |
| G1-E03-07 | E03 | الجداول الإرثية للمحافظ (customer_wallets وhistories وseller_wallet_histories وadmin_wallet_histories وpaytabs_invoices وdelivery_syria_*) هل تحوي بيانات حية | UNKNOWN | P1 | بيانات تاريخية قد تُنقل | قراءة الإنتاج بتفويض المالك | OD |
| G1-E03-08 | E03 | عدّ أعمدة المال (187 و132) لم يُعَد | UNKNOWN | P2 | عدّ داخلي | 310 تعريف decimal/double/float في migrations (عدّ عام، غير قابل للمقارنة المباشرة) | SD |
| G1-E03-09 | E03 | مصدر عمود customer_wallets.royalty_points غير متحقق منه في الـ migrations | VERIFIED | P2 | لا يظهر في migrations ولا في Modules | بحث royalty في database وModules: لا نتيجة | CI |
| G1-E03-10 | E03 | مسارات مرتبطة ببندين أمنيين (عرض التقييمات وواجهة POS للبائع) لم تُتحقق | UNKNOWN | P1 | مرتبطة بالملحق المقيّد | مُحالة إلى agent E؛ لا تفاصيل هنا | SX |
| G1-E03-11 | E03 | SEC-E03-02: تم التحقق من 6 من 7 مواقع فقط | VERIFIED | P1 | بند أمني مقيّد | مُحالة إلى agent E | SX |
| G1-E03-12 | E03 | installation/backup/database.sql (الـ dump) غير متتبَّع، فلا يمكن إعادة بناء المخطط الأساسي من المستودع | VERIFIED | P1 | CLAUDE.md يشترط استيراد الـ dump قبل migrate | .gitignore:53 وتحقق من ملفات installation المتتبَّعة (ملفان نصيان فقط)؛ الملف عند المالك | OD |
| G1-E04-01 | E04 | مطابقة Σ(credit+admin_bonus−debit) مع رصيد كل عميل لم تُجرَ | UNKNOWN | P0 | خطر خسارة مالية أو تضخم أرصدة | استعلام مطابقة قراءة فقط على نسخة بتفويض المالك | OD |
| G1-E04-02 | E04 | قيد التفرد على users.email وusers.phone | VERIFIED | P1 | تكرار الحسابات يقسم المحفظة (المستودع لا يعرّفه) | migrations: لا unique على users؛ الوحيد على delivery_men.phone (create ثم dropUnique)؛ الإنتاج يتبع G1-E04-03 | CI |
| G1-E04-03 | E04 | عدد الحسابات المكررة في الإنتاج | UNKNOWN | P1 | يحدد حجم التكرار | استعلام تكرار قراءة فقط بتفويض المالك | OD |
| G1-E04-04 | E04 | الترحيلات المطبقة فعلاً (دقة الأعمدة وجداول العناوين) وقيمة unified_address_mode | UNKNOWN | P1 | شرط لأي حكم على الدقة العشرية | جدول migrations وbusiness_settings في الإنتاج | OD |
| G1-E04-05 | E04 | أرصدة customer_wallets غير الصفرية، وصفوف earned_by_referral المكررة، وطلبات pay_by_wallet الملغاة بلا استرداد | UNKNOWN | P1 | أثر مالي محتمل | استعلامات قراءة فقط بتفويض المالك | OD |
| G1-E04-06 | E04 | قيم app.mode وإعدادات login_setups في الإنتاج | UNKNOWN | P2 | يحدد تفعيل التحقق بالهاتف والبريد | ملف .env وجدول login_setups بتفويض المالك | OD |
| G1-E04-07 | E04 | أرصدة وأدفاتر الخلفية القديمة قبل النقل | UNKNOWN | P1 | بيانات موروثة | المستودع القديم غير متاح (G1-E01-06) | OD |
| G1-E04-08 | E04 | مدة صلاحية توكنات Passport وإعداد oauth_clients | UNKNOWN | P2 | المستودع لا يحدد القيمة صراحة | grep لـ tokensExpireIn وPassport:: في app وconfig وbootstrap وModules: لا نتيجة؛ القيمة تتبع إعداد الإطار والإنتاج | OD |
| G1-E04-09 | E04 | بأي وحدة أساس تُثبَّت الأرصدة السعودية التاريخية إن كانت غير SAR | VERIFIED | P1 | قرار أعمال بعد G1-E02-05 | قرار مالك | OX |
| G1-E04-10 | E04 | سياق المتجر الذي يرسله تطبيق العميل لإعادة رصيد المتجر الصحيح غير مصمَّم | VERIFIED | P1 | فجوة تصميم بين الـ API والتطبيق | تصميم مشترك مع فريق API وFlutter | OX |
| G1-E04-11 | E04 | فرع المحفظة في بوابات غير Paymob وMyFatoorah لم يُفحص | VERIFIED | P1 | رموز المحفظة موجودة في متحكم Stripe وPayment trait وCustomer PaymentController | قراءة الفروع المعنية | SD |
| G1-E04-12 | E04 | فرع Apple في الدخول الاجتماعي لم يُفحص كاملاً (SEC-E04-13) | UNKNOWN | P1 | بند أمني مقيّد | مُحالة إلى agent E | SX |
| G1-E04-13 | E04 | مقاطع RestAPI/v2 وRestAPI/v3 وWeb/UserProfileController قُرئت جزئياً (81 سطراً فيها رموز محفظة) | VERIFIED | P2 | تغطية جزئية | قراءة المقاطع الباقية | SD |
| G1-E04-14 | E04 | المسار existing-account-check معرَّف مرتين في الملف نفسه تحت متحكمين؛ ترتيب الأسبقية غير مثبت | VERIFIED | P2 | التعريف المكرر حقيقة؛ الأسبقية INFERRED (سلوك الإطار) | routes/rest_api/v1/api.php:135 و160؛ تأكيد الأسبقية بـ route:list على staging | ST |
| G1-E05-01 | E05 | محتوى admin_roles في الإنتاج ومفاتيح قديمة بعد إعادة التسمية | UNKNOWN | P1 | يحدد الصلاحيات الفعلية | قراءة جدول الإنتاج بتفويض المالك | OD |
| G1-E05-02 | E05 | وجود audit_logs وapproval_requests وapproval_decisions وseller_staff وseller_roles وseller_api_keys في الإنتاج | UNKNOWN | P1 | لا إنشاء ولا تعديل لأي منها في المستودع (تحقق a)؛ الإنتاج غير معروف | فحص مخطط الإنتاج بتفويض المالك | OD |
| G1-E05-03 | E05 | عدد المشرفين وموظفي البائعين وحجم سجل التدقيق | UNKNOWN | P2 | حجم | قاعدة الإنتاج بتفويض المالك | OD |
| G1-E05-04 | E05 | قيم settlement_maker_checker وعتبة الموافقة المزدوجة وemployee_login_url في الإنتاج | UNKNOWN | P1 | الكود يعامل القيمة الغائبة كـ off | SettlementController:150 و153 (القيمة الغائبة = off)؛ القيمة الفعلية بتفويض المالك | OD |
| G1-E05-05 | E05 | هل شُغّل الـ seeder الافتراضي للمشرف وهل تغيّرت قيمه في الإنتاج | UNKNOWN | P1 | حساب إداري افتراضي؛ تُفحص الحالة لا القيم | جدول admins في الإنتاج بتفويض المالك (دون نقل أي قيمة) | OD |
| G1-E05-06 | E05 | وحدة Auction غير موجودة في المستودع رغم إعلانها true، ونقاط استدعاء كثيرة تعتمد عليها | VERIFIED | P1 | الاستدعاءات محمية بفحص حالة الإضافة في ثلاثة مواقع من سبعة ملفات مفحوصة؛ حالة الخادم غير معروفة | modules_statuses.json:6؛ module-helper.php:694–711؛ القسم 5 | OD |
| G1-E05-07 | E05 | تطبيق البائع: معالجة رموز الرفض owner_only وpermission، ودعم دخول الموظفين | VERIFIED | P1 | لا يوجد owner_only في lib؛ permission حقل JSON فقط؛ لا مسار دخول موظفين في features/auth | بحث ساكن في seller-main (main@9ea6423)؛ القسم 5 | CI |
| G1-E05-08 | E05 | قرار: هل يبقى المشرف الرئيسي شاملاً لكل الدول أم يُستحدث مشرف كيان | VERIFIED | P1 | GCC-14 لا يحسم نصاً | قرار مالك | OX |
| G1-E05-09 | E05 | مواضع الإخفاء (61) وgetFirstWhere (78) فُحصت بالعينة | VERIFIED | P2 | نطاق الـ78 غير معرَّف في الدوسيه؛ getFirstWhere في app/: 842 موضعاً | إعادة عدّ بنطاق محدد لاحقاً | SD |
| G1-E05-10 | E05 | SellerApiKeyService وCustomerWalletService وApprovalEngine::open وAddFundRequest لم تُقرأ | VERIFIED | P1 | مسارات موافقة ومحفظة | قراءة ساكنة لاحقة | SD |
| G1-E05-11 | E05 | SEC-E05-03 (P0) في مسار إضافة إدارة وإضافات | VERIFIED | P0 | بند أمني مقيّد بدرجة P0 | مُحالة إلى agent E وصاحب الأمن عبر القناة الخاصة؛ لا تفاصيل هنا | SX |
| G1-E05-12 | E05 | SEC-E05-04 فُحص بعينة من أربعة مواضع؛ SEC-E05-08 يتعلق باكتمال مسارات كتابة المخزون على القناة الويب | VERIFIED | P1 | بنود أمنية مقيّدة | مُحالة إلى agent E؛ الربط بالمخزون في G1-E11-15 | SX |
| G1-E06-01 | E06 | قيم إنتاج الإعدادات: مفاتيح الإشعارات وFCM والبريد ومزوّد SMS وzender وChatwoot وdelivery_syria وروابط التطبيق والصيانة والكوكيز وتذكير السلة | UNKNOWN | P1 | تحدد سلوك القنوات | قراءة الإنتاج بحجب الأسرار بتفويض المالك | OD |
| G1-E06-02 | E06 | مخطط الجداول الـ16 غير المتتبَّعة | UNKNOWN | P1 | المستودع لا ينشئها (تحقق a) | SHOW CREATE TABLE من الإنتاج بتفويض المالك | OD |
| G1-E06-03 | E06 | النطاق الإنتاجي الفعلي (APP_URL) ومحتوى .well-known وrobots وsitemap المخدَّم | UNKNOWN | P2 | المستودع يذكر نطاقين: config/system-addons.php:10 وconfig/deeplinks.php:34 | ملف .env وقراءة الخادم بتفويض المالك | OD |
| G1-E06-04 | E06 | وجود config/analytics.php وmonitoring.php وtelemetry.php على الخادم | UNKNOWN | P2 | غير متتبَّعة في المستودع (ls config: لا نتيجة) | فحص الخادم بتفويض المالك | OD |
| G1-E06-05 | E06 | تطبيق المندوب غير موجود ضمن المستودعات الثلاثة، واشتراكه في topic الصيانة وحالته | UNKNOWN | P2 | مستودع خارج النطاق | تأكيد المالك | OD |
| G1-E06-06 | E06 | هل توجد طرود فعلية في delivery_syria_parcels | UNKNOWN | P2 | بيانات تاريخية | قراءة الإنتاج بتفويض المالك | OD |
| G1-E06-07 | E06 | لغة بريد الطابور (E06-F17) | UNKNOWN | P2 | يحتاج اختباراً تشغيلياً | اختبار staging بتفويض المالك | ST |
| G1-E06-08 | E06 | ملف AASA يحوي /track-order/* بينما config/deeplinks.php يعلن أن /track-order محذوف عمداً | VERIFIED | P2 | تناقض بين مصدرين متتبَّعين وأثره على فتح الروابط في التطبيق | قراءة public/.well-known/apple-app-site-association وconfig/deeplinks.php:30–36 | CI |
| G1-E06-09 | E06 | قرار: تحويل عناوين السعودية إلى /sa/ مع 301، أم خدمة المتجر الافتراضي على الجذر | VERIFIED | P1 | يمس كل الروابط المنشورة والإصدارات الحالية | قرار مالك | OX |
| G1-E06-10 | E06 | هل شروط البائع وثيقة مستقلة عن شروط العميل | VERIFIED | P2 | المحتوى واحد للتطبيقين اليوم | قرار مالك | OX |
| G1-E06-11 | E06 | عدّ استدعاءات route( في §2.14 لم يُعَد | UNKNOWN | P2 | عدّ وصفي | إعادة عدّ لاحقة | SD |
| G1-E06-12 | E06 | التقييم القانوني لسجلات الموافقة التسويقية خارج نطاق التدقيق | UNKNOWN | P1 | رأي قانوني مطلوب | رأي قانوني من المالك؛ لا يُحسم ساكناً | OX |
| G1-E07-01 | E07 | المخطط الفعلي للجداول التسعة للثيم وأعمدة banners الحديثة | UNKNOWN | P1 | المستودع لا ينشئ الجداول الخمسة التي فُحصت بالاسم (themes وtheme_versions وtheme_sections وtheme_blocks وtheme_assets) | تحقق a لأسماء الجداول؛ الإنتاج بتفويض المالك | OD |
| G1-E07-02 | E07 | هل نُشر ثيم فعلاً في الإنتاج وكم نسخة وحملة وتجربة | UNKNOWN | P2 | بيانات حية | عدّ صفوف الإنتاج بتفويض المالك | OD |
| G1-E07-03 | E07 | 'theme tests' و'the seeder' المذكوران في التعليقات | VERIFIED | P2 | لا ملف اختبار اسمه Theme ولا seeder للثيم في الشجرة الحالية؛ المستودع القديم غير متاح | بحث بالاسم في tests وdatabase وapp؛ القسم 5 | CI |
| G1-E07-04 | E07 | سجلات الوصول: هل ما زالت builds تستعمل /theme/sections وapp_carousel_settings | UNKNOWN | P2 | استعمال خارجي | سجلات الوصول بتفويض المالك | OD |
| G1-E07-05 | E07 | قرار: نشر منفصل للويب وللتطبيق داخل المتجر الواحد (GCC-09) | VERIFIED | P1 | قرار تصميمي | قرار مالك | OX |
| G1-E07-06 | E07 | قرار: عند نسخ ثيم بين دولتين تُنسخ الـ banners والأصول أم تُشارَك (GCC-10) | VERIFIED | P1 | الكود يشاركها ضمناً | قرار مالك | OX |
| G1-E07-07 | E07 | منطق SectionDataResolver لكل نوع، و37 قالب قسم، وcacheRemoveByType، وExperienceHealth، وCommerceCampaignsTick لم تُفحص دلالياً | VERIFIED | P2 | تغطية جزئية؛ resources/themes/default/web-views فيه 144 ملفاً، والـ37 لم تُعَد | قراءة لاحقة؛ جزء مُغلق: SectionDataResolver بلا استدعاءات Cache | SD |
| G1-E07-08 | E07 | المنطقة الزمنية للإنتاج (business_settings.timezone) وأثرها على النوافذ المجدولة | UNKNOWN | P2 | يؤثر على الجداول الزمنية | قراءة الإنتاج بتفويض المالك (يتقاطع مع G1-E01-03) | OD |
| G1-E07-09 | E07 | منطق dynamic_theme داخل تطبيق العميل | VERIFIED | P2 | نطاق E17 | تغطية E17 | DL |
| G1-E08-01 | E08 | نقاط كتالوج تطبيق العميل ثابتة بلا معامل سوق | VERIFIED | P2 | الثوابت لا تحمل معامل سوق | lavkooapp:lib/utill/app_constants.dart:46–51 و92–94 (eedbbb8) | CI |
| G1-E08-02 | E08 | كاش استجابات الكتالوج في تطبيق العميل وشاشات إضافة المنتج والقسائم في تطبيق البائع لم تُفحص | VERIFIED | P2 | تغطية جزئية | lavkooapp:lib/features/product/domain/repositories/product_repository.dart:49 وقراءة شاشات البائع لاحقاً | SD |
| G1-E08-03 | E08 | مسارات POS (التسعير والخصم والقسيمة) خارج الفحص | VERIFIED | P1 | مسار أسعار ومبالغ | قراءة ساكنة لاحقة | SD |
| G1-E08-04 | E08 | مفاتيح كاش SectionDataResolver | VERIFIED | P2 | الكاش يُحدَّد في مكان آخر | app/Services/Theme/SectionDataResolver.php (954 سطراً): لا استدعاء Cache أو cache( أو remember؛ مالك الكاش غير معروف | CI |
| G1-E08-05 | E08 | بقية app/Services/Commerce (9 ملفات) وSeoTemplateRenderer وRedirectResolverService وSeoAuditService | VERIFIED | P2 | تغطية جزئية | قراءة لاحقة | SD |
| G1-E08-06 | E08 | أجسام متحكمات العلامات والسمات والمراجعات والتصنيفات الفرعية والتصفية وDeal of the day وFeatured deal وMost demanded وRestAPI/v2/seller ProductController | VERIFIED | P2 | تغطية جزئية | قراءة لاحقة | SD |
| G1-E08-07 | E08 | عروض مرتبطة بالسعر خارج الجداول المفحوصة (free_delivery_over_amount وrefer_and_earn_discount وخصم POS) بلا عملة | VERIFIED | P1 | مبالغ تُحال إلى ملاحق الطلبات والولاء | عدّ ساكن: free_delivery_over_amount في 31 موضعاً من app/؛ الدلالة لم تُقرأ | SD |
| G1-E08-08 | E08 | وحدة المزادات AuctionProduct مشار إليها وغير موجودة في المستودع | VERIFIED | P1 | 17 سطر استدعاء في 7 ملفات يشير إلى صنف غير موجود في الشجرة | git grep 'AuctionProduct::' وتحقق من الصنف؛ حالة الخادم في G1-E05-06 | CI |
| G1-E08-09 | E08 | إشعارات إعادة التوفير والبحث الأخير (restock_products وrecent_searches وsearch_functions) لم تُحلَّل عند تعدد المتاجر | VERIFIED | P2 | نطاق متعدد المتاجر | قراءة لاحقة | SD |
| G1-E08-10 | E08 | تصدير واستيراد Excel للبائع وقوالبه وأثر الأعمدة الجديدة | VERIFIED | P2 | قوالب منشورة | قراءة لاحقة | SD |
| G1-E08-11 | E08 | قوالب Blade وJavaScript لم تُقرأ (مواضع تنسيق السعر غير محصورة) | VERIFIED | P2 | تنسيق سعر في الواجهة | قراءة لاحقة | SD |
| G1-E08-12 | E08 | ProductManager.php الأسطر 1160–1579 و1718–2060 لم تُقرأ | VERIFIED | P2 | مسار منتج كبير | قراءة لاحقة | SD |
| G1-E08-13 | E08 | SEC-E08-06: شكل JSON للمسألة غير مؤكد تشغيلياً | UNKNOWN | P1 | بند أمني مقيّد | مُحالة إلى agent E؛ اختبار staging بتفويض المالك | SX |
| G1-E08-14 | E08 | SEC-E08-08: معالجة القسيمة في POS ضمن API البائع v3 | UNKNOWN | P1 | بند أمني مقيّد | مُحالة إلى agent E | SX |
| G1-E08-15 | E08 | استعمال API البائع v2 فعلياً (F22) | UNKNOWN | P2 | يتوقف على نسخ التطبيق المنشورة | سجلات الوصول ونسخ التطبيق بتفويض المالك | OD |
| G1-E08-16 | E08 | قيم الإنتاج: currency_model وbusiness_mode وlanguage وpnc_language وعدد coupons وflash_deals | UNKNOWN | P1 | تحدد السلوك الفعلي | قراءة الإنتاج بتفويض المالك | OD |
| G1-E09-01 | E09 | مبلغ الدفع الإلكتروني هو مجموع order_amount_with_tax ولا يطرح خصم الإحالة؛ الخصم يخفّض وعاء الضريبة فقط ويُطرح لاحقاً من order_amount المخزَّن، ولا توجد خطوة تسوية تطرحه | VERIFIED | P1 | P0 إذا تحقق الشرطان معاً: (1) البوابة تحصّل الإجمالي دون طرح الخصم، وهذا متحقق بالكود؛ (2) عدم تمويل الخصم من جهة أخرى، وهذا UNKNOWN ويلزم بيانات التسوية والاسترداد من المالك. تبقى P1 حتى تتوفر تلك البيانات؛ الأثر المالي INFERRED | lavkoo:app/Http/Controllers/Customer/PaymentController.php:379–383 (المجموع) و:419 و:434 و:449 (المبلغ الممرَّر)؛ lavkoo:app/Utils/OrderManager.php:939–943 (حساب الخصم) و:988–1014 (وعاء الضريبة)؛ lavkoo:app/Utils/CartManager.php:1043–1075 (حساب الضريبة بعد طرح الخصم)؛ lavkoo:app/Utils/OrderManager.php:1082–1083 (المبلغ المخزَّن، عبر generateOrder من digital_payment_success في lavkoo:app/Utils/module-helper.php:133)؛ lavkoo:app/Services/Monitoring/Panels/PaymentsPanel.php:1379 و:1403 (مقارنة دون طرح)؛ التقارير تعامل خصم الإحالة كتكلفة إدارية (lavkoo:app/Services/Admin/Reports/EarningReportsService.php:92) | OD |
| G1-E09-02 | E09 | مولّد order_group_id لا يضمن التفرد بالبناء (أربعة أرقام عشوائية وخمسة أحرف وtime()) | INFERRED | P2 | المخاطر غير مقاسة؛ المولّد مشترك مع transaction_id | OrderManager:65–68 مستخدم في :1551 وChannelOrderMaterializer:149 | CI |
| G1-E09-03 | E09 | checkValidationForCheckoutPages (OrderManager:2778–2931) لم يُفحص سطراً سطراً | VERIFIED | P1 | تحقق الدفع قبل الطلب | قُرئ الهيكل (الحد الأدنى، السلة، المنتجات النشطة، المخزون، الإغلاق، توفر الشحن لكل جهة، البائعون غير المتاحين)؛ الدلالات الدقيقة لم تُراجع | SD |
| G1-E09-04 | E09 | SEC-E09-11: مفتاح كاش سلة الضيف وأثره على الأسعار | UNKNOWN | P1 | بند أمني مقيّد | مُحالة إلى agent E | SX |
| G1-E09-05 | E09 | وجود حل فوترة إلكترونية خارج المستودع | UNKNOWN | P0 | خطر ضريبي؛ الدوسيه يعدّه P0 | تأكيد المالك | OD |
| G1-E09-06 | E09 | حجم الفرق التاريخي في admin_wallets.pending_amount | UNKNOWN | P1 | أثر مالي تاريخي | استعلام مطابقة قراءة فقط بتفويض المالك | OD |
| G1-E09-07 | E09 | نظام الضريبة الإنتاجي (is_included والنسب) في system_tax_setups وtaxes | UNKNOWN | P1 | يحدد حساب الضريبة | جداول الإنتاج بتفويض المالك | OD |
| G1-E09-08 | E09 | قيم shipping_method وzone_wise_shipping وfree_delivery_* وminimum_order_amount* وwallet_add_refund وinvoice_settings | UNKNOWN | P2 | إعدادات حية | business_settings الإنتاجية بتفويض المالك | OD |
| G1-E09-09 | E09 | هل يوجد بائعون غير الإدارة في الإنتاج (seller_is) | UNKNOWN | P2 | يحدد الاستعمال الفعلي لتقسيم البائعين | عدّ orders حسب seller_is في الإنتاج | OD |
| G1-E09-10 | E09 | المخطط الفعلي لـ orders وorder_details والترحيل DECIMAL(40,20) والجداول المرحلية | UNKNOWN | P1 | الترحيل موجود في الشجرة؛ تطبيقه على الإنتاج غير معروف | الترحيل 2026_05_07_000001 موجود؛ التطبيق بتفويض المالك | OD |
| G1-E09-11 | E09 | عبارة 'قرابة 70 ملفاً مقروءاً' بلا دليل، وسياق الاستشهادات في الأقسام 1 و5 و7 و8 لم يُعَد | VERIFIED | P2 | تغطية وصفية | إعادة مراجعة لاحقة | SD |
| G1-E10-01 | E10 | البوابات المفعّلة فعلياً في الإنتاج (is_active وmode) وsupported_country لـ Paymob | UNKNOWN | P1 | يحدد أي تدفق دفع حي | addon_settings بالأسماء والحالة فقط بتفويض المالك | OD |
| G1-E10-02 | E10 | قيم MYFATOORAH_ENABLED وMYFATOORAH_SCHEDULE_ENABLED وMYFATOORAH_CURRENCY وFULFILLMENT_ALLOW_MIXED_COD | UNKNOWN | P1 | إعدادات حية | ملف .env بالأسماء والقيم غير السرية بتفويض المالك | OD |
| G1-E10-03 | E10 | مخطط payment_requests وaddon_settings الفعلي؛ جداول payment_routing_rules وmtn_cash_terminals وmtn_cash_transactions غير منشأة في المستودع | VERIFIED | P1 | لا إنشاء ولا تعديل لها في المستودع (تحقق a)؛ الإنتاج غير معروف | SHOW CREATE TABLE من الإنتاج بتفويض المالك | OD |
| G1-E10-04 | E10 | قدرة كل مزوّد (Paymob وMyFatoorah وTabby وTamara) لكل سوق وعملة وكيان، وهل يلزم حساب وtoken ومفتاح webhook مستقل لكل دولة | UNKNOWN | P1 | شرط GCC متعدد الدول | تأكيد المزوّدين والمالك (مصفوفة القسم 4 من E10) | OD |
| G1-E10-05 | E10 | سلوك MyFatoorah في مسارَي البحث عن الفاتورة وطريقة توقيع أحداث الاسترداد | UNKNOWN | P1 | لا يُحسم ساكناً | تجربة sandbox موثَّقة بتفويض المالك | ST |
| G1-E10-06 | E10 | علاقة تكامل MyFatoorah الحالي بطلب الدمج #10 في المستودع القديم وبالتطبيق القديم | UNKNOWN | P1 | المستودع القديم غير متاح | G1-E01-06 | OD |
| G1-E10-07 | E10 | الاستردادات اليدوية عبر لوحات المزوّدين وكيفية مطابقتها محاسبياً | UNKNOWN | P1 | أثر مالي | إفادة المالك والمالية | OD |
| G1-E10-08 | E10 | Modules/Gateways غير موجود في المستودع (مُستثنى من git) ومُعلن true في modules_statuses.json؛ حالته على الخادم غير معروفة | VERIFIED | P1 | تفعيل مسارات الدفع يتوقف على حالة الإضافة على الخادم | lavkoo:modules_statuses.json:3؛ lavkoo:.gitignore:25–26 | OD |
| G1-E10-09 | E10 | ضوابط Stripe وRazorPay وbKash وPaytabs قُرئت ولم تُختبر | VERIFIED | P2 | اختبار يحتاج بيئة | اختبار staging بتفويض المالك | ST |
| G1-E10-10 | E10 | ConnectionTester.php وMyFatoorahResponse.php لم تُقرأ في E10 | VERIFIED | P2 | طبقة التحقق من الاستجابة | قُرئا: الاستجابة تُعد نجاحاً فقط عند 2xx مع IsSuccess صحيحاً؛ MyFatoorahResponse:61، ConnectionTester:27 | CI |
| G1-E10-11 | E10 | متحكم قواعد التوجيه كاملاً ولوحة المراقبة (فرعا طلب مدفوع بلا سجل وسجل استرداد بلا دفع) لم يُفحصا | VERIFIED | P2 | تغطية جزئية | قراءة لاحقة | SD |
| G1-E10-12 | E10 | SEC-E10-03: مصدر payment_id في MercadoPago | UNKNOWN | P1 | بند أمني مقيّد | مُحالة إلى agent E | SX |
| G1-E10-13 | E10 | متحكم تعديل الطلب في API v1 (أربع نقاط دفع): نطاق الوصول إلى الطلب مُحال للأمن | UNKNOWN | P1 | مسار دفع بعد تسليم الطلب | routes/rest_api/v1/api.php:461 وRestAPI/v1/OrderEditController.php:51؛ التفاصيل في الملحق المقيّد لا هنا | SX |
| G1-E10-14 | E10 | مسارات SSLCommerz وLiqPay وPaytm وPaymera وMTN Cash لم تُفحص تفصيلياً | VERIFIED | P2 | تغطية جزئية | قراءة لاحقة | SD |
| G1-E10-15 | E10 | Modules/doc.txt لم يُقرأ | VERIFIED | INFO | ملف من سطر واحد (33 بايت) بلا محتوى تشغيلي | قُرئ: 'All modules will be stored here.' | CI |
| G1-E10-16 | E10 | عدد الملفات المفحوصة 'نحو 78' تقدير لم يُعَد | UNKNOWN | INFO | عدّ وصفي | عدّ لاحق | SD |
| G1-E10-17 | E10 | ترحيلات MyFatoorah السبعة مطبَّقة في الإنتاج | UNKNOWN | P2 | حالة الإنتاج | جدول migrations بتفويض المالك | OD |
| G1-E11-01 | E11 | ترحيلات التنفيذ والمخزون مطبَّقة في الإنتاج، وFULFILLMENT_* وSALES_CHANNELS_ENABLED مفعّلة | UNKNOWN | P1 | يحدد أي مسار شحن حي | .env وجدول migrations بتفويض المالك | OD |
| G1-E11-02 | E11 | المزوّد المفعَّل وauto_dispatch والشحنات المفتوحة والمحجوزة | UNKNOWN | P1 | عمليات حية | integration_settings بالحالة فقط (بلا credentials) وprovider_fulfillments بتفويض المالك | OD |
| G1-E11-03 | E11 | مخطط جداول Stage C والجداول الأساسية الفعلي | UNKNOWN | P1 | شرط قبل التنفيذ | fulfillment:compatibility من الخادم أو SHOW CREATE TABLE بتفويض المالك | OD |
| G1-E11-04 | E11 | Delivery Syria مفعّل؟ وعمود shipping_methods.name وطريقة شحن باسم DeliverySyria | UNKNOWN | P2 | shipping_methods تعديل فقط في المستودع (تحقق a) | business_settings.delivery_syria ومخطط shipping_methods بتفويض المالك | OD |
| G1-E11-05 | E11 | تغطية Torod وLogesTechs وM5AZN لدول الخليج الأخرى، وحساباتها وعملاتها ومعرّفات الشركات | UNKNOWN | P1 | شرط توسع GCC | عقود المزوّدين وإعدادات الإنتاج بتفويض المالك | OD |
| G1-E11-06 | E11 | own_warehouse_source وصف inventory_locations في الإنتاج | UNKNOWN | P2 | تحدد مصدر المخزون | ملف .env وجدول inventory_locations بتفويض المالك | OD |
| G1-E11-07 | E11 | المستودع القديم وطلب الدمج القديم #11 (stock) | UNKNOWN | P1 | المستودع القديم غير متاح | G1-E01-06 | OD |
| G1-E11-08 | E11 | LogesTechs: تأكيد حقل المخزون ورجوع رقم الفاتورة (شرطا المزامنة والإرسال الحقيقي) | UNKNOWN | P1 | شرط تشغيلي للمزامنة | stock_field_confirmed_at وinvoice_round_trip في إعدادات المزوّد بتفويض المالك | OD |
| G1-E11-09 | E11 | حسابات القنوات لأسواق أخرى مرتبطة بقوائم في مواقع المخزون | UNKNOWN | P1 | ربط بين أسواق ومواقع | sales_channel_accounts بلا أسرار وsales_channel_product_mappings.stock_location_id بتفويض المالك | OD |
| G1-E11-10 | E11 | managed_dispatch_enabled وقواعد التوجيه fulfillment_routing_rules | UNKNOWN | P1 | يحدد التوجيه الحي | .env وجدول fulfillment_routing_rules بتفويض المالك | OD |
| G1-E11-11 | E11 | صيغة أرقام الهواتف في shipping_addresses (يحدد أثر E11-F04) | UNKNOWN | P2 | الصيغة لا القيم | عيّنة مجهَّلة للصيغة فقط بتفويض المالك | OD |
| G1-E11-12 | E11 | الاختبارات شغّلتها CI على 55e3dd0 وفشلت 5 من 21 وظيفة؛ سجلات الفشل لم تُقرأ، وCI على رأس main أحمر | VERIFIED | P1 | لا غطاء تشغيلي مؤكد | تشغيل CI من جهة مخوَّلة؛ حالة CI في G1-X-01 | ST |
| G1-E11-13 | E11 | التشغيلات والصلاحية (BatchService وBatchController وproduct_batches) لم تُقرأ | VERIFIED | P2 | مسار مخزون | قراءة لاحقة (E11-F20) | SD |
| G1-E11-14 | E11 | واجهة مخزون البائع v3 والتحديث بالجملة وميزة inventory في تطبيق البائع | VERIFIED | P2 | مسار مخزون | قراءة لاحقة | SD |
| G1-E11-15 | E11 | مواضع كتابة current_stock: تم تعدادها بنمط ساكن (25 ملفاً)، ودلالات كل سطر لم تُراجع | VERIFIED | P2 | التعداد مُغلق؛ الدلالة مفتوحة في الجولة اللاحقة | القسم 5 | CI |
| G1-E11-16 | E11 | أوامر الشراء والمرتجعات اللوجستية والتكاليف التشغيلية (PurchaseOrderService وReturnLogisticsService) | VERIFIED | P2 | مسار تكلفة | قراءة لاحقة | SD |
| G1-E11-17 | E11 | حساب تكلفة الشحن (shipping_types وcategory_shipping_costs وcart_shippings) والشحن المجاني ودورة المندوب ومحفظته | VERIFIED | P1 | مبالغ شحن | قراءة لاحقة | SD |
| G1-E11-18 | E11 | قوالب الإدارة وفئات Readiness/* وVerification/* وM5aznProductImporter وTorodCityDirectory | VERIFIED | P2 | تغطية جزئية | قراءة لاحقة | SD |
| G1-E11-19 | E11 | الملحق المقيّد: فُحصت عيّنة ثلاثة مواضع من أصل بنود كثيرة | VERIFIED | P1 | تغطية أمنية جزئية | مُحالة إلى agent E | SX |
| G1-E11-20 | E11 | وثيقة أمنية متتبَّعة في المستودع: قرار الإبقاء عليها أو نقلها | UNKNOWN | P1 | قرار ملكية | قرار مالك | OX |
| G1-E11-21 | E11 | معايرة درجات E11-F02 وE11-F16 (P1 مع شرط الارتفاع إلى P0) | VERIFIED | INFO | قرار تجميع | قرار التقرير الجامع | OX |
| G1-X-01 | E01/E11 (cross) | CI على رأس lavkoo main 55e3dd0 أحمر: تشغيل CI فشل، وخمس وظائف من 21 فشلت (اختبارات SQLite وStrict-mode وSales-channel N-Z على MySQL وMariaDB)، وفي آخر 12 تشغيلاً: 4 فشل و8 ملغاة و0 نجاح | VERIFIED | P1 | الاختبارات ليست خضراء؛ سبب الفشل غير مُحلَّل | بيانات GitHub Actions للقراءة فقط (gh run list وgh run view)؛ السجلات تحتاج صلاحية أو تحليلاً لاحقاً | CI |
| G1-X-02 | E01/E11 (cross) | CI على رأس lavkooappseller main 9ea6423 أحمر: وظيفة analyze-and-test فشلت؛ build-android تخطّت؛ آخر 9 تشغيلات: 6 فشل و3 نجاح | VERIFIED | P1 | لا بوابة خضراء على تطبيق البائع | gh run list وgh run view للقراءة فقط | CI |
| G1-X-03 | E01/E11 (cross) | تطبيق العميل lavkooapp بلا CI: لا مجلد .github ولا تشغيلات | VERIFIED | P1 | لا بوابة آلية على تطبيق العميل قبل الدمج | غياب .github في eedbbb8 ومسار main على GitHub (404)، وgh run list فارغ | CI |

## 5. الفجوات المُغلقة بالفحص: التفاصيل والاستشهادات

**(1) G1-E01-07، استخدامات Modules\Auction.** النمط `Modules\Auction` على شجرة `lavkoo@55e3dd0` خارج `docs`: 158 موضعاً في 33 ملفاً، وهذا يطابق E01 القسم 2.10.

**(2) G1-E01-08 وG1-E03-01 وG1-E01-09، جداول بلا migration (تحقق a).** انظر القسم 7-أ.

**(3) G1-E01-09، تعليق BootstrapTestSchema.** الملف `lavkoo:app/Console/Commands/BootstrapTestSchema.php:16` يقول "من 307 migrations". العدد الحالي 350 ملفاً: 336 في `database/migrations` و14 في `Modules/*/database/migrations`. التعليق متأخر.

**(4) G1-E02-04، PaymentConfigProvider.** الكتل `lavkoo:app/Providers/PaymentConfigProvider.php:89–120` تكرر النمط نفسه ثلاث مرات: `BusinessSetting::where(['type' => ...])->first()` ثم `json_decode($data['value'], true)` دون فحص أن `$data` ليس فارغاً. الأثر يتوقف على وجود الصفوف في الإنتاج (G1-E02-05 وG1-E10-01). الفئة VERIFIED للنمط، والأثر INFERRED.

**(5) G1-E03-09، royalty_points.** بحث `royalty` في `database` و`Modules`: لا نتيجة. العمود المذكور في الدوسيه لا ينشئه ولا يعدّله أي migration في المستودع.

**(6) G1-E04-02، قيد التفرد على users.** لا `unique` على `users.email` أو `users.phone` في الـ migrations. الوحيد هو `lavkoo:database/migrations/2021_11_25_043109_create_delivery_men_table.php:21` (جدول `delivery_men`)، وأُسقط لاحقاً في `2022_10_31_150604_add_address_phone_country_code_column_to_delivery_men_table.php:20`. جدول `users` بتعديلات فقط في الـ dump، والإنتاج غير معروف (G1-E04-03).

**(7) G1-E05-07، تطبيق البائع.** في `lavkooappseller` (main@9ea6423؛ الأسطر على 8ea0092 لم تُتحقق):
- رمز `owner_only`: لا وجود له في `lib/`.
- كلمة `permission`: تظهر حقلاً في نموذج JSON في `lib/features/automation/domain/models/automation_models.dart` ولا تظهر معالجةً لرفض صلاحيات.
- كلمات `staff` و`employee`: تظهر في `lib/features/security/domain/models/security_models.dart` ولا تظهر في مسار دخول أو `lib/features/auth`.
- النتيجة: التطبيق لا يعالج الرموز المذكورة، ولا يحوي مساراً لدخول الموظفين.

**(8) G1-E06-08، روابط Apple.** `lavkoo:public/.well-known/apple-app-site-association` يتضمن المسارات `"/"` و`"/product/*"` و`"/track-order/*"`. أما `lavkoo:config/deeplinks.php:30–36` فيعلن أن `/track-order` محذوف عمداً وأن الاستعلام يحمل المعرّف. التناقض مثبت بين مصدرين متتبَّعين.

**(9) G1-E07-03، اختبارات الثيم ومُهيّئ البيانات.** لا ملف اختبار في `tests/` اسمه يحوي `Theme` (عدد 0). بحث `ThemeSeeder` وأنماط seeder للثيم في `database` و`app` و`tests`: لا نتيجة. المستودع القديم غير متاح، فيبقى الجزء الخاص به UNKNOWN.

**(10) G1-E08-01، ثوابت تطبيق العميل.** `lavkooapp:lib/utill/app_constants.dart:46–51` و`:92–94` (eedbbb8): مسارات ثابتة مثل `/api/v1/brands?guest_id=1`، بلا معامل سوق أو دولة.

**(11) G1-E08-04، كاش SectionDataResolver.** `lavkoo:app/Services/Theme/SectionDataResolver.php` (954 سطراً): لا استدعاء لـ `Cache::` أو `cache(` أو `remember`. مالك كاش قوائم الصفحة الرئيسية يُعرف بالبحث عنه في طبقة أخرى.

**(12) G1-E08-08 وG1-E05-06، صنف AuctionProduct.**
- لا يوجد الصنف `AuctionProduct` في `app/Models` أو `Modules` (لا `Modules/Auction`).
- الاستدعاءات: 17 سطراً في 7 ملفات: `app/Http/Controllers/Admin/Customer/CustomerController.php:315,324`، `app/Http/Controllers/Admin/DashboardController.php:140,148,172,177`، `app/Http/Controllers/RestAPI/v1/CustomerController.php:81`، `app/Http/Controllers/RestAPI/v3/seller/SellerController.php:226`، `app/Http/Controllers/Web/HomeController.php:106`، `app/Utils/module-helper.php:387,402,462,551,590,667`، `routes/admin/routes.php:1922,1932`. وفيها علاقتان في `app/Models/Brand.php:65` و`app/Models/Category.php:87`.
- حراسة الإضافة: `app/Utils/module-helper.php:694–711` تعيد 0 حين يغيب `Modules/Auction/Addon/info.php`، حتى مع `modules_statuses.json:6` المعلَن `"Auction": true`. الاستدعاءات المحروسة بهذه الدالة: `HomeController.php:102`، `Admin/DashboardController.php:132`، `RestAPI/v1/CustomerController.php:72`. الاستدعاءات الأخرى لم تُتحقق واحداً واحداً (G1-E05-06).

**(13) G1-E09-02، مولّد order_group_id.** `lavkoo:app/Utils/OrderManager.php:65–68` يولّد `rand(1000, 9999)` و`Str::random(5)` و`time()`. مستخدم في `:1551` و`lavkoo:app/Services/SalesChannels/Dispatch/ChannelOrderMaterializer.php:149`. لا يوجد قيد تفرد على الحقل في هذه القراءة. خطر التصادم غير مقاس.

**(14) G1-E10-10، التحقق من استجابة MyFatoorah.** `lavkoo:app/Services/MyFatoorah/MyFatoorahResponse.php:61` يعلن أن النجاح هو 2xx مع `IsSuccess` صحيحاً فقط، وكل ما عداه رفض. `lavkoo:app/Services/MyFatoorah/ConnectionTester.php:27` يعيد `ok` و`message` و`methods` ويفحص دعم العملة.

**(15) G1-E10-15، `Modules/doc.txt`.** ملف من سطر واحد بالنص: "All modules will be stored here." (33 بايتاً).

**(16) G1-E11-15، مواضع كتابة current_stock.** النمط الساكن `current_stock` مع `=` أو `+=` أو `-=` أو `'current_stock' =>` أو `increment/decrement`: 66 سطراً في 25 ملفاً، منها `app/Services/Marketplace/BulkStockOperation.php` و`app/Services/Marketplace/PurchaseOrderService.php` و`app/Services/Marketplace/ReturnLogisticsService.php` و`app/Services/Marketplace/InventoryService.php` و`app/Traits/OrderEditManager.php` و`app/Http/Controllers/Admin/POS/POSOrderController.php` و`app/Http/Controllers/Vendor/POS/POSOrderController.php` ومتحكمات المنتج والطلب في الإدارة والبائع. دلالة كل سطر لم تُراجع.

**(17) G1-X-01 وG1-X-02 وG1-X-03، حالة CI.** انظر القسم 7-ب.

## 6. الفجوات المفتوحة: تجميع حسب الجهة المطلوبة

- **بيانات المالك (OD):** 61 فجوة. أهمها G1-E02-05 (وحدة العملة، P0)، وG1-E04-01 (مطابقة المحافظ، P0)، وG1-E09-05 (الفوترة الإلكترونية، P0)، وG1-E01-03 (إعدادات الطوابير والمجدول، P0)، وG1-E03-02 (المخطط الفعلي)، وG1-E10-01 وG1-E10-08 (حالة البوابات والإضافات).
- **اختبار staging بتفويض (ST):** 9 فجوة. تشمل route:list، وتشغيل CI، وsandbox لـ MyFatoorah، واختبار أسبقية المسارات المكررة.
- **فحص ساكن مؤجَّل (SD):** 33 فجوة. يمكن إغلاقها في جولة لاحقة دون بيانات إنتاج، وأغلبها قراءة متحكمات وخدمات لم تُفتح.
- **مُحال للأمن (SX):** 12 فجوة. تشمل SEC-E02-07 وSEC-E02-08 وSEC-E03-02 وSEC-E03-03/05 وSEC-E04-13 وSEC-E05-03 (P0) وSEC-E05-04 وSEC-E05-08 وSEC-E08-06 وSEC-E08-08 وSEC-E09-11 وSEC-E10-03 وSEC-E11 (العيّنة). وأيضاً مسألة نطاق الوصول في متحكم تعديل الطلب (G1-E10-13)، وهي مُحالة دون تفاصيل.
- **قرار مالك (OX):** 12 فجوة. تشمل هوية البائع، وSKU عبر المتاجر، وعملة الأرصدة التاريخية، وسياق المتجر في تطبيق العميل، وسؤال المشرف الرئيسي، وروابط /sa/، والتمييز بين شروط البائع والعميل، ونشر الويب والتطبيق، ونسخ الأصول، والرأي القانوني، والوثيقة الأمنية المتتبَّعة، ومعايرة الدرجات.
- **مُفوَّض (DL):** 2 (E01-02 إلى E10، وE07-09 إلى E17).

**المسائل المفتوحة التي أثارها الفحص ولم تكن في الدوسيهات:**
- **G1-E09-01 (الأهم في الفحص الساكن):** الخصم يُحسب ويُطبَّق على وعاء الضريبة قبل جمع المبلغ، ويُطرح لاحقاً من order_amount المخزَّن، لكنه لا يُطرح من المبلغ المحصَّل. قبل التحويل إلى عملة البوابة: المبلغ المحصَّل = مجموع order_amount المخزَّنة + مجموع refer_and_earn_discount. لا توجد خطوة تسوية تطرح الخصم؛ ولوحة المراقبة (`lavkoo:app/Services/Monitoring/Panels/PaymentsPanel.php:1379` و`:1403`) تقارن order_amount بمبلغ البوابة دون طرح، فتعرض طلبات الإحالة كفروقات. الفرق يساوي خصم الإحالة في كل طلب. التقارير تعامل الخصم كتكلفة إدارية، فقد يكون الفرق مقصوداً. الدرجة P1 حتى تتوفر بيانات التسوية والاسترداد من المالك. تصبح P0 فقط إذا تحقق الشرطان معاً: الشرط الأول (البوابة تحصّل الإجمالي دون طرح الخصم) متحقق بالكود، والشرط الثاني (عدم تمويل الخصم من جهة أخرى) غير محسوم ساكناً.
  - صفحة الدفع تعرض للعميل إجمالياً مطروحاً منه الخصم (`lavkoo:resources/themes/default/web-views/partials/_order-summary.blade.php:164` و`:238`)، فيُستنتج (INFERRED) أن المبلغ المحصَّل يزيد عما يراه العميل بقيمة الخصم؛ المكونات الأخرى لم تُقارن.
  - الدفع بالمحفظة يخصم المبلغ الإجمالي قبل طرح الإحالة (`lavkoo:app/Http/Controllers/Web/WebController.php:825` و`:881`؛ `lavkoo:app/Http/Controllers/RestAPI/v1/OrderController.php:437` و`:495`)، والمودال على صفحة الدفع نفسها يعرض الإجمالي قبل الخصم (`lavkoo:resources/themes/default/web-views/checkout/payment.blade.php:320` و`:337`).
  - الشرط الثاني: الكود يسجل الخصم مصروفاً على الإدارة يُدفع للبائع عند التسليم (`lavkoo:app/Repositories/OrderRepository.php:776–790`، ويُستدعى عند `delivered` لطلبات لها `seller_id` في `lavkoo:app/Http/Controllers/Admin/Order/OrderController.php:768–769`). لم يُعثر في مسارات الدفع والطلب والاسترداد المفحوصة على إعادة للفرق للعميل. حساب استرداد الطلب يطرح الإحالة (`lavkoo:app/Utils/OrderManager.php:2714–2737`)، وسقف استرداد MyFatoorah مبني على المحصَّل الإجمالي (`lavkoo:app/Services/MyFatoorah/RefundService.php:178`). الأثر النقدي UNKNOWN ويحتاج بيانات التسوية والاسترداد من المالك.
- **G1-E08-08 وG1-E05-06:** صنف `AuctionProduct` مستدعى من عدة متحكمات والمستودع لا يحوي الوحدة. الحراسة موجودة في ثلاثة مواضع مفحوصة فقط.
- **G1-E03-01:** عدد الجداول بلا إنشاء في E03 (71) لا يطابق القياس (انظر القسم 7-أ). ويضم القياس 17 جدول `monitoring_*` تُقرأ عبر `->connection()->table(...)` ولا يُنشئها أي migration في المستودع.
- **الجداول الموجودة في القائمة الصريحة لـ RestoreSection ولا تُنشأ بالـ migrations:** القائمة (31 اسماً) داخلة كلها في مجموعة الجداول بلا إنشاء والتعديل، والمجموعة الكاملة تغطي 113 اسماً. الكود يعرف 31 منها فقط.
- **G1-X-01:** CI أحمر على رأس main في `lavkoo` وفي `lavkooappseller`. أي ادعاء بأن الاختبارات خضراء غير مدعوم.

## 7. الفحوص المطلوبة: النتائج

### أ. الجداول بلا migration (تحقق a)

الطريقة: سكربت نصي على نسخة `lavkoo@55e3dd0` (`followup/snap-03a`، مستخرج بـ `git archive`). يقرأ `Schema::create` و`Schema::table` في `database/migrations` و`Modules/*/database/migrations`، ويقرأ أسماء الجداول من نماذج `app/Models` و`Modules/*/Models` (أو `$table` إن وُجد، وإلا اشتقاق Laravel التقريبي)، ومن استدعاءات `DB::table` و`->table(` و`->join` و`Schema::hasTable` في `app` و`Modules` و`routes` و`config` و`bootstrap`.

| البند | العدد |
|---|---|
| ملفات migrations | 350 (336 في `database/migrations` + 14 في `Modules/AI` و`Modules/Blog` و`Modules/TaxModule`) |
| أسماء الجداول المنشأة بـ `Schema::create` | 139 |
| أصناف النماذج | 244 |
| جداول مرتبطة بنماذج بلا إنشاء | 115 |
| منها بتعديل فقط (`Schema::table`، ALTER-only) | 34 |
| منها بلا إنشاء ولا تعديل | 78 (بعد استبعاد 3 إيجابيات كاذبة: `shipping_address` و`billing_address` و`refund_status`، وهي أسماء مفردة اشتقها الفحص من أسماء النماذج، والجداول الفعلية `shipping_addresses` (بتعديل فقط) و`billing_addresses` و`refund_statuses` مُنشأة أو مُعدّلة في migrations) |
| أسماء مرتبطة بالكود (غير النماذج) بلا إنشاء ولا تعديل | 35 (منها جدولان يديرهما الإطار: `migrations` و`failed_jobs`) |
| المجموع بلا إنشاء ولا تعديل (78 + 35) | 113 |
| الأسماء الموجودة في القائمة الصريحة لـ `TABLES_OUTSIDE_THE_MIGRATIONS` | 31 (كلها داخلة في الـ113) |

**الاستنتاج:** الرقم 71 في E03 لا يطابق القياس (78 للنماذج وحدها بعد الاستبعاد)، والرقم "نحو 110 و120" في E01 يقع في نطاق 115 للنماذج. الفرق يرجع إلى طريقة العدّ. العدّ الحالي تقريبي: اشتقاق أسماء النماذج يحاكي Laravel ولا يطابقه تماماً، والاستدعاءات المتغيرة الاسم لا تُلتقط.

**جداول مهمة بلا إنشاء في المستودع (عينة للمالك):** `exchange_rate_logs`، `audit_logs`، `approval_requests`، `approval_decisions`، `seller_staff`، `seller_roles`، `seller_api_keys`، `payment_routing_rules`، `mtn_cash_terminals`، `mtn_cash_transactions`، `themes` وجداول الثيم الأربعة الأخرى، `product_batches`، `purchase_orders`، `vendor_ledger_entries`، والجداول التي يكتبها الـ Monitoring (17 اسماً).

### ب. حالة CI (تحقق b)

| المستودع | الملف | الوظائف | الحالة على main | آخر التشغيلات |
|---|---|---|---|---|
| lavkoo | `.github/workflows/ci.yml` (موجود على main، ومتطابق مع الشجرة المحلية) | 7 وظائف: lint وtests-sqlite وtests-mysql وleak-guard وbuild-overlay وtests-mysql-sales-channels وtests-strict-mode. لا تحوي وظيفة نشر (build-overlay يصرّح بأنه لا ينشر) | **أحمر**: أحدث تشغيل على `55e3dd0` (أُنشئ 2026-11-11 23:49 UTC) فشل. من 21 وظيفة فشلت 5 (SQLite على PHP 8.2 و8.3، وStrict-mode على MariaDB، وSales-channel N–Z على MySQL وMariaDB) | آخر 12 تشغيلاً: 4 فشل، 8 ملغاة، 0 نجاح |
| lavkooapp | لا يوجد `.github` في `eedbbb8`، ولا في main (404 عبر GitHub) | لا شيء | **لا CI** | لا تشغيلات |
| lavkooappseller | `.github/workflows/ci.yml` (على main) | analyze-and-test وbuild-android | **أحمر**: أحدث تشغيل على `9ea6423` (أُنشئ 2026-11-12 01:50 UTC) فشل في analyze-and-test عند خطوة Test (`flutter test`، `lavkooappseller:.github/workflows/ci.yml:41–42`)؛ نجحت خطوة Analyze، وتخطّى build-android بسبب `needs: analyze-and-test` (`lavkooappseller:.github/workflows/ci.yml:46`) | آخر 9 تشغيلات: 6 فشل، 3 نجاح |

**الحكم:** حالة CI مُتحقَّق منها، وليست مجهولة: الـ workflow موجود، والتشغيلات على الرأس فاشلة في المستودعين اللذين لديهما CI، ولا CI لتطبيق العميل. لم تُحلَّل سجلات الفشل (تحتاج قراءة سجلات الوظائف، وهي خارج القراءة الساكنة هنا). الرأس lavkoo@55e3dd0 هو commit توثيقي يعدّل `docs/gcc/README.md` فقط (VERIFIED). الاستنتاج أن الفشل سابق له (INFERRED)، ويدعمه تشغيل lavkoo@7f42777c الفاشل، وهو commit أسبق منه (حالة التشغيل فقط؛ لم تُفحص المهام).

### ج. فروقات المسارات وثوابت التطبيق في E22 (تحقق c، عدّ فقط)

E22 القسم 9-د يسجل أربعة بنود غير متحقق منها:
1. أعداد المسارات حسب درجة الوصول (45 و72 و63) والزوج 161: لم تُعَد في هذه الجولة.
2. المسارات الـ24 غير المستهلكة بثابت من التطبيق: لم تُعَد أسماؤها في هذه الجولة.
3. "44 ملف Dart و203 موقع استخدام": لم يُعَد الرقم الموثّق.
4. مسار إدراج الشحن (SEC-E22-08): مُحال للأمن ولا يُفتح هنا.

الفحص الإضافي (تقريبي، عدّ فقط): في `lavkooapp:lib/utill/app_constants.dart` (eedbbb8) 164 ثابتاً لمسار `/api/...`. من هذه، 145 تتطابق كل مقاطعها مع أسماء حرفية في ملفات مسارات `routes/rest_api` (أول 20 ملفاً في القائمة)، و**19 ثابتاً** له مقطع واحد على الأقل غير موجود حرفياً. هذه **مرشحات لعدم التطابق وليست نتائج مؤكدة**: المسارات المبنية بالبادئات أو بالمتغيرات قد تُعطي إيجابيات كاذبة.

### د. الأسئلة المفتوحة للمالك (تحقق عام)

الأسئلة المفتوحة للمالك في E01 إلى E11 تُغطَّى في الجدول بالحالة OD أو OX أو ST أو SX. لم يُحدَّد جواب لأي منها.

## 8. الحدود

- لم يُشغَّل أي كود أو اختبار أو أمر artisan أو composer أو npm أو flutter، ولم يُنفَّذ أي استعلام إنتاج.
- لم يُتصل بأي مزوّد أو بوابة دفع أو شحن أو Odoo أو Firebase أو الإنتاج. أُجريت طلبات GitHub للقراءة فقط عبر `gh` و`git ls-remote`.
- تم تنفيذ `git fetch --dry-run origin main` مرة واحدة على `lavkoo` لتحديد رأس main دون تغيير أي شجرة عمل. لم يُنفَّذ أي fetch آخر.
- لم يُفتح `pharmacyksa98` بأي شكل، ولا `ksaapp98`. لم يُفتح أي ملف في `restricted/`.
- العدّ الآلي للجداول تقريبي (انظر القسم 7-أ)، ويُعدّ دليلاً على عدم التطابق وليس قيمة نهائية.
- لم تُقرأ `mySpecs.html` (103 كيلوبايت) ولا `ProductManager.php` الأسطر المذكورة ولا قوالب Blade ولا سجلات فشل CI.
- النتائج عن الفروع المتتبَّعة فقط. ما يوجد على الخادم أو في قاعدة الإنتاج أو في المستودع القديم غير مُتحقَّق منه.
- ملفات الاستشهاد في `lavkooappseller` تشير إلى main@9ea6423، والتحقق الآلي يتم على `8ea0092` محلياً؛ لهذا لم تُعطَ أسطر لتلك الاستشهادات وأُعطيت مسارات فقط.
