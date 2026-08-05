package store

import (
	"context"
	"database/sql"
	"errors"
	"sort"
	"time"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/model"
	"github.com/go-sql-driver/mysql"
)

func (s *Store) AddFavourite(ctx context.Context, userID, listingID int64) error {
	result, err := s.db.ExecContext(ctx, `
		INSERT IGNORE INTO favourites (user_id, listing_id)
		SELECT ?, l.listing_id FROM listings l JOIN categories c ON c.category_id = l.category_id
		WHERE l.listing_id = ? AND l.status = 'active' AND l.moderation_status = 'approved' AND c.is_active = TRUE`, userID, listingID)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected == 0 {
		var exists int
		if err := s.db.QueryRowContext(ctx, `SELECT COUNT(*) FROM favourites WHERE user_id = ? AND listing_id = ?`, userID, listingID).Scan(&exists); err != nil {
			return err
		}
		if exists == 0 {
			return ErrNotFound
		}
	}
	_, _ = s.db.ExecContext(ctx, `INSERT INTO listing_interactions (user_id, listing_id, interaction_type) VALUES (?, ?, 'favourite')`, userID, listingID)
	return nil
}

func (s *Store) RemoveFavourite(ctx context.Context, userID, listingID int64) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM favourites WHERE user_id = ? AND listing_id = ?`, userID, listingID)
	return err
}

func (s *Store) ListFavourites(ctx context.Context, userID int64, limit int) ([]model.Listing, error) {
	if limit < 1 || limit > 100 {
		limit = 50
	}
	rows, err := s.db.QueryContext(ctx, `SELECT listing_id FROM favourites WHERE user_id = ? ORDER BY created_at DESC LIMIT ?`, userID, limit)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	ids := make([]int64, 0)
	for rows.Next() {
		var id int64
		if err := rows.Scan(&id); err != nil {
			return nil, err
		}
		ids = append(ids, id)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	return s.ListingsByIDs(ctx, ids)
}

func (s *Store) GetCart(ctx context.Context, userID int64) (model.Cart, error) {
	var cart model.Cart
	err := s.db.QueryRowContext(ctx, `SELECT cart_id, user_id, created_at, updated_at FROM carts WHERE user_id = ?`, userID).
		Scan(&cart.CartID, &cart.UserID, &cart.CreatedAt, &cart.UpdatedAt)
	if err != nil {
		return model.Cart{}, normalizeSQLError(err)
	}
	rows, err := s.db.QueryContext(ctx, `SELECT listing_id, quantity FROM cart_items WHERE cart_id = ? ORDER BY updated_at DESC`, cart.CartID)
	if err != nil {
		return model.Cart{}, err
	}
	quantities := make(map[int64]int)
	ids := make([]int64, 0)
	for rows.Next() {
		var id int64
		var quantity int
		if err := rows.Scan(&id, &quantity); err != nil {
			rows.Close()
			return model.Cart{}, err
		}
		ids = append(ids, id)
		quantities[id] = quantity
	}
	if err := rows.Close(); err != nil {
		return model.Cart{}, err
	}
	listings, err := s.ListingsByIDs(ctx, ids)
	if err != nil {
		return model.Cart{}, err
	}
	cart.Items = make([]model.CartItem, 0, len(listings))
	for _, listing := range listings {
		cart.Items = append(cart.Items, model.CartItem{Listing: listing, Quantity: quantities[listing.ListingID]})
	}
	return cart, nil
}

func (s *Store) SetCartItem(ctx context.Context, userID, listingID int64, quantity int) (model.Cart, error) {
	if quantity < 1 || quantity > 99 {
		return model.Cart{}, ErrInvalidState
	}
	result, err := s.db.ExecContext(ctx, `
		INSERT INTO cart_items (cart_id, listing_id, quantity)
		SELECT cart.cart_id, l.listing_id, ?
		FROM carts cart JOIN listings l ON l.listing_id = ? JOIN categories category ON category.category_id = l.category_id
		WHERE cart.user_id = ? AND l.status = 'active' AND l.moderation_status = 'approved' AND category.is_active = TRUE
		ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = CURRENT_TIMESTAMP(6)`, quantity, listingID, userID)
	if err != nil {
		return model.Cart{}, err
	}
	if affected, _ := result.RowsAffected(); affected == 0 {
		return model.Cart{}, ErrNotFound
	}
	_, _ = s.db.ExecContext(ctx, `UPDATE carts SET updated_at = CURRENT_TIMESTAMP(6) WHERE user_id = ?`, userID)
	_, _ = s.db.ExecContext(ctx, `INSERT INTO listing_interactions (user_id, listing_id, interaction_type) VALUES (?, ?, 'cart_add')`, userID, listingID)
	return s.GetCart(ctx, userID)
}

func (s *Store) RemoveCartItem(ctx context.Context, userID, listingID int64) (model.Cart, error) {
	_, err := s.db.ExecContext(ctx, `
		DELETE ci FROM cart_items ci JOIN carts c ON c.cart_id = ci.cart_id
		WHERE c.user_id = ? AND ci.listing_id = ?`, userID, listingID)
	if err != nil {
		return model.Cart{}, err
	}
	_, _ = s.db.ExecContext(ctx, `UPDATE carts SET updated_at = CURRENT_TIMESTAMP(6) WHERE user_id = ?`, userID)
	return s.GetCart(ctx, userID)
}

func (s *Store) ClearCart(ctx context.Context, userID int64) (model.Cart, error) {
	_, err := s.db.ExecContext(ctx, `DELETE ci FROM cart_items ci JOIN carts c ON c.cart_id = ci.cart_id WHERE c.user_id = ?`, userID)
	if err != nil {
		return model.Cart{}, err
	}
	return s.GetCart(ctx, userID)
}

func (s *Store) CreateConversation(ctx context.Context, buyerID, listingID int64) (model.Conversation, error) {
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return model.Conversation{}, err
	}
	defer tx.Rollback()
	var sellerID int64
	if err := tx.QueryRowContext(ctx, `
		SELECT l.seller_id FROM listings l JOIN categories c ON c.category_id = l.category_id
		WHERE l.listing_id = ? AND l.status = 'active' AND l.moderation_status = 'approved' AND c.is_active = TRUE`, listingID).Scan(&sellerID); err != nil {
		return model.Conversation{}, normalizeSQLError(err)
	}
	if sellerID == buyerID {
		return model.Conversation{}, ErrInvalidState
	}
	result, err := tx.ExecContext(ctx, `
		INSERT INTO conversations (listing_id, buyer_id, seller_id)
		VALUES (?, ?, ?)
		ON DUPLICATE KEY UPDATE conversation_id = LAST_INSERT_ID(conversation_id), updated_at = CURRENT_TIMESTAMP(6)`, listingID, buyerID, sellerID)
	if err != nil {
		return model.Conversation{}, err
	}
	conversationID, err := result.LastInsertId()
	if err != nil {
		return model.Conversation{}, err
	}
	affected, err := result.RowsAffected()
	if err != nil {
		return model.Conversation{}, err
	}
	members := []struct {
		userID int64
		role   string
	}{{buyerID, "buyer"}}
	if affected == 1 {
		members = append(members, struct {
			userID int64
			role   string
		}{sellerID, "seller"})
	}
	for _, member := range members {
		if _, err := tx.ExecContext(ctx, `
			INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, ?)
			ON DUPLICATE KEY UPDATE role = VALUES(role), left_at = NULL`, conversationID, member.userID, member.role); err != nil {
			return model.Conversation{}, err
		}
	}
	if err := tx.Commit(); err != nil {
		return model.Conversation{}, err
	}
	return s.GetConversation(ctx, buyerID, conversationID)
}

func (s *Store) ListConversations(ctx context.Context, userID int64) ([]model.Conversation, error) {
	rows, err := s.db.QueryContext(ctx, `
		SELECT c.conversation_id, c.listing_id, c.buyer_id, c.seller_id, c.created_at, c.updated_at
		FROM conversations c JOIN conversation_members cm ON cm.conversation_id = c.conversation_id
		WHERE cm.user_id = ? AND cm.left_at IS NULL ORDER BY c.updated_at DESC`, userID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]model.Conversation, 0)
	for rows.Next() {
		var item model.Conversation
		if err := rows.Scan(&item.ConversationID, &item.ListingID, &item.BuyerID, &item.SellerID, &item.CreatedAt, &item.UpdatedAt); err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	if err := s.attachConversationDetails(ctx, items); err != nil {
		return nil, err
	}
	return items, nil
}

func (s *Store) GetConversation(ctx context.Context, userID, conversationID int64) (model.Conversation, error) {
	var item model.Conversation
	err := s.db.QueryRowContext(ctx, `
		SELECT c.conversation_id, c.listing_id, c.buyer_id, c.seller_id, c.created_at, c.updated_at
		FROM conversations c JOIN conversation_members cm ON cm.conversation_id = c.conversation_id
		WHERE c.conversation_id = ? AND cm.user_id = ? AND cm.left_at IS NULL`, conversationID, userID).
		Scan(&item.ConversationID, &item.ListingID, &item.BuyerID, &item.SellerID, &item.CreatedAt, &item.UpdatedAt)
	if err != nil {
		return model.Conversation{}, normalizeSQLError(err)
	}
	items := []model.Conversation{item}
	if err := s.attachConversationDetails(ctx, items); err != nil {
		return model.Conversation{}, err
	}
	item = items[0]
	return item, nil
}

func (s *Store) attachConversationDetails(ctx context.Context, conversations []model.Conversation) error {
	if len(conversations) == 0 {
		return nil
	}
	ids := make([]any, 0, len(conversations))
	positions := make(map[int64]int, len(conversations))
	for index := range conversations {
		ids = append(ids, conversations[index].ConversationID)
		positions[conversations[index].ConversationID] = index
		conversations[index].Members = []model.ConversationMember{}
	}
	memberRows, err := s.db.QueryContext(ctx, `
		SELECT cm.conversation_id, cm.role, cm.joined_at, u.user_id, u.username, u.created_at,
		       COALESCE(p.full_name, ''), COALESCE(p.profile_image, '')
		FROM conversation_members cm JOIN users u ON u.user_id = cm.user_id
		LEFT JOIN user_profiles p ON p.user_id = u.user_id
		WHERE cm.conversation_id IN (`+placeholders(len(ids))+`) AND cm.left_at IS NULL
		ORDER BY cm.conversation_id, cm.joined_at`, ids...)
	if err != nil {
		return err
	}
	for memberRows.Next() {
		var conversationID int64
		var member model.ConversationMember
		var fullName, profileImage string
		if err := memberRows.Scan(&conversationID, &member.Role, &member.JoinedAt,
			&member.User.UserID, &member.User.Username, &member.User.CreatedAt, &fullName, &profileImage); err != nil {
			memberRows.Close()
			return err
		}
		member.User.Profile = &model.Profile{UserID: member.User.UserID, FullName: fullName, ProfileImage: profileImage}
		if position, ok := positions[conversationID]; ok {
			conversations[position].Members = append(conversations[position].Members, member)
		}
	}
	if err := memberRows.Err(); err != nil {
		memberRows.Close()
		return err
	}
	if err := memberRows.Close(); err != nil {
		return err
	}

	messageRows, err := s.db.QueryContext(ctx, `
		SELECT m.message_id, m.conversation_id, m.sender_id, m.message_text, m.created_at
		FROM messages m JOIN (
			SELECT conversation_id, MAX(message_id) AS message_id FROM messages
			WHERE conversation_id IN (`+placeholders(len(ids))+`) AND deleted_at IS NULL GROUP BY conversation_id
		) latest ON latest.message_id = m.message_id`, ids...)
	if err != nil {
		return err
	}
	defer messageRows.Close()
	for messageRows.Next() {
		var message model.Message
		if err := messageRows.Scan(&message.MessageID, &message.ConversationID, &message.SenderID, &message.MessageText, &message.CreatedAt); err != nil {
			return err
		}
		if position, ok := positions[message.ConversationID]; ok {
			conversations[position].LastMessage = &message
		}
	}
	return messageRows.Err()
}

func (s *Store) ListMessages(ctx context.Context, userID, conversationID int64, beforeID int64, limit int) ([]model.Message, error) {
	if limit < 1 || limit > 100 {
		limit = 50
	}
	query := `SELECT m.message_id, m.conversation_id, m.sender_id, m.message_text, m.created_at
		FROM messages m JOIN conversation_members cm ON cm.conversation_id = m.conversation_id
		WHERE cm.user_id = ? AND cm.left_at IS NULL AND m.conversation_id = ? AND m.deleted_at IS NULL`
	args := []any{userID, conversationID}
	if beforeID > 0 {
		query += ` AND m.message_id < ?`
		args = append(args, beforeID)
	}
	query += ` ORDER BY m.message_id DESC LIMIT ?`
	args = append(args, limit)
	rows, err := s.db.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]model.Message, 0)
	for rows.Next() {
		var item model.Message
		if err := rows.Scan(&item.MessageID, &item.ConversationID, &item.SenderID, &item.MessageText, &item.CreatedAt); err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	sort.Slice(items, func(i, j int) bool { return items[i].MessageID < items[j].MessageID })
	return items, nil
}

func (s *Store) CreateMessage(ctx context.Context, userID, conversationID int64, text string) (model.Message, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.Message{}, err
	}
	defer tx.Rollback()
	result, err := tx.ExecContext(ctx, `
		INSERT INTO messages (conversation_id, sender_id, message_text)
		SELECT ?, ?, ? FROM conversation_members
		WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL`, conversationID, userID, text, conversationID, userID)
	if err != nil {
		return model.Message{}, err
	}
	if affected, _ := result.RowsAffected(); affected == 0 {
		return model.Message{}, ErrNotFound
	}
	messageID, err := result.LastInsertId()
	if err != nil {
		return model.Message{}, err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE conversations SET updated_at = CURRENT_TIMESTAMP(6) WHERE conversation_id = ?`, conversationID); err != nil {
		return model.Message{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.Message{}, err
	}
	return model.Message{MessageID: messageID, ConversationID: conversationID, SenderID: userID, MessageText: text, CreatedAt: time.Now().UTC()}, nil
}

func (s *Store) UpdateMessage(ctx context.Context, userID, conversationID, messageID int64, text string) (model.Message, error) {
	result, err := s.db.ExecContext(ctx, `
		UPDATE messages SET message_text = ?, updated_at = CURRENT_TIMESTAMP(6)
		WHERE message_id = ? AND conversation_id = ? AND sender_id = ? AND deleted_at IS NULL`, text, messageID, conversationID, userID)
	if err != nil {
		return model.Message{}, err
	}
	if affected, _ := result.RowsAffected(); affected == 0 {
		return model.Message{}, ErrNotFound
	}
	var item model.Message
	err = s.db.QueryRowContext(ctx, `SELECT message_id, conversation_id, sender_id, message_text, created_at FROM messages WHERE message_id = ?`, messageID).
		Scan(&item.MessageID, &item.ConversationID, &item.SenderID, &item.MessageText, &item.CreatedAt)
	return item, err
}

func (s *Store) DeleteMessage(ctx context.Context, userID, conversationID, messageID int64) error {
	result, err := s.db.ExecContext(ctx, `
		UPDATE messages SET message_text = '[deleted]', deleted_at = CURRENT_TIMESTAMP(6), updated_at = CURRENT_TIMESTAMP(6)
		WHERE message_id = ? AND conversation_id = ? AND sender_id = ? AND deleted_at IS NULL`, messageID, conversationID, userID)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected == 0 {
		return ErrNotFound
	}
	return nil
}

func (s *Store) LeaveConversation(ctx context.Context, userID, conversationID int64) error {
	result, err := s.db.ExecContext(ctx, `
		UPDATE conversation_members SET left_at = CURRENT_TIMESTAMP(6)
		WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL`, conversationID, userID)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected == 0 {
		return ErrNotFound
	}
	return nil
}

func isDuplicate(err error) bool {
	var mysqlError *mysql.MySQLError
	return errors.As(err, &mysqlError) && mysqlError.Number == 1062
}
