#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Checkin {
    // In-process cURL double. No socket, HTTP server, T2med login or patient write is used.
    function curl_init(string $url): object { return (object) ['url' => $url]; }
    function curl_setopt_array(object $handle, array $options): bool { $handle->options = $options; return true; }
    function curl_exec(object $handle): bool
    {
        if ($GLOBALS['replies'] === []) { throw new \RuntimeException('Unerwarteter REST-Aufruf im Offline-Test.'); }
        $handle->reply = array_shift($GLOBALS['replies']);
        $GLOBALS['requests'][] = ['url' => $handle->url, 'method' => $handle->options[CURLOPT_CUSTOMREQUEST],
            'body' => $handle->options[CURLOPT_POSTFIELDS] ?? null, 'headers' => $handle->options[CURLOPT_HTTPHEADER]];
        if ($handle->reply['body'] !== '') { ($handle->options[CURLOPT_WRITEFUNCTION])($handle, $handle->reply['body']); }
        return $handle->reply['errno'] === 0;
    }
    function curl_getinfo(object $handle, int $option): mixed
    {
        return match ($option) {
            CURLINFO_RESPONSE_CODE => $handle->reply['http'],
            CURLINFO_CONTENT_TYPE => $handle->reply['type'],
            default => throw new \RuntimeException('Unerwartete cURL-Metadatenabfrage.'),
        };
    }
    function curl_errno(object $handle): int { return $handle->reply['errno']; }
}

namespace {
    require dirname(__DIR__) . '/src/bootstrap.php';
    $replies = []; $requests = []; $checks = []; $temporary = null; $keyPath = null; $leasePath = null;
    function check(bool $condition, string $label): void
    {
        if (!$condition) { throw new RuntimeException('FEHLER: ' . $label); }
        $GLOBALS['checks'][] = $label;
    }
    function reply(int $http = 204, string $body = '', ?string $type = null, int $errno = 0): void
    {
        $GLOBALS['replies'][] = compact('http', 'body', 'type', 'errno');
    }
    function expectError(string $tag, callable $run): Checkin\AppError
    {
        try { $run(); } catch (Checkin\AppError $error) {
            check($error->tag === $tag, 'Fehler bleibt erhalten: ' . $tag); return $error;
        }
        throw new RuntimeException('Erwarteter Fehler fehlt: ' . $tag);
    }
    function blocked(string $id = 'patient-flow'): array
    {
        return ['id' => $id, 'stage' => 'blocked', 'error_code' => 'REST_JSON', 'card_session' => 'fixture-session',
            'patient' => ['private' => 'TEST_PATIENT'], 'plan' => ['TEST_PLAN'], 'note' => 'TEST_NOTE', 'last_activity' => time()];
    }
    try {
        $config = new Checkin\Config(dirname(__DIR__) . '/config.example.toml', false);
        $rest = new Checkin\RestClient($config, 'TEST_USER', 'TEST_PASSWORD');
        $client = new Checkin\T2med($config, $rest);
        $client->eject(null);
        check($requests === [], 'Ohne Kartensitzung kein REST-Aufruf');
        reply(); $client->eject('fixture-session');
        reply(200); $client->eject('fixture-session');
        check(count($requests) === 2, 'Kartenfreigabe akzeptiert leere HTTP-204- und HTTP-200-Antworten');
        check(parse_url($requests[0]['url'], PHP_URL_PORT) === 16567, 'REST-Kartenfreigabe nutzt unverändert Port 16567');
        reply(); $error = expectError('REST_JSON', fn() => $rest->request('GET', '/praxis/versichertenkarte/einlesen/fixture-session/einlesen', null, false));
        check($error->diagnostic === ['operation' => 'CARD_READ', 'http_status' => 204, 'content_type' => 'absent', 'response_bytes' => 0],
            'Datenaufruf bleibt bei leerer Antwort gesperrt und liefert sichere Diagnose');
        reply(200, '<html>TEST_PRIVATE_BODY</html>', 'text/html; private=TEST_HEADER');
        $error = expectError('REST_JSON', fn() => $rest->request('GET', '/praxis/versichertenkarte/einlesen/anfordern?terminalName=TEST_READER'));
        $diagnostic = json_encode($error->diagnostic);
        check($error->diagnostic['operation'] === 'CARD_SESSION' && $error->diagnostic['content_type'] === 'text/html'
            && !str_contains($diagnostic, 'TEST_') && !str_contains($diagnostic, 'fixture-session'),
            'Diagnose enthält weder Antwortinhalt noch Parameter, UUID, Headerwerte oder Zugangsdaten');
        reply(200, '{"successful":true,"nested":{}}', 'application/json;charset=UTF-8');
        check($rest->request('GET', '/wartezimmer/bereiche/all')['nested'] instanceof stdClass, 'Gültiges JSON und leere Objekte bleiben erhalten');
        reply(200, '{"successful":false}', 'application/json');
        expectError('REST_REJECTED', fn() => $rest->request('GET', '/wartezimmer/bereiche/all'));
        foreach ([401 => 'T2_AUTH', 403 => 'T2_RIGHTS', 503 => 'REST_HTTP_503'] as $http => $tag) {
            reply($http); $error = expectError($tag, fn() => $client->eject('fixture-session'));
            check($error->diagnostic['operation'] === 'CARD_RELEASE', 'HTTP-Fehler bei Kartenfreigabe wird nicht ignoriert: ' . $http);
        }
        reply(0, '', null, 28); expectError('REST_TRANSPORT_28', fn() => $client->eject('fixture-session'));

        reply(); $rest->upload('cdn://upload/a+b/c==%*~', 'TEST_JPEG');
        $upload = $requests[array_key_last($requests)];
        check(parse_url($upload['url'], PHP_URL_PORT) === 16570, 'Bildübertragung nutzt CDN-Port 16570, nicht REST-Port');
        check($upload['method'] === 'PUT' && str_ends_with($upload['url'], '/cdn/rest/delivery/upload%252Fa%252Bb%252Fc%253D%253D%2525*%257E'),
            'CDN-Token entspricht Java-URLEncoder plus RESTEasy-Pfadkodierung');
        check(str_contains($upload['body'], '"metaData":{"values":{}}')
            && str_contains($upload['body'], "name=\"properties\"\r\nContent-Type: application/json\r\n\r\n")
            && str_contains($upload['body'], "Content-Type: application/octet-stream\r\n\r\nTEST_JPEG\r\n"),
            'Multipart enthält native ContentProperties, leere MetaData.values und binären Stream');
        $customData = $config->data; $customData['t2med']['cdn_port'] = 17443;
        $customRest = new Checkin\RestClient(new Checkin\Config($customData, false), 'TEST_USER', 'TEST_PASSWORD');
        reply(); $customRest->upload('cdn://TEST_TOKEN', 'TEST_JPEG');
        check(parse_url($requests[array_key_last($requests)]['url'], PHP_URL_PORT) === 17443, 'Individuell konfigurierter CDN-Port wird tatsächlich verwendet');
        $before = count($requests);
        expectError('PHOTO_TOKEN', fn() => $rest->upload("cdn://bad\r\ntoken", 'TEST_JPEG'));
        expectError('PHOTO_TOKEN', fn() => $rest->upload('cdn://', 'TEST_JPEG'));
        check(count($requests) === $before, 'Ungültige CDN-Token verursachen keinen HTTP-Aufruf');
        reply(400, 'TEST_PRIVATE_TOKEN', 'text/plain');
        $error = expectError('REST_HTTP_400', fn() => $rest->upload('cdn://TEST_TOKEN', 'TEST_JPEG'));
        check($error->diagnostic['operation'] === 'PHOTO_TRANSFER' && !str_contains(json_encode($error->diagnostic), 'TEST_'),
            'Uploadfehler bleibt diagnostizierbar, ohne Bild oder Token offenzulegen');

        reply(); $rest->uploadPdf('cdn://upload/a+b/c==%*~', '%PDF-synthetic', str_repeat('a', 32));
        $pdfUpload = $requests[array_key_last($requests)];
        check($pdfUpload['method'] === 'PUT' && str_contains($pdfUpload['body'], '"mediaType":"application\\/pdf"')
            && str_contains($pdfUpload['body'], 'datenschutz-' . str_repeat('a', 32) . '.pdf')
            && str_contains($pdfUpload['body'], "application/octet-stream\r\n\r\n%PDF-synthetic\r\n"), 'PDF verwendet native Multipart-Struktur, MIME-Typ und zufälligen Dateinamen');
        reply(200, '%PDF-synthetic', 'application/pdf');
        check($rest->documentBytes('cdn://APS/Praxis/Patient/doc') === '%PDF-synthetic'
            && str_ends_with($requests[array_key_last($requests)]['url'], '/delivery/APS%252FPraxis%252FPatient%252Fdoc'), 'PDF-Rücklesen nutzt kodierten Pfad auf konfiguriertem CDN');
        $before = count($requests);
        foreach (['https://external.invalid/secret', 'cdn://bad?redirect=x', "cdn://bad\npath", 'cdn://doc;jsessionid=secret'] as $path) {
            expectError('PRIVACY_VERIFY', fn() => $rest->documentBytes($path));
        }
        expectError('PRIVACY_PDF', fn() => $rest->uploadPdf('cdn://token', 'not a PDF', str_repeat('a',32)));
        check(count($requests) === $before, 'Ungültige PDF-Verweise und Inhalte werden vor Netzwerkzugriff abgewiesen');
        reply(200, '<html>not a PDF</html>', 'text/html'); expectError('PRIVACY_VERIFY', fn() => $rest->documentBytes('cdn://doc'));
        reply(403, 'PRIVATE', 'text/plain'); $error=expectError('T2_RIGHTS', fn() => $rest->documentBytes('cdn://doc'));
        check($error->diagnostic['operation'] === 'PRIVACY_DOWNLOAD', 'PDF-Zugriffsfehler wird nicht als fehlender Bogen behandelt');
        reply(400, 'PRIVATE', 'text/plain'); $error=expectError('REST_HTTP_400', fn() => $rest->uploadPdf('cdn://token','%PDF-synthetic',str_repeat('a',32)));
        check($error->diagnostic['operation'] === 'PRIVACY_TRANSFER', 'PDF-Übertragungsfehler hat eigene Kennung');

        // Small, isolated fixture for real PHP-session checkpoints and the existing file lease.
        $temporary = sys_get_temp_dir() . '/checkin-13-offline-' . bin2hex(random_bytes(12));
        if (!mkdir($temporary, 0700)) { throw new RuntimeException('Offline-Verzeichnis konnte nicht angelegt werden.'); }
        $keyPath = $temporary . '/secret.key'; $key = random_bytes(32);
        file_put_contents($keyPath, $key); chmod($keyPath, 0600);
        $data = $config->data; $data['privacy']['enabled'] = false; $data['app']['state_dir'] = $temporary; $data['app']['secret_file'] = $keyPath;
        $data['t2med']['doctor_role_id'] = str_repeat('a', 20); $data['t2med']['treatment_location_id'] = str_repeat('b', 20);
        $config = new Checkin\Config($data, false);
        $leasePath = $temporary . '/reader-' . hash('sha256', $config->get('reader.name')) . '.lock';
        ini_set('session.use_cookies', '0'); session_cache_limiter(''); session_save_path($temporary); session_start();
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt('{"user":"TEST_USER","password":""}', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        $auth = ['auth' => base64_encode($iv . $tag . $encrypted), 'auth_started'=>time(), 'auth_until' => time() + 3600, 'csrf' => 'TEST_CSRF'];
        $_SESSION = $auth + ['flow' => blocked()];
        $session = new Checkin\SessionStore($config); $flow = new Checkin\Flow($config, $session);
        $session->lease('patient-flow'); $before = count($requests);
        reply(); $result = $flow->act('next', ['flowId' => 'patient-flow'], []);
        check($result === ['stage' => 'idle'] && $_SESSION === $auth, 'Nächste Karte entfernt alle Patientendaten, behält Login, Ablaufzeit und CSRF');
        check(count($requests) === $before + 1 && str_ends_with($requests[$before]['url'], '/fixture-session/auswerfen'),
            'Cleanup ruft ausschließlich Kartenfreigabe auf, keine Wiederholung von Import, SQL, Foto oder Wartezimmer');
        $session->lease('next-flow'); $session->release('next-flow');
        check(json_decode(file_get_contents($leasePath), true) === [], 'Lesegerätesperre ist für den nächsten Patienten frei');
        $flow->act('next', ['flowId' => 'patient-flow'], []);
        check(count($requests) === $before + 1, 'Doppelter Cleanup startet kein Kartenlesen und bleibt ohne Nebenwirkung');

        $_SESSION['flow'] = blocked('newer-flow');
        expectError('FLOW_ID', fn() => $flow->act('next', ['flowId' => 'old-flow'], []));
        check($_SESSION['flow']['id'] === 'newer-flow', 'Veraltetes Fenster kann keinen neueren Vorgang beenden');
        $_SESSION['flow']['stage'] = 'committing';
        expectError('FLOW_STEP', fn() => $flow->act('next', ['flowId' => 'newer-flow'], []));
        check($_SESSION['flow']['stage'] === 'committing', 'Laufender Schreibvorgang wird nicht durch Cleanup verworfen');
        check($flow->state()['error_code'] === 'INTERRUPTED', 'Abgebrochener Worker wird über den Zustandsabruf erkannt');
        reply(503); $error = expectError('REST_HTTP_503', fn() => $flow->act('next', ['flowId' => 'newer-flow'], []));
        $flow->block($error->tag, $error->diagnostic);
        check($_SESSION['flow']['card_session'] === 'fixture-session' && $session->authenticated()
            && $flow->state()['error_diagnostic']['operation'] === 'CARD_RELEASE', 'Cleanup-Fehler hält Kartensitzung und Login für gezielte Wiederholung vor');
        reply(); $flow->act('next', ['flowId' => 'newer-flow'], []);
        check($_SESSION === $auth, 'Nach erfolgreichem Cleanup weiter ohne Neuanmeldung');

        $_SESSION['flow'] = blocked(); $session->lease('different-browser'); $before = count($requests);
        expectError('READER_BUSY', fn() => $flow->act('next', ['flowId' => 'patient-flow'], []));
        check(count($requests) === $before && json_decode(file_get_contents($leasePath), true)['owner'] === 'different-browser',
            'Fremde aktive Lesegerätesperre bleibt unangetastet');
        $session->release('different-browser');

        // Focused 1.3 flow fixtures: synthetic refs and queued HTTP responses only.
        $ref = static fn(string $char): array => ['objectId' => ['id' => str_repeat($char, 20)], 'revision' => 1];
        $patientRef = $ref('c');
        $context = ['arztrollen' => [['ref' => $ref('a')]], 'behandlungsorte' => [['ref' => $ref('b')]]];
        $jsonReply = static fn(array $body) => reply(200, json_encode(['successful' => true] + $body), 'application/json');
        foreach ([['acute', '', false, 200, null], ['other', " \n\t", true, 200, null],
            ['acute', 'TEST_NOTE', false, 503, null], ['other', '', false, 200, 'cdn://TEST_EXISTING_PHOTO']] as [$category, $note, $existing, $photoHttp, $photoUrl]) {
            $room = ['ref' => $ref('d'), 'name' => $config->category($category)['room'], 'initialerStatus' => 0];
            $entry = ['ref' => $ref('e'), 'notiz' => $existing ? 'TEST_OLD_NOTE' : trim($note), 'status' => 0,
                'wartebeginn' => 0, 'terminInformationen' => null, 'notfall' => false, 'zusatzInformationen' => new stdClass()];
            $entryResponse = ['wartebereich' => $room, 'eintraege' => [['eintrag' => $entry, 'wartender' => ['ref' => $patientRef], 'wartebereich' => $room]]];
            $_SESSION = $auth + ['flow' => ['id' => 'photo-flow', 'stage' => 'note', 'category' => $category,
                'last_activity' => time(), 'patient' => ['ref' => $patientRef], 'plan' => [], 'card_session' => null]];
            $before = count($requests);
            $jsonReply(['entries' => [$room]]); // Recheck calendar plan.
            $jsonReply(['entries' => [$room]]); // Validate target room.
            $jsonReply(['entries' => [$room]]); $jsonReply($context);
            $jsonReply($existing ? $entryResponse : ['wartebereich' => $room, 'eintraege' => []]);
            if (!$existing) {
                $jsonReply(['entries' => [$room]]); $jsonReply([]); // Add once.
                $jsonReply(['entries' => [$room]]); $jsonReply($entryResponse); // Verify write.
            }
            if ($photoHttp === 200) { $jsonReply(['patientenbild' => ['sichtbar' => true, 'patientRef' => $patientRef, 'patientenbild' => $photoUrl]]); }
            else { reply($photoHttp); }
            try { $flow->act('note', ['flowId' => 'photo-flow', 'note' => $note], []); }
            catch (Checkin\AppError $error) {
                if ($photoHttp === 200) { throw $error; }
                $flow->block($error->tag, $error->diagnostic);
            }
            $state = $flow->state(); $calls = array_slice($requests, $before);
            $writes = array_values(array_filter($calls, static fn($call) => str_ends_with($call['url'], '/eintraege/add') || str_ends_with($call['url'], '/eintraege/update')));
            check(count($writes) === ($existing ? 0 : 1), 'Notiz ' . $category . ': keine doppelten Einträge oder leeren Notiz-Ergänzungen');
            if (!$existing) { check(json_decode($writes[0]['body'], true)['wartebereicheintrag']['notiz'] === trim($note), 'Notiz wird getrimmt als Wartezimmernotiz übertragen'); }
            check($state['checkin_complete'] && $state['message'] === $config->category($category)['message']
                && str_ends_with($calls[array_key_last($calls)]['url'], '/uebersicht/lesedaten'), 'Check-in und seine Meldung stehen vor dem optionalen Fotostatus fest');
            check(!isset($_SESSION['flow']['plan'], $_SESSION['flow']['note']) && json_decode(file_get_contents($leasePath), true) === [],
                'Routingdaten und Lesegerätesperre nach Check-in bereinigt');
            $after = count($requests);
            if ($photoHttp !== 200) {
                check($state['stage'] === 'blocked' && !isset($_SESSION['flow']['patient']), 'Fotofehler behält bestätigten Check-in, aber keine Patientendaten');
                $flow->act('next', ['flowId' => 'photo-flow'], []);
            } else {
                if ($photoUrl === null) {
                    check($state['stage'] === 'selfie', 'Fehlendes Foto führt zur freiwilligen Frage');
                    $state = $flow->act('selfie', ['flowId' => 'photo-flow', 'answer' => 'no'], []);
                }
                check($state['stage'] === 'done' && $state['completionRemaining'] > 0 && $state['completionRemaining'] <= 15
                    && !isset($_SESSION['flow']['patient']), 'Fertig startet Countdown und entfernt Patientenreferenz');
                $_SESSION['flow']['done_at'] = time() - 16;
                check($flow->state()['completionRemaining'] === 0, 'Neuladen startet serverseitigen Countdown nicht erneut');
                $flow->act('reset', ['flowId' => 'photo-flow'], []);
            }
            check(count($requests) === $after && $_SESSION === $auth, 'Foto ablehnen, vorhandenes Foto oder Fotofehler: weiter ohne zweite Wartezimmeraktion oder Login');
        }
        $_SESSION['flow'] = ['id' => 'legacy-photo', 'stage' => 'camera', 'last_activity' => time()];
        check($flow->state()['error_code'] === 'FLOW_VERSION', 'Alte 1.2-Fotostufe zeigt keinen unbestätigten Check-in als fertig');
        $flow->act('next', ['flowId' => 'legacy-photo'], []);
        $_SESSION['flow'] = blocked(); $before = count($requests);
        $_SESSION['auth_until'] = time() - 1;
        expectError('LOGIN_REQUIRED', fn() => $flow->act('next', ['flowId' => 'patient-flow'], []));
        check(count($requests) === $before && isset($_SESSION['flow']), 'Tatsächlich abgelaufener Login wird nicht umgangen');
        check($replies === [], 'Alle vorbereiteten Antworten wurden genau einmal verbraucht');
        // Staff logout: real local session, only mocked card-release HTTP, no patient writes.
        $_SESSION = $auth; $before = count($requests);
        expectError('INPUT', fn() => $flow->act('logout', ['flowId'=>'','confirmations'=>2], []));
        check($_SESSION === $auth && count($requests) === $before, 'Incomplete logout confirmation leaves session unchanged');
        $_SESSION = $auth + ['flow'=>blocked('newer-flow')];
        expectError('FLOW_ID', fn() => $flow->act('logout', ['flowId'=>'older-flow','confirmations'=>3], []));
        check($session->authenticated() && $_SESSION['flow']['id']==='newer-flow', 'Stale logout cannot discard newer patient');
        $_SESSION['flow']['stage']='committing';
        expectError('FLOW_STEP', fn() => $flow->act('logout', ['flowId'=>'newer-flow','confirmations'=>3], []));
        check($session->authenticated() && $_SESSION['flow']['stage']==='committing', 'Logout cannot interrupt write');
        $_SESSION['flow']=blocked('newer-flow'); $session->lease('newer-flow');
        reply(503); expectError('REST_HTTP_503', fn() => $flow->act('logout', ['flowId'=>'newer-flow','confirmations'=>3], []));
        check($session->authenticated() && $_SESSION['flow']['card_session']==='fixture-session', 'Unconfirmed card release prevents silent logout');
        $_SESSION['login_failures']=[time()];$failures=$_SESSION['login_failures'];$oldSessionId=session_id();
        $before=count($requests);reply();
        check($flow->act('logout', ['flowId'=>'newer-flow','confirmations'=>3], [])===['stage'=>'login'], 'Confirmed logout returns staff login');
        check(!$session->authenticated() && !isset($_SESSION['auth']) && !isset($_SESSION['auth_until']) && !isset($_SESSION['auth_started']) && !isset($_SESSION['flow'])
            && $_SESSION['csrf']!==$auth['csrf'] && $_SESSION['login_failures']===$failures && session_id()!==$oldSessionId,
            'Logout removes credentials/patient state, rotates session and CSRF, preserves rate limit');
        check(count($requests)===$before+1 && str_ends_with($requests[$before]['url'],'/fixture-session/auswerfen'), 'Only card release during logout');
        check(json_decode(file_get_contents($leasePath),true)===[], 'Logout releases reader');
        expectError('LOGIN_REQUIRED', fn() => $flow->act('begin', [], []));
        expectError('CSRF', fn() => $session->csrf($auth['csrf']));
        $_SESSION=$auth;$before=count($requests);
        $flow->act('logout', ['flowId'=>'','confirmations'=>3], []);
        check(count($requests)===$before && !$session->authenticated(), 'Idle logout needs no T2med request');
        session_destroy();
        echo implode("\n", array_map(static fn($label) => 'OK: ' . $label, $checks)) . "\nOffline-Prüfung für REST und nächsten Patienten erfolgreich.\n";
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n"); $failed = true;
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
        // Only remove the exact files created by this fixture, never a recursive deletion.
        if ($temporary !== null) {
            foreach ([$keyPath, $leasePath, $temporary . '/events.log'] as $path) {
                if ($path !== null && is_file($path)) { unlink($path); }
            }
            rmdir($temporary);
        }
    }
    exit(isset($failed) ? 1 : 0);
}
