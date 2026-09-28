<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use Checkin\{AppError, Config, Flow, SessionStore};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
ini_set('display_errors', '0');
set_time_limit(300);
$flow = null; $session = null; $action = 'state';
try {
    if (($_SERVER['HTTPS'] ?? '') !== 'on' && ($_SERVER['HTTPS'] ?? '') !== '1') { throw new AppError('HTTPS_REQUIRED', 'Bitte öffnen Sie das Gerät über seine HTTPS-Adresse.', 400); }
    $config = new Config(Checkin\configPath()); $session = new SessionStore($config); $session->start();
    date_default_timezone_set($config->get('app.timezone'));
    $flow = new Flow($config, $session);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $state = $flow->state();
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $session->csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4 * 1024 * 1024) { throw new AppError('INPUT_SIZE', 'Die übermittelten Daten sind zu groß.', 413); }
        if (str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) { $input = $_POST; }
        else {
            if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) { throw new AppError('INPUT_TYPE', 'Ungültiges Anfrageformat.', 415); }
            $input = json_decode(file_get_contents('php://input'), true, 16, JSON_THROW_ON_ERROR);
        }
        if (!is_array($input) || !is_string($input['action'] ?? null)) { throw new AppError('INPUT_ACTION', 'Die Aktion fehlt.'); }
        $action = $input['action']; $state = $flow->act($action, $input, $_FILES);
    } else { throw new AppError('METHOD', 'Diese Anfrage ist nicht erlaubt.', 405); }
    echo json_encode(['ok' => true, 'csrf' => $_SESSION['csrf'], 'state' => $state, 'config' => $config->publicData()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $code = $error instanceof AppError ? $error->tag : 'INTERNAL';
    $http = $error instanceof AppError ? $error->http : 500;
    $diagnostic = $error instanceof AppError ? $error->diagnostic : [];
    // Input and outdated-tab errors do not poison a valid flow. Upstream/ambiguous writes do.
    $inputErrors = ['PRIVACY_INPUT','CONTACT_INPUT','FORM_INPUT','FORM_TOKEN','INPUT','CHOICE','NOTE','SELFIE_ANSWER','PHOTO_UPLOAD','PHOTO_FORMAT','PHOTO_DECODE','PHOTO_ENCODE','FLOW_ID','FLOW_STEP','FLOW_ACTIVE','CSRF','FLOW_EXPIRED','INPUT_SIZE','INPUT_TYPE','INPUT_ACTION','METHOD'];
    if ($flow && $action !== 'login' && !in_array($code, $inputErrors, true)) { $flow->block($code, $diagnostic); }
    http_response_code($http);
    $message = $error instanceof AppError ? $error->getMessage() : 'Ein interner Fehler ist aufgetreten. Bitte am Empfang melden.';
    // Only staff authentication sees technical messages. Patient error screen uses configured reception text.
    echo json_encode(['ok' => false, 'code' => $code, 'diagnostic' => $diagnostic, 'message' => $message, 'csrf' => $_SESSION['csrf'] ?? '',
        'blocked' => ($_SESSION['flow']['stage'] ?? '') === 'blocked'], JSON_UNESCAPED_UNICODE);
    // Only fixed labels, HTTP metadata and random report IDs; never native rejection messages.
    error_log('T2med Check-in: ' . $code . ($diagnostic !== [] ? ' ' . json_encode($diagnostic, JSON_UNESCAPED_SLASHES) : ''));
}
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
