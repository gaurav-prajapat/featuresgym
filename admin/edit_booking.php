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

// Get booking ID from URL
$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$booking_id) {
    $_SESSION['error'] = "Invalid booking ID.";
    header('Location: all_bookings.php');
    exit();
}

// Fetch booking details
try {
    $stmt = $conn->prepare("
        SELECT s.*, 
               u.username, u.email,
               g.name as gym_name
        FROM schedules s
        JOIN users u ON s.user_id = u.id
        JOIN gyms g ON s.gym_id = g.gym_id
        WHERE s.id = ?
    ");
    $stmt->execute([$booking_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        $_SESSION['error'] = "Booking not found.";
        header('Location: all_bookings.php');
        exit();
    }
    
    // Get all gyms for dropdown
    $stmt = $conn->prepare("SELECT gym_id, name, city FROM gyms WHERE status = 'active' ORDER BY name");
    $stmt->execute();
    $gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all users for dropdown
    $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE status = 'active' ORDER BY username");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header('Location: all_bookings.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $gym_id = isset($_POST['gym_id']) ? (int)$_POST['gym_id'] : 0;
    $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : '';
    $start_time = isset($_POST['start_time']) ? $_POST['start_time'] : '';
    $status = isset($_POST['status']) ? $_POST['status'] : '';
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
    
    if (empty($status) || !in_array($status, ['scheduled', 'completed', 'cancelled', 'missed'])) {
        $errors[] = "Valid status is required.";
    }
    
    if (empty($errors)) {
        try {
            $conn->beginTransaction();
            
            // Update booking
            $stmt = $conn->prepare("
                UPDATE schedules 
                SET user_id = ?, gym_id = ?, start_date = ?, start_time = ?, status = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$user_id, $gym_id, $start_date, $start_time, $status, $notes, $booking_id]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO schedule_logs (
                    user_id, schedule_id, action_type, old_gym_id, new_gym_id, old_time, new_time, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $_SESSION['user_id'],
                $booking_id,
                'update',
                $booking['gym_id'],
                $gym_id,
                $booking['start_time'],
                $start_time,
                "Admin updated booking details"
            ]);
            
            // Log in activity_logs
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Updated booking ID: $booking_id for user ID: $user_id at gym ID: $gym_id";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "update_booking", $details, $ip, $user_agent]);
            
            $conn->commit();
            
            $_SESSION['success'] = "Booking updated successfully.";
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
    <title>Edit Booking - FlexFit Admin</title>
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
                <a href="view_booking.php?id=<?= $booking_id ?>" class="mr-4 text-gray-400 hover:text-white">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <h1 class="text-2xl font-bold">Edit Booking</h1>
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
                                    <option value="<?= $user['id'] ?>" <?= $booking['user_id'] == $user['id'] ? 'selected' : '' ?>>
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
                                    <option value="<?= $gym['gym_id'] ?>" <?= $booking['gym_id'] == $gym['gym_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($gym['name']) ?> (<?= htmlspecialchars($gym['city']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-400 mb-1">Date</label>
                            <input type="text" id="start_date" name="start_date" value="<?= htmlspecialchars($booking['start_date']) ?>" required
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 datepicker">
                        </div>
                        
                        <div>
                            <label for="start_time" class="block text-sm font-medium text-gray-400 mb-1">Time</label>
                            <input type="text" id="start_time" name="start_time" value="<?= htmlspecialchars($booking['start_time']) ?>" required
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500 timepicker">
                        </div>
                        
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-400 mb-1">Status</label>
                            <select id="status" name="status" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500">
                                <option value="scheduled" <?= $booking['status'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
                                <option value="completed" <?= $booking['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                <option value="cancelled" <?= $booking['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                <option value="missed" <?= $booking['status'] === 'missed' ? 'selected' : '' ?>>Missed</option>
                            </select>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label for="notes" class="block text-sm font-medium text-gray-400 mb-1">Notes</label>
                            <textarea id="notes" name="notes" rows="4" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-blue-500"><?= htmlspecialchars($booking['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <a href="view_booking.php?id=<?= $booking_id ?>" class="bg-gray-600 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded-lg mr-2">
                            Cancel
                        </a>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg">
                        <i class="fas fa-save mr-2"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Initialize date picker
        flatpickr(".datepicker", {
            dateFormat: "Y-m-d",
            altInput: true,
            altFormat: "F j, Y",
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
    </script>
</body>
</html>

