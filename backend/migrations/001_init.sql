-- LoopBuy backend schema for MySQL 8.4.
-- The database itself and application users are provisioned by infrastructure.

CREATE TABLE users (
    user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    CONSTRAINT chk_users_username_not_blank CHECK (CHAR_LENGTH(TRIM(username)) > 0),
    CONSTRAINT chk_users_email_not_blank CHECK (CHAR_LENGTH(TRIM(email)) > 0),
    CONSTRAINT chk_users_role CHECK (role IN ('user', 'moderator', 'admin')),
    CONSTRAINT chk_users_status CHECK (status IN ('active', 'suspended', 'deleted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE user_profiles (
    user_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(100) NULL,
    phone VARCHAR(32) NULL,
    bio TEXT NULL,
    profile_image VARCHAR(1024) NULL,
    location VARCHAR(120) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_profiles_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE categories (
    category_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_categories_name (name),
    UNIQUE KEY uq_categories_slug (slug),
    CONSTRAINT chk_categories_name_not_blank CHECK (CHAR_LENGTH(TRIM(name)) > 0),
    CONSTRAINT chk_categories_slug_not_blank CHECK (CHAR_LENGTH(TRIM(slug)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE listings (
    listing_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    brand VARCHAR(100) NULL,
    price DECIMAL(10, 2) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'SGD',
    item_condition VARCHAR(30) NOT NULL DEFAULT 'good',
    location VARCHAR(120) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    moderation_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    scam_score DECIMAL(5, 4) NULL,
    scam_label VARCHAR(32) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (listing_id),
    KEY idx_listings_seller_status_created (seller_id, status, created_at, listing_id),
    KEY idx_listings_browse (status, moderation_status, category_id, created_at, listing_id),
    KEY idx_listings_price (status, moderation_status, price, listing_id),
    KEY idx_listings_condition (status, moderation_status, item_condition, listing_id),
    FULLTEXT KEY ftx_listings_search (title, description, brand),
    CONSTRAINT chk_listings_title_not_blank CHECK (CHAR_LENGTH(TRIM(title)) > 0),
    CONSTRAINT chk_listings_price_nonnegative CHECK (price >= 0),
    CONSTRAINT chk_listings_currency_length CHECK (CHAR_LENGTH(currency) = 3),
    CONSTRAINT chk_listings_condition CHECK (item_condition IN ('new', 'like_new', 'good', 'fair')),
    CONSTRAINT chk_listings_status CHECK (status IN ('draft', 'under_review', 'active', 'reserved', 'sold', 'archived', 'deleted')),
    CONSTRAINT chk_listings_moderation CHECK (moderation_status IN ('pending', 'approved', 'rejected', 'review', 'unavailable')),
    CONSTRAINT chk_listings_scam_score CHECK (scam_score IS NULL OR (scam_score >= 0 AND scam_score <= 1)),
    CONSTRAINT fk_listings_seller
        FOREIGN KEY (seller_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_listings_category
        FOREIGN KEY (category_id) REFERENCES categories (category_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE listing_images (
    image_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id BIGINT UNSIGNED NOT NULL,
    image_url VARCHAR(1024) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (image_id),
    UNIQUE KEY uq_listing_images_order (listing_id, sort_order),
    KEY idx_listing_images_primary (listing_id, is_primary, sort_order),
    CONSTRAINT chk_listing_images_url_not_blank CHECK (CHAR_LENGTH(TRIM(image_url)) > 0),
    CONSTRAINT fk_listing_images_listing
        FOREIGN KEY (listing_id) REFERENCES listings (listing_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE favourites (
    user_id BIGINT UNSIGNED NOT NULL,
    listing_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id, listing_id),
    KEY idx_favourites_listing_created (listing_id, created_at),
    CONSTRAINT fk_favourites_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_favourites_listing
        FOREIGN KEY (listing_id) REFERENCES listings (listing_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE carts (
    cart_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (cart_id),
    UNIQUE KEY uq_carts_user (user_id),
    CONSTRAINT fk_carts_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE cart_items (
    cart_id BIGINT UNSIGNED NOT NULL,
    listing_id BIGINT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (cart_id, listing_id),
    KEY idx_cart_items_listing (listing_id),
    CONSTRAINT chk_cart_items_quantity CHECK (quantity > 0),
    CONSTRAINT fk_cart_items_cart
        FOREIGN KEY (cart_id) REFERENCES carts (cart_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_listing
        FOREIGN KEY (listing_id) REFERENCES listings (listing_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE conversations (
    conversation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id BIGINT UNSIGNED NOT NULL,
    buyer_id BIGINT UNSIGNED NOT NULL,
    seller_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (conversation_id),
    UNIQUE KEY uq_conversations_listing_participants (listing_id, buyer_id, seller_id),
    KEY idx_conversations_listing_created (listing_id, created_at, conversation_id),
    KEY idx_conversations_buyer_updated (buyer_id, updated_at, conversation_id),
    KEY idx_conversations_seller_updated (seller_id, updated_at, conversation_id),
    CONSTRAINT fk_conversations_listing
        FOREIGN KEY (listing_id) REFERENCES listings (listing_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_conversations_buyer
        FOREIGN KEY (buyer_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_conversations_seller
        FOREIGN KEY (seller_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE conversation_members (
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'member',
    joined_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    left_at DATETIME(6) NULL,
    last_read_message_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (conversation_id, user_id),
    KEY idx_conversation_members_user (user_id, conversation_id),
    CONSTRAINT chk_conversation_members_role CHECK (role IN ('buyer', 'seller', 'member')),
    CONSTRAINT fk_conversation_members_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations (conversation_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_conversation_members_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE messages (
    message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    client_message_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    message_text TEXT NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (message_id),
    UNIQUE KEY uq_messages_sender_client (sender_id, client_message_id),
    KEY idx_messages_conversation_cursor (conversation_id, message_id),
    CONSTRAINT chk_messages_text_not_blank CHECK (CHAR_LENGTH(TRIM(message_text)) > 0),
    CONSTRAINT fk_messages_member
        FOREIGN KEY (conversation_id, sender_id)
        REFERENCES conversation_members (conversation_id, user_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE refresh_tokens (
    token_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    revoked_at DATETIME(6) NULL,
    replaced_by_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (token_id),
    UNIQUE KEY uq_refresh_tokens_hash (token_hash),
    KEY idx_refresh_tokens_user_active (user_id, revoked_at, expires_at),
    KEY idx_refresh_tokens_expiry (expires_at),
    CONSTRAINT fk_refresh_tokens_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE listing_interactions (
    interaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    listing_id BIGINT UNSIGNED NOT NULL,
    interaction_type VARCHAR(32) NOT NULL,
    metadata JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (interaction_id),
    KEY idx_listing_interactions_user_time (user_id, created_at, interaction_id),
    KEY idx_listing_interactions_listing_type_time (listing_id, interaction_type, created_at),
    CONSTRAINT chk_listing_interactions_type CHECK (
        interaction_type IN ('impression', 'view', 'click', 'favourite', 'unfavourite', 'cart_add', 'cart_remove', 'message')
    ),
    CONSTRAINT fk_listing_interactions_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_listing_interactions_listing
        FOREIGN KEY (listing_id) REFERENCES listings (listing_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ai_chat_sessions (
    session_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (session_id),
    KEY idx_ai_chat_sessions_user_updated (user_id, updated_at, session_id),
    CONSTRAINT fk_ai_chat_sessions_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ai_chat_messages (
    message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(20) NOT NULL,
    content LONGTEXT NOT NULL,
    model VARCHAR(100) NULL,
    prompt_tokens INT UNSIGNED NULL,
    completion_tokens INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (message_id),
    KEY idx_ai_chat_messages_session_cursor (session_id, message_id),
    CONSTRAINT chk_ai_chat_messages_role CHECK (role IN ('system', 'user', 'assistant', 'tool')),
    CONSTRAINT chk_ai_chat_messages_content_not_blank CHECK (CHAR_LENGTH(TRIM(content)) > 0),
    CONSTRAINT fk_ai_chat_messages_session
        FOREIGN KEY (session_id) REFERENCES ai_chat_sessions (session_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE ai_chat_sources (
    source_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id BIGINT UNSIGNED NOT NULL,
    listing_id BIGINT UNSIGNED NULL,
    rank_position INT UNSIGNED NOT NULL,
    relevance_score DECIMAL(7, 6) NULL,
    source_title VARCHAR(255) NULL,
    source_url VARCHAR(1024) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (source_id),
    UNIQUE KEY uq_ai_chat_sources_rank (message_id, rank_position),
    KEY idx_ai_chat_sources_listing (listing_id),
    CONSTRAINT chk_ai_chat_sources_rank CHECK (rank_position > 0),
    CONSTRAINT chk_ai_chat_sources_score CHECK (
        relevance_score IS NULL OR (relevance_score >= 0 AND relevance_score <= 1)
    ),
    CONSTRAINT fk_ai_chat_sources_message
        FOREIGN KEY (message_id) REFERENCES ai_chat_messages (message_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_ai_chat_sources_listing
        FOREIGN KEY (listing_id) REFERENCES listings (listing_id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE scam_assessments (
    assessment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id BIGINT UNSIGNED NOT NULL,
    model_name VARCHAR(100) NOT NULL DEFAULT 'scam-text-classifier',
    model_version VARCHAR(100) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    score DECIMAL(5, 4) NULL,
    label VARCHAR(32) NULL,
    reasons JSON NULL,
    content_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    error_message TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (assessment_id),
    KEY idx_scam_assessments_listing_created (listing_id, created_at, assessment_id),
    KEY idx_scam_assessments_status_created (status, created_at, assessment_id),
    UNIQUE KEY uq_scam_assessments_content_model (listing_id, content_hash, model_name, model_version),
    CONSTRAINT chk_scam_assessments_status CHECK (status IN ('queued', 'running', 'completed', 'failed')),
    CONSTRAINT chk_scam_assessments_score CHECK (score IS NULL OR (score >= 0 AND score <= 1)),
    CONSTRAINT fk_scam_assessments_listing
        FOREIGN KEY (listing_id) REFERENCES listings (listing_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE listing_embedding_state (
    listing_id BIGINT UNSIGNED NOT NULL,
    embedding_model VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    dimensions INT UNSIGNED NOT NULL,
    collection_name VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    vector_name VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    indexed_at DATETIME(6) NULL,
    last_error TEXT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (listing_id),
    CONSTRAINT chk_listing_embedding_dimensions CHECK (dimensions > 0),
    CONSTRAINT fk_listing_embedding_state_listing
        FOREIGN KEY (listing_id) REFERENCES listings (listing_id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE outbox_events (
    event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    aggregate_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload JSON NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    processed_at DATETIME(6) NULL,
    last_error TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (event_id),
    KEY idx_outbox_events_pending (processed_at, next_attempt_at, event_id),
    KEY idx_outbox_events_aggregate (aggregate_id, event_id),
    CONSTRAINT chk_outbox_events_type_not_blank CHECK (CHAR_LENGTH(TRIM(event_type)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
