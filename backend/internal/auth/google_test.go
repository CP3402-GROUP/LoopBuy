package auth

import (
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"google.golang.org/api/idtoken"
)

func TestGoogleExchangeUsesPKCEAndVerifiedClaims(t *testing.T) {
	t.Parallel()
	verifier := strings.Repeat("v", 43)
	var received urlValues
	server := httptest.NewServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		body, _ := io.ReadAll(request.Body)
		received = parseValues(string(body))
		response.Header().Set("Content-Type", "application/json")
		_, _ = response.Write([]byte(`{"id_token":"signed-token"}`))
	}))
	defer server.Close()

	client := &GoogleClient{
		clientID: "client-id", clientSecret: "client-secret",
		redirectURIs: map[string]struct{}{"https://market.example/auth/callback": {}},
		tokenURL:     server.URL, httpClient: server.Client(),
		validateToken: func(_ context.Context, token, audience string) (*idtoken.Payload, error) {
			if token != "signed-token" || audience != "client-id" {
				t.Fatalf("validator received token=%q audience=%q", token, audience)
			}
			return &idtoken.Payload{
				Issuer: "https://accounts.google.com", Subject: "google-subject",
				Claims: map[string]any{"email": "Buyer@Example.com", "email_verified": true, "name": "Buyer Name", "hd": "example.com"},
			}, nil
		},
	}
	identity, err := client.Exchange(context.Background(), "authorization-code", verifier, "https://market.example/auth/callback")
	if err != nil {
		t.Fatalf("Exchange() error = %v", err)
	}
	if identity.Subject != "google-subject" || identity.Email != "buyer@example.com" || !identity.EmailVerified || !identity.CanLinkByEmail {
		t.Fatalf("identity = %#v", identity)
	}
	if received.Get("code_verifier") != verifier || received.Get("grant_type") != "authorization_code" || received.Get("client_secret") != "client-secret" {
		t.Fatalf("token request = %#v", received)
	}
}

func TestGoogleExchangeRejectsUnsafeInputAndUnverifiedEmail(t *testing.T) {
	t.Parallel()
	client := &GoogleClient{
		clientID: "client", clientSecret: "secret", redirectURIs: map[string]struct{}{"https://market.example/callback": {}},
		httpClient: &http.Client{},
	}
	if _, err := client.Exchange(context.Background(), "code", "short", "https://market.example/callback"); err == nil {
		t.Fatal("short PKCE verifier was accepted")
	}
	if _, err := client.Exchange(context.Background(), "code", strings.Repeat("v", 43), "https://evil.example/callback"); err == nil {
		t.Fatal("unlisted redirect URI was accepted")
	}
}

func TestGoogleExchangeDoesNotTreatConsumerAliasAsAuthoritativeForLinking(t *testing.T) {
	t.Parallel()
	server := httptest.NewServer(http.HandlerFunc(func(response http.ResponseWriter, _ *http.Request) {
		_, _ = response.Write([]byte(`{"id_token":"signed-token"}`))
	}))
	defer server.Close()
	client := &GoogleClient{
		clientID: "client", clientSecret: "secret",
		redirectURIs: map[string]struct{}{"https://market.example/callback": {}},
		tokenURL:     server.URL, httpClient: server.Client(),
		validateToken: func(context.Context, string, string) (*idtoken.Payload, error) {
			return &idtoken.Payload{
				Issuer: "accounts.google.com", Subject: "subject",
				Claims: map[string]any{"email": "buyer@third-party.example", "email_verified": true},
			}, nil
		},
	}
	identity, err := client.Exchange(context.Background(), "code", strings.Repeat("v", 43), "https://market.example/callback")
	if err != nil {
		t.Fatalf("Exchange() error = %v", err)
	}
	if identity.CanLinkByEmail {
		t.Fatal("non-Gmail address without matching Workspace hd was considered authoritative")
	}
}

func TestGoogleClientRejectsNonLoopbackHTTPRedirect(t *testing.T) {
	t.Parallel()
	_, err := NewGoogleClient(GoogleConfig{
		ClientID: "client", ClientSecret: "secret", RedirectURIs: []string{"http://market.example/callback"},
	}, &http.Client{})
	if err == nil {
		t.Fatal("non-loopback HTTP Google redirect URI was accepted")
	}
}

type urlValues map[string][]string

func (values urlValues) Get(key string) string {
	if len(values[key]) == 0 {
		return ""
	}
	return values[key][0]
}

func parseValues(input string) urlValues {
	result := make(urlValues)
	for _, pair := range strings.Split(input, "&") {
		parts := strings.SplitN(pair, "=", 2)
		if len(parts) == 2 {
			key := strings.ReplaceAll(parts[0], "+", " ")
			value := strings.ReplaceAll(parts[1], "+", " ")
			result[key] = append(result[key], value)
		}
	}
	return result
}
