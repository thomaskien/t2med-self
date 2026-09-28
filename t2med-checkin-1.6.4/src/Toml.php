<?php
declare(strict_types=1);
namespace Checkin;

/** Strict configuration dialect. Unsupported syntax fails rather than changing meaning. */
final class Toml
{
    public static function read(string $file): array
    {
        $text = @file_get_contents($file);
        if ($text === false) { throw new AppError('CONFIG_MISSING', 'Die Konfiguration fehlt.', 503); }
        return self::parse($text);
    }

    public static function parse(string $text): array
    {
        $out = []; $path = []; $tables = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) as $n => $raw) {
            $line = trim(self::stripComment($raw));
            if ($line === '') { continue; }
            if (preg_match('/^\[([A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*)\]$/D', $line, $m)) {
                if (isset($tables[$m[1]])) { self::fail($n); }
                $tables[$m[1]] = true; $path = explode('.', $m[1]);
                $node =& $out;
                foreach ($path as $part) {
                    if (array_key_exists($part, $node) && !is_array($node[$part])) { self::fail($n); }
                    $node[$part] ??= []; $node =& $node[$part];
                }
                unset($node);
                continue;
            }
            if (!preg_match('/^([A-Za-z0-9_-]+)\s*=\s*(.+)$/D', $line, $m)) { self::fail($n); }
            $value = trim($m[2]);
            if ($value === 'true' || $value === 'false') { $parsed = $value === 'true'; }
            elseif (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value)) {
                $parsed = filter_var($value, FILTER_VALIDATE_INT);
                if ($parsed === false) { self::fail($n); }
            } elseif (preg_match('/^\x27([^\x27\x00-\x1f]*)\x27$/uD', $value, $literal)) {
                $parsed = $literal[1];
            } elseif (str_starts_with($value, '"')) {
                try { $parsed = json_decode($value, true, 8, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { self::fail($n); }
                if (!is_string($parsed)) { self::fail($n); }
            } else { self::fail($n); }
            $node =& $out;
            foreach ($path as $part) { $node =& $node[$part]; }
            if (array_key_exists($m[1], $node)) { self::fail($n); }
            $node[$m[1]] = $parsed; unset($node);
        }
        return $out;
    }

    private static function stripComment(string $line): string
    {
        $quote = ''; $escaped = false;
        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            $c = $line[$i];
            if ($escaped) { $escaped = false; continue; }
            if ($quote === '"' && $c === '\\') { $escaped = true; continue; }
            if ($quote !== '') { if ($c === $quote) { $quote = ''; } continue; }
            if ($c === '"' || $c === "'") { $quote = $c; }
            elseif ($c === '#') { return substr($line, 0, $i); }
        }
        return $line;
    }

    private static function fail(int $line): never
    {
        throw new AppError('CONFIG_TOML', 'Ungültige oder nicht unterstützte TOML-Syntax in Zeile ' . ($line + 1) . '.', 503);
    }

    public static function encode(array $data): string
    {
        $out = '# T2med Check-in ' . VERSION . ": Konfiguration\n";
        $walk = static function (array $node, string $section) use (&$walk, &$out): void {
            if ($section !== '') { $out .= "\n[" . $section . "]\n"; }
            foreach ($node as $key => $value) {
                if (!is_array($value)) {
                    $out .= $key . ' = ' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
                }
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) { $walk($value, $section === '' ? $key : $section . '.' . $key); }
            }
        };
        $walk($data, ''); return $out;
    }
}
