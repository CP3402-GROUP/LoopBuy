-- Optimistic concurrency guard for listing edits, moderation, and image changes.
ALTER TABLE listings
    ADD COLUMN revision BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER updated_at,
    ADD CONSTRAINT chk_listings_revision CHECK (revision > 0);
