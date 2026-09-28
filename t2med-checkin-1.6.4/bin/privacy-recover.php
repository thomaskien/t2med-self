#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use Checkin\{AppError, Config, RestClient, T2med, WriteJournal};

if (PHP_SAPI !== 'cli') { exit(1); }
if (($argv[1] ?? '') === '--help') {
    echo "Nur Datenschutz: --check KENNUNG (nur lesen) oder --complete KENNUNG (nach Mitarbeiterbestätigung).\n"
        . "Als Dienstbenutzer ausführen: sudo -u t2checkin php bin/privacy-recover.php --check KENNUNG\n"
        . "Nie ein PDF neu hochladen oder E-Mail-Einwilligung überschreiben; nur fehlende verwaltete Pins und Abschluss.\n";
    exit(0);
}
function askRecovery(string $label): string
{
    fwrite(STDERR, $label . ': '); $line = fgets(STDIN);
    if ($line === false) { throw new AppError('RECOVERY_CANCELLED', 'Eingabe abgebrochen.'); }
    return trim($line);
}
function recoveryPassword(): string
{
    $old = trim((string) shell_exec('stty -g'));
    if (!preg_match('/^[a-zA-Z0-9:;]+$/D', $old)) { throw new AppError('RECOVERY_TTY', 'Terminal für verdeckte Passworteingabe erforderlich.'); }
    fwrite(STDERR, 'T2med-Passwort (leer zulässig): '); exec('stty -echo');
    try {
        $line = fgets(STDIN);
        if ($line === false) { throw new AppError('RECOVERY_CANCELLED', 'Eingabe abgebrochen.'); }
        return rtrim($line, "\r\n");
    } finally { exec('stty ' . escapeshellarg($old)); fwrite(STDERR, "\n"); }
}
$audit = null; $id = ''; $finished = false;
try {
    $mode = $argv[1] ?? ''; $id = $argv[2] ?? '';
    if (count($argv) !== 3 || !in_array($mode, ['--check', '--complete'], true) || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
        throw new AppError('RECOVERY_USAGE', 'Aufruf: privacy-recover.php --check|--complete KENNUNG');
    }
    if (!stream_isatty(STDIN) || !stream_isatty(STDOUT) || !stream_isatty(STDERR)) {
        throw new AppError('RECOVERY_TTY', 'Nur im interaktiven Mitarbeiterterminal, nicht als Pipeline.');
    }
    $config = new Config(Checkin\configPath());
    $path = $config->get('app.state_dir') . '/pending/' . $id . '.json.enc';
    $data = WriteJournal::read($config, $id);
    $journal = null;
    if ($mode === '--complete') {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== fileowner($path)) {
            throw new AppError('RECOVERY_OWNER', 'Bitte als Eigentümer der Prüfkopie starten, gewöhnlich mit sudo -u t2checkin.');
        }
        if (time() - filemtime($path) < 300) { throw new AppError('RECOVERY_RECENT', 'Vorgang zuerst am Terminal mit Nächste Karte beenden; mindestens fünf Minuten seit letzter Änderung warten.'); }
        $journal = new WriteJournal($config, $id, null); $data = $journal->data();
    }
    fwrite(STDERR, "Lokale Patientendaten nur für berechtigte Mitarbeiter. Keine Zugangsdaten werden gespeichert.\n");
    $user = askRecovery('T2med-Mitarbeiter');
    $client = new T2med($config, new RestClient($config, $user, recoveryPassword()));
    $view = $client->inspectPrivacyRecovery($data);
    echo json_encode(['pruefkennung' => $id, 'patient' => $view['identity'], 'pdf_bestaetigt' => true,
        'email_einwilligung_bestaetigt' => true, 'email_erlaubt' => $view['email'], 'sms_erlaubt' => $view['sms'],
        'gespeicherter_pin_plan' => $view['pin'], 'pin_fehlt' => $view['pin_missing'],
        'alte_pins_im_plan' => count($view['old_rows']), 'alte_pruefkopie_ohne_stammdatenvergleich' => $view['legacy']],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    if ($mode === '--check') { echo "Nur gelesen. Kein Pin, PDF, Stammdatum oder Pending-Status geändert.\n"; exit(0); }
    fwrite(STDERR, "Prüfen Sie Patient, unterschriebenes PDF, aktuelle Einwilligung und Stammdaten in T2med.\n"
        . "Keine parallele Bearbeitung. Kein neuerer Widerruf darf vorliegen.\n"
        . "Dieser Abschluss setzt ggf. den fehlenden Pin und entfernt nur frühere, im Plan markierte Check-in-Pins.\n"
        . "PDF, E-Mail-Status und andere Stammdaten werden nicht geschrieben. Die geprüfte Pending-Datei wird danach entfernt.\n");
    if (askRecovery('Zum bestätigten Abschluss ABSCHLIESSEN ' . $id . ' eingeben') !== 'ABSCHLIESSEN ' . $id) {
        echo "Abgebrochen. Keine T2med-Änderung.\n"; exit(0);
    }
    $auditPath = $config->get('app.state_dir') . '/events.log';
    if (is_link($auditPath)) { throw new AppError('RECOVERY_AUDIT', 'Ungültiges Auditprotokoll.', 503); }
    $audit = fopen($auditPath, 'ab');
    $record = static function (string $event, string $code = '') use (&$audit, $id, $user): void {
        $line = json_encode(['time' => gmdate('c'), 'event' => $event, 'code' => $code, 'report_id' => $id,
            'actor_hash' => hash('sha256', $user)], JSON_THROW_ON_ERROR) . "\n";
        if (!$audit || !flock($audit, LOCK_EX)) { throw new AppError('RECOVERY_AUDIT', 'Auditprotokoll nicht beschreibbar.', 503); }
        try { if (fwrite($audit, $line) !== strlen($line) || !fflush($audit) || !fsync($audit)) { throw new AppError('RECOVERY_AUDIT', 'Auditprotokoll nicht bestätigt.', 503); } }
        finally { flock($audit, LOCK_UN); }
    };
    $record('privacy_recovery_started');
    $client->completePrivacyRecovery($journal); $finished = true;
    $record('privacy_recovery_completed');
    echo "Abschluss bestätigt. Nur die geprüfte Pending-Datei wurde entfernt; das PDF bleibt unverändert in T2med.\n"
        . "Am iPad Nächste Karte wählen und neu beginnen. Es wurde keine neue Anmeldung/Fotofrage automatisch gestartet.\n";
} catch (Throwable $error) {
    $code = $error instanceof AppError ? $error->tag : 'INTERNAL';
    if (isset($record) && !$finished) { try { $record('privacy_recovery_failed', $code); } catch (Throwable) {} }
    fwrite(STDERR, ($finished ? 'Abschluss erfolgt, aber Audit-Nachmeldung fehlgeschlagen: ' : 'Angehalten; kein automatischer Wiederholungsversuch: ') . $code . "\n");
    if ($error instanceof AppError) { fwrite(STDERR, $error->getMessage() . "\n" . json_encode($error->diagnostic, JSON_UNESCAPED_UNICODE) . "\n"); }
    exit(1);
} finally { if (is_resource($audit)) { fclose($audit); } }
