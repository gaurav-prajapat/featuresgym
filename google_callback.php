<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'vendor/autoload.php';

// Initialize database and auth
$db = new GymDatabase();
$conn = $db->getConnection();
$auth = new Auth($conn);

try {
    // Load Google client configuration
    $googleClientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
    $googleClientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
    $googleRedirectUrl = $_ENV['GOOGLE_REDIRECT_URI'] ?? '';
    
    if (empty($googleClientId) || empty($googleClientSecret) || empty($googleRedirectUrl)) {
        throw new Exception("Google OAuth configuration is incomplete");
    }
    
    // Initialize Google Client
    $googleClient = new Google\Client();
    $googleClient->setClientId($googleClientId);
    $googleClient->setClientSecret($googleClientSecret);
    $googleClient->setRedirectUri($googleRedirectUrl);
    $googleClient->addScope("email");
    $googleClient->addScope("profile");
    
    // Handle the OAuth callback
    if (isset($_GET['code'])) {
        // Exchange authorization code for access token
        $token = $googleClient->fetchAccessTokenWithAuthCode($_GET['code']);
        
        if (!isset($token['error'])) {
            // Set access token
            $googleClient->setAccessToken($token);
            
            // Get user profile
            $googleService = new Google\Service\Oauth2($googleClient);
            $userInfo = $googleService->userinfo->get();
            
            // Extract user data
            $googleId = $userInfo->getId();
            $email = $userInfo->getEmail();
            $name = $userInfo->getName();
            $firstName = $userInfo->getGivenName();
            $lastName = $userInfo->getFamilyName();
            $picture = $userInfo->getPicture();
            
            // Check if user exists
            $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE google_id = ? OR email = ?");
            $stmt->execute([$googleId, $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                // User exists, update Google ID if needed
                if (empty($user['google_id'])) {
                    $updateStmt = $conn->prepare("UPDATE users SET google_id = ? WHERE id = ?");
                    $updateStmt->execute([$googleId, $user['id']]);
                }
                
                // Log the user in
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['logged_in'] = true;
                
                // Redirect to dashboard or previous page
                $redirectUrl = isset($_SESSION['prev_url']) ? $_SESSION['prev_url'] : 'dashboard.php';
                header("Location: $redirectUrl");
                exit();
            } else {
                // New user, register them
                // Generate a username from email
                $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
                
                // Check if username exists and append numbers if needed
                $baseUsername = $username;
                $counter = 1;
                
                while (true) {
                    $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
                    $checkStmt->execute([$username]);
                    
                    if ($checkStmt->rowCount() === 0) {
                        break;
                    }
                    
                    $username = $baseUsername . $counter;
                    $counter++;
                }
                
                // Generate a random password (user can reset it later)
                $password = bin2hex(random_bytes(8));
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert the new user
                $insertStmt = $conn->prepare("
                    INSERT INTO users (
                        username, email, password, google_id, profile_image, 
                        first_name, last_name, role, status, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'member', 'active', NOW())
                ");
                
                $insertStmt->execute([
                    $username, $email, $hashedPassword, $googleId, $picture,
                    $firstName, $lastName
                ]);
                
                $userId = $conn->lastInsertId();
                
                // Log the user in
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['role'] = 'member';
                $_SESSION['logged_in'] = true;
                
                // Redirect to complete profile page
                header("Location: complete_profile.php");
                exit();
            }
        } else {
            throw new Exception("Error getting access token: " . $token['error']);
        }
    } else if (isset($_GET['error'])) {
        throw new Exception("OAuth error: " . $_GET['error']);
    } else {
        throw new Exception("Invalid OAuth callback");
    }
} catch (Exception $e) {
    $_SESSION['error'] = "Google sign-in failed: " . $e->getMessage();
    error_log("Google OAuth error: " . $e->getMessage());
    header("Location: login.php");
    exit();
}
