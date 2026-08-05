package store

import (
	"context"
	"database/sql"
	"strings"
	"time"
)

// ReserveProviderRequest atomically consumes one global-hour and one
// per-user-day request. A failed reservation rolls both counters back.
func (s *Store) ReserveProviderRequest(ctx context.Context, scope string, userID int64, hourlyLimit, userDailyLimit int) error {
	if strings.TrimSpace(scope) == "" || userID < 1 || hourlyLimit < 1 || userDailyLimit < 1 {
		return ErrInvalidState
	}
	now := time.Now().UTC()
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return err
	}
	defer tx.Rollback()
	reservations := []struct {
		subjectType string
		subjectID   int64
		windowKind  string
		windowStart time.Time
		limit       int
	}{
		{subjectType: "global", subjectID: 0, windowKind: "hour", windowStart: now.Truncate(time.Hour), limit: hourlyLimit},
		{subjectType: "user", subjectID: userID, windowKind: "day", windowStart: time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, time.UTC), limit: userDailyLimit},
	}
	for _, reservation := range reservations {
		if _, err := tx.ExecContext(ctx, `
			INSERT IGNORE INTO provider_request_budgets
			(provider_scope, subject_type, subject_id, window_kind, window_start, request_count)
			VALUES (?, ?, ?, ?, ?, 0)`, scope, reservation.subjectType, reservation.subjectID, reservation.windowKind, reservation.windowStart); err != nil {
			return err
		}
		result, err := tx.ExecContext(ctx, `
			UPDATE provider_request_budgets SET request_count = request_count + 1
			WHERE provider_scope = ? AND subject_type = ? AND subject_id = ?
			  AND window_kind = ? AND window_start = ? AND request_count < ?`,
			scope, reservation.subjectType, reservation.subjectID, reservation.windowKind, reservation.windowStart, reservation.limit)
		if err != nil {
			return err
		}
		if affected, _ := result.RowsAffected(); affected != 1 {
			return ErrRateLimited
		}
	}
	if _, err := tx.ExecContext(ctx, `
		DELETE FROM provider_request_budgets
		WHERE window_start < DATE_SUB(?, INTERVAL 72 HOUR)
		LIMIT 1000`, now); err != nil {
		return err
	}
	return tx.Commit()
}
