package auth

import "testing"

func TestEmailVerificationTokensAreOpaqueAndHashable(t *testing.T) {
	t.Parallel()
	secret := "test-secret-with-at-least-thirty-two-characters"
	first, firstHash, err := NewEmailVerificationToken(secret)
	if err != nil {
		t.Fatalf("NewEmailVerificationToken() error = %v", err)
	}
	second, secondHash, err := NewEmailVerificationToken(secret)
	if err != nil {
		t.Fatalf("NewEmailVerificationToken() second error = %v", err)
	}
	if len(first) != 90 || len(firstHash) != 64 || HashEmailVerificationToken(first) != firstHash || !ValidEmailVerificationToken(secret, first) {
		t.Fatalf("unexpected token lengths plain=%d hash=%d", len(first), len(firstHash))
	}
	if first == second || firstHash == secondHash {
		t.Fatal("verification token generator repeated output")
	}
	replacement := byte('A')
	if first[len(first)-1] == replacement {
		replacement = 'B'
	}
	tampered := first[:len(first)-1] + string(replacement)
	if ValidEmailVerificationToken(secret, tampered) || ValidEmailVerificationToken("different-secret-with-at-least-thirty-two-chars", first) {
		t.Fatal("tampered or wrong-key verification token was accepted")
	}
}
