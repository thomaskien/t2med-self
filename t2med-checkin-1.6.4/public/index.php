<?php
declare(strict_types=1);
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob: data:; connect-src 'self'; media-src 'self' blob:; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="Praxis Check-in">
  <link rel="manifest" href="manifest.webmanifest">
  <meta name="theme-color" content="#f5f7f4">
  <title>Praxis Check-in</title>
  <link rel="stylesheet" href="assets/app.css?v=1.6.4">
  <script defer src="assets/app.js?v=1.6.4"></script>
</head>
<body>
  <div class="app-shell">
    <header class="topbar">
      <div class="brand"><span class="brand-symbol" aria-hidden="true">+</span><span>Praxis<span class="brand-light"> Check-in</span></span></div>
      <button id="staff" class="staff-button" type="button" aria-label="Mitarbeiter: Gerät abmelden" hidden>Mitarbeiter</button>
    </header>
    <main id="screen" tabindex="-1" aria-live="polite" aria-atomic="false"><p class="intro">Die Anmeldung wird vorbereitet …</p></main>
    <footer><span>Ihre Anmeldung in der Praxis</span><span id="version">Version 1.6.4</span></footer>
  </div>
  <noscript>Für Kartenanmeldung und Kamera muss JavaScript aktiviert sein.</noscript>
</body>
</html>
