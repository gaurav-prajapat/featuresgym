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
            $end_date = $_POST['end_date'];
            $registration_deadline = $_POST['registration_deadline'];
            $max_participants = (int) $_POST['max_participants'];
            $entry_fee = (float) $_POST['entry_fee'];
            $prize_pool = (float) $_POST['prize_pool'];
            $tournament_type = $_POST['tournament_type'];
            $status = $_POST['status'];
            $rules = trim($_POST['rules']);
            $eligibility_criteria = trim($_POST['eligibility_criteria']);

            // Validate required fields
            if (empty($title) || empty($start_date) || empty($end_date)) {
                $_SESSION['error'] = "Title, start date, and end date are required fields.";
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
                        $image_name = uniqid() . '_tournament.' . $file_extension;
                        $image_path = $image_name;

                        move_uploaded_file($_FILES['tournament_image']['tmp_name'], $upload_dir . $image_name);
                    }

                    // Insert tournament into database
                    $stmt = $conn->prepare("
                        INSERT INTO gym_tournaments (
                            gym_id, title, description, start_date, end_date, 
                            registration_deadline, max_participants, entry_fee, 
                            prize_pool, tournament_type, status, rules, 
                            eligibility_criteria, image_path, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                        )
                    ");

                    $stmt->execute([
                        $gym_id,
                        $title,
                        $description,
                        $start_date,
                        $end_date,
                        $registration_deadline,
                        $max_participants,
                        $entry_fee,
                        $prize_pool,
                        $tournament_type,
                        $status,
                        $rules,
                        $eligibility_criteria,
                        $image_path
                    ]);

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
                    header('Location: tournaments.php');
                    exit;
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Database error: " . $e->getMessage();
                }
            }
        }
        // Edit existing tournament
        elseif ($_POST['action'] === 'edit' && isset($_POST['tournament_id'])) {
            $tournament_id = (int) $_POST['tournament_id'];
            $title = trim($_POST['title']);
            $description = trim($_POST['description']);
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $registration_deadline = $_POST['registration_deadline'];
            $max_participants = (int) $_POST['max_participants'];
            $entry_fee = (float) $_POST['entry_fee'];
            $prize_pool = (float) $_POST['prize_pool'];
            $tournament_type = $_POST['tournament_type'];
            $status = $_POST['status'];
            $rules = trim($_POST['rules']);
            $eligibility_criteria = trim($_POST['eligibility_criteria']);

            // Validate required fields
            if (empty($title) || empty($start_date) || empty($tournament_type) || empty($end_date) || empty($registration_deadline) || $max_participants <= 0) {
                $_SESSION['error'] = "Title, start date, and end date are required fields.";
            } else {
                if (empty($end_date)) {
                    $end_date = $start_date;
                }

                // Validate dates
                $current_date = date('Y-m-d');
                if ($registration_deadline < $current_date) {
                    $_SESSION['error'] = "Registration deadline cannot be in the past.";
                    // Redirect or return
                }

                if ($start_date < $registration_deadline) {
                    $_SESSION['error'] = "Tournament start date must be after registration deadline.";
                    // Redirect or return
                }

                if ($end_date < $start_date) {
                    $_SESSION['error'] = "Tournament end date must be after start date.";
                    // Redirect or return
                }
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
                            $image_name = uniqid() . '_tournament.' . $file_extension;
                            $image_path = $image_name;

                            move_uploaded_file($_FILES['tournament_image']['tmp_name'], $upload_dir . $image_name);
                        }

                        // Update tournament in database
                        $stmt = $conn->prepare("
                            UPDATE gym_tournaments SET
                                title = ?, description = ?, start_date = ?, end_date = ?,
                                registration_deadline = ?, max_participants = ?, entry_fee = ?,
                                prize_pool = ?, tournament_type = ?, status = ?, rules = ?,
                                eligibility_criteria = ?, image_path = ?, updated_at = NOW()
                            WHERE id = ? AND gym_id = ?
                        ");

                        $stmt->execute([
                            $title,
                            $description,
                            $start_date,
                            $end_date,
                            $registration_deadline,
                            $max_participants,
                            $entry_fee,
                            $prize_pool,
                            $tournament_type,
                            $status,
                            $rules,
                            $eligibility_criteria,
                            $image_path,
                            $tournament_id,
                            $gym_id
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
                        header('Location: tournaments.php');
                        exit;
                    }
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Database error: " . $e->getMessage();
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
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $tournament_id = (int) $_GET['id'];

    // Get tournament details
    $stmt = $conn->prepare("
        SELECT t.*, 
               COUNT(p.id) as participant_count
        FROM gym_tournaments t
        LEFT JOIN tournament_participants p ON t.id = p.tournament_id
        WHERE t.id = ? AND t.gym_id = ?
        GROUP BY t.id
    ");
    $stmt->execute([$tournament_id, $gym_id]);
    $tournament_details = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tournament_details) {
        // Get participants
        $stmt = $conn->prepare("
            SELECT p.*, u.username, u.email, u.profile_image
            FROM tournament_participants p
            JOIN users u ON p.user_id = u.id
            WHERE p.tournament_id = ?
            ORDER BY p.registration_date
        ");
        $stmt->execute([$tournament_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get tournament results if available
        $stmt = $conn->prepare("
            SELECT r.*, u.username
            FROM tournament_results r
            JOIN users u ON r.user_id = u.id
            WHERE r.tournament_id = ?
            ORDER BY r.position
        ");
        $stmt->execute([$tournament_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Process tournament status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
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
                header("Location: tournaments.php");
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournaments & Competitions - <?php echo htmlspecialchars($gym['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .tournament-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .tournament-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>

<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8 pt-24">
        <!-- Header Section -->
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-3xl font-bold ">Tournaments & Competitions</h1>
            <a href="tournaments.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
            </a>
        </div>
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold">Create New Tournament</h1>
            <a href="manage_tournaments.php"
                class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors duration-200">
                <i class="fas fa-arrow-left mr-2"></i> Back to Tournaments
            </a>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                <p><?php echo $_SESSION['error']; ?></p>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                <p><?php echo $_SESSION['success']; ?></p>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if ($tournament_details): ?>
            <!-- Tournament Details View -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
                <div class="relative">
                    <?php if ($tournament_details['image_path']): ?>
                        <img src="../uploads/tournament_images/<?php echo htmlspecialchars($tournament_details['image_path']); ?>"
                            alt="<?php echo htmlspecialchars($tournament_details['title']); ?>"
                            class="w-full h-64 object-cover">
                    <?php else: ?>
                        <div class="w-full h-64 bg-gray-300 flex items-center justify-center">
                            <i class="fas fa-trophy text-gray-500 text-5xl"></i>
                        </div>
                    <?php endif; ?>

                    <div class="absolute top-0 right-0 p-4 flex space-x-2">
                        <a href="tournaments.php?edit=<?php echo $tournament_details['id']; ?>"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <button type="button"
                            onclick="confirmDelete(<?php echo $tournament_details['id']; ?>, '<?php echo htmlspecialchars($tournament_details['title']); ?>')"
                            class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>

                    <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black to-transparent p-6">
                        <h2 class="text-3xl font-bold text-white">
                            <?php echo htmlspecialchars($tournament_details['title']); ?>
                        </h2>
                        <div class="flex flex-wrap items-center text-white mt-2">
                            <span class="mr-4">
                                <i class="fas fa-calendar mr-1"></i>
                                <?php echo date('M d, Y', strtotime($tournament_details['start_date'])); ?> -
                                <?php echo date('M d, Y', strtotime($tournament_details['end_date'])); ?>
                            </span>
                            <span class="mr-4">
                                <i class="fas fa-users mr-1"></i>
                                <?php echo $tournament_details['participant_count']; ?>/<?php echo $tournament_details['max_participants']; ?>
                                Participants
                            </span>
                            <span class="mr-4">
                                <i class="fas fa-rupee-sign mr-1"></i>
                                Entry Fee: ₹<?php echo number_format($tournament_details['entry_fee'], 2); ?>
                            </span>
                            <span>
                                <i class="fas fa-trophy mr-1"></i>
                                Prize Pool: ₹<?php echo number_format($tournament_details['prize_pool'], 2); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <div class="flex flex-wrap -mx-2 mb-6">
                        <div class="w-full md:w-1/3 px-2 mb-4 md:mb-0">
                            <div class="bg-gray-50 rounded-lg p-4">
                                <h3 class="text-lg font-semibold mb-2">Tournament Details</h3>
                                <ul class="space-y-2">
                                    <li><strong>Type:</strong>
                                        <?php echo ucwords(str_replace('_', ' ', $tournament_details['tournament_type'])); ?>
                                    </li>
                                    <li><strong>Status:</strong>
                                        <span class="px-2 py-1 rounded-full text-xs font-semibold
                                            <?php
                                            switch ($tournament_details['status']) {
                                                case 'upcoming':
                                                    echo 'bg-blue-100 text-blue-800';
                                                    break;
                                                case 'ongoing':
                                                    echo 'bg-green-100 text-green-800';
                                                    break;
                                                case 'completed':
                                                    echo 'bg-gray-100 text-gray-800';
                                                    break;
                                                case 'cancelled':
                                                    echo 'bg-red-100 text-red-800';
                                                    break;
                                                default:
                                                    echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php echo ucfirst($tournament_details['status']); ?>
                                        </span>
                                    </li>
                                    <li><strong>Registration Deadline:</strong>
                                        <?php echo date('M d, Y', strtotime($tournament_details['registration_deadline'])); ?>
                                    </li>
                                    <li><strong>Created:</strong>
                                        <?php echo date('M d, Y', strtotime($tournament_details['created_at'])); ?></li>
                                </ul>
                            </div>
                        </div>

                        <div class="w-full md:w-2/3 px-2">
                            <div class="bg-gray-50 rounded-lg p-4 h-full">
                                <h3 class="text-lg font-semibold mb-2">Description</h3>
                                <p class="text-gray-700">
                                    <?php echo nl2br(htmlspecialchars($tournament_details['description'])); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="mb-6">
                        <h3 class="text-lg font-semibold mb-2">Rules & Regulations</h3>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <?php if (!empty($tournament_details['rules'])): ?>
                                <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($tournament_details['rules'])); ?>
                                </p>
                            <?php else: ?>
                                <p class="text-gray-500">No rules specified for this tournament.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-6">
                        <h3 class="text-lg font-semibold mb-2">Eligibility Criteria</h3>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <?php if (!empty($tournament_details['eligibility_criteria'])): ?>
                                <p class="text-gray-700">
                                    <?php echo nl2br(htmlspecialchars($tournament_details['eligibility_criteria'])); ?>
                                </p>
                            <?php else: ?>
                                <p class="text-gray-500">No specific eligibility criteria for this tournament.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Participants Section -->
                    <div class="mb-6">
                        <div class="flex justify-between items-center mb-2">
                            <h3 class="text-lg font-semibold">Participants</h3>
                            <a href="export_participants.php?tournament_id=<?php echo $tournament_details['id']; ?>"
                                class="text-blue-500 hover:text-blue-700">
                                <i class="fas fa-download mr-1"></i> Export List
                            </a>
                        </div>

                        <?php if (isset($participants) && !empty($participants)): ?>
                            <div class="bg-white border rounded-lg overflow-hidden">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Participant
                                            </th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Registration Date
                                            </th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Payment Status
                                            </th>
                                            <th scope="col"
                                                class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Actions
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($participants as $participant): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="flex-shrink-0 h-10 w-10">
                                                            <?php if ($participant['profile_image']): ?>
                                                                <img class="h-10 w-10 rounded-full"
                                                                    src="../uploads/profile_images/<?php echo htmlspecialchars($participant['profile_image']); ?>"
                                                                    alt="<?php echo htmlspecialchars($participant['username']); ?>">
                                                            <?php else: ?>
                                                                <div
                                                                    class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center">
                                                                    <i class="fas fa-user text-gray-500"></i>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="ml-4">
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?php echo htmlspecialchars($participant['username']); ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">
                                                                <?php echo htmlspecialchars($participant['email']); ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo date('M d, Y', strtotime($participant['registration_date'])); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span
                                                        class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                        <?php echo $participant['payment_status'] === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                                        <?php echo ucfirst($participant['payment_status']); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <a href="participant_details.php?id=<?php echo $participant['id']; ?>"
                                                        class="text-blue-600 hover:text-blue-900 mr-3">
                                                        View
                                                    </a>
                                                    <?php if ($participant['payment_status'] === 'pending'): ?>
                                                        <a href="mark_payment.php?id=<?php echo $participant['id']; ?>&status=paid"
                                                            class="text-green-600 hover:text-green-900 mr-3">
                                                            Mark Paid
                                                        </a>
                                                    <?php endif; ?>
                                                    <button type="button"
                                                        onclick="confirmRemoveParticipant(<?php echo $participant['id']; ?>, '<?php echo htmlspecialchars($participant['username']); ?>')"
                                                        class="text-red-600 hover:text-red-900">
                                                        Remove
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="bg-gray-50 rounded-lg p-8 text-center">
                                <div class="text-gray-400 mb-4">
                                    <i class="fas fa-users text-5xl"></i>
                                </div>
                                <p class="text-gray-500">No participants have registered for this tournament yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Results Section (if tournament is completed) -->
                    <?php if ($tournament_details['status'] === 'completed'): ?>
                        <div class="mb-6">
                            <div class="flex justify-between items-center mb-2">
                                <h3 class="text-lg font-semibold">Tournament Results</h3>
                                <button type="button" onclick="showAddResultForm()"
                                    class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded">
                                    <i class="fas fa-plus"></i> Add Result
                                </button>
                            </div>

                            <?php if (isset($results) && !empty($results)): ?>
                                <div class="bg-white border rounded-lg overflow-hidden">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Position
                                                </th>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Participant
                                                </th>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Score/Result
                                                </th>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Prize
                                                </th>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($results as $result): ?>
                                                <tr>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo $result['position']; ?>
                                                            <?php if ($result['position'] == 1): ?>
                                                                <i class="fas fa-trophy text-yellow-500 ml-1"></i>
                                                            <?php elseif ($result['position'] == 2): ?>
                                                                <i class="fas fa-medal text-gray-400 ml-1"></i>
                                                            <?php elseif ($result['position'] == 3): ?>
                                                                <i class="fas fa-medal text-yellow-700 ml-1"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($result['username']); ?>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($result['score']); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        ₹<?php echo number_format($result['prize_amount'], 2); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                        <button type="button"
                                                            onclick="editResult(<?php echo $result['id']; ?>, <?php echo $result['user_id']; ?>, <?php echo $result['position']; ?>, '<?php echo htmlspecialchars($result['score']); ?>', <?php echo $result['prize_amount']; ?>)"
                                                            class="text-blue-600 hover:text-blue-900 mr-3">
                                                            Edit
                                                        </button>
                                                        <button type="button"
                                                            onclick="confirmDeleteResult(<?php echo $result['id']; ?>)"
                                                            class="text-red-600 hover:text-red-900">
                                                            Delete
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="bg-gray-50 rounded-lg p-8 text-center">
                                    <div class="text-gray-400 mb-4">
                                        <i class="fas fa-trophy text-5xl"></i>
                                    </div>
                                    <p class="text-gray-500">No results have been added for this tournament yet.</p>
                                    <button type="button" onclick="showAddResultForm()"
                                        class="mt-4 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">
                                        Add Tournament Results
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Add/Edit Result Form (hidden by default) -->
                        <div id="resultFormContainer"
                            class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
                            <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
                                <div class="mt-3 text-center">
                                    <h3 class="text-lg leading-6 font-medium text-gray-900" id="resultFormTitle">Add Tournament
                                        Result</h3>
                                    <form id="resultForm" action="process_tournament_result.php" method="POST"
                                        class="mt-4 text-left">
                                        <input type="hidden" name="tournament_id"
                                            value="<?php echo $tournament_details['id']; ?>">
                                        <input type="hidden" name="result_id" id="resultId" value="">

                                        <div class="mb-4">
                                            <label for="participant"
                                                class="block text-sm font-medium text-gray-700">Participant</label>
                                            <select id="participant" name="user_id"
                                                class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                required>
                                                <option value="">Select Participant</option>
                                                <?php foreach ($participants as $participant): ?>
                                                    <option value="<?php echo $participant['user_id']; ?>">
                                                        <?php echo htmlspecialchars($participant['username']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-4">
                                            <label for="position"
                                                class="block text-sm font-medium text-gray-700">Position</label>
                                            <input type="number" id="position" name="position" min="1"
                                                class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                required>
                                        </div>

                                        <div class="mb-4">
                                            <label for="score"
                                                class="block text-sm font-medium text-gray-700">Score/Result</label>
                                            <input type="text" id="score" name="score"
                                                class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                required>
                                        </div>

                                        <div class="mb-4">
                                            <label for="prize_amount" class="block text-sm font-medium text-gray-700">Prize
                                                Amount (₹)</label>
                                            <input type="number" id="prize_amount" name="prize_amount" min="0" step="0.01"
                                                class="mt-1 block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                required>
                                        </div>

                                        <div class="flex justify-between mt-6">
                                            <button type="button" onclick="hideResultForm()"
                                                class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-gray-700 bg-gray-200 hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                                                Cancel
                                            </button>
                                            <button type="submit"
                                                class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                                Save
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="flex justify-between">
                <a href="tournaments.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Tournaments
                </a>

                <?php if ($tournament_details['status'] === 'upcoming' || $tournament_details['status'] === 'ongoing'): ?>
                    <a href="promote_tournament.php?id=<?php echo $tournament_details['id']; ?>"
                        class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-bullhorn mr-2"></i> Promote Tournament
                    </a>
                <?php endif; ?>
            </div>

        <?php elseif ($tournament_to_edit): ?>
            <!-- Edit Tournament Form -->
            <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">Edit Tournament</h2>

                <form action="tournaments.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="tournament_id" value="<?php echo $tournament_to_edit['id']; ?>">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Tournament Title</label>
                            <input type="text" id="title" name="title"
                                value="<?php echo htmlspecialchars($tournament_to_edit['title']); ?>"
                                class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="tournament_type" class="block text-sm font-medium text-gray-700 mb-1">Tournament
                                Type</label>
                            <select id="tournament_type" name="tournament_type"
                                class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                                <option value="bodybuilding" <?php echo $tournament_to_edit['tournament_type'] === 'bodybuilding' ? 'selected' : ''; ?>>
                                    Bodybuilding</option>
                                <option value="powerlifting" <?php echo $tournament_to_edit['tournament_type'] === 'powerlifting' ? 'selected' : ''; ?>>
                                    Powerlifting</option>
                                <option value="crossfit" <?php echo $tournament_to_edit['tournament_type'] === 'crossfit' ? 'selected' : ''; ?>>CrossFit</option>
                                <option value="weightlifting" <?php echo $tournament_to_edit['tournament_type'] === 'weightlifting' ? 'selected' : ''; ?>>
                                    Weightlifting</option>
                                <option value="strongman" <?php echo $tournament_to_edit['tournament_type'] === 'strongman' ? 'selected' : ''; ?>>Strongman</option>
                                <option value="fitness_challenge" <?php echo $tournament_to_edit['tournament_type'] === 'fitness_challenge' ? 'selected' : ''; ?>>
                                    Fitness Challenge</option>
                                <option value="other" <?php echo $tournament_to_edit['tournament_type'] === 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                            <input type="date" id="start_date" name="start_date"
                                value="<?php echo $tournament_to_edit['start_date']; ?>"
                                class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                            <input type="date" id="end_date" name="end_date"
                                value="<?php echo $tournament_to_edit['end_date']; ?>"
                                class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="registration_deadline"
                                class="block text-sm font-medium text-gray-700 mb-1">Registration Deadline</label>
                            <input type="date" id="registration_deadline" name="registration_deadline"
                                value="<?php echo $tournament_to_edit['registration_deadline']; ?>"
                                class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="max_participants" class="block text-sm font-medium text-gray-700 mb-1">Maximum
                                Participants</label>
                            <input type="number" id="max_participants" name="max_participants"
                                value="<?php echo $tournament_to_edit['max_participants']; ?>" min="1"
                                class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="entry_fee" class="block text-sm font-medium text-gray-700 mb-1">Entry Fee
                                (₹)</label>
                            <input type="number" id="entry_fee" name="entry_fee"
                                value="<?php echo $tournament_to_edit['entry_fee']; ?>" min="0" step="0.01"
                                class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="prize_pool" class="block text-sm font-medium text-gray-700 mb-1">Prize Pool
                                (₹)</label>
                            <input type="number" id="prize_pool" name="prize_pool"
                                value="<?php echo $tournament_to_edit['prize_pool']; ?>" min="0" step="0.01"
                                class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                        </div>

                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select id="status" name="status"
                                class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                required>
                                <option value="upcoming" <?php echo $tournament_to_edit['status'] === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                <option value="ongoing" <?php echo $tournament_to_edit['status'] === 'ongoing' ? 'selected' : ''; ?>>Ongoing</option>
                                <option value="completed" <?php echo $tournament_to_edit['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $tournament_to_edit['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-6">
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea id="description" name="description" rows="4"
                            class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                            required><?php echo htmlspecialchars($tournament_to_edit['description']); ?></textarea>
                    </div>

                    <div class="mb-6">
                        <label for="rules" class="block text-sm font-medium text-gray-700 mb-1">Rules & Regulations</label>
                        <textarea id="rules" name="rules" rows="4"
                            class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($tournament_to_edit['rules']); ?></textarea>
                    </div>

                    <div class="mb-6">
                        <label for="eligibility_criteria" class="block text-sm font-medium text-gray-700 mb-1">Eligibility
                            Criteria</label>
                        <textarea id="eligibility_criteria" name="eligibility_criteria" rows="4"
                            class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($tournament_to_edit['eligibility_criteria']); ?></textarea>
                    </div>

                    <div class="mb-6">
                        <label for="tournament_image" class="block text-sm font-medium text-gray-700 mb-1">Tournament
                            Image</label>
                        <?php if ($tournament_to_edit['image_path']): ?>
                            <div class="mb-2">
                                <img src="../uploads/tournament_images/<?php echo htmlspecialchars($tournament_to_edit['image_path']); ?>"
                                    alt="Current Tournament Image" class="h-40 object-cover rounded-lg">
                                <p class="text-sm text-gray-500 mt-1">Current image. Upload a new one to replace it.</p>
                            </div>
                        <?php endif; ?>
                        <input type="file" id="tournament_image" name="tournament_image" accept="image/*"
                            class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="flex justify-between">
                        <a href="tournaments.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            Cancel
                        </a>
                        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            Update Tournament
                        </button>
                    </div>
                </form>
            </div>

        <?php else: ?>
            <!-- Tournaments List and Add New Tournament Form -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Add New Tournament Form -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl shadow-lg p-6 sticky top-24">
                        <h2 class="text-xl font-bold text-gray-800 mb-4">Add New Tournament</h2>

                        <form action="tournaments.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="add">

                            <div class="mb-4">
                                <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Tournament
                                    Title</label>
                                <input type="text" id="title" name="title"
                                    class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    required>
                            </div>

                            <div class="mb-4">
                                <label for="tournament_type" class="block text-sm font-medium text-gray-700 mb-1">Tournament
                                    Type</label>
                                <select id="tournament_type" name="tournament_type"
                                    class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    required>
                                    <option value="">Select Type</option>
                                    <option value="bodybuilding">Bodybuilding</option>
                                    <option value="powerlifting">Powerlifting</option>
                                    <option value="crossfit">CrossFit</option>
                                    <option value="weightlifting">Weightlifting</option>
                                    <option value="strongman">Strongman</option>
                                    <option value="fitness_challenge">Fitness Challenge</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label for="description"
                                    class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                <textarea id="description" name="description" rows="3"
                                    class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    required></textarea>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start
                                        Date</label>
                                    <input type="date" id="start_date" name="start_date"
                                        class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                </div>

                                <div>
                                    <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End
                                        Date</label>
                                    <input type="date" id="end_date" name="end_date"
                                        class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="registration_deadline"
                                    class="block text-sm font-medium text-gray-700 mb-1">Registration Deadline</label>
                                <input type="date" id="registration_deadline" name="registration_deadline"
                                    class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    required>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label for="max_participants" class="block text-sm font-medium text-gray-700 mb-1">Max
                                        Participants</label>
                                    <input type="number" id="max_participants" name="max_participants" min="1" value="50"
                                        class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                </div>

                                <div>
                                    <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                    <select id="status" name="status"
                                        class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
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
                                    <label for="entry_fee" class="block text-sm font-medium text-gray-700 mb-1">Entry Fee
                                        (₹)</label>
                                    <input type="number" id="entry_fee" name="entry_fee" min="0" step="0.01" value="0"
                                        class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                </div>

                                <div>
                                    <label for="prize_pool" class="block text-sm font-medium text-gray-700 mb-1">Prize Pool
                                        (₹)</label>
                                    <input type="number" id="prize_pool" name="prize_pool" min="0" step="0.01" value="0"
                                        class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        required>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="rules" class="block text-sm font-medium text-gray-700 mb-1">Rules &
                                    Regulations</label>
                                <textarea id="rules" name="rules" rows="3"
                                    class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>

                            <div class="mb-4">
                                <label for="eligibility_criteria"
                                    class="block text-sm font-medium text-gray-700 mb-1">Eligibility Criteria</label>
                                <textarea id="eligibility_criteria" name="eligibility_criteria" rows="3"
                                    class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>

                            <div class="mb-4">
                                <label for="tournament_image"
                                    class="block text-sm font-medium text-gray-700 mb-1">Tournament Image</label>
                                <input type="file" id="tournament_image" name="tournament_image" accept="image/*"
                                    class="border border-gray-300 rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <button type="submit"
                                class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                Create Tournament
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Tournaments List -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-bold text-gray-800">Your Tournaments</h2>

                            <div class="flex space-x-2">
                                <select id="statusFilter"
                                    class="border border-gray-300 rounded-lg px-3 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="all">All Status</option>
                                    <option value="upcoming">Upcoming</option>
                                    <option value="ongoing">Ongoing</option>
                                    <option value="completed">Completed</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>

                                <select id="typeFilter"
                                    class="border border-gray-300 rounded-lg px-3 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="all">All Types</option>
                                    <option value="bodybuilding">Bodybuilding</option>
                                    <option value="powerlifting">Powerlifting</option>
                                    <option value="crossfit">CrossFit</option>
                                    <option value="weightlifting">Weightlifting</option>
                                    <option value="strongman">Strongman</option>
                                    <option value="fitness_challenge">Fitness Challenge</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>

                        <?php if (empty($tournaments)): ?>
                            <div class="bg-gray-50 rounded-lg p-8 text-center">
                                <div class="text-gray-400 mb-4">
                                    <i class="fas fa-trophy text-5xl"></i>
                                </div>
                                <p class="text-gray-500 mb-4">You haven't created any tournaments yet.</p>
                                <p class="text-gray-500">Use the form on the left to create your first tournament!</p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 gap-6" id="tournamentsContainer">
                                <?php foreach ($tournaments as $tournament): ?>
                                    <div class="tournament-card border rounded-lg overflow-hidden shadow-sm hover:shadow-md"
                                        data-status="<?php echo $tournament['status']; ?>"
                                        data-type="<?php echo $tournament['tournament_type']; ?>">
                                        <div class="flex flex-col md:flex-row">
                                            <div class="md:w-1/4">
                                                <?php if ($tournament['image_path']): ?>
                                                    <img src="../uploads/tournament_images/<?php echo htmlspecialchars($tournament['image_path']); ?>"
                                                        alt="<?php echo htmlspecialchars($tournament['title']); ?>"
                                                        class="w-full h-full object-cover">
                                                <?php else: ?>
                                                    <div class="w-full h-full bg-gray-200 flex items-center justify-center">
                                                        <i class="fas fa-trophy text-gray-400 text-4xl"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="p-4 md:w-3/4">
                                                <div class="flex justify-between items-start">
                                                    <div>
                                                        <h3 class="text-lg font-bold text-gray-800 mb-1">
                                                            <?php echo htmlspecialchars($tournament['title']); ?>
                                                        </h3>
                                                        <p class="text-sm text-gray-500 mb-2">
                                                            <?php echo ucwords(str_replace('_', ' ', $tournament['tournament_type'])); ?>
                                                            •
                                                            <?php echo date('M d, Y', strtotime($tournament['start_date'])); ?> -
                                                            <?php echo date('M d, Y', strtotime($tournament['end_date'])); ?>
                                                        </p>
                                                    </div>

                                                    <span class="px-2 py-1 rounded-full text-xs font-semibold
                                                        <?php
                                                        switch ($tournament['status']) {
                                                            case 'upcoming':
                                                                echo 'bg-blue-100 text-blue-800';
                                                                break;
                                                            case 'ongoing':
                                                                echo 'bg-green-100 text-green-800';
                                                                break;
                                                            case 'completed':
                                                                echo 'bg-gray-100 text-gray-800';
                                                                break;
                                                            case 'cancelled':
                                                                echo 'bg-red-100 text-red-800';
                                                                break;
                                                            default:
                                                                echo 'bg-gray-100 text-gray-800';
                                                        }
                                                        ?>">
                                                        <?php echo ucfirst($tournament['status']); ?>
                                                    </span>
                                                </div>

                                                <p class="text-gray-600 text-sm mb-4 line-clamp-2">
                                                    <?php echo htmlspecialchars(substr($tournament['description'], 0, 150)) . (strlen($tournament['description']) > 150 ? '...' : ''); ?>
                                                </p>

                                                <div class="flex flex-wrap items-center text-sm text-gray-500 mb-4">
                                                    <span class="mr-4">
                                                        <i class="fas fa-users mr-1"></i>
                                                        <?php echo $tournament['participant_count']; ?>/<?php echo $tournament['max_participants']; ?>
                                                        Participants
                                                    </span>
                                                    <span class="mr-4">
                                                        <i class="fas fa-rupee-sign mr-1"></i>
                                                        Entry Fee: ₹<?php echo number_format($tournament['entry_fee'], 2); ?>
                                                    </span>
                                                    <span>
                                                        <i class="fas fa-trophy mr-1"></i>
                                                        Prize Pool: ₹<?php echo number_format($tournament['prize_pool'], 2); ?>
                                                    </span>
                                                    <span class="mr-4">
                                                        <i class="fas fa-rupee-sign mr-1"></i>
                                                        Revenue: ₹<?php echo number_format($tournament['revenue'] ?? 0, 0); ?>
                                                    </span>
                                                </div>

                                                <div class="flex justify-between items-center">
                                                    <div>
                                                        <span class="text-xs text-gray-500">
                                                            Registration Deadline:
                                                            <?php echo date('M d, Y', strtotime($tournament['registration_deadline'])); ?>
                                                        </span>

                                                    </div>

                                                    <div class="flex space-x-2">
                                                        <a href="tournaments.php?id=<?php echo $tournament['id']; ?>"
                                                            class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm">
                                                            View Details
                                                        </a>
                                                        <a href="tournaments.php?edit=<?php echo $tournament['id']; ?>"
                                                            class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </a>
                                                        <button type="button"
                                                            onclick="confirmDelete(<?php echo $tournament['id']; ?>, '<?php echo htmlspecialchars($tournament['title']); ?>')"
                                                            class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded">
                                                            <i class="fas fa-trash"></i> Delete
                                                        </button>
                                                        <button type="button" class="px-3 py-1 bg-gray-600 hover:bg-gray-700 text-white rounded-lg transition-colors duration-200 status-dropdown-btn" data-id="<?= $tournament['id'] ?>">
                                        <i class="fas fa-cog"></i>
                                    </button>
                                    
                                    <!-- Status Dropdown -->
                                    <div id="status-dropdown-<?= $tournament['id'] ?>" class="status-dropdown hidden absolute right-0 mt-2 w-48 bg-gray-800 rounded-lg shadow-lg z-10">
                                        <div class="p-2">
                                            <h4 class="text-sm font-medium text-gray-400 mb-2">Update Status</h4>
                                            <form action="manage_tournaments.php" method="POST">
                                                <input type="hidden" name="tournament_id" value="<?= $tournament['id'] ?>">
                                                <input type="hidden" name="update_status" value="1">
                                                
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
    <div id="deleteModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                    <i class="fas fa-exclamation-triangle text-red-600"></i>
                </div>
                <h3 class="text-lg leading-6 font-medium text-gray-900 mt-2">Delete Tournament</h3>
                <div class="mt-2 px-7 py-3">
                    <p class="text-sm text-gray-500" id="deleteModalText">
                        Are you sure you want to delete this tournament? This action cannot be undone.
                    </p>
                </div>
                <div class="flex justify-between mt-4">
                    <button id="cancelDelete"
                        class="px-4 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400 focus:outline-none">
                        Cancel
                    </button>
                    <form id="deleteForm" action="tournaments.php" method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="tournament_id" id="deleteTournamentId" value="">
                        <button type="submit"
                            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none">
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Remove Participant Confirmation Modal -->
    <div id="removeParticipantModal"
        class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                    <i class="fas fa-user-minus text-red-600"></i>
                </div>
                <h3 class="text-lg leading-6 font-medium text-gray-900 mt-2">Remove Participant</h3>
                <div class="mt-2 px-7 py-3">
                    <p class="text-sm text-gray-500" id="removeParticipantModalText">
                        Are you sure you want to remove this participant from the tournament?
                    </p>
                </div>
                <div class="flex justify-between mt-4">
                    <button id="cancelRemoveParticipant"
                        class="px-4 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400 focus:outline-none">
                        Cancel
                    </button>
                    <form id="removeParticipantForm" action="remove_participant.php" method="POST">
                        <input type="hidden" name="participant_id" id="removeParticipantId" value="">
                        <input type="hidden" name="tournament_id"
                            value="<?php echo isset($tournament_details) ? $tournament_details['id'] : ''; ?>">
                        <button type="submit"
                            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none">
                            Remove
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Result Confirmation Modal -->
    <div id="deleteResultModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                    <i class="fas fa-trash text-red-600"></i>
                </div>
                <h3 class="text-lg leading-6 font-medium text-gray-900 mt-2">Delete Result</h3>
                <div class="mt-2 px-7 py-3">
                    <p class="text-sm text-gray-500">
                        Are you sure you want to delete this tournament result?
                    </p>
                </div>
                <div class="flex justify-between mt-4">
                    <button id="cancelDeleteResult"
                        class="px-4 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400 focus:outline-none">
                        Cancel
                    </button>
                    <form id="deleteResultForm" action="delete_tournament_result.php" method="POST">
                        <input type="hidden" name="result_id" id="deleteResultId" value="">
                        <input type="hidden" name="tournament_id"
                            value="<?php echo isset($tournament_details) ? $tournament_details['id'] : ''; ?>">
                        <button type="submit"
                            class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none">
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tournament filtering
        document.addEventListener('DOMContentLoaded', function () {
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
            };

            // Remove participant confirmation
            window.confirmRemoveParticipant = function (participantId, participantName) {
                const modal = document.getElementById('removeParticipantModal');
                const modalText = document.getElementById('removeParticipantModalText');
                const removeParticipantId = document.getElementById('removeParticipantId');
                const cancelBtn = document.getElementById('cancelRemoveParticipant');

                modalText.textContent = `Are you sure you want to remove ${participantName} from this tournament?`;
                removeParticipantId.value = participantId;
                modal.classList.remove('hidden');

                cancelBtn.addEventListener('click', function () {
                    modal.classList.add('hidden');
                });
            };

            // Tournament result functions
            window.showAddResultForm = function () {
                const resultFormContainer = document.getElementById('resultFormContainer');
                const resultFormTitle = document.getElementById('resultFormTitle');
                const resultForm = document.getElementById('resultForm');
                const resultId = document.getElementById('resultId');

                // Reset form
                resultFormTitle.textContent = 'Add Tournament Result';
                resultForm.reset();
                resultId.value = '';

                resultFormContainer.classList.remove('hidden');
            };

            window.hideResultForm = function () {
                const resultFormContainer = document.getElementById('resultFormContainer');
                resultFormContainer.classList.add('hidden');
            };

            window.editResult = function (resultId, userId, position, score, prizeAmount) {
                const resultFormContainer = document.getElementById('resultFormContainer');
                const resultFormTitle = document.getElementById('resultFormTitle');
                const resultForm = document.getElementById('resultForm');
                const resultIdInput = document.getElementById('resultId');
                const participantSelect = document.getElementById('participant');
                const positionInput = document.getElementById('position');
                const scoreInput = document.getElementById('score');
                const prizeAmountInput = document.getElementById('prize_amount');

                // Set form values
                resultFormTitle.textContent = 'Edit Tournament Result';
                resultIdInput.value = resultId;
                participantSelect.value = userId;
                positionInput.value = position;
                scoreInput.value = score;
                prizeAmountInput.value = prizeAmount;

                resultFormContainer.classList.remove('hidden');
            };

            window.confirmDeleteResult = function (resultId) {
                const modal = document.getElementById('deleteResultModal');
                const deleteResultId = document.getElementById('deleteResultId');
                const cancelBtn = document.getElementById('cancelDeleteResult');

                deleteResultId.value = resultId;
                modal.classList.remove('hidden');

                cancelBtn.addEventListener('click', function () {
                    modal.classList.add('hidden');
                });
            };
        });
        document.addEventListener('DOMContentLoaded', function() {
        // Status dropdown functionality
        const statusButtons = document.querySelectorAll('.status-dropdown-btn');
        
        statusButtons.forEach(button => {
            button.addEventListener('click', function(e) {
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
        document.addEventListener('click', function() {
            document.querySelectorAll('.status-dropdown').forEach(dropdown => {
                dropdown.classList.add('hidden');
            });
        });
    });
        
        document.addEventListener('DOMContentLoaded', function () {
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
        });
        // Preview tournament image
        const tournamentImage = document.getElementById('tournament_image');

        if (tournamentImage) {
            tournamentImage.addEventListener('change', function () {
                if (this.files && this.files[0]) {
                    const reader = new FileReader();

                    reader.onload = function (e) {
                        // Create preview if it doesn't exist
                        let preview = document.getElementById('image-preview');

                        if (!preview) {
                            preview = document.createElement('div');
                            preview.id = 'image-preview';
                            preview.className = 'mt-3';

                            const img = document.createElement('img');
                            img.className = 'h-40 object-cover rounded-lg';
                            img.alt = 'Tournament image preview';

                            preview.appendChild(img);
                            tournamentImage.parentNode.appendChild(preview);
                        }

                        // Update preview image
                        const img = preview.querySelector('img');
                        img.src = e.target.result;
                    };

                    reader.readAsDataURL(this.files[0]);
                }
            });
        }

    </script>
</body>

</html>


<!-- review -->
 <div>
    
 </div>