# KAYYA-Get.ps1 - holt einzelne Dateien per SFTP von kayya.org (Zugang wird nie angezeigt).
# Aufruf: KAYYA-Get.ps1 "Serverpfad=lokales Ziel (absolut)"
[CmdletBinding(PositionalBinding=$false)]
param([Parameter(Position=0,ValueFromRemainingArguments=$true)][string[]]$Paare, [string[]]$Rm)
$basis=Split-Path -Parent $MyInvocation.MyCommand.Path
$z='C:\Users\BKRG\KAYYA-Zugang'
$net=[IO.File]::ReadAllText("$z\netrc.txt")
$ms=[regex]::Matches($net,'machine\s+(\S+)\s+login\s+(\S+)\s+password\s+(\S+)')
$m=$ms[$ms.Count-1]
$zeilen=@()
foreach($p in $Paare){ if(-not $p){ continue }; $t=$p.Split("=",2); $zeilen+=("get "+$t[0]+" `""+($t[1] -replace "\\","/")+"`"") }
$zeilen+='bye'
$bat=Join-Path $env:TEMP 'kayya-get.txt'; Set-Content -Path $bat -Value ($zeilen -join "`n") -Encoding ASCII
$env:KAYYA_SFTP_PW=$m.Groups[3].Value; $env:SSH_ASKPASS="$z\askpass.cmd"; $env:SSH_ASKPASS_REQUIRE='force'; $env:DISPLAY='w'
$out=& sftp.exe -o BatchMode=no -o PreferredAuthentications=password -o StrictHostKeyChecking=accept-new -b $bat ($m.Groups[2].Value+'@'+$m.Groups[1].Value) 2>&1
$code=$LASTEXITCODE; $env:KAYYA_SFTP_PW=''
if($code -ne 0){ $out | ForEach-Object {"$_"} | Where-Object { $_ -match 'denied|rror|No such' } | Select-Object -First 5 }
Write-Host ("SFTP: "+($zeilen.Count-1)+" Befehle, EXIT="+$code); exit $code
