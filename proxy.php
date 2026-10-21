<?php
// BM Browser – Seiten-Proxy
// Lädt eine Webseite auf dem Server, entfernt die Sperren gegen Einbetten (X-Frame-Options / CSP)
// und liefert sie so aus, dass sie direkt im BM Browser angezeigt wird.
// Hochladen in denselben Ordner wie index.html (benötigt PHP mit cURL).

$NUR_VON_EIGENER_SEITE = true;   // true = nur aus dem BM Browser heraus nutzbar (schützt vor Missbrauch)
$MAX_BYTES = 15 * 1024 * 1024;
$UA = 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Mobile Safari/537.36';
$SANDBOX = 'sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads';

@set_time_limit(40);
error_reporting(0);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fail($titel, $text, $u = '') {
  global $SANDBOX;
  header('Content-Type: text/html; charset=utf-8');
  header('Content-Security-Policy: ' . $SANDBOX);
  header('Cache-Control: no-store');
  echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
     . '<body style="margin:0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;'
     . 'padding:24px;text-align:center;font:16px system-ui,sans-serif;background:#0b1424;color:#f1f6ff">'
     . '<h2 style="margin:0">' . h($titel) . '</h2>'
     . '<p style="margin:0;color:#8ea3c4;max-width:24em">' . h($text) . '</p>';
  if (preg_match('~^https?://~i', $u)) {
    echo '<a href="' . h($u) . '" target="_blank" rel="noopener" style="background:#5cc8ff;color:#04223a;font-weight:700;'
       . 'border-radius:12px;padding:12px 18px;text-decoration:none">Im Browser öffnen</a>';
  }
  echo '</body>';
  exit;
}

// Relative Adresse zu absoluter Adresse auflösen
function absUrl($rel, $base) {
  if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $rel)) return $rel;
  $b = parse_url($base);
  $scheme = $b['scheme'];
  $host = $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
  if (strpos($rel, '//') === 0) return $scheme . ':' . $rel;
  if ($rel === '' || $rel[0] === '#') return $base;
  if ($rel[0] === '/') return "$scheme://$host$rel";
  $path = $b['path'] ?? '/';
  if ($rel[0] === '?') return "$scheme://$host$path$rel";
  $q = '';
  if (($i = strpos($rel, '?')) !== false) { $q = substr($rel, $i); $rel = substr($rel, 0, $i); }
  $full = preg_replace('~/[^/]*$~', '/', $path) . $rel;
  $trail = substr($full, -1) === '/' ? '/' : '';
  $parts = [];
  foreach (explode('/', $full) as $seg) {
    if ($seg === '' || $seg === '.') continue;
    if ($seg === '..') { array_pop($parts); continue; }
    $parts[] = $seg;
  }
  return "$scheme://$host/" . implode('/', $parts) . ($parts ? $trail : '') . $q;
}

// Ziel prüfen: nur http/https, keine internen Adressen (Schutz vor SSRF)
function zielPruefen($url) {
  $p = parse_url($url);
  if (!$p || empty($p['host']) || isset($p['user']) || isset($p['pass'])) return false;
  $scheme = strtolower($p['scheme'] ?? '');
  if ($scheme !== 'http' && $scheme !== 'https') return false;
  $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
  if (!in_array($port, [80, 443, 8080, 8443], true)) return false;
  $host = $p['host'];
  if ($host[0] === '[') return false;
  $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
  if (!$ips) return false;
  foreach ($ips as $ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
  }
  return [$host, $port, $ips[0]];
}

// Nur aus dem BM Browser heraus erlauben
if ($NUR_VON_EIGENER_SEITE) {
  $rh = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
  $me = preg_replace('~:\d+$~', '', $_SERVER['HTTP_HOST'] ?? '');
  if (!$rh || strcasecmp($rh, $me) !== 0) {
    fail('Nur im BM Browser', 'Der Proxy lässt sich nur aus dem BM Browser heraus nutzen. (Einstellung $NUR_VON_EIGENER_SEITE in proxy.php)');
  }
}

$method = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'POST' : 'GET';

// Zieladresse bestimmen (Link: ?u=…, GET-Formular: ?__bm_u=…&Feld=Wert)
$u = $_GET['__bm_u'] ?? ($_GET['u'] ?? '');
if (!is_string($u) || $u === '') fail('Keine Adresse', 'Es wurde keine Webseite angegeben.');
$u = preg_replace('~#.*$~', '', trim($u));
if (isset($_GET['__bm_u'])) {
  $u = preg_replace('~\?.*$~', '', $u);
  $pairs = array_filter(explode('&', $_SERVER['QUERY_STRING'] ?? ''), function ($p) {
    return $p !== '' && strpos($p, '__bm_u=') !== 0;
  });
  if ($pairs) $u .= '?' . implode('&', $pairs);
}

$url = $u;
$reqBody = $method === 'POST' ? file_get_contents('php://input') : null;
$ctReq = $_SERVER['CONTENT_TYPE'] ?? '';

$head = []; $status = 0; $body = '';
for ($hop = 0; $hop < 6; $hop++) {
  $z = zielPruefen($url);
  if (!$z) fail('Adresse nicht erlaubt', 'Diese Adresse kann nicht geöffnet werden.', preg_match('~^https?://~i', $url) ? $url : '');
  list($host, $port, $ip) = $z;
  $head = []; $status = 0; $body = ''; $zuGross = false;
  $hdr = ['Accept: text/html,application/xhtml+xml,*/*;q=0.8', 'Accept-Language: de-DE,de;q=0.9,en;q=0.5'];
  $opts = [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_ENCODING => '',
    CURLOPT_TIMEOUT => 25,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_USERAGENT => $UA,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$head, &$status) {
      $l = trim($line);
      if (preg_match('~^HTTP/\S+\s+(\d{3})~', $l, $m)) { $head = []; $status = (int)$m[1]; }
      elseif (($p = strpos($l, ':')) !== false) { $head[strtolower(trim(substr($l, 0, $p)))] = trim(substr($l, $p + 1)); }
      return strlen($line);
    },
    CURLOPT_WRITEFUNCTION => function ($ch, $d) use (&$body, &$zuGross, $MAX_BYTES) {
      if (strlen($body) + strlen($d) > $MAX_BYTES) { $zuGross = true; return 0; }
      $body .= $d;
      return strlen($d);
    },
  ];
  // DNS-Ergebnis festnageln, damit zwischen Prüfung und Abruf keine andere Adresse gilt
  if (!filter_var($host, FILTER_VALIDATE_IP)) $opts[CURLOPT_RESOLVE] = ["$host:$port:$ip"];
  if ($method === 'POST') {
    $opts[CURLOPT_POST] = true;
    $opts[CURLOPT_POSTFIELDS] = $reqBody;
    if ($ctReq !== '') $hdr[] = 'Content-Type: ' . $ctReq;
  }
  $opts[CURLOPT_HTTPHEADER] = $hdr;
  $ch = curl_init($url);
  curl_setopt_array($ch, $opts);
  $ok = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  if ($zuGross) fail('Seite zu groß', 'Der Inhalt ist größer als 15 MB.', $url);
  if ($ok === false) fail('Seite nicht erreichbar', $err ?: 'Die Verbindung ist fehlgeschlagen.', $url);

  $loc = $head['location'] ?? '';
  if ($status >= 300 && $status < 400 && $loc !== '') {
    $url = preg_replace('~#.*$~', '', absUrl(trim($loc), $url));
    if ($status !== 307 && $status !== 308) { $method = 'GET'; $reqBody = null; }
    continue;
  }
  break;
}
if ($hop >= 6) fail('Zu viele Weiterleitungen', 'Die Seite leitet im Kreis weiter.', $url);

$ct = $head['content-type'] ?? 'application/octet-stream';
header('Content-Security-Policy: ' . $SANDBOX);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if ($status >= 100 && $status < 600) http_response_code($status);

// Alles außer HTML (Bilder, PDF, Downloads …) unverändert durchreichen
if (!preg_match('~^\s*(text/html|application/xhtml\+xml)~i', $ct)) {
  header('Content-Type: ' . $ct);
  if (isset($head['content-disposition'])) header('Content-Disposition: ' . $head['content-disposition']);
  echo $body;
  exit;
}

// HTML: Zeichensatz bestimmen
$cs = 'utf-8';
if (preg_match('~charset\s*=\s*["\']?([\w\-]+)~i', $ct, $m)) $cs = $m[1];
elseif (preg_match('~<meta[^>]+charset\s*=\s*["\']?([\w\-]+)~i', substr($body, 0, 4096), $m)) $cs = $m[1];
header('Content-Type: text/html; charset=' . $cs);

// Eigene <base>- und CSP-Angaben der Seite entfernen
$body = preg_replace('~<base\b[^>]*>~i', '', $body);
$body = preg_replace('~<meta\b[^>]*http-equiv\s*=\s*["\']?content-security-policy[^>]*>~i', '', $body);

$self = ((($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http')
      . '://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?');

$js = <<<'JS'
(function(){
var P=%%P%%,B=%%B%%;
function px(u){return P+'?u='+encodeURIComponent(u)}
function abs(h){try{return new URL(h,B).href}catch(e){return null}}
function tell(){try{parent.postMessage({bmUrl:B,bmTitle:document.title},'*')}catch(e){}}
document.addEventListener('click',function(e){
  if(e.defaultPrevented||e.button>0||e.metaKey||e.ctrlKey||e.shiftKey)return;
  var a=e.target.closest&&e.target.closest('a[href],area[href]');
  if(!a||typeof a.href!=='string')return;
  var h=a.getAttribute('href')||'';
  if(h.charAt(0)==='#'){
    e.preventDefault();
    var id=decodeURIComponent(h.slice(1));
    var el=id?(document.getElementById(id)||document.getElementsByName(id)[0]):document.body;
    if(el&&el.scrollIntoView)el.scrollIntoView();
    return;
  }
  if(a.hasAttribute('download')||/^(javascript|mailto|tel|sms|data|blob):/i.test(h))return;
  var u=abs(a.href);
  if(!u||!/^https?:/i.test(u))return;
  e.preventDefault();
  if(a.target==='_blank'||a.target==='_new'){try{parent.postMessage({bmOpen:u},'*')}catch(x){}return}
  location.href=px(u);
});
document.addEventListener('submit',function(e){
  var f=e.target;
  if(!f||f.tagName!=='FORM'||e.defaultPrevented)return;
  var act=f.getAttribute('action');
  var u=abs(act?act:B);
  if(!u||!/^https?:/i.test(u))return;
  f.setAttribute('target','_self');
  if((f.getAttribute('method')||'get').toLowerCase()==='post'){f.setAttribute('action',px(u))}
  else{
    f.setAttribute('action',P);
    var i=f.querySelector('input[name="__bm_u"]');
    if(!i){i=document.createElement('input');i.type='hidden';i.name='__bm_u';f.appendChild(i)}
    i.value=u;
  }
});
window.open=function(u){var x=u&&abs(u);if(x&&/^https?:/i.test(x)){try{parent.postMessage({bmOpen:x},'*')}catch(e){}}return null};
tell();
document.addEventListener('DOMContentLoaded',tell);
window.addEventListener('load',tell);
})();
JS;
$js = str_replace(
  ['%%P%%', '%%B%%'],
  [json_encode($self, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG), json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)],
  $js
);
$inject = '<base href="' . h($url) . '">'
        . '<meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">'
        . '<script>' . $js . '</script>';

if (preg_match('~<head\b[^>]*>~i', $body, $m, PREG_OFFSET_CAPTURE)
 || preg_match('~<html\b[^>]*>~i', $body, $m, PREG_OFFSET_CAPTURE)
 || preg_match('~^\s*<!doctype[^>]*>~i', $body, $m, PREG_OFFSET_CAPTURE)) {
  $pos = $m[0][1] + strlen($m[0][0]);
} else {
  $pos = 0;
}
echo substr($body, 0, $pos) . $inject . substr($body, $pos);
