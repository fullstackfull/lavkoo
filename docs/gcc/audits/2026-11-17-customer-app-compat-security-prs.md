# Customer app vs the open backend PRs: compatibility review (2026-11-17)

Read-only review. It changed no repository, ran no PHPUnit and no build; the customer Flutter app is `fullstackfull/lavkooapp`, the backend is `fullstackfull/lavkoo`. Framework behaviour that could not be proved from code (Laravel and Dart redirect handling) is marked "expected" and has a test in the UNKNOWN section. No app version, minimum version, force-update gate, package id or Firebase setting change is proposed anywhere.

## Header: what was examined

- Backend `origin/main` c5c04b7b (2026-11-12).
- PR tips (remote branches): #45 d78ef203, #46 c07cd364, #51 a4356eed, #54 54a0be56, #55 cbd976a6, #56 f2e6f2c7, #59 edbb1675, #60 75512094, #62 c98aadc0, #63 de0a7d5e, #40 8c6236dc, #41 f096f4bc, #47 e0dd5dc2, #48 4a821d3c, #49 b52d9b2a, #50 98191fff, #53 7e32bba2, #64 216c2795 (the stack tip also carries #8, #19, #28, #37-#39, #42, #43).
- App `origin/main` 7bde7c19 (package `syriapharmacy`, base URL `https://lavkoo.com`, `appVersion` const 16.2.1, `pubspec` 3.0.6+48).
- App branches read: `gcc/security-social-confirmation-token` 08b9b9d (one commit on 7bde7c19; 5 files, +26/-7; draft PR lavkooapp#4), `gcc/p1-customer-storefront-header` 2a8c84c, `gcc/p1-customer-storefront-choice` 6b74524, `gcc/p1-customer-sellable-browse-only` eda7814. The follow-up on top of them (lavkooapp#5) was not part of the review.
- Version gate: none of the 18 diffs touches `X-App-Version`, `user_app_version_control`, force-update or `X-Platform`. A grep of each diff over `app routes bootstrap config` gave 0 hits per branch.

File references: app paths are relative to lavkooapp `lib/` on origin/main. Backend references are `path:line @tip`.

## Shared error handling (decides most verdicts)

- `data/datasource/remote/dio/dio_client.dart:65-73` sends `Content-Type`, `Authorization: Bearer $token` (the string "Bearer null" for guests), `Accept: application/json`, `X-Platform`, `X-App-Version`, `X-Client-Id` and `lang`. `lang` comes from prefs `language_code`, default `ar` (:89-92). There is no `X-Storefront` on main.
- Every repository does `catch (e) { return ApiResponseModel.withError(ApiErrorHandler.getMessage(e)); }` (for example `auth_repository.dart:21-29`). On error `response` is null (`data/model/api_response.dart:21-24`).
- `data/datasource/remote/exception/api_error_handler.dart:34-137`:
  - 403 and 400 (:54-69, :83-91): `if (error.response!.data['errors'] != null) { ErrorResponse.fromJson(...) -> errors?[0].message } else data['message']`.
  - 401 (:70-80): `Provider.of<AuthController>(Get.context!, listen: false).clearSharedData();`. `AuthRepository.clearSharedData` (`auth_repository.dart:186-191`) removes the saved token AND the saved guest id. This is a silent sign-out and nothing navigates.
  - 404 (:81-82): empty text. 500: 'Internal server error'. 429 (:102-104): the HTTP reason phrase. Any other status (:105-114): `ErrorResponse.fromJson(data)` or "Failed to load data - status code: N".
  - A non-Map body (HTML, string) throws a TypeError from inside the repository's catch. Nothing catches it.
- `data/model/error_response.dart:9`: `json['errors'].forEach((v) {...})`. It only parses the project's list shape `{errors:[{code,message}]}`. A Laravel Map shape `{errors:{field:[..]}}` throws. That is why #60 uses 403 with the list shape.
- `helper/api_checker.dart:12-45`, `getError()` :47-60: with `response == null` it builds `ErrorResponse(errors:[Errors(message: apiResponse.error)])`. So the screens that read `...errors![0].message` work for 400/403 and break for a null message.
- Multipart uploads (profile, review, refund, support ticket, chat) bypass all of this. They use `http.MultipartRequest` with only an `Authorization` header (no `Accept: application/json`) and read raw status codes.

## Report table

Severity is for the owner. Verdicts are BREAKS, DEGRADES, OK or UNKNOWN.

| PR | endpoint / behaviour | before -> after | app call site | handling | verdict | sev | proposed fix (side) |
|---|---|---|---|---|---|---|---|
| #60 | `POST /api/v1/auth/existing-account-check` | e-mail + yes/no returned a Passport token with no proof -> requires `temporary_token` (issued by social-customer-login, 10 min, single use, bound to customer id + e-mail + provider). Unknown, spent, expired or mismatch -> 403 `{errors:[{code,message}]}` (SocialAuthController.php:318-341; SocialLoginConfirmationService.php:20-55 @75512094) | existing_account_bottom_sheet.dart:117-120,165-168 -> auth_controller.dart:1188-1189 -> auth_repository.dart:353-356 | main build sends only email/user_response/medium. The 403 text goes to a warning snackbar (auth_controller.dart:1213-1217). The sheet is already closed (existing_account_bottom_sheet.dart:115,163). No crash. | old builds: BREAKS the "is it you" sheet only. App branch 08b9b9d: OK | medium | app: ship 08b9b9d first. Backend (optional, security-neutral): a translated "update the app or sign in with your password" text for the missing-token 403, instead of the English validation text. Bridge for old builds: OWNER DECISION D1 |
| #60 | `POST /auth/social-customer-login` | adds `temporary_token` only when the answer carries `user` + `status:false` (SocialAuthController.php:302-308) | auth_controller.dart:93-195 (`temp_token` :122, `user` :144-154) | the extra key is ignored. `temp_token` still means "go to OTP registration". | OK | - | - |
| #60 | same, suspended account | "yes" logged a suspended customer in -> 403 `account_suspended`, also for "no" (SocialAuthController.php:343-347) | same | server text shown | OK (intended) | low | - |
| #45, #59 | web social login and web sign-in pages (`/customer/auth/*`) | server-side session rules | none: `grep customer/auth` over lib = 0; social login is REST (app_constants.dart:160) | - | not touched | - | - |
| #62 | `POST /auth/forgot-password` (phone) | already equality -> equality + exactly one customer; not-found stays 401 (CustomerAPIAuthController.php:876-877, 969-971 @c98aadc0) | forget_password_screen.dart:164-176; auth_controller.dart:1130-1154 | two customers with one phone now get "Customer not found" 401, which clears token and guest id (api_error_handler.dart:70-80) | OK; duplicates DEGRADE | low | none. Run SQL U3 |
| #62 | `POST /auth/verify-token`, `PUT /auth/reset-password` | LIKE `%identity%` on `password_resets` -> equality, one customer, proof only from `password_resets` (server-issued); a reset deletes the customer's Passport tokens (CustomerPasswordResetService.php:73-84,116-131; ForgotPasswordController.php:150-193) | otp_verification_screen.dart:364-396; auth_controller.dart:989-1010,1161-1178 | both calls get the same `userInput` string, so equality holds. 400 `invalid_token` text is shown (api_error_handler.dart:83-91). | OK | - | - |
| #62 | `POST /auth/firebase-auth-token-store` | stored the caller's token -> stores a note only (CustomerAPIAuthController.php:1046-1049; Service:105-108) | auth_controller.dart:594-597 | response ignored. `firebase-auth-verify` still needs the note row, because a null row answers 403 `OTP_is_not_matched` (CustomerTrait.php:94-139) | OK | - | - |
| #62 | `POST /auth/firebase-auth-verify`, `is_reset_token=1` | proof written under the typed phone whenever Google's number matched a user -> proof only if `findCustomer(typed phone)` equals the stored phone AND `SaudiPhone::isSameNumber(Google number, stored)`; otherwise silent 200 + `temp_token` (CustomerAPIAuthController.php:575-596; Service:90-102) | auth_controller.dart:698-764 | the app moves to the reset screen on any 200 (:728-733). The last step then fails with 400 "invalid token" after the user typed a new password. | DEGRADES for non-Gulf numbers stored with a trunk zero. Not worse than main (see section 2). | medium | backend: extend the country list (in progress) and see D3. Optional: answer 403 at this step when the proof is refused, so the error shows at the code screen (auth_controller.dart:758-760 already renders it). |
| #62 | migration `2026_11_26_100000` | in-flight codes stay valid -> `phone_or_email_verifications` and customer/delivery-man `password_resets` emptied at `migrate` | check-phone, verify-phone, verify-otp, firebase flows | a user mid-OTP gets 403 `OTP_is_not_matched` once and must ask for a new code | DEGRADES, one time | low | deploy off-peak |
| #62 | `verify-otp`, `verify-phone`, `verify-email`, `verify-profile-info` | `where token = ?` -> `holdingToken()` (non-empty text or number only) (PhoneOrEmailVerificationRepository.php `getFirstWhere`; PhoneOrEmailVerification.php `scopeHoldingToken`) | auth_repository.dart:217-277 | the app sends 6-digit strings | OK | - | - |
| #62 | order tracking | only the web page changed (UserProfileController.php:745-749). REST `track-order` untouched (OrderController.php:958-1025) | order_details_controller.dart:189-205; guest_track_order_screen.dart:229-236 | - | not touched | - | - |
| #54 | `PUT /cart/update` (POST + `_method=put`) | row by `customer_id` only -> by id + `customer_id` + `is_guest` (CartManager.php:808-810 @54a0be56) | cart_repository.dart:130-145; cart_controller.dart:88-115 | the cart list only shows caller-owned rows, so every shown row matches. A `status:0` answer is still shown as a success toast (cart_controller.dart:104-106). That was already so. | OK | - | - |
| #54 | `POST /cart/select-cart-items` | any ids -> caller's rows only; still 200 (CartController.php:239-253) | cart_repository.dart:177-191; cart_controller.dart:212-231 | the app sends `ids` (list of ints) and `guest_id` | OK | - | - |
| #54 | `POST /cart/get-merge-guest-cart` | lookups and deletes without `is_guest` -> with `is_guest=0` (CartController.php:348-382) | cart_repository.dart:163-175; dashboard_screen.dart:62; cart_screen.dart:547 | sends only `cart_guest_id` (default "-1"). Result unchanged. | OK | - | - |
| #55 | order placement for a referred customer's first order | `ErrorException` after commit (HTTP 500, cart not emptied) -> normal 200 (OrderManager.php:2052-2059 @cbd976a6) | checkout_controller.dart:139-260 | the app was already hardened against 500-with-order | OK (fix) | - | - |
| #56 | payment hook (`digital_payment_success`) | payer comes from the stored payment; a replay builds nothing (module-helper.php:21-30; PaymentSettlementScope.php:41-49 @f2e6f2c7) | digital_payment_order_place_screen.dart:123-135,384-407; myfatoorah_payment_service.dart:55-93 | callback and pending URLs, `token`/`order_ids` params and the status JSON are unchanged | OK | - | - |
| #46 | `GET /products/reviews/{slug}` | hidden slug answered; row embedded `product` and the full reviewer row -> 404 `product-001` for hidden; no `product`; reviewer = `id,f_name,l_name,image` (ProductController.php:439-466 @c07cd364) | review_model.dart:58-85,184-204; review_widget.dart:61,78 | uses only `fName`, `lName`, `imageFullUrl`. Every parse is null-safe (`?.toString()`). 404 shows no text. | OK | - | - |
| #46 | `GET /products/details/{slug}` | reviews.customer full row -> `id,name,f_name,l_name,image` (ProductController.php:349-351 area) | product_details_model.dart:635-740,748-800 | `phone` and `email` read as null | OK | - | - |
| #46 | `POST /cart/add` | hidden product added; unknown id 500 -> HTTP 200 `{status:0,message:Product is unavailable}` (CartManager.php:755-760) | cart_controller.dart:117-152 | a 200 closes the sheet and shows the text as a success toast (:131-138). Also logs a Meta add-to-cart event (:140-144). | OK (cosmetic) | low | optional app: check `status` |
| #46 | `GET /banners`, `/flash-deals`, `/deals/featured`, search, suggestion-product | banners of hidden or missing products dropped; hide-mode deal lists shrink to real members; category-filtered search also matches the name; translated search honours `active()` (BannerController.php:100-109,146; ProductManager.php) | app uses `/products/filter` (search_product_repository.dart:30; product_repository.dart:193) and `/suggestion-product` (:54); `products_count` of flash deals is not read | shapes and keys unchanged | OK | low | - |
| #51 | `GET /products/digital-publishing-house-list`, `/api/v1/seller/*` | link rows embed `product` -> no `product`; `/api/v1/seller/*` returns translated flash-deal titles | search_product_controller.dart:323-330; author_model.dart:10-16 | parses only id, name, created_at, updated_at | OK | - | - |
| #63 | 5 multipart uploads: profile, review, refund, support ticket, chat | image branch stored anything decodable -> content decides: SVG, text and undecodable files throw `ValidationException` (ImageManager.php:28-46; FileManagerTrait.php:35-53; StoredFileExtension.php:63-95 @de0a7d5e) | profile_repository.dart:38-74; review_repository.dart:12-58; refund_repository.dart:20-46; support_ticket_repository.dart:22-46,72-96; chat_repository.dart:67-102 | supported formats are unchanged. For a refused file, Laravel renders a controller-thrown `ValidationException` as a 302 redirect when the request does not ask for JSON (expected, test T5), and these requests send no `Accept`. Profile then does `jsonDecode(html)` (profile_contrroller.dart:83) and throws. Review shows "302 Found" (review_controller.dart:116-118). Refund, support and chat are silent. | DEGRADES for refused files (before: 500, same user outcome) | low | app (next release): send `Accept: application/json` on multipart and parse the `errors` map. Backend: none needed. |
| #63 | `GET .../digital-product-download-otp-verify`, `...-otp-resend` | no lockout -> 5 wrong codes per order line in 15 min give 429 `Too_Many_Requests`; unpaid order -> 200 `{status:0}` (OrderController.php:825-879,881-) | order_details_controller.dart:235-283; order_details_repository.dart:40-76 | 429 shows the reason phrase. The unpaid refusal already existed on main in step 1 (`digital_product_download`, OrderController.php:670-675 main). | OK | low | - |
| #63 | `lang` header, `translate()` | an unknown code was a locale; `translate()` reset the locale of every API request -> unknown code answers in the store default; the API keeps the locale its middleware chose (APILocalizationMiddleware.php:22-35,62-100; language.php:16-18) | dio_client.dart:72,89-92 sends `ar` or `en` | `ar` -> the store's Arabic code (`sy`) by language name (middleware :75-97). Arabic names and messages become consistent (on main `getLanguageCode('ar')` returns `es` after the first `translate()`). | OK; cache effect UNKNOWN (T7) | low | - |
| #40 | `GET /business-page/{slug}`, cache-clear order | web route + admin link | app reads only `/api/v1/business-pages` (app_constants.dart:216; splash_controller.dart:334-336) and renders natively | the app's own 5-minute response cache (dio_client.dart:128,149-172) may show an unpublished page briefly | not touched | - | - |
| #41 | blog details (`/app/blog/{slug}`) | any slug shown -> only active + published; unknown looks like "missing" (FrontendBlogController.php:79-96 @f096f4bc) | blog_screen.dart:90-92 (URL from config `blog_page`); :73-74 closes the screen when the final URL has no `app/` | same redirect the screen already handles for an unknown slug | OK | - | - |
| #47-#53 | flags off | see Storefront stack | see section | no app-visible change except the choose-for-order ownership check | OK | - | - |
| #64 | admin product country | admin and vendor views, `ProductService`, admin filter only; no RestAPI file, no route | none | - | not touched | - | - |

## Account and auth flows

### 1. Social login and existing-account-check (#45, #59, #60): does the app branch 08b9b9d satisfy the contract?

**Contract.** `social-customer-login` returns `temporary_token` only in the `user` + `status:false` answer (SocialAuthController.php:302-308). That answer is returned for a suspended account, or for an unverified phone while phone verification is on. `existing-account-check` requires `email`, `user_response`, `medium` and `temporary_token` (:318-341). The token is single use via `Cache::pull` (SocialLoginConfirmationService.php:42), lasts 10 minutes (:20) and is bound to customer id, e-mail and provider.

**Does 08b9b9d cover it?** Yes. The diff is minimal:
- It stores `map['temporary_token']` in `AuthController` (auth_controller.dart:86,126 on the branch). The key name matches, and it is distinct from `temp_token`.
- It sends `"temporary_token": temporaryToken` only when non-null (auth_repository.dart on the branch).
- It sends the token exactly once and clears it (:1196-1197).
- Both entry points (Google and Apple/Facebook widgets, and the "only social login" screen) go through the same `AuthController.socialLogin`. The sheet is the only caller of `existingAccountCheck` (existing_account_bottom_sheet.dart:117,165).

**Compatibility with a server that does not issue it yet.** Nothing is sent, so the old server ignores it.

**Edge cases that stay as they are.**
- The token is cleared even if the request never reached the server (network failure). The user must tap Google again. Low.
- If the sheet stays open over 10 minutes, the answer is a 403 and a new social login is needed. Low.

**What the server side still needs.**
1. `CACHE_DRIVER` must be a store shared by all workers (file, database or redis; docs/integrations/operations.md:40 says never `array`). Otherwise every answer is 403. Test T1.
2. #60, #62, #58, #59 and #45 are one security set. The same takeover is reachable through the token stores until #62/#58 are deployed.

**An app build WITHOUT 08b9b9d against #60.**
- Only the "is it you" sheet fails.
- The request has no `temporary_token`, so the validator answers 403 with the English text "The temporary token field is required.". It is shown as a warning snackbar, and the user is left on the login screen.
- Social login for new users and for active, verified existing accounts is unaffected.
- Password and OTP login still work.
- Who is affected: suspended accounts (refused now anyway) and active accounts with an unverified phone while phone verification is on. Size: SQL U2.
- There is no way to keep the old request working without the hole (see D1).

**#45 and #59 (web).** The app opens no `/customer/auth/*` page. Its WebViews are the blog (config `blog_page`), the payment `redirect_link` and the wallet top-up `redirect_link`. So they are not touched.

### 2. Password reset and Firebase (#62): what the app sends

The app never strips a leading zero. Every phone is `dial + typed text`:
- forgot password: `userInput = _countryCode! + userInput` (forget_password_screen.dart:169)
- sign-up: sign_up_widget.dart:63,305-313
- login: login_screen.dart:616
- guest tracking: guest_track_order_screen.dart:229

Typed text may start with `0` (or even `+`; `NumberCheckerHelper.isNumber` allows `+`, number_checker_helper.dart:6-8). Only `verifyPhone` and `verifyPhoneForOtp` collapse `++` to `+` (auth_controller.dart:800,942).

`register` stores the phone raw (CustomerAPIAuthController.php:75) with `unique:users` (:55). So `+963` + `912345678` and `+963` + `0912345678` are two different accounts.

Flow strings (the same string is reused at every step):
- SMS path: `forgot-password{email_or_phone, type:phone}` -> `verify-token{email_or_phone, reset_token}` -> `reset-password{identity, otp, password, confirm_password}` (auth_repository.dart:388-409, 279-295). `verify-token` is used here, not `verify-otp`.
- Firebase path (when `customerVerification.firebase == 1`, auth_controller.dart:1112): Firebase gets the typed string (:489-597). Then `firebase-auth-token-store{identity, token}` -> `firebase-auth-verify{sessionInfo, phoneNumber, code, is_reset_token:1}` -> `reset-password{identity, otp=code}` (otp_verification_screen.dart:364-371; auth_controller.dart:698-733).

**Which spellings no longer match.** Almost none for the current build, because every step receives the same string:

| step | main | #62 | difference |
|---|---|---|---|
| forgot-password | `users.phone = identity` (already equality) | equality + exactly one | duplicates refused |
| verify-token | equality | equality + `user_type` | none |
| reset-password | LIKE `%identity%` | equality, one customer | partial identities no longer match. The app never sends one. |
| firebase-auth-verify (reset) | `getByIdentity(Google number)`: exact equality of Google's E.164 with the stored phone (CustomerRepository.php:40-48) | typed == stored by equality, AND `isSameNumber(Google, stored)` | see below |

**The only new strictness** is the typed == stored requirement at `findCustomer` (CustomerAPIAuthController.php:576). On main, a typed string that differed from the stored one but whose Google number matched produced a fake success. The reset wrote the proof under the typed identity, but the final `UPDATE ... LIKE '%typed%'` changed no row and still answered `password_changed_successfully`. #62 turns that silent no-op into a 400. So #62 is never worse than main for the Firebase reset flow, and it fixes cases main failed (a stored Saudi number that keeps the trunk zero after the country code, with Google reporting it without the zero: main failed on exact equality, #62 passes through `isSameNumber`).

**The blind spot.** `SaudiPhone.php:12-13,79-93` drops a trunk zero only after `965, 966, 968, 971, 973, 974`. A customer stored as `+963` + `0` + 9 digits (Syria, zero kept) gets Google `+963` + 9 digits. `isSameNumber` is false, so the proof is not recorded, `firebase-auth-verify` still answers 200 (CustomerAPIAuthController.php:595-596), and `reset-password` answers 400 `invalid_token` (ForgotPasswordController.php:191-193). This holds on main too. The country-list extension (in progress for #58 and #62) fixes it for numbers typed the same way twice. It does NOT fix typed != stored: both `findCustomer` (CustomerAPIAuthController.php:576) and `findCustomerHoldingCode` (CustomerPasswordResetService.php:73-84) compare the typed string by equality before `isSameNumber` is ever consulted. The extension also does not reach REST guest-order tracking, which does not call `isSameNumber` (see list below).

**Other places in the customer flows with the same blind spot** (exact spelling or Saudi-only tolerance). All of these are unchanged by the PRs:
- REST tracking, registered owner: `$user->phone == $request['phone_number']` (OrderController.php:970). Offline: `User::where('phone', ...)` (:988).
- Guest order tracking: `StorefrontAddressService::isSamePhone` (StorefrontAddressService.php:181-193). Saudi numbers normalised, everything else `hash_equals` of the trimmed strings. It calls `SaudiPhone::normalize`, not `isSameNumber`.
- Login: `getByIdentity` equality (CustomerRepository.php:40-48).
- `check-phone`, `verify-otp`, `registration-with-otp`, `registration-with-social-media`: equality or duplicate checks on the exact string (CustomerAPIAuthController.php:402-406,626,631,834; SocialAuthController.php:361). A second spelling creates a second account.
- Guest checkout "already registered" check: `User::where(email)->orWhere(phone)` (OrderController.php:201,224,325,348).
- Profile update: `$request['phone'] == $user['phone']` decides `is_phone_verified` (CustomerController.php:629). A different spelling un-verifies the phone.

### 3. Sign-out and 401

- A reset revokes the customer's Passport tokens (CustomerPasswordResetService.php:129-130). On `apiGuestCheck` routes a revoked token is ignored and the request proceeds as a guest, using `guest_id` (APIGuestMiddleware.php: `auth('api')->check()` then `guest_id`). On `auth:api` routes the app gets 401 and signs out silently through api_error_handler.dart:70-80. No crash.
- That handler also removes the saved guest id (auth_repository.dart:186-191), so the next guest request uses the default `"1"` (:166-167) until `getGuestIdUrl()` runs again. This is pre-existing; it is relevant to guest carts (Cart, point 3).

## Cart and checkout and payments

1. **#54 guest ids and ownership.** The app sends `guest_id` in every cart body and a Bearer token when logged in. The server resolves the caller with `getCustomerInformation`, and for an authenticated token it ignores `guest_id` (Helpers.php:34-60). Rows are created with `customer_id` = id and `is_guest` explicitly set (CartManager.php:511,684). The cart list filters the same way (REST CartController.php:70-75). So every row the app shows is a row the new owner conditions accept. `select-cart-items` and `update` cannot fail for legitimate rows.
2. **Merge after login.** `mergeGuestCart` runs on every dashboard init when logged in (dashboard_screen.dart:62) and from the cart screen's login callback (cart_screen.dart:547). It sends only `cart_guest_id` (default "-1", auth_repository.dart:469; cart_repository.dart:163-175). #54 adds `is_guest = 0` to the customer-side lookups and deletes (CartController.php:354-369; CartManager.php:30-44), so another visitor's guest row with the same number as the customer is no longer deleted. Result shape is unchanged. If #48 is merged too, keep #48's version (`moveGuestCartToCustomer`, CartManager.php:38-62 @4a821d3c). It carries the same conditions.
3. **Residual hazard that predates the PRs.** The default guest id is `"1"` when no guest id is saved (auth_repository.dart:167). `AuthController.login` removes the saved guest id (auth_controller.dart:297), and any 401 removes it too. Everyone who sits on the default id shares one guest cart, and #54 now stops that cart from touching customer #1's rows. Size: SQL U6.
4. **#48 choose-for-order ownership check (flag-independent).** `POST /shipping-method/choose-for-order` with `cart_group_id` other than `all_cart_group` is accepted only if the caller's own cart holds that group, otherwise 403 `{errors:[{code,message:Unauthorized}]}` (ShippingMethodController.php:53-57; CartManager.php:421-424 @4a821d3c). The app sends either `all_cart_group` (cart_screen.dart:648) or a group id taken from the loaded cart (:1004; shipping_repository.dart:28-42). `ShippingController.addShippingMethod` handles 200-with-errors and a 403 through `ApiChecker` (shipping_controller.dart:174-205). OK.
5. **Digital payment and top-up.** The app starts payments with `POST /api/v1/digital-payment` (checkout_repository.dart:171-188: `payment_platform:app`, `payment_request_from:app`, `customer_id`, `guest_id`, `is_guest`) and `POST /api/v1/add-to-fund` (wallet_repository.dart:62). It opens `redirect_link` in a WebView (digital_payment_order_place_screen.dart:120) or InAppBrowser (add_fund_to_wallet_screen.dart:48). For MyFatoorah it matches `/payment/myfatoorah/` or `/payment/fatoorah/` and `callback` or `pending` URLs with a `reference` param, then polls `/api/v1/payment/myfatoorah/status` (myfatoorah_payment_service.dart:55-93). For the other gateways it matches `success`+`token`, `fail` or `cancel` plus the base URL (digital_payment_order_place_screen.dart:123-135) and reads `new_user` and `order_ids` (:498-515). None of #53, #55 or #56 changes these URLs, parameters or JSON. The server still builds `payment-success?token=...&new_user=...&order_ids=...` (Processor.php:95-99).
6. **#55** removes a post-commit 500 for a referred customer's first order.
7. **#56 payer identity.** The payer comes from the stored payment (`customer_id`, `is_guest`, `payment_mode`), as the app sends it, and `Helpers::getCustomerInformation` answers from the running settlement first (Helpers.php:36-39 @f2e6f2c7). The MyFatoorah status poll may settle inside an app request. That is correct under the scope.
8. **Merge hazard with #53.** If #56's body is kept as `digital_payment_success()` (the wrapper dropped), then with the flag ON gateway callbacks settle in the default storefront. With the flag off there is no effect. This is already in the PR bodies.

## Search and catalog

- #46: reviews, details, add-to-cart, banners and search are covered in the table. Search results only get narrower and correct; no new keys.
- #51: the shared `Review` scope for `/api/v1/*` is unchanged (isPanel() is false). The extra `reviews_avg_rating` attribute is added only in `cacheTopRatedProductList`, which only the web `HomeController` calls (HomeController.php:36), so the app does not receive it.
- Localization (#63): the store's Arabic language code is `sy` (APILocalizationMiddleware.php:40-43 comment, :75-97). The app asks for `ar`. On main `translate()` ends with `App::setLocale(getLanguageCode($local))` (language.php main :15), and `getLanguageCode('ar')` returns `es`. #63 stops the reset for API requests (language.php:16-18). Arabic product and category names and server messages are now consistently Arabic. A header the store does not list answers in the store default. The shipped builds' `sa`/`us` aliases still fold to `ar`/`en` (APILocalizationMiddleware.php:31).

## Uploads (#63)

| flow | what the app sends | formats the picker allows | server rule after #63 | result |
|---|---|---|---|---|
| profile photo | `image`, no content-type, filename from the local path (profile_repository.dart:44-48) | `pickImage` gallery, quality 50, 500x500 (profile_screen.dart:50-53) | `FileManagerTrait::update`/`upload`: GIF kept only if `.gif` name and GIF content; else content must be a raster image, re-encoded (FileManagerTrait.php:35-53) | JPEG, PNG, WebP, GIF work |
| review images | `fileUpload[i]`, no content-type (review_repository.dart:24-33) | `pickImage` gallery, quality 100 (order_details_controller.dart:155-156) | same helper (ProductController.php:570,647) | work |
| refund images | `images[]`, content-type `image/jpg` for every file (refund_repository.dart:29-35) | same picker | `ImageManager::upload` (OrderController.php:610) | work. The declared type is ignored. |
| support ticket / reply | `image[]`, `image/jpg` declared (support_ticket_repository.dart:30-36,82-88) | same | `$this->upload` (CustomerController.php:93,160) | work |
| chat | `media[]` (images and videos) and `file[]` (documents) (chat_repository.dart:77-94) | images png/jpg/jpeg/gif/webp, videos mp4 mkv avi mov wmv flv webm mpeg mpg m4v 3gp ogv (app_constants.dart:377-398); documents doc docx txt csv xls xlsx pdf, plus rar tar targz zip outside demo (chat_controller.dart:317-326,540-558) | videos and documents: client extension kept when in the allowlist (StoredFileExtension.php:23-29), which contains every one of those. Images: as above. | work. SVG is not offered by any picker. |

- Not in the allowlist: `heic`, `heif`, `avif`, `gz` (note `targz` is a picker word, not an extension). A real HEIC never decoded under GD on main either (500); now it is a `ValidationException`. Under Imagick with libheif it would decode, and `isRasterImage` accepts any `image/*` except SVG. Test T6.
- Error presentation is the DEGRADES in the table. Note the picker for review, refund, support and chat uses `imageQuality: 100`. On Android that can mean the original file is sent, so a HEIC capture may reach the server as real HEIC.

## Storefront stack (flags off, default)

The stack (#47 -> #48 -> #49 -> #50 -> #53 -> #64, on the #8/#19/#28/#37-#39/#42/#43 chain) adds:
- `config/gcc.php`: `GCC_STOREFRONTS_ENABLED` defaults to false.
- `GET /api/v1/storefronts`.
- Middleware `storefront`, `storefront.vary` and `storefront.sellable`.
- `Product::scopeInCurrentStorefront`, a Cart global scope, `PaymentStorefront`, and a per-storefront cache key helper.

Evidence that nothing changes for the app while the flag is off:
- `StorefrontCatalog::context()` returns null unless `config('gcc.storefronts.enabled')`, so `restrict()`, `offers()`, `restrictCartRows()` and `cacheKey()` return the query or key unchanged.
- `StorefrontResolver::resolve()` returns the default country when off (StorefrontResolver.php:93-96). A stale or cached config falls back to the built-in Saudi storefront (:52-58). A stale `config:cache` that predates `gcc.php` reads null, which is falsy, so it stays OFF.
- `VaryByStorefront`/`ResolveStorefront` add the `Vary` header only when enabled (VaryByStorefront.php; ResolveStorefront.php:31-34).
- `StorefrontSellingGate::assertMaySell` returns immediately when off, and its constructor has no DB access.
- `PaymentStorefront::record/within` are no-ops when off (PaymentStorefront.php:25-49).
- `Product::$hidden = ['storefront_country']`, so product JSON has no new key.
- `ThemeDelivery`/`StorefrontThemeRenderer` use `StorefrontContext::scopedCacheKey`, which returns the plain key while off. `StorefrontContext::current()` never throws when unbound.

Differences the app can see even when off:
1. The choose-for-order ownership 403 (Cart, point 4). This comes from #48.
2. `GET /api/v1/storefronts`: new, public, `throttle:60,1` (routes/rest_api/v1/api.php:71). Off payload: `{enabled:false, default_country:"SA", storefronts:[{country,path,currency,is_default}]}` (StorefrontListing.php:28-34). The app on main does not call it; the storefront branches hard-code a list (lavkooapp#5 adds the call, only with the app flag on).
3. Routes under `v1` gain `storefront.vary` (no header while off).

App branches (storefront-header/choice/browse-only): dark by default (`GCC_STOREFRONTS_ENABLED` dart-define, storefront_config.dart). With it set, `X-Storefront` is added (dio_client.dart diff). The server ignores the header while off. The `sellable` flag is hard-coded in those three branches, not read from the server (lavkooapp#5 reads it).

**Before any flag-ON build ships (not a problem today):** the app caches GETs in memory and in SharedPreferences, keyed by `path?query#language` only (dio_client.dart:149-172). The cacheable list includes categories, brands, banners, featured/latest/best-selling, flash deals and home categories (:124-140). After a storefront switch the previous storefront's lists would show for up to the cache window (default 5 minutes, or the server's `/api/v1/cash`). The cache key needs the storefront code first. Low now, medium at flag-on.

## Not affected (with evidence)

- #45, #59: no app call to `/customer/auth/*`; the WebViews are blog, payment link, wallet link only.
- #40: the app reads `/api/v1/business-pages` only (app_constants.dart:216; splash_controller.dart:334-336); `html_screen_view.dart` renders natively.
- #41: only the blog WebView; an unpublished slug behaves like an unknown one (blog_screen.dart:73-74 closes the screen when the redirect leaves `/app/`).
- #64: files are admin and vendor views, `ProductService` and the admin filter. No RestAPI controller, no route.
- #49: gates only web POST routes (checkout-complete, web-payment-request and similar); the app uses `/api/v1/digital-payment`.
- #50: server-internal cache keys; no key change while off.
- #55, #56, #53: no URL, JSON or parameter change (see Cart section).
- No PR touches the version gate (header).

## UNKNOWN, with exact tests (device or emulator; read-only SQL)

- **T1 (#60 token cache).**
  - Staging `.env` should read `CACHE_DRIVER=file`, `database` or `redis`.
  - Two-process probe: `php artisan tinker --execute="Cache::put('probe',1,300);"` then `php artisan tinker --execute="var_dump(Cache::pull('probe'));"` must print `int(1)`.
  - End to end with build 08b9b9d: test customer with `is_active=0` and the Google test account's e-mail. Tap Google, sheet appears, "Yes" gives the "suspended" toast. Then set `is_active=1`, `is_phone_verified=0`, and `login_setups.phone_verification=1`. Sheet "Yes" must sign in and go to phone verification. Repeat with the store build: expect the English validation toast.
- **T2 / U2 (cohort for the old-build sheet).**
  - `SELECT SUM(is_active=0) AS suspended, SUM(is_active=1 AND is_phone_verified=0) AS phone_unverified FROM users WHERE email IS NOT NULL AND email<>'';`
  - `SELECT value FROM login_setups WHERE key='phone_verification';` (the first count only matters when this is 1).
  - `SELECT login_medium, COUNT(*) FROM users GROUP BY login_medium;`
- **U3 (duplicates that make #62 refuse a reset).**
  - `SELECT COUNT(*) FROM (SELECT phone FROM users WHERE phone IS NOT NULL AND phone<>'' GROUP BY phone HAVING COUNT(*)>1) d;` and the same on `email`.
  - `SELECT COUNT(*) FROM users a JOIN users b ON a.id<>b.id AND a.phone=b.email;`
- **T3 / U4 (size of the trunk-zero population).**
  - `SELECT CASE WHEN phone REGEXP '^\\+(965|966|968|971|973|974)0' THEN 'gulf_zero' WHEN phone REGEXP '^\\+(963|20|962|961|964|90|967|970|249|212|213|216|218|92|91|880)0' THEN 'other_zero' WHEN phone LIKE '+%' THEN 'plus_nozero' ELSE 'no_plus' END s, COUNT(*) FROM users WHERE phone IS NOT NULL AND phone<>'' GROUP BY s;`
  - `other_zero` is the population that cannot reset by Firebase until the list is extended.
  - Device test with Firebase test numbers: two customers, stored `+963`+`0`+9 digits and stored `+963`+9 digits. Run forgot-password and type the number the same way both times, then the other way. Expect: same spelling OK for the second customer; zero-kept fails at the reset step for the first (until the fix); mixed spelling fails at the reset step for both.
- **T4 (old session after a reset).** Device A logged in; reset through website or another device; open profile or orders on A. Expect one 401, a silent sign-out and no crash. Check `Authorization` header behaviour on a cart call.
- **T5 (upload refusal presentation).** `curl -i -X POST -H 'Authorization: Bearer <staging token>' -F _method=put -F f_name=a -F l_name=b -F phone=<x> -F email=<y> -F image=@notimage.txt https://<staging>/api/v1/customer/update-profile` (no `Accept`). Expect 302 (by reasoning) and, with `-H 'Accept: application/json'`, 422. Then on a device force the same by a failing image to confirm the profile screen's FormatException.
- **T6 (HEIC).** iPhone with High Efficiency capture and an Android device that shoots HEIC: set a profile photo, add a review image, a refund image, a support attachment and a chat image. Expect success and a stored `.webp`. Server side: image driver in `config/image.php` (GD vs imagick), `php -m | grep -i imagick`, `php -r 'var_dump(imagetypes() & IMG_WEBP);'`.
- **T7 (cross-language caches after #63).** On staging: `php artisan cache:clear`, request `/api/v1/banners`, `/api/v1/flash-deals`, `/api/v1/seller/more` and `/api/v1/products/home-categories` with `lang: ar` then `lang: en`. Then clear and repeat in the other order. Names must follow the header each time. The product endpoints are uncached; the shared caches (banners table, business pages, more-vendors) are the ones to look at.
- **T8 (guest cart end to end).** Fresh install (guest): add product A, tick or untick it, change quantity, remove and re-add. Log in: the row must appear in the customer cart. A second guest device must be unaffected. Read-only: `SELECT id,customer_id,is_guest,cart_group_id FROM carts WHERE customer_id IN (<guest id>,<customer id>);`
- **U6.** `SELECT COUNT(*) FROM carts WHERE is_guest=1 AND customer_id=1;` A large number means many installs run on the default guest id.

## Recommended merge/deploy order (app's point of view)

1. **App first, once:** publish 08b9b9d (lavkooapp#4; compatible with old and new servers). Any other next-release app items are optional: `Accept` header on multipart; check `status` after cart add; cache key with the storefront code before a flag-on build.
2. **Backend, no app dependency, any order:** #55, #56 (with #53's wrapper rule), #54 (then #48 keeps its merge helper), #46, #51 (merge #52 first or with it), #40, #41. Requests and responses the app uses do not change shape.
3. **Backend security set, together:** #62 + #58 + #59 + #45. These need no app change. Run the migration off-peak (codes in flight are asked for again). Confirm `APP_MODE=live`.
4. **#60** with or right after step 3. It is the one PR that needs an app first. See D1 for when to deploy relative to adoption.
5. **#63** separately, after T5-T7 on staging. The app's formats work, so it is safe for current builds.
6. **Storefront stack (#47-#53, #64):** any time, flag off. Do not turn the flag on until the app builds exist and the app cache key includes the storefront. Run `php artisan migrate` (adds `products.storefront_country`), clear caches, and re-cache routes and config.

## Owner decisions

- **D1 (#60 vs old builds).** Deploying #60 immediately closes an unauthenticated takeover that is live at main. The cost is the "is it you" sheet on old builds for a small cohort (U2) that still has password and OTP login. Waiting for adoption keeps the hole open for that time. Recommendation: do not wait, and ship the translated 403 text so old builds show an instruction. An optional bridge exists: stash the vouch under hash(`X-Client-Id`, e-mail, provider) at `social-customer-login` and redeem it when the token is missing. It is still bound to the provider's vouching and single use, and the header is already sent by current builds (dio_client.dart:71,75-82), but it relies on a client-chosen value, needs new tests, and only helps builds that send that header. Not recommended unless adoption is too slow.
- **D2 (#62 duplicates and purge).** Two customers holding one phone or e-mail are refused a reset. Decide whether to merge or fix those accounts first (U3). The purge migration drops in-flight codes once.
- **D3 (trunk-zero rule).** Extending the country list fixes Google-vs-stored. Typed-vs-stored needs the lookup step to use the same spelling tolerance. That stays safe if it resolves candidates by `isSameNumber`, requires exactly one customer and keeps the proof keyed by the stored phone. The trade-off is more spellings treated as one number, so collisions between countries with short national numbers must be tested. Do not loosen the exactly-one rule.
- **D4 (digital OTP lockout).** The lock is per order line for 15 minutes (OrderController.php:829-841). A stranger can lock out the real customer's download. Accepted as a cost of a 4-digit code, or key it by caller too.
- **D5 (stale banners, #46).** Banners whose product id no longer exists are now dropped. If stale banners that still have a working `url` should stay, keep them when the product row does not exist and drop only hidden rows (PR text, F6/G4).
- **D6 (flags).** Keep `GCC_STOREFRONTS_ENABLED` off until the app cache key includes the storefront code and the runbook steps are done.
