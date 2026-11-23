# Lavkoo GCC — Discovery-01 — التقرير 06: نسب Git والـ PRs والفروع والـ commits بين المستودعات القديمة والحالية والتعارضات

> **المرحلة:** AUDIT (قراءة فقط) — **الجلسة:** `claude/exciting-shannon-4mh2ok` — **التاريخ:** 2026-11-12 (+03:00)
> **SHA الأساس:** `lavkoo@7f42777` · `lavkooapp@eedbbb8` · `lavkooappseller@8ea0092` · مرجع قديم `ksaapp98@79f50be`
> **يعتمد على:** [E21](./evidence/E21-git-lineage-legacy-prs.md) (الملحق الأساسي)، [E10](./evidence/E10-payments-gateways.md)، [E11](./evidence/E11-inventory-fulfillment-shipping.md)، [E12](./evidence/E12-sales-channels-marketplaces.md)، [E13](./evidence/E13-finance-odoo-costs-settlements.md)؛ ووثيقتا `docs/gcc/DECISIONS.md` و`docs/gcc/STATUS.md` من `lavkoo@7f42777`.
> **الحالة:** نهائي لهذه الجلسة — لا يتضمن أي تنفيذ.

## 1. الخلاصة

- `lavkoo` يحمل تاريخ المستودع القديم `pharmacyksa98` بمعرّفاته الأصلية: 42 merge commit، منها 13 على الخط الأول لـ `main`، وكلها أسلاف لـ `main` (`VERIFIED`).
- كل محتوى الفروع التي تسمّيها الوثائق PR #12 إلى #16 موجود في `main` عبر الدمج `lavkoo@cbf2cddb` (PR #17، بتاريخ 2026-11-11). حالة تلك الطلبات في المستودع القديم `UNKNOWN (missing access: pharmacyksa98)`.
- الوثائق المعتمدة والتسليمية ما زالت تقول إن #12 إلى #16 مفتوحة وإنه «لم يُدمج شيء». هذا تعارض توثيقي مع git، لا تعارض في القرارات.
- الإنتاج غير معلوم في كل الحالات. الوثائق تفترض أن الخادم التجريبي يعمل على `lavkoo@698aa1e`، بينما `main` يزيد عليه 11 دمجاً رئيسياً و32 migration (`VERIFIED` كفرق في الشجرة، `UNKNOWN` كحالة نشر).
- تطبيق العميل `lavkooapp` ليس لقطة لأي commit في `ksaapp98`. PR #1 موجود فيه، وPR #2 جزئي، والاختبارات وCI وأداة فحص الأسرار غائبة. تطبيق البائع `lavkooappseller` جذر جديد بلا أصل قابل للتتبّع.
- Tabby وTamara غير موجودين في `lavkoo` (`MISSING-in-lavkoo`). Paymob موجود كمتحكّم قالب فقط من الجذر، بلا اختبارات.

## 2. نطاق القراءة وطريقة التحقق

**ما أُعيد التحقق منه في هذه الجلسة (أوامر git للقراءة فقط):**

| الفحص | النتيجة | الحكم |
|---|---|---|
| `git rev-list --count main` في `lavkoo` | 443 | مطابق لـ E21 |
| `git log main --merges` | 42 merge commit؛ 13 منها على الخط الأول (`--first-parent`) والعدد الكلي للخط الأول 21 | مطابق لـ E21 §2.1–§2.3 |
| `git merge-base --is-ancestor <merge> main` للـ 13 دمجاً الرئيسية | كلها «نعم» | `VERIFIED` |
| `git diff --shortstat <merge>^1 <merge>` للـ 42 دمجاً | أحجام مطابقة لـ E21 للدموج الـ13 الرئيسية؛ دمج `85abc845` بلا تغيير شجري | `VERIFIED` |
| تطابق الشجرة: `<merge>^{tree}` مع `<الأب الثاني>^{tree}` | مطابق للدموج `cbf2cddb` و`506429a8` و`8517d6ee` | `VERIFIED` (3 من 13) |
| `git cat-file -e afb8974` في `lavkooapp` | يفشل: لا تاريخ مشترك | `VERIFIED` |
| `git ls-tree -r` للمقارنة ملفاً بملف | `lavkooapp@eedbbb8` = 1,259 ملفاً؛ مطابقة blob+path مع `ksaapp98@c003565` = 1,093، ومع `ksaapp98@79f50be` = 1,092 | `VERIFIED` |
| `git grep -il 'tabby\|tamara'` على `main` وعلى `lavkooapp` و`lavkooappseller` | ملفان فقط في `lavkoo`، وهما وثيقتا GCC؛ صفر في التطبيقين | `VERIFIED` |
| نمط `tabby\|tamara` في `app/ config/ routes/ database/ tests/ resources/ Modules/` | صفر ملفات | `VERIFIED` |
| `git log --all -i --grep` على `paymob` و`tabby` و`tamara` | صفر | `VERIFIED` |
| `git log --all -i --grep='guard'` / `'safeguard'` | 112 / 0 | `VERIFIED` |
| عدّ migrations ببادئة ≥ `2026_11_05` | 36 في `main`، و4 منها فقط عند `698aa1e` | `VERIFIED` |
| `git show -s 95e0ae3` (نص الرسالة) | يصف فحصاً لقيود العمولة القديمة | `VERIFIED` (كنص رسالة) |

**ما لم يُفحص:** لم يُنفَّذ أي `fetch` أو `ls-remote` على `pharmacyksa98`، لأن الوصول إليه مرفوض في هذه الجلسة. لم يُعَد تحقيق مجاميع الملفات الـ1,267 لـ`ksaapp98` فرداً، ولم تُقارَن محتويات الملفات الثنائية إلا بمعرّف الـ blob. فرع الجلسة تقدّم وقت الفحص إلى `0b589e69`؛ كل الاستنتاجات مبنية على `main` عند `7f42777`.

## 3. الخط الزمني لتاريخ `lavkoo`

التوقيتات كما في الـ commit (+03:00 ما لم يُذكر غير ذلك). المصدر: [E21 §2.2](./evidence/E21-git-lineage-legacy-prs.md).

| التاريخ | الحدث | الدليل |
|---|---|---|
| 2026-11-04 13:40 UTC | جذر واحد «Initial commit: pharmacyksa.com» بـ 13,728 ملفاً؛ لا تاريخ قبله في هذا المستودع | `lavkoo@34dce658` |
| 2026-11-06 12:49 | PR #1 من `claude/clever-newton-wvhbt2`: تكامل Fulfillment (M5AZN وTorod Point) وأدوات النشر وleak guard | `lavkoo@ef843600` |
| 2026-11-06 17:37 | PR #2 (Hotfix 1، الفرع نفسه). الخادم التجريبي موثّق على شجرة `698aa1e` بعده | `lavkoo@506429a8`، `lavkoo:docs/integrations/hotfix-2.md:28` |
| 2026-11-06 23:51 | PR #3 (Hotfix 2، وموثّق «prepared, not deployed») | `lavkoo@665c2315`، `lavkoo:docs/integrations/hotfix-2.md:3` |
| 2026-11-07 16:14 | PR #4: العنوان السعودي الموحّد وربط مدن Torod | `lavkoo@0bcabe8c` |
| 2026-11-07 21:18 | PR #5 من `claude/zealous-goodall-q32ja8`: قنوات البيع (Amazon وTrendyol)، التكاليف التشغيلية، المخزون المشترك، أوامر الشراء | `lavkoo@be43519e` |
| 2026-11-08 00:16 – 01:36 | PR #6 (رف المزوّد من جهة المتجر)، PR #7 (Torod webhook)، PR #8 (Torod Lastmile برقم فاتورة مجهول) | `lavkoo@242a8612`، `lavkoo@079822d3`، `lavkoo@a687ecbe` |
| 2026-11-08 20:16 | PR #9 («channels»، PR-A) | `lavkoo@4fa2cb56` |
| 2026-11-08 22:40 | PR #11 («stock»، نص الدمج «PR-B»): outbox المخزون الفوري | `lavkoo@cd54f1eb` |
| 2026-11-08 23:32 | PR #10: تكامل MyFatoorah من `claude/myfatoorah-pharmacyksa-integration-136hgg` | `lavkoo@8517d6ee` |
| 2026-11-11 12:38 | PR #17 من `claude/pharmacyksa-shipping-tests-31q1nu`: يحمل PR-C إلى PR-G وamazon-feeds وshipping tests (125 commit) | `lavkoo@cbf2cddb` |
| 2026-11-11 15:54 | PR #18: مسارات الروابط العميقة للتطبيق (`config/deeplinks.php`) | `lavkoo@f61939f8` |
| 2026-11-11 13:34 UTC (16:34 +03) | commit إعادة التسمية «Rebrand to Lavkoo and fix server deployment» بلا PR وبلا merge | `lavkoo@352ed688` |
| 2026-11-12 00:52 – 00:56 | ستة commits توثيقية `docs(gcc)` حتى رأس `main` | `lavkoo@7f42777c` |

ترقيم الـ PR ليس زمنياً: PR #11 دُمج قبل PR #10. لا يوجد في أي رسالة commit ولا على الخط الرئيسي ذكرٌ للأرقام #12 إلى #16 (`git log --all -E --grep='#1[2-6]\b'` = صفر، `VERIFIED` في [E21 §2.2](./evidence/E21-git-lineage-legacy-prs.md)).

## 4. كل merge commit في `lavkoo` (42)

الحجم = ملفات / أسطر مضافة / أسطر محذوفة، مقاساً مقابل الأب الأول (`git diff --shortstat <merge>^1 <merge>`). «سلف لـ main» = `VERIFIED` لكل الصفوف (كلها مُعدّدة من `git log main --merges`). الصفوف الغامقة هي دموج الخط الأول، وأرقام PR فيها ترقيم المستودع القديم.

| # | merge | التاريخ | الوسم أو lane | الأب الأول ← الأب الثاني | الحجم | سلف لـ main |
|---|---|---|---|---|---|---|
| 1 | **`lavkoo@ef843600`** | 2026-11-06 | **PR #1** (`claude/clever-newton-wvhbt2`، 50 commit) | 34dce658 ← 30dcbf64 | 335 / +45,752 / −30 | نعم |
| 2 | **`lavkoo@506429a8`** | 2026-11-06 | **PR #2** (الفرع نفسه) | ef843600 ← 698aa1e7 | 43 / +3,238 / −118 | نعم |
| 3 | **`lavkoo@665c2315`** | 2026-11-06 | **PR #3** (الفرع نفسه) | 506429a8 ← 7e886f19 | 53 / +4,005 / −176 | نعم |
| 4 | lavkoo@3695e404 | 2026-11-06 | داخلي: `origin/main` إلى فرع القنوات | ff2cc269 ← 665c2315 | 53 / +4,005 / −176 | نعم |
| 5 | lavkoo@b8776de1 | 2026-11-07 | داخلي: PR #4 إلى فرع التكامل | 921c4535 ← f4b25860 | 237 / +31,405 / −1,189 | نعم |
| 6 | lavkoo@2b8d0be3 | 2026-11-07 | داخلي: `lane-dispatch` | 7754a363 ← 6545ce48 | 65 / +3,957 / −31 | نعم |
| 7 | lavkoo@ea56d47c | 2026-11-07 | داخلي: `lane-dispatch` | 2b8d0be3 ← 7977b219 | 9 / +405 / −126 | نعم |
| 8 | lavkoo@af432658 | 2026-11-07 | داخلي: `lane-dispatch` (إصلاحات التدقيق) | 2d8d6f84 ← 93382a9b | 27 / +871 / −63 | نعم |
| 9 | lavkoo@3a3c972f | 2026-11-07 | داخلي: `lane-costs` | af432658 ← 5d7ffc89 | 118 / +5,823 / −171 | نعم |
| 10 | lavkoo@c1e7383b | 2026-11-07 | داخلي: PR #4 إلى فرع التكامل | 3a3c972f ← ec9cb6ca | 33 / +253 / −128 | نعم |
| 11 | lavkoo@7409d4af | 2026-11-07 | داخلي: `lane-stock` | c1e7383b ← c3cf55d7 | 78 / +5,441 / −104 | نعم |
| 12 | lavkoo@90a44cc7 | 2026-11-07 | داخلي: PR #4 (وثائق العنوان) | 06b510b8 ← f884a1ec | 4 / +37 / −5 | نعم |
| 13 | lavkoo@999c36c6 | 2026-11-07 | داخلي: `lane-costs` (إصلاحات التدقيق) | 90a44cc7 ← ef21daff | 42 / +1,844 / −287 | نعم |
| 14 | **`lavkoo@0bcabe8c`** | 2026-11-07 | **PR #4**: العنوان السعودي الموحّد | 665c2315 ← fc288084 | 246 / +32,577 / −1,281 | نعم |
| 15 | lavkoo@a7faee72 | 2026-11-07 | داخلي: `origin/main` (PR #4) إلى فرع القنوات | 999c36c6 ← 0bcabe8c | 16 / +1,058 / −138 | نعم |
| 16 | lavkoo@f7ff5079 | 2026-11-07 | داخلي: `lane-po` | a7faee72 ← 8601f726 | 61 / +4,646 / −69 | نعم |
| 17 | lavkoo@6fe52fcf | 2026-11-07 | داخلي: `lane-stock-2` (إصلاحات التدقيق) | d0dcf6a0 ← c432eabf | 51 / +1,732 / −192 | نعم |
| 18 | lavkoo@6bd0bdf1 | 2026-11-07 | داخلي: `lane-po` (إصلاحات التدقيق) | acbe7fce ← 462be814 | 46 / +2,213 / −219 | نعم |
| 19 | lavkoo@85abc845 | 2026-11-07 | داخلي: `lane-po` (إصلاح اختبار) | 30e94548 ← d96a2b4b | 0 (لا تغيير شجري) | نعم |
| 20 | **`lavkoo@be43519e`** | 2026-11-07 | **PR #5** (`claude/zealous-goodall-q32ja8`، 150 commit) | 0bcabe8c ← 495aae2a | 599 / +77,219 / −15 | نعم |
| 21 | **`lavkoo@242a8612`** | 2026-11-08 | **PR #6** (`claude/admiring-bardeen-9cgbbm`) | be43519e ← d6522c7e | 21 / +637 / −26 | نعم |
| 22 | **`lavkoo@079822d3`** | 2026-11-08 | **PR #7** (`claude/affectionate-mendel-ie3wf6`) | 242a8612 ← 2db9c4a0 | 7 / +414 / −42 | نعم |
| 23 | **`lavkoo@a687ecbe`** | 2026-11-08 | **PR #8** (`claude/torod-unknown-invoice-rejects`) | 079822d3 ← 73467090 | 3 / +81 / −9 | نعم |
| 24 | **`lavkoo@4fa2cb56`** | 2026-11-08 | **PR #9** «channels» (PR-A) | a687ecbe ← f8035bd3 | 48 / +5,313 / −48 | نعم |
| 25 | **`lavkoo@cd54f1eb`** | 2026-11-08 | **PR #11** «stock» (PR-B) | 4fa2cb56 ← 32b6e63e | 40 / +2,760 / −43 | نعم |
| 26 | lavkoo@542b1762 | 2026-11-08 | داخلي: `main` إلى فرع MyFatoorah | 3309541a ← cd54f1eb | 80 / +8,072 / −83 | نعم |
| 27 | **`lavkoo@8517d6ee`** | 2026-11-08 | **PR #10** «MyFatoorah» (`claude/myfatoorah-pharmacyksa-integration-136hgg`) | cd54f1eb ← df53a8c4 | 62 / +12,311 / −18 | نعم |
| 28 | lavkoo@d326f68e | 2026-11-08 | داخلي: `main` إلى PR-C بعد PR #10 | 6c4630a5 ← 8517d6ee | 62 / +12,311 / −18 | نعم |
| 29 | lavkoo@c78c1c76 | 2026-11-08 | داخلي: `main` إلى PR-D بعد PR #10 | 8d1edadb ← 8517d6ee | 62 / +12,311 / −18 | نعم |
| 30 | lavkoo@bc3742ea | 2026-11-09 | داخلي: PR-D (`pr-d-trendyol-products`) إلى `integration-cdef` | f39dd23b ← f6a8152d | 35 / +3,272 / −37 | نعم |
| 31 | lavkoo@561e8585 | 2026-11-09 | داخلي: PR-E (`pr-e-noon`) إلى `integration-cdef` | bc3742ea ← 0366ba7c | 45 / +7,869 / −21 | نعم |
| 32 | lavkoo@905571fa | 2026-11-09 | داخلي: PR-F (`pr-f-odoo`) إلى `integration-cdef` | 561e8585 ← c8a2b8b5 | 74 / +11,864 / −55 | نعم |
| 33 | lavkoo@56926d33 | 2026-11-09 | داخلي: PR-D | 905571fa ← a5c063fd | 2 / +36 / −1 | نعم |
| 34 | lavkoo@ab3d9e53 | 2026-11-09 | داخلي: PR-D | 56926d33 ← 072711eb | 1 / +15 / −1 | نعم |
| 35 | lavkoo@63729769 | 2026-11-09 | داخلي: PR-D | ab3d9e53 ← b0cf9d06 | 19 / +2,198 / −8 | نعم |
| 36 | lavkoo@d9c76422 | 2026-11-09 | داخلي: PR-C (`pr-c-amazon-completion`) إلى `integration-cdef` | 63729769 ← 7efe022e | 6 / +604 / −4 | نعم |
| 37 | lavkoo@1e1106f7 | 2026-11-09 | داخلي: PR-D | d9c76422 ← 61bbb743 | 4 / +118 / −13 | نعم |
| 38 | lavkoo@538fe91f | 2026-11-09 | داخلي: PR-F | 1e1106f7 ← a907bf0d | 3 / +109 / −11 | نعم |
| 39 | lavkoo@92f0feea | 2026-11-09 | داخلي: رأس `integration-cdef` (C–F) | 538fe91f ← 32249dd6 | 3 / +6 / −3 | نعم |
| 40 | lavkoo@9b3d5244 | 2026-11-09 | داخلي: `integration-cdef` إلى `pr-g-guards` (PR-G، PR #16) | ca2e2f8c ← 92f0feea | 3 / +6 / −3 | نعم |
| 41 | **`lavkoo@cbf2cddb`** | 2026-11-11 | **PR #17** (`claude/pharmacyksa-shipping-tests-31q1nu`، 125 commit، يحمل PR-C…PR-G وamazon-feeds) | 8517d6ee ← 89f1be90 | 254 / +43,244 / −229 | نعم |
| 42 | **`lavkoo@f61939f8`** | 2026-11-11 | **PR #18**: روابط عميقة وتوافق الـ backend | cbf2cddb ← 68612acc | 3 / +214 / −4 | نعم |

**ملاحظات على الجدول:**

- الدموج الـ13 على الخط الأول كلها مُلتزِمها `GitHub` (دمج من واجهة الويب، `VERIFIED` من `git log`)، وشجرة كل منها مطابقة بايتاً لشجرة أبيها الثاني (`VERIFIED` لثلاثة منها هنا، و`VERIFIED` للبقية في [E21 §2.3](./evidence/E21-git-lineage-legacy-prs.md)).
- الدموج الداخلية الـ29 تحمل أسماء فروع المسارات (`lane-*`، `pr-c…pr-g`، `integration-cdef`) وتتضمن ثلاثة commits دمج `origin/main` إلى فروع أخرى.
- الاستنتاج (`INFERRED`): تاريخ `pharmacyksa98` انتقل كما هو إلى `lavkoo`، فانتقلت الـ commits ولم تنتقل بيانات الـ PR. لذلك تحمل الدموج ترقيم المستودع القديم بلا مقابل في `lavkoo`.

## 5. حالة كل وظيفة: الكود في `main` ≠ PR قديم مدموج ≠ منشور في الإنتاج

**تعريف الحالات (الأربع):**

- `VERIFIED-in-lavkoo-main`: الكود موجود في الشجرة ومُسجَّل ومختبَر بملفات اختبار موجودة (لم تُشغَّل).
- `present-partially`: الكود موجود لكن بعض السلوك الموثّق غائب أو قيد الإنشاء.
- `MISSING-in-lavkoo`: لا كود في `lavkoo`.
- `UNKNOWN`: لا يمكن تحديد الحالة بقراءة ثابتة (حالة PR قديم، أو حالة النشر).

**الفصل الثلاثي:** العمود (أ) حالة الكود في `main` (`VERIFIED` بقراءة الشجرة وgit). العمود (ب) حالة الدمج القديم: «مدموج» يعني أن دمج الـ PR موجود في `lavkoo` كسلف لـ `main`، وحالة الـ PR نفسه في `pharmacyksa98` دائماً `UNKNOWN (missing access: pharmacyksa98)`. العمود (ج) النشر على الإنتاج: `UNKNOWN` في كل صف، لأن لا دليل ساكن على ما يعمل فعلاً.

| الوظيفة | الحالة | (أ) كود في `main` | (ب) PR قديم وحالته | (ج) الإنتاج | ملاحظات وفجوات |
|---|---|---|---|---|---|
| **core** (قنوات البيع: الحسابات، المزامنة، listings، dispatch، التكاليف) | VERIFIED-in-lavkoo-main | `app/Services/SalesChannels` ‏201 ملفاً؛ المزوّد مسجَّل في `lavkoo:bootstrap/providers.php:22` | PR #5 (`lavkoo@be43519e`)، PR #6 (`lavkoo@242a8612`)، PR #9 (`lavkoo@4fa2cb56`)؛ مدموجة، حالتها القديمة UNKNOWN | UNKNOWN؛ المفتاح العام مطفأ افتراضياً `lavkoo:config/sales_channels.php:23` | الرسالة `lavkoo@95e0ae3` تنصّ على أن migrations وحدة التكاليف لم تُشغَّل على الإنتاج، وهو ادعاء كاتب الرسالة (انظر الخطر GIT-R07). |
| **channels** (الحسابات والـ listings والـ dispatch كوحدة) | VERIFIED-in-lavkoo-main | `app/Services/SalesChannels` (يتضمن 21 مساحة فرعية؛ [E12 §1](./evidence/E12-sales-channels-marketplaces.md)) | نفس دموج `core` أعلاه | UNKNOWN؛ `lavkoo:config/sales_channels.php:23` مطفأ | الوثيقة `lavkoo:docs/sales-channels/gap-matrix.md` هي المرجع الأدق للحالة لكل قدرة؛ [E12 §2.3](./evidence/E12-sales-channels-marketplaces.md). |
| **stock** (المخزون المشترك وoutbox) | VERIFIED-in-lavkoo-main | `app/Services/SalesChannels/Inventory` ‏37 ملفاً؛ `lavkoo:database/migrations/2026_11_13_100000_create_channel_stock_events_table.php:1`؛ `lavkoo:database/migrations/2026_11_10_100000_add_stock_allocation.php:1` | `lane-stock` ضمن `lavkoo@be43519e`، و PR #11 (`lavkoo@cd54f1eb`) | UNKNOWN | مفتاحا outbox والتقسيم `on` افتراضياً `lavkoo:config/sales_channels.php:288` و`:320`، لكنهما خاملان قبل اكتمال المهاجرات ([E12 §2.8](./evidence/E12-sales-channels-marketplaces.md)). |
| **Amazon** | VERIFIED-in-lavkoo-main | `app/Services/SalesChannels/Amazon` ‏24 ملفاً؛ `lavkoo:app/Services/SalesChannels/Amazon/AmazonConnector.php:50` | الأساس في PR #5؛ الاستكمال (PR-C، legacy #12) عبر `lavkoo@d9c76422` ثم PR #17 `lavkoo@cbf2cddb`. حالة #12 القديمة UNKNOWN | UNKNOWN؛ دفعات Amazon مطفأة `lavkoo:config/sales_channels.php:189` | قدرات D: قراءة المرتجعات، تقسيم الطرود، استلام إشعارات الوارد ([E12 §2.3](./evidence/E12-sales-channels-marketplaces.md)). |
| **Noon** | present-partially | `app/Services/SalesChannels/Noon` ‏8 ملفات؛ `lavkoo:app/Services/SalesChannels/Noon/NoonConnector.php:66`؛ noon أُضيفت إلى بوابة المخزون في آخر الدمج `lavkoo:app/Services/SalesChannels/Publishing/StockListingGate.php:27` | PR-E (legacy #14، رأسه `lavkoo@0366ba7c`) عبر `lavkoo@561e8585` ثم `lavkoo@cbf2cddb`. حالة #14 القديمة UNKNOWN | UNKNOWN | لا قراءة للإدراجات (`NoonConnector.php:66`)، ولا إشعارات واردة `lavkoo:app/Services/SalesChannels/Noon/NoonConnector.php:1647`، ولا مرتجعات بقراءة دورية، ولا تسويات؛ `lavkoo:docs/sales-channels/README.md:3` يقول «Noon is not built at all» وهذا تعارض توثيقي (GIT-R14). |
| **Trendyol** | VERIFIED-in-lavkoo-main | `app/Services/SalesChannels/Trendyol` ‏12 ملفاً؛ `lavkoo:app/Services/SalesChannels/Trendyol/TrendyolConnector.php:104` | الأساس في PR #5؛ PR-D (legacy #13، رأسه `lavkoo@32249dd6`) عبر `lavkoo@bc3742ea` ثم `lavkoo@cbf2cddb`. حالة #13 القديمة UNKNOWN | UNKNOWN | قدرات D: التقارير وFBN ([E12 §2.3](./evidence/E12-sales-channels-marketplaces.md)). |
| **Odoo** | VERIFIED-in-lavkoo-main (الكود؛ الترحيل غير منفَّذ) | `app/Services/Accounting/Odoo` ‏23 ملفاً؛ `lavkoo:app/Services/Accounting/Odoo/OdooClient.php:37`؛ `lavkoo:database/migrations/2026_11_15_100000_create_odoo_accounting_tables.php:1` | PR-F (legacy #15، رأسه `lavkoo@a907bf0d`) عبر `lavkoo@905571fa` ثم `lavkoo@cbf2cddb`. حالة #15 القديمة UNKNOWN | UNKNOWN؛ أربعة مفاتيح مطفأة `lavkoo:config/odoo.php:28` و`:32` و`:36` و`:42` | مُدرِج الطابور بلا مُنادٍ إنتاجي؛ الترحيل النهائي (`final_posting_enabled`) لا يُنفَّذ بنص تعليق الإعداد نفسه ([E13 §2.1](./evidence/E13-finance-odoo-costs-settlements.md)). |
| **MyFatoorah** | VERIFIED-in-lavkoo-main | `app/Services/MyFatoorah` ‏15 ملفاً؛ 7 migrations `lavkoo:database/migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php:1` إلى `_100007`؛ `lavkoo:config/myfatoorah.php` | PR #10 (`lavkoo@8517d6ee`) مدموج؛ حالته القديمة UNKNOWN | UNKNOWN؛ `MYFATOORAH_ENABLED` افتراضي `false` `lavkoo:config/myfatoorah.php:33`؛ التحقق يبقى مُشغَّلاً بعد الإيقاف افتراضياً `lavkoo:config/myfatoorah.php:40` | التكامل بُني داخل `lavkoo` بتسعة commits من `lavkoo@2b249507` إلى `lavkoo@df53a8c4` ([E10 §2.1](./evidence/E10-payments-gateways.md))؛ قدرات الأسواق في حساب المزوّد UNKNOWN. |
| **Paymob** | present-partially | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:14` (متحكّم القالب الأصلي) | لا PR. موجود من الجذر `lavkoo@34dce658` ولم يمسّ متحكّمه أي commit بعده | UNKNOWN (إعدادات البوابة في `addon_settings`) | لا اختبارات (`rg -li paymob tests` = صفر)، ولا جدول محاولات، ولا تسوية، ولا واجهة استرداد؛ النتيجة تعتمد على عودة المتصفح ([E10 §2.8](./evidence/E10-payments-gateways.md)). |
| **Tabby** | MISSING-in-lavkoo | لا كود: صفر ملفات في `app/ config/ routes/ database/ tests/ resources/ Modules/`؛ ملفان توثيقيان فقط في `lavkoo:docs/gcc/DECISIONS.md:15` و`docs/gcc/DISCOVERY_PROMPT_01.md` | لا PR في `lavkoo`؛ حالة المستودع القديم UNKNOWN (missing access) | لا ينطبق | يُبنى من الصفر إن قرّر المالك ([E10 §2.12](./evidence/E10-payments-gateways.md)). |
| **Tamara** | MISSING-in-lavkoo | نفس البحث: صفر كود، وثيقتان فقط | لا PR في `lavkoo`؛ حالة المستودع القديم UNKNOWN | لا ينطبق | نفس Tabby. |
| **safeguards / guards** | VERIFIED-in-lavkoo-main | 21 ملفاً أضافها PR-G، منها `lavkoo:app/Services/SalesChannels/Attention/AttentionCollector.php:31`؛ اختبار `lavkoo:tests/Feature/SalesChannels/EverythingStartsOffTest.php`؛ و`lavkoo:scripts/ci/leak-guard.php` من PR #1 | PR #16 (legacy) عبر `lavkoo@9b3d5244` ثم `lavkoo@cbf2cddb`؛ حالة #16 القديمة (مسوّدة) UNKNOWN | UNKNOWN | `EverythingStartsOffTest` يغطي مفتاحين فقط من مفاتيح القنوات المطفأة افتراضياً ([E12 §2.8](./evidence/E12-sales-channels-marketplaces.md)). |

**ما يعنيه هذا الجدول:** `VERIFIED-in-lavkoo-main` لا يعني أن الوظيفة تعمل على الإنتاج. كل مفاتيح التشغيل الخاصة بالتكاملات مطفأة افتراضياً، ولا يُعرف أي منها مفعّل على الخادم الحالي (`UNKNOWN`).

## 6. commit إعادة التسمية وما بعده

- **`lavkoo@352ed688`** (2026-11-11 16:34 +03): commit مباشر على `main` بلا PR وبلا merge، هويته `Lavkoo Server`، 15 ملفاً (+73 / −26) (`VERIFIED`، [E21 §2.8](./evidence/E21-git-lineage-legacy-prs.md)).
- ما يغيّره، بالتفصيل:
  - إعداد التشغيل: `APP_MODE` بقيمة افتراضية `live` في `lavkoo:config/app.php:30`.
  - الثابت الدالّ على النطاق: `lavkoo:config/system-addons.php:10` = `lavkoo.com`.
  - نصوص تُكتب في أنظمة خارجية: بادئة سرد قيود Odoo تغيّرت من PharmacyKSA إلى Lavkoo في `lavkoo:app/Services/Accounting/Odoo/OdooDraftBuilder.php:247` و`lavkoo:app/Services/Accounting/Odoo/OdooUndoLink.php:134`.
  - إعداد Firebase للويب: استُبدلت قيمه في `lavkoo:firebase-messaging-sw.js:6-12` (قيم `<REDACTED>`). مقارنة تجزئة تُظهر أن المشروع الجديد يطابق مشروع تطبيقَي العميل والبائع الحاليين، وأن القديم يطابق مشروع `ksaapp98` (`VERIFIED` كمقارنة تجزئة).
  - ردود الـ webhook وترجمات وواجهات Blade (5 ملفات).
- **بقايا الاسم القديم:** `syria` في 29 ملفاً من `app/` و46 من `resources/`، و`sixvalley|6valley|sixamtech` في 12 ملفاً من `app/` و43 من `resources/`، و`pharmacyksa` في `lavkoo:config/deeplinks.php:34` (تعليق) وأربعة ملفات اختبار (`VERIFIED`، [E21 §2.8](./evidence/E21-git-lineage-legacy-prs.md)). إعادة التسمية **جزئية وسطحية**.
- بعد الـ commit: ستة commits توثيقية `docs(gcc)` حتى `lavkoo@7f42777c`، بلا تغيير كود (`git diff --stat 352ed68 7f42777c`: 6 ملفات، +358؛ `VERIFIED`).

## 7. نسب تطبيق العميل: `lavkooapp` مقابل `ksaapp98`

**الهيكل:**

| العنصر | `ksaapp98` (مرجع قديم للقراءة) | `lavkooapp` |
|---|---|---|
| الجذر | `ksaapp98@afb8974` (2026-10-22، [E21 §2.1](./evidence/E21-git-lineage-legacy-prs.md)) | `lavkooapp@0df8b09` (2026-11-11 17:56 +03)، بلا أي أب |
| عدد الـ commits | 20، الرأس `ksaapp98@79f50be` | 2، الرأس `lavkooapp@eedbbb8` (يضيف `CLAUDE.md` فقط) |
| الدموج | PR #1 = `ksaapp98@c003565` (2026-11-08 23:31 +03)، PR #2 = `ksaapp98@79f50be` (2026-11-11 15:54 +03) | لا دموج |
| تاريخ مشترك | — | لا: `git cat-file -e afb8974` يفشل في `lavkooapp` (`VERIFIED`) |
| عدد الملفات | 1,267 (`79f50be`) | 1,259 (`eedbbb8`) |
| ملفات متطابقة (مسار + blob) | 1,092 مع `79f50be`، و1,093 مع `c003565` (`VERIFIED` بإعادة الحساب) | |

**الاستنتاج:** لا يطابق `lavkooapp` أي commit في `ksaapp98` تطابقاً تاماً. أقرب المرشحين `c003565` ثم `79f50be`. الأرجح (`INFERRED`) أن الشجرة نُسخت من نسخة عمل محلية لا من commit، ثم أُعيدت تسمية `lib/` يدوياً. التفاصيل الكاملة في [E21 §2.9](./evidence/E21-git-lineage-legacy-prs.md).

**هل محتوى PR #1 موجود؟ نعم (`VERIFIED`).** من 13 ملفاً غيّرها PR #1، خمسة متطابقة بايتاً مع الشجرة الحالية، ومنها `lavkooapp:lib/features/checkout/domain/services/myfatoorah_payment_service.dart:1` و`lavkooapp:test/myfatoorah_payment_status_test.dart:1`. ملف `checkout_screen.dart` يختلف بالمسافات فقط. ([E21 §2.9](./evidence/E21-git-lineage-legacy-prs.md))

**هل محتوى PR #2 موجود؟ جزئياً (`VERIFIED`).**

| الجزء من PR #2 | الحالة في `lavkooapp` | الدليل |
|---|---|---|
| كود `lib/` (24 ملفاً في PR #2) | 21 منها تحمل المحتوى: واحد متطابق بايتاً، و18 بفرق مسافات أو سطر أخير فقط، وواحد بسطر فارغ، و`app_constants.dart` بسطر العلامة | [E21 §2.9](./evidence/E21-git-lineage-legacy-prs.md) |
| إصلاح فيض التخطيط في `ksaapp98@1a34a4d` (ملفان) | **غائب**: الملفان بنسخة ما قبل PR #2، والـ `Text` بلا `Flexible` | `lavkooapp:lib/features/dynamic_theme/renderers/content_section_renderer.dart:801-808`؛ `lavkooapp:lib/features/dynamic_theme/renderers/utility_section_renderer.dart:703-707` |
| الاختبارات | **غائبة**: ستة اختبارات مضافة في PR #2 غير موجودة، واختبار `deep_link_resolver_test.dart` أقصر بـ20 سطراً | `lavkooapp:test/deep_link_resolver_test.dart:1` |
| CI وأداة فحص الأسرار | **غائبة**: لا مجلد `.github` في `lavkooapp`؛ `lavkooapp:tool/` يضم أداتي توليد أصول الإطلاق فقط | `ksaapp98:.github/workflows/flutter.yml:1`؛ `ksaapp98:tool/secret_scan.dart:1` |
| وثائق التوافق مع الـ backend | **غائبة**: `ksaapp98:docs/app-backend-compatibility.md:1` (3,857 سطراً) و`ksaapp98:docs/release-readiness.md:1` | `git ls-tree` |
| ملف حُذف في `ksaapp98@e0058a2` | **ما زال موجوداً** ككود ميت، بلا مستورِد | `lavkooapp:lib/features/wallet/screens/add_fund_to_wallet_screen.dart:13` |

**هوية التطبيق والمعرّفات (كلها في الـ commit الأول `lavkooapp@0df8b09`):**

| البند | `ksaapp98` | `lavkooapp` | الحالة |
|---|---|---|---|
| الاسم الظاهر | `Ksa Cosmetics` (`ksaapp98:lib/utill/app_constants.dart:7`) | `LAVKOO` (`lavkooapp:lib/utill/app_constants.dart:7`)، `baseUrl` = `https://lavkoo.com` (`:39`) | تغيّر |
| Android `applicationId` | `com.ksacosmetics.app` (`ksaapp98:android/app/build.gradle.kts:19`) | `com.lavkoo.app` (`lavkooapp:android/app/build.gradle.kts:31`) | تغيّر |
| iOS bundle | `com.ksacosmetics.app` | `PRODUCT_BUNDLE_IDENTIFIER = com.ksacosmetics.app` (`lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414`) | **تعارض داخلي**: إعداد Firebase لـ iOS يعلن `com.lavkoo.app` (`lavkooapp:ios/Runner/GoogleService-Info.plist:9-10`) |
| استعلام حزمة البائع | `com.ksa.seller` (`ksaapp98:android/app/src/main/AndroidManifest.xml:37`) | `com.lavkoo.seller` (`lavkooapp:android/app/src/main/AndroidManifest.xml:44`) | **لا يطابق** `applicationId` التطبيق الفعلي للبائع `com.lavkoocosmeticsmanager.app` (`lavkooappseller:android/app/build.gradle.kts:39`) |
| مفتاح خرائط Google في الـ manifest | قيمة فعلية (`<REDACTED>`) | قيمة نائبة لم تُستبدل (`lavkooapp:android/app/src/main/AndroidManifest.xml:76-77`) | تعارض في الإعداد |
| نطاق الروابط العميقة | `pharmacyksa.com` | `lavkoo.com` (`lavkooapp:android/app/src/main/AndroidManifest.xml:131`) | تغيّر |
| Firebase | مشروع قديم | مشروع جديد (`google-services.json`، `GoogleService-Info.plist`، `firebase_options.dart`) | تغيّر (انظر GIT-R13) |

**ملاحظة:** عمود `ksaapp98` هنا قيم قديمة للمقارنة فقط ولا يُقترح نقلها. حسم المعرّفات النهائية قرار مكتوب من المالك، وفقاً لقاعدة `lavkooapp:CLAUDE.md` بعدم إعادة تسمية المعرّفات آلياً.

## 8. نسب تطبيق البائع: `lavkooappseller`

| commit | التاريخ | المحتوى | الدليل |
|---|---|---|---|
| `lavkooappseller@afa12db` | 2026-11-11 18:36 +03 | «first commit: lavkooappseller initial setup»، 1,179 ملفاً (747 في `lib/`) | [E21 §2.10](./evidence/E21-git-lineage-legacy-prs.md) |
| `lavkooappseller@e56a533` | 2026-11-11 21:21 +02 | «update brand identity to Seller Center LV…»: 80 ملفاً (+394 / −179)، وتوحيد معرّف iOS | `lavkooappseller:ios/Runner.xcodeproj/project.pbxproj:443` |
| `lavkooappseller@8ea0092` | 2026-11-12 00:55 +03 | `CLAUDE.md` فقط | — |

- الشجرة عند `8ea0092`: 1,151 ملفاً (`VERIFIED`).
- لا أصل مشترك مع `ksaapp98` ولا مع `lavkooapp` (`VERIFIED` لـ`lavkooapp`، و[E21 §2.1](./evidence/E21-git-lineage-legacy-prs.md) لبقية الأصول). لا مستودع قديم متاح لتطبيق البائع.
- الهوية مختلطة: Android `com.lavkoocosmeticsmanager.app` (`lavkooappseller:android/app/build.gradle.kts:39`)؛ وقبل `e56a533` كانت iOS `com.ksa.seller` (E21 §2.10)، وبعده صارت `com.lavkoocosmeticsmanager.app` (`lavkooappseller:ios/Runner.xcodeproj/project.pbxproj:443`).
- وثائق التطبيق متقادمة: `lavkooappseller:MIGRATION.md:48` و`:50` تذكر `com.kuwaitcosmetics.seller` وهو غير موجود في الكود؛ `lavkooappseller:README.md:3-4` يصفه بأنه «customised fork of the Sixvalley seller app».
- `lavkooapp` يستعلم عن حزمة البائع `com.lavkoo.seller` (انظر §7)، وهي غير الحزمة الفعلية للبائع.
- الاستنتاج: الكود مرّ بهويات متعاقبة (قالب Sixvalley، ثم Syria، ثم «Siller Center»، ثم Kuwait، ثم KSA، ثم Lavkoo). لا يمكن القول أيّ إصلاحات ضاعت أو أُضيفت عند النقل: `UNKNOWN`.

## 9. التعارضات والمخاطر

| ID | الوصف | الدرجة | الحالة | الدليل | الأثر | المعالجة المقترحة (PROPOSED) |
|---|---|---|---|---|---|---|
| GIT-R01 | الإنتاج مفترض على `698aa1e`، بينما `main` يزيد عليه 11 دمجاً رئيسياً و32 migration ببادئة ≥ `2026_11_05` (4 فقط عند `698aa1e` مقابل 36 في `main`). لا دليل ساكن على ما نُفّذ على الخادم | P0 | UNKNOWN (الفرق VERIFIED) | `lavkoo:.github/workflows/ci.yml:189`؛ `lavkoo:docs/integrations/hotfix-2.md:28`؛ `lavkoo@698aa1e7`؛ [E21 F06](./evidence/E21-git-lineage-legacy-prs.md) | أي خطة ترحيل تُبنى على مخطط خاطئ تهدد بيانات السعودية القائمة | قراءة جدول `migrations` وآخر `MANIFEST` من الخادم (قراءة فقط، بموافقة المالك) قبل أي تصميم ترحيل |
| GIT-R02 | الوثائق تقول «#12–#16 مفتوحة» و«لم يُدمج شيء» بينما دُمج المحتوى في `main` في 2026-11-11. لم تُحدَّث `final-report.md` ولا `merge-plan.md` بعد الدمج | P1 | VERIFIED | `lavkoo:docs/sales-channels/final-report.md:7-8`؛ `lavkoo:docs/sales-channels/final-report.md:180`؛ `lavkoo:docs/sales-channels/merge-plan.md:162`؛ `lavkoo@cbf2cddb` | جلسة لاحقة قد تعيد دمج محتوى موجود أو تفترض غياب كود موجود | ملاحظة تصويب موثّقة تُضاف بجانب النص التاريخي (لا إعادة كتابة له)؛ لا يُعاد فتح أي قرار معتمد |
| GIT-R03 | `docs/gcc/DECISIONS.md:60` و`docs/gcc/STATUS.md:35` تطلبان التحقق من #12–#16 وهي تصف حالة قديمة؛ لا قرار معتمد متعارض، لكن الوصف يخلط «PR مفتوح» بـ«كود غائب» | P1 | VERIFIED | `lavkoo:docs/gcc/DECISIONS.md:60`؛ `lavkoo:docs/gcc/STATUS.md:35` | الخلط يضلّل مراجعة الفجوات | تصويب فقرة PRs القديمة بعد اعتماد هذا التقرير (PROPOSED) |
| GIT-R04 | الشجرة المدموجة فعلاً `lavkoo@89f1be90` تزيد 11 commit و34 ملفاً (+2,353 / −65، تمسّ `OperationalCosts`) على `lavkoo@412ed58d`، وهي الشجرة التي وُثّق أن CI أخضر عليها | P1 | VERIFIED (الفرق) / UNKNOWN (نتيجة CI) | `lavkoo:docs/sales-channels/merge-plan.md:138-139`؛ `git diff --shortstat 412ed58d 89f1be90` | أساس GCC قد يكون شجرة لم تُختبَر كاملة على قاعدة البيانات المستهدفة | تشغيل CI كامل على `main` بموافقة المالك وتسجيل النتيجة قبل أي فرع GCC |
| GIT-R05 | حالة PR #12–#16 الحيّة في `pharmacyksa98`، وهل وُجدت commits بعد `lavkoo@89f1be90` على فروعها، غير معلومة | P1 | UNKNOWN (missing access: pharmacyksa98) | `lavkoo:docs/gcc/STATUS.md:35`؛ [E21 F03](./evidence/E21-git-lineage-legacy-prs.md) | أي إصلاح موجود هناك فقط لا يصل إلى `lavkoo` | وصول قراءة للمستودع القديم أو تصدير قائمة الفروع والـ PRs بموافقة المالك |
| GIT-R06 | الترقيم: كل أرقام PR في `lavkoo` (#1–#11، #17، #18) تعود للمستودع القديم، ولا PR في `lavkoo` نفسه. أي PR جديد هنا سيبدأ من #1 فيتصادم اسمياً | P1 | VERIFIED | [E21 F01](./evidence/E21-git-lineage-legacy-prs.md)؛ `lavkoo@ef843600` … `lavkoo@f61939f8` | التباس في التتبّع بين المستودعين | صيغة `legacy#N` في وثائق GCC (PROPOSED) |
| GIT-R07 | تعارض بيانات قديم في العمولات: صافي عمولة قناة مكتوب بالطريقة القديمة قد يُحتسب مرتين. قاست رسالة الـ commit ذلك على نسخة معزولة (صافيان بقيمة 12.00 ريال أعطيا تكلفة فعلية 24.00). الأمر `costs:check-legacy-commission-slots` للقراءة فقط. وجود صفوف فعلية في الإنتاج UNKNOWN، والرسالة تفترض أنه لا يوجد | P1 | VERIFIED (الأمر والنص) / UNKNOWN (البيانات) | `lavkoo@95e0ae3`؛ `lavkoo@411aa676`؛ `lavkoo:app/Console/Commands/CostsCheckLegacyCommissionSlotsCommand.php:46-50` | أي تفعيل لوحدة التكاليف قبل الفحص يُظهر أرقاماً مالية خاطئة للسعودية | تشغيل الأمر على نسخة معزولة فقط ضمن خطة الترحيل، بموافقة المالك |
| GIT-R08 | `lavkooapp` ليس لقطة لأي commit قديم: PR #2 جزئي، ويغيب إصلاح فيض التخطيط والاختبارات وCI وأداة فحص الأسرار ووثيقة التوافق | P1 | VERIFIED | §7؛ [E21 F09](./evidence/E21-git-lineage-legacy-prs.md) | فقدان شبكة الأمان لمسارات الدفع والطلب والسمة الديناميكية قبل أي تعديل لـ GCC | نقل المفقودات من `ksaapp98@79f50be` في أول مرحلة تنفيذ معتمدة، مع اختباراتها |
| GIT-R09 | تعارض معرّفات في تطبيق العميل: iOS bundle القديم مع إعداد Firebase الجديد، واستعلام الـ manifest عن حزمة بائع غير فعلية، ومفتاح الخرائط قيمة نائبة | P1 | VERIFIED | `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414`؛ `lavkooapp:ios/Runner/GoogleService-Info.plist:9-10`؛ `lavkooapp:android/app/src/main/AndroidManifest.xml:44`؛ `lavkooapp:android/app/src/main/AndroidManifest.xml:76-77` | بناء iOS لا يطابق إعداد Firebase؛ زر «تطبيق البائع» قد لا يكتشف التطبيق؛ خرائط Android قد تفشل (INFERRED من القيمة النائبة) | قرار مكتوب من المالك للمعرّفات النهائية قبل أي إصدار؛ لا تغيير آلي |
| GIT-R10 | commit إعادة التسمية `lavkoo@352ed688` على `main` بلا PR، من هوية الخادم، ويغيّر إعدادات وعلامة ومشروع Firebase ونصوصاً تُكتب في Odoo وردود webhook. الإعادة جزئية (بقايا `syria` في 29 ملفاً من `app/`) | P1 | VERIFIED | `lavkoo@352ed688`؛ `lavkoo:config/app.php:30`؛ `lavkoo:app/Services/Accounting/Odoo/OdooUndoLink.php:134` | مسار تغيير خارج المراجعة؛ أي بحث في Odoo بالبادئة القديمة يحتاج مراجعة (INFERRED) | منع الالتزام المباشر من الخادم على `main`؛ جرد النصوص المرجعية الخارجية قبل أي استثناء هوية لكل بلد |
| GIT-R11 | تعارض في ترتيب migrations: 9 migrations بادئتها بعد تاريخ التدقيق (`2026_11_13` حتى `2026_11_22`)، أُضيفت في commits بتاريخ 2026-11-08 و2026-11-09. ثلاث بادئات مكرّرة (`2026_11_07_100000` و`100100` و`100200`، ملفان لكل منها). migration أُعيد تسميتها أثناء حلّ دمج | P2 | VERIFIED | `lavkoo:database/migrations/2026_11_22_100000_create_amazon_feed_submissions_table.php:1` (أُضيفت في `lavkoo@4c47205b`)؛ `lavkoo:database/migrations/2026_11_13_100000_create_channel_stock_events_table.php:1` (أُضيفت في `lavkoo@eecb8a2f`) | البادئة ترتيب بين المسارات لا تاريخ تقويمي؛ migration GCC بتاريخ حقيقي أبكر ستُرتّب قبل جداول تعتمد عليها | اتفاق تسمية: بادئات GCC بعد `2026_11_22_100000` (PROPOSED) |
| GIT-R12 | تطبيق البائع: 3 commits، أصل غير قابل للتتبّع، وثائق متقادمة (`com.kuwaitcosmetics.seller`، وهوية القالب)، ومعرّف iOS تغيّر بين أول commit وثانيه | P2 | VERIFIED (النصوص) / UNKNOWN (الأصل) | `lavkooappseller:MIGRATION.md:48`؛ `lavkooappseller:docs/seller-center-update-study.md:69`؛ `lavkooappseller@e56a533` | لا يمكن إثبات أن إصلاحات نسخة سابقة موجودة؛ الوثائق تضلّل | طلب الأرشيف السابق من المالك؛ ملاحظة تصويب |
| GIT-R13 | تبديل مشروع Firebase: التطبيقان والـ backend على مشروع جديد. مستخدمو التطبيق القديم لا تصلهم الإشعارات من المشروع الجديد | INFO | VERIFIED (مقارنة تجزئة) | `lavkoo@352ed68`؛ `lavkoo:firebase-messaging-sw.js:6-12` | تصميم الإشعارات لكل سوق يبدأ من مشروع واحد مشترك؛ انتقال المستخدمين غير مخطط | يُحال إلى ملحق الإشعارات؛ توثيق خطة انتقال المستخدمين |
| GIT-R14 | تعارضات توثيقية أخرى: `lavkoo:docs/sales-channels/odoo-accounting.md:3` يقول «غير مبني» بينما الكود موجود، و`lavkoo:docs/sales-channels/README.md:3` يقول إن Noon «not built at all» بينما الموصّل موجود | INFO | VERIFIED | الموضعان المذكوران | وثائق تصف مراحل أقدم من الشجرة | ملاحظة تصويب (PROPOSED) |
| GIT-R15 | الجذر `lavkoo@34dce658` استيراد واحد لـ 13,728 ملفاً. لا `blame` نافع لما قبله، فنيّة التصميم تُستخرج من الكود وحده (السمة، المحفظة، الولاء، `app/Services/Marketplace`) | INFO | VERIFIED | [E21 F15](./evidence/E21-git-lineage-legacy-prs.md) | لا تاريخ تصميم للكود الأساسي | — |
| GIT-R16 | Tabby وTamara غير موجودين، وPaymob متحكّم قالب بلا اختبارات | INFO | VERIFIED | §5؛ [E21 F08](./evidence/E21-git-lineage-legacy-prs.md) | «examine Tabby/Tamara» يعني بناءً جديداً؛ Paymob يحتاج تدقيقاً مستقلاً قبل ربطه بكيان قانوني | يُحال إلى ملحق المدفوعات |

**قيود أمنية مقيّدة (من الملحق E21، التفاصيل غير منشورة):**

SEC-E21-01: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E21-02: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E21-03: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

SEC-E21-04: مسألة أمنية مقيّدة — التفاصيل في الملحق المقيّد غير المنشور.

## 10. ما بقي UNKNOWN بسبب غياب الوصول إلى `pharmacyksa98`

1. حالة PR #12 إلى #16 (مفتوحة أو مغلقة كمستوعَبة)، وهل وُجدت commits على فروعها بعد `lavkoo@89f1be90`.
2. الدمج الذي يُقال إنه أدخل #12–#16 والشكل الفعلي لكل فرع في المستودع القديم.
3. لماذا لا تتطابق أرقام #1–#18 بين المستودعين، وهل المستودع القديم ما زال يستقبل دفعات.
4. أي من الـ commits القديمة أقدم من `lavkoo@34dce658` (الجذر).
5. حالة الـ CI في المستودع القديم، وصلاحية GitHub Actions فيه.
6. هل نجح CI على `lavkoo@89f1be90` أو `lavkoo@cbf2cddb` أو `lavkoo@f61939f8`؟ (هذا مستقل عن المستودع القديم، ويمكن حسمه من سجل Actions في `lavkoo`.)

**UNKNOWN عام لا يخص المستودع القديم وحده:** أي commit يعمل على الإنتاج اليوم، وأي migrations من الـ36 الحديثة نُفّذت، وإعدادات `.env` للتكاملات على الخادم، وإصدارات التطبيقين المنشورة في المتاجر.

## حدود هذا التقرير وعناصر UNKNOWN

- التقرير قراءة ثابتة لـ git والشجرة. لم يُشغَّل أي كود ولم يُنفَّذ أي اختبار ولم تُجرَ أي نداءات شبكة.
- لا توجد قراءة لأي قاعدة بيانات أو خادم أو مستودع `pharmacyksa98`. الحالات المتعلقة بها مكتوبة `UNKNOWN`.
- الاستنتاجات عن نقل التاريخ وعن أصل `lavkooapp` و`lavkooappseller` `INFERRED`، لأن المستودعات القديمة غير متاحة.
- تطبيق البائع لا أصل قابل للتتبّع له، ولا يُعرف أيّ إصلاح ضاع أو أُضيف عند النقل.
- لم يُعَد تحقيق كل الـ29 دمجاً داخلياً فرداً، ولم تُعَد تصنيفات الـ1,267 ملفاً لـ`ksaapp98`، ولم تُقارَن محتويات الملفات الثنائية إلا بمعرّفات blob.
- هذا التقرير لا يعيد فتح أي قرار معتمد. التعارضات المذكورة توثيقية (حالة الوثائق) ولا تغيّر القرارات.
- كل ما هو `PROPOSED` توثيق فقط، ولا يُعدّ تعليمات تنفيذ.

## سجل الأدلة الرئيسية

| الادعاء | الموضع | الحالة |
|---|---|---|
| جذر واحد لـ`lavkoo` وعدد الـ commits على `main` 443 | `lavkoo@34dce658`؛ `lavkoo@7f42777c` | VERIFIED |
| 42 merge commit؛ 13 على الخط الأول؛ كلها سلف لـ `main` | `lavkoo@ef843600` … `lavkoo@f61939f8` (انظر §4) | VERIFIED |
| شجرة الدمج تطابق شجرة الأب الثاني (ثلاثة أمثلة) | `lavkoo@cbf2cddb`؛ `lavkoo@506429a8`؛ `lavkoo@8517d6ee` | VERIFIED |
| دمج PR #17 يحمل PR-C إلى PR-G ومحتواها في `main` | `lavkoo@cbf2cddb`؛ `lavkoo@9b3d5244`؛ `lavkoo@d9c76422` | VERIFIED |
| الوثائق تقول «#12–#16 مفتوحة» و«لم يُدمج شيء» | `lavkoo:docs/sales-channels/final-report.md:7-8`؛ `lavkoo:docs/sales-channels/merge-plan.md:127-134` | VERIFIED (كنص وثيقة) |
| الإنتاج مفترض على `698aa1e` | `lavkoo:docs/integrations/hotfix-2.md:28`؛ `lavkoo:.github/workflows/ci.yml:189` | VERIFIED (كنص) / UNKNOWN (النشر) |
| 36 migration ببادئة ≥ `2026_11_05`، و4 منها عند `698aa1e` | `lavkoo:database/migrations/2026_11_08_100001_create_myfatoorah_payment_attempts_table.php:1`؛ `lavkoo@698aa1e7` | VERIFIED |
| commit إعادة التسمية بلا PR، ويغيّر إعدادات وعلامة وFirebase ونصوص Odoo | `lavkoo@352ed688`؛ `lavkoo:config/app.php:30`؛ `lavkoo:app/Services/Accounting/Odoo/OdooUndoLink.php:134` | VERIFIED |
| تبديل مشروع Firebase (مقارنة تجزئة) | `lavkoo:firebase-messaging-sw.js:6-12` | VERIFIED |
| Tabby وTamara: صفر كود في `main`، وثيقتان فقط | `lavkoo:docs/gcc/DECISIONS.md:15`؛ بحث `git grep -il` على `main` | VERIFIED |
| Paymob متحكّم من الجذر بلا اختبارات | `lavkoo:app/Http/Controllers/Payment_Methods/PaymobController.php:14`؛ `lavkoo@34dce658` | VERIFIED |
| المفاتيح العامة للتكاملات مطفأة افتراضياً | `lavkoo:config/sales_channels.php:23`؛ `:189`؛ `:357`؛ `:431`؛ `lavkoo:config/odoo.php:28`؛ `:32`؛ `:36`؛ `:42`؛ `lavkoo:config/myfatoorah.php:33` | VERIFIED |
| `verify_when_disabled` مُشغَّل افتراضياً | `lavkoo:config/myfatoorah.php:40` | VERIFIED |
| `lavkooapp` جذر جديد لا يشترك بأي commit مع `ksaapp98` | `lavkooapp@0df8b09`؛ `ksaapp98@afb8974` | VERIFIED |
| 1,092 ملفاً متطابقاً (مسار + blob) مع `ksaapp98@79f50be`؛ 1,093 مع `ksaapp98@c003565` | `lavkooapp@eedbbb8`؛ `ksaapp98@79f50be`؛ `ksaapp98@c003565` | VERIFIED |
| محتوى PR #1 موجود، ومحتوى PR #2 جزئي | `lavkooapp:lib/features/checkout/domain/services/myfatoorah_payment_service.dart:1`؛ `lavkooapp:lib/features/dynamic_theme/renderers/content_section_renderer.dart:801-808` | VERIFIED |
| iOS bundle لتطبيق العميل القديم وتعارضه مع Firebase | `lavkooapp:ios/Runner.xcodeproj/project.pbxproj:414`؛ `lavkooapp:ios/Runner/GoogleService-Info.plist:9-10` | VERIFIED |
| استعلام الـ manifest عن حزمة بائع غير فعلية | `lavkooapp:android/app/src/main/AndroidManifest.xml:44`؛ `lavkooappseller:android/app/build.gradle.kts:39` | VERIFIED |
| مفتاح الخرائط قيمة نائبة | `lavkooapp:android/app/src/main/AndroidManifest.xml:76-77` | VERIFIED |
| تطبيق البائع: 3 commits، وثائق متقادمة | `lavkooappseller@afa12db`؛ `lavkooappseller@e56a533`؛ `lavkooappseller@8ea0092`؛ `lavkooappseller:MIGRATION.md:48` | VERIFIED (النصوص) |
| 9 migrations بادئتها بعد تاريخ التدقيق، ومُضافة في commits سابقة | `lavkoo:database/migrations/2026_11_22_100000_create_amazon_feed_submissions_table.php:1`؛ `lavkoo@4c47205b` | VERIFIED |
| نص قيود العمولة القديمة وأمر الفحص للقراءة فقط | `lavkoo@95e0ae3`؛ `lavkoo:app/Console/Commands/CostsCheckLegacyCommissionSlotsCommand.php:46-50` | VERIFIED (كنص) |
| فرق الشجرة بين `412ed58d` و`89f1be90`: 34 ملفاً و11 commit | `lavkoo:docs/sales-channels/merge-plan.md:138-139`؛ `git diff --shortstat 412ed58d 89f1be90` | VERIFIED (الفرق) / UNKNOWN (CI) |
| حالة PR #12–#16 القديمة وأي commits بعدها | — (`pharmacyksa98` غير متاح) | UNKNOWN |
