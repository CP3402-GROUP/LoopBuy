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
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/store"
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
	zeroSignals := 0
	oneSignal := 1
	tests := []struct {
		name   string
		result ml.ScamResult
		want   bool
	}{
		{name: "low risk", result: ml.ScamResult{Score: 0.449, Label: "low_risk", RiskSignalCount: &zeroSignals, ModelVersion: "v1"}, want: true},
		{name: "review boundary", result: ml.ScamResult{Score: 0.45, Label: "needs_review", Reasons: []string{"model uncertainty"}, RiskSignalCount: &zeroSignals, ModelVersion: "v1"}, want: true},
		{name: "high risk boundary", result: ml.ScamResult{Score: 0.78, Label: "high_risk", Reasons: []string{"advance payment"}, RiskSignalCount: &oneSignal, ModelVersion: "v1"}, want: true},
		{name: "mismatched label", result: ml.ScamResult{Score: 0.9, Label: "low_risk", RiskSignalCount: &zeroSignals, ModelVersion: "v1"}},
		{name: "unknown label", result: ml.ScamResult{Score: 0.2, Label: "safe", RiskSignalCount: &zeroSignals, ModelVersion: "v1"}},
		{name: "missing version", result: ml.ScamResult{Score: 0.2, Label: "low_risk", RiskSignalCount: &zeroSignals}},
		{name: "missing signal count", result: ml.ScamResult{Score: 0.2, Label: "low_risk", ModelVersion: "v1"}},
		{name: "signal count exceeds reasons", result: ml.ScamResult{Score: 0.5, Label: "needs_review", RiskSignalCount: &oneSignal, ModelVersion: "v1"}},
		{name: "nan", result: ml.ScamResult{Score: math.NaN(), Label: "high_risk", RiskSignalCount: &zeroSignals, ModelVersion: "v1"}},
		{name: "infinity", result: ml.ScamResult{Score: math.Inf(1), Label: "high_risk", RiskSignalCount: &zeroSignals, ModelVersion: "v1"}},
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

func TestDisabledScamModerationPublishesWithoutCallingML(t *testing.T) {
	t.Parallel()

	server := &Server{scamModerationEnabled: false}
	request := httptest.NewRequest(http.MethodPost, "/api/v1/listings", nil)
	assessment := server.runScamAssessment(request, store.ListingInput{Title: "Normal listing"})

	if assessment.ModelVersion != "moderation-disabled" || assessment.Label != "not_screened" {
		t.Fatalf("disabled assessment = %#v", assessment)
	}
	if assessment.RiskSignalCount == nil || *assessment.RiskSignalCount != 0 {
		t.Fatalf("disabled risk signal count = %#v, want explicit zero", assessment.RiskSignalCount)
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

func TestListingInputScalarPatchPreservesLocalImages(t *testing.T) {
	t.Parallel()

	current := validListingForPatch()
	current.Images = []model.ListingImage{
		{ImageID: 1, ListingID: current.ListingID, ImageURL: "/media/listings/42/front.jpg", SortOrder: 0, IsPrimary: true},
		{ImageID: 2, ListingID: current.ListingID, ImageURL: "/media/listings/42/back.jpg", SortOrder: 1},
	}
	newTitle := "Updated title"

	input, err := listingInput(listingRequest{Title: &newTitle}, &current)
	if err != nil {
		t.Fatalf("listingInput() rejected a scalar patch for a listing with local images: %v", err)
	}
	if input.Title != newTitle {
		t.Fatalf("Title = %q, want %q", input.Title, newTitle)
	}
	if input.ReplaceImages {
		t.Fatal("ReplaceImages = true for a scalar-only patch")
	}
	if len(input.Images) != 0 {
		t.Fatalf("Images = %#v, want no replacement payload", input.Images)
	}
}

func TestListingInputExplicitImageReplacement(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name       string
		images     []store.ImageInput
		wantErr    bool
		wantLength int
	}{
		{
			name: "valid absolute URLs",
			images: []store.ImageInput{
				{ImageURL: "https://cdn.example.com/front.jpg", SortOrder: 0, IsPrimary: true},
				{ImageURL: "https://cdn.example.com/back.jpg", SortOrder: 1},
			},
			wantLength: 2,
		},
		{
			name:       "empty replacement clears images",
			images:     []store.ImageInput{},
			wantLength: 0,
		},
		{
			name:    "local URL remains invalid for explicit import replacement",
			images:  []store.ImageInput{{ImageURL: "/media/listings/42/front.jpg", SortOrder: 0, IsPrimary: true}},
			wantErr: true,
		},
	}

	for _, test := range tests {
		test := test
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()
			current := validListingForPatch()
			images := test.images

			input, err := listingInput(listingRequest{Images: &images}, &current)
			if test.wantErr {
				if err == nil {
					t.Fatal("listingInput() error = nil, want image validation error")
				}
				return
			}
			if err != nil {
				t.Fatalf("listingInput() error = %v", err)
			}
			if !input.ReplaceImages {
				t.Fatal("ReplaceImages = false for an explicit image payload")
			}
			if len(input.Images) != test.wantLength {
				t.Fatalf("len(Images) = %d, want %d", len(input.Images), test.wantLength)
			}
		})
	}
}

func validListingForPatch() model.Listing {
	return model.Listing{
		ListingID:     42,
		CategoryID:    1,
		Title:         "Original title",
		Description:   "Original description",
		Brand:         "LoopBuy",
		Location:      "Singapore",
		Price:         50,
		Currency:      "SGD",
		ItemCondition: "good",
	}
}
