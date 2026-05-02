<?php
include '../includes/auth_check.php';
require_role('warehouse staff');
include '../includes/constants.php';
include '../includes/db_compat.php';
include '../includes/get_staff_warehouse.php';

// OPT-W-1: Zone breach frequency ranking — filtered to this staff member's warehouse
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime(app_today().' -180 days'));
$date_to   = isset($_GET['date_to'])   ? $_GET['date_to']   : app_today();
$threshold = max(1, (int)(isset($_GET['threshold']) ? $_GET['threshold'] : 3));

if ($staff_wid) {
    $stmt = $pdo->prepare("
        SELECT ztb.WarehouseID, ztb.ZoneCode, sz.Classification,
               COUNT(*) AS breach_count
        FROM ZoneTempBreach ztb
        JOIN StorageZone sz ON sz.WarehouseID=ztb.WarehouseID AND sz.ZoneCode=ztb.ZoneCode
        WHERE DATE(ztb.StartTime) BETWEEN ? AND ?
          AND ztb.WarehouseID = ?
        GROUP BY ztb.WarehouseID, ztb.ZoneCode, sz.Classification
        ORDER BY breach_count DESC
        LIMIT 20
    ");
    $stmt->execute(array($date_from, $date_to, $staff_wid));
} else {
    $stmt = $pdo->prepare("
        SELECT ztb.WarehouseID, ztb.ZoneCode, sz.Classification,
               COUNT(*) AS breach_count
        FROM ZoneTempBreach ztb
        JOIN StorageZone sz ON sz.WarehouseID=ztb.WarehouseID AND sz.ZoneCode=ztb.ZoneCode
        WHERE DATE(ztb.StartTime) BETWEEN ? AND ?
        GROUP BY ztb.WarehouseID, ztb.ZoneCode, sz.Classification
        ORDER BY breach_count DESC
        LIMIT 20
    ");
    $stmt->execute(array($date_from, $date_to));
}
$opt_w1 = $stmt->fetchAll();

$page_title = 'Warehouse KPIs';
include '../includes/header.php';
?>
<div class="page-body">

  <!-- KPI summary cards — values loaded via AJAX -->
  <div class="kpi-row-5">
    <div class="kpi-card" id="card-util-ref">
      <span class="kpi-icon">❄️</span>
      <div><div class="kpi-value" id="val-util-ref">—</div><div class="kpi-label">Refrigerated utilization (W-KPI-1)</div></div>
    </div>
    <div class="kpi-card" id="card-expiring">
      <span class="kpi-icon">⏳</span>
      <div><div class="kpi-value" id="val-expiring">—</div><div class="kpi-label">Lots expiring ≤30 days (W-KPI-2)</div></div>
    </div>
    <div class="kpi-card" id="card-excursions">
      <span class="kpi-icon">🌡️</span>
      <div><div class="kpi-value" id="val-excursions">—</div><div class="kpi-label">Open excursions (W-KPI-3)</div></div>
    </div>
    <div class="kpi-card" id="card-resolution">
      <span class="kpi-icon">⏱️</span>
      <div><div class="kpi-value" id="val-resolution">—</div><div class="kpi-label">Avg resolution time 90d (W-KPI-4)</div></div>
    </div>
    <div class="kpi-card" id="card-freezer">
      <span class="kpi-icon">🧊</span>
      <div><div class="kpi-value" id="val-util-frez">—</div><div class="kpi-label">Freezer utilization (W-KPI-1)</div></div>
    </div>
  </div>

  <!-- Charts row -->
  <div class="detail-grid">
    <div class="section-card">
      <div class="section-header"><h2 class="section-title">Zone utilization by classification (W-KPI-1)</h2></div>
      <div class="chart-wrap"><canvas id="util-chart" height="140"></canvas></div>
    </div>
    <div class="section-card">
      <div class="section-header"><h2 class="section-title">Weekly shipment volume — inbound &amp; outbound (W-KPI-5)</h2></div>
      <div class="chart-wrap"><canvas id="weekly-chart" height="140"></canvas></div>
    </div>
  </div>

  <!-- W-KPI-3 per-zone excursion table -->
  <div class="section-card">
    <div class="section-header"><h2 class="section-title">Open temperature excursions by zone (W-KPI-3)</h2></div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Warehouse</th><th>Zone</th><th>Open Excursions</th></tr></thead>
        <tbody id="excursion-zone-body">
          <tr><td colspan="3" class="empty-row">Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- OPT-W-1: Zone breach frequency ranking -->
  <div class="section-card">
    <div class="section-header">
      <h2 class="section-title">OPT-W-1 — Zone breach frequency ranking</h2>
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
          <label style="font-size:11px">Threshold (ϕ)</label>
          <input type="number" name="threshold" value="<?php echo $threshold; ?>" min="1" style="padding:5px 8px;width:80px">
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:16px">Apply</button>
      </form>
    </div>
    <div class="chart-wrap">
      <?php if (empty($opt_w1)): ?>
        <p class="text-muted text-center" style="padding:20px">No breach data in this date range.</p>
      <?php else: ?>
        <canvas id="opt-w1-chart" height="<?php echo max(80, count($opt_w1)*28); ?>"></canvas>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
(function(){
    var BASE = '/~g1154085/warehouse/ajax/';

    fetch(BASE + 'kpi_zone_utilization.php')
        .then(function(r){ return r.json(); })
        .then(function(data){
            var ref  = data['refrigerated'] !== undefined ? data['refrigerated'] : 0;
            var frez = data['freezer']      !== undefined ? data['freezer']      : 0;
            document.getElementById('val-util-ref').textContent  = ref + '%';
            document.getElementById('val-util-frez').textContent = frez + '%';
            if (ref  > 90) document.getElementById('card-util-ref').classList.add('kpi-danger');
            if (frez > 90) document.getElementById('card-freezer').classList.add('kpi-danger');
            var labels = Object.keys(data).filter(function(k){ return k !== '_warehouse'; });
            var values = labels.map(function(k){ return data[k]; });
            var colors = labels.map(function(c){
                return c==='refrigerated'?'#0ea5e9':c==='freezer'?'#8b5cf6':'#94a3b8';
            });
            new Chart(document.getElementById('util-chart'), {
                type: 'bar',
                data: { labels: labels, datasets:[{ label:'Utilization %', data:values, backgroundColor:colors, borderRadius:6 }]},
                options:{ responsive:true, indexAxis:'y',
                    plugins:{ legend:{display:false} },
                    scales:{ x:{ max:100, title:{display:true,text:'Utilization %'} } }}
            });
        });

    fetch(BASE + 'kpi_expiring_lots.php?d=30')
        .then(function(r){ return r.json(); })
        .then(function(data){
            document.getElementById('val-expiring').textContent = data.count;
            if (data.count > 0) document.getElementById('card-expiring').classList.add('kpi-warn');
        });

    fetch(BASE + 'kpi_open_excursions.php')
        .then(function(r){ return r.json(); })
        .then(function(data){
            document.getElementById('val-excursions').textContent = data.total;
            if (data.total > 0) document.getElementById('card-excursions').classList.add('kpi-danger');
            var tbody = document.getElementById('excursion-zone-body');
            if (!data.zones || !data.zones.length) {
                tbody.innerHTML = '<tr><td colspan="3" class="empty-row">No open excursions.</td></tr>';
            } else {
                tbody.innerHTML = data.zones.map(function(z){
                    return '<tr class="alert-row"><td>' + z.WarehouseID + '</td><td>' + z.ZoneCode
                         + '</td><td><strong>' + z.count + '</strong></td></tr>';
                }).join('');
            }
        });

    fetch(BASE + 'kpi_resolution_time.php')
        .then(function(r){ return r.json(); })
        .then(function(data){
            document.getElementById('val-resolution').textContent = data.avg_hours !== null ? data.avg_hours + 'h' : '—';
        });

    fetch(BASE + 'kpi_weekly_volume.php')
        .then(function(r){ return r.json(); })
        .then(function(data){
            var weekMap = {};
            (data.outbound || []).forEach(function(r){ weekMap[r.week] = weekMap[r.week] || {out:0,inn:0}; weekMap[r.week].out = r.volume; });
            (data.inbound  || []).forEach(function(r){ weekMap[r.week] = weekMap[r.week] || {out:0,inn:0}; weekMap[r.week].inn = r.volume; });
            var weeks  = Object.keys(weekMap).sort();
            var outVol = weeks.map(function(w){ return weekMap[w].out; });
            var inVol  = weeks.map(function(w){ return weekMap[w].inn; });
            new Chart(document.getElementById('weekly-chart'), {
                type:'bar',
                data:{ labels:weeks, datasets:[
                    { label:'Outbound', data:outVol, backgroundColor:'#4f46e5', borderRadius:4 },
                    { label:'Inbound',  data:inVol,  backgroundColor:'#10b981', borderRadius:4 }
                ]},
                options:{ responsive:true,
                    plugins:{ legend:{ position:'top' } },
                    scales:{ x:{ticks:{maxRotation:45}}, y:{title:{display:true,text:'Volume'}} }}
            });
        });

    <?php if (!empty($opt_w1)): ?>
    var w1data = <?php echo json_encode(array_map(function($r){ return [
        'label' => $r['WarehouseID'].'/'.$r['ZoneCode'].'/'.$r['Classification'],
        'count' => (int)$r['breach_count'],
        'cls'   => $r['Classification']
    ]; }, $opt_w1)); ?>;
    var w1thresh = <?php echo $threshold; ?>;
    var w1labels = w1data.map(function(d){ return d.label; }).reverse();
    var w1counts = w1data.map(function(d){ return d.count; }).reverse();
    var w1colors = w1data.map(function(d){
        return d.cls==='refrigerated'?'#0ea5e9':d.cls==='freezer'?'#8b5cf6':'#94a3b8';
    }).reverse();
    new Chart(document.getElementById('opt-w1-chart'), {
        type:'bar',
        data:{ labels:w1labels, datasets:[{
            label:'Breach count', data:w1counts, backgroundColor:w1colors, borderRadius:4
        }]},
        options:{ responsive:true, indexAxis:'y',
            plugins:{ legend:{display:false} },
            scales:{ x:{ min:0, max: Math.max.apply(null, w1counts) + 1,
                         title:{display:true,text:'Breach count'},
                         ticks:{stepSize:1, precision:0} } }}
    });
    <?php endif; ?>
})();
</script>

<?php include '../includes/footer.php'; ?>
