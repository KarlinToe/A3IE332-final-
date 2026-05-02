<?php
// W-KPI-1: Zone utilization filtered to this staff member's warehouse
include '../../includes/auth_check.php';
include '../../includes/db_compat.php';
header('Content-Type: application/json');
error_reporting(0);

$eid = $_SESSION['user']['EmployeeID'];

$stmt = $pdo->prepare("
    SELECT ToWarehouseID, COUNT(*) AS cnt
    FROM LotCustodyEvent
    WHERE EmployeeID = ? AND ToWarehouseID IS NOT NULL
    GROUP BY ToWarehouseID ORDER BY cnt DESC, MAX(EventTime) DESC LIMIT 1
");
$stmt->execute(array($eid));
$wrow = $stmt->fetch();
$wid  = $wrow ? $wrow['ToWarehouseID'] : null;

if (!$wid) { echo json_encode([]); exit; }

$stmt2 = $pdo->prepare("
    SELECT sz.Classification,
           ROUND(100.0 * COALESCE(SUM(bl.LotVolume),0) / NULLIF(SUM(sz.CapacityVolume),0), 1) AS avg_util
    FROM StorageZone sz
    LEFT JOIN StoredIn si ON si.WarehouseID=sz.WarehouseID AND si.ZoneCode=sz.ZoneCode AND si.EndTime IS NULL
    LEFT JOIN BatchLot bl ON bl.VendorID=si.VendorID AND bl.BatchNumber=si.BatchNumber AND bl.LotSeq=si.LotSeq
    WHERE sz.WarehouseID = ?
    GROUP BY sz.Classification
");
$stmt2->execute(array($wid));
$rows = $stmt2->fetchAll();

$result = [];
foreach ($rows as $r) {
    $result[$r['Classification']] = (float)$r['avg_util'];
}
$result['_warehouse'] = $wid;
echo json_encode($result);
