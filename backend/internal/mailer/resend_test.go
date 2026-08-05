package mailer

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestResendClientSendsVerificationWithoutExposingKeyInBody(t *testing.T) {
	t.Parallel()
	var authorization, idempotency string
	var body map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		authorization = request.Header.Get("Authorization")
		idempotency = request.Header.Get("Idempotency-Key")
		if err := json.NewDecoder(request.Body).Decode(&body); err != nil {
			t.Fatalf("decode request: %v", err)
		}
		response.WriteHeader(http.StatusOK)
		_, _ = response.Write([]byte(`{"id":"email-id"}`))
	}))
	defer server.Close()

	client, err := NewResendClient(ResendConfig{
		BaseURL: server.URL, APIKey: "re_private", From: "LoopBuy <verify@example.com>",
		VerificationURL: "https://market.example/login/",
	}, server.Client())
	if err != nil {
		t.Fatalf("NewResendClient() error = %v", err)
	}
	if err := client.SendVerification(context.Background(), "buyer@example.com", "<Buyer>", "https://market.example/verify-email?token=secret", "verify-abc"); err != nil {
		t.Fatalf("SendVerification() error = %v", err)
	}
	if authorization != "Bearer re_private" || idempotency != "verify-abc" {
		t.Fatalf("headers authorization=%q idempotency=%q", authorization, idempotency)
	}
	encoded, _ := json.Marshal(body)
	htmlBody, _ := body["html"].(string)
	if string(encoded) == "" || contains(string(encoded), "re_private") || !contains(htmlBody, "&lt;Buyer&gt;") {
		t.Fatalf("unexpected body %s", encoded)
	}
}

func TestResendClientRejectsInsecureVerificationURL(t *testing.T) {
	t.Parallel()
	_, err := NewResendClient(ResendConfig{
		APIKey: "re_private", From: "LoopBuy <verify@example.com>",
		VerificationURL: "http://market.example/login/",
	}, &http.Client{})
	if err == nil {
		t.Fatal("non-loopback HTTP verification URL was accepted")
	}
}

func contains(value, substring string) bool {
	for index := 0; index+len(substring) <= len(value); index++ {
		if value[index:index+len(substring)] == substring {
			return true
		}
	}
	return false
}
