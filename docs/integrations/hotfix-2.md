# Hotfix 2: Torod Point orders map and warehouse stock ownership

> **Status: prepared, not deployed.** Nothing in this document has been run against the live server, the live database or Torod
> Point. This package changes **code only**: **no migration, no `.env` line, no setting, no credential, no data**. It switches
> nothing on, creates no order, no shipment and no product at Torod Point, and starts no synchronisation.

## What it does

| | Before | After |
|---|---|---|
| **Orders field map** | *Save and verify* fails with `invoice_number`: most historical warehouse orders carry `invoiceNumber: null`, and every required field needed a value in 80% of the sample | The invoice-number **key** has to be present in 80% of the sample and may be empty. A path that does not exist (a typo, another name) still fails. `reference` (the order barcode) stays strict. The page says how many sampled orders really had an invoice number ("0 / 5 …") and that the map is verified for its **shape only** |
| **What a verified orders map means** | it also let a lost answer be looked up | the same, and **nothing more**: it releases no order and proves no round trip |
| **Real orders** | created as soon as the switches allowed | created only after a **master administrator** has recorded, with evidence, that the invoice number we send comes back in the order list (Torod Point page → *Invoice number round trip*). Until then they are held as *Provider not ready*. Rehearsals (Dry run) are not affected |
| **Finding a lost order again** | the first listed order carrying our invoice number | the same, but: a blank invoice number never matches; an order's `id` or barcode is never taken for the invoice number; a match without a barcode is not acceptance; two orders carrying our number are not linked automatically (they wait for a person) |
| **Stock field** | the screen proposed `quantity` (a `0` on every product of the live account) and the confirmation was a bare tick | **no quantity is proposed**; the confirmation names the **path** it was made for and lapses when the path changes; its text says what is being confirmed (available to sell, already excluding units on orders the warehouse accepted) |
| **Stock sync** (off, and stays off) | any approved mapping was written, a negative figure became `0` | only products **assigned to Torod Point**; a figure that is missing, negative, fractional, not a number or contradictory is **skipped and counted, never turned into zero**; not one usable figure is a failure, not a success. Products not assigned keep their own stock |
| **Product form** | a typed stock figure always replaced the stock | when (and only when) the warehouse controls a product's stock, the typed figure is ignored and the page says so; so does the manual stock entry of the matching page |

The full behaviour is in [torod-point.md](torod-point.md#real-orders-wait-for-one-confirmation-the-invoice-number-comes-back) (orders)
and [torod-point.md](torod-point.md#stock) (stock). The questions only LogesTechs can answer are
[section F of the request](vendor-requests/logestechs-technical-request.md).

**Untouched by design:** `FULFILLMENT_EXTERNAL_WRITES_ENABLED` (stays `false`), automatic dispatch, automatic inventory synchronisation,
pushing products to Torod Point, M5AZN, prices, discounts, VAT, titles, descriptions, credentials, the database and its migrations.

## The package

An **incremental** overlay: the files that changed between commit **`698aa1e`** (the tree the server runs after Hotfix 1; the
documentation of `506429a`, the merge of that pull request, has the identical tree) and the Hotfix 2 commit. It contains no test, no
documentation and no tool of the repository. The commit hash and the file count are given to you in writing with this document and
are valid for that hash only.

## The procedure

Run these commands as `root` or as the owner of the application files. Set the variables first (a new shell has lost them; `DOCROOT` is
the one from phase 0 of the [pilot deployment](pilot-deployment.md#0-look-at-the-server-first-read-only)):

```bash
APP=/path/to/the/application                                   # the directory that contains artisan and .env
BACKUPS=/path/outside/every/web/root/pharmacy-backups          # the same directory as for the earlier deployments
HOTFIX2=/path/outside/every/web/root/overlay-hotfix-2          # a NEW directory (as root, /root/… is fine)
PHP=php                                                        # the PHP binary of the earlier deployments (write its full path if several are installed)
: "${APP:?}" "${BACKUPS:?}" "${HOTFIX2:?}" "${DOCROOT:?}" "${PHP:?}"     # stops here if one of them is empty
for dir in "$BACKUPS" "$HOTFIX2"; do
  case "$(realpath -m "$dir")/" in "$(realpath -m "$DOCROOT")"/*) echo "STOP: $dir is inside the web root" ;; *) echo "ok: $dir is outside the web root" ;; esac
done
OWNER=$(stat -c %U "$APP/artisan")                             # the user that owns the application files
art() { (cd "$APP" && sudo -u "$OWNER" "$PHP" artisan "$@"); }  # without sudo: runuser -u "$OWNER" -- "$PHP" artisan "$@"; already that user: "$PHP" artisan "$@"
```

**H2-0. Prove what the server runs before you change it (reads only).** The record of Hotfix 1's deployment does; the server's own `git`
HEAD proves nothing, the overlay does not move it:

```bash
ls -t "$BACKUPS/deploy" | head -5                                # Hotfix 1's directory (…-hotfix1) is among them
LAST="$BACKUPS/deploy/<the …-hotfix1 directory>"
php -r '$m = json_decode(file_get_contents($argv[1]), true); echo $m["head"], PHP_EOL;' "$LAST/MANIFEST.json"    # the commit Hotfix 1 was built at
grep -c rolled_back_at "$LAST/applied.json"                      # must print 0: Hotfix 1 was not rolled back
```

The printed commit may differ from `698aa1e` by documentation only; step H2-3 is what proves the files.

**H2-1. Get the package.** Never fetch into, pull in, check out in or reset `$APP`. `<HOTFIX2_COMMIT>` is the **full 40-character hash we
give you in writing** (never a branch name). Either download the artifact `pilot-overlay-<HOTFIX2_COMMIT>` of the pull request's CI run
(`overlay.tar.gz` and `overlay.tar.gz.sha256`), or build it in a scratch clone **anywhere but `$APP`**:

```bash
WORK=$HOME/hotfix2-build && test ! -e "$WORK"                                          # a NEW directory
git clone -c core.autocrlf=false https://github.com/fullstackfull/pharmacyksa98.git "$WORK/repo"     # a private repository: clone it the way you usually do
git -C "$WORK/repo" checkout --detach <HOTFIX2_COMMIT>                                 # a scratch clone: nothing on the server changes
test "$(git -C "$WORK/repo" rev-parse HEAD)" = "<HOTFIX2_COMMIT>" && echo "the clone is at the hotfix commit"
git -C "$WORK/repo" cat-file -t 698aa1e                                                # must print: commit
php "$WORK/repo/scripts/deploy/build-overlay.php" --repo="$WORK/repo" --base=698aa1e --head=<HOTFIX2_COMMIT> --out="$WORK/overlay" --archive
```

Copy `overlay.tar.gz` and `overlay.tar.gz.sha256` to the server, outside every web root.

**H2-2. Check it.**

```bash
sha256sum -c overlay.tar.gz.sha256                                  # in the directory holding both files
mkdir -p "$HOTFIX2" && tar -xzf overlay.tar.gz -C "$HOTFIX2"
(cd "$HOTFIX2" && sha256sum -c SHA256SUMS | grep -v ': OK$' ; echo "(nothing listed above = every file matches its checksum)")
php -r '$m = json_decode(file_get_contents($argv[1]), true); echo count($m["files"]), " files, base ", $m["base"], ", head ", $m["head"], PHP_EOL;' "$HOTFIX2/MANIFEST.json"
```

Expected: `head` is `<HOTFIX2_COMMIT>`, `base` begins `698aa1e`, and the file count is the one we gave you. A base of `34dce65…` or `ef843600…` means a
different, larger package was built: do not use it.

**H2-3. Compare it with the files on the server (reads only).**

```bash
"$PHP" "$HOTFIX2/deploy.php" verify --app="$APP" --package="$HOTFIX2"
```

Expected, and nothing else: a `Summary:` line with only `CLEAN` and `NEW` (the count of each is given with the package) and
`Result: every file can be applied.` `CLEAN` means the file on the server is exactly the one Hotfix 1 installed. A `DRIFTED` file means
someone changed it since: read what they changed (`diff -u "$HOTFIX2/base/<path>" "$APP/<path>"`) and accept it by name
(`--accept-drift=…`) only after a person has looked.

> **Gate A1, for this package's files.** Nothing here switches anything on: sending stays off and `FULFILLMENT_EXTERNAL_WRITES_ENABLED`
> stays `false`. If you want a second pair of eyes first, the changed files are listed in the pull request.

**H2-4. Put the files on the server (about a minute; the database is not touched).** One chain: if any step fails, the next does not run
and the site stays in maintenance mode (the failure table of [4a](pilot-deployment.md#4a-the-files-about-two-minutes-the-database-is-not-touched) applies; read `$HOTFIX2` for `$PKG`).

```bash
STAMP=$(date -u +%Y%m%d-%H%M%S)
"$PHP" "$HOTFIX2/deploy.php" apply --app="$APP" --package="$HOTFIX2"                      # dry run: prints the report, changes nothing
art down --retry=60 \
  && "$PHP" "$HOTFIX2/deploy.php" apply --app="$APP" --package="$HOTFIX2" --yes --backup-dir="$BACKUPS/deploy/$STAMP-hotfix2" \
  && art optimize:clear && art queue:restart && art --version && art up
```

Keep the `[next] To undo:` line `apply` prints. Reload PHP-FPM or reset OPcache if `opcache.validate_timestamps` is off. The language
keys the release adds are merged by `apply` (the site's own keys stay).

**H2-5. Read-only checks.**

```bash
art fulfillment:preflight --allow-production-read-only
art fulfillment:compatibility --allow-production-read-only --expect-database=<the production database name>
```

Expected: **the same result as after Hotfix 1**: no new FAIL; the lines that prove writes and sending are off (*external writes*,
*m5azn dispatch*, *torod_point dispatch*) PASS; *nothing was written*. Anything different: stop and send us the output.

**H2-6. Look at the pages (nothing here writes to a provider).** In the admin panel: **3rd Party Setup → Fulfillment providers**.

* *Integration mode on this server* is still **READ-ONLY**. Open **Torod Point**: it renders, and the settings are exactly what they were
  (Dry run on, *Send orders to this provider* off, *Send automatically* off, *Follow warehouse stock* off).
* The capability table lists **Real order submission: Unverified**, and the checklist *Before automatic sending* lists *Invoice number
  round trip confirmed* as unmet. That is the new gate, closed, as intended.
* Under *Products and stock* the box beside the stock field names the path it is about, or says to set the path first. **Leave it
  unticked.**

**H2-7. Verify the orders map (reads the order list; creates nothing).** On the Torod Point page, *Field maps → Orders*:

1. **Fetch real sample** (a read-only `GET` of the order list; names, phones and addresses are masked in what is shown).
2. Check the paths. They are already saved: *Invoice number* `invoiceNumber`, *Order reference (barcode)* `barcode`, *Status* `status`
   (**Use proposed paths** fills them from the sample; `id` and `barcode` are never proposed for the invoice number, and a map that reads
   the invoice number from the barcode or the id can never be confirmed, so no real order would be created).
3. **Save and verify**. Expected: the map reads **verified**, and an amber notice says how many of the sampled orders had an invoice
   number (for example `0 / 5`) and that the map is verified for its shape only. **That is the intended result, not a warning to act on.** It does not unlock anything.
   If it says `invoice_number` or `reference` did not resolve, send us the sample's field names (no values).

**H2-8. Stop here.** The following belong to later gates and need your decision in writing; none is part of this deployment:

* **Invoice number round trip**: ask LogesTechs ([section F, item 31](vendor-requests/logestechs-technical-request.md)); only after their written
  answer (or an order created for the purpose and read back) does the master administrator record it on the Torod Point page. Until then
  no real order is created, in any mode.
* **Stock**: ask LogesTechs which quantity is available to sell ([items 29–30](vendor-requests/logestechs-technical-request.md)). Until they answer, do not tick the confirmation, and keep *Follow warehouse stock* **off**.
* `FULFILLMENT_EXTERNAL_WRITES_ENABLED`, *Send orders*, *Send automatically* and every controlled test order: the
  [controlled test orders](controlled-test-orders.md) plan, which now also asks for the round-trip confirmation.

**Send us**, in this order: the H2-0 head line; the H2-3 `Summary:` and `Result:` lines; the H2-4 `[ok]` and `To undo:` lines; the H2-5
output; what H2-6 and H2-7 showed (a screenshot is fine; no customer data).

## Taking Hotfix 2 back

Newest first: `"$PHP" "$HOTFIX2/deploy.php" rollback --backup-dir="$BACKUPS/deploy/$STAMP-hotfix2"` (the directory `apply` printed) restores
Hotfix 1's version of every file, removes the new ones and takes back only the language keys this release added; then `art optimize:clear
&& art queue:restart`. There is **no database change to undo**. The only things Hotfix 2 writes anywhere are plain keys in the Torod
Point settings JSON, and only when someone uses the new buttons: `field_maps.orders.coverage`, `invoice_round_trip` and
`stock_field_confirmed_path`. The previous code ignores all three. A confirmation recorded under Hotfix 2 does nothing under Hotfix 1,
which has no such gate. Hotfix 1 also treats a recorded stock confirmation as valid whatever the stock path is, so **if you ever ticked
the stock confirmation, untick it and save before rolling back**.

## What is verified and what is not

Verified by tests (SQLite, MySQL 8, MariaDB 10.11; every behaviour change has a test that fails when the change is reverted, checked by
mutation): the orders map with historical, null, missing, wrong-path and filled invoice numbers; the strict barcode; that a verified map
releases nothing; the held real order, the rehearsal that is not held, the adapter's own refusal, the withdrawal when the path changes
and the master-administrator rule with its audit entry; the reconciliation rules; that a lost answer is never answered with a second
order; the stock modes (every combination of assigned, mapped, switched on, verified and confirmed), the confirmation bound to its path,
the assigned-only write, every kind of unusable figure, reservations for every shipment status, idempotence, the stock movement, the
product form and the manual entry guard, and that nothing but the stock of an assigned product changes.

**Not verified** (it needs the live account): what the order list really returns for an order we create; which warehouse quantity is
available to sell; whether the live account's response differs from the shapes the tests assume; the pilot server's MariaDB 11.4.2
(this release has no migration, so nothing database-specific is new).
