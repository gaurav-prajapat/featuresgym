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
$backupFiles = [];
$backupDir = '../backups/';

// Create backup directory if it doesn't exist
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Get all existing backup files
if (is_dir($backupDir)) {
    $files = scandir($backupDir);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..' && pathinfo($file, PATHINFO_EXTENSION) == 'sql') {
            $backupFiles[] = [
                'name' => $file,
                'size' => filesize($backupDir . $file),
                'date' => filemtime($backupDir . $file)
            ];
        }
    }
    
    // Sort by date (newest first)
    usort($backupFiles, function($a, $b) {
        return $b['date'] - $a['date'];
    });
}

// Get database credentials directly from the database class properties
// Since these are private properties in the GymDatabase class, we need to use the values from the class
$dbHost = "localhost"; // Same as in GymDatabase class
$dbName = "gym-p";     // Same as in GymDatabase class
$dbUser = "root";      // Same as in GymDatabase class
$dbPass = "";          // Same as in GymDatabase class

// Handle backup creation
if (isset($_POST['create_backup'])) {
    $timestamp = date('Y-m-d_H-i-s');
    $backupFileName = "flexfit_backup_{$timestamp}.sql";
    $backupFilePath = $backupDir . $backupFileName;
    
    try {
        // Create backup command
        $command = sprintf(
            'mysqldump --host=%s --user=%s --password=%s %s > %s',
            escapeshellarg($dbHost),
            escapeshellarg($dbUser),
            escapeshellarg($dbPass),
            escapeshellarg($dbName),
            escapeshellarg($backupFilePath)
        );
        
        // Execute backup command
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);
        
        if ($returnVar === 0) {
            $message = "Database backup created successfully: {$backupFileName}";
            
            // Log the activity
            $adminId = $_SESSION['admin_id'];
            $activitySql = "
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (
                    ?, 'admin', ?, ?, ?, ?
                )
            ";
            $details = "Admin ID: {$adminId} created database backup: {$backupFileName}";
            $activityStmt = $conn->prepare($activitySql);
            $activityStmt->execute([
                $adminId,
                'create_backup',
                $details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            // Refresh the page to show the new backup
            header('Location: backup.php?success=1');
            exit();
        } else {
            $error = "Failed to create backup. Error code: {$returnVar}";
        }
    } catch (Exception $e) {
        $error = "Error creating backup: " . $e->getMessage();
    }
}

// Handle backup restoration
if (isset($_POST['restore_backup']) && isset($_POST['backup_file'])) {
    $backupFile = $_POST['backup_file'];
    $backupFilePath = $backupDir . basename($backupFile);
    
    // Validate that the file exists and is a .sql file
    if (file_exists($backupFilePath) && pathinfo($backupFilePath, PATHINFO_EXTENSION) == 'sql') {
        try {
            // Create restore command
            $command = sprintf(
                'mysql --host=%s --user=%s --password=%s %s < %s',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($backupFilePath)
            );
            
            // Execute restore command
            $output = [];
            $returnVar = 0;
            exec($command, $output, $returnVar);
            
            if ($returnVar === 0) {
                $message = "Database restored successfully from: " . basename($backupFile);
                
                // Log the activity
                $adminId = $_SESSION['admin_id'];
                $activitySql = "
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (
                        ?, 'admin', ?, ?, ?, ?
                    )
                ";
                $details = "Admin ID: {$adminId} restored database from backup: " . basename($backupFile);
                $activityStmt = $conn->prepare($activitySql);
                $activityStmt->execute([
                    $adminId,
                    'restore_backup',
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                // Refresh the page to show success message
                header('Location: backup.php?restored=1');
                exit();
            } else {
                $error = "Failed to restore database. Error code: {$returnVar}";
            }
        } catch (Exception $e) {
            $error = "Error restoring database: " . $e->getMessage();
        }
    } else {
        $error = "Invalid backup file selected.";
    }
}

// Handle backup deletion
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $backupFile = basename($_GET['delete']);
    $backupFilePath = $backupDir . $backupFile;
    
    if (file_exists($backupFilePath) && pathinfo($backupFilePath, PATHINFO_EXTENSION) == 'sql') {
        if (unlink($backupFilePath)) {
            // Log the activity
            $adminId = $_SESSION['admin_id'];
            $activitySql = "
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (
                    ?, 'admin', ?, ?, ?, ?
                )
            ";
            $details = "Admin ID: {$adminId} deleted backup file: {$backupFile}";
            $activityStmt = $conn->prepare($activitySql);
            $activityStmt->execute([
                $adminId,
                'delete_backup',
                $details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            header('Location: backup.php?deleted=1');
            exit();
        } else {
            $error = "Failed to delete backup file.";
        }
    } else {
        $error = "Invalid backup file selected for deletion.";
    }
}

// Handle backup download
if (isset($_GET['download']) && !empty($_GET['download'])) {
    $backupFile = basename($_GET['download']);
    $backupFilePath = $backupDir . $backupFile;
    
    if (file_exists($backupFilePath) && pathinfo($backupFilePath, PATHINFO_EXTENSION) == 'sql') {
        // Log the activity
        $adminId = $_SESSION['admin_id'];
        $activitySql = "
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (
                ?, 'admin', ?, ?, ?, ?
            )
        ";
        $details = "Admin ID: {$adminId} downloaded backup file: {$backupFile}";
        $activityStmt = $conn->prepare($activitySql);
        $activityStmt->execute([
            $adminId,
            'download_backup',
            $details,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        // Set headers for download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $backupFile . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($backupFilePath));
        readfile($backupFilePath);
        exit;
    } else {
        $error = "Invalid backup file selected for download.";
    }
}

// Set success messages from URL parameters
if (isset($_GET['success'])) {
    $message = "Database backup created successfully.";
}
if (isset($_GET['restored'])) {
    $message = "Database restored successfully.";
}
if (isset($_GET['deleted'])) {
    $message = "Backup file deleted successfully.";
}

// Get database size
$dbSizeQuery = "
    SELECT 
        SUM(data_length + index_length) / 1024 / 1024 AS size_mb 
    FROM information_schema.TABLES 
    WHERE table_schema = ?
    GROUP BY table_schema
";
$dbSizeStmt = $conn->prepare($dbSizeQuery);
$dbSizeStmt->execute([$dbName]);
$dbSize = $dbSizeStmt->fetchColumn();

// Get table count
$tableCountQuery = "
    SELECT COUNT(*) 
    FROM information_schema.TABLES 
    WHERE table_schema = ?
";
$tableCountStmt = $conn->prepare($tableCountQuery);
$tableCountStmt->execute([$dbName]);
$tableCount = $tableCountStmt->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Backup & Restore - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .backup-card {
            transition: all 0.3s ease;
        }
        .backup-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-64 p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">Database Backup & Restore</h1>
            <div class="flex space-x-4">
                <div class="bg-gray-800 rounded-lg px-4 py-2 flex items-center">
                    <i class="fas fa-database text-yellow-500 mr-2"></i>
                    <span><?php echo number_format($dbSize, 2); ?> MB</span>
                </div>
                <div class="bg-gray-800 rounded-lg px-4 py-2 flex items-center">
                    <i class="fas fa-table text-blue-500 mr-2"></i>
                    <span><?php echo $tableCount; ?> Tables</span>
                </div>
                <div class="bg-gray-800 rounded-lg px-4 py-2 flex items-center">
                    <i class="fas fa-save text-green-500 mr-2"></i>
                    <span><?php echo count($backupFiles); ?> Backups</span>
                </div>
            </div>
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
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Backup Actions -->
            <div class="lg:col-span-1">
                <div class="bg-gray-800 rounded-xl p-6 shadow-lg">
                    <h2 class="text-xl font-bold mb-4">Backup Actions</h2>
                    
                    <form method="POST" class="mb-6">
                        <button type="submit" name="create_backup" class="w-full bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-3 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-download mr-2"></i> Create New Backup
                        </button>
                    </form>
                    
                    <?php if (!empty($backupFiles)): ?>
                        <form method="POST" id="restoreForm" class="space-y-4">
                            <div>
                                <label for="backup_file" class="block text-sm font-medium text-gray-400 mb-1">Select Backup to Restore</label>
                                <select id="backup_file" name="backup_file" required
                                       class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                    <option value="">-- Select Backup File --</option>
                                    <?php foreach ($backupFiles as $file): ?>
                                        <option value="<?php echo htmlspecialchars($file['name']); ?>">
                                            <?php echo htmlspecialchars($file['name']); ?> 
                                            (<?php echo date('M d, Y H:i', $file['date']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <button type="submit" name="restore_backup" onclick="return confirmRestore()" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-lg font-medium transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-upload mr-2"></i> Restore Selected Backup
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="bg-gray-700 rounded-lg p-4 text-center">
                            <p class="text-gray-400">No backup files available.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="bg-gray-800 rounded-xl p-6 shadow-lg mt-6">
                    <h2 class="text-xl font-bold mb-4">Important Notes</h2>
                    <ul class="space-y-2 text-gray-300">
                        <li class="flex items-start">
                            <i class="fas fa-exclamation-triangle text-yellow-500 mt-1 mr-2"></i>
                            <span>Restoring a backup will <strong>overwrite</strong> all current data. This action cannot be undone.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-info-circle text-blue-500 mt-1 mr-2"></i>
                            <span>Regular backups are recommended to prevent data loss.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-shield-alt text-green-500 mt-1 mr-2"></i>
                            <span>Keep backup files secure as they contain sensitive information.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-clock text-purple-500 mt-1 mr-2"></i>
                            <span>Backup and restore operations may take some time depending on database size.</span>
                        </li>
                    </ul>
                </div>
            </div>
            
            <!-- Backup Files List -->
            <div class="lg:col-span-2">
                <div class="bg-gray-800 rounded-xl p-6 shadow-lg">
                    <h2 class="text-xl font-bold mb-4">Available Backups</h2>
                    
                    <?php if (empty($backupFiles)): ?>
                        <div class="bg-gray-700 rounded-xl p-8 text-center">
                            <i class="fas fa-database text-yellow-500 text-5xl mb-4"></i>
                            <h3 class="text-2xl font-bold mb-2">No Backups Found</h3>
                            <p class="text-gray-400 mb-6">Create your first database backup to protect your data.</p>
                            <form method="POST">
                                <button type="submit" name="create_backup" class="bg-yellow-500 hover:bg-yellow-600 text-black px-6 py-3 rounded-lg font-medium transition-colors duration-200">
                                    <i class="fas fa-download mr-2"></i> Create First Backup
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full bg-gray-700 rounded-lg overflow-hidden">
                                <thead>
                                    <tr>
                                        <th class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Filename</th>
                                        <th class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Date Created</th>
                                        <th class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Size</th>
                                        <th class="py-3 px-4 bg-gray-600 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-600">
                                    <?php foreach ($backupFiles as $file): ?>
                                        <tr class="hover:bg-gray-600 transition-colors duration-200">
                                            <td class="py-3 px-4 text-sm text-gray-300 font-medium">
                                                <?php echo htmlspecialchars($file['name']); ?>
                                            </td>
                                            <td class="py-3 px-4 text-sm text-gray-300">
                                                <?php echo date('M d, Y H:i:s', $file['date']); ?>
                                            </td>
                                            <td class="py-3 px-4 text-sm text-gray-300">
                                                <?php 
                                                    $sizeInMB = $file['size'] / (1024 * 1024);
                                                    echo $sizeInMB < 1 ? 
                                                        number_format($file['size'] / 1024, 2) . ' KB' : 
                                                        number_format($sizeInMB, 2) . ' MB'; 
                                                ?>
                                            </td>
                                            <td class="py-3 px-4 text-sm">
                                                <div class="flex space-x-2">
                                                    <a href="backup.php?download=<?php echo urlencode($file['name']); ?>" class="text-blue-400 hover:text-blue-300" title="Download">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    <a href="#" onclick="confirmRestore('<?php echo htmlspecialchars($file['name']); ?>')" class="text-green-400 hover:text-green-300" title="Restore">
                                                        <i class="fas fa-upload"></i>
                                                    </a>
                                                    <a href="#" onclick="confirmDelete('<?php echo htmlspecialchars($file['name']); ?>')" class="text-red-400 hover:text-red-300" title="Delete">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="bg-gray-800 rounded-xl p-6 shadow-lg mt-6">
                    <h2 class="text-xl font-bold mb-4">Backup Statistics</h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="bg-gray-700 rounded-lg p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-gray-400 text-sm">Total Backups</p>
                                    <p class="text-2xl font-bold text-white"><?php echo count($backupFiles); ?></p>
                                </div>
                                <div class="bg-blue-500 bg-opacity-20 p-3 rounded-full">
                                    <i class="fas fa-save text-blue-500 text-xl"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-700 rounded-lg p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-gray-400 text-sm">Latest Backup</p>
                                    <p class="text-xl font-bold text-white">
                                        <?php 
                                            echo !empty($backupFiles) ? 
                                                date('M d, Y', $backupFiles[0]['date']) : 
                                                'None'; 
                                        ?>
                                    </p>
                                </div>
                                <div class="bg-green-500 bg-opacity-20 p-3 rounded-full">
                                    <i class="fas fa-calendar-check text-green-500 text-xl"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="bg-gray-700 rounded-lg p-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-gray-400 text-sm">Total Storage</p>
                                    <p class="text-xl font-bold text-white">
                                        <?php 
                                            $totalSize = array_reduce($backupFiles, function($carry, $file) {
                                                return $carry + $file['size'];
                                            }, 0);
                                            
                                            $totalSizeInMB = $totalSize / (1024 * 1024);
                                            echo $totalSizeInMB < 1 ? 
                                                number_format($totalSize / 1024, 2) . ' KB' : 
                                                number_format($totalSizeInMB, 2) . ' MB';
                                        ?>
                                    </p>
                                </div>
                                <div class="bg-yellow-500 bg-opacity-20 p-3 rounded-full">
                                    <i class="fas fa-hdd text-yellow-500 text-xl"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-800 rounded-xl p-6 shadow-lg mt-6">
                    <h2 class="text-xl font-bold mb-4">Backup Schedule</h2>
                    
                    <div class="bg-gray-700 rounded-lg p-4">
                        <p class="text-gray-300 mb-4">
                            <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                            Setting up automatic backups requires server-level configuration. Contact your system administrator to set up a cron job for regular backups.
                        </p>
                        
                        <div class="bg-gray-800 rounded-lg p-4 font-mono text-sm text-gray-300">
                            <p class="mb-2"># Example cron job for daily backup at 2 AM:</p>
                            <p>0 2 * * * php <?php echo realpath(__DIR__ . '/../cron/backup.php'); ?> > /dev/null 2>&1</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Restore Confirmation Modal -->
    <div id="restore-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl p-6 max-w-md w-full mx-4">
            <h3 class="text-xl font-bold mb-4">Confirm Restoration</h3>
            <p class="text-gray-300 mb-6">Are you sure you want to restore the database from <span id="restore-backup-name" class="font-medium text-white"></span>? This will <strong class="text-red-400">overwrite all current data</strong> and cannot be undone.</p>
            
            <div class="flex justify-end space-x-3">
                <button id="cancel-restore" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    Cancel
                </button>
                <button id="confirm-restore" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    Restore
                </button>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="delete-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl p-6 max-w-md w-full mx-4">
            <h3 class="text-xl font-bold mb-4">Confirm Deletion</h3>
            <p class="text-gray-300 mb-6">Are you sure you want to delete the backup <span id="delete-backup-name" class="font-medium text-white"></span>? This action cannot be undone.</p>
            
            <div class="flex justify-end space-x-3">
                <button id="cancel-delete" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    Cancel
                </button>
                <a id="confirm-delete" href="#" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition-colors duration-200 text-center">
                    Delete
                </a>
            </div>
        </div>
    </div>
    
    <script>
        // Restore confirmation
        function confirmRestore(filename = null) {
            const modal = document.getElementById('restore-modal');
            const nameSpan = document.getElementById('restore-backup-name');
            const confirmButton = document.getElementById('confirm-restore');
            
            if (filename) {
                // Called from the table row
                nameSpan.textContent = filename;
                
                confirmButton.onclick = function() {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'backup.php';
                    
                    const backupFileInput = document.createElement('input');
                    backupFileInput.type = 'hidden';
                    backupFileInput.name = 'backup_file';
                    backupFileInput.value = filename;
                    
                    const restoreInput = document.createElement('input');
                    restoreInput.type = 'hidden';
                    restoreInput.name = 'restore_backup';
                    restoreInput.value = '1';
                    
                    form.appendChild(backupFileInput);
                    form.appendChild(restoreInput);
                    document.body.appendChild(form);
                    form.submit();
                };
                
                modal.classList.remove('hidden');
                return false;
            } else {
                // Called from the form submit
                const selectElement = document.getElementById('backup_file');
                if (!selectElement.value) {
                    alert('Please select a backup file to restore.');
                    return false;
                }
                
                nameSpan.textContent = selectElement.value;
                modal.classList.remove('hidden');
                
                confirmButton.onclick = function() {
                    document.getElementById('restoreForm').submit();
                };
                
                return false;
            }
        }
        
        // Delete confirmation
        function confirmDelete(filename) {
            const modal = document.getElementById('delete-modal');
            const nameSpan = document.getElementById('delete-backup-name');
            const confirmLink = document.getElementById('confirm-delete');
            
            nameSpan.textContent = filename;
            confirmLink.href = `backup.php?delete=${encodeURIComponent(filename)}`;
            modal.classList.remove('hidden');
            return false;
        }
        
        // Cancel buttons
        document.getElementById('cancel-restore').addEventListener('click', function() {
            document.getElementById('restore-modal').classList.add('hidden');
        });
        
        document.getElementById('cancel-delete').addEventListener('click', function() {
            document.getElementById('delete-modal').classList.add('hidden');
        });
        
        // Close modals when clicking outside
        document.getElementById('restore-modal').addEventListener('click', function(event) {
            if (event.target === this) {
                this.classList.add('hidden');
            }
        });
        
        document.getElementById('delete-modal').addEventListener('click', function(event) {
            if (event.target === this) {
                this.classList.add('hidden');
            }
        });
        
        // Escape key to close modals
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                document.getElementById('restore-modal').classList.add('hidden');
                document.getElementById('delete-modal').classList.add('hidden');
            }
        });
        
        // Highlight newly created backup
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($_GET['success'])): ?>
            const firstRow = document.querySelector('tbody tr:first-child');
            if (firstRow) {
                firstRow.classList.add('bg-yellow-900', 'bg-opacity-30');
                setTimeout(() => {
                    firstRow.classList.remove('bg-yellow-900', 'bg-opacity-30');
                }, 3000);
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>


