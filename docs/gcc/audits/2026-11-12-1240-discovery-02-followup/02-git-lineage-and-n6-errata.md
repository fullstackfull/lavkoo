# Discovery-02 follow-up — وكيل 02: نسب Git وQ-30 وسجل Errata للبند N6

**الوضع:** DOCUMENTATION ONLY. لا تنفيذ، لا تعديل لأي وثيقة معتمدة، لا تعديل لأي مستودع. كل المقترحات أدناه **PROPOSED — requires owner approval, not applied**.

## 0. النطاق وSHAs

| المستودع | الفرع/المرجع | SHA | ملاحظة |
|---|---|---|---|
| `lavkoo` | `origin/main` (GitHub) | `55e3dd0e` | مطابق لـ `git ls-remote` ولـ MCP |
| `lavkoo` | `origin/claude/exciting-shannon-4mh2ok` | `f2e01213` | الفرع الذي تُقرأ منه وثائق الجولة |
| `lavkoo` | الفرع المحلي `claude/exciting-shannon-4mh2ok` (HEAD) | `f2e01213` | شجرة العمل نظيفة عند البداية |
| `lavkoo` | الفرع المحلي `main` | `7f42777c` | متأخر 5 commits عن `origin/main`؛ لم يُحدَّث (قراءة فقط) |
| `lavkoo` | خط الأساس للتدقيق `89f1be9` | — | سلف لـ `origin/main`، والفرق 16 commit |
| `lavkooapp` | `origin/main` = HEAD المحلي | `eedbbb8` | مطابق لخط الأساس |
| `lavkooappseller` | `origin/main` (GitHub) | `9ea6423` | المحلي `8ea0092`؛ النسخة المرجعية في scratchpad عند `9ea6423` |
| `ksaapp98` | HEAD المحلي (قراءة فقط) | `79f50be` | يُستخدم فقط للتحقق من سجل PR #1 |
| `pharmacyksa98` | — | — | **خارج النطاق. لم يُستدعَ أي أمر أو أداة عليه** |

المستندات المقروءة من `origin/main`: `docs/gcc/DECISIONS.md` (67 سطراً)، `docs/gcc/STATUS.md` (68 سطراً)، `docs/sales-channels/final-report.md` (770 سطراً)، `docs/sales-channels/merge-plan.md` (161 سطراً). الأرقام أدناه أرقام أسطر في `origin/main`.

## 1. المنهج

- GitHub (قراءة فقط): `list_pull_requests` بحالة `all` و`list_branches` للمستودعات الثلاثة، ثم تحقق متقاطع بـ `gh api` (`pulls`، `pulls/17`، `pulls/18`، `commits/<sha>/pulls`).
- Git محلي (قراءة فقط): `log --all --merges`، `log --all --grep` لأرقام PR من `#12` إلى `#18` ولكلمات `Amazon` و`Trendyol` و`Noon` و`Odoo` و`integration-cdef`، `merge-base --is-ancestor` لكل merge وtip، `show origin/main:<path>`، `grep` على الوثائق، و`git grep` على شجرة `lavkooapp` للتحقق من ملفات MyFatoorah.
- لم يُنفَّذ checkout ولا fetch ولا reset ولا تشغيل كود.
- كل SHA في هذا التقرير تحقق منه بـ `cat-file` أو `merge-base`.

## 2. النتائج

### 2.1 حالة المستودعات الحالية على GitHub (VERIFIED)

| المستودع | عدد الطلبات (state=all) | الفروع | ملاحظة |
|---|---|---|---|
| `fullstackfull/lavkoo` | **0** (MCP وgh كلاهما) | `main` (`55e3dd0e`)، `claude/exciting-shannon-4mh2ok` (`f2e01213`) | الفروع `claude/pr-c-amazon-completion` و`pr-d-trendyol-products` و`pr-e-noon` و`pr-f-odoo` و`pr-g-guards` و`integration-cdef` و`amazon-feeds` و`pharmacyksa-shipping-tests-31q1nu` **غير موجودة على الريموت الحالي** |
| `fullstackfull/lavkooapp` | **0** | `main` (`eedbbb8`) | — |
| `fullstackfull/lavkooappseller` | **0** | `main` (`9ea6423`) | — |

**تناقض مهم (VERIFIED):** الرمز لديه صلاحية admin/push/pull على `lavkoo`، و`has_pull_requests=true`، ومع ذلك:
- `GET pulls/17` و`GET pulls/18` تعيد **404**.
- `commits/<sha>/pulls` يعيد قائمة فارغة للـ merge commits `lavkoo@cbf2cddb` (#17) و`lavkoo@f61939f8` (#18) و`lavkoo@4fa2cb56` (#9).

أي أن رسائل «Merge pull request #17/#18» في السجل المحلي **لا يقابلها كائن PR على GitHub** في هذا الرمز. السبب غير معلوم (استيراد أو نقل تاريخ، أو حذف كائنات PR، أو رسائل من نظام آخر)، ويُصنَّف UNKNOWN. لذلك فإن الدليل على «الدمج عبر PR» لـ #9 وما بعدها هو نص رسالة الـ commit فقط (VERIFIED كنص، UNVERIFIED كحالة PR).

### 2.2 سجل lavkoo المحلي: أرقام PR ومفاتيح الكلمات (VERIFIED)

- عدد merge commits على `origin/main`: **42**. وعلى كل الفروع: 43.
- **PR #12 إلى #16:** لا يوجد أي commit في أي ref يحمل رقم PR من هذه الأرقام (`--grep "#12\b"` ... `"#16\b"` = صفر نتائج). أي أن `lavkoo` لا يحمل PR بهذه الأرقام؛ وهذا يطابق ما تقوله `docs/gcc/STATUS.md:35` حرفياً.
- **PR #17:** `lavkoo@cbf2cddb` (2026-11-11)، «Merge pull request #17 from fullstackfull/claude/pharmacyksa-shipping-tests-31q1nu»، سلف لـ `origin/main`.
- **PR #18:** `lavkoo@f61939f8` (2026-11-11)، «Merge PR #18: App deep-link routes and backend compatibility»، سلف لـ `origin/main`.
- محتوى الفروع C–G موجود في `main` عبر merges داخلية (كلها أسلاف لـ `origin/main`):
  - PR-C (Amazon): `lavkoo@d9c76422`.
  - PR-D (Trendyol): `lavkoo@92f0feea`، `lavkoo@1e1106f7`، `lavkoo@63729769`، `lavkoo@ab3d9e53`، `lavkoo@56926d33`، `lavkoo@bc3742ea`.
  - PR-E (Noon): `lavkoo@561e8585`.
  - PR-F (Odoo): `lavkoo@538fe91f`، `lavkoo@905571fa`.
  - PR-G وintegration-cdef: `lavkoo@9b3d5244` (يدمج `integration-cdef` في `pr-g-guards`).
- كلمات `Amazon` و`Trendyol` و`Noon` و`Odoo` تظهر في عشرات commits مواضيع (مثلاً `lavkoo@33b7ae32`، `lavkoo@64947b34`)، وهي commits محتوى وليست أرقام PR.

### 2.3 تحقق من PR #17 وPR #18 (VERIFIED نصياً؛ UNKNOWN كحالة PR)

- `lavkoo@cbf2cddb` و`lavkoo@f61939f8` موجودان في تاريخ `origin/main` (`merge-base --is-ancestor` = yes).
- `lavkoo@cbf2cddb` هو الـ merge الذي يحمل محتوى PR-C…PR-G وamazon-feeds وshipping tests (`E21 §2.3`، `reports/06 §4`).
- لا يوجد كائن PR على GitHub لهذين الرقمين في هذا الرمز (§2.1).

### 2.4 بيان الأرقام والحالات في الوثائق المعتمدة (المدخلات الكاملة للسجل)

| الملف | الأسطر التي تذكر PR أو حالة PR | عدد الإدخالات |
|---|---|---|
| `docs/gcc/DECISIONS.md` | 60 (حالتان)، 61 | 3 إدخالات |
| `docs/gcc/STATUS.md` | 35 (جملتان) | 2 إدخال |
| `docs/sales-channels/final-report.md` | 3، 8، 250، 330–332، 364، 424–426، 434، 474، 622، 623، 626، 630، 632، 638، 660، 680، 686، 698، 747 | 19 إدخالاً |
| `docs/sales-channels/merge-plan.md` | 127، 128، 129، 130، 132، 134، 149، 150 | 8 إدخالات |

كل إدخال مرقّم في السجل في القسم 4. الأسطر 698 تذكر فروعاً بلا رقم PR لكنها تنفي دخول محتواها `main`، ولذلك أُدرجت.

### 2.5 حالة ملفات PR #1 لـ `ksaapp98` في `lavkooapp` (PARTIAL)

- `ksaapp98@c003565` (سجل محلي، قراءة فقط): «Merge PR #1: MyFatoorah payment status handling in KSA app» (VERIFIED نصياً؛ حالة GitHub غير متحقق منها).
- `lavkooapp@eedbbb8`: شجرة الكود تحتوي 7 ملفات تحمل اسم MyFatoorah (مثل `lib/features/checkout/domain/services/myfatoorah_payment_service.dart` و`lib/features/checkout/domain/models/myfatoorah_payment_status.dart` وملفات `test/myfatoorah_*_test.dart`). لا يوجد سجل لهذه الملفات من `ksaapp98` في `lavkooapp`، لأن `lavkooapp` يبدأ من جذر `0df8b09` (2026-11-11) وله commitان فقط.
- **لم تُجرَ مقارنة محتوى** بين الملفات السبعة وPR #1 لـ `ksaapp98`. لذلك يُصنَّف الانتقال **PARTIAL / INFERRED**، لا VERIFIED.

### 2.6 تاريخ PR #9 إلى #11 في lavkoo (VERIFIED نصياً)

- #9: `lavkoo@4fa2cb56` (2026-11-08)، «Merge pull request #9 from fullstackfull/claude/admiring-bardeen-9cgbbm».
- #10: `lavkoo@8517d6ee` (2026-11-08)، «Merge pull request #10 from fullstackfull/claude/myfatoorah-pharmacyksa-integration-136hgg».
- #11: `lavkoo@cd54f1eb` (2026-11-08)، «Merge PR #11: realtime shared stock outbox and queue workers».

### 2.7 سلسلة Git لـ PR #12 إلى #16 (الشجرة الفعلية)

الهيكل الحالي في `lavkoo`، بناءً على الأسطر المحسوبة من `git log` وmerge-base:

```
origin/main ← cbf2cddb (PR #17، 2026-11-11)
  ← 9b3d5244 (integration-cdef داخل pr-g-guards، 2026-11-09)
      ← 92f0feea / 538fe91f / 561e8585 / d9c76422 (C وD وE وF داخل integration-cdef)
```

الفروع C–G لم تعد موجودة على GitHub، لكن محتواها موجود في `main` كما هو موضح أعلاه. هذا يطابق ما وصفه `docs/sales-channels/merge-plan.md:137` بأن الشجرة المجمّعة هي `claude/pharmacyksa-shipping-tests-31q1nu`.

### 2.8 نسب تطبيق البائع `lavkooappseller` (لقطة نسب فقط، بلا تحليل كود)

- الجذر المحلي (`lavkooappseller@8ea0092`): النسخة التي يقرأها الدليل `E21` في `2.10`. عند `8ea0092` كان المستودع `CLAUDE.md` فقط (+26).
- الريموت `origin/main` = `9ea6423` (main@9ea6423، غير موجود في النسخة المحلية `8ea0092`).
- `git log 8ea0092..9ea6423` في نسخة scratchpad يعرض **8 commits** بعد الأساس:
  - `9ea6423` chore: update Seller release configs, ar.json, firebase, icons, and bump version
  - `a090384` chore(release): bump build / config for App Store Connect
  - `307acc3` chore(release): bump build / config for App Store Connect
  - `117b564` docs(gcc): link seller app to new platform super admin scope
  - `79f0fbe` chore(release): bump build / config for App Store Connect
  - `bcafb5e` chore(ios): update export options to destination upload
  - `3aa48a7` chore(release): set version to 1.0.0+1 for initial release
  - `0d2be3c` feat(store): add App Store screenshots, metadata, and ExportOptions
- `8ea0092` سلف لـ `9ea6423` (يتضح من نطاق السجل). هذا فرق نسب فقط، ولم يُحلَّل محتواه هنا.

### 2.9 نسب تطبيق العميل `lavkooapp` (VERIFIED)

- `origin/main` = HEAD المحلي = `eedbbb8`. يتطابق مع خط الأساس.
- السجل: `0df8b09` (2026-11-11، «Initial commit: Flutter project setup») ثم `eedbbb8` (2026-11-12، «docs(gcc): link Flutter agent to canonical shared GCC plan»).
- لا يوجد أي تاريخ مشترك مع `ksaapp98` داخل `lavkooapp`.

## 3. ما ثبت وما لم يثبت (ملخص مختصر)

- **ثابت:** `lavkoo` لا يحمل أي merge بأرقام PR #12–#16. محتوى C–G موجود في `main` عبر `cbf2cddb` (PR #17). PR #17 وPR #18 موجودان كـ merge commits في التاريخ.
- **غير ثابت:** أي كائن PR على GitHub لأي رقم من `lavkoo` (0 PR في الواجهة، و404 لـ #17 و#18).
- **غير ثابت:** حالة PR #12–#18 في `pharmacyksa98` (خارج النطاق). الحالة التي يذكرها المالك: مغلقة أو مدموجة. **OWNER-REPORTED، UNVERIFIED.**

## 4. مخزون عبارات الوثائق المعتمدة (المرجع للسجل)

صيغة الاقتباس مختصرة وفق قاعدة 25 كلمة. الأسطر من `origin/main`.

| # | الملف:السطر | الاقتباس المختصر | الحكم |
|---|---|---|---|
| 1 | `DECISIONS.md:60` | «PR #9 (channels)، #10 (MyFatoorah)، #11 (stock) reported merged there» | حالة معلنة |
| 2 | `DECISIONS.md:60` | «#12 (Amazon) … #16 (guards) reported open there at last check» | حالة معلنة قديمة |
| 3 | `DECISIONS.md:61` | «Legacy Flutter customer PR #1 (MyFatoorah) reported merged there» | حالة معلنة |
| 4 | `STATUS.md:35` | «not PRs #12–#16 in lavkoo» | عبارة عن الأرقام |
| 5 | `STATUS.md:35` | «#9–#11 merged in legacy؛ #12–#16 open، with #16 based on claude/integration-cdef» | حالة معلنة قديمة |
| 6 | `final-report.md:3` | «دُمجت الجولة الرابعة في main عبر PR #6»؛ «دمجه المالك في main عبر PR #5»؛ «PR #7 وPR #8» | حالة معلنة |
| 7 | `final-report.md:8` | «ولم يُدمج شيء في main، ولم يُنشر شيء» | نفي دمج |
| 8 | `final-report.md:250` | «PR #16 مسوّدة تستهدف فرع التكامل لا main» | حالة معلنة (مسودة) |
| 9 | `final-report.md:330–332` | «إصلاحات مراجعة PR #9»؛ «راجع المالك PR #9» | رقم بلا حالة |
| 10 | `final-report.md:622` | «(عبر PR #9)» | رقم بلا حالة |
| 11 | `final-report.md:623` | «(رأس الفرع، عبر PR #9)» | رقم بلا حالة |
| 12 | `final-report.md:626` | «عبر PR #9» | رقم بلا حالة |
| 13 | `final-report.md:630` | «(رأس الفرع، عبر PR #6)» | رقم بلا حالة |
| 14 | `final-report.md:364` | «ما تغيّر بعد دمج PR #5 (الجولة الرابعة)» | حالة معلنة (مدموج) |
| 15 | `final-report.md:424–426` | «قبلت أن يُدمج PR #4 في main أولًا بالتزام دمج» | حالة/خطة |
| 16 | `final-report.md:434` | «دمج PR #5 في main تمّ بموافقة المالك» | حالة معلنة (مدموج) |
| 17 | `final-report.md:474` | «بعد دمج PR #4 فيه» | حالة معلنة (مدموج) |
| 18 | `final-report.md:632` | «GitHub Actions على main بعد دمج PR #5» | حالة معلنة (مدموج) |
| 19 | `final-report.md:638` | «دمج origin/main (فيه PR #4)» | رقم بلا حالة |
| 20 | `final-report.md:660` | «main يحوي PR #4 وPR #5 سلفًا (الالتزام be43519)» | حالة معلنة (مدموج) |
| 21 | `final-report.md:680` | «اتفقتُ مع محادثة PR #4 على أن يُدمج PR #4 في main أولًا» | خطة |
| 22 | `final-report.md:686` | «موعد دمج فرع الجولة الرابعة في main (PR #5 دُمج بموافقتك…)» | حالة معلنة (مدموج) |
| 23 | `final-report.md:698` | «C وD وE وF أربعة فروع مستقلّة عن main» | حالة فرع |
| 24 | `final-report.md:747` | «وهو من PR #10 المدموج سابقًا» | حالة معلنة (مدموج) |
| 25 | `merge-plan.md:127` | «claude/pr-c-amazon-completion (PR #12، مفتوح)» | حالة معلنة |
| 26 | `merge-plan.md:128` | «claude/pr-d-trendyol-products (PR #13، مفتوح)» | حالة معلنة |
| 27 | `merge-plan.md:129` | «claude/pr-e-noon (PR #14، مفتوح)» | حالة معلنة |
| 28 | `merge-plan.md:130` | «claude/pr-f-odoo (PR #15، مفتوح)» | حالة معلنة |
| 29 | `merge-plan.md:132` | «claude/pr-g-guards (PR #16، **مسوّدة**)» | حالة معلنة (مسودة) |
| 30 | `merge-plan.md:134` | «claude/pharmacyksa-shipping-tests-31q1nu ← لا PR له» | نفي وجود PR |
| 31 | `merge-plan.md:149` | «إغلاق PR #12–#15 بوصفها مُستوعَبة» | إجراء مقترح |
| 32 | `merge-plan.md:150` | «إغلاق PR #16 (المسوّدة) كذلك» | إجراء مقترح |

ملاحظة على `merge-plan.md:120–125`: العنوان يقول «مراجعة الإغلاق، ٢٠٢٦-١١-١٠»، أي أن لقطة الحالة بتاريخ 2026-11-10، أي قبل دمج PR #17 في lavkoo بيوم.

## 5. سجل Errata لـ N6 (PROPOSED، لم يُطبَّق على أي وثيقة)

الأدلة: `lavkoo@<sha>` = سجل Git محلي وتحقق ancestry (VERIFIED). «legacy» = `pharmacyksa98`، وحالته غير متحقق منها في هذا التدقيق. مرجع التقارير: `reports/06` وE21 وreports/12 القسم 5.

| ID | الوثيقة والسطر | النص الحالي (مختصر) | ما يُظهره الدليل | النص المقترح للتصويب | الحالة | قرار المالك المطلوب |
|---|---|---|---|---|---|---|
| E-01 | `DECISIONS.md:60` | «#9 و#10 و#11 reported merged there» | `lavkoo@4fa2cb56`، `@8517d6ee`، `@cd54f1eb` أسلاف لـ `origin/main` (VERIFIED). حالة legacy غير متحقق منها (`reports/06 §10`) | «PR #9–#11: المحتوى مدموج في lavkoo main عبر 4fa2cb56 و8517d6ee وcd54f1eb (VERIFIED بنسب Git). حالتها في pharmacyksa98 معلنة من المالك، غير متحقق منها.» | PROPOSED — requires owner approval, not applied | اعتماد الصيغة، وتأكيد أن قول المالك هو مصدر الحالة في legacy |
| E-02 | `DECISIONS.md:60` | «#12 … #16 reported open there at last check» | لا merge بأرقام #12–#16 في lavkoo (VERIFIED). محتواها عبر `lavkoo@cbf2cddb` (PR #17) وأسلافه. حالتها في legacy UNKNOWN. المالك يذكر أنها مغلقة/مدموجة (OWNER-REPORTED) | «PR #12–#16 في pharmacyksa98: الحالة غير متحقق منها في هذا التدقيق، والمالك يذكر أنها مغلقة أو مدموجة. محتواها موجود في lavkoo main عبر cbf2cddb (PR #17)، لا عبر أرقام PR بهذه الأرقام.» | PROPOSED — requires owner approval, not applied | قبول قول المالك كحالة مؤقتة، أو منح قراءة للتحقق (Q-30) |
| E-03 | `DECISIONS.md:61` | «Legacy Flutter customer PR #1 (MyFatoorah) reported merged there. Verify transfer to lavkooapp» | `ksaapp98@c003565` دمج PR #1 محلياً (VERIFIED نصياً). ملفات MyFatoorah موجودة في `lavkooapp@eedbbb8` (7 ملفات)، بلا مقارنة محتوى (PARTIAL) | «PR #1 لـ ksaapp98: دمج موثّق في السجل المحلي (c003565). ملفات MyFatoorah موجودة في lavkooapp@eedbbb8، ولم تُقارَن بعد بمحتوى PR #1. حالة PR على GitHub غير متحقق منها.» | PROPOSED — requires owner approval, not applied | إما تكليف مقارنة محتوى ثابتة لاحقاً، أو إبقاء البند UNKNOWN |
| E-04 | `STATUS.md:35` | «those numbers are not PRs #12–#16 in lavkoo» | صحيح: لا PR بهذه الأرقام في تاريخ lavkoo (VERIFIED) | لا تغيير مقترح | NO CHANGE PROPOSED — consistent | لا شيء |
| E-05 | `STATUS.md:35` | «Earlier reported states: #9–#11 merged in legacy؛ #12–#16 open، with #16 based on claude/integration-cdef» | «#16 مبني على integration-cdef» مؤكد بـ `lavkoo@9b3d5244`. الحالة نفسها قديمة وغير متحقق منها | «هذه حالات أُبلغ عنها في وقت سابق ولم تُتحقق في Discovery-02. محتوى #12–#16 دُمج في lavkoo main عبر cbf2cddb (PR #17).» | PROPOSED — requires owner approval, not applied | اعتماد التصويب |
| E-06 | `final-report.md:3` | «دُمجت الجولة الرابعة في main عبر PR #6»؛ «دمجه المالك عبر PR #5»؛ «PR #7 وPR #8» | `lavkoo@be43519e` (#5، 2026-11-07)، `@242a8612` (#6)، `@079822d3` (#7)، `@a687ecbe` (#8) أسلاف لـ `origin/main` (VERIFIED). حالة legacy غير متحقق منها | لا تغيير مقترح على المحتوى؛ إضافة سطر: «حالة PR #5–#8 في pharmacyksa98 غير متحقق منها في Discovery-02؛ المحتوى موجود في lavkoo main.» | NO CHANGE PROPOSED — consistent in lavkoo; legacy unverified | لا شيء الآن (P2) |
| E-07 | `final-report.md:8` | «ولم يُدمج شيء في main، ولم يُنشر شيء» | الفرع في السطر 7 هو `claude/pharmacyksa-shipping-tests-31q1nu`. دُمج في lavkoo main عبر `lavkoo@cbf2cddb` (PR #17، 2026-11-11). هذا النص يصف legacy وقت الكتابة فقط. عدم النشر يبقى خارج هذا التدقيق | «هذا النص يصف حالة pharmacyksa98 وقت كتابته فقط. الفرع نفسه دُمج في lavkoo main عبر cbf2cddb (PR #17) بتاريخ 2026-11-11 حسب رسالة الدمج؛ ولا يعني ذلك نشراً على الإنتاج.» | PROPOSED — requires owner approval, not applied | **أولوية عالية:** اعتماد التوضيح؛ هذا هو البند الجوهري في N6 |
| E-08 | `final-report.md:250` | «PR #16 مسوّدة تستهدف فرع التكامل لا main» | `lavkoo@9b3d5244` يدمج integration-cdef في pr-g-guards (VERIFIED). حالة PR #16 الحالية غير متحقق منها | «PR #16 في pharmacyksa98: كانت مسودة وقت كتابة التقرير (لقطة قديمة). حالتها الحالية غير متحقق منها. محتواها في lavkoo main عبر cbf2cddb.» | PROPOSED — requires owner approval, not applied | اعتماد التصويب |
| E-09 | `final-report.md:330–332` | «إصلاحات مراجعة PR #9»؛ «راجع المالك PR #9» | `lavkoo@4fa2cb56` باسم «Merge pull request #9» سلف لـ `origin/main` (VERIFIED). لا ادعاء حالة | لا تغيير | NO CHANGE PROPOSED — consistent | لا شيء |
| E-10 | `final-report.md:622` | «CI #68 على 79fb408 (عبر PR #9)» | `lavkoo@79fb408` سلف لـ `origin/main` (VERIFIED). CI في legacy غير متحقق منه | لا تغيير | NO CHANGE PROPOSED — consistent (number only) | لا شيء |
| E-11 | `final-report.md:623` | «CI #66 على 01bb5e5 (رأس الفرع، عبر PR #9)» | `lavkoo@01bb5e5` سلف لـ `origin/main` (VERIFIED) | لا تغيير | NO CHANGE PROPOSED — consistent (number only) | لا شيء |
| E-12 | `final-report.md:626` | «CI #62 على 55dc7e0 عبر PR #9» | `lavkoo@55dc7e0` سلف لـ `origin/main` (VERIFIED) | لا تغيير | NO CHANGE PROPOSED — consistent (number only) | لا شيء |
| E-13 | `final-report.md:630` | «CI #55 على f11d3d5 (رأس الفرع، عبر PR #6)» | `lavkoo@f11d3d5` سلف لـ `origin/main` (VERIFIED) | لا تغيير | NO CHANGE PROPOSED — consistent (number only) | لا شيء |
| E-14 | `final-report.md:364` | «ما تغيّر بعد دمج PR #5 (الجولة الرابعة)» | `lavkoo@be43519e` (2026-11-07) سلف لـ `origin/main` (VERIFIED) | لا تغيير | NO CHANGE PROPOSED — consistent | لا شيء |
| E-15 | `final-report.md:424–426` | «قبلت أن يُدمج PR #4 في main أولًا بالتزام دمج» | `lavkoo@0bcabe8c` (#4، 2026-11-07) سلف لـ `origin/main` (VERIFIED). الجملة وصف خطة قديمة | لا تغيير | NO CHANGE PROPOSED — consistent | لا شيء |
| E-16 | `final-report.md:434` | «دمج PR #5 في main تمّ بموافقة المالك» | `lavkoo@be43519e` سلف لـ `origin/main` (VERIFIED). موافقة المالك خارج الأدلة هنا | لا تغيير | NO CHANGE PROPOSED — consistent | لا شيء |
| E-17 | `final-report.md:474` | «بعد دمج PR #4 فيه» | `lavkoo@0bcabe8c` (VERIFIED) | لا تغيير | NO CHANGE PROPOSED — consistent | لا شيء |
| E-18 | `final-report.md:632` | «GitHub Actions على main بعد دمج PR #5» | `lavkoo@be43519e` (VERIFIED). CI legacy غير متحقق منه | لا تغيير | NO CHANGE PROPOSED — consistent | لا شيء |
| E-19 | `final-report.md:638` | «دمج origin/main (فيه PR #4)» | `lavkoo@0bcabe8c` سلف لـ `origin/main` (VERIFIED) | لا تغيير | NO CHANGE PROPOSED — consistent | لا شيء |
| E-20 | `final-report.md:660` | «main يحوي PR #4 وPR #5 سلفًا (الالتزام be43519)» | `lavkoo@be43519e` سلف لـ `origin/main` (VERIFIED). `lavkoo@0bcabe8c` سلف (VERIFIED) | لا تغيير | NO CHANGE PROPOSED — consistent | لا شيء |
| E-21 | `final-report.md:680` | «اتفقتُ مع محادثة PR #4 على أن يُدمج PR #4 في main أولًا» | خطة قديمة؛ `lavkoo@0bcabe8c` سلف (VERIFIED) | لا تغيير | NO CHANGE PROPOSED — consistent | لا شيء |
| E-22 | `final-report.md:686` | «موعد دمج فرع الجولة الرابعة في main (PR #5 دُمج بموافقتك…)» | `lavkoo@be43519e` سلف (VERIFIED) | لا تغيير | NO CHANGE PROPOSED — consistent | لا شيء |
| E-23 | `final-report.md:698` | «C وD وE وF أربعة فروع مستقلّة عن main» | وقت الكتابة صحيح في legacy. في lavkoo: محتوى C–F دخل `main` عبر `cbf2cddb` (PR #17) (VERIFIED) | «كانت فروع C–F مستقلة عن main في pharmacyksa98 وقت الكتابة. في lavkoo دُمج محتواها في main عبر cbf2cddb (PR #17) بتاريخ 2026-11-11.» | PROPOSED — requires owner approval, not applied | اعتماد التوضيح |
| E-24 | `final-report.md:747` | «وهو من PR #10 المدموج سابقًا» | `lavkoo@8517d6ee` سلف (VERIFIED) | لا تغيير | NO CHANGE PROPOSED — consistent | لا شيء |
| E-25 | `merge-plan.md:127` | «claude/pr-c-amazon-completion (PR #12، مفتوح)» | الفرع غير موجود على ريموت lavkoo (VERIFIED). محتواه في `lavkoo@d9c76422` سلف لـ `origin/main` | «(PR #12، مفتوح وقت لقطة 2026-11-10؛ لقطة قديمة. محتواه في lavkoo main عبر cbf2cddb.)» | PROPOSED — requires owner approval, not applied | اعتماد التوضيح |
| E-26 | `merge-plan.md:128` | «claude/pr-d-trendyol-products (PR #13، مفتوح)» | محتواه في `lavkoo@92f0feea` سلف (VERIFIED) | «(PR #13، مفتوح وقت لقطة 2026-11-10؛ لقطة قديمة. محتواه في lavkoo main.)» | PROPOSED — requires owner approval, not applied | اعتماد التوضيح |
| E-27 | `merge-plan.md:129` | «claude/pr-e-noon (PR #14، مفتوح)» | محتواه في `lavkoo@561e8585` سلف (VERIFIED) | «(PR #14، مفتوح وقت لقطة 2026-11-10؛ لقطة قديمة. محتواه في lavkoo main.)» | PROPOSED — requires owner approval, not applied | اعتماد التوضيح |
| E-28 | `merge-plan.md:130` | «claude/pr-f-odoo (PR #15، مفتوح)» | محتواه في `lavkoo@538fe91f` سلف (VERIFIED) | «(PR #15، مفتوح وقت لقطة 2026-11-10؛ لقطة قديمة. محتواه في lavkoo main.)» | PROPOSED — requires owner approval, not applied | اعتماد التوضيح |
| E-29 | `merge-plan.md:132` | «claude/pr-g-guards (PR #16، **مسوّدة**)» | `lavkoo@9b3d5244` سلف (VERIFIED) | «(PR #16، مسودة وقت لقطة 2026-11-10؛ لقطة قديمة. محتواه في lavkoo main.)» | PROPOSED — requires owner approval, not applied | اعتماد التوضيح |
| E-30 | `merge-plan.md:134` | «claude/pharmacyksa-shipping-tests-31q1nu ← لا PR له» | في legacy: لا PR وقت الكتابة (المرجع). في lavkoo: رسالة الدمج `cbf2cddb` تذكر «Merge pull request #17 from …pharmacyksa-shipping-tests-31q1nu» (VERIFIED نصياً؛ كائن PR غير موجود، §2.1) | «لا PR له في pharmacyksa98 وقت الكتابة. في lavkoo ظهر في merge commit بعنوان PR #17 (cbf2cddb)، ولا يوجد كائن PR مقابل على GitHub في هذا الرمز.» | PROPOSED — requires owner approval, not applied | اعتماد التوضيح |
| E-31 | `merge-plan.md:149` | «إغلاق PR #12–#15 بوصفها مُستوعَبة (مُحتواة في الدمج)» | إجراء على legacy، حالته UNKNOWN. المحتوى في lavkoo main | «إجراء مقترح على pharmacyksa98 لا يُنفَّذ قبل تحقق حالة كل PR فيه. المحتوى في lavkoo main عبر cbf2cddb.» | PROPOSED — requires owner approval, not applied | قرار إغلاق في legacy لا يُتخذ قبل Q-30 |
| E-32 | `merge-plan.md:150` | «إغلاق PR #16 (المسوّدة) كذلك، أو إعادة توجيه أساسه» | إجراء على legacy، حالته UNKNOWN | «إجراء مقترح على pharmacyksa98 لا يُنفَّذ قبل تحقق حالة PR #16 فيه.» | PROPOSED — requires owner approval, not applied | نفس E-31 |

### 5.1 الخلاصة العددية للسجل

- عدد الإدخالات: **32**.
- إدخالات تحتاج تصويباً (PROPOSED): **E-01، E-02، E-03، E-05، E-07، E-08، E-23، E-25 إلى E-32** = 15 إدخالاً. (E-30 وE-31 وE-32 كلها إجراءات أو تفسيرات على legacy تحتاج قرار المالك.)
- إدخالات متسقة بلا تغيير: **17** (E-04، E-06، E-09 إلى E-22، E-24).
- **أولوية عالية:** E-07 (الجملة الجوهرية في `final-report.md:8`) وE-02 (حالة #12–#16) وE-01 (حالة #9–#11).

## 6. Q-30: الجواب

**Q-30 لا يُغلق في هذه الجلسة.** السبب من الحقائق:
- الوصول إلى `pharmacyksa98` خارج نطاق هذه الجلسة، ولم يُستدعَ أي أمر أو أداة عليه؛ لذلك لم يُختبر الوصول، وحالة PR #12 إلى #18 فيه تبقى **UNKNOWN**.
- يمكن **تحديث وصف** Q-30 فقط، ليصبح «قراءة فقط للتحقق من PR #12–#18 في `pharmacyksa98`، بلا كتابة»، لكن منح هذا النطاق لأي جلسة قرار للمالك وحده.
- **إغلاق G0 ليس تلقائياً** ولا يتم بمجرد تحديث وصف Q-30؛ وG0 يتوقف على قرار مكتوب من المالك بشأن حالة `pharmacyksa98` أو قبول UNKNOWN كقيد (`reports/12` N5).

## 7. جدول النسب للـ PR المدموجة في سجل lavkoo

| PR | merge SHA في lavkoo | التاريخ | سلف لـ `origin/main` | ملاحظة |
|---|---|---|---|---|
| #1 | `ef843600` | 2026-11-06 | نعم | `clever-newton-wvhbt2` |
| #2 | `506429a8` | 2026-11-06 | نعم | نفس الفرع |
| #3 | `665c2315` | 2026-11-06 | نعم | — |
| #4 | `0bcabe8c` | 2026-11-07 | نعم | — |
| #5 | `be43519e` | 2026-11-07 | نعم | — |
| #6 | `242a8612` | 2026-11-08 | نعم | — |
| #7 | `079822d3` | 2026-11-08 | نعم | — |
| #8 | `a687ecbe` | 2026-11-08 | نعم | — |
| #9 | `4fa2cb56` | 2026-11-08 | نعم | لا كائن PR على GitHub (§2.1) |
| #10 | `8517d6ee` | 2026-11-08 | نعم | لا كائن PR على GitHub |
| #11 | `cd54f1eb` | 2026-11-08 | نعم | لا كائن PR على GitHub |
| #12 | لا merge بهذا الرقم | — | محتوى PR-C عبر `d9c76422` (سلف) | — |
| #13 | لا merge بهذا الرقم | — | محتوى PR-D عبر `92f0feea` (سلف) | — |
| #14 | لا merge بهذا الرقم | — | محتوى PR-E عبر `561e8585` (سلف) | — |
| #15 | لا merge بهذا الرقم | — | محتوى PR-F عبر `538fe91f` (سلف) | — |
| #16 | لا merge بهذا الرقم | — | محتوى PR-G عبر `9b3d5244` (سلف) | — |
| #17 | `cbf2cddb` | 2026-11-11 | نعم | يحمل محتوى C–G |
| #18 | `f61939f8` | 2026-11-11 | نعم | — |

التحقق بـ `merge-base --is-ancestor` = نعم لكل SHA أعلاه (VERIFIED). أرقام PR #1–#8 مختصرة من `E21 §2.3`، وتحققت منها بالسجل.

## 8. فروق الخط بعد الأساس

- `lavkoo`: `origin/main` تقدم 16 commit بعد `89f1be9` (خط الأساس)، والمحلي `main` متأخر 5 commits عن `origin/main`. لم تُحلَّل محتويات هذه الفروق هنا.
- `lavkooapp`: لا فرق. `eedbbb8` = الأساس.
- `lavkooappseller`: 8 commits بين `8ea0092` (الأساس المحلي) و`9ea6423` (main@9ea6423، الريموت)، مذكورة في §2.8. لم يُحلَّل محتواها.

## 9. الحدود وعناصر UNKNOWN

- **UNKNOWN:** حالة PR #12–#18 في `pharmacyksa98` (خارج النطاق). قول المالك بأنها مغلقة أو مدموجة: OWNER-REPORTED، UNVERIFIED.
- **UNKNOWN:** سبب غياب كائنات PR على GitHub لـ `lavkoo` (0 PR، و404 لـ #17 و#18، وفراغ `commits/<sha>/pulls` للـ merge commits)، مع أن الرمز يملك صلاحيات admin.
- **UNKNOWN:** هل يطابق محتوى الملفات السبعة لـ MyFatoorah في `lavkooapp@eedbbb8` محتوى PR #1 في `ksaapp98`. لم تُجرَ مقارنة محتوى (PARTIAL).
- **UNKNOWN:** الـ commit المنشور على الإنتاج، وهو خارج هذه الجلسة.
- **INFERRED:** أن `lavkoo` أُنشئ بنقل التاريخ من `pharmacyksa98` بأرقامه الأصلية. هذا مرجَّح من رسائل merge ومن `E21 §2.3`، لكنه لم يُتحقق منه بوصول إلى legacy.
- **حد التحقق:** الوثائق المعتمدة قُرئت من `origin/main` (55e3dd0e). شجرة العمل المحلية في `f2e01213`، وقد تختلف أرقام أسطرها؛ لذلك تُقرأ الأسطر من `origin/main` لا من شجرة العمل.
- **خارج النطاق:** تحليل كود تطبيق البائع، وتحليل محتوى merge الفروع C–G بالتفصيل، ومراجعة PR كل رقم.
- **لم يُكتب أي شيء:** لم يُعدَّل `DECISIONS.md` ولا `STATUS.md` ولا `final-report.md` ولا `merge-plan.md`. هذه المقترحات كلها تنتظر موافقة المالك.

## 10. قرارات المالك المطلوبة

1. اعتماد أو رفض E-01 وE-02 وE-05 وE-07 وE-08 وE-23 وE-25 إلى E-32 (تصويب `DECISIONS.md` و`STATUS.md` و`final-report.md` و`merge-plan.md`).
2. تأكيد أن قول المالك عن حالة PR #12–#18 في legacy هو المصدر المؤقت، أو منح نطاق قراءة للتحقق (Q-30).
3. قرار بشأن مقارنة محتوى ملفات MyFatoorah بين `ksaapp98` و`lavkooapp` (E-03)، أو إبقاؤه UNKNOWN.
4. قرار بشأن أسباب غياب كائنات PR على GitHub لـ `lavkoo`، وإن كان يلزم التحقق من ذلك في جلسة أخرى.
5. لا يُغلق G0 بهذا التقرير.
