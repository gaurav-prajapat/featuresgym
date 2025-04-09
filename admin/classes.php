<?php
ob_start();

include '../includes/navbar.php';

require_once '../includes/auth.php';
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Add Class
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $trainer = $_POST['trainer'];
    $schedule = $_POST['schedule'];
    $capacity = $_POST['capacity'];

    $stmt = $conn->prepare("INSERT INTO classes (name, trainer_id, schedule, capacity) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $trainer, $schedule, $capacity]);
}

// Get Classes
$stmt = $conn->query("SELECT * FROM classes");
$classes = $stmt->fetchAll();

?>
    <div class="container mx-auto p-6">
        <h1 class="text-3xl font-bold mb-6">Manage Classes</h1>
        
        <!-- Add Class Form -->
        <div class="bg-white p-6 rounded-lg shadow mb-6">
            <h2 class="text-xl font-bold mb-4">Add New Class</h2>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block mb-2">Class Name</label>
                    <input type="text" name="name" required class="w-full p-2 border rounded">
                </div>
                <div>
                    <label class="block mb-2">Trainer</label>
                    <select name="trainer" required class="w-full p-2 border rounded">
                        <!-- Add trainer options dynamically -->
                    </select>
                </div>
                <div>
                    <label class="block mb-2">Schedule</label>
                    <input type="datetime-local" name="schedule" required class="w-full p-2 border rounded">
                </div>
                <div>
                    <label class="block mb-2">Capacity</label>
                    <input type="number" name="capacity" required class="w-full p-2 border rounded">
                </div>
                <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                    Add Class
                </button>
            </form>
        </div>

        <!-- Classes List -->
        <div class="bg-white p-6 rounded-lg shadow">
            <h2 class="text-xl font-bold mb-4">Current Classes</h2>
            <table class="w-full">
                <thead>
                    <tr>
                        <th class="text-left p-2">Name</th>
                        <th class="text-left p-2">Trainer</th>
                        <th class="text-left p-2">Schedule</th>
                        <th class="text-left p-2">Capacity</th>
                        <th class="text-left p-2">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $class): ?>
                    <tr>
                        <td class="p-2"><?php echo htmlspecialchars($class['name']); ?></td>
                        <td class="p-2"><?php echo htmlspecialchars($class['trainer_id']); ?></td>
                        <td class="p-2"><?php echo htmlspecialchars($class['schedule']); ?></td>
                        <td class="p-2"><?php echo htmlspecialchars($class['capacity']); ?></td>
                        <td class="p-2">
                            <a href="edit_class.php?id=<?php echo $class['id']; ?>" class="text-blue-500">Edit</a>
                            <a href="delete_class.php?id=<?php echo $class['id']; ?>" class="text-red-500 ml-2">Delete</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
