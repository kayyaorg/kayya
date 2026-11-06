<?php
/* ============================================================
   KAYYA Tor (gate.php) — serverseitiger Zugang zu den Bereichen.
   - POST {bereich, wort}: prueft das Zugangswort (SHA-256, kein Klartext hier),
     setzt ein signiertes Cookie (12 h) und antwortet {ok:true}.
   - GET ?p=<pfad>: wird von .htaccess fuer geschuetzte Seiten aufgerufen;
     liefert die Datei nur mit gueltigem Cookie, sonst Umleitung zur Startseite.
   - Live-Link ?t=<token>: gueltige API-Sitzung (Kostentraeger) oeffnet g4/ und g5/ ohne Wort.
   ============================================================ */
$BEREICHE=[
  'g5'=>'c25bddcba4b03073c9c3626abcc42cc4b19a48a481672079f69865f7fa189d1f',
  'g4'=>'df9004701c9ed897b65af37093062559c8c5b33b80aa1c51bc7e0e58298579a4',
  'g3'=>'9974e928721e916b2c9b5ea6391db3e6458ee870286547b2ebf17ae478539a42',
  'wartung'=>'a8014fe24738a6f95210a73f40aef6b54b6300fa01c71aee899a73336db166ce',
  'invest'=>'336cedd39d365341372e9318615c4521a6d9025656fd9d7e06fa5f3171332fd6'
];
$cfg=is_file(__DIR__.'/kayya-db.php') ? include __DIR__.'/kayya-db.php' : null;
$SECRET=hash('sha256',($cfg['pass'] ?? 'kein-geheimnis').'|kayya-tor|2026');
$DAUER=12*3600;

function bereichVon($p){
  $p=ltrim($p,'/');
  if(preg_match('#^g4(/|\.html$)#',$p)) return 'g4';
  if(preg_match('#^g5(/|\.html$)#',$p)) return 'g5';
  if($p==='beta1.html') return 'g3';
  if($p==='wartung.html') return 'wartung';
  if(preg_match('#^docs/[^/]+\.(html|docx|pptx|xlsx|pdf)$#i',$p)) return 'invest';
  return null;
}
$MIME=['html'=>'text/html; charset=utf-8','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','pdf'=>'application/pdf'];
function offen(){ global $SECRET;
  $c=$_COOKIE['kayya_tor'] ?? ''; if($c==='') return [];
  $t=explode('.',$c); if(count($t)!==3) return [];
  [$b,$exp,$sig]=$t;
  if(!ctype_digit($exp) || (int)$exp<time()) return [];
  if(!hash_equals(hash_hmac('sha256',"$b.$exp",$SECRET),$sig)) return [];
  return array_filter(explode(',',$b));
}
function freischalten($neu){ global $SECRET,$DAUER;
  $b=array_values(array_unique(array_merge(offen(),[$neu]))); sort($b);
  $exp=time()+$DAUER; $bs=implode(',',$b);
  $sig=hash_hmac('sha256',"$bs.$exp",$SECRET);
  setcookie('kayya_tor',"$bs.$exp.$sig",['expires'=>$exp,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
}

/* ---- Zugangswort pruefen (von der Startseite) ---- */
if($_SERVER['REQUEST_METHOD']==='POST'){
  header('Content-Type: application/json; charset=utf-8');
  $in=json_decode(file_get_contents('php://input'),true) ?: [];
  /* Ein Feld, ein Wort: das Wort bestimmt den Bereich (Startseite); optional kann ein Bereich vorgegeben werden */
  $b=$in['bereich'] ?? ''; $w=(string)($in['wort'] ?? '');
  $ok=false; $kandidaten=isset($BEREICHE[$b]) ? [$b] : array_keys($BEREICHE);
  foreach($kandidaten as $kb){
    foreach([$w,trim($w),mb_strtoupper(trim($w))] as $k){ if(hash_equals($BEREICHE[$kb],hash('sha256',$k))){ $ok=true; $b=$kb; break 2; } }
  }
  if(!$ok){ usleep(500000); http_response_code(403); echo json_encode(['ok'=>false]); exit; }
  freischalten($b);
  echo json_encode(['ok'=>true,'bereich'=>$b]); exit;
}

/* ---- Geschuetzte Seite ausliefern ---- */
$p=$_GET['p'] ?? '';
$bereich=bereichVon($p);
if($bereich===null){ http_response_code(404); echo 'Nicht gefunden.'; exit; }
$erlaubt=in_array($bereich,offen(),true);

/* Live-Link: gueltige Kostentraeger-Sitzung oeffnet g4/ und g5/ */
if(!$erlaubt && in_array($bereich,['g4','g5'],true) && preg_match('/^[0-9a-f]{64}$/',$_GET['t'] ?? '') && $cfg){
  try{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name'],$cfg['port']);
    $st=$db->prepare("SELECT 1 FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.ablauf>NOW() AND u.aktiv=1");
    $st->bind_param('s',$_GET['t']); $st->execute();
    if($st->get_result()->fetch_row()){ $erlaubt=true; freischalten($bereich); }
  }catch(Throwable $e){}
}

if(!$erlaubt){
  header('Cache-Control: no-store');
  header('Location: /?bereich='.rawurlencode($bereich).'&tor=1',true,302); exit;
}

/* Datei sicher aufloesen: nur innerhalb des Webroots, nur bekannte Typen */
$p=ltrim($p,'/'); if($p==='' || substr($p,-1)==='/') $p.='index.html';
$datei=realpath(__DIR__.'/'.$p);
$ext=strtolower(pathinfo($p,PATHINFO_EXTENSION));
if($datei===false || strpos($datei,realpath(__DIR__).DIRECTORY_SEPARATOR)!==0 || !is_file($datei) || !isset($MIME[$ext])){ http_response_code(404); echo 'Nicht gefunden.'; exit; }
header('Content-Type: '.$MIME[$ext]);
header('Cache-Control: no-cache, must-revalidate');
header('X-KAYYA-Tor: '.$bereich);
if($ext!=='html'){ header('Content-Disposition: attachment; filename="'.basename($datei).'"'); header('Content-Length: '.filesize($datei)); }
readfile($datei);
