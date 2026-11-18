# KAYYA-Film-Plan.ps1 - schneidet den Film (OS\film.html) auf eine neue Sprachaufnahme.
# Misst die Sprechpausen der Aufnahme, ordnet jeder Szene ihre Startzeit zu und schreibt
#   OS\video\kayya-film.json   (Schnittplan, den film.html beim Start laedt)
#   OS\video\kayya-stimme.m4a  (Tonspur: Stimme, Lautstaerke angeglichen, mit Ausklang)
# Aufruf:  powershell -NoProfile -ExecutionPolicy Bypass -File KAYYA-Film-Plan.ps1 -Stimme "Video\261118_Sprecherin_V1.mp3"
#          -OhneZusatz, wenn die Absaetze "Eskalation" und "Zahllauf" im Sprechtext geloescht wurden
#          -Plan / -Ton   andere Zieldateien (zum Testen)
# Der Sprechtext muss dem ElevenLabs-Skript folgen (Arbeitsdokumente\261118_V3_..._ElevenLabs-Skript.txt):
# die Absaetze bestimmen die Szenen. Datei bewusst ASCII-only (PS 5.1).
param([Parameter(Mandatory=$true)][string]$Stimme, [switch]$OhneZusatz,
      [string]$Plan='OS\video\kayya-film.json', [string]$Ton='OS\video\kayya-stimme.m4a')
$ErrorActionPreference='Stop'
$basis=Split-Path -Parent $MyInvocation.MyCommand.Path
$inv=[Globalization.CultureInfo]::InvariantCulture
$ff=(Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Recurse -Filter ffmpeg.exe -ErrorAction SilentlyContinue | Select-Object -First 1).FullName
if(-not $ff){ $c=Get-Command ffmpeg -ErrorAction SilentlyContinue; if($c){ $ff=$c.Source } }
if(-not $ff){ throw 'ffmpeg nicht gefunden' }
$probe=Join-Path (Split-Path $ff) 'ffprobe.exe'
$quelle=if([IO.Path]::IsPathRooted($Stimme)){ $Stimme } else { Join-Path $basis $Stimme }
if(-not (Test-Path $quelle)){ throw "Aufnahme nicht gefunden: $quelle" }
function Zahl($s){ return [double]::Parse($s,$inv) }

$ErrorActionPreference='Continue'
$laenge=Zahl ((& $probe -v error -show_entries format=duration -of csv=p=0 $quelle) | Select-Object -First 1)
$roh=(& $ff -hide_banner -i $quelle -af 'silencedetect=noise=-38dB:d=0.18' -f null - 2>&1 | ForEach-Object { "$_" }) -join "`n"
$ErrorActionPreference='Stop'
$st=[regex]::Matches($roh,'silence_start: ([0-9.]+)'); $en=[regex]::Matches($roh,'silence_end: ([0-9.]+)')
$pausen=@()
for($i=0;$i -lt $st.Count;$i++){ $a=Zahl $st[$i].Groups[1].Value; $b=if($i -lt $en.Count){ Zahl $en[$i].Groups[1].Value } else { $laenge }; $pausen+=[pscustomobject]@{a=$a;b=$b;l=($b-$a)} }
$beginn=0.0; $ende=$laenge
if($pausen.Count -and $pausen[0].a -lt 0.08){ $beginn=$pausen[0].b }
if($pausen.Count -and $pausen[-1].b -ge ($laenge-0.08)){ $ende=$pausen[-1].a }
$innen=@($pausen | Where-Object { $_.a -ge ($beginn+0.05) -and $_.b -le ($ende-0.05) })

# Saetze je Absatz laut Skript (Fassung A): 1 Sie kennen das, 2 Jede Massnahme, 3 Vorgaben, 4 Wer daran festhaelt, 5 dreht um,
# 6 Was der Kostentraeger sehen will, 7 Kursleitung, 8 Eskalation, 9 Zahllauf, 10 Zeit gehoert den Teilnehmern, 11 Festangestellt, 12 Schluss
$saetze=if($OhneZusatz){ @(3,2,5,2,3,4,2,2,2,2) } else { @(3,2,5,2,3,4,2,2,2,2,2,2) }
$nAbs=$saetze.Count
if($innen.Count -lt ($nAbs-1)){ throw ("Zu wenige Pausen gefunden: "+$innen.Count+", gebraucht mindestens "+($nAbs-1)+". Passt der Sprechtext zum Skript?") }
$bruch=@($innen | Sort-Object l -Descending | Select-Object -First ($nAbs-1) | Sort-Object a)
$abs=@(); $von=$beginn
for($i=0;$i -lt $nAbs;$i++){
  $bis=if($i -lt $bruch.Count){ $bruch[$i].a } else { $ende }
  $drin=@($innen | Where-Object { $_.a -gt $von -and $_.b -lt $bis })
  $teil=@($drin | Sort-Object l -Descending | Select-Object -First ($saetze[$i]-1) | Sort-Object a)
  $s=@($von); foreach($t in $teil){ $s+=$t.b }
  while($s.Count -lt $saetze[$i]){ $s+=($von+($bis-$von)*($s.Count/$saetze[$i])) }   # fehlt eine Pause, gleichmaessig schaetzen
  $abs+=[pscustomobject]@{von=$von;bis=$bis;s=$s}
  if($i -lt $bruch.Count){ $von=$bruch[$i].b }
}
$v=0.25   # Bild kommt eine Viertelsekunde vor dem Wort
$P=$abs; $ab=[ordered]@{}; $d=[ordered]@{}
$ab.a1=0
$ab.a2=$P[0].s[2]-$v
$ab.b1=$P[1].s[0]-$v; $ab.b2=$P[1].s[1]-$v
$ab.c=$P[2].s[0]-$v;  $d.c=@(1..4 | ForEach-Object { [math]::Round(($P[2].s[$_]-$ab.c-0.15)*1000) })
$ab.d1=$P[3].s[0]-$v; $ab.d2=$P[3].s[1]-$v
$ab.e1=$P[4].s[0]-$v; $ab.e2=$P[4].s[1]-$v
$ab.e3=[math]::Min($P[4].s[1]+2.0,$P[4].s[2]-1.6); $ab.e4=$P[4].s[2]-$v; $ab.f1=$P[4].s[2]+1.8
$ab.f2=$P[5].s[2]-$v; $ab.f3=$P[5].s[3]-$v
$ab.g1=$P[6].s[0]-$v; $ab.g2=$P[6].s[0]+0.5*($P[6].s[1]-$P[6].s[0])
if($OhneZusatz){ $z=7; $ab.zahl=$P[6].s[1]-$v; $ab.h1=$P[7].s[0]-$v; $ab.esk=$P[7].s[1]-$v }
else { $z=9; $ab.esk=$P[7].s[0]-$v; $ab.zahl=$P[8].s[0]-$v; $ab.h1=$P[9].s[0]-$v; $ab.h2=$P[9].s[1]-$v }
$ab.j1=$P[$z+1].s[0]-$v
$ab.i1=$P[$z+1].s[1]+0.5*($P[$z+1].bis-$P[$z+1].s[1])
$zEnde=$P[$z+2].s[0]-$v
if(($zEnde-$ab.i1) -gt 3.6){ $ab.i2=$zEnde-1.4 }
$ab.z=$zEnde
$dauer=[math]::Round($ende+4.5,2); $ueb=[math]::Round($ende+0.9,2)
foreach($k in @($ab.Keys)){ $ab[$k]=[math]::Round([math]::Max(0,[double]$ab[$k]),2) }

# Tonspur: Lautstaerke angleichen, Ausklang anhaengen
$tonZiel=Join-Path $basis $Ton; New-Item -ItemType Directory -Force (Split-Path $tonZiel) | Out-Null
$ErrorActionPreference='Continue'
& $ff -hide_banner -loglevel error -y -i $quelle -af 'loudnorm=I=-16:TP=-1.5:LRA=11,apad=pad_dur=5' -t $dauer.ToString($inv) -ar 48000 -c:a aac -b:a 192k $tonZiel 2>&1 | Out-Null
$ErrorActionPreference='Stop'
if(-not (Test-Path $tonZiel)){ throw 'Tonspur konnte nicht geschrieben werden' }

$json=[ordered]@{ erzeugt=(Get-Date).ToString('yyyy-MM-dd HH:mm'); quelle=(Split-Path $quelle -Leaf); audio=($Ton -replace '^OS\\','' -replace '\\','/'); dauer=$dauer; uebergabe=$ueb; ab=$ab; d=$d }
$planZiel=Join-Path $basis $Plan
[IO.File]::WriteAllText($planZiel,($json | ConvertTo-Json -Depth 5),(New-Object Text.UTF8Encoding($false)))
Write-Host ("Aufnahme: {0:N1} s, Sprache von {1:N1} bis {2:N1} s, {3} Pausen, {4} Absaetze" -f $laenge,$beginn,$ende,$innen.Count,$nAbs)
for($i=0;$i -lt $nAbs;$i++){ Write-Host ("  Absatz {0,2}: {1,6:N1} - {2,6:N1} s   Saetze bei {3}" -f ($i+1),$abs[$i].von,$abs[$i].bis,(($abs[$i].s | ForEach-Object { $_.ToString('N1',$inv) }) -join ', ')) }
Write-Host ("Film: {0:N1} s. Plan: {1}   Ton: {2}" -f $dauer,$Plan,$Ton)
