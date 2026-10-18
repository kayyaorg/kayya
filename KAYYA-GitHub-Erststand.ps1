# ============================================================
#  KAYYA-GitHub-Erststand.ps1  -  verbindet den Ordner KAYYA mit
#  https://github.com/kayyaorg/kayya und schiebt den Stand hoch.
#  Einmalig ausfuehren: Rechtsklick -> "Mit PowerShell ausfuehren".
#  Beim ersten Mal oeffnet GitHub einmal den Browser zur Anmeldung.
# ============================================================
$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot
$git = Join-Path ${env:ProgramFiles} "Git\cmd\git.exe"
if (!(Test-Path $git)) { $git = "git" }
& $git --version | Out-Null
if (-not (Test-Path ".git")) { Write-Host "Kein Repository in $PSScriptRoot" -ForegroundColor Yellow; exit 2 }
$hasOrigin = (& $git remote) -contains "origin"
if (-not $hasOrigin) { & $git remote add origin https://github.com/kayyaorg/kayya.git }
else { & $git remote set-url origin https://github.com/kayyaorg/kayya.git }
Write-Host "Schiebe Stand nach github.com/kayyaorg/kayya ..." -ForegroundColor Cyan
& $git push -u origin main
if ($LASTEXITCODE -eq 0) { Write-Host "FERTIG - Erststand liegt auf GitHub." -ForegroundColor Green }
else { Write-Host "Push fehlgeschlagen (Code $LASTEXITCODE). Ist das Repository leer und privat angelegt?" -ForegroundColor Red }
