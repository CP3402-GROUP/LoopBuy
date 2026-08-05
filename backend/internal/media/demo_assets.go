package media

import (
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

type DemoAsset struct {
	Name       string
	SourceFile string
	Key        string
	MIMEType   string
}

var demoAssets = []DemoAsset{
	{Name: "headphones", SourceFile: "wireless_headphone.jpeg", Key: "demo/wireless-headphones-v1.jpeg", MIMEType: "image/jpeg"},
	{Name: "iphone", SourceFile: "iphone.webp", Key: "demo/iphone-v1.webp", MIMEType: "image/webp"},
	{Name: "keyboard", SourceFile: "Gaming_Mechanical_Keyboard.jpg", Key: "demo/mechanical-keyboard-v1.jpg", MIMEType: "image/jpeg"},
	{Name: "bicycle", SourceFile: "Mountain_Bike_Trek.webp", Key: "demo/mountain-bike-v1.webp", MIMEType: "image/webp"},
	{Name: "jacket", SourceFile: "Denim_Jacket.webp", Key: "demo/denim-jacket-v1.webp", MIMEType: "image/webp"},
	{Name: "air-fryer", SourceFile: "Air_Fryer.webp", Key: "demo/air-fryer-v1.webp", MIMEType: "image/webp"},
	{Name: "book", SourceFile: "Atomic_Habits_James_Clear.webp", Key: "demo/atomic-habits-v1.webp", MIMEType: "image/webp"},
	{Name: "sofa", SourceFile: "Three_Seater_Sofa.jpg", Key: "demo/three-seater-sofa-v1.jpg", MIMEType: "image/jpeg"},
	{Name: "tennis-racket", SourceFile: "Wilson_Tennis_Racket.webp", Key: "demo/tennis-racket-v1.webp", MIMEType: "image/webp"},
	{Name: "guitar", SourceFile: "Acoustic_Guitar.webp", Key: "demo/acoustic-guitar-v1.webp", MIMEType: "image/webp"},
	{Name: "coffee-machine", SourceFile: "Coffee_Machine.webp", Key: "demo/coffee-machine-v1.webp", MIMEType: "image/webp"},
	{Name: "suitcase", SourceFile: "Travel_Suitcase_Samsonite.jpg", Key: "demo/travel-suitcase-v1.jpg", MIMEType: "image/jpeg"},
}

// EnsureDemoAssets copies the repository-owned product photos into persistent
// media storage. Existing versioned objects are never overwritten, which keeps
// the immutable HTTP cache contract honest.
func (s *Storage) EnsureDemoAssets(sourceRoot string) (map[string]string, error) {
	sourceRoot = strings.TrimSpace(sourceRoot)
	if sourceRoot == "" {
		return nil, errors.New("media: demo source directory is required")
	}
	absSourceRoot, err := filepath.Abs(sourceRoot)
	if err != nil {
		return nil, fmt.Errorf("media: resolve demo source directory: %w", err)
	}
	info, err := os.Stat(absSourceRoot)
	if err != nil || !info.IsDir() {
		return nil, fmt.Errorf("media: demo source directory is unavailable: %w", err)
	}
	if err := os.MkdirAll(filepath.Join(s.root, "demo"), 0o750); err != nil {
		return nil, fmt.Errorf("media: create demo directory: %w", err)
	}

	urls := make(map[string]string, len(demoAssets))
	for _, asset := range demoAssets {
		sourcePath := filepath.Join(absSourceRoot, asset.SourceFile)
		if err := copyDemoAsset(sourcePath, filepath.Join(s.root, filepath.FromSlash(asset.Key)), asset.MIMEType); err != nil {
			return nil, fmt.Errorf("media: install demo asset %q: %w", asset.Name, err)
		}
		publicURL, err := s.PublicURL(asset.Key)
		if err != nil {
			return nil, err
		}
		urls[asset.Name] = publicURL
	}
	return urls, nil
}

func copyDemoAsset(sourcePath, destinationPath, wantedMIME string) error {
	if info, err := os.Lstat(destinationPath); err == nil {
		if !info.Mode().IsRegular() {
			return errors.New("destination exists but is not a regular file")
		}
		return nil
	} else if !errors.Is(err, os.ErrNotExist) {
		return err
	}

	source, err := os.Open(sourcePath)
	if err != nil {
		return err
	}
	defer source.Close()
	header := make([]byte, 512)
	headerLength, err := io.ReadFull(source, header)
	if err != nil && !errors.Is(err, io.ErrUnexpectedEOF) && !errors.Is(err, io.EOF) {
		return err
	}
	if headerLength == 0 {
		return ErrEmptyFile
	}
	header = header[:headerLength]
	if detected := http.DetectContentType(header); detected != wantedMIME {
		return fmt.Errorf("%w: expected %s but found %s", ErrMIMETypeMismatch, wantedMIME, detected)
	}

	temporary, err := os.CreateTemp(filepath.Dir(destinationPath), ".demo-*.tmp")
	if err != nil {
		return err
	}
	temporaryName := temporary.Name()
	committed := false
	defer func() {
		_ = temporary.Close()
		if !committed {
			_ = os.Remove(temporaryName)
		}
	}()
	if _, err := temporary.Write(header); err != nil {
		return err
	}
	if _, err := io.Copy(temporary, source); err != nil {
		return err
	}
	if err := temporary.Sync(); err != nil {
		return err
	}
	if err := temporary.Close(); err != nil {
		return err
	}
	if err := os.Rename(temporaryName, destinationPath); err != nil {
		return err
	}
	committed = true
	if err := os.Chmod(destinationPath, 0o640); err != nil {
		_ = os.Remove(destinationPath)
		return err
	}
	return nil
}
