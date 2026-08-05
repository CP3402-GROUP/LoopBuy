package store

import (
	"database/sql"
	"errors"
)

var (
	ErrNotFound                 = errors.New("resource not found")
	ErrConflict                 = errors.New("resource already exists")
	ErrForbidden                = errors.New("operation is not allowed")
	ErrInvalidState             = errors.New("resource is in an invalid state")
	ErrStaleWrite               = errors.New("resource changed since it was read")
	ErrEmailUnverified          = errors.New("email address is not verified")
	ErrIdentityConflict         = errors.New("provider identity conflicts with an existing account")
	ErrInvalidVerificationToken = errors.New("email verification token is invalid or expired")
	ErrRateLimited              = errors.New("operation rate limit exceeded")
)

type Store struct {
	db *sql.DB
}

func New(db *sql.DB) *Store {
	return &Store{db: db}
}

func normalizeSQLError(err error) error {
	if errors.Is(err, sql.ErrNoRows) {
		return ErrNotFound
	}
	return err
}
