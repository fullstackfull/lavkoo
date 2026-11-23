# D-01(B) — Phase 0 محدودة، قراءة وتوثيق فقط (P0-A وP0-C وP0-E)

> **المرجع:** `docs/gcc/PHASE0-D01B-LIMITED-AUTHORIZATION.md` (الالتزام `c8b6ed3e`).
> **التاريخ:** 2026-11-12 14:19 +03:00 (تاريخ التكليف). **الحالة:** موثّق، **غير مُغلق**. لا شيء من P0-A أو P0-C أو P0-E مُغلق، وG0 غير مُغلق، وQ-30 مفتوح.
> **نوع العمل:** توثيق فقط. لا وصول إلى الإنتاج، ولا نسخة قاعدة بيانات أُنشئت أو استُعيدت، ولا تعديل كود، ولا تشغيل اختبارات أو CI.

## 1. رؤوس الفروع التي تُعدّ مرجعاً لهذه الجولة

| المستودع | الرأس | المصدر |
|---|---|---|
| `fullstackfull/lavkoo` `main` | `55e3dd0e` | ls-remote |
| `fullstackfull/lavkoo` `claude/exciting-shannon-4mh2ok` | `fe3907d6` بعد المزامنة (كان `c8b6ed3e` عند بدء التكليف و`cd9ef550` عند كتابة مسودة P0-A) | ls-remote والفرع المحلي بعد الجلب |
| `fullstackfull/lavkooapp` `main` | `eedbbb8` | ls-remote، والفرع المحلي مطابق |
| `fullstackfull/lavkooappseller` `main` | `9ea6423` (الفرع المحلي `8ea0092` لم يُغيَّر) | ls-remote |
| `fullstackfull/ksaapp98` | `79f50be` (محلي، قراءة فقط) | النسخة المحلية |
| `fullstackfull/pharmacyksa98` | **غير متاح لهذه الجلسة (BLOCKED)** | لا استدعاء عليه |

## 2. الملفات المنشورة

| الملف | المحتوى | الالتزام الأخير |
|---|---|---|
| [01-p0a-git-lineage.md](./01-p0a-git-lineage.md) | P0-A: نسب Git وخط الأساس وPRs #9–#18 والحالة الحدّية | [`9f84e82`](https://github.com/fullstackfull/lavkoo/blob/9f84e824c3ba102d8848e6fabc992f000c1d7da3/docs/gcc/audits/2026-11-12-1419-phase0-d01b-limited/01-p0a-git-lineage.md) |
| [02-p0c-schema-baseline.md](./02-p0c-schema-baseline.md) | P0-C: جرد ساكن للترحيلات والجداول، وDDL المعزول BLOCKED | [`3dcc5e0`](https://github.com/fullstackfull/lavkoo/blob/3dcc5e018bbb6f76bebebcee58655a45f295692e/docs/gcc/audits/2026-11-12-1419-phase0-d01b-limited/02-p0c-schema-baseline.md) |
| [03-p0e-isolated-measurements.md](./03-p0e-isolated-measurements.md) | P0-E: تعيين M-01 إلى M-16 إلى مصادرها، وكلها BLOCKED | [`8be8c9e`](https://github.com/fullstackfull/lavkoo/blob/8be8c9e18da57dfe1863a4223e6b9f85891d6017/docs/gcc/audits/2026-11-12-1419-phase0-d01b-limited/03-p0e-isolated-measurements.md) |
| [04-gates-blockers-next-approvals.md](./04-gates-blockers-next-approvals.md) | البوابات والمعوّقات B-01 إلى B-08 والموافقات المطلوبة وانحرافات الإجراء | [`2a94f44`](https://github.com/fullstackfull/lavkoo/blob/2a94f44513d963a04d1b7665cc180d5d9c67afab/docs/gcc/audits/2026-11-12-1419-phase0-d01b-limited/04-gates-blockers-next-approvals.md) |

## 3. ما هو VERIFIED

- رؤوس الفروع المذكورة أعلاه (ls-remote، وقراءة محلية للقراءة فقط).
- تفرّد الجرد الساكن: 336 ملف ترحيل جذري و14 ملف ترحيل في الوحدات (المجموع 350 من ملفات PHP) على `main`.
- عدم وجود كائنات PR للمشاريع الحالية عبر API (404 لـ#9 و#18 في `lavkoo`)، ووجود دمجات #9 إلى #18 في سجل `lavkoo` مع تحقق السلف بـ`merge-base`.
- أسماء مفاتيح `.env.example` فقط (لا قيم).

## 4. ما هو BLOCKED أو UNKNOWN

- `pharmacyksa98` (B-06): لا وصول لهذه الجلسة. Q-30 مفتوح.
- نسخة معزولة مُجهَّلة (B-01): لم تُقدَّم. لذلك كل DDL معزول وكل قياس M-01 إلى M-16 BLOCKED.
- الإنتاج (B-02، B-05): لا وصول، ولا تُنفَّذ قراءة إنتاج في هذه المهمة.
- الترحيلات المطبَّقة فعلاً، والقيم المالية، والأحجام والمحرك، ورصيد المحافظ، والتسويات: UNKNOWN حتى تتوفر الموافقات في القسم 2 من التقرير 04.

## 5. انحرافات الإجراء

التفاصيل والإصلاح في [04](./04-gates-blockers-next-approvals.md) القسم 4: نشرت مسودات التقارير الثلاثة آلياً قبل المراجعة، وصُحِّحت المسودات الأولى بنشر لاحق بعد التحقق، ورسالة `fe3907d6` مضلّلة ولا تُعاد كتابتها، وقُرئ `.env.example` أثناء المسودة دون اقتباس قيم.

## 6. الحدود

- لم يُغلق P0-A ولا P0-C ولا P0-E، ولم يُغلق G0، ولم يُغيَّر أي حكم Go/No-Go.
- لم تُعدَّل أي وثيقة معتمدة (`DECISIONS.md`، `STATUS.md`، `final-report.md`، `merge-plan.md`).
- القرار في الإغلاق للمالك وحده، بتوقيع كتابي منفصل.
