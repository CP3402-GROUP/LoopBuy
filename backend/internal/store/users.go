package store

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/model"
	"github.com/go-sql-driver/mysql"
)

type UserWithPassword struct {
	User         model.User
	PasswordHash string
}

func (s *Store) CreateUser(ctx context.Context, username, email, passwordHash string) (model.User, error) {
	return s.createUser(ctx, username, email, passwordHash, "", time.Time{})
}

func (s *Store) CreateUserWithRefresh(ctx context.Context, username, email, passwordHash, refreshHash string, refreshExpiresAt time.Time) (model.User, error) {
	return s.createUser(ctx, username, email, passwordHash, refreshHash, refreshExpiresAt)
}

func (s *Store) createUser(ctx context.Context, username, email, passwordHash, refreshHash string, refreshExpiresAt time.Time) (model.User, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.User{}, err
	}
	defer tx.Rollback()

	result, err := tx.ExecContext(ctx, `
		INSERT INTO users (username, email, password_hash, email_verified_at, role, status)
		VALUES (?, ?, ?, CURRENT_TIMESTAMP(6), 'user', 'active')`, username, strings.ToLower(email), passwordHash)
	if err != nil {
		var mysqlError *mysql.MySQLError
		if errors.As(err, &mysqlError) && mysqlError.Number == 1062 {
			return model.User{}, ErrConflict
		}
		return model.User{}, err
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
	if refreshHash != "" {
		if _, err = tx.ExecContext(ctx, `
			INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
			VALUES (?, ?, ?)`, userID, refreshHash, refreshExpiresAt.UTC()); err != nil {
			return model.User{}, err
		}
	}
	user, err := getUser(ctx, tx, userID, true)
	if err != nil {
		return model.User{}, err
	}
	if err = tx.Commit(); err != nil {
		return model.User{}, err
	}
	return user, nil
}

func (s *Store) FindUserForLogin(ctx context.Context, email string) (UserWithPassword, error) {
	var result UserWithPassword
	err := s.db.QueryRowContext(ctx, `
		SELECT user_id, username, email, password_hash, email_verified_at IS NOT NULL, role, status, created_at
		FROM users
		WHERE email = ?`, strings.ToLower(email)).Scan(
		&result.User.UserID,
		&result.User.Username,
		&result.User.Email,
		&result.PasswordHash,
		&result.User.EmailVerified,
		&result.User.Role,
		&result.User.Status,
		&result.User.CreatedAt,
	)
	return result, normalizeSQLError(err)
}

func (s *Store) GetUser(ctx context.Context, userID int64, includePrivate bool) (model.User, error) {
	return getUser(ctx, s.db, userID, includePrivate)
}

type queryRower interface {
	QueryRowContext(context.Context, string, ...any) *sql.Row
}

func getUser(ctx context.Context, querier queryRower, userID int64, includePrivate bool) (model.User, error) {
	var user model.User
	var email string
	var profile model.Profile
	err := querier.QueryRowContext(ctx, `
		SELECT u.user_id, u.username, u.email, u.email_verified_at IS NOT NULL, u.role, u.status, u.created_at,
		       COALESCE(p.full_name, ''), COALESCE(p.phone, ''), COALESCE(p.location, ''),
		       COALESCE(p.bio, ''), COALESCE(p.profile_image, ''), COALESCE(p.updated_at, u.created_at)
		FROM users u
		LEFT JOIN user_profiles p ON p.user_id = u.user_id
		WHERE u.user_id = ? AND u.status <> 'deleted'`, userID).Scan(
		&user.UserID,
		&user.Username,
		&email,
		&user.EmailVerified,
		&user.Role,
		&user.Status,
		&user.CreatedAt,
		&profile.FullName,
		&profile.Phone,
		&profile.Location,
		&profile.Bio,
		&profile.ProfileImage,
		&profile.UpdatedAt,
	)
	if err != nil {
		return model.User{}, normalizeSQLError(err)
	}
	profile.UserID = user.UserID
	user.Profile = &profile
	if includePrivate {
		user.Email = email
	} else {
		user.EmailVerified = false
		user.Role = ""
		user.Status = ""
		profile.Phone = ""
	}
	return user, nil
}

type UpdateUserInput struct {
	Username string
	Email    string
	FullName string
	Phone    string
	Location string
	Bio      string
}

func (s *Store) UpdateUser(ctx context.Context, userID int64, input UpdateUserInput) (model.User, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.User{}, err
	}
	defer tx.Rollback()

	result, err := tx.ExecContext(ctx, `
		UPDATE users SET username = ?, email = ?, updated_at = CURRENT_TIMESTAMP(6)
		WHERE user_id = ? AND status <> 'deleted'`, input.Username, strings.ToLower(input.Email), userID)
	if err != nil {
		var mysqlError *mysql.MySQLError
		if errors.As(err, &mysqlError) && mysqlError.Number == 1062 {
			return model.User{}, ErrConflict
		}
		return model.User{}, err
	}
	if affected, _ := result.RowsAffected(); affected == 0 {
		return model.User{}, ErrNotFound
	}
	_, err = tx.ExecContext(ctx, `
		UPDATE user_profiles
		SET full_name = ?, phone = ?, location = ?, bio = ?, updated_at = CURRENT_TIMESTAMP(6)
		WHERE user_id = ?`, input.FullName, input.Phone, input.Location, input.Bio, userID)
	if err != nil {
		return model.User{}, err
	}
	user, err := getUser(ctx, tx, userID, true)
	if err != nil {
		return model.User{}, err
	}
	if err = tx.Commit(); err != nil {
		return model.User{}, err
	}
	return user, nil
}

// ReplaceUserProfileImage atomically swaps the current generated avatar URL
// and returns both the updated user and the previously referenced URL. Callers
// can safely delete the old object after commit; exact-reference media serving
// makes it unreachable as soon as this transaction succeeds.
func (s *Store) ReplaceUserProfileImage(ctx context.Context, userID int64, imageURL string) (model.User, string, error) {
	imageURL = strings.TrimSpace(imageURL)
	if userID <= 0 || imageURL == "" || len(imageURL) > 1024 {
		return model.User{}, "", errors.New("store: invalid profile image replacement")
	}
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.User{}, "", err
	}
	defer tx.Rollback()

	var previousURL string
	if err := tx.QueryRowContext(ctx, `
		SELECT COALESCE(p.profile_image, '')
		FROM user_profiles AS p
		JOIN users AS u ON u.user_id = p.user_id
		WHERE p.user_id = ? AND u.status <> 'deleted'
		FOR UPDATE`, userID).Scan(&previousURL); err != nil {
		return model.User{}, "", normalizeSQLError(err)
	}
	result, err := tx.ExecContext(ctx, `
		UPDATE user_profiles
		SET profile_image = ?, updated_at = CURRENT_TIMESTAMP(6)
		WHERE user_id = ?`, imageURL, userID)
	if err != nil {
		return model.User{}, "", err
	}
	if affected, _ := result.RowsAffected(); affected != 1 {
		return model.User{}, "", ErrNotFound
	}
	user, err := getUser(ctx, tx, userID, true)
	if err != nil {
		return model.User{}, "", err
	}
	if err := tx.Commit(); err != nil {
		return model.User{}, "", err
	}
	return user, previousURL, nil
}

// UserProfileImageURLExists is the revocation check for public avatar reads.
// BINARY forces a byte-exact URL comparison despite the table's default
// case-insensitive collation.
func (s *Store) UserProfileImageURLExists(ctx context.Context, userID int64, imageURL string) (bool, error) {
	if userID <= 0 || strings.TrimSpace(imageURL) == "" {
		return false, nil
	}
	var exists bool
	err := s.db.QueryRowContext(ctx, `
		SELECT EXISTS(
			SELECT 1
			FROM user_profiles AS p
			JOIN users AS u ON u.user_id = p.user_id
			WHERE p.user_id = ? AND BINARY p.profile_image = BINARY ? AND u.status <> 'deleted'
		)`, userID, imageURL).Scan(&exists)
	return exists, err
}

// DeleteUser commits the account tombstone and removes every database media
// reference before invoking cleanupAccountMedia. A cleanup failure may leave
// inaccessible orphan files, but can no longer roll the database back to live
// rows that reference files which were already deleted.
func (s *Store) DeleteUser(ctx context.Context, userID int64, cleanupAccountMedia func([]int64) error) error {
	if cleanupAccountMedia == nil {
		return errors.New("account media cleanup is unavailable")
	}

	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return err
	}
	defer tx.Rollback()
	var accountStatus string
	if err := tx.QueryRowContext(ctx, `SELECT status FROM users WHERE user_id = ? FOR UPDATE`, userID).Scan(&accountStatus); err != nil {
		return normalizeSQLError(err)
	}
	if accountStatus == "deleted" {
		return ErrNotFound
	}
	rows, err := tx.QueryContext(ctx, `SELECT listing_id FROM listings WHERE seller_id = ? FOR UPDATE`, userID)
	if err != nil {
		return err
	}
	listingIDs := make([]int64, 0)
	for rows.Next() {
		var listingID int64
		if err := rows.Scan(&listingID); err != nil {
			rows.Close()
			return err
		}
		listingIDs = append(listingIDs, listingID)
	}
	if err := rows.Err(); err != nil {
		rows.Close()
		return err
	}
	if err := rows.Close(); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `
		DELETE image FROM listing_images AS image
		JOIN listings AS listing ON listing.listing_id = image.listing_id
		WHERE listing.seller_id = ?`, userID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `
		UPDATE listings SET status = 'archived', updated_at = CURRENT_TIMESTAMP(6), revision = revision + 1
		WHERE seller_id = ? AND status <> 'deleted'`, userID); err != nil {
		return err
	}
	result, err := tx.ExecContext(ctx, `
		UPDATE users
		SET status = 'deleted', email = CONCAT('deleted+', user_id, '@invalid.local'),
		    username = CONCAT('deleted-', user_id), password_hash = '', updated_at = CURRENT_TIMESTAMP(6)
		WHERE user_id = ? AND status <> 'deleted'`, userID)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected == 0 {
		return ErrNotFound
	}
	if _, err := tx.ExecContext(ctx, `
		UPDATE user_profiles SET full_name = NULL, phone = NULL, location = NULL, bio = NULL,
		profile_image = NULL, updated_at = CURRENT_TIMESTAMP(6) WHERE user_id = ?`, userID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP(6) WHERE user_id = ? AND revoked_at IS NULL`, userID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM auth_identities WHERE user_id = ?`, userID); err != nil {
		return err
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM email_verification_tokens WHERE user_id = ?`, userID); err != nil {
		return err
	}
	for _, listingID := range listingIDs {
		if err := enqueueListing(ctx, tx, listingID, "listing.delete_vector"); err != nil {
			return err
		}
	}
	if err := tx.Commit(); err != nil {
		return err
	}
	if err := cleanupAccountMedia(listingIDs); err != nil {
		return fmt.Errorf("delete account media after account commit: %w", err)
	}
	return nil
}

func (s *Store) GetActiveUserRole(ctx context.Context, userID int64) (string, error) {
	var role string
	err := s.db.QueryRowContext(ctx, `SELECT role FROM users WHERE user_id = ? AND status = 'active' AND email_verified_at IS NOT NULL`, userID).Scan(&role)
	return role, normalizeSQLError(err)
}

func (s *Store) SaveRefreshToken(ctx context.Context, userID int64, tokenHash string, expiresAt time.Time) error {
	_, err := s.db.ExecContext(ctx, `
		INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
		VALUES (?, ?, ?)`, userID, tokenHash, expiresAt.UTC())
	return err
}

func (s *Store) RotateRefreshToken(ctx context.Context, oldHash, newHash string, newExpiresAt time.Time) (model.User, error) {
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return model.User{}, err
	}
	defer tx.Rollback()

	var userID int64
	err = tx.QueryRowContext(ctx, `
		SELECT rt.user_id FROM refresh_tokens rt JOIN users u ON u.user_id = rt.user_id
		WHERE rt.token_hash = ? AND rt.revoked_at IS NULL AND rt.expires_at > CURRENT_TIMESTAMP(6)
		  AND u.status = 'active' AND u.email_verified_at IS NOT NULL
		FOR UPDATE`, oldHash).Scan(&userID)
	if err != nil {
		return model.User{}, normalizeSQLError(err)
	}
	result, err := tx.ExecContext(ctx, `
		UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP(6), replaced_by_hash = ?
		WHERE token_hash = ? AND revoked_at IS NULL`, newHash, oldHash)
	if err != nil {
		return model.User{}, err
	}
	if affected, _ := result.RowsAffected(); affected != 1 {
		return model.User{}, ErrConflict
	}
	if _, err = tx.ExecContext(ctx, `
		INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
		VALUES (?, ?, ?)`, userID, newHash, newExpiresAt.UTC()); err != nil {
		return model.User{}, err
	}
	user, err := getUser(ctx, tx, userID, true)
	if err != nil {
		return model.User{}, err
	}
	if err = tx.Commit(); err != nil {
		return model.User{}, err
	}
	return user, nil
}

func (s *Store) RevokeRefreshToken(ctx context.Context, tokenHash string) error {
	_, err := s.db.ExecContext(ctx, `
		UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP(6)
		WHERE token_hash = ? AND revoked_at IS NULL`, tokenHash)
	return err
}

func (s *Store) RevokeRefreshTokenForUser(ctx context.Context, tokenHash string, userID int64) error {
	_, err := s.db.ExecContext(ctx, `
		UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP(6)
		WHERE token_hash = ? AND user_id = ? AND revoked_at IS NULL`, tokenHash, userID)
	return err
}

func (s *Store) RevokeAllRefreshTokens(ctx context.Context, userID int64) error {
	_, err := s.db.ExecContext(ctx, `
		UPDATE refresh_tokens SET revoked_at = CURRENT_TIMESTAMP(6)
		WHERE user_id = ? AND revoked_at IS NULL`, userID)
	return err
}
