
<?php
include '../includes/auth_check.php';
require_role('driver');
include '../includes/constants.php';
include '../includes/db_compat.php';
global $conn;

$eid = mysqli_real_escape_string($conn, $_SESSION['user']['EmployeeID']);

// Find most recently used vehicle from unload events (Vehicle->Zone or Vehicle->Clinic)
$r = mysqli_query($conn, "
    SELECT ce.FromVehicleID AS VehicleID, ce.EventTime
    FROM LotCustodyEvent ce
    WHERE ce.EmployeeID = '$eid'
    AND ce.FromLocation = 'Vehicle'
    AND ce.FromVehicleID IS NOT NULL
    ORDER BY ce.EventTime DESC
    LIMIT 1
");
$vid_row = mysqli_fetch_assoc($r);
$vid = $vid_row ? $vid_row['VehicleID'] : null;

// Get full vehicle details
$vehicle = null;
if ($vid) {
    $r = mysqli_query($conn, "SELECT * FROM Vehicle WHERE VehicleID='$vid'");
    $vehicle = mysqli_fetch_assoc($r);
}

// Sensors
$sensors = array();
$faulty  = false;
if ($vid) {
    $r = mysqli_query($conn, "SELECT * FROM Sensor WHERE VehicleID='$vid'");
    while ($row = mysqli_fetch_assoc($r)) {
        $sensors[] = $row;
        if ($row['Status'] === 'faulty') $faulty = true;
    }
}

// Last 20 readings
$readings = array();
if ($vid) {
    $r = mysqli_query($conn, "
        SELECT sr.ReadingTime, sr.Temperature, sr.Latitude, sr.Longitude, sr.ReadingStatus
        FROM SensorReading sr
        JOIN Sensor s ON s.SensorID = sr.SensorID
        WHERE s.VehicleID = '$vid'
        ORDER BY sr.ReadingTime DESC
        LIMIT 20
    ");
    while ($row = mysqli_fetch_assoc($r)) {
        $readings[] = $row;
    }
}

$page_title = 'My Vehicle';
include '../includes/header.php';
?>
<div class="page-body">

  <?php if ($faulty): ?>
    <div class="sensor-warning">⚠️ Warning: one or more sensors on this vehicle are flagged as FAULTY. Check sensor panel below.</div>
  <?php endif; ?>

  <?php if (!$vehicle): ?>
    <div class="alert alert-info">No vehicle currently assigned. Contact dispatch.</div>
  <?php else: ?>

  <!-- Vehicle info -->
  <div class="section-card">
    <div class="section-header"><h2 class="section-title">Vehicle — <?php echo htmlspecialchars($vehicle['VehicleID']); ?></h2>
      <?php echo status_badge($vehicle['Status']); ?>
    </div>
    <div class="section-body">
      <div class="detail-grid">
        <div>
          <div class="detail-row"><span class="detail-key">Vehicle ID</span><span class="detail-val"><?php echo htmlspecialchars($vehicle['VehicleID']); ?></span></div>
          <div class="detail-row"><span class="detail-key">License plate</span><span class="detail-val"><?php echo htmlspecialchars($vehicle['LicensePlate']); ?></span></div>
          <div class="detail-row"><span class="detail-key">Status</span><span class="detail-val"><?php echo status_badge($vehicle['Status']); ?></span></div>
        </div>
        <div>
          <div class="detail-row"><span class="detail-key">Refrigeration volume</span><span class="detail-val"><?php echo htmlspecialchars($vehicle['RefrigerationVolume']); ?> units</span></div>
          <div class="detail-row"><span class="detail-key">Min temp rating</span><span class="detail-val"><?php echo $vehicle['MinTempRating']; ?>°C</span></div>
          <div class="detail-row"><span class="detail-key">Max temp rating</span><span class="detail-val"><?php echo $vehicle['MaxTempRating']; ?>°C</span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Sensor panel -->
  <div class="section-card">
    <div class="section-header"><h2 class="section-title">Sensor panel</h2></div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Sensor ID</th><th>Type</th><th>Last calibration</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (empty($sensors)): ?>
          <tr><td colspan="4" class="empty-row">No sensors found.</td></tr>
          <?php else: foreach ($sensors as $s): ?>
          <tr <?php echo $s['Status']==='faulty'?'style="background:#fff1f2"':''; ?>>
            <td class="mono"><?php echo htmlspecialchars($s['SensorID']); ?></td>
            <td><?php echo htmlspecialchars($s['SensorType']); ?></td>
            <td><?php echo fmt_date($s['CalibrationDate']); ?></td>
            <td><?php echo status_badge($s['Status']); ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Last 20 readings -->
  <div class="section-card">
    <div class="section-header"><h2 class="section-title">Last 20 sensor readings</h2></div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Time</th><th>Temperature (°C)</th><th>Latitude</th><th>Longitude</th><th>Status</th></tr></thead>
        <tbody>
          <?php if (empty($readings)): ?>
          <tr><td colspan="5" class="empty-row">No readings found.</td></tr>
          <?php else: foreach ($readings as $r): ?>
          <tr <?php echo $r['ReadingStatus']!=='Valid'?'style="background:#fffbeb"':''; ?>>
            <td><?php echo fmt_dt($r['ReadingTime']); ?></td>
            <td><?php echo htmlspecialchars((isset($r['Temperature']) ? $r['Temperature'] : '—')); ?></td>
            <td><?php echo htmlspecialchars((isset($r['Latitude']) ? $r['Latitude'] : '—')); ?></td>
            <td><?php echo htmlspecialchars((isset($r['Longitude']) ? $r['Longitude'] : '—')); ?></td>
            <td><?php echo status_badge($r['ReadingStatus']); ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
