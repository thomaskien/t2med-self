<?php
declare(strict_types=1);
namespace Checkin;

final class SqlDate
{
    public function __construct(private Config $config) {}
    public function check(): void
    {
        if (!$this->config->get('card_presentation_date.enabled')) { return; }
        try {
            if ($this->config->get('sql.mode') === 'ssh') {
                if (trim($this->gateway("check\n")) !== 'OK') { throw new \RuntimeException(); }
                return;
            }
            $pdo = $this->connect();
            $found = $pdo->query("SELECT count(*) FROM information_schema.columns WHERE table_schema='aps' AND table_name='versicherungsnachweis' AND column_name='kartenvorlage_datum' AND data_type='date'")->fetchColumn();
            if ((int) $found !== 1) { throw new \RuntimeException(); }
        } catch (\Throwable) { throw new AppError('SQL_PREFLIGHT', 'Der optionale SQL-Zugang oder die erwartete Datumsspalte ist nicht verfügbar.', 503); }
    }
    public function apply(array $card): void
    {
        if (!$this->config->get('card_presentation_date.enabled')) { return; }
        $card = T2med::ref($card); $id = $card['objectId']['id']; $revision = $card['revision'];
        $date = $this->config->get('card_presentation_date.target_date');
        try {
            if ($this->config->get('sql.mode') === 'ssh') {
                if (trim($this->gateway('set|' . $id . '|' . $revision . '|' . $date . "\n")) !== 'OK') { throw new \RuntimeException(); }
                return;
            }
            $pdo = $this->connect(); $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare("UPDATE aps.versicherungsnachweis SET kartenvorlage_datum=CAST(:date AS date) WHERE objectid=:id AND revision>=:revision AND classid=3 AND creationtimestamp >= clock_timestamp() - interval '15 minutes'");
                $statement->execute(['date' => $date, 'id' => $id, 'revision' => $revision]);
                if ($statement->rowCount() !== 1) { throw new \RuntimeException(); }
                $pdo->commit();
            } catch (\Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
            // Independent connection sees the committed state, matching the 1.3 verification.
            $verify = $this->connect()->prepare("SELECT count(*) FROM aps.versicherungsnachweis WHERE objectid=:id AND classid=3 AND kartenvorlage_datum=CAST(:date AS date)");
            $verify->execute(['id' => $id, 'date' => $date]);
            if ((int) $verify->fetchColumn() !== 1) { throw new \RuntimeException(); }
        } catch (\Throwable) { throw new AppError('SQL_DATE', 'Das Kartenvorlagedatum konnte nicht eindeutig gesetzt und bestätigt werden. Bitte den Vorgang am Empfang prüfen.', 502); }
    }
    private function connect(): \PDO
    {
        $s = $this->config->get('sql');
        $host = $s['mode'] === 'local' ? $s['socket_directory'] : $s['host'];
        $dsn = 'pgsql:host=' . $host . ';port=' . $s['port'] . ';dbname=' . $s['database'] . ';connect_timeout=5';
        if ($s['mode'] === 'tcp') {
            $dsn .= ';sslmode=' . $s['sslmode'];
            if ($s['sslrootcert'] !== '') { $dsn .= ';sslrootcert=' . $s['sslrootcert']; }
        }
        $password = $s['password_file'] === '' ? '' : @file_get_contents($s['password_file']);
        if ($password === false) { throw new \RuntimeException(); }
        $pdo = new \PDO($dsn, $s['username'], rtrim($password, "\r\n"), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET statement_timeout='10s'; SET lock_timeout='1s'; SET idle_in_transaction_session_timeout='15s'");
        return $pdo;
    }
    private function gateway(string $input): string
    {
        $s = $this->config->get('sql');
        $command = ['/usr/bin/ssh', '-T', '-p', (string) $s['ssh_port'], '-i', $s['ssh_key'],
            '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'ConnectTimeout=5', '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UpdateHostKeys=no', '-o', 'UserKnownHostsFile=' . $s['ssh_known_hosts'],
            '-o', 'ServerAliveInterval=5', '-o', 'ServerAliveCountMax=2', $s['ssh_user'] . '@' . $s['host'], 't2med-checkin-card-date'];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { throw new \RuntimeException(); }
        fwrite($pipes[0], $input); fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $output = ''; $status = null; $deadline = microtime(true) + 40;
        do {
            $output .= stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); // Never expose remote stderr/SQL.
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if (strlen($output) > 4096 || microtime(true) >= $deadline) { proc_terminate($process, 9); break; }
            usleep(20000);
        } while (true);
        $output .= stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        if (($status['running'] ?? true) || (($status['exitcode'] ?? $exit) !== 0)) { throw new \RuntimeException(); }
        return $output;
    }
}
