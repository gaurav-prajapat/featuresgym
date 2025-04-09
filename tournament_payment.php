<?php
ob_start();
require_once 'config/database.php';
include 'includes/navbar.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please log in to complete payment.";
    header('Location: login.php');
    exit;
}

// Check if tournament ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Invalid tournament ID.";
    header('Location: tournaments.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];
$tournament_id = (int)$_GET['id'];

// Get tournament and registration details
$stmt = $conn->prepare("
    SELECT t.*, g.name as gym_name, g.gym_id, tp.registration_date, tp.payment_status, tp.id as participant_id,
           pm.method_type, pm.account_name, pm.account_number, pm.ifsc_code, pm.bank_name, pm.upi_id
    FROM gym_tournaments t
    JOIN gyms g ON t.gym_id = g.gym_id
    JOIN tournament_participants tp ON t.id = tp.tournament_id AND tp.user_id = ?
    LEFT JOIN payment_methods pm ON t.payment_method_id = pm.id
    WHERE t.id = ?
");
$stmt->execute([$user_id, $tournament_id]);
$tournament = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tournament) {
    $_SESSION['error'] = "You are not registered for this tournament or the tournament doesn't exist.";
    header('Location: tournaments.php');
    exit;
}

if ($tournament['payment_status'] === 'paid') {
    $_SESSION['success'] = "You have already paid for this tournament.";
    header("Location: tournament_details.php?id=$tournament_id");
    exit;
}

// Get user details
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Process payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $conn->beginTransaction();
        
        $payment_method = $_POST['payment_method'];
        $transaction_id = $_POST['transaction_id'] ?? null;
        $payment_notes = $_POST['payment_notes'] ?? null;
        
        // Handle payment proof upload
        $payment_proof = null;
        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/payment_proofs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION);
            $new_filename = 'payment_' . $tournament['participant_id'] . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            if (move_uploaded_file($_FILES['payment_proof']['tmp_name'], $upload_path)) {
                $payment_proof = $new_filename;
            } else {
                throw new Exception("Failed to upload payment proof.");
            }
        }
        
        // Update payment status to 'pending_verification'
        $stmt = $conn->prepare("
            UPDATE tournament_participants 
            SET payment_status = 'pending_verification', 
                payment_date = NOW(),
                payment_method = ?,
                transaction_id = ?,
                payment_notes = ?,
                payment_proof = ?
            WHERE tournament_id = ? AND user_id = ?
        ");
        $stmt->execute([
            $payment_method,
            $transaction_id,
            $payment_notes,
            $payment_proof,
            $tournament_id, 
            $user_id
        ]);
        
        // Record the transaction
        $stmt = $conn->prepare("
            INSERT INTO transactions 
            (user_id, gym_id, amount, transaction_type, status, description, transaction_date, payment_method, transaction_id) 
            VALUES (?, ?, ?, 'tournament_fee', 'pending', ?, NOW(), ?, ?)
        ");
        $stmt->execute([
            $user_id, 
            $tournament['gym_id'], 
            $tournament['entry_fee'], 
            "Tournament entry fee for " . $tournament['title'],
            $payment_method,
            $transaction_id
        ]);
        
        // Send notification to gym owner
        $stmt = $conn->prepare("
            INSERT INTO notifications 
            (user_id, type, message, related_id, is_read, created_at) 
            VALUES (?, 'payment', ?, ?, 0, NOW())
        ");
        $owner_message = "New payment submitted for tournament: " . $tournament['title'];
        $stmt->execute([$tournament['gym_id'], $owner_message, $tournament_id]);
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success'] = "Payment submitted successfully! Your payment is pending verification by the gym.";
        header("Location: tournament_details.php?id=$tournament_id");
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        $_SESSION['error'] = "Payment submission failed: " . $e->getMessage();
    }
}
?>

<div class="container mx-auto px-4 py-8 pt-24">
    <!-- Back button -->
    <div class="mb-6">
        <a href="tournament_details.php?id=<?= $tournament_id ?>" class="inline-flex items-center text-yellow-400 hover:text-yellow-500">
            <i class="fas fa-arrow-left mr-2"></i> Back to Tournament
        </a>
    </div>
    
    <!-- Messages -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-900 text-red-100 p-6 rounded-3xl mb-6">
            <?= htmlspecialchars($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <!-- Payment Form -->
    <div class="max-w-3xl mx-auto">
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden mb-8">
            <div class="p-6 border-b border-gray-700">
                <h1 class="text-2xl font-bold">Complete Your Payment</h1>
                <p class="text-gray-400 mt-1">Tournament: <?= htmlspecialchars($tournament['title']) ?></p>
            </div>
            
            <div class="p-6">
                <div class="mb-6">
                    <h2 class="text-lg font-semibold mb-4">Order Summary</h2>
                    
                    <div class="bg-gray-700 rounded-xl p-4 mb-4">
                        <div class="flex justify-between items-center mb-2">
                            <span>Tournament Entry Fee</span>
                            <span>₹<?= number_format($tournament['entry_fee'], 2) ?></span>
                        </div>
                        
                        <div class="border-t border-gray-600 my-2"></div>
                        
                        <div class="flex justify-between items-center font-bold">
                            <span>Total Amount</span>
                            <span class="text-yellow-400">₹<?= number_format($tournament['entry_fee'], 2) ?></span>
                        </div>
                    </div>
                </div>
                
                <?php if ($tournament['method_type']): ?>
                <!-- Payment Instructions -->
                <div class="mb-6">
                    <h2 class="text-lg font-semibold mb-4">Payment Instructions</h2>
                    
                    <div class="bg-gray-700 rounded-xl p-4">
                        <p class="mb-4">Please make the payment using the following details:</p>
                        
                        <?php if ($tournament['method_type'] === 'bank'): ?>
                            <div class="space-y-2">
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Bank Name:</span>
                                    <span class="font-medium"><?= htmlspecialchars($tournament['bank_name']) ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Account Name:</span>
                                    <span class="font-medium"><?= htmlspecialchars($tournament['account_name']) ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Account Number:</span>
                                    <span class="font-medium"><?= htmlspecialchars($tournament['account_number']) ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">IFSC Code:</span>
                                    <span class="font-medium"><?= htmlspecialchars($tournament['ifsc_code']) ?></span>
                                </div>
                            </div>
                        <?php elseif ($tournament['method_type'] === 'upi'): ?>
                            <div class="space-y-2">
        <div class="flex justify-between">
            <span class="text-gray-400">UPI ID:</span>
            <span class="font-medium"><?= htmlspecialchars($tournament['upi_id']) ?></span>
        </div>
    </div>
    
    <div class="mt-4 text-center">
        <!-- Using Bootstrap QR Code Generator -->
        <div id="qrcode" class="inline-block bg-white p-2 rounded-lg"></div>
        <p class="text-sm text-gray-400 mt-2">Scan this QR code to pay</p>
    </div>
                        <?php endif; ?>
                        
                        <div class="mt-4 p-3 bg-yellow-900 bg-opacity-50 rounded-lg text-yellow-300 text-sm">
                            <i class="fas fa-info-circle mr-2"></i> After making the payment, please fill out the form below with your payment details and upload a screenshot of the payment confirmation.
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <form method="POST" id="paymentForm" enctype="multipart/form-data">
                    <h2 class="text-lg font-semibold mb-4">Payment Details</h2>
                    
                    <div class="mb-4">
                        <label for="payment_method" class="block text-sm font-medium mb-1">Payment Method Used</label>
                        <select id="payment_method" name="payment_method" 
                               class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400" required>
                            <option value="">Select Payment Method</option>
                            <option value="upi">UPI</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="net_banking">Net Banking</option>
                            <option value="wallet">Digital Wallet</option>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label for="transaction_id" class="block text-sm font-medium mb-1">Transaction ID / Reference Number</label>
                        <input type="text" id="transaction_id" name="transaction_id" placeholder="Enter the transaction ID or reference number" 
                               class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400" required>
                    </div>
                    
                    <div class="mb-4">
                        <label for="payment_proof" class="block text-sm font-medium mb-1">Payment Proof (Screenshot)</label>
                        <input type="file" id="payment_proof" name="payment_proof" accept="image/*" 
                               class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400" required>
                        <p class="text-sm text-gray-400 mt-1">Upload a screenshot of your payment confirmation (Max size: 5MB)</p>
                    </div>
                    
                    <div class="mb-6">
                        <label for="payment_notes" class="block text-sm font-medium mb-1">Additional Notes (Optional)</label>
                        <textarea id="payment_notes" name="payment_notes" rows="2" 
                                  class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400"
                                  placeholder="Any additional information about your payment"></textarea>
                    </div>
                    
                    <div class="flex justify-between items-center">
                        <a href="tournament_details.php?id=<?= $tournament_id ?>" class="text-gray-400 hover:text-white">
                            Cancel
                        </a>
                        
                        <button type="submit" class="px-6 py-3 bg-yellow-500 hover:bg-yellow-600 text-black font-medium rounded-xl transition-colors duration-200">
                            Submit Payment Details
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6">
            <div class="flex items-center justify-center space-x-4">
                <i class="fas fa-info-circle text-yellow-400 text-xl"></i>
                <p class="text-sm text-gray-400">Your payment will be verified by the gym administrator. You will be notified once your payment is confirmed.</p>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Preview uploaded image
        const paymentProofInput = document.getElementById('payment_proof');
        if (paymentProofInput) {
            paymentProofInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                                       // Check file size (5MB max)
                                       if (this.files[0].size > 5 * 1024 * 1024) {
                        alert('File size exceeds 5MB. Please choose a smaller file.');
                        this.value = '';
                        return;
                    }
                    
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        // Create preview if it doesn't exist
                        let previewContainer = document.getElementById('proof-preview');
                        if (!previewContainer) {
                            previewContainer = document.createElement('div');
                            previewContainer.id = 'proof-preview';
                            previewContainer.className = 'mt-3';
                            paymentProofInput.parentNode.appendChild(previewContainer);
                        }
                        
                        // Update preview
                        previewContainer.innerHTML = `
                            <div class="relative">
                                <img src="${e.target.result}" alt="Payment proof preview" class="max-h-60 rounded-lg">
                                <button type="button" id="remove-preview" class="absolute top-2 right-2 bg-red-500 text-white rounded-full p-1 hover:bg-red-600">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `;
                        
                        // Add remove button functionality
                        document.getElementById('remove-preview').addEventListener('click', function() {
                            paymentProofInput.value = '';
                            previewContainer.remove();
                        });
                    };
                    reader.readAsDataURL(this.files[0]);
                }
            });
        }
        
        // Form validation
        const paymentForm = document.getElementById('paymentForm');
        if (paymentForm) {
            paymentForm.addEventListener('submit', function(e) {
                const paymentMethod = document.getElementById('payment_method').value;
                const transactionId = document.getElementById('transaction_id').value;
                const paymentProof = document.getElementById('payment_proof').value;
                
                if (!paymentMethod) {
                    alert('Please select a payment method');
                    e.preventDefault();
                    return;
                }
                
                if (!transactionId) {
                    alert('Please enter the transaction ID or reference number');
                    e.preventDefault();
                    return;
                }
                
                if (!paymentProof) {
                    alert('Please upload a screenshot of your payment confirmation');
                    e.preventDefault();
                    return;
                }
                
                // Confirm submission
                if (!confirm('Are you sure you want to submit your payment details? This cannot be undone.')) {
                    e.preventDefault();
                    return;
                }
            });
        }
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Create QR code with payment information
            new QRCode(document.getElementById("qrcode"), {
                text: "upi://pay?pa=<?= urlencode($tournament['upi_id']) ?>&am=<?= $tournament['entry_fee'] ?>&pn=<?= urlencode($tournament['gym_name']) ?>&tn=<?= urlencode('Tournament: ' . $tournament['title']) ?>",
                width: 200,
                height: 200,
                colorDark: "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H
            });
        });
    </script>

<?php include 'includes/footer.php'; ?>

