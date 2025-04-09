<?php
require_once 'config/database.php';
include 'includes/navbar.php';

// Create a log function
function payment_log($message, $data = null) {
    $log_message = date('Y-m-d H:i:s') . " - " . $message;
    if ($data !== null) {
        $log_message .= " - Data: " . json_encode($data);
    }
    file_put_contents(__DIR__ . '/payment.log', $log_message . PHP_EOL, FILE_APPEND);
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

$error_message = "Your payment could not be processed.";
$membership_id = null;

if (isset($_GET['membership_id'])) {
    $membership_id = filter_input(INPUT_GET, 'membership_id', FILTER_VALIDATE_INT);
    
    // Fetch membership details
    $stmt = $conn->prepare("
        SELECT 
            um.*, 
            g.name as gym_name, 
            gmp.plan_name as plan_name
        FROM user_memberships um
        JOIN gyms g ON um.gym_id = g.gym_id
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        WHERE um.id = ? AND um.user_id = ?
    ");
    $stmt->execute([$membership_id, $_SESSION['user_id']]);
    $membership = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$membership) {
        header('Location: error.php?message=membership_not_found');
        exit();
    }
    
    // Update membership status to failed if it's still pending
    if ($membership['payment_status'] === 'pending') {
        $stmt = $conn->prepare("
            UPDATE user_memberships 
            SET payment_status = 'failed'
            WHERE id = ? AND user_id = ? AND payment_status = 'pending'
        ");
        $stmt->execute([$membership_id, $_SESSION['user_id']]);
        
        payment_log("Updated membership status to failed", [
            'membership_id' => $membership_id,
            'user_id' => $_SESSION['user_id']
        ]);
        
        // Update payment record
        $stmt = $conn->prepare("
            UPDATE payments 
            SET status = 'failed', notes = ?
            WHERE membership_id = ? AND status = 'pending'
        ");
        $stmt->execute([
            isset($_GET['error']) ? $_GET['error'] : 'Payment failed',
            $membership_id
        ]);
        
        payment_log("Updated payment record to failed", [
            'membership_id' => $membership_id,
            'error' => $_GET['error'] ?? 'Payment failed'
        ]);
    }
    
    if (isset($_GET['error'])) {
        $error_message = urldecode($_GET['error']);
    }
}
?>

<div class="min-h-screen bg-gray-900 py-12">
    <div class="max-w-3xl mx-auto px-4">
        <div class="bg-gray-800 rounded-2xl shadow-xl overflow-hidden text-white">
            <!-- Failure Header -->
            <div class="bg-gradient-to-r from-red-700 to-red-900 p-8 text-center">
                <div class="bg-red-600 rounded-full p-3 inline-flex mb-3">
                    <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-white">
                    Payment Failed
                </h1>
            </div>

            <!-- Error Details -->
            <div class="p-8">
                <div class="text-center mb-8">
                    <p class="text-gray-300 text-lg">
                        <?php echo htmlspecialchars($error_message); ?>
                    </p>
                    <?php if ($membership_id): ?>
                    <p class="text-red-400 font-medium mt-2">
                        Membership ID: #<?php echo $membership_id; ?>
                    </p>
                    <?php endif; ?>
                </div>
                
                <?php if (isset($membership)): ?>
                <!-- Membership Info -->
                <div class="bg-gray-700 rounded-xl p-6 mb-8">
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-gray-300">Gym</span>
                        <span class="font-semibold text-white"><?php echo htmlspecialchars($membership['gym_name']); ?></span>
                    </div>
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-gray-300">Plan</span>
                        <span class="font-semibold text-white"><?php echo htmlspecialchars($membership['plan_name']); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-300">Amount</span>
                        <span class="font-semibold text-white">₹<?php echo number_format($membership['amount'], 2); ?></span>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Common Payment Issues -->
                <div class="bg-gray-700 rounded-xl p-6 mb-8">
                    <h4 class="font-semibold text-white mb-4">Common Payment Issues</h4>
                    <ul class="space-y-3 text-gray-300">
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-red-400 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            <span>Insufficient funds in your account</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-red-400 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            <span>Card declined by your bank</span>
                        </li>
                        <li class="flex items-start">
                            <svg class="w-5 h-5 text-red-400 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            <span>Network or connectivity issues</span>
                        </li>
                    </ul>
                </div>
                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-4">
                    <a href="dashboard.php" 
                       class="flex-1 bg-gray-600 text-white py-3 px-6 rounded-full font-bold text-center
                              hover:bg-gray-700 transform hover:scale-105 transition-all duration-300">
                        Go to Dashboard
                    </a>
                    <?php if ($membership_id): ?>
                    <a href="verify_payment.php?membership_id=<?php echo $membership_id; ?>&amount=<?php echo round($membership['amount'] * 100); ?>&plan_name=<?php echo urlencode($membership['plan_name']); ?>&gym_id=<?php echo $membership['gym_id']; ?>&plan_id=<?php echo $membership['plan_id']; ?>&user_id=<?php echo $_SESSION['user_id']; ?>&plan_price=<?php echo $membership['amount']; ?>&plan_duration=<?php echo urlencode($membership['plan_duration'] ?? ''); ?>&start_date=<?php echo $membership['start_date']; ?>&end_date=<?php echo $membership['end_date']; ?>" 
                       class="flex-1 bg-gradient-to-r from-yellow-400 to-yellow-500 text-black py-3 px-6 rounded-full font-bold text-center
                              hover:from-yellow-500 hover:to-yellow-600 transform hover:scale-105 transition-all duration-300">
                        Try Again
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// Clear any stored payment data
localStorage.removeItem('razorpay_response');
localStorage.removeItem('membership_id');
</script>

               
