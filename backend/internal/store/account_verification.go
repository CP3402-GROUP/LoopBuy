package store

import (
	"context"
	"database/sql"
	"errors"
	"strings"
	"time"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/model"
	"github.com/go-sql-driver/mysql"
)

type VerificationRecipient struct {
	UserID   int64
	Username string
	Email    string
}

type GoogleIdentityInput struct {
	Subject        string
	Email          string
	Username       string
	FullName       string
	CanLinkByEmail bool
}

func (s *Store) ReserveEmailDelivery(ctx context.Context, scope string, hourlyLimit int) error {
	if hourlyLimit < 1 || strings.TrimSpace(scope) == "" {
		return ErrInvalidState
	}
	windowStart := time.Now().UTC().Truncate(time.Hour)
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return err
	}
	defer tx.Rollback()
	if _, err := tx.ExecContext(ctx, `
		INSERT IGNORE INTO email_delivery_budgets (scope, window_start, delivery_count)
		VALUES (?, ?, 0)`, scope, windowStart); err != nil {
		return err
	}
	result, err := tx.ExecContext(ctx, `
		UPDATE email_delivery_budgets SET delivery_count = delivery_count + 1
		WHERE scope = ? AND window_start = ? AND delivery_count < ?`, scope, windowStart, hourlyLimit)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected != 1 {
		return ErrRateLimited
	}
	if _, err := tx.ExecContext(ctx, `
		DELETE FROM email_delivery_budgets WHERE window_start < DATE_SUB(?, INTERVAL 48 HOUR)`, windowStart); err != nil {
		return err
	}
	return tx.Commit()
}

func (s *Store) CreatePendingUserWithVerification(ctx context.Context, username, email, passwordHash, tokenHash string, tokenExpiresAt time.Time) (model.User, error) {
	maximumReservation := time.Now().UTC().Add(24 * time.Hour)
	if tokenExpiresAt.After(maximumReservation) {
		tokenExpiresAt = maximumReservation
	}
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.User{}, err
	}
	defer tx.Rollback()

	// Pending accounts cannot authenticate. Remove only abandoned reservations
	// that conflict with this request; the unique email/username indexes keep
	// anonymous registration from triggering an unbounded cleanup scan.
	if _, err := tx.ExecContext(ctx, `
		DELETE u FROM users u
		LEFT JOIN auth_identities ai ON ai.user_id = u.user_id
		WHERE u.email_verified_at IS NULL AND u.status = 'active'
		  AND u.created_at <= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 24 HOUR)
		  AND ai.identity_id IS NULL AND (u.email = ? OR u.username = ?)`, strings.ToLower(email), username); err != nil {
		return model.User{}, err
	}

	result, err := tx.ExecContext(ctx, `
		INSERT INTO users (username, email, password_hash, email_verified_at, role, status)
		VALUES (?, ?, ?, NULL, 'user', 'active')`, username, strings.ToLower(email), passwordHash)
	if err != nil {
		return model.User{}, normalizeDuplicate(err)
	}
	userID, err := result.LastInsertId()
	if err != nil {
		return model.User{}, err
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO user_profiles (user_id) VALUES (?)`, userID); err != nil {
		return model.User{}, err
	}
	if _, err = tx.ExecContext(ctx, `INSERT INTO carts (user_id) VALUES (?)`, userID); err != nil {
		return model.User{}, err
	}
	if _, err = tx.ExecContext(ctx, `
		INSERT INTO email_verification_tokens (token_hash, user_id, expires_at)
		VALUES (?, ?, ?)`, tokenHash, userID, tokenExpiresAt.UTC()); err != nil {
		return model.User{}, err
	}
	user, err := getUser(ctx, tx, userID, true)
	if err != nil {
		return model.User{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.User{}, err
	}
	return user, nil
}

// CreateEmailVerification issues a new token without invalidating earlier
// delivered links. Successfully consuming any token invalidates the rest; this
// avoids locking a user out when a resend provider call fails after this commit.
// ErrNotFound and ErrInvalidState are safe for callers to collapse into the
// same generic response, preventing account enumeration.
func (s *Store) CreateEmailVerification(ctx context.Context, email, tokenHash string, expiresAt time.Time) (VerificationRecipient, error) {
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return VerificationRecipient{}, err
	}
	defer tx.Rollback()

	var recipient VerificationRecipient
	var verified bool
	var createdAt time.Time
	err = tx.QueryRowContext(ctx, `
		SELECT user_id, username, email, email_verified_at IS NOT NULL, created_at
		FROM users WHERE email = ? AND status = 'active' FOR UPDATE`, strings.ToLower(email)).Scan(
		&recipient.UserID, &recipient.Username, &recipient.Email, &verified, &createdAt,
	)
	if err != nil {
		return VerificationRecipient{}, normalizeSQLError(err)
	}
	if verified {
		return VerificationRecipient{}, ErrInvalidState
	}
	reservationExpiresAt := createdAt.UTC().Add(24 * time.Hour)
	if !time.Now().UTC().Before(reservationExpiresAt) {
		return VerificationRecipient{}, ErrInvalidState
	}
	if expiresAt.After(reservationExpiresAt) {
		expiresAt = reservationExpiresAt
	}
	var issued int
	if err := tx.QueryRowContext(ctx, `
		SELECT COUNT(*) FROM email_verification_tokens
		WHERE user_id = ? AND created_at >= DATE_SUB(CURRENT_TIMESTAMP(6), INTERVAL 24 HOUR)`, recipient.UserID).Scan(&issued); err != nil {
		return VerificationRecipient{}, err
	}
	// One registration delivery plus five resends is enough for recovery while
	// placing a hard provider-cost and spam bound on an unverified account.
	if issued >= 6 {
		return VerificationRecipient{}, ErrRateLimited
	}
	if _, err := tx.ExecContext(ctx, `
		DELETE FROM email_verification_tokens
		WHERE user_id = ? AND (consumed_at IS NOT NULL OR expires_at <= CURRENT_TIMESTAMP(6))`, recipient.UserID); err != nil {
		return VerificationRecipient{}, err
	}
	if _, err := tx.ExecContext(ctx, `
		INSERT INTO email_verification_tokens (token_hash, user_id, expires_at)
		VALUES (?, ?, ?)`, tokenHash, recipient.UserID, expiresAt.UTC()); err != nil {
		return VerificationRecipient{}, err
	}
	if err := tx.Commit(); err != nil {
		return VerificationRecipient{}, err
	}
	return recipient, nil
}

func (s *Store) VerifyEmailToken(ctx context.Context, tokenHash string) error {
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return err
	}
	defer tx.Rollback()

	var userID int64
	err = tx.QueryRowContext(ctx, `
		SELECT evt.user_id
		FROM email_verification_tokens evt
		JOIN users u ON u.user_id = evt.user_id
		WHERE evt.token_hash = ? AND evt.consumed_at IS NULL
		  AND evt.expires_at > CURRENT_TIMESTAMP(6) AND u.status = 'active'
		FOR UPDATE`, tokenHash).Scan(&userID)
	if errors.Is(err, sql.ErrNoRows) {
		return ErrInvalidVerificationToken
	}
	if err != nil {
		return err
	}
	result, err := tx.ExecContext(ctx, `
		UPDATE email_verification_tokens SET consumed_at = CURRENT_TIMESTAMP(6)
		WHERE token_hash = ? AND consumed_at IS NULL`, tokenHash)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected != 1 {
		return ErrInvalidVerificationToken
	}
	if _, err := tx.ExecContext(ctx, `
		UPDATE users SET email_verified_at = COALESCE(email_verified_at, CURRENT_TIMESTAMP(6)),
		updated_at = CURRENT_TIMESTAMP(6) WHERE user_id = ? AND status = 'active'`, userID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `
		UPDATE email_verification_tokens SET consumed_at = CURRENT_TIMESTAMP(6)
		WHERE user_id = ? AND consumed_at IS NULL`, userID); err != nil {
		return err
	}
	return tx.Commit()
}

// AuthenticateGoogle resolves the stable Google subject first. Only when the
// subject is new may it link by an email for which Google is authoritative.
// Such proof may safely recover an unverified pending reservation, but its
// password is discarded so a pre-registration attacker cannot inherit access.
func (s *Store) AuthenticateGoogle(ctx context.Context, input GoogleIdentityInput, refreshHash string, refreshExpiresAt time.Time) (model.User, error) {
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return model.User{}, err
	}
	defer tx.Rollback()

	var userID int64
	var status string
	var verified bool
	err = tx.QueryRowContext(ctx, `
		SELECT ai.user_id, u.status, u.email_verified_at IS NOT NULL
		FROM auth_identities ai JOIN users u ON u.user_id = ai.user_id
		WHERE ai.provider = 'google' AND ai.provider_subject = ? FOR UPDATE`, input.Subject).Scan(&userID, &status, &verified)
	if err == nil {
		if status != "active" || !verified {
			return model.User{}, ErrForbidden
		}
		if _, err := tx.ExecContext(ctx, `UPDATE auth_identities SET provider_email = ?, updated_at = CURRENT_TIMESTAMP(6) WHERE provider = 'google' AND provider_subject = ?`, strings.ToLower(input.Email), input.Subject); err != nil {
			return model.User{}, err
		}
	} else if !errors.Is(err, sql.ErrNoRows) {
		return model.User{}, err
	} else {
		err = tx.QueryRowContext(ctx, `
			SELECT user_id, status, email_verified_at IS NOT NULL
			FROM users WHERE email = ? FOR UPDATE`, strings.ToLower(input.Email)).Scan(&userID, &status, &verified)
		switch {
		case err == nil:
			if status != "active" {
				return model.User{}, ErrForbidden
			}
			if !input.CanLinkByEmail {
				return model.User{}, ErrIdentityConflict
			}
			var existingSubject string
			identityErr := tx.QueryRowContext(ctx, `SELECT provider_subject FROM auth_identities WHERE user_id = ? AND provider = 'google' FOR UPDATE`, userID).Scan(&existingSubject)
			if identityErr == nil && existingSubject != input.Subject {
				return model.User{}, ErrIdentityConflict
			}
			if identityErr != nil && !errors.Is(identityErr, sql.ErrNoRows) {
				return model.User{}, identityErr
			}
			if !verified {
				if _, err := tx.ExecContext(ctx, `
					UPDATE users SET password_hash = '', email_verified_at = CURRENT_TIMESTAMP(6),
					updated_at = CURRENT_TIMESTAMP(6) WHERE user_id = ?`, userID); err != nil {
					return model.User{}, err
				}
				if _, err := tx.ExecContext(ctx, `
					UPDATE email_verification_tokens SET consumed_at = CURRENT_TIMESTAMP(6)
					WHERE user_id = ? AND consumed_at IS NULL`, userID); err != nil {
					return model.User{}, err
				}
				if strings.TrimSpace(input.FullName) != "" {
					if _, err := tx.ExecContext(ctx, `
						UPDATE user_profiles SET full_name = ?, updated_at = CURRENT_TIMESTAMP(6)
						WHERE user_id = ?`, input.FullName, userID); err != nil {
						return model.User{}, err
					}
				}
			}
		case errors.Is(err, sql.ErrNoRows):
			result, insertErr := tx.ExecContext(ctx, `
				INSERT INTO users (username, email, password_hash, email_verified_at, role, status)
				VALUES (?, ?, '', CURRENT_TIMESTAMP(6), 'user', 'active')`, input.Username, strings.ToLower(input.Email))
			if insertErr != nil {
				return model.User{}, normalizeDuplicate(insertErr)
			}
			userID, err = result.LastInsertId()
			if err != nil {
				return model.User{}, err
			}
			if _, err = tx.ExecContext(ctx, `INSERT INTO user_profiles (user_id, full_name) VALUES (?, NULLIF(?, ''))`, userID, input.FullName); err != nil {
				return model.User{}, err
			}
			if _, err = tx.ExecContext(ctx, `INSERT INTO carts (user_id) VALUES (?)`, userID); err != nil {
				return model.User{}, err
			}
		default:
			return model.User{}, err
		}
		if _, err = tx.ExecContext(ctx, `
			INSERT INTO auth_identities (user_id, provider, provider_subject, provider_email)
			VALUES (?, 'google', ?, ?)`, userID, input.Subject, strings.ToLower(input.Email)); err != nil {
			return model.User{}, normalizeDuplicate(err)
		}
	}

	if _, err = tx.ExecContext(ctx, `INSERT INTO refresh_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)`, userID, refreshHash, refreshExpiresAt.UTC()); err != nil {
		return model.User{}, err
	}
	user, err := getUser(ctx, tx, userID, true)
	if err != nil {
		return model.User{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.User{}, err
	}
	return user, nil
}

func normalizeDuplicate(err error) error {
	var mysqlError *mysql.MySQLError
	if errors.As(err, &mysqlError) && mysqlError.Number == 1062 {
		return ErrConflict
	}
	return err
}
