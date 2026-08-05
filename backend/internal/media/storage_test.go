package media

import (
	"bytes"
	"encoding/base64"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

var onePixelPNG = mustDecodeBase64("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=")

func TestSaveServeAndDeleteListingImage(t *testing.T) {
	t.Parallel()
	storage, err := New(Config{Root: t.TempDir(), PublicBaseURL: "/media", MaxUploadBytes: 1024})
	if err != nil {
		t.Fatal(err)
	}

	saved, err := storage.SaveListingImage(42, `C:\fakepath\..\photo.PNG`, bytes.NewReader(onePixelPNG))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(saved.URL, "/media/listings/42/") || !strings.HasSuffix(saved.URL, ".png") {
		t.Fatalf("unexpected public URL %q", saved.URL)
	}
	if strings.Contains(saved.URL, "photo") || strings.Contains(saved.URL, "..") {
		t.Fatalf("client filename leaked into storage path %q", saved.URL)
	}

	request := httptest.NewRequest(http.MethodGet, saved.URL, nil)
	response := httptest.NewRecorder()
	storage.ServeHTTP(response, request)
	if response.Code != http.StatusOK {
		t.Fatalf("GET returned %d: %s", response.Code, response.Body.String())
	}
	if got := response.Header().Get("Cache-Control"); got != "private, no-store" {
		t.Fatalf("unexpected Cache-Control %q", got)
	}
	if got := response.Header().Get("X-Content-Type-Options"); got != "nosniff" {
		t.Fatalf("unexpected X-Content-Type-Options %q", got)
	}
	if !bytes.Equal(response.Body.Bytes(), onePixelPNG) {
		t.Fatal("served bytes differ from uploaded bytes")
	}

	if err := storage.DeleteURL("https://attacker.invalid" + saved.URL); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(storage.Root(), filepath.FromSlash(saved.Key))); err != nil {
		t.Fatalf("foreign URL deleted local object: %v", err)
	}
	if err := storage.DeleteURL(saved.URL); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(storage.Root(), filepath.FromSlash(saved.Key))); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("expected object deletion, got %v", err)
	}
}

func TestDeleteListingMediaPreservesOtherListings(t *testing.T) {
	t.Parallel()
	storage, err := New(Config{Root: t.TempDir(), PublicBaseURL: "/media", MaxUploadBytes: 1024})
	if err != nil {
		t.Fatal(err)
	}
	first, err := storage.SaveListingImage(42, "first.png", bytes.NewReader(onePixelPNG))
	if err != nil {
		t.Fatal(err)
	}
	second, err := storage.SaveListingImage(42, "second.png", bytes.NewReader(onePixelPNG))
	if err != nil {
		t.Fatal(err)
	}
	other, err := storage.SaveListingImage(43, "other.png", bytes.NewReader(onePixelPNG))
	if err != nil {
		t.Fatal(err)
	}

	if err := storage.DeleteListingURL(43, first.URL); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(storage.Root(), filepath.FromSlash(first.Key))); err != nil {
		t.Fatalf("mismatched listing ID removed another listing's object: %v", err)
	}
	if err := storage.DeleteListingMedia(42); err != nil {
		t.Fatal(err)
	}
	for _, saved := range []SavedFile{first, second} {
		if _, err := os.Stat(filepath.Join(storage.Root(), filepath.FromSlash(saved.Key))); !errors.Is(err, os.ErrNotExist) {
			t.Fatalf("listing 42 object still exists: %v", err)
		}
	}
	if _, err := os.Stat(filepath.Join(storage.Root(), filepath.FromSlash(other.Key))); err != nil {
		t.Fatalf("listing 43 object was removed: %v", err)
	}
	if err := storage.DeleteListingMedia(0); err == nil {
		t.Fatal("DeleteListingMedia(0) unexpectedly succeeded")
	}
}

func TestListingReferenceForRequestAcceptsOnlyStrictGeneratedKeys(t *testing.T) {
	t.Parallel()
	storage, err := New(Config{Root: t.TempDir(), PublicBaseURL: "https://assets.example/app/media"})
	if err != nil {
		t.Fatal(err)
	}
	key := "listings/42/0123456789abcdef0123456789abcdef.png"
	listingID, imageURL, isUpload, ok := storage.ListingReferenceForRequest("/app/media/" + key)
	if !ok || !isUpload || listingID != 42 || imageURL != "https://assets.example/app/media/"+key {
		t.Fatalf("unexpected listing reference: id=%d url=%q upload=%t ok=%t", listingID, imageURL, isUpload, ok)
	}
	if _, _, isUpload, ok := storage.ListingReferenceForRequest("/app/media/demo/wireless-headphones-v1.jpeg"); !ok || isUpload {
		t.Fatalf("demo asset classified as upload: upload=%t ok=%t", isUpload, ok)
	}
	for _, requestPath := range []string{
		"/media/" + key,
		"/app/media/listings/43/not-random.png",
		"/app/media/listings/42/../../secret.png",
		"/app/media/listings/0/0123456789abcdef0123456789abcdef.png",
	} {
		if _, _, _, ok := storage.ListingReferenceForRequest(requestPath); ok {
			t.Fatalf("unsafe request path accepted: %q", requestPath)
		}
	}
}

func TestSaveListingImageValidatesSizeExtensionAndMagicBytes(t *testing.T) {
	t.Parallel()
	storage, err := New(Config{Root: t.TempDir(), PublicBaseURL: "https://cdn.example/media", MaxUploadBytes: int64(len(onePixelPNG) - 1)})
	if err != nil {
		t.Fatal(err)
	}

	if _, err := storage.SaveListingImage(1, "image.svg", bytes.NewReader(onePixelPNG)); !errors.Is(err, ErrInvalidExtension) {
		t.Fatalf("SVG upload error = %v, want ErrInvalidExtension", err)
	}
	if _, err := storage.SaveListingImage(1, "image.jpg", bytes.NewReader(onePixelPNG)); !errors.Is(err, ErrMIMETypeMismatch) {
		t.Fatalf("mismatched upload error = %v, want ErrMIMETypeMismatch", err)
	}
	if _, err := storage.SaveListingImage(1, "image.png", bytes.NewReader(onePixelPNG)); !errors.Is(err, ErrFileTooLarge) {
		t.Fatalf("oversized upload error = %v, want ErrFileTooLarge", err)
	}
	if _, err := storage.SaveListingImage(1, "image.png", bytes.NewReader(nil)); !errors.Is(err, ErrEmptyFile) {
		t.Fatalf("empty upload error = %v, want ErrEmptyFile", err)
	}
}

func TestServeRejectsTraversalAndNonReadMethods(t *testing.T) {
	t.Parallel()
	storage, err := New(Config{Root: t.TempDir(), PublicBaseURL: "/media"})
	if err != nil {
		t.Fatal(err)
	}

	for _, target := range []string{"/media/../secret", "/media/%2e%2e/secret", "/media/listings/1/not-random.png"} {
		request := httptest.NewRequest(http.MethodGet, target, nil)
		response := httptest.NewRecorder()
		storage.ServeHTTP(response, request)
		if response.Code != http.StatusNotFound {
			t.Fatalf("GET %s returned %d, want 404", target, response.Code)
		}
	}
	request := httptest.NewRequest(http.MethodPost, "/media/listings/1/0123456789abcdef0123456789abcdef.png", nil)
	response := httptest.NewRecorder()
	storage.ServeHTTP(response, request)
	if response.Code != http.StatusMethodNotAllowed {
		t.Fatalf("POST returned %d, want 405", response.Code)
	}
}

func TestEnsureDemoAssetsCopiesVersionedRepositoryPhotos(t *testing.T) {
	t.Parallel()
	sourceRoot := t.TempDir()
	for _, asset := range demoAssets {
		contents := minimalJPEG()
		if asset.MIMEType == "image/webp" {
			contents = minimalWEBP()
		}
		if err := os.WriteFile(filepath.Join(sourceRoot, asset.SourceFile), contents, 0o600); err != nil {
			t.Fatal(err)
		}
	}
	storage, err := New(Config{Root: t.TempDir(), PublicBaseURL: "/media"})
	if err != nil {
		t.Fatal(err)
	}
	urls, err := storage.EnsureDemoAssets(sourceRoot)
	if err != nil {
		t.Fatal(err)
	}
	if len(urls) != len(demoAssets) || urls["headphones"] != "/media/demo/wireless-headphones-v1.jpeg" {
		t.Fatalf("unexpected demo URL map %#v", urls)
	}
	// A second run is intentionally a no-op and must remain successful.
	if _, err := storage.EnsureDemoAssets(sourceRoot); err != nil {
		t.Fatal(err)
	}
	request := httptest.NewRequest(http.MethodGet, urls["headphones"], nil)
	response := httptest.NewRecorder()
	storage.ServeHTTP(response, request)
	if got := response.Header().Get("Cache-Control"); got != "public, max-age=31536000, immutable" {
		t.Fatalf("unexpected demo Cache-Control %q", got)
	}
}

func mustDecodeBase64(value string) []byte {
	decoded, err := base64.StdEncoding.DecodeString(value)
	if err != nil {
		panic(err)
	}
	return decoded
}

func minimalJPEG() []byte {
	return append([]byte{0xff, 0xd8, 0xff, 0xe0, 0, 16}, []byte("JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xd9")...)
}

func minimalWEBP() []byte {
	return []byte{'R', 'I', 'F', 'F', 4, 0, 0, 0, 'W', 'E', 'B', 'P', 'V', 'P', '8', ' '}
}
