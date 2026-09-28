# fragebogenpi Check-in-Adapter

## Herkunft

Lokale, versionierte Komponente für T2med Check-in 1.6.4. Quelle:
[fragebogenpi](https://github.com/thomaskien/fragebogenpi), Basis-Commit
`a9ed0b960a8822b854006f26baa6c0870ccbe31b` mit tablet.php 1.8.2.
Die hier angepassten Tablet- und Check-in-Varianten tragen 1.8.3.
Dies ist der gebündelte Stand, keine Aussage über den neuesten Upstream.

## Lieferumfang

- `tablet-checkin.php` – Check-in-spezifischer Renderer (1.8.3)
- `tablet-engine.php` – Unveränderte gemeinsame Hilfsfunktionen aus tablet.php 1.8.2
- `tablet.php` – GDT-Einstieg mit ausgegliederten Hilfsfunktionen (1.8.3)
- `tablet-checkin.css` / `tablet-checkin.js` – Check-in-Oberfläche
- `_yaml/` – Neun unveränderte YAML-Formulare (aktuell: act, alka, ana 2.3.5, bart, cat, fage, goeb, phq2, phq9)
- Zusätzlich `_yaml/datenschutz.yaml` 1.3.4 vom selben Commit.
- `upstream/datenschutz.php` 1.1 als unveränderte Referenz, **nicht ausgeführt**;
  dessen GDT-Einstieg wird nicht in den Check-in eingebunden.

Die YAML-Dateien wurden bytegleich gegen den Basis-Commit geprüft.
Prüfsummen: `fragebogenpi/UPSTREAM-YAML.sha256`; aus dem Release-Hauptverzeichnis
mit `sha256sum -c fragebogenpi/UPSTREAM-YAML.sha256` kontrollierbar.

## Architektur

Die obigen Dateien liegen im Release unter `fragebogenpi/`, außerhalb des Webroots.
Der Check-in-Controller `public/questionnaire.php` lädt `fragebogenpi/tablet-checkin.php`;
den Renderer nicht direkt veröffentlichen. Mitarbeiter-Session, CSRF und Flow-/Formtoken
binden die Übergabe. Keine Patientendaten in URLs. Der Check-in nutzt weder GDT-Eingang
noch GDT-Ausgang. Speicherung erfolgt durch die Check-in-Anwendung über APS/REST.

Klartext und Vorlagenauswertung werden unter `ana` gespeichert, freiwillige Größe und
Gewicht zusätzlich als anamnestische Körpermaße. Positive Allergieangaben stehen im
Text und zusätzlich in der Allergiefunktion: bei eindeutigem aktuellem Fall als
unbestätigte Patientenangabe, andernfalls als unstrukturierter Allergietext.
Es werden keine Fälle angelegt und keine vorhandenen Allergien gelöscht oder bestätigt.
Seit Check-in 1.5.5 wird dafür ausschließlich die normale `build_6228_blocks`-Ausgabe
verwendet, ohne zusätzliche Vollfeldliste mit Leerangaben. Nur die GDT-Zeilenrahmung
wird entfernt; es entsteht weiterhin Klartext für APS, keine GDT-Datei.
Die gemeinsame Engine und die YAML-Dateien wurden für diese Korrektur nicht verändert.

Datenschutz verwendet den separaten Check-in-Host `public/privacy.php` mit
`PrivacyForm`/`PrivacyPdf`. Er übernimmt das explizite Datenschutz-YAML-Schema,
die Texte und optionalen Entscheidungen sowie den TCPDF-/Unterschriftsansatz
aus upstream/datenschutz.php. Unterschriften werden als validierte normierte
Linien direkt ins PDF gezeichnet, nicht als GDT-Datei oder temporäres Bild.
Die Ablage erfolgt als APS-PDF statt medizinischem ana-Text. `[privacy]` steuert
Aktivierung und Mindestversion unabhängig von `[questionnaires]`; Details im Haupt-README.
Die unveränderte Vorlage ist wegen Praxisplatzhaltern/Testhinweis vor echtem Einsatz
zu prüfen und anzupassen. Der Installer überschreibt keine eigene Fassung.

## Konfiguration

TOML-Werte (`[questionnaires]`):

- `enabled = true` – Fragebogenschritte aktivieren
- `new_patient_forms = "ana"` – Startformular für Neupatienten
- `existing_patient_forms = ""` – Keine Startformulare für Bestandspatienten
- `allowed_forms` – Erlaubte Start- und Folgeformulare (ana/anam sind dieselbe Vorlage)
- `forms_dir` – Pfad zu YAML-Dateien (z. B. `/etc/t2med-checkin/formulare`)
- `timeout_seconds` – Formularzeitlimit (Standard: 900)

Antwortabhängige Folgeformulare aus der Vorlage bleiben nutzbar. Individuelle,
wiederkehrende Formularzuweisungen aus der Patientenakte sind nicht Teil 1.5.

## Betrieb

Der Adapter wird vom Installer nach `/opt/t2med-checkin/1.6.4/fragebogenpi` kopiert. Vorhandene YAML-Dateien werden erhalten. Keine Laufzeitverbindung zu einem separaten Fragebogenserver. Der Installer benötigt PHP-YAML und PHP-TCPDF; er führt keinen fragebogenpi.sh-/AP-/Samba-/GDT-Installer aus.

## Pflege

Im Release liegt `fragebogenpi-upstream.patch` für das Basis-Repository, einschließlich
Engine-Einbindung und Download-Anpassungen. Der Patch wurde dort nicht angewendet
oder veröffentlicht. Er betrifft den medizinischen Tablet-Adapter; die neuen
Datenschutz-Hostklassen liegen im Check-in-Projekt, nicht in diesem Patch.
Vor gemeinsamer Übernahme dort den aktuellen Stand prüfen.
Ein späteres fragebogenpi-Update darf die Anpassungen nicht durch ungeprüften Download
von main ersetzen. Klinische YAML-Inhalte wurden für diese Integration nicht verändert.

## Hinweis

Keine Installation oder Live-Abnahme ausgeführt. Native PHP-YAML-Integration,
Safari/iPad und APS-Schreibvorgänge müssen auf dem Zielsystem noch abgenommen werden.
Prüfstand, Konfiguration und Behandlung von Teilübertragungen stehen in `README.md`
im Release-Hauptverzeichnis.
