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

function buildAttachmentFilename(string $invoiceNo): string {
    $safeInvoice = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($invoiceNo)) ?: 'invoice';
    return 'Invoice-' . $safeInvoice . '.pdf';
}

function detectPdfMime(string $filePath): ?string {
    $header = @file_get_contents($filePath, false, null, 0, 5);
    if ($header !== false && strpos($header, '%PDF') === 0) {
        return 'application/pdf';
    }

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if ($mime !== false) {
                return $mime;
            }
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($filePath);
        if ($mime !== false) {
            return $mime;
        }
    }

    return null;
}

function parseIncomingRequest(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isJson = stripos($contentType, 'application/json') !== false;
    $isMultipart = stripos($contentType, 'multipart/form-data') !== false;

    if ($isJson) {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            jsonResponse(400, ['success' => false, 'message' => 'Request body is required.']);
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            jsonResponse(400, ['success' => false, 'message' => 'Request body must be valid JSON.']);
        }

        return ['type' => 'json', 'data' => $decoded, 'attachment' => null];
    }

    if ($isMultipart) {
        $data = [];
        foreach ($_POST as $key => $value) {
            $data[$key] = is_string($value) ? sanitizeText($value) : $value;
        }

        if (!isset($_FILES['invoice_pdf']) || !is_array($_FILES['invoice_pdf'])) {
            jsonResponse(422, ['success' => false, 'message' => 'invoice_pdf is required for multipart/form-data requests.']);
        }

        $file = $_FILES['invoice_pdf'];
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            jsonResponse(422, ['success' => false, 'message' => 'invoice_pdf is required for multipart/form-data requests.']);
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(422, ['success' => false, 'message' => 'The uploaded file has an error.']);
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            jsonResponse(422, ['success' => false, 'message' => 'Invalid uploaded file.']);
        }

        $maxSize = 10 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            jsonResponse(422, ['success' => false, 'message' => 'The uploaded PDF must be 10 MB or smaller.']);
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($extension !== 'pdf') {
            jsonResponse(422, ['success' => false, 'message' => 'Only PDF files are allowed.']);
        }

        $mime = detectPdfMime($file['tmp_name']);
        if ($mime !== 'application/pdf') {
            jsonResponse(422, ['success' => false, 'message' => 'Only PDF files are allowed.']);
        }

        return ['type' => 'multipart', 'data' => $data, 'attachment' => [
            'tmp_name' => $file['tmp_name'],
            'name' => $file['name'],
        ]];
    }

    jsonResponse(415, ['success' => false, 'message' => 'Content-Type must be application/json or multipart/form-data.']);
}

function buildHtmlEmail(array $data, bool $hasAttachment = false): string {
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
    $attachmentNote = $hasAttachment
        ? '<p style="margin:8px 0 0;font-size:14px;line-height:1.6;color:#e0f2fe;">The invoice PDF is attached to this email for your records.</p>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Invoice from TimeBill</title>
</head>
<body style="margin:0;padding:24px 12px;background-color:#f4f7fb;font-family:Arial, Helvetica, sans-serif;color:#14213d;">
  <div style="max-width:680px;margin:0 auto;">
    <div style="background:linear-gradient(135deg,#143b8a 0%,#2563eb 100%);border-radius:24px 24px 0 0;padding:32px 32px 24px;color:#ffffff;">
      <div style="font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#dbeafe;margin-bottom:8px;">TimeBill Invoice</div>
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td valign="top">
            <h1 style="margin:0 0 8px;font-size:30px;line-height:1.2;color:#ffffff;">Invoice Ready</h1>
            <p style="margin:0;font-size:15px;line-height:1.6;color:#e0f2fe;">Hello {$clientName}, thank you for your continued trust. Your invoice is prepared and ready for your review.</p>
            {$attachmentNote}
          </td>
          <td align="right" valign="top" style="padding-left:12px;">
            <div style="display:inline-block;background:rgba(255,255,255,0.16);border:1px solid rgba(255,255,255,0.25);border-radius:999px;padding:10px 14px;font-size:13px;font-weight:bold;color:#ffffff;">TimeBill</div>
          </td>
        </tr>
      </table>
    </div>

    <div style="background:#ffffff;padding:28px;border-radius:0 0 24px 24px;box-shadow:0 12px 30px rgba(15,23,42,0.08);">
      <p style="margin:0 0 18px;font-size:15px;line-height:1.7;color:#475569;">Thank you for choosing TimeBill. Your invoice is prepared with a polished summary below and the complete PDF attachment is included for your convenience.</p>

      <div style="background:#f8fbff;border:1px solid #e2ecfb;border-radius:18px;padding:20px;margin-bottom:18px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#64748b;padding-bottom:10px;">Invoice Details</td>
          </tr>
          <tr>
            <td>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="padding:6px 0;font-size:14px;color:#64748b;">Invoice Number</td>
                  <td style="padding:6px 0;font-size:14px;color:#14213d;font-weight:bold;text-align:right;">{$invoiceNo}</td>
                </tr>
                <tr>
                  <td style="padding:6px 0;font-size:14px;color:#64748b;">Invoice Date</td>
                  <td style="padding:6px 0;font-size:14px;color:#14213d;font-weight:bold;text-align:right;">{$generatedDate} at {$generatedTime}</td>
                </tr>
                <tr>
                  <td style="padding:6px 0;font-size:14px;color:#64748b;">Work Name</td>
                  <td style="padding:6px 0;font-size:14px;color:#14213d;font-weight:bold;text-align:right;">{$workName}</td>
                </tr>
                <tr>
                  <td style="padding:6px 0;font-size:14px;color:#64748b;">Duration</td>
                  <td style="padding:6px 0;font-size:14px;color:#14213d;font-weight:bold;text-align:right;">{$duration}</td>
                </tr>
                <tr>
                  <td style="padding:6px 0;font-size:14px;color:#64748b;">Rate</td>
                  <td style="padding:6px 0;font-size:14px;color:#14213d;font-weight:bold;text-align:right;">{$rate}</td>
                </tr>
                <tr>
                  <td style="padding:6px 0;font-size:14px;color:#64748b;">Amount</td>
                  <td style="padding:6px 0;font-size:14px;color:#14213d;font-weight:bold;text-align:right;">{$amount}</td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
      </div>

      <div style="background:#ffffff;border:1px solid #eaf1ff;border-radius:18px;padding:20px;box-shadow:0 6px 18px rgba(37,99,235,0.07);margin-bottom:18px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#64748b;padding-bottom:8px;">Payment Summary</td>
          </tr>
          <tr>
            <td style="font-size:14px;color:#64748b;">Total Amount</td>
            <td style="font-size:28px;color:#1d4ed8;font-weight:bold;text-align:right;">{$total}</td>
          </tr>
          <tr>
            <td style="padding-top:10px;font-size:14px;color:#64748b;">Payment Status</td>
            <td style="padding-top:10px;text-align:right;">
              <span style="display:inline-block;background:#dcfce7;color:#166534;padding:8px 12px;border-radius:999px;font-size:13px;font-weight:bold;">{$statusText}</span>
            </td>
          </tr>
        </table>
      </div>

      <div style="background:#f8fbff;border:1px solid #e2ecfb;border-radius:18px;padding:18px 20px;margin-bottom:18px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#64748b;padding-bottom:8px;">Invoice Breakdown</td>
          </tr>
          <tr>
            <td style="padding:6px 0;font-size:14px;color:#64748b;">Subtotal</td>
            <td style="padding:6px 0;font-size:14px;color:#14213d;font-weight:bold;text-align:right;">{$subtotal}</td>
          </tr>
          <tr>
            <td style="padding:6px 0;font-size:14px;color:#64748b;">Tax</td>
            <td style="padding:6px 0;font-size:14px;color:#14213d;font-weight:bold;text-align:right;">{$tax}</td>
          </tr>
          <tr>
            <td style="padding:8px 0 0;font-size:15px;color:#14213d;font-weight:bold;">Total</td>
            <td style="padding:8px 0 0;font-size:15px;color:#1d4ed8;font-weight:bold;text-align:right;">{$total}</td>
          </tr>
        </table>
      </div>

      <div style="background:linear-gradient(135deg,#eff6ff 0%,#f8fbff 100%);border:1px solid #dbeafe;border-radius:18px;padding:20px;margin-bottom:18px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="48" valign="top" style="font-size:28px;line-height:1;">📎</td>
            <td valign="top">
              <div style="font-size:16px;font-weight:bold;color:#1e3a8a;margin-bottom:4px;">Invoice Attached (PDF)</div>
              <div style="font-size:14px;line-height:1.6;color:#475569;">The complete invoice is attached as a PDF for easy download, sharing, and record keeping.</div>
            </td>
          </tr>
        </table>
      </div>

      <div style="border-top:1px solid #e2e8f0;padding-top:16px;font-size:13px;line-height:1.7;color:#64748b;">
        <p style="margin:0 0 6px;"><strong>Bill From</strong></p>
        <p style="margin:0 0 6px;">{$businessName}</p>
        <p style="margin:0 0 6px;">{$businessEmail} · {$businessPhone}</p>
        <p style="margin:0;">{$businessAddress}</p>
      </div>
    </div>

    <div style="text-align:center;padding:20px 12px 4px;color:#64748b;font-size:12px;line-height:1.7;">
      <p style="margin:0 0 4px;">© 2026 TimeBill. All rights reserved.</p>
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

$incoming = parseIncomingRequest();
$decoded = $incoming['data'];
$attachment = $incoming['attachment'];

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

$hasAttachment = false;
$tempAttachmentPath = null;

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

    if ($attachment !== null) {
        $tempAttachmentPath = $attachment['tmp_name'];
        $attachmentName = buildAttachmentFilename($data['invoice_no']);
        $mail->addAttachment($tempAttachmentPath, $attachmentName);
        $hasAttachment = true;
    }

    $mail->Body = buildHtmlEmail($data, $hasAttachment);
    $mail->AltBody = "Invoice {$data['invoice_no']} from TimeBill\n\nGenerated: {$data['generated_date']} at {$data['generated_time']}\nStatus: {$data['status']}\nTotal: {$data['total']}";

    $mail->send();
    jsonResponse(200, ['success' => true, 'message' => 'Email sent successfully.']);
} catch (Exception $e) {
    logError('SMTP send failed: ' . $e->getMessage());
    jsonResponse(500, ['success' => false, 'message' => 'Unable to send email at this time.']);
}
