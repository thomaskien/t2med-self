<?php
declare(strict_types=1);
// fragebogenpi tablet-engine.php v1.8.3; shared unchanged helpers from tablet.php 1.8.2.
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function is_valid_utf8(string $s): bool {
    return $s === '' ? true : (bool)@preg_match('//u', $s);
}

function req_to_utf8_for_ui(string $raw): string {
    if ($raw === '') return '';
    if (is_valid_utf8($raw)) return $raw;

    if (function_exists('iconv')) {
        foreach (['CP437', 'ISO-8859-1', 'Windows-1252'] as $enc) {
            $tmp = @iconv($enc, 'UTF-8//IGNORE', $raw);
            if ($tmp !== false && $tmp !== '') return $tmp;
        }
    }
    return $raw;
}

function translit_for_ui(string $raw): string {
    $s = req_to_utf8_for_ui($raw);
    $map = [
        'Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss',
        '’'=>"'","´"=>"'","`"=>"'","“"=>'"',"”"=>'"',"„"=>'"',"–"=>'-',"—"=>'-',"…"=>'...',
    ];
    $s = strtr($s, $map);

    if (function_exists('iconv')) {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($tmp !== false && $tmp !== '') $s = $tmp;
    }

    $s = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? $s;
    return $s;
}

function ascii_only(string $s): string {
    $map = [
        'Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss',
        '’'=>"'","´"=>"'","`"=>"'","“"=>'"',"”"=>'"',"„"=>'"',"–"=>'-',"—"=>'-',"…"=>'...',
    ];
    $s = strtr($s, $map);

    if (function_exists('iconv')) {
        $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($tmp !== false && $tmp !== '') $s = $tmp;
    }

    $s = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? $s;
    return $s;
}

function clean_utf8_text(string $s, int $maxLen = 200): string {
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = trim($s);

    if (function_exists('mb_substr')) {
        $s = mb_substr($s, 0, $maxLen, 'UTF-8');
    } else {
        $s = substr($s, 0, $maxLen);
    }

    if (function_exists('iconv')) {
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        if ($fixed !== false) $s = $fixed;
    }

    return $s;
}

function req_value_passthrough(string $s, int $maxLen = 200): string {
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = trim($s);
    if (strlen($s) > $maxLen) $s = substr($s, 0, $maxLen);
    return $s;
}

function json_out(int $code, array $payload): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        $json = json_encode([
            'status' => 'error',
            'message' => 'json_encode fehlgeschlagen',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"status":"error","message":"json_encode failed"}';
    }

    echo $json;
    exit;
}

function gdt_line(string $field4, string $value): string {
    $rest = $field4 . $value;
    $len  = 3 + strlen($rest) + 2; // +CRLF
    return str_pad((string)$len, 3, '0', STR_PAD_LEFT) . $rest;
}

function parse_gdt(string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false) return [];
    $raw = str_replace("\r\n", "\n", $raw);
    $lines = array_filter(explode("\n", $raw), fn($l) => trim($l) !== '');

    $fields = [];
    foreach ($lines as $line) {
        if (strlen($line) < 7) continue;
        $rest  = substr($line, 3);
        $field = substr($rest, 0, 4);
        $value = substr($rest, 4);
        $fields[$field] = $value; // raw bytes beibehalten
    }
    return $fields;
}

function write_gdt_file(string $path, array $lines): void {
    $joined = implode("\r\n", $lines) . "\r\n";
    $totalBytes = strlen($joined);
    $total6 = str_pad((string)$totalBytes, 6, '0', STR_PAD_LEFT);

    foreach ($lines as $i => $line) {
        $rest = substr($line, 3);
        $field = substr($rest, 0, 4);
        if ($field === '8100') {
            $lines[$i] = gdt_line('8100', $total6);
            break;
        }
    }

    $joined2 = implode("\r\n", $lines) . "\r\n";
    $totalBytes2 = strlen($joined2);
    if ($totalBytes2 !== $totalBytes) {
        $total6b = str_pad((string)$totalBytes2, 6, '0', STR_PAD_LEFT);
        foreach ($lines as $i => $line) {
            $rest = substr($line, 3);
            $field = substr($rest, 0, 4);
            if ($field === '8100') {
                $lines[$i] = gdt_line('8100', $total6b);
                break;
            }
        }
        $joined2 = implode("\r\n", $lines) . "\r\n";
    }

    file_put_contents($path, $joined2);
}

function to_ascii_wrapped_lines(string $s, int $maxBytes, string $firstPrefix = '', string $nextPrefix = ''): array {
    $s = clean_utf8_text($s, 5000);
    $s = ascii_only($s);

    $candidate = $firstPrefix . $s;
    if (strlen($candidate) <= $maxBytes) return [$candidate];

    $words = preg_split('/\s+/', $s) ?: [];
    $out = [];
    $cur = '';
    $isFirst = true;

    foreach ($words as $w) {
        $try = ($cur === '') ? $w : ($cur . ' ' . $w);
        $prefix = $isFirst ? $firstPrefix : $nextPrefix;
        if (strlen($prefix . $try) <= $maxBytes) {
            $cur = $try;
            continue;
        }

        if ($cur !== '') {
            $prefix2 = $isFirst ? $firstPrefix : $nextPrefix;
            $out[] = $prefix2 . $cur;
            $isFirst = false;
            $cur = $w;
            continue;
        }

        $out[] = substr($prefix . $w, 0, $maxBytes);
        $isFirst = false;
        $cur = '';
    }

    if ($cur !== '') {
        $prefix3 = $isFirst ? $firstPrefix : $nextPrefix;
        $out[] = $prefix3 . $cur;
    }

    foreach ($out as &$line) $line = ascii_only($line);
    return $out;
}

/** YAML lesen und alle Strings rekursiv ASCII-only machen */
function yaml_load_or_die_ascii(string $path): array {
    if (!is_file($path)) return ['__error' => 'YAML-Datei nicht gefunden: ' . $path];
    if (!function_exists('yaml_parse_file')) return ['__error' => 'PHP YAML Extension fehlt (yaml_parse_file nicht verfuegbar). Bitte php-yaml installieren.'];
    $data = @yaml_parse_file($path);
    if (!is_array($data)) return ['__error' => 'YAML konnte nicht geparst werden oder ist leer/ungueltig.'];
    return yaml_ascii_walk($data);
}

function yaml_ascii_walk($v) {
    if (is_string($v)) return ascii_only($v);
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $vv) {
            $kk = is_string($k) ? ascii_only($k) : $k;
            $out[$kk] = yaml_ascii_walk($vv);
        }
        return $out;
    }
    return $v;
}

function cond_ok(array $answers, ?array $cond): bool {
    if (!$cond) return true;
    $id = (string)($cond['id'] ?? '');
    if ($id === '') return true;
    $val = $answers[$id] ?? null;

    $eq  = $cond['equals'] ?? null;
    $neq = $cond['not_equals'] ?? null;

    if (is_bool($val)) {
        if ($eq === 'yes') $eq = true;
        if ($eq === 'no')  $eq = false;
        if ($neq === 'yes') $neq = true;
        if ($neq === 'no')  $neq = false;
    } elseif (is_string($val)) {
        if ($val === 'yes' && $eq === true) $eq = 'yes';
        if ($val === 'no'  && $eq === false) $eq = 'no';
        if ($val === 'yes' && $neq === true) $neq = 'yes';
        if ($val === 'no'  && $neq === false) $neq = 'no';
    }

    if (array_key_exists('equals', $cond)) return $val === $eq;
    if (array_key_exists('not_equals', $cond)) return $val !== $neq;

    if (array_key_exists('greater_than_or_equal', $cond)) {
        $minimum = $cond['greater_than_or_equal'];
        if (!is_numeric($val) || !is_numeric($minimum)) return false;
        return (float)$val >= (float)$minimum;
    }

    if (array_key_exists('in', $cond)) {
        $lst = $cond['in'];
        if (!is_array($lst)) $lst = [];
        $norm = ascii_only(clean_utf8_text((string)$val, 200));
        foreach ($lst as $x) {
            $x = ascii_only(clean_utf8_text((string)$x, 200));
            if ($x !== '' && $norm === $x) return true;
        }
        return false;
    }

    if (array_key_exists('any_selected_except', $cond)) {
        $except = ascii_only(clean_utf8_text((string)$cond['any_selected_except'], 200));
        if (!is_array($val)) return false;
        foreach ($val as $opt) {
            $opt = ascii_only(clean_utf8_text((string)$opt, 200));
            if ($opt === '') continue;
            if ($except === '') return true;
            if ($opt !== $except) return true;
        }
        return false;
    }

    return true;
}

function build_section_block_lines(string $title, array $bullets, int $maxBytes): array {
    $out = [];
    foreach (to_ascii_wrapped_lines('---', $maxBytes) as $l) $out[] = gdt_line('6228', $l);
    foreach (to_ascii_wrapped_lines($title, $maxBytes) as $l) $out[] = gdt_line('6228', $l);
    foreach (to_ascii_wrapped_lines('========', $maxBytes) as $l) $out[] = gdt_line('6228', $l);
    foreach ($bullets as $b) {
        foreach (to_ascii_wrapped_lines($b, $maxBytes, '- ', '  ') as $l) $out[] = gdt_line('6228', $l);
    }
    return $out;
}

function yaml_questions_by_id(array $yaml): array {
    $out = [];
    $sections = $yaml['sections'] ?? [];
    if (!is_array($sections)) return $out;

    foreach ($sections as $section) {
        if (!is_array($section)) continue;
        $questions = $section['questions'] ?? [];
        if (!is_array($questions)) continue;
        foreach ($questions as $question) {
            if (!is_array($question)) continue;
            $id = (string)($question['id'] ?? '');
            if ($id !== '') $out[$id] = $question;
        }
    }
    return $out;
}

function numeric_value(string $value): int|float|null {
    if ($value === '' || !is_numeric($value)) return null;
    $number = (float)$value;
    return floor($number) === $number ? (int)$number : $number;
}

function scale_answer_valid(array $question, mixed $answer): bool {
    if (!is_string($answer) || $answer === '') return false;
    $value = numeric_value($answer);
    if ($value === null) return false;

    $scale = $question['scale'] ?? [];
    if (!is_array($scale)) return false;
    $minimum = isset($scale['minimum']) && is_numeric($scale['minimum']) ? (float)$scale['minimum'] : 0.0;
    $maximum = isset($scale['maximum']) && is_numeric($scale['maximum']) ? (float)$scale['maximum'] : 10.0;
    $step = isset($scale['step']) && is_numeric($scale['step']) ? (float)$scale['step'] : 1.0;
    if ($step <= 0 || (float)$value < $minimum || (float)$value > $maximum) return false;

    $steps = ((float)$value - $minimum) / $step;
    return abs($steps - round($steps)) < 0.000001;
}

function question_score(array $question, mixed $answer): int|float|null {
    if (!is_string($answer) || $answer === '') return null;
    if ((string)($question['type'] ?? '') === 'scale') {
        return scale_answer_valid($question, $answer) ? numeric_value($answer) : null;
    }

    $scores = $question['scores'] ?? [];
    if (!is_array($scores) || !array_key_exists($answer, $scores)) return null;
    return is_numeric($scores[$answer]) ? numeric_value((string)$scores[$answer]) : null;
}

function derive_yaml_answers(array $yaml, array &$answers): void {
    $questionsById = yaml_questions_by_id($yaml);

    foreach ($questionsById as $id => $question) {
        if ((string)($question['type'] ?? '') !== 'derived') continue;
        $calculation = $question['calculation'] ?? null;
        if (!is_array($calculation) || (string)($calculation['operation'] ?? '') !== 'sum_scores') continue;

        $fields = $calculation['fields'] ?? [];
        if (!is_array($fields) || count($fields) === 0) continue;

        $sum = 0;
        $complete = true;
        foreach ($fields as $field) {
            $field = (string)$field;
            $score = isset($questionsById[$field])
                ? question_score($questionsById[$field], $answers[$field] ?? null)
                : null;
            if ($score === null) {
                $complete = false;
                break;
            }
            $sum += $score;
        }
        if ($complete) $answers[$id] = $sum;
    }

    foreach ($questionsById as $id => $question) {
        if ((string)($question['type'] ?? '') !== 'derived') continue;
        $source = (string)($question['source'] ?? '');
        $ranges = $question['ranges'] ?? [];
        if ($source === '' || !isset($answers[$source]) || !is_array($ranges)) continue;

        $value = (float)$answers[$source];
        foreach ($ranges as $range) {
            if (!is_array($range)) continue;
            $minimum = isset($range['minimum']) && is_numeric($range['minimum']) ? (float)$range['minimum'] : -INF;
            $maximum = isset($range['maximum']) && is_numeric($range['maximum']) ? (float)$range['maximum'] : INF;
            if ($value < $minimum || $value > $maximum) continue;
            $status = ascii_only(clean_utf8_text((string)($range['status'] ?? ''), 300));
            if ($status !== '') $answers[$id] = $status;
            break;
        }
    }
}

function validate_yaml_answers(array $yaml, array $answers): array {
    $errors = [];
    $sections = $yaml['sections'] ?? [];
    if (!is_array($sections)) return $errors;

    foreach ($sections as $section) {
        if (!is_array($section)) continue;
        if (isset($section['show_if']) && is_array($section['show_if']) && !cond_ok($answers, $section['show_if'])) continue;
        $sectionType = (string)($section['type'] ?? '');
        $questions = $section['questions'] ?? [];
        if (!is_array($questions)) continue;

        foreach ($questions as $question) {
            if (!is_array($question)) continue;
            $id = (string)($question['id'] ?? '');
            $type = (string)($question['type'] ?? '');
            $label = ascii_only(clean_utf8_text((string)($question['label'] ?? $id), 300));
            if ($id === '' || $type === 'derived' || $type === 'header') continue;
            if (isset($question['show_if']) && is_array($question['show_if']) && !cond_ok($answers, $question['show_if'])) continue;

            $value = $answers[$id] ?? null;
            $empty = $sectionType === 'checklist'
                ? $value !== true
                : (is_array($value) ? count($value) === 0 : trim((string)$value) === '');

            if (!empty($question['required']) && $empty) {
                $errors[] = 'Pflichtfrage unbeantwortet: ' . $label;
                continue;
            }
            if ($empty) continue;

            if ($type === 'scale' && !scale_answer_valid($question, $value)) {
                $errors[] = 'Ungueltiger Skalenwert: ' . $label;
                continue;
            }
            if ($type === 'choice') {
                $options = $question['options'] ?? [];
                if (is_array($options) && !in_array((string)$value, array_map('strval', $options), true)) {
                    $errors[] = 'Ungueltige Auswahl: ' . $label;
                }
                continue;
            }
            if ($type === 'yesno' && !in_array((string)$value, ['yes', 'no'], true)) {
                $errors[] = 'Ungueltige Auswahl: ' . $label;
            }
        }
    }

    return $errors;
}

function configured_score_maximum(array $element, array $questionsById, string $source): int|float|null {
    if (isset($element['maximum']) && is_numeric($element['maximum'])) {
        return numeric_value((string)$element['maximum']);
    }
    if ($source === '' || !isset($questionsById[$source]) || !is_array($questionsById[$source])) return null;

    $question = $questionsById[$source];
    $calculation = $question['calculation'] ?? [];
    if (is_array($calculation) && isset($calculation['maximum']) && is_numeric($calculation['maximum'])) {
        return numeric_value((string)$calculation['maximum']);
    }

    $scale = $question['scale'] ?? [];
    if (is_array($scale) && isset($scale['maximum']) && is_numeric($scale['maximum'])) {
        return numeric_value((string)$scale['maximum']);
    }
    return null;
}

function build_gdt_summary_lines(array $yaml, array $answers, int $maxBytes): array {
    $summary = $yaml['gdt_summary'] ?? null;
    if (!is_array($summary)) return [];

    $title = ascii_only(clean_utf8_text((string)($summary['title'] ?? ''), 200));
    if ($title === '') return [];

    $content = [];
    $questionsById = yaml_questions_by_id($yaml);
    $score = $summary['score'] ?? null;
    if (is_array($score)) {
        $source = (string)($score['source'] ?? '');
        if ($source !== '' && isset($answers[$source]) && is_numeric($answers[$source])) {
            $label = ascii_only(clean_utf8_text((string)($score['label'] ?? 'Punktwert'), 100));
            $suffix = ascii_only(clean_utf8_text((string)($score['suffix'] ?? 'Punkte'), 100));
            $value = numeric_value((string)$answers[$source]);
            if ($value !== null) {
                $scoreText = numeric_text($value);
                $maximum = configured_score_maximum($score, $questionsById, $source);
                if ($maximum !== null) $scoreText .= '/' . numeric_text($maximum);
                $content[] = trim($label . ': ' . $scoreText . ' ' . $suffix);
            }
        }
    }

    $assessment = $summary['assessment'] ?? null;
    if (is_array($assessment)) {
        $source = (string)($assessment['source'] ?? '');
        $value = ascii_only(clean_utf8_text((string)($answers[$source] ?? ''), 300));
        if ($value !== '') $content[] = $value;
    }

    $problemLines = [];
    $problems = $summary['problems'] ?? null;
    if (is_array($problems)) {
        $questionsById = yaml_questions_by_id($yaml);
        $fields = $problems['fields'] ?? [];
        if (is_array($fields)) {
            foreach ($fields as $field) {
                if (!is_array($field)) continue;
                $id = (string)($field['id'] ?? '');
                if ($id === '' || !isset($questionsById[$id])) continue;
                $scoreValue = question_score($questionsById[$id], $answers[$id] ?? null);
                $maximumScore = isset($field['maximum_score']) && is_numeric($field['maximum_score'])
                    ? (int)$field['maximum_score']
                    : PHP_INT_MAX;
                $minimumScore = isset($field['minimum_score']) && is_numeric($field['minimum_score'])
                    ? (int)$field['minimum_score']
                    : PHP_INT_MIN;
                if ($scoreValue === null || $scoreValue < $minimumScore || $scoreValue > $maximumScore) continue;
                $text = ascii_only(clean_utf8_text((string)($field['text'] ?? ''), 300));
                if ($text !== '') $problemLines[] = $text;
            }
        }
    }

    if (count($content) === 0 && count($problemLines) === 0) return [];

    $out = [];
    foreach (to_ascii_wrapped_lines('---', $maxBytes) as $line) $out[] = gdt_line('6228', $line);
    foreach (to_ascii_wrapped_lines($title, $maxBytes) as $line) $out[] = gdt_line('6228', $line);
    foreach (to_ascii_wrapped_lines('========', $maxBytes) as $line) $out[] = gdt_line('6228', $line);
    foreach ($content as $item) {
        foreach (to_ascii_wrapped_lines($item, $maxBytes) as $line) $out[] = gdt_line('6228', $line);
    }
    if (count($problemLines) > 0) {
        $out[] = gdt_line('6228', '');
        $problemTitle = ascii_only(clean_utf8_text((string)($problems['title'] ?? 'Problemfelder'), 200));
        foreach (to_ascii_wrapped_lines($problemTitle . ':', $maxBytes) as $line) $out[] = gdt_line('6228', $line);
        foreach ($problemLines as $item) {
            foreach (to_ascii_wrapped_lines($item, $maxBytes, '- ', '  ') as $line) $out[] = gdt_line('6228', $line);
        }
    }
    return $out;
}

function numeric_text(int|float $value): string {
    if (is_int($value) || floor($value) === $value) return (string)(int)$value;
    return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
}

function build_configured_gdt_lines(array $yaml, array $answers, int $maxBytes): array {
    $gdt = $yaml['gdt'] ?? null;
    if (!is_array($gdt)) return [];
    $sections = $gdt['sections'] ?? [];
    if (!is_array($sections)) return [];

    $questionsById = yaml_questions_by_id($yaml);
    $out = [];

    foreach ($sections as $section) {
        if (!is_array($section)) continue;
        if (isset($section['show_if']) && is_array($section['show_if']) && !cond_ok($answers, $section['show_if'])) continue;

        $title = ascii_only(clean_utf8_text((string)($section['title'] ?? ''), 200));
        $elements = $section['elements'] ?? [];
        if ($title === '' || !is_array($elements)) continue;

        $sectionLines = [];
        foreach ($elements as $element) {
            if (!is_array($element)) continue;
            $type = (string)($element['type'] ?? '');

            if ($type === 'score') {
                $source = (string)($element['source'] ?? '');
                $value = $answers[$source] ?? null;
                if (!is_int($value) && !is_float($value)) continue;
                $label = ascii_only(clean_utf8_text((string)($element['label'] ?? 'Punktwert'), 100));
                $suffix = ascii_only(clean_utf8_text((string)($element['suffix'] ?? ''), 100));
                $singularSuffix = ascii_only(clean_utf8_text((string)($element['singular_suffix'] ?? ''), 100));
                $maximum = configured_score_maximum($element, $questionsById, $source);
                if ($maximum === null && (float)$value === 1.0 && $singularSuffix !== '') $suffix = $singularSuffix;
                $scoreText = numeric_text($value);
                if ($maximum !== null) $scoreText .= '/' . numeric_text($maximum);
                $line = $label . ': ' . $scoreText;
                if ($suffix !== '') $line .= ' ' . $suffix;
                $sectionLines[] = ['kind' => 'line', 'text' => $line];
                continue;
            }

            if ($type === 'interpretation' || $type === 'value') {
                $source = (string)($element['source'] ?? '');
                $value = ascii_only(clean_utf8_text((string)($answers[$source] ?? ''), 600));
                if ($value === '') continue;
                $label = ascii_only(clean_utf8_text((string)($element['label'] ?? ''), 100));
                $suffix = ascii_only(clean_utf8_text((string)($element['suffix'] ?? ''), 100));
                if ($label !== '') $value = $label . ': ' . $value;
                if ($suffix !== '') $value .= ' ' . $suffix;
                $sectionLines[] = ['kind' => 'line', 'text' => $value];
                continue;
            }

            if ($type === 'text') {
                $value = ascii_only(clean_utf8_text((string)($element['text'] ?? ''), 600));
                if ($value !== '') $sectionLines[] = ['kind' => 'line', 'text' => $value];
                continue;
            }

            if ($type === 'answer_list') {
                $fields = $element['fields'] ?? [];
                if (!is_array($fields)) continue;
                $items = [];

                foreach ($fields as $field) {
                    $fieldConfig = is_array($field) ? $field : ['id' => $field];
                    $id = (string)($fieldConfig['id'] ?? '');
                    if ($id === '' || !isset($questionsById[$id])) continue;
                    $question = $questionsById[$id];
                    if (isset($question['show_if']) && is_array($question['show_if']) && !cond_ok($answers, $question['show_if'])) continue;
                    $rawValue = $answers[$id] ?? null;

                    if (is_array($rawValue)) {
                        $value = implode(', ', array_map('strval', $rawValue));
                    } elseif ($rawValue === true) {
                        $value = 'Ja';
                    } elseif ($rawValue === false) {
                        $value = 'Nein';
                    } else {
                        $value = (string)$rawValue;
                    }
                    $value = ascii_only(clean_utf8_text($value, 1000));
                    if ($value === '' && empty($fieldConfig['include_empty'])) continue;
                    if ($value === '') $value = 'Keine Angabe';

                    $label = (string)($fieldConfig['label'] ?? ($question['label'] ?? $id));
                    $label = ascii_only(clean_utf8_text($label, 500));
                    if ($label !== '') $items[] = $label . ': ' . $value;
                }

                if (count($items) > 0) {
                    $listTitle = ascii_only(clean_utf8_text((string)($element['title'] ?? ''), 200));
                    $sectionLines[] = ['kind' => 'answer_list', 'title' => $listTitle, 'items' => $items];
                }
                continue;
            }

            if ($type !== 'problem_list') continue;
            $fields = $element['fields'] ?? [];
            if (!is_array($fields)) continue;
            $minimumScore = isset($element['minimum_score']) && is_numeric($element['minimum_score'])
                ? (float)$element['minimum_score']
                : -INF;
            $maximumScore = isset($element['maximum_score']) && is_numeric($element['maximum_score'])
                ? (float)$element['maximum_score']
                : INF;
            $items = [];

            foreach ($fields as $field) {
                $fieldConfig = is_array($field) ? $field : ['id' => $field];
                $id = (string)($fieldConfig['id'] ?? '');
                if ($id === '' || !isset($questionsById[$id])) continue;
                $question = $questionsById[$id];
                $score = question_score($question, $answers[$id] ?? null);
                $fieldMinimum = isset($fieldConfig['minimum_score']) && is_numeric($fieldConfig['minimum_score'])
                    ? (float)$fieldConfig['minimum_score']
                    : $minimumScore;
                $fieldMaximum = isset($fieldConfig['maximum_score']) && is_numeric($fieldConfig['maximum_score'])
                    ? (float)$fieldConfig['maximum_score']
                    : $maximumScore;
                if ($score === null || (float)$score < $fieldMinimum || (float)$score > $fieldMaximum) continue;

                $text = (string)($fieldConfig['text'] ?? ($question['problem_label'] ?? ($question['label'] ?? '')));
                $text = ascii_only(clean_utf8_text($text, 300));
                if ($text !== '') $items[] = $text;
            }

            if (count($items) > 0) {
                $listTitle = ascii_only(clean_utf8_text((string)($element['title'] ?? 'Problemfelder'), 200));
                $sectionLines[] = ['kind' => 'problem_list', 'title' => $listTitle, 'items' => $items];
            }
        }

        if (count($sectionLines) === 0) continue;
        $separatorLines = isset($section['separator_lines']) && is_numeric($section['separator_lines'])
            ? max(1, min(5, (int)$section['separator_lines']))
            : 1;
        for ($separator = 0; $separator < $separatorLines; $separator++) {
            foreach (to_ascii_wrapped_lines('---', $maxBytes) as $line) $out[] = gdt_line('6228', $line);
        }
        foreach (to_ascii_wrapped_lines($title, $maxBytes) as $line) $out[] = gdt_line('6228', $line);
        foreach (to_ascii_wrapped_lines('========', $maxBytes) as $line) $out[] = gdt_line('6228', $line);

        foreach ($sectionLines as $lineConfig) {
            if ($lineConfig['kind'] === 'line') {
                foreach (to_ascii_wrapped_lines((string)$lineConfig['text'], $maxBytes) as $line) $out[] = gdt_line('6228', $line);
                continue;
            }

            if ((string)$lineConfig['title'] !== '') {
                $out[] = gdt_line('6228', '');
                foreach (to_ascii_wrapped_lines((string)$lineConfig['title'] . ':', $maxBytes) as $line) $out[] = gdt_line('6228', $line);
            }
            foreach ($lineConfig['items'] as $item) {
                foreach (to_ascii_wrapped_lines((string)$item, $maxBytes, '- ', '  ') as $line) $out[] = gdt_line('6228', $line);
            }
        }
    }

    return $out;
}

function section_bullets(array $section, array $answers): array {
    $bullets = [];

    if (isset($section['show_if']) && is_array($section['show_if'])) {
        if (!cond_ok($answers, $section['show_if'])) return [];
    }

    $type = (string)($section['type'] ?? '');
    $questions = $section['questions'] ?? [];
    if (!is_array($questions)) return [];

    if ($type === 'checklist') {
        foreach ($questions as $q) {
            if (!is_array($q)) continue;
            $qType = (string)($q['type'] ?? '');
            if ($qType === 'header') continue;

            $id = (string)($q['id'] ?? '');
            $label = (string)($q['label'] ?? '');
            if ($id === '' || $label === '') continue;
            if (($answers[$id] ?? false) === true) $bullets[] = $label;
        }
        return $bullets;
    }

    foreach ($questions as $q) {
        if (!is_array($q)) continue;
        $id = (string)($q['id'] ?? '');
        $label = (string)($q['label'] ?? '');
        $qType = (string)($q['type'] ?? '');
        if ($id === '' || $label === '') continue;

        if (isset($q['show_if']) && is_array($q['show_if'])) {
            if (!cond_ok($answers, $q['show_if'])) continue;
        }

        $val = $answers[$id] ?? null;

        if ($qType === 'yesno') {
            if ($val === 'yes' || $val === true) $bullets[] = $label;
            continue;
        }

        if ($qType === 'multiselect') {
            if (is_array($val) && count($val) > 0) {
                $direct = in_array($id, ['allergie_typen'], true);
                foreach ($val as $opt) {
                    $opt = ascii_only(clean_utf8_text((string)$opt, 200));
                    if ($opt === '') continue;
                    $bullets[] = $direct ? $opt : ($label . ': ' . $opt);
                }
            }
            continue;
        }

        if ($qType === 'choice') {
            $v = ascii_only(clean_utf8_text((string)$val, 200));
            if ($v === '' || $v === 'nein' || $v === 'normal' || $v === 'konstant') continue;
            $bullets[] = $label . ': ' . $v;
            continue;
        }

        if ($qType === 'number' || $qType === 'text') {
            $v = ascii_only(clean_utf8_text((string)$val, 600));
            if ($v === '') continue;
            $bullets[] = $label . ': ' . $v;
            continue;
        }

        if ($qType === 'derived') {
            if ($id === 'packyears') {
                $v = ascii_only(clean_utf8_text((string)($answers['_packyears_text'] ?? ''), 200));
                if ($v !== '') $bullets[] = $v;
            }
            continue;
        }

        if ($val === true) $bullets[] = $label;
    }

    return $bullets;
}

function build_6228_blocks(array $yaml, array $answers, int $maxBytes): array {
    $out = [];
    $sections = $yaml['sections'] ?? [];
    if (!is_array($sections)) return [];

    foreach ($sections as $sec) {
        if (!is_array($sec)) continue;
        if (array_key_exists('gdt_output', $sec) && $sec['gdt_output'] === false) continue;
        $title = ascii_only(clean_utf8_text((string)($sec['title'] ?? ''), 200));
        if ($title === '') continue;

        $bullets = section_bullets($sec, $answers);
        if (count($bullets) === 0) continue;

        $out = array_merge($out, build_section_block_lines($title, $bullets, $maxBytes));
    }
    if (isset($yaml['gdt']) && is_array($yaml['gdt'])) {
        $out = array_merge($out, build_configured_gdt_lines($yaml, $answers, $maxBytes));
    } else {
        $out = array_merge($out, build_gdt_summary_lines($yaml, $answers, $maxBytes));
    }
    return $out;
}

function norm_contact(string $s): string {
    $s = ascii_only(clean_utf8_text($s, 200));
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

function format_gebdat(string $s): string {
    $digits = preg_replace('/\D+/', '', $s) ?? '';
    if (strlen($digits) >= 8) $digits = substr($digits, 0, 8);
    if (strlen($digits) !== 8) return ($s !== '' ? $s : '—');
    return substr($digits, 0, 2) . '.' . substr($digits, 2, 2) . '.' . substr($digits, 4, 4);
}

function parse_float_de(string $s): ?float {
    $s = trim($s);
    if ($s === '') return null;
    $s = str_replace(',', '.', $s);
    $s = preg_replace('/[^0-9.]/', '', $s) ?? $s;
    if ($s === '' || $s === '.') return null;
    return (float)$s;
}

function ymd_today(): string { return date('Ymd'); }

function form_yaml_for_id(string $formDir, string $formId, int $maxLength): array {
    if ($formId === '' || strlen($formId) > $maxLength) {
        return ['error' => 'Ungueltige Formular-ID: ' . $formId];
    }

    $matches = [];
    $yamlFiles = [];
    foreach ((array)@scandir($formDir) as $name) {
        if (!is_string($name)) continue;
        if (!preg_match('/^(?:(\\d+)-)?([a-z][a-z0-9_-]*)\\.yaml$/', $name, $m)) continue;
        $path = rtrim($formDir, '/') . '/' . $name;
        if (!is_file($path)) continue;
        $yamlFile = [
            'path' => $path,
            'priority' => ($m[1] === '') ? 1000 : (int)$m[1],
        ];
        $yamlFiles[] = $yamlFile;
        if ($m[2] === $formId) $matches[] = $yamlFile;
    }

    // Wenn kein Dateiname exakt passt, duerfen YAML-Dateien weitere IDs
    // deklarieren. Dadurch bleiben neue Formulare ohne PHP-Sonderlogik moeglich.
    if (count($matches) === 0 && function_exists('yaml_parse_file')) {
        foreach ($yamlFiles as $yamlFile) {
            $data = @yaml_parse_file((string)$yamlFile['path']);
            if (!is_array($data)) continue;
            $formIds = $data['meta']['form_ids'] ?? [];
            if (!is_array($formIds)) $formIds = [$formIds];
            foreach ($formIds as $declaredId) {
                if ((string)$declaredId !== $formId) continue;
                $matches[] = $yamlFile;
                break;
            }
        }
    }

    if (count($matches) === 0) {
        return ['error' => 'Formular-YAML nicht gefunden: ' . $formId . '.yaml'];
    }
    if (count($matches) > 1) {
        return ['error' => 'Mehrere Formular-YAML-Dateien fuer ' . $formId . ' gefunden.'];
    }
    return $matches[0];
}

/**
 * Ermittelt die Folgeformulare, die nach dem aktuellen Formular angefordert
 * werden sollen. Das YAML-Schema lautet:
 *
 * follow_up_forms:
 *   - form: act
 *     when:
 *       id: asthma
 *       equals: true
 */
function follow_up_forms_for_answers(
    array $yaml,
    array $answers,
    string $currentFormId,
    string $formDir,
    int $maxFormIdLength
): array {
    $rules = $yaml['follow_up_forms'] ?? [];
    if ($rules === null || $rules === []) {
        return ['forms' => [], 'errors' => []];
    }
    if (!is_array($rules)) {
        return ['forms' => [], 'errors' => ['follow_up_forms muss eine Liste sein.']];
    }

    $forms = [];
    $errors = [];
    $seen = [];

    foreach ($rules as $index => $rule) {
        if (!is_array($rule)) {
            $errors[] = 'follow_up_forms[' . (string)$index . '] ist ungueltig.';
            continue;
        }

        $condition = $rule['when'] ?? null;
        if (!is_array($condition) || !cond_ok($answers, $condition)) continue;

        $formId = trim((string)($rule['form'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_-]{0,3}$/', $formId)) {
            $errors[] = 'Ungueltige Folgeformular-ID: ' . $formId;
            continue;
        }
        if ($formId === $currentFormId) {
            $errors[] = 'Folgeformular darf nicht sich selbst ausloesen: ' . $formId;
            continue;
        }
        if (isset($seen[$formId])) continue;

        $yamlInfo = form_yaml_for_id($formDir, $formId, $maxFormIdLength);
        if (isset($yamlInfo['error'])) {
            $errors[] = 'Folgeformular ' . $formId . ': ' . (string)$yamlInfo['error'];
            continue;
        }

        $seen[$formId] = true;
        $forms[] = [
            'form_id' => $formId,
            'yaml_path' => (string)$yamlInfo['path'],
            'priority' => (int)$yamlInfo['priority'],
        ];
    }

    usort($forms, static function (array $a, array $b): int {
        return [$a['priority'], $a['form_id']] <=> [$b['priority'], $b['form_id']];
    });

    return ['forms' => $forms, 'errors' => $errors];
}

/**
 * Legt Folgeauftraege als sichere Kopien der aktuellen Eingabe-GDT an.
 * Der temporaere Dateiname wird erst nach vollstaendigem Kopieren per Hardlink
 * sichtbar gemacht, damit tablet.php keine halbe GDT-Datei einliest.
 */
function create_follow_up_requests(
    string $dirGdt,
    string $tabletPrefix,
    string $sourcePath,
    string $currentFormId,
    array $followUpForms
): array {
    $created = [];
    $existing = [];
    $errors = [];

    foreach ($followUpForms as $followUp) {
        $formId = (string)($followUp['form_id'] ?? '');
        if ($formId === '' || $formId === $currentFormId) {
            $errors[] = 'Ungueltiges Folgeformular: ' . $formId;
            continue;
        }

        $name = $tabletPrefix . $formId . '-i.gdt';
        $path = rtrim($dirGdt, '/') . '/' . $name;
        if (is_file($path)) {
            $existing[] = $name;
            continue;
        }

        $tmp = @tempnam($dirGdt, '.fragebogenpi-followup-');
        if ($tmp === false || !@copy($sourcePath, $tmp)) {
            if ($tmp !== false) @unlink($tmp);
            $errors[] = 'Folgeauftrag konnte nicht vorbereitet werden: ' . $name;
            continue;
        }

        if (@link($tmp, $path)) {
            @unlink($tmp);
            @chmod($path, 0664);
            $created[] = $name;
            continue;
        }

        @unlink($tmp);
        if (is_file($path)) {
            $existing[] = $name;
        } else {
            $errors[] = 'Folgeauftrag konnte nicht angelegt werden: ' . $name;
        }
    }

    return ['created' => $created, 'existing' => $existing, 'errors' => $errors];
}

function tablet_input_pattern(string $tabletId): string {
    $prefix = ($tabletId === '') ? '' : preg_quote($tabletId . '-', '/');
    return '/^' . $prefix . '([a-z][a-z0-9_-]{0,3})-i\\.gdt$/';
}

function collect_tablet_requests(string $dirGdt, string $formDir, string $tabletId, int $maxFormIdLength): array {
    $requests = [];
    $allRequests = [];
    $errors = [];
    $pattern = tablet_input_pattern($tabletId);

    foreach ((array)@scandir($dirGdt) as $name) {
        if (!is_string($name) || !preg_match($pattern, $name, $m)) continue;
        $path = rtrim($dirGdt, '/') . '/' . $name;
        if (!is_file($path)) continue;

        $fields = parse_gdt($path);
        $allRequests[] = [
            'name' => $name,
            'path' => $path,
            'form_id' => $m[1],
            'fields' => $fields,
        ];

        $yamlInfo = form_yaml_for_id($formDir, $m[1], $maxFormIdLength);
        if (isset($yamlInfo['error'])) {
            $errors[] = $name . ': ' . $yamlInfo['error'];
            continue;
        }

        $requests[] = [
            'name' => $name,
            'path' => $path,
            'form_id' => $m[1],
            'yaml_path' => $yamlInfo['path'],
            'priority' => $yamlInfo['priority'],
            'fields' => $fields,
        ];
    }

    usort($requests, static function (array $a, array $b): int {
        return [$a['priority'], $a['name']] <=> [$b['priority'], $b['name']];
    });

    return ['requests' => $requests, 'all' => $allRequests, 'errors' => $errors];
}

function patient_identity(array $fields): array {
    $identity = [];
    foreach (['3000', '0193', '3101', '3102', '3103'] as $field) {
        $value = (string)($fields[$field] ?? '');
        $identity[$field] = $value;
    }
    return $identity;
}

function has_patient_identity(array $fields): bool {
    return trim((string)($fields['3000'] ?? '')) !== ''
        || trim((string)($fields['0193'] ?? '')) !== '';
}

function requests_have_identity_conflict(array $requests): bool {
    if (count($requests) < 2) return false;
    $known = [];
    foreach ($requests as $request) {
        foreach (patient_identity($request['fields']) as $field => $value) {
            if ($value === '') continue;
            if (isset($known[$field]) && $known[$field] !== $value) return true;
            $known[$field] = $value;
        }
    }
    return false;
}

function delete_request_files(array $requests): int {
    $deleted = 0;
    foreach ($requests as $request) {
        $path = (string)($request['path'] ?? '');
        if ($path !== '' && is_file($path) && @unlink($path)) $deleted++;
    }
    return $deleted;
}
