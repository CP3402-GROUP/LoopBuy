package httpapi

import (
	"strings"
	"testing"
)

func TestValidAIInputCapsUTF8BytesAndRunes(t *testing.T) {
	tests := []struct {
		name  string
		value string
		want  bool
	}{
		{name: "ascii at limit", value: strings.Repeat("a", aiInputMaxLength), want: true},
		{name: "multibyte at byte limit", value: strings.Repeat("é", aiInputMaxLength/2), want: true},
		{name: "too many bytes", value: strings.Repeat("é", aiInputMaxLength/2+1), want: false},
		{name: "too many runes", value: strings.Repeat("a", aiInputMaxLength+1), want: false},
		{name: "invalid utf8", value: string([]byte{0xff}), want: false},
	}
	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if got := validAIInput(test.value); got != test.want {
				t.Fatalf("validAIInput() = %t; want %t", got, test.want)
			}
		})
	}
}

func TestBoundedAIInputPreservesUTF8WithinByteLimit(t *testing.T) {
	t.Parallel()
	input := "  " + strings.Repeat("é", aiInputMaxLength) + "  "
	got := boundedAIInput(input)
	if !validAIInput(got) {
		t.Fatalf("boundedAIInput returned invalid output: bytes=%d runes=%d", len(got), len([]rune(got)))
	}
	if len(got) != aiInputMaxLength {
		t.Fatalf("boundedAIInput bytes = %d, want %d", len(got), aiInputMaxLength)
	}
	if got != strings.Repeat("é", aiInputMaxLength/2) {
		t.Fatal("boundedAIInput cut a UTF-8 rune or returned unexpected text")
	}
}

func TestBoundedAIInputRejectsInvalidUTF8(t *testing.T) {
	t.Parallel()
	if got := boundedAIInput(string([]byte{0xff})); got != "" {
		t.Fatalf("boundedAIInput(invalid UTF-8) = %q, want empty", got)
	}
}
