<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();
$auth = new Auth($conn);

// Fetch system settings
$stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('site_name', 'logo_path', 'favicon_path')");
$stmt->execute();
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$siteName = $settings['site_name'] ?? 'Features Gym';
$logoPath = $settings['logo_path'] ?? 'assets/images/logo.png';

$error = '';
$success = '';

// Get the base URL
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$baseDir = dirname($_SERVER['PHP_SELF']);
if ($baseDir == '/' || $baseDir == '\\') {
    $baseDir = '';
}
$baseUrl .= $baseDir;

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $userType = $_POST['user_type'] ?? 'member'; // Default to member
    
    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } else {
        $loginSuccess = false;
        
        if ($userType === 'gym_owner') {
            // Try gym owner login
            $loginSuccess = $auth->loginGymOwner($email, $password);
            if ($loginSuccess) {
                header("Location: $baseUrl/gym/dashboard.php");
                exit;
            }
        } else {
            // Try regular user login
            $loginSuccess = $auth->login($email, $password);
            if ($loginSuccess) {
                // Redirect based on role
                if ($_SESSION['role'] === 'admin') {
                    header("Location: $baseUrl/admin/dashboard.php");
                } else {
                    header("Location: $baseUrl/dashboard.php");
                }
                exit;
            }
        }
        
        if (!$loginSuccess) {
            $error = "Invalid email or password. Please try again.";
        }
    }
}

// Check for messages from other pages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
  <title>Login | <?= htmlspecialchars($siteName) ?></title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <meta name="description" content="Login to your <?= htmlspecialchars($siteName) ?> account and start your fitness journey today.">
  <?php if (!empty($settings['favicon_path'])): ?>
  <link rel="icon" href="<?= htmlspecialchars($settings['favicon_path']) ?>" type="image/x-icon">
  <?php endif; ?>

  <!-- Preload critical assets -->
  <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/webfonts/fa-solid-900.woff2" as="font" type="font/woff2" crossorigin>
  <style>
    /* Custom styles */
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
  </style>
</head>
<body class="bg-gradient-to-br from-indigo-50 to-blue-50 min-h-screen">
  <div class="container mx-auto px-4 py-12 sm:px-6 lg:px-8">
    <!-- Header with logo -->
    <div class="flex justify-center mb-8">
      <div class="logo-container">
        <div class="w-20 h-20 bg-gradient-to-r from-indigo-600 to-blue-500 rounded-xl flex items-center justify-center shadow-lg">
          <div class="relative">
            <span class="text-white text-3xl font-bold">FG</span>
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
              <h2 class="text-3xl font-bold mb-6">Welcome Back!</h2>
              <p class="mb-8 text-indigo-100">Log in to your <?= htmlspecialchars($siteName) ?> account and continue your fitness journey.</p>
              
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
              <p class="text-sm text-indigo-200">Don't have an account? <a href="register.php" class="text-white font-medium underline">Sign up here</a></p>
            </div>
          </div>
        </div>
        
        <!-- Right side - Login form -->
        <div class="col-span-1 lg:col-span-3 p-8 md:p-12">
          <h2 class="text-2xl font-bold text-gray-800 mb-6">Login to Your Account</h2>
          
          <?php if ($error): ?>
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
          
          <?php if ($success): ?>
          <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-md">
            <div class="flex">
              <div class="flex-shrink-0">
                <i class="fas fa-check-circle text-green-500"></i>
              </div>
              <div class="ml-3">
                <p class="text-sm"><?php echo htmlspecialchars($success); ?></p>
              </div>
            </div>
          </div>
          <?php endif; ?>
          
          <!-- User Type Selector -->
          <div class="flex justify-center mb-6">
            <div class="inline-flex rounded-md shadow-sm" role="group">
              <button type="button" id="memberBtn" 
                class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-l-lg focus:z-10 focus:ring-2 focus:ring-indigo-400 active-tab">
                Member
              </button>
              <button type="button" id="gymOwnerBtn"
                class="px-4 py-2 text-sm font-medium text-white bg-gray-700 rounded-r-lg focus:z-10 focus:ring-2 focus:ring-indigo-400">
                Gym Owner
              </button>
            </div>
          </div>
          
          <form id="login-form" method="POST" action="" class="space-y-6">
            <input type="hidden" name="user_type" id="userType" value="member">
            
            <!-- Email -->
            <div>
              <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address <span class="text-red-500">*</span></label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <i class="fas fa-envelope text-gray-400"></i>
                </div>
                <input type="email" id="email" name="email" required
                  class="w-full pl-10 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200"
                  placeholder="Enter your email">
              </div>
            </div>
            
            <!-- Password -->
            <div>
              <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password <span class="text-red-500">*</span></label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <i class="fas fa-lock text-gray-400"></i>
                </div>
                <input type="password" id="password" name="password" required
                  class="w-full pl-10 px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200"
                  placeholder="Enter your password">
                <span class="password-toggle" id="password-toggle">
                  <i class="far fa-eye"></i>
                </span>
              </div>
            </div>
            
            <!-- Remember me & Forgot password -->
            <div class="flex items-center justify-between">
              <div class="flex items-center">
                <input id="remember" name="remember" type="checkbox" 
                  class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                <label for="remember" class="ml-2 block text-sm text-gray-700">
                  Remember me
                </label>
              </div>
              
              <a href="forgot-password.php" class="text-sm font-medium text-indigo-600 hover:text-indigo-500">
                Forgot your password?
              </a>
            </div>
            
            <!-- Submit Button -->
            <div>
              <button type="submit"
                class="w-full bg-gradient-to-r from-indigo-600 to-blue-500 text-white py-3 px-4 rounded-lg shadow-md hover:from-indigo-700 hover:to-blue-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all duration-200 flex items-center justify-center">
                <span>Sign In</span>
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
                Sign in with Google
              </button>
              <p class="text-xs text-gray-500 text-center">Google sign-in is currently unavailable</p>
            </div>
          </div>

          <!-- Registration Links -->
          <div class="flex flex-col sm:flex-row justify-between items-center mt-6 text-center sm:text-left">
            <a href="gym/register.html"
              class="w-full sm:w-auto mb-3 sm:mb-0 inline-flex justify-center items-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition duration-150 ease-in-out">
              <i class="fas fa-dumbbell mr-2"></i>
              Register as Gym Partner
            </a>

            <p class="text-sm text-gray-600">
              Don't have an account?
              <a href="register.php" class="font-medium text-indigo-600 hover:text-indigo-500">
                Sign up here
              </a>
            </p>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Footer -->
    <div class="mt-8 text-center text-sm text-gray-500">
      <p>&copy; <?php echo date('Y'); ?> <?= htmlspecialchars($siteName) ?>. All rights reserved.</p>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const passwordInput = document.getElementById('password');
      const passwordToggle = document.getElementById('password-toggle');
      const form = document.getElementById('login-form');
      const errorMessage = document.getElementById('error-message');
      
      // User type toggle
      const memberBtn = document.getElementById('memberBtn');
      const gymOwnerBtn = document.getElementById('gymOwnerBtn');
      const userTypeInput = document.getElementById('userType');
      
      // Toggle password visibility
      if (passwordToggle) {
        passwordToggle.addEventListener('click', function() {
          const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
          passwordInput.setAttribute('type', type);
          this.querySelector('i').classList.toggle('fa-eye');
          this.querySelector('i').classList.toggle('fa-eye-slash');
        });
      }
      
      // User type toggle functionality
      memberBtn.addEventListener('click', function() {
        memberBtn.classList.add('bg-indigo-600');
        memberBtn.classList.remove('bg-gray-700');
        gymOwnerBtn.classList.add('bg-gray-700');
        gymOwnerBtn.classList.remove('bg-indigo-600');
        userTypeInput.value = 'member';
      });
      
      gymOwnerBtn.addEventListener('click', function() {
        gymOwnerBtn.classList.add('bg-indigo-600');
        gymOwnerBtn.classList.remove('bg-gray-700');
        memberBtn.classList.add('bg-gray-700');
        memberBtn.classList.remove('bg-indigo-600');
        userTypeInput.value = 'gym_owner';
      });
      
      // Form validation
      if (form) {
        form.addEventListener('submit', function(event) {
          const email = document.getElementById('email').value.trim();
          const password = passwordInput.value;
          
          if (!email || !password) {
            event.preventDefault();
            showError("Please enter both email and password.");
            return;
          }
          
          // Validate email format
          const isValidEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
          if (!isValidEmail) {
            event.preventDefault();
            showError("Please enter a valid email address.");
            return;
          }
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
      
      // Add CSRF protection for forms
      const csrfToken = '<?php echo bin2hex(random_bytes(32)); ?>';
      const csrfInput = document.createElement('input');
      csrfInput.type = 'hidden';
      csrfInput.name = 'csrf_token';
      csrfInput.value = csrfToken;
      form.appendChild(csrfInput);
      
      // Store token in session storage for validation
      sessionStorage.setItem('csrf_token', csrfToken);
      
      // Auto-focus email field
      document.getElementById('email').focus();
      
      // Add login attempt tracking
      const maxLoginAttempts = <?php 
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'login_attempts_limit'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo $result ? $result['setting_value'] : 5; 
      ?>;
      
      let loginAttempts = parseInt(localStorage.getItem('loginAttempts') || '0');
      
      if (loginAttempts >= maxLoginAttempts) {
        const lockoutTime = <?php 
          $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'account_lockout_time'");
          $stmt->execute();
          $result = $stmt->fetch(PDO::FETCH_ASSOC);
          echo $result ? $result['setting_value'] : 30; 
        ?>;
        
        const lockoutTimestamp = parseInt(localStorage.getItem('lockoutTimestamp') || '0');
        const currentTime = Math.floor(Date.now() / 1000);
        
        if (lockoutTimestamp > 0 && currentTime - lockoutTimestamp < lockoutTime * 60) {
          const remainingTime = Math.ceil((lockoutTimestamp + lockoutTime * 60 - currentTime) / 60);
          
          // Disable form and show lockout message
          document.getElementById('email').disabled = true;
          document.getElementById('password').disabled = true;
          document.querySelector('button[type="submit"]').disabled = true;
          
          showError(`Too many failed login attempts. Please try again in ${remainingTime} minutes.`);
        } else {
          // Reset login attempts if lockout period has passed
          localStorage.setItem('loginAttempts', '0');
          localStorage.removeItem('lockoutTimestamp');
        }
      }
      
      <?php if ($error): ?>
      // Increment login attempts on failed login
      loginAttempts++;
      localStorage.setItem('loginAttempts', loginAttempts.toString());
      
      if (loginAttempts >= maxLoginAttempts) {
        localStorage.setItem('lockoutTimestamp', Math.floor(Date.now() / 1000).toString());
      }
      <?php endif; ?>
      
      <?php if ($success): ?>
      // Reset login attempts on successful login
      localStorage.setItem('loginAttempts', '0');
      localStorage.removeItem('lockoutTimestamp');
      <?php endif; ?>
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
      
      // Check for session timeout
      const sessionTimeout = <?php 
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'session_timeout'");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo $result ? $result['setting_value'] : 60; 
      ?>;
      
      const lastActivity = sessionStorage.getItem('lastActivityTime');
      if (lastActivity) {
        const currentTime = Math.floor(Date.now() / 1000);
        if (currentTime - parseInt(lastActivity) > sessionTimeout * 60) {
          // Session timeout occurred
          const timeoutMessage = document.createElement('div');
          timeoutMessage.className = 'bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-6 rounded-md';
          timeoutMessage.innerHTML = `
            <div class="flex">
              <div class="flex-shrink-0">
                <i class="fas fa-clock text-yellow-500"></i>
              </div>
              <div class="ml-3">
                <p class="text-sm">Your session has expired due to inactivity. Please log in again.</p>
              </div>
            </div>
          `;
          
          const formElement = document.getElementById('login-form');
          formElement.parentNode.insertBefore(timeoutMessage, formElement);
          
          // Clear session data
          sessionStorage.removeItem('lastActivityTime');
        }
      }
    });
  </script>
</body>
</html>

