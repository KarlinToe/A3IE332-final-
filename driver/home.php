<?php
include '../includes/auth_check.php';
require_role('driver');
include '../includes/constants.php';
include '../includes/db_compat.php';
global $conn;
$emp  = current_user();
$eid  = mysqli_real_escape_string($conn, $emp['EmployeeID']);

// KPI 1: shipments this month
$r = mysqli_query($conn, "
    SELECT COUNT(DISTINCT s.ShipmentID) AS cnt
    FROM Shipment s
    JOIN LotCustodyEvent ce ON ce.FromVehicleID=s.VehicleID AND ce.FromLocation='Vehicle'
         AND ce.EmployeeID='$eid'
    WHERE s.Status='delivered'
      AND YEAR(s.ArrivalTime)=YEAR('".app_today()."')
      AND MONTH(s.ArrivalTime)=MONTH('".app_today()."')
");
$_tmp = mysqli_fetch_assoc($r); $ship_month = (int)$_tmp['cnt'];

// KPI 2: on-time rate
$r = mysqli_query($conn, "
    SELECT COUNT(*) AS total,
           SUM(CASE
               WHEN TIMESTAMPDIFF(MINUTE,s.DepartureTime,s.ArrivalTime) <=
                    COALESCE(
                        (SELECT AVG(TIMESTAMPDIFF(MINUTE,s2.DepartureTime,s2.ArrivalTime))
                         FROM Shipment s2
                         WHERE s2.Status='delivered'
                           AND s2.OriginWarehouseID=s.OriginWarehouseID
                           AND s2.DestinationWarehouseID=s.DestinationWarehouseID
                           AND s2.DestinationClinicID=s.DestinationClinicID
                           AND s2.DestinationType=s.DestinationType),
                        (SELECT AVG(TIMESTAMPDIFF(MINUTE,s3.DepartureTime,s3.ArrivalTime))
                         FROM Shipment s3 WHERE s3.Status='delivered')
                    )
               THEN 1 ELSE 0
           END) AS on_time
    FROM Shipment s
    WHERE s.Status='delivered'
      AND s.ShipmentID IN (
          SELECT DISTINCT s4.ShipmentID FROM Shipment s4
          JOIN LotCustodyEvent ce ON ce.FromVehicleID=s4.VehicleID
               AND ce.FromLocation='Vehicle' AND ce.EmployeeID='$eid'
      )
");
$krow = mysqli_fetch_assoc($r);
$total_del    = (int)($krow['total'] ? $krow['total'] : 0);
$on_time_rate = $total_del > 0 ? min(100.0, round(100.0 * $krow['on_time'] / $total_del, 1)) : 0;

// KPI 3: open alerts
$r = mysqli_query($conn, "
    SELECT COUNT(DISTINCT stb.ShipmentID) AS cnt
    FROM ShipmentTempBreach stb
    JOIN Shipment s ON s.ShipmentID=stb.ShipmentID
    JOIN LotCustodyEvent ce ON ce.FromVehicleID=s.VehicleID AND ce.FromLocation='Vehicle'
         AND ce.EmployeeID='$eid'
    WHERE stb.ResolutionStatus='Open'
");
$_tmp = mysqli_fetch_assoc($r); $open_alerts = (int)$_tmp['cnt'];

// KPI 4: vehicle
$r = mysqli_query($conn, "
    SELECT v.VehicleID, v.Status FROM Vehicle v
    JOIN Shipment s ON s.VehicleID=v.VehicleID
    JOIN LotCustodyEvent ce ON ce.ToVehicleID=s.VehicleID AND ce.ToLocation='Vehicle' AND ce.EmployeeID='$eid'
    WHERE s.Status IN ('in transit','delayed')
    ORDER BY s.DepartureTime DESC LIMIT 1
");
$veh = mysqli_fetch_assoc($r);
$veh_label = $veh ? $veh['VehicleID'].' &mdash; '.ucwords($veh['Status']) : 'No active shipment';

// Shipment list
$sf = isset($_GET['status']) ? $_GET['status'] : '';
if (!in_array($sf, array('','delivered','in transit','delayed','scheduled'))) $sf = '';
$sf_safe = mysqli_real_escape_string($conn, $sf);
$sf_clause = $sf !== '' ? "HAVING Status='$sf_safe'" : '';

$r = mysqli_query($conn, "
    SELECT * FROM (
        SELECT DISTINCT s.ShipmentID, wh_o.WarehouseName AS origin_name,
            CASE s.DestinationType WHEN 'Warehouse' THEN wh_d.WarehouseName ELSE c.ClinicName END AS destination_name,
            s.DepartureTime, s.ArrivalTime, s.Status, s.VehicleID
        FROM Shipment s
        JOIN LotCustodyEvent ce ON ce.FromVehicleID=s.VehicleID AND ce.FromLocation='Vehicle'
             AND ce.EmployeeID='$eid'
        JOIN Warehouse wh_o ON wh_o.WarehouseID=s.OriginWarehouseID
        LEFT JOIN Warehouse wh_d ON wh_d.WarehouseID=s.DestinationWarehouseID
        LEFT JOIN Clinic c ON c.ClinicID=s.DestinationClinicID
        UNION
        SELECT DISTINCT s.ShipmentID, wh_o.WarehouseName,
            CASE s.DestinationType WHEN 'Warehouse' THEN wh_d.WarehouseName ELSE c.ClinicName END,
            s.DepartureTime, s.ArrivalTime, s.Status, s.VehicleID
        FROM Shipment s
        JOIN LotCustodyEvent ce ON ce.ToVehicleID=s.VehicleID AND ce.ToLocation='Vehicle' AND ce.EmployeeID='$eid'
        JOIN Warehouse wh_o ON wh_o.WarehouseID=s.OriginWarehouseID
        LEFT JOIN Warehouse wh_d ON wh_d.WarehouseID=s.DestinationWarehouseID
        LEFT JOIN Clinic c ON c.ClinicID=s.DestinationClinicID
        WHERE s.Status IN ('in transit','delayed','scheduled')
    ) all_s $sf_clause
    ORDER BY DepartureTime DESC
");
$shipments = [];
while ($row = mysqli_fetch_assoc($r)) {
    $shipments[] = $row;
}

$page_title = 'Driver Home';
include '../includes/header.php';
?>
<div class="page-body">
  <div class="kpi-row">
    <div class="kpi-card">
      <span class="kpi-icon">&#128666;</span>
      <div><div class="kpi-value"><?php echo $ship_month; ?></div><div class="kpi-label">Shipments this month</div></div>
    </div>
    <div class="kpi-card <?php echo $on_time_rate < 80 ? 'kpi-warn':''; ?>">
      <span class="kpi-icon">&#9200;</span>
      <div><div class="kpi-value"><?php echo $on_time_rate; ?>%</div><div class="kpi-label">On-time delivery rate</div></div>
    </div>
    <div class="kpi-card <?php echo $open_alerts > 0 ? 'kpi-danger':''; ?>">
      <span class="kpi-icon">&#127777;</span>
      <div><div class="kpi-value"><?php echo $open_alerts; ?></div><div class="kpi-label">Open temp alerts</div></div>
    </div>
    <div class="kpi-card">
      <span class="kpi-icon">&#128663;</span>
      <div><div class="kpi-value kpi-value-sm"><?php echo $veh_label; ?></div><div class="kpi-label">Vehicle status</div></div>
    </div>
  </div>

  <div class="section-card">
    <div class="section-header">
      <h2 class="section-title">My shipments</h2>
      <div class="filter-pills">
        <?php
        $filters = array(''=>'All','delivered'=>'Delivered','in transit'=>'In Transit','delayed'=>'Delayed','scheduled'=>'Scheduled');
        foreach ($filters as $v=>$l) {
            $active = ($sf===$v) ? 'active' : '';
            echo "<a href='?status=".urlencode($v)."' class='pill $active'>$l</a>";
        }
        ?>
      </div>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Shipment</th><th>From</th><th>To</th><th>Departed</th><th>Arrived</th><th>Status</th><th>Temp</th></tr></thead>
        <tbody>
          <?php if (empty($shipments)): ?>
          <tr><td colspan="7" class="empty-row">No shipments found.</td></tr>
          <?php else: foreach ($shipments as $s): ?>
          <tr class="clickable-row" onclick="window.location='/~g1154085/driver/shipment_detail.php?id=<?php echo urlencode($s['ShipmentID']); ?>'">
            <td class="mono"><?php echo htmlspecialchars(fmt_sid($s['ShipmentID'])); ?></td>
            <td><?php echo htmlspecialchars($s['origin_name']); ?></td>
            <td><?php echo htmlspecialchars($s['destination_name'] ? $s['destination_name'] : '&mdash;'); ?></td>
            <td><?php echo fmt_dt($s['DepartureTime']); ?></td>
            <td><?php echo fmt_dt($s['ArrivalTime']); ?></td>
            <td><?php echo status_badge($s['Status']); ?></td>
            <td><canvas class="sparkline" id="spark-<?php echo htmlspecialchars($s['ShipmentID']); ?>" width="80" height="30" data-sid="<?php echo htmlspecialchars($s['ShipmentID']); ?>"></canvas></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var canvases = document.querySelectorAll('.sparkline');
    if (!canvases.length) return;
    var ids = [];
    for (var i=0; i<canvases.length; i++) ids.push(canvases[i].dataset.sid);
    fetch('/~g1154085/driver/ajax/get_sparklines.php?ids=' + ids.map(encodeURIComponent).join(','))
        .then(function(r){ return r.json(); })
        .then(function(data){
            for (var i=0; i<canvases.length; i++) {
                var canvas = canvases[i];
                var sid = canvas.dataset.sid;
                var readings = data[sid];
                if (!readings || !readings.length) continue;
                var temps  = readings.map(function(r){ return parseFloat(r.Temperature); });
                var colors = readings.map(function(r){
                    return r.ReadingStatus==='Missing'?'#f59e0b':r.ReadingStatus==='Suspect'?'#ef4444':'#4f46e5';
                });
                new Chart(canvas, { type:'line',
                    data:{ labels:temps.map(function(_,i){return i;}),
                           datasets:[{data:temps,borderColor:'#4f46e5',borderWidth:1.5,
                                      pointBackgroundColor:colors,pointRadius:1.5,tension:0.2,fill:false}]},
                    options:{responsive:false,animation:false,
                             plugins:{legend:{display:false},tooltip:{enabled:false}},
                             scales:{x:{display:false},y:{display:false}}}});
            }
        }).catch(function(){});
});
</script>
<?php include '../includes/footer.php'; ?>