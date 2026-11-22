# Open owner decisions by axis

Taken from the "Open owner decisions" field of each requirement card, as of 2026-11-22. Nothing in this list is decided by the code; each is a choice, a review or a fact only the owner or an outside party can supply.

## Axis A: Six-country base and customer identity

- **A1-01:** None open for the six markets (approved in DECISIONS). Config file or table for the registry is raised under A1-09, not decided.
- **A1-02:** Open: what ready to open means; who may open a market; whether deactivate-never-switch-the-flag-off is the accepted kill switch (written in the runbook of PR 53, not recorded as an owner decision).
- **A1-03:** Open: what operational means is defined in no owner document; whether a changed default affects visitors who already hold a stored choice.
- **A1-04:** Open in the PR 28 body: the owner reviews the storefront response contract (key country, not code). The precedence order is stated in the PR 8 and PR 28 bodies.
- **A1-05:** Open (PR 49 body calls it a product decision): prefix routes or a switcher for the website; whether Saudi pages also live under /sa or stay at the root.
- **A1-06:** Open: how the website offers the choice (prefix or switcher, PR 49 body); whether an IP suggestion is wanted at all. The four Arabic strings of lavkooapp#7 await a native reader (its body).
- **A1-07:** Open in the PR 39 body: keep a market that may not sell in the list as browse-only, or hide it.
- **A1-09:** Open: is a storefront always one country (key = ISO code) or its own entity; where the Legal Entity lives (table or config). Discovery 03 section 4.2 proposes tables; no owner document decides it.
- **A1-10:** Open: adopt the Discovery 03 proposal (storefront_id on seven anchors, entity-scoped ledgers) or extend the product-row design to the other tables; recorded as decided nowhere.
- **A1-11:** Open: may a seller list in more than one country (PR 64 body); until decided the seller APIs stay unbound.
- **A1-12:** Open, not yet raised in any document: which session keys (coupon, currency, address) may survive a storefront switch.
- **A1-13:** Open (PR 49 body): whether a point-of-sale or marketplace-order sale is a storefront sale. Finance and security review of the payment finish path before merge (charter section 5).
- **A1-14:** Open, not yet raised in any document: whether jobs must carry the storefront (needed once mail, push and exports are per market).
- **A1-15:** None open. Running cache:clear at every flag flip is a runbook step (PR 50 body), not a code guarantee.
- **A1-16:** None. Reviews queued in the lavkooapp#7 body: a native Arabic reader for four strings and a device test against a server that lists two storefronts.
- **A1-17:** Open: one push topic or one per market; sender identity per market (plan lines 405-418). Not written in any owner document yet.
- **A1-18:** None for the row-based design; the own-warehouse stock switch (price-stock gap 28) needs a decision before a second country uses it.
- **A2-01:** Whether to add unique keys on users.email and users.phone, after the duplicate report, and which account wins when duplicates exist.
- **A2-03:** SECURITY_RELEASE_PLAN decisions 1, 3 and 4 (APP_MODE and the config line; delivery-man app and ship order; duplicate accounts and unlisted trunk-zero countries). Acceptance that the purge makes invited customers use 'forgot password'.
- **A2-04:** What proves an e-mail or phone at registration (product decision, PR 60 body); whether phone-only social accounts are supported; ship order lavkooapp PR 4 then PR 60.
- **A2-05:** Code lifetime and which links may outlive it; whether a test phone or store-review account may keep fixed codes (plan decision 1).
- **A2-06:** Whether users gets a country_code column (a migration on live data) and how existing numbers without a code are classified; trunk-zero countries to add need their numbering plans checked.
- **A2-07:** Add a per-guest secret and ship an app release (PERM-05, docs/address decision 9)? Fix the customer app's guest due-payment flow, which does not work before or after PR 21?
- **A2-08:** none (wishlist and compare per storefront is a product choice if wanted)
- **A2-09:** Which legal entity each storefront sells under (GCC_LEGAL_ENTITY per storefront) and whether POS and marketplace orders count as storefront orders.
- **A2-10:** Whether the history defaults to all markets or the current storefront, and how a market is named to the customer.
- **A2-11:** docs/address decisions 9 (guest identity secret) and 10 (delete on GET).
- **A2-12:** Which destinations each storefront serves, whether cross-border (GCC-16) is on per pair, and how regulated pharmacy goods are handled (no automatic bypass).
- **A2-13:** Opt-in or opt-out rule per country and channel (legal), retention of consent rows, and whether existing Saudi subscribers are carried over.
- **A2-14:** Acceptance that the purge migration ends codes in flight and invited-customer links at deploy.
- **A2-15:** Retention period and anonymisation versus hard delete (legal); the POS and seller customer listing (owner decision, PR 52); who may export a customer's data.

## Axis B: Catalogue and products

- **B-01:** Whether auctions are sold per country or only in the default storefront; whether to add the (storefront_country, id) index after reading plans on real data (runbook).
- **B-03:** Rounding rule and decimals per currency, and whether Saudi display keeps zero decimals (Q-07); the currency policy for new storefronts (Q-08); production currency_model is unknown.
- **B-04:** Accept the open sites (D1 partial, G9 deferred) before the switch; approve the composite index and the duplicate clean-up; review the Arabic strings the_scanned_code_matches_more_than_one_product and the_country_must_be_one_of_the_configured_storefront_countries.
- **B-05:** Which open sites are acceptable (D1 partial, G9); a country for provider connections; Amazon accounts of other marketplaces; whether a promotion has a country; who changes the seller app; native read of the seller app's 11 new Arabic keys and the two backend strings.
- **B-06:** Whether variant SKUs must be unique at all (free text today) and how a copy treats them (GCC-14B copy tool, point 4).
- **B-07:** What a copy carries (tax set-up, the price and in which unit, variant SKUs); who may copy and how it is audited; eligibility of regulated or pharmacy goods in the target country.
- **B-08:** Import for several countries; whether the master admin may restrict itself; moderation of seller products by country; legal-entity scopes; the intersection with a platform admin's grant countries (#71); native read of three Arabic strings in new-messages.php.
- **B-09:** One market or several per seller; whether a seller's product in another country is an admin copy or a seller creation; the seller's legal entity per market (discovery D-11).
- **B-10:** Is a category depth beyond three levels needed for the GCC catalogues? (it means new product columns and forms); who may change the central tree.
- **B-11:** none (per-store hide is B-13)
- **B-12:** Where local attributes live (a column or a mapping table); whether a value dictionary is wanted; whether a local attribute can be promoted to base; required attributes per category and country.
- **B-13:** Does hiding a category hide its sub-categories and products or only its entry; the products of a hidden brand; per-storefront display names for categories and brands (GCC-10).
- **B-14:** Whether empty brands and categories are hidden in a storefront's facets or only through the B-13 visibility.
- **B-15:** The banner assignment model (pivot or country column), and whether banners follow the per-country theme (theme unit).
- **B-16:** GCC-17 details: does a campaign, deal or coupon belong to one market or select several; how a fixed amount is expressed per currency; one active flash deal system-wide or per storefront.
- **B-17:** Canonical scheme (country path as the plan proposes, and whether Saudi stays at the root or moves to /sa with 301); one sitemap per storefront or an index; indexing of copies of one product in several countries.
- **B-18:** URL scheme and redirect policy for Saudi (root or /sa), whether the path applies to the blog and the API, and the deep-link association policy per storefront.

## Axis C: Inventory and fulfillment

- **C-01:** Q-33: written statement per provider and market of where it can serve, before any non-Saudi assignment is allowed.
- **C-03:** Whether an unknown stored source refuses checkout or holds the line; which default source each non-Saudi country's in-house products belong to; which of the three location concepts is the reference (E11-F08).
- **C-04:** The rule for old orders (default Saudi account, no data rewrite); whether in-house lines get an explicit row; which legal entity the snapshot is attributed to.
- **C-05:** Which entity owns a location (country, legal entity or both); the default for existing rows; which of warehouses, inventory_locations or the provider code is the reference for each purpose (E11-F08); whether the own warehouse may read the store's stock through in more than one country.
- **C-08:** #24: must a reservation released for a cancelled order be held again if the channel order is reinstated? Today it stays released, which may allow overselling. Pinned as is, not changed.
- **C-11:** Whether the post-payment path may keep taking stock below zero (allow_oversell, pinned in #18) and whether a hold over the cart-to-payment window is wanted (E11-F07); the owner accepts the fixes of the three #24 findings.
- **C-12:** Q-33: which providers serve AE, KW, BH, QA, OM and under which contracts; one provider account per country or per legal entity; whether one provider code may be reused across countries.
- **C-14:** E11-F19: may an account of one country take part in the pool of another country's product at all (shared stock is forbidden by the decisions)? Should PoolWarnings::OTHER_LOCATION become a refusal?
- **C-15:** Which currency a provider is paid in for a non-Saudi order (storefront currency or a provider-account currency); E11-F02 rates it P0 if a non-Saudi provider is enabled before this is done.
- **C-16:** Whether the seller and admin POS, the marketplace order materializer and the sales-channel webhooks are storefront sales; whether a channel order's units may come off a product of another country than its account's.
- **C-18:** Merging #1 also changes CLAUDE.md on main (its own note): the owner decides, or only its Torod test line is cherry-picked.

## Axis D: Orders, checkout and payments

- **D1-01:** Where the key lives (column on orders or a storefronts table with ids); whether POS and marketplace sales are storefront sales (open in the runbook); the value used to backfill existing orders (Saudi) after reconciliation.
- **D1-03:** Legal name, tax registration, invoice-series and e-invoicing duties of each country's company; whether the first launch has one entity per country (the plan says so).
- **D1-04:** Whether storefronts stay config entries or become a table with ids; naming of the keys.
- **D1-05:** GCC-05: how a storefront's currency reaches the converters and whether the stored base stays USD.
- **D1-06:** Stored unit of amounts (storefront currency or USD base), rounding rule for 3-decimal currencies, and how Saudi history is backfilled once the production currency_model is read.
- **D1-07:** VAT rate and mode per country and entity; whether included tax must be stored; any e-invoicing duty (none exists in code).
- **D1-10:** Refund policy across currency movements (original amount versus current rate); credit notes; whether refunds may only return to the original merchant account.
- **D1-11:** A return authorised for zero units restocks one unit (flagged in PR 17); pause modes of GCC-12 are store operations.
- **D1-14:** Whether the server issues a per-checkout token that the apps must send (needs app releases) or only accepts a client key.
- **D1-15:** Guest identity secret (needs a mobile release); behaviour changes listed in #21 and #22 (guest due payments, partial phone refused, a signed-in customer cannot act on a guest order).
- **D1-16:** Arabic wording of the refusal (the app PR asks for it); whether POS and marketplace sales are storefront sales; what ready to sell includes beyond legal texts.
- **D1-17:** Which destinations each storefront ships to; cross-border rules for regulated health goods; whether a visitor from a non-active country may buy at all.
- **D2-01:** Q-33: which GCC markets Paymob serves and for which legal entity; whether Paymob gets a server-to-server notification (R-071), decided after the characterisation.
- **D2-03:** Q-09: study only (audit default), build for Saudi first, or build multi-country after written confirmation; which markets and merchant accounts exist.
- **D2-04:** Confirm the discarded Paymera copy holds no logic worth keeping (PR 3 body); whether legacy non-GCC gateways (Paymera, MTN Cash, bKash, SSLCommerz, Paytm) and the 23 stubs are disabled for GCC (audit: Isolate).
- **D2-05:** Q-33: for each market, which of Paymob, MyFatoorah, Tabby, Tamara are confirmed, with currency, methods and merchant eligibility; accept default-deny as the rule.
- **D2-06:** Which methods are allowed on cross-border orders (COD, wallet, BNPL); who maintains regulated-goods restrictions (GCC-16).
- **D2-07:** Q-10: first legal entity model; which entity owns each gateway account; approval of migrations on payment tables.
- **D2-08:** Where each company's secrets live (admin form with encryption or a secret store); who may see them (GCC-14 scope).
- **D2-09:** none beyond D2-07 and D2-12 (R-071).
- **D2-10:** Columns on payment_requests (needs the real schema) or keep additional_data; entity identity (Q-10).
- **D2-11:** Whether a settlement whose order total differs from the paid amount is refused or flagged (PR 56 left it to the owner); whether Paymob needs the confirmation call (R-071).
- **D2-12:** R-071: server-to-server notification or sweep for Paymob, or keep the browser return only.
- **D2-13:** Merge order and who resolves the PR 53 and PR 56 conflict (documented in the guide); the amount-mismatch policy (D2-11).
- **D2-14:** URL form of the return pages (path prefix on one domain or a query parameter); accept the kill-switch rule 'deactivate the country, never turn the flag off'.
- **D2-15:** Who approves and who executes gateway refunds, manual or automatic; whether a refund must link to refund_requests (R-073); the refund race decisions in the PR 15 proposals.
- **D2-16:** Which settlement file or API each provider supplies and who in finance reconciles it; retention.
- **D2-17:** Guest ids are sequential and guessable (PR 21 decision 1): add a guest token or accept; delete the old debug files only after a retention review; DR-1 stays in the owner register.

## Axis E: Wallets, loyalty and financial records

- **E-01:** Wallet key: per storefront, or per legal entity when two storefronts share one; stored-value rules per country (legal); storage unit of historical balances; per-market wallet switch, top-up limits and bonus tiers.
- **E-02:** Earn rate, exchange rate and minimum per market; the shape of the future sharing key; whether points expire.
- **E-03:** none open (decided: no automatic transfer between legal companies); manual goodwill transfers stay out of scope until separately approved.
- **E-04:** What a restricted employee may see of customer wallet and loyalty reports and the wallet bonus tab (GCC-14c decision 15); which customer fields the seller app may receive.
- **E-05:** Whether the ledger balance includes the add-fund bonus (PR 6); the refund reference format (RF-02); a writer-level idempotency key (IDEM-06/07, D6); how old rows are marked.
- **E-06:** Who runs the read-only check, on which copy and when; the storage unit (E04-F02); whether any unexplained difference blocks go-live.
- **E-07:** Approval to run any backfill on real data; the Saudi opening-balance rule; the cut-over date and rollback window; the storage unit of historical balances.
- **E-08:** Anonymise, or refuse deletion while a balance exists; ledger retention period (legal).
- **E-09:** D5: whether a lock timeout needs retries or a clean answer (today HTTP 500, no money lost); financial-owner review of PR 9 and PR 10.
- **E-10:** D6: whether every writer refuses overdraft for every caller (PR 11 does it for order and due debits); a dedicated message when the wallet is off.
- **E-11:** DC-06/DC-07: the debit amount source and the double conversion; whether POS paths lock products up front; D18 rounding; which key the seller app sends.
- **E-12:** The fix for the stale-cache conversion defect and the v2 close amount skew (D18); the no-USD-row case; whether the legacy withdraw stays beside the ledger payouts.
- **E-13:** RF-01 to RF-11 (claims, refund-id reference, one transaction, rounding); the points floor LO-04; the vendor-panel return credit; automatic refund of cancelled wallet-paid orders (E04-F10).
- **E-14:** What loyalty_point_exchange_rate means (LF-1); the rounding rule (LF-4); recorded per-line points as the source (LF-6); the refund floor (LO-04); whether points depend on the wallet being on (LO-08).
- **E-15:** D15 (pay once, through which writer), D16 (credit the referrer in the vendor panel; correct past referee credits?), D17 (admin resubmission guard), D18 (USD or default currency), D19 (first-order rule).
- **E-16:** D18: the rounding rule (half up to cents) and sign-off of the Saudi behaviour change in PR 36; whether POS follows it; what loyalty_point_exchange_rate means.
- **E-17:** The legal-entity model; the opening balance of the Saudi seller chain (signed by the owner); settlement currency policy; conversion rules for any cross-currency reporting view.

## Axis F: Themes and brand

- **F-01:** Decided (DECISIONS.md GCC-07, GCC-19): independent theme per country on a shared component library; Saudi Arabia is the initial default storefront. Still open: what a storefront without its own theme serves (the default storefront's theme or the built-in templates), which existing theme becomes Saudi's, and the Q-19 details (copy or share banners and assets; website and customer app published separately or together).
- **F-02:** Decided (GCC-07): independent drafts, versions and publishing per storefront; no cross-country accidental publication. Open (Q-19, proposed option a): copy, not share, banners and assets; whether the website and the customer app publish separately per store.
- **F-03:** Plan line 319 asks for independent publish per store and platform; GCC-09 only says sections and layout may differ. Decide whether a separate web and app publish is required. Q-43: the seller app shows no theme (proposed option a).
- **F-08:** Which time zone a scheduled publish and section windows use per storefront (one clock today; AE and OM are an hour ahead of SA, KW, QA and BH).
- **F-11:** Q-19: when a theme is copied between countries, copy or share the banners and assets (proposed option a: copy).
- **F-12:** What a storefront may override (colours, fonts, layout) and who owns the shared default identity.
- **F-13:** Which brand fields a country may override, who approves them, the fallback rule, and any trademark or registration limit per country.
- **F-14:** Legal entity names, registration and tax numbers per storefront; whether the brand may appear beside the legal name on invoices; which fields an order snapshots.
- **F-15:** Whether theme_view is read-only for the whole builder (PR 66 note) and who may publish a storefront's theme (staff scope, slice 4).

## Axis G: Admin panel and staff permissions

- **G-02:** #74 decision 3 (may the master admin restrict itself) and 6 (entity dimension; the intersection with platform grants is built in #84); who may assign scopes (console only today) and whether a panel screen with audit and step-up is wanted.
- **G-03:** #74 decisions 1 (import for several countries: refuse now or add a country field), 4 (moderating seller products, all SA), 5 (copy between countries from the gallery) and 7 (read the three Arabic strings in new-messages.php).
- **G-04:** #77 decisions 1 (unattributed orders need a permission, not a country), 2 (mixed-country orders: refuse them in the POS for everybody?), 3 (refund about one line of a mixed order), 5 (POS refusal wording), 6 (layout counter cost: cache, or stamp the country on the order).
- **G-05:** #82 decisions 12 (cache the layout counters or keep per-country counts), 13 (hide, show to an all-country employee, or split by line share), 14 (sales of a product in a mixed order), 15 (customer wallet and loyalty reports), 16 (delivery-man ratings), 17 (recent searches).
- **G-06:** #82 decision 17 (recent searches that predate the restriction).
- **G-07:** #82 decisions 15 (wallet and loyalty reports are not narrowed today) and 13 (hide, show to an all-country employee, or split); the name and holder of the cross-country permission.
- **G-08:** #74 decision 2 (which out-of-slice screens are acceptable on the day the switch is turned on) and how inventory locations get a country (unit C).
- **G-09:** #74 decision 2 (which out-of-slice screens are acceptable on the day the switch is turned on).
- **G-10:** #82 decision 15 (wallet and loyalty reports and the wallet bonus tab are not narrowed); whether an export needs an audit line and a watermark (L-14).
- **G-11:** none (technical); whether queued exports and mail render per storefront is the A1-14 owner question.
- **G-12:** Reading of plan line 374: one role with a set of countries (built) or a different role per country and company; whether an entity scope is wanted before launch (#74 decision 6).
- **G-13:** Section 12 items 1 to 7 of GCC-20-PLATFORM-ADMIN-API.md as listed in #84: own switch, trail for a restricted employee, every-market grant holder, no-market sign-in, telling the app, ending sessions on scope change, a staff switch on but not enforced.
- **G-14:** Which admin actions need step-up or dual approval and who approves; the open points of #66 (the theme builder section add, update and delete do not ask canEdit; the people module can create a role with any module grant).
- **G-15:** Retention and tamper evidence of the panel audit trail; whether the own trail is enough or the panel's audit_logs must also be readable per market (L-04); who may read every country's lines.
- **G-16:** Where each company's secrets live and who may see them (D2-08, GCC-14 scope). DR-1 and DR-2 are decided as deferred (no rotation) and only referenced here.
- **G-17:** Whether people managers may edit master #1 (#57); whether a role editor is limited to the modules it holds (#66 note: may be intended, then it should be said); who may widen an employee's scope.
- **G-18:** Release plan decision 5 (roles that lose access: add the keys to the roles before stage 3); the 24 routes left open on purpose; whether the any-of lists for products/search and the invoice are accepted.

## Axis H: Shipping and integrations

- **H1-01:** Which source-to-destination pairs may open first, who may switch a pair on, and whether any pair needs a legal check first (Discovery Q-13).
- **H1-02:** Q-33: which carrier serves which country under which contract and company id, and whether one provider code may serve two countries.
- **H1-03:** Who decides and maintains the permission lists, and whether the default is deny for every foreign destination (Discovery Q-13, option a).
- **H1-04:** Q-13: which regulated classes may be shipped across each border, who approves that list (a competent authority or counsel), and that an unclassified product is refused (option a, closed by default).
- **H1-05:** The licensed source of region and city lists for AE, KW, QA, BH and OM, which postal and phone formats to enforce per country, and the unified address mode per storefront (Q-23).
- **H1-06:** Whether staff may place a cross-border order by hand from the admin panel or POS. The pairs, regulated classes and duty rules are decided under H1-01, H1-04 and H1-09.
- **H1-07:** Q-10 first legal-entity model; Q-39 one-entity cart against seller split; which entity invoices a cross-border sale (the source entity, by GCC-16).
- **H1-08:** Q-07 decimals and rounding per currency; Q-08 conversion policy for new stores (no automatic FX is decided); who sets cross-border shipping rates.
- **H1-09:** Q-11: tax and invoice per entity and who approves rates; whether the seller or the buyer pays duty per route; which entity invoices a cross-border sale.
- **H1-10:** The licensed source for the five countries' lists and who approves city mappings per country; whether a Saudi mapping may be reused on a cross-border route.
- **H1-11:** Q-33: which provider serves which country under which contract; Q-24: whether Delivery Syria stays disabled in Gulf storefronts (proposed: yes).
- **H1-12:** Merging #1 also changes CLAUDE.md on main (its own note): the owner merges it, or only its Torod test line is cherry-picked.
- **H1-14:** Q-24: which carriers and shipping methods each market uses, and whether the Delivery Syria method stays off in Gulf storefronts.
- **H2-02:** none open for coding. Who handles an FBPI return is unresolved in noon's own documentation.
- **H2-03:** Item 1: leave Trendyol invoices to the seller panel (Gulf endpoint needs a public 10-year link; recorded, awaiting approval). Item 2: no Unsupplied item cancellation from the panel (awaiting approval).
- **H2-04:** Closed: final posting not enabled (approved item 3). Open: who enqueues (schedule or button), whether estimates are ever posted (today only settled), Odoo plan and key renewal.
- **H2-06:** Whether one seller id serves several storefronts or each legal entity has its own accounts; which channels each country uses.
- **H2-07:** Which channels the owner uses per country; Amazon accounts of other marketplaces or of two marketplaces (open in #76); whether dispatch may ever leave SA (carrier unit).
- **H2-08:** Price decimals per currency; whether channel prices ever follow storefront prices (today manual only, by design); VAT-inclusive pricing per market.
- **H2-09:** Which products are regulated in each market and what clearance a channel listing needs there; the launch value of the mode per country.
- **H2-10:** How many Odoo databases and companies (one per legal entity?); which entity books each channel order; whether the Saudi company's posted references are frozen.
- **H2-11:** Per-entity cost base currency and exchange-rate source; whether estimated costs may ever be posted (today only settled).
- **H2-12:** What pausing a storefront does to its channel accounts (stock, prices, orders); orders and webhooks must keep being received (GCC-12).
- **H2-15:** DR-1 and DR-2 are decided: no rotation now, open and not remediated (DEFERRED_RISKS.md).
- **H2-17:** Whether inventory locations are per country or global with a country filter (unit C's decision).

## Axis I: Marketing, policies and operations

- **I1-01:** One shared sender or one per store at launch, and who owns each market's sender domain (SPF, DKIM). Production's mail identity is UNVERIFIED (Discovery Q-32); no owner document answers it.
- **I1-02:** Whether each market gets its own Firebase project (needs separate app builds) or one shared project with per-market topics (A1-17 lists it as open), and which SMS and WhatsApp accounts each market uses (Q-24, Q-32). No owner document answers either.
- **I1-03:** Which texts must differ per country (brand, currency, legal wording, Gulf Arabic) and who approves each variant. No owner document answers it; DECISIONS only allows inheritable branding defaults.
- **I1-04:** How a broadcast chooses its audience: one store, several or all, and whether 'all' stays possible for the platform owner. No owner document answers it (Discovery E06-F08).
- **I1-05:** One topic or one per market (A1-17 records it as open; no owner document answers it); whether the seller topic lavkoo_seller gets a sender (Discovery Q-44); which Firebase project each app uses (Q-32).
- **I1-06:** none beyond the GCC-05 decisions (Discovery Q-07 rounding, Q-08 conversion policy).
- **I1-07:** Which obligations continue during a pause and whether support replies and one-time codes count as necessary (Discovery Q-40, open). The necessary-versus-marketing line needs a legal reading per country.
- **I1-08:** Opt-in model per channel and market, and whether abandoned-cart reminders may run before consent is recorded (Discovery Q-15, open; its proposal is explicit opt-in per channel and market and no cart reminders in new markets until approved).
- **I1-09:** Retention period and redaction rule for delivery logs (not decided), and whether the log is per store or central.
- **I1-11:** Copy only: a dedicated customer message for a discount above the subtotal (PR 4); whether the admin wording 'must be greater than' should say 'at least' since equality is accepted (PR 2); Arabic text for the two defined refusal keys (PR 5); the seller-app decision on admin-assigned seller-bearer coupons (PR 52).
- **I1-12:** Open Discovery questions Q-04 (limits and first order per store or per identity), Q-07 (rounding and decimals per currency), Q-08 (conversion policy for new stores), Q-38 (no automatic conversion against the existing multi-currency model); and whether one coupon code may exist in two stores (today unique across the table).
- **I1-13:** GCC-17 (DECISIONS.md) already says each store has its own eligible items. Open: whether a store may copy another store's coupon definition (copy, no sync) and the Arabic and English refusal wording for a coupon of another store (Discovery Q-04 proposes everything per store).
- **I1-14:** GCC-17 (DECISIONS.md) says each store has its own limits and redemption. Still open: whether first order and the per-customer limit are counted per store or per customer identity (Discovery Q-04; its proposal is per store), what a budget caps (discounts given per store and period) and who may raise it.
- **I1-15:** GCC-17 (DECISIONS.md) decides that a central campaign may select markets and that each store has its own amounts, currency, limits, eligible items and redemption. Not written down: who may create and run a local campaign (plan line 426), what a campaign budget caps and who may raise it, and whether the one active flash deal stays system-wide or becomes per store (B-16).
- **I1-16:** The plan names no language list: which languages each market offers (Arabic and English assumed), whether Gulf Arabic variants are needed, one Arabic code ('ar' or the live 'sy'), and whether language follows the market or the customer. No owner document answers it.
- **I1-17:** Arabic wording of the GCC strings (backend PR 5, customer app PR 5 and 7, seller app PR 6), and whether keys may keep being written at run time on the server. No owner document answers either.
- **I2-01:** Retention period and deletion rules for legal texts and acceptances (Q-16). Whether seller terms are a separate document from customer terms (Q-16). Whether Saudi gets imported history or stays grandfathered only.
- **I2-02:** Which document types each country needs, in which locales, and whether English is required (PR 38 body: engineering defaults, a qualified reviewer confirms per country). The effective-date rules.
- **I2-03:** Who may write and who may approve legal texts (permission key, master admin only or not). Whether publishing needs dual approval. Whether seller terms are a separate document (Q-16).
- **I2-04:** Whether a non-Saudi page may fall back to the English text, or to nothing, when the Arabic text is missing. Whether seller terms are separate from customer terms (Q-16).
- **I2-05:** Which document types need explicit acceptance (none today), whether identity is stored, the retention period, and the per-storefront legal entity (PR 43 body).
- **I2-07:** Whether a recorded legal review is required by the system before a country can sell, who may record it, and which reviewer counts as independent.
- **I2-08:** List non-sellable storefronts as browse-only or hide them (PR 39 body). How a web visitor chooses a storefront: path prefix or switcher (PR 49 body).
- **I2-09:** Whether a point-of-sale or marketplace sale counts as a storefront sale (PR 49 body, runbook), and what happens to marketplace orders of a paused country.
- **I2-10:** Approve the Arabic notice wording; confirm the backend PRs are merged before the app flag is turned on anywhere (PR 5 body).
- **I2-11:** The state model and who may change it; the default stop mode (Q-20); whether staff may preview a Draft country.
- **I2-12:** Which items block and which are advisory per country, and whether the owner may override a blocking item (GoLiveOverride exists for fulfillment).
- **I2-13:** The default stop mode (Q-20 recommends Browse Only). Hide or list a non-sellable storefront (PR 39 body).
- **I2-14:** Q-40: whether order, refund and support pages stay open during a store stop (the audit recommends yes). Maintenance message wording and duration rules.
- **I2-15:** Whether the redirect is 301 or 302, whether apps are moved automatically, and the default stop mode (Q-20).
- **I2-16:** Q-40: whether order, refund and support pages stay reachable in every stop mode. What settlement and shipment sync do for a paused country.
- **I2-17:** When and by whom the default may change, and whether the default may ever be paused.
- **I2-18:** When the first country is activated, what proves Saudi continues, and approval to rehearse on a pilot server.

## Axis J: Flutter customer app

- **J-02:** Arabic wording of the ten new strings and the six country names; the shared 'select' button reads as 'he chooses' in Arabic; bn, es, hi lines are unreviewed.
- **J-05:** Currency symbol or code per language (for example KWD or the Arabic symbol), trailing zeros and rounding mode: finance to confirm.
- **J-08:** Approve the Arabic notice wording (PR 5 asks) and the bn, es, hi lines; the app screens for Maintenance and Redirect wait for a backend mode field.
- **J-09:** Per-market gateway and BNPL eligibility (Paymob, MyFatoorah, Tabby, Tamara) per legal entity and merchant account (plan lines 303-307).
- **J-10:** What a link that names another storefront does (switch, ask, or open as the current one; manual choice precedes inference, plan line 189). Which host is production for iOS (pharmacyksa.com or lavkoo.com).
- **J-11:** Topic naming and whether the legacy sixvalley topic stays for SA; default marketing consent per storefront (legal).
- **J-12:** D1 of the owner guide: deploy lavkoo #60 without waiting for adoption of #4, or wait.
- **J-14:** none (address design decisions are in docs/address)
- **J-15:** Which document types need explicit acceptance per storefront and whether the customer's identity is stored with the acceptance (config gcc.storefronts.legal): legal and privacy review.
- **J-16:** Native-Arabic approval of the new Arabic strings and the six country names; wording of the shared select button.
- **J-17:** Which way to align the version numbers (lower the shop's floor to at most 3.0.6 and set appVersion to match, or renumber the app at or above 14.1). The charter forbids bumping a version or lowering a minimum on a test constant alone.

## Axis K: Flutter seller app

- **K-01:** Q-18: one seller app with a market switcher (header or scoped token) or one build per country; whether any seller works in more than one market (U6).
- **K-02:** Q-18 and U6: whether the seller app has a market switcher and whether a seller may work in more than one market.
- **K-03:** Q-18 (switcher or separate builds); whether All Markets is a view in the seller app at all (the owner overview is the Super Admin shell, unit L).
- **K-04:** Q-18 and U6: does one seller account trade in several markets (switcher), or does each market get its own seller account and legal entity; which seller data is global (profile, bank) and which per market.
- **K-05:** GCC-14B open decision 2: who changes the seller app to send country and handle 409 and 422, and when; whether a not-found scan should speak; whether Code 128 only is acceptable once two countries share an EAN.
- **K-06:** Finance owner: wallet debit rounding (D18) and the units of paid_amount (PR 35 'Not changed, open'); how long unsent outbox entries are kept.
- **K-07:** Refusal channel for #52 (audit row 10): keep HTTP 200 + status error (API-key integrators read it) or answer 403 with errors[0].message. The app reads both.
- **K-08:** Hide by role in the app or keep the 403 toast (the study doc's phase 4 was never done); staff push tokens (#52 row 8): accept owner-only or build per-staff device tokens.
- **K-09:** Q-17 and Q-18: whether sellers trade in several markets with one login; who holds the top scope; entity scope as a second dimension (14C: entities have no table yet).
- **K-10:** Where decimals live (server per storefront or an app table); whether multi_currency is dropped for GCC builds.
- **K-11:** Q-44 (D-14): remove or feed the lavkoo_seller topic (E19 U7 asks whether it was ever sent to by hand); per-staff push tokens (#52 row 8); whether pushes for necessary order notices continue while a market is paused (GCC-12).
- **K-12:** Where queued offline sales live after sign-out; whether a stored market is per account or per device.
- **K-13:** Wording of scan: المسح or المسح الضوئي (the app#5 body points to the existing scan_item_or_add_from_item).
- **K-14:** Options A, B or C of the 2026-11-12 audit: correct the test constant with evidence, release a build at or above the minimum, or lower the production minimum.
- **K-17:** Q-18 and U6; refusal channel (#52 row 10); staff push tokens (row 8); admin-assigned coupons (row 15); digital file types (row 35); the lavkoo_seller topic (Q-44 / D-14).
- **K-18:** Q-43 (D-12; risk R-117): the seller app's theme scope (own static theme versus consuming the theme contract, or only image rules) and whether the app shows the market's brand (GCC-10).

## Axis L: GCC-20 Super Admin

- **L-01:** Who holds a grant (owner only, or country managers as restricted viewers; whether an every-market grant needs the master role); address allow-list or a separate host (contract s.10 items 3 and 6, s.12 item 3).
- **L-02:** MFA policy for restricted viewers and for levels 2/3, passkeys, recovery-code custody (including whether the owner's own account has codes), lockout without an unlock command (contract s.10 items 1, 10, 11).
- **L-03:** Token lifetime and refresh (480 min, 60 idle, 5 sessions), a panel screen to end sessions, whether sessions is always inside a capability set (contract s.10 items 2, 7, 12).
- **L-04:** Retention in days, export before prune, who records the head hash and where it is kept out of the DB administrator's reach (contract s.10 item 8); whether the panel's business audit_logs should also be readable per market.
- **L-06:** Whether a build with the flag on may reach sellers' phones (the entry shows on every seller login screen) or a separate build is wanted; an inactivity lock for the shell (Discovery-02 F-18).
- **L-07:** GCC-05 (record market and currency on every order); whether a restricted viewer may ever see the unattributed bucket (contract s.10 items 3, 4).
- **L-08:** GCC-05 (record market and currency on every order; contract s.10 item 4); the legal entity of each market (DECISIONS: separate selling company per country).
- **L-09:** Which signals the owner wants first and their thresholds (all placeholders today); the default capability rule for the new part.
- **L-10:** Alert thresholds (zero stock 10 and 50, pending 24 h, 1, 5 and 20 are placeholders for a Saudi-sized catalogue; contract s.10 item 5).
- **L-12:** Contract s.12 items 1-7 (switch of its own, trail for a restricted employee, every-market grant holder, no-market sign-in, telling the app why, ending sessions on scope change, a scope that is on but not enforced) and s.10 item 12 (capability sets and end dates as policy).
- **L-13:** Which per-market views the owner wants first, which workflows stay desktop-only (Discovery item 9), and what personal data a list may show.
- **L-14:** Which reports the owner needs, the watermark and retention policy, and whether a restricted viewer may export.
- **L-15:** Separate authorization for each action class (level 2 later, level 3 per class), the dual-approval policy, who may hold level 2/3, and whether step-up replaces or adds to MFA (contract s.10 item 1).
- **L-16:** Whether push is wanted and for which severities (contract s.10 item 9); topic naming and per-market subscription; which FCM project to use.
- **L-17:** The live backend seller_app_version_control minimum and whether 1.0.0+3 shipped (docs/gcc/audits/2026-11-12-seller-version-verification.md); whether the shell needs its own minimum version; whether a flag-on build may be released.
- **L-18:** Alert thresholds sized for the real catalogue, the acceptable latency budget, and whether a short server cache is wanted (contract s.10 item 5).

## Axis M: Security, tests and deployment

- **M1-01:** none; server reads need the administrator's authorization (charter section 4).
- **M1-02:** Decision 2 (route A or B) decides which order matters first; none otherwise.
- **M1-04:** Whether stage 0 goes before stage 1 (PR 85 body); keeping, rotating or deleting the old log files (administrator and owner); decision 15.
- **M1-05:** 1 (APP_MODE and fixed test codes), 3 (customer app and delivery-man app first), 4 (duplicate phones, trunk-zero numbers), 15 (public repositories); registration with any phone marked verified; reset codes have no lifetime.
- **M1-06:** 6 (finance review of #52 before stage 2), 7 (behaviour changes: staff cannot change the owner's profile, no staff push token, admin-assigned coupons answer 403 on edit, refusals ride HTTP 200); the vendor-row exposure and the three low write doors.
- **M1-07:** Whether these ship in the hotfix or only through main; D20 (status check inside get_seller_by_token); a guest token for guest orders (PERM-05); who sets PAYMERA_SECRET_KEY.
- **M1-08:** 5 (roles that lose access, restock toast), 9 (about 80 routes without a permission, stored theme SVGs); admin auction helpers stay without a module key (decided).
- **M1-09:** 8 (private files stay off, moved in a separate step), 9 (file types, stored theme SVGs), 10 (download lock), 11 (server checks before the stage).
- **M1-11:** 14 (the overlay job stays red); set the repository variable PILOT_BASELINE after the first deployment; whether to extend the tool or keep the manual loop.
- **M1-12:** Route A or B (decision 2); decisions 1, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15 of plan section 6; when to deploy stage 1; who keeps the database dump and for how long.
- **M1-13:** Who is the finance owner, the security reviewer and the Arabic reader; whether #52 waits for finance before stage 2 (decision 6); whether the Arabic text of the security PRs needs a reader.
- **M1-14:** Whether to build a staging at all and where; confirm that only invented data is used; who may reach it.
- **M1-15:** DR-3 (public repositories) and DR-4 (protect main) are undecided; DR-1 and DR-2 deferred on 2026-11-18; DR-5 is information for DR-3.
- **M1-16:** DR-4 (protect main); DR-3 and DR-5 (a private repository costs CI minutes); raise the 110-minute ceiling or split the group (the owner's call, handoff).
- **M1-17:** None of its own; the seller app's production minimum version needs the owner's check (guide section 7).
- **M1-18:** Whether a switch may be turned off again once a second country has data or must be refused; deactivating the country is the kill switch (storefront runbook).
- **M2-01:** Sign-off of the SAR wallet debit change (#36: a 10.01 USD order debits 10.01, not 10.010667). Pin the 0-decimal SAR display as a known defect or fix it. Which pinned defects to fix (#24 four, #6 add-fund bonus missing from the ledger balance).
- **M2-02:** Whether point-of-sale and marketplace-order sales are storefront sales (#49, runbook). Wishlist and compare adds accept another country's id; the quick view answers 500 for a hidden id (runbook known limits).
- **M2-03:** From #74, #77, #82 bodies: bulk import for an employee with several countries; whether the master admin may restrict itself; who sees unattributed orders; hide or show money that cannot be split; moderation of seller products (all SA); legal-entity scopes; accept the partial isolation before anyone is assigned.
- **M2-04:** Identity key (phone with dial code or e-mail); consent texts and channels per country; whether the customer sees orders of every country in one list (DECISIONS allows it); two customers sharing a phone cannot reset a password (OWNER_PR_GUIDE section 7).
- **M2-05:** Rounding rule (half up to whole cents) and the SAR behaviour change in #36; opening-balance policy for Saudi balances; referral and loyalty rules per market (Discovery question A3); whether POS wallet sales belong to a storefront.
- **M2-06:** Whether POS and marketplace sales are storefront sales (#49); the legal entity per country and its gateway accounts; how historical multi-vendor orders map to one selling entity.
- **M2-07:** Rounding rule for KWD, BHD, OMR; whether the Saudi 0-decimal display is a known defect; storage unit of historical amounts; exchange-rate snapshot policy; whether cost price may appear in customer JSON (price-stock map finding 3).
- **M2-08:** Which pinned defects to fix first (#24); a released reservation of a cancelled order stays released on reopen and may allow overselling (#24 note); authority of the own-warehouse stock switch.
- **M2-09:** Which products may cross a border; courier per country; who verifies regulated-goods rules; tax and import-export treatment (GCC-16 says verify, nothing is decided).
- **M2-10:** Theme storage for independent per-country drafts; who runs the device test plan; the four new Arabic strings of lavkooapp#7 and the browse-only notice need a native reader.
- **M2-11:** Gateways and methods per country and legal entity (Tabby, Tamara); settlement currency per market; refund to the original method or to the wallet; finance and security review of the payment finish path (#53).
- **M2-12:** Amazon accounts of other marketplaces or of two marketplaces (#76); whether channel-order sales are storefront sales; channel accounts per legal entity.
- **M2-13:** Required documents and locales per country (a qualified reviewer must confirm); which types need explicit acceptance; whether an acceptance stores identity; the Arabic strings of #38 and #42.
- **M2-14:** The pause policy per mode and its customer text (native Arabic); whether POS keeps selling in a paused store; who may pause (staff scope, platform API level 2); how refund windows run after closure.
- **M2-15:** Branch protection and the required checks (DR-4); whether the Catalog and Staff scope groups join the main workflow; closing #75, #78, #81, #83 once their results are read.
- **M2-16:** Deploy order and off-peak window for migrations that purge data; whether a structure-only copy of the real schema may be made (operator-run, separately authorised); an index on products.storefront_country only after reading plans on real data.
- **M2-17:** Opening balance signed by the owner; storage unit of historical balances; tolerance zero; timing of the contract phase (two clean closes proposed); whether and by whom an isolated KSA-derived copy can be produced.
- **M2-18:** Which market-specific pause the scheduler needs; monitoring retention; platform API levels beyond read-only (GCC-20); setting CACHE_DRIVER to a shared store before the flag is turned on (guide: never array).
- **M2-19:** Redirect and canonical policy for old Saudi URLs; whether unprefixed Saudi URLs stay forever; which provider defaults move to per-account settings.
- **M3-05:** Legal-entity model (Q-10), and whether the country column plus config registry stays instead of storefronts and legal_entities tables.
- **M3-07:** SECURITY_RELEASE_PLAN decision 2 (A: merge and deploy main, or B: baseline hotfix) and the order of the GCC chain after the security set.
- **M3-09:** Whether this roadmap, rebased on the drafts, is the plan of record for the order of merges.
- **M3-11:** Answers to Q-01..Q-50 and D-01..D-10 (only D-01 B answered, D-05 deferred); approval of the N6 errata; the force-push decision for the three public commits with unredacted text.
- **M3-12:** Close G0 or accept UNKNOWN for pharmacyksa98 (Q-30, D-02); approvals to merge or release any phase; the SECURITY_RELEASE_PLAN decisions.
- **M3-13:** Whether new owner-facing documents (guide, plan, tracker) also need an Arabic edition.
- **M3-15:** Read access to pharmacyksa98 (Q-30) and read-only production facts (Q-27, Q-29).
- **M3-16:** PR 3 sign-off by the payments owner.
- **M3-17:** Approve the N6 errata to DECISIONS.md and STATUS.md (still not applied); whether the audit branch documents are merged to main.
- **M3-18:** Raising the 110-minute ceiling or splitting the sales-channel A-M group (handoff: the owner's call).
