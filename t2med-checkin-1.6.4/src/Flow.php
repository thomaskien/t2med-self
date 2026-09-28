<?php
declare(strict_types=1);
namespace Checkin;

final class Flow
{
    public function __construct(private Config $config, private SessionStore $session) {}

    public function state(): array
    {
        if (!$this->session->authenticated()) { return ['stage' => 'login']; }
        $flow = $_SESSION['flow'] ?? null;
        if ($flow === null) { return ['stage' => 'idle']; }
        if (!in_array($flow['stage'], ['reading', 'committing', 'contacts_saving', 'form_saving', 'privacy_saving', 'photo_pending', 'uploading', 'blocked', 'done'], true) && ($flow['last_activity'] ?? 0) + $this->timeout($flow['stage']) < time()) {
            $this->reset(); return ['stage' => 'idle'];
        }
        if (in_array($flow['stage'], ['reading', 'committing', 'contacts_saving', 'form_saving', 'privacy_saving', 'photo_pending', 'uploading'], true)) {
            $this->block('INTERRUPTED'); $flow = $_SESSION['flow'];
        }
        if (in_array($flow['stage'], ['selfie', 'camera'], true) && !($flow['checkin_complete'] ?? false)) {
            // A photo step opened with <= 1.2 has not yet committed the check-in. Never imply success.
            $this->block('FLOW_VERSION'); $flow = $_SESSION['flow'];
        }
        $state = array_intersect_key($flow, array_flip(['id', 'stage', 'category', 'initial_category', 'message', 'checkin_complete', 'error_code', 'error_diagnostic', 'error_area']));
        if ($flow['stage'] === 'contacts') { $state['contacts'] = ContactData::view($flow['contacts'], $flow['patient']['new']); }
        if ($flow['stage'] === 'questionnaire') { $state['questionnaireUrl'] = 'questionnaire.php'; }
        if ($flow['stage'] === 'privacy') { $state['privacyUrl'] = 'privacy.php'; }
        if ($flow['stage'] === 'done') {
            $state['completionRemaining'] = max(0, $this->config->get('app.completion_seconds') - max(0, time() - ($flow['done_at'] ?? $flow['last_activity'])));
        }
        return $state;
    }
    public function act(string $action, array $input, array $files): array
    {
        if ($action === 'login') {
            $this->session->login(self::text($input, 'username'), self::text($input, 'password'));
            $this->session->audit('login'); return $this->state();
        }
        if ($action === 'logout') {
            if (($input['confirmations'] ?? null) !== 3) { throw new AppError('INPUT', 'Bitte die Abmeldung vollständig bestätigen.'); }
            // A stale tab must not sign out a newer patient's flow. CSRF guards a newer staff login.
            if (!hash_equals($_SESSION['flow']['id'] ?? '', self::text($input, 'flowId'))) {
                throw new AppError('FLOW_ID', 'Dieser Vorgang ist nicht mehr aktuell.', 409);
            }
            if (isset($_SESSION['flow'])) { $this->nextPatient($this->session->client()); }
            $this->session->audit('logout'); $this->session->logout();
            return $this->state();
        }
        $client = $this->session->client();
        if ($action === 'next') {
            // Retrying a completed cleanup is harmless and never starts another card read.
            if (!isset($_SESSION['flow'])) { return $this->state(); }
            $this->matchFlow(self::text($input, 'flowId'));
            $this->nextPatient($client); return $this->state();
        }
        if ($action === 'begin') {
            if (isset($_SESSION['flow'])) { throw new AppError('FLOW_ACTIVE', 'Ein Vorgang ist bereits geöffnet.', 409); }
            $id = bin2hex(random_bytes(16)); $this->session->lease($id);
            $_SESSION['flow'] = ['id' => $id, 'stage' => 'reading', 'last_activity' => time(), 'card_session' => null];
            $this->session->checkpoint(); $this->session->audit('read_started');
            $client->preflight(); (new SqlDate($this->config))->check();
            $card = $client->readCard(function (?string $uuid): void { $_SESSION['flow']['card_session'] = $uuid; $this->session->checkpoint(); });
            $_SESSION['flow']['patient'] = $client->identify($card);
            (new SqlDate($this->config))->apply($card);
            if ($this->config->get('card_presentation_date.enabled')) { $this->session->audit('card_date_changed'); }
            $client->eject($_SESSION['flow']['card_session']); $_SESSION['flow']['card_session'] = null;
            $patient = $_SESSION['flow']['patient'];
            $plan = $client->calendarPlan($patient['ref']['objectId']['id']);
            $_SESSION['flow']['plan'] = $plan;
            $category = $patient['new'] ? 'new_patient' : ($plan !== [] ? ($patient['has_case'] ? 'waiting' : 'appointment_without_case') : ($patient['has_case'] ? 'with_case_fallback' : 'without_case'));
            $_SESSION['flow']['category'] = $_SESSION['flow']['initial_category'] = $category;
            $_SESSION['flow']['last_activity'] = time();
            $_SESSION['flow']['forms'] = (new Questionnaires($this->config))->start($patient['new']);
            $_SESSION['flow']['forms_seen'] = [];
            if ($this->config->get('contacts.enabled')) {
                $_SESSION['flow']['error_area'] = 'contacts';
                $_SESSION['flow']['contacts'] = $client->contactSnapshot($patient['ref']);
                $_SESSION['flow']['stage'] = 'contacts';
            } else { $this->afterContacts($client); }
            return $this->state();
        }
        $this->matchFlow(self::text($input, 'flowId'));
        if ($action === 'reset') { $this->reset(); return $this->state(); }
        $flow =& $_SESSION['flow'];
        if ($flow['stage'] === 'blocked') { throw new AppError('FLOW_BLOCKED', 'Dieser Vorgang wurde angehalten. Mit „Nächste Karte“ können Sie zum Start zurückkehren.', 409); }
        if (($flow['last_activity'] ?? 0) + $this->timeout($flow['stage']) < time() && $flow['stage'] !== 'done') { $this->reset(); throw new AppError('FLOW_EXPIRED', 'Der Vorgang ist abgelaufen. Bitte starten Sie das Kartenlesen erneut.', 409); }
        if (!($flow['checkin_complete'] ?? false)) { $this->session->lease($flow['id']); }
        $flow['last_activity'] = time();
        if (in_array($action, ['selfie', 'upload'], true) && !($flow['checkin_complete'] ?? false)) {
            throw new AppError('FLOW_VERSION', 'Dieser Fotovorgang stammt aus einer älteren Version. Bitte mit „Nächste Karte“ beenden.', 409);
        }
        if ($action === 'touch') {
            if (!in_array($flow['stage'], ['contacts', 'privacy', 'questionnaire', 'choice', 'note', 'selfie', 'camera'], true)) {
                if ($flow['stage'] === 'done') { $this->session->release($flow['id']); }
                throw new AppError('FLOW_STEP', 'Dieser Vorgang ist bereits abgeschlossen.', 409);
            }
            return $this->state();
        }
        if ($action === 'contacts' && $flow['stage'] === 'contacts') {
            $change = ContactData::changes($flow['contacts'], $input);
            $flow['stage'] = 'contacts_saving'; $this->session->checkpoint();
            $flow =& $_SESSION['flow'];
            $journal = new WriteJournal($this->config, bin2hex(random_bytes(16)), ['kind' => 'contacts', 'patient' => $flow['patient']['ref'], 'snapshot' => $flow['contacts'], 'change' => $change]);
            $client->saveContacts($flow['patient']['ref'], $flow['contacts'], $change, $journal); $journal->complete();
            unset($_SESSION['flow']['contacts']); $this->session->audit('contacts_confirmed'); $this->afterContacts($client);
        } elseif (in_array($action, ['privacy_submit', 'privacy_decline'], true) && $flow['stage'] === 'privacy') {
            $job = $flow['privacy'] ?? null;
            if (!is_array($job) || !hash_equals($job['token'], self::text($input, 'formToken'))) { throw new AppError('FORM_TOKEN', 'Dieser Datenschutzbogen ist nicht mehr aktuell.', 409); }
            if ($action === 'privacy_submit') {
                $form = (new PrivacyForm($this->config))->current($job);
                $answers = PrivacyForm::answers($input);
                $flow['stage'] = 'privacy_saving'; $flow['error_area'] = 'privacy'; $this->session->checkpoint();
                $flow =& $_SESSION['flow'];
                // Re-read before writing: another terminal/staff member may have stored a valid document.
                if ($client->privacySufficient($flow['patient']['ref'], $form['minimum'])) {
                    // Do not silently discard the newly signed, possibly different will.
                    throw new AppError('PRIVACY_CHANGED', 'Zwischenzeitlich wurde ein Datenschutzbogen hinterlegt. Bitte am Empfang abgleichen.', 409);
                }
                $identity = $client->privacyIdentity($flow['patient']['ref']);
                if ($identity['name'] !== $job['identity']['name'] || $identity['number'] !== $job['identity']['number']) {
                    throw new AppError('PRIVACY_PATIENT_CHANGED', 'Patientenangaben wurden geändert. Bitte am Empfang klären.', 409);
                }
                $plan = $client->preparePrivacyConsent($identity['ref'], $answers);
                $pdf = PrivacyPdf::create($this->config, $form, $identity, $answers, $job['token']);
                $text = PrivacyForm::recordText($form['version'], $job['token'], $form['hash'], hash('sha256', $pdf), $answers);
                if ($plan['pin'] !== '') { $text .= "\nSMS-Pin (Check-in): " . $plan['pin']; }
                $journal = new WriteJournal($this->config, $job['token'], ['kind' => 'privacy', 'patient' => $identity['ref'],
                    'version' => $form['version'], 'template_hash' => $form['hash'], 'record_text' => $text,
                    'pdf_base64' => base64_encode($pdf), 'consent' => $plan]);
                $row = $client->savePrivacyDocument($identity['ref'], $pdf, $text, $job['token']);
                $journal->confirmed('pdf_verified');
                $client->savePrivacyConsent($identity['ref'], $row, $plan, $journal);
                $journal->complete();
                unset($pdf);
                $this->session->audit('privacy_confirmed');
            } else {
                // No consent, PDF, or "completed" marker is written on decline.
                $flow['message'] .= "\n\n" . $this->config->get('privacy.declined_message');
                $this->session->audit('privacy_declined');
            }
            $_SESSION['flow']['privacy_checked'] = true;
            unset($_SESSION['flow']['privacy']);
            $this->afterForms($client);
        } elseif (in_array($action, ['form_submit', 'form_abort'], true) && $flow['stage'] === 'questionnaire') {
            $job = $flow['forms'][0] ?? null;
            if (!is_array($job) || !hash_equals($job['token'], self::text($input, 'formToken'))) { throw new AppError('FORM_TOKEN', 'Dieser Fragebogen ist nicht mehr aktuell.', 409); }
            if ($action === 'form_submit') {
                $result = (new Questionnaires($this->config))->result($job, $input);
                $flow['stage'] = 'form_saving'; $flow['error_area'] = 'questionnaire'; $this->session->checkpoint();
                $flow =& $_SESSION['flow'];
                $journal = new WriteJournal($this->config, $job['token'], ['kind' => 'questionnaire', 'patient' => $flow['patient']['ref'], 'form' => $job, 'result' => $result]);
                $client->writeText($flow['patient']['ref'], $this->config->get('questionnaires.entry_code'), $result['text'], 1); $journal->confirmed('ana');
                $client->saveMeasurements($flow['patient']['ref'], $result['height'], $result['weight']); $journal->confirmed('measurements');
                $client->saveAllergies($flow['patient']['ref'], $result['allergy'], $result['allergy_types']); $journal->confirmed('allergies');
                $journal->complete();
                $_SESSION['flow']['forms_seen'][$job['key']] = true;
                array_shift($_SESSION['flow']['forms']);
                foreach ($result['follow'] as $next) {
                    if (isset($_SESSION['flow']['forms_seen'][$next['key']]) || in_array($next['key'], array_column($_SESSION['flow']['forms'], 'key'), true)) { continue; }
                    $_SESSION['flow']['forms'][] = $next;
                }
                $this->session->audit('questionnaire_confirmed');
            } else {
                $_SESSION['flow']['forms'] = []; $this->session->audit('questionnaires_declined');
            }
            $this->afterForms($client);
        } elseif ($action === 'choose' && $flow['stage'] === 'choice') {
            $choice = self::text($input, 'choice');
            if (!in_array($choice, ['acute', 'card_only', 'other'], true)) { throw new AppError('CHOICE', 'Bitte ein Anliegen auswählen.'); }
            $flow['category'] = $choice;
            if ($choice === 'card_only') { $this->prepareSelfie($client); }
            else { $flow['stage'] = 'note'; }
        } elseif ($action === 'note' && $flow['stage'] === 'note') {
            $note = trim(self::text($input, 'note'));
            if (mb_strlen($note) > $this->config->get('routing.note_max_length') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $note)) { throw new AppError('NOTE', 'Bitte beachten Sie die maximale Textlänge und verwenden Sie keine Steuerzeichen.'); }
            $flow['note'] = $note; $this->prepareSelfie($client);
        } elseif ($action === 'selfie' && in_array($flow['stage'], ['selfie', 'camera'], true)) {
            $answer = self::text($input, 'answer');
            if ($answer === 'yes' && $flow['stage'] === 'selfie') { $flow['stage'] = 'camera'; }
            elseif ($answer === 'no') { $this->finish(); }
            else { throw new AppError('SELFIE_ANSWER', 'Bitte eine Antwort auswählen.'); }
        } elseif ($action === 'upload' && $flow['stage'] === 'camera') {
            $jpeg = $this->jpeg($files['image'] ?? []);
            $flow['stage'] = 'uploading'; $this->session->checkpoint();
            $flow =& $_SESSION['flow'];
            $client->uploadPhoto($flow['patient']['ref'], $jpeg); unset($jpeg);
            $this->session->audit('photo_saved'); $this->finish();
        } else { throw new AppError('FLOW_STEP', 'Dieser Schritt ist bereits abgeschlossen. Bitte laden Sie die Seite erneut.', 409); }
        if (isset($_SESSION['flow'])) { $_SESSION['flow']['last_activity'] = time(); }
        return $this->state();
    }
    private static function text(array $input, string $field): string
    {
        if (!is_string($input[$field] ?? null)) { throw new AppError('INPUT', 'Eine erforderliche Eingabe fehlt.'); }
        return $input[$field];
    }
    private function prepareSelfie(T2med $client): void
    {
        $this->commit($client);
        $this->afterForms($client);
    }
    private function timeout(string $stage): int
    {
        if ($stage === 'privacy') { return $this->config->get('privacy.timeout_seconds'); }
        return $this->config->get($stage === 'questionnaire' ? 'questionnaires.timeout_seconds' : 'app.patient_timeout_seconds');
    }
    private function afterContacts(T2med $client): void
    {
        unset($_SESSION['flow']['error_area']);
        if (in_array($_SESSION['flow']['category'], ['without_case', 'with_case_fallback'], true)) { $_SESSION['flow']['stage'] = 'choice'; }
        else { $this->prepareSelfie($client); }
    }
    private function afterForms(T2med $client): void
    {
        if (!($_SESSION['flow']['privacy_checked'] ?? false) && $this->config->get('privacy.enabled')) {
            $_SESSION['flow']['error_area'] = 'privacy';
            // This step must precede every configured medical form, also for existing patients.
            $form = (new PrivacyForm($this->config))->load();
            if (!$client->privacySufficient($_SESSION['flow']['patient']['ref'], $form['minimum'])) {
                $_SESSION['flow']['privacy'] = ['token' => bin2hex(random_bytes(16)), 'hash' => $form['hash'],
                    'version' => $form['version'], 'minimum' => $form['minimum'], 'pins' => $form['pins'],
                    'identity' => $client->privacyIdentity($_SESSION['flow']['patient']['ref'])];
                $_SESSION['flow']['stage'] = 'privacy'; return;
            }
            $_SESSION['flow']['privacy_checked'] = true;
        }
        if (($_SESSION['flow']['forms'] ?? []) !== []) {
            $_SESSION['flow']['stage'] = 'questionnaire'; $_SESSION['flow']['error_area'] = 'questionnaire'; return;
        }
        unset($_SESSION['flow']['forms'], $_SESSION['flow']['forms_seen']);
        $_SESSION['flow']['error_area'] = 'photo';
        $_SESSION['flow']['stage'] = 'photo_pending'; $this->session->checkpoint();
        if ($this->config->get('selfie.enabled') && $client->photoStatus($_SESSION['flow']['patient']['ref'])['ask']) { $_SESSION['flow']['stage'] = 'selfie'; }
        else { $this->finish(); }
    }
    private function commit(T2med $client): void
    {
        if ($_SESSION['flow']['checkin_complete'] ?? false) { return; }
        $flow =& $_SESSION['flow']; $flow['stage'] = 'committing'; $this->session->checkpoint();
        $flow =& $_SESSION['flow'];
        $patient = $flow['patient']['ref']['objectId']['id'];
        if ($client->calendarPlan($patient) !== $flow['plan']) { throw new AppError('ROUTING_CHANGED', 'Die Terminzuordnung wurde während der Anmeldung geändert.', 409); }
        $category = $this->config->category($flow['category']);
        $room = $category['room'] ?? null;
        if ($flow['category'] === 'appointment_without_case' && !$this->config->get('routing.add_appointment_without_case_queue')) { $room = null; }
        if ($room !== null) { $client->room($room); }
        foreach ($flow['plan'] as $item) { $client->move($item, $patient); }
        if ($room !== null) { $client->add($room, $patient, $flow['note'] ?? ''); }
        $flow['checkin_complete'] = true; $flow['message'] = $category['message'];
        $flow['stage'] = 'photo_pending';
        // Only the patient reference is still needed for the optional photo; no routing writes remain.
        $flow['patient'] = ['ref' => $flow['patient']['ref']];
        unset($flow['plan'], $flow['note']);
        $this->session->checkpoint();
        $this->session->audit('checkin_completed'); $this->session->release($_SESSION['flow']['id']);
    }
    private function finish(): void
    {
        $flow = $_SESSION['flow'];
        if (!($flow['checkin_complete'] ?? false)) { throw new AppError('CHECKIN_INCOMPLETE', 'Der Check-in ist noch nicht abgeschlossen.', 409); }
        $_SESSION['flow'] = ['id' => $flow['id'], 'stage' => 'done', 'category' => $flow['category'],
            'message' => $flow['message'], 'checkin_complete' => true, 'done_at' => time(), 'last_activity' => time()];
    }
    private function matchFlow(string $id): void
    {
        if (!isset($_SESSION['flow']['id']) || !hash_equals($_SESSION['flow']['id'], $id)) { throw new AppError('FLOW_ID', 'Dieser Vorgang ist nicht mehr aktuell.', 409); }
    }
    private function nextPatient(T2med $client): void
    {
        $flow = $_SESSION['flow'];
        if (!in_array($flow['stage'], ['blocked', 'done', 'contacts', 'privacy', 'questionnaire', 'choice', 'note', 'selfie', 'camera'], true)) {
            throw new AppError('FLOW_STEP', 'Der aktuelle Vorgang läuft noch. Bitte warten Sie auf die Rückmeldung.', 409);
        }
        if (($flow['card_session'] ?? null) !== null) {
            // Do not touch a reader already leased to another browser after a lease timeout.
            $this->session->lease($flow['id']);
            $client->eject($flow['card_session']);
            $_SESSION['flow']['card_session'] = null;
            $this->session->checkpoint();
        }
        $this->session->release($flow['id']);
        $this->session->audit($flow['stage'] === 'done' || ($flow['checkin_complete'] ?? false) ? 'patient_session_cleared' : 'checkin_abandoned', $flow['error_code'] ?? '');
        // Keep auth, auth_until and CSRF unchanged. No patient write is retried or rolled back.
        unset($_SESSION['flow']);
    }
    public function reset(): void
    {
        if (!isset($_SESSION['flow'])) { return; }
        if (in_array($_SESSION['flow']['stage'], ['blocked', 'reading', 'contacts_saving', 'form_saving', 'privacy_saving', 'committing', 'photo_pending', 'uploading'], true)) { throw new AppError('FLOW_BLOCKED', 'Der Vorgang wurde angehalten. Bitte wählen Sie „Nächste Karte“.', 409); }
        $this->session->client()->eject($_SESSION['flow']['card_session'] ?? null);
        $this->session->release($_SESSION['flow']['id']);
        $this->session->audit('patient_session_cleared'); unset($_SESSION['flow']);
    }
    public function block(string $code, array $diagnostic = []): void
    {
        if (!isset($_SESSION['flow']) || $_SESSION['flow']['stage'] === 'done') { return; }
        $token = $_SESSION['flow']['privacy']['token'] ?? '';
        if (!isset($diagnostic['report_id']) && preg_match('/^[a-f0-9]{32}$/D', $token)
            && is_file($this->config->get('app.state_dir') . '/pending/' . $token . '.json.enc')) {
            $diagnostic['report_id'] = $token;
        }
        $_SESSION['flow']['stage'] = 'blocked'; $_SESSION['flow']['error_code'] = $code;
        $_SESSION['flow']['error_diagnostic'] = $diagnostic;
        unset($_SESSION['flow']['note'], $_SESSION['flow']['contacts'], $_SESSION['flow']['forms'], $_SESSION['flow']['forms_seen'], $_SESSION['flow']['privacy']);
        if ($_SESSION['flow']['checkin_complete'] ?? false) { unset($_SESSION['flow']['patient'], $_SESSION['flow']['plan']); }
        $this->session->audit('checkin_blocked', $code);
    }
    private function jpeg(array $file): string
    {
        if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name']) || ($file['size'] ?? 0) > 3 * 1024 * 1024) { throw new AppError('PHOTO_UPLOAD', 'Das Foto konnte nicht empfangen werden.'); }
        $dimensions = @getimagesize($file['tmp_name']); $size = $this->config->get('selfie.output_size');
        if (!$dimensions || $dimensions[0] !== $size || $dimensions[1] !== $size || $dimensions[2] !== IMAGETYPE_JPEG) { throw new AppError('PHOTO_FORMAT', 'Bitte ein quadratisches Kamerafoto übermitteln.'); }
        $source = @imagecreatefromjpeg($file['tmp_name']);
        if ($source === false) { throw new AppError('PHOTO_DECODE', 'Das Foto konnte nicht gelesen werden.'); }
        ob_start(); imagejpeg($source, null, $this->config->get('selfie.jpeg_quality_percent')); $jpeg = ob_get_clean(); unset($source);
        if (!is_string($jpeg) || $jpeg === '') { throw new AppError('PHOTO_ENCODE', 'Das Foto konnte nicht verarbeitet werden.'); }
        return $jpeg;
    }
}
