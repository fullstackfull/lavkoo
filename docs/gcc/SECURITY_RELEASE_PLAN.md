# Security release plan: 24 open pull requests, two ways to ship them

> **Status: prepared, not executed.** Nothing in this document has been run against the live server, the live database or a provider. The
> hotfix described in section 1B is the branch `gcc/release/security-hotfix`, pushed to GitHub as the draft pull request [#78](https://github.com/fullstackfull/lavkoo/pull/78) (a deployment branch: it is not merged and not deployed). Every statement about the server
> is a statement about the tree it is said to run (commit `698aa1e`, 2026-11-06, *Hotfix 1 runbook: name the PHP binary*); what the server
> answers is not known until the steps below are run. **Nothing is merged, deployed or switched on without the owner's explicit approval.**

## 1. The two ways to ship

Twenty-four draft pull requests close the account-takeover, ownership, permission, upload and content-visibility findings of the review (the
list, with what each closes, is in [OWNER_PR_GUIDE.md](OWNER_PR_GUIDE.md) sections 1 and 2). They are cut from `main`. The server does **not** run
`main`: it runs `698aa1e`, and `main` is **395 commits ahead** of it. The project deploys by overlay (`scripts/deploy/build-overlay.php` builds
a package from two revisions, `scripts/deploy/deploy.php` verifies and applies it with backups and rollback), and every earlier hotfix was
built **from the server's own commit**, not from `main`. So there are two honest ways to ship the set.

| | **A. Merge on `main`, deploy `main` as a whole** | **B. The baseline hotfix** |
|---|---|---|
| What the server gets | the 24 fixes **and** the 395 commits between `698aa1e` and `main` | the 24 fixes (as one net change each) and **nothing else** but one config line (below) |
| Built from | `main` (after the owner merges the set, in stages) | `698aa1e` (the tree the server runs) |
| Files an overlay would carry | 796 (686 added, 110 modified) under `app`, `bootstrap`, `config`, `database`, `resources`, `routes`, today, **before** the 24 pull requests | **209** (182 modified, 27 added) |
| Files an overlay cannot carry | 3 today (`firebase-messaging-sw.js`, `public/firebase-messaging-sw.js`, `robots.txt`), **plus the 17 of the security set** once it is merged | **17** (`.htaccess`, 14 files under `Modules/`, two files under `public/`) |
| Migrations that come with it | 32 new files since the baseline (address, operational costs, sales channels, fulfilment, MyFatoorah …), **plus the 3 data-only ones of the set** | the **3** data-only ones of the set |
| Behaviour that changes beyond the security fixes | the whole of those 395 commits (all of it written to be dormant until its migrations run; none of it has ever run on this server) | none, except `config('app.mode')` (the OTP decision, section 6) |
| Risk | the size of the change: a first deployment of an address system, sales channels, fulfilment changes and the rest, together with the security set | the port: 24 hand-checked applications of a patch to an older tree (every line verified, below) |
| Needs a decision before it starts | **yes**: whether the owner wants those 395 commits on the live server now (and the Firebase project and the domain of three files), besides the decisions of section 6 | the decisions of section 6, but not that one: it is the same kind of package as Hotfix 1 and Hotfix 2 |
| Rollback | the same tool, but the migrations of the 395 commits are a separate rollback story | `deploy.php rollback` for the files, by hand for the 17 files; the 3 data migrations cannot be undone (section 5) |

**The two are not exclusive.** B can go out first and A later: after B the server runs the hotfix tip, and the next package is built with
`--base=<the hotfix tip>` as every earlier hotfix was. The hotfix branch is a **deployment branch**: do not merge it into `main` (it conflicts, as
it must, in every file where it differs from `main`: section 1B). `main` keeps the 24 pull requests as they are.

**What is in this document.** Section 1B: the hotfix in facts (what it is, how every pull request was ported, what had to be adapted, what the package is). Section 2: the five stages and why they are in
this order. Section 3: for each stage the pull requests, the files, the migrations, the `.env` and config, what people will notice, what employees must be told, smoke tests and expected log lines.
Section 4: the runbook (H-0 to H-8, and by stage). Section 5: rollback and what cannot be undone. Section 6: the decisions only the owner can take. Section 7: what is verified and what is not.
Appendix A lists every file of every stage; appendix B the 17 files an overlay cannot carry, with checksums.

**Before anything starts, the owner has to decide** (section 6): **1** whether the verification codes become random (on the baseline they are the constant `123456` whatever `.env` says), **2** A or B, **3** that the
customer app (`lavkooapp#4`) and the delivery-man app are ready for stage 1, **5** which roles get the permissions that stage 3 starts to ask for, **6** the finance reading of #52, **11** the server checks before stage 4.

## 1A. Merging on `main` and deploying `main` as a whole, in facts

* The 24 pull requests are cut from `main` (`c5c04b7b`, 2026-11-12). Merged in the stage order of section 2 they merge into each other cleanly except for **one textual conflict**, #44 against #52 in `app/Http/Controllers/RestAPI/v2/seller/SellerController.php`, `shop_info()` (keep both changes: the orders count that only counts the seller's own orders, and `makeVisible(Seller::OWNER_ONLY_ATTRIBUTES)`). The integration of all 24 on `main` exists locally as `gcc/release/security-integration` (tip `47b78ab8`).
* **What the server would get besides the fixes:** the 395 commits between `698aa1e` (2026-11-06) and `main` (2026-11-12): **796 files** under `app`, `bootstrap`, `config`, `database`, `resources`, `routes` (686 added, 110 modified), five new configuration files (address, MyFatoorah, Odoo, operational costs, sales channels) and changes to four others (`app`, `deeplinks`, `fulfillment`, `system-addons`), and **32 migration files**. An overlay does not run migrations; the new code is written to be dormant until its migrations run, and every behaviour behind a switch that is off. **None of it has ever run on this server.**
* **Files an overlay cannot carry, today, before the 24 pull requests:** `build-overlay.php --base=698aa1e --head=main` refuses exactly three: `firebase-messaging-sw.js`, `public/firebase-messaging-sw.js` (the web-push project in them changes from the old Firebase project to another one: a Firebase setting) and `robots.txt` (the sitemap domain). That is why the CI job *Deployment overlay* is red on every branch cut from `main`. With the security set merged, the 17 files of section 4 join them.
* **What the owner has to decide first:** whether the 395 commits go to the live server now, and the Firebase project and the domain of those three files. Everything else is the same procedure as section 4, with a larger package and the three extra files by hand; `main`'s own migrations are a separate decision and a separate runbook.
* **When A is the right way:** when the owner wants `main` on the server anyway, the address system and the rest included; then the 24 pull requests are merged in the order of section 2, the stage gates of section 3 still apply, and sections 5 and 6 are unchanged.

## 1B. The baseline hotfix, in facts

### Where it is

| | |
|---|---|
| Repository | `fullstackfull/lavkoo`; the three branches below are pushed. The draft pull request [#78](https://github.com/fullstackfull/lavkoo/pull/78) has `gcc/release/security-hotfix-base` (a branch that only points at `698aa1e`) as its base, so that its diff is exactly the hotfix; the integration of the whole set on `main` is the draft pull request [#75](https://github.com/fullstackfull/lavkoo/pull/75) (`gcc/release/security-integration`, CI evidence only, do not merge) |
| Branch of the fixes | `gcc/release/security-hotfix`, **25 commits** on top of `698aa1e`, tip **`665057a5d839f3626663dc451f88965a60e51893`** |
| Branch to build the package from | `gcc/release/security-hotfix-package`, **one commit** on top of the tip: **`844bd27fc439deebf0b6e9c1fd6c9f2398e7d8b3`**. Its tree is the tip with the 17 files an overlay cannot carry put back to their baseline versions, because `build-overlay.php` refuses a release that changes a file outside `app`, `bootstrap`, `config`, `database`, `resources` and `routes` (the builder's own words: *Deploy it by hand, deliberately*) |
| Commit that is on the server afterwards | the tip: `665057a5…`. The overlay files are identical in both commits; the 17 are deployed by hand **from the tip** |
| Composer | no change (`composer.json` and `composer.lock` are identical to the baseline's and to `main`'s): no `composer install`, no `dump-autoload` |

The 25 commits are the 24 pull requests, each as **one commit with its net change** applied to the baseline, in the stage order of section 2, plus one
that is not a pull request (the 9th, `config/app.php`, section 6). Each commit message names the pull request, the range it was taken from, what had
to be adapted and why. The shas of the commits that end each stage are the sha of the stage packages' `--head` (section 2).

### How each pull request was ported

* The production diff of the pull request (from its merge base with `main`, or with the pull request it is stacked on) was applied to the baseline
  with `git apply --3way`; its test files were added in the same commit; the tests were run **before** the production change (red) and **after**
  (green).
* `main` has changed 23 of the 230 production files of the set since the baseline. **207 of the 230 are byte for byte the file of the integration of
  the whole set on `main`** (`gcc/release/security-integration`, tip `47b78ab8`). Of the 23 others, 12 equal the integration's file exactly once
  `main`'s own changes since the baseline are applied to the hotfix's file (`git merge-file`), and the 11 where `main`'s changes overlap the pull
  request's hunks (resolved by hand) or where language keys sit elsewhere differ from the integration's file in **no line** except lines that `main`
  itself added or removed, and in one constructor line (`RestAPI/v1/CustomerController`: the baseline has no `ShippingAddressService`).
* **Every non-blank line a pull request adds to a production file is present in the result** (checked line by line; the only line that is not is that
  constructor line). Every line a pull request adds to a language file is present, except the six *not found* texts that moved from `messages.php` to
  `new-messages.php` (below).

### What had to be adapted (the whole list)

| Where | What | Why |
|---|---|---|
| `app/Http/Controllers/Web/UserProfileController.php` (#62) | the order-tracking lookup of the pull request sits in the baseline's own `track_order_result()`; the `is_string()` guard of the typed phone that `main` has in front of it is added | `main` rewrote the guest branch of that function for the address system; none of that is carried over. `SaudiPhone::isSameNumber()` takes text |
| `app/Services/Fulfillment/Support/SaudiPhone.php` (#62, #58) | the whole file of the pull request | the baseline lacks the Persian and full-width digit tables that `main` added and that the pull request's helpers document and use. The baseline's only callers (`normalize()` in the Torod and M5AZN payload builders) read more spellings of the same digits afterwards, nothing else |
| `app/Http/Controllers/RestAPI/v2/seller/SellerController.php` (#52) | `shop_info()` keeps both changes: the seller's own orders count (#52) and `makeVisible(Seller::OWNER_ONLY_ATTRIBUTES)` (#44) | the one textual conflict of the set |
| `RestAPI/v3/seller/POSController.php` (#52), `app/Utils/module-helper.php` (#56) | the pull request's lines in the places the baseline has; the address guard and the address imports of `main` around them are not carried over | `main` changed both files for the address system |
| `RestAPI/v1/{ConfigController,CustomerController,OrderController}`, `RestAPI/v3/seller/OrderController`, `Vendor/Order/OrderController`, `Web/WebController` (#69) | the pull request's constructor parameters and imports, without the address services that `main` injects beside them | same |
| `resources/lang/{en,sa}/new-messages.php` (#57, #52, #41) | the keys of the pull requests are inserted after `Profile_Info` / `admin_password_updated_successfully`; **`profile_updated_successfully`** is added to both | the baseline's two files lack the two thousand keys of `main` the patch hunks sit in; #57's own test refuses a profile flow that makes `translate()` write a language file, and the baseline does not define that key |
| The six *not found* texts of #52 | go to `new-messages.php` of `en`, `sa`, `bd`, `in`, `ks` instead of `messages.php`; the five `messages.php` stay as they are | the admin panel's language screens rewrite `messages.php`, so on a live server it is the file most likely to be `DRIFTED`, and accepting a drift would throw away the store's own translations. `new-messages.php` is the file `deploy.php` merges. `translate()` reads both, `messages.php` first, so the answer is the same |
| `tests/Feature/Security/AdminRouteAuthenticationTest.php` (#67) | the pull request's tip of the file | `main` had already improved the same test; the baseline's copy conflicted |
| Tests that extend `main`'s address suite (#54, #55, #56) | extend `tests/Feature/OrderPipelineTestCase.php` instead, which builds the same store tables through `tests/Support/BuildsOrderPipelineSchema.php` (`main`'s `BuildsApiAddressSchema` without the address catalog, plus the reset and the legacy address book of `BuildsAddressSchema`) | the base class of the address suite runs address migrations that the baseline does not have |
| #62's tracking-page tests | **new** `tests/Feature/Security/TrackOrderByCustomerPhoneTest.php` (15 tests) | the pull request's tracking cases live in `tests/Feature/Address/StorefrontTrackOrderTest.php`, which needs the address tables |
| Test support copied unchanged from `main` | `tests/Support/RestoresLanguageFiles.php`, `tests/Support/panel-views/*`, `tests/Support/views/storefront/*` | the ported tests prepend these stand-in layouts and use that trait; the baseline has only `tests/Support/views` |

**Not ported** (they test features that exist only on `main`): the changes the pull requests make to `tests/Feature/SalesChannels/*` (a time-dependent fixture of
`main`), `tests/Feature/Address/*`, `scripts/ci/leak-guard-allow.json` and `docs/`. `php scripts/ci/leak-guard.php --base=698aa1e` says nothing was added.

**No pull request was left out**: none needed a large part of `main` to be dragged in. The file `main` has and the baseline lacks that a pull request
*builds on* is the digit table of `SaudiPhone.php` (four lines) and `config('app.mode')` (one line).

### What the package is

An **incremental overlay** built from `698aa1e` to the package commit. Built here, from `gcc/release/security-hotfix-package`:

| | |
|---|---|
| Files in the package | **209**: 182 modified, 27 added (the branch changes 226 production files: 209 here, 17 by hand) |
| States `deploy.php verify` gives them against a scratch copy of the baseline tree | **175 CLEAN, 7 MERGE, 27 NEW**: `Result: every file can be applied.` (the 7 MERGE are `resources/lang/{ae,bd,en,es,in,ks,sa}/new-messages.php`: the keys of the release are put into the live file, the site's own keys stay) |
| `apply` on that scratch copy | `[ok] 209 file(s) written`; 202 files are byte for byte the release's, the 7 language files are merged |
| `rollback` on that scratch copy | `[ok] 209 file(s) restored`; the tree is byte for byte the baseline's again **except** `resources/lang/bd/new-messages.php` and `resources/lang/in/new-messages.php`, which hold the same array written again (the tool re-serialises a merged language file; their baseline copies use double quotes) |
| Migrations in the manifest | 3, all data only (section 3, stage 1) |
| Refusal of `build-overlay.php` for the **tip** | exactly the 17 files of section 4, nothing else |
| New routes | 16 added (digital-file downloads, the draft preview of a blog post, the barcode sheet's signed link), 2 removed (`GET api/v1/customer/order/digital-product-download/{id}` is replaced by the same path with an optional file name; `POST vendor/pos/order-details/{id}`); 95 existing routes got a new middleware (a `module:` permission, `seller_staff_access`, `seller_can:…`, `seller_owner`) |
| Config | `config/app.php` (one line), `config/filesystems.php` (the `digital_files_private` switch and the private disk, off) |

## 2. The stage plan and its order

Closing one door and leaving another is no fix, so the set is shipped in five stages whose order follows what each fix needs. **Stage 1 is the one that
cannot be split**: a customer or a seller can be taken over by someone who knows only a phone number or an e-mail, through five doors at once (#62, #58,
#59, #45, #60), and the page that carries a seller's session token (#44) is open to everybody.

| Stage | Pull requests, in the order of the commits | Hotfix commits (the tip of the stage is the `--head` of its package) | Why here |
|---|---|---|---|
| **1. Account takeover** | #44, #61, #62, #58, #59, #45, #60, #57, and `config/app.php` | `4ad858bd`, `bb9f7448`, `e0006053`, `c0007bb4`, `5627bd5b`, `8edd8d8c`, `69279a0a`, `bd3284a0`, `779a3e50` (**tip `779a3e5046b716474f1e889a4681260549b744e7`**) | a phone number or an e-mail must stop being enough to become someone else; the verification codes must be random for the fixes to mean anything (section 6, decision 1) |
| **2. Ownership and data exposure** | #46, #52, #51, #54, #56, #55 | `0974b562`, `fb258400`, `b4b0fd7a`, `b135da24`, `e0e7c517`, `b7842fa1` (**tip `b7842fa1f3462bac3d059fa8be5dbf96ed8dc953`**) | a seller acting on another seller's rows, a hidden product answering, a cart row or a paid callback used by the wrong person. **#52 before #51**: alone, #51 makes the seller API v2 product list show the product whose id equals the seller's own id, with its unapproved reviews, to that seller |
| **3. Permissions** | #65, #66, #67, #70, #73, #68 | `6de74670`, `7cbfa55b`, `15936e4e`, `c8ba4388`, `01c8228d`, `6263b8b3` (**tip `6263b8b398739812c9cd10001aa61a391a0fc3d9`**) | what an employee or a staff member may open. **#65 before #67 before #70 and #73** (stacked), **#52 before #68** (stacked): this stage changes what roles can do, so it needs the preparation of its own checklist |
| **4. Uploads and files** | #63, #69 | `435b9385`, `53ba4a25` (**tip `53ba4a2591a33be63eeee35d91ac75bcbf610f4d`**) | a stored script, an archive that escapes its folder, a download of any file. **#63 before #69** (stacked). Needs the web-server checks of its own list, because a file written before the fix may already be there |
| **5. Content visibility** | #40, #41 | `eb3695a0`, `665057a5` (**tip `665057a5d839f3626663dc451f88965a60e51893`**) | unpublished pages and posts; small, independent, last because they close nothing that can be abused for an account |

Rules that decide the order, all kept by the order of the commits:

1. **#62, #58, #59, #45 and #60 together**, with #44, #61 and #57 in the same stage. #60 needs #62 (the table of codes) and **the customer app's
   `lavkooapp#4` published first**: an older app build only loses the "is this your account?" sheet (it gets a translated 403), which the app audit
   recommends accepting rather than waiting (decision D1 there).
2. **#52 before #51; #52 before #68; #65 before #67 before #70 and #73; #63 before #69.**
3. The set needs `APP_MODE=live` to mean anything, and **on the baseline that needs a line of code, not only a `.env` line** (stage 1, config).
4. Stage 3 is the only stage that can take a screen away from a person who uses it today. Stage 4 is the only one that deletes or refuses stored data.

**A stage is one package.** The branch is linear in this order, so the package of stage *n* is built from the tip of stage *n−1* (the commit that is on the
server after stage *n−1*) to the tip of stage *n*; the recipe is in section 4 (H-1, "by stage"). Built that way:

| Stage | Files in the package | Added / modified | Language files merged | Migrations | Files by hand |
|---|---|---|---|---|---|
| 1 | 50 | 7 / 43 | 7 | **3** | 0 |
| 2 | 97 | 3 / 94 | 7 | 0 | 4 |
| 3 | 19 | 3 / 16 | 0 | 0 | 4 |
| 4 | 73 | 14 / 59 | 3 | 0 | 5 |
| 5 | 4 | 0 / 4 | 1 | 0 | 5 |

(A file that two stages change is in both packages; the 209 of section 1B are the distinct files, the 17 by hand are 4 + 4 + 5 + 5 with two of them in two stages.)
Shipping all five at once is the same procedure with the one package of section 1B.

---

## 3. Stage by stage

Wherever a command below says `art`, it is the function defined in section 4. `DOMAIN` is the shop's address. **Every smoke test here reads or is refused;
none of them writes** unless it says so. A refusal that a test expects is the fix working.

### Stage 1. Account takeover (8 pull requests and one config line)

**What each closes**

| PR | Hotfix commit | Closes |
|---|---|---|
| #44 | `4ad858bd` | **an anonymous visitor could open any seller's vendor panel through a public product page**: the product JSON carried the seller's `remember_token`, push tokens, bank account and commission, and the web panel accepts a cookie made of a seller id and that token. The seller is now serialized without credentials or finances (the seller's own profile still shows them). Also: public seller registration approved itself; a seller who is not approved kept working on the v2 API; the web sign-in checked a different row; the master admin's e-mail and the admin's registration switch were not honoured on every door. **If only one thing ships, ship this** |
| #61 | `bb9f7448` | the vendor panel's shop pages acting on a shop named in the request (the same change is inside #52, byte for byte) |
| #62 | `e0006053` | customer and delivery-man password reset, the order tracking page (a piece of a phone opened an order), the shared table of codes (a row without a token signed anybody in); the Firebase reset for a phone that keeps the trunk zero after a non-Gulf country code (Syria, Egypt and ten more); a phone stored in Arabic-Indic or Bengali digits no longer equals its bare country code |
| #58 | `c0007bb4` | the same for sellers (reset by pattern, a proof anyone could write) |
| #59 | `5627bd5b` | the website's customer sign-in pages (a phone number alone signed in, or showed a name) |
| #45 | `8edd8d8c` | the website's social login confirmation (any e-mail was approved) |
| #60 | `69279a0a` | the app's "is this your account?" check: it now needs the `temporary_token` that `social-customer-login` issued (10 minutes, one use, bound to the customer, the e-mail and the provider). **Ship `lavkooapp#4` first** |
| #57 | `bd3284a0` | an admin editing any admin's profile or password |
| config | `779a3e50` | `config/app.php` defines `'mode' => env('APP_MODE', 'live')`. **On the baseline `config('app.mode')` is `null` whatever `.env` says, so every verification, reset and sign-in code is the constant `123456` (6 digits) or `1234` (4 digits)** (`(config('app.mode') == 'live') ? rand(…) : 123456`, in 12 places in 8 files). Without this line the five fixes above leave "know a phone number, type 123456" open. `main` has had the line since `352ed688` (2026-11-11). Not a pull request: decision 1 |

**Files.** 50 overlay files (see the appendix), none by hand. New classes: `CustomerPasswordResetService`, `VendorPasswordResetService`, `RequestText`,
`SocialLoginConfirmationService` and the three migrations. `app/Services/Fulfillment/Support/SaudiPhone.php` is replaced by the pull request's file.

**Migrations: three, all data only** (no schema), each skipped when its table or column is missing:

| File | What it does when it runs | Undone by |
|---|---|---|
| `2026_11_26_100000_purge_customer_and_delivery_man_reset_proofs` | deletes **every row** of `phone_or_email_verifications`, and the rows of `password_resets` whose `user_type` is `customer` or `delivery_man` (codes in flight, reset links, and the "choose your password" links a panel, the point of sale or an admin sent to a customer they created) | nothing: the rows are codes in flight |
| `2026_11_26_100100_purge_seller_reset_proofs` | deletes every row of `phone_or_email_verifications` again, and the rows of `password_resets` whose `user_type` is `seller` | nothing |
| `2026_11_26_100200_clear_seller_remember_tokens` | sets `sellers.remember_token` to `NULL` where it is not | nothing: the cleared tokens were credentials that had to stop working |

```bash
art migrate --path=database/migrations/2026_11_26_100000_purge_customer_and_delivery_man_reset_proofs.php \
            --path=database/migrations/2026_11_26_100100_purge_seller_reset_proofs.php \
            --path=database/migrations/2026_11_26_100200_clear_seller_remember_tokens.php
#   (APP_ENV=production asks "Do you really wish to run this command?": answer yes. Add --force only in a non-interactive shell.)
```

**Never a bare `php artisan migrate`**: the platform has about 300 migrations of its own, `migrate:status` may list some as pending, and one of them
(`2026_05_07_000001`) rebuilds 35 large tables to `DECIMAL(40,20)` and cannot be reversed losslessly ([pilot deployment, 4c](../integrations/pilot-deployment.md#4c-the-migrations-about-a-minute)).

**`.env` and config.** No new key. `config/app.php` now reads `APP_MODE`: it must say `live` (or the line must be absent: the default is `live`). **`CACHE_DRIVER` must be a
store every PHP process shares (`file`, `database` or `redis`, never `array`)**: #60's confirmation token is `Cache::put` at one request and `Cache::pull` at the next.
`APP_MODE=demo` or `dev` keeps the constant codes, and from this release on the 47 `config('app.mode') == 'demo'` checks (they refuse an action in demo mode) start to take effect on that server.

**Before the stage (read-only, tell the owner the answers).** From the app audits:
```sql
-- two customers holding one phone or e-mail cannot reset by it after this stage (they get "customer not found"); fix or accept them first
SELECT COUNT(*) FROM (SELECT phone FROM users WHERE phone IS NOT NULL AND phone<>'' GROUP BY phone HAVING COUNT(*)>1) d;
SELECT COUNT(*) FROM (SELECT email FROM users WHERE email IS NOT NULL AND email<>'' GROUP BY email HAVING COUNT(*)>1) d;
SELECT phone, COUNT(*) FROM sellers GROUP BY phone HAVING COUNT(*)>1;
-- customers whose stored phone keeps the trunk zero after a non-Gulf code cannot reset by Firebase until the list is extended (decision 4)
SELECT CASE WHEN phone REGEXP '^\\+(965|966|968|971|973|974)0' THEN 'gulf_zero' WHEN phone REGEXP '^\\+(963|20|962|961|964|90|967|970|249|212|213|216|218|92|91|880)0' THEN 'other_zero' WHEN phone LIKE '+%' THEN 'plus_nozero' ELSE 'no_plus' END s, COUNT(*) FROM users WHERE phone IS NOT NULL AND phone<>'' GROUP BY s;
SELECT value FROM business_settings WHERE type IN ('vendor_forgot_password_method','firebase_otp_verification');
```

**What people will notice**

* Everyone with a code in flight asks for a new one once (the migrations). A reset signs the customer out of the app (their tokens are deleted). A customer a panel or the point of sale invited with a "choose your password" link uses *forgot password*.
* The codes are random six-digit (four-digit for the delivery man's login and phone verification) numbers. A test phone or a store-review account that relied on `123456` stops working (decision 1).
* Two accounts holding one phone or e-mail cannot reset by it. A phone with a digit of a script nobody converts reads as nothing.
* Sellers: everyone who used *Remember me* on the vendor panel signs in again; a seller whose status changes (suspended, approved again) signs in again; a seller who is not approved loses the v2 session; a public registration no longer approves itself and cannot use the master admin's e-mail.
* The website's sign-in pages no longer sign in or name a customer by a phone number alone. The order tracking page opens an order only for the phone of the customer who placed it (the same number written another way, with or without the leading zero or the country code, still opens it; a piece of it or a pattern opens nothing).
* **Customer app builds older than `lavkooapp#4`** show a refusal where the "is this your account?" sheet used to answer. The delivery-man app is not in these repositories: it must call `verify-otp` before `reset-password` (decision 3).

**What employees must be told.** Support: a customer who was mid-code asks again; duplicates are handled by hand; a customer who cannot reset is probably one of the duplicates. Vendor staff: sign in again. Admin employees: each edits only their own profile and password (#57); the master admin is unaffected.

**Smoke tests** (nothing writes; the first group needs only `curl`)

```bash
art tinker --execute="var_dump(config('app.mode'));"                         # string(4) "live". On the baseline it printed NULL
art migrate:status | grep 2026_11_26                                         # three lines, all Ran
# #44: a public product answer carries none of the seller's secrets. Must print nothing:
curl -s "https://DOMAIN/api/v1/products/latest?limit=5&offset=1&guest_id=1" | grep -oE '"(auth_token|remember_token|cm_firebase_token|cm_firebase_token_web|bank_name|branch|account_no|holder_name|sales_commission_percentage)"' | sort | uniq -c
# #62: a wildcard names nobody. Both refuse (403 and 400) and change nothing:
curl -s -o /dev/null -w '%{http_code}\n' -X POST "https://DOMAIN/api/v1/auth/verify-token" -H 'Accept: application/json' --data-urlencode 'email_or_phone=%' --data-urlencode 'reset_token=314159'
curl -s -o /dev/null -w '%{http_code}\n' -X PUT  "https://DOMAIN/api/v1/auth/reset-password" -H 'Accept: application/json' --data-urlencode 'identity=%' --data-urlencode 'otp=246810' --data-urlencode 'password=Zz9!probe-only' --data-urlencode 'confirm_password=Zz9!probe-only'
# #60: the existing-account answer needs the token social login issued. Refused (403) for a made-up address:
curl -s -o /dev/null -w '%{http_code}\n' -X POST "https://DOMAIN/api/v1/auth/existing-account-check" -H 'Accept: application/json' -d 'email=nobody@example.invalid&user_response=1&medium=google'
```
* Website, as a visitor: open `https://DOMAIN/track-order/result?order_id=<the number of any order a registered customer placed>&phone_number=%25`, then again with `phone_number=%2B966`: **both redirect to the tracking form** (they answered with the tracking page before when the first customer containing the text happened to be the order's). With the real phone of that order's customer it opens.
* Admin: as an employee whose role is not the master's, open `/admin/profile/update/<another admin's id>`: you are sent to the dashboard with *access denied*; your own profile opens.
* Vendor panel: signed in as a seller, `/vendor/shop/update/<another seller's shop id>` answers **404**; your own shop opens.
* Vendor panel: a seller who ticked *Remember me* before the deployment is asked to sign in.

**Expected in `storage/logs/laravel.log`:** nothing new from these. Any `Class "App\…" not found`, `Call to undefined method`, `Undefined array key` or `SQLSTATE` that appears after the stage is a failure: take it back (section 5).

### Stage 2. Ownership and data exposure (6 pull requests)

| PR | Hotfix commit | Closes |
|---|---|---|
| #46 | `0974b562` | searches, reviews, counters, banners, hints and quick views answering for products a customer may not see; the cart (add, and the route that re-prices a line); the review list with an array id; the reviewers' e-mail and phone in a product's details; a flash deal's product count. Still open (owner): the vendor's whole row in public product payloads, three low write doors (web restock request, compare, wish list) |
| #52 | `fb258400` | the seller APIs (v2, v3) and the vendor panel acting on any seller's rows: payouts, orders, order lines, products, reviews, promotions, shop, point of sale, delivery men, shipping methods; staff with no role changing the owner's profile; **a seller could put another shop's product on an order and take its stock, and could rewrite the lines, amount and stock of a delivered order by posting to the save route** (the rule "can this order be edited" lived only in the page: the server asks it now) |
| #51 | `b4b0fd7a` | pages that merely start with `/vendor`, `/seller` or `/admin` counting as panels (unapproved reviews shown); publishing-house lists cached for every shopper |
| #54 | `b135da24` | a cart row changed by someone it does not belong to (a guest id equal to a customer's id touched the customer's rows) |
| #56 | `e0e7c517` | a paid callback settled for whoever the request named, or left state for the next one in the same process |
| #55 | `b7842fa1` | the first order of a referred customer failed after it was committed (HTTP 500, the cart not emptied, a second delivery built a second order) |

**Files.** 97 overlay files, **4 by hand**: `Modules/AI/routes/vendor/routes.php`, `Modules/TaxModule/routes/vendor.php` (#52: the staff middleware on two route files),
`Modules/Blog/app/Models/Blog.php`, `Modules/Blog/app/Models/BlogCategory.php` (#51). New classes: `PaymentSettlementScope`, `RequestArea`, `SellerOwnedRecords` and the rest
of the appendix. **No migration. No `.env` or config change.**

**Before the stage.** Read the finance decision (section 6, decision 7): #52 changes who can see and act on payouts, withdrawals and order edits. Check `SELECT id,status FROM sellers WHERE id=1`: if seller 1 is a real seller using the app, its dashboard numbers and `seller-info` counts drop once in-house rows are excluded.

**What people will notice** (from the seller-app and customer-app audits, 2026-11-17)

* A hidden product answers like an unknown one (404) in reviews and details. A product the storefront hides cannot be put in a cart. The reviewers' e-mail and phone are gone from a product's details; a product's review list on the seller APIs now includes the unapproved reviews, as its count always did.
* **Sellers' staff**: a staff member can no longer change the owner's password or profile, and a staff phone no longer registers a push token (`PUT cm-firebase-token` is for the owner: 403 `owner_only`, no sign-out).
* **The server now refuses the edit of an order that is delivered or closed** (and an edit that would drop a line whose product no longer exists). The refusal rides HTTP **200** with `status: error` and the seller app never reads `status` on a 200, so the app closes the edit screen as if it had saved (`lavkooappseller#3` shows the refusal).
* A coupon the admin assigned with the seller as its bearer answers 403 on edit and on the status switch (delete was already refused).
* Orders of seller id 1 (the in-house shop) no longer count in a seller's own dashboard.
* No change for a customer's checkout; a paid callback replayed builds nothing the second time.

**What employees must be told.** Sellers' staff: the owner's profile, password and push token are the owner's. Finance: the decision-7 list. Support: "the order cannot be edited any more" is the server speaking now.

**Smoke tests**
```bash
# #46: a hidden (inactive) product answers like an unknown one. The first query finds one slug, read-only:
art tinker --execute="echo \App\Models\Product::withoutGlobalScopes()->where('status', 0)->value('slug');"
curl -s -o /dev/null -w '%{http_code}\n' "https://DOMAIN/api/v1/products/reviews/<that slug>?guest_id=1"            # 404
curl -s -o /dev/null -w '%{http_code}\n' "https://DOMAIN/api/v1/products/reviews/<the slug of a visible product>?guest_id=1"   # 200
```
* Vendor panel, signed in as seller A: `/vendor/orders/details/<an order id of seller B>` answers **404** (*Order not found*); the same for the shop's other pages with another seller's id.
* Seller app (or a token): `GET /api/v3/seller/orders/<another seller's order id>` answers **404** with the code `order`.
* A seller's own order opens and prints as before; an order that is delivered refuses an edit with the message, not with a saved change.
* `art tinker --execute="echo DB::table('carts')->count();"` is the same number as before the stage (nothing wrote).

**Expected in the log.** `A paid payment was settled without building an order.` (level **critical**, with `transaction_id` and `customer_id`) appears only when a paid callback arrives and no order can be built: it is the new alarm of #56, and each occurrence is a customer who paid and has no order; investigate every one. Nothing else is new.

### Stage 3. Permissions (6 pull requests)

| PR | Hotfix commit | Closes |
|---|---|---|
| #65 | `6de74670` | the AI provider settings (API key and limits), the VAT and tax settings and the tax reports answered any employee whatever their role holds: they now ask for `3rd_party_setup`, `business_settings` and `reports` |
| #66 | `7cbfa55b` | three admin handlers (business settings, database settings, theme builder) and the deep-link request use the list their own screen is built from |
| #67 | `15936e4e` | the admin routes that no permission guarded and that belong to one menu entry now ask for it: the admin chat inbox (`people`), Meta app tracking (`3rd_party_setup`), the priority setup (`business_settings`; its two deal modals `marketing`), the product and blog AI buttons (`catalog` and `marketing`), the product pickers (`catalog`, `marketing`), the dashboard widgets (`dashboard`, which exposed earnings, commission and order counts to any employee) |
| #70 | `c8ba4388` | the classic admin header search showed customers, vendors, contact messages, coupons and order amounts to every employee, and was an oracle for a phone number or e-mail typed into it; it drops what the role does not hold, the recent searches are filtered and sanitized, the result list escapes an address |
| #73 | `01c8228d` | `module:a,b,c` used to ask for its first permission only (PHP dropped the others); it passes an employee who holds **any** listed permission. `GET admin/products/search` now needs `marketing` or `people`, the printable invoice of any order needs `orders`, `pos` or `people`; the dashboard poller stops telling a role about restock requests and chat messages of modules it does not hold |
| #68 | `6263b8b3` | the auction and product AI helpers (vendor panel and seller API) ask staff for the permission of the catalogue (a staff member with no role could spend the shop's AI quota); the barcode sheet was one public file every seller shared and overwrote: it is a signed ten-minute link made when it is opened, nothing is written; a quantity outside 1 to 270 is refused (the apps' own limit) |

**Files.** 19 overlay files, **4 by hand**: `Modules/AI/routes/admin/routes.php` (#65, #67), `Modules/AI/routes/api.php` (#68), `Modules/AI/routes/vendor/routes.php` (#68, again after stage 2),
`Modules/TaxModule/routes/web.php` (#65). **No migration. No `.env` or config change.** New: `AdvancedSearchPermissionFilter`, `ProductBarcodeSheetService`, `ProductBarcodeSheetController`.

**Before the stage: prepare the roles (read-only, then in the admin panel).** Everything below is about which role holds which `module_access` key; the master admin (`admin_role_id` 1) always passes.
```sql
SELECT id, name, status, module_access FROM admin_roles;
SELECT a.id, a.name, a.email, a.admin_role_id, r.name AS role FROM admins a LEFT JOIN admin_roles r ON r.id = a.admin_role_id WHERE a.admin_role_id <> 1;
```
A route group now answers **access denied** (redirect to the dashboard) to a role that does not hold the key in the second column:

| Screens and routes | Permission now required |
|---|---|
| AI provider settings (`admin/third-party/ai-setting/*`, 6 routes); Meta app tracking (`admin/meta-app*`) | `3rd_party_setup` |
| VAT and tax settings (`admin/vat-tax/*`, 8 routes); the priority setup (`admin/business-settings/priority-setup`, 2 routes) | `business_settings` |
| Tax reports (`admin/report/*tax*`, 8 routes) | `reports` |
| Product AI buttons (`admin/product/*-auto-fill`, `generate-title-suggestions`, `analyze-image-auto-fill`: 8 routes); product pickers (`admin/products/picker-options`, `picker-labels`) | `catalog` |
| Blog AI buttons (`admin/blog/*-auto-fill`, `generate-title-suggestions`, `analyze-image-auto-fill`: 5 routes); `admin/products/multiple-product-details`, `search-all-product`; the priority modals (`priority-setup/update-by-type`) | `marketing` |
| The admin chat inbox (`admin/messages/*`, 3 routes) | `people` |
| Dashboard widgets: order status, earnings, order statistics (`admin/dashboard/*`, 3 routes) | `dashboard` |
| `GET admin/products/search` | `marketing` **or** `people` |
| Printable invoice of any order (`admin/orders/generate-invoice/{id}`) | `orders` **or** `pos` **or** `people` |
| Add-on screens (`admin/addon*`, 5 routes; from stage 4's #63) | `themes_and_addons` |
| The header search lists | only the modules the role holds |

**Add the key to the role of every employee who uses those screens today, before this stage ships.** A role that reaches the screens by address or through the header search
without the key loses them. An `orders` role without `catalog` also loses the dashboard's restock toast (decision 5).

**What people will notice.** Employees as above; the barcode of a seller's product is a link that opens a sheet made at that moment and stops working after ten minutes; a barcode quantity outside 1 to 270 is refused. **Deploy step: delete `storage/app/public/product/barcode.pdf`** (the one file every seller shared): `rm -f "$APP/storage/app/public/product/barcode.pdf"`.

**Smoke tests**
* Admin, signed in as an employee whose role holds only `catalog`: `/admin/vat-tax/list` and `/admin/third-party/ai-setting` answer *access denied* (redirect to the dashboard); the product list opens.
* Admin, master: every one of those screens opens; the header search finds a customer.
* Admin, employee with `orders` only: the header search does **not** list customers, vendors or coupons.
* Seller app / API: `GET /api/v3/seller/products/barcode/sheet` without a signature answers **403**; the link the app gets when it opens the sheet works once, for ten minutes.
```bash
ls -la "$APP/storage/app/public/product/barcode.pdf"        # No such file
```

**Expected in the log:** nothing new.

### Stage 4. Uploads and files (2 pull requests)

| PR | Hotfix commit | Closes |
|---|---|---|
| #63 | `435b9385` | **uploads**: a seller, a customer, a delivery man or the unauthenticated seller registration could store a script (`.php`, `.pht`, `.phps`, `.inc`, `.shtml`) or a page (`.svg`, `.html`) on the public disk, and a seller could make an **anonymous** request download any file the web user can read, `.env` included (a product's digital-file name was stored as sent and the download joined it to a directory). Every stored file gets a data extension (the content decides when the name is not one); an SVG is refused as an image; stored names must be one plain segment; the download needs a paid order and locks after five wrong codes. Also the admin panel's side: the legacy `/admin/addon/*` routes had no permission and took the add-on's path from the request; the sitemap download and delete read and removed any file; the file manager unpacked an archive into any folder and listed the whole private `storage/app` tree; a language code such as `../../public/x` created files outside `resources/lang`; the Firebase settings were written unescaped into the service worker every visitor registers; a theme SVG passed a word-list check; "translate all" wrote translations unescaped into PHP files that every request includes; the payment gateway logo field copied any server file named in a text field into the public disk; the cookie banner ran a stored script in every visitor's session; the `.env` writer let a line break add whole lines |
| #69 | `53ba4a25` | **paid digital files** are fetchable from a public address by anyone who has or guesses the name. An opt-in switch `DIGITAL_FILES_PRIVATE` (**ships off**) keeps files on a disk the web server does not serve and streams them through the checks each route already makes; `php artisan digital-files:privatize` moves the existing files (dry run by default) |

**Files.** 73 overlay files, **5 by hand**: `.htaccess` (twice over the set: #63 and #69), `Modules/AI/app/Services/AIContentGeneratorService.php`,
`Modules/Blog/app/Http/Controllers/Admin/BlogDownloadAppController.php`, `public/assets/new/back-end/js/translate.js` (#63), `public/assets/front-end/js/custom.js` (#69; its source `resources/assets/front-end/js/custom.js` is in the package).
`config/filesystems.php` gets the private disk and `digital_files_private`. New (14 files): the `app/Utils/*` guards (`AtomicFile`, `CssScanner`, `EnvironmentFile`, `InertSvg`, `PlainFileName`, `SharedProductFile`, `SitemapXml`, `StoredFileExtension`), `app/Rules/StoredFileNames`, `app/Services/FileManager/ArchiveReport`, `DigitalFileAddressService`, `DigitalFilePrivatizer`, `DigitalProductFileService` and `PrivatizeDigitalFilesCommand`. **No migration.**
`.htaccess` changes in two ways: it denies `^\.env` and `\.php\.tmp$` (the files written beside `.env`, `system-addons.php` and an add-on's `info.php` while a settings screen replaces them) and it refuses `storage/app/private` (the private disk, read by the application only).

**`.env`:** nothing is needed. To be explicit: `DIGITAL_FILES_PRIVATE=false`. **Do not turn it on in this stage** (decision 8).

**Before the stage: look at what is already on the disk** (the upload fixes cannot remove a file that was stored before them)
```bash
find "$APP/storage/app/public" "$APP/public/uploads" \( -iname '*.php' -o -iname '*.phtml' -o -iname '*.pht' -o -iname '*.phps' -o -iname '*.phar' -o -iname '*.inc' -o -iname '*.shtml' -o -iname '*.svg' -o -iname '*.html' \) -print 2>/dev/null | head -50
ls "$APP/public/uploads/app-carousel" 2>/dev/null | head       # anything here that is not an image
ACCESSLOG=/path/to/the/web/server/access.log                     # the web server's own log, in its own format (the status code is the 9th field in the common format)
grep -iE 'digital-product-download[^ ]*(\.\.|%2e%2e)' "$ACCESSLOG" | awk '$9 == 200' | head      # a download request whose file name starts with ".." that was ANSWERED: if there is one, rotate the application key and the credentials in .env
```
and in the web server's configuration: no handler may execute PHP under `public/storage`, `storage/app/public` or `public/uploads`. **Hold the stage until every hit is explained.**

**What people will notice.** An uploaded picture is stored as a data file (`.webp`, `.jpg`…) whatever the name said; an SVG, a text file or an undecodable file is refused as an image; a digital product's variation or preview file of a type outside the allowed list (`.epub`, `.psd`) is stored as `.bin` (decision 9); a product whose stored file name holds a slash cannot be saved until the name is fixed. A digital product download needs a paid order and locks for fifteen minutes after five wrong codes (429, per order line: decision 10). The file manager no longer lists `storage/app`, never unpacks an archive outside its folder or to more than 64 MiB, and names the files it leaves out. The add-on screens need `themes_and_addons`. A language code or a `lang` header the store does not have answers in the store's default language.

**Right after the stage:** open the Firebase settings page of the admin panel and **save it once** (it regenerates the service worker with the escaped values), and re-check the theme SVGs already stored.

**Smoke tests**
```bash
for path in .env .env.tmp storage/logs/laravel.log composer.json .git/config storage/app/private/x; do printf '%-34s' "$path"; curl -s -o /dev/null -w '%{http_code}\n' "https://DOMAIN/$path"; done     # every line 403 or 404 (this is the phase 0 list, with two more lines)
art digital-files:privatize                                            # dry run: "Private mode (DIGITAL_FILES_PRIVATE): off", reports what it WOULD copy; writes nothing
```
* Admin: upload a product image that is really a text file renamed `x.jpg`: refused. Upload a real picture: stored, shown.
* Website, signed in as a customer who bought a digital product: *download* works (the order is paid); with an unpaid order it answers `status: 0`.
* Admin: `/admin/addon` as an employee without `themes_and_addons`: access denied.

**Expected in the log.** `The file <path> cannot be locked, so it is changed without a lock.` (**warning**) appears when a settings screen replaces `.env`, a language file, the licence configuration or an add-on's `info.php` on a host whose file system refuses `flock`: expected on some shared hosting, harmless, and written once per write.

### Stage 5. Content visibility (2 pull requests)

| PR | Hotfix commit | Closes |
|---|---|---|
| #40 | `eb3695a0` | an unpublished business page was served to anyone who added `?source=admin`; the page cache is cleared after the change, not before |
| #41 | `665057a5` | blog drafts and unpublished posts were shown on their public address; the admin gets a draft preview route (`GET admin/blog/draft-preview/{id}`, `marketing`) |

**Files.** 4 overlay files, **5 by hand**: `Modules/Blog/app/Http/Controllers/Admin/BlogController.php`, `Modules/Blog/app/Http/Controllers/Web/FrontendBlogController.php`, `Modules/Blog/app/Services/Frontend/FrontendBlogService.php`, `Modules/Blog/resources/views/admin-views/blog/draft-edit.blade.php`, `Modules/Blog/routes/admin/routes.php`. No migration, no `.env`, no config.

**What people will notice.** A page or a post nobody published is a *not found* for the public (the website's link of the admin's *View URL* no longer carries `source=admin`: the admin previews a draft through its own route). The app's own five-minute response cache may show an unpublished page for a few minutes.

**Smoke tests.** Public: `curl -s -o /dev/null -w '%{http_code}\n' "https://DOMAIN/business-page/<the slug of an unpublished page>"` is a redirect (302), and `…?source=admin` is the same. `https://DOMAIN/blog/<the slug of a draft>` is not found. A published page and a published post open as before.

---

## 4. The hotfix runbook (all five stages at once; "by stage" at the end)

> Written in the style of [hotfix-2.md](../integrations/hotfix-2.md) and phases 4a and 10 of the [pilot deployment](../integrations/pilot-deployment.md): the same variables, the
> same maintenance window, the same `deploy.php`. **Run these commands as `root` or as the owner of the application files.** Nothing here switches a feature on, sends
> anything to a provider or touches a credential. The hashes below are the ones of this document and are valid for them only: if the branches are rebuilt they change and
> this document is issued again.

```bash
APP=/path/to/the/application                                   # the directory that contains artisan and .env
BACKUPS=/path/outside/every/web/root/pharmacy-backups          # the same directory as for the earlier deployments
SECFIX=/path/outside/every/web/root/overlay-security           # a NEW directory (as root, /root/… is fine)
SECMAN=/path/outside/every/web/root/security-manual            # a NEW directory: the 17 files an overlay cannot carry, with their checksums
PHP=php                                                        # the PHP binary of the earlier deployments (write its full path if several are installed)
BASELINE=698aa1e74afb43eedc37fe2a683c6aae6002b8bc              # the tree the server runs (Hotfix 1)
TIP=665057a5d839f3626663dc451f88965a60e51893                   # the commit that is on the server afterwards: gcc/release/security-hotfix
PKGCOMMIT=844bd27fc439deebf0b6e9c1fd6c9f2398e7d8b3             # the commit the package is built from: gcc/release/security-hotfix-package
: "${APP:?}" "${BACKUPS:?}" "${SECFIX:?}" "${SECMAN:?}" "${DOCROOT:?}" "${PHP:?}"     # stops here if one of them is empty (DOCROOT is the one from phase 0 of the pilot deployment)
for dir in "$BACKUPS" "$SECFIX" "$SECMAN"; do
  case "$(realpath -m "$dir")/" in "$(realpath -m "$DOCROOT")"/*) echo "STOP: $dir is inside the web root" ;; *) echo "ok: $dir is outside the web root" ;; esac
done
OWNER=$(stat -c %U "$APP/artisan")                             # the user that owns the application files
art() { (cd "$APP" && sudo -u "$OWNER" "$PHP" artisan "$@"); } # without sudo: runuser -u "$OWNER" -- "$PHP" artisan "$@"; already that user: "$PHP" artisan "$@"
```

**H-0. Prove what the server runs before you change it (reads only).** The record of the last deployment does; the server's own `git` HEAD proves nothing, an overlay does not move it:

```bash
ls -t "$BACKUPS/deploy" | head -5                                # Hotfix 1's directory (…-hotfix1) is among them. A …-hotfix2 means Hotfix 2 was applied: STOP, this package is built against Hotfix 1
LAST="$BACKUPS/deploy/<the newest directory of that list>"
php -r '$m = json_decode(file_get_contents($argv[1]), true); echo $m["head"], PHP_EOL;' "$LAST/MANIFEST.json"    # the commit that deployment was built at: 698aa1e… (or 506429a…, which has the identical tree)
grep -c rolled_back_at "$LAST/applied.json"                      # must print 0: that deployment was not rolled back
cd "$APP"
grep -E '^(APP_ENV|APP_MODE|CACHE_DRIVER|QUEUE_CONNECTION|SESSION_DRIVER)=' .env      # no secrets in this list: APP_MODE live or absent; CACHE_DRIVER file, database or redis
art tinker --execute="var_dump(config('app.mode'));"             # NULL today: that is the reason every code is 123456 (stage 1, config)
grep -c setClassMapAuthoritative vendor/composer/autoload_real.php   # must print 0: the package adds classes without regenerating Composer's class map
ls bootstrap/cache/config.php 2>&1 | head -1                     # must say "No such file": a cached configuration would make config/app.php inert (NEVER run config:cache, route:cache or optimize here)
art migrate:status | tail -n 25                                  # what is pending today: we run only our three, by path
id -un; stat -c '%U:%G %a %n' artisan .env resources/lang/en/new-messages.php .htaccess Modules/AI/routes/api.php
```

The printed commit may differ from `698aa1e` by documentation only; H-3 is what proves the files.

**H-1. Get the package.** The `Deployment overlay` job of CI cannot produce it: it builds the whole branch and refuses the 17 files an overlay cannot carry (by design; do not change that job).
Build it from the package commit in a scratch clone **anywhere but `$APP`**; the 17 files are taken from the tip:

```bash
WORK=$HOME/security-hotfix-build && test ! -e "$WORK"                                       # a NEW directory
git clone -c core.autocrlf=false https://github.com/fullstackfull/lavkoo.git "$WORK/repo"   # a private repository: clone it the way you usually do
git -C "$WORK/repo" fetch origin gcc/release/security-hotfix gcc/release/security-hotfix-package
test "$(git -C "$WORK/repo" rev-parse $TIP)" = "$TIP" && test "$(git -C "$WORK/repo" rev-parse $PKGCOMMIT)" = "$PKGCOMMIT" && echo "both commits are the ones of this document"
git -C "$WORK/repo" cat-file -t 698aa1e                                                     # must print: commit
git -C "$WORK/repo" checkout --detach $PKGCOMMIT                                            # a scratch clone: nothing on the server changes
php "$WORK/repo/scripts/deploy/build-overlay.php" --repo="$WORK/repo" --base=698aa1e --head=$PKGCOMMIT --out="$WORK/overlay" --archive
cat > "$WORK/manual-files.txt" <<'EOF'
.htaccess
Modules/AI/app/Services/AIContentGeneratorService.php
Modules/AI/routes/admin/routes.php
Modules/AI/routes/api.php
Modules/AI/routes/vendor/routes.php
Modules/Blog/app/Http/Controllers/Admin/BlogController.php
Modules/Blog/app/Http/Controllers/Admin/BlogDownloadAppController.php
Modules/Blog/app/Http/Controllers/Web/FrontendBlogController.php
Modules/Blog/app/Models/Blog.php
Modules/Blog/app/Models/BlogCategory.php
Modules/Blog/app/Services/Frontend/FrontendBlogService.php
Modules/Blog/resources/views/admin-views/blog/draft-edit.blade.php
Modules/Blog/routes/admin/routes.php
Modules/TaxModule/routes/vendor.php
Modules/TaxModule/routes/web.php
public/assets/front-end/js/custom.js
public/assets/new/back-end/js/translate.js
EOF
mkdir -p "$WORK/manual-pack/files" && git -C "$WORK/repo" archive $TIP -- $(cat "$WORK/manual-files.txt") | tar -x -C "$WORK/manual-pack/files"      # the 17 files, as the TIP has them
cp "$WORK/manual-files.txt" "$WORK/manual-pack/"
(cd "$WORK/manual-pack/files" && sha256sum $(cat "$WORK/manual-files.txt")) > "$WORK/manual-pack/manual-new.sha256"                    # what they must be after the copy
(while read -r f; do printf '%s  %s\n' "$(git -C "$WORK/repo" show 698aa1e:"$f" | sha256sum | cut -d' ' -f1)" "$f"; done < "$WORK/manual-files.txt") > "$WORK/manual-pack/manual-baseline.sha256"   # what they are on the server today (appendix B)
tar -czf "$WORK/manual.tar.gz" -C "$WORK/manual-pack" . && (cd "$WORK" && sha256sum manual.tar.gz > manual.tar.gz.sha256)
```

Copy `overlay.tar.gz`, `overlay.tar.gz.sha256`, `manual.tar.gz` and `manual.tar.gz.sha256` to the server, **outside every web root**.

**H-2. Check it.**

```bash
sha256sum -c overlay.tar.gz.sha256 && sha256sum -c manual.tar.gz.sha256                     # in the directory holding the files
mkdir -p "$SECFIX" && tar -xzf overlay.tar.gz -C "$SECFIX"
mkdir -p "$SECMAN" && tar -xzf manual.tar.gz -C "$SECMAN"
(cd "$SECFIX" && sha256sum -c SHA256SUMS | grep -v ': OK$' ; echo "(nothing listed above = every file matches its checksum)")
(cd "$SECMAN/files" && sha256sum -c ../manual-new.sha256 | grep -v ': OK$'; echo "(nothing listed above = the 17 files are the tip's)")
php -r '$m = json_decode(file_get_contents($argv[1]), true); echo count($m["files"]), " files, base ", $m["base"], ", head ", $m["head"], ", migrations ", count($m["migrations"]), PHP_EOL;' "$SECFIX/MANIFEST.json"
```

Expected: `209 files, base 698aa1e74afb43eedc37fe2a683c6aae6002b8bc, head 844bd27fc439deebf0b6e9c1fd6c9f2398e7d8b3, migrations 3`. A base of `34dce65…`, `30dcbf64…` or `ef843600…` means a different, larger package was built: do not use it.

**H-3. Compare it with the files on the server (reads only).**

```bash
"$PHP" "$SECFIX/deploy.php" verify --app="$APP" --package="$SECFIX"
```

Expected, and nothing else: `Summary: 175 CLEAN, 7 MERGE, 27 NEW` and `Result: every file can be applied.` `CLEAN` means the file on the server is exactly the one Hotfix 1 installed. `MERGE` is each of the
seven language files `resources/lang/{ae,bd,en,es,in,ks,sa}/new-messages.php`: the keys this release adds are put into the live file and every key the site already has keeps the site's wording. `NEW` is a file that does not exist on the server yet.
A `DRIFTED` file means someone changed it since Hotfix 1: read what they changed (`diff -u "$SECFIX/base/<path>" "$APP/<path>"`), and accept it by name (`--accept-drift=…`) **only after a person has looked, and never for a language file**: accepting a drift
**replaces** the live file with the release's version (the live one is backed up first), so what the site changed in it is gone.

The 17 files that an overlay does not carry are compared by hand against the checksum of **their baseline** (appendix B has the table):

```bash
cd "$APP" && sha256sum -c "$SECMAN/manual-baseline.sha256" 2>&1 | grep -v ': OK$'     # nothing listed = all 17 are the files Hotfix 1 installed
```

(`manual-baseline.sha256` is the table of appendix B, one `checksum  path` line per file; H-1 made it from the baseline commit.) A file listed here was changed on the server: read the difference first, and do not copy over it blindly.

> **Gate A1, for this package.** Nothing here switches anything on. The backup of H-4 is made and proven, no `DRIFTED` file is unexplained, **the owner has answered the decisions of section 6 that stage 1 depends on (1, 3)**, and the roles of decision 5 are prepared. The migration of H-5 deletes the codes in flight: choose a quiet hour.

**H-4. Back up, then put the files on the server (about two minutes; the database is not touched).** The backup first: it is the restore point of everything below, and the database part of it is the only way back for the data of H-5.

```bash
bash "$SECFIX/tools/backup.sh" --app="$APP" --to="$BACKUPS/data"          # prints the directory it made
bash "$SECFIX/tools/verify-backup.sh" --backup=<the directory backup.sh printed> --app="$APP"    # reads the backup only; copy it off the server (pilot deployment, phase 2)
```

**The overlay first, the 17 files second, and why:** `apply` checks everything before it writes (a refusal changes nothing), writes atomically with a backup of every original and takes everything back when it fails;
the 17 files are one short loop with their originals saved first. If the overlay is refused or fails, no hand-copied file has been touched; if the loop fails, `rollback` and the saved originals restore the lot. The other order could leave
new route files that name controllers or middleware the old tree does not have, if the overlay then failed. The site is in maintenance mode for both, so no request sees the half-way state. Use `php artisan down`, **not** the admin panel's maintenance switch (that one sends a push notification to every user).

```bash
STAMP=$(date -u +%Y%m%d-%H%M%S)
"$PHP" "$SECFIX/deploy.php" apply --app="$APP" --package="$SECFIX"                      # dry run: prints the report, changes nothing
art down --retry=60 \
  && "$PHP" "$SECFIX/deploy.php" apply --app="$APP" --package="$SECFIX" --yes --backup-dir="$BACKUPS/deploy/$STAMP-security" \
  && MANUAL_BACKUP="$BACKUPS/deploy/$STAMP-security-manual" \
  && while read -r f; do mkdir -p "$MANUAL_BACKUP/$(dirname "$f")" && cp -p "$APP/$f" "$MANUAL_BACKUP/$f" && cat "$SECMAN/files/$f" > "$APP/$f"; done < "$SECMAN/manual-files.txt" \
  && (cd "$APP" && sha256sum -c "$SECMAN/manual-new.sha256" | grep -v ': OK$'; echo "(nothing listed above = the 17 files are the tip's)") \
  && art optimize:clear && art queue:restart && art --version
```

Keep the `[next] To undo:` line `apply` prints. `cat … >` writes into the existing file, so its owner, group and mode stay. Only when the chain ended with the Laravel version line: if `opcache.validate_timestamps` is off, reload PHP-FPM or reset OPcache now (the web server would otherwise keep running Hotfix 1's classes beside the new files), then go on to H-5 (**still in maintenance mode**). If any step of the chain failed, do not run H-5: read the failure table of [4a](../integrations/pilot-deployment.md#4a-the-files-about-two-minutes-the-database-is-not-touched), take the lot back (section 5) and `art up`.

**H-5. The three data migrations (about a second; the data they empty is in the backup of H-4).** Only these three, by path, in this order. **Never a bare `php artisan migrate`.**

```bash
art migrate --path=database/migrations/2026_11_26_100000_purge_customer_and_delivery_man_reset_proofs.php \
            --path=database/migrations/2026_11_26_100100_purge_seller_reset_proofs.php \
            --path=database/migrations/2026_11_26_100200_clear_seller_remember_tokens.php
#   (APP_ENV=production asks "Do you really wish to run this command?": answer yes. --force only for a non-interactive shell.)
art optimize:clear && art queue:restart && art migrate:status | grep 2026_11_26 && art up
```

**H-6. Read-only checks.**

```bash
art tinker --execute="var_dump(config('app.mode'));"                          # string(4) "live"
art tinker --execute="echo DB::table('phone_or_email_verifications')->count(), ' ', DB::table('password_resets')->whereIn('user_type', ['customer','delivery_man','seller'])->count(), ' ', DB::table('sellers')->whereNotNull('remember_token')->count();"   # 0 0 0, at this moment (somebody may already have asked for a code: a small number is not a failure)
art route:list --path=barcode/sheet                                           # GET api/v3/seller/products/barcode/sheet
(cd "$APP" && sha256sum -c "$SECMAN/manual-new.sha256" | grep -v ': OK$'; echo "(nothing listed above = the 17 files are the tip's)")
tail -n 50 "$APP/storage/logs/laravel.log"                                    # nothing new that is an exception; see the "expected log lines" of each stage
```

**H-7. Look at the site and run the smoke tests of section 3** (stage 1 to stage 5), in that order. They read or are refused; none writes.

**H-8. Do what the stages ask for after the files are in** (and only these):
* `rm -f "$APP/storage/app/public/product/barcode.pdf"` (stage 3). Save the Firebase settings page of the admin panel once and re-check the stored theme SVGs (stage 4).
* The web-server checks of stage 4 (no handler executes PHP under `public/storage`, `storage/app/public`, `public/uploads`).
* **Stop here.** The following are decisions of the owner and none is part of this deployment: turning `DIGITAL_FILES_PRIVATE` on and moving the digital files (decision 8), extending the trunk-zero list for other countries (decision 4), anything that merges these pull requests on `main`.

**Send us**, in this order: the H-0 head line and the `config('app.mode')` line; the H-3 `Summary:` and `Result:` lines and the manual checksum result; the H-4 `[ok]` and `To undo:` lines; the H-5 migration output;
the H-6 outputs; what the smoke tests showed (a screenshot is fine; no customer data).

### By stage

Shipping the stages one at a time uses the **same steps** with a package per stage. The loop below builds them: the package of stage *n* is built from the tip of stage *n−1* (for stage 1: `698aa1e`),
and its package commit puts the stage's out-of-scope files back to what the previous stage left on the server, so that `build-overlay.php` accepts the rest.

```bash
cd "$WORK/repo" && git checkout --detach $TIP
prev=698aa1e74afb43eedc37fe2a683c6aae6002b8bc
for pair in "1 779a3e5046b716474f1e889a4681260549b744e7" "2 b7842fa1f3462bac3d059fa8be5dbf96ed8dc953" "3 6263b8b398739812c9cd10001aa61a391a0fc3d9" "4 53ba4a2591a33be63eeee35d91ac75bcbf610f4d" "5 665057a5d839f3626663dc451f88965a60e51893"; do
  set -- $pair; n=$1; tip=$2
  manual=$(php scripts/deploy/build-overlay.php --repo=. --base=$prev --head=$tip --out="$WORK/probe-$n" 2>&1 | sed -n 's/^  - \(.*\) changes, and an overlay.*/\1/p')   # the refusal names the stage's files by hand (none for stage 1: then the probe is the package)
  rm -rf "$WORK/probe-$n"
  git checkout -q --detach $tip
  [ -n "$manual" ] && git checkout -q $prev -- $manual && git -c user.name=build -c user.email=build@localhost commit -q -m "package stage $n"      # a scratch commit in the scratch clone only
  php scripts/deploy/build-overlay.php --repo=. --base=$prev --head=$(git rev-parse HEAD) --out="$WORK/overlay-$n" --archive
  mkdir -p "$WORK/manual-$n" && [ -n "$manual" ] && git archive $tip -- $manual | tar -x -C "$WORK/manual-$n"   # the stage's files by hand, as the stage's tip has them
  git checkout -q --detach $tip && prev=$tip
done
```

Built this way (measured): stage 1 **50 files, 3 migrations**; stage 2 **97 files, 4 by hand**; stage 3 **19 files, 4 by hand**; stage 4 **73 files, 5 by hand**; stage 5 **4 files, 5 by hand**. Then, for every stage in turn:
H-2 and H-3 with that stage's package (the base in `MANIFEST.json` is the previous stage's tip; the files by hand are compared with what the previous stage left, `git show <previous tip>:<path> | sha256sum`),
H-4 with `$STAMP-security-s<n>` as the backup directory, H-5 for stage 1 only, H-6, and the smoke tests and the "before the stage" list of that stage. **Wait for the stage's smoke tests and a day of the log before the next stage**; a stage is taken back (section 5) before the next one is applied, never after.
The commit that is on the server after stage *n* is the tip of stage *n*; stage 5's tip is `$TIP`.

---

## 5. Rollback

### The files

Newest first, with the site in maintenance mode (`art down --retry=60`):

```bash
"$PHP" "$SECFIX/deploy.php" rollback --backup-dir="$BACKUPS/deploy/$STAMP-security"       # the directory apply printed: restores Hotfix 1's version of every file, removes the files this release added, and takes back only the language keys it added (the site's own keys stay)
while read -r f; do cat "$BACKUPS/deploy/$STAMP-security-manual/$f" > "$APP/$f"; done < "$SECMAN/manual-files.txt"     # the 17 files, from the copies H-4 saved
(cd "$APP" && sha256sum -c "$SECMAN/manual-baseline.sha256" | grep -v ': OK$'; echo "(nothing listed above = the 17 files are Hotfix 1's again)")
art optimize:clear && art queue:restart       # then reload PHP-FPM or reset OPcache as in H-4, and:
art up
```

If the application does not start after the files landed (for example *Class not found*), `art up` cannot run either: run the two restore steps above, then `rm storage/framework/down` to leave maintenance mode. The rollback is checked on a scratch copy of the baseline tree: apply, then rollback, gives back a tree that is byte for byte the baseline's except two language files that hold the same array written again (section 1B).
**By stage:** roll back the newest stage first, each with its own backup directory, and each stage's files by hand from its own `-manual` directory. If the manual files of two stages share a path (`Modules/AI/routes/vendor/routes.php`), restoring in newest-first order gives each stage's own previous version.

### What cannot be undone, and what that means

| What | Why | Consequence |
|---|---|---|
| **The three data migrations** (`2026_11_26_100000`, `…100100`, `…100200`) | they delete rows of `phone_or_email_verifications` and `password_resets` and null `sellers.remember_token`; their `down()` restores nothing, on purpose | after a rollback of the files the old code runs on emptied tables: people ask for new codes, sellers sign in again. The rows are in the H-4 database dump; **restoring that dump is the last resort** and it discards everything ordered since (pilot deployment, section 10, the row *The database itself was damaged*). The rows were codes in flight and planted proofs: nothing worth restoring |
| Passwords **reset** after the release | the reset changed the password and deleted the customer's tokens | nothing to undo: the customer chose the password; the sign-out of their app is not restored |
| Seller sessions ended by a **status change** (#44) | the hook rotates `auth_token` and `remember_token` when `status` changes | not restored, and not wanted |
| Reset links and the "choose your password" links of panels, the point of sale, admins | deleted by the migration | the customer uses *forgot password* |
| **Digital files**, if `DIGITAL_FILES_PRIVATE` was turned on and `digital-files:privatize --execute --remove-public` was run | the command deletes the public copy after a verified copy; nothing copies it back | **never run `--remove-public` in the same window as this release** (decision 8). With `--execute` alone the public copies stay, and turning the switch off restores the old behaviour at once. The private copies stay on disk (`storage/app/private/digital`) and are harmless |
| `storage/app/public/product/barcode.pdf` (stage 3) | deleted by hand | the old code writes it again on first use |
| Files a seller or an employee uploaded **after** the release | stored with their data extension | they stay and keep working |
| The theme SVGs, the Firebase service worker | the settings page rewrote the service worker | rewrite it again from the same page after a rollback |

**The safe reversible order for the whole hotfix:** (1) take the backups (H-4); (2) deploy the files and run the smoke tests; (3) **only then** anything that is not reversible: stage 1's migrations are the one irreversible step and they come
last in the window, after the files have started and been checked in maintenance mode; (4) leave `DIGITAL_FILES_PRIVATE` off and never combine the move of the digital files with this release; (5) if something fails before the migrations, `rollback` loses nothing; if it fails after them,
`rollback` returns the code and leaves the emptied tables, which the old code tolerates.

---

## 6. Decisions only the owner can take (they block or shape the release)

| # | Decision | Why it matters | Safe default until it is taken |
|---|---|---|---|
| **1** | **The verification codes.** Deploy `config('app.mode')` (commit `779a3e50`), so that `APP_MODE=live` makes every code random? And what does `.env` say today (H-0 prints it)? | Without it **every code is the constant `123456`/`1234` on this server**: the five account-takeover fixes then leave "know a phone number, type 123456" open, and the owner guide's "production must run with `APP_MODE=live`" cannot be met by `.env` alone on this tree. With it, a test phone, a store-review account or anyone relying on `123456` stops working; a server whose `.env` says `demo` or `dev` keeps the constant codes and gets the 47 demo-mode refusals | **Deploy it** (it is the owner's own checklist item); it is a separate commit and can be left out by dropping the 9th commit and its package file `config/app.php`. This is a **security** decision, not a financial or a legal one |
| **2** | **A or B** (section 1), and in one go or by stage | A ships 395 commits that have never run on this server; B ships the 24 fixes and one config line | B, by stage if the owner wants to watch each one |
| **3** | **`lavkooapp#4` first** (customer app), and **the delivery-man app** calling `verify-otp` before `reset-password` (it is not in these repositories; the third commit of #62, `e9352502`, depends on it and is inside the single net commit here) | an older customer build only loses the "is this your account?" sheet; **a delivery-man build that does not call `verify-otp` first cannot reset a password after stage 1** | publish the customer app first; confirm the delivery-man app before stage 1, or hold stage 1 |
| **4** | **Customers and sellers whose stored phone keeps the trunk zero after a non-Gulf code** (Syria `+963`, Egypt, Jordan … the list of the pull request covers 18 codes) and **duplicates** (two accounts, one phone or e-mail) | a duplicate cannot reset by that phone or e-mail; a number the list does not cover cannot reset by Firebase (it reads as another number) | run the SQL of stage 1 first; fix duplicates by hand; extend the list as a separate, tested change |
| **5** | **The roles that lose access** with #65, #67 and #73 (stage 3 table) and the restock toast of #73's last commit (an `orders` role without `catalog` loses it; the commit was droppable on its own in the pull request, here it is in the net change) | an employee who uses those screens today without the key gets *access denied* | add the keys to the roles **before** stage 3; stage 3 is not shipped until the owner has the list from the two SQL queries |
| **6** | **Finance review of #52** (payouts, withdrawals, order edits by sellers and staff) | #52 changes who may read and act on money rows; and the server now refuses the edit of a delivered order (the round-3 finding). Financial decisions are the owner's | stage 2 waits for the finance owner's reading of #52's body and of the seller-app audit rows 8, 10, 15, 23 |
| **7** | **#52's behaviour changes** the owner must accept: staff cannot change the owner's password or profile; staff phones do not register a push token; admin-assigned seller-bearer coupons answer 403 on edit; a refusal of an order edit rides HTTP 200 `status: error` and the seller app closes the screen as if saved (`lavkooappseller#3` fixes the app side); orders of seller id 1 leave a seller's counts | listed in the seller-app audit (2026-11-17) | accept, or hold stage 2 |
| **8** | **`DIGITAL_FILES_PRIVATE` stays off**, and moving the files is **a separate step**: `php artisan digital-files:privatize` (dry run), then `--execute`, then the switch, then `--remove-public` much later | the two Flutter apps must call the new authenticated routes before the switch is turned on (`GET api/v1/customer/order/digital-product-download/{id}/{fileName?}`, `api/v2|v3/seller/…/digital-file/…`); `--remove-public` is not reversible | off; never in the same window |
| **9** | **#63's own open points**: which file types a digital product may keep beyond the list (a variation or preview file of another type, such as `.epub` or `.psd`, is stored as `.bin`); the theme SVGs already stored; the add-on installer and the software updater run PHP by design (not changed); about 80 admin routes still carry no module permission ([audit](audits/2026-11-16-admin-routes-without-module-permission.md)) | none blocks the release | accept as shipped |
| **10** | **The digital-download lock** (five wrong codes lock the order line for 15 minutes): a stranger who knows an order line id can lock the real customer's download | the cost of a 4-digit code | accept, or key it by caller as well (a follow-up) |
| **11** | **The server's own checks** before stage 4 (no PHP executed under `public/storage`, no script or page already stored, the log shows no `..` name answered); `APP_KEY` against the public example key (pilot deployment, D1) | a file stored before the fix is still there; if the log shows the download endpoint answering a name that starts with `..`, **rotate the application key and the credentials in `.env`** | hold stage 4 until the answers are in |
| **12** | **A finding that exists only on the baseline** (section 7): `RestAPI/v1/CustomerController::update_address` finds the row by `customer_id` and `id` without `is_guest`: a guest whose guest id equals a customer's id can overwrite that customer's saved address (low; `main` scopes it) | the same class as #54 | one line to add if the owner wants it in the hotfix |
| **13** | **Hotfix 2** (the Torod Point orders map) is prepared and not deployed, and is built against the same baseline | both change `routes/admin/routes.php` and the language files | whichever goes second is rebuilt against the first's tip |
| **14** | CI will show the `Deployment overlay` job **red** on a pull request of the hotfix branch: it refuses the 17 files, which is its job. The job and the deploy scripts are not touched | none | expected |

---

## 7. What is verified and what is not

**Repeated by the lead on the pushed branches (2026-11-17, before this plan was published).** The commit identities, trailers and the absence of model names were checked on all 26 commits; `php -l` on the 383 changed PHP files: 0 errors; `leak-guard.php --base=698aa1e`: nothing added; `build-overlay.php` on the tip refuses exactly the 17 files of appendix B (the same list), and on the package commit `844bd27f` it builds **209 files** (182 modified, 27 added); `deploy.php verify` against a fresh `git archive` of `698aa1e`: **175 CLEAN, 7 MERGE, 27 NEW, every file can be applied**. The whole-suite runs below are the worker's. The draft pull request [#78](https://github.com/fullstackfull/lavkoo/pull/78) runs the project's own CI on the same tip, with the baseline's own workflow (PHP 8.2 and 8.3 on SQLite, MySQL 8, the syntax and secret checks); its result is the independent second source and is read on that page.

### The full runs on the final tree (tip `665057a5`, tree `4b53b15b2628c4141073c491974fd73193245843`)

| Mode | Scope | Result |
|---|---|---|
| SQLite, as root, `APP_DEBUG=true` (the CI configuration), final tree | the whole suite (`tests/Unit` and `tests/Feature`), on a private copy of the tree | **5642 tests, 23953 assertions: 1 failure, the known false one:** `LeakGuardTest::test_every_name_rule_has_a_matching_gitignore_entry…` runs `git` and the private copy has no `.git` (the same class in the worktree, which has a `.git`: 110 tests, 220 assertions: OK). 55 skipped, 8 PHPUnit deprecations. On the tree before the one-file test change, run in the worktree: 5642 tests, 23980 assertions: OK †; the 27 assertions of difference are that one test |
| SQLite, as root, default environment (`APP_DEBUG` unset), final tree | the whole suite, on a private copy of the tree | **5642 tests, 23953 assertions: 1 failure, the same known false one**; 55 skipped, 8 PHPUnit deprecations. On the earlier tree: 5642 tests, 23980 assertions: OK † |
| SQLite, as the unprivileged user (`nobody`), `APP_DEBUG=true`, final tree | the whole suite, on a copy of the tree owned by that user (CI's runner is not root) | **5642 tests, 23909 assertions: 1 failure, the same known false one** (the copy has no `.git`); 66 skipped (the 11 extra skips need root); 8 deprecations. The earlier tree gave the same numbers † |
| MySQL 8.0.46, fresh databases | `tests/Feature/Security` (105 classes, 3,813 tests), as three runs of 35 classes | **chunk 1: 1077 tests, 3854 assertions: OK †; chunk 2: 1418 tests, 7582 assertions: OK †; chunk 0, first run: 1318 tests, 14 failures**, all 14 in the new `TrackOrderByCustomerPhoneTest` (the stand-in table of the test, explained below; fixed); **chunk 0 again on a fresh database with the final tree: 1318 tests, 3750 assertions: OK** (so all 3,813 tests of the directory have passed on MySQL). Each run: 1 PHPUnit deprecation, none skipped. Includes the 13 tests that SQLite skips for its collation and its column lengths (`SearchVisibilityTest`, `SellerRegistrationGatesTest`, `SocialLoginConfirmationTest`): they ran and passed |
| MySQL 8.0.46, fresh database, final tree | `tests/Feature/Security/TrackOrderByCustomerPhoneTest.php` after the fix | **15 tests, 31 assertions: OK** (SQLite as root with `APP_DEBUG=true`: the same 15 tests, 31 assertions: OK) |
| MySQL 8.0.46 † | the baseline's own suites as `main`'s MySQL jobs run them: `tests/Unit/Fulfillment`, `tests/Feature/Fulfillment`, `tests/Feature/CiDatabaseTest.php` | **1308 tests, 7297 assertions: OK**; 3 skipped |
| MySQL 8.0.46 † | the other directories the branch adds tests to: `tests/Feature/Cart`, `Payments`, `Referral`, `Web`, `Blog`, `tests/Unit/Security`, `Http`, `Utils` | **309 tests: 293 pass, 16 error**, all 16 in `tests/Feature/Web` and `tests/Feature/Blog` (the tests of #40 and #41): their schema helper creates tables without emptying the database first, so on a real server they stop with *Table 'business_settings' already exists*. They are SQLite-only tests, and `main`'s MySQL and MariaDB jobs do not run these directories either; they pass on SQLite |

The baseline's own suites (`tests/Feature/Fulfillment`, `tests/Unit/Fulfillment`, `tests/Unit/Deploy`, `tests/Feature/Security/AdminRouteAuthenticationTest.php`) pass on the hotfix tree as they do on `698aa1e` (218 tests of the Security and Deploy suites on the untouched baseline: OK, 1 skipped).
No test failed on the hotfix that passes on the baseline.

**A finding of the MySQL runs, fixed in the commit of #62.** The first MySQL run of `tests/Feature/Security` (three runs of 35 classes each) failed 14 of the 15 tests of the new `TrackOrderByCustomerPhoneTest` with *Unknown column 'order_due_amount'*: the test's stand-in `order_edit_histories` table lacked the four columns that the tracking page reads through `Order::latestEditHistory()`. SQLite does not notice (it reads a double-quoted unknown column as a string, and the comparison holds), MySQL does. It was a flaw of the test's schema, not of the production code. The stand-in table now has `order_due_amount`, `order_due_payment_status`, `order_return_amount` and `order_return_payment_status`; the commit of #62 and the commits after it were re-created with that one file changed (every commit differs from its first version in that file only), and the 15 tests pass on SQLite and on a fresh MySQL database. Rows marked **†** were run on the tree before that change (tree `473e6171874e4720357075ca3dfb5714aed3b778`): its production files are identical to the final tree's, and those runs did not load the changed file (the two MySQL chunks and the two other MySQL rows) or loaded its earlier version, which passes on SQLite (the whole-suite figures given as the earlier tree's). What was run on the final tree: the whole suite in the three SQLite modes, chunk 0 of the Security directory on a fresh MySQL database (1,318 tests, 3,750 assertions: OK), the changed file on SQLite and on a fresh MySQL database, the five test files of #62 on the re-created #62 commit (green: 122 tests, 621 assertions; red without its production change: 77 of 122), the mutation pass, the overlay build and `verify`, the dry-run merges.

### What every pull request's tests did (red on the old code, green on the port)

Each pull request's tests were put in the tree first and run with the production change taken out (red), then with it (green). "Failing" counts failures and errors of the run on the previous tip.
Counts of tests overlap where two pull requests share a test file (`AdminRouteTableTest`), so they do not add up to the totals above. The red run of #62 was repeated on the final history after the change described above: 77 of 122 (3 errors, 74 failures), the same.

| Change | Hotfix commit | Tests of the change | Failing on the previous tip (red) | On the port (green) |
|---|---|---|---|---|
| #44 | 4ad858bd | 70 (4 skipped on SQLite) | 41 of 70 (4 errors, 37 failures) | 70 passed |
| #61 | bb9f7448 | 12 | 6 of 12 | 12 passed |
| #62 | e0006053 | 122 (107 of the pull request, 15 new) | 77 of 122 (3 errors, 74 failures) | 122 passed |
| #58 | c0007bb4 | 62 | 36 of 62 (1 error, 35 failures) | 62 passed |
| #59 | 5627bd5b | 39 | 26 of 39 | 39 passed |
| #45 | 8edd8d8c | 41 (9 skipped on SQLite) | 27 of 41 | 41 passed |
| #60 | 69279a0a | 22 | 21 of 22 | 22 passed |
| #57 | bd3284a0 | 10 | 6 of 10 | 10 passed |
| config | 779a3e50 | 3 (new) | 1 of 3 | 3 passed |
| #46 | 0974b562 | 84 (1 skipped) | 53 of 84 | 84 passed |
| #52 | fb258400 | 713 | 475 of 713 (7 errors, 468 failures) | 713 passed |
| #51 | b4b0fd7a | 493 | 211 of 488 that run (134 errors, 77 failures) | 493 passed |
| #54 | b135da24 | 12 | 7 of 12 | 12 passed |
| #56 | e0e7c517 | 19 | 15 of 19 | 19 passed |
| #55 | b7842fa1 | 2 | 2 of 2 (errors) | 2 passed |
| #65 | 6de74670 | 32 | 28 of 32 | 32 passed |
| #66 | 7cbfa55b | 8 | 8 of 8 (2 errors, 6 failures) | 8 passed |
| #67 | 15936e4e | 99 | 57 of 99 | 99 passed |
| #70 | c8ba4388 | 258 | two files stop the process with a fatal error (the old result template declares its highlight function in the view); of the others 54 of 54 and 17 of 28 | 258 passed |
| #73 | 01c8228d | 135 | 21 of 135 (1 error, 20 failures) | 135 passed |
| #68 | 6263b8b3 | 343 | 118 of 283 in the four files that finish; the barcode-sheet file does not finish in two minutes (the old code builds a sheet of any quantity) | 343 passed |
| #63 | 435b9385 | 1391 | 1217 of 1391 (750 errors, 467 failures) | 1391 passed |
| #69 | 53ba4a25 | 277 | 169 of 277 (71 errors, 98 failures) | 277 passed |
| #40 | eb3695a0 | 9 | 6 of 9 | 9 passed |
| #41 | 665057a5 | 7 | 6 of 7 (3 errors, 3 failures) | 7 passed |

### Static checks (the whole branch against the baseline)

* `php -l` on the committed version of all **383** PHP files the branch adds or changes (357 plain files and 26 Blade templates; the other 5 files it changes are three JavaScript files, `.htaccess` and `.env.example`): **0** errors. All **26** Blade templates it adds or changes compile with the application's Blade compiler and the result lints: **0** errors.
* A symbol check written for this port (`nikic/php-parser` over every changed file: imports, `new`, static calls, class constants, `$this->` calls, global functions, resolved against the hotfix tree's own autoloader) finds **0** reference that exists only on `main`, over every production PHP file changed (it prints 79 problems for the same files at the baseline, and the same 79 for the hotfix: the Auction module that this repository does not contain, a few imports of classes that do not exist; none is new).
* **PHP 8.2:** only PHP 8.3 exists locally, and CI runs both. All 383 files parse as PHP 8.2 (`php-parser` in 8.2 mode) and none uses `json_validate`, `mb_str_pad`, `mb_trim`, `#[\Override]`, a typed class constant, `Randomizer::getBytesFromString` or another 8.3-only function; `mb_substr()` is used twice (in `IntegrationsPanel.php` and `ProductService.php`) in lines the branch did not add. The code is the pull requests' code, which CI ran on both.
* `php scripts/ci/leak-guard.php --base=698aa1e`: *nothing that looks like a secret or personal data was added.*
* **Equivalence with the integration of the whole set on `main`** (`gcc/release/security-integration`, `47b78ab8`): of the 230 production files the set changes, 207 are byte for byte the same file; the other 23 are the files `main` itself changed since the baseline; 12 of them become the integration's file exactly when `main`'s changes are applied (`git merge-file`), and for the other 11 every line that differs is a line `main` added or removed, except one constructor line.
* **Every non-blank line a pull request adds to a production file is present in the final tree**: 5,680 lines checked pull request by pull request; 9 are not, and each is explained: eight are lines that a later pull request of the set rewrites (the same line is changed twice: #44 and #52 in `shop_info()`, #52 and #68 in two files, #67 and #73 in a comment, #63 and #69 in the file-download lines), and one is the constructor line of `CustomerController`. Every language line is present (the six relocated *not found* texts are in `new-messages.php`).
* A route table dumped from the baseline tree and from the hotfix tree (1,960 and 1,974 routes) differs by the **16 added** and **2 removed** routes of section 1B and by **95 routes whose middleware changed**; no route changed its action.

### On the deployment tooling

* `build-overlay.php` on the tip refuses **exactly the 17 files** of appendix B and nothing else. On the package commit it builds **209** files; the package's own checksums verify.
* `deploy.php verify` against a scratch copy of the baseline tree: **175 CLEAN, 7 MERGE, 27 NEW**, `every file can be applied`. `apply` on a scratch copy: 209 files written, 202 identical to the release's, 7 language files merged. `rollback`: 209 files restored, the tree byte for byte the baseline except two language files that hold the same array written again. Nothing was applied to a real tree.
* The five stage packages were built with the loop of section 4 from the commits of section 2: 50, 97, 19, 73 and 4 files (3, 0, 0, 0 and 0 migrations). **Applied one after the other on a scratch copy of the baseline tree**, each stage's `verify` answers `every file can be applied` (stage 1: 36 CLEAN, 7 MERGE, 7 NEW; stage 2: 87 CLEAN, 7 MERGE, 3 NEW; stage 3: 16 CLEAN, 3 NEW; stage 4: 56 CLEAN, 3 MERGE, 14 NEW; stage 5: 3 CLEAN, 1 MERGE), and the result equals the single package's: the same 209 files, the seven language files equal as arrays (7,802 keys in `es`, no value different; only the order of the keys differs). Rolled back newest first, the tree equals the baseline's git objects for all 14,043 files **except** `resources/lang/bd/new-messages.php` and `resources/lang/in/new-messages.php` (the same arrays written again).

* After the commits were re-created (the finding above) the package was **built again** from the new package commit: its 209 files are byte for byte those of the first build (`diff -r` of the two `files/` directories; the manifests differ only in `head` and `built_at`), `verify` against a fresh scratch copy of the baseline gave the same **175 CLEAN, 7 MERGE, 27 NEW**, the refusal output of the tip is byte for byte the first one (17 files), and the five stage packages again have 50, 97, 19, 73 and 4 files. `apply`, `rollback` and the one-after-the-other application above were run on the first build, which is the same set of files.

### Mutation pass (the lines that are not a verbatim port)

One production line at a time was changed with a script, the tests that should notice it were run, and the file was restored from a copy (the worktree was clean afterwards). The lines chosen are the ones that are **not** a verbatim port: the adaptations, the new test's subject and the added language keys.

| # | Mutation | File | Result |
|---|---|---|---|
| 1 | the app.mode line removed | `config/app.php` | **killed** (Tests: 3, Assertions: 24, Failures: 1) |
| 2 | the default mode changed to dev | `config/app.php` | **killed** (Tests: 3, Assertions: 25, Failures: 1) |
| 3 | the key renamed | `config/app.php` | **killed** (Tests: 3, Assertions: 24, Failures: 1) |
| 4 | the is_string guard of the typed phone disabled | `app/Http/Controllers/Web/UserProfileController.php` | **killed** (Tests: 15, Assertions: 30, Failures: 1) |
| 5 | the owner of the order is nobody | `app/Http/Controllers/Web/UserProfileController.php` | **killed** (Tests: 15, Assertions: 19, Failures: 5) |
| 6 | any typed phone opens the order of its owner | `app/Http/Controllers/Web/UserProfileController.php` | **killed** (Tests: 15, Assertions: 25, Failures: 12) |
| 7 | a piece of the phone opens the order again | `app/Http/Controllers/Web/UserProfileController.php` | **killed** (Tests: 15, Assertions: 21, Failures: 8) |
| 8 | the seller profile no longer shows the bank account | `app/Http/Controllers/RestAPI/v2/seller/SellerController.php` | **killed** (Tests: 31, Assertions: 101, Errors: 1) |
| 9 | the orders count no longer excludes the in-house orders | `app/Http/Controllers/RestAPI/v2/seller/SellerController.php` | **killed** (Tests: 31, Assertions: 109, Failures: 1) |
| 10 | the unavailable-product check of the point of sale removed | `app/Http/Controllers/RestAPI/v3/seller/POSController.php` | **killed** (Tests: 19, Assertions: 55, Failures: 9) |
| 11 | the settlement scope import broken | `app/Utils/module-helper.php` | **killed** (Tests: 19, Assertions: 5, Errors: 14, Failures: 5) |
| 12 | the digital address service not injected | `app/Http/Controllers/RestAPI/v1/CustomerController.php` | **killed** (Tests: 118, Assertions: 276, Failures: 2) |
| 13 | the profile key removed again from en | `resources/lang/en/new-messages.php` | **killed** (Tests: 10, Assertions: 66, Failures: 1) |
| 14 | a relocated not-found text removed from en | `resources/lang/en/new-messages.php` | **killed** (Tests: 13, Assertions: 90, Failures: 1) |
| 15 | a relocated not-found text removed from sa | `resources/lang/sa/new-messages.php` | **killed** (Tests: 13, Assertions: 89, Failures: 1) |

**Killed** 15, survived 0, skipped 0; worktree after the pass: clean (the pass was run on the final tree, `665057a5`, with the same test counts as on its first run). The code and the tests of the 24 pull requests are the pull requests' own and were not mutated again; this pass covers what the port adds.

### Dry-run merge

`git merge-tree --write-tree --name-only` of the hotfix tip with `origin/main` and with each of the 43 `gcc/security-*`, `gcc/p1-*` and `gcc/fix-*` branches that exist as remote-tracking refs in this repository (44 merges, nothing fetched): **all 44 conflict**, in the files where the port differs from `main`: the nine controllers and helpers the baseline and `main` differ in (44 of 44: `RestAPI/v1/ConfigController`, `RestAPI/v1/CustomerController`, `RestAPI/v1/OrderController`, `RestAPI/v3/seller/OrderController`, `RestAPI/v3/seller/POSController`, `Vendor/Order/OrderController`, `Web/UserProfileController`, `Web/WebController`, `app/Utils/module-helper.php`), `app/Services/Fulfillment/Support/SaudiPhone.php` (42 of 44) and `tests/Feature/Security/AdminRouteAuthenticationTest.php` (41 of 44); the ten branches that carry the storefront chain add `app/Utils/CartManager.php`, `app/Services/Search/ProductSearchService.php` and the two cart controllers, and a few more files conflict in one to seven branches (the language files of `bd`, `in` and `ks`, `routes/admin/routes.php`, a handful of ported tests). **That is the expected answer for a deployment branch built on the baseline: it is not meant to be merged into `main`** (section 1).

### Not verified (it needs the live server, the devices or the engines that do not exist here)

* **The server itself**: its PHP version and OPcache, its queue and cron, its web-server configuration (`.htaccess` is read by Apache only; under nginx the `.htaccess` lines do nothing), its drift in any of the 209 + 17 files. `deploy.php verify` against the real tree is what proves the files.
* **MariaDB 11.4** (CI runs it next to MySQL 8; it is not available here). The three migrations are data only (`Schema::hasTable`, `Schema::hasColumn`, `DB::table()->delete()` and `->update()`), with no schema and no engine-specific SQL; the tests that depend on a case-insensitive collation ran on MySQL 8 and are skipped on SQLite.
* **PHP 8.2** was not run, only parsed and scanned (above). CI runs it.
* **The two Flutter apps and the delivery-man app**: nothing was run on a device. The compatibility statements are the ones of the 2026-11-17 audits.
* **Real SMS, Firebase and social-login providers**: every provider call in the tests is faked. Whether the codes are delivered, and whether Firebase's answer matches the phone the way the tests assume, is for the smoke tests.
* **A real tree**: every apply and rollback above was done on a scratch copy of the baseline made with `git archive`. The server's own files, ownership and modes are what H-3 and `verify` look at.
* The things the pull requests themselves list as open (the vendor's whole row in public product payloads, three low write doors, the add-on installer running PHP by design, about 80 admin routes without a module permission) are not changed by the port.

### What the baseline lacks that makes a fix incomplete there (found while porting)

1. **`config('app.mode')` does not exist on the baseline** (`config/app.php` has no `mode`), so `APP_MODE=live` in `.env` changes nothing and every code is a constant. `main` defines it (`352ed688`). Closed here by the 9th commit; decision 1.
2. **`RestAPI/v1/CustomerController::update_address`** finds the saved address by `customer_id` and `id` without `is_guest`: a guest whose guest id equals a customer's id can overwrite that customer's address and move the row to the guest. The list and the delete of the same controller scope by `is_guest`, and `main`'s address book does too. The class of #54, not in the set; low; decision 12.
3. **The guest branch of the order-tracking page** (`UserProfileController::track_order_result`) and of the REST `track_order` compare phones with a loose `==`: two numeric strings are equal when they are equal as numbers (`0551234567` and `551234567`), and a guest order whose address copies carry no phone key (`null == ''`) opens for an empty phone. `main` replaced the web page's branch with an exact (or Saudi-normalised) comparison; the REST endpoint still has it on `main`. #62 fixes the registered customers' branch only. Low: the digits are needed anyway.
4. **`messages.php` is rewritten by the admin's language screens**, which is why a pull request that edits it cannot be deployed as an overlay without a decision on every drift (handled: the six texts moved to `new-messages.php`).
5. The old code of #63 writes into the working tree while it is being tested (a `.env` with made-up values, probe files under `Modules/`, the service worker): the red run of that pull request was cleaned up by hand. Nothing of that is in the commits.

---

## Appendix A. The files of every stage

`A` = added, `M` = modified. The files a stage's package carries are the ones in its list under *Overlay*; *By hand* are the files an overlay cannot carry. A file two stages change is in both lists (the 209 files of section 1B are the distinct ones). The complete list of the one package of section 1B is `MANIFEST.json` of the package.


### Stage 1: Account takeover (`698aa1e` to `779a3e50`): 50 overlay files, 0 by hand

```text
M app/Contracts/Repositories/PasswordResetRepositoryInterface.php
M app/Enums/SessionKey.php
M app/Http/Controllers/Admin/ProfileController.php
M app/Http/Controllers/Admin/Vendor/VendorController.php
M app/Http/Controllers/Customer/Auth/CustomerAuthController.php
M app/Http/Controllers/Customer/Auth/ForgotPasswordController.php
M app/Http/Controllers/Customer/Auth/LoginController.php
M app/Http/Controllers/Customer/Auth/RegisterController.php
M app/Http/Controllers/Customer/Auth/SocialAuthController.php
M app/Http/Controllers/RestAPI/v1/auth/CustomerAPIAuthController.php
M app/Http/Controllers/RestAPI/v1/auth/ForgotPasswordController.php
M app/Http/Controllers/RestAPI/v1/auth/SocialAuthController.php
M app/Http/Controllers/RestAPI/v2/delivery_man/auth/LoginController.php
M app/Http/Controllers/RestAPI/v2/seller/SellerController.php
M app/Http/Controllers/RestAPI/v2/seller/auth/ForgotPasswordController.php
M app/Http/Controllers/RestAPI/v2/seller/auth/RegisterController.php
M app/Http/Controllers/RestAPI/v3/seller/SellerController.php
M app/Http/Controllers/RestAPI/v3/seller/auth/ForgotPasswordController.php
M app/Http/Controllers/RestAPI/v3/seller/auth/RegisterController.php
M app/Http/Controllers/Vendor/Auth/LoginController.php
M app/Http/Controllers/Vendor/Auth/RegisterController.php
M app/Http/Controllers/Vendor/ShopController.php
M app/Http/Controllers/Web/UserProfileController.php
M app/Models/Admin.php
M app/Models/PhoneOrEmailVerification.php
M app/Models/Seller.php
M app/Repositories/PhoneOrEmailVerificationRepository.php
M app/Repositories/VendorRepository.php
A app/Services/CustomerPasswordResetService.php
M app/Services/Fulfillment/Support/SaudiPhone.php
M app/Services/Monitoring/Panels/IntegrationsPanel.php
A app/Services/SocialLoginConfirmationService.php
A app/Services/VendorPasswordResetService.php
M app/Services/VendorService.php
M app/Services/Web/CustomerAuthService.php
M app/Utils/Helpers.php
A app/Utils/RequestText.php
M config/app.php
A database/migrations/2026_11_26_100000_purge_customer_and_delivery_man_reset_proofs.php
A database/migrations/2026_11_26_100100_purge_seller_reset_proofs.php
A database/migrations/2026_11_26_100200_clear_seller_remember_tokens.php
M resources/lang/ae/new-messages.php
M resources/lang/bd/new-messages.php
M resources/lang/en/new-messages.php
M resources/lang/es/new-messages.php
M resources/lang/in/new-messages.php
M resources/lang/ks/new-messages.php
M resources/lang/sa/new-messages.php
M resources/themes/default/web-views/customer-views/auth/verify.blade.php
M routes/rest_api/v1/api.php
```

### Stage 2: Ownership and data exposure (`779a3e50` to `b7842fa1`): 97 overlay files, 4 by hand

```text
M app/Contracts/Repositories/ReviewRepositoryInterface.php
M app/Enums/ViewPaths/Vendor/POSOrder.php
M app/Http/Controllers/RestAPI/v1/BannerController.php
M app/Http/Controllers/RestAPI/v1/CartController.php
M app/Http/Controllers/RestAPI/v1/ProductController.php
M app/Http/Controllers/RestAPI/v2/seller/ChatController.php
M app/Http/Controllers/RestAPI/v2/seller/OrderController.php
M app/Http/Controllers/RestAPI/v2/seller/ProductController.php
M app/Http/Controllers/RestAPI/v2/seller/RefundController.php
M app/Http/Controllers/RestAPI/v2/seller/SellerController.php
M app/Http/Controllers/RestAPI/v2/seller/ShippingMethodController.php
M app/Http/Controllers/RestAPI/v2/seller/shippingController.php
M app/Http/Controllers/RestAPI/v3/seller/ChatController.php
M app/Http/Controllers/RestAPI/v3/seller/ClearanceSaleController.php
M app/Http/Controllers/RestAPI/v3/seller/CouponController.php
M app/Http/Controllers/RestAPI/v3/seller/DeliveryManController.php
M app/Http/Controllers/RestAPI/v3/seller/EmergencyContactController.php
M app/Http/Controllers/RestAPI/v3/seller/OrderController.php
M app/Http/Controllers/RestAPI/v3/seller/OrderEditController.php
M app/Http/Controllers/RestAPI/v3/seller/POSCartController.php
M app/Http/Controllers/RestAPI/v3/seller/POSController.php
M app/Http/Controllers/RestAPI/v3/seller/ProductController.php
M app/Http/Controllers/RestAPI/v3/seller/RefundController.php
M app/Http/Controllers/RestAPI/v3/seller/SellerController.php
M app/Http/Controllers/RestAPI/v3/seller/SellerInventoryController.php
M app/Http/Controllers/RestAPI/v3/seller/SellerSecurityController.php
M app/Http/Controllers/RestAPI/v3/seller/ShippingMethodController.php
M app/Http/Controllers/RestAPI/v3/seller/VendorPaymentInfoController.php
M app/Http/Controllers/RestAPI/v3/seller/shippingController.php
M app/Http/Controllers/Vendor/ChattingController.php
M app/Http/Controllers/Vendor/Coupon/CouponController.php
M app/Http/Controllers/Vendor/DashboardController.php
M app/Http/Controllers/Vendor/DeliveryMan/DeliveryManWalletController.php
M app/Http/Controllers/Vendor/DeliveryMan/EmergencyContactController.php
M app/Http/Controllers/Vendor/Marketplace/SellerStaffController.php
M app/Http/Controllers/Vendor/Order/OrderController.php
M app/Http/Controllers/Vendor/Order/OrderEditController.php
M app/Http/Controllers/Vendor/POS/CartController.php
M app/Http/Controllers/Vendor/POS/POSController.php
M app/Http/Controllers/Vendor/POS/POSOrderController.php
M app/Http/Controllers/Vendor/Product/ProductController.php
M app/Http/Controllers/Vendor/Promotion/ClearanceSaleController.php
M app/Http/Controllers/Vendor/ReviewController.php
M app/Http/Controllers/Vendor/Shipping/CategoryShippingCostController.php
M app/Http/Controllers/Vendor/WithdrawController.php
M app/Http/Controllers/Web/CartController.php
M app/Http/Controllers/Web/ShopViewController.php
M app/Http/Controllers/Web/WebController.php
M app/Http/Middleware/ActivationCheckMiddleware.php
M app/Http/Middleware/SellerStaffAccessMiddleware.php
M app/Models/Attribute.php
M app/Models/Brand.php
M app/Models/BusinessPage.php
M app/Models/Category.php
M app/Models/DealOfTheDay.php
M app/Models/FlashDeal.php
M app/Models/OrderDetail.php
M app/Models/Product.php
M app/Models/Review.php
M app/Models/StockMovement.php
M app/Models/WithdrawRequest.php
M app/Providers/AppServiceProvider.php
M app/Repositories/OrderRepository.php
M app/Repositories/ReviewRepository.php
M app/Services/Marketplace/InventoryService.php
M app/Services/Marketplace/SellerOrderBreakdownService.php
M app/Services/Marketplace/SellerOrderTimelineService.php
M app/Services/Marketplace/SellerScorecardService.php
M app/Services/Marketplace/SellerTeamService.php
M app/Services/OrderEditService.php
A app/Services/Payments/PaymentSettlementScope.php
M app/Services/Search/ProductSearchService.php
M app/Services/SellerCenter/Lists/InventoryList.php
M app/Services/SellerCenter/Revenue.php
M app/Services/SellerIntelligence/Producers/FinanceIntegrityProducer.php
M app/Services/SellerIntelligence/Severity/SellerBaselineProvider.php
M app/Services/Theme/SectionDataResolver.php
M app/Services/VendorService.php
M app/Traits/CacheManagerTrait.php
M app/Traits/OrderEditManager.php
A app/Traits/SellerOwnedRecords.php
M app/Utils/CartManager.php
M app/Utils/Helpers.php
M app/Utils/OrderManager.php
M app/Utils/ProductManager.php
A app/Utils/RequestArea.php
M app/Utils/language.php
M app/Utils/module-helper.php
M resources/lang/ae/new-messages.php
M resources/lang/bd/new-messages.php
M resources/lang/en/new-messages.php
M resources/lang/es/new-messages.php
M resources/lang/in/new-messages.php
M resources/lang/ks/new-messages.php
M resources/lang/sa/new-messages.php
M routes/rest_api/v3/seller.php
M routes/vendor/routes.php
```

By hand:

```text
Modules/AI/routes/vendor/routes.php
Modules/Blog/app/Models/Blog.php
Modules/Blog/app/Models/BlogCategory.php
Modules/TaxModule/routes/vendor.php
```

### Stage 3: Permissions (`b7842fa1` to `6263b8b3`): 19 overlay files, 4 by hand

```text
M app/Http/Controllers/Admin/AdvancedSearchController.php
M app/Http/Controllers/Admin/DashboardController.php
M app/Http/Controllers/Admin/Settings/BusinessSettingsController.php
M app/Http/Controllers/Admin/Settings/DatabaseSettingController.php
M app/Http/Controllers/Admin/Settings/ThemeBuilderController.php
M app/Http/Controllers/Admin/V2AdvancedSearchController.php
M app/Http/Controllers/RestAPI/v2/seller/ProductController.php
A app/Http/Controllers/RestAPI/v3/seller/ProductBarcodeSheetController.php
M app/Http/Controllers/RestAPI/v3/seller/ProductController.php
M app/Http/Middleware/ModulePermissionMiddleware.php
M app/Http/Middleware/SellerStaffAccessMiddleware.php
M app/Http/Requests/Admin/DeepLinkRequest.php
M app/Packages/AdvanceSearch/AdvanceSearch.php
A app/Services/AdvancedSearchPermissionFilter.php
M app/Services/AdvancedSearchService.php
A app/Services/ProductBarcodeSheetService.php
M resources/views/layouts/admin/partials/_advance-search-result.blade.php
M routes/admin/routes.php
M routes/rest_api/v3/seller.php
```

By hand:

```text
Modules/AI/routes/admin/routes.php
Modules/AI/routes/api.php
Modules/AI/routes/vendor/routes.php
Modules/TaxModule/routes/web.php
```

### Stage 4: Uploads and files (`6263b8b3` to `53ba4a25`): 73 overlay files, 5 by hand

```text
A app/Console/Commands/PrivatizeDigitalFilesCommand.php
M app/Http/Controllers/Admin/AppCarouselController.php
M app/Http/Controllers/Admin/Order/OrderController.php
M app/Http/Controllers/Admin/Product/ProductController.php
M app/Http/Controllers/Admin/Settings/AddonActivationController.php
M app/Http/Controllers/Admin/Settings/AddonController.php
M app/Http/Controllers/Admin/Settings/FileManagerController.php
M app/Http/Controllers/Admin/Settings/LanguageController.php
M app/Http/Controllers/Admin/Settings/SiteMapController.php
M app/Http/Controllers/Admin/ThirdParty/PaymentMethodController.php
M app/Http/Controllers/RestAPI/v1/ConfigController.php
M app/Http/Controllers/RestAPI/v1/CustomerController.php
M app/Http/Controllers/RestAPI/v1/OrderController.php
M app/Http/Controllers/RestAPI/v2/seller/OrderController.php
M app/Http/Controllers/RestAPI/v2/seller/ProductController.php
M app/Http/Controllers/RestAPI/v3/seller/OrderController.php
M app/Http/Controllers/RestAPI/v3/seller/ProductController.php
M app/Http/Controllers/SharedController.php
M app/Http/Controllers/Vendor/Order/OrderController.php
M app/Http/Controllers/Vendor/Product/ProductController.php
M app/Http/Controllers/Web/WebController.php
M app/Http/Middleware/APILocalizationMiddleware.php
M app/Http/Requests/API/v3/DigitalProductFileUploadAfterSell.php
M app/Http/Requests/Admin/FileManagerUploadRequest.php
M app/Http/Requests/Admin/LanguageRequest.php
M app/Http/Requests/ProductAddRequest.php
M app/Http/Requests/ProductUpdateRequest.php
M app/Models/DigitalProductVariation.php
M app/Models/OrderDetail.php
M app/Models/Product.php
A app/Rules/StoredFileNames.php
M app/Services/AddonService.php
A app/Services/DigitalFileAddressService.php
A app/Services/DigitalFilePrivatizer.php
A app/Services/DigitalProductFileService.php
A app/Services/FileManager/ArchiveReport.php
M app/Services/FileManagerService.php
M app/Services/LanguageService.php
M app/Services/ProductService.php
M app/Services/PushNotificationService.php
M app/Services/Theme/ThemeAssetService.php
M app/Traits/ActivationClass.php
M app/Traits/FileManagerTrait.php
M app/Traits/InstallationTrail.php
M app/Traits/Processor.php
M app/Traits/SettingsTrait.php
A app/Utils/AtomicFile.php
A app/Utils/CssScanner.php
A app/Utils/EnvironmentFile.php
M app/Utils/Helpers.php
M app/Utils/ImageManager.php
A app/Utils/InertSvg.php
A app/Utils/PlainFileName.php
A app/Utils/SharedProductFile.php
A app/Utils/SitemapXml.php
A app/Utils/StoredFileExtension.php
M app/Utils/language.php
M config/filesystems.php
M resources/assets/front-end/js/custom.js
M resources/lang/ae/new-messages.php
M resources/lang/en/new-messages.php
M resources/lang/sa/new-messages.php
M resources/themes/default/layouts/front-end/app.blade.php
M resources/themes/default/web-views/blogs/blog-layouts.blade.php
M resources/themes/default/web-views/order/digital-product-download.blade.php
M resources/themes/default/web-views/users-profile/account-address-edit.blade.php
M resources/themes/default/web-views/users-profile/account-address.blade.php
M routes/admin/routes.php
M routes/rest_api/v1/api.php
M routes/rest_api/v2/api.php
M routes/rest_api/v3/seller.php
M routes/vendor/routes.php
M routes/web/routes.php
```

By hand:

```text
.htaccess
Modules/AI/app/Services/AIContentGeneratorService.php
Modules/Blog/app/Http/Controllers/Admin/BlogDownloadAppController.php
public/assets/front-end/js/custom.js
public/assets/new/back-end/js/translate.js
```

### Stage 5: Content visibility (`53ba4a25` to `665057a5`): 4 overlay files, 5 by hand

```text
M app/Http/Controllers/Web/PageController.php
M app/Repositories/BusinessPageRepository.php
M resources/lang/en/new-messages.php
M resources/views/admin-views/pages-and-media/update.blade.php
```

By hand:

```text
Modules/Blog/app/Http/Controllers/Admin/BlogController.php
Modules/Blog/app/Http/Controllers/Web/FrontendBlogController.php
Modules/Blog/app/Services/Frontend/FrontendBlogService.php
Modules/Blog/resources/views/admin-views/blog/draft-edit.blade.php
Modules/Blog/routes/admin/routes.php
```

---

## Appendix B. The 17 files an overlay cannot carry, with their checksums

SHA-256 of the file **as the server has it today** (the baseline `698aa1e`) and **as the hotfix tip `665057a5` has it**. H-3 compares the server's copy with the first column, H-4 with the second. The `manual-baseline.sha256` of H-1 is the first column.

| File | Stage(s) | Baseline SHA-256 | Tip SHA-256 |
|---|---|---|---|
| `.htaccess` | 4 | `be72279f28d451573b74a788905a8378ff34d4077383bf923aea5c188fdc273a` | `38adb9383f3458dba53a3e8e6025cc89b4e706800f5fd557e71bf981736cfd4a` |
| `Modules/AI/app/Services/AIContentGeneratorService.php` | 4 | `9d59030e4862f578db3a29e643bbe6568119c1dbe9a1251584b2d47c6a958111` | `6217fb2589b26014dced6f18fb67b815d1386b844c4f5f7ae048cf00a78778b2` |
| `Modules/AI/routes/admin/routes.php` | 3 | `df1a997c0496e5455300bc0c07ee9cd0577f0719c628beb65d7a05a46e670106` | `ed9910bfe90d61f3ff87896dd01e8e3046f3539f4d3059b30d8e816ae644f489` |
| `Modules/AI/routes/api.php` | 3 | `59229381f2c7e4f25dc0abc55751ffbee3dabd84604718e28a4732907dc9d6e7` | `aab05c3a71d1ebf758f75a16b54d90c41cea1f9ddeff47a10123fca3700fb853` |
| `Modules/AI/routes/vendor/routes.php` | 2,3 | `6917dfdac2313d2f3d96201cffb0a443df53dd0f666b827125367d06daf37636` | `7758ac4b44854675b8a9a4927380c50194f3df0539dbc93e7b2820110239ac41` |
| `Modules/Blog/app/Http/Controllers/Admin/BlogController.php` | 5 | `a9ef9a9c3c989dac3fa8e80485a2cb253398a99eb91b0afcbb1280aad37f562f` | `816ea7a54716adfbac2f9bc5548811aaa62a07218498d40d6b9fbf9b7009d605` |
| `Modules/Blog/app/Http/Controllers/Admin/BlogDownloadAppController.php` | 4 | `20fb6616cec79ff14a1cf9f979e8d05b40c3e1309788728719db4d5b9485c639` | `18d51ac6878bec560004cde25d953f70e512759b6a1c0bd4a3afba4aa342966d` |
| `Modules/Blog/app/Http/Controllers/Web/FrontendBlogController.php` | 5 | `f89fe18e60e1064f9e6fde14b2e8d764c5e77037de2d043d4f544ebff3569521` | `0bed83dce2437614fd1904ad9420649093f2eeb5ec24126e87446aae9e18eec0` |
| `Modules/Blog/app/Models/Blog.php` | 2 | `f7dfbb01f5a955e211f235ab843131e94b96cb5767953326a2cf904e3eeacb42` | `422dcd7be44051569922f469949cb2071020e15f3f1c37e551307b1125594ced` |
| `Modules/Blog/app/Models/BlogCategory.php` | 2 | `1519c7bbdb15d6cb30da605bbde928e9618dfa3904082546782047688c69ad9e` | `3722813d441910f842959ff91d869662496cfbf53aa8006a9f2ecf22b45c2f89` |
| `Modules/Blog/app/Services/Frontend/FrontendBlogService.php` | 5 | `97d14bac5d11c65583057eadc27b896eee3b41667c9da21e260691346320b016` | `0999774155840212a7204370768436734acfe26d690e5a15a964a2dfcbe2333c` |
| `Modules/Blog/resources/views/admin-views/blog/draft-edit.blade.php` | 5 | `7b1d4d6b40c375c955f1f346f10e8e8b67f4bfb2749e4551951ac3887f5a31e8` | `8c31d6141f6206776438f769864f2f07ad096d77c9cc8d18f4146e8f228fa733` |
| `Modules/Blog/routes/admin/routes.php` | 5 | `e0c0697a4810c541a54e8bf56bce1a7ce003263bb69166473f5b78ed063edfdd` | `0310bdc2a8aecc703a06213aaae74f92ca5d0b7c775f6ae0de10b6cf4682ccac` |
| `Modules/TaxModule/routes/vendor.php` | 2 | `4c5ab99e718380dac339640b149f70c1f7418943fdc8a5e860cc0d2f7975eca9` | `03976c590cf9deff8348a6d042a818206c37c8c31862d673a8105ee439613792` |
| `Modules/TaxModule/routes/web.php` | 3 | `5f905be14620d01fc657ddb33d1268836cfb836e1f9e3fa245bfe91e2a89cb9b` | `bd553bf74f3c3333c777a46490b1925d21e3bf089a55f49b14324732039bc8aa` |
| `public/assets/front-end/js/custom.js` | 4 | `8ab7f7bcc607b2fdadb4a804e8e9f86c8414cfc7076471e68a97a0942f763e6a` | `075631bf932d6e5e56f3241812a2b4922086ceddd7af8c7aa821c31b137b752c` |
| `public/assets/new/back-end/js/translate.js` | 4 | `b3eee55deccc7fa35bf29d72b0465dcf43e202a1ca68ecf2b108ec275c1671f7` | `00d181064f9f512d491d0c728089d8471465712dbab10025d016fcb8321c552f` |
