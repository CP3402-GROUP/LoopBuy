package httpapi

import (
	"errors"
	"net/http"
	"regexp"
	"strconv"
	"strings"

	"github.com/CP3402-GROUP/LoopBuy/backend/internal/store"
)

var slugPattern = regexp.MustCompile(`^[a-z0-9]+(?:-[a-z0-9]+)*$`)

func (s *Server) getMe(response http.ResponseWriter, request *http.Request) {
	user, err := s.store.GetUser(request.Context(), currentClaims(request).UserID, true)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, user)
}

func (s *Server) getPublicUser(response http.ResponseWriter, request *http.Request) {
	userID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	user, err := s.store.GetUser(request.Context(), userID, false)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, user)
}

func (s *Server) updateMe(response http.ResponseWriter, request *http.Request) {
	current, err := s.store.GetUser(request.Context(), currentClaims(request).UserID, true)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	input := store.UpdateUserInput{
		Username: current.Username, Email: current.Email,
		FullName: current.Profile.FullName, Phone: current.Profile.Phone, Location: current.Profile.Location,
		Bio: current.Profile.Bio,
	}
	var patch struct {
		Username *string `json:"username"`
		Email    *string `json:"email"`
		FullName *string `json:"full_name"`
		Phone    *string `json:"phone"`
		Location *string `json:"location"`
		Bio      *string `json:"bio"`
	}
	if err := decodeJSON(response, request, &patch); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}
	assign := func(target *string, value *string, max int) bool {
		if value == nil {
			return true
		}
		trimmed := strings.TrimSpace(*value)
		if len(trimmed) > max {
			return false
		}
		*target = trimmed
		return true
	}
	if !assign(&input.Username, patch.Username, 50) || !assign(&input.Email, patch.Email, 254) ||
		!assign(&input.FullName, patch.FullName, 100) || !assign(&input.Phone, patch.Phone, 32) ||
		!assign(&input.Location, patch.Location, 120) || !assign(&input.Bio, patch.Bio, 2000) ||
		!usernamePattern.MatchString(input.Username) || !validEmail(input.Email) {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "One or more profile fields are invalid.")
		return
	}
	if !strings.EqualFold(input.Email, current.Email) {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "Email changes require a dedicated verification flow and are not supported by this endpoint.")
		return
	}
	updated, err := s.store.UpdateUser(request.Context(), current.UserID, input)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, updated)
}

func (s *Server) deleteMe(response http.ResponseWriter, request *http.Request) {
	userID := currentClaims(request).UserID
	var mediaCleanupErr error
	err := s.store.DeleteUser(request.Context(), userID, func(listingIDs []int64) error {
		if s.media == nil {
			mediaCleanupErr = errors.New("local media storage is unavailable")
			return nil
		}
		for _, listingID := range listingIDs {
			if cleanupErr := s.media.DeleteListingMedia(listingID); cleanupErr != nil {
				if mediaCleanupErr == nil {
					mediaCleanupErr = cleanupErr
				}
			}
		}
		if cleanupErr := s.media.DeleteUserAvatarMedia(userID); cleanupErr != nil && mediaCleanupErr == nil {
			mediaCleanupErr = cleanupErr
		}
		return nil
	})
	if err != nil {
		s.logger.Error("delete account", "user_id", userID, "error", err)
		writeStoreError(response, request, err)
		return
	}
	if mediaCleanupErr != nil {
		s.logger.Warn("delete inaccessible account media", "user_id", userID, "error", mediaCleanupErr)
	}
	writeJSON(response, http.StatusNoContent, nil)
}

func (s *Server) listCategories(response http.ResponseWriter, request *http.Request) {
	items, err := s.store.ListCategories(request.Context(), false)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, map[string]any{"items": items})
}

func (s *Server) getCategory(response http.ResponseWriter, request *http.Request) {
	item, err := s.store.GetCategory(request.Context(), request.PathValue("identifier"))
	if err != nil || !item.IsActive {
		writeStoreError(response, request, func() error {
			if err != nil {
				return err
			}
			return store.ErrNotFound
		}())
		return
	}
	writeJSON(response, http.StatusOK, item)
}

type categoryRequest struct {
	Name     string `json:"name"`
	Slug     string `json:"slug"`
	IsActive *bool  `json:"is_active,omitempty"`
}

func (s *Server) createCategory(response http.ResponseWriter, request *http.Request) {
	var input categoryRequest
	if err := decodeJSON(response, request, &input); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}
	input.Name, input.Slug = strings.TrimSpace(input.Name), strings.ToLower(strings.TrimSpace(input.Slug))
	if input.Name == "" || len(input.Name) > 100 || !slugPattern.MatchString(input.Slug) || len(input.Slug) > 100 {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "Category name or slug is invalid.")
		return
	}
	item, err := s.store.CreateCategory(request.Context(), input.Name, input.Slug)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusCreated, item)
}

func (s *Server) updateCategory(response http.ResponseWriter, request *http.Request) {
	categoryID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	current, err := s.store.GetCategory(request.Context(), strconv.FormatInt(categoryID, 10))
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	var input categoryRequest
	if err := decodeJSON(response, request, &input); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return
	}
	if input.Name == "" {
		input.Name = current.Name
	}
	if input.Slug == "" {
		input.Slug = current.Slug
	}
	input.Name = strings.TrimSpace(input.Name)
	input.Slug = strings.ToLower(strings.TrimSpace(input.Slug))
	isActive := current.IsActive
	if input.IsActive != nil {
		isActive = *input.IsActive
	}
	if input.Name == "" || len(input.Name) > 100 || !slugPattern.MatchString(input.Slug) || len(input.Slug) > 100 {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "Category name or slug is invalid.")
		return
	}
	item, err := s.store.UpdateCategory(request.Context(), categoryID, input.Name, input.Slug, isActive)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) deleteCategory(response http.ResponseWriter, request *http.Request) {
	categoryID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	if err := s.store.DeleteCategory(request.Context(), categoryID); err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusNoContent, nil)
}

func pathID(response http.ResponseWriter, request *http.Request, name string) (int64, bool) {
	value, err := strconv.ParseInt(request.PathValue(name), 10, 64)
	if err != nil || value < 1 {
		writeProblem(response, request, http.StatusBadRequest, "Invalid identifier", name+" must be a positive integer.")
		return 0, false
	}
	return value, true
}
