# KAYYA-Status.ps1 - Drei-Welten-Abgleich: lokal <-> kayya.org <-> GitHub
# Aufruf:  powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Users\BKRG\Desktop\KAYYA\KAYYA-Status.ps1"
# Keine Zugangsdaten noetig (nur lesende HTTP-Abrufe + git status).

$basis='C:\Users\BKRG\Desktop\KAYYA'
$paare=@(
  @('OS\landing.html',      'https://kayya.org/index.html',        'Landing'),
  @('OS\g4.html',           'https://kayya.org/g4.html',           'G4-Schaubild'),
  @('OS\g5.html',           'https://kayya.org/g5.html',           'G5'),
  @('G4\index.html',        'https://kayya.org/g4/index.html',     'G4-App'),
  @('OS\wartung.html',      'https://kayya.org/wartung.html',      'Wartungsuebersicht'),
  @('OS\docs\invest.html',  'https://kayya.org/docs/invest.html',  'Investor-Deck'),
  @('OS\docs\abo-modell.html','https://kayya.org/docs/abo-modell.html','Erloesmodell'),
  @('OS\index.html',        'https://kayya.org/beta1.html',        'G3 (beta1)')
)
$wc=New-Object Net.WebClient
$sha=[Security.Cryptography.SHA256]::Create()
$probleme=@()
# Hash mit normalisierten Zeilenenden (CRLF->LF), damit Git-Normalisierung keine Phantom-Abweichungen erzeugt
function normHash($bytes){ $t=[Text.Encoding]::UTF8.GetString($bytes) -replace "`r`n","`n"; [BitConverter]::ToString(([Security.Cryptography.SHA256]::Create()).ComputeHash([Text.Encoding]::UTF8.GetBytes($t))) }

Write-Host ''
Write-Host '=== LOKAL <-> kayya.org (Byte-Vergleich) ==='
foreach($p in $paare){
  $lp=Join-Path $basis $p[0]
  if(-not (Test-Path $lp)){ Write-Host ("  ?  "+$p[2]+" - lokale Datei fehlt: "+$p[0]); continue }
  try{
    $l=normHash([IO.File]::ReadAllBytes($lp))
    $r=normHash($wc.DownloadData($p[1]+'?v='+(Get-Random)))
    if($l -eq $r){ Write-Host ("  OK "+$p[2]) }
    else { Write-Host ("  !! "+$p[2]+" - WEICHT AB (lokal != live)"); $probleme+=($p[2]+': lokal != kayya.org') }
  }catch{ Write-Host ("  !! "+$p[2]+" - live nicht erreichbar"); $probleme+=($p[2]+': live nicht erreichbar') }
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
if($probleme.Count -eq 0){ Write-Host '>>> ALLES SYNCHRON: lokal = kayya.org = GitHub <<<' -ForegroundColor Green }
else { Write-Host ('>>> '+$probleme.Count+' ABWEICHUNG(EN): '+($probleme -join ' | ')) -ForegroundColor Yellow }
Write-Host ''
