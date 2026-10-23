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

    // 2. Clear / Reset All Leads Action
    if (isset($_GET['action']) && $_GET['action'] === 'clear_leads') {
        if (!is_dir($reportsDir)) @mkdir($reportsDir, 0755, true);
        file_put_contents($leadsJson, json_encode([], JSON_PRETTY_PRINT));

        $fp = @fopen($leadsCsv, 'w');
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
        header('Location: admin.php?tab=leads&msg=cleared');
        exit;
    }

    // 3. Unblock IP Action
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

      --sidebar-width: 260px;
      --topbar-height: 64px;

      --shadow-sm: 0 1px 2px 0 rgba(15, 23, 42, 0.05);
      --shadow-md: 0 4px 6px -1px rgba(15, 23, 42, 0.07), 0 2px 4px -2px rgba(15, 23, 42, 0.05);
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

    /* =========================================================================
       ADMIN LAYOUT (SIDEBAR + MAIN CONTENT)
       ========================================================================= */
    .admin-layout {
      display: flex;
      min-height: 100vh;
    }

    /* Left Sidebar */
    .admin-sidebar {
      width: var(--sidebar-width);
      background: var(--bg-surface);
      border-right: 1px solid var(--border-color);
      position: fixed;
      top: 0;
      bottom: 0;
      left: 0;
      z-index: 100;
      display: flex;
      flex-direction: column;
      transition: transform 200ms ease;
    }

    /* Brand Header inside Sidebar */
    .sidebar-brand {
      padding: 20px 22px;
      border-bottom: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }

    .sidebar-logo {
      height: 32px;
      width: auto;
      max-width: 145px;
      display: block;
      object-fit: contain;
    }

    .sidebar-badge {
      font-size: 10px;
      font-weight: 800;
      color: var(--color-primary);
      background: var(--color-primary-light);
      border: 1px solid var(--color-primary-border);
      padding: 2px 7px;
      border-radius: 4px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    /* Navigation Menu */
    .sidebar-nav {
      flex: 1;
      overflow-y: auto;
      padding: 16px 0;
    }

    .nav-section-title {
      font-size: 11px;
      font-weight: 700;
      color: var(--text-subtle);
      letter-spacing: 0.08em;
      text-transform: uppercase;
      padding: 14px 22px 6px;
    }

    .nav-link {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 14px;
      margin: 3px 12px;
      border-radius: 8px;
      font-size: 13.5px;
      font-weight: 600;
      color: var(--text-body);
      text-decoration: none;
      transition: all 140ms ease;
    }

    .nav-link:hover {
      background: var(--bg-subtle);
      color: var(--text-main);
    }

    .nav-link.active {
      background: var(--color-primary-light);
      color: var(--color-primary);
      font-weight: 700;
    }

    .nav-icon {
      width: 18px;
      height: 18px;
      flex-shrink: 0;
    }

    .nav-text {
      flex: 1;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .nav-counter {
      background: var(--bg-subtle);
      color: var(--text-muted);
      font-family: var(--font-mono);
      font-size: 11.5px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 9999px;
      border: 1px solid var(--border-color);
    }

    .nav-link.active .nav-counter {
      background: #DBEAFE;
      color: var(--color-primary-hover);
      border-color: var(--color-primary-border);
    }

    .nav-counter.danger {
      background: var(--color-danger-light);
      color: var(--color-danger);
      border-color: var(--color-danger-border);
    }

    .nav-tag {
      font-size: 10.5px;
      font-weight: 700;
      padding: 2px 6px;
      border-radius: 4px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      background: var(--bg-subtle);
      color: var(--text-muted);
    }

    .nav-link-excel {
      color: var(--color-success);
    }
    .nav-link-excel:hover {
      background: var(--color-success-light);
      color: var(--color-success-hover);
    }
    .nav-link-excel .nav-tag {
      background: var(--color-success-light);
      color: var(--color-success);
      border: 1px solid var(--color-success-border);
    }

    /* Sidebar Footer (User Profile) */
    .sidebar-footer {
      padding: 16px;
      border-top: 1px solid var(--border-color);
      background: #FAFCFE;
    }

    .sidebar-user-card {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .user-avatar {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: linear-gradient(135deg, #2563EB 0%, #3B82F6 100%);
      color: #FFFFFF;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 800;
      flex-shrink: 0;
    }

    .user-details {
      flex: 1;
      min-width: 0;
    }

    .user-name {
      font-size: 13px;
      font-weight: 700;
      color: var(--text-main);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .user-role {
      font-size: 11px;
      color: var(--text-muted);
      font-weight: 500;
    }

    .btn-logout-icon {
      width: 32px;
      height: 32px;
      border-radius: 6px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--color-danger);
      text-decoration: none;
      transition: background 140ms ease;
      flex-shrink: 0;
    }
    .btn-logout-icon:hover {
      background: var(--color-danger-light);
    }

    /* =========================================================================
       MAIN CONTENT VIEWPORT
       ========================================================================= */
    .admin-main {
      flex: 1;
      margin-left: var(--sidebar-width);
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    /* Topbar */
    .main-topbar {
      height: var(--topbar-height);
      background: var(--bg-surface);
      border-bottom: 1px solid var(--border-color);
      padding: 0 28px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 40;
      gap: 16px;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .mobile-menu-btn {
      display: none;
      background: none;
      border: 1px solid var(--border-color);
      border-radius: 6px;
      padding: 6px;
      color: var(--text-main);
      cursor: pointer;
    }

    .topbar-breadcrumb {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 13.5px;
      font-weight: 600;
    }

    .crumb-root {
      color: var(--text-muted);
    }

    .crumb-sep {
      color: var(--border-darker);
    }

    .crumb-current {
      color: var(--text-main);
      font-weight: 700;
    }

    .topbar-right {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .sys-pulse-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--color-success-light);
      border: 1px solid var(--color-success-border);
      color: var(--color-success);
      font-size: 12px;
      font-weight: 700;
      padding: 4px 10px;
      border-radius: 9999px;
    }
    .pulse-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: var(--color-success);
      box-shadow: 0 0 0 2px rgba(5, 150, 105, 0.2);
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
      padding: 6px 12px;
      border-radius: 8px;
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-family: var(--font-family);
      font-size: 13px;
      font-weight: 600;
      padding: 8px 16px;
      border-radius: 8px;
      border: 1px solid transparent;
      cursor: pointer;
      text-decoration: none;
      transition: all 150ms ease;
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

    .btn-primary {
      background: var(--color-primary);
      color: #FFFFFF;
      box-shadow: 0 1px 2px rgba(37, 99, 235, 0.2);
    }
    .btn-primary:hover {
      background: var(--color-primary-hover);
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
    }

    .btn-danger-outline {
      background: var(--color-danger-light);
      border: 1px solid var(--color-danger-border);
      color: var(--color-danger);
    }
    .btn-danger-outline:hover {
      background: var(--color-danger);
      color: #FFFFFF;
      border-color: var(--color-danger);
      transform: translateY(-1px);
    }

    .nav-link-danger {
      color: var(--color-danger);
    }
    .nav-link-danger:hover {
      background: var(--color-danger-light);
      color: var(--color-danger-hover);
    }
    .nav-tag.danger {
      background: var(--color-danger-light);
      color: var(--color-danger);
      border: 1px solid var(--color-danger-border);
    }

    /* Main Content Container */
    .main-content-inner {
      padding: 28px 28px 60px;
      max-width: 1400px;
      width: 100%;
    }

    .page-header-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 24px;
      flex-wrap: wrap;
      gap: 12px;
    }

    .page-heading h1 {
      font-size: 22px;
      font-weight: 800;
      color: var(--text-main);
      letter-spacing: -0.02em;
    }

    .page-heading p {
      font-size: 13px;
      color: var(--text-muted);
      margin-top: 2px;
    }

    /* KPI Grid */
    .kpi-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 16px;
      margin-bottom: 26px;
    }

    .kpi-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 18px 20px;
      box-shadow: var(--shadow-card);
      display: flex;
      flex-direction: column;
      gap: 10px;
      transition: transform 150ms ease, box-shadow 150ms ease;
    }
    .kpi-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
    }

    .kpi-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .kpi-label {
      font-size: 11.5px;
      font-weight: 700;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .kpi-icon-pill {
      width: 36px;
      height: 36px;
      border-radius: 9px;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .kpi-blue .kpi-icon-pill { background: var(--color-primary-light); color: var(--color-primary); }
    .kpi-green .kpi-icon-pill { background: var(--color-success-light); color: var(--color-success); }
    .kpi-red .kpi-icon-pill { background: var(--color-danger-light); color: var(--color-danger); }
    .kpi-purple .kpi-icon-pill { background: var(--color-purple-light); color: var(--color-purple); }

    .kpi-value {
      font-size: 32px;
      font-weight: 800;
      color: var(--text-main);
      font-family: var(--font-mono);
      line-height: 1;
      letter-spacing: -0.02em;
    }

    .kpi-footer {
      font-size: 12px;
      color: var(--text-muted);
      border-top: 1px solid var(--border-color);
      padding-top: 8px;
      margin-top: 2px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    /* Search & Controls */
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
      max-width: 400px;
    }

    .search-input {
      width: 100%;
      background: var(--bg-surface);
      border: 1px solid var(--border-darker);
      border-radius: 8px;
      padding: 9px 14px 9px 38px;
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
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      pointer-events: none;
    }

    .record-count-badge {
      font-size: 12.5px;
      color: var(--text-muted);
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      padding: 6px 12px;
      border-radius: 7px;
    }

    /* Tables */
    .table-card {
      background: var(--bg-surface);
      border: 1px solid var(--border-color);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: var(--shadow-card);
    }

    .table-card-header {
      padding: 16px 20px;
      border-bottom: 1px solid var(--border-color);
      background: var(--bg-surface);
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }

    .table-card-title {
      font-size: 14.5px;
      font-weight: 700;
      color: var(--text-main);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .table-card-sub {
      font-size: 12px;
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
      padding: 12px 16px;
      font-size: 11.5px;
      font-weight: 700;
      color: #475569;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      border-bottom: 1px solid var(--border-color);
    }

    .data-table td {
      padding: 13px 16px;
      font-size: 13.5px;
      border-bottom: 1px solid #F1F5F9;
      color: var(--text-body);
      vertical-align: middle;
    }

    .data-table tbody tr:hover {
      background: #F8FAFC;
    }

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

    .risk-badge {
      font-size: 11.5px;
      font-weight: 700;
      padding: 3px 9px;
      border-radius: 6px;
      display: inline-block;
    }
    .risk-low { background: #DCFCE7; color: #15803D; border: 1px solid #86EFAC; }
    .risk-moderate { background: #FEF3C7; color: #B45309; border: 1px solid #FDE68A; }
    .risk-high { background: #FEE2E2; color: #B91C1C; border: 1px solid #FECACA; }
    .risk-critical { background: #FFE4E6; color: #9F1239; border: 1px solid #FDA4AF; }

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

    .empty-state {
      padding: 50px 20px;
      text-align: center;
      color: var(--text-muted);
    }
    .empty-state-icon {
      font-size: 34px;
      margin-bottom: 10px;
      opacity: 0.7;
    }
    .empty-state-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--text-main);
      margin-bottom: 4px;
    }

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

    /* Mobile Drawer */
    .sidebar-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, 0.4);
      z-index: 90;
    }

    @media (max-width: 992px) {
      .admin-sidebar {
        transform: translateX(-100%);
      }
      .admin-sidebar.open {
        transform: translateX(0);
      }
      .sidebar-overlay.open {
        display: block;
      }
      .admin-main {
        margin-left: 0;
      }
      .mobile-menu-btn {
        display: inline-flex;
      }
      .main-topbar {
        padding: 0 16px;
      }
      .main-content-inner {
        padding: 20px 16px 40px;
      }
    }

    /* Login Screen */
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
       LOGIN SCREEN
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
       AUTHENTICATED ENTERPRISE SIDEBAR ADMIN PORTAL
       ========================================================================= -->
  <div class="admin-layout">
    
    <!-- Mobile Sidebar Backdrop Overlay -->
    <div class="sidebar-overlay" id="sidebar-overlay"></div>

    <!-- LEFT SIDEBAR -->
    <aside class="admin-sidebar" id="admin-sidebar">
      
      <!-- Sidebar Brand Area -->
      <div class="sidebar-brand">
        <a href="https://www.cmgalaxy.com/" target="_blank" rel="noopener noreferrer">
          <img src="assets/cmgalaxy-logo.png" alt="CMGalaxy Logo" class="sidebar-logo">
        </a>
        <span class="sidebar-badge">Admin</span>
      </div>

      <!-- Navigation Links -->
      <nav class="sidebar-nav">
        
        <div class="nav-section-title">Leads & Audits</div>
        
        <!-- Tab 1: Form Submissions -->
        <a href="admin.php?tab=leads" class="nav-link <?= $currentTab === 'leads' ? 'active' : '' ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
            <line x1="16" y1="13" x2="8" y2="13"></line>
            <line x1="16" y1="17" x2="8" y2="17"></line>
            <polyline points="10 9 9 9 8 9"></polyline>
          </svg>
          <span class="nav-text">Form Submissions</span>
          <span class="nav-counter"><?= count($leadsList) ?></span>
        </a>

        <div class="nav-section-title">Security & Traffic</div>

        <!-- Tab 2: Blocked IPs -->
        <a href="admin.php?tab=security" class="nav-link <?= $currentTab === 'security' ? 'active' : '' ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
          </svg>
          <span class="nav-text">Blocked Spam IPs</span>
          <span class="nav-counter <?= $activeBlockedCount > 0 ? 'danger' : '' ?>">
            <?= $activeBlockedCount ?>
          </span>
        </a>

        <!-- Tab 3: Rate Limit Monitor -->
        <a href="admin.php?tab=traffic" class="nav-link <?= $currentTab === 'traffic' ? 'active' : '' ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
          </svg>
          <span class="nav-text">Rate Limit Monitor</span>
          <span class="nav-counter"><?= count($activeTrackers) ?></span>
        </a>

        <div class="nav-section-title">Quick Actions</div>

        <!-- Export to Excel -->
        <a href="admin.php?action=export_excel" class="nav-link nav-link-excel" title="Download all leads in Microsoft Excel (.csv)">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="7 10 12 15 17 10"></polyline>
            <line x1="12" y1="15" x2="12" y2="3"></line>
          </svg>
          <span class="nav-text">Export to Excel</span>
          <span class="nav-tag">CSV</span>
        </a>

        <!-- Clear Sheet Data -->
        <a href="admin.php?action=clear_leads" onclick="return confirm('WARNING: Are you sure you want to completely clear the Excel sheet and remove all lead records? This action cannot be undone.')" class="nav-link nav-link-danger" title="Clear all leads from the Excel sheet">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
          </svg>
          <span class="nav-text">Clear Sheet Data</span>
          <span class="nav-tag danger">RESET</span>
        </a>

        <!-- Public App Preview -->
        <a href="index.html" target="_blank" class="nav-link" title="Open live public audit application">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
            <polyline points="15 3 21 3 21 9"></polyline>
            <line x1="10" y1="14" x2="21" y2="3"></line>
          </svg>
          <span class="nav-text">Public App</span>
          <span class="nav-tag">↗</span>
        </a>

      </nav>

      <!-- Sidebar User Footer -->
      <div class="sidebar-footer">
        <div class="sidebar-user-card">
          <div class="user-avatar">
            <?= strtoupper(substr($_SESSION['cmg_admin_user'] ?? 'A', 0, 1)) ?>
          </div>
          <div class="user-details">
            <div class="user-name"><?= htmlspecialchars($_SESSION['cmg_admin_user'] ?? 'Admin') ?></div>
            <div class="user-role">Super Admin</div>
          </div>
          <a href="admin.php?logout=1" class="btn-logout-icon" title="Logout from console">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
              <polyline points="16 17 21 12 16 7"></polyline>
              <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
          </a>
        </div>
      </div>

    </aside>

    <!-- MAIN VIEWPORT -->
    <main class="admin-main">
      
      <!-- Topbar Header -->
      <header class="main-topbar">
        <div class="topbar-left">
          <button class="mobile-menu-btn" id="mobile-toggle" aria-label="Toggle Navigation">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="3" y1="12" x2="21" y2="12"></line>
              <line x1="3" y1="6" x2="21" y2="6"></line>
              <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
          </button>
          
          <div class="topbar-breadcrumb">
            <span class="crumb-root">Console</span>
            <span class="crumb-sep">/</span>
            <span class="crumb-current">
              <?php
                if ($currentTab === 'security') echo 'Blocked Spam IPs & Security';
                elseif ($currentTab === 'traffic') echo 'Live IP Rate Limit Monitor';
                else echo 'Form Submissions (Leads)';
              ?>
            </span>
          </div>
        </div>

        <div class="topbar-right">
          <div class="sys-pulse-badge">
            <span class="pulse-dot"></span>
            <span>Live Protected</span>
          </div>

          <div class="time-indicator">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"></circle>
              <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            <span>IST: <?= date('M j, Y • h:i A') ?></span>
          </div>

          <!-- 1-Click Excel Download Button -->
          <a href="admin.php?action=export_excel" class="btn btn-excel" title="Download all leads in Microsoft Excel (.csv)">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
              <polyline points="7 10 12 15 17 10"></polyline>
              <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
            Export Excel
          </a>
        </div>
      </header>

      <!-- Main Content Inner Wrapper -->
      <div class="main-content-inner">

        <!-- Page Header Info -->
        <div class="page-header-row">
          <div class="page-heading">
            <h1>
              <?php
                if ($currentTab === 'security') echo 'Security & Blocked Spam IPs';
                elseif ($currentTab === 'traffic') echo '24-Hour IP Rate Limit Monitor';
                else echo 'D2C Attribution Form Submissions';
              ?>
            </h1>
            <p>
              <?php
                if ($currentTab === 'security') echo 'Review automated spam filters and manage blacklisted IP addresses.';
                elseif ($currentTab === 'traffic') echo 'Real-time tracking of 3 submissions per 24 hours per unique IP address.';
                else echo 'All verified form captures with lead contact details and generated PDF audit reports.';
              ?>
            </p>
          </div>
        </div>

        <!-- Feedback Alert Messages -->
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
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'cleared'): ?>
          <div class="alert-banner alert-success">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
            <div>The Excel sheet and all recorded form submissions have been completely cleared successfully.</div>
          </div>
        <?php endif; ?>

        <!-- KPI Metric Cards Grid -->
        <div class="kpi-grid">
          
          <!-- Card 1: Total Leads -->
          <div class="kpi-card kpi-blue">
            <div class="kpi-card-top">
              <span class="kpi-label">Total Leads</span>
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
              All-time form submissions
            </div>
          </div>

          <!-- Card 2: Today's Submissions -->
          <div class="kpi-card kpi-green">
            <div class="kpi-card-top">
              <span class="kpi-label">Today's Leads</span>
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
              Past 24 hours window
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
              <span class="kpi-label">Monitored IPs</span>
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
              Unique visitors today
            </div>
          </div>

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

            <div style="display: flex; align-items: center; gap: 10px;">
              <div class="record-count-badge">
                Showing <strong><?= count($leadsList) ?></strong> recorded submissions
              </div>
              <a href="admin.php?action=clear_leads" 
                 onclick="return confirm('WARNING: Are you sure you want to completely clear the Excel sheet and remove all lead records? This action cannot be undone.')" 
                 class="btn btn-danger-outline" 
                 style="font-size: 12px; padding: 6px 12px;"
                 title="Clear all leads from the Excel sheet">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="3 6 5 6 21 6"></polyline>
                  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                </svg>
                Clear Sheet Data
              </a>
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
          <div class="table-card" style="padding: 20px; margin-bottom: 22px;">
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
    </main>

  </div>

  <!-- Real-Time Instant Search & Mobile Toggle Scripts -->
  <script>
    // Search Filter
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

    // Mobile Sidebar Toggle
    const mobileToggle = document.getElementById('mobile-toggle');
    const sidebar = document.getElementById('admin-sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    if (mobileToggle && sidebar && overlay) {
      mobileToggle.addEventListener('click', function() {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
      });
      overlay.addEventListener('click', function() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
      });
    }
  </script>
<?php endif; ?>

</body>
</html>
