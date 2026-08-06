package database

import (
	"strings"
	"testing"
	"testing/fstest"

	migrationfiles "github.com/CP3402-GROUP/LoopBuy/backend/migrations"
)

func TestEmbeddedMigrationsLoad(t *testing.T) {
	t.Parallel()

	migrations, err := loadMigrations(migrationfiles.Files)
	if err != nil {
		t.Fatalf("loadMigrations(embedded) error = %v", err)
	}
	if got, want := len(migrations), 11; got != want {
		t.Fatalf("embedded migration count = %d, want %d", got, want)
	}
	if migrations[0].Name != "001_init.sql" || migrations[0].Transactional {
		t.Fatalf("first embedded migration = %#v, want non-transactional 001_init.sql", migrations[0])
	}
	if migrations[1].Name != "002_seed_categories.sql" || !migrations[1].Transactional {
		t.Fatalf("second embedded migration = %#v, want transactional 002_seed_categories.sql", migrations[1])
	}
	if migrations[2].Name != "003_outbox_leases_and_cosine_scores.sql" || migrations[2].Transactional {
		t.Fatalf("third embedded migration = %#v, want non-transactional 003_outbox_leases_and_cosine_scores.sql", migrations[2])
	}
	if migrations[3].Name != "004_listing_revisions.sql" || migrations[3].Transactional {
		t.Fatalf("fourth embedded migration = %#v, want non-transactional 004_listing_revisions.sql", migrations[3])
	}
	if migrations[4].Name != "005_account_verification_and_identities.sql" || migrations[4].Transactional {
		t.Fatalf("fifth embedded migration = %#v, want non-transactional 005_account_verification_and_identities.sql", migrations[4])
	}
	if migrations[5].Name != "006_demo_seed_registry.sql" || migrations[5].Transactional {
		t.Fatalf("sixth embedded migration = %#v, want non-transactional 006_demo_seed_registry.sql", migrations[5])
	}
	if migrations[6].Name != "007_outbox_embedding_retry_safety.sql" || migrations[6].Transactional {
		t.Fatalf("seventh embedded migration = %#v, want non-transactional 007_outbox_embedding_retry_safety.sql", migrations[6])
	}
	if migrations[7].Name != "008_email_delivery_budget.sql" || migrations[7].Transactional {
		t.Fatalf("eighth embedded migration = %#v, want non-transactional 008_email_delivery_budget.sql", migrations[7])
	}
	if migrations[8].Name != "009_provider_request_budgets.sql" || migrations[8].Transactional {
		t.Fatalf("ninth embedded migration = %#v, want non-transactional 009_provider_request_budgets.sql", migrations[8])
	}
	if migrations[9].Name != "010_scam_signal_audit.sql" || migrations[9].Transactional {
		t.Fatalf("tenth embedded migration = %#v, want non-transactional 010_scam_signal_audit.sql", migrations[9])
	}
	if migrations[10].Name != "011_classify_legacy_moderation.sql" || !migrations[10].Transactional {
		t.Fatalf("eleventh embedded migration = %#v, want transactional 011_classify_legacy_moderation.sql", migrations[10])
	}
}

func TestSplitSQLStatements(t *testing.T) {
	t.Parallel()

	input := `
-- a semicolon in this comment must not split; anything
CREATE TABLE example (id BIGINT PRIMARY KEY, note VARCHAR(100));
INSERT INTO example (id, note) VALUES (1, 'seller\'s; note');
INSERT INTO example (id, note) VALUES (2, "double;quoted");
INSERT INTO example (id, note) VALUES (3, 'two ''quoted;'' words');
/* block; comment */ UPDATE example SET note = 'done; still one statement' WHERE id = 1;
# trailing comment;
`

	statements, err := splitSQLStatements(input)
	if err != nil {
		t.Fatalf("splitSQLStatements() error = %v", err)
	}
	if got, want := len(statements), 5; got != want {
		t.Fatalf("splitSQLStatements() count = %d, want %d\n%q", got, want, statements)
	}
	if !strings.Contains(statements[1], "seller\\'s; note") {
		t.Fatalf("single-quoted semicolon was split: %q", statements[1])
	}
	if !strings.HasPrefix(statements[4], "/* block; comment */ UPDATE") {
		t.Fatalf("leading block comment was not retained: %q", statements[4])
	}
}

func TestSplitSQLStatementsRejectsUnterminatedInput(t *testing.T) {
	t.Parallel()

	tests := []struct {
		name  string
		input string
	}{
		{name: "single quote", input: "INSERT INTO t VALUES ('oops);"},
		{name: "double quote", input: `INSERT INTO t VALUES ("oops);`},
		{name: "backtick", input: "SELECT `oops;"},
		{name: "block comment", input: "/* never closed"},
	}

	for _, test := range tests {
		test := test
		t.Run(test.name, func(t *testing.T) {
			t.Parallel()
			if _, err := splitSQLStatements(test.input); err == nil {
				t.Fatal("splitSQLStatements() error = nil, want parse error")
			}
		})
	}
}

func TestMigrationChecksumNormalizesBOMAndLineEndings(t *testing.T) {
	t.Parallel()

	unix := []byte("INSERT INTO t VALUES (1);\n")
	windowsWithBOM := append([]byte{0xEF, 0xBB, 0xBF}, []byte("INSERT INTO t VALUES (1);\r\n")...)

	if got, want := migrationChecksum(windowsWithBOM), migrationChecksum(unix); got != want {
		t.Fatalf("migrationChecksum() = %s, want %s", got, want)
	}
}

func TestLoadMigrationsSortsAndClassifies(t *testing.T) {
	t.Parallel()

	source := fstest.MapFS{
		"002_seed.sql": {Data: []byte("INSERT INTO categories (name) VALUES ('One');")},
		"001_init.sql": {Data: []byte("CREATE TABLE categories (name VARCHAR(100));")},
		"README.md":    {Data: []byte("ignored")},
	}

	migrations, err := loadMigrations(source)
	if err != nil {
		t.Fatalf("loadMigrations() error = %v", err)
	}
	if got, want := len(migrations), 2; got != want {
		t.Fatalf("loadMigrations() count = %d, want %d", got, want)
	}
	if migrations[0].Version != 1 || migrations[0].Name != "001_init.sql" {
		t.Fatalf("first migration = %#v, want version 1", migrations[0])
	}
	if migrations[0].Transactional {
		t.Fatal("DDL migration marked transactional")
	}
	if !migrations[1].Transactional {
		t.Fatal("InnoDB INSERT migration marked non-transactional")
	}
	if len(migrations[0].Checksum) != 64 {
		t.Fatalf("checksum length = %d, want 64", len(migrations[0].Checksum))
	}
}

func TestLoadMigrationsRejectsDuplicateVersion(t *testing.T) {
	t.Parallel()

	source := fstest.MapFS{
		"001_first.sql":  {Data: []byte("CREATE TABLE first_table (id BIGINT);")},
		"001_second.sql": {Data: []byte("CREATE TABLE second_table (id BIGINT);")},
	}

	_, err := loadMigrations(source)
	if err == nil || !strings.Contains(err.Error(), "duplicate migration version") {
		t.Fatalf("loadMigrations() error = %v, want duplicate version error", err)
	}
}

func TestValidateAppliedMigrationsDetectsDrift(t *testing.T) {
	t.Parallel()

	available := []migration{{Version: 1, Name: "001_init.sql", Checksum: "new"}}
	applied := map[uint64]appliedMigration{1: {Name: "001_init.sql", Checksum: "old"}}

	err := validateAppliedMigrations(available, applied)
	if err == nil || !strings.Contains(err.Error(), "checksum drift") {
		t.Fatalf("validateAppliedMigrations() error = %v, want checksum drift", err)
	}
}

func TestValidateAppliedMigrationsRejectsNonPrefixHistory(t *testing.T) {
	t.Parallel()

	available := []migration{
		{Version: 1, Name: "001_init.sql", Checksum: "one"},
		{Version: 2, Name: "002_seed.sql", Checksum: "two"},
	}
	applied := map[uint64]appliedMigration{
		2: {Name: "002_seed.sql", Checksum: "two"},
	}

	err := validateAppliedMigrations(available, applied)
	if err == nil || !strings.Contains(err.Error(), "after an unapplied earlier migration") {
		t.Fatalf("validateAppliedMigrations() error = %v, want non-prefix history error", err)
	}
}

func TestMigrationCanUseTransaction(t *testing.T) {
	t.Parallel()

	if !migrationCanUseTransaction([]string{
		"INSERT INTO t VALUES (1)",
		"UPDATE t SET value = 2",
	}) {
		t.Fatal("DML-only migration was not transactional")
	}
	if migrationCanUseTransaction([]string{
		"INSERT INTO t VALUES (1)",
		"ALTER TABLE t ADD COLUMN value INT",
	}) {
		t.Fatal("migration containing DDL was transactional")
	}
}
