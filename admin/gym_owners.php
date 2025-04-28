<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$message = '';
$error = '';
$owners = [];
$totalOwners = 0;

// Pagination settings
$ownersPerPage = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $ownersPerPage;

// Filter settings
$filterCity = isset($_GET['city']) ? $_GET['city'] : '';
$filterState = isset($_GET['state']) ? $_GET['state'] : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update owner status
    if (isset($_POST['update_status'])) {
        $ownerId = isset($_POST['owner_id']) ? (int)$_POST['owner_id'] : 0;
        $newStatus = isset($_POST['status']) ? $_POST['status'] : '';
        
        if ($ownerId > 0 && in_array($newStatus, ['active', 'inactive', 'suspended'])) {
            try {
                $sql = "UPDATE gym_owners SET status = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$newStatus, $ownerId]);
                
                // Log the activity
                $activitySql = "
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (
                        ?, 'admin', ?, ?, ?, ?
                    )
                ";
                $details = "Admin updated gym owner (ID: {$ownerId}) status to {$newStatus}";
                $activityStmt = $conn->prepare($activitySql);
                $activityStmt->execute([
                    $_SESSION['user_id'],
                    'update_owner_status',
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $message = "Gym owner status has been updated to " . ucfirst($newStatus);
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid owner ID or status.";
        }
    }
    
    // Update gym limit
    if (isset($_POST['update_gym_limit'])) {
        $ownerId = isset($_POST['owner_id']) ? (int)$_POST['owner_id'] : 0;
        $newLimit = isset($_POST['gym_limit']) ? (int)$_POST['gym_limit'] : 0;
        
        if ($ownerId > 0 && $newLimit >= 0) {
            try {
                $sql = "UPDATE gym_owners SET gym_limit = ? WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$newLimit, $ownerId]);
                
                // Log the activity
                $activitySql = "
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (
                        ?, 'admin', ?, ?, ?, ?
                    )
                ";
                $details = "Admin updated gym owner (ID: {$ownerId}) gym limit to {$newLimit}";
                $activityStmt = $conn->prepare($activitySql);
                $activityStmt->execute([
                    $_SESSION['user_id'],
                    'update_gym_limit',
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $message = "Gym owner's gym limit has been updated to {$newLimit}";
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid owner ID or gym limit value.";
        }
    }
    
    // Delete owner
    if (isset($_POST['delete_owner'])) {
        $ownerId = isset($_POST['owner_id']) ? (int)$_POST['owner_id'] : 0;
        
        if ($ownerId > 0) {
            try {
                // Check if owner has active gyms
                $checkSql = "SELECT COUNT(*) FROM gyms WHERE owner_id = ? AND status = 'active'";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$ownerId]);
                $activeGyms = $checkStmt->fetchColumn();
                
                if ($activeGyms > 0) {
                    $error = "Cannot delete owner with active gyms. Please deactivate the owner instead.";
                } else {
                    // Get owner details for logging
                    $ownerDetailsSql = "SELECT name, email FROM gym_owners WHERE id = ?";
                    $ownerDetailsStmt = $conn->prepare($ownerDetailsSql);
                    $ownerDetailsStmt->execute([$ownerId]);
                    $ownerDetails = $ownerDetailsStmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Delete owner
                    $sql = "DELETE FROM gym_owners WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$ownerId]);
                    
                    // Log the activity
                    $activitySql = "
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (
                            ?, 'admin', ?, ?, ?, ?
                        )
                    ";
                    $details = "Admin deleted gym owner (ID: {$ownerId}, Name: {$ownerDetails['name']}, Email: {$ownerDetails['email']})";
                    $activityStmt = $conn->prepare($activitySql);
                    $activityStmt->execute([
                        $_SESSION['user_id'],
                        'delete_gym_owner',
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    $message = "Gym owner has been permanently deleted.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid owner ID.";
        }
    }
}

// Build query with filters
$sql = "SELECT o.*, 
        (SELECT COUNT(*) FROM gyms g WHERE g.owner_id = o.id) as gym_count,
        (SELECT COUNT(*) FROM gyms g WHERE g.owner_id = o.id AND g.status = 'active') as active_gyms
        FROM gym_owners o
        WHERE 1=1";
$countSql = "SELECT COUNT(*) FROM gym_owners o WHERE 1=1";
$params = [];
$countParams = [];

if (!empty($filterCity)) {
    $sql .= " AND o.city = ?";
    $countSql .= " AND o.city = ?";
    $params[] = $filterCity;
    $countParams[] = $filterCity;
}

if (!empty($filterState)) {
    $sql .= " AND o.state = ?";
    $countSql .= " AND o.state = ?";
    $params[] = $filterState;
    $countParams[] = $filterState;
}

if (!empty($filterStatus)) {
    $sql .= " AND o.status = ?";
    $countSql .= " AND o.status = ?";
    $params[] = $filterStatus;
    $countParams[] = $filterStatus;
}

if (!empty($searchTerm)) {
    $sql .= " AND (o.name LIKE ? OR o.email LIKE ? OR o.phone LIKE ? OR o.address LIKE ? OR o.city LIKE ? OR o.state LIKE ?)";
    $countSql .= " AND (o.name LIKE ? OR o.email LIKE ? OR o.phone LIKE ? OR o.address LIKE ? OR o.city LIKE ? OR o.state LIKE ?)";
    $searchParam = "%$searchTerm%";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $searchParam;
        $countParams[] = $searchParam;
    }
}

// Get total count for pagination
$countStmt = $conn->prepare($countSql);
$countStmt->execute($countParams);
$totalOwners = $countStmt->fetchColumn();

// Add pagination to query
$sql .= " ORDER BY o.created_at DESC LIMIT " . (int)$offset . ", " . (int)$ownersPerPage;

// Fetch owners
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching gym owners: " . $e->getMessage();
}

// Get unique cities and states for filters
try {
    $citiesSql = "SELECT DISTINCT city FROM gym_owners WHERE city != '' ORDER BY city";
    $citiesStmt = $conn->prepare($citiesSql);
    $citiesStmt->execute();
    $cities = $citiesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $statesSql = "SELECT DISTINCT state FROM gym_owners WHERE state != '' ORDER BY state";
    $statesStmt = $conn->prepare($statesSql);
    $statesStmt->execute();
    $states = $statesStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $error = "Error fetching filter data: " . $e->getMessage();
}

// Calculate pagination
$totalPages = ceil($totalOwners / $ownersPerPage);
$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);

// Generate pagination URL
function getPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

// Format date
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Gym Owners - FlexFit Admin</title>
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
        .owner-card {
            transition: all 0.3s ease;
        }
        .owner-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h1 class="text-2xl font-bold">Manage Gym Owners</h1>
            <a href="add_owner.php" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                <i class="fas fa-plus mr-2"></i> Add New Owner
            </a>
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
        
               <!-- Filter Section -->
               <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-6">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center">
                <h2 class="text-lg font-semibold">Filter Owners</h2>
                <a href="gym_owners.php" class="text-gray-400 hover:text-white text-sm">
                    <i class="fas fa-times mr-1"></i> Clear Filters
                </a>
            </div>
            
            <form action="gym_owners.php" method="GET" class="p-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <label for="city" class="block text-sm font-medium text-gray-400 mb-1">City</label>
                        <select id="city" name="city" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All Cities</option>
                            <?php foreach ($cities as $city): ?>
                                <option value="<?php echo htmlspecialchars($city); ?>" <?php echo $filterCity === $city ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($city); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="state" class="block text-sm font-medium text-gray-400 mb-1">State</label>
                        <select id="state" name="state" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All States</option>
                            <?php foreach ($states as $state): ?>
                                <option value="<?php echo htmlspecialchars($state); ?>" <?php echo $filterState === $state ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($state); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-400 mb-1">Status</label>
                        <select id="status" name="status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $filterStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filterStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="suspended" <?php echo $filterStatus === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-400 mb-1">Search</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search by name, email, phone..." class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                    </div>
                </div>
                
                <div class="mt-4 flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Owners Grid -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-semibold">Gym Owner Listings</h2>
                        <p class="text-gray-400 mt-1">
                            Showing <?php echo min($totalOwners, $offset + 1); ?> - <?php echo min($totalOwners, $offset + count($owners)); ?> of <?php echo $totalOwners; ?> gym owners
                        </p>
                    </div>
                </div>
            </div>
            
            <?php if (empty($owners)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-user-tie text-4xl mb-3"></i>
                    <p>No gym owners found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($owners as $owner): ?>
                        <?php
                            $statusClass = '';
                            $statusBadge = '';
                            
                            switch ($owner['status']) {
                                case 'active':
                                    $statusClass = 'bg-green-900 text-green-300';
                                    $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-green-900 text-green-300">Active</span>';
                                    break;
                                case 'inactive':
                                    $statusClass = 'bg-yellow-900 text-yellow-300';
                                    $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-yellow-900 text-yellow-300">Inactive</span>';
                                    break;
                                case 'suspended':
                                    $statusClass = 'bg-red-900 text-red-300';
                                    $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-red-900 text-red-300">Suspended</span>';
                                    break;
                                default:
                                    $statusClass = 'bg-gray-700 text-gray-300';
                                    $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-gray-700 text-gray-300">Unknown</span>';
                            }
                        ?>
                        <div class="owner-card bg-gray-700 rounded-lg overflow-hidden">
                            <div class="p-4 border-b border-gray-600 flex justify-between items-center">
                                <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($owner['name']); ?></h3>
                                <?php echo $statusBadge; ?>
                            </div>
                            
                            <div class="p-4">
                                <div class="mb-3">
                                    <div class="text-sm text-gray-400">Contact</div>
                                    <div class="font-medium"><?php echo htmlspecialchars($owner['email']); ?></div>
                                    <div class="text-sm"><?php echo htmlspecialchars($owner['phone']); ?></div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="text-sm text-gray-400">Location</div>
                                    <div class="font-medium"><?php echo htmlspecialchars($owner['address']); ?></div>
                                    <div class="text-sm"><?php echo htmlspecialchars($owner['city'] . ', ' . $owner['state'] . ' ' . $owner['zip_code']); ?></div>
                                </div>
                                
                                <div class="grid grid-cols-3 gap-3 mb-3">
                                    <div>
                                        <div class="text-sm text-gray-400">Gyms</div>
                                        <div class="font-medium"><?php echo $owner['gym_count']; ?></div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-gray-400">Active Gyms</div>
                                        <div class="font-medium"><?php echo $owner['active_gyms']; ?></div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-gray-400">Gym Limit</div>
                                        <div class="font-medium"><?php echo $owner['gym_limit']; ?></div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="text-sm text-gray-400">Registered On</div>
                                    <div class="text-sm"><?php echo formatDate($owner['created_at']); ?></div>
                                </div>
                                
                                <div class="flex flex-wrap gap-2 mt-4">
                                    <a href="view_owner.php?id=<?php echo $owner['id']; ?>" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-eye mr-1"></i> View
                                    </a>
                                    
                                    <a href="edit_owner.php?id=<?php echo $owner['id']; ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-edit mr-1"></i> Edit
                                    </a>
                                    
                                    <button class="status-btn bg-purple-600 hover:bg-purple-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200"
                                            data-id="<?php echo $owner['id']; ?>"
                                            data-status="<?php echo $owner['status']; ?>"
                                            data-name="<?php echo htmlspecialchars($owner['name']); ?>">
                                        <i class="fas fa-exchange-alt mr-1"></i> Status
                                    </button>
                                    
                                    <button class="limit-btn bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200"
                                            data-id="<?php echo $owner['id']; ?>"
                                            data-limit="<?php echo $owner['gym_limit']; ?>"
                                            data-name="<?php echo htmlspecialchars($owner['name']); ?>">
                                        <i class="fas fa-sliders-h mr-1"></i> Gym Limit
                                    </button>
                                    
                                    <?php if ($owner['active_gyms'] == 0): ?>
                                        <button class="delete-btn bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200"
                                                data-id="<?php echo $owner['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($owner['name']); ?>">
                                            <i class="fas fa-trash-alt mr-1"></i> Delete
                                        </button>
                                    <?php else: ?>
                                        <button class="bg-gray-600 text-gray-400 text-sm px-3 py-1 rounded-lg cursor-not-allowed" title="Cannot delete owner with active gyms">
                                            <i class="fas fa-trash-alt mr-1"></i> Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="p-4 border-t border-gray-700 flex justify-between items-center">
                        <div class="text-sm text-gray-400">
                            Page <?php echo $page; ?> of <?php echo $totalPages; ?>
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="<?php echo getPaginationUrl(1); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="<?php echo getPaginationUrl($prevPage); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <?php
                            // Show a limited number of page links
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            // Always show first page
                            if ($startPage > 1) {
                                echo '<a href="' . getPaginationUrl(1) . '" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">1</a>';
                                if ($startPage > 2) {
                                    echo '<span class="text-gray-400 px-1">...</span>';
                                }
                            }
                            
                            // Show page links
                            for ($i = $startPage; $i <= $endPage; $i++) {
                                $activeClass = $i === $page ? 'bg-blue-600 hover:bg-blue-700' : 'bg-gray-700 hover:bg-gray-600';
                                echo '<a href="' . getPaginationUrl($i) . '" class="' . $activeClass . ' text-white px-3 py-1 rounded-lg transition-colors duration-200">' . $i . '</a>';
                            }
                            
                            // Always show last page
                            if ($endPage < $totalPages) {
                                if ($endPage < $totalPages - 1) {
                                    echo '<span class="text-gray-400 px-1">...</span>';
                                }
                                echo '<a href="' . getPaginationUrl($totalPages) . '" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">' . $totalPages . '</a>';
                            }
                            ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="<?php echo getPaginationUrl($nextPage); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="<?php echo getPaginationUrl($totalPages); ?>" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div id="statusModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold">Update Owner Status</p>
                    <button class="modal-close cursor-pointer z-50">
                    <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <div class="mt-4">
                    <p class="text-gray-300 mb-4">
                        You are updating the status for: <span id="status-owner-name" class="font-semibold"></span>
                    </p>
                    
                    <form method="POST">
                        <input type="hidden" id="status-owner-id" name="owner_id" value="">
                        
                        <div class="mb-4">
                            <label for="status" class="block text-sm font-medium text-gray-400 mb-1">New Status</label>
                            <select id="status-select" name="status" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>
                        
                        <div class="mt-6 flex justify-end">
                            <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                                Cancel
                            </button>
                            <button type="submit" name="update_status" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                                <i class="fas fa-save mr-2"></i> Update Status
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Gym Limit Modal -->
    <div id="limitModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold">Update Gym Limit</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <div class="mt-4">
                    <p class="text-gray-300 mb-4">
                        You are updating the gym limit for: <span id="limit-owner-name" class="font-semibold"></span>
                    </p>
                    
                    <form method="POST">
                        <input type="hidden" id="limit-owner-id" name="owner_id" value="">
                        
                        <div class="mb-4">
                            <label for="gym_limit" class="block text-sm font-medium text-gray-400 mb-1">New Gym Limit</label>
                            <input type="number" id="gym-limit-input" name="gym_limit" min="0" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <p class="text-sm text-gray-400 mt-1">Set to 0 for unlimited gyms</p>
                        </div>
                        
                        <div class="mt-6 flex justify-end">
                            <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                                Cancel
                            </button>
                            <button type="submit" name="update_gym_limit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                                <i class="fas fa-save mr-2"></i> Update Limit
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Owner Modal -->
    <div id="deleteModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold text-red-500">Delete Gym Owner</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <div class="mt-4">
                    <div class="bg-red-900 bg-opacity-50 p-4 rounded-lg mb-4">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle text-red-500 text-xl mr-3"></i>
                            <div>
                                <h4 class="font-medium text-white">Warning: This action cannot be undone</h4>
                                <p class="text-sm text-red-300 mt-1">You are about to delete the gym owner: <span id="delete-owner-name" class="font-semibold"></span></p>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" id="delete-owner-id" name="owner_id" value="">
                        
                        <div class="mt-6 flex justify-end">
                            <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                                Cancel
                            </button>
                            <button type="submit" name="delete_owner" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg">
                                <i class="fas fa-trash-alt mr-2"></i> Delete Owner
                            </button>
                        </div>
                    </form>
                </div>
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
        
        // Status update modal
        document.querySelectorAll('.status-btn').forEach(button => {
            button.addEventListener('click', function() {
                const ownerId = this.getAttribute('data-id');
                const ownerName = this.getAttribute('data-name');
                const currentStatus = this.getAttribute('data-status');
                
                document.getElementById('status-owner-id').value = ownerId;
                document.getElementById('status-owner-name').textContent = ownerName;
                document.getElementById('status-select').value = currentStatus;
                
                toggleModal('statusModal');
            });
        });
        
        // Gym limit modal
        document.querySelectorAll('.limit-btn').forEach(button => {
            button.addEventListener('click', function() {
                const ownerId = this.getAttribute('data-id');
                const ownerName = this.getAttribute('data-name');
                const currentLimit = this.getAttribute('data-limit');
                
                document.getElementById('limit-owner-id').value = ownerId;
                document.getElementById('limit-owner-name').textContent = ownerName;
                document.getElementById('gym-limit-input').value = currentLimit;
                
                toggleModal('limitModal');
            });
        });
        
        // Delete owner modal
        document.querySelectorAll('.delete-btn').forEach(button => {
            button.addEventListener('click', function() {
                const ownerId = this.getAttribute('data-id');
                const ownerName = this.getAttribute('data-name');
                
                document.getElementById('delete-owner-id').value = ownerId;
                document.getElementById('delete-owner-name').textContent = ownerName;
                
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
        
        // Highlight cards on hover
        document.querySelectorAll('.owner-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.classList.add('transform', 'scale-[1.02]', 'shadow-lg');
            });
            
            card.addEventListener('mouseleave', function() {
                this.classList.remove('transform', 'scale-[1.02]', 'shadow-lg');
            });
        });
    </script>
</body>
</html>
