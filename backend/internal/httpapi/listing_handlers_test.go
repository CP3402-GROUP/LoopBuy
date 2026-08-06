package httpapi

import (
	"math"
	"net/http"
	"net/http/httptest"
	"net/url"
	"reflect"
	"testing"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/ml"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/model"
)

func TestListMyListingsFailsClosedWithoutAuthenticatedClaims(t *testing.T) {
	t.Parallel()

	server := &Server{}
	request := httptest.NewRequest(http.MethodGet, "/api/v1/users/me/listings", nil)
	response := httptest.NewRecorder()

	server.listMyListings(response, request)

	if response.Code != http.StatusUnauthorized {
		t.Fatalf("listMyListings status = %d, want %d", response.Code, http.StatusUnauthorized)
	}
}

func TestMyListingsFiltersAreBoundToAuthenticatedSeller(t *testing.T) {
	t.Parallel()

	filters := myListingsFilters(42, url.Values{
		"seller_id": {"7"},
		"limit":     {"25"},
		"offset":    {"50"},
	})

	if filters.SellerID != 42 {
		t.Fatalf("SellerID = %d, want authenticated user 42", filters.SellerID)
	}
	if filters.Limit != 25 || filters.Offset != 50 {
		t.Fatalf("pagination = (%d, %d), want (25, 50)", filters.Limit, filters.Offset)
	}
	if filters.ActiveCategoriesOnly {
		t.Fatal("owner listing query unexpectedly excludes listings in inactive categories")
	}
	if !reflect.DeepEqual(filters.Statuses, []string{"draft", "under_review", "active", "reserved", "sold", "archived"}) {
		t.Fatalf("Statuses = %#v", filters.Statuses)
	}
	if !reflect.DeepEqual(filters.Moderation, []string{"approved", "pending", "rejected", "review", "unavailable"}) {
		t.Fatalf("Moderation = %#v", filters.Moderation)
	}
}

func TestMyListingsFiltersUseBoundedPaginationDefaults(t *testing.T) {
	t.Parallel()

	filters := myListingsFilters(9, url.Values{
		"limit":  {"1000"},
		"offset": {"-1"},
	})

	if filters.Limit != 100 || filters.Offset != 0 {
		t.Fatalf("pagination = (%d, %d), want bounded defaults (100, 0)", filters.Limit, filters.Offset)
	}
}

func TestValidAssessmentResultRequiresConsistentThresholds(t *testing.T) {
	t.Parallel()
	tests := []struct {
		name   string
		result ml.ScamResult
		want   bool
	}{
		{name: "low risk", result: ml.ScamResult{Score: 0.449, Label: "low_risk", ModelVersion: "v1"}, want: true},
		{name: "review boundary", result: ml.ScamResult{Score: 0.45, Label: "needs_review", ModelVersion: "v1"}, want: true},
		{name: "high risk boundary", result: ml.ScamResult{Score: 0.78, Label: "high_risk", ModelVersion: "v1"}, want: true},
		{name: "mismatched label", result: ml.ScamResult{Score: 0.9, Label: "low_risk", ModelVersion: "v1"}},
		{name: "unknown label", result: ml.ScamResult{Score: 0.2, Label: "safe", ModelVersion: "v1"}},
		{name: "missing version", result: ml.ScamResult{Score: 0.2, Label: "low_risk"}},
		{name: "nan", result: ml.ScamResult{Score: math.NaN(), Label: "high_risk", ModelVersion: "v1"}},
		{name: "infinity", result: ml.ScamResult{Score: math.Inf(1), Label: "high_risk", ModelVersion: "v1"}},
	}
	for _, test := range tests {
		test := test
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()
			if got := validAssessmentResult(test.result); got != test.want {
				t.Fatalf("validAssessmentResult(%#v) = %v, want %v", test.result, got, test.want)
			}
		})
	}
}

func TestFirstAvailableSortOrder(t *testing.T) {
	t.Parallel()
	images := []model.ListingImage{{SortOrder: 0}, {SortOrder: 2}, {SortOrder: 3}}
	if got := firstAvailableSortOrder(images); got != 1 {
		t.Fatalf("firstAvailableSortOrder() = %d, want 1", got)
	}
	if got := firstAvailableSortOrder(nil); got != 0 {
		t.Fatalf("firstAvailableSortOrder(nil) = %d, want 0", got)
	}
}
