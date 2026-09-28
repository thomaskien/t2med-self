<?php
declare(strict_types=1);
namespace Checkin;

class RestClient
{
    public function __construct(private Config $config, private string $user, private string $password) {}

    public function request(string $method, string $path, mixed $body = null, bool $requireSuccess = true): array
    {
        $response = $this->send($method, '/aps/rest' . $path, $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), ['Content-Type: application/json']);
        try {
            $result = self::decodeObjects(json_decode($response['body'], false, 128, JSON_THROW_ON_ERROR));
            if ($result instanceof \stdClass) { $result = (array) $result; }
        } catch (\JsonException) { throw new AppError('REST_JSON', 'T2med hat eine unlesbare Antwort geliefert.', 502, $response['diagnostic']); }
        if (!is_array($result)) { throw new AppError('REST_SHAPE', 'T2med hat eine unerwartete Antwort geliefert.', 502, $response['diagnostic']); }
        if ($requireSuccess && ($result['successful'] ?? null) !== true) {
            $diagnostic = $response['diagnostic'];
            if ($diagnostic['operation'] === 'TEXT_SAVE') {
                $diagnostic = RestRejection::record($this->config, $result, $diagnostic);
            }
            throw new AppError('REST_REJECTED', 'T2med konnte den Vorgang nicht ausführen.', 502, $diagnostic);
        }
        return $result;
    }

    /** Only for endpoints declared void by T2med; ordinary requests still require JSON. */
    public function requestVoid(string $method, string $path): void
    {
        $this->send($method, '/aps/rest' . $path, null, ['Content-Type: application/json']);
    }

    private static function decodeObjects(mixed $value): mixed
    {
        // Preserve empty JSON objects in patient details and entry metadata when sending them back.
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);
            return $fields === [] ? new \stdClass() : array_map([self::class, 'decodeObjects'], $fields);
        }
        return is_array($value) ? array_map([self::class, 'decodeObjects'], $value) : $value;
    }

    public function upload(string $token, string $jpeg): void
    {
        $this->uploadContent($token, $jpeg, 'selfie.jpg', 'image/jpeg', 'PHOTO_TRANSFER');
    }

    public function uploadPdf(string $token, string $pdf, string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !str_starts_with($pdf, '%PDF-') || strlen($pdf) > 8 * 1024 * 1024) {
            throw new AppError('PRIVACY_PDF', 'Ungültiges Datenschutz-PDF.', 500);
        }
        $this->uploadContent($token, $pdf, 'datenschutz-' . $id . '.pdf', 'application/pdf', 'PRIVACY_TRANSFER');
    }

    public function documentBytes(string $contentPath): string
    {
        if (!str_starts_with($contentPath, 'cdn://')) { throw new AppError('PRIVACY_VERIFY', 'Dokument ist nicht über das eingerichtete CDN lesbar.', 502); }
        $path = substr($contentPath, 6);
        if ($path === '' || strlen($path) > 2048 || preg_match('/[\x00-\x20\x7f?#]/', $path) || stripos($path, ';jsessionid') !== false) {
            throw new AppError('PRIVACY_VERIFY', 'Ungültiger Dokumentverweis.', 502);
        }
        // Fixed configured host/port; never follow a response-supplied URL or redirect.
        $encoded = str_replace('%', '%25', str_replace('%2A', '*', urlencode($path)));
        $response = $this->send('GET', '/cdn/rest/delivery/' . $encoded, null, ['Accept: application/pdf'], true, 'PRIVACY_DOWNLOAD');
        if (!str_starts_with($response['body'], '%PDF-')) { throw new AppError('PRIVACY_VERIFY', 'Die Akte lieferte kein PDF.', 502); }
        return $response['body'];
    }

    private function uploadContent(string $token, string $bytes, string $filename, string $mediaType, string $operation): void
    {
        $boundary = 'checkin-' . bin2hex(random_bytes(20));
        // Match ContentProperties/MetaData used by T2med's CDNUploadManager.
        $properties = json_encode(['filename' => $filename, 'mediaType' => $mediaType, 'metaData' => ['values' => new \stdClass()]], JSON_THROW_ON_ERROR);
        $body = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"properties\"\r\nContent-Type: application/json\r\n\r\n" . $properties
            . "\r\n--" . $boundary . "\r\nContent-Disposition: form-data; name=\"stream\"; filename=\"" . $filename . "\"\r\nContent-Type: application/octet-stream\r\n\r\n" . $bytes . "\r\n--" . $boundary . "--\r\n";
        $deliveryToken = preg_replace('#^cdn://#', '', $token);
        if ($deliveryToken === '' || strlen($deliveryToken) > 1024 || preg_match('/[\x00-\x20\x7f]/', $deliveryToken) || stripos($deliveryToken, ';jsessionid') !== false) {
            throw new AppError('PHOTO_TOKEN', 'Der Bild-Uploadtoken hat ein unerwartetes Format.', 502);
        }
        // T2med: URLUtil.encodeURLpart (Java URLEncoder) THEN RESTEasy resolveTemplate,
        // which encodes percent signs again. A single encoded slash is not equivalent.
        $encodedToken = str_replace('%', '%25', str_replace('%2A', '*', urlencode($deliveryToken)));
        $this->send('PUT', '/cdn/rest/delivery/' . $encodedToken, $body, ['Content-Type: multipart/form-data; boundary=' . $boundary], true, $operation);
    }

    private static function operation(string $method, string $path, bool $cdn): string
    {
        if ($cdn) { return 'PHOTO_TRANSFER'; }
        // Fixed labels only: never log URLs, session UUIDs, patient IDs or query values.
        $path = explode('?', $path, 2)[0];
        if ($method === 'GET' && preg_match('#^/aps/rest/praxis/versichertenkarte/einlesen/[A-Za-z0-9-]{8,100}/(einlesen|auswerfen)$#D', $path, $matches)) {
            return $matches[1] === 'auswerfen' ? 'CARD_RELEASE' : 'CARD_READ';
        }
        if ($method === 'GET' && preg_match('#^/aps/rest/praxis/karteikarte/text/[a-fA-F0-9]{20,80}/read$#D', $path)) { return 'TEXT_VERIFY'; }
        if ($method === 'POST' && preg_match('#^/aps/rest/praxis/karteikarte/patient/[a-fA-F0-9]{20,80}/byids$#D', $path)) { return 'TEXT_PATIENT_VERIFY'; }
        if ($method === 'POST' && preg_match('#^/aps/rest/praxis/karteikarte/patient/[a-fA-F0-9]{20,80}/gesperrt$#D', $path)) { return 'PRIVACY_ACCESS'; }
        $labels = [
            'GET /wartezimmer/eintraege/notificationfilter' => 'LOGIN_CHECK',
            'GET /praxis/praxisstruktur/kontextauswaehlen/arztrollenbehandlungorte' => 'CONTEXT_READ',
            'GET /wartezimmer/bereiche/all' => 'ROOMS_READ',
            'POST /wartezimmer/eintraege/all' => 'ENTRIES_READ',
            'POST /wartezimmer/eintraege/add' => 'ENTRY_ADD',
            'POST /wartezimmer/eintraege/move' => 'ENTRY_MOVE',
            'POST /wartezimmer/eintraege/update' => 'ENTRY_UPDATE',
            'POST /kartenlesegeraete/verwalten/kartenlesegeraete' => 'READERS_READ',
            'POST /kartenlesegeraete/verwalten/testen' => 'READER_TEST',
            'GET /praxis/versichertenkarte/einlesen/terminalverbinden' => 'READER_CONNECT',
            'GET /praxis/versichertenkarte/einlesen/anfordern' => 'CARD_SESSION',
            'POST /praxis/versichertenkarte/pruefen/pruefen' => 'PATIENT_IDENTIFY',
            'POST /praxis/patient/detailsbearbeiten/create/details/versichertenkarte' => 'PATIENT_PREFILL',
            'POST /praxis/patient/detailsbearbeiten/erfasse/details' => 'PATIENT_CREATE',
            'POST /praxis/patient/detailsbearbeiten/patienthataktuellenfall' => 'CASE_READ',
            'POST /praxis/patient/detailsbearbeiten/find/details' => 'CONTACT_READ',
            'POST /praxis/patient/detailsbearbeiten/updateTelefonnummer' => 'CONTACT_PHONE',
            'POST /praxis/patient/detailsbearbeiten/updateEmailadressen' => 'CONTACT_EMAIL',
            'POST /praxis/patient/detailsbearbeiten/aktualisiere/details' => 'CONSENT_SAVE',
            'GET /praxis/karteikarte/allestandardsymbolnamen' => 'SMS_PIN_CATALOG',
            'POST /praxis/karteikarte/symbolaendern' => 'SMS_PIN_SAVE',
            'GET /praxis/karteikarte/text/create' => 'TEXT_PREPARE',
            'POST /praxis/karteikarte/text/insert' => 'TEXT_SAVE',
            'POST /praxis/karteikarte/dokumentationbearbeitenvorgang' => 'TEXT_REF_READ',
            'POST /praxis/koerpermass/erzeugen' => 'MEASUREMENT_PREPARE',
            'POST /praxis/koerpermass/anlegen' => 'MEASUREMENT_SAVE',
            'POST /praxis/koerpermass/holen' => 'MEASUREMENT_VERIFY',
            'POST /praxis/koerpermass/allekoerpermasseintraegeholen' => 'MEASUREMENT_LIST',
            'POST /praxis/behandlungsfaelle/faellefuerpatient' => 'ALLERGY_CASE',
            'POST /verordnung/allergien/verwalten/loadstructured' => 'ALLERGY_READ',
            'POST /verordnung/allergien/verwalten/loadunstructured' => 'ALLERGY_TEXT_READ',
            'POST /verordnung/allergien/verwalten/save' => 'ALLERGY_SAVE',
            'POST /praxis/patient/kopf/uebersicht/lesedaten' => 'PHOTO_STATUS',
            'GET /praxis/verweis/bildeintrag/upload/token' => 'PHOTO_TOKEN',
            'POST /praxis/verweis/bildeintrag/upload/passbild' => 'PHOTO_SAVE',
            'POST /praxis/karteikarte/all' => 'PRIVACY_RECORDS',
            'POST /praxis/verweis/dokumentverweis/update' => 'PRIVACY_SAVE',
            'POST /praxis/verweis/dokumentverweis/find' => 'PRIVACY_VERIFY',
        ];
        return str_starts_with($path, '/aps/rest/') ? ($labels[$method . ' ' . substr($path, 9)] ?? 'OTHER') : 'OTHER';
    }

    private function send(string $method, string $path, ?string $body, array $headers, bool $cdn = false, ?string $operation = null): array
    {
        $diagnostic = ['operation' => $operation ?? self::operation($method, $path, $cdn), 'http_status' => 0,
            'content_type' => 'absent', 'response_bytes' => 0];
        $host = $this->config->get('t2med.server');
        if (str_contains($host, ':')) { $host = '[' . $host . ']'; }
        $port = $this->config->get($cdn ? 't2med.cdn_port' : 't2med.rest_port');
        $handle = curl_init('https://' . $host . ':' . $port . $path);
        if ($handle === false) { throw new AppError('CURL_INIT', 'REST-Verbindung nicht verfügbar.', 503, $diagnostic); }
        $response = '';
        $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->user . ':' . $this->password, CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'Expect:'], $headers),
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $this->config->get('t2med.timeout_seconds'),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => $this->config->get('t2med.verify_tls'), CURLOPT_SSL_VERIFYHOST => $this->config->get('t2med.verify_tls') ? 2 : 0,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 16 * 1024 * 1024) { return 0; }
                $response .= $chunk; return strlen($chunk);
            }];
        if ($body !== null) { $options[CURLOPT_POSTFIELDS] = $body; }
        $ca = $this->config->get('t2med.ca_file');
        if ($ca !== '') { $options[CURLOPT_CAINFO] = $ca; }
        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle); $http = curl_getinfo($handle, CURLINFO_RESPONSE_CODE); $errno = curl_errno($handle);
        $type = strtolower(trim(explode(';', (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE), 2)[0]));
        $diagnostic['http_status'] = $http;
        $diagnostic['response_bytes'] = strlen($response);
        $diagnostic['content_type'] = $type === '' ? 'absent' : (in_array($type,
            ['application/json', 'application/problem+json', 'text/plain', 'text/html', 'application/octet-stream'], true) ? $type : 'other');
        unset($handle);
        if ($http === 401) { throw new AppError('T2_AUTH', 'T2med-Anmeldung fehlgeschlagen oder abgelaufen.', 401, $diagnostic); }
        if ($http === 403) { throw new AppError('T2_RIGHTS', 'Dem angemeldeten Benutzer fehlen T2med-Rechte.', 403, $diagnostic); }
        if ($ok === false) {
            throw new AppError('REST_TRANSPORT_' . $errno, 'Die Antwort von T2med fehlt. Der Vorgang darf nicht ungeprüft wiederholt werden.', 502, $diagnostic);
        }
        if ($http < 200 || $http >= 300) { throw new AppError('REST_HTTP_' . $http, 'T2med meldet einen Verbindungsfehler (HTTP ' . $http . ').', 502, $diagnostic); }
        return ['body' => $response, 'diagnostic' => $diagnostic];
    }
}
