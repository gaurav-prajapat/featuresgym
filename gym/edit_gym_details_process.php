<?php
// ini_set('display_errors', 0);
// error_reporting(0);

// // Log errors instead
// ini_set('log_errors', 1);
// ini_set('error_log', 'php_errors.log');

session_start();
require '../config/database.php';
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

/**
 * Format time value to ensure consistent HH:MM:SS format
 * 
 * @param string $time Time value from form
 * @return string Formatted time in HH:MM:SS format
 */
function formatTimeValue($time) {
    // If time is empty, return default
    if (empty($time)) {
        return '00:00:00';
    }
    
    // If time already has seconds, ensure it's properly formatted
    if (substr_count($time, ':') === 2) {
        return $time;
    }
    
    // If time is just HH:MM, add seconds
    if (substr_count($time, ':') === 1) {
        return $time . ':00';
    }
    
    // If time is just a number (mobile browsers sometimes do this)
    if (is_numeric($time)) {
        $hours = floor($time / 100);
        $minutes = $time % 100;
        return sprintf('%02d:%02d:00', $hours, $minutes);
    }
    
    // Default fallback
    return '00:00:00';
}

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
$success_message = '';
$error_message = '';

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

                // Around line 190-365, modify the operating hours case:

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

        // Check if all days are marked as closed
        $all_days_closed = isset($_POST['all_days_closed']) && $_POST['all_days_closed'] == 'on';

        if ($all_days_closed) {
            // Insert closed hours for all days
            foreach ($days as $day) {
                $insert_hours = "INSERT INTO gym_operating_hours 
                    (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time, is_closed) 
                    VALUES (:gym_id, :day, '00:00:00', '00:00:00', '00:00:00', '00:00:00', 1)";

                $stmt_hours = $conn->prepare($insert_hours);
                $result = $stmt_hours->execute([
                    ':gym_id' => $gym_id,
                    ':day' => $day
                ]);

                if (!$result) {
                    error_log("Failed to insert closed hours for $day: " . print_r($stmt_hours->errorInfo(), true));
                    throw new Exception("Failed to update operating hours");
                }
            }

            // Also add a "Daily" entry marked as closed
            $insert_hours = "INSERT INTO gym_operating_hours 
                (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time, is_closed) 
                VALUES (:gym_id, 'Daily', '00:00:00', '00:00:00', '00:00:00', '00:00:00', 1)";

            $stmt_hours = $conn->prepare($insert_hours);
            $result = $stmt_hours->execute([
                ':gym_id' => $gym_id
            ]);

            if (!$result) {
                error_log("Failed to insert Daily closed hours: " . print_r($stmt_hours->errorInfo(), true));
                throw new Exception("Failed to update operating hours");
            }
        } else {
            // Validate required fields only if not closed
            if (
                empty($daily['morning_open_time']) || empty($daily['morning_close_time']) ||
                empty($daily['evening_open_time']) || empty($daily['evening_close_time'])
            ) {
                throw new Exception("All operating hours fields must be filled out");
            }

            // Format time values properly
            $morning_open = formatTimeValue($daily['morning_open_time']);
            $morning_close = formatTimeValue($daily['morning_close_time']);
            $evening_open = formatTimeValue($daily['evening_open_time']);
            $evening_close = formatTimeValue($daily['evening_close_time']);

            foreach ($days as $day) {
                $insert_hours = "INSERT INTO gym_operating_hours 
                    (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time, is_closed) 
                    VALUES (:gym_id, :day, :morning_open, :morning_close, :evening_open, :evening_close, 0)";

                $stmt_hours = $conn->prepare($insert_hours);
                $result = $stmt_hours->execute([
                    ':gym_id' => $gym_id,
                    ':day' => $day,
                    ':morning_open' => $morning_open,
                    ':morning_close' => $morning_close,
                    ':evening_open' => $evening_open,
                    ':evening_close' => $evening_close
                ]);

                if (!$result) {
                    error_log("Failed to insert hours for $day: " . print_r($stmt_hours->errorInfo(), true));
                    throw new Exception("Failed to update operating hours");
                }
            }

            // Also add a "Daily" entry for convenience
            $insert_hours = "INSERT INTO gym_operating_hours 
                (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time, is_closed) 
                VALUES (:gym_id, 'Daily', :morning_open, :morning_close, :evening_open, :evening_close, 0)";

            $stmt_hours = $conn->prepare($insert_hours);
            $result = $stmt_hours->execute([
                ':gym_id' => $gym_id,
                ':morning_open' => $morning_open,
                ':morning_close' => $morning_close,
                ':evening_open' => $evening_open,
                ':evening_close' => $evening_close
            ]);

            if (!$result) {
                error_log("Failed to insert Daily hours: " . print_r($stmt_hours->errorInfo(), true));
                throw new Exception("Failed to update operating hours");
            }
        }

        error_log("Inserted daily hours for all days");
    } else {
        // Use specific hours for each day
        foreach ($days as $day) {
            $day_lower = strtolower($day);

            // Check if day is marked as closed
            $is_closed = isset($_POST['closed_days']) && in_array($day_lower, $_POST['closed_days']);

            if ($is_closed) {
                $insert_hours = "INSERT INTO gym_operating_hours 
                    (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time, is_closed) 
                    VALUES (:gym_id, :day, '00:00:00', '00:00:00', '00:00:00', '00:00:00', 1)";

                $stmt_hours = $conn->prepare($insert_hours);
                $result = $stmt_hours->execute([
                    ':gym_id' => $gym_id,
                    ':day' => $day
                ]);
            } else {
                // Only validate if the day has operating hours data
                if (isset($_POST['operating_hours'][$day_lower])) {
                    $day_hours = $_POST['operating_hours'][$day_lower];

                    // Check if all required fields are filled
                    if (
                        empty($day_hours['morning_open_time']) || empty($day_hours['morning_close_time']) ||
                        empty($day_hours['evening_open_time']) || empty($day_hours['evening_close_time'])
                    ) {
                        throw new Exception("All operating hours fields for $day must be filled out");
                    }

                    // Format time values properly
                    $morning_open = formatTimeValue($day_hours['morning_open_time']);
                    $morning_close = formatTimeValue($day_hours['morning_close_time']);
                    $evening_open = formatTimeValue($day_hours['evening_open_time']);
                    $evening_close = formatTimeValue($day_hours['evening_close_time']);

                    $insert_hours = "INSERT INTO gym_operating_hours 
                        (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time, is_closed) 
                    VALUES (:gym_id, :day, :morning_open, :morning_close, :evening_open, :evening_close, 0)";

                    $stmt_hours = $conn->prepare($insert_hours);
                    $result = $stmt_hours->execute([
                        ':gym_id' => $gym_id,
                        ':day' => $day,
                        ':morning_open' => $morning_open,
                        ':morning_close' => $morning_close,
                        ':evening_open' => $evening_open,
                        ':evening_close' => $evening_close
                    ]);
                } else {
                    // If no data provided for this day, mark it as closed
                    $insert_hours = "INSERT INTO gym_operating_hours 
                (gym_id, day, morning_open_time, morning_close_time, evening_open_time, evening_close_time, is_closed) 
                VALUES (:gym_id, :day, '00:00:00', '00:00:00', '00:00:00', '00:00:00', 1)";

                    $stmt_hours = $conn->prepare($insert_hours);
                    $result = $stmt_hours->execute([
                        ':gym_id' => $gym_id,
                        ':day' => $day
                    ]);
                }
            }

            if (!$result) {
                error_log("Failed to insert hours for $day: " . print_r($stmt_hours->errorInfo(), true));
                throw new Exception("Failed to update operating hours for $day");
            }
        }

        error_log("Inserted individual operating hours for each day");
    }

    $tab_saved = true;
    break;


                case 'amenities':
                    // Process amenities
                    $selected_amenities = isset($_POST['amenities']) ? $_POST['amenities'] : [];

                    // Convert to JSON for storage
                    $amenities_json = json_encode($selected_amenities);

                    // Update gym record
                    $update_amenities = "UPDATE gyms SET amenities = :amenities WHERE gym_id = :gym_id";
                    $stmt_amenities = $conn->prepare($update_amenities);
                    $result = $stmt_amenities->execute([
                        ':amenities' => $amenities_json,
                        ':gym_id' => $gym_id
                    ]);

                    if (!$result) {
                        error_log("Failed to update amenities: " . print_r($stmt_amenities->errorInfo(), true));
                        throw new Exception("Failed to update amenities");
                    }

                    $tab_saved = true;
                    break;

                case 'images':
                    // Process gallery images
                    if (isset($_FILES['gallery_images']) && !empty($_FILES['gallery_images']['name'][0])) {
                        $upload_dir = 'uploads/gym_images/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0755, true);
                        }

                        // Get current max display order
                        $order_query = "SELECT MAX(display_order) as max_order FROM gym_images WHERE image_id = :gym_id";
                        $order_stmt = $conn->prepare($order_query);
                        $order_stmt->execute([':gym_id' => $gym_id]);
                        $max_order = $order_stmt->fetch(PDO::FETCH_ASSOC)['max_order'] ?? 0;

                        // Process each uploaded file
                        $file_count = count($_FILES['gallery_images']['name']);
                        for ($i = 0; $i < $file_count; $i++) {
                            if ($_FILES['gallery_images']['error'][$i] === UPLOAD_ERR_OK) {
                                $tmp_name = $_FILES['gallery_images']['tmp_name'][$i];
                                $name = uniqid() . '_' . basename($_FILES['gallery_images']['name'][$i]);
                                $path = $upload_dir . $name;

                                if (move_uploaded_file($tmp_name, $path)) {
                                    // Insert into gallery
                                    $insert_gallery = "INSERT INTO gym_images (gym_id, image_path, caption, display_order, status) 
                                                                                          VALUES (:gym_id, :image_path, :caption, :display_order, 'active')";
                                    $stmt_gallery = $conn->prepare($insert_gallery);
                                    $result = $stmt_gallery->execute([
                                        ':gym_id' => $gym_id,
                                        ':image_path' => $name,
                                        ':caption' => $_POST['image_captions'][$i] ?? '',
                                        ':display_order' => $max_order + $i + 1
                                    ]);

                                    if (!$result) {
                                        error_log("Failed to insert gallery image: " . print_r($stmt_gallery->errorInfo(), true));
                                    }
                                }
                            }
                        }
                    }

                    // Process deleted images
                    if (isset($_POST['delete_images']) && !empty($_POST['delete_images'])) {
                        $delete_ids = $_POST['delete_images'];

                        // Get image paths before deleting
                        $path_query = "SELECT image_path FROM gym_images WHERE image_id IN (" . implode(',', array_fill(0, count($delete_ids), '?')) . ")";
                        $path_stmt = $conn->prepare($path_query);
                        $path_stmt->execute($delete_ids);
                        $image_paths = $path_stmt->fetchAll(PDO::FETCH_COLUMN);

                        // Delete from database
                        $delete_query = "DELETE FROM gym_images WHERE image_id IN (" . implode(',', array_fill(0, count($delete_ids), '?')) . ")";
                        $delete_stmt = $conn->prepare($delete_query);
                        $result = $delete_stmt->execute($delete_ids);

                        if ($result) {
                            // Delete files from disk
                            foreach ($image_paths as $path) {
                                $full_path = $upload_dir . $path;
                                if (file_exists($full_path)) {
                                    unlink($full_path);
                                }
                            }
                        } else {
                            error_log("Failed to delete gallery images: " . print_r($delete_stmt->errorInfo(), true));
                        }
                    }


                    // Update image captions and order
                    if (isset($_POST['existing_image_ids']) && !empty($_POST['existing_image_ids'])) {
                        foreach ($_POST['existing_image_ids'] as $index => $id) {
                            $update_query = "UPDATE gym_images SET 
                            caption = :caption, 
                            display_order = :display_order 
                            WHERE image_id = :id";
                            $update_stmt = $conn->prepare($update_query);
                            $result = $update_stmt->execute([
                                ':caption' => $_POST['existing_captions'][$index] ?? '',
                                ':display_order' => $_POST['existing_orders'][$index] ?? 0,
                                ':id' => $id
                            ]);

                            if (!$result) {
                                error_log("Failed to update gallery image: " . print_r($update_stmt->errorInfo(), true));
                            }
                        }
                    }

                    $tab_saved = true;
                    break;

                case 'equipment':
                    // Process equipment updates

                    // Handle deleted equipment
                    if (isset($_POST['delete_equipment']) && !empty($_POST['delete_equipment'])) {
                        $delete_ids = $_POST['delete_equipment'];
                        $delete_query = "UPDATE gym_equipment SET status = 'deleted' WHERE id IN (" . implode(',', array_fill(0, count($delete_ids), '?')) . ")";
                        $delete_stmt = $conn->prepare($delete_query);
                        $result = $delete_stmt->execute($delete_ids);

                        if (!$result) {
                            error_log("Failed to delete equipment: " . print_r($delete_stmt->errorInfo(), true));
                        }
                    }

                    // Update existing equipment
                    if (isset($_POST['equipment_ids']) && !empty($_POST['equipment_ids'])) {
                        foreach ($_POST['equipment_ids'] as $index => $id) {
                            $update_query = "UPDATE gym_equipment SET 
                            name = :name, 
                            category = :category, 
                            quantity = :quantity, 
                            condition_status = :condition_status 
                            WHERE id = :id";
                            $update_stmt = $conn->prepare($update_query);
                            $result = $update_stmt->execute([
                                ':name' => $_POST['equipment_names'][$index] ?? '',
                                ':category' => $_POST['equipment_categories'][$index] ?? '',
                                ':quantity' => $_POST['equipment_quantities'][$index] ?? 1,
                                ':condition_status' => $_POST['equipment_conditions'][$index] ?? 'good',
                                ':id' => $id
                            ]);

                            if (!$result) {
                                error_log("Failed to update equipment: " . print_r($update_stmt->errorInfo(), true));
                            }
                        }
                    }

                    // Add new equipment
                    if (isset($_POST['new_equipment_names']) && !empty($_POST['new_equipment_names'])) {
                        foreach ($_POST['new_equipment_names'] as $index => $name) {
                            if (!empty($name)) {
                                $insert_query = "INSERT INTO gym_equipment (gym_id, name, category, quantity, condition_status, status) 
                                VALUES (:gym_id, :name, :category, :quantity, :condition_status, 'active')";
                                $insert_stmt = $conn->prepare($insert_query);
                                $result = $insert_stmt->execute([
                                    ':gym_id' => $gym_id,
                                    ':name' => $name,
                                    ':category' => $_POST['new_equipment_categories'][$index] ?? '',
                                    ':quantity' => $_POST['new_equipment_quantities'][$index] ?? 1,
                                    ':condition_status' => $_POST['new_equipment_conditions'][$index] ?? 'good'
                                ]);

                                if (!$result) {
                                    error_log("Failed to insert equipment: " . print_r($insert_stmt->errorInfo(), true));
                                }
                            }
                        }
                    }

                    $tab_saved = true;
                    break;

                case 'membership_plans':
                    // Process membership plans

                    // Update existing plans
                    if (isset($_POST['plan_ids']) && !empty($_POST['plan_ids'])) {
                        foreach ($_POST['plan_ids'] as $index => $id) {
                            $update_query = "UPDATE gym_membership_plans SET 
                            plan_name = :plan_name, 
                            tier = :tier, 
                            duration = :duration, 
                            price = :price, 
                            inclusions = :inclusions, 
                            best_for = :best_for 
                            WHERE plan_id = :plan_id";
                            $update_stmt = $conn->prepare($update_query);
                            $result = $update_stmt->execute([
                                ':plan_name' => $_POST['plan_names'][$index] ?? '',
                                ':tier' => $_POST['plan_tiers'][$index] ?? '',
                                ':duration' => $_POST['plan_durations'][$index] ?? '',
                                ':price' => $_POST['plan_prices'][$index] ?? 0,
                                ':inclusions' => $_POST['plan_inclusions'][$index] ?? '',
                                ':best_for' => $_POST['plan_best_for'][$index] ?? '',
                                ':plan_id' => $id
                            ]);

                            if (!$result) {
                                error_log("Failed to update membership plan: " . print_r($update_stmt->errorInfo(), true));
                            }
                        }
                    }

                    // Add new plans
                    if (isset($_POST['new_plan_names']) && !empty($_POST['new_plan_names'])) {
                        foreach ($_POST['new_plan_names'] as $index => $name) {
                            if (!empty($name)) {
                                $insert_query = "INSERT INTO gym_membership_plans (gym_id, plan_name, tier, duration, price, inclusions, best_for) 
                                VALUES (:gym_id, :plan_name, :tier, :duration, :price, :inclusions, :best_for)";
                                $insert_stmt = $conn->prepare($insert_query);
                                $result = $insert_stmt->execute([
                                    ':gym_id' => $gym_id,
                                    ':plan_name' => $name,
                                    ':tier' => $_POST['new_plan_tiers'][$index] ?? '',
                                    ':duration' => $_POST['new_plan_durations'][$index] ?? '',
                                    ':price' => $_POST['new_plan_prices'][$index] ?? 0,
                                    ':inclusions' => $_POST['new_plan_inclusions'][$index] ?? '',
                                    ':best_for' => $_POST['new_plan_best_for'][$index] ?? ''
                                ]);

                                if (!$result) {
                                    error_log("Failed to insert membership plan: " . print_r($insert_stmt->errorInfo(), true));
                                }
                            }
                        }
                    }

                    // Delete plans
                    if (isset($_POST['delete_plans']) && !empty($_POST['delete_plans'])) {
                        $delete_ids = $_POST['delete_plans'];
                        $delete_query = "DELETE FROM gym_membership_plans WHERE plan_id IN (" . implode(',', array_fill(0, count($delete_ids), '?')) . ")";
                        $delete_stmt = $conn->prepare($delete_query);
                        $result = $delete_stmt->execute($delete_ids);

                        if (!$result) {
                            error_log("Failed to delete membership plans: " . print_r($delete_stmt->errorInfo(), true));
                        }
                    }

                    $tab_saved = true;
                    break;

                default:
                    error_log("Unknown tab to save: $tab_to_save");
                    throw new Exception("Unknown section to update");
            }

            // If we got here without exceptions, commit the transaction
            if ($tab_saved) {
                $conn->commit();
                error_log("Transaction committed for tab: $tab_to_save");
                $success_message = ucfirst(str_replace('_', ' ', $tab_to_save)) . " updated successfully!";

                // Set session message for frontend
                $_SESSION['success_message'] = $success_message;

                // Redirect to prevent form resubmission
                header("Location: edit_gym_details.php?tab=$tab_to_save&success=1");
                exit;
            }
        } catch (Exception $e) {
            // Roll back the transaction on error
            $conn->rollBack();
            error_log("Transaction rolled back due to error: " . $e->getMessage());
            $error_message = $e->getMessage();

            // Set session error for frontend
            $_SESSION['error_message'] = $error_message;

            // Redirect with error
            header("Location: edit_gym_details.php?tab=$tab_to_save&error=1");
            exit;
        }
    }
}

// If we get here, it's not a POST request or no tab was specified
// Redirect back to the form
header("Location: edit_gym_details.php");
exit;
