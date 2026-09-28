# Konfiguration

[Zurück zur Hauptanleitung](../README.md)

## Speicherort und Bearbeitung

Die **aktive Datei auf dem Linux-Server** ist `/etc/t2med-checkin/config.toml`. Der Installer legt sie an und übernimmt darin die bei der Einrichtung gewählten Werte. Der hier beschriebene Praxisstandard ist vollständig in [config.standard.toml](config.standard.toml) festgehalten. Änderungen an dieser Dokumentationsdatei ändern keine bereits installierte Konfiguration.

Vor Änderungen eine Sicherung anlegen und dann die aktive Datei öffnen:

```bash
sudo cp -p /etc/t2med-checkin/config.toml /etc/t2med-checkin/config.toml.bak-$(date +%Y%m%d-%H%M%S)
sudo nano /etc/t2med-checkin/config.toml
```

Die Datei ist nach Abschnitten wie `[app]` oder `[privacy]` gegliedert. Texte stehen in Anführungszeichen, Zahlen ohne Anführungszeichen; `true` schaltet ein, `false` aus. `#` beginnt einen Kommentar. Vorhandene Werte im jeweiligen Abschnitt ersetzen, Abschnitte nicht doppelt anlegen. Listen von Formularen sind kommagetrennte Texte, etwa `"ana,phq2"`. Keine Passwörter in diese Datei eintragen.

Die folgenden Angaben beziehen sich auf Version 1.6.4 und die [Standardkonfiguration des Praxisaufbaus](config.standard.toml). Darin stehen der Server `10.0.83.120`, der Kartenleser `kienzlebox` sowie die Arztrollen- und Behandlungsort-IDs dieser Installation. Diese Werte bei einer anderen Praxis passend einrichten.

Die [Paketvorlage von 1.6.4](../t2med-checkin-1.6.4/config.example.toml) und die Installer-Vorschläge übernehmen diesen Praxisstandard für neue Installationen. Bestehende ausdrücklich konfigurierte Werte bleiben beim Update erhalten. Die im Praxisstandard nicht ausdrücklich gesetzten Texte `messages.next_card_button` und `messages.photo_error` ergänzt die Anwendung aus der Paketvorlage.

## Anzeige und Sitzungen: `[app]`

| Option | Bedeutung |
|---|---|
| `title` | Begrüßung auf der Startseite. |
| `timezone` | Zeitzone, normalerweise `"Europe/Berlin"`. |
| `session_hours` | Gültigkeit der Mitarbeiteranmeldung ab Login, normalerweise `12`. Werte von 1 bis 12 Stunden verwenden; Aktivität verlängert die Sitzung nicht. Ältere höhere Werte werden auf höchstens zwölf Stunden begrenzt. |
| `patient_timeout_seconds` | Zeit ohne Aktivität im normalen Patientenablauf: Vorgabe `180`, erlaubt 60–600 Sekunden. Für Datenschutz und Fragebögen gelten eigene Fristen. |
| `completion_seconds` | Zeit bis zur automatischen Rückkehr von der Abschlussseite: Vorgabe `15`, erlaubt 5–60 Sekunden. |
| `state_dir` | Ablage für Sitzungen und offene Übertragungen, normalerweise `"/var/lib/t2med-checkin"`. Vom Installer verwaltet. |
| `secret_file` | Datei mit dem Anwendungsschlüssel, normalerweise `"/etc/t2med-checkin/secret.key"`. Bestehenden Schlüssel beibehalten. |

## t2med-Verbindung: `[t2med]`

| Option | Bedeutung |
|---|---|
| `server` | IP-Adresse oder DNS-Name des t2med-Servers. |
| `rest_port`, `cdn_port` | Ports der t2med-Schnittstelle und des Bilderdienstes; Vorgaben `16567` und `16570`. Dies sind nicht die HTTPS-Ports des iPads. |
| `verify_tls` | Prüfung des t2med-Serverzertifikats; im dokumentierten Standard `false`, die Verbindung zum t2med-Server erfolgt ohne Zertifikatsprüfung. Betrifft nicht den Safari-Dialog auf dem iPad. |
| `ca_file` | Absoluter Pfad zur vertrauenswürdigen t2med-CA im PEM-Format. `""` verwendet den systemweiten Zertifikatsspeicher. |
| `timeout_seconds` | Zeitlimit pro t2med-Anfrage: Vorgabe `30`, erlaubt 5–60 Sekunden. |
| `doctor_role_id`, `treatment_location_id` | Technischer Aufrufkontext aus Arztrolle und Behandlungsort. Wird bei der Einrichtung ausgewählt; die IDs nicht durch Anzeigenamen ersetzen. |

Die HTTPS-Adresse des Check-in-Terminals wird **beim Installer** festgelegt, nicht über diese Ports. Die zugehörigen Dateien liegen ebenfalls unter `/etc/t2med-checkin/`, insbesondere `web-settings`, `apache.conf` und `tls/`; eine Adressänderung betrifft auch das Zertifikat.

## Kartenleser: `[reader]`

| Option | Bedeutung |
|---|---|
| `name` | Exakter, eindeutiger Gerätename in t2med; im dokumentierten Aufbau `"kienzlebox"`. |
| `connect_before_read` | Vor dem Lesen eine Verbindung zum Terminal anfordern; Vorgabe `false`. |
| `test_before_read` | Kartenleser vor dem Lesen über t2med testen; Vorgabe `true`. |
| `read_attempts` | Anzahl der Leseversuche: Vorgabe `3`, erlaubt 1–5. Wiederholungen erfolgen nur nach eindeutig negativem Ergebnis. |

## Wartebereiche: `[routing]` und `[categories.…]`

| Option in `[routing]` | Bedeutung |
|---|---|
| `calendar_prefix`, `waiting_prefix` | Ordnen Kalenderlisten den Wartebereichen zu: Aus `"Kalender Müller"` wird bei den Vorgaben `"Kalender "` und `"Wartezimmer "` der Bereich `"Wartezimmer Müller"`. Das Leerzeichen am Ende der Präfixe gehört dazu. |
| `add_appointment_without_case_queue` | Bei Termin ohne Schein zusätzlich in die Mitarbeiterliste aus `categories.appointment_without_case.room` aufnehmen; Vorgabe `true`. |
| `note_max_length` | Maximale Länge der kurzen Anliegenbeschreibung: Vorgabe `250`, erlaubt 1–250 Zeichen. |

Die Kategorien steuern die jeweiligen Situationen:

| Abschnitt | Situation |
|---|---|
| `[categories.waiting]` | Bekannter Patient mit Termin und Schein; Ziel ergibt sich aus der Kalenderzuordnung. |
| `[categories.new_patient]` | Als neu erkannter Patient. |
| `[categories.appointment_without_case]` | Bekannter Patient mit Termin, aber ohne Schein. |
| `[categories.with_case_fallback]` | Bekannter Patient mit Schein, aber ohne zugeordneten Termin. |
| `[categories.without_case]` | Bekannter Patient ohne Schein und ohne zugeordneten Termin. |
| `[categories.acute]` | Auswahl „akutes Problem“. |
| `[categories.card_only]` | Auswahl „nur Karte einlesen“. |
| `[categories.other]` | Auswahl „anderes Anliegen“. |

In den jeweiligen Abschnitten bedeuten `room` den **exakten Namen eines vorhandenen t2med-Wartebereichs**, `message` die Abschlussmeldung, `label` die Beschriftung einer Auswahl und `question` die gestellte Frage. Nur die in der Vorlage vorhandenen Felder bearbeiten: Beispielsweise hat `categories.waiting` kein `room`. Ein geänderter Text benennt keinen Wartebereich in t2med um.

## Patientenfoto: `[selfie]`

| Option | Bedeutung |
|---|---|
| `enabled` | Fotofunktion einschließlich Kameravorschau auf der Mitarbeiterseite; Vorgabe `true`. |
| `preview_side` | Vorschau links (`"left"`) oder rechts (`"right"`, Vorgabe). Passend zur iPad-Ausrichtung wählen. |
| `lens_arrow` | Richtung des Hinweispfeils zur Kamera: `"left"`, `"right"`, `"top"` oder `"off"`. |
| `frame_height_percent` | Größe des Gesichtsrahmens relativ zur Vorschau; Vorgabe `100`, erlaubt 40–100. Beeinflusst auch den anfänglichen Bildausschnitt. |
| `question`, `frame_text`, `capture_text`, `adjust_text` | Texte zur Fotoeinwilligung, zum Positionieren des Gesichts, zum Auslösen und zum Anpassen des Ausschnitts. |
| `output_size` | Seitenlänge des quadratischen Fotos in Pixeln: Vorgabe `800`, erlaubt 400–1200. |
| `jpeg_quality_percent` | JPEG-Qualität: Vorgabe `88`, erlaubt 60–95. Höhere Werte erzeugen größere Dateien. |

## Kontaktdaten: `[contacts]`

| Option | Bedeutung |
|---|---|
| `enabled` | Kontaktdatenprüfung ein- oder ausschalten; Vorgabe `true`. |
| `question`, `new_question` | Überschrift für bekannte bzw. neue Patienten. |
| `hint`, `address_question` | Eingabehinweis und Frage zur Postanschrift. |
| `note_code` | t2med-Karteikartenkürzel für den Änderungsvermerk; Vorgabe `"N"`. |

## Medizinische Fragebögen: `[questionnaires]`

Die integrierte Funktion arbeitet technisch mit Fragebögen, Auswertung, Folgeformularen und Ablage in t2med. **Sie wird im beschriebenen Praxisablauf nicht genutzt, weil sie sich im Usertest am Terminal nicht bewährt hat.** Im dokumentierten Standard bleibt `enabled = true`; `new_patient_forms = ""` und `existing_patient_forms = ""` sorgen dafür, dass weder für neue noch für bekannte Patienten ein medizinischer Fragebogen startet. Die Liste `allowed_forms` allein startet keine Formulare. Für Anamnesefragebögen ist das separate Tablet im Wartebereich vorgesehen.

| Option | Bedeutung |
|---|---|
| `enabled` | Medizinische Fragebogenfunktion ein- oder ausschalten; im Standard `true`, jedoch ohne Startformulare. `false` würde die Funktion vollständig deaktivieren. Die Datenschutzerklärung wird separat gesteuert. |
| `new_patient_forms`, `existing_patient_forms` | Startformulare für neue bzw. bekannte Patienten. Kommagetrennte Formular-IDs; im Standard beide `""` (keine Startformulare). |
| `allowed_forms` | Erlaubte IDs für Start- und Folgeformulare. Ein Startformular muss hier enthalten und als YAML-Datei vorhanden sein. |
| `forms_dir` | Formularordner, normalerweise `"/etc/t2med-checkin/formulare"`. **Auch die Datenschutzerklärung wird aus diesem Ordner geladen**, selbst bei deaktivierten medizinischen Fragebögen. |
| `timeout_seconds` | Zeit ohne Aktivität im Fragebogen: Vorgabe `900`, erlaubt 180–3600 Sekunden. |
| `entry_code` | Karteikartenkürzel für Fragebogenergebnisse; Vorgabe `"ana"`. |
| `allergy_code` | Kürzel für Allergieeinträge; Vorgabe `"all"`. Muss in t2med dem Fachinformationstyp Allergie entsprechen. |

## Datenschutzerklärung: `[privacy]`

| Option | Bedeutung |
|---|---|
| `enabled` | Datenschutzablauf ein- oder ausschalten; Vorgabe `true`, unabhängig von `questionnaires.enabled`. |
| `minimum_version` | Mindestversion eines bereits gespeicherten, von der Anwendung erkannten Datenschutzdokuments. Vorgabe `"1.3.4"`. Fehlt ein ausreichendes Dokument, wird die aktuelle Vorlage zur Unterschrift angezeigt. Die verfügbare Vorlage muss mindestens diese Version haben. |
| `sms_pin_allowed`, `sms_pin_denied` | Vorhandene PNG-Dateinamen im t2med-Pin-Katalog für SMS-Zustimmung bzw. Ablehnung. Vorgaben `"SMS-erlaubt.png"` und `"SMS-nicht-erlaubt.png"`. `""` deaktiviert die jeweilige Pin-Zuordnung; die Antwort bleibt im PDF dokumentiert. Zwei aktive Namen müssen verschieden sein. |
| `timeout_seconds` | Zeit ohne Aktivität beim Datenschutzformular: Vorgabe `900`, erlaubt 180–3600 Sekunden. |
| `declined_message` | Zusätzlicher Hinweis, wenn der Patient das Formular am Empfang klären möchte. |

Wie Formulartext, Praxisangaben und Version zusammenpassen, erklärt [Datenschutzvorlage anpassen](DATENSCHUTZVORLAGE.md).

## Allgemeine Texte: `[messages]`

| Option | Bedeutung |
|---|---|
| `insert_card`, `read_button`, `next_card_button` | Aufforderung zum Einstecken der Karte und Beschriftungen zum Einlesen bzw. zum nächsten Vorgang. |
| `reading`, `remove_card` | Wartehinweis beim Lesen und Erinnerung zur Kartenentnahme. |
| `reception_error` | Hinweis zum Empfang bei nicht vollständig abgeschlossenem Check-in. |
| `camera_error`, `photo_error` | Hinweise bei nicht verfügbarer Kamera bzw. nicht bestätigter Fotoübertragung. |
| `done_title` | Überschrift der Abschlussseite, beispielsweise `"Vielen Dank!"`. |

## Optionale Änderung des Kartenvorlagedatums

Diese Zusatzfunktion ist im dokumentierten Standard **eingeschaltet** und setzt das Kartenvorlagedatum direkt in der t2med-Datenbank auf **01.01.1990**. Der Zugang erfolgt über einen lokalen Datenbank-Socket (`sql.mode = "local"`, `socket_directory = "/tmp"`).

`local` setzt voraus, dass Anwendung und t2med-Datenbank auf demselben Rechner laufen; `sql.host` wird dabei nicht als entfernter Server angesprochen. Für einen separaten Check-in-Server oder Raspberry Pi `ssh` oder `tcp` passend einrichten. Soll diese Datumsänderung entfallen, im Abschnitt `[card_presentation_date]` `enabled = false` setzen.

| Option in `[card_presentation_date]` | Bedeutung |
|---|---|
| `enabled` | Zusatzfunktion einschalten; im dokumentierten Standard `true`. |
| `target_date` | Zu setzendes Datum im Format `"JJJJ-MM-TT"`; Vorgabe `"1990-01-01"`. |

Die dazugehörige Verbindung steht unter `[sql]`:

| Option | Bedeutung |
|---|---|
| `mode` | `"local"` für lokalen Datenbank-Socket (dokumentierter Standard), `"tcp"` für PostgreSQL über TLS oder `"ssh"` für das begrenzte SQL-Gateway. |
| `host`, `port` | Server für TCP und PostgreSQL-Port für `local`/`tcp`; Vorgabe für den Port `16569`. Im SSH-Modus ist `host` das SSH-Ziel. |
| `database`, `username` | Datenbank und Datenbankbenutzer für `local`/`tcp`. |
| `socket_directory` | Socket-Verzeichnis bei `local`, Vorgabe `"/tmp"`. |
| `password_file` | Pfad zu einer separaten Datenbank-Passwortdatei für `local`/`tcp`; `""` bedeutet kein übergebenes Passwort. |
| `sslmode`, `sslrootcert` | TLS-Prüfung und CA-Datei für `tcp`. Modi: `"require"`, `"verify-ca"`, `"verify-full"` (Vorgabe). Bei leerem CA-Pfad gilt der PostgreSQL-Clientstandard. |
| `ssh_user`, `ssh_port` | SSH-Benutzer und Port; Vorgaben `"root"` und `22`. |
| `ssh_key`, `ssh_known_hosts` | Absolute Pfade zu SSH-Schlüssel und Datei mit geprüften Hostschlüsseln. Vom Installer eingerichtet. |

Im SSH-Modus verwendet das Gateway seine serverseitig eingerichtete Datenbankverbindung; die TOML-Felder für Datenbankport, -benutzer und -passwort steuern dann nicht diesen Zugang.

## Änderungen prüfen und übernehmen

Änderungen außerhalb eines laufenden Patientenvorgangs vornehmen. Auf dem installierten Server prüfen (Versionsordner gegebenenfalls anpassen):

```bash
sudo -u t2checkin php /opt/t2med-checkin/1.6.4/bin/check-config.php /etc/t2med-checkin/config.toml
```

Die Prüfung kontrolliert Konfiguration, lokale Voraussetzungen und aktivierte Formulare; bei eingeschalteter SQL-Funktion zusätzlich das Datenbankschema. Sie ändert keine Patientendaten und ersetzt keinen Funktionstest der t2med-Verbindung und Benutzerrechte.

Normale TOML- und Formularänderungen werden bei nachfolgenden Anfragen eingelesen; ein Dienstneustart ist dafür nicht erforderlich. Die Homescreen-Anwendung vollständig schließen und neu öffnen, damit auch die angezeigten Texte und Optionen neu geladen werden. Nach geänderten Verbindungsdaten das Gerät erneut anmelden.
