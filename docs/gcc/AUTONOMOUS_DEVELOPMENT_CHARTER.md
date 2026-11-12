# LAVKOO GCC — Standing Autonomous Development Authorization

**Owner instruction date:** 2026-11-12 (Asia/Kuwait)  
**Status:** APPROVED — continuing delegation for development in all three repositories.  
**Priority:** This later, owner-issued charter **supersedes previous "DISCOVERY ONLY", test-only, one-PR-only and repeated owner-approval requirements for development work on isolated feature branches**, to the extent they conflict. It **does not** override the approved business decisions, security boundaries, production safeguards, or explicit restrictions on merging/deployment.

## 1. Intent — stop asking for every ordinary coding decision

The owner has confirmed: "ما بدي كل شوي موافقه وكل شوي قرار. الخطة موجودة والسكربت موجود. بدي يشتغل اللي بشوفه مناسب ويتخذ القرار فورا."

**Interpretation:** Execute the existing approved LAVKOO GCC development plan proactively and continuously. Select safe technical solutions; fix issues, write runtime code, tests, CI, UI, Flutter and Laravel changes **without asking for a fresh owner approval at each step**. Do not keep generating discovery reports instead of coding. Make reasonable choices, record them succinctly, commit/push immediately **after reviewing each meaningful checkpoint**, open PRs, and move on to the next independent task while awaiting review.

If a business choice was already approved in `docs/gcc/DECISIONS.md` or `GCC-20-SUPER-ADMIN-MOBILE.md`, implement it as agreed. Do not reopen it because of a minor coding ambiguity. When undecided technical options are all safe and reversible, pick the simplest one that preserves the Saudi baseline, state the rationale, and proceed.

## 2. Repositories and canonical documents

- `fullstackfull/lavkoo`: Laravel backend, storefront, admin, APIs, canonical project plan and this charter.
- `fullstackfull/lavkooapp`: Flutter customer app.
- `fullstackfull/lavkooappseller`: Flutter seller app, including future distinct platform-owner Super Admin experience (GCC-20).

Read current `main` of all three before each new workstream. Also read `docs/gcc/README.md`, `DECISIONS.md`, `GCC-20-SUPER-ADMIN-MOBILE.md`, `COLLABORATION.md`, relevant Discovery evidence and recent handoffs. Existing code/production assumptions must be verified, not guessed.

**Non-negotiable business foundation:** six storefronts SA/AE/KW/QA/BH/OM; Saudi first default; **country-independent product records and SKUs**, stock, prices, merchant/legal entity, payments and ledgers; globally shared category taxonomy, brands and base attribute definitions; localized overrides and web/app layouts; one selected fulfillment source per product/store; scoped wallet/loyalty; security-scoped staff and seller access; no automatic catalog FX; provisional single-store/single-legal-entity checkout pending compatibility audit; GCC-20 platform Super Admin is **not** a seller token with wider permissions.

Maintain the current Saudi experience and all legacy IDs, contracts, data and fulfillments until tested equivalent. `shop_id` and `seller_id` do NOT mean a country. Never design central shared SKUs/products that contradict the approved independent country catalogs.

## 3. Broad permissions — no repeated consent required

This charter authorizes autonomous **development in isolated branches**, including:

1. Read/analyze existing code and completed Discovery evidence across the three repositories; inspect CI logs with secret redaction.
2. Implement/refactor/repair Laravel backend and web, admin UI, customer Flutter and seller Flutter application/runtime code, APIs, validation, auth, theme rendering, country-scoping, catalog, workflows and GCC-20 — in small reviewable increments and behind default-OFF feature flags for new GCC behavior where feasible.
3. Add/modify test code, fixtures, CI workflow and isolated developer tooling; fix genuine production-source defects **in feature branches** with a regression test and clear evidence, without needing a separate owner decision for each fix.
4. **Write additive/expand-first migration files** as proposed code and exercise them only on disposable, demonstrably isolated test databases; never run migrations on live data or assume the actual Saudi production schema is reconstructed from migrations. Require rollback/failure checks.
5. Build/compile/run isolated automated tests and static analysis in disposable environments with fake/mocked providers and no live financial/shipping/customer interactions. Investigate failing CI and repair root causes rather than disabling checks.
6. Improve translation/UI copy, including the already approved coupon rule **eligibleSubtotal >= min_purchase**. Actual `OrderManager` check already allows equality; preserve it. Distinguish it from the unrelated rule comparing minimum purchase to discount amount. Stop tests from writing into tracked `resources/lang`.
7. Create unique feature branches, atomic commits, **push immediately after each reviewed checkpoint**, and open/update draft PRs targeting `main`. Progress on independent workstreams should continue rather than waiting for review of every PR.
8. Perform source-based technical decisions (class/route design, isolated tests, safe defaults, modularity, indexing strategy for proposed migrations, code ownership boundaries), recording significant tradeoffs in PR text; no individual permission prompts.

**This approval is standing, not a one-commit exception.** It applies across the full approved GCC architecture and the three repositories, even where earlier docs restrict code changes to DISCOVERY or P0 test-only. However, implementing a feature in an isolated branch does not imply that it may be merged or released.

## 4. Safety boundaries — no autonomous production or irreversible action

**Still NOT authorized without a separate explicit written owner approval:**
- Merge PRs or push code directly to `main`; alter branch protection, auto-merge, force push, rebase/reset destructive shared history.
- Deployment, production configuration changes, real app-store releases, live DNS/domain/routing changes, production cron/queue/integration activation, changing real payment/shipping accounts or making real payment/fulfillment requests.
- Production server/database access or export/backup/restore/copy, including read-only SELECT, unless separately approved with named operator and scope. Do not fetch credentials or sensitive logs.
- Running migrations/backfills against real environments or using a KSA-derived data copy until its origin, isolation, sanitization and authorization are established. A **test-only ephemeral schema** is allowed, using fixtures and mock data.
- Destructive data migrations, deleting financial records, changing live wallet balances, moving money between legal entities, or approving irreversible financial settlement changes.
- Rotating real credentials, exploiting security vulnerabilities, publishing restricted annexes, changing secret keys, removing history via force push. The known `SEC-E21-04` exposure still requires urgent authorized credential revocation/rotation; `SEC-E05-03` and GCC-20 privileged auth require competent security review **before merge/release**.
- Circumventing RBAC, authentication, version gates, legal rules, secret scanning or test checks to get a green build. No seller app version bump or lowering backend minimum based only on a hardcoded test constant; determine actual release/gate from trustworthy evidence first.

**Not a blocker to all coding:** If production schema, merchant contracts or live release versions are unknown, record assumptions and build other non-dependent components using safe mocks and feature flags; label the dependent PR as NOT RELEASE READY. Do not keep requesting approval or restart Discovery in a loop.

## 5. G0, testing and merge gates

- **G0 remains OPEN** until documented closure. The owner authorizes coding *before* G0 on isolated feature branches but **does not authorize merge/release** of GCC implementation phases 1–17 before G0 and appropriate review.
- Seller app version/gate disagreement and current CI failures remain blockers to *release*, not blockers to independent coding/tests.
- Any changes involving financial settlement, cross-company legal obligations, payment providers, wallets, data migration, elevated admin security, or seller session tokens must be covered by strong tests, explicit risk assessment and a security/financial-owner review **before merging or production release**. Claude may code these on a feature branch; it may not declare them safe for release without evidence.
- Keep old Saudi routes/APIs operational. Avoid one-shot rewrites or breaking API contracts. Plan additive releases, backward-compatibility, dual-read/controlled-cutover only when separately authorized.
- Use CI status from actual head SHAs. If CI fails, try to fix the problem in the branch; if it requires unavailable secret/production access, flag it and continue another safe task.

## 6. Continuous workflow for multiple Claude accounts

At every session and after each checkpoint:

1. Fetch current remote HEADs; read this charter, approved GCC decisions, relevant open PRs, branch/handoff state. Check for conflicting agent-owned worktrees.
2. Select next highest-priority **unblocked development task**, not another broad research report: stabilize baseline Saudi tests/CI; protect checkout/payment; isolate country context/cache/permissions behind flags; build country-specific product/catalog, inventory, themes; customer Flutter; seller admin/mobile GCC-20; payment/legal/ledger workflows; etc. Choose safe dependency order.
3. Work on a **single small workstream branch** per repo (never reuse another Claude's active branch without coordination). Use stacked branches/PRs with explicit parents if blocked by an earlier unmerged feature, but prefer work that can branch from current `main`.
4. Make changes; run safe isolated tests; self-review ALL diff including secrets, PII, unexpected modified files, test reliability, old Saudi behavior and API compatibility. If review fails, fix before any public push.
5. After quality gate passes: atomic commit → **push immediately to GitHub** → verify remote SHA and file diff → open/update draft PR, referencing exact baseline, tests, remaining risks and this charter.
6. **DO NOT STOP** just because one PR needs review or CI is still running. Continue the next independent approved development task; check pending CI when useful. Never invent CI results or claim tests green while pending.
7. Write a short sanitized progress/handoff on GitHub: completed, PR links, SHAs, tests, blockers, next task. Another Claude account must be able to resume using GitHub alone.
8. If a high-impact restricted step is unavoidable, do not perform *that step*, but keep working on alternative safe tasks. Ask the owner only for the **specific, truly irreversible/production decision**, never for routine coding.

## 7. What counts as DONE

Not a document. A workstream must deliver **working code + relevant tests + successful isolated verification (where feasible) + remote GitHub commit + draft PR + acceptance criteria**. If test runs are blocked, explain why and never claim success. A proposed merge must state G0, CI, security, financial, privacy and deployment gates. Deployments stay separately approved.

## 8. No silent conflict with earlier instructions

This is an **explicit later owner-issued standing development delegation**. Earlier text requiring:
- "DISCOVERY ONLY",
- "P0 tests only",
- "new owner decision for every production-source fix",
- "stop after one PR" or "no runtime code before G0"

is **superseded for isolated branch coding and testing**. Restrictions about **production**, **merge**, **secrets**, **destructive changes**, **isolated data**, **privacy**, and **no-force-push** are NOT superseded.

If another agent cannot read this file due to an older local branch, it must fetch canonical main and act under this later approval. If an older `CLAUDE.md` contradicts this charter, prefer this explicit later owner approval **only within the scope stated here**.

**Owner's expectation:** Code and ship reviewed checkpoints to GitHub continuously; make reasonable technical decisions without repeatedly seeking permission. GitHub feature branch push ≠ merge or production release.
