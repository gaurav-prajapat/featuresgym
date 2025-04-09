<?php
ob_start();
require_once '../config/database.php';
include '../includes/navbar.php';

// Check if gym owner is logged in
if (!isset($_SESSION['owner_id'])) {
    $_SESSION['error'] = "Please log in to access this page.";
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new payment method
        if ($_POST['action'] === 'add') {
            $method_type = $_POST['method_type'];
            $is_primary = isset($_POST['is_primary']) ? 1 : 0;
            
            // If setting as primary, unset all other primary methods first
            if ($is_primary) {
                $updateStmt = $conn->prepare("UPDATE payment_methods SET is_primary = 0 WHERE owner_id = ?");
                $updateStmt->execute([$owner_id]);
            }
            
            if ($method_type === 'bank') {
                $account_name = $_POST['account_name'];
                $account_number = $_POST['account_number'];
                $ifsc_code = $_POST['ifsc_code'];
                $bank_name = $_POST['bank_name'];
                
                $stmt = $conn->prepare("INSERT INTO payment_methods (owner_id, method_type, account_name, account_number, ifsc_code, bank_name, is_primary) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$owner_id, $method_type, $account_name, $account_number, $ifsc_code, $bank_name, $is_primary]);
            } else if ($method_type === 'upi') {
                $upi_id = $_POST['upi_id'];
                
                $stmt = $conn->prepare("INSERT INTO payment_methods (owner_id, method_type, upi_id, is_primary) VALUES (?, ?, ?, ?)");
                $stmt->execute([$owner_id, $method_type, $upi_id, $is_primary]);
            }
            
            $_SESSION['success'] = "Payment method added successfully.";
            header('Location: payment_methods.php');
            exit();
        }
        
        // Set payment method as primary
        else if ($_POST['action'] === 'set_primary') {
            $method_id = $_POST['method_id'];
            
            // Unset all primary methods first
            $updateStmt = $conn->prepare("UPDATE payment_methods SET is_primary = 0 WHERE owner_id = ?");
            $updateStmt->execute([$owner_id]);
            
            // Set the selected method as primary
            $updateStmt = $conn->prepare("UPDATE payment_methods SET is_primary = 1 WHERE id = ? AND owner_id = ?");
            $updateStmt->execute([$method_id, $owner_id]);
            
            $_SESSION['success'] = "Primary payment method updated.";
            header('Location: payment_methods.php');
            exit();
        }
        
        // Delete payment method
        else if ($_POST['action'] === 'delete') {
            $method_id = $_POST['method_id'];
            
            $stmt = $conn->prepare("DELETE FROM payment_methods WHERE id = ? AND owner_id = ?");
            $stmt->execute([$method_id, $owner_id]);
            
            $_SESSION['success'] = "Payment method deleted successfully.";
            header('Location: payment_methods.php');
            exit();
        }
    }
}

// Get all payment methods for this owner
$stmt = $conn->prepare("SELECT * FROM payment_methods WHERE owner_id = ? ORDER BY is_primary DESC, created_at DESC");
$stmt->execute([$owner_id]);
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get withdrawal history
$stmt = $conn->prepare("
    SELECT w.*, pm.method_type, pm.account_name, pm.bank_name, pm.upi_id 
    FROM withdrawals w
    LEFT JOIN payment_methods pm ON w.payment_method_id = pm.id
    WHERE w.gym_id = ?
    ORDER BY w.created_at DESC
    LIMIT 10
");
$stmt->execute([$gym_id]);
$withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container mx-auto px-4 py-8 pt-20">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Payment Methods</h1>
        <button type="button" onclick="openAddMethodModal()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
            <i class="fas fa-plus mr-2"></i>Add Payment Method
        </button>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-500 text-white p-4 rounded-lg mb-6">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-500 text-white p-4 rounded-lg mb-6">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Payment Methods Section -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-8">
        <h2 class="text-xl font-semibold mb-4">Your Payment Methods</h2>
        
        <?php if (empty($payment_methods)): ?>
            <div class="bg-gray-700 rounded-lg p-6 text-center">
                <i class="fas fa-credit-card text-gray-500 text-4xl mb-4"></i>
                <p class="text-gray-400">You haven't added any payment methods yet.</p>
                <p class="text-gray-400 mt-2">Add a payment method to receive your earnings.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($payment_methods as $method): ?>
                    <div class="bg-gray-700 rounded-lg p-6 relative">
                        <?php if ($method['is_primary']): ?>
                            <span class="absolute top-2 right-2 bg-yellow-500 text-black text-xs px-2 py-1 rounded-full">Primary</span>
                        <?php endif; ?>
                        
                        <div class="flex items-start mb-4">
                            <div class="bg-gray-600 p-3 rounded-full mr-4">
                                <i class="fas <?php echo $method['method_type'] === 'bank' ? 'fa-university' : 'fa-mobile-alt'; ?> text-2xl text-blue-400"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-medium">
                                    <?php echo $method['method_type'] === 'bank' ? 'Bank Account' : 'UPI ID'; ?>
                                </h3>
                                <p class="text-gray-400">Added on <?php echo date('M d, Y', strtotime($method['created_at'])); ?></p>
                            </div>
                        </div>
                        
                        <?php if ($method['method_type'] === 'bank'): ?>
                            <div class="space-y-2 mb-4">
                                <p><span class="text-gray-400">Account Name:</span> <?php echo htmlspecialchars($method['account_name']); ?></p>
                                <p><span class="text-gray-400">Bank:</span> <?php echo htmlspecialchars($method['bank_name']); ?></p>
                                <p><span class="text-gray-400">Account Number:</span> •••• <?php echo substr($method['account_number'], -4); ?></p>
                                <p><span class="text-gray-400">IFSC Code:</span> <?php echo htmlspecialchars($method['ifsc_code']); ?></p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-2 mb-4">
                                <p><span class="text-gray-400">UPI ID:</span> <?php echo htmlspecialchars($method['upi_id']); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <div class="flex space-x-2">
                            <?php if (!$method['is_primary']): ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="set_primary">
                                    <input type="hidden" name="method_id" value="<?php echo $method['id']; ?>">
                                    <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black px-3 py-1 rounded transition-colors duration-200">
                                        <i class="fas fa-star mr-1"></i> Set as Primary
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete this payment method?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="method_id" value="<?php echo $method['id']; ?>">
                                <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded transition-colors duration-200">
                                    <i class="fas fa-trash-alt mr-1"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Withdrawals Section -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6">
        <h2 class="text-xl font-semibold mb-4">Recent Withdrawals</h2>
        
        <?php if (empty($withdrawals)): ?>
            <div class="bg-gray-700 rounded-lg p-6 text-center">
                <i class="fas fa-hand-holding-usd text-gray-500 text-4xl mb-4"></i>
                <p class="text-gray-400">No withdrawal history found.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead class="bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Amount</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Payment Method</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Transaction ID</th>
                        </tr>
                    </thead>
                    <tbody class="bg-gray-800 divide-y divide-gray-700">
                        <?php foreach ($withdrawals as $withdrawal): ?>
                            <tr class="hover:bg-gray-700 transition-colors duration-150">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo date('M d, Y', strtotime($withdrawal['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-yellow-400 font-medium">₹<?php echo number_format($withdrawal['amount'], 2); ?></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($withdrawal['method_type'] === 'bank'): ?>
                                        <span class="flex items-center">
                                            <i class="fas fa-university mr-2 text-blue-400"></i>
                                            <?php echo htmlspecialchars($withdrawal['bank_name']); ?> (<?php echo substr($withdrawal['account_name'], 0, 10) . '...'; ?>)
                                        </span>
                                    <?php elseif ($withdrawal['method_type'] === 'upi'): ?>
                                        <span class="flex items-center">
                                            <i class="fas fa-mobile-alt mr-2 text-green-400"></i>
                                            <?php echo htmlspecialchars($withdrawal['upi_id']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400">Not specified</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $statusClass = '';
                                    switch ($withdrawal['status']) {
                                        case 'pending':
                                            $statusClass = 'bg-yellow-100 text-yellow-800';
                                            break;
                                        case 'completed':
                                            $statusClass = 'bg-green-100 text-green-800';
                                            break;
                                        case 'failed':
                                            $statusClass = 'bg-red-100 text-red-800';
                                            break;
                                    }
                                    ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($withdrawal['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($withdrawal['transaction_id']): ?>
                                        <span class="text-gray-300"><?php echo $withdrawal['transaction_id']; ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-500">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (count($withdrawals) >= 10): ?>
                <div class="mt-4 text-center">
                    <a href="withdrawal_history.php" class="text-blue-400 hover:text-blue-300">
                        View all withdrawals <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Add Payment Method Modal -->
<div id="addMethodModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
    <div class="bg-gray-800 rounded-lg shadow-lg max-w-md w-full mx-4">
        <div class="bg-gray-700 px-6 py-4 flex justify-between items-center">
            <h3 class="text-xl font-medium">Add Payment Method</h3>
            <button type="button" onclick="closeAddMethodModal()" class="text-gray-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="p-6">
            <div class="mb-4">
                <div class="flex space-x-4 mb-6">
                    <button type="button" onclick="showMethodForm('bank')" id="bankTabBtn" class="flex-1 py-2 px-4 bg-blue-500 text-white rounded-lg">
                        <i class="fas fa-university mr-2"></i> Bank Account
                    </button>
                    <button type="button" onclick="showMethodForm('upi')" id="upiTabBtn" class="flex-1 py-2 px-4 bg-gray-700 text-white rounded-lg">
                        <i class="fas fa-mobile-alt mr-2"></i> UPI
                    </button>
                </div>
                
                <!-- Bank Account Form -->
                <form method="POST" id="bankForm">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="method_type" value="bank">
                    
                    <div class="mb-4">
                        <label class="block text-gray-300 text-sm font-medium mb-2">Account Holder Name</label>
                        <input type="text" name="account_name" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-300 text-sm font-medium mb-2">Bank Name</label>
                        <input type="text" name="bank_name" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-300 text-sm font-medium mb-2">Account Number</label>
                        <input type="text" name="account_number" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-300 text-sm font-medium mb-2">IFSC Code</label>
                        <input type="text" name="ifsc_code" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="mb-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_primary" class="form-checkbox h-5 w-5 text-blue-500">
                            <span class="ml-2 text-gray-300">Set as primary payment method</span>
                        </label>
                    </div>
                    
                    <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white py-2 px-4 rounded-lg transition-colors duration-200">
                        Add Bank Account
                    </button>
                </form>
                
                <!-- UPI Form -->
                <form method="POST" id="upiForm" class="hidden">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="method_type" value="upi">
                    
                    <div class="mb-4">
                        <label class="block text-gray-300 text-sm font-medium mb-2">UPI ID</label>
                        <input type="text" name="upi_id" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="example@upi">
                    </div>
                    
                    <div class="mb-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_primary" class="form-checkbox h-5 w-5 text-blue-500">
                            <span class="ml-2 text-gray-300">Set as primary payment method</span>
                        </label>
                    </div>
                    
                    <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white py-2 px-4 rounded-lg transition-colors duration-200">
                        Add UPI ID
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    function openAddMethodModal() {
        document.getElementById('addMethodModal').classList.remove('hidden');
    }
    
    function closeAddMethodModal() {
        document.getElementById('addMethodModal').classList.add('hidden');
    }
    
    function showMethodForm(type) {
        // Update tab buttons
        document.getElementById('bankTabBtn').classList.remove('bg-blue-500');
        document.getElementById('bankTabBtn').classList.add('bg-gray-700');
        document.getElementById('upiTabBtn').classList.remove('bg-blue-500');
        document.getElementById('upiTabBtn').classList.add('bg-gray-700');
        
        document.getElementById(type + 'TabBtn').classList.remove('bg-gray-700');
        document.getElementById(type + 'TabBtn').classList.add('bg-blue-500');
        
        // Show/hide forms
        document.getElementById('bankForm').classList.add('hidden');
        document.getElementById('upiForm').classList.add('hidden');
        document.getElementById(type + 'Form').classList.remove('hidden');
    }
    
    // Close modal when clicking outside
    document.addEventListener('click', function(event) {
        const modal = document.getElementById('addMethodModal');
        const modalContent = modal.querySelector('div');
        
        if (event.target === modal) {
            closeAddMethodModal();
        }
    });
</script>

<?php include '../includes/footer.php'; ?>

