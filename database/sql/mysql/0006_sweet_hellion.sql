-- Generated from ../0006_sweet_hellion.sql by database/tools/pg2mysql.php — do not edit by hand.
CREATE TABLE `extension_catalog` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`key` VARCHAR(100) NOT NULL,
	`kind` VARCHAR(20) NOT NULL,
	`name` VARCHAR(255) NOT NULL,
	`description` TEXT NOT NULL DEFAULT (''),
	`config` JSON NOT NULL DEFAULT (_utf8mb4'{}'),
	`available` TINYINT(1) NOT NULL DEFAULT 1,
	`min_plan` VARCHAR(20) NOT NULL DEFAULT 'starter',
	`custom` TINYINT(1) NOT NULL DEFAULT 0,
	`updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `mcp_tokens` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`created_by` CHAR(36) NOT NULL,
	`name` VARCHAR(100) NOT NULL,
	`token_hash` VARCHAR(64) NOT NULL,
	`prefix` VARCHAR(16) NOT NULL,
	`last_used_at` DATETIME(3) NULL,
	`revoked_at` DATETIME(3) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `workspace_extensions` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`key` VARCHAR(100) NOT NULL,
	`kind` VARCHAR(20) NOT NULL,
	`enabled` TINYINT(1) NOT NULL DEFAULT 1,
	`settings` JSON NOT NULL DEFAULT (_utf8mb4'{}'),
	`secrets_encrypted` TEXT NULL,
	`status` VARCHAR(20) NOT NULL DEFAULT 'ok',
	`last_error` TEXT NULL,
	`usage_count` INT NOT NULL DEFAULT 0,
	`last_used_at` DATETIME(3) NULL,
	`activated_by` CHAR(36) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
ALTER TABLE `mcp_tokens` ADD CONSTRAINT `mcp_tokens_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `workspace_extensions` ADD CONSTRAINT `workspace_extensions_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_extension_catalog_key` ON `extension_catalog` (`key`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_mcp_tokens_hash` ON `mcp_tokens` (`token_hash`);
--> statement-breakpoint
CREATE INDEX `idx_mcp_tokens_tenant` ON `mcp_tokens` (`tenant_id`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_workspace_extensions_key` ON `workspace_extensions` (`tenant_id`,`key`);
