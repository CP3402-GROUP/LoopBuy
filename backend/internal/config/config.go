package config

import (
	"fmt"
	"os"
	"strconv"
	"strings"
	"time"
)

type Config struct {
	HTTPAddr             string
	DatabaseDSN          string
	JWTSecret            string
	AccessTokenTTL       time.Duration
	RefreshTTL           time.Duration
	CORSOrigins          []string
	MediaStorageRoot     string
	MediaPublicBaseURL   string
	MediaMaxUploadBytes  int64
	DemoSeedEnabled      bool
	DemoMediaSourceDir   string
	GoogleClientID       string
	GoogleClientSecret   string
	GoogleRedirectURIs   []string
	BFFSharedSecret      string
	ResendAPIKey         string
	ResendBaseURL        string
	ResendFrom           string
	ResendMaxEmailsHour  int
	EmailVerificationURL string
	EmailVerificationTTL time.Duration

	OpenAIAPIKey              string
	OpenAIBaseURL             string
	OpenAIEmbeddingModel      string
	OpenAIEmbeddingDimensions int
	OpenAIMaxRequestsHour     int
	OpenAIMaxRequestsUserDay  int

	QdrantURL        string
	QdrantAPIKey     string
	QdrantCollection string
	QdrantVectorName string

	QwenAPIKey             string
	QwenBaseURL            string
	QwenModel              string
	QwenMaxRequestsHour    int
	QwenMaxRequestsUserDay int

	MLServiceURL   string
	AIChatFallback bool
	WorkerInterval time.Duration
}

func Load() (Config, error) {
	mediaMaxUploadBytes, err := envInt64("MEDIA_MAX_UPLOAD_BYTES", 8<<20)
	if err != nil || mediaMaxUploadBytes < 1 || mediaMaxUploadBytes > 25<<20 {
		return Config{}, fmt.Errorf("MEDIA_MAX_UPLOAD_BYTES must be between 1 and 26214400")
	}
	dimensions, err := envInt("OPENAI_EMBEDDING_DIMENSIONS", 1536)
	if err != nil || dimensions < 1 {
		return Config{}, fmt.Errorf("OPENAI_EMBEDDING_DIMENSIONS must be a positive integer")
	}
	resendMaxEmailsHour, err := envInt("RESEND_MAX_EMAILS_PER_HOUR", 100)
	if err != nil || resendMaxEmailsHour < 1 || resendMaxEmailsHour > 10000 {
		return Config{}, fmt.Errorf("RESEND_MAX_EMAILS_PER_HOUR must be between 1 and 10000")
	}
	openAIMaxRequestsHour, err := providerRequestLimit("OPENAI_MAX_REQUESTS_PER_HOUR", 300)
	if err != nil {
		return Config{}, err
	}
	openAIMaxRequestsUserDay, err := providerRequestLimit("OPENAI_MAX_REQUESTS_PER_USER_DAY", 20)
	if err != nil {
		return Config{}, err
	}
	qwenMaxRequestsHour, err := providerRequestLimit("QWEN_MAX_REQUESTS_PER_HOUR", 100)
	if err != nil {
		return Config{}, err
	}
	qwenMaxRequestsUserDay, err := providerRequestLimit("QWEN_MAX_REQUESTS_PER_USER_DAY", 50)
	if err != nil {
		return Config{}, err
	}

	accessTTL, err := envDuration("ACCESS_TOKEN_TTL", 15*time.Minute)
	if err != nil {
		return Config{}, err
	}
	refreshTTL, err := envDuration("REFRESH_TOKEN_TTL", 30*24*time.Hour)
	if err != nil {
		return Config{}, err
	}
	emailVerificationTTL, err := envDuration("EMAIL_VERIFICATION_TTL", 24*time.Hour)
	if err != nil || emailVerificationTTL <= 0 {
		return Config{}, fmt.Errorf("EMAIL_VERIFICATION_TTL must be a positive duration")
	}
	workerInterval, err := envDuration("OUTBOX_POLL_INTERVAL", 2*time.Second)
	if err != nil {
		return Config{}, err
	}

	cfg := Config{
		HTTPAddr:             env("HTTP_ADDR", ":8080"),
		DatabaseDSN:          databaseDSN(),
		JWTSecret:            os.Getenv("JWT_SECRET"),
		AccessTokenTTL:       accessTTL,
		RefreshTTL:           refreshTTL,
		CORSOrigins:          splitCSV(env("CORS_ALLOWED_ORIGINS", "http://localhost:8080")),
		MediaStorageRoot:     env("MEDIA_STORAGE_ROOT", "/var/lib/loopbuy/media"),
		MediaPublicBaseURL:   env("MEDIA_PUBLIC_BASE_URL", "/media"),
		MediaMaxUploadBytes:  mediaMaxUploadBytes,
		DemoSeedEnabled:      envBool("DEMO_SEED_ENABLED", false),
		DemoMediaSourceDir:   env("DEMO_MEDIA_SOURCE_DIR", "/opt/loopbuy/demo-media"),
		GoogleClientID:       strings.TrimSpace(os.Getenv("GOOGLE_CLIENT_ID")),
		GoogleClientSecret:   strings.TrimSpace(os.Getenv("GOOGLE_CLIENT_SECRET")),
		GoogleRedirectURIs:   splitCSV(os.Getenv("GOOGLE_REDIRECT_URIS")),
		BFFSharedSecret:      strings.TrimSpace(os.Getenv("BFF_SHARED_SECRET")),
		ResendAPIKey:         strings.TrimSpace(os.Getenv("RESEND_API_KEY")),
		ResendBaseURL:        strings.TrimRight(env("RESEND_BASE_URL", "https://api.resend.com"), "/"),
		ResendFrom:           strings.TrimSpace(os.Getenv("RESEND_FROM")),
		ResendMaxEmailsHour:  resendMaxEmailsHour,
		EmailVerificationURL: strings.TrimSpace(os.Getenv("EMAIL_VERIFICATION_URL")),
		EmailVerificationTTL: emailVerificationTTL,

		OpenAIAPIKey:              os.Getenv("OPENAI_API_KEY"),
		OpenAIBaseURL:             strings.TrimRight(env("OPENAI_BASE_URL", "https://api.openai.com"), "/"),
		OpenAIEmbeddingModel:      env("OPENAI_EMBEDDING_MODEL", "text-embedding-3-small"),
		OpenAIEmbeddingDimensions: dimensions,
		OpenAIMaxRequestsHour:     openAIMaxRequestsHour,
		OpenAIMaxRequestsUserDay:  openAIMaxRequestsUserDay,

		QdrantURL:        strings.TrimRight(env("QDRANT_URL", "http://qdrant:6333"), "/"),
		QdrantAPIKey:     os.Getenv("QDRANT_API_KEY"),
		QdrantCollection: env("QDRANT_COLLECTION", "loopbuy_listings_v1"),
		QdrantVectorName: env("QDRANT_VECTOR_NAME", "listing_text_v1"),

		QwenAPIKey:             strings.TrimSpace(os.Getenv("QWEN_API_KEY")),
		QwenBaseURL:            strings.TrimRight(env("QWEN_BASE_URL", "https://dashscope-intl.aliyuncs.com/compatible-mode/v1"), "/"),
		QwenModel:              env("QWEN_MODEL", "qwen3.5-flash"),
		QwenMaxRequestsHour:    qwenMaxRequestsHour,
		QwenMaxRequestsUserDay: qwenMaxRequestsUserDay,

		MLServiceURL:   strings.TrimRight(env("ML_SERVICE_URL", "http://ml:8000"), "/"),
		AIChatFallback: envBool("AI_CHAT_FALLBACK_ENABLED", true),
		WorkerInterval: workerInterval,
	}

	if len(cfg.JWTSecret) < 32 {
		return Config{}, fmt.Errorf("JWT_SECRET must contain at least 32 characters")
	}
	return cfg, nil
}

func databaseDSN() string {
	if value := os.Getenv("DATABASE_DSN"); value != "" {
		return value
	}
	user := env("BACKEND_DB_USER", "loopbuy")
	password := env("BACKEND_DB_PASSWORD", "loopbuy-local-only")
	host := env("BACKEND_DB_HOST", "backend-db")
	port := env("BACKEND_DB_PORT", "3306")
	name := env("BACKEND_DB_NAME", "loopbuy_backend")
	return fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true&collation=utf8mb4_0900_ai_ci&loc=UTC", user, password, host, port, name)
}

func env(key, fallback string) string {
	if value := strings.TrimSpace(os.Getenv(key)); value != "" {
		return value
	}
	return fallback
}

func splitCSV(value string) []string {
	parts := strings.Split(value, ",")
	result := make([]string, 0, len(parts))
	for _, part := range parts {
		if trimmed := strings.TrimSpace(part); trimmed != "" {
			result = append(result, trimmed)
		}
	}
	return result
}

func envInt(key string, fallback int) (int, error) {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback, nil
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return 0, fmt.Errorf("%s must be an integer: %w", key, err)
	}
	return parsed, nil
}

func providerRequestLimit(key string, fallback int) (int, error) {
	value, err := envInt(key, fallback)
	if err != nil || value < 1 || value > 100000 {
		return 0, fmt.Errorf("%s must be between 1 and 100000", key)
	}
	return value, nil
}

func envInt64(key string, fallback int64) (int64, error) {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback, nil
	}
	parsed, err := strconv.ParseInt(value, 10, 64)
	if err != nil {
		return 0, fmt.Errorf("%s must be an integer: %w", key, err)
	}
	return parsed, nil
}

func envDuration(key string, fallback time.Duration) (time.Duration, error) {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback, nil
	}
	parsed, err := time.ParseDuration(value)
	if err != nil {
		return 0, fmt.Errorf("%s must be a duration: %w", key, err)
	}
	return parsed, nil
}

func envBool(key string, fallback bool) bool {
	value := strings.TrimSpace(os.Getenv(key))
	if value == "" {
		return fallback
	}
	parsed, err := strconv.ParseBool(value)
	if err != nil {
		return fallback
	}
	return parsed
}
