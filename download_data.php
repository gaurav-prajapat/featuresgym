<?php
ob_start();
require_once 'config/database.php';
include 'includes/navbar.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$error_message = '';

try {
    // Verify CSRF token if form submitted
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            throw new Exception("Security validation failed. Please try again.");
        }
        
        // Verify password
        $password = $_POST['password'] ?? '';
        if (empty($password)) {
            throw new Exception("Password is required to download your data.");
        }
        
        // Check if password is correct
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();
        
        if (!password_verify($password, $hash)) {
            throw new Exception("Incorrect password. Please try again.");
        }
        
        // Password verified, proceed with data export
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="flexfit_personal_data_' . date('Y-m-d') . '.json"');
        
        // Begin transaction for consistent reads
        $conn->beginTransaction();
        
        // Fetch user data
        $userStmt = $conn->prepare("
            SELECT id, username, email, phone, city, role, status, created_at, updated_at, balance, age
            FROM users 
            WHERE id = ?
        ");
        $userStmt->execute([$user_id]);
        $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch memberships
        $membershipStmt = $conn->prepare("
            SELECT um.id, um.gym_id, g.name as gym_name, um.plan_id, gmp.plan_name, 
                   um.start_date, um.end_date, um.status, um.amount, um.payment_status,
                   um.created_at
            FROM user_memberships um
            JOIN gyms g ON um.gym_id = g.gym_id
            JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
            WHERE um.user_id = ?
            ORDER BY um.created_at DESC
        ");
        $membershipStmt->execute([$user_id]);
        $memberships = $membershipStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch schedules
        $scheduleStmt = $conn->prepare("
            SELECT s.id, s.gym_id, g.name as gym_name, s.activity_type, 
                   s.start_date, s.end_date, s.start_time, s.status,
                   s.check_in_time, s.check_out_time, s.created_at
            FROM schedules s
            JOIN gyms g ON s.gym_id = g.gym_id
            WHERE s.user_id = ?
            ORDER BY s.created_at DESC
        ");
        $scheduleStmt->execute([$user_id]);
        $schedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch payments
        $paymentStmt = $conn->prepare("
            SELECT p.id, p.gym_id, g.name as gym_name, p.amount, p.base_amount,
                   p.discount_amount, p.payment_method, p.transaction_id,
                   p.status, p.payment_date, p.created_at
            FROM payments p
            JOIN gyms g ON p.gym_id = g.gym_id
            WHERE p.user_id = ?
            ORDER BY p.created_at DESC
        ");
        $paymentStmt->execute([$user_id]);
        $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch reviews
        $reviewStmt = $conn->prepare("
            SELECT r.id, r.gym_id, g.name as gym_name, r.rating, r.comment,
                   r.visit_date, r.status, r.created_at
            FROM reviews r
            JOIN gyms g ON r.gym_id = g.gym_id
            WHERE r.user_id = ?
            ORDER BY r.created_at DESC
        ");
        $reviewStmt->execute([$user_id]);
        $reviews = $reviewStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch notifications
        $notificationStmt = $conn->prepare("
            SELECT id, type, title, message, status, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
        ");
        $notificationStmt->execute([$user_id]);
        $notifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch tournament participations
        $tournamentStmt = $conn->prepare("
            SELECT tp.id, tp.tournament_id, gt.title as tournament_name, 
                   gt.tournament_type, gt.start_date, gt.end_date,
                   tp.payment_status, tp.registration_date
            FROM tournament_participants tp
            JOIN gym_tournaments gt ON tp.tournament_id = gt.id
            WHERE tp.user_id = ?
            ORDER BY tp.registration_date DESC
        ");
        $tournamentStmt->execute([$user_id]);
        $tournaments = $tournamentStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch login history
        $loginStmt = $conn->prepare("
            SELECT ip_address, user_agent, login_time, logout_time
            FROM login_history
            WHERE user_id = ? AND user_type = 'member'
            ORDER BY login_time DESC
        ");
        $loginStmt->execute([$user_id]);
        $loginHistory = $loginStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch activity logs
        $activityStmt = $conn->prepare("
            SELECT action, details, ip_address, created_at
            FROM activity_logs
            WHERE user_id = ? AND user_type = 'member'
            ORDER BY created_at DESC
        ");
        $activityStmt->execute([$user_id]);
        $activityLogs = $activityStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Commit transaction
        $conn->commit();
        
        // Prepare data for export
        $exportData = [
            'personal_information' => $userData,
            'memberships' => $memberships,
            'schedules' => $schedules,
            'payments' => $payments,
            'reviews' => $reviews,
            'notifications' => $notifications,
            'tournaments' => $tournaments,
            'login_history' => $loginHistory,
            'activity_logs' => $activityLogs,
            'export_date' => date('Y-m-d H:i:s'),
            'export_requested_by' => $userData['username']
        ];
        
        // Log this activity
        $logStmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'member', 'data_export', 'User downloaded personal data', ?, ?)
        ");
        $logStmt->execute([
            $user_id,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        // Output JSON and exit
        echo json_encode($exportData, JSON_PRETTY_PRINT);
        exit;
    }
    
    // Generate CSRF token if not exists
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
} catch (Exception $e) {
    $error_message = $e->getMessage();
    
    // Log the error
    error_log("Data download error: " . $e->getMessage());
    
    // Rollback transaction if active
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
}
?>

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black pt-24 pb-12">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 max-w-4xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold text-white">Download Your Data</h1>
            <a href="settings.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                <i class="fas fa-arrow-left mr-2"></i> Back to Settings
            </a>
        </div>
        
        <?php if (!empty($error_message)): ?>
            <div class="bg-red-500 bg-opacity-80 text-white p-4 rounded-xl mb-6 animate-fade-in">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error_message) ?>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden shadow-xl">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-bold text-white">Personal Data Export</h2>
                <p class="text-gray-400 text-sm">Download a copy of your personal data in JSON format</p>
            </div>
            
            <div class="p-6">
                <div class="bg-gray-700 bg-opacity-50 rounded-xl p-6 mb-6">
                    <h3 class="text-lg font-semibold text-white mb-4">What's included in your data export?</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex items-start">
                            <div class="bg-gray-600 p-2 rounded-lg mr-3">
                                <i class="fas fa-user text-yellow-500"></i>
                            </div>
                            <div>
                                <h4 class="text-white font-medium">Personal Information</h4>
                                <p class="text-gray-400 text-sm">Your profile details and account information</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-gray-600 p-2 rounded-lg mr-3">
                                <i class="fas fa-id-card text-yellow-500"></i>
                            </div>
                            <div>
                                <h4 class="text-white font-medium">Memberships</h4>
                                <p class="text-gray-400 text-sm">Your current and past gym memberships</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-gray-600 p-2 rounded-lg mr-3">
                                <i class="fas fa-calendar-alt text-yellow-500"></i>
                            </div>
                            <div>
                                <h4 class="text-white font-medium">Schedules</h4>
                                <p class="text-gray-400 text-sm">Your gym visit schedules and history</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-gray-600 p-2 rounded-lg mr-3">
                                <i class="fas fa-credit-card text-yellow-500"></i>
                            </div>
                            <div>
                                <h4 class="text-white font-medium">Payments</h4>
                                <p class="text-gray-400 text-sm">Your payment history and transactions</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-gray-600 p-2 rounded-lg mr-3">
                                <i class="fas fa-star text-yellow-500"></i>
                            </div>
                            <div>
                                <h4 class="text-white font-medium">Reviews</h4>
                                <p class="text-gray-400 text-sm">Reviews you've left for gyms</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-gray-600 p-2 rounded-lg mr-3">
                                <i class="fas fa-bell text-yellow-500"></i>
                            </div>
                            <div>
                                <h4 class="text-white font-medium">Notifications</h4>
                                <p class="text-gray-400 text-sm">Your notification history</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-gray-600 p-2 rounded-lg mr-3">
                                <i class="fas fa-trophy text-yellow-500"></i>
                            </div>
                            <div>
                                <h4 class="text-white font-medium">Tournaments</h4>
                                <p class="text-gray-400 text-sm">Tournaments you've participated in</p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-gray-600 p-2 rounded-lg mr-3">
                                <i class="fas fa-history text-yellow-500"></i>
                            </div>
                            <div>
                                <h4 class="text-white font-medium">Activity Logs</h4>
                                <p class="text-gray-400 text-sm">History of your account activity</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-yellow-500 bg-opacity-10 border border-yellow-500 rounded-lg p-4 mb-6">
                    <div class="flex items-start">
                        <div class="text-yellow-500 mr-3">
                            <i class="fas fa-info-circle text-xl"></i>
                        </div>
                        <div>
                            <h4 class="text-yellow-500 font-medium">Privacy Notice</h4>
                            <p class="text-gray-300 text-sm">
                                For security reasons, you'll need to verify your password before downloading your data. 
                                The downloaded file will contain personal information. Please store it securely and do not share it with others.
                            </p>
                        </div>
                    </div>
                </div>
                
                <form method="POST" action="download_data.php">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    
                    <div class="mb-6">
                        <label for="password" class="block text-sm font-medium text-gray-400 mb-1">Verify your password</label>
                        <input type="password" id="password" name="password" required
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        <p class="text-sm text-gray-500 mt-1">Enter your current password to verify your identity</p>
                    </div>
                    
                    <div class="flex items-center mb-6">
                        <input type="checkbox" id="confirm_download" name="confirm_download" required class="w-4 h-4 text-yellow-600 border-gray-600 rounded focus:ring-yellow-500">
                        <label for="confirm_download" class="ml-2 text-sm text-gray-300">
                            I understand that this file contains my personal data and I will keep it secure
                        </label>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black font-medium px-6 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-download mr-2"></i> Download My Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="mt-8 bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden shadow-xl">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-bold text-white">Data Usage Information</h2>
                <p class="text-gray-400 text-sm">How we use and protect your data</p>
            </div>
            
            <div class="p-6">
                <div class="space-y-6">
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">How We Use Your Data</h3>
                        <p class="text-gray-300">
                            At FlexFit, we collect and process your personal data to provide you with the best gym experience. 
                            Your data helps us personalize your experience, process payments, manage your memberships, 
                            and keep you informed about your schedules and activities.
                        </p>
                    </div>
                    
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">Data Retention</h3>
                        <p class="text-gray-300">
                            We retain your personal data for as long as your account is active or as needed to provide you services. 
                            If you delete your account, we will delete or anonymize your personal data within 30 days, 
                            except where we are required to retain it for legal or regulatory purposes.
                        </p>
                    </div>
                    
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">Your Data Rights</h3>
                        <p class="text-gray-300">
                            You have the right to access, correct, or delete your personal data. You can download a copy of your data using this tool, 
                            update your information in your account settings, or request deletion of your account.
                        </p>
                    </div>
                    
                    <div class="pt-4 border-t border-gray-700">
                        <a href="privacy.php" class="text-yellow-500 hover:text-yellow-400 transition-colors duration-200">
                            View our full Privacy Policy <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Form validation
    const downloadForm = document.querySelector('form');
    const passwordInput = document.getElementById('password');
    const confirmCheckbox = document.getElementById('confirm_download');
    
    if (downloadForm) {
        downloadForm.addEventListener('submit', function(e) {
            // Validate password
            if (!passwordInput.value.trim()) {
                e.preventDefault();
                passwordInput.classList.add('border-red-500');
                
                // Add error message if not exists
                let errorMsg = passwordInput.parentNode.querySelector('.text-red-500');
                if (!errorMsg) {
                    errorMsg = document.createElement('p');
                    errorMsg.className = 'text-sm text-red-500 mt-1';
                    errorMsg.textContent = "Password is required to verify your identity";
                    passwordInput.parentNode.appendChild(errorMsg);
                }
                
                return false;
            }
            
            // Validate checkbox
            if (!confirmCheckbox.checked) {
                e.preventDefault();
                
                // Add error message if not exists
                let errorMsg = confirmCheckbox.parentNode.parentNode.querySelector('.text-red-500');
                if (!errorMsg) {
                    errorMsg = document.createElement('p');
                    errorMsg.className = 'text-sm text-red-500 mt-1';
                    errorMsg.textContent = "You must confirm that you understand the data security implications";
                    confirmCheckbox.parentNode.parentNode.appendChild(errorMsg);
                }
                
                return false;
            }
            
            // Show loading state
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
        });
        
        // Clear validation errors on input
        passwordInput.addEventListener('input', function() {
            this.classList.remove('border-red-500');
            const errorMsg = this.parentNode.querySelector('.text-red-500');
            if (errorMsg) {
                errorMsg.remove();
            }
        });
        
        confirmCheckbox.addEventListener('change', function() {
            const errorMsg = this.parentNode.parentNode.querySelector('.text-red-500');
            if (errorMsg) {
                errorMsg.remove();
            }
        });
    }
</script>

