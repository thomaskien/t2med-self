#!/bin/bash
# T2med Check-in 1.6.4 – Debian 12/13 and Raspberry Pi OS Bookworm/Trixie.
# This installer is intentionally not run while preparing the release.
set -euo pipefail
umask 027
VERSION=1.6.4
SOURCE_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="/opt/t2med-checkin/$VERSION"
CONFIG_DIR=/etc/t2med-checkin
STATE_DIR=/var/lib/t2med-checkin
SERVICE_USER=t2checkin
RESUME=0

usage() {
    echo "T2med Check-in $VERSION"
    echo 'Installation: sudo bash install.sh'
    echo 'Nach unterbrochener Installation fortsetzen: sudo bash install.sh --resume'
    echo 'Auch beim Update einer älteren Version: --resume im neuen Release-Verzeichnis ausführen.'
    echo 'Richtet eine eigene HTTPS-Apache-Instanz und einen PHP-FPM-Pool ein.'
    echo 'Bestehende Praxis-Websites, Netzwerkeinstellungen und T2med-Daten bleiben bei der Einrichtung erhalten.'
}
case "${1:-}" in
    --help|-h) usage; exit 0 ;;
    --resume) RESUME=1 ;;
    '') ;;
    *) usage >&2; exit 1 ;;
esac
[[ $# -le 1 ]] || { usage >&2; exit 1; }
[[ $EUID -eq 0 ]] || { echo 'Bitte den Installer mit sudo starten.' >&2; exit 1; }
[[ -t 0 ]] || { echo 'Bitte in einem interaktiven Terminal starten.' >&2; exit 1; }
[[ -f /etc/debian_version ]] || { echo "Version $VERSION unterstützt Debian 12/13 und Raspberry Pi OS Bookworm/Trixie." >&2; exit 1; }
[[ -f "$SOURCE_DIR/public/index.php" && -f "$SOURCE_DIR/src/bootstrap.php" && -f "$SOURCE_DIR/bin/check-runtime.php" && -f "$SOURCE_DIR/VERSION" ]] || { echo 'Das vollständige Release-Verzeichnis wird benötigt.' >&2; exit 1; }
[[ "$(< "$SOURCE_DIR/VERSION")" == "$VERSION" ]] || { echo 'Installer und Release-Version passen nicht zusammen.' >&2; exit 1; }
if [[ -d "$APP_DIR" && $RESUME -ne 1 ]]; then
    echo "Version $VERSION ist bereits vorhanden. Zum Fortsetzen --resume verwenden; vorhandene Konfiguration bleibt erhalten." >&2
    exit 1
fi
if [[ -L "$APP_DIR" || -L "$CONFIG_DIR" || -L "$STATE_DIR" ]]; then
    echo 'Installationsverzeichnisse dürfen keine symbolischen Links sein.' >&2; exit 1
fi
trap 'printf "\nInstallation angehalten (Zeile %s). Vorhandene Konfiguration bleibt erhalten. Fortsetzen mit --resume.\n" "$LINENO" >&2' ERR
trap 'stty echo 2>/dev/null || true' EXIT

ask() {
    local label="$1" default="${2:-}" reply
    printf '%s [%s]: ' "$label" "$default" >&2
    IFS= read -r reply
    printf '%s' "${reply:-$default}"
}
yes() {
    local answer
    answer="$(ask "$1 (j/n)" "${2:-n}")"
    [[ "$answer" == j || "$answer" == J || "$answer" == ja ]]
}

echo "T2med Check-in $VERSION wird mit einer eigenen HTTPS-Instanz eingerichtet."
if [[ -d /opt/t2med-checkin/1.0 || -d /opt/t2med-checkin/1.1 || -d /opt/t2med-checkin/1.2 || -d /opt/t2med-checkin/1.3 || -d /opt/t2med-checkin/1.4 || -d /opt/t2med-checkin/1.5 || -d /opt/t2med-checkin/1.5.1 || -d /opt/t2med-checkin/1.5.2 || -d /opt/t2med-checkin/1.5.3 || -d /opt/t2med-checkin/1.5.4 || -d /opt/t2med-checkin/1.5.5 ]]; then
    echo "Ältere Programmordner bleiben unverändert. Diese Einrichtung verwendet ausschließlich Version $VERSION."
    echo 'Vorhandene TOML-Konfiguration, Schlüssel und HTTPS-Einstellungen werden weiterverwendet.'
    echo 'Neue TOML-Felder werden nach Sicherung ergänzt; eigene Einstellungen und Formulare bleiben erhalten.'
    echo 'Für den bisherigen CDN-Port 16567 wird eine Korrektur auf 16570 angeboten; REST-Port und eigene Ports bleiben erhalten.'
fi
echo 'Die T2med-Zugangsdaten werden nur für die einmalige Prüfung abgefragt und nicht gespeichert.'
echo 'Geräteanmeldungen gelten höchstens 12 Stunden; längere TOML-Fristen werden nach Sicherung auf 12 begrenzt.'
echo 'Nach dem Update einmal neu am iPad anmelden; alte Sitzungen ohne Anmeldezeitpunkt werden nicht übernommen.'
echo 'APT installiert die benötigten PHP-/Apache-Pakete. Bestehende Web-Anwendungen werden nicht umkonfiguriert.'
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y apache2 php-cli php-fpm php-curl php-gd php-mbstring php-pgsql php-yaml php-tcpdf openssl openssh-client ca-certificates
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || { echo 'PHP 8.2 oder neuer wird benötigt.' >&2; exit 1; }
[[ -f "/etc/php/$PHP_VERSION/fpm/php-fpm.conf" ]] || { echo 'Passende PHP-FPM-Version fehlt.' >&2; exit 1; }
php "$SOURCE_DIR/bin/check-runtime.php" "$VERSION"

if ! id "$SERVICE_USER" >/dev/null 2>&1; then
    useradd --system --user-group --home-dir "$STATE_DIR" --no-create-home --shell /usr/sbin/nologin "$SERVICE_USER"
fi
install -d -o root -g "$SERVICE_USER" -m 750 "$CONFIG_DIR"
install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 700 "$STATE_DIR" "$STATE_DIR/sessions" "$STATE_DIR/requests" "$STATE_DIR/uploads" "$STATE_DIR/pending"
install -d -o root -g root -m 755 /opt/t2med-checkin
if [[ ! -d "$APP_DIR" ]]; then
    install -d -m 755 "$APP_DIR"
    cp -a "$SOURCE_DIR/src" "$SOURCE_DIR/public" "$SOURCE_DIR/bin" "$SOURCE_DIR/installer" "$SOURCE_DIR/fragebogenpi" "$SOURCE_DIR/pins" "$SOURCE_DIR/fragebogenpi-upstream.patch" "$SOURCE_DIR/config.example.toml" "$SOURCE_DIR/VERSION" "$SOURCE_DIR/README.md" "$APP_DIR/"
    chown -R root:root "$APP_DIR"
    find "$APP_DIR" -type d -exec chmod 755 {} +
    find "$APP_DIR" -type f -exec chmod 644 {} +
fi
php "$APP_DIR/bin/check-runtime.php" "$VERSION"
if [[ ! -f "$CONFIG_DIR/secret.key" ]]; then
    openssl rand -out "$CONFIG_DIR/secret.key" 32
fi
chown root:"$SERVICE_USER" "$CONFIG_DIR/secret.key"
chmod 640 "$CONFIG_DIR/secret.key"
php "$APP_DIR/bin/configure.php" "$CONFIG_DIR/config.toml"
chown root:"$SERVICE_USER" "$CONFIG_DIR/config.toml"
chmod 640 "$CONFIG_DIR/config.toml"
if [[ -f "$CONFIG_DIR/sql-password" ]]; then
    chown root:"$SERVICE_USER" "$CONFIG_DIR/sql-password"
    chmod 640 "$CONFIG_DIR/sql-password"
fi

get_config() { php "$APP_DIR/bin/config-get.php" "$1"; }
echo 'fragebogenpi: mitgelieferter, fest versionierter YAML-/Tablet-Stand wird lokal eingebunden.'
echo 'Datenschutz benötigt PHP-YAML und PHP-TCPDF; vorhandene Praxisvorlagen werden nicht überschrieben.'
echo 'Kein fragebogenpi-Netzwerk-, WLAN-, Samba- oder GDT-Installer wird ausgeführt.'
php "$APP_DIR/bin/install-forms.php" "$CONFIG_DIR/config.toml" "$SERVICE_USER"
if [[ "$(get_config card_presentation_date.enabled)" == true && "$(get_config sql.mode)" == ssh ]]; then
    SQL_HOST="$(get_config sql.host)"
    SQL_PORT="$(get_config sql.ssh_port)"
    if [[ ! -f "$CONFIG_DIR/sql_ed25519" ]]; then
        ssh-keygen -t ed25519 -N '' -C t2med-checkin-sql -f "$CONFIG_DIR/sql_ed25519"
    fi
    chown root:"$SERVICE_USER" "$CONFIG_DIR/sql_ed25519"
    chmod 640 "$CONFIG_DIR/sql_ed25519"
    touch "$CONFIG_DIR/known_hosts"
    chmod 640 "$CONFIG_DIR/known_hosts"
    chown root:"$SERVICE_USER" "$CONFIG_DIR/known_hosts"
    echo 'Für SQL über SSH wird auf dem T2med-Server ein begrenztes Gateway mit einem eigenen Schlüssel benötigt.'
    if yes 'SQL-Gateway jetzt über eine einmalige root-SSH-Anmeldung einrichten' j; then
        echo 'Der folgende SSH-Dialog prüft den Host-Schlüssel und fragt ggf. das root-Passwort ab.'
        REMOTE_TEMP="$(ssh -p "$SQL_PORT" -o "UserKnownHostsFile=$CONFIG_DIR/known_hosts" -o StrictHostKeyChecking=ask "root@$SQL_HOST" 'mktemp -d /tmp/t2med-checkin-sql.XXXXXX')"
        [[ "$REMOTE_TEMP" =~ ^/tmp/t2med-checkin-sql\.[A-Za-z0-9]{6,12}$ ]] || { echo 'Unerwartetes SSH-Tempverzeichnis.' >&2; exit 1; }
        scp -P "$SQL_PORT" -o "UserKnownHostsFile=$CONFIG_DIR/known_hosts" -o StrictHostKeyChecking=yes \
            "$APP_DIR/installer/sql-gateway.sh" "$APP_DIR/installer/provision-sql.sh" "$CONFIG_DIR/sql_ed25519.pub" "root@$SQL_HOST:$REMOTE_TEMP/"
        ssh -p "$SQL_PORT" -o "UserKnownHostsFile=$CONFIG_DIR/known_hosts" -o StrictHostKeyChecking=yes "root@$SQL_HOST" \
            "bash '$REMOTE_TEMP/provision-sql.sh' '$REMOTE_TEMP/sql_ed25519.pub'"
        echo "Die drei Einrichtungsdateien verbleiben zur Nachvollziehbarkeit unter $REMOTE_TEMP auf dem T2med-Server."
    else
        echo "Gateway manuell auf dem T2med-Server einrichten: installer/provision-sql.sh mit $CONFIG_DIR/sql_ed25519.pub."
        echo 'Außerdem den verifizierten T2med-SSH-Host-Schlüssel in /etc/t2med-checkin/known_hosts hinterlegen.'
    fi
fi
runuser -u "$SERVICE_USER" -- php "$APP_DIR/bin/check-config.php" "$CONFIG_DIR/config.toml"

# Own certificate and listener: no changes to the existing Apache virtual hosts.
if [[ ! -f "$CONFIG_DIR/web-settings" ]]; then
    KIOSK_HOST="$(ask 'DNS-Name oder IP-Adresse, unter der das iPad diese Anwendung öffnet' "$(hostname -f)")"
    [[ "$KIOSK_HOST" =~ ^[A-Za-z0-9][A-Za-z0-9.:-]*$ ]] || { echo 'Ungültiger Hostname.' >&2; exit 1; }
    LISTEN_ADDRESS="$(ask 'Lokale IPv4-Adresse für HTTPS (0.0.0.0 für alle Schnittstellen)' '0.0.0.0')"
    php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false ? 1 : 0);' "$LISTEN_ADDRESS" || { echo 'Ungültige Bind-Adresse.' >&2; exit 1; }
    HTTPS_PORT="$(ask 'Eigener HTTPS-Port für Check-in' '8443')"
    [[ "$HTTPS_PORT" =~ ^[0-9]{2,5}$ && "$HTTPS_PORT" -ge 1024 && "$HTTPS_PORT" -le 65535 ]] || { echo 'Port muss zwischen 1024 und 65535 liegen.' >&2; exit 1; }
    # Refuse a competing listener before creating service configuration.
    if ss -H -ltn "sport = :$HTTPS_PORT" | grep -q .; then
        echo "Port $HTTPS_PORT ist bereits belegt. Bitte einen freien Port wählen und --resume starten." >&2; exit 1
    fi
    printf '%s\n%s\n%s\n' "$KIOSK_HOST" "$LISTEN_ADDRESS" "$HTTPS_PORT" > "$CONFIG_DIR/web-settings"
    chmod 600 "$CONFIG_DIR/web-settings"
else
    mapfile -t WEB_SETTINGS < "$CONFIG_DIR/web-settings"
    KIOSK_HOST="${WEB_SETTINGS[0]}"; LISTEN_ADDRESS="${WEB_SETTINGS[1]}"; HTTPS_PORT="${WEB_SETTINGS[2]}"
fi
TLS_DIR="$CONFIG_DIR/tls"
install -d -m 700 "$TLS_DIR"
if [[ ! -f "$TLS_DIR/server.crt" ]]; then
    if [[ ! -f "$TLS_DIR/ca.key" ]]; then
        openssl req -x509 -newkey rsa:3072 -nodes -sha256 -days 3650 \
            -subj '/CN=Praxis Check-in Local CA' -addext 'basicConstraints=critical,CA:TRUE' -addext 'keyUsage=critical,keyCertSign,cRLSign' \
            -keyout "$TLS_DIR/ca.key" -out "$TLS_DIR/ca.crt"
    fi
    openssl req -new -newkey rsa:2048 -nodes -subj "/CN=$KIOSK_HOST" -keyout "$TLS_DIR/server.key" -out "$TLS_DIR/server.csr"
    if php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP) === false ? 1 : 0);' "$KIOSK_HOST"; then
        CERT_SAN="IP:$KIOSK_HOST"
    else
        CERT_SAN="DNS:$KIOSK_HOST"
    fi
    cat > "$TLS_DIR/server.ext" <<EOF
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=$CERT_SAN
EOF
    openssl x509 -req -sha256 -days 365 -in "$TLS_DIR/server.csr" -CA "$TLS_DIR/ca.crt" -CAkey "$TLS_DIR/ca.key" \
        -CAcreateserial -extfile "$TLS_DIR/server.ext" -out "$TLS_DIR/server.crt"
    chmod 600 "$TLS_DIR/ca.key" "$TLS_DIR/server.key"
fi
openssl x509 -in "$TLS_DIR/ca.crt" -outform DER -out "$APP_DIR/public/checkin-ca.cer"
chmod 644 "$APP_DIR/public/checkin-ca.cer"

cat > "$CONFIG_DIR/php-fpm.conf" <<EOF
[global]
pid = /run/t2med-checkin-php/php-fpm.pid
error_log = /var/log/t2med-checkin/php-fpm.log
daemonize = no

[t2med-checkin]
user = $SERVICE_USER
group = $SERVICE_USER
listen = /run/t2med-checkin-php/php.sock
listen.owner = $SERVICE_USER
listen.group = $SERVICE_USER
listen.mode = 0600
pm = ondemand
pm.max_children = 6
pm.process_idle_timeout = 30s
pm.max_requests = 500
request_terminate_timeout = 360s
clear_env = yes
security.limit_extensions = .php
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_flag[expose_php] = off
php_admin_flag[allow_url_fopen] = off
php_admin_flag[yaml.decode_php] = off
php_admin_value[error_log] = $STATE_DIR/php-error.log
php_admin_value[memory_limit] = 128M
php_admin_value[max_execution_time] = 300
php_admin_value[upload_max_filesize] = 3M
php_admin_value[post_max_size] = 4M
php_admin_value[max_input_vars] = 3000
php_admin_value[upload_tmp_dir] = $STATE_DIR/uploads
php_admin_value[session.save_path] = $STATE_DIR/sessions
php_admin_value[session.gc_maxlifetime] = 86400
EOF

cat > "$CONFIG_DIR/apache.conf" <<EOF
ServerRoot /etc/apache2
ServerName $KIOSK_HOST
DefaultRuntimeDir /run/t2med-checkin
PidFile /run/t2med-checkin/apache.pid
Listen $LISTEN_ADDRESS:$HTTPS_PORT
User $SERVICE_USER
Group $SERVICE_USER
LoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule authz_host_module /usr/lib/apache2/modules/mod_authz_host.so
LoadModule mime_module /usr/lib/apache2/modules/mod_mime.so
LoadModule dir_module /usr/lib/apache2/modules/mod_dir.so
LoadModule ssl_module /usr/lib/apache2/modules/mod_ssl.so
LoadModule socache_shmcb_module /usr/lib/apache2/modules/mod_socache_shmcb.so
LoadModule proxy_module /usr/lib/apache2/modules/mod_proxy.so
LoadModule proxy_fcgi_module /usr/lib/apache2/modules/mod_proxy_fcgi.so
LoadModule headers_module /usr/lib/apache2/modules/mod_headers.so
LoadModule reqtimeout_module /usr/lib/apache2/modules/mod_reqtimeout.so
LoadModule setenvif_module /usr/lib/apache2/modules/mod_setenvif.so
ServerTokens Prod
ServerSignature Off
TraceEnable Off
TypesConfig /etc/mime.types
DirectoryIndex index.php
DocumentRoot "$APP_DIR/public"
Timeout 360
ProxyTimeout 360
LimitRequestBody 4194304
RequestReadTimeout header=20-40,MinRate=500 body=20,MinRate=500
SSLProtocol -all +TLSv1.2 +TLSv1.3
SSLEngine on
SSLOptions +StdEnvVars
SSLCertificateFile "$TLS_DIR/server.crt"
SSLCertificateKeyFile "$TLS_DIR/server.key"
SSLSessionCache shmcb:/run/t2med-checkin/ssl_cache(512000)
ErrorLog /var/log/t2med-checkin/error.log
LogLevel warn
# Do not log query strings, cookies, request bodies or credentials.
LogFormat "%h %t %m %U %>s %b" checkin
CustomLog /var/log/t2med-checkin/access.log checkin
Header always set X-Content-Type-Options "nosniff"
Header always set Referrer-Policy "no-referrer"
<Directory />
    Require all denied
</Directory>
<Directory "$APP_DIR/public">
    Options -Indexes -ExecCGI +FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>
<FilesMatch "\\.php$">
    SetHandler "proxy:unix:/run/t2med-checkin-php/php.sock|fcgi://localhost/"
</FilesMatch>
<FilesMatch "\\.(toml|key|sh|log|md)$">
    Require all denied
</FilesMatch>
EOF
install -d -m 750 /var/log/t2med-checkin
cat > /etc/systemd/system/t2med-checkin-php.service <<EOF
[Unit]
Description=T2med Check-in $VERSION PHP-FPM
After=network.target

[Service]
Type=simple
RuntimeDirectory=t2med-checkin-php
RuntimeDirectoryMode=0755
ExecStart=/usr/sbin/php-fpm$PHP_VERSION --nodaemonize --fpm-config /etc/t2med-checkin/php-fpm.conf
ExecReload=/bin/kill -USR2 \$MAINPID
Restart=on-failure
UMask=0077
# A local T2med PostgreSQL socket may live in the real /tmp.
PrivateTmp=false
NoNewPrivileges=true
ProtectHome=true
ProtectSystem=full

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/systemd/system/t2med-checkin.service <<EOF
[Unit]
Description=T2med Check-in $VERSION HTTPS
After=network.target t2med-checkin-php.service
Requires=t2med-checkin-php.service

[Service]
Type=simple
RuntimeDirectory=t2med-checkin
RuntimeDirectoryMode=0755
ExecStart=/usr/sbin/apache2 -f /etc/t2med-checkin/apache.conf -DFOREGROUND
ExecReload=/usr/sbin/apache2 -f /etc/t2med-checkin/apache.conf -k graceful
KillSignal=SIGWINCH
TimeoutStopSec=30
Restart=on-failure
PrivateTmp=true
NoNewPrivileges=true
ProtectHome=true
ProtectSystem=full

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/logrotate.d/t2med-checkin <<'EOF'
/var/log/t2med-checkin/*.log {
    weekly
    rotate 4
    missingok
    notifempty
    compress
    delaycompress
    sharedscripts
    postrotate
        systemctl reload t2med-checkin.service >/dev/null 2>&1 || true
        systemctl reload t2med-checkin-php.service >/dev/null 2>&1 || true
    endscript
}
/var/lib/t2med-checkin/events.log /var/lib/t2med-checkin/php-error.log {
    weekly
    rotate 4
    missingok
    notifempty
    compress
    copytruncate
    su t2checkin t2checkin
}
EOF
# Remove abandoned sessions/empty lock files only after two days, well past all lease deadlines.
cat > /etc/tmpfiles.d/t2med-checkin.conf <<'EOF'
d /var/lib/t2med-checkin/sessions 0700 t2checkin t2checkin 2d -
d /var/lib/t2med-checkin/requests 0700 t2checkin t2checkin 2d -
d /var/lib/t2med-checkin/uploads 0700 t2checkin t2checkin 1d -
EOF
install -d -m 755 /run/t2med-checkin /run/t2med-checkin-php
"/usr/sbin/php-fpm$PHP_VERSION" -t --fpm-config "$CONFIG_DIR/php-fpm.conf"
/usr/sbin/apache2 -t -f "$CONFIG_DIR/apache.conf"
systemctl daemon-reload
systemctl enable t2med-checkin-php.service t2med-checkin.service
# Restart also activates the new DocumentRoot when our own services already run.
# Never restart the distribution's apache2 or php*-fpm services here.
systemctl restart t2med-checkin-php.service t2med-checkin.service

URL_HOST="$KIOSK_HOST"
if [[ "$URL_HOST" == *:* ]]; then URL_HOST="[$URL_HOST]"; fi
echo
echo "Version $VERSION ist eingerichtet: https://$URL_HOST:$HTTPS_PORT/"
echo "Konfiguration: $CONFIG_DIR/config.toml"
echo "Für die iPad-Kamera die eigene CA installieren und unter Einstellungen > Allgemein > Info > Zertifikatsvertrauenseinstellungen vollständig vertrauen."
echo "Öffentliches CA-Zertifikat: https://$URL_HOST:$HTTPS_PORT/checkin-ca.cer"
echo "Alternativ das Zertifikat über AirDrop/MDM übertragen: $APP_DIR/public/checkin-ca.cer"
echo 'Danach in Safari öffnen, optional zum Home-Bildschirm hinzufügen und durch einen Mitarbeiter anmelden.'
echo 'Es wurden keine Karten eingelesen oder Patientendaten verändert.'
