# E21 — سلالة Git وأصول طلبات الدمج القديمة وحالة التكاملات (Git lineage, legacy PR ancestry and integration status)

> جلسة التدقيق: Discovery-01 — التاريخ: 2026-11-12 (+03:00) — الحالة: قراءة فقط
> المستودعات والـ SHA المدقَّقة: lavkoo@7f42777 (الفرع `main`؛ شجرة العمل على فرع الجلسة عند lavkoo@046d623 الذي يزيد commit توثيق واحداً وقت القراءة؛ تقدّم الفرع لاحقاً إلى ba39ddbd بـ19 commit توثيقياً من جلسات أخرى، ولا يغيّر ذلك الاستنتاجات المبنية على `main`) — lavkooapp@eedbbb8 — lavkooappseller@8ea0092 — ksaapp98@79f50be (مرجع قديم للقراءة فقط).
> المستودع القديم للـ backend ‏`fullstackfull/pharmacyksa98` **غير متاح** في هذه الجلسة: كل ما يتوقف عليه موسوم `UNKNOWN (missing access: pharmacyksa98)`.

## 1. النطاق وما تم فحصه

**التغطية (البند H من `DISCOVERY_PROMPT_01.md`):** سلالة الـ commits في المستودعات الأربعة، كل merge commit في `lavkoo`، مطابقة أرقام طلبات الدمج القديمة (#1–#18) مع التاريخ الفعلي، وجود كود كل تكامل في الشجرة الحالية، commit إعادة التسمية وما بعده، أصل تطبيق العميل مقارنةً بالمستودع القديم ملفاً ملفاً، أصل تطبيق البائع، والتصادمات والمخاطر.

**الأدوات (قراءة فقط):** `git log / show / diff --stat / ls-tree -r / ls-files / rev-list / rev-parse / merge-base --is-ancestor / cat-file -e / grep / status / branch -a / for-each-ref`، و`grep`/`sed -n`/`wc`/`diff` على الملفات، وأربعة سكربتات Python قارئة فقط محفوظة في مجلد العمل خارج المستودعات (`work-E21/cmp.py … cmp4.py`) تقارن معرّفات الـ blob بين `lavkooapp` وكل commit في `ksaapp98` ثم تصنّف الفروق (متطابق / فرق مسافات فقط / فرق محتوى / ثنائي / موجود في طرف واحد). لم يُنفَّذ أي `checkout` أو `fetch` أو أمر كتابة، ولم يُشغَّل أي كود من المستودعات.

**ما قُرئ:** 443 commit في `lavkoo` (العناوين كلها، والنصوص الكاملة لنحو 25 commit مفصلياً)، 20 commit في `ksaapp98`، commit-ان في `lavkooapp`، ثلاثة في `lavkooappseller`؛ والوثائق `docs/sales-channels/merge-plan.md` (161 سطراً، كاملة)، `shared-file-changes.md` (كاملة)، `decisions.md` (كاملة)، `final-report.md` (770 سطراً: المقاطع 1–12 و176–182 و246–280 و688–770 كاملة، والباقي عبر بحث عن الفروع/PR/الدمج/النشر)، `docs/integrations/hotfix-2.md` و`readiness-report.md` (المقدمات وكل سطر يذكر فرعاً أو commit أو نشراً)، و`MIGRATION.md` و`README.md` و`docs/seller-center-update-study.md` في تطبيق البائع.

**أنماط البحث المسجَّلة:** `git log --all -i --grep` للكلمات `Amazon`، `Trendyol`، `noon`، `Odoo`، `guard`، `safeguard`، `integration-cdef`، `lane-`، `MyFatoorah`، `Paymob`، `Tabby`، `Tamara`، `PR-A … PR-G`، `hotfix`، `deploy`؛ و`git log --all -E --grep='#1[2-6]\b'`؛ و`git grep -il` (غير حسّاس للحالة) للكلمات `amazon|trendyol|noon|odoo|myfatoorah|paymob|tabby|tamara|torod|m5azn` في `app/ config/ routes/ database/ tests/ resources/ Modules/ docs/`؛ و`git log --all -i -S` للكلمتين `tabby` و`tamara`.

**ما لم يُفحص:** الحالة الحيّة لطلبات الدمج والـ Actions في المستودع القديم (غير متاح)؛ ما يعمل فعلياً على الخادم الإنتاجي وأي migrations نُفِّذت؛ أي مستودع قديم لتطبيق البائع (لا يوجد مستودع متاح)؛ السلوك الوظيفي لكود التكاملات نفسه (من اختصاص E11 وE12 وملاحق المدفوعات)؛ محتوى الصور الثنائية (قورنت بالـ blob id فقط).

## 2. الوضع الحالي — كيف يعمل النظام فعلياً

### 2.1 حقائق بنيوية عن المستودعات الأربعة

| المستودع | عدد الـ commits | الجذور | merge commits | الفروع المحلية/البعيدة | الوسوم | حالة شجرة العمل |
|---|---|---|---|---|---|---|
| `lavkoo` | 443 على `main` (444 مع فرع الجلسة) | جذر واحد lavkoo@34dce65 «Initial commit: pharmacyksa.com» (13,728 ملفاً، 2026-11-04) | 42 (13 على الخط الرئيسي + 29 داخلية) | `main` وفرع الجلسة `claude/exciting-shannon-4mh2ok` فقط | لا وسوم | نظيفة (`git status --porcelain --ignored` فارغ) |
| `ksaapp98` | 20 | جذر واحد ksaapp98@afb8974 (2026-10-22) | 2 (ksaapp98@c003565 وksaapp98@79f50be) | `main` فقط | — | نظيفة |
| `lavkooapp` | 2 | جذر واحد lavkooapp@0df8b09 (2026-11-11 17:56 +03:00) | 0 | `main` وفرع الجلسة (على الـ SHA نفسه) | — | نظيفة |
| `lavkooappseller` | 3 | جذر واحد lavkooappseller@afa12db (2026-11-11 18:36 +03:00) | 0 | `main` وفرع الجلسة (على الـ SHA نفسه) | — | نظيفة |

لا يوجد مستودع ضحل (`git rev-parse --is-shallow-repository` = `false` في الأربعة). لا يشترك `lavkooapp` ولا `lavkooappseller` بأي commit مع `ksaapp98` (`git cat-file -e afb8974` و`79f50be` يفشلان فيهما): **التطبيقان الحاليان جذران جديدان بلا تاريخ موروث** — `VERIFIED`.

في المقابل، `lavkoo` **يحمل التاريخ القديم بمعرّفاته الأصلية**: كل SHA تذكره وثائق المستودع بروابط `github.com/fullstackfull/pharmacyksa98` (21 رابطاً في `docs/`) موجود في `lavkoo` وسلفٌ لـ `main` — فُحص 19 SHA منها `be43519`، `0bcabe8`، `79fb408`، `01bb5e5`، `55dc7e0`، `f11d3d5`، `93a9de1`، `a7faee7`، `698aa1e`، `36cd50a`، `8f92f7e`، `412ed58d` وكلها موجودة. والـ 13 merge commit على الخط الرئيسي مُلتزِمها (`committer`) هو `GitHub` (أي دمج من واجهة الويب)، بينما `lavkoo` على GitHub ليس فيه أي PR (معلومة القائد). **الاستنتاج (`INFERRED`):** تاريخ `pharmacyksa98` دُفع كما هو إلى مستودع جديد اسمه `lavkoo`، فانتقلت الـ commits ولم تنتقل بيانات الـ PR؛ الأرقام #1–#18 في رسائل الدمج تعود إلى ترقيم المستودع القديم.

### 2.2 الخط الزمني للـ first-parent في `lavkoo` `main` (21 commit)

| # | SHA | التاريخ (كما في الـ commit) | المؤلف | الموضوع |
|---|---|---|---|---|
| 1 | lavkoo@34dce65 | 2026-11-04 13:40 UTC | fullstackfull | Initial commit: pharmacyksa.com |
| 2 | lavkoo@ef84360 | 2026-11-06 12:49 +03 | fullstackfull | Merge pull request #1 — `claude/clever-newton-wvhbt2` |
| 3 | lavkoo@506429a | 2026-11-06 17:37 +03 | fullstackfull | Merge pull request #2 — الفرع نفسه (Hotfix 1) |
| 4 | lavkoo@665c231 | 2026-11-06 23:51 +03 | fullstackfull | Merge pull request #3 — الفرع نفسه (Hotfix 2) |
| 5 | lavkoo@0bcabe8 | 2026-11-07 16:14 +03 | fullstackfull | Merge pull request #4: unified Saudi address |
| 6 | lavkoo@be43519 | 2026-11-07 21:18 +03 | fullstackfull | Merge pull request #5 — `claude/zealous-goodall-q32ja8` |
| 7 | lavkoo@242a861 | 2026-11-08 00:16 +03 | fullstackfull | Merge pull request #6 — `claude/admiring-bardeen-9cgbbm` |
| 8 | lavkoo@079822d | 2026-11-08 00:59 +03 | fullstackfull | Merge pull request #7 — `claude/affectionate-mendel-ie3wf6` |
| 9 | lavkoo@a687ecb | 2026-11-08 01:36 +03 | fullstackfull | Merge pull request #8 — `claude/torod-unknown-invoice-rejects` |
| 10 | lavkoo@4fa2cb5 | 2026-11-08 20:16 +03 | fullstackfull | Merge pull request #9 — `claude/admiring-bardeen-9cgbbm` |
| 11 | lavkoo@cd54f1e | 2026-11-08 22:40 +03 | fullstackfull | Merge PR #11: realtime shared stock outbox and queue workers |
| 12 | lavkoo@8517d6e | 2026-11-08 23:32 +03 | fullstackfull | Merge pull request #10 — `claude/myfatoorah-pharmacyksa-integration-136hgg` |
| 13 | lavkoo@cbf2cdd | 2026-11-11 12:38 +03 | fullstackfull | Merge pull request #17 — `claude/pharmacyksa-shipping-tests-31q1nu` |
| 14 | lavkoo@f61939f | 2026-11-11 15:54 +03 | fullstackfull | Merge PR #18: App deep-link routes and backend compatibility |
| 15 | lavkoo@352ed68 | 2026-11-11 13:34 UTC (16:34 +03) | Lavkoo Server | Rebrand to Lavkoo and fix server deployment |
| 16–21 | lavkoo@8981b7b ← lavkoo@c16d107 ← lavkoo@8b87ecd ← lavkoo@7edc202 ← lavkoo@7ea0ed6 ← lavkoo@7f42777 | 2026-11-12 00:52–00:56 +03 | fullstackfull | ستة commits ‏`docs(gcc): …` |

ملاحظتان: (أ) PR #11 دُمج **قبل** PR #10، فالترقيم ليس زمنياً. (ب) لا يوجد على الخط الرئيسي ولا في أي رسالة commit ذكرٌ للأرقام #12 إلى #16 (`git log --all -E --grep='#1[2-6]\b'` = صفر نتائج).

### 2.3 كل merge commit على الخط الرئيسي (13) — المقاس من git

الحجم = `git diff --shortstat <merge>^1 <merge>`؛ «commits» = `git rev-list --count <merge>^1..<merge>^2`. في الـ 13 كلها شجرة الدمج **مطابقة بايتاً** لشجرة الأب الثاني (`git rev-parse <m>^{tree}` = `<m>^2^{tree}`)، أي لا حلّ تعارض يدوي في أي دمج رئيسي. وكلها أسلاف لـ `main` بالتعريف.

| PR (ترقيم قديم) | الفرع كما في الرسالة | merge SHA | الأب الثاني | commits | الحجم | المحتوى |
|---|---|---|---|---|---|---|
| #1 | `claude/clever-newton-wvhbt2` | lavkoo@ef84360 | lavkoo@30dcbf6 | 50 | 335 ملفاً، +45,752 / −30 | تكامل Fulfillment ‏(M5AZN + Torod Point)، أدوات النشر overlay، leak guard |
| #2 | الفرع نفسه | lavkoo@506429a | lavkoo@698aa1e | 4 | 43 ملفاً، +3,238 / −118 | Hotfix 1 (مواءمة مخطط الخادم التجريبي) |
| #3 | الفرع نفسه | lavkoo@665c231 | lavkoo@7e886f1 | 4 | 53 ملفاً، +4,005 / −176 | Hotfix 2 (خريطة طلبات Torod وملكية المخزون) |
| #4 | غير مذكور في رسالة الدمج؛ الدموج الداخلية lavkoo@90a44cc وlavkoo@c1e7383 تسمّيه `origin/claude/clever-newton-wvhbt2` | lavkoo@0bcabe8 | lavkoo@fc28808 | 38 | 246 ملفاً، +32,577 / −1,281 | العنوان السعودي الموحّد، National Address، ربط مدن Torod |
| #5 | `claude/zealous-goodall-q32ja8` | lavkoo@be43519 | lavkoo@495aae2 | 150 (منها 15 دمجاً داخلياً لمسارات `lane-*`) | 599 ملفاً، +77,219 / −15 | قنوات البيع (Amazon وTrendyol)، OperationalCosts، dispatch، المخزون المشترك، أوامر الشراء |
| #6 | `claude/admiring-bardeen-9cgbbm` | lavkoo@242a861 | lavkoo@d6522c7 | 6 | 21 ملفاً، +637 / −26 | الجولة الرابعة: رفّ المزوّد من جهة المتجر |
| #7 | `claude/affectionate-mendel-ie3wf6` | lavkoo@079822d | lavkoo@2db9c4a | 1 | 7 ملفات، +414 / −42 | Torod webhook (barcode كرقم فاتورة) |
| #8 | `claude/torod-unknown-invoice-rejects` | lavkoo@a687ecb | lavkoo@7346709 | 1 | 3 ملفات، +81 / −9 | Torod Lastmile برقم فاتورة مجهول |
| #9 «channels» | `claude/admiring-bardeen-9cgbbm` | lavkoo@4fa2cb5 | lavkoo@f8035bd | 12 | 48 ملفاً، +5,313 / −48 | PR-A: نشر منتجات المتجر على القنوات (listings) |
| #11 «stock» | غير مذكور؛ نص الدمج: «PR-B» | lavkoo@cd54f1e | lavkoo@32b6e63 | 10 | 40 ملفاً، +2,760 / −43 | PR-B: المخزون المشترك الفوري (outbox + workers) |
| #10 «MyFatoorah» | `claude/myfatoorah-pharmacyksa-integration-136hgg` | lavkoo@8517d6e | lavkoo@df53a8c | 19 (منها دمج داخلي واحد) | 62 ملفاً، +12,311 / −18 | تكامل MyFatoorah السعودي |
| #17 | `claude/pharmacyksa-shipping-tests-31q1nu` | lavkoo@cbf2cdd | lavkoo@89f1be9 | 125 (112 غير دمج + 13 دمجاً داخلياً) | 254 ملفاً، +43,244 / −229 | PR-C…PR-G + amazon-feeds + shipping tests (انظر 2.4) |
| #18 | غير مذكور | lavkoo@f61939f | lavkoo@68612ac | 2 | 3 ملفات، +214 / −4 | نشر مسارات الروابط العميقة للتطبيق (`config/deeplinks.php`) |

مجموع الـ commits الداخلة عبر الدموج 422، ومعها 13 دمجاً + الجذر + commit إعادة التسمية + 6 توثيق = 443، وهو عدد `main`. الدموج الداخلية الـ 29 (غير الرئيسية) كلها أسلاف لـ `main`: 15 داخل PR #5 (مثل lavkoo@2b8d0be «Merge lane-dispatch»، lavkoo@3a3c972 «Merge lane-costs»، lavkoo@7409d4a «Merge lane-stock»، lavkoo@f7ff507 «Merge lane-po»، lavkoo@6fe52fc «Merge lane-stock-2»، lavkoo@a7faee7 دمج `origin/main` بعد PR #4)، واحد داخل PR #10 (lavkoo@542b176)، و13 داخل PR #17.

### 2.4 تشريح PR #17: أين ذهبت المسارات C وD وE وF وG

الوثيقة `lavkoo:docs/sales-channels/merge-plan.md:127-134` تربط الأحرف بالأرقام القديمة: `claude/pr-c-amazon-completion` = PR #12، `claude/pr-d-trendyol-products` = PR #13، `claude/pr-e-noon` = PR #14، `claude/pr-f-odoo` = PR #15، `claude/integration-cdef` بلا PR، `claude/pr-g-guards` = PR #16 (مسوّدة أساسها `integration-cdef`)، ثم `claude/amazon-feeds` و`claude/pharmacyksa-shipping-tests-31q1nu` بلا PR. أسماء هذه الفروع تظهر حرفياً في رسائل الدموج الداخلية (مثل lavkoo@9b3d524 «Merge branch 'claude/integration-cdef' into claude/pr-g-guards»).

| المسار | رقم PR القديم (بحسب الوثيقة) | رأس المسار | commits غير دمج (فوق lavkoo@8517d6e) | الحجم مقابل lavkoo@8517d6e أو قاعدة المسار | ملفات مضافة / مفقودة منها في `main` | سلف لـ `main`؟ |
|---|---|---|---|---|---|---|
| PR-C Amazon completion | #12 | lavkoo@7efe022 | 11 | 51 ملفاً، +5,071 / −53 | 75 (محسوبة من قاعدة الفرع lavkoo@cd54f1e) / 0 | نعم |
| PR-D Trendyol products | #13 | lavkoo@32249dd | 14 | 48 ملفاً، +5,634 / −38 | 74 (من القاعدة نفسها) / 0 | نعم |
| PR-E Noon | #14 | lavkoo@0366ba7 | 18 | 46 ملفاً، +7,890 / −26 | 23 / 0 | نعم |
| PR-F Odoo | #15 | lavkoo@a907bf0 | 20 | 74 ملفاً، +11,976 / −56 | 57 / 0 | نعم |
| `integration-cdef` = دمج(C,D,E,F) | — | lavkoo@92f0fee | 63 | 183 ملفاً، +30,498 / −143 | 131 / 0 | نعم |
| PR-G guards | #16 | lavkoo@8f92f7e | 8 (فوق lavkoo@92f0fee) | 37 ملفاً، +3,809 / −155 | 21 / 0 | نعم |
| `amazon-feeds` | — | lavkoo@36cd50a | 20 (فوق lavkoo@8f92f7e) | 46 ملفاً، +5,401 / −95 | 15 / 0 | نعم |
| `pharmacyksa-shipping-tests-31q1nu` | #17 | lavkoo@89f1be9 | 21 (فوق lavkoo@36cd50a) | 47 ملفاً، +3,817 / −117 | 9 / 0 | نعم |

المجموع 63 + 8 + 20 + 21 = 112 commit غير دمج، وهو ما يحمله PR #17. الرقم «183 ملفاً» يطابق ما تقوله `lavkoo:docs/sales-channels/merge-plan.md:5`. وكل ملف أضافه أي مسار موجود اليوم في `main` (فحص `git cat-file -e main:<path>` لكل ملف مضاف: صفر مفقود)، باستثناء ما حُذف عمداً لاحقاً (lavkoo@a204f31 «Revert the full-listings reader»، ثم أُعيد أحد الملفين باسمه).

**النتيجة (`VERIFIED`):** محتوى الفروع التي تسمّيها الوثائق PR #12 (Amazon) و#13 (Trendyol) و#14 (Noon) و#15 (Odoo) و#16 (guards) **موجود كاملاً في `lavkoo` `main`** عبر دمج واحد هو lavkoo@cbf2cdd بتاريخ 2026-11-11. لا يوجد merge commit يحمل أياً من هذه الأرقام؛ **الحالة الحيّة لتلك الطلبات في المستودع القديم (مفتوحة / مغلقة كمستوعَبة) `UNKNOWN (missing access: pharmacyksa98)`**.

### 2.5 مطابقة الخطة الموثّقة مع التاريخ الفعلي

| ما تقوله الوثيقة | الموضع | ما يقوله git | الحكم |
|---|---|---|---|
| ترتيب بناء فرع التكامل C ثم D ثم E ثم F | `lavkoo:docs/sales-channels/merge-plan.md:12-19` و`lavkoo:docs/sales-channels/merge-plan.md:88` | السلسلة الأولى لـ lavkoo@92f0fee تبدأ من رأس PR-C (lavkoo@f39dd23) ثم تدمج D (lavkoo@bc3742e) ثم E (lavkoo@561e858) ثم F (lavkoo@905571f)، ثم دموج متابعة لـ D وC وF | مطابق — `VERIFIED` |
| PR #12–#15 «مفتوح»، PR #16 «مسوّدة»، وفرع الشحن «لا PR له» | `lavkoo:docs/sales-channels/merge-plan.md:127-134`، `lavkoo:docs/sales-channels/account-handoff.md:13-14`، `lavkoo:docs/sales-channels/final-report.md:250` | فرع الشحن دُمج لاحقاً باسم «pull request #17» (lavkoo@cbf2cdd) ومعه كل رؤوس C–G | الوثيقة **متقادمة**؛ حالة #12–#16 الحيّة `UNKNOWN` |
| «لم يُدمج شيء في `main`» / «لا دمج في `main` دون موافقة» | `lavkoo:docs/sales-channels/final-report.md:7-8`، `lavkoo:docs/sales-channels/final-report.md:180`، `lavkoo:docs/sales-channels/final-report.md:760`، `lavkoo:docs/sales-channels/merge-plan.md:117`، `lavkoo:docs/sales-channels/merge-plan.md:162` | الدمج حصل فعلاً في 2026-11-11 (lavkoo@cbf2cdd، مُلتزِمه GitHub ومؤلّفه حساب المالك) ولم يُحدَّث `final-report.md` ولا `merge-plan.md` بعده (آخر تعديل لكليهما 2026-11-10)؛ والتعديل الوحيد في `docs/sales-channels/` بعد الدمج سطر واحد في `gap-matrix.md` (lavkoo@a973fb9d، ضمن سلسلة PR #18) لا يذكر PR ولا SHA (بحث عن `#17`، `#18`، `cbf2cdd`، `89f1be9`، `f61939f` في `docs/` خارج `docs/gcc`: صفر نتائج) | الوثائق **متقادمة** — `VERIFIED` |
| الخطوة ١ الموصى بها: دمج فرع الشحن في `main` كتقديم سريع ينتج الشجرة المختبَرة | `lavkoo:docs/sales-channels/merge-plan.md:137-139` و`lavkoo:docs/sales-channels/merge-plan.md:148` | نُفِّذ كـ merge commit لا fast-forward، لكن شجرة lavkoo@cbf2cdd مطابقة بايتاً لشجرة lavkoo@89f1be9 | مطابق في المضمون — `VERIFIED` |
| CI أخضر ثلاث مرات على `1f8a7c2b` و`2f7b4748` و`412ed58d` | `lavkoo:docs/sales-channels/merge-plan.md:138-139` | الرأس المدموج lavkoo@89f1be9 يزيد **11 commit** على lavkoo@412ed58 (34 ملفاً، +2,353 / −65؛ منها 15 ملفاً في `app/` تشمل `OperationalCosts` و`ChannelFeeImporter` و`StockListingGate`) بمؤلف اسمه `integration trial` | نتيجة CI للشجرة المدموجة فعلاً `UNKNOWN (missing access: pharmacyksa98 Actions)` |
| الخطوتان ٢ و٣: إغلاق #12–#15 كمستوعَبة وإغلاق/إعادة توجيه #16 | `lavkoo:docs/sales-channels/merge-plan.md:149-150` | لا أثر في git (إغلاق PR ليس commit) | `UNKNOWN` |
| شجرة `698aa1e` (ما يعمل على الخادم بعد Hotfix 1) مطابقة لدمج PR #2 | `lavkoo:docs/integrations/hotfix-2.md:28` | `git rev-parse 698aa1e^{tree}` = `506429a^{tree}` | مطابق — `VERIFIED` |
| PR #1 و#2 مدموجان، وHotfix 2 له PR مستقل | `lavkoo:docs/integrations/readiness-report.md:3` | lavkoo@ef84360 وlavkoo@506429a، وHotfix 2 = PR #3 (lavkoo@665c231) | مطابق — `VERIFIED` |
| PR #5 دُمج عند `be43519`، والجولة الرابعة عبر PR #6 مع PR #7 و#8 | `lavkoo:docs/sales-channels/final-report.md:3` | lavkoo@be43519، lavkoo@242a861، lavkoo@079822d، lavkoo@a687ecb | مطابق — `VERIFIED` |
| PR #4 دُمج في `main` بـ merge commit `0bcabe8` ثم دُمج `main` في فرع القنوات دون تعارض | `lavkoo:docs/sales-channels/shared-file-changes.md:3` | lavkoo@0bcabe8 ثم lavkoo@a7faee7 | مطابق — `VERIFIED` |
| خطة المراحل السبع PR-A … PR-G | `lavkoo:docs/sales-channels/final-report.md:346` | PR-A = #9، PR-B = #11 (نص lavkoo@cd54f1e)، C–G عبر #17 | مطابق — `VERIFIED` |
| «#9–#11 reported merged؛ #12–#16 reported open» | `lavkoo:docs/gcc/DECISIONS.md:60` و`lavkoo:docs/gcc/STATUS.md:35` | #9 و#10 و#11 لها merge commits؛ محتوى #12–#16 موجود عبر #17 | النصف الأول `VERIFIED`؛ النصف الثاني: «مفتوح» لم يعد يعني «غير موجود في الكود» |

### 2.6 حالة كل وظيفة: «الكود في `main`» ≠ «PR قديم مدموج» ≠ «منشور على الإنتاج»

عدّ الملفات بـ `git ls-files <dir> | wc -l` عند `main`؛ «أول إضافة» بـ `git log --diff-filter=A --reverse`. عمود النشر مبني على الوثائق فقط؛ لا دليل ساكن على ما يعمل فعلياً.

| الوظيفة | الكود في `lavkoo` `main` | دليل ملفّي | الدمج في `lavkoo` | PR القديم وحالته الحيّة | النشر على الإنتاج |
|---|---|---|---|---|---|
| core (قنوات البيع: الحسابات، المزامنة، listings، dispatch، التكاليف) | **VERIFIED-in-lavkoo-main** | `app/Services/SalesChannels` ‏201 ملفاً (صفر عند الجذر)، `app/Services/OperationalCosts` ‏83 ملفاً؛ المزوّد مسجَّل في `lavkoo:bootstrap/providers.php:22` | #5 lavkoo@be43519، #6 lavkoo@242a861، #9 lavkoo@4fa2cb5 | مدموجة بحسب git؛ الحالة الحيّة `UNKNOWN` | `UNKNOWN`؛ المفتاح الرئيسي مطفأ افتراضياً `lavkoo:config/sales_channels.php:23` و`lavkoo:config/sales_channels.php:25`؛ ورسالة lavkoo@95e0ae3 (2026-11-11) تقول (ترجمة لعبارة الرسالة) إن migrations «الوحدة» لم تُشغَّل على الإنتاج قط؛ والوحدة المقصودة هي وحدة التكاليف على الأرجح من سياق الرسالة (`INFERRED`) |
| stock (المخزون المشترك، outbox) | **VERIFIED-in-lavkoo-main** | `app/Services/SalesChannels/Inventory` ‏37 ملفاً؛ `lavkoo:database/migrations/2026_11_13_100000_create_channel_stock_events_table.php:1`؛ `lavkoo:database/migrations/2026_11_10_100000_add_stock_allocation.php:1` | `lane-stock` داخل #5، ثم #11 lavkoo@cd54f1e | #11 مدموج بحسب git | `UNKNOWN` |
| Amazon | **VERIFIED-in-lavkoo-main** | `app/Services/SalesChannels/Amazon` ‏24 ملفاً؛ `lavkoo:app/Services/SalesChannels/Amazon/AmazonConnector.php:50`؛ 102 ملفاً في `app/` تذكر amazon | الأساس في #5؛ الاستكمال (PR-C + amazon-feeds) عبر #17 lavkoo@cbf2cdd | #12 موثَّق «مفتوح» — حيّاً `UNKNOWN` | `UNKNOWN`؛ دفعات Amazon مطفأة `lavkoo:config/sales_channels.php:189` |
| Trendyol | **VERIFIED-in-lavkoo-main** | `app/Services/SalesChannels/Trendyol` ‏12 ملفاً؛ `lavkoo:app/Services/SalesChannels/Trendyol/TrendyolConnector.php:50` | الأساس في #5؛ PR-D عبر #17 | #13 — حيّاً `UNKNOWN` | `UNKNOWN` |
| Noon | **VERIFIED-in-lavkoo-main** | `app/Services/SalesChannels/Noon` ‏8 ملفات (أول إضافة lavkoo@30f22c0)؛ `lavkoo:app/Services/SalesChannels/Noon/NoonConnector.php:66` | PR-E عبر #17 فقط | #14 — حيّاً `UNKNOWN` | `UNKNOWN` |
| Odoo | **VERIFIED-in-lavkoo-main** | `app/Services/Accounting/Odoo` ‏23 ملفاً (أول إضافة lavkoo@c7a0cc8)؛ `lavkoo:app/Services/Accounting/Odoo/OdooClient.php:37`؛ `lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:1` | PR-F عبر #17 فقط | #15 — حيّاً `UNKNOWN` | `UNKNOWN`؛ أربعة مفاتيح مطفأة `lavkoo:config/odoo.php:28-42` |
| safeguards / guards | **VERIFIED-in-lavkoo-main** | 21 ملفاً أضافها PR-G، منها `lavkoo:app/Services/SalesChannels/Attention/AttentionCollector.php:31` و`lavkoo:app/Services/SalesChannels/Support/UncalledOperations.php:31` واختبارات مثل `tests/Feature/SalesChannels/EverythingStartsOffTest.php`؛ و`scripts/ci/leak-guard.php` من PR #1 | PR-G عبر #17 | #16 (مسوّدة على `integration-cdef`) — حيّاً `UNKNOWN` | `UNKNOWN` |
| MyFatoorah | **VERIFIED-in-lavkoo-main** | `app/Services/MyFatoorah` ‏15 ملفاً + 7 migrations ‏`2026_11_08_10000[1-7]` + `config/myfatoorah.php`؛ `lavkoo:app/Services/MyFatoorah/PaymentVerificationService.php:33`؛ `lavkoo:app/Http/Controllers/Payment_Methods/MyFatoorahController.php:33` | #10 lavkoo@8517d6e | #10 مدموج بحسب git | `UNKNOWN`؛ `enabled` مطفأ لكن `verify_when_disabled` مُشغَّل افتراضياً (`lavkoo:config/myfatoorah.php:33` و`lavkoo:config/myfatoorah.php:40`؛ موثَّق في `lavkoo:docs/sales-channels/final-report.md:747`) |
| Paymob | **present-partially** (بوابة القالب فقط) | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:14` (290 سطراً) و10 ملفات أخرى تذكر paymob في `app/` (المجموع 11 مع المتحكّم) ومسار في `routes/web/routes.php`؛ ثمانية منها من الجذر lavkoo@34dce65 فقط، وثلاثة مسّتها commits لاحقة من تكامل MyFatoorah (`app/Enums/GlobalConstant.php` عبر lavkoo@2b249507 وlavkoo@b8989027، و`app/Traits/Payment.php` و`app/Utils/Helpers.php` عبر lavkoo@2b249507)، و**المتحكّم نفسه لم يمسّه أي commit بعد الجذر**؛ صفر ذكر في `config/ database/ tests/` | لا دمج خاص (جاء مع الجذر) | لا PR | `UNKNOWN` (إعدادات البوابة في قاعدة البيانات) |
| Tabby | **MISSING-in-lavkoo** | `git grep -il tabby` على الشجرة كلها: ملفان فقط هما `lavkoo:docs/gcc/DECISIONS.md:15` و`DISCOVERY_PROMPT_01.md`؛ صفر عند الجذر؛ `git log --all -i -S tabby` يعيد commit-ي التوثيق فقط؛ وصفر في `lib/` و`test/` للتطبيقين | — | — | — |
| Tamara | **MISSING-in-lavkoo** | البحث نفسه بالنتيجة نفسها | — | — | — |
| Fulfillment (Torod Point / M5AZN) | **VERIFIED-in-lavkoo-main** | `app/Services/Fulfillment` ‏158 ملفاً (أول إضافة lavkoo@c819154)، ومنها `Providers/TorodPoint` و`Providers/M5azn` | #1–#3، #7، #8 | مدموجة بحسب git | **موثَّق** أن الخادم التجريبي (= الإنتاج القائم) يعمل بشجرة `698aa1e` بعد Hotfix 1 (`lavkoo:docs/integrations/hotfix-2.md:28`، `lavkoo:docs/integrations/pilot-deployment.md:175-176`، `lavkoo:.github/workflows/ci.yml:189`)؛ وHotfix 2 «prepared, not deployed» (`lavkoo:docs/integrations/hotfix-2.md:3`)؛ ما بعد ذلك `UNKNOWN` |
| العنوان الموحّد | **VERIFIED-in-lavkoo-main** | `app/Services/Address` ‏27 ملفاً (أول إضافة lavkoo@43f95a0) | #4 lavkoo@0bcabe8 | مدموج بحسب git | `UNKNOWN` |
| MyFatoorah في تطبيق العميل | **VERIFIED-in-lavkooapp** | انظر 2.9 | — | PR #1 في `ksaapp98` (ksaapp98@c003565) | `UNKNOWN` (لا دليل على إصدار منشور) |

### 2.7 عدّ الكلمات المفتاحية (غير حسّاس للحالة) — ملفات / أسطر مطابقة

المصدر: `git grep -il` و`git grep -ih … | wc -l` عند شجرة العمل.

| الكلمة | `app/` | `config/` | `routes/` | `database/` | `tests/` | `resources/` | `Modules/` | `docs/` |
|---|---|---|---|---|---|---|---|---|
| amazon | 102 / 921 | 4 / 60 | 1 / 1 | 6 / 53 | 81 / 1,039 | 41 / 161 | 0 | 22 / 231 |
| trendyol | 61 / 445 | 2 / 33 | 2 / 5 | 7 / 25 | 104 / 1,211 | 7 / 73 | 0 | 19 / 202 |
| noon | 32 / 558 (منها 6 أسطر `afternoon` في 5 ملفات غير ذات صلة) | 2 / 12 | 0 | 2 / 4 | 28 / 862 | 12 / 18 | 0 | 13 / 89 |
| odoo | 44 / 803 | 2 / 18 | 1 / 28 | 3 / 48 | 28 / 1,140 | 14 / 586 | 0 | 14 / 102 |
| myfatoorah | 31 / 480 | 1 / 56 | 4 / 26 | 7 / 48 | 13 / 361 | 5 / 53 | 0 | 7 / 88 |
| paymob | 11 / 32 | 0 | 1 / 5 | 0 | 0 | 10 / 16 | 0 | 2 / 2 |
| tabby | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 2 / 3 |
| tamara | 0 | 0 | 0 | 0 | 0 | 0 | 0 | 2 / 3 |
| torod | 69 / 267 | 2 / 14 | 2 / 30 | 6 / 7 | 129 / 1,916 | 13 / 184 | 0 | 35 / 278 |
| m5azn | 60 / 188 | 2 / 9 | 1 / 14 | 5 / 6 | 73 / 662 | 8 / 145 | 0 | 34 / 207 |

رسائل الـ commits (`git log --all -i --grep`، وكل النتائج على `main`): Amazon 61، Trendyol 42، noon 32، Odoo 31، guard 112، safeguard 0، `integration-cdef` 12، `lane-` 10، MyFatoorah 22، PR-C 14، PR-D 19، PR-E 18، PR-F 25، PR-G 13، Paymob 0، Tabby 0، Tamara 0.

### 2.8 commit إعادة التسمية lavkoo@352ed68 وما بعده

**المصدر:** مؤلّفه ومُلتزِمه هوية باسم `Lavkoo Server` (2026-11-11 13:34 UTC)، مباشرةً فوق lavkoo@f61939f على الخط الرئيسي، دون PR ودون merge commit. الحجم: 15 ملفاً، +73 / −26.

| الفئة | الملفات | طبيعة التغيير |
|---|---|---|
| إعداد | `lavkoo:config/app.php:30` | إضافة مفتاح `mode` يقرأ `APP_MODE` (الافتراضي `live`) — هذا هو «fix server deployment» |
| ثابت النطاق | `lavkoo:config/system-addons.php:10` | `pharmacyksa.com` ← `lavkoo.com` |
| SEO | `lavkoo:robots.txt:3` | رابط الـ sitemap إلى `lavkoo.com` |
| رسائل API | `lavkoo:app/Http/Controllers/Api/DeliverySyriaWebhookController.php:59` و`lavkoo:app/Http/Controllers/Api/DeliverySyriaWebhookController.php:95` | «Pharmacy Syria» ← «Lavkoo» في نص الاستجابة |
| نصوص تُكتب في نظام خارجي | `lavkoo:app/Services/Accounting/Odoo/OdooDraftBuilder.php:247` و`lavkoo:app/Services/Accounting/Odoo/OdooUndoLink.php:134` | بادئة السرد «PharmacyKSA ledger entry #» ← «Lavkoo ledger entry #» (الاختبار `lavkoo:tests/Feature/Accounting/OdooDraftBuilderTest.php:155` يطابق الجزء «ledger entry #» فقط فلا يتأثر) |
| Web push | `lavkoo:firebase-messaging-sw.js:6-12` ونسخته في `public/` | استبدال قيم إعداد Firebase للويب كلها (قيم `<REDACTED>`؛ النوع: api key / project id / sender id / app id). مقارنة تجزئة تُظهر أن `projectId` الجديد **يطابق** مشروع `google-services.json` في `lavkooapp` و`lavkooappseller`، وأن القديم يطابق مشروع `ksaapp98` |
| ترجمات | `resources/lang/en/new-messages.php` (+7) و`resources/lang/sa/new-messages.php` (+39) | مفاتيح مضافة |
| واجهات | 5 ملفات Blade (header، register، tracking-page، delivery-syria-settings، kohl/gallery) | تحميل أعلام `intl-tel-input` محلياً بدل `pharmacysyria.com`، عنوان webhook المعروض، توسيط أيقونة الضيف، اسم المتجر |

بعده ستة commits توثيق (lavkoo@8981b7b … lavkoo@7f42777) تضيف `docs/gcc/*` وسبعة أسطر في `CLAUDE.md` فقط (`git diff --stat 352ed68 7f42777`: 6 ملفات، +358)، ثم commit فهرس الجلسة lavkoo@046d623 على فرع الجلسة (ملف واحد تحت `docs/gcc/audits/`). **لا تغيير كود بعد lavkoo@352ed68** — `VERIFIED`.

بقايا الأسماء القديمة بعد إعادة التسمية (عدّ ملفات، `git grep -il`): `syria` في 29 ملفاً في `app/` و46 في `resources/` ومسارين في `routes/` (مثل `lavkoo:routes/admin/routes.php:1482`)؛ `sixvalley|6valley|sixamtech` في 12 ملفاً في `app/` و43 في `resources/`؛ `pharmacyksa` في `lavkoo:config/deeplinks.php:34` (تعليق) و4 ملفات اختبار. إعادة التسمية **جزئية وسطحية** وليست إعادة هيكلة.

### 2.9 سلالة تطبيق العميل: `lavkooapp` مقابل `ksaapp98`

**تاريخ `ksaapp98`:** 7 commits يدوية لمطوّرين بشريين (أسماؤهم `<REDACTED>`) من ksaapp98@afb8974 إلى ksaapp98@750468d (إعدادات iOS/التوقيع)، ثم PR #1 (MyFatoorah: ksaapp98@59715ea وksaapp98@f21c0d7، دمج ksaapp98@c003565 في 2026-11-08 23:31 +03 — بفارق دقيقة عن دمج PR #10 في الـ backend)، ثم PR #2 (تسعة commits من ksaapp98@1a34a4d إلى ksaapp98@cbb8239، دمج ksaapp98@79f50be في 2026-11-11 15:54 +03 — في الدقيقة نفسها مع PR #18 في الـ backend).

**`lavkooapp`:** جذر جديد lavkooapp@0df8b09 بعد ساعتين من دمج PR #2، ثم lavkooapp@eedbbb8 الذي يضيف `CLAUDE.md` فقط. مقارنة الـ blobs لشجرة lavkooapp@eedbbb8 (1,259 ملفاً) مع كل commit من الـ 20 في `ksaapp98`: **لا تطابق تام مع أي commit**؛ أقرب المرشحين ksaapp98@c003565 وksaapp98@58997df (1,093 ملفاً متطابقاً) ثم ksaapp98@79f50be (1,092). `pubspec.yaml` و`pubspec.lock` متطابقان مع كل commit منذ ksaapp98@84f181e (الاسم `syriapharmacy` والإصدار `3.0.6+48` في `lavkooapp:pubspec.yaml:1` و`lavkooapp:pubspec.yaml:5`).

**التصنيف مقابل الرأس القديم ksaapp98@79f50be (1,267 ملفاً):**

| الفئة | متطابق | فرق مسافات فقط | فرق محتوى | ثنائي مختلف | في `lavkooapp` فقط | في `ksaapp98` فقط |
|---|---|---|---|---|---|---|
| `lib/` | 674 | 20 | 14 | 0 | 1 | 0 |
| `test/` | 10 | 0 | 1 | 0 | 0 | 6 |
| `android/` | 16 | 0 | 6 | 22 | 1 | 1 |
| `ios/` | 79 | 1 | 2 | 0 | 0 | 0 |
| `assets/images` | 155 | 0 | 0 | 90 | 0 | 0 |
| `assets/language` | 2 | 3 | 0 | 0 | 0 | 0 |
| بقية `assets/` | 109 | 0 | 0 | 0 | 0 | 0 |
| CI / أدوات | 2 | 0 | 0 | 0 | 0 | 2 |
| وثائق | 2 | 0 | 0 | 0 | 1 | 2 |
| الجذر و`.idea` | 43 | 0 | 4 | 0 | 1 | 1 |
| **المجموع** | **1,092** | **24** | **27** | **112** | **4** | **12** |

**هل محتوى PR #1 (MyFatoorah) موجود؟ نعم — `VERIFIED`.** من 13 ملفاً غيّرها PR #1: خمسة متطابقة بايتاً مع ما بعد الدمج (`lavkooapp:lib/features/checkout/domain/models/myfatoorah_payment_status.dart:1`، `lavkooapp:lib/features/checkout/domain/services/myfatoorah_payment_service.dart:1`، `lib/features/splash/controllers/splash_controller.dart`، `lavkooapp:test/myfatoorah_payment_status_test.dart:1`، `lavkooapp:test/myfatoorah_return_url_test.dart:1`)؛ `checkout_screen.dart` يختلف بالمسافات فقط؛ `digital_payment_order_place_screen.dart` و`app_constants.dart` وملفات اللغة تحمل نسخة PR #2 اللاحقة (التي تتضمن PR #1).

**هل محتوى PR #2 موجود؟ جزئياً — `VERIFIED`.** من 41 ملفاً غيّرها PR #2:

| الجزء | الحالة في `lavkooapp` | الدليل |
|---|---|---|
| كود `lib/` (24 ملفاً) | 21 تحمل محتوى PR #2: واحد متطابق بايتاً (`meta_tracking_service.dart`)، 18 بفرق مسافات/سطر أخير فقط (مثل `checkout_controller.dart`، `checkout_repository.dart`، `order_controller.dart`، `wallet_controller.dart`، `route_healper.dart`، `deep_link_resolver.dart`)، `facebook_login_controller.dart` بسطر فارغ واحد، و`app_constants.dart` بسطرَي العلامة فقط | مقارنة موحَّدة المسافات = صفر فروق محتوى |
| إصلاح فيض التخطيط (overflow) في ksaapp98@1a34a4d (ملفان) | **غائب**: الملفان بنسخة ما قبل PR #2 | `lavkooapp:lib/features/dynamic_theme/renderers/content_section_renderer.dart:801-808` (نص `Text` بلا `Flexible`؛ وسطر `debugPrint` تشخيصي في `lavkooapp:lib/features/dynamic_theme/renderers/content_section_renderer.dart:742-743`) مقابل `ksaapp98:lib/features/dynamic_theme/renderers/content_section_renderer.dart:798-808`؛ و`lavkooapp:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:703-707` مقابل `ksaapp98:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:703-709` |
| ملف حذفه ksaapp98@e0058a2 | **ما زال موجوداً** ككود ميت | `lavkooapp:lib/features/wallet/screens/add_fund_to_wallet_screen.dart:13` (216 سطراً)؛ لا مستورِد له، والمسار في `lavkooapp:lib/helper/route_healper.dart:1819-1823` لا يفتحه — انظر SEC-E21-03 |
| الاختبارات | **غائبة**: ستة اختبارات مضافة (`ksaapp98:test/api_journeys_test.dart:1`، `ksaapp98:test/order_placement_test.dart:1`، `ksaapp98:test/payment_outcome_source_test.dart:1`، `ksaapp98:test/release_log_hygiene_test.dart:1`، `ksaapp98:test/release_version_test.dart:1`، `ksaapp98:test/secret_scan_test.dart:1`) واختبار محدَّث ناقص 20 سطراً (`lavkooapp:test/deep_link_resolver_test.dart:1` بـ 91 سطراً مقابل 111؛ الحالة المفقودة تبدأ في `ksaapp98:test/deep_link_resolver_test.dart:74`) | `git ls-tree` |
| CI والأدوات | **غائبة**: `ksaapp98:.github/workflows/flutter.yml:1` (344 سطراً) و`ksaapp98:tool/secret_scan.dart:1` (248 سطراً)؛ لا مجلد `.github` في `lavkooapp` (`lavkooapp:tool/` موجود ويضم أداتي توليد أصول الإطلاق فقط، وهما متطابقتان مع القديم) | `ls -a` |
| الوثائق | **غائبة**: `ksaapp98:docs/app-backend-compatibility.md:1` (3,857 سطراً — مصفوفة توافق التطبيق مع الـ backend) و`ksaapp98:docs/release-readiness.md:1` (291 سطراً) | `git ls-tree` |
| ملفات اللغة | `ar.json` و`en.json` متطابقان مع ما بعد PR #2؛ `bn`/`es`/`hi` فرق مسافات فقط | — |

**طريقة النقل (`INFERRED`):** الملفات العشرون ذات «فرق المسافات فقط» تختلف في الإزاحة وغياب السطر الأخير حتى في ملفات لم يمسّها PR #1 ولا PR #2 (مثل `wallet_screen.dart`)، ومحتوى PR #2 حاضر في `lib/` وغائب في `test/` و`tool/` و`.github/` و`docs/`، وأول commits الفرع (ksaapp98@1a34a4d) غائب. هذا لا يطابق لقطة لأي commit؛ الأرجح نسخة عمل محلية نُقلت إليها ملفات `lib/` يدوياً ثم أُعيدت تسميتها.

**فروق العلامة والهوية (كلها في الـ commit الأول lavkooapp@0df8b09):**

| البند | `ksaapp98` | `lavkooapp` |
|---|---|---|
| اسم التطبيق والخادم | `ksaapp98:lib/utill/app_constants.dart:7` («Ksa Cosmetics») و`ksaapp98:lib/utill/app_constants.dart:39` (`pharmacyksa.com`) | `lavkooapp:lib/utill/app_constants.dart:7` («LAVKOO») و`lavkooapp:lib/utill/app_constants.dart:39` (`lavkoo.com`)؛ `appVersion` لم يتغيّر (`lavkooapp:lib/utill/app_constants.dart:29`) |
| Android applicationId | `ksaapp98:android/app/build.gradle.kts:19` و`ksaapp98:android/app/build.gradle.kts:31` (`com.ksacosmetics.app`) | `lavkooapp:android/app/build.gradle.kts:19` و`lavkooapp:android/app/build.gradle.kts:31` (`com.lavkoo.app`)، مع نقل `MainActivity.kt` إلى مسار الحزمة الجديد |
| iOS bundle id | `com.ksacosmetics.app` | **لم يتغيّر**: `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414` ما زال `com.ksacosmetics.app` (الملف متطابق بايتاً مع القديم)، بينما إعداد Firebase لـ iOS يعلن `com.lavkoo.app` (`lavkooapp:ios/Runner/GoogleService-Info.plist:9-10` و`lavkooapp:lib/firebase_options.dart:66`) — **تعارض داخلي** |
| Firebase | مشروع قديم | مشروع جديد (`google-services.json` −41/+8، `GoogleService-Info.plist` −17/+11، `firebase_options.dart` −13/+12)؛ يطابق مشروع الـ backend بعد lavkoo@352ed68 |
| ربط تطبيق البائع | `ksaapp98:android/app/src/main/AndroidManifest.xml:37` (`com.ksa.seller`) و`ksaapp98:android/app/src/main/AndroidManifest.xml:40` (`ksaseller`) | `lavkooapp:android/app/src/main/AndroidManifest.xml:44` يستعلم عن `com.lavkoo.seller`، بينما `lavkooapp:lib/features/more/screens/become_merchant_screen.dart:101-102` يستعمل `com.lavkoocosmeticsmanager.app` — والأخير هو المعرّف الفعلي لتطبيق البائع (`lavkooappseller:android/app/build.gradle.kts:39`)؛ **الاستعلام في الـ manifest لا يطابق** |
| نطاق الروابط العميقة | `pharmacyksa.com` | `lavkooapp:android/app/src/main/AndroidManifest.xml:131` (`lavkoo.com`) |
| مفتاح خرائط Google في الـ manifest | قيمة فعلية (`<REDACTED>`) | **قيمة نائبة** غير مستبدلة (`lavkooapp:android/app/src/main/AndroidManifest.xml:76-77`) |
| الألوان والأصول | بنفسجي | أخضر: 11 ملف `lib/` (مثل `custom_theme_colors.dart`، `dark_theme.dart`، `splash_screen.dart`)، 90 صورة في `assets/images`، 22 أيقونة Android |
| اسم مشروع IDE | `.idea/ksa_cosmatics.iml` | `.idea/lavkoo_cosmatics.iml` |

### 2.10 سلالة تطبيق البائع `lavkooappseller`

| commit | التاريخ | المحتوى |
|---|---|---|
| lavkooappseller@afa12db | 2026-11-11 18:36 +03 | «first commit: lavkooappseller initial setup» — 1,179 ملفاً (747 في `lib/`، 278 في `assets/`، 19 اختباراً، `tool/check_translations.dart`، `.github/workflows/ci.yml`). الهوية عنده **مختلطة**: Android ‏`com.lavkoocosmeticsmanager.app` والاسم «Lavkoo Seller»، لكن iOS ‏`com.ksa.seller` / «KSA Seller»، و`appName` = «Seller Center KSA» و`companyName` = «KSA Cosmetics»، و`baseUrl` = `lavkoo.com` |
| lavkooappseller@e56a533 | 2026-11-11 21:21 +02 | «update brand identity to Seller Center LV…» — 80 ملفاً (+394 / −179): 9 مضافة، 38 محذوفة (أيقونات iOS قديمة)، 33 معدَّلة؛ النصّي منها 11 ملفاً: توحيد bundle id في iOS إلى `com.lavkoocosmeticsmanager.app` (`lavkooappseller:ios/Runner.xcodeproj/project.pbxproj:443`)، الاسم «Seller Center LV» (`lavkooappseller:android/app/src/main/AndroidManifest.xml:36`، `lavkooappseller:lib/utill/app_constants.dart:6-7`)، `firebase_options.dart`، تحصين تهيئة Firebase في `lib/main.dart`، تغيير `User-Agent`، وإعادة تنسيق `app_constants.dart` (+322 / −144، أغلبها التفاف أسطر) |
| lavkooappseller@8ea0092 | 2026-11-12 00:55 +03 | `CLAUDE.md` فقط (+26) |

**قرائن الأصل (`VERIFIED` كنصوص، والأصل الأبعد `UNKNOWN`):**

- `lavkooappseller:README.md:1` و`lavkooappseller:README.md:3-4`: «Siller Center — seller app … a customised fork of the Sixvalley seller app».
- `lavkooappseller:pubspec.yaml:1` الاسم الداخلي `syriacosmeticsmanger` والإصدار `3.1.6+49` (`lavkooappseller:pubspec.yaml:8`)؛ و`lavkooappseller:MIGRATION.md:173` يذكر «Syrian pound scale»؛ و`lavkooappseller:MIGRATION.md:55` يقول إن إعداد Firebase الملتزَم وقت كتابته «belongs to a different business».
- `lavkooappseller:MIGRATION.md:48` و`lavkooappseller:MIGRATION.md:50` يعلنان المعرّف `com.kuwaitcosmetics.seller` — **لا يطابق الكود الحالي** (`lavkooappseller:android/app/build.gradle.kts:23` و`lavkooappseller:android/app/build.gradle.kts:39` = `com.lavkoocosmeticsmanager.app`).
- `lavkooappseller:docs/seller-center-update-study.md:3` مؤرَّخة 2026-08-23، وتقول إن الهوية ما زالت هوية القالب (`lavkooappseller:docs/seller-center-update-study.md:13` و`lavkooappseller:docs/seller-center-update-study.md:69`) — متقادمة أيضاً.
- مجلد Kotlin باسم `…/seller/` وحزمته `com.lavkoocosmeticsmanager.app` (`lavkooappseller:android/app/src/main/kotlin/com/lavkoocosmeticsmanager/seller/MainActivity.kt:1`)؛ ومخطط الرابط `lavkooseller` (`lavkooappseller:android/app/src/main/AndroidManifest.xml:92`) يطابق ما يستعمله تطبيق العميل.

**القراءة:** الكود مرّ بهويات تجارية متعاقبة (قالب Sixvalley ← Syria ← «Siller Center» ← Kuwait ← KSA ← Lavkoo) ووثائقه الثلاث تصف مراحل أقدم من الشجرة الحالية. لا مستودع قديم متاح للمقارنة؛ لذلك لا يمكن القول أيّ إصلاحات ضاعت أو أُضيفت عند النقل — `UNKNOWN`.

### 2.11 تواريخ migrations المتقدّمة على التقويم وحالة العمل غير الملتزَم

- 36 migration في `database/migrations` ببادئة ≥ `2026_11_05`. منها **24 تحمل تاريخاً لاحقاً لتاريخ الـ commit الذي أضافها**، و**9 بادئتها بعد تاريخ التدقيق** (2026-11-12): من `2026_11_13_100000_create_channel_stock_events_table.php` (أُضيف 2026-11-08 في lavkoo@eecb8a2) إلى `lavkoo:database/migrations/2026_11_22_100000_create_amazon_feed_submissions_table.php:1` (أُضيف 2026-11-09 في lavkoo@4c47205)، مروراً بـ `lavkoo:database/migrations/2026_11_17_100000_create_amazon_report_runs_table.php:1` و`lavkoo:database/migrations/2026_11_17_200000_create_channel_return_decisions_table.php:1` و`lavkoo:database/migrations/2026_11_18_100000_create_channel_package_splits_table.php:1` و`lavkoo:database/migrations/2026_11_20_100000_create_channel_attention_reviews_table.php:1`. **التاريخ في الاسم بادئة ترتيب بين المسارات لا تاريخ تقويمي** — `VERIFIED`.
- ثلاث بادئات مكرّرة بين مسارَي العنوان والقنوات: `2026_11_07_100000` و`2026_11_07_100100` و`2026_11_07_100200` (ملفان لكل بادئة).
- migration واحدة أُعيدت تسميتها داخل التاريخ أثناء حلّ دمج (`2026_11_09_100000_create_channel_order_dispatches_table.php` ← `2026_11_09_200000_…` في lavkoo@3a3c972).
- 9 مسارات فقط حُذفت منذ الجذر: قارئ تقرير Amazon واختباره في lavkoo@a204f31 (ملفّا خدمة واختبار واحد؛ أحد ملفَّي الخدمة أُعيد لاحقاً)، اختبار حارس في lavkoo@de04f28، وخمسة ملفات تجريبية في lavkoo@9d099f5؛ لا migration محذوفة.
- شجرات العمل الأربع نظيفة؛ لا ملفات غير متتبَّعة ولا مُتجاهَلة ولا stash. المستودعات الثلاثة الحالية واقفة على فرع الجلسة `claude/exciting-shannon-4mh2ok`؛ في `lavkoo` يزيد عن `main` بـ lavkoo@046d623 (ملف README للتدقيق)، وفي التطبيقين يساوي `main`.

## 3. افتراضات المتجر الواحد / الدولة / العملة

افتراضات بنيوية ظاهرة في هذا المجال (السلالة والهوية والإعداد):

1. **نطاق واحد كثابت في الكود**: `lavkoo:config/system-addons.php:10` و`lavkoo:robots.txt:3`، و`baseUrl` ثابت واحد في كل تطبيق (`lavkooapp:lib/utill/app_constants.dart:39`، `lavkooappseller:lib/utill/app_constants.dart:11`). الانتقال من `pharmacyksa.com` إلى `lavkoo.com` تمّ بتعديل ثوابت، لا بإعداد لكل متجر.
2. **مشروع Firebase واحد لكل شيء**: ملف service worker واحد للويب (`lavkoo:firebase-messaging-sw.js:6-12`) والتطبيقان على المشروع نفسه؛ لا تمييز بالسوق.
3. **هوية تطبيق واحدة لكل منصة**: `applicationId` واحد لكل تطبيق؛ وقد غُيّر عند إعادة التسمية (Android العميل) قبل صدور توجيه «لا تغيّر معرّفات الحزم» في `lavkooapp:CLAUDE.md:3`.
4. **خط أساس نشر واحد لخادم واحد**: `PILOT_BASELINE` بقيمة افتراضية وحيدة (`lavkoo:.github/workflows/ci.yml:189`)، والوثائق تفترض أن «الخادم التجريبي هو الإنتاج القائم» (`lavkoo:docs/integrations/readiness-report.md:13`).
5. **مفاتيح تشغيل عامة عبر `.env`**: كل مفاتيح التكاملات (`SALES_CHANNELS_ENABLED`، `ODOO_ENABLED`، `MYFATOORAH_ENABLED` …) قيمة واحدة للتثبيت كله (`lavkoo:config/sales_channels.php:23`، `lavkoo:config/odoo.php:28`، `lavkoo:config/myfatoorah.php:33`) وليست لكل متجر أو كيان قانوني.
6. **نصوص مرجعية بهوية واحدة تُكتب في أنظمة خارجية**: بادئة سرد قيود Odoo (`lavkoo:app/Services/Accounting/Odoo/OdooDraftBuilder.php:247`) اسم علامة واحد لا اسم كيان قانوني.
7. **أسماء موروثة تدلّ على أصل غير خليجي**: `DeliverySyria*`، `syriapharmacy`، `syriacosmeticsmanger` — لا تحمل دلالة سوق حالية لكنها تؤكد أن الكود وُلد لمتجر واحد في بلد واحد ثم أُعيدت تسميته مراراً.

## 4. الأثر على قرارات GCC المعتمدة

| القرار / الأساس | ما يدعمه اليوم في هذا المجال | ما يتعارض أو ينقص | الدليل |
|---|---|---|---|
| Guardrail 5: لا تخلط PRs المستودع القديم بالجديد؛ تحقّق من الـ commits والسلالة والنشر كلٌّ على حدة | التاريخ القديم محفوظ بمعرّفاته في `lavkoo`، فكل ادّعاء قابل للتحقق محلياً | الوثائق ما زالت تقول «#12–#16 مفتوحة / لا دمج»؛ والنشر الفعلي `UNKNOWN` | lavkoo@cbf2cdd؛ `lavkoo:docs/gcc/DECISIONS.md:60`؛ `lavkoo:docs/sales-channels/merge-plan.md:127-134` |
| Guardrail 2: لا دمج لطلبات معلّقة دون موافقة | لا يوجد في `lavkoo` أي فرع أو PR معلّق؛ كل شيء في `main` | إن كانت #12–#16 ما زالت مفتوحة في القديم فدمجها هناك لا أثر له على `lavkoo`، وإعادة دمجها هنا تكرار | 2.4 |
| Foundation: keep marketplace integrations and Odoo | Amazon وTrendyol وNoon وOdoo كلها في `main` مع اختباراتها | رسالة lavkoo@95e0ae3 تذكر أن migrations وحدة التكاليف لم تُشغَّل على الإنتاج، ولا يُعرف الأمر ذاته لبقية الوحدات (`UNKNOWN`)؛ CI للشجرة المدموجة غير معلوم | lavkoo@95e0ae3؛ 2.5 |
| Foundation: keep Paymob and MyFatoorah, examine Tabby/Tamara | MyFatoorah تكامل كامل (backend + تطبيق العميل)؛ Paymob متحكّم القالب | **لا كود Tabby/Tamara إطلاقاً** — يُبنى من الصفر؛ Paymob بلا اختبارات ولا إعداد في `config/` | 2.6 و2.7 |
| Foundation: one fulfillment source … keep Torod/M5AZN | كود Fulfillment كامل، وأدوات نشر overlay موثّقة | خط الأساس المنشور الموثَّق (`698aa1e`) أقدم بـ 11 دمجاً من `main` | `lavkoo:docs/integrations/hotfix-2.md:28` |
| GCC-09 (تطبيق العميل والبائع بمكوّنات مشتركة) | محتوى PR #1 وأغلب PR #2 حاضر في `lavkooapp` | غياب CI والاختبارات ومصفوفة التوافق، وإصلاحان في محرّك السمة الديناميكية؛ تعارض معرّفات iOS | 2.9 |
| GCC-10 (هوية مشتركة مع استثناءات لكل بلد) | — | الهوية ثوابت في الكود ومعرّفات حزم؛ إعادة التسمية الأخيرة تمت بتعديل ثوابت يدوي على الخادم | lavkoo@352ed68 |
| GCC-12 / القيود المؤقتة (notifications market-scoped) | — | مشروع Firebase واحد، وقد بُدِّل للتوّ؛ رموز push لمستخدمي التطبيق القديم لا تنتقل | 2.8 |
| Guardrail 4 (تطبيق البائع وبوابة الإصدار) | للبائع CI و19 اختباراً منها `app_version_gate_test.dart` | وثائقه الثلاث متقادمة ومعرّفاته تغيّرت مرتين في يوم واحد؛ لا أصل للمقارنة | 2.10 |
| Guardrail 6 (التدقيق أولاً بأدلة SHA) | ممكن بالكامل للـ backend وتطبيق العميل | غير ممكن لحالة PR الحيّة والنشر | القسم 7 |

## 5. مصفوفة Preserve / Extend / Refactor / New

| المكوّن | التصنيف | السبب | أقل إضافة موصى بها |
|---|---|---|---|
| تاريخ `lavkoo` بمعرّفاته الأصلية (443 commit) | Preserve | هو السجل الوحيد القابل للتحقق لكل ما بُني؛ لا force push ولا squash | حماية الفرع `main` |
| دمج lavkoo@cbf2cdd وما يحمله (C–G) | Preserve | الكود المختبَر كشجرة واحدة؛ تفكيكه يعيد تعارضات حُلّت | — |
| مفاتيح التشغيل المطفأة افتراضياً للتكاملات | Preserve ثم Extend | نمط «يُسلَّم خامداً» يخدم تفعيل الأسواق تباعاً | ربط التفعيل بالمتجر/الكيان القانوني بدل `.env` العام (تصميم لاحق) |
| أدوات النشر overlay و`PILOT_BASELINE` | Preserve ثم Extend | الطريقة الوحيدة الموثّقة لمعرفة ما يعمل على الخادم | تسجيل SHA المنشور فعلياً في وثيقة حالة بعد كل نشر |
| وثائق `docs/sales-channels/*` و`docs/integrations/*` | Refactor (توثيق فقط) | تصف حالة ما قبل دمج #17 | إضافة ملاحظة «الحالة بعد 2026-11-11» بدل تعديل النص التاريخي |
| `docs/gcc/DECISIONS.md` و`STATUS.md` (فقرة PRs القديمة) | Refactor (توثيق فقط) | «#12–#16 open» لم تعد تعني غياب الكود | تصويب بعد اعتماد هذا الملحق |
| اختبارات وCI وأداة فحص الأسرار ووثيقتا التوافق في `ksaapp98` | New في `lavkooapp` (نقل) | فُقدت عند النقل وهي شبكة الأمان لمسارات الدفع والطلب | نقلها كما هي في أول مرحلة تنفيذ معتمدة |
| إصلاحا ksaapp98@1a34a4d في محرّك السمة | New في `lavkooapp` (نقل) | فيض تخطيط مع المحتوى العربي | نقل الملفين |
| معرّفات الحزم ومشاريع Firebase الحالية | Preserve (حتى قرار صريح) | توجيه المالك يمنع إعادة التسمية الآلية | حسم تعارض iOS في تطبيق العميل بقرار مكتوب |
| migrations ببادئات مستقبلية حتى `2026_11_22` | Preserve | ترتيبها جزء من عقد المسارات | أي migration لـ GCC تأخذ بادئة لاحقة لـ `2026_11_22_100000` |
| Tabby / Tamara | New | لا كود | دراسة مستقلة بعد قرار المالك |
| وثائق تطبيق البائع (`MIGRATION.md`، دراسة أغسطس) | Refactor (توثيق فقط) | معرّفات وهويات لا تطابق الشجرة | ملاحظة تصويب |

## 6. الفجوات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة |
|---|---|---|---|---|---|---|
| E21-F01 | `lavkoo` يحمل تاريخ المستودع القديم بمعرّفاته: 13 دمجاً رئيسياً بأرقام #1–#11 و#17 و#18 مُلتزِمها GitHub، ولا PRs في `lavkoo` نفسه | INFO | VERIFIED (التاريخ) / INFERRED (طريقة النقل) | lavkoo@ef84360 … lavkoo@f61939f؛ `lavkoo:docs/integrations/pilot-deployment.md:501` | كل رقم PR في الوثائق والرسائل ترقيم قديم؛ أي PR جديد في `lavkoo` سيبدأ من #1 ويتصادم اسمياً | اعتماد صيغة `legacy#N` في وثائق GCC |
| E21-F02 | محتوى الفروع الموثّقة كـ PR #12 (Amazon) و#13 (Trendyol) و#14 (Noon) و#15 (Odoo) و#16 (guards) موجود كاملاً في `main` عبر lavkoo@cbf2cdd؛ ما عدا ثلاثة ملفات أُضيفت داخل الفروع ثم حُذفت عمداً (lavkoo@a204f31 وlavkoo@de04f28، انظر 2.4 و2.11) | INFO | VERIFIED | 2.4؛ lavkoo@7efe022، lavkoo@32249dd، lavkoo@0366ba7، lavkoo@a907bf0، lavkoo@8f92f7e | لا حاجة لدمج أي PR قديم لبدء GCC؛ دمجها مجدداً خطر تكرار | تصويب `DECISIONS.md`/`STATUS.md`؛ طلب إغلاق #12–#16 القديمة كمستوعَبة إن كانت مفتوحة |
| E21-F03 | الحالة الحيّة لـ PR #12–#16 وأي commits لاحقة على فروعها في المستودع القديم غير معلومة | P1 | UNKNOWN | `lavkoo:docs/gcc/STATUS.md:35` | إن وُجدت commits بعد 2026-11-11 هناك فهي إصلاحات غير موجودة في `lavkoo` | منح وصول قراءة لـ `pharmacyksa98` أو تصدير `git ls-remote` منه |
| E21-F04 | وثائق التسليم تقول «لم يُدمج شيء / لا PR» بينما الدمج تمّ؛ لم يُحدَّث `final-report.md` ولا `merge-plan.md` بعد الدمج (آخر تعديل لهما 2026-11-10) | P1 | VERIFIED | `lavkoo:docs/sales-channels/final-report.md:7-8`؛ `lavkoo:docs/sales-channels/merge-plan.md:162`؛ lavkoo@cbf2cdd | جلسة لاحقة تقرأ الوثائق قد تعيد دمجاً أو تفترض غياب الكود | ملاحظة تصويب موثَّقة |
| E21-F05 | الشجرة المدموجة فعلاً (lavkoo@89f1be9) تزيد 11 commit و34 ملفاً (+2,353 / −65، تمسّ كوداً مالياً في `OperationalCosts`) على آخر شجرة موثَّق أن CI أخضر عليها (lavkoo@412ed58) | P1 | VERIFIED (الفرق) / UNKNOWN (نتيجة CI) | `lavkoo:docs/sales-channels/merge-plan.md:138-139`؛ `git diff --shortstat 412ed58d 89f1be90` | أساس GCC قد يكون شجرة لم تُختبَر كاملة على MySQL/MariaDB | تشغيل CI كاملاً على `main` قبل أي فرع GCC (بموافقة) وتسجيل النتيجة |
| E21-F06 | لا يُعرف أي commit يعمل على الإنتاج ولا أي migrations نُفِّذت: الموثَّق `698aa1e` (بعد Hotfix 1)، و`main` يزيد عليه 11 دمجاً رئيسياً و32 migration (من أصل 36 ببادئة ≥ `2026_11_05`؛ أربع فقط موجودة عند `698aa1e`)، ووجود commit من هوية الخادم يوحي بشجرة أحدث على خادم ما | P0 | UNKNOWN | `lavkoo:docs/integrations/hotfix-2.md:28`؛ `lavkoo:.github/workflows/ci.yml:189`؛ lavkoo@352ed68؛ lavkoo@95e0ae3 | خطة ترحيل GCC (ومطابقة أرصدة السعودية) تحتاج مخطط الإنتاج الفعلي؛ البناء على افتراض خاطئ يهدد البيانات القائمة | قراءة جدول `migrations` و`MANIFEST.json` آخر نشر من الخادم (قراءة فقط، بموافقة المالك) قبل أي تصميم ترحيل |
| E21-F07 | commit إعادة التسمية جاء من هوية الخادم مباشرةً على `main` دون PR، ويخلط إعداداً وعلامةً وتبديل مشروع Firebase، ويغيّر نصوصاً تُكتب في Odoo وردود webhook | P1 | VERIFIED | lavkoo@352ed68؛ `lavkoo:app/Services/Accounting/Odoo/OdooUndoLink.php:134`؛ `lavkoo:config/app.php:30` | مسار تغيير خارج المراجعة؛ نص مرجعي تغيّر قد يفصل قيوداً قديمة إن وُجدت؛ إعادة التسمية جزئية (29 ملف `app/` ما زالت «syria») | منع الالتزام من الخادم؛ جرد النصوص المرجعية الخارجية قبل أي استثناء هوية لكل بلد |
| E21-F08 | Tabby وTamara: لا كود ولا تاريخ في أي مستودع؛ Paymob متحكّم القالب فقط منذ الجذر بلا اختبارات | INFO | VERIFIED | 2.6؛ `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:14` | «examine Tabby/Tamara» يعني بناءً جديداً؛ Paymob يحتاج تدقيقاً وظيفياً مستقلاً قبل ربطه بكيان قانوني | يحال إلى ملحق المدفوعات |
| E21-F09 | `lavkooapp` ليس لقطة لأي commit قديم: يحمل PR #1 كاملاً و21 من 24 ملف `lib/` من PR #2، ويفتقد إصلاحَي ksaapp98@1a34a4d، وستة اختبارات + تحديث سابع، وCI، وأداة فحص الأسرار، ووثيقتين (4,148 سطراً)، ويبقي ملفاً محذوفاً | P1 | VERIFIED | 2.9 | فقدان شبكة الأمان لمسارات الدفع والطلب والسمة الديناميكية قبل تعديلات GCC؛ مصفوفة التوافق مع الـ backend غير متاحة للفريق | نقل المفقودات من ksaapp98@79f50be في أول مرحلة تنفيذ معتمدة |
| E21-F10 | تعارض معرّفات في `lavkooapp`: iOS bundle id قديم مع إعداد Firebase جديد؛ استعلام الـ manifest عن حزمة بائع غير الفعلية؛ مفتاح الخرائط قيمة نائبة | P1 | VERIFIED | `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414`؛ `lavkooapp:ios/Runner/GoogleService-Info.plist:9-10`؛ `lavkooapp:android/app/src/main/AndroidManifest.xml:44`؛ `lavkooapp:android/app/src/main/AndroidManifest.xml:76-77` | بناء iOS الحالي لا يطابق إعداد Firebase؛ زر «تطبيق البائع» قد لا يكتشف التطبيق على Android 11+؛ قد تتعطل خرائط Android (`INFERRED` من القيمة النائبة) | قرار مكتوب من المالك للمعرّفات النهائية قبل أي إصدار؛ لا تغيير آلي |
| E21-F11 | تبديل مشروع Firebase: الـ backend والتطبيقان الحاليان على مشروع جديد يختلف عن مشروع `ksaapp98` | INFO | VERIFIED (مقارنة تجزئة دون نسخ القيم) | lavkoo@352ed68؛ `lavkoo:firebase-messaging-sw.js:6-12` | مستخدمو التطبيق القديم لا تصلهم إشعارات المشروع الجديد؛ تصميم الإشعارات لكل سوق يبدأ من مشروع واحد مشترك | يحال إلى ملحق الإشعارات؛ توثيق خطة انتقال المستخدمين |
| E21-F12 | تطبيق البائع: 3 commits، أصل غير قابل للتتبّع، وثائق متقادمة (معرّف `com.kuwaitcosmetics.seller`، هوية القالب) ومعرّف iOS تغيّر بين أول commit وثانيه | P2 | VERIFIED (النصوص) / UNKNOWN (الأصل) | `lavkooappseller:MIGRATION.md:48`؛ `lavkooappseller:docs/seller-center-update-study.md:69`؛ lavkooappseller@e56a533 | لا يمكن إثبات أن إصلاحات نسخة سابقة موجودة؛ الوثائق تضلّل | طلب المستودع/الأرشيف السابق من المالك؛ ملاحظة تصويب |
| E21-F13 | بادئات migrations متقدّمة على التقويم (9 بعد تاريخ التدقيق، حتى `2026_11_22`) وثلاث بادئات مكرّرة | P2 | VERIFIED | 2.11 | migration لـ GCC بتاريخ حقيقي أبكر ستُرتَّب قبل جداول تعتمد عليها | اتفاق تسمية: بادئات GCC بعد `2026_11_22_100000` |
| E21-F14 | تعارض بيانات قديمة موثَّق في آخر commits الدمج: صافي عمولة قناة مكتوب بالطريقة القديمة قد يُحتسب مرتين؛ أُضيف أمر فحص قارئ فقط بدل migration | P1 | VERIFIED (وجود الأمر والتوثيق) / UNKNOWN (وجود صفوف فعلية) | lavkoo@95e0ae3؛ lavkoo@411aa67؛ `lavkoo:app/Console/Commands/CostsCheckLegacyCommissionSlotsCommand.php:46-50` | أي تفعيل لوحدة التكاليف أو ربطها بكيان قانوني دون هذا الفحص يهدد صحة الأرقام المالية السعودية | إدراج الفحص كبوابة إلزامية في خطة الترحيل (يُنفَّذ على نسخة معزولة فقط) |
| E21-F15 | الجذر lavkoo@34dce65 استيراد واحد لـ 13,728 ملفاً؛ لا تاريخ لما قبله (السمة، المحفظة، الولاء، حزمة البائع `app/Services/Marketplace` ‏42 ملفاً) | INFO | VERIFIED | 2.1؛ `git ls-tree -r 34dce65` | لا `blame` نافع للكود الأساسي؛ نيّة التصميم تُستخرج من الكود فقط | — |

SEC-E21-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E21-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E21-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E21-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.


## 7. أسئلة مفتوحة وعناصر UNKNOWN

| # | السؤال | ما يحسمه |
|---|---|---|
| 1 | هل PR #12–#16 في `pharmacyksa98` مفتوحة أم أُغلقت، وهل على فروعها commits بعد lavkoo@89f1be9؟ | وصول قراءة للمستودع القديم (`git ls-remote` + قائمة PRs) |
| 2 | هل نجح CI على lavkoo@89f1be9 أو lavkoo@cbf2cdd أو lavkoo@f61939f؟ وهل Actions مفعّلة في `lavkoo`؟ | سجل Actions في المستودعين |
| 3 | أي commit يعمل على الإنتاج اليوم، وأي migrations من الـ 36 الحديثة نُفِّذت؟ | جدول `migrations` و`MANIFEST.json` لآخر نشر على الخادم (قراءة فقط) |
| 4 | هل الخادم الذي أنتج lavkoo@352ed68 هو خادم الإنتاج القديم (`pharmacyksa.com`) أم خادم جديد لـ `lavkoo.com`، وهل قاعدة بياناته نسخة من السعودية؟ | إفادة المالك + قراءة إعداد الخادم |
| 5 | كيف نُقل تاريخ `pharmacyksa98` إلى `lavkoo` (إعادة تسمية أم دفع مرآة)، وهل المستودع القديم ما زال يستقبل دفعات؟ | إعدادات GitHub للمستودعين |
| 6 | من أي نسخة عمل أُنشئ `lavkooapp`، ولماذا غابت الاختبارات والـ CI وإصلاحا ksaapp98@1a34a4d؟ | إفادة المطوّر الذي أنشأ lavkooapp@0df8b09 |
| 7 | ما المعرّفات النهائية المقصودة (iOS bundle id للعميل، حزمة البائع في استعلام الـ manifest)، وهل نُشر أي إصدار من التطبيقين الحاليين في المتاجر؟ | قرار المالك + لوحات المتاجر |
| 8 | ما الأصل السابق لتطبيق البائع، وهل له مستودع بتاريخ؟ | إفادة المالك |
| 9 | هل توجد قيود Odoo أو ردود webhook خارجية تعتمد على النصوص التي غيّرها lavkoo@352ed68؟ | بيانات Odoo والمزوّد (المفاتيح مطفأة افتراضياً، فالمتوقع: لا) |
| 10 | هل توجد صفوف عمولة «بالطريقة القديمة» في `operational_cost_entries`؟ | تشغيل أمر الفحص على نسخة معزولة (غير مسموح في هذه المرحلة) |

## 8. سجل الأدلة

| الادعاء | الموضع | الحالة |
|---|---|---|
| جذر `lavkoo` واحد و13,728 ملفاً | lavkoo@34dce65 | VERIFIED |
| 13 دمجاً رئيسياً شجرتها = شجرة الأب الثاني | lavkoo@ef84360، lavkoo@506429a، lavkoo@665c231، lavkoo@0bcabe8، lavkoo@be43519، lavkoo@242a861، lavkoo@079822d، lavkoo@a687ecb، lavkoo@4fa2cb5، lavkoo@cd54f1e، lavkoo@8517d6e، lavkoo@cbf2cdd، lavkoo@f61939f | VERIFIED |
| ربط الأحرف C–G بالأرقام #12–#16 وأسماء الفروع | `lavkoo:docs/sales-channels/merge-plan.md:127-134` | VERIFIED |
| `integration-cdef` دُمج في `pr-g-guards` | lavkoo@9b3d524 | VERIFIED |
| رؤوس C وD وE وF وG أسلاف لـ `main` | lavkoo@7efe022، lavkoo@32249dd، lavkoo@0366ba7، lavkoo@a907bf0، lavkoo@8f92f7e | VERIFIED |
| «183 ملفاً» لفرع التكامل | `lavkoo:docs/sales-channels/merge-plan.md:5`؛ lavkoo@92f0fee | VERIFIED |
| الوثائق تقول «لم يُدمج شيء» | `lavkoo:docs/sales-channels/final-report.md:7-8`؛ `lavkoo:docs/sales-channels/final-report.md:180` | VERIFIED |
| PR #16 مسوّدة على `integration-cdef` | `lavkoo:docs/sales-channels/final-report.md:250`؛ `lavkoo:docs/sales-channels/account-handoff.md:13` | VERIFIED (كنص وثيقة) |
| `amazon-feeds` بلا PR | `lavkoo:docs/sales-channels/final-report.md:263`؛ `lavkoo:docs/sales-channels/account-handoff.md:14` | VERIFIED (كنص وثيقة) |
| فروع C–F مستقلة والفرع الأخير يحويها | `lavkoo:docs/sales-channels/final-report.md:698-701` | VERIFIED |
| CI أخضر على ثلاث شجرات قبل الرأس المدموج | `lavkoo:docs/sales-channels/merge-plan.md:138-139`؛ lavkoo@412ed58؛ lavkoo@89f1be9 | VERIFIED (الفرق) / UNKNOWN (CI) |
| الخادم بعد Hotfix 1 على `698aa1e`؛ Hotfix 2 غير منشور | `lavkoo:docs/integrations/hotfix-2.md:28`؛ `lavkoo:docs/integrations/hotfix-2.md:3`؛ `lavkoo:docs/integrations/pilot-deployment.md:175-176` | VERIFIED (كنص وثيقة) |
| الإنتاج القائم هو الخادم التجريبي؛ تحقق حيّ للقراءة فقط أفاد به المالك | `lavkoo:docs/integrations/readiness-report.md:8`؛ `lavkoo:docs/integrations/readiness-report.md:13` | VERIFIED (كنص وثيقة) |
| migrations وحدة القنوات/التكاليف لم تُشغَّل على الإنتاج (حتى 2026-11-11) | lavkoo@95e0ae3 (نص الرسالة) | VERIFIED (كنص رسالة) |
| مفاتيح التكاملات مطفأة افتراضياً | `lavkoo:config/sales_channels.php:23`؛ `lavkoo:config/sales_channels.php:25`؛ `lavkoo:config/sales_channels.php:189`؛ `lavkoo:config/sales_channels.php:199`؛ `lavkoo:config/sales_channels.php:357`؛ `lavkoo:config/sales_channels.php:431`؛ `lavkoo:config/odoo.php:28-42`؛ `lavkoo:config/myfatoorah.php:33` | VERIFIED |
| `verify_when_disabled` مُشغَّل افتراضياً | `lavkoo:config/myfatoorah.php:40` | VERIFIED |
| noon أُضيفت إلى بوابة المخزون في آخر commits الدمج | `lavkoo:app/Services/SalesChannels/Publishing/StockListingGate.php:27`؛ lavkoo@3d827d4 | VERIFIED |
| قائمة مفاتيح القنوات في الناشر بلا noon | `lavkoo:app/Services/SalesChannels/Publishing/StockPricePublisher.php:51` | VERIFIED (يُحال إلى E12) |
| Paymob من الجذر دون تعديل لاحق | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:14`؛ lavkoo@34dce65 | VERIFIED |
| Tabby/Tamara في وثائق GCC فقط | `lavkoo:docs/gcc/DECISIONS.md:15` | VERIFIED |
| commit إعادة التسمية: 15 ملفاً من هوية الخادم | lavkoo@352ed68 | VERIFIED |
| قرارات المالك المسجَّلة للقنوات (Odoo بلا ترحيل، محرّك التكاليف الوحيد، noon في المخزون المشترك) | `lavkoo:docs/sales-channels/decisions.md:13-14`؛ `lavkoo:docs/sales-channels/decisions.md:166-167` | VERIFIED (كنص وثيقة) |
| PR #1 و#2 في `ksaapp98` | ksaapp98@c003565؛ ksaapp98@79f50be | VERIFIED |
| `lavkooapp` جذر جديد ثم `CLAUDE.md` | lavkooapp@0df8b09؛ lavkooapp@eedbbb8 | VERIFIED |
| applicationId الجديد لتطبيق العميل | `lavkooapp:android/app/build.gradle.kts:31` | VERIFIED |
| iOS bundle id لم يتغيّر | `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414` | VERIFIED |
| إصلاحا التخطيط غائبان | `lavkooapp:lib/features/dynamic_theme/renderers/content_section_renderer.dart:801-808`؛ `lavkooapp:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:703-707` | VERIFIED |
| CI وأداة الفحص في القديم فقط | `ksaapp98:.github/workflows/flutter.yml:1`؛ `ksaapp98:tool/secret_scan.dart:1` | VERIFIED |
| تطبيق البائع: ثلاثة commits | lavkooappseller@afa12db؛ lavkooappseller@e56a533؛ lavkooappseller@8ea0092 | VERIFIED |
| البائع fork من Sixvalley | `lavkooappseller:README.md:3-4` | VERIFIED (كنص وثيقة) |
| معرّف البائع الفعلي | `lavkooappseller:android/app/build.gradle.kts:39`؛ `lavkooappseller:ios/Runner.xcodeproj/project.pbxproj:443` | VERIFIED |
| migrations ببادئات مستقبلية | `lavkoo:database/migrations/2026_11_22_100000_create_amazon_feed_submissions_table.php:1`؛ lavkoo@4c47205 | VERIFIED |
| أمر فحص خانات العمولة القديمة (قارئ فقط) | `lavkoo:app/Console/Commands/CostsCheckLegacyCommissionSlotsCommand.php:46-50`؛ lavkoo@411aa67 | VERIFIED |
| توجيه «لا تعِد تسمية المعرّفات» وحالة السلالة غير المؤكدة في وثائق GCC | `lavkoo:docs/gcc/STATUS.md:44`؛ `lavkoo:docs/gcc/STATUS.md:60`؛ `lavkoo:docs/gcc/README.md:14` | VERIFIED |

## 9. سجل التحقق المستقل

> أُجري التحقق بعد إعداد الملف، بقراءة الشفرة وسجل git بأوامر قراءة فقط، ولم يُشغَّل أي كود من المستودعات. غطّى التحقق كل بنود P0 وP1 (E21-F03 وF04 وF05 وF06 وF07 وF09 وF10 وF14)، وأكثر من عشرين ادعاءً داعماً في الأقسام 2 إلى 5، وإعادة بحث بأنماط مختلفة لكل ادعاء سلبي.

| البند | العدد |
|---|---|
| ادعاءات تحقّق منها دلالياً | 162 |
| مؤكَّدة | 151 |
| مصحَّحة | 8 |
| مُفنَّدة كلياً أو جزئياً | 3 |
| غير قابلة للتحقق سكونياً (مُعلَّمة UNKNOWN) | 6 (خارج العدد أعلاه) |
| عناصر أمنية منقولة إلى الملحق المقيّد | 4 (SEC-E21-01 إلى SEC-E21-04؛ أُضيف SEC-E21-04 في هذا التحقق) |
| فحص الاستشهادات الآلي `check_citations.py` | 0 عيوب بعد التصحيح |
| فحص الحساسية `scan_sensitive.py` | 0 مطابقات |

**التصحيحات:**

1. رأس الملف: حالة فرع الجلسة وقت التحقق؛ تقدّم الفرع إلى ba39ddbd بـ19 commit توثيقياً من جلسات أخرى، والاستنتاجات مبنية على `main` ولم تتغيّر.
2. §2.5، صف «لم يُدمج شيء»: عبارة «لم تُحدَّث أي وثيقة» مُفنَّدة جزئياً؛ عُدّل `gap-matrix.md` بسطر واحد (lavkoo@a973fb9d)، بينما بقي `final-report.md` و`merge-plan.md` دون تغيير بعد الدمج.
3. §2.6، صف Paymob: عبارة «كلها من الجذر» مُفنَّدة؛ ثلاثة من الأحد عشر ملفاً مسّتها commits لاحقة، وعدد الملفات الإضافية هو عشرة لا أحد عشر.
4. §4، صف Foundation الخاص بالتكاملات: تعميم «لم تُشغَّل migrations» على كل التكاملات غير مدعوم؛ العبارة تخص وحدة التكاليف.
5. §2.6، صف core: ضبط ترجمة الاقتباس من رسالة lavkoo@95e0ae3 ونطاق الوحدة (INFERRED).
6. §2.9، صف CI والأدوات: أُضيف أن `lavkooapp:tool/` موجود ويحوي أداتي توليد أصول الإطلاق فقط.
7. §3، البند 3: مرجع التوجيه في `lavkooapp:CLAUDE.md` صُحِّح من السطر 1 إلى السطر 3.
8. §2.11: عدد migrations ذات البادئة اللاحقة لتاريخ إضافتها صُحِّح من 25 إلى 24 بعد إعادة العدّ.
9. E21-F02: عبارة «لا ملف مضاف مفقود» مُفنَّدة؛ ثلاثة ملفات أُضيفت داخل الفروع ثم حُذفت عمداً (lavkoo@a204f31 وlavkoo@de04f28).
10. E21-F04: نطاق عبارة «لم تُحدَّث أي وثيقة» صُحِّح وفق البند 2.
11. E21-F10: أثر القيمة النائبة لمفتاح الخرائط خُفِّف إلى INFERRED.
12. أُعيدت صياغة ثلاثة مواضع في الجداول لتفادي لبس مصطلحي في الوصف الهندسي للفيض والاستثناءات؛ دون تغيير وقائعي.
13. صفوف المعرّفات الأمنية في §6 حُوّلت إلى أسطر إشارة محايدة.

**الملاحظات المتبقية (حسب الأقسام):**

- §2.6 وE21-F06 (P0): الدرجة مُبقاة لأن ترحيلاً مبنياً على افتراض خاطئ عن مخطط الإنتاج يهدد بيانات قائمة، لكن الحالة UNKNOWN بالكامل لغياب بيانات الإنتاج، ويُترك للمراجع تأكيد الدرجة.
- §2.8 وE21-F07: الاستنتاج المتعلق بصلاحيات الكتابة على المستودع المرجعي من جهة الخادم `INFERRED`، ومحتواه في الملحق المقيّد.
- §2.8: تكوين الإضافات في `config/system-addons.php` يحتاج مراجعة أمنية منفصلة خارج هذا الملف، وتفاصيله في الملحق المقيّد.
- §7: الحالة الحيّة لطلبات الدمج القديمة ونتائج CI والمخطط المنشور تبقى UNKNOWN كما هي معلنة.

**فجوات التغطية:**

- لم تُعَد الدمجات الداخلية الـ29 فرداً، ولم تُعَد تصنيفات الـ1,267 ملفاً فرداً؛ أُعيد التحقق من مجاميعها فقط.
- الملفات الثنائية قورنت بمعرّف blob فقط، ولم تُفحَص محتويات ملفات `lib/` الـ24 سطراً سطراً.
- لم يُعَد عدّ رسائل PR-D وPR-E وPR-F (19 و18 و25)، ولم يُعَد عدّ الكلمات المفتاحية في كل مجلد.
- بنود غير قابلة للتحقق سكونياً (6): الحالة الحيّة لـ PR #12 إلى #16 في المستودع القديم، ونتائج CI لـ`89f1be9` و`cbf2cdd` و`f61939f`، والمخطط المنفَّذ على الإنتاج والـ migrations المنفَّذة، وخلو المستودعات الحالية من PRs (معلومة القائد من GitHub)، وإصدارات المتاجر المنشورة، وإعدادات GitHub للمستودعات.
