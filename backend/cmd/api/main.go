package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/ai"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/auth"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/config"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/database"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/httpapi"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/indexer"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/mailer"
	localmedia "github.com/CP3402-GROUP/LoopBuy/backend/internal/media"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/ml"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/store"
)

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{Level: slog.LevelInfo}))
	if err := run(logger); err != nil {
		logger.Error("api stopped", "error", err)
		os.Exit(1)
	}
}

func run(logger *slog.Logger) error {
	cfg, err := config.Load()
	if err != nil {
		return err
	}
	db, err := database.Open(database.Config{DSN: cfg.DatabaseDSN})
	if err != nil {
		return err
	}
	defer db.Close()

	migrationCtx, migrationCancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer migrationCancel()
	if err := database.Migrate(migrationCtx, db); err != nil {
		return err
	}
	mediaStorage, err := localmedia.New(localmedia.Config{
		Root: cfg.MediaStorageRoot, PublicBaseURL: cfg.MediaPublicBaseURL,
		MaxUploadBytes: cfg.MediaMaxUploadBytes,
	})
	if err != nil {
		return err
	}
	if cfg.DemoSeedEnabled {
		demoURLs, seedErr := mediaStorage.EnsureDemoAssets(cfg.DemoMediaSourceDir)
		if seedErr != nil {
			return seedErr
		}
		seedCtx, seedCancel := context.WithTimeout(context.Background(), time.Minute)
		defer seedCancel()
		if seedErr := database.SeedDemo(seedCtx, db, database.DemoSeedConfig{ImageURLs: demoURLs}); seedErr != nil {
			return seedErr
		}
		logger.Info("demo marketplace content is ready", "listings", len(demoURLs))
	}

	storeValue := store.New(db)
	tokenManager := auth.NewManager(cfg.JWTSecret, cfg.AccessTokenTTL)
	mlClient := ml.NewClient(cfg.MLServiceURL, &http.Client{Timeout: 6 * time.Second})
	if !cfg.ScamModerationEnabled {
		logger.Warn("automated scam moderation is disabled; listings will publish as not screened")
	}

	var googleAuth auth.GoogleAuthenticator
	if cfg.GoogleClientID != "" || cfg.GoogleClientSecret != "" || len(cfg.GoogleRedirectURIs) > 0 {
		if cfg.GoogleClientID == "" || cfg.GoogleClientSecret == "" || len(cfg.GoogleRedirectURIs) == 0 {
			return errors.New("GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, and GOOGLE_REDIRECT_URIS must be configured together")
		}
		googleClient, createErr := auth.NewGoogleClient(auth.GoogleConfig{
			ClientID: cfg.GoogleClientID, ClientSecret: cfg.GoogleClientSecret, RedirectURIs: cfg.GoogleRedirectURIs,
		}, &http.Client{Timeout: 10 * time.Second})
		if createErr != nil {
			return createErr
		}
		googleAuth = googleClient
	} else {
		logger.Warn("Google OAuth is not configured")
	}

	var verificationMailer mailer.VerificationSender
	if cfg.ResendAPIKey != "" {
		if cfg.ResendFrom == "" || cfg.EmailVerificationURL == "" {
			return errors.New("RESEND_FROM and EMAIL_VERIFICATION_URL are required when RESEND_API_KEY is configured")
		}
		resendClient, createErr := mailer.NewResendClient(mailer.ResendConfig{
			BaseURL: cfg.ResendBaseURL, APIKey: cfg.ResendAPIKey, From: cfg.ResendFrom,
			VerificationURL: cfg.EmailVerificationURL,
		}, &http.Client{Timeout: 8 * time.Second})
		if createErr != nil {
			return createErr
		}
		verificationMailer = resendClient
	} else {
		logger.Warn("Resend is not configured; password registration cannot deliver verification email")
	}

	var embedder ai.Embedder
	if cfg.OpenAIAPIKey != "" {
		embedderClient, createErr := ai.NewOpenAIEmbedder(ai.OpenAIEmbedderConfig{
			BaseURL: cfg.OpenAIBaseURL, APIKey: cfg.OpenAIAPIKey, Model: cfg.OpenAIEmbeddingModel,
			Dimensions: cfg.OpenAIEmbeddingDimensions,
		}, &http.Client{Timeout: 15 * time.Second})
		if createErr != nil {
			return createErr
		}
		embedder = embedderClient
	} else {
		logger.Warn("OPENAI_API_KEY is not configured; vector indexing and semantic search are disabled")
	}

	vectorStore, err := ai.NewQdrantVectorStore(ai.QdrantConfig{
		BaseURL: cfg.QdrantURL, APIKey: cfg.QdrantAPIKey, Collection: cfg.QdrantCollection,
		VectorName: cfg.QdrantVectorName, VectorSize: cfg.OpenAIEmbeddingDimensions, Distance: "Cosine",
	}, &http.Client{Timeout: 8 * time.Second})
	if err != nil {
		return err
	}
	var vectors ai.VectorStore = vectorStore

	var chatModel ai.ChatModel
	if cfg.QwenAPIKey != "" && cfg.QwenBaseURL != "" {
		temperature := 0.2
		enableThinking := false
		client, createErr := ai.NewQwenChatModel(ai.QwenChatConfig{
			BaseURL: cfg.QwenBaseURL, APIKey: cfg.QwenAPIKey, Model: cfg.QwenModel,
			Temperature: &temperature, MaxCompletionTokens: 800, EnableThinking: &enableThinking,
		}, &http.Client{Timeout: 22 * time.Second})
		if createErr != nil {
			return createErr
		}
		chatModel = client
	} else {
		logger.Warn("Qwen is not configured; assistant will use deterministic RAG fallback")
	}

	router := httpapi.New(httpapi.Config{
		DB: db, Store: storeValue, Tokens: tokenManager, Google: googleAuth,
		Media:              mediaStorage,
		VerificationMailer: verificationMailer, VerificationURL: cfg.EmailVerificationURL,
		VerificationTTL: cfg.EmailVerificationTTL, VerificationSecret: cfg.JWTSecret,
		EmailHourlyLimit: cfg.ResendMaxEmailsHour,
		ML:               mlClient, ScamModerationEnabled: cfg.ScamModerationEnabled, Embedder: embedder,
		Vectors: vectors, Chat: chatModel, Logger: logger, RefreshTTL: cfg.RefreshTTL,
		OpenAIMaxRequestsHour: cfg.OpenAIMaxRequestsHour, OpenAIMaxRequestsUserDay: cfg.OpenAIMaxRequestsUserDay,
		QwenMaxRequestsHour: cfg.QwenMaxRequestsHour, QwenMaxRequestsUserDay: cfg.QwenMaxRequestsUserDay,
		CORSOrigins: cfg.CORSOrigins, ChatFallback: cfg.AIChatFallback, BFFSharedSecret: cfg.BFFSharedSecret,
	})
	server := &http.Server{
		Addr: cfg.HTTPAddr, Handler: router,
		ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 20 * time.Second,
		WriteTimeout: 65 * time.Second, IdleTimeout: 90 * time.Second,
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	worker := indexer.New(storeValue, embedder, vectors, logger, indexer.Config{
		Interval: cfg.WorkerInterval, EmbeddingModel: cfg.OpenAIEmbeddingModel,
		Dimensions: cfg.OpenAIEmbeddingDimensions, Collection: cfg.QdrantCollection,
		VectorName: cfg.QdrantVectorName, OpenAIMaxRequestsHour: cfg.OpenAIMaxRequestsHour,
		OpenAIMaxRequestsUserDay: cfg.OpenAIMaxRequestsUserDay,
	})
	go worker.Run(ctx)

	serveError := make(chan error, 1)
	go func() {
		logger.Info("api listening", "address", cfg.HTTPAddr)
		serveError <- server.ListenAndServe()
	}()

	select {
	case <-ctx.Done():
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		return server.Shutdown(shutdownCtx)
	case err := <-serveError:
		if errors.Is(err, http.ErrServerClosed) {
			return nil
		}
		return err
	}
}
