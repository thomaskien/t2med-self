<?php
declare(strict_types=1);
namespace Checkin;

/** Native TEXT_SAVE rejection details stay encrypted; only fixed labels leave the server. */
final class RestRejection
{
    public static function record(Config $config, array $result, array $diagnostic): array
    {
        $messages = [];
        $add = static function (mixed $entry) use (&$messages): void {
            $text = is_string($entry) ? $entry : (is_array($entry) ? ($entry['message'] ?? null) : null);
            if (is_string($text) && $text !== '' && count($messages) < 64) {
                $messages[] = mb_substr($text, 0, 2048);
            }
        };
        $add($result['message'] ?? null);
        $validation = $result['validation'] ?? null;
        if (is_array($validation) && is_array($validation['messages'] ?? null)) {
            foreach ($validation['messages'] as $entry) { $add($entry); }
        }
        $reasons = [];
        foreach ($messages as $text) {
            $reason = match (true) {
                $text === 'Arztrolle nicht gefunden' => 'DOCTOR_ROLE_NOT_FOUND',
                $text === 'Behandlungsort nicht gefunden' => 'LOCATION_NOT_FOUND',
                $text === 'Es wurde kein Behandlungsfall angegeben.' => 'CASE_REQUIRED',
                $text === 'Es wurde keine Arztrolle angegeben.' => 'DOCTOR_ROLE_REQUIRED',
                $text === 'Es wurde kein Behandlungsort angegeben.' => 'LOCATION_REQUIRED',
                $text === 'Patient und Behandlungsfall passen nicht zusammen.' => 'PATIENT_CASE_MISMATCH',
                preg_match("/^Der Behandlungsort '.+' passt nicht zur Arztrolle '.+'\\.$/usD", $text) === 1 => 'ROLE_LOCATION_MISMATCH',
                default => null,
            };
            if ($reason !== null) { $reasons[$reason] = true; }
        }
        $diagnostic['reason'] = ($result['successful'] ?? null) !== false ? 'SUCCESS_FLAG_INVALID'
            : ($reasons !== [] ? implode('+', array_keys($reasons)) : 'UPSTREAM_VALIDATION');
        try {
            $id = bin2hex(random_bytes(16));
            // Reuse existing encryption/permissions and the local CLI viewer. This is
            // diagnostic evidence, NOT a queued write and never an automatic retry.
            new WriteJournal($config, $id, ['kind' => 'rest_rejection', 'operation' => 'TEXT_SAVE',
                'reason' => $diagnostic['reason'], 'messages' => $messages,
                'warning_as_error' => is_array($validation) && ($validation['treatWarningAsError'] ?? null) === true]);
            $diagnostic['report_id'] = $id;
        } catch (\Throwable) {
            // Preserve the original rejection even if storage fails; no raw exception leaks.
            $diagnostic['report_storage'] = 'UNAVAILABLE';
        }
        return $diagnostic;
    }
}
