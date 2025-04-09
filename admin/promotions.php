<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$message = '';
$error = '';
$promotions = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new promotion
    if (isset($_POST['add_promotion'])) {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $discount_type = $_POST['discount_type'];
        $discount_value = floatval($_POST['discount_value']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $code = strtoupper(trim($_POST['code']));
        $usage_limit = intval($_POST['usage_limit']);
        $min_purchase = floatval($_POST['min_purchase']);
        $max_discount = floatval($_POST['max_discount']);
        $applicable_to = $_POST['applicable_to'];
        $status = $_POST['status'];
        
        // Validate inputs
        if (empty($title) || empty($code) || empty($start_date) || empty($end_date)) {
            $error = "Please fill in all required fields.";
        } elseif ($discount_value <= 0) {
            $error = "Discount value must be greater than zero.";
        } elseif ($discount_type === 'percentage' && $discount_value > 100) {
            $error = "Percentage discount cannot exceed 100%.";
        } elseif (strtotime($end_date) < strtotime($start_date)) {
            $error = "End date cannot be earlier than start date.";
        } else {
            // Check if code already exists
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM promotions WHERE code = ?");
            $checkStmt->execute([$code]);
            if ($checkStmt->fetchColumn() > 0) {
                $error = "Promotion code already exists. Please use a different code.";
            } else {
                try {
                    $sql = "INSERT INTO promotions (
                                title, description, discount_type, discount_value, 
                                start_date, end_date, code, usage_limit, 
                                min_purchase, max_discount, applicable_to, status
                            ) VALUES (
                                ?, ?, ?, ?, 
                                ?, ?, ?, ?, 
                                ?, ?, ?, ?
                            )";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $title, $description, $discount_type, $discount_value,
                        $start_date, $end_date, $code, $usage_limit,
                        $min_purchase, $max_discount, $applicable_to, $status
                    ]);
                    
                    // Log the activity
                    $activitySql = "
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (
                            ?, 'admin', ?, ?, ?, ?
                        )
                    ";
                    $details = "Admin created new promotion: {$title} (Code: {$code})";
                    $activityStmt = $conn->prepare($activitySql);
                    $activityStmt->execute([
                        $_SESSION['admin_id'],
                        'create_promotion',
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    $message = "Promotion created successfully!";
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
    
    // Update promotion
    if (isset($_POST['update_promotion'])) {
        $promotion_id = intval($_POST['promotion_id']);
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $discount_type = $_POST['discount_type'];
        $discount_value = floatval($_POST['discount_value']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $code = strtoupper(trim($_POST['code']));
        $usage_limit = intval($_POST['usage_limit']);
        $min_purchase = floatval($_POST['min_purchase']);
        $max_discount = floatval($_POST['max_discount']);
        $applicable_to = $_POST['applicable_to'];
        $status = $_POST['status'];
        
        // Validate inputs
        if (empty($title) || empty($code) || empty($start_date) || empty($end_date)) {
            $error = "Please fill in all required fields.";
        } elseif ($discount_value <= 0) {
            $error = "Discount value must be greater than zero.";
        } elseif ($discount_type === 'percentage' && $discount_value > 100) {
            $error = "Percentage discount cannot exceed 100%.";
        } elseif (strtotime($end_date) < strtotime($start_date)) {
            $error = "End date cannot be earlier than start date.";
        } else {
            // Check if code already exists (excluding the current promotion)
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM promotions WHERE code = ? AND id != ?");
            $checkStmt->execute([$code, $promotion_id]);
            if ($checkStmt->fetchColumn() > 0) {
                $error = "Promotion code already exists. Please use a different code.";
            } else {
                try {
                    $sql = "UPDATE promotions SET 
                                title = ?, description = ?, discount_type = ?, discount_value = ?,
                                start_date = ?, end_date = ?, code = ?, usage_limit = ?,
                                min_purchase = ?, max_discount = ?, applicable_to = ?, status = ?,
                                updated_at = NOW()
                            WHERE id = ?";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        $title, $description, $discount_type, $discount_value,
                        $start_date, $end_date, $code, $usage_limit,
                        $min_purchase, $max_discount, $applicable_to, $status,
                        $promotion_id
                    ]);
                    
                    // Log the activity
                    $activitySql = "
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (
                            ?, 'admin', ?, ?, ?, ?
                        )
                    ";
                    $details = "Admin updated promotion ID: {$promotion_id} - {$title} (Code: {$code})";
                    $activityStmt = $conn->prepare($activitySql);
                    $activityStmt->execute([
                        $_SESSION['admin_id'],
                        'update_promotion',
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    $message = "Promotion updated successfully!";
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        }
    }
    
    // Delete promotion
    if (isset($_POST['delete_promotion'])) {
        $promotion_id = intval($_POST['promotion_id']);
        
        try {
            // Get promotion details for logging
            $getStmt = $conn->prepare("SELECT title, code FROM promotions WHERE id = ?");
            $getStmt->execute([$promotion_id]);
            $promotionDetails = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            // Delete the promotion
            $sql = "DELETE FROM promotions WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$promotion_id]);
            
            // Log the activity
            $activitySql = "
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (
                    ?, 'admin', ?, ?, ?, ?
                )
            ";
            $details = "Admin deleted promotion: {$promotionDetails['title']} (Code: {$promotionDetails['code']})";
            $activityStmt = $conn->prepare($activitySql);
            $activityStmt->execute([
                $_SESSION['admin_id'],
                'delete_promotion',
                $details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $message = "Promotion deleted successfully!";
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch all promotions
try {
    $sql = "SELECT * FROM promotions ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching promotions: " . $e->getMessage();
}

// Check if promotions table exists, if not create it
try {
    $tableCheckQuery = "SHOW TABLES LIKE 'promotions'";
    $tableCheckStmt = $conn->prepare($tableCheckQuery);
    $tableCheckStmt->execute();
    
    if ($tableCheckStmt->rowCount() == 0) {
        // Table doesn't exist, create it
        $createTableQuery = "
            CREATE TABLE `promotions` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `title` varchar(255) NOT NULL,
              `description` text DEFAULT NULL,
              `discount_type` enum('percentage','fixed') NOT NULL DEFAULT 'percentage',
              `discount_value` decimal(10,2) NOT NULL,
              `start_date` date NOT NULL,
              `end_date` date NOT NULL,
              `code` varchar(50) NOT NULL,
              `usage_limit` int(11) DEFAULT NULL,
              `usage_count` int(11) NOT NULL DEFAULT 0,
              `min_purchase` decimal(10,2) DEFAULT NULL,
              `max_discount` decimal(10,2) DEFAULT NULL,
              `applicable_to` enum('all','membership','class','product') NOT NULL DEFAULT 'all',
              `status` enum('active','inactive','expired') NOT NULL DEFAULT 'active',
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
              PRIMARY KEY (`id`),
              UNIQUE KEY `code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        
        $conn->exec($createTableQuery);
        $message = "Promotions table created successfully.";
    }
} catch (PDOException $e) {
    $error = "Error checking/creating promotions table: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Promotions - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .modal {
            transition: opacity 0.25s ease;
        }
        body.modal-active {
            overflow-x: hidden;
            overflow-y: visible !important;
        }
        .animate-fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Manage Promotions</h1>
            <button id="openAddModal" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                <i class="fas fa-plus mr-2"></i> Add New Promotion
            </button>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="bg-green-600 text-white p-4 rounded-lg mb-6 animate-fade-in">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-600 text-white p-4 rounded-lg mb-6 animate-fade-in">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Promotions Table -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-semibold">All Promotions</h2>
                <p class="text-gray-400 mt-1">Manage promotional offers and discount codes</p>
            </div>
            
            <?php if (empty($promotions)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-ticket-alt text-4xl mb-3"></i>
                    <p>No promotions found. Create your first promotion to get started.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-gray-800">
                        <thead>
                            <tr class="bg-gray-700">
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Code</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Title</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Discount</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Validity</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Usage</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                            <?php foreach ($promotions as $promotion): ?>
                                <?php 
                                    $isActive = $promotion['status'] === 'active';
                                    $isExpired = strtotime($promotion['end_date']) < strtotime(date('Y-m-d'));
                                    $statusClass = $isActive ? 'bg-green-900 text-green-300' : 'bg-red-900 text-red-300';
                                    if ($isExpired) {
                                        $statusClass = 'bg-gray-700 text-gray-400';
                                    }
                                ?>
                                <tr class="hover:bg-gray-700 transition-colors duration-200">
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <span class="font-mono font-medium text-yellow-500"><?php echo htmlspecialchars($promotion['code']); ?></span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="font-medium"><?php echo htmlspecialchars($promotion['title']); ?></div>
                                        <div class="text-sm text-gray-400 truncate max-w-xs"><?php echo htmlspecialchars($promotion['description']); ?></div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php if ($promotion['discount_type'] === 'percentage'): ?>
                                            <span class="text-green-400"><?php echo $promotion['discount_value']; ?>%</span>
                                        <?php else: ?>
                                            <span class="text-green-400">₹<?php echo number_format($promotion['discount_value'], 2); ?></span>
                                        <?php endif; ?>
                                        
                                        <?php if ($promotion['min_purchase'] > 0): ?>
                                            <div class="text-xs text-gray-400 mt-1">
                                                Min: ₹<?php echo number_format($promotion['min_purchase'], 2); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($promotion['max_discount'] > 0): ?>
                                            <div class="text-xs text-gray-400">
                                                Max: ₹<?php echo number_format($promotion['max_discount'], 2); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="text-sm">
                                            <div><?php echo date('M d, Y', strtotime($promotion['start_date'])); ?></div>
                                            <div class="text-gray-400">to</div>
                                            <div><?php echo date('M d, Y', strtotime($promotion['end_date'])); ?></div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php if ($promotion['usage_limit']): ?>
                                            <div class="text-sm">
                                                <span class="font-medium"><?php echo $promotion['usage_count']; ?></span>
                                                <span class="text-gray-400">/<?php echo $promotion['usage_limit']; ?></span>
                                            </div>
                                            
                                            <?php 
                                                $usagePercent = ($promotion['usage_limit'] > 0) ? 
                                                    min(100, ($promotion['usage_count'] / $promotion['usage_limit']) * 100) : 0;
                                            ?>
                                            <div class="w-24 bg-gray-700 rounded-full h-1.5 mt-1">
                                                <div class="bg-blue-500 h-1.5 rounded-full" style="width: <?php echo $usagePercent; ?>%"></div>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-sm">
                                                <span class="font-medium"><?php echo $promotion['usage_count']; ?></span>
                                                <span class="text-gray-400">/∞</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-1 text-xs rounded-full <?php echo $statusClass; ?>">
                                            <?php 
                                                if ($isExpired) {
                                                    echo 'Expired';
                                                } else {
                                                    echo ucfirst($promotion['status']);
                                                }
                                            ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex space-x-2">
                                            <button class="text-blue-400 hover:text-blue-300 transition-colors duration-200 edit-btn"
                                                    data-id="<?php echo $promotion['id']; ?>"
                                                    data-title="<?php echo htmlspecialchars($promotion['title']); ?>"
                                                    data-description="<?php echo htmlspecialchars($promotion['description']); ?>"
                                                    data-discount-type="<?php echo $promotion['discount_type']; ?>"
                                                    data-discount-value="<?php echo $promotion['discount_value']; ?>"
                                                    data-start-date="<?php echo $promotion['start_date']; ?>"
                                                    data-end-date="<?php echo $promotion['end_date']; ?>"
                                                    data-code="<?php echo htmlspecialchars($promotion['code']); ?>"
                                                    data-usage-limit="<?php echo $promotion['usage_limit']; ?>"
                                                    data-min-purchase="<?php echo $promotion['min_purchase']; ?>"
                                                    data-max-discount="<?php echo $promotion['max_discount']; ?>"
                                                    data-applicable-to="<?php echo $promotion['applicable_to']; ?>"
                                                    data-status="<?php echo $promotion['status']; ?>">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="text-red-400 hover:text-red-300 transition-colors duration-200 delete-btn"
                                                    data-id="<?php echo $promotion['id']; ?>"
                                                    data-title="<?php echo htmlspecialchars($promotion['title']); ?>">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
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
    
    <!-- Add Promotion Modal -->
    <div id="addModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-2xl mx-auto rounded-xl shadow-lg z-50 overflow-y-auto max-h-[90vh]">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold">Add New Promotion</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <form method="POST" class="mt-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-gray-300 mb-1">Title <span class="text-red-500">*</span></label>
                            <input type="text" name="title" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div class="col-span-2">
                            <label class="block text-gray-300 mb-1">Description</label>
                            <textarea name="description" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500"></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Discount Type <span class="text-red-500">*</span></label>
                            <select name="discount_type" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (₹)</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Discount Value <span class="text-red-500">*</span></label>
                            <input type="number" name="discount_value" required min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Start Date <span class="text-red-500">*</span></label>
                            <input type="date" name="start_date" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">End Date <span class="text-red-500">*</span></label>
                            <input type="date" name="end_date" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Promotion Code <span class="text-red-500">*</span></label>
                            <input type="text" name="code" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Usage Limit (leave empty for unlimited)</label>
                            <input type="number" name="usage_limit" min="0" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Minimum Purchase Amount (₹)</label>
                            <input type="number" name="min_purchase" min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Maximum Discount Amount (₹)</label>
                            <input type="number" name="max_discount" min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Applicable To <span class="text-red-500">*</span></label>
                            <select name="applicable_to" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <option value="all">All Products</option>
                                <option value="membership">Memberships Only</option>
                                <option value="class">Classes Only</option>
                                <option value="product">Products Only</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Status <span class="text-red-500">*</span></label>
                            <select name="status" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                            Cancel
                        </button>
                        <button type="submit" name="add_promotion" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg">
                            Create Promotion
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
       <!-- Edit Promotion Modal -->
       <div id="editModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-2xl mx-auto rounded-xl shadow-lg z-50 overflow-y-auto max-h-[90vh]">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold">Edit Promotion</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <form method="POST" class="mt-4">
                    <input type="hidden" name="promotion_id" id="edit_promotion_id">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="col-span-2">
                            <label class="block text-gray-300 mb-1">Title <span class="text-red-500">*</span></label>
                            <input type="text" name="title" id="edit_title" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div class="col-span-2">
                            <label class="block text-gray-300 mb-1">Description</label>
                            <textarea name="description" id="edit_description" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500"></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Discount Type <span class="text-red-500">*</span></label>
                            <select name="discount_type" id="edit_discount_type" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <option value="percentage">Percentage (%)</option>
                                <option value="fixed">Fixed Amount (₹)</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Discount Value <span class="text-red-500">*</span></label>
                            <input type="number" name="discount_value" id="edit_discount_value" required min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Start Date <span class="text-red-500">*</span></label>
                            <input type="date" name="start_date" id="edit_start_date" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">End Date <span class="text-red-500">*</span></label>
                            <input type="date" name="end_date" id="edit_end_date" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Promotion Code <span class="text-red-500">*</span></label>
                            <input type="text" name="code" id="edit_code" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Usage Limit (leave empty for unlimited)</label>
                            <input type="number" name="usage_limit" id="edit_usage_limit" min="0" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Minimum Purchase Amount (₹)</label>
                            <input type="number" name="min_purchase" id="edit_min_purchase" min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Maximum Discount Amount (₹)</label>
                            <input type="number" name="max_discount" id="edit_max_discount" min="0" step="0.01" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Applicable To <span class="text-red-500">*</span></label>
                            <select name="applicable_to" id="edit_applicable_to" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <option value="all">All Products</option>
                                <option value="membership">Memberships Only</option>
                                <option value="class">Classes Only</option>
                                <option value="product">Products Only</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-gray-300 mb-1">Status <span class="text-red-500">*</span></label>
                            <select name="status" id="edit_status" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                            Cancel
                        </button>
                        <button type="submit" name="update_promotion" class="bg-yellow-500 hover:bg-yellow-600 text-black font-bold py-2 px-4 rounded-lg">
                            Update Promotion
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold text-red-500">Confirm Deletion</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <div class="my-4">
                    <p class="text-white">Are you sure you want to delete the promotion: <span id="delete_promotion_title" class="font-semibold"></span>?</p>
                    <p class="text-gray-400 mt-2">This action cannot be undone.</p>
                </div>
                
                <form method="POST" class="mt-6 flex justify-end">
                    <input type="hidden" name="promotion_id" id="delete_promotion_id">
                    
                    <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                        Cancel
                    </button>
                    <button type="submit" name="delete_promotion" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Modal functionality
        function toggleModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.toggle('opacity-0');
            modal.classList.toggle('pointer-events-none');
            document.body.classList.toggle('modal-active');
        }
        
        // Add modal
        document.getElementById('openAddModal').addEventListener('click', function() {
            toggleModal('addModal');
            
            // Set default dates
            const today = new Date().toISOString().split('T')[0];
            document.querySelector('#addModal input[name="start_date"]').value = today;
            
            const nextMonth = new Date();
            nextMonth.setMonth(nextMonth.getMonth() + 1);
            document.querySelector('#addModal input[name="end_date"]').value = nextMonth.toISOString().split('T')[0];
        });
        
        // Edit modal
        document.querySelectorAll('.edit-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const title = this.getAttribute('data-title');
                const description = this.getAttribute('data-description');
                const discountType = this.getAttribute('data-discount-type');
                const discountValue = this.getAttribute('data-discount-value');
                const startDate = this.getAttribute('data-start-date');
                const endDate = this.getAttribute('data-end-date');
                const code = this.getAttribute('data-code');
                const usageLimit = this.getAttribute('data-usage-limit');
                const minPurchase = this.getAttribute('data-min-purchase');
                const maxDiscount = this.getAttribute('data-max-discount');
                const applicableTo = this.getAttribute('data-applicable-to');
                const status = this.getAttribute('data-status');
                
                document.getElementById('edit_promotion_id').value = id;
                document.getElementById('edit_title').value = title;
                document.getElementById('edit_description').value = description;
                document.getElementById('edit_discount_type').value = discountType;
                document.getElementById('edit_discount_value').value = discountValue;
                document.getElementById('edit_start_date').value = startDate;
                document.getElementById('edit_end_date').value = endDate;
                document.getElementById('edit_code').value = code;
                document.getElementById('edit_usage_limit').value = usageLimit;
                document.getElementById('edit_min_purchase').value = minPurchase;
                document.getElementById('edit_max_discount').value = maxDiscount;
                document.getElementById('edit_applicable_to').value = applicableTo;
                document.getElementById('edit_status').value = status;
                
                toggleModal('editModal');
            });
        });
        
        // Delete modal
        document.querySelectorAll('.delete-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const title = this.getAttribute('data-title');
                
                document.getElementById('delete_promotion_id').value = id;
                document.getElementById('delete_promotion_title').textContent = title;
                
                toggleModal('deleteModal');
            });
        });
        
        // Close modals
        document.querySelectorAll('.modal-close').forEach(button => {
            button.addEventListener('click', function() {
                const modal = this.closest('.modal');
                toggleModal(modal.id);
            });
        });
        
        // Close modals when clicking on overlay
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function() {
                const modal = this.closest('.modal');
                toggleModal(modal.id);
            });
        });
        
        // Close modals with ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.body.classList.contains('modal-active')) {
                document.querySelectorAll('.modal').forEach(modal => {
                    if (!modal.classList.contains('opacity-0')) {
                        toggleModal(modal.id);
                    }
                });
            }
        });
        
        // Auto-generate promotion code
        document.querySelector('#addModal input[name="title"]').addEventListener('input', function() {
            const codeInput = document.querySelector('#addModal input[name="code"]');
            if (!codeInput.value) {
                // Generate code from title (uppercase, no spaces, alphanumeric only)
                const code = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                codeInput.value = code;
            }
        });
        
        // Discount type change handler
        document.querySelectorAll('select[name="discount_type"]').forEach(select => {
            select.addEventListener('change', function() {
                const valueInput = this.closest('form').querySelector('input[name="discount_value"]');
                const maxDiscountInput = this.closest('form').querySelector('input[name="max_discount"]');
                
                if (this.value === 'percentage') {
                    valueInput.setAttribute('max', '100');
                    if (parseFloat(valueInput.value) > 100) {
                        valueInput.value = '100';
                    }
                    maxDiscountInput.closest('div').style.display = 'block';
                } else {
                    valueInput.removeAttribute('max');
                    maxDiscountInput.closest('div').style.display = 'none';
                    maxDiscountInput.value = '';
                }
            });
        });
                // Initialize discount type handlers on page load
                document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('select[name="discount_type"]').forEach(select => {
                const valueInput = select.closest('form').querySelector('input[name="discount_value"]');
                const maxDiscountInput = select.closest('form').querySelector('input[name="max_discount"]');
                
                if (select.value === 'percentage') {
                    valueInput.setAttribute('max', '100');
                    maxDiscountInput.closest('div').style.display = 'block';
                } else {
                    valueInput.removeAttribute('max');
                    maxDiscountInput.closest('div').style.display = 'none';
                }
            });
            
            // Set today's date as default for new promotions
            const startDateInput = document.querySelector('#addModal input[name="start_date"]');
            if (startDateInput && !startDateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                startDateInput.value = today;
                
                // Set end date to one month from today
                const endDateInput = document.querySelector('#addModal input[name="end_date"]');
                if (endDateInput && !endDateInput.value) {
                    const nextMonth = new Date();
                    nextMonth.setMonth(nextMonth.getMonth() + 1);
                    endDateInput.value = nextMonth.toISOString().split('T')[0];
                }
            }
        });
        
        // Validate date ranges
        document.querySelectorAll('input[name="start_date"]').forEach(input => {
            input.addEventListener('change', function() {
                const endDateInput = this.closest('form').querySelector('input[name="end_date"]');
                if (endDateInput.value && this.value > endDateInput.value) {
                    endDateInput.value = this.value;
                }
            });
        });
        
        document.querySelectorAll('input[name="end_date"]').forEach(input => {
            input.addEventListener('change', function() {
                const startDateInput = this.closest('form').querySelector('input[name="start_date"]');
                if (startDateInput.value && this.value < startDateInput.value) {
                    this.value = startDateInput.value;
                    alert('End date cannot be earlier than start date.');
                }
            });
        });
        
        // Code validation - uppercase and no spaces
        document.querySelectorAll('input[name="code"]').forEach(input => {
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase().replace(/\s/g, '');
            });
        });
        
        // Form validation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const discountType = this.querySelector('select[name="discount_type"]').value;
                const discountValue = parseFloat(this.querySelector('input[name="discount_value"]').value);
                
                if (discountType === 'percentage' && (discountValue <= 0 || discountValue > 100)) {
                    e.preventDefault();
                    alert('Percentage discount must be between 0 and 100.');
                    return false;
                }
                
                if (discountType === 'fixed' && discountValue <= 0) {
                    e.preventDefault();
                    alert('Fixed discount must be greater than 0.');
                    return false;
                }
                
                const startDate = this.querySelector('input[name="start_date"]').value;
                const endDate = this.querySelector('input[name="end_date"]').value;
                
                if (startDate > endDate) {
                    e.preventDefault();
                    alert('End date cannot be earlier than start date.');
                    return false;
                }
                
                return true;
            });
        });
        
        // Auto-generate random code button
        function generateRandomCode() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let code = '';
            for (let i = 0; i < 8; i++) {
                code += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            return code;
        }
        
        // Add random code generation buttons
        document.addEventListener('DOMContentLoaded', function() {
            const addCodeInput = document.querySelector('#addModal input[name="code"]');
            const editCodeInput = document.querySelector('#editModal input[name="code"]');
            
            if (addCodeInput) {
                const randomBtn = document.createElement('button');
                randomBtn.type = 'button';
                randomBtn.className = 'absolute right-2 top-1/2 transform -translate-y-1/2 text-xs bg-gray-600 hover:bg-gray-500 text-white px-2 py-1 rounded';
                randomBtn.textContent = 'Random';
                randomBtn.onclick = function() {
                    addCodeInput.value = generateRandomCode();
                };
                
                const wrapper = document.createElement('div');
                wrapper.className = 'relative';
                addCodeInput.parentNode.insertBefore(wrapper, addCodeInput);
                wrapper.appendChild(addCodeInput);
                wrapper.appendChild(randomBtn);
            }
            
            if (editCodeInput) {
                const randomBtn = document.createElement('button');
                randomBtn.type = 'button';
                randomBtn.className = 'absolute right-2 top-1/2 transform -translate-y-1/2 text-xs bg-gray-600 hover:bg-gray-500 text-white px-2 py-1 rounded';
                randomBtn.textContent = 'Random';
                randomBtn.onclick = function() {
                    editCodeInput.value = generateRandomCode();
                };
                
                const wrapper = document.createElement('div');
                wrapper.className = 'relative';
                editCodeInput.parentNode.insertBefore(wrapper, editCodeInput);
                wrapper.appendChild(editCodeInput);
                wrapper.appendChild(randomBtn);
            }
        });
        
        // Add copy button for promotion codes
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.font-mono').forEach(codeElement => {
                const code = codeElement.textContent;
                codeElement.innerHTML = `${code} <button type="button" class="copy-code ml-1 text-xs bg-gray-700 hover:bg-gray-600 text-white px-1 rounded" data-code="${code}"><i class="far fa-copy"></i></button>`;
            });
            
            document.addEventListener('click', function(e) {
                if (e.target.closest('.copy-code')) {
                    const button = e.target.closest('.copy-code');
                    const code = button.getAttribute('data-code');
                    
                    navigator.clipboard.writeText(code).then(() => {
                        const originalHTML = button.innerHTML;
                        button.innerHTML = '<i class="fas fa-check"></i>';
                        button.classList.add('bg-green-600');
                        
                        setTimeout(() => {
                            button.innerHTML = originalHTML;
                            button.classList.remove('bg-green-600');
                        }, 1500);
                    });
                }
            });
        });
    </script>
</body>
</html>



