<?php
require_once __DIR__ . '/lib/SimplePDF.php';
/**
 * CMGalaxy - SMTP Email Dispatcher for Audit Reports
 * v1.1 - Deployed via GitHub Actions CI/CD
 */

// Suppress raw PHP errors from breaking JSON output
error_reporting(0);
ini_set('display_errors', '0');

// Ensure execution continues even if user closes or refreshes the window/tab
ignore_user_abort(true);
set_time_limit(60);

// Timezone: India Standard Time (IST, UTC+5:30)
date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // =========================================================================
    // 1. LOAD .ENV SECRETS & CONFIGURATION
    // =========================================================================
    if (file_exists(__DIR__ . '/.env')) {
        $envLines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($envLines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (strpos($line, '=') !== false) {
                [$envKey, $envVal] = explode('=', $line, 2);
                $envKey = trim($envKey);
                $envVal = trim($envVal);
                if ((str_starts_with($envVal, '"') && str_ends_with($envVal, '"')) ||
                    (str_starts_with($envVal, "'") && str_ends_with($envVal, "'"))) {
                    $envVal = substr($envVal, 1, -1);
                }
                putenv("{$envKey}={$envVal}");
                $_ENV[$envKey] = $envVal;
            }
        }
    }

    define('SMTP_HOST', trim((string)getenv('SMTP_HOST')));
    define('SMTP_PORT', (int)getenv('SMTP_PORT'));
    define('SMTP_USER', trim((string)getenv('SMTP_USER')));
    define('SMTP_PASS', (string)getenv('SMTP_PASS'));
    define('FROM_NAME', trim((string)getenv('FROM_NAME')));
    define('ADMIN_EMAIL', trim((string)getenv('ADMIN_EMAIL')));

    // Google reCAPTCHA v3 Configuration (Invisible)
    define('RECAPTCHA_SITE_KEY', trim((string)getenv('RECAPTCHA_SITE_KEY')));
    define('RECAPTCHA_SECRET_KEY', trim((string)getenv('RECAPTCHA_SECRET_KEY')));

    // Base URL of your site (no trailing slash) — used to build the public report link
    $isHttps = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    define('SITE_URL', $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'd2c.cmgalaxy.com'));

    // =========================================================================
    // 1.3. PUBLIC CONFIG ENDPOINT (Provides frontend with reCAPTCHA site key)
    // =========================================================================
    if (isset($_GET['config'])) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'recaptchaSiteKey' => (defined('RECAPTCHA_SITE_KEY') && RECAPTCHA_SITE_KEY !== 'your_recaptcha_site_key_here') ? RECAPTCHA_SITE_KEY : ''
        ]);
        exit;
    }

    /**
     * Verifies Google reCAPTCHA v3 token against Google API.
     * Gracefully bypasses if keys are unconfigured / placeholder.
     */
    function verifyGoogleRecaptcha($token, $action = 'submit_lead') {
        $secret = defined('RECAPTCHA_SECRET_KEY') ? RECAPTCHA_SECRET_KEY : '';
        if (empty($secret) || $secret === 'your_recaptcha_secret_key_here') {
            return ['success' => true, 'score' => 1.0, 'bypassed' => true];
        }

        if (empty($token)) {
            return ['success' => false, 'error' => 'reCAPTCHA token missing. Please refresh and try again.'];
        }

        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $postData = http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $resp = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($resp, true);
        if (!$result || empty($result['success'])) {
            $errCodes = isset($result['error-codes']) ? implode(', ', $result['error-codes']) : 'verification failed';
            return ['success' => false, 'error' => "reCAPTCHA check failed ({$errCodes})."];
        }

        $score = (float)($result['score'] ?? 0);
        if ($score < 0.3) {
            return ['success' => false, 'score' => $score, 'error' => 'Bot activity protection check failed. Please refresh.'];
        }

        return ['success' => true, 'score' => $score];
    }

    // =========================================================================
    // 1.4. CLIENT IP DETECTION & RATE LIMITING SYSTEM (3 per day limit)
    // =========================================================================
    function getClientIp() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return trim($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    /**
     * Checks if IP has exceeded the daily form submission limit (3 per 24 hours).
     * If limit exceeded, automatically records IP into blocked_ips.json.
     */
    function checkSubmissionRateLimit($ip) {
        $dir = __DIR__ . '/reports/.ratelimits';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $now = time();
        $windowSeconds = 86400; // 24 hours (1 day)
        $maxAllowed = 3;

        // Check if IP is already in blocked list
        $blockedFile = $dir . '/blocked_ips.json';
        $blockedList = file_exists($blockedFile) ? json_decode(@file_get_contents($blockedFile), true) : [];
        if (!is_array($blockedList)) $blockedList = [];

        if (isset($blockedList[$ip])) {
            $item = $blockedList[$ip];
            if (!empty($item['active']) && $now < ($item['expires_at'] ?? 0)) {
                $hoursLeft = ceil(($item['expires_at'] - $now) / 3600);
                return [
                    'allowed' => false,
                    'blocked' => true,
                    'error' => "Daily submission limit reached (3 per day). Your IP ({$ip}) is blocked. Please try again in {$hoursLeft} hour(s)."
                ];
            }
        }

        $ipFile = $dir . '/submit_' . md5($ip) . '.json';
        $record = file_exists($ipFile) ? json_decode(@file_get_contents($ipFile), true) : null;

        if (!$record || ($now - ($record['start'] ?? 0)) > $windowSeconds) {
            $record = [
                'ip' => $ip,
                'count' => 1,
                'start' => $now,
                'submissions' => [$now]
            ];
        } else {
            $record['count'] = ($record['count'] ?? 0) + 1;
            $record['submissions'][] = $now;
        }

        file_put_contents($ipFile, json_encode($record, JSON_PRETTY_PRINT));

        if ($record['count'] > $maxAllowed) {
            $blockedList[$ip] = [
                'ip' => $ip,
                'reason' => "Exceeded daily form submission limit ({$maxAllowed} forms / 24 hours)",
                'attempts' => $record['count'],
                'blocked_at' => $now,
                'expires_at' => $now + $windowSeconds,
                'active' => true
            ];
            file_put_contents($blockedFile, json_encode($blockedList, JSON_PRETTY_PRINT));

            return [
                'allowed' => false,
                'blocked' => true,
                'error' => "Submission limit reached (maximum {$maxAllowed} reports per day from one IP). Your IP has been temporarily blocked for 24 hours."
            ];
        }

        return [
            'allowed' => true,
            'count' => $record['count'],
            'remaining' => $maxAllowed - $record['count']
        ];
    }

    /**
     * Checks if IP is spamming OTP requests (Max 10 OTP requests per 24 hours).
     */
    function checkOtpRateLimit($ip) {
        $dir = __DIR__ . '/reports/.ratelimits';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $now = time();
        $windowSeconds = 86400; // 24 hours
        $maxAllowed = 10;

        $blockedFile = $dir . '/blocked_ips.json';
        $blockedList = file_exists($blockedFile) ? json_decode(@file_get_contents($blockedFile), true) : [];
        if (!is_array($blockedList)) $blockedList = [];

        if (isset($blockedList[$ip]) && !empty($blockedList[$ip]['active']) && $now < ($blockedList[$ip]['expires_at'] ?? 0)) {
            $hoursLeft = ceil(($blockedList[$ip]['expires_at'] - $now) / 3600);
            return [
                'allowed' => false,
                'error' => "Your IP is blocked. Please try again in {$hoursLeft} hour(s)."
            ];
        }

        $ipFile = $dir . '/otp_' . md5($ip) . '.json';
        $record = file_exists($ipFile) ? json_decode(@file_get_contents($ipFile), true) : null;

        if (!$record || ($now - ($record['start'] ?? 0)) > $windowSeconds) {
            $record = ['ip' => $ip, 'count' => 1, 'start' => $now];
        } else {
            $record['count'] = ($record['count'] ?? 0) + 1;
        }

        file_put_contents($ipFile, json_encode($record));

        if ($record['count'] > $maxAllowed) {
            $blockedList[$ip] = [
                'ip' => $ip,
                'reason' => "Excessive OTP requests ({$record['count']} requests / 24 hours)",
                'attempts' => $record['count'],
                'blocked_at' => $now,
                'expires_at' => $now + $windowSeconds,
                'active' => true
            ];
            file_put_contents($blockedFile, json_encode($blockedList, JSON_PRETTY_PRINT));

            return [
                'allowed' => false,
                'error' => "Too many OTP verification requests from your IP. Please try again tomorrow."
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Appends lead record to reports/leads_log.json for Admin Dashboard.
     */
    function logLeadToJson($lead) {
        $reportsDir = __DIR__ . '/reports';
        if (!is_dir($reportsDir)) @mkdir($reportsDir, 0755, true);
        $file = $reportsDir . '/leads_log.json';

        $leads = file_exists($file) ? json_decode(@file_get_contents($file), true) : [];
        if (!is_array($leads)) $leads = [];

        array_unshift($leads, $lead); // Newest first
        if (count($leads) > 2000) {
            $leads = array_slice($leads, 0, 2000);
        }
        file_put_contents($file, json_encode($leads, JSON_PRETTY_PRINT));
    }

    // =========================================================================
    // 1.5. 1-CLICK TEST MODE (Browser me direct test: send_email.php?test=1)
    // =========================================================================
    if (isset($_GET['test'])) {
        $testSubject = "CMGalaxy SMTP Test — " . date('Y-m-d H:i:s');
        $testBody = "<h3>SMTP Connected Successfully!</h3><p>Server: " . SMTP_HOST . "</p><p>Account: " . SMTP_USER . "</p>";
        $testResult = sendSmtpMail(ADMIN_EMAIL, $testSubject, $testBody);

        echo json_encode([
            'status' => 'SMTP Direct Test',
            'host' => SMTP_HOST,
            'port' => SMTP_PORT,
            'user' => SMTP_USER,
            'adminEmail' => ADMIN_EMAIL,
            'result' => $testResult
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // =========================================================================
    // 1.6. SECURE EXCEL CSV EXPORT (Browser me direct: send_email.php?export_leads=1&key=cmgalaxy_leads_2026)
    // =========================================================================
    if (isset($_GET['export_leads'])) {
        $secretKey = $_GET['key'] ?? '';
        if ($secretKey !== 'cmgalaxy_leads_2026') {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Unauthorized access. Valid secret key required.']);
            exit;
        }

        $csvFile = __DIR__ . '/reports/leads.csv';
        if (!file_exists($csvFile)) {
            http_response_code(404);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'No leads recorded in Excel sheet yet.']);
            exit;
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="cmgalaxy_attribution_leads_' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($csvFile);
        exit;
    }

    // =========================================================================
    // 1.7. SECURE EXCEL CSV CLEAR (send_email.php?clear_leads=1&key=cmgalaxy_leads_2026)
    // =========================================================================
    if (isset($_GET['clear_leads'])) {
        $secretKey = $_GET['key'] ?? '';
        if ($secretKey !== 'cmgalaxy_leads_2026') {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Unauthorized access. Valid secret key required.']);
            exit;
        }

        $reportsDir = __DIR__ . '/reports';
        if (!is_dir($reportsDir)) @mkdir($reportsDir, 0755, true);
        file_put_contents($reportsDir . '/leads_log.json', json_encode([], JSON_PRETTY_PRINT));
        
        $fp = @fopen($reportsDir . '/leads.csv', 'w');
        if ($fp) {
            fputs($fp, "\xEF\xBB\xBF");
            fputcsv($fp, [
                'Submission Date (IST)',
                'First Name',
                'Last Name',
                'Full Name',
                'Phone Number',
                'Work Email',
                'Company / Brand'
            ]);
            fclose($fp);
        }

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true, 'message' => 'Excel leads sheet cleared successfully.']);
        exit;
    }

    // =========================================================================
    // 2. INPUT DATA PROCESSING & OTP ACTIONS
    // =========================================================================
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!$data) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid or empty JSON request.'
        ]);
        exit;
    }

    $otpDir = __DIR__ . '/reports/.otps';
    if (!is_dir($otpDir)) {
        @mkdir($otpDir, 0755, true);
    }

    // -------------------------------------------------------------------------
    // ACTION: SEND OTP
    // -------------------------------------------------------------------------
    if (isset($data['action']) && ($data['action'] === 'send_otp' || $data['action'] === 'sendotp')) {
        // Rate limit OTP requests by client IP (Max 10 per 24 hours)
        $clientIp = getClientIp();
        $ipOtpCheck = checkOtpRateLimit($clientIp);
        if (!$ipOtpCheck['allowed']) {
            echo json_encode(['success' => false, 'error' => $ipOtpCheck['error']]);
            exit;
        }

        // Validate reCAPTCHA v3 token
        $recaptchaToken = $data['recaptcha_token'] ?? $data['recaptchaToken'] ?? '';
        $captchaCheck = verifyGoogleRecaptcha($recaptchaToken, 'sendotp');
        if (!$captchaCheck['success']) {
            echo json_encode(['success' => false, 'error' => $captchaCheck['error']]);
            exit;
        }

        $email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            echo json_encode(['success' => false, 'error' => 'Please enter a valid work email address.']);
            exit;
        }

        $emailClean = strtolower($email);
        $emailHash = md5($emailClean);
        $otpFile = $otpDir . '/' . $emailHash . '.json';

        // Rate limit: 20 seconds between code requests
        if (file_exists($otpFile)) {
            $existing = json_decode(@file_get_contents($otpFile), true);
            if ($existing && isset($existing['created']) && (time() - $existing['created']) < 20) {
                $waitSec = 20 - (time() - $existing['created']);
                echo json_encode([
                    'success' => false,
                    'error' => "Please wait {$waitSec}s before requesting a new code."
                ]);
                exit;
            }
        }

        // Generate 6-digit OTP
        $otp = (string)random_int(100000, 999999);
        $name = htmlspecialchars(trim($data['name'] ?? 'There'));
        if ($name === '') $name = 'There';

        $otpRecord = [
            'email' => $emailClean,
            'otp' => password_hash($otp, PASSWORD_DEFAULT),
            'created' => time(),
            'expires' => time() + 600, // 10 minutes
            'verified' => false,
            'attempts' => 0
        ];
        file_put_contents($otpFile, json_encode($otpRecord));

        // Send OTP Email via SMTP
        $logoUrl = SITE_URL . '/assets/cmgalaxy-logo.png';
        $subject = "Your CMGalaxy Verification Code: {$otp}";
        $body = "
        <!DOCTYPE html>
        <html>
        <head><meta charset='UTF-8'></head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px;'>
          <div style='max-width: 540px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;'>
            <div style='background: #0f172a; padding: 24px; color: #ffffff;'>
              <h2 style='margin: 0; font-size: 20px; font-weight: 700; color: #ffffff !important;'>Email Verification</h2>
              <p style='margin: 4px 0 0 0; color: #94a3b8 !important; font-size: 14px;'>CMGalaxy D2C Attribution Audit</p>
            </div>
            <div style='padding: 32px 24px; text-align: center;'>
              <p style='font-size: 15px; color: #334155; margin: 0 0 16px 0; text-align: left;'>Hi <strong>{$name}</strong>,</p>
              <p style='font-size: 15px; color: #334155; line-height: 1.6; margin: 0 0 20px 0; text-align: left;'>
                Please use the 6-digit verification code below to confirm your work email and unlock your personalised D2C Attribution Audit Report:
              </p>
              
              <div style='background: #f1f5f9; border: 1.5px dashed #cbd5e1; border-radius: 12px; padding: 18px 24px; margin: 24px auto; display: inline-block;'>
                <span style='font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, monospace; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #0f172a;'>{$otp}</span>
              </div>
              
              <p style='font-size: 13px; color: #64748b; margin: 16px 0 0 0; line-height: 1.5;'>
                This code is valid for <strong>10 minutes</strong>. If you did not request this audit report, please disregard this email.
              </p>

              <div style='margin-top: 28px; padding-top: 20px; border-top: 1px solid #f1f5f9; text-align: left;'>
                <p style='font-size: 13px; color: #334155; margin: 0 0 10px 0;'>Best regards,<br><strong>CMGalaxy Team</strong></p>
                <a href='https://www.cmgalaxy.com/' target='_blank' rel='noopener noreferrer' style='text-decoration: none;'>
                  <img src='{$logoUrl}' alt='CMGalaxy' style='height: 26px; width: auto; max-width: 130px; display: block; border: 0;' />
                </a>
              </div>
            </div>
          </div>
        </body>
        </html>";

        $mailRes = sendSmtpMail($email, $subject, $body);

        if ($mailRes['success']) {
            echo json_encode([
                'success' => true,
                'message' => 'Verification code sent to ' . htmlspecialchars($email)
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Could not send verification email. Please check your address or try again.'
            ]);
        }
        exit;
    }

    // -------------------------------------------------------------------------
    // ACTION: VERIFY OTP
    // -------------------------------------------------------------------------
    if (isset($data['action']) && $data['action'] === 'verify_otp') {
        $email = filter_var(trim($data['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $otpInput = trim((string)($data['otp'] ?? ''));

        if (!$email || strlen($otpInput) !== 6) {
            echo json_encode(['success' => false, 'error' => 'Please enter the 6-digit verification code.']);
            exit;
        }

        $emailClean = strtolower($email);
        $emailHash = md5($emailClean);
        $otpFile = $otpDir . '/' . $emailHash . '.json';

        if (!file_exists($otpFile)) {
            echo json_encode(['success' => false, 'error' => 'No active verification code found. Please request a new code.']);
            exit;
        }

        $record = json_decode(@file_get_contents($otpFile), true);
        if (!$record || time() > ($record['expires'] ?? 0)) {
            @unlink($otpFile);
            echo json_encode(['success' => false, 'error' => 'Verification code has expired. Please request a new code.']);
            exit;
        }

        if (($record['attempts'] ?? 0) >= 5) {
            @unlink($otpFile);
            echo json_encode(['success' => false, 'error' => 'Too many failed attempts. Please request a new code.']);
            exit;
        }

        if (!password_verify($otpInput, $record['otp'])) {
            $record['attempts'] = ($record['attempts'] ?? 0) + 1;
            file_put_contents($otpFile, json_encode($record));
            $remaining = 5 - $record['attempts'];
            echo json_encode([
                'success' => false,
                'error' => "Incorrect verification code. ({$remaining} attempts remaining)"
            ]);
            exit;
        }

        // Verified successfully!
        $record['verified'] = true;
        $record['verified_at'] = time();
        file_put_contents($otpFile, json_encode($record));

        echo json_encode([
            'success' => true,
            'verified' => true,
            'message' => 'Email verified successfully!'
        ]);
        exit;
    }

    // -------------------------------------------------------------------------
    // LEAD CAPTURE & REPORT DISPATCH
    // -------------------------------------------------------------------------
    if (!isset($data['lead'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid lead data received or empty request.'
        ]);
        exit;
    }

    $firstName   = htmlspecialchars(mb_convert_case(trim($data['lead']['firstName'] ?? ''), MB_CASE_TITLE, 'UTF-8'));
    $lastName    = htmlspecialchars(mb_convert_case(trim($data['lead']['lastName'] ?? ''), MB_CASE_TITLE, 'UTF-8'));
    $fullNameRaw = trim($firstName . ' ' . $lastName);
    if ($fullNameRaw === '') {
        $fullNameRaw = trim($data['lead']['name'] ?? 'User');
    }
    $userName    = htmlspecialchars(mb_convert_case($fullNameRaw, MB_CASE_TITLE, 'UTF-8'));
    
    // Robust Phone Extraction (handles lead.phone, root phone, phoneNumber, etc.)
    $rawPhone    = $data['lead']['phone'] ?? $data['phone'] ?? $data['lead']['phoneNumber'] ?? $data['lead']['tel'] ?? '';
    $trimmedPhone = trim((string)$rawPhone);
    $userPhone   = ($trimmedPhone !== '') ? htmlspecialchars($trimmedPhone) : 'N/A';
    
    $userEmail   = filter_var($data['lead']['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $userCompany = htmlspecialchars($data['lead']['company'] ?? 'N/A');
    $auditId     = htmlspecialchars($data['id'] ?? 'N/A');
    $submittedAt = htmlspecialchars($data['submittedAt'] ?? date('c'));
    $submissionDate = date('F j, Y - H:i') . ' IST';
    $answers     = $data['answers'] ?? [];

    if (!$userEmail) {
        echo json_encode([
            'success' => false,
            'error' => 'Valid user email is required.'
        ]);
        exit;
    }

    // Validate reCAPTCHA v3 token on report generation
    $recaptchaToken = $data['recaptcha_token'] ?? $data['recaptchaToken'] ?? '';
    $captchaCheck = verifyGoogleRecaptcha($recaptchaToken, 'submitlead');
    if (!$captchaCheck['success']) {
        echo json_encode(['success' => false, 'error' => $captchaCheck['error']]);
        exit;
    }

    // Verify that the email was verified before generating the report
    $emailClean = strtolower($userEmail);
    $emailHash = md5($emailClean);
    $otpFile = $otpDir . '/' . $emailHash . '.json';
    $isVerified = false;
    if (file_exists($otpFile)) {
        $record = json_decode(@file_get_contents($otpFile), true);
        if ($record && !empty($record['verified'])) {
            $isVerified = true;
        }
    }

    if (!$isVerified) {
        echo json_encode([
            'success' => false,
            'error' => 'Email verification required before generating the report.'
        ]);
        exit;
    }

    // =========================================================================
    // 2.2. IP RATE LIMIT: MAXIMUM 3 SUBMISSIONS PER IP PER 24 HOURS
    // =========================================================================
    $clientIp = getClientIp();
    $rateLimitCheck = checkSubmissionRateLimit($clientIp);
    if (!$rateLimitCheck['allowed']) {
        echo json_encode([
            'success' => false,
            'error' => $rateLimitCheck['error']
        ]);
        exit;
    }

    // =========================================================================
    // 2.5. SAVE PDF REPORT TO /reports/ FOLDER
    // =========================================================================
    // Filename: {name}-{YYYY-MM-DD}-{HHmmss}.pdf
    // HHmmss timestamp ensures every submission = new unique file (never overwrites)
    $safeNameSlug   = preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($data['lead']['name'] ?? 'User'));
    $dateSlug       = date('Y-m-d');
    $timeSlug       = date('His');   // e.g. 143022
    $reportFileName = $safeNameSlug . '-' . $dateSlug . '-' . $timeSlug . '.pdf';
    $reportsDir     = __DIR__ . '/reports';
    $reportFilePath = $reportsDir . '/' . $reportFileName;
    $reportPublicUrl = SITE_URL . '/reports/' . $reportFileName;

    // Generate PDF and save it
    $reportSaveResult = ['saved' => false, 'path' => null, 'url' => null, 'error' => null, 'filename' => $reportFileName];
    if (!is_dir($reportsDir)) {
        @mkdir($reportsDir, 0755, true);
    }
    $analysisData = $data['analysis'] ?? null;
    $pdfBytes = generateReportPdf($userName, $userEmail, $userCompany, $auditId, $submittedAt, $answers, $analysisData);
    $written  = file_put_contents($reportFilePath, $pdfBytes);
    if ($written !== false) {
        $reportSaveResult = ['saved' => true, 'path' => $reportFilePath, 'url' => $reportPublicUrl, 'filename' => $reportFileName, 'error' => null];
    } else {
        $reportSaveResult['error'] = 'Could not write PDF to: ' . $reportFilePath;
        $reportPublicUrl = null; // No URL if save failed
    }

    // =========================================================================
    // 2.7. RECORD LEAD TO SERVER EXCEL (CSV) SHEET (Only Core Lead Details)
    // =========================================================================
    $csvRecorded = false;
    $csvFilePath = $reportsDir . '/leads.csv';
    $isNewCsv = !file_exists($csvFilePath) || filesize($csvFilePath) === 0;
    $csvFp = @fopen($csvFilePath, 'a');

    if ($csvFp) {
        // If new file, write UTF-8 BOM (Byte Order Mark) for Excel compatibility + Header Row
        if ($isNewCsv) {
            fputs($csvFp, "\xEF\xBB\xBF");
            fputcsv($csvFp, [
                'Submission Date (IST)',
                'First Name',
                'Last Name',
                'Full Name',
                'Phone Number',
                'Work Email',
                'Company / Brand'
            ]);
        }

        fputcsv($csvFp, [
            $submissionDate,
            $firstName,
            $lastName,
            $userName,
            $userPhone,
            $userEmail,
            $userCompany
        ]);
        fclose($csvFp);
        $csvRecorded = true;

        // Also record to reports/leads_log.json for the Admin Dashboard
        logLeadToJson([
            'id'               => $auditId,
            'timestamp'        => time(),
            'date'             => $submissionDate,
            'firstName'        => $firstName,
            'lastName'         => $lastName,
            'fullName'         => $userName,
            'email'            => $userEmail,
            'phone'            => $userPhone,
            'company'          => $userCompany,
            'ip'               => $clientIp,
            'pdfUrl'           => $reportPublicUrl,
            'pdfFilename'      => $reportFileName,
            'riskLevel'        => $analysisData['strategicRiskLevel'] ?? 'Moderate',
            'reliableCount'    => $analysisData['reliableCount'] ?? 0,
            'gapsCount'        => $analysisData['attributionGapCount'] ?? 0,
            'investigateCount' => $analysisData['investigateCount'] ?? 0
        ]);
    }



    // =========================================================================
    // 3. USER EMAIL CONTENT
    // =========================================================================
    $userSubject = "Your CMGalaxy D2C Attribution Audit Report — " . $userCompany;

    $logoUrl = SITE_URL . '/assets/cmgalaxy-logo.png';

    // Build the CTA button for the PDF report link (only if the report was saved successfully)
    $reportLinkBlock = '';
    if ($reportPublicUrl) {
        $reportLinkBlock = "
          <div style='text-align: center; margin: 28px 0;'>
            <a href='{$reportPublicUrl}'
               style='display: inline-block; background: #0f172a;
                      color: #ffffff; text-decoration: none; padding: 14px 32px;
                      border-radius: 50px; font-size: 15px; font-weight: 600;
                      letter-spacing: 0.3px; box-shadow: 0 4px 14px rgba(15,23,42,0.25);'>
              View My Attribution Report &rarr;
            </a>
          </div>";
    }

    $userBody = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px;'>
      <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;'>
        <div style='background: #0f172a; padding: 24px; color: #ffffff;'>
          <h2 style='margin: 0; font-size: 20px; font-weight: 700; color: #ffffff !important;'>CMGalaxy D2C Attribution Audit</h2>
          <p style='margin: 4px 0 0 0; color: #94a3b8 !important; font-size: 14px;'>Attribution Report for {$userCompany}</p>
        </div>
        <div style='padding: 28px 24px;'>
          <p style='font-size: 15px; color: #334155; margin-top: 0;'>Hi <strong>{$userName}</strong>,</p>
          <p style='font-size: 15px; color: #334155; line-height: 1.6;'>Thank you for completing the attribution audit!</p>
          <p style='font-size: 15px; color: #334155; line-height: 1.6;'>Your personalised attribution report for <strong>{$userCompany}</strong> is ready. Discover potential gaps in your attribution setup and opportunities to improve tracking and reporting.</p>

          {$reportLinkBlock}

          <div style='text-align: center; margin: 32px 0 20px 0; border-top: 1px solid #f1f5f9; padding-top: 24px;'>
            <p style='font-size: 16px; font-weight: 700; color: #0f172a; margin: 0 0 16px 0;'>Ready to take the next step?</p>
            <div>
              <a href='https://cal.com/cmgalaxy/demo'
                 style='display: inline-block; background: #34C759; color: #ffffff;
                        text-decoration: none; padding: 13px 28px; border-radius: 50px;
                        font-size: 14px; font-weight: 600; margin: 6px 6px;
                        box-shadow: 0 3px 10px rgba(52,199,89,0.30);'>
                Book an Appointment &rarr;
              </a>
              <a href='https://platform.cmgalaxy.com/sign-up'
                 style='display: inline-block; background: #ffffff; color: #0f172a;
                        border: 1.5px solid #0f172a; text-decoration: none;
                        padding: 12px 28px; border-radius: 50px;
                        font-size: 14px; font-weight: 600; margin: 6px 6px;'>
                Sign Up Now &rarr;
              </a>
            </div>
          </div>

          <p style='margin-top: 24px; font-size: 14px; color: #64748b; line-height: 1.6;'>Need help understanding your results? Simply reply to this email &mdash; we're happy to help.</p>

          <p style='margin-top: 20px; font-size: 14px; color: #334155; margin-bottom: 12px;'>Best regards,<br><strong>CMgalaxy Team</strong></p>
          <div style='margin-top: 14px;'>
            <a href='https://www.cmgalaxy.com/' target='_blank' rel='noopener noreferrer' style='text-decoration: none; display: inline-block;'>
              <img src='{$logoUrl}' alt='CMGalaxy' style='height: 28px; width: auto; max-width: 140px; display: block; border: 0;' />
            </a>
          </div>
        </div>
      </div>
    </body>
    </html>";

    // =========================================================================
    // 4. ADMIN EMAIL CONTENT
    // =========================================================================
    $adminSubject = "New D2C Attribution Audit Lead: " . $userName . " (" . $userCompany . ")";

    $csvDownloadUrl = SITE_URL . '/send_email.php?export_leads=1&key=cmgalaxy_leads_2026';
    $hubspotBoardUrl = 'https://app-na2.hubspot.com/contacts/246183516/objects/0-1/views/all/board';

    $adminReportBtn = "
      <div style='text-align: center; margin: 28px 0 20px 0;'>
        <a href='{$hubspotBoardUrl}'
           target='_blank'
           style='display: inline-block; background: #34C759; color: #ffffff;
                  text-decoration: none; padding: 13px 28px; border-radius: 50px;
                  font-size: 14px; font-weight: 600; letter-spacing: 0.3px;
                  box-shadow: 0 4px 14px rgba(52,199,89,0.30); margin: 6px;'>
          View Hubspot &rarr;
        </a>
        " . ($reportPublicUrl ? "
        <a href='{$reportPublicUrl}'
           target='_blank'
           style='display: inline-block; background: #0f172a; color: #ffffff;
                  text-decoration: none; padding: 13px 28px; border-radius: 50px;
                  font-size: 14px; font-weight: 600; letter-spacing: 0.3px;
                  box-shadow: 0 4px 14px rgba(15,23,42,0.25); margin: 6px;'>
          View Audit Report &rarr;
        </a>" : "") . "
      </div>";

    $adminBody = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px;'>
      <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;'>
        <div style='background: #0f172a; padding: 24px; color: #ffffff;'>
          <h2 style='margin: 0; font-size: 20px; font-weight: 700; color: #ffffff !important;'>New D2C Attribution Audit Lead Captured</h2>
          <p style='margin: 4px 0 0 0; color: #94a3b8 !important; font-size: 14px;'>Lead notification for {$userCompany}</p>
        </div>
        <div style='padding: 28px 24px;'>
          <p style='font-size: 15px; color: #334155; margin-top: 0;'>Hi Team,</p>
          <p style='font-size: 15px; color: #334155; line-height: 1.6;'>A new lead has completed all 12 questions of the Personalised Attribution Audit.</p>

          <h3 style='margin-top: 24px; margin-bottom: 10px; font-size: 15px; font-weight: 700; color: #0f172a;'>Lead Details</h3>

          <table style='width: 100%; border-collapse: collapse; margin: 12px 0 24px 0; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;'>
            <tbody>
              <tr>
                <td style='padding: 10px 16px; border-bottom: 1px solid #e2e8f0; font-size: 13px; font-weight: 600; color: #64748b; width: 28%;'>Name</td>
                <td style='padding: 10px 16px; border-bottom: 1px solid #e2e8f0; font-size: 14px; font-weight: 600; color: #0f172a;'>{$userName}</td>
              </tr>
              <tr>
                <td style='padding: 10px 16px; border-bottom: 1px solid #e2e8f0; font-size: 13px; font-weight: 600; color: #64748b;'>Phone</td>
                <td style='padding: 10px 16px; border-bottom: 1px solid #e2e8f0; font-size: 14px; font-weight: 600; color: #0f172a;'>{$userPhone}</td>
              </tr>
              <tr>
                <td style='padding: 10px 16px; border-bottom: 1px solid #e2e8f0; font-size: 13px; font-weight: 600; color: #64748b;'>Email</td>
                <td style='padding: 10px 16px; border-bottom: 1px solid #e2e8f0; font-size: 14px; color: #0284c7;'><a href='mailto:{$userEmail}' style='color: #0284c7; text-decoration: none;'>{$userEmail}</a></td>
              </tr>
              <tr>
                <td style='padding: 10px 16px; border-bottom: 1px solid #e2e8f0; font-size: 13px; font-weight: 600; color: #64748b;'>Company</td>
                <td style='padding: 10px 16px; border-bottom: 1px solid #e2e8f0; font-size: 14px; font-weight: 600; color: #0f172a;'>{$userCompany}</td>
              </tr>
              <tr>
                <td style='padding: 10px 16px; font-size: 13px; font-weight: 600; color: #64748b;'>Date</td>
                <td style='padding: 10px 16px; font-size: 13px; color: #334155;'>{$submissionDate}</td>
              </tr>
            </tbody>
          </table>

          {$adminReportBtn}

          <p style='font-size: 14px; color: #64748b; margin-top: 24px; line-height: 1.6;'>Please review the findings, update the CRM, and follow up as appropriate.</p>

          <p style='margin-top: 20px; font-size: 14px; color: #334155; margin-bottom: 12px;'>From:<br><a href='" . SITE_URL . "' target='_blank' rel='noopener noreferrer' style='color: #0284c7; text-decoration: none; font-weight: 600; font-size: 14px;'>" . SITE_URL . "</a></p>
          <div style='margin-top: 14px;'>
            <a href='https://www.cmgalaxy.com/' target='_blank' rel='noopener noreferrer' style='text-decoration: none; display: inline-block;'>
              <img src='{$logoUrl}' alt='CMGalaxy' style='height: 28px; width: auto; max-width: 140px; display: block; border: 0;' />
            </a>
          </div>
        </div>
      </div>
    </body>
    </html>";

    // =========================================================================
    // 5. DISPATCH TO USER AND ADMIN
    // =========================================================================
    $userResult = sendSmtpMail($userEmail, $userSubject, $userBody);
    $adminResult = null;

    if (defined('ADMIN_EMAIL') && filter_var(ADMIN_EMAIL, FILTER_VALIDATE_EMAIL)) {
        $adminResult = sendSmtpMail(ADMIN_EMAIL, $adminSubject, $adminBody);
    }

    // =========================================================================
    // 5.5. CMGALAXY STAGING API SYNC (Server-to-Server, Bypasses Browser CORS!)
    // =========================================================================
    $stagingResult = ['success' => false, 'error' => null];
    $stagingUrl = 'https://staging-api.cmgalaxy.com/api/v2/lead_reporting/d_two_c_attribution_lead/';
    $apiKey = 'cmg_d2c_lead_8a39f1c7e42d65b09812af43';

    try {
        $stagingPayload = is_array($data) ? $data : [];
        $stagingPayload['lead_type'] = 'raw';
        $stagingPayload['is_enriched'] = false;
        $stagingPayload['status'] = 'new';
        $stagingPayload['lead_status'] = 'New';
        $stagingPayload['hs_lead_status'] = 'NEW';
        $stagingPayload['leadStatus'] = 'New';
        $stagingPayload['lifecyclestage'] = 'subscriber';
        $stagingPayload['lifecycle_stage'] = 'subscriber';
        if (isset($stagingPayload['lead']) && is_array($stagingPayload['lead'])) {
            $stagingPayload['lead']['lead_type'] = 'raw';
            $stagingPayload['lead']['is_enriched'] = false;
            $stagingPayload['lead']['status'] = 'new';
            $stagingPayload['lead']['lead_status'] = 'New';
            $stagingPayload['lead']['hs_lead_status'] = 'NEW';
            $stagingPayload['lead']['leadStatus'] = 'New';
            $stagingPayload['lead']['lifecyclestage'] = 'subscriber';
            $stagingPayload['lead']['lifecycle_stage'] = 'subscriber';
        }
        $stagingJson = json_encode($stagingPayload);

        $ch = curl_init($stagingUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $stagingJson);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $stagingResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decodedStaging = json_decode($stagingResponse, true);
        $stagingResult = [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'httpCode' => $httpCode,
            'response' => $decodedStaging ?: $stagingResponse
        ];
    } catch (Throwable $stgEx) {
        $stagingResult['error'] = $stgEx->getMessage();
    }

    echo json_encode([
        'success' => true,
        'userEmail' => [
            'sent' => $userResult['success'],
            'to' => $userEmail,
            'error' => $userResult['error'] ?? null
        ],
        'adminEmail' => [
            'sent' => $adminResult ? $adminResult['success'] : false,
            'to' => ADMIN_EMAIL,
            'error' => $adminResult['error'] ?? null
        ],
        'reportFile' => $reportSaveResult,
        'excelRecorded' => $csvRecorded,
        'stagingApi' => $stagingResult
    ]);
    exit;

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Server exception: ' . $e->getMessage()
    ]);
    exit;
}

// =========================================================================
// 6. MULTI-PAGE VISUAL PDF REPORT GENERATOR (matches on-screen diagnostic report)
// =========================================================================
function generateReportPdf($userName, $userEmail, $userCompany, $auditId, $submittedAt, $answers, $analysis = null): string {
    $generatedDate = date('F j, Y');
    $generatedTime = date('H:i');

    // Default static question definitions
    $questionsCatalog = [
        1 => [
            'id' => 1,
            'category' => '01 — REVENUE & CONVERSION',
            'title' => 'Revenue match',
            'question' => 'Does total revenue claimed by Meta + Google make sense against actual Shopify revenue after allowing for attribution overlap?',
            'whyItMatters' => 'When ad channels collectively claim 120-150%+ of your gross Shopify revenue, your blended return metrics become inflated illusions. You risk allocating more capital to channels that are simply claiming credit for organic demand or each other\'s assists.',
            'whatToInvestigate' => 'Sum 30-day reported revenue across Meta Ads Manager and Google Ads. Compare this against Shopify Gross Sales (less returns). Calculate your Net Attribution Inflation ratio and establish an incremental baseline.',
            'defaultImpact' => 'HIGH',
            'keyQuestion' => 'Can the team reproduce the same answer consistently?'
        ],
        2 => [
            'id' => 2,
            'category' => '01 — REVENUE & CONVERSION',
            'title' => 'Conversion match',
            'question' => 'Do Meta, Google, GA4 and Shopify broadly agree on the number and value of purchases?',
            'whyItMatters' => 'Discrepancies exceeding 15% between ad platform conversions, GA4 purchase events, and Shopify orders signal fundamental signal loss, ad blockers, or duplicate pixel firing. Inaccurate conversion counts mislead bidding algorithms.',
            'whatToInvestigate' => 'Cross-reference transaction IDs between Shopify order exports and GA4 purchase events. Audit Meta event deduplication scores and Google Ads enhanced conversion match rates over identical date windows.',
            'defaultImpact' => 'HIGH',
            'keyQuestion' => 'What data or integration is missing?'
        ],
        3 => [
            'id' => 3,
            'category' => '02 — ATTRIBUTION SETUP',
            'title' => 'Attribution windows',
            'question' => 'Do you know which attribution windows Meta and Google are using when they report conversions?',
            'whyItMatters' => 'Comparing a 7-day click / 1-day view attribution window in Meta directly against a Google Ads data-driven or 30-day click model creates an unfair apples-to-oranges comparison, distorting cross-channel budget allocation.',
            'whatToInvestigate' => 'Document the exact reporting settings in Meta Ads Manager (e.g. 7d click + 1d view vs 1d click only) and Google Ads (account-level vs campaign attribution models). Align both to a standardized view for budget comparisons.',
            'defaultImpact' => 'MEDIUM',
            'keyQuestion' => 'Is the difference documented and acceptable for decision-making?'
        ],
        4 => [
            'id' => 4,
            'category' => '02 — ATTRIBUTION SETUP',
            'title' => 'Double attribution',
            'question' => 'Could Meta and Google both be claiming credit for the same Shopify order?',
            'whyItMatters' => 'If Meta and Google both receive credit for the same Shopify order, platform-reported performance may not provide a reliable basis for reallocating budget. Both algorithms will believe they drove the sale independently.',
            'whatToInvestigate' => 'Compare platform-reported conversions against Shopify orders and review the attribution rules being used. Implement order ID transaction deduplication in your central analytics layer.',
            'defaultImpact' => 'HIGH',
            'keyQuestion' => 'Is the difference documented and acceptable for decision-making?'
        ],
        5 => [
            'id' => 5,
            'category' => '03 — TRACKING QUALITY',
            'title' => 'UTM hygiene',
            'question' => 'Are UTMs used consistently enough to trace paid traffic and revenue back to campaigns?',
            'whyItMatters' => 'Inconsistent UTM naming conventions (casing, campaign IDs, ad IDs, dynamic parameters) prevent your analytics engine from matching sessions to ad spend, creating "Direct" or "(Unassigned)" traffic black holes in GA4.',
            'whatToInvestigate' => 'Audit URL parameters across all active ad sets and ads in Meta and Google. Check for lowercase normalization, parameter casing, and automated parameter tracking templates.',
            'defaultImpact' => 'MEDIUM',
            'keyQuestion' => 'Is the difference documented and acceptable for decision-making?'
        ],
        6 => [
            'id' => 6,
            'category' => '03 — TRACKING QUALITY',
            'title' => 'Meta tracking',
            'question' => 'Are Meta Pixel and Conversions API configured correctly, without obvious missing or duplicate purchase events?',
            'whyItMatters' => 'Improper deduplication between browser Pixel and server-side Conversions API (CAPI) can either double-count purchases or drop valid signal, causing Meta Advantage+ campaigns to optimize on flawed feedback loops.',
            'whatToInvestigate' => 'Check Meta Events Manager for Event Match Quality (EMQ) scores and deduplication rates on Purchase events. Ensure identical event_id and order_id parameters are passed from both client and server.',
            'defaultImpact' => 'HIGH',
            'keyQuestion' => 'Is the difference documented and acceptable for decision-making?'
        ],
        7 => [
            'id' => 7,
            'category' => '03 — TRACKING QUALITY',
            'title' => 'Google tracking',
            'question' => 'Are Google Ads conversions set up without double-counting GA4 imports and direct tags?',
            'whyItMatters' => 'Having both GA4 imported purchases and direct Google Ads conversion tags active as "Primary" conversion actions causes Smart Bidding to double-count transactions and aggressively over-bid on brand search and PMax.',
            'whatToInvestigate' => 'Inspect Google Ads > Goals > Conversions. Verify that only one transaction source is marked as "Primary" for bidding, with Enhanced Conversions enabled and passing hashed user data.',
            'defaultImpact' => 'HIGH',
            'keyQuestion' => 'Is the difference documented and acceptable for decision-making?'
        ],
        8 => [
            'id' => 8,
            'category' => '04 — COMMERCE RECONCILIATION',
            'title' => 'Shopify reconciliation',
            'question' => 'Are paid orders verified against realized Shopify revenue after cancellations, returns, and failed payments?',
            'whyItMatters' => 'Ad platforms report gross orders without visibility into cancellations, fraud, refunds, or chargebacks. Optimizing marketing spend on phantom revenue leads to negative unit economics and cash flow strain.',
            'whatToInvestigate' => 'Build an automated reconciliation pipeline between Shopify webhook order status (specifically cancelled/refunded flags) and your ad reporting dashboard to observe net realized revenue.',
            'defaultImpact' => 'HIGH',
            'keyQuestion' => 'Is the difference documented and acceptable for decision-making?'
        ],
        9 => [
            'id' => 9,
            'category' => '04 — COMMERCE RECONCILIATION',
            'title' => 'New vs returning customers',
            'question' => 'Can you separate new vs returning customer revenue by acquisition channel?',
            'whyItMatters' => 'If retargeting ads or branded search campaigns claim credit for repurchases by loyal subscribers, your true Blended Customer Acquisition Cost (nCAC) is artificially deflated while top-of-funnel reach stalls.',
            'whatToInvestigate' => 'Segment Shopify orders by first-time customer vs repeat customer tags. Calculate new-customer ROAS (ncROAS) and new-customer CAC (nCAC) independently for Meta and Google spend.',
            'defaultImpact' => 'HIGH',
            'keyQuestion' => 'Is the difference documented and acceptable for decision-making?'
        ],
        10 => [
            'id' => 10,
            'category' => '05 — PERFORMANCE MEASUREMENT',
            'title' => 'CAC / ROAS consistency',
            'question' => 'Is there an agreed definition of CAC and ROAS across marketing, finance, and agencies?',
            'whyItMatters' => 'If the growth team defines ROAS using in-platform Meta attribution while finance defines it as Shopify Net Revenue divided by Total Ad Spend (MER), executive misalignment will freeze budget scaling discussions.',
            'whatToInvestigate' => 'Document a single shared "Marketing Metrics Dictionary" across growth, agency partners, and finance defining exact numerator and denominator inclusions (taxes, discounts, shipping, agency fees).',
            'defaultImpact' => 'HIGH',
            'keyQuestion' => 'Is the difference documented and acceptable for decision-making?'
        ],
        11 => [
            'id' => 11,
            'category' => '05 — PERFORMANCE MEASUREMENT',
            'title' => 'Cross-channel journey',
            'question' => 'Do you know how Meta and Google work together across the buyer journey before conversion?',
            'whyItMatters' => 'Last-click or siloed platform models starve top-of-funnel awareness channels (like TikTok or non-brand Meta) while over-rewarding bottom-funnel capturing channels (like Google Branded Search and Klaviyo flows).',
            'whatToInvestigate' => 'Map multi-touch conversion paths to evaluate channel synergy. Identify touchpoint sequences where Meta acts as the initial discoverer and Google acts as the final navigation converter.',
            'defaultImpact' => 'HIGH',
            'keyQuestion' => 'Is the difference documented and acceptable for decision-making?'
        ],
        12 => [
            'id' => 12,
            'category' => '06 — DECISION CONFIDENCE',
            'title' => 'Budget decision confidence',
            'question' => 'Does leadership trust the numbers enough to move $10,000-$50,000 between channels with confidence?',
            'whyItMatters' => 'This is the ultimate litmus test of attribution health. If your data cannot give leadership confidence to shift $10,000-$50,000 between channels, your reporting is merely a vanity dashboard rather than an operational steering wheel.',
            'whatToInvestigate' => 'Evaluate the uncertainty spread across your measurement stack. Design clean geo-lift or matched-market holdout tests to measure true marginal incrementality before making large portfolio reallocations.',
            'defaultImpact' => 'CRITICAL',
            'keyQuestion' => 'Can the team reproduce the same answer consistently?'
        ],
    ];

    // Build or extract findings
    $findings = [];
    $rawAnswersMap = [];
    foreach ($answers as $ans) {
        $qId = (int)($ans['questionId'] ?? 0);
        if ($qId > 0) {
            $rawAnswersMap[$qId] = $ans;
        }
    }

    $reliableCount = 0;
    $investigateCount = 0;
    $gapCount = 0;
    $unknownCount = 0;

    for ($i = 1; $i <= 12; $i++) {
        $qInfo = $questionsCatalog[$i];
        $ansRow = $rawAnswersMap[$i] ?? [];
        $rawAnswer = strtolower(trim($ansRow['answer'] ?? 'dont_know'));
        $rawStatus = strtoupper(trim($ansRow['status'] ?? ''));

        // Normalize status
        if ($rawStatus === 'RELIABLE' || ($rawStatus === '' && $rawAnswer === 'yes')) {
            $status = 'RELIABLE';
            $statusLabel = 'RELIABLE';
            $reliableCount++;
        } elseif (strpos($rawStatus, 'GAP') !== false || ($rawStatus === '' && $rawAnswer === 'no')) {
            $status = 'GAP';
            $statusLabel = 'ATTRIBUTION GAP';
            $gapCount++;
        } elseif ($rawStatus === 'INVESTIGATE' || ($rawStatus === '' && $rawAnswer === 'partly')) {
            $status = 'INVESTIGATE';
            $statusLabel = 'INVESTIGATE';
            $investigateCount++;
        } else {
            $status = 'UNKNOWN';
            $statusLabel = 'UNKNOWN';
            $unknownCount++;
        }

        // Answer label
        if ($rawAnswer === 'yes') {
            $answerLabel = 'YES';
        } elseif ($rawAnswer === 'partly') {
            $answerLabel = 'PARTLY';
        } elseif ($rawAnswer === 'no') {
            $answerLabel = 'NO';
        } else {
            $answerLabel = "DON'T KNOW";
        }

        $impact = strtoupper(trim($ansRow['budgetImpact'] ?? $qInfo['defaultImpact']));

        // Weight for sorting priority (critical gaps first)
        $impactWeight = match($impact) { 'CRITICAL' => 30, 'HIGH' => 20, default => 10 };
        $statusWeight = match($status) { 'GAP' => 35, 'INVESTIGATE' => 25, 'UNKNOWN' => 15, default => 5 };
        $sortScore = $impactWeight + $statusWeight + (15 - $i);

        $findings[] = [
            'id' => $i,
            'category' => $qInfo['category'],
            'title' => $qInfo['title'],
            'question' => $qInfo['question'],
            'whyItMatters' => $qInfo['whyItMatters'],
            'whatToInvestigate' => $qInfo['whatToInvestigate'],
            'keyQuestion' => $qInfo['keyQuestion'],
            'impact' => $impact,
            'status' => $status,
            'statusLabel' => $statusLabel,
            'answerLabel' => $answerLabel,
            'sortScore' => $sortScore
        ];
    }

    // Override counts if provided in analysis object
    if (is_array($analysis)) {
        if (isset($analysis['reliableCount'])) $reliableCount = (int)$analysis['reliableCount'];
        if (isset($analysis['investigateCount'])) $investigateCount = (int)$analysis['investigateCount'];
        if (isset($analysis['attributionGapCount'])) $gapCount = (int)$analysis['attributionGapCount'];
        if (isset($analysis['unknownCount'])) $unknownCount = (int)$analysis['unknownCount'];
    }

    // Sort findings by priority (highest risk on top)
    usort($findings, fn($a, $b) => $b['sortScore'] <=> $a['sortScore']);

    // Strategic finding headline & subtext
    if (!empty($analysis['biggestFindingHeadline'])) {
        $headline = $analysis['biggestFindingHeadline'];
        $subtext  = $analysis['biggestFindingSubtext'] ?? '';
    } elseif ($gapCount >= 2) {
        $headline = "{$gapCount} major attribution gaps are distorting your marketing budget decisions.";
        $subtext  = "Your audit indicates significant reporting divergence between Meta, Google, and Shopify. Reallocating budget right now carries substantial blind-spot risk until cross-channel deduplication and Shopify reconciliation are established.";
    } elseif ($gapCount === 1) {
        $headline = "1 critical area may affect how confidently your team allocates marketing budget.";
        $subtext  = "Your audit indicates that several parts of your attribution setup need investigation before using platform dashboards to justify significant cross-channel budget shifts.";
    } elseif ($investigateCount >= 3) {
        $headline = "{$investigateCount} reporting areas show variance that requires diagnostic clarity.";
        $subtext  = "While there are no catastrophic tracking breakages, numbers between ad managers and Shopify diverge enough to create friction between marketing and finance during scale planning.";
    } elseif ($unknownCount >= 2) {
        $headline = "{$unknownCount} unverified attribution variables prevent confident performance measurement.";
        $subtext  = "Your team lacks visibility into key tracking mechanics (such as lookback windows or deduplication). An attribution audit should be conducted to establish baseline truth.";
    } else {
        $headline = "Your attribution foundation shows strong alignment across channels.";
        $subtext  = "Platform reporting and Shopify data broadly reconcile. Your next priority is testing incremental lift and continuous reconciliation to safeguard scale.";
    }

    // Initialize PDF
    $pdf = new SimplePDF();
    $M   = $pdf->getMargin();          // 40
    $W   = $pdf->getPageWidth();       // 595.28
    $H   = $pdf->getPageHeight();      // 841.89
    $CW  = $pdf->getContentWidth();    // 515.28
    $totalPages = 8;

    // Helper footer function
    $drawPageFooter = function($pageNum) use ($pdf, $M, $CW, $totalPages, $generatedDate, $generatedTime) {
        $pdf->setDrawColor(226, 232, 240);
        $pdf->line($M, 805, $M + $CW, 805);
        $pdf->setTextColor(148, 163, 184);
        $pdf->text($M, 818, 'CMGalaxy - D2C Attribution Intelligence Platform  |  ' . $generatedDate . ' ' . $generatedTime, 7.5, false);
        $pdf->text($M + $CW - 35, 818, $pageNum . ' / ' . $totalPages, 7.5, false);
    };

    // =========================================================================
    // PAGE 1: OVERVIEW & STRATEGIC FINDINGS
    // =========================================================================
    // Top Eyebrow
    $pdf->setTextColor(79, 70, 229); // Indigo
    $pdf->text($M, 52, 'YOUR D2C ATTRIBUTION AUDIT', 8, true);

    // Title
    $pdf->setTextColor(15, 23, 42); // Dark navy
    $pdf->text($M, 75, "Here's where your reporting stands.", 19, true);

    // Subtitle
    $pdf->setTextColor(100, 116, 139); // Slate grey
    $pdf->text($M, 93, 'Prepared for: ' . $userName . ' - ' . $userCompany . '  |  Audit ID: ' . $auditId, 9, false);

    // 4 Outcome Summary Cards (2x2 Grid)
    $gridW = ($CW - 14) / 2; // ~250.6
    $gridH = 64;

    // Row 1
    // Card 1: Reliable
    $pdf->setFillColor(255, 255, 255);
    $pdf->setDrawColor(226, 232, 240);
    $pdf->roundedRect($M, 115, $gridW, $gridH, 6, 'FD');
    $pdf->setTextColor(16, 185, 129); // Green
    $pdf->text($M + 12, 131, '✓ RELIABLE', 8, true);
    $pdf->text($M + 12, 153, $reliableCount . ' areas', 16, true);
    $pdf->setTextColor(100, 116, 139);
    $pdf->text($M + 12, 168, 'Tracking & reporting reconcile', 8, false);

    // Card 2: Investigate
    $pdf->setFillColor(255, 255, 255);
    $pdf->roundedRect($M + $gridW + 14, 115, $gridW, $gridH, 6, 'FD');
    $pdf->setTextColor(245, 158, 11); // Amber
    $pdf->text($M + $gridW + 26, 131, '◐ INVESTIGATE', 8, true);
    $pdf->text($M + $gridW + 26, 153, $investigateCount . ' areas', 16, true);
    $pdf->setTextColor(100, 116, 139);
    $pdf->text($M + $gridW + 26, 168, 'Numbers differ; reason understood', 8, false);

    // Row 2
    // Card 3: Attribution Gaps
    $pdf->setFillColor(255, 255, 255);
    $pdf->roundedRect($M, 190, $gridW, $gridH, 6, 'FD');
    $pdf->setTextColor(239, 68, 68); // Red
    $pdf->text($M + 12, 206, '! ATTRIBUTION GAPS', 8, true);
    $pdf->text($M + 12, 228, $gapCount . ' areas', 16, true);
    $pdf->setTextColor(100, 116, 139);
    $pdf->text($M + 12, 243, 'Materially affects budget decisions', 8, false);

    // Card 4: Unknown
    $pdf->setFillColor(255, 255, 255);
    $pdf->roundedRect($M + $gridW + 14, 190, $gridW, $gridH, 6, 'FD');
    $pdf->setTextColor(100, 116, 139); // Slate
    $pdf->text($M + $gridW + 26, 206, '? UNKNOWN', 8, true);
    $pdf->setTextColor(51, 65, 85);
    $pdf->text($M + $gridW + 26, 228, $unknownCount . ' areas', 16, true);
    $pdf->setTextColor(100, 116, 139);
    $pdf->text($M + $gridW + 26, 243, 'Team cannot answer with confidence', 8, false);

    // Strategic Finding Card
    $pdf->setFillColor(248, 250, 252);
    $pdf->setDrawColor(226, 232, 240);
    $pdf->roundedRect($M, 270, $CW, 105, 6, 'FD');

    $pdf->setTextColor(217, 119, 6);
    $pdf->text($M + 14, 288, '⚡ STRATEGIC ATTRIBUTION FINDING', 8, true);

    $pdf->setTextColor(15, 23, 42);
    $pdf->text($M + 14, 308, $headline, 12, true);

    $pdf->setTextColor(71, 85, 105);
    $pdf->multiText($M + 14, 326, $CW - 28, $subtext, 8.5, false, 12.5);

    // Section Header: Priority areas to investigate
    $pdf->setTextColor(15, 23, 42);
    $pdf->text($M, 410, 'Priority areas to investigate', 17, true);
    $pdf->setTextColor(100, 116, 139);
    $pdf->text($M, 428, 'Ranked by budget decision risk and attribution status', 9.5, false);

    // Intro explanation box on page 1
    $pdf->setFillColor(255, 255, 255);
    $pdf->setDrawColor(226, 232, 240);
    $pdf->roundedRect($M, 448, $CW, 95, 6, 'FD');
    $pdf->setTextColor(15, 23, 42);
    $pdf->text($M + 14, 468, 'Diagnostic Framework Overview', 10, true);
    $pdf->setTextColor(71, 85, 105);
    $pdf->multiText($M + 14, 484, $CW - 28, 'The following pages break down each of the 12 attribution areas evaluated in your audit. For each finding, we highlight Why It Matters from a unit economics perspective, What To Investigate within your ad platforms and Shopify data, and the core diagnostic prompt your team can use during weekly growth reviews.', 8.5, false, 12);

    $drawPageFooter(1);

    // =========================================================================
    // PAGES 2 - 7: FINDING CARDS (2 per page = 12 findings across 6 pages)
    // =========================================================================
    $cardH = 360;
    $yPositions = [42, 416]; // Top card and bottom card

    $findingChunks = array_chunk($findings, 2);
    foreach ($findingChunks as $pageIdx => $chunk) {
        $pdf->addPage();
        $currPage = 2 + $pageIdx;

        foreach ($chunk as $cardIdx => $f) {
            $cardY = $yPositions[$cardIdx];

            // Outer Card Frame
            $pdf->setFillColor(255, 255, 255);
            $pdf->setDrawColor(226, 232, 240);
            $pdf->roundedRect($M, $cardY, $CW, $cardH, 6, 'FD');

            // Top Status Badge Pill
            [$badgeBg, $badgeBorder, $badgeText] = match($f['status']) {
                'RELIABLE'    => [[236, 253, 245], [16, 185, 129], [5, 150, 105]],
                'INVESTIGATE' => [[254, 243, 199], [245, 158, 11], [180, 83, 9]],
                'GAP'         => [[254, 242, 242], [239, 68, 68],  [220, 38, 38]],
                default       => [[241, 245, 249], [148, 163, 184], [71, 85, 105]]
            };
            $pdf->setFillColor($badgeBg[0], $badgeBg[1], $badgeBg[2]);
            $pdf->setDrawColor($badgeBorder[0], $badgeBorder[1], $badgeBorder[2]);
            $pdf->roundedRect($M + 12, $cardY + 12, 100, 16, 4, 'FD');
            $pdf->setTextColor($badgeText[0], $badgeText[1], $badgeText[2]);
            $pdf->text($M + 18, $cardY + 23, $f['statusLabel'], 7.5, true);

            // Category text
            $pdf->setTextColor(100, 116, 139);
            $pdf->text($M + 120, $cardY + 23, $f['category'], 7.5, false);

            // Budget Impact badge on right
            $impactColor = ($f['impact'] === 'CRITICAL' || $f['impact'] === 'HIGH') ? [220, 38, 38] : [180, 83, 9];
            $pdf->setTextColor($impactColor[0], $impactColor[1], $impactColor[2]);
            $pdf->text($M + $CW - 130, $cardY + 23, 'BUDGET IMPACT: ' . $f['impact'], 7.5, true);

            // Finding Title
            $pdf->setTextColor(15, 23, 42);
            $pdf->text($M + 12, $cardY + 46, $f['title'], 13, true);

            // User Answer Strip
            $pdf->setTextColor(100, 116, 139);
            $pdf->text($M + 12, $cardY + 63, 'You answered:', 8, false);

            $pdf->setFillColor(241, 245, 249);
            $pdf->setDrawColor(203, 213, 225);
            $pdf->roundedRect($M + 78, $cardY + 54, 75, 13, 3, 'FD');
            $pdf->setTextColor(15, 23, 42);
            $pdf->text($M + 84, $cardY + 63, $f['answerLabel'], 7.5, true);

            // Inner Inset Box (Why It Matters & What To Investigate)
            $pdf->setFillColor(248, 250, 252);
            $pdf->setDrawColor(226, 232, 240);
            $pdf->roundedRect($M + 12, $cardY + 74, $CW - 24, 244, 4, 'FD');

            // Why It Matters
            $pdf->setTextColor(71, 85, 105);
            $pdf->text($M + 22, $cardY + 90, 'WHY IT MATTERS', 8, true);
            $pdf->setTextColor(51, 65, 85);
            $pdf->multiText($M + 22, $cardY + 103, $CW - 44, $f['whyItMatters'], 8.5, false, 12);

            // What To Investigate
            $pdf->setTextColor(71, 85, 105);
            $pdf->text($M + 22, $cardY + 176, 'WHAT TO INVESTIGATE', 8, true);
            $pdf->setTextColor(51, 65, 85);
            $pdf->multiText($M + 22, $cardY + 189, $CW - 44, $f['whatToInvestigate'], 8.5, false, 12);

            // Diagnostic prompt footer
            $pdf->setTextColor(100, 116, 139);
            $pdf->text($M + 14, $cardY + 338, 'Diagnostic prompt: "' . $f['keyQuestion'] . '"', 7.5, false);
        }

        $drawPageFooter($currPage);
    }

    // =========================================================================
    // PAGE 8: THREE POST-AUDIT QUESTIONS & CMGALAXY CONVERSION
    // =========================================================================
    $pdf->addPage();

    // Section Header
    $pdf->setTextColor(15, 23, 42);
    $pdf->text($M, 52, 'Three questions to discuss after the audit', 18, true);
    $pdf->setTextColor(100, 116, 139);
    $pdf->text($M, 70, 'Use these prompts in your next growth, marketing, or executive budget meeting.', 9.5, false);

    // Discussion Card 1
    $pdf->setFillColor(255, 255, 255);
    $pdf->setDrawColor(226, 232, 240);
    $pdf->roundedRect($M, 90, $CW, 84, 6, 'FD');
    $pdf->setTextColor(2, 132, 199);
    $pdf->text($M + 16, 116, '01', 17, true);
    $pdf->setTextColor(15, 23, 42);
    $pdf->text($M + 48, 112, 'Where do Meta, Google and Shopify disagree the most?', 11, true);
    $pdf->setTextColor(71, 85, 105);
    $pdf->multiText($M + 48, 128, $CW - 60, 'Pinpoint whether the variance is in conversion counts, gross vs net revenue, or lookback window assumptions.', 8.5, false, 12);

    // Discussion Card 2
    $pdf->setFillColor(255, 255, 255);
    $pdf->setDrawColor(226, 232, 240);
    $pdf->roundedRect($M, 186, $CW, 84, 6, 'FD');
    $pdf->setTextColor(2, 132, 199);
    $pdf->text($M + 16, 212, '02', 17, true);
    $pdf->setTextColor(15, 23, 42);
    $pdf->text($M + 48, 208, 'Which disagreement is most likely to change a real budget decision?', 11, true);
    $pdf->setTextColor(71, 85, 105);
    $pdf->multiText($M + 48, 224, $CW - 60, 'Focus on high-impact areas like double attribution and new vs returning customer split before minor discrepancies.', 8.5, false, 12);

    // Discussion Card 3
    $pdf->setFillColor(255, 255, 255);
    $pdf->setDrawColor(226, 232, 240);
    $pdf->roundedRect($M, 282, $CW, 84, 6, 'FD');
    $pdf->setTextColor(2, 132, 199);
    $pdf->text($M + 16, 308, '03', 17, true);
    $pdf->setTextColor(15, 23, 42);
    $pdf->text($M + 48, 304, 'What would your team need to see before trusting one cross-channel view?', 11, true);
    $pdf->setTextColor(71, 85, 105);
    $pdf->multiText($M + 48, 320, $CW - 60, 'Determine what unified validation standard (Shopify order reconciliation, incrementality test, or single dashboard) would satisfy both marketing and finance.', 8.5, false, 12);

    // Conversion Banner Box (Dark Navy)
    $pdf->setFillColor(15, 23, 42);
    $pdf->setDrawColor(30, 41, 59);
    $pdf->roundedRect($M, 386, $CW, 160, 8, 'FD');

    $pdf->setTextColor(52, 199, 89);
    $pdf->text($M + 20, 412, 'READY TO FIX THESE ATTRIBUTION GAPS?', 8, true);

    $pdf->setTextColor(255, 255, 255);
    $pdf->text($M + 20, 434, 'Turn attribution gaps into clearer marketing decisions.', 14, true);

    $pdf->setTextColor(148, 163, 184);
    $pdf->multiText($M + 20, 452, $CW - 40, 'CMGalaxy helps D2C teams bring marketing and commerce data together, identify reporting gaps, and build a clearer view of what is driving revenue across channels.', 8.5, false, 13);

    // Buttons inside Banner
    // Button 1: Book a Demo
    $pdf->setFillColor(52, 199, 89);
    $pdf->setDrawColor(52, 199, 89);
    $pdf->roundedRect($M + 20, 488, 130, 26, 13, 'FD');
    $pdf->setTextColor(255, 255, 255);
    $pdf->text($M + 46, 504, 'Book a Demo', 9, true);

    // Button 2: Sign up for free
    $pdf->setFillColor(15, 23, 42);
    $pdf->setDrawColor(255, 255, 255);
    $pdf->roundedRect($M + 164, 488, 140, 26, 13, 'FD');
    $pdf->setTextColor(255, 255, 255);
    $pdf->text($M + 192, 504, 'Sign up for free', 9, true);

    // Links below
    $pdf->setTextColor(148, 163, 184);
    $pdf->text($M + 20, 532, 'Cal.com: https://cal.com/cmgalaxy/demo  |  Platform: https://platform.cmgalaxy.com/sign-up', 7.5, false);

    $drawPageFooter(8);

    return $pdf->output();
}

// =========================================================================
// 7. ROBUST SMTP SOCKET SENDER
// =========================================================================
function sendSmtpMail($toEmail, $subject, $htmlBody) {
    $host = defined('SMTP_HOST') ? SMTP_HOST : '';
    $port = defined('SMTP_PORT') ? SMTP_PORT : 0;
    $user = defined('SMTP_USER') ? SMTP_USER : '';
    $pass = defined('SMTP_PASS') ? SMTP_PASS : '';
    $fromName = (defined('FROM_NAME') && FROM_NAME !== '') ? FROM_NAME : $user;

    // Strict validation: Only send if all credentials exist in .env
    if (empty($host) || empty($port) || empty($user) || empty($pass)) {
        return [
            'success' => false,
            'error' => 'SMTP credentials missing. Please define SMTP_HOST, SMTP_PORT, SMTP_USER, and SMTP_PASS in .env file.'
        ];
    }

    $timeout = 15;
    $isSSL = ($port == 465);
    $connectHost = ($isSSL ? "ssl://{$host}" : "tcp://{$host}");

    // SSL context with self-signed & hostname tolerance for custom mail servers
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    $socket = @stream_socket_client("{$connectHost}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        return ['success' => false, 'error' => "Cannot connect to SMTP {$host}:{$port} - {$errstr} ({$errno})"];
    }

    stream_set_timeout($socket, $timeout);

    $read = function() use ($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) == ' ') break;
        }
        return $response;
    };

    $send = function($cmd) use ($socket) {
        fputs($socket, $cmd . "\r\n");
    };

    $read(); // Initial 220 banner

    $send("EHLO " . gethostname());
    $ehloRes = $read();

    // STARTTLS for Port 587
    if ($port == 587 && stripos($ehloRes, 'STARTTLS') !== false) {
        $send("STARTTLS");
        $tlsRes = $read();
        if (substr($tlsRes, 0, 3) != '220') {
            fclose($socket);
            return ['success' => false, 'error' => 'STARTTLS failed: ' . trim($tlsRes)];
        }

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        $cryptoOk = @stream_socket_enable_crypto($socket, true, $cryptoMethod);
        if (!$cryptoOk) {
            fclose($socket);
            return ['success' => false, 'error' => 'TLS crypto handshake failed with mail server'];
        }

        $send("EHLO " . gethostname());
        $read();
    }

    // AUTH LOGIN
    $send("AUTH LOGIN");
    $authInit = $read();
    if (substr($authInit, 0, 3) != '334') {
        fclose($socket);
        return ['success' => false, 'error' => 'AUTH LOGIN rejected: ' . trim($authInit)];
    }

    $send(base64_encode($user));
    $userRes = $read();
    if (substr($userRes, 0, 3) != '334') {
        fclose($socket);
        return ['success' => false, 'error' => 'Username rejected: ' . trim($userRes)];
    }

    $send(base64_encode($pass));
    $authRes = $read();
    if (substr($authRes, 0, 3) != '235') {
        fclose($socket);
        return ['success' => false, 'error' => 'SMTP Authentication failed: ' . trim($authRes)];
    }

    // MAIL FROM
    $send("MAIL FROM: <{$user}>");
    $fromRes = $read();
    if (substr($fromRes, 0, 3) != '250') {
        fclose($socket);
        return ['success' => false, 'error' => 'MAIL FROM rejected: ' . trim($fromRes)];
    }

    // RCPT TO
    $send("RCPT TO: <{$toEmail}>");
    $rcptRes = $read();
    if (substr($rcptRes, 0, 3) != '250') {
        fclose($socket);
        return ['success' => false, 'error' => 'Recipient <' . $toEmail . '> rejected: ' . trim($rcptRes)];
    }

    // DATA
    $send("DATA");
    $dataInit = $read();
    if (substr($dataInit, 0, 3) != '354') {
        fclose($socket);
        return ['success' => false, 'error' => 'DATA initiation rejected: ' . trim($dataInit)];
    }

    $headers = [
        "MIME-Version: 1.0",
        "Content-type: text/html; charset=UTF-8",
        "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$user}>",
        "To: <{$toEmail}>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "Date: " . date('r'),
        "X-Mailer: CMGalaxy Attribution Audit Mailer"
    ];

    $emailContent = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.";
    $send($emailContent);
    $dataRes = $read();

    $send("QUIT");
    fclose($socket);

    if (substr($dataRes, 0, 3) == '250') {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => 'Failed to deliver message: ' . trim($dataRes)];
    }
}
