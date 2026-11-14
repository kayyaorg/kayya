# KOORDINATION — wer arbeitet woran

Gemeinsames Brett aller Claude-Sessions. Neueste Einträge oben. Zeiten in Ortszeit Berlin.

## In Arbeit (belegt)

| Seit | Session | Dateien | Ziel |
|---|---|---|---|
| 31.10. | Desktop-Session | `OS/kayya-api.php`, `OS/g5/index.html`, `OS/g5.html`, `OS/landing.html`, `G4/index.html`, `OS/KAYYA-Deploy.ps1` | Serverseitiger Zugriffsschutz, eigenständiges G5-Frontend, lesbarer Zugang mit Bereichswahl — alles committet, **Deploy steht aus** (SFTP-Passwort). Danach: `kayya-upgrade2.php?token=…` einmal aufrufen und löschen. |

## Übergaben und Fragen

### 14.11. · Desktop-Session: G5 ist jetzt die vollständige App (Build aus G4), neue G5-Startseite, Sicherheits-Header
- **G5-App = Build.** `OS/g5/index.html` wird nicht mehr von Hand gepflegt, sondern von `KAYYA-G5-Build.ps1` aus `G4/index.html` erzeugt (G4 bleibt eingefroren und unverändert). Bausteine: `OS/g5/g5-theme.css` (Look, hell und dunkel), `g5-zusatz.html` (Modulleiste, Rollen, Kostenträger-Sicht, Übersetzungen der Leiste), `g5-details.html` + `g5-details2.html` (Detailfenster je Zeile), `g5-texte.txt` (Textkorrekturen alt<TAB>neu, Marken `{{T+n}}`/`{{t+n}}`/`{{e+n}}` = Datum relativ zum Build-Tag). **Bitte `OS/g5/index.html` nie direkt ändern**, sondern die Bausteine, dann Build + Upload.
- **Termine wandern mit.** Der Build verschiebt alle Beispieldaten (Daten, KW, Monate, MM/JJJJ, Belegnummern) auf den Build-Tag. Vor Terminen neu bauen: `powershell -NoProfile -ExecutionPolicy Bypass -File KAYYA-G5-Build.ps1`, dann `KAYYA-Put.ps1 "OS\g5\index.html=g5/index.html"`.
- **Startseite** `OS/g5.html` neu (freigegebener Entwurf `OS/g5-entwurf.html`): 15 Module in vier Aufgaben, Links `g5/#/…`, Hell/Dunkel teilt sich `kayya_theme` mit der App.
- **`.htaccess`:** neuer Block Sicherheits-Header (nosniff, X-Frame-Options SAMEORIGIN, Referrer-Policy strict-origin-when-cross-origin, HSTS). Live geprüft.
- **Neue Helfer:** `KAYYA-Put.ps1` (einzelne Dateien hochladen/löschen), `KAYYA-Get.ps1` (einzelne Dateien holen). Beide lesen den Zugang, ohne ihn anzuzeigen.
- **Offen für euch/Bernhard:** `KAYYA-Zugang\torworte.txt` ist seit eurem Wort-Wechsel veraltet, deshalb meldet `KAYYA-Status.ps1` die geschützten Seiten als nicht prüfbar. Im Demo-Datenbestand mischen sich Hamburg (Sitz, Bank, Stadtwerke) und Berlin (Kursangebote) – bewusst nicht angefasst.

### 31.10. · Desktop-Session: Docs-Schutz übernommen
Die Änderungen vom 21.10. (`.htaccess`, Deploy-Filter, NDA-Links) lagen zehn Tage uncommittet und undeployed. Mit Bernhards Freigabe übernommen und committet (9f32a99). `abo-modell.html` ist nach `Arbeitsdokumente/` verschoben; `-DocsBereinigen` löscht sie auf dem Server. Wer am 21.10. daran gearbeitet hat, bitte hier eintragen — sonst wird dieses Brett beim nächsten Aufräumen entfernt.

### 21.10. 20:35 · Desktop-Session → Cloud-Session: Lücke im Docs-Schutz
Stand live um 20:31: Vom Docs-Schutz ist **noch nichts deployed**. `docs/260723_V2_KAYYA_Lastenheft.docx` liefert HTTP 200, ist also öffentlich herunterladbar.

Lücke: `OS/docs/abo-modell.html` (Konzeptpapier Erlösmodell, als **vertraulich / Geschäftsgeheimnis** gekennzeichnet) ist eine `.html`-Seite. Die neue `.htaccess`-Regel und der Deploy-Filter lassen `.html` durch. Das Papier bliebe öffentlich per Direkt-URL erreichbar (`/docs/abo-modell.html`), und der Deploy lädt es weiter hoch.

Vorschlag, im selben Zug wie dein Docs-Schutz:
1. `abo-modell.html` nach `Arbeitsdokumente/` verschieben (intern, wird nicht deployed).
2. Auf dem Server `docs/abo-modell.html` löschen.
3. In `OS/docs/invest.html` den Link „📊 Konzeptpapier Erlösmodell (v1 · vertraulich)" auf dasselbe NDA-mailto umstellen wie die anderen Unterlagen.

Die Desktop-Session fasst deine belegten Dateien nicht an. Wenn du lieber willst, dass sie die drei Punkte nach deinem Deploy übernimmt: hier eintragen „übernimm abo-modell" und die Belegung freigeben.

## Erledigt (letzte Tage)
- 21.10. Desktop-Session: Commits 4a53866 (Landing-Logo-Choreografie, G5-Redesign, Investor-Deck 19.10., Erlösmodell, Status-Skript, Fonts) und 0c78d9d (Erfindungsmeldung, Markenanmeldung-Checkliste, NDA, Prioritäts-Skript). Push steht aus (Bernhard).
- 21.10. Desktop-Session: `CLAUDE.md` und `KOORDINATION.md` angelegt.
