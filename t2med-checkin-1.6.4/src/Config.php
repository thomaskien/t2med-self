<?php
declare(strict_types=1);
namespace Checkin;

final class Config
{
    public readonly array $data;
    public function __construct(string|array $file, bool $requireContext = true)
    {
        $defaults = Toml::read(dirname(__DIR__) . '/config.example.toml');
        $provided = is_array($file) ? $file : Toml::read($file);
        self::checkKeys($provided, $defaults);
        $this->data = array_replace_recursive($defaults, $provided);
        $this->validate($requireContext);
    }
    public function get(string $path): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) { throw new AppError('CONFIG_KEY', 'Konfigurationsfeld fehlt: ' . $path, 503); }
            $value = $value[$key];
        }
        return $value;
    }
    public function category(string $key): array { return $this->get('categories.' . $key); }
    private static function checkKeys(array $provided, array $schema, string $path = ''): void
    {
        foreach ($provided as $key => $value) {
            if (!array_key_exists($key, $schema) || get_debug_type($value) !== get_debug_type($schema[$key])) {
                throw new AppError('CONFIG_TYPE', 'Ungültiges Konfigurationsfeld: ' . $path . $key, 503);
            }
            if (is_array($value)) { self::checkKeys($value, $schema[$key], $path . $key . '.'); }
        }
    }
    private function validate(bool $requireContext): void
    {
        foreach (['t2med.server', 'sql.host'] as $key) {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.:-]*$/D', $this->get($key))) { throw new AppError('CONFIG_HOST', 'Ungültiger Servername: ' . $key, 503); }
        }
        foreach (['t2med.rest_port', 't2med.cdn_port', 'sql.port', 'sql.ssh_port'] as $key) { $this->range($key, 1, 65535); }
        foreach (['t2med.doctor_role_id', 't2med.treatment_location_id'] as $key) {
            if ($requireContext && !preg_match('/^[a-fA-F0-9]{20,80}$/D', $this->get($key))) { throw new AppError('CONFIG_CONTEXT', 'Der technische Kontext muss eingerichtet werden.', 503); }
        }
        // Legacy values through 24 remain readable for migration. SessionStore always caps at 12.
        $this->range('app.session_hours', 1, 24); $this->range('app.patient_timeout_seconds', 60, 600);
        $this->range('app.completion_seconds', 5, 60); $this->range('t2med.timeout_seconds', 5, 60);
        $this->range('reader.read_attempts', 1, 5); $this->range('routing.note_max_length', 1, 250);
        $this->range('selfie.output_size', 400, 1200); $this->range('selfie.jpeg_quality_percent', 60, 95);
        $this->range('selfie.frame_height_percent', 40, 100);
        $this->range('questionnaires.timeout_seconds', 180, 3600);
        $this->range('privacy.timeout_seconds', 180, 3600);
        PrivacyForm::minimum($this->get('privacy.minimum_version'));
        foreach (['sms_pin_allowed', 'sms_pin_denied'] as $key) {
            $pin = $this->get('privacy.' . $key);
            if ($pin !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}\.png$/D', $pin)) {
                throw new AppError('CONFIG_SMS_PIN', 'SMS-Pin muss ein PNG-Dateiname ohne Pfad, Leerzeichen oder Umlaute sein.', 503);
            }
        }
        if ($this->get('privacy.sms_pin_allowed') !== '' && $this->get('privacy.sms_pin_allowed') === $this->get('privacy.sms_pin_denied')) {
            throw new AppError('CONFIG_SMS_PIN', 'Erlaubte und abgelehnte SMS benötigen unterschiedliche Pins.', 503);
        }
        if (trim($this->get('privacy.declined_message')) === '' || mb_strlen($this->get('privacy.declined_message')) > 1000) {
            throw new AppError('CONFIG_PRIVACY', 'Hinweis für nicht ausgefüllten Datenschutzbogen fehlt oder ist zu lang.', 503);
        }
        if (!in_array($this->get('selfie.preview_side'), ['left', 'right'], true)
            || !in_array($this->get('selfie.lens_arrow'), ['left', 'right', 'top', 'off'], true)) {
            throw new AppError('CONFIG_CAMERA', 'Ungültige Kameraposition oder Pfeilrichtung.', 503);
        }
        $allowed = self::formIds($this->get('questionnaires.allowed_forms'));
        foreach (['new_patient_forms', 'existing_patient_forms'] as $key) {
            if (array_diff(self::formIds($this->get('questionnaires.' . $key)), $allowed)) {
                throw new AppError('CONFIG_FORMS', 'Ein Startformular fehlt in allowed_forms.', 503);
            }
        }
        foreach (['contacts.note_code', 'questionnaires.entry_code', 'questionnaires.allergy_code'] as $key) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,15}$/D', $this->get($key))) { throw new AppError('CONFIG_CODE', 'Ungültiges Karteikartenkürzel.', 503); }
        }
        if (!in_array($this->get('sql.mode'), ['local', 'tcp', 'ssh'], true)) { throw new AppError('CONFIG_SQL', 'Ungültiger SQL-Modus.', 503); }
        if (!in_array($this->get('sql.sslmode'), ['require', 'verify-ca', 'verify-full'], true)) { throw new AppError('CONFIG_SQL_TLS', 'Ungültiger SQL-TLS-Modus.', 503); }
        if (!preg_match('/^[a-z_][a-z0-9_-]*$/Di', $this->get('sql.ssh_user'))) { throw new AppError('CONFIG_SSH', 'Ungültiger SSH-Benutzer.', 503); }
        foreach (['sql.database', 'sql.username'] as $key) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $this->get($key))) { throw new AppError('CONFIG_SQL_NAME', 'Ungültiger SQL-Name.', 503); }
        }
        foreach (['questionnaires.forms_dir','app.state_dir','app.secret_file','t2med.ca_file','sql.socket_directory','sql.password_file','sql.sslrootcert','sql.ssh_key','sql.ssh_known_hosts'] as $key) {
            $value = $this->get($key);
            if ($value !== '' && (!str_starts_with($value, '/') || preg_match('/[\x00-\x1f;\x27]/', $value))) { throw new AppError('CONFIG_PATH', 'Ungültiger absoluter Pfad: ' . $key, 503); }
        }
        foreach (['reader.name', 'routing.calendar_prefix', 'routing.waiting_prefix', 'questionnaires.forms_dir'] as $key) {
            if (trim($this->get($key)) === '') { throw new AppError('CONFIG_EMPTY', 'Konfigurationswert fehlt: ' . $key, 503); }
        }
        foreach ($this->get('categories') as $cat) {
            if (isset($cat['room']) && (trim($cat['room']) === '' || preg_match('/[\x00-\x1f]/', $cat['room']))) { throw new AppError('CONFIG_ROOM', 'Ungültiger Wartebereichname.', 503); }
        }
        $date = $this->get('card_presentation_date.target_date');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new AppError('CONFIG_DATE', 'Ungültiges Kartenvorlagedatum.', 503); }
        try { new \DateTimeZone($this->get('app.timezone')); }
        catch (\Exception) { throw new AppError('CONFIG_TIMEZONE', 'Ungültige Zeitzone.', 503); }
    }
    private function range(string $key, int $min, int $max): void
    {
        $value = $this->get($key);
        if ($value < $min || $value > $max) { throw new AppError('CONFIG_RANGE', 'Konfigurationswert außerhalb des Bereichs: ' . $key, 503); }
    }
    public static function formIds(string $text): array
    {
        if (trim($text) === '') { return []; }
        $ids = array_map('trim', explode(',', $text));
        foreach ($ids as $id) {
            if (!preg_match('/^[a-z][a-z0-9_-]{0,3}$/D', $id)) { throw new AppError('CONFIG_FORMS', 'Ungültige Formular-ID.', 503); }
        }
        return array_values(array_unique($ids));
    }
    public function publicData(): array
    {
        return ['version' => VERSION, 'title' => $this->get('app.title'), 'messages' => $this->get('messages'),
            'categories' => array_map(static fn(array $c): array => array_diff_key($c, ['room' => true]), $this->get('categories')),
            'selfie' => $this->get('selfie'), 'contacts' => array_diff_key($this->get('contacts'), ['note_code' => true]),
            'timeout' => $this->get('app.patient_timeout_seconds'),
            'completionSeconds' => $this->get('app.completion_seconds'), 'noteMaxLength' => $this->get('routing.note_max_length')];
    }
}
