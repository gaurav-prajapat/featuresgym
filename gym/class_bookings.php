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

// Fetch all bookings with user and class details
$stmt = $conn->prepare("
    SELECT 
        cb.id as booking_id,
        cb.booking_date,
        cb.status,
        u.username,
        u.email,
        gc.name as class_name,
        gc.instructor,
        gc.schedule
    FROM class_bookings cb
    JOIN users u ON cb.user_id = u.id
    JOIN gym_classes gc ON cb.class_id = gc.id
    WHERE gc.gym_id = :gym_id
    ORDER BY cb.booking_date DESC
");
$stmt->execute([':gym_id' => $gym_id]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container mx-auto px-4 py-8 pt-20">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-white">Class Bookings</h1>
        <div class="flex gap-5">
        <a href="manage_classes.php" 
           class="bg-yellow-400 text-black px-6 py-3 rounded-full font-bold text-center
                  hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300">
            Manage Class
        </a>
        <a href="create_class.php" 
           class="bg-yellow-400 text-black px-6 py-3 rounded-full font-bold text-center
                  hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300">
            Create New Class
        </a>
        </div>
    </div>

    <div class="bg-gray-800 rounded-lg shadow-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-700">
            <thead class="bg-gray-900">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                        Booking ID
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                        Member
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                        Class
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                        Date
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                        Status
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-300 uppercase tracking-wider">
                        Actions
                    </th>
                </tr>
            </thead>
            <tbody class="bg-gray-800 divide-y divide-gray-700">
                <?php foreach ($bookings as $booking): ?>
                <tr>
                    <td class="px-6 py-4 whitespace-nowrap text-gray-300">
                        #<?php echo htmlspecialchars($booking['booking_id']); ?>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm font-medium text-white">
                            <?php echo htmlspecialchars($booking['username']); ?>
                        </div>
                        <div class="text-sm text-gray-400">
                            <?php echo htmlspecialchars($booking['email']); ?>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <div class="text-sm font-medium text-white">
                            <?php echo htmlspecialchars($booking['class_name']); ?>
                        </div>
                        <div class="text-sm text-gray-400">
                            Instructor: <?php echo htmlspecialchars($booking['instructor']); ?>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">
                        <?php echo date('M d, Y', strtotime($booking['booking_date'])); ?>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                            <?php echo getStatusClass($booking['status']); ?>">
                            <?php echo ucfirst($booking['status']); ?>
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <a href="update_booking.php?id=<?php echo $booking['booking_id']; ?>" 
                           class="text-yellow-400 hover:text-yellow-300 mr-3">Update</a>
                        <a href="cancel_booking.php?id=<?php echo $booking['booking_id']; ?>" 
                           class="text-red-400 hover:text-red-300"
                           onclick="return confirm('Are you sure you want to cancel this booking?')">Cancel</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
function getStatusClass($status) {
    switch ($status) {
        case 'booked':
            return 'bg-green-900 text-green-300';
        case 'attended':
            return 'bg-blue-900 text-blue-300';
        case 'cancelled':
            return 'bg-red-900 text-red-300';
        case 'missed':
            return 'bg-yellow-900 text-yellow-300';
        default:
            return 'bg-gray-700 text-gray-300';
    }
}
?>
