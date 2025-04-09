<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Start session if not already started
if (!isset($_SESSION)) {
    session_start();
}

// Include navbar and database connection
require_once '../includes/navbar.php';
require_once '../config/database.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Check if owner is logged in
if (!isset($_SESSION['owner_id'])) {
    $_SESSION['error'] = "Please log in to access this page.";
    header("Location: ./login.php");
    exit;
}

$owner_id = $_SESSION['owner_id'];

// Fetch gym details for this owner
$query = "SELECT * FROM gyms WHERE owner_id = :owner_id";
$stmt = $conn->prepare($query);
$stmt->execute([':owner_id' => $owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    $_SESSION['error'] = "No gym found. Please add a gym first.";
    header("Location: add_gym.php");
    exit;
}

$gym_id = $gym['gym_id'];

// Fetch related data
$query_images = "SELECT * FROM gym_images WHERE gym_id = :gym_id";
$stmt_images = $conn->prepare($query_images);
$stmt_images->execute([':gym_id' => $gym_id]);
$gym_images = $stmt_images->fetchAll(PDO::FETCH_ASSOC);

$query_equipment = "SELECT * FROM gym_equipment WHERE gym_id = :gym_id";
$stmt_equipment = $conn->prepare($query_equipment);
$stmt_equipment->execute([':gym_id' => $gym_id]);
$gym_equipment = $stmt_equipment->fetchAll(PDO::FETCH_ASSOC);

$query_plans = "SELECT * FROM gym_membership_plans WHERE gym_id = :gym_id";
$stmt_plans = $conn->prepare($query_plans);
$stmt_plans->execute([':gym_id' => $gym_id]);
$gym_plans = $stmt_plans->fetchAll(PDO::FETCH_ASSOC);

// Fetch operating hours
$query_hours = "SELECT * FROM gym_operating_hours WHERE gym_id = :gym_id";
$stmt_hours = $conn->prepare($query_hours);
$stmt_hours->execute([':gym_id' => $gym_id]);
$operating_hours = $stmt_hours->fetchAll(PDO::FETCH_ASSOC);

// Organize operating hours by day
$hours_by_day = [];
foreach ($operating_hours as $hour) {
    $hours_by_day[$hour['day']] = $hour;
}

// Check if there's a "Daily" schedule
$has_daily_schedule = isset($hours_by_day['Daily']);

// Fetch permissions
$stmt = $conn->prepare("SELECT * FROM gym_edit_permissions WHERE gym_id = ?");
$stmt->execute([$gym_id]);
$permissions = $stmt->fetch(PDO::FETCH_ASSOC);

// If no permissions set, use default (everything allowed except gym_cut_percentage)
if (!$permissions) {
    $permissions = [
        'basic_info' => 1,
        'operating_hours' => 1,
        'amenities' => 1,
        'images' => 1,
        'equipment' => 1,
        'membership_plans' => 1,
        'gym_cut_percentage' => 0
    ];
}

// Check if specific tab is being saved
$tab_to_save = isset($_POST['save_tab']) ? $_POST['save_tab'] : '';
$tab_saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($tab_to_save)) {
    // Validate that user has permission to edit this tab
    if (!isset($permissions[$tab_to_save]) || !$permissions[$tab_to_save]) {
        $error_message = "You don't have permission to edit the $tab_to_save section.";
    } else {
        try {
            // Start transaction
            $conn->beginTransaction();

            // Process the specific tab's data
            switch ($tab_to_save) {
                case 'basic_info':
                    // Process basic info fields
                    $gym_name = $_POST['gym_name'] ?? '';
                    $address = $_POST['address'] ?? '';
                    $city = $_POST['city'] ?? '';
                    $state = $_POST['state'] ?? '';
                    $zip_code = $_POST['zip_code'] ?? '';
                    $phone = $_POST['phone'] ?? '';
                    $email = $_POST['email'] ?? '';
                    $description = $_POST['description'] ?? '';
                    $capacity = $_POST['capacity'] ?? 0;
                    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
                    $additional_notes = $_POST['additional_notes'] ?? '';

                    // Only include gym_cut_percentage if user has permission
                    $gym_cut_percentage_sql = '';
                    $params = [
                        ':name' => $gym_name,
                        ':address' => $address,
                        ':city' => $city,
                        ':state' => $state,
                        ':zip_code' => $zip_code,
                        ':phone' => $phone,
                        ':email' => $email,
                        ':capacity' => $capacity,
                        ':description' => $description,
                        ':is_featured' => $is_featured,
                        ':additional_notes' => $additional_notes
                    ];

                    if ($permissions['gym_cut_percentage']) {
                        $gym_cut_percentage = isset($_POST['gym_cut_percentage']) ? intval($_POST['gym_cut_percentage']) : 70;
                        if ($gym_cut_percentage < 0 || $gym_cut_percentage > 100) {
                            $gym_cut_percentage = 70;
                        }
                        $gym_cut_percentage_sql = ', gym_cut_percentage = :gym_cut_percentage';
                        $params[':gym_cut_percentage'] = $gym_cut_percentage;
                    }

                    $params[':gym_id'] = $gym_id;

                    $update_query = "UPDATE gyms SET 
                        name = :name, 
                        address = :address, 
                        city = :city, 
                        state = :state, 
                        zip_code = :zip_code, 
                        phone = :phone, 
                        email = :email, 
                        capacity = :capacity, 
                        description = :description, 
                        is_featured = :is_featured,
                        additional_notes = :additional_notes
                        $gym_cut_percentage_sql
                        WHERE gym_id = :gym_id";

                    $stmt_update = $conn->prepare($update_query);
                    $result = $stmt_update->execute($params);

                    // Handle cover photo update
                    if (isset($_FILES['cover_photo']) && $_FILES['cover_photo']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = './uploads/gym_images/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }

                        $cover_photo_name = uniqid() . '_' . basename($_FILES['cover_photo']['name']);
                        $cover_photo_path = $upload_dir . $cover_photo_name;

                        if (move_uploaded_file($_FILES['cover_photo']['tmp_name'], $cover_photo_path)) {
                            // Delete old cover photo if exists
                            if ($gym['cover_photo'] && file_exists($upload_dir . $gym['cover_photo'])) {
                                unlink($upload_dir . $gym['cover_photo']);
                            }

                            // Update cover_photo in database
                            $update_cover = "UPDATE gyms SET cover_photo = :cover_photo WHERE gym_id = :gym_id";
                            $stmt_cover = $conn->prepare($update_cover);
                            $stmt_cover->execute([
                                ':cover_photo' => $cover_photo_name,
                                ':gym_id' => $gym_id
                            ]);
                        }
                    }

                    $tab_saved = true;
                    break;

                case 'operating_hours':
                    // Delete existing hours
                    $delete_hours = "DELETE FROM gym_operating_hours WHERE gym_id = :gym_id";
                    $stmt_delete = $conn->prepare($delete_hours);
                    $stmt_delete->execute([':gym_id' => $gym_id]);

                    // Insert new hours
                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    if (isset($_POST['use_daily_hours']) && $_POST['use_daily_hours'] == 'on') {
                        // Use same hours for all days
                        $daily = $_POST['operating_hours']['daily'] ?? [];

                        if (
                            empty($daily['morning_open_time']) || empty($daily['morning_close_time']) ||
                            empty($daily['evening_open_time']) || empty($daily['evening_close_time'])
                        ) {
                            throw new Exception("All operating hours fields must be filled out");
                        }

                        foreach ($days as $day) {
                            $insert_hours = "INSERT INTO gym_operating_hours 
                                (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time) 
                                VALUES (:gym_id, :day, :morning_open, :morning_close, :evening_open, :evening_close)";

                            $stmt_hours = $conn->prepare($insert_hours);
                            $stmt_hours->execute([
                                ':gym_id' => $gym_id,
                                ':day' => $day,
                                ':morning_open' => $daily['morning_open_time'] ?? '00:00:00',
                                ':morning_close' => $daily['morning_close_time'] ?? '00:00:00',
                                ':evening_open' => $daily['evening_open_time'] ?? '00:00:00',
                                ':evening_close' => $daily['evening_close_time'] ?? '00:00:00'
                            ]);
                        }

                        // Also add a "Daily" entry for convenience
                        $insert_hours = "INSERT INTO gym_operating_hours 
                            (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time) 
                            VALUES (:gym_id, 'Daily', :morning_open, :morning_close, :evening_open, :evening_close)";

                        $stmt_hours = $conn->prepare($insert_hours);
                        $stmt_hours->execute([
                            ':gym_id' => $gym_id,
                            ':morning_open' => $daily['morning_open_time'] ?? '00:00:00',
                            ':morning_close' => $daily['morning_close_time'] ?? '00:00:00',
                            ':evening_open' => $daily['evening_open_time'] ?? '00:00:00',
                            ':evening_close' => $daily['evening_close_time'] ?? '00:00:00'
                        ]);
                    } else {
                        // Use specific hours for each day
                        foreach ($days as $day) {
                            $day_lower = strtolower($day);
                            if (isset($_POST['operating_hours'][$day_lower])) {
                                $day_hours = $_POST['operating_hours'][$day_lower];

                                // Check if day is marked as closed
                                if (isset($_POST['closed_days']) && in_array($day_lower, $_POST['closed_days'])) {
                                    $insert_hours = "INSERT INTO gym_operating_hours 
                                        (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time) 
                                        VALUES (:gym_id, :day, '00:00:00', '00:00:00', '00:00:00', '00:00:00')";

                                    $stmt_hours = $conn->prepare($insert_hours);
                                    $stmt_hours->execute([
                                        ':gym_id' => $gym_id,
                                        ':day' => $day
                                    ]);
                                } else {
                                    if (
                                        empty($day_hours['morning_open_time']) || empty($day_hours['morning_close_time']) ||
                                        empty($day_hours['evening_open_time']) || empty($day_hours['evening_close_time'])
                                    ) {
                                        throw new Exception("All operating hours fields for $day must be filled out");
                                    }

                                    $insert_hours = "INSERT INTO gym_operating_hours 
                                    (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time) 
                                    VALUES (:gym_id, :day, :morning_open, :morning_close, :evening_open, :evening_close)";

                                    $stmt_hours = $conn->prepare($insert_hours);
                                    $stmt_hours->execute([
                                        ':gym_id' => $gym_id,
                                        ':day' => $day,
                                        ':morning_open' => $day_hours['morning_open_time'] ?? '00:00:00',
                                        ':morning_close' => $day_hours['morning_close_time'] ?? '00:00:00',
                                        ':evening_open' => $day_hours['evening_open_time'] ?? '00:00:00',
                                        ':evening_close' => $day_hours['evening_close_time'] ?? '00:00:00'
                                    ]);
                                }
                            }
                        }
                    }

                    $tab_saved = true;
                    break;

                case 'amenities':
                    // Get selected amenities
                    $selectedAmenities = isset($_POST['amenities']) ? $_POST['amenities'] : [];
                    
                    // Convert string amenities to proper format and ensure all IDs are integers
                    $processedAmenities = [];
                    foreach ($selectedAmenities as $amenity) {
                        if (is_numeric($amenity)) {
                            // This is an ID from the database
                            $processedAmenities[] = (int)$amenity;
                        } else {
                            // This is a custom amenity name
                            $processedAmenities[] = $amenity;
                        }
                    }
                    
                    $amenitiesJson = json_encode($processedAmenities);
                
                    $update_query = "UPDATE gyms SET amenities = :amenities WHERE gym_id = :gym_id";
                    $stmt_update = $conn->prepare($update_query);
                    $result = $stmt_update->execute([
                        ':amenities' => $amenitiesJson,
                        ':gym_id' => $gym_id
                    ]);
                
                    $tab_saved = true;
                    break;

                case 'images':
                    // Handle additional gym images
                    if (isset($_FILES['gym_images']) && !empty($_FILES['gym_images']['name'][0])) {
                        $upload_dir = './uploads/gym_images/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }
                        
                        $totalFiles = count($_FILES['gym_images']['name']);
                        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $maxFileSize = 5 * 1024 * 1024; // 5MB limit
                        
                        for ($i = 0; $i < $totalFiles; $i++) {
                            if ($_FILES['gym_images']['error'][$i] == 0) {
                                // Security check: validate file type and size
                                $fileType = $_FILES['gym_images']['type'][$i];
                                $fileSize = $_FILES['gym_images']['size'][$i];
                                
                                if (!in_array($fileType, $allowedTypes)) {
                                    throw new Exception("Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.");
                                }
                                
                                if ($fileSize > $maxFileSize) {
                                    throw new Exception("File size exceeds the 5MB limit.");
                                }
                                
                                // Generate a secure filename with a random string
                                $fileExtension = pathinfo($_FILES['gym_images']['name'][$i], PATHINFO_EXTENSION);
                                $fileName = bin2hex(random_bytes(8)) . '_' . time() . '.' . $fileExtension;
                                $uploadPath = $upload_dir . $fileName;
                                
                                if (move_uploaded_file($_FILES['gym_images']['tmp_name'][$i], $uploadPath)) {
                                    // Optimize image if possible
                                    if (extension_loaded('imagick')) {
                                        try {
                                            $image = new Imagick($uploadPath);
                                            $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                                            $image->setImageCompressionQuality(85);
                                            $image->stripImage(); // Remove EXIF data for privacy and size
                                            $image->writeImage($uploadPath);
                                            $image->destroy();
                                        } catch (Exception $e) {
                                            // Log error but continue
                                            error_log("Image optimization failed: " . $e->getMessage());
                                        }
                                    }
                                    
                                    // Determine if this is a cover image
                                    $is_cover = isset($_POST['cover_image']) && $_POST['cover_image'] == $i ? 1 : 0;
                                    
                                    // Insert image record with prepared statement
                                    $stmt = $conn->prepare("
                                        INSERT INTO gym_images 
                                        (gym_id, image_path, is_cover, created_at)
                                        VALUES (:gym_id, :image_path, :is_cover, NOW())
                                    ");

                                    $result = $stmt->execute([
                                        ':gym_id' => $gym_id,
                                        ':image_path' => $fileName,
                                        ':is_cover' => $is_cover
                                    ]);

                                    if (!$result) {
                                        error_log("Failed to insert image record: " . print_r($stmt->errorInfo(), true));
                                        throw new Exception("Failed to save image information");
                                    }

                                    // If this is a cover image, update the gym's cover_photo
                                    if ($is_cover) {
                                        $update_cover = "UPDATE gyms SET cover_photo = :cover_photo WHERE gym_id = :gym_id";
                                        $stmt = $conn->prepare($update_cover);
                                        $stmt->execute([
                                            ':cover_photo' => $fileName,
                                            ':gym_id' => $gym_id
                                        ]);
                                    }
                                }
                            }
                        }
                    }
                    
                    // Handle image deletions
                    if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
                        foreach ($_POST['delete_images'] as $image_id) {
                            // First get the image path
                            $stmt = $conn->prepare("SELECT image_path, is_cover FROM gym_images WHERE image_id = :image_id AND gym_id = :gym_id");
                            $stmt->execute([
                                ':image_id' => $image_id,
                                ':gym_id' => $gym_id
                            ]);
                            
                            $image = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($image) {
                                // Delete the file
                                $file_path = $upload_dir . $image['image_path'];
                                if (file_exists($file_path)) {
                                    unlink($file_path);
                                }
                                
                                // Delete from database
                                $stmt = $conn->prepare("DELETE FROM gym_images WHERE image_id = :image_id AND gym_id = :gym_id");
                                $stmt->execute([
                                    ':image_id' => $image_id,
                                    ':gym_id' => $gym_id
                                ]);
                                
                                // If this was a cover image, update the gym record
                                if ($image['is_cover']) {
                                    $update_cover = "UPDATE gyms SET cover_photo = NULL WHERE gym_id = :gym_id AND cover_photo = :cover_photo";
                                    $stmt = $conn->prepare($update_cover);
                                    $stmt->execute([
                                        ':gym_id' => $gym_id,
                                        ':cover_photo' => $image['image_path']
                                    ]);
                                }
                            }
                        }
                    }
                    
                    $tab_saved = true;
                    break;

                case 'equipment':
                    // Process equipment updates
                    if (isset($_POST['equipment']) && is_array($_POST['equipment'])) {
                        foreach ($_POST['equipment'] as $equipment_id => $equipment_data) {
                            // Validate data
                            $name = trim($equipment_data['name'] ?? '');
                            $quantity = intval($equipment_data['quantity'] ?? 0);
                            $category = trim($equipment_data['category'] ?? 'General');
                            $status = $equipment_data['status'] ?? 'active';
                            
                            if (empty($name) || $quantity <= 0) {
                                continue; // Skip invalid entries
                            }
                            
                            // Update existing equipment
                            $stmt = $conn->prepare("
                                UPDATE gym_equipment 
                                SET name = :name, quantity = :quantity, category = :category, status = :status, updated_at = NOW()
                                WHERE equipment_id = :equipment_id AND gym_id = :gym_id
                            ");
                            
                            $stmt->execute([
                                ':name' => $name,
                                ':quantity' => $quantity,
                                ':category' => $category,
                                ':status' => $status,
                                ':equipment_id' => $equipment_id,
                                ':gym_id' => $gym_id
                            ]);
                        }
                    }
                    
                    // Add new equipment
                    if (isset($_POST['new_equipment']) && is_array($_POST['new_equipment'])) {
                        $stmt = $conn->prepare("
                            INSERT INTO gym_equipment 
                            (gym_id, name, quantity, category, status, created_at, updated_at)
                            VALUES (:gym_id, :name, :quantity, :category, :status, NOW(), NOW())
                        ");
                        
                        foreach ($_POST['new_equipment'] as $new_equipment) {
                            $name = trim($new_equipment['name'] ?? '');
                            $quantity = intval($new_equipment['quantity'] ?? 0);
                            $category = trim($new_equipment['category'] ?? 'General');
                            $status = $new_equipment['status'] ?? 'active';
                            
                            if (empty($name) || $quantity <= 0) {
                                continue; // Skip invalid entries
                            }
                            
                            $stmt->execute([
                                ':gym_id' => $gym_id,
                                ':name' => $name,
                                ':quantity' => $quantity,
                                ':category' => $category,
                                ':status' => $status
                            ]);
                        }
                    }
                    
                    // Delete equipment
                    if (isset($_POST['delete_equipment']) && is_array($_POST['delete_equipment'])) {
                        $stmt = $conn->prepare("
                            DELETE FROM gym_equipment 
                            WHERE equipment_id = :equipment_id AND gym_id = :gym_id
                        ");
                        
                        foreach ($_POST['delete_equipment'] as $equipment_id) {
                            $stmt->execute([
                                ':equipment_id' => $equipment_id,
                                ':gym_id' => $gym_id
                            ]);
                        }
                    }
                    
                    $tab_saved = true;
                    break;

                case 'membership_plans':
                    // Process membership plan updates
                    if (isset($_POST['plans']) && is_array($_POST['plans'])) {
                        foreach ($_POST['plans'] as $plan_id => $plan_data) {
                            // Validate data
                            $plan_name = trim($plan_data['plan_name'] ?? '');
                            $tier = $plan_data['tier'] ?? 'Tier 1';
                            $duration = $plan_data['duration'] ?? 'Monthly';
                            $plan_type = trim($plan_data['plan_type'] ?? '');
                            $price = floatval($plan_data['price'] ?? 0);
                            $inclusions = trim($plan_data['inclusions'] ?? '');
                            $best_for = trim($plan_data['best_for'] ?? '');
                            
                            if (empty($plan_name) || empty($plan_type) || $price <= 0) {
                                continue; // Skip invalid entries
                            }
                            
                            // Update existing plan
                            $stmt = $conn->prepare("
                                UPDATE gym_membership_plans 
                                SET plan_name = :plan_name, tier = :tier, duration = :duration, 
                                    plan_type = :plan_type, price = :price, inclusions = :inclusions, 
                                    best_for = :best_for
                                WHERE plan_id = :plan_id AND gym_id = :gym_id
                            ");
                            
                            $stmt->execute([
                                ':plan_name' => $plan_name,
                                ':tier' => $tier,
                                ':duration' => $duration,
                                ':plan_type' => $plan_type,
                                ':price' => $price,
                                ':inclusions' => $inclusions,
                                ':best_for' => $best_for,
                                ':plan_id' => $plan_id,
                                ':gym_id' => $gym_id
                            ]);
                        }
                    }
                    
                    // Add new plans
                    if (isset($_POST['new_plans']) && is_array($_POST['new_plans'])) {
                        $stmt = $conn->prepare("
                            INSERT INTO gym_membership_plans 
                            (gym_id, plan_name, tier, duration, plan_type, price, inclusions, best_for)
                            VALUES (:gym_id, :plan_name, :tier, :duration, :plan_type, :price, :inclusions, :best_for)
                        ");
                        
                        foreach ($_POST['new_plans'] as $new_plan) {
                            $plan_name = trim($new_plan['plan_name'] ?? '');
                            $tier = $new_plan['tier'] ?? 'Tier 1';
                            $duration = $new_plan['duration'] ?? 'Monthly';
                            $plan_type = trim($new_plan['plan_type'] ?? '');
                            $price = floatval($new_plan['price'] ?? 0);
                            $inclusions = trim($new_plan['inclusions'] ?? '');
                            $best_for = trim($new_plan['best_for'] ?? '');
                            
                            if (empty($plan_name) || empty($plan_type) || $price <= 0) {
                                continue; // Skip invalid entries
                            }
                            
                            $stmt->execute([
                                ':gym_id' => $gym_id,
                                ':plan_name' => $plan_name,
                                ':tier' => $tier,
                                ':duration' => $duration,
                                ':plan_type' => $plan_type,
                                ':price' => $price,
                                ':inclusions' => $inclusions,
                                ':best_for' => $best_for
                            ]);
                        }
                    }
                    
                    // Delete plans
                    if (isset($_POST['delete_plans']) && is_array($_POST['delete_plans'])) {
                        // First check if any plans are in use
                        $stmt = $conn->prepare("
                            SELECT plan_id FROM user_memberships 
                            WHERE plan_id IN (" . implode(',', array_fill(0, count($_POST['delete_plans']), '?')) . ")
                            LIMIT 1
                        ");
                        
                        $stmt->execute($_POST['delete_plans']);
                        
                        if ($stmt->rowCount() > 0) {
                            throw new Exception("Cannot delete plans that are currently in use by members.");
                        }
                        
                        // If no plans in use, proceed with deletion
                        $stmt = $conn->prepare("
                            DELETE FROM gym_membership_plans 
                            WHERE plan_id = :plan_id AND gym_id = :gym_id
                        ");
                        
                        foreach ($_POST['delete_plans'] as $plan_id) {
                            $stmt->execute([
                                ':plan_id' => $plan_id,
                                ':gym_id' => $gym_id
                            ]);
                        }
                    }
                    
                    $tab_saved = true;
                    break;
            }
            
            // Commit transaction
            $conn->commit();
            
            // Set success message
            $_SESSION['success'] = ucfirst(str_replace('_', ' ', $tab_to_save)) . " updated successfully!";
            
            // Refresh gym data
            $stmt = $conn->prepare("SELECT * FROM gyms WHERE gym_id = :gym_id");
            $stmt->execute([':gym_id' => $gym_id]);
            $gym = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            // Rollback transaction
            $conn->rollBack();
            
            // Set error message
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    }
}

// Get active tab from URL hash or default to basic_info
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'basic_info';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Gym Details - <?= htmlspecialchars($gym['name']) ?></title>
    <!-- Preload critical assets -->
    <link rel="preload" href="../assets/css/tailwind.min.css" as="style">
    <link rel="preload" href="../assets/js/alpine.min.js" as="script">
    <link rel="stylesheet" href="../assets/css/tailwind.min.css">
    <script src="../assets/js/alpine.min.js" defer></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    
    <!-- Add CSP for security -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; img-src 'self' data: blob:; font-src 'self' https://cdnjs.cloudflare.com; connect-src 'self';">
    
    <style>
        /* Critical CSS for faster rendering */
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .loading {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0,0,0,0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen">
    <!-- Loading indicator -->
    <!-- <div id="loadingIndicator" class="loading hidden">
        <div class="bg-white dark:bg-gray-800 p-5 rounded-lg shadow-lg">
            <div class="flex items-center space-x-3">
                <svg class="animate-spin h-8 w-8 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span class="text-gray-700 dark:text-gray-200 text-lg font-medium">Processing...</span>
            </div>
        </div>
    </div> -->

    <div class="container mx-auto px-4 py-8">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg overflow-hidden mt-10">
            <div class="p-6 bg-gradient-to-r from-blue-500 to-blue-600 text-white">
                <h1 class="text-2xl font-bold">Edit Gym Details: <?= htmlspecialchars($gym['name']) ?></h1>
                <p class="text-blue-100">Update your gym's information, operating hours, amenities, and more.</p>
            </div>
            
            <!-- Display messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert">
                    <p><?= $_SESSION['success'] ?></p>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                    <p><?= $_SESSION['error'] ?></p>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>
            
            <!-- Tabs -->
            <div class="border-b border-gray-200 dark:border-gray-700">
                <nav class="flex overflow-x-auto py-4 px-6 space-x-4" aria-label="Tabs">
                    <button data-tab="basic_info" class="tab-button px-3 py-2 text-sm font-medium rounded-md whitespace-nowrap <?= $active_tab == 'basic_info' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' ?> <?= !$permissions['basic_info'] ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= !$permissions['basic_info'] ? 'disabled' : '' ?>>
                        <i class="fas fa-info-circle mr-2"></i>Basic Info
                    </button>
                    <button data-tab="operating_hours" class="tab-button px-3 py-2 text-sm font-medium rounded-md whitespace-nowrap <?= $active_tab == 'operating_hours' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' ?> <?= !$permissions['operating_hours'] ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= !$permissions['operating_hours'] ? 'disabled' : '' ?>>
                        <i class="far fa-clock mr-2"></i>Operating Hours
                    </button>
                    <button data-tab="amenities" class="tab-button px-3 py-2 text-sm font-medium rounded-md whitespace-nowrap <?= $active_tab == 'amenities' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' ?> <?= !$permissions['amenities'] ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= !$permissions['amenities'] ? 'disabled' : '' ?>>
                        <i class="fas fa-spa mr-2"></i>Amenities
                    </button>
                    <button data-tab="images" class="tab-button px-3 py-2 text-sm font-medium rounded-md whitespace-nowrap <?= $active_tab == 'images' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' ?> <?= !$permissions['images'] ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= !$permissions['images'] ? 'disabled' : '' ?>>
                        <i class="far fa-images mr-2"></i>Images
                    </button>
                    <button data-tab="equipment" class="tab-button px-3 py-2 text-sm font-medium rounded-md whitespace-nowrap <?= $active_tab == 'equipment' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' ?> <?= !$permissions['equipment'] ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= !$permissions['equipment'] ? 'disabled' : '' ?>>
                        <i class="fas fa-dumbbell mr-2"></i>Equipment
                    </button>
                    <button data-tab="membership_plans" class="tab-button px-3 py-2 text-sm font-medium rounded-md whitespace-nowrap <?= $active_tab == 'membership_plans' ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' ?> <?= !$permissions['membership_plans'] ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= !$permissions['membership_plans'] ? 'disabled' : '' ?>>
                        <i class="fas fa-id-card mr-2"></i>Membership Plans
                    </button>
                </nav>
            </div>
            
            <!-- Tab Contents -->
            <div class="p-6">
                <!-- Basic Info Tab -->
                <div id="basic_info" class="tab-content <?= $active_tab == 'basic_info' ? 'active' : '' ?>">
                    <h2 class="text-xl font-semibold mb-4 text-gray-800 dark:text-white">Basic Information</h2>
                    
                    <form method="POST" enctype="multipart/form-data" class="space-y-6" onsubmit="showLoading()">
                        <input type="hidden" name="save_tab" value="basic_info">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="gym_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Gym Name</label>
                                <input type="text" name="gym_name" id="gym_name" value="<?= htmlspecialchars($gym['name']) ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            </div>
                            
                            <div>
                                <label for="capacity" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Capacity</label>
                                <input type="number" name="capacity" id="capacity" value="<?= htmlspecialchars($gym['capacity']) ?>" required min="1" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            </div>
                            
                            <div>
                                <label for="address" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Address</label>
                                <input type="text" name="address" id="address" value="<?= htmlspecialchars($gym['address']) ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            </div>
                            
                            <div>
                                <label for="city" class="block text-sm font-medium text-gray-700 dark:text-gray-300">City</label>
                                <input type="text" name="city" id="city" value="<?= htmlspecialchars($gym['city']) ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            </div>
                            
                            <div>
                                <label for="state" class="block text-sm font-medium text-gray-700 dark:text-gray-300">State</label>
                                <input type="text" name="state" id="state" value="<?= htmlspecialchars($gym['state']) ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            </div>
                            
                            <div>
                                <label for="zip_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">ZIP Code</label>
                                <input type="text" name="zip_code" id="zip_code" value="<?= htmlspecialchars($gym['zip_code']) ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                                <input type="tel" name="phone" id="phone" value="<?= htmlspecialchars($gym['phone']) ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                                <input type="email" name="email" id="email" value="<?= htmlspecialchars($gym['email']) ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                            </div>
                            
                            <?php if ($permissions['gym_cut_percentage']): ?>
                            <div>
                                <label for="gym_cut_percentage" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Gym Cut Percentage</label>
                                <input type="number" name="gym_cut_percentage" id="gym_cut_percentage" value="<?= htmlspecialchars($gym['gym_cut_percentage']) ?>" min="0" max="100" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Percentage of revenue that goes to the gym (0-100)</p>
                            </div>
                            <?php endif; ?>
                            
                            <div>
                                <label for="is_featured" class="flex items-center">
                                    <input type="checkbox" name="is_featured" id="is_featured" <?= $gym['is_featured'] ? 'checked' : '' ?> class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600">
                                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Feature this gym on the homepage</span>
                                </label>
                            </div>
                        </div>
                        
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                            <textarea name="description" id="description" rows="4" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"><?= htmlspecialchars($gym['description']) ?></textarea>
                        </div>
                        
                        <div>
                            <label for="additional_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Additional Notes</label>
                            <textarea name="additional_notes" id="additional_notes" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"><?= htmlspecialchars($gym['additional_notes']) ?></textarea>
                        </div>
                        
                        <div>
                            <label for="cover_photo" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cover Photo</label>
                            
                            <?php if ($gym['cover_photo']): ?>
                                <div class="mt-2 mb-4">
                                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">Current Cover Photo:</p>
                                    <img src="./uploads/gym_images/<?= htmlspecialchars($gym['cover_photo']) ?>" alt="Cover Photo" class="w-full max-w-md h-auto rounded-lg shadow-md">
                                </div>
                            <?php endif; ?>
                            
                            <div class="mt-1 flex items-center">
                                <input type="file" name="cover_photo" id="cover_photo" accept="image/*" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:text-gray-400 dark:file:bg-gray-700 dark:file:text-gray-200">
                            </div>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Recommended size: 1200x400 pixels. Max file size: 5MB.</p>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                                <i class="fas fa-save mr-2"></i> Save Basic Info
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Operating Hours Tab -->
                <div id="operating_hours" class="tab-content <?= $active_tab == 'operating_hours' ? 'active' : '' ?>">
                    <h2 class="text-xl font-semibold mb-4 text-gray-800 dark:text-white">Operating Hours</h2>
                    
                    <form method="POST" class="space-y-6" onsubmit="showLoading()">
                        <input type="hidden" name="save_tab" value="operating_hours">
                        
                        <div class="mb-4">
                            <label for="use_daily_hours" class="flex items-center">
                                <input type="checkbox" name="use_daily_hours" id="use_daily_hours" <?= $has_daily_schedule ? 'checked' : '' ?> class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600">
                                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Use same hours for all days</span>
                            </label>
                        </div>
                        
                        <!-- Daily hours section -->
                        <div id="daily-hours-section" class="<?= $has_daily_schedule ? '' : 'hidden' ?> bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                            <h3 class="text-lg font-medium text-gray-800 dark:text-white mb-3">Daily Hours</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="daily_morning_open" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Morning Open Time</label>
                                    <input type="time" name="operating_hours[daily][morning_open_time]" id="daily_morning_open" value="<?= $has_daily_schedule ? $hours_by_day['Daily']['morning_open_time'] : '06:00:00' ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                </div>
                                
                                <div>
                                    <label for="daily_morning_close" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Morning Close Time</label>
                                    <input type="time" name="operating_hours[daily][morning_close_time]" id="daily_morning_close" value="<?= $has_daily_schedule ? $hours_by_day['Daily']['morning_close_time'] : '12:00:00' ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                </div>
                                
                                <div>
                                    <label for="daily_evening_open" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Evening Open Time</label>
                                    <input type="time" name="operating_hours[daily][evening_open_time]" id="daily_evening_open" value="<?= $has_daily_schedule ? $hours_by_day['Daily']['evening_open_time'] : '16:00:00' ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                </div>
                                
                                <div>
                                    <label for="daily_evening_close" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Evening Close Time</label>
                                    <input type="time" name="operating_hours[daily][evening_close_time]" id="daily_evening_close" value="<?= $has_daily_schedule ? $hours_by_day['Daily']['evening_close_time'] : '22:00:00' ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Individual days section -->
                        <div id="individual-days-section" class="<?= $has_daily_schedule ? 'hidden' : '' ?> space-y-6">
                            <?php
                            $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                            foreach ($days as $day):
                                $day_lower = strtolower($day);
                                $day_hours = $hours_by_day[$day] ?? null;
                                $is_closed = $day_hours && 
                                            $day_hours['morning_open_time'] == '00:00:00' && 
                                            $day_hours['morning_close_time'] == '00:00:00' && 
                                            $day_hours['evening_open_time'] == '00:00:00' && 
                                            $day_hours['evening_close_time'] == '00:00:00';
                            ?>
                                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                                    <div class="flex justify-between items-center mb-3">
                                        <h3 class="text-lg font-medium text-gray-800 dark:text-white"><?= $day ?></h3>
                                        
                                        <label class="flex items-center">
                                            <input type="checkbox" name="closed_days[]" value="<?= $day_lower ?>" <?= $is_closed ? 'checked' : '' ?> class="day-closed-checkbox rounded border-gray-300 text-red-600 shadow-sm focus:border-red-500 focus:ring-red-500 dark:bg-gray-700 dark:border-gray-600">
                                            <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Closed</span>
                                        </label>
                                    </div>
                                    
                                    <div class="day-hours-inputs <?= $is_closed ? 'hidden' : '' ?> grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label for="<?= $day_lower ?>_morning_open" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Morning Open Time</label>
                                            <input type="time" name="operating_hours[<?= $day_lower ?>][morning_open_time]" id="<?= $day_lower ?>_morning_open" value="<?= $day_hours ? $day_hours['morning_open_time'] : '06:00:00' ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                        </div>
                                        
                                        <div>
                                            <label for="<?= $day_lower ?>_morning_close" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Morning Close Time</label>
                                            <input type="time" name="operating_hours[<?= $day_lower ?>][morning_close_time]" id="<?= $day_lower ?>_morning_close" value="<?= $day_hours ? $day_hours['morning_close_time'] : '12:00:00' ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                        </div>
                                        
                                        <div>
                                            <label for="<?= $day_lower ?>_evening_open" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Evening Open Time</label>
                                            <input type="time" name="operating_hours[<?= $day_lower ?>][evening_open_time]" id="<?= $day_lower ?>_evening_open" value="<?= $day_hours ? $day_hours['evening_open_time'] : '16:00:00' ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                        </div>
                                        
                                        <div>
                                            <label for="<?= $day_lower ?>_evening_close" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Evening Close Time</label>
                                            <input type="time" name="operating_hours[<?= $day_lower ?>][evening_close_time]" id="<?= $day_lower ?>_evening_close" value="<?= $day_hours ? $day_hours['evening_close_time'] : '22:00:00' ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                                <i class="fas fa-save mr-2"></i> Save Operating Hours
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Amenities Tab -->
                <div id="amenities" class="tab-content <?= $active_tab == 'amenities' ? 'active' : '' ?>">
                    <h2 class="text-xl font-semibold mb-4 text-gray-800 dark:text-white">Amenities</h2>
                    
                    <form method="POST" class="space-y-6" onsubmit="showLoading()">
                        <input type="hidden" name="save_tab" value="amenities">
                        
                        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                        <h3 class="text-lg font-medium text-gray-800 dark:text-white mb-3">Select Amenities</h3>
                            
                            <?php
                            // Fetch all available amenities
                            $amenities_query = "SELECT * FROM amenities ORDER BY category, name";
                            $amenities_stmt = $conn->prepare($amenities_query);
                            $amenities_stmt->execute();
                            $all_amenities = $amenities_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            // Group amenities by category
                            $amenities_by_category = [];
                            foreach ($all_amenities as $amenity) {
                                $category = $amenity['category'];
                                if (!isset($amenities_by_category[$category])) {
                                    $amenities_by_category[$category] = [];
                                }
                                $amenities_by_category[$category][] = $amenity;
                            }
                            
                            // Get gym's current amenities
                            $gym_amenities = json_decode($gym['amenities'] ?? '[]', true);
                            ?>
                            
                            <div class="space-y-4">
                                <?php foreach ($amenities_by_category as $category => $amenities): ?>
                                    <div>
                                        <h4 class="text-md font-medium text-gray-700 dark:text-gray-300 mb-2"><?= htmlspecialchars($category) ?></h4>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                                            <?php foreach ($amenities as $amenity): ?>
                                                <label class="flex items-start">
                                                    <input type="checkbox" name="amenities[]" value="<?= $amenity['id'] ?>" <?= in_array($amenity['id'], $gym_amenities) ? 'checked' : '' ?> class="mt-1 rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600">
                                                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">
                                                        <?= htmlspecialchars($amenity['name']) ?>
                                                        <?php if (!empty($amenity['description'])): ?>
                                                            <span class="block text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($amenity['description']) ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                                <i class="fas fa-save mr-2"></i> Save Amenities
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Images Tab -->
                <div id="images" class="tab-content <?= $active_tab == 'images' ? 'active' : '' ?>">
                    <h2 class="text-xl font-semibold mb-4 text-gray-800 dark:text-white">Gym Images</h2>
                    
                    <form method="POST" enctype="multipart/form-data" class="space-y-6" onsubmit="showLoading()">
                        <input type="hidden" name="save_tab" value="images">
                        
                        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                            <h3 class="text-lg font-medium text-gray-800 dark:text-white mb-3">Current Images</h3>
                            
                            <?php if (empty($gym_images)): ?>
                                <p class="text-gray-500 dark:text-gray-400">No images uploaded yet.</p>
                            <?php else: ?>
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                    <?php foreach ($gym_images as $image): ?>
                                        <div class="relative group">
                                            <img src="./uploads/gym_images/<?= htmlspecialchars($image['image_path']) ?>" alt="Gym Image" class="w-full h-48 object-cover rounded-lg shadow-md">
                                            
                                            <div class="absolute inset-0 bg-black bg-opacity-50 opacity-0 group-hover:opacity-100 transition-opacity duration-200 rounded-lg flex items-center justify-center">
                                                <label class="inline-flex items-center px-3 py-1.5 bg-white text-gray-800 rounded-md shadow-sm text-sm font-medium cursor-pointer mr-2">
                                                    <input type="checkbox" name="delete_images[]" value="<?= $image['image_id'] ?>" class="sr-only">
                                                    <i class="fas fa-trash-alt text-red-500 mr-1"></i> Delete
                                                </label>
                                                
                                                <?php if ($image['is_cover']): ?>
                                                    <span class="inline-flex items-center px-3 py-1.5 bg-yellow-500 text-white rounded-md shadow-sm text-sm font-medium">
                                                        <i class="fas fa-star mr-1"></i> Cover
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                            <h3 class="text-lg font-medium text-gray-800 dark:text-white mb-3">Upload New Images</h3>
                            
                            <div class="space-y-4">
                                <div>
                                    <label for="gym_images" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Select Images</label>
                                    <input type="file" name="gym_images[]" id="gym_images" multiple accept="image/*" class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:text-gray-400 dark:file:bg-gray-700 dark:file:text-gray-200">
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">You can select multiple images. Max file size: 5MB each.</p>
                                </div>
                                
                                <div>
                                    <label for="cover_image" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Set as Cover Image</label>
                                    <select name="cover_image" id="cover_image" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                        <option value="">None (Keep current cover)</option>
                                        <option value="0">First uploaded image</option>
                                        <option value="1">Second uploaded image</option>
                                        <option value="2">Third uploaded image</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                                <i class="fas fa-save mr-2"></i> Save Images
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Equipment Tab -->
                <div id="equipment" class="tab-content <?= $active_tab == 'equipment' ? 'active' : '' ?>">
                    <h2 class="text-xl font-semibold mb-4 text-gray-800 dark:text-white">Gym Equipment</h2>
                    
                    <form method="POST" class="space-y-6" id="equipmentForm" onsubmit="showLoading()">
                        <input type="hidden" name="save_tab" value="equipment">
                        
                        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                            <div class="flex justify-between items-center mb-3">
                                <h3 class="text-lg font-medium text-gray-800 dark:text-white">Current Equipment</h3>
                                <button type="button" id="addEquipmentBtn" class="inline-flex items-center px-3 py-1.5 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 dark:focus:ring-offset-gray-800">
                                    <i class="fas fa-plus mr-1"></i> Add Equipment
                                </button>
                            </div>
                            
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                                    <thead class="bg-gray-100 dark:bg-gray-800">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Name</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Category</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Quantity</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-700 divide-y divide-gray-200 dark:divide-gray-600" id="equipmentTableBody">
                                        <?php if (empty($gym_equipment)): ?>
                                            <tr>
                                                <td colspan="5" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No equipment added yet.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($gym_equipment as $equipment): ?>
                                                <tr class="equipment-row">
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <input type="text" name="equipment[<?= $equipment['equipment_id'] ?>][name]" value="<?= htmlspecialchars($equipment['name']) ?>" required class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <select name="equipment[<?= $equipment['equipment_id'] ?>][category]" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                                            <option value="Cardio" <?= $equipment['category'] == 'Cardio' ? 'selected' : '' ?>>Cardio</option>
                                                            <option value="Strength" <?= $equipment['category'] == 'Strength' ? 'selected' : '' ?>>Strength</option>
                                                            <option value="Free Weights" <?= $equipment['category'] == 'Free Weights' ? 'selected' : '' ?>>Free Weights</option>
                                                            <option value="Machines" <?= $equipment['category'] == 'Machines' ? 'selected' : '' ?>>Machines</option>
                                                            <option value="Accessories" <?= $equipment['category'] == 'Accessories' ? 'selected' : '' ?>>Accessories</option>
                                                            <option value="General" <?= $equipment['category'] == 'General' ? 'selected' : '' ?>>General</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <input type="number" name="equipment[<?= $equipment['equipment_id'] ?>][quantity]" value="<?= htmlspecialchars($equipment['quantity']) ?>" min="1" required class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <select name="equipment[<?= $equipment['equipment_id'] ?>][status]" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                                            <option value="active" <?= $equipment['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                                                            <option value="maintenance" <?= $equipment['status'] == 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                                            <option value="inactive" <?= $equipment['status'] == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                                        </select>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <button type="button" class="delete-equipment-btn inline-flex items-center px-2 py-1 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 dark:focus:ring-offset-gray-800" data-id="<?= $equipment['equipment_id'] ?>">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                        <input type="hidden" name="equipment_ids[]" value="<?= $equipment['equipment_id'] ?>">
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div id="newEquipmentContainer" class="mt-4 space-y-4"></div>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                                <i class="fas fa-save mr-2"></i> Save Equipment
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Membership Plans Tab -->
                <div id="membership_plans" class="tab-content <?= $active_tab == 'membership_plans' ? 'active' : '' ?>">
                    <h2 class="text-xl font-semibold mb-4 text-gray-800 dark:text-white">Membership Plans</h2>
                    
                    <form method="POST" class="space-y-6" id="plansForm" onsubmit="showLoading()">
                        <input type="hidden" name="save_tab" value="membership_plans">
                        
                        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                            <div class="flex justify-between items-center mb-3">
                                <h3 class="text-lg font-medium text-gray-800 dark:text-white">Current Plans</h3>
                                <button type="button" id="addPlanBtn" class="inline-flex items-center px-3 py-1.5 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 dark:focus:ring-offset-gray-800">
                                    <i class="fas fa-plus mr-1"></i> Add Plan
                                </button>
                            </div>
                            
                            <div class="space-y-6" id="plansContainer">
                                <?php if (empty($membership_plans)): ?>
                                    <p class="text-gray-500 dark:text-gray-400">No membership plans added yet.</p>
                                <?php else: ?>
                                    <?php foreach ($membership_plans as $plan): ?>
                                        <div class="plan-card bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 relative">
                                            <button type="button" class="delete-plan-btn absolute top-2 right-2 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300" data-id="<?= $plan['plan_id'] ?>">
                                                <i class="fas fa-times-circle text-xl"></i>
                                            </button>
                                            <input type="hidden" name="plan_ids[]" value="<?= $plan['plan_id'] ?>">
                                            
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Plan Name</label>
                                                    <input type="text" name="plans[<?= $plan['plan_id'] ?>][plan_name]" value="<?= htmlspecialchars($plan['plan_name']) ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                                </div>
                                                
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Plan Type</label>
                                                    <input type="text" name="plans[<?= $plan['plan_id'] ?>][plan_type]" value="<?= htmlspecialchars($plan['plan_type']) ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                                </div>
                                                
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tier</label>
                                                    <select name="plans[<?= $plan['plan_id'] ?>][tier]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                                        <option value="Tier 1" <?= $plan['tier'] == 'Tier 1' ? 'selected' : '' ?>>Tier 1</option>
                                                        <option value="Tier 2" <?= $plan['tier'] == 'Tier 2' ? 'selected' : '' ?>>Tier 2</option>
                                                        <option value="Tier 3" <?= $plan['tier'] == 'Tier 3' ? 'selected' : '' ?>>Tier 3</option>
                                                    </select>
                                                </div>
                                                
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Duration</label>
                                                    <select name="plans[<?= $plan['plan_id'] ?>][duration]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                                        <option value="Daily" <?= $plan['duration'] == 'Daily' ? 'selected' : '' ?>>Daily</option>
                                                        <option value="Weekly" <?= $plan['duration'] == 'Weekly' ? 'selected' : '' ?>>Weekly</option>
                                                        <option value="Monthly" <?= $plan['duration'] == 'Monthly' ? 'selected' : '' ?>>Monthly</option>
                                                        <option value="Quarterly" <?= $plan['duration'] == 'Quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                                        <option value="Half Yearly" <?= $plan['duration'] == 'Half Yearly' ? 'selected' : '' ?>>Half Yearly</option>
                                                        <option value="Yearly" <?= $plan['duration'] == 'Yearly' ? 'selected' : '' ?>>Yearly</option>
                                                    </select>
                                                </div>
                                                
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Price (₹)</label>
                                                    <input type="number" name="plans[<?= $plan['plan_id'] ?>][price]" value="<?= htmlspecialchars($plan['price']) ?>" min="0" step="0.01" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                                </div>
                                                
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Best For</label>
                                                    <input type="text" name="plans[<?= $plan['plan_id'] ?>][best_for]" value="<?= htmlspecialchars($plan['best_for']) ?>" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                                </div>
                                                
                                                <div class="md:col-span-2">
                                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Inclusions</label>
                                                    <textarea name="plans[<?= $plan['plan_id'] ?>][inclusions]" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"><?= htmlspecialchars($plan['inclusions']) ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <div id="newPlansContainer" class="mt-6 space-y-6"></div>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 dark:focus:ring-offset-gray-800">
                                <i class="fas fa-save mr-2"></i> Save Membership Plans
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Use DOMContentLoaded for faster page load
        document.addEventListener('DOMContentLoaded', function() {
            // Cache DOM elements for better performance
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            const useDailyHoursCheckbox = document.getElementById('use_daily_hours');
            const dailyHoursSection = document.getElementById('daily-hours-section');
            const individualDaysSection = document.getElementById('individual-days-section');
            const dayClosedCheckboxes = document.querySelectorAll('.day-closed-checkbox');
            const addEquipmentBtn = document.getElementById('addEquipmentBtn');
            const newEquipmentContainer = document.getElementById('newEquipmentContainer');
            const addPlanBtn = document.getElementById('addPlanBtn');
            const newPlansContainer = document.getElementById('newPlansContainer');
            const loadingIndicator = document.getElementById('loadingIndicator');
            
            // Get active tab from URL hash or default
            let activeTabId = window.location.hash.substring(1) || 'basic_info';
            
            // Function to set active tab
            function setActiveTab(tabId) {
                // Hide all tabs
                tabContents.forEach(tab => {
                    tab.classList.remove('active');
                });
                
                // Remove active class from all buttons
                tabButtons.forEach(btn => {
                    btn.classList.remove('bg-blue-100', 'text-blue-700', 'dark:bg-blue-900', 'dark:text-blue-200');
                    btn.classList.add('text-gray-500', 'hover:text-gray-700', 'dark:text-gray-400', 'dark:hover:text-gray-300');
                });
                
                // Show active tab
                const activeTab = document.getElementById(tabId);
                if (activeTab) {
                    activeTab.classList.add('active');
                }
                
                // Set active button
                const activeBtn = document.querySelector(`.tab-button[data-tab="${tabId}"]`);
                if (activeBtn) {
                    activeBtn.classList.remove('text-gray-500', 'hover:text-gray-700', 'dark:text-gray-400', 'dark:hover:text-gray-300');
                    activeBtn.classList.add('bg-blue-100', 'text-blue-700', 'dark:bg-blue-900', 'dark:text-blue-200');
                }
                
                // Update URL hash
                window.location.hash = tabId;
                activeTabId = tabId;
            }

            // Initialize active tab
            setActiveTab(activeTabId);

            // Tab button click handlers
            tabButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    setActiveTab(tabId);
                });
            });

            // Operating hours toggle
            if (useDailyHoursCheckbox) {
                useDailyHoursCheckbox.addEventListener('change', function() {
                    dailyHoursSection.classList.toggle('hidden', !this.checked);
                    individualDaysSection.classList.toggle('hidden', this.checked);
                });
            }
            
            // Day closed checkboxes
            dayClosedCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const hoursInputs = this.closest('.bg-gray-50, .dark\\:bg-gray-700').querySelector('.day-hours-inputs');
                    hoursInputs.classList.toggle('hidden', this.checked);
                });
            });
            
            // Add equipment functionality
            let equipmentCounter = 0;
            
            if (addEquipmentBtn) {
                addEquipmentBtn.addEventListener('click', function() {
                    equipmentCounter++;
                    
                    const newEquipmentHtml = `
                        <div class="new-equipment bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 relative">
                            <button type="button" class="remove-new-equipment absolute top-2 right-2 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                                                <i class="fas fa-times-circle text-xl"></i>
                            </button>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Equipment Name</label>
                                    <input type="text" name="new_equipment[${equipmentCounter}][name]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                                    <select name="new_equipment[${equipmentCounter}][category]" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                        <option value="Cardio">Cardio</option>
                                        <option value="Strength">Strength</option>
                                        <option value="Free Weights">Free Weights</option>
                                        <option value="Machines">Machines</option>
                                        <option value="Accessories">Accessories</option>
                                        <option value="General">General</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Quantity</label>
                                    <input type="number" name="new_equipment[${equipmentCounter}][quantity]" value="1" min="1" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                                    <select name="new_equipment[${equipmentCounter}][status]" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                        <option value="active">Active</option>
                                        <option value="maintenance">Maintenance</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    newEquipmentContainer.insertAdjacentHTML('beforeend', newEquipmentHtml);
                    
                    // Add event listener to the remove button
                    const removeBtn = newEquipmentContainer.querySelector(`.new-equipment:last-child .remove-new-equipment`);
                    removeBtn.addEventListener('click', function() {
                        this.closest('.new-equipment').remove();
                    });
                });
            }
            
            // Delete equipment buttons
            document.querySelectorAll('.delete-equipment-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const equipmentId = this.getAttribute('data-id');
                    const row = this.closest('.equipment-row');
                    
                    if (confirm('Are you sure you want to delete this equipment?')) {
                        // Add a hidden input to mark this equipment for deletion
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'delete_equipment[]';
                        hiddenInput.value = equipmentId;
                        document.getElementById('equipmentForm').appendChild(hiddenInput);
                        
                        // Hide the row
                        row.style.display = 'none';
                    }
                });
            });
            
            // Add plan functionality
            let planCounter = 0;
            
            if (addPlanBtn) {
                addPlanBtn.addEventListener('click', function() {
                    planCounter++;
                    
                    const newPlanHtml = `
                        <div class="new-plan bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 relative">
                            <button type="button" class="remove-new-plan absolute top-2 right-2 text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                                <i class="fas fa-times-circle text-xl"></i>
                            </button>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Plan Name</label>
                                    <input type="text" name="new_plans[${planCounter}][plan_name]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Plan Type</label>
                                    <input type="text" name="new_plans[${planCounter}][plan_type]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="e.g. Basic, Premium, etc.">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tier</label>
                                    <select name="new_plans[${planCounter}][tier]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                        <option value="Tier 1">Tier 1</option>
                                        <option value="Tier 2">Tier 2</option>
                                        <option value="Tier 3">Tier 3</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Duration</label>
                                    <select name="new_plans[${planCounter}][duration]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                        <option value="Daily">Daily</option>
                                        <option value="Weekly">Weekly</option>
                                        <option value="Monthly">Monthly</option>
                                        <option value="Quarterly">Quarterly</option>
                                        <option value="Half Yearly">Half Yearly</option>
                                        <option value="Yearly">Yearly</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Price (₹)</label>
                                    <input type="number" name="new_plans[${planCounter}][price]" min="0" step="0.01" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Best For</label>
                                    <input type="text" name="new_plans[${planCounter}][best_for]" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="e.g. Beginners, Athletes, etc.">
                                </div>
                                
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Inclusions</label>
                                    <textarea name="new_plans[${planCounter}][inclusions]" rows="3" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white" placeholder="List of features included in this plan"></textarea>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    newPlansContainer.insertAdjacentHTML('beforeend', newPlanHtml);
                    
                    // Add event listener to the remove button
                    const removeBtn = newPlansContainer.querySelector(`.new-plan:last-child .remove-new-plan`);
                    removeBtn.addEventListener('click', function() {
                        this.closest('.new-plan').remove();
                    });
                });
            }
            
            // Delete plan buttons
            document.querySelectorAll('.delete-plan-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const planId = this.getAttribute('data-id');
                    const card = this.closest('.plan-card');
                    
                    if (confirm('Are you sure you want to delete this membership plan?')) {
                        // Add a hidden input to mark this plan for deletion
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'delete_plans[]';
                        hiddenInput.value = planId;
                        document.getElementById('plansForm').appendChild(hiddenInput);
                        
                        // Hide the card
                        card.style.display = 'none';
                    }
                });
            });
            
          
            
            // Optimize image uploads with client-side validation
            const imageInputs = document.querySelectorAll('input[type="file"]');
            imageInputs.forEach(input => {
                input.addEventListener('change', function() {
                    const files = this.files;
                    const maxSize = 5 * 1024 * 1024; // 5MB
                    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    
                    for (let i = 0; i < files.length; i++) {
                        const file = files[i];
                        
                        // Check file size
                        if (file.size > maxSize) {
                            alert(`File "${file.name}" exceeds the 5MB size limit.`);
                            this.value = ''; // Clear the input
                            return;
                        }
                        
                        // Check file type
                        if (!allowedTypes.includes(file.type)) {
                            alert(`File "${file.name}" is not an allowed image type. Please use JPG, PNG, GIF, or WebP.`);
                            this.value = ''; // Clear the input
                            return;
                        }
                    }
                });
            });
            
            // Function to show loading indicator
window.showLoading = function() {
    const loadingIndicator = document.getElementById('loadingIndicator');
    if (loadingIndicator) {
        loadingIndicator.classList.remove('hidden');
    }
};

// Function to hide loading indicator
window.hideLoading = function() {
    const loadingIndicator = document.getElementById('loadingIndicator');
    if (loadingIndicator) {
        loadingIndicator.classList.add('hidden');
    }
};

// Hide loading indicator when page loads
window.addEventListener('load', function() {
    hideLoading();
});

// For AJAX submissions
function submitFormWithAjax(formElement) {
    showLoading();
    
    fetch(formElement.action, {
        method: formElement.method,
        body: new FormData(formElement)
    })
    .then(response => response.json())
    .then(data => {
        // Handle success
        hideLoading();
    })
    .catch(error => {
        // Handle error
        hideLoading();
    });
}

            // Implement lazy loading for images
            if ('IntersectionObserver' in window) {
                const imgObserver = new IntersectionObserver((entries, observer) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            const src = img.getAttribute('data-src');
                            if (src) {
                                img.src = src;
                                img.removeAttribute('data-src');
                            }
                            observer.unobserve(img);
                        }
                    });
                });
                
                document.querySelectorAll('img[data-src]').forEach(img => {
                    imgObserver.observe(img);
                });
            }
            
            // Add form validation
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const requiredInputs = form.querySelectorAll('input[required], select[required], textarea[required]');
                    let isValid = true;
                    
                    requiredInputs.forEach(input => {
                        if (!input.value.trim()) {
                            isValid = false;
                            input.classList.add('border-red-500');
                            
                            // Add error message if not already present
                            const errorMsg = input.nextElementSibling;
                            if (!errorMsg || !errorMsg.classList.contains('error-message')) {
                                const msg = document.createElement('p');
                                msg.textContent = 'This field is required';
                                msg.classList.add('error-message', 'text-red-500', 'text-sm', 'mt-1');
                                input.parentNode.insertBefore(msg, input.nextSibling);
                            }
                        } else {
                            input.classList.remove('border-red-500');
                            
                            // Remove error message if present
                            const errorMsg = input.nextElementSibling;
                            if (errorMsg && errorMsg.classList.contains('error-message')) {
                                errorMsg.remove();
                            }
                        }
                    });
                    
                    if (!isValid) {
                        e.preventDefault();
                        alert('Please fill in all required fields.');
                    } else {
                        // showLoading();
                    }
                });
            });
        });
    </script>
</body>
</html>
