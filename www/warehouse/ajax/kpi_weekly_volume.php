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
if (empty($wids)) { echo json_encode(['outbound'=>[],'inbound'=>[]]); exit; }
$in = implode(',', $wids);

$cutoff = date('Y-m-d', strtotime(app_today().' -8 weeks'));

$r2 = mysqli_query($conn, "
    SELECT YEARWEEK(s.DepartureTime,3) AS iso_week,
           MIN(DATE(s.DepartureTime)) AS week_start,
           SUM(sl.QuantityVolume) AS volume
    FROM Shipment s
    JOIN ShipmentLot sl ON sl.ShipmentID=s.ShipmentID
    WHERE s.OriginWarehouseID IN ($in)
      AND s.DepartureTime >= '$cutoff'
      AND s.DepartureTime IS NOT NULL
    GROUP BY YEARWEEK(s.DepartureTime,3)
    ORDER BY iso_week ASC
");
$outbound = [];
while ($row = mysqli_fetch_assoc($r2)) { $outbound[] = ['week'=>$row['week_start'],'volume'=>(int)$row['volume']]; }

$r3 = mysqli_query($conn, "
    SELECT YEARWEEK(s.ArrivalTime,3) AS iso_week,
           MIN(DATE(s.ArrivalTime)) AS week_start,
           SUM(sl.QuantityVolume) AS volume
    FROM Shipment s
    JOIN ShipmentLot sl ON sl.ShipmentID=s.ShipmentID
    WHERE s.DestinationWarehouseID IN ($in)
      AND s.ArrivalTime >= '$cutoff'
      AND s.ArrivalTime IS NOT NULL
    GROUP BY YEARWEEK(s.ArrivalTime,3)
    ORDER BY iso_week ASC
");
$inbound = [];
while ($row = mysqli_fetch_assoc($r3)) { $inbound[] = ['week'=>$row['week_start'],'volume'=>(int)$row['volume']]; }

echo json_encode(['outbound'=>$outbound,'inbound'=>$inbound]);
