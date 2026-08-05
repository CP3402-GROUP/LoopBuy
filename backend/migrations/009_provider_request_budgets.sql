-- Persistent multi-replica provider budgets bound OpenAI/Qwen spend. Global
-- hourly and per-user daily rows are reserved atomically before paid calls.
-- The content hash lets superseded/duplicate outbox events reuse the currently
-- indexed vector without purchasing the same embedding again.
ALTER TABLE listing_embedding_state
    ADD COLUMN content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER vector_name;

CREATE TABLE provider_request_budgets (
    provider_scope VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    subject_id BIGINT UNSIGNED NOT NULL,
    window_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start DATETIME(6) NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (provider_scope, subject_type, subject_id, window_kind, window_start),
    KEY idx_provider_request_budget_cleanup (window_start),
    CONSTRAINT chk_provider_request_budget_subject CHECK (subject_type IN ('global', 'user')),
    CONSTRAINT chk_provider_request_budget_window CHECK (window_kind IN ('hour', 'day'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
