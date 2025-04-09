<?php
include 'includes/navbar.php';
require_once 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$db = new GymDatabase();
$conn = $db->getConnection();
$user_id = $_SESSION['user_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    
    // Handle profile image upload
    $profile_image = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/profiles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileExtension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $fileName = uniqid() . '.' . $fileExtension;
        $targetPath = $uploadDir . $fileName;
        
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetPath)) {
            $profile_image = $targetPath;
        }
    }

    try {
        $stmt = $conn->prepare("
            UPDATE users 
            SET username = ?, 
                email = ?, 
                phone = ?,
                " . ($profile_image ? "profile_image = ?," : "") . "
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");

        $params = [$username, $email, $phone];
        if ($profile_image) {
            $params[] = $profile_image;
        }
        $params[] = $user_id;

        $stmt->execute($params);
        
        header('Location: profile.php?success=1');
        exit;
    } catch (PDOException $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

// Fetch current user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

?>

<div class="min-h-screen bg-gradient-to-b from-gray-900 to-black py-12 sm:py-16 lg:py-20">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="max-w-4xl mx-auto">
            <div class="bg-gray-800 bg-opacity-50 backdrop-blur-lg rounded-3xl">
                <div class="p-6 sm:p-8 lg:p-10">
                    <h2 class="text-2xl sm:text-3xl font-bold text-white mb-8">Edit Profile</h2>
                    
                    <?php if (isset($error)): ?>
                        <div class="bg-red-900 border border-red-700 text-red-100 px-4 py-3 rounded-xl mb-6">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                    <div class="mb-6">
    <label class="block text-yellow-400 text-sm font-bold mb-2" for="profile_image">
        Profile Image
    </label>
    
    <!-- Image Preview Container -->
    <div class="mb-4 flex justify-center">
        <div class="w-32 h-32 rounded-full border-4 border-yellow-400 overflow-hidden">
            <img id="imagePreview" 
                 src="<?= htmlspecialchars($user['profile_image'] ?? 'assets/images/default-profile.png') ?>" 
                 alt="Profile Preview" 
                 class="w-full h-full object-cover">
        </div>
    </div>
    
    <input type="file" 
           name="profile_image" 
           accept="image/*"
           class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white focus:border-yellow-400 focus:outline-none">
</div>

                        <div class="mb-6">
                            <label class="block text-yellow-400 text-sm font-bold mb-2" for="username">
                                Username
                            </label>
                            <input type="text" 
                                   name="username" 
                                   value="<?= htmlspecialchars($user['username']) ?>"
                                   class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white focus:border-yellow-400 focus:outline-none">
                        </div>

                        <div class="mb-6">
                            <label class="block text-yellow-400 text-sm font-bold mb-2" for="email">
                                Email
                            </label>
                            <input type="email" 
                                   name="email" 
                                   value="<?= htmlspecialchars($user['email']) ?>"
                                   class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white focus:border-yellow-400 focus:outline-none">
                        </div>

                        <div class="mb-8">
                            <label class="block text-yellow-400 text-sm font-bold mb-2" for="phone">
                                Phone
                            </label>
                            <input type="tel" 
                                   name="phone" 
                                   value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                   class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-xl text-white focus:border-yellow-400 focus:outline-none">
                        </div>

                        <div class="flex flex-col sm:flex-row justify-end space-y-4 sm:space-y-0 sm:space-x-4">
                            <a href="profile.php" 
                               class="bg-gray-700 text-white px-6 py-3 rounded-full font-bold text-center
                                      hover:bg-gray-600 transform hover:scale-105 transition-all duration-300">
                                Cancel
                            </a>
                            <button type="submit" 
                                    class="bg-yellow-400 text-black px-6 py-3 rounded-full font-bold text-center
                                           hover:bg-yellow-500 transform hover:scale-105 transition-all duration-300">
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.querySelector('input[name="profile_image"]');
    const imagePreview = document.getElementById('imagePreview');

    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                imagePreview.src = e.target.result;
            }
            reader.readAsDataURL(file);
        }
    });
});
</script>