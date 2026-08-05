package ai

import (
	"context"
	"fmt"
	"net/http"
	"strings"
)

const openAIEmbeddingsPath = "/v1/embeddings"

// OpenAIEmbedderConfig configures the OpenAI embeddings REST client.
type OpenAIEmbedderConfig struct {
	BaseURL    string
	APIKey     string
	Model      string
	Dimensions int
}

// OpenAIEmbedder implements Embedder with OpenAI's embeddings endpoint.
type OpenAIEmbedder struct {
	rest       *restClient
	model      string
	dimensions int
}

var _ Embedder = (*OpenAIEmbedder)(nil)

// NewOpenAIEmbedder constructs an embeddings client. A zero Dimensions value
// omits the optional dimensions field and accepts the model's native size.
func NewOpenAIEmbedder(config OpenAIEmbedderConfig, client *http.Client) (*OpenAIEmbedder, error) {
	config.APIKey = strings.TrimSpace(config.APIKey)
	config.Model = strings.TrimSpace(config.Model)
	if config.APIKey == "" {
		return nil, fmt.Errorf("openai embeddings: API key is required")
	}
	if config.Model == "" {
		return nil, fmt.Errorf("openai embeddings: model is required")
	}
	if config.Dimensions < 0 {
		return nil, fmt.Errorf("openai embeddings: dimensions must not be negative")
	}

	rest, err := newRESTClient(
		"openai embeddings",
		config.BaseURL,
		client,
		"Authorization",
		"Bearer "+config.APIKey,
	)
	if err != nil {
		return nil, err
	}

	return &OpenAIEmbedder{
		rest:       rest,
		model:      config.Model,
		dimensions: config.Dimensions,
	}, nil
}

type openAIEmbeddingRequest struct {
	Model          string   `json:"model"`
	Input          []string `json:"input"`
	EncodingFormat string   `json:"encoding_format"`
	Dimensions     int      `json:"dimensions,omitempty"`
}

type openAIEmbeddingResponse struct {
	Object string `json:"object"`
	Model  string `json:"model"`
	Data   []struct {
		Object    string    `json:"object"`
		Index     int       `json:"index"`
		Embedding []float32 `json:"embedding"`
	} `json:"data"`
	Usage struct {
		PromptTokens int `json:"prompt_tokens"`
		TotalTokens  int `json:"total_tokens"`
	} `json:"usage"`
}

// Embed returns embeddings ordered to match inputs, even if the provider's
// data array is not ordered by index.
func (e *OpenAIEmbedder) Embed(ctx context.Context, inputs []string) ([][]float32, error) {
	if len(inputs) == 0 {
		return [][]float32{}, nil
	}
	for i, input := range inputs {
		if strings.TrimSpace(input) == "" {
			return nil, fmt.Errorf("openai embeddings: input %d is empty", i)
		}
	}

	request := openAIEmbeddingRequest{
		Model:          e.model,
		Input:          inputs,
		EncodingFormat: "float",
		Dimensions:     e.dimensions,
	}
	var response openAIEmbeddingResponse
	if err := e.rest.doJSON(ctx, http.MethodPost, openAIEmbeddingsPath, nil, request, &response); err != nil {
		return nil, err
	}
	if response.Object != "list" {
		return nil, fmt.Errorf("openai embeddings: unexpected object %q", response.Object)
	}
	if response.Model == "" {
		return nil, fmt.Errorf("openai embeddings: response model is empty")
	}
	if len(response.Data) != len(inputs) {
		return nil, fmt.Errorf("openai embeddings: got %d vectors for %d inputs", len(response.Data), len(inputs))
	}

	result := make([][]float32, len(inputs))
	seen := make([]bool, len(inputs))
	expectedDimensions := e.dimensions
	for _, item := range response.Data {
		if item.Object != "embedding" {
			return nil, fmt.Errorf("openai embeddings: unexpected data object %q", item.Object)
		}
		if item.Index < 0 || item.Index >= len(inputs) {
			return nil, fmt.Errorf("openai embeddings: response index %d is out of range", item.Index)
		}
		if seen[item.Index] {
			return nil, fmt.Errorf("openai embeddings: duplicate response index %d", item.Index)
		}
		if len(item.Embedding) == 0 {
			return nil, fmt.Errorf("openai embeddings: vector %d is empty", item.Index)
		}
		if expectedDimensions == 0 {
			expectedDimensions = len(item.Embedding)
		}
		if len(item.Embedding) != expectedDimensions {
			return nil, fmt.Errorf(
				"openai embeddings: vector %d has %d dimensions, expected %d",
				item.Index,
				len(item.Embedding),
				expectedDimensions,
			)
		}
		seen[item.Index] = true
		result[item.Index] = item.Embedding
	}

	return result, nil
}
