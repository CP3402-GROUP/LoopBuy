package httpapi

import (
	"math"
	"testing"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/ml"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/model"
)

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
