package ai

import (
	"net/http"
	"strings"
	"testing"
)

func TestParseAPIErrorRedactsProviderTokens(t *testing.T) {
	err := parseAPIError(
		"openai embeddings",
		http.StatusUnauthorized,
		http.Header{},
		[]byte(`{"error":{"message":"Incorrect key sk-proj-supersecretvalue123456","code":"invalid_api_key"}}`),
	)
	apiErr, ok := err.(*APIError)
	if !ok {
		t.Fatalf("error type = %T", err)
	}
	if strings.Contains(apiErr.Message, "supersecretvalue") || !strings.Contains(apiErr.Message, "REDACTED_PROVIDER_TOKEN") {
		t.Fatalf("message was not redacted: %q", apiErr.Message)
	}
}
