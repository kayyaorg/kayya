<?php
/* KAYYA Upgrade 2 (31.10.2026): Kostenträger-Zuordnung in die Datenbank — Grundlage für serverseitigen Zugriffsschutz.
   Einmalig aufrufen, danach vom Server LÖSCHEN. Idempotent. */
header('Content-Type: application/json; charset=utf-8');
$TOKEN='k2v7xq9pmz4rt1hw8bn3cy6sdfj0lega';
if(($_GET['token'] ?? '')!==$TOKEN){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
$cfg=include __DIR__.'/kayya-db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name'],$cfg['port']); $db->set_charset('utf8mb4');
$log=[];
function spalte($db,$tab,$sp){ $r=$db->query("SHOW COLUMNS FROM `$tab` LIKE '$sp'"); return $r->num_rows>0; }
try{
  if(!spalte($db,'massnahmen','kostentraeger_key')) $db->query("ALTER TABLE massnahmen ADD kostentraeger_key VARCHAR(20) NULL, ADD kostentraeger VARCHAR(120) NULL");
  if(!spalte($db,'users','kostentraeger_key')) $db->query("ALTER TABLE users ADD kostentraeger_key VARCHAR(20) NULL");
  $log['spalten']='ok';
  $zu=['M-4711'=>['aa-nord','Agentur für Arbeit Nord'],'M-4712'=>['jc-mitte','Jobcenter Region Mitte'],'M-4713'=>['bamf','BAMF (regional)'],'M-4714'=>['jc-mitte','Jobcenter Region Mitte'],'M-4715'=>['jc-nord','Jobcenter Region Nord']];
  $st=$db->prepare("UPDATE massnahmen SET kostentraeger_key=?, kostentraeger=? WHERE kennung=?");
  foreach($zu as $k=>$v){ $st->bind_param('sss',$v[0],$v[1],$k); $st->execute(); }
  $log['massnahmen']='Kostenträger zugeordnet ('.count($zu).')';
  /* Beta-Konto jobcenter wird zum Jobcenter Region Mitte (2 Maßnahmen, reichhaltigste Demo) */
  $db->query("UPDATE users SET kostentraeger_key='jc-mitte', name='Jobcenter Region Mitte' WHERE login='jobcenter'");
  $log['users']='jobcenter -> jc-mitte';
  $db->query("INSERT INTO protokoll(aktion,detail) VALUES('upgrade2','Kostenträger-Zuordnung in DB, Demo-Rolle nur lesend')");
}catch(Throwable $e){ $log['fehler']=$e->getMessage(); }
echo json_encode(['ok'=>!isset($log['fehler']),'schritte'=>$log],JSON_UNESCAPED_UNICODE);
