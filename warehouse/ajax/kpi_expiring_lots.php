<?php
// W-KPI-2: Lots expiring within d days filtered to this staff member's warehouse
include '../../includes/auth_check.php';
include '../../includes/constants.php';
include '../../includes/db_compat.php';
header('Content-Type: application/json');
error_reporting(0);

$eid = $_SESSION['user']['EmployeeID'];
$d   = isset($_GET['d']) ? max(1, (int)$_GET['d']) : 30;

$stmt = $pdo->prepare("
    SELECT ToWarehouseID, COUNT(*) AS cnt
    FROM LotCustodyEvent
    WHERE EmployeeID = ? AND ToWarehouseID IS NOT NULL
    GROUP BY ToWarehouseID ORDER BY cnt DESC, MAX(EventTime) DESC LIMIT 1
");
$stmt->execute(array($eid));
$wrow = $stmt->fetch();
$wid  = $wrow ? $wrow['ToWarehouseID'] : null;

if (!$wid) { echo json_encode(['count'=>0,'d'=>$d]); exit; }

$today = app_today();
$stmt2 = $pdo->prepare("
    SELECT COUNT(*) AS cnt
    FROM StoredIn si
    JOIN Batch b ON b.VendorID = si.VendorID AND b.BatchNumber = si.BatchNumber
    WHERE si.EndTime IS NULL
      AND si.WarehouseID = ?
      AND b.ExpiryDate >= ?
      AND b.ExpiryDate <= DATE_ADD(?, INTERVAL $d DAY)
");
$stmt2->execute(array($wid, $today, $today));
$row = $stmt2->fetch();
echo json_encode(['count'=>(int)$row['cnt'],'d'=>$d,'warehouse'=>$wid]);
