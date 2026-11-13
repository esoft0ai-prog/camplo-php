-- Generated from ../0004_strong_spacker_dave.sql by database/tools/pg2mysql.php — do not edit by hand.
CREATE TABLE `error_events` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`fingerprint` VARCHAR(64) NOT NULL,
	`source` VARCHAR(20) NOT NULL,
	`level` VARCHAR(10) NOT NULL DEFAULT 'error',
	`message` TEXT NOT NULL,
	`stack` TEXT NULL,
	`route` VARCHAR(500) NULL,
	`tenant_id` CHAR(36) NULL,
	`user_id` CHAR(36) NULL,
	`context` JSON NULL,
	`count` INT NOT NULL DEFAULT 1,
	`first_seen_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`last_seen_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`resolved_at` DATETIME(3) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_error_events_fingerprint` ON `error_events` (`fingerprint`);
--> statement-breakpoint
CREATE INDEX `idx_error_events_last_seen` ON `error_events` (`last_seen_at`);
