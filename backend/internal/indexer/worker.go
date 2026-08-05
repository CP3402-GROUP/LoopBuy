package indexer

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"math"
	"strconv"
	"time"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/ai"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/store"
)

type Worker struct {
	store            *store.Store
	embedder         ai.Embedder
	vectors          ai.VectorStore
	logger           *slog.Logger
	interval         time.Duration
	embeddingModel   string
	dimensions       int
	collection       string
	vectorName       string
	openAIHourMax    int
	openAIUserDayMax int
}

type Config struct {
	Interval                 time.Duration
	EmbeddingModel           string
	Dimensions               int
	Collection               string
	VectorName               string
	OpenAIMaxRequestsHour    int
	OpenAIMaxRequestsUserDay int
}

func New(storeValue *store.Store, embedder ai.Embedder, vectors ai.VectorStore, logger *slog.Logger, config Config) *Worker {
	return &Worker{
		store: storeValue, embedder: embedder, vectors: vectors, logger: logger,
		interval: config.Interval, embeddingModel: config.EmbeddingModel, dimensions: config.Dimensions,
		collection: config.Collection, vectorName: config.VectorName,
		openAIHourMax: config.OpenAIMaxRequestsHour, openAIUserDayMax: config.OpenAIMaxRequestsUserDay,
	}
}

func (w *Worker) Run(ctx context.Context) {
	if w.embedder == nil || w.vectors == nil {
		w.logger.Warn("listing indexer disabled because embeddings or vector store is not configured")
		return
	}
	if w.interval <= 0 {
		w.interval = 2 * time.Second
	}
	if err := w.vectors.Ensure(ctx); err != nil {
		w.logger.Error("qdrant collection initialization failed", "error", err)
	}
	w.drain(ctx)
	ticker := time.NewTicker(w.interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			w.drain(ctx)
		}
	}
}

func (w *Worker) drain(ctx context.Context) {
	// One leased event per drain keeps the two-minute lease tied to the actual
	// provider call. A batch claimed up front could expire at the tail while
	// earlier embeddings are still being paid for and processed.
	events, err := w.store.ClaimOutbox(ctx, 1)
	if err != nil {
		w.logger.Error("claim outbox events", "error", err)
		return
	}
	latest := make(map[string]int, len(events))
	for index, event := range events {
		latest[event.AggregateID] = index
	}
	for index, event := range events {
		if latest[event.AggregateID] != index {
			if err := w.store.CompleteOutbox(ctx, event.EventID, event.ClaimToken); err != nil {
				w.logger.Error("coalesce outbox event", "event_id", event.EventID, "error", err)
			}
			continue
		}
		if err := w.process(ctx, event); err != nil {
			w.logger.Warn("index listing", "event_id", event.EventID, "aggregate_id", event.AggregateID, "error", err)
			if errors.Is(err, store.ErrRateLimited) {
				if deferErr := w.store.DeferOutbox(ctx, event.EventID, event.ClaimToken, 15*time.Minute, err); deferErr != nil {
					w.logger.Error("defer budget-limited outbox event", "event_id", event.EventID, "error", deferErr)
				}
				continue
			}
			if failErr := w.store.FailOutbox(ctx, event.EventID, event.ClaimToken, err); failErr != nil {
				w.logger.Error("record outbox failure", "event_id", event.EventID, "error", failErr)
			} else if event.Attempts+1 >= store.MaxOutboxAttempts {
				w.logger.Error("dead-letter outbox event", "event_id", event.EventID, "aggregate_id", event.AggregateID, "attempts", event.Attempts+1)
			}
			continue
		}
		if err := w.store.CompleteOutbox(ctx, event.EventID, event.ClaimToken); err != nil {
			w.logger.Error("complete outbox event", "event_id", event.EventID, "error", err)
		}
	}
}

func (w *Worker) process(ctx context.Context, event store.OutboxEvent) error {
	listingID, err := strconv.ParseInt(event.AggregateID, 10, 64)
	if err != nil || listingID < 1 {
		return fmt.Errorf("invalid listing aggregate id %q", event.AggregateID)
	}
	if event.EventType != "listing.upsert" && event.EventType != "listing.delete_vector" {
		return fmt.Errorf("unsupported outbox event %q", event.EventType)
	}
	// Reconcile from current MySQL state for every event. An older retried
	// delete must never remove a vector after the listing was reactivated.
	listing, err := w.store.GetListing(ctx, listingID)
	if errors.Is(err, store.ErrNotFound) {
		return w.vectors.DeleteListing(ctx, uint64(listingID))
	}
	if err != nil {
		return err
	}
	if listing.Status != "active" || listing.ModerationStatus != "approved" || listing.Category == nil || !listing.Category.IsActive {
		if err := w.vectors.DeleteListing(ctx, uint64(listingID)); err != nil {
			return err
		}
		return w.store.MarkEmbeddingRemoved(ctx, listingID)
	}
	input := store.ListingText(listing)
	digest := sha256.Sum256([]byte(w.embeddingModel + "\x00" + strconv.Itoa(w.dimensions) + "\x00" + input))
	inputHash := hex.EncodeToString(digest[:])
	current, err := w.store.EmbeddingIsCurrent(ctx, listingID, w.embeddingModel, w.dimensions, w.collection, w.vectorName, inputHash)
	if err != nil {
		return err
	}
	if current {
		return nil
	}
	vector, cacheValid := cachedEmbedding(event, inputHash, w.dimensions)
	if !cacheValid {
		if err := w.store.ReserveProviderRequest(ctx, "openai", listing.SellerID, w.openAIHourMax, w.openAIUserDayMax); err != nil {
			return err
		}
		vectors, err := w.embedder.Embed(ctx, []string{input})
		if err != nil {
			_ = w.store.MarkEmbeddingError(ctx, listingID, w.embeddingModel, w.dimensions, w.collection, w.vectorName, err)
			return err
		}
		if len(vectors) != 1 || !validEmbeddingVector(vectors[0], w.dimensions) {
			return fmt.Errorf("embedder returned an invalid vector batch of size %d", len(vectors))
		}
		vector = vectors[0]
		encoded, err := json.Marshal(vector)
		if err != nil {
			return fmt.Errorf("encode embedding cache: %w", err)
		}
		// Persist before Qdrant. If the upsert fails, the paid result is reused
		// on the next leased retry as long as the listing/model input is unchanged.
		if err := w.store.CacheOutboxEmbedding(ctx, event.EventID, event.ClaimToken, inputHash, encoded); err != nil {
			return fmt.Errorf("cache embedding result: %w", err)
		}
	}
	if err := w.vectors.UpsertListing(ctx, uint64(listingID), vector, store.ListingPayload(listing)); err != nil {
		_ = w.store.MarkEmbeddingError(ctx, listingID, w.embeddingModel, w.dimensions, w.collection, w.vectorName, err)
		return err
	}
	return w.store.MarkEmbeddingIndexed(ctx, listingID, w.embeddingModel, w.dimensions, w.collection, w.vectorName, inputHash)
}

func cachedEmbedding(event store.OutboxEvent, inputHash string, dimensions int) ([]float32, bool) {
	if event.EmbeddingInputHash != inputHash || len(event.CachedEmbedding) == 0 {
		return nil, false
	}
	var vector []float32
	if err := json.Unmarshal(event.CachedEmbedding, &vector); err != nil || !validEmbeddingVector(vector, dimensions) {
		return nil, false
	}
	return vector, true
}

func validEmbeddingVector(vector []float32, dimensions int) bool {
	if dimensions < 1 || len(vector) != dimensions {
		return false
	}
	for _, value := range vector {
		if math.IsNaN(float64(value)) || math.IsInf(float64(value), 0) {
			return false
		}
	}
	return true
}
