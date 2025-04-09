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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $description = $_POST['description'];
    $instructor = $_POST['instructor'];
    $capacity = (int)$_POST['capacity'];
    $duration = (int)$_POST['duration_minutes'];
    $difficulty = $_POST['difficulty_level'];
    
    // Process schedule dates and times
    $scheduleDates = $_POST['schedule_date'] ?? [];
    $scheduleStartTimes = $_POST['schedule_start_time'] ?? [];
    $scheduleEndTimes = $_POST['schedule_end_time'] ?? [];
    
    $scheduleData = [];
    for ($i = 0; $i < count($scheduleDates); $i++) {
        if (!empty($scheduleDates[$i]) && !empty($scheduleStartTimes[$i]) && !empty($scheduleEndTimes[$i])) {
            $scheduleData[] = [
                'date' => $scheduleDates[$i],
                'start_time' => $scheduleStartTimes[$i],
                'end_time' => $scheduleEndTimes[$i]
            ];
        }
    }
    
    $schedule = json_encode($scheduleData);
    
    $stmt = $conn->prepare("
        INSERT INTO gym_classes (
            gym_id, name, description, instructor, 
            capacity, duration_minutes, difficulty_level, 
            schedule, status
        ) VALUES (
            :gym_id, :name, :description, :instructor,
            :capacity, :duration, :difficulty,
            :schedule, 'active'
        )
    ");

    $result = $stmt->execute([
        ':gym_id' => $gym_id,
        ':name' => $name,
        ':description' => $description,
        ':instructor' => $instructor,
        ':capacity' => $capacity,
        ':duration' => $duration,
        ':difficulty' => $difficulty,
        ':schedule' => $schedule
    ]);

    if ($result) {
        header('Location: manage_classes.php?success=1');
        exit;
    }
}

?>

<div class="container mx-auto px-4 py-8 pt-20">
    <div class="max-w-2xl mx-auto">
        <h1 class="text-2xl font-bold mb-6 text-white">Create New Class</h1>

        <form method="POST" class="bg-gray-800 rounded-lg shadow-lg p-6 text-white">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-300">Class Name</label>
                    <input type="text" name="name" required
                           class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300">Description</label>
                    <textarea name="description" rows="3"
                            class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400"></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300">Instructor</label>
                    <input type="text" name="instructor" required
                           class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-300">Capacity</label>
                        <input type="number" name="capacity" required min="1"
                               class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-300">Duration (minutes)</label>
                        <input type="number" name="duration_minutes" required min="15" step="15"
                               class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300">Difficulty Level</label>
                    <select name="difficulty_level" required
                            class="mt-1 block w-full rounded-md border-gray-600 bg-gray-700 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400">
                        <option value="beginner">Beginner</option>
                        <option value="intermediate">Intermediate</option>
                        <option value="advanced">Advanced</option>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Class Schedule</label>
                    <p class="text-sm text-gray-400 mb-3">Add specific dates and times for this class</p>
                    
                    <div id="schedule-container" class="space-y-3">
                        <div class="schedule-entry bg-gray-700 p-3 rounded-md">
                            <div class="grid grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-300 mb-1">Date</label>
                                    <input type="date" name="schedule_date[]" required
                                           class="block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-300 mb-1">Start Time</label>
                                    <input type="time" name="schedule_start_time[]" required
                                           class="block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400 text-sm">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-300 mb-1">End Time</label>
                                    <input type="time" name="schedule_end_time[]" required
                                           class="block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400 text-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="button" id="add-schedule" 
                            class="mt-3 bg-gray-700 text-white px-4 py-2 rounded-md hover:bg-gray-600 transition-colors duration-200">
                        <i class="fas fa-plus mr-2"></i>Add Another Date/Time
                    </button>
                </div>
            </div>

            <div class="mt-6 flex flex-col sm:flex-row justify-end space-y-4 sm:space-y-0 sm:space-x-4">
                <a href="manage_classes.php" 
                   class="bg-gray-600 text-white px-6 py-3 rounded-full font-bold text-center
                          hover:bg-gray-700 transform hover:scale-105 transition-all duration-300">
                    Cancel
                </a>
                <button type="submit" 
                        class="bg-yellow-400 text-black px-6 py-3 rounded-full font-bold text-center
                               hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300">
                    Create Class
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const scheduleContainer = document.getElementById('schedule-container');
    const addScheduleBtn = document.getElementById('add-schedule');
    
    addScheduleBtn.addEventListener('click', function() {
        const newEntry = document.createElement('div');
        newEntry.className = 'schedule-entry bg-gray-700 p-3 rounded-md relative';
        
        newEntry.innerHTML = `
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-300 mb-1">Date</label>
                    <input type="date" name="schedule_date[]" required
                           class="block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-300 mb-1">Start Time</label>
                    <input type="time" name="schedule_start_time[]" required
                           class="block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-300 mb-1">End Time</label>
                    <input type="time" name="schedule_end_time[]" required
                           class="block w-full rounded-md border-gray-600 bg-gray-800 text-white shadow-sm focus:border-yellow-400 focus:ring-yellow-400 text-sm">
                </div>
            </div>
            <button type="button" class="remove-schedule absolute top-2 right-2 text-red-400 hover:text-red-300">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        scheduleContainer.appendChild(newEntry);
        
        // Add event listener to the remove button
        newEntry.querySelector('.remove-schedule').addEventListener('click', function() {
            scheduleContainer.removeChild(newEntry);
        });
    });
});
</script>
