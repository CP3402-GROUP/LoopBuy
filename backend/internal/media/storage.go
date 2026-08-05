package media

import (
	"crypto/rand"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"mime"
	"net/http"
	"net/url"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

const defaultMaxUploadBytes int64 = 8 << 20

var (
	ErrEmptyFile        = errors.New("media: uploaded file is empty")
	ErrFileTooLarge     = errors.New("media: uploaded file is too large")
	ErrInvalidExtension = errors.New("media: filename extension is not supported")
	ErrMIMETypeMismatch = errors.New("media: file contents do not match its extension")

	uploadKeyPattern = regexp.MustCompile(`^listings/[1-9][0-9]*/[a-f0-9]{32}\.(?:jpg|png|webp|gif)$`)
	demoKeyPattern   = regexp.MustCompile(`^demo/[a-z0-9][a-z0-9-]*-v[1-9][0-9]*\.(?:jpg|jpeg|png|webp|avif)$`)
)

type Config struct {
	Root           string
	PublicBaseURL  string
	MaxUploadBytes int64
}

type Storage struct {
	root           string
	publicBaseURL  string
	publicBase     *url.URL
	maxUploadBytes int64
}

type SavedFile struct {
	Key         string
	URL         string
	ContentType string
	Size        int64
}

func New(config Config) (*Storage, error) {
	root := strings.TrimSpace(config.Root)
	if root == "" {
		return nil, errors.New("media: storage root is required")
	}
	absRoot, err := filepath.Abs(root)
	if err != nil {
		return nil, fmt.Errorf("media: resolve storage root: %w", err)
	}
	volumeName := filepath.VolumeName(absRoot)
	if filepath.Clean(absRoot) == filepath.Clean(volumeName+string(filepath.Separator)) {
		return nil, errors.New("media: filesystem root cannot be used as the storage root")
	}
	if err := os.MkdirAll(absRoot, 0o750); err != nil {
		return nil, fmt.Errorf("media: create storage root: %w", err)
	}
	probe, err := os.CreateTemp(absRoot, ".write-probe-*.tmp")
	if err != nil {
		return nil, fmt.Errorf("media: storage root is not writable: %w", err)
	}
	probeName := probe.Name()
	if err := probe.Close(); err != nil {
		_ = os.Remove(probeName)
		return nil, fmt.Errorf("media: close storage write probe: %w", err)
	}
	if err := os.Remove(probeName); err != nil {
		return nil, fmt.Errorf("media: remove storage write probe: %w", err)
	}

	publicBaseURL := strings.TrimRight(strings.TrimSpace(config.PublicBaseURL), "/")
	if publicBaseURL == "" {
		publicBaseURL = "/media"
	}
	publicBase, err := validatePublicBaseURL(publicBaseURL)
	if err != nil {
		return nil, err
	}
	maxUploadBytes := config.MaxUploadBytes
	if maxUploadBytes <= 0 {
		maxUploadBytes = defaultMaxUploadBytes
	}

	return &Storage{
		root: absRoot, publicBaseURL: publicBaseURL, publicBase: publicBase,
		maxUploadBytes: maxUploadBytes,
	}, nil
}

func validatePublicBaseURL(raw string) (*url.URL, error) {
	parsed, err := url.Parse(raw)
	if err != nil {
		return nil, fmt.Errorf("media: invalid public base URL: %w", err)
	}
	if parsed.RawQuery != "" || parsed.Fragment != "" || parsed.User != nil {
		return nil, errors.New("media: public base URL cannot contain credentials, a query, or a fragment")
	}
	if parsed.IsAbs() {
		if (parsed.Scheme != "http" && parsed.Scheme != "https") || parsed.Host == "" {
			return nil, errors.New("media: absolute public base URL must use HTTP(S) and include a host")
		}
	} else if !strings.HasPrefix(parsed.Path, "/") {
		return nil, errors.New("media: relative public base URL must start with /")
	}
	if path.Clean(parsed.Path) != parsed.Path || !strings.HasSuffix(parsed.Path, "/media") {
		return nil, errors.New("media: public base URL path must end with /media")
	}
	return parsed, nil
}

func (s *Storage) Root() string { return s.root }

func (s *Storage) MaxUploadBytes() int64 { return s.maxUploadBytes }

func (s *Storage) PublicURL(key string) (string, error) {
	if !validKey(key) {
		return "", errors.New("media: invalid object key")
	}
	return s.publicBaseURL + "/" + key, nil
}

// ListingReferenceForRequest identifies a generated listing upload addressed
// by a media request. Demo assets are valid media requests but deliberately do
// not return a database reference because they are repository-owned fixtures.
//
// The strict generated-key pattern is also the authorization boundary: callers
// must not derive listing IDs or database URLs from arbitrary request paths.
func (s *Storage) ListingReferenceForRequest(requestPath string) (listingID int64, imageURL string, isUpload bool, ok bool) {
	prefix := strings.TrimRight(s.publicBase.Path, "/") + "/"
	if !strings.HasPrefix(requestPath, prefix) {
		return 0, "", false, false
	}
	key := strings.TrimPrefix(requestPath, prefix)
	if demoKeyPattern.MatchString(key) {
		return 0, "", false, true
	}
	if !uploadKeyPattern.MatchString(key) {
		return 0, "", false, false
	}
	parts := strings.SplitN(key, "/", 3)
	if len(parts) != 3 {
		return 0, "", false, false
	}
	listingID, err := strconv.ParseInt(parts[1], 10, 64)
	if err != nil || listingID <= 0 {
		return 0, "", false, false
	}
	imageURL, err = s.PublicURL(key)
	if err != nil {
		return 0, "", false, false
	}
	return listingID, imageURL, true, true
}

func (s *Storage) SaveListingImage(listingID int64, originalName string, source io.Reader) (SavedFile, error) {
	if listingID <= 0 {
		return SavedFile{}, errors.New("media: listing ID must be positive")
	}
	wantedType, outputExtension, err := contentTypeForFilename(originalName)
	if err != nil {
		return SavedFile{}, err
	}

	listingPart := strconv.FormatInt(listingID, 10)
	directory := filepath.Join(s.root, "listings", listingPart)
	if err := os.MkdirAll(directory, 0o750); err != nil {
		return SavedFile{}, fmt.Errorf("media: create listing directory: %w", err)
	}
	temporary, err := os.CreateTemp(directory, ".upload-*.tmp")
	if err != nil {
		return SavedFile{}, fmt.Errorf("media: create temporary file: %w", err)
	}
	temporaryName := temporary.Name()
	committed := false
	defer func() {
		_ = temporary.Close()
		if !committed {
			_ = os.Remove(temporaryName)
		}
	}()

	limited := &io.LimitedReader{R: source, N: s.maxUploadBytes + 1}
	header := make([]byte, 512)
	headerLength, readErr := io.ReadFull(limited, header)
	if readErr != nil && !errors.Is(readErr, io.ErrUnexpectedEOF) && !errors.Is(readErr, io.EOF) {
		return SavedFile{}, fmt.Errorf("media: read upload header: %w", readErr)
	}
	if headerLength == 0 {
		return SavedFile{}, ErrEmptyFile
	}
	header = header[:headerLength]
	detectedType := http.DetectContentType(header)
	if detectedType != wantedType {
		return SavedFile{}, fmt.Errorf("%w: extension expects %s but contents are %s", ErrMIMETypeMismatch, wantedType, detectedType)
	}

	written, err := temporary.Write(header)
	if err != nil {
		return SavedFile{}, fmt.Errorf("media: write upload header: %w", err)
	}
	total := int64(written)
	copied, err := io.Copy(temporary, limited)
	total += copied
	if err != nil {
		return SavedFile{}, fmt.Errorf("media: store upload: %w", err)
	}
	if total > s.maxUploadBytes {
		return SavedFile{}, ErrFileTooLarge
	}
	if err := temporary.Sync(); err != nil {
		return SavedFile{}, fmt.Errorf("media: sync upload: %w", err)
	}
	if err := temporary.Close(); err != nil {
		return SavedFile{}, fmt.Errorf("media: close upload: %w", err)
	}

	randomName, err := randomFilename(outputExtension)
	if err != nil {
		return SavedFile{}, err
	}
	finalPath := filepath.Join(directory, randomName)
	if err := os.Rename(temporaryName, finalPath); err != nil {
		return SavedFile{}, fmt.Errorf("media: commit upload: %w", err)
	}
	committed = true
	if err := os.Chmod(finalPath, 0o640); err != nil {
		_ = os.Remove(finalPath)
		return SavedFile{}, fmt.Errorf("media: set upload permissions: %w", err)
	}

	key := path.Join("listings", listingPart, randomName)
	publicURL, err := s.PublicURL(key)
	if err != nil {
		_ = os.Remove(finalPath)
		return SavedFile{}, err
	}
	return SavedFile{Key: key, URL: publicURL, ContentType: detectedType, Size: total}, nil
}

func contentTypeForFilename(filename string) (contentType, outputExtension string, err error) {
	base := path.Base(strings.ReplaceAll(strings.TrimSpace(filename), `\`, "/"))
	extension := strings.ToLower(path.Ext(base))
	switch extension {
	case ".jpg", ".jpeg":
		return "image/jpeg", ".jpg", nil
	case ".png":
		return "image/png", ".png", nil
	case ".webp":
		return "image/webp", ".webp", nil
	case ".gif":
		return "image/gif", ".gif", nil
	default:
		return "", "", ErrInvalidExtension
	}
}

func randomFilename(extension string) (string, error) {
	randomBytes := make([]byte, 16)
	if _, err := rand.Read(randomBytes); err != nil {
		return "", fmt.Errorf("media: generate random filename: %w", err)
	}
	return hex.EncodeToString(randomBytes) + extension, nil
}

func (s *Storage) DeleteURL(rawURL string) error {
	key, ok := s.keyFromURL(rawURL)
	if !ok || !uploadKeyPattern.MatchString(key) {
		return nil
	}
	root, err := os.OpenRoot(s.root)
	if err != nil {
		return fmt.Errorf("media: open storage root: %w", err)
	}
	defer root.Close()
	if err := root.Remove(filepath.FromSlash(key)); err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("media: remove object: %w", err)
	}
	return nil
}

// DeleteListingURL removes an uploaded object only when its generated key is
// namespaced to the expected listing. A poisoned database URL therefore cannot
// make one seller's delete operation remove another seller's file.
func (s *Storage) DeleteListingURL(listingID int64, rawURL string) error {
	if listingID <= 0 {
		return errors.New("media: listing ID must be positive")
	}
	key, ok := s.keyFromURL(rawURL)
	expectedPrefix := "listings/" + strconv.FormatInt(listingID, 10) + "/"
	if !ok || !uploadKeyPattern.MatchString(key) || !strings.HasPrefix(key, expectedPrefix) {
		return nil
	}
	return s.deleteKey(key)
}

// DeleteListingMedia removes the complete generated upload namespace for one
// listing, including any orphan left by an interrupted database operation.
func (s *Storage) DeleteListingMedia(listingID int64) error {
	if listingID <= 0 {
		return errors.New("media: listing ID must be positive")
	}
	root, err := os.OpenRoot(s.root)
	if err != nil {
		return fmt.Errorf("media: open storage root: %w", err)
	}
	defer root.Close()
	directory := filepath.Join("listings", strconv.FormatInt(listingID, 10))
	if err := root.RemoveAll(directory); err != nil {
		return fmt.Errorf("media: remove listing media: %w", err)
	}
	return nil
}

func (s *Storage) deleteKey(key string) error {
	root, err := os.OpenRoot(s.root)
	if err != nil {
		return fmt.Errorf("media: open storage root: %w", err)
	}
	defer root.Close()
	if err := root.Remove(filepath.FromSlash(key)); err != nil && !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("media: remove object: %w", err)
	}
	return nil
}

func (s *Storage) keyFromURL(rawURL string) (string, bool) {
	parsed, err := url.Parse(strings.TrimSpace(rawURL))
	if err != nil || parsed.RawQuery != "" || parsed.Fragment != "" {
		return "", false
	}
	if s.publicBase.IsAbs() {
		if parsed.Scheme != s.publicBase.Scheme || parsed.Host != s.publicBase.Host {
			return "", false
		}
	} else if parsed.IsAbs() || parsed.Host != "" {
		return "", false
	}
	prefix := strings.TrimRight(s.publicBase.Path, "/") + "/"
	if !strings.HasPrefix(parsed.Path, prefix) {
		return "", false
	}
	key := strings.TrimPrefix(parsed.Path, prefix)
	return key, validKey(key)
}

func (s *Storage) ServeHTTP(response http.ResponseWriter, request *http.Request) {
	if request.Method != http.MethodGet && request.Method != http.MethodHead {
		response.Header().Set("Allow", "GET, HEAD")
		http.Error(response, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	key := strings.TrimPrefix(request.URL.Path, "/media/")
	if request.URL.Path == "/media/" || !validKey(key) {
		http.NotFound(response, request)
		return
	}
	root, err := os.OpenRoot(s.root)
	if err != nil {
		http.NotFound(response, request)
		return
	}
	defer root.Close()
	file, err := root.Open(filepath.FromSlash(key))
	if err != nil {
		http.NotFound(response, request)
		return
	}
	defer file.Close()
	info, err := file.Stat()
	if err != nil || !info.Mode().IsRegular() {
		http.NotFound(response, request)
		return
	}

	contentType := mime.TypeByExtension(strings.ToLower(path.Ext(key)))
	if strings.HasSuffix(key, ".svg") {
		contentType = "image/svg+xml"
	}
	response.Header().Set("Content-Type", contentType)
	if uploadKeyPattern.MatchString(key) {
		// User uploads are revocable on image/account deletion, so shared or
		// persistent browser caches must not outlive the origin object.
		response.Header().Set("Cache-Control", "private, no-store")
	} else {
		response.Header().Set("Cache-Control", "public, max-age=31536000, immutable")
	}
	response.Header().Set("Content-Disposition", `inline; filename="`+path.Base(key)+`"`)
	response.Header().Set("Content-Security-Policy", "default-src 'none'; style-src 'unsafe-inline'; sandbox")
	response.Header().Set("Cross-Origin-Resource-Policy", "cross-origin")
	response.Header().Set("X-Content-Type-Options", "nosniff")
	http.ServeContent(response, request, path.Base(key), info.ModTime(), file)
}

func validKey(key string) bool {
	return uploadKeyPattern.MatchString(key) || demoKeyPattern.MatchString(key)
}
