<?php

    require_once 'config/database.php';
    include 'includes/navbar.php';
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    $db   = new GymDatabase();
    $conn = $db->getConnection();

$user_id = $_SESSION['user_id'];
  // Modify the schedule query to group by gym_id and get date ranges
  $stmt = $conn->prepare("
SELECT 
    s.gym_id,
    g.name as gym_name,
    s.activity_type,
    s.start_date,
    s.end_date,
    s.start_time,
    s.status,
    s.notes,
    s.recurring,
    s.recurring_until,
    s.days_of_week
FROM schedules s
JOIN gyms g ON s.gym_id = g.gym_id
WHERE s.user_id = ?
AND s.start_time >= CURDATE()
ORDER BY s.gym_id, s.start_date ASC
");

$stmt->execute([$user_id]);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group schedules by gym_id
$groupedSchedules = [];
foreach ($schedules as $schedule) {
    $groupedSchedules[$schedule['gym_id']][] = $schedule;
} 

    // Fetch user's gym for dropdown
    $stmt = $conn->prepare("
    SELECT g.* FROM gyms g
    JOIN user_memberships um ON g.gym_id = um.id
    WHERE um.user_id = ? AND um.status = 'active'
");
    $stmt->execute([$_SESSION['user_id']]);
    $userGyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $timeSlots = [
        '06:00:00', '07:00:00', '08:00:00', '09:00:00', '10:00:00',
        '11:00:00', '12:00:00', '13:00:00', '14:00:00', '15:00:00',
        '16:00:00', '17:00:00', '18:00:00', '19:00:00', '20:00:00',
    ];

    $workoutTypes = [
        'gym_visit'         => 'General Workout',
        'class'             => 'Class Session',
        'personal_training' => 'Personal Training',
    ];

     // Fetch user's active membership with completed payment
     $stmt = $conn->prepare("
     SELECT um.*, gmp.tier as plan_name, gmp.inclusions, gmp.duration,
            g.name as gym_name, g.address, p.status as payment_status
     FROM user_memberships um
     JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
     JOIN gyms g ON gmp.gym_id = g.gym_id
     JOIN payments p ON um.id = p.membership_id
     WHERE um.user_id = ?
     AND um.status = 'active'
     AND p.status = 'completed'
     ORDER BY um.start_date DESC
 ");
     $stmt->execute([$user_id]);
     $membership = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="container mx-auto px-4 py-8">
    <div class="bg-white rounded-lg shadow-lg p-6">
    <?php if($schedules): ?>
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">My Workout Schedule</h1>
        <a href="schedule_workout.php" class="bg-gradient-to-r from-blue-500 to-blue-600 text-white py-3 px-6 rounded-lg font-semibold hover:from-blue-600 hover:to-blue-700 transition-shadow shadow-md hover:shadow-lg">
          Change Schedule 
        </a>
    </div>
    
    <!-- Calendar View -->
    <div id="calendar"></div>

    <!-- Upcoming Schedules -->
    <div class="mt-8 bg-white rounded-lg p-6">
    <h2 class="text-2xl font-bold mb-6">Upcoming Schedules</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($groupedSchedules as $gym_id => $schedulesGroup): ?>
            <div class="border rounded-lg p-4 hover:shadow-md transition-shadow">
                <div class="flex justify-between items-start">
                    <div>
                        <h3 class="font-semibold"><?php echo htmlspecialchars($schedulesGroup[0]['gym_name']); ?></h3>
                        <p class="text-sm text-gray-600">
                            <?php echo $workoutTypes[$schedulesGroup[0]['activity_type']]; ?>
                        </p>
                    </div>
                    <span class="px-2 py-1 rounded-full text-sm <?php echo $schedulesGroup[0]['status'] === 'scheduled' ? 'bg-green-100 text-green-800' : ($schedulesGroup[0]['status'] === 'completed' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800'); ?>">
                        <?php echo ucfirst($schedulesGroup[0]['status']); ?>
                    </span>
                </div>

                <div class="mt-2 text-sm text-gray-600">
                    <?php
                    // Show start and end dates for the grouped schedules
                    $firstSchedule = $schedulesGroup[0];
                    $lastSchedule = end($schedulesGroup);
                    ?>
                    <p>From: <?php echo date('M j, Y', strtotime($firstSchedule['start_date'])); ?></p>
                    <p>To: <?php echo date('M j, Y', strtotime($lastSchedule['end_date'])); ?></p>
                    <p>Time: <?php echo date('g:i A', strtotime($firstSchedule['start_time'])); ?> - <?php echo date('g:i A', strtotime($lastSchedule['start_time'])); ?></p>

                    <?php if ($firstSchedule['recurring'] !== 'none'): ?>
                        <p class="text-blue-600">
                            <?php echo ucfirst($firstSchedule['recurring']); ?> schedule
                            <?php if ($firstSchedule['recurring_until']): ?>
                                until <?php echo date('M j, Y', strtotime($firstSchedule['end_date'])); ?>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($firstSchedule['notes']): ?>
                    <p class="mt-2 text-sm italic"><?php echo htmlspecialchars($firstSchedule['notes']); ?></p>
                <?php endif; ?>

                <div class="mt-4 flex space-x-2">
                    <button onclick="showEditForm('<?php echo $firstSchedule['gym_id']; ?>')"
                            class="bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600">
                        Edit
                    </button>
                    <button onclick="deleteSchedule('<?php echo $firstSchedule['gym_id']; ?>')"
                            class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600">
                        Delete
                    </button>
                </div>

            </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="flex flex-col justify-between items-center"> 
        <h1 class="text-2xl font-bold">My Workout Schedule</h1>
        <a href="schedule.php?gym_id=<?php echo $membership['gym_id']; ?>" class="bg-gradient-to-r from-blue-500 to-blue-600 text-white py-3 px-6 rounded-lg font-semibold hover:from-blue-600 hover:to-blue-700 transition-shadow shadow-md hover:shadow-lg my-10">
            Create Schedule
        </a>
    </div>
    <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css" rel="stylesheet">

<script>
    document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        events: <?php echo json_encode(array_map(function ($schedule) {
            return [
                'title' => htmlspecialchars($schedule['gym_name']),
                'start' => $schedule['start_date'],
                'end' => date('Y-m-d', strtotime($schedule['end_date'] . ' +1 day')),
                'groupId' => $schedule['gym_id'] // Group events by gym_id
            ];
        }, $schedules)); ?>,
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        eventRender: function(info) {
            var gymId = info.event.extendedProps.groupId;
            info.el.style.backgroundColor = gymId % 2 === 0 ? 'lightblue' : 'lightgreen'; // Example: alternate colors based on gym_id
        },
        eventGroupRender: function(info) {
            // Optional: Add breaks or separator between events if needed
            var events = info.group;
            if (events.length > 1) {
                var breakEvent = {
                    title: 'Break',
                    start: events[events.length - 1].end, // Break after the last event
                    duration: '00:15:00', // 15-minute break
                    allDay: false,
                    rendering: 'background' // This will render as a background event
                };
                info.calendar.addEvent(breakEvent);
            }
        }
    });

    calendar.render();
});


function showEditForm(scheduleId) {
    document.querySelectorAll('[id^="editForm_"]').forEach(form => form.classList.add('hidden'));
    document.getElementById(`editForm_${scheduleId}`).classList.remove('hidden');
}

function updateSchedule(scheduleId) {
    const newTime = document.getElementById(`time_${scheduleId}`).value;
    const newType = document.getElementById(`type_${scheduleId}`).value;

    fetch('update_schedule.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `schedule_id=${scheduleId}&new_time=${newTime}&new_type=${newType}`
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            location.reload();
        } else {
            alert('Failed to update schedule');
        }
    });
}

function deleteSchedule(scheduleId) {
    if (confirm('Are you sure you want to delete this schedule?')) {
        fetch('delete_schedule.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `schedule_id=${scheduleId}`
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                location.reload();
            } else {
                alert('Failed to delete schedule');
            }
        });
    }
}
</script>
