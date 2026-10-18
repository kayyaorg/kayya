<?php
/* ============================================================
   KAYYA API v1 — eine Datenbasis für alle Ansichten.
   Auth: POST action=login {login, pass} -> {token, rolle, name}
   Danach: Header X-KAYYA-Token (oder ?token=) bei jedem Aufruf.
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');

/* Nur eigene Domain */
$allowed=['kayya.de','www.kayya.de','kayya.org','www.kayya.org','localhost'];
$refHost=parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST) ?: '';
$origin =parse_url($_SERVER['HTTP_ORIGIN']  ?? '', PHP_URL_HOST) ?: '';
if(!in_array($refHost,$allowed) && !in_array($origin,$allowed)){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$cfg=is_file(__DIR__.'/kayya-db.php') ? include __DIR__.'/kayya-db.php' : null;
if(!$cfg){ http_response_code(503); echo json_encode(['error'=>'keine Datenbank konfiguriert']); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try{
  $db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name'],$cfg['port']);
  $db->set_charset('utf8mb4');
}catch(Throwable $e){ http_response_code(503); echo json_encode(['error'=>'datenbank nicht erreichbar']); exit; }

function fail($code,$msg){ http_response_code($code); echo json_encode(['error'=>$msg],JSON_UNESCAPED_UNICODE); exit; }
function alle($st){ $r=$st->get_result(); $out=[]; while($row=$r->fetch_assoc()) $out[]=$row; return $out; }

$in=json_decode(file_get_contents('php://input'),true) ?: [];
$action=$_GET['action'] ?? $in['action'] ?? '';

/* ---- Ping: DB-Verbindung steht an dieser Stelle bereits ---- */
if($action==='ping'){
  $t=$db->query("SELECT COUNT(*) c FROM massnahmen")->fetch_assoc()['c'];
  echo json_encode(['ok'=>true,'db'=>'MariaDB','massnahmen'=>(int)$t,'zeit'=>date('H:i:s')]); exit;
}

/* ---- Login ---- */
if($action==='login'){
  $login=trim($in['login'] ?? ''); $pass=$in['pass'] ?? '';
  if($login===''||$pass==='') fail(400,'login und pass erforderlich');
  $st=$db->prepare("SELECT id,pass_hash,name,rolle,tn_id FROM users WHERE login=? AND aktiv=1");
  $st->bind_param('s',$login); $st->execute(); $u=$st->get_result()->fetch_assoc();
  if(!$u || !password_verify($pass,$u['pass_hash'])){
    usleep(400000); fail(401,'Anmeldung fehlgeschlagen');
  }
  $tok=bin2hex(random_bytes(32));
  $st=$db->prepare("INSERT INTO sessions(token,user_id,ablauf) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 12 HOUR))");
  $st->bind_param('si',$tok,$u['id']); $st->execute();
  $st=$db->prepare("INSERT INTO protokoll(user_id,aktion,detail) VALUES(?,?,?)");
  $ak='login'; $dt='Anmeldung '.$u['rolle']; $st->bind_param('iss',$u['id'],$ak,$dt); $st->execute();
  echo json_encode(['token'=>$tok,'name'=>$u['name'],'rolle'=>$u['rolle'],'tn_id'=>$u['tn_id']],JSON_UNESCAPED_UNICODE); exit;
}

/* ---- Auth-Pflicht für alles Weitere ---- */
$tok=$_SERVER['HTTP_X_KAYYA_TOKEN'] ?? $_GET['token'] ?? $in['token'] ?? '';
if($tok==='') fail(401,'kein Token');
$st=$db->prepare("SELECT u.id,u.name,u.rolle,u.tn_id FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=? AND s.ablauf>NOW() AND u.aktiv=1");
$st->bind_param('s',$tok); $st->execute(); $ME=$st->get_result()->fetch_assoc();
if(!$ME) fail(401,'Sitzung abgelaufen');
$ROLLE=$ME['rolle'];

function protokoll($db,$uid,$aktion,$detail){
  $st=$db->prepare("INSERT INTO protokoll(user_id,aktion,detail) VALUES(?,?,?)");
  $st->bind_param('iss',$uid,$aktion,$detail); $st->execute();
}
function nur($rollen){ global $ROLLE; if(!in_array($ROLLE,$rollen)) fail(403,'keine Berechtigung für Rolle '.$ROLLE); }

switch($action){

case 'me':
  echo json_encode($ME,JSON_UNESCAPED_UNICODE); break;

case 'massnahmen':
  nur(['management','kursleiter','kostentraeger','demo']);
  $st=$db->prepare("SELECT kennung,name,plaetze,belegt,anwesenheit_prozent,fortschritt_prozent,kosten_je_eingliederung,ampel,status FROM massnahmen ORDER BY kennung");
  $st->execute(); echo json_encode(alle($st),JSON_UNESCAPED_UNICODE); break;

case 'teilnehmer':
  nur(['management','kursleiter','demo']);
  $st=$db->prepare("SELECT t.id,t.name,m.kennung,t.status,t.lernstand_prozent,t.foerderpfad,
      (SELECT a.status FROM anwesenheit a WHERE a.tn_id=t.id AND a.datum=CURDATE()) AS heute,
      (SELECT a.quelle FROM anwesenheit a WHERE a.tn_id=t.id AND a.datum=CURDATE()) AS heute_quelle
    FROM teilnehmer t JOIN massnahmen m ON m.id=t.massnahme_id ORDER BY t.name");
  $st->execute(); echo json_encode(alle($st),JSON_UNESCAPED_UNICODE); break;

case 'mein_stand':
  nur(['teilnehmer']);
  $tn=(int)$ME['tn_id'];
  $st=$db->prepare("SELECT t.name,m.kennung,m.name AS massnahme,t.lernstand_prozent,t.foerderpfad,
      (SELECT a.status FROM anwesenheit a WHERE a.tn_id=t.id AND a.datum=CURDATE()) AS heute
    FROM teilnehmer t JOIN massnahmen m ON m.id=t.massnahme_id WHERE t.id=?");
  $st->bind_param('i',$tn); $st->execute();
  $me=$st->get_result()->fetch_assoc();
  $st=$db->prepare("SELECT typ,titel,ausgestellt,status FROM zeugnisse WHERE tn_id=? ORDER BY ausgestellt DESC");
  $st->bind_param('i',$tn); $st->execute(); $me['zeugnisse']=alle($st);
  echo json_encode($me,JSON_UNESCAPED_UNICODE); break;

case 'anwesenheit_setzen':
  nur(['kursleiter','teilnehmer','demo']);
  $tn=(int)($in['tn_id'] ?? 0); $status=$in['status'] ?? ''; $quelle=$in['quelle'] ?? ($ROLLE==='teilnehmer'?'Teilnehmer-App':'Kursleiter');
  if($ROLLE==='teilnehmer'){ $tn=(int)$ME['tn_id']; if(!in_array($status,['krank','checkin'])) fail(403,'Teilnehmer dürfen nur krank/checkin melden'); }
  if($tn<1 || !in_array($status,['anwesend','krank','fehlt','checkin'])) fail(400,'tn_id/status ungültig');
  $st=$db->prepare("INSERT INTO anwesenheit(tn_id,datum,status,quelle) VALUES(?,CURDATE(),?,?)
                    ON DUPLICATE KEY UPDATE status=VALUES(status), quelle=VALUES(quelle), zeit=NOW()");
  $st->bind_param('iss',$tn,$status,$quelle); $st->execute();
  if($status==='krank'){
    $st=$db->prepare("INSERT INTO meldungen(tn_id,typ,text) VALUES(?,?,?)");
    $ty='krankmeldung'; $tx='Krankmeldung über '.$quelle;
    $st->bind_param('iss',$tn,$ty,$tx); $st->execute();
  }
  protokoll($db,$ME['id'],'anwesenheit',$tn.' -> '.$status.' ('.$quelle.')');
  echo json_encode(['ok'=>true]); break;

case 'anwesenheit_heute':
  nur(['management','kursleiter','demo']);
  $st=$db->prepare("SELECT t.name,a.status,a.quelle,TIME_FORMAT(a.zeit,'%H:%i') zeit FROM anwesenheit a JOIN teilnehmer t ON t.id=a.tn_id WHERE a.datum=CURDATE() ORDER BY a.zeit DESC");
  $st->execute(); echo json_encode(alle($st),JSON_UNESCAPED_UNICODE); break;

case 'meldungen':
  nur(['management','kursleiter','demo']);
  $st=$db->prepare("SELECT m.id,t.name,m.typ,m.text,DATE_FORMAT(m.zeit,'%d.%m. %H:%i') zeit,m.gelesen FROM meldungen m LEFT JOIN teilnehmer t ON t.id=m.tn_id ORDER BY m.zeit DESC LIMIT 50");
  $st->execute(); echo json_encode(alle($st),JSON_UNESCAPED_UNICODE); break;

case 'meldung':
  nur(['kursleiter','teilnehmer','demo']);
  $tx=trim($in['text'] ?? ''); $ty=trim($in['typ'] ?? 'hinweis');
  $tn=($ROLLE==='teilnehmer') ? (int)$ME['tn_id'] : (int)($in['tn_id'] ?? 0);
  if($tx==='') fail(400,'text erforderlich');
  $st=$db->prepare("INSERT INTO meldungen(tn_id,typ,text) VALUES(?,?,?)");
  $tnn=$tn>0?$tn:null; $st->bind_param('iss',$tnn,$ty,$tx); $st->execute();
  protokoll($db,$ME['id'],'meldung',$ty);
  echo json_encode(['ok'=>true]); break;

case 'belege':
  nur(['management','kostentraeger','demo']);
  $st=$db->prepare("SELECT m.kennung,b.typ,b.betrag,DATE_FORMAT(b.datum,'%d.%m.%Y') datum,b.beschreibung,b.status FROM belege b JOIN massnahmen m ON m.id=b.massnahme_id ORDER BY b.datum DESC");
  $st->execute(); echo json_encode(alle($st),JSON_UNESCAPED_UNICODE); break;

case 'zeugnisse':
  nur(['management','kursleiter','kostentraeger','demo']);
  $st=$db->prepare("SELECT t.name,z.typ,z.titel,DATE_FORMAT(z.ausgestellt,'%d.%m.%Y') ausgestellt,z.status FROM zeugnisse z JOIN teilnehmer t ON t.id=z.tn_id ORDER BY z.ausgestellt DESC");
  $st->execute(); echo json_encode(alle($st),JSON_UNESCAPED_UNICODE); break;

case 'protokoll':
  nur(['management','kostentraeger','demo']);
  $st=$db->prepare("SELECT DATE_FORMAT(p.zeit,'%d.%m. %H:%i:%s') zeit,u.name,p.aktion,p.detail FROM protokoll p LEFT JOIN users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 100");
  $st->execute(); echo json_encode(alle($st),JSON_UNESCAPED_UNICODE); break;

case 'massnahme_anlegen':
  nur(['management','demo']);
  $kennung=trim($in['kennung'] ?? ''); $name=trim($in['name'] ?? ''); $pl=(int)($in['plaetze'] ?? 0);
  if($kennung===''||$name===''||$pl<1) fail(400,'kennung, name, plaetze erforderlich');
  $st=$db->prepare("SELECT COUNT(*) c FROM massnahmen WHERE kennung=?");
  $st->bind_param('s',$kennung); $st->execute();
  if((int)$st->get_result()->fetch_assoc()['c']>0){ echo json_encode(['ok'=>true,'hinweis'=>'existiert bereits']); break; }
  $st=$db->prepare("INSERT INTO massnahmen(kennung,name,plaetze,belegt,ampel,status) VALUES(?,?,?,0,'gruen','aktiv')");
  $st->bind_param('ssi',$kennung,$name,$pl); $st->execute();
  protokoll($db,$ME['id'],'massnahme_anlegen',$kennung.' · '.$name.' ('.$pl.' Plätze)');
  echo json_encode(['ok'=>true]); break;

case 'teilnehmer_anlegen':
  nur(['management','kursleiter','demo']);
  $name=trim($in['name'] ?? ''); $kennung=trim($in['kennung'] ?? ''); $pfad=trim($in['foerderpfad'] ?? 'Zuweisung');
  if($name===''||$kennung==='') fail(400,'name und kennung erforderlich');
  $st=$db->prepare("SELECT id FROM massnahmen WHERE kennung=?");
  $st->bind_param('s',$kennung); $st->execute();
  $mm=$st->get_result()->fetch_assoc(); if(!$mm) fail(404,'Maßnahme unbekannt');
  $mid=(int)$mm['id'];
  $st=$db->prepare("INSERT INTO teilnehmer(name,massnahme_id,foerderpfad) VALUES(?,?,?)");
  $st->bind_param('sis',$name,$mid,$pfad); $st->execute();
  $st=$db->prepare("UPDATE massnahmen SET belegt=belegt+1 WHERE id=?");
  $st->bind_param('i',$mid); $st->execute();
  protokoll($db,$ME['id'],'zuweisung',$name.' -> '.$kennung);
  echo json_encode(['ok'=>true]); break;

case 'beleg_anlegen':
  nur(['management','kursleiter','demo']);
  $kennung=trim($in['kennung'] ?? ''); $typ=trim($in['typ'] ?? 'Sachkosten'); $betrag=(float)($in['betrag'] ?? 0); $besch=trim($in['beschreibung'] ?? '');
  if($kennung===''||$betrag<=0) fail(400,'kennung und betrag erforderlich');
  $st=$db->prepare("SELECT id FROM massnahmen WHERE kennung=?");
  $st->bind_param('s',$kennung); $st->execute();
  $mm=$st->get_result()->fetch_assoc(); if(!$mm) fail(404,'Maßnahme unbekannt');
  $mid=(int)$mm['id'];
  $st=$db->prepare("INSERT INTO belege(massnahme_id,typ,betrag,datum,beschreibung,status) VALUES(?,?,?,CURDATE(),?,'erfasst')");
  $st->bind_param('isds',$mid,$typ,$betrag,$besch); $st->execute();
  protokoll($db,$ME['id'],'beleg',$kennung.' · '.$typ.' · '.number_format($betrag,2,',','.').' €');
  echo json_encode(['ok'=>true]); break;

case 'logout':
  $st=$db->prepare("DELETE FROM sessions WHERE token=?"); $st->bind_param('s',$tok); $st->execute();
  echo json_encode(['ok'=>true]); break;

default: fail(400,'unbekannte action');
}
