<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth_check.php';

// Check if user is logged in as gym owner
if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit();
}

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Get owner's gyms
$owner_id = $_SESSION['owner_id'];
$stmt = $conn->prepare("SELECT gym_id, name FROM gyms WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if owner has any gyms
if (empty($gyms)) {
    $_SESSION['error'] = "You don't have any gyms registered. Please add a gym first.";
    header('Location: dashboard.php');
    exit();
}

// Get selected gym
$selected_gym_id = $_GET['gym_id'] ?? $gyms[0]['gym_id'];

// Validate that the gym belongs to the owner
$gym_belongs_to_owner = false;
foreach ($gyms as $gym) {
    if ($gym['gym_id'] == $selected_gym_id) {
        $gym_belongs_to_owner = true;
        break;
    }
}

if (!$gym_belongs_to_owner) {
    $_SESSION['error'] = "You don't have permission to manage this gym.";
    header('Location: dashboard.php');
    exit();
}

// Get current policies for the selected gym
$stmt = $conn->prepare("
    SELECT * FROM gym_policies 
    WHERE gym_id = ?
");
$stmt->execute([$selected_gym_id]);
$policies = $stmt->fetch(PDO::FETCH_ASSOC);

// If no policies exist, get values from the gyms table
if (!$policies) {
    $stmt = $conn->prepare("
        SELECT 
            cancellation_policy, reschedule_policy, late_fee_policy,
            cancellation_fee_amount, reschedule_fee_amount, late_fee_amount
        FROM gyms 
        WHERE gym_id = ?
    ");
    $stmt->execute([$selected_gym_id]);
    $gym_policies = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set default values
    $policies = [
        'cancellation_hours' => 4,
        'reschedule_hours' => 2,
        'cancellation_fee' => $gym_policies['cancellation_fee_amount'] ?? 200.00,
        'reschedule_fee' => $gym_policies['reschedule_fee_amount'] ?? 100.00,
        'late_fee' => $gym_policies['late_fee_amount'] ?? 300.00,
        'is_active' => 1
    ];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $cancellation_hours = filter_input(INPUT_POST, 'cancellation_hours', FILTER_VALIDATE_INT);
    $reschedule_hours = filter_input(INPUT_POST, 'reschedule_hours', FILTER_VALIDATE_INT);
    $cancellation_fee = filter_input(INPUT_POST, 'cancellation_fee', FILTER_VALIDATE_FLOAT);
    $reschedule_fee = filter_input(INPUT_POST, 'reschedule_fee', FILTER_VALIDATE_FLOAT);
    $late_fee = filter_input(INPUT_POST, 'late_fee', FILTER_VALIDATE_FLOAT);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validate required fields
    if ($cancellation_hours === false || $reschedule_hours === false || 
        $cancellation_fee === false || $reschedule_fee === false || $late_fee === false) {
        $_SESSION['error'] = "Please provide valid values for all fields.";
    } else {
        try {
            // Check if policies already exist
            if ($policies['id'] ?? false) {
                // Update existing policies
                $stmt = $conn->prepare("
                    UPDATE gym_policies 
                    SET 
                        cancellation_hours = ?,
                        reschedule_hours = ?,
                        cancellation_fee = ?,
                        reschedule_fee = ?,
                        late_fee = ?,
                        is_active = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $cancellation_hours,
                    $reschedule_hours,
                    $cancellation_fee,
                    $reschedule_fee,
                    $late_fee,
                    $is_active,
                    $policies['id']
                ]);
            } else {
                // Insert new policies
                $stmt = $conn->prepare("
                    INSERT INTO gym_policies (
                        gym_id, cancellation_hours, reschedule_hours,
                        cancellation_fee, reschedule_fee, late_fee,
                        is_active, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, NOW()
                    )
                ");
                $stmt->execute([
                    $selected_gym_id,
                    $cancellation_hours,
                    $reschedule_hours,
                    $cancellation_fee,
                    $reschedule_fee,
                    $late_fee,
                    $is_active
                ]);
            }
            
            // Update the gym table as well for backward compatibility
            $stmt = $conn->prepare("
                UPDATE gyms 
                SET 
                    cancellation_fee_amount = ?,
                    reschedule_fee_amount = ?,
                    late_fee_amount = ?
                WHERE gym_id = ?
            ");
            $stmt->execute([
                $cancellation_fee,
                $reschedule_fee,
                $late_fee,
                $selected_gym_id
            ]);
            
            $_SESSION['success'] = "Gym policies updated successfully.";
            
            // Refresh the page to show updated data
            header("Location: gym_policies.php?gym_id=$selected_gym_id");
            exit();
            
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
        }
    }
}

// Include header
include '../includes/navbar.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Manage Gym Policies</h1>
        <a href="dashboard.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
    </div>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
            <p><?= htmlspecialchars($_SESSION['success']) ?></p>
            <?php unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p><?= htmlspecialchars($_SESSION['error']) ?></p>
            <?php unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <!-- Gym Selector -->
        <?php if (count($gyms) > 1): ?>
            <div class="bg-gray-50 px-6 py-4 border-b">
                <form action="" method="GET" class="flex items-center">
                    <label for="gym_selector" class="mr-3 font-medium text-gray-700">Select Gym:</label>
                    <select id="gym_selector" name="gym_id" class="border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" onchange="this.form.submit()">
                        <?php foreach ($gyms as $gym): ?>
                            <option value="<?= $gym['gym_id'] ?>" <?= $gym['gym_id'] == $selected_gym_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($gym['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        <?php endif; ?>
        
        <!-- Policies Form -->
        <form action="" method="POST" class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h2 class="text-lg font-semibold mb-4">Cancellation Policy</h2>
                    
                    <div class="mb-4">
                        <label for="cancellation_hours" class="block text-gray-700 font-medium mb-2">
                            Cancellation Notice Period (hours)
                        </label>
                        <input type="number" id="cancellation_hours" name="cancellation_hours" 
                               value="<?= htmlspecialchars($policies['cancellation_hours']) ?>" 
                               min="1" max="72" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-sm text-gray-500 mt-1">
                            Members must cancel at least this many hours before their scheduled session to avoid fees.
                        </p>
                    </div>
                    
                    <div class="mb-4">
                        <label for="cancellation_fee" class="block text-gray-700 font-medium mb-2">
                            Cancellation Fee (₹)
                        </label>
                        <input type="number" id="cancellation_fee" name="cancellation_fee" 
                               value="<?= htmlspecialchars($policies['cancellation_fee']) ?>" 
                               min="0" step="0.01" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-sm text-gray-500 mt-1">
                            Fee charged for late cancellations.
                        </p>
                    </div>
                </div>
                
                <div>
                    <h2 class="text-lg font-semibold mb-4">Rescheduling Policy</h2>
                    
                    <div class="mb-4">
                        <label for="reschedule_hours" class="block text-gray-700 font-medium mb-2">
                            Reschedule Notice Period (hours)
                        </label>
                        <input type="number" id="reschedule_hours" name="reschedule_hours" 
                               value="<?= htmlspecialchars($policies['reschedule_hours']) ?>" 
                               min="1" max="72" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-sm text-gray-500 mt-1">
                            Members must reschedule at least this many hours before their scheduled session to avoid fees.
                        </p>
                    </div>
                    
                    <div class="mb-4">
                        <label for="reschedule_fee" class="block text-gray-700 font-medium mb-2">
                            Reschedule Fee (₹)
                        </label>
                        <input type="number" id="reschedule_fee" name="reschedule_fee" 
                               value="<?= htmlspecialchars($policies['reschedule_fee']) ?>" 
                               min="0" step="0.01" required
                               class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <p class="text-sm text-gray-500 mt-1">
                            Fee charged for late rescheduling.
                        </p>
                    </div>
                </div>
            </div>
            
            <div class="mt-6">
                <h2 class="text-lg font-semibold mb-4">Late Arrival Policy</h2>
                
                <div class="mb-4">
                    <label for="late_fee" class="block text-gray-700 font-medium mb-2">
                        Late Fee (₹)
                    </label>
                    <input type="number" id="late_fee" name="late_fee" 
                           value="<?= htmlspecialchars($policies['late_fee']) ?>" 
                           min="0" step="0.01" required
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <p class="text-sm text-gray-500 mt-1">
                        Fee charged for members who arrive late to their scheduled sessions.
                    </p>
                </div>
            </div>
            
            <div class="mt-6">
                <div class="flex items-center">
                    <input type="checkbox" id="is_active" name="is_active" 
                           <?= $policies['is_active'] ? 'checked' : '' ?>
                           class="h-5 w-5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                    <label for="is_active" class="ml-2 block text-gray-700 font-medium">
                        Enable Policy Enforcement
                    </label>
                </div>
                <p class="text-sm text-gray-500 mt-1 ml-7">
                    When enabled, these policies will be enforced automatically. When disabled, no fees will be charged.
                </p>
            </div>
            
            <div class="mt-8 border-t border-gray-200 pt-6">
                <div class="flex justify-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                        <i class="fas fa-save mr-2"></i> Save Policies
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <div class="mt-8 bg-white rounded-lg shadow-md p-6">
        <h2 class="text-lg font-semibold mb-4">Policy Information</h2>
        
        <div class="space-y-4 text-gray-700">
            <div>
                <h3 class="font-medium">Cancellation Policy</h3>
                <p class="text-sm">
                    This policy determines how many hours in advance a member must cancel their scheduled session to avoid cancellation fees. If a member cancels with less notice than specified, they will be charged the cancellation fee.
                </p>
            </div>
            
            <div>
                <h3 class="font-medium">Rescheduling Policy</h3>
                <p class="text-sm">
                    This policy determines how many hours in advance a member must reschedule their session to avoid rescheduling fees. If a member reschedules with less notice than specified, they will be charged the rescheduling fee.
                </p>
            </div>
            
            <div>
                <h3 class="font-medium">Late Arrival Policy</h3>
                <p class="text-sm">
                    Members who arrive late to their scheduled sessions may be charged a late fee. This helps ensure that all members respect the schedule and that equipment and facilities are available as planned.
                </p>
            </div>
            
            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                    </div>
                    <div class="ml-3">
                        <h3 class="text-sm font-medium text-yellow-800">Important Note</h3>
                        <div class="text-sm text-yellow-700">
                            <p>
                                These policies are automatically enforced when enabled. Members will be notified of these policies when they book sessions at your gym. Make sure your policies are fair and clearly communicated to your members.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Simple form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const cancellationHours = document.getElementById('cancellation_hours').value;
        const rescheduleHours = document.getElementById('reschedule_hours').value;
        const cancellationFee = document.getElementById('cancellation_fee').value;
        const rescheduleFee = document.getElementById('reschedule_fee').value;
        const lateFee = document.getElementById('late_fee').value;
        
        let isValid = true;
        let errorMessage = '';
        
        if (cancellationHours < 1 || cancellationHours > 72) {
            errorMessage = 'Cancellation hours must be between 1 and 72.';
            isValid = false;
        } else if (rescheduleHours < 1 || rescheduleHours > 72) {
            errorMessage = 'Reschedule hours must be between 1 and 72.';
            isValid = false;
        } else if (cancellationFee < 0) {
            errorMessage = 'Cancellation fee cannot be negative.';
            isValid = false;
        } else if (rescheduleFee < 0) {
            errorMessage = 'Reschedule fee cannot be negative.';
            isValid = false;
        } else if (lateFee < 0) {
            errorMessage = 'Late fee cannot be negative.';
            isValid = false;
        }
        
        if (!isValid) {
            e.preventDefault();
            alert(errorMessage);
        }
    });
</script>

<?php include '../includes/footer.php'; ?>

