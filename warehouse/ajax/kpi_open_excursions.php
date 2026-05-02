<?php
// W-KPI-3: Open temperature excursions filtered to this staff member's warehouse
include '../../includes/auth_check.php';
include '../../includes/db_compat.php';
header('Content-Type: application/json');
error_reporting(0);

$eid = $_SESSION['user']['EmployeeID'];

// Determine this staff member's home warehouse via their most frequent custody events
$stmt = $pdo->prepare("
    SELECT ToWarehouseID, COUNT(*) AS cnt
    FROM LotCustodyEvent
    WHERE EmployeeID = ? AND ToWarehouseID IS NOT NULL
    GROUP BY ToWarehouseID ORDER BY cnt DESC, MAX(EventTime) DESC LIMIT 1
");
$stmt->execute(array($eid));
$wrow = $stmt->fetch();
$wid = $wrow ? $wrow['ToWarehouseID'] : null;

if (!$wid) { echo json_encode(['total'=>0,'zones'=>[]]); exit; }

$stmt2 = $pdo->prepare("
    SELECT COUNT(*) FROM ZoneTempBreach
    WHERE ResolutionStatus IN ('Open', 'Under Review') AND WarehouseID = ?
");
$stmt2->execute(array($wid));
$total = (int)$stmt2->fetchColumn();

$stmt3 = $pdo->prepare("
    SELECT ztb.WarehouseID, ztb.ZoneCode, COUNT(*) AS open_count
    FROM ZoneTempBreach ztb
    WHERE ztb.ResolutionStatus IN ('Open', 'Under Review') AND ztb.WarehouseID = ?
    GROUP BY ztb.WarehouseID, ztb.ZoneCode ORDER BY open_count DESC
");
$stmt3->execute(array($wid));
$rows = $stmt3->fetchAll();

$zones = [];
foreach ($rows as $r) {
    $zones[] = ['WarehouseID'=>$r['WarehouseID'],'ZoneCode'=>$r['ZoneCode'],'count'=>(int)$r['open_count']];
}
echo json_encode(['total'=>$total,'zones'=>$zones,'warehouse'=>$wid]);
