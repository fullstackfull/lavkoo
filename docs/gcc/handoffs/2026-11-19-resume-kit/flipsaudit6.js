export const meta = {
  name: 'flips-audit-6',
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
    key: 'U14-legacy-pins-and-notification-stop',
    desc: 'The legacy behaviours of main and the notifications of a stopped storefront. (A) lavkoo draft pull request 122 (branch gcc/p1-legacy-behaviour-pins, head ce8786b777a327336324a7f8e41b3eef15721f4e, based on main; 7 test files and a third group in the real-engine job of .github/workflows/ci.yml, no application file): PushBodyLanguageTest (14) and PushTopicNamesTest (12) in tests/Feature/Notifications, AbandonedCartReminderLedgerTest (5) in tests/Feature/Retention, OneTimeCodesByAppModeTest (27) in tests/Feature/Security, ApiVendorSplitOrderTest (4) in tests/Feature/Address, WebsiteDirectionTest (6) in tests/Feature/Localization, support FakesThePushService. CI run 367 (pull_request, id 44448514495, 23 jobs, 17 success): the new group Notification and localization tests on MySQL 8 and MariaDB 11.4.2 (job_367_notif_mysql.txt and job_367_notif_mariadb.txt: 37 tests, 174 assertions each, success), the Address tests and the Fulfillment + security tests on both engines (success; their logs are not saved), the whole suite on SQLite (job_367_sqlite83.txt and job_367_sqlite82.txt: 6268 tests, 97 skipped, 1 failure which is TorodShipmentDiscoveryTest, red on main), and four more jobs red for the same Torod test (job_367_nz_mysql.txt, job_367_nz_mariadb.txt, job_367_strict_mariadb.txt); the deployment overlay is red by design. The implementers admit: I1-09.1 the real behaviour is AT MOST ONCE and a failed send looks like a sent one (the ledger row is written before the mail); the unique key of the live table is in no file, so the two key tests can fail only by editing the test\'s own table; I1-03.1 covers the body of the push for customer, seller, delivery man and promoter, not the title (it is in the language of the request, not the recipient\'s), not chat, wallet, cash-collect, withdraw, block and product-request pushes; I1-05.3 is the backend half only and the scans miss a message with no literal topic key; M1-05.5 two deciders are dead code and structural only, ten places are driven through routes; M2-06.2 pins the one currency an order row has on main (bring_change_amount_currency), has no storefront, and skips on a strict SQL server; I1-16.5 website half only: the rendered page is the maintenance page, the other layouts are scan-only, and maintenance-mode.blade.php prints dir="" when the session has no direction. For I1-16.5 the app half is lavkooapp draft pull request 19 (head 0ed2c8443f6b38056691cf14b70a77f4b1e9a450, test/localization_direction_test.dart, 10 tests): app CI run 33 (push, id 44443281527), log ${SP}/app_job_33_storefront_header.txt, step I1-16.5 passed 10 tests in one build; judge I1-16.5 on both halves together and say what each proves. I1-10.4 says a characterization test pins EACH kept notification behaviour on SQLite, MySQL 8 and MariaDB 11.4.2: say which behaviours the six classes pin and which senders have none. The tracker cards: I1-03, I1-05, I1-09, I1-10 and I1-16 in axis-i-marketing-policies-and-operations.md, M1-05 and M2-06 in axis-m-security-tests-and-deployment.md. Criteria to decide: I1-03.1, I1-05.3, I1-09.1, I1-10.4, I1-16.5, M1-05.5, M2-06.2.',
    criteria: ['I1-03.1', 'I1-05.3', 'I1-09.1', 'I1-10.4', 'I1-16.5', 'M1-05.5', 'M2-06.2'],
  },
  {
    key: 'U15-notification-stop',
    desc: 'The notifications of an earlier order while a storefront may not sell. lavkoo draft pull request 123 (branch gcc/p1-notification-stop-pins, head 53b1d22ff80ff909a4086c79397612d612c9e008, stacked on pull request 111 7e19d284, TESTS ONLY, two files): StorefrontStopNotificationsTest (180 tests, 3768 assertions, tests/Feature/Storefront) and the support trait FakesPushNotifications. CI run 368 (pull_request, id 44451474584, 22 of 23 jobs success, overlay red by design): job_368_mysql.txt and job_368_mariadb.txt (Storefront tests: 1121 tests, 8428 assertions, none skipped), job_368_sqlite83.txt (whole suite 7410 tests, 99 skipped, none of them in this class). What it pins: in nine states of a storefront (two controls that sell; browse only, maintenance and redirect on Kuwait; browse only on the default storefront; draft; ready; active without accepted legal texts) the customer\'s device gets exactly one push for each status step of an order placed before the state (administrator, vendor panel, seller API v2 and v3, delivery-man API), for a refund decision (administrator, vendor panel, seller API v2 and v3), for the amount of an edited order returned, and the vendor gets one for a refund request; each case also checks that the new sale a shopper had waiting is refused or taken. What it found: the application sends NO e-mail for the status of an order, for a refund or for a due payment (the only order mails are order placed and new order), so the test pins that the mail transport stays empty, which is not proof that a mail is sent; the due-payment push is NEVER sent (its key order_edit_due_payment_message has no entry in PushNotificationTrait::pushNotificationMessage), in a storefront that sells either, and the test pins that as a characterisation; a refund request is announced with the status-change key (its own branch is unreachable). Not driven: the MyFatoorah refund, the bulk status route, the delivery-man assignment, the notification of an order edit, the pushes raised for a delivery man and a vendor by a status, a queued listener run by a worker; on MySQL strict every test skips (not run in CI here). The tracker card: I1-07 in axis-i-marketing-policies-and-operations.md. Criterion to decide: I1-07.1 ("Order status, refund and due-payment push and e-mail are sent for an order of a storefront that may not sell"): decide whether this sentence is proven, partly proven (CODED) or cannot be MET as written (say why).',
    criteria: ['I1-07.1'],
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
