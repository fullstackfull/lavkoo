#!/usr/bin/env bash
#
# A full backup of the Pharmacy KSA application and its database, taken BEFORE a deployment.
#
#   bash backup.sh --app=/path/to/app [--to=/dir/outside/the/web/root] [--db-only | --files-only] [--no-vendor] [--no-uploads]
#
# It needs mysql-defaults.php beside it (the deployment package keeps both in tools/). It writes only into --to.
#
# Writes one directory:  db.sql.gz  files.tar.gz  row-counts.txt  SHA256SUMS  RESTORE.txt
# and prints where it is. The output of this script is not secret. The BACKUP is: it holds the .env file and customer data, so it
# stays on the server (or on storage you control), never on GitHub and never in a third-party service.
#
# Nothing here changes the application or the database: the dump is taken in a single consistent read (--single-transaction).

set -Eeuo pipefail
umask 077
trap 'echo "backup: stopped unexpectedly at line $LINENO (status $?)." >&2' ERR

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP="$(pwd)"
TO=""
DO_DB=1
DO_FILES=1
NO_VENDOR=0
NO_UPLOADS=0
ALLOW_INSIDE_APP=0
FORCE_LOW_DISK=0

for argument in "$@"; do
  case "$argument" in
    --app=*) APP="${argument#*=}" ;;
    --to=*) TO="${argument#*=}" ;;
    --db-only) DO_FILES=0 ;;
    --files-only) DO_DB=0 ;;
    --no-vendor) NO_VENDOR=1 ;;
    --no-uploads) NO_UPLOADS=1 ;;
    --allow-inside-app) ALLOW_INSIDE_APP=1 ;;
    --force-low-disk) FORCE_LOW_DISK=1 ;;
    -h|--help) sed -n '2,12p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "backup: unknown option $argument" >&2; exit 2 ;;
  esac
done

fail() { echo "backup: $*" >&2; exit 1; }
[ "$DO_DB" = 1 ] || [ "$DO_FILES" = 1 ] || { echo "backup: --db-only and --files-only together would back up nothing; use one of them, or neither for both." >&2; exit 2; }
need() { command -v "$1" >/dev/null 2>&1 || fail "'$1' is not available on this server. $2"; }

# An absolute path with no `.`, `..` or links in it, for a directory that may not exist yet.
absolute_path() {
  local path="$1" rest=""
  if realpath -m / >/dev/null 2>&1; then realpath -m -- "$path"; return; fi
  # Without GNU realpath a `..` after a missing component cannot be resolved, and a destination that climbs back into the application
  # must not pass the check below.
  case "/$path/" in */../*) echo "backup: this server's realpath cannot resolve '..' in --to; write the destination without '..'." >&2; exit 2 ;; esac
  case "$path" in /*) ;; *) path="$PWD/$path" ;; esac
  while [ ! -e "$path" ] && [ "$path" != "/" ]; do rest="/$(basename "$path")$rest"; path="$(dirname "$path")"; done
  printf '%s%s\n' "$(cd "$path" && pwd -P)" "$rest"
}

APP="$(cd "$APP" 2>/dev/null && pwd -P)" || fail "--app is not a directory."
[ -f "$APP/artisan" ] && [ -f "$APP/.env" ] || fail "$APP does not look like the application (no artisan and .env)."
[ -n "$TO" ] || TO="$(dirname "$APP")/pharmacy-backups"
TO="$(absolute_path "$TO")"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
DEST="$TO/$STAMP"
case "$DEST/" in "$APP"/*) [ "$ALLOW_INSIDE_APP" = 1 ] || fail "the backup directory $DEST is inside the application tree, which may be served to the web. Use --to=<a directory outside it>." ;; esac

need php "PHP CLI is needed to read the database settings."
need tar "Without tar, copy the application directory by hand (SFTP or the hosting file manager)."
need gzip ""
need sha256sum ""
need df ""
if [ "$DO_DB" = 1 ]; then
  need mysqldump "Export the database from phpMyAdmin or the hosting panel instead (all tables, structure and data, compressed), and keep the file with the file backup."
  need mysql ""
fi

# The option file with the database password lives in a private temporary directory. A normal exit and SIGTERM/SIGHUP remove it; a SIGKILL
# (the out-of-memory killer) cannot, so after one look for $TMPDIR/tmp.*/client.cnf and delete it.
WORK="$(mktemp -d)"
MADE=0
DONE=0
finish() {
  rm -rf "$WORK"
  if [ "$MADE" = 1 ] && [ "$DONE" != 1 ]; then
    rm -rf "$DEST"
    echo "backup: the unfinished backup in $DEST was removed, so nothing partial is left beside a real one." >&2
  fi
}
trap finish EXIT
DEFAULTS="$WORK/client.cnf"

DB_NAME=""
if [ "$DO_DB" = 1 ]; then
  FACTS="$(php "$HERE/mysql-defaults.php" --app="$APP" --out="$DEFAULTS")" || fail "could not read the database settings from $APP/.env."
  DB_NAME="$(printf '%s\n' "$FACTS" | sed -n 's/^DB_DATABASE=//p')"
  mysql --defaults-extra-file="$DEFAULTS" -N -e "SELECT 1" "$DB_NAME" >/dev/null 2>&1 || fail "cannot connect to database '$DB_NAME' with the settings in .env."
fi

# umask 077: a directory this run creates is private; one that was already there keeps its mode. Only the dated directory is ever removed
# if the run does not finish, and only if this run made it.
mkdir -p "$TO"
mkdir "$DEST" || fail "$DEST already exists; wait a second and run again."
MADE=1
chmod 700 "$DEST"
if [ "$ALLOW_INSIDE_APP" != 1 ]; then
  case "$(cd "$DEST" && pwd -P)/" in "$APP"/*) fail "the backup directory resolves to a place inside the application tree, which may be served to the web. Use --to=<a directory outside it>." ;; esac
fi

# Enough room? Compression is not predictable, so require half of the uncompressed size.
DB_KB=0
if [ "$DO_DB" = 1 ]; then
  DB_KB="$(mysql --defaults-extra-file="$DEFAULTS" -N -e "SELECT COALESCE(ROUND(SUM(data_length+index_length)/1024),0) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null || echo 0)"
fi
FILES_KB=0
# Only an estimate: a directory that cannot be read, or a cache file that vanishes while it is counted, must not stop the backup.
[ "$DO_FILES" = 1 ] && { FILES_KB="$(du -sk "$APP" 2>/dev/null | cut -f1)" || true; }
case "$FILES_KB" in ''|*[!0-9]*) FILES_KB=0 ;; esac
FREE_KB="$(df -Pk "$TO" | awk 'NR==2 {print $4}')"
NEEDED_KB=$(( (DB_KB + FILES_KB) / 2 ))
if [ "$FREE_KB" -lt "$NEEDED_KB" ] && [ "$FORCE_LOW_DISK" = 0 ]; then
  fail "only $((FREE_KB / 1024)) MB free where the backup goes; about $((NEEDED_KB / 1024)) MB are needed. Free some space, use --no-vendor / --no-uploads, or --to=<another disk>."
fi

if [ "$DO_DB" = 1 ]; then
  echo "Dumping database '$DB_NAME' (about $((DB_KB / 1024)) MB of data) ..."
  OPTS=(--defaults-extra-file="$DEFAULTS" --single-transaction --quick --routines --triggers --events --hex-blob --default-character-set=utf8mb4)
  HELP="$(mysqldump --help 2>/dev/null || true)"
  printf '%s' "$HELP" | grep -q -- '--no-tablespaces' && OPTS+=(--no-tablespaces)
  printf '%s' "$HELP" | grep -q -- '--column-statistics' && OPTS+=(--column-statistics=0)
  printf '%s' "$HELP" | grep -q -- '--set-gtid-purged' && OPTS+=(--set-gtid-purged=OFF)
  mysqldump "${OPTS[@]}" "$DB_NAME" | gzip -6 > "$DEST/db.sql.gz" || fail "the database dump failed."

  gzip -t "$DEST/db.sql.gz" || fail "the dump is not a valid gzip file."
  zcat "$DEST/db.sql.gz" | tail -n 3 | grep -q 'Dump completed' || fail "the dump does not end with mysqldump's completion line: it is truncated. Do not deploy."

  : > "$DEST/row-counts.txt"
  for table in orders order_details products users business_settings migrations jobs failed_jobs admins integration_settings; do
    count="$(mysql --defaults-extra-file="$DEFAULTS" -N -e "SELECT COUNT(*) FROM \`$table\`" "$DB_NAME" 2>/dev/null || true)"
    [ -n "$count" ] && printf '%s\t%s\n' "$table" "$count" >> "$DEST/row-counts.txt"
  done
  tables="$(mysql --defaults-extra-file="$DEFAULTS" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'" 2>/dev/null || echo 0)"
  printf '%s\t%s\n' "(tables in the database)" "$tables" >> "$DEST/row-counts.txt"
  echo "  db.sql.gz: $(du -h "$DEST/db.sql.gz" | cut -f1), complete."
fi

if [ "$DO_FILES" = 1 ]; then
  echo "Archiving the application files (about $((FILES_KB / 1024)) MB before compression) ..."
  EXCLUDES=(--exclude='./.git' --exclude='./storage/logs/*' --exclude='./storage/framework/cache/*' --exclude='./storage/framework/sessions/*' --exclude='./storage/framework/views/*' --exclude='./bootstrap/cache/*.php')
  [ "$NO_VENDOR" = 1 ] && EXCLUDES+=(--exclude='./vendor')
  [ "$NO_UPLOADS" = 1 ] && EXCLUDES+=(--exclude='./storage/app/public' --exclude='./public/storage')
  tar -czf "$DEST/files.tar.gz" -C "$APP" "${EXCLUDES[@]}" . || fail "the file archive failed."
  tar -tzf "$DEST/files.tar.gz" >/dev/null || fail "the file archive cannot be read back."
  echo "  files.tar.gz: $(du -h "$DEST/files.tar.gz" | cut -f1), readable."
fi

( cd "$DEST" && sha256sum $( [ "$DO_DB" = 1 ] && echo db.sql.gz row-counts.txt ) $( [ "$DO_FILES" = 1 ] && echo files.tar.gz ) > SHA256SUMS )

cat > "$DEST/RESTORE.txt" <<RESTORE
Restore points for $(basename "$APP"), taken $(date -u +'%Y-%m-%d %H:%M UTC').
Check the files first:   cd "$DEST" && sha256sum -c SHA256SUMS

DATABASE (only if the deployment's migrations damaged data; this REPLACES the database with the state at backup time, so anything ordered since is lost):
  1. Put the site in maintenance mode:      php artisan down
  2. Write a mysql option file with the .env credentials (mode 600) and run:
       gunzip -c "$DEST/db.sql.gz" | mysql --defaults-extra-file=<that file> ${DB_NAME:-<database>}
  3. Clear caches and bring the site back:  php artisan optimize:clear && php artisan up

FILES (the deployment script has its own rollback, php deploy.php rollback; use this only if the application directory itself was damaged):
  tar -xzf "$DEST/files.tar.gz" -C <application directory>
  The archive contains .env and storage/: restoring it over a running site also restores those.
RESTORE
chmod 600 "$DEST"/* 2>/dev/null || true
DONE=1

echo
echo "Backup complete: $DEST"
for name in db.sql.gz files.tar.gz row-counts.txt SHA256SUMS RESTORE.txt; do
  if [ -f "$DEST/$name" ]; then printf '  %s  %s\n' "$(wc -c < "$DEST/$name" | tr -d ' ')" "$name"; fi
done
echo "Keep it off GitHub and off third-party storage: it contains .env and customer data."
