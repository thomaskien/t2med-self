# Arbeit an 1.6.4

Dokumentationsergänzung: Die Pin-Anleitung beschreibt die manuelle Einrichtung
für t2med vor 26.11. Beide PNGs sind einzeln, als sms-pins.zip und im vollständigen
symbole.zip aus dem Aufbau vom 27.09.2026 verfügbar. Die 134 ursprünglichen
Einträge dieses Katalogs sind unverändert. Der Check-in-Installer verteilt die
Pins weiterhin nicht an t2med und startet t2med nicht neu. Downloadverweise,
ZIP-Inhalte und das neu gepackte Release samt Prüfsumme wurden lokal geprüft.

Benutzerauftrag: Den übermittelten Praxisstandard sowohl dokumentieren als auch
in den Installationsvorgaben übernehmen. Basis ist 1.6.3. Umsetzung und
abschließendes Review durch den Lead; keine Installation oder Serverzugriffe.

Die Paketvorlage übernimmt alle 97 vorgegebenen Konfigurationswerte. Die beiden
nicht angegebenen Meldungen next_card_button und photo_error bleiben mit den
bisherigen Vorgaben verfügbar. Die Installer-Fragen zu t2med-Zertifikatsprüfung,
SQL-Datumsänderung und SQL-Verbindungsart lesen die Vorgaben aus der TOML statt
fest codierte Gegenwerte vorzuschlagen. Kontext und Leser werden weiter anhand
der tatsächlich vom Zielserver gelieferten Auswahl geprüft.

Fragebogenfunktion bleibt aktiviert, beide Startlisten sind leer. Der bestehende
Fragebogentest prüft jetzt diesen Standard und aktiviert ana anschließend
explizit für die Tests der optionalen Funktion. Der Migrationstest prüft außerdem,
dass eigene TLS-, Leser-, Fragebogen- und SQL-Einstellungen erhalten bleiben.
Die Anwendungsschreibpfade wurden nicht geändert.

Ausgeführte Prüfungen (jeweils Exit-Code 0):

- php bin/check-runtime.php 1.6.4
- php tests/smoke.php
- php tests/config-upgrade.php
- ruby tests/forms-data.rb | php tests/features15.php
- PHP-Lint für alle PHP-Dateien und bash -n für alle Shellskripte.

Fragebogenprüfung lokal mit Ruby/Psych-Adapter, da PHP-YAML auf dem Mac fehlt.
Die Prüfungen sind offline; keine Aussage über den tatsächlichen Datenbankzugang
einer neuen Zielinstallation. local benötigt die t2med-Datenbank auf demselben
Rechner; bei getrennten Geräten ssh/tcp einrichten oder die Datumsoption abwählen.

# Historische Implementierungsnotizen 1.6.3

Freigabe: Mitarbeiter-Anmeldeseite ohne Header/Footer, Mitarbeiterknopf nur auf
Kartenstartseite, Anliegen einzeilig für bessere Erreichbarkeit mit Tastatur.
Basis 1.6.2; kleine reine UI-Änderung durch Lead. Alte Releases unverändert.
Keine Installation oder Serverzugriffe. Kein Backend- oder Konfigurationswechsel.

renderLogin verwendet nun die vorhandene natural-scroll-Ausnahme: normale
Dokumentdarstellung, keine festen Höhen/Positionen oder eigenen Scrollkorrekturen.
Miniaturkamera einschließlich Epoch-/Stream-Cleanup unverändert. clearScreen
verbirgt Mitarbeiter standardmäßig, nur renderIdle zeigt ihn. Bereits im initialen
HTML hidden, damit kein Aufblitzen vor Statusabruf. Handler prüft zusätzlich idle
und Sichtbarkeit. Dreifachdialog und serverseitiger Abmeldeweg unverändert.

renderNote erzeugt input[type=text], weiterhin optional und mit bestehendem
maxLength. Payload bleibt note, keine Backend-Änderung. Im iPad-Querformat dauerhaft
kompaktere Abstände/Schrift-/Kontrollhöhen, unabhängig von Tastatur-Resize und
Unterschieden zwischen Layout- und Visual-Viewport; kein Sticky oder Fixieren.
Synthetische UI-Tests prüfen beide Kategorien, leere Eingabe, Login mit Kamera,
Sichtbarkeit des Mitarbeiterknopfs, Seitenwechsel und verkleinerten Viewport.
Echte iPad-Tastatur und Homescreen bleiben am Gerät zu bestätigen.

## Historische Implementierungsnotizen 1.6.2

Freigabe: Miniaturkamera auf Mitarbeiterseite zur vorgezogenen iPad-Freigabe;
dreifache Abmeldebestätigung beim Mitarbeiterknopf. Basis 1.6.1, alte Releases
unverändert. Kleine zusammenhängende Änderung durch Lead; kein Live-Zugriff.
Ergänzende Freigabe: Geräteanmeldung auf maximal 12 Stunden begrenzen.

Login-Vorschau verwendet dieselben getUserMedia-Constraints wie die Fotoseite,
muted/playsinline und audio:false. Keine Canvas-Aufnahme/Uploadfunktion. Bestehende
cameraEpoch verhindert verspätete Stream-Übernahme nach Seitenwechsel; Tracks
werden bei Verlassen/Hintergrundwechsel beendet. Kein Warmhalten der Kamera im
Patientenbetrieb. Berechtigungsablehnung beeinträchtigt die Anmeldung nicht.

Drei native HTML-Dialogschritte, Fokus jeweils auf Abbrechen, kein Netzwerk vor
Schritt 3. Abbruch entfernt nur den Dialog, nicht die aktuelle Seite/Eingaben.
Patienten-Inaktivität pausiert während der Bestätigung; Fertig-Countdown darf
währenddessen keinen Reset auslösen. Bei Seitenwechsel endet auch der Dialog.
Die Warnung beschreibt nun eine echte serverseitige Abmeldung (vorher öffnete
Mitarbeiter nur das Anmeldeformular). POST logout mit bestehendem CSRF-Schutz,
vollständiger UI-Bestätigung und Flow-ID-Abgleich; Wiederanmeldung rotiert ohnehin
CSRF. Die drei Klicks sind ein Versehenschutz, keine Mitarbeiter-Authentifizierung.
Vor Sessionbereinigung vorhandene nextPatient-Kartenfreigabe/Lease-Prüfung;
keine Patientenschreibaktionen. Logout entfernt Authentifizierung/Flow, rotiert
CSRF und Session-ID, behält Login-Ratenbegrenzung. Pending-Dateien unberührt.

Gezielte lokale JS-/PHP-Tests: Abbruch in jedem Schritt, Escape, doppelte Öffnung,
Eingabenerhalt, verlorene Logout-Antwort ohne Replay, Kameraablehnung/Retry,
verspätete Freigabe/Hintergrund/Anmeldung, deaktivierte Selfies, alte Flow-ID,
laufender Schreibmarker, fehlgeschlagene Kartenfreigabe, Session-/CSRF-Wechsel.
Browserlayout mit synthetischem Canvas-Stream, kein echtes Gerät oder Server.
Keine Zusage dauerhaft gespeicherter iPadOS-PWA-Berechtigungen.

Login speichert auth_started und auth_until. SessionStore verwendet höchstens
min(12, session_hours) Stunden, sowohl beim Login/GC als auch bei jeder Prüfung.
Keine Verlängerung durch Aktivität; spätere kürzere Konfiguration wirkt ebenfalls.
Alte Sitzungen ohne verlässlichen Startzeitpunkt verlangen einmalige Neuanmeldung,
Patienten-Flow bleibt bis zum kontrollierten Login-Cleanup erhalten. ConfigUpgrade
reduziert nur session_hours > 12 nach Sicherung, Kommentare/kleinere Werte bleiben.
Legacy-Konfigurationen bis 24 bleiben für Migration lesbar, aber nie 24h gültig.
Testuhr prüft Grenze einschließlich letzter Sekunde, exakt 12h, Altformat und
verkürzte Konfiguration; vorhandene Flow-Fixtures haben jetzt auth_started.

## Historische Implementierungsnotizen 1.6.1

Freigabe: CONSENT_VERIFY nach bestätigtem PDF/E-Mail, fehlender SMS-Pin, danach
PRIVACY_PENDING. Zusätzlich beide Anliegen-Freitextseiten wie Kontakte ohne Rahmen.
Basis 1.6, alte Releases unverändert. Sol-Worker nicht verfügbar (konfigurierte
Codex-CLI fehlt); Umsetzung und Review durch Lead. Kein Live-Zugriff/Installerlauf.

Der alte Logeintrag enthält keine Feldabweichung. Deshalb keine Behauptung, welcher
konkrete Serverwert den ursprünglichen Fehler auslöste. Native APS-26.9.1-DTOs per
javap geprüft: AbstractPersistableTO liefert ref/objectId/revision, owned value TOs
für Namen/Geburt/Adresse/Telefon/E-Mail, berechnete isValid/isEmpty-Getter. Diese
technischen Felder sind kein Identitäts-/Einwilligungsnachweis. PatientRef und
Referenzen auf fremde Objekte bleiben inhaltlich geprüft, Revisionszähler ignoriert.
ConsentCheck normalisiert ausschließlich bekannte owned refs/Getter, optionale
leere Text-/Adresswerte, Listenreihenfolge und Sequenz-Anzeigetext. Alle unbekannten
Felder und fachlichen Werte bleiben strikt verglichen. Volle frische DTOs werden
unverändert bis auf benachrichtigungErlaubt gesendet; Normalisierung nur beim Lesen.
Separate strikte bool-Prüfung, sonst CONSENT_DETAILS_CHANGED mit festen Gruppennamen.
Erwartete Daten vor Schreibversuch und abweichende Rückantwort nur verschlüsselt.

WriteJournal: datenschutzbezogene Prozesssperre unter requests, Hashprüfung gegen
zwischenzeitliche Änderungen, authentifiziertes Lesen alter CI1-Dateien. Recovery
nur als CLI, TTY, einmalige T2med-Anmeldung, Eigentümerprüfung beim Abschluss,
fünf Minuten Abstand zur letzten Journaländerung, explizite Mitarbeiterbestätigung.
Prüft exaktes gespeichertes PDF/Text/Patient, korrekte aktuelle E-Mail-Auswahl, Pin-
Plan aus Journal und Konflikte mit anderen Dokumenten/offenen Datenschutzjobs.
Schreibt nur fehlende Pins; vorhandene gleiche Pins nicht erneut senden. Keine PDF-
oder E-Mail-Schreibaufrufe im Recovery-Pfad. Abschluss nach erneuter Gesamtprüfung;
kein automatisches Replay. Bei alten Journals ohne Stammdatensnapshot ausdrücklich
manueller Abgleich. Externe T2med-Änderungen bleiben ohne native CAS nicht atomar
sperrbar; parallele Bearbeitung verboten. Audit nur Metadaten, kein Patienteninhalt.

Tests: 136 fokussierte Vergleichs-/Recovery-Prüfungen, 133 bestehende Consent- und
133 Datenschutz-/Flow-Prüfungen (synthetisch, YAML mit Ruby-Adapter), REST-/Routing-
Regressionsprüfung, UI-Doubles und Chromium einschließlich beide Freitextseiten
bei Tastatur-Viewport und anschließendem Rahmenwechsel. Reales iPad/T2med noch
abzunehmen. Vorhandene Pending-Kopie wird durch Release/Installer nicht beseitigt.

## Historische Implementierungsnotizen 1.6

Freigabe: Kontaktseite ohne Header und ohne Scrollverbote/Viewport-Tricks;
hinterlegte Nummern deutlicher mit Korrigieren daneben. Benutzer wünscht 1.6.
Basis 1.5.9, alte Releases unverändert. Keine Installation oder Live-Schreibtests.

html.contacts-mode schaltet ausschließlich für Kontakte HTML/Body/App-Shell/main
auf normalen Dokumentfluss ohne feste Höhe/Position oder inneren Scrollcontainer.
Header und Footer ausgeblendet. Die ehemaligen Querformat-Fixierungen für Kontakte
sind entfernt. viewportHeight, keepInputVisible und verzögerte Resets greifen dort
nicht ein. Andere Seiten behalten den Rahmen; beim Wechsel wird die sichtbare Höhe
neu gelesen. Kein initialer programmatischer Fokus auf main in der Kontaktansicht.

Bekannte Telefon-/E-Mail-Werte bleiben maskiert, erscheinen größer/fett mit
Korrekturknopf. Leeres neues Feld erst beim Öffnen sichtbar, Verwerfen leert nur
die neue Eingabe und schließt ggf. den Telefon-Keypad. Fehlende Werte bleiben direkt
ergänzbar. Alle Felder in einer normalen scrollbaren Liste, kein Seitenwechsel.
Adresskorrektur, freiwillige Angaben, eigener Telefon-Keypad und Serverpayload
unverändert; keine Kontaktdaten werden allein durch Öffnen/Schließen gesendet.

Prüfungen: DOM/Fokus/Timer-Test ohne Kontakt-Scrollaufrufe, Korrigieren/Verwerfen,
mehrere Kontakte und leere/unveränderte Werte. Chromium bei 1080×810, 1024×768 und
1024×664 mit zwölf scrollbaren Kontakten, verkleinertem Tastatur-Viewport und
anschließendem Seitenwechsel ohne Neuladen. Kein Ersatz für echte iPad-Abnahme.

## Historische Implementierungsnotizen 1.5.9

Die damalige Kontakt-Korrektur half laut Rückmeldung nicht; sie ist ab 1.6 auf
dieser Seite deaktiviert und durch normalen Dokumentfluss ersetzt.

Freigabe: Nach Schließen der iPad-Tastatur den festsitzenden Seiteninhalt wieder
nach oben bringen. Basis 1.5.8, alte Releases unverändert. Keine Installation.
Ursache im bisherigen Code: keepInputVisible lief auf jedem Viewport-Resize/Scroll,
auch beim Schließen mit noch fokussierter E-Mail. Kein expliziter Scroll-Reset.

Tastaturkompression über unkomprimierte Höhe und editierbares Feld erkennen;
Zoom separat behandeln. Beim Übergang offen → geschlossen --view-top auf null,
main/App-Shell und Dokument nach oben setzen. Ein begrenzter zweiter Reset nach
250 ms fängt spätes Safari-Panning ab. Epoch-Token invalidiert alte Fokus-Callbacks
und alte Resets bei neuer Fokussierung/Seitenwechsel; keine Blur-Erzwingung,
kein Rendern und kein Verändern der Feldwerte. Kleine Bildschirmbereiche bleiben
intern scrollbar, der äußere Rahmen bleibt gesperrt. Medizinische Fragebögen und
Datenschutzseite bleiben unverändert.

Gezielte Tests: Fokus bleibt beim Schließen erhalten; Blur vor Schließen;
verspäteter Versatz; alter scrollIntoView-Callback; erneuter Fokus; Pinch-Zoom;
keine Datensendung. Chromium prüft E-Mail und Adresskorrektur vor/nach verkleinertem
Viewport auf derselben Seite. Diese Simulation ersetzt keine echte iPad-Abnahme.

## Historische Implementierungsnotizen 1.5.8

Freigabe: Footer auf der Kontaktseite ersatzlos entfernen, weil er bei sichtbarer
E-Mail-Tastatur kollidiert; anschließend äußeren Seitenrahmen gegen Scrollen fixieren.
Basis 1.5.7, alte Releases unverändert.
CSS-Geschwisterselektor nutzt die bestehende contacts-screen-Klasse, ohne neue
Browser-APIs oder globales Verstecken. Gilt auch unterhalb der Querformat-Mediaquery.
Fester Kontaktbereich bis 16px vor den unteren Rand statt bisherigem Footerabstand.
HTML/Body nicht scrollbar, Body fixiert, App-Shell innerhalb des sichtbaren Viewports.
visualViewport.height/offsetTop bei resize/scroll berücksichtigt. Absolute Kontakt-
und Fotobereiche bleiben in diesem Rahmen. Nur zentrale Überlänge intern scrollbar;
fokussierte Eingaben werden nach Viewportänderung dort sichtbar gehalten.
Keine REST-/Datenmodelländerung. Gezielte Offline-Runtime-/Syntax-/Layoutprüfung,
inklusive E-Mail-Fokus bei 1024×430 und weiter sichtbarem Footer auf der Fotoseite.
Das verkleinerte Browserfenster simuliert keine echte iPad-Systemtastatur.

## Historische Implementierungsnotizen 1.5.7

Freigabe: gesammelte Kontakt-/Tastatur-/Signatur-Änderungen und aktuelle E-Mail-/SMS-
Einwilligung mit frei konfigurierbaren Pins. Basis 1.5.6, alte Releases unverändert.
Lead-Umsetzung: Sol-Wrapper vorhanden, konfigurierte Codex-CLI weiterhin nicht vorhanden.
Keine neue Installation, kein Zugriff auf Live-Patienten und kein T2med-Neustart.

Native, lokal untersuchte APS-Version: 26.9.1, SHA-256 des Server-JAR
721d5b4b39bb10e4e626c2f83cb88dd0ed11fe5c04bf3404313cd823f0b22311.
PatientDetailsBearbeitenBoundaryImpl.aktualisierePatientDetails/toPatientTO verwendet
findExactRevision, führt den vollständigen Details-DTO zurück und validiert das
Ergebnis. Deshalb unmittelbar frisch lesen, eine Eigenschaft ändern, alles sonst
erhalten und zurücklesen. Der Endpoint führt auch den normalen unveränderten
Sequenz-/Stammdatenabgleich aus; kein eigenständiger Fall wird angelegt.
`setbenachrichtigungerlaubt` setzt ausschließlich TRUE und ignoriert die Rückgabe
der Servicevalidierung: bewusst NICHT verwendet.

- POST /praxis/patient/detailsbearbeiten/aktualisiere/details:
  PatientDetailsAktualisierenRequestDTO, kontext + details; Boolean unter
  details.kontaktdatenDTO.benachrichtigungErlaubt. Bevorzugten Weg nicht verändern.
- GET /praxis/karteikarte/allestandardsymbolnamen: rohe String-Liste mit .png-Namen.
- POST /praxis/karteikarte/symbolaendern: KarteieintragSymbolAendernRequestDTO,
  kontext + karteieintragRef (nicht FachinformationRef) + symbol (Dateiname/null).
  FachinformationSymbolZusatz wird neu/aktualisiert; Wrapper bestätigt nicht jede
  darunterliegende Validierung. Patientengebundenes Readback von row.symbol zwingend.
  Kein CAS im nativen Pin-Endpunkt: unmittelbar vorher Ref/Text/Symbol vergleichen,
  hinterher prüfen; externe parallele Bearbeitung kann nicht atomar gesperrt werden.

PDF zuerst vollständig bestätigen, danach E-Mail, danach neuen SMS-Pin, zuletzt
alte markierte App-Pins entfernen. Alle Schritte in einem verschlüsselten Journal.
Nur Versionstext + Check-in-Dokumentkennung + explizite SMS-Pin-Markierung und
passendes tatsächliches Symbol identifizieren einen alten verwalteten Pin;
Dokumentbesitz zusätzlich über byids und dokumentverweis/find geprüft.
Historische Dokumente unverändert; fremde Pins bleiben unangetastet. Fehlendes
Katalogsymbol stoppt vor PDF-Upload. Leere Zuordnung deaktiviert nur diesen Pinpfad.
Offenes Journal zu einer PDF-Zeile blockiert auch spätere privacySufficient-Prüfungen,
unabhängig von Reihenfolge/Version anderer Dokumente. Kein automatisches Replay.
Pin-Konfiguration wird mit dem Formularjob festgehalten; Änderung verlangt Neustart.

Kontaktseite: Telefon/E-Mail links, Mitte abwechselnd Adresse oder lokaler Keypad,
Aktionen rechts. readonly + inputmode=none verhindern iPad-Systemtastatur beim Telefon;
Auswahlbereich, Plus, Ziffern und Rücktaste lokal verarbeitet, maximal 50 Zeichen.
E-Mail und Adresskorrektur bleiben nativ. Überschrift unabhängig von Feldhöhe.
Canvas 1000×500, normierte Punkte bleiben 0..1, PDF-Zeichenfläche 140×70 mm.

Prüfungen: consent.php (vier Kombinationen, Konflikte, Teilfehler, fremde Pins,
konfigurierbare/fehlende Symbole), privacy-flow.php (Speicherreihenfolge, pending),
Config-Migration und bestehende Regressionen. NativeConsentProbe liest die tatsächlich
von PHP erzeugten Anfragen mit den APS-Klassen, ohne einen Service zu starten.
Browser testet drei Querformatgrößen, Keypad und Signatur; vier PDF-Seiten gerendert
und visuell geprüft. YAML lokal über Ruby/Psych-Adapter, da PHP-YAML lokal fehlt.
Echtes Safari, finale Rollenrechte und Live-End-to-End bleiben Abnahmeaufgabe.

## Historische Implementierungsnotizen 1.5.6

Freigabe: die gesammelten Kontakt-/Foto-/Datenschutz-UI-Änderungen umsetzen.
Basis 1.5.5, alte Releases unverändert. Kein Installer oder Live-Zugriff.

Kontaktansicht: Name aus den bereits patientengeprüften Details, strukturierte
Adresszeilen zusätzlich zur bisherigen flachen Adresse. Telefon/E-Mail weiter
nur maskiert zum Client. Korrektur links, Kontakte mittig untereinander, Aktionen
rechts. Keine Ja-Bestätigung; unveränderte Übernahme erzeugt keine N-Notiz.
Mehr als drei Kontaktfelder mit erhaltenden Seitenwechseln, Validierung zeigt
bei Fehlern zuerst die richtige Eingabegruppe. Adresskorrektur optional/verwerfbar.

Kamera: Meldung in Textspalte statt oberhalb des gesamten Rasters. Gleichmäßige
vertikale Ränder, feste Rasterzeile und davon unabhängige Auslöserposition.
Livevorschau und Bildkorrektur mit identischem Rahmen; Seitenpfeil auf Bildschirmmitte.
Keine Änderung der Kameraanforderung oder der Pan-/Pinch-Implementierung.

Datenschutz: nur zusätzlicher UI-Hinweis entfernt, Signaturbeschriftung geändert.
Keine Änderung an Einwilligungslogik, Vorlage, Versionsprüfung oder PDF.
Gemeinsames Manifest (relative Start-URL/Scope, standalone) und gleichartige
Apple-Meta-Tags auf allen drei Seiten; CSP erlaubt nur das lokale Manifest.
Kein Service Worker, kein Cache klinischer Daten. Kein Browser-Fullscreen-API-Trick.

Prüfung: Syntax aller PHP/JS-Dateien und Installer; Runtime, Smoke, Kontaktabruf,
Konfigurationsmigration, medizinische Ausgabe/Speicherung, Foto/Datenschutz,
DOM-/Timer-Tests. Bestehende TOML aus 1.5.5 bleibt bytegleich.
Lokaler Chrome/Playwright-Test mit vollständig abgefangenen Requests und Canvas-Kamera:
1080x810, 1024x768, 1024x664; Standard/Neu, Adresskorrektur, sieben Kontakte,
Kamera mit/ohne längere Meldung, links/rechts, gleicher Platz im Bildeditor.
Screenshots mit synthetischer Person sichtgeprüft. Browserprüfung fand und behob
eine Raster-Mindesthöhe im kleineren Fenster und die Fußleistenposition.
Echtes iPad inkl. Bildschirmtastatur und Homescreen-Wechsel bleibt Abnahmeaufgabe.
Optionaler Browser-Test: NODE_PATH auf vorhandenes Playwright setzen,
`node tests/ui-layout.js /absoluter/vorhandener/testausgabeordner` (Chrome vorhanden).

Sol-Worker nicht verfügbar: konfigurierte /Applications/Codex.app/Contents/Resources/codex
fehlt. Umsetzung und Review durch Lead, keine Konto-/Worker-Konfigurationsänderung.

## Historische Implementierungsnotizen 1.5.5

Freigabe: nur kompakte medizinische Selbstauskunft wie im normalen fragebogenpi.
Basis 1.5.4; die zusätzliche Vollfeldliste in Questionnaires::result entfernt.
Der bereits verwendete native Berichtsaufbau liefert nun allein den Inhalt,
zusammen mit Herkunftskopf und freiwilligen Körpermaßen. Keine Änderungen an
REST, Allergie-/Messwertpfad, YAML/Engine, Datenschutz oder bestehender Akte.
Neue Tests reproduzieren die beanstandete Ausgabe vor der Korrektur und prüfen
danach das konkrete Beispiel, Leerfelder, Auswahl, Freitext und ACT-Auswertung.
Keine Installation oder Live-Schreibtests. Alte Versionen bleiben unverändert.

## Historische Implementierungsnotizen 1.5.4

Freigabe: Foto-Revisionsfix und Datenschutz aus fragebogenpi gemeinsam.
Datenschutz für ALLE Patienten ohne ausreichend aktuelles Dokument, immer vor
medizinischen Bögen. Mindestversion in TOML als reine Versionsnummer. Version im PDF und
Akteneintrag; unbekannte/alte Version reicht nicht. Keine Installation oder
Schreibversuche am T2med-Server. Ältere Release-Verzeichnisse bleiben unverändert.

Basis: laufende 1.5.2. NICHT die in diesem T2med durch native DTO-Schlüsselkollision
fehlerhafte Kombinationsabfrage aus 1.5.3 übernehmen. Bestehendes manuell geprüftes
TOML-Paar tt/Test beibehalten; kein Kontextwechsel/kein Schein am Terminal.

Upstream Datenschutz: fragebogenpi Commit a9ed0b960a8822b854006f26baa6c0870ccbe31b,
datenschutz.yaml 1.3.4 und datenschutz.php 1.1. Enthält Praxisplatzhalter und
Testhinweis, die nicht als ausgefüllte Praxisvorlage ausgegeben werden dürfen.

Native APS 26.9.1 (lokal aus Server-JAR untersucht):
- Foto /upload/passbild -> PatientPassbildService -> findExactWithWriteLock:
  lehnt veraltete PatientRef.revision ab. Aktuelle Ref aus Kopfmodul laden;
  erneut direkt vor finalem Speichern prüfen, niemals bei unklarer Antwort retry.
- Dokument POST /praxis/verweis/dokumentverweis/update unterstützt neuerEintrag=true,
  uploadToken, kontext (Patient, ohne Fall), dokumentverweis (DokumentverweisTO).
  Token aus bestehendem Bildeintrag-Token-Endpunkt: gleicher PatientContentService.
  PDF über CDN Delivery als application/pdf. Insert mutiert TO.ref und TO.verweis.
- DokumentverweisTO extends AbstractVerweisTO: fachinformationstyp,
  gueltigkeitszeitpunkt, anamnestisch, text, kuerzel, verweis, unzugeordnet + ref.
  DokumentverweisService verwendet BEFUND_DOKUMENT.
- Karteikarte POST /all mit kontext.patientRef liefert alle Zeilen (ohne Filter).
  Gesperrte Akte liefert jedoch leere Liste! Vorher und nachher /patient/{id}/gesperrt
  prüfen: sperrungPatientStatusTyp 0 oder 4 frei, 1/2 gesperrt. Unbekannt -> Halt.
- Zuordnung/Prüfung: Karteizeile -> /dokumentationbearbeitenvorgang -> fachinformationRef;
  /praxis/verweis/dokumentverweis/find liefert dokumentverweisTO (+ ungenutztes Token).
  Patientengebundene /byids-Prüfung vor Übernahme fremder Referenzen.

Implementiert und fokussiert offline geprüft: Versionsvergleich, Ablauf vor
medizinischen Bögen, gültiges/fehlendes/altes PDF, Abbruch/Timeout, Formularwechsel,
Identitätswechsel, unklare Schreibantwort ohne Wiederholung, verschlüsselte Prüfkopie,
Photo-Revision und paralleles vorhandenes Foto. Native DTO-Deserialisierung erfolgreich.
PDF mit TCPDF 6.6.2 erzeugt; alle vier synthetischen Seiten gerendert und sichtgeprüft.
Native PHP-YAML-Erweiterung fehlt lokal: Ruby/Psych-Testadapter, keine neue Installation.
Die Mindestversion bezieht sich auf meta.version, nicht auf Check-in-Release-Version.
Kein Installer-/Live-Schreibtest; Zielsystemabnahme bleibt offen.
