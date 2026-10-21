<?php
// BM Browser – Konto-Schnittstelle: Registrierung, Anmeldung, Passwort-Reset, Synchronisation, Admin.
declare(strict_types=1);
require __DIR__ . '/lib.php';

function err(string $msg, int $status = 400, array $extra = []): never { jout($status, ['error' => $msg] + $extra); }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in = [];
if ($method === 'POST') {
    // Schutz vor fremden Seiten: eigener Header + Herkunftsprüfung
    if (($_SERVER['HTTP_X_BM'] ?? '') !== '1') err('Ungültige Anfrage.', 403);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '') {
        $oh = strtolower((string)parse_url($origin, PHP_URL_HOST));
        $hh = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        if ($oh === '' || $oh !== $hh) err('Ungültige Herkunft.', 403);
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 700000) err('Anfrage zu groß.', 413);
    $in = json_decode($raw === '' ? '[]' : $raw, true);
    if (!is_array($in)) err('Ungültige Anfrage.');
} elseif ($method !== 'GET') {
    err('Methode nicht erlaubt.', 405);
}
$a = (string)($in['a'] ?? ($_GET['a'] ?? ''));
if ($method === 'GET' && $a !== 'me') err('Nur POST erlaubt.', 405);

function pub(array $u): array { return ['email' => $u['email'], 'role' => $u['role'], 'ai' => $u['ai']]; }

function clean_links($v, int $max = 24): array {
    $out = [];
    if (!is_array($v)) return $out;
    foreach ($v as $l) {
        if (!is_array($l)) continue;
        $t = trim((string)($l['t'] ?? '')); $u = trim((string)($l['u'] ?? ''));
        if ($t === '' || !preg_match('~^https?://[^\s]+$~i', $u)) continue;
        $out[] = ['t' => mb_substr($t, 0, 40, 'UTF-8'), 'u' => mb_substr($u, 0, 500, 'UTF-8')];
        if (count($out) >= $max) break;
    }
    return $out;
}

function site_cfg(): array {
    $r = ['links' => [], 'ext' => []];
    if (!db_configured()) return $r;
    $st = db()->prepare('SELECT v FROM bm_config WHERE k = ?');
    $st->execute(['site']);
    $v = $st->fetchColumn();
    $d = $v ? json_decode((string)$v, true) : null;
    if (is_array($d)) { $r['links'] = clean_links($d['links'] ?? []); $r['ext'] = clean_links($d['ext'] ?? []); }
    return $r;
}

function need_user(): array { $u = me(); if (!$u) err('Bitte zuerst anmelden.', 401); return $u; }
function need_admin(): array { $u = need_user(); if ($u['role'] !== 'admin') err('Nur für Admins.', 403); return $u; }
function norm_email($v): string { return strtolower(trim((string)$v)); }

function send_reset_mail(string $to, string $link): void {
    $from = (string)(cfg()['mail_from'] ?? 'info@bomaex.de');
    $subject = '=?UTF-8?B?' . base64_encode('BM Browser – Passwort zurücksetzen') . '?=';
    $body = "Hallo,\r\n\r\nfür dein BM-Browser-Konto wurde ein neues Passwort angefordert.\r\n"
          . "Diesen Link kannst du eine Stunde lang nutzen:\r\n\r\n$link\r\n\r\n"
          . "Wenn du das nicht angefordert hast, ignoriere diese Mail einfach – dein Passwort bleibt unverändert.\r\n\r\nBM Browser";
    $headers = "From: BM Browser <$from>\r\nReply-To: $from\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    @mail($to, $subject, $body, $headers, '-f' . $from);
}

switch ($a) {

case 'me':
    if (!db_configured()) jout(200, ['off' => true, 'user' => null, 'cfg' => ['links' => [], 'ext' => []]]);
    $u = me();
    jout(200, ['user' => $u ? pub($u) : null, 'cfg' => site_cfg()]);

case 'register': {
    $email = norm_email($in['email'] ?? ''); $pw = (string)($in['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) err('Bitte eine gültige E-Mail-Adresse eingeben.');
    if (strlen($pw) < 8 || strlen($pw) > 200) err('Das Passwort muss mindestens 8 Zeichen haben.');
    if (!rate_hit('reg:' . client_ip(), 10, 3600)) err('Zu viele Versuche. Bitte später erneut versuchen.', 429);
    $role = 'user';
    $adminMail = norm_email(cfg()['admin_email'] ?? '');
    if ($adminMail !== '' && $email === $adminMail) {
        $code = (string)(cfg()['admin_code'] ?? '');
        if ($code === '' || strpos($code, 'HIER_') === 0) err('Der Admin-Code ist in db-config.php noch nicht gesetzt.');
        if (!hash_equals($code, (string)($in['admin_code'] ?? ''))) err('Für dieses Konto wird der Admin-Code benötigt.', 403, ['code' => 'admin_code']);
        $role = 'admin';
    }
    try {
        db()->prepare('INSERT INTO bm_users (email, pass_hash, role, ai_enabled, created_at, last_login) VALUES (?, ?, ?, 0, ?, ?)')
            ->execute([$email, password_hash($pw, PASSWORD_DEFAULT), $role, time(), time()]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') err('Diese E-Mail-Adresse ist bereits registriert.', 409);
        err('Registrierung fehlgeschlagen.', 500);
    }
    $uid = (int)db()->lastInsertId();
    session_start_for($uid);
    jout(200, ['ok' => true]);
}

case 'login': {
    $email = norm_email($in['email'] ?? ''); $pw = (string)($in['password'] ?? '');
    if ($email === '' || $pw === '') err('Bitte E-Mail und Passwort eingeben.');
    if (!rate_hit('lip:' . client_ip(), 20, 900) || !rate_hit('lem:' . $email, 8, 900)) err('Zu viele Versuche. Bitte in einigen Minuten erneut versuchen.', 429);
    $st = db()->prepare('SELECT id, pass_hash FROM bm_users WHERE email = ?');
    $st->execute([$email]);
    $r = $st->fetch();
    if (!$r || !password_verify($pw, (string)$r['pass_hash'])) err('E-Mail oder Passwort stimmt nicht.', 401);
    db()->prepare('UPDATE bm_users SET last_login = ? WHERE id = ?')->execute([time(), (int)$r['id']]);
    session_start_for((int)$r['id']);
    jout(200, ['ok' => true]);
}

case 'logout':
    if (db_configured()) session_end();
    jout(200, ['ok' => true]);

case 'forgot': {
    $email = norm_email($in['email'] ?? '');
    $okMsg = 'Wenn die E-Mail-Adresse registriert ist, wurde eine Mail mit einem Link zum Zurücksetzen gesendet.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) err('Bitte eine gültige E-Mail-Adresse eingeben.');
    if (!rate_hit('fip:' . client_ip(), 6, 3600) || !rate_hit('fem:' . $email, 3, 3600)) err('Zu viele Anfragen. Bitte später erneut versuchen.', 429);
    $st = db()->prepare('SELECT id FROM bm_users WHERE email = ?');
    $st->execute([$email]);
    $uid = $st->fetchColumn();
    if ($uid) {
        $tok = bin2hex(random_bytes(32));
        db()->prepare('DELETE FROM bm_resets WHERE user_id = ? OR expires < ?')->execute([(int)$uid, time()]);
        db()->prepare('INSERT INTO bm_resets (token_hash, user_id, expires) VALUES (?, ?, ?)')->execute([hash('sha256', $tok), (int)$uid, time() + 3600]);
        $base = rtrim((string)(cfg()['base_url'] ?? ''), '/');
        if ($base === '') $base = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
        send_reset_mail($email, $base . '/?reset=' . $tok);
    }
    jout(200, ['ok' => true, 'message' => $okMsg]);
}

case 'reset': {
    $tok = (string)($in['token'] ?? ''); $pw = (string)($in['password'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $tok)) err('Der Link ist ungültig.');
    if (strlen($pw) < 8 || strlen($pw) > 200) err('Das Passwort muss mindestens 8 Zeichen haben.');
    if (!rate_hit('rip:' . client_ip(), 20, 3600)) err('Zu viele Versuche. Bitte später erneut versuchen.', 429);
    $st = db()->prepare('SELECT user_id FROM bm_resets WHERE token_hash = ? AND expires > ?');
    $st->execute([hash('sha256', $tok), time()]);
    $uid = $st->fetchColumn();
    if (!$uid) err('Der Link ist abgelaufen oder wurde schon benutzt. Bitte „Passwort vergessen“ erneut anfordern.', 410);
    db()->prepare('UPDATE bm_users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$uid]);
    db()->prepare('DELETE FROM bm_resets WHERE user_id = ?')->execute([(int)$uid]);
    db()->prepare('DELETE FROM bm_sessions WHERE user_id = ?')->execute([(int)$uid]);
    jout(200, ['ok' => true]);
}

case 'sync_get': {
    $u = need_user();
    $st = db()->prepare('SELECT data FROM bm_users WHERE id = ?');
    $st->execute([$u['id']]);
    $v = $st->fetchColumn();
    jout(200, ['data' => $v ? json_decode((string)$v, true) : null]);
}

case 'sync_put': {
    $u = need_user();
    $d = $in['data'] ?? null;
    if (!is_array($d)) err('Ungültige Daten.');
    $clean = [
        'bm' => [], 'hist' => [],
        'theme' => (($d['theme'] ?? '') === 'light') ? 'light' : 'dark',
        'useproxy' => !empty($d['useproxy']), 'extonly' => !empty($d['extonly']),
    ];
    foreach (array_slice((array)($d['bm'] ?? []), 0, 500) as $b) {
        if (is_array($b) && isset($b['u']) && is_string($b['u'])) $clean['bm'][] = ['t' => mb_substr((string)($b['t'] ?? ''), 0, 120, 'UTF-8'), 'u' => mb_substr($b['u'], 0, 800, 'UTF-8')];
    }
    foreach (array_slice((array)($d['hist'] ?? []), 0, 300) as $h) {
        if (is_array($h) && isset($h['u']) && is_string($h['u'])) $clean['hist'][] = ['t' => mb_substr((string)($h['t'] ?? ''), 0, 120, 'UTF-8'), 'u' => mb_substr($h['u'], 0, 800, 'UTF-8'), 'd' => (int)($h['d'] ?? 0)];
    }
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > 600000) err('Daten zu groß.', 413);
    db()->prepare('UPDATE bm_users SET data = ? WHERE id = ?')->execute([$json, $u['id']]);
    jout(200, ['ok' => true]);
}

case 'change_password': {
    $u = need_user();
    $old = (string)($in['old'] ?? ''); $new = (string)($in['new'] ?? '');
    if (strlen($new) < 8 || strlen($new) > 200) err('Das neue Passwort muss mindestens 8 Zeichen haben.');
    if (!rate_hit('cpw:' . $u['id'], 8, 900)) err('Zu viele Versuche. Bitte später erneut versuchen.', 429);
    $st = db()->prepare('SELECT pass_hash FROM bm_users WHERE id = ?');
    $st->execute([$u['id']]);
    if (!password_verify($old, (string)$st->fetchColumn())) err('Das aktuelle Passwort stimmt nicht.', 403);
    db()->prepare('UPDATE bm_users SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
    jout(200, ['ok' => true]);
}

case 'delete_account': {
    $u = need_user();
    if ($u['role'] === 'admin') err('Das Admin-Konto kann hier nicht gelöscht werden.', 403);
    if (!rate_hit('dacc:' . $u['id'], 8, 900)) err('Zu viele Versuche. Bitte später erneut versuchen.', 429);
    $st = db()->prepare('SELECT pass_hash FROM bm_users WHERE id = ?');
    $st->execute([$u['id']]);
    if (!password_verify((string)($in['password'] ?? ''), (string)$st->fetchColumn())) err('Das Passwort stimmt nicht.', 403);
    db()->prepare('DELETE FROM bm_sessions WHERE user_id = ?')->execute([$u['id']]);
    db()->prepare('DELETE FROM bm_resets WHERE user_id = ?')->execute([$u['id']]);
    db()->prepare('DELETE FROM bm_users WHERE id = ?')->execute([$u['id']]);
    cookie_set('', time() - 3600);
    jout(200, ['ok' => true]);
}

case 'admin_users': {
    need_admin();
    $rows = db()->query('SELECT id, email, role, ai_enabled, created_at, last_login FROM bm_users ORDER BY id DESC LIMIT 500')->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['ai_enabled'] = (int)$r['ai_enabled']; $r['created_at'] = (int)$r['created_at']; $r['last_login'] = $r['last_login'] === null ? null : (int)$r['last_login']; }
    jout(200, ['users' => $rows]);
}

case 'admin_user_set': {
    $adm = need_admin();
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) err('Ungültiger Benutzer.');
    if (!empty($in['delete'])) {
        if ($id === $adm['id']) err('Du kannst dich nicht selbst löschen.', 403);
        db()->prepare('DELETE FROM bm_sessions WHERE user_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM bm_resets WHERE user_id = ?')->execute([$id]);
        db()->prepare('DELETE FROM bm_users WHERE id = ?')->execute([$id]);
        jout(200, ['ok' => true]);
    }
    if (array_key_exists('ai', $in)) {
        db()->prepare('UPDATE bm_users SET ai_enabled = ? WHERE id = ?')->execute([empty($in['ai']) ? 0 : 1, $id]);
    }
    jout(200, ['ok' => true]);
}

case 'admin_cfg_set': {
    need_admin();
    $c = ['links' => clean_links($in['links'] ?? []), 'ext' => clean_links($in['ext'] ?? [])];
    db()->prepare('REPLACE INTO bm_config (k, v) VALUES (?, ?)')->execute(['site', json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    jout(200, ['ok' => true, 'cfg' => $c]);
}

default:
    err('Unbekannte Aktion.', 404);
}
