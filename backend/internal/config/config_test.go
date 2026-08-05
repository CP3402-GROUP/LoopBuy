package config

import (
	"strings"
	"testing"
)

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

func TestQwenDefaultsUseSingleQwenKey(t *testing.T) {
	t.Setenv("JWT_SECRET", strings.Repeat("x", 32))
	t.Setenv("QWEN_API_KEY", "  qwen-test-key  ")
	t.Setenv("DASHSCOPE_API_KEY", "legacy-alias-must-be-ignored")
	t.Setenv("QWEN_BASE_URL", "")
	t.Setenv("QWEN_MODEL", "")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("Load() error = %v", err)
	}
	if cfg.QwenAPIKey != "qwen-test-key" {
		t.Fatalf("QwenAPIKey = %q; want trimmed QWEN_API_KEY", cfg.QwenAPIKey)
	}
	if cfg.QwenBaseURL != "https://dashscope-intl.aliyuncs.com/compatible-mode/v1" {
		t.Fatalf("QwenBaseURL = %q; want international compatible-mode default", cfg.QwenBaseURL)
	}
	if cfg.QwenModel != "qwen3.5-flash" {
		t.Fatalf("QwenModel = %q; want qwen3.5-flash", cfg.QwenModel)
	}
}
