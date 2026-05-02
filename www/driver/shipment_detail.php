<?php
include '../includes/auth_check.php';
require_role('driver');
include '../includes/constants.php';
include '../includes/db_compat.php';
global $conn;
$eid = $_SESSION['user']['EmployeeID'];

$sid = isset($_GET['id']) ? trim($_GET['id']) : '';
if ($sid === '' || !preg_match('/^SHP-\d+$/', $sid)) {
    header('Location: /~g1154085/driver/shipments.php');
    exit;
}


// Convert formatted ID (SHP-160) back to raw DB format (SHP0000000000160)
$sid_raw  = 'SHP' . str_pad(substr($sid, 4), 12, '0', STR_PAD_LEFT);

// Verify this driver has access to this shipment
$sid_safe = mysqli_real_escape_string($conn, $sid_raw);
$r = mysqli_query($conn, "
    SELECT s.*, wh_o.WarehouseName AS origin_name, wh_o.StreetAddress AS origin_addr,
           wh_o.City AS origin_city, wh_o.State AS origin_state,
           CASE s.DestinationType WHEN 'Warehouse' THEN wh_d.WarehouseName ELSE c.ClinicName END AS dest_name,
           CASE s.DestinationType WHEN 'Warehouse' THEN wh_d.StreetAddress ELSE c.StreetAddress END AS dest_addr,
           CASE s.DestinationType WHEN 'Warehouse' THEN wh_d.City ELSE c.City END AS dest_city,
           CASE s.DestinationType WHEN 'Warehouse' THEN wh_d.State ELSE c.State END AS dest_state
    FROM Shipment s
    JOIN Warehouse wh_o ON wh_o.WarehouseID = s.OriginWarehouseID
    LEFT JOIN Warehouse wh_d ON wh_d.WarehouseID = s.DestinationWarehouseID
    LEFT JOIN Clinic c ON c.ClinicID = s.DestinationClinicID
    WHERE s.ShipmentID = '$sid_safe'
");
$ship = mysqli_fetch_assoc($r);
if (!$ship) { header('Location: /~g1154085/driver/home.php'); exit; }

// Handle condition update
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_condition'])) {
    $ceid = isset($_POST['custody_event_id']) ? $_POST['custody_event_id'] : '';
    $cond = isset($_POST['condition']) ? $_POST['condition'] : '';
    if (in_array($cond, array('Seal Intact','Packaging Damaged')) && is_numeric($ceid)) {
        $cond_safe = mysqli_real_escape_string($conn, $cond);
        $eid_safe  = mysqli_real_escape_string($conn, $eid);
        $ceid_int  = (int)$ceid;
        $upd = mysqli_query($conn, "
            UPDATE LotCustodyEvent SET ConditionConfirmed = '$cond_safe'
            WHERE CustodyEventID = $ceid_int AND EmployeeID = '$eid_safe'
        ");
        $msg = ($upd && mysqli_affected_rows($conn) > 0) ? 'success' : 'error';
    }
}

// Chain of custody
$vid_safe  = mysqli_real_escape_string($conn, $ship['VehicleID']);
$dep_safe  = mysqli_real_escape_string($conn, $ship['DepartureTime']);
$arr_safe  = $ship['ArrivalTime'] ? "'" . mysqli_real_escape_string($conn, $ship['ArrivalTime']) . "'" : ''.app_now().'';
$r = mysqli_query($conn, "
    SELECT ce.CustodyEventID, ce.EventTime,
           CONCAT(e.FirstName,' ',e.LastName) AS emp_name, e.Role,
           ce.FromLocation, ce.ToLocation,
           ce.FromWarehouseID, ce.FromZoneCode, ce.FromVehicleID,
           ce.ToWarehouseID, ce.ToZoneCode, ce.ToVehicleID, ce.ToClinicID,
           ce.ConditionConfirmed, ce.EmployeeID
    FROM LotCustodyEvent ce
    JOIN Employee e ON e.EmployeeID = ce.EmployeeID
    WHERE (ce.FromVehicleID = '$vid_safe' OR ce.ToVehicleID = '$vid_safe')
      AND ce.EventTime BETWEEN '$dep_safe' AND COALESCE($arr_safe, '.app_now().')
    ORDER BY ce.EventTime ASC
");
$events = array();
while ($row = mysqli_fetch_assoc($r)) $events[] = $row;

// Lot manifest
$r = mysqli_query($conn, "
    SELECT v.VendorName, sl.BatchNumber, sl.LotSeq, sl.QuantityVolume,
           b.ExpiryDate, b.MinStorageTemp, b.MaxStorageTemp
    FROM ShipmentLot sl
    JOIN Batch b ON b.VendorID=sl.VendorID AND b.BatchNumber=sl.BatchNumber
    JOIN Vendor v ON v.VendorID=sl.VendorID
    WHERE sl.ShipmentID = '$sid_safe'
    ORDER BY v.VendorName, sl.BatchNumber
");
$lots = array();
while ($row = mysqli_fetch_assoc($r)) $lots[] = $row;

// Temp range from lots
$r = mysqli_query($conn, "
    SELECT MIN(b.MinStorageTemp) AS req_min, MAX(b.MaxStorageTemp) AS req_max
    FROM ShipmentLot sl
    JOIN Batch b ON b.VendorID=sl.VendorID AND b.BatchNumber=sl.BatchNumber
    WHERE sl.ShipmentID = '$sid_safe'
");
$range = mysqli_fetch_assoc($r);

// Sensor readings for chart + GPS
$r = mysqli_query($conn, "
    SELECT sr.ReadingTime, sr.Temperature, sr.Latitude, sr.Longitude, sr.ReadingStatus
    FROM SensorReading sr
    WHERE sr.ShipmentID = '$sid_safe'
    ORDER BY sr.ReadingTime ASC
");
$readings = array();
while ($row = mysqli_fetch_assoc($r)) $readings[] = $row;

// Breach windows
$r = mysqli_query($conn, "
    SELECT StartTime, EndTime, MaxDeviation, ResolutionStatus
    FROM ShipmentTempBreach WHERE ShipmentID = '$sid_safe' ORDER BY StartTime
");
$breaches = array();
while ($row = mysqli_fetch_assoc($r)) $breaches[] = $row;

// Status stepper
$steps = ['scheduled','in transit','delivered'];
$curr  = $ship['Status'] === 'delayed' ? 'in transit' : $ship['Status'];

$page_title = 'Shipment ' . fmt_sid($sid);
$breadcrumb_trail = array(
    array('label' => 'My Shipments', 'url' => '/~g1154085/driver/shipments.php'),
    array('label' => fmt_sid($sid)),
);
include '../includes/header.php';
?>
<div class="page-body">

  <?php if ($msg === 'success'): ?>
    <div class="alert alert-success">Condition updated successfully.</div>
  <?php elseif ($msg === 'error'): ?>
    <div class="alert alert-error">Could not update — you can only edit your own events.</div>
  <?php endif; ?>

  <!-- Header card -->
  <div class="section-card">
    <div class="section-header">
      <div>
        <h2 class="section-title"><?php echo htmlspecialchars(fmt_sid($sid)); ?></h2>
        <div class="mt-8"><?php echo status_badge($ship['Status']); ?></div>
      </div>
      <button onclick="window.print()" class="btn btn-secondary btn-sm">Print Report</button>
    </div>
    <!-- Status stepper -->
    <div class="status-stepper">
      <?php foreach ($steps as $i => $step):
          $done = array_search($curr, $steps) > $i;
          $active = $curr === $step;
          $cls = $done ? 'done' : ($active ? 'curr' : '');
      ?>
      <?php if ($i > 0): ?>
        <div class="step-line <?php echo $done?'done':''; ?>"></div>
      <?php endif; ?>
      <div class="step <?php echo $cls; ?>">
        <div class="step-dot"><?php echo $i+1; ?></div>
        <div class="step-label"><?php echo ucwords($step); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="section-body">
      <div class="detail-grid">
        <div>
          <div class="kpi-label mb-8" style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">Origin</div>
          <div style="font-weight:600"><?php echo htmlspecialchars($ship['origin_name']); ?></div>
          <div class="text-muted"><?php echo htmlspecialchars($ship['origin_addr'].', '.$ship['origin_city'].', '.$ship['origin_state']); ?></div>
          <div class="mt-8 text-muted" style="font-size:12px">Departed: <strong style="color:#1e293b"><?php echo fmt_dt($ship['DepartureTime']); ?></strong></div>
        </div>
        <div>
          <div class="kpi-label mb-8" style="font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">Destination</div>
          <div style="font-weight:600"><?php echo htmlspecialchars((isset($ship['dest_name']) ? $ship['dest_name'] : '—')); ?></div>
          <div class="text-muted"><?php echo htmlspecialchars(((isset($ship['dest_addr']) ? $ship['dest_addr'] : '')).', '.((isset($ship['dest_city']) ? $ship['dest_city'] : '')).', '.((isset($ship['dest_state']) ? $ship['dest_state'] : ''))); ?></div>
          <div class="mt-8 text-muted" style="font-size:12px">Arrived: <strong style="color:#1e293b"><?php echo fmt_dt($ship['ArrivalTime']); ?></strong></div>
        </div>
      </div>
    </div>
  </div>

  <div class="detail-grid">
    <!-- Chain of custody -->
    <div class="section-card">
      <div class="section-header"><h2 class="section-title">Chain of custody</h2></div>
      <div class="timeline">
        <?php if (empty($events)): ?>
          <p class="text-muted">No custody events recorded.</p>
        <?php else: foreach ($events as $ev): ?>
        <div class="timeline-event">
          <div class="timeline-time"><?php echo date('H:i:s', strtotime($ev['EventTime'])); ?><br><span style="font-size:10px"><?php echo date('M j', strtotime($ev['EventTime'])); ?></span></div>
          <div class="timeline-dot-col"><div class="timeline-dot"></div><div class="timeline-line"></div></div>
          <div class="timeline-body">
            <div class="timeline-person"><?php echo htmlspecialchars($ev['emp_name']); ?> <span class="text-muted" style="font-weight:400">(<?php echo htmlspecialchars($ev['Role']); ?>)</span></div>
            <div class="timeline-movement">
              <?php echo htmlspecialchars($ev['FromLocation']); ?>
              <?php if ($ev['FromWarehouseID']): ?>(<?php echo $ev['FromWarehouseID'].'-'.$ev['FromZoneCode']; ?>)<?php endif; ?>
              <?php if ($ev['FromVehicleID']): ?>(<?php echo $ev['FromVehicleID']; ?>)<?php endif; ?>
              &rarr;
              <?php echo htmlspecialchars($ev['ToLocation']); ?>
              <?php if ($ev['ToWarehouseID']): ?>(<?php echo $ev['ToWarehouseID'].'-'.$ev['ToZoneCode']; ?>)<?php endif; ?>
              <?php if ($ev['ToVehicleID']): ?>(<?php echo $ev['ToVehicleID']; ?>)<?php endif; ?>
              <?php if ($ev['ToClinicID']): ?>(Clinic <?php echo $ev['ToClinicID']; ?>)<?php endif; ?>
            </div>
            <div class="timeline-condition">
              <?php if ($ev['EmployeeID'] === $eid): ?>
              <form method="POST" style="display:inline-flex;gap:6px;align-items:center">
                <input type="hidden" name="custody_event_id" value="<?php echo (int)$ev['CustodyEventID']; ?>">
                <select name="condition" style="padding:3px 8px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:12px;font-family:inherit;">
                  <option <?php echo $ev['ConditionConfirmed']==='Seal Intact'?'selected':''; ?>>Seal Intact</option>
                  <option <?php echo $ev['ConditionConfirmed']==='Packaging Damaged'?'selected':''; ?>>Packaging Damaged</option>
                </select>
                <button name="update_condition" value="1" class="btn btn-primary btn-sm">Save</button>
              </form>
              <?php else: ?>
                <?php echo status_badge($ev['ConditionConfirmed']); ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Lot manifest -->
    <div class="section-card">
      <div class="section-header"><h2 class="section-title">Lot manifest</h2></div>
      <div class="table-scroll">
        <table class="data-table">
          <thead><tr><th>Vendor</th><th>Batch</th><th>Lot</th><th>Qty</th><th>Expires</th><th>Temp range</th></tr></thead>
          <tbody>
            <?php if (empty($lots)): ?>
            <tr><td colspan="6" class="empty-row">No lots.</td></tr>
            <?php else: foreach ($lots as $l): ?>
            <tr>
              <td><?php echo htmlspecialchars($l['VendorName']); ?></td>
              <td class="mono"><?php echo htmlspecialchars($l['BatchNumber']); ?></td>
              <td><?php echo htmlspecialchars($l['LotSeq']); ?></td>
              <td><?php echo htmlspecialchars($l['QuantityVolume']); ?></td>
              <td><?php echo fmt_date($l['ExpiryDate']); ?></td>
              <td><?php echo $l['MinStorageTemp']; ?>–<?php echo $l['MaxStorageTemp']; ?>°C</td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Temperature chart -->
  <div class="section-card">
    <div class="section-header"><h2 class="section-title">Temperature profile</h2></div>
    <div class="chart-wrap">
      <canvas id="temp-chart" height="100"></canvas>
    </div>
  </div>

</div>

<script>
(function(){
    var readings = <?php echo json_encode(array_map(function($r){ return ['t'=>$r['ReadingTime'],'temp'=>(float)$r['Temperature'],'s'=>$r['ReadingStatus']]; }, $readings)); ?>;
    var breaches = <?php echo json_encode(array_map(function($b){ return ['start'=>$b['StartTime'],'end'=>$b['EndTime']]; }, $breaches)); ?>;
    var reqMin = <?php echo (float)((isset($range['req_min']) ? $range['req_min'] : 2)); ?>;
    var reqMax = <?php echo (float)((isset($range['req_max']) ? $range['req_max'] : 8)); ?>;

    if (!readings.length) return;

    var labels = readings.map(function(r){ return r.t.substring(11,16); });
    var temps  = readings.map(function(r){ return r.temp; });
    var ptColors = readings.map(function(r){
        if (r.s==='Missing') return '#f59e0b';
        if (r.s==='Suspect') return '#ef4444';
        if (r.temp < reqMin || r.temp > reqMax) return '#ef4444';
        return '#4f46e5';
    });

    var ctx = document.getElementById('temp-chart').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Temperature (°C)', data: temps, borderColor: '#4f46e5', borderWidth: 2,
                  pointBackgroundColor: ptColors, pointRadius: 3, tension: 0.2, fill: false,
                  segment: {
                      borderColor: function(ctx){
                          var i = ctx.p0DataIndex;
                          return (temps[i]<reqMin||temps[i]>reqMax) ? '#ef4444' : '#4f46e5';
                      },
                      borderDash: function(ctx){
                          var i = ctx.p0DataIndex;
                          return (readings[i]&&readings[i].s!=='Valid'||readings[i+1]&&readings[i+1].s!=='Valid') ? [4,4] : [];
                      }
                  }
                },
                { label: 'Min '+reqMin+'°C', data: temps.map(function(){return reqMin;}),
                  borderColor:'#10b981', borderDash:[6,3], pointRadius:0, borderWidth:1.5, fill:false },
                { label: 'Max '+reqMax+'°C', data: temps.map(function(){return reqMax;}),
                  borderColor:'#f97316', borderDash:[6,3], pointRadius:0, borderWidth:1.5, fill:false }
            ]
        },
        options: {
            responsive: true,
            plugins: {
                tooltip: { callbacks: { afterLabel: function(ctx){ return 'Status: '+readings[ctx.dataIndex].s; } } },
                legend: { position: 'top' }
            },
            scales: {
                x: { ticks: { maxTicksLimit: 10 } },
                y: { title: { display: true, text: 'Temperature (°C)' } }
            }
        }
    });
})();
</script>

<?php include '../includes/footer.php'; ?>