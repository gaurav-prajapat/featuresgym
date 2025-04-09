<?php
session_start();
require '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$owner_id = $_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Get gym_id for the owner
$stmt = $conn->prepare("SELECT gym_id FROM gyms WHERE owner_id = :owner_id");
$stmt->bindParam(':owner_id', $owner_id);
$stmt->execute();
$gym = $stmt->fetch(PDO::FETCH_ASSOC);
$gym_id = $gym['gym_id'];

if (isset($_GET['id'])) {
    $equipmentId = $_GET['id'];

    // First get the image filename if exists
    $stmt = $conn->prepare("SELECT image FROM gym_equipment WHERE equipment_id = :equipment_id AND gym_id = :gym_id");
    $stmt->execute([
        ':equipment_id' => $equipmentId,
        ':gym_id' => $gym_id
    ]);
    $equipment = $stmt->fetch(PDO::FETCH_ASSOC);

    // Delete the equipment
    $stmt = $conn->prepare("DELETE FROM gym_equipment WHERE gym_id = :gym_id AND equipment_id = :equipment_id");
    $stmt->bindParam(':gym_id', $gym_id);
    $stmt->bindParam(':equipment_id', $equipmentId);

    if ($stmt->execute()) {
        // Delete the image file if it exists
        if ($equipment && $equipment['image']) {
            $image_path = "../uploads/equipments/" . $equipment['image'];
            if (file_exists($image_path)) {
                unlink($image_path);
            }
        }
        header("Location: manage_equipment.php?success=deleted");
        exit;
    }
}

header("Location: manage_equipment.php?error=failed");
exit;
