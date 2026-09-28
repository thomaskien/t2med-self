#!/bin/bash
# T2med Check-in 1.4. Forced SSH command; accepts no arbitrary commands or SQL.
set -euo pipefail
umask 077
exec 2>/dev/null
PSQL=(/opt/t2med/server/postgres/bin/psql -X -w -h /tmp -p 16569 -U t2med -d t2med -v ON_ERROR_STOP=1 -At)
export PGOPTIONS='-c statement_timeout=10000 -c lock_timeout=1000 -c idle_in_transaction_session_timeout=15000'
IFS= read -r -t 10 payload
[[ ${#payload} -le 256 ]] || exit 1
if [[ "$payload" == check ]]; then
    count="$("${PSQL[@]}" -c "SELECT count(*) FROM information_schema.columns WHERE table_schema='aps' AND table_name='versicherungsnachweis' AND column_name='kartenvorlage_datum' AND data_type='date'")"
    [[ "$count" == 1 ]] || exit 1
    printf 'OK\n'
    exit 0
fi
IFS='|' read -r operation card_id revision target_date extra <<<"$payload"
[[ "$operation" == set && -z "${extra:-}" ]] || exit 1
[[ "$card_id" =~ ^[a-fA-F0-9]{20,80}$ ]] || exit 1
[[ "$revision" =~ ^[0-9]{1,18}$ ]] || exit 1
[[ "$target_date" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || exit 1
"${PSQL[@]}" -q <<SQL
BEGIN;
DO \$checkin\$
DECLARE affected integer;
BEGIN
    UPDATE aps.versicherungsnachweis
       SET kartenvorlage_datum = DATE '${target_date}'
     WHERE objectid = '${card_id}' AND revision >= ${revision} AND classid = 3
       AND creationtimestamp >= clock_timestamp() - interval '15 minutes';
    GET DIAGNOSTICS affected = ROW_COUNT;
    IF affected <> 1 THEN RAISE EXCEPTION 'Expected exactly one fresh card'; END IF;
END
\$checkin\$;
COMMIT;
SQL
verified="$("${PSQL[@]}" -c "SELECT count(*) FROM aps.versicherungsnachweis WHERE objectid='${card_id}' AND classid=3 AND kartenvorlage_datum=DATE '${target_date}'")"
[[ "$verified" == 1 ]] || exit 1
printf 'OK\n'
