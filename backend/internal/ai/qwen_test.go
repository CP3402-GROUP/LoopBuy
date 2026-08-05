package ai

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestQwenChatModelComplete(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			t.Errorf("method = %s, want POST", r.Method)
		}
		if r.URL.Path != "/compatible-mode/v1/chat/completions" {
			t.Errorf("path = %s", r.URL.Path)
		}
		if got := r.Header.Get("Authorization"); got != "Bearer dashscope-key" {
			t.Errorf("Authorization = %q", got)
		}

		var request map[string]any
		if err := json.NewDecoder(r.Body).Decode(&request); err != nil {
			t.Fatalf("decode request: %v", err)
		}
		if request["model"] != "qwen-test" {
			t.Errorf("model = %#v", request["model"])
		}
		if request["temperature"] != 0.25 {
			t.Errorf("temperature = %#v", request["temperature"])
		}
		if request["max_completion_tokens"] != float64(512) {
			t.Errorf("max_completion_tokens = %#v", request["max_completion_tokens"])
		}
		if request["enable_thinking"] != false {
			t.Errorf("enable_thinking = %#v", request["enable_thinking"])
		}
		if _, exists := request["max_tokens"]; exists {
			t.Error("deprecated max_tokens must not be sent")
		}
		messages, ok := request["messages"].([]any)
		if !ok || len(messages) != 2 {
			t.Fatalf("messages = %#v", request["messages"])
		}

		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{
			"id":"chatcmpl-1",
			"object":"chat.completion",
			"model":"qwen-test",
			"choices":[{
				"index":0,
				"message":{"role":"assistant","content":"Found two listings.","reasoning_content":"checked filters"},
				"finish_reason":"stop"
			}],
			"usage":{
				"prompt_tokens":20,
				"completion_tokens":5,
				"total_tokens":25,
				"prompt_tokens_details":{"cached_tokens":4}
			}
		}`))
	}))
	defer server.Close()

	temperature := 0.25
	enableThinking := false
	model, err := NewQwenChatModel(QwenChatConfig{
		BaseURL:             server.URL + "/compatible-mode/v1",
		APIKey:              "dashscope-key",
		Model:               "qwen-test",
		Temperature:         &temperature,
		MaxCompletionTokens: 512,
		EnableThinking:      &enableThinking,
	}, &http.Client{Timeout: time.Second})
	if err != nil {
		t.Fatalf("NewQwenChatModel: %v", err)
	}

	result, err := model.Complete(t.Context(), "Use only context.", "Find a laptop.")
	if err != nil {
		t.Fatalf("Complete: %v", err)
	}
	if result.ID != "chatcmpl-1" || result.Model != "qwen-test" ||
		result.Content != "Found two listings." || result.ReasoningContent != "checked filters" ||
		result.FinishReason != "stop" {
		t.Fatalf("result = %#v", result)
	}
	if result.Usage != (TokenUsage{PromptTokens: 20, CompletionTokens: 5, TotalTokens: 25, CachedTokens: 4}) {
		t.Fatalf("usage = %#v", result.Usage)
	}
}

func TestQwenChatModelParsesDashScopeError(t *testing.T) {
	t.Parallel()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		_, _ = w.Write([]byte(`{"code":"InvalidApiKey","message":"invalid key","request_id":"dash-1"}`))
	}))
	defer server.Close()

	model, err := NewQwenChatModel(QwenChatConfig{
		BaseURL: server.URL,
		APIKey:  "bad-key",
		Model:   "qwen-test",
	}, &http.Client{Timeout: time.Second})
	if err != nil {
		t.Fatalf("NewQwenChatModel: %v", err)
	}

	_, err = model.Complete(t.Context(), "system", "user")
	var apiErr *APIError
	if !errors.As(err, &apiErr) {
		t.Fatalf("error = %v, want *APIError", err)
	}
	if apiErr.StatusCode != http.StatusUnauthorized || apiErr.Code != "InvalidApiKey" ||
		apiErr.Message != "invalid key" || apiErr.RequestID != "dash-1" {
		t.Fatalf("APIError = %#v", apiErr)
	}
}
