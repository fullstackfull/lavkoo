# Handoff, 2026-11-19 15:00 UTC: waves 5 and 6 finished and pushed, wave 7 under review

Written by the Claude session `claude/lucid-babbage-jiiim2`, which took over from `exciting-shannon` when that account's weekly agent capacity ran out. It resumed from the saved handoff of 08:00 and the resume kit, as the owner asked, and started nothing from scratch.

**Nothing is merged, deployed or rotated. No force push. Every pull request is a draft. No approval of any kind — financial, security or legal — is recorded by Claude or by CI.** The charter's standing bans still hold: no merge to `main`, no production deploy, no key rotation, no production data, no force push, no bypass of CI or of the version gate. DR-1 to DR-5 stay open risks.

Read first, in this order: this file, `docs/gcc/STATUS.md`, `docs/gcc/MASTER_IMPLEMENTATION_TRACKER.md` (generated), `docs/gcc/PR_LEDGER.md`, `docs/gcc/COLLABORATION.md`, and the previous handoff `2026-11-19-0800-final-handoff-wave5-and-account-switch-claude-exciting-shannon.md` with its resume kit.

## 1. What the tracker says now

| | MET | CODED | MISSING | of |
|---|---|---|---|---|
| published at `10fd3c6a` (the handover) | 783 | 317 | 575 | 1,675 criteria |
| `a35930fd` (audits U11 to U15 applied) | 790 | 318 | 567 | 1,675 criteria |
| `d32b97c9` (wave 5 recorded) | **795** | **320** | **560** | 1,675 criteria |

Both generated commits reproduce from `docs/gcc/tools/tracker-generator`; `check_pr_ledger.py` exits 0 at 127 of 127, and the generator reports **0 evidence failures**.

### The seven criteria that moved at `d32b97c9`

Each is backed by a CI run on the right head, read **job by job**, before the state was written.

**MISSING to MET (5)**

- **I1-07.3** — a registry lists every notification type as necessary or marketing. `NotificationTypeRegistry` classifies 68 push, 22 e-mail and 4 WhatsApp types, and a scan test fails in **both** directions (a type sent but not listed, and a listed type nothing sends).
- **F-10.2** — banner-backed cards pointing at another country's product are not delivered.
- **F-12.2** and **F-12.3** — a storefront without overrides inherits the shared identity and a change to it reaches that storefront; an override changes only its own storefront.
- **K-16.4** — orders, products, stock, withdrawals and reviews have app-side flow tests of the request and of the handling of the answer.

**MISSING to CODED (2), each with the reason it is not MET written into the criterion**

- **I1-07.2** — only **two of the four** marketing senders ask the stop. The admin push broadcast (one FCM topic plus every approved seller) and the referral push do not, so a customer of a stopped market still receives them; scoping the broadcast needs a topic per country and matching subscriptions in both Flutter apps, i.e. a release.
- **J-13.1** — `POST /api/v1/order/track-order` answers **403 to every signed-in customer**, because `RestAPI/v1/OrderController::track_order` scopes the lookup with `auth('customer')`, the website session guard that an API token never fills. The branch pins the 403 it answers today rather than claiming a route that does not answer its real caller.

## 2. Pull requests opened by this session

All drafts. All five CI runs of wave 5 were read job by job **before** any state moved.

| PR | branch | head | base | what it is |
|---|---|---|---|---|
| [lavkoo 124](https://github.com/fullstackfull/lavkoo/pull/124) | `gcc/p1-saudi-literals-guard` | `3a23dadc` | `main` | M2-19: the 58 Saudi literals in 28 files are a closed list that fails in both directions (tests only) |
| [lavkoo 125](https://github.com/fullstackfull/lavkoo/pull/125) | `gcc/p1-marketing-stop-registry` | `6b1bf942` | `gcc/p1-storefront-lifecycle` | I1-07.2, I1-07.3 |
| [lavkoo 126](https://github.com/fullstackfull/lavkoo/pull/126) | `gcc/p1-order-answer-market` | `331ee671` | `gcc/p1-storefront-lifecycle` | J-13.1 backend half (tests only) |
| [lavkoo 127](https://github.com/fullstackfull/lavkoo/pull/127) | `gcc/p1-theme-banner-folder-and-identity` | `2976ebb2` | `gcc/p1-theme-publish-per-storefront` | F-10.2, F-12.2, F-12.3 |
| [lavkoo 128](https://github.com/fullstackfull/lavkoo/pull/128) | `gcc/p1-market-wallet-loyalty-accounts` | `2629b9f1` | `gcc/p1-storefront-lifecycle` | E-01, E-02 foundation: a dormant wallet and loyalty account per market, expand only |
| [lavkoo 129](https://github.com/fullstackfull/lavkoo/pull/129) | `gcc/p1-coupon-form-money` | `7ca87a9f` | `gcc/p1-storefront-six-country-isolation` | I1-12.1, I1-12.2 — **changes two live Saudi screens, see 4** |
| [lavkoo 130](https://github.com/fullstackfull/lavkoo/pull/130) | `gcc/p1-market-account-sharing-key` | `b6723a1f` | `gcc/p1-market-wallet-loyalty-accounts` | E-02.3: the sharing key nothing reads |
| [lavkooapp 20](https://github.com/fullstackfull/lavkooapp/pull/20) | `gcc/p1-customer-order-currency` | `80069745` | `gcc/p1-customer-pins` | J-13.1 app half |
| [lavkooappseller 16](https://github.com/fullstackfull/lavkooappseller/pull/16) | `claude/seller-flow-tests` | `7538ad9b` | `main` | K-16.4 (tests and CI steps only) |

The four `claude/wip-unreviewed-*` branches the previous account saved are **untouched**: each was restored into a worktree, run, reviewed and repaired on a new `gcc/*` branch, and the original was left exactly as it was.

### CI, read job by job

| run | head | result |
|---|---|---|
| 44517678226 (PR 125) | `6b1bf942` | 22 of 23 success. Storefront 1118 tests / 6073 assertions on MySQL 8 and MariaDB 11.4.2; SQLite whole suite 7407 / 798843 on PHP 8.2 and 8.3 |
| 44522423719 (PR 126) | `331ee671` | 22 of 23. Storefront 994 / 5237 both engines; SQLite 7283 / 798007 |
| 44530335351 (PR 127) | `2976ebb2` | 22 of 23. Storefront 1299 / 5687 both engines; SQLite 7588 / 798456 |
| 44530243729 (lavkooapp 20) | `80069745` | both jobs success; 255 analyzer findings against the 255 recorded, so **no finding was added** |
| 44530418881 (lavkooappseller 16) | `7538ad9b` | `analyze-and-test` 325 passed, 1 failed; `build-android` skipped |

**Every red is accounted for, and none belongs to these branches.**

- The **deployment overlay** job is red by design on all three backend runs. Its step refuses because `firebase-messaging-sw.js`, `public/firebase-messaging-sw.js` and `robots.txt` differ between the deploy baseline `698aa1e` and the **base branch**; `git diff --name-only <base>...<head>` shows **none of the three heads touches any of them**.
- The seller run's single failure is `test/app_version_gate_test.dart: "force-update gate the shipped version clears the backend minimum, so login is reachable"`, which is red on **seller `main` too** — proved on main's own run 43229610276, job 131221083251: *141 passed, 1 failed, the same test*. PR 16 therefore adds 184 passing tests and no new red, and `git diff 83bd60f..HEAD -- test/app_version_gate_test.dart` is empty.
- `main` of `lavkoo` is itself red (run 43229669643 on `c5c04b7b`): five jobs fail on the Torod fixture-clock test whose one-line repair sits on draft PR 1 and has never been merged.

## 3. Method used, and what it caught

Every stream ran **build → two independent adversarial reviewers → repair**, in its own git worktree, with the reviewers working in throwaway worktrees of their own so they never touched the author's tree. Each fix is pinned by a test watched failing without it, and each pin is proved by a mutation.

What that found, which a single pass would not have:

- A guard that **failed open**: `bannersNotOfferedByTheStorefront` wrapped its read in `safely()`, and an empty "leave out" list means leave nothing out — so a read that threw delivered every banner, another country's included.
- `ThemeManager::activate`, handed a `Theme` loaded while it was still active, left **both** of the default storefront's themes with `is_active = 0`, so the storefront had no active theme and other storefronts inherited nothing.
- A **session leak**: `StorefrontCurrency::within()` restored the market and nothing else, so after one view of a Kuwaiti coupon's edit page `localToDefaultCurrency(100)` answered **325.73 instead of 26.67** for the rest of the session.
- A customer-app fix that had replaced a wrong currency mark with a **plausible wrong amount** (a 12.50 SAR order written as "SR47" from Kuwait).
- Money of two storefronts **summed into one unmarked figure** in the cart-marketing list and in the admin coupon list.
- A reviewer's suggested locking fix that was **wrong**: implemented, it deadlocked on the ordinary path, because an ignored INSERT takes a shared lock that two writers deadlock upgrading. The account is now opened outside the transaction instead.

Claims were also taken back where they could not be proved: the whole-suite count of one branch was corrected, a commit message's "925 call sites in 190 files" was removed rather than replaced with a third figure, and a reviewer's request to align a list figure with a form figure was **refuted with the measurement** that showed it only moved the inconsistency onto a live Saudi screen.

## 4. What needs the owner, and must not be merged before it

1. **PR 129 changes two live Saudi screens with every switch off.** The admin coupon edit form and the vendor coupon update form now show the amount that is **stored** (4.35 where they showed 4.34, 10.505 where they showed 10.504). It is a repair of a defect that took a minor unit off a coupon on every untouched **Update**, not a new rounding rule — a stored 2.675 at two decimals is still cut to 2.67 — but it is a user-facing change and needs explicit sign-off.
2. **PR 129 rewrote four of its own commit SHAs.** Three sentences of the reviewed commit message were overclaims the reviewers named; the message was reworded by a rebase over that branch's own commits. **Every tree is byte-identical** (tip tree `85631962…` before and after), the old SHAs are still reachable, nothing was pushed before this and nothing was force-pushed. Say the word and the reviewed SHAs go back with the corrections in a follow-up commit instead.
3. **PR 128 needs a design decision before E-07 can backfill Saudi Arabia.** On a platform running the multi-currency model, no Saudi market account can be opened, because the currency a visitor sees there is their own choice and the balance is in none of them. The code refuses rather than guesses. If Saudi accounts should open in the platform's own currency regardless, that belongs in `DECISIONS.md`.
4. **Three defects are pinned and deliberately not fixed**, because fixing each changes what a live screen or a live route does: `auth('customer')` in the v1 track-order route (403 to every signed-in customer); `SegmentResolver::total_spent` summing `order_amount` across the currencies orders were taken in, into one unitless number an admin segment rule compares against a threshold; and the coupon **quick view** and **export** still printing another storefront's figure when a storefront's currency is switched off.
5. **Twelve seller-app defects are pinned and none is fixed** (PR 16). Two are money or a crash: a refused withdrawal leaves the typed values in `inputValueList`, so correcting an account number re-sends the old one; and `updateBalance` throws `NoSuchMethodError` on a status `ApiErrorHandler` does not name. A characterisation proves what the app does, not that it is right.
6. **I1-07.1 is unreachable as written** and needs the owner, not more tests: it asks for a due-payment push and three e-mails the application never sends at all (`order_edit_due_payment_message` has no entry in `PushNotificationTrait::pushNotificationMessage`).

## 5. In flight when this was written

- **CI on PRs 128, 129 and 130** (runs 44545336093, 44546598026, 44546747284) had not finished. Their criteria are **not** recorded in the tracker yet and no state moved for them. Read them job by job first.
- **Wave 7** was running in worktrees, four streams, nothing pushed: **E-08** (deleting an account must not forget money — a guard behind a switch that ships off, because refusing a deletion a customer can make today is a live change), **D2-04** (one payment gateway registry with no key that disagrees with itself, across the six hand-kept lists including `app/Traits/Payment.txt`, which `AddonService::deleteAddon` copies over `Payment.php`), **I2-06** (a test that fails if a `PolicyRegistry` key is named for a legal document type) and **K-18** (the seller app's shop image rules stop reading the one global `active_theme`).
- The whole-suite line quoted in PR 128's evidence section is the build agent's own measured run; this session's attempts to re-measure it locally were killed by the machine before finishing, so **CI is the authority** and the PR says so.

## 6. How to carry on

The generator is the only way to change the tracker: edit `docs/gcc/tools/tracker-generator/gen_main.py`, add a block before `def main():`, then

```bash
cd docs/gcc/tools/tracker-generator
export GCC_REPOS_ROOT=/home/user
python3 gen_main.py ../.. <date>
python3 gen_pr_ledger.py ../.. <date>
python3 ../check_pr_ledger.py        # must exit 0
python3 batch_measure.py <repo-root> origin/claude/exciting-shannon-4mh2ok
```

The index of a criterion is its **position in that requirement's list in the generated card**, counting from 0; check it against the card after you regenerate, because it has been wrong before. A criterion is MET only with a named test or job run on that head and read job by job; a pull request body or a local run is CODED at best. Add a new pull request to `data/context.json` and to `gen_pr_ledger.py`'s `EXTRA` or the ledger check fails.

The workflow scripts of this session are in the scratch space and were written from the resume kit's own; the pattern is one stream per criterion set, `pipeline(STREAMS, build, two reviews in parallel, repair)`. One warning worth keeping: a JavaScript array literal built by joining bodies that already end in `,` produces `},,` and therefore a **hole**, which `pipeline` throws on; detect it with `if (!(i in items))`, never with `filter(x => x === undefined)`, which skips holes.
