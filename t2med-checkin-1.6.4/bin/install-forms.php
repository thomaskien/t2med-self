#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
try {
    $config = new Checkin\Config($argv[1] ?? Checkin\configPath());
    $group = $argv[2] ?? 't2checkin';
    if (!preg_match('/^[a-z_][a-z0-9_-]*$/D', $group)) { throw new RuntimeException('Ungültige Dienstgruppe.'); }
    $directory = rtrim($config->get('questionnaires.forms_dir'), '/');
    // Reject broad roots and symlinked destinations; never alter their ownership recursively.
    if ($directory === '' || in_array($directory, ['/etc', '/var', '/var/lib', '/opt', '/srv', '/tmp', '/home', '/root'], true)
        || is_link($directory) || str_contains($directory, '/..') || str_contains($directory, '/./')) { throw new RuntimeException('Bitte ein eigenes Formularverzeichnis konfigurieren.'); }
    if (!is_dir($directory)) {
        if (!mkdir($directory, 0750, true) || !chgrp($directory, $group)) { throw new RuntimeException('Formularverzeichnis konnte nicht erstellt werden.'); }
    }
    foreach (['ana', 'act', 'cat', 'phq2', 'phq9', 'alka', 'fage', 'goeb', 'bart', 'datenschutz'] as $id) {
        // A site's priority-prefixed template or ana/anam alias wins over bundled defaults.
        $pattern = $id === 'ana' ? '(?:ana|anam)' : ($id === 'datenschutz' ? '(?:datenschutz|dsgv)' : $id);
        $exists = false;
        foreach (scandir($directory) as $name) {
            if (preg_match('/^(?:[0-9]+-)?' . $pattern . '\.yaml$/D', $name)) { $exists = true; break; }
        }
        if ($exists) { echo 'Vorhandenes Formular beibehalten: ' . $id . "\n"; continue; }
        $content = file_get_contents(dirname(__DIR__) . '/fragebogenpi/_yaml/' . $id . '.yaml');
        if ($content === false) { throw new RuntimeException('Mitgeliefertes Formular fehlt: ' . $id); }
        $target = $directory . '/' . $id . '.yaml';
        $temporary = tempnam($directory, '.form-');
        if ($temporary === false) { throw new RuntimeException('Formular konnte nicht vorbereitet werden: ' . $id); }
        $handle = fopen($temporary, 'wb');
        if (!$handle) { throw new RuntimeException('Formular konnte nicht angelegt werden: ' . $id); }
        try {
            if (fwrite($handle, $content) !== strlen($content) || !fflush($handle) || !fsync($handle)
                || !chmod($temporary, 0640) || !chgrp($temporary, $group)) { throw new RuntimeException('Formular konnte nicht vollständig bereitgestellt werden: ' . $id); }
            // Publish only a complete file; link refuses to overwrite a concurrently created target.
            if (!link($temporary, $target)) { throw new RuntimeException('Formularziel wurde zwischenzeitlich angelegt: ' . $id); }
        } finally { fclose($handle); if (is_file($temporary)) { unlink($temporary); } }
        echo 'Lokales Formular bereitgestellt: ' . $id . "\n";
    }
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
