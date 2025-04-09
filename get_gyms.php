<?php
// Enable error reporting for debugging but capture errors instead of displaying them
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Start output buffering to prevent any unexpected output
ob_start();

try {
    require_once 'config/database.php';

    // Initialize database connection
    $GymDatabase = new GymDatabase();
    $db = $GymDatabase->getConnection();

    // Get parameters
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 9;
    $offset = ($page - 1) * $limit;
    $search = $_GET['search'] ?? '';
    $city = $_GET['city'] ?? '';
    $amenities = $_GET['amenities'] ?? [];
    $min_price = $_GET['min_price'] ?? '';
    $max_price = $_GET['max_price'] ?? '';
    $sort = $_GET['sort'] ?? 'name_asc';

    // Build base query for fetching gyms with both daily and monthly prices
    $sql = "
        SELECT DISTINCT g.*, 
               (SELECT AVG(rating) FROM reviews r WHERE r.gym_id = g.gym_id) as avg_rating,
               (SELECT COUNT(*) FROM reviews r WHERE r.gym_id = g.gym_id) as review_count,
               (SELECT price FROM gym_membership_plans WHERE gym_id = g.gym_id AND duration = 'daily' LIMIT 1) as daily_price,
               (SELECT price FROM gym_membership_plans WHERE gym_id = g.gym_id AND duration = 'monthly' LIMIT 1) as monthly_price
        FROM gyms g 
        JOIN gym_membership_plans gmp ON g.gym_id = gmp.gym_id
        WHERE g.status = 'active'";

    // Build WHERE clause for filtering
    $whereClause = "";
    $params = [];

    // Add search filters
    if ($search) {
        $whereClause .= " AND (g.name LIKE ? OR g.description LIKE ? OR g.city LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($city) {
        $whereClause .= " AND g.city = ?";
        $params[] = $city;
    }
    
    // Improved amenities filtering - properly handle JSON format
    if (!empty($amenities)) {
        foreach ($amenities as $amenity) {
            // Ensure amenity ID is treated as an integer and properly formatted for JSON_CONTAINS
            $whereClause .= " AND JSON_CONTAINS(g.amenities, ?)";
            $params[] = json_encode((int)$amenity);
        }
    }
    
    // Price filtering - apply to either daily or monthly plans depending on what's available
    if ($min_price !== '') {
        $whereClause .= " AND (
            (gmp.duration = 'daily' AND gmp.price >= ?) OR 
            (gmp.duration = 'monthly' AND gmp.price/30 >= ?)
        )";
        $params[] = $min_price;
        $params[] = $min_price;
    }
    
    if ($max_price !== '') {
        $whereClause .= " AND (
            (gmp.duration = 'daily' AND gmp.price <= ?) OR 
            (gmp.duration = 'monthly' AND gmp.price/30 <= ?)
        )";
        $params[] = $max_price;
        $params[] = $max_price;
    }

    // Count total gyms for pagination - use a separate, simpler query
    $countSql = "
        SELECT COUNT(DISTINCT g.gym_id) as total 
        FROM gyms g 
        JOIN gym_membership_plans gmp ON g.gym_id = gmp.gym_id
        WHERE g.status = 'active'" . $whereClause;
        
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total_records = $countStmt->fetchColumn();
    $total_pages = ceil($total_records / $limit);

    // Add the WHERE clause to the main query
    $sql .= $whereClause;

    // Add sorting
    switch ($sort) {
        case 'name_desc':
            $sql .= " GROUP BY g.gym_id ORDER BY g.name DESC";
            break;
        case 'price_asc':
            $sql .= " GROUP BY g.gym_id ORDER BY COALESCE(daily_price, monthly_price/30) ASC";
            break;
        case 'price_desc':
            $sql .= " GROUP BY g.gym_id ORDER BY COALESCE(daily_price, monthly_price/30) DESC";
            break;
        case 'rating_desc':
            $sql .= " GROUP BY g.gym_id ORDER BY avg_rating DESC";
            break;
        case 'name_asc':
        default:
            $sql .= " GROUP BY g.gym_id ORDER BY g.name ASC";
            break;
    }

    // Add pagination
    $sql .= " LIMIT $limit OFFSET $offset";

    // Execute query
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch operating hours for each gym
    foreach ($gyms as &$gym) {
        try {
            $hoursSql = "
                SELECT * 
                FROM gym_operating_hours 
                WHERE gym_id = ?
                ORDER BY FIELD(day, 'Daily', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')";
            
            $hoursStmt = $db->prepare($hoursSql);
            $hoursStmt->execute([$gym['gym_id']]);
            $gym['operating_hours'] = $hoursStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse amenities to ensure they're in the correct format
            if (isset($gym['amenities']) && !empty($gym['amenities'])) {
                // If amenities is a JSON string, parse it
                if (is_string($gym['amenities'])) {
                    $gym['amenities'] = json_decode($gym['amenities'], true);
                }
            } else {
                $gym['amenities'] = [];
            }
        } catch (Exception $e) {
            // If there's an error (like table doesn't exist), just set empty operating hours
            $gym['operating_hours'] = [];
            // Log the error for debugging
            error_log("Error fetching operating hours: " . $e->getMessage());
        }
    }

    // Prepare pagination data
    $pagination = [
        'current_page' => $page,
        'total_pages' => $total_pages,
        'total_records' => $total_records,
        'limit' => $limit
    ];

    // Clear any buffered output
    ob_end_clean();
    
    // Set proper content type
    header('Content-Type: application/json');
    
    // Return JSON response
    echo json_encode([
        'gyms' => $gyms,
        'pagination' => $pagination
    ]);
    
} catch (Exception $e) {
    // Clear any buffered output
    ob_end_clean();
    
    // Set proper content type
    header('Content-Type: application/json');
    
    // Return error as JSON
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
        'gyms' => [],
        'pagination' => [
            'current_page' => 1,
            'total_pages' => 0,
            'total_records' => 0,
            'limit' => 9
        ]
    ]);
}
?>
