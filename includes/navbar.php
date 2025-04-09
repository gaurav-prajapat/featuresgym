<?php
ob_start();
// Set default timezone to Indian Standard Time
date_default_timezone_set('Asia/Kolkata');
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
// require_once '../includes/preload.php';


// Initialize auth if not already done
if (!isset($auth)) {
    $db = new GymDatabase();
    $conn = $db->getConnection();
    $auth = new Auth($conn);
}

// Check if user is authenticated
if (
    !$auth->isAuthenticated() && !in_array(basename($_SERVER['PHP_SELF']), [
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
        'gym-profile.php'
    ])
) {
    $_SESSION['error'] = "Please log in to access this page.";
    header('Location: ' . (strpos($_SERVER['PHP_SELF'], 'admin/') !== false ? '../login.php' :
        (strpos($_SERVER['PHP_SELF'], 'gym/') !== false ? '../login.php' : 'login.php')));
    exit;
}

// For gym partner pages, check if user has gym_partner role
if (strpos($_SERVER['PHP_SELF'], 'gym/') !== false && !isset($_SESSION['owner_id'])) {
    $_SESSION['error'] = "You don't have permission to access the gym partner area.";
    header('Location: ../login.php');
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
$isLoggedIn = isset($_SESSION['user_id']) || isset($_SESSION['owner_id']);
$user_id = $_SESSION['user_id'] ?? ($_SESSION['owner_id'] ?? null);
$username = isset($_SESSION['username']) ? $_SESSION['username'] : (isset($_SESSION['owner_name']) ? $_SESSION['owner_name'] : '');
$account_type = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : (isset($_SESSION['owner_account_type']) ? $_SESSION['owner_account_type'] : 'basic');


// Get unread notifications count based on role
$unreadNotificationsCount = 0;
if ($isLoggedIn) {
    if ($role === 'member') {
        $query = "SELECT COUNT(*) FROM notifications 
                 WHERE user_id = ? 
                 AND status = 'unread'";
        $stmt = $conn->prepare($query);
        $stmt->execute([$user_id]);

    } elseif (isset($_SESSION['owner_id'])) {
        $query = "SELECT COUNT(*) FROM notifications 
                 WHERE gym_id IN (SELECT gym_id FROM gyms WHERE owner_id = ?) 
                 AND is_read = 0";
        $stmt = $conn->prepare($query);
        $stmt->execute([$_SESSION['owner_id']]);
    } else {
        // Default query when no role matches
        $query = "SELECT COUNT(*) FROM notifications WHERE 1=0";
        $stmt = $conn->prepare($query);
        $stmt->execute();
    }

    $unreadNotificationsCount = $stmt->fetchColumn();
}

// Get base URL for consistent paths
$base_url = 'http://localhost/profitmarts/FlexFit';

// Determine current section and set paths accordingly
$in_gym = strpos($_SERVER['REQUEST_URI'], '/gym') !== false;

if ($in_gym) {
    $base_path = '../';
    $gym_path = './';
} else {
    $base_path = './';
    $gym_path = 'gym/';
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
    global $base_path, $gym_path;

    if ($section === 'gym') {
        return $gym_path . $page;
    } else {
        return $base_path . $page;
    }
}


?>
<!DOCTYPE html>
<html lang="en" data-default-theme="<?= htmlspecialchars($site_settings['theme']['default_theme'] ?? 'dark') ?>"
    data-allow-user-theme="<?= htmlspecialchars($site_settings['theme']['allow_user_theme'] ?? '1') ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site_settings['seo']['site_title'] ?? 'ProFitMart - Find Your Perfect Gym') ?></title>

    <!-- Meta tags -->
    <meta name="description" content="<?= htmlspecialchars($site_settings['seo']['meta_description'] ?? '') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($site_settings['seo']['meta_keywords'] ?? '') ?>">

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
        }

        /* Add custom CSS from database if available */
        <?= $site_settings['custom']['custom_css'] ?? '' ?>
    </style>

    <!-- Theme Switcher Script -->
    <script src="<?= $base_url ?>/assets/js/theme-switcher.js"></script>

    <!-- Google Analytics if configured -->
    <?php if (!empty($site_settings['seo']['google_analytics'])): ?>
        <script async
            src="https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($site_settings['seo']['google_analytics']) ?>"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag() { dataLayer.push(arguments); }
            gtag('js', new Date());
            gtag('config', '<?= htmlspecialchars($site_settings['seo']['google_analytics']) ?>');
        </script>
    <?php endif; ?>
</head>

<body class="theme-transition">
    <!-- Rest of your body content -->


    <style>
        .loader-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: #1a1a1a;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }

        .gym-loader {
            position: relative;
            width: 200px;
            height: 200px;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #fbbf24;
        }

        .weightlifter i {
            font-size: 60px;
            animation: lift 1.5s infinite;
            display: none;
        }

        .dumbbell i {
            font-size: 40px;
            margin-left: 20px;
            animation: rotate 2s infinite linear;
        }

        @keyframes lift {
            0% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-20px);
            }

            100% {
                transform: translateY(0);
            }
        }

        @keyframes rotate {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        /* Toast animations */
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }

            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }

            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        #toast-container>div {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }


        :root {
            /* Dark mode (default) variables */
            --bg-primary: #111827;
            --bg-secondary: #1F2937;
            --bg-tertiary: #374151;
            --text-primary: #FFFFFF;
            --text-secondary: #9CA3AF;
            --text-muted: #6B7280;
            --accent: #FBBF24;
            --accent-hover: #F59E0B;
            --danger: #EF4444;
            --danger-hover: #DC2626;
            --success: #10B981;
            --success-hover: #059669;
            --info: #3B82F6;
            --info-hover: #2563EB;
            --border-color: #4B5563;
            --card-bg: #1F2937;
            --input-bg: #374151;
            --input-text: #FFFFFF;
            --shadow-color: rgba(0, 0, 0, 0.5);
            --indigo: #6366F1;
            --indigo-light: #818CF8;
            --yellow-light: #FCD34D;
        }

        :root.light-mode {
            /* Light mode variables */
            --bg-primary: #F3F4F6;
            --bg-secondary: #FFFFFF;
            --bg-tertiary: #F9FAFB;
            --text-primary: #111827;
            --text-secondary: #4B5563;
            --text-muted: #6B7280;
            --accent: #D97706;
            --accent-hover: #B45309;
            --danger: #DC2626;
            --danger-hover: #B91C1C;
            --success: #059669;
            --success-hover: #047857;
            --info: #2563EB;
            --info-hover: #1D4ED8;
            --border-color: #E5E7EB;
            --card-bg: #FFFFFF;
            --input-bg: #F9FAFB;
            --input-text: #111827;
            --shadow-color: rgba(0, 0, 0, 0.1);
            --indigo: #4F46E5;
            --indigo-light: #6366F1;
            --yellow-light: #FBBF24;
        }

        /* Apply the dark mode variables by default */
        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        /* Background colors */
        .bg-gray-100 {
            background-color: var(--bg-primary);
        }

        .bg-gray-200 {
            background-color: var(--bg-tertiary);
        }

        .bg-gray-600 {
            background-color: var(--border-color);
        }

        .bg-gray-700 {
            background-color: var(--bg-tertiary);
        }

        .bg-gray-800 {
            background-color: var(--bg-secondary);
        }

        .bg-gray-900 {
            background-color: var(--bg-primary);
        }

        .bg-black {
            background-color: var(--bg-secondary);
        }

        .bg-white {
            background-color: var(--card-bg);
        }

        /* From-to gradients */
        .from-gray-900 {
            --tw-gradient-from: var(--bg-primary);
        }

        .to-gray-800 {
            --tw-gradient-to: var(--bg-secondary);
        }

        .to-black {
            --tw-gradient-to: var(--bg-secondary);
        }

        /* Text colors */
        .text-white {
            color: var(--text-primary);
        }

        .text-black {
            color: var(--text-primary);
        }

        .text-gray-100 {
            color: var(--text-primary);
        }

        .text-gray-200 {
            color: var(--text-primary);
        }

        .text-gray-300 {
            color: var(--text-secondary);
        }

        .text-gray-400 {
            color: var(--text-secondary);
        }

        .text-gray-500 {
            color: var(--text-secondary);
        }

        .text-gray-600 {
            color: var(--text-muted);
        }

        .text-gray-700 {
            color: var(--text-muted);
        }

        .text-gray-800 {
            color: var(--text-primary);
        }

        .text-gray-900 {
            color: var(--text-primary);
        }

        /* Accent colors */
        .text-yellow-300 {
            color: var(--yellow-light);
        }

        .text-yellow-400 {
            color: var(--accent);
        }

        .text-yellow-500 {
            color: var(--accent);
        }

        .text-yellow-600 {
            color: var(--accent-hover);
        }

        .bg-yellow-100 {
            background-color: rgba(251, 191, 36, 0.1);
        }

        .bg-yellow-500 {
            background-color: var(--accent);
        }

        .bg-yellow-600 {
            background-color: var(--accent-hover);
        }

        .bg-yellow-900 {
            background-color: rgba(251, 191, 36, 0.2);
        }

        .border-yellow-300 {
            border-color: var(--yellow-light);
        }

        .border-yellow-500 {
            border-color: var(--accent);
        }

        .border-yellow-600 {
            border-color: var(--accent-hover);
        }

        /* Danger colors */
        .text-red-300 {
            color: var(--danger-hover);
        }

        .text-red-400 {
            color: var(--danger);
        }

        .text-red-500 {
            color: var(--danger);
        }

        .text-red-600 {
            color: var(--danger-hover);
        }

        .text-red-800 {
            color: var(--danger-hover);
        }

        .bg-red-100 {
            background-color: rgba(239, 68, 68, 0.1);
        }

        .bg-red-500 {
            background-color: var(--danger);
        }

        .bg-red-600 {
            background-color: var(--danger-hover);
        }

        .bg-red-900 {
            background-color: rgba(239, 68, 68, 0.2);
        }

        .border-red-500 {
            border-color: var(--danger);
        }

        /* Success colors */
        .text-green-300 {
            color: var(--success-hover);
        }

        .text-green-500 {
            color: var(--success);
        }

        .text-green-600 {
            color: var(--success-hover);
        }

        .text-green-800 {
            color: var(--success-hover);
        }

        .bg-green-100 {
            background-color: rgba(16, 185, 129, 0.1);
        }

        .bg-green-500 {
            background-color: var(--success);
        }

        .bg-green-600 {
            background-color: var(--success-hover);
        }

        .bg-green-900 {
            background-color: rgba(16, 185, 129, 0.2);
        }

        .border-green-500 {
            border-color: var(--success);
        }

        /* Info colors */
        .text-blue-300 {
            color: var(--info-hover);
        }

        .text-blue-500 {
            color: var(--info);
        }

        .text-blue-600 {
            color: var(--info-hover);
        }

        .bg-blue-100 {
            background-color: rgba(59, 130, 246, 0.1);
        }

        .bg-blue-500 {
            background-color: var(--info);
        }

        .bg-blue-600 {
            background-color: var(--info-hover);
        }

        .bg-blue-900 {
            background-color: rgba(59, 130, 246, 0.2);
        }

        .border-blue-500 {
            border-color: var(--info);
        }

        /* Indigo colors */
        .text-indigo-300 {
            color: var(--indigo-light);
        }

        .text-indigo-400 {
            color: var(--indigo);
        }

        .text-indigo-500 {
            color: var(--indigo);
        }

        .bg-indigo-100 {
            background-color: rgba(99, 102, 241, 0.1);
        }

        .bg-indigo-500 {
            background-color: var(--indigo);
        }

        .bg-indigo-600 {
            background-color: var(--indigo);
        }

        .border-indigo-500 {
            border-color: var(--indigo);
        }

        /* Border colors */
        .border-gray-200 {
            border-color: var(--border-color);
        }

        .border-gray-300 {
            border-color: var(--border-color);
        }

        .border-gray-600 {
            border-color: var(--border-color);
        }

        .border-gray-700 {
            border-color: var(--border-color);
        }

        .border-gray-800 {
            border-color: var(--border-color);
        }

        /* Input field styling with theme support */
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        input[type="date"],
        input[type="time"],
        input[type="search"],
        input[type="tel"],
        input[type="url"],
        textarea,
        select {
            background-color: var(--input-bg);
            color: var(--input-text);
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            padding: 0.5rem 0.75rem;
            width: 100%;
            font-size: 1rem;
            line-height: 1.5;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        /* Focus state */
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus,
        input[type="number"]:focus,
        input[type="date"]:focus,
        input[type="time"]:focus,
        input[type="search"]:focus,
        input[type="tel"]:focus,
        input[type="url"]:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.2);
        }

        /* Placeholder text */
        ::placeholder {
            color: var(--text-muted);
            opacity: 0.7;
        }

        /* Disabled state */
        input:disabled,
        textarea:disabled,
        select:disabled {
            background-color: var(--bg-tertiary);
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* Error state */
        input.error,
        textarea.error,
        select.error {
            border-color: var(--danger);
        }

        input.error:focus,
        textarea.error:focus,
        select.error:focus {
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2);
        }

        /* Success state */
        input.success,
        textarea.success,
        select.success {
            border-color: var(--success);
        }

        input.success:focus,
        textarea.success:focus,
        select.success:focus {
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
        }

        /* Checkbox and radio styling */
        input[type="checkbox"],
        input[type="radio"] {
            appearance: none;
            -webkit-appearance: none;
            width: 1.25rem;
            height: 1.25rem;
            border: 1px solid var(--border-color);
            background-color: var(--input-bg);
            display: inline-block;
            position: relative;
            cursor: pointer;
            margin-right: 0.5rem;
            vertical-align: middle;
        }

        input[type="checkbox"] {
            border-radius: 0.25rem;
        }

        input[type="radio"] {
            border-radius: 50%;
        }

        input[type="checkbox"]:checked,
        input[type="radio"]:checked {
            background-color: var(--accent);
            border-color: var(--accent);
        }

        input[type="checkbox"]:checked::after {
            content: "✓";
            font-size: 0.875rem;
            color: white;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        input[type="radio"]:checked::after {
            content: "";
            width: 0.625rem;
            height: 0.625rem;
            border-radius: 50%;
            background-color: white;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        input[type="checkbox"]:focus,
        input[type="radio"]:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(251, 191, 36, 0.2);
        }

        /* File input styling */
        input[type="file"] {
            background-color: var(--input-bg);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            padding: 0.375rem 0.75rem;
            cursor: pointer;
            width: 100%;
        }

        input[type="file"]::-webkit-file-upload-button {
            background-color: var(--accent);
            color: white;
            border: none;
            padding: 0.375rem 0.75rem;
            margin-right: 0.75rem;
            border-radius: 0.25rem;
            cursor: pointer;
            transition: background-color 0.15s ease-in-out;
        }

        input[type="file"]::-webkit-file-upload-button:hover {
            background-color: var(--accent-hover);
        }

        /* Range input styling */
        input[type="range"] {
            -webkit-appearance: none;
            width: 100%;
            height: 0.5rem;
            background-color: var(--bg-tertiary);
            border-radius: 0.25rem;
            outline: none;
        }

        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 1.25rem;
            height: 1.25rem;
            background-color: var(--accent);
            border-radius: 50%;
            cursor: pointer;
        }

        input[type="range"]::-moz-range-thumb {
            width: 1.25rem;
            height: 1.25rem;
            background-color: var(--accent);
            border-radius: 50%;
            cursor: pointer;
            border: none;
        }

        /* Card styling */
        .card {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px var(--shadow-color);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        /* Button styling */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            font-weight: 500;
            border-radius: 0.375rem;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }

        .btn-primary {
            background-color: var(--accent);
            color: white;
        }

        .btn-primary:hover {
            background-color: var(--accent-hover);
        }

        .btn-danger {
            background-color: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background-color: var(--danger-hover);
        }

        .btn-success {
            background-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background-color: var(--success-hover);
        }

        .btn-info {
            background-color: var(--info);
            color: white;
        }

        .btn-info:hover {
            background-color: var(--info-hover);
        }

        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .btn-outline:hover {
            background-color: var(--bg-tertiary);
        }

        /* Table styling */
        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 1.5rem;
        }

        table th {
            background-color: var(--bg-tertiary);
            color: var(--text-primary);
            font-weight: 600;
            text-align: left;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        table tr:hover td {
            background-color: var(--bg-tertiary);
        }

        /* Badge styling */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1;
            border-radius: 9999px;
        }

        .badge-success {
            background-color: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        .badge-danger {
            background-color: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .badge-warning {
            background-color: rgba(251, 191, 36, 0.2);
            color: var(--accent);
        }

        .badge-info {
            background-color: rgba(59, 130, 246, 0.2);
            color: var(--info);
        }

        /* Alert styling */
        .alert {
            padding: 1rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            border-left-width: 4px;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            border-color: var(--success);
            color: var(--success);
        }

        .alert-danger {
            background-color: rgba(239, 68, 68, 0.1);
            border-color: var(--danger);
            color: var(--danger);
        }

        .alert-warning {
            background-color: rgba(251, 191, 36, 0.1);
            border-color: var(--accent);
            color: var(--accent);
        }

        .alert-info {
            background-color: rgba(59, 130, 246, 0.1);
            border-color: var(--info);
            color: var(--info);
        }

        /* Modal styling */
        .modal {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px var(--shadow-color), 0 4px 6px -2px var(--shadow-color);
        }

        .modal-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-body {
            padding: 1rem;
        }

        .modal-footer {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
        }

        /* Dropdown styling */
        .dropdown-menu {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.375rem;
            box-shadow: 0 4px 6px var(--shadow-color);
        }

        .dropdown-item {
            padding: 0.5rem 1rem;
            color: var(--text-primary);
        }

        .dropdown-item:hover {
            background-color: var(--bg-tertiary);
        }

        .dropdown-divider {
            border-top: 1px solid var(--border-color);
            margin: 0.5rem 0;
        }

        /* Navbar styling */
        .navbar {
            background-color: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
        }

        /* Sidebar styling */
        .sidebar {
            background-color: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
        }

        .sidebar-link {
            color: var(--text-secondary);
            padding: 0.75rem 1rem;
            display: flex;
            align-items: center;
        }

        .sidebar-link:hover,
        .sidebar-link.active {
            background-color: var(--bg-tertiary);
            color: var(--accent);
        }

        /* Pagination styling */
        .pagination {
            display: flex;
            list-style: none;
            padding: 0;
            margin: 1.5rem 0;
        }

        .page-item {
            margin: 0 0.25rem;
        }

        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 0.75rem;
            border-radius: 0.375rem;
            background-color: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .page-link:hover {
            background-color: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .page-item.active .page-link {
            background-color: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        /* Progress bar styling */
        .progress {
            height: 0.75rem;
            background-color: var(--bg-tertiary);
            border-radius: 9999px;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .progress-bar {
            height: 100%;
            background-color: var(--accent);
        }

        /* Tooltip styling */
        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltip-text {
            visibility: hidden;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            text-align: center;
            padding: 0.5rem;
            border-radius: 0.375rem;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s;
            box-shadow: 0 2px 4px var(--shadow-color);
            border: 1px solid var(--border-color);
            width: max-content;
            max-width: 250px;
        }

        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }

        /* Specific components from your codebase */
        /* Statistics Cards */
        .statistics-card {
            background-color: var(--bg-secondary);
            border-radius: 0.75rem;
            box-shadow: 0 4px 6px var(--shadow-color);
            padding: 1rem;
        }

        .statistics-card-icon {
            background-color: rgba(59, 130, 246, 0.2);
            color: var(--info);
            padding: 0.75rem;
            border-radius: 9999px;
        }

        .statistics-card-icon.yellow {
            background-color: rgba(251, 191, 36, 0.2);
            color: var(--accent);
        }

        .statistics-card-icon.green {
            background-color: rgba(16, 185, 129, 0.2);
            color: var(--success);
        }

        .statistics-card-icon.red {
            background-color: rgba(239, 68, 68, 0.2);
            color: var(--danger);
        }

        .statistics-card-icon.purple {
            background-color: rgba(139, 92, 246, 0.2);
            color: var(--indigo);
        }

        /* User cards */
        .user-card {
            background-color: var(--bg-tertiary);
            border-radius: 0.5rem;
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .user-avatar {
            height: 2.5rem;
            width: 2.5rem;
            border-radius: 9999px;
            object-fit: cover;
        }

        .user-avatar-placeholder {
            height: 2.5rem;
            width: 2.5rem;
            border-radius: 9999px;
            background-color: var(--bg-tertiary);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Schedule cards */
        .schedule-card {
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            transition: box-shadow 0.2s ease-in-out;
        }

        .schedule-card:hover {
            box-shadow: 0 4px 6px var(--shadow-color);
        }

        .schedule-icon {
            background-color: rgba(251, 191, 36, 0.1);
            padding: 0.75rem;
            border-radius: 9999px;
        }

        .schedule-status {
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .schedule-status.completed {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        .schedule-status.cancelled {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--danger);
        }

        .schedule-status.scheduled {
            background-color: rgba(251, 191, 36, 0.1);
            color: var(--accent);
        }

        /* Calendar styling */
        .fc-theme-standard .fc-scrollgrid,
        .fc-theme-standard td,
        .fc-theme-standard th {
            border-color: var(--border-color);
        }

        .fc-theme-standard .fc-toolbar {
            color: var(--text-primary);
        }

        .fc-theme-standard .fc-button {
            background-color: var(--bg-tertiary);
            border-color: var(--border-color);
            color: var(--text-primary);
        }

        .fc-theme-standard .fc-button:hover {
            background-color: var(--accent);
            border-color: var(--accent);
            color: white;
        }

        .fc-theme-standard .fc-button-primary:not(:disabled).fc-button-active,
        .fc-theme-standard .fc-button-primary:not(:disabled):active {
            background-color: var(--accent);
            border-color: var(--accent);
            color: white;
        }

        .fc-theme-standard .fc-daygrid-day-number,
        .fc-theme-standard .fc-col-header-cell-cushion {
            color: var(--text-primary);
        }

        .fc-theme-standard .fc-event {
            background-color: var(--accent);
            border-color: var(--accent-hover);
        }

        /* Transitions for smooth theme switching */
        .theme-transition,
        .theme-transition * {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease, box-shadow 0.3s ease;
        }

        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes slideOut {
            from {
                transform: translateY(0);
                opacity: 1;
            }

            to {
                transform: translateY(-10px);
                opacity: 0;
            }
        }

        .animate-fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }

        .animate-slide-in {
            animation: slideIn 0.3s ease-out forwards;
        }

        .animate-slide-out {
            animation: slideOut 0.3s ease-in forwards;
        }

        /* Toast notifications */
        #toast-container {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            max-width: 24rem;
        }

        .toast {
            border-radius: 0.375rem;
            padding: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 6px var(--shadow-color);
            animation: slideIn 0.3s ease-out forwards;
        }

        .toast-success {
            background-color: var(--success);
            color: white;
        }

        .toast-error {
            background-color: var(--danger);
            color: white;
        }

        .toast-warning {
            background-color: var(--accent);
            color: white;
        }

        .toast-info {
            background-color: var(--info);
            color: white;
        }

        /* Loader */
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
            opacity: 1;
            transition: opacity 0.5s ease;
        }

        .loader {
            border: 4px solid var(--bg-tertiary);
            border-top: 4px solid var(--accent);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Media queries for responsive design */
        @media (max-width: 640px) {
            .card {
                padding: 1rem;
            }

            table th,
            table td {
                padding: 0.5rem;
            }

            .btn {
                padding: 0.375rem 0.75rem;
            }
        }
    </style>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const loader = document.querySelector('.loader-container');

            window.addEventListener('load', () => {
                setTimeout(() => {
                    loader.style.opacity = '0';
                    setTimeout(() => {
                        loader.style.display = 'none';
                    }, 100);
                }, 100);
            });
        });

        // toggle theme
        function initTheme() {
            const theme = localStorage.getItem('theme') || 'dark';
            document.documentElement.classList.toggle('light-mode', theme === 'light');
            return theme;
        }

        function toggleTheme() {
            const currentTheme = document.documentElement.classList.contains('light-mode') ? 'dark' : 'light';
            localStorage.setItem('theme', currentTheme);
            document.documentElement.classList.toggle('light-mode');
        }
        document.addEventListener('DOMContentLoaded', () => {
            initTheme();
            document.getElementById('themeToggle').addEventListener('click', toggleTheme);
        });
    </script>
    </head>

    <body class="bg-gray-900 text-white">
        <div class="loader-container">
            <div class="gym-loader">
                <div class="weightlifter">
                    <i class="fas fa-dumbbell"></i>
                </div>
                <div class="dumbbell">
                    <i class="fas fa-dumbbell"></i>
                </div>
            </div>
        </div>

        <div id="toast-container" class="fixed top-4 right-4 z-50 flex flex-col space-y-4 max-w-xs"></div>

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
                    </div>
                    <div class="flex-1 flex items-center justify-center sm:items-stretch sm:justify-start">
                        <div class="flex-shrink-0 flex items-center">
                            <a href="<?php echo $base_url; ?>/" class="flex items-center">
                                <span class="text-yellow-500 text-2xl font-bold mr-1">Flex</span>
                                <span class="text-white text-2xl font-bold">Fit</span>
                            </a>
                        </div>
                        <div class="hidden sm:block sm:ml-6">
                            <div class="flex space-x-4">
                                <?php if (isset($_SESSION['owner_id'])): ?>
                                    <!-- Gym Partner Navigation -->
                                    <a href="<?php echo $base_url; ?>/gym/dashboard.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('dashboard.php', 'bg-gray-900 text-white'); ?>">
                                        Dashboard
                                    </a>
                                    <a href="<?php echo $base_url; ?>/gym/member_list.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('member_list.php', 'bg-gray-900 text-white'); ?>">
                                        Members
                                    </a>
                                    <a href="<?php echo $base_url; ?>/gym/schedules.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('schedules.php', 'bg-gray-900 text-white'); ?>">
                                        Schedules
                                    </a>
                                    <a href="<?php echo $base_url; ?>/gym/membership_plans.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('membership_plans.php', 'bg-gray-900 text-white'); ?>">
                                        Plans
                                    </a>
                                    <a href="<?php echo $base_url; ?>/gym/revenue.php"
                                        class="text-gray-300 hover:bg-gray-700 hover:text-white px-3 py-2 rounded-md text-sm font-medium <?php echo isActivePage('revenue.php', 'bg-gray-900 text-white'); ?>">
                                        Revenue
                                    </a>
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
                                        <a href="<?php echo $base_url; ?>/gym/profile.php"
                                            class="block px-4 py-2 text-sm text-gray-300 hover:bg-gray-600 hover:text-white"
                                            role="menuitem">Your Profile</a>
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
                            Dashboard
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/member_list.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('member_list.php', 'bg-gray-900 text-white'); ?>">
                            Members
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/schedules.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('schedules.php', 'bg-gray-900 text-white'); ?>">
                            Schedules
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/membership_plans.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('membership_plans.php', 'bg-gray-900 text-white'); ?>">
                            Plans
                        </a>
                        <a href="<?php echo $base_url; ?>/gym/revenue.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('revenue.php', 'bg-gray-900 text-white'); ?>">
                            Revenue
                        </a>
                    <?php elseif (isset($_SESSION['user_id']) && $_SESSION['role'] === 'member'): ?>
                        <!-- Member Mobile Navigation -->
                        <a href="<?php echo $base_url; ?>/dashboard.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('dashboard.php', 'bg-gray-900 text-white'); ?>">
                            Dashboard
                        </a>
                        <a href="<?php echo $base_url; ?>/my_memberships.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('my_memberships.php', 'bg-gray-900 text-white'); ?>">
                            My Memberships
                        </a>
                        <a href="<?php echo $base_url; ?>/my_schedules.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('my_schedules.php', 'bg-gray-900 text-white'); ?>">
                            My Schedules
                        </a>
                        <a href="<?php echo $base_url; ?>/all-gyms.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('all-gyms.php', 'bg-gray-900 text-white'); ?>">
                            Find Gyms
                        </a>
                    <?php else: ?>
                        <!-- Public Mobile Navigation -->
                        <a href="<?php echo $base_url; ?>/"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('index.php', 'bg-gray-900 text-white'); ?>">
                            Home
                        </a>
                        <a href="<?php echo $base_url; ?>/all-gyms.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('all-gyms.php', 'bg-gray-900 text-white'); ?>">
                            Find Gyms
                        </a>
                        <a href="<?php echo $base_url; ?>/about-us.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('about-us.php', 'bg-gray-900 text-white'); ?>">
                            About Us
                        </a>
                        <a href="<?php echo $base_url; ?>/contact.php"
                            class="text-gray-300 hover:bg-gray-700 hover:text-white block px-3 py-2 rounded-md text-base font-medium <?php echo isActivePage('contact.php', 'bg-gray-900 text-white'); ?>">
                            Contact
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <?php if (isset($_SESSION['success']) || isset($_SESSION['error']) || isset($_SESSION['warning']) || isset($_SESSION['info'])): ?>
            <div class="container mx-auto px-4 pt-20">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4 rounded" role="alert">
                        <p><?php echo $_SESSION['success']; ?></p>
                    </div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4 rounded" role="alert">
                        <p><?php echo $_SESSION['error']; ?></p>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['warning'])): ?>
                    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4 rounded" role="alert">
                        <p><?php echo $_SESSION['warning']; ?></p>
                    </div>
                    <?php unset($_SESSION['warning']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['info'])): ?>
                    <div class="bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4 mb-4 rounded" role="alert">
                        <p><?php echo $_SESSION['info']; ?></p>
                    </div>
                    <?php unset($_SESSION['info']); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <script>
            // Mobile menu toggle
            document.getElementById('mobile-menu-button').addEventListener('click', function () {
                const mobileMenu = document.getElementById('mobile-menu');
                mobileMenu.classList.toggle('hidden');
            });

            // User menu toggle
            const userMenuButton = document.getElementById('user-menu-button');
            if (userMenuButton) {
                userMenuButton.addEventListener('click', function () {
                    const userMenu = document.getElementById('user-menu');
                    userMenu.classList.toggle('hidden');
                });
            }

            // Notification menu toggle
            const notificationButton = document.getElementById('notification-button');
            if (notificationButton) {
                notificationButton.addEventListener('click', function () {
                    const notificationDropdown = document.getElementById('notification-dropdown');
                    notificationDropdown.classList.toggle('hidden');

                    // Load notifications via AJAX when opened
                    if (!notificationDropdown.classList.contains('hidden')) {
                        loadNotifications();
                    }
                });
            }

            // Close dropdowns when clicking outside
            document.addEventListener('click', function (event) {
                const userMenu = document.getElementById('user-menu');
                const userMenuButton = document.getElementById('user-menu-button');
                const notificationDropdown = document.getElementById('notification-dropdown');
                const notificationButton = document.getElementById('notification-button');

                if (userMenu && userMenuButton && !userMenuButton.contains(event.target) && !userMenu.contains(event.target)) {
                    userMenu.classList.add('hidden');
                }

                if (notificationDropdown && notificationButton && !notificationButton.contains(event.target) && !notificationDropdown.contains(event.target)) {
                    notificationDropdown.classList.add('hidden');
                }
            });

            // Theme toggle functionality
            const themeToggle = document.getElementById('theme-toggle');
            const darkIcon = document.getElementById('dark-icon');
            const lightIcon = document.getElementById('light-icon');
            const htmlElement = document.documentElement;

            // Check for saved theme preference or use device preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'light') {
                htmlElement.classList.add('light-mode');
                darkIcon.classList.add('hidden');
                lightIcon.classList.remove('hidden');
            } else {
                htmlElement.classList.remove('light-mode');
                darkIcon.classList.remove('hidden');
                lightIcon.classList.add('hidden');
            }

            themeToggle.addEventListener('click', function () {
                if (htmlElement.classList.contains('light-mode')) {
                    // Switch to dark mode
                    htmlElement.classList.remove('light-mode');
                    localStorage.setItem('theme', 'dark');
                    darkIcon.classList.remove('hidden');
                    lightIcon.classList.add('hidden');
                } else {
                    // Switch to light mode
                    htmlElement.classList.add('light-mode');
                    localStorage.setItem('theme', 'light');
                    darkIcon.classList.add('hidden');
                    lightIcon.classList.remove('hidden');
                }
            });


            // Function to load notifications via AJAX
            function loadNotifications() {
                const notificationList = document.getElementById('notification-list');
                if (!notificationList) return;

                // Show loading state
                notificationList.innerHTML = '<div class="px-4 py-2 text-sm text-gray-400 text-center">Loading notifications...</div>';

                // Make AJAX request
                fetch('<?php echo $base_url; ?>/api/get_notifications.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.notifications.length > 0) {
                            let notificationsHtml = '';

                            data.notifications.forEach(notification => {
                                const isUnread = notification.status === 'unread' || notification.is_read === 0;
                                const unreadClass = isUnread ? 'bg-gray-600' : '';

                                notificationsHtml += `
                                <a href="${notification.link || '#'}" class="block px-4 py-2 hover:bg-gray-600 ${unreadClass}">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0 pt-0.5">
                                            <div class="h-8 w-8 rounded-full bg-yellow-500 flex items-center justify-center">
                                                <i class="fas fa-bell text-white"></i>
                                            </div>
                                        </div>
                                        <div class="ml-3 w-0 flex-1">
                                            <p class="text-sm font-medium text-white">${notification.title}</p>
                                            <p class="text-xs text-gray-400 truncate">${notification.message}</p>
                                            <p class="text-xs text-gray-500 mt-1">${notification.created_at}</p>
                                        </div>
                                        ${isUnread ? '<span class="ml-2 h-2 w-2 rounded-full bg-yellow-500"></span>' : ''}
                                    </div>
                                </a>
                            `;
                            });

                            notificationList.innerHTML = notificationsHtml;
                        } else {
                            notificationList.innerHTML = '<div class="px-4 py-2 text-sm text-gray-400 text-center">No notifications found</div>';
                        }
                    })
                    .catch(error => {
                        console.error('Error loading notifications:', error);
                        notificationList.innerHTML = '<div class="px-4 py-2 text-sm text-red-400 text-center">Failed to load notifications</div>';
                    });
            }

            // Page loader
            window.addEventListener('load', function () {
                const loader = document.querySelector('.loader-container');
                setTimeout(function () {
                    loader.style.opacity = '0';
                    setTimeout(function () {
                        loader.style.display = 'none';
                    }, 500);
                }, 500);
            });

            // Toast notification system
            function showToast(message, type = 'info', duration = 5000) {
                const toastContainer = document.getElementById('toast-container');
                const toast = document.createElement('div');

                // Set toast classes based on type
                let bgColor, textColor, icon;
                switch (type) {
                    case 'success':
                        bgColor = 'bg-green-500';
                        textColor = 'text-white';
                        icon = '<i class="fas fa-check-circle mr-2"></i>';
                        break;
                    case 'error':
                        bgColor = 'bg-red-500';
                        textColor = 'text-white';
                        icon = '<i class="fas fa-exclamation-circle mr-2"></i>';
                        break;
                    case 'warning':
                        bgColor = 'bg-yellow-500';
                        textColor = 'text-white';
                        icon = '<i class="fas fa-exclamation-triangle mr-2"></i>';
                        break;
                    default:
                        bgColor = 'bg-blue-500';
                        textColor = 'text-white';
                        icon = '<i class="fas fa-info-circle mr-2"></i>';
                }

                toast.className = `${bgColor} ${textColor} p-4 rounded-lg shadow-lg flex items-center justify-between`;
                toast.style.animation = 'slideIn 0.3s ease-out forwards';
                toast.innerHTML = `
                <div class="flex items-center">
                    ${icon}
                    <span>${message}</span>
                </div>
                <button class="ml-4 text-white focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            `;

                // Add to container
                toastContainer.appendChild(toast);

                // Close button functionality
                const closeButton = toast.querySelector('button');
                closeButton.addEventListener('click', () => {
                    toast.style.animation = 'slideOut 0.3s ease-in forwards';
                    setTimeout(() => {
                        toastContainer.removeChild(toast);
                    }, 300);
                });

                // Auto-remove after duration
                setTimeout(() => {
                    if (toastContainer.contains(toast)) {
                        toast.style.animation = 'slideOut 0.3s ease-in forwards';
                        setTimeout(() => {
                            if (toastContainer.contains(toast)) {
                                toastContainer.removeChild(toast);
                            }
                        }, 300);
                    }
                }, duration);
            }

            // Example of how to use the toast:
            // showToast('This is a success message', 'success');
            // showToast('This is an error message', 'error');
            // showToast('This is a warning message', 'warning');
            // showToast('This is an info message', 'info');
        </script>
    </body>

</html>