#!/usr/bin/env php
<?php
declare(strict_types=1);
namespace Checkin {
    // Real RestClient and Flow; an in-process HTTP double forbids all network access.
    function curl_init(string $url): object { return (object) ['url' => $url]; }
    function curl_setopt_array(object $h, array $options): bool { $h->options = $options; return true; }
    function curl_exec(object $h): bool {
        $path = substr($h->url, strpos($h->url, '/aps/rest') + 9);
        $input = isset($h->options[CURLOPT_POSTFIELDS]) ? json_decode($h->options[CURLOPT_POSTFIELDS], true, 128, JSON_THROW_ON_ERROR) : null;
        $body = $GLOBALS['server']->request($h->options[CURLOPT_CUSTOMREQUEST], $path, $input);
        ($h->options[CURLOPT_WRITEFUNCTION])($h, json_encode($body, JSON_THROW_ON_ERROR)); return true;
    }
    function curl_getinfo(object $h, int $option): mixed { return $option === CURLINFO_RESPONSE_CODE ? 200 : 'application/json'; }
    function curl_errno(object $h): int { return 0; }
}
namespace {
require dirname(__DIR__) . '/src/bootstrap.php';
function check(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } }
function ref(string $id): array { return ['objectId' => ['id' => str_repeat($id, 32)], 'revision' => 0]; }
class TextServer {
    public array $calls = [], $details, $texts = [], $waiting = [];
    public array $reject = ['successful' => false, 'validation' => ['messages' => [['message' => "Der Behandlungsort 'PRIVATE_LOCATION' passt nicht zur Arztrolle 'PRIVATE_ROLE'."]]]];
    public bool $accept = false;
    public function __construct(private array $room) {
        $this->details = ['patientRef' => ref('c'), 'kontaktdatenDTO' => ['telefonnummern' => [], 'emailAdressen' => []],
            'adressdatenDTO' => ['postadresse' => ['strasse' => 'Testweg', 'hausnummer' => '12', 'plz' => '12345', 'ort' => 'Testort']]];
    }
    public function request(string $method, string $path, mixed $input): array {
        $this->calls[] = $method . ' ' . $path;
        if ($method === 'GET' && str_ends_with($path, '/arztrollenbehandlungorte')) {
            $out = ['arztrollen' => [['ref' => ref('a')]], 'behandlungsorte' => [['ref' => ref('b')]]];
        } elseif ($method === 'POST' && str_ends_with($path, '/find/details')) {
            check($input['patientRef'] === ref('c'), 'Patient identity'); $out = ['details' => $this->details];
        } elseif ($method === 'GET' && $path === '/praxis/karteikarte/text/create?kuerzel=N') {
            $out = ['texteintragTO' => ['ref' => ['objectId' => null, 'revision' => 0], 'kuerzel' => 'N', 'fachinformationstypTO' => ['fachinformationstyp' => 99]]];
        } elseif ($method === 'POST' && $path === '/praxis/karteikarte/text/insert') {
            check($input['kontext']['patientRef'] === ref('c') && $input['kontext']['behandlungsfallRef'] === null, 'No fabricated case');
            if (!$this->accept) { return $this->reject; }
            $entry = $input['texteintrag']; $entry['ref'] = ref('d'); $this->texts[] = $entry;
            $out = ['karteieintragZeileDTO' => ['ref' => ref('e')]];
        } elseif ($method === 'POST' && $path === '/praxis/karteikarte/patient/' . str_repeat('c', 32) . '/byids') {
            check($input === [ref('e')['objectId']], 'Verify saved row'); return [['ref' => ref('e')]];
        } elseif ($method === 'POST' && $path === '/praxis/karteikarte/dokumentationbearbeitenvorgang') {
            $out = ['fachinformationRef' => ref('d')];
        } elseif ($method === 'GET' && $path === '/praxis/karteikarte/text/' . str_repeat('d', 32) . '/read') {
            $out = ['texteintragTO' => $this->texts[0]];
        } elseif ($method === 'POST' && str_ends_with($path, '/updateTelefonnummer')) {
            $this->details['kontaktdatenDTO']['telefonnummern'] = $input['telefonnummerTOList']; $out = [];
        } elseif ($method === 'POST' && str_ends_with($path, '/updateEmailadressen')) {
            $this->details['kontaktdatenDTO']['emailAdressen'] = $input['adresseTOList']; $out = [];
        } elseif ($method === 'GET' && $path === '/wartezimmer/bereiche/all') {
            $out = ['entries' => [$this->room]];
        } elseif ($method === 'POST' && $path === '/wartezimmer/eintraege/all') {
            $out = ['wartebereich' => $this->room, 'eintraege' => $this->waiting];
        } elseif ($method === 'POST' && $path === '/wartezimmer/eintraege/add') {
            check($input['wartebereich'] === $this->room['ref'] && $input['wartender'] === ref('c')['objectId'], 'Correct waiting room and patient');
            $entry = $input['wartebereicheintrag']; $entry['ref'] = ref('f');
            $this->waiting[] = ['eintrag' => $entry, 'wartender' => ['ref' => ref('c')], 'wartebereich' => $this->room]; $out = [];
        } else { throw new RuntimeException('Unexpected synthetic endpoint: ' . $method . ' ' . $path); }
        return ['successful' => true] + $out;
    }
}
$temp = sys_get_temp_dir() . '/checkin152-text-' . bin2hex(random_bytes(8)); $failed = false;
try {
    mkdir($temp, 0700); mkdir($temp . '/pending', 0700);
    $key = random_bytes(32); file_put_contents($temp . '/key', $key); chmod($temp . '/key', 0600);
    $data = Checkin\Toml::read(dirname(__DIR__) . '/config.example.toml');
    $data['privacy']['enabled'] = false; // This regression isolates N/contacts/routing; privacy has its own tests.
    $data['app']['state_dir'] = $temp; $data['app']['secret_file'] = $temp . '/key';
    $data['t2med']['doctor_role_id'] = str_repeat('a', 32); $data['t2med']['treatment_location_id'] = str_repeat('b', 32);
    $data['selfie']['enabled'] = false;
    $config = new Checkin\Config($data); $room = ['ref' => ref('1'), 'name' => $config->category('new_patient')['room'], 'initialerStatus' => 0];
    $rest = new Checkin\RestClient($config, 'TEST', '');
    $server = new TextServer($room);
    $errors = [
        [['successful' => false, 'validation' => ['messages' => [['message' => "Der Behandlungsort 'PRIVATE_LOCATION' passt nicht zur Arztrolle 'PRIVATE_ROLE'."]]]], 'ROLE_LOCATION_MISMATCH'],
        [['successful' => false, 'message' => ['message' => 'PRIVATE_UNKNOWN_PATIENT_DATA']], 'UPSTREAM_VALIDATION'],
        [['successful' => false, 'validation' => ['messages' => [['message' => 'Es wurde kein Behandlungsfall angegeben.']]]], 'CASE_REQUIRED'],
        [[], 'SUCCESS_FLAG_INVALID'], [['successful' => 'true'], 'SUCCESS_FLAG_INVALID'],
    ];
    foreach ($errors as [$body, $reason]) {
        $server->reject = $body; $before = count($server->calls);
        try { $rest->request('POST', '/praxis/karteikarte/text/insert', ['kontext' => ['patientRef' => ref('c'), 'behandlungsfallRef' => null]]); throw new RuntimeException('Rejection accepted'); }
        catch (Checkin\AppError $error) {
            check($error->tag === 'REST_REJECTED' && $error->diagnostic['reason'] === $reason, 'Stable reason code');
            check(!str_contains(json_encode($error->diagnostic) . $error->getMessage(), 'PRIVATE_'), 'No raw data in public error/log metadata');
            $path = $temp . '/pending/' . $error->diagnostic['report_id'] . '.json.enc';
            $blob = file_get_contents($path); check(!str_contains($blob, 'PRIVATE_') && (fileperms($path) & 0777) === 0600, 'Encrypted private report');
            $plain = openssl_decrypt(substr($blob, 31), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 3, 12), substr($blob, 15, 16), 'checkin-recovery-1');
            $report = json_decode($plain, true, 128, JSON_THROW_ON_ERROR);
            check($report['payload']['kind'] === 'rest_rejection' && $report['payload']['reason'] === $reason, 'Local report can be decrypted');
            if ($reason === 'UPSTREAM_VALIDATION') { check(str_contains($plain, 'PRIVATE_UNKNOWN_PATIENT_DATA'), 'Unknown cause retained only encrypted'); }
        }
        check(count($server->calls) === $before + 1, 'No write retry');
    }
    // Storage failure must retain REST_REJECTED and never turn it into success.
    $bad = $data; $bad['app']['secret_file'] = $temp . '/missing-key';
    $meta = Checkin\RestRejection::record(new Checkin\Config($bad), ['successful' => false], ['operation' => 'TEXT_SAVE']);
    check($meta['report_storage'] === 'UNAVAILABLE' && !isset($meta['report_id']), 'Missing key handled without raw exception');
    // Real contact submit -> N -> phones/email -> new-patient room, and its rejected variant.
    ini_set('session.use_cookies', '0'); session_cache_limiter(''); session_save_path($temp); session_start();
    $iv = random_bytes(12); $tag = ''; $cipher = openssl_encrypt('{"user":"TEST","password":""}', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    $auth = ['auth' => base64_encode($iv . $tag . $cipher), 'auth_started'=>time(), 'auth_until' => time() + 3600, 'csrf' => 'TEST'];
    foreach ([false, true] as $accept) {
        $server = new TextServer($room); $server->accept = $accept;
        $_SESSION = $auth + ['flow' => ['id' => 'test-flow', 'stage' => 'contacts', 'patient' => ['ref' => ref('c'), 'new' => true],
            'contacts' => Checkin\ContactData::snapshot($server->details), 'category' => 'new_patient', 'last_activity' => time(),
            'plan' => [], 'forms' => [], 'forms_seen' => [], 'card_session' => null]];
        $flow = new Checkin\Flow($config, new Checkin\SessionStore($config));
        try {
            $state = $flow->act('contacts', ['flowId' => 'test-flow', 'values' => ['add1' => '030123456', 'add2' => '0171234567', 'addEmail' => 'test@example.invalid']], []);
            check($accept && $state['stage'] === 'done' && $state['checkin_complete'], 'Successful synthetic check-in completes');
            check(count($server->texts) === 1 && count($server->waiting) === 1
                && count($server->details['kontaktdatenDTO']['telefonnummern']) === 2
                && count($server->details['kontaktdatenDTO']['emailAdressen']) === 1, 'N, contacts and room each saved');
        } catch (Checkin\AppError $error) {
            check(!$accept && $error->tag === 'REST_REJECTED', 'Expected N failure');
            $flow->block($error->tag, $error->diagnostic);
            check($flow->state()['stage'] === 'blocked' && $server->texts === [] && $server->waiting === []
                && $server->details['kontaktdatenDTO']['telefonnummern'] === [], 'Reject stops downstream writes');
            check(count(array_filter($server->calls, fn($c) => str_ends_with($c, '/text/insert'))) === 1, 'Rejected N is not retried');
        }
        check($_SESSION['auth'] === $auth['auth'], 'Employee login preserved');
    }
    echo "OK: HTTP-200-Ablehnung, feste Kategorien, verschlüsselte Begründung, Speicherausfall, kein Retry.\n";
    echo "OK: Synthetischer Neupatient: erfolgreiche N/Kontakt/Wartezimmer-Kette; Ablehnung stoppt sicher. Kein Live-T2med-Test.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); $failed = true; }
finally {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    foreach (glob($temp . '/pending/*') ?: [] as $path) { if (is_file($path)) { unlink($path); } }
    if (is_dir($temp . '/pending')) { rmdir($temp . '/pending'); }
    foreach (glob($temp . '/*') ?: [] as $path) { if (is_file($path)) { unlink($path); } }
    if (is_dir($temp)) { rmdir($temp); }
}
exit($failed ? 1 : 0);
}
