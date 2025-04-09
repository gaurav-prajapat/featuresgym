<?php
require_once 'config/database.php';
require_once 'vendor/autoload.php';
include 'includes/navbar.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Create a log function for production (writes to file but doesn't expose details)
function payment_log($message, $data = null) {
    $log_message = date('Y-m-d H:i:s') . " - " . $message;
    if ($data !== null) {
        $log_message .= " - Data: " . json_encode($data);
    }
    
    // Write to a dedicated payment log file
    file_put_contents(__DIR__ . '/payment.log', $log_message . PHP_EOL, FILE_APPEND);
}

// Payment verification section
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get the raw POST data
    $raw_post = file_get_contents('php://input');
    $input = json_decode($raw_post, true);
    
    payment_log("Payment verification request received", [
        'payment_id' => $input['razorpay_payment_id'] ?? 'not set',
        'order_id' => $input['razorpay_order_id'] ?? 'not set'
    ]);
    
    if (isset($input['razorpay_payment_id']) && isset($input['razorpay_order_id']) && isset($input['razorpay_signature'])) {
        $razorpay_payment_id = $input['razorpay_payment_id'];
        $razorpay_order_id = $input['razorpay_order_id'];
        $razorpay_signature = $input['razorpay_signature'];
        $membership_id = $input['membership_id'];
        $start_date = $input['start_date'];
        $end_date = $input['end_date'];
        
        $keyId = $_ENV['RAZORPAY_KEY_ID'];
        $keySecret = $_ENV['RAZORPAY_KEY_SECRET'];
        
        try {
            // Verify signature
            $generated_signature = hash_hmac('sha256', $razorpay_order_id . "|" . $razorpay_payment_id, $keySecret);
            
            if ($generated_signature == $razorpay_signature) {
                payment_log("Signature verified successfully for payment", [
                    'payment_id' => $razorpay_payment_id,
                    'membership_id' => $membership_id
                ]);
                
                // Start transaction for critical updates
                $conn->beginTransaction();
                
                // Check if membership exists
                $check_stmt = $conn->prepare("SELECT id, status, payment_status FROM user_memberships WHERE id = ?");
                $check_stmt->execute([$membership_id]);
                $membership_check = $check_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$membership_check) {
                    throw new Exception("Membership not found with ID: " . $membership_id);
                }
                
                // Update membership status
                $stmt = $conn->prepare("
                    UPDATE user_memberships 
                    SET status = 'active', payment_status = 'paid', 
                        payment_id = ?, payment_date = NOW()
                    WHERE id = ?
                ");
                $result = $stmt->execute([$razorpay_payment_id, $membership_id]);
                
                if (!$result) {
                    throw new Exception("Failed to update membership: " . implode(", ", $stmt->errorInfo()));
                }
                
                // Update payment record
                $stmt = $conn->prepare("
                    UPDATE payments 
                    SET status = 'completed', payment_id = ?, 
                        payment_date = NOW()
                    WHERE transaction_id = ?
                ");
                $result = $stmt->execute([$razorpay_payment_id, $razorpay_order_id]);
                
                if (!$result) {
                    throw new Exception("Failed to update payment: " . implode(", ", $stmt->errorInfo()));
                }
                
                // Commit the critical updates
                $conn->commit();
                payment_log("Payment records updated successfully", [
                    'payment_id' => $razorpay_payment_id,
                    'membership_id' => $membership_id
                ]);
                
                // Create notification for user - outside the main transaction
                try {
                    $message = "Your membership has been activated successfully. Valid from " . 
                               date('d M Y', strtotime($start_date)) . " to " . 
                               date('d M Y', strtotime($end_date)) . ".";
                    
                    // Check the structure of the notifications table
                    $tableInfo = $conn->query("DESCRIBE notifications");
                    $columns = $tableInfo->fetchAll(PDO::FETCH_COLUMN);
                    
                    // Build a dynamic query based on the actual table structure
                    $sql = "INSERT INTO notifications (user_id";
                    $params = [$_SESSION['user_id']];
                    
                    if (in_array('type', $columns)) {
                        $sql .= ", type";
                        $params[] = 'membership_activated';
                    }
                    
                    $sql .= ", message";
                    $params[] = $message;
                    
                    if (in_array('related_id', $columns)) {
                        $sql .= ", related_id";
                        $params[] = $membership_id;
                    }
                    
                    $sql .= ", created_at, is_read) VALUES (?";
                    
                    // Add placeholders for each parameter
                    for ($i = 1; $i < count($params); $i++) {
                        $sql .= ", ?";
                    }
                    
                    $sql .= ", NOW(), 0)";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($params);
                    
                    payment_log("Notification created successfully");
                } catch (Exception $e) {
                    // Just log the notification error but don't fail the payment
                    payment_log("Failed to create notification: " . $e->getMessage());
                }
                
                // Return success response
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit();
            } else {
                payment_log("Signature verification failed", [
                    'payment_id' => $razorpay_payment_id,
                    'order_id' => $razorpay_order_id
                ]);
                
                // Invalid signature - payment verification failed
                $conn->beginTransaction();
                
                // Update membership status to failed
                $stmt = $conn->prepare("
                    UPDATE user_memberships 
                    SET payment_status = 'failed'
                    WHERE id = ?
                ");
                $stmt->execute([$membership_id]);
                
                // Update payment record
                $stmt = $conn->prepare("
                    UPDATE payments 
                    SET status = 'failed', notes = 'Signature verification failed'
                    WHERE transaction_id = ?
                ");
                $stmt->execute([$razorpay_order_id]);
                
                $conn->commit();
                
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid payment signature']);
                exit();
            }
        } catch (Exception $e) {
            // Handle errors
            payment_log("Exception during payment processing: " . $e->getMessage());
            
            if ($conn->inTransaction()) {
                $conn->rollBack();
                payment_log("Transaction rolled back");
            }
            
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Payment processing error']);
            exit();
        }
    } else {
        payment_log("Missing payment data in request");
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Missing payment data']);
        exit();
    }
}

// Display success page if membership_id is provided in GET
if (isset($_GET['membership_id'])) {
    $membership_id = filter_input(INPUT_GET, 'membership_id', FILTER_VALIDATE_INT);
    
    // Fetch membership details
    $stmt = $conn->prepare("
        SELECT 
            um.*, 
            g.name as gym_name, 
            g.address, 
            g.city,
            g.cover_photo,
            gmp.tier as plan_name,
            gmp.duration as plan_duration,
            gmp.inclusions
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
    
    // Calculate price breakdown
    $base_price = $membership['base_price'] ?? $membership['amount'];
    $discount_amount = $membership['discount_amount'] ?? 0;
    $coupon_code = $membership['coupon_code'] ?? '';
    $gateway_tax = $membership['gateway_tax'] ?? 0;
    $govt_tax = $membership['govt_tax'] ?? 0;
    $subtotal = $base_price - $discount_amount;
    $total_price = $membership['amount'];
    
    // Format dates
    $start_date = date('d M Y', strtotime($membership['start_date']));
    $end_date = date('d M Y', strtotime($membership['end_date']));
} else {
    header('Location: dashboard.php');
    exit();
}
?>

<div class="min-h-screen bg-gray-900 py-12">
    <div class="max-w-3xl mx-auto px-4">
        <div class="bg-gray-800 rounded-2xl shadow-xl overflow-hidden text-white">
            <!-- Success Header -->
            <div class="relative h-40 bg-gradient-to-r from-green-600 to-green-800">
                <?php if (!empty($membership['cover_photo'])): ?>
                    <img src="./gym/uploads/gym_images/<?php echo htmlspecialchars($membership['cover_photo']); ?>" 
                         class="w-full h-full object-cover opacity-30" alt="Gym Cover">
                <?php endif; ?>
                <div class="absolute inset-0 bg-black bg-opacity-50 flex flex-col items-center justify-center">
                    <div class="bg-green-500 rounded-full p-3 mb-3">
                        <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold text-white text-center">
                        Payment Successful!
                    </h1>
                </div>
            </div>

            <!-- Membership Details -->
            <div class="p-8">
                <div class="text-center mb-8">
                    <p class="text-gray-300 text-lg">
                        Your membership has been activated successfully.
                    </p>
                    <p class="text-yellow-400 font-medium mt-2">
                        Membership ID: #<?php echo $membership_id; ?>
                    </p>
                </div>
                
                <!-- Gym and Plan Info -->
                <div class="mb-6">
                    <h2 class="text-xl font-bold text-white mb-2">
                        <?php echo htmlspecialchars($membership['gym_name']); ?>
                    </h2>
                    <p class="text-gray-400">
                        <?php echo htmlspecialchars($membership['address'] . ', ' . $membership['city']); ?>
                    </p>
                </div>
                
                <!-- Membership Summary Card -->
                <div class="bg-gray-700 rounded-xl p-6 mb-8">
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-gray-300">Plan</span>
                        <span class="font-semibold text-white"><?php echo htmlspecialchars($membership['plan_name']); ?></span>
                    </div>
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-gray-300">Duration</span>
                        <span class="font-semibold text-white"><?php echo htmlspecialchars($membership['plan_duration']); ?></span>
                    </div>
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-gray-300">Start Date</span>
                        <span class="font-semibold text-white"><?php echo $start_date; ?></span>
                    </div>
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-gray-300">End Date</span>
                        <span class="font-semibold text-white"><?php echo $end_date; ?></span>
                    </div>
                    
                    <!-- Price Breakdown -->
                    <div class="border-t border-gray-600 pt-4 mt-4">
                        <h5 class="text-sm font-medium text-gray-300 mb-3">Payment Details</h5>
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-400">Base Price</span>
                                <span class="text-white">₹<?php echo number_format($base_price, 2); ?></span>
                            </div>
                            
                            <?php if ($discount_amount > 0): ?>
                            <div class="flex justify-between text-sm">
                                <span class="text-green-400">
                                    Coupon Discount
                                    <?php if (!empty($coupon_code)): ?>
                                        (<?php echo htmlspecialchars($coupon_code); ?>)
                                    <?php endif; ?>
                                </span>
                                <span class="text-green-400">-₹<?php echo number_format($discount_amount, 2); ?></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-400">Subtotal</span>
                                <span class="text-white">₹<?php echo number_format($subtotal, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($gateway_tax > 0): ?>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-400">Payment Gateway Fee</span>
                                <span class="text-white">₹<?php echo number_format($gateway_tax, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($govt_tax > 0): ?>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-400">GST</span>
                                <span class="text-white">₹<?php echo number_format($govt_tax, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="flex justify-between text-base font-bold pt-2 border-t border-gray-600">
                                <span class="text-gray-300">Total Paid</span>
                                <span class="text-yellow-400">₹<?php echo number_format($total_price, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Plan Features -->
                <div class="bg-gray-700 rounded-xl p-6 mb-8">
                    <h4 class="font-semibold text-white mb-4">Membership Inclusions</h4>
                    <div class="space-y-3">
                        <?php 
                        $inclusions = explode(',', $membership['inclusions']);
                        foreach ($inclusions as $inclusion): ?>
                            <div class="flex items-center text-gray-300">
                                <svg class="w-5 h-5 text-yellow-400 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <?php echo htmlspecialchars(trim($inclusion)); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row space-y-4 sm:space-y-0 sm:space-x-4">
                    <a href="dashboard.php" 
                       class="flex-1 bg-gray-600 text-white py-3 px-6 rounded-full font-bold text-center
                              hover:bg-gray-700 transform hover:scale-105 transition-all duration-300">
                        Go to Dashboard
                    </a>
                    <a href="view_membership.php?id=<?php echo $membership_id; ?>" 
                       class="flex-1 bg-gradient-to-r from-yellow-400 to-yellow-500 text-black py-3 px-6 rounded-full font-bold text-center
                              hover:from-yellow-500 hover:to-yellow-600 transform hover:scale-105 transition-all duration-300">
                        View Membership
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Optional: Show a success message when the page loads
    document.addEventListener('DOMContentLoaded', function() {
        Sweetalert2.fire({
            icon: 'success',
            title: 'Payment Successful!',
            text: 'Your membership has been activated successfully.',
            confirmButtonColor: '#F59E0B'
        });
    });
</script>

