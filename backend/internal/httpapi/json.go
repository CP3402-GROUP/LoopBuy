package httpapi

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/store"
)

type problem struct {
	Type     string            `json:"type"`
	Title    string            `json:"title"`
	Status   int               `json:"status"`
	Detail   string            `json:"detail,omitempty"`
	Instance string            `json:"instance,omitempty"`
	Errors   map[string]string `json:"errors,omitempty"`
}

func writeJSON(response http.ResponseWriter, status int, value any) {
	if response.Header().Get("Content-Type") == "" {
		response.Header().Set("Content-Type", "application/json; charset=utf-8")
	}
	response.WriteHeader(status)
	if status != http.StatusNoContent {
		_ = json.NewEncoder(response).Encode(value)
	}
}

func writeProblem(response http.ResponseWriter, request *http.Request, status int, title, detail string) {
	response.Header().Set("Content-Type", "application/problem+json; charset=utf-8")
	writeJSON(response, status, problem{
		Type:  "https://loopbuy.local/problems/" + strings.ToLower(strings.ReplaceAll(title, " ", "-")),
		Title: title, Status: status, Detail: detail, Instance: request.URL.Path,
	})
}

func writeStoreError(response http.ResponseWriter, request *http.Request, err error) {
	switch {
	case errors.Is(err, store.ErrNotFound):
		writeProblem(response, request, http.StatusNotFound, "Not found", "The requested resource does not exist.")
	case errors.Is(err, store.ErrConflict):
		writeProblem(response, request, http.StatusConflict, "Conflict", "A resource with these values already exists.")
	case errors.Is(err, store.ErrForbidden):
		writeProblem(response, request, http.StatusForbidden, "Forbidden", "You are not allowed to perform this operation.")
	case errors.Is(err, store.ErrInvalidState):
		writeProblem(response, request, http.StatusConflict, "Invalid state", "The resource cannot be changed in its current state.")
	case errors.Is(err, store.ErrStaleWrite):
		writeProblem(response, request, http.StatusConflict, "Stale write", "The resource changed since it was read. Reload it and retry with the latest revision.")
	default:
		writeProblem(response, request, http.StatusInternalServerError, "Internal server error", "The request could not be completed.")
	}
}

func decodeJSON(response http.ResponseWriter, request *http.Request, output any) error {
	request.Body = http.MaxBytesReader(response, request.Body, 1<<20)
	decoder := json.NewDecoder(request.Body)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(output); err != nil {
		var syntaxError *json.SyntaxError
		var typeError *json.UnmarshalTypeError
		switch {
		case errors.As(err, &syntaxError):
			return fmt.Errorf("malformed JSON at byte %d", syntaxError.Offset)
		case errors.As(err, &typeError):
			return fmt.Errorf("field %q has the wrong type", typeError.Field)
		case errors.Is(err, io.EOF):
			return errors.New("request body is required")
		case strings.HasPrefix(err.Error(), "json: unknown field "):
			return err
		default:
			return fmt.Errorf("invalid JSON: %w", err)
		}
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return errors.New("request body must contain one JSON object")
	}
	return nil
}
