package store

import (
	"context"
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"math"
	"strconv"
	"strings"
	"time"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/model"
	"github.com/go-sql-driver/mysql"
)

type ListingFilters struct {
	Query                string
	Category             string
	MinPrice             *float64
	MaxPrice             *float64
	Condition            string
	Location             string
	SellerID             int64
	Statuses             []string
	Moderation           []string
	ActiveCategoriesOnly bool
	Sort                 string
	Limit                int
	Offset               int
}

type ListingInput struct {
	CategoryID    int64
	Title         string
	Description   string
	Brand         string
	Location      string
	Price         float64
	Currency      string
	ItemCondition string
	Images        []ImageInput
}

type ImageInput struct {
	ImageURL  string `json:"image_url"`
	SortOrder int    `json:"sort_order"`
	IsPrimary bool   `json:"is_primary"`
}

type AssessmentInput struct {
	Score        float64
	Label        string
	Reasons      []string
	ModelVersion string
}

func (s *Store) ListCategories(ctx context.Context, includeInactive bool) ([]model.Category, error) {
	query := `SELECT category_id, name, slug, is_active, created_at FROM categories`
	if !includeInactive {
		query += ` WHERE is_active = TRUE`
	}
	query += ` ORDER BY sort_order, name`
	rows, err := s.db.QueryContext(ctx, query)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]model.Category, 0)
	for rows.Next() {
		var item model.Category
		if err := rows.Scan(&item.CategoryID, &item.Name, &item.Slug, &item.IsActive, &item.CreatedAt); err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func (s *Store) GetCategory(ctx context.Context, identifier string) (model.Category, error) {
	var item model.Category
	var err error
	if id, parseErr := strconv.ParseInt(identifier, 10, 64); parseErr == nil {
		err = s.db.QueryRowContext(ctx, `SELECT category_id, name, slug, is_active, created_at FROM categories WHERE category_id = ?`, id).
			Scan(&item.CategoryID, &item.Name, &item.Slug, &item.IsActive, &item.CreatedAt)
	} else {
		err = s.db.QueryRowContext(ctx, `SELECT category_id, name, slug, is_active, created_at FROM categories WHERE slug = ?`, identifier).
			Scan(&item.CategoryID, &item.Name, &item.Slug, &item.IsActive, &item.CreatedAt)
	}
	return item, normalizeSQLError(err)
}

func (s *Store) CreateCategory(ctx context.Context, name, slug string) (model.Category, error) {
	result, err := s.db.ExecContext(ctx, `INSERT INTO categories (name, slug) VALUES (?, ?)`, name, slug)
	if err != nil {
		var mysqlError *mysql.MySQLError
		if errors.As(err, &mysqlError) && mysqlError.Number == 1062 {
			return model.Category{}, ErrConflict
		}
		return model.Category{}, err
	}
	id, err := result.LastInsertId()
	if err != nil {
		return model.Category{}, err
	}
	return s.GetCategory(ctx, strconv.FormatInt(id, 10))
}

func (s *Store) UpdateCategory(ctx context.Context, categoryID int64, name, slug string, isActive bool) (model.Category, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.Category{}, err
	}
	defer tx.Rollback()
	var wasActive bool
	if err := tx.QueryRowContext(ctx, `SELECT is_active FROM categories WHERE category_id = ? FOR UPDATE`, categoryID).Scan(&wasActive); err != nil {
		return model.Category{}, normalizeSQLError(err)
	}
	_, err = tx.ExecContext(ctx, `
		UPDATE categories SET name = ?, slug = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP(6)
		WHERE category_id = ?`, name, slug, isActive, categoryID)
	if err != nil {
		var mysqlError *mysql.MySQLError
		if errors.As(err, &mysqlError) && mysqlError.Number == 1062 {
			return model.Category{}, ErrConflict
		}
		return model.Category{}, err
	}
	if wasActive != isActive {
		rows, err := tx.QueryContext(ctx, `SELECT listing_id FROM listings WHERE category_id = ?`, categoryID)
		if err != nil {
			return model.Category{}, err
		}
		listingIDs := make([]int64, 0)
		for rows.Next() {
			var listingID int64
			if err := rows.Scan(&listingID); err != nil {
				rows.Close()
				return model.Category{}, err
			}
			listingIDs = append(listingIDs, listingID)
		}
		if err := rows.Err(); err != nil {
			rows.Close()
			return model.Category{}, err
		}
		if err := rows.Close(); err != nil {
			return model.Category{}, err
		}
		eventType := "listing.delete_vector"
		if isActive {
			eventType = "listing.upsert"
		}
		for _, listingID := range listingIDs {
			if err := enqueueListing(ctx, tx, listingID, eventType); err != nil {
				return model.Category{}, err
			}
		}
	}
	if err := tx.Commit(); err != nil {
		return model.Category{}, err
	}
	return s.GetCategory(ctx, strconv.FormatInt(categoryID, 10))
}

func (s *Store) DeleteCategory(ctx context.Context, categoryID int64) error {
	current, err := s.GetCategory(ctx, strconv.FormatInt(categoryID, 10))
	if err != nil {
		return err
	}
	_, err = s.UpdateCategory(ctx, categoryID, current.Name, current.Slug, false)
	return err
}

func moderationForAssessment(assessment AssessmentInput) (status, moderation string) {
	// The database must fail closed if a provider ever returns a malformed or
	// internally inconsistent result. Only the documented low-risk interval is
	// eligible for automatic publication.
	if assessment.Label == "low_risk" && assessment.Score >= 0 && assessment.Score < 0.45 &&
		!math.IsNaN(assessment.Score) && !math.IsInf(assessment.Score, 0) {
		return "active", "approved"
	}
	return "under_review", "pending"
}

func (s *Store) CreateListing(ctx context.Context, sellerID int64, input ListingInput, assessment AssessmentInput) (model.Listing, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.Listing{}, err
	}
	defer tx.Rollback()
	var categoryExists int
	if err := tx.QueryRowContext(ctx, `SELECT 1 FROM categories WHERE category_id = ? AND is_active = TRUE`, input.CategoryID).Scan(&categoryExists); err != nil {
		return model.Listing{}, normalizeSQLError(err)
	}

	status, moderation := moderationForAssessment(assessment)
	result, err := tx.ExecContext(ctx, `
		INSERT INTO listings
		(seller_id, category_id, title, description, brand, location, price, currency,
		 item_condition, status, moderation_status, scam_score, scam_label)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		sellerID, input.CategoryID, input.Title, input.Description, input.Brand, input.Location,
		input.Price, input.Currency, input.ItemCondition, status, moderation, assessment.Score, assessment.Label)
	if err != nil {
		return model.Listing{}, err
	}
	listingID, err := result.LastInsertId()
	if err != nil {
		return model.Listing{}, err
	}
	if err := replaceImages(ctx, tx, listingID, input.Images); err != nil {
		return model.Listing{}, err
	}
	if err := insertAssessment(ctx, tx, listingID, assessment); err != nil {
		return model.Listing{}, err
	}
	if err := enqueueListing(ctx, tx, listingID, "listing.upsert"); err != nil {
		return model.Listing{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.Listing{}, err
	}
	return s.GetListing(ctx, listingID)
}

func (s *Store) UpdateListing(ctx context.Context, listingID, actorID int64, isAdmin bool, expectedRevision uint64, input ListingInput, assessment AssessmentInput) (model.Listing, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.Listing{}, err
	}
	defer tx.Rollback()

	var sellerID int64
	var currentStatus string
	var currentRevision uint64
	if err := tx.QueryRowContext(ctx, `SELECT seller_id, status, revision FROM listings WHERE listing_id = ? FOR UPDATE`, listingID).Scan(&sellerID, &currentStatus, &currentRevision); err != nil {
		return model.Listing{}, normalizeSQLError(err)
	}
	if sellerID != actorID && !isAdmin {
		return model.Listing{}, ErrNotFound
	}
	if currentStatus == "sold" || currentStatus == "archived" {
		return model.Listing{}, ErrInvalidState
	}
	if currentRevision != expectedRevision {
		return model.Listing{}, ErrStaleWrite
	}
	var categoryExists int
	if err := tx.QueryRowContext(ctx, `SELECT 1 FROM categories WHERE category_id = ? AND is_active = TRUE`, input.CategoryID).Scan(&categoryExists); err != nil {
		return model.Listing{}, normalizeSQLError(err)
	}
	status, moderation := moderationForAssessment(assessment)
	_, err = tx.ExecContext(ctx, `
		UPDATE listings SET category_id = ?, title = ?, description = ?, brand = ?, location = ?,
		price = ?, currency = ?, item_condition = ?, status = ?, moderation_status = ?,
		scam_score = ?, scam_label = ?, updated_at = CURRENT_TIMESTAMP(6), revision = revision + 1
		WHERE listing_id = ?`, input.CategoryID, input.Title, input.Description, input.Brand,
		input.Location, input.Price, input.Currency, input.ItemCondition, status, moderation,
		assessment.Score, assessment.Label, listingID)
	if err != nil {
		return model.Listing{}, err
	}
	if err := replaceImages(ctx, tx, listingID, input.Images); err != nil {
		return model.Listing{}, err
	}
	if err := insertAssessment(ctx, tx, listingID, assessment); err != nil {
		return model.Listing{}, err
	}
	if err := enqueueListing(ctx, tx, listingID, "listing.upsert"); err != nil {
		return model.Listing{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.Listing{}, err
	}
	return s.GetListing(ctx, listingID)
}

// ReassessListing persists only the automated moderation fields. The revision
// precondition prevents an assessment of stale content from overwriting a
// concurrent edit or a human moderation decision.
func (s *Store) ReassessListing(ctx context.Context, listingID, actorID int64, isAdmin bool, expectedRevision uint64, assessment AssessmentInput) (model.Listing, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.Listing{}, err
	}
	defer tx.Rollback()

	var sellerID int64
	var currentStatus string
	var currentRevision uint64
	var categoryActive bool
	if err := tx.QueryRowContext(ctx, `
		SELECT l.seller_id, l.status, l.revision, c.is_active
		FROM listings l JOIN categories c ON c.category_id = l.category_id
		WHERE l.listing_id = ? FOR UPDATE`, listingID).Scan(&sellerID, &currentStatus, &currentRevision, &categoryActive); err != nil {
		return model.Listing{}, normalizeSQLError(err)
	}
	if sellerID != actorID && !isAdmin {
		return model.Listing{}, ErrNotFound
	}
	if currentStatus == "sold" || currentStatus == "archived" || !categoryActive {
		return model.Listing{}, ErrInvalidState
	}
	if currentRevision != expectedRevision {
		return model.Listing{}, ErrStaleWrite
	}

	status, moderation := moderationForAssessment(assessment)
	if _, err := tx.ExecContext(ctx, `
		UPDATE listings SET status = ?, moderation_status = ?, scam_score = ?, scam_label = ?,
		updated_at = CURRENT_TIMESTAMP(6), revision = revision + 1 WHERE listing_id = ?`,
		status, moderation, assessment.Score, assessment.Label, listingID); err != nil {
		return model.Listing{}, err
	}
	if err := insertAssessment(ctx, tx, listingID, assessment); err != nil {
		return model.Listing{}, err
	}
	if err := enqueueListing(ctx, tx, listingID, "listing.upsert"); err != nil {
		return model.Listing{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.Listing{}, err
	}
	return s.GetListing(ctx, listingID)
}

func (s *Store) SetListingStatus(ctx context.Context, listingID, actorID int64, isAdmin bool, status string) (model.Listing, error) {
	if status != "active" && status != "sold" && status != "archived" {
		return model.Listing{}, ErrInvalidState
	}
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.Listing{}, err
	}
	defer tx.Rollback()
	var sellerID int64
	var currentStatus, moderationStatus string
	if err := tx.QueryRowContext(ctx, `
		SELECT seller_id, status, moderation_status FROM listings WHERE listing_id = ? FOR UPDATE`, listingID).
		Scan(&sellerID, &currentStatus, &moderationStatus); err != nil {
		return model.Listing{}, normalizeSQLError(err)
	}
	if sellerID != actorID && !isAdmin {
		return model.Listing{}, ErrNotFound
	}
	if currentStatus == "sold" && !isAdmin && status != "sold" {
		return model.Listing{}, ErrInvalidState
	}
	if status == "active" && (moderationStatus != "approved" || (currentStatus == "sold" && !isAdmin)) {
		return model.Listing{}, ErrInvalidState
	}
	if _, err := tx.ExecContext(ctx, `UPDATE listings SET status = ?, updated_at = CURRENT_TIMESTAMP(6), revision = revision + 1 WHERE listing_id = ?`, status, listingID); err != nil {
		return model.Listing{}, err
	}
	event := "listing.upsert"
	if status != "active" || moderationStatus != "approved" {
		event = "listing.delete_vector"
	}
	if err := enqueueListing(ctx, tx, listingID, event); err != nil {
		return model.Listing{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.Listing{}, err
	}
	return s.GetListing(ctx, listingID)
}

func (s *Store) SetListingModeration(ctx context.Context, listingID int64, moderationStatus string) (model.Listing, error) {
	if moderationStatus != "approved" && moderationStatus != "rejected" && moderationStatus != "review" {
		return model.Listing{}, ErrInvalidState
	}
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.Listing{}, err
	}
	defer tx.Rollback()

	var currentStatus string
	if err := tx.QueryRowContext(ctx, `SELECT status FROM listings WHERE listing_id = ? FOR UPDATE`, listingID).Scan(&currentStatus); err != nil {
		return model.Listing{}, normalizeSQLError(err)
	}
	nextStatus := currentStatus
	if currentStatus != "sold" && currentStatus != "archived" {
		if moderationStatus == "approved" {
			nextStatus = "active"
		} else {
			nextStatus = "under_review"
		}
	}
	if _, err := tx.ExecContext(ctx, `
		UPDATE listings SET status = ?, moderation_status = ?, updated_at = CURRENT_TIMESTAMP(6), revision = revision + 1
		WHERE listing_id = ?`, nextStatus, moderationStatus, listingID); err != nil {
		return model.Listing{}, err
	}
	event := "listing.delete_vector"
	if nextStatus == "active" && moderationStatus == "approved" {
		event = "listing.upsert"
	}
	if err := enqueueListing(ctx, tx, listingID, event); err != nil {
		return model.Listing{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.Listing{}, err
	}
	return s.GetListing(ctx, listingID)
}

func (s *Store) DeleteListing(ctx context.Context, listingID, actorID int64, isAdmin bool) error {
	_, err := s.SetListingStatus(ctx, listingID, actorID, isAdmin, "archived")
	return err
}

func (s *Store) GetListing(ctx context.Context, listingID int64) (model.Listing, error) {
	query := baseListingSelect() + ` WHERE l.listing_id = ?`
	row := s.db.QueryRowContext(ctx, query, listingID)
	item, err := scanListing(row)
	if err != nil {
		return model.Listing{}, normalizeSQLError(err)
	}
	images, err := s.listImages(ctx, listingID)
	if err != nil {
		return model.Listing{}, err
	}
	item.Images = images
	return item, nil
}

func (s *Store) ListListings(ctx context.Context, filters ListingFilters) ([]model.Listing, error) {
	query := strings.Builder{}
	query.WriteString(baseListingSelect())
	where := make([]string, 0)
	args := make([]any, 0)
	if filters.Query != "" {
		where = append(where, `MATCH(l.title, l.description, l.brand) AGAINST (? IN NATURAL LANGUAGE MODE)`)
		args = append(args, filters.Query)
	}
	if filters.Category != "" {
		where = append(where, `(c.slug = ? OR CAST(c.category_id AS CHAR) = ?)`)
		args = append(args, filters.Category, filters.Category)
	}
	if filters.MinPrice != nil {
		where = append(where, `l.price >= ?`)
		args = append(args, *filters.MinPrice)
	}
	if filters.MaxPrice != nil {
		where = append(where, `l.price <= ?`)
		args = append(args, *filters.MaxPrice)
	}
	if filters.Condition != "" {
		where = append(where, `l.item_condition = ?`)
		args = append(args, filters.Condition)
	}
	if filters.Location != "" {
		where = append(where, `l.location LIKE ?`)
		args = append(args, "%"+filters.Location+"%")
	}
	if filters.SellerID > 0 {
		where = append(where, `l.seller_id = ?`)
		args = append(args, filters.SellerID)
	}
	if len(filters.Statuses) > 0 {
		where = append(where, `l.status IN (`+placeholders(len(filters.Statuses))+`)`)
		for _, status := range filters.Statuses {
			args = append(args, status)
		}
	}
	if len(filters.Moderation) > 0 {
		where = append(where, `l.moderation_status IN (`+placeholders(len(filters.Moderation))+`)`)
		for _, status := range filters.Moderation {
			args = append(args, status)
		}
	}
	if filters.ActiveCategoriesOnly {
		where = append(where, `c.is_active = TRUE`)
	}
	if len(where) > 0 {
		query.WriteString(` WHERE ` + strings.Join(where, ` AND `))
	}
	switch filters.Sort {
	case "price-low":
		query.WriteString(` ORDER BY l.price ASC, l.listing_id DESC`)
	case "price-high":
		query.WriteString(` ORDER BY l.price DESC, l.listing_id DESC`)
	default:
		query.WriteString(` ORDER BY l.created_at DESC, l.listing_id DESC`)
	}
	limit := filters.Limit
	if limit < 1 || limit > 100 {
		limit = 20
	}
	if filters.Offset < 0 {
		filters.Offset = 0
	}
	query.WriteString(` LIMIT ? OFFSET ?`)
	args = append(args, limit, filters.Offset)

	rows, err := s.db.QueryContext(ctx, query.String(), args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]model.Listing, 0)
	for rows.Next() {
		item, err := scanListing(rows)
		if err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	if err := s.attachImages(ctx, items); err != nil {
		return nil, err
	}
	return items, nil
}

func baseListingSelect() string {
	return `SELECT l.listing_id, l.seller_id, l.category_id, l.title, COALESCE(l.description, ''),
		COALESCE(l.brand, ''), COALESCE(l.location, ''), l.price, l.currency, l.item_condition, l.status,
		l.moderation_status, l.scam_score, COALESCE(l.scam_label, ''), l.created_at, l.updated_at, l.revision,
		c.category_id, c.name, c.slug, c.is_active, c.created_at,
		u.user_id, u.username, u.created_at, COALESCE(p.full_name, '')
		FROM listings l
		JOIN categories c ON c.category_id = l.category_id
		JOIN users u ON u.user_id = l.seller_id
		LEFT JOIN user_profiles p ON p.user_id = u.user_id`
}

type scanner interface {
	Scan(dest ...any) error
}

func scanListing(row scanner) (model.Listing, error) {
	var item model.Listing
	var score sql.NullFloat64
	var category model.Category
	var seller model.User
	var fullName string
	err := row.Scan(
		&item.ListingID, &item.SellerID, &item.CategoryID, &item.Title, &item.Description,
		&item.Brand, &item.Location, &item.Price, &item.Currency, &item.ItemCondition,
		&item.Status, &item.ModerationStatus, &score, &item.ScamLabel, &item.CreatedAt, &item.UpdatedAt, &item.Revision,
		&category.CategoryID, &category.Name, &category.Slug, &category.IsActive, &category.CreatedAt,
		&seller.UserID, &seller.Username, &seller.CreatedAt, &fullName,
	)
	if err != nil {
		return model.Listing{}, err
	}
	if score.Valid {
		item.ScamScore = &score.Float64
	}
	seller.Profile = &model.Profile{UserID: seller.UserID, FullName: fullName}
	item.Category = &category
	item.Seller = &seller
	item.Images = []model.ListingImage{}
	return item, nil
}

func (s *Store) listImages(ctx context.Context, listingID int64) ([]model.ListingImage, error) {
	rows, err := s.db.QueryContext(ctx, `
		SELECT image_id, listing_id, image_url, sort_order, is_primary
		FROM listing_images WHERE listing_id = ? ORDER BY is_primary DESC, sort_order, image_id`, listingID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	items := make([]model.ListingImage, 0)
	for rows.Next() {
		var item model.ListingImage
		if err := rows.Scan(&item.ImageID, &item.ListingID, &item.ImageURL, &item.SortOrder, &item.IsPrimary); err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func (s *Store) ListListingImages(ctx context.Context, listingID int64) ([]model.ListingImage, error) {
	return s.listImages(ctx, listingID)
}

// ListingImageURLExists is used by the local-media serving boundary. A file is
// public only while the exact generated URL is referenced by its listing row;
// an interrupted upload or account deletion therefore cannot expose an orphan.
func (s *Store) ListingImageURLExists(ctx context.Context, listingID int64, imageURL string) (bool, error) {
	var exists int
	err := s.db.QueryRowContext(ctx, `
		SELECT 1 FROM listing_images
		WHERE listing_id = ? AND image_url = ? LIMIT 1`, listingID, imageURL).Scan(&exists)
	if errors.Is(err, sql.ErrNoRows) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	return true, nil
}

func (s *Store) attachImages(ctx context.Context, listings []model.Listing) error {
	if len(listings) == 0 {
		return nil
	}
	args := make([]any, 0, len(listings))
	index := make(map[int64]int, len(listings))
	for position := range listings {
		args = append(args, listings[position].ListingID)
		index[listings[position].ListingID] = position
		listings[position].Images = []model.ListingImage{}
	}
	rows, err := s.db.QueryContext(ctx, `SELECT image_id, listing_id, image_url, sort_order, is_primary
		FROM listing_images WHERE listing_id IN (`+placeholders(len(args))+`)
		ORDER BY listing_id, is_primary DESC, sort_order, image_id`, args...)
	if err != nil {
		return err
	}
	defer rows.Close()
	for rows.Next() {
		var image model.ListingImage
		if err := rows.Scan(&image.ImageID, &image.ListingID, &image.ImageURL, &image.SortOrder, &image.IsPrimary); err != nil {
			return err
		}
		if position, ok := index[image.ListingID]; ok {
			listings[position].Images = append(listings[position].Images, image)
		}
	}
	return rows.Err()
}

func replaceImages(ctx context.Context, tx *sql.Tx, listingID int64, images []ImageInput) error {
	if _, err := tx.ExecContext(ctx, `DELETE FROM listing_images WHERE listing_id = ?`, listingID); err != nil {
		return err
	}
	hasExplicitPrimary := false
	for _, image := range images {
		if image.IsPrimary {
			hasExplicitPrimary = true
			break
		}
	}
	for index, image := range images {
		isPrimary := image.IsPrimary || (!hasExplicitPrimary && index == 0)
		if _, err := tx.ExecContext(ctx, `
			INSERT INTO listing_images (listing_id, image_url, sort_order, is_primary)
			VALUES (?, ?, ?, ?)`, listingID, image.ImageURL, image.SortOrder, isPrimary); err != nil {
			return err
		}
	}
	return nil
}

func insertAssessment(ctx context.Context, tx *sql.Tx, listingID int64, assessment AssessmentInput) error {
	reasons, err := json.Marshal(assessment.Reasons)
	if err != nil {
		return err
	}
	_, err = tx.ExecContext(ctx, `
		INSERT INTO scam_assessments (listing_id, score, label, reasons, model_version)
		VALUES (?, ?, ?, ?, ?)`, listingID, assessment.Score, assessment.Label, reasons, assessment.ModelVersion)
	return err
}

func enqueueListing(ctx context.Context, tx *sql.Tx, listingID int64, eventType string) error {
	payload := fmt.Sprintf(`{"listing_id":%d}`, listingID)
	_, err := tx.ExecContext(ctx, `
		INSERT INTO outbox_events (aggregate_id, event_type, payload)
		VALUES (?, ?, ?)`, strconv.FormatInt(listingID, 10), eventType, payload)
	return err
}

func placeholders(count int) string {
	if count <= 0 {
		return ""
	}
	return strings.TrimSuffix(strings.Repeat("?,", count), ",")
}

func ListingText(item model.Listing) string {
	return strings.TrimSpace(strings.Join([]string{
		item.Title,
		item.Brand,
		item.Description,
		item.ItemCondition,
		item.Location,
		func() string {
			if item.Category != nil {
				return item.Category.Name
			}
			return ""
		}(),
	}, "\n"))
}

func (s *Store) AddListingImage(ctx context.Context, listingID, actorID int64, isAdmin bool, input ImageInput) (model.ListingImage, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.ListingImage{}, err
	}
	defer tx.Rollback()
	if !isAdmin {
		var actorActive int
		if err := tx.QueryRowContext(ctx, `
			SELECT 1 FROM users
			WHERE user_id = ? AND status = 'active' AND email_verified_at IS NOT NULL
			FOR SHARE`, actorID).Scan(&actorActive); err != nil {
			return model.ListingImage{}, normalizeSQLError(err)
		}
	}
	var sellerID int64
	if err := tx.QueryRowContext(ctx, `SELECT seller_id FROM listings WHERE listing_id = ? FOR UPDATE`, listingID).Scan(&sellerID); err != nil {
		return model.ListingImage{}, normalizeSQLError(err)
	}
	if sellerID != actorID && !isAdmin {
		return model.ListingImage{}, ErrNotFound
	}
	var sellerActive int
	if err := tx.QueryRowContext(ctx, `
		SELECT 1 FROM users
		WHERE user_id = ? AND status = 'active' AND email_verified_at IS NOT NULL
		FOR SHARE`, sellerID).Scan(&sellerActive); err != nil {
		return model.ListingImage{}, normalizeSQLError(err)
	}
	var imageCount int
	if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM listing_images WHERE listing_id = ?`, listingID).Scan(&imageCount); err != nil {
		return model.ListingImage{}, err
	}
	if imageCount >= 10 {
		return model.ListingImage{}, ErrInvalidState
	}
	if imageCount == 0 {
		input.IsPrimary = true
	}
	if input.IsPrimary {
		if _, err := tx.ExecContext(ctx, `UPDATE listing_images SET is_primary = FALSE WHERE listing_id = ?`, listingID); err != nil {
			return model.ListingImage{}, err
		}
	}
	result, err := tx.ExecContext(ctx, `INSERT INTO listing_images (listing_id, image_url, sort_order, is_primary) VALUES (?, ?, ?, ?)`, listingID, input.ImageURL, input.SortOrder, input.IsPrimary)
	if err != nil {
		if isDuplicate(err) {
			return model.ListingImage{}, ErrConflict
		}
		return model.ListingImage{}, err
	}
	id, err := result.LastInsertId()
	if err != nil {
		return model.ListingImage{}, err
	}
	if _, err := tx.ExecContext(ctx, `UPDATE listings SET updated_at = CURRENT_TIMESTAMP(6), revision = revision + 1 WHERE listing_id = ?`, listingID); err != nil {
		return model.ListingImage{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.ListingImage{}, err
	}
	return model.ListingImage{ImageID: id, ListingID: listingID, ImageURL: input.ImageURL, SortOrder: input.SortOrder, IsPrimary: input.IsPrimary}, nil
}

func (s *Store) DeleteListingImage(ctx context.Context, listingID, imageID, actorID int64, isAdmin bool) (model.ListingImage, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.ListingImage{}, err
	}
	defer tx.Rollback()
	var sellerID int64
	var image model.ListingImage
	if err := tx.QueryRowContext(ctx, `
		SELECT i.image_id, i.listing_id, i.image_url, i.sort_order, i.is_primary, l.seller_id
		FROM listing_images i JOIN listings l ON l.listing_id = i.listing_id
		WHERE i.image_id = ? AND i.listing_id = ? FOR UPDATE`, imageID, listingID).Scan(
		&image.ImageID, &image.ListingID, &image.ImageURL, &image.SortOrder, &image.IsPrimary, &sellerID); err != nil {
		return model.ListingImage{}, normalizeSQLError(err)
	}
	if sellerID != actorID && !isAdmin {
		return model.ListingImage{}, ErrNotFound
	}
	if _, err := tx.ExecContext(ctx, `DELETE FROM listing_images WHERE image_id = ? AND listing_id = ?`, imageID, listingID); err != nil {
		return model.ListingImage{}, err
	}
	if image.IsPrimary {
		if _, err := tx.ExecContext(ctx, `
			UPDATE listing_images SET is_primary = TRUE WHERE listing_id = ?
			ORDER BY sort_order, image_id LIMIT 1`, listingID); err != nil {
			return model.ListingImage{}, err
		}
	}
	if _, err := tx.ExecContext(ctx, `UPDATE listings SET updated_at = CURRENT_TIMESTAMP(6), revision = revision + 1 WHERE listing_id = ?`, listingID); err != nil {
		return model.ListingImage{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.ListingImage{}, err
	}
	return image, nil
}

func (s *Store) UpdateListingImage(ctx context.Context, listingID, imageID, actorID int64, isAdmin bool, input ImageInput) (model.ListingImage, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return model.ListingImage{}, err
	}
	defer tx.Rollback()
	var image model.ListingImage
	var sellerID int64
	err = tx.QueryRowContext(ctx, `
		SELECT i.image_id, i.listing_id, i.image_url, i.sort_order, i.is_primary, l.seller_id
		FROM listing_images i JOIN listings l ON l.listing_id = i.listing_id
		WHERE i.image_id = ? AND i.listing_id = ? FOR UPDATE`, imageID, listingID).Scan(
		&image.ImageID, &image.ListingID, &image.ImageURL, &image.SortOrder, &image.IsPrimary, &sellerID)
	if err != nil {
		return model.ListingImage{}, normalizeSQLError(err)
	}
	if sellerID != actorID && !isAdmin {
		return model.ListingImage{}, ErrNotFound
	}
	if input.IsPrimary {
		if _, err := tx.ExecContext(ctx, `UPDATE listing_images SET is_primary = FALSE WHERE listing_id = ?`, image.ListingID); err != nil {
			return model.ListingImage{}, err
		}
	}
	_, err = tx.ExecContext(ctx, `
		UPDATE listing_images SET image_url = ?, sort_order = ?, is_primary = ? WHERE image_id = ?`,
		input.ImageURL, input.SortOrder, input.IsPrimary, imageID)
	if err != nil {
		if isDuplicate(err) {
			return model.ListingImage{}, ErrConflict
		}
		return model.ListingImage{}, err
	}
	if image.IsPrimary && !input.IsPrimary {
		var primaryCount int
		if err := tx.QueryRowContext(ctx, `SELECT COUNT(*) FROM listing_images WHERE listing_id = ? AND is_primary = TRUE`, listingID).Scan(&primaryCount); err != nil {
			return model.ListingImage{}, err
		}
		if primaryCount == 0 {
			result, err := tx.ExecContext(ctx, `
				UPDATE listing_images SET is_primary = TRUE WHERE listing_id = ? AND image_id <> ?
				ORDER BY sort_order, image_id LIMIT 1`, listingID, imageID)
			if err != nil {
				return model.ListingImage{}, err
			}
			if affected, _ := result.RowsAffected(); affected == 0 {
				if _, err := tx.ExecContext(ctx, `UPDATE listing_images SET is_primary = TRUE WHERE image_id = ?`, imageID); err != nil {
					return model.ListingImage{}, err
				}
				input.IsPrimary = true
			}
		}
	}
	if _, err := tx.ExecContext(ctx, `UPDATE listings SET updated_at = CURRENT_TIMESTAMP(6), revision = revision + 1 WHERE listing_id = ?`, listingID); err != nil {
		return model.ListingImage{}, err
	}
	if err := tx.Commit(); err != nil {
		return model.ListingImage{}, err
	}
	image.ImageURL, image.SortOrder, image.IsPrimary = input.ImageURL, input.SortOrder, input.IsPrimary
	return image, nil
}

func (s *Store) LatestAssessment(ctx context.Context, listingID int64) (model.ScamAssessment, error) {
	var item model.ScamAssessment
	var reasons []byte
	err := s.db.QueryRowContext(ctx, `
		SELECT assessment_id, listing_id, score, label, reasons, model_version, created_at
		FROM scam_assessments WHERE listing_id = ? ORDER BY assessment_id DESC LIMIT 1`, listingID).Scan(
		&item.AssessmentID, &item.ListingID, &item.Score, &item.Label, &reasons, &item.ModelVersion, &item.CreatedAt)
	if err != nil {
		return model.ScamAssessment{}, normalizeSQLError(err)
	}
	_ = json.Unmarshal(reasons, &item.Reasons)
	return item, nil
}

func (s *Store) RecentListings(ctx context.Context, limit int) ([]model.Listing, error) {
	return s.ListListings(ctx, ListingFilters{Statuses: []string{"active"}, Moderation: []string{"approved"}, ActiveCategoriesOnly: true, Limit: limit})
}

func (s *Store) ListingsByIDs(ctx context.Context, ids []int64) ([]model.Listing, error) {
	if len(ids) == 0 {
		return []model.Listing{}, nil
	}
	query := baseListingSelect() + ` WHERE l.listing_id IN (` + placeholders(len(ids)) + `) AND l.status = 'active' AND l.moderation_status = 'approved' AND c.is_active = TRUE`
	args := make([]any, len(ids))
	for index, id := range ids {
		args[index] = id
	}
	rows, err := s.db.QueryContext(ctx, query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	byID := make(map[int64]model.Listing, len(ids))
	for rows.Next() {
		item, err := scanListing(rows)
		if err != nil {
			return nil, err
		}
		byID[item.ListingID] = item
	}
	if err := rows.Err(); err != nil {
		return nil, err
	}
	ordered := make([]model.Listing, 0, len(byID))
	for _, id := range ids {
		if item, ok := byID[id]; ok {
			ordered = append(ordered, item)
		}
	}
	if err := s.attachImages(ctx, ordered); err != nil {
		return nil, err
	}
	return ordered, nil
}

func (s *Store) UserPreferenceText(ctx context.Context, userID int64) (string, error) {
	rows, err := s.db.QueryContext(ctx, `
		SELECT l.title, COALESCE(l.brand, ''), COALESCE(l.description, ''), c.name
		FROM listings l
		JOIN categories c ON c.category_id = l.category_id
		LEFT JOIN favourites f ON f.listing_id = l.listing_id AND f.user_id = ?
		LEFT JOIN carts ca ON ca.user_id = ?
		LEFT JOIN cart_items ci ON ci.cart_id = ca.cart_id AND ci.listing_id = l.listing_id
		WHERE f.user_id IS NOT NULL OR ci.cart_id IS NOT NULL
		ORDER BY COALESCE(f.created_at, ci.updated_at) DESC LIMIT 20`, userID, userID)
	if err != nil {
		return "", err
	}
	defer rows.Close()
	parts := make([]string, 0)
	for rows.Next() {
		var title, brand, description, category string
		if err := rows.Scan(&title, &brand, &description, &category); err != nil {
			return "", err
		}
		parts = append(parts, strings.Join([]string{title, brand, description, category}, " "))
	}
	return strings.Join(parts, "\n"), rows.Err()
}

type OutboxEvent struct {
	EventID            int64
	AggregateID        string
	EventType          string
	Payload            json.RawMessage
	Attempts           int
	CreatedAt          time.Time
	ClaimToken         string
	EmbeddingInputHash string
	CachedEmbedding    []byte
}

const MaxOutboxAttempts = 8

func (s *Store) ClaimOutbox(ctx context.Context, limit int) ([]OutboxEvent, error) {
	if limit < 1 || limit > 100 {
		limit = 25
	}
	tx, err := s.db.BeginTx(ctx, &sql.TxOptions{Isolation: sql.LevelReadCommitted})
	if err != nil {
		return nil, err
	}
	defer tx.Rollback()
	leaseCutoff := time.Now().UTC().Add(-2 * time.Minute)
	rows, err := tx.QueryContext(ctx, `
		SELECT event_id, aggregate_id, event_type, payload, attempts, created_at,
		       embedding_input_hash, cached_embedding
		FROM outbox_events
		WHERE processed_at IS NULL AND dead_lettered_at IS NULL
		  AND next_attempt_at <= CURRENT_TIMESTAMP(6)
		  AND (claimed_at IS NULL OR claimed_at < ?)
		ORDER BY event_id LIMIT ? FOR UPDATE SKIP LOCKED`, leaseCutoff, limit)
	if err != nil {
		return nil, err
	}
	events := make([]OutboxEvent, 0)
	for rows.Next() {
		var event OutboxEvent
		var embeddingInputHash sql.NullString
		if err := rows.Scan(&event.EventID, &event.AggregateID, &event.EventType, &event.Payload, &event.Attempts, &event.CreatedAt, &embeddingInputHash, &event.CachedEmbedding); err != nil {
			rows.Close()
			return nil, err
		}
		if embeddingInputHash.Valid {
			event.EmbeddingInputHash = embeddingInputHash.String
		}
		events = append(events, event)
	}
	if err := rows.Close(); err != nil {
		return nil, err
	}
	if len(events) > 0 {
		claimToken, err := newClaimToken()
		if err != nil {
			return nil, err
		}
		args := make([]any, 0, len(events)+2)
		args = append(args, time.Now().UTC(), claimToken)
		for index := range events {
			events[index].ClaimToken = claimToken
			args = append(args, events[index].EventID)
		}
		if _, err := tx.ExecContext(ctx, `
			UPDATE outbox_events SET claimed_at = ?, claim_token = ?
			WHERE event_id IN (`+placeholders(len(events))+`)`, args...); err != nil {
			return nil, err
		}
	}
	if err := tx.Commit(); err != nil {
		return nil, err
	}
	return events, nil
}

func (s *Store) CompleteOutbox(ctx context.Context, eventID int64, claimToken string) error {
	result, err := s.db.ExecContext(ctx, `
		UPDATE outbox_events SET processed_at = CURRENT_TIMESTAMP(6), last_error = NULL,
		claimed_at = NULL, claim_token = NULL, embedding_input_hash = NULL, cached_embedding = NULL
		WHERE event_id = ? AND claim_token = ?`, eventID, claimToken)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected != 1 {
		return ErrConflict
	}
	return nil
}

func (s *Store) FailOutbox(ctx context.Context, eventID int64, claimToken string, processError error) error {
	result, err := s.db.ExecContext(ctx, `
		UPDATE outbox_events SET
		next_attempt_at = DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL LEAST(300, POW(2, attempts + 1)) SECOND),
		dead_lettered_at = CASE WHEN attempts + 1 >= ? THEN CURRENT_TIMESTAMP(6) ELSE NULL END,
		attempts = attempts + 1, last_error = LEFT(?, 1000), claimed_at = NULL, claim_token = NULL
		WHERE event_id = ? AND claim_token = ?`, MaxOutboxAttempts, processError.Error(), eventID, claimToken)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected != 1 {
		return ErrConflict
	}
	return nil
}

// DeferOutbox releases a lease and schedules another attempt without consuming
// the retry budget. Provider-budget exhaustion can outlast the normal retry
// backoff and must not dead-letter otherwise healthy indexing work.
func (s *Store) DeferOutbox(ctx context.Context, eventID int64, claimToken string, delay time.Duration, processError error) error {
	if delay <= 0 {
		delay = 15 * time.Minute
	}
	message := "processing deferred"
	if processError != nil {
		message = processError.Error()
	}
	result, err := s.db.ExecContext(ctx, `
		UPDATE outbox_events SET next_attempt_at = ?, last_error = LEFT(?, 1000),
		claimed_at = NULL, claim_token = NULL
		WHERE event_id = ? AND claim_token = ?`, time.Now().UTC().Add(delay), message, eventID, claimToken)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected != 1 {
		return ErrConflict
	}
	return nil
}

func newClaimToken() (string, error) {
	raw := make([]byte, 16)
	if _, err := rand.Read(raw); err != nil {
		return "", err
	}
	return hex.EncodeToString(raw), nil
}
