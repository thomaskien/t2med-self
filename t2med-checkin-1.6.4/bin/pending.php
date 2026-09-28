#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
try {
    $config = new Checkin\Config(Checkin\configPath());
    $directory = $config->get('app.state_dir') . '/pending';
    if (($argv[1] ?? '') === '--show' && count($argv) === 3 && preg_match('/^[a-f0-9]{32}$/D', $argv[2])) {
        if (!stream_isatty(STDOUT)) { throw new RuntimeException('Patientendaten werden nur in einem interaktiven Terminal angezeigt, nicht in eine Datei/Pipeline geschrieben.'); }
        $path = $directory . '/' . $argv[2] . '.json.enc';
        if (is_link($path) || !is_file($path)) { throw new RuntimeException('Prüfkopie fehlt.'); }
        $blob = file_get_contents($path); $key = file_get_contents($config->get('app.secret_file'));
        if ($blob === false || strlen($blob) < 32 || substr($blob, 0, 3) !== 'CI1' || strlen($key) !== 32) { throw new RuntimeException('Prüfkopie oder Schlüssel ist ungültig.'); }
        $plain = openssl_decrypt(substr($blob, 31), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 3, 12), substr($blob, 15, 16), 'checkin-recovery-1');
        if ($plain === false) { throw new RuntimeException('Prüfkopie konnte nicht entschlüsselt werden.'); }
        fwrite(STDERR, "ACHTUNG: Patientendaten. Nur für berechtigte Mitarbeiter; keine automatische Wiederholung.\n");
        echo json_encode(json_decode($plain, true, 128, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    } elseif (count($argv) === 1) {
        echo "Prüfkopien: offene Übertragungen und REST-Ablehnungen (keine Patientendaten):\n";
        foreach (scandir($directory) as $name) {
            if (preg_match('/^([a-f0-9]{32})\.json\.enc$/D', $name, $match)) { echo $match[1], "\n"; }
        }
        echo "Details lokal: sudo php bin/pending.php --show KENNUNG\nKeine Daten werden gesendet, geändert oder gelöscht.\n";
    } else { throw new RuntimeException('Aufruf: php bin/pending.php [--show KENNUNG]'); }
} catch (Throwable $error) { fwrite(STDERR, "Prüfkopie nicht verfügbar. Bitte Konfiguration, Rechte und Kennung prüfen.\n"); exit(1); }
