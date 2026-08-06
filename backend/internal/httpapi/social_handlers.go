package httpapi

import (
	"net/http"
	"strconv"
	"strings"
)

func (s *Server) listFavourites(response http.ResponseWriter, request *http.Request) {
	items, err := s.store.ListFavourites(request.Context(), currentClaims(request).UserID, intQuery(request.URL.Query().Get("limit"), 100, 1, 100))
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, map[string]any{"items": items})
}

func (s *Server) addFavourite(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "listingId")
	if !ok {
		return
	}
	if err := s.store.AddFavourite(request.Context(), currentClaims(request).UserID, listingID); err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusNoContent, nil)
}

func (s *Server) removeFavourite(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "listingId")
	if !ok {
		return
	}
	if err := s.store.RemoveFavourite(request.Context(), currentClaims(request).UserID, listingID); err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusNoContent, nil)
}

func (s *Server) getCart(response http.ResponseWriter, request *http.Request) {
	item, err := s.store.GetCart(request.Context(), currentClaims(request).UserID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) setCartItem(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "listingId")
	if !ok {
		return
	}
	var input struct {
		Quantity int `json:"quantity"`
	}
	if err := decodeJSON(response, request, &input); err != nil || input.Quantity < 1 || input.Quantity > 99 {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "quantity must be between 1 and 99.")
		return
	}
	item, err := s.store.SetCartItem(request.Context(), currentClaims(request).UserID, listingID, input.Quantity)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) removeCartItem(response http.ResponseWriter, request *http.Request) {
	listingID, ok := pathID(response, request, "listingId")
	if !ok {
		return
	}
	item, err := s.store.RemoveCartItem(request.Context(), currentClaims(request).UserID, listingID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) clearCart(response http.ResponseWriter, request *http.Request) {
	item, err := s.store.ClearCart(request.Context(), currentClaims(request).UserID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) createConversation(response http.ResponseWriter, request *http.Request) {
	var input struct {
		ListingID int64 `json:"listing_id"`
	}
	if err := decodeJSON(response, request, &input); err != nil || input.ListingID < 1 {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "listing_id must be a positive integer.")
		return
	}
	item, err := s.store.CreateConversation(request.Context(), currentClaims(request).UserID, input.ListingID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusCreated, item)
}

func (s *Server) listConversations(response http.ResponseWriter, request *http.Request) {
	items, err := s.store.ListConversations(request.Context(), currentClaims(request).UserID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, map[string]any{"items": items})
}

func (s *Server) getConversation(response http.ResponseWriter, request *http.Request) {
	conversationID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	item, err := s.store.GetConversation(request.Context(), currentClaims(request).UserID, conversationID)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) leaveConversation(response http.ResponseWriter, request *http.Request) {
	conversationID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	if err := s.store.LeaveConversation(request.Context(), currentClaims(request).UserID, conversationID); err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusNoContent, nil)
}

func (s *Server) listMessages(response http.ResponseWriter, request *http.Request) {
	conversationID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	before, _ := strconv.ParseInt(request.URL.Query().Get("before"), 10, 64)
	items, err := s.store.ListMessages(request.Context(), currentClaims(request).UserID, conversationID, before, intQuery(request.URL.Query().Get("limit"), 50, 1, 100))
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, map[string]any{"items": items})
}

func (s *Server) createMessage(response http.ResponseWriter, request *http.Request) {
	conversationID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	text, ok := messageText(response, request)
	if !ok {
		return
	}
	item, err := s.store.CreateMessage(request.Context(), currentClaims(request).UserID, conversationID, text)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusCreated, item)
}

func (s *Server) updateMessage(response http.ResponseWriter, request *http.Request) {
	conversationID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	messageID, ok := pathID(response, request, "messageId")
	if !ok {
		return
	}
	text, ok := messageText(response, request)
	if !ok {
		return
	}
	item, err := s.store.UpdateMessage(request.Context(), currentClaims(request).UserID, conversationID, messageID, text)
	if err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusOK, item)
}

func (s *Server) deleteMessage(response http.ResponseWriter, request *http.Request) {
	conversationID, ok := pathID(response, request, "id")
	if !ok {
		return
	}
	messageID, ok := pathID(response, request, "messageId")
	if !ok {
		return
	}
	if err := s.store.DeleteMessage(request.Context(), currentClaims(request).UserID, conversationID, messageID); err != nil {
		writeStoreError(response, request, err)
		return
	}
	writeJSON(response, http.StatusNoContent, nil)
}

func messageText(response http.ResponseWriter, request *http.Request) (string, bool) {
	var input struct {
		MessageText string `json:"message_text"`
	}
	if err := decodeJSON(response, request, &input); err != nil {
		writeProblem(response, request, http.StatusBadRequest, "Invalid request", err.Error())
		return "", false
	}
	input.MessageText = strings.TrimSpace(input.MessageText)
	if input.MessageText == "" || len(input.MessageText) > 5000 {
		writeProblem(response, request, http.StatusUnprocessableEntity, "Validation failed", "message_text must contain 1-5000 characters.")
		return "", false
	}
	return input.MessageText, true
}
