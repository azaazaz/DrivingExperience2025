<?php
require_once 'db_config.php';

$pdo = connect_db();

$today = date('Y-m-d');
$now   = date('H:i');

$message = '';
$message_class = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $date      = trim($_POST['date'] ?? '');
    $timeStart = trim($_POST['timeStart'] ?? '');
    $timeEnd   = trim($_POST['timeEnd'] ?? '');
    $km        = isset($_POST['km']) ? (float)$_POST['km'] : 0.0;

    $idRoadType  = (int)($_POST['idRoadType'] ?? 0);
    $idParking   = (int)($_POST['idParking'] ?? 0);
    $idEmergency = (int)($_POST['idEmergency'] ?? 0);

    $weather_ids = $_POST['weather_ids'] ?? [];
    if (!is_array($weather_ids)) $weather_ids = [];
    $weather_ids = array_values(array_unique(array_filter(array_map('intval', $weather_ids), fn($x) => $x > 0)));

    $errors = [];
    if ($date === '') $errors[] = "Date is required.";
    if ($timeStart === '') $errors[] = "Start time is required.";
    if ($timeEnd === '') $errors[] = "End time is required.";
    if ($km <= 0) $errors[] = "Kilometers must be greater than 0.";
    if ($idRoadType <= 0) $errors[] = "Road type is required.";
    if ($idParking <= 0) $errors[] = "Parking type is required.";
    if ($idEmergency <= 0) $errors[] = "Emergency type is required.";
    if (count($weather_ids) === 0) $errors[] = "Please select at least one weather condition.";

    if ($errors) {
        $message = "❌ " . implode(" ", $errors);
        $message_class = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            $sql = "
                INSERT INTO DrivingExp
                    (date, timeStart, timeEnd, km, idRoadType, idParking, idEmergency)
                VALUES
                    (:date, :timeStart, :timeEnd, :km, :idRoadType, :idParking, :idEmergency)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':date' => $date,
                ':timeStart' => $timeStart,
                ':timeEnd' => $timeEnd,
                ':km' => $km,
                ':idRoadType' => $idRoadType,
                ':idParking' => $idParking,
                ':idEmergency' => $idEmergency,
            ]);

            $idDrivingExp = (int)$pdo->lastInsertId();

            $sqlJ = "INSERT INTO DrivingExp_Weather (idDrivingExp, idWeatherType) VALUES (:idDrivingExp, :idWeatherType)";
            $stmtJ = $pdo->prepare($sqlJ);

            foreach ($weather_ids as $wid) {
                $stmtJ->execute([
                    ':idDrivingExp' => $idDrivingExp,
                    ':idWeatherType' => $wid,
                ]);
            }

            $pdo->commit();

            header("Location: dashboard.php?created=1");
            exit;
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = "❌ Error saving log: " . htmlspecialchars($ex->getMessage());
            $message_class = 'error';
        }
    }
}

$roadTypes = fetch_lookup_data($pdo, 'RoadType', 'idRoadType', 'roadType');
$weatherTypes = fetch_lookup_data($pdo, 'WeatherType', 'idWeatherType', 'weatherType');
$parkingTypes = fetch_lookup_data($pdo, 'Parking', 'idParking', 'parkingType');
$emergencyTypes = fetch_lookup_data($pdo, 'Emergency', 'idEmergency', 'emergencyType');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>New Driving Experience Log</title>
  <style>
    :root{--bg:#f3f6fb;--card:#fff;--text:#0f172a;--muted:#64748b;--line:#e5e7eb;--primary:#2563eb;--primaryHover:#1d4ed8;}
    *{box-sizing:border-box;}
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);margin:0;color:var(--text);}

    header.site-header{background:#111827;color:#fff;padding:18px 20px;position:sticky;top:0;z-index:10;}
    .header-inner{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px;}
    header h1{margin:0;font-size:20px;font-weight:700;text-align:center;}
    nav.header-nav{justify-self:end;display:flex;gap:10px;align-items:center;}
    .btn{background:var(--primary);color:#fff;text-decoration:none;padding:9px 14px;border-radius:10px;font-size:14px;font-weight:600;display:inline-block;border:0;cursor:pointer;}
    .btn:hover{background:var(--primaryHover);}

    .container{padding:22px 16px 40px;max-width:1100px;margin:0 auto;}
    .card{background:var(--card);border-radius:16px;padding:22px;box-shadow:0 10px 30px rgba(15,23,42,.08);border:1px solid rgba(229,231,235,.9);}
    .card h2{margin:0 0 4px;font-size:20px;font-weight:800;}
    .subtitle{margin:0 0 18px;color:var(--muted);font-size:14px;line-height:1.35;}

    .message{padding:10px 12px;border-radius:10px;margin-bottom:14px;font-size:14px;}
    .message.error{background:#fee2e2;border:1px solid #ef4444;color:#7f1d1d;}

    form{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;align-items:start;}
    @media(max-width:820px){
      form{grid-template-columns:1fr;}
      .header-inner{grid-template-columns:1fr;}
      nav.header-nav{justify-self:center;}
    }

    .form-group{display:flex;flex-direction:column;}
    label{font-weight:700;margin-bottom:6px;color:#0f172a;font-size:14px;}
    .hint{font-size:12px;color:var(--muted);margin-top:-2px;margin-bottom:8px;}

    input[type="date"],input[type="time"],input[type="number"],select{
      padding:10px 12px;border-radius:12px;border:1px solid var(--line);font-size:14px;background:#fff;transition:border-color .15s,box-shadow .15s;
    }
    input:focus,select:focus{outline:none;border-color:rgba(37,99,235,.6);box-shadow:0 0 0 4px rgba(37,99,235,.15);}

    .full-width{grid-column:1/-1;}

    .weather-box{border:1px solid var(--line);border-radius:14px;padding:12px;background:#f8fafc;max-height:210px;overflow:auto;}
    .weather-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 12px;}
    @media(max-width:820px){.weather-grid{grid-template-columns:1fr;}.weather-box{max-height:260px;}}

    .weather-item{display:flex;align-items:center;gap:10px;padding:10px;border-radius:12px;background:#fff;border:1px solid rgba(229,231,235,.9);cursor:pointer;user-select:none;}
    .weather-item:hover{border-color:rgba(37,99,235,.35);box-shadow:0 4px 14px rgba(15,23,42,.06);}
    .weather-item input[type="checkbox"]{width:18px;height:18px;margin:0;accent-color:var(--primary);}
    .weather-item span{font-size:14px;font-weight:600;color:#111827;}

    .actions{grid-column:1/-1;margin-top:10px;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;}
    .btn-secondary{background:#e5e7eb;color:#111827;border-radius:12px;padding:10px 14px;text-decoration:none;font-size:14px;font-weight:700;border:0;cursor:pointer;}
    .btn-secondary:hover{background:#d1d5db;}
    button[type="submit"]{padding:10px 16px;background:var(--primary);color:#fff;border:none;border-radius:12px;cursor:pointer;font-size:14px;font-weight:800;}
    button[type="submit"]:hover{background:var(--primaryHover);}

    footer.site-footer{margin-top:18px;padding:18px 16px 26px;color:var(--muted);font-size:13px;text-align:center;}
  </style>
</head>
<body>

<header class="site-header">
  <div class="header-inner">
    <div aria-hidden="true"></div>
    <h1>New Driving Experience</h1>
    <nav class="header-nav" aria-label="Primary navigation">
      <a href="dashboard.php" class="btn">View Dashboard</a>
    </nav>
  </div>
</header>

<main>
  <section class="container" aria-label="Driving log form">
    <div class="card">
      <h2>Log a New Session</h2>
      <p class="subtitle">Track your practice by recording where, when, and how you drove.</p>

      <?php if ($message): ?>
        <div class="message <?php echo htmlspecialchars($message_class); ?>">
          <?php echo $message; ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">

        <div class="form-group">
          <label for="date">Date</label>
          <span class="hint">When did you drive?</span>
          <input type="date" id="date" name="date" required value="<?php echo htmlspecialchars($today); ?>">
        </div>

        <div class="form-group">
          <label for="km">Kilometers Driven</label>
          <span class="hint">Total distance for this session (e.g., 12.5)</span>
          <input type="number" id="km" name="km" step="0.1" min="0.1" inputmode="decimal" required>
        </div>

        <div class="form-group">
          <label for="timeStart">Start Time</label>
          <span class="hint">When you started driving</span>
          <input type="time" id="timeStart" name="timeStart" required value="<?php echo htmlspecialchars($now); ?>">
        </div>

        <div class="form-group">
          <label for="timeEnd">End Time</label>
          <span class="hint">When you finished driving</span>
          <input type="time" id="timeEnd" name="timeEnd" required>
        </div>

        <div class="form-group">
          <label for="idRoadType">Road Type</label>
          <span class="hint">What kind of road did you practice on?</span>
          <select id="idRoadType" name="idRoadType" required>
            <option value="">-- Select Road Type --</option>
            <?php foreach ($roadTypes as $rt): ?>
              <option value="<?php echo (int)$rt['idRoadType']; ?>">
                <?php echo htmlspecialchars($rt['roadType']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group full-width">
          <label>Weather Conditions</label>
          <span class="hint">Select one or more conditions.</span>

          <div class="weather-box">
            <div class="weather-grid">
              <?php $first = true; ?>
              <?php foreach ($weatherTypes as $wt): ?>
                <label class="weather-item" for="weather_<?php echo (int)$wt['idWeatherType']; ?>">
                  <input
                    type="checkbox"
                    id="weather_<?php echo (int)$wt['idWeatherType']; ?>"
                    name="weather_ids[]"
                    value="<?php echo (int)$wt['idWeatherType']; ?>"
                    <?php if ($first) { echo 'required'; $first = false; } ?>
                  >
                  <span><?php echo htmlspecialchars($wt['weatherType']); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <span class="hint">You can pick one condition, or multiple to show many-to-many.</span>
        </div>

        <div class="form-group">
          <label for="idParking">Parking Practiced</label>
          <span class="hint">Did you practice any parking type?</span>
          <select id="idParking" name="idParking" required>
            <option value="">-- Select Parking Type --</option>
            <?php foreach ($parkingTypes as $pt): ?>
              <option value="<?php echo (int)$pt['idParking']; ?>">
                <?php echo htmlspecialchars($pt['parkingType']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label for="idEmergency">Emergency Scenario Practiced</label>
          <span class="hint">Emergency / special maneuvers</span>
          <select id="idEmergency" name="idEmergency" required>
            <option value="">-- Select Emergency Type --</option>
            <?php foreach ($emergencyTypes as $et): ?>
              <option value="<?php echo (int)$et['idEmergency']; ?>">
                <?php echo htmlspecialchars($et['emergencyType']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="actions">
          <a href="dashboard.php" class="btn-secondary">Cancel</a>
          <button type="submit">Save Driving Log</button>
        </div>

      </form>
    </div>
  </section>
</main>

<footer class="site-footer">
  <small>Driving Experience Log — PDO + prepared statements + many-to-many weather.</small>
</footer>

</body>
</html>
