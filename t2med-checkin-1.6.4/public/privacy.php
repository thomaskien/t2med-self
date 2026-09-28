<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
use Checkin\{AppError, Config, Flow, PrivacyForm, SessionStore};
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; manifest-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
ini_set('display_errors', '0');
$flow = null;
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { throw new AppError('METHOD', 'Nicht erlaubt.', 405); }
    if (!in_array($_SERVER['HTTPS'] ?? '', ['on', '1'], true)) { throw new AppError('HTTPS_REQUIRED', 'Bitte HTTPS verwenden.', 400); }
    $config = new Config(Checkin\configPath()); $session = new SessionStore($config); $session->start();
    $flow = new Flow($config, $session); $state = $flow->state();
    if ($state['stage'] !== 'privacy') { header('Location: ./', true, 303); exit; }
    $job = $_SESSION['flow']['privacy']; $form = (new PrivacyForm($config))->current($job);
    $yaml = $form['yaml'];
    $context = ['flowId' => $state['id'], 'formToken' => $job['token'], 'csrf' => $_SESSION['csrf'],
        'timeout' => $config->get('privacy.timeout_seconds')];
    session_write_close();
} catch (Throwable $error) {
    $code = $error instanceof AppError ? $error->tag : 'INTERNAL';
    if ($flow) { $flow->block($code); }
    error_log('T2med Check-in: ' . $code);
    header('Location: ./', true, 303); exit;
}
function privacy_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Praxis Check-in">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="manifest.webmanifest">
<title>Datenschutz – Praxis Check-in</title>
<link rel="stylesheet" href="assets/privacy.css?v=1.6.4"><script defer src="assets/privacy.js?v=1.6.4"></script></head>
<body>
<input type="hidden" id="privacy-context" value="<?= privacy_h(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) ?>">
<header><span>Praxis Check-in</span><span>Datenschutz · Version <?= privacy_h($form['version']) ?></span></header>
<main>
<article id="document" tabindex="0" aria-label="Datenschutzinformation">
<p class="notice"><?= nl2br(privacy_h($state['message'] ?? '')) ?></p>
<h1><?= privacy_h($yaml['meta']['title']) ?></h1>
<p class="patient"><?= privacy_h($job['identity']['name']) ?> · Patientennummer <?= privacy_h($job['identity']['number']) ?></p>
<?php if (trim($yaml['meta']['warning_notice'] ?? '') !== ''): ?><p class="warning"><?= privacy_h($yaml['meta']['warning_notice']) ?></p><?php endif; ?>
<h2><?= privacy_h($yaml['document']['heading']) ?></h2>
<p><?= nl2br(privacy_h($yaml['document']['intro'] ?? '')) ?></p>
<?php foreach ($yaml['document']['sections'] as $section): ?>
<h2><?= privacy_h($section['title']) ?></h2><p><?= nl2br(privacy_h($section['text'])) ?></p>
<?php endforeach; ?>
</article>
<aside aria-label="Einwilligungen und Unterschrift">
<h2>Bitte lesen und unterschreiben</h2>
<label><input type="checkbox" id="privacy-email"> <span><?= privacy_h($yaml['consent']['checkbox_label']) ?></span></label>
<label><input type="checkbox" id="privacy-sms"> <span><?= privacy_h($yaml['consent']['sms_checkbox_label']) ?></span></label>
<label for="signature">Bitte im Feld unterschreiben</label>
<canvas id="signature" width="1000" height="500" aria-label="Hier mit dem Finger unterschreiben"></canvas>
<button id="clear-signature" class="secondary" type="button">Unterschrift löschen</button>
<p id="privacy-status" role="status" aria-live="polite"></p>
<button id="privacy-submit" type="button">Unterschreiben und weiter</button>
<button id="privacy-decline" class="secondary" type="button">Am Empfang klären</button>
</aside></main>
<noscript>Für die Unterschrift muss JavaScript aktiviert sein.</noscript>
</body></html>
