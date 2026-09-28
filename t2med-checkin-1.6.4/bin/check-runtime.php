#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') { exit(1); }

// Offline only: use the supplied template, never a site's configuration or credentials.
$stage = 'lokale Programmprüfung';
try {
    $manifest = trim((string) file_get_contents(dirname(__DIR__) . '/VERSION'));
    $expected = $argv[1] ?? Checkin\VERSION;
    if ($manifest !== Checkin\VERSION || $expected !== Checkin\VERSION) {
        throw new RuntimeException('Installer, VERSION-Datei und PHP-Programmstand passen nicht zusammen. Bitte das vollständige Release verwenden.');
    }
    foreach (['AppError', 'Config', 'ConfigUpgrade', 'ContactData', 'ConsentCheck', 'Questionnaires', 'PrivacyForm', 'PrivacyPdf', 'WriteJournal', 'Flow', 'RestClient', 'SessionStore', 'SqlDate', 'T2med', 'Toml'] as $name) {
        if (!class_exists('Checkin\\' . $name)) {
            throw new RuntimeException('PHP-Klasse nicht ladbar: Checkin\\' . $name . '. Bitte das vollständige Release verwenden.');
        }
    }
    $config = new Checkin\Config(dirname(__DIR__) . '/config.example.toml', false);
    // Constructors do not make requests. Do not call discover(), preflight() or SQL here.
    new Checkin\T2med($config, new Checkin\RestClient($config, '', ''));
    foreach (['tablet-engine.php', 'tablet-checkin.php', 'tablet-checkin.js', 'tablet-checkin.css'] as $file) {
        if (!is_readable(dirname(__DIR__) . '/fragebogenpi/' . $file)) { throw new RuntimeException('Fragebogenkomponente fehlt: ' . $file); }
    }
    $webApp = json_decode((string) file_get_contents(dirname(__DIR__) . '/public/manifest.webmanifest'), true, 16, JSON_THROW_ON_ERROR);
    if (($webApp['display'] ?? null) !== 'standalone' || ($webApp['scope'] ?? null) !== './' || ($webApp['start_url'] ?? null) !== './') {
        throw new RuntimeException('Homescreen-Konfiguration unvollständig. Bitte das vollständige Release verwenden.');
    }
    echo 'Lokale Programmprüfung: Version ' . Checkin\VERSION . ", Klassen und Konfigurationsvorlage OK (ohne Serverzugriff).\n";
} catch (Throwable $error) {
    fwrite(STDERR, Checkin\setupError($error, $stage)); exit(1);
}
