package auth

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strings"

	"google.golang.org/api/idtoken"
)

const googleTokenEndpoint = "https://oauth2.googleapis.com/token"

var (
	ErrGoogleAuthorization = errors.New("google authorization failed")
	pkceVerifierPattern    = regexp.MustCompile(`^[A-Za-z0-9._~-]{43,128}$`)
)

// GoogleIdentity contains only claims cryptographically verified by Google's
// ID-token validator. OAuth access and refresh tokens are deliberately not
// persisted because LoopBuy needs identity, not delegated Google API access.
type GoogleIdentity struct {
	Subject        string
	Email          string
	EmailVerified  bool
	Name           string
	HostedDomain   string
	CanLinkByEmail bool
}

type GoogleAuthenticator interface {
	Exchange(context.Context, string, string, string) (GoogleIdentity, error)
}

type GoogleConfig struct {
	ClientID     string
	ClientSecret string
	RedirectURIs []string
	TokenURL     string
}

type GoogleClient struct {
	clientID      string
	clientSecret  string
	redirectURIs  map[string]struct{}
	tokenURL      string
	httpClient    *http.Client
	validateToken func(context.Context, string, string) (*idtoken.Payload, error)
}

func NewGoogleClient(config GoogleConfig, httpClient *http.Client) (*GoogleClient, error) {
	config.ClientID = strings.TrimSpace(config.ClientID)
	config.ClientSecret = strings.TrimSpace(config.ClientSecret)
	if config.ClientID == "" || config.ClientSecret == "" {
		return nil, errors.New("google OAuth client ID and secret are required")
	}
	if httpClient == nil {
		return nil, errors.New("google OAuth HTTP client is required")
	}

	allowed := make(map[string]struct{}, len(config.RedirectURIs))
	for _, candidate := range config.RedirectURIs {
		candidate = strings.TrimSpace(candidate)
		parsed, err := url.Parse(candidate)
		if err != nil || parsed.Scheme == "" || parsed.Host == "" || parsed.Fragment != "" {
			return nil, fmt.Errorf("invalid Google redirect URI %q", candidate)
		}
		if parsed.Scheme != "https" && !(parsed.Scheme == "http" && isLoopbackHost(parsed.Hostname())) {
			return nil, fmt.Errorf("Google redirect URI %q must use HTTPS except on loopback", candidate)
		}
		allowed[candidate] = struct{}{}
	}
	if len(allowed) == 0 {
		return nil, errors.New("at least one Google redirect URI is required")
	}

	tokenURL := strings.TrimSpace(config.TokenURL)
	if tokenURL == "" {
		tokenURL = googleTokenEndpoint
	}
	validator, err := idtoken.NewValidator(context.Background(), idtoken.WithHTTPClient(httpClient))
	if err != nil {
		return nil, fmt.Errorf("create Google ID-token validator: %w", err)
	}
	return &GoogleClient{
		clientID: config.ClientID, clientSecret: config.ClientSecret,
		redirectURIs: allowed, tokenURL: tokenURL, httpClient: httpClient,
		validateToken: validator.Validate,
	}, nil
}

func (client *GoogleClient) Exchange(ctx context.Context, code, codeVerifier, redirectURI string) (GoogleIdentity, error) {
	code = strings.TrimSpace(code)
	redirectURI = strings.TrimSpace(redirectURI)
	if code == "" || len(code) > 4096 || !pkceVerifierPattern.MatchString(codeVerifier) {
		return GoogleIdentity{}, ErrGoogleAuthorization
	}
	if _, allowed := client.redirectURIs[redirectURI]; !allowed {
		return GoogleIdentity{}, ErrGoogleAuthorization
	}

	form := url.Values{
		"client_id":     {client.clientID},
		"client_secret": {client.clientSecret},
		"code":          {code},
		"code_verifier": {codeVerifier},
		"grant_type":    {"authorization_code"},
		"redirect_uri":  {redirectURI},
	}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, client.tokenURL, strings.NewReader(form.Encode()))
	if err != nil {
		return GoogleIdentity{}, ErrGoogleAuthorization
	}
	request.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	request.Header.Set("Accept", "application/json")

	response, err := client.httpClient.Do(request)
	if err != nil {
		return GoogleIdentity{}, ErrGoogleAuthorization
	}
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 1<<20))
		return GoogleIdentity{}, ErrGoogleAuthorization
	}
	var tokenResult struct {
		IDToken string `json:"id_token"`
	}
	decoder := json.NewDecoder(io.LimitReader(response.Body, 1<<20))
	if err := decoder.Decode(&tokenResult); err != nil || strings.TrimSpace(tokenResult.IDToken) == "" {
		return GoogleIdentity{}, ErrGoogleAuthorization
	}

	payload, err := client.validateToken(ctx, tokenResult.IDToken, client.clientID)
	if err != nil || payload == nil || (payload.Issuer != "accounts.google.com" && payload.Issuer != "https://accounts.google.com") {
		return GoogleIdentity{}, ErrGoogleAuthorization
	}
	email, emailOK := payload.Claims["email"].(string)
	emailVerified, verifiedOK := payload.Claims["email_verified"].(bool)
	name, _ := payload.Claims["name"].(string)
	hostedDomain, _ := payload.Claims["hd"].(string)
	email = strings.ToLower(strings.TrimSpace(email))
	if payload.Subject == "" || len(payload.Subject) > 255 || !emailOK || !verifiedOK || !emailVerified || email == "" || len(email) > 254 {
		return GoogleIdentity{}, ErrGoogleAuthorization
	}
	emailDomain := ""
	if separator := strings.LastIndexByte(email, '@'); separator >= 0 {
		emailDomain = email[separator+1:]
	}
	hostedDomain = strings.ToLower(strings.TrimSpace(hostedDomain))
	return GoogleIdentity{
		Subject: payload.Subject, Email: email, EmailVerified: true, Name: strings.TrimSpace(name),
		HostedDomain:   hostedDomain,
		CanLinkByEmail: emailDomain == "gmail.com" || (hostedDomain != "" && hostedDomain == emailDomain),
	}, nil
}

func isLoopbackHost(host string) bool {
	host = strings.ToLower(strings.TrimSpace(host))
	return host == "localhost" || host == "127.0.0.1" || host == "::1"
}
