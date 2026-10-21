<?php
// Such-Proxy für den BM Browser: Google (über die Programmable-Search-API, wenn in db-config.php eingetragen),
// sonst als Ersatz DuckDuckGo (HTML). Liefert die Ergebnisse als JSON.
// Hochladen in denselben Ordner wie index.html (z. B. bomaex.de/browser/suche.php)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$q = trim($_GET['q'] ?? '');
if ($q === '' || mb_strlen($q) > 200) { echo json_encode(['items'=>[], 'error'=>'leer']); exit; }
$cf = __DIR__ . '/db-config.php';
$c = is_file($cf) ? (array)(require $cf) : [];
$gk = (string)($c['google_api_key'] ?? ''); $cx = (string)($c['google_cx'] ?? '');
if ($gk !== '' && $cx !== '' && strpos($gk, 'HIER_') !== 0) {
  $g = curl_init('https://www.googleapis.com/customsearch/v1?' . http_build_query(['key'=>$gk, 'cx'=>$cx, 'q'=>$q, 'hl'=>'de', 'num'=>10]));
  curl_setopt_array($g, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
  $gr = curl_exec($g); $gc = curl_getinfo($g, CURLINFO_HTTP_CODE); curl_close($g);
  $gd = $gr ? json_decode($gr, true) : null;
  if ($gc === 200 && !empty($gd['items'])) {
    $gi = [];
    foreach ($gd['items'] as $it) {
      if (empty($it['link']) || !preg_match('~^https?://~i', $it['link'])) continue;
      $gi[] = ['t'=>trim((string)($it['title'] ?? $it['link'])), 'u'=>$it['link'], 's'=>trim(preg_replace('~\s+~', ' ', (string)($it['snippet'] ?? '')))];
    }
    if ($gi) { echo json_encode(['items'=>$gi, 'error'=>null, 'src'=>'google'], JSON_UNESCAPED_UNICODE); exit; }
  }
}
$ch = curl_init('https://html.duckduckgo.com/html/');
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => http_build_query(['q'=>$q, 'kl'=>'de-de']),
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_TIMEOUT => 10,
  CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36',
  CURLOPT_HTTPHEADER => ['Accept-Language: de-DE,de;q=0.9'],
]);
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if (!$html || $code >= 400) { echo json_encode(['items'=>[], 'error'=>'Suchdienst nicht erreichbar']); exit; }
libxml_use_internal_errors(true);
$doc = new DOMDocument();
$doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
$xp = new DOMXPath($doc);
$items = [];
foreach ($xp->query("//div[contains(concat(' ',normalize-space(@class),' '),' result ')]") as $n) {
  $a = $xp->query(".//a[contains(@class,'result__a')]", $n)->item(0);
  if (!$a) continue;
  $href = $a->getAttribute('href');
  if (strpos($href, '//') === 0) $href = 'https:' . $href;
  $qs = parse_url($href, PHP_URL_QUERY);
  if ($qs) { parse_str($qs, $p); if (!empty($p['uddg'])) $href = $p['uddg']; }
  if (!preg_match('~^https?://~i', $href)) continue;
  if (strpos($href, 'duckduckgo.com/y.js') !== false) continue; // Werbung
  $s = $xp->query(".//*[contains(@class,'result__snippet')]", $n)->item(0);
  $items[] = ['t'=>trim($a->textContent), 'u'=>$href, 's'=>$s ? trim($s->textContent) : ''];
  if (count($items) >= 15) break;
}
echo json_encode(['items'=>$items, 'error'=>$items ? null : 'Keine Ergebnisse'], JSON_UNESCAPED_UNICODE);
