<?php
include '../includes/auth_check.php';
require_role('driver');
include '../includes/constants.php';
include '../includes/db_compat.php';
global $conn;

$emp = current_user();
$eid = mysqli_real_escape_string($conn, $emp['EmployeeID']);

$sf = isset($_GET['status']) ? $_GET['status'] : '';
if (!in_array($sf, array('','delivered','in transit','delayed','scheduled'))) $sf = '';
$sf_safe = mysqli_real_escape_string($conn, $sf);

// Part 1: delivered shipments (driver unloaded)
$shipments = array();
$seen = array();

$q1 = "SELECT DISTINCT s.ShipmentID, wh_o.WarehouseName AS origin_name,
    CASE s.DestinationType WHEN 'Warehouse' THEN wh_d.WarehouseName ELSE c.ClinicName END AS destination_name,
    s.DepartureTime, s.ArrivalTime, s.Status, s.VehicleID
FROM Shipment s
JOIN LotCustodyEvent ce ON ce.FromVehicleID=s.VehicleID AND ce.FromLocation='Vehicle'
     AND ce.EmployeeID='$eid'
JOIN Warehouse wh_o ON wh_o.WarehouseID=s.OriginWarehouseID
LEFT JOIN Warehouse wh_d ON wh_d.WarehouseID=s.DestinationWarehouseID
LEFT JOIN Clinic c ON c.ClinicID=s.DestinationClinicID";
if ($sf !== '') $q1 .= " WHERE s.Status='$sf_safe'";
$q1 .= " ORDER BY s.DepartureTime DESC";

$r1 = mysqli_query($conn, $q1);
while ($row = mysqli_fetch_assoc($r1)) {
    if (!isset($seen[$row['ShipmentID']])) {
        $seen[$row['ShipmentID']] = true;
        $shipments[] = $row;
    }
}

// Part 2: active shipments (driver loaded, no arrival yet)
if ($sf === '' || in_array($sf, array('in transit','delayed','scheduled'))) {
    $q2 = "SELECT DISTINCT s.ShipmentID, wh_o.WarehouseName AS origin_name,
        CASE s.DestinationType WHEN 'Warehouse' THEN wh_d.WarehouseName ELSE c.ClinicName END AS destination_name,
        s.DepartureTime, s.ArrivalTime, s.Status, s.VehicleID
    FROM Shipment s
    JOIN LotCustodyEvent ce ON ce.ToVehicleID=s.VehicleID AND ce.ToLocation='Vehicle' AND ce.EmployeeID='$eid'
    JOIN Warehouse wh_o ON wh_o.WarehouseID=s.OriginWarehouseID
    LEFT JOIN Warehouse wh_d ON wh_d.WarehouseID=s.DestinationWarehouseID
    LEFT JOIN Clinic c ON c.ClinicID=s.DestinationClinicID
    WHERE s.Status IN ('in transit','delayed','scheduled')";
    if ($sf !== '') $q2 .= " AND s.Status='$sf_safe'";
    $q2 .= " ORDER BY s.DepartureTime DESC";

    $r2 = mysqli_query($conn, $q2);
    while ($row = mysqli_fetch_assoc($r2)) {
        if (!isset($seen[$row['ShipmentID']])) {
            $seen[$row['ShipmentID']] = true;
            $shipments[] = $row;
        }
    }
}

usort($shipments, function($a, $b) {
    return strtotime($b['DepartureTime']) - strtotime($a['DepartureTime']);
});

$page_title = 'My Shipments';
include '../includes/header.php';
?>
<div class="page-body">
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
        <thead>
          <tr>
            <th>Shipment #</th>
            <th>From</th>
            <th>To</th>
            <th>Departed</th>
            <th>Arrived</th>
            <th>Status</th>
            <th>Temp</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($shipments)): ?>
          <tr><td colspan="7" class="empty-row">No shipments found.</td></tr>
          <?php else: foreach ($shipments as $s): ?>
          <tr class="clickable-row" onclick="window.location='/~g1154085/driver/shipment_detail.php?id=<?php echo urlencode(fmt_sid($s['ShipmentID'])); ?>'">
            <td class="mono"><?php echo htmlspecialchars(fmt_sid($s['ShipmentID'])); ?></td>
            <td><?php echo htmlspecialchars($s['origin_name']); ?></td>
            <td><?php echo htmlspecialchars($s['destination_name'] ? $s['destination_name'] : '—'); ?></td>
            <td><?php echo fmt_dt($s['DepartureTime']); ?></td>
            <td><?php echo fmt_dt($s['ArrivalTime']); ?></td>
            <td><?php echo status_badge($s['Status']); ?></td>
            <td>
              <canvas class="sparkline"
                      id="spark-<?php echo htmlspecialchars($s['ShipmentID']); ?>"
                      width="80" height="30"
                      data-sid="<?php echo htmlspecialchars($s['ShipmentID']); ?>">
              </canvas>
            </td>
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
    for (var i = 0; i < canvases.length; i++) ids.push(canvases[i].dataset.sid);
    fetch('/~g1154085/driver/ajax/get_sparklines.php?ids=' + ids.map(encodeURIComponent).join(','))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            for (var i = 0; i < canvases.length; i++) {
                var canvas = canvases[i];
                var sid = canvas.dataset.sid;
                var readings = data[sid];
                if (!readings || !readings.length) continue;
                var temps = readings.map(function(r) { return parseFloat(r.Temperature); });
                var colors = readings.map(function(r) {
                    return r.ReadingStatus === 'Missing' ? '#f59e0b' :
                           r.ReadingStatus === 'Suspect' ? '#ef4444' : '#4f46e5';
                });
                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: temps.map(function(_, i) { return i; }),
                        datasets: [{
                            data: temps,
                            borderColor: '#4f46e5',
                            borderWidth: 1.5,
                            pointBackgroundColor: colors,
                            pointRadius: 1.5,
                            tension: 0.2,
                            fill: false
                        }]
                    },
                    options: {
                        responsive: false,
                        animation: false,
                        plugins: { legend: { display: false }, tooltip: { enabled: false } },
                        scales: { x: { display: false }, y: { display: false } }
                    }
                });
            }
        }).catch(function() {});
});
</script>

<?php include '../includes/footer.php'; ?>