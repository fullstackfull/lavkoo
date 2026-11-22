# I2-02 and I2-07: a market does not sell on texts nobody qualified has read

Master tracker requirements I2-02 (criterion 6) and I2-07 (criteria 3 and 4). **Draft, off by default: while `GCC_STOREFRONTS_ENABLED` is off nothing reads any of this, and the Saudi storefront (grandfathered) is never asked for a review.** This records what a person tells it; it reviews nothing and it approves nothing.

## What was wrong

1. **An empty list opened a market.** `StorefrontLegalReadiness::isReady` walked the required document types and locales and answered yes when none of them failed, so a configuration that required no document type, or no locale, made every storefront legally ready. A missing configuration is not a reason to open a market: it now answers no (the grandfathered storefront still answers yes).
2. **Texts approved inside the platform were the only legal gate.** The platform checks that a text exists, is effective, is not blank, is not an unedited template and was approved by someone other than its author. It cannot know what a country's law requires. Nothing asked for a lawyer of that country to have read the texts before the market sold.

## What it does now

- A storefront other than the grandfathered ones cannot sell until `StorefrontLegalReview` has a record for it: **who** reviewed the texts (a lawyer or a firm of that country), **where** the opinion is written down (the opinion, the ticket, the minutes) and **the day** it was given. `StorefrontSellingGate::maySell` asks for it next to the texts, the currency and the tax decision; the list of storefronts (`sellable`) and the readiness report read the same gate.
- `php artisan gcc:storefront:legal-review KW --by="…" --reference="…" --on=YYYY-MM-DD` records it, `--status` says what is on record (and exits 1 while the storefront has none), `--revoke` withdraws it so the storefront stops selling. It works while the switches are off, and then nothing reads the record.
- The record is a business setting (`storefront_legal_review_<country>`) written in one transaction with its audit line (`storefront.legal_review.recorded` / `.revoked`, with the row before and after): a review whose trace cannot be written is not recorded.
- It refuses, with the reason in words: a storefront that is not configured, a grandfathered storefront, no reviewer or one longer than the column keeps, a tool or an assistant as reviewer (best effort on free text: the owner is who runs the command), no source, a day that is not `YYYY-MM-DD` or lies in the future. A row that does not say all of it counts for nothing.
- `gcc:storefront:currency-readiness` prints a legal line for each storefront that is switched on: the texts and the review are fine, or UNSAFE with what is missing and the command that records it; the report fails while either is missing.
- The decision store the tax approval used (`StorefrontDecisionRecord`) is now shared: both services write their record and their audit line through it, and the tax approval's tests are unchanged.

## What is not done, and why

- **A recorded review does not go stale when a text changes.** Whether a new effective version of a text needs a new review is the owner's decision: if it did, correcting a comma would close a market. Today a review stays until it is withdrawn.
- **The review is not an assessment.** Nothing checks the opinion, the reviewer's authority or the country's required list of documents (`gcc.storefronts.legal.required_documents` is configuration, "not a statement of what the law requires", and a qualified reviewer confirms it per country).
- **No record exists for any country.** None was made here: the reviewers, the opinions and the days are the owner's, so every non-Saudi storefront stays closed until the owner records them.
- I2-07 criterion 5 (a test that scans the language files, views and API payloads for compliance claims) is separate work.

## Tests

`StorefrontLegalReviewTest` (service: who needs a review, what is recorded and traced, the refusals one by one, rows that count for nothing, withdrawing), `StorefrontLegalReviewCommandTest`, `StorefrontLegalLaunchReportTest` (the legal lines of the readiness report), and cases added to `StorefrontSellingGateTest` (texts and tax are not enough without the review, the review is not enough without the texts, one storefront's review opens no other, an empty requirement keeps a reviewed storefront closed), `StorefrontListApiTest` and `StorefrontLegalReadinessTest` (the empty list, no locale, the grandfathered list). The shared test helpers record the review wherever a world publishes the legal texts; a test that is about something else leaves the legal texts out with `leaveTheLegalTextsOutOfThisTest()`.

## Open owner decisions

1. Who may record a legal review (the command is the person's; the repository's only collaborator today is the owner).
2. Whether a new effective version of a text withdraws the review.
3. Which country needs which documents and locales (`required_documents`, `required_locales`, `locales_by_country`), confirmed by that country's reviewer.
