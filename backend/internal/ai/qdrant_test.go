package ai

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strings"
	"testing"
	"time"
)

func TestQdrantEnsureCreatesNamedCollection(t *testing.T) {
	t.Parallel()

	var calls int
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		calls++
		if got := r.Header.Get("api-key"); got != "qdrant-key" {
			t.Errorf("api-key = %q", got)
		}
		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/collections/listings":
			w.WriteHeader(http.StatusNotFound)
			_, _ = w.Write([]byte(`{"status":{"error":"Not found"}}`))
		case r.Method == http.MethodPut && r.URL.Path == "/collections/listings":
			var request map[string]any
			if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
				t.Fatalf("decode request: %v", err)
			}
			vectors := request["vectors"].(map[string]any)
			params := vectors["listing_text_v1"].(map[string]any)
			if params["size"] != float64(2) || params["distance"] != "Cosine" {
				t.Errorf("vector params = %#v", params)
			}
			_, _ = w.Write([]byte(`{"status":"ok","result":true}`))
		default:
			t.Errorf("unexpected request: %s %s", r.Method, r.URL.String())
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer server.Close()

	store := newTestQdrantStore(t, server.URL)
	if err := store.Ensure(context.Background()); err != nil {
		t.Fatalf("Ensure: %v", err)
	}
	if calls != 2 {
		t.Fatalf("calls = %d, want 2", calls)
	}
}

func TestQdrantEnsureAddsMissingNamedVector(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/collections/listings":
			_, _ = w.Write([]byte(`{
				"status":"ok",
				"result":{"config":{"params":{"vectors":{"old_vector":{"size":2,"distance":"Cosine"}}}}}
			}`))
		case r.Method == http.MethodPut && r.URL.Path == "/collections/listings/vectors/listing_text_v1":
			if r.URL.Query().Get("wait") != "true" {
				t.Errorf("wait = %q", r.URL.Query().Get("wait"))
			}
			var request map[string]any
			if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
				t.Fatalf("decode request: %v", err)
			}
			dense := request["dense"].(map[string]any)
			if dense["size"] != float64(2) || dense["distance"] != "Cosine" {
				t.Errorf("dense = %#v", dense)
			}
			_, _ = w.Write([]byte(`{"status":"ok","result":{"status":"completed","operation_id":1}}`))
		default:
			t.Errorf("unexpected request: %s %s", r.Method, r.URL.String())
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer server.Close()

	store := newTestQdrantStore(t, server.URL)
	if err := store.Ensure(context.Background()); err != nil {
		t.Fatalf("Ensure: %v", err)
	}
}

func TestQdrantEnsureRejectsMismatchedVector(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		_, _ = w.Write([]byte(`{
			"status":"ok",
			"result":{"config":{"params":{"vectors":{"listing_text_v1":{"size":3,"distance":"Cosine"}}}}}
		}`))
	}))
	defer server.Close()

	store := newTestQdrantStore(t, server.URL)
	err := store.Ensure(context.Background())
	if err == nil || !strings.Contains(err.Error(), "has size 3, expected 2") {
		t.Fatalf("error = %v", err)
	}
}

func TestQdrantListingOperations(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if got := r.Header.Get("api-key"); got != "qdrant-key" {
			t.Errorf("api-key = %q", got)
		}
		switch {
		case r.Method == http.MethodPut && r.URL.Path == "/collections/listings/points":
			if r.URL.Query().Get("wait") != "true" {
				t.Errorf("wait = %q", r.URL.Query().Get("wait"))
			}
			var request map[string]any
			if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
				t.Fatalf("decode upsert: %v", err)
			}
			points := request["points"].([]any)
			point := points[0].(map[string]any)
			if point["id"] != float64(42) {
				t.Errorf("point ID = %#v", point["id"])
			}
			vectors := point["vector"].(map[string]any)
			if !reflect.DeepEqual(vectors["listing_text_v1"], []any{float64(0.1), float64(0.2)}) {
				t.Errorf("vector = %#v", vectors)
			}
			_, _ = w.Write([]byte(`{"status":"ok","result":{"status":"completed","operation_id":1}}`))

		case r.Method == http.MethodPost && r.URL.Path == "/collections/listings/points/query":
			var request map[string]any
			if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
				t.Fatalf("decode query: %v", err)
			}
			if request["using"] != "listing_text_v1" || request["limit"] != float64(5) ||
				request["with_payload"] != true || request["with_vector"] != false {
				t.Errorf("query request = %#v", request)
			}
			must := request["filter"].(map[string]any)["must"].([]any)
			first := must[0].(map[string]any)
			second := must[1].(map[string]any)
			if first["key"] != "category_id" || second["key"] != "status" {
				t.Errorf("filter order = %#v", must)
			}
			_, _ = w.Write([]byte(`{
				"status":"ok",
				"result":{"points":[{"id":42,"score":0.91,"payload":{"status":"active"}}]}
			}`))

		case r.Method == http.MethodPost && r.URL.Path == "/collections/listings/points/delete":
			if r.URL.Query().Get("wait") != "true" {
				t.Errorf("wait = %q", r.URL.Query().Get("wait"))
			}
			var request map[string]any
			if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
				t.Fatalf("decode delete: %v", err)
			}
			if !reflect.DeepEqual(request["points"], []any{float64(42)}) {
				t.Errorf("delete points = %#v", request["points"])
			}
			_, _ = w.Write([]byte(`{"status":"ok","result":{"status":"acknowledged","operation_id":2}}`))

		default:
			t.Errorf("unexpected request: %s %s", r.Method, r.URL.String())
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer server.Close()

	store := newTestQdrantStore(t, server.URL)
	ctx := context.Background()
	if err := store.UpsertListing(ctx, 42, []float32{0.1, 0.2}, map[string]any{"status": "active"}); err != nil {
		t.Fatalf("UpsertListing: %v", err)
	}
	results, err := store.QueryListings(ctx, []float32{0.3, 0.4}, 5, map[string]any{
		"status":      "active",
		"category_id": []int{2, 3},
	})
	if err != nil {
		t.Fatalf("QueryListings: %v", err)
	}
	want := []SearchResult{{ID: 42, Score: 0.91, Payload: map[string]any{"status": "active"}}}
	if !reflect.DeepEqual(results, want) {
		t.Fatalf("results = %#v, want %#v", results, want)
	}
	if err := store.DeleteListing(ctx, 42); err != nil {
		t.Fatalf("DeleteListing: %v", err)
	}
}

func TestQdrantParsesApplicationError(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusBadRequest)
		_, _ = w.Write([]byte(`{"status":{"error":"Wrong vector dimension"},"time":0.001}`))
	}))
	defer server.Close()

	store := newTestQdrantStore(t, server.URL)
	err := store.UpsertListing(context.Background(), 42, []float32{0.1, 0.2}, nil)
	var apiErr *APIError
	if !errors.As(err, &apiErr) {
		t.Fatalf("error = %v, want *APIError", err)
	}
	if apiErr.StatusCode != http.StatusBadRequest || apiErr.Message != "Wrong vector dimension" {
		t.Fatalf("APIError = %#v", apiErr)
	}
}

func newTestQdrantStore(t *testing.T, baseURL string) *QdrantVectorStore {
	t.Helper()
	store, err := NewQdrantVectorStore(QdrantConfig{
		BaseURL:    baseURL,
		APIKey:     "qdrant-key",
		Collection: "listings",
		VectorName: "listing_text_v1",
		VectorSize: 2,
		Distance:   "Cosine",
	}, &http.Client{Timeout: time.Second})
	if err != nil {
		t.Fatalf("NewQdrantVectorStore: %v", err)
	}
	return store
}
