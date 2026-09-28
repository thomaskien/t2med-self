# SMS-Pins in t2med vor 26.11 einbinden

Die Pins kennzeichnen am gespeicherten Datenschutz-Karteikarteneintrag, ob der Patient SMS erlaubt hat. Die folgenden Schritte beschreiben die manuelle Einbindung auf dem t2med-Linux-Server für Versionen **vor 26.11**. Der Check-in-Installer installiert die Pins nicht automatisch.

## Downloads

| SMS erlaubt | SMS nicht erlaubt |
|---|---|
| [![Grüner SMS-Pin](SMS-erlaubt.png)](SMS-erlaubt.png?raw=true) | [![Roter SMS-Pin](SMS-nicht-erlaubt.png)](SMS-nicht-erlaubt.png?raw=true) |
| [SMS-erlaubt.png herunterladen](SMS-erlaubt.png?raw=true) | [SMS-nicht-erlaubt.png herunterladen](SMS-nicht-erlaubt.png?raw=true) |

- [sms-pins.zip herunterladen](sms-pins.zip): nur die beiden PNG-Dateien, jeweils 80 × 80 Pixel, zum Ergänzen des eigenen Katalogs.
- [symbole.zip herunterladen](symbole.zip): vollständiger Symbolkatalog aus dem erprobten Aufbau, Stand **27.09.2026**, mit 134 unveränderten ursprünglichen Einträgen und den beiden zusätzlichen SMS-Pins.

Auf GitHub bei einer Dateivorschau **„Download raw file“** wählen; einzelne PNGs lassen sich auch über die Bildansicht speichern. Die Dateien liegen ebenfalls im Verzeichnis `pins/` des Installationspakets.

## Einrichtung

1. **Beide PNGs herunterladen**, einzeln oder als `sms-pins.zip`. Das kleine ZIP zunächst entpacken.
2. Auf dem **t2med-Server** den gesamten Ordner **`/opt/t2med/server/pins` außerhalb dieses Ordners sichern**, einschließlich `symbole.zip` und `cdnconfig.properties`.
3. Die beiden PNGs als einzelne Dateien nach `/opt/t2med/server/pins/` kopieren. Dateinamen einschließlich Groß-/Kleinschreibung beibehalten; Eigentümer und Leserechte wie bei den vorhandenen PNGs setzen.
4. Dieselben PNGs zusätzlich in das dort vorhandene **aktuelle `symbole.zip`** aufnehmen, direkt auf der obersten Ebene des Archivs. Alle anderen Einträge erhalten. Mit installiertem Linux-Werkzeug `zip` geht das nach dem Kopieren so:

   ```bash
   cd /opt/t2med/server/pins && sudo zip symbole.zip SMS-erlaubt.png SMS-nicht-erlaubt.png
   ```

   `sms-pins.zip` ist nur ein Transportarchiv und darf nicht in `symbole.zip` umbenannt werden. Das fertige vollständige `symbole.zip` aus den Downloads kann Schritt 4 nur ersetzen, wenn der bisherige Katalog dem unten genannten Ausgangsarchiv entspricht. Bei neueren oder eigenen Symbolen stets das vorhandene Archiv ergänzen. Die lose `cdnconfig.properties` und alle übrigen Dateien unverändert lassen.
5. **Den t2med-Server neu starten**, wenn der Praxisbetrieb dadurch nicht unterbrochen wird. Danach t2med wieder öffnen und prüfen, ob beide Symbole im Pin-Katalog zur Auswahl stehen.
6. Die Zuordnung in der Check-in-Konfiguration **`/etc/t2med-checkin/config.toml`** prüfen. Diese Dateinamen sind bereits die Installationsvorgabe:

   ```toml
   [privacy]
   sms_pin_allowed = "SMS-erlaubt.png"
   sms_pin_denied = "SMS-nicht-erlaubt.png"
   ```

7. Mit einem Testpatienten eine neue Datenschutzerklärung speichern und den passenden Pin am zugehörigen Karteikarteneintrag kontrollieren. Das Check-in-System prüft vor der Übertragung, ob der konfigurierte Dateiname im t2med-Katalog verfügbar ist.

Nach t2med-Updates erneut prüfen, ob die beiden Pins noch vorhanden sind. Falls sie fehlen, die Schritte mit dem dann aktuellen Katalog wiederholen.

## Andere Symbole oder Verzicht auf Pins

Andere im t2med-Katalog vorhandene PNG-Namen können verwendet werden. Die Namen müssen für Ja und Nein verschieden sein. Ein leerer Wert (`""`) deaktiviert die jeweilige Zuordnung einschließlich der Bereinigung früherer SMS-Pins bei dieser Antwort. Für einen Betrieb ganz ohne SMS-Pins beide Werte leeren. Die E-Mail-Einwilligung wird weiterhin unabhängig davon übernommen.

## Prüfsummen

SHA-256 der beiden PNG-Dateien und des angebotenen vollständigen Symbolarchivs:

```text
c77913f547fc7be5fe6de1e71ce94c33719db3fef7d53463d286cde4557abc7a  SMS-erlaubt.png
edd72153f9fd5e4d0339242d4a2ed59c7d51502ee18119fbdda2e7edbe6177e6  SMS-nicht-erlaubt.png
28d28020fc29fe8eed54967fe6cd8ead3c5ed69dd57d685653c4279572a61112  symbole.zip
```

Das unveränderte Ausgangsarchiv vor Ergänzung der beiden Pins hatte die SHA-256-Prüfsumme `db2270d81fd595a335245aabaca8a8fe8ddecd57a95444b92b7eb6810f5c5094`. Zum Vergleich auf dem Zielserver: `sha256sum /opt/t2med/server/pins/symbole.zip`.
