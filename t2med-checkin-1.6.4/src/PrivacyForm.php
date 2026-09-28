<?php
declare(strict_types=1);
namespace Checkin;

/** Only the explicit Datenschutz schema, never arbitrary YAML handlers. */
final class PrivacyForm
{
    public function __construct(private Config $config) {}

    public static function version(string $text): string
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,3})\.(0|[1-9][0-9]{0,3})(?:\.(0|[1-9][0-9]{0,3}))?$/D', $text)) {
            throw new AppError('PRIVACY_VERSION', 'Datenschutzversion muss numerisch sein, z.B. 1.5 oder 1.5.1.', 503);
        }
        return $text;
    }
    public static function minimum(string $version): string
    {
        // The setting itself already means "at least"; no comparison operator needed.
        return self::version(trim($version));
    }
    public static function sufficient(string $version, string $minimum): bool
    {
        self::version($version); self::version($minimum);
        return version_compare(count(explode('.', $version)) === 2 ? $version . '.0' : $version,
            count(explode('.', $minimum)) === 2 ? $minimum . '.0' : $minimum, '>=');
    }
    public function load(): array
    {
        if (!function_exists('yaml_parse')) { throw new AppError('FORM_YAML', 'PHP-YAML ist nicht installiert.', 503); }
        $dir = realpath($this->config->get('questionnaires.forms_dir'));
        $files = [];
        if ($dir !== false) {
            foreach (scandir($dir) as $name) {
                if (preg_match('/^(?:[0-9]+-)?(?:datenschutz|dsgv)\.yaml$/D', $name)) { $files[] = $dir . '/' . $name; }
            }
        }
        if (count($files) !== 1 || is_link($files[0]) || !is_file($files[0]) || filesize($files[0]) > 128 * 1024) {
            throw new AppError('PRIVACY_TEMPLATE', 'Eine eindeutige reguläre datenschutz.yaml wird benötigt.', 503);
        }
        $source = file_get_contents($files[0]);
        if ($source === false) { throw new AppError('PRIVACY_TEMPLATE', 'Datenschutzvorlage nicht lesbar.', 503); }
        ini_set('yaml.decode_php', '0');
        $yaml = @yaml_parse($source);
        if (!is_array($yaml) || ($yaml['meta']['handler'] ?? '') !== 'datenschutz.php'
            || !is_array($yaml['document']['sections'] ?? null) || !array_is_list($yaml['document']['sections'])
            || count($yaml['document']['sections']) < 1 || count($yaml['document']['sections']) > 60
            || ($yaml['consent']['optional'] ?? null) !== true) {
            throw new AppError('PRIVACY_TEMPLATE', 'Datenschutzvorlage hat ein nicht unterstütztes Format.', 503);
        }
        foreach (['title', 'version'] as $key) { self::text($yaml['meta'][$key] ?? null, 200); }
        self::text($yaml['meta']['warning_notice'] ?? '', 1000, true);
        self::text($yaml['document']['heading'] ?? null, 300);
        self::text($yaml['document']['intro'] ?? '', 12000, true);
        foreach ($yaml['document']['sections'] as $section) {
            self::text($section['title'] ?? null, 300); self::text($section['text'] ?? null, 16000);
        }
        foreach (['checkbox_label', 'pdf_label', 'sms_checkbox_label', 'sms_pdf_label'] as $key) { self::text($yaml['consent'][$key] ?? null, 400); }
        $version = self::version($yaml['meta']['version']);
        $minimum = self::minimum($this->config->get('privacy.minimum_version'));
        if (!self::sufficient($version, $minimum)) {
            throw new AppError('PRIVACY_TEMPLATE_OLD', 'Datenschutzvorlage ist älter als die konfigurierte Mindestversion. Bitte zuerst die Vorlage aktualisieren.', 503);
        }
        return ['yaml' => $yaml, 'version' => $version, 'minimum' => $minimum, 'hash' => hash('sha256', $source),
            'pins' => [$this->config->get('privacy.sms_pin_allowed'), $this->config->get('privacy.sms_pin_denied')]];
    }
    private static function text(mixed $value, int $max, bool $empty = false): void
    {
        if (!is_string($value) || (!$empty && trim($value) === '') || mb_strlen($value) > $max
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) {
            throw new AppError('PRIVACY_TEMPLATE', 'Datenschutzvorlage enthält ungültigen Text.', 503);
        }
    }
    public function current(array $job): array
    {
        $form = $this->load();
        if (($job['hash'] ?? '') !== $form['hash'] || ($job['version'] ?? '') !== $form['version']
            || ($job['minimum'] ?? '') !== $form['minimum'] || ($job['pins'] ?? null) !== $form['pins']) {
            throw new AppError('PRIVACY_CHANGED', 'Datenschutzvorlage, Mindestversion oder Pin-Zuordnung wurde geändert. Bitte neu beginnen.', 409);
        }
        return $form;
    }
    public static function answers(array $input): array
    {
        foreach (['email', 'sms'] as $key) {
            if (!is_bool($input[$key] ?? null)) { throw new AppError('PRIVACY_INPUT', 'Bitte die optionalen Einwilligungen prüfen.'); }
        }
        $strokes = $input['strokes'] ?? null; $count = 0; $distance = 0.0;
        if (!is_array($strokes) || !array_is_list($strokes) || count($strokes) > 100) { throw new AppError('PRIVACY_INPUT', 'Bitte im Unterschriftsfeld unterschreiben.'); }
        foreach ($strokes as $stroke) {
            if (!is_array($stroke) || !array_is_list($stroke) || count($stroke) < 1) { throw new AppError('PRIVACY_INPUT', 'Ungültige Unterschrift.'); }
            $prev = null;
            foreach ($stroke as $point) {
                if (++$count > 6000 || !is_array($point) || !array_is_list($point) || count($point) !== 2) { throw new AppError('PRIVACY_INPUT', 'Unterschrift zu umfangreich. Bitte neu unterschreiben.'); }
                foreach ($point as $v) { if ((!is_int($v) && !is_float($v)) || !is_finite((float) $v) || $v < 0 || $v > 1) { throw new AppError('PRIVACY_INPUT', 'Ungültige Unterschrift.'); } }
                if ($prev !== null) { $distance += hypot($point[0] - $prev[0], ($point[1] - $prev[1]) / 2); }
                $prev = $point;
            }
        }
        if ($count < 5 || $distance < 0.05) { throw new AppError('PRIVACY_INPUT', 'Bitte im Unterschriftsfeld unterschreiben.'); }
        return ['email' => $input['email'], 'sms' => $input['sms'], 'strokes' => $strokes];
    }
    public static function recordText(string $version, string $token, string $templateHash, string $pdfHash, array $answers): string
    {
        self::version($version);
        return "Datenschutz | Formularversion: $version\nCheck-in-Dokument: $token\nVorlagen-SHA256: $templateHash\nPDF-SHA256: $pdfHash\n"
            . 'Kenntnisnahme mit Unterschrift. E-Mail-Einwilligung: ' . ($answers['email'] ? 'JA' : 'NEIN')
            . '; SMS-Einwilligung: ' . ($answers['sms'] ? 'JA' : 'NEIN');
    }
    public static function recordVersion(string $text): ?string
    {
        if (!preg_match('/\ADatenschutz \| Formularversion: ([0-9.]+)(?:\r?\n|\z)/', $text, $m)) { return null; }
        try { return self::version($m[1]); } catch (AppError) { return null; }
    }
}
