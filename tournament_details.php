<?php
require_once 'config/database.php';
include 'includes/navbar.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = "Please log in to view tournaments.";
    header('Location: login.php');
    exit;
}

// Check if tournament ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error'] = "Invalid tournament ID.";
    header('Location: tournaments.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];
$tournament_id = (int)$_GET['id'];

// Get tournament details
$stmt = $conn->prepare("
    SELECT t.*, g.name as gym_name, g.address, g.city, g.state, g.zip_code, g.phone, g.email,
           (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id) as participant_count,
           (SELECT COUNT(*) FROM tournament_participants WHERE tournament_id = t.id AND user_id = ?) as is_registered,
           (SELECT payment_status FROM tournament_participants WHERE tournament_id = t.id AND user_id = ?) as payment_status
    FROM gym_tournaments t
    JOIN gyms g ON t.gym_id = g.gym_id
    WHERE t.id = ?
");
$stmt->execute([$user_id, $user_id, $tournament_id]);
$tournament = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tournament) {
    $_SESSION['error'] = "Tournament not found.";
    header('Location: tournaments.php');
    exit;
}

// Check if user has active membership with this gym
$stmt = $conn->prepare("
    SELECT COUNT(*) as has_membership
    FROM user_memberships
    WHERE user_id = ? AND gym_id = ? AND status = 'active' AND end_date >= CURRENT_DATE
");
$stmt->execute([$user_id, $tournament['gym_id']]);
$membership = $stmt->fetch(PDO::FETCH_ASSOC);
$has_membership = $membership['has_membership'] > 0;


// Get tournament participants
$stmt = $conn->prepare("
    SELECT tp.id, tp.tournament_id, tp.user_id, tp.registration_date, 
           tp.notes, tp.payment_status, tp.payment_date, tp.payment_method,
           tp.transaction_id, tp.payment_notes, tp.payment_proof, tp.updated_at,
           u.username, u.profile_image
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

// Check if registration is open
$registration_open = $tournament['status'] === 'upcoming' && 
                     strtotime($tournament['registration_deadline']) >= time() && 
                     $tournament['participant_count'] < $tournament['max_participants'];

// Check eligibility based on criteria set by gym owner
$eligibility_status = [
    'eligible' => true,
    'message' => ''
];

// Check membership requirements
if ($tournament['eligibility_type'] !== 'all') {
    // Check if user has an active membership with this gym
    $stmt = $conn->prepare("
        SELECT um.*, gmp.tier 
        FROM user_memberships um
        JOIN gym_membership_plans gmp ON um.plan_id = gmp.plan_id
        WHERE um.user_id = ? AND um.gym_id = ? AND um.status = 'active' 
        AND um.end_date >= CURRENT_DATE()
        ORDER BY um.end_date DESC LIMIT 1
    ");
    $stmt->execute([$user_id, $tournament['gym_id']]);
    $active_membership = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$active_membership) {
        $eligibility_status['eligible'] = false;
        $eligibility_status['message'] = "This tournament requires an active gym membership.";
    } else {
        // Check premium membership requirement
        if ($tournament['eligibility_type'] === 'premium_members') {
            // Get the gym's premium tier names
            $stmt = $conn->prepare("
                SELECT premium_tiers FROM gyms WHERE gym_id = ?
            ");
            $stmt->execute([$tournament['gym_id']]);
            $premium_tiers_json = $stmt->fetchColumn();
            $premium_tiers = $premium_tiers_json ? json_decode($premium_tiers_json, true) : ['premium'];
            
            if (!in_array($active_membership['tier'], $premium_tiers)) {
                $eligibility_status['eligible'] = false;
                $eligibility_status['message'] = "This tournament is only open to premium members.";
            }
        }
        
        // Check minimum membership days
        if ($tournament['min_membership_days'] > 0) {
            $membership_start = new DateTime($active_membership['start_date']);
            $today = new DateTime();
            $days_as_member = $today->diff($membership_start)->days;
            
            if ($days_as_member < $tournament['min_membership_days']) {
                $eligibility_status['eligible'] = false;
                $eligibility_status['message'] = "You need to be a member for at least " . $tournament['min_membership_days'] . " days to join this tournament.";
            }
        }
    }
} elseif ($tournament['eligibility_type'] === 'invite_only') {
    // Check if user has an invitation
    $stmt = $conn->prepare("
        SELECT * FROM tournament_invitations 
        WHERE tournament_id = ? AND user_id = ? AND status = 'active'
    ");
    $stmt->execute([$tournament_id, $user_id]);
    $invitation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invitation) {
        $eligibility_status['eligible'] = false;
        $eligibility_status['message'] = "This tournament is by invitation only.";
    }
}

// Check age restrictions if set
if ($eligibility_status['eligible'] && ($tournament['min_age'] !== null || $tournament['max_age'] !== null)) {
    // Get user's date of birth
    $stmt = $conn->prepare("SELECT date_of_birth FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $dob = $stmt->fetchColumn();
    
    if ($dob) {
        $birth_date = new DateTime($dob);
        $today = new DateTime();
        $age = $today->diff($birth_date)->y;
        
        if ($tournament['min_age'] !== null && $age < $tournament['min_age']) {
            $eligibility_status['eligible'] = false;
            $eligibility_status['message'] = "This tournament requires participants to be at least " . $tournament['min_age'] . " years old.";
        } elseif ($tournament['max_age'] !== null && $age > $tournament['max_age']) {
            $eligibility_status['eligible'] = false;
            $eligibility_status['message'] = "This tournament is open to participants up to " . $tournament['max_age'] . " years old.";
        }
    } else {
        // If date of birth is not set in profile
        $eligibility_status['eligible'] = false;
        $eligibility_status['message'] = "Please update your date of birth in your profile to verify age eligibility.";
    }
}

// Check gender restrictions if set
if ($eligibility_status['eligible'] && $tournament['gender_restriction'] !== 'none') {
    // Get user's gender
    $stmt = $conn->prepare("SELECT gender FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $gender = $stmt->fetchColumn();
    
    if (!$gender) {
        $eligibility_status['eligible'] = false;
        $eligibility_status['message'] = "Please update your gender in your profile to verify eligibility.";
    } elseif ($gender !== $tournament['gender_restriction']) {
        $eligibility_status['eligible'] = false;
        $eligibility_status['message'] = "This tournament is only open to " . 
            ($tournament['gender_restriction'] === 'male' ? 'male' : 'female') . " participants.";
    }
}

// Check if user needs to pay
$needs_payment = $tournament['is_registered'] && $tournament['payment_status'] === 'pending';

// Determine if user can register
$can_register = $registration_open && $eligibility_status['eligible'] && !$tournament['is_registered'];
?>

<div class="container mx-auto px-4 py-8 pt-24">
    <!-- Back button -->
    <div class="mb-6">
        <a href="tournaments.php" class="inline-flex items-center text-yellow-400 hover:text-yellow-500">
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
                <img src="uploads/tournament_images/<?= htmlspecialchars($tournament['image_path']) ?>" 
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
                        <div class="flex items-center text-gray-300">
                            <i class="fas fa-map-marker-alt mr-2 text-yellow-400"></i>
                            <?= htmlspecialchars($tournament['gym_name']) ?>, <?= htmlspecialchars($tournament['city']) ?>
                        </div>
                    </div>
                    
                    <div class="mt-4 md:mt-0">
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
                    <p class="text-sm text-gray-400">Participants</p>
                    <p class="text-lg font-medium"><?= $tournament['participant_count'] ?> / <?= $tournament['max_participants'] ?></p>
                </div>
            </div>
            
            <!-- Registration Status & Actions -->
            <div class="mb-6">
                <?php if ($tournament['is_registered']): ?>
                    <div class="bg-gray-700 p-4 rounded-xl">
                        <div class="flex flex-col md:flex-row justify-between items-center">
                            <div class="flex items-center mb-4 md:mb-0">
                                <i class="fas fa-check-circle text-green-400 text-2xl mr-3"></i>
                                <div>
                                    <h3 class="text-lg font-semibold">You're registered for this tournament!</h3>
                                    <p class="text-xs text-gray-400 mt-1">
                                    Joined <?= isset($participant['registration_date']) ? date('M d, Y g:i A', strtotime($participant['registration_date'])) : 'Recently' ?>
                                    </p>


                                </div>
                            </div>
                            
                            <?php if ($needs_payment): ?>
                                <a href="tournament_payment.php?id=<?= $tournament_id ?>" 
                                   class="px-6 py-3 bg-yellow-500 hover:bg-yellow-600 text-black font-medium rounded-xl transition-colors duration-200">
                                    <i class="fas fa-credit-card mr-2"></i> Complete Payment
                                </a>
                            <?php elseif ($tournament['payment_status'] === 'pending_verification'): ?>
                                <div class="px-6 py-3 bg-yellow-700 text-yellow-100 font-medium rounded-xl">
                                    <i class="fas fa-clock mr-2"></i> Payment Pending Verification
                                </div>
                            <?php elseif ($tournament['payment_status'] === 'paid'): ?>
                                <div class="px-6 py-3 bg-green-700 text-green-100 font-medium rounded-xl">
                                    <i class="fas fa-check-circle mr-2"></i> Payment Confirmed
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($registration_open): ?>
                    <div class="bg-gray-700 p-4 rounded-xl">
                        <div class="flex flex-col md:flex-row justify-between items-center">
                            <div class="mb-4 md:mb-0">
                                <h3 class="text-lg font-semibold">Registration is open!</h3>
                                <p class="text-gray-400">Deadline: <?= date('F d, Y', strtotime($tournament['registration_deadline'])) ?></p>
                                
                                <?php if (!$eligibility_status['eligible']): ?>
                                    <div class="mt-2 text-red-400">
                                        <i class="fas fa-exclamation-circle mr-1"></i> <?= $eligibility_status['message'] ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($can_register): ?>
                                <form action="register_tournament.php" method="POST">
                                    <input type="hidden" name="tournament_id" value="<?= $tournament_id ?>">
                                    <button type="submit" class="px-6 py-3 bg-yellow-500 hover:bg-yellow-600 text-black font-medium rounded-xl transition-colors duration-200">
                                        <i class="fas fa-user-plus mr-2"></i> Register Now
                                    </button>
                                </form>
                            <?php elseif (!$eligibility_status['eligible']): ?>
                                <button disabled class="px-6 py-3 bg-gray-600 text-gray-300 font-medium rounded-xl cursor-not-allowed">
                                    <i class="fas fa-user-slash mr-2"></i> Not Eligible
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-gray-700 p-4 rounded-xl">
                        <div class="flex items-center">
                            <?php if ($tournament['status'] === 'upcoming' && strtotime($tournament['registration_deadline']) < time()): ?>
                                <i class="fas fa-calendar-times text-red-400 text-2xl mr-3"></i>
                                <div>
                                    <h3 class="text-lg font-semibold">Registration is closed</h3>
                                    <p class="text-gray-400">The registration deadline has passed.</p>
                                </div>
                            <?php elseif ($tournament['status'] === 'upcoming' && $tournament['participant_count'] >= $tournament['max_participants']): ?>
                                <i class="fas fa-users-slash text-red-400 text-2xl mr-3"></i>
                                <div>
                                    <h3 class="text-lg font-semibold">Tournament is full</h3>
                                    <p class="text-gray-400">The maximum number of participants has been reached.</p>
                                </div>
                            <?php elseif ($tournament['status'] === 'ongoing'): ?>
                                <i class="fas fa-play-circle text-blue-400 text-2xl mr-3"></i>
                                <div>
                                    <h3 class="text-lg font-semibold">Tournament is in progress</h3>
                                    <p class="text-gray-400">Registration is no longer available.</p>
                                </div>
                            <?php elseif ($tournament['status'] === 'completed'): ?>
                                <i class="fas fa-trophy text-yellow-400 text-2xl mr-3"></i>
                                <div>
                                    <h3 class="text-lg font-semibold">Tournament is completed</h3>
                                    <p class="text-gray-400">Check out the results below.</p>
                                </div>
                            <?php elseif ($tournament['status'] === 'cancelled'): ?>
                                <i class="fas fa-ban text-red-400 text-2xl mr-3"></i>
                                <div>
                                    <h3 class="text-lg font-semibold">Tournament is cancelled</h3>
                                    <p class="text-gray-400">This tournament has been cancelled by the organizer.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Eligibility Requirements -->
            <div class="mb-6">
                <h3 class="text-lg font-semibold mb-3">Eligibility Requirements</h3>
                <div class="bg-gray-700 p-4 rounded-xl">
                    <ul class="space-y-2">
                        <?php if ($tournament['eligibility_type'] === 'all'): ?>
                            <li class="flex items-center">
                                <i class="fas fa-check text-green-400 mr-2"></i>
                                <span>Open to all participants</span>
                            </li>
                        <?php elseif ($tournament['eligibility_type'] === 'members_only'): ?>
                            <li class="flex items-center">
                                <i class="fas fa-id-card text-yellow-400 mr-2"></i>
                                <span>Must be an active member of <?= htmlspecialchars($tournament['gym_name']) ?></span>
                            </li>
                        <?php elseif ($tournament['eligibility_type'] === 'premium_members'): ?>
                            <li class="flex items-center">
                                <i class="fas fa-crown text-yellow-400 mr-2"></i>
                                <span>Must be a premium member of <?= htmlspecialchars($tournament['gym_name']) ?></span>
                            </li>
                        <?php elseif ($tournament['eligibility_type'] === 'invite_only'): ?>
                            <li class="flex items-center">
                                <i class="fas fa-envelope text-yellow-400 mr-2"></i>
                                <span>By invitation only</span>
                            </li>
                        <?php endif; ?>
                        
                        <?php if ($tournament['min_membership_days'] > 0): ?>
                            <li class="flex items-center">
                                <i class="fas fa-calendar-alt text-yellow-400 mr-2"></i>
                                <span>Must be a member for at least <?= $tournament['min_membership_days'] ?> days</span>
                            </li>
                        <?php endif; ?>
                        
                        <?php if ($tournament['min_age'] !== null || $tournament['max_age'] !== null): ?>
                            <li class="flex items-center">
                                <i class="fas fa-birthday-cake text-yellow-400 mr-2"></i>
                                <span>
                                    Age requirement: 
                                    <?php if ($tournament['min_age'] !== null && $tournament['max_age'] !== null): ?>
                                        Between <?= $tournament['min_age'] ?> and <?= $tournament['max_age'] ?> years
                                    <?php elseif ($tournament['min_age'] !== null): ?>
                                        Minimum <?= $tournament['min_age'] ?> years
                                    <?php else: ?>
                                        Maximum <?= $tournament['max_age'] ?> years
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endif; ?>
                        
                        <?php if ($tournament['gender_restriction'] !== 'none'): ?>
                            <li class="flex items-center">
                                <i class="fas fa-<?= $tournament['gender_restriction'] === 'male' ? 'mars' : 'venus' ?> text-yellow-400 mr-2"></i>
                                <span><?= ucfirst($tournament['gender_restriction']) ?>s only</span>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tournament Content Tabs -->
    <div class="mb-6">
        <div class="border-b border-gray-700">
            <nav class="flex -mb-px">
                <button id="detailsTab" class="tab-button active py-4 px-6 border-b-2 border-yellow-400 font-medium text-yellow-400">
                    Details
                </button>
                <button id="participantsTab" class="tab-button py-4 px-6 border-b-2 border-transparent font-medium hover:text-yellow-400">
                    Participants (<?= count($participants) ?>)
                </button>
                <?php if ($tournament['status'] === 'completed' && !empty($results)): ?>
                    <button id="resultsTab" class="tab-button py-4 px-6 border-b-2 border-transparent font-medium hover:text-yellow-400">
                        Results
                    </button>
                <?php endif; ?>
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
                
                <!-- Eligibility Criteria -->
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
                
                <!-- Gym Information -->
                <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6 mb-8">
                    <h2 class="text-xl font-semibold mb-4">Organizer Information</h2>
                    
                    <div class="space-y-4">
                        <div class="flex items-start">
                            <div class="bg-yellow-400 text-black rounded-full p-2 mr-3">
                                <i class="fas fa-dumbbell"></i>
                            </div>
                            <div>
                                <h3 class="font-medium"><?= htmlspecialchars($tournament['gym_name']) ?></h3>
                                <p class="text-gray-300">
                                    <?= htmlspecialchars($tournament['address']) ?><br>
                                    <?= htmlspecialchars($tournament['city']) ?>, <?= htmlspecialchars($tournament['state']) ?> <?= htmlspecialchars($tournament['zip_code']) ?>
                                </p>
                                </div>
                        </div>
                        
                        <div class="flex items-start">
                            <div class="bg-yellow-400 text-black rounded-full p-2 mr-3">
                                <i class="fas fa-phone"></i>
                            </div>
                            <div>
                                <h3 class="font-medium">Contact</h3>
                                <p class="text-gray-300"><?= htmlspecialchars($tournament['phone']) ?></p>
                                <p class="text-gray-300"><?= htmlspecialchars($tournament['email']) ?></p>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <a href="gym_details.php?id=<?= $tournament['gym_id'] ?>" class="inline-flex items-center text-yellow-400 hover:text-yellow-500">
                                <span>View Gym Profile</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Registration Stats -->
                <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6">
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
                        
                        <?php if ($registration_open): ?>
                            <div class="bg-blue-900 bg-opacity-50 p-4 rounded-xl text-center">
                                <p class="text-blue-300">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <?= $tournament['max_participants'] - $tournament['participant_count'] ?> spots remaining
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Participants Tab Content -->
    <div id="participantsContent" class="tab-content hidden">
        <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6">
            <h2 class="text-xl font-semibold mb-6">Registered Participants</h2>
            
            <?php if (empty($participants)): ?>
                <div class="text-center py-8">
                    <div class="text-gray-400 mb-4">
                        <i class="fas fa-users text-5xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold mb-2">No Participants Yet</h3>
                    <p class="text-gray-400">Be the first to register for this tournament!</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    <?php foreach ($participants as $participant): ?>
                        <div class="bg-gray-700 rounded-xl p-4 text-center">
                            <div class="w-16 h-16 mx-auto rounded-full overflow-hidden mb-3">
                                <?php if ($participant['profile_image']): ?>
                                    <img src="uploads/profile_images/<?= htmlspecialchars($participant['profile_image']) ?>" 
                                         alt="<?= htmlspecialchars($participant['username']) ?>" 
                                         class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full bg-gray-600 flex items-center justify-center text-white font-bold">
                                        <?= strtoupper(substr($participant['username'], 0, 1)) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <h3 class="font-medium truncate"><?= htmlspecialchars($participant['username']) ?></h3>
                            <p class="text-xs text-gray-400 mt-1">
    Joined <?= isset($participant['registration_date']) ? date('M d', strtotime($participant['registration_date'])) : 'Recently' ?>
</p>

                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Results Tab Content -->
    <?php if ($tournament['status'] === 'completed' && !empty($results)): ?>
        <div id="resultsContent" class="tab-content hidden">
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl p-6">
                <h2 class="text-xl font-semibold mb-6">Tournament Results</h2>
                
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
                                        <img src="uploads/profile_images/<?= htmlspecialchars($winner['profile_image']) ?>" 
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
                                                    <img src="uploads/profile_images/<?= htmlspecialchars($result['profile_image']) ?>" 
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
            </div>
        </div>
    <?php endif; ?>
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
                if (tab) {
                    tab.classList.remove('active', 'border-yellow-400', 'text-yellow-400');
                    tab.classList.add('border-transparent');
                }
            });
            
            // Reset all content
            [detailsContent, participantsContent, resultsContent].forEach(content => {
                if (content) {
                    content.classList.add('hidden');
                }
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
        
        if (resultsTab) {
            resultsTab.addEventListener('click', function() {
                setActiveTab(resultsTab, resultsContent);
            });
        }
    });
</script>

<?php include 'includes/footer.php'; ?>


