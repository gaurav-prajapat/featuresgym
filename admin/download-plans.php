<?php
require_once '../config/database.php';
session_start();
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}
$db = new GymDatabase();
$conn = $db->getConnection();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="gym_membership_plans.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Plan ID', 'Gym ID', 'Tier', 'Duration', 'Price', 'Admin Cut (%)', 'Gym Owner Cut (%)', 'Admin Revenue', 'Gym Revenue', 'Inclusions']);

$planStmt = $conn->prepare("
    SELECT 
        gmp.plan_id, 
        gmp.gym_id, 
        gmp.tier, 
        gmp.duration, 
        gmp.price, 
        gmp.inclusions, 
        coc.admin_cut_percentage, 
        coc.gym_owner_cut_percentage
    FROM gym_membership_plans gmp
    JOIN cut_off_chart coc ON gmp.tier = coc.tier AND gmp.duration = coc.duration
");
$planStmt->execute();

while ($plan = $planStmt->fetch(PDO::FETCH_ASSOC)) {
    $adminRevenue = ($plan['price'] * $plan['admin_cut_percentage']) / 100;
    $gymRevenue = ($plan['price'] * $plan['gym_owner_cut_percentage']) / 100;

    fputcsv($output, [
        $plan['plan_id'],
        $plan['gym_id'],
        $plan['tier'],
        $plan['duration'],
        $plan['price'],
        $plan['admin_cut_percentage'],
        $plan['gym_owner_cut_percentage'],
        $adminRevenue,
        $gymRevenue,
        $plan['inclusions']
    ]);
}

fclose($output);
?>
