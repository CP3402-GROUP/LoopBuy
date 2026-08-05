package mailer

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"html"
	"io"
	"net/http"
	"net/mail"
	"net/url"
	"strings"
)

const defaultResendBaseURL = "https://api.resend.com"

var ErrDelivery = errors.New("email delivery failed")

type VerificationSender interface {
	SendVerification(context.Context, string, string, string, string) error
}

type ResendConfig struct {
	BaseURL         string
	APIKey          string
	From            string
	VerificationURL string
}

type ResendClient struct {
	baseURL    string
	apiKey     string
	from       string
	httpClient *http.Client
}

func NewResendClient(config ResendConfig, httpClient *http.Client) (*ResendClient, error) {
	baseURL := strings.TrimRight(strings.TrimSpace(config.BaseURL), "/")
	if baseURL == "" {
		baseURL = defaultResendBaseURL
	}
	parsed, err := url.Parse(baseURL)
	if err != nil || parsed.Scheme == "" || parsed.Host == "" || (parsed.Scheme != "https" && !isLoopback(parsed.Hostname())) {
		return nil, errors.New("Resend base URL must be HTTPS except on loopback")
	}
	if strings.TrimSpace(config.APIKey) == "" {
		return nil, errors.New("Resend API key is required")
	}
	from := strings.TrimSpace(config.From)
	if _, err := mail.ParseAddress(from); err != nil {
		return nil, fmt.Errorf("invalid Resend sender address: %w", err)
	}
	if httpClient == nil {
		return nil, errors.New("Resend HTTP client is required")
	}
	if config.VerificationURL != "" {
		verificationURL, parseErr := url.Parse(strings.TrimSpace(config.VerificationURL))
		if parseErr != nil || verificationURL.Scheme == "" || verificationURL.Host == "" ||
			(verificationURL.Scheme != "https" && !(verificationURL.Scheme == "http" && isLoopback(verificationURL.Hostname()))) {
			return nil, errors.New("email verification URL must be HTTPS except on loopback")
		}
	}
	return &ResendClient{baseURL: baseURL, apiKey: config.APIKey, from: from, httpClient: httpClient}, nil
}

func (client *ResendClient) SendVerification(ctx context.Context, recipient, username, verificationLink, idempotencyKey string) error {
	address, err := mail.ParseAddress(strings.TrimSpace(recipient))
	if err != nil || address.Address != strings.TrimSpace(recipient) {
		return ErrDelivery
	}
	link, err := url.Parse(verificationLink)
	if err != nil || link.Scheme == "" || link.Host == "" || (link.Scheme != "https" && !isLoopback(link.Hostname())) {
		return ErrDelivery
	}
	if idempotencyKey == "" || len(idempotencyKey) > 256 {
		return ErrDelivery
	}

	displayName := strings.TrimSpace(username)
	if displayName == "" {
		displayName = "there"
	}
	escapedName := html.EscapeString(displayName)
	escapedLink := html.EscapeString(link.String())
	payload := struct {
		From    string   `json:"from"`
		To      []string `json:"to"`
		Subject string   `json:"subject"`
		Text    string   `json:"text"`
		HTML    string   `json:"html"`
	}{
		From: client.from, To: []string{address.Address}, Subject: "Verify your LoopBuy email",
		Text: "Hi " + displayName + ",\n\nVerify your LoopBuy email by opening this link:\n" + link.String() + "\n\nIf you did not create this account, ignore this email.",
		HTML: `<!doctype html><html><body style="font-family:Arial,sans-serif;color:#17191f;line-height:1.5">` +
			`<div style="max-width:560px;margin:32px auto;padding:32px;border:1px solid #e5e7eb;border-radius:16px">` +
			`<h1 style="margin-top:0">Verify your LoopBuy email</h1><p>Hi ` + escapedName + `,</p>` +
			`<p>Confirm your email to finish creating your marketplace account.</p>` +
			`<p><a href="` + escapedLink + `" style="display:inline-block;padding:12px 20px;border-radius:10px;background:#17191f;color:#fff;text-decoration:none">Verify email</a></p>` +
			`<p style="color:#6b7280;font-size:14px">If you did not create this account, you can ignore this email.</p></div></body></html>`,
	}
	body, err := json.Marshal(payload)
	if err != nil {
		return ErrDelivery
	}
	request, err := http.NewRequestWithContext(ctx, http.MethodPost, client.baseURL+"/emails", bytes.NewReader(body))
	if err != nil {
		return ErrDelivery
	}
	request.Header.Set("Authorization", "Bearer "+client.apiKey)
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Accept", "application/json")
	request.Header.Set("Idempotency-Key", idempotencyKey)
	request.Header.Set("User-Agent", "LoopBuy/1.0")

	response, err := client.httpClient.Do(request)
	if err != nil {
		return ErrDelivery
	}
	defer response.Body.Close()
	_, _ = io.Copy(io.Discard, io.LimitReader(response.Body, 1<<20))
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return ErrDelivery
	}
	return nil
}

func isLoopback(host string) bool {
	host = strings.ToLower(strings.TrimSpace(host))
	return host == "localhost" || host == "127.0.0.1" || host == "::1"
}
