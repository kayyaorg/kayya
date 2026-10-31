# ============================================================
#  KAYYA-Deploy.ps1  -  laedt den aktuellen Stand aus KAYYA\OS
#  auf ALLE Ziele in C:\Users\BKRG\KAYYA-Zugang\ziele.txt
#  Zugangsdaten: C:\Users\BKRG\KAYYA-Zugang\netrc.txt (wird nie angezeigt)
#  SFTP: Windows-OpenSSH sftp.exe + askpass.exe (IONOS braucht moderne
#        SSH-Verfahren, die Git-curl/libssh2 nicht kann)
#  FTP/FTPS (z.B. kayya.de cPanel): weiterhin Git-curl
#  Aufruf:  .\KAYYA-Deploy.ps1            -> index.html, landing.html, g5.html (G 0.5), Proxys, .htaccess, docs\
#           .\KAYYA-Deploy.ps1 -NurIndex  -> nur index.html
#           .\KAYYA-Deploy.ps1 -Key       -> zusaetzlich KAYYA-Zugang\kayya-key.php (Anthropic-Key) einmalig hochladen
#           .\KAYYA-Deploy.ps1 -NurPruefen-> nichts hochladen, nur von aussen pruefen
#           .\KAYYA-Deploy.ps1 -DocsBereinigen -> zusaetzlich interne Unterlagen aus docs/ auf dem Server loeschen
# ============================================================
param([switch]$NurIndex, [switch]$Key, [switch]$NurPruefen, [switch]$DocsBereinigen)
$ErrorActionPreference = "Stop"
try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch {}
$zugang = @("C:\Users\BKRG\KAYYA-Zugang", (Join-Path $env:USERPROFILE "KAYYA-Zugang")) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $zugang) { $zugang = Join-Path $env:USERPROFILE "KAYYA-Zugang" }
$ziele  = Join-Path $zugang "ziele.txt"
$netrc  = Join-Path $zugang "netrc.txt"
$curl   = Join-Path ${env:ProgramFiles} "Git\mingw64\bin\curl.exe"   # FTPS
if (!(Test-Path $curl)) { $curl = "curl.exe" }
$sftpExe = Join-Path $env:WINDIR "System32\OpenSSH\sftp.exe"

if (!(Test-Path $ziele) -or !(Test-Path $netrc)) {
  Write-Host "EINRICHTUNG FEHLT: $zugang\ziele.txt und netrc.txt - siehe $zugang\ZUGANG-EINRICHTEN.txt" -ForegroundColor Yellow; exit 2 }
if ((Get-Content $netrc -Raw) -match 'DEIN-BENUTZER|DEIN-PASSWORT') {
  Write-Host "netrc.txt enthaelt noch Platzhalter - bitte Benutzer/Passwort eintragen (siehe ZUGANG-EINRICHTEN.txt)." -ForegroundColor Yellow; exit 2 }

# askpass.cmd: gibt nur den Inhalt der Umgebungsvariable KAYYA_SFTP_PW aus.
# (.cmd statt .exe: Windows-Anwendungssteuerung blockiert unsignierte EXEs)
$askpass = Join-Path $zugang "askpass.cmd"
Set-Content $askpass -Encoding ascii -Value "@echo off`r`npowershell -NoProfile -Command `"[Console]::Out.Write(`$env:KAYYA_SFTP_PW)`""

# SEIT 08.09.2026: Wurzel-Struktur - landing.html wird zu index.html (oeffentliche
# Eingangsseite mit Zugangswort), die Haupt-App index.html wird zu beta1.html.
$umbenennen = @{ "index.html" = "beta1.html"; "landing.html" = "index.html" }
# SEIT 16.10.2026: g5.html (G 0.5, eigenes Zugangswort) wird mit hochgeladen.
# NICHT hochladen: kayya-setup.php / kayya-upgrade1.php (Tokens, nach Gebrauch loeschen).
$files = if ($NurIndex) { @("index.html","landing.html") } else { @("index.html","landing.html","g4.html","g5.html","wartung.html","wartung-api.php","kayya-api.php","gate.php","ted-proxy.php","kayya-ki.php","foerder-proxy.php",".htaccess") }
# SEIT 31.10.2026: die Apps liegen in Unterordnern (G4-App: ..\G4\index.html -> g4/, G5-Frontend: g5\index.html -> g5/)
$apps = @()
$g4App = Join-Path (Split-Path $PSScriptRoot -Parent) 'G4\index.html'; if (-not $NurIndex -and (Test-Path $g4App)) { $apps += @{ lokal = $g4App; fern = 'g4' } }
$g5App = Join-Path $PSScriptRoot 'g5\index.html';                          if (-not $NurIndex -and (Test-Path $g5App)) { $apps += @{ lokal = $g5App; fern = 'g5' } }
$targets = @(Get-Content $ziele | Where-Object { $_ -and $_ -notmatch '^\s*#' })
if ($targets.Count -eq 0) { Write-Host "ziele.txt enthaelt noch kein aktives Ziel (Beispielzeile entkommentieren)." -ForegroundColor Yellow; exit 2 }
$ok = $true
foreach ($t in $targets) {
  $parts = $t.Split('|'); if ($parts.Count -lt 3) { Write-Host "Zeile unverstaendlich: $t" -ForegroundColor Yellow; continue }
  $name = $parts[0].Trim(); $base = $parts[1].Trim(); $check = $parts[2].Trim()
  if (-not $base.EndsWith('/')) { $base += '/' }
  $sftp = $base.StartsWith('sftp://')
  Write-Host ("== " + $name + "  (" + ($base -replace '//[^/]+', '//<host>') + ")") -ForegroundColor Cyan

  if (-not $NurPruefen) {
    $toUpload = @($files | ForEach-Object { Join-Path $PSScriptRoot $_ })
    if ($Key) { $kf = Join-Path $zugang "kayya-key.php"; if (Test-Path $kf) { $toUpload += $kf } else { Write-Host "  -Key: $kf fehlt (Inhalt: <?php return 'sk-ant-...';)" -ForegroundColor Yellow } }
    $docsDir = Join-Path $PSScriptRoot 'docs'
    # SEIT 21.10.2026: nur Seiten (.html) und der One-Pager gehen auf den Server; interne Unterlagen bleiben lokal.
    $docsFrei = "260823_V1_KAYYA_One-Pager_Kostentraeger.docx"
    $docsFiles = @(); if (-not $NurIndex -and (Test-Path $docsDir)) { $docsFiles = @(Get-ChildItem $docsDir -File | Where-Object { $_.Extension -ieq ".html" -or $_.Name -eq $docsFrei }) }
    $docsWeg = @(); if ($DocsBereinigen -and (Test-Path $docsDir)) { $docsWeg = @(Get-ChildItem $docsDir -File | Where-Object { $_.Extension -match "^\.(docx|pptx|xlsx|pdf|zip)$" -and $_.Name -ne $docsFrei } | ForEach-Object { $_.Name }) }
    $bilderDir = Join-Path $PSScriptRoot 'wbilder'
    $bilderFiles = @(); if (-not $NurIndex -and (Test-Path $bilderDir)) { $bilderFiles = @(Get-ChildItem $bilderDir -File | Where-Object { $_.Extension -match '\.(jpe?g|png)$' }) }

    if ($sftp) {
      # ---- SFTP ueber Windows-OpenSSH ----
      $u = [Uri]$base; $sftpHost = $u.Host
      $remoteDir = ($u.AbsolutePath -replace '^/~', '').Trim('/')
      $n = Get-Content $netrc -Raw
      $m = [regex]::Match($n, 'machine\s+' + [regex]::Escape($sftpHost) + '\s+login\s+(\S+)\s+password\s+(\S+)')
      if (!$m.Success) { $m = [regex]::Match($n, 'login\s+(\S+)\s+password\s+(\S+)') }
      if (!$m.Success) { Write-Host "  netrc.txt: keine Zugangsdaten gefunden." -ForegroundColor Yellow; $ok = $false }
      else {
        $bat = Join-Path $env:TEMP "kayya-sftp-batch.txt"
        $lines = @()
        if ($remoteDir) { $lines += "-mkdir $remoteDir"; $lines += "cd $remoteDir" }
        foreach ($f in $toUpload) {
          $leaf = Split-Path $f -Leaf
          $rn = if ($umbenennen.ContainsKey($leaf)) { $umbenennen[$leaf] } else { $leaf }
          $lines += ('put "' + ($f -replace '\\','/') + '" ' + $rn)
        }
        if ($docsFiles.Count -gt 0) {
          $lines += "-mkdir docs"
          foreach ($f in $docsFiles) { $lines += ('put "' + ($f.FullName -replace '\\','/') + '" docs/' + $f.Name) }
        }
        if ($bilderFiles.Count -gt 0) {
          $lines += "-mkdir wbilder"
          foreach ($f in $bilderFiles) { $lines += ('put "' + ($f.FullName -replace '\\','/') + '" wbilder/' + $f.Name) }
        }
        foreach ($a in $apps) { $lines += ("-mkdir " + $a.fern); $lines += ('put "' + ($a.lokal -replace '\\','/') + '" ' + $a.fern + '/index.html') }
        foreach ($w in $docsWeg) { $lines += ("-rm docs/" + $w) }
        if ($DocsBereinigen) { $lines += "-rm docs/abo-modell.html" }
        Set-Content $bat -Encoding ascii -Value $lines
        $env:KAYYA_SFTP_PW = $m.Groups[2].Value
        $env:SSH_ASKPASS = $askpass; $env:SSH_ASKPASS_REQUIRE = "force"
        if (-not $env:DISPLAY) { $env:DISPLAY = ":0" }
        $eap = $ErrorActionPreference; $ErrorActionPreference = "Continue"
        # WICHTIG: -o BatchMode=no MUSS vor -b stehen (sftp haengt bei -b intern
        # "batchmode yes" an; bei OpenSSH gewinnt die zuerst gesetzte Option)
        $out = & $sftpExe -4 -o BatchMode=no -o PreferredAuthentications=password -o StrictHostKeyChecking=accept-new -o ConnectTimeout=25 -o NumberOfPasswordPrompts=1 -b $bat ($m.Groups[1].Value + "@" + $sftpHost) 2>&1
        $code = $LASTEXITCODE; $ErrorActionPreference = $eap
        $env:KAYYA_SFTP_PW = ""
        Remove-Item $bat -Force -ErrorAction SilentlyContinue
        if ($code -eq 0) {
          Write-Host ("  hochgeladen (SFTP): " + $toUpload.Count + " Dateien" + $(if ($docsFiles.Count) { " + docs/ (" + $docsFiles.Count + ")" }) + $(if ($bilderFiles.Count) { " + wbilder/ (" + $bilderFiles.Count + ")" }) + $(if ($docsWeg.Count) { ", geloescht in docs/: " + $docsWeg.Count })) -ForegroundColor Green
        } else {
          $txt = (($out | ForEach-Object { "$_" }) -join "`n") -replace '(?i)(login|password)\S*','***'
          Write-Host ("  SFTP-FEHLER (exit " + $code + "): " + $txt.Trim()) -ForegroundColor Red; $ok = $false
        }
      }
    } else {
      # ---- FTP/FTPS ueber Git-curl ----
      foreach ($f in $toUpload) {
        $leaf = Split-Path $f -Leaf
        if ($umbenennen.ContainsKey($leaf)) { $leaf = $umbenennen[$leaf] }
        $cargs = @("--silent","--show-error","--fail","--stderr","-","--netrc-file",$netrc,"--connect-timeout","25","--ssl","--ftp-create-dirs","-T",$f,"$base$leaf")
        $out = & $curl @cargs
        if ($LASTEXITCODE -eq 0) { Write-Host ("  hochgeladen: " + $leaf + "  (" + (Get-Item $f).Length + " B)") -ForegroundColor Green }
        else { Write-Host ("  FEHLER bei " + $leaf + " (curl exit " + $LASTEXITCODE + "): " + (($out | Out-String) -replace '(?i)(login|password)\S*','***').Trim()) -ForegroundColor Red; $ok = $false }
      }
      foreach ($f in $docsFiles) {
        $cargs = @("--silent","--show-error","--fail","--stderr","-","--netrc-file",$netrc,"--connect-timeout","25","--ssl","--ftp-create-dirs","-T",$f.FullName,($base + "docs/" + $f.Name))
        $out = & $curl @cargs
        if ($LASTEXITCODE -eq 0) { Write-Host ("  hochgeladen: docs/" + $f.Name) -ForegroundColor Green }
        else { Write-Host ("  FEHLER bei docs/" + $f.Name + " (curl exit " + $LASTEXITCODE + "): " + (($out | Out-String) -replace '(?i)(login|password)\S*','***').Trim()) -ForegroundColor Red; $ok = $false }
      }
      foreach ($f in $bilderFiles) {
        $cargs = @("--silent","--show-error","--fail","--stderr","-","--netrc-file",$netrc,"--connect-timeout","25","--ssl","--ftp-create-dirs","-T",$f.FullName,($base + "wbilder/" + $f.Name))
        $out = & $curl @cargs
        if ($LASTEXITCODE -eq 0) { Write-Host ("  hochgeladen: wbilder/" + $f.Name) -ForegroundColor Green }
        else { Write-Host ("  FEHLER bei wbilder/" + $f.Name + " (curl exit " + $LASTEXITCODE + "): " + (($out | Out-String) -replace '(?i)(login|password)\S*','***').Trim()) -ForegroundColor Red; $ok = $false }
      }
      if ($docsWeg.Count -gt 0) {
        $cargs = @("--silent","--show-error","--stderr","-","--netrc-file",$netrc,"--connect-timeout","25","--ssl","--list-only","-o","NUL")
        foreach ($w in $docsWeg) { $cargs += @("-Q", ("-*DELE docs/" + $w)) }
        $cargs += $base
        $out = & $curl @cargs
        Write-Host ("  docs/ bereinigt: " + $docsWeg.Count + " interne Unterlagen geloescht (falls vorhanden)") -ForegroundColor Green
      }
    }
  }

  # Pruefung von aussen (Inhalt, Groesse, Proxys)
  try {
    $local = Get-Item (Join-Path $PSScriptRoot "index.html")
    $r = Invoke-WebRequest -Uri ($check.TrimEnd('/') + "/beta1.html?nocache=" + (Get-Date -Format HHmmss)) -UseBasicParsing -TimeoutSec 30
    $html = [Text.Encoding]::UTF8.GetString($r.RawContentStream.ToArray())
    $ver = [regex]::Match($html, 'KA..A OS \S+').Value
    $same = ($r.RawContentStream.Length -eq $local.Length)
    $msg = "  App (beta1.html): HTTP " + $r.StatusCode + " - " + $ver + " - " + $r.RawContentStream.Length + " B "
    if ($same) { Write-Host ($msg + "= lokal OK") -ForegroundColor Green } else { Write-Host ($msg + "<> lokal " + $local.Length + " B (noch alt oder Cache)") -ForegroundColor Yellow; $ok = $false }
    $localL = Get-Item (Join-Path $PSScriptRoot "landing.html")
    $rl = Invoke-WebRequest -Uri ($check.TrimEnd('/') + "/?nocache=" + (Get-Date -Format HHmmss)) -UseBasicParsing -TimeoutSec 30
    $htmlL = [Text.Encoding]::UTF8.GetString($rl.RawContentStream.ToArray())
    $torOk = ($htmlL.Contains('beta1.html') -and $htmlL.Contains('g4.html'))
    $sameL = ($rl.RawContentStream.Length -eq $localL.Length)
    if ($sameL -and $torOk) { Write-Host ("  Landing (/): HTTP " + $rl.StatusCode + " - beide Tore da - " + $rl.RawContentStream.Length + " B = lokal OK") -ForegroundColor Green }
    else { Write-Host ("  Landing (/): HTTP " + $rl.StatusCode + " - Tore " + $torOk + " - " + $rl.RawContentStream.Length + " B <> lokal " + $localL.Length + " B") -ForegroundColor Yellow; $ok = $false }
    $localG5 = Join-Path $PSScriptRoot "g5.html"
    if (Test-Path $localG5) {
      $rg = Invoke-WebRequest -Uri ($check.TrimEnd('/') + "/g5.html?nocache=" + (Get-Date -Format HHmmss)) -UseBasicParsing -TimeoutSec 30
      $sameG = ($rg.RawContentStream.Length -eq (Get-Item $localG5).Length)
      if ($sameG) { Write-Host ("  G 0.5 (g5.html): HTTP " + $rg.StatusCode + " - " + $rg.RawContentStream.Length + " B = lokal OK") -ForegroundColor Green }
      else { Write-Host ("  G 0.5 (g5.html): HTTP " + $rg.StatusCode + " - " + $rg.RawContentStream.Length + " B <> lokal " + (Get-Item $localG5).Length + " B") -ForegroundColor Yellow; $ok = $false }
    }
    foreach ($px in "ted-proxy.php","kayya-ki.php") {
      try { $j = Invoke-WebRequest -Uri ($check.TrimEnd('/') + "/" + $px + "?ping=1") -Headers @{Referer=$check} -UseBasicParsing -TimeoutSec 20
            Write-Host ("  " + $px + ": " + $j.Content.Trim()) -ForegroundColor DarkGray } catch { Write-Host ("  " + $px + ": nicht erreichbar") -ForegroundColor Yellow }
    }
  } catch { Write-Host ("  Pruefung fehlgeschlagen: " + $_.Exception.Message) -ForegroundColor Yellow; $ok = $false }
}
if ($ok) { Write-Host "FERTIG - alle Ziele aktuell." -ForegroundColor Green; exit 0 } else { Write-Host "FERTIG MIT HINWEISEN." -ForegroundColor Yellow; exit 1 }
