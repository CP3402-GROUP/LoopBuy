package database

import (
	"strings"
	"testing"
)

func TestValidateDemoImageURLs(t *testing.T) {
	t.Parallel()
	urls := make(map[string]string)
	for _, listing := range demoListings {
		urls[listing.Image] = "/media/demo/placeholder-v1.webp"
	}
	if err := validateDemoImageURLs(urls); err != nil {
		t.Fatal(err)
	}
	delete(urls, "headphones")
	if err := validateDemoImageURLs(urls); err == nil || !strings.Contains(err.Error(), "headphones") {
		t.Fatalf("missing image error = %v", err)
	}
}

func TestDemoSeedDefinitionsUseStableUniqueKeys(t *testing.T) {
	t.Parallel()
	keys := make(map[string]bool)
	for _, listing := range demoListings {
		if listing.Key == "" || keys[listing.Key] {
			t.Fatalf("duplicate or empty seed key %q", listing.Key)
		}
		keys[listing.Key] = true
		if listing.Seller == "" || listing.Category == "" || listing.Image == "" || listing.Title == "" {
			t.Fatalf("incomplete demo listing %#v", listing)
		}
	}
}
