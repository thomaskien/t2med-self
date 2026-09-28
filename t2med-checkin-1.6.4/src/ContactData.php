<?php
declare(strict_types=1);
namespace Checkin;

final class ContactData
{
    public static function snapshot(array $details): array
    {
        $contact = $details['kontaktdatenDTO'] ?? null;
        // This DTO comes from the authenticated Bearbeiten/find/details endpoint.
        // APS 26.8.0 leaves sichtbarFuerBenutzer at its default false here; only
        // the separate Anzeigen endpoint populates it. It is NOT an access flag
        // for this response. RestClient still rejects 401/403/unsuccessful results,
        // and T2med::contactSnapshot verifies the patient before calling us.
        if (!is_array($contact)) {
            throw new AppError('CONTACT_SHAPE', 'T2med lieferte keine vollständigen Kontaktdaten.', 502);
        }
        $phones = T2med::list($contact, 'telefonnummern'); $emails = T2med::list($contact, 'emailAdressen');
        foreach ($phones as $p) {
            if (!is_array($p) || !is_string($p['nummer'] ?? null) || !is_int($p['typ'] ?? null)) { throw new AppError('CONTACT_SHAPE', 'Telefonliste ist unvollständig.', 502); }
        }
        foreach ($emails as $e) {
            if (!is_array($e) || !is_string($e['emailadresse'] ?? null)) { throw new AppError('CONTACT_SHAPE', 'E-Mail-Liste ist unvollständig.', 502); }
        }
        $address = $details['adressdatenDTO']['postadresse'] ?? null;
        if ($address !== null && !is_array($address) && !($address instanceof \stdClass)) { throw new AppError('CONTACT_SHAPE', 'Adressdaten sind unvollständig.', 502); }
        $name = $details['personendatenDTO']['namensdaten'] ?? [];
        if (!is_array($name)) { $name = []; }
        $nameParts = [];
        foreach (['vorname', 'nachname'] as $key) {
            $value = $name[$key] ?? '';
            if (is_string($value) && trim($value) !== '') { $nameParts[] = trim($value); }
        }
        return ['phones' => $phones, 'emails' => $emails, 'address' => (array) $address, 'name' => implode(' ', $nameParts)];
    }
    public static function mask(string $value, string $type): string
    {
        if ($value === '') { return ''; }
        if ($type === 'email') {
            $parts = explode('@', $value, 2);
            return mb_substr($parts[0], 0, 1) . '***@' . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) . '***' : '***');
        }
        $length = mb_strlen($value);
        if ($type === 'tel' && $length >= 9) { return mb_substr($value, 0, 3) . str_repeat('*', min(10, $length - 6)) . mb_substr($value, -3); }
        return mb_substr($value, 0, 1) . '***';
    }
    public static function rows(array $s): array
    {
        $rows = []; $seen = [];
        foreach ($s['phones'] as $i => $p) {
            if (!in_array($p['typ'], [1, 2], true)) { continue; } // Work/fax/other untouched.
            $seen[$p['typ']] = true;
            $rows[] = ['key' => 'p' . $i, 'label' => $p['typ'] === 2 ? 'Mobilfunk' : 'Festnetz', 'type' => 'tel', 'value' => $p['nummer']];
        }
        foreach ([1 => 'Festnetz', 2 => 'Mobilfunk'] as $type => $label) {
            if (!isset($seen[$type])) { $rows[] = ['key' => 'add' . $type, 'label' => $label, 'type' => 'tel', 'value' => '']; }
        }
        foreach ($s['emails'] as $i => $e) { $rows[] = ['key' => 'e' . $i, 'label' => 'E-Mail', 'type' => 'email', 'value' => $e['emailadresse']]; }
        if ($s['emails'] === []) { $rows[] = ['key' => 'addEmail', 'label' => 'E-Mail', 'type' => 'email', 'value' => '']; }
        return $rows;
    }
    public static function view(array $s, bool $new): array
    {
        $rows = [];
        foreach (self::rows($s) as $r) {
            $rows[] = ['key' => $r['key'], 'label' => $r['label'], 'type' => $r['type'], 'known' => $r['value'] !== '', 'masked' => self::mask($r['value'], $r['type'])];
        }
        $address = [];
        foreach (['strasse', 'hausnummer', 'zusatz', 'plz', 'ort', 'land', 'postfach'] as $key) {
            $value = $s['address'][$key] ?? '';
            if (!is_string($value)) { throw new AppError('CONTACT_SHAPE', 'Adresse ist unlesbar.', 502); }
            // The patient must be able to verify the complete address, including for existing records.
            if ($value !== '') { $address[] = $value; }
        }
        $a = $s['address'];
        $lines = array_values(array_filter([
            trim(($a['strasse'] ?? '') . ' ' . ($a['hausnummer'] ?? '')),
            $a['zusatz'] ?? '', $a['postfach'] ?? '',
            trim(($a['plz'] ?? '') . ' ' . ($a['ort'] ?? '')),
            $a['land'] ?? '',
        ], static fn(string $line): bool => trim($line) !== ''));
        // Only the address block is unmasked; phone and email values stay server-side.
        return ['new' => $new, 'rows' => $rows, 'address' => implode(' ', $address),
            'name' => $s['name'] ?? '', 'address_lines' => $lines];
    }
    public static function changes(array $snapshot, array $input): array
    {
        $values = $input['values'] ?? [];
        if (!is_array($values)) { throw new AppError('CONTACT_INPUT', 'Bitte gültige Kontaktdaten eingeben.'); }
        $next = $snapshot; $notes = []; $known = [];
        foreach (self::rows($snapshot) as $r) {
            $key = $r['key']; $known[$key] = true;
            $value = $values[$key] ?? '';
            if (!is_string($value)) { throw new AppError('CONTACT_INPUT', 'Bitte gültige Kontaktdaten eingeben.'); }
            $value = trim($value);
            if ($value === '' || $value === $r['value']) { continue; }
            if (mb_strlen($value) > 150 || preg_match('/[\x00-\x1f\x7f]/', $value)
                || ($r['type'] === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL))
                || ($r['type'] === 'tel' && (!preg_match('/^\+?[0-9 ()\/.\-]+$/D', $value) || strlen(preg_replace('/\D/', '', $value)) < 3 || strlen($value) > 50))) {
                throw new AppError('CONTACT_INPUT', 'Bitte eine gültige ' . ($r['type'] === 'email' ? 'E-Mail-Adresse' : 'Telefonnummer') . ' eingeben.');
            }
            $old = $r['value'] === '' ? '(nicht hinterlegt)' : $r['value'];
            $notes[] = ($r['type'] === 'tel' ? 'Selbständerung Telefonnummer am Terminal (' . $r['label'] . '), alte Nummer: ' : 'Selbständerung E-Mail am Terminal, alte E-Mail: ')
                . $old . ($r['type'] === 'tel' ? ', neue Nummer: ' : ', neue E-Mail: ') . $value;
            if ($key === 'add1' || $key === 'add2') {
                $next['phones'][] = ['nummer' => $value, 'typ' => (int) substr($key, 3), 'kategorie' => 'Privat', 'kommentar' => 'Patientenangabe am Terminal'];
            } elseif ($key === 'addEmail') { $next['emails'][] = ['emailadresse' => $value, 'kategorie' => 'Privat', 'kommentar' => 'Patientenangabe am Terminal']; }
            elseif ($key[0] === 'p') { $next['phones'][(int) substr($key, 1)]['nummer'] = $value; }
            else { $next['emails'][(int) substr($key, 1)]['emailadresse'] = $value; }
        }
        if (array_diff_key($values, $known)) { throw new AppError('CONTACT_INPUT', 'Das Kontaktformular ist nicht mehr aktuell.'); }
        $addressOk = $input['address_ok'] ?? '';
        if (!in_array($addressOk, ['', 'yes', 'no'], true)) { throw new AppError('CONTACT_INPUT', 'Bitte die Adresse prüfen.'); }
        $address = $input['address_correction'] ?? '';
        if (!is_string($address) || mb_strlen($address) > 500 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $address)) { throw new AppError('CONTACT_INPUT', 'Bitte eine gültige Adresse eingeben.'); }
        if ($addressOk === 'no') {
            $notes[] = 'Achtung: Adresse auf der Karte stimmt nicht, korrekte Adresse: ' . (trim($address) !== '' ? trim($address) : '(nicht angegeben; bitte am Empfang klären)');
        }
        return ['next' => $next, 'notes' => $notes];
    }
}
