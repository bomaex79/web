<?php
// BM KI serverseitiger Groq-Endpunkt für All-Inkl (PHP + cURL erforderlich).
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Vary: Origin');
function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); respond(405, ['error' => ['message' => 'Nur POST-Anfragen sind erlaubt.']]);
}
// Browser-Origin-Prüfung als Zusatzschutz, kein Ersatz für Limits (kann von Skripten umgangen werden).
$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    if ($originHost === '' || !hash_equals($host, $originHost)) respond(403, ['error' => ['message' => 'Ungültige Herkunft.']]);
}
// Zugriff nur für angemeldete Konten, die vom Admin für BM KI freigegeben wurden.
require __DIR__ . '/lib.php';
if (!db_configured()) respond(503, ['error' => ['message' => 'Die Anmeldung ist noch nicht eingerichtet.']]);
$bmUser = me();
if (!$bmUser) respond(401, ['error' => ['message' => 'Bitte melde dich an, um BM KI zu nutzen.']]);
if (!$bmUser['ai']) respond(403, ['error' => ['message' => 'BM KI ist für dein Konto nicht freigeschaltet.']]);
$configFile = __DIR__ . '/ai-config.php';
if (!is_file($configFile)) respond(503, ['error' => ['message' => 'BM KI ist noch nicht vom Betreiber eingerichtet.']]);
$config = require $configFile;
$key = is_array($config) ? (string)($config['groq_api_key'] ?? '') : '';
$model = is_array($config) ? (string)($config['model'] ?? 'openai/gpt-oss-20b') : 'openai/gpt-oss-20b';
if ($key === '' || strpos($key, 'HIER_DEINEN_') === 0) respond(503, ['error' => ['message' => 'BM KI ist noch nicht eingerichtet.']]);
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 24000) respond(413, ['error' => ['message' => 'Anfrage zu groß.']]);
$input = json_decode($raw, true);
if (!is_array($input) || !isset($input['messages']) || !is_array($input['messages'])) respond(400, ['error' => ['message' => 'Ungültige Anfrage.']]);
$messages = [];
$system = 'Du bist BM KI, ein hilfreicher, freundlicher deutschsprachiger Allround-Assistent im BM Community Stil. Antworte klar und praktisch. Sage offen, wenn du etwas nicht sicher weißt.';
$messages[] = ['role' => 'system', 'content' => $system];
foreach (array_slice($input['messages'], -12) as $m) {
    if (!is_array($m)) continue;
    $role = $m['role'] ?? '';
    $content = $m['content'] ?? '';
    if (!in_array($role, ['user', 'assistant'], true) || !is_string($content) || trim($content) === '') continue;
    if (mb_strlen($content, 'UTF-8') > 4000) respond(413, ['error' => ['message' => 'Eine Nachricht ist zu lang.']]);
    $messages[] = ['role' => $role, 'content' => $content];
}
if (count($messages) < 2) respond(400, ['error' => ['message' => 'Bitte eine Frage eingeben.']]);
// Begrenzung pro Konto: bis zu 4 Anfragen/Minute und standardmäßig 100/Tag. Speicherung im temporären Verzeichnis.
$ip = 'user-' . $bmUser['id'];
$ipHash = hash('sha256', $ip . '|' . __DIR__);
$rateDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bmki_rate_' . substr(hash('sha256', __DIR__), 0, 12);
if (!is_dir($rateDir) && !@mkdir($rateDir, 0700, true) && !is_dir($rateDir)) respond(503, ['error' => ['message' => 'Nutzungsschutz ist derzeit nicht verfügbar.']]);
@chmod($rateDir, 0700);
$rateFile = $rateDir . DIRECTORY_SEPARATOR . $ipHash . '.json';
$fh = @fopen($rateFile, 'c+');
if (!$fh) respond(503, ['error' => ['message' => 'Nutzungsschutz ist derzeit nicht verfügbar.']]);
flock($fh, LOCK_EX);
$contents = stream_get_contents($fh);
$hits = json_decode($contents ?: '[]', true); if (!is_array($hits)) $hits = [];
$now = time(); $hits = array_values(array_filter($hits, fn($t) => is_int($t) && $t > $now - 86400));
$dailyLimit = max(1, min(100, (int)($config['daily_limit_per_ip'] ?? 100)));
$minuteLimit = max(1, min(10, (int)($config['minute_limit_per_ip'] ?? 4)));
$recent = array_values(array_filter($hits, fn($t) => $t > $now - 60));
if (count($hits) >= $dailyLimit || count($recent) >= $minuteLimit) {
    flock($fh, LOCK_UN); fclose($fh);
    header('Retry-After: 60'); respond(429, ['error' => ['message' => 'BM KI Nutzungslimit erreicht. Bitte später erneut versuchen.']]);
}
$hits[] = $now; ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($hits)); fflush($fh); flock($fh, LOCK_UN); fclose($fh);
if (!function_exists('curl_init')) respond(503, ['error' => ['message' => 'Auf dem Webspace ist PHP-cURL nicht verfügbar.']]);
$payload = json_encode(['model' => $model, 'messages' => $messages, 'temperature' => 0.7, 'max_tokens' => 1200], JSON_UNESCAPED_UNICODE);
$ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 45, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key]]);
$response = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
if ($response === false) respond(502, ['error' => ['message' => 'GroqCloud ist momentan nicht erreichbar.']]);
$data = json_decode($response, true);
if ($status < 200 || $status >= 300) {
    $msg = $status === 429 ? 'GroqCloud-Nutzungslimit erreicht. Bitte später erneut versuchen.' : 'KI-Anfrage fehlgeschlagen (HTTP ' . $status . ').';
    respond($status === 429 ? 429 : 502, ['error' => ['message' => $msg]]);
}
$answer = $data['choices'][0]['message']['content'] ?? null;
if (!is_string($answer) || $answer === '') respond(502, ['error' => ['message' => 'Die KI hat keine Antwort geliefert.']]);
respond(200, ['choices' => [['message' => ['content' => $answer]]]]);
