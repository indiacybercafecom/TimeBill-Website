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
    $showTaxRow = !(floatval($tax) === 0.0 && floatval($subtotal) === floatval($total));
    $taxRowHtml = '';
    if ($showTaxRow) {
        $taxRowHtml = <<<HTML
                <tr>
                  <td style="padding:8px 0;font-size:13px;color:#7A6A63;white-space:nowrap;">Tax</td>
                  <td style="padding:8px 0;font-size:13px;color:#2E1A17;font-weight:700;text-align:right;">{$tax}</td>
                </tr>
HTML;
    }
    $attachmentNote = $hasAttachment
        ? '<div style="font-size:13px;line-height:1.4;color:#F1D9B5;margin-top:10px;">The invoice PDF is attached to this email.</div>'
        : '';

    $attachmentCardHtml = $hasAttachment
        ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:12px;">
          <tr>
            <td width="100%" style="width:100%;display:block;box-sizing:border-box;background:#FFFDF9;border:1px solid #E4D8C8;border-radius:16px;padding:16px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;">
                <tr>
                  <td width="40" valign="top" style="padding-right:12px;text-align:left;vertical-align:top;">
                    <div style="width:40px;height:40px;line-height:40px;text-align:center;border-radius:12px;background:#F1D9B5;color:#4A231F;font-size:18px;">📎</div>
                  </td>
                  <td width="100%" valign="top" style="text-align:left;vertical-align:top;">
                    <div style="font-size:14px;font-weight:700;color:#4A231F;margin-bottom:4px;">Invoice PDF Attached</div>
                    <div style="font-size:13px;line-height:1.5;color:#7A6A63;">The invoice PDF is attached to this email.</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Invoice from TimeBill</title>
</head>
<body style="margin:0;padding:14px;background-color:#FFFFFF;color:#2E1A17;font-family:Arial, Helvetica, sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;max-width:800px;margin:0 auto;">
    <tr>
      <td width="100%" style="width:100%;display:block;box-sizing:border-box;padding:0 14px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:14px;background:#4A231F;border-radius:16px;overflow:hidden;">
          <tr>
            <td width="100%" style="width:100%;display:block;box-sizing:border-box;padding:20px 18px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;">
                <tr>
                  <td valign="middle" width="1" style="padding-right:12px;text-align:left;vertical-align:middle;">
                    <img src="https://indiacybercafe.com/wp-content/uploads/2026/07/icon-1.png" width="40" height="40" alt="TimeBill Logo" style="display:block;border-radius:12px;">
                  </td>
                  <td valign="middle" width="100%" style="padding-right:12px;text-align:left;vertical-align:middle;">
                    <div style="font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:#F1D9B5;margin-bottom:4px;">TimeBill</div>
                    <div style="font-size:24px;line-height:1.1;font-weight:bold;color:#FFFFFF;">Invoice Ready</div>
                  </td>
                  <td valign="middle" width="1" style="text-align:right;vertical-align:middle;white-space:nowrap;">
                    <span style="display:inline-block;background:#D4AE7A;color:#4A231F;font-size:12px;font-weight:700;padding:8px 12px;border-radius:999px;">#{$invoiceNo}</span>
                  </td>
                </tr>
                <tr>
                  <td colspan="3" style="padding-top:14px;">
                    <div style="font-size:14px;line-height:1.5;color:#F1D9B5;">Your invoice is ready.</div>
                    {$attachmentNote}
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:12px;">
          <tr>
            <td width="100%" style="width:100%;display:block;box-sizing:border-box;background:#FFFFFF;border:1px solid #E4D8C8;border-radius:16px;padding:18px;">
              <div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#7A6A63;margin-bottom:12px;">Invoice Details</div>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;">
                <tr>
                  <td style="padding:8px 0;font-size:13px;color:#7A6A63;white-space:nowrap;">Invoice Number</td>
                  <td style="padding:8px 0;font-size:13px;color:#2E1A17;font-weight:700;text-align:right;">{$invoiceNo}</td>
                </tr>
                <tr>
                  <td style="padding:8px 0;font-size:13px;color:#7A6A63;white-space:nowrap;">Invoice Date</td>
                  <td style="padding:8px 0;font-size:13px;color:#2E1A17;font-weight:700;text-align:right;">{$generatedDate} at {$generatedTime}</td>
                </tr>
                <tr>
                  <td style="padding:8px 0;font-size:13px;color:#7A6A63;white-space:nowrap;">Work Name</td>
                  <td style="padding:8px 0;font-size:13px;color:#2E1A17;font-weight:700;text-align:right;">{$workName}</td>
                </tr>
                <tr>
                  <td style="padding:8px 0;font-size:13px;color:#7A6A63;white-space:nowrap;">Duration</td>
                  <td style="padding:8px 0;font-size:13px;color:#2E1A17;font-weight:700;text-align:right;">{$duration}</td>
                </tr>
                <tr>
                  <td style="padding:8px 0;font-size:13px;color:#7A6A63;white-space:nowrap;">Rate</td>
                  <td style="padding:8px 0;font-size:13px;color:#2E1A17;font-weight:700;text-align:right;">{$rate}</td>
                </tr>
                <tr>
                  <td style="padding:8px 0;font-size:13px;color:#7A6A63;white-space:nowrap;">Amount</td>
                  <td style="padding:8px 0;font-size:13px;color:#2E1A17;font-weight:700;text-align:right;">{$amount}</td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:12px;">
          <tr>
            <td width="100%" style="width:100%;display:block;box-sizing:border-box;background:#FFFFFF;border:1px solid #E4D8C8;border-radius:16px;padding:18px;">
              <div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#7A6A63;margin-bottom:12px;">Payment Summary</div>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;">
                <tr>
                  <td width="100%" style="padding:8px 0;font-size:13px;color:#7A6A63;text-align:left;white-space:nowrap;">Total</td>
                  <td width="1" style="padding:8px 0;font-size:22px;color:#4A231F;font-weight:700;text-align:right;white-space:nowrap;">{$total}</td>
                </tr>
                <tr>
                  <td width="100%" style="padding:8px 0 0;font-size:13px;color:#7A6A63;text-align:left;white-space:nowrap;">Status</td>
                  <td width="1" style="padding:8px 0 0;text-align:right;white-space:nowrap;">
                    <span style="display:inline-block;background:#4CAF50;color:#FFFFFF;font-size:12px;font-weight:700;padding:8px 12px;border-radius:999px;">{$statusText}</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:12px;">
          <tr>
            <td width="100%" style="width:100%;display:block;box-sizing:border-box;background:#FFFFFF;border:1px solid #E4D8C8;border-radius:16px;padding:18px;">
              <div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#7A6A63;margin-bottom:12px;">Invoice Breakdown</div>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;">
                <tr>
                  <td width="100%" style="padding:8px 0;font-size:13px;color:#7A6A63;text-align:left;white-space:nowrap;">Subtotal</td>
                  <td width="1" style="padding:8px 0;font-size:13px;color:#2E1A17;font-weight:700;text-align:right;white-space:nowrap;">{$subtotal}</td>
                </tr>
                {$taxRowHtml}
                <tr>
                  <td width="100%" style="padding:8px 0 0;font-size:13px;color:#2E1A17;font-weight:700;text-align:left;white-space:nowrap;">Total</td>
                  <td width="1" style="padding:8px 0 0;font-size:13px;color:#4A231F;font-weight:700;text-align:right;white-space:nowrap;">{$total}</td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:12px;">
          <tr>
            <td width="100%" style="width:100%;display:block;box-sizing:border-box;background:#FFFDF9;border:1px solid #E4D8C8;border-radius:16px;padding:16px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;">
                <tr>
                  <td width="40" valign="top" style="padding-right:12px;text-align:left;vertical-align:top;">
                    <div style="width:40px;height:40px;line-height:40px;text-align:center;border-radius:12px;background:#F1D9B5;color:#4A231F;font-size:18px;">📎</div>
                  </td>
                  <td width="100%" valign="top" style="text-align:left;vertical-align:top;">
                    <div style="font-size:14px;font-weight:700;color:#4A231F;margin-bottom:4px;">Invoice PDF Attached</div>
                    <div style="font-size:13px;line-height:1.5;color:#7A6A63;">The invoice PDF is attached to this email.</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:12px;">
          <tr>
            <td width="100%" style="width:100%;display:block;box-sizing:border-box;background:#FFFFFF;border:1px solid #E4D8C8;border-radius:16px;padding:16px;">
              <div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#7A6A63;margin-bottom:10px;">Bill From</div>
              <div style="font-size:13px;color:#2E1A17;font-weight:700;">{$businessName}</div>
              <div style="font-size:13px;color:#7A6A63;padding-top:6px;">{$businessEmail} • {$businessPhone}</div>
              <div style="font-size:13px;color:#7A6A63;padding-top:8px;line-height:1.5;">{$businessAddress}</div>
            </td>
          </tr>
        </table>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;margin-bottom:12px;">
          <tr>
            <td width="100%" style="width:100%;display:block;box-sizing:border-box;background:#FFFFFF;border:1px solid #E4D8C8;border-radius:16px;padding:16px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;">
                <tr>
                  <td width="1" valign="middle" style="padding-right:12px;text-align:left;vertical-align:middle;">
                    <img src="https://indiacybercafe.com/wp-content/uploads/2026/07/icon-1.png" width="40" height="40" alt="TimeBill Logo" style="display:block;border-radius:12px;">
                  </td>
                  <td width="100%" valign="middle" style="padding-right:12px;text-align:left;vertical-align:middle;">
                    <div style="font-size:15px;font-weight:bold;color:#4A231F;">Download TimeBill</div>
                    <div style="font-size:13px;color:#7A6A63;line-height:1.4;margin-top:4px;">Track work. Generate invoices. Send instantly.</div>
                  </td>
                  <td width="1" valign="middle" style="text-align:right;vertical-align:middle;">
                    <a href="https://play.google.com/store/apps/details?id=com.timebill.stopwatch" target="_blank" style="display:inline-block;text-decoration:none;">
                      <img src="https://play.google.com/intl/en_us/badges/static/images/badges/en_badge_web_generic.png" width="146" alt="Get it on Google Play" style="display:block;border:none;outline:none;text-decoration:none;">
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;min-width:100%;border-collapse:collapse;table-layout:fixed;">
          <tr>
            <td width="100%" style="width:100%;display:block;box-sizing:border-box;padding:0 0 4px;color:#7A6A63;font-size:12px;line-height:1.5;text-align:center;">© 2026 TimeBill</td>
          </tr>
          <tr>
            <td width="100%" style="width:100%;display:block;box-sizing:border-box;padding:0;color:#7A6A63;font-size:12px;line-height:1.5;text-align:center;">
              <a href="https://timebill.indiacybercafe.com" style="color:#6B3A33;text-decoration:none;">https://timebill.indiacybercafe.com</a>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
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
