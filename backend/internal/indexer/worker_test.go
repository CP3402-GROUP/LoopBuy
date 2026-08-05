package indexer

import (
	"encoding/json"
	"math"
	"testing"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/store"
)

func TestCachedEmbeddingRequiresMatchingInputAndValidVector(t *testing.T) {
	t.Parallel()
	encoded, err := json.Marshal([]float32{0.25, -0.5})
	if err != nil {
		t.Fatal(err)
	}
	event := store.OutboxEvent{EmbeddingInputHash: "matching", CachedEmbedding: encoded}
	vector, ok := cachedEmbedding(event, "matching", 2)
	if !ok || len(vector) != 2 || vector[0] != 0.25 || vector[1] != -0.5 {
		t.Fatalf("cachedEmbedding() = %#v, %v", vector, ok)
	}
	if _, ok := cachedEmbedding(event, "changed", 2); ok {
		t.Fatal("changed input unexpectedly reused a paid embedding")
	}
	if _, ok := cachedEmbedding(event, "matching", 3); ok {
		t.Fatal("dimension mismatch unexpectedly reused a paid embedding")
	}
}

func TestValidEmbeddingVectorRejectsNonFiniteValues(t *testing.T) {
	t.Parallel()
	if validEmbeddingVector([]float32{float32(math.Inf(1))}, 1) {
		t.Fatal("infinite embedding accepted")
	}
	if validEmbeddingVector([]float32{float32(math.NaN())}, 1) {
		t.Fatal("NaN embedding accepted")
	}
}
