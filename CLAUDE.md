# KAȲȲA — Regeln für jede Claude-Session in diesem Projekt

An diesem Projekt arbeiten **mehrere Claude-Sessions parallel**: die Desktop-Session auf dem ThinkPad Privat und Bernhards Claude-Cloud-Session. Sie sehen sich nicht direkt. Abgestimmt wird über diese Datei und über `KOORDINATION.md`.

## Vor jeder Arbeit
1. `git pull --ff-only origin main` im Projektordner `C:\Users\BKRG\Desktop\KAYYA`.
2. `KOORDINATION.md` lesen: Was ist gerade belegt, welche Übergaben und Fragen liegen offen?
3. Eigene Arbeit dort eintragen (Dateien, Ziel, Uhrzeit), **bevor** du Dateien änderst.
4. Abgleich lokal / kayya.org / GitHub: `powershell -NoProfile -ExecutionPolicy Bypass -File KAYYA-Status.ps1`

## Während der Arbeit
- Dateien, die eine andere Session in `KOORDINATION.md` belegt hat, nicht anfassen. Stattdessen eine Übergabe oder Frage eintragen.
- Nur eigene Änderungen committen (`git add <datei>`, nie blind `git add -A`, solange fremde Arbeit offen liegt).
- Zeilenenden: `.gitattributes` normalisiert auf LF. Byte-Vergleiche lokal↔live deshalb immer mit normalisierten Zeilenenden (macht `KAYYA-Status.ps1`).

## Nach der Arbeit
- Belegung in `KOORDINATION.md` freigeben und kurz notieren, was live ist und was offen bleibt.
- Committen. `git push` macht Bernhard, wenn die Session es nicht darf.

## Feste Regeln
- **Zugangsdaten:** `C:\Users\BKRG\KAYYA-Zugang\` (netrc.txt, kayya-key.php, kayya-db.php, askpass.cmd) nie lesen, anzeigen oder committen. Nur Skripte nutzen sie, ohne Werte auszugeben.
- **Repo ist privat** (github.com/kayyaorg/kayya). Vertrauliche Arbeitsdokumente dürfen hinein, Schlüssel und Passwörter nie (siehe `.gitignore`).
- **Vertrauliches nie öffentlich ausliefern:** Seit 21.10.2026 gilt der Docs-Schutz. Interne Unterlagen bleiben lokal oder sind per `.htaccess` gesperrt. Achtung: Der Schutz greift für docx/pptx/xlsx/pdf/zip, **nicht für .html**. Vertrauliche Seiten also nicht nach `OS/docs/` legen.
- **Marke** immer `KAȲȲA` (U+0232), ASCII nur in Datei- und URL-Namen.
- **Bernhard** (Rechtsanwalt, Geschäftsführer) will kurze Antworten auf Deutsch, Ergebnis zuerst, und aktive Produkt-Vorschläge, nicht nur Bugfixes. Vor jeder Fertigmeldung selbst live prüfen.
- **Deploy** nach kayya.org: `OS\KAYYA-Deploy.ps1` (lädt seit 21.10. aus `docs/` nur `.html` und den One-Pager hoch). Danach `KAYYA-Status.ps1`.
