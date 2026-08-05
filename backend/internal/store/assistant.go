package store

import (
	"context"
	"database/sql"
	"sort"
	"time"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/model"
)

func (s *Store) CreateAIChatSession(ctx context.Context, userID int64, title string) (model.AIChatSession, error) {
	result, err := s.db.ExecContext(ctx, `INSERT INTO ai_chat_sessions (user_id, title) VALUES (?, ?)`, userID, title)
	if err != nil {
		return model.AIChatSession{}, err
	}
	id, err := result.LastInsertId()
	if err != nil {
		return model.AIChatSession{}, err
	}
	return s.GetAIChatSession(ctx, userID, id)
}

func (s *Store) GetAIChatSession(ctx context.Context, userID, sessionID int64) (model.AIChatSession, error) {
	var item model.AIChatSession
	err := s.db.QueryRowContext(ctx, `
		SELECT session_id, user_id, COALESCE(title, ''), created_at, updated_at
		FROM ai_chat_sessions WHERE session_id = ? AND user_id = ?`, sessionID, userID).Scan(
		&item.SessionID, &item.UserID, &item.Title, &item.CreatedAt, &item.UpdatedAt)
	return item, normalizeSQLError(err)
}

func (s *Store) ListAIChatSessions(ctx context.Context, userID int64) ([]model.AIChatSession, error) {
	rows, err := s.db.QueryContext(ctx, `
		SELECT session_id, user_id, COALESCE(title, ''), created_at, updated_at
		FROM ai_chat_sessions WHERE user_id = ? ORDER BY updated_at DESC LIMIT 100`, userID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]model.AIChatSession, 0)
	for rows.Next() {
		var item model.AIChatSession
		if err := rows.Scan(&item.SessionID, &item.UserID, &item.Title, &item.CreatedAt, &item.UpdatedAt); err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func (s *Store) UpdateAIChatSession(ctx context.Context, userID, sessionID int64, title string) (model.AIChatSession, error) {
	result, err := s.db.ExecContext(ctx, `
		UPDATE ai_chat_sessions SET title = ?, updated_at = CURRENT_TIMESTAMP(6)
		WHERE session_id = ? AND user_id = ?`, title, sessionID, userID)
	if err != nil {
		return model.AIChatSession{}, err
	}
	if affected, _ := result.RowsAffected(); affected == 0 {
		return model.AIChatSession{}, ErrNotFound
	}
	return s.GetAIChatSession(ctx, userID, sessionID)
}

func (s *Store) DeleteAIChatSession(ctx context.Context, userID, sessionID int64) error {
	result, err := s.db.ExecContext(ctx, `DELETE FROM ai_chat_sessions WHERE session_id = ? AND user_id = ?`, sessionID, userID)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected == 0 {
		return ErrNotFound
	}
	return nil
}

func (s *Store) ListAIChatMessages(ctx context.Context, userID, sessionID int64, limit int) ([]model.AIChatMessage, error) {
	if limit < 1 || limit > 100 {
		limit = 50
	}
	rows, err := s.db.QueryContext(ctx, `
		SELECT m.message_id, m.session_id, m.role, m.content, COALESCE(m.model, ''),
		       COALESCE(m.prompt_tokens, 0), COALESCE(m.completion_tokens, 0), m.created_at
		FROM ai_chat_messages m JOIN ai_chat_sessions s ON s.session_id = m.session_id
		WHERE s.user_id = ? AND m.session_id = ? ORDER BY m.message_id DESC LIMIT ?`, userID, sessionID, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]model.AIChatMessage, 0)
	for rows.Next() {
		var item model.AIChatMessage
		if err := rows.Scan(&item.MessageID, &item.SessionID, &item.Role, &item.Content, &item.Model,
			&item.PromptTokens, &item.CompletionTokens, &item.CreatedAt); err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	sort.Slice(items, func(i, j int) bool { return items[i].MessageID < items[j].MessageID })
	for index := range items {
		sources, err := s.chatSources(ctx, items[index].MessageID)
		if err != nil {
			return nil, err
		}
		items[index].Sources = sources
	}
	return items, nil
}

func (s *Store) SaveAIChatExchange(ctx context.Context, userID, sessionID int64, question, answer, providerModel string, promptTokens, completionTokens int, sources []model.ChatSource) (model.AIChatMessage, error) {
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{})
	if err != nil {
		return model.AIChatMessage{}, err
	}
	defer tx.Rollback()
	var exists int
	if err := tx.QueryRowContext(ctx, `SELECT 1 FROM ai_chat_sessions WHERE session_id = ? AND user_id = ? FOR UPDATE`, sessionID, userID).Scan(&exists); err != nil {
		return model.AIChatMessage{}, normalizeSQLError(err)
	}
	if _, err := tx.ExecContext(ctx, `INSERT INTO ai_chat_messages (session_id, role, content) VALUES (?, 'user', ?)`, sessionID, question); err != nil {
		return model.AIChatMessage{}, err
	}
	result, err := tx.ExecContext(ctx, `
		INSERT INTO ai_chat_messages (session_id, role, content, model, prompt_tokens, completion_tokens)
		VALUES (?, 'assistant', ?, ?, ?, ?)`, sessionID, answer, providerModel, promptTokens, completionTokens)
	if err != nil {
		return model.AIChatMessage{}, err
	}
	messageID, err := result.LastInsertId()
	if err != nil {
		return model.AIChatMessage{}, err
	}
	for position, source := range sources {
		if _, err := tx.ExecContext(ctx, `
			INSERT INTO ai_chat_sources (message_id, listing_id, rank_position, relevance_score)
			VALUES (?, ?, ?, ?)`, messageID, source.ListingID, position+1, source.Score); err != nil {
			return model.AIChatMessage{}, err
		}
	}
	if _, err := tx.ExecContext(ctx, `UPDATE ai_chat_sessions SET updated_at = CURRENT_TIMESTAMP(6) WHERE session_id = ?`, sessionID); err != nil {
		return model.AIChatMessage{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.AIChatMessage{}, err
	}
	return model.AIChatMessage{
		MessageID: messageID, SessionID: sessionID, Role: "assistant", Content: answer,
		Model: providerModel, PromptTokens: promptTokens, CompletionTokens: completionTokens,
		Sources: sources, CreatedAt: time.Now().UTC(),
	}, nil
}

func (s *Store) chatSources(ctx context.Context, messageID int64) ([]model.ChatSource, error) {
	rows, err := s.db.QueryContext(ctx, `
		SELECT src.listing_id, l.title, l.price, l.currency, src.relevance_score
		FROM ai_chat_sources src JOIN listings l ON l.listing_id = src.listing_id
		WHERE src.message_id = ? ORDER BY src.rank_position`, messageID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]model.ChatSource, 0)
	for rows.Next() {
		var item model.ChatSource
		if err := rows.Scan(&item.ListingID, &item.Title, &item.Price, &item.Currency, &item.Score); err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, rows.Err()
}
