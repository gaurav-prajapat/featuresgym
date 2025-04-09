<?php
session_start();
require '../config/database.php';
if (!isset($_SESSION['owner_id'])) {
    header('Location: login.html');
    exit;
}

$accountType = $_GET['type'] ?? '';
if (!in_array($accountType, ['basic', 'premium', 'business', 'unlimited'])) {
    header('Location: profile.php');
    exit;
}

$accountDetails = [
    'basic' => ['name' => 'Basic', 'limit' => 5, 'price' => 0],
    'premium' => ['name' => 'Premium', 'limit' => 10, 'price' => 999],
    'business' => ['name' => 'Business', 'limit' => 15, 'price' => 1999],
    'unlimited' => ['name' => 'Unlimited', 'limit' => 999, 'price' => 4999]
];

$selectedPlan = $accountDetails[$accountType];

// Get owner details
$ownerId = $_SESSION['owner_id'];
$db = new GymDatabase();
$conn = $db->getConnection();

$ownerSql = "SELECT * FROM gym_owners WHERE id = ?";
$ownerStmt = $conn->prepare($ownerSql);
$ownerStmt->execute([$ownerId]);
$owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);

$currentPlan = $accountDetails[$owner['account_type'] ?? 'basic'];

// Process payment and upgrade
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_upgrade'])) {
    try {
        // In a real application, you would process payment here
        
        // Update account type and gym limit
        $updateSql = "UPDATE gym_owners SET account_type = ?, gym_limit = ? WHERE id = ?";
        $updateStmt = $conn->prepare($updateSql);
        $updateStmt->execute([$accountType, $selectedPlan['limit'], $ownerId]);
        
        $message = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        Your account has been successfully upgraded to ' . $selectedPlan['name'] . '!
                    </div>';
    } catch (Exception $e) {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        Error upgrading account: ' . $e->getMessage() . '
                    </div>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upgrade Account</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-white">
    <div class="container mx-auto py-10 px-4">
        <div class="max-w-2xl mx-auto">
            <h1 class="text-3xl font-bold mb-6">Upgrade Your Account</h1>
            
            <?= $message ?>
            
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4">Upgrade to <?= $selectedPlan['name'] ?> Plan</h2>
                
                <div class="flex justify-between items-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg mb-6">
                    <div>
                        <p class="font-medium">Current Plan: <span class="text-blue-600 dark:text-blue-400"><?= $currentPlan['name'] ?></span></p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Gym Limit: <?= $currentPlan['limit'] ?></p>
                    </div>
                    <div class="text-right">
                        <p class="font-medium">New Plan: <span class="text-green-600 dark:text-green-400"><?= $selectedPlan['name'] ?></span></p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Gym Limit: <?= $selectedPlan['limit'] ?></p>
                    </div>
                </div>
                
                <div class="border-t border-gray-200 dark:border-gray-700 pt-4 mb-6">
                    <div class="flex justify-between mb-2">
                        <span>Plan Price:</span>
                        <span>₹<?= number_format($selectedPlan['price'], 2) ?>/month</span>
                    </div>
                    <?php if ($currentPlan['price'] > 0): ?>
                    <div class="flex justify-between mb-2 text-green-600 dark:text-green-400">
                        <span>Credit from Current Plan:</span>
                        <span>-₹<?= number_format($currentPlan['price'], 2) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between font-bold text-lg pt-2 border-t border-gray-200 dark:border-gray-700">
                        <span>Total Due Today:</span>
                        <span>₹<?= number_format(max(0, $selectedPlan['price'] - $currentPlan['price']), 2) ?></span>
                    </div>
                </div>
                
                <form method="POST" action="">
                    <!-- Payment details would go here in a real application -->
                    
                    <div class="flex justify-between mt-6">
                        <a href="profile.php" class="px-4 py-2 bg-gray-300 dark:bg-gray-700 text-gray-800 dark:text-white rounded hover:bg-gray-400 dark:hover:bg-gray-600">
                            Cancel
                        </a>
                        <button type="submit" name="confirm_upgrade" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                            Confirm Upgrade
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="text-center text-sm text-gray-600 dark:text-gray-400">
                <p>Need help? Contact our support team at support@example.com</p>
            </div>
        </div>
    </div>
</body>
</html>
