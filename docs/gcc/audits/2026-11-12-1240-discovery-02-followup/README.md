# Lavkoo GCC — Discovery-02 Follow-up — فهرس التدقيق والحدود

> **المرحلة:** AUDIT / DOCUMENTATION ONLY — لا تنفيذ، ولا تغيير في كود التطبيقات، ولا تشغيل لأي اختبار، ولا دمج، ولا تغيير في الإنتاج.
> **التاريخ:** 2026-11-12 (+03:00) — **الجلسة:** `claude/exciting-shannon-4mh2ok` — **المكلَّف به:** `docs/gcc/DISCOVERY_FOLLOWUP_02_PROMPT.md`
> **الحكم الحالي:** Phase 0 — Go بشروط، **غير مُصرَّح بها** بهذا الفهرس؛ مراحل التنفيذ 1–17 وأي تغيير في الإنتاج — **No-Go**.

## 1. نقاط الأساس (VERIFIED عند كتابة هذا الفهرس)

| المستودع | الرأس المدقَّق | ملاحظة |
|---|---|---|
| `fullstackfull/lavkoo` (الفرع `claude/exciting-shannon-4mh2ok`) | `f2e01213` عند بدء الجولة؛ الرأس بعد النشر موضَّح في سجل الالتزامات | الجولة وثائقية فقط؛ لا تغيير في الكود بين `7f42777c` (أساس Discovery-01) والفرع |
| `fullstackfull/lavkoo` (`main`) | `55e3dd0e` | وثائق GCC-20 فقط؛ لم يُدمج شيء في `main` |
| `fullstackfull/lavkooapp` (`main`) | `eedbbb8f` | لا يوجد workflow للـ CI |
| `fullstackfull/lavkooappseller` (`main`) | `9ea64232` (الأساس المدقَّق السابق `8ea0092`) | 8 التزامات؛ 34 ملفاً تغيّر بعد الأساس، منها 32 غير وثائقية |

## 2. الملفات المنشورة

| الملف | المحتوى | الالتزام الأخير |
|---|---|---|
| [01-security-triage-public.md](./01-security-triage-public.md) | فرز أمني عام: 222 معرّفاً بفئات مجالات وأولويات وإجراءات، بلا تفاصيل آلية | [`f8398b6`](https://github.com/fullstackfull/lavkoo/blob/f8398b69303b8d723de39a6010216dd0b67fdd5f/docs/gcc/audits/2026-11-12-1240-discovery-02-followup/01-security-triage-public.md) |
| [02-git-lineage-and-n6-errata.md](./02-git-lineage-and-n6-errata.md) | نسب Git ومقارنة القديم والجديد، وسجل تصويبات N6 المقترحة، والإجابة عن Q-30 | [`2522c53`](https://github.com/fullstackfull/lavkoo/blob/2522c536d7560b04c3214ce5fd5c1f5b7bc5a80e/docs/gcc/audits/2026-11-12-1240-discovery-02-followup/02-git-lineage-and-n6-errata.md) |
| [03-n1-n8-and-coverage-gaps.md](./03-n1-n8-and-coverage-gaps.md) | سجل تصويبات N1–N8 والحالة المحدَّثة، وملخص فجوات التغطية | [`5cc776c`](https://github.com/fullstackfull/lavkoo/blob/5cc776c7bfdd20392c4007be26986324a1f45e17/docs/gcc/audits/2026-11-12-1240-discovery-02-followup/03-n1-n8-and-coverage-gaps.md) |
| [03a-coverage-gaps-e01-e11.md](./03a-coverage-gaps-e01-e11.md) | فجوات التغطية للأدلة E01–E11 (149 فجوة)، والتحقق بالقراءة الساكنة | [`7bab019`](https://github.com/fullstackfull/lavkoo/blob/7bab019652bd8952bc66a22ca471ea6db3b6c5b2/docs/gcc/audits/2026-11-12-1240-discovery-02-followup/03a-coverage-gaps-e01-e11.md) |
| [03b-coverage-gaps-e12-e23.md](./03b-coverage-gaps-e12-e23.md) | فجوات التغطية للأدلة E12–E23 (137 فجوة) | [`3128fe2`](https://github.com/fullstackfull/lavkoo/blob/3128fe29a8eb2dc2600946108dfb7613d9e2c48a/docs/gcc/audits/2026-11-12-1240-discovery-02-followup/03b-coverage-gaps-e12-e23.md) |
| [03c-seller-drift-check.md](./03c-seller-drift-check.md) | انحراف تطبيق البائع من `8ea0092` إلى `9ea6423`، وتحليل بوابة الإصدار | [`b1c0048`](https://github.com/fullstackfull/lavkoo/blob/b1c0048126e9aa7d7a76c9eba7b477775ada0b3a/docs/gcc/audits/2026-11-12-1240-discovery-02-followup/03c-seller-drift-check.md) |
| [04-owner-questions-q01-q50.md](./04-owner-questions-q01-q50.md) | أسئلة المالك Q-01 إلى Q-50، وحزمة قرارات المالك العشرة (PROPOSED) | [`f8326da`](https://github.com/fullstackfull/lavkoo/blob/f8326da7b81e41ad929a5d9fa8e5827c39b6aab7/docs/gcc/audits/2026-11-12-1240-discovery-02-followup/04-owner-questions-q01-q50.md) |
| [05-gcc20-superadmin-audit.md](./05-gcc20-superadmin-audit.md) | تدقيق GCC-20 (Super Admin في تطبيق البائع): 20 نتيجة و28 حالة اختبار | [`538c271`](https://github.com/fullstackfull/lavkoo/blob/538c2717cb326da18782e2797603e0fe6c36bf3a/docs/gcc/audits/2026-11-12-1240-discovery-02-followup/05-gcc20-superadmin-audit.md) |
| [06-updated-go-no-go.md](./06-updated-go-no-go.md) | حكم Go/No-Go المحدَّث بعد الجولة | [`41a511d`](https://github.com/fullstackfull/lavkoo/blob/41a511d9f4cb0a3dcb6aaca8a0ad93e39d309a14/docs/gcc/audits/2026-11-12-1240-discovery-02-followup/06-updated-go-no-go.md) |

الملفات المدقَّقة في Discovery-01 لم تُعدَّل. التقارير 01–12 في `docs/gcc/audits/2026-11-12-0115-discovery-01/` تبقى كما نُشرت (مع إصلاحاته السابقة).

## 3. النتائج الرئيسية (بالتحديث بعد التحقق المستقل)

- **بوابة إصدار تطبيق البائع:** `pubspec` في `main` يحمل `1.0.0+3` بدل `3.1.6+49`. اختبار البوابة يثبّت حدّاً ثابتاً كتبه المطوّر بالقيمة `2.0.1`، وقيمة الخادم الافتراضية (seed) هي `14.1` لمنصّتي Android وiOS. أي بناء بالإصدار `1.0.0` يُحجب أمام أي حد أعلى من `1.0.0` (VERIFIED بالكود، لم يُشغَّل الاختبار؛ القيمة الإنتاجية UNKNOWN). **تحقّق المالك قبل أي إطلاق.**
- **CI:** فاشل على رأس `lavkoo` (`55e3dd0`) ورأس `lavkooappseller` (`9ea6423`)؛ في البائع فشلت خطوة `Test` (`flutter test`) ونجحت `Analyze`، وتخطّت `build-android` بسبب الاعتماد. `lavkooapp` لا يملك workflow. سبب الفشل التاريخي ما زال INFERRED، وسجلات الفشل لم تُقرأ.
- **حالة G1-E09-01 (مسار الدفع):** مرشّح P1، ويصبح P0 فقط إذا تحقق الشرطان معاً: (1) البوابة تحصّل الإجمالي دون طرح الخصم، وهذا متحقق بالكود؛ (2) الخصم غير مموَّل من جهة أخرى، وهذا UNKNOWN ويلزم بيانات التسوية والاسترداد من المالك. الخصم يخفّض وعاء الضريبة ويُطرح لاحقاً من `order_amount` المخزَّن، ولا يُطرح من المبلغ المحصَّل. تبقى P1 حتى تتوفر تلك البيانات.
- **سجل N1–N8:** N1 جزئي، N2 محلول بقيد، N3 غير محلول مع مسودات، N4 جزئي، N5 غير محلول، N6 غير محلول، N7 وN8 محلولان.
- **Q-30 (الوصول إلى `pharmacyksa98`):** يبقى **مفتوحاً**. المستودع خارج نطاق هذه الجلسة ولم يُقرأ بأي وسيلة. قول المالك بأن PRs #12–#18 مغلقة أو مدموجة مُسجَّل كقول غير متحقَّق منه.
- **GCC-20:** 20 نتيجة و28 حالة اختبار. أربعة مؤشرات أمنية مقيّدة (`SEC-G20-01` إلى `04`) محفوظة في ملحق خاص خارج المستودع.
- **الفرز الأمني:** 222 معرّفاً؛ 23 بأولوية P0 صريحة و4 مشروطة بالقيمة الإنتاجية. كل الفرز بفئات عامة وإجراءات، بلا وصف لكيفية تنفيذ أي بند.

## 4. ما لم يُنجز في هذه الجولة (بنص التكليف)

- لم تُبدأ Phase 0 ولا المراحل 1–17؛ ولم يُنفَّذ GCC-20.
- لم تُعدَّل أي ملفات كود في `lavkoo` أو `lavkooapp` أو `lavkooappseller`، ولم يُشغَّل أي اختبار أو build أو أمر إنتاج.
- لم يُدمج أي فرع، ولم يُستخدم force push، ولم يُعدَّل `main` أو الوثائق المعتمدة (`DECISIONS.md`، `STATUS.md`، `final-report.md`).
- لم يُدوَّر أي سرّ؛ التدوير مسؤولية المالك (انظر §5).
- لم يُفتح `pharmacyksa98`.

## 5. قرارات مطلوبة من المالك (مرتبة بالأولوية)

1. **تدوير الاعتماد المكشوف** (`SEC-E21-04`)، وتحديد المزوّد من اسم المفتاح فقط، ومراجعة سجلات الاستخدام، وإزالة الملف المتتبَّع في commit لاحق. القيمة لم تُنسخ ولم تُختبر.
2. **مراجعة `SEC-E05-03`** (بند P0) من مراجع أمني مخوَّل عبر قناة خاصة، وطلب الملحق المقيّد من هذه القناة.
3. **قرار بوابة إصدار البائع:** الحد الإنتاجي الفعلي، ورقم الإصدار (`1.0.0+3` أو العودة إلى سلسلة `3.x`)، ومعالجة اختبار البوابة وCI.
4. **Q-30:** هل تُمنح الجلسة صلاحية قراءة `pharmacyksa98` لأغراض التحقق من PRs #12–#18، أم تبقى الحالة UNKNOWN؟
5. **تاريخ الالتزامات المكشوفة:** `41201499` (E08)، `98e795b` (E12)، `f263e3d` (E22) تحمل نصوصاً قبل التنقيح في تاريخ عام. قرار إعادة كتابة التاريخ للمالك وحده، ولا يُنفَّذ force push بدونه؛ والتنظيف لا يعالج اعتماداً سُرّب فعلاً.
6. **حزمة القرارات PROPOSED** في `04-owner-questions-q01-q50.md`: D-01 هو الشرط الصريح لدخول Phase 0.
7. **تحديث الوثائق المعتمدة** بتصويبات N6 بعد موافقة المالك على كل منها، وتوضيح أن PRs #12–#16 لم تُثبت حالتها.

## 6. الحدود

- كل النتائج قراءة ساكنة (GitHub read tools أو `git` للقراءة فقط). لم تُنفَّذ أي شيفرة، ولم يُفحص أي بيانات إنتاج أو أي إعداد حي.
- القيم الإنتاجية (`seller_app_version_control`، والعملات، والإعدادات المالية، وجداول الإنتاج) UNKNOWN حتى يوفّرها المالك بقراءة فقط.
- الملاحق الأمنية المقيّدة **غير منشورة**؛ تُسلَّم للمالك عبر قناة خاصة. الفرز العام لا يحوي أي وصف تشغيلي.
- ملاحظة على السجل: الالتزامات المنشورة في الفرع قبل التنقيح (`41201499`، `98e795b`، `f263e3d`) ما زالت في التاريخ العام، وتبقى كذلك إلى أن يقرر المالك خلاف ذلك.

## 7. الروابط المرجعية

- فهرس Discovery-01: [`docs/gcc/audits/2026-11-12-0115-discovery-01/README.md`](../../2026-11-12-0115-discovery-01/README.md)
- التكليف: [`docs/gcc/DISCOVERY_FOLLOWUP_02_PROMPT.md`](../../../DISCOVERY_FOLLOWUP_02_PROMPT.md)
- الـ handoff: يُربط من `docs/gcc/STATUS.md` بعد النشر.
