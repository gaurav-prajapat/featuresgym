<?php
// Start session
session_start();

// Include required files
require_once '../config/database.php';
require_once '../includes/csrf.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize CSRF protection
$csrf = new CSRF();

// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Retrieve and sanitize inputs
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $country = trim($_POST['country'] ?? '');
        $zipCode = trim($_POST['zip_code'] ?? '');
        $accountType = trim($_POST['account_type'] ?? 'basic');
        $termsAgreed = isset($_POST['terms_agreed']) ? 1 : 0;
        
        // Validate required fields
        if (empty($name) || empty($email) || empty($password) || empty($phone) || 
            empty($address) || empty($city) || empty($state) || empty($country) || empty($zipCode)) {
            throw new Exception("All fields are required.");
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format.");
        }

        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM gym_owners WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            throw new Exception("Email address is already registered. Please use a different email or login.");
        }

        // Validate phone number (10 digits)
        if (!preg_match("/^[0-9]{10}$/", $phone)) {
            throw new Exception("Phone number must be exactly 10 digits.");
        }

        // Validate password strength
        if (!preg_match(
            "/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/",
            $password
        )) {
            throw new Exception("Password must be at least 8 characters long and include an uppercase letter, a lowercase letter, a number, and a special character.");
        }

        // Set gym limit based on account type
        $gymLimit = 5; // Default for basic
        switch ($accountType) {
            case 'premium':
                $gymLimit = 10;
                break;
            case 'business':
                $gymLimit = 15;
                break;
            case 'unlimited':
                $gymLimit = 999; // Practically unlimited
                break;
        }

        // Hash the password securely
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        // Handle profile picture upload
        $profilePicturePath = null;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] == 0) {
            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
            $fileType = $_FILES['profile_picture']['type'];
            
            if (!in_array($fileType, $allowedTypes)) {
                throw new Exception("Only JPG, PNG, and GIF images are allowed.");
            }
            
            // Validate file size (max 2MB)
            if ($_FILES['profile_picture']['size'] > 2 * 1024 * 1024) {
                throw new Exception("Profile picture must be less than 2MB.");
            }
            
            // Create upload directory if it doesn't exist
            $uploadDir = '../uploads/profile_pictures/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Generate unique filename
            $fileExtension = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $fileName = 'profile_' . time() . '_' . uniqid() . '.' . $fileExtension;
            $targetFile = $uploadDir . $fileName;
            
            // Move uploaded file
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $targetFile)) {
                $profilePicturePath = 'uploads/profile_pictures/' . $fileName;
            } else {
                throw new Exception("Failed to upload profile picture. Please try again.");
            }
        }

        // Begin transaction
        $conn->beginTransaction();

        // Insert data into the gym_owners table
        $stmt = $conn->prepare("
            INSERT INTO gym_owners (
                name, email, phone, password_hash, address, city, state, country, 
                zip_code, profile_picture, gym_limit, account_type, terms_agreed, status, is_verified
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active',1
            )
        ");
        
        $stmt->execute([
            $name, $email, $phone, $passwordHash, $address, $city, $state, $country,
            $zipCode, $profilePicturePath, $gymLimit, $accountType, $termsAgreed
        ]);
        
        // Get the owner_id of the newly inserted gym owner
        $gymOwnerId = $conn->lastInsertId();

        // Commit transaction
        $conn->commit();

        // Store the owner_id in the session
        $_SESSION['gym_owner_id'] = $gymOwnerId;

        // Store more data in the session as needed
        $_SESSION['gym_owner'] = [
            'id' => $gymOwnerId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'account_type' => $accountType,
            'gym_limit' => $gymLimit
        ];

        // Set success message in session
        $_SESSION['registration_success'] = true;
        $_SESSION['message'] = "Registration successful! You can now add your gym details.";
        
        // Redirect to add gym page
        header("Location: add_gym.php");
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        
        // Store error message in session
        $_SESSION['registration_error'] = $e->getMessage();
        
        // Redirect back to registration form with error
        header("Location: register.php");
        exit;
    }
} else {
    // If not a POST request, redirect to the registration form
    header("Location: register.php");
    exit;
}
?>
