<?php
declare(strict_types=1);
namespace Checkin;

/** Migrate old defaults and the explicit 12-hour login cap; preserve other custom settings. */
final class ConfigUpgrade
{
    public static function text(string $original, bool $correctCdnPort = false): string
    {
        $provided = Toml::parse($original);
        $changes = [
            'categories.with_case_fallback' => ['room', 'Mit Schein unklar', 'fuer Empfang'],
            'categories.other' => ['room', 'Mit Schein unklar', 'fuer Empfang'],
            'categories.card_only' => ['label', 'Ich wollte nur meine Karte einlesen', 'Ich wollte nur kurz meine Karte einlesen lassen :-)'],
        ];
        $section = ''; $expected = $provided;
        // Retain whitespace, line endings and comments, including quoted # characters.
        $lines = preg_split('/(\r\n|\n|\r)/', $original, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($lines as &$line) {
            if (preg_match('/^\s*\[([A-Za-z0-9_.-]+)\]\s*(?:#.*)?$/D', $line, $m)) { $section = $m[1]; }
            if ($section === 'app' && is_int($provided['app']['session_hours'] ?? null) && $provided['app']['session_hours'] > 12
                && preg_match('/^(\s*session_hours\s*=\s*)[0-9]+(\s*(?:#.*)?)$/D', $line, $m)) {
                $line = $m[1] . '12' . $m[2]; $expected['app']['session_hours'] = 12;
            }
            // An explicitly configured 16567 may also be a deliberate proxy port.
            // Only replace it after the installer's separate confirmation; never touch REST.
            if ($correctCdnPort && $section === 't2med' && ($provided['t2med']['cdn_port'] ?? null) === 16567
                && preg_match('/^(\s*cdn_port\s*=\s*)16567(\s*(?:#.*)?)$/D', $line, $m)) {
                $line = $m[1] . '16570' . $m[2];
                $expected['t2med']['cdn_port'] = 16570;
            }
            if (!isset($changes[$section])) { continue; }
            [$key, $old, $new] = $changes[$section];
            [, $category] = explode('.', $section);
            if (($provided['categories'][$category][$key] ?? null) !== $old) { continue; }
            $pattern = '/^(\s*' . $key . '\s*=\s*)("(?:[^"\\\\]|\\\\.)*"|\x27[^\x27]*\x27)(\s*(?:#.*)?)$/D';
            if (preg_match($pattern, $line, $m)) {
                $line = $m[1] . json_encode($new, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . $m[3];
                $expected['categories'][$category][$key] = $new;
            }
        }
        unset($line);
        $updated = implode('', $lines);
        if (Toml::parse($updated) !== $expected) { throw new \RuntimeException('Die Konfigurationsmigration konnte nicht geprüft werden.'); }
        return self::add15($updated);
    }

    private static function add15(string $original): string
    {
        $provided = Toml::parse($original);
        $defaults = Toml::read(dirname(__DIR__) . '/config.example.toml');
        $additions = ['selfie' => array_intersect_key($defaults['selfie'], array_flip(['preview_side', 'frame_height_percent', 'lens_arrow', 'capture_text'])),
            'contacts' => $defaults['contacts'], 'questionnaires' => $defaults['questionnaires'], 'privacy' => $defaults['privacy']];
        $newline = str_contains($original, "\r\n") ? "\r\n" : "\n";
        $updated = $original; $expected = $provided;
        foreach ($additions as $section => $values) {
            $missing = array_diff_key($values, $provided[$section] ?? []);
            if ($missing === []) { continue; }
            $block = '# Ergänzt mit Version ' . VERSION . '; vorhandene Werte bleiben erhalten.' . $newline;
            foreach ($missing as $key => $value) {
                $block .= $key . ' = ' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . $newline;
                $expected[$section][$key] = $value;
            }
            $pattern = '/(^[\t ]*\[' . $section . '\][\t ]*(?:#[^\r\n]*)?)(\r\n|\n|\r|$)/m';
            if (preg_match($pattern, $updated)) {
                $updated = preg_replace_callback($pattern, static fn($m) => $m[1] . $newline . $block, $updated, 1);
            } else { $updated .= $newline . '[' . $section . ']' . $newline . $block; }
        }
        if (Toml::parse($updated) != $expected) { throw new \RuntimeException('Neue Konfigurationsfelder konnten nicht geprüft werden.'); }
        return $updated;
    }

    /** Returns the backup path if anything changed; repeated runs are harmless. */
    public static function file(string $target, bool $correctCdnPort = false): ?string
    {
        if (is_link($target) || !is_file($target)) { throw new \RuntimeException('Konfiguration muss eine reguläre Datei sein.'); }
        $original = file_get_contents($target);
        if ($original === false) { throw new \RuntimeException('Konfiguration konnte nicht gelesen werden.'); }
        new Config(Toml::parse($original));
        $updated = self::text($original, $correctCdnPort);
        if ($updated === $original) { return null; }
        new Config(Toml::parse($updated));
        $stat = stat($target);
        $backup = $target . '.pre-' . VERSION . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.bak';
        $saved = fopen($backup, 'xb');
        if ($saved === false) { throw new \RuntimeException('Konfigurationssicherung konnte nicht angelegt werden.'); }
        try {
            if (!chmod($backup, 0600) || fwrite($saved, $original) !== strlen($original) || !fflush($saved)) {
                throw new \RuntimeException('Konfigurationssicherung ist fehlgeschlagen; Original bleibt unverändert.');
            }
        } finally { fclose($saved); }
        $temporary = tempnam(dirname($target), '.config-' . VERSION . '-');
        if ($temporary === false) { throw new \RuntimeException('Konfigurationsmigration konnte nicht vorbereitet werden.'); }
        try {
            if (file_put_contents($temporary, $updated, LOCK_EX) !== strlen($updated)
                || !chown($temporary, $stat['uid']) || !chgrp($temporary, $stat['gid'])
                || !chmod($temporary, $stat['mode'] & 0777)) {
                throw new \RuntimeException('Konfigurationsmigration konnte nicht gespeichert werden.');
            }
            if (is_link($target) || file_get_contents($target) !== $original) {
                throw new \RuntimeException('Konfiguration wurde zwischenzeitlich geändert; bitte erneut starten.');
            }
            if (!rename($temporary, $target)) { throw new \RuntimeException('Konfiguration konnte nicht ersetzt werden.'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
        return $backup;
    }
}
