<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    exit(json_encode(['success' => false, 'error' => 'Not authenticated']));
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Function to get notifications for a user or gym owner
function getNotifications($user_id, $gym_id = null, $conn) {
    $query = "SELECT * FROM notifications WHERE (user_id = ? OR gym_id = ?) AND status = 'unread' ORDER BY created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute([$user_id, $gym_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    // Get notifications for the user
    $notifications = getNotifications($_SESSION['user_id'], null, $conn);

    echo json_encode(['success' => true, 'notifications' => $notifications]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
