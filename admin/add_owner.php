<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /profitmarts/FlexFit/views/auth/login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

$errors = [];
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate input
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? '');
    $zip_code = trim($_POST['zip_code'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $gym_limit = (int)($_POST['gym_limit'] ?? 1);
    $account_type = trim($_POST['account_type'] ?? 'basic');
    $is_verified = isset($_POST['is_verified']) ? 1 : 0;
    $is_approved = isset($_POST['is_approved']) ? 1 : 0;
    $terms_agreed = isset($_POST['terms_agreed']) ? 1 : 0;
    
    // Validate required fields
    if (empty($name)) $errors[] = "Name is required";
    if (empty($email)) $errors[] = "Email is required";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    if (empty($phone)) $errors[] = "Phone number is required";
    if (empty($address)) $errors[] = "Address is required";
    if (empty($city)) $errors[] = "City is required";
    if (empty($state)) $errors[] = "State is required";
    if (empty($country)) $errors[] = "Country is required";
    if (empty($zip_code)) $errors[] = "ZIP code is required";
    if (empty($password)) $errors[] = "Password is required";
    elseif (strlen($password) < 8) $errors[] = "Password must be at least 8 characters";
    if ($password !== $confirm_password) $errors[] = "Passwords do not match";
    
    // Check if email already exists
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM gym_owners WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Email already exists. Please use a different email.";
        }
    }
    
    // Process profile image if uploaded
    $profile_picture = null;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES['profile_picture']['type'];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Invalid file type. Only JPG, PNG, and GIF are allowed.";
        } else {
            $upload_dir = '../uploads/owner_profiles/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_name = time() . '_' . basename($_FILES['profile_picture']['name']);
            $target_file = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target_file)) {
                $profile_picture = 'uploads/owner_profiles/' . $file_name;
            } else {
                $errors[] = "Failed to upload profile picture.";
            }
        }
    }
    
    // If no errors, insert new owner
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Hash password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert owner
            $stmt = $conn->prepare("
                INSERT INTO gym_owners (
                    name, email, phone, password_hash, address, city, state, country, zip_code, 
                    profile_picture, gym_limit, is_verified, is_approved, terms_agreed, account_type, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active'
                )
            ");
            
            $stmt->execute([
                $name, $email, $phone, $password_hash, $address, $city, $state, $country, $zip_code,
                $profile_picture, $gym_limit, $is_verified, $is_approved, $terms_agreed, $account_type
            ]);
            
            $owner_id = $conn->lastInsertId();
            
            // Log the activity
            $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                         VALUES (:user_id, 'admin', 'add_owner', :details, :ip, :user_agent)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':details' => "Added new gym owner ID: $owner_id - $name",
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $conn->commit();
            
            $success = "Gym owner added successfully!";
            
            // Redirect to owner list or view page
            header("Location: view_owner.php?id=$owner_id");
            exit();
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Gym Owner - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Add New Gym Owner</h1>
            <a href="gym_owners.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                <i class="fas fa-arrow-left mr-2"></i> Back to Owners
            </a>
        </div>
        
        <?php if (!empty($errors)): ?>
            <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
                <ul class="list-disc pl-5">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-semibold">Owner Information</h2>
                <p class="text-gray-400 mt-1">Fill in the details to create a new gym owner account</p>
            </div>
            
            <form method="POST" enctype="multipart/form-data" class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Personal Information -->
                    <div class="space-y-4">
                        <h3 class="text-lg font-semibold border-b border-gray-700 pb-2 mb-4">Personal Information</h3>
                        
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-400 mb-1">Full Name *</label>
                            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-400 mb-1">Email Address *</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-400 mb-1">Phone Number *</label>
                            <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="profile_picture" class="block text-sm font-medium text-gray-400 mb-1">Profile Picture</label>
                            <input type="file" id="profile_picture" name="profile_picture" accept="image/*" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <p class="text-xs text-gray-400 mt-1">Upload a profile picture (JPG, PNG, or GIF)</p>
                        </div>
                        
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-400 mb-1">Password *</label>
                            <input type="password" id="password" name="password" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <p class="text-xs text-gray-400 mt-1">Minimum 8 characters</p>
                        </div>
                        
                        <div>
                            <label for="confirm_password" class="block text-sm font-medium text-gray-400 mb-1">Confirm Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        </div>
                    </div>
                    
                    <!-- Address & Account Information -->
                    <div class="space-y-4">
                        <h3 class="text-lg font-semibold border-b border-gray-700 pb-2 mb-4">Address & Account Information</h3>
                        
                        <div>
                            <label for="address" class="block text-sm font-medium text-gray-400 mb-1">Address *</label>
                            <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="city" class="block text-sm font-medium text-gray-400 mb-1">City *</label>
                                <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="state" class="block text-sm font-medium text-gray-400 mb-1">State *</label>
                                <input type="text" id="state" name="state" value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="country" class="block text-sm font-medium text-gray-400 mb-1">Country *</label>
                                <input type="text" id="country" name="country" value="<?php echo htmlspecialchars($_POST['country'] ?? ''); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="zip_code" class="block text-sm font-medium text-gray-400 mb-1">ZIP Code *</label>
                                <input type="text" id="zip_code" name="zip_code" value="<?php echo htmlspecialchars($_POST['zip_code'] ?? ''); ?>" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            </div>
                        </div>
                        
                        <div>
                            <label for="gym_limit" class="block text-sm font-medium text-gray-400 mb-1">Gym Limit</label>
                            <input type="number" id="gym_limit" name="gym_limit" value="<?php echo htmlspecialchars($_POST['gym_limit'] ?? '1'); ?>" min="0" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <p class="text-xs text-gray-400 mt-1">Maximum number of gyms this owner can create (0 for unlimited)</p>
                        </div>
                        
                        <div>
                            <label for="account_type" class="block text-sm font-medium text-gray-400 mb-1">Account Type</label>
                            <select id="account_type" name="account_type" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                <option value="basic" <?php echo (isset($_POST['account_type']) && $_POST['account_type'] === 'basic') ? 'selected' : ''; ?>>Basic</option>
                                <option value="premium" <?php echo (isset($_POST['account_type']) && $_POST['account_type'] === 'premium') ? 'selected' : ''; ?>>Premium</option>
                                <option value="enterprise" <?php echo (isset($_POST['account_type']) && $_POST['account_type'] === 'enterprise') ? 'selected' : ''; ?>>Enterprise</option>
                            </select>
                        </div>
                        
                        <div class="space-y-2">
                            <div class="flex items-center">
                                <input type="checkbox" id="is_verified" name="is_verified" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-600 rounded bg-gray-700" <?php echo (isset($_POST['is_verified'])) ? 'checked' : ''; ?>>
                                <label for="is_verified" class="ml-2 block text-sm text-gray-400">Email Verified</label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" id="is_approved" name="is_approved" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-600 rounded bg-gray-700" <?php echo (isset($_POST['is_approved'])) ? 'checked' : ''; ?>>
                                <label for="is_approved" class="ml-2 block text-sm text-gray-400">Admin Approved</label>
                            </div>
                            
                            <div class="flex items-center">
                                <input type="checkbox" id="terms_agreed" name="terms_agreed" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-600 rounded bg-gray-700" <?php echo (isset($_POST['terms_agreed'])) ? 'checked' : ''; ?>>
                                <label for="terms_agreed" class="ml-2 block text-sm text-gray-400">Terms & Conditions Agreed</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-8 flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg transition-colors duration-200">
                        <i class="fas fa-plus-circle mr-2"></i> Create Owner Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>

