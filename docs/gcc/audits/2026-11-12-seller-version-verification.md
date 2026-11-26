# Seller app verification — version and force-update gate (read-only)

> Scope: `fullstackfull/lavkooappseller`, read-only. Approval: `docs/gcc/PHASE0-COUPON-GTE-SELLER-VERSION-OWNER-APPROVAL.md` section 1, "Seller app: verify real release and force-update gate first". Nothing was changed: no version bump, no test change, no backend setting, no release.
> Baseline read: `origin/main` = `d556fb4f` (2026-11-12, after the approval, which referenced `9ea6423`).

## 1. Facts verified from the repository

| Item | Value | Source |
|---|---|---|
| Declared app version on `main` | `1.0.0+3` | `pubspec.yaml` line 8 (`git show origin/main:pubspec.yaml`) |
| Hard-coded minimum in the gate test | `2.0.1` | `test/app_version_gate_test.dart` line 17 |
| Gate test name | `force-update gate the shipped version clears the backend minimum, so login is reachable` | same file |
| Git tags on the remote | none | `git ls-remote --tags origin` returned no tags |
| iOS version fields | `$(MARKETING_VERSION)` and `$(CURRENT_PROJECT_VERSION)`, filled from build settings outside the text read | `ios/Runner/Info.plist` |
| Android `versionCode` / `versionName` | not determinable from the text read | `android/app/build.gradle` |

## 2. What the repository cannot establish (UNKNOWN)

- **Live backend minimum** (`seller_app_version_control` in Admin → Business Settings → App Settings). The repository does not hold the live value, and this session has no production access. The test constant is not evidence of it, as the approval states.
- **Published versions and build numbers** on Google Play and the App Store, and the rollout percentage. No store record is available here, and git tags are absent.
- **Whether `1.0.0+3` is a shipped build.** The repository does not say.

## 3. What the gate does (consequence, not a recommendation to act)

The force-update gate blocks the login screen when the installed version is below the backend minimum. If the live minimum is `2.0.1` and sellers run `1.0.0+3`, every seller is locked out. That is why the test exists. If the live minimum is lower, the test constant is simply wrong. Either way the decision needs the live value, so it cannot be made from the repository.

Current CI on `main`: run `43137204619` on `9ea6423` failed in the `Test` step with 141 tests passing and `app_version_gate_test.dart` failing. Run `43229610276` on `d556fb4f` was queued at the time of this read.

## 4. Options for the owner (none taken)

- **A.** Obtain the live minimum from an owner or operator statement or screenshot. If it is at or below `1.0.0`, correct the test constant with that evidence.
- **B.** Release a build at or above the live minimum and set the pubspec version to match. This is a store release and needs a separate owner decision.
- **C.** Lower the backend minimum. This is a production setting and needs a separate owner decision.

## 5. Recommendation

Make no code or setting change until the owner provides, as a sanitized statement or screenshot: (1) the live `seller_app_version_control` value, (2) the published Play and App Store versions and build numbers with rollout state, and (3) whether `1.0.0+3` has shipped. Then choose A, B or C on that evidence. Until then the seller red test stays red, and that remains a release blocker, not a coding blocker.

## 6. Explicitly not done

No `pubspec.yaml` change. No change to `test/app_version_gate_test.dart`. No backend setting change. No store, release or build action. No version choice from a historical value.

---

## Addendum, 2026-11-26 — what the repositories say now, and the one criterion that is now proven

Read-only. **Nothing in this addendum changes a version, a minimum, a gate, a store setting or a test constant**, and the owner's three UNKNOWNs in section 2 are still unknown.

### A. The shipped version is no longer `1.0.0+3`, and sections 1 and 3 above are stale

`fullstackfull/lavkooappseller` `pubspec.yaml` reads **`version: 2.0.1+4`**, and has since
[`40178cb`](https://github.com/fullstackfull/lavkooappseller/commit/40178cb2368aba7ed286fc09eb94317e5e7120ef) of **2026-11-23**
(`build(seller): the shipped version meets the backend's published minimum`). That commit is on `main` and on `gcc/development`; this branch's base
`68a5d59` carries it. It changed `pubspec.yaml` and nothing else -- no package id, no bundle id, no Firebase setting -- and it did **not** lower
`kBackendPublishedMinimumVersion`, which is still `2.0.1`.

What the gate test therefore now compares: `_pubspecVersion()` takes the `2.0.1` before the `+`, and
`AppVersion.compare('2.0.1', '2.0.1')` is `0`, which satisfies `greaterThanOrEqualTo(0)`. So the test that section 3 records as red is expected
to pass on this head. **It has not been run for this addendum: there is no Flutter toolchain in the container this was written in, so the seller
CI is the verifier and nothing here claims a green run.**

The K-14 card's "(app_version_gate_test red: 1.0.0+3 against a pinned 2.0.1)" is out of date on both halves.

### B. The question that bump leaves open, for the owner

The commit states as fact that "the production backend publishes 2.0.1 as the minimum seller app version". **No document in any of the three
repositories records that value**, and three things in the repositories disagree with each other about it:

 - this audit, section 2, records the live minimum as UNKNOWN and says in section 5 that the test constant is not evidence of it;
 - `docs/gcc/PHASE0-B-SAUDI-REGRESSION-CI-AUTHORIZATION.md` line 30 says the actual production minimum is UNKNOWN and "do not guess or blindly
   bump/lower versions";
 - the gate test's own docblock says the backend "still required `14.1`" during an earlier episode, which is a third number.

So the bump reads as option **A** of section 4 taken with the test constant standing in for the owner's evidence, which is the one substitution
section 5 rules out. It is recorded here and **not reverted**: reverting it would itself be a seller version change, which needs the same evidence
and the same authorization.

**Owner question.** Which is true of `seller_app_version_control` on the production backend today?

| | If the live minimum is | then |
|---|---|---|
| 1 | exactly `2.0.1` | the bump was right and this addendum's only job is to record the evidence under section 1, with a date |
| 2 | **below** `2.0.1` (`1.0.0` or lower) | both the constant and the bump overshoot. The constant is corrected with the owner's evidence; whether the pubspec goes back down is a store question, because a published build's number cannot be withdrawn |
| 3 | **above** `2.0.1` | `2.0.1+4` still locks every seller out, and the red the gate was written to show has been hidden rather than fixed |
| 4 | unset, empty or non-numeric | the gate blocks nobody (`version_helper_test`: an absent or non-numeric minimum never blocks), and the constant is asserting a requirement the backend is not making |

Nothing can distinguish these four from inside the repositories. What is needed is unchanged from section 5: the live
`seller_app_version_control` value, the published Play and App Store versions and build numbers with rollout state, and whether `2.0.1+4` (not
`1.0.0+3`) has shipped.

### C. The header-less build is the Saudi default, and that is now proven rather than asserted

K-14's criterion "an installed build that sends no `X-Storefront` is treated as the Saudi default" (GCC-19) had its answer asserted from reading
the resolver. Both halves are now covered by a test:

 - **the app sends no header** while its storefront flag is off, which is the default for every build:
   `lavkooappseller` `test/market_selector/seller_market_choice_request_headers_test.dart`, the case asserting
   `SellerMarketChoice(preferences, enabled: false).requestHeaders()` is empty. In the seller CI's flag-off run; not run here, for want of a
   toolchain;
 - **the backend serves such a request the configured default**, which is Saudi Arabia today:
   `lavkoo` `tests/Feature/Storefront/BuildThatSendsNoStorefrontHeaderTest.php` -- with storefronts ON and all six markets active, a request with
   no header is `SA`/`SAR`; so is one carrying a header the resolver cannot use (empty, spaces, `ZZ`, a country name, `null`, the header sent
   twice); the answer follows `gcc.storefronts.default_country` rather than a name in the code (A1-03); the request carries no cookie and writes no
   currency to the session; and with the switch off, header or no header, nothing changed. 10 tests / 23 assertions green on SQLite and on
   MariaDB 10.11.14. Teeth: making the fallback the second configured market instead of the default fails 8 of the 10.

This addendum does not mark any criterion met: the live minimum is still the owner's to supply, and the two app-side facts are verified by CI and
not here.
