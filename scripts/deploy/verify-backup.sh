#!/usr/bin/env bash
#
# Proves a backup made by backup.sh can be restored, WITHOUT touching the live database: the dump is loaded into a scratch
# database that this script creates and drops again, and its row counts are compared with the ones taken at backup time.
#
#   scripts/deploy/verify-backup.sh --backup=/path/to/the/backup/directory --app=/path/to/app
#
# It needs the MySQL account in .env to be allowed to CREATE and DROP databases. Many hosting plans do not allow that; then import
# db.sql.gz into a new, empty database with phpMyAdmin and compare the counts in row-counts.txt by hand.

set -euo pipefail
umask 077

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKUP=""
APP="$(pwd)"
for argument in "$@"; do
  case "$argument" in
    --backup=*) BACKUP="${argument#*=}" ;;
    --app=*) APP="${argument#*=}" ;;
    -h|--help) sed -n '2,11p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "verify-backup: unknown option $argument" >&2; exit 2 ;;
  esac
done

fail() { echo "verify-backup: $*" >&2; exit 1; }
[ -f "$BACKUP/SHA256SUMS" ] || fail "--backup must be a directory made by backup.sh."
command -v mysql >/dev/null 2>&1 || fail "'mysql' is not available."

( cd "$BACKUP" && sha256sum -c SHA256SUMS ) || fail "a checksum does not match: the backup is damaged."
[ -f "$BACKUP/files.tar.gz" ] && { tar -tzf "$BACKUP/files.tar.gz" >/dev/null || fail "files.tar.gz cannot be read."; }
[ -f "$BACKUP/db.sql.gz" ] || { echo "No database dump in this backup: nothing more to verify."; exit 0; }

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

php "$HERE/mysql-defaults.php" --app="$APP" --out="$DEFAULTS" >/dev/null || fail "could not read the database settings from $APP/.env."
SCRATCH="pharmacy_restore_check_$(head -c 4 /dev/urandom | od -An -tx1 | tr -d ' \n')"
mysql --defaults-extra-file="$DEFAULTS" -e "CREATE DATABASE \`$SCRATCH\` CHARACTER SET utf8mb4" 2>/dev/null \
  || { SCRATCH=""; fail "this MySQL account may not create databases. Import db.sql.gz into a new empty database by hand and compare row-counts.txt."; }

echo "Loading the dump into the scratch database $SCRATCH ..."
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
