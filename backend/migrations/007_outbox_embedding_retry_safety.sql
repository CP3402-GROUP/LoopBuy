-- Keep a paid embedding attached to its leased event so a transient Qdrant
-- failure does not purchase the same embedding again on every retry.
-- Permanently failed events are dead-lettered after a bounded number of tries.
ALTER TABLE outbox_events
    ADD COLUMN embedding_input_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER claim_token,
    ADD COLUMN cached_embedding MEDIUMBLOB NULL AFTER embedding_input_hash,
    ADD COLUMN dead_lettered_at DATETIME(6) NULL AFTER processed_at,
    ADD KEY idx_outbox_events_retry (processed_at, dead_lettered_at, next_attempt_at, claimed_at, event_id),
    ADD CONSTRAINT chk_outbox_embedding_cache_pair CHECK (
        (embedding_input_hash IS NULL AND cached_embedding IS NULL)
        OR (embedding_input_hash IS NOT NULL AND cached_embedding IS NOT NULL)
    );
