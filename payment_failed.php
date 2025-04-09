<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth_check.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please login to view payment details.";
    header('Location: login.php');
    exit();
}

// Validate form submission
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method.";
    header('Location: membership.php');
    exit();
}

// Get error details from POST
$error_code = $_POST['error_code'] ?? '';
$error_description = $_POST['error_description'] ?? '';
$error_source = $_POST['error_source'] ?? '';
$error_step = $_POST['error_step'] ?? '';
$error_reason = $_POST['error_reason'] ?? '';
$order_id = $_POST['order_id'] ?? '';
$membership_id = $_POST['membership_id'] ?? '';
$payment_id = $_POST['payment_id'] ?? '';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

try {
    // Begin transaction
    $conn->beginTransaction();
    
    // Update payment record
    $stmt = $conn->prepare("
        UPDATE payments 
        SET status = 'failed', notes = ? 
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([
        json_encode([
            'error_code' => $error_code,
            'error_description' => $error_description,
            'error_source' => $error_source,
            'error_step' => $error_step,
            'error_reason' => $error_reason,
            'order_id' => $order_id
        ]),
        $payment_id,
        $_SESSION['user_id']
    ]);
    
    // Update membership status
    $stmt = $conn->prepare("
        UPDATE user_memberships 
        SET payment_status = 'failed'
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$membership_id, $_SESSION['user_id']]);
    
    // Log the failed payment
    $stmt = $conn->prepare("
        INSERT INTO activity_logs (
            user_id, user_type, action, details, ip_address, user_agent
        ) VALUES (
            ?, 'member', 'payment_failed', ?, ?, ?
        )
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        "Payment failed for membership ID: $membership_id, Error: $error_description, Code: $error_code, Reason: $error_reason",
        $_SERVER['REMOTE_ADDR'],
        $_SERVER['HTTP_USER_AGENT']
    ]);
    
    // Commit transaction
    $conn->commit();
    
    // Clear order from session
    unset($_SESSION['razorpay_order']);
    
    // Set error message
    $_SESSION['error'] = "Payment failed: $error_description. Please try again or contact support.";
    
    // Redirect to membership page
    header('Location: buy_membership.php?plan_id=' . $_SESSION['razorpay_order']['plan_id'] . '&gym_id=' . $_SESSION['razorpay_order']['gym_id']);
    exit();
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    
    // Log the error
    error_log("Payment failure processing error: " . $e->getMessage());
    
    $_SESSION['error'] = "An error occurred while processing your payment failure. Please contact support.";
    header('Location: membership.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Failed - Fitness Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-900 text-white">

<?php include 'includes/navbar.php'; ?>

<div class="min-h-screen py-12">
    <div class="max-w-3xl mx-auto px-4">
        <div class="text-center mb-8">
            <div class="inline-block p-4 rounded-full bg-red-100 text-red-600 mb-4">
                <i class="fas fa-times-circle text-5xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-white">Payment Failed</h1>
            <p class="text-gray-400 mt-2">We couldn't process your payment</p>
        </div>
        
        <div class="bg-gray-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-8">
                <div class="bg-red-900 bg-opacity-50 text-red-200 p-6 rounded-xl mb-6">
                    <h3 class="font-bold text-lg mb-2">Error Details</h3>
                    <p><?php echo htmlspecialchars($error_description); ?></p>
                    <?php if ($error_reason): ?>
                        <p class="mt-2"><strong>Reason:</strong> <?php echo htmlspecialchars($error_reason); ?></p>
                    <?php endif; ?>
                </div>
                
                <div class="space-y-6">
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">What happened?</h3>
                        <p class="text-gray-300">
                            Your payment couldn't be processed due to the error shown above. This could be due to insufficient funds, 
                            card restrictions, or other payment-related issues.
                        </p>
                    </div>
                    
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">What can you do?</h3>
                        <ul class="list-disc list-inside text-gray-300 space-y-2">
                            <li>Try again with a different payment method</li>
                            <li>Check with your bank if there are any restrictions on your card</li>
                            <li>Ensure you have sufficient funds in your account</li>
                            <li>Contact our support team if the issue persists</li>
                        </ul>
                    </div>
                </div>
                
                <div class="mt-8 flex flex-col sm:flex-row justify-center space-y-4 sm:space-y-0 sm:space-x-4">
                    <a href="buy_membership.php?plan_id=<?php echo $_SESSION['razorpay_order']['plan_id']; ?>&gym_id=<?php echo $_SESSION['razorpay_order']['gym_id']; ?>" class="bg-yellow-500 hover:bg-yellow-400 text-black font-bold px-6 py-3 rounded-lg text-center transition-colors">
                        <i class="fas fa-redo mr-2"></i>Try Again
                    </a>
                    <a href="contact.php" class="bg-gray-700 hover:bg-gray-600 text-white font-bold px-6 py-3 rounded-lg text-center transition-colors">
                        <i class="fas fa-headset mr-2"></i>Contact Support
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Back Button -->
        <div class="mt-8 text-center">
            <a href="membership.php" class="text-gray-400 hover:text-white transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>Back to Memberships
            </a>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

</body>
</html>
