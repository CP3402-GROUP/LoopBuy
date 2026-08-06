package media

import (
	"crypto/rand"
	"encoding/binary"
	"encoding/hex"
	"errors"
	"fmt"
	"image/jpeg"
	"image/png"
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

const (
	defaultMaxUploadBytes int64 = 8 << 20
	maxAvatarUploadBytes  int64 = 2 << 20
	maxAvatarDimension          = 4096
	maxAvatarPixels       int64 = 16_000_000
)

var (
	ErrEmptyFile         = errors.New("media: uploaded file is empty")
	ErrFileTooLarge      = errors.New("media: uploaded file is too large")
	ErrInvalidExtension  = errors.New("media: filename extension is not supported")
	ErrMIMETypeMismatch  = errors.New("media: file contents do not match its extension")
	ErrInvalidDimensions = errors.New("media: image dimensions are invalid")

	uploadKeyPattern = regexp.MustCompile(`^listings/[1-9][0-9]*/[a-f0-9]{32}\.(?:jpg|png|webp|gif)$`)
	avatarKeyPattern = regexp.MustCompile(`^avatars/[1-9][0-9]*/[a-f0-9]{32}\.(?:jpg|png|webp)$`)
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

func (s *Storage) MaxAvatarUploadBytes() int64 { return maxAvatarUploadBytes }

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

// AvatarReferenceForRequest identifies a generated profile avatar addressed by
// a media request. The returned URL is canonical for this storage instance and
// is intended for an exact user_profiles.profile_image reference check before
// any bytes are served.
func (s *Storage) AvatarReferenceForRequest(requestPath string) (userID int64, imageURL string, ok bool) {
	prefix := strings.TrimRight(s.publicBase.Path, "/") + "/"
	if !strings.HasPrefix(requestPath, prefix) {
		return 0, "", false
	}
	key := strings.TrimPrefix(requestPath, prefix)
	if !avatarKeyPattern.MatchString(key) {
		return 0, "", false
	}
	parts := strings.SplitN(key, "/", 3)
	if len(parts) != 3 {
		return 0, "", false
	}
	userID, err := strconv.ParseInt(parts[1], 10, 64)
	if err != nil || userID <= 0 {
		return 0, "", false
	}
	imageURL, err = s.PublicURL(key)
	if err != nil {
		return 0, "", false
	}
	return userID, imageURL, true
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
	return s.saveImage(
		filepath.Join("listings", listingPart),
		path.Join("listings", listingPart),
		wantedType,
		outputExtension,
		s.maxUploadBytes,
		false,
		source,
	)
}

// SaveUserAvatar stores a bounded profile image under a user-scoped namespace.
// Avatars deliberately use a tighter contract than listing images: animated
// GIFs are rejected and the encoded dimensions are validated before commit.
func (s *Storage) SaveUserAvatar(userID int64, originalName string, source io.Reader) (SavedFile, error) {
	if userID <= 0 {
		return SavedFile{}, errors.New("media: user ID must be positive")
	}
	wantedType, outputExtension, err := contentTypeForFilename(originalName)
	if err != nil {
		return SavedFile{}, err
	}
	if wantedType == "image/gif" {
		return SavedFile{}, ErrInvalidExtension
	}
	userPart := strconv.FormatInt(userID, 10)
	return s.saveImage(
		filepath.Join("avatars", userPart),
		path.Join("avatars", userPart),
		wantedType,
		outputExtension,
		maxAvatarUploadBytes,
		true,
		source,
	)
}

func (s *Storage) saveImage(relativeDirectory, keyPrefix, wantedType, outputExtension string, maxBytes int64, validateDimensions bool, source io.Reader) (SavedFile, error) {
	directory := filepath.Join(s.root, relativeDirectory)
	if err := os.MkdirAll(directory, 0o750); err != nil {
		return SavedFile{}, fmt.Errorf("media: create upload directory: %w", err)
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

	limited := &io.LimitedReader{R: source, N: maxBytes + 1}
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
	if total > maxBytes {
		return SavedFile{}, ErrFileTooLarge
	}
	if validateDimensions {
		if _, err := temporary.Seek(0, io.SeekStart); err != nil {
			return SavedFile{}, fmt.Errorf("media: rewind uploaded image: %w", err)
		}
		width, height, err := imageDimensions(temporary, wantedType)
		if err != nil {
			return SavedFile{}, fmt.Errorf("%w: %v", ErrInvalidDimensions, err)
		}
		if !validAvatarDimensions(width, height) {
			return SavedFile{}, ErrInvalidDimensions
		}
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

	key := path.Join(keyPrefix, randomName)
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

func imageDimensions(source io.Reader, contentType string) (int, int, error) {
	switch contentType {
	case "image/jpeg":
		config, err := jpeg.DecodeConfig(source)
		if err != nil {
			return 0, 0, err
		}
		return config.Width, config.Height, nil
	case "image/png":
		config, err := png.DecodeConfig(source)
		if err != nil {
			return 0, 0, err
		}
		return config.Width, config.Height, nil
	case "image/webp":
		return webPDimensions(source)
	default:
		return 0, 0, errors.New("unsupported avatar content type")
	}
}

// webPDimensions validates the bounded RIFF chunk structure and parses the
// three standardized still-image WebP headers without decoding pixels.
func webPDimensions(source io.Reader) (int, int, error) {
	contents, err := io.ReadAll(io.LimitReader(source, maxAvatarUploadBytes+1))
	if err != nil {
		return 0, 0, err
	}
	if len(contents) > int(maxAvatarUploadBytes) {
		return 0, 0, ErrFileTooLarge
	}
	if len(contents) < 20 || string(contents[0:4]) != "RIFF" || string(contents[8:12]) != "WEBP" {
		return 0, 0, errors.New("invalid WebP container")
	}
	if declared := uint64(binary.LittleEndian.Uint32(contents[4:8])) + 8; declared != uint64(len(contents)) {
		return 0, 0, errors.New("invalid WebP container size")
	}

	var canvasWidth, canvasHeight int
	var imageWidth, imageHeight int
	var haveImage bool
	for offset := 12; offset < len(contents); {
		if offset+8 > len(contents) {
			return 0, 0, errors.New("truncated WebP chunk header")
		}
		kind := string(contents[offset : offset+4])
		size := uint64(binary.LittleEndian.Uint32(contents[offset+4 : offset+8]))
		payloadStart := uint64(offset + 8)
		payloadEnd := payloadStart + size
		if payloadEnd > uint64(len(contents)) {
			return 0, 0, errors.New("truncated WebP chunk payload")
		}
		payload := contents[payloadStart:payloadEnd]
		switch kind {
		case "VP8X":
			if offset != 12 || len(payload) < 10 || payload[0]&0xc3 != 0 || payload[1] != 0 || payload[2] != 0 || payload[3] != 0 {
				return 0, 0, errors.New("invalid or animated VP8X header")
			}
			canvasWidth = 1 + int(payload[4]) + (int(payload[5]) << 8) + (int(payload[6]) << 16)
			canvasHeight = 1 + int(payload[7]) + (int(payload[8]) << 8) + (int(payload[9]) << 16)
		case "VP8L":
			if haveImage || len(payload) < 5 || payload[0] != 0x2f || payload[4]&0xe0 != 0 {
				return 0, 0, errors.New("invalid VP8L header")
			}
			imageWidth = 1 + int(payload[1]) + ((int(payload[2]) & 0x3f) << 8)
			imageHeight = 1 + (int(payload[2]) >> 6) + (int(payload[3]) << 2) + ((int(payload[4]) & 0x0f) << 10)
			haveImage = true
		case "VP8 ":
			if haveImage || len(payload) < 10 || payload[0]&0x01 != 0 || payload[3] != 0x9d || payload[4] != 0x01 || payload[5] != 0x2a {
				return 0, 0, errors.New("invalid VP8 key-frame header")
			}
			imageWidth = int(binary.LittleEndian.Uint16(payload[6:8]) & 0x3fff)
			imageHeight = int(binary.LittleEndian.Uint16(payload[8:10]) & 0x3fff)
			haveImage = true
		}
		paddedEnd := payloadEnd + size%2
		if paddedEnd > uint64(len(contents)) {
			return 0, 0, errors.New("missing WebP chunk padding")
		}
		offset = int(paddedEnd)
	}
	if imageWidth <= 0 || imageHeight <= 0 {
		return 0, 0, errors.New("WebP container has no still image")
	}
	if canvasWidth > 0 || canvasHeight > 0 {
		if canvasWidth <= 0 || canvasHeight <= 0 || imageWidth > canvasWidth || imageHeight > canvasHeight {
			return 0, 0, errors.New("WebP canvas and image dimensions conflict")
		}
		return canvasWidth, canvasHeight, nil
	}
	return imageWidth, imageHeight, nil
}

func validAvatarDimensions(width, height int) bool {
	return width > 0 && height > 0 && width <= maxAvatarDimension && height <= maxAvatarDimension &&
		int64(width)*int64(height) <= maxAvatarPixels
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

// DeleteUserAvatarURL removes an avatar only when its generated key belongs to
// the expected user. Foreign, external, demo, and listing URLs are ignored.
func (s *Storage) DeleteUserAvatarURL(userID int64, rawURL string) error {
	if userID <= 0 {
		return errors.New("media: user ID must be positive")
	}
	key, ok := s.keyFromURL(rawURL)
	expectedPrefix := "avatars/" + strconv.FormatInt(userID, 10) + "/"
	if !ok || !avatarKeyPattern.MatchString(key) || !strings.HasPrefix(key, expectedPrefix) {
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

// DeleteUserAvatarMedia removes the complete generated avatar namespace for a
// user, including any inaccessible orphan left by an interrupted replacement.
func (s *Storage) DeleteUserAvatarMedia(userID int64) error {
	if userID <= 0 {
		return errors.New("media: user ID must be positive")
	}
	root, err := os.OpenRoot(s.root)
	if err != nil {
		return fmt.Errorf("media: open storage root: %w", err)
	}
	defer root.Close()
	directory := filepath.Join("avatars", strconv.FormatInt(userID, 10))
	if err := root.RemoveAll(directory); err != nil {
		return fmt.Errorf("media: remove user avatar media: %w", err)
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
	if uploadKeyPattern.MatchString(key) || avatarKeyPattern.MatchString(key) {
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
	return uploadKeyPattern.MatchString(key) || avatarKeyPattern.MatchString(key) || demoKeyPattern.MatchString(key)
}
