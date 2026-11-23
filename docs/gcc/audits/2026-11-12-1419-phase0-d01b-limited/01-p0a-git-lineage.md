# P0-A — نسب Git وخط الأساس الموثوق

**تعريف الوسوم:** VERIFIED = قُرئ مباشرة ومُستشهد به. INFERRED = استنتاج غير مباشر وأساسه مذكور. UNKNOWN = لا يُحسم بالوصول الحالي، والبند يذكر ما يحسمه. BLOCKED = يتطلب نسخة معزولة مُصرَّحاً بها، أو وصولاً إنتاجياً، أو مستودعاً لا يمكن قراءته.

| البند | القيمة |
|---|---|
| مجال العمل | Phase 0 — P0-A: Git lineage and authoritative baseline (تفويض D-01(B)، قراءة وتوثيق فقط) |
| مرجع التفويض | `lavkoo:docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md:45` (القسم 2)، ورأس الفرع `lavkoo@c8b6ed3e` |
| النطاق | `lavkoo` و`lavkooapp` و`lavkooappseller` عبر أدوات GitHub للقراءة وls-remote. `ksaapp98` من النسخة المحلية للقراءة فقط |
| خارج النطاق | `pharmacyksa98`: BLOCKED، ولم يُستدعَ أي أمر أو أداة عليه |
| ما لم يُنفَّذ | لا تعديل كود، ولا قاعدة بيانات، ولا نسخة معزولة، ولا إنتاج، ولا اختبارات، ولا CI، ولا fetch أو checkout |
| رؤوس الفروع المسجّلة | `lavkoo@55e3dd0e` لـ `origin/main` (لم يتغيّر عند التحقق). رأس التدقيق عند الاستعلام الأول (2026-11-12T11:21:49Z) كان `lavkoo@c8b6ed3e`؛ بعد نشر P0-C (`lavkoo@52055fe4`) صار `lavkoo@cd9ef550` (هذا التقرير)؛ والرأس عند التحقق (2026-11-12T11:54:36Z) هو `lavkoo@fe3907d6` (تقرير P0-E، أبوه `lavkoo@cd9ef550`). التفاصيل في القسم 1. |
| التاريخ | 2026-11-12 14:19 +03:00 (تاريخ الهيدر المطلوب، وليس وقت التنفيذ). أول استعلام عن الريموت في هذه الجلسة كان 2026-11-12T11:21:49Z |
| الحالة | **P0-A غير مُغلَق.** نسب الـ commits داخل lavkoo مُثبَت. كائنات PR وحالة legacy والإنتاج غير مُحسومة. Q-30 غير مُغلَق، وG0 غير مُغلَق |

## 1. رؤوس الفروع والتوقيت (الخطوة 1)

| المستودع | المرجع | SHA | وقت الـ commit (+03:00) | طريقة التحقق | حدّ الوصول | الحكم |
|---|---|---|---|---|---|---|
| lavkoo | `origin/main` | `lavkoo@55e3dd0e` | 2026-11-12 02:49 | ls-remote، ومطابق لـ MCP وللمرجع المحلي `origin/main` | لا | VERIFIED |
| lavkoo | `origin/claude/exciting-shannon-4mh2ok` (رأس التدقيق عند الاستعلام الأول 11:21:49Z) | `lavkoo@c8b6ed3e` | 2026-11-12 13:55 | ls-remote عند 11:21:49Z، ومطابق لـ MCP ولـ HEAD المحلي حينها | لا | VERIFIED (لقطة زمنية) |
| lavkoo | `origin/claude/exciting-shannon-4mh2ok` عند التحقق (11:54:36Z) | `lavkoo@fe3907d6` | 2026-11-12 14:52:45 +03:00 | ls-remote، وMCP get_commit، وgh api compare (متقدّم بـ commit واحد عن `lavkoo@cd9ef550`، وملف واحد تحت docs/gcc/) | لا | VERIFIED |
| lavkoo | `main` المحلي (لم يُستعمل مرجعاً) | `lavkoo@7f42777c` | 2026-11-12 00:56 | git محلي | متأخر 5 commits عن `origin/main` | VERIFIED |
| lavkooapp | `origin/main` | `lavkooapp@eedbbb8` | 2026-11-12 00:55 | ls-remote، ومطابق لـ HEAD المحلي | لا | VERIFIED |
| lavkooappseller | `origin/main` على GitHub | `lavkooappseller` main `9ea64232` (غير موجود محلياً، ولذلك لا يُستشهد به بصيغة commit) | 2026-11-12 04:50 (01:50:02Z) | ls-remote، وMCP get_commit | الرأس الريموت غير موجود في النسخة المحلية | VERIFIED |
| lavkooappseller | HEAD المحلي | `lavkooappseller@8ea00922` | 2026-11-12 00:55 | git محلي | أقدم من الريموت بثمانية commits؛ قائمة الـ commits تُظهر `8ea00922` سلفاً لـ `9ea64232` | VERIFIED |
| ksaapp98 | HEAD المحلي (`main`) | `ksaapp98@79f50bec` | 2026-11-11 15:54 | git محلي للقراءة فقط | لا استعلام عن الريموت، بحسب قاعدة الجلسة | VERIFIED (محلي) / UNKNOWN (ريموت) |
| pharmacyksa98 | — | — | — | لم يُستدعَ | الوصول مرفوض لهذه الجلسة وفق قواعد التشغيل، ولم تُختبر إعادة المحاولة | BLOCKED |

- خط الأساس `lavkoo@89f1be90` (من Discovery-01): سلف لـ `origin/main`، وبعده 16 commit على `origin/main`، وهو الرأس الثاني (parent 2) لدمج PR #17 `lavkoo@cbf2cddb`. [VERIFIED]
- رأس التدقيق تقدّم من `lavkoo@f7d5d966` (المرجع في Discovery-02) إلى `lavkoo@c8b6ed3e` بإضافة 11 commit عند `c8b6ed3e`. كل المسارات المختلفة عن `origin/main` (51 مساراً عند `c8b6ed3e`، و53 عند `cd9ef550`) تقع كلها تحت `docs/gcc/`، فلا تغيير على كود التطبيق في الرأس الجديد. [VERIFIED]
- `DECISIONS.md` و`final-report.md` و`merge-plan.md` مطابقة بين `origin/main` ورأس التدقيق. `STATUS.md` يختلف بإضافة ثلاثة أسطر (58–60)، والسطر 35 مطابق في الاثنين (`lavkoo:docs/gcc/STATUS.md:35`). [VERIFIED]
- حالة ريموت `ksaapp98` لم تُستعلم عنها، لأن قاعدة الجلسة تحصر القراءة في النسخة المحلية. [UNKNOWN]

## 2. طلبات السحب والفروع في GitHub (الخطوة 2)

منهج: قائمة PRs بحالة `all` وحالة `closed` (MCP، 100 لكل صفحة)، وقراءة `pulls/9` و`pulls/18` (MCP)، وطلبات GET فقط لـ `commits/<sha>/pulls` على خمسة merge commits، وقائمة الفروع (MCP). [VERIFIED]

- `lavkoo`: عدد PRs بحالة `all` = **0**، وبحالة `closed` = **0**. `pulls/9` و`pulls/18` = **404**. عدد PRs المرتبطة بكل من `lavkoo@4fa2cb56` و`lavkoo@8517d6ee` و`lavkoo@cd54f1eb` و`lavkoo@cbf2cddb` و`lavkoo@f61939f8` = **0**. [VERIFIED]
- صلاحيات الرمز على `lavkoo` تشمل pull وpush وadmin، وعلامة `has_pull_requests` = true، فالغياب ليس تعطيلاً لميزة PR. [VERIFIED]
- سبب غياب كائنات PR مع بقاء أرقامها في رسائل الدمج (استيراد تاريخ، أو حذف، أو مستودع آخر) غير معروف. [UNKNOWN]
- `lavkooapp` و`lavkooappseller`: عدد PRs بحالة `all` = **0** لكل منهما. [VERIFIED]
- الفروع: `lavkoo` = 2 (`main` و`claude/exciting-shannon-4mh2ok`)، و`lavkooapp` = 1 (`main`)، و`lavkooappseller` = 1 (`main`). [VERIFIED]
- الفروع الهيكلية (`claude/pr-c-amazon-completion`، `claude/pr-d-trendyol-products`، `claude/pr-e-noon`، `claude/pr-f-odoo`، `claude/pr-g-guards`، `claude/integration-cdef`، `claude/amazon-feeds`، `claude/pharmacyksa-shipping-tests-31q1nu`) غير موجودة على ريموت `lavkoo`. [VERIFIED]
- المقارنة مع Discovery-02 (`lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/02-git-lineage-and-n6-errata.md:32-36`): الأعداد والفروع ورؤوس `main` مطابقة. الاختلاف الوحيد هو تقدّم رأس التدقيق المذكور في القسم 1. [VERIFIED]

## 3. سلسلة PR #9 إلى PR #18 في lavkoo (الخطوة 3)

منهج: بحث في موضوعات رسائل الـ commits وأجسامها على `origin/main` عن أرقام PR من 9 إلى 18. والسلف بـ `git merge-base --is-ancestor`. والملفات بـ `git diff --stat <merge>^1 <merge>`، أي فرق الأب الأول، لأن `git show --stat` على الدمج يعرض فرقاً مدمجاً. [VERIFIED]

| PR | الدمج في lavkoo | التاريخ (+03:00) | الرأس الثاني (الفرع عند الدمج) | سلف `origin/main` | الملفات والأسطر | نص الرسالة | الحكم |
|---|---|---|---|---|---|---|---|
| #9 | `lavkoo@4fa2cb56` | 2026-11-08 20:16 | `lavkoo@f8035bd3` | نعم | 48 ملفاً، +5313/−48 | «Merge pull request #9 from …» | VERIFIED (دمج في الـ commit) / UNKNOWN (لا كائن PR) |
| #10 | `lavkoo@8517d6ee` | 2026-11-08 23:32 | `lavkoo@df53a8c4` | نعم | 62 ملفاً، +12311/−18 | «Merge pull request #10 from …» | VERIFIED / UNKNOWN |
| #11 | `lavkoo@cd54f1eb` | 2026-11-08 22:40 | `lavkoo@32b6e63e` | نعم | 40 ملفاً، +2760/−43 | «Merge PR #11: …» | VERIFIED / UNKNOWN |
| #12 | لا دمج بهذا الرقم. المحتوى عبر `lavkoo@d9c76422` | 2026-11-09 19:53 | `lavkoo@7efe022e` | نعم (المحتوى) | 6 ملفات، +604/−4 | «Merge branch 'claude/pr-c-amazon-completion' into claude/integration-cdef» | VERIFIED (المحتوى) / UNKNOWN (حالة PR) |
| #13 | لا دمج بهذا الرقم. المحتوى عبر 6 دمجات: `lavkoo@92f0feea` و`lavkoo@1e1106f7` و`lavkoo@63729769` و`lavkoo@ab3d9e53` و`lavkoo@56926d33` و`lavkoo@bc3742ea` | 2026-11-09 18:57 إلى 21:19 | `lavkoo@32249dd6` (لـ `92f0feea`) | نعم (المحتوى) | `92f0feea`: 3 ملفات، +6/−3 | «Merge branch 'claude/pr-d-trendyol-products' …» | VERIFIED / UNKNOWN |
| #14 | لا دمج بهذا الرقم. المحتوى عبر `lavkoo@561e8585` | 2026-11-09 18:58 | `lavkoo@0366ba7c` | نعم (المحتوى) | 45 ملفاً، +7869/−21 | «Merge remote-tracking branch 'origin/claude/pr-e-noon' …» | VERIFIED / UNKNOWN |
| #15 | لا دمج بهذا الرقم. المحتوى عبر `lavkoo@538fe91f` و`lavkoo@905571fa` | 2026-11-09 19:00 إلى 20:19 | `lavkoo@a907bf0d` (لـ `538fe91f`) | نعم (المحتوى) | `538fe91f`: 3 ملفات، +109/−11 | «Merge branch 'claude/pr-f-odoo' …» | VERIFIED / UNKNOWN |
| #16 | لا دمج بهذا الرقم. المحتوى عبر `lavkoo@9b3d5244` | 2026-11-09 21:19 | `lavkoo@92f0feea` (integration-cdef عند الدمج) | نعم | 3 ملفات، +6/−3 | «Merge branch 'claude/integration-cdef' into claude/pr-g-guards» | VERIFIED / UNKNOWN |
| #17 | `lavkoo@cbf2cddb` | 2026-11-11 12:38 | `lavkoo@89f1be90` (خط الأساس) | نعم | 254 ملفاً، +43244/−229 | «Merge pull request #17 from …» | VERIFIED / UNKNOWN |
| #18 | `lavkoo@f61939f8` | 2026-11-11 15:54 | `lavkoo@68612acc` | نعم | 3 ملفات، +214/−4: `config/deeplinks.php` و`docs/sales-channels/gap-matrix.md` و`tests/Feature/DeepLink/PublishedAppLinkTest.php` | «Merge PR #18: …» | VERIFIED / UNKNOWN |

- عدد الدمجات في `origin/main` = 42. [VERIFIED]
- كل الـ commits في الجدول أسلاف لـ `origin/main`، وتُعرف بالبصمة لا بنص الرسالة. [VERIFIED]
- لا يظهر أي رقم من #12 إلى #16 في موضوع أو جسم أي commit على `origin/main`. [VERIFIED]
- أسماء الفروع المقابلة للأرقام #12 إلى #16 مأخوذة من `lavkoo:docs/sales-channels/merge-plan.md:127-134`. الربط بين الاسم والرقم غير متحقق من GitHub لأن كائنات PR غائبة. [INFERRED]
- ترتيب الدمج على سلسلة الأب الأول: #9 ثم #11 ثم #10 ثم #17 ثم #18. [VERIFIED]
- أرقام PR غير فريدة عبر المستودعات: سجل lavkoo يحوي رسائل «Merge pull request #1» إلى «#8» (مثل `lavkoo@ef843600`)، وفي `ksaapp98` PR #1 وPR #2 مختلفان عنها. لذلك يُذكر المستودع دائماً. [VERIFIED]
- صيغتان لرسائل الدمج: الافتراضية لـ GitHub في #9 و#10 و#17، والمخصصة «Merge PR #N:» في #11 و#18. هذا يوحي بأن #11 و#18 لم يُنفَّذا بتدفق PR على GitHub، والسبب غير محسوم. [INFERRED]
- الـ commits الثلاثة التي يربطها `lavkoo:docs/sales-channels/final-report.md:622-626` بـ PR #9 (`lavkoo@79fb408` و`lavkoo@01bb5e5` و`lavkoo@55dc7e0`) أسلاف لرأس الدمج `lavkoo@f8035bd3`. [VERIFIED]
- دمج PR في `main` لا يثبت النشر في الإنتاج. حالة النشر BLOCKED (القسم 8). [BLOCKED]

## 4. ksaapp98 PR #1 وPR #2 مقابل lavkooapp (الخطوة 4)

منهج:
- ksaapp98 محلياً: 20 commit، والدمجات اثنان فقط: `ksaapp98@c0035656` (PR #1، 2026-11-08 23:31) و`ksaapp98@79f50bec` (PR #2، 2026-11-11 15:54). [VERIFIED]
- لا commit مشترك بين المستودعين: كائنات الدمجين غير موجودة في `lavkooapp`، وتاريخ `lavkooapp` يتكون من commitين، الجذر `lavkooapp@0df8b09` ثم الرأس `lavkooapp@eedbbb8`. [VERIFIED]
- بسبب غياب سجل مشترك لم يُستعمل `git diff` بين الشجرتين مباشرة. استُعملت طريقتان: (أ) لكل ملف يتغيّر في الدمج تُقارَن بصمة الـ blob في شجرة الدمج ببصمة الملف عند المسار نفسه في HEAD لـ `lavkooapp`، و(ب) لكل ملف غير محذوف تُحسب الأسطر غير الفارغة المضافة التي تظهر كسطر كامل في الملف المقابل. الطريقة (ب) مؤشر حضور لا تطابق موضعي. [VERIFIED]
- بحث الاسم `myfatoorah` بلا تفرقة في حالة الأحرف في HEAD لـ `lavkooapp`. [VERIFIED]

نتائج PR #1 (`ksaapp98@c0035656`):
- 13 ملفاً، +793/−7. الملفات الأربعة الجديدة للـ MyFatoorah (`lib/features/checkout/domain/models/myfatoorah_payment_status.dart`، و`lib/features/checkout/domain/services/myfatoorah_payment_service.dart`، و`test/myfatoorah_payment_status_test.dart`، و`test/myfatoorah_return_url_test.dart`) مطابقة بايتياً للمسار نفسه في `lavkooapp`: 4 من 4. [VERIFIED]
- ملف `lib/features/splash/controllers/splash_controller.dart` مطابق بايتياً. [VERIFIED]
- 8 ملفات معدّلة مختلفة بايتياً: ملفات الترجمة الخمسة، و`lib/features/checkout/screens/checkout_screen.dart`، و`lib/features/checkout/screens/digital_payment_order_place_screen.dart`، و`lib/utill/app_constants.dart`. [VERIFIED]
- من 683 سطراً مضافاً غير فارغ، 672 موجودة كسطر كامل في الملف المقابل (98.4%)، والـ 11 الباقية كلها في `digital_payment_order_place_screen.dart`. [VERIFIED]
- اكتمال النقل غير محسوم: معظم محتوى PR #1 موجود، والملفات المعدّلة فيها تطويرات إضافية لم تُفصل. [INFERRED]
- عدد ملفات `lavkooapp` التي تذكر `myfatoorah` = 7 بالمحتوى و4 بالاسم. الوصف في Discovery-02 (`lavkoo:docs/gcc/audits/2026-11-12-1240-discovery-02-followup/02-git-lineage-and-n6-errata.md:78`) بأنها «7 ملفات تحمل اسم MyFatoorah» غير دقيق. [VERIFIED]

نتائج PR #2 (`ksaapp98@79f50bec`):
- 41 مساراً، +6529/−336: 3 مطابقة بايتياً، و27 مختلفة، و10 غائبة في `lavkooapp` (ملفات جديدة كاملة)، و1 حذفها الدمج ولا تزال موجودة في `lavkooapp` (`lib/features/wallet/screens/add_fund_to_wallet_screen.dart`). [VERIFIED]
- من 5145 سطراً مضافاً غير فارغ، 353 موجودة كسطر كامل. من الـ 4792 الباقية، 4733 في الملفات العشرة الغائبة و59 في خمسة ملفات معدّلة. [VERIFIED]
- الاستنتاج: محتوى PR #2 غير موجود في `lavkooapp` بشكل معتبر. [INFERRED]
- عنوان PR #2 («app/backend compatibility») يقابل بالعنوان فقط PR #18 في lavkoo («App deep-link routes and backend compatibility»). هذا ربط بالعنوان لا بالمحتوى. [INFERRED]

## 5. جدول الأدلة (الخطوة 5)

| المستودع / PR | الـ commit أو الدمج | في `lavkoo main` | في كود العميل أو البائع | عدم اليقين | الأدلة الداعمة | الحكم |
|---|---|---|---|---|---|---|
| legacy `pharmacyksa98` / #9 (channels) | `lavkoo@4fa2cb56` | نعم | UNKNOWN (لم يُختبر مصطلح مميز) | حالة legacy: UNKNOWN (قول المالك) (merged)، ولا كائن PR | نص الدمج؛ رأس `lavkoo@f8035bd3` يحوي الـ commits التي يذكرها `final-report.md:622-626` | VERIFIED (lavkoo) / UNKNOWN (legacy) |
| legacy / #10 (MyFatoorah) | `lavkoo@8517d6ee` | نعم | INFERRED: نعم على مستوى الميزة (7 ملفات في `lavkooapp` تذكر MyFatoorah) | لم يُختبر عقد الـ API بين العميل والخلفية؛ حالة legacy UNKNOWN (قول المالك) | القسم 4؛ نص الدمج | VERIFIED / UNKNOWN |
| legacy / #11 (stock) | `lavkoo@cd54f1eb` | نعم | UNKNOWN (5 ملفات في HEAD المحلي للبائع `8ea00922` تطابق `outbox` ولم تُحلَّل؛ الرأس الريموت `9ea64232` لم يُفحص محتواه) | حالة legacy UNKNOWN (قول المالك) (merged)، ولا كائن PR | نص الدمج؛ 40 ملفاً | VERIFIED / UNKNOWN |
| legacy / #12 (Amazon، `claude/pr-c-amazon-completion`) | `lavkoo@d9c76422` (رأس `lavkoo@7efe022e`) | نعم (المحتوى) | INFERRED: لا (لا تطابق لـ `amazon` في العميل أو البائع) | حالة legacy UNKNOWN (قول المالك) (closed or merged)؛ `DECISIONS.md:60` يقول open في لقطة قديمة | سلف لـ `origin/main`؛ `merge-plan.md:127` | VERIFIED / INFERRED / UNKNOWN |
| legacy / #13 (Trendyol، `claude/pr-d-trendyol-products`) | 6 دمجات، أقدمها `lavkoo@bc3742ea` (2026-11-09 18:57 +03:00) وأحدثها `lavkoo@92f0feea` (21:19 +03:00) | نعم (المحتوى) | INFERRED: لا (لا تطابق لـ `trendyol`) | حالة legacy UNKNOWN (قول المالك) (closed or merged) | سلف لـ `origin/main`؛ `merge-plan.md:128` | VERIFIED / INFERRED / UNKNOWN |
| legacy / #14 (Noon، `claude/pr-e-noon`) | `lavkoo@561e8585` | نعم (المحتوى) | UNKNOWN (لا ملف نصي في `lavkooapp` يطابق `noon` ككلمة؛ المطابق الوحيد ملف خط ثنائي `assets/fonts/Tajawal/Tajawal-Medium.ttf` وهو تطابق عرضي محتمل؛ المطابقة الحرفية 12 ملفاً ولم تُحلَّل) | حالة legacy UNKNOWN (قول المالك) (closed or merged) | سلف لـ `origin/main`؛ `merge-plan.md:129` | VERIFIED / UNKNOWN |
| legacy / #15 (Odoo، `claude/pr-f-odoo`) | `lavkoo@538fe91f` و`lavkoo@905571fa` | نعم (المحتوى) | INFERRED: لا (لا تطابق لـ `odoo`) | حالة legacy UNKNOWN (قول المالك) (closed or merged) | سلف لـ `origin/main`؛ `merge-plan.md:130` | VERIFIED / INFERRED / UNKNOWN |
| legacy / #16 (guards، `claude/pr-g-guards`) | `lavkoo@9b3d5244` | نعم (المحتوى) | UNKNOWN (مصطلح عام لا يصلح للاختبار) | مسودة في لقطة وقت الكتابة (`final-report.md:250`)، والحالة الحالية UNKNOWN (قول المالك) | سلف لـ `origin/main`؛ `merge-plan.md:132` | VERIFIED / UNKNOWN |
| lavkoo / #17 (shipping tests، `claude/pharmacyksa-shipping-tests-31q1nu`) | `lavkoo@cbf2cddb` (رأس `lavkoo@89f1be90`) | نعم | INFERRED: لا (لا تطابق لـ `torod`، وهو مصطلح واحد فقط) | لا PR له في legacy وقت الكتابة (`merge-plan.md:134`) | دمج 254 ملفاً؛ نص الدمج | VERIFIED / INFERRED |
| lavkoo / #18 (App deep-link routes) | `lavkoo@f61939f8` (رأس `lavkoo@68612acc`) | نعم | INFERRED: نعم على مستوى الميزة (النمط `deep[ _-]*link` بلا تفرقة حالة الأحرف: 12 ملفاً في `lavkooapp` و1 في HEAD المحلي للبائع؛ العبارة الحرفية `deep link` تعطي 3 في `lavkooapp`) | لا كائن PR؛ الربط بـ PR #2 لـ ksaapp98 بالعنوان فقط | نص الدمج؛ 3 ملفات | VERIFIED / INFERRED |
| legacy #12 إلى #18 (ملخص) | المحتوى عبر `lavkoo@cbf2cddb` | نعم (المحتوى) | — | UNKNOWN (قول المالك): مغلقة أو مدموجة. لا وصول إلى legacy | القسم 2 والقسم 3 | UNKNOWN / BLOCKED |
| ksaapp98 / PR #1 (MyFatoorah) | `ksaapp98@c0035656` | غير منطبق (مستودع مختلف، لا commits مشتركة) | نعم جزئياً: INFERRED للاكتمال | حالة PR #1 على GitHub UNKNOWN؛ 8 ملفات معدّلة مختلفة | القسم 4 | VERIFIED (الحضور) / INFERRED (الاكتمال) |
| ksaapp98 / PR #2 (app/backend compatibility) | `ksaapp98@79f50bec` | غير منطبق | لا بشكل معتبر: INFERRED | حالة PR #2 على GitHub UNKNOWN (لا بيان للمالك في المصادر المقروءة) | القسم 4 | VERIFIED (الأعداد) / INFERRED / UNKNOWN |

## 6. Q-30 بعد مراجعته من الأدلة (الخطوة 6)

- نص Q-30 يطلب وصول قراءة فقط (`git ls-remote` وقائمة الطلبات وسجل الترحيلات) لمستودع `pharmacyksa98`، ويطرح خيارين: وصول بموافقة المالك، أو تصدير من المالك (`lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/11-open-decisions-for-owner.md:389`). [VERIFIED]
- هل أصبح الوصول متاحاً؟ **لا.** `pharmacyksa98` خارج النطاق، وأعلنت قواعد التشغيل أن الوصول مرفوض لهذه الجلسة. لم يُستدعَ أي أمر أو أداة عليه، ولم تُختبر إعادة المحاولة. الحالة BLOCKED. [BLOCKED]
- هل اكتمل تحقق النسب؟ **لا.** المُثبت: الـ commits داخل `lavkoo` لأرقام #9 إلى #18 وصلتها بـ `origin/main`. غير المُثبت: كائنات PR في lavkoo (غائبة عبر API)، وحالة PR في legacy (لا وصول)، وحالة النشر (لا وصول إنتاج). [UNKNOWN]
- لا يُغلق أي من الأمرين G0. إغلاقه يحتاج قراراً كتابياً من المالك (`lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/12-go-no-go-recommendation.md:148`). [VERIFIED]

## 7. تأكيد N6 واقتراحات التصويب (الخطوة 7)

N6 كما صاغته Discovery-01 (`lavkoo:docs/gcc/audits/2026-11-12-0115-discovery-01/12-go-no-go-recommendation.md:149`): الوثائق تصف #12 إلى #16 بأنها مفتوحة و«لم يُدمج شيء»، بينما دُمج محتواها في `main` عبر `lavkoo@cbf2cddb`. [VERIFIED]

| # | الموقع | النص المقتبس (مختصر) | ما يُظهره الدليل | الحكم | التصويب المقترح (PROPOSED، غير مُطبَّق) |
|---|---|---|---|---|---|
| 1 | `lavkoo:docs/sales-channels/final-report.md:8` | «ولم يُدمج شيء في `main`، ولم يُنشر شيء» | دُمج فرع `claude/pharmacyksa-shipping-tests-31q1nu` في `main` مستودع lavkoo عبر `lavkoo@cbf2cddb` بتاريخ 2026-11-11. النشر غير مُحسوم | تناقض مع `main` في lavkoo: VERIFIED. النشر: BLOCKED | «هذا الوصف يخص فرع pharmacyksa98 وقت كتابته فقط. الفرع نفسه دُمج في `main` مستودع lavkoo عبر `lavkoo@cbf2cddb` (PR #17) بتاريخ 2026-11-11 حسب رسالة الدمج. لا يدل ذلك على نشر في الإنتاج.» (أولوية عالية) |
| 2 | `lavkoo:docs/sales-channels/final-report.md:698` | «C وD وE وF أربعة فروع **مستقلّة** عن `main`» | محتوى C إلى F موجود في `main` عبر `lavkoo@cbf2cddb`. الوصف صحيح لحظة الكتابة في legacy | لقطة متقادمة: VERIFIED | «كانت فروع C إلى F مستقلة عن `main` وقت كتابة التقرير في pharmacyksa98. دخل محتواها `main` مستودع lavkoo عبر `lavkoo@cbf2cddb` (PR #17) بتاريخ 2026-11-11.» |
| 3 | `lavkoo:docs/sales-channels/final-report.md:250` | «PR #16 مسوّدة تستهدف فرع التكامل لا `main`» | المحتوى في `main` عبر `lavkoo@9b3d5244` و`lavkoo@cbf2cddb`. حالة PR #16 في legacy غير معروفة | المحتوى: VERIFIED. الحالة الحالية: UNKNOWN | «(لقطة وقت الكتابة) PR #16 في pharmacyksa98 كانت مسودة تستهدف integration-cdef. حالتها الحالية غير متحقق منها. محتواها في `main` مستودع lavkoo عبر `lavkoo@cbf2cddb`.» |
| 4 | `lavkoo:docs/gcc/DECISIONS.md:60` | «#12 (Amazon) … #16 (guards) reported open there at last check» و«Verify live status, and whether any commits exist in current `lavkoo`» | السؤال أُجيب على مستوى الـ commits: محتوى #12 إلى #16 موجود في `main` (VERIFIED)، ولا كائن PR بهذه الأرقام في lavkoo (VERIFIED). «open» حالة قديمة غير متحقق منها (UNKNOWN) | السؤال مُجاب جزئياً؛ الحالة قديمة | «… reported open there at last check (لقطة قديمة غير متحقق منها). التحقق في lavkoo: محتوى #12 إلى #16 موجود في `main` عبر `lavkoo@cbf2cddb`، ولا توجد كائنات PR بهذه الأرقام في lavkoo.» |
| 5 | `lavkoo:docs/gcc/DECISIONS.md:61` | «Legacy Flutter customer PR #1 (MyFatoorah) reported merged there. Verify transfer to `lavkooapp`» | النقل جزئي وقابل للقياس: 4 ملفات جديدة مطابقة، و672 من 683 سطراً موجودة، و8 ملفات معدّلة مختلفة (VERIFIED). اكتمال النقل غير محسوم | سؤال التحقق مُجاب جزئياً: VERIFIED / UNKNOWN | «… Verify transfer to `lavkooapp`. نتيجة التحقق الجزئي: الملفات الأربعة الجديدة للـ MyFatoorah مطابقة بايتياً في `lavkooapp@eedbbb8`، وتوجد معظم أسطر PR #1 هناك، و8 ملفات معدّلة مختلفة، ولم يُحسم اكتمال النقل.» |
| 6 | `lavkoo:docs/gcc/STATUS.md:35` | «Earlier reported states: #9–#11 merged in legacy؛ #12–#16 open…» و«Recheck later; do not assume inclusion in new repo» | محتوى #12 إلى #16 مدموج في `main` عبر `lavkoo@cbf2cddb` (VERIFIED)، وحالات legacy غير معروفة (UNKNOWN) | لقطة متقادمة للمحتوى | «تم التحقق من دمج محتوى #12 إلى #16 في `main` مستودع lavkoo عبر `lavkoo@cbf2cddb`. الحالات المذكورة في legacy قديمة وغير متحقق منها.» |
| 7 | `lavkoo:docs/gcc/STATUS.md:35` | «those numbers are **not PRs #12–#16 in `lavkoo`**» | لا رقم PR من 12 إلى 16 في سجل lavkoo | متسق | لا تغيير |

- مراجعة Discovery-02 §5: تأكيد E-01 وE-02 وE-05 وE-07 وE-08 وE-23، وتصويب E-03 بأن الأعداد 4 بالاسم و7 بالمحتوى (VERIFIED). لم يُعَد فحص E-04 وE-06 وE-09 إلى E-22 وE-24 بنداً بنداً، وتبقى مرجعاً لمن يراجعها. [VERIFIED]
- `merge-plan.md` ليس من الوثائق الثلاث المطلوبة، وفيه بنود من النوع نفسه (E-25 إلى E-32 في Discovery-02). أُدرج للإحالة فقط. [VERIFIED]
- لم يُعدَّل أي من `DECISIONS.md` و`STATUS.md` و`final-report.md` و`merge-plan.md`. [VERIFIED]

## 8. رأس الإنتاج وجدول ترحيلات الإنتاج (الخطوة 8)

- رأس الإنتاج: **غير متاح**. لا وصول إنتاج في هذا التفويض، و`lavkoo` لا يحوي أي وسم (tag) يربط رأساً بالإنتاج. [BLOCKED]
- عدد الوسوم في `lavkoo` = 0. [VERIFIED]
- جدول ترحيلات الإنتاج: **غير متاح**. وجود ملفات الترحيل في المستودع لا يثبت تطبيقها على قاعدة الإنتاج. [BLOCKED]
- لا يُستنتج رأس الإنتاج من رأس `main`، لأن الدمج لا يثبت النشر. [INFERRED]

## 9. قائمة إغلاق P0-A (الخطوة 9)

| # | البند | الحكم | الأساس |
|---|---|---|---|
| 1 | رؤوس `main` ورأس التدقيق مع التوقيت (لقطة 11:21:49Z؛ الرأس الحالي في القسم 1) | VERIFIED | القسم 1 |
| 2 | رؤوس مستودعات العميل والبائع والنسخة المحلية لـ ksaapp98 | VERIFIED | القسم 1 |
| 3 | رؤوس legacy (`pharmacyksa98`) | BLOCKED | خارج النطاق، والوصول مرفوض |
| 4 | عدّ PRs والفروع في المستودعات الثلاثة | VERIFIED | القسم 2 |
| 5 | كائنات PR لـ #9 إلى #18 في lavkoo | UNKNOWN | غائبة عبر API، والسبب غير معروف |
| 6 | سلسلة #9 إلى #18 على مستوى الـ commits | VERIFIED | القسم 3 |
| 7 | حالة #9 إلى #18 في legacy | BLOCKED (وصول) / UNKNOWN (قول المالك) | القسمان 5 و6 |
| 8 | حضور PR #1 و#2 من ksaapp98 في lavkooapp | VERIFIED (القياس) / INFERRED (الاكتمال) | القسم 4 |
| 9 | جدول الأدلة (الخطوة 5) | VERIFIED (lavkoo) / UNKNOWN (legacy) | القسم 5 |
| 10 | مقارنة مع Discovery-02 report 02 | VERIFIED | القسم 2 والقسم 7 |
| 11 | Q-30 | BLOCKED (وصول) | القسم 6 |
| 12 | اكتمال نسب lavkoo الكامل | UNKNOWN | كائنات PR، وحالة legacy، والنشر |
| 13 | N6: تأكيد التناقضات | VERIFIED | القسم 7؛ التصويب PROPOSED ولم يُطبَّق |
| 14 | رأس الإنتاج | BLOCKED | القسم 8 |
| 15 | جدول ترحيلات الإنتاج | BLOCKED | القسم 8 |
| 16 | لا تغيير على كود أو قاعدة بيانات أو إنتاج | VERIFIED | سجل الأدلة E-A15 |
| 17 | إغلاق P0-A | BLOCKED | لا يُغلق قبل حسم الإنتاج وحالة legacy؛ القرار للمالك |

## حدود هذا التقرير وعناصر BLOCKED / UNKNOWN

- **BLOCKED:** `pharmacyksa98` (الوصول مرفوض لهذه الجلسة، ولم يُستدعَ). رأس الإنتاج وجدول ترحيلات الإنتاج. أي قياس على نسخة معزولة: لم تُقدَّم نسخة مُصرَّحة، ولم يُنشأ أو يُستنسخ أو يُصدَّر أي قاعدة بيانات. [BLOCKED]
- **UNKNOWN:** سبب غياب كائنات PR في lavkoo مع بقاء أرقامها في رسائل الدمج. حالة #9 إلى #18 في legacy (قول المالك فقط، غير متحقق منه). حالة PR #1 و#2 في ksaapp98 على GitHub (القراءة محلية فقط). محتوى `lavkooappseller` عند الرأس الريموت `9ea64232` (غير موجود محلياً، ولم تُقارن محتوياته). معنى الأسطر الـ11 الناقصة في `digital_payment_order_place_screen.dart` وطبيعة الفروقات في الملفات الثمانية المعدّلة. [UNKNOWN]
- **INFERRED:** ربط أرقام PR بأسماء الفروع من `merge-plan.md`. أن دمجي #11 و#18 جرى خارج تدفق PR على GitHub. أن `lavkoo` أُنشئ بنقل تاريخ من مستودع آخر، وهذا لم يُتحقق منه بوصول إلى legacy. أن رؤوس الدمج الثانية تساوي رؤوس الفروع لدى legacy. [INFERRED]
- **مدى أسماء الميزات:** البحث بأسماء الميزات (`myfatoorah` و`deep link` وغيرها) مؤشر ضعيف. لا يثبت وجود الكود بمعناه، ولا يثبت التطابق الدلالي. [INFERRED]
- **التوقيت:** الهيدر 14:19 +03:00 كما طُلب. أول استعلام عن الريموت كان بحسب المؤلف 2026-11-12T11:21:49Z (14:21:49 +03:00). [UNKNOWN: لا سجل خارجي]
- **النشر:** نُشرت نسخة أولى من هذا الملف (`lavkoo@cd9ef550`) قبل إكمال مراجعة المؤلف، ثم صُحِّحت بنشر لاحق بعد التحقق. لم يُعدَّل أي ملف آخر، ولا يوجد دمج أو force push. [VERIFIED]
- **حدود القرار:** لم يُغلق P0-A، ولا Q-30، ولا G0. القرار للمالك بتوقيع كتابي منفصل. [VERIFIED]
- **خارج هذا التقرير:** مخرجات P0-C وP0-E، والمستند الختامي للمرحلة، والـ handoff. [VERIFIED]

## سجل الأدلة

| المعرّف | الطريقة (للقراءة فقط) | المدى | النتيجة | الحكم |
|---|---|---|---|---|
| E-A01 | `git ls-remote` لـ `refs/heads/main` و`refs/heads/claude/exciting-shannon-4mh2ok` | lavkoo، lavkooapp، lavkooappseller | `55e3dd0e`، `c8b6ed3e` (رأس وقت الاستعلام الأول، وليس الرأس الحالي `fe3907d6`)، `eedbbb8`، `9ea64232` | VERIFIED |
| E-A02 | `git rev-parse` و`git log -1` لـ HEAD المحلي | lavkooapp، lavkooappseller، ksaapp98، lavkoo | `eedbbb8` (HEAD لـ lavkooapp، فرع `claude/exciting-shannon-4mh2ok`)، `8ea00922` (HEAD المحلي لـ lavkooappseller، فرع `claude/exciting-shannon-4mh2ok`، وليس الرأس الريموت `9ea64232`)، `79f50bec` (HEAD لـ ksaapp98، فرع `main`)، `c8b6ed3e` (HEAD لـ lavkoo وقت الاستعلام؛ `7f42777c` هو فرع `main` المحلي وليس HEAD) | VERIFIED |
| E-A03 | MCP get_commit و list_commits | lavkooappseller | `9ea64232` بتاريخ 2026-11-12T01:50:02Z؛ `8ea00922` في سلفه (8 commits) | VERIFIED |
| E-A04 | MCP list_pull_requests (`all` و`closed`) و pull_request_read لـ `pulls/9` و`pulls/18` | lavkoo، lavkooapp، lavkooappseller | 0 لكل مستودع؛ 404 لـ #9 و#18 | VERIFIED |
| E-A05 | GET فقط عبر gh api: `commits/<sha>/pulls` لخمسة merges، و`pulls?state=all`، والإعدادات الأساسية للمستودع | lavkoo | 0 لكل merge؛ 0 PR؛ `has_pull_requests` = true | VERIFIED |
| E-A06 | MCP list_branches | ثلاثة مستودعات | 2، 1، 1 فرع | VERIFIED |
| E-A07 | `git log origin/main` (subject وbody) بحثاً عن أرقام PR من 9 إلى 18؛ `git rev-list --merges --count` | lavkoo | #9 #10 #11 #17 #18 موجودة؛ #12 إلى #16 غير موجودة؛ 42 دمجاً | VERIFIED |
| E-A08 | `git merge-base --is-ancestor`، و`git diff --stat <merge>^1 <merge>` | الدمجات في القسم 3 | كلها أسلاف؛ الأعداد في القسم 3 | VERIFIED |
| E-A09 | `git diff --name-only origin/main <audit>`، و`git rev-list --count` | lavkoo | 51 مساراً كلها تحت `docs/gcc/`؛ 11 commit بعد `f7d5d966`؛ 16 commit بعد `89f1be90` | VERIFIED |
| E-A10 | `git log --merges`، و`git rev-list --count` | ksaapp98، lavkooapp، lavkooappseller | ksaapp98: دمجان من 20 commit؛ lavkooapp: 2 commit؛ lavkooappseller المحلي: 3 commits | VERIFIED |
| E-A11 | مقارنة البصمات (blob) لكل مسار في دمجي ksaapp98 مقابل HEAD لـ lavkooapp | ksaapp98 PR #1 و#2 | PR #1: 5 مطابق و8 مختلف. PR #2: 3 مطابق و27 مختلف و10 غائب و1 محذوف لم يُطبَّق | VERIFIED |
| E-A12 | `git diff -U0` ثم بحث الأسطر الكاملة في الملف المقابل | ksaapp98 PR #1 و#2 | PR #1: 672 من 683. PR #2: 353 من 5145 | INFERRED (مؤشر حضور) |
| E-A13 | `git grep -il` و`git ls-tree` بالمصطلحات | HEAD المحلي لـ lavkooapp (`eedbbb8`) وlavkooappseller (`8ea00922`، وليس الرأس الريموت `9ea64232`) | `myfatoorah`: 7 بالمحتوى و4 بالاسم؛ `deep[ _-]*link` بلا تفرقة حالة الأحرف: 12 و1 (العبارة الحرفية `deep link`: 3 و1)؛ `amazon` و`trendyol` و`odoo` و`torod`: 0 | VERIFIED (الأعداد) |
| E-A14 | قراءة نصوص مُستشهد بها | DECISIONS.md:58-62، STATUS.md:33-36، final-report.md:1-9 و249-251 و697-699 و622-626، merge-plan.md:127-134، Q-30 (file 11:389)، N5 و N6 (file 12:148-149)، Discovery-02 report 02 | نصوص مطابقة للاستشهادات | VERIFIED |
| E-A15 | لا تغيير: لم يُنفَّذ fetch أو checkout أو commit أو push في المستودعات الأخرى، ولم يُستعمل أي اتصال إنتاجي أو قاعدة بيانات | كل المستودعات | لا تغيير على كود أو قاعدة بيانات أو إنتاج | VERIFIED |
| E-A16 | `git tag --list` | lavkoo | 0 وسوم | VERIFIED |
| E-A17 | `git cat-file -e` لكائنات دمجي ksaapp98 داخل `lavkooapp` | lavkooapp | غائبة (لا commits مشتركة) | VERIFIED |
| E-A18 | pharmacyksa98: لم يُستدعَ أي أمر أو أداة | — | خارج النطاق، والوصول مرفوض وفق قواعد التشغيل | BLOCKED |
