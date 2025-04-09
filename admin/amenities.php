<?php
ob_start();

require_once '../config/database.php';
session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$name = '';
$category = '';
$availability = 1;
$editId = null;
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $availability = isset($_POST['availability']) ? 1 : 0;
    
    // Validate input
    if (empty($name) || empty($category)) {
        $error = "Name and category are required fields.";
    } else {
        // Check if we're editing or adding
        if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
            // Update existing amenity
            $editId = (int)$_POST['edit_id'];
            $stmt = $conn->prepare("
                UPDATE amenities 
                SET name = ?, category = ?, availability = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $result = $stmt->execute([$name, $category, $availability, $editId]);
            
            if ($result) {
                $message = "Amenity updated successfully.";
                
                // Log the activity
                $adminId = $_SESSION['admin_id'];
                $activitySql = "
                    INSERT INTO activity_logs (
                        user_id, user_type, action, details, ip_address, user_agent
                    ) VALUES (
                        ?, 'admin', ?, ?, ?, ?
                    )
                ";
                $details = "Admin ID: {$adminId} updated amenity ID: {$editId}";
                $activityStmt = $conn->prepare($activitySql);
                $activityStmt->execute([
                    $adminId,
                    'update_amenity',
                    $details,
                    $_SERVER['REMOTE_ADDR'],
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                // Reset form
                $name = '';
                $category = '';
                $availability = 1;
                $editId = null;
            } else {
                $error = "Failed to update amenity. Please try again.";
            }
        } else {
            // Add new amenity
            // First check if amenity with same name and category already exists
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM amenities WHERE name = ? AND category = ?");
            $checkStmt->execute([$name, $category]);
            $exists = $checkStmt->fetchColumn();
            
            if ($exists) {
                $error = "An amenity with this name and category already exists.";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO amenities (name, category, availability, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                ");
                $result = $stmt->execute([$name, $category, $availability]);
                
                if ($result) {
                    $newId = $conn->lastInsertId();
                    $message = "Amenity added successfully.";
                    
                    // Log the activity
                    $adminId = $_SESSION['admin_id'];
                    $activitySql = "
                        INSERT INTO activity_logs (
                            user_id, user_type, action, details, ip_address, user_agent
                        ) VALUES (
                            ?, 'admin', ?, ?, ?, ?
                        )
                    ";
                    $details = "Admin ID: {$adminId} added new amenity ID: {$newId}";
                    $activityStmt = $conn->prepare($activitySql);
                    $activityStmt->execute([
                        $adminId,
                        'add_amenity',
                        $details,
                        $_SERVER['REMOTE_ADDR'],
                        $_SERVER['HTTP_USER_AGENT']
                    ]);
                    
                    // Reset form
                    $name = '';
                    $category = '';
                    $availability = 1;
                } else {
                    $error = "Failed to add amenity. Please try again.";
                }
            }
        }
    }
}

// Handle edit request
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM amenities WHERE id = ?");
    $stmt->execute([$editId]);
    $amenity = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($amenity) {
        $name = $amenity['name'];
        $category = $amenity['category'];
        $availability = $amenity['availability'];
    } else {
        $error = "Amenity not found.";
        $editId = null;
    }
}

// Handle delete request
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    
    // Check if amenity is in use by any gyms
    $checkStmt = $conn->prepare("
        SELECT COUNT(*) FROM gyms 
        WHERE JSON_CONTAINS(amenities, ?) OR amenities LIKE ?
    ");
    $checkStmt->execute(["\"$deleteId\"", "%$deleteId%"]);
    $inUse = $checkStmt->fetchColumn();
    
    if ($inUse) {
        $error = "This amenity cannot be deleted because it is being used by one or more gyms.";
    } else {
        $stmt = $conn->prepare("DELETE FROM amenities WHERE id = ?");
        $result = $stmt->execute([$deleteId]);
        
        if ($result) {
            $message = "Amenity deleted successfully.";
            
            // Log the activity
            $adminId = $_SESSION['admin_id'];
            $activitySql = "
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (
                    ?, 'admin', ?, ?, ?, ?
                )
            ";
            $details = "Admin ID: {$adminId} deleted amenity ID: {$deleteId}";
            $activityStmt = $conn->prepare($activitySql);
            $activityStmt->execute([
                $adminId,
                'delete_amenity',
                $details,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
        } else {
            $error = "Failed to delete amenity. Please try again.";
        }
    }
}

// Fetch all amenities
$stmt = $conn->prepare("
    SELECT * FROM amenities 
    ORDER BY category, name
");
$stmt->execute();
$amenities = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group amenities by category
$amenitiesByCategory = [];
foreach ($amenities as $amenity) {
    $category = $amenity['category'];
    if (!isset($amenitiesByCategory[$category])) {
        $amenitiesByCategory[$category] = [];
    }
    $amenitiesByCategory[$category][] = $amenity;
}

// Get unique categories for dropdown
$categories = array_keys($amenitiesByCategory);
if (!in_array($category, $categories) && !empty($category)) {
    $categories[] = $category;
}
sort($categories);

// Count total amenities
$totalAmenities = count($amenities);
$totalCategories = count($categories);
$totalActive = array_reduce($amenities, function($carry, $item) {
    return $carry + ($item['availability'] ? 1 : 0);
}, 0);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Amenities - FlexFit Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .amenity-card {
            transition: all 0.3s ease;
        }
        .amenity-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body class="bg-gray-900 text-white">
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="ml-64 p-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-3xl font-bold">Manage Amenities</h1>
            <div class="flex space-x-4">
                <div class="bg-gray-800 rounded-lg px-4 py-2 flex items-center">
                    <i class="fas fa-list text-yellow-500 mr-2"></i>
                    <span><?php echo $totalAmenities; ?> Total</span>
                </div>
                <div class="bg-gray-800 rounded-lg px-4 py-2 flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-2"></i>
                    <span><?php echo $totalActive; ?> Active</span>
                </div>
                <div class="bg-gray-800 rounded-lg px-4 py-2 flex items-center">
                    <i class="fas fa-folder text-blue-500 mr-2"></i>
                    <span><?php echo $totalCategories; ?> Categories</span>
                </div>
            </div>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="bg-green-600 text-white p-4 rounded-lg mb-6">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="bg-red-600 text-white p-4 rounded-lg mb-6">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Amenity Form -->
            <div class="lg:col-span-1">
                <div class="bg-gray-800 rounded-xl p-6 shadow-lg">
                    <h2 class="text-xl font-bold mb-4">
                        <?php echo $editId ? 'Edit Amenity' : 'Add New Amenity'; ?>
                    </h2>
                    
                    <form method="POST" class="space-y-4">
                        <?php if ($editId): ?>
                            <input type="hidden" name="edit_id" value="<?php echo $editId; ?>">
                        <?php endif; ?>
                        
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-400 mb-1">Name</label>
                            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name); ?>" required
                                   class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                        </div>
                        
                        <div>
                            <label for="category" class="block text-sm font-medium text-gray-400 mb-1">Category</label>
                            <div class="relative">
                                <input type="text" id="category" name="category" value="<?php echo htmlspecialchars($category); ?>" required
                                       list="category-list" autocomplete="off"
                                       class="w-full bg-gray-700 border border-gray-600 rounded-lg p-3 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <datalist id="category-list">
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Type a new category or select from existing ones</p>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="availability" name="availability" value="1" 
                                   <?php echo $availability ? 'checked' : ''; ?>
                                   class="h-5 w-5 text-yellow-500 focus:ring-yellow-500 border-gray-600 rounded">
                            <label for="availability" class="ml-2 block text-sm text-gray-400">
                                Available for gyms to select
                            </label>
                        </div>
                        
                        <div class="flex space-x-3 pt-2">
                            <?php if ($editId): ?>
                                <a href="amenities.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                    Cancel
                                </a>
                            <?php endif; ?>
                            
                            <button type="submit" class="bg-yellow-500 hover:bg-yellow-600 text-black px-4 py-2 rounded-lg font-medium transition-colors duration-200">
                                <?php echo $editId ? 'Update Amenity' : 'Add Amenity'; ?>
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="bg-gray-800 rounded-xl p-6 shadow-lg mt-6">
                    <h2 class="text-xl font-bold mb-4">Quick Tips</h2>
                    <ul class="space-y-2 text-gray-300">
                        <li class="flex items-start">
                            <i class="fas fa-info-circle text-yellow-500 mt-1 mr-2"></i>
                            <span>Group similar amenities under the same category for better organization.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-info-circle text-yellow-500 mt-1 mr-2"></i>
                            <span>Inactive amenities won't appear in the selection list for gym owners.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-info-circle text-yellow-500 mt-1 mr-2"></i>
                            <span>You cannot delete amenities that are currently being used by gyms.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-info-circle text-yellow-500 mt-1 mr-2"></i>
                            <span>Use clear, concise names for amenities to help users understand what's available.</span>
                        </li>
                    </ul>
                </div>
            </div>
            
            <!-- Amenities List -->
            <div class="lg:col-span-2">
                <?php if (empty($amenitiesByCategory)): ?>
                    <div class="bg-gray-800 rounded-xl p-8 text-center">
                        <i class="fas fa-spa text-yellow-500 text-5xl mb-4"></i>
                        <h2 class="text-2xl font-bold mb-2">No Amenities Found</h2>
                        <p class="text-gray-400">Start by adding amenities that gyms can offer to their members.</p>
                    </div>
                <?php else: ?>
                    <div class="bg-gray-800 rounded-xl p-6 shadow-lg">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-xl font-bold">All Amenities</h2>
                            
                            <div class="relative">
                                <input type="text" id="search-amenities" placeholder="Search amenities..." 
                                       class="bg-gray-700 border border-gray-600 rounded-lg pl-10 pr-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-yellow-500">
                                <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                            </div>
                        </div>
                        
                        <div id="amenities-container" class="space-y-6">
                            <?php foreach ($amenitiesByCategory as $categoryName => $categoryAmenities): ?>
                                <div class="amenity-category">
                                    <h3 class="text-lg font-semibold text-yellow-500 mb-3 flex items-center">
                                        <i class="fas fa-folder mr-2"></i>
                                        <?php echo htmlspecialchars($categoryName); ?>
                                        <span class="ml-2 text-sm text-gray-400">(<?php echo count($categoryAmenities); ?>)</span>
                                    </h3>
                                    
                                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                        <?php foreach ($categoryAmenities as $amenity): ?>
                                            <div class="amenity-card bg-gray-700 rounded-lg p-4 flex justify-between items-center">
                                                <div class="flex items-center">
                                                    <div class="h-8 w-8 rounded-full <?php echo $amenity['availability'] ? 'bg-green-500' : 'bg-gray-500'; ?> flex items-center justify-center mr-3">
                                                        <i class="fas fa-check text-white"></i>
                                                    </div>
                                                    <div>
                                                        <h4 class="font-medium"><?php echo htmlspecialchars($amenity['name']); ?></h4>
                                                        <p class="text-xs text-gray-400">
                                                            <?php echo $amenity['availability'] ? 'Active' : 'Inactive'; ?>
                                                        </p>
                                                    </div>
                                                </div>
                                                
                                                <div class="flex space-x-2">
                                                    <a href="amenities.php?edit=<?php echo $amenity['id']; ?>" class="text-blue-400 hover:text-blue-300">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="#" onclick="confirmDelete(<?php echo $amenity['id']; ?>, '<?php echo addslashes($amenity['name']); ?>')" class="text-red-400 hover:text-red-300">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div id="no-results" class="hidden py-8 text-center">
                            <i class="fas fa-search text-gray-500 text-4xl mb-3"></i>
                            <p class="text-gray-400">No amenities match your search.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Delete Confirmation Modal -->
    <div id="delete-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center hidden">
        <div class="bg-gray-800 rounded-xl p-6 max-w-md w-full mx-4">
            <h3 class="text-xl font-bold mb-4">Confirm Deletion</h3>
            <p class="text-gray-300 mb-6">Are you sure you want to delete the amenity <span id="delete-amenity-name" class="font-medium text-white"></span>? This action cannot be undone.</p>
            
            <div class="flex justify-end space-x-3">
                <button id="cancel-delete" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    Cancel
                </button>
                <a id="confirm-delete" href="#" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                    Delete
                </a>
            </div>
        </div>
    </div>
    
    <script>
        // Search functionality
        document.getElementById('search-amenities').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const amenityCategories = document.querySelectorAll('.amenity-category');
            let totalVisible = 0;
            
            amenityCategories.forEach(category => {
                const amenityCards = category.querySelectorAll('.amenity-card');
                let visibleCards = 0;
                
                amenityCards.forEach(card => {
                    const amenityName = card.querySelector('h4').textContent.toLowerCase();
                    const isVisible = amenityName.includes(searchTerm);
                    
                    card.classList.toggle('hidden', !isVisible);
                    
                    if (isVisible) {
                        visibleCards++;
                        totalVisible++;
                    }
                });
                
                // Hide category if no visible amenities
                category.classList.toggle('hidden', visibleCards === 0);
            });
            
            // Show/hide no results message
            document.getElementById('no-results').classList.toggle('hidden', totalVisible > 0);
        });
        
        // Delete confirmation
        function confirmDelete(id, name) {
            const modal = document.getElementById('delete-modal');
            const nameSpan = document.getElementById('delete-amenity-name');
            const confirmLink = document.getElementById('confirm-delete');
            
            nameSpan.textContent = name;
            confirmLink.href = `amenities.php?delete=${id}`;
            modal.classList.remove('hidden');
        }
        
        document.getElementById('cancel-delete').addEventListener('click', function() {
            document.getElementById('delete-modal').classList.add('hidden');
        });
        
        // Close modal when clicking outside
        document.getElementById('delete-modal').addEventListener('click', function(event) {
            if (event.target === this) {
                this.classList.add('hidden');
            }
        });
        
        // Escape key to close modal
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                document.getElementById('delete-modal').classList.add('hidden');
            }
        });
        
        // Highlight newly added/edited amenity
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($message) && isset($_POST['name'])): ?>
            const searchInput = document.getElementById('search-amenities');
            searchInput.value = '<?php echo addslashes($name); ?>';
            searchInput.dispatchEvent(new Event('input'));
            <?php endif; ?>
        });
    </script>
</body>
</html>

