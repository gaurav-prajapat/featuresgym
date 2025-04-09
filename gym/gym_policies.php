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
include '../includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Manage Gym Policies</h1>
        <a href="dashboard.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
    </div>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500
