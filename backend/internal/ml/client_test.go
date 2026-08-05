package ml

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestScam(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		if request.URL.Path != "/v1/scam/predict" {
			t.Fatalf("unexpected path %s", request.URL.Path)
		}
		response.Header().Set("Content-Type", "application/json")
		_, _ = response.Write([]byte(`{"score":0.91,"label":"high_risk","reasons":["advance payment"],"model_version":"test"}`))
	}))
	defer server.Close()

	client := NewClient(server.URL, &http.Client{Timeout: time.Second})
	result, err := client.Scam(context.Background(), ScamInput{Title: "Phone", Price: 10})
	if err != nil {
		t.Fatal(err)
	}
	if result.Label != "high_risk" || result.Score != 0.91 {
		t.Fatalf("unexpected result: %#v", result)
	}
}
