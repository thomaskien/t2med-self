<?php
declare(strict_types=1);

/*
 * tablet.php v1.8.3
 * fragebogenpi.de von Dr. Thomas Kienzle 2026
 *
 * Changelog (vollstaendig)
 * - v1.8.3:
 *   + Gemeinsame unveraenderte YAML-Helfer in tablet-engine.php fuer den
 *     separaten Check-in-Einstieg tablet-checkin.php. GDT-Ablauf bleibt erhalten.
 * - v1.8.2:
 *   + YAML kann ueber meta.handler einen spezialisierten PHP-Formularhandler
 *     deklarieren; die Tablet-Warteschlange uebergibt Auftrag und Ruecksprungziel.
 * - v1.8.1:
 *   + Formulare koennen ueber ui.heading eine sichtbare Ueberschrift oberhalb
 *     ihrer YAML-Sektionen anzeigen.
 * - v1.8.0:
 *   + Score-Ausgaben enthalten automatisch die konfigurierte Maximalpunktzahl,
 *     zum Beispiel "Punktwert: 22/25 Punkte".
 * - v1.7.10:
 *   + Fehlende Pflichtantworten werden nach dem Absendeversuch als kompletter
 *     Fragenblock deutlich rot hervorgehoben.
 *   + Die Pflichtfeldpruefung benoetigt kein CSS.escape mehr und funktioniert
 *     dadurch auch in aelteren Tablet-Browsern zuverlaessig.
 *   + Formularseiten werden nicht im Browsercache gespeichert, damit neue
 *     PHP-, JavaScript- und CSS-Staende sofort geladen werden.
 * - v1.7.9:
 *   + YAML-Dateien koennen unter meta.form_ids zusaetzliche GDT-Formular-IDs
 *     deklarieren; exakte Dateinamen bleiben dabei vorrangig.
 * - v1.7.8:
 *   + Nach dem ersten Absendeversuch werden unbeantwortete Pflichtfragen rot
 *     markiert und anschliessend dynamisch aktualisiert.
 *   + Beim Laden eines Folgeformulars springt der Browser an den Seitenanfang.
 * - v1.7.7:
 *   + Sichtbare unbeantwortete Pflichtfragen werden bereits beim Anzeigen
 *     und fortlaufend nach jeder Eingabe rot markiert.
 * - v1.7.6:
 *   + Bedingungen fuer Folgeformulare unterstuetzen den allgemeinen
 *     numerischen Vergleich greater_than_or_equal.
 * - v1.7.5:
 *   + Neuer allgemeiner GDT-Baustein answer_list zur vollstaendigen Ausgabe
 *     konfigurierter Antworten als "Frage: Antwort".
 *   + GDT-Sektionen koennen die Anzahl vorangestellter --- Trennzeilen ueber
 *     separator_lines selbst festlegen.
 *   + Score-Elemente koennen fuer den Wert 1 eine eigene Einzahl-Endung ueber
 *     singular_suffix definieren.
 * - v1.7.4:
 *   + Direkter Tap auf eine Slider-Position springt sofort auf den naechsten
 *     Rastwert; die angezeigten Skalenwerte sind ebenfalls direkt antippbar.
 * - v1.7.3:
 *   + Neuer allgemeiner YAML-Fragetyp scale fuer diskrete, zunaechst
 *     unbeantwortete Tablet-Slider mit frei definierbaren Endpunkten.
 *   + Pflichtfragen werden im Browser und serverseitig geprueft.
 *   + Die GDT-Ausgabe kann allgemein ueber gdt.sections/elements aus score-,
 *     interpretation- und problem_list-Bausteinen zusammengesetzt werden.
 *   + Numerische Skalen liefern ihren Wert direkt als Score; neue Frageboegen
 *     benoetigen dadurch keine formularspezifische PHP-Logik.
 * - v1.7.2:
 *   + GDT-Problemfelder koennen neben einem maximalen nun auch einen minimalen
 *     auffaelligen Punktwert definieren, etwa fuer den COPD Assessment Test.
 * - v1.7.1:
 *   + YAML-gesteuerte Summen, Bereichsbewertungen und kompakte GDT-Zusammenfassungen.
 *   + Auffaellige Choice-Antworten koennen anhand ihres Punktwerts als
 *     Problemfelder in der GDT ausgegeben werden.
 * - v1.7.0:
 *   + YAML kann bedingte Folgeformulare ueber follow_up_forms definieren.
 *   + Nach erfolgreicher Uebermittlung werden Folgeauftraege als -i.gdt im
 *     bestehenden GDT-Ordner angelegt und anschliessend nach Prioritaet verarbeitet.
 *   + Folgeformulare koennen ihrerseits weitere Folgeformulare ausloesen.
 *   + Folgeformular-IDs werden validiert; Selbstreferenzen und fehlende YAML-Dateien
 *     werden als Konfigurationsfehler gemeldet.
 * - v1.6.4:
 *   + Neuer generischer Tablet-Einstiegspunkt neben anamnesebogen.php.
 *   + Neue GDT-Namen anam-i.gdt/anam-o.gdt bzw. mit Tablet-Praefix.
 *   + Formularauswahl aus dem GDT-Dateinamen und YAML-Dateien ausserhalb des Webroots.
 *   + Optionaler Tablet-Praefix fuer parallele Endgeraete.
 *   + Konfliktpruefung der Patientendaten fuer alle offenen Eingabe-GDTs eines Tablets.
 * - v1.0:
 *   + Uebernahme aus befund.php als Template/Grundworkflow
 *   + Auftrags-GDT finden (fester Dateiname), Formular anzeigen, Antwort-GDT 6310 schreiben, Auftrags-GDT loeschen
 *   + YAML-basierte Fragen/Checkboxen/Choice/Multiselect (editierbar per Texteditor), Ausgabe in 6228 als strukturierte Bloecke
 * - v1.1:
 *   + Kontaktfelder (Telefon/E-Mail) editierbar; Uebernahme aus Request-Feldern (3619, 3626, 3618)
 *   + Wenn Kontaktinfos abweichen: 6228-Block "Aktualisierte Kontaktinformationen" ganz oben
 * - v1.2:
 *   + Anzeige oben: Adresse entfernt (nur Name, Vorname, Geburtsdatum)
 *   + Geburtsdatum (3103) als 8 Ziffern parsen (ggf. abschneiden) und als DD.MM.YYYY anzeigen
 *   + Packyears aus Rauchen (Zigaretten/Tag und Jahre) berechnen und als "mind. X Packyears" ausgeben
 *   + Alkohol: Feld "Getraenke pro Woche" nur wenn Alkoholkonsum != nein
 * - v1.2.1:
 *   + Fix Packyears: yes/no werden intern als "yes"/"no" gespeichert (kompatibel zu YAML show_if)
 *   + Fix Packyears: derived-Ausgabe funktioniert stabil (auch wenn show_if aktiv ist)
 *   + Packyears-Rundung: Abrunden (floor) auf ganze Packyears, mindestens 1 wenn >0
 * - v1.3:
 *   + ASCII-only: Alle Umlaute/Unicode werden konsequent transliteriert (ue/oe/ae/ss) in UI UND GDT-Text
 *   + YAML-Parsing: Strings aus YAML werden beim Einlesen transliteriert (Titel/Labels/Optionen)
 *   + GDT-Ausgabe fuer x.concept robuster:
 *       * Zeilenlaengen berechnen inkl. CRLF (wie in der funktionierenden Referenzdatei)
 *       * 0193: Wenn in Request vorhanden -> uebernehmen; sonst 3000 verwenden; 3000 bleibt zusaetzlich erhalten
 *       * Satzende ueber Feld 4121 (wie Referenzdatei), 9999 wird nicht mehr geschrieben
 * - v1.3.1:
 *   + Choice-Felder (Radio-Gruppen) ohne Default: initial unselektiert (kein automatisch "gut"/erstes Element)
 *   + Backend: fehlende Choice-Auswahl bleibt leer und wird nicht ausgegeben (nur bewusst ausgewaehlte Inhalte)
 * - v1.4.0:
 *   + UI: Unterueberschriften/Headers innerhalb checklist-Sections (YAML question type: "header") werden angezeigt
 *   + show_if erweitert: "in" und "any_selected_except" (Client+Server konsistent)
 * - v1.4.1:
 *   + x.concept Fix: 0193/3000 in der Antwortdatei jetzt EXKLUSIV (3000 hat Vorrang)
 * - v1.4.2:
 *   + x.concept Fix zusaetzlich: Wenn 3000 verwendet wird, wird Feld 6200 (ANA1) NICHT geschrieben
 * - v1.4.3:
 *   + Workaround: Wenn 3000 verwendet wird, zusaetzliches 8000=6310 am Dateiende
 * - v1.4.4:
 *   + Erweiterter Workaround (3000-Fall): Datei endet mit:
 *       01041211
 *       01380006310
 *       01041211
 * - v1.4.5:
 *   + Umlaut-Fix: Request fuer UI sauber transliterieren; Antwort-GDT Request-Felder bytegenau ausgeben
 *   + Projektname: fragebogenpi.de
 *   + Web-App Modus fuer iPad (Meta-Tags/Viewport), Design sonst unveraendert
 * - v1.4.6:
 *   + UI: mehr Abstand oben im WebApp-Modus (safe-area + padding-top)
 *   + GDT: x.concept Workaround-Zeilen (zus. 4121/8000/4121) als Konfigurationsoption schaltbar, Default AUS
 */

$APP_FOOTER  = 'fragebogenpi.de von Dr. Thomas Kienzle 2026';
$APP_VERSION = 'v1.8.3 (tablet.php)';

$dirGdt = '/srv/fragebogenpi/GDT';

// Auftragsdatei (Request) wird aus Tablet-Praefix und Formular-ID ermittelt.
$REQUEST_GDT_NAME = '';

// Antwortdatei verwendet dasselbe Praefix und die Endung -o.gdt.
$OUT_GDT_NAME = '';

// YAML-Konfiguration (Fragen):
$FORM_DIR = '/srv/fragebogenpi/formulare';
$FORM_ID_MAX_LENGTH = 4;

$scriptBase = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'tablet.php'));
$tabletId = '';
if (preg_match('/^tablet([1-9])\\.php$/', $scriptBase, $m)) {
    $tabletId = $m[1];
}
$tabletPrefix = ($tabletId === '') ? '' : ($tabletId . '-');
$configuredTabletCount = 1;
$tabletCountFile = '/etc/fragebogenpi/tablet-count';
if (is_file($tabletCountFile)) {
    $configuredValue = trim((string)@file_get_contents($tabletCountFile));
    if (preg_match('/^[1-9]$/', $configuredValue)) {
        $configuredTabletCount = (int)$configuredValue;
    }
}
if ($tabletId === '' && $configuredTabletCount > 1) {
    http_response_code(404);
    exit('tablet.php ist im Mehrgeraetebetrieb kein aktiver Endpunkt.');
}

// Default IDs falls 8315/8316 in der Request fehlen:
$DEFAULT_8315 = 'BOGI_GDT';  // 8315 soll BOGI_GDT sein
$DEFAULT_8316 = 'BIMP_GDT';

// Identitaet der Antwort (GDT 6200/6201):
$ANSWER_6200 = 'ANA1';
$ANSWER_6201 = 'KI-Anamnese';

// UI-Titel:
$UI_TITLE = 'Anamnese (Tablet)';

// Maximale Laenge pro 6228-Zeile (ASCII/CP437-safe Bytes):
$MAX_6228_BYTES = 70;

// ------------------------------------------------------------
// KONFIG: x.concept Workaround am Ende bei 3000?
// Default AUS, weil T2med diese Zusatzzeilen nicht mag.
// Wenn du x.concept einsetzt -> hier auf true setzen.
// ------------------------------------------------------------
$ENABLE_XCONCEPT_3000_END_WORKAROUND = false;

// Shared YAML engine; GDT transport below stays unchanged.
require_once __DIR__ . '/tablet-engine.php';

// ----------------- dir checks -----------------
if (!is_dir($dirGdt)) @mkdir($dirGdt, 0775, true);
if ((!is_dir($dirGdt) || !is_writable($dirGdt)) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    json_out(500, ['status'=>'error','message'=>'Zielverzeichnis existiert nicht oder ist nicht beschreibbar','dir'=>$dirGdt]);
}

// ----------------- request gdt -----------------
$dispatch = collect_tablet_requests($dirGdt, $FORM_DIR, $tabletId, $FORM_ID_MAX_LENGTH);
$dispatchError = '';
$assignmentError = false;

if (requests_have_identity_conflict($dispatch['all'])) {
    delete_request_files($dispatch['all']);
    $dispatchError = 'Zuordnungsfehler, bitte bei Mitarbeiter melden';
    $assignmentError = true;
} elseif (count($dispatch['errors']) > 0) {
    $dispatchError = implode(' ', $dispatch['errors']);
}

$selectedRequest = $dispatch['requests'][0] ?? null;
$hasRequest = is_array($selectedRequest);
$requestPath = $hasRequest ? (string)$selectedRequest['path'] : '';
$REQUEST_GDT_NAME = $hasRequest ? (string)$selectedRequest['name'] : '';
$OUT_GDT_NAME = $hasRequest
    ? ($tabletPrefix . (string)$selectedRequest['form_id'] . '-o.gdt')
    : '';
$YAML_PATH = $hasRequest ? (string)$selectedRequest['yaml_path'] : '';
$reqFields = $hasRequest ? (array)$selectedRequest['fields'] : [];

if ($hasRequest && !has_patient_identity($reqFields)) {
    $dispatchError = 'Weder 3000 noch 0193 in der Auftrags-GDT vorhanden';
}

if ($hasRequest && $YAML_PATH !== '') {
    $initialYaml = yaml_load_or_die_ascii($YAML_PATH);
    if (!isset($initialYaml['__error']) && isset($initialYaml['meta']['title'])) {
        $UI_TITLE = ascii_only((string)$initialYaml['meta']['title']) . ' (Tablet)';
    }
    $specialHandler = (string)($initialYaml['meta']['handler'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $specialHandler !== '') {
        if (!preg_match('/^[a-z][a-z0-9_-]*\\.php$/', $specialHandler)) {
            $dispatchError = 'Ungueltiger Formularhandler: ' . $specialHandler;
        } else {
            $returnTo = basename((string)($_SERVER['SCRIPT_NAME'] ?? 'tablet.php'));
            if (!preg_match('/^tablet(?:[1-9])?\\.php$/', $returnTo)) $returnTo = 'tablet.php';
            $query = http_build_query([
                'request_gdt' => $REQUEST_GDT_NAME,
                'return_to' => $returnTo,
            ]);
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Location: ' . $specialHandler . '?' . $query, true, 302);
            exit;
        }
    }
}

$vorname_raw  = $reqFields['3102'] ?? '';
$nachname_raw = $reqFields['3101'] ?? '';
$gebdat_raw   = $reqFields['3103'] ?? '';

// UI
$vorname_ui  = translit_for_ui($vorname_raw);
$nachname_ui = translit_for_ui($nachname_raw);
$gebdat_ui   = format_gebdat(req_to_utf8_for_ui($gebdat_raw));

$displayName_ui = trim(trim($vorname_ui . ' ' . $nachname_ui));
if ($displayName_ui === '') $displayName_ui = '—';

$reqEmail   = $reqFields['3619'] ?? '';
$reqPhone1  = $reqFields['3626'] ?? '';
$reqPhone2  = $reqFields['3618'] ?? '';

$patId3000 = $reqFields['3000'] ?? '';
$kennfeld  = $reqFields['8402'] ?? 'ALLG0';
if ($kennfeld === '') $kennfeld = 'ALLG0';

// sender/receiver swap
$req8315   = $reqFields['8315'] ?? '';
$req8316   = $reqFields['8316'] ?? '';
$ans8315 = ($req8316 !== '') ? $req8316 : $DEFAULT_8315;
$ans8316 = ($req8315 !== '') ? $req8315 : $DEFAULT_8316;

// 0193/3000 exklusiv (3000 hat Vorrang)
$req0193 = $reqFields['0193'] ?? '';
$use3000_only = ($patId3000 !== '');
$use0193_only = (!$use3000_only && $req0193 !== '');

$ans3000 = $use3000_only ? $patId3000 : '';
$ans0193 = $use0193_only ? $req0193 : '';

// optional meta
$req4109 = $reqFields['4109'] ?? '';
$req4104 = $reqFields['4104'] ?? '';

// ----------------- POST -----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $liveDispatch = collect_tablet_requests($dirGdt, $FORM_DIR, $tabletId, $FORM_ID_MAX_LENGTH);
    if (requests_have_identity_conflict($liveDispatch['all'])) {
        delete_request_files($liveDispatch['all']);
        json_out(409, [
            'status' => 'assignment_error',
            'message' => 'Zuordnungsfehler, bitte bei Mitarbeiter melden',
            'display_seconds' => 20,
        ]);
    }

    $postedRequestName = basename((string)($_POST['request_gdt'] ?? $REQUEST_GDT_NAME));
    $postedRequest = null;
    foreach ($liveDispatch['requests'] as $liveRequest) {
        if ((string)$liveRequest['name'] === $postedRequestName) {
            $postedRequest = $liveRequest;
            break;
        }
    }
    if (!is_array($postedRequest)) {
        json_out(409, ['status'=>'error','message'=>'Auftrags-GDT nicht mehr vorhanden. Bitte erneut versuchen.']);
    }

    $selectedRequest = $postedRequest;
    $requestPath = (string)$selectedRequest['path'];
    $REQUEST_GDT_NAME = (string)$selectedRequest['name'];
    $OUT_GDT_NAME = $tabletPrefix . (string)$selectedRequest['form_id'] . '-o.gdt';
    $YAML_PATH = (string)$selectedRequest['yaml_path'];
    $reqFields = (array)$selectedRequest['fields'];
    $hasRequest = true;

    $vorname_raw = $reqFields['3102'] ?? '';
    $nachname_raw = $reqFields['3101'] ?? '';
    $gebdat_raw = $reqFields['3103'] ?? '';
    $reqEmail = $reqFields['3619'] ?? '';
    $reqPhone1 = $reqFields['3626'] ?? '';
    $reqPhone2 = $reqFields['3618'] ?? '';
    $patId3000 = $reqFields['3000'] ?? '';
    $kennfeld = $reqFields['8402'] ?? 'ALLG0';
    if ($kennfeld === '') $kennfeld = 'ALLG0';
    $req8315 = $reqFields['8315'] ?? '';
    $req8316 = $reqFields['8316'] ?? '';
    $ans8315 = ($req8316 !== '') ? $req8316 : $DEFAULT_8315;
    $ans8316 = ($req8315 !== '') ? $req8315 : $DEFAULT_8316;
    $req0193 = $reqFields['0193'] ?? '';
    $use3000_only = ($patId3000 !== '');
    $use0193_only = (!$use3000_only && $req0193 !== '');
    $ans3000 = $use3000_only ? $patId3000 : '';
    $ans0193 = $use0193_only ? $req0193 : '';
    $req4109 = $reqFields['4109'] ?? '';
    $req4104 = $reqFields['4104'] ?? '';

    if (!$hasRequest) json_out(409, ['status'=>'error','message'=>'Keine Auftrags-GDT gefunden ('.$REQUEST_GDT_NAME.').']);
    if ($ans3000 === '' && $ans0193 === '') json_out(422, ['status'=>'error','message'=>'Weder 3000 noch 0193 in der Auftrags-GDT vorhanden']);

    if (($_POST['action'] ?? '') === 'abort') {
        $deleted = @unlink($requestPath);
        json_out(200, ['status'=>'ok','message'=>'abgebrochen','request_deleted'=>$deleted,'request_gdt'=>$REQUEST_GDT_NAME]);
    }

    $yaml = yaml_load_or_die_ascii($YAML_PATH);
    if (isset($yaml['__error'])) json_out(500, ['status'=>'error','message'=>$yaml['__error'],'yaml'=>$YAML_PATH]);

    // hardcoded top fields
    $height = ascii_only(clean_utf8_text((string)($_POST['height_cm'] ?? ''), 10));
    $weight = ascii_only(clean_utf8_text((string)($_POST['weight_kg'] ?? ''), 10));
    $phone1 = ascii_only(clean_utf8_text((string)($_POST['phone1'] ?? ''), 70));
    $phone2 = ascii_only(clean_utf8_text((string)($_POST['phone2'] ?? ''), 70));
    $email  = ascii_only(clean_utf8_text((string)($_POST['email']  ?? ''), 70));

    // parse YAML-driven answers
    $rawQ = $_POST['q'] ?? [];
    if (!is_array($rawQ)) $rawQ = [];

    $answers = [];
    $sections = $yaml['sections'] ?? [];
    if (!is_array($sections)) $sections = [];

    foreach ($sections as $sec) {
        if (!is_array($sec)) continue;
        $secType = (string)($sec['type'] ?? '');
        $questions = $sec['questions'] ?? [];
        if (!is_array($questions)) continue;

        if ($secType === 'checklist') {
            foreach ($questions as $q) {
                if (!is_array($q)) continue;
                $qType = (string)($q['type'] ?? '');
                if ($qType === 'header') continue;

                $id = (string)($q['id'] ?? '');
                if ($id === '') continue;
                $answers[$id] = !empty($rawQ[$id]);
            }
            continue;
        }

        foreach ($questions as $q) {
            if (!is_array($q)) continue;
            $id = (string)($q['id'] ?? '');
            $type = (string)($q['type'] ?? '');
            if ($id === '') continue;

            if ($type === 'multiselect') {
                $v = $rawQ[$id] ?? [];
                if (!is_array($v)) $v = [];
                $answers[$id] = array_values(array_filter(array_map(
                    fn($x) => ascii_only(clean_utf8_text((string)$x, 200)),
                    $v
                ), fn($x) => $x !== ''));
                continue;
            }

            if ($type === 'yesno') {
                $v = (string)($rawQ[$id] ?? '');
                $answers[$id] = ($v === 'yes') ? 'yes' : 'no';
                continue;
            }

            if ($type === 'derived') continue;

            $answers[$id] = ascii_only(clean_utf8_text((string)($rawQ[$id] ?? ''), 600));
        }
    }

    $answerErrors = validate_yaml_answers($yaml, $answers);
    if (count($answerErrors) > 0) {
        json_out(422, [
            'status' => 'error',
            'message' => implode(' ', $answerErrors),
            'details' => $answerErrors,
        ]);
    }

    // derive packyears
    $isSmoker = (($answers['raucher'] ?? 'no') === 'yes');
    $cigs = parse_float_de((string)($answers['rauchen_zigaretten_tag'] ?? ''));
    $yrs  = parse_float_de((string)($answers['rauchen_jahre'] ?? ''));

    if ($isSmoker && $cigs !== null && $yrs !== null && $cigs > 0 && $yrs > 0) {
        $py = ($cigs / 20.0) * $yrs;
        $pyInt = (int)floor($py);
        if ($pyInt < 1 && $py > 0) $pyInt = 1;
        $answers['_packyears_text'] = 'mind. ' . $pyInt . ' Packyears';
    } else {
        $answers['_packyears_text'] = '';
    }

    derive_yaml_answers($yaml, $answers);

    $followUpPlan = follow_up_forms_for_answers(
        $yaml,
        $answers,
        (string)$selectedRequest['form_id'],
        $FORM_DIR,
        $FORM_ID_MAX_LENGTH
    );
    if (count($followUpPlan['errors']) > 0) {
        json_out(500, [
            'status' => 'error',
            'message' => 'Folgeformular-Konfiguration ungueltig.',
            'details' => $followUpPlan['errors'],
        ]);
    }

    // Build 6228 lines
    $lines6228 = [];

    $chgBullets = [];
    if (norm_contact($phone1) !== '' && norm_contact($phone1) !== norm_contact($reqPhone1)) $chgBullets[] = 'Telefon 1: ' . $phone1;
    if (norm_contact($phone2) !== '' && norm_contact($phone2) !== norm_contact($reqPhone2)) $chgBullets[] = 'Telefon 2: ' . $phone2;
    if (norm_contact($email)  !== '' && norm_contact($email)  !== norm_contact($reqEmail))  $chgBullets[] = 'E-Mail: ' . $email;

    if (count($chgBullets) > 0) {
        $lines6228 = array_merge($lines6228, build_section_block_lines('Aktualisierte Kontaktinformationen', $chgBullets, $MAX_6228_BYTES));
    }

    $lines6228 = array_merge($lines6228, build_6228_blocks($yaml, $answers, $MAX_6228_BYTES));

    // Compose answer GDT 6310
    $lines = [];
    $lines[] = gdt_line('8000', '6310');
    $lines[] = gdt_line('8100', '000000');
    $lines[] = gdt_line('9218', '02.00');

    // EXKLUSIV: entweder 3000 ODER 0193
    if ($ans3000 !== '') {
        $lines[] = gdt_line('3000', $ans3000);
    } elseif ($ans0193 !== '') {
        $lines[] = gdt_line('0193', $ans0193);
    }

    $lines[] = gdt_line('8402', $kennfeld);

    // Request-Felder bytegenau
    if ($nachname_raw !== '') $lines[] = gdt_line('3101', req_value_passthrough($nachname_raw, 120));
    if ($vorname_raw  !== '') $lines[] = gdt_line('3102', req_value_passthrough($vorname_raw, 120));
    if ($gebdat_raw   !== '') $lines[] = gdt_line('3103', req_value_passthrough($gebdat_raw, 40));

    $lines[] = gdt_line('8315', $ans8315);
    $lines[] = gdt_line('8316', $ans8316);

    if ($req4109 !== '') $lines[] = gdt_line('4109', $req4109);
    if ($req4104 !== '') $lines[] = gdt_line('4104', $req4104);

    if ($height !== '') $lines[] = gdt_line('3622', $height);
    if ($weight !== '') $lines[] = gdt_line('3623', $weight);
    if ($phone1 !== '') $lines[] = gdt_line('3626', $phone1);
    if ($phone2 !== '') $lines[] = gdt_line('3618', $phone2);
    if ($email  !== '') $lines[] = gdt_line('3619', $email);

    // Absenderkennung: bei 3000 darf 6200 NICHT geschrieben werden
    if ($ans0193 !== '') {
        $lines[] = gdt_line('6200', ascii_only($ANSWER_6200));
    }
    $lines[] = gdt_line('6201', ascii_only($ANSWER_6201));

    foreach ($lines6228 as $l) $lines[] = $l;

    $lines[] = gdt_line('4109', ymd_today());
    $lines[] = gdt_line('4121', '1');

    // OPTIONAL: Workaround nur wenn explizit aktiviert UND 3000-Fall
    if ($ENABLE_XCONCEPT_3000_END_WORKAROUND && $ans3000 !== '') {
        $lines[] = gdt_line('8000', '6310');
        $lines[] = gdt_line('4121', '1');
    }

    $outGdtPath = rtrim($dirGdt, '/') . '/' . $OUT_GDT_NAME;
    write_gdt_file($outGdtPath, $lines);

    $followUpResult = create_follow_up_requests(
        $dirGdt,
        $tabletPrefix,
        $requestPath,
        (string)$selectedRequest['form_id'],
        $followUpPlan['forms']
    );
    if (count($followUpResult['errors']) > 0) {
        json_out(500, [
            'status' => 'error',
            'message' => 'Folgeformular konnte nicht angelegt werden. Der aktuelle Auftrag bleibt bestehen.',
            'details' => $followUpResult['errors'],
        ]);
    }

    $deleted = @unlink($requestPath);

    json_out(200, [
        'status'          => 'ok',
        'message'         => 'Anamnese uebermittelt',
        'answer_gdt'      => $OUT_GDT_NAME,
        'request_gdt'     => $REQUEST_GDT_NAME,
        'request_deleted' => $deleted,
        'contact_changed' => (count($chgBullets) > 0),
        'id_0193_used'    => $ans0193,
        'id_3000_used'    => $ans3000,
        'packyears'       => (string)($answers['_packyears_text'] ?? ''),
        'xconcept_workaround' => ($ENABLE_XCONCEPT_3000_END_WORKAROUND && $ans3000 !== ''),
        'follow_up_created' => $followUpResult['created'],
        'follow_up_existing' => $followUpResult['existing'],
    ]);
}

// ----------------- GET -----------------
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<?php if ($assignmentError || (!$hasRequest && $dispatchError !== '')) { ?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no" />
  <meta http-equiv="refresh" content="20" />
  <title><?php echo h(ascii_only($UI_TITLE)); ?></title>
  <style>
    body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f2f2f7;margin:0;padding:calc(20px + env(safe-area-inset-top, 0px)) 20px 20px;text-align:center}
    .card{background:#fff;border-radius:14px;padding:28px 20px;box-shadow:0 6px 18px rgba(0,0,0,.06);margin:0 auto;max-width:520px}
    .error{font-size:1.45rem;font-weight:800;color:#b00020;line-height:1.35}
    .small{margin-top:18px;color:#555}
  </style>
</head>
<body>
  <div class="card">
    <div class="error"><?php echo h($dispatchError); ?></div>
    <div class="small">Diese Meldung wird 20 Sekunden angezeigt.</div>
  </div>
</body>
</html>
<script>setTimeout(function(){ location.reload(); }, 20000);</script>
<?php exit; } elseif (!$hasRequest) { ?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta http-equiv="refresh" content="3" />
  <title><?php echo h(ascii_only($UI_TITLE)); ?></title>
  <style>
    :root { --maxw: 520px; }
    /* mehr Abstand oben (safe-area) fuer WebApp */
    body {
      font-family: -apple-system, BlinkMacSystemFont, sans-serif;
      background:#f2f2f7;
      margin:0;
      padding: calc(20px + env(safe-area-inset-top, 0px)) 20px 20px 20px;
      text-align:center;
    }
    .card { background:#fff; border-radius:14px; padding:16px; box-shadow:0 6px 18px rgba(0,0,0,0.06); margin:0 auto; max-width:var(--maxw); }
    .patient { font-size: 2.3rem; font-weight: 900; margin: 4px 0 10px 0; }
    .hint { font-size:1rem; color:#555; line-height:1.4; }
    .small { font-size:0.85rem; color:#777; margin-top:10px; }
    .footer { margin-top: 14px; font-size: 0.8rem; color: #777; }
  </style>
</head>
<body>
  <div class="card">
    <div class="patient"><?php echo h($displayName_ui); ?></div>
    <div class="hint">
      Warte auf Auftrags-GDT im Ordner:<br/>
      <b><?php echo h($dirGdt); ?></b><br/><br/>
      Erwartete Eingabe: <code><?php echo h($tabletPrefix . 'anam-i.gdt'); ?></code><br/><br/>
      Seite aktualisiert sich automatisch alle 3 Sekunden.
    </div>
    <div class="small">Sobald die Auftragsdatei da ist,<br>erscheint der Anamnese-Bogen.</div>
    <div class="footer"><?php echo h(ascii_only($APP_FOOTER . ' · ' . $APP_VERSION)); ?></div>
  </div>
</body>
</html>
<?php exit; } ?>

<?php
$yaml = yaml_load_or_die_ascii($YAML_PATH);
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
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta name="apple-mobile-web-app-title" content="<?php echo h(ascii_only($UI_TITLE)); ?>" />
  <title><?php echo h(ascii_only($UI_TITLE)); ?></title>

  <style>
    :root { --maxw: 780px; }
    /* mehr Abstand oben (safe-area) fuer WebApp */
    body {
      font-family: -apple-system, BlinkMacSystemFont, sans-serif;
      background:#f2f2f7;
      margin:0;
      padding: calc(20px + env(safe-area-inset-top, 0px)) 20px 20px 20px;
    }
    .card { background:#fff; border-radius:14px; padding:16px; box-shadow:0 6px 18px rgba(0,0,0,0.06); margin:0 auto; max-width:var(--maxw); }
    .patient { font-size: 2.0rem; font-weight: 900; margin: 0 0 6px 0; }
    .sub { color:#444; margin:0 0 12px 0; line-height:1.35; }
    .row { display:flex; gap:10px; flex-wrap:wrap; }
    .field { flex:1 1 220px; text-align:left; margin: 8px 0; }
    label { display:block; font-size:0.95rem; font-weight:700; margin-bottom:6px; color:#222; }
    input, textarea { width:100%; box-sizing:border-box; padding:10px 12px; border-radius:10px; border:1px solid #ddd; font-size:1rem; }
    textarea { min-height: 88px; resize: vertical; }

    .section { margin-top: 16px; padding-top: 8px; border-top: 1px solid #eee; }
    .section h2 { font-size: 1.2rem; margin: 10px 0 6px 0; }
    .formHeading { font-size:1.45rem; line-height:1.25; margin:18px 0 10px; text-align:center; }

    .checkgrid { display:grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap:10px; }
    .check { display:flex; align-items:center; gap:10px; background:#fafafa; border:1px solid #eee; border-radius:12px; padding:10px 12px; }
    .check input { width:22px; height:22px; flex:0 0 auto; }
    .check span { font-size:1.05rem; overflow-wrap:anywhere; }

    .checkHeader{
      grid-column: 1 / -1;
      padding: 8px 10px 0 6px;
      margin-top: 4px;
      font-weight: 900;
      font-size: 1.05rem;
      color:#222;
    }

    .radioRow{
      display:grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap:10px;
    }
    .radioPill{
      display:flex;
      align-items:center;
      justify-content:center;
      gap:10px;
      background:#fafafa;
      border:1px solid #eee;
      border-radius:12px;
      padding:12px 12px;
      min-height:44px;
      box-sizing:border-box;
    }
    .radioPill input{ width:22px; height:22px; flex:0 0 auto; }
    .radioPill span{ white-space:normal; overflow-wrap:anywhere; text-align:center; }

    .scaleQuestion{
      flex:1 1 100%;
      background:#fafafa;
      border:1px solid #e5e5ea;
      border-radius:14px;
      padding:14px 16px 12px 16px;
      box-sizing:border-box;
      margin:10px 0;
    }
    .scaleQuestion.invalid{ border-color:#ff3b30; background:#fff3f2; }
    .field.invalid{
      background:#fff3f2;
      border-radius:12px;
      box-shadow:0 0 0 2px #ff3b30;
      padding:10px;
      box-sizing:border-box;
    }
    .field.invalid > label{ color:#d70015; }
    .field.invalid .radioRow{ outline:2px solid #ff3b30; outline-offset:3px; border-radius:12px; }
    .field.invalid input:not([type="radio"]):not([type="range"]):not([type="hidden"]),
    .field.invalid textarea,
    .field.invalid select{ border-color:#ff3b30; outline:1px solid #ff3b30; }
    .check.invalid{ border-color:#ff3b30; outline:1px solid #ff3b30; background:#fff3f2; color:#d70015; }
    .scaleValue{
      display:table;
      min-width:42px;
      margin:4px auto 8px auto;
      padding:5px 12px;
      border-radius:999px;
      background:#e5e5ea;
      color:#555;
      text-align:center;
      font-size:1.05rem;
      font-weight:900;
    }
    .scaleQuestion.answered .scaleValue{ background:#007aff; color:#fff; }
    .scaleEndpoints{
      display:grid;
      grid-template-columns:minmax(0, 1fr) minmax(0, 1fr);
      gap:18px;
      align-items:end;
      font-size:0.92rem;
      line-height:1.25;
      color:#333;
    }
    .scaleEndpoints span:last-child{ text-align:right; }
    .scaleRange{
      width:100%;
      margin:14px 0 2px 0;
      padding:0;
      border:0;
      accent-color:#007aff;
      opacity:0.45;
    }
    .scaleQuestion.answered .scaleRange{ opacity:1; }
    .scaleTicks{
      display:flex;
      justify-content:space-between;
      padding:0;
      color:#666;
      font-size:0.82rem;
      font-variant-numeric:tabular-nums;
    }
    button.scaleTick{
      width:auto;
      min-width:30px;
      min-height:30px;
      margin:0;
      padding:3px 8px;
      border-radius:8px;
      background:transparent;
      color:#666;
      font-size:0.82rem;
      line-height:1;
    }
    button.scaleTick.selected{ background:#007aff; color:#fff; }
    button.scaleTick:focus-visible{ outline:2px solid #007aff; outline-offset:2px; }
    .requiredHint{ color:#777; font-size:0.8rem; font-weight:600; }

    button { font-size: 1.05rem; padding: 14px 18px; border-radius: 12px; border: none; width: 100%; margin: 10px 0; cursor: pointer; }
    #submitBtn { background: #34c759; color:#fff; }
    #abortBtn { background: #ff3b30; color:#fff; }
    #submitBtn:disabled { background: #a7e3b7; cursor: not-allowed; }

    #status { font-size: 1.05rem; font-weight: 700; color: #333; margin-top: 10px; min-height: 1.4em; word-break: break-word; }
    .footer { margin-top: 14px; font-size: 0.8rem; color: #777; text-align:center; }
    .warn { background:#fff7e6; border:1px solid #ffe0a6; padding:12px; border-radius:12px; margin:10px 0; color:#6b4b00; }

    .hidden { display:none !important; }
  </style>
</head>
<body>
  <div class="card">

    <div class="patient"><?php echo h($displayName_ui); ?></div>
    <div class="sub">
      Geburtsdatum: <b><?php echo h($gebdat_ui !== '' ? $gebdat_ui : '—'); ?></b>
    </div>

    <?php if ($yamlError !== '') { ?>
      <div class="warn">
        <b>⚠️ YAML-Fehler:</b> <?php echo h(ascii_only($yamlError)); ?><br/>
        Datei: <code><?php echo h($YAML_PATH); ?></code>
      </div>
    <?php } ?>

    <form id="anamForm">
      <input type="hidden" name="request_gdt" value="<?php echo h($REQUEST_GDT_NAME); ?>" />

      <?php if ($showContactSection) { ?>
      <div class="section">
        <h2>Koerpermasse & Kontakt</h2>
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

        <div class="row">
          <div class="field">
            <label for="phone1">Telefon 1</label>
            <input id="phone1" name="phone1" inputmode="tel" value="<?php echo h(ascii_only($reqPhone1)); ?>" />
          </div>
          <div class="field">
            <label for="phone2">Telefon 2</label>
            <input id="phone2" name="phone2" inputmode="tel" value="<?php echo h(ascii_only($reqPhone2)); ?>" />
          </div>
          <div class="field">
            <label for="email">E-Mail</label>
            <input id="email" name="email" inputmode="email" value="<?php echo h(ascii_only($reqEmail)); ?>" />
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
                      <input type="radio" name="q[<?php echo h($id); ?>]" value="no" checked />
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
                    <textarea id="<?php echo h('q_'.$id); ?>" name="q[<?php echo h($id); ?>]"></textarea>
                  <?php } else { ?>
                    <input id="<?php echo h('q_'.$id); ?>" name="q[<?php echo h($id); ?>]" />
                  <?php } ?>
              <?php } ?>

              </div>
            <?php } ?>
          <?php } ?>

        </div>
      <?php } ?>

      <button id="submitBtn" type="button">✅ Anamnese absenden</button>
      <button id="abortBtn" type="button">❌ Abbruch</button>

      <div id="status"></div>
      <div class="footer"><?php echo h(ascii_only($APP_FOOTER . ' · ' . $APP_VERSION)); ?></div>
    </form>
  </div>

  <script>
    var FOLLOW_UP_SCROLL_KEY = 'fragebogenpi-follow-up-scroll-top';
    var SHOULD_SCROLL_TO_TOP = false;
    try {
      if (sessionStorage.getItem(FOLLOW_UP_SCROLL_KEY) === '1') {
        SHOULD_SCROLL_TO_TOP = true;
        sessionStorage.removeItem(FOLLOW_UP_SCROLL_KEY);
        if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
        window.scrollTo(0, 0);
        requestAnimationFrame(function(){ window.scrollTo(0, 0); });
        setTimeout(function(){ window.scrollTo(0, 0); }, 0);
      }
    } catch (e) {}
    window.addEventListener('pageshow', function(){
      if (!SHOULD_SCROLL_TO_TOP) return;
      window.scrollTo(0, 0);
      setTimeout(function(){ window.scrollTo(0, 0); }, 50);
    });

    var SCRIPT_NAME = <?php echo json_encode($scriptName, JSON_UNESCAPED_SLASHES); ?>;

    var POST_URL;
    try { POST_URL = new URL(SCRIPT_NAME, window.location.href).toString(); }
    catch (e) { POST_URL = window.location.origin + window.location.pathname; }

    var submitBtn = document.getElementById("submitBtn");
    var abortBtn  = document.getElementById("abortBtn");
    var statusEl  = document.getElementById("status");
    var formEl    = document.getElementById("anamForm");

    function setStatus(msg, isError) {
      statusEl.textContent = msg || "";
      statusEl.style.color = isError ? "#d00" : "#333";
    }

    function questionWrapForId(qid) {
      var wraps = formEl.querySelectorAll('[data-qwrap="1"]');
      for (var i = 0; i < wraps.length; i++) {
        if ((wraps[i].getAttribute('data-qid') || '') === qid) return wraps[i];
      }
      return null;
    }

    function getAnswerValue(qid) {
      var wrap = questionWrapForId(qid);
      if (!wrap) return "";

      var cbs = wrap.querySelectorAll('input[type="checkbox"]');
      if (cbs && cbs.length === 1 && (cbs[0].name || '').slice(-2) !== '[]') {
        return cbs[0].checked ? true : false;
      }
      if (cbs && cbs.length) {
        var vals = [];
        cbs.forEach(function(x){ if (x.checked) vals.push(x.value || ""); });
        return vals;
      }

      var r = wrap.querySelector('input[type="radio"]:checked');
      if (r) return r.value;

      var t = wrap.querySelector('input[type="hidden"], input:not([type]), input[type="text"], input[type="number"], textarea, select');
      if (t) return (t.value || "");
      return "";
    }

    function commitScale(range) {
      var targetId = range.getAttribute("data-answer-target");
      var target = targetId ? document.getElementById(targetId) : null;
      var wrap = range.closest('[data-qwrap="1"]');
      if (!target || !wrap) return;
      target.value = range.value;
      range.setAttribute("aria-valuetext", range.value);
      var valueEl = wrap.querySelector('[data-scale-value="1"]');
      if (valueEl) valueEl.textContent = range.value;
      wrap.querySelectorAll('[data-scale-tick="1"]').forEach(function(tick){
        var selected = String(tick.getAttribute('data-scale-value-option')) === String(range.value);
        tick.classList.toggle('selected', selected);
        tick.setAttribute('aria-pressed', selected ? 'true' : 'false');
      });
      wrap.classList.add("answered");
      wrap.classList.remove("invalid");
    }

    function setScaleFromPointer(range, event) {
      if (typeof event.clientX !== 'number') return;
      if (typeof event.button === 'number' && event.button !== 0) return;
      var rect = range.getBoundingClientRect();
      if (!rect || rect.width <= 0) return;

      var minimum = Number(range.min || 0);
      var maximum = Number(range.max || 100);
      var step = Number(range.step || 1);
      if (!Number.isFinite(minimum) || !Number.isFinite(maximum) || !Number.isFinite(step) || step <= 0 || maximum <= minimum) return;

      var ratio = Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width));
      var rawValue = minimum + ratio * (maximum - minimum);
      var snappedValue = minimum + Math.round((rawValue - minimum) / step) * step;
      snappedValue = Math.max(minimum, Math.min(maximum, snappedValue));
      range.value = String(Number(snappedValue.toFixed(6)));
      commitScale(range);
    }

    formEl.querySelectorAll('[data-scale-range="1"]').forEach(function(range){
      range.addEventListener("pointerdown", function(event){ setScaleFromPointer(range, event); });
      range.addEventListener("input", function(){ commitScale(range); });
      range.addEventListener("change", function(){ commitScale(range); });
      range.addEventListener("click", function(event){ setScaleFromPointer(range, event); });
    });

    formEl.querySelectorAll('[data-scale-tick="1"]').forEach(function(tick){
      tick.addEventListener("click", function(){
        var rangeId = tick.getAttribute("data-range-target");
        var range = rangeId ? document.getElementById(rangeId) : null;
        if (!range) return;
        range.value = tick.getAttribute("data-scale-value-option") || range.value;
        commitScale(range);
        range.focus();
      });
    });

    var requiredValidationActive = false;

    function updateRequiredQuestionMarks() {
      var firstInvalid = null;
      var missingLabels = [];
      var required = formEl.querySelectorAll('[data-qwrap="1"][data-required="1"]');

      if (!requiredValidationActive) {
        required.forEach(function(wrap){ wrap.classList.remove('invalid'); });
        return { firstInvalid:null, missingLabels:[] };
      }

      required.forEach(function(wrap){
        var section = wrap.closest('.section');
        var hidden = wrap.classList.contains('hidden') || (section && section.classList.contains('hidden'));
        if (hidden) {
          wrap.classList.remove('invalid');
          return;
        }

        var qid = wrap.getAttribute('data-qid') || '';
        var value = getAnswerValue(qid);
        var answered = Array.isArray(value) ? value.length > 0 : (value === true || String(value || '').trim() !== '');
        wrap.classList.toggle('invalid', !answered);
        if (!answered) {
          if (!firstInvalid) firstInvalid = wrap;
          missingLabels.push(wrap.getAttribute('data-qlabel') || qid);
        }
      });

      return { firstInvalid:firstInvalid, missingLabels:missingLabels };
    }

    function requiredQuestionsValid() {
      requiredValidationActive = true;
      var state = updateRequiredQuestionMarks();

      if (state.firstInvalid) {
        state.firstInvalid.scrollIntoView({ behavior:"smooth", block:"center" });
        setStatus("Bitte alle Pflichtfragen beantworten. Erste offene Frage: " + state.missingLabels[0], true);
        return false;
      }
      return true;
    }

    function parseJsonArrayMaybe(s) {
      if (!s) return null;
      try { var v = JSON.parse(s); if (Array.isArray(v)) return v; } catch(e) {}
      return null;
    }

    function applyShowIf() {
      var nodes = formEl.querySelectorAll('[data-qwrap="1"][data-show-id]');
      nodes.forEach(function(el){
        var depId = el.getAttribute('data-show-id');
        var op = el.getAttribute('data-show-op') || 'equals';
        var val = el.getAttribute('data-show-val');

        var cur = getAnswerValue(depId);
        var show = true;

        if (op === 'equals') show = (String(cur) === String(val));
        else if (op === 'not_equals') show = (String(cur) !== String(val));
        else if (op === 'in') {
          var lst = parseJsonArrayMaybe(val) || [];
          show = (lst.map(String).indexOf(String(cur)) !== -1);
        } else if (op === 'any_selected_except') {
          var except = String(val || "");
          show = false;
          if (Array.isArray(cur)) {
            for (var i=0;i<cur.length;i++){
              var x = String(cur[i] || "");
              if (!x) continue;
              if (!except) { show = true; break; }
              if (x !== except) { show = true; break; }
            }
          }
        }

        el.classList.toggle('hidden', !show);
      });

      var secs = formEl.querySelectorAll('.section[data-show-id]');
      secs.forEach(function(el){
        var depId = el.getAttribute('data-show-id');
        var op = el.getAttribute('data-show-op') || 'equals';
        var val = el.getAttribute('data-show-val');

        var cur = getAnswerValue(depId);
        var show = true;

        if (op === 'equals') show = (String(cur) === String(val));
        else if (op === 'not_equals') show = (String(cur) !== String(val));
        else if (op === 'in') {
          var lst = parseJsonArrayMaybe(val) || [];
          show = (lst.map(String).indexOf(String(cur)) !== -1);
        } else if (op === 'any_selected_except') {
          var except = String(val || "");
          show = false;
          if (Array.isArray(cur)) {
            for (var i=0;i<cur.length;i++){
              var x = String(cur[i] || "");
              if (!x) continue;
              if (!except) { show = true; break; }
              if (x !== except) { show = true; break; }
            }
          }
        }

        el.classList.toggle('hidden', !show);
      });
    }

    formEl.addEventListener('change', applyShowIf);
    formEl.addEventListener('input', applyShowIf);
    formEl.addEventListener('change', updateRequiredQuestionMarks);
    formEl.addEventListener('input', updateRequiredQuestionMarks);
    applyShowIf();
    updateRequiredQuestionMarks();

    submitBtn.addEventListener("click", function () {
      if (!requiredQuestionsValid()) return;
      submitBtn.disabled = true;
      abortBtn.disabled = true;
      setStatus("Uebermittlung laeuft…", false);

      var formData = new FormData(formEl);

      fetch(POST_URL, { method: "POST", body: formData })
        .then(function(res) {
          var ct = res.headers.get("content-type") || "";
          if (ct.indexOf("application/json") !== -1) {
            return res.json().then(function(data) { return { ok: res.ok, data: data }; });
          }
          return res.text().then(function(text) {
            throw new Error("Server hat kein JSON geliefert: " + text.slice(0, 200));
          });
        })
        .then(function(r) {
          if (r.data && r.data.status === "assignment_error") {
            setStatus("Zuordnungsfehler, bitte bei Mitarbeiter melden", true);
            setTimeout(function() { location.reload(); }, 20000);
            return;
          }
          if (!r.ok || !r.data || r.data.status !== "ok") {
            var msg = (r.data && r.data.message) ? r.data.message : "Uebermittlung fehlgeschlagen";
            throw new Error(msg);
          }
          setStatus("✅ erfolgreich uebermittelt", false);
          var followUpCreated = Array.isArray(r.data.follow_up_created) && r.data.follow_up_created.length > 0;
          var followUpExisting = Array.isArray(r.data.follow_up_existing) && r.data.follow_up_existing.length > 0;
          var hasFollowUp = followUpCreated || followUpExisting;
          if (hasFollowUp) {
            try {
              sessionStorage.setItem(FOLLOW_UP_SCROLL_KEY, '1');
              if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
            } catch (e) {}
          }
          setTimeout(function() {
            if (hasFollowUp) window.scrollTo(0, 0);
            location.reload();
          }, 1000);
        })
        .catch(function(err) {
          setStatus("❌ " + (err && err.message ? err.message : String(err)), true);
          submitBtn.disabled = false;
          abortBtn.disabled = false;
        });
    });

    abortBtn.addEventListener("click", function () {
      if (!confirm("Vorgang wirklich abbrechen? Die Anforderung wird geloescht.")) return;

      submitBtn.disabled = true;
      abortBtn.disabled = true;
      setStatus("Abbruch laeuft…", false);

      var formData = new FormData();
      formData.append("action", "abort");

      fetch(POST_URL, { method: "POST", body: formData })
        .then(function(res) {
          var ct = res.headers.get("content-type") || "";
          if (ct.indexOf("application/json") !== -1) return res.json();
          return res.text().then(function(text) {
            throw new Error("Server hat kein JSON geliefert: " + text.slice(0, 200));
          });
        })
        .then(function(data) {
          if (!data || data.status !== "ok") throw new Error((data && data.message) ? data.message : "Abbruch fehlgeschlagen");
          setStatus("❌ abgebrochen", false);
          setTimeout(function() { location.reload(); }, 800);
        })
        .catch(function(err) {
          setStatus("❌ " + (err && err.message ? err.message : String(err)), true);
          submitBtn.disabled = false;
          abortBtn.disabled = false;
        });
    });
  </script>
</body>
</html>
