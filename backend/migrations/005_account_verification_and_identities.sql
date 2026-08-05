-- Existing accounts predate verification and are trusted as a one-time legacy
-- backfill. Accounts created after this migration remain unverified until they
-- consume a single-use email token or authenticate with a verified provider.
ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME(6) NULL AFTER password_hash;

UPDATE users
SET email_verified_at = created_at
WHERE status <> 'deleted' AND email_verified_at IS NULL;

CREATE TABLE auth_identities (
    identity_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_subject VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    provider_email VARCHAR(254) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (identity_id),
    UNIQUE KEY uq_auth_identities_provider_subject (provider, provider_subject),
    UNIQUE KEY uq_auth_identities_user_provider (user_id, provider),
    CONSTRAINT chk_auth_identities_provider CHECK (provider IN ('google')),
    CONSTRAINT fk_auth_identities_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE email_verification_tokens (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    consumed_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (token_hash),
    KEY idx_email_verification_user_active (user_id, consumed_at, expires_at),
    KEY idx_email_verification_expiry (expires_at),
    CONSTRAINT fk_email_verification_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
