# Offenen Datenschutzvorgang kontrolliert abschließen – 1.6.1

Nur für berechtigte Mitarbeiter. Nicht automatisch beim Einlesen, nicht im Installer.
Kein neues PDF, kein Überschreiben der E-Mail-Einwilligung und kein pauschales
Löschen offener Vorgänge. Das Werkzeug wurde mit simulierten Antworten geprüft;
es wurde bei der Release-Erstellung nicht auf dem Server ausgeführt.

## Warum PDF und E-Mail trotzdem schon gespeichert sein können

Der Ablauf speichert und bestätigt zuerst das PDF, setzt dann die E-Mail-Einwilligung,
prüft die Rückantwort und setzt erst danach den SMS-Pin. `CONSENT_VERIFY` konnte
bisher auch durch einen Unterschied in anderen, technischen DTO-Feldern entstehen.
Die Meldung allein nennt das ursprüngliche abweichende Feld nicht; der konkrete
Serverunterschied des gemeldeten Altvorgangs lässt sich daraus nicht rekonstruieren.

1.6.1 prüft Einwilligung und Patientenzuordnung strikt. Fachliche Stammdaten werden
weiter verglichen. Ausgenommen sind klar begrenzte Anzeige-/Validierungsmetadaten,
Referenzen der zum Patienten gehörenden Wertobjekte und Revisionszähler. Leere
optionale Textfelder und leere Postanschriften werden vereinheitlicht; Telefon-
und E-Mail-Listen werden wertgleich einschließlich Anzahl/Duplikaten verglichen.
Bei anderen Änderungen: `CONSENT_DETAILS_CHANGED` mit festen Bereichsnamen im
Log, ohne Werte. Vorher-/Nachher-Daten liegen nur verschlüsselt in der Prüfkopie.
Keine ungeprüfte Annahme, dass jeder Unterschied harmlos sei.

## Vorbereitung

1. Vollständiges Release 1.6.4 installieren, im neuen Ordner `sudo bash install.sh --resume`.
2. Betroffenen Vorgang am iPad mit „Nächste Karte“ beenden. Während des Abgleichs
   keine parallele Patientenbearbeitung oder erneutes Kartenlesen.
3. Seit der letzten Änderung der Prüfkopie mindestens fünf Minuten warten. Die
   neue Anwendung sperrt laufende Datenschutzschreibvorgänge zusätzlich gegen
   gleichzeitigen Konsolenabschluss. Alte noch laufende Requests zuerst beenden.
4. Die genaue Prüfkennung aus der Anzeige `PRIVACY_PENDING` verwenden. Alternativ:

```bash
sudo grep 'T2med Check-in: PRIVACY_PENDING' /var/lib/t2med-checkin/php-error.log | tail -n 3
sudo php /opt/t2med-checkin/1.6.4/bin/pending.php
```

Eine reine Liste enthält auch andere Fehlerkopien. Niemals irgendeine Kennung
stellvertretend für einen Patienten auswählen. `--show KENNUNG` zeigt sensible
Daten nur im lokalen interaktiven Mitarbeiterterminal; nicht an Support senden.

## Zuerst nur prüfen

`KENNUNG` durch die tatsächliche 32-stellige Prüfkennung ersetzen:

```bash
sudo -u t2checkin php /opt/t2med-checkin/1.6.4/bin/privacy-recover.php --check KENNUNG
```

T2med-Mitarbeitername und Passwort werden einmalig abgefragt, das Passwort
verdeckt. Zugangsdaten werden nicht gespeichert. Die TOML bestimmt Server,
Ports, Zertifikatsprüfung und Kontext.
Nur das konkrete ursprüngliche Dokument wird patientengebunden gesucht und sein
PDF gegen die verschlüsselte Kopie geprüft. Der aktuelle E-Mail-Status muss bereits
zur gespeicherten Auswahl passen. Der Pin-Name stammt aus dem damaligen Auftrag,
nicht aus einer möglicherweise inzwischen geänderten TOML.

Die lokale Ausgabe enthält Patientenzuordnung und Sollzustand. Das sind sensible
Daten; keine Pipeline/Dateiumleitung und kein Einfügen der Ausgabe in Chats.
`--check` sendet keine Schreibanfragen und ändert keine lokale Prüfkopie.

## Nach fachlichem Abgleich abschließen

Prüfen: richtiges Patientendokument, aktuelle Einwilligung, Stammdaten und kein
späterer Widerruf. Besonders wichtig bei einer alten Prüfkopie ohne vollständigen
Vorher-/Nachher-Stammdatensatz: Dessen Erhaltung kann nicht rückwirkend automatisch
nachgewiesen werden. Das Werkzeug bezeichnet diesen Fall ausdrücklich als alt.

```bash
sudo -u t2checkin php /opt/t2med-checkin/1.6.4/bin/privacy-recover.php --complete KENNUNG
```

Der Dienstbenutzer muss Eigentümer der Prüfkopie sein; normalerweise `t2checkin`.
Nicht durch Rechteänderungen an fremden Prüfkopien umgehen. Der Befehl liest erneut
und verlangt ausdrücklich `ABSCHLIESSEN KENNUNG`. Ohne diese Eingabe keine
T2med-Schreibaktion und keine Entfernung der Prüfkopie.

Danach wird nur ein fehlender SMS-Pin gesetzt. Bereits richtig gesetzte Pins werden
nicht erneut geschrieben. Falls im ursprünglichen Auftrag enthalten, werden nur
damals eindeutig markierte ältere Check-in-SMS-Pins entfernt; alte Texte und PDFs
bleiben erhalten. Fremde Pins werden nicht überschrieben. Nach vollständigem
erneutem Nachweis wird ausschließlich die zugehörige verschlüsselte Pending-Datei
entfernt. Das bestätigte Original-PDF bleibt in T2med; die entfernte Arbeitskopie
wird nicht separat aufbewahrt. Audit enthält Zeitpunkt, Prüfkennung, Ergebnis und
Hash des ausführenden Benutzernamens, keine Patientendaten oder Zugangsdaten.

Am iPad danach „Nächste Karte“ und erneut beginnen. Eine vorhandene alte
Fehleranzeige wird nicht fernbedient zurückgesetzt. Ob anschließend ein Foto
angeboten wird, hängt unverändert von `selfie.enabled`, zugänglichem Bildmodul
und fehlendem echtem Patientenbild ab.

## Wann der Abschluss bewusst angehalten wird

- PDF fehlt, stimmt nicht überein, ist doppelt oder gehört nicht eindeutig zum Patienten.
- E-Mail-Einwilligung entspricht nicht der damaligen Auswahl: kein automatisches
  Wiederherstellen einer möglicherweise inzwischen widerrufenen Einwilligung.
- Andere offene Datenschutzübertragung oder weiterer nicht im ursprünglichen
  Alt-Pin-Plan enthaltener Datenschutzbogen. Das Werkzeug entscheidet nicht selbst,
  welche Willensäußerung Vorrang hat; auch ein älterer fremder Bogen braucht manuellen Abgleich.
- Fachliche Stammdatenabweichung in einer neueren Prüfkopie, fremder/geänderter Pin,
  fehlendes Katalogsymbol, fehlende Rechte oder anderweitige Aktenänderung.
- Laufender paralleler Vorgang, veränderte/defekte Prüfkopie oder fehlendes Audit.

Bei Verbindungsfehlern nicht blind erneut schreiben. Die Kopie bleibt erhalten.
Ein später ausdrücklich gestartetes `--check` liest den tatsächlichen Zustand;
`--complete` darf bereits bestätigte Schritte überspringen. Keine automatische
Wiederholung. T2meds Pin-Endpunkt bietet keinen atomaren Änderungsvergleich:
unmittelbares Vorher-/Nachher-Lesen reduziert, beseitigt aber nicht das Risiko
paralleler externer Änderungen. Deshalb keine gleichzeitige Bearbeitung.
