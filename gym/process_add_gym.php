<?php
session_start();
require '../config/database.php';

// Check if user is logged in as owner
if (!isset($_SESSION['owner_id'])) {
    header('Location: ../login.php');
    exit;
}

// Initialize database connection
$ownerId = $_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Function to sanitize input
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: add_gym.php');
    exit;
}

try {
    // Start transaction
    $conn->beginTransaction();
    
    // Basic gym information
    $gymName = sanitizeInput($_POST['gym_name']);
    $address = sanitizeInput($_POST['address']);
    $city = sanitizeInput($_POST['city']);
    $state = sanitizeInput($_POST['state']);
    $country = sanitizeInput($_POST['country']);
    $zipCode = sanitizeInput($_POST['zip_code']);
    $phone = sanitizeInput($_POST['phone']);
    $email = sanitizeInput($_POST['email']);
    $capacity = (int)$_POST['capacity'];
    $description = sanitizeInput($_POST['description']);
    $gymCutPercentage = isset($_POST['gym_cut_percentage']) ? (int)$_POST['gym_cut_percentage'] : 70;
    $additionalNotes = isset($_POST['additional_notes']) ? sanitizeInput($_POST['additional_notes']) : '';
    $latitude = isset($_POST['latitude']) && !empty($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = isset($_POST['longitude']) && !empty($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    
    // Policy settings
    $cancellationHours = isset($_POST['cancellation_hours']) ? (int)$_POST['cancellation_hours'] : 4;
    $rescheduleHours = isset($_POST['reschedule_hours']) ? (int)$_POST['reschedule_hours'] : 2;
    $cancellationFee = isset($_POST['cancellation_fee']) ? (float)$_POST['cancellation_fee'] : 200.00;
    $rescheduleFee = isset($_POST['reschedule_fee']) ? (float)$_POST['reschedule_fee'] : 100.00;
    $lateFee = isset($_POST['late_fee']) ? (float)$_POST['late_fee'] : 300.00;
    
    // Process amenities
    $selectedAmenities = isset($_POST['amenities']) ? $_POST['amenities'] : [];
    $amenitiesArray = [];
    foreach ($selectedAmenities as $amenityId) {
        $amenitiesArray[] = (int)$amenityId;
    }
    $amenitiesJson = json_encode($amenitiesArray);
    
    // Process operating hours
    $operatingHours = [];
    if (isset($_POST['same_hours_all_days']) && $_POST['same_hours_all_days'] == 'on') {
        // Use same hours for all days
        $morningOpen = $_POST['all_morning_open'] ?? '06:00';
        $morningClose = $_POST['all_morning_close'] ?? '12:00';
        $eveningOpen = $_POST['all_evening_open'] ?? '16:00';
        $eveningClose = $_POST['all_evening_close'] ?? '22:00';
        
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        foreach ($days as $day) {
            $operatingHours[$day] = [
                'morning_open_time' => $morningOpen,
                'morning_close_time' => $morningClose,
                'evening_open_time' => $eveningOpen,
                'evening_close_time' => $eveningClose
            ];
        }
    } else {
        // Use specific hours for each day from the hours array
        if (isset($_POST['hours']) && is_array($_POST['hours'])) {
            foreach ($_POST['hours'] as $day => $times) {
                $operatingHours[$day] = [
                    'morning_open_time' => $times['morning_open'] ?? '06:00',
                    'morning_close_time' => $times['morning_close'] ?? '12:00',
                    'evening_open_time' => $times['evening_open'] ?? '16:00',
                    'evening_close_time' => $times['evening_close'] ?? '22:00'
                ];
            }
        }
    }
    
    // Handle cover photo upload
    $coverPhoto = null;
    if (isset($_FILES['cover_photo']) && $_FILES['cover_photo']['error'] == 0) {
        $uploadDir = '../uploads/gym_images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileExtension = pathinfo($_FILES['cover_photo']['name'], PATHINFO_EXTENSION);
        $coverPhoto = uniqid('cover_') . '.' . $fileExtension;
        $uploadPath = $uploadDir . $coverPhoto;
        
        if (!move_uploaded_file($_FILES['cover_photo']['tmp_name'], $uploadPath)) {
            throw new Exception('Failed to upload cover photo');
        }
    } else {
        throw new Exception('Cover photo is required');
    }
    
    // Insert gym data
    $insertGymSql = "
        INSERT INTO gyms (
            owner_id, name, description, address, city, state, country, zip_code, 
            cover_photo, latitude, longitude, phone, email, amenities, capacity, 
            current_occupancy, gym_cut_percentage, additional_notes, 
            status, is_open, created_at, cancellation_policy, reschedule_policy, 
            late_fee_policy, reschedule_fee_amount, cancellation_fee_amount, late_fee_amount
        ) VALUES (
            :owner_id, :name, :description, :address, :city, :state, :country, :zip_code, 
            :cover_photo, :latitude, :longitude, :phone, :email, :amenities, :capacity, 
            0, :gym_cut_percentage, :additional_notes, 
            'pending', 1, NOW(), 'standard', 'standard', 'standard', 
            :reschedule_fee, :cancellation_fee, :late_fee
        )
    ";
    
    $stmt = $conn->prepare($insertGymSql);
    $stmt->bindParam(':owner_id', $ownerId, PDO::PARAM_INT);
    $stmt->bindParam(':name', $gymName, PDO::PARAM_STR);
    $stmt->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt->bindParam(':address', $address, PDO::PARAM_STR);
    $stmt->bindParam(':city', $city, PDO::PARAM_STR);
    $stmt->bindParam(':state', $state, PDO::PARAM_STR);
    $stmt->bindParam(':country', $country, PDO::PARAM_STR);
    $stmt->bindParam(':zip_code', $zipCode, PDO::PARAM_STR);
    $stmt->bindParam(':cover_photo', $coverPhoto, PDO::PARAM_STR);
    $stmt->bindParam(':latitude', $latitude, PDO::PARAM_STR);
    $stmt->bindParam(':longitude', $longitude, PDO::PARAM_STR);
    $stmt->bindParam(':phone', $phone, PDO::PARAM_STR);
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->bindParam(':amenities', $amenitiesJson, PDO::PARAM_STR);
    $stmt->bindParam(':capacity', $capacity, PDO::PARAM_INT);
    $stmt->bindParam(':gym_cut_percentage', $gymCutPercentage, PDO::PARAM_INT);
    $stmt->bindParam(':additional_notes', $additionalNotes, PDO::PARAM_STR);
    $stmt->bindParam(':reschedule_fee', $rescheduleFee, PDO::PARAM_STR);
    $stmt->bindParam(':cancellation_fee', $cancellationFee, PDO::PARAM_STR);
    $stmt->bindParam(':late_fee', $lateFee, PDO::PARAM_STR);
    $stmt->execute();
    
    $gymId = $conn->lastInsertId();
    
    // Insert gym policies
    $insertPoliciesSql = "
        INSERT INTO gym_policies (
            gym_id, cancellation_hours, reschedule_hours, 
            cancellation_fee, reschedule_fee, late_fee, 
            is_active, created_at
        ) VALUES (
            :gym_id, :cancellation_hours, :reschedule_hours, 
            :cancellation_fee, :reschedule_fee, :late_fee, 
            1, NOW()
        )
    ";
    
    $stmt = $conn->prepare($insertPoliciesSql);
    $stmt->bindParam(':gym_id', $gymId, PDO::PARAM_INT);
    $stmt->bindParam(':cancellation_hours', $cancellationHours, PDO::PARAM_INT);
    $stmt->bindParam(':reschedule_hours', $rescheduleHours, PDO::PARAM_INT);
    $stmt->bindParam(':cancellation_fee', $cancellationFee, PDO::PARAM_STR);
    $stmt->bindParam(':reschedule_fee', $rescheduleFee, PDO::PARAM_STR);
    $stmt->bindParam(':late_fee', $lateFee, PDO::PARAM_STR);
    $stmt->execute();
    
    // Insert operating hours
    foreach ($operatingHours as $day => $hours) {
        $insertHoursSql = "
            INSERT INTO gym_operating_hours (
                gym_id, day, morning_open_time, morning_close_time, 
                evening_open_time, evening_close_time
            ) VALUES (
                :gym_id, :day, :morning_open_time, :morning_close_time, 
                :evening_open_time, :evening_close_time
            )
        ";
        
        $stmt = $conn->prepare($insertHoursSql);
        $stmt->bindParam(':gym_id', $gymId, PDO::PARAM_INT);
        $stmt->bindParam(':day', $day, PDO::PARAM_STR);
        $stmt->bindParam(':morning_open_time', $hours['morning_open_time'], PDO::PARAM_STR);
        $stmt->bindParam(':morning_close_time', $hours['morning_close_time'], PDO::PARAM_STR);
        $stmt->bindParam(':evening_open_time', $hours['evening_open_time'], PDO::PARAM_STR);
        $stmt->bindParam(':evening_close_time', $hours['evening_close_time'], PDO::PARAM_STR);
        $stmt->execute();
    }
    
    // Process equipment
    if (isset($_POST['equipment']) && is_array($_POST['equipment'])) {
        foreach ($_POST['equipment'] as $equipment) {
            if (!empty($equipment['name']) && !empty($equipment['category']) && isset($equipment['quantity'])) {
                $equipmentName = sanitizeInput($equipment['name']);
                $equipmentCategory = sanitizeInput($equipment['category']);
                $equipmentQuantity = (int)$equipment['quantity'];
                
                if ($equipmentQuantity > 0) {
                    $insertEquipmentSql = "
                        INSERT INTO gym_equipment (
                            gym_id, category, name, quantity, status, created_at
                        ) VALUES (
                            :gym_id, :category, :name, :quantity, 'active', NOW()
                        )
                    ";
                    
                    $stmt = $conn->prepare($insertEquipmentSql);
                    $stmt->bindParam(':gym_id', $gymId, PDO::PARAM_INT);
                    $stmt->bindParam(':category', $equipmentCategory, PDO::PARAM_STR);
                    $stmt->bindParam(':name', $equipmentName, PDO::PARAM_STR);
                    $stmt->bindParam(':quantity', $equipmentQuantity, PDO::PARAM_INT);
                    $stmt->execute();
                }
            }
        }
    }
    
    // Process gallery images
    if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
        $uploadDir = '../uploads/gym_images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $totalFiles = count($_FILES['gallery_images']['name']);
        $maxFiles = min($totalFiles, 5); // Limit to 5 images
        
        for ($i = 0; $i < $maxFiles; $i++) {
            if ($_FILES['gallery_images']['error'][$i] == 0) {
                $fileExtension = pathinfo($_FILES['gallery_images']['name'][$i], PATHINFO_EXTENSION);
                $fileName = uniqid('gallery_') . '.' . $fileExtension;
                $uploadPath = $uploadDir . $fileName;
                
                if (move_uploaded_file($_FILES['gallery_images']['tmp_name'][$i], $uploadPath)) {
                    $insertImageSql = "
                        INSERT INTO gym_gallery (
                            gym_id, image_path, caption, display_order, status, created_at
                        ) VALUES (
                            :gym_id, :image_path, :caption, :display_order, 'active', NOW()
                        )
                    ";
                    
                    $caption = "Gallery image " . ($i + 1);
                    
                    $stmt = $conn->prepare($insertImageSql);
                    $stmt->bindParam(':gym_id', $gymId, PDO::PARAM_INT);
                    $stmt->bindParam(':image_path', $fileName, PDO::PARAM_STR);
                    $stmt->bindParam(':caption', $caption, PDO::PARAM_STR);
                    $stmt->bindParam(':display_order', $i, PDO::PARAM_INT);
                    $stmt->execute();
                }
            }
        }
    }
    
    // Process membership plans
    if (isset($_POST['plans']) && is_array($_POST['plans'])) {
        foreach ($_POST['plans'] as $plan) {
            if (!empty($plan['name']) && isset($plan['price']) && !empty($plan['tier']) && !empty($plan['duration']) && !empty($plan['type']) && !empty($plan['best_for'])) {
                $planName = sanitizeInput($plan['name']);
                $price = (float)$plan['price'];
                $tier = sanitizeInput($plan['tier']);
                $duration = sanitizeInput($plan['duration']);
                $planType = sanitizeInput($plan['type']);
                $bestFor = sanitizeInput($plan['best_for']);
                $inclusions = isset($plan['inclusions']) ? sanitizeInput($plan['inclusions']) : '';
                
                $insertPlanSql = "
                    INSERT INTO gym_membership_plans (
                        gym_id,
                                                plan_name, tier, duration, plan_type, price, best_for, inclusions
                    ) VALUES (
                        :gym_id, :plan_name, :tier, :duration, :plan_type, :price, :best_for, :inclusions
                    )
                ";
                
                $stmt = $conn->prepare($insertPlanSql);
                $stmt->bindParam(':gym_id', $gymId, PDO::PARAM_INT);
                $stmt->bindParam(':plan_name', $planName, PDO::PARAM_STR);
                $stmt->bindParam(':tier', $tier, PDO::PARAM_STR);
                $stmt->bindParam(':duration', $duration, PDO::PARAM_STR);
                $stmt->bindParam(':plan_type', $planType, PDO::PARAM_STR);
                $stmt->bindParam(':price', $price, PDO::PARAM_STR);
                $stmt->bindParam(':best_for', $bestFor, PDO::PARAM_STR);
                $stmt->bindParam(':inclusions', $inclusions, PDO::PARAM_STR);
                $stmt->execute();
            }
        }
    }
    
    // Set up edit permissions (default all allowed)
    $insertPermissionsSql = "
        INSERT INTO gym_edit_permissions (
            gym_id, basic_info, operating_hours, amenities, images, equipment, membership_plans, created_at
        ) VALUES (
            :gym_id, 1, 1, 1, 1, 1, 1, NOW()
        )
    ";
    
    $stmt = $conn->prepare($insertPermissionsSql);
    $stmt->bindParam(':gym_id', $gymId, PDO::PARAM_INT);
    $stmt->execute();
    
    // Create notification for admin about new gym
    $insertNotificationSql = "
        INSERT INTO notifications (
            user_id, type, message, related_id, title, created_at, status, gym_id, is_read
        ) VALUES (
            1, 'gym_approval', :message, :gym_id, 'New Gym Registration', NOW(), 'unread', :gym_id, 0
        )
    ";
    
    $notificationMessage = "A new gym '{$gymName}' has been registered by owner #{$ownerId} and is pending approval.";
    
    $stmt = $conn->prepare($insertNotificationSql);
    $stmt->bindParam(':message', $notificationMessage, PDO::PARAM_STR);
    $stmt->bindParam(':gym_id', $gymId, PDO::PARAM_INT);
    $stmt->execute();
    
    // Log the activity
    $activityDetailsSql = "
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent, created_at
        ) VALUES (
            :user_id, 'owner', 'add_gym', :details, :ip_address, :user_agent, NOW()
        )
    ";
    
    $activityDetails = "Added new gym: {$gymName} (ID: {$gymId})";
    
    $stmt = $conn->prepare($activityDetailsSql);
    $stmt->bindParam(':user_id', $ownerId, PDO::PARAM_INT);
    $stmt->bindParam(':details', $activityDetails, PDO::PARAM_STR);
    $stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR'], PDO::PARAM_STR);
    $stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT'], PDO::PARAM_STR);
    $stmt->execute();
    
    // Commit transaction
    $conn->commit();
    
    // Set success message in session
    $_SESSION['success'] = "Gym added successfully! Your gym will be reviewed by our team and activated soon.";
    
    // Redirect to dashboard
    header('Location: dashboard.php');
    exit;
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollBack();
    
    // Log the error
    error_log("Error adding gym: " . $e->getMessage());
    
    // Set error message in session
    $_SESSION['error'] = "Error adding gym: " . $e->getMessage();
    
    // Redirect back to form
    header('Location: add_gym.php');
    exit;
}
?>

