// Package database opens the MySQL connection pool and applies the embedded
// versioned schema migrations.
package database

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"time"

	"github.com/go-sql-driver/mysql"
)

const (
	defaultMaxOpenConnections = 25
	defaultMaxIdleConnections = 5
	defaultConnectionLifetime = 30 * time.Minute
	defaultConnectionIdleTime = 5 * time.Minute
	defaultPingTimeout        = 5 * time.Second
)

// Config controls the MySQL connection pool. DSN must name a database that
// has already been provisioned; migrations intentionally do not CREATE or USE
// a database.
type Config struct {
	DSN             string
	MaxOpenConns    int
	MaxIdleConns    int
	ConnMaxLifetime time.Duration
	ConnMaxIdleTime time.Duration
	PingTimeout     time.Duration
}

// Open validates config, opens a MySQL pool, and verifies that the database is
// reachable. Call Migrate after Open during process startup.
func Open(config Config) (*sql.DB, error) {
	if config.DSN == "" {
		return nil, errors.New("database: DSN is required")
	}

	driverConfig, err := mysql.ParseDSN(config.DSN)
	if err != nil {
		return nil, fmt.Errorf("database: parse DSN: %w", err)
	}

	// Keep timestamps and text behavior identical across local, CI, and
	// production environments. Multi-statements stay disabled because the
	// migration runner parses and executes each statement itself.
	driverConfig.ParseTime = true
	driverConfig.Loc = time.UTC
	driverConfig.MultiStatements = false
	if driverConfig.Collation == "" {
		driverConfig.Collation = "utf8mb4_0900_ai_ci"
	}
	if driverConfig.Params == nil {
		driverConfig.Params = make(map[string]string)
	}
	// Collation is handled by the driver during connection setup. Do not add a
	// generic `charset` system-variable parameter: MySQL 8 has no variable with
	// that name and would reject the connection with error 1193.
	delete(driverConfig.Params, "charset")
	if _, exists := driverConfig.Params["time_zone"]; !exists {
		driverConfig.Params["time_zone"] = "'+00:00'"
	}

	connector, err := mysql.NewConnector(driverConfig)
	if err != nil {
		return nil, fmt.Errorf("database: create MySQL connector: %w", err)
	}

	db := sql.OpenDB(connector)
	poolConfig := config.withDefaults()
	db.SetMaxOpenConns(poolConfig.MaxOpenConns)
	db.SetMaxIdleConns(poolConfig.MaxIdleConns)
	db.SetConnMaxLifetime(poolConfig.ConnMaxLifetime)
	db.SetConnMaxIdleTime(poolConfig.ConnMaxIdleTime)

	ctx, cancel := context.WithTimeout(context.Background(), poolConfig.PingTimeout)
	defer cancel()
	if err := Ping(ctx, db); err != nil {
		_ = db.Close()
		return nil, err
	}

	return db, nil
}

// Ping verifies that db can reach MySQL before ctx expires.
func Ping(ctx context.Context, db *sql.DB) error {
	if db == nil {
		return errors.New("database: nil database handle")
	}
	if ctx == nil {
		return errors.New("database: nil context")
	}
	if err := db.PingContext(ctx); err != nil {
		return fmt.Errorf("database: ping MySQL: %w", err)
	}
	return nil
}

func (config Config) withDefaults() Config {
	if config.MaxOpenConns <= 0 {
		config.MaxOpenConns = defaultMaxOpenConnections
	}
	if config.MaxIdleConns <= 0 {
		config.MaxIdleConns = defaultMaxIdleConnections
	}
	if config.MaxIdleConns > config.MaxOpenConns {
		config.MaxIdleConns = config.MaxOpenConns
	}
	if config.ConnMaxLifetime <= 0 {
		config.ConnMaxLifetime = defaultConnectionLifetime
	}
	if config.ConnMaxIdleTime <= 0 {
		config.ConnMaxIdleTime = defaultConnectionIdleTime
	}
	if config.PingTimeout <= 0 {
		config.PingTimeout = defaultPingTimeout
	}
	return config
}
