<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Get gym ID from the logged-in gym owner
$gymId = $_SESSION['gym_id'];

// Define equipment categories
$categories = [
    'Cardio',
    'Strength',
    'Free Weights',
    'Machines',
    'Functional Training',
    'Yoga/Pilates',
    'CrossFit',
    'Other'
];

// Handle form submission for adding equipment
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_equipment'])) {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $quantity = (int) ($_POST['quantity'] ?? 1);
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            $errorMessage = "Equipment name is required.";
        } else {
            // Handle image upload
            $imagePath = '';
            if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                $maxSize = 5 * 1024 * 1024; // 5MB

                if (!in_array($_FILES['image']['type'], $allowedTypes)) {
                    $errorMessage = "Only JPG, PNG, and GIF images are allowed.";
                } elseif ($_FILES['image']['size'] > $maxSize) {
                    $errorMessage = "Image size should be less than 5MB.";
                } else {
                    $uploadDir = '../uploads/equipment/';

                    // Create directory if it doesn't exist
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    $fileName = time() . '_' . basename($_FILES['image']['name']);
                    $targetFile = $uploadDir . $fileName;

                    if (move_uploaded_file($_FILES['image']['tmp_name'], $targetFile)) {
                        $imagePath = $fileName;
                    } else {
                        $errorMessage = "Failed to upload image.";
                    }
                }
            }

            if (empty($errorMessage)) {
                try {
                    $sql = "INSERT INTO gym_equipment (gym_id, name, category, quantity, description, image, status) 
                            VALUES (?, ?, ?, ?, ?, ?, 'active')";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$gymId, $name, $category, $quantity, $description, $imagePath]);

                    $successMessage = "Equipment added successfully!";

                    // Clear form data
                    $_POST = [];
                } catch (Exception $e) {
                    $errorMessage = "Error adding equipment: " . $e->getMessage();
                }
            }
        }
    } elseif (isset($_POST['update_equipment'])) {
        $equipmentId = (int) ($_POST['equipment_id'] ?? 0);
        $name = trim($_POST['edit_name'] ?? '');
        $category = trim($_POST['edit_category'] ?? '');
        $quantity = (int) ($_POST['edit_quantity'] ?? 1);
        $description = trim($_POST['edit_description'] ?? '');

        if (empty($name) || $equipmentId <= 0) {
            $errorMessage = "Equipment name is required.";
        } else {
            try {
                // Check if a new image was uploaded
                $imageSql = '';
                $params = [$name, $category, $quantity, $description, $equipmentId, $gymId];

                if (isset($_FILES['edit_image']) && $_FILES['edit_image']['error'] == 0) {
                    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                    $maxSize = 5 * 1024 * 1024; // 5MB

                    if (!in_array($_FILES['edit_image']['type'], $allowedTypes)) {
                        $errorMessage = "Only JPG, PNG, and GIF images are allowed.";
                    } elseif ($_FILES['edit_image']['size'] > $maxSize) {
                        $errorMessage = "Image size should be less than 5MB.";
                    } else {
                        $uploadDir = '../uploads/equipment/';

                        // Create directory if it doesn't exist
                        if (!file_exists($uploadDir)) {
                            mkdir($uploadDir, 0777, true);
                        }

                        $fileName = time() . '_' . basename($_FILES['edit_image']['name']);
                        $targetFile = $uploadDir . $fileName;

                        if (move_uploaded_file($_FILES['edit_image']['tmp_name'], $targetFile)) {
                            // Get the old image to delete it
                            $oldImageSql = "SELECT image FROM gym_equipment WHERE equipment_id = ? AND gym_id = ?";
                            $oldImageStmt = $conn->prepare($oldImageSql);
                            $oldImageStmt->execute([$equipmentId, $gymId]);
                            $oldImage = $oldImageStmt->fetchColumn();

                            // Delete old image if it exists
                            if (!empty($oldImage) && file_exists($uploadDir . $oldImage)) {
                                unlink($uploadDir . $oldImage);
                            }

                            $imageSql = ', image = ?';
                            array_unshift($params, $fileName); // Add image to beginning of params
                        } else {
                            $errorMessage = "Failed to upload image.";
                        }
                    }
                }

                if (empty($errorMessage)) {
                    $sql = "UPDATE gym_equipment 
                                                    SET name = ?, category = ?, quantity = ?, description = ?" . $imageSql . "
                                                    WHERE equipment_id = ? AND gym_id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($params);

                    $successMessage = "Equipment updated successfully!";
                }
            } catch (Exception $e) {
                $errorMessage = "Error updating equipment: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['delete_equipment'])) {
        $equipmentId = (int) ($_POST['delete_id'] ?? 0);

        if ($equipmentId <= 0) {
            $errorMessage = "Invalid equipment ID.";
        } else {
            try {
                // Get the image to delete it
                $imageSql = "SELECT image FROM gym_equipment WHERE equipment_id = ? AND gym_id = ?";
                $imageStmt = $conn->prepare($imageSql);
                $imageStmt->execute([$equipmentId, $gymId]);
                $image = $imageStmt->fetchColumn();

                // Delete the equipment
                $sql = "DELETE FROM gym_equipment WHERE equipment_id = ? AND gym_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$equipmentId, $gymId]);

                // Delete the image if it exists
                if (!empty($image)) {
                    $imagePath = '../uploads/equipment/' . $image;
                    if (file_exists($imagePath)) {
                        unlink($imagePath);
                    }
                }

                $successMessage = "Equipment deleted successfully!";
            } catch (Exception $e) {
                $errorMessage = "Error deleting equipment: " . $e->getMessage();
            }
        }
    }
}

// Fetch all equipment for this gym
$sql = "SELECT * FROM gym_equipment WHERE gym_id = ? ORDER BY category, name";
$stmt = $conn->prepare($sql);
$stmt->execute([$gymId]);
$equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group equipment by category
$groupedEquipment = [];
foreach ($equipment as $item) {
    $category = $item['category'] ?: 'Uncategorized';
    if (!isset($groupedEquipment[$category])) {
        $groupedEquipment[$category] = [];
    }
    $groupedEquipment[$category][] = $item;
}
?>

<div class="container px-6 py-8 mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-700 dark:text-white">Manage Equipment</h1>
        <a href="manage-profile.php" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700">
            <i class="fas fa-arrow-left mr-2"></i>Back to Profile Management
        </a>
    </div>

    <?php if ($successMessage): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
            <p><?php echo $successMessage; ?></p>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p><?php echo $errorMessage; ?></p>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Add Equipment Form -->
        <div class="md:col-span-1">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-700 dark:text-white mb-4">Add New Equipment</h2>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="space-y-4">
                            <div>
                                <label for="name"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Equipment Name *
                                </label>
                                <input type="text" id="name" name="name" required
                                    value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>

                            <div>
                                <label for="category"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Category
                                </label>
                                <select id="category" name="category"
                                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category; ?>" <?php echo (isset($_POST['category']) && $_POST['category'] === $category) ? 'selected' : ''; ?>>
                                            <?php echo $category; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="quantity"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Quantity
                                </label>
                                <input type="number" id="quantity" name="quantity" min="1"
                                    value="<?php echo htmlspecialchars($_POST['quantity'] ?? '1'); ?>"
                                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>

                            <div>
                                <label for="description"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Description
                                </label>
                                <textarea id="description" name="description" rows="3"
                                    class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                            </div>

                            <div>
                                <label for="image"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Image
                                </label>
                                <input type="file" id="image" name="image" accept="image/*"
                                    class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Max file size: 5MB. Supported formats: JPG, PNG, GIF
                                </p>
                            </div>

                            <div>
                                <button type="submit" name="add_equipment"
                                    class="w-full px-4 py-2 bg-yellow-500 text-black rounded-md hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                                    Add Equipment
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Equipment List -->
        <div class="md:col-span-2">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-700 dark:text-white mb-4">Equipment List</h2>

                    <?php if (empty($groupedEquipment)): ?>
                        <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                            <i class="fas fa-dumbbell text-4xl mb-3"></i>
                            <p>No equipment added yet. Add your first piece of equipment using the form.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-6">
                            <?php foreach ($groupedEquipment as $category => $items): ?>
                                <div>
                                    <h3
                                        class="text-md font-semibold text-gray-700 dark:text-white mb-3 border-b border-gray-200 dark:border-gray-700 pb-2">
                                        <?php echo htmlspecialchars($category); ?> (<?php echo count($items); ?>)
                                    </h3>

                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <?php foreach ($items as $item): ?>
                                            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 flex items-start">
                                                <?php if (!empty($item['image'])): ?>
                                                    <div class="mr-3 flex-shrink-0">
                                                        <img src="../uploads/equipment/<?php echo htmlspecialchars($item['image']); ?>"
                                                            alt="<?php echo htmlspecialchars($item['name']); ?>"
                                                            class="h-16 w-16 object-cover rounded-md">
                                                    </div>
                                                <?php endif; ?>

                                                <div class="flex-1">
                                                    <div class="flex justify-between items-start">
                                                        <h4 class="font-medium text-gray-700 dark:text-white">
                                                            <?php echo htmlspecialchars($item['name']); ?>
                                                        </h4>
                                                        <div class="flex space-x-2">
                                                            <button type="button"
                                                                onclick="openEditModal(<?php echo htmlspecialchars(json_encode($item)); ?>)"
                                                                class="text-blue-500 hover:text-blue-700">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button type="button"
                                                                onclick="confirmDelete(<?php echo $item['equipment_id']; ?>, '<?php echo addslashes($item['name']); ?>')"
                                                                class="text-red-500 hover:text-red-700">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                                        Qty: <?php echo htmlspecialchars($item['quantity']); ?>
                                                    </p>
                                                    <?php if (!empty($item['description'])): ?>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                            <?php echo htmlspecialchars($item['description']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Equipment Modal -->
<div id="edit-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full mx-4">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-700 dark:text-white">Edit Equipment</h3>
                <button type="button" onclick="closeEditModal()" class="text-gray-400 hover:text-gray-500">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <form method="POST" enctype="multipart/form-data" id="edit-form">
                <input type="hidden" name="equipment_id" id="edit_equipment_id">

                <div class="space-y-4">
                    <div>
                        <label for="edit_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Equipment Name *
                        </label>
                        <input type="text" id="edit_name" name="edit_name" required
                            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label for="edit_category"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Category
                        </label>
                        <select id="edit_category" name="edit_category"
                            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category; ?>">
                                    <?php echo $category; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="edit_quantity"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Quantity
                        </label>
                        <input type="number" id="edit_quantity" name="edit_quantity" min="1"
                            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>

                    <div>
                        <label for="edit_description"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Description
                        </label>
                        <textarea id="edit_description" name="edit_description" rows="3"
                            class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                    </div>

                    <div>
                        <label for="edit_image" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Image
                        </label>
                        <div class="flex items-center mb-2">
                            <img id="current_image" src="" alt="Current Image"
                                class="h-16 w-16 object-cover rounded-md mr-3 hidden">
                            <span id="no_image_text" class="text-sm text-gray-500 dark:text-gray-400">No image
                                uploaded</span>
                        </div>
                        <input type="file" id="edit_image" name="edit_image" accept="image/*"
                            class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Leave empty to keep current image. Max file size: 5MB.
                        </p>
                    </div>

                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" onclick="closeEditModal()"
                            class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700">
                            Cancel
                        </button>
                        <button type="submit" name="update_equipment"
                            class="px-4 py-2 bg-yellow-500 text-black rounded-md hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                            Update Equipment
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="delete-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full mx-4">
        <div class="p-6">
            <div class="mb-4">
                <h3 class="text-lg font-medium text-gray-700 dark:text-white">Confirm Deletion</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">
                    Are you sure you want to delete <span id="delete-item-name" class="font-medium"></span>? This action
                    cannot be undone.
                </p>
            </div>

            <form method="POST" id="delete-form">
                <input type="hidden" name="delete_id" id="delete_id">

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeDeleteModal()"
                        class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit" name="delete_equipment"
                        class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                        Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // Edit Modal Functions
    function openEditModal(item) {
        document.getElementById('edit_equipment_id').value = item.equipment_id;
        document.getElementById('edit_name').value = item.name;
        document.getElementById('edit_category').value = item.category;
        document.getElementById('edit_quantity').value = item.quantity;
        document.getElementById('edit_description').value = item.description;

        // Handle image display
        const currentImage = document.getElementById('current_image');
        const noImageText = document.getElementById('no_image_text');

        if (item.image) {
            currentImage.src = '../uploads/equipment/' + item.image;
            currentImage.alt = item.name;
            currentImage.classList.remove('hidden');
            noImageText.classList.add('hidden');
        } else {
            currentImage.classList.add('hidden');
            noImageText.classList.remove('hidden');
        }

        document.getElementById('edit-modal').classList.remove('hidden');
    }

    function closeEditModal() {
        document.getElementById('edit-modal').classList.add('hidden');
        document.getElementById('edit-form').reset();
    }

    // Delete Modal Functions
    function confirmDelete(id, name) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete-item-name').textContent = name;
        document.getElementById('delete-modal').classList.remove('hidden');
    }

    function closeDeleteModal() {
        document.getElementById('delete-modal').classList.add('hidden');
    }

    // Close modals when clicking outside
    window.addEventListener('click', function (event) {
        const editModal = document.getElementById('edit-modal');
        const deleteModal = document.getElementById('delete-modal');

        if (event.target === editModal) {
            closeEditModal();
        }

        if (event.target === deleteModal) {
            closeDeleteModal();
        }
    });
</script>