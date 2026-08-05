package config

import "testing"

func TestProviderRequestLimit(t *testing.T) {
	const key = "LOOPBUY_TEST_PROVIDER_REQUEST_LIMIT"

	t.Setenv(key, "")
	value, err := providerRequestLimit(key, 300)
	if err != nil || value != 300 {
		t.Fatalf("default provider request limit = %d, %v; want 300, nil", value, err)
	}

	for _, invalid := range []string{"0", "-1", "100001", "invalid"} {
		t.Run(invalid, func(t *testing.T) {
			t.Setenv(key, invalid)
			if _, err := providerRequestLimit(key, 300); err == nil {
				t.Fatalf("providerRequestLimit(%q) succeeded; want validation error", invalid)
			}
		})
	}
}
