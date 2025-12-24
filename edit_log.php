<?php
// edit_log.php
require_once 'db_config.php';

$conn = connect_db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
if ($id <= 0) {
    $conn->close();
    header("Location: dashboard.php?error=bad_id");
    exit;
}

$message = '';
$message_class = '';

function fetch_selected_weather_ids($conn, $idDrivingExp) {
    $ids = [];
    $stmt = $conn->prepare("SELECT idWeatherType FROM DrivingExp_Weather WHERE idDrivingExp = ?");
    $stmt->bind_param("i", $idDrivingExp);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['idWeatherType'];
    }
    $stmt->close();
    return $ids;
}

// Lookup data
$roadTypes = fetch_lookup_data($conn, 'RoadType', 'idRoadType', 'roadType');
$weatherTypes = fetch_lookup_data($conn, 'WeatherType', 'idWeatherType', 'weatherType');
$parkingTypes = fetch_lookup_data($conn, 'Parking', 'idParking', 'parkingType');
$emergencyTypes = fetch_lookup_data($conn, 'Emergency', 'idEmergency', 'emergencyType');

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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
    if (count($weather_ids) === 0) $errors[] = "Select at least one weather condition.";

    if ($errors) {
        $message = "❌ " . implode(' ', $errors);
        $message_class = 'error';
    } else {
        $conn->begin_transaction();
        try {
            // Update DrivingExp
            $sql = "
                UPDATE DrivingExp
                SET date = ?, timeStart = ?, timeEnd = ?, km = ?, idRoadType = ?, idParking = ?, idEmergency = ?
                WHERE idDrivingExp = ?
            ";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);

            $stmt->bind_param(
                "sssdiiii",
                $date, $timeStart, $timeEnd, $km,
                $idRoadType, $idParking, $idEmergency,
                $id
            );
            if (!$stmt->execute()) throw new Exception("Execute failed: " . $stmt->error);
            $stmt->close();

            // Replace junction rows
            $stmtDel = $conn->prepare("DELETE FROM DrivingExp_Weather WHERE idDrivingExp = ?");
            if (!$stmtDel) throw new Exception("Prepare failed: " . $conn->error);
            $stmtDel->bind_param("i", $id);
            if (!$stmtDel->execute()) throw new Exception("Execute failed: " . $stmtDel->error);
            $stmtDel->close();

            $stmtIns = $conn->prepare("INSERT INTO DrivingExp_Weather (idDrivingExp, idWeatherType) VALUES (?, ?)");
            if (!$stmtIns) throw new Exception("Prepare failed: " . $conn->error);

            foreach ($weather_ids as $wid) {
                $stmtIns->bind_param("ii", $id, $wid);
                if (!$stmtIns->execute()) throw new Exception("Execute failed: " . $stmtIns->error);
            }
            $stmtIns->close();

            $conn->commit();
            $conn->close();

            header("Location: dashboard.php?updated=1");
            exit;

        } catch (Exception $ex) {
            $conn->rollback();
            $message = "❌ Update failed: " . htmlspecialchars($ex->getMessage());
            $message_class = 'error';
        }
    }
}

// Load current driving experience values
$stmt = $conn->prepare("SELECT * FROM DrivingExp WHERE idDrivingExp = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$current = $res->fetch_assoc();
$stmt->close();

if (!$current) {
    $conn->close();
    header("Location: dashboard.php?error=not_found");
    exit;
}

$selectedWeatherIds = fetch_selected_weather_ids($conn, $id);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Driving Experience</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f9; margin: 0; padding: 0; }
        header { background: #1f2937; color: #fff; padding: 20px 30px; display:flex; justify-content:center; align-items:center; }
        header h1 { margin:0; font-size:22px; }

        .container { padding: 20px; max-width: 960px; margin: 0 auto; box-sizing:border-box; }
        .card { background:#fff; border-radius:12px; padding:20px; box-shadow:0 2px 6px rgba(0,0,0,0.06); }

        .message { padding:10px 12px; border-radius:8px; margin-bottom:14px; font-size:14px; }
        .message.error { background:#fee2e2; border:1px solid #b91c1c; color:#7f1d1d; }

        form { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px 16px; }
        .form-group { display:flex; flex-direction:column; }
        label { font-weight:700; margin-bottom:6px; color:#374151; font-size:14px; }
        .hint { font-size:12px; color:#6b7280; margin-bottom:6px; }

        input, select {
            padding:10px 12px;
            border-radius:10px;
            border:1px solid #d1d5db;
            font-size:14px;
            background:#f9fafb;
            box-sizing:border-box;
        }

        .weather-box {
            border:1px solid #d1d5db;
            border-radius:10px;
            padding:10px;
            background:#f9fafb;
            max-height: 220px;
            overflow:auto;
        }
        .weather-item { display:flex; align-items:center; gap:10px; padding:6px 4px; }
        .weather-item input { width:18px; height:18px; }
        .weather-item label { margin:0; font-weight:600; }

        .full { grid-column: 1 / -1; }

        .btn {
            background:#3b82f6; color:#fff; text-decoration:none;
            padding:10px 14px; border-radius:10px; font-weight:800; border:none; cursor:pointer;
        }
        .btn:hover { background:#2563eb; }
        .btn-secondary { background:#e5e7eb; color:#111827; }
        .btn-secondary:hover { background:#d1d5db; }

        .actions { grid-column:1 / -1; display:flex; justify-content:flex-end; gap:10px; margin-top:8px; flex-wrap:wrap; }

        @media (max-width: 700px) {
            form { grid-template-columns: 1fr; }
            header { padding: 16px; }
            .container { padding: 16px; }
        }
    </style>
</head>
<body>
<header>
    <h1>Edit Driving Experience</h1>
</header>

<div class="container">
    <div class="card">

        <?php if ($message): ?>
            <div class="message <?php echo htmlspecialchars($message_class); ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="edit_log.php">
            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">

            <div class="form-group">
                <label for="date">Date</label>
                <span class="hint">When did you drive?</span>
                <input type="date" id="date" name="date" required value="<?php echo htmlspecialchars($current['date']); ?>">
            </div>

            <div class="form-group">
                <label for="km">Kilometers</label>
                <span class="hint">Total distance (e.g., 12.5)</span>
                <input type="number" id="km" name="km" step="0.1" min="0.1" required value="<?php echo htmlspecialchars($current['km']); ?>">
            </div>

            <div class="form-group">
                <label for="timeStart">Start Time</label>
                <input type="time" id="timeStart" name="timeStart" required value="<?php echo htmlspecialchars($current['timeStart']); ?>">
            </div>

            <div class="form-group">
                <label for="timeEnd">End Time</label>
                <input type="time" id="timeEnd" name="timeEnd" required value="<?php echo htmlspecialchars($current['timeEnd']); ?>">
            </div>

            <div class="form-group">
                <label for="idRoadType">Road Type</label>
                <select id="idRoadType" name="idRoadType" required>
                    <option value="">-- Select Road Type --</option>
                    <?php foreach ($roadTypes as $rt): ?>
                        <option value="<?php echo (int)$rt['idRoadType']; ?>"
                            <?php if ((int)$current['idRoadType'] === (int)$rt['idRoadType']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($rt['roadType']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group full">
                <label>Weather Conditions</label>
                <span class="hint">Select one or more (many-to-many).</span>
                <div class="weather-box">
                    <?php foreach ($weatherTypes as $wt): 
                        $wid = (int)$wt['idWeatherType'];
                        $checked = in_array($wid, $selectedWeatherIds, true);
                    ?>
                        <div class="weather-item">
                            <input type="checkbox" id="w_<?php echo $wid; ?>" name="weather_ids[]" value="<?php echo $wid; ?>"
                                <?php echo $checked ? 'checked' : ''; ?>>
                            <label for="w_<?php echo $wid; ?>"><?php echo htmlspecialchars($wt['weatherType']); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label for="idParking">Parking</label>
                <select id="idParking" name="idParking" required>
                    <option value="">-- Select Parking Type --</option>
                    <?php foreach ($parkingTypes as $pt): ?>
                        <option value="<?php echo (int)$pt['idParking']; ?>"
                            <?php if ((int)$current['idParking'] === (int)$pt['idParking']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($pt['parkingType']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="idEmergency">Emergency</label>
                <select id="idEmergency" name="idEmergency" required>
                    <option value="">-- Select Emergency Type --</option>
                    <?php foreach ($emergencyTypes as $et): ?>
                        <option value="<?php echo (int)$et['idEmergency']; ?>"
                            <?php if ((int)$current['idEmergency'] === (int)$et['idEmergency']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($et['emergencyType']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="actions">
                <a class="btn btn-secondary" href="dashboard.php">Cancel</a>
                <button class="btn" type="submit">Save Changes</button>
            </div>
        </form>

    </div>
</div>
</body>
</html>
