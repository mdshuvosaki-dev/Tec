<?php
/**
 * Tech BD Bot Engine (PHP) — Firestore REST + Telegram Bot API
 *
 * কাজ (bot.py এর মতোই):
 *   Withdraw নোটিফিকেশন + চ্যানেল বাটন, /start + Refer bonus, ফ্রেন্ড মেসেজ/কল ping,
 *   Telegram লিংক (tgReq), Admin panel Broadcast/Test (botJobs), Leaderboard, botStatus heartbeat
 *
 * চালানো:
 *   ১) Cron (সবচেয়ে ভালো):  * * * * *  php /পাথ/bot.php
 *   ২) Cron URL:            https://আপনার-সাইট/bot.php?key=WEB_SECRET   (নিচে WEB_SECRET সেট করুন)
 *
 * দরকার: PHP 7.4+ (curl, openssl, json এক্সটেনশন)
 * Firebase key: serviceAccountKey.json ফাইল এই ফাইলের পাশে রাখুন (ওয়েব থেকে খোলা যায় এমন ফোল্ডারে নয়, সম্ভব হলে বাইরে)
 *               অথবা ENV: FIREBASE_KEY_JSON
 */

// ───────────── CONFIG ─────────────
$CFG = [
    'KEY_FILE'     => __DIR__ . '/serviceAccountKey.json',
    'KEY_INLINE'   => '',      // চাইলে JSON এর পুরো লেখা এখানে বসাতে পারেন (শুধু প্রাইভেট হোস্টে!)
    'LOOP_SECONDS' => 55,      // প্রতি মিনিটে cron চালালে 55; একবার চালিয়ে বন্ধ করতে 0
    'WEB_SECRET'   => '',      // ব্রাউজার/URL দিয়ে চালাতে চাইলে একটা লম্বা গোপন লেখা দিন
    'CHANNEL'      => '-1004408557794',
];
if (getenv('LOOP_SECONDS') !== false) $CFG['LOOP_SECONDS'] = (int)getenv('LOOP_SECONDS');
if (getenv('WEB_SECRET')) $CFG['WEB_SECRET'] = getenv('WEB_SECRET');

const LEASE_S    = 90;
const SCAN_EVERY = 5;
const LB_EVERY   = 900;

date_default_timezone_set('Asia/Dhaka');
@set_time_limit(0);
@ignore_user_abort(true);
mb_internal_encoding('UTF-8');

$IS_CLI = (PHP_SAPI === 'cli');
if (!$IS_CLI) {
    header('Content-Type: text/plain; charset=utf-8');
    $k = $_GET['key'] ?? '';
    if ($CFG['WEB_SECRET'] === '' || !hash_equals($CFG['WEB_SECRET'], (string)$k)) { http_response_code(403); exit("forbidden\n"); }
}

$ME = substr(bin2hex(random_bytes(6)), 0, 8);
$STARTED = (int)(microtime(true) * 1000);
$S = [
    'tok' => '', 'chan' => $CFG['CHANNEL'], 'uname' => '', 'off' => null,
    'stats' => ['wd' => 0, 'ref' => 0, 'bc' => 0], 'log' => [],
    'adm_t' => 0, 'adm_ids' => [], 'last_beat' => 0,
    'key' => null, 'gtok' => '', 'gexp' => 0, 'pid' => '',
];

// ───────────── HELPERS ─────────────
function now_ms() { return (int)(microtime(true) * 1000); }
function ts_ms($v) {
    if (!$v) return 0;
    try { $d = new DateTime((string)$v); return $d->getTimestamp() * 1000 + (int)$d->format('v'); }
    catch (Exception $e) { return 0; }
}
function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function bdt($n) { return 'BDT=' . number_format((float)$n, 2, '.', '') . '(💸)'; }
function logm($m) {
    global $S;
    $line = date('H:i:s') . '  ' . $m;
    array_unshift($S['log'], $line);
    $S['log'] = array_slice($S['log'], 0, 40);
    echo $line . "\n";
    if (PHP_SAPI !== 'cli') { @ob_flush(); @flush(); }
}
function fail($m) { echo $m . "\n"; exit(1); }
function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function http($url, $method, $headers = [], $body = null, $timeout = 40) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return [0, $err];
    return [$code, $res];
}

// ───────────── FIREBASE AUTH + FIRESTORE REST ─────────────
class FsError extends Exception {
    public $status;
    function __construct($m, $code = 0, $status = '') { parent::__construct($m, $code); $this->status = $status; }
}

function load_key() {
    global $CFG, $S;
    $kj = trim((string)getenv('FIREBASE_KEY_JSON'));
    if ($kj === '') $kj = trim($CFG['KEY_INLINE']);
    if ($kj === '' && is_file($CFG['KEY_FILE'])) $kj = trim((string)file_get_contents($CFG['KEY_FILE']));
    if ($kj === '') fail('❌ Firebase key নেই ({
  "type": "service_account",
  "project_id": "tech-bd-income",
  "private_key_id": "91bf99297ff29bf1a0e2ace8db6cf67606e5b778",
  "private_key": "-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDYgA4hfh6yG9U/\n2aL5N1w4oRm6uMXGykCgLHZw72yf5EJrA8W/VpmaDnr5pY/Hb+8XH9SBuLXP1BDq\nPraBw/o1HrQ1KBfu4EkIA1X2zcqqZHfuJeJNcASZQaz/kGAKPCsXYTfo+sdw4RtT\n8ys7k6tK0pyAk+FI2ttxYfwlmYcLW+NKwAL/heOiTv0WSQJIDsjPWCAd/u4U1PzB\nnbugl9S/KpskVOwqbPzkmKl807hwzIND3IhTsNHsmF5X+xzWcBLpJnbbfGFNoOzC\nsIMTn1SD6eF/H2advsNVvAA07kGvHruNZOQCAaCL7B7LdBK4cAsRYU2j/Ha2bNbA\nyUKaoCYNAgMBAAECggEADOS7crUvIM5VieLtOMhaPqkhfiRI6wjahm9/ckRgBLm0\nVuk3mqiOp0Ubf8ijYm5BH/BuWHdmpITtqPWpKttACG/WrZ/ApSJIVyjTssoYusMW\n/WDWNGRqcbIemm2/unxQ2Kiais58mDVfykUeGwM1XVRjq6ii7xLRpNQAD0EwcLWh\nHFaqvEDWEe6sIPDuv47ZG/3gGJnNZRm7esP2KK6C/xcbKZ8ihbxLJ5fgllD2SJnS\nLAJF1RL3gZOCg4s+Jefr/A+F4TUDnRFmn+DmLpMe7Aih7+lRRlifavEnrPvZzyv5\ndLH37dis6R8xd2fKGME3m2ksgZdo5cw96MuCLk3+4QKBgQD8lhvEaJ+EkyiybGQ0\nmwszUqMDpJ1IXp0TRpFtWuT6lwioxUaE8HAqhW6FBS92t82HY/tc/km9muIkzN6G\nC37n6OmvYLjN3u1aLyCR8DXrrMK8czOt34QNeH9r1ZXgw2gSelSdZ0VDJb0p4b3Q\n9Gj34tNglsdhUwfy1RmtONAjiQKBgQDbbRjLSVWXe3Tdew7eCpKX2+FhDPeFpPWx\nbwQcpMLP0c8GEPvlsZI+ibsV9Yocwhsz5mjSLFsrXxdbxzVnCHYgkrvEN7zEKSys\nRQQZUHPfFxE9R4aiZhUTr/ELWDoW3hW5wZJ/awMFljBccPFi/AV6HivO1GlTwFOo\nwWsK/4vZZQKBgQDlZmtnkWG3JXPjwD+t61Zb0WSODXZvYMal2ASCXffygzNxHGI0\noK4uYCsFErWNy9cVbL/BMI3GbyGOLKa49T3X4BhEmMKe7yWAL6cUBCbkmW3ve21n\nL8Yd+d84D65kiPQ/O+0XW9By8JaBsC0V1rRy1gYGBkqkYKWqbNI6GYwniQKBgD8Q\nuBOVXDhRj2EowTkoKMHkvH48T9fldYgttCzXXfaFAs9nij6WLsYyp8bD75IcZ90e\n1/mwLfPlPL1wH/UhBXKPOdBHhMWMX/6TR35PFT6FTrsiF5wNYvlplAoCrY9s/SmK\nbSSaCSBN7lBjVb1xWdGjYkvr2OntWR4jmTRsBjHRAoGBAOTRObqggedTv/K9S6JX\nZ80ZqYmyb8srh6dfBFAtMsUWW3Xf98nEQlLtHhbpxuqa+OpZ+j2sxJR5w8tBWjMn\nstCfGFE1b7YEY2WUK6R44mLmQQlgG2cGUlnsPOgk191BQ1C9tNv+hQ0X/LBvr7EK\n0QIKunmcXe5oCdZ6jPUSTcmD\n-----END PRIVATE KEY-----\n",
  "client_email": "firebase-adminsdk-fbsvc@tech-bd-income.iam.gserviceaccount.com",
  "client_id": "102356074649561494848",
  "auth_uri": "https://accounts.google.com/o/oauth2/auth",
  "token_uri": "https://oauth2.googleapis.com/token",
  "auth_provider_x509_cert_url": "https://www.googleapis.com/oauth2/v1/certs",
  "client_x509_cert_url": "https://www.googleapis.com/robot/v1/metadata/x509/firebase-adminsdk-fbsvc%40tech-bd-income.iam.gserviceaccount.com",
  "universe_domain": "googleapis.com"
}');
    $info = json_decode($kj, true);
    if (!is_array($info)) fail('❌ Firebase key সঠিক JSON নয়');
    foreach (['private_key', 'client_email', 'project_id'] as $f)
        if (empty($info[$f])) fail("❌ Firebase key এ '$f' নেই");
    $info['private_key'] = trim(str_replace('\\n', "\n", $info['private_key'])) . "\n";
    if (strpos($info['private_key'], '-----BEGIN PRIVATE KEY-----') !== 0) fail('❌ private_key ভাঙা — নতুন key নামান');
    if (!openssl_pkey_get_private($info['private_key'])) fail('❌ private_key পড়া যায়নি (openssl)');
    $info['token_uri'] = $info['token_uri'] ?? 'https://oauth2.googleapis.com/token';
    $S['key'] = $info;
    $S['pid'] = $info['project_id'];
}

function g_token() {
    global $S;
    if ($S['gexp'] > time() + 60) return $S['gtok'];
    $k = $S['key']; $now = time();
    $h = b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $c = b64u(json_encode(['iss' => $k['client_email'], 'scope' => 'https://www.googleapis.com/auth/datastore',
        'aud' => $k['token_uri'], 'iat' => $now, 'exp' => $now + 3600]));
    $sig = '';
    if (!openssl_sign("$h.$c", $sig, $k['private_key'], OPENSSL_ALGO_SHA256)) throw new FsError('JWT সই করা যায়নি');
    [$code, $res] = http($k['token_uri'], 'POST', ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$h.$c." . b64u($sig)]), 30);
    $j = json_decode($res, true);
    if ($code !== 200 || empty($j['access_token'])) throw new FsError('টোকেন ব্যর্থ: ' . $res, $code);
    $S['gtok'] = $j['access_token'];
    $S['gexp'] = $now + (int)($j['expires_in'] ?? 3000);
    return $S['gtok'];
}

function db_url() { global $S; return 'https://firestore.googleapis.com/v1/projects/' . $S['pid'] . '/databases/(default)'; }
function doc_name($path) { global $S; return 'projects/' . $S['pid'] . '/databases/(default)/documents/' . $path; }

function fs_req($method, $url, $body = null) {
    [$code, $res] = http($url, $method, ['Authorization: Bearer ' . g_token(), 'Content-Type: application/json'],
        $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE), 60);
    $j = json_decode($res, true);
    if ($code >= 400 || $code === 0) {
        $msg = is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : $res;
        throw new FsError($msg, $code, is_array($j) ? ($j['error']['status'] ?? '') : '');
    }
    return $j;
}

function is_list($a) { return $a === [] || array_keys($a) === range(0, count($a) - 1); }
function enc($v) {
    if ($v === null) return ['nullValue' => null];
    if (is_bool($v)) return ['booleanValue' => $v];
    if (is_int($v)) return ['integerValue' => (string)$v];
    if (is_float($v)) return ['doubleValue' => $v];
    if (is_array($v)) {
        if (is_list($v)) return ['arrayValue' => ['values' => array_map('enc', $v)]];
        return ['mapValue' => ['fields' => enc_map($v)]];
    }
    return ['stringValue' => (string)$v];
}
function enc_map($a) { $o = []; foreach ($a as $k => $v) $o[$k] = enc($v); return (object)$o; }
function dec($v) {
    if (isset($v['stringValue'])) return $v['stringValue'];
    if (isset($v['integerValue'])) return (int)$v['integerValue'];
    if (isset($v['doubleValue'])) return (float)$v['doubleValue'];
    if (isset($v['booleanValue'])) return (bool)$v['booleanValue'];
    if (isset($v['timestampValue'])) return $v['timestampValue'];
    if (isset($v['mapValue'])) return dec_map($v['mapValue']['fields'] ?? []);
    if (isset($v['arrayValue'])) return array_map('dec', $v['arrayValue']['values'] ?? []);
    return null;
}
function dec_map($f) { $o = []; foreach ($f as $k => $v) $o[$k] = dec($v); return $o; }
function to_doc($d) {
    $o = dec_map($d['fields'] ?? []);
    $o['_id'] = basename($d['name']);
    $o['_ut'] = $d['updateTime'] ?? null;
    return $o;
}

function fs_get($path) {
    try { return to_doc(fs_req('GET', db_url() . '/documents/' . $path)); }
    catch (FsError $e) { if ($e->getCode() === 404) return null; throw $e; }
}
function fs_query($col, $filters = [], $limit = null) {
    $q = ['from' => [['collectionId' => $col]]];
    $fl = [];
    foreach ($filters as [$f, $op, $val])
        $fl[] = ['fieldFilter' => ['field' => ['fieldPath' => $f], 'op' => $op, 'value' => $val]];
    if (count($fl) === 1) $q['where'] = $fl[0];
    elseif (count($fl) > 1) $q['where'] = ['compositeFilter' => ['op' => 'AND', 'filters' => $fl]];
    if ($limit) $q['limit'] = $limit;
    $res = fs_req('POST', db_url() . '/documents:runQuery', ['structuredQuery' => $q]);
    $out = [];
    foreach ((array)$res as $r) if (isset($r['document'])) $out[] = to_doc($r['document']);
    return $out;
}
function fs_eq($col, $field, $val) { return fs_query($col, [[$field, 'EQUAL', enc($val)]]); }
function fs_list($col) {
    $out = []; $tok = '';
    do {
        try { $r = fs_req('GET', db_url() . '/documents/' . $col . '?pageSize=300' . ($tok ? '&pageToken=' . urlencode($tok) : '')); }
        catch (FsError $e) { return $out; }
        foreach ($r['documents'] ?? [] as $d) $out[] = to_doc($d);
        $tok = $r['nextPageToken'] ?? '';
    } while ($tok);
    return $out;
}

// writes
function w_set($path, $data, $o = []) {
    $w = ['update' => ['name' => doc_name($path), 'fields' => enc_map($data)]];
    if (!empty($o['merge'])) $w['updateMask'] = ['fieldPaths' => array_keys($data)];
    if (!empty($o['ts'])) $w['updateTransforms'] = array_map(function ($f) { return ['fieldPath' => $f, 'setToServerValue' => 'REQUEST_TIME']; }, $o['ts']);
    if (isset($o['pre'])) $w['currentDocument'] = $o['pre'];
    return $w;
}
function fs_commit($writes) { return fs_req('POST', db_url() . ':commit', ['writes' => $writes]); }
function fs_set($path, $data, $o = []) { return fs_commit([w_set($path, $data, $o)]); }
function fs_merge($path, $data, $ts = []) { return fs_set($path, $data, ['merge' => true, 'ts' => $ts]); }
function fs_update($path, $data, $ts = []) { return fs_set($path, $data, ['merge' => true, 'ts' => $ts, 'pre' => ['exists' => true]]); }
function fs_delete($path) { try { fs_commit([['delete' => doc_name($path)]]); } catch (FsError $e) {} }
function fs_add($col, $data, $ts = ['ts']) {
    $id = substr(bin2hex(random_bytes(10)), 0, 20);
    fs_set($col . '/' . $id, $data, ['ts' => $ts, 'pre' => ['exists' => false]]);
    return $id;
}
// atomic: শুধু যদি ডক্যুমেন্ট এখনও $d এর মতো থাকে
function fs_cas($path, $d, $data, $ts = []) {
    try { fs_set($path, $data, ['merge' => true, 'ts' => $ts, 'pre' => ['updateTime' => $d['_ut']]]); return true; }
    catch (FsError $e) { return false; }
}
function claim($path, $field) {
    $d = fs_get($path);
    if (!$d || !empty($d[$field])) return false;
    return fs_cas($path, $d, [$field => true]);
}

// ───────────── TELEGRAM ─────────────
class TgError extends Exception {
    public $retry;
    function __construct($m, $code = 0, $retry = null) { parent::__construct($m, $code); $this->retry = $retry; }
}
function tg_api($method, $body = [], $timeout = 40) {
    global $S;
    [$code, $res] = http('https://api.telegram.org/bot' . $S['tok'] . '/' . $method, 'POST',
        ['Content-Type: application/json'], json_encode((object)$body, JSON_UNESCAPED_UNICODE), $timeout);
    $j = json_decode($res, true);
    if (!is_array($j)) throw new TgError($res ?: 'network error', 0);
    if (empty($j['ok'])) throw new TgError($j['description'] ?? ('HTTP ' . $code), (int)($j['error_code'] ?? 0), $j['parameters']['retry_after'] ?? null);
    return $j['result'];
}
function tg_try($method, $body = []) {
    try { return tg_api($method, $body); }
    catch (TgError $e) { logm('⚠ ' . $method . ': ' . $e->getMessage()); return null; }
}
function tg_of($uid) { return fs_get('tgLinks/' . $uid); }
function tg_dm($uid, $l, $payload) {
    try { tg_api('sendMessage', array_merge(['chat_id' => $l['tgId']], $payload)); return true; }
    catch (TgError $e) {
        logm('❌ DM যায়নি (' . ($l['name'] ?? $l['tgName'] ?? $l['tgId']) . '): ' . $e->getMessage());
        if (in_array($e->getCode(), [403, 400])) { try { fs_merge('tgLinks/' . $uid, ['started' => false]); } catch (FsError $x) {} }
        return false;
    }
}
function app_kb($st) {
    $u = $st['appUrl'] ?? '';
    if (strpos($u, 'https://') === 0)
        return ['reply_markup' => ['inline_keyboard' => [[['text' => '🚀 Open App', 'web_app' => ['url' => $u]]]]]];
    return [];
}
function app_settings() { $d = fs_get('settings/app'); return $d ?: []; }

function user_bal($uid, $excludeId = null) {
    $e = 0.0; $sp = 0.0;
    foreach (['taskDone', 'adViews', 'bonuses', 'promoUse', 'gameWins'] as $c) {
        try { foreach (fs_eq($c, 'uid', $uid) as $d) $e += (float)($d['reward'] ?? 0); } catch (FsError $x) {}
    }
    foreach (['refBonuses', 'adjustments'] as $c)
        foreach (fs_eq($c, 'uid', $uid) as $d) $e += (float)($d['amount'] ?? 0);
    foreach (fs_eq('withdrawals', 'uid', $uid) as $d)
        if ($d['_id'] !== $excludeId && ($d['status'] ?? '') !== 'rejected') $sp += (float)($d['amount'] ?? 0);
    return round(($e - $sp) * 100) / 100;
}

// ───────────── LEADERBOARD ─────────────
function publish_leaderboard() {
    $users = fs_list('users'); $bal = [];
    foreach (['taskDone', 'adViews', 'bonuses', 'promoUse', 'gameWins'] as $c)
        foreach (fs_list($c) as $x) if (!empty($x['uid'])) $bal[$x['uid']] = ($bal[$x['uid']] ?? 0) + (float)($x['reward'] ?? 0);
    foreach (['refBonuses', 'adjustments'] as $c)
        foreach (fs_list($c) as $x) if (!empty($x['uid'])) $bal[$x['uid']] = ($bal[$x['uid']] ?? 0) + (float)($x['amount'] ?? 0);
    foreach (fs_list('withdrawals') as $w)
        if (!empty($w['uid']) && ($w['status'] ?? '') !== 'rejected') $bal[$w['uid']] = ($bal[$w['uid']] ?? 0) - (float)($w['amount'] ?? 0);
    $rows = [];
    foreach ($users as $u) {
        if (!empty($u['banned'])) continue;
        $v = round(($bal[$u['_id']] ?? 0) * 100) / 100;
        if ($v > 0) {
            $ph = (string)($u['photo'] ?? '');
            $rows[] = ['name' => $u['name'] ?? 'Member', 'amount' => $v, 'k' => substr($u['_id'], -6),
                'p' => preg_match('~^https://[^\s"\'<>]{3,300}$~', $ph) ? $ph : ''];
        }
    }
    usort($rows, function ($a, $b) { return $b['amount'] <=> $a['amount']; });
    fs_set('leaderboard/top', ['rows' => array_slice($rows, 0, 50), 'total' => count($rows)], ['ts' => ['ts']]);
    logm('🏆 Leaderboard: ' . count($rows) . ' জন');
}

// ───────────── UPDATES: /start, REFER, CALLBACK ─────────────
function is_chan_admin($id) {
    global $S;
    if (time() - $S['adm_t'] > 300) {
        $l = tg_api('getChatAdministrators', ['chat_id' => $S['chan']]);
        $S['adm_t'] = time();
        $S['adm_ids'] = array_map(function ($m) { return $m['user']['id']; }, $l);
    }
    return in_array($id, $S['adm_ids']);
}

function on_message($m) {
    global $S;
    $chat = $m['chat'] ?? []; $frm = $m['from'] ?? [];
    if (($chat['type'] ?? '') !== 'private' || !$frm || !empty($frm['is_bot'])) return;
    $tgId = (string)$frm['id']; $text = (string)($m['text'] ?? '');
    $name = trim(($frm['first_name'] ?? '') . ' ' . ($frm['last_name'] ?? ''));
    $existed = fs_get('botUsers/' . $tgId) !== null;
    $data = ['name' => $name, 'username' => $frm['username'] ?? '', 'active' => true];
    fs_merge('botUsers/' . $tgId, $data, $existed ? ['lastStart'] : ['lastStart', 'joinedAt']);
    try { foreach (fs_eq('tgLinks', 'tgId', $tgId) as $d) fs_merge('tgLinks/' . $d['_id'], ['started' => true]); } catch (FsError $e) {}
    if (!preg_match('~^/start~i', $text))
        return tg_try('sendMessage', ['chat_id' => $chat['id'], 'text' => 'অ্যাপ খুলতে নিচের মেনু বাটন ব্যবহার করুন। 👇']);
    $st = app_settings();
    $parts = preg_split('/\s+/', trim($text));
    $payload = trim($parts[1] ?? '');
    $refReward = (float)($st['refReward'] ?? 0);
    if (!$existed && preg_match('~^[A-Za-z0-9]{10,40}$~', $payload) && $refReward > 0) {
        try {
            $ru = fs_get('users/' . $payload);
            $own = fs_eq('tgLinks', 'tgId', $tgId);
            $done = fs_get('refBonuses/' . $tgId);
            $ownIds = array_map(function ($d) { return $d['_id']; }, $own);
            if ($ru && empty($ru['banned']) && !$done && !in_array($payload, $ownIds)) {
                fs_set('refBonuses/' . $tgId, ['uid' => $payload, 'amount' => $refReward, 'tgId' => $tgId, 'name' => $name], ['ts' => ['ts'], 'pre' => ['exists' => false]]);
                $S['stats']['ref']++;
                logm('🎁 Refer: ' . ($name ?: $tgId) . ' → ' . substr($payload, 0, 8) . '… (' . $refReward . ')');
                $rl = tg_of($payload);
                if ($rl) tg_dm($payload, $rl, ['parse_mode' => 'HTML', 'text' =>
                    "🎉 <b>নতুন রেফার!</b>\n" . esc($name ?: 'একজন বন্ধু') . " আপনার লিংক দিয়ে যোগ দিয়েছে।\n➕ " . bdt($refReward) . " আপনার ব্যালেন্সে যোগ হয়েছে।"]);
            }
        } catch (Exception $e) { logm('⚠ referral: ' . $e->getMessage()); }
    }
    return tg_try('sendMessage', array_merge(['chat_id' => $chat['id'], 'parse_mode' => 'HTML',
        'text' => "👋 স্বাগতম, <b>" . esc($frm['first_name'] ?? 'বন্ধু') . "</b>!\n\nTech BD Income এ কাজ করে ইনকাম করুন। শুরু করতে অ্যাপ খুলুন।"], app_kb($st)));
}

function on_callback($q) {
    $p = explode(':', (string)($q['data'] ?? ''));
    if (($p[0] ?? '') !== 'wd' || !in_array($p[1] ?? '', ['a', 'r']) || empty($p[2]))
        return tg_try('answerCallbackQuery', ['callback_query_id' => $q['id']]);
    $ok = false;
    try { $ok = is_chan_admin($q['from']['id']); } catch (TgError $e) { logm('⚠ admins: ' . $e->getMessage()); }
    if (!$ok) return tg_try('answerCallbackQuery', ['callback_query_id' => $q['id'], 'text' => '⛔ আপনি অ্যাডমিন নন', 'show_alert' => true]);
    $status = $p[1] === 'a' ? 'approved' : 'rejected';
    $fu = $q['from'];
    $via = 'telegram:' . (!empty($fu['username']) ? '@' . $fu['username'] : $fu['id']);
    $path = 'withdrawals/' . $p[2];
    $d = fs_get($path);
    $res = 'done';
    if ($d && ($d['status'] ?? '') === 'pending')
        $res = fs_cas($path, $d, ['status' => $status, 'reviewedVia' => $via, 'note' => $status === 'rejected' ? 'Rejected by admin' : ''], ['reviewedAt']) ? 'ok' : 'done';
    $txt = $res === 'ok' ? ($status === 'approved' ? '✅ Approved' : '❌ Rejected') : 'ইতিমধ্যে প্রসেস হয়েছে';
    return tg_try('answerCallbackQuery', ['callback_query_id' => $q['id'], 'text' => $txt]);
}

// ───────────── WITHDRAW ─────────────
function handle_withdraw($w) {
    global $S;
    $path = 'withdrawals/' . $w['_id'];
    if (($w['status'] ?? '') === 'pending' && empty($w['tgSent'])) {
        if (!claim($path, 'tgSent')) return;
        $bal = user_bal($w['uid'] ?? '', $w['_id']);
        $flagged = (float)($w['amount'] ?? 0) > $bal + 0.005;
        $l = tg_of($w['uid'] ?? '');
        if (!$l) logm('ℹ ইউজারের Telegram যুক্ত নেই, ইনবক্সে মেসেজ যাবে না: ' . ($w['name'] ?? $w['uid'] ?? ''));
        $text = "💸 <b>New Withdraw Request</b>\n\n👤 " . esc($w['name'] ?? 'User')
            . (($l && !empty($l['tgName'])) ? ' (@' . esc($l['tgName']) . ')' : '')
            . "\n🆔 <code>" . esc($w['uid'] ?? '') . "</code>\n💰 Amount: <b>" . bdt($w['amount'] ?? 0)
            . "</b>\n🏦 Method: <b>" . esc($w['method'] ?? '') . "</b>\n📮 Address: <code>" . esc($w['address'] ?? '')
            . "</code>\n📊 Verified balance (আগে): " . bdt($bal);
        if ($flagged) $text .= "\n\n⚠️ <b>WARNING:</b> চাওয়া পরিমাণ যাচাইকৃত ব্যালেন্সের চেয়ে বেশি!";
        $sent = tg_try('sendMessage', ['chat_id' => $S['chan'], 'text' => $text, 'parse_mode' => 'HTML',
            'reply_markup' => ['inline_keyboard' => [[
                ['text' => '✅ Approve', 'callback_data' => 'wd:a:' . $w['_id']],
                ['text' => '❌ Reject', 'callback_data' => 'wd:r:' . $w['_id']]]]]]);
        fs_update($path, ['verifiedBalance' => $bal, 'flagged' => $flagged, 'chText' => $text, 'chMsgId' => $sent ? (int)$sent['message_id'] : null]);
        if ($l) tg_dm($w['uid'], $l, ['parse_mode' => 'HTML', 'text' =>
            "⏳ <b>আপনার Withdraw অনুরোধ জমা হয়েছে</b>\n\n💰 " . bdt($w['amount'] ?? 0) . "\n🏦 " . esc($w['method'] ?? '')
            . " — <code>" . esc($w['address'] ?? '') . "</code>\n\nঅ্যাডমিন যাচাই করে শীঘ্রই জানাবে।"]);
        $S['stats']['wd']++;
        logm('💸 Withdraw ' . bdt($w['amount'] ?? 0) . ' — ' . ($w['name'] ?? $w['uid'] ?? ''));
    }
    if (in_array($w['status'] ?? '', ['approved', 'rejected']) && empty($w['tgDone'])) {
        if (!claim($path, 'tgDone')) return;
        $ok = $w['status'] === 'approved';
        $l = tg_of($w['uid'] ?? '');
        if ($l) {
            if ($ok) $msg = "✅ <b>Withdraw Approved!</b>\n\n💰 " . bdt($w['amount'] ?? 0) . "\n🏦 " . esc($w['method'] ?? '')
                . " — <code>" . esc($w['address'] ?? '') . "</code>\n\nপেমেন্ট পাঠানো হয়েছে। ধন্যবাদ!";
            else $msg = "❌ <b>Withdraw Rejected</b>\n\n💰 " . bdt($w['amount'] ?? 0) . "\n"
                . (!empty($w['note']) ? '📝 কারণ: ' . esc($w['note']) . "\n" : '') . "\nটাকা আপনার ব্যালেন্সে ফেরত এসেছে।";
            tg_dm($w['uid'], $l, ['parse_mode' => 'HTML', 'text' => $msg]);
        }
        if ($ok) {
            $n = trim((string)($w['name'] ?? ''));
            $masked = $n === '' ? 'User' : (mb_strlen($n) > 2 ? mb_substr($n, 0, 1) . '***' . mb_substr($n, -1) : mb_substr($n, 0, 1) . '***');
            try { fs_add('payoutsFeed', ['name' => $masked, 'amount' => (float)($w['amount'] ?? 0), 'method' => $w['method'] ?? '']); } catch (FsError $e) {}
        }
        $fresh = fs_get($path) ?: [];
        if (!empty($fresh['chMsgId']) && !empty($fresh['chText']))
            tg_try('editMessageText', ['chat_id' => $S['chan'], 'message_id' => $fresh['chMsgId'], 'parse_mode' => 'HTML',
                'text' => $fresh['chText'] . "\n\n" . ($ok ? '✅ <b>APPROVED</b>' : '❌ <b>REJECTED</b>') . ' ' . esc($fresh['reviewedVia'] ?? '(admin panel)'),
                'reply_markup' => ['inline_keyboard' => []]]);
        logm(($ok ? '✅' : '❌') . ' Withdraw ' . $w['status'] . ': ' . ($w['name'] ?? $w['uid'] ?? ''));
    }
}

// ───────────── PINGS + TELEGRAM LINK ─────────────
function handle_ping($p) {
    if (now_ms() - ts_ms($p['ts'] ?? null) > 90000) { fs_delete('pings/' . $p['_id']); return; }
    $l = tg_of($p['to'] ?? '');
    if ($l && !empty($l['started'])) {
        $labels = ['msg' => '💬 নতুন মেসেজ এসেছে', 'call' => '📞 অডিও কল আসছে', 'vcall' => '🎥 ভিডিও কল আসছে'];
        $label = $labels[$p['kind'] ?? ''] ?? '🔔 নতুন নোটিফিকেশন';
        tg_try('sendMessage', array_merge(['chat_id' => $l['tgId'], 'parse_mode' => 'HTML',
            'text' => $label . "\n\n👤 " . esc($p['fromName'] ?? 'একজন ইউজার') . " থেকে।\nএখনই অ্যাপ খুলুন।"], app_kb(app_settings())));
    }
    fs_delete('pings/' . $p['_id']);
}

function verify_init($init) {
    global $S;
    $pairs = [];
    foreach (explode('&', (string)$init) as $part) {
        if ($part === '') continue;
        $kv = explode('=', $part, 2);
        $pairs[urldecode($kv[0])] = urldecode($kv[1] ?? '');
    }
    $hash = $pairs['hash'] ?? null; unset($pairs['hash']);
    if (!$hash) return null;
    ksort($pairs, SORT_STRING);
    $lines = [];
    foreach ($pairs as $k => $v) $lines[] = $k . '=' . $v;
    $secret = hash_hmac('sha256', $S['tok'], 'WebAppData', true);
    $calc = hash_hmac('sha256', implode("\n", $lines), $secret);
    $age = time() - (float)($pairs['auth_date'] ?? 0);
    if (!hash_equals($calc, $hash) || $age > 7 * 86400) return null;
    $u = json_decode($pairs['user'] ?? '', true);
    return is_array($u) ? $u : null;
}

function handle_link($r) {
    $uid = $r['_id'];
    $u = verify_init($r['initData'] ?? '');
    if ($u && !empty($u['id'])) {
        $tgId = (string)$u['id'];
        $started = false;
        try { tg_api('sendChatAction', ['chat_id' => $tgId, 'action' => 'typing']); $started = true; } catch (TgError $e) {}
        $writes = [];
        foreach (fs_eq('tgLinks', 'tgId', $tgId) as $d) if ($d['_id'] !== $uid) $writes[] = ['delete' => doc_name('tgLinks/' . $d['_id'])];
        $photo = (string)($u['photo_url'] ?? '');
        $writes[] = w_set('tgLinks/' . $uid, [
            'tgId' => $tgId, 'tgName' => $u['username'] ?? '',
            'name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
            'photo' => strpos($photo, 'https://') === 0 ? $photo : '', 'started' => $started,
        ], ['merge' => true, 'ts' => ['ts']]);
        fs_commit($writes);
        logm('🔗 Linked: ' . ($u['first_name'] ?? '') . ' (' . $tgId . ') — ইনবক্স ' . ($started ? '✅' : '❌ (START দিতে হবে)'));
    } else logm('⚠ Telegram যাচাই ব্যর্থ (Token ঠিক আছে?)');
    fs_delete('tgReq/' . $uid);
}

// ───────────── ADMIN JOBS: BROADCAST + TEST ─────────────
function run_job($j) {
    global $S, $ME;
    $path = 'botJobs/' . $j['_id'];
    if (($j['status'] ?? '') !== 'queued') return;
    if (now_ms() - ts_ms($j['ts'] ?? null) > 6 * 3600 * 1000) { try { fs_update($path, ['status' => 'expired']); } catch (FsError $e) {} return; }
    if (!fs_cas($path, $j, ['status' => 'running', 'by' => $ME], ['startedAt'])) return;
    try {
        if (($j['type'] ?? '') === 'test') {
            $me = tg_api('getMe');
            tg_api('sendMessage', ['chat_id' => $S['chan'], 'text' => '✅ Test: @' . $me['username'] . ' চ্যানেলে মেসেজ পাঠাতে পারছে।']);
            tg_api('getChatAdministrators', ['chat_id' => $S['chan']]);
            fs_update($path, ['status' => 'done', 'result' => '✅ সব ঠিক আছে — চ্যানেলে টেস্ট মেসেজ গেছে']);
            return;
        }
        if (($j['type'] ?? '') === 'broadcast') {
            $text = '📢 ' . mb_substr((string)($j['text'] ?? ''), 0, 3500);
            $ids = [];
            if (($j['target'] ?? '') === 'one') {
                $l = tg_of($j['uid'] ?? '');
                if (!$l) throw new Exception('এই ইউজারের Telegram যুক্ত নেই');
                $ids[(string)$l['tgId']] = 1;
            } else {
                foreach (fs_list('botUsers') as $d) if (($d['active'] ?? true) !== false) $ids[(string)$d['_id']] = 1;
                foreach (fs_list('tgLinks') as $d) if (!empty($d['tgId'])) $ids[(string)$d['tgId']] = 1;
            }
            $ids = array_keys($ids);
            $sent = 0; $failed = 0; $n = 0; $total = count($ids);
            foreach ($ids as $cid) {
                $cid = (string)$cid; $n++;
                for ($a = 0; $a < 3; $a++) {
                    try { tg_api('sendMessage', ['chat_id' => $cid, 'text' => $text, 'disable_web_page_preview' => true]); $sent++; break; }
                    catch (TgError $e) {
                        if ($e->getCode() === 429 && $a < 2) { sleep(((int)($e->retry ?: 2)) + 1); continue; }
                        $failed++;
                        if ($e->getCode() === 403) { try { fs_merge('botUsers/' . $cid, ['active' => false]); } catch (FsError $x) {} }
                        break;
                    }
                }
                if ($n % 20 === 0) { try { fs_update($path, ['sent' => $sent, 'failed' => $failed, 'total' => $total]); } catch (FsError $e) {} beat(true); }
                usleep(50000);
            }
            fs_add('broadcasts', ['text' => $j['text'] ?? '', 'target' => $j['target'] ?? '', 'sent' => $sent, 'failed' => $failed, 'by' => $j['by'] ?? '']);
            $S['stats']['bc']++;
            logm("📢 Broadcast: পাঠানো $sent, ব্যর্থ $failed");
            fs_update($path, ['status' => 'done', 'sent' => $sent, 'failed' => $failed, 'total' => $total, 'result' => "✅ পাঠানো: $sent, ব্যর্থ: $failed"]);
            return;
        }
        fs_update($path, ['status' => 'error', 'result' => 'অজানা job']);
    } catch (Exception $e) {
        logm('❌ job: ' . $e->getMessage());
        try { fs_update($path, ['status' => 'error', 'result' => '❌ ' . $e->getMessage()]); } catch (FsError $x) {}
    }
}

// ───────────── LEASE + HEARTBEAT ─────────────
function beat($force = false, $running = true) {
    global $S, $ME, $STARTED;
    if (!$force && time() - $S['last_beat'] < 20) return true;
    try {
        $d = fs_get('botStatus/main');
        $m = $d ?: [];
        if (!empty($m['instance']) && $m['instance'] !== $ME && now_ms() - ts_ms($m['beat'] ?? null) < LEASE_S * 1000) return false;
        $off = $S['off'] !== null ? $S['off'] : (isset($m['offset']) ? $m['offset'] : null);
        fs_set('botStatus/main', [
            'instance' => $ME, 'running' => $running, 'username' => $S['uname'], 'host' => 'php', 'startedAt' => $STARTED,
            'offset' => $off, 'stats' => $S['stats'], 'log' => array_slice($S['log'], 0, 30),
        ], ['merge' => true, 'ts' => ['beat'], 'pre' => $d ? ['updateTime' => $d['_ut']] : ['exists' => false]]);
        $S['last_beat'] = time();
        if ($S['off'] === null && isset($m['offset'])) $S['off'] = (int)$m['offset'];
        return true;
    } catch (FsError $e) {
        if (in_array($e->status, ['FAILED_PRECONDITION', 'ABORTED', 'ALREADY_EXISTS'])) return false;   // অন্য instance আগে লিখেছে
        echo 'heartbeat: ' . $e->getMessage() . "\n";
        return true;
    }
}
function release() {
    global $S;
    try { fs_merge('botStatus/main', ['instance' => '', 'running' => false, 'offset' => $S['off'], 'stats' => $S['stats'], 'log' => array_slice($S['log'], 0, 30)]); }
    catch (Exception $e) {}
}

// ───────────── CYCLE ─────────────
function poll_updates() {
    global $S, $CFG;
    $body = ['timeout' => $CFG['LOOP_SECONDS'] ? 2 : 0, 'allowed_updates' => ['message', 'callback_query']];
    if ($S['off'] !== null) $body['offset'] = $S['off'];
    $ups = tg_api('getUpdates', $body, 20);
    foreach ($ups as $u) {
        $S['off'] = $u['update_id'] + 1;
        try {
            if (!empty($u['message'])) on_message($u['message']);
            elseif (!empty($u['callback_query'])) on_callback($u['callback_query']);
        } catch (Exception $e) { logm('❌ update: ' . $e->getMessage()); }
    }
    if ($ups) beat(true);
}

function scan() {
    foreach (fs_eq('withdrawals', 'status', 'pending') as $w) {
        try { handle_withdraw($w); } catch (Exception $e) { logm('❌ withdraw: ' . $e->getMessage()); }
    }
    try {
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - 1800);
        foreach (fs_query('withdrawals', [['reviewedAt', 'GREATER_THAN_OR_EQUAL', ['timestampValue' => $since]]]) as $w) {
            try { handle_withdraw($w); } catch (Exception $e) { logm('❌ withdraw: ' . $e->getMessage()); }
        }
    } catch (Exception $e) { logm('⚠ reviewed scan: ' . $e->getMessage()); }
    foreach (fs_list('pings') as $p) { try { handle_ping($p); } catch (Exception $e) { logm('❌ ping: ' . $e->getMessage()); } }
    foreach (fs_list('tgReq') as $r) { try { handle_link($r); } catch (Exception $e) { logm('❌ link: ' . $e->getMessage()); } }
    foreach (fs_eq('botJobs', 'status', 'queued') as $j) run_job($j);
}

function load_config() {
    global $S, $CFG;
    $c = fs_get('private/bot') ?: [];
    $S['tok'] = $c['token'] ?? '';
    $S['chan'] = $c['channelId'] ?? $CFG['CHANNEL'];
    $S['uname'] = $c['botUsername'] ?? $S['uname'];
    $ctl = fs_get('botStatus/control');
    return !($ctl && array_key_exists('enabled', $ctl) && $ctl['enabled'] === false);
}
function lb_due() {
    $d = fs_get('botStatus/main');
    return now_ms() - (int)($d['lbAt'] ?? 0) > LB_EVERY * 1000;
}

function main() {
    global $S, $CFG;
    load_key();
    try { g_token(); }
    catch (FsError $e) { echo 'সার্ভারের সময় (UTC): ' . gmdate('c') . "\n"; fail('❌ Firebase লগইন ব্যর্থ — key অকার্যকর/বন্ধ করা, অথবা সার্ভারের সময় ভুল: ' . $e->getMessage()); }
    echo "Firebase key: {$S['pid']} / {$S['key']['client_email']}\n";
    echo "✅ Firebase লগইন ঠিক আছে\n";

    $enabled = load_config();
    if ($S['tok'] === '') { logm('❌ Token নেই (Admin → Settings এ দিন)'); return; }
    if (!$enabled) { logm('⏹ Admin থেকে বন্ধ করা আছে'); beat(true, false); return; }
    if (!beat(true)) { echo "অন্য জায়গায় বট চলছে (Standby) — বের হচ্ছি\n"; return; }
    try {
        tg_api('deleteWebhook', ['drop_pending_updates' => false]);
        $me = tg_api('getMe');
        $S['uname'] = $me['username'];
        logm('✅ Bot চালু: @' . $me['username']);
    } catch (TgError $e) { logm('❌ ' . $e->getMessage()); release(); return; }

    $end = time() + (int)$CFG['LOOP_SECONDS'];
    $lastScan = 0;
    try {
        while (true) {
            try { poll_updates(); }
            catch (TgError $e) {
                if ($e->getCode() === 401) { logm('❌ Token ভুল'); break; }
                logm('⚠ getUpdates: ' . $e->getMessage());
                if ($e->getCode() === 409) break;
            }
            if (time() - $lastScan >= SCAN_EVERY || !$CFG['LOOP_SECONDS']) {
                $lastScan = time();
                scan();
                if (lb_due()) {
                    try { publish_leaderboard(); fs_merge('botStatus/main', ['lbAt' => now_ms()]); }
                    catch (Exception $e) { logm('⚠ leaderboard: ' . $e->getMessage()); }
                }
            }
            if (!beat()) { logm('⏸ lease হারিয়েছে, বন্ধ'); return; }
            if (time() >= $end) break;
            if (!load_config()) { logm('⏹ Admin থেকে বন্ধ'); break; }
            sleep(1);
        }
    } finally {
        release();
    }
}

// একসাথে দুটো প্রসেস চালু হওয়া ঠেকাতে লক
$lock = fopen(sys_get_temp_dir() . '/techbd_bot_' . md5(__FILE__) . '.lock', 'c');
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) { echo "আগের রান এখনো চলছে, বের হচ্ছি\n"; exit(0); }
try { main(); }
catch (Throwable $e) { echo '❌ ' . $e->getMessage() . "\n"; exit(1); }
