<?php
ob_start();
// Set default timezone to Indian Standard Time
date_default_timezone_set('Asia/Kolkata');
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/settings.php';

// Initialize auth if not already done
if (!isset($auth)) {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    $auth = new Auth($conn);
}

// Load site settings
$site_settings = getSiteSettings($conn);

// Check if user is authenticated
$public_pages = [
    'login.php',
    'register.php',
    'forgot-password.php',
    'reset-password.php',
    'index.php',
    'terms.php',
    'privacy.php',
    'about-us.php',
    'contact.php',
    'all-gyms.php',
    'gym-profile.php',
    'faq.php',
    'pricing.php',
    'blog.php',
    'blog-post.php',
    'careers.php',
    'testimonials.php'
];

if (!$auth->isAuthenticated() && !in_array(basename($_SERVER['PHP_SELF']), $public_pages)) {
    $_SESSION['error'] = "Please log in to access this page.";
    $redirect_url = 'login.php';
    
    if (strpos($_SERVER['PHP_SELF'], 'admin/') !== false) {
        $redirect_url = './login.php';
    } elseif (strpos($_SERVER['PHP_SELF'], 'gym/') !== false) {
        $redirect_url = './login.php';
    }
    
    header('Location: ' . $redirect_url);
    exit;
}

// Get user data for display in navbar
$user = null;
if (isset($_SESSION['user_id'])) {
    $user = [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'] ?? 'User',
        'role' => $_SESSION['role'] ?? 'member',
        'profile_pic' => $_SESSION['user_profile_pic'] ?? null
    ];
}

// Current page and path information
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = dirname($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? '';
$isLoggedIn = isset($_SESSION['user_id']) || isset($_SESSION['owner_id']) || isset($_SESSION['admin_id']);
$user_id = $_SESSION['user_id'] ?? ($_SESSION['owner_id'] ?? ($_SESSION['admin_id'] ?? null));
$username = isset($_SESSION['username']) ? $_SESSION['username'] : (isset($_SESSION['owner_name']) ? $_SESSION['owner_name'] : (isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : ''));
$account_type = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : (isset($_SESSION['owner_account_type']) ? $_SESSION['owner_account_type'] : 'basic');

// Get unread notifications count based on role
$unreadNotificationsCount = 0;
if ($isLoggedIn) {
    try {
        // Check if the notifications table exists and get its structure
        $checkTableStmt = $conn->prepare("SHOW TABLES LIKE 'notifications'");
        $checkTableStmt->execute();
        $tableExists = $checkTableStmt->rowCount() > 0;
        
        if ($tableExists) {
            // Get column names to determine the correct structure
            $columnsStmt = $conn->prepare("SHOW COLUMNS FROM notifications");
            $columnsStmt->execute();
            $columns = $columnsStmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Check for different possible column structures
            $hasRecipientId = in_array('recipient_id', $columns);
            $hasRecipientType = in_array('recipient_type', $columns);
            $hasUserId = in_array('user_id', $columns);
            $hasUserType = in_array('user_type', $columns);
            $hasIsRead = in_array('is_read', $columns);
            $hasStatus = in_array('status', $columns);
            $hasGymId = in_array('gym_id', $columns);
            
            $userType = isset($_SESSION['user_id']) ? 'user' : (isset($_SESSION['owner_id']) ? 'owner' : 'admin');
            
            // Determine which query to use based on available columns
            if ($hasRecipientId && $hasRecipientType && $hasIsRead) {
                $notifQuery = "SELECT COUNT(*) FROM notifications WHERE recipient_id = ? AND recipient_type = ? AND is_read = 0";
                $notifStmt = $conn->prepare($notifQuery);
                $notifStmt->execute([$user_id, $userType]);
            } elseif ($hasUserId && $hasUserType && $hasIsRead) {
                $notifQuery = "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND user_type = ? AND is_read = 0";
                $notifStmt = $conn->prepare($notifQuery);
                $notifStmt->execute([$user_id, $userType]);
            } elseif ($hasUserId && $hasStatus) {
                $notifQuery = "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = 'unread'";
                $notifStmt = $conn->prepare($notifQuery);
                $notifStmt->execute([$user_id]);
            } elseif (isset($_SESSION['owner_id']) && $hasGymId && $hasIsRead) {
                $notifQuery = "SELECT COUNT(*) FROM notifications WHERE gym_id IN (SELECT gym_id FROM gyms WHERE owner_id = ?) AND is_read = 0";
                $notifStmt = $conn->prepare($notifQuery);
                $notifStmt->execute([$_SESSION['owner_id']]);
            } elseif ($hasUserId && $hasIsRead) {
                $notifQuery = "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0";
                $notifStmt = $conn->prepare($notifQuery);
                $notifStmt->execute([$user_id]);
            } else {
                // Fallback if none of the expected columns exist
                $unreadNotificationsCount = 0;
            }
            
            if (isset($notifStmt)) {
                $unreadNotificationsCount = $notifStmt->fetchColumn();
            }
        }
    } catch (PDOException $e) {
        // Log error silently
        error_log("Notification count error: " . $e->getMessage());
        $unreadNotificationsCount = 0;
    }
}

// Get base URL for consistent paths
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . '://' . $host;

// For local development, add the project folder if needed
if ($host === 'localhost' || strpos($host, '127.0.0.1') !== false) {
    $base_url .= '/featuresgym';
}

// Determine current section and set paths accordingly
$in_gym = strpos($_SERVER['REQUEST_URI'], '/gym') !== false;
$in_admin = strpos($_SERVER['REQUEST_URI'], '/admin') !== false;

if ($in_gym) {
    $base_path = '../';
    $gym_path = './';
    $admin_path = '../admin/';
} elseif ($in_admin) {
    $base_path = '../';
    $gym_path = '../gym/';
    $admin_path = './';
} else {
    $base_path = './';
    $gym_path = 'gym/';
    $admin_path = 'admin/';
}

/**
 * Check if the current page matches the given page name
 * 
 * @param string $page_name The page name to check
 * @param string $active_class The CSS class to return if active
 * @param string $inactive_class The CSS class to return if inactive
 * @return string The appropriate CSS class
 */
function isActivePage($page_name, $active_class = 'text-yellow-400', $inactive_class = '')
{
    global $current_page;
    return ($current_page === $page_name) ? $active_class : $inactive_class;
}

/**
 * Check if the current section matches the given section
 * 
 * @param string $section The section to check (admin, gym, etc.)
 * @param string $active_class The CSS class to return if active
 * @param string $inactive_class The CSS class to return if inactive
 * @return string The appropriate CSS class
 */
function isActiveSection($section, $active_class = 'text-yellow-400', $inactive_class = '')
{
    global $current_dir;
    return (strpos($current_dir, '/' . $section . '/') !== false) ? $active_class : $inactive_class;
}

/**
 * Generate the correct URL for a page based on current location
 * 
 * @param string $page The page name
 * @param string $section Optional section (admin, gym)
 * @return string The full URL
 */
function getPageUrl($page, $section = '')
{
    global $base_path, $gym_path, $admin_path;

    if ($section === 'gym') {
        return $gym_path . $page;
    } elseif ($section === 'admin') {
        return $admin_path . $page;
    } else {
        return $base_path . $page;
    }
}

// Get site logo and branding
$site_name = $site_settings['site_name'] ?? 'FlexFit';
$site_logo = $site_settings['logo_path'] ?? 'assets/images/logo.png';
$site_favicon = $site_settings['favicon_path'] ?? 'assets/images/favicon.ico';

// Get theme settings
$default_theme = $site_settings['default_theme'] ?? 'dark';
$allow_user_theme = $site_settings['user_registration'] ?? '1';

// Get user's preferred theme if allowed
$user_theme = '';
if ($allow_user_theme === '1' && isset($_COOKIE['preferred_theme'])) {
    $user_theme = $_COOKIE['preferred_theme'];
}

// Determine the active theme
$active_theme = $user_theme ?: $default_theme;

// Check if site is in maintenance mode
$maintenance_mode = $site_settings['maintenance_mode'] ?? '0';
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="en" data-default-theme="<?= htmlspecialchars($default_theme) ?>" 
      data-allow-user-theme="<?= htmlspecialchars($allow_user_theme) ?>"
      class="<?= $active_theme === 'light' ? 'light-mode' : '' ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_settings['site_name'] ?? 'FlexFit - Find Your Perfect Gym') ?></title>

    <!-- Meta tags -->
    <meta name="description" content="<?= htmlspecialchars($site_settings['site_description'] ?? '') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($site_settings['meta_keywords'] ?? '') ?>">
    <meta name="author" content="<?= htmlspecialchars($site_settings['meta_author'] ?? 'FlexFit') ?>">
    
    <!-- Favicon -->
    <link rel="icon" href="<?= $base_url ?>/<?= htmlspecialchars($site_favicon) ?>">
    <link rel="apple-touch-icon" href="<?= $base_url ?>/<?= htmlspecialchars($site_settings['apple_touch_icon'] ?? 'assets/images/apple-touch-icon.png') ?>">
    
    <!-- Open Graph / Social Media Meta Tags -->
    <meta property="og:title" content="<?= htmlspecialchars($site_settings['og_title'] ?? $site_settings['site_name'] ?? 'FlexFit') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($site_settings['og_description'] ?? $site_settings['site_description'] ?? '') ?>">
    <meta property="og:image" content="<?= $base_url ?>/<?= htmlspecialchars($site_settings['og_image'] ?? 'assets/images/og-image.jpg') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($protocol . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]") ?>">
    <meta property="og:type" content="website">

    <!-- Theme CSS -->
    <link rel="stylesheet" href="<?= $base_url ?>/assets/css/theme-variables.css">

    <!-- Tailwind CSS -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <!-- Custom CSS -->
    <style>
        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            font-family: <?= htmlspecialchars($site_settings['font_family'] ?? 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif') ?>;
        }

        /* Add custom CSS from database if available */
        <?= $site_settings['custom_css'] ?? '' ?>
        
        /* Loader styles */
        .loader-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: var(--bg-primary);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            transition: opacity 0.5s ease-out;
        }
        
        .gym-loader {
            display: inline-block;
            position: relative;
            width: 80px;
            height: 80px;
        }
        
        .spinner {
            width: 64px;
            height: 64px;
            border: 8px solid rgba(255, 193, 7, 0.3);
            border-radius: 50%;
            border-top: 8px solid #ffc107;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Theme transition */
        .theme-transition {
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        /* Notification badge */
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            transform: translate(25%, -25%);
            background-color: #ef4444;
            color: white;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.15rem 0.4rem;
            min-width: 1.25rem;
            text-align: center;
        }
        
        /* Mobile menu animation */
        .mobile-menu-enter {
            opacity: 0;
            transform: translateY(-10px);
        }
        
        .mobile-menu-enter-active {
            opacity: 1;
            transform: translateY(0);
            transition: opacity 200ms, transform 200ms;
        }
        
        .mobile-menu-exit {
            opacity: 1;
            transform: translateY(0);
        }
        
        .mobile-menu-exit-active {
            opacity: 0;
            transform: translateY(-10px);
            transition: opacity 150ms, transform 150ms;
        }
    </style>

    <!-- Google Analytics if configured -->
    <?php if (!empty($site_settings['google_analytics_id'])): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($site_settings['google_analytics_id']) ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '<?= htmlspecialchars($site_settings['google_analytics_id']) ?>');
    </script>
    <?php endif; ?>
    
    <!-- Facebook Pixel if configured -->
    <?php if (!empty($site_settings['facebook_pixel_id'])): ?>
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?= htmlspecialchars($site_settings['facebook_pixel_id']) ?>');
        fbq('track', 'PageView');
    </script>
    <noscript>
        <img height="1" width="1" style="display:none" 
            src="https://www.facebook.com/tr?id=<?= htmlspecialchars($site_settings['facebook_pixel_id']) ?>&ev=PageView&noscript=1"/>
    </noscript>
    <?php endif; ?>
    
    <!-- Theme Switcher Script -->
    <script src="<?= $base_url ?>/assets/js/theme-switcher.js" defer></script>
</head>

<body class="theme-transition">
    <?php if ($maintenance_mode === '1' && !$is_admin): ?>
        <div class="min-h-screen flex items-center justify-center bg-gray-900 px-4">
            <div class="max-w-md w-full bg-gray-800 rounded-lg shadow-lg p-8 text-center">
                <i class="fas fa-tools text-yellow-500 text-5xl mb-6"></i>
                <h1 class="text-3xl font-bold text-white mb-4">Site Maintenance</h1>
                <p class="text-gray-300 mb-6">
                    We're currently performing scheduled maintenance. We'll be back online shortly.
                </p>
                <p class="text-gray-400 text-sm">
                    <?= htmlspecialchars($site_settings['maintenance_message'] ?? 'Thank you for your patience.') ?>
                </p>
            </div>
        </div>
        <?php exit; ?>
    <?php endif; ?>

    <!-- Page loader -->
    <?php if ($site_settings['enable_page_loader'] ?? '0' === '1'): ?>
    <div class="loader-container" id="page-loader">
        <div class="gym-loader">
            <div class="spinner"></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Navigation -->
    <nav class="bg-gray-800 fixed w-full z-10 top-0">
            <div class="max-w-7xl mx-auto px-2 sm:px-6 lg:px-8">
                <div class="relative flex items-center justify-between h-16">
                    <div class="absolute inset-y-0 left-0 flex items-center sm:hidden">
                        <!-- Mobile menu button -->
                        <button type="button" id="mobile-menu-button"
                            class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-white hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-white">
                            <span class="sr-only">Open main menu</span>
                            <svg class="block h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                            <svg class="hidden h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                        <div class="flex-shrink-0 flex items-center">
                            <a href="<?php echo $base_url; ?>/" class="flex items-center">
                                <span class="text-yellow-500 text-2xl font-bold mr-1">Flex</span>
                                <span class="text-white text-2xl font-bold">Fit</span>
                            </a>
                        </div>
                    </div>
                    <div class="flex-1 flex items-center justify-center sm:items-stretch sm:justify-start">
                        <div class="flex-shrink-0 flex items-center hidden sm:block">
                            <a href="<?php echo $base_url; ?>/" class="flex items-center">
                                <span class="text-yellow-500 text-2xl font-bold mr-1">Flex</span>
                                <span class="text-white text-2xl font-bold">Fit</span>
                            </a>
                        </div>
                        <div class="hidden sm:block sm:ml-6">
                            <div class="flex space-x-4">
                                <?php if (isset($_SESSION['owner_id'])): ?>
                                    <!-- Gym Partner Navigation -->
                                    <div class="flex items-baseline space-x-1 md:space-x-2 lg:space-x-4">
                                        <!-- Most important direct links -->
                                        <a href="<?php echo $base_url; ?>/gym/dashboard.php"
                                            class="flex items-center text-white hover:text-yellow-400 px-2 py-2 rounded-md text-sm md:text-base lg:text-lg font-medium <?php echo isActivePage('dashboard.php') || isActiveSection('gym'); ?>">
                                            <i
                                                class="fas fa-tachometer-alt mr-1 hidden sm:inline-block"></i><span>Dashboard</span>
                                        </a>
                                        <a href="<?php echo $base_url; ?>/gym/edit_gym_details.php"
                                            class="flex items-center text-white hover:text-yellow-400 px-2 py-2 rounded-md text-sm md:text-base lg:text-lg font-medium <?php echo isActivePage('edit_gym_details.php'); ?>">
                                            <i class="fas fa-dumbbell mr-1 hidden sm:inline-block"></i><span>Gym</span>
                                        </a>
                                        <a href="<?php echo $base_url; ?>/gym/member_list.php"
                                            class="flex items-center text-white hover:text-yellow-400 px-2 py-2 rounded-md text-sm md:text-base lg:text-lg font-medium <?php echo isActivePage('member_list.php'); ?>">
                                            <i class="fas fa-users mr-1 hidden sm:inline-block"></i><span>Members</span>
                                        </a>
                                        <a href="<?php echo $base_url; ?>/gym/booking.php"
                                            class="flex items-center text-white hover:text-yellow-400 px-2 py-2 rounded-md text-sm md:text-base lg:text-lg font-medium <?php echo isActivePage('booking.php'); ?>">
                                            <i
                                                class="fas fa-calendar-check mr-1 hidden sm:inline-block"></i><span>Schedules</span>
                                        </a>
                                        <!-- More dropdown for remaining options -->
                                        <div class="relative">
                                            <?php
                                            // Check if current page is in the "More" dropdown
                                            $morePages = [
                                                'earning-history.php',
                                                'withdraw.php',
                                                'tournaments.php',
                                                'class_bookings.php',
                                                'visit_attendance.php',
                                                'view_reviews.php',
                                                'notifications.php',
                                                'payment_methods.php',
                                                'gym_policies.php',
                                                'reports.php',
                                                'settings.php'
                                            ];
                                            $isMoreActive = false;
                                            foreach ($morePages as $page) {
                                                if (isActivePage($page)) {
                                                    $isMoreActive = true;
                                                    break;
                                                }
                                            }
                                            ?>
                                            <button id="moreDropdownButton"
                                                class="text-white hover:text-yellow-400 px-2 py-2 rounded-md text-sm md:text-base lg:text-lg font-medium flex items-center <?php echo $isMoreActive ? 'text-yellow-400' : ''; ?>">
                                                <i
                                                    class="fas fa-ellipsis-h mr-1 hidden sm:inline-block"></i><span>More</span><i
                                                    class="fas fa-chevron-down ml-1 text-xs"></i>
                                            </button>
                                            <div id="moreDropdownMenu"
                                                class="absolute right-0 mt-2 w-56 rounded-md shadow-lg bg-gray-800 ring-1 ring-black ring-opacity-5 focus:outline-none hidden z-50">
                                                <div class="py-1 grid grid-cols-1 divide-y divide-gray-700">
                                                    <a href="<?php echo $base_url; ?>/gym/earning-history.php"
                                                        class="text-white hover:text-yellow-400 px-3 py-2 text-sm font-medium <?php echo isActivePage('earning-history.php') ? 'text-yellow-400 bg-gray-700' : ''; ?>">
                                                        <i class="fas fa-chart-line mr-2"></i> Earnings
                                                    </a>
                                                    <a href="<?php echo $base_url; ?>/gym/withdraw.php"
                                                        class="text-white hover:text-yellow-400 px-3 py-2 text-sm font-medium <?php echo isActivePage('withdraw.php') ? 'text-yellow-400 bg-gray-700' : ''; ?>">
                                                        <i class="fas fa-money-bill-wave mr-2"></i> Withdrawals
                                                    </a>
                                                    <a href="<?php echo $base_url; ?>/gym/tournaments.php"
                                                        class="text-white hover:text-yellow-400 px-3 py-2 text-sm font-medium <?php echo isActivePage('tournaments.php') ? 'text-yellow-400 bg-gray-700' : ''; ?>">
                                                        <i class="fas fa-trophy mr-2"></i> Tournaments
                                                    </a>
                                                    <a href="<?php echo $base_url; ?>/gym/class_bookings.php"
                                                        class="text-white hover:text-yellow-400 px-3 py-2 text-sm font-medium <?php echo isActivePage('class_bookings.php') ? 'text-yellow-400 bg-gray-700' : ''; ?>">
                                                        <i class="fas fa-chalkboard-teacher mr-2"></i> Class Bookings
                                                    </a>
                                                    <a href="<?php echo $base_url; ?>/gym/visit_attendance.php"
                                                        class="text-white hover:text-yellow-400 px-3 py-2 text-sm font-medium <?php echo isActivePage('visit_attendance.php') ? 'text-yellow-400 bg-gray-700' : ''; ?>">
                                                        <i class="fas fa-clipboard-check mr-2"></i> Visit Attendance
                                                    </a>
                                                    <a href="<?php echo $base_url; ?>/gym/view_reviews.php"
                                                        class="text-white hover:text-yellow-400 px-3 py-2 text-sm font-medium <?php echo isActivePage('view_reviews.php') ? 'text-yellow-400 bg-gray-700' : ''; ?>">
                                                        <i class="fas fa-star mr-2"></i> Reviews
                                                    </a>
                                                    <a href="<?php echo $base_url; ?>/gym/notifications.php"
                                                        class="text-white hover:text-yellow-400 px-3 py-2 text-sm font-medium <?php echo isActivePage('notifications.php') ? 'text-yellow-400 bg-gray-700' : ''; ?>">
                                                        <i class="fas fa-bell mr-2"></i> Notifications
                                                    </a>
                                                    <a href="<?php echo $base_url; ?>/gym/payment_methods.php"
                                                        class="text-white hover:text-yellow-400 px-3 py-2 text-sm font-medium <?php echo isActivePage('payment_methods.php') ? 'text-yellow-400 bg-gray-700' : ''; ?>">
                                                        <i class="fas fa-credit-card mr-2"></i> Payment Methods
                                                    </a>
                                                    <a href="<?php echo $base_url; ?>/gym/gym_policies.php"
                                                        class="text-white hover:text-yellow-400 px-3 py-2 text-sm font-medium <?php echo isActivePage('gym_policies.php') ? 'text-yellow-400 bg-gray-700' : ''; ?>">
                                                        <i class="fas fa-gavel mr-2"></i> Policies
                                                    </a>
                                                    <a href="<?php echo $base_url; ?>/gym/reports.php"
                                                        class="text-white hover:text-yellow-400 px-3 py-2 text-sm font-medium <?php echo isActivePage('reports.php') ? 'text-yellow-400 bg-gray-700' : ''; ?>">
                                                        <i class="fas fa-chart-bar mr-2"></i> Reports
                                                    </a>
                                                    <a href="<?php echo $base_url; ?>/gym/settings.php"
                                                        class="text-white hover:text-yellow-400 px-3 py-2 text-sm font-medium <?php echo isActivePage('settings.php') ? 'text-yellow-400 bg-gray-700' : ''; ?>">
                                                        <i class="fas fa-cogs mr-2"></i> Settings
                                                    </a>
                                                    <?php if ($account_type !== 'premium'): ?>
                                                        <!--<a href="<?php echo $base_url; ?>/gym/upgrade.php"-->
                                                        <!--    class="block px-4 py-2 text-sm text-yellow-400 hover:bg-yellow-500 hover:text-black">-->
                                                        <!--    <i class="fas fa-crown mr-2"></i> Upgrade to Premium-->
                                                        <!--</a>-->
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <script>
                                        // Toggle dropdown on click
                                        document.addEventListener('DOMContentLoaded', function () {
                                            const moreDropdownButton = document.getElementById('moreDropdownButton');
                                            const moreDropdownMenu = document.getElementById('moreDropdownMenu');

                                            // Toggle dropdown when clicking the button
                                            moreDropdownButton.addEventListener('click', function (e) {
                                                e.preventDefault();
                                                moreDropdownMenu.classList.toggle('hidden');
                                            });

                                            // Close dropdown when clicking outside
                                            document.addEventListener('click', function (e) {
                                                if (!moreDropdownButton.contains(e.target) && !moreDropdownMenu.contains(e.target)) {
                                                    moreDropdownMenu.classList.add('hidden');
                                                }
                                            });
                                        });
                                    </script>


                                <?php elseif (isset($_SESSION['user_id']) && $_SESSION['role'] === 'member'): ?>
                                    <!-- Member Navigation -->
                                    <a href="<?php echo $base_url; ?>/dashboard.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('dashboard.php', 'bg-gray-900 text-white'); ?>">
                                        Dashboard
                                    </a>
                                    <a href="<?php echo $base_url; ?>/view_membership.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('my_memberships.php', 'bg-gray-900 text-white'); ?>">
                                        My Memberships
                                    </a>
                                    <a href="<?php echo $base_url; ?>/schedule-history.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('my_schedules.php', 'bg-gray-900 text-white'); ?>">
                                        My Schedules
                                    </a>
                                    <a href="<?php echo $base_url; ?>/all-gyms.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('all-gyms.php', 'bg-gray-900 text-white'); ?>">
                                        Find Gyms
                                    </a>
                                    <a href="<?php echo $base_url; ?>/tournaments.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('all-gyms.php', 'bg-gray-900 text-white'); ?>">
                                        Tournaments
                                    </a>
                                    <a href="<?php echo $base_url; ?>/payment_history.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('all-gyms.php', 'bg-gray-900 text-white'); ?>">
                                        Payments
                                    </a>
                                <?php else: ?>
                                    <!-- Public Navigation -->
                                    <a href="<?php echo $base_url; ?>/"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('index.php', 'bg-gray-900 text-white'); ?>">
                                        Home
                                    </a>
                                    <a href="<?php echo $base_url; ?>/all-gyms.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('all-gyms.php', 'bg-gray-900 text-white'); ?>">
                                        Find Gyms
                                    </a>
                                    <a href="<?php echo $base_url; ?>/about-us.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('about-us.php', 'bg-gray-900 text-white'); ?>">
                                        About Us
                                    </a>
                                    <a href="<?php echo $base_url; ?>/contact.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('contact.php', 'bg-gray-900 text-white'); ?>">
                                        Contact
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div
                        class="absolute inset-y-0 right-0 flex items-center pr-2 sm:static sm:inset-auto sm:ml-6 sm:pr-0">
                        <!-- Theme Toggle Button -->
                        <button id="theme-toggle"
                            class="p-1 rounded-full text-gray-400 hover:text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 focus:ring-white">
                            <span class="sr-only">Toggle theme</span>
                            <svg id="dark-icon" class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
                            </svg>
                            <svg id="light-icon" class="hidden h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none"
                                viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                        </button>

                        <?php if ($isLoggedIn): ?>
                            <!-- Notification button -->
                            <div class="ml-3 relative">
                                <button id="notification-button"
                                    class="p-1 rounded-full text-gray-400 hover:text-white focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 focus:ring-white">
                                    <span class="sr-only">View notifications</span>
                                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                        stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                    </svg>
                                    <?php if ($unreadNotificationsCount > 0): ?>
                                        <span
                                            class="absolute top-0 right-0 block h-4 w-4 rounded-full bg-red-500 text-white text-xs flex items-center justify-center">
                                            <?php echo $unreadNotificationsCount > 9 ? '9+' : $unreadNotificationsCount; ?>
                                        </span>
                                    <?php endif; ?>
                                </button>
                                <!-- Notification dropdown -->
                                <div id="notification-dropdown"
                                    class="hidden origin-top-right absolute right-0 mt-2 w-80 rounded-md shadow-lg py-1 bg-gray-700 ring-1 ring-black ring-opacity-5 focus:outline-none z-50"
                                    role="menu" aria-orientation="vertical" aria-labelledby="notification-button"
                                    tabindex="-1">
                                    <div class="px-4 py-2 border-b border-gray-600 flex justify-between items-center">
                                        <h3 class="text-sm font-medium text-white">Notifications</h3>
                                        <a href="#" class="text-xs text-yellow-400 hover:text-yellow-300">Mark all as
                                            read</a>
                                    </div>
                                    <div id="notification-list" class="max-h-60 overflow-y-auto">
                                        <!-- Notifications will be loaded here via AJAX -->
                                        <div class="px-4 py-2 text-sm text-gray-400 text-center">
                                            Loading notifications...
                                        </div>
                                    </div>
                                    <div class="px-4 py-2 border-t border-gray-600">
                                        <a href="<?php echo $base_url; ?>/notifications.php"
                                            class="text-xs text-yellow-400 hover:text-yellow-300 flex items-center justify-center">
                                            View all notifications
                                            <svg class="ml-1 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none"
                                                viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M9 5l7 7-7 7" />
                                            </svg>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Profile dropdown -->
                            <div class="ml-3 relative">
                                <div>
                                    <button id="user-menu-button"
                                        class="bg-gray-800 flex text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-gray-800 focus:ring-white"
                                        aria-expanded="false" aria-haspopup="true">
                                        <span class="sr-only">Open user menu</span>
                                        <?php if (isset($_SESSION['user_profile_pic']) && !empty($_SESSION['user_profile_pic'])): ?>
                                            <img class="h-8 w-8 rounded-full object-cover"
                                                src="<?php echo $_SESSION['user_profile_pic']; ?>" alt="Profile picture">
                                        <?php else: ?>
                                            <div class="h-8 w-8 rounded-full bg-yellow-500 flex items-center justify-center">
                                                <span
                                                    class="text-white font-medium"><?php echo substr($username, 0, 1); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </button>
                                </div>
                                <div id="user-menu"
                                    class="hidden origin-top-right absolute right-0 mt-2 w-48 rounded-md shadow-lg py-1 bg-gray-700 ring-1 ring-black ring-opacity-5 focus:outline-none z-50"
                                    role="menu" aria-orientation="vertical" aria-labelledby="user-menu-button"
                                    tabindex="-1">
                                    <div class="px-4 py-2 border-b border-gray-600">
                                        <p class="text-sm font-medium text-white"><?php echo $username; ?></p>
                                        <?php if (isset($_SESSION['role'])): ?>
                                            <p class="text-xs text-gray-400"><?php echo ucfirst($_SESSION['role']); ?></p>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (isset($_SESSION['owner_id'])): ?>
                                        <!-- Gym Partner Profile Menu -->
                                        <!-- <a href="<?php echo $base_url; ?>/gym/profile.php"
                                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-600 hover:text-white"
                                            role="menuitem">Your Profile</a> -->
                                        <a href="<?php echo $base_url; ?>/gym/settings.php"
                                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-600 hover:text-white"
                                            role="menuitem">Settings</a>
                                        <a href="<?php echo $base_url; ?>/gym/payment_methods.php"
                                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-600 hover:text-white"
                                            role="menuitem">Payment Methods</a>
                                    <?php elseif (isset($_SESSION['user_id']) && $_SESSION['role'] === 'member'): ?>
                                        <!-- Member Profile Menu -->
                                        <a href="<?php echo $base_url; ?>/profile.php"
                                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-600 hover:text-white"
                                            role="menuitem">Your Profile</a>
                                        <a href="<?php echo $base_url; ?>/settings.php"
                                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-600 hover:text-white"
                                            role="menuitem">Settings</a>
                                        <a href="<?php echo $base_url; ?>/wallet.php"
                                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-600 hover:text-white"
                                            role="menuitem">My Wallet</a>
                                    <?php endif; ?>

                                    <div class="border-t border-gray-600">
                                        <a href="<?php echo $base_url; ?>/logout.php"
                                            class="block px-4 py-2 text-sm text-red-400 hover:bg-gray-600 hover:text-red-300"
                                            role="menuitem">Sign out</a>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Login/Register buttons for non-authenticated users -->
                            <div class="flex items-center space-x-2">
                                <a href="<?php echo $base_url; ?>/login.php"
                                    class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium">
                                    Login
                                </a>
                                <a href="<?php echo $base_url; ?>/register.php"
                                    class="bg-yellow-500 hover:bg-yellow-600 text-black px-3 py-2 rounded-md text-sm font-medium">
                                    Register
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Mobile menu -->
            <div class="hidden" id="mobile-menu">
                <div class="px-2 pt-2 pb-3 space-y-1">
                    <?php if (isset($_SESSION['owner_id'])): ?>
                        <!-- Gym Partner Mobile Navigation -->
                        <a href="<?php echo $base_url; ?>/gym/dashboard.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('dashboard.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-tachometer-alt mr-2"></i> Dashboard
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/edit_gym_details.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('edit_gym_details.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-dumbbell mr-2"></i> Gym
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/member_list.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('member_list.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-users mr-2"></i> Members
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/booking.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('booking.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-calendar-check mr-2"></i> Schedules
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/earning-history.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('earning-history.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-chart-line mr-2"></i> Earnings
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/withdraw.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('withdraw.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-money-bill-wave mr-2"></i> Withdrawals
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/tournaments.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('tournaments.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-trophy mr-2"></i> Tournaments
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/class_bookings.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('class_bookings.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-chalkboard-teacher mr-2"></i> Class Bookings
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/visit_attendance.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('visit_attendance.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-clipboard-check mr-2"></i> Visit Attendance
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/view_reviews.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('view_reviews.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-star mr-2"></i> Reviews
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/notifications.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('notifications.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-bell mr-2"></i> Notifications
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/payment_methods.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('payment_methods.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-credit-card mr-2"></i> Payment Methods
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/gym_policies.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('gym_policies.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-gavel mr-2"></i> Policies
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/reports.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('reports.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-chart-bar mr-2"></i> Reports
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/settings.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('settings.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-cogs mr-2"></i> Settings
                        </a>
                    <?php elseif (isset($_SESSION['user_id']) && $_SESSION['role'] === 'member'): ?>
                        <!-- Member Mobile Navigation -->
                        <a href="<?php echo $base_url; ?>/dashboard.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('dashboard.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-tachometer-alt mr-2"></i> Dashboard
                        </a>
                        <a href="<?php echo $base_url; ?>/view_membership.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('view_memberships.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-id-card mr-2"></i> My Memberships
                        </a>
                        <a href="<?php echo $base_url; ?>/schedule-history.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('my_schedules.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-calendar-alt mr-2"></i> My Schedules
                        </a>
                        <a href="<?php echo $base_url; ?>/all-gyms.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('all-gyms.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-search mr-2"></i> Find Gyms
                        </a>
                        <a href="<?php echo $base_url; ?>/tournaments.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('tournaments.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-trophy mr-2"></i> Tournaments
                        </a>
                        <a href="<?php echo $base_url; ?>/payment_history.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('payment_history.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-credit-card mr-2"></i> Payments
                        </a>
                    <?php else: ?>
                        <!-- Public Mobile Navigation -->
                        <a href="<?php echo $base_url; ?>/"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('index.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-home mr-2"></i> Home
                        </a>
                        <a href="<?php echo $base_url; ?>/all-gyms.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('all-gyms.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-search mr-2"></i> Find Gyms
                        </a>
                        <a href="<?php echo $base_url; ?>/about-us.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('about-us.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-info-circle mr-2"></i> About Us
                        </a>
                        <a href="<?php echo $base_url; ?>/contact.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('contact.php', 'bg-gray-900 text-white'); ?>">
                            <i class="fas fa-envelope mr-2"></i> Contact
                        </a>
                    <?php endif; ?>
                </div>
            </div>

        </nav>

    <!-- Flash messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="flash-message success-message bg-green-600 text-white" id="success-message">
            <div class="max-w-7xl mx-auto py-3 px-3 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between flex-wrap">
                    <div class="w-0 flex-1 flex items-center">
                        <span class="flex p-2 rounded-lg bg-green-800">
                            <i class="fas fa-check"></i>
                        </span>
                        <p class="ml-3 font-medium truncate">
                            <?= htmlspecialchars($_SESSION['success']) ?>
                        </p>
                    </div>
                    <div class="order-2 flex-shrink-0 sm:order-3 sm:ml-3">
                        <button type="button" class="close-flash -mr-1 flex p-2 rounded-md hover:bg-green-500 focus:outline-none focus:ring-2 focus:ring-white sm:-mr-2">
                            <span class="sr-only">Dismiss</span>
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
            </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="flash-message error-message bg-red-600 text-white" id="error-message">
            <div class="max-w-7xl mx-auto py-3 px-3 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between flex-wrap">
                    <div class="w-0 flex-1 flex items-center">
                        <span class="flex p-2 rounded-lg bg-red-800">
                            <i class="fas fa-exclamation-triangle"></i>
                        </span>
                        <p class="ml-3 font-medium truncate">
                            <?= htmlspecialchars($_SESSION['error']) ?>
                        </p>
                    </div>
                    <div class="order-2 flex-shrink-0 sm:order-3 sm:ml-3">
                        <button type="button" class="close-flash -mr-1 flex p-2 rounded-md hover:bg-red-500 focus:outline-none focus:ring-2 focus:ring-white sm:-mr-2">
                            <span class="sr-only">Dismiss</span>
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['warning'])): ?>
        <div class="flash-message warning-message bg-yellow-500 text-black" id="warning-message">
            <div class="max-w-7xl mx-auto py-3 px-3 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between flex-wrap">
                    <div class="w-0 flex-1 flex items-center">
                        <span class="flex p-2 rounded-lg bg-yellow-600">
                            <i class="fas fa-exclamation-circle"></i>
                        </span>
                        <p class="ml-3 font-medium truncate">
                            <?= htmlspecialchars($_SESSION['warning']) ?>
                        </p>
                    </div>
                    <div class="order-2 flex-shrink-0 sm:order-3 sm:ml-3">
                        <button type="button" class="close-flash -mr-1 flex p-2 rounded-md hover:bg-yellow-400 focus:outline-none focus:ring-2 focus:ring-white sm:-mr-2">
                            <span class="sr-only">Dismiss</span>
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['warning']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['info'])): ?>
        <div class="flash-message info-message bg-blue-600 text-white" id="info-message">
            <div class="max-w-7xl mx-auto py-3 px-3 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between flex-wrap">
                    <div class="w-0 flex-1 flex items-center">
                        <span class="flex p-2 rounded-lg bg-blue-800">
                            <i class="fas fa-info-circle"></i>
                        </span>
                        <p class="ml-3 font-medium truncate">
                            <?= htmlspecialchars($_SESSION['info']) ?>
                        </p>
                    </div>
                    <div class="order-2 flex-shrink-0 sm:order-3 sm:ml-3">
                        <button type="button" class="close-flash -mr-1 flex p-2 rounded-md hover:bg-blue-500 focus:outline-none focus:ring-2 focus:ring-white sm:-mr-2">
                            <span class="sr-only">Dismiss</span>
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['info']); ?>
    <?php endif; ?>

    <!-- JavaScript for navbar functionality -->
    <script>
        // Mobile menu toggle
        document.addEventListener('DOMContentLoaded', function() {
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');
            
            if (mobileMenuButton && mobileMenu) {
                mobileMenuButton.addEventListener('click', function() {
                    // Toggle the 'hidden' class on the mobile menu
                    mobileMenu.classList.toggle('hidden');
                    
                    // Toggle the icon
                    const openIcon = mobileMenuButton.querySelector('svg.block');
                    const closeIcon = mobileMenuButton.querySelector('svg.hidden');
                    
                    if (openIcon && closeIcon) {
                        openIcon.classList.toggle('hidden');
                        openIcon.classList.toggle('block');
                        closeIcon.classList.toggle('hidden');
                        closeIcon.classList.toggle('block');
                    }
                });
            }
            
            // User menu dropdown
            const userMenuButton = document.getElementById('user-menu-button');
            const userMenu = document.getElementById('user-menu');
            
            if (userMenuButton && userMenu) {
                userMenuButton.addEventListener('click', function() {
                    userMenu.classList.toggle('hidden');
                });
                
                // Close the menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!userMenuButton.contains(event.target) && !userMenu.contains(event.target)) {
                        userMenu.classList.add('hidden');
                    }
                });
            }
            
            // Notification dropdown
            const notificationButton = document.getElementById('notification-button');
            const notificationDropdown = document.getElementById('notification-dropdown');
            
            if (notificationButton && notificationDropdown) {
                notificationButton.addEventListener('click', function() {
                    notificationDropdown.classList.toggle('hidden');
                });
                
                // Close the dropdown when clicking outside
                document.addEventListener('click', function(event) {
                    if (!notificationButton.contains(event.target) && !notificationDropdown.contains(event.target)) {
                        notificationDropdown.classList.add('hidden');
                    }
                });
            }
            
            // Theme toggle functionality
            const themeToggleBtn = document.getElementById('theme-toggle');
            const darkIcon = document.getElementById('theme-toggle-dark-icon');
            const lightIcon = document.getElementById('theme-toggle-light-icon');
            
            if (themeToggleBtn && darkIcon && lightIcon) {
                themeToggleBtn.addEventListener('click', function() {
                    // Toggle icons
                    darkIcon.classList.toggle('hidden');
                    lightIcon.classList.toggle('hidden');
                    
                    // Toggle theme
                    const htmlElement = document.documentElement;
                    const currentTheme = htmlElement.getAttribute('data-theme');
                    
                    if (currentTheme === 'dark') {
                        htmlElement.setAttribute('data-theme', 'light');
                        localStorage.setItem('theme', 'light');
                    } else {
                        htmlElement.setAttribute('data-theme', 'dark');
                        localStorage.setItem('theme', 'dark');
                    }
                    
                    // Apply theme changes
                    applyTheme();
                });
            }
            
            // Flash message close buttons
            const closeButtons = document.querySelectorAll('.close-flash');
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const flashMessage = this.closest('.flash-message');
                    if (flashMessage) {
                        flashMessage.style.opacity = '0';
                        setTimeout(() => {
                            flashMessage.style.display = 'none';
                        }, 300);
                    }
                });
            });
            
            // Auto-hide flash messages after 5 seconds
            const flashMessages = document.querySelectorAll('.flash-message');
            flashMessages.forEach(message => {
                setTimeout(() => {
                    message.style.opacity = '0';
                    setTimeout(() => {
                        message.style.display = 'none';
                    }, 300);
                }, 5000);
            });
            
            // Hide page loader when page is fully loaded
            const pageLoader = document.getElementById('page-loader');
            if (pageLoader) {
                window.addEventListener('load', function() {
                    pageLoader.style.opacity = '0';
                    setTimeout(() => {
                        pageLoader.style.display = 'none';
                    }, 300);
                });
            }
        });
        
        // Function to apply theme
        function applyTheme() {
            const theme = localStorage.getItem('theme') || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
            
            // Update theme toggle icons
            const darkIcon = document.getElementById('theme-toggle-dark-icon');
            const lightIcon = document.getElementById('theme-toggle-light-icon');
            
            if (darkIcon && lightIcon) {
                if (theme === 'dark') {
                    darkIcon.classList.add('hidden');
                    lightIcon.classList.remove('hidden');
                } else {
                    darkIcon.classList.remove('hidden');
                    lightIcon.classList.add('hidden');
                }
            }
        }
        
        // Apply theme on page load
        applyTheme();
    </script>



