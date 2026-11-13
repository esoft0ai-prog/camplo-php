-- Generated from ../0003_lumpy_beyonder.sql by database/tools/pg2mysql.php — do not edit by hand.
ALTER TABLE `integrations` ADD COLUMN `config` JSON NULL;
--> statement-breakpoint
ALTER TABLE `integrations` ADD COLUMN `secrets_encrypted` TEXT NULL;
