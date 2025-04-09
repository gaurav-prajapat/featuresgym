<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/navbar.php';

// Initialize database connection
$db = new GymDatabase();
$conn = $db->getConnection();

// Get gym ID from the logged-in gym owner
$gymId = $_SESSION['gym_id'];

// Handle form submission to add images
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_images'])) {
        try {
            $uploadDir = '../gym/uploads/gym_images/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $captions = $_POST['captions'] ?? [];
            $uploadedFiles = 0;
            $errors = [];
            
            // Handle multiple file uploads
            $files = $_FILES['images'];
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $fileName = time() . '_' . basename($files['name'][$i]);
                    $uploadFile = $uploadDir . $fileName;
                    
                    // Check if it's an image
                    $check = getimagesize($files['tmp_name'][$i]);
                    if ($check === false) {
                        $errors[] = "File {$files['name'][$i]} is not an image.";
                        continue;
                    }
                    
                    // Check file size (max 5MB)
                    if ($files['size'][$i] > 5000000) {
                        $errors[] = "File {$files['name'][$i]} is too large. Maximum size is 5MB.";
                        continue;
                    }
                    
                    // Upload the file
                    if (move_uploaded_file($files['tmp_name'][$i], $uploadFile)) {
                        // Insert into database
                        $caption = isset($captions[$i]) ? trim($captions[$i]) : '';
                        $sql = "INSERT INTO gym_images (gym_id, image_path, caption, is_cover) VALUES (?, ?, ?, 0)";
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([$gymId, $fileName, $caption]);
                        $uploadedFiles++;
                    } else {
                        $errors[] = "Failed to upload file {$files['name'][$i]}.";
                    }
                } elseif ($files['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = "Error uploading file {$files['name'][$i]}: " . $files['error'][$i];
                }
            }
            
            if ($uploadedFiles > 0) {
                $successMessage = "$uploadedFiles image(s) uploaded successfully!";
            }
            
            if (!empty($errors)) {
                $errorMessage = implode("<br>", $errors);
            }
        } catch (Exception $e) {
            $errorMessage = "Error: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_image'])) {
        // Delete image
        $imageId = (int)$_POST['image_id'];
        
        try {
            // First get the image path to delete the file
            $getImageSql = "SELECT image_path FROM gym_images WHERE image_id = ? AND gym_id = ?";
            $getImageStmt = $conn->prepare($getImageSql);
            $getImageStmt->execute([$imageId, $gymId]);
            $imagePath = $getImageStmt->fetchColumn();
            
            // Delete from database
            $deleteSql = "DELETE FROM gym_images WHERE image_id = ? AND gym_id = ?";
            $deleteStmt = $conn->prepare($deleteSql);
            $deleteStmt->execute([$imageId, $gymId]);
            
            // Delete image file if it exists
            if ($imagePath && file_exists('../gym/uploads/gym_images/' . $imagePath)) {
                unlink('../gym/uploads/gym_images/' . $imagePath);
            }
            
            $successMessage = "Image deleted successfully!";
        } catch (Exception $e) {
            $errorMessage = "Error deleting image: " . $e->getMessage();
        }
    } elseif (isset($_POST['set_cover'])) {
        // Set image as cover
        $imageId = (int)$_POST['image_id'];
        
        try {
            // Begin transaction
            $conn->beginTransaction();
            
            // First reset all images to non-cover
            $resetSql = "UPDATE gym_images SET is_cover = 0 WHERE gym_id = ?";
            $resetStmt = $conn->prepare($resetSql);
            $resetStmt->execute([$gymId]);
            
            // Set the selected image as cover
            $setCoverSql = "UPDATE gym_images SET is_cover = 1 WHERE image_id = ? AND gym_id = ?";
            $setCoverStmt = $conn->prepare($setCoverSql);
            $setCoverStmt->execute([$imageId, $gymId]);
            
            // Also update the gym's cover_photo field
            $getImagePathSql = "SELECT image_path FROM gym_images WHERE image_id = ?";
            $getImagePathStmt = $conn->prepare($getImagePathSql);
            $getImagePathStmt->execute([$imageId]);
            $imagePath = $getImagePathStmt->fetchColumn();
            
            $updateGymSql = "UPDATE gyms SET cover_photo = ? WHERE gym_id = ?";
            $updateGymStmt = $conn->prepare($updateGymSql);
            $updateGymStmt->execute([$imagePath, $gymId]);
            
            $conn->commit();
            
            $successMessage = "Cover image updated successfully!";
        } catch (Exception $e) {
            $conn->rollBack();
            $errorMessage = "Error updating cover image: " . $e->getMessage();
        }
    } elseif (isset($_POST['update_captions'])) {
        // Update image captions
        $imageIds = $_POST['image_ids'] ?? [];
        $captions = $_POST['update_captions'] ?? [];
        
        try {
            $conn->beginTransaction();
            
            foreach ($imageIds as $index => $id) {
                $caption = isset($captions[$index]) ? trim($captions[$index]) : '';
                $sql = "UPDATE gym_images SET caption = ? WHERE image_id = ? AND gym_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$caption, $id, $gymId]);
            }
            
            $conn->commit();
            $successMessage = "Image captions updated successfully!";
        } catch (Exception $e) {
            $conn->rollBack();
            $errorMessage = "Error updating captions: " . $e->getMessage();
        }
    }
}

// Fetch all images for this gym
$imagesSql = "SELECT * FROM gym_images WHERE gym_id = ? ORDER BY is_cover DESC, image_id DESC";
$imagesStmt = $conn->prepare($imagesSql);
$imagesStmt->execute([$gymId]);
$images = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

// Check if we have a cover image
$hasCover = false;
foreach ($images as $image) {
    if ($image['is_cover'] == 1) {
        $hasCover = true;
        break;
    }
}
?>

<div class="container px-6 py-8 mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-700 dark:text-white">Manage Gallery</h1>
        <a href="manage-profile.php" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700">
            <i class="fas fa-arrow-left mr-2"></i>Back to Profile Management
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
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Upload Form -->
        <div class="md:col-span-1">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-700 dark:text-white mb-4">Upload Images</h2>
                    
                    <form method="POST" enctype="multipart/form-data" id="upload-form">
                        <div class="space-y-4">
                        <div>
                                <label for="images" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Select Images
                                </label>
                                <input type="file" 
                                       id="images" 
                                       name="images[]" 
                                       multiple
                                       accept="image/*"
                                       class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    You can select multiple images. Max file size: 5MB each.
                                </p>
                            </div>
                            
                            <div id="caption-container" class="space-y-3">
                                <!-- Captions will be added here dynamically -->
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <button type="submit" 
                                    name="add_images" 
                                    class="w-full px-4 py-2 bg-yellow-500 text-black rounded-md hover:bg-yellow-600 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
                                Upload Images
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden mt-6">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-700 dark:text-white mb-4">Gallery Tips</h2>
                    
                    <ul class="space-y-2 text-sm text-gray-600 dark:text-gray-400">
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                            <span>Upload high-quality images that showcase your gym's facilities.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                            <span>Include images of different areas: workout spaces, equipment, locker rooms, etc.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                            <span>Add descriptive captions to help visitors understand what they're seeing.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-2"></i>
                            <span>Select your best image as the cover photo - it will be the first impression for potential customers.</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Gallery -->
        <div class="md:col-span-2">
            <div class="bg-white dark:bg-gray-800 shadow rounded-lg overflow-hidden">
                <div class="p-6">
                    <h2 class="text-lg font-medium text-gray-700 dark:text-white mb-4">Gallery Images</h2>
                    
                    <?php if (empty($images)): ?>
                        <div class="text-center py-8">
                            <p class="text-gray-500 dark:text-gray-400">No images uploaded yet. Use the form to add images to your gallery.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($images as $image): ?>
                                <div class="bg-gray-50 dark:bg-gray-700 rounded-lg overflow-hidden">
                                    <div class="relative h-48">
                                        <img src="../gym/uploads/gym_images/<?php echo htmlspecialchars($image['image_path']); ?>" 
                                             alt="<?php echo htmlspecialchars($image['caption'] ?? 'Gallery image'); ?>"
                                             class="w-full h-full object-cover">
                                        
                                        <?php if ($image['is_cover'] == 1): ?>
                                            <div class="absolute top-2 left-2 bg-yellow-500 text-black px-2 py-1 rounded-md text-xs font-medium">
                                                Cover Photo
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="p-4">
                                        <form method="POST" class="mb-3">
                                            <input type="hidden" name="image_id" value="<?php echo $image['image_id']; ?>">
                                            <input type="text" 
                                                   name="update_captions[]" 
                                                   value="<?php echo htmlspecialchars($image['caption'] ?? ''); ?>"
                                                   placeholder="Add a caption"
                                                   class="w-full text-sm border-gray-300 dark:border-gray-600 dark:bg-gray-700 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                            <input type="hidden" name="image_ids[]" value="<?php echo $image['image_id']; ?>">
                                        </form>
                                        
                                        <div class="flex justify-between mt-3">
                                            <div>
                                                <?php if ($image['is_cover'] != 1): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="image_id" value="<?php echo $image['image_id']; ?>">
                                                        <button type="submit" 
                                                                name="set_cover" 
                                                                class="text-sm text-blue-600 hover:text-blue-500 dark:text-blue-400 dark:hover:text-blue-300">
                                                            Set as Cover
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this image?');">
                                                <input type="hidden" name="image_id" value="<?php echo $image['image_id']; ?>">
                                                <button type="submit" 
                                                        name="delete_image" 
                                                        class="text-sm text-red-600 hover:text-red-500 dark:text-red-400 dark:hover:text-red-300">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mt-6 flex justify-end">
                            <button type="button" 
                                    onclick="updateAllCaptions()"
                                    class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                Save All Captions
                            </button>
                        </div>
                        
                        <!-- Hidden form for updating all captions -->
                        <form id="update-captions-form" method="POST" class="hidden">
                            <input type="hidden" name="update_captions" value="1">
                            <!-- Form fields will be added dynamically -->
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle file input change to dynamically add caption fields
    const fileInput = document.getElementById('images');
    const captionContainer = document.getElementById('caption-container');
    
    fileInput.addEventListener('change', function() {
        captionContainer.innerHTML = '';
        
        for (let i = 0; i < this.files.length; i++) {
            const file = this.files[i];
            const captionDiv = document.createElement('div');
            
            captionDiv.innerHTML = `
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Caption for ${file.name}
                </label>
                <input type="text" 
                       name="captions[${i}]" 
                       class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                       placeholder="Add a caption (optional)">
            `;
            
            captionContainer.appendChild(captionDiv);
        }
    });
});

// Function to update all captions at once
function updateAllCaptions() {
    const forms = document.querySelectorAll('.bg-gray-50 form, .bg-gray-700 form');
    const updateForm = document.getElementById('update-captions-form');
    
    // Clear previous inputs
    updateForm.innerHTML = '<input type="hidden" name="update_captions" value="1">';
    
    // Add all caption inputs to the form
    forms.forEach(form => {
        const imageId = form.querySelector('input[name="image_id"]').value;
        const caption = form.querySelector('input[name^="update_captions"]').value;
        
        const imageIdInput = document.createElement('input');
        imageIdInput.type = 'hidden';
        imageIdInput.name = 'image_ids[]';
        imageIdInput.value = imageId;
        
        const captionInput = document.createElement('input');
        captionInput.type = 'hidden';
        captionInput.name = 'update_captions[]';
        captionInput.value = caption;
        
        updateForm.appendChild(imageIdInput);
        updateForm.appendChild(captionInput);
    });
    
    // Submit the form
    updateForm.submit();
}
</script>

<?php require_once 'includes/footer.php'; ?>
