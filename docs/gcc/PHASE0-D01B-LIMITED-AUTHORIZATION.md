# LAVKOO GCC — D-01(B) Owner Authorization & Limited Phase 0 Task

**Owner approval date:** 2026-11-12 (+03:00)  
**Approval:** The owner explicitly chose **D-01 option B** from Discovery-02's `OWNER_DECISION_PACK` (`04-owner-questions-q01-q50.md`).  
**Authorized only:** `P0-A` + `P0-C` + `P0-E` **for read-only inspection and documentation using authorized isolated copies**, subject to the safety boundaries below.  
**Not approved:** Any application code change, migrations, data writes, tests, CI execution, production access/change, security repair, secrets rotation, force push or merge.

This approval satisfies the **written scope decision C1** for **this limited subset only**. It **does not** close `P0-A`, `P0-C`, `P0-E`, `G0` or any other gate; it does **not** authorize GCC implementation phases 1–17 or GCC-20 implementation.

## 0. Source of truth and concurrent changes

Read:
- `docs/gcc/DECISIONS.md`, `COLLABORATION.md`, `STATUS.md`
- Discovery-01 reports 06, 07, 10, 11, 12 and evidence E01, E03, E15, E21.
- Discovery-02 `docs/gcc/audits/2026-11-12-1240-discovery-02-followup/` reports 02, 03/03a/03b, 04, 06 and their handoff.
- The current `CLAUDE.md` in each repository.

Canonical repos: `fullstackfull/lavkoo`, `fullstackfull/lavkooapp`, `fullstackfull/lavkooappseller`. Historic repos for read-only lineage: `fullstackfull/pharmacyksa98`, `fullstackfull/ksaapp98`.

The owner permits **documentation-only deliverables** committed and pushed immediately to the **current audit branch**, not `main`. Before each push check current remote SHA/concurrent work; use unique report filenames; no force push, rebase, overwrite, PR merge, or application change. Report actual remote commits and links. If the audit branch cannot be pushed to, create a unique allowed documentation branch only if authorized by the GitHub connection and no branch/policy conflict; otherwise stop and report the block.

**Prior handoff** was at `lavkoo/claude/exciting-shannon-4mh2ok@218b8aead7928a59702fc35c06c568cca98a7b79`; **reverify all current branch heads** before reporting.

## 1. Strict authorization boundaries

### Allowed
- Read repository files, PR metadata, Git commits/ancestry, migration file names, current CI metadata/results, manifests, current documentation.
- **P0-A:** Create a verified lineage and source-of-truth map (including legacy Git where access exists), without merging or changing any branches/code.
- **P0-C:** Inspect existing **already-prepared, explicitly authorized isolated/restored schema-only copies** or already-approved **schema-only metadata**; document schema facts, data ownership, unknowns and reproducibility gaps. Use **read-only access only**.
- **P0-E:** Inspect **already-prepared authorized isolated/restored/anonymized copies** for structural and non-sensitive operational metadata (counts, database engine/version, configuration state flags, no personal data); document M-01–M-16 as VERIFIED/UNKNOWN with provenance and age of the copy.
- Publish **only sanitized Markdown reports and unique handoffs** to the audit branch immediately after each meaningful checkpoint. Keep raw DDL, production connection metadata, sensitive configuration, data extracts and restricted annexes outside public GitHub in an approved restricted location.

### Forbidden
- Reading or connecting to **live production**, even with SELECT-only queries, without an additional **separate explicit written approval**; **this task grants no production access**.
- Creating/restoring/copying/exporting backups or dumps **from production**; requiring the owner/operator to provide pre-existing isolated material where available.
- Creating, modifying, seeding, running migrations against **any** database (including test/staging), changing schemas, or writing rows.
- Executing tests, CI workflows, builds, installs, deployments, scripts with side effects, payment/shipping/provider requests, queue jobs or sync commands.
- Editing Laravel/PHP/Dart code, CI, workflows, tests, `.env`, configs or files outside the explicitly permitted `docs/gcc/` audit-report paths.
- Reading, collecting, sharing or publishing **secret values**, access tokens, customer/merchant PII, production dumps, restricted vulnerability annexes or details that enable exploitation.
- Fixing SEC-E21-04 or SEC-E05-03, credential rotation, removals, destructive history cleanup/force-push, or changes to production. Those need **separate security owner authorization**.
- Treating D-01 option B as permission for `P0-B`, `P0-D`, `P0-F`, `P0-G`, `G0`, GCC-20 implementation, or any Phase 1–17.

If isolated access or schema evidence is not ready, mark the relevant item **BLOCKED/UNKNOWN** and request the **minimum non-sensitive, pre-existing, authorized** artifact, rather than reading production, creating a fresh backup or guessing.

## 2. Authorized workstream P0-A — Git lineage and authoritative baseline

1. Record exact `main` and audit-branch HEAD SHAs for all reachable repositories, including timestamps and access limitations.
2. Read current and legacy backend PR histories #9–#18 and relevant old/new Flutter histories where accessible. Validate states via GitHub PR metadata; independently compare commit hashes/ancestry and differences.
3. Produce a table: historical repo/PR, head/merge commits, whether included in `lavkoo/main`, whether included in client/seller code, uncertainty and corroborating evidence. **PR merged != in production.**
4. Revisit Q-30 from evidence: distinguish **access newly available** from **lineage fully verified**. Neither status auto-closes G0.
5. Confirm mismatch N6 and propose **errata text only**, without altering approved `DECISIONS.md`, `STATUS.md` or `docs/sales-channels/final-report.md`.
6. Record whether production HEAD and migration table are unavailable. Do not claim P0-A complete if production lineage cannot be verified.

**Deliverable:** `01-p0a-git-lineage.md` with verified evidence and a closure checklist.

## 3. Authorized workstream P0-C — Schema/DDL baseline, read-only

1. Inventory existing migrations and documented SQL schema references from repository **without running** migrations.
2. If the owner/operator already provided an isolated **schema-only** restore or a restricted schema catalog, perform metadata **read-only** inspection. Confirm engine/version, tables, keys, foreign keys, types and non-nullability only where access is explicitly authorized.
3. Reconcile the divergent earlier model/table count estimates and identify migration-only assumptions. Distinguish a static file model from actual isolated DDL; do not infer production equivalence.
4. Prepare a table of missing schema prerequisites and exact tests that would be needed under a later authorization.
5. **Do not apply migrations to MySQL 8 or MariaDB 11.4.2, even on isolated copies in this task**; those are deferred because current authorization is inspection/documentation only.
6. Store **only sanitized summaries** on GitHub. Full DDL and any connection details must stay in an owner-approved restricted storage location.

**Deliverable:** `02-p0c-schema-baseline.md` with verified scope, UNKNOWN list and deferred acceptance criteria.

## 4. Authorized workstream P0-E — Isolated environment measurements, read-only

1. Enumerate M-01–M-16 from Discovery-01 report 07 and relevant Discovery-02 Q-27/29/35/48/49/50.
2. For each, identify what can be checked from current source/documents vs an existing authorized isolated restore; mark `VERIFIED`, `INFERRED`, `UNKNOWN` or `BLOCKED`.
3. From the isolated copy **only if authorized and available**, collect non-sensitive, aggregated counts, DB engine/version, table existence/metadata, deployment/worker/queue configuration evidence where safe. Only check existence/state of sensitive keys; **never read secret values, token lengths, or raw logs containing them**.
4. Record copy origin, date, environment designation and known differences vs production. Do not mislabel cloned/stale values as live production.
5. For money/balances/transactions, report reconciliation **methodology and aggregate discrepancy categories only**; never publish customer-level data, raw SQL exports or real financial balances in the public repo.
6. Anything requiring a live environment, full production history, backup creation, log reads containing PII or new machine access becomes `BLOCKED` pending separately scoped approval.

**Deliverable:** `03-p0e-isolated-measurements.md` including evidence freshness/coverage and follow-up access requests.

## 5. Exclusions and independent tracks

- **P0-B:** Writing or running Saudi regression tests/CI is deferred.
- **P0-D:** Modifying leak guards, scanning protected secrets or remediating exposed values is deferred; emergency credential invalidation/rotation by the authorized owner remains urgent, but **not granted to this Claude task**.
- **P0-F/G:** Owner decision closure and security fixes are not implied by this narrow approval.
- **GCC-20:** Only cross-reference existing Discovery-02 security findings; no mobile admin authentication implementation, API creation, token changes or seller app version-gate fixes.
- **Seller CI/version:** Read existing documented findings if needed for dependencies, but do not run or patch CI/Flutter or choose a production version.
- **Force push:** explicitly not approved. Never rewrite history.
- **Errata:** submit proposed patches as quoted documentation suggestions only, without editing canonical documents.
- **No automatic G0 or Go/No-Go change.** A separate signed owner decision is required after review.

## 6. Documentation files and immediate GitHub handoff

Write to a **new uniquely named directory**:
`docs/gcc/audits/YYYY-MM-DD-HHMM-phase0-d01b-limited/`

Include:
- `README.md`: the scope, branch SHAs, sources, access and status.
- `01-p0a-git-lineage.md`.
- `02-p0c-schema-baseline.md`.
- `03-p0e-isolated-measurements.md`.
- `04-gates-blockers-next-approvals.md`: which acceptance criteria were verifiable, what remains blocked and exact additional authorizations needed.

Create a timestamped `docs/gcc/handoffs/YYYY-MM-DD-HHMM-phase0-d01b-<session>.md` noting all report links, actual remote commit SHA, no tests/code/production changes, open security items and the exact next step.

**After each document checkpoint:** commit and push to a remote **documentation/audit branch** immediately; verify remote SHA and provide the link. Never force push. Check for concurrent sessions before updating any shared file.

## 7. Stop condition and required final response

After P0-A/P0-C/P0-E have a documented evidence table or an explicit BLOCKED list:
- Verify **no application code, database or production state was changed**.
- Provide precise GitHub links/SHAs and a short human-readable Arabic report.
- State clearly which items remain UNKNOWN; don't claim G0 completed.
- End with a list of the **specific additional approvals** needed from the owner for later actions.
- **Stop.** Await explicit owner authorization for any broader Phase 0 item, tests, migrations, security remediation, canonical errata or implementation.

**OWNER AUTHORIZATION SUMMARY:** D-01(B) only — read + document P0-A/P0-C/P0-E on explicitly authorized isolated copies; public GitHub receives sanitized documentation only; no application code or production changes.
