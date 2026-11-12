# LAVKOO GCC — Addendum: KSA as the source for the isolated database baseline

**Date:** 2026-11-12 (+03:00)  
**Owner clarification:** "ياخذها من ksa نفسها" — The intended source of the GCC Phase 0 database baseline is the **existing Saudi (KSA) system/database**, rather than a newly invented schema or an unrelated sample database.

**Scope remains D-01(B): P0-A, P0-C and P0-E for read-only inspection and sanitized documentation only.** This clarification is **NOT approval to access, export, back up, copy, modify, or query live KSA production**. No phase implementation, migration, tests, CI, fixes, deployment, or main merge is authorized.

## 1. What is meant by "take it from KSA"

- Use the **actual Saudi deployed database schema lineage** as the authoritative reference **when verified**; do not treat the `pharmacyksa98` source repository, Laravel migrations, a sample SQL file, or guessed table structures as equivalent to a current production database.
- Prefer an **existing, already-authorized and isolated KSA-derived backup/restore**, with its source, extraction date, engine version, app deployment SHA, migration state, and anonymization/isolation attested by the owner/operator.
- A KSA-derived copy is a **potential source**, not a copy that has been independently verified as safe or current; do not mark B-01 closed based on this note alone.
- The historic KSA repository may be inspected for Git lineage under P0-A where access exists, but repository code itself is not a live DB copy.

## 2. Required verification of any already-existing isolated KSA copy (no production connection)

Before Claude accesses or reads any database metadata:

1. Owner/operator identifies the copy **privately**, without sharing passwords, hosts, raw SQL or PII in a public GitHub file.
2. Confirm it is **not the production system** and cannot write back to production, invoke webhooks, send messages, update payments or contact live providers; outbound integrations disabled.
3. Record the copy's source **KSA**, creation timestamp/time zone, restore timestamp, DB engine/version, whether it includes real customer/financial rows, sanitization status, known staleness, and whether schema-only or anonymized.
4. Confirm read-only credentials and explicit operator authorization for the isolated copy, and verify it is not publicly exposed. Do not infer safety from the word "test" or "backup".
5. If the copy contains unmasked customer or merchant data, **stop and mark B-01 BLOCKED** pending a separate authorized sanitization/privacy decision. This Phase 0 task does not permit data anonymization by writing to a copy.
6. Compare the isolated **schema metadata only** with 350 tracked migration PHP files as static evidence. The count of 350 refers to PHP migration files, not production tables or completed migrations.
7. For M-01 to M-16, record only supported **non-sensitive aggregate metadata**, provenance and gaps. Financial reconciliation involving real customer/transaction rows must remain BLOCKED unless a separate authorization specifies privacy safeguards and allowed outputs.
8. Store only **sanitized summaries and decision status** on the GitHub audit branch; complete DDL, backups, connection details, credentials, sensitive evidence and real amounts must remain outside the public repository.

## 3. If no isolated KSA-derived copy is already available

- Do **not** connect directly to the Saudi server/production database, request DB credentials, run `mysqldump`, export a backup, create a clone, or restore into a local environment.
- Mark P0-C (isolated DDL) and P0-E (M-01 through M-16) **BLOCKED**, and propose an **operator-run, separately authorized** plan to make a safely isolated, appropriately sanitized copy.
- The owner must separately approve **source, named operator, time window, privacy/sanitization approach, destination, permissions, data retention, and rollback / incident controls** before any production-side action. A future authorization must define exact permitted commands and boundaries.
- No-GO for application phases 1–17 and GCC-20 implementation remains.

## 4. Deliverable requested from next Claude session

Add a **new timestamped documentation-only supplement** (do not overwrite audit reports) titled "KSA-source provenance and B-01 readiness" addressing:

| Field | Status required |
|---|---|
| Intended baseline source | KSA (owner clarification; approved intent) |
| Identified safe isolated copy | VERIFIED / UNKNOWN / BLOCKED |
| Copy origin and date | Evidence only; no sensitive endpoints |
| Schema-only or sanitized | VERIFIED / UNKNOWN / BLOCKED |
| Isolation from production and provider callbacks | VERIFIED / UNKNOWN / BLOCKED |
| Independent read-only authorization | VERIFIED / UNKNOWN / BLOCKED |
| P0-C and M-01…M-16 safe evidence | List verified vs blocked; **no assumption of completion** |
| New owner/operator approvals needed | Exact bounded requests, no blanket access |

Commit and push **only approved sanitized Markdown** on the audit branch after review, verify the remote SHA. No raw backup/SQL/config values. If no copy can be verified, stop and report BLOCKED.

## 5. Status after this clarification

- **KSA is the intended database source** for the baseline.
- **B-01 has not yet been verified as satisfied** merely because the owner pointed to KSA.
- **B-02/B-05 live production reads are not authorized**.
- **P0-A/P0-C/P0-E remain open**, G0 remains open and execution remains No-Go.
- Existing open security items (SEC-E21-04 and SEC-E05-03) must be handled under independent security authorization.
