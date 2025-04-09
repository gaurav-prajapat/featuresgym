<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Get gym ID from the logged-in gym owner
$gymId = $_SESSION['gym_id'];

// Handle form submission to update section settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sections'])) {
    try {
        $conn->beginTransaction();
        
        // Process each section
        foreach ($_POST['sections'] as $sectionName => $settings) {
            $isVisible = isset($settings['visible']) ? 1 : 0;
            $displayOrder = (int)$settings['order'];
            $customTitle = trim($settings['title']);
            
            // Check if setting already exists
            $checkSql = "SELECT id FROM gym_page_settings WHERE gym_id = ? AND section_name = ?";
            $checkStmt = $conn->prepare($checkSql);
            $checkStmt->execute([$gymId, $sectionName]);
            
            if ($checkStmt->rowCount() > 0) {
                // Update existing setting
                $updateSql = "UPDATE gym_page_settings 
                              SET is_visible = ?, display_order = ?, custom_title = ?
                              WHERE gym_id = ? AND section_name = ?";
                $updateStmt = $conn->prepare($updateSql);
                $updateStmt->execute([$isVisible, $displayOrder, $customTitle, $gymId, $sectionName]);
            } else {
                // Insert new setting
                $insertSql = "INSERT INTO gym_page_settings 
                              (gym_id, section_name, is_visible, display_order, custom_title)
                              VALUES (?, ?, ?, ?, ?)";
                $insertStmt = $conn->prepare($insertSql);
                $insertStmt->execute([$gymId, $sectionName, $isVisible, $displayOrder, $customTitle]);
            }
        }
        
        $conn->commit();
        $successMessage = "Profile page settings updated successfully!";
    } catch (Exception $e) {
        $conn->rollBack();
        $errorMessage = "Error updating settings: " . $e->getMessage();
    }
}

// Fetch current section settings
$sectionsSql = "SELECT * FROM gym_page_settings WHERE gym_id = ? ORDER BY display_order ASC";
$sectionsStmt = $conn->prepare($sectionsSql);
$sectionsStmt->execute([$gymId]);
$sectionSettings = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Create an associative array for easier access
$sectionData = [];
foreach ($sectionSettings as $setting) {
    $sectionData[$setting['section_name']] = $setting;
}

// Default sections if not in database
$defaultSections = [
    'about' => ['title' => 'About This Gym', 'order' => 1],
    'amenities' => ['title' => 'Amenities', 'order' => 2],
    'operating_hours' => ['title' => 'Operating Hours', 'order' => 3],
    'membership_plans' => ['title' => 'Membership Plans', 'order' => 4],
    'equipment' => ['title' => 'Equipment', 'order' => 5],
    'gallery' => ['title' => 'Gallery', 'order' => 6],
    'reviews' => ['title' => 'Reviews', 'order' => 7],
    'similar_gyms' => ['title' => 'Similar Gyms Nearby', 'order' => 8]
];

// Merge defaults with database settings
foreach ($defaultSections as $sectionName => $defaults) {
    if (!isset($sectionData[$sectionName])) {
        $sectionData[$sectionName] = [
            'section_name' => $sectionName,
            'is_visible' => 1,
            'display_order' => $defaults['order'],
            'custom_title' => $defaults['title']
        ];
    }
}

// Sort sections by display order
uasort($sectionData, function($a, $b) {
    return $a['display_order'] - $b['display_order'];
});
?>

<div class="container px-6 py-8 mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-700 dark:text-white">Manage Profile Page</h1>
        <a href="view-profile.php" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
            <i class="fas fa-eye mr-2"></i>View Public Profile
        </a>
    </div>
    
    <?php if (isset($successMessage)): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
            <p><?php echo $successMessage; ?></p>
        </div>
    <?php endif; ?>
    
    <?php if (isset($errorMessage)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p><?php echo $errorMessage; ?></p>
        </div>
    <?php endif; ?>
    
    <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-700 dark:text-white mb-4">Profile Page Sections</h2>
            <p class="text-gray-600 dark:text-gray-400 mb-6">
                Customize which sections appear on your gym's public profile page and their order.
            </p>
            
            <form method="POST" action="">
                <div class="space-y-6">
                    <?php foreach ($sectionData as $sectionName => $section): ?>
                        <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                            <div class="flex flex-wrap items-center justify-between gap-4">
                                <div class="flex items-center">
                                    <input type="checkbox" 
                                           id="section_<?php echo $sectionName; ?>_visible" 
                                           name="sections[<?php echo $sectionName; ?>][visible]" 
                                           class="w-5 h-5 text-blue-600 rounded focus:ring-blue-500"
                                           <?php echo $section['is_visible'] ? 'checked' : ''; ?>>
                                    <label for="section_<?php echo $sectionName; ?>_visible" 
                                           class="ml-2 text-gray-700 dark:text-white font-medium">
                                        <?php echo ucwords(str_replace('_', ' ', $sectionName)); ?>
                                    </label>
                                </div>
                                
                                <div class="flex items-center space-x-4">
                                    <div>
                                        <label for="section_<?php echo $sectionName; ?>_order" 
                                               class="block text-sm text-gray-600 dark:text-gray-400">
                                            Display Order
                                        </label>
                                        <input type="number" 
                                               id="section_<?php echo $sectionName; ?>_order" 
                                               name="sections[<?php echo $sectionName; ?>][order]" 
                                               value="<?php echo $section['display_order']; ?>"
                                               class="mt-1 block w-20 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                    
                                    <div class="flex-grow">
                                        <label for="section_<?php echo $sectionName; ?>_title" 
                                               class="block text-sm text-gray-600 dark:text-gray-400">
                                            Section Title
                                        </label>
                                        <input type="text" 
                                               id="section_<?php echo $sectionName; ?>_title" 
                                               name="sections[<?php echo $sectionName; ?>][title]" 
                                               value="<?php echo htmlspecialchars($section['custom_title']); ?>"
                                               class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-8 flex justify-end">
                    <button type="submit" 
                            name="update_sections" 
                            class="px-6 py-3 bg-yellow-500 text-black rounded-md hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Additional Settings -->
    <div class="mt-8 bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
        <div class="p-6">
            <h2 class="text-lg font-medium text-gray-700 dark:text-white mb-4">Quick Links</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <a href="manage-equipment.php" class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg flex items-center hover:bg-gray-100 dark:hover:bg-gray-600 transition">
                    <i class="fas fa-dumbbell text-yellow-500 text-xl mr-3"></i>
                    <span class="text-gray-700 dark:text-white">Manage Equipment</span>
                </a>
                
                <a href="manage-gallery.php" class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg flex items-center hover:bg-gray-100 dark:hover:bg-gray-600 transition">
                    <i class="fas fa-images text-yellow-500 text-xl mr-3"></i>
                    <span class="text-gray-700 dark:text-white">Manage Gallery</span>
                </a>
                
                <a href="manage-plans.php" class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg flex items-center hover:bg-gray-100 dark:hover:bg-gray-600 transition">
                    <i class="fas fa-tags text-yellow-500 text-xl mr-3"></i>
                    <span class="text-gray-700 dark:text-white">Manage Plans</span>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add drag-and-drop functionality for reordering sections
    // This would require additional JavaScript libraries like SortableJS
    // For simplicity, we're using number inputs for now
});
</script>

<?php require_once 'includes/footer.php'; ?>
