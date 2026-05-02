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
if (empty($wids)) { echo json_encode(['total'=>0,'zones':[]]); exit; }
$in = implode(',', $wids);

$r2 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM ZoneTempBreach WHERE ResolutionStatus IN ('Open','Under Review') AND WarehouseID IN ($in)");
$total = (int)mysqli_fetch_assoc($r2)['cnt'];

$r3 = mysqli_query($conn, "
    SELECT WarehouseID, ZoneCode, COUNT(*) AS open_count
    FROM ZoneTempBreach
    WHERE ResolutionStatus IN ('Open','Under Review') AND WarehouseID IN ($in)
    GROUP BY WarehouseID, ZoneCode ORDER BY open_count DESC
");
$zones = [];
while ($row = mysqli_fetch_assoc($r3)) {
    $zones[] = ['WarehouseID'=>$row['WarehouseID'],'ZoneCode'=>$row['ZoneCode'],'count'=>(int)$row['open_count']];
}
echo json_encode(['total'=>$total,'zones'=>$zones]);
