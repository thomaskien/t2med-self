#!/usr/bin/env php
<?php
declare(strict_types=1);
namespace Checkin {
    // In-process HTTP double: the real RestClient runs, but no network is possible.
    function curl_init(string $url): object { return (object) ['url' => $url]; }
    function curl_setopt_array(object $handle, array $options): bool { $handle->options = $options; return true; }
    function curl_exec(object $handle): bool {
        $path = parse_url($handle->url, PHP_URL_PATH);
        $method = $handle->options[CURLOPT_CUSTOMREQUEST];
        if ($method === 'GET' && $path === '/aps/rest/praxis/praxisstruktur/kontextauswaehlen/arztrollenbehandlungorte') {
            $handle->status = 200;
            $result = ['successful' => true, 'arztrollen' => [['ref' => \fixtureRef('a')]], 'behandlungsorte' => [['ref' => \fixtureRef('b')]]];
        } elseif ($method === 'POST' && $path === '/aps/rest/praxis/patient/detailsbearbeiten/find/details') {
            $body = json_decode($handle->options[CURLOPT_POSTFIELDS], true, 128, JSON_THROW_ON_ERROR);
            \verify($body['patientRef'] === \fixtureRef('c'), 'Kontaktabruf bleibt an den angefragten Patienten gebunden');
            $handle->status = $GLOBALS['httpStatus']; $result = $GLOBALS['response'];
        } else { throw new \RuntimeException('Unerwarteter Aufruf im Offline-Test: ' . $method . ' ' . $path); }
        ($handle->options[CURLOPT_WRITEFUNCTION])($handle, json_encode($result, JSON_THROW_ON_ERROR));
        return true;
    }
    function curl_getinfo(object $handle, int $option): mixed { return $option === CURLINFO_RESPONSE_CODE ? $handle->status : 'application/json'; }
    function curl_errno(object $handle): int { return 0; }
}
namespace {
require dirname(__DIR__) . '/src/bootstrap.php';
function fixtureRef(string $id): array { return ['objectId' => ['id' => str_repeat($id, 32)], 'revision' => 0]; }
function verify(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException('FEHLER: ' . $message); } }
function fails(string $tag, callable $call): void {
    try { $call(); } catch (Checkin\AppError $error) { verify($error->tag === $tag, 'Erwartet ' . $tag . ', erhalten ' . $error->tag); return; }
    throw new RuntimeException('Fehlender Fehler: ' . $tag);
}
try {
    $data = Checkin\Toml::read(dirname(__DIR__) . '/config.example.toml');
    $data['t2med']['doctor_role_id'] = str_repeat('a', 32);
    $data['t2med']['treatment_location_id'] = str_repeat('b', 32);
    $config = new Checkin\Config($data);
    $client = new Checkin\T2med($config, new Checkin\RestClient($config, 'offline', ''));
    $patient = fixtureRef('c'); $httpStatus = 200;
    $details = ['patientRef' => $patient, 'personendatenDTO' => ['namensdaten' => ['vorname' => 'Erika', 'nachname' => 'Testperson']],
        'kontaktdatenDTO' => ['telefonnummern' => [], 'emailAdressen' => []],
        'adressdatenDTO' => ['postadresse' => ['strasse' => 'Testweg', 'hausnummer' => '12', 'plz' => '12345', 'ort' => 'Testort']]];
    foreach ([true, false] as $newPatient) {
        $base = $details;
        if (!$newPatient) {
            $base['kontaktdatenDTO']['telefonnummern'] = [['nummer' => '01712345567', 'typ' => 2, 'ref' => fixtureRef('1')]];
            $base['kontaktdatenDTO']['emailAdressen'] = [['emailadresse' => 'synthetic@example.invalid', 'ref' => fixtureRef('2')]];
        }
        $expected = null;
        // Bearbeiten/find/details leaves this display-only flag at its Java default false.
        foreach (['false' => false, 'missing' => null, 'true' => true] as $variant => $flag) {
            $response = ['successful' => true, 'details' => $base];
            if ($variant !== 'missing') { $response['details']['sichtbarFuerBenutzer'] = $flag; }
            $snapshot = $client->contactSnapshot($patient);
            $expected ??= $snapshot;
            verify($snapshot === $expected, 'Sichtbarkeitsflag ändert keine gelieferten Daten');
            $view = Checkin\ContactData::view($snapshot, $newPatient);
            verify($view['new'] === $newPatient, 'Kontaktansicht für Neu-/Bestandspatient');
            verify($view['name'] === 'Erika Testperson' && $view['address_lines'] === ['Testweg 12', '12345 Testort'], 'Name und vollständige Adresse als Postanschrift');
            if ($newPatient) {
                verify(count($view['rows']) === 3 && $view['address'] === 'Testweg 12 12345 Testort', 'Neupatient: leere Kontaktlisten und Kartenadresse');
            } else {
                $json = json_encode($view);
                verify(str_contains($json, '017*****567') && !str_contains($json, 'synthetic@')
                    && $view['address'] === 'Testweg 12 12345 Testort', 'Bestand: Telefon/E-Mail maskiert, Adresse vollständig');
            }
        }
        echo 'OK: ', $newPatient ? 'Neupatient mit leeren Listen' : 'Bestandspatient mit maskierten Kontakten', ': false/fehlend/true akzeptiert', "\n";
    }
    $extended = $details;
    $extended['adressdatenDTO']['postadresse'] += ['zusatz'=>'Hinterhaus', 'postfach'=>'Postfach 10', 'land'=>'Deutschland'];
    verify(Checkin\ContactData::view(Checkin\ContactData::snapshot($extended), false)['address_lines'] ===
        ['Testweg 12', 'Hinterhaus', 'Postfach 10', '12345 Testort', 'Deutschland'], 'Adresszusätze bleiben vollständig erhalten');
    unset($extended['personendatenDTO']);
    verify(Checkin\ContactData::snapshot($extended)['name'] === '', 'Fehlender Name blockiert keine Kontaktdaten');
    verify(Checkin\ContactData::changes(Checkin\ContactData::snapshot($details), ['values'=>[]])['notes'] === [], 'Weiter ohne Korrektur erzeugt keine N-Notiz');
    foreach ([null, new stdClass(), 'invalid', ['telefonnummern' => []], ['telefonnummern' => null, 'emailAdressen' => []],
        ['telefonnummern' => [['nummer' => '123', 'typ' => 'invalid']], 'emailAdressen' => []],
        ['telefonnummern' => [], 'emailAdressen' => [['emailadresse' => null]]]] as $invalid) {
        $response = ['successful' => true, 'details' => $details];
        $response['details']['kontaktdatenDTO'] = $invalid;
        fails(is_array($invalid) && (!isset($invalid['telefonnummern'], $invalid['emailAdressen'])) ? 'T2_LIST' : 'CONTACT_SHAPE', fn() => $client->contactSnapshot($patient));
    }
    $response = ['successful' => true, 'details' => $details]; unset($response['details']['kontaktdatenDTO']);
    fails('CONTACT_SHAPE', fn() => $client->contactSnapshot($patient));
    echo "OK: Fehlende und fehlerhafte Kontaktdaten werden weiterhin abgewiesen\n";
    $response = ['successful' => true, 'details' => $details]; $response['details']['patientRef'] = fixtureRef('d');
    fails('CONTACT_PATIENT', fn() => $client->contactSnapshot($patient));
    echo "OK: Falsche Patientenzuordnung bleibt gesperrt\n";
    foreach ([401 => 'T2_AUTH', 403 => 'T2_RIGHTS'] as $status => $error) {
        $httpStatus = $status; $response = ['successful' => true, 'details' => $details];
        fails($error, fn() => $client->contactSnapshot($patient));
    }
    $httpStatus = 200; $response = ['successful' => false, 'details' => $details];
    fails('REST_REJECTED', fn() => $client->contactSnapshot($patient));
    echo "OK: HTTP 401/403 und negative APS-Rückmeldungen bleiben gesperrt\n";
    echo "Kontaktprüfung offline erfolgreich; keine Schreibendpunkte aufgerufen.\n";
} catch (Throwable $error) { fwrite(STDERR, $error->getMessage() . "\n"); exit(1); }
}
