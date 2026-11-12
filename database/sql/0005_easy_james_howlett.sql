ALTER TABLE "chat_messages" ADD COLUMN "sources" jsonb;--> statement-breakpoint
ALTER TABLE "team_note_recipients" ADD COLUMN "archived_at" timestamp with time zone;