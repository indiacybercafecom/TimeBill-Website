<?php
/**
 * Configuration for the TimeBill email API.
 *
 * Keep secrets in environment variables whenever possible.
 * Example (Windows PowerShell):
 *   $env:TIMEBILL_SMTP_PASSWORD = "your-password"
 *   $env:TIMEBILL_API_KEY = "your-secret-key"
 */

date_default_timezone_set('Asia/Kolkata');

return [
    'smtp' => [
        'host' => getenv('TIMEBILL_SMTP_HOST') ?: 'smtp.hostinger.com',
        'port' => (int) (getenv('TIMEBILL_SMTP_PORT') ?: 465),
        'encryption' => getenv('TIMEBILL_SMTP_ENCRYPTION') ?: 'ssl',
        'username' => getenv('TIMEBILL_SMTP_USERNAME') ?: 'support@timebill.indiacybercafe.com',
        'password' => getenv('TIMEBILL_SMTP_PASSWORD') ?: 'Ankit9977498131@@@',
        'from_name' => 'TimeBill',
        'from_email' => getenv('TIMEBILL_SMTP_USERNAME') ?: 'support@timebill.indiacybercafe.com',
    ],
    'api' => [
        'key' => getenv('TIMEBILL_API_KEY') ?: 'TB_7f9K@2xP!TimeBill#2026',
        'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', getenv('TIMEBILL_ALLOWED_ORIGINS') ?: 'https://timebill.indiacybercafe.com,https://www.timebill.indiacybercafe.com,http://localhost:3000,http://127.0.0.1:3000,android-app://timebill')))),
    ],
    'logging' => [
        'file' => getenv('TIMEBILL_LOG_FILE') ?: __DIR__ . '/../logs/send-email.log',
    ],
];
