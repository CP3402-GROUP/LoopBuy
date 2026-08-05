package store

import (
	"math"
	"testing"
)

func TestModerationForAssessmentFailsClosed(t *testing.T) {
	t.Parallel()
	tests := []struct {
		name       string
		assessment AssessmentInput
		status     string
		moderation string
	}{
		{name: "valid low risk", assessment: AssessmentInput{Score: 0.2, Label: "low_risk"}, status: "active", moderation: "approved"},
		{name: "low label high score", assessment: AssessmentInput{Score: 0.9, Label: "low_risk"}, status: "under_review", moderation: "pending"},
		{name: "nan", assessment: AssessmentInput{Score: math.NaN(), Label: "low_risk"}, status: "under_review", moderation: "pending"},
		{name: "unknown", assessment: AssessmentInput{Score: 0.2, Label: "safe"}, status: "under_review", moderation: "pending"},
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
