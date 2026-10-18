<?php
/* ============================================================
   KAYYA TED-Proxy · holt ECHTE EU-Ausschreibungen live
   (Bildungsdienstleistungen CPV 805xx, Kaeuferland Deutschland)
   Kein API-Key noetig. Einfach mit index.html nach public_html laden.
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');

$allowed = ['kayya.de', 'www.kayya.de', 'kayya.org', 'www.kayya.org', 'localhost'];
$refHost = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST) ?: '';
$origin  = parse_url($_SERVER['HTTP_ORIGIN']  ?? '', PHP_URL_HOST) ?: '';
$hostOk  = in_array($refHost, $allowed) || in_array($origin, $allowed) || ($refHost==='' && $origin==='');

if (isset($_GET['ping'])) { echo json_encode(['ok'=>true,'configured'=>true]); exit; }
if (!$hostOk) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

/* Cache 30 Min (schont die EU-API) */
$cacheFile = sys_get_temp_dir().'/kayya_ted_'.md5(__DIR__).'.json';
if (file_exists($cacheFile) && filemtime($cacheFile) > time()-1800 && !isset($_GET['force'])) {
  echo file_get_contents($cacheFile); exit;
}

$payload = json_encode([
  'query'  => 'classification-cpv IN (80500000 80530000 80400000 80420000) AND buyer-country=DEU AND notice-type IN (cn-standard cn-social pin-cfc-standard) SORT BY publication-date DESC',
  'fields' => ['ND','TI','buyer-name','deadline-date-lot','publication-date'],
  'limit'  => 50,
]);
$ch = curl_init('https://api.ted.europa.eu/v3/notices/search');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>30, CURLOPT_POST=>true,
  CURLOPT_POSTFIELDS=>$payload,
  CURLOPT_HTTPHEADER=>['content-type: application/json'],
]);
$res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($res === false || $code >= 400) { http_response_code(502); echo json_encode(['error'=>'TED '.$code]); exit; }

$r = json_decode($res, true); $out = []; $today = new DateTime('today');
foreach (($r['notices'] ?? []) as $n) {
  if (count($out) >= 6) break;
  $ti = $n['TI']['deu'] ?? $n['TI']['eng'] ?? (is_array($n['TI'] ?? null) ? reset($n['TI']) : null);
  if (is_array($ti)) $ti = $ti[0] ?? null;
  if (!$ti) continue;
  $ti = trim(preg_replace('/^Deutschland\s*[\x{2013}\x{2014}-]\s*/u', '', $ti));
  $b = $n['buyer-name']['deu'] ?? $n['buyer-name']['eng'] ?? (is_array($n['buyer-name'] ?? null) ? reset($n['buyer-name']) : 'Vergabestelle (siehe TED)');
  if (is_array($b)) $b = $b[0] ?? 'Vergabestelle (siehe TED)';
  $dl = $n['deadline-date-lot'] ?? []; if (!is_array($dl)) $dl = [$dl];
  $best = null;
  foreach ($dl as $d) { if (!$d) continue;
    try { $dt = new DateTime($d); if ($dt >= $today && (!$best || $dt < $best)) $best = $dt; } catch (Exception $e) {} }
  if (!$best) continue;
  $pub = ''; try { $pub = (new DateTime($n['publication-date']))->format('d.m.Y'); } catch (Exception $e) {}
  $out[] = ['nd'=>(string)$n['ND'], 'titel'=>$ti, 'stelle'=>$b, 'frist'=>$best->format('d.m.Y'), 'pub'=>$pub];
}
$json = json_encode(['stand'=>date('d.m.Y H:i').' Uhr', 'tenders'=>$out], JSON_UNESCAPED_UNICODE);
file_put_contents($cacheFile, $json);
echo $json;
