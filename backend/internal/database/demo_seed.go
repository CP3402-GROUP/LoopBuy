package database

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"sort"
)

const demoPasswordHash = "!demo-account-has-no-password-login!"

type DemoSeedConfig struct {
	ImageURLs map[string]string
}

type demoUser struct {
	Username string
	Email    string
	FullName string
	Location string
	Bio      string
}

type demoListing struct {
	Key         string
	Seller      string
	Category    string
	Image       string
	Title       string
	Description string
	Brand       string
	Location    string
	Price       string
	Condition   string
}

var demoUsers = []demoUser{
	{Username: "loopbuy_alex", Email: "alex@demo.loopbuy.example", FullName: "Alex Tan", Location: "Bishan", Bio: "Tech enthusiast giving well-kept gear a second life."},
	{Username: "loopbuy_maya", Email: "maya@demo.loopbuy.example", FullName: "Maya Lim", Location: "Tampines", Bio: "Weekend cyclist and practical home declutterer."},
	{Username: "loopbuy_noah", Email: "noah@demo.loopbuy.example", FullName: "Noah Lee", Location: "Queenstown", Bio: "Books, music and furniture from a smoke-free home."},
}

var demoListings = []demoListing{
	{Key: "headphones", Seller: "loopbuy_alex", Category: "electronics", Image: "headphones", Title: "Sony WH-1000XM5 wireless headphones", Description: "Clean, fully working headphones with the original case and charging cable. Battery comfortably lasts through a work week.", Brand: "Sony", Location: "Bishan", Price: "248.00", Condition: "like_new"},
	{Key: "iphone", Seller: "loopbuy_alex", Category: "electronics", Image: "iphone", Title: "iPhone 13 128GB in midnight", Description: "Unlocked phone with 88% battery health. Includes a protective case and USB-C to Lightning cable.", Brand: "Apple", Location: "Bishan", Price: "520.00", Condition: "good"},
	{Key: "keyboard", Seller: "loopbuy_alex", Category: "gaming", Image: "keyboard", Title: "Compact mechanical gaming keyboard", Description: "Hot-swappable keyboard with tactile switches and working RGB lighting. Freshly cleaned and ready to use.", Brand: "Keychron", Location: "Bishan", Price: "72.00", Condition: "good"},
	{Key: "bicycle", Seller: "loopbuy_maya", Category: "sports", Image: "bicycle", Title: "Trek mountain bike, serviced", Description: "Reliable aluminium-frame bike with recently adjusted brakes and gears. Suitable for park connectors and light trails.", Brand: "Trek", Location: "Tampines", Price: "390.00", Condition: "good"},
	{Key: "tennis-racket", Seller: "loopbuy_maya", Category: "sports", Image: "tennis-racket", Title: "Wilson tennis racket with cover", Description: "Comfortable beginner-to-intermediate racket. Grip and strings are in good usable condition.", Brand: "Wilson", Location: "Tampines", Price: "48.00", Condition: "good"},
	{Key: "jacket", Seller: "loopbuy_maya", Category: "fashion", Image: "jacket", Title: "Classic denim jacket, size M", Description: "Medium-wash denim jacket with no stains or tears. Relaxed fit and easy to layer.", Brand: "Levi's", Location: "Tampines", Price: "42.00", Condition: "like_new"},
	{Key: "air-fryer", Seller: "loopbuy_maya", Category: "home-appliances", Image: "air-fryer", Title: "Digital air fryer, 4.2 litre", Description: "Evenly heats and the basket is easy to clean. Selling after moving to a built-in oven.", Brand: "Philips", Location: "Tampines", Price: "68.00", Condition: "good"},
	{Key: "book", Seller: "loopbuy_noah", Category: "books", Image: "book", Title: "Atomic Habits paperback", Description: "Paperback edition with clean pages and only light shelf wear. No highlighting or annotations.", Brand: "Penguin", Location: "Queenstown", Price: "12.00", Condition: "good"},
	{Key: "sofa", Seller: "loopbuy_noah", Category: "furniture", Image: "sofa", Title: "Three-seater fabric sofa", Description: "Comfortable neutral-grey sofa from a smoke-free home. Buyer arranges collection from a lift-access floor.", Brand: "IKEA", Location: "Queenstown", Price: "180.00", Condition: "good"},
	{Key: "guitar", Seller: "loopbuy_noah", Category: "others", Image: "guitar", Title: "Acoustic guitar with padded bag", Description: "Full-size acoustic guitar with a comfortable action. Includes a padded gig bag and spare picks.", Brand: "Yamaha", Location: "Queenstown", Price: "135.00", Condition: "good"},
	{Key: "coffee-machine", Seller: "loopbuy_noah", Category: "home-appliances", Image: "coffee-machine", Title: "Compact espresso coffee machine", Description: "Working espresso machine with steam wand, portafilter and two baskets. Descaled before listing.", Brand: "De'Longhi", Location: "Queenstown", Price: "115.00", Condition: "good"},
	{Key: "suitcase", Seller: "loopbuy_noah", Category: "others", Image: "suitcase", Title: "Samsonite medium travel suitcase", Description: "Four smooth spinner wheels, working combination lock and a clean interior. A few cosmetic marks from normal travel.", Brand: "Samsonite", Location: "Queenstown", Price: "85.00", Condition: "good"},
}

// SeedDemo installs presentation content in one transaction. It is safe to
// call repeatedly: stable users and registry keys prevent duplicate rows.
func SeedDemo(ctx context.Context, db *sql.DB, config DemoSeedConfig) error {
	if ctx == nil || db == nil {
		return errors.New("database: demo seed requires a context and database")
	}
	if err := validateDemoImageURLs(config.ImageURLs); err != nil {
		return err
	}
	tx, err := db.BeginTx(ctx, nil)
	if err != nil {
		return fmt.Errorf("database: begin demo seed: %w", err)
	}
	defer tx.Rollback()

	userIDs := make(map[string]int64, len(demoUsers))
	for _, user := range demoUsers {
		if _, err := tx.ExecContext(ctx, `
			INSERT IGNORE INTO users
			(username, email, password_hash, email_verified_at, role, status)
			VALUES (?, ?, ?, CURRENT_TIMESTAMP(6), 'user', 'active')`,
			user.Username, user.Email, demoPasswordHash); err != nil {
			return fmt.Errorf("database: seed user %s: %w", user.Username, err)
		}
		var userID int64
		if err := tx.QueryRowContext(ctx, `
			SELECT user_id FROM users WHERE username = ? AND email = ? AND status = 'active'`,
			user.Username, user.Email).Scan(&userID); err != nil {
			return fmt.Errorf("database: resolve demo user %s: %w", user.Username, err)
		}
		userIDs[user.Username] = userID
		if _, err := tx.ExecContext(ctx, `
			INSERT IGNORE INTO user_profiles (user_id, full_name, location, bio)
			VALUES (?, ?, ?, ?)`, userID, user.FullName, user.Location, user.Bio); err != nil {
			return fmt.Errorf("database: seed profile %s: %w", user.Username, err)
		}
		if _, err := tx.ExecContext(ctx, `INSERT IGNORE INTO carts (user_id) VALUES (?)`, userID); err != nil {
			return fmt.Errorf("database: seed cart %s: %w", user.Username, err)
		}
	}

	for _, listing := range demoListings {
		listingID, created, err := seedDemoListing(ctx, tx, userIDs[listing.Seller], listing, config.ImageURLs[listing.Image])
		if err != nil {
			return err
		}
		if created {
			if _, err := tx.ExecContext(ctx, `
			INSERT INTO outbox_events (aggregate_id, event_type, payload)
			VALUES (?, 'listing.upsert', JSON_OBJECT('listing_id', ?))`, listingID, listingID); err != nil {
				return fmt.Errorf("database: enqueue demo listing %s: %w", listing.Key, err)
			}
		}
	}

	// Replace the historical smoke-test placeholder that caused the visibly
	// broken Sony card without touching genuine external images.
	repairResult, err := tx.ExecContext(ctx, `
		UPDATE listing_images AS image
		JOIN listings AS listing ON listing.listing_id = image.listing_id
		SET image.image_url = ?
		WHERE listing.title = 'Sony WH-1000XM5 headphones'
		  AND image.image_url = 'https://example.com/headphones.jpg'`, config.ImageURLs["headphones"])
	if err != nil {
		return fmt.Errorf("database: repair historical placeholder image: %w", err)
	}
	if affected, _ := repairResult.RowsAffected(); affected > 0 {
		if _, err := tx.ExecContext(ctx, `
			UPDATE listings AS listing
			JOIN listing_images AS image ON image.listing_id = listing.listing_id
			SET listing.updated_at = CURRENT_TIMESTAMP(6), listing.revision = listing.revision + 1
			WHERE listing.title = 'Sony WH-1000XM5 headphones' AND image.image_url = ?`, config.ImageURLs["headphones"]); err != nil {
			return fmt.Errorf("database: advance repaired listing revision: %w", err)
		}
	}

	if err := tx.Commit(); err != nil {
		return fmt.Errorf("database: commit demo seed: %w", err)
	}
	return nil
}

func seedDemoListing(ctx context.Context, tx *sql.Tx, sellerID int64, listing demoListing, imageURL string) (int64, bool, error) {
	var listingID int64
	err := tx.QueryRowContext(ctx, `SELECT listing_id FROM demo_seed_listings WHERE seed_key = ?`, listing.Key).Scan(&listingID)
	if err == nil {
		result, err := tx.ExecContext(ctx, `
			UPDATE listing_images SET image_url = ?
			WHERE listing_id = ? AND sort_order = 0 AND image_url <> ?`, imageURL, listingID, imageURL)
		if err != nil {
			return 0, false, fmt.Errorf("database: refresh demo image %s: %w", listing.Key, err)
		}
		if affected, _ := result.RowsAffected(); affected > 0 {
			if _, err := tx.ExecContext(ctx, `
				UPDATE listings SET updated_at = CURRENT_TIMESTAMP(6), revision = revision + 1
				WHERE listing_id = ?`, listingID); err != nil {
				return 0, false, fmt.Errorf("database: advance demo listing revision %s: %w", listing.Key, err)
			}
		}
		return listingID, false, nil
	}
	if !errors.Is(err, sql.ErrNoRows) {
		return 0, false, fmt.Errorf("database: look up demo listing %s: %w", listing.Key, err)
	}

	var categoryID int64
	if err := tx.QueryRowContext(ctx, `SELECT category_id FROM categories WHERE slug = ? AND is_active = TRUE`, listing.Category).Scan(&categoryID); err != nil {
		return 0, false, fmt.Errorf("database: resolve demo category %s: %w", listing.Category, err)
	}
	result, err := tx.ExecContext(ctx, `
		INSERT INTO listings
		(seller_id, category_id, title, description, brand, location, price, currency,
		 item_condition, status, moderation_status, scam_score, scam_label)
		VALUES (?, ?, ?, ?, ?, ?, ?, 'SGD', ?, 'active', 'approved', 0.0500, 'low_risk')`,
		sellerID, categoryID, listing.Title, listing.Description, listing.Brand,
		listing.Location, listing.Price, listing.Condition)
	if err != nil {
		return 0, false, fmt.Errorf("database: insert demo listing %s: %w", listing.Key, err)
	}
	listingID, err = result.LastInsertId()
	if err != nil {
		return 0, false, fmt.Errorf("database: read demo listing ID %s: %w", listing.Key, err)
	}
	if _, err := tx.ExecContext(ctx, `
		INSERT INTO listing_images (listing_id, image_url, sort_order, is_primary)
		VALUES (?, ?, 0, TRUE)`, listingID, imageURL); err != nil {
		return 0, false, fmt.Errorf("database: insert demo image %s: %w", listing.Key, err)
	}
	if _, err := tx.ExecContext(ctx, `
		INSERT INTO scam_assessments
		(listing_id, model_name, model_version, status, score, label, reasons, completed_at)
		VALUES (?, 'demo-seed', '1', 'completed', 0.0500, 'low_risk',
		        JSON_ARRAY('Curated demonstration listing'), CURRENT_TIMESTAMP(6))`, listingID); err != nil {
		return 0, false, fmt.Errorf("database: insert demo assessment %s: %w", listing.Key, err)
	}
	if _, err := tx.ExecContext(ctx, `
		INSERT INTO demo_seed_listings (seed_key, listing_id) VALUES (?, ?)`, listing.Key, listingID); err != nil {
		return 0, false, fmt.Errorf("database: register demo listing %s: %w", listing.Key, err)
	}
	return listingID, true, nil
}

func validateDemoImageURLs(imageURLs map[string]string) error {
	missing := make([]string, 0)
	for _, listing := range demoListings {
		if imageURLs[listing.Image] == "" {
			missing = append(missing, listing.Image)
		}
	}
	if len(missing) > 0 {
		sort.Strings(missing)
		return fmt.Errorf("database: demo seed is missing image URLs: %v", missing)
	}
	return nil
}
