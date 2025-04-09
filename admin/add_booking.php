<?php
ob_start();
require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /profitmarts/FlexFit/views/auth/login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get all active gyms
try {
    $stmt = $conn->prepare("SELECT gym_id, name, city FROM gyms WHERE status = 'active' ORDER BY name");
    $stmt->execute();
    $gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $gyms = [];
    $error_message = "Error fetching gyms: " . $e->getMessage();
}

// Get all active users
try {
    $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE status = 'active' ORDER BY username");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
    $error_message = "Error fetching users: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $gym_id = isset($_POST['gym_id']) ? (int)$_POST['gym_id'] : 0;
    $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
    $start_time = isset($_POST['start_time']) ? $_POST['start_time'] : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    // Validate inputs
    $errors = [];
    
    if (empty($user_id)) {
        $errors[] = "Member is required.";
    }
    
    if (empty($gym_id)) {
        $errors[] = "Gym is required.";
    }
    
    if (empty($start_date)) {
        $errors[] = "Date is required.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
        $errors[] = "Invalid date format. Use YYYY-MM-DD.";
    }
    
    if (empty($start_time)) {
        $errors[] = "Time is required.";
    } elseif (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time)) {
        $errors[] = "Invalid time format. Use HH:MM or HH:MM:SS.";
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Check if user has an active membership for this gym
            $stmt = $conn->prepare("
                SELECT id, plan_id FROM user_memberships 
                WHERE user_id = ? AND gym_id = ? AND status = 'active' 
                AND start_date <= ? AND end_date >= ?
            ");
            $stmt->execute([$user_id, $gym_id, $start_date, $start_date]);
            $membership = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $membership_id = null;
            $daily_rate = 0;
            
            if ($membership) {
                $membership_id = $membership['id'];
                
                // Get plan details for daily rate
                $stmt = $conn->prepare("
                    SELECT price, duration FROM gym_membership_plans 
                    WHERE plan_id = ?
                ");
                $stmt->execute([$membership['plan_id']]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($plan) {
                    // Calculate daily rate based on plan
                    switch ($plan['duration']) {
                        case 'Daily':
                            $daily_rate = $plan['price'];
                            break;
                        case 'Weekly':
                            $daily_rate = $plan['price'] / 7;
                            break;
                        case 'Monthly':
                            $daily_rate = $plan['price'] / 30;
                            break;
                        case 'Quarterly':
                            $daily_rate = $plan['price'] / 90;
                            break;
                        case 'Half Yearly':
                            $daily_rate = $plan['price'] / 180;
                            break;
                        case 'Yearly':
                            $daily_rate = $plan['price'] / 365;
                            break;
                        default:
                            $daily_rate = 0;
                    }
                }
            } else {
                // Get pay-per-visit rate for this gym
                $stmt = $conn->prepare("
                    SELECT price FROM gym_membership_plans 
                    WHERE gym_id = ? AND duration = 'Daily' 
                    ORDER BY price ASC LIMIT 1
                ");
                $stmt->execute([$gym_id]);
                $daily_plan = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($daily_plan) {
                    $daily_rate = $daily_plan['price'];
                }
            }
            
            // Create booking
            $stmt = $conn->prepare("
                INSERT INTO schedules (
                    user_id, gym_id, membership_id, activity_type, 
                    start_date, end_date, start_time, status, notes, daily_rate
                ) VALUES (
                    ?, ?, ?, 'gym_visit', ?, ?, ?, 'scheduled', ?, ?
                )
            ");
            
            $stmt->execute([
                $user_id,
                $gym_id,
                $membership_id,
                $start_date,
                $start_date,
                $start_time,
                $notes,
                $daily_rate
            ]);
            
            $booking_id = $conn->lastInsertId();
            
            // Log the activity in schedule_logs
            $stmt = $conn->prepare("
                INSERT INTO schedule_logs (
                    user_id, schedule_id, action_type, notes
                ) VALUES (?, ?, 'create', ?)
            ");
            
            $stmt->execute([
                $_SESSION['user_id'],
                $booking_id,
                "Booking created by admin"
            ]);
            
            // Log in activity_logs
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Created booking ID: $booking_id for user ID: $user_id at gym ID: $gym_id";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "create_booking", $details, $ip, $user_agent]);
            
            $conn->commit();
            
            $_SESSION['success'] = "Booking created successfully.";
            header("Location: view_booking.php?id=$booking_id");
            exit();
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Booking - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-0 lg:ml-64 p-4 sm:p-6 transition-all duration-200">
        <div class="flex justify-between items-center mb-6">
            <div class="flex items-center">
                <a href="all_bookings.php" class="mr-4 text-gray-400 hover:text-white">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="text-2xl font-bold">Add New Booking</h1>
            </div>
        </div>
        
        <?php if (isset($error_message)): ?>
        <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
            <p><?= $error_message ?></p>
        </div>
        <?php endif; ?>
        
        <div class="bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6">
                <form method="POST" action="">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="user_id" class="block text-sm font-medium text-gray-400 mb-1">Member</label>
                            <select id="user_id" name="user_id" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                <option value="">Select Member</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>" <?= isset($_POST['user_id']) && $_POST['user_id'] == $user['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars($user['email']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="gym_id" class="block text-sm font-medium text-gray-400 mb-1">Gym</label>
                            <select id="gym_id" name="gym_id" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                <option value="">Select Gym</option>
                                <?php foreach ($gyms as $gym): ?>
                                    <option value="<?= $gym['gym_id'] ?>" <?= isset($_POST['gym_id']) && $_POST['gym_id'] == $gym['gym_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($gym['name']) ?> (<?= htmlspecialchars($gym['city']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-400 mb-1">Date</label>
                            <input type="text" id="start_date" name="start_date" value="<?= htmlspecialchars($_POST['start_date'] ?? '') ?>" required
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 datepicker">
                        </div>
                        
                        <div>
                            <label for="start_time" class="block text-sm font-medium text-gray-400 mb-1">Time</label>
                            <input type="text" id="start_time" name="start_time" value="<?= htmlspecialchars($_POST['start_time'] ?? '') ?>" required
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 timepicker">
                        </div>
                        
                        <div class="md:col-span-2">
                            <label for="notes" class="block text-sm font-medium text-gray-400 mb-1">Notes</label>
                            <textarea id="notes" name="notes" rows="4" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <a href="all_bookings.php" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                            Cancel
                        </a>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-plus mr-2"></i> Create Booking
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="mt-8 bg-gray-800 rounded-xl shadow-lg overflow-hidden">
            <div class="p-6 border-b border-gray-700">
                <h2 class="text-xl font-semibold">Booking Information</h2>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-lg font-medium mb-4">Membership Status</h3>
                        <div id="membership-status" class="bg-gray-700 rounded-lg p-4">
                            <p class="text-gray-400">Select a member and gym to see membership status</p>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-lg font-medium mb-4">Gym Operating Hours</h3>
                        <div id="operating-hours" class="bg-gray-700 rounded-lg p-4">
                            <p class="text-gray-400">Select a gym to see operating hours</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize date picker
        flatpickr(".datepicker", {
            dateFormat: "Y-m-d",
            altInput: true,
            altFormat: "F j, Y",
            minDate: "today",
            theme: "dark"
        });
        
        // Initialize time picker
        flatpickr(".timepicker", {
            enableTime: true,
            noCalendar: true,
            dateFormat: "H:i:S",
            time_24hr: true,
            theme: "dark"
        });
        
        // Check membership status when user and gym are selected
        document.getElementById('user_id').addEventListener('change', checkMembership);
        document.getElementById('gym_id').addEventListener('change', checkMembership);
        document.getElementById('gym_id').addEventListener('change', getOperatingHours);
        
        function checkMembership() {
            const userId = document.getElementById('user_id').value;
            const gymId = document.getElementById('gym_id').value;
            const membershipStatus = document.getElementById('membership-status');
            
            if (userId && gymId) {
                membershipStatus.innerHTML = '<p class="text-gray-400">Loading membership information...</p>';
                
                fetch(`/profitmarts/FlexFit/admin/api/check_membership.php?user_id=${userId}&gym_id=${gymId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.has_membership) {
                            membershipStatus.innerHTML = `
                                <div class="flex items-center">
                                    <div class="rounded-full bg-green-900 p-2 mr-3">
                                        <i class="fas fa-check text-green-400"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white">Active Membership</p>
                                        <p class="text-sm text-gray-400">${data.plan_name}</p>
                                        <p class="text-sm text-gray-400">Valid until: ${data.end_date}</p>
                                    </div>
                                </div>
                            `;
                        } else {
                            membershipStatus.innerHTML = `
                                <div class="flex items-center">
                                    <div class="rounded-full bg-yellow-900 p-2 mr-3">
                                        <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white">No Active Membership</p>
                                        <p class="text-sm text-gray-400">Member will be charged the pay-per-visit rate.</p>
                                        <p class="text-sm text-gray-400">Rate: ₹${data.daily_rate}</p>
                                    </div>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        membershipStatus.innerHTML = `
                            <div class="flex items-center">
                                <div class="rounded-full bg-red-900 p-2 mr-3">
                                    <i class="fas fa-times text-red-400"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-white">Error</p>
                                    <p class="text-sm text-gray-400">Could not check membership status.</p>
                                </div>
                            </div>
                        `;
                        console.error('Error checking membership:', error);
                    });
            } else {
                membershipStatus.innerHTML = '<p class="text-gray-400">Select a member and gym to see membership status</p>';
            }
        }
        
        function getOperatingHours() {
            const gymId = document.getElementById('gym_id').value;
            const operatingHours = document.getElementById('operating-hours');
            
            if (gymId) {
                operatingHours.innerHTML = '<p class="text-gray-400">Loading operating hours...</p>';
                
                fetch(`/profitmarts/FlexFit/admin/api/get_operating_hours.php?gym_id=${gymId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            let hoursHtml = '<div class="space-y-2">';
                            
                            data.hours.forEach(hour => {
                                hoursHtml += `
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">${hour.day}</span>
                                        <span class="text-white">${hour.morning_open_time} - ${hour.morning_close_time}, ${hour.evening_open_time} - ${hour.evening_close_time}</span>
                                    </div>
                                `;
                            });
                            
                            hoursHtml += '</div>';
                            operatingHours.innerHTML = hoursHtml;
                        } else {
                            operatingHours.innerHTML = `
                                <div class="flex items-center">
                                    <div class="rounded-full bg-yellow-900 p-2 mr-3">
                                        <i class="fas fa-clock text-yellow-400"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-white">No Operating Hours Set</p>
                                        <p class="text-sm text-gray-400">Please check with the gym directly.</p>
                                    </div>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        operatingHours.innerHTML = `
                            <div class="flex items-center">
                                <div class="rounded-full bg-red-900 p-2 mr-3">
                                    <i class="fas fa-times text-red-400"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-white">Error</p>
                                    <p class="text-sm text-gray-400">Could not fetch operating hours.</p>
                                </div>
                            </div>
                        `;
                        console.error('Error fetching operating hours:', error);
                    });
            } else {
                operatingHours.innerHTML = '<p class="text-gray-400">Select a gym to see operating hours</p>';
            }
        }
    </script>
</body>
</html>

