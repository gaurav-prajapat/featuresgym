<?php
ob_start();

require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Handle review actions (approve/reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['review_id'])) {
        $reviewId = (int)$_POST['review_id'];
        $action = $_POST['action'];
        
        if ($action === 'approve') {
            $stmt = $conn->prepare("UPDATE reviews SET status = 'approved' WHERE id = ?");
            $message = "Review approved successfully.";
        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("UPDATE reviews SET status = 'rejected' WHERE id = ?");
            $message = "Review rejected successfully.";
        }
        
        if (isset($stmt)) {
            $stmt->execute([$reviewId]);
            
            // Log the activity
            $adminId = $_SESSION['admin_id'];
            $activitySql = "
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (
                    ?, 'admin', ?, ?, ?, ?
                )
            ";
            $details = "Admin ID: {$adminId} {$action}d review ID: {$reviewId}";
            $activityStmt = $conn->prepare($activitySql);
            $activityStmt->execute([
                $adminId,
                $action . '_review',
                $details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            $_SESSION['success'] = $message;
            header('Location: pending_reviews.php');
            exit();
        }
    }
}

// Fetch pending reviews with user and gym information
$stmt = $conn->prepare("
    SELECT 
        r.id, 
        r.user_id, 
        r.gym_id, 
        r.rating, 
        r.comment, 
        r.visit_date, 
        r.created_at,
        u.username as user_name,
        u.email as user_email,
        g.name as gym_name,
        g.city as gym_city
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    JOIN gyms g ON r.gym_id = g.gym_id
    WHERE r.status = 'pending'
    ORDER BY r.created_at DESC
");
$stmt->execute();
$pendingReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total pending reviews
$countStmt = $conn->prepare("SELECT COUNT(*) FROM reviews WHERE status = 'pending'");
$countStmt->execute();
$totalPending = $countStmt->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Reviews - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .review-card {
            transition: all 0.3s ease;
        }
        .review-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-64 p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">Pending Reviews</h1>
            <div class="flex items-center">
                <span class="bg-yellow-500 text-black px-3 py-1 rounded-full text-sm font-medium">
                    <?php echo $totalPending; ?> pending
                </span>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (empty($pendingReviews)): ?>
            <div class="bg-gray-800 rounded-xl p-8 text-center">
                <i class="fas fa-check-circle text-green-500 text-5xl mb-4"></i>
                <h2 class="text-2xl font-bold mb-2">No Pending Reviews</h2>
                <p class="text-gray-400">All reviews have been moderated. Check back later for new submissions.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($pendingReviews as $review): ?>
                    <div class="review-card bg-gray-800 rounded-xl overflow-hidden">
                        <div class="p-6">
                            <div class="flex justify-between items-start mb-4">
                                <div>
                                    <h3 class="font-bold text-lg"><?php echo htmlspecialchars($review['user_name']); ?></h3>
                                    <p class="text-gray-400 text-sm"><?php echo htmlspecialchars($review['user_email']); ?></p>
                                </div>
                                <div class="flex">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="<?php echo $i <= $review['rating'] ? 'fas' : 'far'; ?> fa-star text-yellow-500"></i>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <div class="text-sm text-gray-400 mb-1">Gym:</div>
                                <div class="font-medium"><?php echo htmlspecialchars($review['gym_name']); ?></div>
                                <div class="text-sm text-gray-400"><?php echo htmlspecialchars($review['gym_city']); ?></div>
                            </div>
                            
                            <div class="mb-4">
                                <div class="text-sm text-gray-400 mb-1">Review:</div>
                                <div class="bg-gray-700 p-3 rounded-lg text-gray-300 max-h-32 overflow-y-auto">
                                    <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                                </div>
                            </div>
                            
                            <div class="flex justify-between text-sm text-gray-400">
                                <div>Visit Date: <?php echo date('M d, Y', strtotime($review['visit_date'])); ?></div>
                                <div>Submitted: <?php echo date('M d, Y', strtotime($review['created_at'])); ?></div>
                            </div>
                        </div>
                        
                        <div class="flex border-t border-gray-700">
                            <form method="POST" class="w-1/2">
                                <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="w-full py-3 bg-green-600 hover:bg-green-700 text-white font-medium transition-colors duration-200 flex items-center justify-center">
                                    <i class="fas fa-check mr-2"></i> Approve
                                </button>
                            </form>
                            
                            <form method="POST" class="w-1/2" onsubmit="return confirm('Are you sure you want to reject this review?');">
                                <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                <input type="hidden" name="action" value="reject">
                                <button type="submit" class="w-full py-3 bg-red-600 hover:bg-red-700 text-white font-medium transition-colors duration-200 flex items-center justify-center">
                                    <i class="fas fa-times mr-2"></i> Reject
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Add confirmation for reject action
        document.querySelectorAll('form[action="reject"]').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!confirm('Are you sure you want to reject this review?')) {
                    e.preventDefault();
                }
            });
        });
        
        // Expand review text on click
        document.querySelectorAll('.review-card .bg-gray-700').forEach(reviewText => {
            reviewText.addEventListener('click', function() {
                this.classList.toggle('max-h-32');
                this.classList.toggle('max-h-full');
            });
        });
    </script>
</body>
</html>
