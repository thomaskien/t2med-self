#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

// Local synthetic TOML only. No installer, actual configuration or network access.
use Checkin\{ConfigUpgrade, Toml};
function check(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException('FEHLER: ' . $label); }
    echo 'OK: ' . $label . "\n";
}
$directory = null; $target = null; $backup = null;
try {
    $original = "# Testdatei\r\n[t2med]\r\nrest_port = 16567\r\n  cdn_port = 16567 # bisheriger Port\r\ndoctor_role_id = 'aaaaaaaaaaaaaaaaaaaa'\r\ntreatment_location_id = 'bbbbbbbbbbbbbbbbbbbb'\r\n"
        . "[categories.with_case_fallback] # erhalten\r\n  room = 'Mit Schein unklar' # Kommentar bleibt\r\n"
        . "[categories.other]\r\nroom = \"Mit Schein unklar\"\r\nmessage = 'TEXT # bleibt unverändert'\r\n"
        . "[categories.card_only]\r\nlabel = \"Ich wollte nur meine Karte einlesen\"\r\nroom = 'Ohne Schein'\r\n";
    $updated = ConfigUpgrade::text($original); $parsed = Toml::parse($updated);
    $longSession = "[app]\r\n  session_hours = 24 # individuelle Frist\r\n" . $original;
    $capped = ConfigUpgrade::text($longSession);
    check(Toml::parse($capped)['app']['session_hours']===12
        && str_contains($capped,"  session_hours = 12 # individuelle Frist\r\n") && ConfigUpgrade::text($capped)===$capped,
        'Alte längere Anmeldung auf 12 Stunden begrenzt, Kommentar/CRLF erhalten und wiederholbar');
    $shortSession = "[app]\nsession_hours = 4\n" . $updated;
    check(ConfigUpgrade::text($shortSession)===$shortSession,'Kürzere Login-Frist bleibt unverändert');
    check($parsed['categories']['with_case_fallback']['room'] === 'fuer Empfang'
        && $parsed['categories']['other']['room'] === 'fuer Empfang'
        && $parsed['categories']['card_only']['label'] === 'Ich wollte nur kurz meine Karte einlesen lassen :-)', 'Drei alte Standardwerte gezielt ersetzt');
    check($parsed['categories']['card_only']['room'] === 'Ohne Schein'
        && str_contains($updated, "  room = \"fuer Empfang\" # Kommentar bleibt\r\n")
        && str_contains($updated, "message = 'TEXT # bleibt unverändert'\r\n"), 'Ohne Schein, Kommentare, Einrückung und CRLF bleiben unverändert');
    check(ConfigUpgrade::text($updated) === $updated, 'Erneute Migration verändert nichts');
    check($parsed['privacy']['sms_pin_allowed'] === 'SMS-erlaubt.png' && $parsed['privacy']['sms_pin_denied'] === 'SMS-nicht-erlaubt.png', 'SMS-Pin-Defaults werden ergänzt');
    $customPins = str_replace(['sms_pin_allowed = "SMS-erlaubt.png"', 'sms_pin_denied = "SMS-nicht-erlaubt.png"'],
        ['sms_pin_allowed = "Mein-Pin.png"', 'sms_pin_denied = ""'], $updated);
    check(ConfigUpgrade::text($customPins) === $customPins, 'Eigener Pin und deaktivierte Zuordnung bleiben bytegleich');
    $custom = str_replace(['Mit Schein unklar', 'Ich wollte nur meine Karte einlesen'], ['Eigener Empfang', 'Eigener Text'], $original);
    check(str_starts_with(ConfigUpgrade::text($custom), $custom), 'Individuelle Wartezimmer und Auswahltexte bleiben bytegleich; neue Abschnitte werden ergänzt');
    check($parsed['t2med']['cdn_port'] === 16567, 'Ohne Bestätigung bleibt ein expliziter alter CDN-Port erhalten');
    $corrected = ConfigUpgrade::text($original, true); $ports = Toml::parse($corrected)['t2med'];
    check($ports['rest_port'] === 16567 && $ports['cdn_port'] === 16570
        && str_contains($corrected, "  cdn_port = 16570 # bisheriger Port\r\n"), 'Bestätigte Korrektur ändert nur CDN-Port, nicht REST oder Kommentare');
    $customPorts = str_replace(['rest_port = 16567', 'cdn_port = 16567'], ['rest_port = 18443', 'cdn_port = 17443'], $custom);
    check(str_starts_with(ConfigUpgrade::text($customPorts, true), $customPorts), 'Eigene Ports bleiben auch bei aktivierter Korrektur bytegleich');
    $missingPort = str_replace("  cdn_port = 16567 # bisheriger Port\r\n", '', $custom);
    check(str_starts_with(ConfigUpgrade::text($missingPort, true), $missingPort)
        && (new Checkin\Config(Toml::parse($missingPort)))->get('t2med.cdn_port') === 16570, 'Fehlender CDN-Wert nutzt neuen Standard, ohne TOML umzuschreiben');
    $directory = sys_get_temp_dir() . '/checkin-config-14-' . bin2hex(random_bytes(12));
    if (!mkdir($directory, 0700)) { throw new RuntimeException('Temporäres Verzeichnis fehlt.'); }
    $target = $directory . '/config.toml';
    file_put_contents($target, $original); chmod($target, 0640);
    $backup = ConfigUpgrade::file($target, true);
    check($backup !== null && str_contains($backup, '.pre-1.6.4-') && file_get_contents($backup) === $original
        && file_get_contents($target) === $corrected, 'Original mit Versionskennung gesichert, Port und Standardwerte atomar übernommen');
    check((fileperms($backup) & 0777) === 0600 && (fileperms($target) & 0777) === 0640, 'Private Sicherung und ursprüngliche Konfigurationsrechte');
    check(ConfigUpgrade::file($target, true) === null, 'Fortsetzung erzeugt keine zweite Sicherung');
    $custom15 = ConfigUpgrade::text($custom);
    file_put_contents($target, $custom15);
    check(ConfigUpgrade::file($target, false) === null && file_get_contents($target) === $custom15, 'Abgelehnte Portkorrektur lässt die aktualisierte Datei unverändert');
    $previous15 = str_replace('Ergänzt mit Version 1.6.4;', 'Ergänzt mit Version 1.5;', $custom15);
    file_put_contents($target, $previous15);
    check(ConfigUpgrade::file($target, false) === null && file_get_contents($target) === $previous15,
        'Vollständig ergänzte TOML bleibt einschließlich alter Versionskommentare bytegleich');
    $previous151 = str_replace('Ergänzt mit Version 1.6.4;', 'Ergänzt mit Version 1.5.1;', $custom15);
    file_put_contents($target, $previous151);
    check(ConfigUpgrade::file($target, false) === null && file_get_contents($target) === $previous151,
        'Ergänzte TOML mit 1.5.1-Kommentar bleibt bytegleich');
    $previous156 = preg_replace('/^sms_pin_(allowed|denied) = .*\n/m', '', file_get_contents(dirname(__DIR__) . '/config.example.toml'));
    $next157 = Toml::parse(ConfigUpgrade::text($previous156));
    check($next157['privacy']['sms_pin_allowed']==='SMS-erlaubt.png' && $next157['privacy']['sms_pin_denied']==='SMS-nicht-erlaubt.png', '1.5.6-Konfiguration erhält zwei neue Pin-Werte');
    unset($next157['privacy']['sms_pin_allowed'],$next157['privacy']['sms_pin_denied']);
    check($next157===Toml::parse($previous156),'Alle bisherigen 1.5.6-Konfigurationswerte unverändert');
    $old152 = file_get_contents(dirname(__DIR__) . '/config.example.toml');
    $old152 = preg_replace('/^\[privacy\]\n.*?(?=^\[|\z)/ms', '', $old152);
    $oldParsed = Toml::parse($old152); $added = Toml::parse(ConfigUpgrade::text($old152));
    check($added['privacy']['enabled'] && $added['privacy']['minimum_version'] === '1.3.4', 'Alte TOML ohne Datenschutz bekommt Mindestversion und aktivierten Schritt');
    unset($added['privacy']); check($added === $oldParsed, 'Alle bisherigen Werte einschließlich technischem Kontext bleiben erhalten');
    $customPrivacy = "[privacy]\nenabled = false\nminimum_version = '1.5' # individuell\ntimeout_seconds = 1200\n";
    $preserved = Toml::parse(ConfigUpgrade::text($customPrivacy));
    check(!$preserved['privacy']['enabled'] && $preserved['privacy']['minimum_version'] === '1.5' && $preserved['privacy']['timeout_seconds'] === 1200,
        'Eigene Datenschutz-Aktivierung, Mindestversion und Zeitlimit werden nicht überschrieben');
    check($parsed['selfie']['frame_height_percent'] === 100 && $parsed['questionnaires']['new_patient_forms'] === ''
        && $parsed['questionnaires']['existing_patient_forms'] === '', 'Neue Kamera- und Fragebogenstandards ohne Startformulare ergänzt');
    $previousDefaults = Toml::read(dirname(__DIR__) . '/config.example.toml');
    $previousDefaults['t2med']['verify_tls'] = true;
    $previousDefaults['reader']['name'] = 'Eigener Leser';
    $previousDefaults['questionnaires']['new_patient_forms'] = 'ana';
    $previousDefaults['card_presentation_date']['enabled'] = false;
    $previousDefaults['sql']['mode'] = 'ssh';
    $previousText = Toml::encode($previousDefaults);
    check(ConfigUpgrade::text($previousText) === $previousText, 'Bestehende TLS-, Leser-, Fragebogen- und SQL-Werte werden vom neuen Praxisstandard nicht überschrieben');
    $own = "[selfie]\npreview_side = 'left' # bleibt\nframe_height_percent = 70\n[contacts]\nenabled = false\n";
    $ownUpdated = ConfigUpgrade::text($own); $ownParsed = Toml::parse($ownUpdated);
    check($ownParsed['selfie']['preview_side'] === 'left' && $ownParsed['selfie']['frame_height_percent'] === 70 && !$ownParsed['contacts']['enabled']
        && str_contains($ownUpdated, "preview_side = 'left' # bleibt"), 'Vorhandene neue Optionen werden nicht überschrieben');
    echo "Offline-Konfigurationsprüfung erfolgreich.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); $failed = true; }
finally {
    foreach ([$backup, $target] as $file) { if ($file !== null && is_file($file)) { unlink($file); } }
    if ($directory !== null) { rmdir($directory); }
}
exit(isset($failed) ? 1 : 0);
