<?php
/**
 * CMGalaxy - D2C Attribution Audit Executive Admin Console
 * Version: 1.0
 * Features:
 *   - Secure Session Password Protection
 *   - Live Stats & Metrics (Leads, Today, Blocked IPs, OTPs)
 *   - Full Form Submissions (Leads) with PDF download links
 *   - 1-Click Excel / CSV Export with UTF-8 BOM
 *   - Blocked IPs Security Management & 1-Click Unblock
 *   - Live Rate Limit Monitor (3 submissions/day per IP)
 */

session_start();

// Timezone: IST (UTC+5:30)
date_default_timezone_set('Asia/Kolkata');

// Load .env secrets if present
if (file_exists(__DIR__ . '/.env')) {
    $envLines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\n\r\0\x0B\"'");
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }
}

// Configurable Admin Password (default: cmgalaxy@admin2026)
define('ADMIN_PASSWORD', getenv('ADMIN_PANEL_PASS') ?: 'cmgalaxy@admin2026');

// Directories
$reportsDir   = __DIR__ . '/reports';
$rateLimitDir = $reportsDir . '/.ratelimits';
$leadsJson    = $reportsDir . '/leads_log.json';
$leadsCsv     = $reportsDir . '/leads.csv';
$blockedJson  = $rateLimitDir . '/blocked_ips.json';

// Handle Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['cmg_admin_logged']);
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Handle Login
$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $enteredPass = trim($_POST['password'] ?? '');
    if ($enteredPass === ADMIN_PASSWORD) {
        $_SESSION['cmg_admin_logged'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $loginError = 'Invalid admin password. Please try again.';
    }
}

// Check Authentication
$isAuthenticated = !empty($_SESSION['cmg_admin_logged']);

// -----------------------------------------------------------------------------
// AUTHENTICATED ACTIONS: EXCEL EXPORT & IP UNBLOCK
// -----------------------------------------------------------------------------
if ($isAuthenticated) {
    // 1. Export Excel / CSV Action
    if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
        $filename = 'CMGalaxy_Attribution_Leads_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM for Microsoft Excel compatibility
        fputs($out, "\xEF\xBB\xBF");

        // Header Row
        fputcsv($out, [
            'Submission Date (IST)',
            'Full Name',
            'First Name',
            'Last Name',
            'Company / Brand',
            'Work Email',
            'Phone Number',
            'Client IP',
            'Audit Risk Level',
            'Reliable Areas',
            'Attribution Gaps',
            'PDF Report File'
        ]);

        $leads = file_exists($leadsJson) ? json_decode(@file_get_contents($leadsJson), true) : [];
        if (!empty($leads) && is_array($leads)) {
            foreach ($leads as $l) {
                fputcsv($out, [
                    $l['date'] ?? '',
                    $l['fullName'] ?? '',
                    $l['firstName'] ?? '',
                    $l['lastName'] ?? '',
                    $l['company'] ?? '',
                    $l['email'] ?? '',
                    $l['phone'] ?? '',
                    $l['ip'] ?? '',
                    $l['riskLevel'] ?? 'Moderate',
                    $l['reliableCount'] ?? 0,
                    $l['gapsCount'] ?? 0,
                    $l['pdfFilename'] ?? ''
                ]);
            }
        } elseif (file_exists($leadsCsv)) {
            // Fallback: Read from existing leads.csv
            $fp = fopen($leadsCsv, 'r');
            while (($row = fgetcsv($fp)) !== false) {
                fputcsv($out, $row);
            }
            fclose($fp);
        }

        fclose($out);
        exit;
    }

    // 2. Unblock IP Action
    if (isset($_GET['action']) && $_GET['action'] === 'unblock' && !empty($_GET['ip'])) {
        $unblockIp = trim($_GET['ip']);
        if (file_exists($blockedJson)) {
            $blockedList = json_decode(@file_get_contents($blockedJson), true);
            if (is_array($blockedList) && isset($blockedList[$unblockIp])) {
                unset($blockedList[$unblockIp]);
                file_put_contents($blockedJson, json_encode($blockedList, JSON_PRETTY_PRINT));
            }
        }
        // Also reset the rate limit submit tracker for this IP
        $ipHash = md5($unblockIp);
        $submitFile = $rateLimitDir . '/submit_' . $ipHash . '.json';
        if (file_exists($submitFile)) {
            @unlink($submitFile);
        }
        $otpFile = $rateLimitDir . '/otp_' . $ipHash . '.json';
        if (file_exists($otpFile)) {
            @unlink($otpFile);
        }
        header('Location: admin.php?tab=security&msg=unblocked');
        exit;
    }

    // 3. Manual Block IP Action
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_block') {
        $blockIp = trim($_POST['ip'] ?? '');
        $reason = trim($_POST['reason'] ?? 'Manual admin block');
        if (filter_var($blockIp, FILTER_VALIDATE_IP)) {
            if (!is_dir($rateLimitDir)) @mkdir($rateLimitDir, 0755, true);
            $blockedList = file_exists($blockedJson) ? json_decode(@file_get_contents($blockedJson), true) : [];
            if (!is_array($blockedList)) $blockedList = [];

            $now = time();
            $blockedList[$blockIp] = [
                'ip' => $blockIp,
                'reason' => $reason,
                'attempts' => 99,
                'blocked_at' => $now,
                'expires_at' => $now + 86400,
                'active' => true
            ];
            file_put_contents($blockedJson, json_encode($blockedList, JSON_PRETTY_PRINT));
            header('Location: admin.php?tab=security&msg=blocked');
            exit;
        }
    }
}

// Load Data for Dashboard
$leadsList = [];
if (file_exists($leadsJson)) {
    $leadsList = json_decode(@file_get_contents($leadsJson), true) ?: [];
}

$blockedList = [];
if (file_exists($blockedJson)) {
    $blockedList = json_decode(@file_get_contents($blockedJson), true) ?: [];
}

// Active blocked count
$now = time();
$activeBlockedCount = 0;
foreach ($blockedList as $b) {
    if (!empty($b['active']) && $now < ($b['expires_at'] ?? 0)) {
        $activeBlockedCount++;
    }
}

// Today's leads count (last 24 hours)
$todayLeadsCount = 0;
foreach ($leadsList as $l) {
    if (($l['timestamp'] ?? 0) >= ($now - 86400)) {
        $todayLeadsCount++;
    }
}

// Active IP track list
$activeTrackers = [];
if (is_dir($rateLimitDir)) {
    $files = glob($rateLimitDir . '/submit_*.json');
    foreach ($files as $f) {
        $data = json_decode(@file_get_contents($f), true);
        if ($data && ($now - ($data['start'] ?? 0)) < 86400) {
            $activeTrackers[] = $data;
        }
    }
}

$currentTab = $_GET['tab'] ?? 'leads';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CMGalaxy Admin Console | D2C Attribution Leads & Security</title>
  
  <!-- Favicon -->
  <link rel="icon" type="image/webp" href="assets/favicons.webp">

  <!-- Google Fonts: Onest & JetBrains Mono -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">

  <style>
    :root {
      --bg-dark: #0B0F19;
      --bg-card: #111827;
      --bg-card-hover: #1F2937;
      --border-color: #1F293D;
      --color-primary: #3A7DFF;
      --color-primary-hover: #2563EB;
      --color-success: #10B981;
      --color-warning: #F59E0B;
      --color-danger: #EF4444;
      --text-main: #F3F4F6;
      --text-muted: #9CA3AF;
      --font-family: 'Onest', -apple-system, BlinkMacSystemFont, sans-serif;
      --font-mono: 'JetBrains Mono', monospace;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: var(--font-family);
      background-color: var(--bg-dark);
      color: var(--text-main);
      min-height: 100vh;
      -webkit-font-smoothing: antialiased;
      line-height: 1.5;
    }

    /* Container */
    .admin-container {
      max-width: 1320px;
      margin: 0 auto;
      padding: 24px 20px 60px;
    }

    /* Top Header */
    .admin-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding-bottom: 24px;
      border-bottom: 1px solid var(--border-color);
      margin-bottom: 28px;
      gap: 16px;
      flex-wrap: wrap;
    }

    .brand-group {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .brand-logo-img {
      height: 30px;
      width: auto;
      display: block;
    }

    .admin-title-wrap {
      display: flex;
      flex-direction: column;
    }

    .admin-title {
      font-size: 19px;
      font-weight: 800;
      letter-spacing: -0.01em;
      color: #FFFFFF;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .admin-badge-role {
      background: rgba(58, 125, 255, 0.15);
      border: 1px solid rgba(58, 125, 255, 0.4);
      color: #93C5FD;
      font-size: 11px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 4px;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }

    .admin-subtext {
      font-size: 13px;
      color: var(--text-muted);
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-family: var(--font-family);
      font-size: 13px;
      font-weight: 600;
      padding: 9px 16px;
      border-radius: 8px;
      border: 1px solid transparent;
      cursor: pointer;
      text-decoration: none;
      transition: all 180ms ease;
      white-space: nowrap;
    }

    .btn-excel {
      background: #064E3B;
      color: #A7F3D0;
      border-color: #059669;
    }
    .btn-excel:hover {
      background: #047857;
      color: #FFFFFF;
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
    }

    .btn-primary {
      background: var(--color-primary);
      color: #FFFFFF;
    }
    .btn-primary:hover {
      background: var(--color-primary-hover);
      box-shadow: 0 4px 14px rgba(58, 125, 255, 0.35);
    }

    .btn-outline {
      background: rgba(255, 255, 255, 0.05);
      border-color: var(--border-color);
      color: var(--text-main);
    }
    .btn-outline:hover {
      background: rgba(255, 255, 255, 0.1);
      border-color: #374151;
    }

    .btn-danger {
      background: rgba(239, 68, 68, 0.15);
      border-color: rgba(239, 68, 68, 0.4);
      color: #FCA5A5;
      padding: 5px 10px;
      font-size: 12px;
    }
    .btn-danger:hover {
      background: var(--color-danger);
      color: #FFFFFF;
    }

    .btn-unblock {
      background: rgba(16, 185, 129, 0.15);
      border-color: rgba(16, 185, 129, 0.4);
      color: #6EE7B7;
      padding: 5px 12px;
      font-size: 12px;
    }
    .btn-unblock:hover {
      background: var(--color-success);
      color: #FFFFFF;
    }

    /* KPI Grid */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
      margin-bottom: 30px;
    }

    .kpi-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 20px;
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .kpi-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--color-primary), #60A5FA);
    }
    .kpi-card.kpi-warning::before {
      background: linear-gradient(90deg, var(--color-warning), #FCD34D);
    }
    .kpi-card.kpi-danger::before {
      background: linear-gradient(90deg, var(--color-danger), #F87171);
    }
    .kpi-card.kpi-success::before {
      background: linear-gradient(90deg, var(--color-success), #34D399);
    }

    .kpi-label {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .kpi-value {
      font-size: 32px;
      font-weight: 800;
      color: #FFFFFF;
      font-family: var(--font-mono);
      line-height: 1.1;
    }

    .kpi-footer {
      font-size: 12px;
      color: var(--text-muted);
      margin-top: 4px;
    }

    /* Tabs Bar */
    .admin-tabs {
      display: flex;
      gap: 8px;
      border-bottom: 1px solid var(--border-color);
      margin-bottom: 24px;
      overflow-x: auto;
    }

    .tab-btn {
      padding: 12px 18px;
      background: none;
      border: none;
      border-bottom: 2px solid transparent;
      color: var(--text-muted);
      font-family: var(--font-family);
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all 160ms ease;
    }

    .tab-btn:hover {
      color: #FFFFFF;
    }

    .tab-btn.active {
      color: #FFFFFF;
      border-bottom-color: var(--color-primary);
    }

    .tab-counter {
      background: rgba(255, 255, 255, 0.1);
      padding: 2px 7px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 700;
    }

    /* Controls Bar */
    .table-controls {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 16px;
      gap: 12px;
      flex-wrap: wrap;
    }

    .search-box-wrap {
      position: relative;
      flex: 1;
      max-width: 380px;
    }

    .search-input {
      width: 100%;
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: 8px;
      padding: 10px 14px 10px 38px;
      color: var(--text-main);
      font-family: var(--font-family);
      font-size: 13.5px;
      outline: none;
    }
    .search-input:focus {
      border-color: var(--color-primary);
    }

    .search-icon {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      pointer-events: none;
    }

    /* Table Container */
    .table-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }

    .data-table th {
      background: #0E1424;
      padding: 12px 16px;
      font-size: 11.5px;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
      border-bottom: 1px solid var(--border-color);
    }

    .data-table td {
      padding: 14px 16px;
      font-size: 13.5px;
      border-bottom: 1px solid rgba(31, 41, 61, 0.6);
      color: #E2E8F0;
      vertical-align: middle;
    }

    .data-table tbody tr:hover {
      background: var(--bg-card-hover);
    }

    .ip-badge {
      font-family: var(--font-mono);
      font-size: 12px;
      background: rgba(255, 255, 255, 0.06);
      border: 1px solid rgba(255, 255, 255, 0.1);
      padding: 2px 7px;
      border-radius: 4px;
      color: #93C5FD;
      display: inline-block;
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 9px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }

    .status-blocked {
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.4);
      color: #F87171;
    }

    .status-active {
      background: rgba(16, 185, 129, 0.15);
      border: 1px solid rgba(16, 185, 129, 0.4);
      color: #34D399;
    }

    .risk-badge {
      font-size: 11px;
      font-weight: 700;
      padding: 3px 8px;
      border-radius: 4px;
    }
    .risk-low { background: #064E3B; color: #6EE7B7; }
    .risk-moderate { background: #78350F; color: #FCD34D; }
    .risk-high { background: #7F1D1D; color: #FCA5A5; }
    .risk-critical { background: #881337; color: #FDA4AF; }

    .btn-pdf-link {
      color: #38BDF8;
      text-decoration: none;
      font-weight: 600;
      font-size: 12.5px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 8px;
      background: rgba(56, 189, 248, 0.1);
      border-radius: 6px;
      transition: background 150ms ease;
    }
    .btn-pdf-link:hover {
      background: rgba(56, 189, 248, 0.2);
      text-decoration: underline;
    }

    .empty-state {
      padding: 48px 20px;
      text-align: center;
      color: var(--text-muted);
    }
    .empty-state-title {
      font-size: 16px;
      font-weight: 700;
      color: #E2E8F0;
      margin-bottom: 6px;
    }

    /* Login Screen */
    .login-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
    }

    .login-card {
      width: 100%;
      max-width: 400px;
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 36px 30px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
      text-align: center;
    }

    .login-logo {
      height: 36px;
      width: auto;
      margin-bottom: 20px;
    }

    .login-title {
      font-size: 20px;
      font-weight: 800;
      color: #FFFFFF;
      margin-bottom: 6px;
    }

    .login-sub {
      font-size: 13.5px;
      color: var(--text-muted);
      margin-bottom: 24px;
    }

    .login-form {
      display: flex;
      flex-direction: column;
      gap: 16px;
      text-align: left;
    }

    .form-group label {
      font-size: 13px;
      font-weight: 600;
      color: #D1D5DB;
      margin-bottom: 6px;
      display: block;
    }

    .login-input {
      width: 100%;
      background: #0E1424;
      border: 1px solid var(--border-color);
      border-radius: 8px;
      padding: 12px 14px;
      color: #FFFFFF;
      font-family: var(--font-family);
      font-size: 14px;
      outline: none;
    }
    .login-input:focus {
      border-color: var(--color-primary);
    }

    .error-banner {
      background: rgba(239, 68, 68, 0.15);
      border: 1px solid rgba(239, 68, 68, 0.4);
      color: #FCA5A5;
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 13px;
      margin-bottom: 16px;
    }

    .success-banner {
      background: rgba(16, 185, 129, 0.15);
      border: 1px solid rgba(16, 185, 129, 0.4);
      color: #6EE7B7;
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 13px;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
  </style>
</head>
<body>

<?php if (!$isAuthenticated): ?>
  <!-- =========================================================================
       LOGIN SCREEN
       ========================================================================= -->
  <div class="login-wrapper">
    <div class="login-card">
      <img src="assets/cmgalaxy-logo.png" alt="CMGalaxy Logo" class="login-logo">
      <h1 class="login-title">Admin Console</h1>
      <p class="login-sub">D2C Attribution Audit Security & Leads Monitor</p>

      <?php if (!empty($loginError)): ?>
        <div class="error-banner">
          <?= htmlspecialchars($loginError) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="admin.php" class="login-form">
        <input type="hidden" name="admin_login" value="1">
        <div class="form-group">
          <label for="password">Admin Password</label>
          <input 
            type="password" 
            id="password" 
            name="password" 
            class="login-input" 
            placeholder="Enter password..." 
            autocomplete="current-password"
            required
            autofocus
          >
        </div>
        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">
          Unlock Admin Console →
        </button>
      </form>
    </div>
  </div>

<?php else: ?>
  <!-- =========================================================================
       AUTHENTICATED ADMIN CONSOLE
       ========================================================================= -->
  <div class="admin-container">
    
    <!-- Top Header -->
    <header class="admin-header">
      <div class="brand-group">
        <a href="https://www.cmgalaxy.com/" target="_blank" rel="noopener noreferrer">
          <img src="assets/cmgalaxy-logo.png" alt="CMGalaxy Logo" class="brand-logo-img">
        </a>
        <div class="admin-title-wrap">
          <div class="admin-title">
            D2C Attribution Audit Console
            <span class="admin-badge-role">Admin</span>
          </div>
          <div class="admin-subtext">Live Lead Captures, Rate Limiting & Security Dashboard</div>
        </div>
      </div>

      <div class="header-actions">
        <!-- 1-Click Excel Download -->
        <a href="admin.php?action=export_excel" class="btn btn-excel" title="Download all leads in Microsoft Excel compatible CSV">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="7 10 12 15 17 10"></polyline>
            <line x1="12" y1="15" x2="12" y2="3"></line>
          </svg>
          Download Excel Sheet
        </a>

        <!-- View App -->
        <a href="index.html" target="_blank" class="btn btn-outline" title="Open live public application">
          Open App ↗
        </a>

        <!-- Logout -->
        <a href="admin.php?logout=1" class="btn btn-outline" style="color: #F87171;" title="Sign out from console">
          Logout
        </a>
      </div>
    </header>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'unblocked'): ?>
      <div class="success-banner">
        <span>✓</span> IP address has been successfully unblocked and its rate limit counters have been reset.
      </div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'blocked'): ?>
      <div class="success-banner">
        <span>✓</span> IP address has been successfully blocked for 24 hours.
      </div>
    <?php endif; ?>

    <!-- KPI Metric Cards -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">Total Leads Captured</div>
        <div class="kpi-value"><?= count($leadsList) ?></div>
        <div class="kpi-footer">All-time form submissions</div>
      </div>

      <div class="kpi-card kpi-success">
        <div class="kpi-label">Today's Form Submissions</div>
        <div class="kpi-value"><?= $todayLeadsCount ?></div>
        <div class="kpi-footer">Completed within last 24 hours</div>
      </div>

      <div class="kpi-card <?= $activeBlockedCount > 0 ? 'kpi-danger' : '' ?>">
        <div class="kpi-label">Blocked Spam IPs</div>
        <div class="kpi-value"><?= $activeBlockedCount ?></div>
        <div class="kpi-footer">Exceeded 3 submissions/day limit</div>
      </div>

      <div class="kpi-card kpi-warning">
        <div class="kpi-label">Active Monitored IPs</div>
        <div class="kpi-value"><?= count($activeTrackers) ?></div>
        <div class="kpi-footer">IPs tracked in 24h rolling window</div>
      </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="admin-tabs">
      <a href="admin.php?tab=leads" class="tab-btn <?= $currentTab === 'leads' ? 'active' : '' ?>">
        📋 Form Submissions (Leads)
        <span class="tab-counter"><?= count($leadsList) ?></span>
      </a>
      <a href="admin.php?tab=security" class="tab-btn <?= $currentTab === 'security' ? 'active' : '' ?>">
        🛡️ Blocked IPs & Security Log
        <span class="tab-counter"><?= $activeBlockedCount ?></span>
      </a>
      <a href="admin.php?tab=traffic" class="tab-btn <?= $currentTab === 'traffic' ? 'active' : '' ?>">
        📊 Live IP Rate Limit Monitor
        <span class="tab-counter"><?= count($activeTrackers) ?></span>
      </a>
    </div>

    <!-- TAB 1: LEADS SUBMISSIONS -->
    <?php if ($currentTab === 'leads'): ?>
      <div class="table-controls">
        <div class="search-box-wrap">
          <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input type="text" id="lead-search" class="search-input" placeholder="Search by name, email, company, IP...">
        </div>

        <div style="font-size: 13px; color: var(--text-muted);">
          Showing <strong><?= count($leadsList) ?></strong> recorded submissions
        </div>
      </div>

      <div class="table-card">
        <?php if (empty($leadsList)): ?>
          <div class="empty-state">
            <div class="empty-state-title">No form submissions recorded yet</div>
            <p>When users submit the lead capture form, their details and generated PDF reports will show up here.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="data-table" id="leads-table">
              <thead>
                <tr>
                  <th>Date & Time (IST)</th>
                  <th>Full Name</th>
                  <th>Company</th>
                  <th>Work Email</th>
                  <th>Phone Number</th>
                  <th>Client IP</th>
                  <th>Risk Level</th>
                  <th>Report PDF</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($leadsList as $lead): ?>
                  <?php
                    $riskClass = 'risk-moderate';
                    $risk = strtolower($lead['riskLevel'] ?? 'moderate');
                    if (str_contains($risk, 'low')) $riskClass = 'risk-low';
                    elseif (str_contains($risk, 'critical')) $riskClass = 'risk-critical';
                    elseif (str_contains($risk, 'high')) $riskClass = 'risk-high';
                  ?>
                  <tr>
                    <td style="white-space: nowrap; font-size: 12.5px; color: var(--text-muted);">
                      <?= htmlspecialchars($lead['date'] ?? 'N/A') ?>
                    </td>
                    <td>
                      <strong style="color: #FFFFFF;"><?= htmlspecialchars($lead['fullName'] ?? 'N/A') ?></strong>
                    </td>
                    <td><?= htmlspecialchars($lead['company'] ?? 'N/A') ?></td>
                    <td>
                      <a href="mailto:<?= htmlspecialchars($lead['email'] ?? '') ?>" style="color: #93C5FD; text-decoration: none;">
                        <?= htmlspecialchars($lead['email'] ?? 'N/A') ?>
                      </a>
                    </td>
                    <td style="font-family: var(--font-mono); font-size: 12.5px;">
                      <?= htmlspecialchars($lead['phone'] ?? 'N/A') ?>
                    </td>
                    <td>
                      <span class="ip-badge"><?= htmlspecialchars($lead['ip'] ?? 'Unknown') ?></span>
                    </td>
                    <td>
                      <span class="risk-badge <?= $riskClass ?>">
                        <?= htmlspecialchars($lead['riskLevel'] ?? 'Moderate') ?>
                      </span>
                    </td>
                    <td>
                      <?php if (!empty($lead['pdfUrl'])): ?>
                        <a href="<?= htmlspecialchars($lead['pdfUrl']) ?>" target="_blank" class="btn-pdf-link" title="Open generated PDF report">
                          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                          </svg>
                          PDF
                        </a>
                      <?php else: ?>
                        <span style="color: var(--text-muted); font-size: 12px;">Saved</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    <!-- TAB 2: BLOCKED IPS & SECURITY -->
    <?php elseif ($currentTab === 'security'): ?>
      
      <!-- Manual Block Form -->
      <div class="table-card" style="padding: 20px; margin-bottom: 24px;">
        <h3 style="font-size: 15px; font-weight: 700; color: #FFFFFF; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
          </svg>
          Manually Block An IP Address
        </h3>
        <form method="POST" action="admin.php?tab=security" style="display: flex; gap: 12px; flex-wrap: wrap;">
          <input type="hidden" name="action" value="manual_block">
          <input type="text" name="ip" class="search-input" placeholder="e.g. 192.168.1.10" style="flex: 1; min-width: 200px;" required>
          <input type="text" name="reason" class="search-input" placeholder="Reason for blocking (e.g. Malicious spam)" style="flex: 2; min-width: 250px;">
          <button type="submit" class="btn btn-primary" style="background: var(--color-danger);">
            Block IP (24h)
          </button>
        </form>
      </div>

      <div class="table-card">
        <?php if (empty($blockedList)): ?>
          <div class="empty-state">
            <div class="empty-state-title">No IPs currently blocked</div>
            <p>When an IP exceeds 3 form submissions in 24 hours or spams OTPs, it will automatically appear here.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Blocked IP Address</th>
                  <th>Block Reason</th>
                  <th>Attempts</th>
                  <th>Blocked At (IST)</th>
                  <th>Expires In</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($blockedList as $ip => $item): ?>
                  <?php
                    $isStillActive = !empty($item['active']) && $now < ($item['expires_at'] ?? 0);
                    $hoursRemaining = $isStillActive ? ceil(($item['expires_at'] - $now) / 3600) : 0;
                  ?>
                  <tr>
                    <td>
                      <span class="ip-badge" style="color: #F87171; font-weight: 700;"><?= htmlspecialchars($ip) ?></span>
                    </td>
                    <td><?= htmlspecialchars($item['reason'] ?? 'Exceeded rate limit') ?></td>
                    <td style="font-family: var(--font-mono);"><?= (int)($item['attempts'] ?? 0) ?></td>
                    <td style="font-size: 12.5px; color: var(--text-muted);">
                      <?= date('M j, Y - H:i', $item['blocked_at'] ?? time()) ?>
                    </td>
                    <td>
                      <?php if ($isStillActive): ?>
                        <span style="color: #FCD34D; font-weight: 600; font-size: 12.5px;">~<?= $hoursRemaining ?> hr(s)</span>
                      <?php else: ?>
                        <span style="color: var(--text-muted); font-size: 12.5px;">Expired</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($isStillActive): ?>
                        <span class="status-badge status-blocked">Blocked</span>
                      <?php else: ?>
                        <span class="status-badge status-active">Released</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <a href="admin.php?action=unblock&ip=<?= urlencode($ip) ?>" 
                         onclick="return confirm('Are you sure you want to unblock <?= htmlspecialchars($ip) ?>?')" 
                         class="btn btn-unblock">
                        Unblock IP
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    <!-- TAB 3: LIVE IP RATE LIMIT MONITOR -->
    <?php elseif ($currentTab === 'traffic'): ?>
      <div class="table-card">
        <div style="padding: 18px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
          <div>
            <h3 style="font-size: 15px; font-weight: 700; color: #FFFFFF;">24-Hour Active Rate Limit Tracker</h3>
            <p style="font-size: 12.5px; color: var(--text-muted);">Rule: Max 3 form submissions allowed per IP in a rolling 24-hour window.</p>
          </div>
          <span style="font-size: 12px; color: #34D399; font-weight: 600;">● Active Tracking</span>
        </div>

        <?php if (empty($activeTrackers)): ?>
          <div class="empty-state">
            <div class="empty-state-title">No IP traffic tracked in the last 24 hours</div>
            <p>As visitors submit forms, their IP activity counter will display here live.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Client IP</th>
                  <th>Submissions Used</th>
                  <th>Remaining Today</th>
                  <th>First Submission (IST)</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($activeTrackers as $trk): ?>
                  <?php
                    $used = (int)($trk['count'] ?? 0);
                    $rem = max(0, 3 - $used);
                    $isBlocked = $used >= 3;
                  ?>
                  <tr>
                    <td><span class="ip-badge"><?= htmlspecialchars($trk['ip'] ?? 'Unknown') ?></span></td>
                    <td style="font-family: var(--font-mono); font-weight: 700;">
                      <span style="color: <?= $isBlocked ? '#EF4444' : ($used === 2 ? '#F59E0B' : '#10B981') ?>">
                        <?= $used ?> / 3
                      </span>
                    </td>
                    <td style="font-family: var(--font-mono); font-size: 12.5px;">
                      <?= $rem ?> left
                    </td>
                    <td style="font-size: 12.5px; color: var(--text-muted);">
                      <?= date('M j, Y - H:i', $trk['start'] ?? time()) ?>
                    </td>
                    <td>
                      <?php if ($isBlocked): ?>
                        <span class="status-badge status-blocked">Limit Reached</span>
                      <?php else: ?>
                        <span class="status-badge status-active">Allowed</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($isBlocked): ?>
                        <a href="admin.php?action=unblock&ip=<?= urlencode($trk['ip']) ?>" class="btn btn-unblock">
                          Reset Limit
                        </a>
                      <?php else: ?>
                        <span style="color: var(--text-muted); font-size: 12px;">Active</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </div>

  <!-- Real-Time Instant Search Script -->
  <script>
    const searchInput = document.getElementById('lead-search');
    const table = document.getElementById('leads-table');
    if (searchInput && table) {
      searchInput.addEventListener('input', function() {
        const query = this.value.toLowerCase().trim();
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
          const text = row.textContent.toLowerCase();
          row.style.display = text.includes(query) ? '' : 'none';
        });
      });
    }
  </script>
<?php endif; ?>

</body>
</html>
