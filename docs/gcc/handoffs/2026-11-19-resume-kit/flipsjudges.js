export const meta = {
  name: 'flips-judges-2',
  description: 'Independent audit of the criteria flipped to MET from the runs 355, 356, 358, 359 and the customer app run 20: each unit is read by a skeptical verifier against the criterion sentence, the tests and the CI logs, and every flag is judged by a second agent',
  phases: [
    { title: 'Verify', detail: 'one skeptical verifier per unit' },
    { title: 'Judge', detail: 'a second agent decides every criterion the verifier would downgrade or correct' },
  ],
}

const SP = '/tmp/claude-0/-home-user/53272e27-a6c8-5c39-bbc4-fcca8e45650f/scratchpad'

const COMMON = `
You audit claims in a project tracker. You change NOTHING in any repository, you push nothing, you commit nothing. You may read any file and run read-only commands, and you may run tests only in a throwaway detached worktree of your own (git -C <main repo> worktree add --detach ${SP}/wt/<your own unique name> <sha>, then cp -al ${SP}/wt/legal/vendor vendor, then APP_KEY="base64:$(openssl rand -base64 32)" php vendor/bin/phpunit --no-progress <file>); remove your worktree with git -C <main repo> worktree remove --force when you are done. Never run tests in the worktrees of the other agents.

THE QUESTION. The tracker (the generated files ${SP}/wt/handoff/docs/gcc/tracker/axis-*.md, the uncommitted working copy; find a criterion by its id in the card of its requirement, criteria are numbered from 0 in the order of the card) says each criterion below is MET (or CODED) and gives an evidence sentence. Your job is to decide whether the criterion's OWN SENTENCE, every part of it, is proven by the tests the evidence names, run by the CI jobs the evidence names, on the head the evidence names. Be adversarial: the previous round of this project found that evidence sentences overclaimed (a test that never signs in cited for a sign-in criterion; a test that passes arguments in cited for a sentence about the row's own amounts; a structural scan cited for a behaviour). Check, for each criterion:
(1) The sentence has parts (a list of things, a negative case, a condition). For every part name the test (class and method) that asserts it, by reading the test. A part no test asserts is a gap. A part asserted only by a source scan or a route-table scan is structural: say so.
(2) The test can fail: read the assertion; is the expected value computed by the code under test (circular)? is it an assertion that passes whatever the code does? Mutation notes are in the commit messages of the branch (git log --format=%B); re-run at least ONE mutation per unit yourself in your throwaway worktree (remove the behaviour, see the named test fail, discard the worktree).
(3) The CI: the job logs of the cited run are saved as text in ${SP}/job_<run>_<job>.txt (for example job_359_mysql.txt is the Storefront tests job on MySQL 8 of run 359; also _mariadb, _sqlite83, _sqlite82 where they exist). Read the PHPUnit summary at the end (grep -n "Tests:" ), check the test count and the skipped count, and that the directory of the test is run by that job (the Storefront jobs run tests/Unit/Storefront and tests/Feature/Storefront; the SQLite jobs run the whole suite; the strict-mode job runs only a short list of classes and is not proof for a Storefront test; a test that skips itself on an engine did not run there: grep the test for markTestSkipped and skip helpers). Say which engines each test ran on.
(4) The evidence text: are the names, counts, heads and numbers in it true? Does it say anything the tests do not assert? Quote each false or unsupported phrase.
(5) Is a criterion that was kept CODED too modest (a test that proves its whole sentence exists)? Say so only with the test named.

DECISIONS. For each criterion: KEEP (the state and the text are right), CORRECT_TEXT (the state is right but the text overclaims or is wrong: give the corrected full evidence sentence), DOWNGRADE_TO_CODED or DOWNGRADE_TO_MISSING (the sentence is not proven: say exactly which part has no test, and give the corrected full evidence sentence for the lower state), or UPGRADE_TO_MET (a CODED criterion whose sentence is fully proven: give the evidence). Be specific and concise; do not pad.
`

const UNITS = [
  {
    key: 'U1-theme-publish-audit',
    desc: 'F-02.3, M2-10.2, M2-10.3: publish, restore and schedule audit lines name the storefront; a storefront publishes its own draft without touching another; backend tests of publish, revision, checksum, fallback and old app versions. Head eaa04e2f of lavkoo #112, worktree ${SP}/wt/theme (branch gcc/p1-theme-publish-per-storefront, main repo /home/user/lavkoo), run 359 (job_359_*.txt).',
    criteria: ['F-02.3', 'M2-10.2', 'M2-10.3'],
  },
  {
    key: 'U2-theme-caches',
    desc: 'F-04.2, F-04.3, F-04.4, F-09.1: the keys the theme caches are written under, the language and storefront isolation of a cached page, and the delivery cache key (platform, device, audience, segments, language, engine, schema, components, channel). Head eaa04e2f of lavkoo #112, worktree ${SP}/wt/theme, run 359.',
    criteria: ['F-04.2', 'F-04.3', 'F-04.4', 'F-09.1'],
  },
  {
    key: 'U3-theme-preview',
    desc: 'F-07.0, F-07.1, F-07.2, F-07.3, F-07.4: the preview of a draft (token, session, web and app routes, headers, caches, other storefronts). Head eaa04e2f of lavkoo #112, worktree ${SP}/wt/theme, run 359. Test classes StorefrontThemePreviewTokenTest, StorefrontThemePreviewWebTest, StorefrontThemePreviewStorefrontTest and tests/Support/BuildsThemePreviewWorld.php.',
    criteria: ['F-07.0', 'F-07.1', 'F-07.2', 'F-07.3', 'F-07.4'],
  },
  {
    key: 'U4-theme-schedule-restore',
    desc: 'F-08.0, F-08.1, F-08.2, F-08.3: a due draft published by theme:publish-due, a blocked or failing scheduled publish cancelled or recorded, a restore that creates a draft and never publishes, a failed publish that leaves the versions as they were. Head eaa04e2f of lavkoo #112, worktree ${SP}/wt/theme, run 359. Classes StorefrontThemeScheduledPublishTest, StorefrontThemeVersionLifecycleTest.',
    criteria: ['F-08.0', 'F-08.1', 'F-08.2', 'F-08.3'],
  },
  {
    key: 'U5-theme-contract-library',
    desc: 'F-09.0, F-09.2, F-09.5, F-10.1, F-11.0, F-11.1, F-12.0 (and check that F-10.0 and F-12.1 are rightly kept CODED): the app contract and compatibility, the section library, the import and the design tokens. Head eaa04e2f of lavkoo #112, worktree ${SP}/wt/theme, run 359. Classes StorefrontThemeAppContractTest, StorefrontThemeAppCompatibilityTest, StorefrontThemeSectionLibraryTest, StorefrontThemeImportTest, StorefrontThemeDesignTokensTest, StorefrontThemeProductRailTest.',
    criteria: ['F-09.0', 'F-09.2', 'F-09.5', 'F-10.0', 'F-10.1', 'F-11.0', 'F-11.1', 'F-12.0', 'F-12.1'],
  },
  {
    key: 'U6-admin-screens',
    desc: 'M2-13.2, I2-11.4, I2-03.4: an administrator creates a draft from a template, approves, publishes and revokes a country legal text through the admin screens behind permissions, each step audited; every change of the lifecycle permission-gated and audited. Head d5755860 of lavkoo #115 (worktree ${SP}/wt/adminscreens, branch gcc/p1-storefront-admin-screens, main repo /home/user/lavkoo), run 358 (job_358_*.txt). Note the six AdminStorefrontMenuTest tests skip off SQLite by design.',
    criteria: ['M2-13.2', 'I2-11.4', 'I2-03.4'],
  },
  {
    key: 'U7-run355-356',
    desc: 'I2-16.2, I2-16.3 (MET), I2-16.4 and M2-14.4 (kept CODED, check the text and whether they are too modest or too generous), M2-04.0, I1-12.3 (MET). I2-16.x and M2-14.4: head 7e19d284 of lavkoo #111 (worktree ${SP}/wt/lifecycle, branch gcc/p1-storefront-lifecycle), run 355 (job_355_*.txt); class StorefrontStopContinuityTest. M2-04.0 and I1-12.3: head e624e43e of lavkoo #96 (worktree ${SP}/wt/isolation, branch gcc/p1-storefront-six-country-isolation), run 356 (job_356_*.txt); classes StorefrontCustomerSignInTest, StorefrontCouponRedemptionTest, CustomerAcrossStorefrontsTest, StorefrontCouponAdminTest. The checkout-driven cases skip under a strict MySQL server; the strict-mode job does not run the Storefront directory.',
    criteria: ['I2-16.2', 'I2-16.3', 'I2-16.4', 'M2-14.4', 'M2-04.0', 'I1-12.3'],
  },
]

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
          decision: { type: 'string', enum: ['KEEP', 'CORRECT_TEXT', 'DOWNGRADE_TO_CODED', 'DOWNGRADE_TO_MISSING', 'UPGRADE_TO_MET'] },
          parts_of_the_sentence: { type: 'array', items: { type: 'object', properties: { part: { type: 'string' }, proven_by: { type: 'string' }, kind: { type: 'string', enum: ['behaviour', 'structural', 'none'] } }, required: ['part', 'proven_by', 'kind'] } },
          engines: { type: 'string' },
          reason: { type: 'string' },
          false_phrases: { type: 'array', items: { type: 'string' } },
          replacement: { type: 'string', description: 'the full corrected evidence sentence when the decision is not KEEP, else empty' },
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
    final_decision: { type: 'string', enum: ['KEEP', 'CORRECT_TEXT', 'DOWNGRADE_TO_CODED', 'DOWNGRADE_TO_MISSING', 'UPGRADE_TO_MET'] },
    agrees_with_verifier: { type: 'boolean' },
    reason: { type: 'string' },
    replacement: { type: 'string', description: 'the full corrected evidence sentence when the final decision is not KEEP, else empty' },
  },
  required: ['id', 'final_decision', 'agrees_with_verifier', 'reason', 'replacement'],
}

const ids = args.ids
const results = await parallel(ids.map((id) => () => agent(COMMON + `
You are the JUDGE. A first verifier audited the criterion ${id} and flagged it. The verifier's decision, the verifier's notes and the description of the audited unit are in the file ${SP}/judge_items.json (a JSON array: read it with the Read tool and take the entry whose d.id is ${id}; its fields are unit, desc, d (the verifier's decision: decision, parts_of_the_sentence, reason, false_phrases, replacement) and notes). Decide independently, from the code, the tests and the CI logs named in desc, whether the criterion's own sentence is proven, and give the final decision. You may overrule the verifier in either direction: a flag that is wrong (the sentence IS proven by a test you can name) must be reversed to KEEP, and a gap the verifier missed must be added. Do not accept their reading: re-read the sentence, the tests and the logs.
When the final decision is not KEEP give the full corrected evidence sentence in 'replacement': plain, exact, naming the test classes, the counts, the run, the head and what is not covered; no other claim.`, { label: 'judge:' + id, phase: 'Judge', schema: JUDGED })))

return results
