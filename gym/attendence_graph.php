<?php
include '../includes/navbar.php';
require_once '../config/database.php';

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

// Get date range filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$user_filter = isset($_GET['user_id']) ? $_GET['user_id'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Pagination variables
$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Build the query based on filters
$query = "
    SELECT s.*, u.username, u.email, u.profile_image
    FROM schedules s
    JOIN users u ON s.user_id = u.id
    WHERE s.gym_id = ?
    AND s.start_date BETWEEN ? AND ?
";

$params = [$gym_id, $start_date, $end_date];

if (!empty($user_filter)) {
    $query .= " AND s.user_id = ?";
    $params[] = $user_filter;
}

if ($status_filter !== 'all') {
    $query .= " AND s.status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY s.start_date DESC, s.start_time DESC";
$query .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $conn->prepare($query);
// Bind all parameters
for ($i = 0; $i < count($params); $i++) {
    $paramType = ($i >= count($params) - 2) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($i + 1, $params[$i], $paramType);
}
$stmt->execute();

$visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total visits count for pagination
$countQuery = "
    SELECT COUNT(*) as total
    FROM schedules s
    WHERE s.gym_id = ?
    AND s.start_date BETWEEN ? AND ?
";

$countParams = [$gym_id, $start_date, $end_date];

if (!empty($user_filter)) {
    $countQuery .= " AND s.user_id = ?";
    $countParams[] = $user_filter;
}

if ($status_filter !== 'all') {
    $countQuery .= " AND s.status = ?";
    $countParams[] = $status_filter;
}

$stmt = $conn->prepare($countQuery);
$stmt->execute($countParams);
$total_visits = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_visits / $limit);

// Get users for filter dropdown
$stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.username
    FROM schedules s
    JOIN users u ON s.user_id = u.id
    WHERE s.gym_id = ?
    ORDER BY u.username
");
$stmt->execute([$gym_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get attendance statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_visits,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_visits,
        COUNT(CASE WHEN status = 'missed' THEN 1 END) as missed_visits,
        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_visits,
        COUNT(CASE WHEN status = 'scheduled' THEN 1 END) as scheduled_visits,
        COUNT(DISTINCT user_id) as unique_visitors
    FROM schedules
    WHERE gym_id = ?
    AND start_date BETWEEN ? AND ?
");
$stmt->execute([$gym_id, $start_date, $end_date]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get daily visit counts for chart
$stmt = $conn->prepare("
    SELECT 
        start_date,
        COUNT(*) as visit_count,
        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_count,
        COUNT(CASE WHEN status = 'missed' THEN 1 END) as missed_count,
        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_count
    FROM schedules
    WHERE gym_id = ?
    AND start_date BETWEEN ? AND ?
    GROUP BY start_date
    ORDER BY start_date
");
$stmt->execute([$gym_id, $start_date, $end_date]);
$daily_visits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process check-in/check-out
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['check_in'])) {
        $schedule_id = $_POST['schedule_id'];
        
        // Update schedule with check-in time
        $stmt = $conn->prepare("
            UPDATE schedules 
            SET check_in_time = NOW(), status = 'completed'
            WHERE id = ? AND gym_id = ?
        ");
        $result = $stmt->execute([$schedule_id, $gym_id]);
        
        if ($result) {
            $_SESSION['success'] = "Check-in recorded successfully.";
        } else {
            $_SESSION['error'] = "Failed to record check-in.";
        }
    } elseif (isset($_POST['check_out'])) {
        $schedule_id = $_POST['schedule_id'];
        
        // Update schedule with check-out time
        $stmt = $conn->prepare("
            UPDATE schedules 
            SET check_out_time = NOW()
            WHERE id = ? AND gym_id = ?
        ");
        $result = $stmt->execute([$schedule_id, $gym_id]);
        
        if ($result) {
            $_SESSION['success'] = "Check-out recorded successfully.";
        } else {
            $_SESSION['error'] = "Failed to record check-out.";
        }
    } elseif (isset($_POST['mark_missed'])) {
        $schedule_id = $_POST['schedule_id'];
        
        // Mark schedule as missed
        $stmt = $conn->prepare("
            UPDATE schedules 
            SET status = 'missed'
            WHERE id = ? AND gym_id = ?
        ");
        $result = $stmt->execute([$schedule_id, $gym_id]);
        
        if ($result) {
            $_SESSION['success'] = "Visit marked as missed.";
        } else {
            $_SESSION['error'] = "Failed to update visit status.";
        }
    }
    
    // Redirect to refresh the page
    header("Location: visit_attendance.php?start_date=$start_date&end_date=$end_date&user_id=$user_filter&status=$status_filter&page=$page");
    exit();
}

?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Visit Attendance</h1>
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
    
    <!-- Attendance Statistics -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
        <h2 class="text-xl font-semibold mb-4">Attendance Overview</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-gray-700 rounded-lg p-4 text-center">
                <div class="text-3xl font-bold text-white mb-1"><?php echo $stats['total_visits']; ?></div>
                <div class="text-gray-400">Total Visits</div>
            </div>
            
            <div class="bg-gray-700 rounded-lg p-4 text-center">
                <div class="text-3xl font-bold text-green-400 mb-1"><?php echo $stats['completed_visits']; ?></div>
                <div class="text-gray-400">Completed</div>
            </div>
            
            <div class="bg-gray-700 rounded-lg p-4 text-center">
                <div class="text-3xl font-bold text-red-400 mb-1"><?php echo $stats['missed_visits']; ?></div>
                <div class="text-gray-400">Missed</div>
            </div>
            
            <div class="bg-gray-700 rounded-lg p-4 text-center">
                <div class="text-3xl font-bold text-blue-400 mb-1"><?php echo $stats['unique_visitors']; ?></div>
                <div class="text-gray-400">Unique Visitors</div>
            </div>
        </div>
        
        <!-- Attendance Chart -->
        <div class="bg-gray-700 rounded-lg p-4">
            <h3 class="text-lg font-medium mb-4">Daily Visits</h3>
            <canvas id="visitsChart" height="200"></canvas>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <div>
                <label class="block text-gray-300 text-sm font-medium mb-2">Start Date</label>
                <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-gray-300 text-sm font-medium mb-2">End Date</label>
                <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            
            <div>
                <label class="block text-gray-300 text-sm font-medium mb-2">Member</label>
                <select name="user_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="">All Members</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($user['username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-gray-300 text-sm font-medium mb-2">Status</label>
                <select name="status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="missed" <?php echo $status_filter === 'missed' ? 'selected' : ''; ?>>Missed</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            
            <div class="flex items-end">
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    Apply Filters
                </button>
            </div>
        </form>
    </div>
    
    <!-- Visits List -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
        <h2 class="text-xl font-semibold mb-4">Visit Records</h2>
        
        <?php if (empty($visits)): ?>
            <div class="bg-gray-700 rounded-lg p-6 text-center">
                <i class="fas fa-calendar-times text-gray-500 text-4xl mb-3"></i>
                <p class="text-gray-400">No visits found matching your filters.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead>
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Member</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Date & Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Check-In/Out</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-700">
                        <?php foreach ($visits as $visit): ?>
                            <tr>
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <?php if ($visit['profile_image']): ?>
                                            <img src="<?php echo '../' . $visit['profile_image']; ?>" alt="Profile" class="w-10 h-10 rounded-full object-cover mr-3">
                                        <?php else: ?>
                                            <div class="w-10 h-10 rounded-full bg-gray-600 flex items-center justify-center mr-3">
                                                <i class="fas fa-user text-gray-400"></i>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div>
                                            <div class="text-white font-medium"><?php echo htmlspecialchars($visit['username']); ?></div>
                                            <div class="text-gray-400 text-sm"><?php echo htmlspecialchars($visit['email']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-white"><?php echo date('M d, Y', strtotime($visit['start_date'])); ?></div>
                                    <div class="text-gray-400 text-sm"><?php echo date('g:i A', strtotime($visit['start_time'])); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($visit['check_in_time']): ?>
                                        <div class="text-green-400">
                                            <i class="fas fa-sign-in-alt mr-1"></i> 
                                            <?php echo date('g:i A', strtotime($visit['check_in_time'])); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-gray-400">Not checked in</div>
                                    <?php endif; ?>
                                    
                                    <?php if ($visit['check_out_time']): ?>
                                        <div class="text-red-400">
                                            <i class="fas fa-sign-out-alt mr-1"></i> 
                                            <?php echo date('g:i A', strtotime($visit['check_out_time'])); ?>
                                        </div>
                                    <?php elseif ($visit['check_in_time']): ?>
                                        <div class="text-gray-400">Not checked out</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php 
                                        if ($visit['status'] === 'completed') echo 'bg-green-100 text-green-800';
                                        elseif ($visit['status'] === 'scheduled') echo 'bg-blue-100 text-blue-800';
                                        elseif ($visit['status'] === 'missed') echo 'bg-red-100 text-red-800';
                                        else echo 'bg-gray-100 text-gray-800';
                                        ?>">
                                        <?php echo ucfirst($visit['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($visit['status'] === 'scheduled'): ?>
                                        <form method="POST" class="inline-block">
                                            <input type="hidden" name="schedule_id" value="<?php echo $visit['id']; ?>">
                                            <button type="submit" name="check_in" class="text-green-400 hover:text-green-300 mr-2">
                                                <i class="fas fa-sign-in-alt mr-1"></i> Check In
                                            </button>
                                        </form>
                                        <form method="POST" class="inline-block">
                                            <input type="hidden" name="schedule_id" value="<?php echo $visit['id']; ?>">
                                            <button type="submit" name="mark_missed" class="text-red-400 hover:text-red-300">
                                                <i class="fas fa-times-circle mr-1"></i> Mark Missed
                                            </button>
                                        </form>
                                    <?php elseif ($visit['status'] === 'completed' && !$visit['check_out_time']): ?>
                                        <form method="POST">
                                            <input type="hidden" name="schedule_id" value="<?php echo $visit['id']; ?>">
                                            <button type="submit" name="check_out" class="text-red-400 hover:text-red-300">
                                                <i class="fas fa-sign-out-alt mr-1"></i> Check Out
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-gray-400">No actions available</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="flex justify-center mt-6">
                    <div class="flex space-x-1">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&user_id=<?php echo $user_filter; ?>&status=<?php echo $status_filter; ?>" class="px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&user_id=<?php echo $user_filter; ?>&status=<?php echo $status_filter; ?>" class="px-4 py-2 <?php echo $i === $page ? 'bg-blue-500 text-white' : 'bg-gray-700 text-white hover:bg-gray-600'; ?> rounded-lg">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&user_id=<?php echo $user_filter; ?>&status=<?php echo $status_filter; ?>" class="px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Include Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Prepare data for chart
        const dates = <?php echo json_encode(array_column($daily_visits, 'start_date')); ?>;
        const completedCounts = <?php echo json_encode(array_column($daily_visits, 'completed_count')); ?>;
        const missedCounts = <?php echo json_encode(array_column($daily_visits, 'missed_count')); ?>;
        const cancelledCounts = <?php echo json_encode(array_column($daily_visits, 'cancelled_count')); ?>;
        
        // Create chart
        const ctx = document.getElementById('visitsChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: dates,
                datasets: [
                    {
                        label: 'Completed',
                        data: completedCounts,
                        backgroundColor: 'rgba(72, 187, 120, 0.7)',
                        borderColor: 'rgba(72, 187, 120, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Missed',
                        data: missedCounts,
                        backgroundColor: 'rgba(245, 101, 101, 0.7)',
                        borderColor: 'rgba(245, 101, 101, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Cancelled',
                        data: cancelledCounts,
                        backgroundColor: 'rgba(160, 174, 192, 0.7)',
                        borderColor: 'rgba(160, 174, 192, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)'
                        }
                    },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        },
                        ticks: {
                            color: 'rgba(255, 255, 255, 0.7)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        labels: {
                            color: 'rgba(255, 255, 255, 0.7)'
                        }
                    }
                }
            }
        });
    });
</script>

<?php include '../includes/footer.php'; ?>

