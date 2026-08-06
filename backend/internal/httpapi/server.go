package httpapi

import (
	"context"
	"database/sql"
	"errors"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/ai"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/auth"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/mailer"
	localmedia "github.com/CP3402-GROUP/LoopBuy/backend/internal/media"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/ml"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/store"
)

type Server struct {
	db                       *sql.DB
	store                    *store.Store
	tokens                   *auth.Manager
	google                   auth.GoogleAuthenticator
	verificationMailer       mailer.VerificationSender
	verificationURL          string
	verificationTTL          time.Duration
	verificationSecret       string
	emailHourlyLimit         int
	media                    *localmedia.Storage
	ml                       *ml.Client
	embedder                 ai.Embedder
	vectors                  ai.VectorStore
	chat                     ai.ChatModel
	openAIMaxRequestsHour    int
	openAIMaxRequestsUserDay int
	qwenMaxRequestsHour      int
	qwenMaxRequestsUserDay   int
	logger                   *slog.Logger
	refreshTTL               time.Duration
	corsOrigins              []string
	chatFallback             bool
	bffSharedSecret          string
	rateLimitMu              sync.Mutex
	rateLimits               map[string]rateLimitWindow
	aiSlots                  chan struct{}
	authSlots                chan struct{}
}

type Config struct {
	DB                       *sql.DB
	Store                    *store.Store
	Tokens                   *auth.Manager
	Google                   auth.GoogleAuthenticator
	VerificationMailer       mailer.VerificationSender
	VerificationURL          string
	VerificationTTL          time.Duration
	VerificationSecret       string
	EmailHourlyLimit         int
	Media                    *localmedia.Storage
	ML                       *ml.Client
	Embedder                 ai.Embedder
	Vectors                  ai.VectorStore
	Chat                     ai.ChatModel
	OpenAIMaxRequestsHour    int
	OpenAIMaxRequestsUserDay int
	QwenMaxRequestsHour      int
	QwenMaxRequestsUserDay   int
	Logger                   *slog.Logger
	RefreshTTL               time.Duration
	CORSOrigins              []string
	ChatFallback             bool
	BFFSharedSecret          string
}

func New(config Config) http.Handler {
	if config.VerificationTTL <= 0 {
		config.VerificationTTL = 24 * time.Hour
	}
	if config.EmailHourlyLimit <= 0 {
		config.EmailHourlyLimit = 100
	}
	if config.OpenAIMaxRequestsHour <= 0 {
		config.OpenAIMaxRequestsHour = 300
	}
	if config.OpenAIMaxRequestsUserDay <= 0 {
		config.OpenAIMaxRequestsUserDay = 20
	}
	if config.QwenMaxRequestsHour <= 0 {
		config.QwenMaxRequestsHour = 100
	}
	if config.QwenMaxRequestsUserDay <= 0 {
		config.QwenMaxRequestsUserDay = 10
	}
	server := &Server{
		db: config.DB, store: config.Store, tokens: config.Tokens, google: config.Google,
		verificationMailer: config.VerificationMailer, verificationURL: config.VerificationURL,
		verificationTTL: config.VerificationTTL, verificationSecret: config.VerificationSecret,
		emailHourlyLimit: config.EmailHourlyLimit,
		media:            config.Media, ml: config.ML,
		embedder: config.Embedder, vectors: config.Vectors, chat: config.Chat,
		openAIMaxRequestsHour: config.OpenAIMaxRequestsHour, openAIMaxRequestsUserDay: config.OpenAIMaxRequestsUserDay,
		qwenMaxRequestsHour: config.QwenMaxRequestsHour, qwenMaxRequestsUserDay: config.QwenMaxRequestsUserDay,
		logger: loggerOrDefault(config.Logger), refreshTTL: config.RefreshTTL,
		corsOrigins: config.CORSOrigins, chatFallback: config.ChatFallback, bffSharedSecret: config.BFFSharedSecret,
		rateLimits: make(map[string]rateLimitWindow), aiSlots: make(chan struct{}, 8), authSlots: make(chan struct{}, 4),
	}
	mux := http.NewServeMux()

	mux.HandleFunc("GET /health/live", server.live)
	mux.HandleFunc("GET /health/ready", server.ready)
	if server.media != nil {
		mux.HandleFunc("GET /media/{path...}", server.serveMedia)
	}

	// These outer limits are proxy-wide DoS ceilings. The handlers also enforce
	// much tighter hashed account/token limits, so a WordPress BFF does not make
	// every visitor share one tiny authentication bucket.
	mux.Handle("POST /api/v1/auth/register", server.rateLimited("register-proxy", 10, time.Hour, false, http.HandlerFunc(server.register)))
	mux.Handle("POST /api/v1/auth/login", server.rateLimited("login-proxy", 30, time.Minute, false, http.HandlerFunc(server.login)))
	mux.Handle("POST /api/v1/auth/google", server.rateLimited("google-auth-proxy", 10, time.Hour, false, http.HandlerFunc(server.googleLogin)))
	mux.Handle("POST /api/v1/auth/email/verify", server.rateLimited("email-verify-proxy", 30, time.Minute, false, http.HandlerFunc(server.verifyEmail)))
	mux.Handle("POST /api/v1/auth/email/resend", server.rateLimited("email-resend-proxy", 20, time.Hour, false, http.HandlerFunc(server.resendVerification)))
	mux.Handle("POST /api/v1/auth/refresh", server.rateLimited("refresh-proxy", 60, time.Minute, false, http.HandlerFunc(server.refresh)))
	mux.Handle("POST /api/v1/auth/logout", server.authenticated(http.HandlerFunc(server.logout)))
	mux.Handle("POST /api/v1/auth/logout-all", server.authenticated(http.HandlerFunc(server.logoutAll)))

	mux.HandleFunc("GET /api/v1/categories", server.listCategories)
	mux.HandleFunc("GET /api/v1/categories/{identifier}", server.getCategory)
	mux.Handle("POST /api/v1/categories", server.admin(http.HandlerFunc(server.createCategory)))
	mux.Handle("PATCH /api/v1/categories/{id}", server.admin(http.HandlerFunc(server.updateCategory)))
	mux.Handle("DELETE /api/v1/categories/{id}", server.admin(http.HandlerFunc(server.deleteCategory)))

	mux.Handle("GET /api/v1/listings", server.optionallyAuthenticated(server.rateLimited("listing-search", 240, time.Minute, false, http.HandlerFunc(server.listListings))))
	mux.Handle("GET /api/v1/listings/{id}", server.optionallyAuthenticated(http.HandlerFunc(server.getListing)))
	mux.Handle("POST /api/v1/listings", server.authenticated(server.rateLimited("listing-create", 30, time.Hour, false, http.HandlerFunc(server.createListing))))
	mux.Handle("PATCH /api/v1/listings/{id}", server.authenticated(server.rateLimited("listing-update", 120, time.Hour, false, http.HandlerFunc(server.updateListing))))
	mux.Handle("DELETE /api/v1/listings/{id}", server.authenticated(http.HandlerFunc(server.deleteListing)))
	mux.Handle("PATCH /api/v1/listings/{id}/status", server.authenticated(http.HandlerFunc(server.updateListingStatus)))
	mux.Handle("GET /api/v1/listings/{id}/images", server.optionallyAuthenticated(http.HandlerFunc(server.listListingImages)))
	mux.Handle("POST /api/v1/listings/{id}/images", server.authenticated(http.HandlerFunc(server.addListingImage)))
	mux.Handle("POST /api/v1/listings/{id}/images/upload", server.authenticated(server.rateLimited("listing-image-upload", 60, time.Hour, false, http.HandlerFunc(server.uploadListingImage))))
	mux.Handle("PATCH /api/v1/listings/{id}/images/{imageId}", server.authenticated(http.HandlerFunc(server.updateListingImage)))
	mux.Handle("DELETE /api/v1/listings/{id}/images/{imageId}", server.authenticated(http.HandlerFunc(server.deleteListingImage)))
	mux.Handle("POST /api/v1/listings/{id}/scam-assessments", server.authenticated(server.rateLimited("listing-assessment", 30, time.Hour, false, http.HandlerFunc(server.assessListing))))
	mux.Handle("GET /api/v1/listings/{id}/scam-assessments/latest", server.authenticated(http.HandlerFunc(server.latestAssessment)))
	mux.Handle("PATCH /api/v1/admin/listings/{id}/moderation", server.moderator(http.HandlerFunc(server.moderateListing)))
	mux.Handle("GET /api/v1/admin/listings", server.moderator(http.HandlerFunc(server.listModerationQueue)))

	mux.Handle("GET /api/v1/users/me", server.authenticated(http.HandlerFunc(server.getMe)))
	mux.Handle("PATCH /api/v1/users/me", server.authenticated(http.HandlerFunc(server.updateMe)))
	mux.Handle("DELETE /api/v1/users/me", server.authenticated(http.HandlerFunc(server.deleteMe)))
	mux.Handle("GET /api/v1/users/me/listings", server.authenticated(http.HandlerFunc(server.listMyListings)))
	mux.Handle("POST /api/v1/users/me/avatar", server.authenticated(server.rateLimited("profile-avatar-upload", 20, time.Hour, false, http.HandlerFunc(server.uploadMyAvatar))))
	mux.HandleFunc("GET /api/v1/users/{id}", server.getPublicUser)
	mux.Handle("GET /api/v1/users/me/favourites", server.authenticated(http.HandlerFunc(server.listFavourites)))
	mux.Handle("PUT /api/v1/users/me/favourites/{listingId}", server.authenticated(http.HandlerFunc(server.addFavourite)))
	mux.Handle("DELETE /api/v1/users/me/favourites/{listingId}", server.authenticated(http.HandlerFunc(server.removeFavourite)))
	mux.Handle("GET /api/v1/users/me/cart", server.authenticated(http.HandlerFunc(server.getCart)))
	mux.Handle("PUT /api/v1/users/me/cart/items/{listingId}", server.authenticated(http.HandlerFunc(server.setCartItem)))
	mux.Handle("PATCH /api/v1/users/me/cart/items/{listingId}", server.authenticated(http.HandlerFunc(server.setCartItem)))
	mux.Handle("DELETE /api/v1/users/me/cart/items/{listingId}", server.authenticated(http.HandlerFunc(server.removeCartItem)))
	mux.Handle("DELETE /api/v1/users/me/cart/items", server.authenticated(http.HandlerFunc(server.clearCart)))

	mux.Handle("POST /api/v1/conversations", server.authenticated(http.HandlerFunc(server.createConversation)))
	mux.Handle("GET /api/v1/conversations", server.authenticated(http.HandlerFunc(server.listConversations)))
	mux.Handle("GET /api/v1/conversations/{id}", server.authenticated(http.HandlerFunc(server.getConversation)))
	mux.Handle("DELETE /api/v1/conversations/{id}", server.authenticated(http.HandlerFunc(server.leaveConversation)))
	mux.Handle("GET /api/v1/conversations/{id}/messages", server.authenticated(http.HandlerFunc(server.listMessages)))
	mux.Handle("POST /api/v1/conversations/{id}/messages", server.authenticated(http.HandlerFunc(server.createMessage)))
	mux.Handle("PATCH /api/v1/conversations/{id}/messages/{messageId}", server.authenticated(http.HandlerFunc(server.updateMessage)))
	mux.Handle("DELETE /api/v1/conversations/{id}/messages/{messageId}", server.authenticated(http.HandlerFunc(server.deleteMessage)))

	mux.Handle("GET /api/v1/recommendations", server.authenticated(server.rateLimited("recommendations", 60, time.Minute, true, http.HandlerFunc(server.recommendations))))
	mux.Handle("GET /api/v1/listings/{id}/similar", server.authenticated(server.rateLimited("similar", 60, time.Minute, true, http.HandlerFunc(server.similarListings))))
	mux.Handle("POST /api/v1/assistant/chat", server.authenticated(server.rateLimited("assistant", 20, time.Minute, true, http.HandlerFunc(server.statelessChat))))
	mux.Handle("POST /api/v1/ai/chat/sessions", server.authenticated(http.HandlerFunc(server.createChatSession)))
	mux.Handle("GET /api/v1/ai/chat/sessions", server.authenticated(http.HandlerFunc(server.listChatSessions)))
	mux.Handle("GET /api/v1/ai/chat/sessions/{id}", server.authenticated(http.HandlerFunc(server.getChatSession)))
	mux.Handle("PATCH /api/v1/ai/chat/sessions/{id}", server.authenticated(http.HandlerFunc(server.updateChatSession)))
	mux.Handle("DELETE /api/v1/ai/chat/sessions/{id}", server.authenticated(http.HandlerFunc(server.deleteChatSession)))
	mux.Handle("GET /api/v1/ai/chat/sessions/{id}/messages", server.authenticated(http.HandlerFunc(server.listChatMessages)))
	mux.Handle("POST /api/v1/ai/chat/sessions/{id}/messages", server.authenticated(server.rateLimited("assistant-session", 20, time.Minute, true, http.HandlerFunc(server.createChatMessage))))

	return server.middleware(mux)
}

func (s *Server) live(response http.ResponseWriter, _ *http.Request) {
	writeJSON(response, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *Server) ready(response http.ResponseWriter, request *http.Request) {
	components := map[string]string{
		"mysql": "ok", "ml": "ok", "qdrant": "ok", "embeddings": "enabled_unverified",
		"qwen": "enabled_unverified", "google_oauth": "enabled", "email_delivery": "enabled_unverified",
	}
	status := http.StatusOK
	degraded := false
	type checkResult struct {
		name string
		err  error
	}
	results := make(chan checkResult, 3)
	checks := []struct {
		name string
		fn   func(context.Context) error
	}{
		{name: "mysql", fn: s.db.PingContext},
		{name: "ml", fn: func(ctx context.Context) error {
			if s.ml == nil {
				return errors.New("ML client is disabled")
			}
			return s.ml.Health(ctx)
		}},
		{name: "qdrant", fn: func(ctx context.Context) error {
			if s.vectors == nil {
				return errors.New("vector store is disabled")
			}
			return s.vectors.Ready(ctx)
		}},
	}
	for _, check := range checks {
		check := check
		go func() {
			ctx, cancel := context.WithTimeout(request.Context(), 2*time.Second)
			defer cancel()
			results <- checkResult{name: check.name, err: check.fn(ctx)}
		}()
	}
	for range checks {
		result := <-results
		if result.err != nil {
			components[result.name] = "unavailable"
			status = http.StatusServiceUnavailable
		}
	}
	if s.embedder == nil {
		components["embeddings"] = "disabled"
		degraded = true
	}
	if s.google == nil {
		components["google_oauth"] = "disabled"
		degraded = true
	}
	if s.verificationMailer == nil || strings.TrimSpace(s.verificationURL) == "" || len(s.verificationSecret) < 32 {
		components["email_delivery"] = "disabled"
		degraded = true
	}
	if s.chat == nil {
		components["qwen"] = "disabled"
		degraded = true
		if !s.chatFallback {
			status = http.StatusServiceUnavailable
		}
	}
	overall := "ok"
	if status != http.StatusOK || degraded {
		overall = "degraded"
	}
	writeJSON(response, status, map[string]any{"status": overall, "components": components})
}
