#!/bin/bash
# Refresh the staging DB from production (first run: step 7.2 prep, owner-approved 2026-09-29).
# From a Windows checkout strip CRs first (sed -i 's/\r$//' on a COPY), bash on the host rejects CRLF.
# Run ON THE SERVER, alone:  scp tools/staging_refresh.sh <server>:fwl-tools/ && ssh <server> 'bash ~/fwl-tools/staging_refresh.sh'
# Afterwards: check APP_ENV/BOKUN_SYNC_ENABLED/TWILIO_DRY_RUN/DIGEST_LIVE in the staging env, compare the
# staging passwords with production (fingerprints only), run the staging smoke test.
# Recount with ONE PHP connection per DB, not one mysql call per table: the host starts refusing
# socket connections (ERROR 2002) after a burst of mysql client calls (seen 2026-09-29).
# Production is only READ (one consistent mysqldump of data tables). Staging is backed up in full
# first. Staging keeps its own users/sessions/logins/rate limits, bokun_config (encrypted with the
# staging key), guide message tables, webhook logs, assistant tables and client_perf.
# Credentials come from the env files into mode-600 defaults files that are removed on exit.
set -euo pipefail
umask 077
cd "$HOME/fwl-tools"
TS=$(date +%Y%m%d_%H%M%S)
PROD_ENV=$HOME/env/withlocals/.env
STG_ENV=$HOME/env/stagingwithlocals/.env

val() { grep -E "^[[:space:]]*$2[[:space:]]*=" "$1" | tail -1 | cut -d= -f2- | sed -E 's/^[[:space:]]+|[[:space:]]+$//g; s/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/' | tr -d '\r'; }
mkcnf() { printf '[client]\nuser=%s\npassword="%s"\nhost=%s\n' "$(val "$1" DB_USER)" "$(val "$1" DB_PASS)" "$(val "$1" DB_HOST)" > "$2"; }
trap 'rm -f .prod.cnf .stg.cnf prod_part.sql' EXIT
mkcnf "$PROD_ENV" .prod.cnf
mkcnf "$STG_ENV" .stg.cnf
PROD_DB=$(val "$PROD_ENV" DB_NAME)
STG_DB=$(val "$STG_ENV" DB_NAME)

# hard guards: never import into anything but the staging DB
case "$STG_DB" in *_stg) ;; *) echo "refused: staging DB name '$STG_DB' does not end in _stg"; exit 2;; esac
[ "$STG_DB" != "$PROD_DB" ] || { echo "refused: same DB name"; exit 2; }
echo "production DB: $PROD_DB (read only)   staging DB: $STG_DB"

TABLES="tours tour_groups guides products tickets payments guide_payments pnl_settings pnl_tour_costs pnl_unit_links radio_orders viator_switch viator_watchdog sync_logs availability_requests"

count() { # count <cnf> <db>
  for t in $TABLES; do printf "%s=%s " "$t" "$(mysql --defaults-extra-file="$1" -N -B -e "SELECT COUNT(*) FROM \`$t\`" "$2")"; done; echo
}
echo "== before: staging"; count .stg.cnf "$STG_DB"

# 1. full staging backup (kept until 7.2 is verified)
BK=$HOME/backups/stg_before_refresh_$TS.sql.gz
mysqldump --defaults-extra-file=.stg.cnf --single-transaction --no-tablespaces --routines --triggers "$STG_DB" | gzip > "$BK"
gzip -t "$BK"
echo "staging backup: $BK ($(du -h "$BK" | cut -f1))"

# 2. production dump: data tables only, one consistent snapshot, read only
mysqldump --defaults-extra-file=.prod.cnf --single-transaction --no-tablespaces --skip-triggers "$PROD_DB" $TABLES > prod_part.sql
echo "production dump: $(du -h prod_part.sql | cut -f1)"
grep -c '^CREATE TABLE' prod_part.sql | sed 's/^/tables in dump: /'

# 3. load into staging (each copied table is dropped and recreated)
mysql --defaults-extra-file=.stg.cnf "$STG_DB" < prod_part.sql

echo "loaded; recount with a PHP script (one connection per DB), see the header"
echo DONE
