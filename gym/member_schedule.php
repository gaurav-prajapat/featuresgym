<?php
include '../includes/navbar.php';
require_once '../config/database.php';

$db = new GymDatabase();
$conn = $db->getConnection();
$member_id = $_GET['id'];

// Fetch member's schedules with detailed information
$stmt = $conn->prepare("
    SELECT 
        s.*,
        g.name as gym_name,
        g.address as gym_address,
        t.name as trainer_name,
        t.specialization as trainer_specialization
    FROM schedules s
    JOIN gyms g ON s.gym_id = g.gym_id
    LEFT JOIN trainers t ON s.id = t.id
    WHERE s.user_id = ?
    ORDER BY s.start_date ASC, s.start_time ASC
");
$stmt->execute([$member_id]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch member basic info
$stmt = $conn->prepare("
    SELECT 
        u.username, 
        u.email,
        um.status as membership_status,
        mp.plan_name as plan_name
    FROM users u
    LEFT JOIN user_memberships um ON u.id = um.user_id
    LEFT JOIN gym_membership_plans mp ON um.plan_id = mp.plan_id
    WHERE u.id = ?
");
$stmt->execute([$member_id]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

?>

<div class="container mx-auto px-4 py-20">
    <!-- Member Schedule Header -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-6">
        <div class="p-6 bg-gradient-to-r from-gray-900 to-gray-800 text-white">
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-4">
                    <div class="h-16 w-16 rounded-full bg-yellow-500 flex items-center justify-center">
                        <i class="fas fa-calendar-alt text-2xl text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold">Schedule for <?php echo htmlspecialchars($member['username']); ?></h1>
                        <p class="text-white "><?php echo htmlspecialchars($member['plan_name']); ?> Plan</p>
                    </div>
                </div>
                <span class="px-4 py-2 rounded-full <?php 
                    echo $member['membership_status'] === 'active' 
                        ? 'bg-green-500' 
                        : 'bg-red-500'; ?> text-white font-semibold">
                    <?php echo ucfirst($member['membership_status']); ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Calendar View -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
        <div id="calendar"></div>
    </div>

    <!-- Schedule List -->
    <div class="bg-white rounded-xl shadow-lg p-6">
        <h2 class="text-xl font-bold mb-6 flex items-center">
            <i class="fas fa-list text-yellow-500 mr-2"></i>
            Upcoming Schedules
        </h2>
        <div class="space-y-4">
            <?php foreach ($schedules as $schedule): ?>
                <div class="border rounded-lg hover:shadow-md transition-shadow duration-200">
                    <div class="p-4">
                        <div class="flex justify-between items-start">
                            <div class="flex items-start space-x-4">
                                <div class="rounded-full bg-yellow-100 p-3">
                                    <i class="fas fa-dumbbell text-yellow-500"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold text-lg"><?php echo htmlspecialchars($schedule['gym_name']); ?></h3>
                                    <p class="text-gray-600">
                                        <i class="fas fa-map-marker-alt mr-2"></i>
                                        <?php echo htmlspecialchars($schedule['gym_address']); ?>
                                    </p>
                                    <?php if ($schedule['trainer_name']): ?>
                                        <p class="text-gray-600 mt-2">
                                            <i class="fas fa-user-tie mr-2"></i>
                                            Trainer: <?php echo htmlspecialchars($schedule['trainer_name']); ?>
                                            (<?php echo htmlspecialchars($schedule['trainer_specialization']); ?>)
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <span class="px-3 py-1 rounded-full text-sm font-semibold <?php 
                                echo $schedule['status'] === 'completed' 
                                    ? 'bg-green-100 text-green-800' 
                                    : ($schedule['status'] === 'cancelled' 
                                        ? 'bg-red-100 text-red-800' 
                                        : 'bg-yellow-100 text-yellow-800'); ?>">
                                <?php echo ucfirst($schedule['status']); ?>
                            </span>
                        </div>
                        
                        <div class="mt-4 flex items-center justify-between text-sm text-gray-600">
                            <div class="flex items-center space-x-4">
                                <span>
                                    <i class="far fa-calendar mr-2"></i>
                                    <?php echo date('M d, Y', strtotime($schedule['start_date'])); ?>
                                </span>
                                <span>
                                    <i class="far fa-clock mr-2"></i>
                                    <?php echo date('h:i A', strtotime($schedule['start_time'])); ?>
                                </span>
                            </div>
                            <div class="flex items-center space-x-2">
                                <?php if ($schedule['status'] === 'scheduled'): ?>
                                    <button class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-edit mr-2"></i>Edit
                                    </button>
                                    <button class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">

<script>
document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        events: <?php echo json_encode(array_map(function($schedule) {
            return [
                'title' => $schedule['gym_name'],
                'start' => $schedule['start_date'] . 'T' . $schedule['start_time'],
                'className' => 'bg-yellow-500 border-yellow-600',
                'extendedProps' => [
                    'status' => $schedule['status'],
                    'trainer' => $schedule['trainer_name']
                ]
            ];
        }, $schedules)); ?>,
        eventDidMount: function(info) {
            info.el.querySelector('.fc-event-title').innerHTML = `
                <i class="fas fa-dumbbell mr-1"></i> ${info.event.title}
                ${info.event.extendedProps.trainer ? `<br><small>${info.event.extendedProps.trainer}</small>` : ''}
            `;
        }
    });
    calendar.render();
});
</script>
