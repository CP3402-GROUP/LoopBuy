package ml

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
)

type Client struct {
	baseURL string
	http    *http.Client
}

type ScamInput struct {
	Title       string  `json:"title"`
	Description string  `json:"description"`
	Price       float64 `json:"price"`
	Category    string  `json:"category"`
}

type ScamResult struct {
	Score        float64  `json:"score"`
	Label        string   `json:"label"`
	Reasons      []string `json:"reasons"`
	ModelVersion string   `json:"model_version"`
}

type Candidate struct {
	ListingID int64   `json:"listing_id"`
	Text      string  `json:"text"`
	BaseScore float64 `json:"base_score"`
}

type RankedCandidate struct {
	ListingID int64   `json:"listing_id"`
	Score     float64 `json:"score"`
}

func NewClient(baseURL string, httpClient *http.Client) *Client {
	return &Client{baseURL: strings.TrimRight(baseURL, "/"), http: httpClient}
}

func (c *Client) Scam(ctx context.Context, input ScamInput) (ScamResult, error) {
	var result ScamResult
	err := c.post(ctx, "/v1/scam/predict", input, &result)
	return result, err
}

func (c *Client) Rerank(ctx context.Context, preference string, candidates []Candidate, limit int) ([]RankedCandidate, error) {
	request := struct {
		PreferenceText string      `json:"preference_text"`
		Candidates     []Candidate `json:"candidates"`
		Limit          int         `json:"limit"`
	}{PreferenceText: preference, Candidates: candidates, Limit: limit}
	var response struct {
		Items []RankedCandidate `json:"items"`
	}
	if err := c.post(ctx, "/v1/recommendations/rerank", request, &response); err != nil {
		return nil, err
	}
	return response.Items, nil
}

func (c *Client) Health(ctx context.Context) error {
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+"/healthz", nil)
	if err != nil {
		return err
	}
	response, err := c.http.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return responseError(response)
	}
	return nil
}

func (c *Client) post(ctx context.Context, path string, input, output any) error {
	if c.baseURL == "" {
		return errors.New("ML service URL is not configured")
	}
	body, err := json.Marshal(input)
	if err != nil {
		return err
	}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+path, bytes.NewReader(body))
	if err != nil {
		return err
	}
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Accept", "application/json")
	response, err := c.http.Do(request)
	if err != nil {
		return fmt.Errorf("ML service request: %w", err)
	}
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return responseError(response)
	}
	if err := json.NewDecoder(io.LimitReader(response.Body, 2<<20)).Decode(output); err != nil {
		return fmt.Errorf("decode ML service response: %w", err)
	}
	return nil
}

func responseError(response *http.Response) error {
	body, _ := io.ReadAll(io.LimitReader(response.Body, 16<<10))
	return fmt.Errorf("ML service returned %s: %s", response.Status, strings.TrimSpace(string(body)))
}
