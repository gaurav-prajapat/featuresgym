<?php
require_once 'config/database.php';
include 'includes/navbar.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please log in to view tournaments.";
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$gym_filter = isset($_GET['gym_id']) ? (int)$_GET['gym_id'] : 0;

// Build the query based on filters
$query = "
    SELECT t.*, g.name as gym_name, g.city, g.state, 
           (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id) as participant_count,
           (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND user_id = ?) as is_registered
    FROM gym_tournaments t
    JOIN gyms g ON t.gym_id = g.gym_id
    WHERE 1=1
";

$params = [$user_id];

if ($status_filter !== 'all') {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
}

if ($type_filter !== 'all') {
    $query .= " AND t.tournament_type = ?";
    $params[] = $type_filter;
}

if ($gym_filter > 0) {
    $query .= " AND t.gym_id = ?";
    $params[] = $gym_filter;
}

// Only show tournaments that are not cancelled and are either upcoming or ongoing
if ($status_filter === 'all') {
    $query .= " AND t.status IN ('upcoming', 'ongoing')";
}

$query .= " ORDER BY 
    CASE 
        WHEN t.status = 'upcoming' THEN 1
        WHEN t.status = 'ongoing' THEN 2
        WHEN t.status = 'completed' THEN 3
        ELSE 4
    END,
    t.start_date ASC";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all gyms for the filter dropdown
$stmt = $conn->prepare("SELECT gym_id, name FROM gyms ORDER BY name");
$stmt->execute();
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get user's registered tournaments
$stmt = $conn->prepare("
    SELECT t.*, g.name as gym_name, tp.registration_date, tp.payment_status
    FROM tournament_participants tp
    JOIN gym_tournaments t ON tp.tournament_id = t.id
    JOIN gyms g ON t.gym_id = g.gym_id
    WHERE tp.user_id = ?
    ORDER BY t.start_date DESC
");
$stmt->execute([$user_id]);
$registered_tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mx-auto px-4 py-8 pt-24">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <h1 class="text-2xl font-bold mb-4">Fitness Tournaments</h1>
        
        <div class="mt-4 md:mt-0 flex flex-wrap gap-2">
            <select id="statusFilter" onchange="applyFilters()" class="border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400">
                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="upcoming" <?= $status_filter === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                <option value="ongoing" <?= $status_filter === 'ongoing' ? 'selected' : '' ?>>Ongoing</option>
                <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
            </select>
            
            <select id="typeFilter" onchange="applyFilters()" class="border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400">
                <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                <option value="bodybuilding" <?= $type_filter === 'bodybuilding' ? 'selected' : '' ?>>Bodybuilding</option>
                <option value="powerlifting" <?= $type_filter === 'powerlifting' ? 'selected' : '' ?>>Powerlifting</option>
                <option value="crossfit" <?= $type_filter === 'crossfit' ? 'selected' : '' ?>>CrossFit</option>
                <option value="weightlifting" <?= $type_filter === 'weightlifting' ? 'selected' : '' ?>>Weightlifting</option>
                <option value="strongman" <?= $type_filter === 'strongman' ? 'selected' : '' ?>>Strongman</option>
                <option value="fitness_challenge" <?= $type_filter === 'fitness_challenge' ? 'selected' : '' ?>>Fitness Challenge</option>
                <option value="other" <?= $type_filter === 'other' ? 'selected' : '' ?>>Other</option>
            </select>
            
            <select id="gymFilter" onchange="applyFilters()" class="border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-yellow-400">
                <option value="0" <?= $gym_filter === 0 ? 'selected' : '' ?>>All Gyms</option>
                <?php foreach ($gyms as $gym): ?>
                    <option value="<?= $gym['gym_id'] ?>" <?= $gym_filter === (int)$gym['gym_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($gym['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
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
    
    <!-- Tabs -->
    <div class="mb-6">
        <div class="border-b border-gray-700">
            <nav class="flex -mb-px">
                <button id="allTournamentsTab" class="tab-button active py-4 px-6 border-b-2 border-yellow-400 font-medium text-yellow-400">
                    All Tournaments
                </button>
                <button id="myTournamentsTab" class="tab-button py-4 px-6 border-b-2 border-transparent font-medium hover:text-yellow-400">
                    My Tournaments
                </button>
            </nav>
        </div>
    </div>
    
    <!-- All Tournaments Tab Content -->
    <div id="allTournamentsContent" class="tab-content">
        <?php if (empty($tournaments)): ?>
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-8 text-center">
                <div class="mb-4">
                    <i class="fas fa-trophy text-yellow-400 text-5xl"></i>
                </div>
                <h3 class="text-xl font-semibold mb-2">No Tournaments Found</h3>
                <p class="text-gray-400">There are no tournaments matching your filters at the moment.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($tournaments as $tournament): ?>
                    <div class="group bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden hover:shadow-xl transition-all duration-300 transform hover:-translate-y-2">
                        <div class="h-48 bg-gray-700 relative">
                            <?php if ($tournament['image_path']): ?>
                                <img src="uploads/tournament_images/<?= htmlspecialchars($tournament['image_path']) ?>" 
                                     alt="<?= htmlspecialchars($tournament['title']) ?>" 
                                     class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center bg-gradient-to-r from-yellow-400 to-yellow-600">
                                    <i class="fas fa-trophy text-white text-5xl"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="absolute top-4 right-4">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold
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
                                        default:
                                            echo 'bg-gray-700 text-gray-300';
                                    }
                                    ?>">
                                    <?= ucfirst($tournament['status']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="p-6">
                            <div class="flex justify-between items-start mb-2">
                                <h3 class="text-lg font-bold">
                                    <?= htmlspecialchars($tournament['title']) ?>
                                </h3>
                                <span class="bg-yellow-400 text-black px-2 py-1 rounded-full text-xs font-semibold">
                                    <?= ucwords(str_replace('_', ' ', $tournament['tournament_type'])) ?>
                                </span>
                            </div>
                            
                            <p class="text-sm text-gray-400 mb-4">
                                <?= htmlspecialchars(substr($tournament['description'], 0, 100)) . (strlen($tournament['description']) > 100 ? '...' : '') ?>
                            </p>
                            
                            <div class="flex items-center text-sm text-gray-400 mb-4">
                                <i class="fas fa-map-marker-alt mr-2 text-yellow-400"></i>
                                <?= htmlspecialchars($tournament['gym_name']) ?>, <?= htmlspecialchars($tournament['city']) ?>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-2 mb-4">
                                <div class="bg-gray-700 p-2 rounded-lg text-center">
                                    <p class="text-xs text-gray-400">Date</p>
                                    <p class="text-sm font-medium">
                                        <?= date('M d', strtotime($tournament['start_date'])) ?>
                                        <?php if ($tournament['start_date'] !== $tournament['end_date']): ?>
                                            - <?= date('M d', strtotime($tournament['end_date'])) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                
                                <div class="bg-gray-700 p-2 rounded-lg text-center">
                                    <p class="text-xs text-gray-400">Entry Fee</p>
                                    <p class="text-sm font-medium">
                                        ₹<?= number_format($tournament['entry_fee'], 0) ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="flex justify-between items-center">
                                <div class="text-sm text-gray-400">
                                    <span class="font-medium"><?= $tournament['participant_count'] ?></span>/<span><?= $tournament['max_participants'] ?></span> Participants
                                </div>
                                
                                <a href="tournament_details.php?id=<?= $tournament['id'] ?>" 
                                   class="inline-flex items-center px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-black rounded-lg transition-colors duration-200">
                                    <i class="fas fa-info-circle mr-2"></i> Details
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- My Tournaments Tab Content -->
    <div id="myTournamentsContent" class="tab-content hidden">
        <?php if (empty($registered_tournaments)): ?>
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-8 text-center">
                <div class="mb-4">
                    <i class="fas fa-calendar-xmark text-yellow-400 text-5xl"></i>
                </div>
                <h3 class="text-xl font-semibold mb-2">No Registered Tournaments</h3>
                <p class="text-gray-400">You haven't registered for any tournaments yet.</p>
                <button onclick="document.getElementById('allTournamentsTab').click()" 
                        class="mt-4 px-6 py-2 bg-yellow-500 hover:bg-yellow-600 text-black rounded-lg transition-colors duration-200">
                    Browse Tournaments
                </button>
            </div>
        <?php else: ?>
            <div class="space-y-6">
                <?php foreach ($registered_tournaments as $tournament): ?>
                    <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl overflow-hidden">
                        <div class="flex flex-col md:flex-row">
                            <div class="md:w-1/4 h-48 md:h-auto bg-gray-700">
                                <?php if ($tournament['image_path']): ?>
                                    <img src="uploads/tournament_images/<?= htmlspecialchars($tournament['image_path']) ?>"
                                         alt="<?= htmlspecialchars($tournament['title']) ?>" 
                                         class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center bg-gradient-to-r from-yellow-400 to-yellow-600">
                                        <i class="fas fa-trophy text-white text-5xl"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="p-6 md:w-3/4">
                                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4">
                                    <div>
                                        <h3 class="text-xl font-bold mb-1">
                                            <?= htmlspecialchars($tournament['title']) ?>
                                        </h3>
                                        <div class="flex items-center text-sm text-gray-400">
                                            <i class="fas fa-map-marker-alt mr-2 text-yellow-400"></i>
                                            <?= htmlspecialchars($tournament['gym_name']) ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-2 md:mt-0 flex items-center space-x-2">
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold
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
                                        
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold
                                            <?php 
                                            switch($tournament['payment_status']) {
                                                case 'paid':
                                                    echo 'bg-green-900 text-green-300';
                                                    break;
                                                case 'pending':
                                                    echo 'bg-yellow-900 text-yellow-300';
                                                    break;
                                                case 'failed':
                                                    echo 'bg-red-900 text-red-300';
                                                    break;
                                                default:
                                                    echo 'bg-gray-700 text-gray-300';
                                            }
                                            ?>">
                                            Payment: <?= ucfirst($tournament['payment_status']) ?>
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                                    <div class="bg-gray-700 p-3 rounded-lg text-center">
                                        <p class="text-xs text-gray-400">Date</p>
                                        <p class="text-sm font-medium">
                                            <?= date('M d', strtotime($tournament['start_date'])) ?>
                                            <?php if ($tournament['start_date'] !== $tournament['end_date']): ?>
                                                - <?= date('M d', strtotime($tournament['end_date'])) ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    
                                    <div class="bg-gray-700 p-3 rounded-lg text-center">
                                        <p class="text-xs text-gray-400">Entry Fee</p>
                                        <p class="text-sm font-medium">
                                            ₹<?= number_format($tournament['entry_fee'], 0) ?>
                                        </p>
                                    </div>
                                    
                                    <div class="bg-gray-700 p-3 rounded-lg text-center">
                                        <p class="text-xs text-gray-400">Prize Pool</p>
                                        <p class="text-sm font-medium">
                                            ₹<?= number_format($tournament['prize_pool'], 0) ?>
                                        </p>
                                    </div>
                                    
                                    <div class="bg-gray-700 p-3 rounded-lg text-center">
                                        <p class="text-xs text-gray-400">Registered On</p>
                                        <p class="text-sm font-medium">
                                            <?= date('M d, Y', strtotime($tournament['registration_date'])) ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="flex flex-col md:flex-row justify-between items-center">
                                    <div class="text-sm text-gray-400 mb-4 md:mb-0">
                                        <span class="font-medium"><?= ucwords(str_replace('_', ' ', $tournament['tournament_type'])) ?></span> Tournament
                                    </div>
                                    
                                    <div class="flex space-x-3">
                                        <?php if ($tournament['payment_status'] === 'pending' && $tournament['status'] !== 'cancelled'): ?>
                                            <a href="tournament_payment.php?id=<?= $tournament['id'] ?>" 
                                               class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors duration-200">
                                                <i class="fas fa-credit-card mr-2"></i> Pay Now
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a href="tournament_details.php?id=<?= $tournament['id'] ?>" 
                                           class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-black rounded-lg transition-colors duration-200">
                                            <i class="fas fa-info-circle mr-2"></i> Details
                                        </a>
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

<script>
    // Tab switching functionality
    document.addEventListener('DOMContentLoaded', function() {
        const allTournamentsTab = document.getElementById('allTournamentsTab');
        const myTournamentsTab = document.getElementById('myTournamentsTab');
        const allTournamentsContent = document.getElementById('allTournamentsContent');
        const myTournamentsContent = document.getElementById('myTournamentsContent');
        
        allTournamentsTab.addEventListener('click', function() {
            // Update tab buttons
            allTournamentsTab.classList.add('active', 'border-yellow-400', 'text-yellow-400');
            allTournamentsTab.classList.remove('border-transparent');
            myTournamentsTab.classList.remove('active', 'border-yellow-400', 'text-yellow-400');
            myTournamentsTab.classList.add('border-transparent');
            
            // Show/hide content
            allTournamentsContent.classList.remove('hidden');
            myTournamentsContent.classList.add('hidden');
        });
        
        myTournamentsTab.addEventListener('click', function() {
            // Update tab buttons
            myTournamentsTab.classList.add('active', 'border-yellow-400', 'text-yellow-400');
            myTournamentsTab.classList.remove('border-transparent');
            allTournamentsTab.classList.remove('active', 'border-yellow-400', 'text-yellow-400');
            allTournamentsTab.classList.add('border-transparent');
            
            // Show/hide content
            myTournamentsContent.classList.remove('hidden');
            allTournamentsContent.classList.add('hidden');
        });
    });
    
    // Filter functionality
    function applyFilters() {
        const statusFilter = document.getElementById('statusFilter').value;
        const typeFilter = document.getElementById('typeFilter').value;
        const gymFilter = document.getElementById('gymFilter').value;
        
        let url = 'tournaments.php?';
        
        if (statusFilter !== 'all') {
            url += 'status=' + statusFilter + '&';
        }
        
        if (typeFilter !== 'all') {
            url += 'type=' + typeFilter + '&';
        }
        
        if (gymFilter !== '0') {
            url += 'gym_id=' + gymFilter + '&';
        }
        
        // Remove trailing & if exists
        if (url.endsWith('&')) {
            url = url.slice(0, -1);
        }
        
        window.location.href = url;
    }
</script>

<?php include 'includes/footer.php'; ?>

