<?php
ob_start();
require_once '../config/database.php';
include '../includes/navbar.php';

// Check if owner is logged in
if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];

// Get Gym Details
$stmt = $conn->prepare("SELECT * FROM gyms WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    header('Location: add_gym.php');
    exit;
}

$gym_id = $gym['gym_id'];

// Process form submission for adding/editing tournament
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new tournament
        if ($_POST['action'] === 'add') {
            $title = trim($_POST['title']);
            $description = trim($_POST['description']);
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'] ?? $start_date;
            $registration_deadline = $_POST['registration_deadline'];
            $max_participants = (int) $_POST['max_participants'];
            $entry_fee = (float) $_POST['entry_fee'];
            $prize_pool = (float) $_POST['prize_pool'];
            $tournament_type = $_POST['tournament_type'];
            $status = $_POST['status'];
            $rules = trim($_POST['rules']);
            $eligibility_criteria = trim($_POST['eligibility_criteria']);
            $location = $_POST['location'] ?? $gym['address'];
            $eligibility_type = $_POST['eligibility_type'];
            $min_membership_days = isset($_POST['min_membership_days']) ? (int)$_POST['min_membership_days'] : 0;
            $has_age_restriction = isset($_POST['has_age_restriction']) ? 1 : 0;
            $min_age = $has_age_restriction ? (int)$_POST['min_age'] : null;
            $max_age = $has_age_restriction ? (int)$_POST['max_age'] : null;
            $gender_restriction = $_POST['gender_restriction'];


            // Validate required fields
            if (
                empty($title) || empty($tournament_type) || empty($start_date) ||
                empty($registration_deadline) || $max_participants <= 0
            ) {
                $_SESSION['error'] = "Please fill in all required fields.";
            } else {
                // Validate dates
                $current_date = date('Y-m-d');
                if ($registration_deadline < $current_date) {
                    $_SESSION['error'] = "Registration deadline cannot be in the past.";
                } elseif ($start_date < $registration_deadline) {
                    $_SESSION['error'] = "Tournament start date must be after registration deadline.";
                } elseif ($end_date < $start_date) {
                    $_SESSION['error'] = "Tournament end date must be after start date.";
                } else {
                    try {
                        // Upload tournament image if provided
                        $image_path = null;
                        if (isset($_FILES['tournament_image']) && $_FILES['tournament_image']['error'] === UPLOAD_ERR_OK) {
                            $upload_dir = '../uploads/tournament_images/';
                            if (!is_dir($upload_dir)) {
                                mkdir($upload_dir, 0755, true);
                            }

                            $file_extension = pathinfo($_FILES['tournament_image']['name'], PATHINFO_EXTENSION);
                            $new_filename = 'tournament_' . uniqid() . '.' . $file_extension;
                            $upload_path = $upload_dir . $new_filename;

                            if (move_uploaded_file($_FILES['tournament_image']['tmp_name'], $upload_path)) {
                                $image_path = $new_filename;
                            }
                        }

                        // Insert tournament into database
                        $stmt = $conn->prepare("
                        INSERT INTO gym_tournaments (
                            gym_id, title, description, start_date, end_date, 
                            registration_deadline, max_participants, entry_fee, 
                            prize_pool, tournament_type, status, rules, 
                            eligibility_criteria, location, image_path, 
                            eligibility_type, min_membership_days, min_age, max_age, gender_restriction,
                            created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                        )
                    ");
                    
                    $stmt->execute([
                        $gym_id, $title, $description, $start_date, $end_date,
                        $registration_deadline, $max_participants, $entry_fee,
                        $prize_pool, $tournament_type, $status, $rules,
                        $eligibility_criteria, $location, $image_path,
                        $eligibility_type, $min_membership_days, $min_age, $max_age, $gender_restriction
                    ]);

                        $tournament_id = $conn->lastInsertId();

                        // Log the activity
                        $stmt = $conn->prepare("
                            INSERT INTO activity_logs (
                                user_id, user_type, action, details, ip_address, user_agent
                            ) VALUES (?, 'owner', 'create_tournament', ?, ?, ?)
                        ");

                        $details = "Created tournament: " . $title;
                        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

                        $stmt->execute([$owner_id, $details, $ip, $user_agent]);

                        $_SESSION['success'] = "Tournament created successfully!";
                        header('Location: tournaments.php?id=' . $tournament_id);
                        exit;
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Database error: " . $e->getMessage();
                    }
                }
            }
        }
        // Edit existing tournament
        elseif ($_POST['action'] === 'edit' && isset($_POST['tournament_id'])) {
            $tournament_id = (int) $_POST['tournament_id'];
            $title = trim($_POST['title']);
            $description = trim($_POST['description']);
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'] ?? $start_date;
            $registration_deadline = $_POST['registration_deadline'];
            $max_participants = (int) $_POST['max_participants'];
            $entry_fee = (float) $_POST['entry_fee'];
            $prize_pool = (float) $_POST['prize_pool'];
            $tournament_type = $_POST['tournament_type'];
            $status = $_POST['status'];
            $rules = trim($_POST['rules']);
            $eligibility_criteria = trim($_POST['eligibility_criteria']);
            $location = $_POST['location'] ?? $gym['address'];
            $eligibility_type = $_POST['eligibility_type'] ?? 'all';
$min_membership_days = isset($_POST['min_membership_days']) ? (int)$_POST['min_membership_days'] : 0;
$has_age_restriction = isset($_POST['has_age_restriction']) ? 1 : 0;
$min_age = $has_age_restriction ? (int)$_POST['min_age'] : null;
$max_age = $has_age_restriction ? (int)$_POST['max_age'] : null;
$gender_restriction = $_POST['gender_restriction'] ?? 'none';

            // Validate required fields
            if (
                empty($title) || empty($tournament_type) || empty($start_date) ||
                empty($registration_deadline) || $max_participants <= 0
            ) {
                $_SESSION['error'] = "Please fill in all required fields.";
            } else {
                // Validate dates
                $current_date = date('Y-m-d');

                // Only validate registration deadline for upcoming tournaments
                if ($status === 'upcoming' && $registration_deadline < $current_date) {
                    $_SESSION['error'] = "Registration deadline cannot be in the past for upcoming tournaments.";
                } elseif ($end_date < $start_date) {
                    $_SESSION['error'] = "Tournament end date must be after start date.";
                } else {
                    try {
                        // Check if tournament belongs to this gym
                        $stmt = $conn->prepare("SELECT * FROM gym_tournaments WHERE id = ? AND gym_id = ?");
                        $stmt->execute([$tournament_id, $gym_id]);
                        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

                        if (!$tournament) {
                            $_SESSION['error'] = "Tournament not found or you don't have permission to edit it.";
                        } else {
                            // Upload tournament image if provided
                            $image_path = $tournament['image_path'];
                            if (isset($_FILES['tournament_image']) && $_FILES['tournament_image']['error'] === UPLOAD_ERR_OK) {
                                $upload_dir = '../uploads/tournament_images/';
                                if (!is_dir($upload_dir)) {
                                    mkdir($upload_dir, 0755, true);
                                }

                                // Delete old image if exists
                                if ($image_path && file_exists($upload_dir . $image_path)) {
                                    unlink($upload_dir . $image_path);
                                }

                                $file_extension = pathinfo($_FILES['tournament_image']['name'], PATHINFO_EXTENSION);
                                $new_filename = 'tournament_' . uniqid() . '.' . $file_extension;
                                $upload_path = $upload_dir . $new_filename;

                                if (move_uploaded_file($_FILES['tournament_image']['tmp_name'], $upload_path)) {
                                    $image_path = $new_filename;
                                }
                            }

                            // Update tournament in database
                            $stmt = $conn->prepare("
                            UPDATE gym_tournaments SET
                                title = ?, description = ?, start_date = ?, end_date = ?,
                                registration_deadline = ?, max_participants = ?, entry_fee = ?,
                                prize_pool = ?, tournament_type = ?, status = ?, rules = ?,
                                eligibility_criteria = ?, location = ?, image_path = ?, 
                                eligibility_type = ?, min_membership_days = ?, min_age = ?, max_age = ?, gender_restriction = ?,
                                updated_at = NOW()
                            WHERE id = ? AND gym_id = ?
                        ");
                        
                        $stmt->execute([
                            $title, $description, $start_date, $end_date,
                            $registration_deadline, $max_participants, $entry_fee,
                            $prize_pool, $tournament_type, $status, $rules,
                            $eligibility_criteria, $location, $image_path,
                            $eligibility_type, $min_membership_days, $min_age, $max_age, $gender_restriction,
                            $tournament_id, $gym_id
                        ]);

                            // Log the activity
                            $stmt = $conn->prepare("
                                INSERT INTO activity_logs (
                                    user_id, user_type, action, details, ip_address, user_agent
                                ) VALUES (?, 'owner', 'update_tournament', ?, ?, ?)
                            ");

                            $details = "Updated tournament: " . $title;
                            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

                            $stmt->execute([$owner_id, $details, $ip, $user_agent]);

                            $_SESSION['success'] = "Tournament updated successfully!";
                            header('Location: tournaments.php?id=' . $tournament_id);
                            exit;
                        }
                    } catch (PDOException $e) {
                        $_SESSION['error'] = "Database error: " . $e->getMessage();
                    }
                }
            }
        }
        // Delete tournament
        elseif ($_POST['action'] === 'delete' && isset($_POST['tournament_id'])) {
            $tournament_id = (int) $_POST['tournament_id'];

            try {
                // Check if tournament belongs to this gym
                $stmt = $conn->prepare("SELECT * FROM gym_tournaments WHERE id = ? AND gym_id = ?");
                $stmt->execute([$tournament_id, $gym_id]);
                $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$tournament) {
                    $_SESSION['error'] = "Tournament not found or you don't have permission to delete it.";
                } else {
                    // Delete tournament image if exists
                    if ($tournament['image_path']) {
                        $image_path = '../uploads/tournament_images/' . $tournament['image_path'];
                        if (file_exists($image_path)) {
                            unlink($image_path);
                        }
                    }

                    // Delete tournament from database
                    $stmt = $conn->prepare("DELETE FROM gym_tournaments WHERE id = ? AND gym_id = ?");
                    $stmt->execute([$tournament_id, $gym_id]);

                    // Log the activity
                    $stmt = $conn->prepare("
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (?, 'owner', 'delete_tournament', ?, ?, ?)
                    ");

                    $details = "Deleted tournament: " . $tournament['title'];
                    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
                    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

                    $stmt->execute([$owner_id, $details, $ip, $user_agent]);

                    $_SESSION['success'] = "Tournament deleted successfully!";
                    header('Location: tournaments.php');
                    exit;
                }
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
            }
        }
        // Update tournament status
        elseif ($_POST['action'] === 'update_status' && isset($_POST['tournament_id'])) {
            $tournament_id = (int) $_POST['tournament_id'];
            $new_status = $_POST['new_status'];

            // Validate status
            $valid_statuses = ['upcoming', 'ongoing', 'completed', 'cancelled'];
            if (in_array($new_status, $valid_statuses)) {
                try {
                    // Check if tournament belongs to this gym
                    $stmt = $conn->prepare("SELECT * FROM gym_tournaments WHERE id = ? AND gym_id = ?");
                    $stmt->execute([$tournament_id, $gym_id]);
                    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$tournament) {
                        $_SESSION['error'] = "Tournament not found or you don't have permission to update it.";
                    } else {
                        $stmt = $conn->prepare("UPDATE gym_tournaments SET status = ? WHERE id = ? AND gym_id = ?");
                        $stmt->execute([$new_status, $tournament_id, $gym_id]);

                        $_SESSION['success'] = "Tournament status updated successfully.";
                        header("Location: tournaments.php?id=" . $tournament_id);
                        exit;
                    }
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Database error: " . $e->getMessage();
                }
            }
        }
    }
}

// Get tournaments for this gym
$stmt = $conn->prepare("
    SELECT t.*, 
           COUNT(p.id) as participant_count,
           (SELECT SUM(amount) FROM gym_revenue WHERE source_type = 'tournament' AND id = t.id) as revenue
    FROM gym_tournaments t
    LEFT JOIN tournament_participants p ON t.id = p.tournament_id
    WHERE t.gym_id = ?
    GROUP BY t.id
    ORDER BY 
        CASE 
            WHEN t.status = 'upcoming' THEN 1
            WHEN t.status = 'ongoing' THEN 2
            WHEN t.status = 'completed' THEN 3
            ELSE 4
        END,
        t.start_date DESC
");
$stmt->execute([$gym_id]);
$tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tournament to edit if ID is provided
$tournament_to_edit = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int) $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM gym_tournaments WHERE id = ? AND gym_id = ?");
    $stmt->execute([$edit_id, $gym_id]);
    $tournament_to_edit = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get tournament details if ID is provided
$tournament_details = null;
$participants = [];
$results = [];
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $tournament_id = (int) $_GET['id'];

    // Get tournament details
    $stmt = $conn->prepare("
        SELECT t.*, 
               (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id) as participant_count,
               (SELECT SUM(amount) FROM gym_revenue WHERE source_type = 'tournament' AND id = t.id) as revenue
        FROM gym_tournaments t
        WHERE t.id = ? AND t.gym_id = ?
    ");
    $stmt->execute([$tournament_id, $gym_id]);
    $tournament_details = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tournament_details) {
        // Get participants
        $stmt = $conn->prepare("
            SELECT tp.*, u.username, u.email, u.phone, u.profile_image
            FROM tournament_participants tp
            JOIN users u ON tp.user_id = u.id
            WHERE tp.tournament_id = ?
            ORDER BY tp.registration_date
        ");
        $stmt->execute([$tournament_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get tournament results if available
        if ($tournament_details['status'] === 'completed') {
            $stmt = $conn->prepare("
                SELECT tr.*, u.username, u.profile_image
                FROM tournament_results tr
                JOIN users u ON tr.user_id = u.id
                WHERE tr.tournament_id = ?
                ORDER BY tr.position
            ");
            $stmt->execute([$tournament_id]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

// Tournament types
$tournament_types = [
    'bodybuilding_competition',
    'weightlifting_competition',
    'powerlifting_meet',
    'crossfit_challenge',
    'fitness_challenge',
    'yoga_competition',
    'martial_arts_tournament',
    'sports_event',
    'other'
];
?>

<div class="container mx-auto px-4 py-8 pt-24">
    <!-- Header Section -->
    <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold text-white">Tournaments & Competitions</h1>
        <a href="dashboard.php" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
            <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
        </a>
    </div>

    <!-- Messages -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-900 text-red-100 p-6 rounded-3xl mb-6">
            <?= htmlspecialchars($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-900 text-green-100 p-6 rounded-3xl mb-6">
            <?= htmlspecialchars($_SESSION['success']) ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if ($tournament_details): ?>
        <!-- Tournament Details View (Similar to view_tournament.php) -->
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden mb-8">
            <div class="h-64 relative">
                <?php if ($tournament_details['image_path']): ?>
                    <img src="../uploads/tournament_images/<?= htmlspecialchars($tournament_details['image_path']) ?>"
                        alt="<?= htmlspecialchars($tournament_details['title']) ?>" class="w-full h-full object-cover">
                <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-r from-yellow-400 to-yellow-600">
                        <i class="fas fa-trophy text-white text-6xl"></i>
                    </div>
                <?php endif; ?>

                <div class="absolute inset-0 bg-gradient-to-t from-black to-transparent opacity-70"></div>

                <div class="absolute bottom-0 left-0 right-0 p-6">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-end">
                        <div>
                            <span
                                class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-yellow-400 text-black mb-2">
                                <?= ucwords(str_replace('_', ' ', $tournament_details['tournament_type'])) ?>
                            </span>
                            <h1 class="text-3xl font-bold text-white mb-1">
                                <?= htmlspecialchars($tournament_details['title']) ?></h1>
                        </div>

                        <div class="mt-4 md:mt-0 flex space-x-3">
                            <span class="px-4 py-2 rounded-full text-sm font-semibold
                                <?php
                                switch ($tournament_details['status']) {
                                    case 'upcoming':
                                        echo 'bg-blue-900 text-blue-300';
                                        break;
                                    case 'ongoing':
                                        echo 'bg-green-900 text-green-300';
                                        break;
                                    case 'completed':
                                        echo 'bg-gray-700 text-gray-300';
                                        break;
                                    case 'cancelled':
                                        echo 'bg-red-900 text-red-300';
                                        break;
                                    default:
                                        echo 'bg-gray-700 text-gray-300';
                                }
                                ?>">
                                <?= ucfirst($tournament_details['status']) ?>
                            </span>

                            <a href="tournaments.php?edit=<?= $tournament_details['id'] ?>"
                                class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-black rounded-full transition-colors duration-200">
                                <i class="fas fa-edit mr-1"></i> Edit
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-gray-700 p-4 rounded-xl text-center">
                        <p class="text-sm text-gray-400">Date</p>
                        <p class="text-lg font-medium">
                            <?= date('M d', strtotime($tournament_details['start_date'])) ?>
                            <?php if ($tournament_details['start_date'] !== $tournament_details['end_date']): ?>
                                - <?= date('M d, Y', strtotime($tournament_details['end_date'])) ?>
                            <?php else: ?>
                                <?= ', ' . date('Y', strtotime($tournament_details['start_date'])) ?>
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="bg-gray-700 p-4 rounded-xl text-center">
                        <p class="text-sm text-gray-400">Entry Fee</p>
                        <p class="text-lg font-medium">₹<?= number_format($tournament_details['entry_fee'], 0) ?></p>
                    </div>

                    <div class="bg-gray-700 p-4 rounded-xl text-center">
                        <p class="text-sm text-gray-400">Prize Pool</p>
                        <p class="text-lg font-medium">₹<?= number_format($tournament_details['prize_pool'], 0) ?></p>
                    </div>

                    <div class="bg-gray-700 p-4 rounded-xl text-center">
                        <p class="text-sm text-gray-400">Revenue</p>
                        <p class="text-lg font-medium text-yellow-400">
                            ₹<?= number_format($tournament_details['revenue'] ?? 0, 0) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tournament Details Tabs -->
        <div class="mb-6">
            <div class="border-b border-gray-700">
                <nav class="flex -mb-px">
                    <button id="detailsTab"
                        class="tab-button active py-4 px-6 border-b-2 border-yellow-400 font-medium text-yellow-400">
                        Details
                    </button>
                    <button id="participantsTab"
                        class="tab-button py-4 px-6 border-b-2 border-transparent font-medium hover:text-yellow-400">
                        Participants (<?= count($participants) ?>)
                    </button>
                    <button id="resultsTab"
                        class="tab-button py-4 px-6 border-b-2 border-transparent font-medium hover:text-yellow-400">
                        Results
                    </button>
                </nav>
            </div>
        </div>

        <!-- Details Tab Content -->
        <div id="detailsContent" class="tab-content">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2">
                    <!-- Description -->
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 mb-8">
                        <h2 class="text-xl font-semibold mb-4">About This Tournament</h2>
                        <div class="prose prose-lg max-w-none text-gray-300">
                            <?= nl2br(htmlspecialchars($tournament_details['description'])) ?>
                        </div>
                    </div>

                    <!-- Rules -->
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 mb-8">
                        <h2 class="text-xl font-semibold mb-4">Rules & Guidelines</h2>
                        <div class="prose prose-lg max-w-none text-gray-300">
                            <?= nl2br(htmlspecialchars($tournament_details['rules'])) ?>
                        </div>
                    </div>

                    <!-- Eligibility -->
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6">
                        <h2 class="text-xl font-semibold mb-4">Eligibility Criteria</h2>
                        <div class="prose prose-lg max-w-none text-gray-300">
                            <?= nl2br(htmlspecialchars($tournament_details['eligibility_criteria'])) ?>
                        </div>
                    </div>
                </div>

                <div>
                    <!-- Important Dates -->
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 mb-8">
                        <h2 class="text-xl font-semibold mb-4">Important Dates</h2>

                        <div class="space-y-4">
                            <div class="flex items-start">
                                <div class="bg-yellow-400 text-black rounded-full p-2 mr-3">
                                    <i class="fas fa-calendar-day"></i>
                                </div>
                                <div>
                                    <h3 class="font-medium">Registration Deadline</h3>
                                    <p class="text-gray-300">
                                        <?= date('F d, Y', strtotime($tournament_details['registration_deadline'])) ?></p>
                                </div>
                            </div>

                            <div class="flex items-start">
                                <div class="bg-yellow-400 text-black rounded-full p-2 mr-3">
                                    <i class="fas fa-flag-checkered"></i>
                                </div>
                                <div>
                                    <h3 class="font-medium">Tournament Start</h3>
                                    <p class="text-gray-300">
                                        <?= date('F d, Y', strtotime($tournament_details['start_date'])) ?></p>
                                </div>
                            </div>

                            <?php if ($tournament_details['start_date'] !== $tournament_details['end_date']): ?>
                                <div class="flex items-start">
                                    <div class="bg-yellow-400 text-black rounded-full p-2 mr-3">
                                        <i class="fas fa-trophy"></i>
                                    </div>
                                    <div>
                                        <h3 class="font-medium">Tournament End</h3>
                                        <p class="text-gray-300">
                                            <?= date('F d, Y', strtotime($tournament_details['end_date'])) ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Registration Stats -->
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 mb-8">
                        <h2 class="text-xl font-semibold mb-4">Registration Stats</h2>

                        <div class="space-y-4">
                            <div>
                                <h3 class="font-medium mb-2">Participants</h3>
                                <div class="w-full bg-gray-700 rounded-full h-4">
                                    <div class="bg-yellow-400 h-4 rounded-full"
                                        style="width: <?= ($tournament_details['participant_count'] / $tournament_details['max_participants']) * 100 ?>%">
                                    </div>
                                </div>
                                <div class="flex justify-between mt-1 text-sm">
                                    <span><?= $tournament_details['participant_count'] ?> registered</span>
                                    <span><?= $tournament_details['max_participants'] ?> max</span>
                                </div>
                            </div>

                            <div>
                                <h3 class="font-medium mb-2">Payment Status</h3>
                                <?php
                                // Calculate payment stats
                                $paid_count = 0;
                                $pending_count = 0;

                                foreach ($participants as $participant) {
                                    if ($participant['payment_status'] === 'paid') {
                                        $paid_count++;
                                    } else {
                                        $pending_count++;
                                    }
                                }

                                $total_count = count($participants);
                                $paid_percentage = $total_count > 0 ? ($paid_count / $total_count) * 100 : 0;
                                ?>

                                <div class="w-full bg-gray-700 rounded-full h-4">
                                    <div class="bg-green-500 h-4 rounded-full" style="width: <?= $paid_percentage ?>%">
                                    </div>
                                </div>
                                <div class="flex justify-between mt-1 text-sm">
                                    <span><?= $paid_count ?> paid</span>
                                    <span><?= $pending_count ?> pending</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6">
                        <h2 class="text-xl font-semibold mb-4">Quick Actions</h2>

                        <div class="space-y-3">
                            <a href="tournaments.php?edit=<?= $tournament_details['id'] ?>"
                                class="block w-full py-3 px-4 bg-yellow-500 hover:bg-yellow-600 text-black rounded-lg transition-colors duration-200 text-center">
                                <i class="fas fa-edit mr-2"></i> Edit Tournament
                            </a>

                            <!-- Add Payment Management Button Here -->
                            <a href="tournament_payment.php?id=<?= $tournament_details['id'] ?>"
                                class="block w-full py-3 px-4 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors duration-200 text-center">
                                <i class="fas fa-money-bill-wave mr-2"></i> Manage Payments
                            </a>

                            <?php if ($tournament_details['status'] === 'upcoming'): ?>
                                <form action="tournaments.php" method="POST" class="block w-full">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="tournament_id" value="<?= $tournament_details['id'] ?>">
                                    <input type="hidden" name="new_status" value="ongoing">

                                    <button type="submit"
                                        class="w-full py-3 px-4 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors duration-200">
                                        <i class="fas fa-play mr-2"></i> Start Tournament
                                    </button>
                                </form>
                            <?php elseif ($tournament_details['status'] === 'ongoing'): ?>
                                <form action="tournaments.php" method="POST" class="block w-full">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="tournament_id" value="<?= $tournament_details['id'] ?>">
                                    <input type="hidden" name="new_status" value="completed">

                                    <button type="submit"
                                        class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                                        <i class="fas fa-flag-checkered mr-2"></i> Complete Tournament
                                    </button>
                                </form>
                            <?php endif; ?>


                            <?php if ($tournament_details['status'] !== 'cancelled' && $tournament_details['status'] !== 'completed'): ?>
                                <form action="tournaments.php" method="POST" class="block w-full"
                                    onsubmit="return confirm('Are you sure you want to cancel this tournament? This action cannot be undone.');">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="tournament_id" value="<?= $tournament_details['id'] ?>">
                                    <input type="hidden" name="new_status" value="cancelled">

                                    <button type="submit"
                                        class="w-full py-3 px-4 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors duration-200">
                                        <i class="fas fa-ban mr-2"></i> Cancel Tournament
                                    </button>
                                </form>
                            <?php endif; ?>

                            <button type="button"
                                onclick="confirmDelete(<?= $tournament_details['id'] ?>, '<?= htmlspecialchars($tournament_details['title']) ?>')"
                                class="w-full py-3 px-4 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors duration-200">
                                <i class="fas fa-trash mr-2"></i> Delete Tournament
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Participants Tab Content -->
        <div id="participantsContent" class="tab-content hidden">
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-semibold">Registered Participants</h2>

                    <?php if (!empty($participants)): ?>
                        <a href="export_participants.php?id=<?= $tournament_details['id'] ?>"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                            <i class="fas fa-download mr-2"></i> Export List
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($participants)): ?>
                    <div class="text-center py-8">
                        <div class="text-gray-400 mb-4">
                            <i class="fas fa-users text-5xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold mb-2">No Participants Yet</h3>
                        <p class="text-gray-400">Participants will appear here once they register for the tournament.</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="border-b border-gray-700">
                                    <th class="px-4 py-3 text-left">Participant</th>
                                    <th class="px-4 py-3 text-left">Contact</th>
                                    <th class="px-4 py-3 text-left">Registration Date</th>
                                    <th class="px-4 py-3 text-left">Payment Status</th>
                                    <th class="px-4 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($participants as $participant): ?>
                                    <tr class="border-b border-gray-700 hover:bg-gray-700">
                                        <td class="px-4 py-3">
                                            <div class="flex items-center">
                                                <div class="w-10 h-10 rounded-full overflow-hidden mr-3">
                                                    <?php if ($participant['profile_image']): ?>
                                                        <img src="../uploads/profile_images/<?= htmlspecialchars($participant['profile_image']) ?>"
                                                            alt="<?= htmlspecialchars($participant['username']) ?>"
                                                            class="w-full h-full object-cover">
                                                    <?php else: ?>
                                                        <div
                                                            class="w-full h-full bg-gray-600 flex items-center justify-center text-white font-bold">
                                                            <?= strtoupper(substr($participant['username'], 0, 1)) ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?= htmlspecialchars($participant['username']) ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div>
                                                <div class="text-sm"><?= htmlspecialchars($participant['email']) ?></div>
                                                <?php if ($participant['phone']): ?>
                                                    <div class="text-sm text-gray-400"><?= htmlspecialchars($participant['phone']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?= date('M d, Y', strtotime($participant['registration_date'])) ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="px-3 py-1 rounded-full text-xs font-semibold
                                                <?= $participant['payment_status'] === 'paid' ? 'bg-green-900 text-green-300' : 'bg-yellow-900 text-yellow-300' ?>">
                                                <?= ucfirst($participant['payment_status']) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right">
                                            <div class="flex justify-end space-x-2">
                                                <?php if ($participant['payment_status'] !== 'paid'): ?>
                                                    <form action="update_payment_status.php" method="POST">
                                                        <input type="hidden" name="tournament_id"
                                                            value="<?= $tournament_details['id'] ?>">
                                                        <input type="hidden" name="user_id" value="<?= $participant['user_id'] ?>">
                                                        <input type="hidden" name="status" value="paid">

                                                        <button type="submit"
                                                            class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors duration-200">
                                                            <i class="fas fa-check"></i> Mark Paid
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <form action="remove_participant.php" method="POST"
                                                    onsubmit="return confirm('Are you sure you want to remove this participant?');">
                                                    <input type="hidden" name="tournament_id"
                                                        value="<?= $tournament_details['id'] ?>">
                                                    <input type="hidden" name="user_id" value="<?= $participant['user_id'] ?>">

                                                    <button type="submit"
                                                        class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors duration-200">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Results Tab Content -->
        <div id="resultsContent" class="tab-content hidden">
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-semibold">Tournament Results</h2>

                    <?php if ($tournament_details['status'] === 'completed' && !empty($results)): ?>
                        <a href="export_results.php?id=<?= $tournament_details['id'] ?>"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                            <i class="fas fa-download mr-2"></i> Export Results
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($tournament_details['status'] === 'completed'): ?>
                    <?php if (empty($results)): ?>
                        <!-- Add Results Form -->
                        <form method="POST" action="add_results.php">
                            <input type="hidden" name="tournament_id" value="<?= $tournament_details['id'] ?>">

                            <div class="mb-6">
                                <p class="text-gray-400 mb-4">Enter the results for this tournament. Add positions, scores, and
                                    prize amounts for each participant.</p>

                                <?php if (empty($participants)): ?>
                                    <div class="bg-yellow-900 text-yellow-300 p-4 rounded-lg">
                                        <p>No participants registered for this tournament. Cannot add results.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="border-b border-gray-700">
                                                    <th class="px-4 py-3 text-left">Participant</th>
                                                    <th class="px-4 py-3 text-left">Position</th>
                                                    <th class="px-4 py-3 text-left">Score</th>
                                                    <th class="px-4 py-3 text-left">Prize Amount (₹)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($participants as $participant): ?>
                                                    <?php if ($participant['payment_status'] === 'paid'): ?>
                                                        <tr class="border-b border-gray-700">
                                                            <td class="px-4 py-3">
                                                                <div class="flex items-center">
                                                                    <div class="w-10 h-10 rounded-full overflow-hidden mr-3">
                                                                        <?php if ($participant['profile_image']): ?>
                                                                            <img src="../uploads/profile_images/<?= htmlspecialchars($participant['profile_image']) ?>"
                                                                                alt="<?= htmlspecialchars($participant['username']) ?>"
                                                                                class="w-full h-full object-cover">
                                                                        <?php else: ?>
                                                                            <div
                                                                                class="w-full h-full bg-gray-600 flex items-center justify-center text-white font-bold">
                                                                                <?= strtoupper(substr($participant['username'], 0, 1)) ?>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <?= htmlspecialchars($participant['username']) ?>
                                                                </div>
                                                            </td>
                                                            <td class="px-4 py-3">
                                                                <input type="number" name="results[<?= $participant['user_id'] ?>][position]"
                                                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-yellow-400"
                                                                    min="1" required>
                                                            </td>
                                                            <td class="px-4 py-3">
                                                                <input type="text" name="results[<?= $participant['user_id'] ?>][score]"
                                                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-yellow-400"
                                                                    required>
                                                            </td>
                                                            <td class="px-4 py-3">
                                                                <input type="number"
                                                                    name="results[<?= $participant['user_id'] ?>][prize_amount]"
                                                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-yellow-400"
                                                                    min="0" step="0.01" value="0">
                                                            </td>
                                                        </tr>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mt-6 flex justify-end">
                                        <button type="submit"
                                            class="px-6 py-3 bg-yellow-500 hover:bg-yellow-600 text-black font-medium rounded-lg transition-colors duration-200">
                                            <i class="fas fa-save mr-2"></i> Save Results
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php else: ?>
                        <!-- Display Results -->
                        <div class="space-y-6">
                            <!-- Winners Podium -->
                            <div class="flex flex-col md:flex-row items-center justify-center mb-8">
                                <?php
                                $top3 = array_filter($results, function ($r) {
                                    return $r['position'] <= 3;
                                });

                                // Sort by position
                                usort($top3, function ($a, $b) {
                                    return $a['position'] <=> $b['position'];
                                });

                                // Ensure we have exactly 3 positions
                                $podium = [
                                    2 => $top3[1] ?? null, // 2nd place (left)
                                    1 => $top3[0] ?? null, // 1st place (center)
                                    3 => $top3[2] ?? null  // 3rd place (right)
                                ];

                                $heights = [
                                    1 => 'h-32',
                                    2 => 'h-24',
                                    3 => 'h-16'
                                ];
                                ?>

                                <?php foreach ($podium as $position => $winner): ?>
                                    <?php if ($winner): ?>
                                        <div
                                            class="flex flex-col items-center mx-4 mb-4 md:mb-0 order-<?= $position === 1 ? '1' : ($position === 2 ? '0' : '2') ?>">
                                            <div
                                                class="w-20 h-20 rounded-full overflow-hidden mb-2 border-4 
                                                <?= $position === 1 ? 'border-yellow-400' : ($position === 2 ? 'border-gray-300' : 'border-yellow-700') ?>">
                                                <?php if ($winner['profile_image']): ?>
                                                    <img src="../uploads/profile_images/<?= htmlspecialchars($winner['profile_image']) ?>"
                                                        alt="<?= htmlspecialchars($winner['username']) ?>" class="w-full h-full object-cover">
                                                <?php else: ?>
                                                    <div
                                                        class="w-full h-full bg-gray-600 flex items-center justify-center text-white font-bold">
                                                        <?= strtoupper(substr($winner['username'], 0, 1)) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="text-center">
                                                <h3 class="font-medium"><?= htmlspecialchars($winner['username']) ?></h3>
                                                <div class="flex items-center justify-center">
                                                    <span
                                                        class="text-2xl 
                                                        <?= $position === 1 ? 'text-yellow-400' : ($position === 2 ? 'text-gray-300' : 'text-yellow-700') ?>">
                                                        <?php if ($position === 1): ?>
                                                            <i class="fas fa-trophy"></i>
                                                        <?php elseif ($position === 2): ?>
                                                            <i class="fas fa-medal"></i>
                                                        <?php else: ?>
                                                            <i class="fas fa-award"></i>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="ml-1"><?= $position ?></span>
                                                </div>
                                            </div>

                                            <div class="w-full <?= $heights[$position] ?> bg-gradient-to-t 
                                                <?= $position === 1 ? 'from-yellow-400 to-yellow-600' :
                                                    ($position === 2 ? 'from-gray-400 to-gray-600' : 'from-yellow-700 to-yellow-900') ?> 
                                                rounded-t-lg mt-2">
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>

                            <!-- Full Results Table -->
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead>
                                        <tr class="border-b border-gray-700">
                                            <th class="px-4 py-3 text-left">Position</th>
                                            <th class="px-4 py-3 text-left">Participant</th>
                                            <th class="px-4 py-3 text-left">Score</th>
                                            <th class="px-4 py-3 text-right">Prize</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($results as $result): ?>
                                            <tr class="border-b border-gray-700 hover:bg-gray-700">
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full 
                                                        <?php if ($result['position'] === 1): ?>
                                                            bg-yellow-400 text-black
                                                        <?php elseif ($result['position'] === 2): ?>
                                                            bg-gray-400 text-black
                                                        <?php elseif ($result['position'] === 3): ?>
                                                            bg-yellow-700 text-white
                                                        <?php else: ?>
                                                            bg-gray-600 text-white
                                                        <?php endif; ?>
                                                        font-bold">
                                                        <?= $result['position'] ?>
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div class="flex items-center">
                                                        <div class="w-10 h-10 rounded-full overflow-hidden mr-3">
                                                            <?php if ($result['profile_image']): ?>
                                                                <img src="../uploads/profile_images/<?= htmlspecialchars($result['profile_image']) ?>"
                                                                    alt="<?= htmlspecialchars($result['username']) ?>"
                                                                    class="w-full h-full object-cover">
                                                            <?php else: ?>
                                                                <div
                                                                    class="w-full h-full bg-gray-600 flex items-center justify-center text-white font-bold">
                                                                    <?= strtoupper(substr($result['username'], 0, 1)) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?= htmlspecialchars($result['username']) ?>
                                                    </div>
                                                </td>
                                                <td class="px-4 py-3"><?= htmlspecialchars($result['score']) ?></td>
                                                <td class="px-4 py-3 text-right">
                                                    <?php if ($result['prize_amount'] > 0): ?>
                                                        <span
                                                            class="text-yellow-400 font-medium">₹<?= number_format($result['prize_amount'], 0) ?></span>
                                                    <?php else: ?>
                                                        <span class="text-gray-400">-</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="flex justify-end">
                                <a href="edit_results.php?id=<?= $tournament_details['id'] ?>"
                                    class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-black rounded-lg transition-colors duration-200">
                                    <i class="fas fa-edit mr-2"></i> Edit Results
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-center py-8">
                        <div class="text-gray-400 mb-4">
                            <i class="fas fa-trophy text-5xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold mb-2">Results Not Available</h3>
                        <p class="text-gray-400 mb-6">Results will be available once the tournament is completed.</p>

                        <?php if ($tournament_details['status'] === 'ongoing'): ?>
                            <form action="tournaments.php" method="POST">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="tournament_id" value="<?= $tournament_details['id'] ?>">
                                <input type="hidden" name="new_status" value="completed">

                                <button type="submit"
                                    class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                                    <i class="fas fa-flag-checkered mr-2"></i> Mark Tournament as Completed
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($tournament_to_edit): ?>
        <!-- Edit Tournament Form -->
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 mb-8">
            <h2 class="text-2xl font-bold mb-6">Edit Tournament</h2>

            <form action="tournaments.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="tournament_id" value="<?= $tournament_to_edit['id'] ?>">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Basic Information -->
                    <div class="md:col-span-2">
                        <h3 class="text-xl font-semibold mb-4">Basic Information</h3>
                    </div>

                    <div>
                        <label for="title" class="block text-sm font-medium mb-1">Tournament Title <span
                                class="text-red-500">*</span></label>
                        <input type="text" id="title" name="title" required
                            value="<?= htmlspecialchars($tournament_to_edit['title']) ?>"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                    </div>

                    <div>
                        <label for="tournament_type" class="block text-sm font-medium mb-1">Tournament Type <span
                                class="text-red-500">*</span></label>
                        <select id="tournament_type" name="tournament_type" required
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                            <?php foreach ($tournament_types as $type): ?>
                                <option value="<?= $type ?>" <?= ($tournament_to_edit['tournament_type'] === $type) ? 'selected' : '' ?>>
                                    <?= ucwords(str_replace('_', ' ', $type)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="md:col-span-2">
                        <label for="description" class="block text-sm font-medium mb-1">Description</label>
                        <textarea id="description" name="description" rows="4"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400"><?= htmlspecialchars($tournament_to_edit['description']) ?></textarea>
                    </div>

                    <div class="md:col-span-2">
                        <label for="rules" class="block text-sm font-medium mb-1">Rules & Guidelines</label>
                        <textarea id="rules" name="rules" rows="4"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400"><?= htmlspecialchars($tournament_to_edit['rules']) ?></textarea>
                    </div>

                    <div class="md:col-span-2">
                        <label for="eligibility_criteria" class="block text-sm font-medium mb-1">Eligibility
                            Criteria</label>
                        <textarea id="eligibility_criteria" name="eligibility_criteria" rows="3"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400"><?= htmlspecialchars($tournament_to_edit['eligibility_criteria']) ?></textarea>
                    </div>

                    <!-- Dates and Capacity -->
                    <div class="md:col-span-2 mt-4">
                        <h3 class="text-xl font-semibold mb-4">Dates and Capacity</h3>
                    </div>

                    <div>
                        <label for="registration_deadline" class="block text-sm font-medium mb-1">Registration Deadline
                            <span class="text-red-500">*</span></label>
                        <input type="date" id="registration_deadline" name="registration_deadline" required
                            value="<?= $tournament_to_edit['registration_deadline'] ?>"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                    </div>

                    <div>
                        <label for="max_participants" class="block text-sm font-medium mb-1">Maximum Participants <span
                                class="text-red-500">*</span></label>
                        <input type="number" id="max_participants" name="max_participants" required min="1"
                            value="<?= $tournament_to_edit['max_participants'] ?>"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                    </div>

                    <div>
                        <label for="start_date" class="block text-sm font-medium mb-1">Start Date <span
                                class="text-red-500">*</span></label>
                        <input type="date" id="start_date" name="start_date" required
                            value="<?= $tournament_to_edit['start_date'] ?>"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                    </div>

                    <div>
                        <label for="end_date" class="block text-sm font-medium mb-1">End Date (if multi-day)</label>
                        <input type="date" id="end_date" name="end_date" value="<?= $tournament_to_edit['end_date'] ?>"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                    </div>

                    <!-- Fees and Prizes -->
                    <div class="md:col-span-2 mt-4">
                        <h3 class="text-xl font-semibold mb-4">Fees and Prizes</h3>
                    </div>

                    <div>
                        <label for="entry_fee" class="block text-sm font-medium mb-1">Entry Fee (₹)</label>
                        <input type="number" id="entry_fee" name="entry_fee" min="0" step="0.01"
                            value="<?= $tournament_to_edit['entry_fee'] ?>"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                    </div>

                    <div>
                        <label for="prize_pool" class="block text-sm font-medium mb-1">Prize Pool (₹)</label>
                        <input type="number" id="prize_pool" name="prize_pool" min="0" step="0.01"
                            value="<?= $tournament_to_edit['prize_pool'] ?>"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                    </div>

                    <div>
                        <label for="location" class="block text-sm font-medium mb-1">Location</label>
                        <input type="text" id="location" name="location"
                            value="<?= htmlspecialchars($tournament_to_edit['location'] ?? $gym['address']) ?>"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                        <p class="mt-1 text-sm text-gray-400">Leave blank to use gym address</p>
                    </div>

                    <div>
                        <label for="status" class="block text-sm font-medium mb-1">Status</label>
                        <select id="status" name="status"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400"
                            required>
                            <option value="upcoming" <?= $tournament_to_edit['status'] === 'upcoming' ? 'selected' : '' ?>>
                                Upcoming</option>
                            <option value="ongoing" <?= $tournament_to_edit['status'] === 'ongoing' ? 'selected' : '' ?>>
                                Ongoing</option>
                            <option value="completed" <?= $tournament_to_edit['status'] === 'completed' ? 'selected' : '' ?>>
                                Completed</option>
                            <option value="cancelled" <?= $tournament_to_edit['status'] === 'cancelled' ? 'selected' : '' ?>>
                                Cancelled</option>
                        </select>
                    </div>

                    <!-- Tournament Image -->
                    <div class="md:col-span-2 mt-4">
                        <h3 class="text-xl font-semibold mb-4">Tournament Image</h3>
                    </div>

                    <div class="md:col-span-2">
                        <label for="tournament_image" class="block text-sm font-medium mb-1">Tournament Image</label>
                        <?php if ($tournament_to_edit['image_path']): ?>
                            <div class="mb-2">
                                <img src="../uploads/tournament_images/<?= htmlspecialchars($tournament_to_edit['image_path']) ?>"
                                    alt="Current Tournament Image" class="h-40 object-cover rounded-lg">
                                <p class="text-sm text-gray-400 mt-1">Current image. Upload a new one to replace it.</p>
                            </div>
                        <?php endif; ?>
                        <input type="file" id="tournament_image" name="tournament_image" accept="image/*"
                            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                    </div>

                    <!-- Submit Button -->
                    <div class="md:col-span-2 mt-6 flex justify-between">
                        <a href="tournaments.php"
                            class="px-6 py-3 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors duration-200">
                            Cancel
                        </a>
                        <button type="submit"
                            class="px-6 py-3 bg-yellow-500 hover:bg-yellow-600 text-black font-medium rounded-lg transition-colors duration-200">
                            <i class="fas fa-save mr-2"></i> Update Tournament
                        </button>
                    </div>
                </div>
            </form>
        </div>

    <?php else: ?>
        <!-- Tournaments List and Add New Tournament Form -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Add New Tournament Form -->
            <div class="lg:col-span-1">
                <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 sticky top-24">
                    <h2 class="text-xl font-semibold mb-4">Add New Tournament</h2>

                    <form action="tournaments.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add">

                        <div class="mb-4">
                            <label for="title" class="block text-sm font-medium mb-1">Tournament Title <span
                                    class="text-red-500">*</span></label>
                            <input type="text" id="title" name="title" required
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                        </div>

                        <div class="mb-4">
                            <label for="tournament_type" class="block text-sm font-medium mb-1">Tournament Type <span
                                    class="text-red-500">*</span></label>
                            <select id="tournament_type" name="tournament_type" required
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                                <option value="">Select Type</option>
                                <?php foreach ($tournament_types as $type): ?>
                                    <option value="<?= $type ?>">
                                        <?= ucwords(str_replace('_', ' ', $type)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label for="description" class="block text-sm font-medium mb-1">Description</label>
                            <textarea id="description" name="description" rows="3"
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400"></textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="start_date" class="block text-sm font-medium mb-1">Start Date <span
                                        class="text-red-500">*</span></label>
                                <input type="date" id="start_date" name="start_date" required
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                            </div>

                            <div>
                                <label for="end_date" class="block text-sm font-medium mb-1">End Date</label>
                                <input type="date" id="end_date" name="end_date"
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="registration_deadline" class="block text-sm font-medium mb-1">Registration Deadline
                                <span class="text-red-500">*</span></label>
                            <input type="date" id="registration_deadline" name="registration_deadline" required
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="max_participants" class="block text-sm font-medium mb-1">Max Participants <span
                                        class="text-red-500">*</span></label>
                                <input type="number" id="max_participants" name="max_participants" min="1" value="50"
                                    required
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                            </div>

                            <div>
                                <label for="status" class="block text-sm font-medium mb-1">Status</label>
                                <select id="status" name="status"
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400"
                                    required>
                                    <option value="upcoming">Upcoming</option>
                                    <option value="ongoing">Ongoing</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="entry_fee" class="block text-sm font-medium mb-1">Entry Fee (₹)</label>
                                <input type="number" id="entry_fee" name="entry_fee" min="0" step="0.01" value="0"
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                            </div>

                            <div>
                                <label for="prize_pool" class="block text-sm font-medium mb-1">Prize Pool (₹)</label>
                                <input type="number" id="prize_pool" name="prize_pool" min="0" step="0.01" value="0"
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="rules" class="block text-sm font-medium mb-1">Rules & Guidelines</label>
                            <textarea id="rules" name="rules" rows="3"
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400"></textarea>
                        </div>

                        <div class="mb-4">
                            <label for="eligibility_criteria" class="block text-sm font-medium mb-1">Eligibility
                                Criteria</label>
                            <textarea id="eligibility_criteria" name="eligibility_criteria" rows="3"
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400"></textarea>
                        </div>
                       
<div class="mb-4">
    <label for="eligibility_type" class="block text-sm font-medium mb-1">Who Can Join This Tournament?</label>
    <select id="eligibility_type" name="eligibility_type" 
            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
        <option value="all">Anyone can join</option>
        <option value="members_only">Only gym members</option>
        <option value="premium_members">Only premium members</option>
        <option value="invite_only">Invitation only</option>
    </select>
    <p class="mt-1 text-sm text-gray-400">This determines who can register for your tournament</p>
</div>

<div id="min_membership_days_container" class="mb-4 hidden">
    <label for="min_membership_days" class="block text-sm font-medium mb-1">Minimum Membership Days</label>
    <input type="number" id="min_membership_days" name="min_membership_days" min="0" value="0"
           class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
    <p class="mt-1 text-sm text-gray-400">Minimum days a user must be a member to join (0 = no minimum)</p>
</div>

<div id="age_restriction_container" class="mb-4">
    <div class="flex items-center mb-2">
        <input type="checkbox" id="has_age_restriction" name="has_age_restriction" class="mr-2">
        <label for="has_age_restriction" class="text-sm font-medium">Set Age Restrictions</label>
    </div>
    
    <div id="age_range_inputs" class="grid grid-cols-2 gap-4 hidden">
        <div>
            <label for="min_age" class="block text-sm font-medium mb-1">Minimum Age</label>
            <input type="number" id="min_age" name="min_age" min="0" max="100" value="18"
                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
        </div>
        <div>
            <label for="max_age" class="block text-sm font-medium mb-1">Maximum Age</label>
            <input type="number" id="max_age" name="max_age" min="0" max="100" value="65"
                   class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
        </div>
    </div>
</div>

<div id="gender_restriction_container" class="mb-4">
    <label for="gender_restriction" class="block text-sm font-medium mb-1">Gender Restriction</label>
    <select id="gender_restriction" name="gender_restriction" 
            class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
        <option value="none">No restriction</option>
        <option value="male">Males only</option>
        <option value="female">Females only</option>
    </select>
</div>


                        <div class="mb-4">
                            <label for="location" class="block text-sm font-medium mb-1">Location</label>
                            <input type="text" id="location" name="location"
                                value="<?= htmlspecialchars($gym['address']) ?>"
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                            <p class="mt-1 text-sm text-gray-400">Leave blank to use gym address</p>
                        </div>

                        <div class="mb-4">
                            <label for="tournament_image" class="block text-sm font-medium mb-1">Tournament Image</label>
                            <input type="file" id="tournament_image" name="tournament_image" accept="image/*"
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-yellow-400">
                            <div id="image-preview" class="hidden mt-3">
                                <img class="h-40 object-cover rounded-lg" alt="Tournament image preview">
                            </div>
                        </div>

                        <button type="submit"
                            class="w-full py-3 px-4 bg-yellow-500 hover:bg-yellow-600 text-black font-medium rounded-lg transition-colors duration-200">
                            <i class="fas fa-plus-circle mr-2"></i> Create Tournament
                        </button>
                    </form>
                </div>
            </div>

            <!-- Tournaments List -->
            <div class="lg:col-span-2">
                <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-semibold">Your Tournaments</h2>

                        <div class="flex space-x-2">
                            <select id="statusFilter"
                                class="bg-gray-700 border border-gray-600 rounded-lg px-3 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400">
                                <option value="all">All Status</option>
                                <option value="upcoming">Upcoming</option>
                                <option value="ongoing">Ongoing</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>

                            <select id="typeFilter"
                                class="bg-gray-700 border border-gray-600 rounded-lg px-3 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400">
                                <option value="all">All Types</option>
                                <?php foreach ($tournament_types as $type): ?>
                                    <option value="<?= $type ?>">
                                        <?= ucwords(str_replace('_', ' ', $type)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if (empty($tournaments)): ?>
                        <div class="bg-gray-700 rounded-3xl p-8 text-center">
                            <div class="text-gray-400 mb-4">
                                <i class="fas fa-trophy text-5xl"></i>
                            </div>
                            <h3 class="text-xl font-semibold mb-2">No Tournaments Yet</h3>
                            <p class="text-gray-400 mb-4">You haven't created any tournaments yet.</p>
                            <p class="text-gray-400">Use the form on the left to create your first tournament!</p>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 gap-6" id="tournamentsContainer">
                            <?php foreach ($tournaments as $tournament): ?>
                                <div class="tournament-card bg-gray-700 rounded-3xl overflow-hidden shadow-sm hover:shadow-md transition-all duration-300"
                                    data-status="<?= $tournament['status'] ?>" data-type="<?= $tournament['tournament_type'] ?>">
                                    <div class="flex flex-col md:flex-row">
                                        <div class="md:w-1/4 h-48 md:h-auto">
                                            <?php if ($tournament['image_path']): ?>
                                                <img src="../uploads/tournament_images/<?= htmlspecialchars($tournament['image_path']) ?>"
                                                    alt="<?= htmlspecialchars($tournament['title']) ?>"
                                                    class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <div
                                                    class="w-full h-full bg-gradient-to-r from-yellow-400 to-yellow-600 flex items-center justify-center">
                                                    <i class="fas fa-trophy text-white text-4xl"></i>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="p-6 md:w-3/4">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <span
                                                        class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-yellow-400 text-black mb-2">
                                                        <?= ucwords(str_replace('_', ' ', $tournament['tournament_type'])) ?>
                                                    </span>
                                                    <h3 class="text-xl font-bold mb-1">
                                                        <?= htmlspecialchars($tournament['title']) ?>
                                                    </h3>
                                                    <p class="text-sm text-gray-400 mb-2">
                                                        <?= date('M d, Y', strtotime($tournament['start_date'])) ?>
                                                        <?php if ($tournament['start_date'] !== $tournament['end_date']): ?>
                                                            - <?= date('M d, Y', strtotime($tournament['end_date'])) ?>
                                                        <?php endif; ?>
                                                    </p>
                                                </div>

                                                <span class="px-3 py-1 rounded-full text-xs font-semibold
                                                    <?php
                                                    switch ($tournament['status']) {
                                                        case 'upcoming':
                                                            echo 'bg-blue-900 text-blue-300';
                                                            break;
                                                        case 'ongoing':
                                                            echo 'bg-green-900 text-green-300';
                                                            break;
                                                        case 'completed':
                                                            echo 'bg-gray-700 text-gray-300';
                                                            break;
                                                        case 'cancelled':
                                                            echo 'bg-red-900 text-red-300';
                                                            break;
                                                        default:
                                                            echo 'bg-gray-700 text-gray-300';
                                                    }
                                                    ?>">
                                                    <?= ucfirst($tournament['status']) ?>
                                                </span>
                                            </div>

                                            <p class="text-gray-300 text-sm mb-4 line-clamp-2">
                                                <?= htmlspecialchars(substr($tournament['description'], 0, 150)) . (strlen($tournament['description']) > 150 ? '...' : '') ?>
                                            </p>

                                            <div class="flex flex-wrap items-center text-sm text-gray-400 mb-4 gap-4">
                                                <span>
                                                    <i class="fas fa-users mr-1"></i>
                                                    <?= $tournament['participant_count'] ?>/<?= $tournament['max_participants'] ?>
                                                </span>
                                                <span>
                                                    <i class="fas fa-rupee-sign mr-1"></i>
                                                    Entry: ₹<?= number_format($tournament['entry_fee'], 0) ?>
                                                </span>
                                                <span>
                                                    <i class="fas fa-trophy mr-1"></i>
                                                    Prize: ₹<?= number_format($tournament['prize_pool'], 0) ?>
                                                </span>

                                                <span>
                                                    <i class="fas fa-coins mr-1"></i>
                                                    Revenue: ₹<?= number_format($tournament['revenue'] ?? 0, 0) ?>
                                                </span>
                                            </div>

                                            <div class="flex justify-between items-center">
                                                <div>
                                                    <span class="text-xs text-gray-400">
                                                        Registration Deadline:
                                                        <?= date('M d, Y', strtotime($tournament['registration_deadline'])) ?>
                                                    </span>
                                                </div>

                                                <div class="flex space-x-2">
                                                    <a href="tournaments.php?id=<?= $tournament['id'] ?>"
                                                        class="px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <a href="tournaments.php?edit=<?= $tournament['id'] ?>"
                                                        class="px-3 py-1 bg-yellow-500 hover:bg-yellow-600 text-black rounded-lg transition-colors duration-200">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="tournament_payment.php?id=<?= $tournament['id'] ?>"
                                                        class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors duration-200">
                                                        <i class="fas fa-money-bill-wave"></i>
                                                    </a>
                                                    <button type="button"
                                                        class="px-3 py-1 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors duration-200 status-dropdown-btn"
                                                        data-id="<?= $tournament['id'] ?>">
                                                        <i class="fas fa-cog"></i>
                                                    </button>


                                                    <!-- Status Dropdown -->
                                                    <div id="status-dropdown-<?= $tournament['id'] ?>"
                                                        class="status-dropdown hidden absolute right-0 mt-2 w-48 bg-gray-800 rounded-lg shadow-lg z-10">
                                                        <div class="p-2">
                                                            <h4 class="text-sm font-medium text-gray-400 mb-2">Update Status</h4>
                                                            <form action="tournaments.php" method="POST">
                                                                <input type="hidden" name="action" value="update_status">
                                                                <input type="hidden" name="tournament_id"
                                                                    value="<?= $tournament['id'] ?>">

                                                                <div class="space-y-1">
                                                                    <?php
                                                                    $statuses = ['upcoming', 'ongoing', 'completed', 'cancelled'];
                                                                    foreach ($statuses as $status):
                                                                        if ($status !== $tournament['status']):
                                                                            ?>
                                                                            <button type="submit" name="new_status" value="<?= $status ?>"
                                                                                class="w-full text-left px-3 py-2 text-sm hover:bg-gray-700 rounded">
                                                                                <?= ucfirst($status) ?>
                                                                            </button>
                                                                        <?php
                                                                        endif;
                                                                    endforeach;
                                                                    ?>
                                                                </div>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Delete Tournament Confirmation Modal -->
<div id="deleteModal"
    class="hidden fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm overflow-y-auto h-full w-full z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-3xl bg-gray-800 border-gray-700">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-900 text-red-300">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3 class="text-lg leading-6 font-medium mt-2">Delete Tournament</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-400" id="deleteModalText">
                    Are you sure you want to delete this tournament? This action cannot be undone.
                </p>
            </div>
            <div class="flex justify-between mt-4">
                <button id="cancelDelete"
                    class="px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600 focus:outline-none">
                    Cancel
                </button>
                <form id="deleteForm" action="tournaments.php" method="POST">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="tournament_id" id="deleteTournamentId" value="">
                    <button type="submit"
                        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 focus:outline-none">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
    // Handle eligibility type changes
    const eligibilityTypeSelect = document.getElementById('eligibility_type');
    const minMembershipDaysContainer = document.getElementById('min_membership_days_container');
    
    if (eligibilityTypeSelect && minMembershipDaysContainer) {
        eligibilityTypeSelect.addEventListener('change', function() {
            if (this.value === 'members_only' || this.value === 'premium_members') {
                minMembershipDaysContainer.classList.remove('hidden');
            } else {
                minMembershipDaysContainer.classList.add('hidden');
            }
        });
        
        // Trigger on page load
        eligibilityTypeSelect.dispatchEvent(new Event('change'));
    }
    
    // Handle age restriction checkbox
    const hasAgeRestrictionCheckbox = document.getElementById('has_age_restriction');
    const ageRangeInputs = document.getElementById('age_range_inputs');
    
    if (hasAgeRestrictionCheckbox && ageRangeInputs) {
        hasAgeRestrictionCheckbox.addEventListener('change', function() {
            if (this.checked) {
                ageRangeInputs.classList.remove('hidden');
            } else {
                ageRangeInputs.classList.add('hidden');
            }
        });
        
        // Trigger on page load if checkbox is checked
        if (hasAgeRestrictionCheckbox.checked) {
            ageRangeInputs.classList.remove('hidden');
        }
    }
});
    // Handle tournament status changes
    document.addEventListener('DOMContentLoaded', function () {
        // Tab switching functionality
        const detailsTab = document.getElementById('detailsTab');
        const participantsTab = document.getElementById('participantsTab');
        const resultsTab = document.getElementById('resultsTab');

        if (detailsTab && participantsTab && resultsTab) {
            const detailsContent = document.getElementById('detailsContent');
            const participantsContent = document.getElementById('participantsContent');
            const resultsContent = document.getElementById('resultsContent');

            function setActiveTab(activeTab, activeContent) {
                // Reset all tabs
                [detailsTab, participantsTab, resultsTab].forEach(tab => {
                    tab.classList.remove('active', 'border-yellow-400', 'text-yellow-400');
                    tab.classList.add('border-transparent');
                });

                // Reset all content
                [detailsContent, participantsContent, resultsContent].forEach(content => {
                    content.classList.add('hidden');
                });

                // Set active tab
                activeTab.classList.add('active', 'border-yellow-400', 'text-yellow-400');
                activeTab.classList.remove('border-transparent');

                // Show active content
                activeContent.classList.remove('hidden');
            }

            detailsTab.addEventListener('click', function () {
                setActiveTab(detailsTab, detailsContent);
            });

            participantsTab.addEventListener('click', function () {
                setActiveTab(participantsTab, participantsContent);
            });

            resultsTab.addEventListener('click', function () {
                setActiveTab(resultsTab, resultsContent);
            });
        }

        // Tournament filtering
        const statusFilter = document.getElementById('statusFilter');
        const typeFilter = document.getElementById('typeFilter');
        const tournamentsContainer = document.getElementById('tournamentsContainer');

        if (statusFilter && typeFilter && tournamentsContainer) {
            function filterTournaments() {
                const statusValue = statusFilter.value;
                const typeValue = typeFilter.value;

                const tournamentCards = tournamentsContainer.querySelectorAll('.tournament-card');

                tournamentCards.forEach(card => {
                    const cardStatus = card.getAttribute('data-status');
                    const cardType = card.getAttribute('data-type');

                    const statusMatch = statusValue === 'all' || cardStatus === statusValue;
                    const typeMatch = typeValue === 'all' || cardType === typeValue;

                    if (statusMatch && typeMatch) {
                        card.classList.remove('hidden');
                    } else {
                        card.classList.add('hidden');
                    }
                });
            }

            statusFilter.addEventListener('change', filterTournaments);
            typeFilter.addEventListener('change', filterTournaments);
        }

        // Status dropdown functionality
        const statusButtons = document.querySelectorAll('.status-dropdown-btn');

        statusButtons.forEach(button => {
            button.addEventListener('click', function (e) {
                e.stopPropagation();
                const tournamentId = this.getAttribute('data-id');
                const dropdown = document.getElementById(`status-dropdown-${tournamentId}`);

                // Close all other dropdowns
                document.querySelectorAll('.status-dropdown').forEach(d => {
                    if (d.id !== `status-dropdown-${tournamentId}`) {
                        d.classList.add('hidden');
                    }
                });

                // Toggle this dropdown
                dropdown.classList.toggle('hidden');

                // Position the dropdown
                const buttonRect = this.getBoundingClientRect();
                dropdown.style.top = `${buttonRect.bottom + window.scrollY}px`;
                dropdown.style.right = `${window.innerWidth - buttonRect.right}px`;
            });
        });

        // Close dropdowns when clicking elsewhere
        document.addEventListener('click', function () {
            document.querySelectorAll('.status-dropdown').forEach(dropdown => {
                dropdown.classList.add('hidden');
            });
        });

        // Delete tournament confirmation
        window.confirmDelete = function (tournamentId, tournamentTitle) {
            const deleteModal = document.getElementById('deleteModal');
            const deleteModalText = document.getElementById('deleteModalText');
            const deleteTournamentId = document.getElementById('deleteTournamentId');
            const cancelDelete = document.getElementById('cancelDelete');

            deleteModalText.textContent = `Are you sure you want to delete the tournament "${tournamentTitle}"? This action cannot be undone.`;
            deleteTournamentId.value = tournamentId;
            deleteModal.classList.remove('hidden');

            cancelDelete.addEventListener('click', function () {
                deleteModal.classList.add('hidden');
            });

            // Close modal when clicking outside
            deleteModal.addEventListener('click', function (e) {
                if (e.target === deleteModal) {
                    deleteModal.classList.add('hidden');
                }
            });
        };

        // Set minimum dates for date inputs
        const today = new Date().toISOString().split('T')[0];

        const registrationDeadline = document.getElementById('registration_deadline');
        const startDate = document.getElementById('start_date');
        const endDate = document.getElementById('end_date');

        if (registrationDeadline && startDate && endDate) {
            registrationDeadline.min = today;

            // Update start date min when registration deadline changes
            registrationDeadline.addEventListener('change', function () {
                startDate.min = this.value;
                if (startDate.value && startDate.value < this.value) {
                    startDate.value = this.value;
                }
            });

            // Update end date min when start date changes
            startDate.addEventListener('change', function () {
                endDate.min = this.value;
                if (endDate.value && endDate.value < this.value) {
                    endDate.value = this.value;
                }
            });

            // Set default end date to start date if empty
            startDate.addEventListener('change', function () {
                if (!endDate.value) {
                    endDate.value = this.value;
                }
            });
        }

        // Preview tournament image
        const tournamentImage = document.getElementById('tournament_image');

        if (tournamentImage) {
            tournamentImage.addEventListener('change', function () {
                if (this.files && this.files[0]) {
                    const reader = new FileReader();

                    reader.onload = function (e) {
                        // Get preview element
                        const preview = document.getElementById('image-preview');

                        if (preview) {
                            // Show preview container
                            preview.classList.remove('hidden');

                            // Update preview image
                            const img = preview.querySelector('img');
                            if (img) {
                                img.src = e.target.result;
                            }
                        }
                    };

                    reader.readAsDataURL(this.files[0]);
                }
            });
        }
    });
</script>

<?php include '../includes/footer.php'; ?>