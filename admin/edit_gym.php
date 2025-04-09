<?php
ob_start();
require_once '../config/database.php';
include '../includes/navbar.php';

// Ensure user is authenticated and has admin role
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = "You don't have permission to access this page.";
    header('Location: ../login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Check if gym ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid gym ID.";
    header('Location: manage_gym.php');
    exit();
}

$gym_id = $_GET['id'];

// Fetch gym details
$query = "
    SELECT g.*, go.name AS owner_name, go.email AS owner_email, go.phone AS owner_phone, go.id AS owner_id
    FROM gyms g
    LEFT JOIN gym_owners go ON g.owner_id = go.id
    WHERE g.gym_id = :gym_id
";
$stmt = $conn->prepare($query);
$stmt->execute([':gym_id' => $gym_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    $_SESSION['error'] = "Gym not found.";
    header('Location: manage_gym.php');
    exit();
}

// Fetch all gym owners for the dropdown
$owners_query = "SELECT id, name, email FROM gym_owners WHERE status = 'active' ORDER BY name ASC";
$owners_stmt = $conn->prepare($owners_query);
$owners_stmt->execute();
$gym_owners = $owners_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_gym']) && isset($_POST['csrf_token'])) {
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = "CSRF token validation failed.";
        header("Location: edit_gym.php?id=$gym_id");
        exit();
    }
    
    // Sanitize and validate input
    $name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING));
    $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING));
    $address = trim(filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING));
    $city = trim(filter_input(INPUT_POST, 'city', FILTER_SANITIZE_STRING));
    $state = trim(filter_input(INPUT_POST, 'state', FILTER_SANITIZE_STRING));
    $country = trim(filter_input(INPUT_POST, 'country', FILTER_SANITIZE_STRING));
    $zip_code = trim(filter_input(INPUT_POST, 'zip_code', FILTER_SANITIZE_STRING));
    $phone = trim(filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING));
    $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
    $capacity = (int)filter_input(INPUT_POST, 'capacity', FILTER_SANITIZE_NUMBER_INT);
    $owner_id = (int)filter_input(INPUT_POST, 'owner_id', FILTER_SANITIZE_NUMBER_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $gym_cut_percentage = (int)filter_input(INPUT_POST, 'gym_cut_percentage', FILTER_SANITIZE_NUMBER_INT);
    $additional_notes = trim(filter_input(INPUT_POST, 'additional_notes', FILTER_SANITIZE_STRING));
    
    // Validate required fields
    $errors = [];
    if (empty($name)) $errors[] = "Gym name is required.";
    if (empty($address)) $errors[] = "Address is required.";
    if (empty($city)) $errors[] = "City is required.";
    if (empty($country)) $errors[] = "Country is required.";
    if ($capacity <= 0) $errors[] = "Capacity must be greater than zero.";
    if ($owner_id <= 0) $errors[] = "Please select a gym owner.";
    if ($gym_cut_percentage < 0 || $gym_cut_percentage > 100) $errors[] = "Gym cut percentage must be between 0 and 100.";
    
    // Validate email if provided
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format.";
    }
    
    // Handle cover photo upload if provided
    $cover_photo = $gym['cover_photo']; // Keep existing by default
    
    if (isset($_FILES['cover_photo']) && $_FILES['cover_photo']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $file_type = $_FILES['cover_photo']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Only JPG, JPEG, PNG, and WEBP files are allowed for cover photo.";
        } else {
            $upload_dir = '../uploads/gym_covers/';
            
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = uniqid() . '_' . basename($_FILES['cover_photo']['name']);
            $upload_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['cover_photo']['tmp_name'], $upload_path)) {
                // Delete old cover photo if it exists and is not the default
                if (!empty($gym['cover_photo']) && file_exists('../' . $gym['cover_photo']) && strpos($gym['cover_photo'], 'default') === false) {
                    unlink('../' . $gym['cover_photo']);
                }
                
                $cover_photo = 'uploads/gym_covers/' . $file_name;
            } else {
                $errors[] = "Failed to upload cover photo.";
            }
        }
    }
    
    // Process form if no errors
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Update gym details
            $update_query = "
                UPDATE gyms SET
                    name = :name,
                    description = :description,
                    address = :address,
                    city = :city,
                    state = :state,
                    country = :country,
                    zip_code = :zip_code,
                    cover_photo = :cover_photo,
                    phone = :phone,
                    email = :email,
                    capacity = :capacity,
                    owner_id = :owner_id,
                    status = :status,
                    is_featured = :is_featured,
                    gym_cut_percentage = :gym_cut_percentage,
                    additional_notes = :additional_notes
                WHERE gym_id = :gym_id
            ";
            
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->execute([
                ':name' => $name,
                ':description' => $description,
                ':address' => $address,
                ':city' => $city,
                ':state' => $state,
                ':country' => $country,
                ':zip_code' => $zip_code,
                ':cover_photo' => $cover_photo,
                ':phone' => $phone,
                ':email' => $email,
                ':capacity' => $capacity,
                ':owner_id' => $owner_id,
                ':status' => $status,
                ':is_featured' => $is_featured,
                ':gym_cut_percentage' => $gym_cut_percentage,
                ':additional_notes' => $additional_notes,
                ':gym_id' => $gym_id
            ]);
            
            // Log the activity
            $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                         VALUES (:user_id, 'admin', 'update_gym', :details, :ip, :user_agent)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->execute([
                ':user_id' => $_SESSION['admin_id'],
                ':details' => "Updated gym ID: $gym_id - $name",
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $conn->commit();
            
            $_SESSION['success'] = "Gym updated successfully!";
            header("Location: manage_gym.php");
            exit();
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Fetch gym operating hours
$hours_query = "SELECT * FROM gym_operating_hours WHERE gym_id = :gym_id ORDER BY FIELD(day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'Daily')";
$hours_stmt = $conn->prepare($hours_query);
$hours_stmt->execute([':gym_id' => $gym_id]);
$operating_hours = $hours_stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize hours by day for easier access
$hours_by_day = [];
foreach ($operating_hours as $hour) {
    $hours_by_day[$hour['day']] = $hour;
}

// Fetch gym equipment
$equipment_query = "SELECT * FROM gym_equipment WHERE gym_id = :gym_id ORDER BY category, name";
$equipment_stmt = $conn->prepare($equipment_query);
$equipment_stmt->execute([':gym_id' => $gym_id]);
$equipment = $equipment_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch gym membership plans
$plans_query = "SELECT * FROM gym_membership_plans WHERE gym_id = :gym_id ORDER BY price ASC";
$plans_stmt = $conn->prepare($plans_query);
$plans_stmt->execute([':gym_id' => $gym_id]);
$membership_plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch gym images
$images_query = "SELECT * FROM gym_images WHERE gym_id = :gym_id ORDER BY display_order ASC";
$images_stmt = $conn->prepare($images_query);
$images_stmt->execute([':gym_id' => $gym_id]);
$gym_images = $images_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
    <!-- Display success/error messages -->
    <?php if (isset($error_message)): ?>
        <div class="bg-red-500 text-white p-4 rounded-lg mb-6 flex items-center justify-between">
            <div>
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= $error_message ?>
            </div>
            <button class="text-white focus:outline-none" onclick="this.parentElement.style.display='none'">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <div class="p-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                <h1 class="text-3xl font-bold text-white mb-4 md:mb-0">Edit Gym: <?= htmlspecialchars($gym['name']) ?></h1>
                <div class="flex space-x-3">
                    <a href="manage_gym.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Gyms
                    </a>
                    <a href="../gym-details.php?id=<?= $gym_id ?>" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                        <i class="fas fa-external-link-alt mr-2"></i>View Gym Page
                    </a>
                </div>
            </div>
            
            <form action="edit_gym.php?id=<?= $gym_id ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                
                <div class="bg-gray-700 rounded-lg p-6 mb-6">
                    <h2 class="text-xl font-semibold text-white mb-4">Basic Information</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-gray-300 mb-2">Gym Name*</label>
                            <input type="text" id="name" name="name" value="<?= htmlspecialchars($gym['name']) ?>" required
                                   class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </div>
                        
                        <div>
                            <label for="owner_id" class="block text-gray-300 mb-2">Gym Owner*</label>
                            <select id="owner_id" name="owner_id" required
                                    class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <option value="">Select Owner</option>
                                <?php foreach ($gym_owners as $owner): ?>
                                    <option value="<?= $owner['id'] ?>" <?= $owner['id'] == $gym['owner_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($owner['name']) ?> (<?= htmlspecialchars($owner['email']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label for="description" class="block text-gray-300 mb-2">Description</label>
                            <textarea id="description" name="description" rows="4"
                                      class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?= htmlspecialchars($gym['description']) ?></textarea>
                        </div>
                        
                        <div>
                            <label for="status" class="block text-gray-300 mb-2">Status*</label>
                            <select id="status" name="status" required
                                    class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <option value="active" <?= $gym['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="inactive" <?= $gym['status'] == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                <option value="pending" <?= $gym['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="suspended" <?= $gym['status'] == 'suspended' ? 'selected' : '' ?>>Suspended</option>
                                <option value="deleted" <?= $gym['status'] == 'deleted' ? 'selected' : '' ?>>Deleted</option>
                            </select>
                        </div>
                        
                        <div>
                            <label for="capacity" class="block text-gray-300 mb-2">Capacity*</label>
                            <input type="number" id="capacity" name="capacity" value="<?= $gym['capacity'] ?>" required min="1"
                                   class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </div>
                        
                        <div>
                            <label for="gym_cut_percentage" class="block text-gray-300 mb-2">Gym Cut Percentage (%)*</label>
                            <input type="number" id="gym_cut_percentage" name="gym_cut_percentage" value="<?= $gym['gym_cut_percentage'] ?>" required min="0" max="100"
                                   class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <p class="text-gray-400 text-sm mt-1">Percentage of revenue that goes to the gym owner.</p>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="is_featured" name="is_featured" <?= $gym['is_featured'] ? 'checked' : '' ?>
                                   class="w-5 h-5 bg-gray-800 border border-gray-600 rounded focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <label for="is_featured" class="ml-2 text-gray-300">Feature this gym on homepage</label>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-6 mb-6">
                    <h2 class="text-xl font-semibold text-white mb-4">Location & Contact</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="md:col-span-2">
                            <label for="address" class="block text-gray-300 mb-2">Address*</label>
                            <input type="text" id="address" name="address" value="<?= htmlspecialchars($gym['address']) ?>" required
                                   class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </div>
                        
                        <div>
                            <label for="city" class="block text-gray-300 mb-2">City*</label>
                            <input type="text" id="city" name="city" value="<?= htmlspecialchars($gym['city']) ?>" required
                                   class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </div>
                        
                        <div>
                            <label for="state" class="block text-gray-300 mb-2">State/Province</label>
                            <input type="text" id="state" name="state" value="<?= htmlspecialchars($gym['state']) ?>"
                                   class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </div>
                        
                        <div>
                            <label for="country" class="block text-gray-300 mb-2">Country*</label>
                            <input type="text" id="country" name="country" value="<?= htmlspecialchars($gym['country']) ?>" required
                                   class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </div>
                        
                        <div>
                            <label for="zip_code" class="block text-gray-300 mb-2">ZIP/Postal Code</label>
                            <input type="text" id="zip_code" name="zip_code" value="<?= htmlspecialchars($gym['zip_code']) ?>"
                                   class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </div>
                        
                        <div>
                            <label for="phone" class="block text-gray-300 mb-2">Phone Number</label>
                            <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($gym['phone']) ?>"
                                   class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </div>
                        
                        <div>
                            <label for="email" class="block text-gray-300 mb-2">Email Address</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($gym['email']) ?>"
                                   class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-6 mb-6">
                    <h2 class="text-xl font-semibold text-white mb-4">Cover Photo</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="cover_photo" class="block text-gray-300 mb-2">Upload New Cover Photo</label>
                            <input type="file" id="cover_photo" name="cover_photo" accept="image/*"
                                   class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                            <p class="text-gray-400 text-sm mt-1">Recommended size: 1200x600 pixels. Max file size: 2MB.</p>
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-2">Current Cover Photo</label>
                            <?php if (!empty($gym['cover_photo'])): ?>
                                <div class="relative h-40 bg-gray-800 rounded-lg overflow-hidden">
                                    <img src="../<?= htmlspecialchars($gym['cover_photo']) ?>" alt="<?= htmlspecialchars($gym['name']) ?>" class="w-full h-full object-cover">
                                </div>
                            <?php else: ?>
                                <div class="h-40 bg-gray-800 rounded-lg flex items-center justify-center">
                                    <p class="text-gray-400">No cover photo</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-700 rounded-lg p-6 mb-6">
                    <h2 class="text-xl font-semibold text-white mb-4">Additional Information</h2>
                    
                    <div>
                        <label for="additional_notes" class="block text-gray-300 mb-2">Additional Notes</label>
                        <textarea id="additional_notes" name="additional_notes" rows="4"
                                  class="w-full bg-gray-800 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500"><?= htmlspecialchars($gym['additional_notes']) ?></textarea>
                    </div>
                </div>
                
                <div class="flex justify-between mt-8">
                    <a href="manage_gym.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-3 px-6 rounded-lg transition-colors duration-200">
                        Cancel
                    </a>
                    <button type="submit" name="update_gym" class="bg-yellow-500 hover:bg-yellow-600 text-black font-medium py-3 px-6 rounded-lg transition-colors duration-200">
                        Update Gym
                    </button>
                </div>
            </form>
            
            <!-- Additional Management Sections -->
            <div class="mt-12 grid grid-cols-1 md:grid-cols-3 gap-6">
                <a href="manage_gym_hours.php?id=<?= $gym_id ?>" class="bg-gray-700 hover:bg-gray-600 rounded-lg p-6 transition-colors duration-200">
                    <div class="flex items-center mb-4">
                        <div class="p-3 rounded-full bg-blue-500 bg-opacity-25 text-blue-500 mr-4">
                            <i class="fas fa-clock text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Operating Hours</h3>
                    </div>
                    <p class="text-gray-400">Manage the gym's opening and closing hours for each day of the week.</p>
                </a>
                
                <a href="manage_gym_equipment.php?id=<?= $gym_id ?>" class="bg-gray-700 hover:bg-gray-600 rounded-lg p-6 transition-colors duration-200">
                    <div class="flex items-center mb-4">
                        <div class="p-3 rounded-full bg-green-500 bg-opacity-25 text-green-500 mr-4">
                            <i class="fas fa-dumbbell text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Equipment</h3>
                    </div>
                    <p class="text-gray-400">Add, edit, or remove equipment available at this gym.</p>
                </a>
                
                <a href="manage_gym_plans.php?id=<?= $gym_id ?>" class="bg-gray-700 hover:bg-gray-600 rounded-lg p-6 transition-colors duration-200">
                    <div class="flex items-center mb-4">
                        <div class="p-3 rounded-full bg-purple-500 bg-opacity-25 text-purple-500 mr-4">
                            <i class="fas fa-tags text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Membership Plans</h3>
                    </div>
                    <p class="text-gray-400">Manage membership plans, pricing, and features for this gym.</p>
                </a>
                
                <a href="manage_gym_gallery.php?id=<?= $gym_id ?>" class="bg-gray-700 hover:bg-gray-600 rounded-lg p-6 transition-colors duration-200">
                    <div class="flex items-center mb-4">
                        <div class="p-3 rounded-full bg-yellow-500 bg-opacity-25 text-yellow-500 mr-4">
                            <i class="fas fa-images text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Gallery</h3>
                    </div>
                    <p class="text-gray-400">Upload and manage photos showcasing the gym's facilities.</p>
                </a>
                
                <a href="manage_gym_permissions.php?id=<?= $gym_id ?>" class="bg-gray-700 hover:bg-gray-600 rounded-lg p-6 transition-colors duration-200">
                    <div class="flex items-center mb-4">
                        <div class="p-3 rounded-full bg-red-500 bg-opacity-25 text-red-500 mr-4">
                            <i class="fas fa-key text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Permissions</h3>
                    </div>
                    <p class="text-gray-400">Configure what the gym owner can edit and manage.</p>
                </a>
                
                <a href="gym_revenue.php?id=<?= $gym_id ?>" class="bg-gray-700 hover:bg-gray-600 rounded-lg p-6 transition-colors duration-200">
                    <div class="flex items-center mb-4">
                        <div class="p-3 rounded-full bg-indigo-500 bg-opacity-25 text-indigo-500 mr-4">
                            <i class="fas fa-chart-line text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-white">Revenue & Analytics</h3>
                    </div>
                    <p class="text-gray-400">View revenue reports, visitor statistics, and performance metrics.</p>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
    // Preview image before upload
      // Preview image before upload
      document.getElementById('cover_photo').addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(event) {
                const imgContainer = document.querySelector('.h-40.bg-gray-800');
                
                // Clear existing content
                imgContainer.innerHTML = '';
                
                // Create and append new image
                const img = document.createElement('img');
                img.src = event.target.result;
                img.alt = 'Cover Photo Preview';
                img.className = 'w-full h-full object-cover';
                imgContainer.appendChild(img);
            };
            reader.readAsDataURL(file);
        }
    });
    
    // Confirm before leaving page if form has been modified
    const form = document.querySelector('form');
    const originalFormData = new FormData(form);
    
    window.addEventListener('beforeunload', function(e) {
        const currentFormData = new FormData(form);
        let formChanged = false;
        
        // Compare original and current form data
        for (const [key, value] of currentFormData.entries()) {
            if (originalFormData.get(key) !== value) {
                formChanged = true;
                break;
            }
        }
        
        if (formChanged) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        }
    });
    
    // Disable confirmation when form is submitted
    form.addEventListener('submit', function() {
        window.removeEventListener('beforeunload', function() {});
    });
</script>

<style>
    /* Custom file input styling */
    input[type="file"] {
        position: relative;
        padding-left: 30px;
    }
    
    input[type="file"]::before {
        content: '\f093';
        font-family: 'Font Awesome 5 Free';
        font-weight: 900;
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: #EAB308;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        .grid-cols-1 {
            grid-template-columns: 1fr;
        }
    }
</style>

<?php include('../includes/footer.php'); ?>


