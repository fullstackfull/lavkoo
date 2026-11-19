#!/usr/bin/env python3
"""Writes docs/gcc/PR_LEDGER.md: every pull request of lavkoo with the branch, base, head of the day and the requirements of the master tracker that name it.

usage: gen_pr_ledger.py <docs-gcc-dir> <asof-date>

It reads the remote's pull request refs (refs/pull/N/head, one `git ls-remote`), the stored index of the pull requests and the tracker's links; it writes the one file.
Nothing here reads CI: a state of CI is only claimed where the tracker has read it.
"""
import collections
import json
import os
import subprocess
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import gen_main as M  # noqa: E402

REPO = '/home/user/lavkoo'
URL = 'https://github.com/fullstackfull/lavkoo'
INDEX = os.path.join(os.environ.get('GCC_TRACKER_DATA', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'data')), 'lavkoo-pulls.json')

# Pull requests opened after the stored index was taken: title, branch, base.
EXTRA = {
    87: ('GCC-05 slices 1 to 5: a storefront trades in its own currency; its amounts, shipping and coupons are its own; its orders and payments respect it', 'gcc/p1-storefront-currency', 'gcc/p1-admin-product-country'),
    88: ('E-06: wallet:reconcile, a read-only proof that wallet balances and loyalty points equal their ledgers', 'gcc/p1-wallet-reconcile-command', 'main'),
    89: ('fix(strict-sql): three checkout statements a strict SQL server refuses now name the column they group by', 'gcc/p0-coupon-use-count-strict-sql', 'main'),
    90: ('GCC-13: the company that sells in each country is registered, stamped on the order and named on its invoice', 'gcc/p1-legal-entity-registry', 'gcc/p1-storefront-currency'),
    91: ('docs(gcc): the exact protection rule for main and a CODEOWNERS file, for a person to apply', 'gcc/p0-branch-protection-draft', 'main'),
    92: ('test(ci): every test starts from an empty database on a real server', 'gcc/p0-test-empty-real-database', 'main'),
    93: ('fix(privacy): a paid order no longer appends its customer, guest, address and order ids to a file under storage/logs', 'gcc/p1-remove-order-debug-file', 'main'),
    94: ('test(ci): every test directory runs on a real MySQL or MariaDB, or says why it does not', 'gcc/p0-ci-every-test-directory-on-a-real-engine', 'main'),
    95: ("test(wallet): a customer's balances are written by their known writers and by nothing else (E-03)", 'gcc/p1-wallet-balance-writers-guard', 'main'),
    96: ('test(gcc): six-country isolation for every ordered pair (M2-02) and one customer across the storefronts (M2-04)', 'gcc/p1-storefront-six-country-isolation', 'gcc/p1-storefront-currency'),
    97: ('test(saudi): golden answers of the checkout, recorded from main, that every GCC branch must leave unchanged while its switches are off (M1-17, M2-01)', 'gcc/p0-saudi-golden-responses', 'main'),
    98: ("test(tax): what an order keeps of its tax is pinned, and its tax row stops writing '' into an integer column (D1-07 step 1)", 'gcc/p1-order-tax-characterization', 'main'),
    101: ('feat(gcc): a refund names the money and the company of its order (D1-10 first criterion, additive, recording only)', 'gcc/p1-refund-records-order-money', 'gcc/p1-legal-entity-registry'),
    102: ("fix(tax): the rows of order_taxes add up to the tax of the order, and a read-only command lists the orders whose rows do not (D1-07; draft, awaiting finance review)", 'gcc/p1-order-tax-rows-per-line', 'gcc/p1-order-tax-characterization'),
    103: ("feat(gcc): a refund worked out from the original payment, in its currency, not wired to any payout (D1-10; draft, prepared and not adopted)", 'gcc/p1-refund-from-original-payment', 'gcc/p1-refund-records-order-money'),
    104: ("ci(saudi): the Saudi baseline pack is a named job on MySQL 8 and MariaDB 11.4.2, and a pull request may not change an existing golden answer (M2-01; draft)", 'gcc/p0-saudi-baseline-ci-job', 'gcc/p0-saudi-golden-responses'),
    105: ('feat(gcc): a market does not sell on an empty legal requirement or without a recorded independent legal review (I2-02, I2-07; draft, off by default)', 'gcc/p1-storefront-legal-review', 'gcc/p1-storefront-tax-setup'),
    106: ('fix(security): the vendor password reset and the Zender gateway no longer write one-time codes or their message to the log (main-based; M1-04, M2-13, M2-18; draft)', 'gcc/p0-stop-logging-one-time-codes', 'main'),
    107: ('feat(gcc): each storefront serves its own active theme, and activating one never deactivates another\'s (F-01 criteria 0 to 3; draft, off by default)', 'gcc/p1-storefront-theme-isolation', 'gcc/p1-storefront-currency'),
    108: ('feat(gcc): the seller app can ask who it is, and the seller order list and detail carry the storefront and currency of each order (K-17.1, K-08.3, K-04.0; draft, off by default)', 'gcc/p1-seller-market-contract', 'gcc/p1-storefront-currency'),
    109: ('feat(gcc): a wallet or loyalty ledger row says what stands behind it, who made a manual one, and the market it was made in (E-05 criteria 1, 2 and 5; draft, dormant until its migration has run)', 'gcc/p1-wallet-ledger-attribution', 'gcc/p1-legal-entity-registry'),
    110: ('feat(gcc): a storefront\'s business pages carry its own legal texts, in the visitor\'s language, and the agreement links name the storefront (I2-04; draft, off by default)', 'gcc/p1-storefront-legal-pages', 'gcc/p1-storefront-currency'),
    111: ('feat(gcc): a storefront is a draft, ready, active or paused (browse only, maintenance or redirect), kept in the database and audited, and only an active one sells (I2-11 to I2-17; draft, off by default)', 'gcc/p1-storefront-lifecycle', 'gcc/p1-storefront-legal-review'),
    112: ('feat(gcc): one storefront\'s theme is published, scheduled, previewed and delivered without touching another\'s, and the caches, preview, app contract, library, import and tokens are pinned (F-02, F-04, F-07 to F-12; draft, off by default; stacked on #107)', 'gcc/p1-theme-publish-per-storefront', 'gcc/p1-storefront-theme-isolation'),
    113: ('feat(gcc): a channel with no renderer (the seller app) is sent no theme section; a channel never shares a cached page; the channels of a section move the stored checksum (F-03 criteria 0 and 2; draft, stacked on #112)', 'gcc/p1-theme-channel-delivery', 'gcc/p1-theme-publish-per-storefront'),
    114: ('feat(gcc): the legal text of a storefront is written, approved, published and revoked through one audited service and a command (I2-01, I2-03; draft, off by default, stacked on #105)', 'gcc/p1-legal-text-publisher', 'gcc/p1-storefront-legal-review'),
    115: ('feat(gcc): the lifecycle and the legal texts of a storefront are run from the admin panel, behind three permissions, by the admin who is signed in (I2-03, I2-11; draft, off by default)', 'gcc/p1-storefront-admin-screens', 'gcc/p1-storefront-lifecycle'),
    100: ('fix(privacy): a customer embedded in an order, refund, review or chat carries no wallet balance, loyalty points or referral code (A2-15 criterion 2)', 'gcc/p0-embedded-customer-without-balances', 'main'),
    99: ("feat(gcc): a storefront is taxed by its own setup and cannot sell before its tax is approved (D1-07, GCC-05 step 11, off by default)", 'gcc/p1-storefront-tax-setup', 'gcc/p1-storefront-currency'),
    # Titles, branches and bases read from the remote's own pull request records on 2026-11-19; the head is always the remote's.
    116: ('fix(security): a vendor lists, views, edits, switches and deletes only the coupons that are theirs, in the seller app and in the vendor panel (draft, main based; one owner decision)', 'gcc/p1-security-coupon-ownership', 'main'),
    117: ('test(gcc): every writer of orders says what keeps a stopped storefront out of it, and the marketplace dispatch is pinned as it is (I2-09; draft, test only, stacked on #111)', 'gcc/p1-order-writers-gate-list', 'gcc/p1-storefront-lifecycle'),
    118: ('test(gcc): the customer balance answers, the ledger edit scan and the vendor ledger entry are pinned as they are (E-04.2; E-08.0 and E-17.0 stay open; draft, test only, main based)', 'gcc/p1-wallet-contract-tests', 'main'),
    119: ('test(gcc): a stopped storefront still serves its cart, wishlist and catalogue and refuses only a new sale, and the platform maintenance is pinned through the real routes (I2-13.2, I2-14.0; draft, test only, stacked on PR 111)', 'gcc/p1-stop-mode-pins', 'gcc/p1-storefront-lifecycle'),
    120: ('test(gcc): the coupon rules of the customer API, the currency round trip and the vendor ownership are pinned as they are (I1-11.4, I1-12.0, I1-14.0, I1-14.1; I1-11.3, I1-12.1, I1-12.2 stay open; draft, test only, stacked on PR 96)', 'gcc/p1-coupon-behaviour-pins', 'gcc/p1-storefront-six-country-isolation'),
    121: ('test(gcc): the legal text version key, the policy registry kept apart from legal documents and a scan for claims of compliance are pinned (I2-01.2, I2-06.2, I2-07.4; I2-06.1 and I2-07.0 in part; draft, test only, stacked on PR 115)', 'gcc/p1-legal-store-pins', 'gcc/p1-storefront-admin-screens'),
    122: ('test(gcc): six legacy behaviours of main are pinned as they are: push language, push topics, the abandoned-cart ledger, one-time codes by app mode, the vendor split of an order and the website direction (I1-03.1, I1-05.3, I1-09.1, M1-05.5, M2-06.2, I1-16.5; draft, test only, main based)', 'gcc/p1-legacy-behaviour-pins', 'main'),
    123: ('test(gcc): the status, refund and due-payment notifications of an earlier order are sent in every state in which a storefront may not sell (I1-07.1; draft, test only, stacked on PR 111)', 'gcc/p1-notification-stop-pins', 'gcc/p1-storefront-lifecycle'),
    124: ('M2-19: the Saudi literals in the platform\'s code are a closed list that fails on a new one (tests only)', 'gcc/p1-saudi-literals-guard', 'main'),
}


def remote_heads():
    out = subprocess.run(['git', '-C', REPO, 'ls-remote', 'origin', 'refs/pull/*/head'], capture_output=True, text=True, timeout=120, check=True).stdout
    return {int(line.split('\t')[1].split('/')[2]): line.split('\t')[0] for line in out.splitlines() if line}


def requirements_by_pr():
    by_pr = collections.defaultdict(set)
    for data in M.load_all().values():
        for requirement in data['reqs']:
            for link in requirement.get('links', []):
                if link.get('repo') == 'lavkoo' and link.get('pr'):
                    by_pr[int(link['pr'])].add(requirement['id'])
    return by_pr


def short(title):
    text = title.split(': ', 1)[-1] if title.startswith(('fix(', 'feat(', 'test(', 'chore(', 'ci(', 'docs(')) else title
    return text.replace('|', '\\|')[:110]


def main():
    out_dir, asof = sys.argv[1], sys.argv[2]
    heads = remote_heads()
    index = {int(item['number']): item for item in json.load(open(INDEX))}
    served = requirements_by_pr()
    rows = []

    for number in sorted(heads):
        if number in EXTRA:
            title, branch, base = EXTRA[number]
            state = 'draft'
        elif number in index:
            item = index[number]
            title, branch, base = item['title'], item['head'], item['base']
            state = 'merged' if item['merged'] else ('draft' if item['draft'] else item['state'])
        else:
            title, branch, base, state = '(not in the stored index)', '', '', 'open'
        requirements = ', '.join(sorted(served.get(number, []), key=lambda value: (value.split('-')[0], int(''.join(ch for ch in value.split('-')[1] if ch.isdigit()) or 0))))
        rows.append('| [%d](%s/pull/%d) | %s | `%s` | `%s` | [`%s`](%s/pull/%d/commits/%s) | %s | %s |' % (
            number, URL, number, short(title), branch, base, heads[number][:8], URL, number, heads[number], state, requirements or ''))

    text = """# LAVKOO GCC: pull request ledger

As of %s. One row for every pull request of `lavkoo`, with the branch, the base it is stacked on, the head it had when this was written, and the requirements of the
[master tracker](MASTER_IMPLEMENTATION_TRACKER.md) that name it (so a reader can go from a requirement to its code and back). Generated by a read-only script from the remote's
pull request refs (`git ls-remote origin 'refs/pull/*/head'`), the stored index of the pull requests and the links of the tracker; `docs/gcc/tools/check_pr_ledger.py` compares the heads
below with the remote and exits 1 when one moved or a pull request is missing.

- **Nothing here is merged, deployed or verified in production.** Every pull request is a draft. A head in this table is not a claim that its CI passed: the state of CI of a head is
  read per job in the tracker and in the audit reports, never inferred from this table. Use the pull request's checks page for the run of a head.
- The base column shows the stack: a pull request whose base is another pull request's branch cannot be merged before it (see [MERGE_ORDER.md](MERGE_ORDER.md)).
- The title and the base of a pull request opened or changed after the stored index was taken are from this script's table of later pull requests (87 to 101); the head is always the remote's.
- Pull request 75 and 83 are the CI evidence branches of the security set and of the GCC stack; the all-in-one integration branch `gcc/release/all-integration-ci` is not a pull request and is never merged.

| PR | Title | Branch | Base | Head | State | Requirements |
|---|---|---|---|---|---|---|
%s
""" % (asof, '\n'.join(rows))
    open(os.path.join(out_dir, 'PR_LEDGER.md'), 'w', encoding='utf-8').write(text)
    print('pull requests:', len(rows), 'with requirements:', sum(1 for number in heads if served.get(number)))


if __name__ == '__main__':
    main()
