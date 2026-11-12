<?php

/*
 * Camplo tunables. Every value comes from the environment with the same names the Node version used, so an
 * existing deployment's variables work unchanged.
 */

$env = static function (string ...$names): string {
    foreach ($names as $n) {
        $v = env($n);
        if ($v !== null && $v !== '') {
            return (string) $v;
        }
    }

    return '';
};
$num = static fn (?string $v, float|int $d) => ($v !== null && $v !== '' && is_numeric($v)) ? $v + 0 : $d;

return [
    'env' => env('APP_ENV', 'production'),
    'app_url' => rtrim((string) (env('APP_URL') ?: env('RENDER_EXTERNAL_URL') ?: 'http://localhost:8000'), '/'),
    'seed_demo' => filter_var(env('SEED_DEMO', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,

    'jwt_secret' => (string) env('JWT_SECRET', 'dev-only-insecure-jwt-secret-change-me'),
    'access_token_ttl' => (int) $num(env('ACCESS_TOKEN_TTL_SECONDS'), 15 * 60),
    'session_inactivity_days' => (int) $num(env('SESSION_INACTIVITY_DAYS'), 7),
    'encryption_key' => (string) env('ENCRYPTION_KEY', ''),
    'encryption_keys' => [
        'ai' => $env('AI_KEY_ENCRYPTION_SECRET'),
        'webhook' => $env('WEBHOOK_SECRET_ENCRYPTION_KEY'),
        'integration' => $env('INTEGRATION_KEY_ENCRYPTION_SECRET'),
        'telegram' => $env('TELEGRAM_TOKEN_ENCRYPTION_SECRET'),
    ],

    'magic_link_signing_secret' => $env('MAGIC_LINK_SIGNING_SECRET'),
    'magic_link_expiry' => (int) $num(env('MAGIC_LINK_EXPIRY_SECONDS'), 900),
    'ack_link_expiry' => (int) $num(env('ACKNOWLEDGMENT_LINK_EXPIRY_SECONDS'), 259200),
    'password_reset_expiry' => (int) $num(env('PASSWORD_RESET_EXPIRY_SECONDS'), 3600),
    'invitation_expiry_days' => (int) $num(env('INVITATION_EXPIRY_DAYS'), 7),

    'max_zip_size_mb' => (int) $num(env('MAX_ZIP_SIZE_MB'), 100),
    'pages_base_domain' => $env('BASE_DOMAIN', 'PAGES_BASE_DOMAIN') ?: 'camplo.app',
    'default_storage_quota_bytes' => (int) ($num(env('DEFAULT_STORAGE_QUOTA_GB'), 5) * 1024 ** 3),
    'admin_url' => $env('ADMIN_URL'),
    'support_email' => (string) env('SUPPORT_EMAIL', 'support@camplo.app'),

    'openrouter_api_key' => (string) env('OPENROUTER_API_KEY', ''),
    'openrouter_base_url' => (string) env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
    'models' => [
        'quick' => (string) env('MODEL_QUICK', 'meta-llama/llama-3.3-70b-instruct'),
        'standard' => (string) env('MODEL_STANDARD', 'anthropic/claude-sonnet-5'),
        'deep' => (string) env('MODEL_DEEP', 'anthropic/claude-sonnet-5'),
        'strategic' => (string) env('MODEL_STRATEGIC', 'anthropic/claude-opus-5-5'),
    ],
    'ai_cache_ttl' => (int) $num(env('AI_CACHE_TTL_SECONDS'), 30 * 60),
    'manual_refresh_debounce_minutes' => (int) $num(env('MANUAL_REFRESH_DEBOUNCE_MINUTES'), 15),
    'default_ai_provider' => $env('CAMPLO_DEFAULT_AI_PROVIDER') ?: 'openrouter',

    'hindsight_api_url' => (string) env('HINDSIGHT_API_URL', ''),
    'hindsight_api_key' => (string) env('HINDSIGHT_API_KEY', ''),
    'sentry_dsn' => (string) env('SENTRY_DSN', ''),

    'worker_max_depth' => (int) $num(env('WORKER_MAX_DEPTH'), 2),
    'worker_max_concurrent' => (int) $num(env('WORKER_MAX_CONCURRENT'), 4),
    'worker_default_timeout' => (int) $num(env('WORKER_DEFAULT_TIMEOUT_SECONDS'), 300),
    'worker_max_tool_calls' => (int) $num(env('WORKER_MAX_TOOL_CALLS'), 20),

    'cron_secret' => (string) env('CRON_SECRET', ''),

    'smtp' => ['host' => $env('SMTP_HOST'), 'port' => (int) $num(env('SMTP_PORT'), 587), 'user' => $env('SMTP_USER'), 'pass' => $env('SMTP_PASS')],
    'resend_api_key' => $env('RESEND_API_KEY'),
    'email_from' => $env('SMTP_FROM', 'EMAIL_FROM') ?: 'Camplo <no-reply@camplo.app>',

    'polar_webhook_secret' => $env('POLAR_WEBHOOK_SECRET'),
    'polar_access_token' => $env('POLAR_ACCESS_TOKEN'),
    'polar_organization_id' => $env('POLAR_ORGANIZATION_ID'),
    'polar_api_url' => $env('POLAR_API_URL') ?: 'https://api.polar.sh',
    'polar_product_ids' => [
        'starter' => $env('POLAR_PRODUCT_ID_STARTER', 'POLAR_PRODUCT_ID'),
        'growth' => $env('POLAR_PRODUCT_ID_GROWTH', 'POLAR_PRODUCT_ID'),
        'watchtower' => $env('POLAR_PRODUCT_ID_WATCHTOWER', 'POLAR_PRODUCT_ID'),
        'agency' => $env('POLAR_PRODUCT_ID_AGENCY', 'POLAR_PRODUCT_ID'),
    ],
    'polar_checkout_urls' => [
        'starter' => $env('POLAR_CHECKOUT_URL_STARTER'),
        'growth' => $env('POLAR_CHECKOUT_URL_GROWTH'),
        'watchtower' => $env('POLAR_CHECKOUT_URL_WATCHTOWER'),
        'agency' => $env('POLAR_CHECKOUT_URL_AGENCY'),
    ],
    'allow_direct_plan_change' => filter_var(env('ALLOW_DIRECT_PLAN_CHANGE', false), FILTER_VALIDATE_BOOL),
    'auto_activate' => filter_var(env('AUTO_ACTIVATE', false), FILTER_VALIDATE_BOOL),

    'telegram_bot_token' => $env('TELEGRAM_BOT_TOKEN'),
    'telegram_webhook_secret' => $env('TELEGRAM_WEBHOOK_SECRET'),

    'storage_provider' => $env('STORAGE_PROVIDER') ?: 'r2',
    's3' => [
        'endpoint' => $env('STORAGE_ENDPOINT', 'S3_ENDPOINT'),
        'bucket' => $env('STORAGE_BUCKET', 'S3_BUCKET'),
        'key' => $env('STORAGE_ACCESS_KEY', 'AWS_ACCESS_KEY_ID'),
        'secret' => $env('STORAGE_SECRET_KEY', 'AWS_SECRET_ACCESS_KEY'),
        'region' => $env('STORAGE_REGION', 'AWS_REGION') ?: 'auto',
    ],

    'super_admin_email' => (string) env('SUPER_ADMIN_EMAIL', ''),
    'super_admin_password' => (string) env('SUPER_ADMIN_PASSWORD', ''),
    'admin_totp_secret' => $env('ADMIN_TOTP_SECRET', 'SUPER_ADMIN_TOTP_SECRET'),

    'rollback_retention_days' => (int) $num(env('ROLLBACK_RETENTION_DAYS'), 30),
    'early_warning_hours' => (int) $num(env('EARLY_WARNING_HOURS'), 72),
    'note_edit_window_seconds' => (int) $num(env('NOTE_EDIT_WINDOW_SECONDS'), 7200),
    'ai_panel_timeout_seconds' => (int) $num(env('AI_PANEL_TIMEOUT_SECONDS'), 10),
];
