<?php
include '../includes/navbar.php';
require_once '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
    header('Location: login.html');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];

// Get gym details
$stmt = $conn->prepare("SELECT gym_id, name FROM gyms WHERE owner_id = ?");
$stmt->execute([$owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);
$gym_id = $gym['gym_id'];

// Get revenue period from query params
$period = $_GET['period'] ?? 'daily';

// Prepare revenue query based on period
$revenueQuery = match($period) {
    'daily' => "
        SELECT DATE(date) as revenue_date,
               COUNT(DISTINCT schedule_id) as visit_count,
               SUM(amount) as total_revenue
        FROM gym_revenue 
        WHERE gym_id = ? 
        AND source_type = 'transfer_in'
        AND DATE(date) >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
        GROUP BY DATE(date)
        ORDER BY date DESC
    ",
    'weekly' => "
        SELECT YEARWEEK(date) as week_number,
               MIN(DATE(date)) as week_start,
               MAX(DATE(date)) as week_end,
               COUNT(DISTINCT schedule_id) as visit_count,
               SUM(amount) as total_revenue
        FROM gym_revenue 
        WHERE gym_id = ?
        AND source_type = 'transfer_in'
        AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 12 WEEK)
        GROUP BY YEARWEEK(date)
        ORDER BY week_number DESC
    ",
    'monthly' => "
        SELECT DATE_FORMAT(date, '%Y-%m') as month,
               COUNT(DISTINCT schedule_id) as visit_count,
               SUM(amount) as total_revenue
        FROM gym_revenue 
        WHERE gym_id = ?
        AND source_type = 'transfer_in'
        AND date >= DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(date, '%Y-%m')
        ORDER BY month DESC
    "
};

$stmt = $conn->prepare($revenueQuery);
$stmt->execute([$gym_id]);
$revenues = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container mx-auto px-4 py-20">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Revenue History - <?php echo htmlspecialchars($gym['name']); ?></h1>
        
        <!-- Period Selection -->
        <div class="flex gap-4">
            <a href="?period=daily" class="<?php echo $period === 'daily' ? 'bg-blue-500 text-white' : 'bg-gray-200'; ?> px-4 py-2 rounded">Daily</a>
            <a href="?period=weekly" class="<?php echo $period === 'weekly' ? 'bg-blue-500 text-white' : 'bg-gray-200'; ?> px-4 py-2 rounded">Weekly</a>
            <a href="?period=monthly" class="<?php echo $period === 'monthly' ? 'bg-blue-500 text-white' : 'bg-gray-200'; ?> px-4 py-2 rounded">Monthly</a>
        </div>
    </div>

    <!-- Revenue Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Period</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visits</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Revenue</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Average Per Visit</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($revenues as $revenue): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            switch($period) {
                                case 'daily':
                                    echo date('d M Y', strtotime($revenue['revenue_date']));
                                    break;
                                case 'weekly':
                                    echo date('d M', strtotime($revenue['week_start'])) . ' - ' . 
                                         date('d M Y', strtotime($revenue['week_end']));
                                    break;
                                case 'monthly':
                                    echo date('F Y', strtotime($revenue['month'] . '-01'));
                                    break;
                            }
                            ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap"><?php echo number_format($revenue['visit_count']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">₹<?php echo number_format($revenue['total_revenue'], 2); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            ₹<?php echo $revenue['visit_count'] > 0 ? 
                                number_format($revenue['total_revenue'] / $revenue['visit_count'], 2) : '0.00'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
