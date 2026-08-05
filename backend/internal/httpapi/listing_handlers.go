package httpapi

import (
	"errors"
	"math"
	"net/http"
	"net/url"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/ml"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/model"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/store"
)

var currencyPattern = regexp.MustCompile(`^[A-Z]{3}$`)

type listingRequest struct {
	Revision      *uint64             `json:"revision"`
	CategoryID    *int64              `json:"category_id"`
	Title         *string             `json:"title"`
	Description   *string             `json:"description"`
	Brand         *string             `json:"brand"`
	Location      *string             `json:"location"`
	Price         *float64            `json:"price"`
	Currency      *string             `json:"currency"`
	ItemCondition *string             `json:"item_condition"`
	Images        *[]store.ImageInput `json:"images"`
}

func (s *Server) listListings(response http.ResponseWriter, request *http.Request) {
	query := request.URL.Query()
	search := strings.TrimSpace(firstQuery(query, "q", "product_search"))
	location := strings.TrimSpace(query.Get("location"))
	category := strings.TrimSpace(query.Get("category"))
	if utf8.RuneCountInString(search) > 120 || utf8.RuneCountInString(location) > 120 || utf8.RuneCountInString(category) > 100 {
		writeProblem(response, request, http.StatusBadRequest, "Invalid filter", "Search, location, or category filter is too long.")
		return
	}
	filters := store.ListingFilters{
		Query: search, Category: category,
		Condition: normalizeCondition(query.Get("condition")), Location: location,
		Sort: query.Get("sort"), Limit: intQuery(query.Get("limit"), 20, 1, 100),
		Offset: intQuery(query.Get("offset"), 0, 0, 10_000), ActiveCategoriesOnly: true,
	}
	if value := query.Get("min_price"); value != "" {
		if parsed, err := strconv.ParseFloat(value, 64); err == nil && parsed >= 0 {
			filters.MinPrice = &parsed
		} else {
			writeProblem(response, request, http.StatusBadRequest, "Invalid filter", "min_price must be a non-negative number.")
			return
		}
	}
	if value := query.Get("max_price"); value != "" {
		if parsed, err := strconv.ParseFloat(value, 64); err == nil && parsed >= 0 {
			filters.MaxPrice = &parsed
		} else {
			writeProblem(response, request, http.StatusBadRequest, "Invalid filter", "max_price must be a non-negative number.")
			return
		}
	}
	if value := query.Get("seller_id"); value != "" {
		if parsed, err := strconv.ParseInt(value, 10, 64); err == nil && parsed > 0 {
			filters.SellerID = parsed
		}
	}
	claims := currentClaims(request)
	if filters.SellerID > 0 && (claims.UserID == filters.SellerID || claims.Role == "admin" || claims.Role == "moderator") {
		filters.Statuses = []string{"active", "under_review", "sold", "archived"}
		filters.Moderation = []string{"approved", "pending", "rejected", "review", "unavailable"}
		filters.ActiveCategoriesOnly = false
	} else {
		filters.Statuses = []string{"active"}
		filters.Moderation = []string{"approved"}
	}
	items, err := s.store.ListListings(request.Context(), filters)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, map[string]any{"items": items, "limit": filters.Limit, "offset": filters.Offset})
}

func (s *Server) getListing(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	item, err := s.store.GetListing(request.Context(), listingID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	claims := currentClaims(request)
	if (item.Status != "active" || item.ModerationStatus != "approved" || item.Category == nil || !item.Category.IsActive) && claims.UserID != item.SellerID && claims.Role != "admin" && claims.Role != "moderator" {
		writeStoreError(response, request, store.ErrNotFound)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) createListing(response http.ResponseWriter, request *http.Request) {
	var payload listingRequest
	if err := decodeJSON(response, request, &payload); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}
	claims := currentClaims(request)
	if payload.Images != nil && len(*payload.Images) > 0 && claims.Role != "admin" {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "Create the listing first, then upload local images through its multipart image endpoint.")
		return
	}
	input, err := listingInput(payload, nil)
	if err != nil {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", err.Error())
		return
	}
	category, err := s.store.GetCategory(request.Context(), strconv.FormatInt(input.CategoryID, 10))
	if err != nil || !category.IsActive {
		if err == nil {
			err = store.ErrNotFound
		}
		writeStoreError(response, request, err)
		return
	}
	assessment := s.runScamAssessment(request, input)
	item, err := s.store.CreateListing(request.Context(), claims.UserID, input, assessment)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusCreated, item)
}

func (s *Server) updateListing(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	current, err := s.store.GetListing(request.Context(), listingID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	claims := currentClaims(request)
	if current.SellerID != claims.UserID && claims.Role != "admin" {
		writeStoreError(response, request, store.ErrNotFound)
		return
	}
	var payload listingRequest
	if err := decodeJSON(response, request, &payload); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}
	if payload.Revision == nil || *payload.Revision == 0 {
		writeProblem(response, request, http.StatusPreconditionRequired, "Precondition required", "PATCH requests must include the current listing revision.")
		return
	}
	if *payload.Revision != current.Revision {
		writeStoreError(response, request, store.ErrStaleWrite)
		return
	}
	if payload.Images != nil && claims.Role != "admin" {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "Listing images are managed through the local multipart upload and image endpoints.")
		return
	}
	input, err := listingInput(payload, &current)
	if err != nil {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", err.Error())
		return
	}
	assessment := s.runScamAssessment(request, input)
	item, err := s.store.UpdateListing(request.Context(), listingID, claims.UserID, claims.Role == "admin", *payload.Revision, input, assessment)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) deleteListing(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	claims := currentClaims(request)
	if err := s.store.DeleteListing(request.Context(), listingID, claims.UserID, claims.Role == "admin"); err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusNoContent, nil)
}

func (s *Server) updateListingStatus(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	var input struct {
		Status string `json:"status"`
	}
	if err := decodeJSON(response, request, &input); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}
	if input.Status != "active" && input.Status != "sold" && input.Status != "archived" {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "status must be active, sold, or archived.")
		return
	}
	claims := currentClaims(request)
	item, err := s.store.SetListingStatus(request.Context(), listingID, claims.UserID, claims.Role == "admin", input.Status)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) addListingImage(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	claims := currentClaims(request)
	if claims.Role != "admin" {
		writeProblem(response, request, http.StatusForbidden, "Forbidden", "Sellers must upload images through the local multipart upload endpoint.")
		return
	}
	var input store.ImageInput
	if err := decodeJSON(response, request, &input); err != nil || !validImageURL(input.ImageURL) || input.SortOrder < 0 || input.SortOrder > 1000 {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "A valid HTTP(S) image_url and sort_order between 0 and 1000 are required.")
		return
	}
	item, err := s.store.AddListingImage(request.Context(), listingID, claims.UserID, claims.Role == "admin", input)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusCreated, item)
}

func (s *Server) listListingImages(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	listing, err := s.store.GetListing(request.Context(), listingID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	claims := currentClaims(request)
	if (listing.Status != "active" || listing.ModerationStatus != "approved" || listing.Category == nil || !listing.Category.IsActive) && claims.UserID != listing.SellerID && claims.Role != "admin" && claims.Role != "moderator" {
		writeStoreError(response, request, store.ErrNotFound)
		return
	}
	writeJSON(response, http.StatusOK, map[string]any{"items": listing.Images})
}

func (s *Server) deleteListingImage(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	imageID, ok := pathID(response, request, "imageId")
	if !ok {
		return
	}
	claims := currentClaims(request)
	deleted, err := s.store.DeleteListingImage(request.Context(), listingID, imageID, claims.UserID, claims.Role == "admin")
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	if s.media != nil {
		if err := s.media.DeleteListingURL(listingID, deleted.ImageURL); err != nil {
			s.logger.Warn("remove deleted listing image", "image_id", imageID, "error", err)
		}
	}
	writeJSON(response, http.StatusNoContent, nil)
}

func (s *Server) updateListingImage(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	imageID, ok := pathID(response, request, "imageId")
	if !ok {
		return
	}
	listing, err := s.store.GetListing(request.Context(), listingID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	claims := currentClaims(request)
	if listing.SellerID != claims.UserID && claims.Role != "admin" {
		writeStoreError(response, request, store.ErrNotFound)
		return
	}
	var currentImage *model.ListingImage
	for index := range listing.Images {
		if listing.Images[index].ImageID == imageID {
			currentImage = &listing.Images[index]
			break
		}
	}
	if currentImage == nil {
		writeStoreError(response, request, store.ErrNotFound)
		return
	}
	var input store.ImageInput
	if err := decodeJSON(response, request, &input); err != nil || input.SortOrder < 0 || input.SortOrder > 1000 {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "sort_order between 0 and 1000 is required.")
		return
	}
	if claims.Role != "admin" {
		if input.ImageURL != "" && input.ImageURL != currentImage.ImageURL {
			writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "Sellers cannot replace a stored image URL; upload a new local image instead.")
			return
		}
		input.ImageURL = currentImage.ImageURL
	} else if input.ImageURL == "" || input.ImageURL == currentImage.ImageURL {
		input.ImageURL = currentImage.ImageURL
	} else if !validImageURL(input.ImageURL) {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "Administrator import image_url must use HTTP(S).")
		return
	}
	item, err := s.store.UpdateListingImage(request.Context(), listingID, imageID, claims.UserID, claims.Role == "admin", input)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) moderateListing(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	var input struct {
		ModerationStatus string `json:"moderation_status"`
	}
	if err := decodeJSON(response, request, &input); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}
	input.ModerationStatus = strings.ToLower(strings.TrimSpace(input.ModerationStatus))
	if input.ModerationStatus != "approved" && input.ModerationStatus != "rejected" && input.ModerationStatus != "review" {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "moderation_status must be approved, rejected, or review.")
		return
	}
	item, err := s.store.SetListingModeration(request.Context(), listingID, input.ModerationStatus)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) listModerationQueue(response http.ResponseWriter, request *http.Request) {
	query := request.URL.Query()
	search := strings.TrimSpace(firstQuery(query, "q"))
	category := strings.TrimSpace(query.Get("category"))
	if utf8.RuneCountInString(search) > 120 || utf8.RuneCountInString(category) > 100 {
		writeProblem(response, request, http.StatusBadRequest, "Invalid filter", "Search or category filter is too long.")
		return
	}
	moderationStatus := strings.ToLower(strings.TrimSpace(query.Get("moderation_status")))
	if moderationStatus == "" {
		moderationStatus = "pending"
	}
	allowedModeration := map[string]bool{
		"pending": true, "review": true, "rejected": true, "approved": true, "unavailable": true,
	}
	if !allowedModeration[moderationStatus] {
		writeProblem(response, request, http.StatusBadRequest, "Invalid filter", "moderation_status is not supported.")
		return
	}
	filters := store.ListingFilters{
		Query: search, Category: category,
		Statuses:   []string{"draft", "under_review", "active", "reserved", "sold", "archived"},
		Moderation: []string{moderationStatus}, Sort: query.Get("sort"),
		Limit: intQuery(query.Get("limit"), 50, 1, 100), Offset: intQuery(query.Get("offset"), 0, 0, 10_000),
	}
	items, err := s.store.ListListings(request.Context(), filters)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, map[string]any{"items": items, "limit": filters.Limit, "offset": filters.Offset})
}

func (s *Server) assessListing(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	current, err := s.store.GetListing(request.Context(), listingID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	claims := currentClaims(request)
	if current.SellerID != claims.UserID && claims.Role != "admin" {
		writeStoreError(response, request, store.ErrNotFound)
		return
	}
	if current.Category == nil || !current.Category.IsActive {
		writeStoreError(response, request, store.ErrInvalidState)
		return
	}
	images := make([]store.ImageInput, 0, len(current.Images))
	for _, image := range current.Images {
		images = append(images, store.ImageInput{ImageURL: image.ImageURL, SortOrder: image.SortOrder, IsPrimary: image.IsPrimary})
	}
	input := store.ListingInput{CategoryID: current.CategoryID, Title: current.Title, Description: current.Description,
		Brand: current.Brand, Location: current.Location, Price: current.Price, Currency: current.Currency,
		ItemCondition: current.ItemCondition, Images: images}
	assessment := s.runScamAssessment(request, input)
	if _, err := s.store.ReassessListing(request.Context(), listingID, claims.UserID, claims.Role == "admin", current.Revision, assessment); err != nil {
		writeStoreError(response, request, err)
		return
	}
	persisted, err := s.store.LatestAssessment(request.Context(), listingID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusCreated, persisted)
}

func (s *Server) latestAssessment(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	listing, err := s.store.GetListing(request.Context(), listingID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	claims := currentClaims(request)
	if claims.UserID != listing.SellerID && claims.Role != "admin" && claims.Role != "moderator" {
		writeStoreError(response, request, store.ErrNotFound)
		return
	}
	item, err := s.store.LatestAssessment(request.Context(), listingID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) runScamAssessment(request *http.Request, input store.ListingInput) store.AssessmentInput {
	category := ""
	if item, err := s.store.GetCategory(request.Context(), strconv.FormatInt(input.CategoryID, 10)); err == nil {
		category = item.Name
	}
	if s.ml == nil {
		return unavailableAssessment()
	}
	result, err := s.ml.Scam(request.Context(), ml.ScamInput{Title: input.Title, Description: input.Description, Price: input.Price, Category: category})
	if err != nil {
		s.logger.Warn("scam assessment unavailable", "error", err)
		return unavailableAssessment()
	}
	if !validAssessmentResult(result) {
		s.logger.Warn("scam assessment contract violation", "label", result.Label, "score", result.Score)
		return store.AssessmentInput{Score: 0.5, Label: "needs_review", Reasons: []string{"Automated screening returned an inconsistent result"}, ModelVersion: "invalid-provider-response"}
	}
	return store.AssessmentInput{Score: result.Score, Label: result.Label, Reasons: result.Reasons, ModelVersion: result.ModelVersion}
}

func validAssessmentResult(result ml.ScamResult) bool {
	if result.Score < 0 || result.Score > 1 || math.IsNaN(result.Score) || math.IsInf(result.Score, 0) || strings.TrimSpace(result.ModelVersion) == "" {
		return false
	}
	switch {
	case result.Score < 0.45:
		return result.Label == "low_risk"
	case result.Score < 0.78:
		return result.Label == "needs_review"
	default:
		return result.Label == "high_risk"
	}
}

func unavailableAssessment() store.AssessmentInput {
	return store.AssessmentInput{Score: 0.5, Label: "needs_review", Reasons: []string{"Automated screening is temporarily unavailable"}, ModelVersion: "unavailable"}
}

func listingInput(payload listingRequest, current *model.Listing) (store.ListingInput, error) {
	input := store.ListingInput{Currency: "SGD", Images: []store.ImageInput{}}
	if current != nil {
		input = store.ListingInput{CategoryID: current.CategoryID, Title: current.Title, Description: current.Description,
			Brand: current.Brand, Location: current.Location, Price: current.Price, Currency: current.Currency,
			ItemCondition: current.ItemCondition, Images: make([]store.ImageInput, 0, len(current.Images))}
		for _, image := range current.Images {
			input.Images = append(input.Images, store.ImageInput{ImageURL: image.ImageURL, SortOrder: image.SortOrder, IsPrimary: image.IsPrimary})
		}
	}
	if payload.CategoryID != nil {
		input.CategoryID = *payload.CategoryID
	}
	if payload.Title != nil {
		input.Title = strings.TrimSpace(*payload.Title)
	}
	if payload.Description != nil {
		input.Description = strings.TrimSpace(*payload.Description)
	}
	if payload.Brand != nil {
		input.Brand = strings.TrimSpace(*payload.Brand)
	}
	if payload.Location != nil {
		input.Location = strings.TrimSpace(*payload.Location)
	}
	if payload.Price != nil {
		input.Price = *payload.Price
	}
	if payload.Currency != nil {
		input.Currency = strings.ToUpper(strings.TrimSpace(*payload.Currency))
	}
	if payload.ItemCondition != nil {
		input.ItemCondition = normalizeCondition(*payload.ItemCondition)
	}
	if payload.Images != nil {
		input.Images = *payload.Images
	}
	if input.CategoryID < 1 || input.Title == "" || len(input.Title) > 150 || len(input.Description) > 10_000 ||
		len(input.Brand) > 100 || len(input.Location) > 120 || input.Price < 0 || input.Price > 99_999_999.99 ||
		!currencyPattern.MatchString(input.Currency) || !allowedCondition(input.ItemCondition) || len(input.Images) > 10 {
		return store.ListingInput{}, errors.New("listing fields are incomplete or outside allowed limits")
	}
	primaryCount := 0
	allSortOrdersZero := len(input.Images) > 1
	for index := range input.Images {
		if input.Images[index].SortOrder != 0 {
			allSortOrdersZero = false
			break
		}
	}
	seenSortOrders := make(map[int]struct{}, len(input.Images))
	for index := range input.Images {
		if !validImageURL(input.Images[index].ImageURL) {
			return store.ListingInput{}, errors.New("every image_url must be a valid HTTP(S) URL")
		}
		if allSortOrdersZero {
			input.Images[index].SortOrder = index
		}
		if input.Images[index].SortOrder < 0 || input.Images[index].SortOrder > 1000 {
			return store.ListingInput{}, errors.New("every image sort_order must be between 0 and 1000")
		}
		if _, exists := seenSortOrders[input.Images[index].SortOrder]; exists {
			return store.ListingInput{}, errors.New("image sort_order values must be unique")
		}
		seenSortOrders[input.Images[index].SortOrder] = struct{}{}
		if input.Images[index].IsPrimary {
			primaryCount++
		}
	}
	if primaryCount > 1 {
		return store.ListingInput{}, errors.New("only one image can be primary")
	}
	return input, nil
}

func validImageURL(value string) bool {
	parsed, err := url.ParseRequestURI(strings.TrimSpace(value))
	return err == nil && (parsed.Scheme == "http" || parsed.Scheme == "https") && parsed.Host != "" && len(value) <= 1024
}

func normalizeCondition(value string) string {
	return strings.ReplaceAll(strings.ToLower(strings.TrimSpace(value)), "-", "_")
}

func allowedCondition(value string) bool {
	return value == "new" || value == "like_new" || value == "good" || value == "fair"
}

func firstQuery(values url.Values, keys ...string) string {
	for _, key := range keys {
		if value := strings.TrimSpace(values.Get(key)); value != "" {
			return value
		}
	}
	return ""
}

func intQuery(value string, fallback, minimum, maximum int) int {
	parsed, err := strconv.Atoi(value)
	if err != nil || parsed < minimum || parsed > maximum {
		return fallback
	}
	return parsed
}
