# KAYYA-Status.ps1 - Drei-Welten-Abgleich: lokal <-> kayya.org <-> GitHub
# Aufruf:  powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Users\BKRG\Desktop\KAYYA\KAYYA-Status.ps1"
# Keine Zugangsdaten noetig (nur lesende HTTP-Abrufe + git status). Datei bewusst ASCII-only.

$basis='C:\Users\BKRG\Desktop\KAYYA'
$paare=@(
  @('OS\landing.html',      'https://kayya.org/index.html',        'Landing'),
  @('OS\g4.html',           'https://kayya.org/g4.html',           'G4-Schaubild'),
  @('OS\g5.html',           'https://kayya.org/g5.html',           'G5'),
  @('G4\index.html',        'https://kayya.org/g4/index.html',     'G4-App'),
  @('OS\g5\index.html',     'https://kayya.org/g5/index.html',     'G5-Frontend'),
  @('OS\wartung.html',      'https://kayya.org/wartung.html',      'Wartungsuebersicht'),
  @('OS\docs\invest.html',  'https://kayya.org/docs/invest.html',  'Investor-Deck'),
  @('OS\docs\docs.html',    'https://kayya.org/docs/docs.html',    'Unterlagen-Seite'),
  @('OS\index.html',        'https://kayya.org/beta1.html',        'G3 (beta1)')
)
# Muss vom Server mit 403/404 beantwortet werden (Docs-Schutz, Schluessel, Setup-Skripte)
$gesperrt=@('docs/abo-modell.html','docs/260723_V2_KAYYA_Lastenheft.docx','docs/260721_V1_KAYYA_Ideenpapier.docx',
  'docs/260721_V1_KAYYA_Investorendeck.pptx','docs/260721_V1_KAYYA_Foerderfahrplan.docx','docs/260721_V1_KAYYA_Einschaetzung.docx',
  'wartung-setup.php','kayya-setup.php','kayya-key.php','kayya-db.php')

[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12
$probleme=@()
# Tor passieren: Zugangswoerter liegen lokal in KAYYA-Zugang\torworte.txt (nie im Repo); das Cookie gilt fuer alle Abrufe
$cc=New-Object Net.CookieContainer
$torDatei='C:\Users\BKRG\KAYYA-Zugang\torworte.txt'
if(Test-Path $torDatei){
  foreach($zeile in [IO.File]::ReadAllLines($torDatei,[Text.Encoding]::UTF8)){
    if($zeile -match '^\s*#' -or $zeile -notmatch '=') { continue }
    $k=$zeile.Split('=',2)[0].Trim(); $w=$zeile.Split('=',2)[1]
    try{
      $req=[Net.HttpWebRequest]::Create('https://kayya.org/gate.php'); $req.Method='POST'; $req.ContentType='application/json'; $req.CookieContainer=$cc
      $body=[Text.Encoding]::UTF8.GetBytes(('{"bereich":"'+$k+'","wort":'+(ConvertTo-Json $w)+'}')); $req.ContentLength=$body.Length
      $s=$req.GetRequestStream(); $s.Write($body,0,$body.Length); $s.Close(); $resp=$req.GetResponse(); $resp.Close()
    }catch{ Write-Host ("  !! Tor fuer '"+$k+"' abgelehnt - Zugangswort in torworte.txt pruefen"); $probleme+=('Tor abgelehnt: '+$k) }
  }
} else { Write-Host '  ?  KAYYA-Zugang\torworte.txt fehlt - geschuetzte Seiten koennen nicht verglichen werden' }
function holen($url){ $req=[Net.HttpWebRequest]::Create($url); $req.CookieContainer=$cc; $req.AllowAutoRedirect=$false; $resp=$req.GetResponse(); $ms=New-Object IO.MemoryStream; $resp.GetResponseStream().CopyTo($ms); $code=[int]$resp.StatusCode; $resp.Close(); if($code -ne 200){ throw ("HTTP "+$code) }; return $ms.ToArray() }
# Hash mit normalisierten Zeilenenden (CRLF->LF), damit Git-Normalisierung keine Phantom-Abweichungen erzeugt
function normHash($bytes){ $t=[Text.Encoding]::UTF8.GetString($bytes) -replace "`r`n","`n"; [BitConverter]::ToString(([Security.Cryptography.SHA256]::Create()).ComputeHash([Text.Encoding]::UTF8.GetBytes($t))) }
function httpCode($url){
  try{ $r=[Net.HttpWebRequest]::Create($url); $r.Method='GET'; $r.AllowAutoRedirect=$false; $r.Timeout=15000; $resp=$r.GetResponse(); $c=[int]$resp.StatusCode; $resp.Close(); return $c }
  catch [Net.WebException]{ if($_.Exception.Response){ return [int]$_.Exception.Response.StatusCode } else { return 0 } }
  catch{ return 0 }
}

Write-Host ''
Write-Host '=== LOKAL <-> kayya.org (Inhaltsvergleich) ==='
foreach($p in $paare){
  $lp=Join-Path $basis $p[0]
  if(-not (Test-Path $lp)){ Write-Host ("  ?  "+$p[2]+" - lokale Datei fehlt: "+$p[0]); continue }
  try{
    $l=normHash([IO.File]::ReadAllBytes($lp))
    $r=normHash((holen ($p[1]+'?v='+(Get-Random))))
    if($l -eq $r){ Write-Host ("  OK "+$p[2]) }
    else { Write-Host ("  !! "+$p[2]+" - WEICHT AB (lokal != live)"); $probleme+=($p[2]+': lokal != kayya.org') }
  }catch{ Write-Host ("  !! "+$p[2]+" - live nicht erreichbar"); $probleme+=($p[2]+': live nicht erreichbar') }
}

Write-Host ''
Write-Host '=== TOR (ohne Cookie muss der Server umleiten, 302) ==='
foreach($t in @('g4/','g5/','g4.html','g5.html','beta1.html','wartung.html','docs/invest.html')){
  $code=httpCode('https://kayya.org/'+$t)
  if($code -eq 302 -or $code -eq 301 -or $code -eq 303){ Write-Host ("  OK "+$t+" (gesperrt, "+$code+")") }
  elseif($code -eq 0){ Write-Host ("  ?  "+$t+" (keine Antwort)") }
  else { Write-Host ("  !! "+$t+" OHNE TOR ERREICHBAR ("+$code+")"); $probleme+=('Tor offen: '+$t) }
}

Write-Host ''
Write-Host '=== GESPERRT (muss 403/404 liefern) ==='
foreach($g in $gesperrt){
  $code=httpCode('https://kayya.org/'+$g)
  if($code -eq 403 -or $code -eq 404){ Write-Host ("  OK "+$g+" ("+$code+")") }
  elseif($code -eq 0){ Write-Host ("  ?  "+$g+" (keine Antwort - nicht pruefbar)") }
  else { Write-Host ("  !! "+$g+" OEFFENTLICH ("+$code+")"); $probleme+=('OEFFENTLICH: '+$g) }
}

Write-Host ''
Write-Host '=== GIT (GitHub kayyaorg/kayya) ==='
Set-Location $basis
$dirty=git status --porcelain
if($dirty){
  Write-Host '  !! Nicht committete Aenderungen:'
  $dirty | ForEach-Object { Write-Host ('     '+$_) }
  $probleme+='GitHub: nicht committete Aenderungen'
} else { Write-Host '  OK Arbeitskopie sauber (alles committet)' }
git fetch origin 2>$null | Out-Null
$ab=(git rev-list --left-right --count origin/main...HEAD 2>$null) -split '\s+'
if($ab.Count -ge 2){
  if([int]$ab[1] -gt 0){ Write-Host ("  !! "+$ab[1]+" Commit(s) noch nicht gepusht"); $probleme+='GitHub: ungepushte Commits' }
  elseif([int]$ab[0] -gt 0){ Write-Host ("  !! GitHub ist "+$ab[0]+" Commit(s) voraus - git pull noetig"); $probleme+='GitHub: pull noetig' }
  else { Write-Host '  OK Mit GitHub synchron' }
}

Write-Host ''
if($probleme.Count -eq 0){ Write-Host '>>> ALLES SYNCHRON: lokal = kayya.org = GitHub, nichts Vertrauliches oeffentlich <<<' -ForegroundColor Green }
else { Write-Host ('>>> '+$probleme.Count+' ABWEICHUNG(EN): '+($probleme -join ' | ')) -ForegroundColor Yellow }
Write-Host ''
