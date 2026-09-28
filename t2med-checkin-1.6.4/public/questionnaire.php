<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use Checkin\{AppError, Config, Flow, Questionnaires, SessionStore};

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src 'self'; manifest-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
ini_set('display_errors', '0'); set_time_limit(300);

// Only these two non-sensitive, local component assets are exposed. Never accept a file path.
if (isset($_GET['asset'])) {
    $assets = ['js' => ['tablet-checkin.js', 'application/javascript'], 'css' => ['tablet-checkin.css', 'text/css']];
    $asset = is_string($_GET['asset']) ? ($assets[$_GET['asset']] ?? null) : null;
    if (!$asset || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(404); exit; }
    header('Content-Type: ' . $asset[1] . '; charset=utf-8');
    readfile(dirname(__DIR__) . '/fragebogenpi/' . $asset[0]); exit;
}

$flow = null; $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
try {
    if (!in_array($_SERVER['HTTPS'] ?? '', ['on', '1'], true)) { throw new AppError('HTTPS_REQUIRED', 'Bitte HTTPS verwenden.', 400); }
    $config = new Config(Checkin\configPath()); $session = new SessionStore($config); $session->start();
    date_default_timezone_set($config->get('app.timezone'));
    $flow = new Flow($config, $session); $state = $flow->state();
    if ($post) {
        header('Content-Type: application/json; charset=utf-8');
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 256 * 1024) { throw new AppError('INPUT_SIZE', 'Die Antworten sind zu umfangreich.', 413); }
        $session->csrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : '');
        $job = $_SESSION['flow']['forms'][0] ?? null;
        if ($state['stage'] !== 'questionnaire' || !is_array($job)
            || !is_string($_POST['flowId'] ?? null) || !hash_equals($state['id'], $_POST['flowId'])
            || !is_string($_POST['formToken'] ?? null) || !hash_equals($job['token'], $_POST['formToken'])) {
            throw new AppError('FORM_TOKEN', 'Dieser Fragebogen ist nicht mehr aktuell.', 409);
        }
        $action = $_POST['action'] ?? null;
        if (!in_array($action, ['form_submit', 'form_abort', 'form_touch', 'form_timeout'], true)) { throw new AppError('INPUT_ACTION', 'Ungültige Aktion.'); }
        $state = $flow->act(match ($action) { 'form_touch' => 'touch', 'form_timeout' => 'reset', default => $action }, $_POST, []);
        echo json_encode(['ok' => true, 'more' => $state['stage'] === 'questionnaire'], JSON_THROW_ON_ERROR);
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        if ($state['stage'] !== 'questionnaire') { header('Location: ./', true, 303); exit; }
        $job = $_SESSION['flow']['forms'][0];
        $form = (new Questionnaires($config))->current($job);
        $checkinView = ['yaml' => $form['yaml'], 'flowId' => $state['id'], 'formToken' => $job['token'],
            'csrf' => $_SESSION['csrf'], 'message' => $state['message'], 'timeout' => $config->get('questionnaires.timeout_seconds')];
        session_write_close();
        require dirname(__DIR__) . '/fragebogenpi/tablet-checkin.php';
    } else { throw new AppError('METHOD', 'Diese Anfrage ist nicht erlaubt.', 405); }
} catch (Throwable $error) {
    $code = $error instanceof AppError ? $error->tag : 'INTERNAL';
    $diagnostic = $error instanceof AppError ? $error->diagnostic : [];
    if ($flow && !in_array($code, ['FORM_INPUT', 'FORM_TOKEN', 'CSRF', 'INPUT_SIZE', 'INPUT_ACTION', 'FLOW_ID', 'FLOW_STEP', 'FLOW_EXPIRED', 'METHOD'], true)) {
        $flow->block($code, $diagnostic);
    }
    if ($post) {
        header('Content-Type: application/json; charset=utf-8'); http_response_code($error instanceof AppError ? $error->http : 500);
        echo json_encode(['ok' => false, 'code' => $code, 'blocked' => ($_SESSION['flow']['stage'] ?? '') === 'blocked',
            'message' => $code === 'FORM_INPUT' ? $error->getMessage() : 'Die Übertragung konnte nicht bestätigt werden. Bitte am Empfang melden.'], JSON_UNESCAPED_UNICODE);
    } else { header('Location: ./', true, 303); }
    error_log('T2med Check-in questionnaire: ' . $code);
}
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
