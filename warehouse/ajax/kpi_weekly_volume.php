<?php
// W-KPI-5: Weekly shipment volume filtered to this staff member's warehouse
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

if (!$wid) { echo json_encode(['outbound'=>[],'inbound'=>[]]); exit; }

// Outbound from this warehouse
$stmt2 = $pdo->prepare("
    SELECT YEARWEEK(s.DepartureTime,3) AS iso_week,
           MIN(DATE(s.DepartureTime)) AS week_start,
           SUM(sl.QuantityVolume) AS volume
    FROM Shipment s
    JOIN ShipmentLot sl ON sl.ShipmentID = s.ShipmentID
    WHERE s.OriginWarehouseID = ?
      AND s.DepartureTime >= ?
      AND s.DepartureTime IS NOT NULL
    GROUP BY YEARWEEK(s.DepartureTime,3)
    ORDER BY iso_week ASC
");
$stmt2->execute(array($wid, date('Y-m-d', strtotime(app_today().' -8 weeks'))));
$out_rows = $stmt2->fetchAll();

// Inbound to this warehouse
$stmt3 = $pdo->prepare("
    SELECT YEARWEEK(s.ArrivalTime,3) AS iso_week,
           MIN(DATE(s.ArrivalTime)) AS week_start,
           SUM(sl.QuantityVolume) AS volume
    FROM Shipment s
    JOIN ShipmentLot sl ON sl.ShipmentID = s.ShipmentID
    WHERE s.DestinationWarehouseID = ?
      AND s.ArrivalTime >= ?
      AND s.ArrivalTime IS NOT NULL
    GROUP BY YEARWEEK(s.ArrivalTime,3)
    ORDER BY iso_week ASC
");
$stmt3->execute(array($wid, date('Y-m-d', strtotime(app_today().' -8 weeks'))));
$in_rows = $stmt3->fetchAll();

$outbound = [];
foreach ($out_rows as $r) { $outbound[] = ['week'=>$r['week_start'],'volume'=>(int)$r['volume']]; }
$inbound = [];
foreach ($in_rows as $r)  { $inbound[]  = ['week'=>$r['week_start'],'volume'=>(int)$r['volume']]; }

echo json_encode(['outbound'=>$outbound,'inbound'=>$inbound,'warehouse'=>$wid]);
