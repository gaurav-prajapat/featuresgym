
<?php
require_once '../config/database.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /gym/views/auth/login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $plan_id = $_POST['plan_id'];
        try {
            $stmt = $conn->prepare("DELETE FROM membership_plans WHERE id = ?");
            $stmt->execute([$plan_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit();
    }

    $planData = [
        'name' => $_POST['name'],
        'description' => $_POST['description'],
        'price' => $_POST['price'],
        'duration_days' => $_POST['duration_days'],
        'features' => json_encode(array_filter(explode("\n", $_POST['features']))),
        'status' => $_POST['status']
    ];

    if (!empty($_POST['plan_id'])) {
        // Update existing plan
        $planData['id'] = $_POST['plan_id'];
        $sql = "UPDATE membership_plans SET 
                name = :name, 
                description = :description,
                price = :price,
                duration_days = :duration_days,
                features = :features,
                status = :status
                WHERE id = :id";
    } else {
        // Create new plan
        $sql = "INSERT INTO membership_plans (name, description, price, duration_days, features, status) 
                VALUES (:name, :description, :price, :duration_days, :features, :status)";
    }

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($planData);
        header('Location: membership_plans.php?success=true');
    } catch (PDOException $e) {
        header('Location: membership_plans.php?error=' . urlencode($e->getMessage()));
    }
}
?>