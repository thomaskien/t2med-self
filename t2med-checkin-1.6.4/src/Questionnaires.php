<?php
declare(strict_types=1);
namespace Checkin;

final class Questionnaires
{
    public function __construct(private Config $config)
    {
        require_once dirname(__DIR__) . '/fragebogenpi/tablet-engine.php';
    }
    public function form(string $id): array
    {
        if (!in_array($id, Config::formIds($this->config->get('questionnaires.allowed_forms')), true)) {
            throw new AppError('FORM_DISABLED', 'Dieses Formular ist nicht freigegeben.', 503);
        }
        if (!function_exists('yaml_parse_file')) { throw new AppError('FORM_YAML', 'PHP-YAML ist nicht installiert.', 503); }
        // Disable PHP-object deserialization in libyaml, including during alias resolution.
        ini_set('yaml.decode_php', '0');
        $directory = $this->config->get('questionnaires.forms_dir');
        foreach ((array) @scandir($directory) as $name) {
            if (!is_string($name) || !preg_match('/^(?:[0-9]+-)?[a-z][a-z0-9_-]*\.yaml$/D', $name)) { continue; }
            $candidate = $directory . '/' . $name;
            if (is_link($candidate) || !is_file($candidate) || filesize($candidate) > 512 * 1024) {
                throw new AppError('FORM_PATH', 'Formularverzeichnis enthält einen ungültigen Eintrag.', 503);
            }
        }
        $info = \form_yaml_for_id($this->config->get('questionnaires.forms_dir'), $id, 4);
        if (isset($info['error'])) { throw new AppError('FORM_MISSING', 'Ein konfiguriertes Formular fehlt oder ist mehrdeutig.', 503); }
        $root = realpath($this->config->get('questionnaires.forms_dir')); $path = realpath($info['path']);
        if ($root === false || $path === false || dirname($path) !== $root || is_link($info['path']) || filesize($path) > 512 * 1024) {
            throw new AppError('FORM_PATH', 'Ungültige Formulardatei.', 503);
        }
        $yaml = \yaml_load_or_die_ascii($path);
        if (isset($yaml['__error']) || !is_array($yaml['sections'] ?? null) || !empty($yaml['meta']['handler'])) {
            throw new AppError('FORM_CONFIG', 'Das Formular ist kein unterstützter YAML-Fragebogen. Spezialhandler sind hier nicht freigegeben.', 503);
        }
        return ['id' => $id, 'key' => basename($path), 'hash' => hash_file('sha256', $path), 'yaml' => $yaml, 'priority' => $info['priority']];
    }
    public function start(bool $new): array
    {
        if (!$this->config->get('questionnaires.enabled')) { return []; }
        $jobs = []; $seen = [];
        foreach (Config::formIds($this->config->get('questionnaires.' . ($new ? 'new_patient_forms' : 'existing_patient_forms'))) as $id) {
            $form = $this->form($id);
            if (isset($seen[$form['key']])) { continue; }
            $seen[$form['key']] = true; $jobs[] = $this->job($form);
        }
        return $jobs;
    }
    private function job(array $form): array
    {
        return ['id' => $form['id'], 'key' => $form['key'], 'hash' => $form['hash'], 'token' => bin2hex(random_bytes(16))];
    }
    public function current(array $job): array
    {
        $form = $this->form($job['id']);
        if ($job['key'] !== $form['key'] || !hash_equals($job['hash'], $form['hash'])) {
            throw new AppError('FORM_CHANGED', 'Das Formular wurde zwischenzeitlich geändert. Bitte am Empfang klären.', 409);
        }
        return $form;
    }
    private static function value(mixed $v): string
    {
        if (!is_string($v) || mb_strlen($v) > 600 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $v)) { throw new AppError('FORM_INPUT', 'Bitte gültige Antworten mit höchstens 600 Zeichen eingeben.'); }
        return trim($v);
    }
    private static function measurement(mixed $value, float $maximum): ?float
    {
        $text = self::value($value);
        if ($text === '') { return null; }
        if (!preg_match('/^[0-9]{1,4}(?:[.,][0-9]{1,2})?$/D', $text)) { throw new AppError('FORM_INPUT', 'Größe und Gewicht bitte als Zahl in cm bzw. kg eingeben.'); }
        $number = (float) str_replace(',', '.', $text);
        if ($number <= 0 || $number > $maximum) { throw new AppError('FORM_INPUT', 'Bitte Größe und Gewicht sowie die Einheiten cm und kg prüfen.'); }
        return $number;
    }
    public function result(array $job, array $input): array
    {
        $form = $this->current($job); $yaml = $form['yaml']; $raw = $input['q'] ?? [];
        if (!is_array($raw)) { throw new AppError('FORM_INPUT', 'Ungültige Formularantworten.'); }
        $answers = [];
        foreach ($yaml['sections'] as $section) {
            foreach ($section['questions'] ?? [] as $q) {
                $id = $q['id'] ?? ''; $type = $q['type'] ?? '';
                if ($id === '' || in_array($type, ['header', 'derived'], true)) { continue; }
                $value = $raw[$id] ?? '';
                if (($section['type'] ?? '') === 'checklist') {
                    if (!in_array($value, ['', '1'], true)) { throw new AppError('FORM_INPUT', 'Ungültige Auswahl.'); }
                    $answers[$id] = $value === '1';
                } elseif ($type === 'multiselect') {
                    if ($value === '') { $value = []; }
                    if (!is_array($value) || !array_is_list($value)) { throw new AppError('FORM_INPUT', 'Ungültige Mehrfachauswahl.'); }
                    foreach ($value as $v) {
                        if (!is_string($v) || !in_array($v, $q['options'] ?? [], true)) { throw new AppError('FORM_INPUT', 'Ungültige Mehrfachauswahl.'); }
                    }
                    $answers[$id] = array_values(array_unique($value));
                } else { $answers[$id] = self::value($value); }
            }
        }
        // Discard hidden answers before validation, scoring, follow-ups or clinical output.
        foreach ($yaml['sections'] as $section) {
            foreach ($section['questions'] ?? [] as $q) {
                $id = $q['id'] ?? '';
                if ((isset($section['show_if']) && !\cond_ok($answers, $section['show_if']))
                    || (isset($q['show_if']) && !\cond_ok($answers, $q['show_if']))) { unset($answers[$id]); }
            }
        }
        $errors = \validate_yaml_answers($yaml, $answers);
        if ($errors !== []) { throw new AppError('FORM_INPUT', implode(' ', $errors)); }
        foreach ($yaml['sections'] as $section) {
            foreach ($section['questions'] ?? [] as $q) {
                $value = $answers[$q['id'] ?? ''] ?? '';
                if (($q['type'] ?? '') === 'number' && $value !== '' && (!is_string($value) || !preg_match('/^[0-9]+(?:[.,][0-9]+)?$/D', $value))) {
                    throw new AppError('FORM_INPUT', 'Bitte Zahlenfelder als Zahl oder leer übermitteln.');
                }
            }
        }
        $allergies = $answers['allergie_typen'] ?? [];
        if (!is_array($allergies)) { throw new AppError('FORM_CONFIG', 'Ungültige Allergieauswahl in der Vorlage.', 503); }
        if (in_array('Keine Allergie bekannt', $allergies, true) && count($allergies) > 1) {
            throw new AppError('FORM_INPUT', 'Bitte „Keine Allergie bekannt“ nicht zusammen mit einer Allergie auswählen.');
        }
        $cigs = \parse_float_de((string) ($answers['rauchen_zigaretten_tag'] ?? ''));
        $years = \parse_float_de((string) ($answers['rauchen_jahre'] ?? ''));
        $answers['_packyears_text'] = ($answers['raucher'] ?? '') === 'yes' && $cigs !== null && $years !== null && $cigs > 0 && $years > 0
            ? 'mind. ' . max(1, (int) floor($cigs / 20 * $years)) . ' Packyears' : '';
        \derive_yaml_answers($yaml, $answers);
        $measurementsVisible = ($yaml['ui']['show_contact_section'] ?? true) !== false;
        $height = $measurementsVisible ? self::measurement($input['height_cm'] ?? '', 350) : null;
        $weight = $measurementsVisible ? self::measurement($input['weight_kg'] ?? '', 1500) : null;
        $title = $yaml['meta']['title'] ?? $job['id'];
        $lines = [$title, 'Patienten-Selbstauskunft am Check-in-Terminal; ' . date('d.m.Y H:i'), 'Formular: ' . $job['key'], ''];
        if ($height !== null) { $lines[] = 'Körpergröße: ' . $height . ' cm (Patientenangabe)'; }
        if ($weight !== null) { $lines[] = 'Körpergewicht: ' . $weight . ' kg (Patientenangabe)'; }
        // Use exactly the regular fragebogenpi report selection, including template
        // output flags and scores. Do not prepend a second, exhaustive field dump:
        // it generated empty headings, "no answer" placeholders and duplicate findings.
        // Only strip transport framing; APS still receives plain text, never a GDT file.
        $summary = \build_6228_blocks($yaml, $answers, 250);
        if ($summary !== []) {
            if (end($lines) !== '') { $lines[] = ''; }
            foreach ($summary as $line) { $lines[] = rtrim(substr($line, 7), "\r\n"); }
        }
        $allergyText = '';
        $positive = array_values(array_diff($allergies, ['Keine Allergie bekannt']));
        if ($positive !== []) {
            $allergyText = "Patienten-Selbstauskunft am Terminal (ärztlich nicht bestätigt):\n" . implode(', ', $positive);
            if (($answers['allergie_details'] ?? '') !== '') { $allergyText .= "\nGenaueres / Reaktionen: " . $answers['allergie_details']; }
            foreach (['allergieausweis' => 'Allergieausweis vorhanden', 'allergischer_schock' => 'Früherer allergischer Schock'] as $id => $label) {
                if (in_array($answers[$id] ?? '', ['yes', 'no'], true)) { $allergyText .= "\n" . $label . ': ' . ($answers[$id] === 'yes' ? 'Ja' : 'Nein'); }
            }
        }
        $followYaml = $yaml;
        $rules = $yaml['follow_up_forms'] ?? [];
        if (!is_array($rules)) { throw new AppError('FORM_CONFIG', 'Ungültige Folgeformular-Konfiguration.', 503); }
        // Filter intentionally disabled forms BEFORE upstream resolves their files.
        $allowed = Config::formIds($this->config->get('questionnaires.allowed_forms'));
        $followYaml['follow_up_forms'] = array_values(array_filter($rules, static fn($rule) => !is_array($rule) || in_array($rule['form'] ?? '', $allowed, true)));
        $plan = \follow_up_forms_for_answers($followYaml, $answers, $job['id'], $this->config->get('questionnaires.forms_dir'), 4);
        if ($plan['errors'] !== []) { throw new AppError('FORM_CONFIG', 'Die Folgeformular-Konfiguration ist unvollständig.', 503); }
        $follow = [];
        foreach ($plan['forms'] as $p) {
            // A deliberately disabled follow-up is skipped, never launched through an arbitrary URL.
            if (!in_array($p['form_id'], Config::formIds($this->config->get('questionnaires.allowed_forms')), true)) { continue; }
            $follow[] = $this->job($this->form($p['form_id']));
        }
        return ['text' => rtrim(implode("\n", $lines)), 'height' => $height, 'weight' => $weight, 'allergy' => $allergyText, 'allergy_types' => $positive, 'follow' => $follow];
    }
}
