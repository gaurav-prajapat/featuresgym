<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Get gym ID from URL
$gymId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($gymId <= 0) {
    header('Location: manage_gyms.php');
    exit;
}

// Fetch gym details
$stmt = $conn->prepare("SELECT * FROM gyms WHERE gym_id = ?");
$stmt->execute([$gymId]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    header('Location: manage_gyms.php');
    exit;
}

// Fetch current permissions
$stmt = $conn->prepare("SELECT * FROM gym_edit_permissions WHERE gym_id = ?");
$stmt->execute([$gymId]);
$permissions = $stmt->fetch(PDO::FETCH_ASSOC);

// If no permissions set yet, create default
if (!$permissions) {
    $stmt = $conn->prepare("INSERT INTO gym_edit_permissions (gym_id) VALUES (?)");
    $stmt->execute([$gymId]);
    
    $stmt = $conn->prepare("SELECT * FROM gym_edit_permissions WHERE gym_id = ?");
    $stmt->execute([$gymId]);
    $permissions = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $basic_info = isset($_POST['basic_info']) ? 1 : 0;
    $operating_hours = isset($_POST['operating_hours']) ? 1 : 0;
    $amenities = isset($_POST['amenities']) ? 1 : 0;
    $images = isset($_POST['images']) ? 1 : 0;
    $equipment = isset($_POST['equipment']) ? 1 : 0;
    $membership_plans = isset($_POST['membership_plans']) ? 1 : 0;
    $gym_cut_percentage = isset($_POST['gym_cut_percentage']) ? 1 : 0;
    
    $stmt = $conn->prepare("
        UPDATE gym_edit_permissions 
        SET basic_info = ?, operating_hours = ?, amenities = ?, 
            images = ?, equipment = ?, membership_plans = ?, gym_cut_percentage = ?
        WHERE gym_id = ?
    ");
    
    $stmt->execute([
        $basic_info, $operating_hours, $amenities, 
        $images, $equipment, $membership_plans, $gym_cut_percentage,
        $gymId
    ]);
    
    $success_message = "Permissions updated successfully!";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Gym Edit Permissions - Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-100">
    <?php include '../includes/navbar.php'; ?>
    
    <div class="container mx-auto px-4 py-8">
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold">Edit Permissions for <?= htmlspecialchars($gym['name']) ?></h1>
                <a href="manage_gym.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">
                    <i class="fas fa-arrow-left mr-2"></i> Back
                </a>
            </div>
            
            <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <?= $success_message ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="mb-6">
                    <p class="text-gray-700 mb-2">Select which sections the gym owner can edit:</p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex items-center">
                            <input type="checkbox" id="basic_info" name="basic_info" value="1" 
                                   <?= $permissions['basic_info'] ? 'checked' : '' ?> 
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <label for="basic_info" class="ml-2 text-gray-700">Basic Information</label>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="operating_hours" name="operating_hours" value="1" 
                                   <?= $permissions['operating_hours'] ? 'checked' : '' ?> 
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <label for="operating_hours" class="ml-2 text-gray-700">Operating Hours</label>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="amenities" name="amenities" value="1" 
                                   <?= $permissions['amenities'] ? 'checked' : '' ?> 
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <label for="amenities" class="ml-2 text-gray-700">Amenities</label>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="images" name="images" value="1" 
                                   <?= $permissions['images'] ? 'checked' : '' ?> 
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <label for="images" class="ml-2 text-gray-700">Images</label>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="equipment" name="equipment" value="1" 
                                   <?= $permissions['equipment'] ? 'checked' : '' ?> 
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <label for="equipment" class="ml-2 text-gray-700">Equipment</label>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="membership_plans" name="membership_plans" value="1" 
                                   <?= $permissions['membership_plans'] ? 'checked' : '' ?> 
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <label for="membership_plans" class="ml-2 text-gray-700">Membership Plans</label>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="gym_cut_percentage" name="gym_cut_percentage" value="1" 
                                   <?= $permissions['gym_cut_percentage'] ? 'checked' : '' ?> 
                                   class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                            <label for="gym_cut_percentage" class="ml-2 text-gray-700">Gym Cut Percentage</label>
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">
                        Save Permissions
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
