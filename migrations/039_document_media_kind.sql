-- Skeny dokladu totožnosti u výkupu (v3.28.0).
--
-- Doklad totožnosti je citlivý osobní údaj a NESMÍ se dostat do fotodokumentace,
-- která se tiskne a posílá klientovi e-mailem. Proto dostane příloha druh:
--   'photo'    = fotky zařízení (tisknou se, jdou do e-mailu) — dosavadní chování
--   'id_front' = přední strana dokladu totožnosti (jen interně)
--   'id_back'  = zadní strana dokladu totožnosti (jen interně)
ALTER TABLE document_media ADD COLUMN kind VARCHAR(16) NOT NULL DEFAULT 'photo';
ALTER TABLE document_media ADD INDEX idx_dm_kind (document_id, kind);
