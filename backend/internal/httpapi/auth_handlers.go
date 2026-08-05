package httpapi

import (
	"crypto/sha256"
	"errors"
	"net/http"
	"net/mail"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/auth"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/model"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/store"
	"golang.org/x/crypto/bcrypt"
)

var usernamePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{2,49}$`)

var dummyPasswordHash = func() []byte {
	hash, err := bcrypt.GenerateFromPassword([]byte("loopbuy-invalid-account-password"), 12)
	if err != nil {
		panic(err)
	}
	return hash
}()

type credentialsRequest struct {
	Username string `json:"username"`
	Email    string `json:"email"`
	Password string `json:"password"`
}

type tokenResponse struct {
	AccessToken      string     `json:"access_token"`
	TokenType        string     `json:"token_type"`
	ExpiresAt        time.Time  `json:"expires_at"`
	RefreshToken     string     `json:"refresh_token"`
	RefreshExpiresAt time.Time  `json:"refresh_expires_at"`
	User             model.User `json:"user"`
}

func (s *Server) register(response http.ResponseWriter, request *http.Request) {
	var input credentialsRequest
	if err := decodeJSON(response, request, &input); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}
	input.Username = strings.TrimSpace(input.Username)
	input.Email = strings.ToLower(strings.TrimSpace(input.Email))
	if !s.allowCredentialAttempt(response, request, "register-account", input.Email+"\x00"+input.Username, 8) {
		return
	}
	if !usernamePattern.MatchString(input.Username) {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "Username must be 3-50 characters and contain only letters, numbers, dot, dash, or underscore.")
		return
	}
	if !validEmail(input.Email) {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "A valid email address is required.")
		return
	}
	if len(input.Password) < 8 || len(input.Password) > 72 {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "Password must contain between 8 and 72 bytes.")
		return
	}
	if s.verificationMailer == nil || strings.TrimSpace(s.verificationURL) == "" {
		writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Email verification is temporarily unavailable.")
		return
	}
	if !s.acquireAuthWork(response, request) {
		return
	}
	defer s.releaseAuthWork()
	passwordHash, err := bcrypt.GenerateFromPassword([]byte(input.Password), 12)
	if err != nil {
		writeProblem(response, request, http.StatusInternalServerError, "Internal server error", "The account could not be created.")
		return
	}
	verificationToken, verificationHash, err := auth.NewEmailVerificationToken(s.verificationSecret)
	if err != nil {
		writeProblem(response, request, http.StatusInternalServerError, "Internal server error", "The account could not be created.")
		return
	}
	verificationExpiresAt := time.Now().UTC().Add(s.verificationTTL)
	user, err := s.store.CreatePendingUserWithVerification(request.Context(), input.Username, input.Email, string(passwordHash), verificationHash, verificationExpiresAt)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	link, err := verificationLink(s.verificationURL, verificationToken)
	if err != nil {
		s.logger.Error("build email verification link", "error", err, "user_id", user.UserID)
		writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "The account was created, but its verification email could not be delivered. Request a new verification email.")
		return
	}
	if err := s.store.ReserveEmailDelivery(request.Context(), "verification", s.emailHourlyLimit); err != nil {
		if errors.Is(err, store.ErrRateLimited) {
			response.Header().Set("Retry-After", "3600")
			writeProblem(response, request, http.StatusTooManyRequests, "Email delivery limit reached", "The account was created, but email delivery is temporarily at capacity. Request a new verification email later.")
			return
		}
		s.logger.Error("reserve registration email delivery", "error", err, "user_id", user.UserID)
		writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "The account was created, but its verification email could not be delivered. Request a new verification email.")
		return
	}
	if err := s.verificationMailer.SendVerification(request.Context(), user.Email, user.Username, link, "email-verify-"+verificationHash); err != nil {
		s.logger.Error("send registration verification email", "error", err, "user_id", user.UserID)
		writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "The account was created, but its verification email could not be delivered. Request a new verification email.")
		return
	}
	writeJSON(response, http.StatusAccepted, map[string]string{"status": "verification_required", "email": user.Email})
}

func (s *Server) login(response http.ResponseWriter, request *http.Request) {
	var input credentialsRequest
	if err := decodeJSON(response, request, &input); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}
	input.Email = strings.ToLower(strings.TrimSpace(input.Email))
	if !s.allowCredentialAttempt(response, request, "login-account", input.Email, 12) {
		return
	}
	if !s.acquireAuthWork(response, request) {
		return
	}
	defer s.releaseAuthWork()
	account, err := s.store.FindUserForLogin(request.Context(), input.Email)
	// Google-only accounts deliberately have no password hash. Run the same
	// deliberately expensive dummy comparison used for an unknown address so
	// their existence cannot be inferred from a much faster bcrypt failure.
	passwordHash := dummyPasswordHash
	if err == nil && strings.TrimSpace(account.PasswordHash) != "" {
		passwordHash = []byte(account.PasswordHash)
	}
	passwordMatches := bcrypt.CompareHashAndPassword(passwordHash, []byte(input.Password)) == nil
	if err != nil && !errors.Is(err, store.ErrNotFound) {
		s.logger.Error("login account lookup", "error", err)
		writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Authentication is temporarily unavailable.")
		return
	}
	if err != nil || !passwordMatches || account.User.Status != "active" {
		// Keep login failures deliberately uniform to avoid account enumeration.
		writeProblem(response, request, http.StatusUnauthorized, "Unauthorized", "Email or password is incorrect.")
		return
	}
	if !account.User.EmailVerified {
		writeProblem(response, request, http.StatusForbidden, "Email not verified", "Verify your email address before signing in.")
		return
	}
	account.User, err = s.store.GetUser(request.Context(), account.User.UserID, true)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	tokens, err := s.issueTokens(request, account.User)
	if err != nil {
		writeProblem(response, request, http.StatusInternalServerError, "Internal server error", "A session could not be started.")
		return
	}
	writeJSON(response, http.StatusOK, tokens)
}

func (s *Server) googleLogin(response http.ResponseWriter, request *http.Request) {
	if s.google == nil {
		writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Google sign-in is not configured.")
		return
	}
	var input struct {
		Code         string `json:"code"`
		CodeVerifier string `json:"code_verifier"`
		RedirectURI  string `json:"redirect_uri"`
	}
	if err := decodeJSON(response, request, &input); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}
	if !s.allowCredentialAttempt(response, request, "google-exchange", "google", 6) {
		return
	}
	if !s.acquireAuthWork(response, request) {
		return
	}
	defer s.releaseAuthWork()
	identity, err := s.google.Exchange(request.Context(), input.Code, input.CodeVerifier, input.RedirectURI)
	if err != nil || !identity.EmailVerified || !validEmail(identity.Email) {
		writeProblem(response, request, http.StatusUnauthorized, "Unauthorized", "Google authorization is invalid or expired.")
		return
	}
	refreshToken, refreshHash, err := auth.NewRefreshToken()
	if err != nil {
		writeProblem(response, request, http.StatusInternalServerError, "Internal server error", "A session could not be started.")
		return
	}
	refreshExpiresAt := time.Now().UTC().Add(s.refreshTTL)
	user, err := s.store.AuthenticateGoogle(request.Context(), store.GoogleIdentityInput{
		Subject: identity.Subject, Email: identity.Email, Username: googleUsername(identity), FullName: truncateRunes(identity.Name, 100),
		CanLinkByEmail: identity.CanLinkByEmail,
	}, refreshHash, refreshExpiresAt)
	if err != nil {
		switch {
		case errors.Is(err, store.ErrEmailUnverified):
			writeProblem(response, request, http.StatusConflict, "Email verification required", "An unverified password account already uses this email. Verify it before linking Google.")
		case errors.Is(err, store.ErrIdentityConflict), errors.Is(err, store.ErrConflict):
			writeProblem(response, request, http.StatusConflict, "Identity conflict", "This Google identity cannot be linked automatically.")
		case errors.Is(err, store.ErrForbidden):
			writeProblem(response, request, http.StatusForbidden, "Forbidden", "This account cannot sign in.")
		default:
			s.logger.Error("authenticate Google identity", "error", err)
			writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Google sign-in is temporarily unavailable.")
		}
		return
	}
	accessToken, accessExpiresAt, err := s.tokens.IssueAccessToken(user.UserID, user.Role)
	if err != nil {
		writeProblem(response, request, http.StatusInternalServerError, "Internal server error", "A session could not be started.")
		return
	}
	writeJSON(response, http.StatusOK, tokenResponse{
		AccessToken: accessToken, TokenType: "Bearer", ExpiresAt: accessExpiresAt,
		RefreshToken: refreshToken, RefreshExpiresAt: refreshExpiresAt, User: user,
	})
}

func (s *Server) verifyEmail(response http.ResponseWriter, request *http.Request) {
	var input struct {
		Token string `json:"token"`
	}
	if err := decodeJSON(response, request, &input); err != nil || !auth.ValidEmailVerificationToken(s.verificationSecret, strings.TrimSpace(input.Token)) {
		writeProblem(response, request, http.StatusBadRequest, "Invalid verification token", "The verification token is invalid or expired.")
		return
	}
	tokenHash := auth.HashEmailVerificationToken(strings.TrimSpace(input.Token))
	if !s.allowCredentialAttempt(response, request, "email-verification-token", tokenHash, 12) {
		return
	}
	if err := s.store.VerifyEmailToken(request.Context(), tokenHash); err != nil {
		if errors.Is(err, store.ErrInvalidVerificationToken) {
			writeProblem(response, request, http.StatusBadRequest, "Invalid verification token", "The verification token is invalid or expired.")
			return
		}
		s.logger.Error("verify email token", "error", err)
		writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Email verification is temporarily unavailable.")
		return
	}
	writeJSON(response, http.StatusNoContent, nil)
}

func (s *Server) resendVerification(response http.ResponseWriter, request *http.Request) {
	var input struct {
		Email string `json:"email"`
	}
	if err := decodeJSON(response, request, &input); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}
	input.Email = strings.ToLower(strings.TrimSpace(input.Email))
	if !validEmail(input.Email) {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "A valid email address is required.")
		return
	}
	if !s.allowCredentialAttempt(response, request, "email-verification-resend", input.Email, 4) {
		return
	}
	if s.verificationMailer == nil || strings.TrimSpace(s.verificationURL) == "" {
		writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Email verification is temporarily unavailable.")
		return
	}
	plain, tokenHash, err := auth.NewEmailVerificationToken(s.verificationSecret)
	if err != nil {
		writeProblem(response, request, http.StatusInternalServerError, "Internal server error", "The request could not be completed.")
		return
	}
	recipient, err := s.store.CreateEmailVerification(request.Context(), input.Email, tokenHash, time.Now().UTC().Add(s.verificationTTL))
	if errors.Is(err, store.ErrNotFound) || errors.Is(err, store.ErrInvalidState) || errors.Is(err, store.ErrRateLimited) {
		writeJSON(response, http.StatusAccepted, map[string]string{"status": "accepted"})
		return
	}
	if err != nil {
		s.logger.Error("create email verification", "error", err)
		writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Email verification is temporarily unavailable.")
		return
	}
	link, err := verificationLink(s.verificationURL, plain)
	if err != nil {
		s.logger.Error("build verification resend link", "error", err, "user_id", recipient.UserID)
		writeJSON(response, http.StatusAccepted, map[string]string{"status": "accepted"})
		return
	}
	if err := s.store.ReserveEmailDelivery(request.Context(), "verification", s.emailHourlyLimit); err != nil {
		if !errors.Is(err, store.ErrRateLimited) {
			s.logger.Error("reserve verification resend delivery", "error", err, "user_id", recipient.UserID)
		}
		writeJSON(response, http.StatusAccepted, map[string]string{"status": "accepted"})
		return
	}
	err = s.verificationMailer.SendVerification(request.Context(), recipient.Email, recipient.Username, link, "email-verify-"+tokenHash)
	if err != nil {
		// Keep the public response indistinguishable from unknown/already-verified
		// addresses. The user can retry and the previous token remains single-use.
		s.logger.Error("resend verification email", "error", err, "user_id", recipient.UserID)
	}
	writeJSON(response, http.StatusAccepted, map[string]string{"status": "accepted"})
}

func verificationLink(baseURL, token string) (string, error) {
	parsed, err := url.Parse(strings.TrimSpace(baseURL))
	if err != nil || parsed.Scheme == "" || parsed.Host == "" ||
		(parsed.Scheme != "https" && !(parsed.Scheme == "http" && loopbackHostname(parsed.Hostname()))) {
		return "", errors.New("invalid verification URL")
	}
	query := parsed.Query()
	query.Set("token", token)
	parsed.RawQuery = query.Encode()
	return parsed.String(), nil
}

func loopbackHostname(host string) bool {
	host = strings.ToLower(strings.TrimSpace(host))
	return host == "localhost" || host == "127.0.0.1" || host == "::1"
}

func googleUsername(identity auth.GoogleIdentity) string {
	local := identity.Email
	if at := strings.IndexByte(local, '@'); at >= 0 {
		local = local[:at]
	}
	var base strings.Builder
	for _, character := range local {
		isAlphaNumeric := (character >= 'a' && character <= 'z') || (character >= 'A' && character <= 'Z') || (character >= '0' && character <= '9')
		if isAlphaNumeric || (base.Len() > 0 && (character == '_' || character == '.' || character == '-')) {
			base.WriteRune(character)
		}
		if base.Len() >= 34 {
			break
		}
	}
	if base.Len() < 3 {
		base.Reset()
		base.WriteString("buyer")
	}
	digest := sha256.Sum256([]byte(identity.Subject))
	return base.String() + "-" + stringHex(digest[:6])
}

func truncateRunes(value string, limit int) string {
	runes := []rune(strings.TrimSpace(value))
	if len(runes) > limit {
		runes = runes[:limit]
	}
	return string(runes)
}

func (s *Server) issueTokens(request *http.Request, user model.User) (tokenResponse, error) {
	accessToken, accessExpiresAt, err := s.tokens.IssueAccessToken(user.UserID, user.Role)
	if err != nil {
		return tokenResponse{}, err
	}
	refreshToken, refreshHash, err := auth.NewRefreshToken()
	if err != nil {
		return tokenResponse{}, err
	}
	refreshExpiresAt := time.Now().UTC().Add(s.refreshTTL)
	if err := s.store.SaveRefreshToken(request.Context(), user.UserID, refreshHash, refreshExpiresAt); err != nil {
		return tokenResponse{}, err
	}
	return tokenResponse{
		AccessToken: accessToken, TokenType: "Bearer", ExpiresAt: accessExpiresAt,
		RefreshToken: refreshToken, RefreshExpiresAt: refreshExpiresAt, User: user,
	}, nil
}

func (s *Server) refresh(response http.ResponseWriter, request *http.Request) {
	var input struct {
		RefreshToken string `json:"refresh_token"`
	}
	if err := decodeJSON(response, request, &input); err != nil || strings.TrimSpace(input.RefreshToken) == "" {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", "A refresh_token is required.")
		return
	}
	refreshHash := auth.HashRefreshToken(input.RefreshToken)
	if !s.allowCredentialAttempt(response, request, "refresh-token", refreshHash, 60) {
		return
	}
	newRefresh, newHash, err := auth.NewRefreshToken()
	if err != nil {
		writeProblem(response, request, http.StatusInternalServerError, "Internal server error", "The token could not be refreshed.")
		return
	}
	refreshExpiresAt := time.Now().UTC().Add(s.refreshTTL)
	user, err := s.store.RotateRefreshToken(request.Context(), refreshHash, newHash, refreshExpiresAt)
	if err != nil {
		if !errors.Is(err, store.ErrNotFound) && !errors.Is(err, store.ErrConflict) {
			s.logger.Error("rotate refresh token", "error", err)
			writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Authentication is temporarily unavailable.")
			return
		}
		writeProblem(response, request, http.StatusUnauthorized, "Unauthorized", "The refresh token is invalid or expired.")
		return
	}
	accessToken, accessExpiresAt, err := s.tokens.IssueAccessToken(user.UserID, user.Role)
	if err != nil {
		writeProblem(response, request, http.StatusInternalServerError, "Internal server error", "The token could not be refreshed.")
		return
	}
	writeJSON(response, http.StatusOK, tokenResponse{
		AccessToken: accessToken, TokenType: "Bearer", ExpiresAt: accessExpiresAt,
		RefreshToken: newRefresh, RefreshExpiresAt: refreshExpiresAt, User: user,
	})
}

func (s *Server) logout(response http.ResponseWriter, request *http.Request) {
	var input struct {
		RefreshToken string `json:"refresh_token"`
	}
	if err := decodeJSON(response, request, &input); err != nil || strings.TrimSpace(input.RefreshToken) == "" {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", "A refresh_token is required.")
		return
	}
	if err := s.store.RevokeRefreshTokenForUser(request.Context(), auth.HashRefreshToken(input.RefreshToken), currentClaims(request).UserID); err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusNoContent, nil)
}

func (s *Server) logoutAll(response http.ResponseWriter, request *http.Request) {
	if err := s.store.RevokeAllRefreshTokens(request.Context(), currentClaims(request).UserID); err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusNoContent, nil)
}

func validEmail(value string) bool {
	if len(value) == 0 || len(value) > 254 {
		return false
	}
	address, err := mail.ParseAddress(value)
	return err == nil && strings.EqualFold(address.Address, value) && strings.Contains(value, "@")
}

func (s *Server) allowCredentialAttempt(response http.ResponseWriter, request *http.Request, scope, value string, limit int) bool {
	digest := sha256.Sum256([]byte(value))
	// Never let an unauthenticated caller spend a victim's account-wide bucket.
	// The signed BFF client (or direct peer IP fallback) is part of the key.
	identity := s.rateLimitIdentity(request) + "|credential:" + stringHex(digest[:])
	allowed, retryAfter := s.takeRateLimit(scope, identity, limit, time.Minute)
	if allowed {
		return true
	}
	response.Header().Set("Retry-After", strconv.Itoa(retryAfter))
	writeProblem(response, request, http.StatusTooManyRequests, "Too many requests", "Too many attempts for these credentials. Try again later.")
	return false
}

func (s *Server) acquireAuthWork(response http.ResponseWriter, request *http.Request) bool {
	select {
	case s.authSlots <- struct{}{}:
		return true
	default:
		response.Header().Set("Retry-After", "1")
		writeProblem(response, request, http.StatusTooManyRequests, "Authentication busy", "Authentication is at its concurrency limit. Try again shortly.")
		return false
	}
}

func (s *Server) releaseAuthWork() {
	<-s.authSlots
}

func stringHex(value []byte) string {
	const alphabet = "0123456789abcdef"
	encoded := make([]byte, len(value)*2)
	for index, item := range value {
		encoded[index*2] = alphabet[item>>4]
		encoded[index*2+1] = alphabet[item&0x0f]
	}
	return string(encoded)
}
