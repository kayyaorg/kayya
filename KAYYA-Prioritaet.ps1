# ============================================================
#  KAYYA-Prioritaet.ps1  -  versiegelt den aktuellen Stand aller
#  Projektdateien mit unabhaengigen Zeitstempeln (RFC 3161).
#  Belegt spaeter: Diese Dateien existierten genau so zu diesem Zeitpunkt.
#  Rechtsklick -> "Mit PowerShell ausfuehren". Dauer: unter einer Minute.
#  Ergebnis: Ordner Prioritaet\ mit Manifest, Zeitstempeln und Pruefzertifikaten.
#  Inhalte verlassen den Rechner NICHT, nur ein einziger Hashwert geht an den Dienst.
# ============================================================
$ErrorActionPreference = "Stop"
[Console]::OutputEncoding = [Text.Encoding]::UTF8
Set-Location $PSScriptRoot

$openssl = @( (Join-Path ${env:ProgramFiles} "Git\usr\bin\openssl.exe"),
              (Join-Path ${env:ProgramFiles} "Git\mingw64\bin\openssl.exe") ) | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $openssl) { Write-Host "openssl nicht gefunden (kommt mit Git fuer Windows)." -ForegroundColor Red; exit 2 }
$curl = "curl.exe"

$stamp = Get-Date -Format "yyMMdd_HHmm"
$dir = Join-Path $PSScriptRoot "Prioritaet"
New-Item -ItemType Directory -Force $dir | Out-Null

# 1) Manifest: SHA-256 jeder Projektdatei (ohne Ausgeschlossenes aus .gitignore)
$head  = (git rev-parse HEAD).Trim()
$files = git -c core.quotepath=off ls-files -co --exclude-standard | Where-Object { $_ -and $_ -notlike "Prioritaet/*" } | Sort-Object
$lines = @("KAYYA Prioritaetsnachweis",
           "Erstellt:  " + (Get-Date -Format o),
           "Inhaber:   Bernhard Krug",
           "Git-Stand: $head",
           "Dateien:   " + $files.Count,
           "")
foreach ($f in $files) {
  if (Test-Path -LiteralPath $f) {
    $h = (Get-FileHash -Algorithm SHA256 -LiteralPath $f).Hash.ToLower()
    $lines += "$h  $f"
  }
}
$man = Join-Path $dir ($stamp + "_manifest.txt")
[IO.File]::WriteAllLines($man, $lines, (New-Object Text.UTF8Encoding($false)))
$manHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $man).Hash.ToLower()
Write-Host ("Manifest: " + $files.Count + " Dateien, Hash " + $manHash) -ForegroundColor Cyan

# 2) Zeitstempel-Anfrage (enthaelt nur den Hashwert des Manifests)
$tsq = "$man.tsq"
& $openssl ts -query -data $man -sha256 -cert -out $tsq 2>$null

# 3) Zwei unabhaengige Zeitstempeldienste
$dienste = [ordered]@{ "freetsa" = "https://freetsa.org/tsr"; "sectigo" = "http://timestamp.sectigo.com" }
$ok = 0
foreach ($k in $dienste.Keys) {
  $tsr = "$man.$k.tsr"
  & $curl -s -S -m 30 -H "Content-Type: application/timestamp-query" --data-binary "@$tsq" $dienste[$k] -o $tsr
  if ($LASTEXITCODE -eq 0 -and (Test-Path $tsr) -and (Get-Item $tsr).Length -gt 200) {
    $m = & $openssl ts -reply -in $tsr -text 2>$null | Select-String "Time stamp:" | Select-Object -First 1
    $zeit = if ($m) { $m.Line.Trim() } else { "Zeitstempel erhalten" }
    Write-Host ("  " + $k + ": " + $zeit) -ForegroundColor Green; $ok++
  } else { Write-Host ("  " + $k + ": fehlgeschlagen") -ForegroundColor Yellow; Remove-Item $tsr -ErrorAction SilentlyContinue }
}

# 4) Pruefzertifikate von freetsa fuer die spaetere Verifikation
& $curl -s -m 30 https://freetsa.org/files/cacert.pem -o (Join-Path $dir "freetsa_cacert.pem")
& $curl -s -m 30 https://freetsa.org/files/tsa.crt    -o (Join-Path $dir "freetsa_tsa.crt")

# 5) Anleitung zur Verifikation daneben legen
$hilfe = @(
 "Verifikation (z. B. durch einen Sachverstaendigen):",
 "  openssl ts -verify -in <manifest>.freetsa.tsr -data <manifest> -CAfile freetsa_cacert.pem -untrusted freetsa_tsa.crt",
 "Einzelne Datei pruefen: SHA-256 der Datei berechnen und mit der Zeile im Manifest vergleichen.",
 "Hinweis: freetsa und sectigo sind unabhaengige, aber keine qualifizierten eIDAS-Zeitstempel.",
 "Fuer die hoechste Beweiskraft (Art. 41 eIDAS) zusaetzlich einen qualifizierten Zeitstempel, z. B. bei D-Trust, einholen.")
[IO.File]::WriteAllLines((Join-Path $dir "LIESMICH.txt"), $hilfe, (New-Object Text.UTF8Encoding($false)))

# 6) Im Repository sichern und zu GitHub schieben (weiterer datierter Beleg)
if ($ok -gt 0) {
  git add Prioritaet | Out-Null
  git commit -m ("Prioritaetsnachweis " + $stamp + " (Manifest " + $manHash.Substring(0,12) + ")") -- Prioritaet | Out-Null
  git push 2>$null
  Write-Host ("FERTIG - " + $ok + " Zeitstempel, gesichert in Prioritaet\ und auf GitHub.") -ForegroundColor Green
} else {
  Write-Host "Kein Zeitstempel erhalten - Internetverbindung pruefen und erneut starten." -ForegroundColor Red; exit 1
}
