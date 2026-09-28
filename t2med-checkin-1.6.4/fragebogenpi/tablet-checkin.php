<?php
// fragebogenpi Check-in renderer 1.8.3. Host supplies a validated view; no GDT I/O.
if (!isset($checkinView)) { http_response_code(404); exit; }
require_once __DIR__ . '/tablet-engine.php';
$yaml = $checkinView['yaml'];
$UI_TITLE = $yaml['meta']['title'] ?? 'Fragebogen';
$APP_FOOTER = 'Praxis Check-in'; $APP_VERSION = '1.6.4 / fragebogenpi 1.8.3';
$yamlError = $yaml['__error'] ?? '';
$sections = (isset($yaml['sections']) && is_array($yaml['sections'])) ? $yaml['sections'] : [];
$uiConfig = (isset($yaml['ui']) && is_array($yaml['ui'])) ? $yaml['ui'] : [];
$showContactSection = !array_key_exists('show_contact_section', $uiConfig) || $uiConfig['show_contact_section'] !== false;
$formHeading = ascii_only(clean_utf8_text((string)($uiConfig['heading'] ?? ''), 300));
?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="Praxis Check-in" />
  <link rel="manifest" href="manifest.webmanifest">
  <title><?php echo h(ascii_only($UI_TITLE)); ?></title>

  <link rel="stylesheet" href="questionnaire.php?asset=css&v=1.6.4">
</head>
<body>
  <div class="card">

    <div class="completion-notice"><b>Anmeldung abgeschlossen</b><p><?php echo h($checkinView['message']); ?></p></div>
    <h1><?php echo h($UI_TITLE); ?></h1>
    <p class="sub">Bitte beantworten Sie die Fragen. Größe und Gewicht sind freiwillig; Pflichtfragen sind gekennzeichnet.</p>
    <form id="anamForm" autocomplete="off" data-timeout="<?php echo (int) $checkinView['timeout']; ?>">
      <?php foreach (['flowId', 'formToken', 'csrf'] as $tokenField) { ?>
      <input type="hidden" name="<?php echo h($tokenField); ?>" value="<?php echo h($checkinView[$tokenField]); ?>">
      <?php } ?>

      <?php if ($showContactSection) { ?>
      <div class="section">
        <h2>Größe und Gewicht</h2>
        <div class="row">
          <div class="field">
            <label for="height_cm">Koerpergroesse (cm)</label>
            <input id="height_cm" name="height_cm" inputmode="numeric" placeholder="z. B. 180" />
          </div>
          <div class="field">
            <label for="weight_kg">Koerpergewicht (kg)</label>
            <input id="weight_kg" name="weight_kg" inputmode="decimal" placeholder="z. B. 82,5" />
          </div>
        </div>

      </div>
      <?php } ?>

      <?php if ($formHeading !== '') { ?>
        <h1 class="formHeading"><?php echo h($formHeading); ?></h1>
      <?php } ?>

      <?php foreach ($sections as $secIdx => $sec) {
        if (!is_array($sec)) continue;
        if (array_key_exists('ui_output', $sec) && $sec['ui_output'] === false) continue;
        $title = (string)($sec['title'] ?? '');
        if ($title === '') continue;
        $type  = (string)($sec['type'] ?? '');
        $questions = $sec['questions'] ?? [];
        if (!is_array($questions)) $questions = [];

        $secShow = $sec['show_if'] ?? null;
        $secAttr = '';
        if (is_array($secShow) && isset($secShow['id'])) {
            $secAttr = ' data-show-id="' . h((string)$secShow['id']) . '"';
            if (array_key_exists('equals', $secShow)) {
                $secAttr .= ' data-show-op="equals" data-show-val="' . h((string)$secShow['equals']) . '"';
            } elseif (array_key_exists('not_equals', $secShow)) {
                $secAttr .= ' data-show-op="not_equals" data-show-val="' . h((string)$secShow['not_equals']) . '"';
            } elseif (array_key_exists('in', $secShow)) {
                $secAttr .= ' data-show-op="in" data-show-val="' . h(json_encode(array_values((array)$secShow['in']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
            } elseif (array_key_exists('any_selected_except', $secShow)) {
                $secAttr .= ' data-show-op="any_selected_except" data-show-val="' . h((string)$secShow['any_selected_except']) . '"';
            }
        }
      ?>
        <div class="section" data-section="<?php echo h((string)$secIdx); ?>"<?php echo $secAttr; ?>>
          <h2><?php echo h(ascii_only($title)); ?></h2>

          <?php if ($type === 'checklist') { ?>
            <div class="checkgrid">
              <?php foreach ($questions as $q) {
                if (!is_array($q)) continue;

                $qType = (string)($q['type'] ?? '');
                $label = (string)($q['label'] ?? '');

                if ($qType === 'header') {
                  if ($label !== '') echo '<div class="checkHeader">'.h(ascii_only($label)).'</div>';
                  continue;
                }

                $id = (string)($q['id'] ?? '');
                if ($id === '' || $label === '') continue;
              ?>
                <label class="check" data-qwrap="1" data-qid="<?php echo h($id); ?>" data-qtype="<?php echo h($qType); ?>" data-required="<?php echo !empty($q['required']) ? '1' : '0'; ?>" data-qlabel="<?php echo h(ascii_only($label)); ?>">
                  <input type="checkbox" name="q[<?php echo h($id); ?>]" value="1" />
                  <span><?php echo h(ascii_only($label)); ?></span>
                </label>
              <?php } ?>
            </div>
          <?php } else { ?>
            <?php foreach ($questions as $q) {
              if (!is_array($q)) continue;
              $id = (string)($q['id'] ?? '');
              $label = (string)($q['label'] ?? '');
              $qType = (string)($q['type'] ?? '');
              $opts = $q['options'] ?? [];
              $isRequired = !empty($q['required']);
              if ($id === '' || $label === '') continue;

              $show = $q['show_if'] ?? null;
              $wrapAttr = '';
              if (is_array($show) && isset($show['id'])) {
                  $wrapAttr = ' data-show-id="' . h((string)$show['id']) . '"';
                  if (array_key_exists('equals', $show)) {
                      $wrapAttr .= ' data-show-op="equals" data-show-val="' . h((string)$show['equals']) . '"';
                  } elseif (array_key_exists('not_equals', $show)) {
                      $wrapAttr .= ' data-show-op="not_equals" data-show-val="' . h((string)$show['not_equals']) . '"';
                  } elseif (array_key_exists('in', $show)) {
                      $wrapAttr .= ' data-show-op="in" data-show-val="' . h(json_encode(array_values((array)$show['in']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
                  } elseif (array_key_exists('any_selected_except', $show)) {
                      $wrapAttr .= ' data-show-op="any_selected_except" data-show-val="' . h((string)$show['any_selected_except']) . '"';
                  }
              }

              if ($qType === 'derived') {
                  echo '<div class="field hidden" data-qwrap="1" data-qid="'.h($id).'"'.$wrapAttr.'></div>';
                  continue;
              }
            ?>
              <div class="field<?php echo $qType === 'scale' ? ' scaleQuestion' : ''; ?>" data-qwrap="1" data-qid="<?php echo h($id); ?>" data-qtype="<?php echo h($qType); ?>" data-required="<?php echo $isRequired ? '1' : '0'; ?>" data-qlabel="<?php echo h(ascii_only($label)); ?>"<?php echo $wrapAttr; ?>>

              <?php if ($qType === 'scale') {
                  $scale = (isset($q['scale']) && is_array($q['scale'])) ? $q['scale'] : [];
                  $scaleMin = isset($scale['minimum']) && is_numeric($scale['minimum']) ? (float)$scale['minimum'] : 0.0;
                  $scaleMax = isset($scale['maximum']) && is_numeric($scale['maximum']) ? (float)$scale['maximum'] : 10.0;
                  $scaleStep = isset($scale['step']) && is_numeric($scale['step']) && (float)$scale['step'] > 0 ? (float)$scale['step'] : 1.0;
                  $scaleInitial = $scaleMin + round((($scaleMax - $scaleMin) / 2) / $scaleStep) * $scaleStep;
                  $scaleLeft = (string)($scale['left_label'] ?? numeric_text($scaleMin));
                  $scaleRight = (string)($scale['right_label'] ?? numeric_text($scaleMax));
                  $tickCount = (int)floor(($scaleMax - $scaleMin) / $scaleStep) + 1;
                  if ($tickCount < 2 || $tickCount > 21) $tickCount = 0;
              ?>
                  <label for="<?php echo h('scale_'.$id); ?>">
                    <?php echo h(ascii_only($label)); ?>
                    <?php if ($isRequired) { ?><span class="requiredHint">(Pflichtfeld)</span><?php } ?>
                  </label>
                  <input type="hidden" id="<?php echo h('q_'.$id); ?>" name="q[<?php echo h($id); ?>]" value="" />
                  <div class="scaleValue" data-scale-value="1" aria-live="polite">Bitte auswaehlen</div>
                  <div class="scaleEndpoints">
                    <span><b><?php echo h(numeric_text($scaleMin)); ?></b> – <?php echo h(ascii_only($scaleLeft)); ?></span>
                    <span><b><?php echo h(numeric_text($scaleMax)); ?></b> – <?php echo h(ascii_only($scaleRight)); ?></span>
                  </div>
                  <input
                    id="<?php echo h('scale_'.$id); ?>"
                    class="scaleRange"
                    type="range"
                    min="<?php echo h(numeric_text($scaleMin)); ?>"
                    max="<?php echo h(numeric_text($scaleMax)); ?>"
                    step="<?php echo h(numeric_text($scaleStep)); ?>"
                    value="<?php echo h(numeric_text($scaleInitial)); ?>"
                    data-scale-range="1"
                    data-answer-target="<?php echo h('q_'.$id); ?>"
                    aria-label="<?php echo h(ascii_only($label)); ?>"
                    aria-valuetext="Bitte auswaehlen"
                  />
                  <?php if ($tickCount > 0) { ?>
                    <div class="scaleTicks" role="group" aria-label="Wert direkt auswaehlen">
                      <?php for ($tick = 0; $tick < $tickCount; $tick++) { ?>
                        <?php $tickValue = numeric_text($scaleMin + $tick * $scaleStep); ?>
                        <button
                          type="button"
                          class="scaleTick"
                          data-scale-tick="1"
                          data-range-target="<?php echo h('scale_'.$id); ?>"
                          data-scale-value-option="<?php echo h($tickValue); ?>"
                          aria-label="<?php echo h(ascii_only($label) . ': ' . $tickValue); ?>"
                          aria-pressed="false"
                        ><?php echo h($tickValue); ?></button>
                      <?php } ?>
                    </div>
                  <?php } ?>
              <?php } elseif ($qType === 'yesno') { ?>
                  <label><?php echo h(ascii_only($label)); ?></label>
                  <div class="radioRow">
                    <label class="radioPill">
                      <input type="radio" name="q[<?php echo h($id); ?>]" value="yes" />
                      <span>Ja</span>
                    </label>
                    <label class="radioPill">
                      <input type="radio" name="q[<?php echo h($id); ?>]" value="no" />
                      <span>Nein</span>
                    </label>
                  </div>
              <?php } elseif ($qType === 'choice' && is_array($opts)) { ?>
                  <label><?php echo h(ascii_only($label)); ?></label>
                  <div class="radioRow">
                    <?php foreach ($opts as $opt) {
                      $opt = (string)$opt;
                      if ($opt === '') continue;
                    ?>
                      <label class="radioPill">
                        <input type="radio" name="q[<?php echo h($id); ?>]" value="<?php echo h(ascii_only($opt)); ?>" />
                        <span><?php echo h(ascii_only($opt)); ?></span>
                      </label>
                    <?php } ?>
                  </div>
              <?php } elseif ($qType === 'multiselect' && is_array($opts)) { ?>
                  <label><?php echo h(ascii_only($label)); ?></label>
                  <div class="checkgrid">
                    <?php foreach ($opts as $opt) {
                      $opt = (string)$opt;
                      if ($opt === '') continue;
                    ?>
                      <label class="check">
                        <input type="checkbox" name="q[<?php echo h($id); ?>][]" value="<?php echo h(ascii_only($opt)); ?>" />
                        <span><?php echo h(ascii_only($opt)); ?></span>
                      </label>
                    <?php } ?>
                  </div>
              <?php } elseif ($qType === 'number') { ?>
                  <label for="<?php echo h('q_'.$id); ?>"><?php echo h(ascii_only($label)); ?></label>
                  <input id="<?php echo h('q_'.$id); ?>" name="q[<?php echo h($id); ?>]" inputmode="numeric" />
              <?php } else { ?>
                  <label for="<?php echo h('q_'.$id); ?>"><?php echo h(ascii_only($label)); ?></label>
                  <?php $ml = !empty($q['multiline']); ?>
                  <?php if ($ml) { ?>
                    <textarea id="<?php echo h('q_'.$id); ?>" name="q[<?php echo h($id); ?>]" maxlength="600"></textarea>
                  <?php } else { ?>
                    <input id="<?php echo h('q_'.$id); ?>" name="q[<?php echo h($id); ?>]" maxlength="600" />
                  <?php } ?>
              <?php } ?>

              </div>
            <?php } ?>
          <?php } ?>

        </div>
      <?php } ?>

      <button id="submitBtn" type="button">Angaben speichern und weiter</button>
      <button id="abortBtn" type="button">Fragebögen überspringen</button>

      <div id="status" role="alert"></div>
      <div class="footer"><?php echo h(ascii_only($APP_FOOTER . ' · ' . $APP_VERSION)); ?></div>
    </form>
  </div>

  <script src="questionnaire.php?asset=js&v=1.6.4" defer></script>
</body>
</html>
