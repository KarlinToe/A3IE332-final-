<?php
// W-KPI-4: Avg excursion resolution time filtered to this staff member's warehouse
include '../../includes/auth_check.php';
include '../../includes/constants.php';
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

if (!$wid) { echo json_encode(['resolved_count'=>0,'avg_hours'=>null,'avg_days'=>null]); exit; }

$stmt2 = $pdo->prepare("
    SELECT COUNT(*) AS resolved_count,
           ROUND(AVG(TIMESTAMPDIFF(MINUTE, StartTime, EndTime)) / 60.0, 1) AS avg_hours,
           ROUND(AVG(TIMESTAMPDIFF(MINUTE, StartTime, EndTime)) / 1440.0, 2) AS avg_days
    FROM ZoneTempBreach
    WHERE ResolutionStatus = 'Resolved'
      AND WarehouseID = ?
      AND StartTime >= ?
");
$stmt2->execute(array($wid, date('Y-m-d', strtotime(app_today().' -90 days'))));
$row = $stmt2->fetch();
echo json_encode([
    'resolved_count' => (int)$row['resolved_count'],
    'avg_hours'      => $row['avg_hours'] !== null ? (float)$row['avg_hours'] : null,
    'avg_days'       => $row['avg_days']  !== null ? (float)$row['avg_days']  : null,
    'warehouse'      => $wid
]);
