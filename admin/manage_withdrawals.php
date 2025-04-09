<?php
include '../includes/navbar.php';
require_once '../config/database.php';


if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Get all withdrawal requests
$withdrawalQuery = "
    SELECT w.*, g.name as gym_name
    FROM withdrawals w
    JOIN gyms g ON w.gym_id = g.gym_id
    ORDER BY w.created_at DESC";

$stmt = $conn->prepare($withdrawalQuery);
$stmt->execute();
$withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container mx-auto px-4 py-20">
    <div class="bg-white rounded-xl shadow-lg p-6">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Withdrawal Management</h1>
            <button onclick="processAllPayments()"
                class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg">
                Process All Pending Payments
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr>
                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase">Gym</th>
                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase">Amount
                        </th>
                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase">Payment
                            Method</th>
                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase">Status
                        </th>
                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase">Requested
                            On</th>
                        <th class="px-6 py-3 bg-gray-50 text-left text-xs font-medium text-gray-500 uppercase">Actions
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($withdrawals as $withdrawal): ?>
                        <tr>
                            <td class="px-6 py-4"><?= htmlspecialchars($withdrawal['gym_name']) ?></td>
                            <td class="px-6 py-4">₹<?= number_format($withdrawal['amount'], 2) ?></td>
                            <td class="px-6 py-4">
                                <?= htmlspecialchars($withdrawal['bank_account']) ?>
                            </td>
                            <td class="px-6 py-4">
                                <span
                                    class="px-2 py-1 rounded-full text-xs font-semibold
                    <?= $withdrawal['status'] === 'completed' ? 'bg-green-100 text-green-800' :
                        ($withdrawal['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
                                    <?= ucfirst($withdrawal['status']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4"><?= date('M j, Y g:i A', strtotime($withdrawal['created_at'])) ?></td>
                            <td class="px-6 py-4">
                                <?php if ($withdrawal['status'] === 'pending'): ?>
                                    <button onclick="updateStatus(<?= $withdrawal['id'] ?>, 'completed')"
                                        class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded mr-2">
                                        Approve
                                    </button>
                                    <button onclick="updateStatus(<?= $withdrawal['id'] ?>, 'rejected')"
                                        class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded">
                                        Reject
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>

            </table>
        </div>
    </div>
</div>

<script>
    function updateStatus(withdrawalId, status) {
        fetch('process_withdrawal_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                withdrawal_id: withdrawalId,
                status: status
            })
        }).then(response => response.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message);
                }
            });
    }

    function processAllPayments() {
        if (confirm('Are you sure you want to process all pending payments?')) {
            fetch('process_all_payments.php', {
                method: 'POST'
            }).then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.message);
                    }
                });
        }
    }
</script>