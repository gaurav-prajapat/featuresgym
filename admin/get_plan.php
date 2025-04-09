<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    exit();
}

if (isset($_GET['id'])) {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT * FROM membership_plans WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode($plan);
}
