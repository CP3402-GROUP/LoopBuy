package auth

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"errors"
	"strings"
)

const emailVerificationDomain = "loopbuy-email-verification-v1\x00"

func NewEmailVerificationToken(secret string) (plain string, hash string, err error) {
	if len(secret) < 32 {
		return "", "", errors.New("email verification secret must contain at least 32 characters")
	}
	raw := make([]byte, 32)
	if _, err := rand.Read(raw); err != nil {
		return "", "", err
	}
	nonce := base64.RawURLEncoding.EncodeToString(raw)
	signature := signEmailVerificationNonce(secret, nonce)
	plain = "v1." + nonce + "." + signature
	return plain, HashEmailVerificationToken(plain), nil
}

func HashEmailVerificationToken(plain string) string {
	digest := sha256.Sum256([]byte(plain))
	return hex.EncodeToString(digest[:])
}

func ValidEmailVerificationToken(secret, plain string) bool {
	if len(secret) < 32 {
		return false
	}
	parts := strings.Split(plain, ".")
	if len(parts) != 3 || parts[0] != "v1" || len(parts[1]) != 43 || len(parts[2]) != 43 {
		return false
	}
	nonceBytes, err := base64.RawURLEncoding.DecodeString(parts[1])
	if err != nil || base64.RawURLEncoding.EncodeToString(nonceBytes) != parts[1] {
		return false
	}
	expected, err := base64.RawURLEncoding.DecodeString(signEmailVerificationNonce(secret, parts[1]))
	if err != nil {
		return false
	}
	provided, err := base64.RawURLEncoding.DecodeString(parts[2])
	return err == nil && base64.RawURLEncoding.EncodeToString(provided) == parts[2] && hmac.Equal(provided, expected)
}

func signEmailVerificationNonce(secret, nonce string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(emailVerificationDomain))
	_, _ = mac.Write([]byte(nonce))
	return base64.RawURLEncoding.EncodeToString(mac.Sum(nil))
}
