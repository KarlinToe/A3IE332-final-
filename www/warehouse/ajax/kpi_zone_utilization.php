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

// Get ALL warehouses for this employee
$r = mysqli_query($conn, "
    SELECT DISTINCT ToWarehouseID FROM LotCustodyEvent
    WHERE EmployeeID='$e' AND ToWarehouseID IS NOT NULL
");
$wids = [];
while ($row = mysqli_fetch_assoc($r)) { $wids[] = "'" . mysqli_real_escape_string($conn, $row['ToWarehouseID']) . "'"; }
if (empty($wids)) { echo json_encode([]); exit; }
$in = implode(',', $wids);

$r2 = mysqli_query($conn, "
    SELECT sz.Classification,
           ROUND(100.0 * COALESCE(SUM(bl.LotVolume),0) / NULLIF(SUM(sz.CapacityVolume),0), 1) AS avg_util
    FROM StorageZone sz
    LEFT JOIN StoredIn si ON si.WarehouseID=sz.WarehouseID AND si.ZoneCode=sz.ZoneCode AND si.EndTime IS NULL
    LEFT JOIN BatchLot bl ON bl.VendorID=si.VendorID AND bl.BatchNumber=si.BatchNumber AND bl.LotSeq=si.LotSeq
    WHERE sz.WarehouseID IN ($in)
    GROUP BY sz.Classification
");
$result = [];
while ($row = mysqli_fetch_assoc($r2)) { $result[$row['Classification']] = (float)$row['avg_util']; }
echo json_encode($result);
