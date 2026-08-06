-- Persist whether the transparent lexical rules found concrete scam language.
-- NULL is deliberately reserved for unavailable or contract-invalid provider
-- results, so those assessments can never qualify for automatic publication.
ALTER TABLE scam_assessments
    ADD COLUMN risk_signal_count SMALLINT UNSIGNED NULL AFTER reasons,
    ADD CONSTRAINT chk_scam_assessments_risk_signal_count CHECK (
        risk_signal_count IS NULL OR risk_signal_count <= 5
    );
