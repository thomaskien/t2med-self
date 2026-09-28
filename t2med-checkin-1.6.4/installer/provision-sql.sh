#!/bin/bash
# Run explicitly on the T2med host as root, with sql-gateway.sh and a public key.
set -euo pipefail
umask 077
[[ $EUID -eq 0 ]] || { echo 'Bitte auf dem T2med-Server als root ausführen.' >&2; exit 1; }
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
KEY_FILE="${1:?Pfad zur öffentlichen Check-in-SSH-Schlüsseldatei fehlt}"
[[ -x /opt/t2med/server/postgres/bin/psql ]] || { echo 'T2med-PostgreSQL nicht gefunden.' >&2; exit 1; }
[[ -f "$SOURCE_DIR/sql-gateway.sh" && -f "$KEY_FILE" ]] || exit 1
read -r key_type key_data key_comment < "$KEY_FILE"
[[ "$key_type" == ssh-ed25519 && "$key_data" =~ ^[A-Za-z0-9+/=]+$ ]] || exit 1
install -d -m 755 /usr/local/libexec
if [[ -f /usr/local/libexec/t2med-checkin-card-date ]]; then
    cp -p /usr/local/libexec/t2med-checkin-card-date "/usr/local/libexec/t2med-checkin-card-date.backup.$(date +%s)"
fi
install -m 755 "$SOURCE_DIR/sql-gateway.sh" /usr/local/libexec/t2med-checkin-card-date
install -d -m 700 /root/.ssh
touch /root/.ssh/authorized_keys
chmod 600 /root/.ssh/authorized_keys
expected="restrict,command=\"/usr/local/libexec/t2med-checkin-card-date\" ssh-ed25519 $key_data t2med-checkin-sql"
existing="$(grep -F -- "$key_data" /root/.ssh/authorized_keys || true)"
if [[ -n "$existing" && "$existing" != "$expected" ]]; then
    echo 'Dieser Schlüssel besitzt bereits andere SSH-Regeln. Bitte vor der Einrichtung prüfen.' >&2
    exit 1
fi
if [[ -z "$existing" ]]; then
    printf '\n%s\n' "$expected" >> /root/.ssh/authorized_keys
fi
printf 'check\n' | /usr/local/libexec/t2med-checkin-card-date
echo 'SQL-Gateway eingerichtet. Der neue Schlüssel erlaubt nur die festgelegten Datumsoperationen.'
