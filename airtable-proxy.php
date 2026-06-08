<?php
define('DOING_AJAX', true);
require_once dirname(__DIR__) . '/wp-load.php';
define('AT_TOKEN',   'patztnHPJdo6ihr0l.f02ace97da8e694bd943399deda96fde235cd8480f1605e29e7302772884d14c');
define('AT_BASE',    'appSIg5wDCS1LQ52p');
define('VIMEO_TOKEN','9ec33cb8b24dc13821c695465f68f4ec');
define('ADMIN_EMAIL','lucian.virtic@hotmail.com');
define('IMAP_HOST',  '{mail.nicolaecatrina.com:993/imap/ssl/novalidate-cert}INBOX');
define('IMAP_USER',  'contact@nicolaecatrina.com');
define('IMAP_PASS',  'Ananda69#');

$allowed_origins = ['https://nicolaecatrina.com', 'https://www.nicolaecatrina.com'];
$request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$cors_origin = in_array($request_origin, $allowed_origins) ? $request_origin : $allowed_origins[0];
header('Access-Control-Allow-Origin: ' . $cors_origin);
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Block requests from foreign origins (allow empty origin = same-server/curl)
if ($request_origin && !in_array($request_origin, $allowed_origins)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';
$id     = isset($_GET['id'])     ? $_GET['id']     : '';
$body   = json_decode(file_get_contents('php://input'), true);
if (!$body) $body = array();

function at($method, $path, $body = null) {
    $url = 'https://api.airtable.com/v0/' . AT_BASE . '/' . $path;
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST,  $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . AT_TOKEN,
        'Content-Type: application/json'
    ));
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $result = curl_exec($ch);
    $error  = curl_error($ch);
    curl_close($ch);
    if ($error) return json_encode(array('error' => $error));
    return $result;
}

// Fetch all records across all pages (handles Airtable's 100-record page limit)
function atAll($path_base) {
    $records = array();
    $offset  = null;
    do {
        $path   = $path_base . ($offset ? '&offset=' . urlencode($offset) : '');
        $result = json_decode(at('GET', $path), true);
        if (isset($result['error']) || isset($result['errors'])) return $result;
        $records = array_merge($records, $result['records'] ?? array());
        $offset  = $result['offset'] ?? null;
    } while ($offset);
    return array('records' => $records);
}


// Table IDs
$TBL_C = 'tblnVo1XVBMhUmA4h'; // Courses
$TBL_S = 'tblrZi26rAsAHDI4z'; // Students
$TBL_SS = 'tblCkiOttKop525yY'; // Sessions

// Field queries
$FC = 'fields[]=fldQ7kSY1KZ2bTska&fields[]=fldqdzbMbgMf4fVU4&fields[]=fld4OYOz8TD70oFjP';
$FS = 'fields[]=fldTKQVFs3vrMrwWA&fields[]=fld4Lx0LL4sfYOyy7&fields[]=fld03gdtFjOIkn7wB&fields[]=fldUvZSA5jTlsLQFH&fields[]=fldl4zBz2wxOtsBkR&fields[]=fldzXA61F6oOzodW6';
$FSS = 'fields[]=fldfJXk0duawFD6mR&fields[]=fldgtWrqar4zhAZYJ&fields[]=fldp8IWdYZVQFQlWN&fields[]=fldTu1RdOGl8wMsBw';

// ── Email parse helper ────────────────────────────────────────────────────────
function ncParseEmail($subj, $body, $from_name, $from_email, $reply_to, $cat) {
    $t = strtolower($subj . ' ' . $body);
    $p = [
        'name'     => $from_name ?: '',
        'email'    => $reply_to  ?: $from_email,
        'course'   => '',
        'group'    => '',
        'status'   => 'P',
        'session'  => 'live',
        'amount'   => '',
        'city'     => 'Bucuresti',
        'notes'    => '',
        'is_order' => (bool)preg_match('/comand[ăa]\s*nou[ăa]|new order|order #/i', $subj),
    ];
    // WooCommerce: extract billing email from body (different from sender)
    if ($p['is_order'] && preg_match('/\b([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})\b/',
            str_ireplace($from_email, '', $body), $m))
        $p['email'] = trim($m[1]);
    // Amount: "200 RON", "630 lei", "200.00 RON"
    if (preg_match('/(\d+(?:[.,]\d{1,2})?)\s*(?:ron|lei|EUR|€|\$)/i', $body, $m))
        $p['amount'] = preg_replace('/[^0-9]/', '', $m[1]);
    // Order Nota field — highest priority for course/group
    $nota = '';
    if (preg_match('/not[ăa](?:\s*de\s*la\s*comand[ăa])?[:\s]+([^\n]{3,120})/i', $body, $mn)) $nota = trim($mn[1]);
    elseif (preg_match('/order\s+notes?[:\s]+([^\n]{3,120})/i', $body, $mn)) $nota = trim($mn[1]);
    $p['nota']     = $nota;
    $p['has_nota'] = $nota !== '';
    $src = $nota ?: $t; // prefer nota for course/group detection
    // Course
    if      (preg_match('/ytt.?m1|ttc1|modul\s*1/', strtolower($src))) $p['course'] = 'YTT-M1';
    elseif  (preg_match('/ytt.?m2|ttc2|modul\s*2/', strtolower($src))) $p['course'] = 'YTT-M2';
    elseif  (preg_match('/ytt.?m3|ttc3|modul\s*3|tibetan/', strtolower($src))) $p['course'] = 'YTT-M3';
    elseif  (preg_match('/i.?ching/', strtolower($src))) $p['course'] = 'I Ching';
    elseif  (preg_match('/alchimie|\bal\b/', strtolower($src))) $p['course'] = 'AL';
    // Group (from nota first, then body)
    if (preg_match('/grup[aă]\s*(\d+)|[gG](\d+)\b/', $nota ?: $body, $m))
        $p['group'] = trim($m[1] ?: $m[2]);
    // Payment confirmation keywords (student manual emails)
    $p['is_payment'] = (bool)preg_match('/am\s*pl[ăa]tit|am\s*achitat|pl[ăa]tit\b|v[aă]\s*mul[tț]umesc.*plat|payment.*confirm/i', $body);
    // Month detection (Romanian + English)
    $month_map = [
        'ianuarie'=>'January','ian'=>'January','january'=>'January',
        'februarie'=>'February','feb'=>'February','february'=>'February',
        'martie'=>'March','mar'=>'March','march'=>'March',
        'aprilie'=>'April','apr'=>'April','april'=>'April',
        'mai'=>'May','may'=>'May',
        'iunie'=>'June','iun'=>'June','june'=>'June',
        'iulie'=>'July','iul'=>'July','july'=>'July',
        'august'=>'August','aug'=>'August',
        'septembrie'=>'September','sep'=>'September','sept'=>'September','september'=>'September',
        'octombrie'=>'October','oct'=>'October','october'=>'October',
        'noiembrie'=>'November','noi'=>'November','nov'=>'November','november'=>'November',
        'decembrie'=>'December','dec'=>'December','december'=>'December',
    ];
    $p['month'] = '';
    foreach ($month_map as $pat => $name) {
        if (preg_match('/\b' . preg_quote($pat, '/') . '\b/i', $t)) { $p['month'] = $name; break; }
    }
    // KS26 session
    if ($cat === 'ks26' && preg_match('/replay/i', $body)) $p['session'] = 'replay';
    return $p;
}
// ─────────────────────────────────────────────────────────────────────────────

// ── Upsert student subscription ──────────────────────────────────────────────
function ncUpsertSub($em, $nm, $crs, $grp, $sts, $TBL_S) {
    $em  = strtolower(trim($em));
    $nm  = trim($nm);
    $crs = trim($crs);
    $grp = trim($grp);
    $sts = trim($sts) ?: 'P';
    if (!$em || !$crs) return ['ok' => false, 'error' => 'email and course required'];
    $fsub = 'fields[]=fldTKQVFs3vrMrwWA&fields[]=fld4Lx0LL4sfYOyy7&fields[]=fldUvZSA5jTlsLQFH';
    $all  = atAll("$TBL_S?$fsub");
    $found = null;
    foreach (($all['records'] ?? []) as $rec) {
        $f = $rec['fields'] ?? [];
        if (strtolower(trim($f['email'] ?? '')) === $em) { $found = $rec; break; }
    }
    $subs = [];
    if ($found) $subs = json_decode($found['fields']['subscriptions'] ?? '{}', true) ?: [];
    if (!isset($subs[$crs])) $subs[$crs] = ['curr' => '', 'next' => ''];
    $subs[$crs]['next'] = $sts;
    if ($grp) $subs[$crs]['G'] = $grp;
    $flds = ['subscriptions' => json_encode($subs)];
    if (!$found) {
        $flds['email'] = $em;
        if ($nm) $flds['name'] = $nm;
        $r = json_decode(at('POST', $TBL_S, ['fields' => $flds]), true);
        return ['ok' => true, 'action' => 'created', 'id' => $r['id'] ?? ''];
    } else {
        at('PATCH', "$TBL_S/" . $found['id'], ['fields' => $flds]);
        return ['ok' => true, 'action' => 'updated', 'name' => $found['fields']['name'] ?? $em];
    }
}
// ─────────────────────────────────────────────────────────────────────────────

// ── IMAP helpers ─────────────────────────────────────────────────────────────
function ncDecodeHeader($s) {
    if (!$s) return '';
    $parts = imap_mime_header_decode($s);
    $out = '';
    foreach ((array)$parts as $p) {
        $cs = (!$p->charset || $p->charset === 'default') ? 'UTF-8' : $p->charset;
        $out .= @mb_convert_encoding($p->text, 'UTF-8', $cs);
    }
    return $out;
}
function ncDecodeBody($raw, $enc) {
    switch ((int)$enc) {
        case 3: return base64_decode($raw);
        case 4: return quoted_printable_decode($raw);
        default: return $raw;
    }
}
function ncGetBodyPart($mbox, $num, $part, $sec) {
    $raw = imap_fetchbody($mbox, $num, $sec);
    $txt = ncDecodeBody($raw, $part->encoding ?? 0);
    $cs  = 'UTF-8';
    foreach (($part->parameters ?? []) as $p) {
        if (strtolower($p->attribute) === 'charset') { $cs = $p->value; break; }
    }
    $txt = @mb_convert_encoding($txt, 'UTF-8', $cs ?: 'UTF-8');
    if ($part->type === 0 && strtolower($part->subtype ?? '') === 'html') $txt = strip_tags($txt);
    return $txt;
}
function ncGetBody($mbox, $num, $struct, $prefix = '') {
    if (!isset($struct->parts)) {
        return ncGetBodyPart($mbox, $num, $struct, $prefix ?: '1');
    }
    $html = null;
    foreach ($struct->parts as $i => $p) {
        $sec = ($prefix ? $prefix . '.' : '') . ($i + 1);
        if ($p->type === 0) {
            $txt = ncGetBodyPart($mbox, $num, $p, $sec);
            if (strtolower($p->subtype ?? '') === 'plain') return $txt;
            $html = $txt;
        } elseif (isset($p->parts)) {
            $r = ncGetBody($mbox, $num, $p, $sec);
            if ($r) return $r;
        }
    }
    return $html ?? '';
}
function ncMakeDraft($cat, $from_name, $reply_to, $subject, $body_text) {
    $first   = trim(preg_split('/[\s,]+/u', trim($from_name))[0] ?? '');
    $foreign = !preg_match('/[ăâîșțĂÂÎȘȚ]/u', $from_name)
            && !preg_match('/\b(Mihai|Andrei|Ion|Dan|Ana|Ioana|Maria|Radu|Bogdan|Florin|Lucian|Claudia|Monica|Simona|Cristina|Daniela|Raluca|Sorin|Adrian|Ciprian|Stefan|Carmen|Florenta|Ramona|Dragos|Olga|Eugeniu|Anca|Mihaela|Sergiu|Neculai|Cristea|Mircea|Leonard|Catalin|Razvan|Gabriela|Adelina|Doina|Camelia|Apostol|Victoria|Emanuel|Doru|Tanase|Livadaru|Gutan|Spiridon|Bargau|Totu|Catalin|Ciprian|Dinu|Florentа)\b/iu', $from_name);
    $sign = $foreign ? "Best regards,\nNicolaeCatrina Team" : "Vă mulțumim!\nEchipa NicolaeCatrina";
    $hi   = $first ? ($foreign ? "Dear $first," : "Bună ziua $first,") : ($foreign ? "Hello," : "Bună ziua,");
    $t    = strtolower($subject . ' ' . $body_text);
    switch ($cat) {
        case 'ks26':
            return $foreign
                ? "$hi\n\nThank you for your interest in the Kashmir Shaivism 2026 Retreat!\n\nWe will contact you shortly with payment details (Early Early Bird: 630 RON).\n\n$sign"
                : "$hi\n\nMulțumim pentru interesul față de retragerea Kashmir Shaivism 2026!\n\nVă vom contacta în curând cu detalii (Early Early Bird: 630 RON).\n\n$sign";
        case 'monthly':
            $c = '';
            if (preg_match('/ytt.?m1|ttc1|modul\s*1/', $t)) $c = 'YTT-M1';
            elseif (preg_match('/ytt.?m2|ttc2|modul\s*2/', $t)) $c = 'YTT-M2';
            elseif (preg_match('/ytt.?m3|ttc3|modul\s*3|tibetan/', $t)) $c = 'YTT-M3';
            elseif (preg_match('/i.?ching|ching/', $t)) $c = 'I Ching';
            elseif (preg_match('/alchimie|\bal\b/', $t)) $c = 'AL';
            return "$hi\n\nMulțumim! Am înregistrat plata" . ($c ? " la $c" : '') . ".\n\n$sign";
        case 'q':
            return $foreign
                ? "$hi\n\nThank you for reaching out.\n\n[Please describe the issue and provide access/link here]\n\nWe apologise for the inconvenience. Please let us know if you need anything else.\n\n$sign"
                : "$hi\n\nMulțumim pentru mesajul dumneavoastră.\n\n[Descrieți situația și oferiți link/acces]\n\nNe cerem scuze pentru neplăcere. Reveniți dacă mai aveți întrebări.\n\n$sign";
        case 'initiation':
            return "$hi\n\nMulțumim pentru mesajul dumneavoastră.\n\nImpulsionările speciale sunt disponibile doar pentru participanții care îndeplinesc simultan:\n1. Au participat la tabăra din 2025 (în engleză — primăvară, sau în română — toamnă; nu se iau în calcul traducerile)\n2. Au participat la cel puțin un workshop de aprofundare (decembrie sau februarie, inclusiv reluările)\n\nFiecare participant are dreptul la o singură impulsionare per workshop.\n\nVă rugăm verificați participarea și reveniți cu detalii.\n\n$sign";
        default:
            return "$hi\n\nMulțumim pentru mesajul dumneavoastră. Vă vom răspunde în curând.\n\n$sign";
    }
}
// ─────────────────────────────────────────────────────────────────────────────

switch ($action) {
    // === Admin endpoints ===
    case 'courses':
        echo at('GET', "$TBL_C?$FC");
        break;
    case 'students':
        echo json_encode(atAll("$TBL_S?$FS"));
        break;
    case 'update':
        if (!$id) { echo json_encode(array('error' => 'Missing id')); break; }
        echo at('PATCH', "$TBL_S/$id", array('fields' => $body));
        break;
    case 'create':
        echo at('POST', $TBL_S, array('fields' => $body));
        break;

    // === Student portal endpoints ===
    case 'sessions':
        echo at('GET', "$TBL_SS?$FSS");
        break;

    case 'login':
        // Find student by login_code
        $code = isset($body['code']) ? $body['code'] : '';
        if (strlen($code) !== 5) {
            echo json_encode(array('error' => 'Invalid code'));
            break;
        }
        // Fetch all students and find by code
        $result = atAll("$TBL_S?$FS");
        $found = null;
        foreach (($result['records'] ?? []) as $rec) {
            $f = $rec['fields'] ?? [];
            if (($f['login_code'] ?? '') === $code) {
                $found = $rec;
                break;
            }
        }
        if ($found) {
            $f = $found['fields'] ?? [];
            $isAdmin = strtolower(trim($f['email'] ?? '')) === ADMIN_EMAIL;
            echo json_encode(array('ok' => true, 'student' => $found, 'isAdmin' => $isAdmin));
        } else {
            echo json_encode(array('error' => 'Invalid code'));
        }
        break;

    case 'sendcode':
        // Generate 5-digit code, save to student record, email it
        $email = isset($body['email']) ? strtolower(trim($body['email'])) : '';
        if (!$email) {
            echo json_encode(array('error' => 'Email required'));
            break;
        }
        // Find student by email
        $result = atAll("$TBL_S?$FS");
        $found = null;
        foreach (($result['records'] ?? []) as $rec) {
            $f = $rec['fields'] ?? [];
            if (strtolower(trim($f['email'] ?? '')) === $email) {
                $found = $rec;
                break;
            }
        }
        if (!$found) {
            // Check ks26.csv for this email before giving up
            $csv_file = __DIR__ . '/ks26.csv';
            $csv_name = null;
            if (file_exists($csv_file) && ($fh = fopen($csv_file, 'r')) !== false) {
                fgetcsv($fh); // skip header
                while (($row = fgetcsv($fh)) !== false) {
                    if (strtolower(trim($row[1] ?? '')) === $email) {
                        $csv_name = trim($row[0] ?? '');
                        break;
                    }
                }
                fclose($fh);
            }
            if ($csv_name === null) {
                echo json_encode(array('error' => 'Email not found'));
                break;
            }
            $new = json_decode(at('POST', $TBL_S, array('fields' => array(
                'email' => $email,
                'name'  => $csv_name,
            ))), true);
            if (isset($new['error'])) {
                echo json_encode(array('error' => 'Failed to create student'));
                break;
            }
            $found = $new;
        }
        // Generate code and save
        $code = str_pad(mt_rand(0, 99999), 5, '0', STR_PAD_LEFT);
        at('PATCH', "$TBL_S/" . $found['id'], array(
            'fields' => array('login_code' => $code)
        ));
        // Send email via wp_mail or PHP mail
        $name = $found['fields']['name'] ?? 'Student';
        $subject = 'Your login code';
        $message = "Hi $name,\n\nYour login code is: $code\n\nUse it to log in at: https://www.nicolaecatrina.com/app/student.html\n\nThis code is valid until you request a new one.";
        $headers = 'From: noreply@nicolaecatrina.com';
        wp_mail($email, $subject, $message, $headers);
        echo json_encode(array('ok' => true, 'message' => 'Code sent to your email'));
        break;

    case 'srvcheck':
        echo json_encode(['imap' => extension_loaded('imap'), 'php' => PHP_VERSION, 'extensions' => get_loaded_extensions()]);
        break;

    case 'debug':
        $url = 'https://api.airtable.com/v0/meta/bases';
        $ch  = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . AT_TOKEN,
            'Content-Type: application/json'
        ));
        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
        $imap_folders = [];
        $imap_err = '';
        $mb = @imap_open('{mail.nicolaecatrina.com:993/imap/ssl/novalidate-cert}INBOX', IMAP_USER, IMAP_PASS, 0, 1);
        if ($mb) {
            $fl = imap_list($mb, '{mail.nicolaecatrina.com:993/imap/ssl/novalidate-cert}', '*');
            foreach ((array)$fl as $f) $imap_folders[] = str_replace('{mail.nicolaecatrina.com:993/imap/ssl/novalidate-cert}', '', $f);
            imap_close($mb);
        } else { $imap_err = imap_last_error(); }
        echo json_encode(array(
            'token_prefix' => substr(AT_TOKEN, 0, 10) . '...',
            'token_length' => strlen(AT_TOKEN),
            'http_code'    => $httpCode,
            'curl_error'   => $error,
            'imap_loaded'  => extension_loaded('imap'),
            'php_version'  => PHP_VERSION,
            'imap_folders' => $imap_folders,
            'imap_err'     => $imap_err,
            'response'     => json_decode($result)
        ));
        break;

    // Fetch video links from the Vimeo folder whose name matches the course ID
    case 'vimeo':
        $course = trim($_GET['course'] ?? '');
        $vimeo_aliases = ['AL' => 'Alchimie'];
        if (isset($vimeo_aliases[$course])) $course = $vimeo_aliases[$course];
        $from = intval($_GET['from'] ?? 0);
        $to   = intval($_GET['to']   ?? 0);
        $all_mode = ($from <= 0 && $to <= 0);
        if (!$course || (!$all_mode && $from > $to)) {
            echo json_encode(array('error' => 'Invalid parameters'));
            break;
        }
        if (!VIMEO_TOKEN) {
            echo json_encode(array('error' => 'Vimeo not configured on server'));
            break;
        }
        // Find the folder (project) whose name matches the course ID
        $ch = curl_init('https://api.vimeo.com/me/projects?per_page=100&fields=uri,name');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . VIMEO_TOKEN));
        $result = curl_exec($ch);
        curl_close($ch);
        $projects   = json_decode($result, true);
        $project_id = null;
        foreach (($projects['data'] ?? array()) as $proj) {
            if (strcasecmp(trim($proj['name']), $course) === 0) {
                preg_match('/\/(\d+)$/', $proj['uri'], $m);
                $project_id = $m[1] ?? null;
                break;
            }
        }
        if (!$project_id) {
            echo json_encode(array('error' => 'No Vimeo folder found matching "' . $course . '"'));
            break;
        }
        // Fetch all videos from the folder, extract class number via C0*(\d+) pattern
        $videos = array();
        $page   = 1;
        do {
            $url = 'https://api.vimeo.com/me/projects/' . $project_id . '/videos'
                 . '?per_page=100&page=' . $page
                 . '&sort=default&direction=asc&fields=link,uri,name';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer ' . VIMEO_TOKEN));
            $result = curl_exec($ch);
            $err    = curl_error($ch);
            curl_close($ch);
            if ($err) { echo json_encode(array('error' => $err)); break 2; }
            $data = json_decode($result, true);
            if (isset($data['error'])) { echo json_encode(array('error' => $data['error'])); break 2; }
            foreach (($data['data'] ?? array()) as $video) {
                $name = $video['name'] ?? '';
                $num  = null;
                // Primary: extract number from C01/C1 pattern (handles zero-padded)
                if (preg_match('/[Cc]0*(\d+)/', $name, $cm)) {
                    $num = intval($cm[1]);
                } else {
                    // Fallback: bare number preceded by non-alphanumeric or start
                    if (preg_match('/(?:^|(?<=[^a-zA-Z\d]))(\d+)(?!\d)/', $name, $cm)) {
                        $num = intval($cm[1]);
                    }
                }
                if ($num === null) continue;
                if ($all_mode || ($num >= $from && $num <= $to)) {
                    $videos[] = array('class_num' => $num, 'link' => $video['link'] ?? '');
                }
            }
            $page++;
        } while (!empty($data['paging']['next']));
        usort($videos, function($a, $b) { return $a['class_num'] - $b['class_num']; });
        echo json_encode(array('videos' => $videos));
        break;

    // Set a fresh password on a Vimeo video and return its embed code
    case 'vimeo_play':
        $url = trim($body['url'] ?? '');
        if (!$url) { echo json_encode(array('error' => 'No URL')); break; }
        if (!VIMEO_TOKEN) { echo json_encode(array('error' => 'Vimeo not configured')); break; }
        preg_match('/vimeo\.com\/(\d+)/', $url, $m);
        $video_id = $m[1] ?? '';
        if (!$video_id) { echo json_encode(array('error' => 'Invalid Vimeo URL')); break; }
        // 4-digit numeric password
        $password = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        // Update video privacy + password
        $ch = curl_init('https://api.vimeo.com/videos/' . $video_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . VIMEO_TOKEN,
            'Content-Type: application/json',
            'Accept: application/vnd.vimeo.*+json;version=3.4'
        ));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
            'privacy'  => array('view' => 'password'),
            'password' => $password
        )));
        $result = curl_exec($ch);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($err) { echo json_encode(array('error' => $err)); break; }
        $data = json_decode($result, true);
        if (isset($data['error'])) {
            echo json_encode(array('error' => $data['error'], 'detail' => $data['developer_message'] ?? ''));
            break;
        }
        // Make embed iframe responsive
        $embed = $data['embed']['html'] ?? '';
        $embed = preg_replace('/width="\d+"/',  'width="100%"',  $embed);
        $embed = preg_replace('/height="\d+"/', 'height="100%"', $embed);
        echo json_encode(array('ok' => true, 'password' => $password, 'embed' => $embed));
        break;

    // Delete all sessions for a given group id (e.g. YTT-M1-G6)
    // Optional ?email=x to delete only private sessions for that email; omit to delete only public (no-email) records
    case 'sessions_clear':
        $groupId   = isset($_GET['id'])    ? $_GET['id']             : '';
        $emailClear = isset($_GET['email']) ? trim($_GET['email'])    : '';
        if (!$groupId) { echo json_encode(array('error' => 'Missing id')); break; }
        $safeGid  = str_replace("'", "\\'", $groupId);
        $safeEml  = str_replace("'", "\\'", $emailClear);
        // email param present → clear only that subscriber's private records
        // email param absent  → clear only public (no-email) group records
        $formula  = urlencode("AND({id}='$safeGid',{email}='$safeEml')");
        $existing = json_decode(at('GET', "$TBL_SS?filterByFormula=$formula&fields[]=fldfJXk0duawFD6mR"), true);
        $records = $existing['records'] ?? [];
        $deleted = 0;
        foreach (array_chunk($records, 10) as $chunk) {
            $params = implode('&', array_map(function($r) { return 'records[]=' . $r['id']; }, $chunk));
            at('DELETE', "$TBL_SS?$params");
            $deleted += count($chunk);
        }
        echo json_encode(array('ok' => true, 'deleted' => $deleted));
        break;

    // Upsert session records into Sessions table
    case 'sessions_upsert':
        $sessions = $body['sessions'] ?? array();
        if (!$sessions) { echo json_encode(array('error' => 'No sessions provided')); break; }
        $count = 0;
        foreach ($sessions as $sess) {
            $sid       = $sess['id']      ?? '';
            $session   = $sess['session'] ?? '';
            $emailVal  = isset($sess['email']) ? trim($sess['email']) : '';
            if (!$sid || !$session) continue;
            $safeId    = str_replace("'", "\\'", $sid);
            $safeSess  = str_replace("'", "\\'", $session);
            $safeEmail = str_replace("'", "\\'", $emailVal);
            // id + session + email together identify a unique record (private vs public)
            $formula  = urlencode("AND({id}='$safeId',{session}='$safeSess',{email}='$safeEmail')");
            $existing = json_decode(at('GET', "$TBL_SS?filterByFormula=$formula&maxRecords=1"), true);
            $recId    = $existing['records'][0]['id'] ?? null;
            $fields   = array(
                'id'      => $sid,
                'session' => $sess['session'] ?? '',
                'link'    => $sess['link']    ?? ''
            );
            if ($emailVal) $fields['email'] = $emailVal;
            if ($recId) {
                at('PATCH', "$TBL_SS/$recId", array('fields' => $fields));
            } else {
                at('POST', $TBL_SS, array('fields' => $fields));
            }
            $count++;
        }
        echo json_encode(array('ok' => true, 'count' => $count));
        break;

    // Rename YTT-M2 videos: C1## / V1## → "YTT M2 C{num-114}". Others left untouched.
    // POST body: { dry_run: true } to preview without applying.
    case 'vimeo_rename_m2':
        if (!VIMEO_TOKEN) { echo json_encode(['error' => 'Vimeo not configured']); break; }
        $dry_run = !empty($body['dry_run']);

        // Find YTT-M2 folder
        $ch = curl_init('https://api.vimeo.com/me/projects?per_page=100&fields=uri,name');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . VIMEO_TOKEN]);
        $result = curl_exec($ch);
        curl_close($ch);
        $projects   = json_decode($result, true);
        $project_id = null;
        foreach (($projects['data'] ?? []) as $proj) {
            if (strcasecmp(trim($proj['name']), 'YTT-M2') === 0) {
                preg_match('/\/(\d+)$/', $proj['uri'], $m);
                $project_id = $m[1] ?? null;
                break;
            }
        }
        if (!$project_id) { echo json_encode(['error' => 'YTT-M2 folder not found']); break; }

        // Fetch all videos in folder
        $all_videos = [];
        $page = 1;
        do {
            $url = 'https://api.vimeo.com/me/projects/' . $project_id . '/videos'
                 . '?per_page=100&page=' . $page . '&fields=uri,name';
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . VIMEO_TOKEN]);
            $vres = curl_exec($ch);
            $verr = curl_error($ch);
            curl_close($ch);
            if ($verr) { echo json_encode(['error' => $verr]); break 2; }
            $vdata = json_decode($vres, true);
            if (isset($vdata['error'])) { echo json_encode(['error' => $vdata['error']]); break 2; }
            foreach (($vdata['data'] ?? []) as $v) $all_videos[] = $v;
            $page++;
        } while (!empty($vdata['paging']['next']));

        // Process each video
        $renamed = [];
        $skipped = [];
        $errors  = [];
        foreach ($all_videos as $v) {
            $name = trim($v['name'] ?? '');
            // Match exactly C1## or V1## (case-insensitive, full title)
            if (!preg_match('/^[CV]\s*1(\d{2})$/i', $name, $m)) {
                $skipped[] = $name;
                continue;
            }
            $old_num  = 100 + (int)$m[1];   // e.g. C115 → 115
            $new_num  = $old_num - 114;       // 115 → 1
            if ($new_num < 1) { $skipped[] = $name . ' (result < 1)'; continue; }
            $new_name = 'YTT M2 C' . $new_num;

            if ($dry_run) {
                $renamed[] = ['from' => $name, 'to' => $new_name, 'dry' => true];
                continue;
            }

            preg_match('/\/(\d+)$/', $v['uri'], $vm);
            $vid = $vm[1] ?? '';
            if (!$vid) { $errors[] = $name . ': no video id'; continue; }

            $ch = curl_init('https://api.vimeo.com/videos/' . $vid);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . VIMEO_TOKEN,
                'Content-Type: application/json',
                'Accept: application/vnd.vimeo.*+json;version=3.4'
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['name' => $new_name]));
            $pres = curl_exec($ch);
            $perr = curl_error($ch);
            curl_close($ch);

            if ($perr) {
                $errors[] = $name . ': ' . $perr;
            } else {
                $pd = json_decode($pres, true);
                if (isset($pd['error'])) $errors[] = $name . ': ' . $pd['error'];
                else $renamed[] = ['from' => $name, 'to' => $new_name];
            }
        }
        echo json_encode(['ok' => true, 'dry_run' => $dry_run, 'renamed' => $renamed, 'skipped' => $skipped, 'errors' => $errors]);
        break;

    case 'sendemail':
        $subject  = trim($body['subject']  ?? '');
        $message  = trim($body['message']  ?? '');
        $replyTo  = trim($body['replyTo']  ?? '');
        $name     = trim($body['name']     ?? '');
        if (!$subject || !$message) {
            echo json_encode(array('error' => 'Subject and message required'));
            break;
        }
        // Rate limit: max 10 requests per hour per IP
        $rl_file = sys_get_temp_dir() . '/nc_mail_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rl = file_exists($rl_file) ? json_decode(file_get_contents($rl_file), true) : [];
        $now = time();
        if (!isset($rl['reset']) || $now > $rl['reset']) { $rl = ['count' => 0, 'reset' => $now + 3600]; }
        if ($rl['count'] >= 10) {
            http_response_code(429);
            echo json_encode(['error' => 'Too many requests. Try again later.']);
            break;
        }
        $rl['count']++;
        file_put_contents($rl_file, json_encode($rl));
        $to      = 'contact@nicolaecatrina.com';
        $headers = array('Content-Type: text/plain; charset=UTF-8');
        if ($replyTo) {
            $headers[] = $name
                ? 'Reply-To: ' . $name . ' <' . $replyTo . '>'
                : 'Reply-To: ' . $replyTo;
        }
        $fullMessage = $message . ($name || $replyTo ? "\n\n-- \n" . $name . ($replyTo ? " <$replyTo>" : '') : '');
        $sent = wp_mail($to, $subject, $fullMessage, $headers);
        if ($sent) {
            echo json_encode(array('ok' => true));
        } else {
            echo json_encode(array('error' => 'Mail delivery failed'));
        }
        break;

    case 'csv_append':
        $name  = str_replace([',',"\n","\r"], '', trim($body['name']  ?? ''));
        $email = str_replace([',',"\n","\r"], '', trim($body['email'] ?? ''));
        $part  = str_replace([',',"\n","\r"], '', trim($body['part']  ?? 'new'));
        if (!$name) { echo json_encode(['error' => 'Name required']); break; }
        $csv_file = __DIR__ . '/mahamrityunjaya.csv';
        $line = $name . ',' . $email . ',' . $part . "\n";
        $ok = file_put_contents($csv_file, $line, FILE_APPEND | LOCK_EX);
        echo $ok !== false ? json_encode(['ok' => true]) : json_encode(['error' => 'Write failed']);
        break;

    case 'email_scan':
        $mark_rd = !empty($body['mark_read']);
        $mbox = @imap_open(IMAP_HOST, IMAP_USER, IMAP_PASS, 0, 1);
        if (!$mbox) { echo json_encode(['error' => 'IMAP: ' . imap_last_error()]); break; }
        $nums = imap_search($mbox, 'UNSEEN') ?: [];
        rsort($nums);
        $nums = array_slice($nums, 0, 100);
        $cats = ['ks26' => [], 'monthly' => [], 'initiation' => [], 'q' => [], 'other' => []];
        foreach ($nums as $num) {
            $hdr  = imap_headerinfo($mbox, $num);
            $ov   = imap_fetch_overview($mbox, (string)$num, 0);
            $from = $hdr->from[0]     ?? null;
            $repl = $hdr->reply_to[0] ?? null;
            $fe   = $from ? ($from->mailbox . '@' . ($from->host ?? '')) : '';
            $re   = $repl ? ($repl->mailbox . '@' . ($repl->host ?? '')) : $fe;
            $fn   = $from ? ncDecodeHeader($from->personal ?? '') : '';
            $subj = ncDecodeHeader($hdr->subject ?? '(no subject)');
            $seen = !empty($ov[0]->seen);
            // B. Skip "Plată înregistrată" — mark as read, exclude
            if (preg_match('/plat[ăa]\s*[îi]nregistrat[ăa]/i', $subj)) {
                imap_setflag_full($mbox, (string)$num, '\\Seen');
                continue;
            }
            $dstr = date('d M Y, H:i', strtotime($hdr->date ?? 'now'));
            $struct = imap_fetchstructure($mbox, $num);
            $btxt = mb_substr(trim(ncGetBody($mbox, $num, $struct)), 0, 500);
            $tx   = strtolower($subj . ' ' . $btxt);
            $is_q = preg_match('/\?\s*$/', trim($subj))
                 || preg_match('/not\s+received|haven.t\s+received|didn.t\s+receive|still\s+wait|not\s+arriv|no.*recording|recording.*not|not.*access/i', $tx);
            if      (preg_match('/ks\s*26|kashmir|retreat\s*2026|reluare\s*ks/i', $tx))               $cat = 'ks26';
            elseif  (preg_match('/impulsionar|ini[tț]ier|initiation|ceremoni|mahamrityunjaya/i', $tx)) $cat = 'initiation';
            elseif  ($is_q && preg_match('/ytt|ttc|modul|yoga|tantr|lesson|class|record|session|link|access/i', $tx)) $cat = 'q';
            elseif  (preg_match('/ytt|ttc|modul\s*[123]|yoga\s*tantr|i\s*ching|alchimie|abonament|comand[ăa]\s*nou[ăa]|new order|order #/i', $tx)) $cat = 'monthly';
            else    $cat = 'other';
            $parsed = ncParseEmail($subj, $btxt, $fn, $fe, $re, $cat);
            // C. Other + payment confirm → reclassify for easier handling
            if ($cat === 'other' && ($parsed['is_payment'] || $parsed['is_order'])) {
                $cat = preg_match('/ks\s*26|kashmir/i', $tx) ? 'ks26' : 'monthly';
                $parsed['cat'] = $cat;
            }
            // Auto-upsert when all required payment fields are present
            $auto_action = null;
            if (($parsed['is_payment'] || $parsed['is_order'])
                && !empty($parsed['email']) && filter_var($parsed['email'], FILTER_VALIDATE_EMAIL)
                && !empty($parsed['course'])
                && !empty($parsed['group'])
                && !empty($parsed['month'])
            ) {
                $auto_action = ncUpsertSub($parsed['email'], $parsed['name'], $parsed['course'], $parsed['group'], $parsed['status'] ?? 'P', $TBL_S);
                if (!empty($auto_action['ok'])) {
                    imap_setflag_full($mbox, (string)$num, '\\Seen');
                    continue;
                }
            }
            $cats[$cat][] = ['num' => $num, 'from_name' => $fn, 'from_email' => $fe, 'reply_to' => $re,
                             'subject' => $subj, 'date' => $dstr, 'seen' => $seen, 'body' => $btxt,
                             'draft' => ncMakeDraft($cat, $fn, $re, $subj, $btxt), 'cat' => $cat,
                             'parsed' => $parsed, 'auto_action' => $auto_action];
            if ($mark_rd && !$seen) imap_setflag_full($mbox, (string)$num, '\\Seen');
        }
        imap_close($mbox);
        echo json_encode(['ok' => true, 'total' => count($nums), 'cats' => $cats]);
        break;

    case 'send_admin':
        $to   = trim($body['to']      ?? '');
        $subj = trim($body['subject'] ?? '');
        $msg  = trim($body['message'] ?? '');
        if (!$to || !$subj || !$msg) { echo json_encode(['error' => 'to/subject/message required']); break; }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { echo json_encode(['error' => 'Invalid email']); break; }
        $hdrs = ['Content-Type: text/plain; charset=UTF-8',
                 'From: NicolaeCatrina <contact@nicolaecatrina.com>',
                 'Reply-To: contact@nicolaecatrina.com'];
        $sent = wp_mail($to, $subj, $msg, $hdrs);
        if (!$sent) { echo json_encode(['error' => 'wp_mail failed']); break; }
        // Append copy to IMAP Sent folder
        $imap_base = '{mail.nicolaecatrina.com:993/imap/ssl/novalidate-cert}';
        $imbox = @imap_open($imap_base . 'INBOX', IMAP_USER, IMAP_PASS, 0, 1);
        $saved_folder = '';
        if ($imbox) {
            $fl = imap_list($imbox, $imap_base, '*') ?: [];
            foreach ($fl as $f) {
                if (stripos($f, 'sent') !== false) { $saved_folder = $f; break; }
            }
            if (!$saved_folder) $saved_folder = $imap_base . 'Sent Messages'; // cPanel Dovecot default
            $raw = "From: NicolaeCatrina <contact@nicolaecatrina.com>\r\n"
                 . "To: $to\r\n"
                 . "Subject: $subj\r\n"
                 . "Date: " . date('r') . "\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
                 . $msg;
            imap_append($imbox, $saved_folder, $raw, '\\Seen');
            imap_close($imbox);
        }
        echo json_encode(['ok' => true, 'saved_to' => str_replace($imap_base, '', $saved_folder)]);
        break;

    case 'upsert_sub':
        $em  = $body['email']  ?? '';
        $nm  = $body['name']   ?? '';
        $crs = $body['course'] ?? '';
        $grp = $body['group']  ?? '';
        $sts = $body['status'] ?? 'P';
        $result = ncUpsertSub($em, $nm, $crs, $grp, $sts, $TBL_S);
        echo json_encode($result);
        break;

    case 'ks26_upsert':
        $unm  = str_replace(["\n","\r"], '', trim($body['name']    ?? ''));
        $uem  = strtolower(str_replace(["\n","\r"], '', trim($body['email']   ?? '')));
        $utax = str_replace(["\n","\r"], '', trim($body['tax']     ?? ''));
        $uses = str_replace(["\n","\r"], '', trim($body['session'] ?? 'live'));
        $unto = str_replace(["\n","\r"], '', trim($body['notes']   ?? ''));
        if (!$uem) { echo json_encode(['error' => 'Email required']); break; }
        $uf = __DIR__ . '/ks26.csv';
        if (!file_exists($uf)) { file_put_contents($uf, "name,email,tax,session,notes\n"); }
        $urows = []; $uidx = -1;
        if (($ufh = fopen($uf, 'r')) !== false) { fgetcsv($ufh); while (($ur=fgetcsv($ufh))!==false) $urows[]=$ur; fclose($ufh); }
        for ($i=0;$i<count($urows);$i++) {
            if (strtolower(trim($urows[$i][1]??''))===$uem && strtolower(trim($urows[$i][3]??''))===$uses) { $uidx=$i; break; }
        }
        if ($uidx>=0) {
            if ($unm) $urows[$uidx][0]=$unm; if ($utax) $urows[$uidx][2]=$utax; if ($unto) $urows[$uidx][4]=$unto;
            $uact='updated';
        } else { $urows[]=[$unm,$uem,$utax,$uses,$unto]; $uact='created'; }
        $ufh=fopen($uf,'w'); fputcsv($ufh,['name','email','tax','session','notes']);
        foreach($urows as $ur) fputcsv($ufh,$ur); fclose($ufh);
        echo json_encode(['ok'=>true,'action'=>$uact]);
        break;

    case 'email_flag':
        $num  = intval($body['num']  ?? 0);
        $flag = !empty($body['flag']);
        if (!$num) { echo json_encode(['error' => 'num required']); break; }
        $mbox = @imap_open(IMAP_HOST, IMAP_USER, IMAP_PASS, 0, 1);
        if (!$mbox) { echo json_encode(['error' => 'IMAP: ' . imap_last_error()]); break; }
        if ($flag) imap_setflag_full($mbox,   (string)$num, '\\Flagged');
        else       imap_clearflag_full($mbox, (string)$num, '\\Flagged');
        imap_close($mbox);
        echo json_encode(['ok' => true, 'flagged' => $flag]);
        break;

    case 'ks26_read':
        $f = __DIR__ . '/ks26.csv';
        if (!file_exists($f)) { echo json_encode(['rows' => []]); break; }
        $rows = [];
        if (($fh = fopen($f, 'r')) !== false) {
            fgetcsv($fh); // skip header
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 4) continue;
                $rows[] = ['name' => trim($row[0]), 'email' => trim($row[1]), 'tax' => trim($row[2]), 'session' => trim($row[3]), 'notes' => trim($row[4] ?? '')];
            }
            fclose($fh);
        }
        echo json_encode(['rows' => $rows]);
        break;

    case 'ks26_append':
        $name    = str_replace(["\n","\r"], '', trim($body['name']    ?? ''));
        $email   = str_replace(["\n","\r"], '', trim($body['email']   ?? ''));
        $tax     = str_replace(["\n","\r"], '', trim($body['tax']     ?? ''));
        $session = str_replace(["\n","\r"], '', trim($body['session'] ?? 'live'));
        $notes   = str_replace(["\n","\r"], '', trim($body['notes']   ?? ''));
        if (!$name && !$email) { echo json_encode(['error' => 'Name or email required']); break; }
        $f = __DIR__ . '/ks26.csv';
        if (!file_exists($f)) file_put_contents($f, "name,email,tax,session,notes\n");
        $fh = fopen($f, 'a');
        if (!$fh) { echo json_encode(['error' => 'Write failed']); break; }
        fputcsv($fh, [$name, $email, $tax, $session, $notes]);
        fclose($fh);
        echo json_encode(['ok' => true]);
        break;

    case 'student_sub':
        $em = strtolower(trim($_GET['email'] ?? ''));
        if (!$em) { echo json_encode(['error' => 'email required']); break; }
        $fsub = 'fields[]=fldTKQVFs3vrMrwWA&fields[]=fld4Lx0LL4sfYOyy7&fields[]=fldUvZSA5jTlsLQFH&fields[]=fld03gdtFjOIkn7wB';
        $all  = atAll("$TBL_S?$fsub");
        $found = null;
        foreach (($all['records'] ?? []) as $rec) {
            $f = $rec['fields'] ?? [];
            if (strtolower(trim($f['email'] ?? '')) === $em) { $found = $rec; break; }
        }
        if (!$found) { echo json_encode(['found' => false]); break; }
        $ff   = $found['fields'] ?? [];
        $subs = json_decode($ff['subscriptions'] ?? '{}', true) ?: [];
        echo json_encode(['found' => true, 'name' => $ff['name'] ?? '', 'note' => $ff['note'] ?? '', 'courses' => array_keys($subs)]);
        break;

    case 'initiations_append':
        $name   = str_replace([',',"\n","\r"], '', trim($body['name']   ?? ''));
        $email  = str_replace([',',"\n","\r"], '', trim($body['email']  ?? ''));
        $status = str_replace([',',"\n","\r"], '', trim($body['status'] ?? 'new'));
        if (!$name && !$email) { echo json_encode(['error' => 'Name or email required']); break; }
        $csv_file = __DIR__ . '/mahamrityunjaya.csv';
        $line = $name . ',' . $email . ',' . $status . "\n";
        $ok = file_put_contents($csv_file, $line, FILE_APPEND | LOCK_EX);
        echo $ok !== false ? json_encode(['ok' => true]) : json_encode(['error' => 'Write failed']);
        break;

    case 'ks2026':
        $f = __DIR__ . '/ks26.csv';
        if (!file_exists($f)) { echo json_encode(['enrollments' => new stdClass()]); break; }
        $enrollments = [];
        if (($fh = fopen($f, 'r')) !== false) {
            fgetcsv($fh); // skip header
            while (($row = fgetcsv($fh)) !== false) {
                $email = strtolower(trim($row[1] ?? ''));
                $sess  = strtolower(trim($row[3] ?? 'live')) ?: 'live';
                if ($email && strpos($email, '@') !== false) {
                    if (!isset($enrollments[$email])) $enrollments[$email] = [];
                    if (!in_array($sess, $enrollments[$email])) $enrollments[$email][] = $sess;
                }
            }
            fclose($fh);
        }
        echo json_encode(['enrollments' => $enrollments ?: new stdClass()]);
        break;

    default:
        echo json_encode(array('error' => 'unknown action'));
}