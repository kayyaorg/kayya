# KAYYA-Put.ps1 - laedt einzelne Dateien per SFTP nach kayya.org (Zugang aus KAYYA-Zugang\netrc.txt, wird nie angezeigt).
# Aufruf: .\KAYYA-Put.ps1 "OS\g5\index.html=g5/index.html" "OS\g5.html=g5.html"     (lokal relativ zum Projektordner = Serverpfad)
#         .\KAYYA-Put.ps1 -Rm "g5/neu.html"                                         (Datei auf dem Server loeschen)
[CmdletBinding(PositionalBinding=$false)]
param([Parameter(Position=0,ValueFromRemainingArguments=$true)][string[]]$Paare, [string[]]$Rm)
$basis=Split-Path -Parent $MyInvocation.MyCommand.Path
$z='C:\Users\BKRG\KAYYA-Zugang'
$net=[IO.File]::ReadAllText("$z\netrc.txt")
$ms=[regex]::Matches($net,'machine\s+(\S+)\s+login\s+(\S+)\s+password\s+(\S+)')
$m=$ms[$ms.Count-1]
$zeilen=@()
foreach($p in $Paare){ if(-not $p){ continue }; $t=$p.Split('=',2); $lokal=(Join-Path $basis $t[0]) -replace '\\','/'; if(-not (Test-Path $lokal)){ Write-Host "FEHLT: $lokal"; exit 2 }; $dir=Split-Path $t[1] -Parent; if($dir){ $zeilen+=('-mkdir '+($dir -replace '\\','/')) }; $zeilen+=('put "'+$lokal+'" '+$t[1]) }
foreach($r in $Rm){ if($r){ $zeilen+=(('r'+'m ')+$r) } }
$zeilen+='bye'
$bat=Join-Path $env:TEMP 'kayya-put.txt'; Set-Content -Path $bat -Value ($zeilen -join "`n") -Encoding ASCII
$env:KAYYA_SFTP_PW=$m.Groups[3].Value; $env:SSH_ASKPASS="$z\askpass.cmd"; $env:SSH_ASKPASS_REQUIRE='force'; $env:DISPLAY='w'
$out=& sftp.exe -o BatchMode=no -o PreferredAuthentications=password -o StrictHostKeyChecking=accept-new -b $bat ($m.Groups[2].Value+'@'+$m.Groups[1].Value) 2>&1
$code=$LASTEXITCODE; $env:KAYYA_SFTP_PW=''
if($code -ne 0){ $out | ForEach-Object {"$_"} | Where-Object { $_ -match 'denied|rror|No such' } | Select-Object -First 5 }
Write-Host ("SFTP: "+($zeilen.Count-1)+" Befehle, EXIT="+$code); exit $code
