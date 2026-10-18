<?php
/* ============================================================
   KAYYA-KI Proxy  ·  macht die KAYYA-KI ECHT (Engine: Claude)
   ------------------------------------------------------------
   EINRICHTUNG (einmalig, ~2 Minuten):
   1) Anthropic-Konto: https://console.anthropic.com -> API-Key
   2) Key NICHT hier eintragen, sondern in die separate Datei
      kayya-key.php (eine Zeile):   <?php return 'sk-ant-...';
      -> liegt neben dieser Datei auf dem Server. So kann diese
      Datei jederzeit neu hochgeladen werden, ohne den Key zu verlieren.
   3) WICHTIG: In der Anthropic-Konsole ein Monats-Spend-Limit setzen!
   ============================================================ */

$API_KEY = 'HIER-DEIN-ANTHROPIC-API-KEY';
if (is_file(__DIR__.'/kayya-key.php')) { $k = include __DIR__.'/kayya-key.php'; if (is_string($k) && trim($k) !== '') $API_KEY = trim($k); }
const MODEL   = 'claude-sonnet-5';                    // Qualitaet; guenstiger: 'claude-haiku-4-5-20251001'
const MAX_TOKENS = 600;

/* ---- Schutz: nur Aufrufe von der eigenen Domain ---- */
$allowed = ['kayya.de', 'www.kayya.de', 'kayya.org', 'www.kayya.org', 'localhost'];
$refHost = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST) ?: '';
$origin  = parse_url($_SERVER['HTTP_ORIGIN']  ?? '', PHP_URL_HOST) ?: '';
$hostOk  = in_array($refHost, $allowed) || in_array($origin, $allowed);
header('Content-Type: application/json; charset=utf-8');

/* ---- Ping: Demo prueft, ob der Proxy bereit ist ---- */
if (isset($_GET['ping'])) {
  echo json_encode(['ok'=>true, 'configured'=> ($API_KEY !== 'HIER-DEIN-ANTHROPIC-API-KEY')]);
  exit;
}
if (!$hostOk) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
if ($API_KEY === 'HIER-DEIN-ANTHROPIC-API-KEY') { http_response_code(503); echo json_encode(['error'=>'kein API-Key eingetragen']); exit; }

/* ---- sehr einfaches Rate-Limit (60 Anfragen / 10 Min je IP) ---- */
$rlFile = sys_get_temp_dir().'/kayya_rl_'.md5(__DIR__.$_SERVER['REMOTE_ADDR'] ?? 'x');
$hits = file_exists($rlFile) ? json_decode(file_get_contents($rlFile), true) : [];
$hits = array_values(array_filter((array)$hits, fn($t)=> $t > time()-600));
if (count($hits) >= 60) { http_response_code(429); echo json_encode(['error'=>'rate limit']); exit; }
$hits[] = time(); file_put_contents($rlFile, json_encode($hits));

/* ---- Anfrage lesen ---- */
$in    = json_decode(file_get_contents('php://input'), true) ?: [];
$rolle = substr(trim($in['rolle'] ?? 'Nutzer'), 0, 60);
$frage = substr(trim($in['frage'] ?? ''), 0, 2000);
$ctx   = substr(trim($in['kontext'] ?? ''), 0, 4000);
if ($frage === '') { echo json_encode(['error'=>'leere Frage']); exit; }

$system = "Du bist die KAȲȲA-KI, der Assistent der Plattform KAȲȲA OS der KAȲȲA GmbH, eines "
 ."Bildungs- und Eingliederungstraegers (Engine: Claude von Anthropic - auf Nachfrage transparent sagen). "
 ."Der Nutzer arbeitet gerade im Frontend: {$rolle}. Antworte auf Deutsch, knapp (max. 120 Woerter), "
 ."konkret und duzend. Grundregeln der Plattform: (1) Human-in-the-Loop - du erzeugst nur Entwuerfe "
 ."und Empfehlungen, Entscheidungen treffen Menschen; kennzeichne Entwuerfe als 'KI-Entwurf - Freigabe "
 ."erforderlich'. (2) Keine Rechtsberatung (nur erste Einordnung mit Hinweis). (3) Keine Gesundheitsdaten "
 ."erfragen. (4) Wenn dir Live-Daten fehlen, sag das ehrlich, statt Zahlen zu erfinden. "
 .($ctx !== '' ? "Aktueller Demo-Kontext (synthetische Uebungsdaten): {$ctx}" : "");

/* ---- Anthropic Messages API ---- */
$payload = json_encode([
  'model' => MODEL,
  'max_tokens' => MAX_TOKENS,
  'system' => $system,
  'messages' => [['role'=>'user','content'=>$frage]],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 45,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $payload,
  CURLOPT_HTTPHEADER => [
    'content-type: application/json',
    'x-api-key: '.$API_KEY,
    'anthropic-version: 2023-06-01',
  ],
]);
$res  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($res === false) { http_response_code(502); echo json_encode(['error'=>'Verbindung: '.$err]); exit; }
$data = json_decode($res, true);
if ($code >= 400 || !isset($data['content'][0]['text'])) {
  http_response_code(502);
  echo json_encode(['error'=>'API '.$code.': '.substr($data['error']['message'] ?? $res, 0, 200)]);
  exit;
}
echo json_encode(['text'=>$data['content'][0]['text'], 'model'=>MODEL], JSON_UNESCAPED_UNICODE);
