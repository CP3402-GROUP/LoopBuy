package store

import (
	"context"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/model"
)

func (s *Store) CacheOutboxEmbedding(ctx context.Context, eventID int64, claimToken, inputHash string, encoded []byte) error {
	result, err := s.db.ExecContext(ctx, `
		UPDATE outbox_events SET embedding_input_hash = ?, cached_embedding = ?
		WHERE event_id = ? AND claim_token = ? AND processed_at IS NULL AND dead_lettered_at IS NULL`,
		inputHash, encoded, eventID, claimToken)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected != 1 {
		return ErrConflict
	}
	return nil
}

func (s *Store) EmbeddingIsCurrent(ctx context.Context, listingID int64, embeddingModel string, dimensions int, collection, vectorName, contentHash string) (bool, error) {
	var current bool
	err := s.db.QueryRowContext(ctx, `
		SELECT EXISTS(
			SELECT 1 FROM listing_embedding_state
			WHERE listing_id = ? AND embedding_model = ? AND dimensions = ?
			  AND collection_name = ? AND vector_name = ? AND content_hash = ?
			  AND indexed_at IS NOT NULL AND last_error IS NULL
		)`, listingID, embeddingModel, dimensions, collection, vectorName, contentHash).Scan(&current)
	return current, err
}

func (s *Store) MarkEmbeddingIndexed(ctx context.Context, listingID int64, embeddingModel string, dimensions int, collection, vectorName, contentHash string) error {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO listing_embedding_state
		(listing_id, embedding_model, dimensions, collection_name, vector_name, content_hash, indexed_at, last_error)
		VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(6), NULL)
		ON DUPLICATE KEY UPDATE embedding_model = VALUES(embedding_model), dimensions = VALUES(dimensions),
		collection_name = VALUES(collection_name), vector_name = VALUES(vector_name),
		content_hash = VALUES(content_hash), indexed_at = CURRENT_TIMESTAMP(6), last_error = NULL`,
		listingID, embeddingModel, dimensions, collection, vectorName, contentHash)
	return err
}

func (s *Store) MarkEmbeddingRemoved(ctx context.Context, listingID int64) error {
	_, err := s.db.ExecContext(ctx, `
		UPDATE listing_embedding_state
		SET content_hash = NULL, indexed_at = NULL, last_error = NULL
		WHERE listing_id = ?`, listingID)
	return err
}

func (s *Store) MarkEmbeddingError(ctx context.Context, listingID int64, embeddingModel string, dimensions int, collection, vectorName string, processError error) error {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO listing_embedding_state
		(listing_id, embedding_model, dimensions, collection_name, vector_name, last_error)
		VALUES (?, ?, ?, ?, ?, LEFT(?, 1000))
		ON DUPLICATE KEY UPDATE last_error = VALUES(last_error)`, listingID, embeddingModel, dimensions, collection, vectorName, processError.Error())
	return err
}

func ListingPayload(item model.Listing) map[string]any {
	payload := map[string]any{
		"listing_id":        item.ListingID,
		"seller_id":         item.SellerID,
		"category_id":       item.CategoryID,
		"title":             item.Title,
		"price":             item.Price,
		"currency":          item.Currency,
		"item_condition":    item.ItemCondition,
		"location":          item.Location,
		"status":            item.Status,
		"moderation_status": item.ModerationStatus,
		"created_at":        item.CreatedAt.UTC().Format("2006-01-02T15:04:05.000000Z"),
	}
	if item.Category != nil {
		payload["category_slug"] = item.Category.Slug
	}
	return payload
}
