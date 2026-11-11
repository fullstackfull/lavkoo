# Lavkoo GCC — Discovery-01 — فهرس التدقيق وبيان بدء الجلسة

> **المرحلة:** AUDIT (قراءة فقط) — لا تنفيذ، لا تعديل كود، لا قاعدة بيانات، لا دمج، لا نشر.
> **الجلسة:** `claude/exciting-shannon-4mh2ok` — **بدء الجلسة:** 2026-11-12 01:15 (+03:00)
> **المرجع:** [`docs/gcc/DISCOVERY_PROMPT_01.md`](../../DISCOVERY_PROMPT_01.md) وفق [`docs/gcc/COLLABORATION.md`](../../COLLABORATION.md) و[`docs/gcc/DECISIONS.md`](../../DECISIONS.md).

هذا الملف هو فهرس مخرجات جلسة التدقيق Discovery-01. يُحدَّث مع نشر كل تقرير، ولا يستبدل أي ملف لجلسة أخرى.

## 1. المستودعات و SHA الأساس (VERIFIED عند بدء الجلسة)

| النظام | المستودع | الفرع | SHA الأساس |
|---|---|---|---|
| Laravel backend / web / admin / APIs | `fullstackfull/lavkoo` | `main` | `7f42777c8247144df322fdc0e0eeca03ae947129` |
| Flutter customer app | `fullstackfull/lavkooapp` | `main` | `eedbbb8fd0303aa9fed5d4ff0ac45b3a409abf7a` |
| Flutter seller app | `fullstackfull/lavkooappseller` | `main` | `8ea00922e44db3d69059d46892f8f4f84349b580` |
| (مرجع قديم) Flutter customer legacy | `fullstackfull/ksaapp98` | `main` | `79f50bec1c419cae8bc1a6f4c314962d3b682ac0` |

## 2. حالة GitHub عند بدء الجلسة (VERIFIED عبر GitHub API)

- كل من المستودعات الثلاثة الحالية يحتوي فرعاً واحداً فقط هو `main`، و**لا يوجد فيه أي Pull Request** (مفتوح أو مغلق).
- لا يوجد على `main` أي ملف في `docs/gcc/handoffs/` أو `docs/gcc/audits/` قبل هذه الجلسة؛ أي لا توجد جلسة تدقيق سابقة منشورة تتعارض مع هذا العمل.
- أرقام الـ PR الظاهرة في رسائل الـ commits داخل تاريخ `lavkoo` (‎#1–#11 و‎#17 و‎#18) تعود إلى ترقيم المستودع القديم، وليست PRs في `lavkoo`.

## 3. قيود الوصول (تؤثر على بنود UNKNOWN)

| البند | الحالة |
|---|---|
| `fullstackfull/pharmacyksa98` (backend القديم) | **غير متاح لهذه الجلسة** — طلب إضافة المستودع للقراءة رُفض من نظام صلاحيات الجلسة. الحالة الحيّة لـ PRs القديمة ‎#12–#16 تبقى `UNKNOWN (missing access)` إلى أن يمنح المالك الوصول. |
| `fullstackfull/ksaapp98` (تطبيق العميل القديم) | متاح للقراءة فقط؛ استُخدم للمقارنة مع `lavkooapp`. |
| قاعدة بيانات الإنتاج، `.env`، لوحات المزوّدين، نسخ التطبيقات المنشورة في المتاجر | غير متاحة (وغير مطلوبة في هذه المرحلة). القيم المخزّنة في DB تُوسَم `UNKNOWN`. |

## 4. فرع النشر

- تُنشر تقارير هذه الجلسة على الفرع **`claude/exciting-shannon-4mh2ok`** في `fullstackfull/lavkoo`، لأن صلاحية الكتابة الممنوحة للجلسة محصورة بهذا الفرع.
- **لم يُكتب أي شيء على `main`** ولم يُفتح أي Pull Request. دمج هذه الوثائق إلى `main` (ليقرأها بقية الجلسات من المسار المعتمد) قرار يعود للمالك.
- لا يُكتب أي ملف في `lavkooapp` أو `lavkooappseller`؛ جميع التقارير في المستودع المرجعي `lavkoo` فقط.

## 5. المنهجية

- **تدقيق ساكن للقراءة فقط:** قراءة الكود وتاريخ Git والوثائق. لم تُشغَّل أي اختبارات أو builds أو أوامر `artisan`/`composer`/`flutter`، ولم يُجرَ أي اتصال بمزوّد خارجي أو ببيئة الإنتاج.
- **معيار الدليل:** كل ادعاء يحمل استشهاداً بالصيغة `<repo>:<path>:<line>` (والـ commits بالصيغة `<repo>@<sha>`) نسبةً إلى SHA الأساس في الجدول أعلاه.
- **وسوم الحالة:** `VERIFIED` (قُرئ الكود في الموضع المذكور) — `INFERRED` (استنتاج من أدلة غير مباشرة) — `UNKNOWN` (لا يمكن حسمه بالقراءة الساكنة، مع ذكر الوصول اللازم).
- **درجات الخطورة:** `P0` (يمنع الإطلاق أو يهدد بخسارة مالية/تسريب بين المتاجر/تعرّض قانوني/إفساد بيانات السعودية الحالية) — `P1` (يجب حلّه ضمن التنفيذ بمسار معروف) — `P2` (تحسين/ثانوي) — `INFO` (حقيقة يجب أن يراعيها التصميم).
- **التحقق المستقل:** كل ملحق أدلة يراجعه مدقق ثانٍ مستقل يحاول دحض الادعاءات، مع فحص آلي لصحة كل استشهاد (وجود الملف وصحة أرقام الأسطر) قبل النشر.
- **السرّية:** لا تُنسخ أي قيم سرّية أو بيانات شخصية. ولأن هذا المستودع **عام (public)** بحسب بيانات GitHub المتاحة للجلسة، فإن التفاصيل الأمنية القابلة للاستغلال **لا تُنشر هنا**؛ تُسلَّم للمالك مباشرة خارج المستودع ويُشار إليها في التقارير بمعرّفات `SEC-…` فقط.

## 6. المخرجات المخطّطة وحالتها

### التقارير المرقّمة (المطلوبة في DISCOVERY_PROMPT_01)

| # | التقرير | الملف | الحالة |
|---|---|---|---|
| 01 | الملخص التنفيذي وتغطية التدقيق والعوائق الحرجة | `01-executive-summary.md` | قيد الإعداد |
| 02 | المعمارية الحالية عبر المستودعات وعقود الـ API ومخطط الاعتماديات | `02-cross-repo-architecture-api-contracts.md` | قيد الإعداد |
| 03 | مصفوفة ملكية البيانات والعزل والعلاقات المستهدفة | `03-data-ownership-isolation-matrix.md` | قيد الإعداد |
| 04 | مصفوفة Preserve / Extend / Refactor / New / Dependent-On-PR | `04-preserve-extend-refactor-new-matrix.md` | قيد الإعداد |
| 05 | سجل الفجوات والمخاطر P0/P1/P2 | `05-gap-risk-register.md` | قيد الإعداد |
| 06 | نسب Git والـ PRs والفروع بين المستودعات القديمة والحالية | `06-git-lineage-legacy-prs.md` | قيد الإعداد |
| 07 | خطة الترحيل والتراجع ومطابقة أرصدة وطلبات السعودية التاريخية | `07-migration-rollback-plan.md` | قيد الإعداد |
| 08 | مصفوفة الأثر: الويب وتطبيق العميل وتطبيق البائع | `08-web-customer-seller-impact-matrix.md` | قيد الإعداد |
| 09 | استراتيجية الاختبار | `09-test-strategy.md` | قيد الإعداد |
| 10 | خارطة طريق التنفيذ على مراحل صغيرة | `10-implementation-roadmap.md` | قيد الإعداد |
| 11 | القرارات غير المحسومة التي تحتاج مدخلات المالك | `11-open-decisions-for-owner.md` | قيد الإعداد |
| 12 | توصية Go / No-Go النهائية | `12-go-no-go-recommendation.md` | قيد الإعداد |

### ملاحق الأدلة (`evidence/`)

| الرمز | المجال | الحالة |
|---|---|---|
| E01 | خريطة الكود والمعمارية في الـ backend | منشور — [evidence/E01-backend-architecture-map.md](./evidence/E01-backend-architecture-map.md) — 2026-11-12 02:44 (+03:00) |
| E02 | سياق المتجر: الإعدادات العامة، العملة، اللغة، الجلسة، الـ cache | منشور — [evidence/E02-store-context-settings-currency-cache.md](./evidence/E02-store-context-settings-currency-cache.md) — 2026-11-12 02:44 (+03:00) |
| E03 | جرد نموذج البيانات ومصفوفة الملكية | منشور — [evidence/E03-data-model-ownership-inventory.md](./evidence/E03-data-model-ownership-inventory.md) — 2026-11-12 02:44 (+03:00) |
| E04 | هوية العميل، المحفظة، الولاء، العناوين، الموافقات | منشور — [evidence/E04-customer-identity-wallet-loyalty.md](./evidence/E04-customer-identity-wallet-loyalty.md) — 2026-11-12 02:44 (+03:00) |
| E05 | أدوار الموظفين والصلاحيات وموظفو البائع والتدقيق والتصدير | منشور — [evidence/E05-staff-roles-permissions-audit.md](./evidence/E05-staff-roles-permissions-audit.md) — 2026-11-12 02:44 (+03:00) |
| E06 | الاتصالات، الصفحات القانونية، الصيانة/الإيقاف، التوجيه، SEO، الروابط العميقة | منشور — [evidence/E06-communications-compliance-activation.md](./evidence/E06-communications-compliance-activation.md) — 2026-11-12 02:41 (+03:00) |
| E07 | نظام الثيم في الـ backend | منشور — [evidence/E07-theme-system-backend.md](./evidence/E07-theme-system-backend.md) — 2026-11-12 02:42 (+03:00) |
| E08 | الكتالوج، التصنيفات، العلامات، الأسعار، العروض، الكوبونات، البحث | منشور — [evidence/E08-catalog-discovery-pricing.md](./evidence/E08-catalog-discovery-pricing.md) — 2026-11-12 02:28 (+03:00) |
| E09 | السلة، الدفع، الطلبات، المرتجعات، العمولات، الضريبة، الفواتير | قيد الإعداد |
| E10 | بوابات الدفع والتحقق والـ webhooks | منشور — [evidence/E10-payments-gateways.md](./evidence/E10-payments-gateways.md) — 2026-11-12 02:44 (+03:00) |
| E11 | المخزون والحجوزات ومزوّدو التنفيذ (Torod/M5AZN) والشحن | منشور — [evidence/E11-inventory-fulfillment-shipping.md](./evidence/E11-inventory-fulfillment-shipping.md) — 2026-11-12 02:43 (+03:00) |
| E12 | قنوات البيع والأسواق (Amazon/Noon/Trendyol) | منشور — [evidence/E12-sales-channels-marketplaces.md](./evidence/E12-sales-channels-marketplaces.md) — 2026-11-12 02:17 (+03:00) |
| E13 | المحاسبة: Odoo، التكاليف التشغيلية، التسويات، الضريبة، التقارير | منشور — [evidence/E13-finance-odoo-costs-settlements.md](./evidence/E13-finance-odoo-costs-settlements.md) — 2026-11-12 02:44 (+03:00) |
| E14 | مجال البائع في الـ backend: Seller API v3 ولوحة البائع | منشور — [evidence/E14-seller-vendor-backend-api-v3.md](./evidence/E14-seller-vendor-backend-api-v3.md) — 2026-11-12 02:43 (+03:00) |
| E15 | الاختبارات الآلية والـ CI وبوابات الجودة | قيد الإعداد |
| E16 | تطبيق العميل: المعمارية والإعداد وانتقال السياق | منشور — [evidence/E16-customer-app-architecture-context.md](./evidence/E16-customer-app-architecture-context.md) — 2026-11-12 02:41 (+03:00) |
| E17 | تطبيق العميل: الثيم الديناميكي وعقده مع الـ backend | منشور — [evidence/E17-customer-app-dynamic-theme.md](./evidence/E17-customer-app-dynamic-theme.md) — 2026-11-12 02:42 (+03:00) |
| E18 | تطبيق العميل: السلة والدفع والطلبات والمحفظة والولاء | قيد الإعداد |
| E19 | تطبيق البائع: المعمارية والدخول والصلاحيات وبوابة الإصدار | قيد الإعداد |
| E20 | تطبيق البائع: المنتجات والمخزون والطلبات والمالية | قيد الإعداد |
| E21 | نسب Git والـ PRs القديمة وحالة التكاملات | قيد الإعداد |
| E22 | عقد الـ API: تطبيق العميل مقابل API v1/v2 | قيد الإعداد |
| E23 | عقد الـ API: تطبيق البائع مقابل Seller API v3 | قيد الإعداد |

## 7. ما لم يُنفَّذ في هذه الجلسة (بحكم المرحلة)

- الاختبارات: **NOT RUN**. الـ builds: **NOT RUN**. الترحيلات (migrations): **NOT RUN**.
- لا تعديل على كود `lavkoo` أو `lavkooapp` أو `lavkooappseller`، ولا على أي بيئة تشغيل.
