package ai

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"reflect"
	"regexp"
	"sort"
	"strings"
)

var qdrantIdentifierPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]*$`)

// QdrantConfig configures a named dense-vector collection.
type QdrantConfig struct {
	BaseURL    string
	APIKey     string
	Collection string
	VectorName string
	VectorSize int
	Distance   string
}

// QdrantVectorStore implements VectorStore with Qdrant's REST API.
type QdrantVectorStore struct {
	rest       *restClient
	collection string
	vectorName string
	vectorSize int
	distance   string
}

var _ VectorStore = (*QdrantVectorStore)(nil)

func NewQdrantVectorStore(config QdrantConfig, client *http.Client) (*QdrantVectorStore, error) {
	config.Collection = strings.TrimSpace(config.Collection)
	config.VectorName = strings.TrimSpace(config.VectorName)
	if !qdrantIdentifierPattern.MatchString(config.Collection) {
		return nil, fmt.Errorf("qdrant: collection must match %s", qdrantIdentifierPattern.String())
	}
	if !qdrantIdentifierPattern.MatchString(config.VectorName) {
		return nil, fmt.Errorf("qdrant: vector name must match %s", qdrantIdentifierPattern.String())
	}
	if config.VectorSize <= 0 {
		return nil, fmt.Errorf("qdrant: vector size must be positive")
	}
	distance, err := canonicalQdrantDistance(config.Distance)
	if err != nil {
		return nil, err
	}

	authValue := strings.TrimSpace(config.APIKey)
	rest, err := newRESTClient("qdrant", config.BaseURL, client, "api-key", authValue)
	if err != nil {
		return nil, err
	}

	return &QdrantVectorStore{
		rest:       rest,
		collection: config.Collection,
		vectorName: config.VectorName,
		vectorSize: config.VectorSize,
		distance:   distance,
	}, nil
}

func canonicalQdrantDistance(value string) (string, error) {
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "cosine":
		return "Cosine", nil
	case "dot":
		return "Dot", nil
	case "euclid":
		return "Euclid", nil
	case "manhattan":
		return "Manhattan", nil
	default:
		return "", fmt.Errorf("qdrant: unsupported distance %q", value)
	}
}

func (s *QdrantVectorStore) collectionPath() string {
	return "/collections/" + s.collection
}

func (s *QdrantVectorStore) pointsPath(suffix string) string {
	return s.collectionPath() + "/points" + suffix
}

type qdrantCollectionResponse struct {
	Status string `json:"status"`
	Result struct {
		Config struct {
			Params struct {
				Vectors json.RawMessage `json:"vectors"`
			} `json:"params"`
		} `json:"config"`
	} `json:"result"`
}

type qdrantCreateResponse struct {
	Status string `json:"status"`
	Result bool   `json:"result"`
}

type qdrantUpdateResponse struct {
	Status string `json:"status"`
	Result struct {
		Status      string  `json:"status"`
		OperationID *uint64 `json:"operation_id"`
	} `json:"result"`
}

type qdrantVectorParams struct {
	Size     int    `json:"size"`
	Distance string `json:"distance"`
}

// Ensure creates the collection, adds a missing named vector to an existing
// collection, or verifies that the existing vector schema is compatible.
func (s *QdrantVectorStore) Ensure(ctx context.Context) error {
	collectionExists, vectorExists, err := s.inspectCollection(ctx)
	if err != nil {
		return err
	}
	if !collectionExists {
		if err := s.createCollection(ctx); err != nil {
			var apiErr *APIError
			if !errors.As(err, &apiErr) || apiErr.StatusCode != http.StatusConflict {
				return err
			}
			collectionExists, vectorExists, err = s.inspectCollection(ctx)
			if err != nil {
				return err
			}
			if !collectionExists {
				return fmt.Errorf("qdrant: collection conflict reported but collection is absent")
			}
		}
		if !collectionExists {
			return nil
		}
	}
	if vectorExists {
		return nil
	}

	if err := s.createNamedVector(ctx); err != nil {
		var apiErr *APIError
		if !errors.As(err, &apiErr) || apiErr.StatusCode != http.StatusConflict {
			return err
		}
		_, vectorExists, inspectErr := s.inspectCollection(ctx)
		if inspectErr != nil {
			return inspectErr
		}
		if !vectorExists {
			return err
		}
	}
	return nil
}

// Ready verifies the expected collection and named-vector schema without
// mutating Qdrant. Provisioning belongs to worker startup, not a health probe.
func (s *QdrantVectorStore) Ready(ctx context.Context) error {
	collectionExists, vectorExists, err := s.inspectCollection(ctx)
	if err != nil {
		return err
	}
	if !collectionExists {
		return fmt.Errorf("qdrant: collection %q does not exist", s.collection)
	}
	if !vectorExists {
		return fmt.Errorf("qdrant: vector %q does not exist", s.vectorName)
	}
	return nil
}

func (s *QdrantVectorStore) inspectCollection(ctx context.Context) (bool, bool, error) {
	statusCode, headers, body, err := s.rest.request(ctx, http.MethodGet, s.collectionPath(), nil, nil)
	if err != nil {
		return false, false, err
	}
	if statusCode == http.StatusNotFound {
		return false, false, nil
	}
	if statusCode < http.StatusOK || statusCode >= http.StatusMultipleChoices {
		return false, false, parseAPIError("qdrant", statusCode, headers, body)
	}

	var response qdrantCollectionResponse
	if err := decodeJSONResponse("qdrant", body, &response); err != nil {
		return false, false, err
	}
	if response.Status != "ok" {
		return false, false, fmt.Errorf("qdrant: unexpected application status %q", response.Status)
	}

	var vectors map[string]json.RawMessage
	if len(response.Result.Config.Params.Vectors) == 0 ||
		string(response.Result.Config.Params.Vectors) == "null" {
		return true, false, nil
	}
	if err := json.Unmarshal(response.Result.Config.Params.Vectors, &vectors); err != nil {
		return false, false, fmt.Errorf("qdrant: decode collection vectors: %w", err)
	}
	raw, found := vectors[s.vectorName]
	if !found {
		return true, false, nil
	}

	var params qdrantVectorParams
	if err := json.Unmarshal(raw, &params); err != nil {
		return false, false, fmt.Errorf("qdrant: decode vector %q: %w", s.vectorName, err)
	}
	if params.Size != s.vectorSize {
		return false, false, fmt.Errorf(
			"qdrant: vector %q has size %d, expected %d",
			s.vectorName,
			params.Size,
			s.vectorSize,
		)
	}
	if !strings.EqualFold(params.Distance, s.distance) {
		return false, false, fmt.Errorf(
			"qdrant: vector %q uses distance %q, expected %q",
			s.vectorName,
			params.Distance,
			s.distance,
		)
	}
	return true, true, nil
}

func (s *QdrantVectorStore) createCollection(ctx context.Context) error {
	request := map[string]any{
		"vectors": map[string]any{
			s.vectorName: qdrantVectorParams{
				Size:     s.vectorSize,
				Distance: s.distance,
			},
		},
	}
	var response qdrantCreateResponse
	if err := s.rest.doJSON(ctx, http.MethodPut, s.collectionPath(), nil, request, &response); err != nil {
		return err
	}
	if response.Status != "ok" || !response.Result {
		return fmt.Errorf("qdrant: create collection returned status %q and result %t", response.Status, response.Result)
	}
	return nil
}

func (s *QdrantVectorStore) createNamedVector(ctx context.Context) error {
	request := map[string]any{
		"dense": qdrantVectorParams{
			Size:     s.vectorSize,
			Distance: s.distance,
		},
	}
	query := url.Values{"wait": []string{"true"}}
	var response qdrantUpdateResponse
	endpoint := s.collectionPath() + "/vectors/" + s.vectorName
	if err := s.rest.doJSON(ctx, http.MethodPut, endpoint, query, request, &response); err != nil {
		return err
	}
	return validateQdrantUpdate("create named vector", response)
}

func validateQdrantUpdate(operation string, response qdrantUpdateResponse) error {
	if response.Status != "ok" {
		return fmt.Errorf("qdrant: %s returned application status %q", operation, response.Status)
	}
	switch response.Result.Status {
	case "acknowledged", "completed":
		return nil
	default:
		return fmt.Errorf("qdrant: %s returned operation status %q", operation, response.Result.Status)
	}
}

func (s *QdrantVectorStore) validateVector(vector []float32) error {
	if len(vector) != s.vectorSize {
		return fmt.Errorf("qdrant: vector has size %d, expected %d", len(vector), s.vectorSize)
	}
	return nil
}

func (s *QdrantVectorStore) UpsertListing(
	ctx context.Context,
	id uint64,
	vector []float32,
	payload map[string]any,
) error {
	if err := s.validateVector(vector); err != nil {
		return err
	}
	if payload == nil {
		payload = map[string]any{}
	}
	request := map[string]any{
		"points": []any{
			map[string]any{
				"id": id,
				"vector": map[string]any{
					s.vectorName: vector,
				},
				"payload": payload,
			},
		},
	}
	query := url.Values{"wait": []string{"true"}}
	var response qdrantUpdateResponse
	if err := s.rest.doJSON(ctx, http.MethodPut, s.pointsPath(""), query, request, &response); err != nil {
		return err
	}
	return validateQdrantUpdate("upsert listing", response)
}

func (s *QdrantVectorStore) QueryListings(
	ctx context.Context,
	vector []float32,
	limit int,
	filters map[string]any,
) ([]SearchResult, error) {
	if err := s.validateVector(vector); err != nil {
		return nil, err
	}
	if limit <= 0 {
		return nil, fmt.Errorf("qdrant: query limit must be positive")
	}

	must, err := qdrantFilterConditions(filters)
	if err != nil {
		return nil, err
	}
	request := map[string]any{
		"query":        vector,
		"using":        s.vectorName,
		"limit":        limit,
		"with_payload": true,
		"with_vector":  false,
	}
	if len(must) > 0 {
		request["filter"] = map[string]any{"must": must}
	}

	var response struct {
		Status string `json:"status"`
		Result struct {
			Points []struct {
				ID      uint64         `json:"id"`
				Score   float32        `json:"score"`
				Payload map[string]any `json:"payload"`
			} `json:"points"`
		} `json:"result"`
	}
	if err := s.rest.doJSON(ctx, http.MethodPost, s.pointsPath("/query"), nil, request, &response); err != nil {
		return nil, err
	}
	if response.Status != "ok" {
		return nil, fmt.Errorf("qdrant: query returned application status %q", response.Status)
	}

	results := make([]SearchResult, len(response.Result.Points))
	for i, point := range response.Result.Points {
		results[i] = SearchResult{
			ID:      point.ID,
			Score:   point.Score,
			Payload: point.Payload,
		}
	}
	return results, nil
}

func qdrantFilterConditions(filters map[string]any) ([]map[string]any, error) {
	if len(filters) == 0 {
		return nil, nil
	}
	keys := make([]string, 0, len(filters))
	for key := range filters {
		if strings.TrimSpace(key) == "" {
			return nil, fmt.Errorf("qdrant: filter key is empty")
		}
		keys = append(keys, key)
	}
	sort.Strings(keys)

	conditions := make([]map[string]any, 0, len(keys))
	for _, key := range keys {
		match, err := qdrantMatch(filters[key])
		if err != nil {
			return nil, fmt.Errorf("qdrant: filter %q: %w", key, err)
		}
		conditions = append(conditions, map[string]any{
			"key":   key,
			"match": match,
		})
	}
	return conditions, nil
}

func qdrantMatch(value any) (map[string]any, error) {
	if value == nil {
		return nil, fmt.Errorf("nil requires an explicit Qdrant null condition")
	}
	if _, ok := value.(json.Number); ok {
		return map[string]any{"value": value}, nil
	}

	rv := reflect.ValueOf(value)
	switch rv.Kind() {
	case reflect.String, reflect.Bool,
		reflect.Int, reflect.Int8, reflect.Int16, reflect.Int32, reflect.Int64,
		reflect.Uint, reflect.Uint8, reflect.Uint16, reflect.Uint32, reflect.Uint64,
		reflect.Float32, reflect.Float64:
		return map[string]any{"value": value}, nil
	case reflect.Array, reflect.Slice:
		if rv.Len() == 0 {
			return nil, fmt.Errorf("list is empty")
		}
		values := make([]any, rv.Len())
		for i := 0; i < rv.Len(); i++ {
			item := rv.Index(i)
			if item.Kind() == reflect.Interface {
				if item.IsNil() {
					return nil, fmt.Errorf("list item %d is nil", i)
				}
				item = item.Elem()
			}
			switch item.Kind() {
			case reflect.String, reflect.Bool,
				reflect.Int, reflect.Int8, reflect.Int16, reflect.Int32, reflect.Int64,
				reflect.Uint, reflect.Uint8, reflect.Uint16, reflect.Uint32, reflect.Uint64,
				reflect.Float32, reflect.Float64:
				values[i] = item.Interface()
			default:
				return nil, fmt.Errorf("list item %d has unsupported type %s", i, item.Type())
			}
		}
		return map[string]any{"any": values}, nil
	default:
		return nil, fmt.Errorf("unsupported value type %T", value)
	}
}

func (s *QdrantVectorStore) DeleteListing(ctx context.Context, id uint64) error {
	request := map[string]any{"points": []uint64{id}}
	query := url.Values{"wait": []string{"true"}}
	var response qdrantUpdateResponse
	if err := s.rest.doJSON(ctx, http.MethodPost, s.pointsPath("/delete"), query, request, &response); err != nil {
		return err
	}
	return validateQdrantUpdate("delete listing", response)
}
