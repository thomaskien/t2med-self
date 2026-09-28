#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use Checkin\{Config, ConfigUpgrade, RestClient, T2med, Toml};

if (PHP_SAPI !== 'cli') { exit(1); }
function ask(string $label, string $default = ''): string
{
    fwrite(STDERR, $label . ($default !== '' ? ' [' . $default . ']' : '') . ': ');
    $answer = fgets(STDIN);
    if ($answer === false) { throw new RuntimeException('Eingabe abgebrochen.'); }
    $answer = trim($answer); return $answer === '' ? $default : $answer;
}
function yes(string $label, bool $default): bool
{
    while (true) {
        $value = strtolower(ask($label . ' (j/n)', $default ? 'j' : 'n'));
        if (in_array($value, ['j', 'ja', 'y', 'yes'], true)) { return true; }
        if (in_array($value, ['n', 'nein', 'no'], true)) { return false; }
    }
}
function password(string $label): string
{
    fwrite(STDERR, $label . ': ');
    $old = trim((string) shell_exec('stty -g'));
    if ($old === '' || !preg_match('/^[a-zA-Z0-9:;]+$/D', $old)) { throw new RuntimeException('Für die verdeckte Passworteingabe wird ein Terminal benötigt.'); }
    exec('stty -echo');
    try {
        $line = fgets(STDIN);
        if ($line === false) { throw new RuntimeException('Eingabe abgebrochen.'); }
        return rtrim($line, "\r\n");
    } finally { exec('stty ' . escapeshellarg($old)); fwrite(STDERR, "\n"); }
}
function selectItem(string $label, array $items, callable $describe, string $preferred = ''): array
{
    if ($items === []) { throw new RuntimeException('T2med liefert keine Auswahl für: ' . $label); }
    fwrite(STDERR, "\n" . $label . "\n"); $default = '';
    foreach (array_values($items) as $i => $item) {
        $text = $describe($item);
        fwrite(STDERR, '  ' . ($i + 1) . ') ' . $text . "\n");
        if ($text === $preferred) { $default = (string) ($i + 1); }
    }
    if (count($items) === 1) { $default = '1'; }
    while (true) {
        $choice = ask('Nummer', $default);
        if (ctype_digit($choice) && (int) $choice >= 1 && (int) $choice <= count($items)) { return array_values($items)[(int) $choice - 1]; }
    }
}
function contextLabel(array $row): string
{
    $label = '';
    foreach (['beschreibung','bezeichnung','name','kuerzel'] as $key) {
        if (is_string($row[$key] ?? null) && trim($row[$key]) !== '') { $label = $row[$key]; break; }
    }
    if ($label === '') { $label = trim(($row['vorname'] ?? '') . ' ' . ($row['nachname'] ?? '')); }
    return ($label !== '' ? $label . ' – ' : '') . T2med::ref($row['ref'] ?? null)['objectId']['id'];
}

$stage = 'Konfiguration und Eingaben';
try {
    $target = $argv[1] ?? '/etc/t2med-checkin/config.toml';
    if (is_file($target)) {
        $stage = 'vorhandene Konfiguration und CDN-Port prüfen';
        $existing = new Config($target); $correctCdnPort = false;
        if ($existing->get('t2med.cdn_port') === 16567) {
            fwrite(STDERR, "\nDer bisherige Installer schlug für den CDN-Dienst fälschlich Port 16567 vor.\n"
                . "Die mitgelieferte T2med-Konfiguration 26.8.0 verwendet standardmäßig CDN-Port 16570.\n"
                . "Bei abweichender Server-/Proxy-Konfiguration bitte den bestehenden Port beibehalten.\n"
                . "Diese Prüfung liest nur die lokale TOML; der Server wurde nicht geprüft.\n");
            $correctCdnPort = yes('CDN-Port für eine T2med-Standardinstallation von 16567 auf 16570 korrigieren', true);
        }
        $stage = 'freigegebene Konfigurationsmigration für Version ' . Checkin\VERSION;
        $backup = ConfigUpgrade::file($target, $correctCdnPort);
        if ($backup !== null) {
            fwrite(STDERR, "Konfiguration aktualisiert (neue Kontakt-/Fragebogen-/Kamerafelder, bisherige Empfang/Karten-Standardwerte und ggf. bestätigte CDN-Portkorrektur).\n"
                . "Übrige Werte bleiben erhalten. Sicherung: " . $backup . "\n");
        } else { fwrite(STDERR, "Vorhandene TOML-Konfiguration wird unverändert beibehalten.\n"); }
        $active = new Config($target);
        fwrite(STDERR, 'REST-Port bleibt ' . $active->get('t2med.rest_port') . '; CDN-Port: ' . $active->get('t2med.cdn_port') . ".\n");
        exit(0);
    }
    $data = Toml::read(dirname(__DIR__) . '/config.example.toml');
    fwrite(STDERR, "\nT2med Check-in " . Checkin\VERSION . " – Servereinrichtung\n");
    $data['t2med']['server'] = ask('T2med-Server (IP-Adresse oder DNS-Name)', $data['t2med']['server']);
    $data['t2med']['rest_port'] = (int) ask('T2med-REST-Port', (string) $data['t2med']['rest_port']);
    $data['t2med']['cdn_port'] = (int) ask('T2med-CDN-Port (eigener Dienst für Bilder)', (string) $data['t2med']['cdn_port']);
    $data['t2med']['verify_tls'] = yes('T2med-Serverzertifikat prüfen', $data['t2med']['verify_tls']);
    if ($data['t2med']['verify_tls']) {
        $ca = ask('Pfad zur T2med-CA-Datei (leer bei öffentlich vertrauenswürdigem Zertifikat)');
        if ($ca !== '') {
            if (!is_readable($ca) || !openssl_x509_read(file_get_contents($ca))) { throw new RuntimeException('CA-Datei fehlt oder ist kein lesbares PEM-Zertifikat.'); }
            $dest = dirname($target) . '/t2med-ca.pem';
            if (!copy($ca, $dest)) { throw new RuntimeException('CA-Datei konnte nicht gespeichert werden.'); }
            chmod($dest, 0644); $data['t2med']['ca_file'] = $dest;
        }
    }
    $username = ask('T2med-Benutzer für die einmalige Einrichtung'); $pass = password('T2med-Passwort (leer ist zulässig)');
    $stage = 'REST-Client vorbereiten';
    $config = new Config($data, false);
    $t2med = new T2med($config, new RestClient($config, $username, $pass));
    $stage = 'T2med-Anmeldung und Listen lesen';
    fwrite(STDERR, "T2med-Anmeldung wird geprüft; Kartenleser, Wartebereiche und technischer Kontext werden gelesen …\n");
    $inventory = $t2med->discover();
    $stage = 'Kartenleser, Kontext und Wartebereiche auswählen';
    $reader = selectItem('Kartenleser', $inventory['readers'], static fn($r) => $r['geraetename'] ?? '', $data['reader']['name']);
    $data['reader']['name'] = $reader['geraetename'];
    fwrite(STDERR, "\nDer folgende Kontext gilt nur für technische Aufrufe. Am iPad wird er nicht ausgewählt. Es wird kein Schein angelegt.\n");
    $role = selectItem('Technische Arztrolle', T2med::list($inventory['context'], 'arztrollen'), 'contextLabel');
    $location = selectItem('Technischer Behandlungsort', T2med::list($inventory['context'], 'behandlungsorte'), 'contextLabel');
    $data['t2med']['doctor_role_id'] = T2med::ref($role['ref'])['objectId']['id'];
    $data['t2med']['treatment_location_id'] = T2med::ref($location['ref'])['objectId']['id'];
    foreach ($data['categories'] as $key => &$category) {
        if (!isset($category['room'])) { continue; }
        $matches = array_values(array_filter($inventory['rooms'], static fn($r) => $r['name'] === $category['room']));
        if (count($matches) === 1) { fwrite(STDERR, $key . ': ' . $category['room'] . "\n"); continue; }
        $chosen = selectItem('Zielwartebereich für ' . $key . ' (Vorgabe „' . $category['room'] . '“ fehlt)', $inventory['rooms'], static fn($r) => $r['name']);
        $category['room'] = $chosen['name'];
    }
    unset($category);
    $stage = 'optionalen SQL-Zugang konfigurieren';
    $data['card_presentation_date']['enabled'] = yes('Kartenvorlagedatum nach dem Einlesen per SQL auf 01.01.1990 setzen', $data['card_presentation_date']['enabled']);
    $data['sql']['host'] = $data['t2med']['server'];
    if ($data['card_presentation_date']['enabled']) {
        fwrite(STDERR, "SQL verändert nur den frisch eingelesenen eGK-Datensatz nach der Patientenzuordnung. T2med-Revision und Envers-Audit bleiben dabei unverändert.\n");
        do { $mode = ask('SQL-Verbindung: local (Socket), tcp (PostgreSQL/TLS) oder ssh (Gateway)', $data['sql']['mode']); }
        while (!in_array($mode, ['local','tcp','ssh'], true));
        $data['sql']['mode'] = $mode;
        if ($mode === 'ssh') {
            $data['sql']['ssh_port'] = (int) ask('SSH-Port des T2med-Servers', '22');
        } else {
            if ($mode === 'local') { $data['sql']['socket_directory'] = ask('PostgreSQL-Socket-Verzeichnis', '/tmp'); }
            else { $data['sql']['host'] = ask('PostgreSQL-Server', $data['sql']['host']); }
            $data['sql']['port'] = (int) ask('PostgreSQL-Port', '16569');
            $data['sql']['database'] = ask('Datenbank', 't2med'); $data['sql']['username'] = ask('Datenbankbenutzer', 't2med');
            $dbpass = password('Datenbankpasswort (leer bei Socket-Zugang ohne Passwort)');
            if ($dbpass !== '') {
                $secretPath = dirname($target) . '/sql-password';
                if (file_exists($secretPath)) { throw new RuntimeException('SQL-Passwortdatei existiert bereits; bitte vor Fortsetzung prüfen.'); }
                file_put_contents($secretPath, $dbpass, LOCK_EX); chmod($secretPath, 0600); $data['sql']['password_file'] = $secretPath;
            }
            unset($dbpass);
            if ($mode === 'tcp') { $data['sql']['sslrootcert'] = ask('Absoluter Pfad zur PostgreSQL-CA-Datei (leer für libpq-Standard)'); }
        }
    }
    $stage = 'gewählte T2med-Konfiguration prüfen';
    $config = new Config($data); $client = new T2med($config, new RestClient($config, $username, $pass)); $client->preflight();
    unset($pass, $client, $t2med);
    $stage = 'TOML-Konfiguration speichern';
    if (file_put_contents($target, Toml::encode($data), LOCK_EX) === false) { throw new RuntimeException('Konfiguration konnte nicht gespeichert werden.'); }
    chmod($target, 0640);
    fwrite(STDERR, "\nServer, Leser, Wartebereiche und technischer Kontext wurden lesend geprüft.\nTOML gespeichert: " . $target . "\nDie T2med-Zugangsdaten wurden nicht gespeichert.\n");
} catch (Throwable $error) {
    fwrite(STDERR, Checkin\setupError($error, $stage)); exit(1);
}
