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
    // KS26 session — "live and replay" combo product (old title: "Replay session for live
    // subscription" / "Sesiune de revizionare pentru cei care au participat live"; new title:
    // "KS26 live and replay") always grants both → saved as 'both' (shown as L+R in ks26.html)
    if ($cat === 'ks26') {
        if (preg_match('/live\s*(?:and|&|\+|si|și)\s*replay|replay session for live subscription|sesiune de revizionare pentru cei care au participat live/i', $subj . ' ' . $body))
            $p['session'] = 'both';
        elseif (preg_match('/replay|reaudi|reluare/i', $body))
            $p['session'] = 'replay';
    }
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

// ── WooCommerce order parser (product-line first; more precise than ncParseEmail)
function ncParseOrder($subj, $body) {
    $flat = trim(preg_replace('/\s+/', ' ', $body));
    $o = ['onum'=>'', 'name'=>'', 'email'=>'', 'course'=>'', 'group'=>'', 'total'=>'', 'nota'=>'', 'month'=>'', 'session'=>''];
    if (preg_match('/\((\d+)\)/', $subj, $m)) $o['onum'] = $m[1];
    // Buyer name
    if (preg_match('/(?:comand[ăa] de la|order from) (.+?):/iu', $flat, $m)) $o['name'] = trim($m[1]);
    // Billing email = last email address in body
    if (preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $flat, $m)) $o['email'] = end($m[0]);
    // Product region between price header and subtotal
    $prod = '';
    if (preg_match('/(?:Pre[țt]|Price)\s+(.*?)\s+(?:Sub-?total|Subtotal)/iu', $flat, $m))
        $prod = preg_replace('/\s+1 lei [\d.,]+$/', '', $m[1]);
    // Total (last occurrence)
    if (preg_match_all('/Total:\s*lei\s*([\d.,]+)/i', $flat, $m)) $o['total'] = end($m[1]);
    // Nota
    if (preg_match('/(?:Not[ăa]|Note):\s*(.+?)\s*(?:Adres[ăa] de facturare|Billing address)/iu', $flat, $m))
        $o['nota'] = trim($m[1]);
    $o['product'] = trim($prod);
    // Course from product line, fallback nota
    $hay = strtolower($prod . ' ' . $o['nota']);
    if      (preg_match('/iching|i ching/', $hay))                       $o['course'] = 'I Ching';
    elseif  (preg_match('/kashmir|ks\s*26|replay session for live subscription|sesiune de revizionare pentru cei care au participat live/', $hay))
                                                                          $o['course'] = 'KS26';
    elseif  (preg_match('/alchim/', $hay))                               $o['course'] = 'AL';
    elseif  (preg_match('/m3|modul(?:ul)? 3|module 3/', $hay))           $o['course'] = 'YTT-M3';
    elseif  (preg_match('/m2|modul(?:ul)? 2|module 2/', $hay))           $o['course'] = 'YTT-M2';
    elseif  (preg_match('/m1|modul(?:ul)? 1|module 1/', $hay))           $o['course'] = 'YTT-M1';
    // KS26 session — combo product (old title: "Replay session for live subscription" /
    // "Sesiune de revizionare pentru cei care au participat live"; new title: "KS26 live and
    // replay") always grants both → 'both' (shown as L+R in ks26.html)
    if ($o['course'] === 'KS26') {
        if (preg_match('/live\s*(?:and|&|\+|si|și)\s*replay|replay session for live subscription|sesiune de revizionare pentru cei care au participat live/i', $prod . ' ' . $o['nota']))
            $o['session'] = 'both';
        elseif (preg_match('/replay|reaudi|reluare/i', $prod . ' ' . $o['nota']))
            $o['session'] = 'replay';
        else
            $o['session'] = 'live';
    }
    // Group from product line first, then nota
    foreach ([$prod, $o['nota']] as $txt) {
        if (preg_match('/gr(?:upa|\.|\b)?\s*([0-9])/i', $txt, $m)) { $o['group'] = $m[1]; break; }
    }
    // Month (from nota / subject)
    if (preg_match('/\b(ianuarie|februarie|martie|aprilie|mai|iunie|iulie|august|septembrie|octombrie|noiembrie|decembrie|january|february|march|april|may|june|july|august|september|october|november|december)\b/i', $o['nota'].' '.$subj, $m))
        $o['month'] = strtolower($m[1]);
    return $o;
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
    list($hi, $sign, $foreign) = ncGreetSign($from_name);
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

// ── Greeting helper (shared by ncMakeDraft and auto-reply builders) ────────────
function ncGreetSign($from_name) {
    $foreign = !preg_match('/[ăâîșțĂÂÎȘȚ]/u', $from_name)
            && !preg_match('/\b(Mihai|Andrei|Ion|Dan|Ana|Ioana|Maria|Radu|Bogdan|Florin|Lucian|Claudia|Monica|Simona|Cristina|Daniela|Raluca|Sorin|Adrian|Ciprian|Stefan|Carmen|Florenta|Ramona|Dragos|Olga|Eugeniu|Anca|Mihaela|Sergiu|Neculai|Cristea|Mircea|Leonard|Catalin|Razvan|Gabriela|Adelina|Doina|Camelia|Apostol|Victoria|Emanuel|Doru|Tanase|Livadaru|Gutan|Spiridon|Bargau|Totu|Catalin|Ciprian|Dinu|Florenta)\b/iu', $from_name);
    $sign = $foreign ? "Best regards" : "Vă mulțumim!";
    $hi   = $foreign ? "Hi" : "Bună ziua,";
    return [$hi, $sign, $foreign];
}
function ncReSubj($s) { return preg_match('/^re:/i', $s) ? $s : 'Re: ' . $s; }

// ── Rudra-Șiva initiation: location detection + confirmation text ──────────────
// Rudra is the KS26 retreat's initiation add-on — only auto-file for someone who is already a
// retreat participant (has a ks26.csv row); otherwise fall through to a draft instead of creating
// a speculative/unpaid row.
function ncKs26HasEmail($email) {
    $em = strtolower(trim($email));
    if (!$em) return false;
    $f = __DIR__ . '/ks26.csv';
    if (!file_exists($f)) return false;
    $found = false;
    if (($fh = fopen($f, 'r')) !== false) {
        fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            if (strtolower(trim($row[1] ?? '')) === $em) { $found = true; break; }
        }
        fclose($fh);
    }
    return $found;
}
function ncDetectRudraLocation($text) {
    if (preg_match('/costine[sș]ti/i', $text)) return 'Costinesti';
    if (preg_match('/cluj/i',          $text)) return 'Cluj';
    if (preg_match('/bucure[sș]ti/i',  $text)) return 'Bucuresti';
    return '';
}
function ncRudraConfirmText($from_name, $loc) {
    list($hi, $sign, $foreign) = ncGreetSign($from_name);
    return $foreign
        ? "$hi\n\nConfirmed — Rudra-Șiva initiation, location: $loc.\n\n$sign"
        : "$hi\n\nConfirmăm înscrierea la inițierea Rudra-Șiva, locația $loc.\n\n$sign";
}

// ── KS26 live/replay confirmation ("live and replay" 100 RON / ~19 EUR combo) ──
// Language is decided from the words in the incoming message itself (not the sender's name),
// per the admin's instruction — someone with a foreign name can still write in Romanian.
function ncIsForeignText($text) {
    return !preg_match('/[ăâîșțĂÂÎȘȚ]/u', $text)
        && !preg_match('/\b(salut|bun[ăa]|mul[țt]umesc|v[ăa]\s*rog|doresc|reluare|confirmare|particip|[îi]nregistrat[ăa]|dumneavoastr[ăa]|ziua)\b/iu', $text);
}
function ncKs26FindByEmail($email) {
    $em = strtolower(trim($email));
    if (!$em) return null;
    $f = __DIR__ . '/ks26.csv';
    if (!file_exists($f)) return null;
    $found = null;
    if (($fh = fopen($f, 'r')) !== false) {
        fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            if (strtolower(trim($row[1] ?? '')) === $em) {
                $found = ['name' => trim($row[0] ?? ''), 'email' => trim($row[1] ?? ''),
                          'tax' => trim($row[2] ?? ''), 'session' => trim($row[3] ?? ''),
                          'notes' => trim($row[4] ?? ''), 'rudra' => trim($row[5] ?? '')];
                break;
            }
        }
        fclose($fh);
    }
    return $found;
}
// In-place session change by email (like the ks26_edit endpoint) — avoids ks26_upsert's
// email+session dedupe key creating a second row instead of upgrading the existing one.
function ncKs26SetSession($email, $newSession) {
    $em = strtolower(trim($email));
    $f = __DIR__ . '/ks26.csv';
    if (!$em || !file_exists($f)) return ['ok' => false];
    $rows = []; $idx = -1;
    if (($fh = fopen($f, 'r')) !== false) { fgetcsv($fh); while (($row = fgetcsv($fh)) !== false) $rows[] = $row; fclose($fh); }
    for ($i = 0; $i < count($rows); $i++) { if (strtolower(trim($rows[$i][1] ?? '')) === $em) { $idx = $i; break; } }
    if ($idx < 0) return ['ok' => false];
    $rows[$idx][3] = $newSession;
    $fh = fopen($f, 'w'); fputcsv($fh, ['name','email','tax','session','notes','rudra']);
    foreach ($rows as $r) fputcsv($fh, $r);
    fclose($fh);
    return ['ok' => true, 'action' => 'updated'];
}
function ncKs26ReplayConfirmText($from_name, $session, $foreign) {
    $hi    = $foreign ? "Hi" : "Bună ziua,";
    $sign  = $foreign ? "Best regards" : "Vă mulțumim!";
    $label = $session === 'both'
        ? ($foreign ? 'Live + Replay access' : 'acces Live + Reluare')
        : ($foreign ? 'Replay access' : 'acces la reluare');
    return $foreign
        ? "$hi\n\nConfirmed — $label for the Kashmir Shaivism 2026 Retreat. Please use the app to access the replay link.\n\n$sign"
        : "$hi\n\nConfirmăm — $label pentru Retragerea Kashmir Shaivism 2026. Vă rugăm folosiți aplicația pentru a accesa linkul de reluare.\n\n$sign";
}

// ── AMRITA SHAKTIPATA (Bucharest ceremony / "impulsionare") ────────────────────
// Mirrors mm_initiation.html's client-side checkEligibility() against mm_initiation.csv.
function ncAmritaEligibility($email, $name) {
    $csv = __DIR__ . '/mm_initiation.csv';
    $e = strtolower(trim($email));
    $n = strtolower(trim($name));
    $sets = ['Part 1' => [], 'Part 2' => [], 'Iasi' => [], 'Cluj' => []];
    if (file_exists($csv) && ($fh = fopen($csv, 'r')) !== false) {
        fgetcsv($fh); // header
        while (($row = fgetcsv($fh)) !== false) {
            $part = trim($row[2] ?? '');
            if (!isset($sets[$part])) continue;
            $re = strtolower(trim($row[1] ?? ''));
            $rn = strtolower(trim($row[0] ?? ''));
            if ($re) $sets[$part][$re] = true;
            if ($rn) $sets[$part][$rn] = true;
        }
        fclose($fh);
    }
    $has = function($part) use ($sets, $e, $n) {
        return ($e && isset($sets[$part][$e])) || ($n && isset($sets[$part][$n]));
    };
    $p1 = $has('Part 1'); $p2 = $has('Part 2');
    $ia = $has('Iasi');   $cl = $has('Cluj');
    if (!$p1 && !$p2) return ['eligible' => false, 'reason' => 'nici o aprofundare'];
    if ($p1 && $p2) {
        if ($ia && $cl) return ['eligible' => false, 'reason' => 'ambele impulsionari facute'];
        return ['eligible' => true, 'reason' => 'Part 1+2'];
    }
    if ($ia || $cl) return ['eligible' => false, 'reason' => 'lipseste aprofundarea a doua'];
    return ['eligible' => true, 'reason' => ($p1 ? 'Part 1' : 'Part 2')];
}
// Is this email/name already queued as a 'Bucuresti' (pending ceremony) row?
function ncAmritaAlreadyQueued($email, $name) {
    $csv = __DIR__ . '/mm_initiation.csv';
    if (!file_exists($csv)) return false;
    $e = strtolower(trim($email));
    $n = strtolower(trim($name));
    $found = false;
    if (($fh = fopen($csv, 'r')) !== false) {
        fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            if (trim($row[2] ?? '') !== 'Bucuresti') continue;
            if (($e && strtolower(trim($row[1] ?? '')) === $e) || ($n && strtolower(trim($row[0] ?? '')) === $n)) { $found = true; break; }
        }
        fclose($fh);
    }
    return $found;
}
function ncAmritaConfirmText($from_name) {
    list($hi, $sign, $foreign) = ncGreetSign($from_name);
    return $foreign
        ? "$hi\n\nConfirmed — see you at the ceremony.\n\n$sign"
        : "$hi\n\nConfirmăm participarea dumneavoastră.\n\n$sign";
}

// ── Payment-status lookup for "didn't receive course X" questions ──────────────
// $studentsByEmail / $ks26ByEmail are pre-loaded maps (see email_scan) to avoid refetching
// Airtable/ks26.csv per message when several 'q' emails are in the same scan.
function ncPaymentStatusFromMap($studentsByEmail, $ks26ByEmail, $email, $course) {
    $em = strtolower(trim($email));
    if (!$em || !$course) return null;
    if ($course === 'KS26') {
        if (isset($ks26ByEmail[$em])) return ['found' => true, 'course' => 'KS26', 'session' => $ks26ByEmail[$em]['session'], 'tax' => $ks26ByEmail[$em]['tax']];
        return ['found' => false, 'course' => 'KS26'];
    }
    if (!isset($studentsByEmail[$em])) return ['found' => false, 'course' => $course];
    $subs = json_decode($studentsByEmail[$em]['subscriptions'] ?? '{}', true) ?: [];
    if (isset($subs[$course])) {
        return ['found' => true, 'course' => $course, 'curr' => $subs[$course]['curr'] ?? '', 'next' => $subs[$course]['next'] ?? '', 'group' => $subs[$course]['G'] ?? ''];
    }
    return ['found' => true, 'course' => $course, 'curr' => null, 'next' => null, 'all_courses' => array_keys($subs)];
}
function ncPaymentCheckNote($pc, $course) {
    if (!$pc) return '';
    if (!$pc['found']) return "Nu găsim nicio plată înregistrată pentru $course la adresa dumneavoastră. Vă rugăm trimiteți dovada plății (order # / dată).";
    if ($course === 'KS26') return "Plata pentru KS26 este înregistrată (sesiune: " . ($pc['session'] ?: 'live') . ($pc['tax'] ? ", $pc[tax] RON" : '') . ").";
    if ($pc['curr'] === null) return "Nu găsim un abonament la $course — cursurile înregistrate la dumneavoastră: " . (empty($pc['all_courses']) ? '(niciunul)' : implode(', ', $pc['all_courses'])) . ".";
    $next = $pc['next'] ?: '(gol)';
    $curr = $pc['curr'] ?: '(gol)';
    return "Plata pentru $course este înregistrată — status curent: $curr, luna următoare: $next" . ($pc['group'] ? ", grupa G{$pc['group']}" : '') . ".";
}

// ── Shared writers (also used directly by ks26_upsert / csv_append cases below) ─
function ncKs26Upsert($name, $email, $tax, $session, $notes, $rudra) {
    $unm  = str_replace(["\n","\r"], '', trim($name));
    $uem  = strtolower(str_replace(["\n","\r"], '', trim($email)));
    $utax = str_replace(["\n","\r"], '', trim($tax));
    $uses = str_replace(["\n","\r"], '', trim($session)) ?: 'live';
    $unto = str_replace(["\n","\r"], '', trim($notes));
    $uru  = str_replace(["\n","\r"], '', trim($rudra));
    if (!$uem) return ['error' => 'Email required'];
    $uf = __DIR__ . '/ks26.csv';
    if (!file_exists($uf)) file_put_contents($uf, "name,email,tax,session,notes,rudra\n");
    $urows = []; $uidx = -1;
    if (($ufh = fopen($uf, 'r')) !== false) { fgetcsv($ufh); while (($ur=fgetcsv($ufh))!==false) $urows[]=$ur; fclose($ufh); }
    for ($i=0;$i<count($urows);$i++) {
        if (strtolower(trim($urows[$i][1]??''))===$uem && strtolower(trim($urows[$i][3]??''))===$uses) { $uidx=$i; break; }
    }
    if ($uidx>=0) {
        if ($unm) $urows[$uidx][0]=$unm; if ($utax) $urows[$uidx][2]=$utax; if ($unto) $urows[$uidx][4]=$unto;
        if ($uru !== '') $urows[$uidx][5]=$uru;
        $uact='updated';
    } else { $urows[]=[$unm,$uem,$utax,$uses,$unto,$uru]; $uact='created'; }
    $ufh=fopen($uf,'w'); fputcsv($ufh,['name','email','tax','session','notes','rudra']);
    foreach($urows as $ur) fputcsv($ufh,$ur); fclose($ufh);
    return ['ok'=>true,'action'=>$uact];
}
function ncMmInitiationAppend($name, $email, $part) {
    $name  = str_replace([',',"\n","\r"], '', trim($name));
    $email = str_replace([',',"\n","\r"], '', trim($email));
    $part  = str_replace([',',"\n","\r"], '', trim($part)) ?: 'Bucuresti';
    if (!$name) return ['error' => 'Name required'];
    $csv_file = __DIR__ . '/mm_initiation.csv';
    $line = $name . ',' . $email . ',' . $part . "\n";
    $ok = file_put_contents($csv_file, $line, FILE_APPEND | LOCK_EX);
    return $ok !== false ? ['ok' => true] : ['error' => 'Write failed'];
}
function ncSendAdmin($to, $subj, $msg) {
    if (!$to || !$subj || !$msg) return ['error' => 'to/subject/message required'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['error' => 'Invalid email'];
    $hdrs = ['Content-Type: text/plain; charset=UTF-8',
             'From: NicolaeCatrina <contact@nicolaecatrina.com>',
             'Reply-To: contact@nicolaecatrina.com'];
    $sent = wp_mail($to, $subj, $msg, $hdrs);
    if (!$sent) return ['error' => 'wp_mail failed'];
    $imap_base = '{mail.nicolaecatrina.com:993/imap/ssl/novalidate-cert}';
    $imbox = @imap_open($imap_base . 'INBOX', IMAP_USER, IMAP_PASS, 0, 1);
    $saved_folder = '';
    if ($imbox) {
        $fl = imap_list($imbox, $imap_base, '*') ?: [];
        foreach ($fl as $f) { if (stripos($f, 'sent') !== false) { $saved_folder = $f; break; } }
        if (!$saved_folder) $saved_folder = $imap_base . 'Sent Messages';
        $raw = "From: NicolaeCatrina <contact@nicolaecatrina.com>\r\n"
             . "To: $to\r\n" . "Subject: $subj\r\n" . "Date: " . date('r') . "\r\n"
             . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $msg;
        imap_append($imbox, $saved_folder, $raw, '\\Seen');
        imap_close($imbox);
    }
    return ['ok' => true, 'saved_to' => str_replace($imap_base, '', $saved_folder)];
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
        $subject = 'Codul tău de acces / Your access code';
        $message = "Salut $name,\n\nCodul tău de acces este: $code\n\nFolosește-l pentru a te autentifica la: https://www.nicolaecatrina.com/app/\n\nCodul este valabil până când soliciți unul nou.\n\n---\n\nHi $name,\n\nYour access code is: $code\n\nUse it to log in at: https://www.nicolaecatrina.com/app/\n\nThe code remains valid until you request a new one.";
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
        echo json_encode(ncMmInitiationAppend($body['name'] ?? '', $body['email'] ?? '', $body['part'] ?? 'Bucuresti'));
        break;

    case 'csv_delete':
        $name  = str_replace([',',"\n","\r"], '', trim($body['name']  ?? ''));
        $email = str_replace([',',"\n","\r"], '', trim($body['email'] ?? ''));
        $part  = str_replace([',',"\n","\r"], '', trim($body['part']  ?? ''));
        if (!$name) { echo json_encode(['error' => 'Name required']); break; }
        $csv_file = __DIR__ . '/mm_initiation.csv';
        $lines = file($csv_file, FILE_IGNORE_NEW_LINES);
        $target = $name . ',' . $email . ',' . $part;
        $filtered = array_filter($lines, function($l) use ($target) { return trim($l) !== $target; });
        $ok = file_put_contents($csv_file, implode("\n", $filtered) . "\n", LOCK_EX);
        echo $ok !== false ? json_encode(['ok' => true]) : json_encode(['error' => 'Write failed']);
        break;

    case 'email_scan':
        $mark_rd = !empty($body['mark_read']);
        $mbox = @imap_open(IMAP_HOST, IMAP_USER, IMAP_PASS, 0, 1);
        if (!$mbox) { echo json_encode(['error' => 'IMAP: ' . imap_last_error()]); break; }
        // Union of unread + manually-flagged — flagged is how the admin marks a message for a
        // later look, so it always deserves a pass too, not just brand-new unread mail.
        $unseenUids  = imap_search($mbox, 'UNSEEN', SE_UID) ?: [];
        $flaggedUids = imap_search($mbox, 'FLAGGED', SE_UID) ?: [];
        $uids = array_values(array_unique(array_merge($unseenUids, $flaggedUids)));
        rsort($uids);
        $uids = array_slice($uids, 0, 100);
        $cats = ['ks26' => [], 'monthly' => [], 'initiation' => [], 'q' => [], 'other' => [], 'auto' => []];
        // Lazily loaded once per scan, only if a 'q' email actually needs a payment check.
        $studentsByEmail = null; $ks26ByEmail = null;
        foreach ($uids as $uid) {
            $num  = imap_msgno($mbox, $uid);
            $flagged = in_array($uid, $flaggedUids);
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
            if (preg_match('/plat[ăa]\s*[îi]nregistrat[ăa]/iu', $subj)) {
                imap_setflag_full($mbox, (string)$num, '\\Seen');
                continue;
            }
            $dstr = date('d M Y, H:i', strtotime($hdr->date ?? 'now'));
            $struct = imap_fetchstructure($mbox, $num);
            $btxt = mb_substr(trim(ncGetBody($mbox, $num, $struct)), 0, 500);
            $tx   = strtolower($subj . ' ' . $btxt);
            $is_q = preg_match('/\?\s*$/', trim($subj))
                 || preg_match('/not\s+received|haven.t\s+received|didn.t\s+receive|still\s+wait|not\s+arriv|no.*recording|recording.*not|not.*access/i', $tx);
            if      (preg_match('/ks\s*26|kashmir|retreat\s*2026|reluare\s*ks|replay|reaudi|reluare/i', $tx))               $cat = 'ks26';
            elseif  (preg_match('/impulsionar|ini[tț]ier|initiation|ceremoni|mahamrityunjaya|rudra/i', $tx)) $cat = 'initiation';
            elseif  ($is_q && preg_match('/ytt|ttc|modul|yoga|tantr|lesson|class|record|session|link|access/i', $tx)) $cat = 'q';
            elseif  (preg_match('/ytt|ttc|modul\s*[123]|yoga\s*tantr|i\s*ching|alchimie|abonament|comand[ăa]\s*nou[ăa]|new order|order #/i', $tx)) $cat = 'monthly';
            else    $cat = 'other';
            $parsed = ncParseEmail($subj, $btxt, $fn, $fe, $re, $cat);
            // C. Other + payment confirm → reclassify for easier handling
            if ($cat === 'other' && ($parsed['is_payment'] || $parsed['is_order'])) {
                $cat = preg_match('/ks\s*26|kashmir|replay|reaudi|reluare/i', $tx) ? 'ks26' : 'monthly';
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
                    imap_clearflag_full($mbox, (string)$num, '\\Flagged');
                    continue;
                }
            }
            // D. Auto-answer: Rudra-Șiva initiation signup (location detected) → ks26.csv 'rudra'
            //    column, or AMRITA SHAKTIPATA / "impulsionare" ceremony signup (eligible) →
            //    mm_initiation.csv. Both send a confirmation email and clear the message —
            //    everything else (including ineligible/undetected cases) stays a draft below.
            $auto = null;
            $hasEmail = !empty($parsed['email']) && filter_var($parsed['email'], FILTER_VALIDATE_EMAIL);
            if ($cat === 'initiation') {
                if (preg_match('/rudra/i', $tx)) {
                    $parsed['sub'] = 'rudra';
                    $loc = ncDetectRudraLocation($subj . ' ' . $btxt);
                    $isParticipant = $hasEmail && ncKs26HasEmail($parsed['email']);
                    $parsed['rudra_location'] = $loc;
                    $parsed['rudra_is_participant'] = $isParticipant;
                    if ($loc && $isParticipant) {
                        $up = ncKs26Upsert($fn ?: $parsed['name'], $parsed['email'], '', 'live', '', $loc);
                        if (!empty($up['ok'])) {
                            $sr = ncSendAdmin($parsed['email'], ncReSubj($subj), ncRudraConfirmText($fn, $loc));
                            $auto = ['type' => 'rudra', 'location' => $loc, 'csv_action' => $up['action'], 'email_sent' => !empty($sr['ok'])];
                        }
                    }
                } elseif (preg_match('/mahamrityunjaya|mantra/i', $tx)) {
                    $parsed['sub'] = 'other'; // different event/CSV entirely — needs a human answer
                } else {
                    $parsed['sub'] = 'amrita';
                    $elig = ncAmritaEligibility($parsed['email'], $fn ?: $parsed['name']);
                    $parsed['eligibility'] = $elig;
                    if (!empty($elig['eligible']) && $hasEmail) {
                        if (ncAmritaAlreadyQueued($parsed['email'], $fn ?: $parsed['name'])) {
                            $auto = ['type' => 'amrita_dup'];
                        } else {
                            $ap = ncMmInitiationAppend($fn ?: $parsed['name'], $parsed['email'], 'Bucuresti');
                            if (!empty($ap['ok'])) {
                                $sr = ncSendAdmin($parsed['email'], ncReSubj($subj), ncAmritaConfirmText($fn));
                                $auto = ['type' => 'amrita', 'csv_action' => 'appended', 'email_sent' => !empty($sr['ok'])];
                            }
                        }
                    }
                }
            } elseif ($cat === 'ks26' && $hasEmail
                   && in_array($parsed['session'], ['both', 'replay'], true)
                   && ($parsed['is_order'] || preg_match('/confirm/i', $tx)
                       || preg_match('/\d+(?:[.,]\d{1,2})?\s*(?:lei|ron|eur|euro|€)/i', $tx))) {
                // KS26 "live and replay" combo (L+R) — someone already registered 'live' asking
                // to also get the replay → upgrade in place to 'both' so the live row is kept,
                // not duplicated. Someone not registered at all yet → filed straight into the
                // L+R/replay-only session per ncParseEmail's detection ($parsed['session']).
                // Trigger is deliberately not tied to an exact "100 RON"/"19 EUR" string — real
                // orders (is_order) or any confirm/amount wording are enough; a bare question
                // about replay availability (no order, no confirm, no amount) still falls to Q/draft.
                $parsed['sub'] = 'ks26_replay';
                $foreignMsg = ncIsForeignText($subj . ' ' . $btxt);
                $existing = ncKs26FindByEmail($parsed['email']);
                if ($existing) {
                    $curSession = $existing['session'] ?: 'live';
                    if ($curSession === 'live') {
                        $up = ncKs26SetSession($parsed['email'], 'both');
                        if (!empty($up['ok'])) {
                            $sr = ncSendAdmin($parsed['email'], ncReSubj($subj), ncKs26ReplayConfirmText($fn, 'both', $foreignMsg));
                            $auto = ['type' => 'ks26_replay', 'session' => 'both', 'csv_action' => 'upgraded', 'email_sent' => !empty($sr['ok'])];
                        }
                    } else {
                        // Already replay or both — nothing to change, just confirm.
                        $sr = ncSendAdmin($parsed['email'], ncReSubj($subj), ncKs26ReplayConfirmText($fn, $curSession, $foreignMsg));
                        $auto = ['type' => 'ks26_replay', 'session' => $curSession, 'csv_action' => 'none', 'email_sent' => !empty($sr['ok'])];
                    }
                } else {
                    // No prior row — file straight into whichever session ncParseEmail detected
                    // ('both' for the L+R combo phrasing, 'replay' for a plain replay mention).
                    $newSession = $parsed['session'];
                    $amt = $parsed['amount'] ?: '100';
                    $up = ncKs26Upsert($fn ?: $parsed['name'], $parsed['email'], $amt, $newSession, '', '');
                    if (!empty($up['ok'])) {
                        $sr = ncSendAdmin($parsed['email'], ncReSubj($subj), ncKs26ReplayConfirmText($fn, $newSession, $foreignMsg));
                        $auto = ['type' => 'ks26_replay', 'session' => $newSession, 'csv_action' => $up['action'], 'email_sent' => !empty($sr['ok'])];
                    }
                }
            }
            if ($auto && !empty($auto['email_sent'])) {
                imap_setflag_full($mbox, (string)$num, '\\Seen');
                imap_clearflag_full($mbox, (string)$num, '\\Flagged');
                $cats['auto'][] = ['num' => $num, 'from_name' => $fn, 'from_email' => $fe, 'reply_to' => $re,
                                   'subject' => $subj, 'date' => $dstr, 'cat' => 'auto',
                                   'auto' => array_merge($auto, ['orig_cat' => $cat])];
                continue;
            }
            // E. Payment-status check for "didn't receive course X" questions — surfaced in the
            //    draft so the admin doesn't have to look it up by hand; still sent manually.
            if ($cat === 'q') {
                $qcourse = $parsed['course'] ?: (preg_match('/ks\s*26|kashmir/i', $tx) ? 'KS26' : '');
                if ($qcourse) {
                    if ($studentsByEmail === null) {
                        $studentsByEmail = [];
                        $sall = atAll("$TBL_S?fields[]=fldTKQVFs3vrMrwWA&fields[]=fldUvZSA5jTlsLQFH");
                        foreach (($sall['records'] ?? []) as $rec) {
                            $sem = strtolower(trim($rec['fields']['email'] ?? ''));
                            if ($sem) $studentsByEmail[$sem] = $rec['fields'];
                        }
                        $ks26ByEmail = [];
                        $k26f = __DIR__ . '/ks26.csv';
                        if (file_exists($k26f) && ($k26fh = fopen($k26f, 'r')) !== false) {
                            fgetcsv($k26fh);
                            while (($k26r = fgetcsv($k26fh)) !== false) {
                                $k26em = strtolower(trim($k26r[1] ?? ''));
                                if ($k26em) $ks26ByEmail[$k26em] = ['session' => trim($k26r[3] ?? 'live'), 'tax' => trim($k26r[2] ?? '')];
                            }
                            fclose($k26fh);
                        }
                    }
                    $parsed['payment_check'] = ncPaymentStatusFromMap($studentsByEmail, $ks26ByEmail, $parsed['email'], $qcourse);
                }
            }
            $draft = ncMakeDraft($cat, $fn, $re, $subj, $btxt);
            if ($cat === 'q' && !empty($parsed['payment_check'])) {
                $draft = str_replace(
                    ['[Please describe the issue and provide access/link here]', '[Descrieți situația și oferiți link/acces]'],
                    ncPaymentCheckNote($parsed['payment_check'], $qcourse), $draft);
            }
            $cats[$cat][] = ['num' => $num, 'from_name' => $fn, 'from_email' => $fe, 'reply_to' => $re,
                             'subject' => $subj, 'date' => $dstr, 'seen' => $seen, 'flagged' => $flagged, 'body' => $btxt,
                             'draft' => $draft, 'cat' => $cat,
                             'parsed' => $parsed, 'auto_action' => $auto_action];
            if ($mark_rd && !$seen) imap_setflag_full($mbox, (string)$num, '\\Seen');
        }
        imap_close($mbox);
        echo json_encode(['ok' => true, 'total' => count($uids), 'cats' => $cats]);
        break;

    case 'email_search':
        $kw   = trim($body['keyword'] ?? '');
        $max  = intval($body['max'] ?? 50);
        if (!$kw) { echo json_encode(['error' => 'keyword required']); break; }
        $mbox = @imap_open(IMAP_HOST, IMAP_USER, IMAP_PASS, 0, 1);
        if (!$mbox) { echo json_encode(['error' => 'IMAP: ' . imap_last_error()]); break; }
        $since = trim($body['since'] ?? '');
        $criteria = 'SINCE "01-Jun-2026" TEXT "' . addslashes($kw) . '"';
        if ($since) $criteria = 'SINCE "' . addslashes($since) . '" TEXT "' . addslashes($kw) . '"';
        $nums = imap_search($mbox, $criteria) ?: [];
        rsort($nums);
        $nums = array_slice($nums, 0, $max);
        $results = [];
        foreach ($nums as $num) {
            $hdr  = imap_headerinfo($mbox, $num);
            $ov   = imap_fetch_overview($mbox, (string)$num, 0);
            $from = $hdr->from[0] ?? null;
            $repl = $hdr->reply_to[0] ?? null;
            $fe   = $from ? ($from->mailbox . '@' . ($from->host ?? '')) : '';
            $re   = $repl ? ($repl->mailbox . '@' . ($repl->host ?? '')) : $fe;
            $fn   = $from ? ncDecodeHeader($from->personal ?? '') : '';
            $subj = ncDecodeHeader($hdr->subject ?? '(no subject)');
            $dstr = date('d M Y, H:i', strtotime($hdr->date ?? 'now'));
            $struct = imap_fetchstructure($mbox, $num);
            $btxt = mb_substr(trim(ncGetBody($mbox, $num, $struct)), 0, 800);
            $results[] = ['num' => $num, 'from_name' => $fn, 'from_email' => $fe,
                          'reply_to' => $re, 'subject' => $subj, 'date' => $dstr, 'body' => $btxt,
                          'seen' => !empty($ov[0]->seen), 'flagged' => !empty($ov[0]->flagged)];
        }
        imap_close($mbox);
        echo json_encode(['ok' => true, 'total' => count($nums), 'results' => $results]);
        break;

    case 'flagged_scan':
        // Read-only: every UNSEEN or FLAGGED message since a date, any subject. For manual
        // review sweeps that aren't limited to the WooCommerce order pattern (see orders_scan).
        $since = trim($body['since'] ?? '20-May-2026');
        $max_f = intval($body['max'] ?? 200);
        $mbox  = @imap_open(IMAP_HOST, IMAP_USER, IMAP_PASS, OP_READONLY, 1);
        if (!$mbox) { echo json_encode(['error' => 'IMAP: ' . imap_last_error()]); break; }
        $unseenUids  = imap_search($mbox, 'UNSEEN SINCE "' . addslashes($since) . '"', SE_UID) ?: [];
        $flaggedUids = imap_search($mbox, 'FLAGGED SINCE "' . addslashes($since) . '"', SE_UID) ?: [];
        $uids = array_slice(array_values(array_unique(array_merge($unseenUids, $flaggedUids))), 0, $max_f);
        $results = [];
        foreach ($uids as $uid) {
            $no   = imap_msgno($mbox, $uid);
            $hdr  = imap_headerinfo($mbox, $no);
            $from = $hdr->from[0] ?? null;
            $fe   = $from ? ($from->mailbox . '@' . ($from->host ?? '')) : '';
            $fn   = $from ? ncDecodeHeader($from->personal ?? '') : '';
            $subj = ncDecodeHeader($hdr->subject ?? '(no subject)');
            // NETOPIA "Plată înregistrată" payment-confirmation emails — treated as already read,
            // never worth surfacing in a review sweep (daily-orders' orders_mark clears them
            // alongside their order; read-only here so we just exclude, can't set \Seen).
            if (preg_match('/^\s*plat[ăa]\s*[îi]nregistrat[ăa]/iu', $subj)) continue;
            $dstr = date('d M Y, H:i', strtotime($hdr->date ?? 'now'));
            $btxt = mb_substr(trim(ncGetBody($mbox, $no, imap_fetchstructure($mbox, $no))), 0, 500);
            $results[] = ['uid' => $uid, 'from_name' => $fn, 'from_email' => $fe, 'subject' => $subj,
                          'date' => $dstr, 'body' => $btxt,
                          'unread' => in_array($uid, $unseenUids), 'flagged' => in_array($uid, $flaggedUids)];
        }
        imap_close($mbox); // OP_READONLY — no flags touched
        echo json_encode(['ok' => true, 'since' => $since, 'count' => count($results), 'results' => $results]);
        break;

    case 'sent_scan':
        // Scan INBOX.Sent folder; returns To: recipients + subject + date
        $since_raw = trim($body['since'] ?? '19-Jun-2026');
        $max_s     = intval($body['max'] ?? 100);
        $kw_s      = trim($body['keyword'] ?? '');
        $imap_base = '{mail.nicolaecatrina.com:993/imap/ssl/novalidate-cert}';
        $mbox = @imap_open($imap_base . 'INBOX.Sent', IMAP_USER, IMAP_PASS, OP_READONLY, 1);
        if (!$mbox) { echo json_encode(['error' => 'IMAP Sent: ' . imap_last_error()]); break; }
        $crit = 'SINCE "' . addslashes($since_raw) . '"';
        if ($kw_s) $crit .= ' TEXT "' . addslashes($kw_s) . '"';
        $nums = imap_search($mbox, $crit) ?: [];
        rsort($nums);
        $nums = array_slice($nums, 0, $max_s);
        $results = [];
        foreach ($nums as $num) {
            $hdr  = imap_headerinfo($mbox, $num);
            $to_list = [];
            foreach ((array)($hdr->to ?? []) as $t) {
                $te = ($t->mailbox ?? '') . '@' . ($t->host ?? '');
                $tn = ncDecodeHeader($t->personal ?? '');
                $to_list[] = ['name' => $tn, 'email' => $te];
            }
            $subj = ncDecodeHeader($hdr->subject ?? '(no subject)');
            $dstr = date('d M Y, H:i', strtotime($hdr->date ?? 'now'));
            $struct = imap_fetchstructure($mbox, $num);
            $btxt = mb_substr(trim(ncGetBody($mbox, $num, $struct)), 0, 400);
            $results[] = ['num' => $num, 'to' => $to_list, 'subject' => $subj, 'date' => $dstr, 'body' => $btxt];
        }
        imap_close($mbox);
        echo json_encode(['ok' => true, 'total' => count($results), 'results' => $results]);
        break;

    case 'orders_scan':
        // Read-only scan of unread WooCommerce orders + Airtable duplicate check. Never marks \Seen.
        $days  = max(1, intval($body['days'] ?? 2));
        $since = date('d-M-Y', strtotime("-" . ($days - 1) . " days"));
        $mbox  = @imap_open(IMAP_HOST, IMAP_USER, IMAP_PASS, OP_READONLY, 1);
        if (!$mbox) { echo json_encode(['error' => 'IMAP: ' . imap_last_error()]); break; }
        // Unread orders + manually-flagged ones (flagged = held for later review, still worth a pass)
        $unseenUids  = imap_search($mbox, 'UNSEEN SINCE "' . $since . '"', SE_UID) ?: [];
        $flaggedUids = imap_search($mbox, 'FLAGGED SINCE "' . $since . '"', SE_UID) ?: [];
        $uids = array_values(array_unique(array_merge($unseenUids, $flaggedUids)));
        // Load students once for dup-check
        $all = atAll("$TBL_S?" . 'fields[]=fldTKQVFs3vrMrwWA&fields[]=fld4Lx0LL4sfYOyy7&fields[]=fldUvZSA5jTlsLQFH');
        $byEmail = [];
        foreach (($all['records'] ?? []) as $rec) {
            $em = strtolower(trim($rec['fields']['email'] ?? ''));
            if ($em) $byEmail[$em] = $rec['fields'];
        }
        // Load ks26.csv once for KS26 dup-check (email+session pair already recorded)
        $ks26Seen = [];
        $ks26f = __DIR__ . '/ks26.csv';
        if (file_exists($ks26f) && ($ks26fh = fopen($ks26f, 'r')) !== false) {
            fgetcsv($ks26fh);
            while (($ks26r = fgetcsv($ks26fh)) !== false) {
                $ks26em = strtolower(trim($ks26r[1] ?? ''));
                $ks26se = strtolower(trim($ks26r[3] ?? 'live')) ?: 'live';
                if ($ks26em) $ks26Seen[$ks26em . '|' . $ks26se] = true;
            }
            fclose($ks26fh);
        }
        $orders = [];
        foreach ($uids as $uid) {
            $no   = imap_msgno($mbox, $uid);
            $hdr  = imap_headerinfo($mbox, $no);
            $subj = ncDecodeHeader($hdr->subject ?? '');
            if (!preg_match('/^\s*(Comand[ăa] nou[ăa] de la client|New order)/iu', $subj)) continue;
            $btxt = ncGetBody($mbox, $no, imap_fetchstructure($mbox, $no));
            $p = ncParseOrder($subj, $btxt);
            $p['uid']     = $uid;
            $p['flagged'] = in_array($uid, $flaggedUids);
            $p['date'] = date('Y-m-d H:i', strtotime($hdr->date ?? 'now'));
            // Course not detected from product line/nota (blank nota, unrecognised wording, etc.)
            // — fall back to the student's existing Airtable subscription, since a renewal payment
            // almost always matches whatever course they're already enrolled in. Only safe when
            // they have exactly one course on file; with more than one, surface the candidates
            // instead of guessing (the fee/tax amount + nota month wording help pick the right one
            // by hand — ncParseOrder already pulls both into $p['total']/$p['nota']/$p['month']).
            if (!$p['course']) {
                $f = $byEmail[strtolower($p['email'])] ?? null;
                if ($f) {
                    $subs = json_decode($f['subscriptions'] ?? '{}', true) ?: [];
                    $courseKeys = array_keys($subs);
                    if (count($courseKeys) === 1) {
                        $p['course'] = $courseKeys[0];
                        $p['course_source'] = 'inferred_from_student';
                        if (!$p['group'] && !empty($subs[$p['course']]['G'])) $p['group'] = $subs[$p['course']]['G'];
                    } elseif (count($courseKeys) > 1) {
                        $p['course_candidates'] = $courseKeys;
                    }
                }
            }
            // Duplicate / already-paid check
            if ($p['course'] === 'KS26') {
                $key = strtolower($p['email']) . '|' . ($p['session'] ?: 'live');
                $dup = ['student_exists' => isset($ks26Seen[$key]), 'already_paid' => isset($ks26Seen[$key])];
            } else {
                $f = $byEmail[strtolower($p['email'])] ?? null;
                if ($f) {
                    $subs = json_decode($f['subscriptions'] ?? '{}', true) ?: [];
                    $e = $subs[$p['course']] ?? null;
                    $dup = ['student_exists' => true,
                            'has_course'  => $e !== null,
                            'curr'        => $e['curr'] ?? null,
                            'next'        => $e['next'] ?? null,
                            'group'       => $e['G'] ?? null,
                            'already_paid'=> $e !== null && !empty($e['next']) && $e['next'] !== 'S'];
                } else {
                    $dup = ['student_exists' => false];
                }
            }
            $p['check'] = $dup;
            $orders[] = $p;
        }
        imap_close($mbox); // OP_READONLY — no flags touched
        echo json_encode(['ok' => true, 'since' => $since, 'count' => count($orders), 'orders' => $orders],
                         JSON_UNESCAPED_UNICODE);
        break;

    case 'orders_mark':
        // Mark order emails + matching NETOPIA confirmations + failed orders as read.
        // Input: {names:[buyer names], mark_failed:bool}
        $names = array_map(function($n){
            $n = @iconv('UTF-8','ASCII//TRANSLIT', $n);
            $n = strtolower(preg_replace('/[^a-z ]/', ' ', strtolower($n)));
            return trim(preg_replace('/\s+/', ' ', $n));
        }, (array)($body['names'] ?? []));
        $nameset    = array_fill_keys(array_filter($names), 1);
        $markFailed = !empty($body['mark_failed']);
        $mbox = @imap_open(IMAP_HOST, IMAP_USER, IMAP_PASS, 0, 1); // read-write
        if (!$mbox) { echo json_encode(['error' => 'IMAP: ' . imap_last_error()]); break; }
        // Unread + flagged — flagged copies get unflagged too once processed
        $unseenUids  = imap_search($mbox, 'UNSEEN', SE_UID) ?: [];
        $flaggedUids = imap_search($mbox, 'FLAGGED', SE_UID) ?: [];
        $uids = array_values(array_unique(array_merge($unseenUids, $flaggedUids)));
        $r = ['orders'=>0, 'confirmations'=>0, 'failed'=>0];
        $done = function($mbox, $uid) {
            imap_setflag_full($mbox, (string)$uid, "\\Seen", ST_UID);
            imap_clearflag_full($mbox, (string)$uid, "\\Flagged", ST_UID);
        };
        foreach ($uids as $uid) {
            $no   = imap_msgno($mbox, $uid);
            $subj = ncDecodeHeader(imap_headerinfo($mbox, $no)->subject ?? '');
            if (preg_match('/^\s*(Comand[ăa] nou[ăa] de la client|New order)/iu', $subj)) {
                $obtxt = ncGetBody($mbox, $no, imap_fetchstructure($mbox, $no));
                $obuyer = ncParseOrder($subj, $obtxt)['name'];
                $obn = trim(preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^a-z ]/', ' ',
                        strtolower(@iconv('UTF-8','ASCII//TRANSLIT', $obuyer))))));
                if ($obn && isset($nameset[$obn])) {
                    $done($mbox, $uid); $r['orders']++;
                }
                continue;
            }
            if ($markFailed && preg_match('/comand[ăa]\s+e[șs]uat[ăa]|failed order/iu', $subj)) {
                $done($mbox, $uid); $r['failed']++; continue;
            }
            if (preg_match('/plat[ăa]\s+[îi]nregistrat[ăa]\s*-\s*self awakening/iu', $subj)) {
                $flat = preg_replace('/\s+/', ' ', ncGetBody($mbox, $no, imap_fetchstructure($mbox, $no)));
                $buyer = '';
                if (preg_match('/Buyer.?s Name:\s*(.+?)\s+Transaction ID/iu', $flat, $m)) $buyer = $m[1];
                elseif (preg_match('/^\s*(.+?)\s+ID tranzac/iu', $flat, $m)) $buyer = $m[1];
                $bn = trim(preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^a-z ]/', ' ',
                        strtolower(@iconv('UTF-8','ASCII//TRANSLIT', $buyer))))));
                if ($bn && isset($nameset[$bn])) {
                    $done($mbox, $uid); $r['confirmations']++;
                }
            }
        }
        imap_close($mbox);
        echo json_encode(['ok' => true, 'marked' => $r]);
        break;

    case 'send_admin':
        echo json_encode(ncSendAdmin(trim($body['to'] ?? ''), trim($body['subject'] ?? ''), trim($body['message'] ?? '')));
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
        echo json_encode(ncKs26Upsert($body['name'] ?? '', $body['email'] ?? '', $body['tax'] ?? '', $body['session'] ?? 'live', $body['notes'] ?? '', $body['rudra'] ?? ''));
        break;

    case 'ks26_edit':
        $oe  = strtolower(str_replace(["\n","\r"], '', trim($body['orig_email'] ?? '')));
        $ose = strtolower(str_replace(["\n","\r"], '', trim($body['orig_session'] ?? '')));
        $nm  = str_replace(["\n","\r"], '', trim($body['name']    ?? ''));
        $ne  = strtolower(str_replace(["\n","\r"], '', trim($body['email']  ?? '')));
        $nt  = str_replace(["\n","\r"], '', trim($body['tax']     ?? ''));
        $nse = str_replace(["\n","\r"], '', trim($body['session'] ?? ''));
        $nno = str_replace(["\n","\r"], '', trim($body['notes']   ?? ''));
        if (!$oe) { echo json_encode(['error' => 'orig_email required']); break; }
        $ef = __DIR__ . '/ks26.csv';
        if (!file_exists($ef)) { echo json_encode(['error' => 'File not found']); break; }
        $er = []; $ei = -1;
        if (($efh = fopen($ef,'r')) !== false) { fgetcsv($efh); while (($row=fgetcsv($efh))!==false) $er[]=$row; fclose($efh); }
        // Same email can have separate rows per session — when orig_session is given, match on
        // both so editing one row (e.g. the 'live' row) never touches a sibling 'both'/'replay' row.
        for ($i=0;$i<count($er);$i++) {
            if (strtolower(trim($er[$i][1]??'')) !== $oe) continue;
            if ($ose !== '' && strtolower(trim($er[$i][3]??'')) !== $ose) continue;
            $ei = $i; break;
        }
        $nru = str_replace(["\n","\r"], '', trim($body['rudra'] ?? "\x00"));
        if ($ei < 0) { echo json_encode(['error' => 'Record not found']); break; }
        if ($nm  !== '') $er[$ei][0] = $nm;
        if ($ne  !== '') $er[$ei][1] = $ne;
        $er[$ei][2] = $nt;
        if ($nse !== '') $er[$ei][3] = $nse;
        $er[$ei][4] = $nno;
        if ($nru !== "\x00") $er[$ei][5] = $nru;
        $efh = fopen($ef,'w'); fputcsv($efh,['name','email','tax','session','notes','rudra']);
        foreach($er as $row) fputcsv($efh,$row); fclose($efh);
        echo json_encode(['ok' => true]);
        break;

    case 'rudra_set_location':
        $rle = strtolower(str_replace(["\n","\r"], '', trim($body['email']    ?? '')));
        $rll = str_replace(["\n","\r"], '', trim($body['location'] ?? ''));
        if (!$rle) { echo json_encode(['error' => 'email required']); break; }
        if (!in_array($rll, ['Bucuresti', 'Cluj', 'Costinesti', ''])) { echo json_encode(['error' => 'Invalid location']); break; }
        $rlf = __DIR__ . '/ks26.csv';
        if (!file_exists($rlf)) { echo json_encode(['error' => 'Not enrolled in KS26']); break; }
        $rlrows = []; $rlidx = -1;
        if (($rlfh = fopen($rlf, 'r')) !== false) { fgetcsv($rlfh); while (($rlr = fgetcsv($rlfh)) !== false) $rlrows[] = $rlr; fclose($rlfh); }
        for ($i = 0; $i < count($rlrows); $i++) { if (strtolower(trim($rlrows[$i][1] ?? '')) === $rle) { $rlidx = $i; break; } }
        if ($rlidx < 0) { echo json_encode(['error' => 'Not enrolled in KS26']); break; }
        $rlrows[$rlidx][5] = $rll;
        $rlfh = fopen($rlf, 'w'); fputcsv($rlfh, ['name','email','tax','session','notes','rudra']);
        foreach ($rlrows as $rlr) fputcsv($rlfh, $rlr); fclose($rlfh);
        echo json_encode(['ok' => true, 'location' => $rll]);
        break;

    case 'email_flag':
        $useUid   = isset($body['uid']);
        $id       = $useUid ? intval($body['uid']) : intval($body['num'] ?? 0);
        $flag     = !empty($body['flag']);
        $markSeen = !empty($body['mark_seen']);
        if (!$id) { echo json_encode(['error' => 'num or uid required']); break; }
        $mbox = @imap_open(IMAP_HOST, IMAP_USER, IMAP_PASS, 0, 1);
        if (!$mbox) { echo json_encode(['error' => 'IMAP: ' . imap_last_error()]); break; }
        $opt = $useUid ? ST_UID : 0;
        if ($flag) imap_setflag_full($mbox,   (string)$id, '\\Flagged', $opt);
        else       imap_clearflag_full($mbox, (string)$id, '\\Flagged', $opt);
        if ($markSeen) imap_setflag_full($mbox, (string)$id, '\\Seen', $opt);
        imap_close($mbox);
        echo json_encode(['ok' => true, 'flagged' => $flag, 'seen' => $markSeen]);
        break;

    case 'ks26_read':
        $f = __DIR__ . '/ks26.csv';
        if (!file_exists($f)) { echo json_encode(['rows' => []]); break; }
        $rows = [];
        if (($fh = fopen($f, 'r')) !== false) {
            fgetcsv($fh); // skip header
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 4) continue;
                $rows[] = ['name' => trim($row[0]), 'email' => trim($row[1]), 'tax' => trim($row[2]), 'session' => trim($row[3]), 'notes' => trim($row[4] ?? ''), 'rudra' => trim($row[5] ?? '')];
            }
            fclose($fh);
        }
        echo json_encode(['rows' => $rows]);
        break;

    case 'ks26_delete':
        $de = strtolower(str_replace(["\n","\r"], '', trim($body['email'] ?? '')));
        $dse = strtolower(str_replace(["\n","\r"], '', trim($body['session'] ?? '')));
        if (!$de) { echo json_encode(['error' => 'email required']); break; }
        $df = __DIR__ . '/ks26.csv';
        if (!file_exists($df)) { echo json_encode(['error' => 'File not found']); break; }
        $drows = []; $dfound = false; $ddeleted = false;
        if (($dfh = fopen($df,'r')) !== false) { fgetcsv($dfh); while (($dr=fgetcsv($dfh))!==false) $drows[]=$dr; fclose($dfh); }
        // Same email can legitimately have separate rows per session (live/replay/both) —
        // only delete the row matching both email AND session so removing one doesn't wipe the rest.
        $drows = array_filter($drows, function($r) use ($de, $dse, &$dfound, &$ddeleted) {
            if (strtolower(trim($r[1]??'')) !== $de) return true;
            $dfound = true;
            if ($dse !== '' && strtolower(trim($r[3]??'')) !== $dse) return true;
            $ddeleted = true;
            return false;
        });
        if (!$dfound) { echo json_encode(['error' => 'Record not found']); break; }
        if (!$ddeleted) { echo json_encode(['error' => 'No row matching that email + session']); break; }
        $dfh = fopen($df,'w'); fputcsv($dfh,['name','email','tax','session','notes','rudra']);
        foreach($drows as $dr) fputcsv($dfh,$dr); fclose($dfh);
        echo json_encode(['ok' => true]);
        break;

    case 'ks26_append':
        $name    = str_replace(["\n","\r"], '', trim($body['name']    ?? ''));
        $email   = str_replace(["\n","\r"], '', trim($body['email']   ?? ''));
        $tax     = str_replace(["\n","\r"], '', trim($body['tax']     ?? ''));
        $session = str_replace(["\n","\r"], '', trim($body['session'] ?? 'live'));
        $notes   = str_replace(["\n","\r"], '', trim($body['notes']   ?? ''));
        $rudra   = str_replace(["\n","\r"], '', trim($body['rudra']   ?? ''));
        if (!$name && !$email) { echo json_encode(['error' => 'Name or email required']); break; }
        $f = __DIR__ . '/ks26.csv';
        if (!file_exists($f)) file_put_contents($f, "name,email,tax,session,notes,rudra\n");
        $fh = fopen($f, 'a');
        if (!$fh) { echo json_encode(['error' => 'Write failed']); break; }
        fputcsv($fh, [$name, $email, $tax, $session, $notes, $rudra]);
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
                $tax   = trim($row[2] ?? '');
                $sess  = strtolower(trim($row[3] ?? 'live')) ?: 'live';
                $rudra = trim($row[5] ?? '');
                // 'both' means L+R on a single row — expand into both flags so the student
                // portal's hasLive/hasReplay checks (which only look for 'live'/'replay') see it.
                $sessFlags = $sess === 'both' ? ['live', 'replay'] : [$sess];
                if ($email && strpos($email, '@') !== false) {
                    if (!isset($enrollments[$email])) $enrollments[$email] = ['sessions' => [], 'rudra' => '', 'tax' => $tax];
                    foreach ($sessFlags as $sf) {
                        if (!in_array($sf, $enrollments[$email]['sessions'])) $enrollments[$email]['sessions'][] = $sf;
                    }
                    if ($rudra) $enrollments[$email]['rudra'] = $rudra;
                    if ($tax) $enrollments[$email]['tax'] = $tax;
                }
            }
            fclose($fh);
        }
        echo json_encode(['enrollments' => $enrollments ?: new stdClass()]);
        break;

    default:
        echo json_encode(array('error' => 'unknown action'));
}