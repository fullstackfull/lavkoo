export const meta = {
  name: 'wave5-closures',
  description: 'Four closable criteria with real code and proof: theme caches, design identity and banner reach per storefront (F-04.5, F-12.2, F-12.3, F-10.2), marketing sends and the notification type registry (I1-07.2, I1-07.3), the seller app request and answer flows (K-16.4) and the order currency in the customer app (J-13.1); each reviewed by an independent agent',
  phases: [
    { title: 'Implement', detail: 'one implementer per task in its own worktree' },
    { title: 'Review', detail: 'an independent reviewer re-runs the tests and the mutations' },
    { title: 'Fix', detail: 'one bounded fix round when the review finds a real issue' },
  ],
}

const SP = '/tmp/claude-0/-home-user/53272e27-a6c8-5c39-bbc4-fcca8e45650f/scratchpad'

const TRAILERS = `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01HNJoVmTAZ6eKPMi45fXN34`

const COMMON_BACKEND = `
You work inside the repository lavkoo (Laravel 12, PHP 8.3, PHP-Unit 11). CLAUDE.md is already in your context; read other docs only as needed: the task is narrow.

STANDING RULES (they override everything else): no merge, NO PUSH (the parent session reviews and pushes), no deployment, no migration on any real database, no change of a live financial or legal rule, no force of anything. The task says what application code you may change: change nothing else, and any change you make must do nothing while the storefront flag is off and nothing for the default Saudi storefront unless the task says so. Commit locally on your own branch, with a message that ends with these two lines after a blank line:
${TRAILERS}
Put no model identifier and no personal data in any file you add. Never change the condition that refuses a coupon whose min_purchase is greater than the eligible total (OrderManager::getTotalCouponAmount).

YOUR WORKTREE. Create it first, with the names your task gives: git -C /home/user/lavkoo fetch origin && git -C /home/user/lavkoo worktree add -b <branch> ${SP}/wt/<dir> <start point> && cd ${SP}/wt/<dir> && cp -al ../legal/vendor vendor && git config user.email integration@example.invalid && git config user.name "integration scratch". Confirm git log -1. (The vendor directory of ../legal has no package git history any more; never run composer.)

HOW TO RUN THINGS.
- SQLite (the default of phpunit.xml): cd <worktree> && APP_KEY="base64:$(openssl rand -base64 32)" php vendor/bin/phpunit --no-progress <paths>
- A real engine (MySQL 8.0.46 on 127.0.0.1:3308): bash ${SP}/mysql_wt.sh <the worktree's directory name under ${SP}/wt> <database name that matches the regex (^|_)test(s|ing)?($|_) and is UNIQUE to you> <absolute output file under ${SP}/> [STRICT] <phpunit paths>. It copies the worktree, creates the database, runs phpunit with phpunit.mysql.xml and drops both; read the output file afterwards (it ends with exit=N and FINISHED). Run your tests on SQLite first, then once on MySQL normal and once on MySQL with STRICT. Some checkout-driven tests skip under STRICT for two bare GROUP BY clauses of the application (ONLY_FULL_GROUP_BY): the neighbouring tests do the same, copy their skip helper; do not change the application for it.
- Check the disk before long runs (df -h /): if less than 2 GB is free, stop and say so in your notes.
- Write each test the way its neighbours do: read two existing tests of the same area first and the support classes under tests/Support. translate() writes missing keys into resources/lang when a test renders a view or an API message: use the RestoresLanguageFiles trait where a test renders anything translated, run git status on resources/lang after your runs and make sure nothing there changed.
- No time bomb: never a fixed calendar date; use moments relative to now() and keep any date before the year 2037.
- Project rules: named arguments wherever PHP allows, all imports at the top of the file, no unnecessary comments, reuse the existing helpers instead of writing new ones, small single-purpose methods.

MUTATION PROOF IS MANDATORY. For each behaviour your task names, make a temporary change to the application code that removes it, run your test, see it fail, restore the file with git checkout -- <file>, and record the change and the names of the failing tests. A test that stays green under its mutation is not a test: strengthen it. At the end, git status must show nothing but your commit.

BEFORE YOU RETURN: php scripts/ci/leak-guard.php --base=<the start point sha> --head=<your head> must exit 0 (run it from your worktree); no file under resources/lang changed (unless the task adds a language key, and then only that key in each language file the neighbouring keys are in); the commit exists; git status is clean.
BE HONEST: for each criterion say exactly which part of its sentence each test covers and which part nothing covers ("structural only" or "not covered"); never claim more than a test asserts. If you find a real defect that is not in your task, do NOT change the application to hide it: leave the failing test out of the commit and report the route, the case, the status and the body. Where a criterion's sentence is already proven by an existing test, say which one and do not duplicate it. Do not touch what is not yours: other agents work on other branches at the same time.
`

const COMMON_APP = `
You work in a Flutter app of this project. Its CLAUDE.md is already in your context: GCC work is a set of draft pull requests stacked on each other, every new behaviour is OFF by default behind the compile-time flag GCC_STOREFRONTS_ENABLED (find it with grep: StorefrontConfig), and with the flag off the app must behave exactly as it does on main.

STANDING RULES (they override everything else): no merge, NO PUSH (the parent session reviews and pushes), no deployment, never change the app version, a package id, a bundle id or any Firebase setting, never touch the version gate (lib/utill/update_gate.dart and the splash routing for the customer app; test/app_version_gate_test.dart and the version gate code for the seller app) other than to read it, never commit pubspec.lock (flutter pub get rewrites it: run git checkout -- pubspec.lock before you commit and before you return). The task says whether you may change lib/ and what: change nothing else. Commit locally on your own branch, with a message that ends with these two lines after a blank line:
${TRAILERS}
Put no model identifier and no personal data in any file you add.

HOW TO RUN THINGS. Every flutter or dart command needs: export PATH=${SP}/sdk/flutter/bin:$PATH PUB_CACHE=${SP}/sdk/pub-cache . Run flutter pub get --offline once in your worktree (then git checkout -- pubspec.lock). Run flutter test on the specific files you add and on the neighbouring test files of the code you read, never on the whole suite, always with a time limit: timeout 900 flutter test <files>. Only ONE flutter command at a time in your worktree; other agents run flutter in other worktrees at the same time, so a run can take several minutes: be patient, do not start a second copy. Log lines about null check operators printed by getTranslated in tests are expected noise. Before you return also run flutter analyze --no-fatal-infos on the files you touched (it must find nothing new). If the disk is nearly full (df -h /), stop and say so in your notes; do not delete anything outside your worktree.
Write each test the way its neighbours do (read two of them first). Project rules: named arguments where Dart allows, imports at the top, no needless comments, reuse existing helpers. Add one named step per new test file in .github/workflows/ci.yml, following the pattern of the steps already there (a name that says what the step proves, the same flutter command, if: always() where the neighbours have it); no other change of the workflow.

MUTATION PROOF IS MANDATORY. For each behaviour your task names, make a temporary change to the application code that removes the behaviour, run your test, see it fail, restore the file (git checkout -- <file>), and record the change and the names of the failing tests. A test that stays green under its mutation is not a test: strengthen it. At the end git status must show nothing but your commit.

BEFORE YOU RETURN: the commit exists; git status is clean; cd ${SP}/wt/handoff && php scripts/ci/leak-guard.php --base=<the start point sha> --head=<your head> --root=<your worktree> exits 0.
BE HONEST: for each criterion say exactly which part each test covers and which part nothing covers ("structural only", "not covered", "nothing was run on a device"); never claim more than a test asserts. Where a criterion is already proven by an existing test, say which one and do not duplicate it. Do not touch what is not yours: other agents work on other branches at the same time.
`

const COMMON_CUSTOMER = COMMON_APP + `
YOUR WORKTREE. Create it first, with the names your task gives: git -C /home/user/lavkooapp fetch origin && git -C /home/user/lavkooapp worktree add -b <branch> ${SP}/wt/<dir> <start point> && cd ${SP}/wt/<dir> && git config user.email integration@example.invalid && git config user.name "integration scratch". Confirm git log -1. The app is the customer app (repository lavkooapp, package name syriapharmacy).
`

const COMMON_SELLER = COMMON_APP + `
YOUR WORKTREE. Create it first, with the names your task gives: git -C /home/user/lavkooappseller fetch origin && git -C /home/user/lavkooappseller worktree add -b <branch> ${SP}/wt/<dir> <start point> && cd ${SP}/wt/<dir> && git config user.email integration@example.invalid && git config user.name "integration scratch". Confirm git log -1. The app is the seller app (repository lavkooappseller, package name syriacosmeticsmanger). Its test/app_version_gate_test.dart is red on purpose (an owner decision): never touch it, never try to make it pass, and do not read the red whole-suite result as a failure of yours: run only your files and their neighbours.
`

const TASKS = {
  'W5-F': {
    key: 'W5-F-theme-caches-identity-reach',
    dir: 'themecache',
    branch: 'gcc/p1-theme-cache-identity',
    start: 'origin/gcc/p1-theme-publish-per-storefront',
    kind: 'backend',
    prompt: `
TASK W5-F: themes per storefront. Branch gcc/p1-theme-cache-identity, worktree directory themecache, start point origin/gcc/p1-theme-publish-per-storefront (lavkoo draft pull request 112, head eaa04e2f: it holds the theme preview, delivery cache, app contract, section library, import and design-token tests this task builds on; read them first: tests/Feature/Storefront/StorefrontTheme*Test.php and tests/Support/BuildsThemePreviewWorld.php, ReadsThemeWorld.php). MySQL database names start with tc_. Application changes are allowed where each criterion below says so; each must be small, do nothing for the default Saudi storefront (its payloads and cache keys stay byte-identical, because other tests pin them) and nothing while the storefront flag is off, and be proven by a test that fails without it.

F-04.5: "Section reach, campaign overlay and experiment caches are storefront-aware or declared global (keys carry no market at PR 83)". Find every cache key the theme code writes or reads (Cache::remember, put, forever, forget; search app/Services/Theme*, app/Http/Controllers/RestAPI/v1/ThemeSection*, app/Utils, Modules/*; the section reach, the campaign overlay and the experiments / experiment assignment in particular). For each: if its content depends on the storefront (its products, banners, language, currency or audience), the key must carry the storefront country the way the other theme caches do (read ThemeDelivery and the key builders pinned by StorefrontThemeDeliveryCacheTest; the default storefront's key stays as it is); if it is global by nature, it goes in a hand-written table GLOBAL_THEME_CACHE_KEYS in the new scan test, with the reason. Add a scan test that fails on any theme cache key that is neither storefront-aware nor in that table (and on a table entry no code uses), self-tested on in-memory source strings the way tests/Support scanners of this branch are.
F-12.2: "A storefront without overrides inherits the shared identity and a change to it reaches that storefront". F-12.3: "A storefront override changes only that storefront". Read the design-token service and StorefrontThemeDesignTokensTest (16 tests). Prove both through the real services with two storefronts (SA default and AE): a change of the shared identity reaches AE when AE has no override, including the delivered payload cache of AE (the cache must be forgotten or keyed so that the new value is served: assert the payload read after the change); an override on AE changes AE only and SA keeps the shared value; removing the override returns AE to the shared value; an override on one field leaves the other fields inherited. Fix what fails with the smallest change and report each fix.
F-10.2: "Banner-backed cards pointing at another country's product are not delivered (dashboardBanners reads every published banner)". Find dashboardBanners in the section library data source. A banner whose target is a product that does not belong to the viewer's storefront must not be delivered to that storefront (a product's storefront is its country column; use the existing catalogue narrowing helper StorefrontCatalog rather than a new rule); a banner whose target is not a product (or none) is delivered everywhere as today; the default storefront and storefronts off behave exactly as today. Test with SA and AE products and the control.
Mutations to prove: the storefront dropped from a cache key; the override applied to every storefront; the shared change not reaching a storefront without override (cache not forgotten); the banner filter removed.`,
  },
  'W5-I': {
    key: 'W5-I-marketing-stop-and-registry',
    dir: 'mktstop',
    branch: 'gcc/p1-marketing-stop-registry',
    start: 'origin/gcc/p1-storefront-lifecycle',
    kind: 'backend',
    prompt: `
TASK W5-I: marketing sends for a storefront that may not sell, and the notification type registry. Branch gcc/p1-marketing-stop-registry, worktree directory mktstop, start point origin/gcc/p1-storefront-lifecycle (lavkoo draft pull request 111: the storefront lifecycle with the decision "may this storefront sell": read StorefrontStopModesTest, StorefrontSalesRoutesTest and the service they exercise; MySQL database names start with mk_). Application changes are allowed where each criterion below says so, small, doing nothing while the storefront flag is off and nothing for the default storefront while it sells.

I1-07.2: "Abandoned-cart reminders and other marketing sends skip a storefront that may not sell (AbandonedCartService::findAbandoned and the reminder command ignore sellability)". Find the abandoned-cart reminder (service and console command) and the other marketing senders (the admin push broadcast and campaigns, the newsletter, the WhatsApp cart-marketing list, coupon and campaign pushes, scheduled promotions). For each marketing send that can be tied to a storefront (a cart to the storefront of its products; anything that already carries a storefront), skip it while that storefront may not sell, using the existing sellability decision of PR 111. A send that cannot be tied to a storefront (a global broadcast) stays as it is and goes in the report. A skipped reminder must NOT write its ledger row (so it is sent when the storefront sells again). Order status, refund, payment and one-time-code messages are never touched (they continue, pinned elsewhere). Test with carts of SA (selling) and AE in each state that may not sell (browse only, paused, redirect, draft, ready without approved legal texts) and a selling control; and with the flag off.
I1-07.3: "A registry lists every notification type as necessary or marketing, and a test fails for an unclassified type (no classification exists in code)". Find every notification type the code sends: the push types (PushNotificationTrait and the notification helpers, the order status keys, chat, wallet, cash collect, withdraw, block, product request, campaign), the e-mail template types (EmailTemplate keys) and the WhatsApp templates. Create a small registry in the existing style of the app's services (one class with the classification and a lookup, no database), listing each as NECESSARY or MARKETING, and use it where the marketing skip above decides whether a send is subject to the stop (a NECESSARY type is never skipped, a MARKETING type is). A scan test fails for a type used in code and missing from the registry and for a registry entry no code uses. Say in the report what the scan cannot see (types built from variables).
Mutations to prove: the sellability check removed from the reminder; the ledger row written before the skip; a necessary type treated as marketing; a type removed from the registry; a new type added in code and not in the registry.`,
  },
  'W5-K': {
    key: 'W5-K-seller-flow-tests',
    dir: 'sellerflows',
    branch: 'gcc/p1-seller-flow-tests',
    start: 'origin/main',
    kind: 'seller',
    prompt: `
TASK W5-K: seller app flow tests, criterion K-16.4. Branch gcc/p1-seller-flow-tests, worktree directory sellerflows, start point origin/main of lavkooappseller. TESTS ONLY: no file under lib/ changes (a test file per flow and the named steps in .github/workflows/ci.yml; if the workflow on main has no named-step pattern yet, add the steps after the existing test step with if: always() so that each one is read on its own even while the known red gate test fails the whole suite).

K-16.4: "Orders, products, stock, withdrawals and reviews have app-side flow tests of the request and the handling of the answer (the 19 test files on main pin models and the auth ...)". For each of the five flows (orders: list, details, status change; products: list, add or update, delete or status; stock: the stock screens' update; withdrawals: the payout request and its list; reviews: list and reply), find the repository / controller / provider that makes the request and handles the answer (lib/features/..., read how the existing tests fake the network: test/ and its fakes; the Dio client or http client abstraction). Write tests that drive the real controller or repository against a fake transport and assert: the request that leaves (method, path, query or body fields, headers that matter), the handling of a success answer (the state or model the screen reads), of a business refusal (4xx with the server's message: shown, not swallowed) and of a transport failure (state, no crash, a retry possible). Say precisely, per flow, what is covered and what is not (a flow whose network call cannot be reached without a widget tree: say so and cover the controller). Do not duplicate what an existing test already proves.
Mutations to prove (restore each): a wrong path or method in a request; a body field dropped; the success handler not updating the state; the error message swallowed; an exception escaping on a transport failure.`,
  },
  'W5-J': {
    key: 'W5-J-customer-order-currency',
    dir: 'ordercur',
    branch: 'gcc/p1-customer-order-currency',
    start: 'origin/gcc/p1-customer-pins',
    kind: 'customer',
    prompt: `
TASK W5-J: the customer app prints each order in its own currency with its storefront shown, criterion J-13.1. Branch gcc/p1-customer-order-currency, worktree directory ordercur, start point origin/gcc/p1-customer-pins (lavkooapp draft pull request 19, the tip of the app stack; the app CI is green on it).

J-13.1: "Each order and its totals print in the order's own currency with its storefront shown (OrderModel parses no currency or storefront; PriceConverter uses the global currency)". Steps: (1) find out what the backend sends: the customer order list and details answers are built in the backend repository (/home/user/lavkoo, read only, never change it): read the order list and details routes (routes/rest_api/v1/api.php, the order controller, OrderManager) and the order snapshot service (app/Services/Storefront/OrderStorefrontSnapshot.php and StorefrontCurrency.php on origin/gcc/p1-storefront-lifecycle: use git -C /home/user/lavkoo show origin/gcc/p1-storefront-lifecycle:<path>) to see whether the answers carry the snapshot columns (storefront_country, the currency code, the rate) of the order. Write down exactly which fields arrive and under which keys; if they do NOT arrive, do not guess: implement nothing and report precisely what the backend would have to add (keys and values), and stop with only a commit of whatever test pins the absence. (2) If they arrive: OrderModel (and the details model) parses them (all null-safe: an order without them, an old order, parses as today); the order list item, the order details totals and the order-amount calculation print money through the storefront money formatter (lib/features/storefront: StorefrontMoney or the helper PriceConverter delegates to for a storefront with its own currency: read price_converter.dart and its storefront tests first) in the ORDER's currency and decimals (3 for KWD, BHD, OMR) and show the order's storefront (country name or code) on the list item and the details, only while GCC_STOREFRONTS_ENABLED is on and the order carries a storefront; with the flag off, or for an order without the fields, nothing changes. Never multiply an amount by an exchange rate in the app. Tests: model parsing (with, without, malformed fields), the formatter with the order's currency for the six currencies, and widget or controller-level tests of the list item and the details totals if the existing tests build them with fakes (read test/ for how the order screens are tested); the flag on and off.
Mutations to prove: the currency read from the global config instead of the order; the storefront label printed with the flag off; the decimals taken from the wrong currency; the fields not parsed.`,
  },
  'W5-K2': {
    key: 'W5-K2-seller-outbox-keeps-market',
    dir: 'sellermarket',
    branch: 'gcc/p1-seller-outbox-market',
    start: 'origin/gcc/p1-seller-outbox-owner',
    kind: 'seller',
    prompt: `
TASK W5-K2: a queued POS sale keeps the market it was taken in, and is never replayed under another seller (criteria K-03.3 second clause and K-06.2). Branch gcc/p1-seller-outbox-market, worktree directory sellermarket, start point origin/gcc/p1-seller-outbox-owner (lavkooappseller draft pull request 14: the offline outbox keyed by seller, stacked on pull request 8). The market selector lives in another draft pull request (13, branch origin/gcc/p1-seller-market-selector, stacked on pull request 2: SellerMarketChoice, SellerMarketController, StorefrontInterceptor with the request-own X-Storefront rule). Right after you create the worktree, bring the selector in with: git merge --no-ff origin/gcc/p1-seller-market-selector -m "merge: the market selector (pull request 13) into the outbox owner (pull request 14), so that the outbox can keep the market" (resolve a conflict by keeping both sides; the integration branch origin/claude/seller-all-integration-ci holds both pull requests merged and one fix you may copy: test/market_selector/support/fake_screens.dart, the fake outbox enqueue must accept and pass through the optional idempotencyKey; read that branch to see the same merge done, but never base your branch on it). That merge commit is yours and is part of this branch.

The defects, found by an independent audit of the integration branch (read test/offline_outbox_owner_test.dart, test/market_selector/*, lib/features/pos/... PendingPosOrder, the outbox and its sync, StorefrontInterceptor, SellerMarketChoice):
(1) K-03.3: "A switch drops market-scoped state (POS cart, lists, notifications); a queued offline sale keeps the market it was taken in (cart_list and offline_pos_orders are device-global ...)". The second clause has no code and the code does the opposite: PendingPosOrder.toJson keys are id, body, created_at, order_amount, customer_name, attempts, last_error, idempotency_key, seller_id: no market; StorefrontInterceptor stamps X-Storefront from the stored choice WHEN THE REQUEST IS SENT. The audit's probe: a sale queued while the market is SA, the seller picks KW, syncPending sends X-Storefront KW, so the sale is booked in the wrong market. Fix: the market (the storefront code SellerMarketChoice holds at the moment the sale is queued; null when storefronts are off or none is stored) is stored in the entry (toJson / fromJson; an entry written before has none and is replayed as today) and sent as the request's OWN X-Storefront at replay (the interceptor already keeps a request that names a storefront of its own: market_header_follows_pick_test 'a request that names a storefront of its own keeps it'). With the flag off nothing changes: no header, no field written beyond what exists today if the neighbouring tests pin the written keys (check them).
(2) K-06.2: "A queued sale is replayed only under the seller and market that took it, and is never deleted silently at sign-out". The seller half holds for a sale that carries a seller. It fails for a sale with NO seller: one an earlier version queued, and one rung up in a session whose profile never answered (the seller id is not known yet). The audit's probe with the real sign-out and the real profile: A rings up a sale in such a session and signs out, B signs in, B sees the sale and sends it under B. Fix, as small as it can be: a sale that has no seller is never replayed under another seller: either it takes the seller of the session when the profile answers during the same session (and then it is A's), or it is kept apart and never sent by a session other than the one that queued it (decide from the code: do not invent an identity that the app does not have; a session id kept in memory is enough to tell that a different session signed in), and it is never deleted silently. The existing test 'a sale rung up before the profile has answered is given the seller of its own session only' asserts only that B's answer does not claim it: add the missing assertion that B does not send it.
Tests (test files under test/, run in the default build and with --dart-define=GCC_STOREFRONTS_ENABLED=true where the flag matters): a sale queued under SA and replayed after a switch to KW carries SA on the wire (a real DioClient with a recording adapter, as market_header_follows_pick_test does); an old entry with no market replays with the current market as today; a sale with a seller and a market replays only under that seller and that market; the sale with no seller case above (A signs out, B signs in, nothing is sent under B, nothing deleted, A's session or A signing in again sends it once). Each of the three behaviours has a mutation that kills a named test. The named CI steps: the two steps of the market selector and of the POS rules in .github/workflows/ci.yml on the merged branch already run the folders you extend: add your new files to them or add a step.
Mutations to prove: the market not stored in the entry; the stored market ignored at replay (the interceptor's current market used); a sale with no seller replayed under the next seller; an entry with a market and no seller treated as another seller's.`,
  },
}

const IMPL = {
  type: 'object',
  properties: {
    task: { type: 'string' },
    worktree: { type: 'string' },
    commit: { type: 'string', description: 'the sha of your commit, or empty when you committed nothing' },
    start_point_sha: { type: 'string' },
    files: { type: 'array', items: { type: 'string' } },
    application_files_changed: { type: 'array', items: { type: 'string' } },
    tests: { type: 'array', items: { type: 'object', properties: { class: { type: 'string' }, count: { type: 'number' }, result: { type: 'string' }, mysql: { type: 'string' }, mysql_strict: { type: 'string' } }, required: ['class', 'count', 'result'] } },
    criteria: { type: 'array', items: { type: 'object', properties: { id: { type: 'string' }, covered_by: { type: 'array', items: { type: 'string' } }, fully_covered: { type: 'boolean' }, gap: { type: 'string' } }, required: ['id', 'covered_by', 'fully_covered', 'gap'] } },
    mutations: { type: 'array', items: { type: 'object', properties: { change: { type: 'string' }, failing_tests: { type: 'array', items: { type: 'string' } } }, required: ['change', 'failing_tests'] } },
    findings: { type: 'array', items: { type: 'string' } },
    leak_guard_exit: { type: 'number' },
    resources_lang_changed: { type: 'boolean' },
    notes: { type: 'string' },
  },
  required: ['task', 'worktree', 'commit', 'start_point_sha', 'files', 'application_files_changed', 'tests', 'criteria', 'mutations', 'findings', 'leak_guard_exit', 'resources_lang_changed', 'notes'],
}

const REVIEW = {
  type: 'object',
  properties: {
    verdict: { type: 'string', enum: ['APPROVE', 'FIXES_NEEDED'] },
    issues: { type: 'array', items: { type: 'object', properties: { severity: { type: 'string', enum: ['high', 'medium', 'low'] }, where: { type: 'string' }, problem: { type: 'string' }, fix: { type: 'string' } }, required: ['severity', 'where', 'problem', 'fix'] } },
    reran_tests: { type: 'string' },
    reran_mutations: { type: 'array', items: { type: 'object', properties: { change: { type: 'string' }, killed: { type: 'boolean' } }, required: ['change', 'killed'] } },
    claims_that_overreach: { type: 'array', items: { type: 'string' } },
    summary: { type: 'string' },
  },
  required: ['verdict', 'issues', 'reran_tests', 'reran_mutations', 'claims_that_overreach', 'summary'],
}

const commonFor = (task) => (task.kind === 'backend' ? COMMON_BACKEND : (task.kind === 'seller' ? COMMON_SELLER : COMMON_CUSTOMER))

const wanted = (args && args.tasks) ? args.tasks : Object.keys(TASKS)
const selected = wanted.map((name) => TASKS[name])

const results = await pipeline(
  selected,
  (task) => agent(commonFor(task) + '\n' + task.prompt, { label: 'implement:' + task.key, phase: 'Implement', schema: IMPL }),
  (impl, task) => {
    if (!impl || !impl.commit) {
      return Promise.resolve({ impl, review: null })
    }
    const how = task.kind === 'backend'
      ? 'lavkoo (PHP 8.3, PHP-Unit 11); run the new tests on SQLite with: cd the worktree; APP_KEY="base64:$(openssl rand -base64 32)" php vendor/bin/phpunit --no-progress <the new test files>; a real engine with bash ' + SP + '/mysql_wt.sh as the implementer was told'
      : 'a Flutter app (' + (task.kind === 'seller' ? 'seller app lavkooappseller' : 'customer app lavkooapp') + '). Every flutter or dart command needs: export PATH=' + SP + '/sdk/flutter/bin:$PATH PUB_CACHE=' + SP + '/sdk/pub-cache ; run flutter pub get --offline once in the worktree and git checkout -- pubspec.lock; run only the new test files and the neighbouring ones, with timeout 900, one flutter command at a time (other agents run flutter in other worktrees); the leak guard is run as: cd ' + SP + '/wt/handoff && php scripts/ci/leak-guard.php --base=<start> --head=<head> --root=' + SP + '/wt/' + task.dir
    return agent(`
You are an independent, skeptical reviewer. You did not write this change. Review the commits from ${task.start} to ${impl.commit} on branch ${task.branch} in the git worktree ${SP}/wt/${task.dir}, in ${how}. Do NOT push, do NOT merge, do NOT commit; if you make a temporary change to test a mutation, restore it exactly with git checkout -- <file> (or delete the scratch file), and leave git status clean.

The implementer's own report (treat it as claims to check, not as facts):
${JSON.stringify(impl, null, 1)}

The task they were given:
${task.prompt}

Check, by running things yourself: (1) git diff ${task.start}..${impl.commit} --stat: only the files the task allows changed, and every application change is small, does nothing while the storefront flag is off and nothing for the default storefront (check the default storefront's payloads and cache keys against the tests of the start point: run the neighbouring test classes of the changed code, not only the new ones); (2) run the new tests THREE times in a row: flaky or order dependent? (3) re-run at least THREE of the claimed mutations yourself and at least TWO mutations they did not claim that remove behaviour a criterion needs; a test that stays green is vacuous; (4) read every assertion: does it assert the behaviour the criterion's sentence says, or only that something happened? is an expected value computed by the code under test (circular)? (5) does the report claim more than the tests prove (list each overreach)? (6) for an application change: read the diff line by line for a behaviour change nobody asked for, an N+1, a cache key collision between storefronts, a null that is not handled, a Saudi default that leaks.
Severity: high = a test that cannot fail, a behaviour change outside the task, a defect hidden, a false claim, a regression of the default storefront; medium = a weak assertion, a flaky order, a missing case of the criterion; low = style. Be specific: where, what, and the exact fix. Verdict APPROVE only when there is no high and no medium issue.`, { label: 'review:' + task.key, phase: 'Review', schema: REVIEW }).then((review) => ({ impl, review }))
  },
  ({ impl, review }, task) => {
    if (!impl || !impl.commit || !review || review.verdict === 'APPROVE' || !review.issues.some((i) => i.severity !== 'low')) {
      return Promise.resolve({ impl, review, fix: null })
    }
    return agent(commonFor(task) + `
You are the implementer of this change again; your worktree ${SP}/wt/${task.dir} and branch ${task.branch} exist already: do not create them again. The independent review of your commit ${impl.commit} found issues. Fix every high and medium issue (the low ones if cheap) in a NEW commit on top (do not rewrite history, no amend), re-run the new tests (and, for the backend, once on MySQL STRICT), re-prove the mutations the review named, and return the same report as before for the new commit (commit = the sha of the new commit; files and tests cover the whole branch since ${task.start}).
The task you were given:
${task.prompt}
The review:
${JSON.stringify(review, null, 1)}`, { label: 'fix:' + task.key, phase: 'Fix', schema: IMPL }).then((fix) => ({ impl, review, fix }))
  },
)

return results.map((r, index) => (r ? { task: selected[index].key, impl: r.impl, review: r.review, fix: r.fix } : { task: selected[index].key, failed: true }))
