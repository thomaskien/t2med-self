# Datenschutzvorlage anpassen

[Zurück zur Hauptanleitung](../README.md) · [Konfiguration](KONFIGURATION.md)

Die Datenschutzerklärung ist eine **YAML-Textdatei auf dem Linux-Server**. Dieselbe Vorlage liefert den Text für die Anzeige am iPad und für das unterschriebene PDF in t2med.

## 1. Aktive Vorlage sichern und öffnen

Der Standardpfad lautet **`/etc/t2med-checkin/formulare/datenschutz.yaml`**. Ein abweichender Formularordner wird in `/etc/t2med-checkin/config.toml` unter `[questionnaires]` mit `forms_dir` festgelegt. Dieser Ordner gilt auch bei abgeschalteter Fragebogenfunktion.

```bash
sudo cp -p /etc/t2med-checkin/formulare/datenschutz.yaml /etc/t2med-checkin/formulare/datenschutz.yaml.bak-$(date +%Y%m%d-%H%M%S)
sudo nano /etc/t2med-checkin/formulare/datenschutz.yaml
```

Die [Vorlage im Release](../t2med-checkin-1.6.4/fragebogenpi/_yaml/datenschutz.yaml) dient als Ausgangspunkt. Für die installierte Anwendung die Datei unter `/etc/` bearbeiten. Der Installer erhält bereits vorhandene Praxisvorlagen bei Updates.

Im Formularordner darf nur eine passende Datenschutzvorlage liegen. Auch `dsgv.yaml` und Namen mit numerischem Präfix wie `10-datenschutz.yaml` werden erkannt. Vorhandene eigene Dateinamen beibehalten; Sicherungskopien mit einer Endung wie `.bak-…` ablegen.

## 2. Praxisangaben und Texte bearbeiten

| Feld | Was dort angepasst wird |
|---|---|
| `meta.title` | Titel auf dem iPad und im PDF. |
| `document.heading` | Überschrift der Patienteninformation. |
| `document.intro` | Einleitender Text. |
| `document.sections` | Liste der Textabschnitte. Jeder Abschnitt hat eine Überschrift `title` und den Inhalt `text`. Hier stehen insbesondere die Praxisplatzhalter. |
| `consent.checkbox_label`, `consent.sms_checkbox_label` | Beschriftungen der freiwilligen E-Mail- und SMS-Auswahl am iPad. |
| `consent.pdf_label`, `consent.sms_pdf_label` | Zugehörige Beschriftungen der Ja/Nein-Antworten im PDF. |
| `meta.warning_notice` | Sichtbarer Hinweisbanner auf dem iPad und im PDF. Nach vollständiger Anpassung und Prüfung auf `""` setzen. |

Unter „Verantwortlicher“ alle Platzhalter wie `[Praxisname]`, `[Praxisinhaber/in]`, Anschrift und Kontaktdaten ersetzen. Auch die übrigen Aussagen müssen zur eigenen Praxis passen, etwa zum Datenschutzbeauftragten, zu Empfängern, Kommunikationswegen und der zuständigen Aufsichtsbehörde. Das Entfernen des Testhinweises allein passt den Inhalt nicht an.

Beispiel für den vorhandenen Abschnitt „Verantwortlicher“ innerhalb von `document.sections` – **nur diesen Abschnitt bearbeiten, nicht die gesamte Datei ersetzen**:

```yaml
document:
  sections:
    - title: "Verantwortlicher"
      text: |
        Verantwortlich für die Datenverarbeitung ist:

        Praxis am Park
        Dr. Erika Beispiel
        Musterstraße 12
        12345 Musterstadt
        Telefon: 01234 567890
        E-Mail: praxis@example.org
```

YAML benötigt die gezeigte Einrückung mit **Leerzeichen, nicht Tabulatoren**. Das Zeichen `|` beginnt einen mehrzeiligen Text; alle Textzeilen darunter bleiben eingerückt. Inhalte sind einfacher Text, kein HTML.

Die technischen Felder `meta.handler: "datenschutz.php"`, `meta.form_ids`, die Einwilligungs-IDs und `consent.optional: true` beibehalten. E-Mail- und SMS-Einwilligung bleiben freiwillig. `meta.pdf_title` und `meta.submit_label` aus der gemeinsamen Vorlage steuern in dieser Check-in-Version nicht den PDF-Titel bzw. die Schaltfläche.

## 3. Version und erneute Unterschrift festlegen

Bei inhaltlicher Änderung die Formularversion unter `meta.version` erhöhen, beispielsweise von `"1.3.4"` auf `"1.3.5"`. Nur numerische Versionen wie `1.3.5` verwenden, keine Zusätze wie `-test`.

Ein Ausschnitt der **bestehenden** YAML-Felder nach abgeschlossener Anpassung:

```yaml
meta:
  version: "1.3.5"
  warning_notice: ""
```

Die Version der Vorlage und die Mindestversion in der Konfiguration haben unterschiedliche Aufgaben:

- **`meta.version` in der YAML-Datei** kennzeichnet den Text, der bei einer neuen Unterschrift angezeigt und gespeichert wird.
- **`minimum_version` unter `[privacy]` in der TOML-Datei** bestimmt, welche bereits gespeicherten Dokumente noch ausreichen. Wird nur der Text oder seine Versionsnummer geändert, erzwingt das bei ausreichenden älteren Dokumenten keine erneute Unterschrift.

Soll künftig mindestens Version 1.3.5 vorliegen, zuerst die angepasste Vorlage bereitstellen und danach in der aktiven Konfiguration ändern:

```toml
[privacy]
minimum_version = "1.3.5"
```

Beim nächsten Check-in erhalten Patienten ohne ausreichendes, von der Anwendung erkanntes Dokument die aktuelle Vorlage. Vorhandene PDFs werden nicht überschrieben. Die lokale Vorlage darf nicht älter sein als die eingestellte Mindestversion; andernfalls hält die Anwendung den Datenschutzablauf an. In `minimum_version` keine Vergleichszeichen wie `>=` eintragen.

## 4. Prüfen und am iPad ansehen

Vorlage und Mindestversion außerhalb laufender Unterschriftsvorgänge ändern. Ein bereits geöffnetes Formular kann nach einer Änderung nicht mehr unverändert abgesendet werden und muss neu begonnen werden.

```bash
sudo -u t2checkin php /opt/t2med-checkin/1.6.4/bin/check-config.php /etc/t2med-checkin/config.toml
```

Die Prüfung erkennt unter anderem YAML-Fehler, fehlende Pflichtfelder, mehrere passende Vorlagen und eine zu alte Formularversion. Sie prüft nicht die inhaltliche Eignung des Textes. Bei einem verbleibenden Hinweisbanner wird eine Meldung ausgegeben.

Anschließend die Anwendung am iPad neu öffnen und den Ablauf mit einem vorgesehenen Testpatienten ohne ausreichendes Datenschutzdokument prüfen. Nach einer Testunterschrift auch das gespeicherte PDF in t2med ansehen: Praxisangaben, vollständiger Text, Version, beide Antworten und Unterschrift. Dieser Funktionstest schreibt ein Dokument in die Testpatientenakte.
