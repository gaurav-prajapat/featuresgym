<?php
require_once 'config/database.php';
header('Content-Type: application/json');

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Validate and sanitize input
$gymId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$gymId) {
    echo json_encode([
        'error' => true,
        'message' => 'Invalid gym ID'
    ]);
    exit;
}

try {
    // Fetch gym details with prepared statement
    $gymSql = "
        SELECT g.*, 
               (SELECT COUNT(*) FROM reviews r WHERE r.gym_id = g.gym_id) as review_count,
               (SELECT AVG(rating) FROM reviews r WHERE r.gym_id = g.gym_id) as avg_rating
        FROM gyms g 
        WHERE g.gym_id = ? AND g.status = 'active'
    ";
    $gymStmt = $conn->prepare($gymSql);
    $gymStmt->execute([$gymId]);
    $gym = $gymStmt->fetch(PDO::FETCH_ASSOC);

    if (!$gym) {
        echo json_encode([
            'error' => true,
            'message' => 'Gym not found or inactive'
        ]);
        exit;
    }

    // Format the rating
    $gym['avg_rating'] = $gym['avg_rating'] ? round(floatval($gym['avg_rating']), 1) : 0;

    // Fetch gym operating hours
    $hoursSql = "
        SELECT * 
        FROM gym_operating_hours 
        WHERE gym_id = ?
        ORDER BY FIELD(day, 'Daily', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
    ";
    $hoursStmt = $conn->prepare($hoursSql);
    $hoursStmt->execute([$gymId]);
    $gym['operating_hours'] = $hoursStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch gym images
    $imagesSql = "
        SELECT * FROM gym_images 
        WHERE gym_id = ? 
        ORDER BY is_cover DESC, image_id DESC
    ";
    $imagesStmt = $conn->prepare($imagesSql);
    $imagesStmt->execute([$gymId]);
    $gym['images'] = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Ensure we have at least one image
    if (empty($gym['images'])) {
        $gym['image_url'] = 'assets/images/gym-placeholder.jpg';
    } else {
        $gym['image_url'] = $gym['images'][0]['image_path'];
    }

    // Fetch membership plans with pricing info
    $plansSql = "
        SELECT * FROM gym_membership_plans 
        WHERE gym_id = ? 
        ORDER BY FIELD(duration, 'Daily', 'Weekly', 'Monthly', 'Quarterly', 'Yearly'), price ASC
    ";
    $plansStmt = $conn->prepare($plansSql);
    $plansStmt->execute([$gymId]);
    $plans = $plansStmt->fetchAll(PDO::FETCH_ASSOC);

    // Extract daily and monthly prices for quick reference
    foreach ($plans as $plan) {
        if ($plan['duration'] === 'Daily' && (!isset($gym['daily_price']) || $plan['price'] < $gym['daily_price'])) {
            $gym['daily_price'] = $plan['price'];
        }
        if ($plan['duration'] === 'Monthly' && (!isset($gym['monthly_price']) || $plan['price'] < $gym['monthly_price'])) {
            $gym['monthly_price'] = $plan['price'];
        }
    }

    // Fetch reviews with user information
    $reviewsSql = "
        SELECT r.*, u.name as user_name, u.profile_image
        FROM reviews r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.gym_id = ?
        ORDER BY r.created_at DESC
        LIMIT 10
    ";
    $reviewsStmt = $conn->prepare($reviewsSql);
    $reviewsStmt->execute([$gymId]);
    $reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch amenities data to include names
    if (!empty($gym['amenities'])) {
        $amenityIds = json_decode($gym['amenities'], true);
        
        if (is_array($amenityIds) && !empty($amenityIds)) {
            $placeholders = implode(',', array_fill(0, count($amenityIds), '?'));
            $amenitiesSql = "
                SELECT id, name, category 
                FROM amenities 
                WHERE id IN ($placeholders)
            ";
            $amenitiesStmt = $conn->prepare($amenitiesSql);
            $amenitiesStmt->execute($amenityIds);
            $gym['amenity_details'] = $amenitiesStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Fetch equipment information
    $equipmentSql = "
        SELECT * FROM gym_equipment
        WHERE gym_id = ? AND status = 'active'
        ORDER BY category, name
    ";
    $equipmentStmt = $conn->prepare($equipmentSql);
    $equipmentStmt->execute([$gymId]);
    $gym['equipment'] = $equipmentStmt->fetchAll(PDO::FETCH_ASSOC);

    // Group equipment by category
    $equipmentByCategory = [];
    foreach ($gym['equipment'] as $item) {
        $category = $item['category'] ?: 'Other';
        if (!isset($equipmentByCategory[$category])) {
            $equipmentByCategory[$category] = [];
        }
        $equipmentByCategory[$category][] = $item;
    }
    $gym['equipment_by_category'] = $equipmentByCategory;

    // Fetch upcoming events/classes
    $eventsSql = "
        SELECT * FROM gym_events
        WHERE gym_id = ? AND event_date >= CURDATE()
        ORDER BY event_date ASC, start_time ASC
        LIMIT 5
    ";
    $eventsStmt = $conn->prepare($eventsSql);
    $eventsStmt->execute([$gymId]);
    $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch trainers
    $trainersSql = "
        SELECT t.*, u.name, u.profile_image
        FROM gym_trainers t
        JOIN users u ON t.user_id = u.id
        WHERE t.gym_id = ? AND t.status = 'active'
        ORDER BY t.featured DESC, u.name ASC
    ";
    $trainersStmt = $conn->prepare($trainersSql);
    $trainersStmt->execute([$gymId]);
    $trainers = $trainersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Return comprehensive data as JSON
    echo json_encode([
        'error' => false,
        'gym' => $gym,
        'membership_plans' => $plans,
        'reviews' => $reviews,
        'events' => $events,
        'trainers' => $trainers
    ]);

} catch (PDOException $e) {
    // Log the error server-side
    error_log("Database error in get_gym_details.php: " . $e->getMessage());
    
    // Return a generic error message to the client
    echo json_encode([
        'error' => true,
        'message' => 'An error occurred while fetching gym details. Please try again later.'
    ]);
}
