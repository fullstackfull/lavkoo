# Handoff — wave 7 after its reviews, the corrections of this evening, and what needs the owner

**Session** `claude/lucid-babbage-jiiim2`, 2026-11-19, night. Supersedes nothing; read after
[the 15:00 handoff](2026-11-19-1500-waves-5-6-7-claude-lucid-babbage.md). Nothing is merged, deployed or rotated,
and no approval of any kind is recorded.

## 1. Where things stand

Tracker: **1,753 criteria — 808 MET, 339 CODED, 606 MISSING.** The total rose from 1,675 because the 28
requirements the verifiers added had one placeholder criterion each and now have 106 real ones.

| Pull request | Reviewed head | What it is | Criterion it serves | State now |
|---|---|---|---|---|
| lavkoo 130 | `3152b0de` | the sharing key nothing reads | E-02.3 | **MET**, CI read |
| lavkoo 131 | `cf0228b8` | account deletion that keeps the money, switch off | E-08.1 | not moved; CI of this head not read |
| lavkoo 132 | `9510d42d` | six gateway registries pinned to each other | D2-04.2 | not moved; CI of this head not read |
| lavkoo 133 | `18bd311f` | legal texts kept apart from operating rules | I2-06.0 | not moved; CI of this head not read |
| lavkoo 134 | `14c85671` | admin sign-in throttle and status check pinned | G-V2.2, G-V2.3 | not moved; **not independently reviewed yet** |
| lavkooappseller 17 | `5b2fd40` | seller image rules stated once, in one table | K-18.1 | **CODED and stays CODED** (see section 3) |

## 2. Three traps this session fell into. Read these before running or recording anything.

**Trap 1 — `phpunit.xml` forces SQLite whatever the shell says.** It pins
`<server name="DB_CONNECTION" value="sqlite"/>` with `:memory:`, and a PHPUnit `<server>` entry overrides the
environment. `DB_CONNECTION=mysql php vendor/bin/phpunit …` therefore runs on SQLite and prints nothing about it.
The only way to a real engine is the second config:

```bash
mariadb -h127.0.0.1 -P3308 -uroot -e "DROP DATABASE IF EXISTS mywork_test; CREATE DATABASE mywork_test"
```

```bash
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3308 DB_USERNAME=root DB_DATABASE=mywork_test EXPECT_DB_DRIVER=mysql php vendor/bin/phpunit -c phpunit.mysql.xml --no-progress tests/Feature/Wallet
```

The real engine on the session machine is on port **3308**; a second MariaDB on 3306 authenticates by unix
socket and refuses root over TCP. The database name **must contain `test`** or the schema traits refuse to run
("Refusing to drop tables in …") — that guard is doing its job. Two tests fail on that local server whatever
the branch, because they are about the server and not the code: `CiDatabaseTest` (expects the engine CI
declares) and `PilotServerColumnMigrationTest::test_a_database_user_that_cannot_see_the_column…` (a probe
database user the local authentication refuses).

**Trap 2 — a workflow's build output is not its result.** Wave 7 was pushed and opened as four pull requests
when the build step finished, while two reviews per stream and the repairs were still running. All four
reviews then found something real. Do not push a stream, and do not move a criterion, until the stream's
**final** report exists — and read its `criteria_addressed` for the state the author claims. One criterion
(K-18.1) was recorded MET against its own author's claim of CODED.

**Trap 3 — every branch cut from `main` starts red.** `TorodShipmentDiscoveryTest::test_the_scheduled_run_links…`
stamps its order at a fixed instant (2026-11-10 09:00); past 36 hours, discovery moves the order to
`needs_review`. It fails on `main` and on every branch from it, in five jobs (both SQLite whole-suite jobs,
strict-mode, and the sales-channel N–Z shard on both engines), each showing exactly `Failures: 1`. The repair
is one line, already on `gcc/p0-saudi-baseline-ci` (`7f5c4906`) and `gcc/p0-remove-duplicate-payment-routing`
(`24cc1033`). A branch whose base carries it is green: #132's first head ran 20 jobs success with only the
deployment overlay red.

## 3. Corrections made this evening, in one place

- **Runs reported as "MariaDB 10.11" were SQLite** (trap 1). Every affected suite and every mutation was re-run
  on the real engine; no count changed. Pull requests 131–134 carry the correction.
- **K-18.1 was wrong twice.** Recorded MET from the first head's CI, then set to CODED "until the reviewed
  head's run is read". Both were wrong: the offer-or-not decision still reads the one global `active_theme`
  (`ShopImageRules.isAskedOf`), one layer down from where it was. It stays CODED until that line is repointed,
  which waits on risk R-117.
- **Pull request 133 was labelled I2-06.3.** It serves I2-06.0. The admin-menu criterion still waits for the
  screen of I2-03.
- **"Writing the 28 requirements' criteria is the cheapest way to move MISSING"** read as though the count
  would fall. It rose by 48, and was meant to: a placeholder counted one criterion where the behaviour needs
  four.
- **K-V3 was nearly downgraded** from MET to CODED after reading an older branch than the one its evidence
  rests on; `batch_measure.py` showed `MET: -1` and the evidence was restored verbatim before publishing.
- **STATUS.md carried 42 literal `\u2014` sequences** where a dash was meant; repaired.
- A local assertion count (797802) differed from CI's (797796) by six, as did #129's; CI is the authority.

## 4. What needs the owner. None of these was decided here.

**Money and payments**

1. **`liqpay` sends a paying customer to a 404.** It is in both default gateway lists, so an operator can
   switch it on; `Payment::generate_link` saves the payment request and redirects to `payment/liqpay/pay`,
   while only `payment/liqpay/payment` and `payment/liqpay/callback` are registered. An orphan
   `payment_requests` row is left behind. On `main` today. (#132)
2. **Deleting the Gateways add-on silently removes four gateways.** `AddonService::deleteAddon` copies
   `app/Traits/Payment.txt` over `Payment.php`, and that file lacks `paymera`, `mtn_cash`, `my_fatoorah` and
   `mercadopago_pix`. Risk R-002. (#132)
3. **Account deletion with a balance: refuse, or anonymise?** With the switch on, a customer cannot clear a
   small residue themselves — wallet checkout is all or nothing, there is no customer withdrawal, points under
   the conversion minimum cannot be converted, the only admin wallet action adds funds. Refusal is therefore a
   permanent block on "delete my account" unless one way out is built (partial wallet payment, an admin
   deduct-to-zero with a ledger row, or a forfeit-and-delete confirmation). The stream's recommendation is
   anonymisation, in the shape `BuyerDataPurger` already has. **Legal has to say**: how long a ledger row lives
   after the person is gone; whether a retained row may keep the customer id; whether an unspent balance is a
   liability to hold, hand over or forfeit, and what the customer must be told. (#131)
4. **The deletion threshold is SAR-shaped.** One hundredth is one halala, but KWD, BHD and OMR are 1/1000
   currencies, so up to nine of their smallest units would count as rounding dust. No minor-unit field exists
   in the codebase. Decide before the switch goes on in those three markets. (#131)
5. **Payment currency falls back silently.** `getPaymentGatewayCurrencyCode` substitutes the first active
   currency of a gateway's list, and finally `USD`, where the storefront's currency is not supported. (D2-V1)
6. **A wallet top-up can be credited twice and cannot be traced.** No replay guard at wallet level; the ledger
   row stores the payment *method* and drops the gateway's payment id; the amount is converted twice through a
   truncating function into the platform's currency, not the market's. (E-V1)

**Security**

7. **The secret scan gates one repository of three.** `lavkoo` has it; `lavkooappseller`'s workflow has none;
   `lavkooapp` has no `.github` directory on `main` at all. (M1-V2)
8. **All 222 rows of the security triage read OPEN.** Fixes exist for whole classes; no card maps a SEC id to
   what closed it, and the restricted annex has been read by no tracker unit. (M1-V1)
9. **A local admin of one market can read every market's wallet and loyalty movements of a customer.** The
   staff country scope covers orders, products and refunds only. (A2-V1)
10. **The theme builder guards the draft and not the market.** No storefront scope in `ThemeBuilderService`;
    every method takes an object the controller loaded by id. (F-V1)

**Seller app**

11. **`ConfigModel`'s constructor drops twelve values** — `this.activeTheme;` and eleven more are expression
    statements, not assignments; only `fromJson` sets them. On `main`. (#17)
12. **A seller can be made to upload an image they can never edit**: registration requires the secondary
    banner on every theme but `default`, the shop form offers it on `theme_aster` only. And the two forms
    state different ratios for the same shop banner (3:1 and 4:1). (#17)
13. **The apps carry their market list inside themselves** (`MarketScope.gccMarkets`), so opening or closing
    a market needs a release in both stores. (A1-V1)
14. **The version gate has an off switch the app parses and never reads.** (K-V5)

**Process**

15. **#129 changes two live Saudi screens with every flag off** and needs sign-off before it merges.
16. **Three finished documents block three requirements by living on unmerged branches**: the isolation
    design (G-V1), the legal audit (I2-V1) and the classification matrix (M2-V1).
17. **The one-line Torod repair** (trap 3) should reach `main` so that new branches stop starting red.

## 5. How to carry on

1. Read the CI of `cf0228b8`, `9510d42d`, `18bd311f` (lavkoo) and `5b2fd40` (seller) **job by job**. For
   lavkoo 131 expect it green but for the deployment overlay now that the Torod repair is on the branch.
2. Have #134 read by two independent reviewers before any criterion moves on it.
3. Then, and only then, put E-08.1, D2-04.2, I2-06.0, G-V2.2 and G-V2.3 through the programme's own method —
   a skeptical verifier and an independent judge per criterion — rather than moving them on a CI read alone.
   Open questions a judge should weigh: E-08.1 holds only with the switch on and is not atomic; D2-04.2's
   stated direction may hold while four other disagreements are pinned; D2-04.3 ("a stub key is never
   offered") is CODED in the tracker and `liqpay` contradicts it as a platform property.
4. Regenerate with `GCC_REPOS_ROOT=/home/user python3 gen_main.py ../.. <date>`, then `gen_pr_ledger.py`,
   `../check_pr_ledger.py docs/gcc/PR_LEDGER.md origin` (must exit 0) and `batch_measure.py <worktree>` —
   and **read what `batch_measure.py` prints**: it is what caught the K-V3 downgrade.
