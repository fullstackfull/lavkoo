# Lavkoo GCC — Canonical Project Hub

> Formerly discussed as **PharmacyKSA GCC**. Current canonical product name: **Lavkoo**.
> Updated: 2026-11-12. Status: **Discovery / planning only; no implementation approved.**

## Repositories (current)

| System | Canonical repository | Purpose |
|---|---|---|
| Laravel website, admin, APIs | [fullstackfull/lavkoo](https://github.com/fullstackfull/lavkoo) | Canonical home of GCC planning |
| Flutter customer app | [fullstackfull/lavkooapp](https://github.com/fullstackfull/lavkooapp) | Customer-facing mobile app |
| Flutter seller app | [fullstackfull/lavkooappseller](https://github.com/fullstackfull/lavkooappseller) | Seller/vendor mobile app |

**Important:** The old repositories `fullstackfull/pharmacyksa98` and `fullstackfull/ksaapp98` remain separate GitHub resources. Old PR numbers **#9–#16** refer to the *old backend repository*, not necessarily to `lavkoo`. Review branch ancestry and file diffs before deciding what was transferred. The seller app is part of the scope; do not treat it as the customer app.

## Read in this order

1. [DECISIONS.md](./DECISIONS.md) — approved GCC scope and non-negotiable architecture.
2. [STATUS.md](./STATUS.md) — current stage, risks, pending work, and ownership.
3. [DISCOVERY_PROMPT_01.md](./DISCOVERY_PROMPT_01.md) — Claude Code audit prompt; **code read-only**.
4. [COLLABORATION.md](./COLLABORATION.md) — mandatory collaboration, handoff and immediate GitHub publishing protocol.

## Working agreement

- **Preserve → Extend → Isolate → Verify.** Do not rebuild working inventory, themes, payments, or channel integrations.
- **GitHub is the durable memory** for multiple Claude accounts: conversations are not the source of truth.
- Record audit and workstream reports in unique versioned `docs/gcc/audits/` or `docs/gcc/handoffs/` files, then commit and **push the relevant remote branch immediately after a meaningful checkpoint**.
- The **first task is discovery only**, with one exception: saving reports, status, and handoffs as documentation. No application-code or production changes without explicit permission.
- A Git push is not deployment. Never merge to `main`, deploy, run production migrations, or enable integrations without the owner's explicit approval.
- Every session must refresh from GitHub, inspect open PRs, read the canonical docs, and leave a remote-pushed handoff with links and exact commit SHAs.

Approved default first-launch storefront: **Saudi Arabia**. Countries planned from the beginning: **SA, AE, KW, QA, BH, OM**.
