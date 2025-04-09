<?php
ob_start();
include 'includes/navbar.php';
require_once 'config/database.php';


if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

// Fetch available classes
$stmt = $conn->prepare("
    SELECT c.*, g.name as gym_name, 
           (SELECT COUNT(*) FROM class_bookings WHERE class_id = c.id) as current_bookings 
    FROM gym_classes c 
    JOIN gyms g ON c.gym_id = g.gym_id 
    WHERE c.status = 'active' 
    AND c.capacity > (SELECT COUNT(*) FROM class_bookings WHERE class_id = c.id)
");
$stmt->execute();
$available_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['class_id'])) {
    $class_id = $_POST['class_id'];
    
    // Check if already booked
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM class_bookings 
        WHERE user_id = :user_id AND class_id = :class_id
    ");
    $stmt->execute([
        ':user_id' => $user_id,
        ':class_id' => $class_id
    ]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing['count'] == 0) {
        $stmt = $conn->prepare("
            INSERT INTO class_bookings (class_id, user_id, booking_date, status) 
            VALUES (:class_id, :user_id, CURRENT_DATE(), 'booked')
        ");
        if ($stmt->execute([
            ':class_id' => $class_id,
            ':user_id' => $user_id
        ])) {
            $_SESSION['success'] = "Class booked successfully!";
        } else {
            $_SESSION['error'] = "Failed to book class.";
        }
    } else {
        $_SESSION['error'] = "You have already booked this class.";
    }
    
    header('Location: book_class.php');
    exit;
}

?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-8">Available Classes</h1>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
            <?php 
            echo $_SESSION['success'];
            unset($_SESSION['success']);
            ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
            <?php 
            echo $_SESSION['error'];
            unset($_SESSION['error']);
            ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($available_classes as $class): ?>
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-bold mb-2"><?php echo htmlspecialchars($class['name']); ?></h2>
                <p class="text-gray-600 mb-4"><?php echo htmlspecialchars($class['gym_name']); ?></p>
                
                <div class="mb-4">
                    <p class="text-sm text-gray-600">Instructor: <?php echo htmlspecialchars($class['instructor']); ?></p>
                    <p class="text-sm text-gray-600">Duration: <?php echo $class['duration_minutes']; ?> minutes</p>
                    <p class="text-sm text-gray-600">Level: <?php echo ucfirst($class['difficulty_level']); ?></p>
                </div>

                <div class="mb-4">
                    <div class="w-full bg-gray-200 rounded-full h-2">
                        <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo ($class['current_bookings'] / $class['capacity']) * 100; ?>%"></div>
                    </div>
                    <p class="text-sm text-gray-600 mt-1">
                        <?php echo $class['current_bookings']; ?>/<?php echo $class['capacity']; ?> spots filled
                    </p>
                </div>

                <form method="POST" action="book_class.php">
                    <input type="hidden" name="class_id" value="<?php echo $class['id']; ?>">
                    <button type="submit" 
                            class="w-full bg-blue-500 text-white py-2 px-4 rounded hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-50">
                        Book Class
                    </button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>
