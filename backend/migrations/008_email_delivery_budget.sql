-- A shared provider budget prevents multiple API replicas or distributed
-- clients from turning verification mail into an unbounded cost/spam relay.
CREATE TABLE email_delivery_budgets (
    scope VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start DATETIME(6) NOT NULL,
    delivery_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (scope, window_start),
    CONSTRAINT chk_email_delivery_budget_count CHECK (delivery_count >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
