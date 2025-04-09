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
$logs = [];
$totalLogs = 0;
$logTypes = [];
$userTypes = [];

// Pagination settings
$logsPerPage = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$offset = ($page - 1) * $logsPerPage;

// Filter settings
$filterType = isset($_GET['type']) ? $_GET['type'] : '';
$filterUserType = isset($_GET['user_type']) ? $_GET['user_type'] : '';
$filterUser = isset($_GET['user']) ? $_GET['user'] : '';
$filterDateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$filterDateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$filterIp = isset($_GET['ip']) ? $_GET['ip'] : '';
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';

// Check if system_logs table exists, if not create it
try {
    $tableCheckQuery = "SHOW TABLES LIKE 'system_logs'";
    $tableCheckStmt = $conn->prepare($tableCheckQuery);
    $tableCheckStmt->execute();
    
    if ($tableCheckStmt->rowCount() == 0) {
        // Table doesn't exist, create it
        $createTableQuery = "
            CREATE TABLE `system_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `log_type` enum('error','warning','info','debug') NOT NULL DEFAULT 'info',
              `message` text NOT NULL,
              `context` text DEFAULT NULL,
              `user_id` int(11) DEFAULT NULL,
              `user_type` varchar(20) DEFAULT NULL,
              `ip_address` varchar(45) DEFAULT NULL,
              `user_agent` text DEFAULT NULL,
              `request_url` varchar(255) DEFAULT NULL,
              `request_method` varchar(10) DEFAULT NULL,
              `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
              PRIMARY KEY (`id`),
              KEY `log_type` (`log_type`),
              KEY `user_id` (`user_id`),
              KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        
        $conn->exec($createTableQuery);
        $message = "System logs table created successfully.";
    }
} catch (PDOException $e) {
    $error = "Error checking/creating system logs table: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Clear logs
    if (isset($_POST['clear_logs']) && isset($_POST['confirm_clear'])) {
        $clearType = $_POST['clear_type'];
        
        try {
            if ($clearType === 'all') {
                $sql = "TRUNCATE TABLE system_logs";
                $stmt = $conn->prepare($sql);
                $stmt->execute();
                $message = "All system logs have been cleared.";
            } else {
                $sql = "DELETE FROM system_logs WHERE log_type = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$clearType]);
                $message = "All " . ucfirst($clearType) . " logs have been cleared.";
            }
            
            // Log the activity
            $activitySql = "
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (
                    ?, 'admin', ?, ?, ?, ?
                )
            ";
            $details = "Admin cleared " . ($clearType === 'all' ? 'all system logs' : "all $clearType logs");
            $activityStmt = $conn->prepare($activitySql);
            $activityStmt->execute([
                $_SESSION['admin_id'],
                'clear_system_logs',
                $details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
    
    // Export logs
    if (isset($_POST['export_logs'])) {
        $exportType = $_POST['export_type'];
        $exportFormat = $_POST['export_format'];
        
        try {
            // Build query based on export type and current filters
            $sql = "SELECT * FROM system_logs WHERE 1=1";
            $params = [];
            
            if ($exportType !== 'all') {
                $sql .= " AND log_type = ?";
                $params[] = $exportType;
            }
            
            // Apply filters
            if (!empty($filterUserType)) {
                $sql .= " AND user_type = ?";
                $params[] = $filterUserType;
            }
            
            if (!empty($filterUser)) {
                $sql .= " AND user_id = ?";
                $params[] = $filterUser;
            }
            
            if (!empty($filterDateFrom)) {
                $sql .= " AND DATE(created_at) >= ?";
                $params[] = $filterDateFrom;
            }
            
            if (!empty($filterDateTo)) {
                $sql .= " AND DATE(created_at) <= ?";
                $params[] = $filterDateTo;
            }
            
            if (!empty($filterIp)) {
                $sql .= " AND ip_address LIKE ?";
                $params[] = "%$filterIp%";
            }
            
            if (!empty($searchTerm)) {
                $sql .= " AND (message LIKE ? OR context LIKE ? OR request_url LIKE ?)";
                $params[] = "%$searchTerm%";
                $params[] = "%$searchTerm%";
                $params[] = "%$searchTerm%";
            }
            
           // Add pagination to query
           $sql .= " ORDER BY created_at DESC LIMIT " . (int)$offset . ", " . (int)$logsPerPage;

            
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $exportLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Generate export file
            if ($exportFormat === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="system_logs_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                
                // Add CSV header
                fputcsv($output, array_keys($exportLogs[0]));
                
                // Add data rows
                foreach ($exportLogs as $row) {
                    fputcsv($output, $row);
                }
                
                fclose($output);
                exit;
            } elseif ($exportFormat === 'json') {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="system_logs_' . date('Y-m-d') . '.json"');
                
                echo json_encode($exportLogs, JSON_PRETTY_PRINT);
                exit;
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Build query with filters
$sql = "SELECT SQL_CALC_FOUND_ROWS sl.*, 
        CASE 
            WHEN sl.user_type = 'admin' THEN (SELECT username FROM users WHERE id = sl.user_id)
            WHEN sl.user_type = 'member' THEN (SELECT username FROM users WHERE id = sl.user_id)
            WHEN sl.user_type = 'gym_owner' THEN (SELECT username FROM users WHERE id = sl.user_id)
            ELSE 'System'
        END as username
        FROM system_logs sl
        WHERE 1=1";
$countSql = "SELECT COUNT(*) FROM system_logs WHERE 1=1";
$params = [];
$countParams = [];

if (!empty($filterType)) {
    $sql .= " AND log_type = ?";
    $countSql .= " AND log_type = ?";
    $params[] = $filterType;
    $countParams[] = $filterType;
}

if (!empty($filterUserType)) {
    $sql .= " AND user_type = ?";
    $countSql .= " AND user_type = ?";
    $params[] = $filterUserType;
    $countParams[] = $filterUserType;
}

if (!empty($filterUser)) {
    $sql .= " AND user_id = ?";
    $countSql .= " AND user_id = ?";
    $params[] = $filterUser;
    $countParams[] = $filterUser;
}

if (!empty($filterDateFrom)) {
    $sql .= " AND DATE(created_at) >= ?";
    $countSql .= " AND DATE(created_at) >= ?";
    $params[] = $filterDateFrom;
    $countParams[] = $filterDateFrom;
}

if (!empty($filterDateTo)) {
    $sql .= " AND DATE(created_at) <= ?";
    $countSql .= " AND DATE(created_at) <= ?";
    $params[] = $filterDateTo;
    $countParams[] = $filterDateTo;
}

if (!empty($filterIp)) {
    $sql .= " AND ip_address LIKE ?";
    $countSql .= " AND ip_address LIKE ?";
    $params[] = "%$filterIp%";
    $countParams[] = "%$filterIp%";
}

if (!empty($searchTerm)) {
    $sql .= " AND (message LIKE ? OR context LIKE ? OR request_url LIKE ?)";
    $countSql .= " AND (message LIKE ? OR context LIKE ? OR request_url LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
    $countParams[] = "%$searchTerm%";
    $countParams[] = "%$searchTerm%";
    $countParams[] = "%$searchTerm%";
}

// Get total count for pagination
$countStmt = $conn->prepare($countSql);
$countStmt->execute($countParams);
$totalLogs = $countStmt->fetchColumn();

// Add pagination to query
$sql .= " ORDER BY created_at DESC LIMIT ?, ?";
$params[] = $offset;
$params[] = $logsPerPage;

// Fetch logs
try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching logs: " . $e->getMessage();
}

// Get available log types
try {
    $typesSql = "SELECT DISTINCT log_type FROM system_logs ORDER BY log_type";
    $typesStmt = $conn->prepare($typesSql);
    $typesStmt->execute();
    $logTypes = $typesStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $error = "Error fetching log types: " . $e->getMessage();
}

// Get available user types
try {
    $userTypesSql = "SELECT DISTINCT user_type FROM system_logs WHERE user_type IS NOT NULL ORDER BY user_type";
    $userTypesStmt = $conn->prepare($userTypesSql);
    $userTypesStmt->execute();
    $userTypes = $userTypesStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $error = "Error fetching user types: " . $e->getMessage();
}

// Calculate pagination
$totalPages = ceil($totalLogs / $logsPerPage);
$prevPage = max(1, $page - 1);
$nextPage = min($totalPages, $page + 1);

// Generate pagination URL
function getPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

// Format log context for display
function formatContext($context) {
    if (empty($context)) return '';
    
    try {
        $data = json_decode($context, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            $output = '<ul class="text-xs space-y-1">';
            foreach ($data as $key => $value) {
                $formattedValue = is_array($value) ? json_encode($value) : $value;
                $output .= '<li><span class="text-gray-400">' . htmlspecialchars($key) . ':</span> ' . htmlspecialchars($formattedValue) . '</li>';
            }
            $output .= '</ul>';
            return $output;
        }
    } catch (Exception $e) {
        // If JSON parsing fails, return as plain text
    }
    
    return '<pre class="text-xs whitespace-pre-wrap">' . htmlspecialchars($context) . '</pre>';
}

// Add a system log entry (for demonstration)
function addSystemLog($conn, $type, $message, $context = null, $userId = null, $userType = null) {
    try {
        $sql = "INSERT INTO system_logs (
                    log_type, message, context, user_id, user_type, 
                    ip_address, user_agent, request_url, request_method
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, ?, ?
                )";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $type,
            $message,
            $context ? json_encode($context) : null,
            $userId,
            $userType,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['REQUEST_METHOD']
        ]);
        
        return true;
    } catch (Exception $e) {
        error_log("Error adding system log: " . $e->getMessage());
        return false;
    }
}

// Add a test log entry if requested
if (isset($_GET['add_test_log'])) {
    $logTypes = ['error', 'warning', 'info', 'debug'];
    $randomType = $logTypes[array_rand($logTypes)];
    
    $testContext = [
        'test_key' => 'test_value',
        'timestamp' => time(),
        'random' => rand(1000, 9999)
    ];
    
    addSystemLog(
        $conn,
        $randomType,
        "This is a test {$randomType} log message",
        $testContext,
        $_SESSION['admin_id'],
        'admin'
    );
    
    header('Location: system_logs.php');
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - FlexFit Admin</title>
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
        .log-context {
            max-height: 200px;
            overflow-y: auto;
        }
        .log-context::-webkit-scrollbar {
            width: 8px;
        }
        .log-context::-webkit-scrollbar-track {
            background: #374151;
        }
        .log-context::-webkit-scrollbar-thumb {
            background-color: #4B5563;
            border-radius: 20px;
        }
        .log-row-error {
            border-left: 4px solid #EF4444;
        }
        .log-row-warning {
            border-left: 4px solid #F59E0B;
        }
        .log-row-info {
            border-left: 4px solid #3B82F6;
        }
        .log-row-debug {
            border-left: 4px solid #10B981;
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
            <h1 class="text-2xl font-bold">System Logs</h1>
            <div class="flex flex-wrap gap-2">
                <a href="?add_test_log=1" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center text-sm">
                    <i class="fas fa-plus mr-2"></i> Add Test Log
                </a>
                <button id="openExportModal" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center text-sm">
                    <i class="fas fa-file-export mr-2"></i> Export
                </button>
                <button id="openClearModal" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200 flex items-center text-sm">
                    <i class="fas fa-trash-alt mr-2"></i> Clear Logs
                </button>
            </div>
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
                <h2 class="text-lg font-semibold">Filter Logs</h2>
                <a href="system_logs.php" class="text-gray-400 hover:text-white text-sm">
                    <i class="fas fa-times mr-1"></i> Clear Filters
                </a>
            </div>
            
            <form action="system_logs.php" method="GET" class="p-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    <div>
                        <label for="type" class="block text-sm font-medium text-gray-400 mb-1">Log Type</label>
                        <select id="type" name="type" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All Types</option>
                            <?php foreach ($logTypes as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo $filterType === $type ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="user_type" class="block text-sm font-medium text-gray-400 mb-1">User Type</label>
                        <select id="user_type" name="user_type" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                            <option value="">All User Types</option>
                            <?php foreach ($userTypes as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo $filterUserType === $type ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="date_from" class="block text-sm font-medium text-gray-400 mb-1">Date From</label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo $filterDateFrom; ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label for="date_to" class="block text-sm font-medium text-gray-400 mb-1">Date To</label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo $filterDateTo; ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label for="ip" class="block text-sm font-medium text-gray-400 mb-1">IP Address</label>
                        <input type="text" id="ip" name="ip" value="<?php echo $filterIp; ?>" placeholder="e.g. 192.168.1.1" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label for="search" class="block text-sm font-medium text-gray-400 mb-1">Search</label>
                        <input type="text" id="search" name="search" value="<?php echo $searchTerm; ?>" placeholder="Search in message, context, or URL" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                    </div>
                </div>
                
                <div class="mt-4 flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg transition-colors duration-200">
                        <i class="fas fa-filter mr-2"></i> Apply Filters
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Logs Table -->
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <div class="flex justify-between items-center">
                    <div>
                        <h2 class="text-xl font-semibold">System Logs</h2>
                        <p class="text-gray-400 mt-1">
                            Showing <?php echo min($totalLogs, $offset + 1); ?> - <?php echo min($totalLogs, $offset + count($logs)); ?> of <?php echo $totalLogs; ?> logs
                        </p>
                    </div>
                    <div class="flex items-center space-x-2 text-sm">
                        <span class="px-2 py-1 bg-red-900 text-red-300 rounded-lg flex items-center">
                            <span class="w-2 h-2 bg-red-500 rounded-full mr-1"></span> Error
                        </span>
                        <span class="px-2 py-1 bg-yellow-900 text-yellow-300 rounded-lg flex items-center">
                            <span class="w-2 h-2 bg-yellow-500 rounded-full mr-1"></span> Warning
                        </span>
                        <span class="px-2 py-1 bg-blue-900 text-blue-300 rounded-lg flex items-center">
                            <span class="w-2 h-2 bg-blue-500 rounded-full mr-1"></span> Info
                        </span>
                        <span class="px-2 py-1 bg-green-900 text-green-300 rounded-lg flex items-center">
                            <span class="w-2 h-2 bg-green-500 rounded-full mr-1"></span> Debug
                        </span>
                    </div>
                </div>
            </div>
            
            <?php if (empty($logs)): ?>
                <div class="p-6 text-center text-gray-500">
                    <i class="fas fa-search text-4xl mb-3"></i>
                    <p>No logs found matching your criteria.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full bg-gray-800">
                        <thead>
                            <tr class="bg-gray-700">
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Type</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Message</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">User</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">IP Address</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Date & Time</th>
                                <th class="py-3 px-4 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700">
                            <?php foreach ($logs as $log): ?>
                                <?php 
                                    $typeClass = '';
                                    $typeColor = '';
                                    
                                    switch ($log['log_type']) {
                                        case 'error':
                                            $typeClass = 'bg-red-900 text-red-300';
                                            $typeColor = 'text-red-500';
                                            $rowClass = 'log-row-error';
                                            break;
                                        case 'warning':
                                            $typeClass = 'bg-yellow-900 text-yellow-300';
                                            $typeColor = 'text-yellow-500';
                                            $rowClass = 'log-row-warning';
                                            break;
                                        case 'info':
                                            $typeClass = 'bg-blue-900 text-blue-300';
                                            $typeColor = 'text-blue-500';
                                            $rowClass = 'log-row-info';
                                            break;
                                        case 'debug':
                                            $typeClass = 'bg-green-900 text-green-300';
                                            $typeColor = 'text-green-500';
                                            $rowClass = 'log-row-debug';
                                            break;
                                        default:
                                            $typeClass = 'bg-gray-700 text-gray-300';
                                            $typeColor = 'text-gray-500';
                                            $rowClass = '';
                                    }
                                ?>
                                <tr class="hover:bg-gray-700 transition-colors duration-200 <?php echo $rowClass; ?>">
                                    <td class="py-3 px-4">
                                        <span class="px-2 py-1 text-xs rounded-full <?php echo $typeClass; ?>">
                                            <?php echo ucfirst($log['log_type']); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="font-medium <?php echo $typeColor; ?>"><?php echo htmlspecialchars($log['message']); ?></div>
                                        <?php if (!empty($log['request_url'])): ?>
                                            <div class="text-xs text-gray-400 mt-1">
                                                <span class="font-medium"><?php echo $log['request_method']; ?></span> <?php echo htmlspecialchars($log['request_url']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php if ($log['user_id']): ?>
                                            <div class="text-sm"><?php echo htmlspecialchars($log['username']); ?></div>
                                            <div class="text-xs text-gray-400"><?php echo ucfirst($log['user_type']); ?></div>
                                        <?php else: ?>
                                            <span class="text-gray-400">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php if ($log['ip_address']): ?>
                                            <div class="text-sm font-mono"><?php echo htmlspecialchars($log['ip_address']); ?></div>
                                        <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="text-sm"><?php echo date('M d, Y', strtotime($log['created_at'])); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="flex space-x-2">
                                            <button class="text-blue-400 hover:text-blue-300 transition-colors duration-200 view-log-btn"
                                                    data-id="<?php echo $log['id']; ?>"
                                                    data-type="<?php echo $log['log_type']; ?>"
                                                    data-message="<?php echo htmlspecialchars($log['message']); ?>"
                                                    data-context="<?php echo htmlspecialchars($log['context'] ?? ''); ?>"
                                                    data-user-id="<?php echo $log['user_id']; ?>"
                                                    data-user-type="<?php echo htmlspecialchars($log['user_type'] ?? ''); ?>"
                                                    data-username="<?php echo htmlspecialchars($log['username'] ?? ''); ?>"
                                                    data-ip="<?php echo htmlspecialchars($log['ip_address'] ?? ''); ?>"
                                                    data-user-agent="<?php echo htmlspecialchars($log['user_agent'] ?? ''); ?>"
                                                    data-request-url="<?php echo htmlspecialchars($log['request_url'] ?? ''); ?>"
                                                    data-request-method="<?php echo htmlspecialchars($log['request_method'] ?? ''); ?>"
                                                    data-created-at="<?php echo $log['created_at']; ?>">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
    
    <!-- View Log Modal -->
    <div id="viewLogModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-2xl mx-auto rounded-xl shadow-lg z-50 overflow-y-auto max-h-[90vh]">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold">Log Details</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <div class="mt-4 space-y-4">
                    <div>
                        <h3 id="view_log_message" class="text-xl font-semibold text-white"></h3>
                        <div id="view_log_type" class="mt-1"></div>
                    </div>
                    
                    <div id="view_log_context_container" class="bg-gray-700 rounded-lg p-4">
                        <h4 class="text-sm font-medium text-gray-400 mb-2">Context Data</h4>
                        <div id="view_log_context" class="log-context"></div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <h4 class="text-sm font-medium text-gray-400 mb-1">User Information</h4>
                            <div id="view_log_user" class="text-white"></div>
                        </div>
                        
                        <div>
                            <h4 class="text-sm font-medium text-gray-400 mb-1">IP Address</h4>
                            <div id="view_log_ip" class="text-white font-mono"></div>
                        </div>
                        
                        <div class="md:col-span-2">
                            <h4 class="text-sm font-medium text-gray-400 mb-1">User Agent</h4>
                            <div id="view_log_user_agent" class="text-white text-sm break-words"></div>
                        </div>
                        
                        <div>
                            <h4 class="text-sm font-medium text-gray-400 mb-1">Request</h4>
                            <div id="view_log_request" class="text-white"></div>
                        </div>
                        
                        <div>
                            <h4 class="text-sm font-medium text-gray-400 mb-1">Date & Time</h4>
                            <div id="view_log_time" class="text-white"></div>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg">
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Export Logs Modal -->
    <div id="exportModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold text-green-500">Export Logs</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <form method="POST" class="mt-4">
                    <div class="space-y-4">
                        <div>
                            <label for="export_type" class="block text-sm font-medium text-gray-400 mb-1">Log Type to Export</label>
                            <select id="export_type" name="export_type" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-green-500">
                                <option value="all">All Logs</option>
                                <?php foreach ($logTypes as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?> Logs Only</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-400 mt-1">Current filters will be applied to the export.</p>
                        </div>
                        
                        <div>
                            <label for="export_format" class="block text-sm font-medium text-gray-400 mb-1">Export Format</label>
                            <div class="flex space-x-4">
                                <label class="flex items-center">
                                    <input type="radio" name="export_format" value="csv" checked class="text-green-500 focus:ring-green-500 h-4 w-4">
                                    <span class="ml-2">CSV</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="radio" name="export_format" value="json" class="text-green-500 focus:ring-green-500 h-4 w-4">
                                    <span class="ml-2">JSON</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                            Cancel
                        </button>
                        <button type="submit" name="export_logs" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-download mr-2"></i> Export
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Clear Logs Modal -->
    <div id="clearModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-black opacity-75"></div>
        
        <div class="modal-container bg-gray-800 w-11/12 md:max-w-md mx-auto rounded-xl shadow-lg z-50">
            <div class="modal-content py-4 text-left px-6">
                <div class="flex justify-between items-center pb-3 border-b border-gray-700">
                    <p class="text-xl font-bold text-red-500">Clear Logs</p>
                    <button class="modal-close cursor-pointer z-50">
                        <i class="fas fa-times text-gray-400 hover:text-white"></i>
                    </button>
                </div>
                
                <form method="POST" class="mt-4">
                    <div class="space-y-4">
                        <div>
                            <label for="clear_type" class="block text-sm font-medium text-gray-400 mb-1">Logs to Clear</label>
                            <select id="clear_type" name="clear_type" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-red-500">
                                <option value="all">All Logs</option>
                                <?php foreach ($logTypes as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo ucfirst($type); ?> Logs Only</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="bg-red-900 bg-opacity-50 p-4 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-exclamation-triangle text-red-500 text-xl mr-3"></i>
                                <div>
                                    <h4 class="font-medium text-white">Warning: This action cannot be undone</h4>
                                    <p class="text-sm text-red-300 mt-1">All selected logs will be permanently deleted from the system.</p>
                                </div>
                            </div>
                            
                            <div class="mt-3">
                                <label class="flex items-center">
                                    <input type="checkbox" name="confirm_clear" required class="text-red-500 focus:ring-red-500 h-4 w-4">
                                    <span class="ml-2 text-white">I understand and confirm this action</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="button" class="modal-close bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                            Cancel
                        </button>
                        <button type="submit" name="clear_logs" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-trash-alt mr-2"></i> Clear Logs
                        </button>
                    </div>
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
        
        // Open export modal
        document.getElementById('openExportModal').addEventListener('click', function() {
            toggleModal('exportModal');
        });
        
        // Open clear logs modal
        document.getElementById('openClearModal').addEventListener('click', function() {
            toggleModal('clearModal');
        });
        
        // View log details
        document.querySelectorAll('.view-log-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const type = this.getAttribute('data-type');
                const message = this.getAttribute('data-message');
                const context = this.getAttribute('data-context');
                const userId = this.getAttribute('data-user-id');
                const userType = this.getAttribute('data-user-type');
                const username = this.getAttribute('data-username');
                const ip = this.getAttribute('data-ip');
                const userAgent = this.getAttribute('data-user-agent');
                const requestUrl = this.getAttribute('data-request-url');
                const requestMethod = this.getAttribute('data-request-method');
                const createdAt = this.getAttribute('data-created-at');
                
                // Set values in the view modal
                document.getElementById('view_log_message').textContent = message;
                
                // Set log type with appropriate styling
                let typeHTML = '';
                switch (type) {
                    case 'error':
                        typeHTML = '<span class="px-2 py-1 text-xs rounded-full bg-red-900 text-red-300">Error</span>';
                        break;
                    case 'warning':
                        typeHTML = '<span class="px-2 py-1 text-xs rounded-full bg-yellow-900 text-yellow-300">Warning</span>';
                        break;
                    case 'info':
                        typeHTML = '<span class="px-2 py-1 text-xs rounded-full bg-blue-900 text-blue-300">Info</span>';
                        break;
                    case 'debug':
                        typeHTML = '<span class="px-2 py-1 text-xs rounded-full bg-green-900 text-green-300">Debug</span>';
                        break;
                }
                document.getElementById('view_log_type').innerHTML = typeHTML;
                
                // Set context data
                const contextContainer = document.getElementById('view_log_context_container');
                const contextElement = document.getElementById('view_log_context');
                
                if (context && context !== 'null') {
                    try {
                        const contextData = JSON.parse(context);
                        let contextHTML = '<ul class="text-xs space-y-1">';
                        
                        for (const [key, value] of Object.entries(contextData)) {
                            const formattedValue = typeof value === 'object' ? JSON.stringify(value, null, 2) : value;
                            contextHTML += `<li><span class="text-gray-400">${key}:</span> ${formattedValue}</li>`;
                        }
                        
                        contextHTML += '</ul>';
                        contextElement.innerHTML = contextHTML;
                        contextContainer.classList.remove('hidden');
                    } catch (e) {
                        // If JSON parsing fails, display as plain text
                        contextElement.innerHTML = `<pre class="text-xs whitespace-pre-wrap">${context}</pre>`;
                        contextContainer.classList.remove('hidden');
                    }
                } else {
                    contextContainer.classList.add('hidden');
                }
                
                // Set user information
                if (userId && username) {
                    document.getElementById('view_log_user').innerHTML = `
                        <div class="text-sm">${username}</div>
                        <div class="text-xs text-gray-400">ID: ${userId} (${userType})</div>
                    `;
                } else {
                    document.getElementById('view_log_user').innerHTML = '<span class="text-gray-400">System</span>';
                }
                
                // Set IP address
                document.getElementById('view_log_ip').textContent = ip || 'Not available';
                
                // Set user agent
                document.getElementById('view_log_user_agent').textContent = userAgent || 'Not available';
                
                // Set request information
                if (requestUrl) {
                    document.getElementById('view_log_request').innerHTML = `
                        <div class="text-sm">${requestMethod} ${requestUrl}</div>
                    `;
                } else {
                    document.getElementById('view_log_request').innerHTML = '<span class="text-gray-400">Not available</span>';
                }
                
                // Set date and time
                const logDate = new Date(createdAt);
                document.getElementById('view_log_time').innerHTML = `
                    <div class="text-sm">${logDate.toLocaleDateString()}</div>
                    <div class="text-xs text-gray-400">${logDate.toLocaleTimeString()}</div>
                `;
                
                toggleModal('viewLogModal');
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
        
        // Date range validation
        const dateFrom = document.getElementById('date_from');
        const dateTo = document.getElementById('date_to');
        
        dateFrom.addEventListener('change', function() {
            if (dateTo.value && this.value > dateTo.value) {
                dateTo.value = this.value;
            }
        });
        
        dateTo.addEventListener('change', function() {
            if (dateFrom.value && this.value < dateFrom.value) {
                dateFrom.value = this.value;
            }
        });
        
        // Highlight table rows on hover
        document.querySelectorAll('tbody tr').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.classList.add('bg-gray-700');
            });
            
            row.addEventListener('mouseleave', function() {
                this.classList.remove('bg-gray-700');
            });
        });
    </script>
</body>
</html>



