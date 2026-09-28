import com.fasterxml.jackson.databind.ObjectMapper;
import de.t2med.aps.api.praxis.karteikarte.*;
import de.t2med.aps.api.praxis.verweis.*;
import de.t2med.common.persistable.api.*;

// Offline DTO serialization only. No APS service, HTTP, database or patient data.
public class NativePrivacyProbe {
    public static void main(String[] args) throws Exception {
        var mapper = new ObjectMapper();
        System.out.println("BEFUND_DOKUMENT=" + Fachinformationstyp.BEFUND_DOKUMENT.getGlobalId());
        var doc = new DokumentverweisTO("synthetic", "cdn://synthetic");
        doc.setFachinformationstyp(Fachinformationstyp.BEFUND_DOKUMENT);
        System.out.println(mapper.writeValueAsString(doc));
        System.out.println(mapper.writeValueAsString(new KarteikarteAnzeigenRequestDTO()));
        if (args.length > 0) {
            var req = mapper.readValue(System.in, DokumentverweisAktualisierenRequestDTO.class);
            if (!req.isNeuerEintrag() || req.getKontext().getBehandlungsfallRef() != null
                || req.getDokumentverweis().getFachinformationstyp() != Fachinformationstyp.BEFUND_DOKUMENT)
                throw new AssertionError("native request mismatch");
            System.out.println("Native PDF request: OK");
        }
    }
}
