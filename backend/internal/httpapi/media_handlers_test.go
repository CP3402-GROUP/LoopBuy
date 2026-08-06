package httpapi

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"testing"

	localmedia "github.com/CP3402-GROUP/LoopBuy/backend/internal/media"
)

func TestWriteAvatarMediaErrorUsesStablePublicStatuses(t *testing.T) {
	t.Parallel()
	tests := []struct {
		name   string
		err    error
		status int
	}{
		{name: "too large", err: localmedia.ErrFileTooLarge, status: http.StatusRequestEntityTooLarge},
		{name: "empty", err: localmedia.ErrEmptyFile, status: http.StatusUnprocessableEntity},
		{name: "extension", err: localmedia.ErrInvalidExtension, status: http.StatusUnprocessableEntity},
		{name: "mime", err: localmedia.ErrMIMETypeMismatch, status: http.StatusUnprocessableEntity},
		{name: "dimensions", err: localmedia.ErrInvalidDimensions, status: http.StatusUnprocessableEntity},
		{name: "storage", err: errors.New("disk unavailable"), status: http.StatusInternalServerError},
	}
	for _, test := range tests {
		test := test
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()
			request := httptest.NewRequest(http.MethodPost, "/api/v1/users/me/avatar", nil)
			response := httptest.NewRecorder()
			writeAvatarMediaError(response, request, test.err)
			if response.Code != test.status {
				t.Fatalf("status = %d, want %d; body=%s", response.Code, test.status, response.Body.String())
			}
			if got := response.Header().Get("Content-Type"); got != "application/problem+json; charset=utf-8" {
				t.Fatalf("Content-Type = %q", got)
			}
		})
	}
}
