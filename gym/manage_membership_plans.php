<?php
include '../includes/navbar.php';
require_once '../config/database.php';

$db = new GymDatabase();
$conn = $db->getConnection();

if (!isset($_SESSION['owner_id'])) {
    header('Location: ./login.html');
    exit;
}

$owner_id = $_SESSION['owner_id'];
$gym_id = $_GET['gym_id'] ?? null;

// Fetch gym details
$stmt = $conn->prepare("SELECT gym_id, name FROM gyms WHERE owner_id = :owner_id");
$stmt->execute([':owner_id' => $owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    echo "<script>window.location.href = 'add gym.php';</script>";
    exit;
}

// Handle Add/Edit/Delete Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $stmt = $conn->prepare("
                    INSERT INTO gym_membership_plans (
                        gym_id, plan_name, duration, plan_type, 
                        price, tier, best_for, inclusions
                    ) VALUES (
                        :gym_id, :plan_name, :duration, :plan_type,
                        :price, :tier, :best_for, :inclusions
                    )
                ");
                $stmt->execute([
                    ':gym_id' => $gym['gym_id'],
                    ':plan_name' => $_POST['plan_name'],
                    ':duration' => $_POST['duration'],
                    ':plan_type' => $_POST['plan_type'],
                    ':price' => $_POST['price'],
                    ':tier' => $_POST['tier'],
                    ':best_for' => $_POST['best_for'],
                    ':inclusions' => $_POST['inclusions']
                ]);
                break;

            case 'edit':
                $stmt = $conn->prepare("
                    UPDATE gym_membership_plans SET
                        plan_name = :plan_name,
                        duration = :duration,
                        plan_type = :plan_type,
                        price = :price,
                        tier = :tier,
                        best_for = :best_for,
                        inclusions = :inclusions
                    WHERE plan_id = :plan_id AND gym_id = :gym_id
                ");
                $stmt->execute([
                    ':plan_id' => $_POST['plan_id'],
                    ':gym_id' => $gym['gym_id'],
                    ':plan_name' => $_POST['plan_name'],
                    ':duration' => $_POST['duration'],
                    ':plan_type' => $_POST['plan_type'],
                    ':price' => $_POST['price'],
                    ':tier' => $_POST['tier'],
                    ':best_for' => $_POST['best_for'],
                    ':inclusions' => $_POST['inclusions']
                ]);
                break;

            case 'delete':
                $stmt = $conn->prepare("
                    DELETE FROM gym_membership_plans 
                    WHERE plan_id = :plan_id AND gym_id = :gym_id
                ");
                $stmt->execute([
                    ':plan_id' => $_POST['plan_id'],
                    ':gym_id' => $gym['gym_id']
                ]);
                break;
        }
        header('Location: manage_membership_plans.php');
        exit;
    }
}

// Fetch all plans
$stmt = $conn->prepare("SELECT * FROM gym_membership_plans WHERE gym_id = :gym_id ORDER BY tier, price");
$stmt->execute([':gym_id' => $gym['gym_id']]);
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container mx-auto px-4 py-20">
    <!-- Header -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
        <div class="p-6 bg-gradient-to-r from-gray-900 to-gray-800">
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-4">
                    <div class="h-16 w-16 rounded-full bg-yellow-500 flex items-center justify-center">
                        <i class="fas fa-tags text-2xl text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Membership Plans</h1>
                        <p class="text-white "><?php echo htmlspecialchars($gym['name']); ?></p>
                    </div>
                </div>
                <button onclick="openAddModal()" 
                        class="bg-yellow-500 hover:bg-yellow-600 text-white px-6 py-3 rounded-lg transition-colors duration-200">
                    <i class="fas fa-plus mr-2"></i>Add New Plan
                </button>
            </div>
        </div>
    </div>

    <!-- Plans Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php foreach ($plans as $plan): ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow duration-200">
                <div class="p-6">
                    <div class="flex justify-between items-start mb-4">
                        <h3 class="text-xl font-bold"><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-yellow-100 text-yellow-800">
                            <?php echo htmlspecialchars($plan['tier']); ?>
                        </span>
                    </div>
                    
                    <div class="space-y-3 mb-6">
                        <p class="text-3xl font-bold">₹<?php echo number_format($plan['price']); ?></p>
                        <p class="text-gray-600"><?php echo htmlspecialchars($plan['duration']); ?></p>
                        <p class="text-sm text-gray-500">Best for: <?php echo htmlspecialchars($plan['best_for']); ?></p>
                    </div>

                    <div class="border-t pt-4">
                        <h4 class="font-semibold mb-2">Inclusions:</h4>
                        <p class="text-gray-600 text-sm"><?php echo nl2br(htmlspecialchars($plan['inclusions'])); ?></p>
                    </div>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($plan)); ?>)" 
                                class="text-blue-500 hover:text-blue-700">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="confirmDelete(<?php echo $plan['plan_id']; ?>)" 
                                class="text-red-500 hover:text-red-700">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add/Edit Modal -->
<div id="planModal" class="fixed inset-0 bg-black bg-opacity-50 hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-2xl">
            <form id="planForm" method="POST" class="p-6">
                <input type="hidden" name="action" id="formAction">
                <input type="hidden" name="plan_id" id="planId">
                
                <h2 id="modalTitle" class="text-2xl font-bold mb-6"></h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Plan Name</label>
                        <input type="text" name="plan_name" id="planName" required
                               class="w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Duration</label>
                        <select name="duration" id="duration" required
                                class="w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                            <option value="Daily">Daily</option>
                            <option value="Weekly">Weekly</option>
                            <option value="Monthly">Monthly</option>
                            <option value="Quarterly">Quarterly</option>
                            <option value="Half Yearly">Half Yearly</option>
                            <option value="Yearly">Yearly</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Price (₹)</label>
                        <input type="number" name="price" id="price" required
                               class="w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Tier</label>
                        <select name="tier" id="tier" required
                                class="w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                            <option value="Tier 1">Tier 1</option>
                            <option value="Tier 2">Tier 2</option>
                            <option value="Tier 3">Tier 3</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Plan Type</label>
                        <select name="plan_type" id="planType" required
                                class="w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                            <option value="Basic">Basic</option>
                            <option value="Standard">Standard</option>
                            <option value="Premium">Premium</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Best For</label>
                        <input type="text" name="best_for" id="bestFor" required
                               class="w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Inclusions</label>
                        <textarea name="inclusions" id="inclusions" rows="4" required
                                  class="w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200"></textarea>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal()"
                            class="px-4 py-2 text-gray-600 hover:text-gray-800">
                        Cancel
                    </button>
                    <button type="submit"
                            class="bg-yellow-500 hover:bg-yellow-600 text-white px-6 py-2 rounded-lg transition-colors duration-200">
                        Save Plan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('modalTitle').textContent = 'Add New Plan';
    document.getElementById('planForm').reset();
    document.getElementById('planModal').classList.remove('hidden');
}

function openEditModal(plan) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('modalTitle').textContent = 'Edit Plan';
    document.getElementById('planId').value = plan.plan_id;
    document.getElementById('planName').value = plan.plan_name;
    document.getElementById('duration').value = plan.duration;
    document.getElementById('price').value = plan.price;
    document.getElementById('tier').value = plan.tier;
    document.getElementById('planType').value = plan.plan_type;
    document.getElementById('bestFor').value = plan.best_for;
    document.getElementById('inclusions').value = plan.inclusions;
    document.getElementById('planModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('planModal').classList.add('hidden');
}

function confirmDelete(planId) {
    if (confirm('Are you sure you want to delete this plan?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="plan_id" value="${planId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>
