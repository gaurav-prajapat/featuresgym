<?php
session_start();
require_once 'includes/auth.php';
require_once 'config/database.php';
require_once 'includes/EmailService.php';
require_once 'includes/OTPService.php';

// Set environment to production
define('ENVIRONMENT', 'production');

// Load environment variables
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        try {
            $dotenv->load();
            
            // SMTP Configuration
            $smtpHost = $_ENV['SMTP_HOST'] ?? '';
            $smtpPort = $_ENV['SMTP_PORT'] ?? 587;
            $smtpUsername = $_ENV['SMTP_USERNAME'] ?? '';
            $smtpPassword = $_ENV['SMTP_PASSWORD'] ?? '';
            $smtpEncryption = $_ENV['SMTP_ENCRYPTION'] ?? 'tls';
            $smtpFromEmail = $_ENV['SMTP_FROM_EMAIL'] ?? '';
            $smtpFromName = $_ENV['SMTP_FROM_NAME'] ?? 'ProFitMart';
            
            // Google OAuth Configuration
            $googleClientId = $_ENV['GOOGLE_CLIENT_ID'] ?? '';
            $googleClientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
            $googleRedirectUrl = $_ENV['GOOGLE_REDIRECT_URI'] ?? '';
            
            // Razorpay Configuration (if needed)
            $razorpayKeyId = $_ENV['RAZORPAY_KEY_ID'] ?? '';
            $razorpayKeySecret = $_ENV['RAZORPAY_KEY_SECRET'] ?? '';
        } catch (Exception $e) {
            error_log("Error loading .env file: " . $e->getMessage());
            // Fallback to empty values if .env loading fails
            $smtpHost = '';
            $smtpPort = 587;
            $smtpUsername = '';
            $smtpPassword = '';
            $smtpEncryption = 'tls';
            $smtpFromEmail = '';
            $smtpFromName = 'ProFitMart';
            $googleClientId = '';
            $googleClientSecret = '';
            $googleRedirectUrl = '';
        }
    } else {
        error_log("Dotenv class not found. Make sure you've installed the vlucas/phpdotenv package.");
    }
} else {
    error_log("Vendor autoload.php not found. Please run 'composer require vlucas/phpdotenv'");
}

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();
$auth = new Auth($conn);
$otpService = new OTPService($conn);

// Initialize Google Client if credentials are available
$googleAuthUrl = "#";
$googleApiNotAvailable = true;

if (!empty($googleClientId) && !empty($googleClientSecret) && !empty($googleRedirectUrl)) {
    if (class_exists('Google_Client')) {
        $googleClient = new Google_Client();
        $googleClient->setClientId($googleClientId);
        $googleClient->setClientSecret($googleClientSecret);
        $googleClient->setRedirectUri($googleRedirectUrl);
        $googleClient->addScope("email");
        $googleClient->addScope("profile");

        // Generate Google auth URL
        $googleAuthUrl = $googleClient->createAuthUrl();
        $googleApiNotAvailable = false;
    } else {
        error_log("Google API Client library not found. Please install it using Composer.");
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if it's an OTP verification request
    if (isset($_POST['verify_otp'])) {
        $email = $_SESSION['temp_user_email'] ?? '';
        $submittedOTP = $_POST['otp'];

        if (empty($email)) {
            $error = "Session expired. Please try registering again.";
        } else {
            // Verify OTP
            $verificationResult = $otpService->verifyOTP($email, $submittedOTP);
            
            if ($verificationResult['success']) {
                // OTP verified, proceed with registration
                try {
                    $userData = $_SESSION['temp_user_data'] ?? [];
                    
                    if (empty($userData)) {
                        throw new Exception("Registration data not found. Please try again.");
                    }
                    
                    $username = $userData['username'];
                    $email = $userData['email'];
                    $password = $userData['password'];
                    $phone = $userData['phone'];
                    $city = $userData['city'] ?? null;
                    $role = 'member';

                    // Register user with updated schema fields
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Begin transaction
                    $conn->beginTransaction();
                    
                    // Insert into users table with fields from gym-p (2).sql
                    $stmt = $conn->prepare("
                        INSERT INTO users (
                            username, 
                            email, 
                            password, 
                            role, 
                            phone, 
                            city, 
                            status, 
                            created_at, 
                            updated_at,
                            balance
                        ) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW(), NOW(), 0.00)
                    ");
                    
                    $stmt->execute([
                        $username,
                        $email,
                        $hashedPassword,
                        $role,
                        $phone,
                        $city
                    ]);
                    
                    $userId = $conn->lastInsertId();
                    
                    // Log the registration activity
                    $stmt = $conn->prepare("
                        INSERT INTO activity_logs (
                            user_id, 
                            user_type, 
                            action, 
                            details, 
                            ip_address, 
                            user_agent, 
                            created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $stmt->execute([
                        $userId,
                        'member',
                        'register',
                        'User registration completed',
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    // Create welcome notification
                    $stmt = $conn->prepare("
                        INSERT INTO notifications (
                            user_id, 
                            type, 
                            title,
                            message, 
                            created_at, 
                            status,
                            gym_id,
                            is_read
                        ) VALUES (?, 'welcome', ?, ?, NOW(), 'unread', 0, 0)
                    ");
                    
                    $stmt->execute([
                        $userId,
                        'Welcome to ProFitMart!',
                        'Thank you for joining our community. Start exploring gyms and scheduling your workouts today!'
                    ]);
                    
                    $conn->commit();
                    
                    // Clear temporary session data
                    unset($_SESSION['temp_user_data']);
                    unset($_SESSION['temp_user_email']);
                    
                    $_SESSION['success'] = "Registration successful! Please login.";
                    $redirectUrl = isset($_SESSION['prev_url']) ? $_SESSION['prev_url'] : 'login.php';
                    header("Location: $redirectUrl");
                    exit();
                    
                } catch (Exception $e) {
                    $conn->rollBack();
                    $error = $e->getMessage();
                }
            } else {
                $error = $verificationResult['message'];
            }
        }
    } else if (isset($_POST['resend_otp'])) {
        // Handle OTP resend request
        $email = $_SESSION['temp_user_email'] ?? '';
        
        if (empty($email)) {
            $error = "Session expired. Please try registering again.";
        } else {
            // Generate and store new OTP
            $otp = $otpService->generateOTP();
            if ($otpService->storeOTP($email, $otp) && $otpService->sendOTPEmail($email, $otp)) {
                $otpResent = true;
            } else {
                $error = "Failed to resend verification code. Please try again.";
            }
        }
    } else {
        // Initial registration form submission
        try {
            $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'];
            $confirmPassword = $_POST['confirm_password'];
            $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
            $city = filter_input(INPUT_POST, 'city', FILTER_SANITIZE_STRING);
            
            // Check terms agreement
            if (!isset($_POST['terms']) || $_POST['terms'] !== 'on') {
                throw new Exception("You must agree to the Terms of Service and Privacy Policy");
            }

            // Validate input
            if (empty($username) || empty($email) || empty($password) || empty($confirmPassword)) {
                throw new Exception("All fields are required");
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception("Invalid email format");
            }

            if ($password !== $confirmPassword) {
                throw new Exception("Passwords do not match");
            }

            if (!preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/", $password)) {
                throw new Exception("Password must be at least 8 characters and contain uppercase, lowercase, number, and special character");
            }

            if (!empty($phone) && !preg_match("/^\d{10}$/", $phone)) {
                throw new Exception("Phone number must be 10 digits");
            }

            // Check if email already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->rowCount() > 0) {
                throw new Exception("Email already registered. Please use a different email or login.");
            }

            // Check if username already exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->rowCount() > 0) {
                throw new Exception("Username already taken. Please choose a different username.");
            }

            // Store user data temporarily
            $_SESSION['temp_user_data'] = [
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'phone' => $phone,
                'city' => $city
            ];
            $_SESSION['temp_user_email'] = $email;

            // Generate and send OTP
            $otp = $otpService->generateOTP();
            if ($otpService->storeOTP($email, $otp) && $otpService->sendOTPEmail($email, $otp)) {
                $showOTPForm = true;
            } else {
                throw new Exception("Failed to send verification code. Please try again.");
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <title>User Registration | ProFitMart</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <meta name="description" content="Create your account at ProFitMart and start your fitness journey today.">
  <link rel="icon" href="assets/img/favicon.ico" type="image/x-icon">
  <!-- Preload critical assets -->
  <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-solid-900.woff2" as="font" type="font/woff2" crossorigin>
  <style>
    /* Custom styles */
    .password-requirements {
      font-size: 0.8rem;
      color: #6b7280;
      margin-top: 0.5rem;
    }
    .password-requirements ul {
      list-style-type: none;
      padding-left: 0;
      margin-top: 0.25rem;
    }
    .password-requirements li {
      display: flex;
      align-items: center;
      margin-bottom: 0.25rem;
    }
    .password-requirements li i {
      margin-right: 0.5rem;
    }
    .valid-requirement {
      color: #10b981;
    }
    .invalid-requirement {
      color: #ef4444;
    }
    .password-toggle {
      position: absolute;
      right: 0.75rem;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      color: #6b7280;
    }
    
    /* Logo animation */
    .logo-container {
      animation: pulse 2s infinite alternate;
    }
    @keyframes pulse {
      0% { transform: scale(1); }
      100% { transform: scale(1.05); }
    }
    
    /* Custom scrollbar */
    ::-webkit-scrollbar {
      width: 8px;
    }
    ::-webkit-scrollbar-track {
      background: #f1f1f1;
    }
    ::-webkit-scrollbar-thumb {
      background: #6366f1;
      border-radius: 4px;
    }
    ::-webkit-scrollbar-thumb:hover {
      background: #4f46e5;
    }
    
    /* Form focus styles */
    input:focus, select:focus {
      box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
    }
    
    /* Custom file input */
    .custom-file-input::-webkit-file-upload-button {
      visibility: hidden;
      width: 0;
    }
    .custom-file-input::before {
      content: 'Choose file';
      display: inline-block;
      background: #f3f4f6;
      border: 1px solid #d1d5db;
      border-radius: 0.375rem;
      padding: 0.5rem 1rem;
      outline: none;
      white-space: nowrap;
      cursor: pointer;
      font-weight: 500;
      font-size: 0.875rem;
      color: #4b5563;
    }
    .custom-file-input:hover::before {
      background: #e5e7eb;
    }
    .custom-file-input:active::before {
      background: #d1d5db;
    }
  </style>
  </head>
<body class="bg-gradient-to-br from-indigo-50 to-blue-50 min-h-screen">
  <div class="container mx-auto px-4 py-12 sm:px-6 lg:px-8">
    <!-- Header with logo -->
    <div class="flex justify-center mb-8">
      <div class="logo-container">
        <div class="w-20 h-20 bg-gradient-to-r from-indigo-600 to-blue-500 rounded-xl flex items-center justify-center shadow-lg">
          <div class="relative">
            <span class="text-white text-3xl font-bold">PFM</span>
            <div class="absolute -bottom-1 -right-1 w-3 h-3 bg-orange-500 rounded-full"></div>
          </div>
        </div>
      </div>
    </div>
    
    <div class="max-w-6xl mx-auto bg-white rounded-2xl shadow-xl overflow-hidden">
      <div class="grid grid-cols-1 lg:grid-cols-5">
        <!-- Left side - Image and info -->
        <div class="hidden lg:block lg:col-span-2 bg-gradient-to-br from-indigo-600 to-blue-500 p-12 text-white">
          <div class="h-full flex flex-col justify-between">
            <div>
              <h2 class="text-3xl font-bold mb-6">Join Our Fitness Community</h2>
              <p class="mb-8 text-indigo-100">Register with ProFitMart and connect with fitness enthusiasts across the country.</p>
              
              <div class="space-y-6">
                <div class="flex items-start">
                  <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-2xl text-indigo-200"></i>
                  </div>
                  <div class="ml-4">
                    <h3 class="text-xl font-semibold">Find Nearby Gyms</h3>
                    <p class="text-indigo-100">Discover top-rated fitness centers in your area</p>
                  </div>
                </div>
                
                <div class="flex items-start">
                  <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-2xl text-indigo-200"></i>
                  </div>
                  <div class="ml-4">
                    <h3 class="text-xl font-semibold">Flexible Memberships</h3>
                    <p class="text-indigo-100">Choose from daily, weekly, monthly, or yearly plans</p>
                  </div>
                </div>
                
                <div class="flex items-start">
                  <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-2xl text-indigo-200"></i>
                  </div>
                  <div class="ml-4">
                    <h3 class="text-xl font-semibold">Secure Payments</h3>
                    <p class="text-indigo-100">Integrated payment gateway for hassle-free transactions</p>
                  </div>
                </div>
              </div>
            </div>
            
            <div class="mt-auto">
              <p class="text-sm text-indigo-200">Already have an account? <a href="login.php" class="text-white font-medium underline">Login here</a></p>
            </div>
          </div>
        </div>
        
        <!-- Right side - Registration form -->
        <div class="col-span-1 lg:col-span-3 p-8 md:p-12">
          <h2 class="text-2xl font-bold text-gray-800 mb-6">User Registration</h2>
          
          <?php if (isset($error)): ?>
          <div id="error-message" class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md" role="alert">
            <div class="flex">
              <div class="flex-shrink-0">
                <i class="fas fa-exclamation-circle text-red-500"></i>
              </div>
              <div class="ml-3">
                <p class="text-sm" id="error-text"><?php echo htmlspecialchars($error); ?></p>
              </div>
            </div>
          </div>
          <?php endif; ?>
          
          <?php if (isset($otpResent) && $otpResent): ?>
          <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md">
            <div class="flex">
              <div class="flex-shrink-0">
                <i class="fas fa-check-circle text-green-500"></i>
              </div>
              <div class="ml-3">
                <p class="text-sm">Verification code has been resent to your email.</p>
              </div>
            </div>
          </div>
          <?php endif; ?>
          
          <?php if (isset($_SESSION['success'])): ?>
          <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md">
            <div class="flex">
              <div class="flex-shrink-0">
                <i class="fas fa-check-circle text-green-500"></i>
              </div>
              <div class="ml-3">
                <p class="text-sm"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></p>
              </div>
            </div>
          </div>
          <?php endif; ?>
          
          <?php if (isset($showOTPForm) && $showOTPForm): ?>
            <!-- OTP Verification Form -->
            <form id="otpForm" action="register.php" method="POST" class="space-y-6">
              <div class="text-center mb-4">
                <p class="text-sm text-gray-600">We've sent a verification code to your email</p>
                <p class="font-medium text-gray-800">
                  <?php echo htmlspecialchars($_SESSION['temp_user_email']); ?>
                </p>
              </div>

              <div class="relative">
                <label for="otp" class="block text-sm font-medium text-gray-700 mb-1">Verification Code <span class="text-red-500">*</span></label>
                <input type="text" id="otp" name="otp" required
                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200"
                  placeholder="Enter 6-digit code">
              </div>

              <div>
                <input type="hidden" name="verify_otp" value="1">
                <button type="submit"
                  class="group relative w-full flex justify-center py-3 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition duration-150 ease-in-out">
                  <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                    <i class="fas fa-check-circle"></i>
                  </span>
                  Verify & Complete Registration
                </button>
              </div>

              <div class="text-center mt-4">
                <p class="text-sm text-gray-600">
                  Didn't receive the code?
                  <button type="submit" name="resend_otp" value="1" id="resendOTP" 
                    class="font-medium text-indigo-600 hover:text-indigo-500 focus:outline-none">
                    Resend Code
                  </button>
                </p>
              </div>
            </form>
          <?php else: ?>
            <!-- Registration Form -->
            <form id="registration-form" action="register.php" method="POST" class="space-y-6">
              <!-- Form grid layout -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Username -->
                <div class="col-span-2 sm:col-span-1">
                  <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username <span class="text-red-500">*</span></label>
                  <input type="text" id="username" name="username" required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200">
                </div>

                <!-- Email -->
                <div class="col-span-2 sm:col-span-1">
                  <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                  <input type="email" id="email" name="email" required
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200">
                </div>

                <!-- Phone -->
                <div class="col-span-2 sm:col-span-1">
                  <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Phone Number <span class="text-red-500">*</span></label>
                  <input type="tel" id="phone" name="phone" required pattern="[0-9]{10}"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200">
                  <p class="text-xs text-gray-500 mt-1">10-digit number without spaces or dashes</p>
                </div>

                <!-- City -->
                <div class="col-span-2 sm:col-span-1">
                  <label for="city" class="block text-sm font-medium text-gray-700 mb-1">City</label>
                  <input type="text" id="city" name="city"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200">
                </div>

                <!-- Password -->
                <div class="col-span-2 sm:col-span-1">
                  <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
                  <div class="relative">
                    <input type="password" id="password" name="password" required
                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200">
                    <span class="password-toggle" id="password-toggle">
                      <i class="far fa-eye"></i>
                    </span>
                  </div>
                  <div class="password-requirements mt-2">
                    <p class="font-medium text-xs text-gray-600">Password must contain:</p>
                    <ul class="grid grid-cols-1 sm:grid-cols-2 gap-x-2 gap-y-1 mt-1">
                      <li id="length-check" class="text-xs"><i class="fas fa-times"></i> 8+ characters</li>
                      <li id="uppercase-check" class="text-xs"><i class="fas fa-times"></i> Uppercase letter</li>
                      <li id="lowercase-check" class="text-xs"><i class="fas fa-times"></i> Lowercase letter</li>
                      <li id="number-check" class="text-xs"><i class="fas fa-times"></i> Number</li>
                      <li id="special-check" class="text-xs"><i class="fas fa-times"></i> Special character</li>
                    </ul>
                  </div>
                </div>

                <!-- Confirm Password -->
                <div class="col-span-2 sm:col-span-1">
                  <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password <span class="text-red-500">*</span></label>
                  <div class="relative">
                    <input type="password" id="confirm_password" name="confirm_password" required
                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200">
                    <span class="password-toggle" id="toggleConfirmPassword">
                      <i class="far fa-eye"></i>
                    </span>
                  </div>
                </div>

                <!-- Terms and Conditions -->
                <div class="col-span-2">
                  <div class="flex items-start">
                    <div class="flex items-center h-5">
                      <input id="terms" name="terms" type="checkbox" required
                        class="h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500">
                    </div>
                    <div class="ml-3 text-sm">
                      <label for="terms" class="font-medium text-gray-700">I agree to the <a href="terms.php" class="text-indigo-600 hover:text-indigo-500 underline">Terms of Service</a> and <a href="privacy.php" class="text-indigo-600 hover:text-indigo-500 underline">Privacy Policy</a></label>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Submit Button -->
              <div>
                <button type="submit"
                  class="w-full bg-gradient-to-r from-indigo-600 to-blue-500 text-white py-3 px-4 rounded-lg shadow-md hover:from-indigo-700 hover:to-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all duration-200 flex items-center justify-center">
                  <span>Register</span>
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 ml-2" viewBox="0 0 20 20" fill="currentColor">
                  <path fill-rule="evenodd" d="M10.293 5.293a1 1 0 011.414 0l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414-1.414L12.586 11H5a1 1 0 110-2h7.586l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd" />
                  </svg>
                </button>
              </div>
            </form>

            <!-- Social Login Options -->
            <div class="mt-6">
              <div class="relative">
                <div class="absolute inset-0 flex items-center">
                  <div class="w-full border-t border-gray-300"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                  <span class="px-2 bg-white text-gray-500">Or continue with</span>
                </div>
              </div>

              <div class="mt-6 grid grid-cols-1 gap-3">
                <?php if (!$googleApiNotAvailable): ?>
                <a href="<?php echo htmlspecialchars($googleAuthUrl); ?>" 
                  class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 transition duration-150 ease-in-out">
                  <svg class="h-5 w-5 mr-2" viewBox="0 0 24 24" width="24" height="24" xmlns="http://www.w3.org/2000/svg">
                    <g transform="matrix(1, 0, 0, 1, 27.009001, -39.238998)">
                      <path fill="#4285F4" d="M -3.264 51.509 C -3.264 50.719 -3.334 49.969 -3.454 49.239 L -14.754 49.239 L -14.754 53.749 L -8.284 53.749 C -8.574 55.229 -9.424 56.479 -10.684 57.329 L -10.684 60.329 L -6.824 60.329 C -4.564 58.239 -3.264 55.159 -3.264 51.509 Z"/>
                      <path fill="#34A853" d="M -14.754 63.239 C -11.514 63.239 -8.804 62.159 -6.824 60.329 L -10.684 57.329 C -11.764 58.049 -13.134 58.489 -14.754 58.489 C -17.884 58.489 -20.534 56.379 -21.484 53.529 L -25.464 53.529 L -25.464 56.619 C -23.494 60.539 -19.444 63.239 -14.754 63.239 Z"/>
                      <path fill="#FBBC05" d="M -21.484 53.529 C -21.734 52.809 -21.864 52.039 -21.864 51.239 C -21.864 50.439 -21.724 49.669 -21.484 48.949 L -21.484 45.859 L -25.464 45.859 C -26.284 47.479 -26.754 49.299 -26.754 51.239 C -26.754 53.179 -26.284 54.999 -25.464 56.619 L -21.484 53.529 Z"/>
                      <path fill="#EA4335" d="M -14.754 43.989 C -12.984 43.989 -11.404 44.599 -10.154 45.789 L -6.734 42.369 C -8.804 40.429 -11.514 39.239 -14.754 39.239 C -19.444 39.239 -23.494 41.939 -25.464 45.859 L -21.484 48.949 C -20.534 46.099 -17.884 43.989 -14.754 43.989 Z"/>
                    </g>
                  </svg>
                  Sign up with Google
                </a>
                <?php else: ?>
                <button disabled
                  class="w-full inline-flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm bg-gray-100 text-sm font-medium text-gray-500 cursor-not-allowed opacity-70">
                  <svg class="h-5 w-5 mr-2" viewBox="0 0 24 24" width="24" height="24" xmlns="http://www.w3.org/2000/svg">
                    <g transform="matrix(1, 0, 0, 1, 27.009001, -39.238998)">
                      <path fill="#4285F4" d="M -3.264 51.509 C -3.264 50.719 -3.334 49.969 -3.454 49.239 L -14.754 49.239 L -14.754 53.749 L -8.284 53.749 C -8.574 55.229 -9.424 56.479 -10.684 57.329 L -10.684 60.329 L -6.824 60.329 C -4.564 58.239 -3.264 55.159 -3.264 51.509 Z"/>
                      <path fill="#34A853" d="M -14.754 63.239 C -11.514 63.239 -8.804 62.159 -6.824 60.329 L -10.684 57.329 C -11.764 58.049 -13.134 58.489 -14.754 58.489 C -17.884 58.489 -20.534 56.379 -21.484 53.529 L -25.464 53.529 L -25.464 56.619 C -23.494 60.539 -19.444 63.239 -14.754 63.239 Z"/>
                      <path fill="#FBBC05" d="M -21.484 53.529 C -21.734 52.809 -21.864 52.039 -21.864 51.239 C -21.864 50.439 -21.724 49.669 -21.484 48.949 L -21.484 45.859 L -25.464 45.859 C -26.284 47.479 -26.754 49.299 -26.754 51.239 C -26.754 53.179 -26.284 54.999 -25.464 56.619 L -21.484 53.529 Z"/>
                      <path fill="#EA4335" d="M -14.754 43.989 C -12.984 43.989 -11.404 44.599 -10.154 45.789 L -6.734 42.369 C -8.804 40.429 -11.514 39.239 -14.754 39.239 C -19.444 39.239 -23.494 41.939 -25.464 45.859 L -21.484 48.949 C -20.534 46.099 -17.884 43.989 -14.754 43.989 Z"/>
                    </g>
                  </svg>
                  Sign up with Google
                </button>
                <p class="text-xs text-gray-500 text-center">Google sign-in is currently unavailable</p>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <!-- Registration Links -->
          <div class="flex flex-col sm:flex-row justify-between items-center mt-6 text-center sm:text-left">
            <a href="gym/register.html"
              class="w-full sm:w-auto mb-3 sm:mb-0 inline-flex justify-center items-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-150 ease-in-out">
              <i class="fas fa-dumbbell mr-2"></i>
              Register as Gym Partner
            </a>

            <p class="text-sm text-gray-600">
              Already have an account?
              <a href="login.php" class="font-medium text-indigo-600 hover:text-indigo-500">
                Login here
              </a>
            </p>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Footer -->
    <div class="mt-8 text-center text-sm text-gray-500">
      <p>&copy; <?php echo date('Y'); ?> ProFitMart. All rights reserved.</p>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const passwordInput = document.getElementById('password');
      const passwordToggle = document.getElementById('password-toggle');
      const lengthCheck = document.getElementById('length-check');
      const uppercaseCheck = document.getElementById('uppercase-check');
      const lowercaseCheck = document.getElementById('lowercase-check');
      const numberCheck = document.getElementById('number-check');
      const specialCheck = document.getElementById('special-check');
      const form = document.getElementById('registration-form');
      const errorMessage = document.getElementById('error-message');
      const confirmPasswordField = document.getElementById('confirm_password');
      const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
      const resendOTPBtn = document.getElementById('resendOTP');

      // Toggle password visibility
      if (passwordToggle) {
        passwordToggle.addEventListener('click', function() {
          const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
          passwordInput.setAttribute('type', type);
          this.querySelector('i').classList.toggle('fa-eye');
          this.querySelector('i').classList.toggle('fa-eye-slash');
        });
      }

      if (toggleConfirmPassword) {
        toggleConfirmPassword.addEventListener('click', function() {
          const type = confirmPasswordField.getAttribute('type') === 'password' ? 'text' : 'password';
          confirmPasswordField.setAttribute('type', type);
          this.querySelector('i').classList.toggle('fa-eye');
          this.querySelector('i').classList.toggle('fa-eye-slash');
        });
      }

      // Password validation
      if (passwordInput) {
        passwordInput.addEventListener('input', function() {
          const password = passwordInput.value;
          
          // Check length
          if (password.length >= 8) {
            lengthCheck.innerHTML = '<i class="fas fa-check valid-requirement"></i> 8+ characters';
            lengthCheck.classList.add('valid-requirement');
            lengthCheck.classList.remove('invalid-requirement');
          } else {
            lengthCheck.innerHTML = '<i class="fas fa-times invalid-requirement"></i> 8+ characters';
            lengthCheck.classList.add('invalid-requirement');
            lengthCheck.classList.remove('valid-requirement');
          }
          
          // Check uppercase
          if (/[A-Z]/.test(password)) {
            uppercaseCheck.innerHTML = '<i class="fas fa-check valid-requirement"></i> Uppercase letter';
            uppercaseCheck.classList.add('valid-requirement');
            uppercaseCheck.classList.remove('invalid-requirement');
          } else {
            uppercaseCheck.innerHTML = '<i class="fas fa-times invalid-requirement"></i> Uppercase letter';
            uppercaseCheck.classList.add('invalid-requirement');
            uppercaseCheck.classList.remove('valid-requirement');
          }
          
          // Check lowercase
          if (/[a-z]/.test(password)) {
            lowercaseCheck.innerHTML = '<i class="fas fa-check valid-requirement"></i> Lowercase letter';
            lowercaseCheck.classList.add('valid-requirement');
            lowercaseCheck.classList.remove('invalid-requirement');
          } else {
            lowercaseCheck.innerHTML = '<i class="fas fa-times invalid-requirement"></i> Lowercase letter';
            lowercaseCheck.classList.add('invalid-requirement');
            lowercaseCheck.classList.remove('valid-requirement');
          }
          
          // Check number
          if (/[0-9]/.test(password)) {
            numberCheck.innerHTML = '<i class="fas fa-check valid-requirement"></i> Number';
            numberCheck.classList.add('valid-requirement');
            numberCheck.classList.remove('invalid-requirement');
          } else {
            numberCheck.innerHTML = '<i class="fas fa-times invalid-requirement"></i> Number';
            numberCheck.classList.add('invalid-requirement');
            numberCheck.classList.remove('valid-requirement');
          }
          
          // Check special character
          if (/[^A-Za-z0-9]/.test(password)) {
            specialCheck.innerHTML = '<i class="fas fa-check valid-requirement"></i> Special character';
            specialCheck.classList.add('valid-requirement');
            specialCheck.classList.remove('invalid-requirement');
          } else {
            specialCheck.innerHTML = '<i class="fas fa-times invalid-requirement"></i> Special character';
            specialCheck.classList.add('invalid-requirement');
            specialCheck.classList.remove('valid-requirement');
          }
        });
      }

      // Form submission validation
      if (form) {
        form.addEventListener('submit', function(event) {
          // Prevent default form submission
          event.preventDefault();
          
          // Get form values
          const password = passwordInput.value;
          const confirmPassword = confirmPasswordField.value;
          const email = document.getElementById('email').value;
          const phone = document.getElementById('phone').value;
          const termsCheckbox = document.getElementById('terms');
          
          // Validate password meets all requirements
          const isValidPassword = 
            password.length >= 8 && 
            /[A-Z]/.test(password) && 
            /[a-z]/.test(password) && 
            /[0-9]/.test(password) && 
            /[^A-Za-z0-9]/.test(password);
          
          // Validate email format
          const isValidEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
          
          // Validate phone number (10 digits)
          const isValidPhone = /^[0-9]{10}$/.test(phone);
          
          // Display error message if validation fails
          if (!isValidPassword) {
            showError("Password does not meet all requirements.");
            return;
          }
          
          if (password !== confirmPassword) {
            showError("Passwords do not match.");
            return;
          }
          
          if (!isValidEmail) {
            showError("Please enter a valid email address.");
            return;
          }
          
          if (!isValidPhone) {
            showError("Please enter a valid 10-digit phone number.");
            return;
          }
          
          if (!termsCheckbox.checked) {
            showError("You must agree to the Terms of Service and Privacy Policy.");
            return;
          }
          
          // If all validations pass, submit the form
          form.submit();
        });
      }
      
      // Function to show error message
      function showError(message) {
        if (!errorMessage) {
          // Create error message container if it doesn't exist
          const newErrorMessage = document.createElement('div');
          newErrorMessage.id = 'error-message';
          newErrorMessage.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-md';
          newErrorMessage.setAttribute('role', 'alert');
          
          const flexDiv = document.createElement('div');
          flexDiv.className = 'flex';
          
          const iconDiv = document.createElement('div');
          iconDiv.className = 'flex-shrink-0';
          iconDiv.innerHTML = '<i class="fas fa-exclamation-circle text-red-500"></i>';
          
          const textDiv = document.createElement('div');
          textDiv.className = 'ml-3';
          
          const errorText = document.createElement('p');
          errorText.id = 'error-text';
          errorText.className = 'text-sm';
          errorText.textContent = message;
          
          textDiv.appendChild(errorText);
          flexDiv.appendChild(iconDiv);
          flexDiv.appendChild(textDiv);
          newErrorMessage.appendChild(flexDiv);
          
          // Insert at the top of the form
          form.parentNode.insertBefore(newErrorMessage, form);
          
          // Scroll to error message
          newErrorMessage.scrollIntoView({ behavior: 'smooth', block: 'center' });
          
          // Hide error after 5 seconds
          setTimeout(function() {
            newErrorMessage.remove();
          }, 5000);
        } else {
          // Update existing error message
          const errorText = document.getElementById('error-text');
          if (errorText) {
            errorText.textContent = message;
          }
          
          // Make sure error is visible
          errorMessage.classList.remove('hidden');
          
          // Scroll to error message
          errorMessage.scrollIntoView({ behavior: 'smooth', block: 'center' });
          
          // Hide error after 5 seconds
          setTimeout(function() {
            errorMessage.classList.add('hidden');
          }, 5000);
        }
      }
      
      // Real-time validation for email
      const emailInput = document.getElementById('email');
      if (emailInput) {
        emailInput.addEventListener('blur', function() {
          const email = emailInput.value.trim();
          if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            emailInput.classList.add('border-red-500');
            emailInput.classList.remove('border-gray-300');
            
            // Add error message below the input
            let errorElement = emailInput.nextElementSibling;
            if (!errorElement || !errorElement.classList.contains('email-error')) {
              errorElement = document.createElement('p');
              errorElement.classList.add('email-error', 'text-xs', 'text-red-500', 'mt-1');
              emailInput.parentNode.insertBefore(errorElement, emailInput.nextSibling);
            }
            errorElement.textContent = 'Please enter a valid email address';
          } else {
            emailInput.classList.remove('border-red-500');
            emailInput.classList.add('border-gray-300');
            
            // Remove error message if it exists
            const errorElement = emailInput.nextElementSibling;
            if (errorElement && errorElement.classList.contains('email-error')) {
              errorElement.remove();
            }
          }
        });
      }
      
      // Real-time validation for phone
      const phoneInput = document.getElementById('phone');
      if (phoneInput) {
        phoneInput.addEventListener('input', function() {
          // Remove any non-numeric characters
          this.value = this.value.replace(/\D/g, '');
          
          // Limit to 10 digits
          if (this.value.length > 10) {
            this.value = this.value.slice(0, 10);
          }
        });
        
        phoneInput.addEventListener('blur', function() {
          const phone = phoneInput.value.trim();
          if (phone && phone.length !== 10) {
            phoneInput.classList.add('border-red-500');
            phoneInput.classList.remove('border-gray-300');
            
            // Add error message below the input
            let errorElement = phoneInput.nextElementSibling;
            if (!errorElement || !errorElement.classList.contains('phone-error')) {
              errorElement = document.createElement('p');
              errorElement.classList.add('phone-error', 'text-xs', 'text-red-500', 'mt-1');
              phoneInput.parentNode.insertBefore(errorElement, phoneInput.nextSibling);
            }
            errorElement.textContent = 'Phone number must be exactly 10 digits';
          } else {
            phoneInput.classList.remove('border-red-500');
            phoneInput.classList.add('border-gray-300');
            
            // Remove error message if it exists
            const errorElement = phoneInput.nextElementSibling;
            if (errorElement && errorElement.classList.contains('phone-error')) {
              errorElement.remove();
            }
          }
        });
      }
      
      // Add countdown timer for OTP resend
      if (resendOTPBtn) {
        let countdown = 60;
        let timer;
        
        // Disable resend button initially and start countdown
        resendOTPBtn.disabled = true;
        resendOTPBtn.classList.add('opacity-50', 'cursor-not-allowed');
        resendOTPBtn.textContent = `Resend Code (${countdown}s)`;
        
        timer = setInterval(() => {
          countdown--;
          resendOTPBtn.textContent = `Resend Code (${countdown}s)`;
          
          if (countdown <= 0) {
            clearInterval(timer);
            resendOTPBtn.disabled = false;
            resendOTPBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            resendOTPBtn.textContent = 'Resend Code';
          }
        }, 1000);
        
        // Reset timer when resend button is clicked
        resendOTPBtn.addEventListener('click', function() {
          if (!this.disabled) {
            countdown = 60;
            this.disabled = true;
            this.classList.add('opacity-50', 'cursor-not-allowed');
            this.textContent = `Resend Code (${countdown}s)`;
            
            timer = setInterval(() => {
              countdown--;
              this.textContent = `Resend Code (${countdown}s)`;
              
              if (countdown <= 0) {
                clearInterval(timer);
                this.disabled = false;
                this.classList.remove('opacity-50', 'cursor-not-allowed');
                this.textContent = 'Resend Code';
              }
            }, 1000);
          }
        });
      }
      
      // Password strength meter
      if (passwordInput) {
        const strengthMeter = document.createElement('div');
        strengthMeter.className = 'w-full h-2 mt-1 rounded-full overflow-hidden bg-gray-200';

        const strengthBar = document.createElement('div');
        strengthBar.className = 'h-full bg-red-500 transition-all duration-300';
        strengthBar.style.width = '0%';

        strengthMeter.appendChild(strengthBar);
        
        // Insert after password requirements
        const passwordRequirements = document.querySelector('.password-requirements');
        if (passwordRequirements) {
          passwordRequirements.after(strengthMeter);
        }

        passwordInput.addEventListener('input', function() {
          const password = this.value;
          let strength = 0;
          
          if (password.length >= 8) strength += 1;
          if (password.match(/[a-z]+/)) strength += 1;
          if (password.match(/[A-Z]+/)) strength += 1;
          if (password.match(/[0-9]+/)) strength += 1;
          if (password.match(/[^a-zA-Z0-9]+/)) strength += 1;

          // Update strength bar
          const percentage = (strength / 5) * 100;
          strengthBar.style.width = `${percentage}%`;
          
          // Update color based on strength
          if (strength <= 1) {
            strengthBar.className = 'h-full bg-red-500 transition-all duration-300';
          } else if (strength <= 3) {
            strengthBar.className = 'h-full bg-yellow-500 transition-all duration-300';
          } else {
            strengthBar.className = 'h-full bg-green-500 transition-all duration-300';
          }
        });
      }
    });
    
    // Optimize page load performance
    window.addEventListener('load', function() {
      // Lazy load background image for larger screens
      if (window.innerWidth >= 1024) { // lg breakpoint
        const bgSection = document.querySelector('.lg\\:block.lg\\:col-span-2');
        if (bgSection) {
          bgSection.style.opacity = '0';
          bgSection.style.transition = 'opacity 0.5s ease-in-out';
          
          setTimeout(() => {
            bgSection.style.opacity = '1';
          }, 100);
        }
      }
      
      // Add CSRF protection for forms
      const forms = document.querySelectorAll('form');
      forms.forEach(form => {
        // Check if the form doesn't already have a CSRF token
        if (!form.querySelector('input[name="csrf_token"]')) {
          const csrfToken = '<?php echo bin2hex(random_bytes(32)); ?>';
          const csrfInput = document.createElement('input');
          csrfInput.type = 'hidden';
          csrfInput.name = 'csrf_token';
          csrfInput.value = csrfToken;
          form.appendChild(csrfInput);
          
          // Store token in session storage for validation
          sessionStorage.setItem('csrf_token', csrfToken);
        }
      });
    });
  </script>
</body>
</html>



