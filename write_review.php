<?php
// Database connection
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Get gyms user has visited
$stmt = $conn->prepare("
    SELECT DISTINCT g.* 
    FROM gyms g
    JOIN visit v ON g.gym_id = v.gym_id
    WHERE v.user_id = :user_id
    ORDER BY g.name
");
$stmt->execute([':user_id' => $user_id]);
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gym_id = $_POST['gym_id'];
    $rating = $_POST['rating'];
    $comment = $_POST['comment'];
    $visit_date = $_POST['visit_date'];

    $stmt = $conn->prepare("
        INSERT INTO reviews (user_id, gym_id, rating, comment, visit_date, status, created_at)
        VALUES (:user_id, :gym_id, :rating, :comment, :visit_date, 'pending', CURRENT_TIMESTAMP)
    ");

    $stmt->execute([
        ':user_id' => $user_id,
        ':gym_id' => $gym_id,
        ':rating' => $rating,
        ':comment' => $comment,
        ':visit_date' => $visit_date
    ]);

    header('Location: my_reviews.php?success=1');
    exit;
}
?>

<div class="container mx-auto px-4 py-8">
    <div class="max-w-2xl mx-auto">
        <h1 class="text-2xl font-bold mb-6">Write a Review</h1>

        <form method="POST" class="bg-white rounded-lg shadow-lg p-6">
            <div class="space-y-4">
                <!-- Select Gym -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Select Gym</label>
                    <select name="gym_id" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                        <?php foreach ($gyms as $gym): ?>
                            <option value="<?php echo $gym['gym_id']; ?>">
                                <?php echo htmlspecialchars($gym['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Star Rating -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Rating</label>
                    <div class="mt-1 flex items-center space-x-2">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <input type="radio" name="rating" value="<?php echo $i; ?>" required
                                   class="hidden peer" id="star<?php echo $i; ?>">
                            <label for="star<?php echo $i; ?>" 
                                   class="cursor-pointer text-white  peer-checked:text-yellow-400">
                                <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
                                </svg>
                            </label>
                        <?php endfor; ?>
                    </div>
                    <div id="rating-value" class="text-sm text-gray-500 mt-2">Select a rating</div>
                </div>

                <!-- Visit Date -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Visit Date</label>
                    <input type="date" name="visit_date" required
                           max="<?php echo date('Y-m-d'); ?>"
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm">
                </div>

                <!-- Review Comment -->
                <div>
                    <label class="block text-sm font-medium text-gray-700">Your Review</label>
                    <textarea name="comment" rows="4" required
                              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                              placeholder="Share your experience..."></textarea>
                </div>

                <!-- Submit Button -->
                <button type="submit" 
                        class="w-full bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700">
                    Submit Review
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('input[name="rating"]').forEach(radio => {
        radio.addEventListener('change', (e) => {
            document.getElementById('rating-value').textContent = `You selected ${e.target.value} star(s).`;
        });
    });
</script>
