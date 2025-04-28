<?php
ob_start();
include '../includes/navbar.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ./login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

$success = false;
$error = '';

try {
    // Create site_settings table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS `site_settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `setting_group` varchar(50) NOT NULL,
      `setting_key` varchar(100) NOT NULL,
      `setting_value` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      UNIQUE KEY `group_key` (`setting_group`, `setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $conn->exec($sql);
    
    // Check if default footer settings already exist
    $stmt = $conn->prepare("SELECT COUNT(*) FROM site_settings WHERE setting_group = 'footer'");
    $stmt->execute();
    $count = $stmt->fetchColumn();
    
    // Insert default footer settings if none exist
    if ($count == 0) {
        $defaultSettings = [
            ['footer', 'brand_name', 'FITCONNECT'],
            ['footer', 'tagline', 'Transform your fitness journey with access to premium gyms across India.'],
            ['footer', 'facebook_url', '#'],
            ['footer', 'instagram_url', '#'],
            ['footer', 'twitter_url', '#'],
            ['footer', 'address', '123 Fitness Street, Gym City, India'],
            ['footer', 'phone', '+91 123 456 7890'],
            ['footer', 'email', 'info@fitconnect.com'],
            ['footer', 'copyright_text', '© ' . date('Y') . ' FitConnect. All rights reserved.'],
            ['footer', 'privacy_url', 'privacy.php'],
            ['footer', 'terms_url', 'terms.php'],
            ['footer', 'cookie_url', 'cookie-policy.php'],
            ['footer', 'about_url', 'about.php'],
            ['footer', 'gyms_url', 'all-gyms.php'],
            ['footer', 'trainers_url', 'trainers.php'],
            ['footer', 'membership_url', 'membership.php'],
            ['footer', 'contact_url', 'contact.php'],
            ['footer', 'faq_url', 'faq.php'],
            ['footer', 'footer_bg_color', 'from-gray-900 to-black'],
            ['footer', 'footer_text_color', 'text-white'],
            ['footer', 'footer_accent_color', 'text-yellow-400'],
            ['footer', 'footer_hover_color', 'hover:text-yellow-400']
        ];
        
        $insertSql = "INSERT INTO site_settings (setting_group, setting_key, setting_value) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($insertSql);
        
        foreach ($defaultSettings as $setting) {
            $stmt->execute($setting);
        }
    }
    
    $success = true;
    
} catch (PDOException $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Site Settings Table - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Create Site Settings Table</h1>
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
            </a>
        </div>

        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-700">Database Setup</h2>
            </div>
            <div class="p-6">
                <?php if ($success): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert">
                        <p class="font-bold">Success!</p>
                        <p>The site_settings table has been created successfully.</p>
                    </div>
                    <p class="mb-4">You can now use the footer management feature.</p>
                    <div class="flex space-x-4">
                        <a href="manage_footer.php" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-cog mr-2"></i> Manage Footer
                        </a>
                        <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-home mr-2"></i> Go to Dashboard
                        </a>
                    </div>
                <?php else: ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                        <p class="font-bold">Error!</p>
                        <p>Failed to create the site_settings table: <?php echo htmlspecialchars($error); ?></p>
                    </div>
                    <p class="mb-4">Please check your database connection and try again.</p>
                    <div class="flex space-x-4">
                        <a href="create_site_settings.php" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-redo mr-2"></i> Try Again
                        </a>
                        <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-home mr-2"></i> Go to Dashboard
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
