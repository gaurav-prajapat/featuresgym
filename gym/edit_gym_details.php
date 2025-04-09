<?php
ini_set('display_errors', 0);
error_reporting(0);

// Log errors instead
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

ob_start();
require '../config/database.php';
include '../includes/navbar.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Check database connection
if (!$conn) {
    error_log("Failed to establish database connection");
    die("Database connection failed. Please try again later.");
}

// Check if owner is logged in
if (!isset($_SESSION['owner_id'])) {
    header("Location: login.html");
    exit;
}

$owner_id = $_SESSION['owner_id'];

// Fetch gym details for this owner
$query = "SELECT * FROM gyms WHERE owner_id = :owner_id";
$stmt = $conn->prepare($query);
$stmt->execute([':owner_id' => $owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
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

// Debug logging for form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    error_log('Form submitted. POST data: ' . print_r($_POST, true));
}

// Check if specific tab is being saved
$tab_to_save = isset($_POST['save_tab']) ? $_POST['save_tab'] : '';
$tab_saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($tab_to_save)) {
    error_log('Processing tab: ' . $tab_to_save);
    error_log('Form data: ' . print_r($_POST, true));

    // Validate that user has permission to edit this tab
    if (!isset($permissions[$tab_to_save]) || !$permissions[$tab_to_save]) {
        $error_message = "You don't have permission to edit the $tab_to_save section.";
        error_log("Permission denied for tab: $tab_to_save");
    } else {
        try {
            // Start transaction
            $conn->beginTransaction();
            error_log("Started transaction for tab: $tab_to_save");

            // Process the specific tab's data
            switch ($tab_to_save) {
                case 'basic_info':
                    // Process basic info fields
                    error_log('Processing basic_info tab');
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

                    // Log values for debugging
                    error_log("Updating gym with name: $gym_name, address: $address");

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
                    error_log("Basic info update result: " . ($result ? 'success' : 'failed'));


                    if (!$result) {
                        error_log("Database error: " . print_r($stmt_update->errorInfo(), true));
                        throw new Exception("Failed to update gym information: " . $stmt_update->errorInfo()[2]);
                    }

                    $affected_rows = $stmt_update->rowCount();
                    error_log("Rows affected by update: $affected_rows");

                    // Handle cover photo update
                    if (isset($_FILES['cover_photo']) && $_FILES['cover_photo']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = 'uploads/gym_images/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }

                        $cover_photo_name = uniqid() . '_' . basename($_FILES['cover_photo']['name']);
                        $cover_photo_path = $upload_dir . $cover_photo_name;

                        if (move_uploaded_file($_FILES['cover_photo']['tmp_name'], $cover_photo_path)) {
                            // Delete old cover photo if exists
                            if ($gym['cover_photo'] && file_exists('uploads/gym_images/' . $gym['cover_photo'])) {
                                unlink('uploads/gym_images/' . $gym['cover_photo']);
                            }

                            // Update cover_photo in database
                            $update_cover = "UPDATE gyms SET cover_photo = :cover_photo WHERE gym_id = :gym_id";
                            $stmt_cover = $conn->prepare($update_cover);
                            $result = $stmt_cover->execute([
                                ':cover_photo' => $cover_photo_name,
                                ':gym_id' => $gym_id
                            ]);

                            if (!$result) {
                                error_log("Failed to update cover photo: " . print_r($stmt_cover->errorInfo(), true));
                            }
                        }
                    }

                    $tab_saved = true;
                    break;

                case 'operating_hours':
                    // Delete existing hours
                    $delete_hours = "DELETE FROM gym_operating_hours WHERE gym_id = :gym_id";
                    $stmt_delete = $conn->prepare($delete_hours);
                    $result = $stmt_delete->execute([':gym_id' => $gym_id]);

                    if (!$result) {
                        error_log("Failed to delete existing hours: " . print_r($stmt_delete->errorInfo(), true));
                        throw new Exception("Failed to update operating hours");
                    }

                    error_log("Deleted existing operating hours");

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
                            $result = $stmt_hours->execute([
                                ':gym_id' => $gym_id,
                                ':day' => $day,
                                ':morning_open' => $daily['morning_open_time'] ?? '00:00:00',
                                ':morning_close' => $daily['morning_close_time'] ?? '00:00:00',
                                ':evening_open' => $daily['evening_open_time'] ?? '00:00:00',
                                ':evening_close' => $daily['evening_close_time'] ?? '00:00:00'
                            ]);

                            if (!$result) {
                                error_log("Failed to insert hours for $day: " . print_r($stmt_hours->errorInfo(), true));
                                throw new Exception("Failed to update operating hours");
                            }
                        }

                        // Also add a "Daily" entry for convenience
                        $insert_hours = "INSERT INTO gym_operating_hours 
                            (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time) 
                            VALUES (:gym_id, 'Daily', :morning_open, :morning_close, :evening_open, :evening_close)";

                        $stmt_hours = $conn->prepare($insert_hours);
                        $result = $stmt_hours->execute([
                            ':gym_id' => $gym_id,
                            ':morning_open' => $daily['morning_open_time'] ?? '00:00:00',
                            ':morning_close' => $daily['morning_close_time'] ?? '00:00:00',
                            ':evening_open' => $daily['evening_open_time'] ?? '00:00:00',
                            ':evening_close' => $daily['evening_close_time'] ?? '00:00:00'
                        ]);

                        if (!$result) {
                            error_log("Failed to insert Daily hours: " . print_r($stmt_hours->errorInfo(), true));
                            throw new Exception("Failed to update operating hours");
                        }

                        error_log("Inserted daily hours for all days");
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
                                    $result = $stmt_hours->execute([
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
                                    $result = $stmt_hours->execute([
                                        ':gym_id' => $gym_id,
                                        ':day' => $day,
                                        ':morning_open' => $day_hours['morning_open_time'] ?? '00:00:00',
                                        ':morning_close' => $day_hours['morning_close_time'] ?? '00:00:00',
                                        ':evening_open' => $day_hours['evening_open_time'] ?? '00:00:00',
                                        ':evening_close' => $day_hours['evening_close_time'] ?? '00:00:00'
                                    ]);
                                }

                                if (!$result) {
                                    error_log("Failed to insert hours for $day: " . print_r($stmt_hours->errorInfo(), true));
                                    throw new Exception("Failed to update operating hours for $day");
                                }
                            }
                        }

                        error_log("Inserted individual operating hours for each day");
                    }

                    $tab_saved = true;
                    break;

                    case 'amenities':
                        error_log('Processing amenities tab');
                        
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
                        error_log('Amenities to save: ' . $amenitiesJson);
                    
                        try {
                            $update_query = "UPDATE gyms SET amenities = :amenities WHERE gym_id = :gym_id";
                            $stmt_update = $conn->prepare($update_query);
                            $result = $stmt_update->execute([
                                ':amenities' => $amenitiesJson,
                                ':gym_id' => $gym_id
                            ]);
                            error_log('Update result: ' . ($result ? 'success' : 'failed'));
                    
                            $tab_saved = true;
                        } catch (Exception $e) {
                            error_log('Error updating amenities: ' . $e->getMessage());
                        }
                        break;
                    


                case 'images':
                    // Handle additional gym images
                    if (isset($_FILES['gym_images']) && !empty($_FILES['gym_images']['name'][0])) {
                        $upload_dir = 'uploads/gym_images/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }

                        $total_files = count($_FILES['gym_images']['name']);
                        error_log("Processing $total_files new gym images");

                        for ($i = 0; $i < $total_files; $i++) {
                            if ($_FILES['gym_images']['error'][$i] === UPLOAD_ERR_OK) {
                                $image_name = uniqid() . '_' . basename($_FILES['gym_images']['name'][$i]);
                                $image_path = $upload_dir . $image_name;

                                if (move_uploaded_file($_FILES['gym_images']['tmp_name'][$i], $image_path)) {
                                    $is_cover = isset($_POST['is_cover']) && $_POST['is_cover'] == $i ? 1 : 0;

                                    $stmt = $conn->prepare("
                                    INSERT INTO gym_images 
                                    (gym_id, image_path, is_cover, created_at)
                                    VALUES (:gym_id, :image_path, :is_cover, NOW())
                                ");

                                    $result = $stmt->execute([
                                        ':gym_id' => $gym_id,
                                        ':image_path' => $image_name,
                                        ':is_cover' => $is_cover
                                    ]);

                                    if (!$result) {
                                        error_log("Failed to insert image record: " . print_r($stmt->errorInfo(), true));
                                        throw new Exception("Failed to save image information");
                                    }

                                    // If this is a cover image, update the gym's cover_photo
                                    if ($is_cover) {
                                        $update_cover = "UPDATE gyms SET cover_photo = :cover_photo WHERE gym_id = :gym_id";
                                        $stmt_cover = $conn->prepare($update_cover);
                                        $result = $stmt_cover->execute([
                                            ':cover_photo' => $image_name,
                                            ':gym_id' => $gym_id
                                        ]);

                                        if (!$result) {
                                            error_log("Failed to update cover photo: " . print_r($stmt_cover->errorInfo(), true));
                                            throw new Exception("Failed to set cover photo");
                                        }
                                    }

                                    error_log("Successfully uploaded and saved image: $image_name");
                                } else {
                                    error_log("Failed to move uploaded file to destination");
                                    throw new Exception("Failed to save uploaded image");
                                }
                            } else {
                                error_log("File upload error code: " . $_FILES['gym_images']['error'][$i]);
                            }
                        }
                    }

                    // Handle deleted images
                    if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
                        foreach ($_POST['delete_images'] as $image_id) {
                            // Get image path before deleting
                            $stmt = $conn->prepare("SELECT image_path FROM gym_images WHERE id = :id AND gym_id = :gym_id");
                            $stmt->execute([':id' => $image_id, ':gym_id' => $gym_id]);
                            $image = $stmt->fetch(PDO::FETCH_ASSOC);

                            if ($image) {
                                // Delete file from server
                                $file_path = 'uploads/gym_images/' . $image['image_path'];
                                if (file_exists($file_path)) {
                                    unlink($file_path);
                                    error_log("Deleted image file: " . $image['image_path']);
                                }

                                // Delete from database
                                $stmt = $conn->prepare("DELETE FROM gym_images WHERE id = :id AND gym_id = :gym_id");
                                $result = $stmt->execute([':id' => $image_id, ':gym_id' => $gym_id]);

                                if (!$result) {
                                    error_log("Failed to delete image record: " . print_r($stmt->errorInfo(), true));
                                    throw new Exception("Failed to delete image");
                                }

                                error_log("Deleted image record ID: $image_id");
                            }
                        }
                    }

                    // Handle set as cover image
                    if (isset($_POST['cover_photo']) && !empty($_POST['cover_photo'])) {
                        $update_cover = "UPDATE gyms SET cover_photo = :cover_photo WHERE gym_id = :gym_id";
                        $stmt_cover = $conn->prepare($update_cover);
                        $result = $stmt_cover->execute([
                            ':cover_photo' => $_POST['cover_photo'],
                            ':gym_id' => $gym_id
                        ]);

                        if (!$result) {
                            error_log("Failed to update cover photo: " . print_r($stmt_cover->errorInfo(), true));
                            throw new Exception("Failed to set cover photo");
                        }

                        // Update is_cover flag in gym_images
                        $stmt = $conn->prepare("UPDATE gym_images SET is_cover = 0 WHERE gym_id = :gym_id");
                        $stmt->execute([':gym_id' => $gym_id]);

                        $stmt = $conn->prepare("UPDATE gym_images SET is_cover = 1 WHERE gym_id = :gym_id AND image_path = :image_path");
                        $result = $stmt->execute([
                            ':gym_id' => $gym_id,
                            ':image_path' => $_POST['cover_photo']
                        ]);

                        if (!$result) {
                            error_log("Failed to update is_cover flag: " . print_r($stmt->errorInfo(), true));
                        }

                        error_log("Set cover photo to: " . $_POST['cover_photo']);
                    }

                    $tab_saved = true;
                    break;
                    case 'equipment':
                        // Check if we're deleting equipment
                        if (isset($_POST['delete_equipment']) && is_array($_POST['delete_equipment'])) {
                            foreach ($_POST['delete_equipment'] as $equipment_id) {
                                // Get image path before deleting
                                $stmt = $conn->prepare("SELECT image FROM gym_equipment WHERE equipment_id = :equipment_id AND gym_id = :gym_id");
                                $stmt->execute([':equipment_id' => $equipment_id, ':gym_id' => $gym_id]);
                                $equipment = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($equipment && !empty($equipment['image'])) {
                                    // Delete file from server
                                    $file_path = '../uploads/equipment_images/' . $equipment['image'];
                                    if (file_exists($file_path)) {
                                        unlink($file_path);
                                    }
                                }
                                
                                // Delete from database
                                $stmt = $conn->prepare("DELETE FROM gym_equipment WHERE equipment_id = :equipment_id AND gym_id = :gym_id");
                                $stmt->execute([':equipment_id' => $equipment_id, ':gym_id' => $gym_id]);
                            }
                        }
                        
                        // Check if we're updating existing equipment
                        if (isset($_POST['action']) && $_POST['action'] === 'update_equipment' && isset($_POST['equipment_id'])) {
                            $equipment_id = $_POST['equipment_id'];
                            $equipment_data = $_POST['equipment'];
                            
                            $stmt = $conn->prepare("
                                UPDATE gym_equipment 
                                SET name = :name, category = :category, quantity = :quantity, description = :description 
                                WHERE equipment_id = :equipment_id AND gym_id = :gym_id
                            ");
                            
                            $stmt->execute([
                                ':name' => $equipment_data['name'],
                                ':category' => $equipment_data['category'] ?? '',
                                ':quantity' => $equipment_data['quantity'],
                                ':description' => $equipment_data['description'] ?? '',
                                ':equipment_id' => $equipment_id,
                                ':gym_id' => $gym_id
                            ]);
                            
                            // Handle equipment image update
                            if (isset($_FILES['equipment_image']) && $_FILES['equipment_image']['error'] === UPLOAD_ERR_OK) {
                                $upload_dir = '../uploads/equipment_images/';
                                if (!is_dir($upload_dir)) {
                                    mkdir($upload_dir, 0755, true);
                                }
                                
                                // Get old image to delete
                                $stmt = $conn->prepare("SELECT image FROM gym_equipment WHERE equipment_id = :equipment_id AND gym_id = :gym_id");
                                $stmt->execute([':equipment_id' => $equipment_id, ':gym_id' => $gym_id]);
                                $old_equipment = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                $image_name = uniqid() . '_' . basename($_FILES['equipment_image']['name']);
                                $image_path = $upload_dir . $image_name;
                                
                                if (move_uploaded_file($_FILES['equipment_image']['tmp_name'], $image_path)) {
                                    // Delete old image if exists
                                    if ($old_equipment && !empty($old_equipment['image'])) {
                                        $old_file_path = $upload_dir . $old_equipment['image'];
                                        if (file_exists($old_file_path)) {
                                            unlink($old_file_path);
                                        }
                                    }
                                    
                                    // Update image in database
                                    $stmt = $conn->prepare("UPDATE gym_equipment SET image = :image WHERE equipment_id = :equipment_id AND gym_id = :gym_id");
                                    $stmt->execute([
                                        ':image' => $image_name,
                                        ':equipment_id' => $equipment_id,
                                        ':gym_id' => $gym_id
                                    ]);
                                }
                            }
                        }
                        
                        // Add new equipment
                        if (isset($_POST['new_equipment']) && is_array($_POST['new_equipment'])) {
                            foreach ($_POST['new_equipment'] as $index => $equipment_data) {
                                if (!empty($equipment_data['name'])) {
                                    $stmt = $conn->prepare("
                                        INSERT INTO gym_equipment 
                                        (gym_id, name, category, quantity, description, created_at) 
                                        VALUES (:gym_id, :name, :category, :quantity, :description, NOW())
                                    ");
                                    
                                    $stmt->execute([
                                        ':gym_id' => $gym_id,
                                        ':name' => $equipment_data['name'],
                                        ':category' => $equipment_data['category'] ?? '',
                                        ':quantity' => $equipment_data['quantity'],
                                        ':description' => $equipment_data['description'] ?? ''
                                    ]);
                                    
                                    $equipment_id = $conn->lastInsertId();
                                    
                                    // Handle equipment image
                                    if (isset($_FILES['new_equipment_images']['name'][$index]) && 
                                        $_FILES['new_equipment_images']['error'][$index] === UPLOAD_ERR_OK) {
                                        
                                        $upload_dir = '../uploads/equipment_images/';
                                        if (!is_dir($upload_dir)) {
                                            mkdir($upload_dir, 0755, true);
                                        }
                                        
                                        $image_name = uniqid() . '_' . basename($_FILES['new_equipment_images']['name'][$index]);
                                        $image_path = $upload_dir . $image_name;
                                        
                                        if (move_uploaded_file($_FILES['new_equipment_images']['tmp_name'][$index], $image_path)) {
                                            $stmt = $conn->prepare("
                                                UPDATE gym_equipment 
                                                SET image = :image 
                                                WHERE equipment_id = :equipment_id AND gym_id = :gym_id
                                            ");
                                            
                                            $stmt->execute([
                                                ':image' => $image_name,
                                                ':equipment_id' => $equipment_id,
                                                ':gym_id' => $gym_id
                                            ]);
                                        }
                                    }
                                }
                            }
                        }
                        
                        $tab_saved = true;
                        break;
                    
                case 'membership_plans':
                    // Check if we're deleting plans
                    if (isset($_POST['delete_plans']) && is_array($_POST['delete_plans'])) {
                        foreach ($_POST['delete_plans'] as $plan_id) {
                            $stmt = $conn->prepare("DELETE FROM gym_membership_plans WHERE plan_id = :plan_id AND gym_id = :gym_id");
                            $stmt->execute([':plan_id' => $plan_id, ':gym_id' => $gym_id]);
                        }
                    }

                    // Check if we're updating existing plan
                    if (isset($_POST['action']) && $_POST['action'] === 'update_plan' && isset($_POST['plan_id'])) {
                        $plan_id = $_POST['plan_id'];
                        $plan_data = $_POST['membership_plan'];

                        $stmt = $conn->prepare("
            UPDATE gym_membership_plans 
            SET plan_name = :plan_name, tier = :tier, duration = :duration, 
                price = :price, best_for = :best_for, inclusions = :inclusions 
            WHERE plan_id = :plan_id AND gym_id = :gym_id
        ");

                        $stmt->execute([
                            ':plan_name' => $plan_data['plan_name'],
                            ':tier' => $plan_data['tier'],
                            ':duration' => $plan_data['duration'],
                            ':price' => $plan_data['price'],
                            ':best_for' => $plan_data['best_for'] ?? '',
                            ':inclusions' => $plan_data['inclusions'] ?? '',
                            ':plan_id' => $plan_id,
                            ':gym_id' => $gym_id
                        ]);
                    }

                    // Add new plans
                    if (isset($_POST['new_membership_plans']) && is_array($_POST['new_membership_plans'])) {
                        foreach ($_POST['new_membership_plans'] as $plan_data) {
                            if (!empty($plan_data['plan_name'])) {
                                $stmt = $conn->prepare("
                    INSERT INTO gym_membership_plans 
                    (gym_id, plan_name, tier, duration, price, best_for, inclusions, created_at) 
                    VALUES (:gym_id, :plan_name, :tier, :duration, :price, :best_for, :inclusions, NOW())
                ");

                                $stmt->execute([
                                    ':gym_id' => $gym_id,
                                    ':plan_name' => $plan_data['plan_name'],
                                    ':tier' => $plan_data['tier'],
                                    ':duration' => $plan_data['duration'],
                                    ':price' => $plan_data['price'],
                                    ':best_for' => $plan_data['best_for'] ?? '',
                                    ':inclusions' => $plan_data['inclusions'] ?? ''
                                ]);
                            }
                        }
                    }

                    $tab_saved = true;
                    break;


            }

            // Commit transaction
            $conn->commit();
            error_log("Transaction committed successfully for tab: $tab_to_save");

            if ($tab_saved) {
                $success_message = ucfirst(str_replace('_', ' ', $tab_to_save)) . " updated successfully!";

                // Refresh gym data
                $stmt = $conn->prepare("SELECT * FROM gyms WHERE gym_id = :gym_id");
                $stmt->execute([':gym_id' => $gym_id]);
                $gym = $stmt->fetch(PDO::FETCH_ASSOC);

                // Refresh related data based on which tab was saved
                switch ($tab_to_save) {
                    case 'images':
                        $stmt_images = $conn->prepare($query_images);
                        $stmt_images->execute([':gym_id' => $gym_id]);
                        $gym_images = $stmt_images->fetchAll(PDO::FETCH_ASSOC);
                        break;

                    case 'equipment':
                        $stmt_equipment = $conn->prepare($query_equipment);
                        $stmt_equipment->execute([':gym_id' => $gym_id]);
                        $gym_equipment = $stmt_equipment->fetchAll(PDO::FETCH_ASSOC);
                        break;

                    case 'membership_plans':
                        $stmt_plans = $conn->prepare($query_plans);
                        $stmt_plans->execute([':gym_id' => $gym_id]);
                        $gym_plans = $stmt_plans->fetchAll(PDO::FETCH_ASSOC);
                        break;

                    case 'operating_hours':
                        $stmt_hours = $conn->prepare($query_hours);
                        $stmt_hours->execute([':gym_id' => $gym_id]);
                        $operating_hours = $stmt_hours->fetchAll(PDO::FETCH_ASSOC);

                        // Reorganize operating hours by day
                        $hours_by_day = [];
                        foreach ($operating_hours as $hour) {
                            $hours_by_day[$hour['day']] = $hour;
                        }

                        // Check if there's a "Daily" schedule
                        $has_daily_schedule = isset($hours_by_day['Daily']);
                        break;
                }
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollBack();
            $error_message = "Error: " . $e->getMessage();
            error_log("Transaction rolled back for tab $tab_to_save: " . $e->getMessage());
        }
    }
}

// Prepare data for the form
$current_amenities = json_decode($gym['amenities'] ?? '[]', true);
if (!is_array($current_amenities)) {
    $current_amenities = [];
}

// Common amenities list
$common_amenities = [
    'Locker Rooms',
    'Showers',
    'Parking',
    'Personal Training',
    'WiFi',
    'Cardio Equipment',
    'Strength Equipment',
    'Group Classes',
    'Pool',
    'Sauna',
    'Steam Room',
    'Spa',
    'Towel Service',
    'Juice Bar',
    'Childcare'
];

// Equipment categories
$equipment_categories = [
    'Cardio',
    'Strength',
    'Free Weights',
    'Functional Training',
    'Stretching',
    'Yoga/Pilates',
    'Boxing',
    'Other'
];

// Membership tiers
$membership_tiers = ['Basic', 'Standard', 'Premium', 'Elite', 'VIP'];

// Membership durations
$membership_durations = ['Daily', 'Weekly', 'Monthly', 'Quarterly', 'Half Yearly', 'Yearly'];

// Days of the week
$days_of_week = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

// Check if any day is closed
$closed_days = [];
foreach ($days_of_week as $day) {
    $day_title = ucfirst($day);
    if (isset($hours_by_day[$day_title])) {
        $day_hours = $hours_by_day[$day_title];
        if (
            $day_hours['morning_open_time'] === '00:00:00' &&
            $day_hours['morning_close_time'] === '00:00:00' &&
            $day_hours['evening_open_time'] === '00:00:00' &&
            $day_hours['evening_close_time'] === '00:00:00'
        ) {
            $closed_days[] = $day;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Gym Details</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .required-field::after {
            content: "*";
            color: #ef4444;
            margin-left: 4px;
        }
    </style>
</head>

<body class="bg-black dark:bg-gray-900 text-gray-800 dark:text-white">
    <div class="container mx-auto py-8 px-4 sm:px-6 lg:px-8 pt-24">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Edit Gym Details</h1>
            <a href="dashboard.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
            </a>
        </div>

        <!-- Permission Status Indicator -->
        <div class="bg-gray-200 dark:bg-gray-800 p-4 rounded-lg mb-6">
            <h3 class="text-lg font-medium mb-2">Your Edit Permissions</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2">
                <?php
                $permissionLabels = [
                    'basic_info' => 'Basic Information',
                    'operating_hours' => 'Operating Hours',
                    'amenities' => 'Amenities',
                    'images' => 'Images',
                    'equipment' => 'Equipment',
                    'membership_plans' => 'Membership Plans',
                    'gym_cut_percentage' => 'Gym Cut Percentage'
                ];

                foreach ($permissionLabels as $key => $label):
                    $hasPermission = $permissions[$key] ?? false;
                    ?>
                    <div class="flex items-center">
                        <span
                            class="inline-block w-3 h-3 rounded-full mr-2 <?= $hasPermission ? 'bg-green-500' : 'bg-red-500' ?>"></span>
                        <span class="text-sm"><?= $label ?>: <?= $hasPermission ? 'Editable' : 'Locked' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="text-sm mt-2">If you need additional permissions, please contact the administrator.</p>
        </div>

        <!-- Tabs Navigation -->
        <div class="mb-6 overflow-x-auto">
            <div class="flex space-x-2 border-b border-gray-300 dark:border-gray-700 pb-2">
                <button type="button" class="tab-btn px-4 py-2 font-medium rounded-t-lg focus:outline-none"
                    data-tab="basic-info">
                    Basic Info
                </button>
                <button type="button" class="tab-btn px-4 py-2 font-medium rounded-t-lg focus:outline-none"
                    data-tab="operating-hours">
                    Operating Hours
                </button>
                <button type="button" class="tab-btn px-4 py-2 font-medium rounded-t-lg focus:outline-none"
                    data-tab="amenities">
                    Amenities
                </button>
                <button type="button" class="tab-btn px-4 py-2 font-medium rounded-t-lg focus:outline-none"
                    data-tab="images">
                    Images
                </button>
                <button type="button" class="tab-btn px-4 py-2 font-medium rounded-t-lg focus:outline-none"
                    data-tab="equipment">
                    Equipment
                </button>
                <button type="button" class="tab-btn px-4 py-2 font-medium rounded-t-lg focus:outline-none"
                    data-tab="membership-plans">
                    Membership Plans
                </button>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <!-- Basic Info Tab -->
        <div id="basic-info" class="tab-content">
            <form method="POST" enctype="multipart/form-data" class="tab-form">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                    <h2 class="text-xl font-semibold mb-4">Basic Information</h2>

                    <?php if (!$permissions['basic_info']): ?>
                        <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                            <p>You don't have permission to edit basic information. Please contact the administrator.</p>
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label for="gym_name"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Gym
                                Name</label>
                            <input type="text" id="gym_name" name="gym_name"
                                value="<?= htmlspecialchars($gym['name']) ?>" <?= !$permissions['basic_info'] ? 'readonly' : '' ?>
                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="address"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Address</label>
                            <input type="text" id="address" name="address"
                                value="<?= htmlspecialchars($gym['address']) ?>" <?= !$permissions['basic_info'] ? 'readonly' : '' ?>
                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="city"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">City</label>
                            <input type="text" id="city" name="city" value="<?= htmlspecialchars($gym['city']) ?>"
                                <?= !$permissions['basic_info'] ? 'readonly' : '' ?>
                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="state"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">State</label>
                            <input type="text" id="state" name="state" value="<?= htmlspecialchars($gym['state']) ?>"
                                <?= !$permissions['basic_info'] ? 'readonly' : '' ?>
                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="zip_code"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Zip
                                Code</label>
                            <input type="text" id="zip_code" name="zip_code"
                                value="<?= htmlspecialchars($gym['zip_code']) ?>" <?= !$permissions['basic_info'] ? 'readonly' : '' ?>
                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="phone"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Phone</label>
                            <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($gym['phone']) ?>"
                                <?= !$permissions['basic_info'] ? 'readonly' : '' ?>
                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="email"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Email</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($gym['email']) ?>"
                                <?= !$permissions['basic_info'] ? 'readonly' : '' ?>
                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="capacity"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Capacity</label>
                            <input type="number" id="capacity" name="capacity"
                                value="<?= htmlspecialchars($gym['capacity']) ?>" <?= !$permissions['basic_info'] ? 'readonly' : '' ?>
                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="gym_cut_percentage"
                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gym Cut
                                Percentage</label>
                            <input type="number" id="gym_cut_percentage" name="gym_cut_percentage"
                                value="<?= htmlspecialchars($gym['gym_cut_percentage'] ?? 70) ?>" min="0" max="100"
                                <?= !$permissions['gym_cut_percentage'] ? 'readonly' : '' ?>
                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Percentage of membership fees that
                                goes to the gym</p>
                            <?php if (!$permissions['gym_cut_percentage']): ?>
                                <p class="mt-1 text-sm text-red-500">Only administrators can change this value.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label for="description"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1 required-field">Description</label>
                        <textarea id="description" name="description" rows="4" <?= !$permissions['basic_info'] ? 'readonly' : '' ?>
                            class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            required><?= htmlspecialchars($gym['description']) ?></textarea>
                    </div>

                    <div class="mb-6">
                        <label for="additional_notes"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Additional
                            Notes</label>
                        <textarea id="additional_notes" name="additional_notes" rows="3" <?= !$permissions['basic_info'] ? 'readonly' : '' ?>
                            class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"><?= htmlspecialchars($gym['additional_notes'] ?? '') ?></textarea>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Internal notes visible only to gym
                            staff</p>
                    </div>

                    <div class="mb-6">
                        <label for="cover_photo"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cover Photo</label>
                        <?php if (!empty($gym['cover_photo'])): ?>
                            <div class="mb-2">
                                <img src="uploads/gym_images/<?= htmlspecialchars($gym['cover_photo']) ?>" alt="Cover Photo"
                                    class="h-40 object-cover rounded-lg">
                            </div>
                        <?php endif; ?>
                        <input type="file" id="cover_photo" name="cover_photo" accept="image/*"
                            <?= !$permissions['basic_info'] ? 'disabled' : '' ?>
                            class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="flex items-center mb-6">
                        <input type="checkbox" id="is_featured" name="is_featured" value="1" <?= $gym['is_featured'] ? 'checked' : '' ?> <?= !$permissions['basic_info'] ? 'disabled' : '' ?>
                            class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                        <label for="is_featured" class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                            Feature this gym (will be shown on homepage)
                        </label>
                    </div>

                    <!-- Add a save button for this tab only -->
                    <div class="flex justify-end">
                        <?php if ($permissions['basic_info']): ?>
                            <input type="hidden" name="save_tab" value="basic_info">
                            <button type="submit"
                                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                <i class="fas fa-save mr-2"></i> Save Basic Info
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- Operating Hours Tab -->
        <div id="operating-hours" class="tab-content">
            <form method="POST" enctype="multipart/form-data" class="tab-form">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                    <h2 class="text-xl font-semibold mb-4">Operating Hours</h2>

                    <?php if (!$permissions['operating_hours']): ?>
                        <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                            <p>You don't have permission to edit operating hours. Please contact the administrator.</p>
                        </div>
                    <?php endif; ?>

                    <!-- Operating hours fields with readonly attribute based on permissions -->
                    <div class="mb-6">
                        <div class="flex items-center mb-4">
                            <input type="checkbox" id="use_daily_hours" name="use_daily_hours" <?= $has_daily_schedule ? 'checked' : '' ?> <?= !$permissions['operating_hours'] ? 'disabled' : '' ?>
                                class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <label for="use_daily_hours"
                                class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                Use same hours for all days
                            </label>
                        </div>

                        <!-- Daily hours (shown when "use same hours" is checked) -->
                        <div id="daily-hours-section" class="<?= $has_daily_schedule ? '' : 'hidden' ?> mb-6">
                            <h3 class="text-lg font-medium mb-3">Daily Hours</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Morning
                                        Open</label>
                                    <input type="time" name="operating_hours[daily][morning_open_time]"
                                        value="<?= isset($hours_by_day['Daily']) ? substr($hours_by_day['Daily']['morning_open_time'], 0, 5) : '09:00' ?>"
                                        <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Morning
                                        Close</label>
                                    <input type="time" name="operating_hours[daily][morning_close_time]"
                                        value="<?= isset($hours_by_day['Daily']) ? substr($hours_by_day['Daily']['morning_close_time'], 0, 5) : '13:00' ?>"
                                        <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Evening
                                        Open</label>
                                    <input type="time" name="operating_hours[daily][evening_open_time]"
                                        value="<?= isset($hours_by_day['Daily']) ? substr($hours_by_day['Daily']['evening_open_time'], 0, 5) : '16:00' ?>"
                                        <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Evening
                                        Close</label>
                                    <input type="time" name="operating_hours[daily][evening_close_time]"
                                        value="<?= isset($hours_by_day['Daily']) ? substr($hours_by_day['Daily']['evening_close_time'], 0, 5) : '22:00' ?>"
                                        <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <!-- Individual day hours (shown when "use same hours" is unchecked) -->
                        <div id="individual-hours-section" class="<?= $has_daily_schedule ? 'hidden' : '' ?>">
                            <?php foreach ($days_of_week as $day): ?>
                                <?php
                                $day_title = ucfirst($day);
                                $is_closed = in_array($day, $closed_days);
                                $day_hours = isset($hours_by_day[$day_title]) ? $hours_by_day[$day_title] : null;
                                ?>
                                <div class="mb-6 border-b border-gray-200 dark:border-gray-700 pb-4 last:border-0">
                                    <div class="flex justify-between items-center mb-3">
                                        <h3 class="text-lg font-medium"><?= $day_title ?></h3>
                                        <div class="flex items-center">
                                            <input type="checkbox" id="<?= $day ?>_closed" name="closed_days[]"
                                                value="<?= $day ?>" <?= $is_closed ? 'checked' : '' ?>
                                                <?= !$permissions['operating_hours'] ? 'disabled' : '' ?>
                                                class="w-4 h-4 text-red-600 border-gray-300 rounded focus:ring-red-500">
                                            <label for="<?= $day ?>_closed"
                                                class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                                Closed
                                            </label>
                                        </div>
                                    </div>

                                    <div class="day-hours-inputs <?= $is_closed ? 'hidden' : '' ?>">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Morning
                                                    Open</label>
                                                <input type="time" name="operating_hours[<?= $day ?>][morning_open_time]"
                                                    value="<?= $day_hours ? substr($day_hours['morning_open_time'], 0, 5) : '09:00' ?>"
                                                    <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            </div>
                                            <div>
                                                <label
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Morning
                                                    Close</label>
                                                <input type="time" name="operating_hours[<?= $day ?>][morning_close_time]"
                                                    value="<?= $day_hours ? substr($day_hours['morning_close_time'], 0, 5) : '13:00' ?>"
                                                    <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            </div>
                                            <div>
                                                <label
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Evening
                                                    Open</label>
                                                <input type="time" name="operating_hours[<?= $day ?>][evening_open_time]"
                                                    value="<?= $day_hours ? substr($day_hours['evening_open_time'], 0, 5) : '16:00' ?>"
                                                    <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            </div>
                                            <div>
                                                <label
                                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Evening
                                                    Close</label>
                                                <input type="time" name="operating_hours[<?= $day ?>][evening_close_time]"
                                                    value="<?= $day_hours ? substr($day_hours['evening_close_time'], 0, 5) : '22:00' ?>"
                                                    <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Add a save button for this tab only -->
                    <div class="flex justify-end">
                        <?php if ($permissions['operating_hours']): ?>
                            <input type="hidden" name="save_tab" value="operating_hours">
                            <button type="submit"
                                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                <i class="fas fa-save mr-2"></i> Save Operating Hours
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- Amenities Tab -->
<div id="amenities" class="tab-content">
    <form method="POST" enctype="multipart/form-data" class="tab-form">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">Amenities</h2>

            <?php if (!$permissions['amenities']): ?>
                <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                    <p>You don't have permission to edit amenities. Please contact the administrator.</p>
                </div>
            <?php endif; ?>

            <?php
            // Fetch all amenities from database to display proper names
            $amenitiesQuery = "SELECT id, name, category FROM amenities WHERE availability = 1 ORDER BY category, name";
            $amenitiesStmt = $conn->prepare($amenitiesQuery);
            $amenitiesStmt->execute();
            $allAmenities = $amenitiesStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Create a mapping of amenity IDs to names
            $amenityMap = [];
            foreach ($allAmenities as $amenity) {
                $amenityMap[$amenity['id']] = $amenity['name'];
            }
            
            // Group amenities by category
            $amenitiesByCategory = [];
            foreach ($allAmenities as $amenity) {
                $category = $amenity['category'] ?: 'Other';
                if (!isset($amenitiesByCategory[$category])) {
                    $amenitiesByCategory[$category] = [];
                }
                $amenitiesByCategory[$category][] = $amenity;
            }
            
            // Parse current amenities from JSON to array of IDs
            $current_amenities = json_decode($gym['amenities'] ?? '[]', true);
            if (!is_array($current_amenities)) {
                $current_amenities = [];
            }
            ?>

            <!-- Amenities fields with readonly attribute based on permissions -->
            <div class="mb-6">
                <h3 class="text-lg font-medium mb-3">Select Amenities</h3>
                
                <?php foreach ($amenitiesByCategory as $category => $categoryAmenities): ?>
                    <div class="mb-4">
                        <h4 class="font-medium text-gray-700 dark:text-gray-300 mb-2"><?= htmlspecialchars($category) ?></h4>
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                            <?php foreach ($categoryAmenities as $amenity): ?>
                                <div class="flex items-center">
                                    <input type="checkbox" 
                                           id="amenity_<?= $amenity['id'] ?>" 
                                           name="amenities[]" 
                                           value="<?= $amenity['id'] ?>" 
                                           <?= in_array($amenity['id'], $current_amenities) ? 'checked' : '' ?>
                                           <?= !$permissions['amenities'] ? 'disabled' : '' ?>
                                           class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                    <label for="amenity_<?= $amenity['id'] ?>" 
                                           class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                        <?= htmlspecialchars($amenity['name']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="mb-6">
                <label for="custom_amenities"
                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Custom
                    Amenities</label>
                <div class="flex">
                    <input type="text" id="custom_amenity" <?= !$permissions['amenities'] ? 'readonly' : '' ?>
                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-l-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Add custom amenity">
                    <button type="button" id="add_custom_amenity" <?= !$permissions['amenities'] ? 'disabled' : '' ?>
                        class="bg-blue-600 text-white px-4 py-2 rounded-r-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        Add
                    </button>
                </div>
                <div id="custom_amenities_container" class="mt-3 flex flex-wrap gap-2">
                    <?php
                    // Display custom amenities that aren't in the database
                    foreach ($current_amenities as $amenityId):
                        // If this is a string (name) or an ID that's not in our map, it's a custom amenity
                        if (!is_numeric($amenityId) || !isset($amenityMap[$amenityId])):
                            $customName = is_numeric($amenityId) ? "Custom Amenity $amenityId" : $amenityId;
                    ?>
                        <div class="custom-amenity-item bg-gray-100 dark:bg-gray-700 rounded-full px-3 py-1 flex items-center">
                            <input type="hidden" name="amenities[]" value="<?= htmlspecialchars($amenityId) ?>">
                            <span class="text-sm"><?= htmlspecialchars($customName) ?></span>
                            <?php if ($permissions['amenities']): ?>
                                <button type="button" class="remove-amenity ml-2 text-red-500 hover:text-red-700">
                                    <i class="fas fa-times"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php 
                        endif;
                    endforeach; 
                    ?>
                </div>
            </div>

            <!-- Add a save button for this tab only -->
            <div class="flex justify-end">
                <?php if ($permissions['amenities']): ?>
                    <input type="hidden" name="save_tab" value="amenities">
                    <button type="submit"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                        <i class="fas fa-save mr-2"></i> Save Amenities
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>


        <!-- Images Tab -->
        <div id="images" class="tab-content">
            <form method="POST" enctype="multipart/form-data" class="tab-form">
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                    <h2 class="text-xl font-semibold mb-4">Gym Images</h2>

                    <?php if (!$permissions['images']): ?>
                        <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                            <p>You don't have permission to edit images. Please contact the administrator.</p>
                        </div>
                    <?php endif; ?>
                    <!-- Current Images -->
                    <div class="mb-6">
                        <h3 class="text-lg font-medium mb-3">Current Images</h3>
                        <?php if (empty($gym_images)): ?>
                            <p class="text-gray-500 dark:text-gray-400">No images uploaded yet.</p>
                        <?php else: ?>
                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                                <?php foreach ($gym_images as $image): ?>
                                    <div class="relative group">
                                        <img src="uploads/gym_images/<?= isset($image['image_path']) ? htmlspecialchars($image['image_path']) : '' ?>"
                                            alt="Gym Image" class="w-full h-40 object-cover rounded-lg">

                                        <?php if (
                                            isset($image['is_cover']) && $image['is_cover'] ||
                                            (isset($image['image_path']) && isset($gym['cover_photo']) &&
                                                $image['image_path'] === $gym['cover_photo'])
                                        ): ?>
                                            <div
                                                class="absolute top-2 right-2 bg-yellow-500 text-black px-2 py-1 rounded-full text-xs font-bold">
                                                Cover
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($permissions['images']): ?>
                                            <div
                                                class="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                                <div class="flex space-x-2">
                                                    <button type="button"
                                                        class="set-cover-btn bg-yellow-500 text-black px-2 py-1 rounded-full text-xs font-bold"
                                                        data-image="<?= isset($image['image_path']) ? htmlspecialchars($image['image_path']) : '' ?>">
                                                        Set as Cover
                                                    </button>
                                                    <button type="button"
                                                        class="delete-image-btn bg-red-500 text-white px-2 py-1 rounded-full text-xs font-bold"
                                                        data-id="<?= isset($image['id']) ? $image['id'] : '' ?>">
                                                        Delete
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Hidden inputs for deleted images and cover photo -->
                            <div id="deleted-images-container"></div>
                            <input type="hidden" id="cover_photo_input" name="cover_photo" value="">
                        <?php endif; ?>
                    </div>

                    <!-- Upload New Images -->
                    <?php if ($permissions['images']): ?>
                        <div class="mb-6">
                            <h3 class="text-lg font-medium mb-3">Upload New Images</h3>
                            <input type="file" name="gym_images[]" multiple accept="image/*"
                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">You can select multiple images at once.
                                Max 5MB per image.</p>
                        </div>
                    <?php endif; ?>

                    <!-- Add a save button for this tab only -->
                    <div class="flex justify-end">
                        <?php if ($permissions['images']): ?>
                            <input type="hidden" name="save_tab" value="images">
                            <button type="submit"
                                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                <i class="fas fa-save mr-2"></i> Save Images
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- Equipment Tab -->
        <div id="equipment" class="tab-content">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">Gym Equipment</h2>

                <?php if (!$permissions['equipment']): ?>
                    <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                        <p>You don't have permission to edit equipment. Please contact the administrator.</p>
                    </div>
                <?php endif; ?>

                <!-- Current Equipment Cards -->
                <div class="mb-6">
                    <h3 class="text-lg font-medium mb-3">Current Equipment</h3>
                    <?php if (empty($gym_equipment)): ?>
                        <p class="text-gray-500 dark:text-gray-400">No equipment added yet.</p>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="equipment-container">
                            <?php foreach ($gym_equipment as $equipment): ?>
                                <div class="equipment-card bg-gray-50 dark:bg-gray-700 rounded-lg shadow-sm overflow-hidden">
                                    <?php if (!empty($equipment['image'])): ?>
                                        <div class="h-48 overflow-hidden">
                                            <img src="uploads/equipments/<?= htmlspecialchars($equipment['image']) ?>"
                                                alt="<?= htmlspecialchars($equipment['name']) ?>"
                                                class="w-full h-full object-cover">
                                        </div>
                                    <?php endif; ?>

                                    <div class="p-4">
                                        <h4 class="text-lg font-semibold mb-2"><?= htmlspecialchars($equipment['name']) ?></h4>
                                        <div class="mb-2 flex items-center">
                                            <span
                                                class="bg-blue-100 dark:bg-blue-800 text-blue-800 dark:text-blue-100 text-xs px-2 py-1 rounded-full">
                                                <?= htmlspecialchars($equipment['category'] ?: 'Uncategorized') ?>
                                            </span>
                                            <span class="ml-2 text-sm text-gray-600 dark:text-gray-300">
                                                Qty: <?= htmlspecialchars($equipment['quantity']) ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($equipment['description'])): ?>
                                            <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                                                <?= htmlspecialchars($equipment['description']) ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($permissions['equipment']): ?>
                                            <div class="flex justify-between mt-4">
                                            <button type="button" class="edit-equipment-btn bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm"
                        data-id="<?= $equipment['equipment_id'] ?>"
                        data-name="<?= htmlspecialchars($equipment['name']) ?>"
                        data-category="<?= htmlspecialchars($equipment['category']) ?>"
                        data-quantity="<?= htmlspecialchars($equipment['quantity']) ?>"
                        data-description="<?= htmlspecialchars($equipment['description'] ?? '') ?>">
                    <i class="fas fa-edit mr-1"></i> Edit
                </button>
                                                <button type="button"
                                                    class="delete-equipment-btn bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg text-sm"
                                                    data-id="<?= $equipment['id'] ?>">
                                                    <i class="fas fa-trash mr-1"></i> Delete
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($permissions['equipment']): ?>
                    <!-- Add New Equipment Button -->
                    <div class="mb-6">
                        <button type="button" id="show-add-equipment-form"
                            class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i> Add New Equipment
                        </button>
                    </div>

                    <!-- Add Equipment Form (Hidden by default) -->
                    <div id="add-equipment-form" class="hidden bg-gray-100 dark:bg-gray-700 rounded-lg p-4 mb-6">
                        <h3 class="text-lg font-medium mb-3">Add New Equipment</h3>
                        <form method="POST" enctype="multipart/form-data" class="equipment-form">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                                    <input type="text" name="new_equipment[0][name]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Category</label>
                                    <select name="new_equipment[0][category]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="">Select Category</option>
                                        <?php foreach ($equipment_categories as $category): ?>
                                            <option value="<?= $category ?>"><?= $category ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quantity</label>
                                    <input type="number" name="new_equipment[0][quantity]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Image</label>
                                    <input type="file" name="new_equipment_images[0]" accept="image/*"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div class="md:col-span-2">
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                                    <textarea name="new_equipment[0][description]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-2">
                                <button type="button" id="cancel-add-equipment"
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                                    Cancel
                                </button>
                                <input type="hidden" name="save_tab" value="equipment">
                                <button type="submit"
                                    class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-plus mr-2"></i> Add Equipment
                                </button>
                            </div>
                        </form>
                    </div>

                   <!-- Edit Equipment Form (Hidden by default) -->
<div id="edit-equipment-form" class="hidden bg-gray-100 dark:bg-gray-700 rounded-lg p-4 mb-6">
    <h3 class="text-lg font-medium mb-3">Edit Equipment</h3>
    <form method="POST" enctype="multipart/form-data" class="equipment-form">
        <input type="hidden" id="edit-equipment-id" name="equipment_id" value="">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
                <input type="text" id="edit-equipment-name" name="equipment[name]" 
                       class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Category</label>
                <select id="edit-equipment-category" name="equipment[category]" 
                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">Select Category</option>
                    <?php foreach ($equipment_categories as $category): ?>
                        <option value="<?= $category ?>"><?= $category ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Quantity</label>
                <input type="number" id="edit-equipment-quantity" name="equipment[quantity]" 
                       class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Image</label>
                <input type="file" name="equipment_image" accept="image/*" 
                       class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                <p class="mt-1 text-xs text-gray-500">Leave empty to keep current image</p>
            </div>
            
            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                <textarea id="edit-equipment-description" name="equipment[description]" 
                          class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
            </div>
        </div>
        
        <div class="flex justify-end space-x-2">
            <button type="button" id="cancel-edit-equipment" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                Cancel
            </button>
            <input type="hidden" name="save_tab" value="equipment">
            <input type="hidden" name="action" value="update_equipment">
            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                <i class="fas fa-save mr-2"></i> Update Equipment
            </button>
        </div>
    </form>
</div>


                    <!-- Hidden container for deleted equipment IDs -->
                    <div id="deleted-equipment-container"></div>
                <?php endif; ?>
            </div>
        </div>
        <!-- Membership Plans Tab -->
        <div id="membership-plans" class="tab-content">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">Membership Plans</h2>

                <?php if (!$permissions['membership_plans']): ?>
                    <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                        <p>You don't have permission to edit membership plans. Please contact the administrator.</p>
                    </div>
                <?php endif; ?>

                <!-- Current Plans Cards -->
                <div class="mb-6">
                    <h3 class="text-lg font-medium mb-3">Current Plans</h3>
                    <?php if (empty($gym_plans)): ?>
                        <p class="text-gray-500 dark:text-gray-400">No membership plans added yet.</p>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="plans-container">
                            <?php foreach ($gym_plans as $plan): ?>
                                <div class="plan-card bg-gray-50 dark:bg-gray-700 rounded-lg shadow-sm overflow-hidden">
                                    <div class="bg-blue-500 text-white p-3">
                                        <h4 class="text-lg font-semibold"><?= htmlspecialchars($plan['plan_name']) ?></h4>
                                        <div class="flex justify-between items-center">
                                            <span class="text-sm"><?= htmlspecialchars($plan['tier']) ?></span>
                                            <span class="text-sm"><?= htmlspecialchars($plan['duration']) ?></span>
                                        </div>
                                    </div>

                                    <div class="p-4">
                                        <div class="text-2xl font-bold mb-2">₹<?= htmlspecialchars($plan['price']) ?></div>

                                        <?php if (!empty($plan['best_for'])): ?>
                                            <div class="mb-2">
                                                <span class="text-sm text-gray-600 dark:text-gray-300">Best for:</span>
                                                <span
                                                    class="ml-1 text-sm font-medium"><?= htmlspecialchars($plan['best_for']) ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($plan['inclusions'])): ?>
                                            <div class="mb-4">
                                                <span class="text-sm text-gray-600 dark:text-gray-300">Includes:</span>
                                                <ul class="list-disc list-inside mt-1 text-sm">
                                                    <?php
                                                    $inclusions = explode(',', $plan['inclusions']);
                                                    foreach ($inclusions as $inclusion):
                                                        $inclusion = trim($inclusion);
                                                        if (!empty($inclusion)):
                                                            ?>
                                                            <li><?= htmlspecialchars($inclusion) ?></li>
                                                            <?php
                                                        endif;
                                                    endforeach;
                                                    ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($permissions['membership_plans']): ?>
                                            <div class="flex justify-between mt-4">
                                                <button type="button"
                                                    class="edit-plan-btn bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm"
                                                    data-id="<?= $plan['plan_id'] ?>"
                                                    data-name="<?= htmlspecialchars($plan['plan_name']) ?>"
                                                    data-tier="<?= htmlspecialchars($plan['tier']) ?>"
                                                    data-duration="<?= htmlspecialchars($plan['duration']) ?>"
                                                    data-price="<?= htmlspecialchars($plan['price']) ?>"
                                                    data-best-for="<?= htmlspecialchars($plan['best_for'] ?? '') ?>"
                                                    data-inclusions="<?= htmlspecialchars($plan['inclusions'] ?? '') ?>">
                                                    <i class="fas fa-edit mr-1"></i> Edit
                                                </button>
                                                <button type="button"
                                                    class="delete-plan-btn bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg text-sm"
                                                    data-id="<?= $plan['plan_id'] ?>">
                                                    <i class="fas fa-trash mr-1"></i> Delete
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($permissions['membership_plans']): ?>
                    <!-- Add New Plan Button -->
                    <div class="mb-6">
                        <button type="button" id="show-add-plan-form"
                            class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i> Add New Plan
                        </button>
                    </div>

                    <!-- Add Plan Form (Hidden by default) -->
                    <div id="add-plan-form" class="hidden bg-gray-100 dark:bg-gray-700 rounded-lg p-4 mb-6">
                        <h3 class="text-lg font-medium mb-3">Add New Plan</h3>
                        <form method="POST" class="plan-form">
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Plan
                                        Name</label>
                                    <input type="text" name="new_membership_plans[0][plan_name]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tier</label>
                                    <select name="new_membership_plans[0][tier]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                        <?php foreach ($membership_tiers as $tier): ?>
                                            <option value="<?= $tier ?>"><?= $tier ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Duration</label>
                                    <select name="new_membership_plans[0][duration]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                        <?php foreach ($membership_durations as $duration): ?>
                                            <option value="<?= $duration ?>"><?= $duration ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Price
                                        (₹)</label>
                                    <input type="number" name="new_membership_plans[0][price]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Best
                                        For</label>
                                    <input type="text" name="new_membership_plans[0][best_for]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">E.g., "Beginners", "Regular
                                        Gym-goers", etc.</p>
                                </div>

                                <div class="md:col-span-3">
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Inclusions</label>
                                    <textarea name="new_membership_plans[0][inclusions]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Separate each inclusion with a
                                        comma. E.g., "Access to all equipment, Personal trainer, Locker"</p>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-2">
                                <button type="button" id="cancel-add-plan"
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                                    Cancel
                                </button>
                                <input type="hidden" name="save_tab" value="membership_plans">
                                <button type="submit"
                                    class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-plus mr-2"></i> Add Plan
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Edit Plan Form (Hidden by default) -->
                    <div id="edit-plan-form" class="hidden bg-gray-100 dark:bg-gray-700 rounded-lg p-4 mb-6">
                        <h3 class="text-lg font-medium mb-3">Edit Plan</h3>
                        <form method="POST" class="plan-form">
                            <input type="hidden" id="edit-plan-id" name="plan_id" value="">

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Plan
                                        Name</label>
                                    <input type="text" id="edit-plan-name" name="membership_plan[plan_name]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tier</label>
                                    <select id="edit-plan-tier" name="membership_plan[tier]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                        <?php foreach ($membership_tiers as $tier): ?>
                                            <option value="<?= $tier ?>"><?= $tier ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Duration</label>
                                    <select id="edit-plan-duration" name="membership_plan[duration]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                        <?php foreach ($membership_durations as $duration): ?>
                                            <option value="<?= $duration ?>"><?= $duration ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Price
                                        (₹)</label>
                                    <input type="number" id="edit-plan-price" name="membership_plan[price]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Best
                                        For</label>
                                    <input type="text" id="edit-plan-best-for" name="membership_plan[best_for]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">E.g., "Beginners", "Regular
                                        Gym-goers", etc.</p>
                                </div>

                                <div class="md:col-span-3">
                                    <label
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Inclusions</label>
                                    <textarea id="edit-plan-inclusions" name="membership_plan[inclusions]"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Separate each inclusion with a
                                        comma. E.g., "Access to all equipment, Personal trainer, Locker"</p>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-2">
                                <button type="button" id="cancel-edit-plan"
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                                    Cancel
                                </button>
                                <input type="hidden" name="save_tab" value="membership_plans">
                                <input type="hidden" name="action" value="update_plan">
                                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-save mr-2"></i> Update Plan
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Hidden container for deleted plan IDs -->
                    <div id="deleted-plans-container"></div>
                <?php endif; ?>
            </div>
        </div>




    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Tab navigation
            const tabButtons = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');

            // Set active tab from URL hash or default to first tab
            let activeTabId = window.location.hash.substring(1) || 'basic-info';

            function setActiveTab(tabId) {
                // Update active tab button
                tabButtons.forEach(btn => {
                    if (btn.getAttribute('data-tab') === tabId) {
                        btn.classList.add('bg-blue-600', 'text-white');
                        btn.classList.remove('bg-gray-200', 'text-gray-800', 'dark:bg-gray-700', 'dark:text-white');
                    } else {
                        btn.classList.remove('bg-blue-600', 'text-white');
                        btn.classList.add('bg-gray-200', 'text-gray-800', 'dark:bg-gray-700', 'dark:text-white');
                    }
                });

                // Show active tab content
                tabContents.forEach(content => {
                    if (content.id === tabId) {
                        content.classList.add('active');
                    } else {
                        content.classList.remove('active');
                    }
                });

                // Update URL hash
                window.location.hash = tabId;
                activeTabId = tabId;
            }

            // Initialize active tab
            setActiveTab(activeTabId);

            // Tab button click handlers
            tabButtons.forEach(btn => {
                btn.addEventListener('click', function () {
                    const tabId = this.getAttribute('data-tab');
                    setActiveTab(tabId);
                });
            });

            // Operating hours toggle
            const useDailyHoursCheckbox = document.getElementById('use_daily_hours');
            const dailyHoursSection = document.getElementById('daily-hours-section');
            const individualHoursSection = document.getElementById('individual-hours-section');

            if (useDailyHoursCheckbox) {
                useDailyHoursCheckbox.addEventListener('change', function () {
                    if (this.checked) {
                        dailyHoursSection.classList.remove('hidden');
                        individualHoursSection.classList.add('hidden');
                    } else {
                        dailyHoursSection.classList.add('hidden');
                        individualHoursSection.classList.remove('hidden');
                    }
                });
            }

            // Closed day toggles
            const closedDayCheckboxes = document.querySelectorAll('input[name="closed_days[]"]');

            closedDayCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function () {
                    const dayInputs = this.closest('div').nextElementSibling;
                    if (this.checked) {
                        dayInputs.classList.add('hidden');
                    } else {
                        dayInputs.classList.remove('hidden');
                    }
                });
            });

          // Custom amenities
const addCustomAmenityBtn = document.getElementById('add_custom_amenity');
const customAmenityInput = document.getElementById('custom_amenity');
const customAmenitiesContainer = document.getElementById('custom_amenities_container');

if (addCustomAmenityBtn && customAmenityInput && customAmenitiesContainer) {
    addCustomAmenityBtn.addEventListener('click', function () {
        const amenityValue = customAmenityInput.value.trim();
        if (amenityValue) {
            // Create new amenity tag
            const amenityItem = document.createElement('div');
            amenityItem.className = 'custom-amenity-item bg-gray-100 dark:bg-gray-700 rounded-full px-3 py-1 flex items-center';
            amenityItem.innerHTML = `
                <input type="hidden" name="amenities[]" value="${escapeHtml(amenityValue)}">
                <span class="text-sm">${escapeHtml(amenityValue)}</span>
                <button type="button" class="remove-amenity ml-2 text-red-500 hover:text-red-700">
                    <i class="fas fa-times"></i>
                </button>
            `;

            customAmenitiesContainer.appendChild(amenityItem);
            customAmenityInput.value = '';

            // Add event listener to remove button
            const removeBtn = amenityItem.querySelector('.remove-amenity');
            removeBtn.addEventListener('click', function () {
                amenityItem.remove();
            });
        }
    });

    // Add event listeners to existing remove buttons
    document.querySelectorAll('.remove-amenity').forEach(btn => {
        btn.addEventListener('click', function () {
            this.closest('.custom-amenity-item').remove();
        });
    });
}


            // Image management
            const setCoverBtns = document.querySelectorAll('.set-cover-btn');
            const deleteImageBtns = document.querySelectorAll('.delete-image-btn');
            const deletedImagesContainer = document.getElementById('deleted-images-container');
            const coverPhotoInput = document.getElementById('cover_photo_input');

            if (setCoverBtns.length && coverPhotoInput) {
                setCoverBtns.forEach(btn => {
                    btn.addEventListener('click', function () {
                        const imagePath = this.getAttribute('data-image');
                        coverPhotoInput.value = imagePath;
                        alert('This image will be set as the cover photo when you save changes.');
                    });
                });
            }

            if (deleteImageBtns.length && deletedImagesContainer) {
                deleteImageBtns.forEach(btn => {
                    btn.addEventListener('click', function () {
                        const imageId = this.getAttribute('data-id');
                        const confirmDelete = confirm('Are you sure you want to delete this image?');

                        if (confirmDelete) {
                            // Add hidden input for deleted image
                            const hiddenInput = document.createElement('input');
                            hiddenInput.type = 'hidden';
                            hiddenInput.name = 'delete_images[]';
                            hiddenInput.value = imageId;
                            deletedImagesContainer.appendChild(hiddenInput);

                            // Remove image from display
                            this.closest('.relative').remove();
                        }
                    });
                });
            }

            // Equipment management
            const showAddEquipmentBtn = document.getElementById('show-add-equipment-form');
            const addEquipmentForm = document.getElementById('add-equipment-form');
            const cancelAddEquipmentBtn = document.getElementById('cancel-add-equipment');

            const editEquipmentForm = document.getElementById('edit-equipment-form');
            const cancelEditEquipmentBtn = document.getElementById('cancel-edit-equipment');
            const editEquipmentBtns = document.querySelectorAll('.edit-equipment-btn');

            const deleteEquipmentBtns = document.querySelectorAll('.delete-equipment-btn');
            const deletedEquipmentContainer = document.getElementById('deleted-equipment-container');

            // Show/hide add equipment form
            if (showAddEquipmentBtn && addEquipmentForm) {
                showAddEquipmentBtn.addEventListener('click', function () {
                    addEquipmentForm.classList.remove('hidden');
                    editEquipmentForm.classList.add('hidden');
                    showAddEquipmentBtn.classList.add('hidden');
                });
            }

            if (cancelAddEquipmentBtn) {
                cancelAddEquipmentBtn.addEventListener('click', function () {
                    addEquipmentForm.classList.add('hidden');
                    showAddEquipmentBtn.classList.remove('hidden');
                });
            }

            // Edit equipment
if (editEquipmentBtns.length && editEquipmentForm) {
    editEquipmentBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const category = this.getAttribute('data-category');
            const quantity = this.getAttribute('data-quantity');
            const description = this.getAttribute('data-description');
            
            console.log("Editing equipment:", id, name, category, quantity);
            
            // Fill the edit form
            document.getElementById('edit-equipment-id').value = id;
            document.getElementById('edit-equipment-name').value = name;
            document.getElementById('edit-equipment-category').value = category;
            document.getElementById('edit-equipment-quantity').value = quantity;
            document.getElementById('edit-equipment-description').value = description;
            
            // Show edit form, hide add form
            editEquipmentForm.classList.remove('hidden');
            addEquipmentForm.classList.add('hidden');
            showAddEquipmentBtn.classList.add('hidden');
        });
    });
}


            if (cancelEditEquipmentBtn) {
                cancelEditEquipmentBtn.addEventListener('click', function () {
                    editEquipmentForm.classList.add('hidden');
                    showAddEquipmentBtn.classList.remove('hidden');
                });
            }

            // Delete equipment
            if (deleteEquipmentBtns.length && deletedEquipmentContainer) {
                deleteEquipmentBtns.forEach(btn => {
                    btn.addEventListener('click', function () {
                        const equipmentId = this.getAttribute('data-id');
                        const confirmDelete = confirm('Are you sure you want to delete this equipment?');

                        if (confirmDelete) {
                            // Create form for deletion
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.innerHTML = `
                        <input type="hidden" name="delete_equipment[]" value="${equipmentId}">
                        <input type="hidden" name="save_tab" value="equipment">
                    `;
                            document.body.appendChild(form);
                            form.submit();
                        }
                    });
                });
            }

            // Membership plans management
            const showAddPlanBtn = document.getElementById('show-add-plan-form');
            const addPlanForm = document.getElementById('add-plan-form');
            const cancelAddPlanBtn = document.getElementById('cancel-add-plan');

            const editPlanForm = document.getElementById('edit-plan-form');
            const cancelEditPlanBtn = document.getElementById('cancel-edit-plan');
            const editPlanBtns = document.querySelectorAll('.edit-plan-btn');

            const deletePlanBtns = document.querySelectorAll('.delete-plan-btn');
            const deletedPlansContainer = document.getElementById('deleted-plans-container');

            // Show/hide add plan form
            if (showAddPlanBtn && addPlanForm) {
                showAddPlanBtn.addEventListener('click', function () {
                    addPlanForm.classList.remove('hidden');
                    editPlanForm.classList.add('hidden');
                    showAddPlanBtn.classList.add('hidden');
                });
            }

            if (cancelAddPlanBtn) {
                cancelAddPlanBtn.addEventListener('click', function () {
                    addPlanForm.classList.add('hidden');
                    showAddPlanBtn.classList.remove('hidden');
                });
            }

            // Edit plan
            if (editPlanBtns.length && editPlanForm) {
                editPlanBtns.forEach(btn => {
                    btn.addEventListener('click', function () {
                        const id = this.getAttribute('data-id');
                        const name = this.getAttribute('data-name');
                        const tier = this.getAttribute('data-tier');
                        const duration = this.getAttribute('data-duration');
                        const price = this.getAttribute('data-price');
                        const bestFor = this.getAttribute('data-best-for');
                        const inclusions = this.getAttribute('data-inclusions');

                        // Fill the edit form
                        document.getElementById('edit-plan-id').value = id;
                        document.getElementById('edit-plan-name').value = name;
                        document.getElementById('edit-plan-tier').value = tier;
                        document.getElementById('edit-plan-duration').value = duration;
                        document.getElementById('edit-plan-price').value = price;
                        document.getElementById('edit-plan-best-for').value = bestFor;
                        document.getElementById('edit-plan-inclusions').value = inclusions;

                        // Show edit form, hide add form
                        editPlanForm.classList.remove('hidden');
                        addPlanForm.classList.add('hidden');
                        showAddPlanBtn.classList.add('hidden');
                    });
                });
            }

            if (cancelEditPlanBtn) {
                cancelEditPlanBtn.addEventListener('click', function () {
                    editPlanForm.classList.add('hidden');
                    showAddPlanBtn.classList.remove('hidden');
                });
            }

            // Delete plan
            if (deletePlanBtns.length && deletedPlansContainer) {
                deletePlanBtns.forEach(btn => {
                    btn.addEventListener('click', function () {
                        const planId = this.getAttribute('data-id');
                        const confirmDelete = confirm('Are you sure you want to delete this membership plan?');

                        if (confirmDelete) {
                            // Create form for deletion
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.innerHTML = `
                        <input type="hidden" name="delete_plans[]" value="${planId}">
                        <input type="hidden" name="save_tab" value="membership_plans">
                    `;
                            document.body.appendChild(form);
                            form.submit();
                        }
                    });
                });
            }

            // Helper function to escape HTML
            function escapeHtml(unsafe) {
                return unsafe
                    .replace(/&/g, "&amp;")
                    .replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;")
                    .replace(/"/g, "&quot;")
                    .replace(/'/g, "&#039;");
            }
        });
    </script>


</body>
</html>