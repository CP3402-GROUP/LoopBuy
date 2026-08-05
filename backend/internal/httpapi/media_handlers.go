package httpapi

import (
	"errors"
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"strconv"
	"strings"

	localmedia "github.com/CP3402-GROUP/LoopBuy/backend/internal/media"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/model"
	"github.com/CP3402-GROUP/LoopBuy/backend/internal/store"
)

const maxMultipartMetadataBytes int64 = 64 << 10

func (s *Server) serveMedia(response http.ResponseWriter, request *http.Request) {
	listingID, imageURL, isUpload, ok := s.media.ListingReferenceForRequest(request.URL.Path)
	if !ok {
		http.NotFound(response, request)
		return
	}
	if isUpload {
		if s.store == nil {
			writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Media references cannot be verified.")
			return
		}
		exists, err := s.store.ListingImageURLExists(request.Context(), listingID, imageURL)
		if err != nil {
			s.logger.Error("verify listing media reference", "listing_id", listingID, "error", err)
			writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Media references cannot be verified.")
			return
		}
		if !exists {
			http.NotFound(response, request)
			return
		}
	}
	s.media.ServeHTTP(response, request)
}

func (s *Server) uploadListingImage(response http.ResponseWriter, request *http.Request) {
	if s.media == nil {
		writeProblem(response, request, http.StatusServiceUnavailable, "Service unavailable", "Local image storage is unavailable.")
		return
	}
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
	if listing.SellerID != claims.UserID && claims.Role != "admin" {
		writeStoreError(response, request, store.ErrNotFound)
		return
	}

	request.Body = http.MaxBytesReader(response, request.Body, s.media.MaxUploadBytes()+maxMultipartMetadataBytes)
	reader, err := request.MultipartReader()
	if err != nil {
		writeProblem(response, request, http.StatusUnsupportedMediaType, "Unsupported media type", "Content-Type must be multipart/form-data with a valid boundary.")
		return
	}

	var saved localmedia.SavedFile
	var haveImage bool
	var sortOrder int
	var sortOrderSet bool
	var isPrimary bool
	parts := 0
	for {
		part, nextErr := reader.NextPart()
		if errors.Is(nextErr, io.EOF) {
			break
		}
		if nextErr != nil {
			if haveImage {
				_ = s.media.DeleteListingURL(listingID, saved.URL)
			}
			writeMultipartError(response, request, nextErr)
			return
		}
		parts++
		if parts > 8 {
			part.Close()
			if haveImage {
				_ = s.media.DeleteListingURL(listingID, saved.URL)
			}
			writeProblem(response, request, http.StatusBadRequest, "Invalid multipart request", "Too many multipart fields.")
			return
		}

		switch part.FormName() {
		case "image":
			if haveImage || part.FileName() == "" {
				part.Close()
				if haveImage {
					_ = s.media.DeleteListingURL(listingID, saved.URL)
				}
				writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "Exactly one image file is required.")
				return
			}
			saved, err = s.media.SaveListingImage(listingID, part.FileName(), part)
			part.Close()
			if err != nil {
				writeMediaError(response, request, err)
				return
			}
			haveImage = true
		case "sort_order":
			value, readErr := readMultipartValue(part)
			part.Close()
			if readErr != nil {
				if haveImage {
					_ = s.media.DeleteListingURL(listingID, saved.URL)
				}
				writeMultipartError(response, request, readErr)
				return
			}
			sortOrder, err = strconv.Atoi(value)
			if err != nil || sortOrder < 0 || sortOrder > 1000 {
				if haveImage {
					_ = s.media.DeleteListingURL(listingID, saved.URL)
				}
				writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "sort_order must be an integer between 0 and 1000.")
				return
			}
			sortOrderSet = true
		case "is_primary":
			value, readErr := readMultipartValue(part)
			part.Close()
			if readErr != nil {
				if haveImage {
					_ = s.media.DeleteListingURL(listingID, saved.URL)
				}
				writeMultipartError(response, request, readErr)
				return
			}
			isPrimary, err = strconv.ParseBool(value)
			if err != nil {
				if haveImage {
					_ = s.media.DeleteListingURL(listingID, saved.URL)
				}
				writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "is_primary must be true or false.")
				return
			}
		default:
			part.Close()
			if haveImage {
				_ = s.media.DeleteListingURL(listingID, saved.URL)
			}
			writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "Only image, sort_order, and is_primary fields are accepted.")
			return
		}
	}
	if !haveImage {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "The image field is required.")
		return
	}
	if !sortOrderSet {
		sortOrder = firstAvailableSortOrder(listing.Images)
	}

	item, err := s.store.AddListingImage(request.Context(), listingID, claims.UserID, claims.Role == "admin", store.ImageInput{
		ImageURL: saved.URL, SortOrder: sortOrder, IsPrimary: isPrimary,
	})
	if err != nil {
		if removeErr := s.media.DeleteListingURL(listingID, saved.URL); removeErr != nil {
			s.logger.Warn("remove uncommitted listing image", "error", removeErr)
		}
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusCreated, item)
}

func readMultipartValue(part *multipart.Part) (string, error) {
	contents, err := io.ReadAll(io.LimitReader(part, 1025))
	if err != nil {
		return "", err
	}
	if len(contents) > 1024 {
		return "", errors.New("multipart field exceeds 1024 bytes")
	}
	return strings.TrimSpace(string(contents)), nil
}

func firstAvailableSortOrder(images []model.ListingImage) int {
	used := make(map[int]bool, len(images))
	for _, image := range images {
		if image.SortOrder >= 0 && image.SortOrder <= 1000 {
			used[image.SortOrder] = true
		}
	}
	for candidate := 0; candidate <= 1000; candidate++ {
		if !used[candidate] {
			return candidate
		}
	}
	return 1000
}

func writeMediaError(response http.ResponseWriter, request *http.Request, err error) {
	var maxBytesError *http.MaxBytesError
	switch {
	case errors.Is(err, localmedia.ErrFileTooLarge), errors.As(err, &maxBytesError), strings.Contains(strings.ToLower(err.Error()), "request body too large"):
		writeProblem(response, request, http.StatusRequestEntityTooLarge, "Image too large", "The image exceeds the configured upload limit.")
	case errors.Is(err, localmedia.ErrEmptyFile), errors.Is(err, localmedia.ErrInvalidExtension), errors.Is(err, localmedia.ErrMIMETypeMismatch):
		writeProblem(response, request, http.StatusUnprocessableEntity, "Invalid image", "Use a non-empty JPEG, PNG, WebP, or GIF whose contents match its filename extension.")
	default:
		writeProblem(response, request, http.StatusInternalServerError, "Image storage failed", "The image could not be stored.")
	}
}

func writeMultipartError(response http.ResponseWriter, request *http.Request, err error) {
	var maxBytesError *http.MaxBytesError
	if errors.As(err, &maxBytesError) || strings.Contains(strings.ToLower(err.Error()), "request body too large") {
		writeProblem(response, request, http.StatusRequestEntityTooLarge, "Image too large", "The multipart request exceeds the configured upload limit.")
		return
	}
	writeProblem(response, request, http.StatusBadRequest, "Invalid multipart request", fmt.Sprintf("The multipart body could not be read: %v", err))
}
