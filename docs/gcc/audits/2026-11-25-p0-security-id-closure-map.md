# P0 security id closure map (2026-11-25)

Maps each P0 id of the Discovery-02 security triage to the thing on this branch that closed it, or records that nothing did.
Written to answer the one question the register could not answer before: **which of the 23 explicit P0s did a given fix stage
actually close?** Read-only on application code; no code, test or tracker file was changed to produce it, and no test suite
was run.

- Branch: `gcc/development` at `30a2283e` (map written at `30a2283e`; working tree otherwise untouched).
- Triage read from `origin/claude/exciting-shannon-4mh2ok`:
  `docs/gcc/audits/2026-11-12-1240-discovery-02-followup/01-security-triage-public.md`.
- Dossiers read from the same ref: `docs/gcc/audits/2026-11-12-0115-discovery-01/evidence/E*.md`.

## 1. The basis of this map, and its limits

**The restricted annex is unrecoverable.** Every SEC id in the public triage points at a restricted annex for its detail. That
annex does not exist. One sweep settled it: 196 refs across this checkout, 16,844 distinct paths, zero paths whose name
contains `annex`, `restricted` or the Arabic equivalent. The triage itself carries no detail for any id — all 222 of its rows
read OPEN and its table holds only id, dossier, domain category, owner action class and status.

**So every subject in the table below is RECONSTRUCTED from the Discovery-01 dossiers, not read from the annex.** The dossiers
each carry one neutral pointer line per id ("a restricted security issue — details in the unpublished restricted annex"), but
the surrounding prose often states the underlying technical fact with file and line and then attaches the id to it. That
anchoring is the whole basis here. Where the prose states no fact for an id, the row says so and invents nothing.

**What that means for a reader.** A reconstructed subject can be wrong in two ways: the dossier may anchor an id to a fact that
is only adjacent to it, and where several ids share one paragraph the prose cannot say which id is which. Both cases are marked
in the table. **No row should be treated as the annex's own wording**, and the owner's launch gate should read the state column
together with the subject column, never alone.

**How a subject was recovered.** Two independent signals, and the table says which were available:

1. **Direct anchor** — the dossier names the id in the same sentence as a technical fact with a file and line
   (for example E05 line 88, E10 line 167, E14 line 56). Strongest; treated as recovered.
2. **Domain category** — the triage's own `domain category` column for that id, used only to tell sibling ids apart when the
   dossier anchors a group of them to one path. Marked **(attribution inferred)** wherever it was load-bearing.

An id with neither reads `subject not recoverable from the dossier`.

**Commit attribution is bounded.** This checkout is shallow (410 commits; `.git/shallow` lists 8 boundaries, one of which is
`be618cf1`). Most of the security tests named below entered `gcc/development` through that boundary commit, so their
introducing commits are not in this checkout and cannot be named. Column 5 therefore names **the code and the test**, which are
present and readable, and names a commit only where one is genuinely visible. This is a real limit on the map, not a shortcut:
a reader who needs the authoring PR for an audit must fetch unshallowed history.

**Nothing here is marked CLOSED on a commit message.** Every CLOSED-WITH-TEST row was checked twice: the file and line the
dossier named was read on this branch to see what is there now, and the test that pins the behaviour was opened and its methods
counted. A fix with no test reads CODE-WITHOUT-TEST.

**No exploit detail.** Classes of weakness are described in words. No credential, token, secret, payload or
secret-shaped example string appears in this file. `php scripts/ci/leak-guard.php --base=HEAD` was run before the commit and
reported clean.

## 2. The 23 explicit P0 ids

States: **CLOSED-WITH-TEST** (subject recovered, fix on this branch, test pins it) · **CODE-WITHOUT-TEST** ·
**OPEN** (subject recovered, not closed) · **NOT-RECOVERABLE** (dossier states no technical fact for this id).

| SEC id | Cited at | Subject (reconstructed from dossier prose) | file:line the dossier names | What this branch does about it | State |
|---|---|---|---|---|---|
| SEC-E04-01 | E04:72 | subject not recoverable from the dossier — a bare pointer in the §2.2 list under the heading "security issues in these paths", which covers customer login, OTP, attempt limits, social login, referral at registration, guest merge and account deletion together. Five ids share that heading and the prose separates none of them. | none for this id (§2.2 cites many) | nothing identifiable for **this id**; see note A | NOT-RECOVERABLE |
| SEC-E04-02 | E04:73 | subject not recoverable from the dossier — as E04-01. | none for this id | see note A | NOT-RECOVERABLE |
| SEC-E04-03 | E04:74 | subject not recoverable from the dossier — as E04-01. | none for this id | see note A | NOT-RECOVERABLE |
| SEC-E04-04 | E04:75 | subject not recoverable from the dossier — as E04-01. | none for this id | see note A | NOT-RECOVERABLE |
| SEC-E04-05 | E04:76 | subject not recoverable from the dossier — as E04-01. | none for this id | see note A | NOT-RECOVERABLE |
| SEC-E04-06 | E04:199 | **Direct anchor.** Authorization of the wallet operations of an order edit: paying an edited order's due from a wallet, and returning an edited order's difference to one. | `app/Traits/OrderEditManager.php:711-737`; `app/Http/Controllers/Admin/Order/OrderController.php:247-287` | Code: `OrderEditManager` now states and enforces that only the customer who owns the order may pay its due, with the debit, the order, its edit history and the admin wallet in one transaction. Test: `tests/Feature/Api/ApiDuePaymentOwnershipTest.php`, 21 methods, incl. a posted `user` field not spending that customer's wallet, a signed-in customer refused on another's order, and a guest refused a wallet payment. | CLOSED-WITH-TEST |
| SEC-E05-01 | E05:107 | subject not recoverable from the dossier — a bare pointer in §2.4 (admin route module coverage). Of the three E05 ids in that bullet list, E05-02 and E05-03 are anchored to named facts elsewhere; no fact is left attached to E05-01. All three carry the same domain category, so the category cannot separate them. | none for this id (§2.4 is a count) | the §2.4 subject area is covered — see E05-03's row — but nothing is attributable to **this id** | NOT-RECOVERABLE |
| SEC-E05-02 | E05:88 | **Direct anchor.** No separation between holding a module and being allowed to grant permissions: staff management and role management sit under the same coarse `people` key, so whoever held that key could grant any permission, including to their own role. | `routes/admin/routes.php:658`, `:671` | Code: both groups now carry `EnsureMasterAdmin` beside `module:people` (`routes/admin/routes.php:675`, `:688`). Tests: `tests/Feature/Security/StaffManagementIsForMasterAccountsTest.php` (4 methods; every route of both controllers asked for as three people) and `tests/Feature/Security/MasterAccountInStaffManagementTest.php` (18 methods; the master account cannot be rewritten or switched off, including via an id in the body). | CLOSED-WITH-TEST |
| SEC-E05-03 | E05:315 (also :217-219, :261, :411) | **Direct anchor.** 82 admin route declarations require only a signed-in admin and carry no `module:` key (39 in the main file, 43 in the module files), so an employee whose role holds no module at all reaches them; raised to P0 because the add-on package upload routes are among them. | §2.4 count; `routes/admin/routes.php`; `Modules/{AI,Blog,TaxModule}/routes/admin` | Code: the legacy add-on group is gated (`routes/admin/routes.php:1219`, `module:themes_and_addons`). Tests: `tests/Feature/Security/AdminRouteTableTest.php` walks the booted route table and fails for any new ungated admin route unless it is listed with a reason; `AdminRouteGatesTest.php` (7 methods) pins the permission of each named route; `AdminAddonSurfaceTest.php` (23 methods) covers the add-on install/include/delete surface; `AdminRouteAuthenticationTest.php` (13 methods) pins that nothing under `/admin` is reachable unauthenticated. | CLOSED-WITH-TEST |
| SEC-E09-01 | E09:260 | subject not recoverable from the dossier — the §6 row carries `—` in its evidence, impact and action columns, and the id appears nowhere else in E09. The dossier states no fact of any kind for it. | none | no fix attributable to this id | NOT-RECOVERABLE |
| SEC-E10-01 | E10:167 (also :112, :282, :414) | **Direct anchor.** The binding and verification of the Paymob return path: one callback route entered by the customer's browser redirect, where the dossier says the evaluation of the path's remaining controls is restricted, and which it records as also covering the time-generated reference value. | `routes/web/routes.php:574-577`; `app/Http/Controllers/Payment_Methods/PaymobController.php:233-269` | Code: the gateway's confirmation is now held to the payment row it is for — its amount and currency must be that payment's — and one gateway transaction settles one payment only; the return flag is read as before. Test: `tests/Feature/Payments/AGatewayConfirmationSettlesItsOwnPaymentTest.php`, 9 methods over the real signed return on the real route, incl. a confirmation of one payment not settling another of the same amount, another currency settling nothing, and an already-settled payment not settled twice. | CLOSED-WITH-TEST |
| SEC-E10-02 | E10:115 | subject not recoverable from the dossier — a bare pointer in a list of eight E10 ids under "the security issues identified in this axis". Seven siblings share the heading and the prose separates none of them. | none for this id | the payments axis has substantial work (note B) but nothing is attributable to **this id** | NOT-RECOVERABLE |
| SEC-E14-01 | E14:132 (also :306, :318) | subject not recoverable per id. The dossier states a **class** fact for the range E14-01…14: the classic v3 seller controllers inherited from the base product and their web-panel counterparts isolate inconsistently — some functions were tightened and adjacent ones were not. It attaches that to no single id. | class: `app/Http/Controllers/RestAPI/v3/seller/ProductController.php:1767-1776`; `.../OrderController.php:372-375` | the ownership class is heavily covered (note C), but the dossier does not say which id that closes | NOT-RECOVERABLE |
| SEC-E14-02 | E14:133 | subject not recoverable per id — as E14-01. | class only | note C | NOT-RECOVERABLE |
| SEC-E14-03 | E14:134 | subject not recoverable from the dossier. The only signal is the triage's domain category, "wallet and balance", which separates it from its siblings but states no technical fact; a category is not a subject. | none | not determinable | NOT-RECOVERABLE |
| SEC-E14-04 | E14:441 (also :135) | subject only partly recoverable, and what is stated is a **retraction**: the independent verifier REJECTED the claim under E14-04 that an order edit updates only the last `OrderEditHistory` record, because the update reaches every record of the order. The dossier states the correction, not the remaining defect. | not given with the retraction | the retraction means the stated basis is void; the residual defect is unknown, so nothing can be matched to it | NOT-RECOVERABLE |
| SEC-E14-08 | E14:441 (also :139) | subject only partly recoverable, and again a **retraction**: claim (b) under E14-08, that another person's e-mail could be reserved with an account whose password is known, was REJECTED because registration enforces `unique:users`. The subject area is seller registration e-mail handling; the remaining defect is not stated. | not given with the retraction | see note D | NOT-RECOVERABLE |
| SEC-E14-09 | E14:56 (also :140, :447) | **Direct anchor.** The public seller registration route is outside the `throttle:auth` limiter that the seller login and password-recovery routes carry; the dossier moves the registration route's handling to this id in both §2.2 and its change log. | `routes/rest_api/v3/seller.php:51` (the limiter), registration group at `:68` | **No fix found on this branch.** The `auth` group still carries `throttle:auth` (`routes/rest_api/v3/seller.php:53`); the `registration` group at `:68` carries no limiter, only `api_lang`. `tests/Fixtures/routes-on-main.txt:1973` records `POST api/v3/seller/registration \| (none)`. No test asserts a limiter on it: `SellerRegistrationGatesTest` covers the registration switch and the master-admin e-mail, not rate limiting. | OPEN |
| SEC-E16-01 | E16:33 | **Direct anchor.** The dossier states that a step EARLIER in the customer app's boot sequence than the steps it lists is the subject of this id. That earlier step installs a global HTTP override which makes every HTTPS connection the app opens accept any server certificate, so transport authentication is disabled app-wide. | `lavkooapp:lib/main.dart:104-153` (boot sequence as audited); the step is at `lib/main.dart:89-95` and `:108` on this branch | **No fix found on this branch.** Present and active on `lavkooapp` `gcc/development` at `acf422b`: the override class is at `lib/main.dart:89-95` and installed at `:108`, before the first listed boot step. No test in `lavkooapp/test/` references it. The seller app does **not** carry the same override (checked: no match in `lavkooappseller/lib`). See note E — this is the most severe unfixed item in this register. | OPEN |
| SEC-E17-01 | E17:403 | subject not recoverable from the dossier — a bare pointer under the §6 findings table, with no fact attached. The dossier also cannot be cross-read by category: the triage files this id under "seller app" while dossier E17 is the **customer** app's server-driven dynamic theme (note F). | none | not determinable | NOT-RECOVERABLE |
| SEC-E19-01 | E19:78 + :238 | **Direct anchor plus category.** An authorization defect in the public seller registration path. E19 line 78 restricts E19-01/02/03 to "these paths" (password recovery and registration); line 238 anchors E19-01 to the registration request itself; and of those three ids E19-01 is the only one the triage files under authorization, the other two being authentication. | `lavkooappseller:lib/features/auth/domain/repositories/auth_repository.dart:220-232` (and `:195-253`) | Code: the v3 seller `RegisterController` hard-codes `status = 'pending'` (lines 47, 85), so a status in the request cannot approve the registrant. Tests: `tests/Feature/Security/SellerSelfApprovalTest.php` (a visitor sending a status on any of the three public registrations does not self-approve, and may not take the master admin's e-mail) and `SellerRegistrationGatesTest.php` (7 methods; the switch and the e-mail comparison on all three doors, case-insensitively). | CLOSED-WITH-TEST |
| SEC-E19-02 | E19:78 + :297 | **Anchor to a path group; attribution inferred.** An authentication defect in the seller password-recovery path. Line 78 restricts E19-01/02/03 to password recovery and registration, line 297 marks this one "highest priority"; registration is taken by E19-01, and of the two authentication ids left E19-02 is the P0 and E19-03 the P1 — so the severe reset defect is attributed here **by severity, not by a statement in the dossier**. | `app/Http/Controllers/RestAPI/v3/seller/auth/ForgotPasswordController.php` (path named at E19:78; line not given per id) | Code: the identity is matched exactly (`where('identity', $identity)`, `ForgotPasswordController.php:47`) instead of by substring, and the Firebase branch no longer accepts a self-stored token as proof. Test: `tests/Feature/Security/SellerPasswordResetTest.php`, 32 methods over v2 and v3, incl. a wildcard identity redeeming nothing, a fragment redeeming nothing, a reset changing only the seller the code was sent to, and a token anyone stored resetting nothing. | CLOSED-WITH-TEST |
| SEC-E20-04 | E20:235 (also :83, :172) | **Direct anchor, two halves.** Line 235 attaches this id to the POS sale's money: the amount is computed in the app and sent in display currency, and a wallet sale is paid from the customer's platform-wide wallet rather than a store-scoped one; the fix it names is "store context for POS and store wallet". | `lavkooappseller:lib/features/pos/screens/pos_screen.dart:821-836`; `app/Http/Controllers/RestAPI/v3/seller/POSController.php:320-323` | **Partly closed; not closed.** Store context: closed — `tests/Feature/Storefront/PosSingleCompanyTest.php` makes all three tills name one country and one selling company before stock is taken. Client-computed amount: the v3 till charges a server-computed total (`POSController.php:389-391`), and `tests/Feature/Pos/PosTillServerTotalTest.php` pins "a sale posting less than it costs is not charged the lower figure" — but its `tills()` provider (line 47) is the admin and vendor tills only, so **the v3 seller till named by the dossier is not covered by that rule's test**; `SellerAppTillStockTest.php` (16 methods) covers its money/stock ordering, not the posted figure. Store wallet: **explicitly not done** — `POSController.php:389-390` records that the wallet holds one platform-wide balance "until the balances are per market (GCC-15)". | OPEN |

## 3. The 4 conditional P0 ids

| SEC id | Cited at | Subject (reconstructed) | file:line the dossier names | What this branch does about it | State |
|---|---|---|---|---|---|
| SEC-E11-17 | E11:419 (also :443) | **Direct anchor.** The integration centre's permissions, its shipments and its callback log carry no country or legal-entity scope; the dossier rates it P1 today and says it rises to P0 if a second country is activated before the scopes are applied, because that is when it leaks between entities. | §3 (A13); the `admin/fulfillment-providers` routes | **No fix found for this surface.** The staff country scope exists but covers one controller: `app/Services/Staff/StaffGovernanceRoutes.php` states "One controller so far" and its rule set holds `AuditLogController` only. The integration centre is not in it. The condition that raises this to P0 (a second active country) is therefore still live. | OPEN |
| SEC-E15-01 | E15:170 (also :258, :283) | **Direct anchor.** An inherited test route file that is not loaded from `RouteServiceProvider::map()`; the dossier groups it with inherited database-reset console commands and recommends deleting them before further GCC environments exist. | `app/Providers/RouteServiceProvider.php:48-66` | **Unchanged.** `routes/test.php` is still present (108 lines) and `map()` still does not register it (`app/Providers/RouteServiceProvider.php:48-69`). Dormant, as audited. No test asserts that it stays unregistered, so nothing would catch a future `mapTestRoutes()`. | OPEN |
| SEC-E18-03 | E18:219 (also :66, :209) | subject not recoverable — the dossier marks this one **UNKNOWN by its own admission**: "the server's handling of client input at payment start and order placement: UNKNOWN — to be settled by the backend payments/orders auditor". It names a question, not a fact, and its §6 row is `—` in every column. | none | not determinable from the dossier | NOT-RECOVERABLE |
| SEC-E22-05 | E22:93 (also :219) | subject not recoverable — the id is appended to a contract row for the phone one-time-code endpoint (`POST auth/check-phone`) and to the note that a 401 there clears the app's session. The row describes the endpoint; it states no defect. | `routes/rest_api/v1/api.php:128`; `app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php:220-222`, `:286-296` | not determinable; the triage's action class for it is `verify`, which is consistent with nothing being asserted yet | NOT-RECOVERABLE |

## 4. Counts

Of the 23 explicit P0 ids:

| State | Count | Ids |
|---|---|---|
| CLOSED-WITH-TEST | **6** | SEC-E04-06, SEC-E05-02, SEC-E05-03, SEC-E10-01, SEC-E19-01, SEC-E19-02 |
| CODE-WITHOUT-TEST | **0** | — |
| OPEN | **3** | SEC-E14-09, SEC-E16-01, SEC-E20-04 |
| NOT-RECOVERABLE | **14** | SEC-E04-01…05, SEC-E05-01, SEC-E09-01, SEC-E10-02, SEC-E14-01, -02, -03, -04, -08, SEC-E17-01 |

Of the 4 conditional P0 ids: OPEN 2 (SEC-E11-17, SEC-E15-01), NOT-RECOVERABLE 2 (SEC-E18-03, SEC-E22-05).

The dominant fact is not the 6 closed but the **14 not recoverable**: for 14 of 23 explicit P0s the dossiers state no technical
fact, so no amount of reading this repository can say what they are. Those 14 cannot be closed, deferred or accepted on the
evidence that exists; they need the annex or a fresh assessment.

## 5. Notes

**A — the five E04 authentication ids.** E04's §2.2 ends with "security issues in these paths, documented only in the restricted
annex", then lists eleven ids including E04-01…05. The paths are customer login (a Passport personal access token with no
expiry configured in code), the API guest middleware, phone and e-mail one-time codes, the per-IP attempt limiter, social
login, the referral code written at registration, guest-to-customer merge, and account deletion (which leaves ledger and order
rows pointing at a removed user). The dossier's §6 row E04-F04 gives the class for the whole range E04-01…18 as
"authentication, account recovery and wallet-operation authorization". The repository has real work in this area — the customer
password-reset tests, the one-time-code tests, the throttle-counter tests under `tests/Feature/Security/` — but attributing any
of it to a numbered id would be invention, and five ids share one heading. They stay NOT-RECOVERABLE.

**B — the payments axis.** Beyond E10-01's own fix, this branch carries `tests/Feature/Security/PublicPaymentEndpointsAreRateLimitedTest.php`
(every public gateway return and callback route carries a limiter, read off the booted route table) and
`tests/Unit/Payments/GatewayConfirmationsTest.php`. Which of E10-02…08 those bear on is not stated anywhere.

**C — the seller ownership class.** The dossier's class fact for E14-01…14 is matched by a large body of work on this branch:
roughly thirty ownership tests under `tests/Feature/Security/` named `SellerApi*Ownership*` and `Vendor*Ownership*`, covering
catalogue, orders, order lines, payouts, promotions, shipping methods, coupons, POS, delivery men and staff tokens. This is
almost certainly "stage 2 ownership" in the tracker's language, and it is almost certainly what closed several of E14-01…14.
**But the dossier attaches the class to no id**, so this map cannot say which. This is the single place where the register would
gain most from the annex, or from the author of stage 2 naming the ids their PRs closed.

**D — the two retractions.** E14-04 and E14-08 are the only P0s whose dossier text is a correction rather than a finding: the
independent verifier rejected a claim under each (all `OrderEditHistory` records are updated, not only the last; registration
enforces `unique:users`). If those rejected claims were the basis of the P0 rating, the rating is stale. That is an owner
question, not something to change here.

**E — SEC-E16-01.** If the reconstruction is right, this is the most serious item in the register and it is unfixed. Disabling
certificate validation for every HTTPS connection removes the guarantee that the app is talking to the real server, which
undermines every other control on the customer path — sessions, one-time codes, payment returns — regardless of how well those
are implemented server-side. It is three lines in `lib/main.dart`, it is P0 already, and it has no test. Recommend it be the
first of the three OPEN items scheduled, and that a test be written that fails if the override returns.

**F — SEC-E17-01's category disagrees with its dossier.** The triage's section 4 files SEC-E17-01 under "seller app"
(تطبيق البائع), but dossier E17 is titled the **customer** app's server-driven dynamic theme and its contract with the backend;
the seller app dossiers are E19 and E20. One of the two is mis-filed. This matters because a reader looking for E17-01's
subject in the seller app will not find it.

## 6. Questions for the owner (no priority was changed here)

1. **SEC-E16-01 — schedule, not re-rate.** P0 is right. It is the one recovered subject in this register that is both unfixed
   and able to undermine the others. Does the owner want it taken first, and does its fix need a separate authorization given
   it is customer-app runtime code?
2. **SEC-E17-01 — which repository is it in?** The triage says seller app, the dossier is the customer app's theme. Please
   confirm which, so the id can be looked for in the right place.
3. **SEC-E14-09 — is P0 right?** The recovered subject is a missing rate limiter on one public registration route. Its
   siblings in sessions and tokens are rated P1 (for example SEC-E01-06). Either the annex's E14-09 is more than the limiter,
   or the rating is high. Which?
4. **SEC-E14-04 and SEC-E14-08 — do the retractions change the rating?** The dossier records that a claim under each was
   rejected on verification. If the rejected claim carried the P0, these should be re-rated rather than left as unclosable P0s.
5. **SEC-E20-04 — it cannot close before GCC-15.** Half its recovered subject (a store-scoped wallet) is deliberately deferred
   in the code to GCC-15. A P0 that cannot be closed before a later phase needs either an accepted deferral in
   `DEFERRED_RISKS.md` or a split into the part that is done and the part that waits.
6. **SEC-E05-03 — the P0 reason is now gated.** It was raised to P0 specifically because the add-on package upload routes were
   among the ungated ones, and those now carry `module:themes_and_addons` with a route-table guard. Whether the remainder of
   the 82 routes still justifies P0 is an owner call.
7. **The 14 NOT-RECOVERABLE ids — how should they be discharged?** They cannot be closed or accepted on this evidence. Options:
   recover the annex, have the authors of stages 1-3 name the ids their PRs closed, or re-assess those 14 surfaces fresh. The
   first is cheapest if the annex exists anywhere outside the repositories.

## 7. What this map cannot say

- It cannot say what the annex said. Every subject is a reconstruction from dossier prose, and 14 of 23 have no prose to
  reconstruct from.
- It cannot name the introducing commit for most fixes: the checkout is shallow and the tests arrived through a boundary
  commit.
- It cannot confirm the CLOSED-WITH-TEST tests pass. No suite was run (five tracks share this machine). Each named test was
  read: its methods exist, none is skipped, and the assertions match the recovered subject. Whether they are green on this
  branch is a separate check, and the tracker's own CI evidence is the place for it.
- It does not record an owner acceptance for anything. No id here is accepted; that is a separate criterion of the same
  tracker unit and needs the owner's own words in `DEFERRED_RISKS.md`.
