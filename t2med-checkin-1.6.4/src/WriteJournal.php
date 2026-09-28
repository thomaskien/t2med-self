<?php
declare(strict_types=1);
namespace Checkin;

/** Encrypted recovery copy before a series of APS writes. Never automatically replayed. */
final class WriteJournal
{
    private array $data;
    private string $path;
    private mixed $lock = null;
    private ?string $fingerprint = null;
    public function __construct(private Config $config, string $id, ?array $payload)
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new AppError('JOURNAL_ID', 'Ungültige Vorgangskennung.', 500); }
        $this->path = $config->get('app.state_dir') . '/pending/' . $id . '.json.enc';
        if ($payload === null || ($payload['kind'] ?? '') === 'privacy') {
            $directory = $config->get('app.state_dir') . '/requests';
            if (is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700))) { throw new AppError('JOURNAL_LOCK', 'Prüfsperre nicht verfügbar.', 503); }
            $lockPath = $directory . '/privacy-' . $id . '.lock';
            if (is_link($lockPath)) { throw new AppError('JOURNAL_LOCK', 'Ungültige Prüfsperre.', 503); }
            $this->lock = fopen($lockPath, 'c+');
            if (!$this->lock || !flock($this->lock, LOCK_EX | LOCK_NB)) { throw new AppError('JOURNAL_BUSY', 'Dieser Vorgang wird gerade bearbeitet.', 409); }
            chmod($lockPath, 0600);
        }
        if ($payload === null) {
            $this->data = self::read($config, $id, $this->fingerprint);
            return;
        }
        $this->data = ['id' => $id, 'created' => gmdate('c'), 'payload' => $payload, 'confirmed' => []];
        if (file_exists($this->path)) { throw new AppError('WRITE_PENDING', 'Eine frühere Übertragung muss durch Mitarbeiter geprüft werden.', 409); }
        $this->save(true);
    }
    public static function read(Config $config, string $id, ?string &$fingerprint = null): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) { throw new AppError('JOURNAL_ID', 'Ungültige Prüfkennung.'); }
        $path = $config->get('app.state_dir') . '/pending/' . $id . '.json.enc';
        if (is_link(dirname($path)) || is_link($path) || !is_file($path) || filesize($path) > 20 * 1024 * 1024) { throw new AppError('JOURNAL_READ', 'Prüfkopie nicht verfügbar.', 409); }
        $blob = file_get_contents($path); $key = file_get_contents($config->get('app.secret_file'));
        if ($blob === false || strlen($blob) < 32 || substr($blob, 0, 3) !== 'CI1' || $key === false || strlen($key) !== 32) { throw new AppError('JOURNAL_READ', 'Prüfkopie nicht lesbar.', 409); }
        $plain = openssl_decrypt(substr($blob, 31), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 3, 12), substr($blob, 15, 16), 'checkin-recovery-1');
        if ($plain === false) { throw new AppError('JOURNAL_READ', 'Prüfkopie nicht entschlüsselbar.', 409); }
        $data = json_decode($plain, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['id'] ?? '') !== $id || !is_array($data['payload'] ?? null)
            || !is_array($data['confirmed'] ?? null) || !array_is_list($data['confirmed'])
            || count(array_filter($data['confirmed'], 'is_string')) !== count($data['confirmed'])) { throw new AppError('JOURNAL_READ', 'Prüfkopie hat ein ungültiges Format.', 409); }
        $fingerprint = hash('sha256', $blob); return $data;
    }
    public function data(): array { return $this->data; }
    public function annotate(string $key, array $value): void { $this->data['payload'][$key] = $value; $this->save(); }
    public function assertUnchanged(): void
    {
        if ($this->fingerprint === null) { return; }
        $current = !is_link($this->path) && is_file($this->path) ? @hash_file('sha256', $this->path) : false;
        if (!is_string($current) || !hash_equals($this->fingerprint, $current)) { throw new AppError('JOURNAL_CHANGED', 'Prüfkopie wurde zwischenzeitlich geändert.', 409); }
    }
    private function save(bool $new = false): void
    {
        $this->assertUnchanged();
        $key = @file_get_contents($this->config->get('app.secret_file'));
        if ($key === false || strlen($key) !== 32) { throw new AppError('SECRET_MISSING', 'Der Anwendungsschlüssel fehlt.', 503); }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt(json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'checkin-recovery-1');
        if ($cipher === false) { throw new AppError('JOURNAL_CRYPTO', 'Übertragung konnte nicht gesichert werden.', 503); }
        $blob = 'CI1' . $iv . $tag . $cipher;
        // The first file is exclusive; step updates are atomic. Pending data is outside webroot.
        $target = $new ? $this->path : $this->path . '.tmp-' . bin2hex(random_bytes(4));
        $handle = @fopen($target, 'xb');
        if (!$handle) { throw new AppError('JOURNAL_WRITE', 'Übertragungssicherung ist nicht verfügbar.', 503); }
        try {
            if (!chmod($target, 0600) || fwrite($handle, $blob) !== strlen($blob) || !fflush($handle) || !fsync($handle)) { throw new AppError('JOURNAL_WRITE', 'Übertragungssicherung ist fehlgeschlagen.', 503); }
        } finally { fclose($handle); }
        if (!$new && !rename($target, $this->path)) { throw new AppError('JOURNAL_WRITE', 'Übertragungssicherung ist fehlgeschlagen.', 503); }
        $this->fingerprint = hash('sha256', $blob);
    }
    public function confirmed(string $step): void { $this->data['confirmed'][] = $step; $this->save(); }
    public function complete(): void
    {
        $this->assertUnchanged();
        // No recovery data is retained after every write was confirmed.
        if (!unlink($this->path)) { throw new AppError('JOURNAL_CLEANUP', 'Bestätigte Übertragungssicherung konnte nicht bereinigt werden.', 503); }
    }
    public function __destruct()
    {
        if (is_resource($this->lock)) { flock($this->lock, LOCK_UN); fclose($this->lock); }
    }
}
