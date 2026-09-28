import com.fasterxml.jackson.databind.ObjectMapper;
import de.t2med.aps.api.praxis.patient.*;
import de.t2med.aps.api.praxis.karteikarte.*;

// DTO decoding only, using the site's APS jars. Never starts a service or accesses data.
public class NativeConsentProbe {
    public static void main(String[] args) throws Exception {
        var mapper = new ObjectMapper();
        var requests = mapper.readTree(System.in);
        int count = 0;
        for (var request : requests) {
            var email = mapper.treeToValue(request.get("email"), PatientDetailsAktualisierenRequestDTO.class);
            var pin = mapper.treeToValue(request.get("pin"), KarteieintragSymbolAendernRequestDTO.class);
            var allowed = email.getDetails().getKontaktdatenDTO().getBenachrichtigungErlaubt();
            if (allowed == null || allowed != request.get("email").get("details").get("kontaktdatenDTO").get("benachrichtigungErlaubt").asBoolean()
                || email.getKontext().getBehandlungsfallRef() != null || pin.getKontext().getBehandlungsfallRef() != null
                || email.getDetails().getPatientRef().getRevision() != 30
                || !pin.getKarteieintragRef().getObjectId().getId().equals("d".repeat(32))
                || !pin.getSymbol().equals(request.get("pin").get("symbol").asText()))
                throw new AssertionError("Native consent request mismatch");
            count++;
        }
        if(count != 4) throw new AssertionError("All four independent choices required");
        System.out.println("Native APS consent/pin request DTOs: four combinations OK");
    }
}
