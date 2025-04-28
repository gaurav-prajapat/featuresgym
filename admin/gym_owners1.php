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

// Handle status change
if (isset($_GET['action']) && isset($_GET['id'])) {
    $owner_id = (int)$_GET['id'];
    $action = $_GET['action'];
    
    if ($owner_id > 0 && in_array($action, ['activate', 'deactivate', 'suspend'])) {
        try {
            $status = '';
            switch ($action) {
                case 'activate':
                    $status = 'active';
                    break;
                case 'deactivate':
                    $status = 'inactive';
                    break;
                case 'suspend':
                    $status = 'suspended';
                    break;
            }
            
            $stmt = $conn->prepare("UPDATE gym_owners SET status = ? WHERE id = ?");
            $stmt->execute([$status, $owner_id]);
            
            // Log the activity
            $log_query = "INSERT INTO activity_logs (user_id, user_type, action, details, ip_address, user_agent) 
                         VALUES (:user_id, 'admin', 'update_owner_status', :details, :ip, :user_agent)";
            $log_stmt = $conn->prepare($log_query);
            $log_stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':details' => "Updated owner ID: $owner_id status to $status",
                ':ip' => $_SERVER['REMOTE_ADDR'],
                ':user_agent' => $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $message = "Owner status has been updated to " . ucfirst($status);
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
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
    $sql .= " AND (o.name LIKE ? OR o.email LIKE ? OR o.phone LIKE ?)";
    $countSql .= " AND (o.name LIKE ? OR o.email LIKE ? OR o.phone LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
    $countParams[] = $searchParam;
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
    $error = "Error fetching owners: " . $e->getMessage();
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
    <title>Gym Owners - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
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
            <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
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
                            Showing <?php echo min($totalOwners, $offset + 1); ?> - <?php echo min($totalOwners, $offset + count($owners)); ?> of <?php echo $totalOwners; ?> owners
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
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10">
                                        <?php if (!empty($owner['profile_picture'])): ?>
                                            <img class="h-10 w-10 rounded-full object-cover" src="<?php echo '../' . htmlspecialchars($owner['profile_picture']); ?>" alt="Profile image">
                                        <?php else: ?>
                                            <div class="h-10 w-10 rounded-full bg-gray-600 flex items-center justify-center">
                                                <i class="fas fa-user-tie text-gray-300"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="ml-4">
                                        <h3 class="font-semibold"><?php echo htmlspecialchars($owner['name']); ?></h3>
                                    </div>
                                </div>
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
                                    <div class="text-sm"><?php echo htmlspecialchars($owner['city'] . ', ' . $owner['state']); ?></div>
                                </div>
                                
                                <div class="grid grid-cols-2 gap-3 mb-3">
                                    <div>
                                        <div class="text-sm text-gray-400">Gyms</div>
                                        <div class="font-medium"><?php echo $owner['gym_count']; ?> (<?php echo $owner['active_gyms']; ?> active)</div>
                                    </div>
                                    <div>
                                        <div class="text-sm text-gray-400">Gym Limit</div>
                                        <div class="font-medium">
                                            <?php 
                                                if ($owner['gym_limit'] == 0) {
                                                    echo 'Unlimited';
                                                } else {
                                                    echo $owner['gym_limit'];
                                                }
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="text-sm text-gray-400">Joined</div>
                                    <div class="text-sm"><?php echo formatDate($owner['created_at']); ?></div>
                                </div>
                                
                                <div class="flex flex-wrap gap-2 mt-4">
                                    <a href="view_owner.php?id=<?php echo $owner['id']; ?>" class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-eye mr-1"></i> View
                                    </a>
                                    
                                    <a href="edit_owner.php?id=<?php echo $owner['id']; ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-edit mr-1"></i> Edit
                                    </a>
                                    
                                    <?php if ($owner['status'] === 'active'): ?>
                                        <a href="gym_owners.php?action=deactivate&id=<?php echo $owner['id']; ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200" onclick="return confirm('Are you sure you want to deactivate this owner?');">
                                            <i class="fas fa-user-slash mr-1"></i> Deactivate
                                        </a>
                                    <?php elseif ($owner['status'] === 'inactive' || $owner['status'] === 'suspended'): ?>
                                        <a href="gym_owners.php?action=activate&id=<?php echo $owner['id']; ?>" class="bg-green-600 hover:bg-green-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200" onclick="return confirm('Are you sure you want to activate this owner?');">
                                            <i class="fas fa-user-check mr-1"></i> Activate
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($owner['status'] !== 'suspended'): ?>
                                        <a href="gym_owners.php?action=suspend&id=<?php echo $owner['id']; ?>" class="bg-red-600 hover:bg-red-700 text-white text-sm px-3 py-1 rounded-lg transition-colors duration-200" onclick="return confirm('Are you sure you want to suspend this owner?');">
                                            <i class="fas fa-ban mr-1"></i> Suspend
                                        </a>
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
                                
                                <script>
                                    // Highlight table rows on hover
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
                            
                            

