<?php
// BM Browser – gemeinsame Funktionen (Datenbank, Anmeldung). Wird von api.php, ai.php geladen.
declare(strict_types=1);

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/db-config.php';
        $c = is_file($f) ? (array)(require $f) : [];
    }
    return $c;
}

function jout(int $status, array $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function db_configured(): bool {
    $c = cfg();
    $p = (string)($c['db_pass'] ?? '');
    return $p !== '' && strpos($p, 'HIER_') !== 0 && !empty($c['db_name']) && !empty($c['db_user']);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!db_configured()) jout(503, ['error' => 'Die Anmeldung ist noch nicht eingerichtet (db-config.php).', 'code' => 'nosetup']);
    $c = cfg();
    try {
        $pdo = new PDO(
            'mysql:host=' . ($c['db_host'] ?? 'localhost') . ';dbname=' . $c['db_name'] . ';charset=utf8mb4',
            (string)$c['db_user'], (string)$c['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    } catch (Throwable $e) {
        jout(503, ['error' => 'Datenbankverbindung fehlgeschlagen. Zugangsdaten in db-config.php prüfen.', 'code' => 'nodb']);
    }
    schema($pdo);
    return $pdo;
}

function schema(PDO $p): void {
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $p->exec("CREATE TABLE IF NOT EXISTS bm_users (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        pass_hash VARCHAR(255) NOT NULL,
        role VARCHAR(10) NOT NULL DEFAULT 'user',
        ai_enabled TINYINT(1) NOT NULL DEFAULT 0,
        data MEDIUMTEXT NULL,
        created_at INT UNSIGNED NOT NULL,
        last_login INT UNSIGNED NULL
    ) $t");
    $p->exec("CREATE TABLE IF NOT EXISTS bm_sessions (
        token_hash CHAR(64) PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        expires INT UNSIGNED NOT NULL,
        INDEX (user_id)
    ) $t");
    $p->exec("CREATE TABLE IF NOT EXISTS bm_resets (
        token_hash CHAR(64) PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        expires INT UNSIGNED NOT NULL,
        INDEX (user_id)
    ) $t");
    $p->exec("CREATE TABLE IF NOT EXISTS bm_attempts (
        k VARCHAR(120) NOT NULL,
        t INT UNSIGNED NOT NULL,
        INDEX (k)
    ) $t");
    $p->exec("CREATE TABLE IF NOT EXISTS bm_config (
        k VARCHAR(40) PRIMARY KEY,
        v MEDIUMTEXT NOT NULL
    ) $t");
}

function is_https(): bool {
    return (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/** true = Anfrage erlaubt, false = Limit erreicht */
function rate_hit(string $key, int $max, int $window): bool {
    $p = db();
    $key = substr($key, 0, 8) . substr(hash('sha256', $key), 0, 40);
    $p->prepare('DELETE FROM bm_attempts WHERE t < ?')->execute([time() - 86400]);
    $st = $p->prepare('SELECT COUNT(*) FROM bm_attempts WHERE k = ? AND t > ?');
    $st->execute([$key, time() - $window]);
    if ((int)$st->fetchColumn() >= $max) return false;
    $p->prepare('INSERT INTO bm_attempts (k, t) VALUES (?, ?)')->execute([$key, time()]);
    return true;
}

/** Angemeldeter Benutzer oder null. */
function me(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    $tok = $_COOKIE['bmsid'] ?? '';
    if (is_string($tok) && preg_match('/^[a-f0-9]{64}$/', $tok) && db_configured()) {
        $st = db()->prepare('SELECT u.id, u.email, u.role, u.ai_enabled FROM bm_sessions s JOIN bm_users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires > ?');
        $st->execute([hash('sha256', $tok), time()]);
        $r = $st->fetch();
        if ($r) {
            $u = ['id' => (int)$r['id'], 'email' => (string)$r['email'], 'role' => (string)$r['role'],
                  'ai' => ($r['role'] === 'admin' || (int)$r['ai_enabled'] === 1)];
        }
    }
    return $u;
}

function cookie_set(string $value, int $expires): void {
    setcookie('bmsid', $value, ['expires' => $expires, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

function session_start_for(int $uid): void {
    $tok = bin2hex(random_bytes(32));
    $exp = time() + 60 * 86400;
    $p = db();
    $p->prepare('INSERT INTO bm_sessions (token_hash, user_id, expires) VALUES (?, ?, ?)')->execute([hash('sha256', $tok), $uid, $exp]);
    if (random_int(1, 20) === 1) $p->prepare('DELETE FROM bm_sessions WHERE expires < ?')->execute([time()]);
    cookie_set($tok, $exp);
}

function session_end(): void {
    $tok = $_COOKIE['bmsid'] ?? '';
    if (is_string($tok) && preg_match('/^[a-f0-9]{64}$/', $tok)) {
        db()->prepare('DELETE FROM bm_sessions WHERE token_hash = ?')->execute([hash('sha256', $tok)]);
    }
    cookie_set('', time() - 3600);
}
