<?php
require_once 'db_config.php';

$pdo = connect_db();

$weatherOptions = fetch_lookup_data($pdo, 'WeatherType', 'idWeatherType', 'weatherType');

$selectedFrom    = isset($_GET['from']) ? $_GET['from'] : '';
$selectedTo      = isset($_GET['to']) ? $_GET['to'] : '';
$selectedWeather = isset($_GET['weather']) ? (int)$_GET['weather'] : 0;

$whereParts = [];
$params = [];

if ($selectedFrom !== '') {
    $whereParts[] = "d.date >= :from";
    $params[':from'] = $selectedFrom;
}
if ($selectedTo !== '') {
    $whereParts[] = "d.date <= :to";
    $params[':to'] = $selectedTo;
}
if ($selectedWeather > 0) {
    $whereParts[] = "EXISTS (
        SELECT 1
        FROM DrivingExp_Weather dew_f
        WHERE dew_f.idDrivingExp = d.idDrivingExp
          AND dew_f.idWeatherType = :weather
    )";
    $params[':weather'] = $selectedWeather;
}

$where = $whereParts ? ("WHERE " . implode(" AND ", $whereParts)) : "";

function pdo_query(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$created = isset($_GET['created']) && $_GET['created'] === '1';

/* -----------------------------
   1) STATS
------------------------------ */
$statsSql = "
SELECT
  COUNT(*) AS total_logs,
  IFNULL(SUM(d.km), 0) AS total_km,
  IFNULL(SUM(
    TIMESTAMPDIFF(
      MINUTE,
      CONCAT(d.date, ' ', d.timeStart),
      CONCAT(d.date, ' ', d.timeEnd)
    )
  ), 0) AS total_minutes
FROM DrivingExp d
{$where}
";
$statsRow = pdo_query($pdo, $statsSql, $params)[0] ?? ['total_logs'=>0,'total_km'=>0,'total_minutes'=>0];

$totalLogs = (int)$statsRow['total_logs'];
$totalKm = (float)$statsRow['total_km'];
$totalMinutes = (int)$statsRow['total_minutes'];
$totalHours = intdiv($totalMinutes, 60);
$remainingMinutes = $totalMinutes % 60;

/* -----------------------------
   2) LATEST SESSION
------------------------------ */
$latestSql = "
SELECT d.date, d.timeStart, d.timeEnd, d.km,
       rt.roadType, p.parkingType, e.emergencyType,
       GROUP_CONCAT(DISTINCT wt.weatherType ORDER BY wt.weatherType SEPARATOR ', ') AS weatherTypes
FROM DrivingExp d
LEFT JOIN RoadType rt ON d.idRoadType = rt.idRoadType
LEFT JOIN Parking p ON d.idParking = p.idParking
LEFT JOIN Emergency e ON d.idEmergency = e.idEmergency
LEFT JOIN DrivingExp_Weather dew ON d.idDrivingExp = dew.idDrivingExp
LEFT JOIN WeatherType wt ON dew.idWeatherType = wt.idWeatherType
{$where}
GROUP BY d.idDrivingExp
ORDER BY d.date DESC, d.timeStart DESC
LIMIT 1
";
$latest = pdo_query($pdo, $latestSql, $params);
$latest = $latest[0] ?? null;

/* -----------------------------
   3) ROAD TYPE AGG (for bar)
------------------------------ */
$roadSql = "
SELECT rt.roadType, COUNT(*) AS cnt, IFNULL(SUM(d.km), 0) AS sumKm
FROM DrivingExp d
LEFT JOIN RoadType rt ON d.idRoadType = rt.idRoadType
{$where}
GROUP BY rt.roadType
ORDER BY cnt DESC
";
$roadData = pdo_query($pdo, $roadSql, $params);

/* -----------------------------
   4) WEATHER AGG (for pie)
   many-to-many: count distinct sessions per weather
------------------------------ */
$weatherAggSql = "
SELECT wt.weatherType, COUNT(DISTINCT d.idDrivingExp) AS cnt
FROM DrivingExp d
LEFT JOIN DrivingExp_Weather dew ON d.idDrivingExp = dew.idDrivingExp
LEFT JOIN WeatherType wt ON dew.idWeatherType = wt.idWeatherType
{$where}
GROUP BY wt.weatherType
ORDER BY cnt DESC
";
$weatherData = pdo_query($pdo, $weatherAggSql, $params);

/* -----------------------------
   5) EVOLUTION (date -> total km)
   respects filters (including weather EXISTS)
------------------------------ */
$evolutionSql = "
SELECT d.date AS date, IFNULL(SUM(d.km), 0) AS km
FROM DrivingExp d
{$where}
GROUP BY d.date
ORDER BY d.date ASC
";
$evolutionData = pdo_query($pdo, $evolutionSql, $params);

/* -----------------------------
   6) RECENT LOGS TABLE
------------------------------ */
$logsSql = "
SELECT d.idDrivingExp, d.date, d.timeStart, d.timeEnd, d.km,
       rt.roadType, p.parkingType, e.emergencyType,
       GROUP_CONCAT(DISTINCT wt.weatherType ORDER BY wt.weatherType SEPARATOR ', ') AS weatherTypes
FROM DrivingExp d
LEFT JOIN RoadType rt ON d.idRoadType = rt.idRoadType
LEFT JOIN Parking p ON d.idParking = p.idParking
LEFT JOIN Emergency e ON d.idEmergency = e.idEmergency
LEFT JOIN DrivingExp_Weather dew ON d.idDrivingExp = dew.idDrivingExp
LEFT JOIN WeatherType wt ON dew.idWeatherType = wt.idWeatherType
{$where}
GROUP BY d.idDrivingExp
ORDER BY d.date DESC, d.timeStart DESC
LIMIT 20
";
$logs = pdo_query($pdo, $logsSql, $params);

/* -----------------------------
   7) PREP CHART ARRAYS
------------------------------ */
$roadLabels = [];
$roadKmArr  = [];
foreach ($roadData as $r) {
    $roadLabels[] = $r['roadType'] ? $r['roadType'] : 'Unknown';
    $roadKmArr[]  = (float)$r['sumKm'];
}

$weatherLabels = [];
$weatherCounts = [];
foreach ($weatherData as $w) {
    $weatherLabels[] = $w['weatherType'] ? $w['weatherType'] : 'Unknown';
    $weatherCounts[] = (int)$w['cnt'];
}

$evoLabels = [];
$evoKm = [];
foreach ($evolutionData as $row) {
    $evoLabels[] = $row['date'];
    $evoKm[] = (float)$row['km'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Driving Experience Dashboard</title>
  <style>
    *{box-sizing:border-box}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f4f6f9;margin:0;color:#0f172a}
    header{background:#1f2937;color:#fff;padding:18px 20px}
    header .row{max-width:1100px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
    header h1{margin:0;font-size:20px}
    .btn{background:#3b82f6;color:#fff;text-decoration:none;padding:9px 14px;border-radius:10px;font-weight:700;border:0;cursor:pointer}
    .btn:hover{background:#2563eb}
    .container{max-width:1100px;margin:0 auto;padding:18px 14px 40px}
    .alert{background:#dcfce7;border:1px solid #16a34a;color:#166534;padding:10px 12px;border-radius:10px;margin-bottom:14px}
    .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:20px}
    .card{background:#fff;border-radius:14px;padding:16px;box-shadow:0 8px 20px rgba(15,23,42,.06);border:1px solid rgba(229,231,235,.9)}
    .card h2{margin:0 0 6px;font-size:12px;letter-spacing:.08em;color:#6b7280;text-transform:uppercase}
    .value{font-size:26px;font-weight:900;color:#111827}
    small{color:#6b7280}
    .gridCharts{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-bottom:18px}
    table{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 8px 20px rgba(15,23,42,.06);border:1px solid rgba(229,231,235,.9)}
    thead{background:#111827;color:#fff}
    th,td{padding:10px 12px;font-size:14px;text-align:left;vertical-align:top}
    tbody tr:nth-child(even){background:#f9fafb}
    .tag{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;background:#e5e7eb;color:#374151}
    form.filters{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 18px}
    form.filters label{font-size:13px;color:#111827;font-weight:800}
    form.filters input,form.filters select{padding:10px 12px;border-radius:10px;border:1px solid #d1d5db;background:#fff}
    canvas{width:100%!important;max-height:280px}
    @media (max-width:600px){
      header .row{justify-content:center}
      header h1{width:100%;text-align:center}
    }
  </style>
</head>
<body>
<header>
  <div class="row">
    <h1>Driving Experience Dashboard</h1>
    <a class="btn" href="log_form.php">New Driving Log</a>
  </div>
</header>

<div class="container">

  <?php if ($created): ?>
    <div class="alert">New driving experience was logged successfully.</div>
  <?php endif; ?>

  <div class="cards">
    <div class="card">
      <h2>Total Sessions</h2>
      <div class="value"><?php echo $totalLogs; ?></div>
      <small>Number of logs.</small>
    </div>
    <div class="card">
      <h2>Total Kilometers</h2>
      <div class="value"><?php echo number_format($totalKm, 1); ?> km</div>
      <small>All distance recorded.</small>
    </div>
    <div class="card">
      <h2>Total Time</h2>
      <div class="value"><?php echo $totalHours . 'h ' . $remainingMinutes . 'm'; ?></div>
      <small>From start/end times.</small>
    </div>
    <div class="card">
      <h2>Last Session</h2>
      <?php if ($latest): ?>
        <div class="value"><?php echo htmlspecialchars($latest['date']); ?></div>
        <small>
          <?php echo htmlspecialchars($latest['timeStart']); ?>–<?php echo htmlspecialchars($latest['timeEnd']); ?>,
          <?php echo number_format((float)$latest['km'], 1); ?> km
        </small>
      <?php else: ?>
        <div class="value">—</div>
        <small>No data yet.</small>
      <?php endif; ?>
    </div>
  </div>

  <form class="filters" method="GET">
    <div>
      <label for="from">From</label><br>
      <input type="date" id="from" name="from" value="<?php echo htmlspecialchars($selectedFrom); ?>">
    </div>
    <div>
      <label for="to">To</label><br>
      <input type="date" id="to" name="to" value="<?php echo htmlspecialchars($selectedTo); ?>">
    </div>
    <div>
      <label for="weatherFilter">Weather</label><br>
      <select id="weatherFilter" name="weather">
        <option value="">All</option>
        <?php foreach ($weatherOptions as $opt): ?>
          <option value="<?php echo (int)$opt['idWeatherType']; ?>" <?php if ($selectedWeather === (int)$opt['idWeatherType']) echo 'selected'; ?>>
            <?php echo htmlspecialchars($opt['weatherType']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="align-self:flex-end;">
      <button class="btn" type="submit">Apply filters</button>
    </div>
  </form>

  <!-- CHARTS (3) -->
  <div class="gridCharts">
    <div class="card">
      <h2>Distance by Road Type</h2>
      <small>Which roads you drive most (km).</small>
      <canvas id="roadChart"></canvas>
    </div>

    <div class="card">
      <h2>Sessions by Weather</h2>
      <small>Counts from many-to-many weather.</small>
      <canvas id="weatherChart"></canvas>
    </div>

    <div class="card">
      <h2>Evolution</h2>
      <small>Total km per date.</small>
      <canvas id="evolutionChart"></canvas>
    </div>
  </div>

  <h2 style="margin:10px 0;">Recent Driving Logs</h2>
  <table>
    <thead>
      <tr>
        <th>Date</th><th>Start</th><th>End</th><th>KM</th><th>Road</th><th>Weather</th><th>Parking</th><th>Emergency</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!empty($logs)): ?>
        <?php foreach ($logs as $row): ?>
          <tr>
            <td><?php echo htmlspecialchars($row['date']); ?></td>
            <td><?php echo htmlspecialchars($row['timeStart']); ?></td>
            <td><?php echo htmlspecialchars($row['timeEnd']); ?></td>
            <td><?php echo number_format((float)$row['km'], 1); ?></td>
            <td><span class="tag"><?php echo htmlspecialchars($row['roadType'] ?? '—'); ?></span></td>
            <td><span class="tag"><?php echo htmlspecialchars($row['weatherTypes'] ?? '—'); ?></span></td>
            <td><span class="tag"><?php echo htmlspecialchars($row['parkingType'] ?? '—'); ?></span></td>
            <td><span class="tag"><?php echo htmlspecialchars($row['emergencyType'] ?? '—'); ?></span></td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="8">No driving logs found yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
  const roadLabels = <?php echo json_encode($roadLabels); ?>;
  const roadKm = <?php echo json_encode($roadKmArr); ?>;

  const weatherLabels = <?php echo json_encode($weatherLabels); ?>;
  const weatherCounts = <?php echo json_encode($weatherCounts); ?>;

  const evoLabels = <?php echo json_encode($evoLabels); ?>;
  const evoKm = <?php echo json_encode($evoKm); ?>;

  if (roadLabels.length > 0) {
    new Chart(document.getElementById('roadChart').getContext('2d'), {
      type: 'bar',
      data: { labels: roadLabels, datasets: [{ label: 'Kilometers', data: roadKm }] },
      options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
      }
    });
  }

  if (weatherLabels.length > 0) {
    new Chart(document.getElementById('weatherChart').getContext('2d'), {
      type: 'pie',
      data: { labels: weatherLabels, datasets: [{ data: weatherCounts }] },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } }
      }
    });
  }

  if (evoLabels.length > 0) {
    new Chart(document.getElementById('evolutionChart').getContext('2d'), {
      type: 'line',
      data: {
        labels: evoLabels,
        datasets: [{
          label: 'Total km',
          data: evoKm,
          tension: 0.25,
          pointRadius: 3
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false }
        },
        scales: {
          x: {
            ticks: {
              autoSkip: true,
              maxRotation: 0
            }
          },
          y: {
            beginAtZero: true
          }
        }
      }
    });
  }
</script>
</body>
</html>
