#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

// Focused regression checks only. No installation, requests, database access or file writes.
function check(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException('FEHLER: ' . $label); }
    echo 'OK: ' . $label . "\n";
}

try {
    check(Checkin\VERSION === '1.6.4'
        && trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')) === Checkin\VERSION,
        'Release-Version 1.6.4 stimmt überein');
    $root = dirname(__DIR__);
    check(str_contains(file_get_contents($root . '/install.sh'), "\nVERSION=" . Checkin\VERSION . "\n")
        && str_contains(file_get_contents($root . '/public/index.php'), 'Version ' . Checkin\VERSION)
        && str_contains(file_get_contents($root . '/public/index.php'), 'app.js?v=' . Checkin\VERSION . '"')
        && str_contains(file_get_contents($root . '/public/index.php'), 'app.css?v=' . Checkin\VERSION . '"')
        && str_contains(file_get_contents($root . '/fragebogenpi/tablet-checkin.php'), "'" . Checkin\VERSION . ' / fragebogenpi'),
        'Installer, Browser-Cache und alle Seiten melden denselben Patchstand');
    foreach (['AppError', 'Config', 'ConfigUpgrade', 'ContactData', 'Questionnaires', 'WriteJournal', 'Flow', 'RestClient', 'SessionStore', 'SqlDate', 'T2med', 'Toml'] as $name) {
        check(class_exists('Checkin\\' . $name), 'Klassenlader: ' . $name);
    }
    check(!class_exists('Checkin\\../T2med') && !class_exists('Checkin\\Sub\\T2med')
        && !class_exists('Checkin\\2med'), 'Ungültige und verschachtelte Klassennamen werden abgewiesen');

    $data = Checkin\Toml::read(dirname(__DIR__) . '/config.example.toml');
    $config = new Checkin\Config($data, false);
    $client = new Checkin\T2med($config, new Checkin\RestClient($config, 'offline', ''));
    check($client instanceof Checkin\T2med, 'REST-Client mit leerem Passwort lokal erzeugt');
    check($config->publicData()['version'] === '1.6.4', 'Öffentliche Konfiguration meldet Version 1.6.4');
    check($config->get('t2med.rest_port') === 16567 && $config->get('t2med.cdn_port') === 16570, 'Separate Standardports: REST 16567, CDN 16570');
    check(Checkin\Toml::parse(Checkin\Toml::encode($data)) === $data, 'TOML-Roundtrip');

    $stage = 'REST-Client vorbereiten';
    $sentinel = 'NICHT_AUSGEBEN_TESTPASSWORT';
    foreach ([new Error($sentinel), new TypeError($sentinel), new LogicException($sentinel)] as $error) {
        $diagnostic = Checkin\setupError($error, $stage);
        check(str_contains($diagnostic, 'Version 1.6.4') && str_contains($diagnostic, $stage)
            && str_contains($diagnostic, get_class($error)) && str_contains($diagnostic, 'smoke.php:')
            && !str_contains($diagnostic, $sentinel) && !str_contains($diagnostic, __DIR__),
            'Diagnose ohne internen Fehlertext oder absoluten Pfad: ' . get_class($error));
    }
    check(str_contains(Checkin\setupError(new RuntimeException('PHP-Klasse nicht ladbar: Checkin\\T2med.'), $stage),
        'PHP-Klasse nicht ladbar'), 'Erwartete Einrichtungsfehler bleiben verständlich');
    check(str_contains(Checkin\setupError(new Checkin\AppError('T2_AUTH', 'T2med-Anmeldung fehlgeschlagen.', 401), $stage),
        'T2med-Anmeldung fehlgeschlagen'), 'Bekannte Anwendungsfehler bleiben verständlich');
    echo "Offline-Regressionsprüfung erfolgreich.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n"); exit(1);
}
