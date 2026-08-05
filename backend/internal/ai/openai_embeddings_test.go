package ai

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"
	"time"
)

func TestOpenAIEmbedderEmbed(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			t.Errorf("method = %s, want POST", r.Method)
		}
		if r.URL.Path != openAIEmbeddingsPath {
			t.Errorf("path = %s, want %s", r.URL.Path, openAIEmbeddingsPath)
		}
		if got := r.Header.Get("Authorization"); got != "Bearer openai-key" {
			t.Errorf("Authorization = %q", got)
		}

		var request openAIEmbeddingRequest
		if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
			t.Fatalf("decode request: %v", err)
		}
		if request.Model != "text-embedding-test" {
			t.Errorf("model = %q", request.Model)
		}
		if request.EncodingFormat != "float" {
			t.Errorf("encoding_format = %q", request.EncodingFormat)
		}
		if request.Dimensions != 2 {
			t.Errorf("dimensions = %d", request.Dimensions)
		}
		if !reflect.DeepEqual(request.Input, []string{"first", "second"}) {
			t.Errorf("input = %#v", request.Input)
		}

		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{
			"object":"list",
			"model":"text-embedding-test",
			"data":[
				{"object":"embedding","index":1,"embedding":[3,4]},
				{"object":"embedding","index":0,"embedding":[1,2]}
			],
			"usage":{"prompt_tokens":2,"total_tokens":2}
		}`))
	}))
	defer server.Close()

	client := &http.Client{Timeout: time.Second}
	embedder, err := NewOpenAIEmbedder(OpenAIEmbedderConfig{
		BaseURL:    server.URL,
		APIKey:     "openai-key",
		Model:      "text-embedding-test",
		Dimensions: 2,
	}, client)
	if err != nil {
		t.Fatalf("NewOpenAIEmbedder: %v", err)
	}

	vectors, err := embedder.Embed(t.Context(), []string{"first", "second"})
	if err != nil {
		t.Fatalf("Embed: %v", err)
	}
	want := [][]float32{{1, 2}, {3, 4}}
	if !reflect.DeepEqual(vectors, want) {
		t.Fatalf("vectors = %#v, want %#v", vectors, want)
	}
}

func TestOpenAIEmbedderParsesHTTPError(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("X-Request-ID", "req-123")
		w.WriteHeader(http.StatusTooManyRequests)
		_, _ = w.Write([]byte(`{"error":{"message":"rate limited","type":"rate_limit_error","code":"rate_limit"}}`))
	}))
	defer server.Close()

	embedder, err := NewOpenAIEmbedder(OpenAIEmbedderConfig{
		BaseURL: server.URL,
		APIKey:  "openai-key",
		Model:   "embedding-model",
	}, &http.Client{Timeout: time.Second})
	if err != nil {
		t.Fatalf("NewOpenAIEmbedder: %v", err)
	}

	_, err = embedder.Embed(t.Context(), []string{"hello"})
	var apiErr *APIError
	if !errors.As(err, &apiErr) {
		t.Fatalf("error = %v, want *APIError", err)
	}
	if apiErr.StatusCode != http.StatusTooManyRequests || apiErr.Code != "rate_limit" ||
		apiErr.Message != "rate limited" || apiErr.RequestID != "req-123" {
		t.Fatalf("APIError = %#v", apiErr)
	}
}

func TestOpenAIEmbedderRejectsWrongDimensions(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{
			"object":"list",
			"model":"embedding-model",
			"data":[{"object":"embedding","index":0,"embedding":[1]}],
			"usage":{"prompt_tokens":1,"total_tokens":1}
		}`))
	}))
	defer server.Close()

	embedder, err := NewOpenAIEmbedder(OpenAIEmbedderConfig{
		BaseURL:    server.URL,
		APIKey:     "openai-key",
		Model:      "embedding-model",
		Dimensions: 2,
	}, &http.Client{Timeout: time.Second})
	if err != nil {
		t.Fatalf("NewOpenAIEmbedder: %v", err)
	}

	_, err = embedder.Embed(t.Context(), []string{"hello"})
	if err == nil || !strings.Contains(err.Error(), "has 1 dimensions, expected 2") {
		t.Fatalf("error = %v", err)
	}
}
