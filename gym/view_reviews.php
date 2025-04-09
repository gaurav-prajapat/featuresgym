<?php
include '../includes/navbar.php';
require_once '../config/database.php';

// Check if gym owner is logged in
if (!isset($_SESSION['owner_id'])) {
    header('Location: ../login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];

// Get gym information
$stmt = $conn->prepare("SELECT * FROM gyms WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    $_SESSION['error'] = "No gym found for this owner.";
    header('Location: dashboard.php');
    exit();
}

$gym_id = $gym['gym_id'];

// Pagination variables
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Get filter parameters
$rating_filter = isset($_GET['rating']) ? $_GET['rating'] : 'all';
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'newest';

// Build the query based on filters
$query = "
    SELECT r.*, u.username, u.profile_image
    FROM reviews r
    JOIN users u ON r.user_id = u.id
    WHERE r.gym_id = ?
";

$params = [$gym_id];

if ($rating_filter !== 'all') {
    $query .= " AND r.rating = ?";
    $params[] = $rating_filter;
}

if ($status_filter !== 'all') {
    $query .= " AND r.status = ?";
    $params[] = $status_filter;
}

// Add sorting
if ($sort_by === 'highest') {
    $query .= " ORDER BY r.rating DESC, r.created_at DESC";
} elseif ($sort_by === 'lowest') {
    $query .= " ORDER BY r.rating ASC, r.created_at DESC";
} elseif ($sort_by === 'oldest') {
    $query .= " ORDER BY r.created_at ASC";
} else {
    $query .= " ORDER BY r.created_at DESC";
}

// Add pagination
$query .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $conn->prepare($query);
// Bind all parameters
for ($i = 0; $i < count($params); $i++) {
    $paramType = ($i >= count($params) - 2) ? PDO::PARAM_INT : PDO::PARAM_STR;
    $stmt->bindValue($i + 1, $params[$i], $paramType);
}
$stmt->execute();

$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total reviews count for pagination
$countQuery = "
    SELECT COUNT(*) as total
    FROM reviews r
    WHERE r.gym_id = ?
";

$countParams = [$gym_id];

if ($rating_filter !== 'all') {
    $countQuery .= " AND r.rating = ?";
    $countParams[] = $rating_filter;
}

if ($status_filter !== 'all') {
    $countQuery .= " AND r.status = ?";
    $countParams[] = $status_filter;
}

$stmt = $conn->prepare($countQuery);
$stmt->execute($countParams);
$total_reviews = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_reviews / $limit);

// Get rating statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_reviews,
        AVG(rating) as average_rating,
        COUNT(CASE WHEN rating = 5 THEN 1 END) as five_star,
        COUNT(CASE WHEN rating = 4 THEN 1 END) as four_star,
        COUNT(CASE WHEN rating = 3 THEN 1 END) as three_star,
        COUNT(CASE WHEN rating = 2 THEN 1 END) as two_star,
        COUNT(CASE WHEN rating = 1 THEN 1 END) as one_star
    FROM reviews
    WHERE gym_id = ?
");
$stmt->execute([$gym_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Process review response
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_to_review'])) {
    $review_id = $_POST['review_id'];
    $response = $_POST['response'];
    
    // Update the review with the response
    $stmt = $conn->prepare("
        UPDATE reviews 
        SET owner_response = ?, owner_response_date = NOW()
        WHERE id = ? AND gym_id = ?
    ");
    $result = $stmt->execute([$response, $review_id, $gym_id]);
    
    if ($result) {
        $_SESSION['success'] = "Your response has been saved.";
        // Refresh the page to show the updated response
        header("Location: view_reviews.php?page=$page&rating=$rating_filter&status=$status_filter&sort_by=$sort_by");
        exit();
    } else {
        $_SESSION['error'] = "Failed to save your response.";
    }
}

?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Gym Reviews</h1>
    </div>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-500 text-white p-4 rounded-lg mb-6">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-500 text-white p-4 rounded-lg mb-6">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    
    <!-- Rating Statistics -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="flex flex-col items-center justify-center">
                <div class="text-5xl font-bold text-white mb-2">
                    <?php echo number_format($stats['average_rating'] ?? 0, 1); ?>
                </div>
                <div class="flex text-yellow-400 text-2xl mb-2">
                    <?php
                    $avg_rating = $stats['average_rating'] ?? 0;
                    for ($i = 1; $i <= 5; $i++) {
                        if ($i <= $avg_rating) {
                            echo '<i class="fas fa-star"></i>';
                        } elseif ($i - 0.5 <= $avg_rating) {
                            echo '<i class="fas fa-star-half-alt"></i>';
                        } else {
                            echo '<i class="far fa-star"></i>';
                        }
                    }
                    ?>
                </div>
                <div class="text-gray-400">Based on <?php echo $stats['total_reviews'] ?? 0; ?> reviews</div>
            </div>
            
            <div class="col-span-2">
                <div class="space-y-2">
                    <div class="flex items-center">
                        <div class="w-24 text-sm text-gray-400">5 stars</div>
                        <div class="flex-1 h-4 bg-gray-700 rounded-full overflow-hidden">
                            <?php
                            $five_star_percent = $stats['total_reviews'] > 0 ? ($stats['five_star'] / $stats['total_reviews']) * 100 : 0;
                            ?>
                            <div class="h-full bg-green-500" style="width: <?php echo $five_star_percent; ?>%"></div>
                        </div>
                        <div class="w-16 text-right text-sm text-gray-400"><?php echo $stats['five_star'] ?? 0; ?></div>
                    </div>
                    
                    <div class="flex items-center">
                        <div class="w-24 text-sm text-gray-400">4 stars</div>
                        <div class="flex-1 h-4 bg-gray-700 rounded-full overflow-hidden">
                            <?php
                            $four_star_percent = $stats['total_reviews'] > 0 ? ($stats['four_star'] / $stats['total_reviews']) * 100 : 0;
                            ?>
                            <div class="h-full bg-green-400" style="width: <?php echo $four_star_percent; ?>%"></div>
                        </div>
                        <div class="w-16 text-right text-sm text-gray-400"><?php echo $stats['four_star'] ?? 0; ?></div>
                    </div>
                    
                    <div class="flex items-center">
                        <div class="w-24 text-sm text-gray-400">3 stars</div>
                        <div class="flex-1 h-4 bg-gray-700 rounded-full overflow-hidden">
                            <?php
                            $three_star_percent = $stats['total_reviews'] > 0 ? ($stats['three_star'] / $stats['total_reviews']) * 100 : 0;
                            ?>
                            <div class="h-full bg-yellow-500" style="width: <?php echo $three_star_percent; ?>%"></div>
                        </div>
                        <div class="w-16 text-right text-sm text-gray-400"><?php echo $stats['three_star'] ?? 0; ?></div>
                    </div>
                    
                    <div class="flex items-center">
                        <div class="w-24 text-sm text-gray-400">2 stars</div>
                        <div class="flex-1 h-4 bg-gray-700 rounded-full overflow-hidden">
                            <?php
                            $two_star_percent = $stats['total_reviews'] > 0 ? ($stats['two_star'] / $stats['total_reviews']) * 100 : 0;
                            ?>
                            <div class="h-full bg-orange-500" style="width: <?php echo $two_star_percent; ?>%"></div>
                        </div>
                        <div class="w-16 text-right text-sm text-gray-400"><?php echo $stats['two_star'] ?? 0; ?></div>
                    </div>
                    
                    <div class="flex items-center">
                        <div class="w-24 text-sm text-gray-400">1 star</div>
                        <div class="flex-1 h-4 bg-gray-700 rounded-full overflow-hidden">
                            <?php
                            $one_star_percent = $stats['total_reviews'] > 0 ? ($stats['one_star'] / $stats['total_reviews']) * 100 : 0;
                            ?>
                            <div class="h-full bg-red-500" style="width: <?php echo $one_star_percent; ?>%"></div>
                        </div>
                        <div class="w-16 text-right text-sm text-gray-400"><?php echo $stats['one_star'] ?? 0; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-gray-300 text-sm font-medium mb-2">Rating</label>
                <select name="rating" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="all" <?php echo $rating_filter === 'all' ? 'selected' : ''; ?>>All Ratings</option>
                    <option value="5" <?php echo $rating_filter === '5' ? 'selected' : ''; ?>>5 Stars</option>
                    <option value="4" <?php echo $rating_filter === '4' ? 'selected' : ''; ?>>4 Stars</option>
                    <option value="3" <?php echo $rating_filter === '3' ? 'selected' : ''; ?>>3 Stars</option>
                    <option value="2" <?php echo $rating_filter === '2' ? 'selected' : ''; ?>>2 Stars</option>
                    <option value="1" <?php echo $rating_filter === '1' ? 'selected' : ''; ?>>1 Star</option>
                </select>
            </div>
            
            <div>
                <label class="block text-gray-300 text-sm font-medium mb-2">Status</label>
                <select name="status" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>
            
            <div>
                <label class="block text-gray-300 text-sm font-medium mb-2">Sort By</label>
                <select name="sort_by" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="newest" <?php echo $sort_by === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                    <option value="oldest" <?php echo $sort_by === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                    <option value="highest" <?php echo $sort_by === 'highest' ? 'selected' : ''; ?>>Highest Rating</option>
                    <option value="lowest" <?php echo $sort_by === 'lowest' ? 'selected' : ''; ?>>Lowest Rating</option>
                </select>
            </div>
            
            <div class="flex items-end">
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    Apply Filters
                </button>
            </div>
        </form>
    </div>
    
    <!-- Reviews List -->
    <div class="bg-gray-800 rounded-lg shadow-lg p-6 mb-6">
        <?php if (empty($reviews)): ?>
            <div class="bg-gray-700 rounded-lg p-6 text-center">
                <i class="fas fa-comment-slash text-gray-500 text-4xl mb-3"></i>
                <p class="text-gray-400">No reviews found matching your filters.</p>
            </div>
        <?php else: ?>
            <div class="space-y-6">
                <?php foreach ($reviews as $review): ?>
                    <div class="bg-gray-700 rounded-lg p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div class="flex items-center">
                                <?php if ($review['profile_image']): ?>
                                    <img src="<?php echo '../' . $review['profile_image']; ?>" alt="Profile" class="w-10 h-10 rounded-full object-cover mr-3">
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-full bg-gray-600 flex items-center justify-center mr-3">
                                        <i class="fas fa-user text-gray-400"></i>
                                    </div>
                                <?php endif; ?>
                                
                                <div>
                                    <div class="text-white font-medium"><?php echo htmlspecialchars($review['username']); ?></div>
                                    <div class="text-gray-400 text-sm"><?php echo date('M d, Y', strtotime($review['created_at'])); ?></div>
                                </div>
                            </div>
                            
                            <div class="flex items-center">
                                <div class="flex text-yellow-400 mr-2">
                                    <?php
                                    for ($i = 1; $i <= 5; $i++) {
                                        if ($i <= $review['rating']) {
                                            echo '<i class="fas fa-star"></i>';
                                        } else {
                                            echo '<i class="far fa-star"></i>';
                                        }
                                    }
                                    ?>
                                </div>
                                
                                <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                    <?php 
                                    if ($review['status'] === 'approved') echo 'bg-green-100 text-green-800';
                                    elseif ($review['status'] === 'pending') echo 'bg-yellow-100 text-yellow-800';
                                    else echo 'bg-red-100 text-red-800';
                                    ?>">
                                    <?php echo ucfirst($review['status']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <p class="text-white"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                            <?php if ($review['visit_date']): ?>
                                <p class="text-gray-400 text-sm mt-2">
                                    <i class="fas fa-calendar-alt mr-1"></i> Visit Date: <?php echo date('M d, Y', strtotime($review['visit_date'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($review['owner_response'])): ?>
                            <div class="bg-gray-800 rounded-lg p-4 mb-4">
                                <div class="flex items-center mb-2">
                                    <i class="fas fa-reply text-blue-400 mr-2"></i>
                                    <div class="text-blue-400 font-medium">Your Response</div>
                                    <div class="text-gray-400 text-sm ml-auto">
                                        <?php echo date('M d, Y', strtotime($review['owner_response_date'])); ?>
                                    </div>
                                </div>
                                <p class="text-white"><?php echo nl2br(htmlspecialchars($review['owner_response'])); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (empty($review['owner_response'])): ?>
                            <button type="button" onclick="openResponseModal(<?php echo $review['id']; ?>)" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-reply mr-2"></i> Respond to Review
                            </button>
                        <?php else: ?>
                            <button type="button" onclick="openResponseModal(<?php echo $review['id']; ?>, '<?php echo addslashes($review['owner_response']); ?>')" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-edit mr-2"></i> Edit Response
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="flex justify-center mt-6">
                    <div class="flex space-x-1">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&rating=<?php echo $rating_filter; ?>&status=<?php echo $status_filter; ?>&sort_by=<?php echo $sort_by; ?>" class="px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600">
                            <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&rating=<?php echo $rating_filter; ?>&status=<?php echo $status_filter; ?>&sort_by=<?php echo $sort_by; ?>" class="px-4 py-2 <?php echo $i === $page ? 'bg-blue-500 text-white' : 'bg-gray-700 text-white hover:bg-gray-600'; ?> rounded-lg">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&rating=<?php echo $rating_filter; ?>&status=<?php echo $status_filter; ?>&sort_by=<?php echo $sort_by; ?>" class="px-4 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Response Modal -->
<div id="responseModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
    <div class="bg-gray-800 rounded-lg shadow-lg max-w-md w-full mx-4">
        <div class="bg-gray-700 px-6 py-4 flex justify-between items-center">
            <h3 class="text-xl font-medium">Respond to Review</h3>
            <button type="button" onclick="closeResponseModal()" class="text-gray-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="p-6">
            <form method="POST" id="responseForm">
                <input type="hidden" name="review_id" id="review_id">
                
                <div class="mb-4">
                    <label class="block text-gray-300 text-sm font-medium mb-2">Your Response</label>
                    <textarea name="response" id="response" rows="5" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-blue-500" required></textarea>
                    <p class="text-gray-400 text-sm mt-1">Your response will be visible to all users who view this review.</p>
                </div>
                
                <div class="flex justify-end">
                    <button type="button" onclick="closeResponseModal()" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded-lg mr-2">
                        Cancel
                    </button>
                    <button type="submit" name="respond_to_review" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        Submit Response
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function openResponseModal(reviewId, existingResponse = '') {
        document.getElementById('review_id').value = reviewId;
        document.getElementById('response').value = existingResponse;
        document.getElementById('responseModal').classList.remove('hidden');
    }
    
    function closeResponseModal() {
        document.getElementById('responseModal').classList.add('hidden');
    }
    
    // Close modal when clicking outside
    document.addEventListener('click', function(event) {
        const modal = document.getElementById('responseModal');
        const modalContent = modal.querySelector('div');
        
        if (event.target === modal) {
            closeResponseModal();
        }
    });
</script>

<?php include '../includes/footer.php'; ?>


