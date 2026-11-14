# KAYYA-G5-Build.ps1 - baut die G5-App (OS\g5\index.html) aus dem vollstaendigen Funktionsstand (G4\index.html)
# und zieht ihr das G5-Erscheinungsbild an. G5 ist danach eigenstaendig: keine Verweise auf G4.
# Aufruf: powershell -NoProfile -ExecutionPolicy Bypass -File "C:\Users\BKRG\Desktop\KAYYA\KAYYA-G5-Build.ps1"
# Datei bewusst ASCII-only (PS 5.1). Texte mit Umlauten liegen in OS\g5\g5-theme.css und g5-zusatz.html (UTF-8).
$ErrorActionPreference='Stop'
$basis=Split-Path -Parent $MyInvocation.MyCommand.Path
$utf8=New-Object Text.UTF8Encoding($false)
$t=[IO.File]::ReadAllText("$basis\G4\index.html",[Text.Encoding]::UTF8)
$css=[IO.File]::ReadAllText("$basis\OS\g5\g5-theme.css",[Text.Encoding]::UTF8)
$zusatz=[IO.File]::ReadAllText("$basis\OS\g5\g5-zusatz.html",[Text.Encoding]::UTF8)
$details=[IO.File]::ReadAllText("$basis\OS\g5\g5-details.html",[Text.Encoding]::UTF8)
$details+="`n"+[IO.File]::ReadAllText("$basis\OS\g5\g5-details2.html",[Text.Encoding]::UTF8)
$version='G5 &beta;.3.0.0'
function Zaehl($name,$n){ Write-Host ("  {0,-34} {1}" -f $name,$n) }

# 1) Emojis entfernen (Surrogat-Paare, Variation Selector, ZWJ, einzelne Symbole) - Pfeile/Haken bleiben
$rxEmoji=[regex]'[\uD83C-\uD83E][\uDC00-\uDFFF]|\uFE0F|\u200D|[\u2600-\u2604\u260E\u2611\u2614\u2615\u2618\u261D\u2620-\u2623\u2626\u262A\u262E\u262F\u2638-\u263A\u2640\u2642\u2648-\u2653\u265F\u2660\u2663\u2665\u2666\u2668\u267B\u267E\u267F\u2692-\u2697\u2699\u269B\u269C\u26A1\u26AA\u26AB\u26B0\u26B1\u26BD\u26BE\u26C4\u26C5\u26C8\u26CE\u26CF\u26D1\u26D3\u26D4\u26E9\u26EA\u26F0-\u26F5\u26F7-\u26FA\u26FD\u2702\u2705\u2708-\u270D\u270F\u2712\u2728\u2744\u2747\u274C\u274E\u2753-\u2755\u2757\u2763\u2764\u23E9-\u23F3\u23F8-\u23FA\u231A\u231B\u2328\u23CF\u2B50\u2B55\u2934\u2935\u3030\u303D\u3297\u3299]'
Zaehl 'Emojis entfernt' ($rxEmoji.Matches($t).Count)
$t=$rxEmoji.Replace($t,'')

# 2) Farben: Neon -> G5-Palette (fest dunkel-warm; der Hell-Schalter ist in G5 abgeschaltet)
$hex=[ordered]@{
 '41E8FF'='D9A8D9';'7FD9E8'='C9B3C9';'9FE6F2'='B8AFA5';'0FB8D8'='B98AB9';'E8F6FF'='EDE8E2';'E8F6F4'='EDE8E2';
 '05070D'='161311';'0D1626'='201C19';'0B1322'='1E1A17';'1C2433'='2A2522';'101B2E'='201C19';'0A1220'='1A1614';
 '5E7C86'='A39B91';'04222A'='161311';'FFC24B'='E8B94A';'D89B2B'='C99A3A';'FF7AC6'='E58FB5';
 '22DD88'='4CC98A';'3DDC84'='4CC98A';'41E896'='4CC98A';'FF6B7A'='FF7A70';'FF6B6B'='FF7A70';'FF6B5E'='FF7A70';'D8455A'='E0645A';
 'B478FF'='C9A0E0';'CBDCE3'='D8D2CA';'C6D8E6'='D8D2CA';'8FA7BA'='A39B91';'8B96A4'='A39B91' }
$nHex=0; foreach($k in $hex.Keys){ $rx=[regex]("(?i)#"+$k+"\b"); $nHex+=$rx.Matches($t).Count; $t=$rx.Replace($t,('#'+$hex[$k])) }
Zaehl 'Hex-Farben ersetzt' $nHex
$rgb=[ordered]@{
 '65,232,255'='217,168,217';'94,124,134'='155,147,138';'5,7,13'='22,19,17';'13,18,32'='32,28,25';'16,27,46'='32,28,25';
 '10,18,32'='26,22,20';'20,45,70'='46,41,37';'2,4,9'='12,10,9';'255,194,75'='232,185,74';'255,122,198'='229,143,181';
 '34,221,136'='76,201,138';'61,220,132'='76,201,138';'200,220,235'='237,232,226';'230,240,255'='237,232,226';'203,215,228'='237,232,226';
 '255,107,122'='255,122,112';'255,92,122'='255,122,112';'255,107,107'='255,122,112';'255,107,94'='255,122,112' }
$nRgb=0; foreach($k in $rgb.Keys){ $p=$k.Split(','); $rx=[regex]("(rgba?)\(\s*"+$p[0]+"\s*,\s*"+$p[1]+"\s*,\s*"+$p[2]+"(?=\s*[,)])"); $nRgb+=$rx.Matches($t).Count; $t=$rx.Replace($t,('$1('+$rgb[$k])) }
Zaehl 'rgb(a)-Farben ersetzt' $nRgb
# Heller Modus: Blau/Petrol -> warmes Papier mit dunklem Lila
$hexH=[ordered]@{'076D86'='7A3F7A';'0B5D75'='6A356A';'0B4F63'='2A2420';'F2F5FA'='F6F2EC';'EEF3F8'='EFE9E1';'E7EEF5'='ECE6DE';
 '17242E'='221D19';'5B6B76'='6B635A';'38626F'='5F564D';'3C525F'='5F564D';'33505C'='5F564D';'4A6172'='5F564D' }
$nH=0; foreach($k in $hexH.Keys){ $rx=[regex]("(?i)#"+$k+"\b"); $nH+=$rx.Matches($t).Count; $t=$rx.Replace($t,('#'+$hexH[$k])) }
$rgbH=[ordered]@{'7,109,134'='122,63,122';'23,36,46'='34,29,25';'242,245,250'='246,242,236';'91,107,118'='107,99,90' }
foreach($k in $rgbH.Keys){ $p=$k.Split(','); $rx=[regex]("(rgba?)\(\s*"+$p[0]+"\s*,\s*"+$p[1]+"\s*,\s*"+$p[2]+"(?=\s*[,)])"); $nH+=$rx.Matches($t).Count; $t=$rx.Replace($t,('$1('+$rgbH[$k])) }
Zaehl 'Farben heller Modus' $nH

# 2b) Textkorrekturen (OS\g5\g5-texte.txt) und Demo-Termine an heute anpassen
$nTxt=0
foreach($z in [IO.File]::ReadAllLines("$basis\OS\g5\g5-texte.txt",[Text.Encoding]::UTF8)){
  if($z -match '^\s*#' -or $z -notmatch "`t"){ continue }
  $p=$z.Split("`t",2); $c=([regex]::Matches($t,[regex]::Escape($p[0]))).Count
  if($c -eq 0){ Write-Host ("  (Text nicht gefunden: "+$p[0].Substring(0,[Math]::Min(40,$p[0].Length))+")") } else { $nTxt+=$c; $t=$t.Replace($p[0],$p[1]) }
}
Zaehl 'Textkorrekturen' $nTxt
# Die Beispieldaten wurden um den 23.08.2026 verfasst. Alle festen Termine wandern um ganze Wochen mit,
# damit Fristen und Gueltigkeiten zum heutigen Datum passen (Wochentage bleiben erhalten). Jahresgrenzen bleiben stehen.
$anker=[datetime]'2026-08-23'; $versatz=[int]([math]::Round(((Get-Date).Date-$anker).TotalDays/7)*7)
$script:nDat=0
$rxDat=[regex]'(?<![\d.])([0-3]\d)\.([01]\d)\.(20\d\d)?(?!\d)'
$t=$rxDat.Replace($t,[Text.RegularExpressions.MatchEvaluator]{ param($m)
  $d=[int]$m.Groups[1].Value; $mo=[int]$m.Groups[2].Value
  if($d -lt 1 -or $d -gt 31 -or $mo -lt 1 -or $mo -gt 12){ return $m.Value }
  if(($d -eq 31 -and $mo -eq 12) -or ($d -eq 1 -and $mo -eq 1)){ return $m.Value }
  $mitJahr=$m.Groups[3].Success; $j=2026; if($mitJahr){ $j=[int]$m.Groups[3].Value }
  try{ $neu=(New-Object datetime($j,$mo,$d)).AddDays($versatz) }catch{ return $m.Value }
  $script:nDat++
  if($mitJahr){ return $neu.ToString('dd.MM.yyyy') } else { return $neu.ToString('dd.MM.') }
})
Zaehl ("Termine +"+$versatz+" Tage") $script:nDat
# Marken {{T+n}} (mit Jahr) und {{t+n}} (ohne Jahr) aus g5-texte.txt: Datum relativ zum Build-Tag
$inv=[Globalization.CultureInfo]::InvariantCulture
$rxMarke=[regex]'\{\{([Tte])([+-]\d+)\}\}'; Zaehl 'Relative Termine' ($rxMarke.Matches($t).Count)
$t=$rxMarke.Replace($t,[Text.RegularExpressions.MatchEvaluator]{ param($m)
  $d=(Get-Date).Date.AddDays([int]$m.Groups[2].Value)
  if($m.Groups[1].Value -ceq 'T'){ return $d.ToString('dd.MM.yyyy') } elseif($m.Groups[1].Value -ceq 'e'){ return $d.ToString('d MMM',$inv) } else { return $d.ToString('dd.MM.') }
})

# 2c) Zeitraeume mitziehen, damit sie zu den Terminen passen: Kalenderwochen, Monatsnamen, MM/JJJJ, Belegnummern, englische Daten
$heute=(Get-Date).Date
$wochen=[int]($versatz/7); $monate=[int][math]::Round($versatz/30.44)
$kwHeute=$inv.Calendar.GetWeekOfYear($heute.AddDays(3-((([int]$heute.DayOfWeek)+6)%7)),[Globalization.CalendarWeekRule]::FirstFourDayWeek,[DayOfWeek]::Monday)
$script:kwJetzt=$kwHeute-37; $script:kwAlt=$wochen; $script:n2c=0
# KW bis 30 gehoeren zu den Juli-Belegen (ziehen mit den Terminen), KW ab 31 meinen "diese Woche" (KW 37 = heute)
$t=[regex]::Replace($t,'\b(KW ?|[Ww]eek |CW )(\d{1,2})\b',[Text.RegularExpressions.MatchEvaluator]{ param($m)
  $k=[int]$m.Groups[2].Value; if($k -le 30){ $k+=$script:kwAlt } else { $k+=$script:kwJetzt }
  while($k -gt 52){ $k-=52 }; while($k -lt 1){ $k+=52 }; $script:n2c++; return $m.Groups[1].Value+$k })
$ae=[string][char]0xE4
$script:mon=@('Januar','Februar',('M'+$ae+'rz'),'April','Mai','Juni','Juli','August','September','Oktober','November','Dezember')
$script:monE=@('January','February','March','April','May','June','July','August','September','October','November','December')
$script:rel=@{'Juni'=-2;'Juli'=-1;'August'=0;'September'=-1;'Oktober'=2;'June'=-2;'July'=-1;'August_'=0;'October'=2}
$script:heute=$heute
$t=[regex]::Replace($t,'\b(Juni|Juli|August|September|Oktober|June|July|October)\b( 20\d\d)?',[Text.RegularExpressions.MatchEvaluator]{ param($m)
  $n=$m.Groups[1].Value; $d=$script:heute.AddMonths($script:rel[$n]); $script:n2c++
  if($n -cmatch '^(June|July|October)$'){ $neu=$script:monE[$d.Month-1] } else { $neu=$script:mon[$d.Month-1] }
  if($m.Groups[2].Success){ $neu+=' '+$d.Year }; return $neu })
$script:monate=$monate
$t=[regex]::Replace($t,'(?<![\d/.])([01]\d)/(20\d\d)(?!\d)',[Text.RegularExpressions.MatchEvaluator]{ param($m)
  $mo=[int]$m.Groups[1].Value; if($mo -lt 1 -or $mo -gt 12){ return $m.Value }
  $script:n2c++; return (New-Object datetime([int]$m.Groups[2].Value,$mo,1)).AddMonths($script:monate).ToString('MM/yyyy') })
$t=[regex]::Replace($t,'\b([A-Z]{2})-(2026)-([01]\d)-(\d+)',[Text.RegularExpressions.MatchEvaluator]{ param($m)
  $d=(New-Object datetime(2026,[int]$m.Groups[3].Value,1)).AddMonths($script:monate); $script:n2c++
  return $m.Groups[1].Value+'-'+$d.ToString('yyyy-MM')+'-'+$m.Groups[4].Value })
$script:versatz=$versatz; $script:inv=$inv
$t=[regex]::Replace($t,'\b(\d{1,2}) (Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) (20\d\d)\b',[Text.RegularExpressions.MatchEvaluator]{ param($m)
  try{ $d=[datetime]::ParseExact($m.Value,'d MMM yyyy',$script:inv).AddDays($script:versatz) }catch{ return $m.Value }
  $script:n2c++; return $d.ToString('d MMM yyyy',$script:inv) })
$kurz=@('Jan','Feb',('M'+$ae+'r'),'Apr','Mai','Jun','Jul','Aug','Sep','Okt','Nov','Dez')
$script:kurz=$kurz
$t=[regex]::Replace($t,'TREND=\[\[.*?\]\];',[Text.RegularExpressions.MatchEvaluator]{ param($m)
  $script:lauf=$script:heute.Month-1-([regex]::Matches($m.Value,'\["')).Count
  return [regex]::Replace($m.Value,'\["[^"]+"',[Text.RegularExpressions.MatchEvaluator]{ param($x)
    $script:lauf++; return '["'+$script:kurz[(($script:lauf-1)%12+12)%12]+'"' }) })
Zaehl 'Zeitraeume angepasst' $script:n2c

# 3) Eigenstaendigkeit: keine Verweise auf G4
$t=$t.Replace('../g4.html','../g5.html').Replace("'/g4/?","'/g5/?").Replace('/g4/?blick','/g5/?blick')
$rxG4=[regex]'\bG4\b(?!-APP)'; Zaehl 'Bezeichnung G4 -> G5' ($rxG4.Matches($t).Count); $t=$rxG4.Replace($t,'G5')
$t=[regex]::Replace($t,'(?s)<title>.*?</title>','<title>KA&#562;&#562;A G5</title>',1)
$wortmarke='<span class="marke" onclick="location.href=''../g5.html''" title="Zur &Uuml;bersicht"><svg viewBox="-8 -26 276.19 134" role="img" aria-label="KAYYA"><g class="ka"><path d="M4.200 53.768 L42.994 100.000 L48.476 100.000 L6.521 50.000 L48.476 0.000 L42.994 0.000 L4.200 46.232 L4.200 0.000 L0.000 0.000 L0.000 100.000 L4.200 100.000 Z"/><path d="M79.072 0.000 L49.072 74.253 L49.072 85.464 L79.072 11.212 L109.072 85.464 L109.072 74.253 Z"/><path d="M230.190 0.000 L260.190 74.253 L260.190 85.464 L230.190 11.212 L200.190 85.464 L200.190 74.253 Z"/></g><g class="yy"><path d="M120.972 62.394 L95.764 0.000 L91.234 0.000 L118.872 68.408 L118.872 100.000 L123.072 100.000 L123.072 68.408 L150.711 0.000 L146.181 0.000 Z"/><path d="M188.290 62.394 L213.499 0.000 L218.029 0.000 L190.390 68.408 L190.390 100.000 L186.190 100.000 L186.190 68.408 L158.551 0.000 L163.081 0.000 Z"/><rect x="99.56" y="-16.0" width="42.82" height="4.2" rx="2.1"/><rect x="166.88" y="-16.0" width="42.82" height="4.2" rx="2.1"/></g></svg></span>'
$t=[regex]::Replace($t,'(?s)<span class="marke"[^>]*>.*?</span>',$wortmarke.Replace('$','$$'),1)
$t=[regex]::Replace($t,'<span class="vchip">[^<]*</span>',('<span class="vchip">'+$version+' &middot; DB</span>'),1)
$t=[regex]::Replace($t,'(?s)<button id="zurueck"[^>]*>.*?</button>','<button id="zurueck" onclick="location.href=''../g5.html''">&lsaquo; &Uuml;bersicht</button>',1)

# 4) Start/Heim: die Uebersicht ist g5.html; der Hell-Schalter entfaellt
$n=([regex]"document\.getElementById\('home'\)\.style\.display='block';").Matches($t).Count; Zaehl 'heim() -> Uebersicht' $n
$t=$t.Replace("document.getElementById('home').style.display='block';","location.href='../g5.html'; return;")
# Heller Modus bleibt (Schalter im Kopf); die Beschriftung des Schalters ist Text statt Symbol
$t=$t.Replace('b.textContent = hell ? "" : "";','b.textContent = hell ? "Dunkel" : "Hell";')
$t=$t.Replace('hell ? "#F6F2EC" : "#161311"','hell ? "#F6F2EC" : "#161311"')

# 5) G5-Stylesheet (einmal, vor dem ersten </head>) und Modul-Leiste (nach dem Kopf)
$i=$t.IndexOf('</head>'); if($i -lt 0){ throw 'kein </head>' }
$t=$t.Substring(0,$i)+"<style id=""g5-theme"">`n"+$css+"`n</style>`n"+$t.Substring($i)
$i=$t.IndexOf('<div id="home">'); if($i -lt 0){ throw 'kein #home' }
$t=$t.Substring(0,$i)+$zusatz+"`n"+$details+"`n"+$t.Substring($i)

[IO.File]::WriteAllText("$basis\OS\g5\index.html",$t,$utf8)
Write-Host ("G5-App gebaut: OS\g5\index.html  ("+[math]::Round($t.Length/1KB)+" KB)")
$rest=([regex]'(?i)g4').Matches($t) | ForEach-Object { $t.Substring([Math]::Max(0,$_.Index-25),[Math]::Min(60,$t.Length-[Math]::Max(0,$_.Index-25))) -replace '\s+',' ' } | Select-Object -Unique
Write-Host ("Verbleibende 'g4'-Stellen: "+@($rest).Count); $rest | Select-Object -First 25 | ForEach-Object { Write-Host ("   ... "+$_) }
