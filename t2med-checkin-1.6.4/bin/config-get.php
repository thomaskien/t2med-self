<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$allowed = ['card_presentation_date.enabled', 'sql.mode', 'sql.host', 'sql.ssh_port', 't2med.server'];
$key = $argv[1] ?? '';
if (!in_array($key, $allowed, true)) { exit(1); }
$value = (new Checkin\Config(Checkin\configPath()))->get($key);
echo is_bool($value) ? ($value ? 'true' : 'false') : $value;
