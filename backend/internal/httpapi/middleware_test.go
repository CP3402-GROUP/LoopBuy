package httpapi

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestWriteProblemKeepsProblemContentType(t *testing.T) {
	response := httptest.NewRecorder()
	request := httptest.NewRequest(http.MethodGet, "/missing", nil)

	writeProblem(response, request, http.StatusNotFound, "Not found", "missing")

	if got := response.Header().Get("Content-Type"); got != "application/problem+json; charset=utf-8" {
		t.Fatalf("Content-Type = %q", got)
	}
	if !strings.Contains(response.Body.String(), `"status":404`) {
		t.Fatalf("problem body = %s", response.Body.String())
	}
}

func TestSignedBFFClientsUseIndependentRateLimitBuckets(t *testing.T) {
	secret := strings.Repeat("b", 32)
	server := &Server{bffSharedSecret: secret, rateLimits: make(map[string]rateLimitWindow), aiSlots: make(chan struct{}, 1)}
	handler := server.rateLimited("register", 1, time.Hour, false, http.HandlerFunc(func(response http.ResponseWriter, _ *http.Request) {
		response.WriteHeader(http.StatusNoContent)
	}))

	firstClient := signedBFFRequest(t, secret, strings.Repeat("a", 64))
	firstResponse := httptest.NewRecorder()
	handler.ServeHTTP(firstResponse, firstClient)
	if firstResponse.Code != http.StatusNoContent {
		t.Fatalf("first client status = %d", firstResponse.Code)
	}

	repeatedResponse := httptest.NewRecorder()
	handler.ServeHTTP(repeatedResponse, signedBFFRequest(t, secret, strings.Repeat("a", 64)))
	if repeatedResponse.Code != http.StatusTooManyRequests {
		t.Fatalf("repeated client status = %d", repeatedResponse.Code)
	}

	secondResponse := httptest.NewRecorder()
	handler.ServeHTTP(secondResponse, signedBFFRequest(t, secret, strings.Repeat("c", 64)))
	if secondResponse.Code != http.StatusNoContent {
		t.Fatalf("second client status = %d", secondResponse.Code)
	}
}

func TestSignedBFFClientRejectsTamperingAndStaleTimestamp(t *testing.T) {
	secret := strings.Repeat("b", 32)
	server := &Server{bffSharedSecret: secret}
	request := signedBFFRequest(t, secret, strings.Repeat("a", 64))
	request.Header.Set("X-LoopBuy-BFF-Signature", strings.Repeat("0", 64))
	if _, ok := server.validBFFClient(request, time.Now().UTC()); ok {
		t.Fatal("tampered BFF signature accepted")
	}

	stale := signedBFFRequestAt(t, secret, strings.Repeat("a", 64), time.Now().UTC().Add(-2*time.Minute))
	if _, ok := server.validBFFClient(stale, time.Now().UTC()); ok {
		t.Fatal("stale BFF signature accepted")
	}
}

func TestCredentialLimitCannotBeSpentForAnotherBFFClient(t *testing.T) {
	secret := strings.Repeat("b", 32)
	server := &Server{bffSharedSecret: secret, rateLimits: make(map[string]rateLimitWindow)}
	firstRequest := signedBFFRequestFor(t, secret, strings.Repeat("a", 64), http.MethodPost, "/api/v1/auth/login", time.Now().UTC())
	if !server.allowCredentialAttempt(httptest.NewRecorder(), firstRequest, "login-account", "victim@example.com", 1) {
		t.Fatal("first client unexpectedly rate limited")
	}
	if server.allowCredentialAttempt(httptest.NewRecorder(), signedBFFRequestFor(t, secret, strings.Repeat("a", 64), http.MethodPost, "/api/v1/auth/login", time.Now().UTC()), "login-account", "victim@example.com", 1) {
		t.Fatal("repeated client unexpectedly bypassed credential limit")
	}
	if !server.allowCredentialAttempt(httptest.NewRecorder(), signedBFFRequestFor(t, secret, strings.Repeat("c", 64), http.MethodPost, "/api/v1/auth/login", time.Now().UTC()), "login-account", "victim@example.com", 1) {
		t.Fatal("unrelated client was locked out of the victim credential")
	}
}

func signedBFFRequest(t *testing.T, secret, clientHash string) *http.Request {
	t.Helper()
	return signedBFFRequestAt(t, secret, clientHash, time.Now().UTC())
}

func signedBFFRequestAt(t *testing.T, secret, clientHash string, timestamp time.Time) *http.Request {
	t.Helper()
	return signedBFFRequestFor(t, secret, clientHash, http.MethodPost, "/api/v1/auth/register", timestamp)
}

func signedBFFRequestFor(t *testing.T, secret, clientHash, method, path string, timestamp time.Time) *http.Request {
	t.Helper()
	request := httptest.NewRequest(method, path, nil)
	timestampText := strconv.FormatInt(timestamp.Unix(), 10)
	canonical := "loopbuy-bff-v1\n" + timestampText + "\n" + clientHash + "\n" + strings.ToUpper(method) + "\n" + path
	mac := hmac.New(sha256.New, []byte(secret))
	if _, err := mac.Write([]byte(canonical)); err != nil {
		t.Fatal(err)
	}
	request.Header.Set("X-LoopBuy-BFF-Timestamp", timestampText)
	request.Header.Set("X-LoopBuy-BFF-Client", clientHash)
	request.Header.Set("X-LoopBuy-BFF-Signature", hex.EncodeToString(mac.Sum(nil)))
	return request
}

func TestRateLimitedRejectsRequestsPastWindow(t *testing.T) {
	server := &Server{rateLimits: make(map[string]rateLimitWindow), aiSlots: make(chan struct{}, 1)}
	handler := server.rateLimited("login", 1, time.Minute, false, http.HandlerFunc(func(response http.ResponseWriter, _ *http.Request) {
		writeJSON(response, http.StatusOK, map[string]bool{"ok": true})
	}))

	first := httptest.NewRecorder()
	handler.ServeHTTP(first, httptest.NewRequest(http.MethodPost, "/api/v1/auth/login", nil))
	if first.Code != http.StatusOK {
		t.Fatalf("first status = %d", first.Code)
	}

	second := httptest.NewRecorder()
	handler.ServeHTTP(second, httptest.NewRequest(http.MethodPost, "/api/v1/auth/login", nil))
	if second.Code != http.StatusTooManyRequests {
		t.Fatalf("second status = %d", second.Code)
	}
	if second.Header().Get("Retry-After") == "" {
		t.Fatal("Retry-After header is missing")
	}
}
