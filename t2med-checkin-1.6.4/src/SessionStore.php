<?php
declare(strict_types=1);
namespace Checkin;

final class SessionStore
{
    private mixed $requestLock = null;
    public function __construct(private Config $config) {}
    public function start(): void
    {
        $directory = $this->config->get('app.state_dir') . '/sessions';
        if (!is_dir($directory) || !is_writable($directory)) { throw new AppError('SESSION_DIRECTORY', 'Die Sitzungsspeicherung ist nicht eingerichtet.', 503); }
        umask(0077);
        session_name('T2MED_CHECKIN');
        // Hold a separate request lock across PHP session checkpoints. A second tab cannot
        // observe an in-progress marker until the original request has exited.
        $lockKey = hash('sha256', (string) ($_COOKIE['T2MED_CHECKIN'] ?? ('new:' . ($_SERVER['REMOTE_ADDR'] ?? 'local'))));
        $this->requestLock = fopen($this->config->get('app.state_dir') . '/requests/' . $lockKey . '.lock', 'c+');
        if ($this->requestLock === false || !flock($this->requestLock, LOCK_EX)) { throw new AppError('REQUEST_LOCK', 'Die Sitzung kann nicht gesperrt werden.', 503); }
        session_save_path($directory);
        ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $this->lifetime());
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public function checkpoint(): void
    {
        // Persist an in-progress marker before irreversible calls, including on worker termination.
        $snapshot = $_SESSION;
        session_write_close(); session_start();
        $_SESSION = $snapshot;
    }
    public function login(string $username, string $password): void
    {
        if ($username === '' || strlen($username) > 150 || str_contains($username, ':') || preg_match('/[\x00-\x1f]/', $username) || strlen($password) > 1024) { throw new AppError('LOGIN_FORMAT', 'Bitte gültige T2med-Zugangsdaten eingeben.'); }
        $attempts = $_SESSION['login_failures'] ?? [];
        $attempts = array_values(array_filter($attempts, static fn($time) => $time > time() - 300));
        if (count($attempts) >= 5) { throw new AppError('LOGIN_RATE', 'Bitte warten Sie fünf Minuten vor der nächsten Anmeldung.', 429); }
        try {
            $client = new T2med($this->config, new RestClient($this->config, $username, $password));
            $client->preflight(); (new SqlDate($this->config))->check();
        } catch (\Throwable $error) { $_SESSION['login_failures'] = [...$attempts, time()]; throw $error; }
        // A staff login also deliberately acknowledges a blocked/abandoned operation.
        try { $client->eject($_SESSION['flow']['card_session'] ?? null); }
        catch (AppError $error) { $this->audit('staff_card_cleanup_unconfirmed', $error->tag); }
        if (isset($_SESSION['flow']['id'])) { $this->release($_SESSION['flow']['id']); }
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt(json_encode(['user' => $username, 'password' => $password], JSON_THROW_ON_ERROR), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) { throw new AppError('SESSION_CRYPTO', 'Anmeldedaten konnten nicht gesichert werden.', 503); }
        $started = time();
        $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'auth' => base64_encode($iv . $tag . $encrypted),
            'auth_started' => $started, 'auth_until' => $started + $this->lifetime()];
        session_regenerate_id(true);
    }
    public function logout(): void
    {
        // Do not let logging out reset the login rate limit. No credentials/patient state remains.
        $failures = $_SESSION['login_failures'] ?? [];
        $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
        if ($failures !== []) { $_SESSION['login_failures'] = $failures; }
        session_regenerate_id(true);
    }
    private function key(): string
    {
        $key = @file_get_contents($this->config->get('app.secret_file'));
        if ($key === false || strlen($key) !== 32) { throw new AppError('SECRET_MISSING', 'Der Anwendungsschlüssel fehlt.', 503); }
        return $key;
    }
    private function lifetime(): int { return min(12, $this->config->get('app.session_hours')) * 3600; }
    public function authenticated(): bool
    {
        $started = $_SESSION['auth_started'] ?? null; $until = $_SESSION['auth_until'] ?? null; $now = time();
        // Legacy sessions lack a trustworthy login timestamp: require one new staff login.
        // Never slide this deadline with patient activity, touches or page reloads.
        return isset($_SESSION['auth']) && is_int($started) && is_int($until) && $started <= $now
            && $now < min($until, $started + $this->lifetime());
    }
    public function client(): T2med
    {
        if (!$this->authenticated()) { throw new AppError('LOGIN_REQUIRED', 'Bitte lassen Sie das Gerät von einem Mitarbeiter anmelden.', 401); }
        $raw = base64_decode($_SESSION['auth'], true);
        if ($raw === false || strlen($raw) < 29) { throw new AppError('SESSION_CRYPTO', 'Die Anmeldung ist ungültig.', 401); }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) { throw new AppError('SESSION_CRYPTO', 'Die Anmeldung ist ungültig.', 401); }
        $credentials = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
        return new T2med($this->config, new RestClient($this->config, $credentials['user'], $credentials['password']));
    }
    public function csrf(string $token): void
    {
        if ($token === '' || !hash_equals($_SESSION['csrf'], $token)) { throw new AppError('CSRF', 'Bitte laden Sie die Seite erneut.', 403); }
    }
    public function lease(string $owner): void { $this->leaseOperation($owner, false); }
    public function release(string $owner): void { $this->leaseOperation($owner, true); }
    private function leaseOperation(string $owner, bool $release): void
    {
        $path = $this->config->get('app.state_dir') . '/reader-' . hash('sha256', $this->config->get('reader.name')) . '.lock';
        $file = fopen($path, 'c+');
        if ($file === false || !flock($file, LOCK_EX)) { throw new AppError('READER_LOCK', 'Die Gerätesperre ist nicht verfügbar.', 503); }
        try {
            $old = json_decode(stream_get_contents($file) ?: '{}', true);
            if ($release) {
                if (($old['owner'] ?? null) !== $owner) { return; }
                $next = [];
            } else {
                if (($old['until'] ?? 0) > time() && ($old['owner'] ?? null) !== $owner) { throw new AppError('READER_BUSY', 'Das Lesegerät wird gerade an einem anderen Fenster verwendet.', 409); }
                $next = ['owner' => $owner, 'until' => time() + 900];
            }
            rewind($file); ftruncate($file, 0); fwrite($file, json_encode($next, JSON_THROW_ON_ERROR)); fflush($file);
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
    public function audit(string $event, string $tag = ''): void
    {
        $row = ['time' => gmdate('c'), 'event' => $event, 'code' => $tag, 'operation' => $_SESSION['flow']['id'] ?? null];
        file_put_contents($this->config->get('app.state_dir') . '/events.log', json_encode($row, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }
}
