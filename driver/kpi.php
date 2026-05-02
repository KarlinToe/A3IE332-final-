<?php
include '../includes/auth_check.php';
require_role('driver');
include '../includes/constants.php';
include '../includes/db_compat.php';
$eid = $_SESSION['user']['EmployeeID'];

// D-KPI-1: On-time rate
// τ(s) = average transit minutes for shipments with the same origin+destination (all drivers),
// falling back to the global average across all delivered shipments when no route history exists.
// We identify the driver's delivered shipments via a subquery to avoid the CROSS JOIN row-
// multiplication bug that caused on_time > total (and thus a rate > 100%).
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE
            WHEN TIMESTAMPDIFF(MINUTE, s.DepartureTime, s.ArrivalTime) <=
                 COALESCE(
                     (SELECT AVG(TIMESTAMPDIFF(MINUTE, s2.DepartureTime, s2.ArrivalTime))
                      FROM Shipment s2
                      WHERE s2.Status = 'delivered'
                        AND s2.OriginWarehouseID    = s.OriginWarehouseID
                        AND s2.DestinationWarehouseID = s.DestinationWarehouseID
                        AND s2.DestinationClinicID    = s.DestinationClinicID
                        AND s2.DestinationType        = s.DestinationType),
                     (SELECT AVG(TIMESTAMPDIFF(MINUTE, s3.DepartureTime, s3.ArrivalTime))
                      FROM Shipment s3
                      WHERE s3.Status = 'delivered')
                 )
            THEN 1 ELSE 0
        END) AS on_time
    FROM Shipment s
    WHERE s.Status = 'delivered'
      AND s.ShipmentID IN (
          SELECT DISTINCT s4.ShipmentID
          FROM Shipment s4
          JOIN LotCustodyEvent ce ON ce.FromVehicleID = s4.VehicleID
               AND ce.FromLocation = 'Vehicle'
               AND ce.EmployeeID   = ?
      )
");
$stmt->execute(array($eid));
$r1 = $stmt->fetch();
$total_del    = (int)(isset($r1['total']) ? $r1['total'] : 0);
$on_time_rate = $total_del > 0 ? min(100.0, round(100.0 * $r1['on_time'] / $total_del, 1)) : 0;

// D-KPI-2: Excursion rate
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT stb.ShipmentID) AS breach_ships,
           COUNT(DISTINCT s.ShipmentID)   AS total_ships,
           ROUND(100.0 * COUNT(DISTINCT stb.ShipmentID) / NULLIF(COUNT(DISTINCT s.ShipmentID),0),1) AS rate
    FROM Shipment s
    LEFT JOIN ShipmentTempBreach stb ON stb.ShipmentID = s.ShipmentID
    WHERE s.ShipmentID IN (
        SELECT DISTINCT s2.ShipmentID
        FROM Shipment s2
        JOIN LotCustodyEvent ce ON ce.FromVehicleID = s2.VehicleID
             AND ce.FromLocation = 'Vehicle'
             AND ce.EmployeeID   = ?
    )
");
$stmt->execute(array($eid));
$r2 = $stmt->fetch();
$excursion_rate = (float)((isset($r2['rate']) ? $r2['rate'] : 0));

// D-KPI-3: Missing reading rate
$stmt = $pdo->prepare("
    SELECT SUM(CASE WHEN sr.ReadingStatus='Missing' THEN 1 ELSE 0 END) AS missing_cnt,
           COUNT(*) AS total_cnt,
           ROUND(100.0*SUM(CASE WHEN sr.ReadingStatus='Missing' THEN 1 ELSE 0 END)/NULLIF(COUNT(*),0),1) AS rate
    FROM SensorReading sr
    JOIN Sensor sn ON sn.SensorID = sr.SensorID
    JOIN Shipment s ON s.VehicleID = sn.VehicleID
    WHERE sr.ShipmentID IS NOT NULL
      AND s.ShipmentID IN (
          SELECT DISTINCT s2.ShipmentID
          FROM Shipment s2
          JOIN LotCustodyEvent ce ON ce.FromVehicleID = s2.VehicleID
               AND ce.FromLocation = 'Vehicle'
               AND ce.EmployeeID   = ?
      )
");
$stmt->execute(array($eid));
$r3 = $stmt->fetch();
$missing_rate = (float)((isset($r3['rate']) ? $r3['rate'] : 0));

// OPT-D-1: Breach frequency by shipment over date range
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime(app_today().' -90 days'));
$date_to   = (isset($_GET['date_to']) ? $_GET['date_to'] : app_today());
$threshold = max(1, (int)(isset($_GET['threshold']) ? $_GET['threshold'] : 5));

$stmt = $pdo->prepare("
    SELECT s.ShipmentID,
           DATE(s.DepartureTime) AS dep_date,
           COUNT(CASE WHEN sr.Temperature < b_range.req_min
                        OR sr.Temperature > b_range.req_max THEN 1 END) AS out_of_range
    FROM Shipment s
    JOIN SensorReading sr ON sr.ShipmentID = s.ShipmentID
    JOIN (
        SELECT sl2.ShipmentID,
               MIN(b2.MinStorageTemp) AS req_min,
               MAX(b2.MaxStorageTemp) AS req_max
        FROM ShipmentLot sl2
        JOIN Batch b2 ON b2.VendorID = sl2.VendorID AND b2.BatchNumber = sl2.BatchNumber
        GROUP BY sl2.ShipmentID
    ) b_range ON b_range.ShipmentID = s.ShipmentID
    WHERE s.Status = 'delivered'
      AND DATE(s.DepartureTime) BETWEEN ? AND ?
      AND s.ShipmentID IN (
          SELECT DISTINCT s2.ShipmentID
          FROM Shipment s2
          JOIN LotCustodyEvent ce ON ce.FromVehicleID = s2.VehicleID
               AND ce.FromLocation = 'Vehicle'
               AND ce.EmployeeID   = ?
      )
    GROUP BY s.ShipmentID, s.DepartureTime
    ORDER BY s.DepartureTime ASC
");
$stmt->execute(array($date_from, $date_to, $eid));
$opt_d1 = $stmt->fetchAll();

// OPT-D-2: Missing reading rate per trip over date range
// MissingRate(s) = |R_M(s)| / |R(s)| × 100  for s ∈ S(t1,t2)
// Shares the same date_from/date_to controls as OPT-D-1; has its own threshold param.
$missing_threshold = max(0, (int)(isset($_GET['missing_threshold']) ? $_GET['missing_threshold'] : 5));

$stmt = $pdo->prepare("
    SELECT s.ShipmentID,
           DATE(s.DepartureTime) AS dep_date,
           COUNT(sr.ReadingID) AS total_readings,
           SUM(CASE WHEN sr.ReadingStatus = 'Missing' THEN 1 ELSE 0 END) AS missing_cnt,
           ROUND(
               100.0 * SUM(CASE WHEN sr.ReadingStatus = 'Missing' THEN 1 ELSE 0 END)
               / NULLIF(COUNT(sr.ReadingID), 0),
           1) AS missing_rate
    FROM Shipment s
    JOIN SensorReading sr ON sr.ShipmentID = s.ShipmentID
    WHERE s.Status = 'delivered'
      AND DATE(s.DepartureTime) BETWEEN ? AND ?
      AND s.ShipmentID IN (
          SELECT DISTINCT s2.ShipmentID
          FROM Shipment s2
          JOIN LotCustodyEvent ce ON ce.FromVehicleID = s2.VehicleID
               AND ce.FromLocation = 'Vehicle'
               AND ce.EmployeeID   = ?
      )
    GROUP BY s.ShipmentID, s.DepartureTime
    ORDER BY s.DepartureTime DESC
");
$stmt->execute(array($date_from, $date_to, $eid));
$opt_d2 = $stmt->fetchAll();

$page_title = 'My KPIs';
include '../includes/header.php';
?>
<div class="page-body">

  <!-- Summary KPI cards -->
  <div class="kpi-row">
    <div class="kpi-card <?php echo $on_time_rate < 80 ? 'kpi-warn':''; ?>">
      <span class="kpi-icon">⏰</span>
      <div><div class="kpi-value"><?php echo $on_time_rate; ?>%</div><div class="kpi-label">On-time delivery rate (D-KPI-1)</div></div>
    </div>
    <div class="kpi-card <?php echo $excursion_rate > 10 ? 'kpi-danger':''; ?>">
      <span class="kpi-icon">🌡️</span>
      <div><div class="kpi-value"><?php echo $excursion_rate; ?>%</div><div class="kpi-label">Excursion rate per 100 deliveries (D-KPI-2)</div></div>
    </div>
    <div class="kpi-card <?php echo $missing_rate > 5 ? 'kpi-warn':''; ?>">
      <span class="kpi-icon">📡</span>
      <div><div class="kpi-value"><?php echo $missing_rate; ?>%</div><div class="kpi-label">Missing reading rate (D-KPI-3)</div></div>
    </div>
    <div class="kpi-card">
      <span class="kpi-icon">📦</span>
      <div><div class="kpi-value"><?php echo $total_del; ?></div><div class="kpi-label">Total deliveries</div></div>
    </div>
  </div>

  <!-- OPT-D-1 -->
  <div class="section-card">
    <div class="section-header">
      <h2 class="section-title">OPT-D-1 — Breach frequency by shipment</h2>
      <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <div class="form-group" style="margin:0">
          <label style="font-size:11px">From</label>
          <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" style="padding:5px 8px;width:140px">
        </div>
        <div class="form-group" style="margin:0">
          <label style="font-size:11px">To</label>
          <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" style="padding:5px 8px;width:140px">
        </div>
        <div class="form-group" style="margin:0">
          <label style="font-size:11px">Threshold (δ)</label>
          <input type="number" name="threshold" value="<?php echo $threshold; ?>" min="1" style="padding:5px 8px;width:80px">
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:16px">Apply</button>
      </form>
    </div>
    <div class="chart-wrap">
      <?php if (empty($opt_d1)): ?>
        <p class="text-muted text-center" style="padding:20px">No delivered shipments in this date range.</p>
      <?php else: ?>
        <canvas id="opt-d1-chart" height="80"></canvas>
      <?php endif; ?>
    </div>
  </div>

  <!-- OPT-D-2 -->
  <div class="section-card">
    <div class="section-header">
      <h2 class="section-title">OPT-D-2 &mdash; Missing reading rate per trip</h2>
      <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <!-- preserve OPT-D-1 controls so both charts stay active -->
        <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
        <input type="hidden" name="date_to"   value="<?php echo htmlspecialchars($date_to); ?>">
        <input type="hidden" name="threshold" value="<?php echo $threshold; ?>">
        <div class="form-group" style="margin:0">
          <label style="font-size:11px">Threshold (%)</label>
          <input type="number" name="missing_threshold" value="<?php echo $missing_threshold; ?>" min="0" max="100" style="padding:5px 8px;width:80px">
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:16px">Apply</button>
      </form>
    </div>
    <div class="chart-wrap">
      <?php if (empty($opt_d2)): ?>
        <p class="text-muted text-center" style="padding:20px">No delivered shipments in this date range.</p>
      <?php else: ?>
        <canvas id="opt-d2-chart"></canvas>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php if (!empty($opt_d1)): ?>
<script>
(function(){
    var data    = <?php echo json_encode(array_map(function($r){ return ['sid'=>$r['ShipmentID'],'oor'=>(int)$r['out_of_range']]; }, $opt_d1)); ?>;
    var thresh  = <?php echo $threshold; ?>;
    var labels  = data.map(function(d){ return 'SHP-'+d.sid.substring(3).replace(/^0+/,''); });
    var counts  = data.map(function(d){ return d.oor; });
    var colors  = counts.map(function(c){ return c > thresh ? '#ef4444':'#4f46e5'; });

    var ctx = document.getElementById('opt-d1-chart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: { labels: labels, datasets: [{
            label: 'Out-of-range readings',
            data: counts, backgroundColor: colors, borderRadius: 4
        }]},
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                annotation: { annotations: { line1: {
                    type: 'line', yMin: thresh, yMax: thresh,
                    borderColor: '#94a3b8', borderWidth: 1.5, borderDash: [6,3],
                    label: { display: true, content: 'δ = '+thresh, position: 'end', font:{size:11} }
                }}}
            },
            scales: {
                x: { ticks: { maxTicksLimit: 15, maxRotation: 45 } },
                y: { title: { display: true, text: 'Out-of-range readings' }, beginAtZero: true }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php if (!empty($opt_d2)): ?>
<script>
(function(){
    var data    = <?php echo json_encode(array_map(function($r){
        return ['sid'=>$r['ShipmentID'],'rate'=>(float)$r['missing_rate'],'total'=>(int)$r['total_readings'],'missing'=>(int)$r['missing_cnt']];
    }, $opt_d2)); ?>;
    var thresh  = <?php echo $missing_threshold; ?>;

    // Horizontal bar: y=ShipmentID (most recent at top = data already DESC), x=MissingRate
    var labels  = data.map(function(d){ return 'SHP-'+d.sid.substring(3).replace(/^0+/,''); });
    var rates   = data.map(function(d){ return d.rate; });
    var colors  = rates.map(function(r){ return r > thresh ? '#ef4444' : '#4f46e5'; });

    // Dynamic height: ~28px per bar, min 120px
    var chartH  = Math.max(120, data.length * 28);
    var canvas  = document.getElementById('opt-d2-chart');
    canvas.style.height = chartH + 'px';

    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Missing reading rate (%)',
                data: rates,
                backgroundColor: colors,
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var d = data[ctx.dataIndex];
                            return ' ' + ctx.parsed.x + '%  (' + d.missing + ' / ' + d.total + ' readings)';
                        }
                    }
                },
                annotation: { annotations: { thresh_line: {
                    type: 'line',
                    xMin: thresh, xMax: thresh,
                    borderColor: '#94a3b8', borderWidth: 1.5, borderDash: [6,3],
                    label: { display: true, content: thresh + '%', position: 'end', font:{size:11} }
                }}}
            },
            scales: {
                x: {
                    min: 0, max: 100,
                    title: { display: true, text: 'Missing reading rate (%)' }
                },
                y: { ticks: { font: { size: 11 } } }
            }
        }
    });
})();
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>