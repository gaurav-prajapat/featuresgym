<?php
session_start();
require '../config/database.php';
// include '../includes/navbar.php';

$db = new GymDatabase();
$conn = $db->getConnection();

// Check if owner is logged in
if (!isset($_SESSION['owner_id'])) {
    header("Location: login.html");
    exit;
}

$owner_id = $_SESSION['owner_id'];

// Fetch gym details for this owner
$query = "SELECT * FROM gyms WHERE owner_id = :owner_id";
$stmt = $conn->prepare($query);
$stmt->execute([':owner_id' => $owner_id]);
$gym = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$gym) {
    header("Location: add_gym.php");
    exit;
}

$gym_id = $gym['gym_id'];

// Fetch permissions
$stmt = $conn->prepare("SELECT * FROM gym_edit_permissions WHERE gym_id = ?");
$stmt->execute([$gym_id]);
$permissions = $stmt->fetch(PDO::FETCH_ASSOC);

// If no permissions set, use default (everything allowed except gym_cut_percentage)
if (!$permissions) {
    $permissions = [
        'basic_info' => 1,
        'operating_hours' => 1,
        'amenities' => 1,
        'images' => 1,
        'equipment' => 1,
        'membership_plans' => 1,
        'gym_cut_percentage' => 0
    ];
}

// Get active tab from URL or default to basic_info
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'basic_info';

// Check for success/error messages in session
$success_message = '';
$error_message = '';

if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Fetch operating hours
$hours_query = "SELECT * FROM gym_operating_hours WHERE gym_id = ?";
$hours_stmt = $conn->prepare($hours_query);
$hours_stmt->execute([$gym_id]);
$operating_hours = $hours_stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if gym uses daily schedule
$has_daily_schedule = false;
$hours_by_day = [];
$closed_days = [];

foreach ($operating_hours as $hours) {
    $hours_by_day[$hours['day']] = $hours;

    // Check if this day is closed
    if (isset($hours['is_closed']) && $hours['is_closed'] == 1) {
        $closed_days[] = strtolower($hours['day']);
    } elseif (
        $hours['morning_open_time'] === '00:00:00' &&
        $hours['morning_close_time'] === '00:00:00' &&
        $hours['evening_open_time'] === '00:00:00' &&
        $hours['evening_close_time'] === '00:00:00'
    ) {
        $closed_days[] = strtolower($hours['day']);
    }

    if ($hours['day'] === 'Daily') {
        $has_daily_schedule = true;
    }
}

// Define days of week for operating hours
$days_of_week = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

// Fetch gallery images
$gallery_query = "SELECT * FROM gym_images WHERE gym_id = ? AND status = 'active' ORDER BY display_order ASC";
$gallery_stmt = $conn->prepare($gallery_query);
$gallery_stmt->execute([$gym_id]);
$gallery_images = $gallery_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch equipment
$equipment_query = "SELECT * FROM gym_equipment WHERE gym_id = ? AND status = 'active' ORDER BY category, name";
$equipment_stmt = $conn->prepare($equipment_query);
$equipment_stmt->execute([$gym_id]);
$equipment = $equipment_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch membership plans
$plans_query = "SELECT * FROM gym_membership_plans WHERE gym_id = ? ORDER BY price ASC";
$plans_stmt = $conn->prepare($plans_query);
$plans_stmt->execute([$gym_id]);
$membership_plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all amenities for selection
$amenities_query = "SELECT * FROM amenities WHERE availability = 1 ORDER BY category, name";
$amenities_stmt = $conn->prepare($amenities_query);
$amenities_stmt->execute();
$all_amenities = $amenities_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group amenities by category
$amenities_by_category = [];
foreach ($all_amenities as $amenity) {
    $category = $amenity['category'] ?: 'Other';
    if (!isset($amenities_by_category[$category])) {
        $amenities_by_category[$category] = [];
    }
    $amenities_by_category[$category][] = $amenity;
}

// Get gym's selected amenities
$selected_amenities = [];
if (!empty($gym['amenities'])) {
    $selected_amenities = json_decode($gym['amenities'], true) ?: [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Gym Details</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .tab-button.active {
            background-color: #4F46E5;
            color: white;
        }

        .tab-button:not(.active):hover {
            background-color: #E5E7EB;
        }

        /* Dark mode styles */
        .dark .tab-button.active {
            background-color: #6366F1;
        }

        .dark .tab-button:not(.active):hover {
            background-color: #374151;
        }
    </style>
</head>

<body class="bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-200">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-white dark:bg-gray-800 shadow">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex justify-between items-center">
                <h1 class="text-2xl font-bold">Edit Gym Details</h1>
                <div>
                    <a href="dashboard.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg mr-2">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
                    </a>
                    <a href="../gym-profile.php?id=<?php echo $gym_id; ?>" target="_blank"
                        class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-eye mr-2"></i> View Gym Profile
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <?php if (!empty($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <p><?php echo $success_message; ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <p><?php echo $error_message; ?></p>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <div class="mb-6 overflow-x-auto">
                <div class="flex space-x-2 border-b border-gray-200 dark:border-gray-700">
                    <button
                        class="tab-button px-4 py-2 font-medium rounded-t-lg <?php echo $active_tab === 'basic_info' ? 'active' : ''; ?>"
                        data-tab="basic-info">
                        <i class="fas fa-info-circle mr-2"></i> Basic Info
                    </button>
                    <button
                        class="tab-button px-4 py-2 font-medium rounded-t-lg <?php echo $active_tab === 'operating_hours' ? 'active' : ''; ?>"
                        data-tab="operating-hours">
                        <i class="fas fa-clock mr-2"></i> Operating Hours
                    </button>
                    <button
                        class="tab-button px-4 py-2 font-medium rounded-t-lg <?php echo $active_tab === 'amenities' ? 'active' : ''; ?>"
                        data-tab="amenities">
                        <i class="fas fa-spa mr-2"></i> Amenities
                    </button>
                    <button
                        class="tab-button px-4 py-2 font-medium rounded-t-lg <?php echo $active_tab === 'images' ? 'active' : ''; ?>"
                        data-tab="images">
                        <i class="fas fa-images mr-2"></i> Images
                    </button>
                    <button
                        class="tab-button px-4 py-2 font-medium rounded-t-lg <?php echo $active_tab === 'equipment' ? 'active' : ''; ?>"
                        data-tab="equipment">
                        <i class="fas fa-dumbbell mr-2"></i> Equipment
                    </button>
                    <button
                        class="tab-button px-4 py-2 font-medium rounded-t-lg <?php echo $active_tab === 'membership_plans' ? 'active' : ''; ?>"
                        data-tab="membership-plans">
                        <i class="fas fa-id-card mr-2"></i> Membership Plans
                    </button>
                </div>
            </div>

            <!-- Basic Info Tab -->
            <div id="basic-info" class="tab-content <?php echo $active_tab === 'basic_info' ? 'active' : ''; ?>">
                <form method="POST" action="edit_gym_details_process.php" enctype="multipart/form-data"
                    class="tab-form">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2 class="text-xl font-semibold mb-4">Basic Information</h2>

                        <?php if (!$permissions['basic_info']): ?>
                            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                                <p>You don't have permission to edit basic information. Please contact the administrator.
                                </p>
                            </div>
                        <?php endif; ?>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="gym_name"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gym
                                    Name</label>
                                <input type="text" id="gym_name" name="gym_name"
                                    value="<?php echo htmlspecialchars($gym['name']); ?>" <?php echo !$permissions['basic_info'] ? 'readonly' : ''; ?>
                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div>
                                <label for="address"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Address</label>
                                <input type="text" id="address" name="address"
                                    value="<?php echo htmlspecialchars($gym['address']); ?>" <?php echo !$permissions['basic_info'] ? 'readonly' : ''; ?>
                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div>
                                <label for="city"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">City</label>
                                <input type="text" id="city" name="city"
                                    value="<?php echo htmlspecialchars($gym['city']); ?>" <?php echo !$permissions['basic_info'] ? 'readonly' : ''; ?>
                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div>
                                <label for="state"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">State</label>
                                <input type="text" id="state" name="state"
                                    value="<?php echo htmlspecialchars($gym['state']); ?>" <?php echo !$permissions['basic_info'] ? 'readonly' : ''; ?>
                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div>
                                <label for="zip_code"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ZIP
                                    Code</label>
                                <input type="text" id="zip_code" name="zip_code"
                                    value="<?php echo htmlspecialchars($gym['zip_code']); ?>" <?php echo !$permissions['basic_info'] ? 'readonly' : ''; ?>
                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div>
                                <label for="phone"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Phone</label>
                                <input type="text" id="phone" name="phone"
                                    value="<?php echo htmlspecialchars($gym['phone']); ?>" <?php echo !$permissions['basic_info'] ? 'readonly' : ''; ?>
                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div>
                                <label for="email"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
                                <input type="email" id="email" name="email"
                                    value="<?php echo htmlspecialchars($gym['email']); ?>" <?php echo !$permissions['basic_info'] ? 'readonly' : ''; ?>
                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <div>
                                <label for="capacity"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Capacity</label>
                                <input type="number" id="capacity" name="capacity"
                                    value="<?php echo htmlspecialchars($gym['capacity']); ?>" <?php echo !$permissions['basic_info'] ? 'readonly' : ''; ?>
                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>

                            <?php if ($permissions['gym_cut_percentage']): ?>
                                <div>
                                    <label for="gym_cut_percentage"
                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Gym Revenue
                                        Percentage (%)</label>
                                    <input type="number" id="gym_cut_percentage" name="gym_cut_percentage"
                                        value="<?php echo htmlspecialchars($gym['gym_cut_percentage'] ?? 70); ?>" min="0"
                                        max="100"
                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Percentage of revenue that goes
                                        to the gym (0-100)</p>
                                </div>
                            <?php endif; ?>

                            <div class="md:col-span-2">
                                <label for="description"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                                <textarea id="description" name="description" rows="4" <?php echo !$permissions['basic_info'] ? 'readonly' : ''; ?>
                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($gym['description']); ?></textarea>
                            </div>

                            <div class="md:col-span-2">
                                <label for="additional_notes"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Additional
                                    Notes</label>
                                <textarea id="additional_notes" name="additional_notes" rows="3" <?php echo !$permissions['basic_info'] ? 'readonly' : ''; ?>
                                    class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($gym['additional_notes'] ?? ''); ?></textarea>
                            </div>

                            <div class="md:col-span-2">
                                <div class="flex items-center">
                                    <input type="checkbox" id="is_featured" name="is_featured" <?php echo $gym['is_featured'] ? 'checked' : ''; ?> <?php echo !$permissions['basic_info'] ? 'disabled' : ''; ?>
                                        class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                    <label for="is_featured"
                                        class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">Feature this
                                        gym on homepage</label>
                                </div>
                            </div>

                            <div class="md:col-span-2">
                                <label for="cover_photo"
                                    class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cover
                                    Photo</label>
                                <div class="flex items-center space-x-4">
                                    <div
                                        class="flex-shrink-0 w-32 h-24 bg-gray-200 dark:bg-gray-700 rounded-lg overflow-hidden">
                                        <?php if (!empty($gym['cover_photo'])): ?>
                                            <img src="uploads/gym_images/<?php echo htmlspecialchars($gym['cover_photo']); ?>"
                                                alt="Cover Photo" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <div class="w-full h-full flex items-center justify-center text-gray-400">
                                                <i class="fas fa-image text-3xl"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-grow">
                                        <input type="file" id="cover_photo" name="cover_photo" <?php echo !$permissions['basic_info'] ? 'disabled' : ''; ?>
                                            class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:file:bg-gray-700 dark:file:text-gray-300">
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Recommended size:
                                            1200x400 pixels</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Add a save button for this tab only -->
                        <div class="flex justify-end mt-6">
                            <?php if ($permissions['basic_info']): ?>
                                <input type="hidden" name="save_tab" value="basic_info">
                                <button type="submit"
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                    <i class="fas fa-save mr-2"></i> Save Basic Info
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Operating Hours Tab -->
            <div id="operating-hours"
                class="tab-content <?php echo $active_tab === 'operating_hours' ? 'active' : ''; ?>">
                <form method="POST" action="edit_gym_details_process.php" enctype="multipart/form-data"
                    class="tab-form">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2 class="text-xl font-semibold mb-4">
                            <i class="fas fa-clock text-blue-500 mr-2"></i> Operating Hours
                        </h2>

                        <?php if (!$permissions['operating_hours']): ?>
                            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                                <p><i class="fas fa-exclamation-triangle mr-2"></i> You don't have permission to edit
                                    operating hours. Please contact the administrator.</p>
                            </div>
                        <?php endif; ?>

                        <!-- Operating hours fields with readonly attribute based on permissions -->
                        <div class="mb-6">
                            <div class="flex items-center mb-4">
                                <input type="checkbox" id="use_daily_hours" name="use_daily_hours"
                                    <?= $has_daily_schedule ? 'checked' : '' ?> <?= !$permissions['operating_hours'] ? 'disabled' : '' ?>
                                    class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                <label for="use_daily_hours"
                                    class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                    <i class="fas fa-calendar-day mr-1"></i> Use same hours for all days
                                </label>
                            </div>

                            <!-- Daily hours (shown when "use same hours" is checked) -->
                            <div id="daily-hours-section" class="<?= $has_daily_schedule ? '' : 'hidden' ?> mb-6">
                                <div class="flex justify-between items-center mb-3">
                                    <h3 class="text-lg font-medium">
                                        <i class="fas fa-calendar-week text-blue-500 mr-2"></i> Daily Hours
                                    </h3>
                                    <div class="flex items-center" id="all-days-closed-container">
                                        <input type="checkbox" id="all_days_closed" name="all_days_closed"
                                            <?= isset($hours_by_day['Daily']) && $hours_by_day['Daily']['is_closed'] == 1 ? 'checked' : '' ?> <?= !$permissions['operating_hours'] ? 'disabled' : '' ?>
                                            class="w-4 h-4 text-red-600 border-gray-300 rounded focus:ring-red-500">
                                        <label for="all_days_closed"
                                            class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                            <i class="fas fa-door-closed text-red-500 mr-1"></i> Gym is closed all days
                                        </label>
                                    </div>
                                </div>

                                <div id="daily-hours-inputs"
                                    class="<?= isset($hours_by_day['Daily']) && $hours_by_day['Daily']['is_closed'] == 1 ? 'hidden' : '' ?>">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                <i class="fas fa-sun text-yellow-500 mr-1"></i> Morning Open
                                            </label>
                                            <input type="time" name="operating_hours[daily][morning_open_time]"
                                                value="<?= isset($hours_by_day['Daily']) ? substr($hours_by_day['Daily']['morning_open_time'], 0, 5) : '09:00' ?>"
                                                <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        </div>
                                        <div>
                                            <label
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                <i class="fas fa-coffee text-brown-500 mr-1"></i> Morning Close
                                            </label>
                                            <input type="time" name="operating_hours[daily][morning_close_time]"
                                                value="<?= isset($hours_by_day['Daily']) ? substr($hours_by_day['Daily']['morning_close_time'], 0, 5) : '13:00' ?>"
                                                <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        </div>
                                        <div>
                                            <label
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                <i class="fas fa-cloud-sun text-orange-500 mr-1"></i> Evening Open
                                            </label>
                                            <input type="time" name="operating_hours[daily][evening_open_time]"
                                                value="<?= isset($hours_by_day['Daily']) ? substr($hours_by_day['Daily']['evening_open_time'], 0, 5) : '16:00' ?>"
                                                <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        </div>
                                        <div>
                                            <label
                                                class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                <i class="fas fa-moon text-indigo-500 mr-1"></i> Evening Close
                                            </label>
                                            <input type="time" name="operating_hours[daily][evening_close_time]"
                                                value="<?= isset($hours_by_day['Daily']) ? substr($hours_by_day['Daily']['evening_close_time'], 0, 5) : '22:00' ?>"
                                                <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        </div>
                                    </div>
                                </div>

                                <div id="daily-closed-message"
                                    class="<?= isset($hours_by_day['Daily']) && $hours_by_day['Daily']['is_closed'] == 1 ? '' : 'hidden' ?> 
                                    p-4 bg-red-100 dark:bg-red-900 dark:bg-opacity-30 rounded-lg text-red-700 dark:text-red-300 mt-2">
                                    <p><i class="fas fa-door-closed mr-2"></i> The gym is set to be closed on all days.
                                    </p>
                                </div>
                            </div>

                            <!-- Individual day hours (shown when "use same hours" is unchecked) -->
                            <div id="individual-hours-section" class="<?= $has_daily_schedule ? 'hidden' : '' ?>">
                                <?php foreach ($days_of_week as $day): ?>
                                    <?php
                                    $day_title = ucfirst($day);
                                    $is_closed = false;

                                    // Check if day is explicitly marked as closed or has all zeros
                                    if (isset($hours_by_day[$day_title])) {
                                        $day_hours = $hours_by_day[$day_title];
                                        $is_closed = isset($day_hours['is_closed']) && $day_hours['is_closed'] == 1;

                                        // Also check if all times are 00:00:00 which indicates closed
                                        if (!$is_closed) {
                                            $is_closed = $day_hours['morning_open_time'] === '00:00:00' &&
                                                $day_hours['morning_close_time'] === '00:00:00' &&
                                                $day_hours['evening_open_time'] === '00:00:00' &&
                                                $day_hours['evening_close_time'] === '00:00:00';
                                        }
                                    } else {
                                        $is_closed = in_array($day, $closed_days);
                                    }

                                    // Get day icon
                                    $day_icon = 'calendar-day';
                                    switch ($day) {
                                        case 'monday':
                                            $day_icon = 'calendar-day';
                                            break;
                                        case 'tuesday':
                                            $day_icon = 'calendar-day';
                                            break;
                                        case 'wednesday':
                                            $day_icon = 'calendar-day';
                                            break;
                                        case 'thursday':
                                            $day_icon = 'calendar-day';
                                            break;
                                        case 'friday':
                                            $day_icon = 'calendar-day';
                                            break;
                                        case 'saturday':
                                            $day_icon = 'calendar-week';
                                            break;
                                        case 'sunday':
                                            $day_icon = 'calendar-week';
                                            break;
                                    }
                                    ?>
                                    <div class="mb-6 border-b border-gray-200 dark:border-gray-700 pb-4 last:border-0">
                                        <div class="flex justify-between items-center mb-3">
                                            <h3 class="text-lg font-medium">
                                                <i <i class="fas fa-<?= $day_icon ?> text-blue-500 mr-2"></i>
                                                <?= $day_title ?>
                                            </h3>
                                            <div class="flex items-center">
                                                <input type="checkbox" id="<?= $day ?>_closed" name="closed_days[]"
                                                    value="<?= $day ?>" <?= $is_closed ? 'checked' : '' ?>
                                                    <?= !$permissions['operating_hours'] ? 'disabled' : '' ?>
                                                    class="w-4 h-4 text-red-600 border-gray-300 rounded focus:ring-red-500">
                                                <label for="<?= $day ?>_closed"
                                                    class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    <i class="fas fa-door-closed text-red-500 mr-1"></i> Closed
                                                </label>
                                            </div>
                                        </div>

                                        <div class="day-hours-inputs <?= $is_closed ? 'hidden' : '' ?>">
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <div>
                                                    <label
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        <i class="fas fa-sun text-yellow-500 mr-1"></i> Morning Open
                                                    </label>
                                                    <input type="time"
                                                        name="operating_hours[<?= $day ?>][morning_open_time]"
                                                        value="<?= isset($hours_by_day[$day_title]) ? substr($hours_by_day[$day_title]['morning_open_time'], 0, 5) : '09:00' ?>"
                                                        <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </div>
                                                <div>
                                                    <label
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        <i class="fas fa-coffee text-brown-500 mr-1"></i> Morning Close
                                                    </label>
                                                    <input type="time"
                                                        name="operating_hours[<?= $day ?>][morning_close_time]"
                                                        value="<?= isset($hours_by_day[$day_title]) ? substr($hours_by_day[$day_title]['morning_close_time'], 0, 5) : '13:00' ?>"
                                                        <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </div>
                                                <div>
                                                    <label
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        <i class="fas fa-cloud-sun text-orange-500 mr-1"></i> Evening Open
                                                    </label>
                                                    <input type="time"
                                                        name="operating_hours[<?= $day ?>][evening_open_time]"
                                                        value="<?= isset($hours_by_day[$day_title]) ? substr($hours_by_day[$day_title]['evening_open_time'], 0, 5) : '16:00' ?>"
                                                        <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </div>
                                                <div>
                                                    <label
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        <i class="fas fa-moon text-indigo-500 mr-1"></i> Evening Close
                                                    </label>
                                                    <input type="time"
                                                        name="operating_hours[<?= $day ?>][evening_close_time]"
                                                        value="<?= isset($hours_by_day[$day_title]) ? substr($hours_by_day[$day_title]['evening_close_time'], 0, 5) : '22:00' ?>"
                                                        <?= !$permissions['operating_hours'] ? 'readonly' : '' ?>
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-3 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </div>
                                            </div>
                                        </div>

                                        <div
                                            class="day-closed-message <?= $is_closed ? '' : 'hidden' ?> 
                                            p-4 bg-red-100 dark:bg-red-900 dark:bg-opacity-30 rounded-lg text-red-700 dark:text-red-300 mt-2">
                                            <p><i class="fas fa-door-closed mr-2"></i> The gym is set to be closed on
                                                <?= $day_title ?>.</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Add a save button for this tab only -->
                        <div class="flex justify-end">
                            <?php if ($permissions['operating_hours']): ?>
                                <input type="hidden" name="save_tab" value="operating_hours">
                                <button type="submit"
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                    <i class="fas fa-save mr-2"></i> Save Operating Hours
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Amenities Tab -->
            <div id="amenities" class="tab-content <?php echo $active_tab === 'amenities' ? 'active' : ''; ?>">
                <form method="POST" action="edit_gym_details_process.php" class="tab-form">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2 class="text-xl font-semibold mb-4">
                            <i class="fas fa-spa text-green-500 mr-2"></i> Amenities
                        </h2>

                        <?php if (!$permissions['amenities']): ?>
                            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                                <p><i class="fas fa-exclamation-triangle mr-2"></i> You don't have permission to edit
                                    amenities. Please contact the administrator.</p>
                            </div>
                        <?php endif; ?>

                        <div class="space-y-6">
                            <?php foreach ($amenities_by_category as $category => $category_amenities): ?>
                                <div>
                                    <h3 class="text-lg font-medium mb-3">
                                        <i class="fas fa-tag text-blue-500 mr-2"></i>
                                        <?php echo htmlspecialchars($category); ?>
                                    </h3>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                                        <?php foreach ($category_amenities as $amenity): ?>
                                            <div class="flex items-center">
                                                <input type="checkbox" id="amenity_<?php echo $amenity['id']; ?>"
                                                    name="amenities[]" value="<?php echo $amenity['id']; ?>" <?php echo in_array($amenity['id'], $selected_amenities) ? 'checked' : ''; ?>         <?php echo !$permissions['amenities'] ? 'disabled' : ''; ?>
                                                    class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                                <label for="amenity_<?php echo $amenity['id']; ?>"
                                                    class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                                    <?php echo htmlspecialchars($amenity['name']); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Add a save button for this tab only -->
                        <div class="flex justify-end mt-6">
                            <?php if ($permissions['amenities']): ?>
                                <input type="hidden" name="save_tab" value="amenities">
                                <button type="submit"
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                    <i class="fas fa-save mr-2"></i> Save Amenities
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Images Tab -->
            <div id="images" class="tab-content <?php echo $active_tab === 'images' ? 'active' : ''; ?>">
                <form method="POST" action="edit_gym_details_process.php" enctype="multipart/form-data"
                    class="tab-form">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2 class="text-xl font-semibold mb-4">
                            <i class="fas fa-images text-purple-500 mr-2"></i> Gallery Images
                        </h2>

                        <?php if (!$permissions['images']): ?>
                            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                                <p><i class="fas fa-exclamation-triangle mr-2"></i> You don't have permission to edit
                                    gallery images. Please contact the administrator.</p>
                            </div>
                        <?php endif; ?>

                        <!-- Existing Images -->
                        <div class="mb-6">
                            <h3 class="text-lg font-medium mb-3">
                                <i class="fas fa-photo-video text-blue-500 mr-2"></i> Current Gallery
                            </h3>

                            <?php if (empty($gallery_images)): ?>
                                <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg text-center">
                                    <p class="text-gray-500 dark:text-gray-400">
                                        <i class="fas fa-info-circle mr-2"></i> No gallery images yet. Add some below!
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                    <?php foreach ($gallery_images as $image): ?>
                                        <div class="bg-gray-100 dark:bg-gray-700 p-3 rounded-lg">
                                            <div class="relative h-40 mb-2 rounded overflow-hidden">
                                                <img src="uploads/gym_images/<?php echo htmlspecialchars($image['image_path']); ?>"
                                                    alt="Gallery Image" class="w-full h-full object-cover">
                                                <?php if ($permissions['images']): ?>
                                                    <div class="absolute top-2 right-2">
                                                        <button type="button"
                                                            class="delete-image-btn bg-red-500 hover:bg-red-600 text-white rounded-full w-8 h-8 flex items-center justify-center"
                                                            data-id="<?php echo $image['image_id']; ?>">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <input type="hidden" name="existing_image_ids[]"
                                                value="<?php echo $image['image_id']; ?>">
                                            <input type="hidden" name="existing_orders[]"
                                                value="<?php echo $image['display_order']; ?>">
                                            <input type="text" name="existing_captions[]"
                                                value="<?php echo htmlspecialchars($image['caption'] ?? ''); ?>"
                                                placeholder="Caption (optional)" <?php echo !$permissions['images'] ? 'readonly' : ''; ?>
                                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Hidden input for deleted images -->
                                <div id="deleted-images-container"></div>
                            <?php endif; ?>
                        </div>

                        <!-- Upload New Images -->
                        <?php if ($permissions['images']): ?>
                            <div>
                                <h3 class="text-lg font-medium mb-3">
                                    <i class="fas fa-upload text-green-500 mr-2"></i> Upload New Images
                                </h3>
                                <div class="space-y-4">
                                    <div id="image-upload-container">
                                        <div class="flex items-center space-x-4 mb-4">
                                            <div class="flex-grow">
                                                <input type="file" name="gallery_images[]" accept="image/*"
                                                    class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:file:bg-gray-700 dark:file:text-gray-300">
                                            </div>
                                            <input type="text" name="image_captions[]" placeholder="Caption (optional)"
                                                class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-64 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <button type="button"
                                                class="add-image-btn bg-blue-500 hover:bg-blue-600 text-white rounded-full w-8 h-8 flex items-center justify-center">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        <p><i class="fas fa-info-circle mr-1"></i> Recommended image size: 800x600 pixels.
                                            Maximum file size: 5MB.</p>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Add a save button for this tab only -->
                        <div class="flex justify-end mt-6">
                            <?php if ($permissions['images']): ?>
                                <input type="hidden" name="save_tab" value="images">
                                <button type="submit"
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                    <i class="fas fa-save mr-2"></i> Save Gallery Images
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Equipment Tab -->
            <div id="equipment" class="tab-content <?php echo $active_tab === 'equipment' ? 'active' : ''; ?>">
                <form method="POST" action="edit_gym_details_process.php" class="tab-form">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2 class="text-xl font-semibold mb-4">
                            <i class="fas fa-dumbbell text-red-500 mr-2"></i> Equipment
                        </h2>

                        <?php if (!$permissions['equipment']): ?>
                            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                                <p><i class="fas fa-exclamation-triangle mr-2"></i> You don't have permission to edit
                                    equipment. Please contact the administrator.</p>
                            </div>
                        <?php endif; ?>

                        <!-- Existing Equipment -->
                        <div class="mb-6">
                            <h3 class="text-lg font-medium mb-3">
                                <i class="fas fa-list text-blue-500 mr-2"></i> Current Equipment
                            </h3>

                            <?php if (empty($equipment)): ?>
                                <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg text-center">
                                    <p class="text-gray-500 dark:text-gray-400">
                                        <i class="fas fa-info-circle mr-2"></i> No equipment added yet. Add some below!
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Name</th>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Category</th>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Quantity</th>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Condition</th>
                                                <?php if ($permissions['equipment']): ?>
                                                    <th scope="col"
                                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                        Actions</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody
                                            class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                            <?php foreach ($equipment as $item): ?>
                                                <tr>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <input type="hidden" name="equipment_ids[]"
                                                            value="<?php echo $item['id']; ?>">
                                                        <input type="text" name="equipment_names[]"
                                                            value="<?php echo htmlspecialchars($item['name']); ?>" <?php echo !$permissions['equipment'] ? 'readonly' : ''; ?>
                                                            class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <input type="text" name="equipment_categories[]"
                                                            value="<?php echo htmlspecialchars($item['category']); ?>" <?php echo !$permissions['equipment'] ? 'readonly' : ''; ?>
                                                            class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <input type="number" name="equipment_quantities[]"
                                                            value="<?php echo htmlspecialchars($item['quantity']); ?>" min="1"
                                                            <?php echo !$permissions['equipment'] ? 'readonly' : ''; ?>
                                                            class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-20 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <select name="equipment_conditions[]" <?php echo !$permissions['equipment'] ? 'disabled' : ''; ?>
                                                            class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                            <option value="excellent" <?php echo $item['condition_status'] === 'excellent' ? 'selected' : ''; ?>>
                                                                Excellent</option>
                                                            <option value="good" <?php echo $item['condition_status'] === 'good' ? 'selected' : ''; ?>>Good</option>
                                                            <option value="fair" <?php echo $item['condition_status'] === 'fair' ? 'selected' : ''; ?>>Fair</option>
                                                            <option value="poor" <?php echo $item['condition_status'] === 'poor' ? 'selected' : ''; ?>>Poor</option>
                                                        </select>
                                                    </td>
                                                    <?php if ($permissions['equipment']): ?>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <button type="button"
                                                                class="delete-equipment-btn text-red-500 hover:text-red-700"
                                                                data-id="<?php echo $item['id']; ?>">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </button>
                                                        </td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Hidden input for deleted equipment -->
                                <div id="deleted-equipment-container"></div>
                            <?php endif; ?>
                        </div>

                        <!-- Add New Equipment -->
                        <?php if ($permissions['equipment']): ?>
                            <div>
                                <h3 class="text-lg font-medium mb-3">
                                    <i class="fas fa-plus-circle text-green-500 mr-2"></i> Add New Equipment
                                </h3>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700"
                                        id="new-equipment-table">
                                        <thead class="bg-gray-50 dark:bg-gray-700">
                                            <tr>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Name</th>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Category</th>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Quantity</th>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Condition</th>
                                                <th scope="col"
                                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                                    Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="new-equipment-container">
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <input type="text" name="new_equipment_names[]"
                                                        placeholder="Equipment name"
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <input type="text" name="new_equipment_categories[]"
                                                        placeholder="Category"
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <input type="number" name="new_equipment_quantities[]" value="1" min="1"
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-20 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <select name="new_equipment_conditions[]"
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        <option value="excellent">Excellent</option>
                                                        <option value="good" selected>Good</option>
                                                        <option value="fair">Fair</option>
                                                        <option value="poor">Poor</option>
                                                    </select>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <button type="button"
                                                        class="add-equipment-btn text-green-500 hover:text-green-700">
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Add a save button for this tab only -->
                        <div class="flex justify-end mt-6">
                            <?php if ($permissions['equipment']): ?>
                                <input type="hidden" name="save_tab" value="equipment">
                                <button type="submit"
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                    <i class="fas fa-save mr-2"></i> Save Equipment
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Membership Plans Tab -->
            <div id="membership-plans"
                class="tab-content <?php echo $active_tab === 'membership_plans' ? 'active' : ''; ?>">
                <form method="POST" action="edit_gym_details_process.php" class="tab-form">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 mb-6">
                        <h2 class="text-xl font-semibold mb-4">
                            <i class="fas fa-id-card text-indigo-500 mr-2"></i> Membership Plans
                        </h2>

                        <?php if (!$permissions['membership_plans']): ?>
                            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                                <p><i class="fas fa-exclamation-triangle mr-2"></i> You don't have permission to edit
                                    membership plans. Please contact the administrator.</p>
                            </div>
                        <?php endif; ?>

                        <!-- Existing Plans -->
                        <div class="mb-6">
                            <h3 class="text-lg font-medium mb-3">
                                <i class="fas fa-list-alt text-blue-500 mr-2"></i> Current Plans
                            </h3>

                            <?php if (empty($membership_plans)): ?>
                                <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg text-center">
                                    <p class="text-gray-500 dark:text-gray-400">
                                        <i class="fas fa-info-circle mr-2"></i> No membership plans added yet. Add some
                                        below!
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-4">
                                    <?php foreach ($membership_plans as $index => $plan): ?>
                                        <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg">
                                            <input type="hidden" name="plan_ids[]" value="<?php echo $plan['plan_id']; ?>">

                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                                <div>
                                                    <label
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        <i class="fas fa-tag mr-1"></i> Plan Name
                                                    </label>
                                                    <input type="text" name="plan_names[]"
                                                        value="<?php echo htmlspecialchars($plan['plan_name']); ?>" <?php echo !$permissions['membership_plans'] ? 'readonly' : ''; ?>
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </div>

                                                <div>
                                                    <label
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        <i class="fas fa-money-bill-wave mr-1"></i> Price (₹)
                                                    </label>
                                                    <input type="number" name="plan_prices[]"
                                                        value="<?php echo htmlspecialchars($plan['price']); ?>" min="0"
                                                        step="0.01" <?php echo !$permissions['membership_plans'] ? 'readonly' : ''; ?>
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </div>

                                                <div>
                                                    <label
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        <i class="fas fa-calendar-alt mr-1"></i> Duration
                                                    </label>
                                                    <select name="plan_durations[]" <?php echo !$permissions['membership_plans'] ? 'disabled' : ''; ?>
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        <option value="Daily" <?php echo $plan['duration'] === 'Daily' ? 'selected' : ''; ?>>Daily</option>
                                                        <option value="Weekly" <?php echo $plan['duration'] === 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                                                        <option value="Monthly" <?php echo $plan['duration'] === 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                                                        <option value="Quarterly" <?php echo $plan['duration'] === 'Quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                                                        <option value="Half Yearly" <?php echo $plan['duration'] === 'Half Yearly' ? 'selected' : ''; ?>>Half Yearly</option>
                                                        <option value="Yearly" <?php echo $plan['duration'] === 'Yearly' ? 'selected' : ''; ?>>Yearly</option>
                                                    </select>
                                                </div>

                                                <div>
                                                    <label
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        <i class="fas fa-layer-group mr-1"></i> Tier
                                                    </label>
                                                    <select name="plan_tiers[]" <?php echo !$permissions['membership_plans'] ? 'disabled' : ''; ?>
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        <option value="Tier 1" <?php echo $plan['tier'] === 'Tier 1' ? 'selected' : ''; ?>>Tier 1 (Basic)</option>
                                                        <option value="Tier 2" <?php echo $plan['tier'] === 'Tier 2' ? 'selected' : ''; ?>>Tier 2 (Standard)</option>
                                                        <option value="Tier 3" <?php echo $plan['tier'] === 'Tier 3' ? 'selected' : ''; ?>>Tier 3 (Premium)</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                                <div>
                                                    <label
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        <i class="fas fa-list-ul mr-1"></i> Inclusions
                                                    </label>
                                                    <textarea name="plan_inclusions[]" rows="3" <?php echo !$permissions['membership_plans'] ? 'readonly' : ''; ?>
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($plan['inclusions']); ?></textarea>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">List features
                                                        included in this plan, one per line</p>
                                                </div>

                                                <div>
                                                    <label
                                                        class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                                        <i class="fas fa-user-check mr-1"></i> Best For
                                                    </label>
                                                    <textarea name="plan_best_for[]" rows="3" <?php echo !$permissions['membership_plans'] ? 'readonly' : ''; ?>
                                                        class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($plan['best_for']); ?></textarea>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Describe who this
                                                        plan is best suited for</p>
                                                </div>
                                            </div>

                                            <?php if ($permissions['membership_plans']): ?>
                                                <div class="flex justify-end">
                                                    <button type="button" class="delete-plan-btn text-red-500 hover:text-red-700"
                                                        data-id="<?php echo $plan['plan_id']; ?>">
                                                        <i class="fas fa-trash-alt mr-1"></i> Delete Plan
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Hidden input for deleted plans -->
                                <div id="deleted-plans-container"></div>
                            <?php endif; ?>
                        </div>

                        <!-- Add New Plan -->
                        <?php if ($permissions['membership_plans']): ?>
                            <div>
                                <h3 class="text-lg font-medium mb-3">
                                    <i class="fas fa-plus-circle text-green-500 mr-2"></i> Add New Plan
                                </h3>

                                <button type="button" id="add-plan-btn"
                                    class="mb-4 px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                    <i class="fas fa-plus mr-2"></i> Add New Membership Plan
                                </button>

                                <div id="new-plans-container" class="space-y-4">
                                    <!-- New plans will be added here via JavaScript -->
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Add a save button for this tab only -->
                        <div class="flex justify-end mt-6">
                            <?php if ($permissions['membership_plans']): ?>
                                <input type="hidden" name="save_tab" value="membership_plans">
                                <button type="submit"
                                    class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                    <i class="fas fa-save mr-2"></i> Save Membership Plans
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {

            // Fix time inputs for mobile devices
    const timeInputs = document.querySelectorAll('input[type="time"]');
    timeInputs.forEach(input => {
        input.addEventListener('change', function() {
            // Ensure the time has seconds
            if (this.value && this.value.indexOf(':') !== -1 && this.value.split(':').length === 2) {
                this.value = this.value + ':00';
            }
        });
        
        // Also fix on form submission
        const form = input.closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                timeInputs.forEach(input => {
                    if (input.value && input.value.indexOf(':') !== -1 && input.value.split(':').length === 2) {
                        input.value = input.value + ':00';
                    }
                });
            });
        }
    });
            // Tab switching functionality
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');

            tabButtons.forEach(button => {
                button.addEventListener('click', function () {
                    const tabId = this.getAttribute('data-tab');

                    // Update active tab button
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    this.classList.add('active');

                    // Show the selected tab content
                    tabContents.forEach(content => {
                        content.classList.remove('active');
                        if (content.id === tabId) {
                            content.classList.add('active');
                        }
                    });

                    // Update URL without reloading the page
                    const newUrl = new URL(window.location.href);
                    newUrl.searchParams.set('tab', tabId.replace('-', '_'));
                    window.history.pushState({}, '', newUrl);
                });
            });

            // Operating Hours functionality
            const useDailyHours = document.getElementById('use_daily_hours');
            const dailyHoursSection = document.getElementById('daily-hours-section');
            const individualHoursSection = document.getElementById('individual-hours-section');

            if (useDailyHours) {
                useDailyHours.addEventListener('change', function () {
                    if (this.checked) {
                        dailyHoursSection.classList.remove('hidden');
                        individualHoursSection.classList.add('hidden');
                    } else {
                        dailyHoursSection.classList.add('hidden');
                        individualHoursSection.classList.remove('hidden');
                    }
                });
            }

            // Handle "Closed" checkboxes for individual days
            const closedCheckboxes = document.querySelectorAll('input[name="closed_days[]"]');
            closedCheckboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function () {
                    const dayInputs = this.closest('.mb-6').querySelector('.day-hours-inputs');
                    const closedMessage = this.closest('.mb-6').querySelector('.day-closed-message');

                    if (this.checked) {
                        dayInputs.classList.add('hidden');
                        closedMessage.classList.remove('hidden');
                    } else {
                        dayInputs.classList.remove('hidden');
                        closedMessage.classList.add('hidden');
                    }
                });
            });

            // Handle "All Days Closed" checkbox
            const allDaysClosed = document.getElementById('all_days_closed');
            if (allDaysClosed) {
                allDaysClosed.addEventListener('change', function () {
                    const dailyHoursInputs = document.getElementById('daily-hours-inputs');
                    const dailyClosedMessage = document.getElementById('daily-closed-message');

                    if (this.checked) {
                        dailyHoursInputs.classList.add('hidden');
                        dailyClosedMessage.classList.remove('hidden');
                    } else {
                        dailyHoursInputs.classList.remove('hidden');
                        dailyClosedMessage.classList.add('hidden');
                    }
                });
            }

            // Gallery Images functionality
            const addImageBtn = document.querySelector('.add-image-btn');
            const imageUploadContainer = document.getElementById('image-upload-container');

            if (addImageBtn && imageUploadContainer) {
                addImageBtn.addEventListener('click', function () {
                    const newRow = document.createElement('div');
                    newRow.className = 'flex items-center space-x-4 mb-4';
                    newRow.innerHTML = `
                        <div class="flex-grow">
                            <input type="file" name="gallery_images[]" accept="image/*" class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:file:bg-gray-700 dark:file:text-gray-300">
                        </div>
                        <input type="text" name="image_captions[]" placeholder="Caption (optional)" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-64 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <button type="button" class="remove-image-btn bg-red-500 hover:bg-red-600 text-white rounded-full w-8 h-8 flex items-center justify-center">
                            <i class="fas fa-minus"></i>
                        </button>
                    `;

                    imageUploadContainer.appendChild(newRow);

                    // Add event listener to the new remove button
                    const removeBtn = newRow.querySelector('.remove-image-btn');
                    removeBtn.addEventListener('click', function () {
                        newRow.remove();
                    });
                });
            }

            // Delete existing gallery image
            const deleteImageBtns = document.querySelectorAll('.delete-image-btn');
            const deletedImagesContainer = document.getElementById('deleted-images-container');

            if (deleteImageBtns.length > 0 && deletedImagesContainer) {
                deleteImageBtns.forEach(btn => {
                    btn.addEventListener('click', function () {
                        const imageId = this.getAttribute('data-id');
                        const imageContainer = this.closest('.bg-gray-100');

                        // Create hidden input to track deleted image
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'delete_images[]';
                        hiddenInput.value = imageId;
                        deletedImagesContainer.appendChild(hiddenInput);

                        // Remove the image from the UI
                        imageContainer.remove();
                    });
                });
            }

            // Equipment functionality
            const addEquipmentBtn = document.querySelector('.add-equipment-btn');
            const newEquipmentContainer = document.getElementById('new-equipment-container');

            if (addEquipmentBtn && newEquipmentContainer) {
                addEquipmentBtn.addEventListener('click', function () {
                    const newRow = document.createElement('tr');
                    newRow.innerHTML = `
                        <td class="px-6 py-4 whitespace-nowrap">
                            <input type="text" name="new_equipment_names[]" placeholder="Equipment name" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <input type="text" name="new_equipment_categories[]" placeholder="Category" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <input type="number" name="new_equipment_quantities[]" value="1" min="1" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-20 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <select name="new_equipment_conditions[]" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="excellent">Excellent</option>
                                <option value="good" selected>Good</option>
                                <option value="fair">Fair</option>
                                <option value="poor">Poor</option>
                            </select>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <button type="button" class="remove-equipment-btn text-red-500 hover:text-red-700">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </td>
                    `;

                    newEquipmentContainer.appendChild(newRow);

                    // Add event listener to the new remove button
                    const removeBtn = newRow.querySelector('.remove-equipment-btn');
                    removeBtn.addEventListener('click', function () {
                        newRow.remove();
                    });
                });
            }

            // Delete existing equipment
            const deleteEquipmentBtns = document.querySelectorAll('.delete-equipment-btn');
            const deletedEquipmentContainer = document.getElementById('deleted-equipment-container');

            if (deleteEquipmentBtns.length > 0 && deletedEquipmentContainer) {
                deleteEquipmentBtns.forEach(btn => {
                    btn.addEventListener('click', function () {
                        const equipmentId = this.getAttribute('data-id');
                        const row = this.closest('tr');

                        // Create hidden input to track deleted equipment
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'deleted_equipment[]';
                        hiddenInput.value = equipmentId;
                        deletedEquipmentContainer.appendChild(hiddenInput);

                        // Remove the row from the UI
                        row.remove();
                    });
                });
            }

            // Membership Plans functionality
            const addPlanBtn = document.getElementById('add-plan-btn');
            const newPlansContainer = document.getElementById('new-plans-container');
            let newPlanCounter = 0;

            if (addPlanBtn && newPlansContainer) {
                addPlanBtn.addEventListener('click', function () {
                    newPlanCounter++;
                    const planDiv = document.createElement('div');
                    planDiv.className = 'bg-gray-100 dark:bg-gray-700 p-4 rounded-lg';
                    planDiv.innerHTML = `
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    <i class="fas fa-tag mr-1"></i> Plan Name
                                </label>
                                <input type="text" name="new_plan_names[]" placeholder="Plan name" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    <i class="fas fa-money-bill-wave mr-1"></i> Price (₹)
                                </label>
                                <input type="number" name="new_plan_prices[]" placeholder="0.00" min="0" step="0.01" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    <i class="fas fa-calendar-alt mr-1"></i> Duration
                                </label>
                                <select name="new_plan_durations[]" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="Daily">Daily</option>
                                    <option value="Weekly">Weekly</option>
                                    <option value="Monthly" selected>Monthly</option>
                                    <option value="Quarterly">Quarterly</option>
                                    <option value="Half Yearly">Half Yearly</option>
                                    <option value="Yearly">Yearly</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    <i class="fas fa-layer-group mr-1"></i> Tier
                                </label>
                                <select name="new_plan_tiers[]" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="Tier 1">Tier 1 (Basic)</option>
                                    <option value="Tier 2" selected>Tier 2 (Standard)</option>
                                    <option value="Tier 3">Tier 3 (Premium)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    <i class="fas fa-list-ul mr-1"></i> Inclusions
                                </label>
                                <textarea name="new_plan_inclusions[]" rows="3" placeholder="List features included in this plan, one per line" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    <i class="fas fa-user-check mr-1"></i> Best For
                                </label>
                                <textarea name="new_plan_best_for[]" rows="3" placeholder="Describe who this plan is best suited for" class="border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                            </div>
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="button" class="remove-plan-btn text-red-500 hover:text-red-700">
                                <i class="fas fa-trash-alt mr-1"></i> Remove Plan
                            </button>
                        </div>
                    `;

                    newPlansContainer.appendChild(planDiv);

                    // Add event listener to the new remove button
                    const removeBtn = planDiv.querySelector('.remove-plan-btn');
                    removeBtn.addEventListener('click', function () {
                        planDiv.remove();
                    });
                });
            }

            // Delete existing plan
            const deletePlanBtns = document.querySelectorAll('.delete-plan-btn');
            const deletedPlansContainer = document.getElementById('deleted-plans-container');

            if (deletePlanBtns.length > 0 && deletedPlansContainer) {
                deletePlanBtns.forEach(btn => {
                    btn.addEventListener('click', function () {
                        const planId = this.getAttribute('data-id');
                        const planContainer = this.closest('.bg-gray-100');

                        // Create hidden input to track deleted plan
                        const hiddenInput = document.createElement('input');
                        hiddenInput.type = 'hidden';
                        hiddenInput.name = 'deleted_plans[]';
                        hiddenInput.value = planId;
                        deletedPlansContainer.appendChild(hiddenInput);

                        // Remove the plan from the UI
                        planContainer.remove();
                    });
                });
            }
        });
    </script>
</body>

</html>