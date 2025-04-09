<?php
// Start session if not already started
if (!isset($_SESSION)) {
    session_start();
}

// Include database connection
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['owner_id'])) {
    // Return error response if not logged in
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'User not authenticated',
        'notifications' => []
    ]);
    exit;
}

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Get user information based on role
$user_id = $_SESSION['user_id'] ?? null;
$owner_id = $_SESSION['owner_id'] ?? null;
$role = $_SESSION['role'] ?? '';

// Fetch notifications based on user role
try {
    if ($role === 'member' && $user_id) {
        // Query for member notifications
        $query = "SELECT 
                    id, 
                    title, 
                    message, 
                    status, 
                    created_at, 
                    related_id,
                    type,
                    CASE 
                        WHEN type = 'membership' THEN CONCAT('../view_membership.php?id=', related_id)
                        WHEN type = 'schedule' THEN CONCAT('../schedule-history.php?id=', related_id)
                        WHEN type = 'payment' THEN CONCAT('../payment_history.php?id=', related_id)
                        WHEN type = 'gym' THEN CONCAT('../gym-profile.php?id=', related_id)
                        ELSE '#'
                    END as link
                  FROM notifications 
                  WHERE user_id = ? 
                  ORDER BY created_at DESC 
                  LIMIT 10";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([$user_id]);
        
    } elseif ($owner_id) {
        // Query for gym owner notifications
        $query = "SELECT 
                    n.id, 
                    n.title, 
                    n.message, 
                    n.is_read as status, 
                    n.created_at, 
                    n.related_id,
                    n.type,
                    CASE 
                        WHEN n.type = 'membership' THEN CONCAT('../gym/membership_plans.php?id=', n.related_id)
                        WHEN n.type = 'schedule' THEN CONCAT('../gym/schedules.php?id=', n.related_id)
                        WHEN n.type = 'payment' THEN CONCAT('../gym/revenue.php?id=', n.related_id)
                        WHEN n.type = 'member' THEN CONCAT('../gym/member_list.php?id=', n.related_id)
                        ELSE '#'
                    END as link
                  FROM notifications n
                  JOIN gyms g ON n.gym_id = g.gym_id
                  WHERE g.owner_id = ? 
                  ORDER BY n.created_at DESC 
                  LIMIT 10";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([$owner_id]);
        
    } else {
        // Default empty response for other roles
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'notifications' => []
        ]);
        exit;
    }
    
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the created_at date for display
    foreach ($notifications as &$notification) {
        // Convert created_at to a more readable format
        $created_date = new DateTime($notification['created_at']);
        $now = new DateTime();
        $interval = $now->diff($created_date);
        
        if ($interval->days == 0) {
            if ($interval->h == 0) {
                if ($interval->i == 0) {
                    $notification['created_at'] = 'Just now';
                } else {
                    $notification['created_at'] = $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
                }
            } else {
                $notification['created_at'] = $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
            }
        } elseif ($interval->days == 1) {
            $notification['created_at'] = 'Yesterday';
        } elseif ($interval->days < 7) {
            $notification['created_at'] = $interval->days . ' days ago';
        } else {
            $notification['created_at'] = $created_date->format('M d, Y');
        }
        
        // Convert status to boolean for consistency
        if (isset($notification['status'])) {
            if ($role === 'member') {
                $notification['is_read'] = ($notification['status'] === 'read') ? 1 : 0;
            } else {
                // For gym owners, status is already stored as is_read (0 or 1)
                $notification['is_read'] = (int)$notification['status'];
            }
        }
    }
    
    // Return success response with notifications
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'notifications' => $notifications
    ]);
    
} catch (PDOException $e) {
    // Return error response if database query fails
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage(),
        'notifications' => []
    ]);
}
?>
