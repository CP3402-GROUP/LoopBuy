-- Add a renewable claim lease so multiple API replicas cannot process the
-- same paid embedding job concurrently.
ALTER TABLE outbox_events
    ADD COLUMN claimed_at DATETIME(6) NULL AFTER next_attempt_at,
    ADD COLUMN claim_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER claimed_at,
    ADD KEY idx_outbox_events_claim (processed_at, next_attempt_at, claimed_at, event_id);

-- Cosine similarity returned by Qdrant is defined on [-1, 1]. Preserve the
-- raw score rather than rejecting a legitimate negative match.
ALTER TABLE ai_chat_sources
    DROP CHECK chk_ai_chat_sources_score,
    ADD CONSTRAINT chk_ai_chat_sources_score CHECK (
        relevance_score IS NULL OR (relevance_score >= -1 AND relevance_score <= 1)
    );
