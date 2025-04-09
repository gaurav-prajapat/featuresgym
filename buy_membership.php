<?php 
include 'includes/navbar.php';
require_once 'config/database.php';
require_once 'vendor/autoload.php';

require_once 'includes/csrf.php'; // Add CSRF protection

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

// Validate and sanitize input parameters
$plan_id = filter_input(INPUT_GET, 'plan_id', FILTER_VALIDATE_INT);
$gym_id = filter_input(INPUT_GET, 'gym_id', FILTER_VALIDATE_INT);

if (!$plan_id || !$gym_id) {
    $_SESSION['error_message'] = "Invalid plan or gym selected.";
    header('Location: all-gyms.php');
    exit();
}

// Generate CSRF token
$csrf = new CSRF();
$token = $csrf->getToken();

$db = new GymDatabase();
$conn = $db->getConnection();

// Fetch payment settings from database instead of .env file
$stmt = $conn->prepare("SELECT setting_key, setting_value FROM payment_settings");
$stmt->execute();
$payment_settings = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $payment_settings[$row['setting_key']] = $row['setting_value'];
}

// Get Razorpay key ID from database
$razorpay_key_id = $payment_settings['razorpay_key_id'] ?? '';
$test_mode = ($payment_settings['test_mode'] ?? '0') == '1';
$payment_gateway_enabled = ($payment_settings['payment_gateway_enabled'] ?? '0') == '1';

// Fetch plan and gym details with prepared statement
$stmt = $conn->prepare("
    SELECT 
        gmp.*,
        g.name as gym_name,
        g.address,
        g.city,
        g.cover_photo
    FROM gym_membership_plans gmp
    JOIN gyms g ON gmp.gym_id = g.gym_id
    WHERE gmp.plan_id = ? AND g.gym_id = ? AND g.status = 'active'
");
$stmt->execute([$plan_id, $gym_id]);
$plan = $stmt->fetch(PDO::FETCH_ASSOC);

// Verify plan exists and is active
if (!$plan) {
    $_SESSION['error_message'] = "The selected plan is not available.";
    header('Location: gym-profile.php?id=' . $gym_id);
    exit();
}

// Fetch tax settings from the database
$stmt = $conn->prepare("SELECT * FROM tax_settings WHERE is_active = 1");
$stmt->execute();
$taxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Initialize tax variables
$gateway_tax_rate = 2;
$govt_tax_rate = 0;
$gateway_tax_name = "Payment Gateway Fee";
$govt_tax_name = "GST";

// Set tax rates from database
foreach ($taxes as $tax) {
    if ($tax['tax_type'] === 'payment_gateway') {
        $gateway_tax_rate = $tax['tax_rate'];
        $gateway_tax_name = $tax['tax_name'];
    } elseif ($tax['tax_type'] === 'government') {
        $govt_tax_rate = $tax['tax_rate'];
        $govt_tax_name = $tax['tax_name'];
    }
}

// Define duration days mapping
$duration_days = [
    'Daily' => 1,
    'Weekly' => 7,
    'Monthly' => 30,
    'Quarterly' => 90,
    'Half Yearly' => 180,
    'Yearly' => 365
];

// Calculate price per day for daily plans
$price_per_day = 0;
if ($plan['duration'] === 'Daily') {
    $price_per_day = $plan['price'];
}

// Set default values
$start_date = date('Y-m-d');
$num_days = $duration_days[$plan['duration']] ?? 1;
$base_price = $plan['price'];
$total_price = $base_price;
$coupon_code = '';
$discount_amount = 0;
$coupon_error = '';
$coupon_success = '';

// Process form if submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!$csrf->verifyToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = "Security validation failed. Please try again.";
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit();
    }
    
    if (isset($_POST['calculate'])) {
        // Validate start date
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || strtotime($start_date) < strtotime(date('Y-m-d'))) {
            $start_date = date('Y-m-d');
        }
        
        if ($plan['duration'] === 'Daily' && isset($_POST['num_days'])) {
            $num_days = filter_input(INPUT_POST, 'num_days', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30, 'default' => 1]]);
            $base_price = $price_per_day * $num_days;
        }
        
        // Preserve coupon code if it was entered
        if (isset($_POST['coupon_code'])) {
            $coupon_code = trim(filter_input(INPUT_POST, 'coupon_code', FILTER_SANITIZE_STRING));
        }
    } elseif (isset($_POST['apply_coupon'])) {
        // Validate start date
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || strtotime($start_date) < strtotime(date('Y-m-d'))) {
            $start_date = date('Y-m-d');
        }
        
        if ($plan['duration'] === 'Daily' && isset($_POST['num_days'])) {
            $num_days = filter_input(INPUT_POST, 'num_days', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30, 'default' => 1]]);
            $base_price = $price_per_day * $num_days;
        }
        
        // Process coupon code
        $coupon_code = trim(filter_input(INPUT_POST, 'coupon_code', FILTER_SANITIZE_STRING));
        
        if (!empty($coupon_code)) {
            // Check if coupon exists and is valid
            $stmt = $conn->prepare("
                SELECT * FROM coupons 
                WHERE code = ? 
                AND is_active = 1 
                AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                AND (usage_limit IS NULL OR usage_count < usage_limit)
            ");
            $stmt->execute([$coupon_code]);
            $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($coupon) {
                // Check if coupon is applicable to this plan or gym
                $is_applicable = true;
                
                if ($coupon['applicable_to_type'] === 'plan' && $coupon['applicable_to_id'] != $plan_id) {
                    $is_applicable = false;
                } elseif ($coupon['applicable_to_type'] === 'gym' && $coupon['applicable_to_id'] != $gym_id) {
                    $is_applicable = false;
                }
                
                if ($is_applicable) {
                    // Calculate discount
                    if ($coupon['discount_type'] === 'percentage') {
                        $discount_amount = ($base_price * $coupon['discount_value']) / 100;
                    } else { // fixed amount
                        $discount_amount = min($coupon['discount_value'], $base_price); // Don't discount more than the price
                    }
                    
                    $coupon_success = "Coupon applied successfully!";
                } else {
                    $coupon_error = "This coupon is not applicable to this plan.";
                }
            } else {
                $coupon_error = "Invalid or expired coupon code.";
            }
        } else {
            $coupon_error = "Please enter a coupon code.";
        }
    }
}

// Calculate end date based on start date and duration
if ($num_days == 1) {
    $end_date = $start_date; // Same day membership
} else {
    // Subtract 1 from num_days because the end date is inclusive of the start date
    $end_date = date('Y-m-d', strtotime($start_date . ' + ' . ($num_days - 1) . ' days'));
}

// Calculate taxes and final price
$subtotal = $base_price - $discount_amount;
$gateway_tax = ($subtotal * $gateway_tax_rate) / 100;
$govt_tax = ($subtotal * $govt_tax_rate) / 100;
$total_price = $subtotal + $gateway_tax + $govt_tax;

// Check if user already has an active membership for this gym
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM user_memberships 
    WHERE user_id = ? AND gym_id = ? AND status = 'active' 
    AND end_date >= CURDATE()
");
$stmt->execute([$_SESSION['user_id'], $gym_id]);
$has_active_membership = ($stmt->fetchColumn() > 0);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="razorpay-key" content="<?php echo htmlspecialchars($razorpay_key_id); ?>">
    <meta name="razorpay-test-mode" content="<?php echo $test_mode ? 'true' : 'false'; ?>">

    <title>Membership Confirmation - <?php echo htmlspecialchars($plan['gym_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Add nonce for inline scripts if CSP is enabled -->
    <script nonce="<?php echo htmlspecialchars($csrf->getNonce()); ?>">
        // Tailwind configuration if needed
    </script>
</head>
<body class="bg-gray-900 text-white">


<div class="min-h-screen py-12">
    <div class="max-w-4xl mx-auto px-4">
        <div class="bg-gray-800 rounded-2xl shadow-xl overflow-hidden">
            <!-- Header with Gym Image -->
            <div class="relative h-48">
                <?php if ($plan['cover_photo']): ?>
                    <img src="./gym/uploads/gym_images/<?php echo htmlspecialchars($plan['cover_photo']); ?>" 
                         class="w-full h-full object-cover opacity-50" alt="Gym Cover">
                <?php endif; ?>
                <div class="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center">
                    <h1 class="text-3xl font-bold text-white text-center">
                        Membership Confirmation
                    </h1>
                </div>
            </div>

            <div class="p-8">
                <!-- Gym Details -->
                <div class="mb-8">
                    <h2 class="text-2xl font-bold text-white mb-2">
                        <?php echo htmlspecialchars($plan['gym_name']); ?>
                    </h2>
                    <p class="text-gray-400">
                        <?php echo htmlspecialchars($plan['address'] . ', ' . $plan['city']); ?>
                    </p>
                </div>

                <?php if ($has_active_membership): ?>
                <div class="bg-yellow-900 text-yellow-200 p-4 rounded-lg mb-6">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-400 text-xl mr-3"></i>
                        </div>
                        <div>
                            <h3 class="font-bold">You already have an active membership for this gym</h3>
                            <p class="mt-1">Purchasing a new membership will extend your current membership period.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Plan Details Card -->
                <div class="bg-gray-700 rounded-xl p-6 mb-8">
                    <div class="flex justify-between items-start mb-6">
                        <div>
                            <h3 class="text-xl font-semibold text-white">
                                <?php echo htmlspecialchars($plan['tier']); ?> Plan
                            </h3>
                            <p class="text-gray-300">
                                <?php echo htmlspecialchars($plan['duration']); ?> Membership
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-3xl font-bold text-yellow-400">
                                ₹<?php echo number_format($plan['price'], 2); ?>
                            </div>
                            <div class="text-sm text-gray-400">
                                <?php echo strtolower($plan['duration']); ?> billing
                            </div>
                        </div>
                    </div>

                    <!-- Plan Features -->
                    <div class="space-y-3">
                        <?php 
                        $inclusions = explode(',', $plan['inclusions']);
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

                <!-- Membership Customization Form -->
                <form method="POST" class="bg-gray-700 rounded-xl p-6 mb-8">
                    <input type="hidden" name="csrf_token" value="<?php echo $token; ?>">
                    <h4 class="font-semibold text-white mb-4">Customize Your Membership</h4>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-300 mb-1">Start Date</label>
                            <input type="date" name="start_date" value="<?php echo $start_date; ?>" min="<?php echo date('Y-m-d'); ?>"
                                   class="w-full px-4 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-yellow-400 focus:outline-none">
                            <p class="text-xs text-gray-400 mt-1">Select when you want to start your membership</p>
                        </div>
                        
                        <?php if ($plan['duration'] === 'Daily'): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-300 mb-1">Number of Days</label>
                                
                                <input type="number" name="num_days" value="<?php echo $num_days; ?>" min="1" max="30"
                                       class="w-full px-4 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-yellow-400 focus:outline-none">
                                <p class="text-xs text-gray-400 mt-1">Customize the length of your daily membership (up to 30 days)</p>
                            </div>
                        <?php endif; ?>
                        
                        <button type="submit" name="calculate"
                                class="w-full bg-gray-600 text-white py-2 px-4 rounded-lg font-medium hover:bg-gray-500 transition-colors duration-200">
                            Calculate Membership
                        </button>
                    </div>
                </form>

                <!-- Coupon Code Form -->
                <form method="POST" class="bg-gray-700 rounded-xl p-6 mb-8">
                    <input type="hidden" name="csrf_token" value="<?php echo $token; ?>">
                    <h4 class="font-semibold text-white mb-4">Apply Coupon Code</h4>
                    
                    <div class="space-y-4">
                        <!-- Hidden fields to preserve values -->
                        <input type="hidden" name="start_date" value="<?php echo $start_date; ?>">
                        <?php if ($plan['duration'] === 'Daily'): ?>
                            <input type="hidden" name="num_days" value="<?php echo $num_days; ?>">
                        <?php endif; ?>
                        
                        <div>
                            <div class="flex space-x-2">
                                <input type="text" name="coupon_code" value="<?php echo htmlspecialchars($coupon_code); ?>" 
                                       placeholder="Enter coupon code"
                                       class="flex-1 px-4 py-2 bg-gray-800 border border-gray-600 rounded-lg text-white focus:border-yellow-400 focus:outline-none">
                                <button type="submit" name="apply_coupon"
                                        class="bg-yellow-500 text-black px-4 py-2 rounded-lg font-medium hover:bg-yellow-400 transition-colors duration-200">
                                    Apply
                                </button>
                            </div>
                            
                            <?php if (!empty($coupon_error)): ?>
                                <p class="text-red-400 text-sm mt-2"><?php echo $coupon_error; ?></p>
                            <?php endif; ?>
                            
                            <?php if (!empty($coupon_success)): ?>
                                <p class="text-green-400 text-sm mt-2"><?php echo $coupon_success; ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>

                <!-- Membership Duration Summary -->
                <div class="bg-gray-700 rounded-xl p-6 mb-8">
                <h4 class="font-semibold text-white mb-4">Membership Summary</h4>
                    <div class="grid grid-cols-2 gap-4 mb-6">
                        <div>
                            <div class="text-sm text-gray-400">Start Date</div>
                            <div class="font-semibold text-white"><?php echo date('d M Y', strtotime($start_date)); ?></div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-400">End Date</div>
                            <div class="font-semibold text-white"><?php echo date('d M Y', strtotime($end_date)); ?></div>
                        </div>
                        
                        <div>
                            <div class="text-sm text-gray-400">Duration</div>
                            <div class="font-semibold text-white">
                                <?php 
                                if ($plan['duration'] === 'Daily') {
                                    if ($num_days == 1) {
                                        echo "1 day (Today only)";
                                    } else {
                                        echo $num_days . ' days';
                                    }
                                } else {
                                    echo $plan['duration'];
                                }
                                ?>
                            </div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-400">Plan Price</div>
                            <div class="font-semibold text-white">₹<?php echo number_format($base_price, 2); ?></div>
                        </div>
                    </div>
                    
                    <!-- Price Breakdown -->
                    <div class="border-t border-gray-600 pt-4 mb-4">
                        <h5 class="text-sm font-medium text-gray-300 mb-3">Price Breakdown</h5>
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-400">Base Price</span>
                                <span class="text-white">₹<?php echo number_format($base_price, 2); ?></span>
                            </div>
                            
                            <?php if ($discount_amount > 0): ?>
                            <div class="flex justify-between text-sm">
                                <span class="text-green-400">Coupon Discount</span>
                                <span class="text-green-400">-₹<?php echo number_format($discount_amount, 2); ?></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-400">Subtotal</span>
                                <span class="text-white">₹<?php echo number_format($subtotal, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($gateway_tax > 0): ?>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-400"><?php echo htmlspecialchars($gateway_tax_name); ?> (<?php echo $gateway_tax_rate; ?>%)</span>
                                <span class="text-white">₹<?php echo number_format($gateway_tax, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($govt_tax > 0): ?>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-400"><?php echo htmlspecialchars($govt_tax_name); ?> (<?php echo $govt_tax_rate; ?>%)</span>
                                <span class="text-white">₹<?php echo number_format($govt_tax, 2); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="flex justify-between text-base font-bold pt-2 border-t border-gray-600">
                                <span class="text-gray-300">Total</span>
                                <span class="text-yellow-400">₹<?php echo number_format($total_price, 2); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($num_days == 1): ?>
                    <div class="mt-4 p-3 bg-yellow-900 bg-opacity-50 rounded-lg text-yellow-200 text-sm">
                        <i class="fas fa-info-circle mr-2"></i> Note: This membership is valid for a single day only (<?php echo date('d M Y', strtotime($start_date)); ?>).
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Payment Action -->
                <div class="space-y-4">
                    <input type="hidden" id="plan_id" value="<?php echo $plan_id; ?>">
                    <input type="hidden" id="gym_id" value="<?php echo $gym_id; ?>">
                    <input type="hidden" id="start_date" value="<?php echo $start_date; ?>">
                    <input type="hidden" id="end_date" value="<?php echo $end_date; ?>">
                    <input type="hidden" id="base_price" value="<?php echo $base_price; ?>">
                    <input type="hidden" id="discount_amount" value="<?php echo $discount_amount; ?>">
                    <input type="hidden" id="coupon_code" value="<?php echo htmlspecialchars($coupon_code); ?>">
                    <input type="hidden" id="gateway_tax" value="<?php echo $gateway_tax; ?>">
                    <input type="hidden" id="govt_tax" value="<?php echo $govt_tax; ?>">
                    <input type="hidden" id="total_price" value="<?php echo $total_price; ?>">
                    <input type="hidden" id="num_days" value="<?php echo $num_days; ?>">
                    <input type="hidden" id="csrf_token" value="<?php echo $token; ?>">
                    
                    <button type="button" id="proceedToPaymentBtn"
                            class="w-full bg-gradient-to-r from-yellow-400 to-yellow-500 text-black py-4 px-8 rounded-full font-semibold text-lg hover:from-yellow-500 hover:to-yellow-600 transform transition-all duration-200 hover:scale-[1.02] focus:outline-none focus:ring-2 focus:ring-yellow-400 focus:ring-offset-2 focus:ring-offset-gray-800">
                        Proceed to Payment
                    </button>
                    
                    <p class="text-center text-sm text-gray-400">
                        By proceeding, you agree to our <a href="terms.php" class="text-yellow-400 hover:underline">terms and conditions</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script nonce="<?php echo htmlspecialchars($csrf->getNonce()); ?>">
document.addEventListener('DOMContentLoaded', function() {
    // Get form elements
    const startDateInput = document.querySelector('input[name="start_date"]');
    const numDaysInput = document.querySelector('input[name="num_days"]');
    const calculateBtn = document.querySelector('button[name="calculate"]');
    
    // Add event listeners for real-time updates (optional enhancement)
    if (startDateInput && numDaysInput) {
        const updateSummary = function() {
            // This would require AJAX to update the summary in real-time
            // For simplicity, we're using the form submission approach
            calculateBtn.click();
        };
        
        // Uncomment these lines if you want real-time updates without form submission
        startDateInput.addEventListener('change', updateSummary);
        if (numDaysInput) numDaysInput.addEventListener('change', updateSummary);
    }
    
    // Get the proceed to payment button
    const proceedToPaymentBtn = document.getElementById('proceedToPaymentBtn');
    
    if (proceedToPaymentBtn) {
        // Add a click event listener with proper error handling
        proceedToPaymentBtn.addEventListener('click', function(e) {
            e.preventDefault(); // Prevent default form submission if inside a form
            
            // Check if payment gateway is enabled
            const paymentGatewayEnabled = <?php echo $payment_gateway_enabled ? 'true' : 'false'; ?>;
            if (!paymentGatewayEnabled) {
                showError('Payment gateway is currently disabled. Please try again later or contact support.');
                return;
            }
            
            try {
                // Show loading state
                this.disabled = true;
                this.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
                
                // Get payment details
                const paymentData = {
                    amount: document.getElementById('total_price').value,
                    base_amount: document.getElementById('base_price').value,
                    gym_id: document.getElementById('gym_id').value,
                    payment_type: 'membership',
                    related_id: document.getElementById('plan_id').value,
                    csrf_token: document.getElementById('csrf_token').value
                };
                
                // Initialize payment
                fetch('api/initialize-payment.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams(paymentData),
                    credentials: 'same-origin' // Include cookies for session-based CSRF protection
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.status === 'success') {
                        // Store payment ID for later use
                        const paymentId = data.payment_id;
                        
                        // Get Razorpay key from meta tag (which comes from database)
                        const razorpayKey = document.querySelector('meta[name="razorpay-key"]').content;
                        const isTestMode = document.querySelector('meta[name="razorpay-test-mode"]').content === 'true';
                        
                        if (!razorpayKey) {
                            throw new Error('Razorpay key is not configured. Please contact support.');
                        }
                        
                        // Open Razorpay checkout
                        const options = {
                            key: razorpayKey,
                            amount: Math.round(parseFloat(paymentData.amount) * 100), // Convert to paise
                            currency: "INR",
                            name: "<?php echo htmlspecialchars($plan['gym_name']); ?>",
                            description: "<?php echo htmlspecialchars($plan['tier']); ?> Membership",
                            image: "<?php echo !empty($plan['logo']) ? '/uploads/gym_logos/' . htmlspecialchars($plan['logo']) : '/assets/img/logo.png'; ?>",
                            handler: function(response) {
                                // Process successful payment
                                processPayment(paymentId, response.razorpay_payment_id, response);
                            },
                            prefill: {
                                name: "<?php echo htmlspecialchars($_SESSION['username'] ?? ''); ?>",
                                email: "<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>",
                                contact: "<?php echo htmlspecialchars($_SESSION['phone'] ?? ''); ?>"
                            },
                            notes: {
                                plan_id: "<?php echo $plan_id; ?>",
                                gym_id: "<?php echo $gym_id; ?>",
                                start_date: "<?php echo $start_date; ?>",
                                end_date: "<?php echo $end_date; ?>",
                                test_mode: isTestMode ? "true" : "false"
                            },
                            theme: {
                                color: "#F59E0B"
                            },
                            modal: {
                                ondismiss: function() {
                                    // Cancel payment if modal is dismissed
                                    cancelPayment(paymentId);
                                }
                            }
                        };
                        
                        const rzp = new Razorpay(options);
                        rzp.on('payment.failed', function(response) {
                            // Handle payment failure
                            cancelPayment(paymentId, response.error.description);
                        });
                        
                        rzp.open();
                    } else {
                        // Handle initialization error
                        showError('Payment initialization failed: ' + data.message);
                        resetPaymentButton();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showError('An error occurred. Please try again.');
                    resetPaymentButton();
                });
            } catch (error) {
                console.error('Error in payment process:', error);
                showError('An unexpected error occurred. Please try again.');
                resetPaymentButton();
            }
        });
    } else {
        console.error('Payment button not found in the DOM');
    }

    // Function to process successful payment
    function processPayment(paymentId, transactionId, response) {
        const processData = {
            payment_id: paymentId,
            gym_id: document.getElementById('gym_id').value,
            transaction_id: transactionId,
            payment_method: 'razorpay',
            razorpay_payment_id: response.razorpay_payment_id,
            razorpay_order_id: response.razorpay_order_id,
            razorpay_signature: response.razorpay_signature,
            csrf_token: document.getElementById('csrf_token').value
        };
        
        fetch('./api/process-payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams(processData),
            credentials: 'same-origin'
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    console.error('Server response:', text);
                    throw new Error('Network response was not ok: ' + response.status);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Payment processing response:', data); // Add this for debugging
            if (data.status === 'success') {
                // Additional membership data to save
                const membershipData = {
                    plan_id: document.getElementById('plan_id').value,
                    start_date: document.getElementById('start_date').value,
                    end_date: document.getElementById('end_date').value,
                    payment_id: paymentId,
                    num_days: document.getElementById('num_days').value,
                    coupon_code: document.getElementById('coupon_code').value,
                    csrf_token: document.getElementById('csrf_token').value
                };
                
                // Save membership details and redirect to success page
                saveMembershipDetails(membershipData);
            } else {
                showError('Payment processing failed: ' + (data.message || 'Unknown error'));
                resetPaymentButton();
            }
        })
        .catch(error => {
            console.error('Error details:', error);
            showError('An error occurred while processing payment. Please contact support. Details: ' + error.message);
            resetPaymentButton();
        });
    }
    
    // Function to cancel payment
    function cancelPayment(paymentId, errorMessage = 'Payment cancelled by user') {
        fetch('./api/cancel-payment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                payment_id: paymentId,
                gym_id: document.getElementById('gym_id').value,
                error_message: errorMessage,
                csrf_token: document.getElementById('csrf_token').value
            }),
            credentials: 'same-origin'
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            resetPaymentButton();
            
            if (data.status !== 'success') {
                console.error('Error cancelling payment:', data.message);
            }
            
            showError('Payment cancelled: ' + errorMessage);
        })
        .catch(error => {
            console.error('Error:', error);
            resetPaymentButton();
            showError('Payment was cancelled');
        });
    }
    
    // Function to save membership details after successful payment
    function saveMembershipDetails(membershipData) {
        fetch('./save_membership.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams(membershipData),
            credentials: 'same-origin'
        })
        .then(response => {
            if (!response.ok) {
                return response.text().then(text => {
                    console.error('Server response:', text);
                    throw new Error('Network response was not ok: ' + response.status);
                });
            }
            return response.json();
        })
        .then(data => {
            console.log('Membership creation response:', data); // Add this for debugging
            if (data.status === 'success') {
                // Redirect to success page
                window.location.href = 'membership_success.php?id=' + data.membership_id;
            } else {
                showError('Error saving membership details: ' + (data.message || 'Unknown error'));
                resetPaymentButton();
            }
        })
        .catch(error => {
            console.error('Error details:', error);
            showError('An error occurred while saving membership details. Please contact support. Details: ' + error.message);
            resetPaymentButton();
        });
    }
    
    // Helper function to reset payment button
    function resetPaymentButton() {
        const btn = document.getElementById('proceedToPaymentBtn');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = 'Proceed to Payment';
        }
    }
    
    // Helper function to show error messages
    function showError(message) {
        // Create error element if it doesn't exist
        let errorEl = document.getElementById('payment-error');
        if (!errorEl) {
            errorEl = document.createElement('div');
            errorEl.id = 'payment-error';
            errorEl.className = 'bg-red-500 text-white p-4 rounded-lg mb-4 animate-fade-in';
            
            const paymentBtn = document.getElementById('proceedToPaymentBtn');
            if (paymentBtn && paymentBtn.parentNode) {
                paymentBtn.parentNode.insertBefore(errorEl, paymentBtn);
            }
        }
        
        errorEl.innerHTML = `<i class="fas fa-exclamation-circle mr-2"></i> ${message}`;
        
        // Scroll to error
        errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>
</body>
</html>
