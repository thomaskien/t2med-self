#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
try {
    $config = new Checkin\Config($argv[1] ?? Checkin\configPath());
    foreach (['curl','gd','mbstring','openssl','session','json'] as $extension) {
        if (!extension_loaded($extension)) { throw new RuntimeException('PHP-Erweiterung fehlt: ' . $extension); }
    }
    foreach (['sessions','requests','uploads','pending'] as $name) {
        $directory = $config->get('app.state_dir') . '/' . $name;
        if (!is_dir($directory) || !is_writable($directory)) { throw new RuntimeException('Verzeichnis nicht beschreibbar: ' . $directory); }
    }
    if (strlen((string) @file_get_contents($config->get('app.secret_file'))) !== 32) { throw new RuntimeException('Anwendungsschlüssel fehlt.'); }
    if ($config->get('questionnaires.enabled')) {
        $forms = new Checkin\Questionnaires($config);
        foreach (Checkin\Config::formIds($config->get('questionnaires.allowed_forms')) as $id) { $forms->form($id); }
    }
    if ($config->get('privacy.enabled')) {
        $form = (new Checkin\PrivacyForm($config))->load();
        Checkin\PrivacyPdf::dependencies();
        if (trim($form['yaml']['meta']['warning_notice'] ?? '') !== '') {
            fwrite(STDERR, "Datenschutzvorlage enthält einen Hinweisbanner. Praxisangaben und Text vor Produktivbetrieb prüfen; der Banner bleibt am iPad und im PDF sichtbar.\n");
        }
    }
    if ($config->get('card_presentation_date.enabled')) { (new Checkin\SqlDate($config))->check(); }
    echo "Konfiguration, lokale Voraussetzungen und ggf. SQL-Schema: OK (keine Patientendaten geändert).\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
