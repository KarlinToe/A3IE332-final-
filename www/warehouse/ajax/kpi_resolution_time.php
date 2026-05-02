<?php
ini_set('session.cookie_path', '/');
session_set_cookie_params(0, '/');
session_start();
if (!isset($_SESSION['user'])) { header('Content-Type: application/json'); echo '{}'; exit; }
include '../../includes/db.php';
include '../../includes/constants.php';
header('Content-Type: application/json');
error_reporting(0);

$eid = $_SESSION['user']['EmployeeID'];
$e   = mysqli_real_escape_string($conn, $eid);

$r = mysqli_query($conn, "
    SELECT DISTINCT ToWarehouseID FROM LotCustodyEvent
    WHERE EmployeeID='$e' AND ToWarehouseID IS NOT NULL
");
$wids = [];
while ($row = mysqli_fetch_assoc($r)) { $wids[] = "'" . mysqli_real_escape_string($conn, $row['ToWarehouseID']) . "'"; }
if (empty($wids)) { echo json_encode(['resolved_count'=>0,'avg_hours'=>null,'avg_days'=>null]); exit; }
$in = implode(',', $wids);

$cutoff = date('Y-m-d', strtotime(app_today().' -90 days'));
$r2 = mysqli_query($conn, "
    SELECT COUNT(*) AS resolved_count,
           ROUND(AVG(TIMESTAMPDIFF(MINUTE,StartTime,EndTime))/60.0,1) AS avg_hours,
           ROUND(AVG(TIMESTAMPDIFF(MINUTE,StartTime,EndTime))/1440.0,2) AS avg_days
    FROM ZoneTempBreach
    WHERE ResolutionStatus='Resolved'
      AND WarehouseID IN ($in)
      AND StartTime >= '$cutoff'
");
$row = mysqli_fetch_assoc($r2);
echo json_encode([
    'resolved_count' => (int)$row['resolved_count'],
    'avg_hours'      => $row['avg_hours'] !== null ? (float)$row['avg_hours'] : null,
    'avg_days'       => $row['avg_days']  !== null ? (float)$row['avg_days']  : null,
]);
