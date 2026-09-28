#!/usr/bin/env php
<?php
declare(strict_types=1);
namespace Checkin {
    // No network: even the real Flow/SessionStore client terminates in this in-process double.
    function curl_init(string $url): object { return (object) ['url' => $url]; }
    function curl_setopt_array(object $h, array $options): bool { $h->options = $options; return true; }
    function curl_exec(object $h): bool {
        $path = substr($h->url, strpos($h->url, '/aps/rest') + 9);
        $result = $GLOBALS['fake']->request($h->options[CURLOPT_CUSTOMREQUEST], $path,
            isset($h->options[CURLOPT_POSTFIELDS]) ? json_decode($h->options[CURLOPT_POSTFIELDS], true) : null);
        ($h->options[CURLOPT_WRITEFUNCTION])($h, json_encode($result, JSON_THROW_ON_ERROR)); return true;
    }
    function curl_getinfo(object $h, int $option): mixed { return $option === CURLINFO_RESPONSE_CODE ? 200 : 'application/json'; }
    function curl_errno(object $h): int { return 0; }
}
namespace {
require dirname(__DIR__) . '/src/bootstrap.php';
$nativeYaml = function_exists('yaml_parse_file');
if (!$nativeYaml) {
    $fixtures = json_decode(stream_get_contents(STDIN), true, 128, JSON_THROW_ON_ERROR);
    if (!is_array($fixtures) || !isset($fixtures['ana.yaml'])) { throw new RuntimeException('YAML-Testdaten fehlen: ruby tests/forms-data.rb | php tests/features15.php'); }
    function yaml_parse_file(string $path): mixed { return $GLOBALS['fixtures'][basename($path)] ?? false; }
}
$checks = []; $temp = null;
function check(bool $condition, string $label): void { if (!$condition) { throw new RuntimeException('FEHLER: ' . $label); } $GLOBALS['checks'][] = $label; }
function fails(string $tag, callable $fn): void {
    try { $fn(); } catch (Checkin\AppError $e) { check($e->tag === $tag, $tag); return; }
    throw new RuntimeException('Fehlender Fehler: ' . $tag);
}
function ref(string $id): array { return ['objectId' => ['id' => str_repeat($id, 32)], 'revision' => 0]; }
class FixtureRest extends Checkin\RestClient {
    public array $calls = [], $texts = [], $textRows = [], $measurements = [], $structured = [], $unstructured = [], $cases = [];
    public array $details, $measurement = [];
    public bool $failMeasure = false, $wrongTextPatient = false, $wrongTextContent = false, $missingTextRef = false;
    public bool $missingMeasurement = false, $duplicateMeasurement = false, $wrongMeasurement = false;
    private int $counter = 100;
    private function nextRef(): array { return ['objectId' => ['id' => str_pad(dechex(++$this->counter), 32, '0', STR_PAD_LEFT)], 'revision' => 0]; }
    public function request(string $method, string $path, mixed $body = null, bool $requireSuccess = true): array {
        $this->calls[] = [$method, $path, $body]; $data = [];
        if (str_contains($path, 'arztrollenbehandlungorte')) $data = ['arztrollen' => [['ref' => ref('a')]], 'behandlungsorte' => [['ref' => ref('b')]]];
        elseif (str_ends_with($path, '/find/details')) $data = ['details' => $this->details];
        elseif (str_ends_with($path, '/updateTelefonnummer')) $this->details['kontaktdatenDTO']['telefonnummern'] = $body['telefonnummerTOList'];
        elseif (str_ends_with($path, '/updateEmailadressen')) $this->details['kontaktdatenDTO']['emailAdressen'] = $body['adresseTOList'];
        elseif (str_contains($path, '/text/create?')) {
            parse_str(parse_url($path, PHP_URL_QUERY), $q); $code = $q['kuerzel'];
            $data = ['texteintragTO' => ['ref' => ['objectId' => null, 'revision' => 0],
                'kuerzel' => $code, 'fachinformationstypTO' => ['fachinformationstyp' => $code === 'ana' ? 1 : ($code === 'all' ? 26 : 99)]]];
        } elseif (str_ends_with($path, '/text/insert')) {
            $entry = $body['texteintrag']; check($entry['ref']['objectId'] === null, 'Texteintrag behält native leere Referenz bis insert');
            $entry['ref'] = $this->nextRef(); $this->texts[$entry['ref']['objectId']['id']] = $entry;
            $rowRef = $this->nextRef(); $this->textRows[$rowRef['objectId']['id']] = ['patient' => $body['kontext']['patientRef'], 'textRef' => $entry['ref']];
            $data = ['karteieintragZeileDTO' => ['ref' => $rowRef]];
            if ($entry['kuerzel'] === 'all') $this->unstructured[] = ['ref' => $entry['ref'], 'beschreibung' => $entry['decoratedString']['text']];
        } elseif (preg_match('#/karteikarte/patient/([a-f0-9]+)/byids$#', $path, $m)) {
            $row = $this->textRows[$body[0]['id']] ?? null;
            if ($this->wrongTextPatient || ($row['patient']['objectId']['id'] ?? null) !== $m[1]) return [];
            return [['ref' => ['objectId' => $body[0], 'revision' => 0]]];
        } elseif (str_ends_with($path, '/dokumentationbearbeitenvorgang')) {
            $data = ['fachinformationRef' => $this->missingTextRef ? null : $this->textRows[$body['objectId']['id']]['textRef']];
        } elseif (preg_match('#/text/([a-f0-9]+)/read$#', $path, $m)) {
            $data = ['texteintragTO' => $this->texts[$m[1]]];
            if ($this->wrongTextContent) $data['texteintragTO']['decoratedString']['text'] = 'DIFFERENT';
        } elseif (str_ends_with($path, '/koerpermass/erzeugen')) $data = ['koerpermassTO' => ['ref' => ['objectId' => null, 'revision' => 0]], 'fachinformationstypTO' => ['fachinformationstyp' => 17]];
        elseif (str_ends_with($path, '/koerpermass/anlegen')) {
            if ($this->failMeasure) throw new Checkin\AppError('TEST_LOST_WRITE', 'Synthetic lost response', 502);
            check($body['koerpermassTO']['ref']['objectId'] === null, 'Körpermaße behalten native leere Referenz bis anlegen');
            $data = ['koerpermassTO' => $body['koerpermassTO'], 'fachinformationstypTO' => $body['fachinformationstypTO']];
            $this->measurement = $body['koerpermassTO'];
            $this->measurement['ref'] = $this->nextRef();
            if (!$this->missingMeasurement) $this->measurements[] = ['koerpermassTO' => $this->measurement];
            if ($this->duplicateMeasurement) { $duplicate = $this->measurement; $duplicate['ref'] = $this->nextRef(); $this->measurements[] = ['koerpermassTO' => $duplicate]; }
        } elseif (str_ends_with($path, '/koerpermass/allekoerpermasseintraegeholen')) $data = ['koerpermassListe' => $this->measurements];
        elseif (str_ends_with($path, '/koerpermass/holen')) {
            check($body['koerpermassRef'] === $this->measurement['ref'], 'Rücklesen nutzt die serverseitig vergebene Körpermaß-ID');
            $data = ['koerpermassTO' => $this->measurement];
            if ($this->wrongMeasurement) $data['koerpermassTO']['gewichtInKG'] = 999;
        }
        elseif (str_ends_with($path, '/faellefuerpatient')) $data = ['zeilenMaps' => ['Aktuell' => $this->cases]];
        elseif (str_ends_with($path, '/loadstructured')) $data = ['allergien' => $this->structured];
        elseif (str_ends_with($path, '/loadunstructured')) $data = ['allergien' => $this->unstructured];
        elseif (str_ends_with($path, '/allergien/verwalten/save')) {
            $saved = $body['allergie']; $saved['ref'] = ref('e'); $this->structured[] = $saved; $data = ['gespeicherteAllergie' => $saved];
        } else throw new RuntimeException('Unexpected synthetic endpoint: ' . $path);
        return ['successful' => true] + $data;
    }
}
try {
    $temp = sys_get_temp_dir() . '/checkin15-features-' . bin2hex(random_bytes(10)); mkdir($temp, 0700); mkdir($temp . '/pending', 0700);
    $key = random_bytes(32); file_put_contents($temp . '/key', $key); chmod($temp . '/key', 0600);
    $data = Checkin\Toml::read(dirname(__DIR__) . '/config.example.toml');
    $defaults = new Checkin\Questionnaires(new Checkin\Config($data));
    check($defaults->start(true) === [] && $defaults->start(false) === [], 'Praxisstandard startet trotz aktiver Fragebogenfunktion keine medizinischen Formulare');
    $data['questionnaires']['new_patient_forms'] = 'ana'; // Exercise the optional medical feature explicitly.
    $data['privacy']['enabled'] = false; // Existing medical-flow regression; privacy has dedicated tests.
    $data['app']['state_dir'] = $temp; $data['app']['secret_file'] = $temp . '/key';
    $data['t2med']['doctor_role_id'] = str_repeat('a', 32); $data['t2med']['treatment_location_id'] = str_repeat('b', 32);
    $data['questionnaires']['forms_dir'] = dirname(__DIR__) . '/fragebogenpi/_yaml'; $data['selfie']['enabled'] = false;
    $config = new Checkin\Config($data); $forms = new Checkin\Questionnaires($config);
    foreach (Checkin\Config::formIds($data['questionnaires']['allowed_forms']) as $id) $forms->form($id);
    $job = $forms->start(true)[0]; check($job['key'] === 'ana.yaml' && $forms->start(false) === [], 'Neupatient ana, Bestand zunächst ohne Startformular');
    check($forms->form('anam')['key'] === 'ana.yaml', 'ana/anam verwenden dieselbe Vorlage');
    $empty = $forms->result($job, []);
    check($empty['height'] === null && $empty['weight'] === null && $empty['allergy'] === '' && $empty['follow'] === [], 'Leere freiwillige Antworten ergeben keine erfundenen Messungen, Allergien oder Folgeformulare');
    check(count(explode("\n", trim($empty['text']))) === 3 && !str_contains($empty['text'], '(keine Angabe)'),
        'Leerer Anamnesebogen enthält nur Herkunftskopf, keine leeren Rubriken oder erfundenen Negativangaben');
    $example = $forms->result($job, ['height_cm' => '187', 'weight_kg' => '87', 'q' => ['allergie_typen' => ['Chemikalien']]]);
    $exampleLines = explode("\n", $example['text']); $exampleLines[1] = 'DATUM';
    check(implode("\n", $exampleLines) === "Anamnese\nDATUM\nFormular: ana.yaml\n\nKörpergröße: 187 cm (Patientenangabe)\nKörpergewicht: 87 kg (Patientenangabe)\n\n---\nAllergien\n========\n- Chemikalien",
        'Gemeldetes Beispiel: nur Herkunft, Größe, Gewicht und einmal Chemikalien');
    check($example['height'] === 187.0 && $example['weight'] === 87.0 && $example['allergy_types'] === ['Chemikalien'] && str_contains($example['allergy'], 'Chemikalien'),
        'Kompakte Anzeige erhält separate Körpermaß- und Allergiedaten');
    $allergy = $forms->result($job, ['height_cm' => '180', 'weight_kg' => '82,5', 'q' => ['asthma' => '1', 'allergie_typen' => ['Pollen'], 'allergie_details' => 'Juckreiz äöü <b>test</b>']]);
    check($allergy['height'] === 180.0 && $allergy['weight'] === 82.5 && str_contains($allergy['text'], 'Juckreiz aeoeue <b>test</b>')
        && str_contains($allergy['allergy'], 'Juckreiz äöü <b>test</b>'), 'Fragebogentext nutzt normale fragebogenpi-Umlautumschreibung; separate Messwerte und Allergie-Freitext bleiben erhalten');
    $nativeAnswers = ['asthma' => true, 'allergie_typen' => ['Pollen'], 'allergie_details' => 'Juckreiz äöü <b>test</b>'];
    $nativeOutput = implode("\n", array_map(static fn($line) => rtrim(substr($line, 7), "\r\n"), build_6228_blocks($forms->current($job)['yaml'], $nativeAnswers, 250)));
    check(str_ends_with($allergy['text'], $nativeOutput) && substr_count($allergy['text'], 'Juckreiz') === 1
        && !str_contains($allergy['text'], 'Auswertung gemäß Formularvorlage:'), 'Bericht verwendet genau einmal den normalen fragebogenpi-Inhalt statt zusätzlicher Vollfeldliste');
    $filled = $forms->result($job, ['q' => ['gerinnungsstoerung' => 'yes', 'blutverduenner' => 'no', 'haeufige_probleme_freitext' => 'Schwindel seit gestern']]);
    check(str_contains($filled['text'], 'Blutgerinnungsstoerung') && str_contains($filled['text'], 'Schwindel seit gestern')
        && !str_contains($filled['text'], 'Blutverduenner') && !str_contains($filled['text'], '(keine Angabe)'),
        'Positive Auswahl und ausgefüllter Freitext bleiben, Nein/Leerwerte werden nach normaler Vorlage nicht aufgelistet');
    check(array_column($allergy['follow'], 'id') === ['act'], 'Asthma-Antwort startet ACT');
    $hidden = $forms->result($job, ['q' => ['allergie_typen' => ['Keine Allergie bekannt'], 'allergie_details' => 'HIDDEN_SENTINEL']]);
    check(!str_contains($hidden['text'], 'HIDDEN_SENTINEL') && $hidden['allergy'] === '', 'Verborgene Antworten werden nicht gespeichert');
    check(str_contains($hidden['text'], 'Keine Allergie bekannt'), 'Ausdrücklich angekreuztes Keine Allergie bekannt bleibt sichtbar');
    fails('FORM_INPUT', fn() => $forms->result($job, ['q' => ['allergie_typen' => ['Keine Allergie bekannt', 'Pollen']]]));
    fails('FORM_INPUT', fn() => $forms->result($job, ['height_cm' => '1e3']));
    fails('FORM_INPUT', fn() => $forms->result($allergy['follow'][0], []));
    $badJob = $job; $badJob['hash'] = str_repeat('0', 64); fails('FORM_CHANGED', fn() => $forms->current($badJob));
    $restricted = $data; $restricted['questionnaires']['allowed_forms'] = 'ana';
    check((new Checkin\Questionnaires(new Checkin\Config($restricted)))->result($job, ['q' => ['asthma' => '1']])['follow'] === [], 'TOML deaktiviert Folgeformulare vor Auflösung');
    $act = $forms->current($allergy['follow'][0]); $answers = [];
    foreach ($act['yaml']['sections'] as $section) foreach ($section['questions'] ?? [] as $q) if (($q['type'] ?? '') === 'choice') $answers[$q['id']] = $q['options'][count($q['options']) - 1];
    $actResult = $forms->result($allergy['follow'][0], ['q' => $answers]);
    check(str_contains($actResult['text'], '25/25') && !preg_match('/^\d{3}6228/m', $actResult['text']), 'ACT-Auswertung aus gemeinsamer Engine, ohne GDT-Rahmen');
    check(substr_count($actResult['text'], '25/25') === 1 && !str_contains($actResult['text'], 'Wie oft hat Ihr Asthma'),
        'ACT bleibt kompakt mit genau einer Auswertung; gdt_output=false der Vorlage wird beachtet');
    $checkinView = ['yaml' => $forms->current($job)['yaml'], 'flowId' => 'FLOW', 'formToken' => 'FORM', 'csrf' => 'CSRF', 'message' => 'WAITING_MESSAGE', 'timeout' => 900];
    ob_start(); require dirname(__DIR__) . '/fragebogenpi/tablet-checkin.php'; $html = ob_get_clean();
    check(str_contains($html, 'WAITING_MESSAGE') && !str_contains($html, 'request_gdt') && !str_contains($html, 'name="phone1"') && !str_contains($html, 'value="no" checked'), 'Renderer behält Abschlussmeldung, ohne GDT/Kontaktdopplung/vorausgewähltes Nein');

    $fake = new FixtureRest($config, '', ''); $patient = ref('c');
    // Native Bearbeiten/find/details does not set the Anzeigen-only visibility flag.
    $fake->details = ['patientRef' => $patient, 'sichtbarFuerBenutzer' => false, 'kontaktdatenDTO' => [
        'telefonnummern' => [['ref' => ref('1'), 'nummer' => '01712345567', 'typ' => 2, 'kategorie' => 'Alt', 'kommentar' => 'KEEP'], ['ref' => ref('2'), 'nummer' => '088888', 'typ' => 4, 'kommentar' => 'FAX']],
        'emailAdressen' => [['ref' => ref('3'), 'emailadresse' => 'synthetic@example.invalid', 'kategorie' => 'Privat', 'kommentar' => 'KEEP_EMAIL']]],
        'adressdatenDTO' => ['postadresse' => ['strasse' => 'Testweg', 'hausnummer' => '12', 'plz' => '12345', 'ort' => 'Testort']]];
    $client = new Checkin\T2med($config, $fake); $snapshot = $client->contactSnapshot($patient);
    $masked = json_encode(Checkin\ContactData::view($snapshot, false));
    check(str_contains($masked, '017*****567') && !str_contains($masked, 'synthetic@') && str_contains($masked, 'Testweg 12 12345 Testort'), 'Telefon/E-Mail serverseitig maskiert, Adresse vollständig');
    check(Checkin\ContactData::changes($snapshot, ['values' => ['p0' => '', 'e0' => '']]) === ['next' => $snapshot, 'notes' => []], 'Leere Kontaktfelder löschen nichts');
    $change = Checkin\ContactData::changes($snapshot, ['values' => ['p0' => '01799999567', 'e0' => 'new@example.invalid'], 'address_ok' => 'no', 'address_correction' => 'Neuer Testweg 1']);
    $journal = new Checkin\WriteJournal($config, str_repeat('4', 32), ['kind' => 'contacts', 'patient' => $patient, 'change' => $change]);
    check(!str_contains(file_get_contents($temp . '/pending/' . str_repeat('4', 32) . '.json.enc'), 'example.invalid'), 'Prüfkopie ist verschlüsselt und liegt außerhalb des Webroots');
    $client->saveContacts($patient, $snapshot, $change, $journal); $journal->complete();
    check($fake->details['kontaktdatenDTO']['telefonnummern'][1] === $snapshot['phones'][1]
        && $fake->details['adressdatenDTO']['postadresse'] === $snapshot['address'], 'Fax und Kartenadresse bleiben unverändert');
    $note = array_values($fake->texts)[0]['decoratedString']['text'];
    check(str_contains($note, 'alte Nummer: 01712345567') && str_contains($note, 'neue Nummer: 01799999567') && str_contains($note, 'korrekte Adresse: Neuer Testweg 1'), 'N-Protokoll enthält alte/neue Kontakte und Adresskorrektur');
    $client->saveMeasurements($patient, 180, 82.5); check($fake->measurement['anamnestisch'] && $fake->measurement['gewichtInKG'] === 82.5, 'Messwerte als Patientenangabe angelegt und zurückgelesen');
    $client->saveMeasurements($patient, 180, null);
    $client->saveMeasurements($patient, null, 82.5);
    check($fake->measurement['groesseInCM'] === null, 'Einzelne freiwillige Körpermaße bleiben möglich, fehlender Wert bleibt null');
    foreach (['wrongTextPatient' => 'TEXT_VERIFY', 'wrongTextContent' => 'TEXT_VERIFY', 'missingTextRef' => 'T2_REF'] as $flag => $error) {
        $fake->$flag = true; $beforeCalls = count($fake->texts);
        fails($error, fn() => $client->writeText($patient, 'ana', 'Synthetic verification test', 1));
        check(count($fake->texts) === $beforeCalls + 1, 'Unbestätigter Text wird nicht erneut geschrieben'); $fake->$flag = false;
    }
    foreach (['missingMeasurement', 'duplicateMeasurement', 'wrongMeasurement'] as $flag) {
        $fake->$flag = true;
        fails('MEASUREMENT_VERIFY', fn() => $client->saveMeasurements($patient, 179, 81));
        $fake->$flag = false;
    }
    $client->saveAllergies($patient, $allergy['allergy'], ['Pollen']);
    check(count($fake->unstructured) === 1 && $fake->structured === [], 'Ohne Fall echter Allergietexteintrag statt Fallanlage');
    $client->saveAllergies($patient, $allergy['allergy'], ['Pollen']); check(count($fake->unstructured) === 1, 'Identischer Allergietext wird nicht doppelt angelegt');
    $fake->cases = [['ref' => ref('f'), 'patient' => $patient, 'aktuell' => true, 'fallUngueltig' => false]];
    $client->saveAllergies($patient, 'Patientenangabe: Hausstaub', ['Hausstaub']);
    check(count($fake->structured) === 1 && $fake->structured[0]['befundart'] === 5 && $fake->structured[0]['sicherheit'] === 1, 'Mit eindeutigem bestehendem Fall strukturierte Allergie, Patientenangabe/unbestätigt');

    ini_set('session.use_cookies', '0'); session_cache_limiter(''); session_save_path($temp); session_start();
    $iv = random_bytes(12); $tag = ''; $cipher = openssl_encrypt('{"user":"TEST","password":""}', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    $auth = ['auth' => base64_encode($iv . $tag . $cipher), 'auth_started'=>time(), 'auth_until' => time() + 3600, 'csrf' => 'TEST'];
    $session = new Checkin\SessionStore($config); $flow = new Checkin\Flow($config, $session);
    $_SESSION = $auth + ['flow' => ['id' => 'contact-flow', 'stage' => 'contacts', 'patient' => ['ref' => $patient, 'new' => false],
        'contacts' => $client->contactSnapshot($patient), 'category' => 'without_case', 'last_activity' => time(), 'forms' => [], 'forms_seen' => []]];
    check($flow->act('contacts', ['flowId' => 'contact-flow', 'values' => []], [])['stage'] === 'choice', 'Kontaktabschluss über echte Session-Checkpoints führt zur Anliegenauswahl');
    $_SESSION['flow'] = ['id' => 'form-flow', 'stage' => 'questionnaire', 'patient' => ['ref' => $patient], 'checkin_complete' => true,
        'category' => 'new_patient', 'message' => 'DONE', 'forms' => [$job], 'forms_seen' => [], 'last_activity' => time()];
    fails('FORM_TOKEN', fn() => $flow->act('form_submit', ['flowId' => 'form-flow', 'formToken' => 'OLD'], []));
    fails('FLOW_ID', fn() => $flow->act('form_submit', ['flowId' => 'OLD', 'formToken' => $job['token']], []));
    $result = $flow->act('form_submit', ['flowId' => 'form-flow', 'formToken' => $job['token']], []);
    check($result['stage'] === 'done' && $_SESSION['auth'] === $auth['auth'] && !isset($_SESSION['flow']['patient']), 'Formularspeicherung beendet Patientenablauf, Mitarbeiterlogin bleibt');
    $failedJob = $forms->start(true)[0];
    $_SESSION['flow'] = ['id' => 'failed-form', 'stage' => 'questionnaire', 'patient' => ['ref' => $patient], 'checkin_complete' => true,
        'category' => 'new_patient', 'message' => 'DONE', 'forms' => [$failedJob], 'forms_seen' => [], 'last_activity' => time()];
    $fake->failMeasure = true; $before = count($fake->texts);
    fails('TEST_LOST_WRITE', fn() => $flow->act('form_submit', ['flowId' => 'failed-form', 'formToken' => $failedJob['token'], 'height_cm' => '180'], []));
    $state = $flow->state();
    check($state['stage'] === 'blocked' && $state['checkin_complete'] && $state['error_area'] === 'questionnaire'
        && is_file($temp . '/pending/' . $failedJob['token'] . '.json.enc') && count($fake->texts) === $before + 1, 'Teilübertragung blockiert; Anmeldung, N/ana und verschlüsselte Prüfkopie bleiben erhalten');
    fails('FLOW_BLOCKED', fn() => $flow->act('form_submit', ['flowId' => 'failed-form', 'formToken' => $failedJob['token']], []));
    check(count($fake->texts) === $before + 1, 'Unklare Akteneintragung wird nicht wiederholt');
    session_write_close();
    echo 'YAML-Prüfmodus: ', $nativeYaml ? 'native PHP-YAML' : 'Ruby/Psych-Testadapter; PHP-YAML-Zielintegration nicht getestet', "\n";
    foreach ($checks as $label) echo 'OK: ', $label, "\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n"); $failed = true; }
finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($temp !== null) {
        // Only regular synthetic files created in this run's private directory.
        foreach (glob($temp . '/pending/*') as $path) if (is_file($path)) unlink($path);
        rmdir($temp . '/pending');
        foreach (glob($temp . '/*') as $path) if (is_file($path)) unlink($path);
        rmdir($temp);
    }
}
exit(isset($failed) ? 1 : 0);
}
