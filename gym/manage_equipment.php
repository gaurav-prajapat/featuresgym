<?php
ob_start();
include '../includes/navbar.php';

require_once '../config/database.php';

if (!isset($_SESSION['owner_id'])) {
    header('Location: login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$owner_id = $_SESSION['owner_id'];

// Get gym ID
$stmt = $conn->prepare("SELECT gym_id FROM gyms WHERE owner_id = :owner_id");
$stmt->bindParam(':owner_id', $owner_id);
$stmt->execute();
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    header('Location: add gym.php');
    exit;
}
ob_end_flush();
$gym_id = $gym['gym_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $equipment_name = trim($_POST['name']);
    $quantity = (int)$_POST['quantity'];
    $action = $_POST['action'];
    $image = null;
    
    if (isset($_FILES['equipment_image']) && $_FILES['equipment_image']['error'] == 0) {
        $target_dir = "../uploads/equipments/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_extension = strtolower(pathinfo($_FILES["equipment_image"]["name"], PATHINFO_EXTENSION));
        $new_filename = uniqid('equipment_') . '.' . $file_extension;
        $target_file = $target_dir . $new_filename;

        $valid_types = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($file_extension, $valid_types) && $_FILES["equipment_image"]["size"] <= 5000000) {
            if (move_uploaded_file($_FILES["equipment_image"]["tmp_name"], $target_file)) {
                $image = $new_filename;
            }
        }
    }
    if ($action === 'add') {
        $stmt = $conn->prepare("INSERT INTO gym_equipment (gym_id, name, quantity, image) VALUES (:gym_id, :equipment_name, :quantity, :image)");
        $params = [
            ':gym_id' => $gym_id,
            ':equipment_name' => $equipment_name,
            ':quantity' => $quantity,
            ':image' => $image
        ];
    } else {
        $equipment_id = $_POST['equipment_id'];
        if ($image) {
            $stmt = $conn->prepare("UPDATE gym_equipment SET equipment_name = :equipment_name, quantity = :quantity, image = :image WHERE equipment_id = :equipment_id AND gym_id = :gym_id");
            $params = [
                ':equipment_id' => $equipment_id,
                ':gym_id' => $gym_id,
                ':equipment_name' => $equipment_name,
                ':quantity' => $quantity,
                ':image' => $image
            ];
        } else {
            $stmt = $conn->prepare("UPDATE gym_equipment SET equipment_name = :equipment_name, quantity = :quantity WHERE equipment_id = :equipment_id AND gym_id = :gym_id");
            $params = [
                ':equipment_id' => $equipment_id,
                ':gym_id' => $gym_id,
                ':equipment_name' => $equipment_name,
                ':quantity' => $quantity
            ];
        }
    }
    
    $result = $stmt->execute($params);
    

    if ($result) {
        header("Location: manage_equipment.php?success=1");
        exit;
    }
}

// Fetch equipment
$stmt = $conn->prepare("SELECT * FROM gym_equipment WHERE gym_id = :gym_id ORDER BY name");
$stmt->execute([':gym_id' => $gym_id]);
$equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container mx-auto px-4 py-20">
    <!-- Header -->
    <div class="bg-white rounded-xl shadow-lg overflow-hidden mb-8">
        <div class="p-6 bg-gradient-to-r from-gray-900 to-gray-800">
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-4">
                    <div class="h-16 w-16 rounded-full bg-yellow-500 flex items-center justify-center">
                        <i class="fas fa-dumbbell text-2xl text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-white">Gym Equipment</h1>
                        <p class="text-white ">Manage your gym's equipment inventory</p>
                    </div>
                </div>
                <button onclick="openAddModal()" 
                        class="bg-yellow-500 hover:bg-yellow-600 text-white px-6 py-3 rounded-lg transition-colors duration-200">
                    <i class="fas fa-plus mr-2"></i>Add Equipment
                </button>
            </div>
        </div>
    </div>

    <!-- Equipment Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php foreach ($equipments as $equipment): ?>
            <div class="bg-white rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-shadow duration-200">
                <?php if ($equipment['image']): ?>
                    <img src="../gym/uploads/equipments/<?php echo htmlspecialchars($equipment['image']); ?>" 
                         alt="<?php echo htmlspecialchars($equipment['equipment_name']); ?>"
                         class="w-full h-48 object-cover">
                <?php endif; ?>
                
                <div class="p-6">
                    <div class="flex justify-between items-start mb-4">
                        <h3 class="text-xl font-bold"><?php echo htmlspecialchars($equipment['name']); ?></h3>
                        <span class="px-3 py-1 rounded-full text-sm font-semibold bg-green-100 text-green-800">
                            <?php echo htmlspecialchars($equipment['quantity']); ?> units
                        </span>
                    </div>

                    <div class="flex justify-end space-x-3 mt-4">
                        <button onclick="openEditModal(<?php echo htmlspecialchars(json_encode($equipment)); ?>)" 
                                class="text-blue-500 hover:text-blue-700">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button onclick="confirmDelete(<?php echo $equipment['equipment_id']; ?>)" 
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
<div id="equipmentModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="bg-white rounded-xl shadow-lg w-full max-w-2xl">
            <form id="equipmentForm" method="POST" enctype="multipart/form-data" class="p-6">
                <input type="hidden" name="action" id="formAction">
                <input type="hidden" name="equipment_id" id="equipmentId">
                
                <h2 id="modalTitle" class="text-2xl font-bold mb-6"></h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Equipment Name</label>
                        <input type="text" name="equipment_name" id="equipmentName" required
                               class="w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Quantity</label>
                        <input type="number" name="quantity" id="quantity" required min="1"
                               class="w-full rounded-lg border-gray-300 focus:border-yellow-500 focus:ring focus:ring-yellow-200">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Equipment Image</label>
                        <input type="file" name="equipment_image" id="equipmentImage" accept=".jpg,.jpeg,.png,.webp"
                               class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-yellow-50 file:text-yellow-700 hover:file:bg-yellow-100">
                        <p class="mt-1 text-sm text-gray-500">Max file size: 5MB. Accepted formats: JPG, JPEG, PNG, WEBP</p>
                    </div>
                </div>
                
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal()"
                            class="px-4 py-2 text-gray-600 hover:text-gray-800">
                        Cancel
                    </button>
                    <button type="submit"
                            class="bg-yellow-500 hover:bg-yellow-600 text-white px-6 py-2 rounded-lg transition-colors duration-200">
                        Save Equipment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('modalTitle').textContent = 'Add New Equipment';
    document.getElementById('equipmentForm').reset();
    document.getElementById('equipmentModal').classList.remove('hidden');
}

function openEditModal(equipment) {
    document.getElementById('formAction').value = 'edit';
    document.getElementById('modalTitle').textContent = 'Edit Equipment';
    document.getElementById('equipmentId').value = equipment.equipment_id;
    document.getElementById('equipmentName').value = equipment.equipment_name;
    document.getElementById('quantity').value = equipment.quantity;
    document.getElementById('equipmentModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('equipmentModal').classList.add('hidden');
}

function confirmDelete(equipmentId) {
    if (confirm('Are you sure you want to delete this equipment?')) {
        window.location.href = `delete_equipment.php?id=${equipmentId}`;
    }
}

document.querySelector('input[type="file"]').addEventListener('change', function(e) {
    if (this.files[0].size > 5000000) {
        alert('File size must be less than 5MB');
        this.value = '';
    }
});
</script>
