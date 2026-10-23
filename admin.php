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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// Admin Credentials read dynamically from .env (No hardcoding)
$envAdminUser = getenv('ADMIN_PANEL_USER') ?: ($_ENV['ADMIN_PANEL_USER'] ?? '');
$envAdminPass = getenv('ADMIN_PANEL_PASS') ?: ($_ENV['ADMIN_PANEL_PASS'] ?? '');

define('ADMIN_USERNAME', $envAdminUser);
define('ADMIN_PASSWORD', $envAdminPass);

// Directories
$reportsDir   = __DIR__ . '/reports';
$rateLimitDir = $reportsDir . '/.ratelimits';
$leadsJson    = $reportsDir . '/leads_log.json';
$leadsCsv     = $reportsDir . '/leads.csv';
$blockedJson  = $rateLimitDir . '/blocked_ips.json';

// Handle Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['cmg_admin_logged']);
    unset($_SESSION['cmg_admin_user']);
    session_destroy();
    header('Location: admin.php');
    exit;
}

// Handle Login
$loginError = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['admin_login'])) {
    $enteredUser = trim($_POST['username'] ?? '');
    $enteredPass = trim($_POST['password'] ?? '');

    if (empty(ADMIN_USERNAME) || empty(ADMIN_PASSWORD)) {
        $loginError = 'Admin credentials are not configured in .env. Please configure ADMIN_PANEL_USER and ADMIN_PANEL_PASS.';
    } elseif (hash_equals(ADMIN_USERNAME, $enteredUser) && hash_equals(ADMIN_PASSWORD, $enteredPass)) {
        $_SESSION['cmg_admin_logged'] = true;
        $_SESSION['cmg_admin_user'] = $enteredUser;
        header('Location: admin.php');
        exit;
    } else {
        $loginError = 'Invalid admin username or password. Please try again.';
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

  <!-- Google Fonts: Plus Jakarta Sans & JetBrains Mono -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">

  <style>
    :root {
      --bg-body: #F8FAFC;
      --bg-surface: #FFFFFF;
      --bg-surface-hover: #F8FAFC;
      --bg-subtle: #F1F5F9;
      --border-color: #E2E8F0;
      --border-darker: #CBD5E1;
      
      --color-primary: #2563EB;
      --color-primary-hover: #1D4ED8;
      --color-primary-light: #EFF6FF;
      --color-primary-border: #BFDBFE;

      --color-success: #059669;
      --color-success-hover: #047857;
      --color-success-light: #ECFDF5;
      --color-success-border: #A7F3D0;

      --color-danger: #DC2626;
      --color-danger-hover: #B91C1C;
      --color-danger-light: #FEF2F2;
      --color-danger-border: #FECACA;

      --color-warning: #D97706;
      --color-warning-light: #FFFBEB;
      --color-warning-border: #FDE68A;

      --color-purple: #7C3AED;
      --color-purple-light: #F5F3FF;
      --color-purple-border: #DDD6FE;

      --text-main: #0F172A;
      --text-body: #334155;
      --text-muted: #64748B;
      --text-subtle: #94A3B8;

      --shadow-sm: 0 1px 2px 0 rgba(15, 23, 42, 0.05);
      --shadow-md: 0 4px 6px -1px rgba(15, 23, 42, 0.07), 0 2px 4px -2px rgba(15, 23, 42, 0.05);
      --shadow-lg: 0 10px 15px -3px rgba(15, 23, 42, 0.08), 0 4px 6px -4px rgba(15, 23, 42, 0.04);
      --shadow-card: 0 1px 3px 0 rgba(15, 23, 42, 0.06), 0 1px 2px -1px rgba(15, 23, 42, 0.04);

      --font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      --font-mono: 'JetBrains Mono', monospace;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: var(--font-family);
      background-color: var(--bg-body);
      color: var(--text-body);
      min-height: 100vh;
      -webkit-font-smoothing: antialiased;
      line-height: 1.5;
    }

    /* Top Navigation Bar */
    .top-nav {
      background: var(--bg-surface);
      border-bottom: 1px solid var(--border-color);
      box-shadow: var(--shadow-sm);
      position: sticky;
      top: 0;
      z-index: 50;
    }

    .top-nav-inner {
      max-width: 1400px;
      margin: 0 auto;
      padding: 14px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }

    .brand-section {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .brand-logo-img {
      height: 32px;
      width: auto;
      display: block;
    }

    .brand-divider {
      width: 1px;
      height: 24px;
      background: var(--border-color);
    }

    .brand-title-wrap {
      display: flex;
      flex-direction: column;
    }

    .brand-title {
      font-size: 16px;
      font-weight: 800;
      color: var(--text-main);
      display: flex;
      align-items: center;
      gap: 8px;
      letter-spacing: -0.01em;
    }

    .brand-subtitle {
      font-size: 12px;
      color: var(--text-muted);
      font-weight: 500;
    }

    .sys-pulse-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--color-success-light);
      border: 1px solid var(--color-success-border);
      color: var(--color-success);
      font-size: 11.5px;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 9999px;
    }
    .pulse-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--color-success);
      box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.2);
    }

    /* Nav Actions */
    .nav-actions {
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
      transition: all 150ms cubic-bezier(0.4, 0, 0.2, 1);
      white-space: nowrap;
    }

    .btn-excel {
      background: linear-gradient(180deg, #10B981 0%, #059669 100%);
      color: #FFFFFF;
      box-shadow: 0 1px 3px rgba(5, 150, 105, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.2);
      border: 1px solid #047857;
    }
    .btn-excel:hover {
      background: #047857;
      color: #FFFFFF;
      box-shadow: 0 4px 10px rgba(5, 150, 105, 0.25);
      transform: translateY(-1px);
    }

    .btn-secondary {
      background: var(--bg-surface);
      border-color: var(--border-color);
      color: var(--text-body);
      box-shadow: var(--shadow-sm);
    }
    .btn-secondary:hover {
      background: var(--bg-subtle);
      border-color: var(--border-darker);
      color: var(--text-main);
    }

    .btn-primary {
      background: var(--color-primary);
      color: #FFFFFF;
      box-shadow: 0 1px 2px rgba(37, 99, 235, 0.2);
    }
    .btn-primary:hover {
      background: var(--color-primary-hover);
      box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25);
    }

    .btn-danger {
      background: var(--color-danger);
      color: #FFFFFF;
    }
    .btn-danger:hover {
      background: var(--color-danger-hover);
    }

    .btn-unblock {
      background: var(--color-success-light);
      border-color: var(--color-success-border);
      color: var(--color-success);
      padding: 5px 12px;
      font-size: 12px;
      font-weight: 700;
      border-radius: 6px;
    }
    .btn-unblock:hover {
      background: var(--color-success);
      color: #FFFFFF;
      border-color: var(--color-success);
    }

    /* Admin User Pill */
    .user-pill {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--bg-subtle);
      border: 1px solid var(--border-color);
      padding: 5px 12px 5px 6px;
      border-radius: 9999px;
      font-size: 13px;
      font-weight: 600;
      color: var(--text-main);
    }

    .user-avatar {
      width: 26px;
      height: 26px;
      border-radius: 50%;
      background: linear-gradient(135deg, #2563EB 0%, #3B82F6 100%);
      color: #FFFFFF;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 12px;
      font-weight: 700;
    }

    .user-role-tag {
      font-size: 10.5px;
      font-weight: 700;
      color: var(--color-primary);
      background: var(--color-primary-light);
      padding: 1px 6px;
      border-radius: 4px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .btn-logout {
      background: transparent;
      border: 1px solid transparent;
      color: var(--color-danger);
      font-weight: 600;
      padding: 6px 12px;
      font-size: 12.5px;
      border-radius: 6px;
      cursor: pointer;
      text-decoration: none;
      transition: all 120ms ease;
    }
    .btn-logout:hover {
      background: var(--color-danger-light);
      border-color: var(--color-danger-border);
    }

    /* Main Container */
    .admin-container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 28px 24px 60px;
    }

    /* Page Header Info */
    .page-title-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 24px;
      flex-wrap: wrap;
      gap: 12px;
    }

    .page-title-wrap h1 {
      font-size: 24px;
      font-weight: 800;
      color: var(--text-main);
      letter-spacing: -0.02em;
    }

    .page-title-wrap p {
      font-size: 13.5px;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .time-indicator {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 12.5px;
      font-weight: 600;
      color: var(--text-muted);
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      padding: 6px 14px;
      border-radius: 8px;
      box-shadow: var(--shadow-sm);
    }

    /* KPI Grid */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 18px;
      margin-bottom: 30px;
    }

    .kpi-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 20px;
      box-shadow: var(--shadow-card);
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      gap: 12px;
      transition: transform 180ms ease, box-shadow 180ms ease;
    }
    .kpi-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
    }

    .kpi-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .kpi-label {
      font-size: 12px;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }

    .kpi-icon-pill {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .kpi-blue .kpi-icon-pill { background: var(--color-primary-light); color: var(--color-primary); }
    .kpi-green .kpi-icon-pill { background: var(--color-success-light); color: var(--color-success); }
    .kpi-red .kpi-icon-pill { background: var(--color-danger-light); color: var(--color-danger); }
    .kpi-purple .kpi-icon-pill { background: var(--color-purple-light); color: var(--color-purple); }

    .kpi-value {
      font-size: 34px;
      font-weight: 800;
      color: var(--text-main);
      font-family: var(--font-mono);
      line-height: 1;
      letter-spacing: -0.02em;
    }

    .kpi-footer {
      font-size: 12.5px;
      color: var(--text-muted);
      border-top: 1px solid var(--border-color);
      padding-top: 10px;
      margin-top: 2px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    /* Segmented Navigation Tabs */
    .tabs-wrapper {
      background: var(--bg-subtle);
      border: 1px solid var(--border-color);
      border-radius: 10px;
      padding: 4px;
      display: inline-flex;
      gap: 4px;
      margin-bottom: 22px;
      max-width: 100%;
      overflow-x: auto;
    }

    .tab-btn {
      padding: 9px 18px;
      background: transparent;
      border: none;
      color: var(--text-muted);
      font-family: var(--font-family);
      font-size: 13.5px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      border-radius: 7px;
      transition: all 140ms ease;
      white-space: nowrap;
    }

    .tab-btn:hover {
      color: var(--text-main);
    }

    .tab-btn.active {
      background: var(--bg-surface);
      color: var(--text-main);
      font-weight: 700;
      box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08), 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .tab-counter {
      background: var(--border-color);
      color: var(--text-body);
      padding: 2px 8px;
      border-radius: 9999px;
      font-size: 11.5px;
      font-weight: 700;
      font-family: var(--font-mono);
    }
    .tab-btn.active .tab-counter {
      background: var(--color-primary-light);
      color: var(--color-primary);
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
      max-width: 420px;
    }

    .search-input {
      width: 100%;
      background: var(--bg-surface);
      border: 1px solid var(--border-darker);
      border-radius: 8px;
      padding: 10px 14px 10px 40px;
      color: var(--text-main);
      font-family: var(--font-family);
      font-size: 13.5px;
      outline: none;
      box-shadow: var(--shadow-sm);
      transition: border-color 150ms ease, box-shadow 150ms ease;
    }
    .search-input:focus {
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .search-icon {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      pointer-events: none;
    }

    .record-count-badge {
      font-size: 13px;
      color: var(--text-muted);
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      padding: 6px 14px;
      border-radius: 8px;
    }

    /* Table Card */
    .table-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: var(--shadow-card);
    }

    .table-card-header {
      padding: 18px 22px;
      border-bottom: 1px solid var(--border-color);
      background: var(--bg-surface);
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }

    .table-card-title {
      font-size: 15px;
      font-weight: 700;
      color: var(--text-main);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .table-card-sub {
      font-size: 12.5px;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
    }

    .data-table th {
      background: #F8FAFC;
      padding: 13px 18px;
      font-size: 11.5px;
      font-weight: 700;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      border-bottom: 1px solid var(--border-color);
    }

    .data-table td {
      padding: 14px 18px;
      font-size: 13.5px;
      border-bottom: 1px solid #F1F5F9;
      color: var(--text-body);
      vertical-align: middle;
    }

    .data-table tbody tr {
      transition: background-color 100ms ease;
    }
    .data-table tbody tr:hover {
      background: #F8FAFC;
    }

    /* Badges */
    .ip-badge {
      font-family: var(--font-mono);
      font-size: 12px;
      background: #F1F5F9;
      border: 1px solid #CBD5E1;
      padding: 3px 8px;
      border-radius: 5px;
      color: #1E293B;
      font-weight: 600;
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
      background: var(--color-danger-light);
      border: 1px solid var(--color-danger-border);
      color: var(--color-danger);
    }

    .status-active {
      background: var(--color-success-light);
      border: 1px solid var(--color-success-border);
      color: var(--color-success);
    }

    /* Risk Badges */
    .risk-badge {
      font-size: 11.5px;
      font-weight: 700;
      padding: 4px 10px;
      border-radius: 6px;
      display: inline-block;
    }
    .risk-low { background: #DCFCE7; color: #15803D; border: 1px solid #86EFAC; }
    .risk-moderate { background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A; }
    .risk-high { background: #FEE2E2; color: #B91C1C; border: 1px solid #FECACA; }
    .risk-critical { background: #FFE4E6; color: #9F1239; border: 1px solid #FDA4AF; }

    /* PDF Link */
    .btn-pdf-link {
      color: var(--color-primary);
      text-decoration: none;
      font-weight: 700;
      font-size: 12.5px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      background: var(--color-primary-light);
      border: 1px solid var(--color-primary-border);
      border-radius: 6px;
      transition: all 120ms ease;
    }
    .btn-pdf-link:hover {
      background: var(--color-primary);
      color: #FFFFFF;
      border-color: var(--color-primary);
    }

    /* Empty State */
    .empty-state {
      padding: 56px 20px;
      text-align: center;
      color: var(--text-muted);
    }
    .empty-state-icon {
      font-size: 36px;
      margin-bottom: 12px;
      opacity: 0.7;
    }
    .empty-state-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--text-main);
      margin-bottom: 6px;
    }

    /* Success / Error Banners */
    .alert-banner {
      padding: 12px 16px;
      border-radius: 8px;
      font-size: 13.5px;
      font-weight: 500;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .alert-success {
      background: var(--color-success-light);
      border: 1px solid var(--color-success-border);
      color: #065F46;
    }

    .alert-error {
      background: var(--color-danger-light);
      border: 1px solid var(--color-danger-border);
      color: #991B1B;
    }

    /* Light Theme Login Screen */
    .login-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: linear-gradient(135deg, #F8FAFC 0%, #EEF2F6 100%);
    }

    .login-card {
      width: 100%;
      max-width: 420px;
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 38px 32px;
      box-shadow: 0 20px 25px -5px rgba(15, 23, 42, 0.08), 0 8px 10px -6px rgba(15, 23, 42, 0.04);
      text-align: center;
    }

    .login-logo {
      height: 38px;
      width: auto;
      margin-bottom: 18px;
    }

    .login-badge {
      display: inline-block;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      color: var(--color-primary);
      background: var(--color-primary-light);
      padding: 3px 9px;
      border-radius: 6px;
      margin-bottom: 12px;
    }

    .login-title {
      font-size: 22px;
      font-weight: 800;
      color: var(--text-main);
      margin-bottom: 6px;
      letter-spacing: -0.01em;
    }

    .login-sub {
      font-size: 13.5px;
      color: var(--text-muted);
      margin-bottom: 24px;
      line-height: 1.45;
    }

    .login-form {
      display: flex;
      flex-direction: column;
      gap: 16px;
      text-align: left;
    }

    .form-group label {
      font-size: 13px;
      font-weight: 700;
      color: var(--text-main);
      margin-bottom: 6px;
      display: block;
    }

    .login-input {
      width: 100%;
      background: var(--bg-surface);
      border: 1px solid var(--border-darker);
      border-radius: 8px;
      padding: 11px 14px;
      color: var(--text-main);
      font-family: var(--font-family);
      font-size: 14px;
      outline: none;
      transition: border-color 150ms ease, box-shadow 150ms ease;
    }
    .login-input:focus {
      border-color: var(--color-primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .login-card-footer {
      margin-top: 24px;
      font-size: 12px;
      color: var(--text-muted);
      border-top: 1px solid var(--border-color);
      padding-top: 16px;
    }
  </style>
</head>
<body>

<?php if (!$isAuthenticated): ?>
  <!-- =========================================================================
       EXECUTIVE LIGHT-MODE LOGIN SCREEN
       ========================================================================= -->
  <div class="login-wrapper">
    <div class="login-card">
      <a href="https://www.cmgalaxy.com/" target="_blank" rel="noopener noreferrer">
        <img src="assets/cmgalaxy-logo.png" alt="CMGalaxy Logo" class="login-logo">
      </a>
      
      <div>
        <span class="login-badge">Restricted Management Area</span>
      </div>
      <h1 class="login-title">Executive Admin Portal</h1>
      <p class="login-sub">Sign in with your admin credentials to access live D2C attribution leads, security logs, and rate limit controls.</p>

      <?php if (!empty($loginError)): ?>
        <div class="alert-banner alert-error" style="text-align: left;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          </svg>
          <div><?= htmlspecialchars($loginError) ?></div>
        </div>
      <?php endif; ?>

      <form method="POST" action="admin.php" class="login-form">
        <input type="hidden" name="admin_login" value="1">
        
        <div class="form-group">
          <label for="username">Admin Username / ID</label>
          <input 
            type="text" 
            id="username" 
            name="username" 
            class="login-input" 
            placeholder="e.g. admin" 
            autocomplete="username"
            value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
            required
            autofocus
          >
        </div>

        <div class="form-group">
          <label for="password">Admin Password</label>
          <input 
            type="password" 
            id="password" 
            name="password" 
            class="login-input" 
            placeholder="••••••••••••" 
            autocomplete="current-password"
            required
          >
        </div>

        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 6px; font-size: 14px;">
          Unlock Admin Console →
        </button>
      </form>

      <div class="login-card-footer">
        CMGalaxy D2C Attribution Audit • Enterprise Edition
      </div>
    </div>
  </div>

<?php else: ?>
  <!-- =========================================================================
       AUTHENTICATED EXECUTIVE LIGHT DASHBOARD
       ========================================================================= -->
  
  <!-- Top Navigation Bar -->
  <header class="top-nav">
    <div class="top-nav-inner">
      <div class="brand-section">
        <a href="https://www.cmgalaxy.com/" target="_blank" rel="noopener noreferrer">
          <img src="assets/cmgalaxy-logo.png" alt="CMGalaxy Logo" class="brand-logo-img">
        </a>
        <div class="brand-divider"></div>
        <div class="brand-title-wrap">
          <div class="brand-title">
            D2C Attribution Intelligence
            <span class="sys-pulse-badge">
              <span class="pulse-dot"></span>
              Live Protected
            </span>
          </div>
          <div class="brand-subtitle">Executive Lead Captures & IP Rate Limit Security Portal</div>
        </div>
      </div>

      <div class="nav-actions">
        <!-- 1-Click Excel Export Button -->
        <a href="admin.php?action=export_excel" class="btn btn-excel" title="Download all form leads in Microsoft Excel (.csv) format">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="7 10 12 15 17 10"></polyline>
            <line x1="12" y1="15" x2="12" y2="3"></line>
          </svg>
          Export Excel Sheet
        </a>

        <!-- Open Live Public App -->
        <a href="index.html" target="_blank" class="btn btn-secondary" title="Preview public audit tool">
          Public App ↗
        </a>

        <!-- Admin Profile Pill -->
        <div class="user-pill" title="Currently authenticated admin">
          <div class="user-avatar">
            <?= strtoupper(substr($_SESSION['cmg_admin_user'] ?? 'A', 0, 1)) ?>
          </div>
          <span><?= htmlspecialchars($_SESSION['cmg_admin_user'] ?? 'Admin') ?></span>
          <span class="user-role-tag">Super Admin</span>
        </div>

        <!-- Logout -->
        <a href="admin.php?logout=1" class="btn-logout" title="Sign out from console">
          Logout
        </a>
      </div>
    </div>
  </header>

  <!-- Main Content Container -->
  <div class="admin-container">

    <!-- Page Title & Live Clock -->
    <div class="page-title-row">
      <div class="page-title-wrap">
        <h1>Overview & Security Console</h1>
        <p>Monitor real-time audit conversions, manage blocked spam IPs, and track 24-hour rate limit compliance.</p>
      </div>
      <div class="time-indicator">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"></circle>
          <polyline points="12 6 12 12 16 14"></polyline>
        </svg>
        <span>IST: <?= date('M j, Y • h:i A') ?></span>
      </div>
    </div>

    <!-- Feedback Alerts -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'unblocked'): ?>
      <div class="alert-banner alert-success">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <polyline points="20 6 9 17 4 12"></polyline>
        </svg>
        <div>IP address has been unblocked successfully, and its rate limit counters have been reset.</div>
      </div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'blocked'): ?>
      <div class="alert-banner alert-error">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="12" cy="12" r="10"></circle>
          <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
        </svg>
        <div>IP address has been manually blocked from form submissions for 24 hours.</div>
      </div>
    <?php endif; ?>

    <!-- KPI Metric Cards Grid -->
    <div class="kpi-grid">
      
      <!-- Card 1: Total Leads -->
      <div class="kpi-card kpi-blue">
        <div class="kpi-card-top">
          <span class="kpi-label">Total Leads Captured</span>
          <div class="kpi-icon-pill">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
          </div>
        </div>
        <div class="kpi-value"><?= count($leadsList) ?></div>
        <div class="kpi-footer">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
          </svg>
          All-time verified audit submissions
        </div>
      </div>

      <!-- Card 2: Today's Submissions -->
      <div class="kpi-card kpi-green">
        <div class="kpi-card-top">
          <span class="kpi-label">Today's Form Submissions</span>
          <div class="kpi-icon-pill">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
              <line x1="16" y1="2" x2="16" y2="6"></line>
              <line x1="8" y1="2" x2="8" y2="6"></line>
              <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
          </div>
        </div>
        <div class="kpi-value" style="color: var(--color-success);"><?= $todayLeadsCount ?></div>
        <div class="kpi-footer">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <polyline points="12 6 12 12 16 14"></polyline>
          </svg>
          Past 24 hours rolling window
        </div>
      </div>

      <!-- Card 3: Blocked Spam IPs -->
      <div class="kpi-card kpi-red">
        <div class="kpi-card-top">
          <span class="kpi-label">Blocked Spam IPs</span>
          <div class="kpi-icon-pill">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
              <line x1="9.5" y1="9" x2="14.5" y2="14"></line>
              <line x1="14.5" y1="9" x2="9.5" y2="14"></line>
            </svg>
          </div>
        </div>
        <div class="kpi-value" style="color: <?= $activeBlockedCount > 0 ? 'var(--color-danger)' : 'var(--text-main)' ?>;">
          <?= $activeBlockedCount ?>
        </div>
        <div class="kpi-footer">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="8" x2="12" y2="12"></line>
            <line x1="12" y1="16" x2="12.01" y2="16"></line>
          </svg>
          Exceeded 3 forms/day limit
        </div>
      </div>

      <!-- Card 4: Active Monitored IPs -->
      <div class="kpi-card kpi-purple">
        <div class="kpi-card-top">
          <span class="kpi-label">Active Monitored IPs</span>
          <div class="kpi-icon-pill">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"></circle>
              <path d="M2 12h20"></path>
              <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
            </svg>
          </div>
        </div>
        <div class="kpi-value" style="color: var(--color-purple);"><?= count($activeTrackers) ?></div>
        <div class="kpi-footer">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
          </svg>
          Unique visitors submitting today
        </div>
      </div>

    </div>

    <!-- Segmented Navigation Tabs -->
    <div class="tabs-wrapper">
      <a href="admin.php?tab=leads" class="tab-btn <?= $currentTab === 'leads' ? 'active' : '' ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
          <line x1="16" y1="13" x2="8" y2="13"></line>
          <line x1="16" y1="17" x2="8" y2="17"></line>
          <polyline points="10 9 9 9 8 9"></polyline>
        </svg>
        Form Submissions (Leads)
        <span class="tab-counter"><?= count($leadsList) ?></span>
      </a>
      
      <a href="admin.php?tab=security" class="tab-btn <?= $currentTab === 'security' ? 'active' : '' ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
        </svg>
        Blocked IPs & Security Log
        <span class="tab-counter"><?= $activeBlockedCount ?></span>
      </a>

      <a href="admin.php?tab=traffic" class="tab-btn <?= $currentTab === 'traffic' ? 'active' : '' ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
        </svg>
        Live 24h Rate Limit Monitor
        <span class="tab-counter"><?= count($activeTrackers) ?></span>
      </a>
    </div>

    <!-- TAB 1: FORM SUBMISSIONS (LEADS) -->
    <?php if ($currentTab === 'leads'): ?>
      <div class="table-controls">
        <div class="search-box-wrap">
          <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3">
            <circle cx="11" cy="11" r="8"></circle>
            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
          </svg>
          <input type="text" id="lead-search" class="search-input" placeholder="Search by name, email, company, IP...">
        </div>

        <div class="record-count-badge">
          Showing <strong><?= count($leadsList) ?></strong> recorded submissions
        </div>
      </div>

      <div class="table-card">
        <?php if (empty($leadsList)): ?>
          <div class="empty-state">
            <div class="empty-state-icon">📋</div>
            <div class="empty-state-title">No Form Submissions Yet</div>
            <p>When visitors complete the lead capture form, their details and generated PDF audit reports will show up here in real time.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="data-table" id="leads-table">
              <thead>
                <tr>
                  <th>Date & Time (IST)</th>
                  <th>Full Name</th>
                  <th>Company / Brand</th>
                  <th>Work Email</th>
                  <th>Phone Number</th>
                  <th>Client IP</th>
                  <th>Audit Risk Level</th>
                  <th>PDF Report</th>
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
                    <td style="white-space: nowrap; font-size: 12.5px; color: var(--text-muted); font-family: var(--font-mono);">
                      <?= htmlspecialchars($lead['date'] ?? 'N/A') ?>
                    </td>
                    <td>
                      <strong style="color: var(--text-main); font-weight: 700;">
                        <?= htmlspecialchars($lead['fullName'] ?? 'N/A') ?>
                      </strong>
                    </td>
                    <td>
                      <span style="font-weight: 600; color: #1E293B;">
                        <?= htmlspecialchars($lead['company'] ?? 'N/A') ?>
                      </span>
                    </td>
                    <td>
                      <a href="mailto:<?= htmlspecialchars($lead['email'] ?? '') ?>" style="color: var(--color-primary); text-decoration: none; font-weight: 500;">
                        <?= htmlspecialchars($lead['email'] ?? 'N/A') ?>
                      </a>
                    </td>
                    <td style="font-family: var(--font-mono); font-size: 12.5px; color: var(--text-main);">
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
                        <a href="<?= htmlspecialchars($lead['pdfUrl']) ?>" target="_blank" class="btn-pdf-link" title="Download or preview generated PDF report">
                          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                          </svg>
                          Download PDF
                        </a>
                      <?php else: ?>
                        <span style="color: var(--text-muted); font-size: 12px;">Archived</span>
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
      
      <!-- Manual Block Form Card -->
      <div class="table-card" style="padding: 22px; margin-bottom: 24px;">
        <div style="margin-bottom: 14px;">
          <h3 style="font-size: 15px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="color: var(--color-danger);">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line>
            </svg>
            Manually Blacklist An IP Address (24-Hour Block)
          </h3>
          <p style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">
            Enter an IP address below to immediately prevent it from requesting OTPs or submitting audit forms.
          </p>
        </div>

        <form method="POST" action="admin.php?tab=security" style="display: flex; gap: 12px; flex-wrap: wrap;">
          <input type="hidden" name="action" value="manual_block">
          <input 
            type="text" 
            name="ip" 
            class="search-input" 
            placeholder="e.g. 103.21.244.1" 
            style="flex: 1; min-width: 220px;" 
            required
          >
          <input 
            type="text" 
            name="reason" 
            class="search-input" 
            placeholder="Reason for blocking (e.g. Suspicious automated spam bot)" 
            style="flex: 2; min-width: 280px;"
          >
          <button type="submit" class="btn btn-danger">
            Blacklist IP (24 Hours)
          </button>
        </form>
      </div>

      <!-- Blocked IPs Table Card -->
      <div class="table-card">
        <div class="table-card-header">
          <div>
            <div class="table-card-title">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--color-danger);">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
              </svg>
              Blocked IP Addresses & Security Events
            </div>
            <div class="table-card-sub">Automatic triggers: Exceeding 3 submissions in 24 hours or OTP flooding</div>
          </div>
          <span class="record-count-badge">
            <strong><?= $activeBlockedCount ?></strong> active blocks
          </span>
        </div>

        <?php if (empty($blockedList)): ?>
          <div class="empty-state">
            <div class="empty-state-icon">🛡️</div>
            <div class="empty-state-title">No Blocked IPs Currently</div>
            <p>When an IP address exceeds the 3 submissions per day limit, it will automatically appear here with 1-click unblock capability.</p>
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
                  <th>Time Remaining</th>
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
                      <span class="ip-badge" style="color: #B91C1C; background: #FEE2E2; border-color: #FECACA; font-weight: 700;">
                        <?= htmlspecialchars($ip) ?>
                      </span>
                    </td>
                    <td><?= htmlspecialchars($item['reason'] ?? 'Exceeded rate limit') ?></td>
                    <td style="font-family: var(--font-mono); font-weight: 700;">
                      <?= (int)($item['attempts'] ?? 0) ?>
                    </td>
                    <td style="font-size: 12.5px; color: var(--text-muted); font-family: var(--font-mono);">
                      <?= date('M j, Y - H:i', $item['blocked_at'] ?? time()) ?>
                    </td>
                    <td>
                      <?php if ($isStillActive): ?>
                        <span style="color: var(--color-warning); font-weight: 700; font-size: 12.5px;">
                          ~<?= $hoursRemaining ?> hr(s)
                        </span>
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
                         onclick="return confirm('Are you sure you want to unblock <?= htmlspecialchars($ip) ?> and reset its submission limit?')" 
                         class="btn btn-unblock">
                        Unblock & Reset
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
        <div class="table-card-header">
          <div>
            <div class="table-card-title">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: var(--color-purple);">
                <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
              </svg>
              24-Hour Rolling IP Limit Tracker
            </div>
            <div class="table-card-sub">Global Rule: Each unique IP address is permitted up to 3 form submissions every 24 hours</div>
          </div>
          <span class="sys-pulse-badge">
            <span class="pulse-dot"></span>
            Live Monitoring
          </span>
        </div>

        <?php if (empty($activeTrackers)): ?>
          <div class="empty-state">
            <div class="empty-state-icon">⚡</div>
            <div class="empty-state-title">No Active Traffic Recorded In Last 24 Hours</div>
            <p>As visitors interact with the audit tool, their submission quotas will update here live.</p>
          </div>
        <?php else: ?>
          <div style="overflow-x: auto;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Client IP</th>
                  <th>Submissions Used</th>
                  <th>Quota Remaining</th>
                  <th>First Submission (IST)</th>
                  <th>Security Status</th>
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
                    <td>
                      <span class="ip-badge"><?= htmlspecialchars($trk['ip'] ?? 'Unknown') ?></span>
                    </td>
                    <td>
                      <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="font-family: var(--font-mono); font-weight: 800; color: <?= $isBlocked ? '#DC2626' : ($used === 2 ? '#D97706' : '#059669') ?>">
                          <?= $used ?> / 3
                        </span>
                        <div style="width: 70px; height: 6px; background: #E2E8F0; border-radius: 999px; overflow: hidden;">
                          <div style="width: <?= min(100, ($used / 3) * 100) ?>%; height: 100%; background: <?= $isBlocked ? '#DC2626' : ($used === 2 ? '#D97706' : '#10B981') ?>;"></div>
                        </div>
                      </div>
                    </td>
                    <td style="font-family: var(--font-mono); font-size: 13px; font-weight: 600;">
                      <?= $rem ?> remaining
                    </td>
                    <td style="font-size: 12.5px; color: var(--text-muted); font-family: var(--font-mono);">
                      <?= date('M j, Y - H:i', $trk['start'] ?? time()) ?>
                    </td>
                    <td>
                      <?php if ($isBlocked): ?>
                        <span class="status-badge status-blocked">Limit Exceeded</span>
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
                        <span style="color: var(--text-muted); font-size: 12.5px;">Within Quota</span>
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
