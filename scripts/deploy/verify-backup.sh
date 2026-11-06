#!/usr/bin/env bash
#
# Checks a backup made by backup.sh, in two levels.
#
#   bash verify-backup.sh --backup=/path/to/the/backup/directory --app=/path/to/app [--restore-test] [--force-low-disk] [--allow-events]
#
# Without --restore-test it only READS the backup: the checksums, that the archive and the dump are intact, and that the dump ends with
# mysqldump's completion line. That does not prove the dump loads.
#
# With --restore-test it also loads the dump into a scratch database that it creates and drops again on the SAME MySQL server as the live
# database, and compares row counts with the ones taken at backup time. That is a real restore, so it needs about twice the dump's
# uncompressed size free in MySQL's data directory (checked when that directory is on this machine; --force-low-disk skips the check),
# it puts load on the live site's database (run it off-peak), and it needs the .env account to be allowed to CREATE and DROP databases.
# If the live database has scheduled events the dump would create them in the scratch database, where they would run: it refuses unless
# --allow-events. Many hosting plans cannot do any of this; then import db.sql.gz into a new, empty database on ANOTHER server (or with
# phpMyAdmin) and compare the counts in row-counts.txt by hand.

set -euo pipefail
umask 077

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP=""
APP="$(pwd)"
RESTORE_TEST=0
FORCE_LOW_DISK=0
ALLOW_EVENTS=0
for argument in "$@"; do
  case "$argument" in
    --backup=*) BACKUP="${argument#*=}" ;;
    --app=*) APP="${argument#*=}" ;;
    --restore-test) RESTORE_TEST=1 ;;
    --force-low-disk) FORCE_LOW_DISK=1 ;;
    --allow-events) ALLOW_EVENTS=1 ;;
    -h|--help) sed -n '2,16p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "verify-backup: unknown option $argument" >&2; exit 2 ;;
  esac
done

fail() { echo "verify-backup: $*" >&2; exit 1; }
[ -f "$BACKUP/SHA256SUMS" ] || fail "--backup must be a directory made by backup.sh."

( cd "$BACKUP" && sha256sum -c SHA256SUMS ) || fail "a checksum does not match: the backup is damaged."
[ -f "$BACKUP/files.tar.gz" ] && { tar -tzf "$BACKUP/files.tar.gz" >/dev/null || fail "files.tar.gz cannot be read."; }
[ -f "$BACKUP/db.sql.gz" ] || { echo "No database dump in this backup: nothing more to verify."; exit 0; }
gzip -t "$BACKUP/db.sql.gz" || fail "db.sql.gz is not a valid gzip file."
gunzip -c "$BACKUP/db.sql.gz" | tail -n 3 | grep -q 'Dump completed' || fail "the dump does not end with mysqldump's completion line: it is truncated."

if [ "$RESTORE_TEST" != 1 ]; then
  echo "The checksums match, the archive and the dump are intact, and the dump is complete."
  echo "That is NOT a restore test. To load the dump into a scratch database and compare row counts, run this again with --restore-test"
  echo "(read the header of this script first: it loads the live database server and needs free disk space in MySQL's data directory)."
  exit 0
fi

command -v mysql >/dev/null 2>&1 || fail "'mysql' is not available."
WORK="$(mktemp -d)"
trap 'cleanup' EXIT
DEFAULTS="$WORK/client.cnf"
SCRATCH=""
cleanup() {
  if [ -n "$SCRATCH" ] && [[ "$SCRATCH" =~ ^pharmacy_restore_check_[0-9a-f]{8}$ ]]; then
    mysql --defaults-extra-file="$DEFAULTS" -e "DROP DATABASE IF EXISTS \`$SCRATCH\`" >/dev/null 2>&1 || echo "verify-backup: could not drop the scratch database $SCRATCH; drop it by hand." >&2
  fi
  rm -rf "$WORK"
}

FACTS="$(php "$HERE/mysql-defaults.php" --app="$APP" --out="$DEFAULTS")" || fail "could not read the database settings from $APP/.env."
DB_NAME="$(printf '%s\n' "$FACTS" | sed -n 's/^DB_DATABASE=//p')"
DB_HOST="$(printf '%s\n' "$FACTS" | sed -n 's/^DB_HOST=//p')"

# The scratch copy is as big as the data: on a server whose disk cannot hold a second copy, loading it would stop the live site. The free
# space of THIS machine's disk says something about MySQL's only when MySQL runs here.
DATADIR="$(mysql --defaults-extra-file="$DEFAULTS" -N -e 'SELECT @@datadir' 2>/dev/null || true)"
DUMP_KB=$(( ($(gunzip -c "$BACKUP/db.sql.gz" | wc -c) + 1023) / 1024 ))
case "$DB_HOST" in socket|localhost|127.0.0.1|::1|'[::1]') DB_IS_LOCAL=1 ;; *) DB_IS_LOCAL=0 ;; esac
if [ "$DB_IS_LOCAL" != 1 ]; then
  echo "Note: MySQL runs on $DB_HOST, not on this machine, so its free space cannot be checked from here."
elif [ -z "$DATADIR" ] || [ ! -d "$DATADIR" ]; then
  echo "Note: MySQL's data directory (${DATADIR:-unknown}) cannot be read from this account, so its free space cannot be checked from here."
else
  FREE_KB="$(df -Pk "$DATADIR" 2>/dev/null | awk 'NR==2 {print $4}' || true)"
  case "$FREE_KB" in
    ''|*[!0-9]*) [ "$FORCE_LOW_DISK" = 1 ] || fail "cannot read the free space of $DATADIR. Use --force-low-disk to go on without the check, if you know better." ;;
    *)
      NEEDED_KB=$(( DUMP_KB * 2 ))
      if [ "$FREE_KB" -lt "$NEEDED_KB" ] && [ "$FORCE_LOW_DISK" != 1 ]; then
        fail "only $((FREE_KB / 1024)) MB are free in MySQL's data directory ($DATADIR); loading the dump needs about $((NEEDED_KB / 1024)) MB. Import it into another server instead, or free space, or --force-low-disk if you know better."
      fi ;;
  esac
fi

EVENTS="$(mysql --defaults-extra-file="$DEFAULTS" -N -e "SELECT COUNT(*) FROM information_schema.events WHERE event_schema='$DB_NAME'" 2>/dev/null || echo 0)"
if [ "${EVENTS:-0}" != 0 ] && [ "$ALLOW_EVENTS" != 1 ]; then
  fail "the live database has $EVENTS scheduled event(s); the dump would create them in the scratch database, where they would run. Import the dump on another server, or pass --allow-events if you are sure."
fi

SCRATCH="pharmacy_restore_check_$(head -c 4 /dev/urandom | od -An -tx1 | tr -d ' \n')"
mysql --defaults-extra-file="$DEFAULTS" -e "CREATE DATABASE \`$SCRATCH\` CHARACTER SET utf8mb4" 2>/dev/null \
  || { SCRATCH=""; fail "this MySQL account may not create databases. Import db.sql.gz into a new empty database by hand and compare row-counts.txt."; }

echo "Loading the dump (about $((DUMP_KB / 1024)) MB uncompressed) into the scratch database $SCRATCH ..."
gunzip -c "$BACKUP/db.sql.gz" | mysql --defaults-extra-file="$DEFAULTS" "$SCRATCH" || fail "the dump does not load."

STATUS=0
while IFS=$'\t' read -r table expected; do
  if [ "$table" = "(tables in the database)" ]; then
    actual="$(mysql --defaults-extra-file="$DEFAULTS" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$SCRATCH'")"
  else
    actual="$(mysql --defaults-extra-file="$DEFAULTS" -N -e "SELECT COUNT(*) FROM \`$table\`" "$SCRATCH" 2>/dev/null || echo missing)"
  fi
  if [ "$actual" = "$expected" ]; then
    printf '  ok      %-28s %s\n' "$table" "$actual"
  else
    printf '  DIFFERS %-28s backup time %s, restored %s\n' "$table" "$expected" "$actual"
    STATUS=1
  fi
done < "$BACKUP/row-counts.txt"

[ "$STATUS" = 0 ] && echo "The backup restores, and its row counts match." || echo "The restored counts differ from the counts taken at backup time (rows written between the counts and the dump can explain a small difference on a busy site)."
exit "$STATUS"
