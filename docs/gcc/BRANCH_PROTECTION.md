# Protecting `main`: the exact rule, for a person to apply

Register item DR-4 and requirement M1-16 of the master tracker. **Nothing here is applied.** Branch protection, rulesets and repository settings are not something the autonomous sessions may change (charter section 4); this file is the exact rule so that the owner, or an administrator the owner names, can apply it in a few minutes and so that the result can be checked. The reading side was not possible from the session's network (the proxy refuses the settings endpoints), so **today's state is unverified**: the last record says `main` has no protection in the three repositories.

## Before the rule is switched on

1. **`main` must be green first.** A required check that is already red blocks every pull request. At the time of writing `main` is red on one test, `Tests\Feature\SalesChannels\TorodShipmentDiscoveryTest`, which stamps its order at a fixed instant that is now more than 36 hours old; every branch cut from `main` shows the same single failure. The one-line repair (`now()->subHours(10)`, the commit `test(sales-channels): carry the Torod fixture repair from the saudi-baseline branch`) is carried by most open pull requests; land it as the first change.
2. **Decide which checks are required** (the list below is a recommendation, and the cost matters: the sales-channel jobs are the long ones, 52 to 108 minutes on the slowest engine, against a 110-minute ceiling). A job that is killed by its own ceiling reports as cancelled, which a required check treats as a failure.

## The rule for `main` (repository ruleset, target branch `main`)

| Setting | Value |
|---|---|
| Require a pull request before merging | on, **1** approving review |
| Dismiss stale approvals when new commits are pushed | on |
| Require review from Code Owners | on (needs `.github/CODEOWNERS`, in this pull request) |
| Require approval of the most recent push | on (the pusher cannot approve their own last push) |
| Require conversation resolution before merging | on |
| Require status checks to pass | on, with the checks below |
| Require branches to be up to date before merging | on |
| Block force pushes | on |
| Restrict deletions | on |
| Require linear history | off (the repository merges with merge commits; the integration branches rely on it) |
| Bypass | nobody; if an administrator must be able to bypass, that is a conscious choice written down here |

## Required status checks (the names GitHub shows)

Recommended minimum, from the jobs of `.github/workflows/ci.yml` on `main`:

- `PHP syntax (8.2)`, `PHP syntax (8.3)`
- `Tests, SQLite (PHP 8.2)`, `Tests, SQLite (PHP 8.3)`
- `Fulfillment + security tests, MySQL 8`, `Fulfillment + security tests, MariaDB 11.4.2`
- `Address tests, MySQL 8`, `Address tests, MariaDB 11.4.2`
- `Strict-mode tests, MariaDB 11.4.2`
- `No secret or personal data added`

Add when the owner accepts the cost: the ten `Sales-channel and cost tests, <engine> (<group>)` jobs (engines `MySQL 8.0` and `MariaDB 11.4.2`; groups `sales channels A-M`, `sales channels N-Z`, `concurrency`, `myfatoorah`, `costs`). They run the longest and prove the most about real engines.

**Never required: `Deployment overlay (artifact, deploys nothing)`.** It compares a head with the server baseline and is red by design for every branch that is not the baseline hotfix line (it lists the files an overlay never deploys, such as `robots.txt` and `firebase-messaging-sw.js`, which already differ between the baseline and `main`). It decides how a release is packaged, not whether a change is correct.

A check name that no workflow produces any more blocks merging forever: when a job is renamed or a matrix changes, change this list in the same pull request.

## Applying it

In the repository: Settings, Rules, Rulesets, New ruleset, New branch ruleset, target `main`, then the table above. The same as a ruleset body (the `integration_id` of the checks is left out so that any app that reports the name counts):

```json
{
  "name": "protect main",
  "target": "branch",
  "enforcement": "active",
  "conditions": { "ref_name": { "include": ["refs/heads/main"], "exclude": [] } },
  "rules": [
    { "type": "deletion" },
    { "type": "non_fast_forward" },
    { "type": "pull_request", "parameters": {
        "required_approving_review_count": 1,
        "dismiss_stale_reviews_on_push": true,
        "require_code_owner_review": true,
        "require_last_push_approval": true,
        "required_review_thread_resolution": true } },
    { "type": "required_status_checks", "parameters": {
        "strict_required_status_checks_policy": true,
        "required_status_checks": [
          { "context": "PHP syntax (8.2)" },
          { "context": "PHP syntax (8.3)" },
          { "context": "Tests, SQLite (PHP 8.2)" },
          { "context": "Tests, SQLite (PHP 8.3)" },
          { "context": "Fulfillment + security tests, MySQL 8" },
          { "context": "Fulfillment + security tests, MariaDB 11.4.2" },
          { "context": "Address tests, MySQL 8" },
          { "context": "Address tests, MariaDB 11.4.2" },
          { "context": "Strict-mode tests, MariaDB 11.4.2" },
          { "context": "No secret or personal data added" } ] } }
  ],
  "bypass_actors": []
}
```

Repeat for the customer app (`lavkooapp`) and the seller app (`lavkooappseller`): their `main` has no workflow today, so the rule there is pull request, one review and no force push, with no required checks until a workflow exists (M1-16, step 5 of "what remains").

## How to check it afterwards

1. `GET /repos/fullstackfull/lavkoo/rules/branches/main` lists the active rules; the four rule types above must be there.
2. Open a draft pull request from a branch with a failing test: it cannot be merged.
3. Push directly to `main` from a clone: it is refused. Do not try this with a branch that has anything to lose.
4. The next time a job is renamed, the list above and the ruleset change together.

## What stays open

- DR-3 and DR-5 (a private repository costs runner minutes; a path filter for documentation-only changes and a three-way split of `sales channels A-M` are drafts still to write, M1-16 step 3).
- Whether an administrator may bypass the rule.
- Auto-merge and merge queues, which are not turned on and not recommended before the rule has held for some time.
