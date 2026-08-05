-- Tracks optional demo listings without adding presentation-only fields to the
-- marketplace schema. The application seeds content only when
-- DEMO_SEED_ENABLED=true and uses these stable keys to make every run idempotent.
CREATE TABLE demo_seed_listings (
    seed_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    listing_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (seed_key),
    UNIQUE KEY uq_demo_seed_listings_listing (listing_id),
    CONSTRAINT fk_demo_seed_listings_listing
        FOREIGN KEY (listing_id) REFERENCES listings (listing_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
