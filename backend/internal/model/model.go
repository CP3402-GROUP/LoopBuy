package model

import "time"

type User struct {
	UserID        int64     `json:"user_id"`
	Username      string    `json:"username"`
	Email         string    `json:"email,omitempty"`
	EmailVerified bool      `json:"email_verified"`
	Role          string    `json:"role,omitempty"`
	Status        string    `json:"status,omitempty"`
	CreatedAt     time.Time `json:"created_at"`
	Profile       *Profile  `json:"profile,omitempty"`
}

type Profile struct {
	UserID       int64     `json:"user_id"`
	FullName     string    `json:"full_name"`
	Phone        string    `json:"phone"`
	Location     string    `json:"location"`
	Bio          string    `json:"bio"`
	ProfileImage string    `json:"profile_image"`
	UpdatedAt    time.Time `json:"updated_at"`
}

type Category struct {
	CategoryID int64     `json:"category_id"`
	Name       string    `json:"name"`
	Slug       string    `json:"slug"`
	IsActive   bool      `json:"is_active"`
	CreatedAt  time.Time `json:"created_at"`
}

type Listing struct {
	ListingID        int64          `json:"listing_id"`
	SellerID         int64          `json:"seller_id"`
	CategoryID       int64          `json:"category_id"`
	Title            string         `json:"title"`
	Description      string         `json:"description"`
	Brand            string         `json:"brand"`
	Location         string         `json:"location"`
	Price            float64        `json:"price"`
	Currency         string         `json:"currency"`
	ItemCondition    string         `json:"item_condition"`
	Status           string         `json:"status"`
	ModerationStatus string         `json:"moderation_status"`
	ScamScore        *float64       `json:"scam_score,omitempty"`
	ScamLabel        string         `json:"scam_label,omitempty"`
	CreatedAt        time.Time      `json:"created_at"`
	UpdatedAt        time.Time      `json:"updated_at"`
	Revision         uint64         `json:"revision"`
	Category         *Category      `json:"category,omitempty"`
	Seller           *User          `json:"seller,omitempty"`
	Images           []ListingImage `json:"images"`
	SimilarityScore  *float64       `json:"similarity_score,omitempty"`
}

type ListingImage struct {
	ImageID   int64  `json:"image_id"`
	ListingID int64  `json:"listing_id"`
	ImageURL  string `json:"image_url"`
	SortOrder int    `json:"sort_order"`
	IsPrimary bool   `json:"is_primary"`
}

type CartItem struct {
	Listing  Listing `json:"listing"`
	Quantity int     `json:"quantity"`
}

type Cart struct {
	CartID    int64      `json:"cart_id"`
	UserID    int64      `json:"user_id"`
	Items     []CartItem `json:"items"`
	CreatedAt time.Time  `json:"created_at"`
	UpdatedAt time.Time  `json:"updated_at"`
}

type Conversation struct {
	ConversationID int64                `json:"conversation_id"`
	ListingID      int64                `json:"listing_id"`
	BuyerID        int64                `json:"buyer_id"`
	SellerID       int64                `json:"seller_id"`
	CreatedAt      time.Time            `json:"created_at"`
	UpdatedAt      time.Time            `json:"updated_at"`
	Members        []ConversationMember `json:"members"`
	LastMessage    *Message             `json:"last_message,omitempty"`
}

type ConversationMember struct {
	User     User      `json:"user"`
	Role     string    `json:"role"`
	JoinedAt time.Time `json:"joined_at"`
}

type Message struct {
	MessageID      int64     `json:"message_id"`
	ConversationID int64     `json:"conversation_id"`
	SenderID       int64     `json:"sender_id"`
	MessageText    string    `json:"message_text"`
	CreatedAt      time.Time `json:"created_at"`
}

type ScamAssessment struct {
	AssessmentID    int64     `json:"assessment_id"`
	ListingID       int64     `json:"listing_id,omitempty"`
	Score           float64   `json:"score"`
	Label           string    `json:"label"`
	Reasons         []string  `json:"reasons"`
	RiskSignalCount *int      `json:"risk_signal_count,omitempty"`
	ModelVersion    string    `json:"model_version"`
	CreatedAt       time.Time `json:"created_at"`
}

type ChatSource struct {
	ListingID int64   `json:"listing_id"`
	Title     string  `json:"title"`
	Price     float64 `json:"price"`
	Currency  string  `json:"currency"`
	Score     float64 `json:"score"`
}

type AIChatSession struct {
	SessionID int64     `json:"session_id"`
	UserID    int64     `json:"user_id"`
	Title     string    `json:"title"`
	CreatedAt time.Time `json:"created_at"`
	UpdatedAt time.Time `json:"updated_at"`
}

type AIChatMessage struct {
	MessageID        int64        `json:"message_id"`
	SessionID        int64        `json:"session_id"`
	Role             string       `json:"role"`
	Content          string       `json:"content"`
	Model            string       `json:"model,omitempty"`
	PromptTokens     int          `json:"prompt_tokens,omitempty"`
	CompletionTokens int          `json:"completion_tokens,omitempty"`
	Sources          []ChatSource `json:"sources,omitempty"`
	CreatedAt        time.Time    `json:"created_at"`
}
