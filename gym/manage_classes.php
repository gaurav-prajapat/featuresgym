<?php
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

// Fetch all classes
$stmt = $conn->prepare("
    SELECT 
        gc.*,
        COUNT(cb.id) as total_bookings
    FROM gym_classes gc
    LEFT JOIN class_bookings cb ON gc.id = cb.class_id
    WHERE gc.gym_id = :gym_id
    GROUP BY gc.id
    ORDER BY gc.name
");
$stmt->execute([':gym_id' => $gym_id]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container mx-auto px-4 py-20">

<div class="container mx-auto px-4 py-20">
    <?php if (isset($_GET['success'])): ?>
        <div class="bg-green-800 border border-green-600 text-green-100 px-4 py-3 rounded mb-4">
            Class created successfully!
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['updated'])): ?>
        <div class="bg-green-800 border border-green-600 text-green-100 px-4 py-3 rounded mb-4">
            Class updated successfully!
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['cancelled'])): ?>
        <div class="bg-blue-800 border border-blue-600 text-blue-100 px-4 py-3 rounded mb-4">
            Class cancelled successfully. All affected members have been notified.
        </div>
    <?php endif; ?>


    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-white">Manage Classes</h1>
        <a href="create_class.php"
            class="bg-yellow-400 text-black px-6 py-3 rounded-full font-bold text-center
                   hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300">
            Create New Class
        </a>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($classes as $class): ?>
            <div class="bg-gray-800 rounded-lg shadow-lg p-6 text-white">
                <div class="flex justify-between items-start mb-4">
                    <div>
                        <h3 class="text-xl font-semibold"><?php echo htmlspecialchars($class['name']); ?></h3>
                        <p class="text-gray-400">Instructor: <?php echo htmlspecialchars($class['instructor']); ?></p>
                    </div>
                    <span class="px-2 py-1 text-xs font-semibold rounded-full 
                    <?php echo getStatusClass($class['status']); ?>">
                        <?php echo ucfirst($class['status']); ?>
                    </span>
                </div>

                <div class="space-y-2 mb-4">
                    <p class="text-sm text-gray-400">
                        <i class="fas fa-users mr-2"></i> Capacity: <?php echo $class['capacity']; ?>
                        (<?php echo $class['current_bookings'] ?? 0; ?> booked)
                    </p>
                    <p class="text-sm text-gray-400">
                        <i class="fas fa-clock mr-2"></i> Duration: <?php echo $class['duration_minutes']; ?> minutes
                    </p>
                    <p class="text-sm text-gray-400">
                        <i class="fas fa-dumbbell mr-2"></i> Level: <?php echo ucfirst($class['difficulty_level']); ?>
                    </p>
                    <p class="text-sm text-gray-400">
                        <i class="fas fa-calendar-check mr-2"></i> Total Bookings: <?php echo $class['total_bookings']; ?>
                    </p>
                </div>

                <div class="border-t border-gray-700 pt-4">
                    <h4 class="font-medium mb-2 text-yellow-400">Upcoming Sessions</h4>
                    <?php
                    $schedule = json_decode($class['schedule'], true);
                    if ($schedule && is_array($schedule)):
                    ?>
                        <div class="text-sm space-y-2 max-h-40 overflow-y-auto pr-2">
                            <?php 
                            // Sort schedule by date
                            usort($schedule, function($a, $b) {
                                return strtotime($a['date'] . ' ' . $a['start_time']) - strtotime($b['date'] . ' ' . $b['start_time']);
                            });
                            
                            $today = date('Y-m-d');
                            $upcomingSessions = array_filter($schedule, function($session) use ($today) {
                                return $session['date'] >= $today;
                            });
                            
                            if (count($upcomingSessions) > 0):
                                foreach (array_slice($upcomingSessions, 0, 5) as $session): 
                            ?>
                                <div class="bg-gray-700 rounded p-2">
                                    <div class="flex justify-between items-center">
                                        <span class="font-medium">
                                            <?php echo date('D, M j, Y', strtotime($session['date'])); ?>
                                        </span>
                                    </div>
                                    <div class="text-gray-300 mt-1">
                                        <?php echo date('g:i A', strtotime($session['start_time'])); ?> - 
                                        <?php echo date('g:i A', strtotime($session['end_time'])); ?>
                                    </div>
                                </div>
                            <?php 
                                endforeach;
                                if (count($upcomingSessions) > 5): 
                            ?>
                                <div class="text-center text-gray-400 text-xs mt-2">
                                    + <?php echo count($upcomingSessions) - 5; ?> more sessions
                                </div>
                            <?php 
                                endif;
                            else: 
                            ?>
                                <div class="text-gray-400 italic">No upcoming sessions scheduled</div>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-gray-400 italic">No schedule information available</div>
                    <?php endif; ?>
                </div>

                <div class="mt-4 flex justify-end space-x-2">
                    <a href="edit_class.php?id=<?php echo $class['id']; ?>"
                        class="text-yellow-400 hover:text-yellow-300">
                        <i class="fas fa-edit mr-1"></i> Edit
                    </a>
                    <a href="class bookings.php?class_id=<?php echo $class['id']; ?>"
                        class="text-green-400 hover:text-green-300">
                        <i class="fas fa-calendar-alt mr-1"></i> View Bookings
                    </a>
                    <?php if ($class['status'] === 'active'): ?>
    <a href="#" 
       class="text-red-400 hover:text-red-300 cancel-class-btn" 
       data-class-id="<?php echo $class['id']; ?>">
        <i class="fas fa-times-circle mr-1"></i> Cancel
    </a>
<?php endif; ?>

                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Cancel Class Modal -->
<div id="cancelClassModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 max-w-md w-full mx-4">
        <h3 class="text-xl font-bold text-white mb-4">Cancel Class</h3>
        <p class="text-gray-300 mb-6">Are you sure you want to cancel this class? All bookings will be cancelled and members will be notified.</p>
        
        <div class="flex justify-end space-x-4">
            <button id="cancelModalClose" class="bg-gray-600 text-white px-4 py-2 rounded-full hover:bg-gray-700 transition-colors">
                No, Keep Class
            </button>
            <a id="confirmCancelLink" href="#" class="bg-red-500 text-white px-4 py-2 rounded-full hover:bg-red-600 transition-colors">
                Yes, Cancel Class
            </a>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('cancelClassModal');
    const closeBtn = document.getElementById('cancelModalClose');
    const confirmLink = document.getElementById('confirmCancelLink');
    
    // Get all cancel links with the cancel-class-btn class
    const cancelLinks = document.querySelectorAll('.cancel-class-btn');
    
    cancelLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const classId = this.getAttribute('data-class-id');
            modal.classList.remove('hidden');
            confirmLink.href = 'cancel_class.php?id=' + classId;
        });
    });
    
    // Close modal when clicking the close button
    closeBtn.addEventListener('click', function() {
        modal.classList.add('hidden');
    });
    
    // Close modal when clicking outside
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.classList.add('hidden');
        }
    });
});

</script>

<?php
function getStatusClass($status)
{
    switch ($status) {
        case 'active':
            return 'bg-green-900 text-green-300';
        case 'cancelled':
            return 'bg-red-900 text-red-300';
        case 'completed':
            return 'bg-blue-900 text-blue-300';
        default:
            return 'bg-gray-700 text-gray-300';
    }
}
?>
