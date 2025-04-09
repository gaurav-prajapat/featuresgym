<?php
session_start();
require_once 'includes/navbar.php';

// Check if payment data is available
if (!isset($_SESSION['payment_data']) || !isset($_SESSION['payment_details'])) {
    $_SESSION['error'] = "Payment information is missing. Please try again.";
    header('Location: membership.php');
    exit();
}

// Get payment data
$payment_data = $_SESSION['payment_data'];
$payment_details = $_SESSION['payment_details'];

// Extract details for display
$membership_id = $payment_details['membership_id'];
$payment_id = $payment_details['payment_id'];
$amount = $payment_details['amount'];
$gym_name = $payment_details['gym_name'];
$plan_name = $payment_details['plan_name'];
$test_mode = $payment_details['test_mode'];

// Create an order array for the payment page
$order = [
    'membership_id' => $membership_id,
    'payment_id' => $payment_id,
    'order_id' => $payment_data['order_id'],
    'amount' => $amount
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Payment - Fitness Hub</title>
    
    <!-- Preload Razorpay script -->
    <link rel="preload" href="https://checkout.razorpay.com/v1/checkout.js" as="script">
    
    <!-- Razorpay script -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
</head>
<body class="bg-gray-900 text-white">

<div class="min-h-screen py-12">
    <div class="max-w-3xl mx-auto px-4">
        <div class="bg-gray-800 rounded-2xl shadow-xl overflow-hidden">
            <div class="p-8">
                <h1 class="text-3xl font-bold text-white text-center mb-6">Complete Your Payment</h1>
                
                <?php if ($test_mode): ?>
                <div class="bg-blue-900 text-blue-200 p-4 rounded-lg mb-6">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-flask text-blue-400 text-xl mr-3"></i>
                        </div>
                        <div>
                            <h3 class="font-bold">Test Mode Active</h3>
                            <p class="mt-1">The payment system is in test mode. No real charges will be made.</p>
                            <p class="mt-1">Use test card: 4111 1111 1111 1111, Any future date, Any CVV</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="bg-gray-700 rounded-xl p-6 mb-8">
                    <div class="flex justify-between items-start mb-6">
                        <div>
                            <h3 class="text-xl font-bold text-white"><?php echo htmlspecialchars($plan_name); ?></h3>
                            <p class="text-gray-400"><?php echo htmlspecialchars($gym_name); ?></p>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold text-white">₹<?php echo number_format($amount, 2); ?></div>
                        </div>
                    </div>
                    
                    <div class="border-t border-gray-600 pt-4">
                        <p class="text-gray-300">
                            You're about to complete your payment for the membership. Click the button below to proceed with the payment.
                        </p>
                    </div>
                </div>
                
                <div class="text-center">
                    <button id="razorpay-button" class="bg-yellow-500 hover:bg-yellow-400 text-black font-bold px-8 py-3 rounded-full text-lg transition-colors">
                        <i class="fas fa-credit-card mr-2"></i>Pay Now
                    </button>
                    
                    <p class="mt-4 text-sm text-gray-400">
                        <i class="fas fa-lock mr-1"></i> Secure payment powered by Razorpay
                    </p>
                </div>
                
                <div class="mt-6 text-center">
                    <a href="membership.php" class="text-gray-400 hover:text-white transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Cancel and go back
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Document loaded, initializing Razorpay');
    
    try {
        // Initialize Razorpay
        const razorpay = new Razorpay({
            key: "<?php echo htmlspecialchars($payment_data['key']); ?>",
            amount: <?php echo $payment_data['amount']; ?>,
            currency: "<?php echo $payment_data['currency']; ?>",
            name: "<?php echo htmlspecialchars($payment_data['name']); ?>",
            description: "<?php echo htmlspecialchars($payment_data['description']); ?>",
            image: "<?php echo $base_url; ?>/assets/images/logo.png",
            order_id: "<?php echo $payment_data['order_id']; ?>",
            prefill: {
                name: "<?php echo htmlspecialchars($payment_data['prefill']['name']); ?>",
                email: "<?php echo htmlspecialchars($payment_data['prefill']['email']); ?>",
                contact: "<?php echo htmlspecialchars($payment_data['prefill']['contact']); ?>"
            },
            notes: <?php echo json_encode($payment_data['notes']); ?>,
            theme: {
                color: "<?php echo $payment_data['theme']['color']; ?>"
            },
            modal: {
                ondismiss: function() {
                    // Reset button state
                    const razorpayButton = document.getElementById('razorpay-button');
                    razorpayButton.disabled = false;
                    razorpayButton.innerHTML = '<i class="fas fa-credit-card mr-2"></i>Pay Now';
                }
            },
            handler: function(response) {
                // Create a form to submit payment verification data
                const verificationForm = document.createElement('form');
                verificationForm.method = 'POST';
                verificationForm.action = 'verify_payment.php';
                
                // Add Razorpay response fields
                for (const key in response) {
                    if (response.hasOwnProperty(key)) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = key;
                        input.value = response[key];
                        verificationForm.appendChild(input);
                    }
                }
                
                // Add order details
                const orderIdInput = document.createElement('input');
                orderIdInput.type = 'hidden';
                orderIdInput.name = 'order_id';
                orderIdInput.value = "<?php echo $order['order_id']; ?>";
                verificationForm.appendChild(orderIdInput);
                
                const membershipIdInput = document.createElement('input');
                membershipIdInput.type = 'hidden';
                membershipIdInput.name = 'membership_id';
                membershipIdInput.value = "<?php echo $order['membership_id']; ?>";
                verificationForm.appendChild(membershipIdInput);
                
                const paymentIdInput = document.createElement('input');
                paymentIdInput.type = 'hidden';
                paymentIdInput.name = 'payment_id';
                paymentIdInput.value = "<?php echo $order['payment_id']; ?>";
                verificationForm.appendChild(paymentIdInput);
                
                // Add CSRF token if needed
                <?php if (function_exists('csrf_token')): ?>
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = "<?php echo csrf_token(); ?>";
                verificationForm.appendChild(csrfInput);
                <?php endif; ?>
                
                // Add to document and submit
                document.body.appendChild(verificationForm);
                verificationForm.submit();
            }
        });
        
        razorpay.on('payment.failed', function(response) {
            // Create a form to submit payment failure data
            const failureForm = document.createElement('form');
            failureForm.method = 'POST';
            failureForm.action = 'payment_failed.php';
            
            // Add error details
            const errorCodeInput = document.createElement('input');
            errorCodeInput.type = 'hidden';
            errorCodeInput.name = 'error_code';
            errorCodeInput.value = response.error.code;
            failureForm.appendChild(errorCodeInput);
            
            const errorDescInput = document.createElement('input');
            errorDescInput.type = 'hidden';
            errorDescInput.name = 'error_description';
            errorDescInput.value = response.error.description;
            failureForm.appendChild(errorDescInput);
            
            const errorSourceInput = document.createElement('input');
            errorSourceInput.type = 'hidden';
            errorSourceInput.name = 'error_source';
            errorSourceInput.value = response.error.source;
            failureForm.appendChild(errorSourceInput);
            
            const errorStepInput = document.createElement('input');
            errorStepInput.type = 'hidden';
            errorStepInput.name = 'error_step';
            errorStepInput.value = response.error.step;
            failureForm.appendChild(errorStepInput);
            
            const errorReasonInput = document.createElement('input');
            errorReasonInput.type = 'hidden';
            errorReasonInput.name = 'error_reason';
            errorReasonInput.value = response.error.reason;
            failureForm.appendChild(errorReasonInput);
            
            const orderIdInput = document.createElement('input');
            orderIdInput.type = 'hidden';
            orderIdInput.name = 'order_id';
            orderIdInput.value = response.error.metadata.order_id;
            failureForm.appendChild(orderIdInput);
            
            const membershipIdInput = document.createElement('input');
            membershipIdInput.type = 'hidden';
            membershipIdInput.name = 'membership_id';
            membershipIdInput.value = "<?php echo $order['membership_id']; ?>";
            failureForm.appendChild(membershipIdInput);
            
            // Add CSRF token if needed
            <?php if (function_exists('csrf_token')): ?>
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = "<?php echo csrf_token(); ?>";
            failureForm.appendChild(csrfInput);
            <?php endif; ?>
            
            // Add to document and submit
            document.body.appendChild(failureForm);
            failureForm.submit();
        });
        
        // Attach click event to button
        const razorpayButton = document.getElementById('razorpay-button');
        razorpayButton.addEventListener('click', function() {
            // Show loading state
            razorpayButton.disabled = true;
            razorpayButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Processing...';
            
            // Open Razorpay checkout
            razorpay.open();
        });
        
        console.log('Razorpay initialized successfully');
    } catch (error) {
        console.error('Error initializing Razorpay:', error);
        alert('Payment gateway initialization failed. Please try again later.');
    }
});
</script>

<?php include 'includes/footer.php'; ?>
</body>
</html>

