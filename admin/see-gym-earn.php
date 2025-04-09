<?php
require_once '../config/database.php';
include "../includes/navbar.php";


// Check if admin is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$db   = new GymDatabase();
$conn = $db->getConnection();

// Query to fetch gyms with review count, average rating, and total earnings (profit/loss)
$query = "
SELECT g.gym_id, g.name AS gym_name, g.status AS gym_status, COUNT(r.id) AS review_count, AVG(r.rating) AS avg_rating,
       COALESCE(SUM(gr.amount), 0) AS total_revenue, COALESCE(SUM(CASE WHEN gr.amount < 0 THEN gr.amount ELSE 0 END), 0) AS total_loss
FROM gyms g
LEFT JOIN reviews r ON g.gym_id = r.gym_id
LEFT JOIN gym_revenue gr ON g.gym_id = gr.gym_id
WHERE g.status = 'active'
GROUP BY g.gym_id
ORDER BY avg_rating DESC
";

// Execute the query
$stmt = $conn->prepare($query);
$stmt->execute();
$gyms = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-8">All Active Gyms</h1>
    <a href="manage_withdrawals.php" class="">Manage Withdrawals</a>

    <!-- Gyms List -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Gym Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Review Count</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Avg Rating</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Revenue</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Loss</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Net Earnings</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-200">
                <?php if (empty($gyms)): ?>
                    <tr>
                        <td colspan="7" class="px-6 py-4 text-center text-gray-500">No active gyms found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($gyms as $gym): ?>
                        <?php
                            $net_earnings = $gym['total_revenue'] + $gym['total_loss']; // Net earnings (profit/loss)
                        ?>
                        <tr>
                            <td class="px-6 py-4"><?php echo htmlspecialchars($gym['gym_name']) ?></td>
                            <td class="px-6 py-4"><?php echo ucfirst($gym['gym_status']) ?></td>
                            <td class="px-6 py-4"><?php echo $gym['review_count'] ?></td>
                            <td class="px-6 py-4"><?php echo number_format($gym['avg_rating'], 2) ?></td>
                            <td class="px-6 py-4">₹<?php echo number_format($gym['total_revenue'], 2) ?></td>
                            <td class="px-6 py-4">₹<?php echo number_format(abs($gym['total_loss']), 2) ?></td>
                            <td class="px-6 py-4 text-green-600">
                                ₹<?php echo number_format($net_earnings, 2) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
