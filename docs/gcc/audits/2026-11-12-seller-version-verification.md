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
