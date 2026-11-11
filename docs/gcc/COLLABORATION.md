# Lavkoo GCC — Multi-Claude Collaboration & Immediate GitHub Publishing

## Goal

Several Claude accounts/sessions may work on this system. **GitHub is the only durable shared source of truth**, not any individual chat or local machine state. Read docs before acting and write handoffs that another account can resume.

## Canonical repos

- `fullstackfull/lavkoo`: canonical GCC documentation and backend.
- `fullstackfull/lavkooapp`: Flutter customer.
- `fullstackfull/lavkooappseller`: Flutter seller.

Use `docs/gcc/` in backend for canonical plan and global status. In app repos, use their root `GCC_PROJECT.md` pointer. Repositories may have different branch histories. Do not assume old naming implies that PRs were transferred.

## Beginning of EVERY Claude session

1. Identify which repository and branch you have; read `git status` and HEAD SHA without changing local modifications.
2. Fetch/read current GitHub documentation in `fullstackfull/lavkoo/docs/gcc/`, including decisions, status, audit prompt and latest handoffs.
3. Check recently merged/open PRs/issues in each relevant repo, plus the old legacy repo where appropriate.
4. State the scope of your session, its phase (AUDIT or approved IMPLEMENTATION), and existing conflicts before making changes.
5. Do not work on the same file/workstream as another active agent without explicit coordination.
6. Never auto-merge, rebase, force-push, reset, modify production, run migrations, or enable live external integrations.

## Immediate push policy (important)

**After each meaningful documentation/report or approved-code checkpoint:**

1. Record exactly what changed and why; use a small atomic commit.
2. Push **immediately** to the corresponding GitHub remote branch (not merely a local commit).
3. Verify remote branch/commit SHA, and mention repository, branch, commit SHA, and GitHub link in your response.
4. Don't claim an upload succeeded until GitHub confirms the remote commit.
5. If push fails, report the exact blocker and keep work recoverable; do not say it was saved remotely.

In Discovery, the only permitted writes are documentation and handoff files for the audit and owner-approved planning records. This is a narrowly scoped **documentation exception** to read-only analysis; no application-source changes.

For future implementation use small distinct workstream branches/PRs, e.g. `gcc/core-store-context`, `gcc/mobile-customer`, `gcc/mobile-seller`. Never push feature commits straight to main unless the owner specifically authorizes that workflow. Documentation-only commits may go to canonical docs main per owner instruction; check concurrent edits.

## Prevent lost work and collisions

- One agent/workstream writes to its own report/handoff filename.
- Fetch current remote state before making updates to shared `STATUS.md` or `DECISIONS.md`.
- Avoid overwriting other agents' unmerged changes; do not use force flags.
- Record any unavailable repository, unsupported tool and uncertainty honestly.
- Record base SHA, affected branches/PRs, completed work, pending work, tests (executed/not executed), known risks, and exact next step.
- Audit code only, do not paste credentials, PII, raw `.env` values, tokens, wallet/customer exports or production dumps into docs or handoffs.
- Cross-app API changes require coordination and version compatibility checks. Seller app is a distinct third repo.

## Handoff template

```md
# GCC Handoff — YYYY-MM-DD HH:mm +03:00
Agent/session: <identifier>
Phase: AUDIT / IMPLEMENTATION (explicitly approved?)
Repositories and base SHAs:
Task/workstream:
Decisions referenced: GCC-XX
Completed:
Findings with file paths/commit evidence:
Files changed (documentation only in audit):
Tests: NOT RUN (audit) / approved test results
Current risks and PR dependencies:
Remote commits and URLs:
Next action:
Explicit approvals still required:
```

## Scope / approvals

**AUDIT**: may read code, branches, diffs, docs, public repo metadata. May create/push *docs and handoffs only*. No modification to application source/database/prod, no integration calls, no running dangerous tests.

**IMPLEMENTATION**: only if owner explicitly authorizes a bounded phase. Prefer isolated branch + PR; immediately push checkpoints, preserve existing systems, and pause at approval gates.

**PRODUCTION**: requires separate explicit approval. A pushed commit or merged PR is *not* permission to deploy.

## Shared project resume instruction

"Open `fullstackfull/lavkoo/docs/gcc/README.md`, read the decisions/status/collaboration rules and last handoffs, check current repo/PR SHAs, then continue only the currently approved phase. Save every deliverable to GitHub and push immediately. Never assume a previous Claude chat is authoritative over the repository."
