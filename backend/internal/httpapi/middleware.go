package httpapi

import (
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"log/slog"
	"net"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/auth"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/store"
)

type rateLimitWindow struct {
	Count   int
	ResetAt time.Time
}

type contextKey string

const claimsKey contextKey = "claims"

func (s *Server) middleware(next http.Handler) http.Handler {
	return s.recoverer(s.cors(s.requestLogger(s.requestID(next))))
}

func (s *Server) requestID(next http.Handler) http.Handler {
	return http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		requestID := strings.TrimSpace(request.Header.Get("X-Request-ID"))
		if requestID == "" || len(requestID) > 128 {
			raw := make([]byte, 12)
			_, _ = rand.Read(raw)
			requestID = hex.EncodeToString(raw)
		}
		response.Header().Set("X-Request-ID", requestID)
		next.ServeHTTP(response, request)
	})
}

type statusWriter struct {
	http.ResponseWriter
	status int
}

func (writer *statusWriter) WriteHeader(status int) {
	writer.status = status
	writer.ResponseWriter.WriteHeader(status)
}

func (s *Server) requestLogger(next http.Handler) http.Handler {
	return http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		started := time.Now()
		writer := &statusWriter{ResponseWriter: response, status: http.StatusOK}
		next.ServeHTTP(writer, request)
		s.logger.Info("http request", "method", request.Method, "path", request.URL.Path, "status", writer.status, "duration_ms", time.Since(started).Milliseconds())
	})
}

func (s *Server) recoverer(next http.Handler) http.Handler {
	return http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		defer func() {
			if recovered := recover(); recovered != nil {
				s.logger.Error("http panic", "panic", recovered, "path", request.URL.Path)
				writeProblem(response, request, http.StatusInternalServerError, "Internal server error", "The request could not be completed.")
			}
		}()
		next.ServeHTTP(response, request)
	})
}

func (s *Server) cors(next http.Handler) http.Handler {
	allowed := make(map[string]struct{}, len(s.corsOrigins))
	for _, origin := range s.corsOrigins {
		allowed[origin] = struct{}{}
	}
	return http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		origin := request.Header.Get("Origin")
		if origin != "" {
			if _, ok := allowed[origin]; ok {
				response.Header().Set("Access-Control-Allow-Origin", origin)
				response.Header().Set("Vary", "Origin")
				response.Header().Set("Access-Control-Allow-Headers", "Authorization, Content-Type, X-Request-ID")
				response.Header().Set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS")
			}
		}
		if request.Method == http.MethodOptions {
			response.WriteHeader(http.StatusNoContent)
			return
		}
		next.ServeHTTP(response, request)
	})
}

func (s *Server) authenticated(next http.Handler) http.Handler {
	return http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		value := request.Header.Get("Authorization")
		if !strings.HasPrefix(value, "Bearer ") {
			writeProblem(response, request, http.StatusUnauthorized, "Unauthorized", "A bearer access token is required.")
			return
		}
		claims, err := s.tokens.ParseAccessToken(strings.TrimSpace(strings.TrimPrefix(value, "Bearer ")))
		if err != nil {
			writeProblem(response, request, http.StatusUnauthorized, "Unauthorized", "The access token is invalid or expired.")
			return
		}
		role, err := s.store.GetActiveUserRole(request.Context(), claims.UserID)
		if err != nil {
			if !errors.Is(err, store.ErrNotFound) {
				s.logger.Error("active account lookup", "error", err, "user_id", claims.UserID)
				writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Authentication is temporarily unavailable.")
				return
			}
			writeProblem(response, request, http.StatusUnauthorized, "Unauthorized", "The access token is no longer valid for an active account.")
			return
		}
		claims.Role = role
		next.ServeHTTP(response, request.WithContext(context.WithValue(request.Context(), claimsKey, claims)))
	})
}

func (s *Server) optionallyAuthenticated(next http.Handler) http.Handler {
	return http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		value := request.Header.Get("Authorization")
		if strings.HasPrefix(value, "Bearer ") {
			if claims, err := s.tokens.ParseAccessToken(strings.TrimSpace(strings.TrimPrefix(value, "Bearer "))); err == nil {
				if role, stateErr := s.store.GetActiveUserRole(request.Context(), claims.UserID); stateErr == nil {
					claims.Role = role
					request = request.WithContext(context.WithValue(request.Context(), claimsKey, claims))
				}
			}
		}
		next.ServeHTTP(response, request)
	})
}

func (s *Server) admin(next http.Handler) http.Handler {
	return s.authenticated(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		if currentClaims(request).Role != "admin" {
			writeProblem(response, request, http.StatusForbidden, "Forbidden", "Administrator access is required.")
			return
		}
		next.ServeHTTP(response, request)
	}))
}

func (s *Server) moderator(next http.Handler) http.Handler {
	return s.authenticated(http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		role := currentClaims(request).Role
		if role != "moderator" && role != "admin" {
			writeProblem(response, request, http.StatusForbidden, "Forbidden", "Moderator access is required.")
			return
		}
		next.ServeHTTP(response, request)
	}))
}

func (s *Server) rateLimited(scope string, limit int, window time.Duration, useAISlot bool, next http.Handler) http.Handler {
	return http.HandlerFunc(func(response http.ResponseWriter, request *http.Request) {
		identity := s.rateLimitIdentity(request)
		if claims := currentClaims(request); claims.UserID > 0 {
			identity = "user:" + strconv.FormatInt(claims.UserID, 10)
		}
		if allowed, retryAfter := s.takeRateLimit(scope, identity, limit, window); !allowed {
			response.Header().Set("Retry-After", strconv.Itoa(retryAfter))
			writeProblem(response, request, http.StatusTooManyRequests, "Too many requests", "The request limit has been reached. Try again later.")
			return
		}

		if useAISlot {
			select {
			case s.aiSlots <- struct{}{}:
				defer func() { <-s.aiSlots }()
			default:
				response.Header().Set("Retry-After", "1")
				writeProblem(response, request, http.StatusTooManyRequests, "Too many requests", "The AI service is at its concurrency limit. Try again shortly.")
				return
			}
		}
		next.ServeHTTP(response, request)
	})
}

func (s *Server) rateLimitIdentity(request *http.Request) string {
	if clientHash, ok := s.validBFFClient(request, time.Now().UTC()); ok {
		return "bff:" + clientHash
	}
	return "ip:" + remoteIP(request.RemoteAddr)
}

func (s *Server) validBFFClient(request *http.Request, now time.Time) (string, bool) {
	if len(s.bffSharedSecret) < 32 {
		return "", false
	}
	timestampText := strings.TrimSpace(request.Header.Get("X-LoopBuy-BFF-Timestamp"))
	clientHash := strings.TrimSpace(request.Header.Get("X-LoopBuy-BFF-Client"))
	signatureText := strings.TrimSpace(request.Header.Get("X-LoopBuy-BFF-Signature"))
	if len(timestampText) < 10 || len(timestampText) > 12 || len(clientHash) != 64 || len(signatureText) != 64 {
		return "", false
	}
	timestamp, err := strconv.ParseInt(timestampText, 10, 64)
	if err != nil {
		return "", false
	}
	delta := now.Unix() - timestamp
	if delta < -90 || delta > 90 {
		return "", false
	}
	if _, err := hex.DecodeString(clientHash); err != nil || strings.ToLower(clientHash) != clientHash {
		return "", false
	}
	provided, err := hex.DecodeString(signatureText)
	if err != nil || strings.ToLower(signatureText) != signatureText {
		return "", false
	}
	canonical := "loopbuy-bff-v1\n" + timestampText + "\n" + clientHash + "\n" + strings.ToUpper(request.Method) + "\n" + request.URL.Path
	mac := hmac.New(sha256.New, []byte(s.bffSharedSecret))
	_, _ = mac.Write([]byte(canonical))
	if !hmac.Equal(provided, mac.Sum(nil)) {
		return "", false
	}
	return clientHash, true
}

func (s *Server) takeRateLimit(scope, identity string, limit int, window time.Duration) (bool, int) {
	now := time.Now().UTC()
	key := scope + "|" + identity
	s.rateLimitMu.Lock()
	defer s.rateLimitMu.Unlock()
	entry, exists := s.rateLimits[key]
	if !exists || !now.Before(entry.ResetAt) {
		entry = rateLimitWindow{ResetAt: now.Add(window)}
	}
	if entry.Count >= limit {
		return false, max(1, int(time.Until(entry.ResetAt).Seconds()))
	}
	entry.Count++
	s.rateLimits[key] = entry
	if len(s.rateLimits) > 4096 {
		for candidate, candidateEntry := range s.rateLimits {
			if !now.Before(candidateEntry.ResetAt) {
				delete(s.rateLimits, candidate)
			}
		}
	}
	return true, 0
}

func remoteIP(remoteAddress string) string {
	host, _, err := net.SplitHostPort(remoteAddress)
	if err == nil && host != "" {
		return host
	}
	if remoteAddress == "" {
		return "unknown"
	}
	return remoteAddress
}

func currentClaims(request *http.Request) auth.Claims {
	claims, _ := request.Context().Value(claimsKey).(auth.Claims)
	return claims
}

func loggerOrDefault(logger *slog.Logger) *slog.Logger {
	if logger == nil {
		return slog.Default()
	}
	return logger
}
