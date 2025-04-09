<?php
// Check if admin is logged in
include '../includes/navbar.php';

if (!isset($_SESSION['admin_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$success_message = '';
$error_message = '';

// Get current footer settings
$sql = "SELECT * FROM site_settings WHERE setting_group = 'footer'";
$stmt = $conn->prepare($sql);
$stmt->execute();
$footerSettings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create settings array
$settings = [];
foreach ($footerSettings as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

// Default values if not set
$defaults = [
    'brand_name' => 'FITCONNECT',
    'tagline' => 'Transform your fitness journey with access to premium gyms across India.',
    'facebook_url' => '#',
    'instagram_url' => '#',
    'twitter_url' => '#',
    'address' => '123 Fitness Street, Gym City, India',
    'phone' => '+91 123 456 7890',
    'email' => 'info@fitconnect.com',
    'copyright_text' => '© ' . date('Y') . ' FitConnect. All rights reserved.',
    'privacy_url' => 'privacy.php',
    'terms_url' => 'terms.php',
    'cookie_url' => 'cookie-policy.php',
    'about_url' => 'about.php',
    'gyms_url' => 'all-gyms.php',
    'trainers_url' => 'trainers.php',
    'membership_url' => 'membership.php',
    'contact_url' => 'contact.php',
    'faq_url' => 'faq.php',
    'footer_bg_color' => 'from-gray-900 to-black',
    'footer_text_color' => 'text-white',
    'footer_accent_color' => 'text-yellow-400',
    'footer_hover_color' => 'hover:text-yellow-400'
];

// Merge with defaults
foreach ($defaults as $key => $value) {
    if (!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Process each setting
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'footer_') === 0 || in_array($key, array_keys($defaults))) {
                $value = trim($value);
                
                // Check if setting exists
                $checkSql = "SELECT COUNT(*) FROM site_settings WHERE setting_group = 'footer' AND setting_key = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->execute([$key]);
                $exists = $checkStmt->fetchColumn();
                
                if ($exists) {
                    // Update existing setting
                    $updateSql = "UPDATE site_settings SET setting_value = ?, updated_at = NOW() WHERE setting_group = 'footer' AND setting_key = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->execute([$value, $key]);
                } else {
                    // Insert new setting
                    $insertSql = "INSERT INTO site_settings (setting_group, setting_key, setting_value, created_at, updated_at) VALUES ('footer', ?, ?, NOW(), NOW())";
                    $insertStmt = $conn->prepare($insertSql);
                    $insertStmt->execute([$key, $value]);
                }
                
                // Update local array for display
                $settings[$key] = $value;
            }
        }
        
        // Log the activity
        $activitySql = "
            INSERT INTO activity_logs (
                user_id, user_type, action, details, ip_address, user_agent
            ) VALUES (
                ?, 'admin', 'update_footer', 'Updated footer settings', ?, ?
            )
        ";
        $activityStmt = $conn->prepare($activitySql);
        $activityStmt->execute([
            $_SESSION['admin_id'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        
        $conn->commit();
        $success_message = "Footer settings updated successfully!";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = "Error updating footer settings: " . $e->getMessage();
    }
}

// Generate footer preview
function generateFooterPreview($settings) {
    ob_start();
?>
<footer class="bg-gradient-to-b <?php echo $settings['footer_bg_color']; ?> relative pt-20 pb-10">
    <div class="absolute inset-0 bg-pattern opacity-10"></div>
    
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
        <!-- Footer Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-12 mb-16">
            <!-- Brand Section -->
            <div>
                <h3 class="text-2xl font-bold <?php echo $settings['footer_text_color']; ?> mb-6">
                    <span class="<?php echo $settings['footer_accent_color']; ?>"><?php echo explode(' ', $settings['brand_name'])[0] ?? 'FIT'; ?></span><?php echo substr($settings['brand_name'], strlen(explode(' ', $settings['brand_name'])[0])) ?? 'CONNECT'; ?>
                </h3>
                <p class="<?php echo $settings['footer_text_color']; ?> mb-6"><?php echo htmlspecialchars($settings['tagline']); ?></p>
                <div class="flex space-x-4">
                    <a href="<?php echo htmlspecialchars($settings['facebook_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> transition-colors">
                        <i class="fab fa-facebook-f text-xl"></i>
                    </a>
                    <a href="<?php echo htmlspecialchars($settings['instagram_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> transition-colors">
                        <i class="fab fa-instagram text-xl"></i>
                    </a>
                    <a href="<?php echo htmlspecialchars($settings['twitter_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> transition-colors">
                        <i class="fab fa-twitter text-xl"></i>
                    </a>
                </div>
            </div>

            <!-- Quick Links -->
            <div>
                <h4 class="text-lg font-bold <?php echo $settings['footer_text_color']; ?> mb-6">Quick Links</h4>
                <ul class="space-y-4">
                    <li><a href="<?php echo htmlspecialchars($settings['about_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> transition-colors">About Us</a></li>
                    <li><a href="<?php echo htmlspecialchars($settings['gyms_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> transition-colors">Find Gyms</a></li>
                    <li><a href="<?php echo htmlspecialchars($settings['trainers_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> transition-colors">Trainers</a></li>
                    <li><a href="<?php echo htmlspecialchars($settings['membership_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> transition-colors">Membership</a></li>
                </ul>
            </div>

            <!-- Support -->
            <div>
                <h4 class="text-lg font-bold <?php echo $settings['footer_text_color']; ?> mb-6">Support</h4>
                <ul class="space-y-4">
                    <li><a href="<?php echo htmlspecialchars($settings['contact_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> transition-colors">Contact Us</a></li>
                    <li><a href="<?php echo htmlspecialchars($settings['faq_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> transition-colors">FAQs</a></li>
                    <li><a href="<?php echo htmlspecialchars($settings['privacy_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> transition-colors">Privacy Policy</a></li>
                    <li><a href="<?php echo htmlspecialchars($settings['terms_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> transition-colors">Terms of Service</a></li>
                </ul>
            </div>

            <!-- Contact Info -->
            <div>
                <h4 class="text-lg font-bold <?php echo $settings['footer_text_color']; ?> mb-6">Contact Info</h4>
                <ul class="space-y-4">
                    <li class="flex items-start space-x-3">
                        <i class="fas fa-map-marker-alt <?php echo $settings['footer_accent_color']; ?> mt-1"></i>
                        <span class="<?php echo $settings['footer_text_color']; ?>"><?php echo htmlspecialchars($settings['address']); ?></span>
                    </li>
                    <li class="flex items-center space-x-3">
                        <i class="fas fa-phone <?php echo $settings['footer_accent_color']; ?>"></i>
                        <span class="<?php echo $settings['footer_text_color']; ?>"><?php echo htmlspecialchars($settings['phone']); ?></span>
                    </li>
                    <li class="flex items-center space-x-3">
                        <i class="fas fa-envelope <?php echo $settings['footer_accent_color']; ?>"></i>
                        <span class="<?php echo $settings['footer_text_color']; ?>"><?php echo htmlspecialchars($settings['email']); ?></span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Bottom Bar -->
        <div class="border-t border-gray-800 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <p class="<?php echo $settings['footer_text_color']; ?> text-sm mb-4 md:mb-0">
                    <?php echo htmlspecialchars($settings['copyright_text']); ?>
                </p>
                <div class="flex space-x-6">
                    <a href="<?php echo htmlspecialchars($settings['privacy_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> text-sm transition-colors">Privacy Policy</a>
                    <a href="<?php echo htmlspecialchars($settings['terms_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> text-sm transition-colors">Terms of Service</a>
                    <a href="<?php echo htmlspecialchars($settings['cookie_url']); ?>" class="<?php echo $settings['footer_text_color'] . ' ' . $settings['footer_hover_color']; ?> text-sm transition-colors">Cookie Policy</a>
                </div>
            </div>
        </div>
    </div>
</footer>
<?php
    return ob_get_clean();
}

// Color options
$bgColorOptions = [
    'from-gray-900 to-black' => 'Dark (Default)',
    'from-blue-900 to-blue-950' => 'Deep Blue',
    'from-purple-900 to-black' => 'Purple Dark',
    'from-indigo-900 to-indigo-950' => 'Indigo',
    'from-gray-800 to-gray-900' => 'Medium Gray'
];

$accentColorOptions = [
    'text-yellow-400' => 'Yellow (Default)',
    'text-blue-400' => 'Blue',
    'text-green-400' => 'Green',
    'text-purple-400' => 'Purple',
    'text-red-400' => 'Red',
    'text-orange-400' => 'Orange',
    'text-pink-400' => 'Pink',
    'text-indigo-400' => 'Indigo'
];

$hoverColorOptions = [
    'hover:text-yellow-400' => 'Yellow (Default)',
    'hover:text-blue-400' => 'Blue',
    'hover:text-green-400' => 'Green',
    'hover:text-purple-400' => 'Purple',
    'hover:text-red-400' => 'Red',
    'hover:text-orange-400' => 'Orange',
    'hover:text-pink-400' => 'Pink',
    'hover:text-indigo-400' => 'Indigo'
];

$textColorOptions = [
    'text-white' => 'White (Default)',
    'text-gray-200' => 'Light Gray',
    'text-gray-300' => 'Medium Gray'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Footer - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .preview-container {
            transform: scale(0.8);
            transform-origin: top center;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8 pt-20">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Manage  Footer</h1>
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
            </a>
        </div>

        <?php if ($success_message): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
            <p><?php echo htmlspecialchars($success_message); ?></p>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p><?php echo htmlspecialchars($error_message); ?></p>
        </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg shadow-md overflow-hidden mb-8">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-700">Footer Preview</h2>
            </div>
            <div class="p-6 bg-gray-800">
                <div class="preview-container w-full overflow-hidden">
                    <?php echo generateFooterPreview($settings); ?>
                </div>
            </div>
        </div>

        <form action="" method="POST" class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-700">Footer Settings</h2>
            </div>

            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Branding Section -->
                    <div class="col-span-1 md:col-span-2 bg-gray-50 p-4 rounded-lg">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Branding</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="brand_name" class="block text-sm font-medium text-gray-700 mb-1">Brand Name</label>
                                <input type="text" id="brand_name" name="brand_name" value="<?php echo htmlspecialchars($settings['brand_name']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                                <p class="mt-1 text-xs text-gray-500">First word will be highlighted with accent color</p>
                            </div>
                            
                            <div>
                                <label for="tagline" class="block text-sm font-medium text-gray-700 mb-1">Tagline</label>
                                <input type="text" id="tagline" name="tagline" value="<?php echo htmlspecialchars($settings['tagline']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                        </div>
                    </div>

                    <!-- Social Media Links -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Social Media</h3>
                        
                        <div class="space-y-4">
                            <div>
                                <label for="facebook_url" class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fab fa-facebook-f mr-1"></i> Facebook URL
                                </label>
                                <input type="url" id="facebook_url" name="facebook_url" value="<?php echo htmlspecialchars($settings['facebook_url']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                            
                            <div>
                                <label for="instagram_url" class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fab fa-instagram mr-1"></i> Instagram URL
                                </label>
                                <input type="url" id="instagram_url" name="instagram_url" value="<?php echo htmlspecialchars($settings['instagram_url']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                            
                            <div>
                                <label for="twitter_url" class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fab fa-twitter mr-1"></i> Twitter URL
                                </label>
                                <input type="url" id="twitter_url" name="twitter_url" value="<?php echo htmlspecialchars($settings['twitter_url']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Contact Information</h3>
                        
                        <div class="space-y-4">
                            <div>
                                <label for="address" class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-map-marker-alt mr-1"></i> Address
                                </label>
                                <input type="text" id="address" name="address" value="<?php echo htmlspecialchars($settings['address']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-phone mr-1"></i> Phone
                                </label>
                                <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($settings['phone']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-envelope mr-1"></i> Email
                                </label>
                                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($settings['email']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                        </div>
                    </div>

                    <!-- Copyright & Legal -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Copyright & Legal</h3>
                        
                        <div class="space-y-4">
                            <div>
                                <label for="copyright_text" class="block text-sm font-medium text-gray-700 mb-1">
                                    <i class="fas fa-copyright mr-1"></i> Copyright Text
                                </label>
                                <input type="text" id="copyright_text" name="copyright_text" value="<?php echo htmlspecialchars($settings['copyright_text']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label for="privacy_url" class="block text-sm font-medium text-gray-700 mb-1">Privacy Policy URL</label>
                                    <input type="text" id="privacy_url" name="privacy_url" value="<?php echo htmlspecialchars($settings['privacy_url']); ?>" 
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                                </div>
                                
                                <div>
                                    <label for="terms_url" class="block text-sm font-medium text-gray-700 mb-1">Terms of Service URL</label>
                                    <input type="text" id="terms_url" name="terms_url" value="<?php echo htmlspecialchars($settings['terms_url']); ?>" 
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                                </div>
                                
                                <div>
                                    <label for="cookie_url" class="block text-sm font-medium text-gray-700 mb-1">Cookie Policy URL</label>
                                    <input type="text" id="cookie_url" name="cookie_url" value="<?php echo htmlspecialchars($settings['cookie_url']); ?>" 
                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Links -->
                    <div class="col-span-1 md:col-span-2 bg-gray-50 p-4 rounded-lg">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Quick Links</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label for="about_url" class="block text-sm font-medium text-gray-700 mb-1">About Us URL</label>
                                <input type="text" id="about_url" name="about_url" value="<?php echo htmlspecialchars($settings['about_url']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                            
                            <div>
                                <label for="gyms_url" class="block text-sm font-medium text-gray-700 mb-1">Find Gyms URL</label>
                                <input type="text" id="gyms_url" name="gyms_url" value="<?php echo htmlspecialchars($settings['gyms_url']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                            
                            <div>
                                <label for="trainers_url" class="block text-sm font-medium text-gray-700 mb-1">Trainers URL</label>
                                <input type="text" id="trainers_url" name="trainers_url" value="<?php echo htmlspecialchars($settings['trainers_url']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                            
                            <div>
                                <label for="membership_url" class="block text-sm font-medium text-gray-700 mb-1">Membership URL</label>
                                <input type="text" id="membership_url" name="membership_url" value="<?php echo htmlspecialchars($settings['membership_url']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                            
                            <div>
                                <label for="contact_url" class="block text-sm font-medium text-gray-700 mb-1">Contact Us URL</label>
                                <input type="text" id="contact_url" name="contact_url" value="<?php echo htmlspecialchars($settings['contact_url']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                            
                            <div>
                                <label for="faq_url" class="block text-sm font-medium text-gray-700 mb-1">FAQs URL</label>
                                <input type="text" id="faq_url" name="faq_url" value="<?php echo htmlspecialchars($settings['faq_url']); ?>" 
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                            </div>
                        </div>
                    </div>

                    <!-- Appearance -->
                    <div class="col-span-1 md:col-span-2 bg-gray-50 p-4 rounded-lg">
                        <h3 class="text-lg font-semibold text-gray-700 mb-4">Appearance</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label for="footer_bg_color" class="block text-sm font-medium text-gray-700 mb-1">Background Color</label>
                                <select id="footer_bg_color" name="footer_bg_color" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                                    <?php foreach ($bgColorOptions as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $settings['footer_bg_color'] === $value ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="footer_text_color" class="block text-sm font-medium text-gray-700 mb-1">Text Color</label>
                                <select id="footer_text_color" name="footer_text_color" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                                    <?php foreach ($textColorOptions as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $settings['footer_text_color'] === $value ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="footer_accent_color" class="block text-sm font-medium text-gray-700 mb-1">Accent Color</label>
                                <select id="footer_accent_color" name="footer_accent_color" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                                    <?php foreach ($accentColorOptions as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $settings['footer_accent_color'] === $value ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="footer_hover_color" class="block text-sm font-medium text-gray-700 mb-1">Hover Color</label>
                                <select id="footer_hover_color" name="footer_hover_color" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-200">
                                    <?php foreach ($hoverColorOptions as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $settings['footer_hover_color'] === $value ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex justify-between">
                    <button type="button" id="preview-btn" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                        <i class="fas fa-eye mr-2"></i> Preview Changes
                    </button>
                    
                    <div>
                        <button type="reset" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg mr-2 transition duration-300">
                            <i class="fas fa-undo mr-2"></i> Reset
                        </button>
                        
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-save mr-2"></i> Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <!-- Export Footer Code Section -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden mt-8">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                <h2 class="text-lg font-semibold text-gray-700">Export Footer Code</h2>
                <button id="copy-code-btn" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-1 px-3 rounded-lg text-sm transition duration-300">
                    <i class="fas fa-copy mr-1"></i> Copy Code
                </button>
            </div>
            <div class="p-6">
                <p class="text-gray-600 mb-4">
                    You can copy this code and replace the content in <code class="bg-gray-100 px-2 py-1 rounded">includes/footer.php</code> to apply your customized footer.
                </p>
                <div class="relative">
                    <pre id="footer-code" class="bg-gray-800 text-gray-200 p-4 rounded-lg overflow-x-auto text-sm" style="max-height: 400px;"><?php 
                        $footerCode = file_get_contents('../includes/footer.php');
                        echo htmlspecialchars($footerCode);
                    ?></pre>
                    <div id="copy-success" class="hidden absolute top-2 right-2 bg-green-500 text-white px-3 py-1 rounded-lg text-sm">
                        Copied!
                    </div>
                </div>
            </div>
        </div>

        <!-- Generate New Footer Code Section -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden mt-8">
            <div class="px-6 py-4 bg-gray-50 border-b border-gray-200 flex justify-between items-center">
                <h2 class="text-lg font-semibold text-gray-700">Generate New Footer Code</h2>
                <button id="generate-code-btn" class="bg-green-600 hover:bg-green-700 text-white font-medium py-1 px-3 rounded-lg text-sm transition duration-300">
                    <i class="fas fa-code mr-1"></i> Generate Code
                </button>
            </div>
            <div class="p-6">
                <p class="text-gray-600 mb-4">
                    Click the button above to generate PHP code for your customized footer. You can then download this code and replace your existing footer.php file.
                </p>
                <div class="relative hidden" id="generated-code-container">
                    <pre id="generated-code" class="bg-gray-800 text-gray-200 p-4 rounded-lg overflow-x-auto text-sm" style="max-height: 400px;"></pre>
                    <div class="mt-4 flex space-x-3">
                        <button id="copy-generated-btn" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-copy mr-2"></i> Copy Code
                        </button>
                        <button id="download-btn" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-lg transition duration-300">
                            <i class="fas fa-download mr-2"></i> Download footer.php
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Preview changes without submitting the form
        document.getElementById('preview-btn').addEventListener('click', function() {
            const formData = new FormData(document.querySelector('form'));
            
            // Scroll to preview
            document.querySelector('.preview-container').scrollIntoView({ 
                behavior: 'smooth',
                block: 'start'
            });
            
            // Show loading indicator
            document.querySelector('.preview-container').innerHTML = '<div class="flex justify-center items-center h-64"><div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-yellow-500"></div></div>';
            
            // Send AJAX request to preview
            fetch('preview_footer.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                document.querySelector('.preview-container').innerHTML = html;
            })
            .catch(error => {
                console.error('Error:', error);
                document.querySelector('.preview-container').innerHTML = '<div class="text-red-500 p-4">Error generating preview</div>';
            });
        });
        
        // Copy footer code
        document.getElementById('copy-code-btn').addEventListener('click', function() {
            const codeElement = document.getElementById('footer-code');
            const successElement = document.getElementById('copy-success');
            
            // Create a temporary textarea to copy the text
            const textarea = document.createElement('textarea');
            textarea.value = codeElement.textContent;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            
            // Show success message
            successElement.classList.remove('hidden');
            setTimeout(() => {
                successElement.classList.add('hidden');
            }, 2000);
        });
        
        // Generate new footer code
        document.getElementById('generate-code-btn').addEventListener('click', function() {
            const formData = new FormData(document.querySelector('form'));
            formData.append('generate_code', '1');
            
            // Show loading indicator
            document.getElementById('generated-code-container').classList.remove('hidden');
            document.getElementById('generated-code').innerHTML = 'Generating code...';
            
            // Send AJAX request to generate code
            fetch('generate_footer_code.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(code => {
                document.getElementById('generated-code').textContent = code;
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('generated-code').innerHTML = '<div class="text-red-500">Error generating code</div>';
            });
        });
        
        // Copy generated code
        document.getElementById('copy-generated-btn').addEventListener('click', function() {
            const codeElement = document.getElementById('generated-code');
            
            // Create a temporary textarea to copy the text
            const textarea = document.createElement('textarea');
            textarea.value = codeElement.textContent;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            
            // Show success message
            this.innerHTML = '<i class="fas fa-check mr-2"></i> Copied!';
            setTimeout(() => {
                this.innerHTML = '<i class="fas fa-copy mr-2"></i> Copy Code';
            }, 2000);
        });
        
        // Download footer.php
        document.getElementById('download-btn').addEventListener('click', function() {
            const code = document.getElementById('generated-code').textContent;
            const blob = new Blob([code], { type: 'text/php' });
            const url = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = url;
            a.download = 'footer.php';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    </script>
</body>
</html>


