<?php
ob_start();
include '../includes/navbar.php';
require_once '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get gym ID
$stmt = $conn->prepare("SELECT gym_id FROM gyms WHERE owner_id = :owner_id");
$stmt->bindParam(':owner_id', $_SESSION['owner_id']);
$stmt->execute();
$gym = $stmt->fetch(PDO::FETCH_ASSOC);
$gym_id = $gym['gym_id'];

// Check if class ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: manage_classes.php');
    exit;
}

$class_id = $_GET['id'];

// Verify the class belongs to this gym
$stmt = $conn->prepare("SELECT * FROM gym_classes WHERE id = :class_id AND gym_id = :gym_id");
$stmt->execute([
    ':class_id' => $class_id,
    ':gym_id' => $gym_id
]);
$class = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    header('Location: manage_classes.php');
    exit;
}

// Process cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Update class status to cancelled
        $stmt = $conn->prepare("UPDATE gym_classes SET status = 'cancelled' WHERE id = :class_id");
        $stmt->execute([':class_id' => $class_id]);
        
        // Get all bookings for this class
        $stmt = $conn->prepare("SELECT * FROM class_bookings WHERE class_id = :class_id AND status = 'booked'");
        $stmt->execute([':class_id' => $class_id]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Update all bookings to cancelled
        $stmt = $conn->prepare("UPDATE class_bookings SET status = 'cancelled' WHERE class_id = :class_id AND status = 'booked'");
        $stmt->execute([':class_id' => $class_id]);
        
        // Send notifications to all affected users
        foreach ($bookings as $booking) {
            $user_id = $booking['user_id'];
            
            // Create notification
            $stmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, message, related_id, created_at, is_read)
                VALUES (:user_id, 'class_cancelled', :message, :class_id, NOW(), 0)
            ");
            
            $message = "Your booking for '{$class['name']}' class has been cancelled by the gym.";
            $stmt->execute([
                ':user_id' => $user_id,
                ':message' => $message,
                ':class_id' => $class_id
            ]);
        }
        
        // Commit transaction
        $conn->commit();
        
        header('Location: manage_classes.php?cancelled=1');
        exit;
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        $error = "An error occurred: " . $e->getMessage();
    }
}
?>

<div class="container mx-auto px-4 py-20">
    <div class="max-w-lg mx-auto bg-gray-800 rounded-lg shadow-lg p-6 text-white">
        <h1 class="text-2xl font-bold mb-6">Cancel Class</h1>
        
        <?php if (isset($error)): ?>
            <div class="bg-red-800 border border-red-600 text-red-100 px-4 py-3 rounded mb-4">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <div class="mb-6">
            <h2 class="text-xl font-semibold mb-2"><?php echo htmlspecialchars($class['name']); ?></h2>
            <p class="text-gray-300 mb-4">Instructor: <?php echo htmlspecialchars($class['instructor']); ?></p>
            
            <div class="bg-red-900 bg-opacity-50 border border-red-700 rounded-lg p-4 mb-4">
                <p class="text-white">
                    <i class="fas fa-exclamation-triangle text-red-400 mr-2"></i>
                    Warning: Cancelling this class will notify all members who have booked it and mark their bookings as cancelled.
                </p>
            </div>
            
            <p class="text-gray-300 mb-2">Are you sure you want to cancel this class?</p>
        </div>
        
        <div class="flex justify-end space-x-4">
            <a href="manage_classes.php" 
               class="bg-gray-600 text-white px-6 py-3 rounded-full font-bold text-center
                      hover:bg-gray-700 transform hover:scale-105 transition-all duration-300">
                No, Go Back
            </a>
            <form method="POST" class="inline">
                <button type="submit" 
                        class="bg-red-500 text-white px-6 py-3 rounded-full font-bold text-center
                               hover:bg-red-600 transform hover:scale-105 transition-all duration-300">
                    Yes, Cancel Class
                </button>
            </form>
        </div>
    </div>
</div>
