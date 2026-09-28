<?php
declare(strict_types=1);

namespace Checkin;

const VERSION = '1.6.4';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, __NAMESPACE__ . '\\')) {
        $name = substr($class, strlen(__NAMESPACE__) + 1);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
            $path = __DIR__ . '/' . $name . '.php';
            if (is_file($path)) { require_once $path; }
        }
    }
});

function configPath(): string
{
    return getenv('T2MED_CHECKIN_CONFIG') ?: '/etc/t2med-checkin/config.toml';
}

/** CLI-only diagnostics: never include unexpected exception messages or stack arguments. */
function setupError(\Throwable $error, string $stage): string
{
    $expected = $error instanceof AppError || get_class($error) === \RuntimeException::class;
    $message = $expected ? $error->getMessage() : 'Interner PHP-Fehler.';
    $rest = $error instanceof AppError && $error->diagnostic !== []
        ? 'REST-Diagnose: ' . json_encode($error->diagnostic, JSON_UNESCAPED_SLASHES) . "\n" : '';
    return sprintf("Einrichtung angehalten: %s\nDiagnose: Version %s; Schritt: %s; %s in %s:%d.\n",
        $message, VERSION, $stage, get_class($error), basename($error->getFile()), $error->getLine()) . $rest;
}
