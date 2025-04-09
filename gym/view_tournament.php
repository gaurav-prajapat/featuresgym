<?php
ob_start();
require_once '../config/database.php';
include '../includes/navbar.php';

// Check if owner is logged in
if (!isset($_SESSION['owner_id'])) {
    header("Location: login.php");
    exit;
}

// Check if tournament ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Invalid tournament ID.";
    header('Location: manage_tournaments.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];
$tournament_id = (int)$_GET['id'];

// Get gym details
$stmt = $conn->prepare("SELECT * FROM gyms WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    header("Location: add_gym.php");
    exit;
}

$gym_id = $gym['gym_id'];

// Get tournament details
$stmt = $conn->prepare("
    SELECT t.*, 
           (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id) as participant_count,
           (SELECT SUM(amount) FROM gym_revenue WHERE source_type = 'tournament' AND id = t.id) as revenue
    FROM gym_tournaments t
    WHERE t.id = ? AND t.gym_id = ?
");
$stmt->execute([$tournament_id, $gym_id]);
$tournament = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tournament) {
    $_SESSION['error'] = "Tournament not found or you don't have permission to view it.";
    header('Location: manage_tournaments.php');
    exit;
}

// Get tournament participants
$stmt = $conn->prepare("
    SELECT tp.*, u.username, u.email, u.phone, u.profile_image
    FROM tournament_participants tp
    JOIN users u ON tp.user_id = u.id
    WHERE tp.tournament_id = ?
    ORDER BY tp.registration_date
");
$stmt->execute([$tournament_id]);
$participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get tournament results if tournament is completed
$results = [];
if ($tournament['status'] === 'completed') {
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

// Process adding results
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_results'])) {
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Delete existing results
        $stmt = $conn->prepare("DELETE FROM tournament_results WHERE tournament_id = ?");
        $stmt->execute([$tournament_id]);
        
        // Add new results
        foreach ($_POST['results'] as $user_id => $result) {
            $position = (int)$result['position'];
            $score = $result['score'];
            $prize_amount = (float)$result['prize_amount'];
            
            $stmt = $conn->prepare("
                INSERT INTO tournament_results 
                (tournament_id, user_id, position, score, prize_amount, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$tournament_id, $user_id, $position, $score, $prize_amount]);
        }
        
        // Update tournament status to completed if not already
        if ($tournament['status'] !== 'completed') {
            $stmt = $conn->prepare("UPDATE gym_tournaments SET status = 'completed' WHERE id = ?");
            $stmt->execute([$tournament_id]);
        }
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success'] = "Tournament results updated successfully.";
        header("Location: view_tournament.php?id=$tournament_id");
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollBack();
        $_SESSION['error'] = "Failed to update results: " . $e->getMessage();
    }
}
?>

<div class="container mx-auto px-4 py-8 pt-24">
    <!-- Back button -->
    <div class="mb-6">
        <a href="manage_tournaments.php" class="inline-flex items-center text-yellow-400 hover:text-yellow-500">
            <i class="fas fa-arrow-left mr-2"></i> Back to Tournaments
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
    
    <!-- Tournament Header -->
    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden mb-8">
        <div class="h-64 relative">
            <?php if ($tournament['image_path']): ?>
                <img src="../uploads/tournament_images/<?= htmlspecialchars($tournament['image_path']) ?>" 
                     alt="<?= htmlspecialchars($tournament['title']) ?>" 
                     class="w-full h-full object-cover">
            <?php else: ?>
                <div class="w-full h-full flex items-center justify-center bg-gradient-to-r from-yellow-400 to-yellow-600">
                    <i class="fas fa-trophy text-white text-6xl"></i>
                </div>
            <?php endif; ?>
            
            <div class="absolute inset-0 bg-gradient-to-t from-black to-transparent opacity-70"></div>
            
            <div class="absolute bottom-0 left-0 right-0 p-6">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-end">
                    <div>
                        <span class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-yellow-400 text-black mb-2">
                            <?= ucwords(str_replace('_', ' ', $tournament['tournament_type'])) ?>
                        </span>
                        <h1 class="text-3xl font-bold text-white mb-1"><?= htmlspecialchars($tournament['title']) ?></h1>
                    </div>
                    
                    <div class="mt-4 md:mt-0 flex space-x-3">
                        <span class="px-4 py-2 rounded-full text-sm font-semibold
                            <?php 
                            switch($tournament['status']) {
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
                        
                        <a href="edit_tournament.php?id=<?= $tournament_id ?>" 
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
                        <?= date('M d', strtotime($tournament['start_date'])) ?>
                        <?php if ($tournament['start_date'] !== $tournament['end_date']): ?>
                            - <?= date('M d, Y', strtotime($tournament['end_date'])) ?>
                        <?php else: ?>
                            <?= ', ' . date('Y', strtotime($tournament['start_date'])) ?>
                        <?php endif; ?>
                    </p>
                </div>
                
                <div class="bg-gray-700 p-4 rounded-xl text-center">
                    <p class="text-sm text-gray-400">Entry Fee</p>
                    <p class="text-lg font-medium">₹<?= number_format($tournament['entry_fee'], 0) ?></p>
                </div>
                
                <div class="bg-gray-700 p-4 rounded-xl text-center">
                    <p class="text-sm text-gray-400">Prize Pool</p>
                    <p class="text-lg font-medium">₹<?= number_format($tournament['prize_pool'], 0) ?></p>
                </div>
                
                <div class="bg-gray-700 p-4 rounded-xl text-center">
                    <p class="text-sm text-gray-400">Revenue</p>
                    <p class="text-lg font-medium text-yellow-400">₹<?= number_format($tournament['revenue'] ?? 0, 0) ?></p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tournament Details Tabs -->
    <div class="mb-6">
        <div class="border-b border-gray-700">
            <nav class="flex -mb-px">
                <button id="detailsTab" class="tab-button active py-4 px-6 border-b-2 border-yellow-400 font-medium text-yellow-400">
                    Details
                </button>
                <button id="participantsTab" class="tab-button py-4 px-6 border-b-2 border-transparent font-medium hover:text-yellow-400">
                    Participants (<?= count($participants) ?>)
                </button>
                <button id="resultsTab" class="tab-button py-4 px-6 border-b-2 border-transparent font-medium hover:text-yellow-400">
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
                        <?= nl2br(htmlspecialchars($tournament['description'])) ?>
                    </div>
                </div>
                
                <!-- Rules -->
                <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 mb-8">
                    <h2 class="text-xl font-semibold mb-4">Rules & Guidelines</h2>
                    <div class="prose prose-lg max-w-none text-gray-300">
                        <?= nl2br(htmlspecialchars($tournament['rules'])) ?>
                    </div>
                </div>
                
                <!-- Eligibility -->
                <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6">
                    <h2 class="text-xl font-semibold mb-4">Eligibility Criteria</h2>
                    <div class="prose prose-lg max-w-none text-gray-300">
                        <?= nl2br(htmlspecialchars($tournament['eligibility_criteria'])) ?>
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
                                <p class="text-gray-300"><?= date('F d, Y', strtotime($tournament['registration_deadline'])) ?></p>
                            </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-yellow-400 text-black rounded-full p-2 mr-3">
                                <i class="fas fa-flag-checkered"></i>
                            </div>
                            <div>
                                <h3 class="font-medium">Tournament Start</h3>
                                <p class="text-gray-300"><?= date('F d, Y', strtotime($tournament['start_date'])) ?></p>
                            </div>
                        </div>
                        
                        <?php if ($tournament['start_date'] !== $tournament['end_date']): ?>
                            <div class="flex items-start">
                                <div class="bg-yellow-400 text-black rounded-full p-2 mr-3">
                                    <i class="fas fa-trophy"></i>
                                </div>
                                <div>
                                    <h3 class="font-medium">Tournament End</h3>
                                    <p class="text-gray-300"><?= date('F d, Y', strtotime($tournament['end_date'])) ?></p>
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
                                <div class="bg-yellow-400 h-4 rounded-full" style="width: <?= ($tournament['participant_count'] / $tournament['max_participants']) * 100 ?>%"></div>
                            </div>
                            <div class="flex justify-between mt-1 text-sm">
                                <span><?= $tournament['participant_count'] ?> registered</span>
                                <span><?= $tournament['max_participants'] ?> max</span>
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
                                <div class="bg-green-500 h-4 rounded-full" style="width: <?= $paid_percentage ?>%"></div>
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
                        <a href="edit_tournament.php?id=<?= $tournament_id ?>" 
                           class="block w-full py-3 px-4 bg-yellow-500 hover:bg-yellow-600 text-black rounded-lg transition-colors duration-200 text-center">
                            <i class="fas fa-edit mr-2"></i> Edit Tournament
                        </a>
                        
                        <?php if ($tournament['status'] === 'upcoming'): ?>
                            <form action="manage_tournaments.php" method="POST" class="block w-full">
                                <input type="hidden" name="tournament_id" value="<?= $tournament_id ?>">
                                <input type="hidden" name="update_status" value="1">
                                <input type="hidden" name="new_status" value="ongoing">
                                
                                <button type="submit" class="w-full py-3 px-4 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors duration-200">
                                    <i class="fas fa-play mr-2"></i> Start Tournament
                                </button>
                            </form>
                        <?php elseif ($tournament['status'] === 'ongoing'): ?>
                            <form action="manage_tournaments.php" method="POST" class="block w-full">
                                <input type="hidden" name="tournament_id" value="<?= $tournament_id ?>">
                                <input type="hidden" name="update_status" value="1">
                                <input type="hidden" name="new_status" value="completed">
                                
                                <button type="submit" class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                                    <i class="fas fa-flag-checkered mr-2"></i> Complete Tournament
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <?php if ($tournament['status'] !== 'cancelled' && $tournament['status'] !== 'completed'): ?>
                            <form action="manage_tournaments.php" method="POST" class="block w-full" onsubmit="return confirm('Are you sure you want to cancel this tournament? This action cannot be undone.');">
                                <input type="hidden" name="tournament_id" value="<?= $tournament_id ?>">
                                <input type="hidden" name="update_status" value="1">
                                <input type="hidden" name="new_status" value="cancelled">
                                
                                <button type="submit" class="w-full py-3 px-4 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors duration-200">
                                    <i class="fas fa-ban mr-2"></i> Cancel Tournament
                                </button>
                            </form>
                        <?php endif; ?>
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
                    <a href="export_participants.php?id=<?= $tournament_id ?>" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
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
                                                    <div class="w-full h-full bg-gray-600 flex items-center justify-center text-white font-bold">
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
                                                <div class="text-sm text-gray-400"><?= htmlspecialchars($participant['phone']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?= date('M d, Y', strtotime($participant['registration_date'])) ?>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold
                                            <?= $participant['payment_status'] === 'paid' ? 'bg-green-900 text-green-300' : 'bg-yellow-900 text-yellow-300' ?>">
                                            <?= ucfirst($participant['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <div class="flex justify-end space-x-2">
                                            <?php if ($participant['payment_status'] !== 'paid'): ?>
                                                <form action="update_payment_status.php" method="POST">
                                                    <input type="hidden" name="tournament_id" value="<?= $tournament_id ?>">
                                                    <input type="hidden" name="user_id" value="<?= $participant['user_id'] ?>">
                                                    <input type="hidden" name="status" value="paid">
                                                    
                                                    <button type="submit" class="px-3 py-1 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors duration-200">
                                                        <i class="fas fa-check"></i> Mark Paid
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form action="remove_participant.php" method="POST" onsubmit="return confirm('Are you sure you want to remove this participant?');">
                                                <input type="hidden" name="tournament_id" value="<?= $tournament_id ?>">
                                                <input type="hidden" name="user_id" value="<?= $participant['user_id'] ?>">
                                                
                                                <button type="submit" class="px-3 py-1 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors duration-200">
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
                
                <?php if ($tournament['status'] === 'completed' && !empty($results)): ?>
                    <a href="export_results.php?id=<?= $tournament_id ?>" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                        <i class="fas fa-download mr-2"></i> Export Results
                    </a>
                <?php endif; ?>
            </div>
            
            <?php if ($tournament['status'] === 'completed'): ?>
                <?php if (empty($results)): ?>
                    <!-- Add Results Form -->
                    <form method="POST" action="view_tournament.php?id=<?= $tournament_id ?>">
                        <input type="hidden" name="add_results" value="1">
                        
                        <div class="mb-6">
                            <p class="text-gray-400 mb-4">Enter the results for this tournament. Add positions, scores, and prize amounts for each participant.</p>
                            
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
                                                                        <div class="w-full h-full bg-gray-600 flex items-center justify-center text-white font-bold">
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
                                                            <input type="number" name="results[<?= $participant['user_id'] ?>][prize_amount]" 
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
                                    <button type="submit" class="px-6 py-3 bg-yellow-500 hover:bg-yellow-600 text-black font-medium rounded-lg transition-colors duration-200">
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
                            $top3 = array_filter($results, function($r) {
                                return $r['position'] <= 3;
                            });
                            
                            // Sort by position
                            usort($top3, function($a, $b) {
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
                                    <div class="flex flex-col items-center mx-4 mb-4 md:mb-0 order-<?= $position === 1 ? '1' : ($position === 2 ? '0' : '2') ?>">
                                        <div class="w-20 h-20 rounded-full overflow-hidden mb-2 border-4 
                                            <?= $position === 1 ? 'border-yellow-400' : ($position === 2 ? 'border-gray-300' : 'border-yellow-700') ?>">
                                            <?php if ($winner['profile_image']): ?>
                                                <img src="../uploads/profile_images/<?= htmlspecialchars($winner['profile_image']) ?>" 
                                                     alt="<?= htmlspecialchars($winner['username']) ?>" 
                                                     class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <div class="w-full h-full bg-gray-600 flex items-center justify-center text-white font-bold">
                                                    <?= strtoupper(substr($winner['username'], 0, 1)) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="text-center">
                                            <h3 class="font-medium"><?= htmlspecialchars($winner['username']) ?></h3>
                                            <div class="flex items-center justify-center">
                                                <span class="text-2xl 
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
                                                            <div class="w-full h-full bg-gray-600 flex items-center justify-center text-white font-bold">
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
                                                    <span class="text-yellow-400 font-medium">₹<?= number_format($result['prize_amount'], 0) ?></span>
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
                            <a href="edit_results.php?id=<?= $tournament_id ?>" class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-black rounded-lg transition-colors duration-200">
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
                    
                    <?php if ($tournament['status'] === 'ongoing'): ?>
                        <form action="manage_tournaments.php" method="POST">
                            <input type="hidden" name="tournament_id" value="<?= $tournament_id ?>">
                            <input type="hidden" name="update_status" value="1">
                            <input type="hidden" name="new_status" value="completed">
                            
                            <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors duration-200">
                                <i class="fas fa-flag-checkered mr-2"></i> Mark Tournament as Completed
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
               // Tab switching functionality
               const detailsTab = document.getElementById('detailsTab');
        const participantsTab = document.getElementById('participantsTab');
        const resultsTab = document.getElementById('resultsTab');
        
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
        
        detailsTab.addEventListener('click', function() {
            setActiveTab(detailsTab, detailsContent);
        });
        
        participantsTab.addEventListener('click', function() {
            setActiveTab(participantsTab, participantsContent);
        });
        
        resultsTab.addEventListener('click', function() {
            setActiveTab(resultsTab, resultsContent);
        });
    });
</script>

<?php include '../includes/footer.php'; ?>



