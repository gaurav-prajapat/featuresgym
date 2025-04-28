<?php
session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$success_message = '';
$error_message = '';
$selected_table = isset($_GET['table']) ? $_GET['table'] : '';
$action = isset($_GET['action']) ? $_GET['action'] : 'view';
$record_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get all tables from the database
try {
    $tables_query = $conn->query("SHOW TABLES");
    $tables = $tables_query->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $error_message = "Error fetching tables: " . $e->getMessage();
    $tables = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_record']) && $selected_table && $record_id) {
        // Handle record deletion
        try {
            $primary_key = getPrimaryKey($conn, $selected_table);
            $stmt = $conn->prepare("DELETE FROM $selected_table WHERE $primary_key = :id");
            $stmt->execute([':id' => $record_id]);
            
            // Log the activity
            logActivity($conn, $_SESSION['admin_id'], "delete_record", "Deleted record #$record_id from table $selected_table");
            
            $success_message = "Record deleted successfully from $selected_table";
            // Redirect to view table after deletion
            header("Location: database_manager.php?table=$selected_table&action=view");
            exit();
        } catch (PDOException $e) {
            $error_message = "Error deleting record: " . $e->getMessage();
        }
    } elseif (isset($_POST['save_record']) && $selected_table) {
        // Handle record creation/update
        try {
            $fields = [];
            $values = [];
            $primary_key = getPrimaryKey($conn, $selected_table);
            
            // Get table columns
            $columns_query = $conn->query("DESCRIBE $selected_table");
            $columns = $columns_query->fetchAll(PDO::FETCH_ASSOC);
            
            // Build field list and values for SQL
            foreach ($columns as $column) {
                $column_name = $column['Field'];
                
                // Skip auto-increment primary key for inserts
                if ($action === 'add' && $column_name === $primary_key && $column['Extra'] === 'auto_increment') {
                    continue;
                }
                
                // Handle special cases
                if (isset($_POST[$column_name])) {
                    $fields[] = $column_name;
                    $values[":$column_name"] = $_POST[$column_name];
                }
            }
            
            if ($action === 'edit' && $record_id) {
                // Update existing record
                $set_clause = implode(', ', array_map(function($field) {
                    return "$field = :$field";
                }, $fields));
                
                $sql = "UPDATE $selected_table SET $set_clause WHERE $primary_key = :id";
                $values[':id'] = $record_id;
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($values);
                
                logActivity($conn, $_SESSION['admin_id'], "update_record", "Updated record #$record_id in table $selected_table");
                $success_message = "Record updated successfully in $selected_table";
            } else {
                // Insert new record
                $field_list = implode(', ', $fields);
                $placeholder_list = implode(', ', array_map(function($field) {
                    return ":$field";
                }, $fields));
                
                $sql = "INSERT INTO $selected_table ($field_list) VALUES ($placeholder_list)";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($values);
                
                $new_id = $conn->lastInsertId();
                logActivity($conn, $_SESSION['admin_id'], "create_record", "Created new record #$new_id in table $selected_table");
                $success_message = "New record created successfully in $selected_table";
            }
            
            // Redirect to view table after save
            header("Location: database_manager.php?table=$selected_table&action=view");
            exit();
        } catch (PDOException $e) {
            $error_message = "Error saving record: " . $e->getMessage();
        }
    } elseif (isset($_POST['execute_query'])) {
        // Handle custom SQL query execution
        $sql_query = $_POST['sql_query'] ?? '';
        
        if (!empty($sql_query)) {
            try {
                // Check if it's a SELECT query
                $is_select = stripos(trim($sql_query), 'SELECT') === 0;
                
                if ($is_select) {
                    $stmt = $conn->prepare($sql_query);
                    $stmt->execute();
                    $custom_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $success_message = "Query executed successfully. " . count($custom_results) . " rows returned.";
                } else {
                    $stmt = $conn->prepare($sql_query);
                    $affected_rows = $stmt->execute();
                    $success_message = "Query executed successfully. Affected rows: " . $stmt->rowCount();
                    
                    // Log non-SELECT queries
                    logActivity($conn, $_SESSION['admin_id'], "execute_query", "Executed custom SQL: " . substr($sql_query, 0, 100) . (strlen($sql_query) > 100 ? '...' : ''));
                }
            } catch (PDOException $e) {
                $error_message = "Error executing query: " . $e->getMessage();
            }
        } else {
            $error_message = "Please enter a SQL query to execute.";
        }
    }
}

// Get record data for edit form
$record_data = [];
if ($selected_table && $action === 'edit' && $record_id) {
    try {
        $primary_key = getPrimaryKey($conn, $selected_table);
        $stmt = $conn->prepare("SELECT * FROM $selected_table WHERE $primary_key = :id");
        $stmt->execute([':id' => $record_id]);
        $record_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$record_data) {
            $error_message = "Record not found.";
            $action = 'view'; // Fallback to view mode
        }
    } catch (PDOException $e) {
        $error_message = "Error fetching record: " . $e->getMessage();
        $action = 'view'; // Fallback to view mode
    }
}

// Get table structure for add/edit forms
$table_columns = [];
if ($selected_table && ($action === 'add' || $action === 'edit')) {
    try {
        $columns_query = $conn->query("DESCRIBE $selected_table");
        $table_columns = $columns_query->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message = "Error fetching table structure: " . $e->getMessage();
    }
}

// Get table data for view mode
$table_data = [];
$total_records = 0;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

if ($selected_table && $action === 'view') {
    try {
        // Get total count
        $count_stmt = $conn->query("SELECT COUNT(*) FROM $selected_table");
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $per_page);
        
        // Get paginated data
        $stmt = $conn->prepare("SELECT * FROM $selected_table LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get column names
        $columns_query = $conn->query("DESCRIBE $selected_table");
        $table_columns = $columns_query->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message = "Error fetching table data: " . $e->getMessage();
    }
}

// Helper function to get primary key of a table
function getPrimaryKey($conn, $table) {
    $stmt = $conn->prepare("SHOW KEYS FROM $table WHERE Key_name = 'PRIMARY'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['Column_name'] : 'id';
}

// Helper function to log admin activity
function logActivity($conn, $admin_id, $action, $details) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (:user_id, 'admin', :action, :details, :ip, :user_agent)
        ");
        $stmt->execute([
            ':user_id' => $admin_id,
            ':action' => $action,
            ':details' => $details,
            ':ip' => $_SERVER['REMOTE_ADDR'],
            ':user_agent' => $_SERVER['HTTP_USER_AGENT']
        ]);
    } catch (PDOException $e) {
        // Silently fail - don't let logging errors affect the main functionality
    }
}

// Generate pagination URL
function getPaginationUrl($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Transactions - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .transaction-card {
            transition: all 0.3s ease;
        }
        .transaction-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">Database Manager</h1>
            <p class="text-gray-400 mt-1">Manage database tables and records</p>
        </div>
        <div class="mt-4 md:mt-0">
            <a href="index.php" class="bg-gray-700 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
            </a>
        </div>
    </div>
   

    <!-- Alerts Section -->
    <?php if ($success_message): ?>
    <div class="bg-green-600 text-white p-4 rounded-lg mb-6 animate-fade-in">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <i class="fas fa-check-circle text-xl"></i>
            </div>
            <div class="ml-3">
                <p class="font-medium"><?= htmlspecialchars($success_message) ?></p>
            </div>
            <button class="ml-auto" onclick="this.parentElement.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
    <div class="bg-red-600 text-white p-4 rounded-lg mb-6 animate-fade-in">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <i class="fas fa-exclamation-circle text-xl"></i>
            </div>
            <div class="ml-3">
                <p class="font-medium"><?= htmlspecialchars($error_message) ?></p>
            </div>
            <button class="ml-auto" onclick="this.parentElement.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="flex flex-col md:flex-row gap-6">
        <!-- Sidebar with tables list -->
        <div class="w-full md:w-1/4">
            <div class="bg-gray-800 rounded-xl shadow-md overflow-hidden mb-6">
                <div class="px-6 py-4 bg-gray-700 border-b border-gray-600">
                    <h3 class="text-lg font-semibold text-white">Database Tables</h3>
                </div>
                <div class="sidebar-table max-h-[calc(100vh-200px)] overflow-y-auto">
                    <ul class="divide-y divide-gray-700">
                        <?php foreach ($tables as $table): ?>
                            <li>
                                <a href="?table=<?= urlencode($table) ?>&action=view" 
                                   class="block px-6 py-3 hover:bg-gray-700 transition duration-150 <?= $selected_table === $table ? 'bg-blue-900 text-white font-medium' : 'text-gray-300' ?>">
                                    <i class="fas fa-table mr-2 <?= $selected_table === $table ? 'text-blue-400' : 'text-gray-500' ?>"></i>
                                    <?= htmlspecialchars($table) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

            <!-- SQL Query Tool -->
            <div class="bg-gray-800 rounded-xl shadow-md overflow-hidden">
                <div class="px-6 py-4 bg-gray-700 border-b border-gray-600">
                    <h3 class="text-lg font-semibold text-white">SQL Query Tool</h3>
                </div>
                <div class="p-6">
                    <form method="POST" action="">
                        <div class="mb-4">
                            <label for="sql_query" class="block text-sm font-medium text-gray-300 mb-2">SQL Query</label>
                            <textarea id="sql_query" name="sql_query" rows="4" 
                                class="w-full rounded-md bg-gray-700 border-gray-600 text-white shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                                placeholder="Enter your SQL query here..."><?= isset($_POST['sql_query']) ? htmlspecialchars($_POST['sql_query']) : '' ?></textarea>
                        </div>
                        <div>
                        <button type="submit" name="execute_query" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                                <i class="fas fa-play mr-2"></i> Execute Query
                            </button>
                        </div>
                        <div class="mt-2 text-xs text-gray-400">
                            <p>Use with caution. Changes made with SQL queries cannot be undone.</p>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Main content area -->
        <div class="w-full md:w-3/4">
            <?php if ($selected_table): ?>
                <!-- Table actions -->
                <div class="bg-gray-800 rounded-xl shadow-md overflow-hidden mb-6">
                    <div class="px-6 py-4 bg-gray-700 border-b border-gray-600 flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-white">
                            Table: <?= htmlspecialchars($selected_table) ?>
                        </h3>
                        <div class="flex space-x-2">
                            <a href="?table=<?= urlencode($selected_table) ?>&action=view" 
                               class="px-3 py-1 bg-gray-600 text-white rounded-md hover:bg-gray-500 transition duration-150 <?= $action === 'view' ? 'font-semibold' : '' ?>">
                                <i class="fas fa-list mr-1"></i> View
                            </a>
                            <a href="?table=<?= urlencode($selected_table) ?>&action=add" 
                               class="px-3 py-1 bg-green-600 text-white rounded-md hover:bg-green-700 transition duration-150 <?= $action === 'add' ? 'font-semibold' : '' ?>">
                                <i class="fas fa-plus mr-1"></i> Add
                            </a>
                            <a href="export_table.php?table=<?= urlencode($selected_table) ?>" 
                               class="px-3 py-1 bg-purple-600 text-white rounded-md hover:bg-purple-700 transition duration-150">
                                <i class="fas fa-file-export mr-1"></i> Export
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($action === 'view' && !empty($table_data)): ?>
                    <!-- Table data view -->
                    <div class="bg-gray-800 rounded-xl shadow-md overflow-hidden mb-6">
                        <div class="px-6 py-4 bg-gray-700 border-b border-gray-600 flex justify-between items-center">
                            <div>
                                <h3 class="text-lg font-semibold text-white">Records</h3>
                                <p class="text-sm text-gray-400">
                                    Showing <?= count($table_data) ?> of <?= $total_records ?> records
                                </p>
                            </div>
                            <div>
                                <form method="GET" action="" class="flex items-center">
                                    <input type="hidden" name="table" value="<?= htmlspecialchars($selected_table) ?>">
                                    <input type="hidden" name="action" value="view">
                                    <label for="search" class="sr-only">Search</label>
                                    <input type="text" id="search" name="search" placeholder="Search..." 
                                        class="rounded-l-md bg-gray-700 border-gray-600 text-white shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                                        value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
                                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-r-md hover:bg-blue-700 transition duration-150">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-600">
                                <thead class="bg-gray-700">
                                    <tr>
                                        <?php 
                                        $primary_key = getPrimaryKey($conn, $selected_table);
                                        foreach ($table_columns as $column): ?>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                                <?= htmlspecialchars($column['Field']) ?>
                                                <?php if ($column['Field'] === $primary_key): ?>
                                                    <i class="fas fa-key text-yellow-500 ml-1"></i>
                                                <?php endif; ?>
                                            </th>
                                        <?php endforeach; ?>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-300 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="bg-gray-800 divide-y divide-gray-700">
                                    <?php foreach ($table_data as $row): ?>
                                        <tr class="hover:bg-gray-700">
                                            <?php foreach ($row as $key => $value): ?>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                                    <?php 
                                                    // Truncate long values
                                                    if (is_string($value) && strlen($value) > 100) {
                                                        echo htmlspecialchars(substr($value, 0, 100)) . '...';
                                                    } elseif (is_null($value)) {
                                                        echo '<span class="text-gray-500 italic">NULL</span>';
                                                    } else {
                                                        echo htmlspecialchars($value);
                                                    }
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <a href="?table=<?= urlencode($selected_table) ?>&action=edit&id=<?= $row[$primary_key] ?>" 
                                                   class="text-blue-400 hover:text-blue-300 mr-3">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="#" onclick="confirmDelete('<?= htmlspecialchars($selected_table) ?>', <?= $row[$primary_key] ?>)" 
                                                   class="text-red-400 hover:text-red-300">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="px-6 py-4 bg-gray-700 border-t border-gray-600">
                                <div class="flex justify-between items-center">
                                    <div class="text-sm text-gray-300">
                                        Page <?= $page ?> of <?= $total_pages ?>
                                    </div>
                                    <div class="flex space-x-2">
                                        <?php if ($page > 1): ?>
                                            <a href="<?= getPaginationUrl($page - 1) ?>" 
                                               class="px-3 py-1 bg-gray-600 text-white rounded-md hover:bg-gray-500 transition duration-150">
                                                <i class="fas fa-chevron-left mr-1"></i> Previous
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($page < $total_pages): ?>
                                            <a href="<?= getPaginationUrl($page + 1) ?>" 
                                               class="px-3 py-1 bg-gray-600 text-white rounded-md hover:bg-gray-500 transition duration-150">
                                                Next <i class="fas fa-chevron-right ml-1"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($action === 'view' && empty($table_data)): ?>
                    <div class="bg-gray-800 rounded-xl shadow-md overflow-hidden mb-6 p-6 text-center">
                        <i class="fas fa-database text-gray-500 text-5xl mb-4"></i>
                        <p class="text-gray-400">No records found in this table.</p>
                        <a href="?table=<?= urlencode($selected_table) ?>&action=add" 
                           class="mt-4 inline-block px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition duration-150">
                            <i class="fas fa-plus mr-2"></i> Add New Record
                        </a>
                    </div>
                <?php elseif ($action === 'add' || $action === 'edit'): ?>
                    <!-- Add/Edit Form -->
                    <div class="bg-gray-800 rounded-xl shadow-md overflow-hidden mb-6">
                        <div class="px-6 py-4 bg-gray-700 border-b border-gray-600">
                            <h3 class="text-lg font-semibold text-white">
                                <?= $action === 'add' ? 'Add New Record' : 'Edit Record' ?>
                            </h3>
                        </div>
                        <div class="p-6">
                            <form method="POST" action="">
                                <input type="hidden" name="save_record" value="1">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <?php foreach ($table_columns as $column): ?>
                                        <?php
                                        $column_name = $column['Field'];
                                        $column_type = $column['Type'];
                                        $is_primary = $column_name === getPrimaryKey($conn, $selected_table);
                                        $is_auto_increment = strpos($column['Extra'], 'auto_increment') !== false;
                                        
                                        // Skip auto-increment primary key for new records
                                        if ($action === 'add' && $is_primary && $is_auto_increment) {
                                            continue;
                                        }
                                        
                                        // Determine field type
                                        $input_type = 'text';
                                        $is_textarea = false;
                                        $is_date = false;
                                        $is_datetime = false;
                                        $is_enum = false;
                                        $enum_values = [];
                                        
                                        if (strpos($column_type, 'int') !== false) {
                                            $input_type = 'number';
                                        } elseif (strpos($column_type, 'decimal') !== false || strpos($column_type, 'float') !== false || strpos($column_type, 'double') !== false) {
                                            $input_type = 'number';
                                            $step = 'any';
                                        } elseif (strpos($column_type, 'text') !== false || strpos($column_type, 'longtext') !== false) {
                                            $is_textarea = true;
                                        } elseif (strpos($column_type, 'date') !== false && strpos($column_type, 'datetime') === false) {
                                            $input_type = 'date';
                                            $is_date = true;
                                        } elseif (strpos($column_type, 'datetime') !== false || strpos($column_type, 'timestamp') !== false) {
                                            $input_type = 'datetime-local';
                                            $is_datetime = true;
                                        } elseif (strpos($column_type, 'enum') !== false) {
                                            $is_enum = true;
                                            // Extract enum values
                                            preg_match("/enum\(\'(.*)\'\)/", $column_type, $matches);
                                            if (isset($matches[1])) {
                                                $enum_values = explode("','", $matches[1]);
                                            }
                                        }
                                        
                                        // Get current value for edit mode
                                        $current_value = '';
                                        if ($action === 'edit' && isset($record_data[$column_name])) {
                                            $current_value = $record_data[$column_name];
                                            
                                            // Format datetime for input
                                            if ($is_datetime && $current_value) {
                                                $current_value = date('Y-m-d\TH:i', strtotime($current_value));
                                            }
                                        }
                                        
                                        // Determine if field is required
                                        $is_required = $column['Null'] === 'NO' && $column['Default'] === null && !$is_auto_increment;
                                        ?>
                                        
                                        <div class="col-span-1">
                                            <label for="<?= $column_name ?>" class="block text-sm font-medium text-gray-300 mb-1">
                                                <?= htmlspecialchars($column_name) ?>
                                                <?php if ($is_primary): ?>
                                                    <i class="fas fa-key text-yellow-500 ml-1"></i>
                                                <?php endif; ?>
                                                <?php if ($is_required): ?>
                                                    <span class="text-red-500">*</span>
                                                <?php endif; ?>
                                            </label>
                                            
                                            <?php if ($is_textarea): ?>
                                                <textarea id="<?= $column_name ?>" name="<?= $column_name ?>" rows="3"
                                                    class="w-full rounded-md bg-gray-700 border-gray-600 text-white shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                                                    <?= $is_required ? 'required' : '' ?>
                                                    <?= $is_primary && $action === 'edit' ? 'readonly' : '' ?>><?= htmlspecialchars($current_value) ?></textarea>
                                            <?php elseif ($is_enum): ?>
                                                <select id="<?= $column_name ?>" name="<?= $column_name ?>"
                                                class="w-full rounded-md bg-gray-700 border-gray-600 text-white shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                                                    <?= $is_required ? 'required' : '' ?>>
                                                    <?php if (!$is_required): ?>
                                                        <option value="">-- Select --</option>
                                                    <?php endif; ?>
                                                    <?php foreach ($enum_values as $enum_value): ?>
                                                        <option value="<?= htmlspecialchars($enum_value) ?>" <?= $current_value === $enum_value ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($enum_value) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <input type="<?= $input_type ?>" id="<?= $column_name ?>" name="<?= $column_name ?>"
                                                    class="w-full rounded-md bg-gray-700 border-gray-600 text-white shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500 focus:ring-opacity-50"
                                                    value="<?= htmlspecialchars($current_value) ?>"
                                                    <?= $is_required ? 'required' : '' ?>
                                                    <?= $is_primary && $action === 'edit' ? 'readonly' : '' ?>
                                                    <?= isset($step) ? "step=\"$step\"" : '' ?>>
                                            <?php endif; ?>
                                            
                                            <?php if (isset($column['Comment']) && strpos($column['Comment'], ':') !== false): ?>
    <p class="mt-1 text-xs text-gray-400"><?= htmlspecialchars($column['Comment']) ?></p>
<?php endif; ?>

                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <div class="mt-6 flex justify-end space-x-3">
                                    <a href="?table=<?= urlencode($selected_table) ?>&action=view" 
                                       class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-500 transition duration-150">
                                        Cancel
                                    </a>
                                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-150">
                                        <?= $action === 'add' ? 'Create Record' : 'Update Record' ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Table Operations -->
                <div class="bg-gray-800 rounded-xl shadow-md overflow-hidden mb-6">
                    <div class="px-6 py-4 bg-gray-700 border-b border-gray-600">
                        <h3 class="text-lg font-semibold text-white">Table Operations</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <h4 class="text-md font-medium text-white mb-2">Table Structure</h4>
                                <a href="?table=<?= urlencode($selected_table) ?>&action=structure" 
                                   class="inline-block px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition duration-150">
                                    <i class="fas fa-table mr-2"></i> View Structure
                                </a>
                            </div>
                            
                            <div>
                                <h4 class="text-md font-medium text-white mb-2">Truncate Table</h4>
                                <button type="button" onclick="confirmTruncate('<?= htmlspecialchars($selected_table) ?>')"
                                        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition duration-150">
                                    <i class="fas fa-trash-alt mr-2"></i> Truncate Table
                                </button>
                                <p class="mt-1 text-xs text-gray-400">This will delete all records but keep the table structure.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($action === 'structure'): ?>
                    <!-- Table Structure View -->
                    <div class="bg-gray-800 rounded-xl shadow-md overflow-hidden mb-6">
                        <div class="px-6 py-4 bg-gray-700 border-b border-gray-600">
                            <h3 class="text-lg font-semibold text-white">Table Structure</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-600">
                                <thead class="bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Field</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Type</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Null</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Key</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Default</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Extra</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-gray-800 divide-y divide-gray-700">
                                    <?php 
                                    $structure_query = $conn->query("DESCRIBE $selected_table");
                                    $structure = $structure_query->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    foreach ($structure as $column): 
                                    ?>
                                        <tr class="hover:bg-gray-700">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-white">
                                                <?= htmlspecialchars($column['Field']) ?>
                                                <?php if ($column['Field'] === getPrimaryKey($conn, $selected_table)): ?>
                                                    <i class="fas fa-key text-yellow-500 ml-1"></i>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                                <?= htmlspecialchars($column['Type']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                                <?= $column['Null'] === 'YES' ? 'Yes' : 'No' ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                                <?= htmlspecialchars($column['Key']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                                <?= $column['Default'] === null ? '<span class="text-gray-500 italic">NULL</span>' : htmlspecialchars($column['Default']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                                <?= htmlspecialchars($column['Extra']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Indexes -->
                    <div class="bg-gray-800 rounded-xl shadow-md overflow-hidden mb-6">
                        <div class="px-6 py-4 bg-gray-700 border-b border-gray-600">
                            <h3 class="text-lg font-semibold text-white">Table Indexes</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <?php
                            $indexes_query = $conn->query("SHOW INDEXES FROM $selected_table");
                            $indexes = $indexes_query->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (count($indexes) > 0):
                            ?>
                                <table class="min-w-full divide-y divide-gray-600">
                                    <thead class="bg-gray-700">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Key Name</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Column</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Unique</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Type</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-gray-800 divide-y divide-gray-700">
                                        <?php foreach ($indexes as $index): ?>
                                            <tr class="hover:bg-gray-700">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-white">
                                                    <?= htmlspecialchars($index['Key_name']) ?>
                                                    <?php if ($index['Key_name'] === 'PRIMARY'): ?>
                                                        <i class="fas fa-key text-yellow-500 ml-1"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                                    <?= htmlspecialchars($index['Column_name']) ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                                    <?= $index['Non_unique'] == 0 ? 'Yes' : 'No' ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                                    <?= htmlspecialchars($index['Index_type']) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="p-6 text-center text-gray-400">
                                    <p>No indexes found for this table.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- No table selected -->
                <div class="bg-gray-800 rounded-xl shadow-md overflow-hidden p-8 text-center">
                    <i class="fas fa-database text-gray-500 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-white mb-2">Select a Table</h3>
                    <p class="text-gray-400">
                        Please select a table from the sidebar to view, edit, or manage its data.
                    </p>
                </div>
            <?php endif; ?>

            <!-- Custom Query Results (if any) -->
            <?php if (isset($custom_results) && $is_select): ?>
                <div class="bg-gray-800 rounded-xl shadow-md overflow-hidden mb-6">
                    <div class="px-6 py-4 bg-gray-700 border-b border-gray-600">
                        <h3 class="text-lg font-semibold text-white">Query Results</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <?php if (empty($custom_results)): ?>
                            <div class="p-6 text-center text-gray-400">
                                <p>Your query returned no results.</p>
                            </div>
                        <?php else: ?>
                            <table class="min-w-full divide-y divide-gray-600">
                                <thead class="bg-gray-700">
                                    <tr>
                                        <?php foreach (array_keys($custom_results[0]) as $column): ?>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                                <?= htmlspecialchars($column) ?>
                                            </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody class="bg-gray-800 divide-y divide-gray-700">
                                    <?php foreach ($custom_results as $row): ?>
                                        <tr class="hover:bg-gray-700">
                                            <?php foreach ($row as $value): ?>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                                                    <?php 
                                                    if (is_null($value)) {
                                                        echo '<span class="text-gray-500 italic">NULL</span>';
                                                    } elseif (is_string($value) && strlen($value) > 100) {
                                                        echo htmlspecialchars(substr($value, 0, 100)) . '...';
                                                    } else {
                                                        echo htmlspecialchars($value);
                                                    }
                                                    ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Delete Confirmation Form (Hidden) -->
<form id="deleteRecordForm" method="POST" action="" class="hidden">
    <input type="hidden" name="delete_record" value="1">
    <input type="hidden" id="delete_record_id" name="record_id" value="">
</form>

<!-- Truncate Table Form (Hidden) -->
<form id="truncateTableForm" method="POST" action="truncate_table.php" class="hidden">
<input type="hidden" id="truncate_table_name" name="table_name" value="">
    <input type="hidden" name="confirm_truncate" value="1">
</form>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
    <div class="bg-gray-800 rounded-xl p-6 max-w-md w-full">
        <div class="flex justify-between items-center mb-4 border-b border-gray-700 pb-3">
            <h3 class="text-xl font-bold text-white">Confirm Deletion</h3>
            <button type="button" class="text-gray-400 hover:text-white" onclick="closeDeleteModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="mb-6">
            <div class="bg-red-900 bg-opacity-30 p-4 rounded-lg mb-4">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-red-500 text-2xl mr-3"></i>
                    <div>
                        <p class="text-white font-medium">Are you sure you want to delete this record?</p>
                        <p class="text-gray-300 text-sm mt-1">This action cannot be undone.</p>
                    </div>
                </div>
            </div>
        </div>
        <div class="flex justify-end space-x-3">
            <button type="button" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-500" onclick="closeDeleteModal()">
                Cancel
            </button>
            <button type="button" id="confirmDeleteBtn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                <i class="fas fa-trash-alt mr-2"></i> Delete Record
            </button>
        </div>
    </div>
</div>

<!-- Truncate Confirmation Modal -->
<div id="truncateModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
    <div class="bg-gray-800 rounded-xl p-6 max-w-md w-full">
        <div class="flex justify-between items-center mb-4 border-b border-gray-700 pb-3">
            <h3 class="text-xl font-bold text-white">Confirm Truncate</h3>
            <button type="button" class="text-gray-400 hover:text-white" onclick="closeTruncateModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="mb-6">
            <div class="bg-red-900 bg-opacity-30 p-4 rounded-lg mb-4">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-red-500 text-2xl mr-3"></i>
                    <div>
                        <p class="text-white font-medium">WARNING: You are about to delete ALL records!</p>
                        <p class="text-gray-300 text-sm mt-1">This will remove all data from the table <span id="truncateTableName" class="font-semibold"></span> but keep the table structure. This action cannot be undone.</p>
                    </div>
                </div>
            </div>
            <div class="mb-4">
                <label for="truncateConfirmation" class="block text-sm font-medium text-gray-300 mb-2">Type "TRUNCATE" to confirm:</label>
                <input type="text" id="truncateConfirmation" class="w-full rounded-md bg-gray-700 border-gray-600 text-white shadow-sm focus:border-red-500 focus:ring focus:ring-red-500 focus:ring-opacity-50" placeholder="TRUNCATE">
            </div>
        </div>
        <div class="flex justify-end space-x-3">
            <button type="button" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-500" onclick="closeTruncateModal()">
                Cancel
            </button>
            <button type="button" id="confirmTruncateBtn" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 opacity-50 cursor-not-allowed">
                <i class="fas fa-trash-alt mr-2"></i> Truncate Table
            </button>
        </div>
    </div>
</div>
</div>
<script>
    // Confirm record deletion
    function confirmDelete(tableName, recordId) {
        const deleteModal = document.getElementById('deleteModal');
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        
        // Show modal
        deleteModal.classList.remove('hidden');
        
        // Set up confirmation button
        confirmDeleteBtn.onclick = function() {
            document.getElementById('delete_record_id').value = recordId;
            document.getElementById('deleteRecordForm').action = `?table=${encodeURIComponent(tableName)}&action=view`;
            document.getElementById('deleteRecordForm').submit();
        };
    }
    
    // Close delete modal
    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.add('hidden');
    }
    
    // Confirm table truncation
    function confirmTruncate(tableName) {
        const truncateModal = document.getElementById('truncateModal');
        const truncateTableName = document.getElementById('truncateTableName');
        const truncateConfirmation = document.getElementById('truncateConfirmation');
        const confirmTruncateBtn = document.getElementById('confirmTruncateBtn');
        
        // Show modal
        truncateModal.classList.remove('hidden');
        truncateTableName.textContent = tableName;
        truncateConfirmation.value = '';
        
        // Disable button initially
        confirmTruncateBtn.classList.add('opacity-50', 'cursor-not-allowed');
        confirmTruncateBtn.disabled = true;
        
        // Check confirmation text
        truncateConfirmation.addEventListener('input', function() {
            if (this.value === 'TRUNCATE') {
                confirmTruncateBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                confirmTruncateBtn.disabled = false;
            } else {
                confirmTruncateBtn.classList.add('opacity-50', 'cursor-not-allowed');
                confirmTruncateBtn.disabled = true;
            }
        });
        
        // Set up confirmation button
        confirmTruncateBtn.onclick = function() {
            if (truncateConfirmation.value === 'TRUNCATE') {
                document.getElementById('truncate_table_name').value = tableName;
                document.getElementById('truncateTableForm').submit();
            }
        };
    }
    
    // Close truncate modal
    function closeTruncateModal() {
        document.getElementById('truncateModal').classList.add('hidden');
    }
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        const deleteModal = document.getElementById('deleteModal');
        const truncateModal = document.getElementById('truncateModal');
        
        if (event.target === deleteModal) {
            closeDeleteModal();
        }
        
        if (event.target === truncateModal) {
            closeTruncateModal();
        }
    });
    
    // Close modals with ESC key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeDeleteModal();
            closeTruncateModal();
        }
    });
    
    // Auto-hide alerts after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            const alerts = document.querySelectorAll('.animate-fade-in');
            alerts.forEach(function(alert) {
                alert.classList.add('opacity-0', 'transition-opacity', 'duration-500');
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);
    });
</script>

<style>
    /* Custom scrollbar for Webkit browsers */
    ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
    }
    
    ::-webkit-scrollbar-track {
        background: #374151;
        border-radius: 4px;
    }
    
    ::-webkit-scrollbar-thumb {
        background: #4B5563;
        border-radius: 4px;
    }
    
    ::-webkit-scrollbar-thumb:hover {
        background: #6B7280;
    }
    
    /* Animation for alerts */
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .animate-fade-in {
        animation: fadeIn 0.3s ease-out forwards;
    }
</style>
</body>
</html>