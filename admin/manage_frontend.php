<?php
ob_start();
include '../includes/navbar.php';

// Check if user is logged in as admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /gym/views/auth/login.php');
    exit();
}

require_once '../config/database.php';
$db = new GymDatabase();
$conn = $db->getConnection();

// Initialize variables
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Theme settings update
    if (isset($_POST['update_theme'])) {
        try {
            $primary_color = filter_input(INPUT_POST, 'primary_color', FILTER_SANITIZE_STRING);
            $secondary_color = filter_input(INPUT_POST, 'secondary_color', FILTER_SANITIZE_STRING);
            $accent_color = filter_input(INPUT_POST, 'accent_color', FILTER_SANITIZE_STRING);
            $default_theme = filter_input(INPUT_POST, 'default_theme', FILTER_SANITIZE_STRING);
            $allow_user_theme = isset($_POST['allow_user_theme']) ? 1 : 0;
            
            // Update settings in database
            $stmt = $conn->prepare("
                INSERT INTO site_settings (setting_group, setting_key, setting_value) 
                VALUES ('theme', 'primary_color', :primary_color),
                       ('theme', 'secondary_color', :secondary_color),
                       ('theme', 'accent_color', :accent_color),
                       ('theme', 'default_theme', :default_theme),
                       ('theme', 'allow_user_theme', :allow_user_theme)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            
            $stmt->execute([
                ':primary_color' => $primary_color,
                ':secondary_color' => $secondary_color,
                ':accent_color' => $accent_color,
                ':default_theme' => $default_theme,
                ':allow_user_theme' => $allow_user_theme
            ]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Updated frontend theme settings";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "update_theme_settings", $details, $ip, $user_agent]);
            
            $message = "Theme settings updated successfully!";
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
    
    // Homepage settings update
    if (isset($_POST['update_homepage'])) {
        try {
            $hero_title = filter_input(INPUT_POST, 'hero_title', FILTER_SANITIZE_STRING);
            $hero_subtitle = filter_input(INPUT_POST, 'hero_subtitle', FILTER_SANITIZE_STRING);
            $show_featured_gyms = isset($_POST['show_featured_gyms']) ? 1 : 0;
            $featured_gyms_count = filter_input(INPUT_POST, 'featured_gyms_count', FILTER_VALIDATE_INT);
            $show_testimonials = isset($_POST['show_testimonials']) ? 1 : 0;
            
            // Handle hero image upload if provided
            if (isset($_FILES['hero_image']) && $_FILES['hero_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/site_images/';
                
                // Create directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_name = 'hero_' . time() . '_' . basename($_FILES['hero_image']['name']);
                $upload_path = $upload_dir . $file_name;
                
                // Move uploaded file
                if (move_uploaded_file($_FILES['hero_image']['tmp_name'], $upload_path)) {
                    // Update hero image setting
                    $stmt = $conn->prepare("
                        INSERT INTO site_settings (setting_group, setting_key, setting_value) 
                        VALUES ('homepage', 'hero_image', :hero_image)
                        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
                    ");
                    
                    $stmt->execute([':hero_image' => $file_name]);
                }
            }
            
            // Update settings in database
            $stmt = $conn->prepare("
                INSERT INTO site_settings (setting_group, setting_key, setting_value) 
                VALUES ('homepage', 'hero_title', :hero_title),
                       ('homepage', 'hero_subtitle', :hero_subtitle),
                       ('homepage', 'show_featured_gyms', :show_featured_gyms),
                       ('homepage', 'featured_gyms_count', :featured_gyms_count),
                       ('homepage', 'show_testimonials', :show_testimonials)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            
            $stmt->execute([
                ':hero_title' => $hero_title,
                ':hero_subtitle' => $hero_subtitle,
                ':show_featured_gyms' => $show_featured_gyms,
                ':featured_gyms_count' => $featured_gyms_count,
                ':show_testimonials' => $show_testimonials
            ]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Updated homepage settings";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "update_homepage_settings", $details, $ip, $user_agent]);
            
            $message = "Homepage settings updated successfully!";
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
    
    // SEO settings update
    if (isset($_POST['update_seo'])) {
        try {
            $site_title = filter_input(INPUT_POST, 'site_title', FILTER_SANITIZE_STRING);
            $meta_description = filter_input(INPUT_POST, 'meta_description', FILTER_SANITIZE_STRING);
            $meta_keywords = filter_input(INPUT_POST, 'meta_keywords', FILTER_SANITIZE_STRING);
            $google_analytics = filter_input(INPUT_POST, 'google_analytics', FILTER_SANITIZE_STRING);
            
            // Update settings in database
            $stmt = $conn->prepare("
                INSERT INTO site_settings (setting_group, setting_key, setting_value) 
                VALUES ('seo', 'site_title', :site_title),
                       ('seo', 'meta_description', :meta_description),
                       ('seo', 'meta_keywords', :meta_keywords),
                       ('seo', 'google_analytics', :google_analytics)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            
            $stmt->execute([
                ':site_title' => $site_title,
                ':meta_description' => $meta_description,
                ':meta_keywords' => $meta_keywords,
                ':google_analytics' => $google_analytics
            ]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Updated SEO settings";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "update_seo_settings", $details, $ip, $user_agent]);
            
            $message = "SEO settings updated successfully!";
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
    
    // Footer settings update
    if (isset($_POST['update_footer'])) {
        try {
            $footer_text = filter_input(INPUT_POST, 'footer_text', FILTER_SANITIZE_STRING);
            $copyright_text = filter_input(INPUT_POST, 'copyright_text', FILTER_SANITIZE_STRING);
            $facebook_url = filter_input(INPUT_POST, 'facebook_url', FILTER_SANITIZE_URL);
            $twitter_url = filter_input(INPUT_POST, 'twitter_url', FILTER_SANITIZE_URL);
            $instagram_url = filter_input(INPUT_POST, 'instagram_url', FILTER_SANITIZE_URL);
            $linkedin_url = filter_input(INPUT_POST, 'linkedin_url', FILTER_SANITIZE_URL);
            
            // Update settings in database
            $stmt = $conn->prepare("
                INSERT INTO site_settings (setting_group, setting_key, setting_value) 
                VALUES ('footer', 'footer_text', :footer_text),
                       ('footer', 'copyright_text', :copyright_text),
                       ('footer', 'facebook_url', :facebook_url),
                       ('footer', 'twitter_url', :twitter_url),
                       ('footer', 'instagram_url', :instagram_url),
                       ('footer', 'linkedin_url', :linkedin_url)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            
            $stmt->execute([
                ':footer_text' => $footer_text,
                ':copyright_text' => $copyright_text,
                ':facebook_url' => $facebook_url,
                ':twitter_url' => $twitter_url,
                ':instagram_url' => $instagram_url,
                ':linkedin_url' => $linkedin_url
            ]);
            
            // Log the activity
            $stmt = $conn->prepare("
                INSERT INTO activity_logs (
                    user_id, user_type, action, details, ip_address, user_agent
                ) VALUES (?, 'admin', ?, ?, ?, ?)
            ");
            
            $details = "Updated footer settings";
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([$_SESSION['user_id'], "update_footer_settings", $details, $ip, $user_agent]);
            
            $message = "Footer settings updated successfully!";
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch current settings
try {
    $stmt = $conn->prepare("SELECT setting_group, setting_key, setting_value FROM site_settings");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize settings by group and key
    $site_settings = [];
    foreach ($settings as $setting) {
        $site_settings[$setting['setting_group']][$setting['setting_key']] = $setting['setting_value'];
    }
} catch (PDOException $e) {
    $error = "Error fetching settings: " . $e->getMessage();
    $site_settings = [];
}

// Default values if settings don't exist
$theme_settings = $site_settings['theme'] ?? [
    'primary_color' => '#111827',
    'secondary_color' => '#1F2937',
    'accent_color' => '#FBBF24',
    'default_theme' => 'dark',
    'allow_user_theme' => '1'
];

$homepage_settings = $site_settings['homepage'] ?? [
    'hero_title' => 'Find Your Perfect Gym',
    'hero_subtitle' => 'Discover the best fitness centers near you',
    'hero_image' => 'default_hero.jpg',
    'show_featured_gyms' => '1',
    'featured_gyms_count' => '6',
    'show_testimonials' => '1'
];

$seo_settings = $site_settings['seo'] ?? [
    'site_title' => 'ProFitMart - Find Your Perfect Gym',
    'meta_description' => 'ProFitMart helps you find and book the best gyms near you. Discover fitness centers, compare plans, and schedule your workouts.',
    'meta_keywords' => 'gym, fitness, workout, health, exercise, gym booking',
    'google_analytics' => ''
];

$footer_settings = $site_settings['footer'] ?? [
    'footer_text' => 'ProFitMart helps you find and book the best gyms near you.',
    'copyright_text' => '© ' . date('Y') . ' ProFitMart. All rights reserved.',
    'facebook_url' => 'https://facebook.com/',
    'twitter_url' => 'https://twitter.com/',
    'instagram_url' => 'https://instagram.com/',
    'linkedin_url' => 'https://linkedin.com/'
];
?>

<div class="container mx-auto px-4 py-8 pt-20">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Manage Frontend</h1>
        <a href="dashboard.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
        </a>
    </div>
    
    <?php if (!empty($message)): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded" role="alert">
        <p><?= htmlspecialchars($message) ?></p>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($error)): ?>
    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
        <p><?= htmlspecialchars($error) ?></p>
    </div>
    <?php endif; ?>
    
    <!-- Tabs Navigation -->
    <div class="mb-6 border-b border-gray-700">
        <ul class="flex flex-wrap -mb-px text-sm font-medium text-center">
            <li class="mr-2">
                <a href="#theme-settings" class="inline-block p-4 border-b-2 border-yellow-500 rounded-t-lg active text-yellow-500" aria-current="page">
                    <i class="fas fa-palette mr-2"></i> Theme Settings
                </a>
            </li>
            <li class="mr-2">
                <a href="#homepage-settings" class="inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-300 hover:border-gray-300">
                    <i class="fas fa-home mr-2"></i> Homepage Settings
                </a>
            </li>
            <li class="mr-2">
                <a href="#seo-settings" class="inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-300 hover:border-gray-300">
                    <i class="fas fa-search mr-2"></i> SEO Settings
                </a>
            </li>
            <li class="mr-2">
                <a href="#footer-settings" class="inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-300 hover:border-gray-300">
                    <i class="fas fa-shoe-prints mr-2"></i> Footer Settings
                </a>
            </li>
            <li class="mr-2">
                <a href="#custom-css" class="inline-block p-4 border-b-2 border-transparent rounded-t-lg hover:text-gray-300 hover:border-gray-300">
                    <i class="fas fa-code mr-2"></i> Custom CSS
                </a>
            </li>
        </ul>
    </div>
    
    <!-- Tab Content -->
    <div class="tab-content">
        <!-- Theme Settings Tab -->
        <div id="theme-settings" class="tab-pane active">
            <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4 flex items-center">
                    <i class="fas fa-palette text-yellow-500 mr-2"></i>
                    Theme Settings
                </h2>
                
                <form action="" method="POST">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="primary_color" class="block text-sm font-medium text-gray-400 mb-1">Primary Background Color</label>
                            <div class="flex">
                                <input type="color" id="primary_color" name="primary_color" value="<?= htmlspecialchars($theme_settings['primary_color']) ?>" 
                                    class="h-10 w-10 rounded border border-gray-600 cursor-pointer">
                                <input type="text" value="<?= htmlspecialchars($theme_settings['primary_color']) ?>" 
                                    class="ml-2 flex-1 bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500" 
                                    data-color-input="primary_color">
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Main background color for the site</p>
                        </div>
                        
                        <div>
                            <label for="secondary_color" class="block text-sm font-medium text-gray-400 mb-1">Secondary Background Color</label>
                            <div class="flex">
                                <input type="color" id="secondary_color" name="secondary_color" value="<?= htmlspecialchars($theme_settings['secondary_color']) ?>" 
                                    class="h-10 w-10 rounded border border-gray-600 cursor-pointer">
                                <input type="text" value="<?= htmlspecialchars($theme_settings['secondary_color']) ?>" 
                                    class="ml-2 flex-1 bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500" 
                                    data-color-input="secondary_color">
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Color for cards, modals, and other components</p>
                        </div>
                        
                        <div>
                            <label for="accent_color" class="block text-sm font-medium text-gray-400 mb-1">Accent Color</label>
                            <div class="flex">
                                <input type="color" id="accent_color" name="accent_color" value="<?= htmlspecialchars($theme_settings['accent_color']) ?>" 
                                    class="h-10 w-10 rounded border border-gray-600 cursor-pointer">
                                <input type="text" value="<?= htmlspecialchars($theme_settings['accent_color']) ?>" 
                                    class="ml-2 flex-1 bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500" 
                                    data-color-input="accent_color">
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Color for buttons, links, and highlights</p>
                        </div>
                        
                        <div>
                            <label for="default_theme" class="block text-sm font-medium text-gray-400 mb-1">Default Theme</label>
                            <select id="default_theme" name="default_theme" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                                <option value="dark" <?= $theme_settings['default_theme'] === 'dark' ? 'selected' : '' ?>>Dark Mode</option>
                                <option value="light" <?= $theme_settings['default_theme'] === 'light' ? 'selected' : '' ?>>Light Mode</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Default theme for new visitors</p>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <label class="inline-flex items-center">
                            <input type="checkbox" name="allow_user_theme" class="form-checkbox h-5 w-5 text-yellow-500 rounded border-gray-600 bg-gray-700 focus:ring-yellow-500" 
                                <?= $theme_settings['allow_user_theme'] ? 'checked' : '' ?>>
                            <span class="ml-2 text-gray-300">Allow users to toggle between light and dark mode</span>
                        </label>
                    </div>
                    
                    <div class="mt-6 bg-gray-700 p-4 rounded-lg">
                        <h3 class="text-lg font-medium mb-2">Theme Preview</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="preview-card rounded-lg overflow-hidden" style="background-color: var(--preview-secondary);">
                                <div class="p-4 border-b border-gray-600">
                                    <h4 class="font-medium" style="color: var(--preview-text);">Card Title</h4>
                                </div>
                                <div class="p-4">
                                    <p class="text-sm mb-4" style="color: var(--preview-text);">This is how your cards will look with the selected colors.</p>
                                    <button class="px-4 py-2 rounded-lg text-white" style="background-color: var(--preview-accent);">Button</button>
                                </div>
                            </div>
                            
                            <div class="preview-navbar p-4 rounded-lg" style="background-color: var(--preview-secondary);">
                                <div class="flex justify-between items-center">
                                    <div class="font-bold" style="color: var(--preview-text);">Logo</div>
                                    <div class="flex space-x-4">
                                        <span style="color: var(--preview-text);">Link</span>
                                        <span style="color: var(--preview-accent);">Active</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="preview-button-group flex flex-col space-y-2">
                                <button class="px-4 py-2 rounded-lg text-white" style="background-color: var(--preview-accent);">Primary Button</button>
                                <button class="px-4 py-2 rounded-lg border" style="color: var(--preview-text); border-color: var(--preview-accent);">Outline Button</button>
                                <div class="px-4 py-2 rounded-lg text-white bg-green-600">Success Button</div>
                                <div class="px-4 py-2 rounded-lg text-white bg-red-600">Danger Button</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="submit" name="update_theme" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-save mr-2"></i> Save Theme Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Homepage Settings Tab -->
        <div id="homepage-settings" class="tab-pane hidden">
            <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4 flex items-center">
                    <i class="fas fa-home text-yellow-500 mr-2"></i>
                    Homepage Settings
                </h2>
                
                <form action="" method="POST" enctype="multipart/form-data">
                    <div class="mb-6">
                        <h3 class="text-lg font-medium mb-2 text-gray-300">Hero Section</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="hero_title" class="block text-sm font-medium text-gray-400 mb-1">Hero Title</label>
                                <input type="text" id="hero_title" name="hero_title" value="<?= htmlspecialchars($homepage_settings['hero_title']) ?>" 
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                            </div>
                            
                            <div>
                                <label for="hero_subtitle" class="block text-sm font-medium text-gray-400 mb-1">Hero Subtitle</label>
                                <input type="text" id="hero_subtitle" name="hero_subtitle" value="<?= htmlspecialchars($homepage_settings['hero_subtitle']) ?>" 
                                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <label for="hero_image" class="block text-sm font-medium text-gray-400 mb-1">Hero Background Image</label>
                            <div class="flex items-center">
                                <input type="file" id="hero_image" name="hero_image" accept="image/*" 
                                    class="bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                            </div>
                            <?php if (!empty($homepage_settings['hero_image'])): ?>
                                <div class="mt-2 flex items-center">
                                    <span class="text-sm text-gray-400 mr-2">Current image:</span>
                                    <img src="../uploads/site_images/<?= htmlspecialchars($homepage_settings['hero_image']) ?>" alt="Hero Image" class="h-16 rounded">
                                </div>
                            <?php endif; ?>
                            <p class="text-xs text-gray-500 mt-1">Recommended size: 1920x1080px. Max file size: 2MB.</p>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <h3 class="text-lg font-medium mb-2 text-gray-300">Featured Gyms Section</h3>
                        <div class="flex items-center mb-4">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="show_featured_gyms" class="form-checkbox h-5 w-5 text-yellow-500 rounded border-gray-600 bg-gray-700 focus:ring-yellow-500" 
                                    <?= $homepage_settings['show_featured_gyms'] ? 'checked' : '' ?>>
                                <span class="ml-2 text-gray-300">Show Featured Gyms section on homepage</span>
                            </label>
                        </div>
                        
                        <div>
                            <label for="featured_gyms_count" class="block text-sm font-medium text-gray-400 mb-1">Number of Featured Gyms to Display</label>
                            <input type="number" id="featured_gyms_count" name="featured_gyms_count" value="<?= htmlspecialchars($homepage_settings['featured_gyms_count']) ?>" min="3" max="12" 
                                class="w-full md:w-1/4 bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                            <p class="text-xs text-gray-500 mt-1">Recommended: 3-12 gyms</p>
                        </div>
                    </div>
                    
                    <div class="mb-6">
                        <h3 class="text-lg font-medium mb-2 text-gray-300">Testimonials Section</h3>
                        <div class="flex items-center">
                            <label class="inline-flex items-center">
                                <input type="checkbox" name="show_testimonials" class="form-checkbox h-5 w-5 text-yellow-500 rounded border-gray-600 bg-gray-700 focus:ring-yellow-500" 
                                    <?= $homepage_settings['show_testimonials'] ? 'checked' : '' ?>>
                                <span class="ml-2 text-gray-300">Show Testimonials section on homepage</span>
                            </label>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Testimonials are pulled from approved gym reviews with 4+ stars</p>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="submit" name="update_homepage" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2 px-4 rounded-lg">
                        <i class="fas fa-save mr-2"></i> Save Homepage Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- SEO Settings Tab -->
        <div id="seo-settings" class="tab-pane hidden">
            <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4 flex items-center">
                    <i class="fas fa-search text-yellow-500 mr-2"></i>
                    SEO Settings
                </h2>
                
                <form action="" method="POST">
                    <div class="grid grid-cols-1 gap-6">
                        <div>
                            <label for="site_title" class="block text-sm font-medium text-gray-400 mb-1">Site Title</label>
                            <input type="text" id="site_title" name="site_title" value="<?= htmlspecialchars($seo_settings['site_title']) ?>" 
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                            <p class="text-xs text-gray-500 mt-1">The title that appears in search engine results and browser tabs</p>
                        </div>
                        
                        <div>
                            <label for="meta_description" class="block text-sm font-medium text-gray-400 mb-1">Meta Description</label>
                            <textarea id="meta_description" name="meta_description" rows="3" 
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500"><?= htmlspecialchars($seo_settings['meta_description']) ?></textarea>
                            <p class="text-xs text-gray-500 mt-1">Brief description of your site (150-160 characters recommended)</p>
                        </div>
                        
                        <div>
                            <label for="meta_keywords" class="block text-sm font-medium text-gray-400 mb-1">Meta Keywords</label>
                            <input type="text" id="meta_keywords" name="meta_keywords" value="<?= htmlspecialchars($seo_settings['meta_keywords']) ?>" 
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                            <p class="text-xs text-gray-500 mt-1">Comma-separated keywords related to your site</p>
                        </div>
                        
                        <div>
                            <label for="google_analytics" class="block text-sm font-medium text-gray-400 mb-1">Google Analytics Tracking ID</label>
                            <input type="text" id="google_analytics" name="google_analytics" value="<?= htmlspecialchars($seo_settings['google_analytics']) ?>" 
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500"
                                placeholder="UA-XXXXXXXXX-X or G-XXXXXXXXXX">
                            <p class="text-xs text-gray-500 mt-1">Your Google Analytics tracking ID (leave empty to disable)</p>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="submit" name="update_seo" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-save mr-2"></i> Save SEO Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Footer Settings Tab -->
        <div id="footer-settings" class="tab-pane hidden">
            <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4 flex items-center">
                    <i class="fas fa-shoe-prints text-yellow-500 mr-2"></i>
                    Footer Settings
                </h2>
                
                <form action="" method="POST">
                    <div class="grid grid-cols-1 gap-6">
                        <div>
                            <label for="footer_text" class="block text-sm font-medium text-gray-400 mb-1">Footer Text</label>
                            <textarea id="footer_text" name="footer_text" rows="2" 
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500"><?= htmlspecialchars($footer_settings['footer_text']) ?></textarea>
                            <p class="text-xs text-gray-500 mt-1">Brief description that appears in the footer</p>
                        </div>
                        
                        <div>
                            <label for="copyright_text" class="block text-sm font-medium text-gray-400 mb-1">Copyright Text</label>
                            <input type="text" id="copyright_text" name="copyright_text" value="<?= htmlspecialchars($footer_settings['copyright_text']) ?>" 
                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                            <p class="text-xs text-gray-500 mt-1">Copyright notice that appears at the bottom of the page</p>
                        </div>
                        
                        <div>
                            <h3 class="text-lg font-medium mb-2 text-gray-300">Social Media Links</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="facebook_url" class="block text-sm font-medium text-gray-400 mb-1">
                                        <i class="fab fa-facebook text-blue-500 mr-1"></i> Facebook URL
                                    </label>
                                    <input type="url" id="facebook_url" name="facebook_url" value="<?= htmlspecialchars($footer_settings['facebook_url']) ?>" 
                                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="twitter_url" class="block text-sm font-medium text-gray-400 mb-1">
                                        <i class="fab fa-twitter text-blue-400 mr-1"></i> Twitter URL
                                    </label>
                                    <input type="url" id="twitter_url" name="twitter_url" value="<?= htmlspecialchars($footer_settings['twitter_url']) ?>" 
                                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="instagram_url" class="block text-sm font-medium text-gray-400 mb-1">
                                        <i class="fab fa-instagram text-pink-500 mr-1"></i> Instagram URL
                                    </label>
                                    <input type="url" id="instagram_url" name="instagram_url" value="<?= htmlspecialchars($footer_settings['instagram_url']) ?>" 
                                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                                </div>
                                
                                <div>
                                    <label for="linkedin_url" class="block text-sm font-medium text-gray-400 mb-1">
                                        <i class="fab fa-linkedin text-blue-600 mr-1"></i> LinkedIn URL
                                    </label>
                                    <input type="url" id="linkedin_url" name="linkedin_url" value="<?= htmlspecialchars($footer_settings['linkedin_url']) ?>" 
                                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500">
                                </div>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Leave any field empty to hide that social media icon</p>
                        </div>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="submit" name="update_footer" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-save mr-2"></i> Save Footer Settings
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Custom CSS Tab -->
        <div id="custom-css" class="tab-pane hidden">
            <div class="bg-gray-800 rounded-xl shadow-lg p-6 mb-6">
                <h2 class="text-xl font-semibold mb-4 flex items-center">
                    <i class="fas fa-code text-yellow-500 mr-2"></i>
                    Custom CSS
                </h2>
                
                <form action="" method="POST">
                    <div>
                        <label for="custom_css" class="block text-sm font-medium text-gray-400 mb-1">Custom CSS Code</label>
                        <div class="relative">
                            <textarea id="custom_css" name="custom_css" rows="15" 
                                class="w-full bg-gray-900 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-yellow-500 font-mono"><?= htmlspecialchars($site_settings['custom']['custom_css'] ?? '') ?></textarea>
                            <div class="absolute top-0 right-0 p-2">
                                <button type="button" id="expand-editor" class="text-gray-400 hover:text-white">
                                    <i class="fas fa-expand-alt"></i>
                                </button>
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Add custom CSS to override the default styles. This will be added to all pages.</p>
                    </div>
                    
                    <div class="mt-6 flex justify-end">
                        <button type="submit" name="update_custom_css" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2 px-4 rounded-lg">
                            <i class="fas fa-save mr-2"></i> Save Custom CSS
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Full-screen CSS Editor Modal -->
<div id="css-editor-modal" class="fixed inset-0 bg-black bg-opacity-75 z-50 flex items-center justify-center hidden">
    <div class="bg-gray-900 rounded-lg w-11/12 h-5/6 flex flex-col">
        <div class="flex justify-between items-center p-4 border-b border-gray-700">
            <h3 class="text-lg font-medium">Edit Custom CSS</h3>
            <button id="close-editor" class="text-gray-400 hover:text-white">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="flex-1 p-4">
            <textarea id="fullscreen-css-editor" class="w-full h-full bg-gray-900 border border-gray-700 rounded-lg p-4 text-white font-mono"></textarea>
        </div>
        <div class="p-4 border-t border-gray-700 flex justify-end">
            <button id="apply-css" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2 px-4 rounded-lg">
                <i class="fas fa-check mr-2"></i> Apply Changes
            </button>
        </div>
    </div>
</div>

<script>
    // Tab switching functionality
    document.addEventListener('DOMContentLoaded', function() {
        const tabLinks = document.querySelectorAll('.border-b-2');
        const tabPanes = document.querySelectorAll('.tab-pane');
        
        tabLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Get the target tab ID from the href attribute
                const targetId = this.getAttribute('href');
                
                // Remove active class from all tabs and hide all panes
                tabLinks.forEach(link => {
                    link.classList.remove('border-yellow-500', 'text-yellow-500');
                    link.classList.add('border-transparent', 'hover:text-gray-300', 'hover:border-gray-300');
                });
                
                tabPanes.forEach(pane => {
                    pane.classList.add('hidden');
                });
                
                // Add active class to clicked tab and show the corresponding pane
                this.classList.add('border-yellow-500', 'text-yellow-500');
                this.classList.remove('border-transparent', 'hover:text-gray-300', 'hover:border-gray-300');
                
                document.querySelector(targetId).classList.remove('hidden');
            });
        });
        
        // Color picker synchronization
        const colorInputs = document.querySelectorAll('[data-color-input]');
        colorInputs.forEach(input => {
            input.addEventListener('input', function() {
                const targetId = this.getAttribute('data-color-input');
                document.getElementById(targetId).value = this.value;
                updatePreview();
            });
        });
        
        const colorPickers = document.querySelectorAll('input[type="color"]');
        colorPickers.forEach(picker => {
            picker.addEventListener('input', function() {
                const inputs = document.querySelectorAll(`[data-color-input="${this.id}"]`);
                inputs.forEach(input => {
                    input.value = this.value;
                });
                updatePreview();
            });
        });
        
        // Theme preview functionality
        function updatePreview() {
            const primaryColor = document.getElementById('primary_color').value;
            const secondaryColor = document.getElementById('secondary_color').value;
            const accentColor = document.getElementById('accent_color').value;
            
            document.documentElement.style.setProperty('--preview-primary', primaryColor);
            document.documentElement.style.setProperty('--preview-secondary', secondaryColor);
            document.documentElement.style.setProperty('--preview-accent', accentColor);
            document.documentElement.style.setProperty('--preview-text', '#ffffff');
        }
        
        // Initialize preview
        updatePreview();
        
        // CSS Editor functionality
        const expandEditorBtn = document.getElementById('expand-editor');
        const cssEditorModal = document.getElementById('css-editor-modal');
        const closeEditorBtn = document.getElementById('close-editor');
        const customCssTextarea = document.getElementById('custom_css');
        const fullscreenEditor = document.getElementById('fullscreen-css-editor');
        const applyCssBtn = document.getElementById('apply-css');
        
        expandEditorBtn.addEventListener('click', function() {
            fullscreenEditor.value = customCssTextarea.value;
            cssEditorModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        });
        
        closeEditorBtn.addEventListener('click', function() {
            cssEditorModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        });
        
        applyCssBtn.addEventListener('click', function() {
            customCssTextarea.value = fullscreenEditor.value;
            cssEditorModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        });
        
        // Close modal when clicking outside
        cssEditorModal.addEventListener('click', function(e) {
            if (e.target === cssEditorModal) {
                cssEditorModal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
        });
        
        // Escape key to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !cssEditorModal.classList.contains('hidden')) {
                cssEditorModal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
        });
    });
</script>

<?php include '../includes/footer.php'; ?>



