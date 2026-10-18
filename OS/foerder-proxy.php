<?php
/* ============================================================
   KAYYA Foerder- & Arbeitsmarkt-Proxy · Quellen ohne CORS-Freigabe:
   q=jobs       -> BA-Jobsuche v6 (Stellen bundesweit; BA hat CORS abgeschaltet,
                   daher serverseitig; Antwort = BA-JSON unveraendert)
   q=ausbildung -> BA-Ausbildungssuche (Ausbildungsangebote bundesweit)
   q=wb         -> BA-Weiterbildungssuche NOW/KURSNET (bundesweit, gefoerderter Markt)
   q=bund       -> service.bund.de Ausschreibungen (RSS, unterhalb EU-Schwelle)
   Kein eigener API-Key noetig. Mit index.html hochladen. Cache 60 Min.
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');

$allowed = ['kayya.de', 'www.kayya.de', 'kayya.org', 'www.kayya.org', 'localhost'];
$refHost = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST) ?: '';
$origin  = parse_url($_SERVER['HTTP_ORIGIN']  ?? '', PHP_URL_HOST) ?: '';
$hostOk  = in_array($refHost, $allowed) || in_array($origin, $allowed) || ($refHost==='' && $origin==='');

if (isset($_GET['ping'])) { echo json_encode(['ok'=>true,'configured'=>true,'modes'=>['jobs','ausbildung','wb','bund','hh','hb','bw','nrwefre']]); exit; }
if (!$hostOk) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$modes = ['jobs','ausbildung','wb','bund','hh','hb','bw','nrwefre'];
$q = in_array($_GET['q'] ?? '', $modes, true) ? $_GET['q'] : 'wb';
$swDefault = ['jobs'=>'Pflege','ausbildung'=>'Pflege','wb'=>'Pflege','bund'=>'Eingliederung','hh'=>'','hb'=>'','bw'=>'Pflege','nrwefre'=>''];
$sw = preg_replace('/[^\p{L}\p{N} \-]/u', '', $_GET['sw'] ?? ($_GET['was'] ?? $swDefault[$q]));
$sw = mb_substr(trim($sw) ?: $swDefault[$q], 0, 40);
$wo = preg_replace('/[^\p{L}\p{N} \-]/u', '', $_GET['wo'] ?? '');
$wo = mb_substr(trim($wo), 0, 40);
$umkreis = preg_replace('/\D/', '', $_GET['umkreis'] ?? '');
$size = min(10, max(1, intval($_GET['size'] ?? 8)));

$art = preg_match('/^[0-9]$/', $_GET['art'] ?? '') ? $_GET['art'] : '';
$cacheFile = sys_get_temp_dir().'/kayya_foerder_'.md5(__DIR__.'|'.$q.'|'.$sw.'|'.$wo.'|'.$umkreis.'|'.$size.'|'.$art).'.json';
if (file_exists($cacheFile) && filemtime($cacheFile) > time()-3600 && !isset($_GET['force'])) {
  echo file_get_contents($cacheFile); exit;
}

function fetchUrl($url, $headers = [], $range = '') {
  $ch = curl_init($url);
  $base = ['Accept-Language: de-DE,de;q=0.9'];
  $hasAccept = false;
  foreach ($headers as $h0) { if (stripos($h0, 'Accept:') === 0) { $hasAccept = true; break; } }
  if (!$hasAccept) { $base[] = 'Accept: application/json'; }
  $opts = [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25,
    CURLOPT_FOLLOWLOCATION=>true, CURLOPT_HTTPHEADER=>array_merge($base,$headers),
    CURLOPT_ENCODING=>'',
    CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'];
  if ($range !== '') { $opts[CURLOPT_RANGE] = $range; }
  curl_setopt_array($ch, $opts);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  return [$res, $code];
}
function csvKopfIndex($kopf, $such) {
  foreach ($kopf as $i => $name) { if (stripos($name, $such) !== false) return $i; }
  return -1;
}
function fail($msg){ http_response_code(502); echo json_encode(['error'=>$msg]); exit; }

$items = []; $passthrough = null;

if ($q === 'jobs') {
  $p = ['was'=>$sw, 'size'=>strval($size)];
  if ($wo !== '') { $p['wo']=$wo; if($umkreis!==''){ $p['umkreis']=$umkreis; } }
  if ($art !== '') { $p['angebotsart']=$art; }
  $url = 'https://rest.arbeitsagentur.de/jobboerse/jobsuche-service/pc/v6/jobs?'.http_build_query($p);
  [$res, $code] = fetchUrl($url, ['X-API-Key: jobboerse-jobsuche']);
  if ($res === false || $code >= 400) fail('BA-Jobs '.$code);
  $j = json_decode($res, true);
  if (!is_array($j)) fail('BA-Jobs JSON');
  $passthrough = $res;   // BA-Antwort unveraendert (ergebnisliste, maxErgebnisse ...)
} elseif ($q === 'ausbildung') {
  $url = 'https://rest.arbeitsagentur.de/infosysbub/absuche/pc/v1/ausbildungsangebot?was='.rawurlencode($sw).'&size='.$size;
  [$res, $code] = fetchUrl($url, ['X-API-Key: infosysbub-absuche', 'Accept: */*']);
  if ($res === false || $code >= 400) fail('BA-Ausbildung '.$code);
  $j = json_decode($res, true);
  foreach (($j['_embedded']['termine'] ?? []) as $t0) {
    if (count($items) >= $size) break;
    $a = $t0['angebot'] ?? [];
    $ort = $t0['adresse']['ort'] ?? ($a['bildungsanbieter']['adresse']['ort'] ?? '');
    $beginn = isset($t0['beginn']) ? date('d.m.Y', intval($t0['beginn']/1000)) : '';
    $parts = array_filter([$ort, $beginn !== '' ? 'Beginn '.$beginn : '',
      $t0['unterrichtsform']['bezeichnung'] ?? '', $t0['dauer']['bezeichnung'] ?? '']);
    $items[] = ['titel'=>trim($a['titel'] ?? '(ohne Titel)'),
                'anbieter'=>trim($a['bildungsanbieter']['name'] ?? ''),
                'info'=>implode(' · ', $parts)];
  }
} elseif ($q === 'wb') {
  $url = 'https://rest.mein-now.de/now-prod/suche/pc/v1/bildungsangebot?sw='.rawurlencode($sw).'&page=0&size='.$size;
  [$res, $code] = fetchUrl($url, ['X-API-Key: infosysbub-nowsuche']);
  if ($res === false || $code >= 400) fail('NOW '.$code);
  $j = json_decode($res, true);
  $kost = ['BIS_500_EUR'=>'bis 500 €','UEBER_500_BIS_1000_EUR'=>'500–1.000 €','UEBER_1000_BIS_5000_EUR'=>'1.000–5.000 €',
           'UEBER_5000_BIS_10000_EUR'=>'5.000–10.000 €','UEBER_10000_EUR'=>'über 10.000 €'];
  foreach (($j['_embedded']['bildungsangebotDTOList'] ?? []) as $a) {
    if (count($items) >= $size) break;
    $t0 = ($a['termine'] ?? [])[0] ?? [];
    $beginn = isset($t0['beginn']) ? date('d.m.Y', intval($t0['beginn']/1000)) : '';
    $parts = array_filter([
      ($t0['adresse']['ort'] ?? $a['bildungsanbieter']['adresse']['ort'] ?? ''),
      $beginn !== '' ? 'Beginn '.$beginn : '',
      $a['unterrichtsform']['bezeichnung'] ?? '', $a['unterrichtszeit']['bezeichnung'] ?? '',
      isset($a['kostenWert']) ? 'Kosten '.($kost[$a['kostenWert']] ?? '') : '']);
    $items[] = ['titel'=>trim($a['titel'] ?? ''), 'anbieter'=>trim($a['bildungsanbieter']['name'] ?? ''),
                'info'=>implode(' · ', $parts)];
  }
} elseif ($q === 'hh') {
  /* Transparenzportal Hamburg: juengste Zuwendungs-/Subventionsdatensaetze (CKAN) */
  $url = 'https://suche.transparenz.hamburg.de/api/3/action/package_search?fq='.rawurlencode('extras_registerobject_type:subvention').'&sort='.rawurlencode('metadata_modified desc').'&rows='.$size;
  [$res, $code] = fetchUrl($url);
  if ($res === false || $code >= 400) fail('HH '.$code);
  $j = json_decode($res, true);
  foreach (($j['result']['results'] ?? []) as $d) {
    if (count($items) >= $size) break;
    $stand = isset($d['metadata_modified']) ? implode('.', array_reverse(explode('-', substr($d['metadata_modified'],0,10)))) : '';
    $items[] = ['titel'=>mb_substr(trim($d['title'] ?? '(ohne Titel)'),0,90),
                'anbieter'=>trim($d['author'] ?? ($d['organization']['title'] ?? 'Freie und Hansestadt Hamburg')),
                'info'=>trim('Transparenzportal Hamburg'.($stand!==''?' · Stand '.$stand:''))];
  }
} elseif ($q === 'hb') {
  /* Zuwendungsdatenbank Bremen: aktueller ZEBRA-Quartalsbericht (CSV via GovData aufgeloest) */
  $csvUrl = '';
  [$res, $code] = fetchUrl('https://ckan.govdata.de/api/3/action/package_search?q='.rawurlencode('title:"Zuwendungen: Quartalsberichte 2026"').'&rows=2');
  if ($res !== false && $code < 400) {
    $j = json_decode($res, true); $best = '';
    foreach (($j['result']['results'] ?? []) as $d) foreach (($d['resources'] ?? []) as $r0) {
      $u0 = $r0['url'] ?? '';
      if (preg_match('/\.csv$/i', $u0)) { if (preg_match('/20\d{6}/', basename($u0)) && strcmp(basename($u0), basename($best)) > 0) { $best = $u0; } elseif ($best === '') { $best = $u0; } }
    }
    $csvUrl = $best;
  }
  if ($csvUrl === '') $csvUrl = 'https://www.finanzen.bremen.de/sixcms/media.php/bremen02.a.13.de/download/20260701-Quartalsbericht_ZEBRA_2-2026.csv';
  [$res, $code] = fetchUrl($csvUrl, ['Accept: */*'], '0-180000');
  if ($res === false || $code >= 400) fail('HB '.$code);
  $txt = @iconv('CP1252', 'UTF-8//IGNORE', $res); if ($txt === false) $txt = $res;
  $lines = preg_split('/\r\n|\n|\r/', $txt);
  $kopf = null; $ix = [];
  foreach ($lines as $ln) {
    $f = str_getcsv($ln, ';');
    if ($kopf === null) {
      if (stripos($ln, 'Zuwendungszweck') !== false) { $kopf = $f;
        $ix = ['zweck'=>csvKopfIndex($f,'Zuwendungszweck'), 'empf'=>csvKopfIndex($f,'empf'), 'prog'=>csvKopfIndex($f,'rderprogramm'), 'summe'=>csvKopfIndex($f,'Zuwendungssumme')]; }
      continue;
    }
    if (count($items) >= $size) break;
    if (count($f) < 6) continue;
    $zweck = trim($f[$ix['zweck']] ?? ''); $empf = trim($f[$ix['empf']] ?? ''); $summe = trim($f[$ix['summe']] ?? '');
    if ($zweck === '' && $empf === '') continue;
    $items[] = ['titel'=>mb_substr($zweck !== '' ? $zweck : ($f[$ix['prog']] ?? ''),0,90),
                'anbieter'=>mb_substr($empf,0,70),
                'info'=>trim(($ix['prog']>=0 && trim($f[$ix['prog']] ?? '')!=='' ? trim($f[$ix['prog']]).' · ' : '').($summe!==''?$summe.' €':''), " ·")];
  }
  if (!count($items)) fail('HB CSV leer');
} elseif ($q === 'bw') {
  /* Weiterbildungsportal Baden-Wuerttemberg: Kurssuche */
  $url = 'https://www.fortbildung-bw.de/wbLegacy/Suche/suche/?page=1&numPerPage='.$size.'&wort='.rawurlencode($sw).'&types%5B%5D=Kurs&types%5B%5D=OnlineKurs&umkreis=-1&sortby=titel&sortorder=ASC&pageNo=1';
  [$res, $code] = fetchUrl($url);
  if ($res === false || $code >= 400) fail('BW '.$code);
  $j = json_decode($res, true);
  $gesehen = [];
  foreach (($j['data'] ?? []) as $k) {
    if (count($items) >= $size) break;
    $schl = ($k['Kurstitel'] ?? '').'|'.($k['Anbietername'] ?? '');
    if (isset($gesehen[$schl])) continue; $gesehen[$schl] = true;
    $preis = trim(explode('|', $k['preis'] ?? '')[0]);
    $typ = ($k['type'] ?? '') === 'praesenz' ? 'Präsenz' : (($k['type'] ?? '') !== '' ? ucfirst($k['type']) : '');
    $items[] = ['titel'=>mb_substr(trim($k['Kurstitel'] ?? '(ohne Titel)'),0,90),
                'anbieter'=>mb_substr(trim($k['Anbietername'] ?? ''),0,70),
                'info'=>trim(implode(' · ', array_filter([$typ, $preis])))];
  }
} elseif ($q === 'nrwefre') {
  /* EFRE/JTF NRW: Liste der gefoerderten Vorhaben (CSV, erste Zeilen) */
  [$res, $code] = fetchUrl('https://www.efre.nrw/sites/default/files/media/document/file/liste-der-vorhaben_2021-2027.csv', ['Accept: */*'], '0-220000');
  if ($res === false || $code >= 400) fail('NRW '.$code);
  $txt = $res;
  if (!preg_match('//u', $txt)) { $t2 = @iconv('CP1252','UTF-8//IGNORE',$txt); if ($t2 !== false) $txt = $t2; }
  $lines = preg_split('/\r\n|\n|\r/', $txt);
  $kopf = null; $ix = [];
  foreach ($lines as $ln) {
    $f = str_getcsv($ln, ';');
    if ($kopf === null) {
      if (stripos($ln, 'Vorhaben') !== false && stripos($ln, 'Programm') !== false) { $kopf = $f;
        $ix = ['bez'=>csvKopfIndex($f,'Bezeichnung des Vorhabens'), 'beg'=>csvKopfIndex($f,'Name des Beg'), 'kost'=>csvKopfIndex($f,'Gesamtkosten'), 'prog'=>csvKopfIndex($f,'Programm')]; }
      continue;
    }
    if (count($items) >= $size) break;
    if (count($f) < 5) continue;
    $bez = trim($f[$ix['bez']] ?? ''); $beg = trim($f[$ix['beg']] ?? ''); $kost = trim($f[$ix['kost']] ?? '');
    if ($bez === '' || strcasecmp($bez, 'op_name') === 0) continue;
    $items[] = ['titel'=>mb_substr($bez,0,90), 'anbieter'=>mb_substr($beg,0,70),
                'info'=>trim(implode(' · ', array_filter([trim($f[$ix['prog']] ?? ''), $kost!==''?('Gesamtkosten '.$kost.' €'):''])))];
  }
  if (!count($items)) fail('NRW CSV leer');
} else {
  $url = 'https://www.service.bund.de/Content/DE/Ausschreibungen/Suche/Formular.html?resultsPerPage=20'
       .'&sortOrder=dateOfIssue_dt+desc&templateQueryString='.rawurlencode($sw).'&jobsrss=true';
  [$res, $code] = fetchUrl($url);
  if ($res === false || $code >= 400) fail('bund '.$code);
  $xml = @simplexml_load_string($res);
  if ($xml === false) fail('bund xml');
  foreach (($xml->channel->item ?? []) as $it) {
    if (count($items) >= 8) break;
    $desc = trim(preg_replace('/\s+/', ' ', strip_tags((string)$it->description)));
    $stelle = ''; $frist = '';
    if (preg_match('/Vergabestelle\s*:\s*(.+?)\s*(?:Angebots?frist|Bewerbungsfrist|Abgabefrist|Ver(?:ö|&ouml;)ffentlichung|Erf(?:ü|&uuml;)llungsort|$)/us', $desc, $m)) $stelle = mb_substr(trim($m[1]), 0, 90);
    if (preg_match('/(?:Angebots?frist|Bewerbungsfrist|Abgabefrist)\s*:?\s*([0-3]?\d\.[01]?\d\.\d{4}(?:\s+\d{1,2}:\d{2})?)/u', $desc, $m)) $frist = trim($m[1]);
    if ($frist === '' && preg_match('/([0-3]?\d\.[01]?\d\.\d{4})/', $desc, $m)) $frist = $m[1];
    if ($stelle === '') $stelle = mb_substr($desc, 0, 70);
    $items[] = ['titel'=>trim((string)$it->title), 'stelle'=>$stelle, 'frist'=>$frist, 'link'=>(preg_match('#^https?://#i', trim((string)$it->link)) ? trim((string)$it->link) : '')];
  }
}

$json = $passthrough !== null ? $passthrough
      : json_encode(['stand'=>date('d.m.Y H:i').' Uhr', 'q'=>$q, 'sw'=>$sw, 'items'=>$items], JSON_UNESCAPED_UNICODE);
file_put_contents($cacheFile, $json);
echo $json;
