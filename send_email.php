<?php
/**
 * CMGalaxy - SMTP Email Dispatcher for Audit Reports
 * v1.1 - Deployed via GitHub Actions CI/CD
 */

// Suppress raw PHP errors from breaking JSON output
error_reporting(0);
ini_set('display_errors', '0');

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
    // 1. SMTP CONFIGURATION
    // =========================================================================
    define('SMTP_HOST', 'mail.cybermedia.co.in');
    define('SMTP_PORT', 587);
    define('SMTP_USER', 'abubakarm@cmrsl.net');
    define('SMTP_PASS', 'Ym#8$ar@1');
    define('FROM_NAME', 'CMGalaxy Support');
    define('ADMIN_EMAIL', 'support@cmgalaxy.com');

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
    // 2. INPUT DATA PROCESSING
    // =========================================================================
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!$data || !isset($data['lead'])) {
        echo json_encode([
            'success' => false,
            'error' => 'Invalid lead data received or empty request.'
        ]);
        exit;
    }

    $userName    = htmlspecialchars($data['lead']['name'] ?? 'User');
    $userEmail   = filter_var($data['lead']['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $userCompany = htmlspecialchars($data['lead']['company'] ?? 'N/A');
    $auditId     = htmlspecialchars($data['id'] ?? 'N/A');
    $submittedAt = htmlspecialchars($data['submittedAt'] ?? date('c'));
    $answers     = $data['answers'] ?? [];

    if (!$userEmail) {
        echo json_encode([
            'success' => false,
            'error' => 'Valid user email is required.'
        ]);
        exit;
    }

    // Build answers summary table
    $answersHtml = '';
    foreach ($answers as $ans) {
        $qNum = htmlspecialchars($ans['questionId'] ?? '');
        $qTitle = htmlspecialchars($ans['title'] ?? "Question #$qNum");
        $qAnswer = strtoupper(htmlspecialchars($ans['answer'] ?? ''));
        $qStatus = strtoupper(htmlspecialchars($ans['status'] ?? ''));
        $statusColor = ($qStatus === 'RELIABLE') ? '#10b981' : (($qStatus === 'GAP') ? '#ef4444' : '#f59e0b');

        $answersHtml .= "<tr>
            <td style='padding: 8px 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px;'>#{$qNum} {$qTitle}</td>
            <td style='padding: 8px 12px; border-bottom: 1px solid #e2e8f0; font-size: 14px; font-weight: bold;'>{$qAnswer}</td>
            <td style='padding: 8px 12px; border-bottom: 1px solid #e2e8f0; font-size: 13px; color: {$statusColor}; font-weight: bold;'>{$qStatus}</td>
        </tr>";
    }

    // =========================================================================
    // 3. USER EMAIL CONTENT
    // =========================================================================
    $userSubject = "Your CMGalaxy D2C Attribution Audit Report — " . $userCompany;

    $userBody = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px;'>
      <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;'>
        <div style='background: #0f172a; padding: 24px; color: #ffffff;'>
          <h2 style='margin: 0; font-size: 20px;'>CMGalaxy D2C Attribution Audit</h2>
          <p style='margin: 4px 0 0 0; color: #94a3b8; font-size: 14px;'>Audit Report for {$userCompany}</p>
        </div>
        <div style='padding: 24px;'>
          <p style='font-size: 15px; color: #334155;'>Hi <strong>{$userName}</strong>,</p>
          <p style='font-size: 15px; color: #334155;'>Thank you for completing the 12-Point D2C Attribution Audit. Your diagnostic report has been generated successfully.</p>
          
          <div style='background: #f1f5f9; padding: 16px; border-radius: 8px; margin: 20px 0;'>
            <p style='margin: 0 0 8px 0; font-size: 13px; color: #64748b;'><strong>Company:</strong> {$userCompany}</p>
            <p style='margin: 0 0 8px 0; font-size: 13px; color: #64748b;'><strong>Audit ID:</strong> {$auditId}</p>
            <p style='margin: 0; font-size: 13px; color: #64748b;'><strong>Submitted At:</strong> {$submittedAt}</p>
          </div>

          <p style='margin-top: 24px; font-size: 14px; color: #64748b;'>Our team of attribution specialists is reviewing your findings and will be in touch if your setup requires priority calibration.</p>
          <p style='margin-top: 16px; font-size: 14px; color: #334155;'>Best regards,<br><strong>CMGalaxy Support Team</strong></p>
        </div>
      </div>
    </body>
    </html>";

    // =========================================================================
    // 4. ADMIN EMAIL CONTENT
    // =========================================================================
    $adminSubject = "New D2C Attribution Audit Lead: " . $userName . " (" . $userCompany . ")";

    $adminBody = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px;'>
      <div style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;'>
        <div style='background: #0284c7; padding: 20px; color: #ffffff;'>
          <h2 style='margin: 0; font-size: 18px;'>⚡ New D2C Attribution Audit Lead Captured</h2>
        </div>
        <div style='padding: 24px;'>
          <h3 style='margin-top: 0; font-size: 16px; color: #0f172a;'>Lead Details:</h3>
          <ul style='color: #334155; font-size: 14px; line-height: 1.6;'>
            <li><strong>Full Name:</strong> {$userName}</li>
            <li><strong>Email:</strong> <a href='mailto:{$userEmail}'>{$userEmail}</a></li>
            <li><strong>Company:</strong> {$userCompany}</li>
            <li><strong>Audit ID:</strong> {$auditId}</li>
            <li><strong>Submitted At:</strong> {$submittedAt}</li>
          </ul>

          <h3 style='margin-top: 24px; font-size: 16px; color: #0f172a;'>Audit Responses:</h3>
          <table style='width: 100%; border-collapse: collapse; margin-top: 12px;'>
            <thead>
              <tr style='background: #f8fafc; text-align: left;'>
                <th style='padding: 8px 12px; font-size: 13px; color: #64748b;'>Area</th>
                <th style='padding: 8px 12px; font-size: 13px; color: #64748b;'>Answer</th>
                <th style='padding: 8px 12px; font-size: 13px; color: #64748b;'>Status</th>
              </tr>
            </thead>
            <tbody>
              {$answersHtml}
            </tbody>
          </table>
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

    echo json_encode([
        'success' => $userResult['success'],
        'userEmail' => [
            'sent' => $userResult['success'],
            'to' => $userEmail,
            'error' => $userResult['error'] ?? null
        ],
        'adminEmail' => [
            'sent' => $adminResult ? $adminResult['success'] : false,
            'to' => ADMIN_EMAIL,
            'error' => $adminResult['error'] ?? null
        ]
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
// 6. ROBUST SMTP SOCKET SENDER
// =========================================================================
function sendSmtpMail($toEmail, $subject, $htmlBody) {
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    $fromName = FROM_NAME;

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
