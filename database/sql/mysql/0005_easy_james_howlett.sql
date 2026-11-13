-- Generated from ../0005_easy_james_howlett.sql by database/tools/pg2mysql.php — do not edit by hand.
ALTER TABLE `chat_messages` ADD COLUMN `sources` JSON NULL;
--> statement-breakpoint
ALTER TABLE `team_note_recipients` ADD COLUMN `archived_at` DATETIME(3) NULL;
