package store

import (
	"math"
	"testing"
)

func TestModerationForAssessmentFailsClosed(t *testing.T) {
	t.Parallel()
	zeroSignals := 0
	oneSignal := 1
	tests := []struct {
		name       string
		assessment AssessmentInput
		status     string
		moderation string
	}{
		{name: "valid low risk", assessment: AssessmentInput{Score: 0.2, Label: "low_risk", RiskSignalCount: &zeroSignals, ModelVersion: "v1"}, status: "active", moderation: "approved"},
		{name: "moderation feature disabled", assessment: AssessmentInput{Score: 0, Label: "not_screened", RiskSignalCount: &zeroSignals, ModelVersion: "moderation-disabled"}, status: "active", moderation: "approved"},
		{name: "borderline model-only score", assessment: AssessmentInput{Score: 0.4711, Label: "needs_review", RiskSignalCount: &zeroSignals, ModelVersion: "v1"}, status: "active", moderation: "approved"},
		{name: "borderline explicit signal", assessment: AssessmentInput{Score: 0.4711, Label: "needs_review", RiskSignalCount: &oneSignal, ModelVersion: "v1"}, status: "under_review", moderation: "review"},
		{name: "above bounded release", assessment: AssessmentInput{Score: 0.55, Label: "needs_review", RiskSignalCount: &zeroSignals, ModelVersion: "v1"}, status: "under_review", moderation: "review"},
		{name: "low label high score", assessment: AssessmentInput{Score: 0.9, Label: "low_risk", RiskSignalCount: &zeroSignals, ModelVersion: "v1"}, status: "under_review", moderation: "review"},
		{name: "nan", assessment: AssessmentInput{Score: math.NaN(), Label: "low_risk", RiskSignalCount: &zeroSignals, ModelVersion: "v1"}, status: "under_review", moderation: "review"},
		{name: "unknown", assessment: AssessmentInput{Score: 0.2, Label: "safe", RiskSignalCount: &zeroSignals, ModelVersion: "v1"}, status: "under_review", moderation: "review"},
		{name: "provider unavailable", assessment: AssessmentInput{Score: 0.5, Label: "needs_review", ModelVersion: "unavailable"}, status: "under_review", moderation: "unavailable"},
		{name: "missing signal contract", assessment: AssessmentInput{Score: 0.2, Label: "low_risk", ModelVersion: "v1"}, status: "under_review", moderation: "unavailable"},
	}
	for _, test := range tests {
		test := test
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()
			status, moderation := moderationForAssessment(test.assessment)
			if status != test.status || moderation != test.moderation {
				t.Fatalf("moderationForAssessment() = (%q, %q), want (%q, %q)", status, moderation, test.status, test.moderation)
			}
		})
	}
}
