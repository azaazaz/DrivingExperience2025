<?php
// delete_log.php
require_once 'db_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    header("Location: dashboard.php?error=bad_id");
    exit;
}

$conn = connect_db();
$conn->begin_transaction();

try {
    // 1) delete junction rows first
    $stmt1 = $conn->prepare("DELETE FROM DrivingExp_Weather WHERE idDrivingExp = ?");
    if (!$stmt1) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt1->bind_param("i", $id);
    if (!$stmt1->execute()) {
        throw new Exception("Execute failed: " . $stmt1->error);
    }
    $stmt1->close();

    // 2) delete the driving experience
    $stmt2 = $conn->prepare("DELETE FROM DrivingExp WHERE idDrivingExp = ?");
    if (!$stmt2) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt2->bind_param("i", $id);
    if (!$stmt2->execute()) {
        throw new Exception("Execute failed: " . $stmt2->error);
    }
    $stmt2->close();

    $conn->commit();
    $conn->close();

    header("Location: dashboard.php?deleted=1");
    exit;

} catch (Exception $ex) {
    $conn->rollback();
    $conn->close();
    header("Location: dashboard.php?error=delete_failed");
    exit;
}
