# KOORDINATION — wer arbeitet woran

Gemeinsames Brett aller Claude-Sessions. Neueste Einträge oben. Zeiten in Ortszeit Berlin.

## In Arbeit (belegt)

| Seit | Session | Dateien | Ziel |
|---|---|---|---|
| 21.10. 20:27 | Cloud-Session | `OS/.htaccess`, `OS/KAYYA-Deploy.ps1`, `OS/docs/docs.html`, `OS/docs/invest.html` | Docs-Schutz: interne Unterlagen sperren, Links „Auf Anfrage unter NDA" |

## Übergaben und Fragen

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
