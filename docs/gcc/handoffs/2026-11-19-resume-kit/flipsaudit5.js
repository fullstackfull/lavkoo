export const meta = {
  name: 'flips-audit-5',
  description: 'Independent audit of what the backend pins of the draft pull requests 116 to 121 prove (CI runs 360 to 365): each criterion is read by a skeptical verifier against its sentence, the tests and the CI logs, and every decision is judged by a second agent',
  phases: [
    { title: 'Verify', detail: 'one skeptical verifier per unit' },
    { title: 'Judge', detail: 'a second agent decides every criterion the verifier would change' },
  ],
}

const SP = '/tmp/claude-0/-home-user/53272e27-a6c8-5c39-bbc4-fcca8e45650f/scratchpad'

const COMMON = `
You audit claims in a project tracker. You change NOTHING in any repository, you push nothing, you commit nothing. You may read any file and run read-only commands, and you may run tests only in a throwaway detached worktree of your own (git -C /home/user/lavkoo worktree add --detach ${SP}/wt/<your own unique name> <full sha>, then cp -al ${SP}/wt/legal/vendor vendor, then APP_KEY="base64:$(openssl rand -base64 32)" php vendor/bin/phpunit --no-progress <file>); remove your worktree with git -C /home/user/lavkoo worktree remove --force when you are done. Never run tests in the worktrees of the other agents. The repository clone /home/user/lavkoo has all the branches as origin/<branch> (git fetch origin first).

THE QUESTION. The tracker (the generated files ${SP}/wt/handoff/docs/gcc/tracker/axis-*.md, the working copy; find a criterion by its id in the card of its requirement, criteria are numbered from 0 in the order of the card) holds each criterion below with a state (MISSING or CODED) and an evidence sentence that does NOT yet mention the draft pull requests of this audit. Your job is to decide, for each criterion, whether the criterion's OWN SENTENCE, every part of it, is proven by the tests of the pull request named, run by the CI jobs named, on the head named. Be adversarial: the previous rounds of this project found that evidence sentences overclaimed (a test that never signs in cited for a sign-in criterion; a structural scan cited for a behaviour; a rule tested with hand-built rows cited for the application's use of the rule; a test that skips itself on an engine cited as run on it). Check, for each criterion:
(1) The sentence has parts (a list of things, a negative case, a condition). For every part name the test (class and method) that asserts it, by reading the test. A part no test asserts is a gap. A part asserted only by a source scan or a route-table scan is structural: say so.
(2) The test can fail: read the assertion; is the expected value computed by the code under test (circular)? is it an assertion that passes whatever the code does? Mutation notes are in the commit messages of the branch (git log --format=%B); re-run at least TWO mutations yourself in your throwaway worktree (remove the behaviour, see the named test fail, discard the worktree), at least one that the commit message does not claim.
(3) The CI: the logs of the cited runs are saved as text in ${SP}/job_<run number>_<job>.txt (the file names are given below); read the PHPUnit summary line ("Tests: N, Assertions: N, ... Skipped: N") and, where a skip matters, the list of skipped tests (grep -n "skipped test"). Check that the directory of the test is run by that job (the Storefront jobs run tests/Unit/Storefront and tests/Feature/Storefront; the Fulfillment + security jobs run tests/Feature/Security; the whole-suite SQLite jobs run everything; the strict-mode job runs a short list of classes), and which engines each test really ran on. The deployment overlay job is red by design and the Torod test is red on main: neither says anything about these pull requests.
(4) The evidence: write the sentence the tracker should carry (see REPLACEMENT below); are the names, counts, heads and numbers true?
(5) A criterion that the implementers admit to be only partly covered stays CODED, whatever the number of tests; MET only when every part of the sentence has a behaviour test that ran green in CI on the head.

DECISIONS. For each criterion give the END STATE and the decision that gets there from the state the tracker holds now: KEEP (the tracker's state and text are right as they are: rare, since the text does not mention the new work), SET_CODED (MISSING today, code and tests exist, a part of the sentence is not proven), UPGRADE_TO_MET (the sentence is fully proven), CORRECT_TEXT (CODED today and CODED stays; the text needs the new work), DOWNGRADE_TO_CODED or DOWNGRADE_TO_MISSING (the tracker holds a state the evidence does not support). REPLACEMENT: for every decision other than KEEP give the full evidence sentence the tracker should carry, plain and exact: it begins with the pull request (number, draft, base, head, what it changes: tests only or code too), names the test classes and counts, the CI run (number, head, event), the jobs and engines it ran on with the totals and skips, says what the tests assert, and ends with what is NOT covered. No claim a test does not assert. Be specific and concise; do not pad.
`

const UNITS = [
  {
    key: 'U11-coupons',
    desc: 'Coupons. (A) lavkoo draft pull request 116 (branch gcc/p1-security-coupon-ownership, head 8ab3a4dd0cd2fe1440812c8f08c4e9dbdb0a3174, based on main; it CHANGES three application files, the seller app v3 CouponController, the vendor panel CouponController and CouponRepository, and adds SellerAppCouponOwnershipTest (43) and VendorPanelCouponOwnershipTest (31) in tests/Feature/Security, with the support trait BuildsCouponOwnershipWorld; 86 tests with the provider cases; it fixes four ownership defects: a search of the seller app list that returns other vendors\' coupons, the seller app editing and switching an admin coupon made for every vendor, the panel quick view opening any coupon, the panel delete answering 500). CI run 360 (pull_request, id 44442113892): 15 of 21 jobs success; the six others are the deployment overlay (red by design) and five jobs whose only failure is TorodShipmentDiscoveryTest, red on main. Logs: job_360_fulfsec_mysql.txt (Fulfillment + security tests, MySQL 8: 1700 tests, 8812 assertions, 3 skipped), job_360_fulfsec_mariadb.txt (MariaDB 11.4.2: 1700, 8815, 2 skipped), job_360_sqlite83.txt (Tests SQLite PHP 8.3, whole suite, 6286 tests, 1 failure: Torod), job_360_sqlite82.txt. (B) lavkoo draft pull request 120 (branch gcc/p1-coupon-behaviour-pins, head 2ab62bb5836a27295b1c3ff5dd4f2ab6fa83e6a8, stacked on pull request 96 e624e43e, TESTS ONLY, no application file): CouponBehaviourPinsTest (25), CouponCurrencyRoundTripTest (24), CouponOwnershipTest (25), support BuildsCouponCheckoutWorld, in tests/Feature/Storefront; CI run 364 (pull_request, id 44444087809, 22 of 23 jobs success, overlay red by design): job_364_mysql.txt and job_364_mariadb.txt (Storefront tests: 926 tests, 4319 assertions, none skipped), job_364_sqlite83.txt (whole suite 7215 tests, 99 skipped). The implementers admit: the four ownership defects are NOT pinned in pull request 120 (they are fixed in 116 whose tests cover them); delete, export, seller staff permissions, store and check-coupon are not covered anywhere; the admin edit form floors stored amounts (4.35 shown 4.34, 1.005 shown 1.004; multi-currency 10.505 at 0.307 shown 10.504) so I1-12.1 and I1-12.2 are not proven; I1-12.0 is pinned for the decimals settings it runs (2 for the riyal, 3 for the dinar) and not for a three-decimal currency at setting 2; the delivery posting of the bearer is a direct call; checkout-driven cases skip on a strict SQL server (the CI jobs here are not strict). Never touch or re-pin the minimum purchase comparison of OrderManager::getTotalCouponAmount. The tracker cards: I1-11, I1-12 and I1-14 in axis-i-marketing-policies-and-operations.md. Criteria to decide: I1-11.3 (a vendor lists, views, edits, switches and deletes only their coupons: judge it on pull requests 116 and 120 together), I1-11.4, I1-12.0, I1-12.1, I1-12.2, I1-14.0, I1-14.1.',
    criteria: ['I1-11.3', 'I1-11.4', 'I1-12.0', 'I1-12.1', 'I1-12.2', 'I1-14.0', 'I1-14.1'],
  },
  {
    key: 'U12-writers-and-stop-modes',
    desc: 'The writers of orders and the stop modes. (A) lavkoo draft pull request 117 (branch gcc/p1-order-writers-gate-list, head 7a638ed1d4a44a4b7f872c5ff5c0ec7689f3a9cf, stacked on pull request 111 7e19d284, TESTS ONLY): StorefrontOrderWritersTest (21), OrderWriterScannerTest (479, in tests/Unit/Storefront) with tests/Support/OrderWriterScanner.php and TokenStream.php, ChannelOrderSaleGateTest (2, in tests/Feature/SalesChannels); CI run 361 (pull_request, id 44443241331, 22 of 23 jobs success, overlay red by design): job_361_mysql.txt and job_361_mariadb.txt (Storefront tests: 1441 tests, 5361 assertions), job_361_sqlite83.txt (whole suite 7732 tests, 99 skipped), job_361_channels_am_mysql.txt (sales channels A-M, MySQL 8.0: 1216 tests; holds ChannelOrderSaleGateTest). Criteria: I2-09.0 (every writer of orders, the POS ones included, carries the sale gate: the pull request says NOT met, the three POS routes carry no gate), I2-09.1 (the marketplace order materializer: no owner rule recorded, the test pins the current behaviour), I2-09.2 (a scan lists every writer of orders and fails on a new one or a writer without a gate, an exemption with a reason or a pending criterion) and I2-09.3 (the order records its storefront: the pull request reports that the admin and vendor POS and the marketplace materializer record none). (B) lavkoo draft pull request 119 (branch gcc/p1-stop-mode-pins, head 71a05e62af27a1ca123b4d09362e5c614fd07d82, stacked on pull request 111 7e19d284, TESTS ONLY): StorefrontStopBrowsingTest (50), PlatformMaintenanceModeTest (28), support ReadsResolvedRouteMiddleware and BuildsSixStorefrontCheckoutWorld, all in tests/Feature/Storefront; CI run 363 (pull_request, id 44444062184, 22 of 23 jobs success, overlay red by design): job_363_mysql.txt and job_363_mariadb.txt (Storefront tests: 1019 tests, 5257 assertions, none skipped), job_363_sqlite83.txt (whole suite 7308 tests, 99 skipped). Criteria: I2-13.2 (a storefront that may not sell still serves cart, wishlist and catalogue and refuses only a new sale; the implementers say the website\'s own cart and wishlist routes are structural only and several cart routes are not driven) and I2-14.0 (the platform maintenance mode: website, vendor panel and config answer closed for everyone but an administrator; the implementers say the home page is a stand-in controller behind the real route and gate, the platform maintenance is cache-only (optimize:clear ends it) and the config flag is on for any ticked system). The tracker cards: I2-09, I2-13 and I2-14 in axis-i-marketing-policies-and-operations.md (and M2-14 in axis-m if a criterion repeats). Criteria to decide: I2-09.0, I2-09.1, I2-09.2, I2-09.3, I2-13.2, I2-14.0.',
    criteria: ['I2-09.0', 'I2-09.1', 'I2-09.2', 'I2-09.3', 'I2-13.2', 'I2-14.0'],
  },
  {
    key: 'U13-wallet-and-legal',
    desc: 'The wallet and the legal text store. (A) lavkoo draft pull request 118 (branch gcc/p1-wallet-contract-tests, head 533e4cbf09acf15a827797f6dd1a5e5c5b1d0a85, based on main, TESTS ONLY): CustomerBalanceAnswersTest (13), LedgerRowsAreNeverEditedTest (128, scanner tests/Support/LedgerEditScanner.php), VendorLedgerEntryTest (16), support BuildsWalletAndVendorLedgerSchema, all in tests/Feature/Storefront. CI run 362 (pull_request, id 44444043638): the workflow of main has NO real-engine job for tests/Feature/Storefront, so these classes ran only in the whole-suite SQLite jobs (job_362_sqlite83.txt and job_362_sqlite82.txt: 6357 tests, 1 failure which is TorodShipmentDiscoveryTest, red on main); 15 of 21 jobs success. Criteria: E-04.2 (the customer balances answered by the API: keys, types, meaning, the app reads), E-08.0 (no application code updates or deletes a wallet or loyalty ledger row: the pull request says this sentence is FALSE on main because WalletTransactionRepository and LoyaltyPointTransactionRepository update and delete rows, with no caller; the four statements are pinned by file and text, not excused), E-17.0 (the vendor ledger entry: currency as passed, idempotency of a repeated event, never edited: the pull request says it holds for the class\'s own methods only, PayoutService and SettlementEngine edit entries, the existence check races, the table has no migration). (B) lavkoo draft pull request 121 (branch gcc/p1-legal-store-pins, head af88f04560aa243157a8bc7e83c2cc28787804af, stacked on pull request 115 d5755860, TESTS ONLY): StorefrontLegalDocumentVersionKeyTest (11), PolicyRegistryLegalSeparationTest (27), ComplianceClaimScanTest (138), ComplianceClaimPayloadsTest (9), support ComplianceClaimScanner, in tests/Feature/Storefront; CI run 365 (pull_request, id 44444113015, 22 of 23 jobs success, overlay red by design): job_365_mysql.txt and job_365_mariadb.txt (Storefront tests: 1250 tests, 4860 assertions, 6 skipped, all AdminStorefrontMenuTest side-bar cases, SQLite only by design), job_365_sqlite83.txt (whole suite 7539 tests, 99 skipped). Criteria: I2-01.2 (the legal document unique key: country, type, locale and version), I2-06.1 (the policy registry is unchanged: the pull request says structural only, groups and keys pinned, definitions not), I2-06.2 (policy names never equal a legal document type: judged by name only), I2-07.0 (no claim of legal compliance anywhere a customer reads: the scan found four staff-panel help sentences that do claim compliance, tolerated by name; English and Arabic only) and I2-07.4 (a guard scans language files, views and payloads for claims of compliance and fails on a new one: a heuristic over phrases). The tracker cards: E-04, E-08 and E-17 in axis-e-wallets-loyalty-and-financial-records.md; I2-01, I2-06 and I2-07 in axis-i-marketing-policies-and-operations.md. Criteria to decide: E-04.2, E-08.0, E-17.0, I2-01.2, I2-06.1, I2-06.2, I2-07.0, I2-07.4.',
    criteria: ['E-04.2', 'E-08.0', 'E-17.0', 'I2-01.2', 'I2-06.1', 'I2-06.2', 'I2-07.0', 'I2-07.4'],
  },
]

const DECISIONS = ['KEEP', 'CORRECT_TEXT', 'DOWNGRADE_TO_CODED', 'DOWNGRADE_TO_MISSING', 'UPGRADE_TO_MET', 'SET_CODED']

const VERDICT = {
  type: 'object',
  properties: {
    unit: { type: 'string' },
    decisions: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          id: { type: 'string' },
          current_state: { type: 'string' },
          decision: { type: 'string', enum: DECISIONS },
          parts_of_the_sentence: { type: 'array', items: { type: 'object', properties: { part: { type: 'string' }, proven_by: { type: 'string' }, kind: { type: 'string', enum: ['behaviour', 'structural', 'none'] } }, required: ['part', 'proven_by', 'kind'] } },
          engines: { type: 'string' },
          reason: { type: 'string' },
          false_phrases: { type: 'array', items: { type: 'string' } },
          replacement: { type: 'string', description: 'the full evidence sentence the tracker should carry when the decision is not KEEP, else empty' },
        },
        required: ['id', 'current_state', 'decision', 'parts_of_the_sentence', 'engines', 'reason', 'false_phrases', 'replacement'],
      },
    },
    mutation_probe: { type: 'string' },
    notes: { type: 'string' },
  },
  required: ['unit', 'decisions', 'mutation_probe', 'notes'],
}

const JUDGED = {
  type: 'object',
  properties: {
    id: { type: 'string' },
    final_decision: { type: 'string', enum: DECISIONS },
    agrees_with_verifier: { type: 'boolean' },
    reason: { type: 'string' },
    replacement: { type: 'string', description: 'the full evidence sentence the tracker should carry when the final decision is not KEEP, else empty' },
  },
  required: ['id', 'final_decision', 'agrees_with_verifier', 'reason', 'replacement'],
}

const results = await pipeline(
  UNITS,
  (unit) => agent(COMMON + `\nUNIT ${unit.key}: ${unit.desc}\nCriteria to audit: ${unit.criteria.join(', ')}.\nStart by reading each criterion line in the tracker, then the tests, then the job logs.`, { label: 'verify:' + unit.key, phase: 'Verify', schema: VERDICT }),
  (verdict, unit) => {
    if (!verdict) {
      return Promise.resolve({ unit: unit.key, verdict: null, judged: [] })
    }
    const flagged = verdict.decisions.filter((d) => d.decision !== 'KEEP')
    if (!flagged.length) {
      return Promise.resolve({ unit: unit.key, verdict, judged: [] })
    }
    return parallel(flagged.map((d) => () => agent(COMMON + `
You are the JUDGE. A first verifier audited unit ${unit.key} (${unit.desc}) and decided ${d.decision} for the criterion ${d.id}. Decide independently, from the code, the tests and the CI logs, whether the criterion's own sentence is proven, and give the final decision. You may overrule the verifier in either direction: a flag that is wrong (the sentence IS proven by a test you can name) must be reversed, and a gap the verifier missed must be added. Do not accept their reading: re-read the sentence, the tests and the logs.
The verifier's decision on this criterion (a claim to check, not a fact):
${JSON.stringify(d, null, 1)}
The verifier's notes: ${verdict.notes}
When the final decision is not KEEP give the full evidence sentence in 'replacement': plain, exact, naming the test classes, the counts, the run, the head and what is not covered; no other claim.`, { label: 'judge:' + d.id, phase: 'Judge', schema: JUDGED }))).then((judged) => ({ unit: unit.key, verdict, judged: judged.filter(Boolean) }))
  },
)

return results.map((r, index) => (r ? r : { unit: UNITS[index].key, failed: true }))
