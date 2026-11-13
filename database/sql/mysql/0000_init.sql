-- Generated from ../0000_init.sql by database/tools/pg2mysql.php — do not edit by hand.
CREATE TABLE `admin_action_log` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`admin_id` VARCHAR(255) NOT NULL,
	`action` VARCHAR(100) NOT NULL,
	`target_tenant_id` CHAR(36) NOT NULL,
	`note` TEXT NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `agent_worker_logs` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`worker_id` VARCHAR(100) NOT NULL,
	`capability_type` ENUM('investigation', 'research', 'campaign_analysis', 'conversation_analysis', 'strategic_analysis', 'evaluation', 'execution') NOT NULL,
	`objective` TEXT NOT NULL,
	`campaign_id` CHAR(36) NULL,
	`tools_used` JSON NOT NULL DEFAULT (_utf8mb4'[]'),
	`tool_call_count` INT NOT NULL DEFAULT 0,
	`workload_level` ENUM('utility', 'workhorse', 'deep') NOT NULL,
	`model_used` VARCHAR(255) NOT NULL,
	`input_tokens` INT NOT NULL DEFAULT 0,
	`output_tokens` INT NOT NULL DEFAULT 0,
	`cost_usd` DECIMAL(10,6) NOT NULL DEFAULT '0',
	`byok` TINYINT(1) NOT NULL DEFAULT 0,
	`status` ENUM('completed', 'failed', 'interrupted', 'timeout') NOT NULL,
	`duration_ms` INT NOT NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`completed_at` DATETIME(3) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `ai_provider_configs` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`primary_provider` ENUM('anthropic', 'openai', 'groq', 'gemini', 'deepseek', 'mistral', 'cohere', 'xai', 'qwen', 'mimo', 'arcee', 'glm', 'minimax', 'kimi', 'bytedance', 'openrouter', 'other') NULL,
	`primary_model_name` VARCHAR(255) NULL,
	`primary_api_key_encrypted` TEXT NULL,
	`primary_status` ENUM('connected', 'not_connected', 'failed') NOT NULL DEFAULT 'not_connected',
	`fallback_provider` ENUM('anthropic', 'openai', 'groq', 'gemini', 'deepseek', 'mistral', 'cohere', 'xai', 'qwen', 'mimo', 'arcee', 'glm', 'minimax', 'kimi', 'bytedance', 'openrouter', 'other') NULL,
	`fallback_model_name` VARCHAR(255) NULL,
	`fallback_api_key_encrypted` TEXT NULL,
	`fallback_status` ENUM('connected', 'not_connected', 'failed') NOT NULL DEFAULT 'not_connected',
	`fallback_enabled` TINYINT(1) NOT NULL DEFAULT 0,
	`using_fallback` TINYINT(1) NOT NULL DEFAULT 0,
	`refresh_interval_minutes` INT NOT NULL DEFAULT 30,
	`event_triggers` JSON NOT NULL DEFAULT (_utf8mb4'["sla_breach","webhook_silence","lead_batch","budget_threshold"]'),
	`last_refresh_at` DATETIME(3) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `ai_workload_logs` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`task_type` VARCHAR(100) NOT NULL,
	`workload_level` ENUM('quick', 'standard', 'deep', 'strategic') NOT NULL,
	`model_used` VARCHAR(255) NOT NULL,
	`input_tokens` INT NOT NULL DEFAULT 0,
	`output_tokens` INT NOT NULL DEFAULT 0,
	`cost_usd` DECIMAL(10,6) NOT NULL DEFAULT '0',
	`byok` TINYINT(1) NOT NULL DEFAULT 0,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `campaign_changes` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`campaign_id` CHAR(36) NOT NULL,
	`change_type` ENUM('budget', 'audience', 'creative', 'messaging', 'page') NOT NULL,
	`change_description` TEXT NOT NULL,
	`changed_by` CHAR(36) NOT NULL,
	`performance_before` JSON NOT NULL,
	`performance_after` JSON NULL,
	`changed_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `campaign_logs` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`campaign_id` CHAR(36) NOT NULL,
	`actor_id` CHAR(36) NULL,
	`description` TEXT NOT NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `campaign_members` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`campaign_id` CHAR(36) NOT NULL,
	`tenant_id` CHAR(36) NOT NULL,
	`user_id` CHAR(36) NOT NULL,
	`added_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `campaign_recommendations` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`campaign_id` CHAR(36) NULL,
	`level` INT NOT NULL,
	`action_text` VARCHAR(500) NOT NULL,
	`why` TEXT NOT NULL,
	`evidence` JSON NOT NULL,
	`diagnosis` TEXT NULL,
	`expected_outcome` TEXT NOT NULL,
	`risk` TEXT NOT NULL,
	`confidence` ENUM('low', 'medium', 'high') NOT NULL,
	`next_step` TEXT NOT NULL,
	`why_now` TEXT NOT NULL,
	`memory_references` JSON NOT NULL DEFAULT (_utf8mb4'[]'),
	`apply_action` JSON NULL,
	`status` ENUM('surfaced', 'applied', 'dismissed') NOT NULL DEFAULT 'surfaced',
	`surfaced_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`actioned_at` DATETIME(3) NULL,
	`measured_at` DATETIME(3) NULL,
	`outcome_measured` TINYINT(1) NOT NULL DEFAULT 0,
	`outcome_data` JSON NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `campaign_retrospectives` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`campaign_id` CHAR(36) NOT NULL,
	`generated` TINYINT(1) NOT NULL DEFAULT 0,
	`generated_at` DATETIME(3) NULL,
	`total_leads` INT NULL,
	`responded_count` INT NULL,
	`avg_response_time_ms` BIGINT NULL,
	`acknowledgment_rate` DECIMAL(5,4) NULL,
	`cpl` DECIMAL(12,2) NULL,
	`best_page_id` CHAR(36) NULL,
	`worst_page_id` CHAR(36) NULL,
	`ai_observation` TEXT NULL,
	`pdf_url` VARCHAR(500) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `campaigns` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`name` VARCHAR(255) NOT NULL,
	`description` TEXT NULL,
	`status` ENUM('active', 'watch', 'critical', 'paused', 'complete') NOT NULL DEFAULT 'active',
	`owner_id` CHAR(36) NOT NULL,
	`start_date` DATE NOT NULL,
	`budget` DECIMAL(12,2) NULL,
	`currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
	`daily_spend` DECIMAL(12,2) NULL,
	`cpl_threshold` DECIMAL(12,2) NULL,
	`pinned` TINYINT(1) NOT NULL DEFAULT 0,
	`retrospective_ready` TINYINT(1) NOT NULL DEFAULT 0,
	`share_token` CHAR(36) NULL,
	`share_link_active` TINYINT(1) NOT NULL DEFAULT 0,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`completed_at` DATETIME(3) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `chat_messages` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`user_id` CHAR(36) NULL,
	`role` ENUM('user', 'assistant') NOT NULL,
	`content` TEXT NOT NULL,
	`workload_level` ENUM('quick', 'standard', 'deep', 'strategic') NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `cross_tool_sla_breaches` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`lead_id` CHAR(36) NOT NULL,
	`integration_id` CHAR(36) NOT NULL,
	`rule_id` CHAR(36) NOT NULL,
	`breach_type` VARCHAR(100) NOT NULL,
	`hours_exceeded` INT NOT NULL,
	`resolved` TINYINT(1) NOT NULL DEFAULT 0,
	`detected_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`resolved_at` DATETIME(3) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `cross_tool_sla_rules` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`integration_id` CHAR(36) NOT NULL,
	`rule_type` VARCHAR(100) NOT NULL,
	`threshold_value` INT NOT NULL,
	`threshold_unit` ENUM('hours', 'days', 'percent', 'currency') NOT NULL,
	`enabled` TINYINT(1) NOT NULL DEFAULT 1,
	`notification_channels` JSON NOT NULL DEFAULT (_utf8mb4'["ai_panel"]'),
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `deployments` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`campaign_id` CHAR(36) NULL,
	`name` VARCHAR(255) NOT NULL,
	`subdomain` VARCHAR(100) NOT NULL,
	`storage_path` VARCHAR(500) NOT NULL,
	`previous_storage_path` VARCHAR(500) NULL,
	`previous_deployed_at` DATETIME(3) NULL,
	`entry_file` VARCHAR(255) NOT NULL DEFAULT 'index.html',
	`serving_root` VARCHAR(255) NOT NULL DEFAULT '',
	`status` ENUM('processing', 'ready', 'failed', 'deleted') NOT NULL DEFAULT 'processing',
	`storage_size_bytes` BIGINT NOT NULL DEFAULT 0,
	`webhook_secret_encrypted` TEXT NOT NULL,
	`early_warning_active` TINYINT(1) NOT NULL DEFAULT 0,
	`early_warning_triggered` TINYINT(1) NOT NULL DEFAULT 0,
	`early_warning_triggered_at` DATETIME(3) NULL,
	`deployed_at` DATETIME(3) NULL,
	`serving_state` ENUM('active', 'paused', 'archived') NOT NULL DEFAULT 'active',
	`vip` TINYINT(1) NOT NULL DEFAULT 0,
	`failure_reason` TEXT NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `domains` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`deployment_id` CHAR(36) NOT NULL,
	`domain_name` VARCHAR(255) NOT NULL,
	`verification_token` VARCHAR(255) NOT NULL,
	`status` ENUM('pending', 'verified') NOT NULL DEFAULT 'pending',
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `hindsight_retain_log` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`bank_id` VARCHAR(255) NOT NULL,
	`event_type` VARCHAR(100) NOT NULL,
	`entity_id` CHAR(36) NULL,
	`campaign_id` CHAR(36) NULL,
	`content_summary` VARCHAR(500) NOT NULL,
	`retained_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `inbound_webhooks` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`source_label` VARCHAR(255) NOT NULL,
	`url` VARCHAR(500) NOT NULL,
	`secret_encrypted` TEXT NOT NULL,
	`last_received_at` DATETIME(3) NULL,
	`status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
	`campaign_id` CHAR(36) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `insights` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`campaign_id` CHAR(36) NULL,
	`type` ENUM('priority_flag', 'alert', 'win', 'observation', 'insufficient_evidence') NOT NULL,
	`severity` ENUM('red', 'amber', 'blue', 'green') NOT NULL,
	`observation` TEXT NOT NULL,
	`evidence` TEXT NULL,
	`campaign_tag` VARCHAR(100) NULL,
	`reassess_condition` TEXT NULL,
	`reassess_at` DATETIME(3) NULL,
	`generated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`dismissed_at` DATETIME(3) NULL,
	`category` VARCHAR(30) NULL,
	`dedupe_key` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `integrations` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`provider` ENUM('gohighlevel', 'twenty_crm', 'hubspot', 'salesforce', 'activecampaign', 'mailchimp', 'brevo', 'notifuse', 'meta_ads', 'google_ads', 'slack', 'zapier', 'make', 'tally', 'typeform', 'umami', 'instantly', 'apollo', 'lemlist', 'smartlead', 'systeme_io', 'custom') NOT NULL,
	`connection_method` ENUM('api_key', 'oauth', 'webhook') NULL,
	`api_key_encrypted` TEXT NULL,
	`oauth_access_token_encrypted` TEXT NULL,
	`oauth_refresh_token_encrypted` TEXT NULL,
	`webhook_url` VARCHAR(500) NULL,
	`active_modes` JSON NOT NULL DEFAULT (_utf8mb4'[]'),
	`status` ENUM('connected', 'not_connected', 'failed', 'coming_soon') NOT NULL DEFAULT 'not_connected',
	`last_verified_at` DATETIME(3) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `lead_assignment_history` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`lead_id` CHAR(36) NOT NULL,
	`tenant_id` CHAR(36) NOT NULL,
	`from_assignee_id` CHAR(36) NULL,
	`to_assignee_id` CHAR(36) NOT NULL,
	`path` ENUM('A', 'B', 'C') NOT NULL,
	`actioned_by` CHAR(36) NOT NULL,
	`actioned_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `lead_external_mappings` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`lead_id` CHAR(36) NOT NULL,
	`tenant_id` CHAR(36) NOT NULL,
	`integration_id` CHAR(36) NOT NULL,
	`external_contact_id` VARCHAR(500) NOT NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `lead_lifecycle_events` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`lead_id` CHAR(36) NOT NULL,
	`tenant_id` CHAR(36) NOT NULL,
	`event_timestamp` DATETIME(3) NOT NULL,
	`event` VARCHAR(500) NOT NULL,
	`source` VARCHAR(100) NOT NULL,
	`is_milestone` TINYINT(1) NOT NULL DEFAULT 0,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `leads` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`deployment_id` CHAR(36) NULL,
	`campaign_id` CHAR(36) NULL,
	`full_name` VARCHAR(255) NOT NULL,
	`email` VARCHAR(255) NULL,
	`phone` VARCHAR(50) NULL,
	`source_system` VARCHAR(100) NOT NULL,
	`source_identifier` VARCHAR(255) NULL,
	`vip` TINYINT(1) NOT NULL DEFAULT 0,
	`utm_source` VARCHAR(255) NULL,
	`utm_medium` VARCHAR(255) NULL,
	`utm_campaign` VARCHAR(255) NULL,
	`utm_content` VARCHAR(255) NULL,
	`utm_term` VARCHAR(255) NULL,
	`received_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`status` ENUM('not_responded', 'responded') NOT NULL DEFAULT 'not_responded',
	`assignment_path` ENUM('A', 'B', 'C') NULL,
	`assignee_id` CHAR(36) NULL,
	`assigned_at` DATETIME(3) NULL,
	`claimed_at` DATETIME(3) NULL,
	`reassigned_at` DATETIME(3) NULL,
	`reassigned_by` CHAR(36) NULL,
	`responded_at` DATETIME(3) NULL,
	`responded_by` CHAR(36) NULL,
	`has_external_lifecycle_events` TINYINT(1) NOT NULL DEFAULT 0,
	`sla_job_id` VARCHAR(255) NULL,
	`sla_alert_sent_at` DATETIME(3) NULL,
	`deployment_deleted` TINYINT(1) NOT NULL DEFAULT 0,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `notes` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`note_type` ENUM('campaign', 'lead', 'meeting') NOT NULL,
	`entity_id` CHAR(36) NOT NULL,
	`author_id` CHAR(36) NOT NULL,
	`content` TEXT NOT NULL,
	`title` VARCHAR(255) NULL,
	`meeting_date` DATETIME(3) NULL,
	`via` VARCHAR(100) NULL,
	`edited_at` DATETIME(3) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `notifications` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`user_id` CHAR(36) NULL,
	`kind` VARCHAR(30) NOT NULL,
	`description` TEXT NOT NULL,
	`link` VARCHAR(500) NULL,
	`read_at` DATETIME(3) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `one_time_tokens` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`purpose` VARCHAR(30) NOT NULL,
	`token_hash` VARCHAR(128) NOT NULL,
	`user_id` CHAR(36) NULL,
	`lead_id` CHAR(36) NULL,
	`expires_at` DATETIME(3) NOT NULL,
	`used_at` DATETIME(3) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `outbound_webhooks` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`destination_url` VARCHAR(500) NOT NULL,
	`event_trigger` VARCHAR(100) NOT NULL,
	`secret_encrypted` TEXT NULL,
	`last_sent_at` DATETIME(3) NULL,
	`status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `page_visits` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`deployment_id` CHAR(36) NOT NULL,
	`day` DATE NOT NULL,
	`visits` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `sessions` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`user_id` CHAR(36) NOT NULL,
	`refresh_token_hash` VARCHAR(128) NOT NULL,
	`last_used_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`revoked_at` DATETIME(3) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `stored_files` (
	`path` VARCHAR(700) NOT NULL PRIMARY KEY,
	`content_type` VARCHAR(120) NOT NULL,
	`data_base64` TEXT NOT NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `super_admins` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`email` VARCHAR(255) NOT NULL,
	`password_hash` VARCHAR(500) NOT NULL,
	`totp_secret_encrypted` TEXT NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `team_note_recipients` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`team_note_id` CHAR(36) NOT NULL,
	`tenant_id` CHAR(36) NOT NULL,
	`user_id` CHAR(36) NOT NULL,
	`read_at` DATETIME(3) NULL,
	`acknowledged_at` DATETIME(3) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `team_notes` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`author_id` CHAR(36) NOT NULL,
	`content` TEXT NOT NULL,
	`attachment_type` ENUM('lead', 'campaign') NULL,
	`attachment_id` CHAR(36) NULL,
	`time_bound_deadline` DATETIME(3) NULL,
	`time_bound_status` ENUM('pending', 'met', 'expired') NULL,
	`edited_at` DATETIME(3) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `telegram_connections` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`bot_token_encrypted` TEXT NOT NULL,
	`bot_username` VARCHAR(100) NULL,
	`verified` TINYINT(1) NOT NULL DEFAULT 0,
	`critical_alerts_enabled` TINYINT(1) NOT NULL DEFAULT 1,
	`daily_digest_enabled` TINYINT(1) NOT NULL DEFAULT 0,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `tenants` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`business_name` VARCHAR(255) NOT NULL,
	`owner_name` VARCHAR(255) NOT NULL,
	`owner_email` VARCHAR(255) NOT NULL,
	`status` ENUM('pending_activation', 'active', 'suspended', 'flagged') NOT NULL DEFAULT 'pending_activation',
	`setup_complete` TINYINT(1) NOT NULL DEFAULT 0,
	`has_marketing_stack` TINYINT(1) NULL,
	`stack_check_completed` TINYINT(1) NOT NULL DEFAULT 0,
	`plan` ENUM('starter', 'growth', 'watchtower', 'agency') NOT NULL DEFAULT 'starter',
	`monthly_fee_override` DECIMAL(10,2) NULL,
	`polar_customer_id` VARCHAR(255) NULL,
	`polar_subscription_id` VARCHAR(255) NULL,
	`polar_order_id` VARCHAR(255) NULL,
	`logo_url` VARCHAR(500) NULL,
	`sla_threshold_minutes` INT NOT NULL DEFAULT 240,
	`vip_sla_threshold_minutes` INT NOT NULL DEFAULT 60,
	`vip_lead_enabled` TINYINT(1) NOT NULL DEFAULT 0,
	`daily_summary_time` TIME NOT NULL DEFAULT '08:00:00',
	`notification_email` VARCHAR(255) NOT NULL,
	`urgent_alerts_enabled` TINYINT(1) NOT NULL DEFAULT 1,
	`daily_summary_enabled` TINYINT(1) NOT NULL DEFAULT 1,
	`storage_quota_bytes` BIGINT NOT NULL DEFAULT 5368709120,
	`storage_used_bytes` BIGINT NOT NULL DEFAULT 0,
	`team_size` VARCHAR(20) NULL,
	`notification_prefs` JSON NOT NULL DEFAULT (_utf8mb4'{"slaBreach":true,"slaChannel":"both","earlyWarning":true,"budget":true,"webhookOffline":true}'),
	`suspended_at` DATETIME(3) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
	`activated_at` DATETIME(3) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `users` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`email` VARCHAR(255) NOT NULL,
	`password_hash` VARCHAR(500) NULL,
	`name` VARCHAR(255) NOT NULL,
	`avatar_url` VARCHAR(500) NULL,
	`role` ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'member',
	`theme` ENUM('dark', 'light') NOT NULL DEFAULT 'dark',
	`invited_by` CHAR(36) NULL,
	`invitation_token` VARCHAR(500) NULL,
	`invitation_expires_at` DATETIME(3) NULL,
	`joined_at` DATETIME(3) NULL,
	`last_active_at` DATETIME(3) NULL,
	`telegram_chat_id` VARCHAR(100) NULL,
	`notify_enabled` TINYINT(1) NOT NULL DEFAULT 1,
	`notify_channel` VARCHAR(20) NOT NULL DEFAULT 'email',
	`removed_at` DATETIME(3) NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `webhook_sources` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`deployment_id` CHAR(36) NOT NULL,
	`campaign_id` CHAR(36) NULL,
	`label` VARCHAR(255) NOT NULL,
	`source_system` VARCHAR(100) NOT NULL,
	`last_received_at` DATETIME(3) NULL,
	`status` ENUM('operational', 'stale', 'offline') NOT NULL DEFAULT 'operational',
	`stale_threshold_minutes` INT NOT NULL DEFAULT 480,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
CREATE TABLE `workspace_logs` (
	`id` CHAR(36) NOT NULL DEFAULT (uuid()) PRIMARY KEY,
	`tenant_id` CHAR(36) NOT NULL,
	`actor_id` CHAR(36) NULL,
	`description` TEXT NOT NULL,
	`created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
--> statement-breakpoint
ALTER TABLE `agent_worker_logs` ADD CONSTRAINT `agent_worker_logs_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `ai_provider_configs` ADD CONSTRAINT `ai_provider_configs_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `ai_workload_logs` ADD CONSTRAINT `ai_workload_logs_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaign_changes` ADD CONSTRAINT `campaign_changes_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaign_changes` ADD CONSTRAINT `campaign_changes_campaign_id_campaigns_id_fk` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaign_changes` ADD CONSTRAINT `campaign_changes_changed_by_users_id_fk` FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `campaign_logs` ADD CONSTRAINT `campaign_logs_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaign_logs` ADD CONSTRAINT `campaign_logs_campaign_id_campaigns_id_fk` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaign_logs` ADD CONSTRAINT `campaign_logs_actor_id_users_id_fk` FOREIGN KEY (`actor_id`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `campaign_members` ADD CONSTRAINT `campaign_members_campaign_id_campaigns_id_fk` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaign_members` ADD CONSTRAINT `campaign_members_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaign_members` ADD CONSTRAINT `campaign_members_user_id_users_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `campaign_recommendations` ADD CONSTRAINT `campaign_recommendations_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaign_recommendations` ADD CONSTRAINT `campaign_recommendations_campaign_id_campaigns_id_fk` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaign_retrospectives` ADD CONSTRAINT `campaign_retrospectives_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaign_retrospectives` ADD CONSTRAINT `campaign_retrospectives_campaign_id_campaigns_id_fk` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaigns` ADD CONSTRAINT `campaigns_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `campaigns` ADD CONSTRAINT `campaigns_owner_id_users_id_fk` FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `chat_messages` ADD CONSTRAINT `chat_messages_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `chat_messages` ADD CONSTRAINT `chat_messages_user_id_users_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `cross_tool_sla_breaches` ADD CONSTRAINT `cross_tool_sla_breaches_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `cross_tool_sla_breaches` ADD CONSTRAINT `cross_tool_sla_breaches_lead_id_leads_id_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `cross_tool_sla_breaches` ADD CONSTRAINT `cross_tool_sla_breaches_integration_id_integrations_id_fk` FOREIGN KEY (`integration_id`) REFERENCES `integrations`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `cross_tool_sla_breaches` ADD CONSTRAINT `cross_tool_sla_breaches_rule_id_cross_tool_sla_rules_id_fk` FOREIGN KEY (`rule_id`) REFERENCES `cross_tool_sla_rules`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `cross_tool_sla_rules` ADD CONSTRAINT `cross_tool_sla_rules_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `cross_tool_sla_rules` ADD CONSTRAINT `cross_tool_sla_rules_integration_id_integrations_id_fk` FOREIGN KEY (`integration_id`) REFERENCES `integrations`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `deployments` ADD CONSTRAINT `deployments_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `deployments` ADD CONSTRAINT `deployments_campaign_id_campaigns_id_fk` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE SET NULL;
--> statement-breakpoint
ALTER TABLE `domains` ADD CONSTRAINT `domains_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `domains` ADD CONSTRAINT `domains_deployment_id_deployments_id_fk` FOREIGN KEY (`deployment_id`) REFERENCES `deployments`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `hindsight_retain_log` ADD CONSTRAINT `hindsight_retain_log_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `inbound_webhooks` ADD CONSTRAINT `inbound_webhooks_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `inbound_webhooks` ADD CONSTRAINT `inbound_webhooks_campaign_id_campaigns_id_fk` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE SET NULL;
--> statement-breakpoint
ALTER TABLE `insights` ADD CONSTRAINT `insights_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `insights` ADD CONSTRAINT `insights_campaign_id_campaigns_id_fk` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `integrations` ADD CONSTRAINT `integrations_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `lead_assignment_history` ADD CONSTRAINT `lead_assignment_history_lead_id_leads_id_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `lead_assignment_history` ADD CONSTRAINT `lead_assignment_history_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `lead_assignment_history` ADD CONSTRAINT `lead_assignment_history_from_assignee_id_users_id_fk` FOREIGN KEY (`from_assignee_id`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `lead_assignment_history` ADD CONSTRAINT `lead_assignment_history_to_assignee_id_users_id_fk` FOREIGN KEY (`to_assignee_id`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `lead_assignment_history` ADD CONSTRAINT `lead_assignment_history_actioned_by_users_id_fk` FOREIGN KEY (`actioned_by`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `lead_external_mappings` ADD CONSTRAINT `lead_external_mappings_lead_id_leads_id_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `lead_external_mappings` ADD CONSTRAINT `lead_external_mappings_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `lead_external_mappings` ADD CONSTRAINT `lead_external_mappings_integration_id_integrations_id_fk` FOREIGN KEY (`integration_id`) REFERENCES `integrations`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `lead_lifecycle_events` ADD CONSTRAINT `lead_lifecycle_events_lead_id_leads_id_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `lead_lifecycle_events` ADD CONSTRAINT `lead_lifecycle_events_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `leads` ADD CONSTRAINT `leads_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `leads` ADD CONSTRAINT `leads_deployment_id_deployments_id_fk` FOREIGN KEY (`deployment_id`) REFERENCES `deployments`(`id`) ON DELETE SET NULL;
--> statement-breakpoint
ALTER TABLE `leads` ADD CONSTRAINT `leads_campaign_id_campaigns_id_fk` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE SET NULL;
--> statement-breakpoint
ALTER TABLE `leads` ADD CONSTRAINT `leads_assignee_id_users_id_fk` FOREIGN KEY (`assignee_id`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `leads` ADD CONSTRAINT `leads_reassigned_by_users_id_fk` FOREIGN KEY (`reassigned_by`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `leads` ADD CONSTRAINT `leads_responded_by_users_id_fk` FOREIGN KEY (`responded_by`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `notes` ADD CONSTRAINT `notes_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `notes` ADD CONSTRAINT `notes_author_id_users_id_fk` FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `notifications` ADD CONSTRAINT `notifications_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `notifications` ADD CONSTRAINT `notifications_user_id_users_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `one_time_tokens` ADD CONSTRAINT `one_time_tokens_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `one_time_tokens` ADD CONSTRAINT `one_time_tokens_user_id_users_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `one_time_tokens` ADD CONSTRAINT `one_time_tokens_lead_id_leads_id_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `outbound_webhooks` ADD CONSTRAINT `outbound_webhooks_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `page_visits` ADD CONSTRAINT `page_visits_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `page_visits` ADD CONSTRAINT `page_visits_deployment_id_deployments_id_fk` FOREIGN KEY (`deployment_id`) REFERENCES `deployments`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `sessions` ADD CONSTRAINT `sessions_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `sessions` ADD CONSTRAINT `sessions_user_id_users_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `team_note_recipients` ADD CONSTRAINT `team_note_recipients_team_note_id_team_notes_id_fk` FOREIGN KEY (`team_note_id`) REFERENCES `team_notes`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `team_note_recipients` ADD CONSTRAINT `team_note_recipients_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `team_note_recipients` ADD CONSTRAINT `team_note_recipients_user_id_users_id_fk` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `team_notes` ADD CONSTRAINT `team_notes_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `team_notes` ADD CONSTRAINT `team_notes_author_id_users_id_fk` FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE NO ACTION;
--> statement-breakpoint
ALTER TABLE `telegram_connections` ADD CONSTRAINT `telegram_connections_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `users` ADD CONSTRAINT `users_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `webhook_sources` ADD CONSTRAINT `webhook_sources_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `webhook_sources` ADD CONSTRAINT `webhook_sources_deployment_id_deployments_id_fk` FOREIGN KEY (`deployment_id`) REFERENCES `deployments`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
ALTER TABLE `webhook_sources` ADD CONSTRAINT `webhook_sources_campaign_id_campaigns_id_fk` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns`(`id`) ON DELETE SET NULL;
--> statement-breakpoint
ALTER TABLE `workspace_logs` ADD CONSTRAINT `workspace_logs_tenant_id_tenants_id_fk` FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE;
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_ai_provider_tenant` ON `ai_provider_configs` (`tenant_id`);
--> statement-breakpoint
CREATE INDEX `idx_workload_tenant_time` ON `ai_workload_logs` (`tenant_id`,`created_at`);
--> statement-breakpoint
CREATE INDEX `idx_campaign_logs_campaign` ON `campaign_logs` (`campaign_id`,`created_at`);
--> statement-breakpoint
CREATE INDEX `idx_recommendations_campaign` ON `campaign_recommendations` (`campaign_id`);
--> statement-breakpoint
CREATE INDEX `idx_recommendations_status` ON `campaign_recommendations` (`tenant_id`,`status`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_retro_campaign` ON `campaign_retrospectives` (`campaign_id`);
--> statement-breakpoint
CREATE INDEX `idx_campaigns_tenant` ON `campaigns` (`tenant_id`);
--> statement-breakpoint
CREATE INDEX `idx_campaigns_tenant_status` ON `campaigns` (`tenant_id`,`status`);
--> statement-breakpoint
CREATE INDEX `idx_chat_tenant` ON `chat_messages` (`tenant_id`,`created_at`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_deployments_subdomain` ON `deployments` (`subdomain`);
--> statement-breakpoint
CREATE INDEX `idx_deployments_tenant` ON `deployments` (`tenant_id`);
--> statement-breakpoint
CREATE INDEX `idx_deployments_campaign` ON `deployments` (`campaign_id`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_domains_name` ON `domains` (`domain_name`);
--> statement-breakpoint
CREATE INDEX `idx_insights_tenant` ON `insights` (`tenant_id`);
--> statement-breakpoint
CREATE INDEX `idx_insights_campaign` ON `insights` (`campaign_id`);
--> statement-breakpoint
CREATE INDEX `idx_insights_tenant_generated` ON `insights` (`tenant_id`,`generated_at`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_integrations_tenant_provider` ON `integrations` (`tenant_id`,`provider`);
--> statement-breakpoint
CREATE INDEX `idx_lifecycle_lead` ON `lead_lifecycle_events` (`lead_id`);
--> statement-breakpoint
CREATE INDEX `idx_lifecycle_tenant` ON `lead_lifecycle_events` (`tenant_id`);
--> statement-breakpoint
CREATE INDEX `idx_leads_tenant` ON `leads` (`tenant_id`);
--> statement-breakpoint
CREATE INDEX `idx_leads_campaign` ON `leads` (`campaign_id`);
--> statement-breakpoint
CREATE INDEX `idx_leads_deployment` ON `leads` (`deployment_id`);
--> statement-breakpoint
CREATE INDEX `idx_leads_status` ON `leads` (`tenant_id`,`status`);
--> statement-breakpoint
CREATE INDEX `idx_leads_assignee` ON `leads` (`assignee_id`);
--> statement-breakpoint
CREATE INDEX `idx_leads_received_at` ON `leads` (`tenant_id`,`received_at`);
--> statement-breakpoint
CREATE INDEX `idx_notes_entity` ON `notes` (`entity_id`,`note_type`);
--> statement-breakpoint
CREATE INDEX `idx_notes_tenant` ON `notes` (`tenant_id`);
--> statement-breakpoint
CREATE INDEX `idx_notifications_tenant` ON `notifications` (`tenant_id`,`created_at`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_ott_hash` ON `one_time_tokens` (`token_hash`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_page_visits_day` ON `page_visits` (`deployment_id`,`day`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_sessions_token` ON `sessions` (`refresh_token_hash`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_super_admins_email` ON `super_admins` (`email`);
--> statement-breakpoint
CREATE INDEX `idx_tnr_note` ON `team_note_recipients` (`team_note_id`);
--> statement-breakpoint
CREATE INDEX `idx_tnr_user` ON `team_note_recipients` (`user_id`,`tenant_id`);
--> statement-breakpoint
CREATE INDEX `idx_team_notes_tenant` ON `team_notes` (`tenant_id`);
--> statement-breakpoint
CREATE INDEX `idx_team_notes_attachment` ON `team_notes` (`attachment_id`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_telegram_tenant` ON `telegram_connections` (`tenant_id`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_tenants_owner_email` ON `tenants` (`owner_email`);
--> statement-breakpoint
CREATE INDEX `idx_users_tenant` ON `users` (`tenant_id`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_users_email_tenant` ON `users` (`email`,`tenant_id`);
--> statement-breakpoint
CREATE UNIQUE INDEX `idx_webhook_sources_tenant_deployment` ON `webhook_sources` (`tenant_id`,`deployment_id`);
