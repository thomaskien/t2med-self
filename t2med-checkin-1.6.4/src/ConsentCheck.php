<?php
declare(strict_types=1);
namespace Checkin;

/** Compare persisted values, not APS display/validation fields or owned-row identities. */
final class ConsentCheck
{
    public static function differences(array $expected, array $saved): array
    {
        $a = self::normalize($expected); $b = self::normalize($saved); $groups = [];
        foreach (['patientRef', 'personendatenDTO', 'adressdatenDTO', 'kontaktdatenDTO', 'weitereDatenDTO'] as $key) {
            if (($a[$key] ?? null) !== ($b[$key] ?? null)) { $groups[] = $key; }
            unset($a[$key], $b[$key]);
        }
        if ($a !== $b) { $groups[] = 'other_fields'; }
        return $groups; // Fixed labels only, never response-supplied names or values.
    }
    private static function normalize(array $data): array
    {
        unset($data['sichtbarFuerBenutzer']); // Anzeigen-only display flag; Bearbeiten leaves its default.
        foreach (['namensdaten' => ['anrede','titel','vorname','vorsatzwort','nachname','zusatz'],
                  'geburtsdaten' => ['geburtsname','geburtsort']] as $key => $texts) {
            if (is_array($data['personendatenDTO'][$key] ?? null)) {
                $row = $data['personendatenDTO'][$key];
                unset($row['ref'], $row['objectId'], $row['revision']);
                if ($key === 'namensdaten') { unset($row['consideredEmpty']); }
                foreach ($texts as $text) { $row[$text] = $row[$text] ?? ''; }
                $data['personendatenDTO'][$key] = $row;
            }
        }
        foreach (['telefonnummern' => ['nummer','kategorie','kommentar'], 'emailAdressen' => ['emailadresse','kategorie','kommentar']] as $key => $texts) {
            if (is_array($data['kontaktdatenDTO'][$key] ?? null)) {
                $rows = [];
                foreach ($data['kontaktdatenDTO'][$key] as $row) {
                    if (!is_array($row)) { $rows[] = $row; continue; }
                    unset($row['ref'], $row['objectId'], $row['revision']);
                    if ($key === 'telefonnummern') { unset($row['validTelefonnummer']); }
                    foreach ($texts as $text) { $row[$text] = $row[$text] ?? ''; }
                    $rows[] = self::canonical($row);
                }
                // Phone/email DTOs are complete value lists; preserve duplicates and all values.
                usort($rows, static fn($a, $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
                $data['kontaktdatenDTO'][$key] = $rows;
            }
        }
        foreach (['postadresse', 'postfachadresse'] as $key) {
            $row = $data['adressdatenDTO'][$key] ?? null;
            if ($row instanceof \stdClass) { $row = (array) $row; }
            if (is_array($row)) {
                unset($row['ref'], $row['objectId'], $row['revision']);
                foreach (['emptyRechnungsanschrift','validPostanschrift','validPostfachadresse','consideredEmpty'] as $flag) { unset($row[$flag]); }
                foreach (['land','plz','ort','strasse','hausnummer','kommentar','zusatz','postfach'] as $text) { $row[$text] = $row[$text] ?? ''; }
                if (array_filter($row, static fn($value) => $value !== '') === []) { $row = null; }
            }
            $data['adressdatenDTO'][$key] = $row;
        }
        // The association ref stays checked; only its server-rendered caption is excluded.
        if (is_array($data['weitereDatenDTO']['sequenzVorlage'] ?? null)) { unset($data['weitereDatenDTO']['sequenzVorlage']['displayText']); }
        return self::canonical($data);
    }
    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) { $value = (array) $value; }
        if (!is_array($value)) { return $value; }
        if (array_key_exists('objectId', $value) && array_key_exists('revision', $value)) { unset($value['revision']); }
        if (!array_is_list($value)) { ksort($value); }
        return array_map([self::class, 'canonical'], $value);
    }
}
