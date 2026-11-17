# Owner guide to the open draft pull requests (2026-11-16)

Sixty-five draft PRs are open against `fullstackfull/lavkoo` and nothing is merged or deployed. This page says which to read first, which go together, and what to do at deploy. Each PR body repeats its own rule at the top; if this page and a PR body disagree, the PR body is newer.

## 1. The security set: merge and deploy together

The review found that a customer or a seller can be taken over by someone who knows only their phone number or e-mail. Closing one door and leaving another is no fix, so these go **together**:

| PR | Closes |
|---|---|
| #62 | customer and delivery-man password reset, the order tracking page, and the shared code table the apps and the website read (a row without a token signed anybody in) |
| #58 | the same for sellers (reset by pattern, a proof anyone could write) |
| #59 | the website's customer sign-in pages (a phone number alone signed in, or showed a name) |
| #45 | the website's social login confirmation (any e-mail approved) |
| #60 | the app's "is this your account?" check; **ship the customer app's PR 4 (`fullstackfull/lavkooapp#4`) first**, an older app build only loses that one sheet |

Deploy checklist for this set:

1. Production must run with `APP_MODE=live`. Outside it every customer code is a constant and every delivery-man code is a constant, and no code change can fix that.
2. The delivery-man app is not in these repositories. #62's third commit (`e9352502`) needs it to call `verify-otp` before `reset-password`; confirm that, or leave that commit out.
3. `php artisan migrate` runs two data-only migrations (`2026_11_26_100000` in #62, `2026_11_26_100100` in #58) that empty the codes in flight and the reset proofs of customers, delivery men and sellers, because rows planted by the old code would still work. People ask for a new code; a customer a panel or the point of sale invited with a "choose your password" link uses "forgot password".
4. `php artisan optimize:clear`.

What people will notice: a reset signs the customer out of the app; two accounts holding one phone or e-mail cannot reset by it; a seller whose status changes signs in again.

## 2. Other security fixes, each independent

| PR | Closes |
|---|---|
| #44 | **an anonymous visitor could open any seller's vendor panel through a public product page** (the product JSON carried the seller's `remember_token`, push tokens, bank account and commission; the web panel accepts a cookie made of a seller id and that token): the seller is now serialized without credentials or finances (the seller's own profile still shows them) and a migration (`2026_11_26_100200`, data only) clears every stored remember-me token, so sellers who used "Remember me" sign in again; public seller registration approving itself; a seller who is not approved kept working on the v2 API; the web sign-in checked a different row; the master admin's e-mail and the admin's registration switch were not honoured on every door. **Ship this one first if only one ships** |
| #52 | the seller APIs and the vendor panel acting on any seller's rows (payouts, orders, products, reviews, promotions, shop, point of sale); staff with no role changing the owner's profile. Four review rounds: a seller could put another shop's product on an order and take its stock, and (round 3) could rewrite the lines, amount and stock of a **delivered** order by posting to the save route, because the "can this order be edited" rule lived only in the page: the server asks it now, which is a behaviour change you should know. Two files under `Modules/` change (the staff middleware on two route files) and are deployed by hand. Merge it together with or before #63 |
| #63 | **uploads**: a seller, a customer, a delivery man or the **unauthenticated** seller registration could store a script (`.php`, `.pht`, `.phps`, `.inc`, `.shtml`) or a page (`.svg`, `.html`) on the public disk, and a seller could make an **anonymous** request download any file the web user can read, `.env` included (a product's digital-file name was stored as sent and the code-protected download joined it to a directory). Now every stored file gets a data extension (the content decides when the name is not one), an SVG is refused as an image, stored names must be one plain segment, the download needs a paid order and locks after five wrong codes. **Check the web server never runs PHP under `public/storage`, list `storage/app/public` for `*.php`, `*.phtml`, `*.pht`, `*.phar`, `*.shtml`, `*.svg`, and if the logs show the download endpoint answering a name that starts with `..`, rotate the application key and the credentials in `.env`.** The digital files are on the public disk (anyone who guesses a name fetches it without paying): a private disk is an owner decision. The same PR also closes the admin panel's side of the audit: the legacy `/admin/addon/*` routes had no permission and took the add-on's path from the request (an employee with an empty role could run and delete files), the sitemap download and delete read and removed any file (`../../../../.env`) and a manually uploaded sitemap could be a page that runs script, the file manager unpacked a zip into any folder, web root included, a language code such as `../../public/x` created files outside `resources/lang` (and `GET .../language/delete/..` removed `resources`), the Firebase settings were written unescaped into the service worker every visitor registers, a theme SVG passed a word-list check that a prefixed script element walks around, "translate all" wrote translations unescaped into PHP files that every request includes (`${...}` ran), and the payment gateway logo field copied any server file named in a text field (`.env`) into the public disk. Five more fixes close what the central-helpers map found: the file manager listed and downloaded the whole private `storage/app` tree for an employee with `system_settings` (the sellers' identity documents are in `storage/app/seller/kyc`), removing a product image deleted any file of the product directory by name (a seller could delete another seller's images and digital files), the app carousel stored a JPEG that carries code under `.pht`/`.html`/`.svg` in the web root, the `.env` writer let a line break add whole lines, and the blog's app-download delete removed any file of `company/`. What stays as owner decisions: the add-on installer and the software updater run PHP by design, and about 80 admin routes carry no module permission (`docs/gcc/audits/2026-11-16-admin-routes-without-module-permission.md`; #65 gates the AI and tax groups). All seven audit maps are in (`docs/gcc/audits/2026-11-16-upload-surface-audit.md`). Two independent reviews of the admin-panel fixes are answered in the same PR (section 19 of its body, 14 commits): a regression this PR had introduced (the seller app's delete-image deleted the files of every colour-less extra image), a sitemap bypass through the declared encoding, a two-step cross-seller delete of product files (and a copy deleting its original's images), `a.php.jpg` in a zip, the bucket and the language switch for anonymous visitors, SVG addresses written with escapes, the carousel's extension and size, a lock-free `.env` writer and an inline-script coordinate. **Also check `public/uploads/app-carousel` for files that are not images**, and the web server's handler mapping for `storage/app/public` and `public/uploads`. Two more review rounds and a fresh reviewer's verification of each fix (about 70 more commits, sections 20 to 26 of its body) rewrote the theme SVG validator (entities, encodings and CSS read the way a browser reads them), closed a stored script in the **cookie banner that ran in the session of every visitor, administrators included** (from a role that may only edit the business settings), made the `.env`, language-file, licence and add-on writers locked, atomic and best-effort on hardened hosts, made the file manager unpack archives as a stream under a byte budget (a 122 KB zip used to unpack to 125 MB) and name every file it leaves out, and made the shared-product-file rule fast and exact. Owner decisions in its body: whether to cap the unpacked size of an archive absolutely (the budget is a ratio), the theme SVGs already stored (re-check them), and saving the Firebase settings page once after deploying (it regenerates the service worker). The audit's probes reproduced nothing of the upload class at the head |
| #57 | an admin editing any admin's profile |
| #65 | the AI provider settings (API key and limits), the VAT and tax settings and the tax reports answering any employee whatever their role holds: they now ask for `3rd_party_setup`, `business_settings` and `reports`. **An employee who uses those screens today without the permission needs it added to their role before this ships** |
| #61 | the vendor panel's shop pages acting on a shop named in the request (the same change is inside #52, byte for byte) |
| #46 | searches, reviews, counters, banners and hints answering for products a customer may not see; also the cart (add and the update-variation route), the review list with an array id, the reviewers' e-mail and phone in a product's details, and a flash deal's product count. Still open, for the owner: the vendor's whole row (phone, e-mail, bank, tax) in public product payloads, and three low write doors (web restock request, compare, wish list) |
| #51 | pages that merely start with `/vendor`, `/seller` or `/admin` counting as panels (unapproved reviews shown). **Merge it together with #52, or #52 first**: alone, the seller API v2 product list shows the product whose id equals the seller's own id, with its unapproved reviews, to that seller. It also keeps the publishing house lists (cached for every shopper, and returned by a seller API) free of product rows, and its address guard is now a parser based test over `app`, `Modules`, `routes` and `resources` |
| #54 | a cart row changed by someone it does not belong to |
| #56 | a paid callback settled for whoever the request named, or leaving state for the next one |
| #40, #41 | unpublished business pages and blog posts public |
| #16, #20, #21, #22, #23, #27, #32, #33 | earlier ownership and permission fixes for orders, refunds, due payments, withdrawals and identity |

Behaviour changes to know about (each is in the PR body): a staff member can no longer change the owner's password or profile (#52); a product's review list on the seller APIs now includes unapproved reviews, as its count always did (#51); a hidden product answers like an unknown one (#46).

## 3. Money (wallet, loyalty, POS), a chain

`#9` row lock, then `#10` and `#11` on it, then `#12` and `#13` on `#11`, `#14` and `#15` on `#10`; `#35` sits on `#13` and `#36` on `#34`. `#30`, `#31`, `#32`, `#33`, `#34` are independent of the chain. Read `#9` first: the others build on its lock.

## 4. Storefronts (GCC), off by default

A chain, merge bottom to top: `#8`, `#19`, `#28`, `#37`, `#38`, `#39`, `#42`, `#43`, `#47`, `#48`, `#49`, `#50`, `#53`, `#64` (`#46` is carried inside `#47` and everything above it). `#64` is the admin side: the admin chooses a product's country when adding it, and the admin list shows and filters it; it must exist before the flag is turned on with a non-Saudi product. It keeps SKU and barcode unique across countries on purpose: `docs/gcc/audits/2026-11-16-sku-barcode-lookup-map.md` lists the 35 places that find a product by its code, none of them country-aware yet (GCC-14b). Nothing changes while `gcc.storefronts.enabled` is false. Read `docs/gcc/STOREFRONT_FLAG_RUNBOOK.md` (in `#53`) before turning it on: the kill switch is to **deactivate the country, not to turn the flag off**. The legal and financial decisions for a non-Saudi launch stay with the owner; the code only refuses to sell where a storefront is not marked sellable.

## 5. Tests and small fixes

Test-only: `#1`, `#4`, `#6`, `#15`, `#18`, `#24`, `#25`, `#26`, `#31`, `#34`. Small fixes: `#2`, `#3`, `#5`, `#7`, `#17`, `#29`, `#55`.

## 6. CI

`Deployment overlay (artifact, deploys nothing)` is red on every branch cut from `main`: it compares against a fixed baseline and names three files (`firebase-messaging-sw.js`, its public copy and `robots.txt`) that nobody on these branches touched. It stays red until the baseline moves or those files are deployed by hand. Everything else runs per branch; a clock-dependent Torod test that fails on `main` is repaired by one carried line.

## 7. Decisions that are the owner's

- `APP_MODE=live` (above) and when to deploy the security set: until then `main` is exposed to everything in section 1.
- Customer reset codes have no lifetime, on purpose: the same table holds the "choose your password" links panels and the point of sale send, which are used days later.
- Two sellers holding one phone cannot recover a password by SMS or Firebase; a unique phone rule on seller registration and profile updates, and a report of the existing duplicates, would remove it.
- `registration-with-otp` and `registration-with-social-media` create accounts with any phone or e-mail marked verified; what proves them at registration is a product decision.
- The staff of a vendor panel with `payouts.request` can still change the owner's bank information; a phone-only social provider cannot sign in by social login; `SellerStaff` tokens are not rotated when a shop is re-approved.
- `GET /api/v1/products/details/{slug}` still carries every reviewer's e-mail and phone for a visible product.
- For GCC: a phone stored without a country code is read as Saudi; admin and seller tools do not yet set or filter a product's country.

## 8. Merge order and the conflicts to expect (dry-run merges of every pair of the open security branches, 2026-11-17)

Every pair of open branches was merged with `git merge-tree`; these are the only pairs that conflict textually. A conflict is the second pull request's to settle, and settling it needs the tests of both to be run once afterwards: ask and I push the merge commit.

| Pair | File and place | What to keep |
|---|---|---|
| #44 and #52 | `app/Http/Controllers/RestAPI/v2/seller/SellerController.php`, the return of `shop_info()` | both: `$profile = Seller::with(['wallet'])->withCount(['product', 'orders' => fn ($query) => $query->where('seller_is', 'seller')])->where(['id' => $seller['id']])->first(); return response()->json($profile?->makeVisible(Seller::OWNER_ONLY_ATTRIBUTES), 200);` |
| #54 and #48 (and so #49, #50, #53, #64) | `RestAPI/v1/CartController.php` and `Utils/CartManager.php`, the move of a guest cart to a customer | #48 rewrote the same lines (`$customerId`) that #54 gave `is_guest = 0`: keep #48's version and #54's `is_guest => 0` conditions |
| #56 and #53 (and so #64) | `app/Utils/module-helper.php`, `digital_payment_success()` | #53 wraps the order building in `PaymentStorefront::within`, #56 returns early for a payment that is already settled: keep #53's wrapper and put #56's guard at the top of the inner function |

All of this is outline, written from the conflict hunks and not yet resolved and tested. #63 and #51 and #63 and #64 used to conflict on one import line each; they do not any more. Independent of conflicts, merge #52 together with or before #63, and the storefront chain bottom to top as in section 4.
