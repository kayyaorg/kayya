<?php
/* ============================================================
   WARTUNGSÜBERSICHT API v1 — Hausverwaltung · Prüfpflichten.
   Auth: POST action=login {login,pass} -> {token,rolle,name}
   Danach Header X-W-Token (oder ?token=).
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');

$allowed=['kayya.de','www.kayya.de','kayya.org','www.kayya.org','localhost'];
$refHost=parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST) ?: '';
$origin =parse_url($_SERVER['HTTP_ORIGIN']  ?? '', PHP_URL_HOST) ?: '';
if(!in_array($refHost,$allowed) && !in_array($origin,$allowed)){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$cfg=is_file(__DIR__.'/kayya-db.php') ? include __DIR__.'/kayya-db.php' : null;
if(!$cfg){ http_response_code(503); echo json_encode(['error'=>'keine Datenbank']); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try{ $db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],$cfg['name'],$cfg['port']); $db->set_charset('utf8mb4'); }
catch(Throwable $e){ http_response_code(503); echo json_encode(['error'=>'datenbank nicht erreichbar']); exit; }

function fail($c,$m){ http_response_code($c); echo json_encode(['error'=>$m],JSON_UNESCAPED_UNICODE); exit; }
function alle($st){ $r=$st->get_result(); $o=[]; while($x=$r->fetch_assoc()) $o[]=$x; return $o; }

$in=json_decode(file_get_contents('php://input'),true) ?: [];
$action=$_GET['action'] ?? $_POST['action'] ?? $in['action'] ?? '';

/* ---- Login ---- */
if($action==='login'){
  $l=trim($in['login'] ?? ''); $p=$in['pass'] ?? '';
  if($l===''||$p==='') fail(400,'login und pass erforderlich');
  $st=$db->prepare("SELECT id,pass_hash,name,rolle FROM w_users WHERE login=? AND aktiv=1");
  $st->bind_param('s',$l); $st->execute(); $u=$st->get_result()->fetch_assoc();
  if(!$u || !password_verify($p,$u['pass_hash'])){ usleep(400000); fail(401,'Anmeldung fehlgeschlagen'); }
  $tok=bin2hex(random_bytes(32));
  $st=$db->prepare("INSERT INTO w_sessions(token,user_id,ablauf) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 12 HOUR))");
  $st->bind_param('si',$tok,$u['id']); $st->execute();
  echo json_encode(['token'=>$tok,'name'=>$u['name'],'rolle'=>$u['rolle']],JSON_UNESCAPED_UNICODE); exit;
}

/* ---- Auth ---- */
$tok=$_SERVER['HTTP_X_W_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? $in['token'] ?? '';
if($tok==='') fail(401,'kein Token');
$st=$db->prepare("SELECT u.id,u.name,u.rolle FROM w_sessions s JOIN w_users u ON u.id=s.user_id WHERE s.token=? AND s.ablauf>NOW() AND u.aktiv=1");
$st->bind_param('s',$tok); $st->execute(); $ME=$st->get_result()->fetch_assoc();
if(!$ME) fail(401,'Sitzung abgelaufen');

function wlog($db,$uid,$a,$d){ $st=$db->prepare("INSERT INTO w_protokoll(user_id,aktion,detail) VALUES(?,?,?)"); $st->bind_param('iss',$uid,$a,$d); $st->execute(); }

/* ---- KI-Helfer (Key serverseitig, kayya-key.php) ---- */
function ki($content,$system,$maxtok=1500){
  $key=is_file(__DIR__.'/kayya-key.php') ? include __DIR__.'/kayya-key.php' : null;
  if(!is_string($key) || trim($key)==='') return null;
  $key=trim($key);
  $body=['model'=>'claude-sonnet-5','max_tokens'=>$maxtok,'system'=>$system,
         'messages'=>[['role'=>'user','content'=>$content]]];
  $ch=curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>120,
    CURLOPT_HTTPHEADER=>['content-type: application/json','x-api-key: '.$key,'anthropic-version: 2023-06-01'],
    CURLOPT_POSTFIELDS=>json_encode($body)]);
  $res=curl_exec($ch); curl_close($ch);
  if($res===false) return null;
  $j=json_decode($res,true);
  if(!isset($j['content'][0]['text'])){ error_log('wartung-ki: '.substr($res,0,300)); return null; }
  return $j['content'][0]['text'];
}
function jsonAus($txt){
  if(!$txt) return null;
  $txt=preg_replace('/^```(json)?|```$/m','',trim($txt));
  $a=strpos($txt,'{'); $b=strrpos($txt,'}');
  if($a===false||$b===false) return null;
  return json_decode(substr($txt,$a,$b-$a+1),true);
}

switch($action){

case 'me': echo json_encode($ME,JSON_UNESCAPED_UNICODE); break;

case 'uebersicht': {
  $st=$db->prepare("SELECT i.id,i.name,i.adresse,
    (SELECT COUNT(*) FROM w_anlagen a WHERE a.immobilie_id=i.id) anlagen,
    (SELECT COUNT(*) FROM w_pruefarten p JOIN w_anlagen a ON a.id=p.anlage_id WHERE a.immobilie_id=i.id AND p.letzte IS NOT NULL AND DATE_ADD(p.letzte,INTERVAL p.turnus_monate MONTH)<CURDATE()) ueberfaellig,
    (SELECT COUNT(*) FROM w_pruefarten p JOIN w_anlagen a ON a.id=p.anlage_id WHERE a.immobilie_id=i.id AND p.letzte IS NOT NULL AND DATE_ADD(p.letzte,INTERVAL p.turnus_monate MONTH) BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY)) faellig,
    (SELECT COUNT(*) FROM w_maengel m JOIN w_anlagen a ON a.id=m.anlage_id WHERE a.immobilie_id=i.id AND m.wesentlich=1 AND m.status IN('offen','vergeben')) wesentlich_offen,
    (SELECT COUNT(*) FROM w_maengel m JOIN w_anlagen a ON a.id=m.anlage_id WHERE a.immobilie_id=i.id AND m.status IN('offen','vergeben')) maengel_offen
    FROM w_immobilien i ORDER BY i.name");
  $st->execute(); echo json_encode(alle($st),JSON_UNESCAPED_UNICODE); break;
}

case 'immobilie': {
  $id=(int)($in['id'] ?? $_GET['id'] ?? 0); if($id<1) fail(400,'id');
  $st=$db->prepare("SELECT id,name,adresse FROM w_immobilien WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $im=$st->get_result()->fetch_assoc();
  if(!$im) fail(404,'Immobilie unbekannt');
  $st=$db->prepare("SELECT a.id,a.name,a.typ,a.sicherheitsrelevant,a.hersteller,a.baujahr,a.standort,
    (SELECT MIN(DATE_ADD(p.letzte,INTERVAL p.turnus_monate MONTH)) FROM w_pruefarten p WHERE p.anlage_id=a.id AND p.letzte IS NOT NULL) naechste,
    (SELECT COUNT(*) FROM w_pruefarten p WHERE p.anlage_id=a.id AND p.letzte IS NOT NULL AND DATE_ADD(p.letzte,INTERVAL p.turnus_monate MONTH)<CURDATE()) ueberfaellig,
    (SELECT COUNT(*) FROM w_maengel m WHERE m.anlage_id=a.id AND m.wesentlich=1 AND m.status IN('offen','vergeben')) wesentlich_offen,
    (SELECT COUNT(*) FROM w_maengel m WHERE m.anlage_id=a.id AND m.status IN('offen','vergeben')) maengel_offen
    FROM w_anlagen a WHERE a.immobilie_id=? ORDER BY a.sicherheitsrelevant DESC, a.name");
  $st->bind_param('i',$id); $st->execute(); $im['anlagen']=alle($st);
  echo json_encode($im,JSON_UNESCAPED_UNICODE); break;
}

case 'anlage': {
  $id=(int)($in['id'] ?? $_GET['id'] ?? 0); if($id<1) fail(400,'id');
  $st=$db->prepare("SELECT a.*,i.name immobilie,i.id immobilie_id FROM w_anlagen a JOIN w_immobilien i ON i.id=a.immobilie_id WHERE a.id=?");
  $st->bind_param('i',$id); $st->execute(); $an=$st->get_result()->fetch_assoc();
  if(!$an) fail(404,'Anlage unbekannt');
  $st=$db->prepare("SELECT id,art,grundlage,turnus_monate,letzte,kategorie,
    DATE_ADD(letzte,INTERVAL turnus_monate MONTH) naechste,
    CASE WHEN letzte IS NULL THEN 'offen' WHEN DATE_ADD(letzte,INTERVAL turnus_monate MONTH)<CURDATE() THEN 'ueberfaellig'
         WHEN DATE_ADD(letzte,INTERVAL turnus_monate MONTH)<DATE_ADD(CURDATE(),INTERVAL 60 DAY) THEN 'faellig' ELSE 'ok' END ampel
    FROM w_pruefarten WHERE anlage_id=? ORDER BY naechste");
  $st->bind_param('i',$id); $st->execute(); $an['pruefarten']=alle($st);
  $st=$db->prepare("SELECT id,typ,orig_name,mime,groesse,DATE_FORMAT(hochgeladen,'%d.%m.%Y %H:%i') hochgeladen,auswertung IS NOT NULL ausgewertet FROM w_dokumente WHERE anlage_id=? ORDER BY id DESC");
  $st->bind_param('i',$id); $st->execute(); $an['dokumente']=alle($st);
  $st=$db->prepare("SELECT * FROM w_maengel WHERE anlage_id=? ORDER BY wesentlich DESC, id DESC");
  $st->bind_param('i',$id); $st->execute(); $an['maengel']=alle($st);
  $stf=$db->prepare("SELECT id,gewerk,name,kontakt FROM w_firmen ORDER BY gewerk"); $stf->execute(); $an['firmen']=alle($stf);
  echo json_encode($an,JSON_UNESCAPED_UNICODE); break;
}

case 'immobilie_anlegen': {
  if($ME['rolle']!=='gf') fail(403,'nur Geschäftsführung');
  $n=trim($in['name'] ?? ''); $a=trim($in['adresse'] ?? '');
  if($n==='') fail(400,'name erforderlich');
  $st=$db->prepare("INSERT INTO w_immobilien(name,adresse) VALUES(?,?)");
  $st->bind_param('ss',$n,$a); $st->execute();
  $nid=$db->insert_id;
  wlog($db,$ME['id'],'immobilie_anlegen',$n);
  echo json_encode(['ok'=>true,'id'=>$nid]); break;
}

case 'anlage_anlegen': {
  $iid=(int)($in['immobilie_id'] ?? 0); $n=trim($in['name'] ?? '');
  if($iid<1||$n==='') fail(400,'immobilie_id und name erforderlich');
  $typ=trim($in['typ'] ?? ''); $sr=(int)!!($in['sicherheitsrelevant'] ?? 0);
  $h=trim($in['hersteller'] ?? ''); $b=trim($in['baujahr'] ?? ''); $so=trim($in['standort'] ?? '');
  $st=$db->prepare("INSERT INTO w_anlagen(immobilie_id,name,typ,sicherheitsrelevant,hersteller,baujahr,standort) VALUES(?,?,?,?,?,?,?)");
  $st->bind_param('ississs',$iid,$n,$typ,$sr,$h,$b,$so); $st->execute();
  $nid=$db->insert_id;
  wlog($db,$ME['id'],'anlage_anlegen',$n);
  echo json_encode(['ok'=>true,'id'=>$nid]); break;
}

case 'pruefart_anlegen': {
  $aid=(int)($in['anlage_id'] ?? 0); $art=trim($in['art'] ?? ''); $tm=(int)($in['turnus_monate'] ?? 0);
  if($aid<1||$art===''||$tm<1) fail(400,'anlage_id, art, turnus_monate erforderlich');
  $g=trim($in['grundlage'] ?? ''); $kat=in_array($in['kategorie'] ?? '',['sv','wartung','eigen'])?$in['kategorie']:'wartung';
  $le=($in['letzte'] ?? '')!=='' ? $in['letzte'] : null;
  $st=$db->prepare("INSERT INTO w_pruefarten(anlage_id,art,grundlage,turnus_monate,letzte,kategorie) VALUES(?,?,?,?,?,?)");
  $st->bind_param('ississ',$aid,$art,$g,$tm,$le,$kat); $st->execute();
  wlog($db,$ME['id'],'pruefart_anlegen',$art);
  echo json_encode(['ok'=>true]); break;
}

case 'pruefung_erfasst': {
  $pid=(int)($in['pruefart_id'] ?? 0); $d=$in['datum'] ?? date('Y-m-d');
  if($pid<1) fail(400,'pruefart_id');
  $st=$db->prepare("UPDATE w_pruefarten SET letzte=? WHERE id=?");
  $st->bind_param('si',$d,$pid); $st->execute();
  wlog($db,$ME['id'],'pruefung_erfasst','Prüfart '.$pid.' am '.$d);
  echo json_encode(['ok'=>true]); break;
}

case 'upload': {
  $aid=(int)($_POST['anlage_id'] ?? 0);
  if($aid<1 || !isset($_FILES['datei'])) fail(400,'anlage_id und datei erforderlich');
  $f=$_FILES['datei'];
  if($f['error']!==UPLOAD_ERR_OK) fail(400,'Upload-Fehler '.$f['error']);
  if($f['size']>15*1024*1024) fail(400,'max. 15 MB');
  $erlaubt=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','text/plain'=>'txt'];
  $mime=mime_content_type($f['tmp_name']) ?: ($f['type'] ?? '');
  if(!isset($erlaubt[$mime])) fail(400,'Nur PDF, JPG, PNG oder TXT');
  $typ=in_array($_POST['typ'] ?? '',['pruefprotokoll','wartungsbericht','angebot','sonstiges'])?$_POST['typ']:'pruefprotokoll';
  $pid=(int)($_POST['pruefart_id'] ?? 0); $pidN=$pid>0?$pid:null;
  $name=bin2hex(random_bytes(16)).'.'.$erlaubt[$mime];
  if(!move_uploaded_file($f['tmp_name'],__DIR__.'/wdok/'.$name)) fail(500,'Speichern fehlgeschlagen');
  $on=substr($f['name'],0,200); $gr=(int)$f['size'];
  $st=$db->prepare("INSERT INTO w_dokumente(anlage_id,pruefart_id,typ,datei,orig_name,mime,groesse) VALUES(?,?,?,?,?,?,?)");
  $st->bind_param('iissssi',$aid,$pidN,$typ,$name,$on,$mime,$gr); $st->execute();
  $nid=$db->insert_id;
  wlog($db,$ME['id'],'upload',$on.' -> Anlage '.$aid);
  echo json_encode(['ok'=>true,'id'=>$nid],JSON_UNESCAPED_UNICODE); break;
}

case 'datei': {
  $id=(int)($_GET['id'] ?? 0); if($id<1) fail(400,'id');
  $st=$db->prepare("SELECT datei,orig_name,mime FROM w_dokumente WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $d=$st->get_result()->fetch_assoc();
  if(!$d || !is_file(__DIR__.'/wdok/'.$d['datei'])) fail(404,'Datei fehlt');
  header('Content-Type: '.$d['mime']);
  header('Content-Disposition: inline; filename="'.rawurlencode($d['orig_name']).'"');
  readfile(__DIR__.'/wdok/'.$d['datei']); exit;
}

case 'dokument_loeschen': {
  if($ME['rolle']!=='gf') fail(403,'nur Geschäftsführung');
  $id=(int)($in['dokument_id'] ?? 0); if($id<1) fail(400,'dokument_id');
  $st=$db->prepare("SELECT datei,orig_name FROM w_dokumente WHERE id=?");
  $st->bind_param('i',$id); $st->execute(); $d=$st->get_result()->fetch_assoc();
  if(!$d) fail(404,'Dokument unbekannt');
  $st=$db->prepare("DELETE FROM w_dokumente WHERE id=?"); $st->bind_param('i',$id); $st->execute();
  $pf=__DIR__.'/wdok/'.$d['datei']; if(is_file($pf)) @unlink($pf);
  wlog($db,$ME['id'],'dokument_loeschen',$d['orig_name']);
  echo json_encode(['ok'=>true]); break;
}

case 'auswerten': {
  $id=(int)($in['dokument_id'] ?? 0); if($id<1) fail(400,'dokument_id');
  $st=$db->prepare("SELECT d.*,a.name anlage,a.typ FROM w_dokumente d JOIN w_anlagen a ON a.id=d.anlage_id WHERE d.id=?");
  $st->bind_param('i',$id); $st->execute(); $d=$st->get_result()->fetch_assoc();
  if(!$d) fail(404,'Dokument unbekannt');
  $pfad=__DIR__.'/wdok/'.$d['datei'];
  if(!is_file($pfad)) fail(404,'Datei fehlt');
  $system="Du liest deutsche Wartungsberichte und Prüfprotokolle (TÜV/DEKRA/ZÜS-Berichte, SV-Prüfungen, VdS, DGUV). Antworte AUSSCHLIESSLICH mit einem JSON-Objekt: {\"pruefdatum\":\"YYYY-MM-DD oder null\",\"pruefstelle\":\"Name oder null\",\"berichtsnr\":\"oder null\",\"ergebnis\":\"ein Satz\",\"maengel\":[{\"text\":\"konkreter Mangel\",\"wesentlich\":true/false,\"frist\":\"YYYY-MM-DD oder null\"}],\"naechste_pruefung\":\"YYYY-MM-DD oder null\"}. 'wesentlich' = erhebliche/sicherheitsrelevante Mängel im Sinne der Prüfnorm (Betrieb gefährdet, Frist gesetzt, Nachprüfung erforderlich); Ordnungs-/Dokumentationsmängel sind unwesentlich. Keine Erfindungen: nur was im Dokument steht.";
  if($d['mime']==='text/plain'){
    $content=[['type'=>'text','text'=>"Prüfprotokoll zur Anlage \"".$d['anlage']."\" (".$d['typ']."):\n\n".mb_substr(file_get_contents($pfad),0,30000)]];
  } else {
    $block=($d['mime']==='application/pdf')
      ? ['type'=>'document','source'=>['type'=>'base64','media_type'=>'application/pdf','data'=>base64_encode(file_get_contents($pfad))]]
      : ['type'=>'image','source'=>['type'=>'base64','media_type'=>$d['mime'],'data'=>base64_encode(file_get_contents($pfad))]];
    $content=[$block,['type'=>'text','text'=>'Lies dieses Prüf-/Wartungsdokument zur Anlage "'.$d['anlage'].'" aus und antworte nur mit dem JSON.']];
  }
  $txt=ki($content,$system,1500);
  $j=jsonAus($txt);
  if(!$j) fail(502,'KI-Auswertung nicht möglich — Dokument manuell prüfen');
  $st=$db->prepare("UPDATE w_dokumente SET auswertung=? WHERE id=?");
  $aw=json_encode($j,JSON_UNESCAPED_UNICODE); $st->bind_param('si',$aw,$id); $st->execute();
  $neu=0;
  if(!empty($j['maengel']) && is_array($j['maengel'])){
    $st=$db->prepare("INSERT INTO w_maengel(anlage_id,dokument_id,text,wesentlich,frist) VALUES(?,?,?,?,?)");
    foreach(array_slice($j['maengel'],0,20) as $m2){
      $tx=trim($m2['text'] ?? ''); if($tx==='') continue;
      $we=(int)!!($m2['wesentlich'] ?? false);
      $fr=(isset($m2['frist'])&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$m2['frist']??''))?$m2['frist']:null;
      $aid=(int)$d['anlage_id'];
      $st->bind_param('iisis',$aid,$id,$tx,$we,$fr); $st->execute(); $neu++;
    }
  }
  if($d['pruefart_id'] && !empty($j['pruefdatum']) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$j['pruefdatum'])){
    $st=$db->prepare("UPDATE w_pruefarten SET letzte=? WHERE id=?");
    $pa=(int)$d['pruefart_id']; $st->bind_param('si',$j['pruefdatum'],$pa); $st->execute();
  }
  wlog($db,$ME['id'],'auswerten','Dokument '.$id.' · '.$neu.' Mängel erkannt');
  echo json_encode(['ok'=>true,'auswertung'=>$j,'neue_maengel'=>$neu],JSON_UNESCAPED_UNICODE); break;
}

case 'mangel_anlegen': {
  $aid=(int)($in['anlage_id'] ?? 0); $tx=trim($in['text'] ?? '');
  if($aid<1||$tx==='') fail(400,'anlage_id und text erforderlich');
  $we=(int)!!($in['wesentlich'] ?? 0);
  $st=$db->prepare("INSERT INTO w_maengel(anlage_id,text,wesentlich) VALUES(?,?,?)");
  $st->bind_param('isi',$aid,$tx,$we); $st->execute();
  wlog($db,$ME['id'],'mangel_anlegen','Anlage '.$aid);
  echo json_encode(['ok'=>true]); break;
}

case 'mangel_status': {
  $id=(int)($in['id'] ?? 0); $s=$in['status'] ?? '';
  if($id<1||!in_array($s,['offen','vergeben','behoben','freigemeldet'])) fail(400,'id/status');
  $fi=trim($in['firma'] ?? ''); $ba=($s==='behoben')?($in['behoben_am'] ?? date('Y-m-d')):null;
  $st=$db->prepare("UPDATE w_maengel SET status=?, firma=IF(?='',firma,?), behoben_am=COALESCE(?,behoben_am) WHERE id=?");
  $st->bind_param('ssssi',$s,$fi,$fi,$ba,$id); $st->execute();
  wlog($db,$ME['id'],'mangel_status',$id.' -> '.$s);
  echo json_encode(['ok'=>true]); break;
}

case 'vergabe': {
  $id=(int)($in['mangel_id'] ?? 0); if($id<1) fail(400,'mangel_id');
  $st=$db->prepare("SELECT m.*,a.name anlage,a.typ,a.hersteller,a.baujahr,i.name immobilie,i.adresse FROM w_maengel m JOIN w_anlagen a ON a.id=m.anlage_id JOIN w_immobilien i ON i.id=a.immobilie_id WHERE m.id=?");
  $st->bind_param('i',$id); $st->execute(); $m=$st->get_result()->fetch_assoc();
  if(!$m) fail(404,'Mangel unbekannt');
  $gw=['Aufzug'=>'Aufzug','BMA'=>'Brandschutz','Feuerlöscher'=>'Brandschutz','RWA'=>'Brandschutz','Sicherheitsbeleuchtung'=>'Elektro','Elektro'=>'Elektro','Heizung'=>'SHK','Trinkwasser'=>'SHK','Spielplatz'=>'Spielplatz','Tore'=>'Tore'];
  $gewerk=$gw[$m['typ']] ?? 'Elektro';
  $st=$db->prepare("SELECT name,kontakt FROM w_firmen WHERE gewerk=? LIMIT 3");
  $st->bind_param('s',$gewerk); $st->execute(); $firmen=alle($st);
  $txt=ki([['type'=>'text','text'=>"Entwirf eine kurze, präzise Angebotsanfrage (max 150 Wörter) einer Hausverwaltung an einen Fachbetrieb (Gewerk: ".$gewerk.") zur Mangelbeseitigung. Objekt: ".$m['immobilie'].", ".$m['adresse'].". Anlage: ".$m['anlage']." (".$m['hersteller'].", Bj. ".$m['baujahr']."). Mangel (".($m['wesentlich']?'WESENTLICH, aus SV-Prüfung, Frist '.($m['frist']?:'kurzfristig'):'unwesentlich')."): ".$m['text']." Bitte um: Festpreisangebot, Ausführungstermin, Materialangabe; bei wesentlichem Mangel Hinweis auf Dringlichkeit und anschließende Mangelfreimeldung an die Prüforganisation."]],
    "Du schreibst sachliche deutsche Geschäftskorrespondenz für eine Hausverwaltung. Nur der Anfragetext, keine Erklärungen.",700);
  echo json_encode(['ok'=>true,'gewerk'=>$gewerk,'firmen'=>$firmen,'anfrage'=>$txt?:("Sehr geehrte Damen und Herren,\n\nim Objekt ".$m['immobilie']." (".$m['adresse'].") ist an der Anlage „".$m['anlage']."“ folgender Mangel festgestellt worden:\n\n".$m['text']."\n\nBitte unterbreiten Sie uns kurzfristig ein Festpreisangebot mit Ausführungstermin.".($m['wesentlich']?" Es handelt sich um einen wesentlichen Mangel aus einer SV-Prüfung — nach Behebung ist eine Mangelfreimeldung an die Prüforganisation erforderlich.":"")."\n\nMit freundlichen Grüßen\nHausverwaltung")],JSON_UNESCAPED_UNICODE); break;
}

case 'angebot_bewerten': {
  $id=(int)($in['mangel_id'] ?? 0); $betrag=(float)($in['betrag'] ?? 0); $firma=trim($in['firma'] ?? ''); $besch=trim($in['beschreibung'] ?? '');
  if($id<1||$betrag<=0) fail(400,'mangel_id und betrag erforderlich');
  $st=$db->prepare("SELECT m.*,a.name anlage,a.typ,a.baujahr FROM w_maengel m JOIN w_anlagen a ON a.id=m.anlage_id WHERE m.id=?");
  $st->bind_param('i',$id); $st->execute(); $m=$st->get_result()->fetch_assoc();
  if(!$m) fail(404,'Mangel unbekannt');
  $txt=ki([['type'=>'text','text'=>"Bewerte die Angemessenheit dieses Handwerker-Angebots für eine Hausverwaltung in Berlin (Stand 2026). Anlage: ".$m['anlage']." (Typ ".$m['typ'].", Bj. ".$m['baujahr']."). Mangel: ".$m['text']." Angebot: ".number_format($betrag,2,',','.')." € brutto von ".($firma?:'unbenannter Firma').". ".($besch?("Leistungsbeschreibung: ".$besch):"")." Antworte in max 120 Wörtern: typische Preisspanne für diese Arbeit, Einordnung (günstig/marktüblich/überhöht), Empfehlung (beauftragen / nachverhandeln / Vergleichsangebot), worauf achten."]],
    "Du bist erfahrener Sachverständiger für Instandhaltungskosten der Gebäudetechnik. Sachlich, konkret, deutsch. Preisspannen als Orientierung kennzeichnen.",700);
  $st=$db->prepare("UPDATE w_maengel SET angebot=?, firma=IF(?='',firma,?), bewertung=? WHERE id=?");
  $bw=$txt?:'KI nicht erreichbar — manuell bewerten.';
  $st->bind_param('dsssi',$betrag,$firma,$firma,$bw,$id); $st->execute();
  wlog($db,$ME['id'],'angebot_bewerten',$id.' · '.$betrag.' €');
  echo json_encode(['ok'=>true,'bewertung'=>$bw],JSON_UNESCAPED_UNICODE); break;
}

case 'freimeldung': {
  $id=(int)($in['mangel_id'] ?? $_GET['mangel_id'] ?? 0); if($id<1) fail(400,'mangel_id');
  $st=$db->prepare("SELECT m.*,a.name anlage,a.typ,a.hersteller,a.baujahr,a.standort,i.name immobilie,i.adresse,d.auswertung,d.orig_name FROM w_maengel m JOIN w_anlagen a ON a.id=m.anlage_id JOIN w_immobilien i ON i.id=a.immobilie_id LEFT JOIN w_dokumente d ON d.id=m.dokument_id WHERE m.id=?");
  $st->bind_param('i',$id); $st->execute(); $m=$st->get_result()->fetch_assoc();
  if(!$m) fail(404,'Mangel unbekannt');
  wlog($db,$ME['id'],'freimeldung','Mangel '.$id);
  echo json_encode($m,JSON_UNESCAPED_UNICODE); break;
}

case 'protokoll': {
  if($ME['rolle']!=='gf') fail(403,'nur Geschäftsführung');
  $st=$db->prepare("SELECT DATE_FORMAT(p.zeit,'%d.%m. %H:%i') zeit,u.name,p.aktion,p.detail FROM w_protokoll p LEFT JOIN w_users u ON u.id=p.user_id ORDER BY p.id DESC LIMIT 100");
  $st->execute(); echo json_encode(alle($st),JSON_UNESCAPED_UNICODE); break;
}

case 'logout': {
  $st=$db->prepare("DELETE FROM w_sessions WHERE token=?"); $st->bind_param('s',$tok); $st->execute();
  echo json_encode(['ok'=>true]); break;
}

default: fail(400,'unbekannte action');
}
