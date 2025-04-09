<?php
require_once '../config/database.php';

$db = new GymDatabase();
$conn = $db->getConnection();

$search = $_GET['query'] ?? '';
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 9; // Match the limit in gyms.php
$offset = ($page - 1) * $limit;

$sql = "
    SELECT DISTINCT g.*, 
           (SELECT AVG(rating) FROM reviews r WHERE r.gym_id = g.gym_id) as avg_rating,
           (SELECT COUNT(*) FROM reviews r WHERE r.gym_id = g.gym_id) as review_count,
           gmp.price as daily_price,
           g.is_open
    FROM gyms g 
    JOIN gym_membership_plans gmp ON g.gym_id = gmp.gym_id
    WHERE g.status = 'active'
    AND gmp.duration = 'Daily'";


if ($search) {
    $sql .= " AND (g.name LIKE ? OR g.city LIKE ?)";
    $params = ["%$search%", "%$search%"];
} else {
    $params = [];
}

$sql .= " GROUP BY g.gym_id ORDER BY daily_price ASC";

// Only add LIMIT if not searching or if specifically requested
if (empty($search) || isset($_GET['paginate'])) {
    $sql .= " LIMIT $limit OFFSET $offset";
}

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($gyms as $gym) {
    include '../gym_card.php';
}
