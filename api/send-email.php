<?php
/**
 * TimeBill Email API
 *
 * Accepts invoice data, validates it, sends a professional HTML email via SMTP,
 * and returns JSON responses only.
 */

require_once __DIR__ . '/../PHPMailer/src/Exception.php';
require_once __DIR__ . '/../PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$config = require __DIR__ . '/config.php';

function jsonResponse(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function logError(string $message): void {
    global $config;
    $logFile = $config['logging']['file'] ?? __DIR__ . '/../logs/send-email.log';
    $dir = dirname($logFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

function isAllowedOrigin(string $origin, array $allowedOrigins): bool {
    foreach ($allowedOrigins as $allowedOrigin) {
        if ($allowedOrigin === '*' || $allowedOrigin === $origin) {
            return true;
        }
    }
    return false;
}

function sanitizeText(?string $value): string {
    if ($value === null) {
        return '';
    }
    $value = trim($value);
    $value = strip_tags($value);
    return preg_replace('/[\r\n\x00]/', '', $value) ?? '';
}

function sanitizeEmail(?string $value): string {
    return filter_var(sanitizeText($value), FILTER_SANITIZE_EMAIL);
}

function validateRequiredFields(array $data): array {
    $required = [
        'invoice_no', 'client_name', 'client_email', 'work_name', 'status', 'duration', 'rate', 'amount',
        'subtotal', 'tax', 'total', 'generated_date', 'generated_time', 'business_name', 'business_email',
        'business_phone', 'business_address'
    ];

    $errors = [];
    foreach ($required as $field) {
        if (!array_key_exists($field, $data) || trim((string) $data[$field]) === '') {
            $errors[] = "$field is required.";
        }
    }

    return $errors;
}

function containsHeaderInjection(string $value): bool {
    $value = (string) $value;
    return preg_match('/(?:\r|\n|%0d|%0a)/i', $value) === 1;
}

function buildHtmlEmail(array $data): string {
    $statusText = htmlspecialchars(sanitizeText($data['status'] ?? 'Pending'), ENT_QUOTES, 'UTF-8');
    $invoiceNo = htmlspecialchars(sanitizeText($data['invoice_no'] ?? ''), ENT_QUOTES, 'UTF-8');
    $clientName = htmlspecialchars(sanitizeText($data['client_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $clientEmail = htmlspecialchars(sanitizeEmail($data['client_email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $workName = htmlspecialchars(sanitizeText($data['work_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $duration = htmlspecialchars(sanitizeText($data['duration'] ?? ''), ENT_QUOTES, 'UTF-8');
    $rate = htmlspecialchars(sanitizeText($data['rate'] ?? ''), ENT_QUOTES, 'UTF-8');
    $amount = htmlspecialchars(sanitizeText($data['amount'] ?? ''), ENT_QUOTES, 'UTF-8');
    $subtotal = htmlspecialchars(sanitizeText($data['subtotal'] ?? ''), ENT_QUOTES, 'UTF-8');
    $tax = htmlspecialchars(sanitizeText($data['tax'] ?? ''), ENT_QUOTES, 'UTF-8');
    $total = htmlspecialchars(sanitizeText($data['total'] ?? ''), ENT_QUOTES, 'UTF-8');
    $generatedDate = htmlspecialchars(sanitizeText($data['generated_date'] ?? ''), ENT_QUOTES, 'UTF-8');
    $generatedTime = htmlspecialchars(sanitizeText($data['generated_time'] ?? ''), ENT_QUOTES, 'UTF-8');
    $businessName = htmlspecialchars(sanitizeText($data['business_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $businessEmail = htmlspecialchars(sanitizeEmail($data['business_email'] ?? ''), ENT_QUOTES, 'UTF-8');
    $businessPhone = htmlspecialchars(sanitizeText($data['business_phone'] ?? ''), ENT_QUOTES, 'UTF-8');
    $businessAddress = nl2br(htmlspecialchars(sanitizeText($data['business_address'] ?? ''), ENT_QUOTES, 'UTF-8'));

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Invoice from TimeBill</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f7fb;font-family:Arial,sans-serif;color:#1f2937;">
  <div style="max-width:700px;margin:24px auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.08);">
    <div style="background:linear-gradient(135deg,#2563eb,#1d4ed8);padding:28px 32px;color:#ffffff;">
      <h1 style="margin:0 0 8px;font-size:28px;">Invoice Ready</h1>
      <p style="margin:0;font-size:15px;opacity:0.95;">Professional billing delivered by TimeBill</p>
    </div>
    <div style="padding:32px;">
      <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
        <div>
          <p style="margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#6b7280;">Invoice</p>
          <h2 style="margin:0;font-size:22px;color:#111827;">{$invoiceNo}</h2>
        </div>
        <div style="text-align:right;">
          <p style="margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#6b7280;">Status</p>
          <span style="display:inline-block;padding:7px 12px;border-radius:999px;background:#dcfce7;color:#166534;font-weight:bold;font-size:13px;">{$statusText}</span>
        </div>
      </div>

      <div style="border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin-bottom:20px;background:#f9fafb;">
        <p style="margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#6b7280;">Generated</p>
        <p style="margin:0;font-size:15px;color:#111827;">{$generatedDate} at {$generatedTime}</p>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;">
        <div style="border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;background:#ffffff;">
          <p style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#6b7280;">Bill From</p>
          <p style="margin:0;font-weight:bold;color:#111827;">{$businessName}</p>
          <p style="margin:4px 0 0;color:#4b5563;">{$businessEmail}</p>
          <p style="margin:4px 0 0;color:#4b5563;">{$businessPhone}</p>
          <p style="margin:8px 0 0;color:#4b5563;">{$businessAddress}</p>
        </div>
        <div style="border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;background:#ffffff;">
          <p style="margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#6b7280;">Bill To</p>
          <p style="margin:0;font-weight:bold;color:#111827;">{$clientName}</p>
          <p style="margin:4px 0 0;color:#4b5563;">{$clientEmail}</p>
        </div>
      </div>

      <div style="border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;margin-bottom:20px;">
        <div style="background:#f9fafb;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
          <h3 style="margin:0;font-size:16px;color:#111827;">Work Details</h3>
        </div>
        <div style="padding:18px;">
          <p style="margin:0 0 10px;"><strong>Work Name:</strong> {$workName}</p>
          <p style="margin:0 0 10px;"><strong>Duration:</strong> {$duration}</p>
          <p style="margin:0 0 10px;"><strong>Rate:</strong> {$rate}</p>
          <p style="margin:0;"><strong>Amount:</strong> {$amount}</p>
        </div>
      </div>

      <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="border-collapse:collapse;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
        <tbody>
          <tr style="background:#f9fafb;">
            <td style="padding:12px 16px;border-bottom:1px solid #e5e7eb;font-weight:bold;color:#111827;">Item</td>
            <td style="padding:12px 16px;border-bottom:1px solid #e5e7eb;text-align:right;font-weight:bold;color:#111827;">Value</td>
          </tr>
          <tr>
            <td style="padding:12px 16px;border-bottom:1px solid #e5e7eb;color:#4b5563;">Subtotal</td>
            <td style="padding:12px 16px;border-bottom:1px solid #e5e7eb;text-align:right;color:#111827;">{$subtotal}</td>
          </tr>
          <tr>
            <td style="padding:12px 16px;border-bottom:1px solid #e5e7eb;color:#4b5563;">Tax</td>
            <td style="padding:12px 16px;border-bottom:1px solid #e5e7eb;text-align:right;color:#111827;">{$tax}</td>
          </tr>
          <tr style="background:#eff6ff;">
            <td style="padding:12px 16px;font-weight:bold;color:#111827;">Total</td>
            <td style="padding:12px 16px;text-align:right;font-weight:bold;color:#2563eb;">{$total}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div style="background:#f8fafc;padding:20px 32px;border-top:1px solid #e5e7eb;text-align:center;color:#6b7280;font-size:13px;line-height:1.6;">
      <p style="margin:0 0 6px;">Generated by TimeBill</p>
      <a href="https://timebill.indiacybercafe.com" style="color:#2563eb;text-decoration:none;">https://timebill.indiacybercafe.com</a>
    </div>
  </div>
</body>
</html>
HTML;
}

header('Vary: Origin');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $allowedOrigins = $config['api']['allowed_origins'] ?? [];
    if (isAllowedOrigin($origin, $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    jsonResponse(200, ['success' => true, 'message' => 'Preflight OK.']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['success' => false, 'message' => 'Only POST requests are allowed.']);
}

if (!isset($_SERVER['HTTP_X_API_KEY']) || trim((string) $_SERVER['HTTP_X_API_KEY']) === '') {
    jsonResponse(401, ['success' => false, 'message' => 'Missing API key.']);
}

$apiKey = trim((string) $_SERVER['HTTP_X_API_KEY']);
$expectedApiKey = (string) ($config['api']['key'] ?? '');
if ($expectedApiKey === '' || !hash_equals($expectedApiKey, $apiKey)) {
    logError('Rejected request with invalid API key.');
    jsonResponse(403, ['success' => false, 'message' => 'Invalid API key.']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if ($contentType === '' || stripos($contentType, 'application/json') === false) {
    jsonResponse(415, ['success' => false, 'message' => 'Content-Type must be application/json.']);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    jsonResponse(400, ['success' => false, 'message' => 'Request body is required.']);
}

$decoded = json_decode($rawBody, true);
if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
    jsonResponse(400, ['success' => false, 'message' => 'Request body must be valid JSON.']);
}

$errors = validateRequiredFields($decoded);
if ($errors) {
    jsonResponse(422, ['success' => false, 'message' => 'Validation failed.', 'errors' => $errors]);
}

foreach ($decoded as $key => $value) {
    if (is_string($value)) {
        if (containsHeaderInjection($value)) {
            jsonResponse(400, ['success' => false, 'message' => 'Invalid input detected.']);
        }
    }
}

$requiredFields = [
    'invoice_no', 'client_name', 'client_email', 'work_name', 'status', 'duration', 'rate', 'amount',
    'subtotal', 'tax', 'total', 'generated_date', 'generated_time', 'business_name', 'business_email',
    'business_phone', 'business_address'
];

$data = [];
foreach ($requiredFields as $field) {
    $data[$field] = sanitizeText((string) ($decoded[$field] ?? ''));
}

$data['client_email'] = sanitizeEmail((string) ($decoded['client_email'] ?? ''));
$data['business_email'] = sanitizeEmail((string) ($decoded['business_email'] ?? ''));

if (!filter_var($data['client_email'], FILTER_VALIDATE_EMAIL)) {
    jsonResponse(422, ['success' => false, 'message' => 'Client email is invalid.']);
}

if (!filter_var($data['business_email'], FILTER_VALIDATE_EMAIL)) {
    jsonResponse(422, ['success' => false, 'message' => 'Business email is invalid.']);
}

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $config['smtp']['host'];
    $mail->Port = $config['smtp']['port'];
    $mail->SMTPSecure = $config['smtp']['encryption'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['smtp']['username'];
    $mail->Password = $config['smtp']['password'];
    $mail->setFrom($config['smtp']['from_email'], $config['smtp']['from_name']);
    $mail->addAddress($data['client_email'], $data['client_name']);
    $mail->addReplyTo($config['smtp']['from_email'], $config['smtp']['from_name']);
    $mail->Subject = 'Invoice #' . $data['invoice_no'] . ' from TimeBill';
    $mail->isHTML(true);
    $mail->Body = buildHtmlEmail($data);
    $mail->AltBody = "Invoice {$data['invoice_no']} from TimeBill\n\nGenerated: {$data['generated_date']} at {$data['generated_time']}\nStatus: {$data['status']}\nTotal: {$data['total']}";

    $mail->send();
    jsonResponse(200, ['success' => true, 'message' => 'Email sent successfully.']);
} catch (Exception $e) {
    logError('SMTP send failed: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Unable to send email at this time.']);
}
