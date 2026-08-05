package ai

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
)

const maxHTTPResponseBytes int64 = 16 << 20

var providerSecretPattern = regexp.MustCompile(`(?i)\b(?:sk|dashscope|qwen)[-_][A-Za-z0-9_*.-]{6,}`)

// APIError describes a non-2xx response returned by an upstream provider.
type APIError struct {
	Provider   string
	StatusCode int
	Code       string
	Message    string
	RequestID  string
}

func (e *APIError) Error() string {
	parts := []string{fmt.Sprintf("%s: HTTP %d", e.Provider, e.StatusCode)}
	if e.Code != "" {
		parts = append(parts, "code="+e.Code)
	}
	if e.Message != "" {
		parts = append(parts, e.Message)
	}
	if e.RequestID != "" {
		parts = append(parts, "request_id="+e.RequestID)
	}
	return strings.Join(parts, ": ")
}

type restClient struct {
	provider   string
	httpClient *http.Client
	baseURL    *url.URL
	authHeader string
	authValue  string
}

func newRESTClient(provider, rawBaseURL string, client *http.Client, authHeader, authValue string) (*restClient, error) {
	if client == nil {
		return nil, fmt.Errorf("%s: HTTP client is required", provider)
	}

	rawBaseURL = strings.TrimSpace(rawBaseURL)
	if rawBaseURL == "" {
		return nil, fmt.Errorf("%s: base URL is required", provider)
	}
	u, err := url.Parse(rawBaseURL)
	if err != nil {
		return nil, fmt.Errorf("%s: parse base URL: %w", provider, err)
	}
	if (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return nil, fmt.Errorf("%s: base URL must be an absolute HTTP(S) URL", provider)
	}
	if u.RawQuery != "" || u.Fragment != "" {
		return nil, fmt.Errorf("%s: base URL must not contain a query or fragment", provider)
	}
	u.Path = strings.TrimRight(u.Path, "/")
	u.RawPath = ""

	return &restClient{
		provider:   provider,
		httpClient: client,
		baseURL:    u,
		authHeader: authHeader,
		authValue:  authValue,
	}, nil
}

func (c *restClient) doJSON(
	ctx context.Context,
	method, endpoint string,
	query url.Values,
	requestBody any,
	responseBody any,
) error {
	statusCode, headers, body, err := c.request(ctx, method, endpoint, query, requestBody)
	if err != nil {
		return err
	}
	if statusCode < http.StatusOK || statusCode >= http.StatusMultipleChoices {
		return parseAPIError(c.provider, statusCode, headers, body)
	}
	return decodeJSONResponse(c.provider, body, responseBody)
}

func (c *restClient) request(
	ctx context.Context,
	method, endpoint string,
	query url.Values,
	requestBody any,
) (int, http.Header, []byte, error) {
	var bodyReader io.Reader
	if requestBody != nil {
		body, err := json.Marshal(requestBody)
		if err != nil {
			return 0, nil, nil, fmt.Errorf("%s: encode request: %w", c.provider, err)
		}
		bodyReader = bytes.NewReader(body)
	}

	u := *c.baseURL
	u.Path = strings.TrimRight(u.Path, "/") + "/" + strings.TrimLeft(endpoint, "/")
	u.RawPath = ""
	if query != nil {
		u.RawQuery = query.Encode()
	}

	req, err := http.NewRequestWithContext(ctx, method, u.String(), bodyReader)
	if err != nil {
		return 0, nil, nil, fmt.Errorf("%s: create request: %w", c.provider, err)
	}
	req.Header.Set("Accept", "application/json")
	if requestBody != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if c.authHeader != "" && c.authValue != "" {
		req.Header.Set(c.authHeader, c.authValue)
	}

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return 0, nil, nil, fmt.Errorf("%s: execute request: %w", c.provider, err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(io.LimitReader(resp.Body, maxHTTPResponseBytes+1))
	if err != nil {
		return 0, nil, nil, fmt.Errorf("%s: read response: %w", c.provider, err)
	}
	if int64(len(body)) > maxHTTPResponseBytes {
		return 0, nil, nil, fmt.Errorf("%s: response exceeds %d bytes", c.provider, maxHTTPResponseBytes)
	}

	return resp.StatusCode, resp.Header.Clone(), body, nil
}

func decodeJSONResponse(provider string, body []byte, out any) error {
	if out == nil {
		return nil
	}
	if len(bytes.TrimSpace(body)) == 0 {
		return fmt.Errorf("%s: empty JSON response", provider)
	}
	if err := json.Unmarshal(body, out); err != nil {
		return fmt.Errorf("%s: decode JSON response: %w", provider, err)
	}
	return nil
}

func parseAPIError(provider string, statusCode int, headers http.Header, body []byte) error {
	apiErr := &APIError{
		Provider:   provider,
		StatusCode: statusCode,
		RequestID:  firstHeader(headers, "x-request-id", "request-id", "x-dashscope-request-id"),
	}

	var root map[string]json.RawMessage
	if json.Unmarshal(body, &root) == nil {
		apiErr.Code = scalarJSON(root["code"])
		apiErr.Message = scalarJSON(root["message"])
		if apiErr.RequestID == "" {
			apiErr.RequestID = scalarJSON(root["request_id"])
		}

		if raw := root["error"]; len(raw) > 0 {
			mergeErrorObject(raw, apiErr)
		}
		if raw := root["status"]; len(raw) > 0 && apiErr.Message == "" {
			mergeErrorObject(raw, apiErr)
		}
	}

	if apiErr.Message == "" {
		message := strings.TrimSpace(string(body))
		if len(message) > 512 {
			message = message[:512]
		}
		if message == "" {
			message = http.StatusText(statusCode)
		}
		apiErr.Message = message
	}
	apiErr.Message = providerSecretPattern.ReplaceAllString(apiErr.Message, "[REDACTED_PROVIDER_TOKEN]")

	return apiErr
}

func mergeErrorObject(raw json.RawMessage, target *APIError) {
	if value := scalarJSON(raw); value != "" {
		if target.Message == "" {
			target.Message = value
		}
		return
	}

	var object map[string]json.RawMessage
	if json.Unmarshal(raw, &object) != nil {
		return
	}
	if target.Message == "" {
		target.Message = scalarJSON(object["message"])
		if target.Message == "" {
			target.Message = scalarJSON(object["error"])
		}
	}
	if target.Code == "" {
		target.Code = scalarJSON(object["code"])
		if target.Code == "" {
			target.Code = scalarJSON(object["type"])
		}
	}
	if target.RequestID == "" {
		target.RequestID = scalarJSON(object["request_id"])
	}
}

func scalarJSON(raw json.RawMessage) string {
	if len(raw) == 0 || bytes.Equal(bytes.TrimSpace(raw), []byte("null")) {
		return ""
	}
	var text string
	if json.Unmarshal(raw, &text) == nil {
		return text
	}
	var number json.Number
	if json.Unmarshal(raw, &number) == nil {
		return number.String()
	}
	var boolean bool
	if json.Unmarshal(raw, &boolean) == nil {
		return strconv.FormatBool(boolean)
	}
	return ""
}

func firstHeader(headers http.Header, names ...string) string {
	for _, name := range names {
		if value := strings.TrimSpace(headers.Get(name)); value != "" {
			return value
		}
	}
	return ""
}
