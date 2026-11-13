-- Generated from ../0001_low_jean_grey.sql by database/tools/pg2mysql.php — do not edit by hand.
CREATE TABLE `platform_settings` (
	`section` VARCHAR(40) NOT NULL PRIMARY KEY,
	`value` JSON NOT NULL DEFAULT (_utf8mb4'{}'),
	`updated_by` VARCHAR(255) NULL,
	`updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
ALTER TABLE `admin_action_log` MODIFY `target_tenant_id` CHAR(36) NULL;
