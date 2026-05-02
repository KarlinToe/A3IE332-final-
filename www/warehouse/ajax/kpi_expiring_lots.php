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
$d   = isset($_GET['d']) ? max(1, (int)$_GET['d']) : 30;

$r = mysqli_query($conn, "
    SELECT DISTINCT ToWarehouseID FROM LotCustodyEvent
    WHERE EmployeeID='$e' AND ToWarehouseID IS NOT NULL
");
$wids = [];
while ($row = mysqli_fetch_assoc($r)) { $wids[] = "'" . mysqli_real_escape_string($conn, $row['ToWarehouseID']) . "'"; }
if (empty($wids)) { echo json_encode(['count'=>0,'d'=>$d]); exit; }
$in = implode(',', $wids);

$today  = app_today();
$future = date('Y-m-d', strtotime("$today +$d days"));

$r2 = mysqli_query($conn, "
    SELECT COUNT(*) AS cnt
    FROM StoredIn si
    JOIN Batch b ON b.VendorID=si.VendorID AND b.BatchNumber=si.BatchNumber
    WHERE si.EndTime IS NULL
      AND si.WarehouseID IN ($in)
      AND b.ExpiryDate >= '$today'
      AND b.ExpiryDate <= '$future'
");
$row = mysqli_fetch_assoc($r2);
echo json_encode(['count'=>(int)$row['cnt'],'d'=>$d]);
