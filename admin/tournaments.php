<?php
session_start();
require_once '../config/database.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize messages
$success_message = '';
$error_message = '';

// Handle tournament status updates
if (isset($_POST['update_status']) && isset($_POST['tournament_id']) && isset($_POST['new_status'])) {
    $tournament_id = $_POST['tournament_id'];
    $new_status = $_POST['new_status'];
    
    try {
        $stmt = $conn->prepare("UPDATE gym_tournaments SET status = ? WHERE id = ?");
        $stmt->execute([$new_status, $tournament_id]);
        
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'admin', 'update_tournament_status', ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['admin_id'],
            "Updated tournament ID $tournament_id status to $new_status",
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        $success_message = "Tournament status updated successfully!";
    } catch (PDOException $e) {
        $error_message = "Error updating tournament status: " . $e->getMessage();
    }
}

// Handle tournament deletion
if (isset($_POST['delete_tournament']) && isset($_POST['tournament_id'])) {
    $tournament_id = $_POST['tournament_id'];
    
    try {
        // Begin transaction
        $conn->beginTransaction();
        
        // Delete tournament participants
        $stmt = $conn->prepare("DELETE FROM tournament_participants WHERE tournament_id = ?");
        $stmt->execute([$tournament_id]);
        
        // Delete tournament results
        $stmt = $conn->prepare("DELETE FROM tournament_results WHERE tournament_id = ?");
        $stmt->execute([$tournament_id]);
        
        // Delete the tournament
        $stmt = $conn->prepare("DELETE FROM gym_tournaments WHERE id = ?");
        $stmt->execute([$tournament_id]);
        
        // Commit transaction
        $conn->commit();
        
        // Log the activity
        $stmt = $conn->prepare("
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (?, 'admin', 'delete_tournament', ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['admin_id'],
            "Deleted tournament ID $tournament_id",
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        $success_message = "Tournament deleted successfully!";
    } catch (PDOException $e) {
        // Rollback transaction on error
        $conn->rollBack();
        $error_message = "Error deleting tournament: " . $e->getMessage();
    }
}

// Fetch tournaments with gym information
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$gym_filter = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

$query = "
    SELECT t.*, g.name as gym_name, g.city, g.state,
           COUNT(tp.id) as participant_count
    FROM gym_tournaments t
    JOIN gyms g ON t.gym_id = g.gym_id
    LEFT JOIN tournament_participants tp ON t.id = tp.tournament_id
";

$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(t.title LIKE ? OR g.name LIKE ? OR t.description LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

if (!empty($status_filter)) {
    $where_conditions[] = "t.status = ?";
    $params[] = $status_filter;
}

if ($gym_filter > 0) {
    $where_conditions[] = "t.gym_id = ?";
    $params[] = $gym_filter;
}

if (!empty($where_conditions)) {
    $query .= " WHERE " . implode(" AND ", $where_conditions);
}

$query .= " GROUP BY t.id ORDER BY t.start_date DESC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all gyms for filter dropdown
$stmt = $conn->prepare("SELECT gym_id, name, city FROM gyms ORDER BY name");
$stmt->execute();
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Tournament Management";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Gyms - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-white"><?= htmlspecialchars($page_title) ?></h1>
        <nav class="text-gray-400 text-sm">
            <a href="dashboard.php" class="hover:text-white">Dashboard</a>
            <span class="mx-2">/</span>
            <span class="text-gray-300">Tournaments</span>
        </nav>
    </div>

    <?php if ($success_message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($success_message) ?></span>
            <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3" onclick="this.parentElement.style.display='none'">
                <svg class="fill-current h-6 w-6 text-green-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                    <title>Close</title>
                    <path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/>
                </svg>
            </button>
        </div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($error_message) ?></span>
            <button type="button" class="absolute top-0 bottom-0 right-0 px-4 py-3" onclick="this.parentElement.style.display='none'">
                <svg class="fill-current h-6 w-6 text-red-500" role="button" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                    <title>Close</title>
                    <path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/>
                </svg>
            </button>
        </div>
    <?php endif; ?>

    <!-- Filters and Search -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
        <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label for="search" class="block text-gray-300 mb-2">Search</label>
                <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>"
                       class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                       placeholder="Search tournaments...">
            </div>
            
            <div>
                <label for="status" class="block text-gray-300 mb-2">Status</label>
                <select id="status" name="status" 
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Statuses</option>
                    <option value="upcoming" <?= $status_filter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                    <option value="ongoing" <?= $status_filter === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                    <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            
            <div>
                <label for="gym_id" class="block text-gray-300 mb-2">Gym</label>
                <select id="gym_id" name="gym_id" 
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Gyms</option>
                    <?php foreach ($gyms as $gym): ?>
                        <option value="<?= $gym['gym_id'] ?>" <?= $gym_filter === (int)$gym['gym_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($gym['name']) ?> (<?= htmlspecialchars($gym['city']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="flex items-end">
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                    <i class="fas fa-search mr-2"></i> Filter
                </button>
                <a href="tournaments.php" class="ml-2 px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg transition-colors duration-200">
                    <i class="fas fa-sync-alt mr-2"></i> Reset
                </a>
            </div>
        </form>
    </div>

    <!-- Tournaments Table -->
    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <div class="p-6 border-b border-gray-700">
            <h2 class="text-xl font-semibold text-white">All Tournaments</h2>
            <p class="text-gray-400 mt-1">Manage tournaments across all gyms</p>
        </div>
        
        <?php if (empty($tournaments)): ?>
            <div class="p-6 text-center text-gray-400">
                <i class="fas fa-trophy text-4xl mb-4"></i>
                <p>No tournaments found. Try adjusting your filters.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead class="bg-gray-700">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                Tournament
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                Gym
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                Dates
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                Participants
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                Status
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-gray-800 divide-y divide-gray-700">
                        <?php foreach ($tournaments as $tournament): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 bg-gray-700 rounded-full flex items-center justify-center">
                                            <i class="fas fa-trophy text-yellow-500"></i>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-white"><?= htmlspecialchars($tournament['title']) ?></div>
                                            <div class="text-sm text-gray-400"><?= htmlspecialchars($tournament['tournament_type']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-white"><?= htmlspecialchars($tournament['gym_name']) ?></div>
                                    <div class="text-sm text-gray-400"><?= htmlspecialchars($tournament['city']) ?>, <?= htmlspecialchars($tournament['state']) ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-white">
                                        <?= date('M d, Y', strtotime($tournament['start_date'])) ?> - 
                                        <?= date('M d, Y', strtotime($tournament['end_date'])) ?>
                                    </div>
                                    <div class="text-sm text-gray-400">
                                        Registration deadline: <?= date('M d, Y', strtotime($tournament['registration_deadline'])) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="text-sm text-white"><?= $tournament['participant_count'] ?> / <?= $tournament['max_participants'] ?></div>
                                    <div class="text-sm text-gray-400">
                                        <div class="w-full bg-gray-700 rounded-full h-2.5 mt-1">
                                            <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?= min(100, ($tournament['participant_count'] / $tournament['max_participants']) * 100) ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $statusClasses = [
                                        'upcoming' => 'bg-blue-900 text-blue-300',
                                        'ongoing' => 'bg-green-900 text-green-300',
                                        'completed' => 'bg-purple-900 text-purple-300',
                                        'cancelled' => 'bg-red-900 text-red-300'
                                    ];
                                    $statusClass = $statusClasses[$tournament['status']] ?? 'bg-gray-700 text-gray-300';
                                    ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                        <?= ucfirst($tournament['status']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <a href="view_tournament.php?id=<?= $tournament['id'] ?>" class="text-blue-400 hover:text-blue-300" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <button type="button" class="text-yellow-400 hover:text-yellow-300 change-status-btn" 
                                                data-tournament-id="<?= $tournament['id'] ?>" 
                                                data-current-status="<?= $tournament['status'] ?>"
                                                title="Change Status">
                                            <i class="fas fa-exchange-alt"></i>
                                        </button>
                                        
                                        <button type="button" class="text-red-400 hover:text-red-300 delete-tournament-btn" 
                                                data-tournament-id="<?= $tournament['id'] ?>"
                                                data-tournament-title="<?= htmlspecialchars($tournament['title']) ?>"
                                                title="Delete Tournament">
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

<!-- Change Status Modal -->
<div id="changeStatusModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 max-w-md w-full mx-4">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-semibold text-white">Change Tournament Status</h3>
            <button type="button" id="closeStatusModal" class="text-gray-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="changeStatusForm" method="POST" action="">
            <input type="hidden" name="tournament_id" id="status_tournament_id">
            <input type="hidden" name="update_status" value="1">
            
            <div class="mb-4">
                <label for="new_status" class="block text-gray-300 mb-2">New Status</label>
                <select id="new_status" name="new_status" 
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="upcoming">Upcoming</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            
            <div class="flex justify-end">
                <button type="button" id="cancelStatusChange" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg mr-3 transition-colors duration-200">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                    <i class="fas fa-save mr-2"></i> Update Status
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Tournament Modal -->
<div id="deleteTournamentModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 max-w-md w-full mx-4">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-xl font-semibold text-white">Delete Tournament</h3>
            <button type="button" id="closeDeleteModal" class="text-gray-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="mb-6">
            <p class="text-gray-300 mb-4">
                Are you sure you want to delete the tournament: <span id="deleteTournamentName" class="font-semibold text-white"></span>?
            </p>
            <p class="text-red-400 text-sm">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                This action cannot be undone. All participant data and results will be permanently deleted.
            </p>
        </div>
        
        <form id="deleteTournamentForm" method="POST" action="">
            <input type="hidden" name="tournament_id" id="delete_tournament_id">
            <input type="hidden" name="delete_tournament" value="1">
            
            <div class="flex justify-end">
                <button type="button" id="cancelDelete" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-white rounded-lg mr-3 transition-colors duration-200">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors duration-200">
                    <i class="fas fa-trash-alt mr-2"></i> Delete Tournament
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Change Status Modal
    const changeStatusBtns = document.querySelectorAll('.change-status-btn');
    const changeStatusModal = document.getElementById('changeStatusModal');
    const closeStatusModal = document.getElementById('closeStatusModal');
    const cancelStatusChange = document.getElementById('cancelStatusChange');
    const statusTournamentId = document.getElementById('status_tournament_id');
    const newStatusSelect = document.getElementById('new_status');
    
    changeStatusBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tournamentId = this.getAttribute('data-tournament-id');
            const currentStatus = this.getAttribute('data-current-status');
            
            statusTournamentId.value = tournamentId;
            newStatusSelect.value = currentStatus;
            
            changeStatusModal.classList.remove('hidden');
        });
    });
    
    if (closeStatusModal) {
        closeStatusModal.addEventListener('click', function() {
            changeStatusModal.classList.add('hidden');
        });
    }
    
    if (cancelStatusChange) {
        cancelStatusChange.addEventListener('click', function() {
            changeStatusModal.classList.add('hidden');
        });
    }
    
    // Delete Tournament Modal
    const deleteTournamentBtns = document.querySelectorAll('.delete-tournament-btn');
    const deleteTournamentModal = document.getElementById('deleteTournamentModal');
    const closeDeleteModal = document.getElementById('closeDeleteModal');
    const cancelDelete = document.getElementById('cancelDelete');
    const deleteTournamentId = document.getElementById('delete_tournament_id');
    const deleteTournamentName = document.getElementById('deleteTournamentName');
    
    deleteTournamentBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const tournamentId = this.getAttribute('data-tournament-id');
            const tournamentTitle = this.getAttribute('data-tournament-title');
            
            deleteTournamentId.value = tournamentId;
            deleteTournamentName.textContent = tournamentTitle;
            
            deleteTournamentModal.classList.remove('hidden');
        });
    });
    
    if (closeDeleteModal) {
        closeDeleteModal.addEventListener('click', function() {
            deleteTournamentModal.classList.add('hidden');
        });
    }
    
    if (cancelDelete) {
        cancelDelete.addEventListener('click', function() {
            deleteTournamentModal.classList.add('hidden');
        });
    }
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        if (event.target === changeStatusModal) {
            changeStatusModal.classList.add('hidden');
        }
        if (event.target === deleteTournamentModal) {
            deleteTournamentModal.classList.add('hidden');
        }
    });
    
    // Auto-hide alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.bg-green-100, .bg-red-100');
        alerts.forEach(function(alert) {
            alert.style.display = 'none';
        });
    }, 5000);
    
    // Auto-submit form when filters change
    document.querySelectorAll('select[name="status"], select[name="gym_id"]').forEach(select => {
        select.addEventListener('change', function() {
            this.form.submit();
        });
    });
</script>
</body>
</html>

