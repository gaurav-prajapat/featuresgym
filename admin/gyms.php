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
$gyms = [];
$totalGyms = 0;

// Pagination settings
$gymsPerPage = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $gymsPerPage;

// Filter settings
$filterCity = isset($_GET['city']) ? $_GET['city'] : '';
$filterState = isset($_GET['state']) ? $_GET['state'] : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update gym status
    if (isset($_POST['update_status'])) {
        $gymId = isset($_POST['gym_id']) ? (int)$_POST['gym_id'] : 0;
        $newStatus = isset($_POST['status']) ? $_POST['status'] : '';
        
        if ($gymId > 0 && in_array($newStatus, ['active', 'inactive', 'pending', 'suspended'])) {
            try {
                $sql = "UPDATE gyms SET status = ? WHERE gym_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$newStatus, $gymId]);
                
                // Log the activity
                $activitySql = "
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (
                        ?, 'admin', ?, ?, ?, ?
                    )
                ";
                $details = "Admin updated gym (ID: {$gymId}) status to {$newStatus}";
                $activityStmt = $conn->prepare($activitySql);
                $activityStmt->execute([
                    $_SESSION['admin_id'],
                    'update_gym_status',
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $message = "Gym status has been updated to " . ucfirst($newStatus);
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid gym ID or status.";
        }
    }
    
    // Delete gym
    if (isset($_POST['delete_gym'])) {
        $gymId = isset($_POST['gym_id']) ? (int)$_POST['gym_id'] : 0;
        
        if ($gymId > 0) {
            try {
                // Check if gym has active memberships
                $checkSql = "SELECT COUNT(*) FROM user_memberships WHERE gym_id = ? AND status = 'active'";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$gymId]);
                $activeMemberships = $checkStmt->fetchColumn();
                
                if ($activeMemberships > 0) {
                    $error = "Cannot delete gym with active memberships. Please deactivate the gym instead.";
                } else {
                    // Get gym details for logging
                    $gymDetailsSql = "SELECT name FROM gyms WHERE gym_id = ?";
                    $gymDetailsStmt = $conn->prepare($gymDetailsSql);
                    $gymDetailsStmt->execute([$gymId]);
                    $gymName = $gymDetailsStmt->fetchColumn();
                    
                    // Delete gym
                    $sql = "DELETE FROM gyms WHERE gym_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$gymId]);
                    
                    // Log the activity
                    $activitySql = "
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (
                            ?, 'admin', ?, ?, ?, ?
                        )
                    ";
                    $details = "Admin deleted gym (ID: {$gymId}, Name: {$gymName})";
                    $activityStmt = $conn->prepare($activitySql);
                    $activityStmt->execute([
                        $_SESSION['admin_id'],
                        'delete_gym',
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    $message = "Gym has been permanently deleted.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid gym ID.";
        }
    }
    
    // Feature/Unfeature gym
    if (isset($_POST['toggle_featured'])) {
        $gymId = isset($_POST['gym_id']) ? (int)$_POST['gym_id'] : 0;
        $featured = isset($_POST['featured']) ? (int)$_POST['featured'] : 0;
        $newFeatured = $featured ? 0 : 1; // Toggle the value
        
        if ($gymId > 0) {
            try {
                $sql = "UPDATE gyms SET is_featured = ? WHERE gym_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$newFeatured, $gymId]);
                
                // Log the activity
                $activitySql = "
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (
                        ?, 'admin', ?, ?, ?, ?
                    )
                ";
                $action = $newFeatured ? 'feature_gym' : 'unfeature_gym';
                $details = "Admin " . ($newFeatured ? "featured" : "unfeatured") . " gym (ID: {$gymId})";
                $activityStmt = $conn->prepare($activitySql);
                $activityStmt->execute([
                    $_SESSION['admin_id'],
                    $action,
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                $message = "Gym has been " . ($newFeatured ? "featured" : "unfeatured") . " successfully.";
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid gym ID.";
        }
    }
}

// Build query with filters
$sql = "SELECT g.*, 
        (SELECT COUNT(*) FROM user_memberships um WHERE um.gym_id = g.gym_id AND um.status = 'active') as active_memberships,
        (SELECT COUNT(*) FROM reviews r WHERE r.gym_id = g.gym_id) as review_count,
        (SELECT AVG(rating) FROM reviews r WHERE r.gym_id = g.gym_id AND r.status = 'approved') as avg_rating,
        o.username as owner_name, o.email as owner_email
        FROM gyms g
        LEFT JOIN users o ON g.owner_id = o.id
        WHERE 1=1";
$countSql = "SELECT COUNT(*) FROM gyms g LEFT JOIN users o ON g.owner_id = o.id WHERE 1=1";
$params = [];
$countParams = [];

if (!empty($filterCity)) {
    $sql .= " AND g.city = ?";
    $countSql .= " AND g.city = ?";
    $params[] = $filterCity;
    $countParams[] = $filterCity;
}

if (!empty($filterState)) {
    $sql .= " AND g.state = ?";
    $countSql .= " AND g.state = ?";
    $params[] = $filterState;
    $countParams[] = $filterState;
}

if (!empty($filterStatus)) {
    $sql .= " AND g.status = ?";
    $countSql .= " AND g.status = ?";
    $params[] = $filterStatus;
    $countParams[] = $filterStatus;
}

if (!empty($searchTerm)) {
    $sql .= " AND (g.name LIKE ? OR g.address LIKE ? OR g.city LIKE ? OR g.state LIKE ? OR o.username LIKE ?)";
    $countSql .= " AND (g.name LIKE ? OR g.address LIKE ? OR g.city LIKE ? OR g.state LIKE ? OR o.username LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
}

// Get total count for pagination
$countStmt = $conn->prepare($countSql);
$countStmt->execute($countParams);
$totalGyms = $countStmt->fetchColumn();

// Add pagination to query
$sql .= " ORDER BY g.is_featured DESC, g.created_at DESC LIMIT " . (int)$offset . ", " . (int)$gymsPerPage;

// Fetch gyms
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching gyms: " . $e->getMessage();
}

// Get unique cities and states for filters
try {
    $citiesSql = "SELECT DISTINCT city FROM gyms WHERE city != '' ORDER BY city";
    $citiesStmt = $conn->prepare($citiesSql);
    $citiesStmt->execute();
    $cities = $citiesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $statesSql = "SELECT DISTINCT state FROM gyms WHERE state != '' ORDER BY state";
    $statesStmt = $conn->prepare($statesSql);
    $statesStmt->execute();
    $states = $statesStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $error = "Error fetching filter data: " . $e->getMessage();
}

// Calculate pagination
$totalPages = ceil($totalGyms / $gymsPerPage);
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
    <title>Manage Gyms - FlexFit Admin</title>
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
        .gym-card {
            transition: all 0.3s ease;
        }
        .gym-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h1 class="text-2xl font-bold">Manage Gyms</h1>
            <a href="add_gym.php" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center">
                <i class="fas fa-plus mr-2"></i> Add New Gym
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
                <h2 class="text-lg font-semibold">Filter Gyms</h2>
                <a href="gyms.php" class="text-gray-400 hover:text-white text-sm">
                    <i class="fas fa-times mr-1"></i> Clear Filters
                </a>
            </div>
            
            <form action="gyms.php" method="GET" class="p-4">
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
                            <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="suspended" <?php echo $filterStatus === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-400 mb-1">Search</label>
                        <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search by name, address, owner..." class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                    </div>
                </div>
                
                <div class="mt-4 flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Gyms Grid -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-semibold">Gym Listings</h2>
                        <p class="text-gray-400 mt-1">
                            Showing <?php echo min($totalGyms, $offset + 1); ?> - <?php echo min($totalGyms, $offset + count($gyms)); ?> of <?php echo $totalGyms; ?> gyms
                        </p>
                    </div>
                </div>
            </div>
            
            <?php if (empty($gyms)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-dumbbell text-4xl mb-3"></i>
                    <p>No gyms found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($gyms as $gym): ?>
                        <?php
                            $statusClass = '';
                            $statusBadge = '';
                            
                            switch ($gym['status']) {
                                case 'active':
                                    $statusClass = 'bg-green-900 text-green-300';
                                    $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-green-900 text-green-300">Active</span>';
                                    break;
                                case 'inactive':
                                    $statusClass = 'bg-yellow-900 text-yellow-300';
                                    $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-yellow-900 text-yellow-300">Inactive</span>';
                                    break;
                                case 'pending':
                                    $statusClass = 'bg-blue-900 text-blue-300';
                                    $statusBadge = '<span class="px-2 py-1 text-xs rounded-full bg-blue-900 text-blue-300">Pending</span>';
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
                        <div class="gym-card bg-gray-700 rounded-lg overflow-hidden">
                            <div class="h-40 bg-gray-800 relative">
                                <img src="../uploads/gym_images/<?php echo htmlspecialchars($gym['cover_photo'] ?? 'default_gym.jpg'); ?>" 
                                     alt="<?php echo htmlspecialchars($gym['name']); ?>" 
                                     class="w-full h-full object-cover">
                                
                                <?php if ($gym['is_featured']): ?>
                                    <div class="absolute top-2 right-2 bg-yellow-500 text-black text-xs px-2 py-1 rounded-full font-bold">
                                        <i class="fas fa-star mr-1"></i> Featured
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="p-4 border-b border-gray-600 flex justify-between items-center">
                                <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($gym['name']); ?></h3>
                                <?php echo $statusBadge; ?>
                            </div>
                            
                            <div class="p-4">
                                <div class="mb-3">
                                    <div class="text-sm text-gray-400">Location</div>
                                    <div class="font-medium"><?php echo htmlspecialchars($gym['address']); ?></div>
                                    <div class="text-sm"><?php echo htmlspecialchars($gym['city'] . ', ' . $gym['state'] . ' ' . $gym['zip_code']); ?></div>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-3 mb-3">
                                    <div>
                                        <div class="text-sm text-gray-400">Owner</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['owner_name'] ?? 'N/A'); ?></div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-gray-400">Contact</div>
                                        <div class="font-medium"><?php echo htmlspecialchars($gym['phone']); ?></div>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-3 gap-3 mb-3">
                                    <div>
                                        <div class="text-sm text-gray-400">Members</div>
                                        <div class="font-medium"><?php echo $gym['active_memberships']; ?></div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-gray-400">Rating</div>
                                        <div class="font-medium flex items-center">
                                            <?php 
                                            $avgRating = round($gym['avg_rating'] ?? 0, 1);
                                            echo $avgRating; 
                                            ?>
                                            <i class="fas fa-star text-yellow-500 ml-1 text-xs"></i>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-gray-400">Reviews</div>
                                        <div class="font-medium"><?php echo $gym['review_count']; ?></div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="text-sm text-gray-400">Added On</div>
                                    <div class="text-sm"><?php echo formatDate($gym['created_at']); ?></div>
                                </div>
                                
                                <div class="flex flex-wrap gap-2 mt-4">
                                    <a href="view_gym.php?id=<?php echo $gym['gym_id']; ?>" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-eye mr-1"></i> View
                                    </a>
                                    
                                    <a href="edit_gym.php?id=<?php echo $gym['gym_id']; ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-edit mr-1"></i> Edit
                                    </a>
                                    
                                    <button class="status-btn bg-purple-600 hover:bg-purple-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200"
                                            data-id="<?php echo $gym['gym_id']; ?>"
                                            data-status="<?php echo $gym['status']; ?>"
                                            data-name="<?php echo htmlspecialchars($gym['name']); ?>">
                                        <i class="fas fa-exchange-alt mr-1"></i> Status
                                    </button>
                                    
                                    <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to <?php echo $gym['is_featured'] ? 'unfeature' : 'feature'; ?> this gym?');">
                                        <input type="hidden" name="gym_id" value="<?php echo $gym['gym_id']; ?>">
                                        <input type="hidden" name="featured" value="<?php echo $gym['is_featured']; ?>">
                                        <button type="submit" name="toggle_featured" class="<?php echo $gym['is_featured'] ? 'bg-gray-600 hover:bg-gray-700' : 'bg-yellow-600 hover:bg-yellow-700'; ?> text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                            <i class="fas fa-star mr-1"></i> <?php echo $gym['is_featured'] ? 'Unfeature' : 'Feature'; ?>
                                        </button>
                                    </form>
                                    
                                    <?php if ($gym['active_memberships'] == 0): ?>
                                        <button class="delete-btn bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200"
                                                data-id="<?php echo $gym['gym_id']; ?>"
                                                data-name="<?php echo htmlspecialchars($gym['name']); ?>">
                                            <i class="fas fa-trash-alt mr-1"></i> Delete
                                        </button>
                                    <?php else: ?>
                                        <button class="bg-gray-600 text-gray-400 text-sm px-3 py-1 rounded-lg cursor-not-allowed" title="Cannot delete gym with active memberships">
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
                    <p class="text-xl font-bold">Update Gym Status</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <div class="mt-4">
                    <p class="text-gray-300 mb-4">
                        You are updating the status for: <span id="status-gym-name" class="font-semibold"></span>
                    </p>
                    
                    <form method="POST">
                        <input type="hidden" id="status-gym-id" name="gym_id" value="">
                        
                        <div class="mb-4">
                            <label for="status" class="block text-sm font-medium text-gray-400 mb-1">New Status</label>
                            <select id="status-select" name="status" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="pending">Pending</option>
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
    
    <!-- Delete Gym Modal -->
    <div id="deleteModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold text-red-500">Delete Gym</p>
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
                                <p class="text-sm text-red-300 mt-1">You are about to delete the gym: <span id="delete-gym-name" class="font-semibold"></span></p>
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" id="delete-gym-id" name="gym_id" value="">
                        
                        <div class="mt-6 flex justify-end">
                            <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                                Cancel
                            </button>
                            <button type="submit" name="delete_gym" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg">
                                <i class="fas fa-trash-alt mr-2"></i> Delete Gym
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
                const gymId = this.getAttribute('data-id');
                const gymName = this.getAttribute('data-name');
                const currentStatus = this.getAttribute('data-status');
                
                document.getElementById('status-gym-id').value = gymId;
                document.getElementById('status-gym-name').textContent = gymName;
                document.getElementById('status-select').value = currentStatus;
                
                toggleModal('statusModal');
            });
        });
        
        // Delete gym modal
        document.querySelectorAll('.delete-btn').forEach(button => {
            button.addEventListener('click', function() {
                const gymId = this.getAttribute('data-id');
                const gymName = this.getAttribute('data-name');
                
                document.getElementById('delete-gym-id').value = gymId;
                document.getElementById('delete-gym-name').textContent = gymName;
                
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
        
        // Highlight table rows on hover
        document.querySelectorAll('.gym-card').forEach(card => {
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

                                    

