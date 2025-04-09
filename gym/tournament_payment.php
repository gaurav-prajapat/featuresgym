<?php
require_once '../config/database.php';
include '../includes/navbar.php';

// Check if gym owner is logged in
if (!isset($_SESSION['owner_id'])) {
    header('Location: ../login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];

// Get gym information
$stmt = $conn->prepare("SELECT * FROM gyms WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    $_SESSION['error'] = "No gym found for this owner.";
    header('Location: dashboard.php');
    exit();
}

$gym_id = $gym['gym_id'];

// Check if tournament ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Tournament ID is required.";
    header('Location: tournaments.php');
    exit();
}

$tournament_id = $_GET['id'];

// Get tournament details
$stmt = $conn->prepare("
    SELECT * FROM gym_tournaments 
    WHERE id = ? AND gym_id = ?
");
$stmt->execute([$tournament_id, $gym_id]);
$tournament = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tournament) {
    $_SESSION['error'] = "Tournament not found or you don't have permission to access it.";
    header('Location: tournaments.php');
    exit();
}

// Get payment methods
$stmt = $conn->prepare("SELECT * FROM payment_methods WHERE owner_id = ? ORDER BY is_primary DESC");
$stmt->execute([$owner_id]);
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tournament participants with all fields from tournament_participants table
$stmt = $conn->prepare("
    SELECT tp.*, u.username, u.email, u.phone, u.profile_image
    FROM tournament_participants tp
    JOIN users u ON tp.user_id = u.id
    WHERE tp.tournament_id = ?
    ORDER BY tp.registration_date DESC
");
$stmt->execute([$tournament_id]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process payment settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_settings'])) {
    $entry_fee = $_POST['entry_fee'];
    $prize_pool = $_POST['prize_pool'];
    $payment_method_id = $_POST['payment_method_id'];
    
    // Update tournament payment settings
    $stmt = $conn->prepare("
        UPDATE gym_tournaments 
        SET entry_fee = ?, prize_pool = ?, payment_method_id = ?
        WHERE id = ? AND gym_id = ?
    ");
    $result = $stmt->execute([$entry_fee, $prize_pool, $payment_method_id, $tournament_id, $gym_id]);
    
    if ($result) {
        $_SESSION['success'] = "Tournament payment settings updated successfully.";
        // Refresh tournament data
        $stmt = $conn->prepare("SELECT * FROM gym_tournaments WHERE id = ?");
        $stmt->execute([$tournament_id]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $_SESSION['error'] = "Failed to update tournament payment settings.";
    }
}

// Process participant payment status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_status'])) {
    $participant_id = $_POST['participant_id'];
    $payment_status = $_POST['payment_status'];
    $payment_method = $_POST['payment_method'] ?? null;
    $transaction_id = $_POST['transaction_id'] ?? null;
    $payment_notes = $_POST['payment_notes'] ?? null;
    
    try {
        $conn->beginTransaction();
        
        // Update participant payment status
        $stmt = $conn->prepare("
            UPDATE tournament_participants 
            SET payment_status = ?, 
                payment_date = NOW(),
                payment_method = ?,
                transaction_id = ?,
                payment_notes = ?,
                updated_at = NOW()
            WHERE id = ? AND tournament_id = ?
        ");
        $result = $stmt->execute([
            $payment_status, 
            $payment_method,
            $transaction_id,
            $payment_notes,
            $participant_id, 
            $tournament_id
        ]);
        
        if ($result) {
            // Get participant details
            $stmt = $conn->prepare("
                SELECT tp.*, u.id as user_id 
                FROM tournament_participants tp
                JOIN users u ON tp.user_id = u.id
                WHERE tp.id = ?
            ");
            $stmt->execute([$participant_id]);
            $participant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($payment_status === 'paid') {
                // Record the transaction
                $stmt = $conn->prepare("
                    INSERT INTO transactions 
                    (user_id, gym_id, amount, transaction_type, status, description, transaction_date, payment_method, transaction_id) 
                    VALUES (?, ?, ?, 'tournament_fee', 'completed', ?, NOW(), ?, ?)
                ");
                $stmt->execute([
                    $participant['user_id'], 
                    $gym_id, 
                    $tournament['entry_fee'], 
                    "Tournament entry fee for " . $tournament['title'],
                    $payment_method,
                    $transaction_id
                ]);
                
                // Add to gym revenue
                $stmt = $conn->prepare("
                    INSERT INTO gym_revenue 
                    (gym_id, amount, source_type, source_id, date) 
                    VALUES (?, ?, 'tournament', ?, NOW())
                ");
                $stmt->execute([
                    $gym_id, 
                    $tournament['entry_fee'], 
                    $tournament_id
                ]);
                
                // Create notification for the user
                $stmt = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, type, title, message, related_id, created_at, is_read
                    ) VALUES (?, 'payment_confirmed', ?, ?, ?, NOW(), 0)
                ");
                $stmt->execute([
                    $participant['user_id'],
                    "Tournament Payment Confirmed",
                    "Your payment for " . $tournament['title'] . " has been confirmed. Your spot is secured!",
                    $tournament_id
                ]);
            } elseif ($payment_status === 'failed') {
                // Create notification for the user
                $stmt = $conn->prepare("
                    INSERT INTO notifications (
                        user_id, type, title, message, related_id, created_at, is_read
                    ) VALUES (?, 'payment_failed', ?, ?, ?, NOW(), 0)
                ");
                $stmt->execute([
                    $participant['user_id'],
                    "Tournament Payment Failed",
                    "Your payment for " . $tournament['title'] . " has been marked as failed. Please contact the gym for assistance.",
                    $tournament_id
                ]);
            }
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'owner', 'update_payment_status', ?, ?, ?)
            ");
            
            $details = "Updated payment status to " . $payment_status . " for participant in tournament: " . $tournament['title'];
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$owner_id, $details, $ip, $user_agent]);
            
            $conn->commit();
            $_SESSION['success'] = "Participant payment status updated successfully.";
        } else {
            $conn->rollBack();
            $_SESSION['error'] = "Failed to update participant payment status.";
        }
        
        // Refresh participants data
        $stmt = $conn->prepare("
            SELECT tp.*, u.username, u.email, u.phone, u.profile_image
            FROM tournament_participants tp
            JOIN users u ON tp.user_id = u.id
            WHERE tp.tournament_id = ?
            ORDER BY tp.registration_date DESC
        ");
        $stmt->execute([$tournament_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
}

// Process payment proof verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_payment'])) {
    $participant_id = $_POST['participant_id'];
    
    try {
        $conn->beginTransaction();
        
        // Update participant payment status to paid
        $stmt = $conn->prepare("
            UPDATE tournament_participants 
            SET payment_status = 'paid', 
                payment_date = NOW(),
                payment_notes = CONCAT(payment_notes, ' | Verified by owner on ', NOW()),
                updated_at = NOW()
            WHERE id = ? AND tournament_id = ?
        ");
        $result = $stmt->execute([$participant_id, $tournament_id]);
        
        if ($result) {
            // Get participant details
            $stmt = $conn->prepare("
                SELECT tp.*, u.id as user_id 
                FROM tournament_participants tp
                JOIN users u ON tp.user_id = u.id
                WHERE tp.id = ?
            ");
            $stmt->execute([$participant_id]);
            $participant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Record the transaction
            $stmt = $conn->prepare("
                INSERT INTO transactions 
                (user_id, gym_id, amount, transaction_type, status, description, transaction_date, payment_method, transaction_id) 
                VALUES (?, ?, ?, 'tournament_fee', 'completed', ?, NOW(), ?, ?)
            ");
            $stmt->execute([
                $participant['user_id'], 
                $gym_id, 
                $tournament['entry_fee'], 
                "Tournament entry fee for " . $tournament['title'] . " (Verified by owner)",
                $participant['payment_method'],
                $participant['transaction_id']
            ]);
            
            // Add to gym revenue
            // $stmt = $conn->prepare("
            //     INSERT INTO gym_revenue 
            //     (gym_id, amount, source_type, source_id, date) 
            //     VALUES (?, ?, 'tournament', ?, NOW())
            // ");
            // $stmt->execute([
            //     $gym_id, 
            //     $tournament['entry_fee'], 
            //     $tournament_id
            // ]);
            
            // Create notification for the user
            $stmt = $conn->prepare("
                INSERT INTO notifications (
                    user_id, type, title, message, related_id, created_at, is_read
                ) VALUES (?, 'payment_confirmed', ?, ?, ?, NOW(), 0)
            ");
            $stmt->execute([
                $participant['user_id'],
                "Tournament Payment Verified",
                "Your payment for " . $tournament['title'] . " has been verified. Your spot is secured!",
                $tournament_id
            ]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'owner', 'verify_payment', ?, ?, ?)
            ");
            
            $details = "Verified payment for participant in tournament: " . $tournament['title'];
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$owner_id, $details, $ip, $user_agent]);
            
            $conn->commit();
            $_SESSION['success'] = "Payment verified successfully.";
        } else {
            $conn->rollBack();
            $_SESSION['error'] = "Failed to verify payment.";
        }
        
        // Refresh participants data
        $stmt = $conn->prepare("
            SELECT tp.*, u.username, u.email, u.phone, u.profile_image
            FROM tournament_participants tp
            JOIN users u ON tp.user_id = u.id
            WHERE tp.tournament_id = ?
            ORDER BY tp.registration_date DESC
        ");
        $stmt->execute([$tournament_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
}

// Process payment rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_payment'])) {
    $participant_id = $_POST['participant_id'];
    $rejection_reason = $_POST['rejection_reason'] ?? 'Payment proof invalid or insufficient';
    
    try {
        $conn->beginTransaction();
        
        // Update participant payment status to failed
        $stmt = $conn->prepare("
            UPDATE tournament_participants 
            SET payment_status = 'failed', 
                payment_notes = CONCAT(payment_notes, ' | Rejected by owner: ', ?, ' on ', NOW()),
                updated_at = NOW()
            WHERE id = ? AND tournament_id = ?
        ");
        $result = $stmt->execute([$rejection_reason, $participant_id, $tournament_id]);
        
        if ($result) {
            // Get participant details
            $stmt = $conn->prepare("
                SELECT tp.*, u.id as user_id 
                FROM tournament_participants tp
                JOIN users u ON tp.user_id = u.id
                WHERE tp.id = ?
            ");
            $stmt->execute([$participant_id]);
            $participant = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Create notification for the user
            $stmt = $conn->prepare("
                INSERT INTO notifications (
                    user_id, type, title, message, related_id, created_at, is_read
                ) VALUES (?, 'payment_rejected', ?, ?, ?, NOW(), 0)
            ");
            $stmt->execute([
                $participant['user_id'],
                "Tournament Payment Rejected",
                "Your payment for " . $tournament['title'] . " has been rejected: " . $rejection_reason . ". Please submit a new payment.",
                $tournament_id
            ]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'owner', 'reject_payment', ?, ?, ?)
            ");
            
            $details = "Rejected payment for participant in tournament: " . $tournament['title'] . " - Reason: " . $rejection_reason;
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$owner_id, $details, $ip, $user_agent]);
            
            $conn->commit();
            $_SESSION['success'] = "Payment rejected successfully.";
        } else {
            $conn->rollBack();
            $_SESSION['error'] = "Failed to reject payment.";
        }
        
        // Refresh participants data
        $stmt = $conn->prepare("
            SELECT tp.*, u.username, u.email, u.phone, u.profile_image
            FROM tournament_participants tp
            JOIN users u ON tp.user_id = u.id
            WHERE tp.tournament_id = ?
            ORDER BY tp.registration_date DESC
        ");
        $stmt->execute([$tournament_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
}
?>

<div class="container mx-auto px-4 py-8 pt-24">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Tournament Payment Management</h1>
            <p class="text-gray-400">Manage payments for: <?php echo htmlspecialchars($tournament['title']); ?></p>
        </div>
        <a href="tournaments.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
            <i class="fas fa-arrow-left mr-2"></i> Back to Tournaments
        </a>
    </div>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-900 text-green-100 p-4 rounded-lg mb-6">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-900 text-red-100 p-4 rounded-lg mb-6">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Tournament Payment Settings -->
        <div class="md:col-span-1">
            <div class="bg-gray-800 rounded-lg shadow-lg p-6 sticky top-24">
                <h2 class="text-xl font-semibold mb-4 text-white">Payment Settings</h2>
                
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Entry Fee (₹)</label>
                        <input type="number" name="entry_fee" value="<?php echo $tournament['entry_fee']; ?>" min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Prize Pool (₹)</label>
                        <input type="number" name="prize_pool" value="<?php echo $tournament['prize_pool']; ?>" min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-gray-300 text-sm font-medium mb-2">Payment Method</label>
                        <select name="payment_method_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Select Payment Method</option>
                            <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo $method['id']; ?>" <?php echo (isset($tournament['payment_method_id']) && $tournament['payment_method_id'] == $method['id']) ? 'selected' : ''; ?>>
                                    <?php if ($method['method_type'] === 'bank'): ?>
                                        Bank: <?php echo htmlspecialchars($method['bank_name']); ?> (<?php echo substr($method['account_number'], -4); ?>)
                                    <?php else: ?>
                                        UPI: <?php echo htmlspecialchars($method['upi_id']); ?>
                                    <?php endif; ?>
                                    <?php echo $method['is_primary'] ? ' (Primary)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($payment_methods)): ?>
                            <p class="text-yellow-500 text-sm mt-1">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                No payment methods found. <a href="payment_methods.php" class="underline">Add a payment method</a>.
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <button type="submit" name="update_payment_settings" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        Save Payment Settings
                    </button>
                </form>
                
                <div class="mt-6 pt-6 border-t border-gray-700">
                <h3 class="text-lg font-medium mb-2 text-white">Tournament Stats</h3>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-gray-400">Total Participants:</span>
                            <span class="text-white font-medium"><?php echo count($participants); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Paid Participants:</span>
                            <span class="text-green-400 font-medium">
                                <?php 
                                $paid_count = 0;
                                foreach ($participants as $p) {
                                    if ($p['payment_status'] === 'paid') $paid_count++;
                                }
                                echo $paid_count;
                                ?>
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Pending Payments:</span>
                            <span class="text-yellow-400 font-medium">
                                <?php 
                                $pending_count = 0;
                                foreach ($participants as $p) {
                                    if ($p['payment_status'] === 'pending') $pending_count++;
                                }
                                echo $pending_count;
                                ?>
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Pending Verification:</span>
                            <span class="text-blue-400 font-medium">
                                <?php 
                                $verification_count = 0;
                                foreach ($participants as $p) {
                                    if ($p['payment_status'] === 'pending_verification') $verification_count++;
                                }
                                echo $verification_count;
                                ?>
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">Failed Payments:</span>
                            <span class="text-red-400 font-medium">
                                <?php 
                                $failed_count = 0;
                                foreach ($participants as $p) {
                                    if ($p['payment_status'] === 'failed') $failed_count++;
                                }
                                echo $failed_count;
                                ?>
                            </span>
                        </div>
                        <div class="flex justify-between pt-2 border-t border-gray-700">
                            <span class="text-gray-400">Total Revenue:</span>
                            <span class="text-white font-medium">₹<?php echo number_format($paid_count * $tournament['entry_fee'], 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Participants List -->
        <div class="md:col-span-2">
            <div class="bg-gray-800 rounded-lg shadow-lg p-6">
                <h2 class="text-xl font-semibold mb-4 text-white">Participant Payments</h2>
                
                <?php if (empty($participants)): ?>
                    <div class="bg-gray-700 rounded-lg p-6 text-center">
                        <i class="fas fa-users text-gray-500 text-4xl mb-3"></i>
                        <p class="text-gray-400">No participants have registered for this tournament yet.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-700">
                            <thead>
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Participant</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Registration Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-700">
                                <?php foreach ($participants as $participant): ?>
                                    <tr>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div class="w-10 h-10 rounded-full overflow-hidden mr-3">
                                                    <?php if (isset($participant['profile_image']) && $participant['profile_image']): ?>
                                                        <img src="../uploads/profile_images/<?= htmlspecialchars($participant['profile_image']) ?>" 
                                                             alt="<?= htmlspecialchars($participant['username']) ?>" 
                                                             class="w-full h-full object-cover">
                                                    <?php else: ?>
                                                        <div class="w-full h-full bg-gray-600 flex items-center justify-center text-white font-bold">
                                                            <?= strtoupper(substr($participant['username'], 0, 1)) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="text-sm font-medium text-white"><?php echo htmlspecialchars($participant['username']); ?></div>
                                                    <div class="text-sm text-gray-400"><?php echo htmlspecialchars($participant['email']); ?></div>
                                                    <?php if (isset($participant['phone']) && $participant['phone']): ?>
                                                        <div class="text-sm text-gray-400"><?php echo htmlspecialchars($participant['phone']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-300">
                                            <?php echo date('M d, Y g:i A', strtotime($participant['registration_date'])); ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-white">
                                            ₹<?php echo number_format($tournament['entry_fee'], 2); ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm">
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php 
                                                if ($participant['payment_status'] === 'paid') echo 'bg-green-100 text-green-800';
                                                else if ($participant['payment_status'] === 'pending') echo 'bg-yellow-100 text-yellow-800';
                                                else if ($participant['payment_status'] === 'pending_verification') echo 'bg-blue-100 text-blue-800';
                                                else echo 'bg-red-100 text-red-800';
                                                ?>">
                                                <?php 
                                                if ($participant['payment_status'] === 'pending_verification') {
                                                    echo 'Pending Verification';
                                                } else {
                                                    echo ucfirst($participant['payment_status']); 
                                                }
                                                ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm">
                                            <div class="flex space-x-2">
                                                <?php if ($participant['payment_status'] === 'pending_verification'): ?>
                                                    <button type="button" onclick="openPaymentProofModal(<?php echo $participant['id']; ?>)" class="text-blue-400 hover:text-blue-300">
                                                        <i class="fas fa-eye mr-1"></i> View Proof
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" onclick="openUpdateModal(<?php echo $participant['id']; ?>, '<?php echo $participant['payment_status']; ?>')" class="text-blue-400 hover:text-blue-300">
                                                        <i class="fas fa-edit mr-1"></i> Update
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Update Payment Status Modal -->
<div id="updateModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
    <div class="bg-gray-800 rounded-lg shadow-lg max-w-md w-full mx-4">
        <div class="bg-gray-700 px-6 py-4 flex justify-between items-center">
            <h3 class="text-xl font-medium text-white">Update Payment Status</h3>
            <button type="button" onclick="closeUpdateModal()" class="text-gray-400 hover:text-white">
            <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="p-6">
            <form method="POST" id="updateForm">
                <input type="hidden" name="participant_id" id="participant_id">
                
                <div class="mb-4">
                    <label class="block text-gray-300 text-sm font-medium mb-2">Payment Status</label>
                    <select name="payment_status" id="payment_status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="pending">Pending</option>
                        <option value="paid">Paid</option>
                        <option value="failed">Failed</option>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label class="block text-gray-300 text-sm font-medium mb-2">Payment Method</label>
                    <select name="payment_method" id="payment_method" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="cash">Cash</option>
                        <option value="upi">UPI</option>
                        <option value="bank_transfer">Bank Transfer</option>
                        <option value="card">Card</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label class="block text-gray-300 text-sm font-medium mb-2">Transaction ID (Optional)</label>
                    <input type="text" name="transaction_id" id="transaction_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="mb-4">
                    <label class="block text-gray-300 text-sm font-medium mb-2">Notes (Optional)</label>
                    <textarea name="payment_notes" id="payment_notes" rows="2" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
                
                <div class="flex justify-end">
                    <button type="button" onclick="closeUpdateModal()" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded-lg mr-2">
                        Cancel
                    </button>
                    <button type="submit" name="update_payment_status" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Payment Proof Modal -->
<div id="paymentProofModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
    <div class="bg-gray-800 rounded-lg shadow-lg max-w-2xl w-full mx-4">
        <div class="bg-gray-700 px-6 py-4 flex justify-between items-center">
            <h3 class="text-xl font-medium text-white">Payment Proof Verification</h3>
            <button type="button" onclick="closePaymentProofModal()" class="text-gray-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="p-6">
            <div id="paymentProofDetails" class="mb-6">
                <!-- Payment details will be loaded here -->
            </div>
            
            <div id="paymentProofImage" class="mb-6 text-center">
                <!-- Payment proof image will be loaded here -->
            </div>
            
            <div class="flex justify-between">
                <form method="POST" id="rejectForm" class="flex-1 mr-2">
                    <input type="hidden" name="participant_id" id="reject_participant_id">
                    <input type="hidden" name="reject_payment" value="1">
                    <div class="mb-4">
                        <label class="block text-gray-300 text-sm font-medium mb-2">Rejection Reason</label>
                        <textarea name="rejection_reason" rows="2" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-red-500" placeholder="Explain why the payment is being rejected"></textarea>
                    </div>
                    <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                        Reject Payment
                    </button>
                </form>
                
                <form method="POST" id="verifyForm" class="flex-1 ml-2">
                    <input type="hidden" name="participant_id" id="verify_participant_id">
                    <input type="hidden" name="verify_payment" value="1">
                    <div class="mb-4">
                        <p class="text-gray-300 text-sm mb-2">Verify this payment to confirm the participant's registration.</p>
                    </div>
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        Verify Payment
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function openUpdateModal(participantId, currentStatus) {
        document.getElementById('participant_id').value = participantId;
        document.getElementById('payment_status').value = currentStatus;
        document.getElementById('updateModal').classList.remove('hidden');
    }
    
    function closeUpdateModal() {
        document.getElementById('updateModal').classList.add('hidden');
    }
    
    function openPaymentProofModal(participantId) {
        // Set participant IDs for both forms
        document.getElementById('reject_participant_id').value = participantId;
        document.getElementById('verify_participant_id').value = participantId;
        
        // Fetch payment details via AJAX
        fetch('get_payment_details.php?participant_id=' + participantId)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Populate payment details
                    let detailsHtml = `
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <h4 class="text-lg font-medium text-white mb-2">Payment Details</h4>
                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Payment Method:</span>
                                        <span class="text-white">${data.payment_method || 'Not specified'}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Transaction ID:</span>
                                        <span class="text-white">${data.transaction_id || 'Not provided'}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Payment Date:</span>
                                        <span class="text-white">${data.payment_date || 'Not recorded'}</span>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <h4 class="text-lg font-medium text-white mb-2">Participant Info</h4>
                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Name:</span>
                                        <span class="text-white">${data.username}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Email:</span>
                                        <span class="text-white">${data.email}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Registration Date:</span>
                                        <span class="text-white">${data.registration_date}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        ${data.payment_notes ? `
                        <div class="mt-4">
                            <h4 class="text-lg font-medium text-white mb-2">Payment Notes</h4>
                            <div class="bg-gray-700 p-3 rounded-lg text-gray-300">
                                ${data.payment_notes}
                            </div>
                        </div>` : ''}
                    `;
                    
                    document.getElementById('paymentProofDetails').innerHTML = detailsHtml;
                    
                    // Display payment proof image if available
                    if (data.payment_proof) {
                        document.getElementById('paymentProofImage').innerHTML = `
                            <h4 class="text-lg font-medium text-white mb-2">Payment Proof</h4>
                            <div class="bg-white p-2 rounded-lg inline-block">
                                <img src="../uploads/payment_proofs/${data.payment_proof}" alt="Payment Proof" class="max-h-96">
                            </div>
                        `;
                    } else {
                        document.getElementById('paymentProofImage').innerHTML = `
                            <div class="bg-yellow-800 text-yellow-200 p-4 rounded-lg">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                No payment proof image was uploaded.
                            </div>
                        `;
                    }
                } else {
                    document.getElementById('paymentProofDetails').innerHTML = `
                        <div class="bg-red-800 text-red-200 p-4 rounded-lg">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            Error loading payment details: ${data.message}
                        </div>
                    `;
                    document.getElementById('paymentProofImage').innerHTML = '';
                }
                
                document.getElementById('paymentProofModal').classList.remove('hidden');
            })
            .catch(error => {
                console.error('Error fetching payment details:', error);
                document.getElementById('paymentProofDetails').innerHTML = `
                    <div class="bg-red-800 text-red-200 p-4 rounded-lg">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        Error loading payment details. Please try again.
                    </div>
                `;
                document.getElementById('paymentProofImage').innerHTML = '';
                document.getElementById('paymentProofModal').classList.remove('hidden');
            });
    }
    
    function closePaymentProofModal() {
        document.getElementById('paymentProofModal').classList.add('hidden');
    }
    
    // Close modals when clicking outside
    document.addEventListener('click', function(event) {
        const updateModal = document.getElementById('updateModal');
        const paymentProofModal = document.getElementById('paymentProofModal');
        
        if (event.target === updateModal) {
            closeUpdateModal();
        }
        
        if (event.target === paymentProofModal) {
            closePaymentProofModal();
        }
    });
</script>

<?php include '../includes/footer.php'; ?>


