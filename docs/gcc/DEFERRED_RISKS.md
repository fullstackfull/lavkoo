# Deferred risk register

Sanitized: nothing here is a secret or a way to use a flaw. These are risks the owner has decided not to act on yet. They are **open**: not remediated and not considered safe. They do not stop independent development, and they are not raised again at every batch. Rotating or replacing a key or a secret, and changing a repository setting, need the owner's separate authorization; this register records that none is given.

Last updated 2026-11-18.

| ID | Risk | Owner decision | Status | Where it is described |
|---|---|---|---|---|
| DR-1 | Credentials and key material that are tracked in the repository (key files, a licence record) | 2026-11-18: no rotation, revocation or replacement now, for the Apple key, the application key, the payment keys, Firebase or any other credential | Deferred. Open. Not considered safe | `docs/integrations/security-findings.md` (SEC-15); plan decisions 11 and 15 |
| DR-2 | The application key on the live server may be the example key | Same decision (no change). The read-only comparison is section E of [`SERVER_PREFLIGHT.md`](SERVER_PREFLIGHT.md), for the authorized administrator | Deferred. Open | plan, server preflight |
| DR-3 | The three repositories are public | Not decided | Open | plan decision 15 |
| DR-4 | `main` has no branch protection in the three repositories | Not decided. The exact rule, for a person to apply, is `docs/gcc/BRANCH_PROTECTION.md` with `.github/CODEOWNERS` (draft PR 91, documents only; nothing is applied and the real state is unverified) | Open | owner guide, section 7; `BRANCH_PROTECTION.md` in draft PR 91 |
| DR-5 | A private repository would cost CI minutes: one full CI run is about 460 runner-minutes | Information for DR-3 | Open | plan decision 15 |

What this means for the work: nothing in the release plan treats these as handled; the staged hotfix does not rotate anything; no document or pull request claims the system is secure while they stay open; and development, tests and draft pull requests continue on the isolated branches.
