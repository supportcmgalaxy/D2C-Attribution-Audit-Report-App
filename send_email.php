<?php
require_once __DIR__ . '/lib/SimplePDF.php';
/**
 * CMGalaxy - SMTP Email Dispatcher for Audit Reports
 * v1.1 - Deployed via GitHub Actions CI/CD
 */

// Suppress raw PHP errors from breaking JSON output
error_reporting(0);
ini_set('display_errors', '0');

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
    // 1. SMTP CONFIGURATION
    // =========================================================================
    define('SMTP_HOST', 'mail.cybermedia.co.in');
    define('SMTP_PORT', 587);
    define('SMTP_USER', 'abubakarm@cmrsl.net');
    define('SMTP_PASS', 'Ym#8$ar@1');
    define('FROM_NAME', 'CMGalaxy Support');
    define('ADMIN_EMAIL', 'abubakarm@cmrsl.net');

    // Base URL of your site (no trailing slash) — used to build the public report link
    define('SITE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

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
    $pdfBytes = generateReportPdf($userName, $userEmail, $userCompany, $auditId, $submittedAt, $answers);
    $written  = file_put_contents($reportFilePath, $pdfBytes);
    if ($written !== false) {
        $reportSaveResult = ['saved' => true, 'path' => $reportFilePath, 'url' => $reportPublicUrl, 'filename' => $reportFileName, 'error' => null];
    } else {
        $reportSaveResult['error'] = 'Could not write PDF to: ' . $reportFilePath;
        $reportPublicUrl = null; // No URL if save failed
    }



    // =========================================================================
    // 3. USER EMAIL CONTENT
    // =========================================================================
    $userSubject = "Your CMGalaxy D2C Attribution Audit Report — " . $userCompany;

    // Build the CTA button for the PDF report link (only if the report was saved successfully)
    $reportLinkBlock = '';
    if ($reportPublicUrl) {
        $reportLinkBlock = "
          <div style='text-align: center; margin: 28px 0;'>
            <a href='{$reportPublicUrl}'
               style='display: inline-block; background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
                      color: #ffffff; text-decoration: none; padding: 14px 32px;
                      border-radius: 8px; font-size: 15px; font-weight: 600;
                      letter-spacing: 0.3px; box-shadow: 0 4px 14px rgba(15,23,42,0.25);'>
              &#128202; Download Your Attribution Report (PDF) &rarr;
            </a>
          </div>
          <p style='font-size: 12px; color: #94a3b8; text-align: center; margin-top: -12px;'>
            Or copy this link: <a href='{$reportPublicUrl}' style='color: #0284c7;'>{$reportPublicUrl}</a>
          </p>";
    }

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
          <p style='font-size: 15px; color: #334155;'>Thank you for completing the 12-Point D2C Attribution Audit. Your personalized diagnostic report is ready!</p>

          {$reportLinkBlock}

          <div style='background: #f1f5f9; padding: 16px; border-radius: 8px; margin: 20px 0;'>
            <p style='margin: 0 0 8px 0; font-size: 13px; color: #64748b;'><strong>Company:</strong> {$userCompany}</p>
            <p style='margin: 0 0 8px 0; font-size: 13px; color: #64748b;'><strong>Audit ID:</strong> {$auditId}</p>
            <p style='margin: 0; font-size: 13px; color: #64748b;'><strong>Generated On:</strong> {$dateSlug}</p>
          </div>

          <p style='margin-top: 24px; font-size: 14px; color: #64748b;'>Our team of attribution specialists is reviewing your findings and will be in touch if your setup requires priority calibration.</p>

          <!-- CTA Buttons -->
          <div style='text-align: center; margin: 28px 0;'>
            <a href='https://cal.com/cmgalaxy/demo'
               style='display: inline-block; background: #0f172a; color: #ffffff;
                      text-decoration: none; padding: 12px 24px; border-radius: 8px;
                      font-size: 14px; font-weight: 600; margin: 6px 8px;
                      box-shadow: 0 2px 8px rgba(15,23,42,0.20);'>
              &#128197; Book a Demo
            </a>
            <a href='https://platform.cmgalaxy.com/sign-up'
               style='display: inline-block; background: #0284c7; color: #ffffff;
                      text-decoration: none; padding: 12px 24px; border-radius: 8px;
                      font-size: 14px; font-weight: 600; margin: 6px 8px;
                      box-shadow: 0 2px 8px rgba(2,132,199,0.25);'>
              &#128640; Sign Up Free
            </a>
          </div>

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
        $ch = curl_init($stagingUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $rawInput);
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
// 6. PDF REPORT GENERATOR (using zero-dependency SimplePDF class)
// =========================================================================
function generateReportPdf($userName, $userEmail, $userCompany, $auditId, $submittedAt, $answers): string {
    $generatedDate = date('F j, Y');
    $generatedTime = date('H:i');

    // Count statuses
    $reliableCount = $gapCount = $investigateCount = $unknownCount = 0;
    $answerData = [];
    foreach ($answers as $ans) {
        $qNum    = (string)($ans['questionId'] ?? '');
        $qTitle  = $ans['title'] ?? "Question #{$qNum}";
        $qAnswer = strtoupper($ans['answer'] ?? '');
        $qStatus = strtoupper($ans['status'] ?? '');
        $impact  = $ans['budgetImpact'] ?? '';
        switch ($qStatus) {
            case 'RELIABLE':    $reliableCount++;    break;
            case 'GAP':         $gapCount++;         break;
            case 'INVESTIGATE': $investigateCount++; break;
            default:            $unknownCount++;     break;
        }
        $answerData[] = compact('qNum', 'qTitle', 'qAnswer', 'qStatus', 'impact');
    }

    $total      = count($answers);
    $score      = $total > 0 ? (int)round(($reliableCount / $total) * 100) : 0;
    $grade      = $score >= 70 ? 'Strong Attribution' : ($score >= 40 ? 'Needs Attention' : 'Critical Gaps Found');

    $pdf = new SimplePDF();
    $M   = $pdf->getMargin();          // 40
    $W   = $pdf->getPageWidth();       // 595.28
    $CW  = $pdf->getContentWidth();    // 515.28
    $y   = $pdf->getY();

    // ---- HEADER BAND -------------------------------------------------------
    $pdf->setFillColor(15, 23, 42);           // #0f172a dark navy
    $pdf->rect($M, $y, $CW, 72, 'F');

    // Brand label
    $pdf->setTextColor(148, 163, 184);        // #94a3b8
    $pdf->text($M + 10, $y + 14, 'CMGALAXY', 8, false);

    // Title
    $pdf->setTextColor(255, 255, 255);
    $pdf->text($M + 10, $y + 27, 'D2C Attribution Audit Report', 14, true);

    // Subtitle
    $pdf->setTextColor(148, 163, 184);
    $pdf->text($M + 10, $y + 42, 'Prepared for: ' . $userCompany, 9, false);

    // Score
    if ($score >= 70) { $pdf->setTextColor(16, 185, 129); }
    elseif ($score >= 40) { $pdf->setTextColor(245, 158, 11); }
    else { $pdf->setTextColor(239, 68, 68); }
    $pdf->text($W - $M - 60, $y + 20, $score . '%', 22, true);
    $pdf->setTextColor(148, 163, 184);
    $pdf->text($W - $M - 60, $y + 46, $grade, 8, false);

    // ---- META BAR ----------------------------------------------------------
    $y += 72;
    $pdf->setFillColor(248, 250, 252);        // #f8fafc
    $pdf->rect($M, $y, $CW, 28, 'F');
    $pdf->setDrawColor(226, 232, 240);
    $pdf->line($M, $y, $M + $CW, $y);
    $pdf->line($M, $y + 28, $M + $CW, $y + 28);
    $pdf->setTextColor(100, 116, 139);        // #64748b
    $pdf->text($M + 8,  $y + 10, 'Name: ' . $userName,    8, false);
    $pdf->text($M + 8,  $y + 20, 'Email: ' . $userEmail,  8, false);
    $pdf->text($M + 200, $y + 10, 'Audit ID: ' . $auditId,     8, false);
    $pdf->text($M + 200, $y + 20, 'Date: ' . $generatedDate . ' ' . $generatedTime, 8, false);

    // ---- SCORE TILES -------------------------------------------------------
    $y += 40;
    $tileW  = ($CW - 18) / 4;  // 4 tiles with 6pt gaps
    $tileData = [
        ['Reliable',    $reliableCount,    [16,185,129]],
        ['Investigate', $investigateCount, [245,158,11]],
        ['Gaps',        $gapCount,         [239,68,68]],
        ['Unknown',     $unknownCount,     [148,163,184]],
    ];
    foreach ($tileData as $i => [$label, $count, $rgb]) {
        $tx = $M + $i * ($tileW + 6);
        // Tile bg
        $pdf->setFillColor(248, 250, 252);
        $pdf->rect($tx, $y, $tileW, 40, 'F');
        $pdf->setDrawColor(226, 232, 240);
        $pdf->rect($tx, $y, $tileW, 40, 'D');
        // Count (big number)
        $pdf->setTextColor(...$rgb);
        $pdf->text($tx + 10, $y + 18, (string)$count, 20, true);
        // Label
        $pdf->setTextColor(100, 116, 139);
        $pdf->text($tx + 10, $y + 32, $label . ' areas', 8, false);
    }

    // ---- TABLE HEADER -------------------------------------------------------
    $y += 52;
    $pdf->setFillColor(15, 23, 42);
    $pdf->rect($M, $y, $CW, 18, 'F');
    $pdf->setTextColor(255, 255, 255);
    $col1 = $M + 6;   $cw1 = 220;
    $col2 = $col1 + $cw1 + 4;   $cw2 = 60;
    $col3 = $col2 + $cw2 + 4;   $cw3 = 70;
    $col4 = $col3 + $cw3 + 4;
    $pdf->text($col1, $y + 12, 'AREA',          8, true);
    $pdf->text($col2, $y + 12, 'ANSWER',        8, true);
    $pdf->text($col3, $y + 12, 'STATUS',        8, true);
    $pdf->text($col4, $y + 12, 'BUDGET IMPACT', 8, true);

    // ---- TABLE ROWS --------------------------------------------------------
    $y += 18;
    $rowH = 22;
    foreach ($answerData as $idx => $row) {
        // Check if we need a new page
        if ($y + $rowH > $pdf->getPageHeight() - $M - 30) {
            $pdf->addPage();
            $y = $pdf->getMargin();
        }

        // Alternating row bg
        if ($idx % 2 === 0) {
            $pdf->setFillColor(248, 250, 252);
            $pdf->rect($M, $y, $CW, $rowH, 'F');
        } else {
            $pdf->setFillColor(255, 255, 255);
            $pdf->rect($M, $y, $CW, $rowH, 'F');
        }

        // Row border line
        $pdf->setDrawColor(226, 232, 240);
        $pdf->line($M, $y + $rowH, $M + $CW, $y + $rowH);

        // Area cell (question number + title, truncated)
        $pdf->setTextColor(15, 23, 42);
        $rowTitle = '#{' . $row['qNum'] . '} ' . $row['qTitle'];
        if (strlen($rowTitle) * 5 > $cw1) {
            $rowTitle = mb_substr($rowTitle, 0, 38) . '...';
        }
        $pdf->text($col1, $y + 14, $rowTitle, 8, false);

        // Answer cell
        $pdf->setTextColor(51, 65, 85);
        $pdf->text($col2, $y + 14, $row['qAnswer'], 8, true);

        // Status cell with colored text
        $statusColors = [
            'RELIABLE'    => [16, 185, 129],
            'GAP'         => [239, 68, 68],
            'INVESTIGATE' => [245, 158, 11],
        ];
        [$sr, $sg, $sb] = $statusColors[$row['qStatus']] ?? [148, 163, 184];
        $pdf->setTextColor($sr, $sg, $sb);
        $pdf->text($col3, $y + 14, $row['qStatus'], 8, true);

        // Impact cell
        $pdf->setTextColor(100, 116, 139);
        $impactText = $row['impact'];
        if (strlen($impactText) > 28) $impactText = mb_substr($impactText, 0, 25) . '...';
        $pdf->text($col4, $y + 14, $impactText, 7, false);

        $y += $rowH;
    }

    // ---- FOOTER ------------------------------------------------------------
    $y += 16;
    if ($y > $pdf->getPageHeight() - $M - 20) {
        $pdf->addPage();
        $y = $pdf->getMargin();
    }
    $pdf->setDrawColor(226, 232, 240);
    $pdf->line($M, $y, $M + $CW, $y);
    $y += 8;
    $pdf->setTextColor(148, 163, 184);
    $pdf->text($M, $y, 'Generated by CMGalaxy - D2C Attribution Intelligence Platform  |  ' . $generatedDate . ' at ' . $generatedTime, 8, false);

    return $pdf->output();
}

// =========================================================================
// 7. ROBUST SMTP SOCKET SENDER
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
