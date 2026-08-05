package database

import (
	"bytes"
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"errors"
	"fmt"
	"io/fs"
	"math"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"

	migrationfiles "github.com/CP3402-GROUP/LoopBuy/backend/migrations"
)

const (
	defaultMigrationLockName    = "loopbuy_backend_schema_migrations"
	defaultMigrationLockTimeout = 30 * time.Second
	migrationReleaseTimeout     = 5 * time.Second
)

var migrationFilenamePattern = regexp.MustCompile(`^(\d{3,})_([a-z0-9][a-z0-9_-]*)\.sql$`)

const createSchemaMigrationsTableSQL = `
CREATE TABLE IF NOT EXISTS schema_migrations (
    version BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    execution_ms BIGINT UNSIGNED NOT NULL,
    applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci`

const insertSchemaMigrationSQL = `
INSERT INTO schema_migrations (version, name, checksum, execution_ms)
VALUES (?, ?, ?, ?)`

type migration struct {
	Version       uint64
	Name          string
	Checksum      string
	Statements    []string
	Transactional bool
}

type appliedMigration struct {
	Name     string
	Checksum string
}

// MigrationOptions controls serialization of migration execution.
type MigrationOptions struct {
	LockName    string
	LockTimeout time.Duration
}

// Migrate applies every embedded migration that has not yet been recorded in
// schema_migrations. It serializes runners with a MySQL advisory lock.
func Migrate(ctx context.Context, db *sql.DB) error {
	return MigrateWithOptions(ctx, db, MigrationOptions{})
}

// MigrateWithOptions is Migrate with configurable advisory-lock behavior.
func MigrateWithOptions(ctx context.Context, db *sql.DB, options MigrationOptions) (returnErr error) {
	if ctx == nil {
		return errors.New("database: nil migration context")
	}
	if db == nil {
		return errors.New("database: nil database handle")
	}

	migrations, err := loadMigrations(migrationfiles.Files)
	if err != nil {
		return fmt.Errorf("database: load embedded migrations: %w", err)
	}

	options = options.withDefaults()
	if len(options.LockName) > 64 {
		return fmt.Errorf("database: migration lock name is %d bytes; MySQL permits at most 64", len(options.LockName))
	}

	conn, err := db.Conn(ctx)
	if err != nil {
		return fmt.Errorf("database: acquire migration connection: %w", err)
	}
	defer conn.Close()

	locked, err := acquireMigrationLock(ctx, conn, options)
	if err != nil {
		return err
	}
	if !locked {
		return fmt.Errorf("database: migration advisory lock %q was not acquired within %s", options.LockName, options.LockTimeout)
	}
	defer func() {
		if err := releaseMigrationLock(conn, options.LockName); err != nil && returnErr == nil {
			returnErr = err
		}
	}()

	if _, err := conn.ExecContext(ctx, createSchemaMigrationsTableSQL); err != nil {
		return fmt.Errorf("database: create schema_migrations: %w", err)
	}

	applied, err := readAppliedMigrations(ctx, conn)
	if err != nil {
		return err
	}
	if err := validateAppliedMigrations(migrations, applied); err != nil {
		return err
	}

	for _, current := range migrations {
		if _, exists := applied[current.Version]; exists {
			continue
		}
		if err := applyMigration(ctx, conn, current); err != nil {
			return err
		}
	}

	return nil
}

func (options MigrationOptions) withDefaults() MigrationOptions {
	if options.LockName == "" {
		options.LockName = defaultMigrationLockName
	}
	if options.LockTimeout <= 0 {
		options.LockTimeout = defaultMigrationLockTimeout
	}
	return options
}

func acquireMigrationLock(ctx context.Context, conn *sql.Conn, options MigrationOptions) (bool, error) {
	timeoutSeconds := int64(math.Ceil(options.LockTimeout.Seconds()))
	if timeoutSeconds < 1 {
		timeoutSeconds = 1
	}

	var result sql.NullInt64
	if err := conn.QueryRowContext(ctx, "SELECT GET_LOCK(?, ?)", options.LockName, timeoutSeconds).Scan(&result); err != nil {
		return false, fmt.Errorf("database: acquire migration advisory lock: %w", err)
	}
	if !result.Valid {
		return false, errors.New("database: MySQL returned NULL while acquiring migration advisory lock")
	}
	return result.Int64 == 1, nil
}

func releaseMigrationLock(conn *sql.Conn, lockName string) error {
	ctx, cancel := context.WithTimeout(context.Background(), migrationReleaseTimeout)
	defer cancel()

	var result sql.NullInt64
	if err := conn.QueryRowContext(ctx, "SELECT RELEASE_LOCK(?)", lockName).Scan(&result); err != nil {
		return fmt.Errorf("database: release migration advisory lock: %w", err)
	}
	if !result.Valid || result.Int64 != 1 {
		return fmt.Errorf("database: migration advisory lock %q was not owned at release", lockName)
	}
	return nil
}

func readAppliedMigrations(ctx context.Context, conn *sql.Conn) (map[uint64]appliedMigration, error) {
	rows, err := conn.QueryContext(ctx, `SELECT version, name, checksum FROM schema_migrations ORDER BY version`)
	if err != nil {
		return nil, fmt.Errorf("database: read schema_migrations: %w", err)
	}
	defer rows.Close()

	applied := make(map[uint64]appliedMigration)
	for rows.Next() {
		var version uint64
		var current appliedMigration
		if err := rows.Scan(&version, &current.Name, &current.Checksum); err != nil {
			return nil, fmt.Errorf("database: scan schema_migrations: %w", err)
		}
		applied[version] = current
	}
	if err := rows.Err(); err != nil {
		return nil, fmt.Errorf("database: iterate schema_migrations: %w", err)
	}
	return applied, nil
}

func validateAppliedMigrations(available []migration, applied map[uint64]appliedMigration) error {
	byVersion := make(map[uint64]migration, len(available))
	for _, current := range available {
		byVersion[current.Version] = current
	}

	appliedVersions := make([]uint64, 0, len(applied))
	for version := range applied {
		appliedVersions = append(appliedVersions, version)
	}
	sort.Slice(appliedVersions, func(i, j int) bool { return appliedVersions[i] < appliedVersions[j] })
	for _, version := range appliedVersions {
		recorded := applied[version]
		if _, exists := byVersion[version]; !exists {
			return fmt.Errorf("database: applied migration version %d (%s) is absent from this binary", version, recorded.Name)
		}
	}

	missingEarlierMigration := false
	for _, current := range available {
		recorded, exists := applied[current.Version]
		if !exists {
			missingEarlierMigration = true
			continue
		}
		if missingEarlierMigration {
			return fmt.Errorf("database: migration %s is recorded after an unapplied earlier migration", current.Name)
		}
		if recorded.Name != current.Name {
			return fmt.Errorf("database: migration version %d name drift: database=%q binary=%q", current.Version, recorded.Name, current.Name)
		}
		if recorded.Checksum != current.Checksum {
			return fmt.Errorf("database: migration %s checksum drift: database=%s binary=%s", current.Name, recorded.Checksum, current.Checksum)
		}
	}
	return nil
}

func applyMigration(ctx context.Context, conn *sql.Conn, current migration) error {
	started := time.Now()
	if current.Transactional {
		tx, err := conn.BeginTx(ctx, nil)
		if err != nil {
			return fmt.Errorf("database: begin migration %s: %w", current.Name, err)
		}
		for index, statement := range current.Statements {
			if _, err := tx.ExecContext(ctx, statement); err != nil {
				_ = tx.Rollback()
				return fmt.Errorf("database: migration %s statement %d: %w", current.Name, index+1, err)
			}
		}
		if _, err := tx.ExecContext(ctx, insertSchemaMigrationSQL, current.Version, current.Name, current.Checksum, elapsedMilliseconds(started)); err != nil {
			_ = tx.Rollback()
			return fmt.Errorf("database: record migration %s: %w", current.Name, err)
		}
		if err := tx.Commit(); err != nil {
			return fmt.Errorf("database: commit migration %s: %w", current.Name, err)
		}
		return nil
	}

	// MySQL commits DDL implicitly, so wrapping a DDL migration in sql.Tx gives
	// a false sense of rollback safety. Execute it serially under GET_LOCK and
	// record it only after every statement succeeds.
	for index, statement := range current.Statements {
		if _, err := conn.ExecContext(ctx, statement); err != nil {
			return fmt.Errorf(
				"database: non-transactional migration %s statement %d: %w; earlier DDL may already be committed and must be inspected before retry",
				current.Name,
				index+1,
				err,
			)
		}
	}
	if _, err := conn.ExecContext(ctx, insertSchemaMigrationSQL, current.Version, current.Name, current.Checksum, elapsedMilliseconds(started)); err != nil {
		return fmt.Errorf("database: record non-transactional migration %s: %w; its DDL is already committed", current.Name, err)
	}
	return nil
}

func elapsedMilliseconds(started time.Time) int64 {
	elapsed := time.Since(started).Milliseconds()
	if elapsed < 0 {
		return 0
	}
	return elapsed
}

func loadMigrations(source fs.FS) ([]migration, error) {
	entries, err := fs.ReadDir(source, ".")
	if err != nil {
		return nil, fmt.Errorf("read migration directory: %w", err)
	}

	seenVersions := make(map[uint64]string)
	migrations := make([]migration, 0, len(entries))
	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".sql") {
			continue
		}

		matches := migrationFilenamePattern.FindStringSubmatch(entry.Name())
		if matches == nil {
			return nil, fmt.Errorf("invalid migration filename %q", entry.Name())
		}
		version, err := strconv.ParseUint(matches[1], 10, 64)
		if err != nil || version == 0 {
			return nil, fmt.Errorf("invalid migration version in %q", entry.Name())
		}
		if previous, duplicate := seenVersions[version]; duplicate {
			return nil, fmt.Errorf("duplicate migration version %d in %q and %q", version, previous, entry.Name())
		}

		contents, err := fs.ReadFile(source, entry.Name())
		if err != nil {
			return nil, fmt.Errorf("read migration %q: %w", entry.Name(), err)
		}
		canonical := canonicalMigration(contents)
		statements, err := splitSQLStatements(string(canonical))
		if err != nil {
			return nil, fmt.Errorf("parse migration %q: %w", entry.Name(), err)
		}
		if len(statements) == 0 {
			return nil, fmt.Errorf("migration %q contains no executable statements", entry.Name())
		}

		seenVersions[version] = entry.Name()
		migrations = append(migrations, migration{
			Version:       version,
			Name:          entry.Name(),
			Checksum:      migrationChecksum(canonical),
			Statements:    statements,
			Transactional: migrationCanUseTransaction(statements),
		})
	}

	if len(migrations) == 0 {
		return nil, errors.New("no embedded SQL migrations found")
	}
	sort.Slice(migrations, func(i, j int) bool {
		return migrations[i].Version < migrations[j].Version
	})
	return migrations, nil
}

func canonicalMigration(contents []byte) []byte {
	contents = bytes.TrimPrefix(contents, []byte{0xEF, 0xBB, 0xBF})
	contents = bytes.ReplaceAll(contents, []byte("\r\n"), []byte("\n"))
	return bytes.ReplaceAll(contents, []byte("\r"), []byte("\n"))
}

func migrationChecksum(contents []byte) string {
	sum := sha256.Sum256(canonicalMigration(contents))
	return hex.EncodeToString(sum[:])
}

func migrationCanUseTransaction(statements []string) bool {
	if len(statements) == 0 {
		return false
	}
	for _, statement := range statements {
		switch firstSQLKeyword(statement) {
		case "INSERT", "UPDATE", "DELETE", "REPLACE":
			// These statements are transactional with the InnoDB tables used by
			// LoopBuy. Unknown statements deliberately take the safer autocommit
			// path because MySQL has many implicit-commit operations.
		default:
			return false
		}
	}
	return true
}

func splitSQLStatements(input string) ([]string, error) {
	const (
		stateNormal = iota
		stateSingleQuote
		stateDoubleQuote
		stateBacktick
		stateLineComment
		stateBlockComment
	)

	state := stateNormal
	var current strings.Builder
	statements := make([]string, 0)

	appendCurrent := func() {
		statement := strings.TrimSpace(current.String())
		current.Reset()
		if firstSQLKeyword(statement) != "" {
			statements = append(statements, statement)
		}
	}

	for index := 0; index < len(input); index++ {
		character := input[index]
		next := byte(0)
		if index+1 < len(input) {
			next = input[index+1]
		}

		switch state {
		case stateNormal:
			switch {
			case character == '\'':
				state = stateSingleQuote
				current.WriteByte(character)
			case character == '"':
				state = stateDoubleQuote
				current.WriteByte(character)
			case character == '`':
				state = stateBacktick
				current.WriteByte(character)
			case character == '#':
				state = stateLineComment
				current.WriteByte(character)
			case character == '-' && next == '-' && isSQLCommentSpace(input, index+2):
				state = stateLineComment
				current.WriteByte(character)
				current.WriteByte(next)
				index++
			case character == '/' && next == '*':
				state = stateBlockComment
				current.WriteByte(character)
				current.WriteByte(next)
				index++
			case character == ';':
				appendCurrent()
			default:
				current.WriteByte(character)
			}

		case stateSingleQuote, stateDoubleQuote, stateBacktick:
			current.WriteByte(character)
			quote := byte('\'')
			if state == stateDoubleQuote {
				quote = '"'
			} else if state == stateBacktick {
				quote = '`'
			}

			if character == '\\' && state != stateBacktick && index+1 < len(input) {
				index++
				current.WriteByte(input[index])
				continue
			}
			if character == quote {
				if next == quote {
					index++
					current.WriteByte(next)
				} else {
					state = stateNormal
				}
			}

		case stateLineComment:
			current.WriteByte(character)
			if character == '\n' {
				state = stateNormal
			}

		case stateBlockComment:
			current.WriteByte(character)
			if character == '*' && next == '/' {
				current.WriteByte(next)
				index++
				state = stateNormal
			}
		}
	}

	switch state {
	case stateSingleQuote:
		return nil, errors.New("unterminated single-quoted string")
	case stateDoubleQuote:
		return nil, errors.New("unterminated double-quoted string")
	case stateBacktick:
		return nil, errors.New("unterminated backtick identifier")
	case stateBlockComment:
		return nil, errors.New("unterminated block comment")
	}

	appendCurrent()
	return statements, nil
}

func isSQLCommentSpace(input string, index int) bool {
	if index >= len(input) {
		return true
	}
	switch input[index] {
	case ' ', '\t', '\r', '\n', '\f':
		return true
	default:
		return false
	}
}

func firstSQLKeyword(statement string) string {
	remaining := strings.TrimSpace(statement)
	for remaining != "" {
		switch {
		case strings.HasPrefix(remaining, "#"):
			if newline := strings.IndexByte(remaining, '\n'); newline >= 0 {
				remaining = strings.TrimSpace(remaining[newline+1:])
				continue
			}
			return ""
		case strings.HasPrefix(remaining, "--") && isSQLCommentSpace(remaining, 2):
			if newline := strings.IndexByte(remaining, '\n'); newline >= 0 {
				remaining = strings.TrimSpace(remaining[newline+1:])
				continue
			}
			return ""
		case strings.HasPrefix(remaining, "/*"):
			end := strings.Index(remaining[2:], "*/")
			if end < 0 {
				return ""
			}
			remaining = strings.TrimSpace(remaining[end+4:])
			continue
		}
		break
	}

	end := 0
	for end < len(remaining) {
		character := remaining[end]
		if (character < 'a' || character > 'z') && (character < 'A' || character > 'Z') {
			break
		}
		end++
	}
	if end == 0 {
		return ""
	}
	return strings.ToUpper(remaining[:end])
}
