# T2med Check-in 1.6.4

PHP-Anwendung für eGK-Check-in, Kontaktdatenprüfung, Datenschutz mit Unterschrift,
Fragebögen und ein freiwilliges Patientenfoto.
Die Oberfläche ist für ein iPad im Querformat ausgelegt. Serveradresse,
Kartenleser, Wartebereiche, Fragen und Abschlussmeldungen stehen in einer TOML-Datei.

Die Anwendung wurde auf Grundlage der lokalen `t2med-egk-wartezimmer-1.3.sh`
und der bereitgestellten T2med-Klassen aus `aps.jar` 26.8.0 erstellt.
Die alte Shell-Anwendung wird nicht aufgerufen und wurde nicht verändert.
Alle T2med-REST-Aufrufe kommen direkt aus PHP.
Foto-Revisionsprüfung und PDF-Protokoll wurden zusätzlich am bereitgestellten
APS-Stand 26.9.1 untersucht. 1.5.4 basiert auf der funktionierenden 1.5.2;
die problematische Kontextkombinationsabfrage aus 1.5.3 wird nicht übernommen.
Die vorhandenen, bereits korrigierten TOML-Kontext-IDs bleiben erhalten.

## Neu in 1.6.4: Praxisstandard für die Einrichtung

Die Konfigurationsvorlage übernimmt den vorgegebenen Praxisaufbau: Kartenleser
`kienzlebox`, t2med-Zertifikatsprüfung aus, aktualisierter Neupatiententext,
Fragebogenfunktion aktiv mit leeren Startlisten sowie Kartenvorlagedatum
01.01.1990 mit lokalem SQL-Zugang. Kontakte, Datenschutz und Patientenfoto bleiben
aktiv. Die Installer-Fragen verwenden diese Vorgaben aus der Konfigurationsdatei.
Serveradresse, Kartenleser und Kontext-IDs gehören zum gezeigten Praxisaufbau;
bei anderen Installationen werden die passenden Werte im Installer gewählt.

`sql.mode = "local"` setzt die Datenbank auf demselben Rechner voraus. Bei einem
separaten Check-in-Server oder Raspberry Pi den SQL-Zugang als `ssh` oder `tcp`
einrichten oder die Änderung des Kartenvorlagedatums ausschalten.

Vorhandene ausdrücklich konfigurierte Werte werden beim Update beibehalten.
Update aus dem neuen **1.6.4-Verzeichnis**: `sudo bash install.sh --resume`.
Für die iPad-Einrichtung genügt im erprobten Aufbau einmal „Trotzdem verbinden“
in Safari; eine zusätzliche Zertifikatsinstallation am iPad ist dort nicht nötig.
Die nachfolgenden Abschnitte zu früheren Versionen dokumentieren deren Verlauf;
bei abweichenden Standardwerten gelten die Angaben zu 1.6.4 und die beiliegende TOML.

## Enthalten aus 1.6.3: Mitarbeiterseite ohne Rahmen und einzeiliges Anliegen

- Mitarbeiter-Anmeldeseite ohne Header und Footer. Wie bei Kontakten und Anliegen
  normale Browser-Scrollbarkeit, keine feste Seitenhöhe oder JavaScript-Korrektur
  der Tastaturposition. Miniaturkamera und Anmeldung bleiben erhalten.
- Der Kopfzeilenknopf „Mitarbeiter“ erscheint ausschließlich auf der initialen
  Seite mit „Karte einlesen“, nicht während Patientenablauf, Warten oder Fehlern.
  Dort gelten weiterhin die drei Bestätigungen vor der Abmeldung. Die notwendige
  „Mitarbeiteranmeldung“ bei abgelaufener Sitzung bleibt erreichbar.
- „In aller Kürze“ ist für Akutfall und anderes Anliegen ein einzeiliges Textfeld.
  Weiterhin freiwillig, mit bestehender Zeichenbegrenzung und unveränderter
  Speicherung als Wartezimmernotiz. Im iPad-Querformat ist das Layout dauerhaft
  kompakter, sodass die Schaltflächen schon vor dem Öffnen der Tastatur weiter
  oben stehen. Kein Scrollverbot und kein Warten auf ein Tastatur-Resize-Ereignis.

Keine Änderung an Kameraübertragung, REST, Einwilligungen oder 12-Stunden-Grenze.
Lokal mit synthetischen Daten und verkleinertem Chromium-Viewport geprüft;
die echte iPad-Systemtastatur bleibt am Gerät zu bestätigen. Keine Installation.

Update aus dem neuen **1.6.3-Verzeichnis** mit `sudo bash install.sh --resume`;
danach die iPad-Homescreen-App vollständig schließen und neu öffnen.
Keine neuen TOML-Felder. Sitzungen aus 1.6.2 behalten ihre bisherige Ablaufzeit.

## Enthalten aus 1.6.2: Mitarbeiterkamera, Abmeldebestätigung und 12-Stunden-Grenze

- Auf der Mitarbeiter-Anmeldeseite startet eine kleine Frontkamera-Vorschau.
  Die iPad-Freigabe kann dadurch schon das Praxisteam erteilen. Keine Aufnahme,
  Speicherung oder Übertragung; kein Mikrofonzugriff. Beim Verlassen der Seite,
  erfolgreicher Anmeldung oder Wechsel in den Hintergrund wird die Kamera beendet.
  Bei verweigerter Freigabe bleibt die Anmeldung möglich; „Kamera erneut prüfen“
  bietet einen manuellen erneuten Versuch. Bei `selfie.enabled = false` entfällt der Test.
- „Mitarbeiter“ fragt nacheinander **Bestätigung 1/3, 2/3 und 3/3** ab:
  „Das Gerät ist dann nicht mehr benutzbar, wirklich nur für Mitarbeiter!“
  Erst „Ja, Gerät abmelden“ in Schritt 3 beendet die Gerätesitzung serverseitig.
  „Abbrechen“ oder Escape lässt die aktuelle Seite samt Eingaben unverändert.
  Bis zur erneuten Mitarbeiteranmeldung ist danach kein Check-in möglich.
- Ein eventuell noch offener Kartenvorgang wird kontrolliert freigegeben. Laufende
  Schreibvorgänge und veraltete Fenster dürfen keinen anderen Vorgang verwerfen.
  Bereits gespeicherte Akteneinträge und offene verschlüsselte Prüfkopien bleiben
  unverändert. Kein erneutes Einlesen, kein Foto- oder Datenschutz-Schreibzugriff.
- Geräteanmeldung höchstens **12 Stunden ab erfolgreichem Mitarbeiter-Login**,
  ohne Verlängerung durch Kartenlesen oder andere Aktivität. Kleinere Werte unter
  `[app] session_hours` bleiben möglich. Alte größere Werte werden vom Installer
  nach Sicherung auf 12 begrenzt; auch ohne Migration gilt serverseitig maximal 12.
  Das T2med-Passwort selbst wird nicht geändert.

Die Vorschau verlegt die erste Berechtigungsabfrage auf die Mitarbeiterseite;
sie kann nicht erzwingen, dass iPadOS die Freigabe nach Navigation, Neustart oder
Sperren dauerhaft behält. Keine versteckt weiterlaufende Kamera als Umgehung.
Lokal mit simulierten Kamera-/Serverantworten geprüft; keine Installation und
keine echten Patientendaten. Das Berechtigungsverhalten ist am iPad zu bestätigen.

Update: `sudo bash install.sh --resume` aus dem neuen 1.6.2-Verzeichnis; danach
die iPad-Homescreen-App vollständig schließen und neu öffnen. Keine neuen TOML-Felder.
**Nach diesem Update einmal erneut als Mitarbeiter anmelden:** Alte Sitzungen ohne
gespeicherten Anmeldezeitpunkt werden nicht weiterverwendet. Abgelaufene Sitzungen
verlangen beim nächsten Seiten-/API-Aufruf erneut eine Anmeldung; kein automatisches
Wiederholen unklarer Patientenübertragungen.

## Enthalten aus 1.6.1: Einwilligungsprüfung, kontrollierter Abschluss, Freitextseite

- Einwilligung und fachliche Stammdaten werden getrennt von technischen
  T2med-Anzeige-/Referenzfeldern geprüft. Unveränderte Werte mit neu aufgebauten
  Wertobjekt-Referenzen lösen nicht mehr allein `CONSENT_VERIFY` aus.
  Fachliche Abweichungen bleiben gesperrt und erhalten sichere Bereichsdiagnosen.
- `bin/privacy-recover.php --check KENNUNG` prüft einen offenen Datenschutzvorgang
  rein lesend; `--complete KENNUNG` kann ihn nach ausdrücklich bestätigtem
  Mitarbeiterabgleich abschließen. Kein erneutes PDF und kein E-Mail-Schreibzugriff.
  **Ein Update allein entfernt keine vorhandenen Pending-Sperren.**
- Beide Freitextseiten („Warum sind Sie hier?“/„Um was geht es?“) ohne Header,
  Footer, Scroll-Sperre, festgeklebte Aktionen oder Tastatur-Positionskorrekturen.
  Kontaktseite bleibt wie in 1.6; andere Seiten behalten ihren bisherigen Rahmen.

Vorgehen für den bereits betroffenen Patienten: [Datenschutz-Abschluss](docs/DATENSCHUTZ-ABSCHLUSS.md).
Nur lokale synthetische Tests, kein Serverzugriff und keine Installation bei der
Erstellung. Die tatsächliche Feldabweichung im alten `CONSENT_VERIFY` war nicht
protokolliert; neue echte Abweichungen werden nicht pauschal ignoriert.

Update: `sudo bash install.sh --resume` aus dem neuen Verzeichnis; iPad-App
anschließend vollständig schließen und neu öffnen.

## Enthalten aus 1.6: Kontaktseite ohne Scroll-Sperre, gezielte Korrekturen

Die Kontaktseite hat weder Header noch Footer und verwendet normales
Browser-Scrolling. Keine feste Höhe oder Position, kein eigener Scrollbereich,
keine Tastaturhöhen-/Viewport-Anpassung und kein automatisches Zurücksetzen oder
Nachscrollen. Die Kontakt-Tastaturkorrektur aus 1.5.9 wird damit ersetzt.

Hinterlegte Telefonnummern und E-Mail-Adressen erscheinen größer und fett,
weiterhin maskiert. Daneben öffnet „Korrigieren“ das jeweilige neue Eingabefeld;
„Verwerfen“ verwirft nur diese noch nicht übernommene Korrektur. Fehlende Angaben
können direkt freiwillig ergänzt werden. Alle Kontakte stehen auf derselben
scrollbaren Seite; „Weitere“/„Zurück“ entfallen. Telefon weiterhin mit eigener
Zifferntastatur, E-Mail und Adresskorrektur mit der normalen Systemtastatur.
Leere Felder ändern keine vorhandenen Daten. Andere Seiten, REST, Einwilligungen,
Formulare und Protokollierung bleiben unverändert.

Lokal mit synthetischen Daten und Chromium geprüft. Echtes iPad-Safari und die
Bildschirmtastatur sind am Gerät zu bestätigen; keine Installation durchgeführt.

Update aus diesem neuen Ordner mit `sudo bash install.sh --resume`; anschließend
die iPad-Homescreen-App vollständig schließen und neu öffnen.

## Bisheriger Stand 1.5.8/1.5.9: fester Check-in-Rahmen

**Ab 1.6 gilt dieser feste Rahmen nicht mehr für die Kontaktseite, ab 1.6.1 auch
nicht mehr für die Anliegen-Freitextseiten.** Dort hat die oben beschriebene
normale Browserdarstellung Vorrang. Andere Check-in-Seiten
behalten den bisherigen Rahmen und die Tastaturbehandlung.

Die komplette Fußzeile entfällt ausschließlich auf der Kontaktseite, auch bei
geöffneter E-Mail-Tastatur bzw. kleinem sichtbarem Bereich. Kein Ersatztext; der
bisher reservierte Fußleistenplatz entfällt ebenfalls. Andere Seiten zeigen die
Fußzeile weiterhin. Der äußere Check-in-Rahmen ist auf den sichtbaren Bildschirm
begrenzt: Header und Footer werden nicht mehr mit dem Dokument verschoben.
Tastaturhöhe und Safari-Viewport-Versatz werden berücksichtigt. Bei Platzmangel
bleiben Eingaben durch internes Scrollen erreichbar; kein Abschneiden von Feldern.
Die langen medizinischen Fragebögen und der Datenschutztext bleiben lesbar scrollbar.
Keine Änderungen an Kontaktdaten, REST oder Einwilligungen.

Update aus diesem neuen Ordner mit `sudo bash install.sh --resume`; anschließend
die iPad-Homescreen-App vollständig schließen und neu öffnen.

## Enthalten aus 1.5.7: Telefontastatur und aktuelle Einwilligungen

- Telefonnummern/E-Mail links, vollständige Adresse in der Mitte, Aktionen rechts.
  Ab 1.6 steht die Überschrift im normalen Dokumentfluss ohne reservierten Headerbereich.
- Telefonfelder öffnen eine eigene Zifferntastatur mit Plus und Löschen statt der
  iPad-Tastatur. Diese ersetzt vorübergehend die Adresse; „Fertig“ zeigt sie wieder.
  Eingaben bleiben beim Feldwechsel erhalten. E-Mail und Adresskorrektur verwenden
  weiterhin die Systemtastatur. Leere Felder löschen keine vorhandenen Kontaktdaten.
- Unterschriftsfeld doppelt hoch (1000×500); PDF-Unterschrift ebenfalls im Verhältnis
  2:1, ohne Stauchung. Inhalt und Version der Datenschutzvorlage bleiben unverändert.
- Erst nach bestätigter PDF-Ablage wird T2meds `benachrichtigungErlaubt` entsprechend
  der aktuellen E-Mail-Antwort gesetzt: Ja → true, Nein → false. Das ist T2meds
  **patientenweites** Benachrichtigungsfeld, nicht eine Einstellung je E-Mail-Adresse.
  Der bevorzugte Benachrichtigungsweg wird nicht umgestellt.
- SMS-Pin am neuen Datenschutz-Akteneintrag, frei in der TOML konfigurierbar:

```toml
[privacy]
sms_pin_allowed = "SMS-erlaubt.png"
sms_pin_denied = "SMS-nicht-erlaubt.png"
```

Die Dateien müssen im T2med-Katalog vorhanden sein. Für **T2med vor 26.11**
beschreibt die [Pin-Anleitung](pins/README.md) das Ergänzen der beiden PNGs im
Pin-Ordner und im aktuellen `symbole.zip` sowie den anschließenden Serverneustart.
Unter `pins/` liegen die PNGs, ein ZIP mit beiden Pins und ein ergänztes
Symbolarchiv aus dem erprobten Aufbau. Die Anleitung verlinkt alle Downloads.
`""` deaktiviert die jeweilige SMS-Pin-Zuordnung. Kein automatischer Pin-Upload
und kein T2med-Neustart durch den Installer.

Nur zuvor durch diese Anwendung ausdrücklich markierte SMS-Pins an eigenen
Datenschutzdokumenten werden nach bestätigtem neuen Pin entfernt. Historische
Unterschriften/PDFs/Texte bleiben erhalten; fremde/manuell gesetzte Pins werden
nicht bereinigt. T2meds Pin-Endpunkt bietet keinen atomaren Änderungsvergleich:
die Anwendung prüft unmittelbar vor und nach dem Schreiben und wiederholt bei
unklarem Ergebnis nichts. Gleichzeitige manuelle Änderungen deshalb vermeiden.

E-Mail wird über den nativen, revisionsgeprüften Stammdaten-Endpunkt mit frisch
gelesenem vollständigem DTO geschrieben; ausschließlich das Einwilligungsfeld wird
geändert. Danach werden die fachlichen Daten zurückgelesen und verglichen;
die technische Normalisierung ab 1.6.1 ist oben beschrieben.
Kein SQL, keine Fallanlage und keine Umkonfiguration des Arztkontexts.
Beim Abbruch/„Am Empfang klären“ erfolgen keine Einwilligungs- oder Pin-Schreibvorgänge.
Bereits ausreichende ältere Datenschutzbögen lösen **keine rückwirkende Übernahme** aus.

Bei Teilfehlern bleibt die verschlüsselte Prüfkopie mit PDF, gewünschtem Status und
bestätigten Schritten bestehen. Der Vorgang wird angehalten und eine Prüfkennung
angezeigt. Auch beim nächsten Besuch darf ein vorhandenes PDF diese offene
Übertragung nicht verdecken (`PRIVACY_PENDING`). Mitarbeiter müssen den Iststand
abgleichen; niemals blind erneut senden. Prüfkopien erst nach Abgleich abschließen.

Gezielt lokal geprüft: vier unabhängige Ja/Nein-Kombinationen, Statuswechsel,
fehlende Pins, fremde Patienten/Pins, Teilfehler und keine automatische Wiederholung;
DTO-Deserialisierung mit originalen APS-26.9.1-Klassen. Chromium mit synthetischen
Daten bei 1080×810, 1024×768 und 1024×664; Telefon-Keypad, Adresskorrektur und
zusätzliche Kontakte ohne Scrollen. Vierseitiges synthetisches PDF gerendert und
sichtgeprüft. Kein Live-Patientenschreibtest, keine Installation, kein Serverneustart.
Das echte iPad-Safari-Tastaturverhalten bleibt am Gerät zu bestätigen.

Update aus **diesem neuen Ordner**: `sudo bash install.sh --resume`.
Eigene TOML-Werte bleiben erhalten; die zwei fehlenden Pin-Felder werden nach
Sicherung ergänzt. Danach Homescreen-App vollständig schließen/neu öffnen.

## Enthalten aus 1.5.6: kompakte iPad-Oberfläche

Die historische Kontaktanordnung und das Blättern wurden in 1.5.7 bzw. 1.6 ersetzt;
für die aktuelle Kontaktseite gilt die Beschreibung zu 1.6 oben.

- Kontaktseite im Querformat: links Name und vollständige Postanschrift mit
  Korrekturfunktion, daneben Festnetz/Mobilfunk/E-Mail untereinander, rechts
  Hinweise und große Schaltflächen. „IHRE KONTAKTDATEN“ und „Ja, stimmt“ entfallen.
  Ohne Korrektur direkt „Weiter“ wählen; keine zusätzliche N-Notiz.
- Mehr als drei Kontaktfelder werden in kleinen Gruppen mit „Weitere“/„Zurück“
  angezeigt. Alle Eingaben bleiben dabei erhalten und werden zusammen übernommen.
  Telefon/E-Mail bleiben maskiert; leere Felder löschen nichts.
- Foto: Abschlussmeldung in der Textspalte, Vorschau mit fester Größe/Position.
  Seitlicher Linsenpfeil auf Bildschirmmitte; Aufnahme und Bildkorrektur verwenden
  denselben Platz. Keine Kamera-Zoomänderung; Verschieben/Pinch nach Aufnahme bleibt.
- Datenschutz: Zusatzsatz über die beiden freiwilligen Einwilligungen entfernt;
  Beschriftung „Bitte im Feld unterschreiben“. Beide Checkboxen bleiben freiwillig
  und zunächst leer. Formularvorlage, PDF-Inhalt und Formularversion bleiben unverändert.
- Einheitliche Homescreen-Kennzeichnung und gemeinsames Web-App-Manifest für
  Check-in, Datenschutz und Anamnese. Kein Service Worker und keine Offline-Ablage
  von Patientendaten. Keine neuen Pakete oder TOML-Einstellungen.

Lokal geprüft: Syntax, Kontakt-/Fragebogen-/Foto-Regressionen und UI-Abläufe.
Zusätzlich echte Chromium-Layoutprüfung mit vollständig simulierten Daten und
Canvas-Kamera bei 1080×810, 1024×768 und 1024×664: keine Seitenüberläufe bei den
Standardtexten, einschließlich Adresskorrektur und zusätzlicher Kontakte; Vorschau
und Pfeil bleiben bei langer/fehlender Meldung mittig, auch beim Bildkorrekturwechsel.
Screenshots wurden sichtgeprüft. Keine Installation oder Verbindung zu T2med.

Die Bildschirmtastatur und das tatsächliche iPad-Safari-/Homescreen-Verhalten
müssen am Gerät bestätigt werden. Bei schmalen Fenstern oder sehr langen eigenen
Texten bleibt natürlicher Umbruch/Scrollen als Zugänglichkeits-Fallback möglich;
Inhalte werden nicht zur Vermeidung von Scrollen abgeschnitten.

Update im neuen Ordner: `sudo bash install.sh --resume`.
Anschließend **Homescreen-App vollständig schließen und neu öffnen** bzw. die
Safari-Seite vollständig neu laden. Falls ein alter Homescreen-Link weiterhin
die Browserleiste öffnet, die Check-in-Startseite erneut zum Homescreen hinzufügen.
Die iPad-Systemstatusleiste (Uhr/Akku) ist nicht die Safari-Titelleiste.
Bestehende Konfiguration und eigene Datenschutzvorlagen bleiben erhalten.

## Enthalten aus 1.5.5: kompakte Selbstauskunft wie in fragebogenpi

Die medizinische Selbstauskunft verwendet jetzt ausschließlich die reguläre
fragebogenpi-Berichtsausgabe. Die zusätzlich eingefügte Vollfeldliste mit
„(keine Angabe)“, leeren Rubriken und doppelten Befunden entfällt.
Auch die zusätzliche Überschrift „Auswertung gemäß Formularvorlage“ entfällt.

Der Herkunftskopf mit Zeitpunkt und Formular bleibt. Größe und Gewicht erscheinen
nur bei Angabe. Danach folgen die von der jeweiligen Vorlage vorgesehenen
angekreuzten/ausgefüllten Inhalte und Auswertungen, ohne GDT-Transportkennungen.
Die Ausgabe-Regeln der Vorlage gelten auch für Nein-Antworten und unauffällige
Auswahlwerte; es werden keine neuen Negativbefunde aus Leerfeldern erzeugt.
Wie im normalen fragebogenpi werden Umlaute im Bericht als ae/oe/ue umgeschrieben.

Beispiel bei ausschließlich Chemikalienallergie und zwei Körpermaßen:

```text
Anamnese
Patienten-Selbstauskunft am Check-in-Terminal; DATUM UHRZEIT
Formular: ana.yaml

Körpergröße: 187 cm (Patientenangabe)
Körpergewicht: 87 kg (Patientenangabe)

---
Allergien
========
- Chemikalien
```

Die separate Speicherung von Körpermaßen und Allergien, Folgeformulare und
Punktwert-Auswertungen bleiben unverändert. Bestehende Akteneinträge werden nicht
nachträglich geändert. Keine Änderungen an YAML-Vorlagen, Fragebogen-Engine,
Datenschutz, Fotoablauf oder REST-Endpunkten; keine neuen TOML-Einstellungen.

Gezielt offline geprüft: das gemeldete Beispiel, leere Bögen, positive Auswahl,
Freitext, ausgeblendete Antworten, bewusst ausgewähltes „Keine Allergie bekannt“,
ACT-Auswertung, Folgeformulare und getrennte medizinische Speicherung.
Der neue Regressionstest reproduziert den Fehler vor und besteht nach der Korrektur.
Keine Installation und keine Live-Schreibtests. Lokal weiterhin Ruby/Psych-Testadapter,
da PHP-YAML nicht installiert ist. Aufruf: `ruby tests/forms-data.rb | php tests/features15.php`.

Update: Archiv neben den alten Versionen entpacken und im neuen Ordner
`sudo bash install.sh --resume` ausführen. Bestehende Einstellungen bleiben erhalten.
Anschließend die iPad-Seite **vollständig neu laden**; „Nächste Karte“ und
Mitarbeiteranmeldung laden den Browser-Code nicht neu. Die Versionsanzeige stammt
vom Server und beweist allein nicht, dass auch der Browser-Code neu geladen wurde.

## Enthalten aus 1.5.4: Datenschutzversion und Foto-Speicherung

Der Datenschutzbogen erscheint nach dem bestätigten Check-in, aber **vor allen
medizinischen Fragebögen**, bei Neu- und Bestandspatienten ohne ausreichend
aktuelles Datenschutz-PDF. Das gilt auch bei `questionnaires.enabled = false`.
Danach folgen ggf. Anamnese/Folgeformulare und das freiwillige Foto.

In `/etc/t2med-checkin/config.toml`:

```toml
[privacy]
enabled = true
minimum_version = "1.3.4"
timeout_seconds = 900
declined_message = "Bitte melden Sie sich wegen des Datenschutzformulars am Empfang."
```

`minimum_version` bedeutet immer **mindestens diese Version**. Nur die Versionsnummer
eintragen, ohne `ge` oder `>=`. Der Wert ist passend zur Praxisvorlage frei wählbar.
Numerische Versionen mit zwei oder drei Bestandteilen werden verglichen:
1.10 ist neuer als 1.5; 1.5 und 1.5.0 sind gleich. Versionssuffixe wie `-test`
werden nicht als freigegebene Versionsnummer akzeptiert.

Die angebotene Version stammt aus `meta.version` der Datei
`/etc/t2med-checkin/formulare/datenschutz.yaml` (bzw. `questionnaires.forms_dir`).
**Zuerst den Inhalt der Vorlage aktualisieren und ihre Version erhöhen, dann
die TOML-Mindestversion anheben.** Eine zu alte Vorlage stoppt mit
`PRIVACY_TEMPLATE_OLD`, statt einen nicht ausreichenden Bogen wiederholt anzubieten.
Eine Änderung von Vorlage oder Mindestversion während einer Unterschrift stoppt
die Speicherung; der Patient muss den neuen Stand zuerst sehen.
TOML/YAML werden pro Aufruf gelesen, ein Dienstneustart ist dafür nicht erforderlich.
Änderungen zwischen Patientenvorgängen vornehmen und anschließend die Seite neu laden.

**Mitgeliefert ist die unveränderte fragebogenpi-Vorlage 1.3.4. Sie enthält
Praxisplatzhalter und den Hinweis „TESTVERSION – nicht produktiv verwenden“.
Vor dem echten Patienteneinsatz Praxisangaben und Text prüfen und freigeben.
Der Hinweis wird nicht automatisch entfernt; er erscheint am iPad und im PDF.**
Der ausgelieferte Mindestwert `1.3.4` passt zu dieser mitgelieferten Vorlage.
Die technische Integration ist keine rechtliche Prüfung dieser Vorlage.
Bestehende eigene Vorlagen bleiben beim Update erhalten. Genau eine
`datenschutz.yaml` oder `dsgv.yaml`, optional mit Zahlenpräfix, ist zulässig.

### Speicherung und Wiedererkennung

- Die Kenntnisnahme wird mit Fingerunterschrift dokumentiert. Die beiden
  Einwilligungen für unverschlüsselte E-Mail und SMS sind freiwillig,
  getrennt und nicht vorausgewählt; beide dürfen auf NEIN bleiben.
- APS erhält ein neues PDF-Dokument (Typ `BEFUND_DOKUMENT`, 75, Kürzel `dsgv`),
  ohne Behandlungsschein anzulegen. Erste Zeile des Akteneintrags:
  `Datenschutz | Formularversion: 1.3.4`.
- PDF und Akteneintrag enthalten die Formularversion. Das PDF enthält den
  vollständigen gezeigten Text, Namen/Patientennummer, Datum, Unterschrift und
  beide Entscheidungen. Die Vorlage und das PDF werden per SHA-256 zugeordnet.
- Erst nach patientengebundenem Rücklesen des Akteneintrags und Prüfsummenvergleich
  des erneut heruntergeladenen PDFs gilt die Ablage als bestätigt.
- Ein vorhandenes PDF wird nur anerkannt, wenn seine Aktenbeschreibung mit exakt
  `Datenschutz | Formularversion: VERSION` beginnt und diese Version genügt.
  Andere/alte/unversionierte Scans werden **nicht** anhand eines ähnlichen Namens
  oder durch OCR als gültig geraten: Der Bogen wird dann erneut angeboten.
  Für korrekt bezeichnete Alt-PDFs ohne Check-in-Prüfsumme wird wenigstens die
  tatsächliche PDF-Verfügbarkeit geprüft. Nicht erreichbare oder gesperrte Akten
  sind ein Fehler, nicht „kein Formular vorhanden“.
- Bereits gespeicherte Dokumente werden nie überschrieben. Eine unmittelbar
  vor dem Speichern erkannte, zwischenzeitlich hinzugekommene ausreichende Fassung
  hält seit 1.5.7 den neuen unterschriebenen Vorgang zum Abgleich an, statt dessen
  möglicherweise andere Einwilligungen still zu verwerfen. Das Prüf-/Schreibpaar ist keine serverweite Transaktion;
  parallele Bearbeitung desselben Patienten vermeiden.
- „Am Empfang klären“ schreibt weder PDF noch Zustimmung. Die Anmeldung bleibt
  erhalten, die Empfangsmeldung wird ergänzt; beim nächsten Besuch wird erneut
  gefragt. Abbruch oder Zeitablauf gilt niemals als Einwilligung.

Bei einem Speicherfehler bleiben bestätigter Wartezimmereintrag und Mitarbeiterlogin
erhalten. Vor dem PDF-Upload wird eine verschlüsselte Prüfkopie mit dem erzeugten
PDF angelegt; bei unklarer Antwort bleibt sie zur Mitarbeiterprüfung unter
`app.state_dir/pending`. Keine automatische Wiederholung. Die Details enthalten
Patientendaten und bei Datenschutz zusätzlich das PDF als Base64; nicht in Logs/Chats
kopieren. Nach vollständig bestätigter Ablage wird diese Prüfkopie gelöscht.

### Foto-Fix

Nach Kontaktänderungen konnte `PatientRef.revision` aus dem Kartenlesen veraltet
sein. APS lehnt damit das finale Foto-Speichern ab, obwohl der CDN-Upload gelingt.
1.5.4 lädt die aktuelle Patientenreferenz unmittelbar vor `PHOTO_SAVE` erneut.
Ein inzwischen hinzugekommenes Patientenfoto wird nicht überschrieben; echte
Rechtefehler und Revisionskonflikte werden weiterhin beachtet, niemals blind wiederholt.
Damit wird die nachgewiesene Revisionsursache adressiert, kein Benutzerrecht umgangen.

### Prüfstand dieser Änderung

Gezielte Offline-Tests prüfen Versionen, Foto-Revisionen, Reihenfolge,
Unterschrift/optionale Einwilligungen, Abbruch, Zeitablauf, Formularänderung,
Fremdzuordnung, gesperrte Akte, PDF-Rücklesen und unklare Speicherung ohne Retry.
Der erzeugte PDF-Request wurde mit den nativen APS-26.9.1-Java-DTOs deserialisiert.
Ein synthetisches vierseitiges PDF wurde mit TCPDF 6.6.2 erzeugt und jede Seite
gerendert/sichtgeprüft. Lokale PHP-YAML-Tests nutzen Ruby/Psych als Testadapter.
Zusätzlich Syntax- und vorhandene fokussierte Regressionstests.
**Keine Installation, kein Live-Schreibtest.** PHP-YAML/FPM auf dem Zielsystem,
Safari/iPad-Unterschrift und die tatsächliche APS-PDF-Ablage sind noch abzunehmen.

Offline bei vorhandenem PHP-TCPDF/PHP-YAML:
`php tests/photo-privacy.php` und `php tests/privacy-flow.php`.
Ohne lokale YAML-Erweiterung:
`ruby tests/forms-data.rb | php tests/privacy-flow.php /pfad/tcpdf.php`.
Die Tests starten keine Netzwerkverbindungen und benutzen ausschließlich synthetische Daten.

## Lieferumfang und Prüfstand

- Mitarbeiteranmeldung für die gesamte Anwendung und alle Check-in-Schritte.
- Nach einem angehaltenen Vorgang mit „Nächste Karte“ weiter, ohne erneuten Mitarbeiterlogin.
- Kartenleser, eindeutige Patientenzuordnung, ggf. Neupatientenanlage ohne Schein.
- Kalender-Wartebereiche nach dem bisherigen Schema und konfigurierbare Zusatzlisten.
- Drei-Wege-Abfrage bei `Ohne Schein` und `fuer Empfang`.
- Optionaler Freitext für Akutfall und anderes Anliegen als **Wartezimmernotiz**.
- Freiwilliges Foto bei jedem Besuch, solange kein echtes Patientenbild vorliegt.
- Konfigurierbare Kameraseite, Rahmenhöhe und Linsenpfeil; nach Aufnahme Verschieben und Pinch-Zoom.
- Direkter Upload des zugeschnittenen JPEG als T2med-Patientenbild.
- Datenschutzbogen mit Fingerunterschrift, PDF-Ablage und konfigurierbarer Mindestversion.
- Abschlussmeldung bleibt oberhalb der freiwilligen Fotoabfrage sichtbar.
- „Fertig“ zählt standardmäßig von 15 herunter; sofortiges Beenden per Tippen möglich.
- SQL-Setzen von `kartenvorlage_datum`, im Praxisstandard aktiviert mit lokalem Datenbankzugang.
- Interaktiver Installer für Debian 12/13 bzw. Raspberry Pi OS Bookworm/Trixie.
- Eigene HTTPS-Apache-Instanz und eigene PHP-FPM-Instanz neben vorhandenen Anwendungen.

Zusätzlich zu den oben beschriebenen 1.5.4-Prüfungen wurden lokale Syntaxprüfungen für PHP,
JavaScript und Bash sowie kleine Offline-Prüfungen von Klassenlader,
Client-Erzeugung, Versionsangaben, Fehlerdiagnose und TOML-Konfiguration vorgenommen.
Zusätzlich wurden leere REST-Antworten, der Wechsel zum nächsten Patienten,
CDN-Token/Multipart, optionale Notizen und Check-in vor Foto mit simuliertem
HTTP-Verkehr geprüft; es wurden keine echten REST-Aufrufe ausgeführt.
TOML-Upgrades wurden mit synthetischen Testdateien geprüft. Oberfläche und Countdown
wurden mit lokalen DOM-/Fetch-/Timer-Testobjekten geprüft; die ergänzende lokale
Browser-Layoutprüfung in 1.5.7 ist oben beschrieben. Kein Test am echten iPad.
**Der Installer wurde nicht ausgeführt. Es gab keinen Zugriff auf das Praxissystem,
kein Karteneinlesen und keinen SQL-Schreibtest.** Die Abnahme an einem iPad und
mit T2med steht aus. Es handelt sich um den erstellten Release-Stand 1.6.1, nicht
um eine bereits im Praxisbetrieb abgenommene Installation.


## Enthalten aus 1.5.2: Kontaktanzeige und Diagnose der Textablehnung

Festnetz, Mobilfunk und E-Mail stehen einspaltig untereinander. Die Adresse wird
bei Neu- und Bestandspatienten vollständig angezeigt; Telefon und E-Mail bleiben
in der Anzeige bekannter Werte maskiert. Leere Eingaben löschen weiterhin nichts.

`REST_REJECTED · TEXT_SAVE · HTTP 200` heißt: Der HTTP-Aufruf wurde beantwortet,
aber APS hat den Text nicht als erfolgreich bestätigt. Die N-Notiz steht vor den
Stammdatenänderungen und dem Wartezimmereintrag. Eine Ablehnung hält diese Schritte
an; die Anwendung legt keinen Behandlungsschein an und wiederholt den Text nicht.

Der später diagnostizierte Praxisfehler war `ROLE_LOCATION_MISMATCH`: Die
konfigurierten IDs bildeten kein gültiges Paar. Die separat bestätigte Korrektur
auf tt/Test erfolgte in der bestehenden TOML. 1.5.4 übernimmt diese Datei und
ändert weder Praxisstruktur noch Kontext-IDs. Die alte Listenabfrage prüft die
Verfügbarkeit der einzelnen IDs, nicht deren gemeinsame Zulässigkeit;
APS prüft die Kombination weiterhin bei Schreiboperationen.

Seit 1.5.2 gibt es für Textablehnungen feste, patientendatenfreie Kategorien wie
`ROLE_LOCATION_MISMATCH`, `CASE_REQUIRED` oder `UPSTREAM_VALIDATION` (unbekannter
Grund). `SUCCESS_FLAG_INVALID` bezeichnet eine fehlende/ungültige Erfolgsangabe.
Eine Kategorie ist eine Diagnose, keine Freigabe zum Ignorieren der Ablehnung.
Die vom Server übermittelten Meldungen werden begrenzt (maximal 64 × 2048 Zeichen)
AES-256-GCM-verschlüsselt als `kind: rest_rejection` in `app.state_dir/pending`
aufbewahrt. Patientenreferenzen/Attribute und die übrige REST-Antwort werden nicht
mitkopiert; Meldungstexte können trotzdem personenbezogene Angaben enthalten.
Nur feste Kategorien und zufällige Prüfkennungen gelangen an iPad/API/Fehlerlog.
Schlägt die Sicherung fehl, bleibt der Vorgang abgelehnt und zeigt
„Diagnosekopie nicht verfügbar“.

Berechtigte Mitarbeiter können die am iPad angezeigte Prüfkennung **lokal** lesen:

```bash
sudo php /opt/t2med-checkin/1.6.4/bin/pending.php --show PRUEFKENNUNG
```

Die Ausgabe ist nur im interaktiven Terminal möglich. Nicht vollständig in Chats
kopieren; für die weitere Fehlersuche genügt die Kategorie oder die konkrete
Begründung nach Entfernen persönlicher Angaben. Diagnosekopien werden wie andere
Prüfkopien nach manueller Klärung entsprechend dem Praxis-Löschkonzept behandelt.
Sie sind keine Warteschlange und werden niemals automatisch erneut gesendet.

Gezielte Prüfung: `php tests/text-rejection.php`, `php tests/contact-read.php`
und `node tests/ui-next.js`. Alle HTTP-Antworten sind dabei synthetisch. Die
erfolgreiche Offline-Kette beweist nicht, dass der Praxisserver die N-Notiz akzeptiert.

## Enthaltene Korrektur aus 1.5.1: CONTACT_HIDDEN

Version 1.5 wertete `sichtbarFuerBenutzer` beim Abruf über
`/praxis/patient/detailsbearbeiten/find/details` irrtümlich als Zugriffsfreigabe aus.
Der untersuchte APS-Code 26.8.0 setzt dieses Feld an diesem Endpunkt nicht:
Es bleibt beim Java-Standard `false`, auch wenn Kontaktdaten korrekt geliefert werden.
Nur der separate Anzeigen-Endpunkt befüllt das Feld. Die fehlerhafte Prüfung konnte
daher neue und bekannte Patienten gleichermaßen mit `CONTACT_HIDDEN` anhalten.

1.5.1 entfernte diese unzutreffende Feldprüfung. Authentifizierung,
T2med-Berechtigung `PATIENT_BEARBEITEN`, HTTP-401/403-Behandlung, erfolgreiche
APS-Antwort, Patientenzuordnung sowie Prüfung der Kontaktlisten bleiben erforderlich.
Fehlende oder fehlerhafte Kontaktdaten werden nicht als leere Listen übernommen.
Die Kontaktabfrage muss nicht in TOML abgeschaltet werden; keine neuen Optionen.

Ein gezielter Offline-Test reproduziert den Fehler vor der Korrektur und prüft
danach Neu-/Bestandspatienten, leere Kontaktlisten, `false`/fehlendes/`true`-Flag,
Maskierung, fehlerhafte Daten, falsche Patientenzuordnung und echte REST-Ablehnungen.
Aufruf: `php tests/contact-read.php`. Der Test simuliert HTTP vollständig im Prozess
und erlaubt keine Schreibendpunkte. Die bestehenden 1.5-Funktionstests verwenden
nun ebenfalls den nativen `false`-Wert statt der vorher künstlichen Freigabe.
Keine Installation, kein Live-Kartenlesen und kein Zugriff auf das Praxissystem.

Nach dem Update aus dem neuen Release-Verzeichnis mit `install.sh --resume`
die iPad-Seite vollständig neu laden, nicht nur „Nächste Karte“ drücken.
Die Fußzeile allein belegt keinen neuen JavaScript-Stand: Auch alter Browser-Code
kann bereits die neue Serverversion anzeigen. Bei einer Homescreen-App diese
vollständig schließen und die Check-in-Adresse neu in Safari öffnen.
Einen angehaltenen Vorgang mit „Nächste Karte“ beenden; vorherige Schritte werden
nicht automatisch wiederholt oder zurückgenommen. Die Update-Anleitung folgt unten.

## Enthaltene Neuerungen aus 1.5

Kontaktdaten werden nach der Patientenzuordnung geprüft. Bei Neupatienten werden
Festnetz, Mobilfunk und E-Mail freiwillig erfragt und die bekannte Kartenadresse
zur Bestätigung angezeigt. Bei Bestandspatienten sind Telefon und E-Mail
bereits in der Serverantwort maskiert; seit 1.5.2 bleibt die Adresse vollständig. Neue Werte werden vollständig eingegeben;
leere Felder lassen bestehende Werte unverändert. Arbeits-/Fax-/sonstige Nummern
bleiben unangetastet. Es werden weder Nummern noch E-Mails gelöscht.

Telefon und E-Mail werden in den Stammdaten übernommen; der N-Protokollblock
enthält alte und neue Werte. Vorher wird ein N-Änderungsauftrag dokumentiert;
danach werden die geänderten Kontaktlisten geschrieben und zurückgelesen.
Die APS-Listen-Endpunkte ersetzen jeweils eine Liste: unveränderte Elemente und
Zusatzfelder werden mitgeführt. Zwischenzeitliche Änderungen führen zum Halt.
Ein kleines verbleibendes Rennen zwischen Prüfen und Schreiben lässt sich mit
diesen APS-Endpunkten nicht atomar ausschließen; während eines Terminalvorgangs
Kontakte nicht parallel in T2med bearbeiten.

**Adresskorrekturen werden nur als N-Hinweis gespeichert**:
„Achtung: Adresse auf der Karte stimmt nicht, korrekte Adresse: …“.
Die tatsächliche Adresskorrektur erledigt der Empfang. Keine Änderung der eGK.

Nach dem bestätigten Wartezimmereintrag folgen Datenschutz und konfigurierte Fragebögen, danach
das freiwillige Foto. Der Abschluss-/Empfangshinweis bleibt darüber sichtbar.
Als Neupatient gilt hierbei eine Person, die beim Kartenlesen neu angelegt wurde;
bereits vom Empfang vorangelegte Patienten gelten als Bestandspatienten.

```toml
[contacts]
enabled = true
note_code = "N"

[questionnaires]
enabled = true
new_patient_forms = ""
existing_patient_forms = ""
allowed_forms = "ana,anam,act,cat,phq2,phq9,alka,fage,goeb,bart"
forms_dir = "/etc/t2med-checkin/formulare"
timeout_seconds = 900
entry_code = "ana"
allergy_code = "all"

[selfie]
preview_side = "right"       # "left" oder "right"
frame_height_percent = 100  # 40–100; iPad 7: volle bisherige Vorschauhöhe
lens_arrow = "right"         # "left", "right", "top" oder "off"
capture_text = "Bitte Auslöser drücken"
```

Die Fragebogenfunktion ist technisch funktionsfähig, hat sich im Usertest am
Terminal aber nicht bewährt. Beide Startlisten bleiben deshalb im Praxisstandard
leer. Für einen ausdrücklich gewünschten Einsatz kann beispielsweise
`new_patient_forms = "ana"` gesetzt werden; die folgenden Punkte beschreiben
diese optionale Funktion.

Die übrigen TOML-Fragen und Hinweise bleiben ebenfalls konfigurierbar.
Bestehende Abschnitte bearbeiten, nicht ein zweites Mal anlegen.

- Startformulare sind kommagetrennte IDs, keine Pfade. Leere Liste: keine Startformulare.
- Die Neupatientenvorlage ist die unveränderte `ana.yaml` 2.3.5 aus fragebogenpi.
  Antworten lösen deren vorhandene Folgeformulare aus (unter anderem ACT/CAT/PHQ-9).
  `allowed_forms` begrenzt auch Folgeformulare. Dasselbe Formular läuft pro Besuch
  höchstens einmal; die IDs `ana` und `anam` verweisen auf dieselbe Datei.
- Klartext und vorhandene Vorlagenauswertung werden unter `ana` gespeichert.
  Größe (cm) und Gewicht (kg) sind freiwillig und werden bei Angabe zusätzlich
  als anamnestische Körpermaße eingetragen.
- Positive Allergieangaben stehen im ana-Text und in der Allergiefunktion.
  Bei genau einem gültigen aktuellen Fall: strukturierter Eintrag als
  **Patientenangabe, unbestätigt**, ohne erfundene Stoffcodes oder Schweregrade.
  Die ausgewählten Gruppen werden wörtlich übernommen; ausführliche Reaktionen
  stehen vollständig im ana-Bericht.
  Ohne eindeutigen Fall: separater unstrukturierter Allergietext vom Typ
  `DIAGNOSE_ALLERGIE` (26), wie besprochen. Es wird kein Fall angelegt.
  Bereits vorhandene Allergien werden weder ersetzt, bestätigt noch gelöscht.
  „Keine Allergie bekannt“ ist nur eine Selbstangabe im Bericht und löscht nichts.
- `ana` muss Typ ANAMNESE (1), `all` Typ DIAGNOSE_ALLERGIE (26) liefern.
  Abweichende Praxis-Kürzel in TOML eintragen; falsche Typen werden abgewiesen.
- Verborgene Antworten werden verworfen. Freitext pro Formularfeld: 600 Zeichen.
  Pflichtfragen sind nur diejenigen aus der jeweiligen YAML-Vorlage.
  „Fragebögen überspringen“ beendet die noch offenen Bögen; bereits bestätigte
  Bögen und die Anmeldung bleiben gespeichert.
- Formularinhalt und Zuordnung werden vor Speicherung anhand Sitzung, Vorgangs-ID,
  einmaligem Formular-Token und Vorlagen-Hash geprüft. Keine Patientendaten oder
  Anmeldeinformationen in URLs. Medizinische Bögen: keine GDT-/PDF-Ausgabe und keine dauerhaften Antworten
  im Browser. Ein Tabwechsel/Verlassen leert nicht gesendete Formulareingaben.
- Patientenbezogene wiederkehrende Formulare aus der Akte gehören ausdrücklich
  **nicht** zu dieser Version. Der einzige eingebundene Spezialhandler ist der
  oben beschriebene Datenschutzbogen mit PDF-Unterschrift; keine beliebigen YAML-Handler.

### Lokale fragebogenpi-Komponente und Pflege

Der Installer kopiert die mitgelieferte, fest versionierte Komponente nach
`/opt/t2med-checkin/1.6.4/fragebogenpi` und die fehlenden Standard-YAML-Dateien nach
`questionnaires.forms_dir`. Vorhandene Dateien, einschließlich Prioritätspräfixen,
werden nicht überschrieben. Es gibt keine Laufzeitabhängigkeit von einem separaten
fragebogenpi-Server oder GitHub. Zusätzlich werden PHP-YAML und PHP-TCPDF installiert;
kein WLAN-, Samba-, GDT- oder Netzwerk-Installer von fragebogenpi wird gestartet.

`tablet-checkin.php` ist die Variante für den Check-in-Host.
`tablet-engine.php` enthält die unveränderten gemeinsamen Auswertungsfunktionen
aus tablet.php 1.8.2; die beigefügte GDT-Variante 1.8.3 bindet diese ebenfalls ein.
Der Host `public/questionnaire.php` übernimmt Anmeldung, Auftragsbindung und APS.
Die GDT-Variante liegt absichtlich außerhalb des öffentlichen Check-in-Webroots.

Quelle und Übernahmehinweise: `fragebogenpi/README-CHECKIN.md`.
**Das Nachbarrepository/GitHub wurde nicht geändert.** Der gemeinsame Pflegestand
ist als `fragebogenpi-upstream.patch` beigefügt, einschließlich Bootstrap und
Tablet-Download der benötigten Engine. Vor Veröffentlichung dort gemeinsam übernehmen.
Ein späteres fragebogenpi-Update darf diesen Patch nicht durch einen ungeprüften
Download von main ersetzen.

### Teilübertragungen und Prüfkopien

Mehrere APS-Schreibschritte sind keine gemeinsame Transaktion. Bei Abbruch
können beispielsweise ana/N bereits gespeichert, Messwerte/Kontakte aber noch offen
sein. Vor der ersten Änderung wird eine AES-256-GCM-verschlüsselte Prüfkopie unter
`/var/lib/t2med-checkin/pending` (bzw. `app.state_dir/pending`) angelegt.
Sie enthält ursprüngliche Angaben und bestätigte Teilschritte. Nach vollständig
bestätigter Übernahme wird nur diese zugehörige Kopie entfernt.
Bei unklarer Rückmeldung wird der Ablauf angehalten; **keine automatische Wiederholung**.

Berechtigte Mitarbeiter können auf dem Server rein lesend prüfen:

```bash
sudo php /opt/t2med-checkin/1.6.4/bin/pending.php
sudo php /opt/t2med-checkin/1.6.4/bin/pending.php --show KENNUNG
```

Die Liste enthält nur Kennungen. Detailanzeige enthält Patientendaten und funktioniert
nur im interaktiven Terminal. Nicht in Chat/Supportlogs kopieren. Danach die
entsprechende Akte prüfen und fehlende Teile manuell ergänzen. Keine automatische
Rücknahme bereits bestätigter Einträge; keine automatische Wiedervorlage.

Prüfkopien werden bei unklaren Vorgängen absichtlich nicht zeitgesteuert gelöscht.
Berechtigung, verschlüsselte Sicherung (einschließlich `secret.key`), Aufbewahrung und
Löschung nach manueller Klärung müssen im Praxisbetrieb organisiert werden.
Den Schlüssel nicht ersetzen, solange Prüfkopien benötigt werden. Diese Kopien sind
keine vollständige Datensicherung der Patientenakte.

### Prüfstand 1.5

PHP-/Bash-/JavaScript-Syntax und gezielte synthetische Prüfungen wurden lokal
durchgeführt: Kontakte/Maskierung/N, erhaltene Fax-/Adressdaten, Formular-Token,
Vorlagen-Hash, ACT-Folgeformular und Auswertung, freiwillige Messwerte, beide
Allergiewege, Sitzungswechsel und angehaltene Teilübertragung. Die Speicherung
wurde auch mit den nativen zunächst leeren Referenzen geprüft: Text-ID über die
gespeicherte, patientengebundene Karteizeile; Körpermaß-ID über genau einen neuen,
passenden Eintrag in der Patientenliste. Falsche Zuordnung, fehlende/mehrdeutige
Rückmeldungen oder abweichende gespeicherte Werte führen zum Halt ohne Wiederholung.
Die unveränderten YAML-Dateien wurden mit vorhandenem Ruby/Psych eingelesen;
ein Testadapter liefert diese Daten an PHP, da lokal PHP-YAML fehlt.
**Die native PHP-YAML-Integration, echte Safari-/iPad-Kamera und APS-Schreibvorgänge
auf T2med müssen auf dem Zielsystem noch abgenommen werden.**
Es wurde nichts installiert und kein Praxissystem angesprochen.

Optionaler Offline-Aufruf bei vorhandenem Ruby (keine Laufzeitabhängigkeit):
`ruby tests/forms-data.rb | php tests/features15.php`.
Bei installiertem PHP-YAML genügt `php tests/features15.php`.

## Enthaltene Korrekturen aus 1.4 und früher

Version 1.4 korrigiert den vorgeschlagenen CDN-Port für den Foto-Upload:

```toml
[t2med]
rest_port = 16567
cdn_port = 16570
```

Die mitgelieferte T2med-26.8.0-Datei `application.properties` im Modul
`server-util-26.8.0.jar` enthält `cdn.port=16570`. Der bisherige Installer leitete
den CDN-Port dagegen vom REST-Port ab und schlug damit normalerweise `16567` vor.
REST und CDN sind getrennte Dienste. Ein Upload an den falschen Dienst kann
`REST_HTTP_404 / PHOTO_TRANSFER` erklären; die tatsächliche Zielinstallation
wurde nicht geprüft. Bei angepassten Server-/Proxy-Ports gelten deren eigene Werte.

Bei Neuinstallation wird für CDN unabhängig vom REST-Port `16570` vorgeschlagen.
Beim Update mit vorhandener TOML bietet der Installer nur dann eine Portkorrektur
an, wenn `t2med.cdn_port` genau `16567` ist:

> CDN-Port für eine T2med-Standardinstallation von 16567 auf 16570 korrigieren (j/n) [j]

Bei den T2med-Standardports mit `j` bzw. Enter bestätigen. Bei absichtlich
abweichender Portbelegung `n` wählen und den eigenen Wert beibehalten.
Die Änderung wird erst nach dieser Bestätigung und einer Sicherung gespeichert.
Andere explizite CDN-Ports werden nicht geändert; der REST-Port bleibt immer erhalten.
Fehlt `cdn_port` vollständig, greift der neue Vorlagenstandard `16570`, ohne eine
Zeile in die TOML einzufügen. Am Ende werden beide verwendeten Ports ausgegeben.
Es erfolgt dabei keine automatische Portsuche, kein Testupload und keine Wiederholung
eines fehlgeschlagenen Fotos. Nach erfolgreicher Korrektur wird bei `--resume` keine
erneute Portänderung oder Sicherung durchgeführt. Ein zuvor mit `n` beibehaltener
Wert `16567` wird bei einem erneuten Installeraufruf wieder zur Prüfung angeboten.

Der Check-in-Server muss den T2med-CDN-Dienst auf dem konfigurierten HTTPS-Port
erreichen können. Firewall und T2med-Servereinstellungen werden nicht verändert.
Token-Kodierung, Multipart-Format und Patientenablauf aus 1.3 bleiben unverändert.
Offline geprüft wurden Standard-/Sonderports, bestätigte und abgelehnte Migration,
Sicherung und die tatsächlich im simulierten HTTP-Aufruf verwendeten Ports.
Ein erfolgreicher realer Foto-Upload ist noch auf dem Zielsystem zu bestätigen.

Aus Version 1.3 bleiben die gesammelten Änderungen enthalten:

- Standard-Ziel für `with_case_fallback` und `other`: exakt `fuer Empfang`.
  `card_only` und `without_case` bleiben bei `Ohne Schein`.
- Neue Auswahl: „Ich wollte nur kurz meine Karte einlesen lassen :-)“.
- Freitext darf leer bleiben. Bestehende Notizen werden dann nicht geändert.
- Erst wird der Check-in durch Nachlesen bestätigt, danach die freiwillige Fotoaufnahme
  angeboten. Die kategorienbezogene Abschlussmeldung bleibt bei Frage, Kamera und
  Bildanpassung darüber sichtbar. Fotofehler stellen den bestätigten Check-in nicht
  als fehlgeschlagen dar und wiederholen keine Wartezimmeränderung.
- „Fertig (15)“ zählt sichtbar herunter und kehrt zur Kartenstartseite zurück;
  Antippen beendet sofort. Neuladen startet die Frist nicht neu.
- CDN-Upload an den vorhandenen T2med-Client angeglichen: zweistufige Token-Kodierung
  (Java-URLEncoder und RESTEasy-Pfadparameter) sowie `metaData.values` im Multipart.
  Diese Korrektur adressiert `REST_HTTP_400 / PHOTO_TRANSFER`; ein erfolgreicher
  Upload auf dem tatsächlichen T2med-Server ist damit noch nicht nachgewiesen.

Die Korrekturen aus 1.2 bleiben enthalten. Version 1.2 behebt `REST_JSON` bei der Kartenfreigabe: Laut mitgelieferter
`VersichertenkarteEinlesenBoundary` liefert `karteAuswerfen` keinen Rückgabewert
(`void`). Die PHP-Anwendung akzeptiert dort nun erfolgreiche HTTP-Antworten ohne
JSON-Inhalt, einschließlich HTTP 204. Andere Aufrufe mit erwarteten Daten bleiben
streng auf gültiges JSON und die jeweilige Erfolgsantwort geprüft.

Auf dem Fehlerbildschirm führt der große Knopf „Nächste Karte“ zur Startseite
„Bitte Karte einlesen“ zurück. Die Mitarbeiteranmeldung bleibt bestehen.
Bitte die bisherige Karte entfernen und erst dann die nächste einstecken;
der Knopf wiederholt weder den fehlgeschlagenen Vorgang noch startet er unmittelbar
ein weiteres Kartenlesen. Der betroffene Patient klärt seinen Vorgang am Empfang.

Version 1.0 hatte einen Fehler im Klassenlader: Die Ziffer im Namen `T2med`
wurde nicht zugelassen. Dadurch brach die Einrichtung nach der Passworteingabe
mit „Interner Fehler“ ab, bevor der erste REST-Aufruf stattfinden konnte.
Dieser Fehler ist seit Version 1.1 behoben. Benutzer oder Passwort müssen wegen dieses
Fehlers nicht geändert werden.

Das neue Archiv auf den Linux-Rechner übertragen und neben den bisherigen Versionen entpacken.
Im Verzeichnis, in dem das Archiv liegt, ausführen:

```bash
tar -xzf t2med-checkin-1.6.1.tar.gz
cd t2med-checkin-1.6.1
sudo bash install.sh --resume
```

Wichtig: Den Installer **aus dem neuen Verzeichnis `t2med-checkin-1.6.1`** starten.
Er verwendet `/opt/t2med-checkin/1.6.1`. Die alten Programmordner der Versionen
1.0 bis 1.5.4 unter `/opt/t2med-checkin/` bleiben unverändert erhalten.
Die gemeinsame TOML-Datei unter `/etc/t2med-checkin/config.toml` wird geprüft.
Neben der gegebenenfalls bestätigten Portkorrektur werden die drei alten
Standardwerte aus dem 1.3-Update weiterhin ersetzt, wenn sie noch
exakt `Mit Schein unklar` (zweimal `room`) bzw. `Ich wollte nur meine Karte einlesen`
(`card_only.label`) lauten. Individuell geänderte Werte, Kommentare und alle anderen
Einstellungen bleiben erhalten. Vor einer Änderung entsteht eine nur für den
Eigentümer lesbare Sicherung `config.toml.pre-1.6.1-DATUM-ZUFALL.bak` im selben Verzeichnis.
Zusätzlich werden fehlende 1.5-Felder und der Abschnitt `[privacy]` ergänzt. Sind diese bereits vorhanden und gibt es
keine weitere Migration, bleibt die Datei unverändert; erneutes `--resume` erzeugt keine weitere Sicherung. `fuer Empfang` muss in T2med genau so existieren;
es wird kein Wartebereich automatisch angelegt. Schlüssel,
HTTPS-Einstellungen, Zertifikate und Sitzungsverzeichnisse werden weiterverwendet.
Beim ursprünglichen Einrichtungsabbruch in 1.0 war die TOML noch nicht gespeichert; in diesem Fall
werden Server und Zugangsdaten erneut abgefragt. Zugangsdaten werden weiterhin
nicht gespeichert.

Der Installer prüft sowohl das neue Release als auch seine installierte Kopie
lokal auf Versionsgleichheit, ladbare PHP-Klassen und gültige Konfigurationsvorlage,
bevor er Zugangsdaten abfragt. Bei einem Fehler nennt die CLI-Diagnose jetzt Version,
Einrichtungsschritt, Fehlertyp, Dateiname und Zeilennummer. Unerwartete Fehlermeldungen
und Stacktraces mit möglichen Zugangsdaten werden nicht ausgegeben.

Der Installer prüft erneut die benötigten APT-Pakete. Nach erfolgreicher Einrichtung
startet er ausschließlich die beiden eigenen Check-in-Dienste neu, damit auch bei
bereits laufenden Diensten das neue Programmverzeichnis aktiv wird. Daher außerhalb
aktiver Check-in-Vorgänge ausführen. Dies ist keine automatische Rückrollfunktion;
die erzeugten Apache-/PHP-FPM-Konfigurationen verweisen anschließend auf 1.6.1.
Danach die Seite am iPad vollständig neu laden; die Startseite muss Version 1.6.1 anzeigen.
Eine noch gültige Sitzung bleibt bei unverändertem Browser, Servernamen und
Anwendungsschlüssel erhalten. Ein noch angehaltener Vorgang lässt sich mit
„Nächste Karte“ beenden. Eine offene Fotostufe aus 1.2 oder älter wird mit
`FLOW_VERSION` angehalten, da sie den Check-in noch nicht abgeschlossen hatte;
in diesem Fall zuerst am Empfang klären, dann „Nächste Karte“ wählen.

## Installation

Das vollständige Release-Verzeichnis auf den vorgesehenen Linux-Rechner übertragen.
Der Rechner benötigt Netzwerkzugang zum T2med-Server und zu den Paketquellen.
Dann in diesem Verzeichnis ausführen:

```bash
sudo bash install.sh
```

Der Installer führt diese Schritte aus:

1. Apache, PHP-FPM, PHP-CLI, cURL, GD, mbstring, YAML, TCPDF, PostgreSQL-Treiber und SSH-Client installieren.
2. Dienstbenutzer `t2checkin`, eigene Anwendungs- und Sitzungsverzeichnisse anlegen.
3. T2med-Server und Ports abfragen (REST: `16567`, CDN: `16570`). Bei einem eigenen T2med-Zertifikat kann eine
   vertrauenswürdige PEM-CA-Datei eingebunden werden.
4. Einmalig nach T2med-Zugangsdaten fragen, um Kartenleser, technische Arztrolle,
   technischen Behandlungsort und vorhandene Wartebereiche auszulesen.
5. Den Kartenleser und den technischen Kontext auswählen. Standard-Wartezimmernamen
   werden übernommen, wenn sie genau einmal existieren. Fehlende Vorgaben werden
   durch eine Auswahl aus den vorhandenen Wartebereichen aufgelöst.
6. Die optionale SQL-Datumsänderung abfragen und ggf. den SQL-Zugang vorbereiten.
7. Die DNS-/IP-Adresse für das iPad, eine lokale IPv4-Bind-Adresse und einen freien
   HTTPS-Port abfragen. Vorgabe für den Port: `8443`.
8. Eine lokale CA und ein Serverzertifikat mit passendem SAN erzeugen.
9. Eigene PHP-FPM- und Apache-Systemd-Units einrichten und starten.

Bei der Einrichtung werden keine Patienten angelegt, Karten eingelesen oder
Wartezimmereinträge verändert. Der optionale SQL-Zugang wird ausschließlich
über das Schema geprüft.

Eine unterbrochene Installation kann fortgesetzt werden:

```bash
sudo bash install.sh --resume
```

Vorhandene TOML-Konfiguration (mit der oben beschriebenen Standardwertmigration)
und Schlüssel werden dabei beibehalten. `--resume`
verwendet ausschließlich den zum gestarteten Installer gehörenden Versionsordner.
Existiert `/opt/t2med-checkin/1.6.1` schon, wird dessen Programmstand geprüft und
weiterverwendet, nicht mit beliebigen Quelldateien überschrieben. Für Änderungen
wird immer eine neue Versionsnummer und ein eigener Release-Ordner ausgegeben.
Bei einem Fehler vor dem Erzeugen der TOML gegebenenfalls bereits gespeicherte
SQL-Zugangsdaten kontrollieren. Die Meldung des Installers nennt den Haltepunkt.

Die eigene Apache-Instanz verändert keine bestehenden Virtual Hosts, Samba-,
WLAN- oder Firewall-Konfigurationen. Die Paketinstallation kann die üblichen
Distributionsdienste erstmals einrichten; auf einem vorhandenen Apache-System
werden die bestehenden Sites weiter von dessen bisherigem Dienst bedient.
Der Check-in verwendet einen separaten PHP-FPM-Dienst; bestehende PHP-FPM-Pools
werden nicht verändert oder durch den Check-in-Installer neu geladen.

## iPad vorbereiten

Die vom Installer ausgegebene HTTPS-Adresse in Safari öffnen und beim ersten
Aufruf für den eigenen Server einmal „Trotzdem verbinden“ wählen. Im erprobten
Aufbau ist keine zusätzliche Zertifikatsinstallation am iPad erforderlich.
Kamerazugriff erlauben, zum Home-Bildschirm hinzufügen und im geführten Zugriff
betreiben. Für diesen keine automatische Bildschirmsperre einstellen.

Wer das Serverzertifikat zusätzlich auf dem iPad als vertrauenswürdig einrichten
möchte, kann das vom Installer erzeugte öffentliche CA-Zertifikat verwenden:

```text
/opt/t2med-checkin/1.6.4/public/checkin-ca.cer
```

Das Zertifikat per AirDrop/MDM auf das iPad übertragen oder über
`https://CHECKIN-HOST:8443/checkin-ca.cer` laden. In den iPad-Einstellungen das
Profil installieren und unter **Allgemein → Info → Zertifikatsvertrauenseinstellungen**
dieser CA vollständig vertrauen.

Die URL muss genau den eingerichteten DNS-Namen bzw. die eingerichtete IP-Adresse
verwenden. Die CA ist zehn Jahre, das Serverzertifikat ein Jahr gültig.
Für eine bestehende Praxis-PKI können die Zertifikate unter
`/etc/t2med-checkin/tls/server.crt` und `server.key` ersetzt und der eigene
Check-in-Dienst anschließend neu gestartet werden. Vor Ablauf ist eine Erneuerung
erforderlich. Den privaten CA-Schlüssel nicht auf das iPad übertragen.

## Bedienung

Ein Mitarbeiter meldet das Gerät mit seinem T2med-Zugang an. Die Anmeldung gilt
für alle Check-in-Seiten dieser Anwendung im selben Browser und endet spätestens
nach den konfigurierten zwölf Stunden. Nach Ablauf erscheint die Mitarbeiteranmeldung.
Ein gemeinsam genutzter Kartenleser wird gegen gleichzeitig laufende Vorgänge
gesperrt; pro Kartenleser ist ein aktives Check-in-Fenster vorgesehen.

Patienten stecken ihre Karte ein und tippen auf die große Schaltfläche
„Karte einlesen“. Die Karte wird eingelesen und anschließend freigegeben.
Es erfolgt kein fortlaufendes automatisches Wiedereinlesen einer stecken gelassenen Karte.

| Ausgangslage | Weiterer Ablauf |
|---|---|
| Neupatient | Neupatienten-Hinweis; Eintrag in `Neupatient` und ggf. Verschieben vorhandener Kalender-Wartezimmereinträge |
| Bekannter Patient mit Termin und Schein | Regulär in die zugehörigen `Wartezimmer …` |
| Bekannter Patient mit Termin ohne Schein | Regulär in die zugehörigen `Wartezimmer …`; zusätzlich Mitarbeiterliste `Termin ohne Schein` wie in 1.3 |
| Bekannter Patient ohne Termin, mit Schein | Drei-Wege-Abfrage für `fuer Empfang` |
| Bekannter Patient ohne Termin, ohne Schein | Dieselbe Drei-Wege-Abfrage für `Ohne Schein` |

Die ergänzende Mitarbeiterliste bei einem Termin ohne Schein lässt sich über
`routing.add_appointment_without_case_queue` abschalten. Die Patientenausgabe
bleibt die normale Wartezimmernachricht. Den Schein legen Mitarbeiter später an.

| Auswahl | Eingabe | Ziel |
|---|---|---|
| Akutes Problem | „In aller Kürze: Warum sind Sie hier?“ | `Akutfall`, Freitext in der Wartezimmernotiz |
| Nur Karte einlesen | Keine | `Ohne Schein`, anschließend Dankeschön; keine erneute Auswahl-Schleife |
| Anderes Anliegen | „In aller Kürze: Um was geht es?“ | `fuer Empfang`, Freitext in der Wartezimmernotiz |

Freitext ist freiwillig und auf 250 Zeichen begrenzt. „Weiter“ funktioniert auch
ohne Text. Leere Eingaben bzw. reine Leerzeichen erzeugen keine Notiz-Ergänzung.
Bestehende Notizen bleiben erhalten;
der neue Text wird angehängt. Würde das Gesamtlimit überschritten, wird der
Vorgang zur Klärung am Empfang angehalten. `Akutfall` ist eine Wartezimmerkategorie;
die Anwendung setzt nicht eigenständig T2meds medizinisches Notfallkennzeichen.

Die bisherige Kalenderlogik wird übernommen: **`Kalender XXXX` ist ein
T2med-Wartebereich**, dessen vorhandener Eintrag nach `Wartezimmer XXXX`
verschoben wird. Es wird kein neuer Kalendereintrag angelegt. Prefixe sind in
der TOML konfigurierbar. Einträge werden auf Status `0` gesetzt, der in
T2med 26.8.0 als `WARTET` definiert ist.

## Foto

Vor der Fotoabfrage wird der Check-in abgeschlossen und durch Nachlesen geprüft.
Die Abschlussmeldung der gewählten Kategorie bleibt über der Fotoabfrage sowie
über Kamera und Bildanpassung sichtbar. Die Aufnahme ist ein unabhängiger,
freiwilliger Folgeschritt: Ablehnen, Auslassen oder ein Fotofehler löst keinen
weiteren Wartezimmereintrag aus.

Die Anwendung fragt nach dem Foto, wenn das T2med-Modul `PATIENTENBILD` kein
echtes Foto liefert. Standardporträts aus `@static/portraits/` zählen als fehlend.
Eine Ablehnung wird nicht dauerhaft gespeichert; beim nächsten Besuch ohne Bild
erscheint die Frage erneut. Ein vorhandenes Foto wird nicht regulär ersetzt.
Bei für den Benutzer gesperrtem Bildmodul wird keine Aufnahme angeboten.

Die Kamera läuft ohne Kamera-Zoom. Der konfigurierbare Rahmen markiert den vorgesehenen
Ausschnitt (Standard: gesamte Vorschauhöhe). Nach dem Auslösen bleibt das vollständige Kamerabild vorübergehend
im Browser, sodass der Ausschnitt mit einem Finger verschoben und mit zwei
Fingern gezoomt werden kann. Es gibt keine Zoomknöpfe. Das Häkchen übernimmt den
Ausschnitt; der Rückwärtspfeil startet eine neue Aufnahme. Vorschau und gespeichertes
Bild sind identisch gespiegelt.

Nur der quadratische Ausschnitt wird als JPEG übertragen (Vorgabe 800 × 800 Pixel).
PHP prüft die Dimensionen, dekodiert das JPEG erneut und entfernt dadurch
mitgelieferte Metadaten. Das Bild wird direkt über den T2med-Uploadtoken und
den CDN-Upload als Patientenbild gesetzt. Es gibt keine GDT-Datei und kein
dauerhaft im Webroot gespeichertes Selfie. PHP-Uploaddateien sind temporär und
liegen außerhalb des öffentlich erreichbaren Verzeichnisses.

Wenn die Kamera nicht freigegeben oder nicht verfügbar ist, kann der Patient
ohne Foto fortfahren. Ein unklarer Fehler bei der tatsächlichen Übermittlung
wird am Empfang geklärt, statt den Upload ungeprüft zu wiederholen. Der Bildschirm
behält die Check-in-Meldung und zeigt separat `messages.photo_error` an. Mit
„Nächste Karte“ geht es ohne neue Mitarbeiteranmeldung weiter.

Beim Abschluss zeigt „Fertig“ die verbleibenden Sekunden (Vorgabe 15) und kehrt
bei null zur Kartenseite zurück. Antippen kehrt sofort zurück. Die Dauer steht
in `app.completion_seconds`; eine individuell konfigurierte Dauer bleibt erhalten.

## Konfiguration und Zugangsdaten

```text
/etc/t2med-checkin/config.toml       Verbindung, Abläufe und Texte
/etc/t2med-checkin/secret.key        Schlüssel für verschlüsselte Anmeldedaten
/etc/t2med-checkin/sql-password      Optionales PostgreSQL-Passwort
/etc/t2med-checkin/sql_ed25519       Optionaler begrenzter SSH-Schlüssel
/etc/t2med-checkin/known_hosts       Verifizierter SSH-Host-Schlüssel
/var/lib/t2med-checkin/              Private Sitzungen und Betriebsprotokolle
/opt/t2med-checkin/1.6.4/public/     Ausschließlich öffentlich benötigte Dateien
```

`config.example.toml` beschreibt die Felder und alle Standardmeldungen.
Unter `[categories.…]`, `[messages]` und `[selfie]` sind die Bildschirmtexte
anpassbar. Das iPad verwendet lokale Systemschriften; es werden keine CDN-Skripte,
Webfonts oder externen Bilddienste geladen.

Der mitgelieferte TOML-Leser unterstützt bewusst nur die für diese Konfiguration
benötigte, eindeutig geprüfte Teilmenge: einfache und gepunktete Tabellennamen,
einzeilige doppelt/einfach zitierte Strings, JSON-kompatible String-Escapes,
Ganzzahlen, `true`/`false` und Kommentare. Arrays, Inline-Tabellen, unzitierte
Datumswerte und mehrzeilige Strings werden mit einer Zeilenmeldung abgewiesen.
Es werden keine Composer- oder JavaScript-Pakete bei der Installation benötigt.
Unbekannte Konfigurationsschlüssel und falsche Datentypen werden abgewiesen.

T2med-Benutzername und Passwort werden bei der Geräteanmeldung ausschließlich
serverseitig in der Sitzung verschlüsselt aufbewahrt. Der Browser erhält ein
Secure-/HttpOnly-Sitzungscookie und einen CSRF-Token. Es werden keine
Patientenreferenzen an den Browser gegeben. Nach Abschluss werden Patient,
Freitext und Routingplan sofort aus der Sitzung entfernt. Eine inaktive
Patientensitzung endet nach drei Minuten, bei Fragebogen und Datenschutz nach 15 Minuten.
Eingaben verlängern die Inaktivitätsfrist. Die Mitarbeiteranmeldung bleibt bestehen.

Der technische Arztrollen-/Behandlungsortkontext wird einmalig im Installer
festgelegt und beim Anmelden gegen T2med geprüft. Er erscheint nicht in der
Patientenoberfläche. Die Anwendung erzeugt keinen Behandlungsschein.

## Optionales Kartenvorlagedatum per SQL

```toml
[card_presentation_date]
enabled = true
target_date = "1990-01-01"
```

Bei `enabled = false` gibt es keinen SQL-Verbindungsversuch, auch nicht beim
Mitarbeiterlogin. Bei aktiver Option wird der Zugang beim Anmelden und vor dem
Kartenlesen geprüft. Nach dem Einlesen und der eindeutigen Patientenzuordnung
wird ausschließlich `aps.versicherungsnachweis.kartenvorlage_datum` für die
zurückgegebene Kartenreferenz gesetzt. Der Zielwert ist als String angegeben.

Der Schutz entspricht 1.3: exakte Objekt-ID, mindestens die zurückgegebene
Revision, `classid = 3`, höchstens 15 Minuten alter Datensatz, genau eine
betroffene Zeile, Transaktion und unabhängige Bestätigung des gespeicherten Datums.
Erst anschließend geht der Check-in weiter. Bei SQL-Fehlern bleibt der schon
durch REST importierte Datensatz bestehen, der weitere Ablauf wird angehalten.

Wie in 1.3 werden T2med-Revision, Änderungszeitpunkt und Envers-Audit durch den
direkten SQL-Zugriff nicht aktualisiert. Die Anwendung protokolliert nur, dass
die Datumsoperation ausgeführt wurde; dies ersetzt kein T2med-Datenaudit.

Verbindungsarten in `[sql]`:

- `mode = "local"`: PDO/PostgreSQL über den lokalen Socket. Dafür müssen
  Betriebssystem- und PostgreSQL-Zugriffsrechte des Dienstbenutzers passen.
- `mode = "tcp"`: PDO/PostgreSQL mit TLS und separater Passwortdatei. Standard
  ist `sslmode = "verify-full"`; Datenbankzugriff muss auf dem Server eingerichtet sein.
- `mode = "ssh"`: PHP startet OpenSSH mit einem eigenen Schlüssel. Dieser
  Schlüssel ist auf dem T2med-Server auf ein festes Gateway beschränkt und
  erlaubt weder eine interaktive Shell noch Portweiterleitungen. PostgreSQL
  muss dafür nicht im Netzwerk geöffnet werden.

Das SSH-Gateway verwendet wie 1.3 den T2med-Client unter
`/opt/t2med/server/postgres/bin/psql`, Socket `/tmp`, Port `16569`,
Benutzer und Datenbank `t2med`. Diese Werte stehen für den SSH-Modus ausschließlich
im Gateway, nicht in den PDO-Einstellungen der TOML. Bei abweichender T2med-Installation
muss `installer/sql-gateway.sh` vor der Gateway-Einrichtung angepasst werden.

Der Installer kann das Gateway über eine einmalige root-SSH-Anmeldung einrichten.
Dazu wird auf dem T2med-Server ein zusätzlicher öffentlicher Schlüssel mit
`restrict,command=…` in `/root/.ssh/authorized_keys` ergänzt. Vorhandene Schlüssel
werden erhalten. Die PHP-Anwendung erhält nur den zugehörigen eingeschränkten
Schlüssel. Das root-Passwort wird weder in der Anwendung noch in TOML gespeichert.

Alternativ `installer/sql-gateway.sh`, `installer/provision-sql.sh` und den
öffentlichen Check-in-Schlüssel auf den T2med-Server übertragen und dort ausführen:

```bash
sudo bash provision-sql.sh /pfad/sql_ed25519.pub
```

Danach den verifizierten Host-Schlüssel in `/etc/t2med-checkin/known_hosts`
auf dem Anwendungsrechner bereitstellen und dessen Installer mit `--resume`
fortsetzen. Der Gateway-Test führt nur eine Schemaabfrage aus.

## Betrieb und Fehlerklärung

Die neue lokale Programmprüfung lässt sich auch separat im entpackten Release
ausführen. Sie benötigt keine Konfiguration der Praxis und keine Zugangsdaten:

```bash
php bin/check-runtime.php 1.6.1
php tests/consent.php
php tests/smoke.php
php tests/rest-next.php
php tests/config-upgrade.php
```

Diese Prüfungen arbeiten ausschließlich offline. `rest-next.php` nutzt kurzlebige
Testdateien in einem eigenen temporären Verzeichnis und entfernt sie wieder;
Netzwerkverkehr wird durch eine lokale Testimplementierung ersetzt.
`config-upgrade.php` verwendet ausschließlich eigene temporäre TOML-Testdateien.
Bei bereits vorhandenem Node.js kann zusätzlich `node tests/ui-next.js` die
Schaltfläche und den Zustandswechsel mit Testobjekten prüfen. Node.js ist keine
Voraussetzung für Installation oder Betrieb der Anwendung.
Die folgenden Betriebschecks sind davon zu unterscheiden; `check-config.php`
kann bei aktivierter SQL-Option das SQL-Schema auf dem T2med-Server lesen:

```bash
sudo systemctl status t2med-checkin
sudo journalctl -u t2med-checkin -n 50
sudo -u t2checkin php /opt/t2med-checkin/1.6.4/bin/check-config.php
```

Der Konfigurationscheck prüft lokale Voraussetzungen und ggf. das SQL-Schema.
Er liest keine Karte ein und schreibt keine Patientendaten. REST-Rechte werden
mit den tatsächlich eingegebenen Mitarbeiterdaten bei der Geräteanmeldung geprüft.

PHP benötigt insbesondere die T2med-Rechte für Kartenlesen, Patientendaten,
Neuanlage, Wartezimmer, Patientenbilder, Textdokumentation, Körpermaße und Allergien; das genaue Rechteprofil wird in
T2med vergeben. `WARTEZIMMER_OEFFNEN` ist für die Listenabfragen erforderlich.
Fehlende Rechte werden bei den jeweiligen Aufrufen gemeldet.

Nach einem unklaren Schreibfehler oder einem abgebrochenen PHP-Prozess erscheint
ein Empfangshinweis. Ein Mitarbeiter prüft den Zustand des betroffenen Patienten
in T2med; bereits erfolgte Änderungen werden nicht zurückgenommen oder automatisch
wiederholt. Am Gerät lässt sich unabhängig davon mit **„Nächste Karte“** der
bisherige Patientenvorgang beenden. Die noch offene Kartensitzung wird freigegeben,
die eigene Lesegerätesperre aufgehoben und Patient, Routingplan und Notiz aus der
PHP-Sitzung entfernt. Mitarbeiterlogin, ursprüngliche Anmeldefrist und CSRF-Token
bleiben erhalten. Bei fehlgeschlagener Kartenfreigabe bleibt der Vorgang mit einer
neuen Diagnose angehalten; „Nächste Karte“ kann die Freigabe erneut versuchen,
ohne Patientenänderungen zu wiederholen. Eine fremde aktive Lesegerätesperre wird
nicht übernommen. Nur bei abgelaufener oder ungültiger Anmeldung wird ein erneuter
Mitarbeiterlogin verlangt. Der normale Fehlerbildschirm fordert ihn nicht mehr.

Wartezimmer-Add/Move/Update werden durch
Nachlesen geprüft; bereits vorhandene Einträge werden nicht nochmals hinzugefügt.
Mehrere Wartezimmeränderungen sind keine serverübergreifende Transaktion. Bei
einem Fehler nach dem ersten Schritt kann deshalb ein Teil bereits ausgeführt sein.

Bei Verlust des Browser-Sitzungscookies kann die Lesegerätesperre des alten
Fensters noch bis zu 15 Minuten bestehen. Vor einem neuen Versuch den alten
Check-in in T2med prüfen. Bei erhaltenem Cookie kann ein angehaltener Vorgang
über „Nächste Karte“ im selben Fenster beendet werden. Laufende Vorgänge werden
nicht vorzeitig verworfen; veraltete Fenster dürfen keinen neueren Patientenablauf beenden.

Protokolle enthalten Ereignistypen, Fehlercodes und zufällige Vorgangskennungen,
keine Namen, Kartendaten, Freitexte, Fotos oder Anmeldepasswörter. Private Sitzungen
enthalten während eines offenen Vorgangs die notwendigen T2med-Referenzen.
Logrotate begrenzt die Protokollaufbewahrung; tmpfiles entfernt alte Sitzungs-
und Temporärdateien.

Bei REST-Fehlern enthält das PHP-Fehlerprotokoll zusätzlich eine feste Aufrufkennung
(z. B. `CARD_RELEASE`), den HTTP-Status, eine eingegrenzte Content-Type-Kategorie
und die Antwortlänge. URLs, Sitzungs-UUIDs, Abfrageparameter, Headerwerte und
Antwortinhalte werden nicht protokolliert. Aufrufkennung und HTTP-Status erscheinen
auch beim Fehlerhinweis am iPad und bleiben beim Neuladen sichtbar. Den Text des
neuen Knopfs steuert `messages.next_card_button`; bestehende TOML-Dateien erhalten
den Standardwert „Nächste Karte“ automatisch, ohne neu geschrieben zu werden.

## Nachgelagerte Abnahme

Die umfangreichen Tests sind entsprechend der Vorgabe für einen späteren Schritt
vorgesehen. Dazu gehören Installation auf dem Zielsystem, realer Kartenleser,
Neupatient und bekannter Patient, Termine mit/ohne Schein, alle drei Anliegen,
Notizerhaltung, vorhandenes/fehlendes Foto, iPad-Pinch-Zoom, SQL an/aus,
Sitzungswechsel und Fehler nach teilweise ausgeführten Änderungen.

Die Kamera und die T2med-REST-Integration wurden anhand vorhandenen Codes erstellt;
ein erfolgreicher Syntaxcheck allein bestätigt noch keinen produktiven Check-in.

## Änderungen

### 1.6.1 – 27. September 2026

- Semantischer Einwilligungsabgleich mit geschützten fachlichen Werten statt
  vollständigem Roh-DTO-Vergleich. Sichere Diagnosen und verschlüsselte Vergleichsdaten.
- Mitarbeitergesteuerter Pending-Abschluss mit PDF-/Patienten-/Einwilligungsnachweis,
  konservativem Konfliktstopp, Sperre und Audit. Keine automatischen Wiederholungen.
- Natürlich scrollbar dargestellte Freitextseiten ohne Header und Footer.
- Gezielte Offline- und Browserprüfung; keine Installation oder Live-Schreibversuche.

### 1.6 – 27. September 2026

- Kontaktseite ohne Header/Footer, Scroll-Sperre, feste Viewporthöhe oder
  programmatische Tastatur-/Scroll-Korrekturen. Normales Browser-Scrolling.
- Größere, fett dargestellte maskierte Kontaktdaten mit „Korrigieren“ daneben.
  Eingabefeld erst nach Auswahl; Verwerfen betrifft nur die ungespeicherte Änderung.
- Alle Kontakte direkt auf einer Seite statt künstlicher Dreiergruppen.
- Andere Seiten und fachliche Speicherung unverändert. Eigenes Installerpaket,
  gezielte Offline-Prüfungen; keine Installation oder Live-Patientenzugriffe.

### 1.5.9 – 27. September 2026

- Nach Schließen der Bildschirmtastatur zentrale Scrollposition und verspäteten
  Safari-Versatz zurücksetzen, auch bei weiter fokussiertem Eingabefeld.
- Veraltete Fokus-Callbacks können das Zurücksetzen nicht rückgängig machen;
  neue Fokussierung bzw. Seitenwechsel verhindern verspätete alte Korrekturen.
- Eingaben bleiben erhalten; Seiten-Pinch-Zoom nicht als Tastaturwechsel behandeln.
- Gezielte Offline-Regressionen und Chromium-Layoutprüfung; keine Installation.

### 1.5.8 – 27. September 2026

- Footer ausschließlich auf der Kontaktseite ersatzlos entfernt, unabhängig von
  Bildschirmhöhe/Tastatur. Auch dessen bisheriger Platz wird freigegeben.
- Äußerer Check-in-Rahmen gegen Dokument-Scrollen fixiert, mit sichtbarer
  Tastaturhöhe und Safari-Viewport-Versatz; interne Eingaben bleiben erreichbar.
- Fachliche Abläufe unverändert; eigenes Installerpaket.

### 1.5.7 – 27. September 2026

- Kontaktspalten getauscht, eigene Telefontastatur, feste obere Überschriftenzone.
- Doppelt hohes Unterschriftsfeld mit unverzerrter PDF-Wiedergabe.
- Aktueller E-Mail-Benachrichtigungsstatus und konfigurierbare SMS-Pins nach PDF-Nachweis.
- Verschlüsselte Wiederherstellungsinformationen auch für Teilfehler der Einwilligungen.
- Offline-Funktions-, native DTO-, PDF- und Browserprüfung; keine Installation/Live-Schreibtests.

### 1.5.6 – 27. September 2026

- Kompakte Kontaktseite mit Adressblock links, Eingabefeldern untereinander und
  Hauptschaltflächen rechts; keine separate Adressbestätigung mehr.
- Zusätzliche Kontakte in Gruppen, ohne Eingaben oder bestehende Nummern zu verlieren.
- Kameravorschau und seitlicher Linsenpfeil unabhängig von Meldungen mittig fixiert.
- Datenschutz-Beschriftung und gemeinsame Homescreen-/Standalone-Einstellungen.
- Offline-Funktionsprüfungen und lokale Chromium-Layoutprüfung; keine Installation.

### 1.5.5 – 27. September 2026

- Zusätzliche Vollfeldliste entfernt; ausschließlich normale fragebogenpi-Berichtsausgabe.
- Keine automatisch ergänzten Leerangaben, leeren Rubriken oder doppelten Befunde.
- Herkunftskopf, angegebene Körpermaße, Formular-Auswertungen und getrennte
  Körpermaß-/Allergiespeicherung bleiben erhalten.
- Offline-Regression des gemeldeten Beispiels und unveränderter nachgelagerter Speicherung.
- Eigenes Installerpaket; keine Änderung bestehender Akteneinträge oder Praxisvorlagen.

### 1.5.4 – 27. September 2026

- Aktuelle Patientenrevision unmittelbar vor Foto-Speicherung abrufen.
- Datenschutz für alle Patienten ohne ausreichend versioniertes PDF vor der Anamnese.
- Fingerunterschrift und optionale E-Mail-/SMS-Einwilligungen, lokale PDF-Erzeugung,
  geprüfte APS-Ablage und verschlüsselte Prüfkopie bei unklarer Speicherung.
- `[privacy].minimum_version`, numerischer Vergleich und Version in PDF/Akte.
- Installer ergänzt datenschutz.yaml, PHP-TCPDF und neue TOML-Felder;
  bestehende Praxisvorlagen und Kontext-IDs bleiben erhalten.
- Basis 1.5.2: keine Übernahme der fehlerhaften 1.5.3-Kontextkombinationsprüfung.
- Fokussierte Offline-Tests, native DTO-Probe und PDF-Sichtprüfung. Keine Live-Abnahme.

### 1.5.2 – 27. September 2026

- Telefon-/E-Mail-Felder untereinander; vollständige Adressanzeige auch im Bestand.
- Native TEXT_SAVE-Ablehnungsbegründung verschlüsselt gesichert; feste Kategorien
  und Prüfkennungen am iPad, keine Rohmeldungen im Klartextlog oder in der API.
- Erfolgsprüfung bleibt strikt; keine spekulative Requeständerung, kein Schein,
  kein Retry. Praxis-Ablehnungsgrund wurde anschließend als Kontextfehlzuordnung diagnostiziert.
- Fokussierte Offline-Prüfung der Ablehnung und der erfolgreichen synthetischen
  Kette N → Kontaktdaten → Neupatientenwartezimmer. Keine Live-Abnahme.

### 1.5.1 – 27. September 2026

- Falschen `CONTACT_HIDDEN`-Abbruch behoben: Das am Bearbeiten-Endpunkt nicht
  befüllte Anzeigen-Flag `sichtbarFuerBenutzer` wird dort nicht mehr ausgewertet.
- Echte Rechteprüfungen, Patientenzuordnung, Datenstrukturprüfung und Maskierung
  unverändert; unvollständige Kontaktdaten liefern `CONTACT_SHAPE` statt einer
  irreführenden Berechtigungsmeldung.
- Regressionstest mit nativen Antworten und gesonderten Fehlerfällen ergänzt;
  bestehende Funktionstests auf `sichtbarFuerBenutzer=false` korrigiert.
- Separates Installerpaket, Versionsanzeigen und Cache-Kennungen auf 1.5.1;
  keine neuen Funktionen, Konfigurationsfelder oder T2med-Endpunkte.

### 1.5 – 15. September 2026

- Freiwillige Kontaktprüfung mit serverseitiger Maskierung, Stammdatenübernahme
  für Telefon/E-Mail und N-Protokoll; Adresskorrekturen als N-Hinweis für den Empfang.
- Lokale fragebogenpi-Variante mit gemeinsamer Engine, ana und bedingten Folgeformularen;
  Übergabe über die bestehende Sitzung, kein GDT-/PDF-Transport.
- APS-Klartext, anamnestische Körpermaße und zusätzliche Allergiespeicherung;
  Rücklesen der tatsächlich gespeicherten Datensätze statt vorweg angenommener IDs.
- Verschlüsselte Prüfkopien bei Teilübertragungen, keine automatische Wiederholung.
- TOML-Steuerung für Formulare und Kamera: Vorschauseite, Rahmenhöhe, Linsenpfeil,
  Auslöserhinweis; keine Kamera-Zoomsteuerung.
- Installer ergänzt PHP-YAML, lokale Formulare und neue TOML-Felder; vorhandene
  Konfigurationen/Formulare bleiben erhalten. Separater Upstream-Patch beigefügt.
- Gezielte Offline-Prüfungen; keine Installation und keine Live-Abnahme.

### 1.4 – 6. September 2026

- CDN-Standardport auf `16570` korrigiert; REST bleibt standardmäßig `16567`.
- Neuinstallation leitet den CDN-Port nicht mehr aus dem REST-Port ab.
- Bestehender CDN-Wert `16567` wird erst nach gezielter Installerbestätigung geändert.
- TOML-Sicherung mit 1.4-Kennung; Kommentare, eigene Ports und andere Einstellungen bleiben erhalten.
- Keine Änderung am Fotoformat oder Patientenablauf; die 1.3-Korrekturen bleiben enthalten.
- Versionsanzeigen, Cache-Kennungen, Installer und Updateanleitung auf 1.4 angehoben.

### 1.3 – 6. September 2026

- Neue Standardwerte `fuer Empfang` und „Ich wollte nur kurz meine Karte einlesen lassen :-)“.
- Gezielte, gesicherte Migration alter TOML-Standardwerte; eigene Einstellungen bleiben erhalten.
- Beide Freitexte optional, ohne leere Notiz-Ergänzungen.
- Check-in vor Foto; Abschlussmeldung bleibt sichtbar, Fotofehler von Check-in getrennt.
- Sichtbarer Fertig-Countdown, standardmäßig 15 Sekunden, mit sofortiger Rückkehr per Tippen.
- CDN-Token-Kodierung und Multipart-Metadaten an den vorhandenen T2med-Client angepasst.
- Version, Installer, Dokumentation und Browser-Cache-Kennungen auf 1.3 angehoben.

### 1.2 – 6. September 2026

- Kartenfreigabe als Void-Aufruf: erfolgreiche leere HTTP-Antworten lösen kein `REST_JSON` mehr aus.
- Große Schaltfläche „Nächste Karte“ nach Fehlern; bestehender Mitarbeiterlogin bleibt gültig.
- Gezieltes Beenden des Patientenvorgangs ohne Wiederholung oder Rücknahme von Patientenänderungen.
- Kartenfreigabe und Lesegerätesperre werden vor dem nächsten Patienten bereinigt;
  fremde aktive Sperren und neuere Vorgänge bleiben geschützt.
- REST-Fehlerdiagnose mit festen Aufrufkennungen und HTTP-Metadaten, ohne Rohantworten oder Identifikatoren.
- Eigenes Release-Verzeichnis, Versionsanzeigen und Browser-Cache-Kennungen auf 1.2 angehoben.

### 1.1 – 6. September 2026

- Klassenlader erlaubt Ziffern nach dem ersten Zeichen; `T2med` wird korrekt geladen.
- Installer prüft Release-Version, Klassen und Client-Erzeugung ohne Serverzugriff
  vor der Zugangsdatenabfrage, sowohl im Quell- als auch im Zielverzeichnis.
- CLI-Fehlerdiagnose nennt Einrichtungsschritt, Version, Typ, Datei und Zeile,
  ohne unerwartete Exception-Texte oder Stack-Argumente auszugeben.
- Fortsetzung einer abgebrochenen 1.0-Einrichtung aus dem neuen 1.1-Verzeichnis;
  gemeinsame Konfiguration und Schlüssel bleiben erhalten, alter Programmordner bleibt liegen.
- Eigene Check-in-Dienste werden am Ende neu gestartet, damit der neue Pfad aktiv wird.
- Versionsanzeigen und Browser-Cache-Kennungen auf 1.1 angehoben.

### 1.0 – 5. September 2026

Erste eigenständige PHP-Version mit TOML, gemeinsamem Mitarbeiterlogin,
iPad-Querformat-Oberfläche, Anliegen und Wartezimmernotizen, optionalem
Patientenfoto, optionalem SQL-Kartenvorlagedatum und Linux-Installer.
