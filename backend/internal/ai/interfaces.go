package ai

import "context"

// Embedder converts input texts into dense vectors in the same order.
type Embedder interface {
	Embed(ctx context.Context, inputs []string) ([][]float32, error)
}

// VectorStore stores and searches listing vectors.
type VectorStore interface {
	Ensure(ctx context.Context) error
	Ready(ctx context.Context) error
	UpsertListing(ctx context.Context, id uint64, vector []float32, payload map[string]any) error
	QueryListings(ctx context.Context, vector []float32, limit int, filters map[string]any) ([]SearchResult, error)
	DeleteListing(ctx context.Context, id uint64) error
}

// ChatModel completes one system/user exchange.
type ChatModel interface {
	Complete(ctx context.Context, systemPrompt, userPrompt string) (ChatResult, error)
}

// SearchResult is a scored listing returned by the vector store.
type SearchResult struct {
	ID      uint64
	Score   float32
	Payload map[string]any
}

// TokenUsage contains provider-reported token counts.
type TokenUsage struct {
	PromptTokens     int `json:"prompt_tokens"`
	CompletionTokens int `json:"completion_tokens"`
	TotalTokens      int `json:"total_tokens"`
	CachedTokens     int `json:"cached_tokens,omitempty"`
}

// ChatResult is the normalized result of a chat completion.
type ChatResult struct {
	ID               string
	Model            string
	Content          string
	ReasoningContent string
	FinishReason     string
	Usage            TokenUsage
}
