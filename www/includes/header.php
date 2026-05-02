<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$user     = $_SESSION['user'];
$role     = $user['Role'];
$name     = htmlspecialchars($user['FirstName'] . ' ' . $user['LastName']);
$initials = strtoupper(substr($user['FirstName'],0,1) . substr($user['LastName'],0,1));
$self     = $_SERVER['PHP_SELF'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars(isset($page_title) ? $page_title : 'PharmaCool ERP'); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/~g1154085/css/style.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
</head>
<body>
<div class="app-shell">

<aside class="sidebar">
  <div class="sidebar-brand">PharmaCool</div>

  <nav class="sidebar-nav">
    <?php if ($role === 'driver'): ?>
      <a href="/~g1154085/driver/home.php" class="nav-item <?php echo strpos($self,'driver/home') !== false ? 'active' : ''; ?>">Home</a>
      <a href="/~g1154085/driver/shipment_detail.php" class="nav-item <?php echo strpos($self,'shipment_detail') !== false ? 'active' : ''; ?>">Shipments</a>
      <a href="/~g1154085/driver/vehicle.php" class="nav-item <?php echo strpos($self,'vehicle') !== false ? 'active' : ''; ?>">My Vehicle</a>
      <a href="/~g1154085/driver/kpi.php" class="nav-item <?php echo strpos($self,'driver/kpi') !== false ? 'active' : ''; ?>">My KPIs</a>
    <?php else: ?>
      <a href="/~g1154085/warehouse/overview.php" class="nav-item <?php echo strpos($self,'overview') !== false ? 'active' : ''; ?>">Overview</a>
      <a href="/~g1154085/warehouse/shipments.php" class="nav-item <?php echo strpos($self,'warehouse/shipments') !== false ? 'active' : ''; ?>">Shipments</a>
      <a href="/~g1154085/warehouse/batch_lookup.php" class="nav-item <?php echo strpos($self,'batch_lookup') !== false ? 'active' : ''; ?>">Batch &amp; Vendor</a>
      <a href="/~g1154085/warehouse/custody_log.php" class="nav-item <?php echo strpos($self,'custody_log') !== false ? 'active' : ''; ?>">Custody Log</a>
      <a href="/~g1154085/warehouse/kpi.php" class="nav-item <?php echo strpos($self,'warehouse/kpi') !== false ? 'active' : ''; ?>">KPIs</a>
    <?php endif; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="user-avatar"><?php echo $initials; ?></div>
      <div class="user-info">
        <div class="user-name"><?php echo $name; ?></div>
        <div class="user-role"><?php echo htmlspecialchars(ucwords($role)); ?></div>
      </div>
    </div>
    <a href="/~g1154085/logout.php" class="logout-btn" title="Logout">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
    </a>
  </div>
</aside>

<main class="main-content">

<div class="topbar">
  <div class="topbar-breadcrumb">
    <span class="breadcrumb-home">Home</span>
    <?php if (isset($page_title)): ?>
      <span class="breadcrumb-sep">›</span>
      <span class="breadcrumb-current"><?php echo htmlspecialchars($page_title); ?></span>
    <?php endif; ?>
  </div>

  <div class="topbar-search">
    <div class="search-box">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="text" id="global-search" placeholder="Search shipment, batch, vendor, clinic..." autocomplete="off">
    </div>
    <div id="search-dropdown" class="search-dropdown hidden"></div>
  </div>

  <div class="topbar-actions">
    <div class="topbar-avatar" title="<?php echo $name; ?> (<?php echo ucwords($role); ?>)"><?php echo $initials; ?></div>
  </div>
</div>
<script src="/~g1154085/js/search.js"></script>
<script src="/~g1154085/js/session_timeout.js"></script>
