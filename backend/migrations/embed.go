// Package migrations exposes the versioned SQL migrations embedded in the
// backend binary. Keeping the SQL here also lets operators inspect and apply
// the exact same artifacts manually when recovering a failed DDL migration.
package migrations

import "embed"

// Files contains every versioned SQL migration in this directory.
//
//go:embed *.sql
var Files embed.FS
