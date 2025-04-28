<?php
require_once '../config/database.php';
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cut_type = $_POST['cut_type'] ?? '';
    
    // Validate form data
    if ($cut_type === 'tier_based') {
        // Process tier based cut-off
        $tier = $_POST['tier'] ?? '';
        $duration = $_POST['duration'] ?? '';
        $admin_cut = isset($_POST['admin_cut']) ? (float)$_POST['admin_cut'] : 0;
        $gym_cut = isset($_POST['gym_cut']) ? (float)$_POST['gym_cut'] : 0;
        
        // Validate data
        if (empty($tier) || empty($duration) || $admin_cut <= 0 || $gym_cut <= 0) {
            $_SESSION['cutoff_error'] = "All fields are required and percentages must be greater than zero.";
            header('Location: add-cutoff.php');
            exit();
        }
        
        // Validate total percentage equals 100
        if (abs($admin_cut + $gym_cut - 100) > 0.01) { // Allow small floating point errors
            $_SESSION['cutoff_error'] = "Total percentage must equal 100%. Current total: " . ($admin_cut + $gym_cut) . "%";
            header('Location: add-cutoff.php');
            exit();
        }
        
        try {
            // Check if this tier and duration combination already exists
            $checkStmt = $conn->prepare("SELECT id FROM cut_off_chart WHERE tier = ? AND duration = ?");
            $checkStmt->execute([$tier, $duration]);
            
            if ($checkStmt->rowCount() > 0) {
                // Update existing record
                $stmt = $conn->prepare("
                    UPDATE cut_off_chart 
                    SET admin_cut_percentage = ?, gym_owner_cut_percentage = ? 
                    WHERE tier = ? AND duration = ?
                ");
                $stmt->execute([$admin_cut, $gym_cut, $tier, $duration]);
                
                // Log the activity
                $stmt = $conn->prepare("
                                        INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (?, 'admin', 'update_tier_cutoff', ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['admin_id'],
                    "Updated tier-based cutoff for $tier, $duration: Admin $admin_cut%, Gym $gym_cut%",
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $_SESSION['cutoff_success'] = "Tier-based cutoff updated successfully!";
            } else {
                // Insert new record
                $stmt = $conn->prepare("
                    INSERT INTO cut_off_chart (
                        tier, duration, admin_cut_percentage, gym_owner_cut_percentage, cut_type
                    ) VALUES (?, ?, ?, ?, 'tier_based')
                ");
                $stmt->execute([$tier, $duration, $admin_cut, $gym_cut]);
                
                // Log the activity
                $stmt = $conn->prepare("
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (?, 'admin', 'add_tier_cutoff', ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['admin_id'],
                    "Added tier-based cutoff for $tier, $duration: Admin $admin_cut%, Gym $gym_cut%",
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $_SESSION['cutoff_success'] = "Tier-based cutoff added successfully!";
            }
        } catch (PDOException $e) {
            $_SESSION['cutoff_error'] = "Database error: " . $e->getMessage();
        }
    } elseif ($cut_type === 'fee_based') {
        // Process fee based cut-off
        $price_start = isset($_POST['price_start']) ? (float)$_POST['price_start'] : 0;
        $price_end = isset($_POST['price_end']) ? (float)$_POST['price_end'] : 0;
        $admin_cut = isset($_POST['admin_cut']) ? (float)$_POST['admin_cut'] : 0;
        $gym_cut = isset($_POST['gym_cut']) ? (float)$_POST['gym_cut'] : 0;
        
        // Validate data
        if ($price_start <= 0 || $price_end <= 0 || $admin_cut <= 0 || $gym_cut <= 0) {
            $_SESSION['cutoff_error'] = "All fields are required and values must be greater than zero.";
            header('Location: add-cutoff.php');
            exit();
        }
        
        // Validate price range
        if ($price_start >= $price_end) {
            $_SESSION['cutoff_error'] = "Price range end must be greater than price range start.";
            header('Location: add-cutoff.php');
            exit();
        }
        
        // Validate total percentage equals 100
        if (abs($admin_cut + $gym_cut - 100) > 0.01) { // Allow small floating point errors
            $_SESSION['cutoff_error'] = "Total percentage must equal 100%. Current total: " . ($admin_cut + $gym_cut) . "%";
            header('Location: add-cutoff.php');
            exit();
        }
        
        try {
            // Check for overlapping price ranges
            $checkStmt = $conn->prepare("
                SELECT id FROM fee_based_cuts 
                WHERE (? BETWEEN price_range_start AND price_range_end) 
                   OR (? BETWEEN price_range_start AND price_range_end)
                   OR (price_range_start BETWEEN ? AND ?)
                   OR (price_range_end BETWEEN ? AND ?)
            ");
            $checkStmt->execute([$price_start, $price_end, $price_start, $price_end, $price_start, $price_end]);
            
            if ($checkStmt->rowCount() > 0) {
                $_SESSION['cutoff_error'] = "Price range overlaps with an existing range. Please choose a different range.";
                header('Location: add-cutoff.php');
                exit();
            }
            
            // Insert new record
            $stmt = $conn->prepare("
                INSERT INTO fee_based_cuts (
                    price_range_start, price_range_end, admin_cut_percentage, gym_cut_percentage, cut_type
                ) VALUES (?, ?, ?, ?, 'fee_based')
            ");
            $stmt->execute([$price_start, $price_end, $admin_cut, $gym_cut]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', 'add_fee_cutoff', ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['admin_id'],
                "Added fee-based cutoff for price range ₹$price_start - ₹$price_end: Admin $admin_cut%, Gym $gym_cut%",
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $_SESSION['cutoff_success'] = "Fee-based cutoff added successfully!";
        } catch (PDOException $e) {
            $_SESSION['cutoff_error'] = "Database error: " . $e->getMessage();
        }
    } else {
        $_SESSION['cutoff_error'] = "Invalid cut-off type.";
    }
    
    // Redirect back to the add-cutoff page
    header('Location: add-cutoff.php');
    exit();
} else {
    // If not a POST request, redirect to the add-cutoff page
    header('Location: add-cutoff.php');
    exit();
}

