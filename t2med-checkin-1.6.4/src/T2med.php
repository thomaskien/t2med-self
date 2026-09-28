<?php
declare(strict_types=1);
namespace Checkin;

final class T2med
{
    private array $context = [];
    public function __construct(private Config $config, private RestClient $rest) {}

    public static function ref(mixed $ref): array
    {
        if (!is_array($ref) || !is_string($ref['objectId']['id'] ?? null) || !preg_match('/^[a-fA-F0-9]{20,80}$/D', $ref['objectId']['id'])
            || !is_int($ref['revision'] ?? null) || $ref['revision'] < 0) { throw new AppError('T2_REF', 'T2med lieferte eine unvollständige Referenz.', 502); }
        return ['objectId' => ['id' => $ref['objectId']['id']], 'revision' => $ref['revision']];
    }
    public static function list(array $data, string $field): array
    {
        if (!isset($data[$field]) || !is_array($data[$field]) || !array_is_list($data[$field])) { throw new AppError('T2_LIST', 'T2med lieferte eine unvollständige Liste: ' . $field, 502); }
        return $data[$field];
    }
    public function discover(): array
    {
        $this->rest->request('GET', '/wartezimmer/eintraege/notificationfilter', null, false);
        return ['rooms' => $this->rooms(),
            'readers' => self::list($this->rest->request('POST', '/kartenlesegeraete/verwalten/kartenlesegeraete', ['station' => null]), 'kartenlesegeraete'),
            'context' => $this->rest->request('GET', '/praxis/praxisstruktur/kontextauswaehlen/arztrollenbehandlungorte')];
    }
    public function context(): array
    {
        if ($this->context === []) {
            $data = $this->rest->request('GET', '/praxis/praxisstruktur/kontextauswaehlen/arztrollenbehandlungorte');
            foreach (['arztrollen' => ['t2med.doctor_role_id', 'arztrolleRef'], 'behandlungsorte' => ['t2med.treatment_location_id', 'behandlungsortRef']] as $field => [$key, $target]) {
                $matches = array_values(array_filter(self::list($data, $field), fn($r) => ($r['ref']['objectId']['id'] ?? null) === $this->config->get($key)));
                if (count($matches) !== 1) { throw new AppError('T2_CONTEXT', 'Der eingerichtete technische Kontext ist für diesen Benutzer nicht verfügbar.', 403); }
                $this->context[$target] = self::ref($matches[0]['ref']);
            }
        }
        return $this->context;
    }
    public function preflight(): void
    {
        $this->context(); $this->reader();
        $rooms = $this->rooms();
        foreach ($this->config->get('categories') as $category) {
            if (isset($category['room'])) { $this->room($category['room'], $rooms); }
        }
        $this->entries($this->room($this->config->category('new_patient')['room'], $rooms));
    }
    private function reader(): array
    {
        $data = $this->rest->request('POST', '/kartenlesegeraete/verwalten/kartenlesegeraete', ['station' => null]);
        $matches = array_values(array_filter(self::list($data, 'kartenlesegeraete'), fn($r) => ($r['geraetename'] ?? '') === $this->config->get('reader.name')));
        if (count($matches) !== 1) { throw new AppError('T2_READER', 'Der konfigurierte Kartenleser fehlt oder ist nicht eindeutig.', 503); }
        $variants = array_values(array_filter(array_intersect_key($matches[0], array_flip(['lanKartenlesegeraet', 'ctApiKartenlesegeraet', 'seriellesKartenlesegeraet'])), static fn($v) => $v !== null));
        if (count($variants) !== 1) { throw new AppError('T2_READER_TYPE', 'Der Kartenlesertyp ist nicht eindeutig.', 503); }
        return self::ref($variants[0]['ref'] ?? null);
    }
    public function readCard(callable $saveCardSession): array
    {
        $reader = $this->reader(); $terminal = rawurlencode($this->config->get('reader.name'));
        if ($this->config->get('reader.connect_before_read')) { $this->rest->request('GET', '/praxis/versichertenkarte/einlesen/terminalverbinden?terminalName=' . $terminal, null, false); }
        if ($this->config->get('reader.test_before_read')) { $this->rest->request('POST', '/kartenlesegeraete/verwalten/testen', ['kartenlesegeraetRef' => $reader]); }
        for ($i = 0; $i < $this->config->get('reader.read_attempts'); $i++) {
            $result = $this->rest->request('GET', '/praxis/versichertenkarte/einlesen/anfordern?terminalName=' . $terminal, null, false);
            $session = $result['sitzungUuid'] ?? null;
            if (!is_string($session) || !preg_match('/^[A-Za-z0-9-]{8,100}$/D', $session)) { throw new AppError('CARD_SESSION', 'Die Kartensitzung konnte nicht eröffnet werden.'); }
            $saveCardSession($session);
            $result = $this->rest->request('GET', '/praxis/versichertenkarte/einlesen/' . rawurlencode($session) . '/einlesen', null, false);
            if (($result['successful'] ?? null) === true) { return self::ref($result['versichertenkarteRef'] ?? null); }
            // Only a definite negative response may trigger another read; never retry an uncertain import.
            $this->eject($session); $saveCardSession(null);
        }
        throw new AppError('CARD_UNREADABLE', 'Die Karte konnte nicht gelesen werden. Bitte melden Sie sich am Empfang.');
    }
    public function eject(?string $session): void
    {
        // VersichertenkarteEinlesenBoundary.karteAuswerfen returns void, usually HTTP 204.
        if ($session !== null) { $this->rest->requestVoid('GET', '/praxis/versichertenkarte/einlesen/' . rawurlencode($session) . '/auswerfen'); }
    }
    public function identify(array $card): array
    {
        $check = $this->rest->request('POST', '/praxis/versichertenkarte/pruefen/pruefen', ['kontextTO' => $this->context(),
            'versichertenkarteRef' => $card, 'patientRefTO' => null, 'abgeglichen' => false], false);
        // As in 1.3, business validation can report missing case while returning a resolved patient.
        if (isset($check['patientRef']['objectId']['id'])) {
            $patient = self::ref($check['patientRef']); $new = false;
        } else {
            if (self::ref($check['versichertenkarteRef'] ?? null)['objectId']['id'] !== $card['objectId']['id']) { throw new AppError('PATIENT_UNKNOWN', 'Die Karte ist keinem eindeutigen Patienten zugeordnet.'); }
            $prefill = $this->rest->request('POST', '/praxis/patient/detailsbearbeiten/create/details/versichertenkarte', $card);
            if (($prefill['patientMehrfachGefunden'] ?? false) === true) { throw new AppError('PATIENT_DUPLICATE', 'Mehrere Patienten gefunden. Bitte am Empfang klären.'); }
            $details = $prefill['details'] ?? null;
            if (!is_array($details)) { throw new AppError('PATIENT_DETAILS', 'Die Patientenvorbelegung fehlt.', 502); }
            $patient = self::ref($details['patientRef'] ?? null);
            $created = $this->rest->request('POST', '/praxis/patient/detailsbearbeiten/erfasse/details', [
                'kontext' => $this->patientContext($patient), 'versichertenkarteRef' => $card,
                'details' => $details, 'sucheVorhandenePatienten' => true]);
            if (($created['patientMehrfachGefunden'] ?? false) === true) { throw new AppError('PATIENT_DUPLICATE', 'Mehrere Patienten gefunden. Bitte am Empfang klären.'); }
            $patient = self::ref($created['patientTO']['ref'] ?? $created['details']['patientRef'] ?? null); $new = true;
        }
        $hasCase = false;
        if (!$new) {
            $result = $this->rest->request('POST', '/praxis/patient/detailsbearbeiten/patienthataktuellenfall', $patient);
            $found = null;
            foreach (['value', 'result', 'booleanValue', 'boolValue', 'wert'] as $key) {
                if (is_bool($result[$key] ?? null)) { $found = $result[$key]; break; }
            }
            if ($found === null) {
                $flags = array_values(array_filter(array_diff_key($result, ['successful' => true]), 'is_bool'));
                if (count($flags) !== 1) { throw new AppError('PATIENT_CASE', 'Der Fallstatus ist nicht eindeutig.', 502); }
                $found = $flags[0];
            }
            $hasCase = $found;
        }
        return ['ref' => $patient, 'new' => $new, 'has_case' => $hasCase];
    }
    private function patientContext(array $patient): array
    {
        return $this->context() + ['patientRef' => $patient, 'behandlungsfallRef' => null, 'stationRef' => null];
    }
    public function contactSnapshot(array $patient): array
    {
        $result = $this->rest->request('POST', '/praxis/patient/detailsbearbeiten/find/details', $this->patientContext($patient));
        $details = $result['details'] ?? [];
        if (!is_array($details) || self::ref($details['patientRef'] ?? null)['objectId']['id'] !== $patient['objectId']['id']) {
            throw new AppError('CONTACT_PATIENT', 'Kontaktdaten gehören nicht zum aktuellen Vorgang.', 502);
        }
        return ContactData::snapshot($details);
    }
    public function saveContacts(array $patient, array $snapshot, array $change, WriteJournal $journal): void
    {
        $next = $change['next'];
        if ($this->contactSnapshot($patient) != $snapshot) { throw new AppError('CONTACT_CHANGED', 'Kontaktdaten wurden zwischenzeitlich geändert. Bitte am Empfang klären.', 409); }
        if ($change['notes'] !== []) {
            $this->writeText($patient, $this->config->get('contacts.note_code'), "Patientenangaben am Terminal – Änderungsauftrag:\n" . implode("\n\n", $change['notes']));
            $journal->confirmed('N');
        }
        // Each endpoint replaces one whole list. Preserve every untouched list entry and field.
        foreach (['phones', 'emails'] as $field) {
            if ($next[$field] == $snapshot[$field]) { continue; }
            if ($this->contactSnapshot($patient)[$field] != $snapshot[$field]) { throw new AppError('CONTACT_CHANGED', 'Kontaktdaten wurden zwischenzeitlich geändert. Bitte am Empfang klären.', 409); }
            $phone = $field === 'phones';
            $this->rest->request('POST', '/praxis/patient/detailsbearbeiten/' . ($phone ? 'updateTelefonnummer' : 'updateEmailadressen'),
                ['patientRef' => $patient, $phone ? 'telefonnummerTOList' : 'adresseTOList' => $next[$field]]);
            $saved = $this->contactSnapshot($patient)[$field];
            $valueKey = $phone ? 'nummer' : 'emailadresse';
            if (count($saved) !== count($next[$field])) { throw new AppError('CONTACT_VERIFY', 'Kontaktübernahme konnte nicht bestätigt werden.', 502); }
            foreach ($saved as $i => $row) {
                foreach ([$valueKey, 'typ', 'kategorie', 'kommentar'] as $key) {
                    if (($row[$key] ?? null) !== ($next[$field][$i][$key] ?? null)) { throw new AppError('CONTACT_VERIFY', 'Kontaktübernahme konnte nicht bestätigt werden.', 502); }
                }
            }
            $journal->confirmed($field);
        }
    }
    public function writeText(array $patient, string $code, string $text, ?int $expectedType = null): array
    {
        $created = $this->rest->request('GET', '/praxis/karteikarte/text/create?kuerzel=' . rawurlencode($code));
        $entry = $created['texteintragTO'] ?? null;
        if (!is_array($entry) || ($entry['kuerzel'] ?? null) !== $code
            || ($expectedType !== null && ($entry['fachinformationstypTO']['fachinformationstyp'] ?? null) !== $expectedType)) {
            throw new AppError('TEXT_CODE', 'Das konfigurierte Karteikartenkürzel passt nicht zum vorgesehenen Eintrag.', 503);
        }
        $entry['informationszeitpunkt'] = (int) round(microtime(true) * 1000);
        $entry['decoratedString'] = ['text' => $text, 'decorations' => []];
        // APS create returns an empty RefTO. Insert returns a KARTEI row, not the text's ID.
        $inserted = $this->rest->request('POST', '/praxis/karteikarte/text/insert', ['kontext' => $this->patientContext($patient), 'texteintrag' => $entry]);
        $rowRef = self::ref($inserted['karteieintragZeileDTO']['ref'] ?? null);
        $rows = $this->rest->request('POST', '/praxis/karteikarte/patient/' . rawurlencode($patient['objectId']['id']) . '/byids', [$rowRef['objectId']], false);
        if (!array_is_list($rows) || count($rows) !== 1 || self::ref($rows[0]['ref'] ?? null)['objectId']['id'] !== $rowRef['objectId']['id']) {
            throw new AppError('TEXT_VERIFY', 'Der Akteneintrag konnte nicht dem Patienten zugeordnet werden.', 502);
        }
        // This endpoint only resolves the existing row's metadata; it does not edit/create a case.
        $mapping = $this->rest->request('POST', '/praxis/karteikarte/dokumentationbearbeitenvorgang',
            ['kontext' => $this->patientContext($patient), 'objectId' => $rowRef['objectId']]);
        $ref = self::ref($mapping['fachinformationRef'] ?? null);
        $saved = $this->rest->request('GET', '/praxis/karteikarte/text/' . rawurlencode($ref['objectId']['id']) . '/read')['texteintragTO'] ?? null;
        if (!is_array($saved) || self::ref($saved['ref'] ?? null)['objectId']['id'] !== $ref['objectId']['id']
            || ($saved['decoratedString']['text'] ?? null) !== $text || ($saved['kuerzel'] ?? null) !== $code
            || ($expectedType !== null && ($saved['fachinformationstypTO']['fachinformationstyp'] ?? null) !== $expectedType)) {
            throw new AppError('TEXT_VERIFY', 'Der Akteneintrag konnte nicht bestätigt werden.', 502);
        }
        return self::ref($saved['ref']);
    }
    public function saveMeasurements(array $patient, ?float $height, ?float $weight): void
    {
        if ($height === null && $weight === null) { return; }
        $context = $this->patientContext($patient);
        $load = fn(): array => self::list($this->rest->request('POST', '/praxis/koerpermass/allekoerpermasseintraegeholen',
            ['kontext' => $context, 'koerpermassRef' => null]), 'koerpermassListe');
        $before = [];
        foreach ($load() as $row) { $before[self::ref($row['koerpermassTO']['ref'] ?? null)['objectId']['id']] = true; }
        // BEFUND_KOERPERMASS = 17 in 26.8.0; never prefill old measurements as new patient answers.
        $created = $this->rest->request('POST', '/praxis/koerpermass/erzeugen', ['kontext' => $context,
            'kuerzel' => null, 'mitVorbelegen' => false, 'informationszeitpunkt' => (int) round(microtime(true) * 1000), 'fachinformationstyp' => 17]);
        $entry = $created['koerpermassTO'] ?? null; $type = $created['fachinformationstypTO'] ?? null;
        if (!is_array($entry) || !is_array($type) || ($type['fachinformationstyp'] ?? null) !== 17) { throw new AppError('MEASUREMENT_TYPE', 'Körpermaße konnten nicht vorbereitet werden.', 502); }
        $entry['informationszeitpunkt'] = (int) round(microtime(true) * 1000);
        $entry['groesseInCM'] = $height; $entry['gewichtInKG'] = $weight;
        $entry['anamnestisch'] = true; $entry['bemerkung'] = 'Patienten-Selbstauskunft am Check-in-Terminal'; $type['anamnestisch'] = true;
        $this->rest->request('POST', '/praxis/koerpermass/anlegen', ['kontext' => $context, 'koerpermassTO' => $entry, 'fachinformationstypTO' => $type]);
        // anlegen returns the input DTO (still with an empty ref). Resolve exactly one new
        // matching entry in this patient's list, never an old measurement or a guessed ID.
        $matches = [];
        foreach ($load() as $row) {
            $candidate = $row['koerpermassTO'] ?? null;
            $candidateRef = self::ref($candidate['ref'] ?? null);
            if (!isset($before[$candidateRef['objectId']['id']]) && self::sameMeasurement($candidate, $entry)) {
                $matches[] = $candidateRef;
            }
        }
        if (count($matches) !== 1) { throw new AppError('MEASUREMENT_VERIFY', 'Neue Körpermaße konnten nicht eindeutig bestätigt werden.', 502); }
        $ref = $matches[0];
        $saved = $this->rest->request('POST', '/praxis/koerpermass/holen', ['kontext' => $context, 'koerpermassRef' => $ref])['koerpermassTO'] ?? null;
        if (!is_array($saved) || self::ref($saved['ref'] ?? null)['objectId']['id'] !== $ref['objectId']['id']
            || !self::sameMeasurement($saved, $entry)) {
            throw new AppError('MEASUREMENT_VERIFY', 'Körpermaße konnten nicht bestätigt werden.', 502);
        }
    }
    private static function sameMeasurement(array $saved, array $entry): bool
    {
        foreach (['informationszeitpunkt', 'groesseInCM', 'gewichtInKG', 'anamnestisch', 'bemerkung'] as $key) {
            if (!array_key_exists($key, $saved)) { return false; }
            if (in_array($key, ['groesseInCM', 'gewichtInKG'], true) && $entry[$key] !== null) {
                if ((!is_int($saved[$key]) && !is_float($saved[$key])) || (float) $saved[$key] !== (float) $entry[$key]) { return false; }
            } elseif ($saved[$key] !== $entry[$key]) { return false; }
        }
        return true;
    }
    private function currentAllergyCase(array $patient): ?array
    {
        $result = $this->rest->request('POST', '/praxis/behandlungsfaelle/faellefuerpatient', $patient);
        $groups = $result['zeilenMaps'] ?? null;
        if (!is_array($groups) && !($groups instanceof \stdClass)) { throw new AppError('ALLERGY_CASE', 'Fallübersicht ist unvollständig.', 502); }
        $current = [];
        foreach ((array) $groups as $rows) {
            if (!is_array($rows) || !array_is_list($rows)) { throw new AppError('ALLERGY_CASE', 'Fallübersicht ist unvollständig.', 502); }
            foreach ($rows as $row) {
                if (!is_array($row) || !is_bool($row['aktuell'] ?? null) || !is_bool($row['fallUngueltig'] ?? null)
                    || self::ref($row['patient'] ?? null)['objectId']['id'] !== $patient['objectId']['id']) {
                    throw new AppError('ALLERGY_CASE', 'Fallzuordnung ist nicht eindeutig.', 502);
                }
                if ($row['aktuell'] && !$row['fallUngueltig']) {
                    $ref = self::ref($row['ref'] ?? null); $current[$ref['objectId']['id']] = $ref;
                }
            }
        }
        // No case creation, no choice of doctor/location and no arbitrary selection among cases.
        return count($current) === 1 ? array_values($current)[0] : null;
    }
    public function saveAllergies(array $patient, string $text, array $types): void
    {
        if ($text === '' || $types === []) { return; } // An unanswered question never removes or excludes an allergy.
        $load = fn(): array => self::list($this->rest->request('POST', '/verordnung/allergien/verwalten/loadstructured', ['patientRef' => $patient]), 'allergien');
        $name = implode(', ', $types);
        $marker = 'Patientenangabe am Terminal; Details siehe ana. Bericht-ID: ' . hash('sha256', $text);
        foreach ($load() as $row) {
            if (($row['substanzName'] ?? null) === $name && ($row['kommentar'] ?? null) === $marker
                && ($row['sicherheit'] ?? null) !== 3 && ($row['sicherheit'] ?? null) !== 4) { return; }
        }
        $normalize = static fn(string $value): string => trim(preg_replace('/\s+/u', ' ', mb_strtolower($value)));
        foreach (self::list($this->rest->request('POST', '/verordnung/allergien/verwalten/loadunstructured', ['patientRef' => $patient]), 'allergien') as $row) {
            if (is_string($row['beschreibung'] ?? null) && $normalize($row['beschreibung']) === $normalize($text)) { return; }
        }
        $case = $this->currentAllergyCase($patient);
        if ($case === null || mb_strlen($name) > 255) { $this->saveAllergyText($patient, $text); return; }
        $context = $this->patientContext($patient); $context['behandlungsfallRef'] = $case;
        // Omit ref so APS keeps its native new-object RefTO constructor default.
        $entry = ['substanzName' => $name, 'befundart' => 5, 'sicherheit' => 1, 'typ' => 1,
            'informationszeitpunkt' => (int) round(microtime(true) * 1000), 'kommentar' => $marker];
        // The selected groups are copied literally; no invented substance codes or severity.
        if ($this->currentAllergyCase($patient) !== $case) { throw new AppError('ALLERGY_CASE_CHANGED', 'Fallzuordnung wurde zwischenzeitlich geändert.', 409); }
        $result = $this->rest->request('POST', '/verordnung/allergien/verwalten/save', ['kontext' => $context, 'allergie' => $entry]);
        $saved = $result['gespeicherteAllergie'] ?? null;
        $ref = self::ref($saved['ref'] ?? null);
        foreach ($load() as $row) {
            if (($row['ref']['objectId']['id'] ?? null) !== $ref['objectId']['id']) { continue; }
            foreach (['substanzName', 'befundart', 'sicherheit', 'typ', 'kommentar'] as $key) {
                if (($row[$key] ?? null) !== $entry[$key]) { throw new AppError('ALLERGY_VERIFY', 'Die Allergieübernahme konnte nicht bestätigt werden.', 502); }
            }
            return;
        }
        throw new AppError('ALLERGY_VERIFY', 'Die Allergieübernahme konnte nicht bestätigt werden.', 502);
    }
    public function saveAllergyText(array $patient, string $text): void
    {
        if ($text === '') { return; }
        $load = fn(): array => self::list($this->rest->request('POST', '/verordnung/allergien/verwalten/loadunstructured', ['patientRef' => $patient]), 'allergien');
        $normalize = static fn(string $value): string => trim(preg_replace('/\s+/u', ' ', mb_strtolower($value)));
        foreach ($load() as $row) {
            if (is_string($row['beschreibung'] ?? null) && $normalize($row['beschreibung']) === $normalize($text)) { return; }
        }
        // Approved fallback without case: a genuine DIAGNOSE_ALLERGIE text, NOT just ANA.
        $ref = $this->writeText($patient, $this->config->get('questionnaires.allergy_code'), $text, 26);
        foreach ($load() as $row) {
            if (($row['ref']['objectId']['id'] ?? null) === $ref['objectId']['id']) { return; }
        }
        throw new AppError('ALLERGY_VERIFY', 'Die Allergie ist in der Allergiefunktion noch nicht bestätigt.', 502);
    }
    public function photoStatus(array $patient): array
    {
        // PatientUebersichtModul uses EnumWithId JSON, PATIENTENBILD has id 4 in 26.8.0.
        $result = $this->rest->request('POST', '/praxis/patient/kopf/uebersicht/lesedaten', ['kontext' => $this->patientContext($patient), 'module' => [4]]);
        $module = $result['patientenbild'] ?? null;
        if (!is_array($module) || !is_bool($module['sichtbar'] ?? null)) { throw new AppError('PHOTO_STATUS', 'Der Bildstatus konnte nicht eindeutig gelesen werden.', 502); }
        if (self::ref($module['patientRef'] ?? null)['objectId']['id'] !== $patient['objectId']['id']) { throw new AppError('PHOTO_PATIENT', 'Der Bildstatus gehört nicht zum aktuellen Patienten.', 502); }
        $current = self::ref($module['patientRef']);
        if (!$module['sichtbar']) { return ['ask' => false, 'exists' => false, 'patient' => $current]; }
        if (!array_key_exists('patientenbild', $module) || ($module['patientenbild'] !== null && !is_string($module['patientenbild']))) { throw new AppError('PHOTO_FIELD', 'Der Bildstatus ist unvollständig.', 502); }
        $url = $module['patientenbild'] ?? '';
        $exists = $url !== '' && !str_contains($url, '@static/portraits/') && !str_contains($url, '/static/portraits/');
        return ['ask' => !$exists, 'exists' => $exists, 'patient' => $current];
    }
    public function uploadPhoto(array $patient, string $jpeg): void
    {
        $status = $this->photoStatus($patient);
        if ($status['exists']) { return; } // A staff member may have added a photo in the meantime.
        if (!$status['ask']) { throw new AppError('PHOTO_HIDDEN', 'Das Patientenbild ist für diesen Benutzer gesperrt.', 403); }
        $result = $this->rest->request('GET', '/praxis/verweis/bildeintrag/upload/token');
        $token = $result['uploadToken'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 1024) { throw new AppError('PHOTO_TOKEN', 'Der Bild-Upload konnte nicht vorbereitet werden.', 502); }
        $this->rest->upload($token, $jpeg);
        // Contact changes (and other actors) may have advanced the patient's revision.
        // Refresh immediately before the write. APS still performs the optimistic-lock check;
        // never retry a rejected/ambiguous write and never overwrite a meanwhile-added photo.
        $status = $this->photoStatus($patient);
        if ($status['exists']) { return; }
        if (!$status['ask']) { throw new AppError('PHOTO_HIDDEN', 'Das Patientenbild ist für diesen Benutzer gesperrt.', 403); }
        $this->rest->request('POST', '/praxis/verweis/bildeintrag/upload/passbild', ['uploadToken' => $token, 'patientRef' => $status['patient']]);
        if (!$this->photoStatus($patient)['exists']) { throw new AppError('PHOTO_VERIFY', 'Das gespeicherte Patientenbild konnte nicht bestätigt werden.', 502); }
    }

    public function privacyIdentity(array $patient): array
    {
        $details = $this->rest->request('POST', '/praxis/patient/detailsbearbeiten/find/details', $this->patientContext($patient))['details'] ?? null;
        if (!is_array($details) || self::ref($details['patientRef'] ?? null)['objectId']['id'] !== $patient['objectId']['id']) {
            throw new AppError('PRIVACY_PATIENT', 'Patientenzuordnung konnte nicht bestätigt werden.', 502);
        }
        $name = $details['personendatenDTO']['namensdaten'] ?? [];
        $number = $details['weitereDatenDTO']['patientennummer'] ?? null;
        if (!is_string($name['nachname'] ?? null) || trim($name['nachname']) === '' || !is_string($name['vorname'] ?? null)
            || !is_int($number) || $number < 1) { throw new AppError('PRIVACY_PATIENT', 'Patientenname oder Patientennummer fehlt.', 502); }
        return ['name' => trim($name['vorname'] . ' ' . $name['nachname']), 'number' => (string) $number,
            'ref' => self::ref($details['patientRef'])];
    }
    private function requireOpenRecord(array $patient): void
    {
        $result = $this->rest->request('POST', '/praxis/karteikarte/patient/' . $patient['objectId']['id'] . '/gesperrt');
        // Native EAkteSperrungPatientStatusTyp: 0 keine, 4 Benutzer nicht gesperrt.
        if (!in_array($result['sperrungPatientStatusTyp'] ?? null, [0, 4], true)) {
            throw new AppError('PRIVACY_RECORD_ACCESS', 'Datenschutzstatus der Akte ist nicht zugänglich.', 403);
        }
    }
    private function privacyRows(array $patient): array
    {
        $patient = self::ref($patient); $this->requireOpenRecord($patient);
        $rows = $this->rest->request('POST', '/praxis/karteikarte/all', ['kontext' => $this->patientContext($patient)], false);
        if (!array_is_list($rows) || count($rows) > 50000) { throw new AppError('PRIVACY_RECORDS', 'Die Aktenliste konnte nicht vollständig geprüft werden.', 502); }
        $this->requireOpenRecord($patient); // /all silently returns [] for a locked record.
        $seen = [];
        foreach ($rows as $row) {
            $id = self::ref($row['ref'] ?? null)['objectId']['id'];
            if (isset($seen[$id]) || !is_int($row['fachinformationstypTO']['fachinformationstyp'] ?? null)) {
                throw new AppError('PRIVACY_RECORDS', 'Die Aktenliste ist nicht eindeutig lesbar.', 502);
            }
            $seen[$id] = true;
        }
        return $rows;
    }
    private function privacyDocument(array $patient, array $row): array
    {
        $ref = self::ref($row['ref']);
        $owned = $this->rest->request('POST', '/praxis/karteikarte/patient/' . $patient['objectId']['id'] . '/byids', [$ref['objectId']], false);
        if (!array_is_list($owned) || count($owned) !== 1 || self::ref($owned[0]['ref'] ?? null)['objectId']['id'] !== $ref['objectId']['id']
            || ($owned[0]['content']['text'] ?? null) !== ($row['content']['text'] ?? null)) {
            throw new AppError('PRIVACY_PATIENT', 'Dokument gehört nicht eindeutig zum aktuellen Patienten.', 502);
        }
        $mapping = $this->rest->request('POST', '/praxis/karteikarte/dokumentationbearbeitenvorgang',
            ['kontext' => $this->patientContext($patient), 'objectId' => $ref['objectId']]);
        $documentRef = self::ref($mapping['fachinformationRef'] ?? null);
        $doc = $this->rest->request('POST', '/praxis/verweis/dokumentverweis/find',
            ['kontext' => $this->patientContext($patient), 'dokumentverweisRef' => $documentRef])['dokumentverweisTO'] ?? null;
        if (!is_array($doc) || self::ref($doc['ref'] ?? null)['objectId']['id'] !== $documentRef['objectId']['id']
            || ($doc['fachinformationstyp'] ?? null) !== 75 || ($doc['text'] ?? null) !== ($row['content']['text'] ?? null)
            || !is_string($doc['verweis'] ?? null) || !str_starts_with($doc['verweis'], 'cdn://')) {
            throw new AppError('PRIVACY_VERIFY', 'Das Datenschutzdokument konnte nicht bestätigt werden.', 502);
        }
        return $doc;
    }
    public function privacySufficient(array $patient, string $minimum): bool
    {
        $rows = $this->privacyRows($patient);
        // Check every document before accepting any sufficient one: row order must
        // not hide an unfinished consent update behind an older valid PDF.
        foreach ($rows as $row) {
            $text = $row['content']['text'] ?? '';
            if (($row['fachinformationstypTO']['fachinformationstyp'] ?? null) === 75 && is_string($text)
                && PrivacyForm::recordVersion($text) !== null && preg_match('/^Check-in-Dokument: ([a-f0-9]{32})$/m', $text, $pending)
                && is_file($this->config->get('app.state_dir') . '/pending/' . $pending[1] . '.json.enc')) {
                throw new AppError('PRIVACY_PENDING', 'Eine frühere Datenschutzübertragung muss am Empfang geprüft werden.', 409,
                    ['report_id' => $pending[1]]);
            }
        }
        foreach ($rows as $row) {
            if (($row['fachinformationstypTO']['fachinformationstyp'] ?? null) !== 75) { continue; }
            $text = $row['content']['text'] ?? '';
            if (!is_string($text)) { throw new AppError('PRIVACY_RECORDS', 'Dokumentmetadaten sind unlesbar.', 502); }
            $version = PrivacyForm::recordVersion($text);
            if ($version === null || !PrivacyForm::sufficient($version, $minimum)) { continue; }
            $doc = $this->privacyDocument($patient, $row);
            // An entry without its actual PDF (or a damaged transfer) is not sufficient.
            $pdf = $this->rest->documentBytes($doc['verweis']);
            $hashCount = preg_match_all('/^PDF-SHA256: ([a-f0-9]{64})\r?$/m', $text, $hashes);
            if ((str_contains($text, 'PDF-SHA256:') && $hashCount !== 1)
                || ($hashCount === 1 && !hash_equals($hashes[1][0], hash('sha256', $pdf)))) {
                throw new AppError('PRIVACY_VERIFY', 'Datenschutz-PDF und Prüfsumme stimmen nicht überein.', 502);
            }
            return true;
        }
        return false;
    }
    public function savePrivacyDocument(array $patient, string $pdf, string $text, string $id): array
    {
        $this->requireOpenRecord($patient);
        $token = $this->rest->request('GET', '/praxis/verweis/bildeintrag/upload/token')['uploadToken'] ?? null;
        if (!is_string($token) || $token === '' || strlen($token) > 1024) { throw new AppError('PRIVACY_TOKEN', 'Dokument-Upload konnte nicht vorbereitet werden.', 502); }
        $this->rest->uploadPdf($token, $pdf, $id);
        $doc = ['ref' => ['objectId' => null, 'revision' => 0], 'fachinformationstyp' => 75,
            'gueltigkeitszeitpunkt' => (int) round(microtime(true) * 1000), 'anamnestisch' => false,
            'text' => $text, 'kuerzel' => 'dsgv', 'verweis' => $token, 'unzugeordnet' => false];
        $result = $this->rest->request('POST', '/praxis/verweis/dokumentverweis/update',
            ['kontext' => $this->patientContext($patient), 'uploadToken' => $token, 'dokumentverweis' => $doc, 'neuerEintrag' => true]);
        $savedRef = self::ref($result['dokumentverweis']['ref'] ?? null);
        $matches = array_values(array_filter($this->privacyRows($patient), static fn($r) =>
            ($r['fachinformationstypTO']['fachinformationstyp'] ?? null) === 75 && ($r['content']['text'] ?? null) === $text));
        if (count($matches) !== 1) { throw new AppError('PRIVACY_VERIFY', 'Der Datenschutz-Akteneintrag konnte nicht eindeutig bestätigt werden.', 502); }
        $saved = $this->privacyDocument($patient, $matches[0]);
        if (self::ref($saved['ref'])['objectId']['id'] !== $savedRef['objectId']['id']
            || !hash_equals(hash('sha256', $pdf), hash('sha256', $this->rest->documentBytes($saved['verweis'])))) {
            throw new AppError('PRIVACY_VERIFY', 'Die vollständige PDF-Ablage konnte nicht bestätigt werden.', 502);
        }
        return $matches[0];
    }

    private function consentDetails(array $patient): array
    {
        $details = $this->rest->request('POST', '/praxis/patient/detailsbearbeiten/find/details', $this->patientContext($patient))['details'] ?? null;
        if (!is_array($details) || self::ref($details['patientRef'] ?? null)['objectId']['id'] !== $patient['objectId']['id']) {
            throw new AppError('CONSENT_PATIENT', 'Patientenzuordnung der Einwilligung ist unklar.', 502);
        }
        foreach (['personendatenDTO', 'adressdatenDTO', 'kontaktdatenDTO', 'weitereDatenDTO'] as $key) {
            if (!is_array($details[$key] ?? null)) { throw new AppError('CONSENT_SHAPE', 'Vollständige Stammdaten für die Einwilligung fehlen.', 502); }
        }
        $contact = $details['kontaktdatenDTO'];
        if (!array_key_exists('benachrichtigungErlaubt', $contact)
            || ($contact['benachrichtigungErlaubt'] !== null && !is_bool($contact['benachrichtigungErlaubt']))) {
            throw new AppError('CONSENT_SHAPE', 'Benachrichtigungsstatus ist nicht eindeutig lesbar.', 502);
        }
        ContactData::snapshot($details); // Complete lists required; never send a partial DTO.
        return $details;
    }
    /** Read-only preflight. The plan is kept encrypted alongside the signed PDF. */
    public function preparePrivacyConsent(array $patient, array $answers): array
    {
        $details = $this->consentDetails($patient);
        $pin = $this->config->get($answers['sms'] ? 'privacy.sms_pin_allowed' : 'privacy.sms_pin_denied');
        $previous = [];
        if ($pin !== '') {
            $catalog = $this->rest->request('GET', '/praxis/karteikarte/allestandardsymbolnamen', null, false);
            if (!array_is_list($catalog) || !in_array($pin, $catalog, true)) {
                throw new AppError('SMS_PIN_MISSING', 'Der konfigurierte SMS-Pin fehlt im T2med-Katalog. Bitte am Empfang prüfen.', 503);
            }
            foreach ($this->privacyRows($patient) as $row) {
                // Only pins explicitly marked as app-managed on our own privacy documents.
                // Historical PDF/text stays intact, even when an old status pin is cleared.
                $text = $row['content']['text'] ?? '';
                if (($row['fachinformationstypTO']['fachinformationstyp'] ?? null) !== 75 || !is_string($text)
                    || PrivacyForm::recordVersion($text) === null
                    || !preg_match('/^Check-in-Dokument: [a-f0-9]{32}$/m', $text)
                    || !preg_match('/^SMS-Pin \(Check-in\): ([A-Za-z0-9][A-Za-z0-9_.-]{0,119}\.png)$/m', $text, $match)
                    || ($row['symbol'] ?? null) !== $match[1]) { continue; }
                $this->privacyDocument($patient, $row);
                $previous[] = $row;
            }
        }
        return ['email' => $answers['email'], 'sms' => $answers['sms'],
            'previous_email' => $details['kontaktdatenDTO']['benachrichtigungErlaubt'], 'pin' => $pin, 'previous_pins' => $previous];
    }
    public function savePrivacyConsent(array $patient, array $row, array $plan, WriteJournal $journal): void
    {
        // Fresh, complete DTO after PDF save. APS checks its exact patient revision.
        // The single-purpose setBenachrichtigungErlaubt endpoint can only set TRUE
        // and ignores its service validation, so it cannot implement current consent.
        $details = $this->consentDetails($patient);
        if ($details['kontaktdatenDTO']['benachrichtigungErlaubt'] !== $plan['previous_email']) {
            throw new AppError('CONSENT_CHANGED', 'Benachrichtigungsstatus wurde zwischenzeitlich geändert. Bitte am Empfang prüfen.', 409);
        }
        if ($plan['previous_email'] !== $plan['email']) {
            $details['kontaktdatenDTO']['benachrichtigungErlaubt'] = $plan['email'];
            $journal->annotate('consent_expected', $details);
            $this->rest->request('POST', '/praxis/patient/detailsbearbeiten/aktualisiere/details',
                ['kontext' => $this->patientContext(self::ref($details['patientRef'])), 'details' => $details]);
            $saved = $this->consentDetails($patient);
            $differences = ConsentCheck::differences($details, $saved);
            if ($saved['kontaktdatenDTO']['benachrichtigungErlaubt'] !== $plan['email'] || $differences !== []) {
                $journal->annotate('consent_observed', $saved);
                $matched = $saved['kontaktdatenDTO']['benachrichtigungErlaubt'] === $plan['email'];
                throw new AppError($matched ? 'CONSENT_DETAILS_CHANGED' : 'CONSENT_VERIFY',
                    'Die Einwilligungsübernahme benötigt einen Abgleich am Empfang.', 502,
                    ['operation' => 'CONSENT_READBACK', 'reason' => $matched ? 'OTHER_FIELDS_CHANGED' : 'EMAIL_NOT_CONFIRMED',
                     'changed_groups' => $differences]);
            }
        }
        $journal->confirmed('email_consent_verified');
        if ($plan['pin'] === '') { $journal->confirmed('sms_pin_disabled'); return; }
        $this->setPrivacyPin($patient, $row, $plan['pin']);
        $journal->confirmed('sms_pin_verified');
        foreach ($plan['previous_pins'] as $old) {
            $this->setPrivacyPin($patient, $old, null);
            $journal->confirmed('previous_sms_pin_cleared:' . self::ref($old['ref'])['objectId']['id']);
        }
    }
    /** Read-only reconciliation. Never upload a PDF or reapply an old email choice. */
    public function inspectPrivacyRecovery(array $journal): array
    {
        $p = $journal['payload'] ?? []; $id = $journal['id'] ?? '';
        $plan = $p['consent'] ?? []; $pdf = base64_decode($p['pdf_base64'] ?? '', true);
        if (($p['kind'] ?? '') !== 'privacy' || !preg_match('/^[a-f0-9]{32}$/D', $id)
            || !is_bool($plan['email'] ?? null) || !is_bool($plan['sms'] ?? null)
            || !is_string($plan['pin'] ?? null) || !is_array($plan['previous_pins'] ?? null)
            || !is_string($pdf) || !str_starts_with($pdf, '%PDF-')
            || !is_string($p['template_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $p['template_hash'])) {
            throw new AppError('RECOVERY_FORMAT', 'Keine unterstützte Datenschutz-Prüfkopie.', 409);
        }
        $patient = self::ref($p['patient'] ?? null); $pin = $plan['pin'];
        if ($pin !== '' && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}\.png$/D', $pin)) { throw new AppError('RECOVERY_FORMAT', 'Ungültige Pin-Zuordnung.', 409); }
        $text = PrivacyForm::recordText($p['version'] ?? '', $id, $p['template_hash'], hash('sha256', $pdf), $plan);
        if ($pin !== '') { $text .= "\nSMS-Pin (Check-in): " . $pin; }
        if (($p['record_text'] ?? null) !== $text) { throw new AppError('RECOVERY_FORMAT', 'Prüfkopie und Einwilligung passen nicht zusammen.', 409); }
        foreach (scandir($this->config->get('app.state_dir') . '/pending') as $file) {
            if (preg_match('/^([a-f0-9]{32})\.json\.enc$/D', $file, $m) && $m[1] !== $id) {
                $other = WriteJournal::read($this->config, $m[1]);
                if (($other['payload']['kind'] ?? '') === 'privacy'
                    && ($other['payload']['patient']['objectId']['id'] ?? null) === $patient['objectId']['id']) {
                    throw new AppError('RECOVERY_CONFLICT', 'Ein weiterer offener Datenschutzvorgang erfordert manuellen Abgleich.', 409);
                }
            }
        }
        $rows = $this->privacyRows($patient);
        $matches = array_values(array_filter($rows, static fn($row) => ($row['content']['text'] ?? null) === $text));
        if (count($matches) !== 1) { throw new AppError('RECOVERY_DOCUMENT', 'Genau das ursprüngliche Datenschutzdokument muss vorhanden sein.', 409); }
        $target = $matches[0]; $targetId = self::ref($target['ref'])['objectId']['id'];
        $doc = $this->privacyDocument($patient, $target);
        if (!hash_equals(hash('sha256', $pdf), hash('sha256', $this->rest->documentBytes($doc['verweis'])))) {
            throw new AppError('RECOVERY_DOCUMENT', 'Das gespeicherte PDF stimmt nicht mit der Prüfkopie überein.', 409);
        }
        $oldById = [];
        foreach ($plan['previous_pins'] as $old) {
            $oldId = self::ref($old['ref'] ?? null)['objectId']['id'];
            if ($oldId === $targetId || isset($oldById[$oldId])) { throw new AppError('RECOVERY_FORMAT', 'Mehrdeutiger Pin-Plan.', 409); }
            $oldText = $old['content']['text'] ?? '';
            if (!is_string($oldText) || PrivacyForm::recordVersion($oldText) === null
                || !preg_match('/^SMS-Pin \(Check-in\): ([A-Za-z0-9][A-Za-z0-9_.-]{0,119}\.png)$/m', $oldText, $m)
                || ($old['symbol'] ?? null) !== $m[1]) { throw new AppError('RECOVERY_FORMAT', 'Nicht eindeutig verwalteter Alt-Pin.', 409); }
            $oldById[$oldId] = $old;
        }
        $oldRows = [];
        foreach ($rows as $row) {
            $rowId = self::ref($row['ref'] ?? null)['objectId']['id'];
            if ($rowId === $targetId) { continue; }
            if (isset($oldById[$rowId])) {
                $old = $oldById[$rowId];
                if (($row['content']['text'] ?? null) !== $old['content']['text']
                    || !in_array($row['symbol'] ?? null, [null, '', $old['symbol']], true)) { throw new AppError('RECOVERY_CONFLICT', 'Ein früherer Pin wurde zwischenzeitlich geändert.', 409); }
                $this->privacyDocument($patient, $row); $oldRows[$rowId] = $row;
            } elseif (PrivacyForm::recordVersion($row['content']['text'] ?? '') !== null) {
                // Cannot establish whether another declaration supersedes the old patient's will.
                throw new AppError('RECOVERY_CONFLICT', 'Ein weiterer Datenschutzbogen benötigt manuellen Abgleich.', 409);
            }
        }
        if (count($oldRows) !== count($oldById)) { throw new AppError('RECOVERY_CONFLICT', 'Ein früheres Datenschutzdokument fehlt.', 409); }
        $details = $this->consentDetails($patient);
        if ($details['kontaktdatenDTO']['benachrichtigungErlaubt'] !== $plan['email']) {
            throw new AppError('RECOVERY_EMAIL', 'E-Mail-Einwilligung stimmt nicht überein. Keine automatische Änderung; bitte am Empfang abgleichen.', 409);
        }
        if (isset($p['consent_expected'])) {
            $differences = ConsentCheck::differences($p['consent_expected'], $details);
            if ($differences !== []) { throw new AppError('CONSENT_DETAILS_CHANGED', 'Stammdaten benötigen manuellen Abgleich.', 409, ['changed_groups' => $differences]); }
        }
        if ($pin !== '') {
            if (!in_array($target['symbol'] ?? null, [null, '', $pin], true)) { throw new AppError('RECOVERY_CONFLICT', 'Der Dokument-Pin wurde anderweitig belegt.', 409); }
            $catalog = $this->rest->request('GET', '/praxis/karteikarte/allestandardsymbolnamen', null, false);
            if (!array_is_list($catalog) || !in_array($pin, $catalog, true)) { throw new AppError('SMS_PIN_MISSING', 'Der gespeicherte Pin-Name fehlt im aktuellen Katalog.', 409); }
        }
        return ['patient' => self::ref($details['patientRef']), 'identity' => $this->privacyIdentity($patient),
            'row' => $target, 'old_rows' => $oldRows, 'pin' => $pin, 'email' => $plan['email'], 'sms' => $plan['sms'],
            'legacy' => !isset($p['consent_expected']), 'pin_missing' => $pin !== '' && ($target['symbol'] ?? null) !== $pin];
    }
    /** Staff CLI only, after explicit review. No PDF/email writes, no automatic retries. */
    public function completePrivacyRecovery(WriteJournal $journal): void
    {
        $journal->assertUnchanged();
        $view = $this->inspectPrivacyRecovery($journal->data());
        $journal->assertUnchanged();
        if ($view['pin_missing']) { $this->setPrivacyPin($view['patient'], $view['row'], $view['pin']); }
        foreach (array_keys($view['old_rows']) as $id) {
            $view = $this->inspectPrivacyRecovery($journal->data());
            $journal->assertUnchanged();
            $row = $view['old_rows'][$id];
            if (!in_array($row['symbol'] ?? null, [null, ''], true)) { $this->setPrivacyPin($view['patient'], $row, null); }
        }
        $view = $this->inspectPrivacyRecovery($journal->data());
        if ($view['pin_missing']) { throw new AppError('SMS_PIN_VERIFY', 'Pin weiterhin nicht bestätigt.', 409); }
        foreach ($view['old_rows'] as $row) {
            if (!in_array($row['symbol'] ?? null, [null, ''], true)) { throw new AppError('SMS_PIN_VERIFY', 'Alt-Pin weiterhin nicht bestätigt.', 409); }
        }
        $journal->complete(); // Only this verified journal is removed; PDFs remain unchanged.
    }
    private function setPrivacyPin(array $patient, array $expected, ?string $pin): void
    {
        $this->requireOpenRecord($patient);
        $ref = self::ref($expected['ref']);
        $read = fn() => $this->rest->request('POST', '/praxis/karteikarte/patient/' . $patient['objectId']['id'] . '/byids', [$ref['objectId']], false);
        $rows = $read();
        if (!array_is_list($rows) || count($rows) !== 1 || self::ref($rows[0]['ref'] ?? null) !== $ref
            || ($rows[0]['fachinformationstypTO']['fachinformationstyp'] ?? null) !== 75
            || ($rows[0]['content']['text'] ?? null) !== ($expected['content']['text'] ?? null)
            || ($rows[0]['symbol'] ?? null) !== ($expected['symbol'] ?? null)
            || ($pin !== null && !in_array($rows[0]['symbol'] ?? null, [null, ''], true))) {
            throw new AppError('SMS_PIN_CHANGED', 'Dokument oder Pin wurde zwischenzeitlich geändert. Bitte am Empfang prüfen.', 409);
        }
        // Native endpoint has no atomic compare-and-set. Recheck immediately before,
        // read back afterwards, and never automatically repeat an uncertain write.
        $this->rest->request('POST', '/praxis/karteikarte/symbolaendern',
            ['kontext' => $this->patientContext($patient), 'karteieintragRef' => self::ref($rows[0]['ref']), 'symbol' => $pin]);
        $saved = $read();
        if (!array_is_list($saved) || count($saved) !== 1 || self::ref($saved[0]['ref'] ?? null)['objectId'] !== $ref['objectId']
            || ($saved[0]['fachinformationstypTO']['fachinformationstyp'] ?? null) !== 75
            || ($saved[0]['content']['text'] ?? null) !== ($expected['content']['text'] ?? null)
            || ($saved[0]['symbol'] ?? null) !== $pin) {
            throw new AppError('SMS_PIN_VERIFY', 'SMS-Pin konnte nicht bestätigt werden.', 502);
        }
    }

    public function rooms(): array
    {
        $rooms = self::list($this->rest->request('GET', '/wartezimmer/bereiche/all'), 'entries'); $seen = [];
        foreach ($rooms as &$room) {
            $room['ref'] = self::ref($room['ref'] ?? null); $id = $room['ref']['objectId']['id'];
            if (isset($seen[$id]) || !is_string($room['name'] ?? null) || $room['name'] === '' || !array_key_exists('initialerStatus', $room)) { throw new AppError('ROOM_INVALID', 'Ungültiger Wartebereichkatalog.', 502); }
            self::status($room['initialerStatus'] ?? 0); $seen[$id] = true;
        }
        unset($room); return $rooms;
    }
    public function room(string $name, ?array $rooms = null): array
    {
        $matches = array_values(array_filter($rooms ?? $this->rooms(), static fn($r) => $r['name'] === $name));
        if (count($matches) !== 1) { throw new AppError('ROOM_MISSING', 'Wartebereich fehlt oder ist mehrdeutig: ' . $name, 503); }
        return $matches[0];
    }
    private static function status(mixed $status): int
    {
        if (!is_int($status) || $status < 0 || $status > 5) { throw new AppError('ROOM_STATUS', 'Ungültiger Wartestatus.', 502); }
        return $status;
    }
    public function entries(array $room): array
    {
        $result = $this->rest->request('POST', '/wartezimmer/eintraege/all', ['kontext' => $this->context(), 'wartebereich' => self::ref($room['ref'])]);
        $ref = self::ref($result['wartebereich']['ref'] ?? null);
        if ($ref['objectId']['id'] !== $room['ref']['objectId']['id'] || ($result['wartebereich']['name'] ?? null) !== $room['name']) { throw new AppError('ROOM_MISMATCH', 'Der Wartebereich wurde zwischenzeitlich geändert.', 409); }
        $entries = []; $seen = [];
        foreach (self::list($result, 'eintraege') as $row) {
            $entry = $row['eintrag'] ?? null; $eref = self::ref($entry['ref'] ?? null);
            $pref = self::ref($row['wartender']['ref'] ?? null); $rref = self::ref($row['wartebereich']['ref'] ?? null);
            if ($rref['objectId']['id'] !== $ref['objectId']['id'] || isset($seen[$eref['objectId']['id']])) { throw new AppError('ENTRY_MISMATCH', 'Wartezimmereinträge sind nicht eindeutig.', 502); }
            self::status($entry['status'] ?? null);
            foreach (['wartebeginn','terminInformationen','notiz','notfall','zusatzInformationen'] as $key) {
                if (!array_key_exists($key, $entry)) { throw new AppError('ENTRY_FIELD', 'Wartezimmereintrag ist unvollständig.', 502); }
            }
            if (($entry['notiz'] !== null && !is_string($entry['notiz'])) || ($entry['notfall'] !== null && !is_bool($entry['notfall'])) || (!is_array($entry['zusatzInformationen']) && !($entry['zusatzInformationen'] instanceof \stdClass))) { throw new AppError('ENTRY_TYPE', 'Wartezimmereintrag ist unlesbar.', 502); }
            $seen[$eref['objectId']['id']] = true;
            $entries[] = ['room' => ['name' => $room['name'], 'ref' => $ref], 'entry' => $entry, 'patient_id' => $pref['objectId']['id']];
        }
        return $entries;
    }
    public function patientEntries(string $roomName, string $patientId): array
    {
        return array_values(array_filter($this->entries($this->room($roomName)), static fn($e) => $e['patient_id'] === $patientId));
    }
    public function calendarPlan(string $patientId): array
    {
        $plan = []; $targets = []; $prefix = $this->config->get('routing.calendar_prefix');
        foreach ($this->rooms() as $room) {
            if (!str_starts_with($room['name'], rtrim($prefix))) { continue; }
            foreach ($this->entries($room) as $row) {
                if ($row['patient_id'] !== $patientId) { continue; }
                if (!str_starts_with($room['name'], $prefix) || trim(substr($room['name'], strlen($prefix))) === '') { throw new AppError('CALENDAR_NAME', 'Ungültiger Kalender-Wartebereich.', 409); }
                $target = $this->config->get('routing.waiting_prefix') . substr($room['name'], strlen($prefix));
                $this->room($target);
                if (isset($targets[$target]) || $this->patientEntries($target, $patientId) !== []) { throw new AppError('CALENDAR_AMBIGUOUS', 'Die Wartezimmerzuordnung ist nicht eindeutig.', 409); }
                $targets[$target] = true;
                $plan[] = ['source' => $room['name'], 'target' => $target, 'entry_id' => $row['entry']['ref']['objectId']['id']];
            }
        }
        usort($plan, static fn($a, $b) => strcmp($a['entry_id'], $b['entry_id'])); return $plan;
    }
    public function move(array $item, string $patientId): void
    {
        $source = $this->room($item['source']); $target = $this->room($item['target']);
        $entries = $this->entries($source);
        $matches = array_values(array_filter($entries, static fn($e) => $e['entry']['ref']['objectId']['id'] === $item['entry_id'] && $e['patient_id'] === $patientId));
        if (count($matches) !== 1 || $this->patientEntries($item['target'], $patientId) !== []) { throw new AppError('MOVE_CHANGED', 'Die Terminzuordnung wurde inzwischen geändert.', 409); }
        $target = $this->room($item['target']);
        $error = null;
        try { $this->rest->request('POST', '/wartezimmer/eintraege/move', ['kontext' => $this->context(),
            'wartebereicheintrag' => self::ref($matches[0]['entry']['ref']), 'vonWartebereich' => self::ref($matches[0]['room']['ref']),
            'nachWartebereich' => self::ref($target['ref']), 'position' => null]); }
        catch (AppError $e) { $error = $e; }
        $inTarget = $this->patientEntries($item['target'], $patientId);
        $inSource = array_filter($this->entries($this->room($item['source'])), static fn($e) => $e['entry']['ref']['objectId']['id'] === $item['entry_id']);
        if (count($inTarget) !== 1 || $inTarget[0]['entry']['ref']['objectId']['id'] !== $item['entry_id'] || $inSource !== []) { throw $error ?? new AppError('MOVE_VERIFY', 'Die Verschiebung konnte nicht bestätigt werden.', 502); }
        $this->update($item['target'], $patientId, $item['entry_id'], null, 0);
    }
    public function add(string $roomName, string $patientId, string $note = ''): void
    {
        $entries = $this->patientEntries($roomName, $patientId);
        if (count($entries) > 1) { throw new AppError('ENTRY_DUPLICATE', 'Der Patient steht mehrfach im Wartebereich.', 409); }
        if (count($entries) === 1) {
            if ($note !== '') { $this->update($roomName, $patientId, $entries[0]['entry']['ref']['objectId']['id'], $note, null); }
            return;
        }
        $room = $this->room($roomName); $error = null;
        $entry = ['status' => $room['initialerStatus'] ?? 0, 'wartebeginn' => (int) round(microtime(true) * 1000),
            'terminInformationen' => ['terminBeginn' => null, 'termintypBezeichnung' => null], 'notiz' => $note,
            'notfall' => false, 'zusatzInformationen' => new \stdClass()];
        try { $this->rest->request('POST', '/wartezimmer/eintraege/add', ['kontext' => $this->context(), 'wartebereicheintrag' => $entry,
            'wartebereich' => self::ref($room['ref']), 'position' => null, 'wartender' => ['id' => $patientId], 'shouldProduceACopy' => false]); }
        catch (AppError $e) { $error = $e; }
        $after = $this->patientEntries($roomName, $patientId);
        if (count($after) !== 1 || ($note !== '' && $after[0]['entry']['notiz'] !== $note)) { throw $error ?? new AppError('ADD_VERIFY', 'Die Eintragung konnte nicht bestätigt werden.', 502); }
    }
    private function update(string $roomName, string $patientId, string $entryId, ?string $note, ?int $status): void
    {
        $matches = array_values(array_filter($this->patientEntries($roomName, $patientId), static fn($e) => $e['entry']['ref']['objectId']['id'] === $entryId));
        if (count($matches) !== 1) { throw new AppError('UPDATE_CHANGED', 'Der Wartezimmereintrag wurde verändert.', 409); }
        $row = $matches[0]; $entry = $row['entry'];
        if ($note !== null) {
            $old = trim($entry['notiz'] ?? '');
            $entry['notiz'] = $old === '' ? $note : $old . "\n" . $note;
            if (mb_strlen($entry['notiz']) > 250) { throw new AppError('NOTE_FULL', 'Die vorhandene Wartezimmernotiz bietet nicht genug Platz. Bitte am Empfang ergänzen.', 409); }
        }
        if ($status !== null) { $entry['status'] = $status; $entry['wartebeginn'] = (int) round(microtime(true) * 1000); }
        $entry['zusatzInformationen'] = (object) $entry['zusatzInformationen'];
        $entry['terminInformationen'] ??= ['terminBeginn' => null, 'termintypBezeichnung' => null];
        $error = null;
        try { $this->rest->request('POST', '/wartezimmer/eintraege/update', ['kontext' => $this->context(), 'wartebereicheintrag' => $entry, 'wartebereich' => self::ref($row['room']['ref'])]); }
        catch (AppError $e) { $error = $e; }
        $after = array_values(array_filter($this->patientEntries($roomName, $patientId), static fn($e) => $e['entry']['ref']['objectId']['id'] === $entryId));
        if (count($after) !== 1 || ($status !== null && $after[0]['entry']['status'] !== $status) || ($note !== null && $after[0]['entry']['notiz'] !== $entry['notiz'])) { throw $error ?? new AppError('UPDATE_VERIFY', 'Die Änderung konnte nicht bestätigt werden.', 502); }
    }
}
